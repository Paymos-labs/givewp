<?php

declare(strict_types=1);

use Give\DonationForms\Repositories\DonationFormRepository;
use Give\Donations\Models\Donation;
use Give\Donations\Properties\BillingAddress;
use Give\Donors\Models\Donor;
use Give\Session\SessionDonation\DonationAccessor;
use Give\Donations\ValueObjects\DonationMode;
use Give\Donations\ValueObjects\DonationStatus;
use Give\Donations\ValueObjects\DonationType;
use Give\Framework\PaymentGateways\Commands\RedirectOffsite;
use Give\Framework\PaymentGateways\PaymentGatewayRegister;
use Give\Framework\Support\ValueObjects\Money;
use PaymosGivewp\AttemptStore;
use PaymosGivewp\Checkout;
use PaymosGivewp\ConnectionStore;
use PaymosGivewp\Gateway;

final class GivewpCheckout
{
    private const HOME = 'https://example.invalid/shop';
    private const ORIGIN = 'https://example.invalid';

    public static $donations = [];
    public static $options = [];
    public static $principal = 0;
    public static $giveTestMode = true;
    public static $sessionId = 'native-donor-session';
    public static $receiptSession;
    public static $receiptEmail;
    public static $sessionWrites = [];
    public static $purchaseSetterCalls = 0;
    public static $donationReads = [];
    public static $donors = [];
    public static $dropSessionWrite;
    public static $formatting = [];
    public static $registry;
    public static $requests = [];
    public static $invoices = [];
    public static $loseNextResponse = false;

    public static function testTimeoutUsesSavedId(): void
    {
        foreach ([22, 33] as $form) {
            self::reset();
            $first = self::bootstrap($form, 'a');
            $prefix = self::HOME . '/?paymos_givewp=';
            $resume = $first['resume_url'] ?? '';
            $resumeHandle = substr($resume, strlen($prefix));
            self::check(strpos($resume, $prefix) === 0 && preg_match('/\A[a-f0-9]{64}\z/', $resumeHandle) === 1,
                'Bootstrap did not return an exact first-party recovery URL.');
            self::check(Checkout::status($resumeHandle) === null && self::$requests === [] && $GLOBALS['wpdb']->rows === [],
                'The recovery URL authorized an attempt before the native submit.');
            $retry = Checkout::bootstrapRequest(new WP_REST_Request(self::ORIGIN, ['form' => $form, 'intent' => str_repeat('a', 32)]));
            self::check($retry instanceof WP_REST_Response && $retry->data['resume_url'] === $resume && !isset($retry->headers['Set-Cookie']),
                'Preparing the same browser intent changed its recovery URL or cookie.');
            $donation = self::donation($form, 51);
            $handle = self::submit($donation, $first);
            self::check($handle === $resumeHandle, 'The native continuation differs from the earlier recovery URL.');
            $row = Checkout::status($resumeHandle);
            self::check($row !== null && $row['amount'] === '12.68' && $row['currency'] === 'USD' && $row['native_mode'] === 'test', 'The original charged amount, fee, currency or mode was lost.');
            self::check(self::$requests === [], 'Native registration reached the external API.');

            $duplicate = self::donation($form, 52);
            self::check(self::submit($duplicate, $first) === $handle && count($GLOBALS['wpdb']->rows) === 1, 'A duplicate native draft replaced the original attempt.');
            $duplicate->donorId = 97;
            self::check(self::submit($duplicate, $first) === 'unavailable', 'A different donor accessed the original intent.');
            $duplicate->donorId = 7;
            $duplicate->amount = new Money('1269', 'USD');
            self::check(self::submit($duplicate, $first) === 'unavailable', 'An altered native amount reused the original intent.');
            $duplicate->amount = new Money('1268', 'EUR');
            self::check(self::submit($duplicate, $first) === 'unavailable', 'An altered native currency reused the original intent.');

            self::$principal = 78;
            self::check(Checkout::status($handle) === null && self::submit($donation, $first) === 'unavailable', 'A different WordPress principal accessed the intent.');
            self::$principal = 0;
            $cookies = $_COOKIE;
            $_COOKIE = [];
            self::check(Checkout::status($handle) === null && self::submit($donation, $first) === 'unavailable', 'The bearer token worked without the original browser cookie.');
            $newCookie = self::bootstrap($form, 'a');
            self::check(self::submit(self::donation($form, 54), $newCookie) === 'unavailable' && count($GLOBALS['wpdb']->rows) === 1, 'The original intent created another attempt after cookie loss.');
            $_COOKIE = $cookies;
            $tampered = $first;
            $tampered['token'] .= '0';
            self::check(self::submit($donation, $tampered) === 'unavailable', 'An altered submit token worked.');
            $expired = self::expired($first);
            self::check(self::submit($donation, $expired) === 'unavailable', 'A correctly signed but expired submit token worked.');

            $csrf = Checkout::actionNonce($handle, $row, 'start');
            self::check(Checkout::start($handle, Checkout::actionNonce($handle, $row, 'receipt')) === null && self::$requests === [], 'The receipt signature authorized invoice creation.');
            if ($form === 22) {
                self::$sessionId = 'different-native-session';
                self::check(Checkout::start($handle, $csrf) === null && self::$requests === [], 'A foreign legacy GiveWP session could start the payment.');
                self::$sessionId = 'native-donor-session';
            }
            self::loseResponse($handle, $csrf);
            self::check(Checkout::status($handle)['status'] === 'creating', 'The unknown API result was not recorded durably.');
            $redirect = Checkout::start($handle, $csrf);
            self::check($redirect === 'https://checkout.paymos.io/invoice/inv_fixture_1', 'The exact saved invoice did not resume.');
            self::check(count(self::$requests) === 2 && count(self::$invoices) === 1, 'An ambiguous retry created a second invoice.');
            $sent = self::$requests[0]['body'];
            self::check($sent === self::$requests[1]['body'] && $sent === [
                'project_id' => 'prj_fixture',
                'amount' => '12.68',
                'currency' => 'USD',
                'external_order_id' => $row['external_order_id'],
            ], 'Create exposed extra fields or changed the exact saved tuple.');
            self::check(Checkout::start($handle, $csrf) === $redirect && array_column(self::$requests, 'method') === ['POST', 'POST', 'GET'], 'An already bound invoice did not resume with read-only verification.');
            self::check($donation->status->isPending() && $duplicate->status->isPending(), 'Checkout changed a native donation status.');
            self::assertReceipt($resumeHandle, $donation, $form);

            $other = self::bootstrap($form, 'b');
            $otherHandle = self::submit(self::donation($form, 53), $other);
            $otherRow = Checkout::status($otherHandle);
            self::check($otherRow !== null && $other['resume_url'] === $prefix . $otherHandle && $otherHandle !== $handle
                && $otherRow['external_order_id'] !== $row['external_order_id'], 'An independent donation was deduplicated by donor or amount.');
        }

        echo "PASS both native form versions, exact Money plus Fee Recovery, browser binding, timeout and original receipt\n";
    }

    public static function testModeAndInactiveGeneration(): void
    {
        foreach (['sandbox' => DonationMode::LIVE(), 'live' => DonationMode::TEST()] as $environment => $foreignMode) {
            self::reset($environment);
            $prepared = self::bootstrap(33, 'c');
            $donation = self::donation(33, 56);
            $donation->mode = $foreignMode;
            self::check(self::submit($donation, $prepared) === 'unavailable' && self::$requests === [] && $GLOBALS['wpdb']->rows === [], 'A native/paymos mode mismatch created an attempt or contacted Paymos.');
        }

        self::reset();
        $first = self::bootstrap(33, 'd');
        $handle = self::submit(self::donation(33, 57), $first);
        $row = Checkout::status($handle);
        $csrf = Checkout::actionNonce($handle, $row, 'start');
        self::loseResponse($handle, $csrf);

        $next = self::bootstrap(33, 'e');
        $newHandle = self::submit(self::donation(33, 58), $next);
        $newRow = Checkout::status($newHandle);
        $lock = ConnectionStore::lock(7);
        self::check($lock->acquire(), 'Fixture connection mutex unavailable.');
        try {
            ConnectionStore::disable(7, $lock);
        } finally {
            $lock->release();
        }

        self::check(Checkout::start($newHandle, Checkout::actionNonce($newHandle, $newRow, 'start')) === null && count(self::$requests) === 1, 'Disabled checkout contacted Paymos for an unstarted attempt.');
        self::check(Checkout::start($handle, $csrf) === null, 'Inactive generation returned a payable redirect.');
        self::check(array_column(self::$requests, 'method') === ['POST', 'GET', 'GET'], 'Unknown recovery used a write after disable.');
        self::check(Checkout::status($handle)['invoice_id'] === 'inv_fixture_1', 'Read-only recovery did not bind the exact original invoice.');
        self::check(self::$requests[1]['query'] === ['external_order_id' => $row['external_order_id'], 'limit' => '2', 'project_id' => 'prj_fixture'], 'Read-only recovery failed to use the original external/project tuple.');
        self::check(Checkout::start($handle, $csrf) === null && array_column(self::$requests, 'method') === ['POST', 'GET', 'GET', 'GET'], 'An inactive bound invoice was not checked with a direct GET.');
        echo "PASS both native mode mismatches and inactive generation GET-only recovery\n";
    }

    private static function reset(string $environment = 'sandbox'): void
    {
        $GLOBALS['wpdb'] = new GivewpCheckoutDb();
        self::$donations = self::$requests = self::$invoices = $_COOKIE = [];
        self::$options = ['home' => self::HOME, 'paymos_givewp_schema' => '1'];
        self::$principal = 0;
        self::$giveTestMode = $environment === 'sandbox';
        self::$sessionId = 'native-donor-session';
        self::$receiptSession = null;
        self::$receiptEmail = null;
        self::$sessionWrites = [];
        self::$purchaseSetterCalls = 0;
        self::$donationReads = [];
        self::$dropSessionWrite = null;
        self::$formatting = ['currency' => 'USD', 'thousands_separator' => ',', 'decimal_separator' => '.', 'number_decimals' => 2];
        self::$donors = [7 => new Donor(['id' => 7, 'userId' => 86, 'email' => 'changed-profile@example.invalid'])];
        self::$loseNextResponse = false;
        $lock = ConnectionStore::lock(7);
        self::check($lock->acquire(), 'Fixture connection mutex unavailable.');
        try {
            $kind = $environment === 'sandbox' ? 'test' : 'live';
            ConnectionStore::connect(7, self::HOME, $environment, [$environment => [
                'api_key' => "pk_{$kind}_fixture",
                'api_secret' => "sk_{$kind}_fixture",
                'project_id' => 'prj_fixture',
                'webhook_secret' => 'whsec_fixture',
            ]], $lock);
        } finally {
            $lock->release();
        }
    }

    private static function bootstrap(int $form, string $digit): array
    {
        $body = ['form' => $form, 'intent' => str_repeat($digit, 32)];
        self::check(Checkout::bootstrapRequest(new WP_REST_Request('https://evil.invalid', $body)) instanceof WP_Error, 'Foreign Origin was accepted.');
        $response = Checkout::bootstrapRequest(new WP_REST_Request(self::ORIGIN, $body));
        self::check($response instanceof WP_REST_Response && $response->headers['Cache-Control'] === 'private, no-store', 'The first-party bootstrap is not private.');
        $setCookie = $response->headers['Set-Cookie'];
        self::check(strpos($setCookie, '; Path=/; Secure; HttpOnly; SameSite=Lax') !== false, 'Browser authentication cookie is not host-only, Secure, HttpOnly and Lax.');
        [$name, $value] = explode('=', explode(';', $setCookie, 2)[0], 2);
        self::check(strpos($name, '__Host-paymos-givewp-') === 0 && !isset($response->data['cookie'])
            && strpos(json_encode($response->data), $value) === false, 'Bootstrap exposed browser authentication.');
        $_COOKIE[$name] = $value;

        return $response->data + ['cookie' => $value];
    }

    private static function expired(array $state): array
    {
        $parts = explode('.', $state['token']);
        $parts[4] = (string) (time() - 1);
        $payload = implode('.', array_slice($parts, 0, 5));
        $state['token'] = $payload . '.' . hash_hmac('sha256', 'paymos-givewp-v1|submit|' . self::HOME . '|' . $payload . '|' . $state['cookie'], wp_salt('nonce'));

        return $state;
    }

    private static function donation(int $form, int $id): Donation
    {
        $donation = new Donation([
            'id' => $id,
            'formId' => $form,
            'donorId' => 7,
            'purchaseKey' => "original-native-purchase-$id",
            'gatewayId' => 'paymos',
            'mode' => self::$giveTestMode ? DonationMode::TEST() : DonationMode::LIVE(),
            'status' => DonationStatus::PENDING(),
            'type' => DonationType::SINGLE(),
            'amount' => new Money('1268', 'USD'),
            'feeAmountRecovered' => new Money('34', 'USD'),
            'email' => 'native-donor@example.invalid',
            'firstName' => 'Original',
            'lastName' => 'Donor',
            'honorific' => 'Dr',
            'createdAt' => new DateTime('2026-11-13 10:11:12'),
            'billingAddress' => BillingAddress::fromArray([
                'address1' => 'Original address', 'address2' => null, 'city' => 'Paris',
                'state' => '', 'country' => 'FR', 'zip' => '75001',
            ]),
        ]);
        self::$donations[$id] = $donation;

        return $donation;
    }

    private static function submit(Donation $donation, array $state): string
    {
        /** @var Gateway $gateway */
        $gateway = self::$registry->getPaymentGateway('paymos');
        $result = $gateway->createPayment($donation, ['paymos_submit' => $state['token']]);
        self::check($result instanceof RedirectOffsite && strpos($result->redirectUrl, self::HOME . '/?') === 0, 'Native payment did not return the same-site continuation command.');
        parse_str((string) parse_url($result->redirectUrl, PHP_URL_QUERY), $query);

        return $query['paymos_givewp'];
    }

    private static function loseResponse(string $handle, string $csrf): void
    {
        self::$loseNextResponse = true;
        try {
            Checkout::start($handle, $csrf);
            throw new LogicException('The fixture did not lose the Create response.');
        } catch (RuntimeException $error) {
            self::check($error->getMessage() === 'Paymos API request failed.' && count(self::$invoices) === 1, 'Create was not accepted before the mock transport timeout.');
        }
    }

    private static function assertReceipt(string $handle, Donation $donation, int $form): void
    {
        $row = Checkout::status($handle);
        $csrf = Checkout::actionNonce($handle, $row, 'receipt');
        $donation->status = DonationStatus::COMPLETE();
        self::check(Checkout::receipt($handle, $csrf) === null && self::$receiptSession === null, 'A native/manual completion alone exposed the receipt.');
        $lock = AttemptStore::lock($row['donation_id']);
        self::check($lock->acquire(), 'Fixture donation mutex unavailable.');
        try {
            AttemptStore::update($row, ['status' => 'verified_paid'], $lock);
        } finally {
            $lock->release();
        }

        $purchase = $donation->purchaseKey;
        $donation->purchaseKey = 'altered-native-purchase';
        self::check(Checkout::receipt($handle, $csrf) === null, 'An altered original purchase key unlocked the receipt.');
        $donation->purchaseKey = $purchase;
        $url = Checkout::receipt($handle, $csrf);
        if ($form === 33) {
            self::check($url === self::HOME . '/?givewp-route=donation-confirmation-receipt-view&receipt-id=' . $purchase && self::$receiptSession === null, 'Visual receipt did not use the original native route and key.');
        } else {
            self::check($url === self::HOME . '/success?payment-confirmation=paymos', 'Legacy receipt URL differs.');
            $reader = (new DonationAccessor())->get();
            self::check($reader->id === 51 && $reader->purchaseKey === $purchase
                && $reader->donorInfo->wpUserId === 0 && $reader->donorInfo->firstName === 'Original'
                && $reader->donorInfo->address->postalCode === '75001'
                && (int) $reader->formEntry->formId === 22 && (int) $reader->formEntry->wpUserId === 0
                && $reader->cardInfo->address->line1 === 'Original address'
                && $reader->cardInfo->address->postalCode === '75001'
                && self::$receiptEmail === 'native-donor@example.invalid', 'Actual native receipt reader lost the original full session.');
        }
    }

    private static function receiptFixture(int $principal = 0, int $form = 22, string $minor = '1268', string $currency = 'USD'): array
    {
        self::reset();
        self::$principal = $principal;
        $state = self::bootstrap($form, 'c');
        $donation = self::donation($form, 101);
        $donation->amount = new Money($minor, $currency);
        $handle = self::submit($donation, $state);
        $row = Checkout::status($handle);
        $lock = AttemptStore::lock(101);
        self::check($lock->acquire(), 'Receipt fixture lock unavailable.');
        try {
            AttemptStore::update($row, ['status' => 'verified_paid'], $lock);
        } finally {
            $lock->release();
        }
        $donation->status = DonationStatus::COMPLETE();

        return [$handle, Checkout::actionNonce($handle, $row, 'receipt'), $donation];
    }

    /** Independent expected schema, not obtained from the production builder. */
    private static function expectedPurchase(int $principal = 0, string $amount = '12.68'): array
    {
        return [
            'donation_id' => 101, 'price' => $amount, 'purchase_key' => 'original-native-purchase-101',
            'user_email' => 'native-donor@example.invalid', 'date' => '2026-11-13 10:11:12', 'gateway' => 'paymos',
            'user_info' => [
                'id' => $principal, 'first_name' => 'Original', 'last_name' => 'Donor', 'title' => 'Dr',
                'email' => 'native-donor@example.invalid',
                'address' => ['line1' => 'Original address', 'line2' => '', 'city' => 'Paris', 'state' => '', 'country' => 'FR', 'postalCode' => '75001'],
            ],
            'post_data' => [
                'give-form-id' => '22', 'give-amount' => $amount, 'give-first' => 'Original', 'give-last' => 'Donor',
                'give-email' => 'native-donor@example.invalid', 'give-user-id' => (string) $principal, 'give-gateway' => 'paymos',
            ],
            'card_info' => ['card_address' => 'Original address', 'card_address_2' => '', 'card_city' => 'Paris', 'card_state' => '', 'card_country' => 'FR', 'card_zip' => '75001'],
        ];
    }

    public static function testLegacyReceiptSessionContract(): void
    {
        foreach ([0, 45] as $principal) {
            foreach ([null, false, []] as $missing) {
                [$handle, $csrf] = self::receiptFixture($principal);
                self::$receiptSession = $missing;
                $_POST = ['give_first' => 'Attacker', 'give-email' => 'foreign@example.invalid', 'card_number' => 'DO-NOT-COPY'];
                $requests = self::$requests;
                self::check(Checkout::receipt($handle, $csrf) === self::HOME . '/success?payment-confirmation=paymos', 'Missing purchase could not recover inside its original session.');
                self::check(self::$receiptSession === self::expectedPurchase($principal)
                    && self::$receiptEmail === 'native-donor@example.invalid' && self::$sessionWrites === ['give_purchase', 'give_email']
                    && self::$purchaseSetterCalls === 1,
                    'Recovery did not use the complete pinned snapshot and exactly one public setter call.');
                $reader = (new DonationAccessor())->get();
                self::check($reader->id === 101 && $reader->totalAmount === '12.68'
                    && $reader->donorInfo->wpUserId === $principal && $reader->donorInfo->firstName === 'Original'
                    && $reader->donorInfo->address->postalCode === '75001'
                    && (int) $reader->formEntry->wpUserId === $principal && (int) $reader->formEntry->formId === 22
                    && $reader->cardInfo->address->line1 === 'Original address' && $reader->cardInfo->address->postalCode === '75001'
                    && $reader->createdAt->format('Y-m-d H:i:s') === '2026-11-13 10:11:12', 'Actual native reader mapped a wrong value.');
                $before = serialize([self::$receiptSession, self::$receiptEmail]);
                self::$sessionWrites = [];
                self::check(Checkout::receipt($handle, $csrf) !== null && self::$sessionWrites === []
                    && serialize([self::$receiptSession, self::$receiptEmail]) === $before && self::$requests === $requests,
                    'A repeated receipt rewrote session or reached Paymos.');
            }
        }
        [$handle, $csrf, $donation] = self::receiptFixture();
        $donation->billingAddress = null;
        $donation->lastName = null;
        $donation->honorific = null;
        self::check(Checkout::receipt($handle, $csrf) !== null, 'Optional absent native address/name was rejected.');
        $reader = (new DonationAccessor())->get();
        self::check($reader->donorInfo->wpUserId === 0 && $reader->donorInfo->address->postalCode === ''
            && $reader->cardInfo->address->line1 === '' && $reader->cardInfo->address->postalCode === ''
            && $reader->donorInfo->lastName === '' && $reader->donorInfo->honorific === '', 'Null optional fields did not normalize safely.');

        // Source-captured producer forms: float price, underscore names, no guest post identity,
        // false optional address, and a same-donor linked WP identity without a WP login.
        foreach ([0, 86] as $nativeIdentity) {
            foreach ([false, ['line1' => 'Original address', 'line2' => false, 'city' => 'Paris', 'state' => false, 'country' => 'FR', 'zip' => '75001']] as $address) {
                [$handle, $csrf] = self::receiptFixture();
                $purchase = self::expectedPurchase();
                $purchase['price'] = 12.68;
                $purchase['donation_id'] = '101';
                $purchase['user_info']['id'] = (string) $nativeIdentity;
                $purchase['user_info']['address'] = $address;
                foreach (['first', 'last', 'email'] as $name) {
                    $purchase['post_data']['give_' . $name] = $purchase['post_data']['give-' . $name];
                    unset($purchase['post_data']['give-' . $name]);
                }
                unset($purchase['post_data']['give-user-id']);
                $purchase['receipt_extension'] = ['custom' => 'preserved'];
                $purchase['post_data']['custom_field'] = 'custom native input';
                self::$receiptSession = $purchase;
                self::$receiptEmail = 'native-donor@example.invalid';
                $before = serialize([self::$receiptSession, self::$receiptEmail]);
                $baseline = (new DonationAccessor())->get();
                self::check(Checkout::receipt($handle, $csrf) !== null && self::$sessionWrites === []
                    && serialize([self::$receiptSession, self::$receiptEmail]) === $before && self::$principal === 0
                    && Checkout::status($handle)['principal_id'] === 0 && self::$requests === [], 'Valid native producer payload was changed, denied or promoted.');
                $reader = (new DonationAccessor())->get();
                self::check((int) $reader->donorInfo->wpUserId === $nativeIdentity && $reader->formEntry->wpUserId === null
                    && serialize($reader) === serialize($baseline), 'Preserved producer values differ from the actual native reader baseline.');
            }
        }
        echo "PASS receipt complete reconstruction, exact native parser, original guest/registered identity, native producer preservation and repeat no-write\n";
    }


    /** The native adapter does not transfer FormData.purchaseKey into its new Donation. */
    public static function testLegacyInterimKeyPreservation(): void
    {
        $failures = [];
        $cases = 0;
        foreach ([0, 45] as $principal) {
            foreach (['original-native-purchase-101', '8cb2237d0679ca88db6464eac60da963', 'filtered:legacy-key', 'original-native-purchase-102'] as $rawKey) {
                foreach (['native-donor@example.invalid', null] as $email) {
                    [$handle, $csrf, $original] = self::receiptFixture($principal);
                    self::donation(22, 102); // A real separate fixture model; its key must never select this donation.
                    $purchase = self::expectedPurchase($principal);
                    $purchase['purchase_key'] = $rawKey;
                    $purchase['price'] = 12.68;
                    $purchase['user_info']['address'] = false;
                    if ($principal === 0) {
                        $purchase['user_info']['id'] = 86; // Native known-donor guest, still WP principal0.
                        unset($purchase['post_data']['give-user-id']);
                    }
                    $purchase['receipt_extension'] = ['nested' => ['preserve' => true]];
                    $purchase['post_data']['custom_field'] = 'native extension';
                    self::$receiptSession = $purchase;
                    self::$receiptEmail = $email;
                    $before = serialize([$purchase, $email]);
                    $baseline = (new DonationAccessor())->get();
                    self::$donationReads = [];
                    try {
                        for ($repeat = 0; $repeat < 2; ++$repeat) {
                            self::check(Checkout::receipt($handle, $csrf) === self::HOME . '/success?payment-confirmation=paymos', 'Complete native interim-key session was refused.');
                            self::check(self::$purchaseSetterCalls === 0 && self::$sessionWrites === []
                                && serialize([self::$receiptSession, self::$receiptEmail]) === $before
                                && self::$principal === $principal && self::$requests === [], 'Existing raw payload/email/key or authorization was changed.');
                            $reader = (new DonationAccessor())->get();
                            self::check($reader->purchaseKey === $rawKey && serialize($reader) === serialize($baseline)
                                && !in_array(102, self::$donationReads, true) && Checkout::status($handle)['donation_id'] === $original->id,
                                'Raw derived key selected a foreign donation or altered the native reader.');
                        }
                    } catch (Throwable $error) {
                        $failures[] = ['principal' => $principal, 'key' => $rawKey, 'emailMissing' => $email === null];
                    }
                    ++$cases;
                }
            }
        }
        foreach (['canonical-model-key', 'canonical-attempt-hash', 'foreign-donation', 'foreign-form', 'foreign-email', 'foreign-amount'] as $guard) {
            [$handle, $csrf, $donation] = self::receiptFixture();
            self::$receiptSession = self::expectedPurchase();
            self::$receiptSession['purchase_key'] = 'filtered:interim-key';
            if ($guard === 'canonical-model-key') { $donation->purchaseKey = 'changed-persisted-key'; }
            elseif ($guard === 'canonical-attempt-hash') { $row = Checkout::status($handle); $GLOBALS['wpdb']->rows[$row['id']]['purchase_hash'] = str_repeat('0', 64); }
            elseif ($guard === 'foreign-donation') { self::$receiptSession['donation_id'] = 102; }
            elseif ($guard === 'foreign-form') { self::$receiptSession['post_data']['give-form-id'] = '33'; }
            elseif ($guard === 'foreign-email') { self::$receiptSession['user_email'] = 'foreign@example.invalid'; }
            else { self::$receiptSession['price'] = '12.67'; }
            self::deniedReceipt($handle, $csrf, $guard . ' despite a well-formed interim key');
            self::check(self::$purchaseSetterCalls === 0, 'A rejected authoritative tuple called the setter.');
        }
        echo 'Interim-key cases=' . $cases . ', preserved=' . ($cases - count($failures)) . ', failed=' . count($failures) . "; canonical/foreign tuple guards PASS\n";
        self::check($failures === [], 'Native interim-key preservation failed: ' . json_encode($failures));
    }

    /** Each finding has an actual pinned-native oracle; aggregate failures keep all three REDs visible. */
    public static function testLegacyReceiptReviewRegressions(): void
    {
        $failures = [];
        $cases = [
            'second-stage-card-alias' => static function (): void {
                foreach (['cardcard_address' => 'line1', 'cardcard_zip' => 'postalCode', 'cardcard_city' => 'city'] as $alias => $field) {
                    [$handle, $csrf] = self::receiptFixture();
                    self::$receiptSession = self::expectedPurchase();
                    self::$receiptSession['card_info'][$alias] = ['invalid-native-shadow'];
                    $reader = (new DonationAccessor())->get();
                    self::check(is_array($reader->cardInfo->address->{$field}) && in_array('invalid-native-shadow', $reader->cardInfo->address->{$field}, true), 'Native second card stage no longer demonstrates the alias.');
                    self::deniedReceipt($handle, $csrf, 'native second-stage ' . $alias);
                }
            },
            'internal-native-cast-map' => static function (): void {
                foreach (['caseTo', 'case_to'] as $alias) {
                    [$handle, $csrf] = self::receiptFixture();
                    self::$receiptSession = [$alias => 'broken'] + self::expectedPurchase();
                    $nativeFailed = false;
                    try { (new DonationAccessor())->get(); } catch (Throwable $error) { $nativeFailed = true; }
                    self::check($nativeFailed, 'Native internal cast-map alias no longer demonstrates the failure.');
                    self::deniedReceipt($handle, $csrf, 'native private cast-map ' . $alias);
                }
            },
            'native-formatted-post-amount' => static function (): void {
                foreach ([
                    ['100000', '1000.00', 'USD', '.', ',', '1,000.00'],
                    ['1268', '12.68', 'USD', ',', '.', '12,68'],
                    ['12345678', '123456.78', 'INR', '.', ',', '1,23,456.78'],
                    ['100000', '1000.00', 'USD', ',', ' ', '1 000,00'],
                ] as [$minor, $amount, $currency, $decimal, $thousands, $raw]) {
                    [$handle, $csrf] = self::receiptFixture(0, 22, $minor, $currency);
                    self::$formatting = ['currency' => $currency, 'decimal_separator' => $decimal, 'thousands_separator' => $thousands, 'number_decimals' => 2];
                    self::check(give_format_amount($amount, ['sanitize' => false, 'currency' => $currency]) === $raw, 'Pinned native producer did not format the expected amount.');
                    self::$receiptSession = self::expectedPurchase(0, $amount);
                    self::$receiptSession['post_data']['give-amount'] = $raw;
                    $before = serialize(self::$receiptSession);
                    $baseline = (new DonationAccessor())->get();
                    self::check(Checkout::receipt($handle, $csrf) !== null && self::$sessionWrites === []
                        && serialize(self::$receiptSession) === $before && self::$requests === [], 'Native formatted post amount was denied or rewritten.');
                    self::check(serialize((new DonationAccessor())->get()) === serialize($baseline), 'Native formatted reader changed.');
                }
                foreach ([['1268', '12.68', '12.6801'], ['1234', '12.34', '12.344'], ['1268', '12.68', '1,2.68'], ['1268', '12.68', '12,68'], ['1268', '12.68', '12.68junk']] as [$minor, $amount, $raw]) {
                    [$handle, $csrf] = self::receiptFixture(0, 22, $minor);
                    self::$receiptSession = self::expectedPurchase(0, $amount);
                    self::$receiptSession['post_data']['give-amount'] = $raw;
                    self::deniedReceipt($handle, $csrf, 'foreign/unrounded post amount ' . $raw);
                }
                [$handle, $csrf] = self::receiptFixture();
                self::$formatting['decimal_separator'] = ',';
                self::$formatting['thousands_separator'] = ',';
                self::$receiptSession = self::expectedPurchase();
                self::$receiptSession['post_data']['give-amount'] = '12,68';
                self::deniedReceipt($handle, $csrf, 'conflicting native separators');
            },
        ];
        foreach ($cases as $name => $check) {
            try { $check(); echo 'PASS receipt review regression ' . $name . "\n"; }
            catch (Throwable $error) { $failures[] = $name; echo 'FAIL receipt review regression ' . $name . ': ' . $error->getMessage() . "\n"; }
        }
        self::check($failures === [], 'Receipt source-review regressions failed: ' . implode(', ', $failures));
    }

    private static function deniedReceipt(string $handle, string $csrf, string $case): void
    {
        $before = serialize([self::$receiptSession, self::$receiptEmail]);
        $requests = self::$requests;
        $principal = self::$principal;
        self::$sessionWrites = [];
        self::check(Checkout::receipt($handle, $csrf) === null && self::$sessionWrites === []
            && serialize([self::$receiptSession, self::$receiptEmail]) === $before
            && self::$requests === $requests && self::$principal === $principal, 'Receipt denial failed without mutation: ' . $case);
    }

    public static function testLegacyReceiptSessionRefusal(): void
    {
        [$handle, $csrf] = self::receiptFixture(45);
        $good = self::expectedPurchase(45);
        $cases = 0;
        foreach (array_keys($good) as $key) {
            self::$receiptSession = $good;
            unset(self::$receiptSession[$key]);
            self::deniedReceipt($handle, $csrf, 'missing ' . $key);
            ++$cases;
        }
        foreach (['user_info', 'post_data', 'card_info'] as $section) {
            foreach (array_keys($good[$section]) as $key) {
                self::$receiptSession = $good;
                unset(self::$receiptSession[$section][$key]);
                self::deniedReceipt($handle, $csrf, 'missing ' . $section . '.' . $key);
                ++$cases;
            }
        }
        foreach (array_keys($good['user_info']['address']) as $key) {
            self::$receiptSession = $good;
            unset(self::$receiptSession['user_info']['address'][$key]);
            self::deniedReceipt($handle, $csrf, 'missing address.' . $key);
            ++$cases;
        }
        $bad = [
            ['donation_id', true], ['donation_id', 101.0], ['donation_id', '0101'], ['donation_id', 102],
            ['price', NAN], ['price', INF], ['price', false], ['price', []], ['price', '12.6801'],
            ['purchase_key', ''], ['purchase_key', null], ['purchase_key', false], ['purchase_key', 123],
            ['purchase_key', []], ['purchase_key', new stdClass()], ['purchase_key', 's:3:"key";'], ['user_email', 'foreign@example.invalid'], ['gateway', 'manual'],
            ['date', '2026-02-30 10:11:12'], ['date', '2026-11-13 10:11:12extra'], ['date', []],
            ['user_info', 'not-an-array'], ['post_data', null], ['card_info', new stdClass()],
        ];
        foreach ($bad as [$key, $value]) {
            self::$receiptSession = $good;
            self::$receiptSession[$key] = $value;
            self::deniedReceipt($handle, $csrf, 'invalid ' . $key);
            ++$cases;
        }
        $nested = [
            ['user_info', 'id', 7], ['user_info', 'id', false], ['user_info', 'first_name', []],
            ['user_info', 'title', 'O:8:"stdClass":0:{}'], ['user_info', 'email', 'foreign@example.invalid'],
            ['user_info', 'address', null], ['user_info', 'wpUserId', 45],
            ['post_data', 'give-form-id', '33'], ['post_data', 'give-amount', '12.67'],
            ['post_data', 'give-email', 'foreign@example.invalid'], ['post_data', 'give-user-id', 86],
            ['post_data', 'give-gateway', 'manual'], ['post_data', 'give_first', 'conflicting alias'],
            ['post_data', 'wpUserId', 45], ['post_data', 'firstName', 'shadow'],
            ['card_info', 'address', ['line1' => 'nested']], ['card_info', 'card_zip', []],
            ['card_info', 'city', 'shadow'],
        ];
        foreach ($nested as [$section, $key, $value]) {
            self::$receiptSession = $good;
            self::$receiptSession[$section][$key] = $value;
            self::deniedReceipt($handle, $csrf, 'invalid ' . $section . '.' . $key);
            ++$cases;
        }
        foreach (['donorInfo', 'formEntry', 'createdAt', 'give_donation_id', 'cardInfo', 'purchaseKey', 'purchase__key'] as $key) {
            self::$receiptSession = $good + [$key => 'shadow'];
            self::deniedReceipt($handle, $csrf, 'shadow ' . $key);
            ++$cases;
        }
        foreach (['line1' => [], 'country' => 'a:0:{}', 'postalCode' => new stdClass(), 'zip' => 'different'] as $key => $value) {
            self::$receiptSession = $good;
            self::$receiptSession['user_info']['address'][$key] = $value;
            self::deniedReceipt($handle, $csrf, 'invalid address.' . $key);
            ++$cases;
        }
        foreach (['not-an-array', new stdClass(), ['donation_id' => 101, 'purchase_key' => 'original-native-purchase-101']] as $purchase) {
            self::$receiptSession = $purchase;
            self::deniedReceipt($handle, $csrf, 'malformed old session');
            ++$cases;
        }
        foreach ([null, $good] as $purchase) {
            self::$receiptSession = $purchase;
            self::$receiptEmail = 'foreign@example.invalid';
            self::deniedReceipt($handle, $csrf, 'foreign saved email');
            ++$cases;
        }
        [$handle, $csrf, $donation] = self::receiptFixture();
        self::$donors = [];
        self::deniedReceipt($handle, $csrf, 'missing donor');
        [$handle, $csrf, $donation] = self::receiptFixture();
        $donation->billingAddress->address1 = [];
        self::deniedReceipt($handle, $csrf, 'malformed model address');
        foreach (['', 'different-session'] as $sessionId) {
            [$handle, $csrf] = self::receiptFixture();
            self::$sessionId = $sessionId;
            self::deniedReceipt($handle, $csrf, 'native session unavailable/foreign');
        }
        foreach (['give_purchase', 'give_email'] as $key) {
            [$handle, $csrf] = self::receiptFixture();
            self::$dropSessionWrite = $key;
            self::check(Checkout::receipt($handle, $csrf) === null && self::$requests === [], 'A missing native session write was reported as successful.');
        }
        foreach ([7, 87] as $identity) {
            [$handle, $csrf] = self::receiptFixture();
            self::$receiptSession = self::expectedPurchase();
            self::$receiptSession['user_info']['id'] = $identity;
            self::deniedReceipt($handle, $csrf, 'foreign donor identity');
        }
        [$handle, $csrf] = self::receiptFixture();
        self::$receiptSession = self::expectedPurchase();
        self::$receiptSession['user_info']['id'] = 86;
        self::$donors[7]->userId = 87;
        self::deniedReceipt($handle, $csrf, 'same donor link changed');
        [$handle, $csrf] = self::receiptFixture();
        self::$receiptSession = self::expectedPurchase();
        self::$receiptSession['user_info']['id'] = 86;
        self::$receiptSession['post_data']['give-user-id'] = '86';
        self::deniedReceipt($handle, $csrf, 'positive guest post identity');
        [$handle, $csrf] = self::receiptFixture();
        self::deniedReceipt($handle, $csrf . '0', 'bad action HMAC');
        self::$options['home'] = 'https://other.invalid';
        self::deniedReceipt($handle, $csrf, 'changed source');
        echo 'PASS receipt refusal: ' . $cases . " schema/alias cases plus donor, session, principal and source controls; no session/API writes\n";
    }

    public static function testVisualReceiptDoesNotMutateLegacySession(): void
    {
        [$handle, $csrf] = self::receiptFixture(0, 33);
        self::$receiptSession = ['foreign legacy payload' => ['keep' => 'unchanged']];
        self::$receiptEmail = 'unrelated@example.invalid';
        $before = serialize([self::$receiptSession, self::$receiptEmail]);
        self::check(Checkout::receipt($handle, $csrf) === self::HOME . '/?givewp-route=donation-confirmation-receipt-view&receipt-id=original-native-purchase-101'
            && serialize([self::$receiptSession, self::$receiptEmail]) === $before && self::$sessionWrites === [] && self::$requests === [], 'V3 receipt entered the legacy session branch.');
        echo "PASS V3 receipt uses its unchanged native route without legacy mutation\n";
    }

    public static function check(bool $condition, string $failure): void
    {
        if (!$condition) {
            throw new LogicException($failure);
        }
    }
}

final class GivewpCheckoutDb
{
    public $prefix = 'wp_7_';
    public $last_error = '';
    public $rows = [];
    private $queries = [];
    private $locks = [];

    public function prepare(string $sql, ...$values): string
    {
        $key = (string) count($this->queries);
        $this->queries[$key] = [$sql, $values];

        return $key;
    }

    public function get_var(string $key): string
    {
        [$query, $values] = $this->queries[$key];
        if (strpos($query, 'GET_LOCK(') !== false) {
            if (isset($this->locks[$values[0]])) {
                return '0';
            }
            $this->locks[$values[0]] = true;

            return '45';
        }
        $owned = isset($this->locks[$values[1]]);
        if ($owned && strpos($query, 'RELEASE_LOCK(') !== false) {
            unset($this->locks[$values[1]]);
        }

        return $owned ? '1' : '0';
    }

    public function get_row(string $key, string $format): ?array
    {
        [$query, $values] = $this->queries[$key];
        GivewpCheckout::check(array_shift($values) === AttemptStore::table(), 'Expected a bound attempt table name.');
        if ($query === 'SELECT * FROM %i WHERE %i = %s') {
            $fields = [array_shift($values)];
        } else {
            GivewpCheckout::check($query === 'SELECT * FROM %i WHERE form_id = %d AND intent_hash = %s', 'Unexpected attempt lookup.');
            $fields = ['form_id', 'intent_hash'];
        }
        foreach ($this->rows as $row) {
            $match = true;
            foreach ($fields as $index => $field) {
                $match = $match && (string) $row[$field] === (string) $values[$index];
            }
            if ($match) {
                return $row;
            }
        }

        return null;
    }

    public function insert(string $table, array $row): int
    {
        $id = count($this->rows) + 1;
        $this->rows[$id] = $row + ['id' => $id, 'invoice_id' => null, 'remote_status' => null, 'payment_url' => null];

        return 1;
    }

    public function update(string $table, array $values, array $where): int
    {
        if ($this->rows[$where['id']]['status'] !== $where['status']) {
            return 0;
        }
        $this->rows[$where['id']] = $values + $this->rows[$where['id']];

        return 1;
    }
}

final class WP_Error {}
final class WP_REST_Request
{
    private $origin;
    private $body;
    public function __construct(string $origin, array $body) { $this->origin = $origin; $this->body = $body; }
    public function get_header(string $key): string { return $this->origin; }
    public function get_param(string $key) { return $this->body[$key] ?? null; }
}

final class WP_REST_Response
{
    public $data;
    public $headers = [];
    public function __construct(array $data) { $this->data = $data; }
    public function header(string $key, string $value): void { $this->headers[$key] = $value; }
}

function wp_safe_remote_request(string $url, array $args)
{
    GivewpCheckout::check(parse_url($url, PHP_URL_HOST) === 'api.paymos.io' && $args['redirection'] === 0 && $args['sslverify'], 'The mock received a request outside the signed HTTPS transport.');
    $path = parse_url($url, PHP_URL_PATH);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $body = json_decode((string) $args['body'], true);
    $method = $args['method'];
    GivewpCheckout::$requests[] = compact('path', 'query', 'body', 'method');

    if ($method === 'POST' && $path === '/v1/invoices') {
        $id = $body['external_order_id'];
        $row = AttemptStore::byExternalOrder($id);
        GivewpCheckout::check($row !== null && $row['status'] === 'creating', 'The request started before the external ID was committed.');
        if (!isset(GivewpCheckout::$invoices[$id])) {
            $invoice = 'inv_fixture_' . (count(GivewpCheckout::$invoices) + 1);
            GivewpCheckout::$invoices[$id] = [
                'invoice_id' => $invoice,
                'project_id' => $body['project_id'],
                'status' => 'awaiting_payment',
                'is_final' => false,
                'is_test' => true,
                'payment_url' => 'https://checkout.paymos.io/invoice/' . $invoice,
                'order' => ['external_id' => $id, 'amount' => $body['amount'], 'currency' => $body['currency']],
            ];
        }
        if (GivewpCheckout::$loseNextResponse) {
            GivewpCheckout::$loseNextResponse = false;

            return new WP_Error();
        }
        $data = GivewpCheckout::$invoices[$id];
    } elseif ($method === 'GET' && $path === '/v1/invoices') {
        $data = ['items' => [GivewpCheckout::$invoices[$query['external_order_id']]], 'next_cursor' => null];
    } else {
        $data = null;
        foreach (GivewpCheckout::$invoices as $invoice) {
            if ($method === 'GET' && $path === '/v1/invoices/' . $invoice['invoice_id']) {
                $data = $invoice;
                break;
            }
        }
        GivewpCheckout::check($data !== null, 'Unexpected mock API request.');
    }

    return ['body' => json_encode($data)];
}

function add_action(...$arguments): void {}
function add_filter(...$arguments): void {}
function apply_filters(string $tag, $value) {
    return in_array($tag, ['give_enabled_payment_gateways', 'givewp_donation_form_enabled_gateways'], true)
        ? PaymosGivewp\Plugin::filterEnabledGateways($value) : $value;
}
function get_option(string $key, $default = false) { return GivewpCheckout::$options[$key] ?? $default; }
function update_option(string $key, $value, bool $autoload): bool { GivewpCheckout::$options[$key] = $value; return true; }
function give_is_test_mode(): bool { return GivewpCheckout::$giveTestMode; }
function give_get_option(string $key, $default = false) { return $key === 'gateways_v3' ? ['paymos' => 1] : (GivewpCheckout::$formatting[$key] ?? $default); }
function give_get_settings(): array { return GivewpCheckout::$formatting; }
function give_get_currency($id = null): string { return GivewpCheckout::$formatting['currency'] ?? 'USD'; }
function give_get_currencies($scope = null): array { return []; }
function give_is_zero_based_currency($currency): bool { return false; }
function wp_parse_args($args, array $defaults = []): array { return array_merge($defaults, $args); }
function give_get_enabled_payment_gateways(int $form, int $version): array { return apply_filters('give_enabled_payment_gateways', ['paymos' => ['checkout_label' => 'Paymos']]); }
function give_get_default_gateway(int $form, int $version): string { return 'paymos'; }
function give_get_currencies_list(): array { return []; }
function give_get_ip(): string { return '192.0.2.2'; }
function get_post_type(int $form): string { return in_array($form, [22, 33], true) ? 'give_forms' : 'page'; }
function get_post_status(int $form): string { return 'publish'; }
function get_current_blog_id(): int { return 7; }
function get_current_user_id(): int { return GivewpCheckout::$principal; }
function wp_salt(string $scheme): string { return 'test-only-' . $scheme; }
function wp_unslash(string $value): string { return stripslashes($value); }
function wp_parse_url(string $url, int $component = -1) { return parse_url($url, $component); }
function __(string $value, string $domain = ''): string { return $value; }
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code(array $response): int { return 200; }
function wp_remote_retrieve_body(array $response): string { return $response['body']; }
function wp_remote_retrieve_headers(array $response): array { return []; }
function home_url(string $path = ''): string { return get_option('home') . $path; }
function give_get_success_page_url(): string { return home_url('/success'); }
function esc_url_raw(string $value): string { return $value; }
function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function is_serialized($value): bool {
    return is_string($value) && preg_match('/\A(?:N;|(?:a|O|C|s):[0-9]+:|(?:b|i|d):[^\n]*;\z)/', trim($value)) === 1;
}
function give_get_purchase_session() { return give()->session->get('give_purchase'); }
function give_set_purchase_session(array $value): void {
    ++GivewpCheckout::$purchaseSetterCalls;
    give()->session->set('give_purchase', $value);
    give()->session->set('give_email', $value['user_email']);
}
function trailingslashit(string $url): string { return rtrim($url, '/') . '/'; }
function add_query_arg($key, $value, ?string $url = null): string {
    return is_array($key) ? $value . '?' . http_build_query($key) : $url . '?' . http_build_query([$key => $value]);
}

function give(?string $class = null)
{
    static $fixture;
    if ($fixture === null) {
        $fixture = new class {
            public $donations;
            public $donors;
            public $form_meta;
            public $session;

            public function __construct()
            {
                $this->donations = new class {
                    public function getById(int $id): ?Donation { GivewpCheckout::$donationReads[] = $id; return GivewpCheckout::$donations[$id] ?? null; }
                };
                $this->donors = new class {
                    public function getById(int $id): ?Donor { return GivewpCheckout::$donors[$id] ?? null; }
                };
                $this->form_meta = new class {
                    public function get_meta(int $form, string $name, bool $single): bool { return $form === 33; }
                };
                $this->session = new class {
                    public function has_session(): bool { return GivewpCheckout::$sessionId !== ''; }
                    public function get_donor_id(): string { return GivewpCheckout::$sessionId; }
                    public function get(string $key, $default = null) {
                        return $key === 'give_purchase' ? GivewpCheckout::$receiptSession : GivewpCheckout::$receiptEmail;
                    }
                    public function set(string $key, $value): void {
                        GivewpCheckout::$sessionWrites[] = $key;
                        if (GivewpCheckout::$dropSessionWrite === $key) { return; }
                        if ($key === 'give_purchase') { GivewpCheckout::$receiptSession = $value; }
                        elseif ($key === 'give_email') { GivewpCheckout::$receiptEmail = $value; }
                        else { throw new LogicException('Unexpected native session write.'); }
                    }
                };
            }

            public function singleton(...$arguments): void {}
            public function singletonIf(...$arguments): void {}
        };
    }
    if ($class === DonationFormRepository::class) {
        return new DonationFormRepository(GivewpCheckout::$registry);
    }

    return $class === null ? $fixture : new $class();
}

if ($argc !== 2 || !is_file($argv[1]) || hash_file('sha256', $argv[1]) !== 'e4c2e75aeb7225105950e5d1e251af5050fbcdac7fee6bb20dd0853b536f5ecf') {
    fwrite(STDERR, "Usage: php CheckoutTest.php <pinned GiveWP 4.19.1.1 ZIP>\n");
    exit(2);
}

define('ABSPATH', __DIR__);
define('ARRAY_A', 'ARRAY_A');
define('GIVE_VERSION', '4.19.1.1');
$native = 'phar://' . str_replace('\\', '/', realpath($argv[1])) . '/give/';
require $native . 'vendor/autoload.php';
require $native . 'includes/formatting.php';
spl_autoload_register(static function (string $class) use ($native): void {
    if (strncmp($class, 'Give\\', 5) === 0) {
        $file = $native . 'src/' . str_replace('\\', '/', substr($class, 5)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

require dirname(__DIR__) . '/paymos-givewp.php';
GivewpCheckout::$registry = new PaymentGatewayRegister();
PaymosGivewp\Plugin::registerGateway(GivewpCheckout::$registry);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (error_reporting() & $severity) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});
GivewpCheckout::testTimeoutUsesSavedId();
GivewpCheckout::testModeAndInactiveGeneration();
GivewpCheckout::testLegacyInterimKeyPreservation();
GivewpCheckout::testLegacyReceiptSessionContract();
GivewpCheckout::testLegacyReceiptSessionRefusal();
GivewpCheckout::testVisualReceiptDoesNotMutateLegacySession();
GivewpCheckout::testLegacyReceiptReviewRegressions();
