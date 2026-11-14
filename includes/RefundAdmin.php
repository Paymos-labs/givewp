<?php

declare(strict_types=1);

namespace PaymosGivewp;

use Give\Framework\Permissions\Facades\UserPermissions;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

final class RefundAdmin
{
    private const PAGE = 'paymos-givewp-refunds';
    private const ACTIONS = ['status', 'request', 'poll', 'cleanup'];
    private static $pageHook;

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
        add_action('rest_api_init', [self::class, 'registerRoutes']);
    }

    public static function menu(): void
    {
        if (!self::canEdit()) {
            return;
        }
        self::$pageHook = add_submenu_page(
            'edit.php?post_type=give_forms',
            __('Paymos refunds', 'paymos-stablecoin-donations'),
            __('Paymos refunds', 'paymos-stablecoin-donations'),
            UserPermissions::donations()->editCap(),
            self::PAGE,
            [self::class, 'render']
        );
    }

    public static function registerRoutes(): void
    {
        if (!Install::refundsReady()) {
            return;
        }
        foreach (self::ACTIONS as $action) {
            register_rest_route(ConnectController::REST_NAMESPACE, '/refunds/' . $action, [
                'methods' => 'POST',
                'permission_callback' => [self::class, 'authorize'],
                'callback' => static function (WP_REST_Request $request) use ($action) {
                    return self::dispatch($request, $action);
                },
            ]);
        }
    }

    /** @return true|WP_Error */
    public static function authorize(WP_REST_Request $request)
    {
        $nonce = $request->get_header('x-wp-nonce');
        if (!self::canEdit() || get_current_user_id() <= 0 || wp_get_session_token() === ''
            || !is_string($nonce) || $nonce === '' || !wp_verify_nonce($nonce, 'wp_rest')) {
            return self::error('forbidden', __('You cannot manage these GiveWP refunds.', 'paymos-stablecoin-donations'), 403);
        }

        return true;
    }

    /** @return WP_REST_Response|WP_Error */
    public static function dispatch(WP_REST_Request $request, string $action)
    {
        $authorized = self::authorize($request);
        if ($authorized !== true) {
            return $authorized;
        }
        if (!Install::refundsReady() || !in_array($action, self::ACTIONS, true)) {
            return self::unavailable();
        }

        $data = $request->get_json_params();
        $site = (int) get_current_blog_id();
        if (!is_array($data) || ($data['blog_id'] ?? null) !== $site) {
            return self::invalid();
        }
        if ($action === 'cleanup') {
            return self::cleanup($data);
        }
        $id = $data['donation_id'] ?? null;
        $mode = $data['mode'] ?? null;
        $fields = $action === 'status' ? ['blog_id', 'donation_id'] : ['blog_id', 'donation_id', 'mode'];
        if (!is_int($id) || $id <= 0 || count($data) !== count($fields) || array_diff(array_keys($data), $fields) !== []
            || ($action !== 'status' && !in_array($mode, RefundIntentStore::MODES, true))) {
            return self::invalid();
        }

        try {
            if ($action === 'status') {
                $attempt = AttemptStore::byDonation($id);

                return $attempt !== null && RefundProjector::nativeEligible($attempt)
                    ? self::response(self::presentation($attempt)) : self::unavailable();
            }

            $connectionLock = $action === 'request' ? ConnectionStore::lock($site) : null;
            if ($connectionLock !== null && !$connectionLock->acquire()) {
                return self::busy();
            }
            try {
                $lock = AttemptStore::lock($id);
                if (!$lock->acquire()) {
                    return self::busy();
                }
                try {
                    $attempt = AttemptStore::byDonation($id);
                    if ($attempt === null || !RefundProjector::nativeEligible($attempt)) {
                        return self::unavailable();
                    }
                    $intent = RefundIntentStore::byAttempt($attempt['id'], $mode);
                    if ($intent !== null && !RefundIntentStore::matches($intent, $attempt)) {
                        return self::unavailable();
                    }
                    if ($action === 'request') {
                        $connectionLock->assertOwned();
                        $intent = RefundProjector::request($attempt, $mode, $lock);
                    } elseif ($intent !== null) {
                        $intent = RefundProjector::poll($intent, $lock);
                    }

                    $current = AttemptStore::byId($attempt['id']);

                    return $intent !== null && $current !== null
                        ? self::response(self::presentation($current)) : self::unavailable();
                } finally {
                    $lock->release();
                }
            } finally {
                if ($connectionLock !== null) {
                    $connectionLock->release();
                }
            }
        } catch (\Throwable $error) {
            return self::error('retry', __('Paymos could not read the refund status. Try again.', 'paymos-stablecoin-donations'), 503);
        }
    }

    private static function presentation(array $attempt): array
    {
        $modes = [];
        $canRequest = RefundProjector::canRequest($attempt);
        foreach (RefundIntentStore::MODES as $mode) {
            $intent = RefundIntentStore::byAttempt($attempt['id'], $mode);
            if ($intent !== null && !RefundIntentStore::matches($intent, $attempt)) {
                throw new \UnexpectedValueException('Paymos refund scope is inconsistent.');
            }
            $state = $intent['state'] ?? 'none';
            $modes[$mode] = [
                'state' => $state,
                'reference' => $intent['withdrawal_id'] ?? null,
                'amount' => $intent['expected_amount'] ?? null,
                'currency' => $intent['currency'] ?? null,
                'network' => $intent['network'] ?? null,
                'review_reason' => $intent['review_reason'] ?? null,
                'can_request' => $canRequest && in_array($state, ['none', 'reserved'], true),
                'can_poll' => in_array($state, ['unknown', 'pending', 'projection_pending', 'review'], true),
            ];
        }

        $paid = EventStore::projection($attempt['id'], 'paid');

        return [
            'donation_id' => $attempt['donation_id'],
            'invoice_id' => $attempt['invoice_id'],
            'environment' => $attempt['environment'],
            'amount' => $attempt['amount'],
            'currency' => $attempt['currency'],
            'receipt_delivery_unknown' => (int) ($paid['receipt_delivery_unknown'] ?? 0) === 1,
            'modes' => $modes,
        ];
    }

    private static function cleanup(array $data)
    {
        if (!current_user_can('manage_give_settings')) {
            return self::error('forbidden', __('You cannot remove retained Paymos records.', 'paymos-stablecoin-donations'), 403);
        }
        $id = $data['record_id'] ?? null;
        $kind = $data['kind'] ?? null;
        if (!is_int($id) || $id <= 0 || !in_array($kind, ['payment', 'refund'], true)
            || count($data) !== 3 || array_diff(array_keys($data), ['blog_id', 'record_id', 'kind']) !== []) {
            return self::invalid();
        }
        $removed = $kind === 'payment' ? Install::eraseRetainedPayment($id) : RefundIntentStore::eraseRetained($id);

        return $removed ? self::response(['removed' => true]) : self::unavailable();
    }

    private static function canEdit(): bool
    {
        return Plugin::supportsNativeRuntime() && UserPermissions::donations()->canEdit();
    }

    private static function invalid(): WP_Error
    {
        return self::error('invalid', __('The refund request is invalid for this site.', 'paymos-stablecoin-donations'), 400);
    }

    private static function unavailable(): WP_Error
    {
        return self::error('unavailable', __('Paymos cannot verify an eligible donation for this request.', 'paymos-stablecoin-donations'), 409);
    }

    private static function busy(): WP_Error
    {
        return self::error('busy', __('Paymos is already checking this donation. Try again.', 'paymos-stablecoin-donations'), 409);
    }

    private static function error(string $code, string $message, int $status): WP_Error
    {
        return new WP_Error('paymos_givewp_refund_' . $code, $message, ['status' => $status]);
    }

    private static function response(array $data): WP_REST_Response
    {
        $response = new WP_REST_Response($data);
        $response->header('Cache-Control', 'private, no-store');

        return $response;
    }


    public static function pluginLinks(array $links): array
    {
        if (self::canEdit()) {
            $url = admin_url('edit.php?post_type=give_forms&page=' . self::PAGE);
            $links[] = '<a href="' . esc_url($url) . '">' . esc_html__('Paymos refunds', 'paymos-stablecoin-donations') . '</a>';
        }

        return $links;
    }

    public static function enqueue(string $screen): void
    {
        if ($screen !== self::$pageHook || !self::canEdit()) {
            return;
        }
        $handle = 'paymos-givewp-refunds';
        wp_enqueue_script($handle, plugin_dir_url(PAYMOS_GIVEWP_PLUGIN_FILE) . 'assets/js/refunds.js', [], PAYMOS_GIVEWP_PLUGIN_VERSION, true);
        $routes = [];
        foreach (self::ACTIONS as $action) {
            $routes[$action] = rest_url(ConnectController::REST_NAMESPACE . '/refunds/' . $action);
        }
        wp_localize_script($handle, 'PaymosGivewpRefunds', [
            'routes' => $routes,
            'nonce' => wp_create_nonce('wp_rest'),
            'blogId' => (int) get_current_blog_id(),
            'unavailable' => __('Paymos could not load this refund.', 'paymos-stablecoin-donations'),
            'loading' => __('Checking Paymos…', 'paymos-stablecoin-donations'),
            'review' => __('Check this operation in your Paymos dashboard before taking further action.', 'paymos-stablecoin-donations'),
            'removed' => __('Retained local record removed.', 'paymos-stablecoin-donations'),
            'confirmCleanup' => __('Have you checked this record in Paymos? Remove only the local reference?', 'paymos-stablecoin-donations'),
            'confirmation' => [
                'full' => __('Request a full refund to the original sender?', 'paymos-stablecoin-donations'),
                'overpayment' => __('Return the overpayment to the original sender?', 'paymos-stablecoin-donations'),
            ],
            'states' => [
                'none' => __('No refund requested', 'paymos-stablecoin-donations'),
                'reserved' => __('Waiting for an eligible refund', 'paymos-stablecoin-donations'),
                'unknown' => __('Paymos has not confirmed whether it received the request.', 'paymos-stablecoin-donations'),
                'pending' => __('In progress in Paymos', 'paymos-stablecoin-donations'),
                'projection_pending' => __('Confirmed in Paymos. Updating GiveWP.', 'paymos-stablecoin-donations'),
                'completed' => __('Confirmed', 'paymos-stablecoin-donations'),
                'failed' => __('Failed', 'paymos-stablecoin-donations'),
                'cancelled' => __('Cancelled', 'paymos-stablecoin-donations'),
                'review' => __('Needs review', 'paymos-stablecoin-donations'),
            ],
        ]);
    }

    public static function render(): void
    {
        if (!self::canEdit()) {
            wp_die(esc_html__('You cannot manage these GiveWP refunds.', 'paymos-stablecoin-donations'), '', ['response' => 403]);
        }
        $ready = Install::refundsReady();
        $manage = current_user_can('manage_give_settings');
        ?>
        <div class="wrap" id="paymos-givewp-refunds">
            <h1><?php esc_html_e('Paymos refunds', 'paymos-stablecoin-donations'); ?></h1>
            <?php if (!$ready) : ?>
                <p><?php esc_html_e('Paymos refund storage is unavailable. Contact your WordPress administrator.', 'paymos-stablecoin-donations'); ?></p>
            <?php else : ?>
                <p><?php esc_html_e('Use the donation ID from GiveWP. Paymos calculates the amount and returns funds to the original sender.', 'paymos-stablecoin-donations'); ?></p>
                <form data-paymos-refund-lookup>
                    <label for="paymos-givewp-donation"><?php esc_html_e('GiveWP donation ID', 'paymos-stablecoin-donations'); ?></label>
                    <input id="paymos-givewp-donation" type="number" min="1" step="1" inputmode="numeric" required data-paymos-donation>
                    <button type="submit" class="button"><?php esc_html_e('Load donation', 'paymos-stablecoin-donations'); ?></button>
                </form>
                <p data-paymos-refund-message role="status" aria-live="polite"></p>
                <section data-paymos-refund-record hidden>
                    <h2><?php esc_html_e('Donation payment', 'paymos-stablecoin-donations'); ?></h2>
                    <p data-paymos-refund-payment></p>
                    <p data-paymos-refund-email hidden><?php esc_html_e('GiveWP saved the payment. Receipt email delivery is unknown.', 'paymos-stablecoin-donations'); ?></p>
                    <label for="paymos-givewp-refund-mode"><?php esc_html_e('Refund type', 'paymos-stablecoin-donations'); ?></label>
                    <select id="paymos-givewp-refund-mode" data-paymos-refund-mode>
                        <option value="full"><?php esc_html_e('Full refund', 'paymos-stablecoin-donations'); ?></option>
                        <option value="overpayment"><?php esc_html_e('Return overpayment', 'paymos-stablecoin-donations'); ?></option>
                    </select>
                    <p data-paymos-refund-state></p>
                    <p>
                        <button type="button" class="button button-primary" data-paymos-refund-action="request" disabled><?php esc_html_e('Request refund', 'paymos-stablecoin-donations'); ?></button>
                        <button type="button" class="button" data-paymos-refund-action="poll" disabled><?php esc_html_e('Check refund status', 'paymos-stablecoin-donations'); ?></button>
                    </p>
                </section>
                <?php if ($manage) : ?>
                    <?php self::renderRetention(); ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function renderRetention(): void
    {
        $payments = Install::unresolvedPayments();
        $refunds = RefundIntentStore::unresolvedCount();
        $retained = ['payment' => Install::retainedPayments(), 'refund' => RefundIntentStore::retained()];
        ?>
        <section>
            <h2><?php esc_html_e('Before uninstalling', 'paymos-stablecoin-donations'); ?></h2>
            <p><?php
                /* translators: 1: number of unresolved payments; 2: number of unresolved refunds. */
                echo esc_html(sprintf(__('Payments to review: %1$d. Refunds to review: %2$d.', 'paymos-stablecoin-donations'), $payments, $refunds));
            ?></p>
            <p><?php esc_html_e('Uninstalling removes connection credentials and stops automatic checks. GiveWP keeps donation history. Unresolved Paymos references are retained until you review and remove them.', 'paymos-stablecoin-donations'); ?></p>
            <?php if ($retained['payment'] !== [] || $retained['refund'] !== []) : ?>
                <h3><?php esc_html_e('References from a previous uninstall', 'paymos-stablecoin-donations'); ?></h3>
                <p><?php esc_html_e('Compare each reference with your Paymos dashboard before removing the local record.', 'paymos-stablecoin-donations'); ?></p>
                <ul>
                    <?php foreach ($retained as $kind => $rows) : ?>
                        <?php foreach ($rows as $row) : ?>
                            <li>
                                <code><?php echo esc_html(implode(' · ', array_filter([
                                    $row['environment'], $row['project_id'], $row['invoice_id'], $row['withdrawal_id'] ?? null, $row['external_order_id'],
                                ]))); ?></code>
                                <button type="button" class="button" data-paymos-cleanup="<?php echo esc_attr($kind); ?>"
                                    data-paymos-record="<?php echo esc_attr((string) $row['id']); ?>"><?php esc_html_e('Remove local reference', 'paymos-stablecoin-donations'); ?></button>
                            </li>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
        <?php
    }
}
