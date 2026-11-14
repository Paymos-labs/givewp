<?php

declare(strict_types=1);

namespace PaymosGivewp;

use Give\DonationForms\Actions\GenerateDonationConfirmationReceiptViewRouteUrl;
use Give\Donations\Models\Donation;
use Give\Donations\Properties\BillingAddress;
use Give\Donors\Models\Donor;
use Give\Helpers\Form\Utils as FormUtils;
use PaymosGivewpVendor\Paymos\Client;
use PaymosGivewpVendor\Paymos\ClientConfig;
use PaymosGivewpVendor\Paymos\Http\WordPressTransport;
use PaymosGivewpVendor\Paymos\IdempotencyKey;
use PaymosGivewpVendor\Paymos\Plugin\AmountGuard;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

final class Checkout
{
    private const CHECKOUT_ORIGIN = 'https://checkout.paymos.io';
    private const QUERY = 'paymos_givewp';
    private const TOKEN_LIFETIME = 1800;
    private const COOKIE_LIFETIME = 43200;
    private const PAYABLE = ['awaiting_client', 'awaiting_payment', 'confirming', 'underpaid_waiting'];

    public static function registerHooks(): void
    {
        add_action('rest_api_init', [self::class, 'registerRoutes']);
        add_action('template_redirect', [self::class, 'page'], 1);
        add_action('give_pre_form_output', [self::class, 'enqueueLegacy'], 10, 1);
    }

    public static function registerRoutes(): void
    {
        if (!Install::ready()) {
            return;
        }
        register_rest_route(ConnectController::REST_NAMESPACE, '/checkout/bootstrap', [
            'methods' => 'POST',
            // The guest route enforces its own exact browser Origin and native form authorization.
            'permission_callback' => '__return_true',
            'callback' => [self::class, 'bootstrapRequest'],
        ]);
    }

    public static function enqueueLegacy(int $formId): void
    {
        if (Plugin::checkoutEnabled($formId, 2)) {
            self::enqueue();
            echo '<p hidden data-paymos-recovery-form="' . esc_attr((string) $formId) . '"></p>';
        }
    }

    public static function enqueue(int $visualFormId = 0): void
    {
        $handle = 'paymos-givewp-checkout';
        wp_enqueue_script($handle, plugin_dir_url(PAYMOS_GIVEWP_PLUGIN_FILE) . 'assets/js/checkout.js', [], PAYMOS_GIVEWP_PLUGIN_VERSION, true);
        $source = '';
        try {
            $source = self::source();
        } catch (\UnexpectedValueException $error) {
            // Invalid home configuration disables Paymos checkout without breaking the native form.
        }
        wp_localize_script($handle, 'PaymosGivewpCheckoutConfig', [
            'bootstrapUrl' => rest_url(ConnectController::REST_NAMESPACE . '/checkout/bootstrap'),
            'siteScope' => $source === '' ? '' : self::siteScope((int) get_current_blog_id(), $source),
            'statusBase' => $source === '' ? '' : $source . '/',
            'cookieLifetime' => self::COOKIE_LIFETIME,
            'visualFormId' => $visualFormId,
            'resumeLabel' => __('Resume your previous Paymos donation', 'paymos-stablecoin-donations'),
            'unavailable' => __('Paymos is unavailable for this donation. Please try again.', 'paymos-stablecoin-donations'),
        ]);
    }

    public static function bootstrapRequest(WP_REST_Request $request)
    {
        try {
            $origin = $request->get_header('origin');
            if ($origin !== self::origin(self::source())) {
                return self::denied();
            }
            $form = $request->get_param('form');
            $intent = $request->get_param('intent');
            if (!is_int($form) || !is_string($intent)) {
                return self::denied();
            }

            $state = self::bootstrap($form, $intent);
            if ($state === null) {
                return self::denied();
            }
            $response = new WP_REST_Response([
                'token' => $state['token'],
                'expires_in' => self::TOKEN_LIFETIME,
                'resume_url' => $state['resume_url'],
            ]);
            $response->header('Cache-Control', 'private, no-store');
            $response->header('Referrer-Policy', 'no-referrer');
            if ($state['new_cookie']) {
                $response->header('Set-Cookie', $state['cookie_name'] . '=' . $state['cookie']
                    . '; Max-Age=' . self::COOKIE_LIFETIME . '; Path=/; Secure; HttpOnly; SameSite=Lax');
            }

            return $response;
        } catch (\Throwable $error) {
            return self::denied();
        }
    }

    /** Prepare a signed first-party browser intent before either native submit path. */
    public static function bootstrap(int $form, string $intent): ?array
    {
        if ($form <= 0 || preg_match('/\A[a-f0-9]{32}\z/', $intent) !== 1 || !self::publishedForm($form)) {
            return null;
        }
        $version = self::formVersion($form);
        if (!Plugin::checkoutEnabled($form, $version)) {
            return null;
        }

        $site = (int) get_current_blog_id();
        $source = self::source();
        $intentHash = hash('sha256', $intent);
        $cookieName = self::cookieName($site, $source, $intentHash);
        $savedCookie = self::cookie($cookieName);
        $cookie = $savedCookie ?? bin2hex(random_bytes(32));
        $payload = implode('.', [$site, $form, $version, $intentHash, time() + self::TOKEN_LIFETIME]);
        $handle = self::handoff($source, $form, $intentHash, self::sign('cookie', $cookie));

        return [
            'token' => $payload . '.' . self::sign('submit', $source . '|' . $payload . '|' . $cookie),
            'resume_url' => self::statusUrl($source, $handle),
            'cookie_name' => $cookieName,
            'cookie' => $cookie,
            'new_cookie' => $savedCookie === null,
        ];
    }

    /** Called after native GiveWP saves its Donation. Never talks to Paymos. */
    public static function register(Donation $donation, array $gatewayData): ?string
    {
        $source = self::source();
        $site = (int) get_current_blog_id();
        $form = (int) $donation->formId;
        if (!Install::ready() || !self::nativeCanStart($donation) || !self::publishedForm($form)) {
            return null;
        }
        $version = self::formVersion($form);
        $token = self::validateToken($gatewayData['paymos_submit'] ?? null, $site, $source, $form, $version);
        if ($token === null) {
            return null;
        }
        $snapshot = Money::fromDonation($donation);
        $principal = (int) get_current_user_id();
        $nativeSession = self::nativeSession($version);
        if ($version === 2 && $nativeSession === null) {
            return null;
        }
        $handle = self::handoff($source, $form, $token['intent_hash'], $token['cookie_hash']);

        $connectionLock = ConnectionStore::lock($site);
        if (!$connectionLock->acquire()) {
            return null;
        }
        try {
            $existing = AttemptStore::byIntent($form, $token['intent_hash']);
            if ($existing !== null) {
                // GiveWP may save a second pending draft after a lost native response.
                // Its purchase key differs, so check the original separately.
                if (
                    $existing['site'] !== $site || $existing['source'] !== $source || $existing['form_version'] !== $version
                    || !is_string($existing['cookie_hash']) || !hash_equals($existing['cookie_hash'], $token['cookie_hash'])
                    || $existing['donor_id'] !== (int) $donation->donorId || $existing['principal_id'] !== $principal
                    || $existing['native_session_hash'] !== $nativeSession || $existing['native_mode'] !== $snapshot['native_mode']
                    || $existing['currency'] !== $snapshot['currency'] || !AmountGuard::amountsEqual($existing['amount'], $snapshot['amount'])
                    || self::status($handle) === null
                ) {
                    return null;
                }

                return self::statusUrl($source, $handle);
            }

            $binding = ConnectionStore::active($site);
            if (
                $binding === null || Money::environment($snapshot['native_mode']) !== $binding['environment']
                || !Plugin::checkoutEnabled($form, $version)
            ) {
                return null;
            }
            $donationLock = AttemptStore::lock((int) $donation->id);
            if (!$donationLock->acquire()) {
                return null;
            }
            try {
                $purchaseHash = self::purchaseHash($donation);
                if ($purchaseHash === null) {
                    return null;
                }
                $now = time();
                $row = $binding + $snapshot + [
                    'form_id' => $form,
                    'form_version' => $version,
                    'donation_id' => (int) $donation->id,
                    'donor_id' => (int) $donation->donorId,
                    'principal_id' => $principal,
                    'native_session_hash' => $nativeSession,
                    'purchase_hash' => $purchaseHash,
                    'intent_hash' => $token['intent_hash'],
                    'cookie_hash' => $token['cookie_hash'],
                    'handle_hash' => hash('sha256', $handle),
                    'external_order_id' => IdempotencyKey::externalOrderId('givewp'),
                    'status' => 'prepared',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $original = Donation::find($row['donation_id']);
                if (!$original instanceof Donation || !self::nativeCanStart($original) || !self::matchesDonation($row, $original)) {
                    return null;
                }

                $connectionLock->assertOwned();
                AttemptStore::insert($row, $donationLock);
            } finally {
                $donationLock->release();
            }

            return self::statusUrl($source, $handle);
        } finally {
            $connectionLock->release();
        }
    }

    public static function failureUrl(): string
    {
        return self::statusUrl(self::source(), 'unavailable');
    }

    /** Local status is a read-only check of the original browser and native donation. */
    public static function status(string $handle): ?array
    {
        if (!Install::ready() || preg_match('/\A[a-f0-9]{64}\z/', $handle) !== 1) {
            return null;
        }
        $site = (int) get_current_blog_id();
        $source = self::source();
        $row = AttemptStore::byHandle($handle);
        if (
            $row === null || $row['site'] !== $site || $row['source'] !== $source
            || $row['principal_id'] !== (int) get_current_user_id()
            || !is_string($row['intent_hash']) || !is_string($row['cookie_hash'])
        ) {
            return null;
        }
        $cookie = self::cookie(self::cookieName($site, $source, $row['intent_hash']));
        if (
            $cookie === null || !hash_equals($row['cookie_hash'], self::sign('cookie', $cookie))
            || $row['native_session_hash'] !== self::nativeSession($row['form_version'])
        ) {
            return null;
        }
        $donation = Donation::find($row['donation_id']);
        if (!$donation instanceof Donation || !self::matchesDonation($row, $donation)) {
            return null;
        }

        return $row;
    }

    /** This is also the immutable native snapshot that settlement must re-check. */
    public static function matchesDonation(array $row, Donation $donation): bool
    {
        $snapshot = Money::fromDonation($donation);

        return (int) $donation->id === (int) $row['donation_id']
            && $donation->type->isSingle()
            && $donation->gatewayId === Gateway::id()
            && (int) $donation->formId === (int) $row['form_id']
            && (int) $donation->donorId === (int) $row['donor_id']
            && self::formVersion((int) $row['form_id']) === (int) $row['form_version']
            && $snapshot['native_mode'] === $row['native_mode']
            && Money::environment($snapshot['native_mode']) === $row['environment']
            && $snapshot['currency'] === $row['currency']
            && AmountGuard::amountsEqual($snapshot['amount'], $row['amount'])
            && is_string($row['purchase_hash']) && hash_equals($row['purchase_hash'], self::purchaseHash($donation) ?? '');
    }

    public static function actionNonce(string $handle, array $row, string $action): string
    {
        return self::sign('action', $action . '|' . $handle . '|' . $row['cookie_hash'] . '|' . $row['principal_id']);
    }

    /** Start or retry only the durable external ID. Inactive credentials are read-only. */
    public static function start(string $handle, string $csrf): ?string
    {
        $initial = self::status($handle);
        if ($initial === null || !hash_equals(self::actionNonce($handle, $initial, 'start'), $csrf)) {
            return null;
        }
        $connectionLock = ConnectionStore::lock($initial['site']);
        if (!$connectionLock->acquire()) {
            return null;
        }
        try {
            $donationLock = AttemptStore::lock($initial['donation_id']);
            if (!$donationLock->acquire()) {
                return null;
            }
            try {
                $row = self::status($handle);
                $donation = $row === null ? null : Donation::find($row['donation_id']);
                if (
                    $row === null || !$donation instanceof Donation || !self::nativeCanStart($donation)
                    || !in_array($row['status'], ['prepared', 'creating', 'open'], true)
                ) {
                    return null;
                }

                $current = self::isCurrent($row);
                if (!$current || $row['invoice_id'] !== null) {
                    $invoice = self::readExisting($row);
                    if ($invoice === null || !self::invoiceMatches($row, $invoice)) {
                        return null;
                    }
                    $connectionLock->assertOwned();
                    AttemptStore::bindInvoice($row, $invoice, $donationLock);

                    return $current ? self::payableUrl($invoice) : null;
                }

                $client = self::client($row);
                if ($client === null) {
                    return null;
                }

                $connectionLock->assertOwned();
                AttemptStore::markCreating($row, $donationLock);
                $row['status'] = 'creating';
                $invoice = $client->invoices()->create([
                    'project_id' => $row['project_id'],
                    'amount' => $row['amount'],
                    'currency' => $row['currency'],
                    'external_order_id' => $row['external_order_id'],
                ]);

                $connectionLock->assertOwned();
                if (!self::invoiceMatches($row, $invoice)) {
                    return null;
                }
                AttemptStore::bindInvoice($row, $invoice, $donationLock);

                return self::payableUrl($invoice);
            } finally {
                $donationLock->release();
            }
        } finally {
            $connectionLock->release();
        }
    }

    private static function readExisting(array $row): ?array
    {
        if ($row['invoice_id'] === null && $row['status'] !== 'creating') {
            return null;
        }
        $client = self::client($row);
        if ($client === null) {
            return null;
        }
        if ($row['invoice_id'] !== null) {
            return self::validInvoiceId($row['invoice_id']) ? $client->invoices()->get($row['invoice_id']) : null;
        }
        $page = $client->invoices()->listPage([
            'project_id' => $row['project_id'],
            'external_order_id' => $row['external_order_id'],
            'limit' => 2,
        ]);
        if (!is_array($page['items'] ?? null) || count($page['items']) !== 1 || !empty($page['next_cursor'])) {
            return null;
        }
        $id = $page['items'][0]['invoice_id'] ?? null;

        return self::validInvoiceId($id) ? $client->invoices()->get($id) : null;
    }

    private static function payableUrl(array $invoice): ?string
    {
        return !$invoice['is_final'] && in_array($invoice['status'], self::PAYABLE, true) ? $invoice['payment_url'] : null;
    }

    public static function invoiceMatches(array $row, array $invoice): bool
    {
        $id = $invoice['invoice_id'] ?? null;
        $order = $invoice['order'] ?? null;
        $status = $invoice['status'] ?? null;
        $final = $invoice['is_final'] ?? null;

        return self::validInvoiceId($id)
            && ($row['invoice_id'] === null || $row['invoice_id'] === $id)
            && ($invoice['project_id'] ?? null) === $row['project_id']
            && ($invoice['is_test'] ?? null) === ($row['environment'] === 'sandbox')
            && is_array($order)
            && ($order['external_id'] ?? null) === $row['external_order_id']
            && ($order['currency'] ?? null) === $row['currency']
            && is_string($order['amount'] ?? null)
            && AmountGuard::amountsEqual($order['amount'], $row['amount'])
            && is_string($status) && preg_match('/\A[a-z_]{1,32}\z/', $status) === 1
            && is_bool($final) && (!in_array($status, self::PAYABLE, true) || !$final)
            && self::trustedPaymentUrl($invoice['payment_url'] ?? null, $id);
    }

    private static function client(array $row): ?Client
    {
        $keys = ConnectionStore::forAttempt($row);
        if ($keys === null) {
            return null;
        }

        return new Client(new ClientConfig($keys['api_key'], $keys['api_secret'], $keys['base_url'], 8), new WordPressTransport());
    }

    private static function isCurrent(array $row): bool
    {
        $active = ConnectionStore::active($row['site']);
        if ($active === null || !Plugin::checkoutEnabled($row['form_id'], $row['form_version'])) {
            return false;
        }
        foreach ($active as $key => $value) {
            if (($row[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    private static function validInvoiceId($value): bool
    {
        return is_string($value) && preg_match('/\Ainv_[A-Za-z0-9_-]{1,120}\z/', $value) === 1;
    }

    private static function trustedPaymentUrl($url, string $invoiceId): bool
    {
        return is_string($url) && $url === self::CHECKOUT_ORIGIN . '/invoice/' . rawurlencode($invoiceId);
    }

    public static function receipt(string $handle, string $csrf): ?string
    {
        $row = self::status($handle);
        if ($row === null || $row['status'] !== 'verified_paid' || !hash_equals(self::actionNonce($handle, $row, 'receipt'), $csrf)) {
            return null;
        }

        $lock = AttemptStore::lock($row['donation_id']);
        if (!$lock->acquire()) {
            return null;
        }
        try {
            $row = self::status($handle);
            $donation = $row === null ? null : Donation::find($row['donation_id']);
            if (
                $row === null || $row['status'] !== 'verified_paid'
                || !$donation instanceof Donation || !$donation->status->isComplete()
                || !self::matchesDonation($row, $donation)
            ) {
                return null;
            }

            if ($row['form_version'] === 3) {
                $url = (new GenerateDonationConfirmationReceiptViewRouteUrl())($donation->purchaseKey);

                return self::sameSite($url, $row['source']) ? $url : null;
            }

            $url = add_query_arg('payment-confirmation', Gateway::id(), give_get_success_page_url());
            if (!self::sameSite($url, $row['source'])) {
                return null;
            }
            $donor = Donor::find($donation->donorId);
            $candidate = self::legacyPurchase($donation, $row['principal_id']);
            if (!$donor instanceof Donor || $donor->id !== $donation->donorId || $candidate === null) {
                return null;
            }
            $email = give()->session->get('give_email');
            if (!in_array($email, [null, false, ''], true) && $email !== $candidate['user_email']) {
                return null;
            }
            $purchase = give_get_purchase_session();
            $missing = $purchase === null || $purchase === false || $purchase === [];
            if (!self::validLegacyPurchase($missing ? $candidate : $purchase, $candidate, $donor, $missing, $row['currency'])) {
                return null;
            }
            $lock->assertOwned();
            if ($missing) {
                // Use Give's public setter: give_purchase and give_email belong together.
                give_set_purchase_session($candidate);
                if (give_get_purchase_session() !== $candidate || give()->session->get('give_email') !== $candidate['user_email']) {
                    return null;
                }
            }

            return $url;
        } finally {
            $lock->release();
        }
    }

    /** Build only the receipt schema from the pinned donation, never from current POST/profile data. */
    private static function legacyPurchase(Donation $donation, int $principal): ?array
    {
        if ($principal < 0 || !$donation->createdAt instanceof \DateTimeInterface
            || !self::receiptText($donation->email) || $donation->email === '') {
            return null;
        }
        $names = [];
        foreach (['firstName', 'lastName', 'honorific'] as $field) {
            $value = $donation->{$field} ?? '';
            if (!self::receiptText($value)) {
                return null;
            }
            $names[$field] = $value;
        }
        $billing = $donation->billingAddress;
        if ($billing !== null && !$billing instanceof BillingAddress) {
            return null;
        }
        $address = [];
        foreach (['line1' => 'address1', 'line2' => 'address2', 'city' => 'city', 'state' => 'state', 'country' => 'country', 'postalCode' => 'zip'] as $key => $field) {
            $value = $billing === null ? '' : ($billing->{$field} ?? '');
            if (!self::receiptText($value)) {
                return null;
            }
            $address[$key] = $value;
        }
        $money = Money::fromDonation($donation);

        return [
            'donation_id' => $donation->id, 'price' => $money['amount'],
            'purchase_key' => $donation->purchaseKey, 'user_email' => $donation->email,
            'date' => $donation->createdAt->format('Y-m-d H:i:s'), 'gateway' => $donation->gatewayId,
            'user_info' => [
                'id' => $principal, 'first_name' => $names['firstName'], 'last_name' => $names['lastName'],
                'title' => $names['honorific'], 'email' => $donation->email, 'address' => $address,
            ],
            'post_data' => [
                'give-form-id' => (string) $donation->formId, 'give-amount' => $money['amount'],
                'give-first' => $names['firstName'], 'give-last' => $names['lastName'],
                'give-email' => $donation->email, 'give-user-id' => (string) $principal, 'give-gateway' => $donation->gatewayId,
            ],
            'card_info' => [
                'card_address' => $address['line1'], 'card_address_2' => $address['line2'],
                'card_city' => $address['city'], 'card_state' => $address['state'],
                'card_country' => $address['country'], 'card_zip' => $address['postalCode'],
            ],
        ];
    }

    /** Validate every mandatory field before any write; preserve full native producer variants verbatim. */
    private static function validLegacyPurchase($purchase, array $expected, Donor $donor, bool $constructed, string $currency): bool
    {
        $top = self::receiptFields($purchase, [
            'id' => ['donation_id'], 'totalAmount' => ['price'], 'purchaseKey' => ['purchase_key'],
            'donorEmail' => ['user_email'], 'createdAt' => ['date'], 'paymentGateway' => ['gateway'],
            'donorInfo' => ['user_info'], 'formEntry' => ['post_data'], 'cardInfo' => ['card_info'],
        ], [], ['caseTo']);
        // Native legacy sessions retain an interim key; status/matchesDonation bind the persisted key.
        if ($top === null || !self::receiptId($top['id'], $expected['donation_id'])
            || !self::receiptAmount($top['totalAmount'], $expected['price'])
            || !self::receiptText($top['purchaseKey']) || $top['purchaseKey'] === ''
            || ($constructed && $top['purchaseKey'] !== $expected['purchase_key'])
            || $top['donorEmail'] !== $expected['user_email']
            || $top['paymentGateway'] !== $expected['gateway'] || !self::receiptDate($top['createdAt'])) {
            return false;
        }
        $user = self::receiptFields($top['donorInfo'], [
            'wpUserId' => ['id'], 'firstName' => ['first_name'], 'lastName' => ['last_name'],
            'honorific' => ['title'], 'email' => ['email'], 'address' => ['address'],
        ]);
        $principal = $expected['user_info']['id'];
        if ($user === null || $user['email'] !== $expected['user_email']
            || !self::receiptText($user['firstName']) || !self::receiptText($user['lastName']) || !self::receiptText($user['honorific'])) {
            return false;
        }
        $identityMatches = self::receiptId($user['wpUserId'], $principal);
        if (!$identityMatches && !$constructed && $principal === 0 && is_int($donor->userId) && $donor->userId > 0) {
            // Give's existing-email guest producer may retain this donor's linked WP ID without logging in.
            // This is preserve-only: reconstruction and authorization still use the bound guest principal.
            $identityMatches = self::receiptId($user['wpUserId'], $donor->userId);
        }
        if (!$identityMatches || !self::receiptAddress($user['address'], $constructed)) {
            return false;
        }
        $post = self::receiptFields($top['formEntry'], [
            'formId' => ['give-form-id'], 'totalAmount' => ['give-amount'],
            'firstName' => ['give-first', 'give_first'], 'lastName' => ['give-last', 'give_last'],
            'donorEmail' => ['give-email', 'give_email'], 'wpUserId' => ['give-user-id'],
            'paymentGateway' => ['give-gateway'],
        ], !$constructed && $principal === 0 ? ['wpUserId'] : []);
        if ($post === null || !self::receiptId($post['formId'], (int) $expected['post_data']['give-form-id'])
            || !(self::receiptAmount($post['totalAmount'], $expected['price'])
                || (!$constructed && self::receiptFormattedAmount($post['totalAmount'], $expected['price'], $currency)))
            || $post['donorEmail'] !== $expected['user_email']
            || $post['paymentGateway'] !== $expected['gateway'] || !self::receiptText($post['firstName']) || !self::receiptText($post['lastName'])
            || (array_key_exists('wpUserId', $post) && !self::receiptId($post['wpUserId'], $principal))) {
            return false;
        }
        $card = self::receiptFields($top['cardInfo'], [
            'line1' => ['card_address'], 'line2' => ['card_address_2'], 'city' => ['card_city'],
            'state' => ['card_state'], 'country' => ['card_country'], 'postalCode' => ['card_zip'],
        ], [], ['address', 'address2', 'zip'], true);
        if ($card === null) {
            return false;
        }
        foreach ($card as $value) {
            if (!self::receiptText($value)) {
                return false;
            }
        }

        return true;
    }

    private static function receiptAddress($address, bool $constructed): bool
    {
        if (!$constructed && $address === false) {
            return true;
        }
        $fields = self::receiptFields($address, [
            'line1' => ['line1'], 'line2' => ['line2'], 'city' => ['city'], 'state' => ['state'],
            'country' => ['country'], 'postalCode' => $constructed ? ['postalCode'] : ['postalCode', 'zip'],
        ]);
        if ($fields === null) {
            return false;
        }
        foreach ($fields as $value) {
            if ((!$constructed && $value === false) || self::receiptText($value)) {
                continue;
            }
            return false;
        }

        return true;
    }

    /** Allow documented native spellings, but reject extensions which shadow the native reader's fields. */
    private static function receiptFields($data, array $aliases, array $optional = [], array $reserved = [], bool $card = false): ?array
    {
        if (!is_array($data)) {
            return null;
        }
        $allowed = [];
        foreach ($aliases as $field => $keys) {
            $reserved[] = $field;
            foreach ($keys as $key) {
                $allowed[$key] = $field;
                $reserved[] = self::receiptProperty($key);
            }
        }
        $fields = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                return null;
            }
            if (isset($allowed[$key])) {
                $field = $allowed[$key];
                if (array_key_exists($field, $fields) && $fields[$field] !== $value) {
                    return null;
                }
                $fields[$field] = $value;
            } else {
                $property = self::receiptProperty($key);
                // Native Donation::filterCardInfoKeys removes every lowercase "card" after Accessor spelling.
                if ($card) {
                    $property = lcfirst(str_replace('card', '', $property));
                }
                if (in_array($property, $reserved, true)) {
                    return null;
                }
            }
        }
        foreach ($aliases as $field => $keys) {
            if (!array_key_exists($field, $fields) && !in_array($field, $optional, true)) {
                return null;
            }
        }

        return $fields;
    }

    /** Give\Session\Accessor's recursive key spelling, used only to detect ambiguous extensions. */
    private static function receiptProperty(string $key): string
    {
        $key = preg_replace('/[^a-zA-Z0-9_\-]/', '', $key);
        $key = preg_replace('/(-|_)?give(-|_)?/', '', $key);

        return lcfirst(implode('', array_map('ucfirst', array_filter(preg_split('/(-|_)/', $key)))));
    }

    private static function receiptId($value, int $expected): bool
    {
        return $expected >= 0 && ((is_int($value) && $value === $expected)
            || (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) === 1 && $value === (string) $expected));
    }

    private static function receiptAmount($value, string $expected): bool
    {
        return (is_string($value) || is_int($value) || (is_float($value) && is_finite($value)))
            && AmountGuard::amountsEqual($value, $expected);
    }

    /** Existing native POST can retain display separators; compare decimal strings without native rounding. */
    private static function receiptFormattedAmount($value, string $expected, string $currency): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $decimal = give_get_price_decimal_separator($currency);
        $thousands = give_get_price_thousand_separator($currency);
        if (!is_string($decimal) || !is_string($thousands) || $decimal === '' || $decimal === $thousands
            || preg_match('/[0-9A-Za-z+\-]/u', $decimal . $thousands) !== 0
            || ($thousands !== '' && (strpos($decimal, $thousands) !== false || strpos($thousands, $decimal) !== false))) {
            return false;
        }
        $integer = '[0-9]+';
        if ($thousands !== '') {
            $group = preg_quote($thousands, '~');
            $grouped = $currency === 'INR'
                ? '[1-9][0-9]?(?:' . $group . '[0-9]{2})*' . $group . '[0-9]{3}'
                : '[1-9][0-9]{0,2}(?:' . $group . '[0-9]{3})+';
            $integer = '(?:' . $integer . '|' . $grouped . ')';
        }
        if (preg_match('~\A' . $integer . '(?:' . preg_quote($decimal, '~') . '[0-9]+)?\z~u', $value) !== 1) {
            return false;
        }
        $normalized = $thousands === '' ? $value : str_replace($thousands, '', $value);

        return AmountGuard::amountsEqual(str_replace($decimal, '.', $normalized), $expected);
    }

    private static function receiptText($value): bool
    {
        return is_string($value) && !is_serialized($value);
    }

    private static function receiptDate($value): bool
    {
        if (!is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $value) !== 1) {
            return false;
        }
        $date = \DateTime::createFromFormat('!Y-m-d H:i:s', $value);
        $errors = \DateTime::getLastErrors();

        return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d H:i:s') === $value;
    }

    public static function page(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Opaque locator only. GET does not change payment state.
        $handle = self::param($_GET, self::QUERY);
        if ($handle === null) {
            return;
        }

        nocache_headers();
        header('Cache-Control: private, no-store');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
        try {
            $row = self::status($handle);
            $method = self::param($_SERVER, 'REQUEST_METHOD');
            if ($row !== null && $method === 'POST') {
                $origin = self::param($_SERVER, 'HTTP_ORIGIN');
                $fetchSite = self::param($_SERVER, 'HTTP_SEC_FETCH_SITE');
                // A no-referrer navigation can send Origin: null. Accept its browser first-party context only.
                if ($origin !== self::origin($row['source']) && !(($origin === null || $origin === 'null') && $fetchSite === 'same-origin')) {
                    self::render(null, '');

                    return;
                }
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- A purpose-specific CSRF signature is checked with the original browser and donation in each handler.
                $action = self::param($_POST, 'paymos_action');
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This signature is not a WordPress user nonce. Guest actions bind it to the authenticated browser intent.
                $csrf = self::param($_POST, 'paymos_csrf') ?? '';
                if ($action === 'start') {
                    try {
                        $url = self::start($handle, $csrf);
                    } catch (\Throwable $error) {
                        // A lost response leaves the durable intent ready for an explicit retry.
                        $url = null;
                    }
                    if ($url !== null) {
                        // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The redirect is the exact trusted checkout origin and bound invoice path.
                        wp_redirect($url, 303, 'Paymos');
                        exit;
                    }
                } elseif ($action === 'receipt') {
                    $url = self::receipt($handle, $csrf);
                    if ($url !== null) {
                        wp_safe_redirect($url, 303, 'Paymos');
                        exit;
                    }
                }

                self::render(self::status($handle), $handle, true);

                return;
            }

            self::render($method === 'GET' ? $row : null, $handle);
        } catch (\Throwable $error) {
            self::render(null, '');
        }
    }

    private static function render(?array $row, string $handle, bool $retry = false): void
    {
        if ($row === null) {
            status_header(404);
        }
        $donation = $row === null ? null : Donation::find($row['donation_id']);
        $paid = $row !== null && $row['status'] === 'verified_paid' && $donation instanceof Donation && $donation->status->isComplete();
        $payable = $row !== null && self::isCurrent($row) && $donation instanceof Donation && self::nativeCanStart($donation)
            && (in_array($row['status'], ['prepared', 'creating'], true)
                || ($row['status'] === 'open' && in_array($row['remote_status'], self::PAYABLE, true)));
        $recoverable = $row !== null && !$payable && in_array($row['status'], ['creating', 'open'], true)
            && $donation instanceof Donation && self::nativeCanStart($donation);
        $formLink = $row === null ? null : get_permalink($row['form_id']);
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php esc_html_e('Paymos donation', 'paymos-stablecoin-donations'); ?></title>
        </head>
        <body>
        <main<?php if ($row !== null) : ?>
            data-paymos-recovery-status="<?php echo esc_attr($row['form_id'] . ':' . $handle); ?>"
        <?php endif; ?>>
            <h1><?php esc_html_e('Paymos donation', 'paymos-stablecoin-donations'); ?></h1>
            <?php if ($row === null) : ?>
                <p><?php esc_html_e('This donation is unavailable. Return to the donation form to try again.', 'paymos-stablecoin-donations'); ?></p>
            <?php else : ?>
                <p><?php echo esc_html($row['amount'] . ' ' . $row['currency']); ?></p>
                <?php if ($retry) : ?>
                    <p><?php esc_html_e('Paymos could not complete that request. Check the donation status and try again.', 'paymos-stablecoin-donations'); ?></p>
                <?php endif; ?>
                <?php if ($paid) : ?>
                    <p><?php esc_html_e('Your donation is confirmed.', 'paymos-stablecoin-donations'); ?></p>
                    <form method="post">
                        <input type="hidden" name="paymos_action" value="receipt">
                        <input type="hidden" name="paymos_csrf" value="<?php echo esc_attr(self::actionNonce($handle, $row, 'receipt')); ?>">
                        <button type="submit"><?php esc_html_e('View GiveWP receipt', 'paymos-stablecoin-donations'); ?></button>
                    </form>
                <?php elseif ($payable || $recoverable) : ?>
                    <form method="post"<?php if ($payable) : ?> target="_blank" rel="noopener noreferrer"<?php endif; ?>>
                        <input type="hidden" name="paymos_action" value="start">
                        <input type="hidden" name="paymos_csrf" value="<?php echo esc_attr(self::actionNonce($handle, $row, 'start')); ?>">
                        <button type="submit"><?php
                            if ($payable) {
                                esc_html_e('Open Paymos checkout', 'paymos-stablecoin-donations');
                            } else {
                                esc_html_e('Check existing payment', 'paymos-stablecoin-donations');
                            }
                        ?></button>
                    </form>
                <?php else : ?>
                    <p><?php esc_html_e('This donation is waiting for confirmation or needs review.', 'paymos-stablecoin-donations'); ?></p>
                <?php endif; ?>
                <p><a href="<?php echo esc_url(self::statusUrl($row['source'], $handle)); ?>"><?php esc_html_e('Refresh donation status', 'paymos-stablecoin-donations'); ?></a></p>
                <?php if (is_string($formLink) && self::sameSite($formLink, $row['source'])) : ?>
                    <p><a href="<?php echo esc_url($formLink); ?>"><?php esc_html_e('Start a new donation', 'paymos-stablecoin-donations'); ?></a></p>
                <?php endif; ?>
            <?php endif; ?>
        </main>
        <?php
        if ($row !== null) {
            self::enqueue();
            wp_print_scripts('paymos-givewp-checkout');
        }
        ?>
        </body>
        </html>
        <?php
        exit;
    }

    private static function validateToken($token, int $site, string $source, int $form, int $version): ?array
    {
        if (!is_string($token) || strlen($token) > 200) {
            return null;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 6) {
            return null;
        }
        [$tokenSite, $tokenForm, $tokenVersion, $intentHash, $expires, $signature] = $parts;
        $now = time();
        if (
            $tokenSite !== (string) $site || $tokenForm !== (string) $form || $tokenVersion !== (string) $version
            || preg_match('/\A[a-f0-9]{64}\z/', $intentHash) !== 1
            || !ctype_digit($expires) || (int) $expires <= $now || (int) $expires > $now + self::TOKEN_LIFETIME
        ) {
            return null;
        }
        $cookie = self::cookie(self::cookieName($site, $source, $intentHash));
        $payload = implode('.', array_slice($parts, 0, 5));
        if ($cookie === null || !hash_equals(self::sign('submit', $source . '|' . $payload . '|' . $cookie), $signature)) {
            return null;
        }

        return ['intent_hash' => $intentHash, 'cookie_hash' => self::sign('cookie', $cookie)];
    }

    private static function nativeCanStart(Donation $donation): bool
    {
        return (int) $donation->id > 0 && (int) $donation->donorId > 0
            && $donation->gatewayId === Gateway::id() && $donation->type->isSingle()
            && in_array($donation->status->getValue(), ['pending', 'processing'], true);
    }

    private static function nativeSession(int $version): ?string
    {
        if ($version !== 2) {
            return null;
        }
        $session = give()->session;
        if (!$session->has_session()) {
            return null;
        }
        $id = (string) $session->get_donor_id();

        return $id === '' ? null : self::sign('native-session', $id);
    }

    private static function purchaseHash(Donation $donation): ?string
    {
        $key = $donation->purchaseKey;

        return is_string($key) && $key !== '' ? self::sign('native-receipt', $key) : null;
    }

    private static function formVersion(int $form): int
    {
        return FormUtils::isV3Form($form) ? 3 : 2;
    }

    private static function publishedForm(int $form): bool
    {
        return get_post_type($form) === 'give_forms' && get_post_status($form) === 'publish';
    }

    private static function statusUrl(string $source, string $handle): string
    {
        return add_query_arg(self::QUERY, $handle, $source . '/');
    }

    private static function handoff(string $source, int $form, string $intentHash, string $cookieHash): string
    {
        return self::sign('handoff', $source . '|' . $form . '|' . $intentHash . '|' . $cookieHash);
    }

    private static function source(): string
    {
        return ConnectionStore::source((int) get_current_blog_id());
    }

    private static function sameSite(string $url, string $source): bool
    {
        return self::origin($url) !== null && self::origin($url) === self::origin($source);
    }

    private static function origin(string $url): ?string
    {
        $parts = wp_parse_url($url);
        if (
            !is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
        ) {
            return null;
        }
        $port = $parts['port'] ?? 443;

        return 'https://' . strtolower($parts['host']) . ($port === 443 ? '' : ':' . $port);
    }

    private static function cookieName(int $site, string $source, string $intentHash): string
    {
        return '__Host-paymos-givewp-' . substr(self::siteScope($site, $source), 0, 12) . '-' . substr($intentHash, 0, 24);
    }

    private static function siteScope(int $site, string $source): string
    {
        return hash('sha256', $site . '|' . $source);
    }

    private static function cookie(string $name): ?string
    {
        $value = self::param($_COOKIE, $name);

        return $value !== null && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1 ? $value : null;
    }

    private static function param(array $values, string $key): ?string
    {
        return isset($values[$key]) && is_string($values[$key]) ? wp_unslash($values[$key]) : null;
    }

    private static function sign(string $purpose, string $data): string
    {
        return hash_hmac('sha256', 'paymos-givewp-v1|' . $purpose . '|' . $data, wp_salt('nonce'));
    }

    private static function denied(): WP_Error
    {
        return new WP_Error('paymos_givewp_checkout_unavailable', __('Paymos is unavailable for this donation.', 'paymos-stablecoin-donations'), ['status' => 403]);
    }
}
