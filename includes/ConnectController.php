<?php

declare(strict_types=1);

namespace PaymosGivewp;

use PaymosGivewpVendor\Paymos\Connect\DeviceConnectClient;
use PaymosGivewpVendor\Paymos\Http\WordPressTransport;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

final class ConnectController
{
    private const CMS = 'givewp';
    public const REST_NAMESPACE = 'paymos-givewp/v1';
    private const CONNECT_ORIGIN = 'https://app.paymos.io';
    private const SETTINGS_PATH = 'edit.php?post_type=give_forms&page=give-settings&tab=gateways&section=paymos';

    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'registerRoutes']);
        add_filter('give_get_sections_gateways', [self::class, 'settingsSection']);
        add_filter('give_get_settings_gateways', [self::class, 'settingsFields']);
        add_action('give_admin_field_paymos_connect', [self::class, 'render']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function registerRoutes(): void
    {
        foreach (['status', 'start', 'poll', 'disable', 'reset'] as $action) {
            register_rest_route(self::REST_NAMESPACE, '/connect/' . $action, [
                'methods' => $action === 'status' ? 'GET' : 'POST',
                'permission_callback' => [self::class, 'authorize'],
                'callback' => [self::class, $action],
            ]);
        }
    }

    /** @return true|WP_Error */
    public static function authorize(WP_REST_Request $request)
    {
        $nonce = $request->get_header('x-wp-nonce');
        if (
            !Plugin::supportsNativeRuntime()
            || get_current_user_id() <= 0
            || !current_user_can('manage_give_settings')
            || wp_get_session_token() === ''
            || !is_string($nonce)
            || $nonce === ''
            || !wp_verify_nonce($nonce, 'wp_rest')
        ) {
            return self::failure('paymos_connect_forbidden', __('You cannot manage the Paymos connection.', 'paymos-stablecoin-donations'), 403);
        }

        return true;
    }

    public static function settingsSection(array $sections): array
    {
        if (current_user_can('manage_give_settings')) {
            $sections['paymos'] = __('Paymos', 'paymos-stablecoin-donations');
        }

        return $sections;
    }

    public static function settingsFields(array $settings): array
    {
        if (!self::isSettingsScreen()) {
            return $settings;
        }

        $settings[] = ['id' => 'paymos_givewp_connect_title', 'type' => 'title'];
        $settings[] = ['id' => 'paymos_givewp_connect', 'type' => 'paymos_connect'];
        $settings[] = ['id' => 'paymos_givewp_connect_title', 'type' => 'sectionend'];

        return $settings;
    }

    public static function render(): void
    {
        if (!self::isSettingsScreen()) {
            return;
        }
        ?>
        <tr>
            <th scope="row"><?php esc_html_e('Paymos connection', 'paymos-stablecoin-donations'); ?></th>
            <td>
                <div id="paymos-givewp-connect">
                    <p><?php esc_html_e('Choose a mode and approve the connection in Paymos. Compare the code on both screens before you approve.', 'paymos-stablecoin-donations'); ?></p>
                    <label for="paymos-givewp-mode"><?php esc_html_e('Mode', 'paymos-stablecoin-donations'); ?></label>
                    <select id="paymos-givewp-mode" data-paymos-mode>
                        <option value="sandbox"><?php esc_html_e('Sandbox', 'paymos-stablecoin-donations'); ?></option>
                        <option value="live"><?php esc_html_e('Live', 'paymos-stablecoin-donations'); ?></option>
                    </select>
                    <button type="button" class="button button-primary" data-paymos-start disabled><?php esc_html_e('Connect Paymos', 'paymos-stablecoin-donations'); ?></button>
                    <button type="button" class="button hidden" data-paymos-disable hidden><?php esc_html_e('Disconnect', 'paymos-stablecoin-donations'); ?></button>
                    <button type="button" class="button hidden" data-paymos-reset hidden><?php esc_html_e('Remove saved credentials', 'paymos-stablecoin-donations'); ?></button>
                    <p data-paymos-connection></p>
                    <p data-paymos-message role="status" aria-live="polite"></p>
                    <p class="hidden" data-paymos-approval hidden>
                        <span><?php esc_html_e('Approval code', 'paymos-stablecoin-donations'); ?></span>
                        <code data-paymos-code></code>
                        <a class="button" data-paymos-open target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open Paymos', 'paymos-stablecoin-donations'); ?></a>
                    </p>
                </div>
            </td>
        </tr>
        <?php
    }

    public static function enqueue(): void
    {
        if (!self::isSettingsScreen()) {
            return;
        }

        $handle = 'paymos-givewp-connect';
        wp_enqueue_script(
            $handle,
            plugin_dir_url(PAYMOS_GIVEWP_PLUGIN_FILE) . 'assets/js/connect.js',
            [],
            PAYMOS_GIVEWP_PLUGIN_VERSION,
            true
        );

        $routes = [];
        foreach (['status', 'start', 'poll', 'disable', 'reset'] as $action) {
            $routes[$action] = rest_url(self::REST_NAMESPACE . '/connect/' . $action);
        }
        wp_localize_script($handle, 'PaymosGivewpConnect', [
            'routes' => $routes,
            'nonce' => wp_create_nonce('wp_rest'),
            'approvalOrigin' => self::CONNECT_ORIGIN,
            'messages' => [
                'disconnected' => __('Paymos is disconnected.', 'paymos-stablecoin-donations'),
                'sandbox' => __('Connected in Sandbox', 'paymos-stablecoin-donations'),
                'live' => __('Connected in Live', 'paymos-stablecoin-donations'),
                'waiting' => __('Waiting for approval in Paymos.', 'paymos-stablecoin-donations'),
                'expired' => __('The approval expired. Start again.', 'paymos-stablecoin-donations'),
                'failed' => __('Paymos could not complete this request.', 'paymos-stablecoin-donations'),
                'confirmDisable' => __('Disconnect Paymos for new donations? Existing donations keep their original connection.', 'paymos-stablecoin-donations'),
                'confirmReset' => __('Remove saved Paymos credentials? This does not revoke keys in Paymos.', 'paymos-stablecoin-donations'),
                'confirmLive' => __('Connect this store in Live mode for real payments?', 'paymos-stablecoin-donations'),
            ],
        ]);
    }

    public static function status()
    {
        try {
            $scope = DeviceState::scope();
            $now = time();
            $pending = DeviceState::owned($scope, $now);

            return self::reply([
                'connection' => self::connection($scope['site']),
                'pending' => $pending === null ? null : DeviceState::presentation($pending, $now),
            ]);
        } catch (\Throwable $error) {
            return self::unavailable();
        }
    }

    public static function start(WP_REST_Request $request)
    {
        $environment = $request->get_param('environment');
        if (!in_array($environment, ['sandbox', 'live'], true)) {
            return self::failure('paymos_connect_mode', __('Choose Sandbox or Live.', 'paymos-stablecoin-donations'), 400);
        }

        return self::locked(static function ($lock) use ($environment) {
            $scope = DeviceState::scope();
            $now = time();
            $previous = DeviceState::load($scope['site']);
            if ($previous !== null && $previous['expires_at'] > $now) {
                if ($previous['scope'] !== $scope) {
                    return self::failure('paymos_connect_busy', __('Another admin session has a pending connection.', 'paymos-stablecoin-donations'), 409);
                }
                if ($previous['environment'] === $environment) {
                    return self::reply(DeviceState::presentation($previous, $now));
                }
            }

            $returnUrl = admin_url(self::SETTINGS_PATH);
            if (self::origin($returnUrl) !== self::origin($scope['source'])) {
                $returnUrl = null;
            }
            $reply = self::client()->start(self::CMS, $scope['source'], $returnUrl);
            if (!self::trustedApproval($reply['verification_url'], $reply['user_code'], $scope['source'], $returnUrl)) {
                return self::failure('paymos_connect_approval', __('Paymos returned an invalid approval link.', 'paymos-stablecoin-donations'), 502);
            }
            $now = time();
            $state = DeviceState::begin($scope, $environment, $reply, $now);
            DeviceState::save($state, $lock);

            return self::reply(DeviceState::presentation($state, $now));
        });
    }

    public static function poll(WP_REST_Request $request)
    {
        $requestId = $request->get_param('request_id');
        if (!is_string($requestId) || preg_match('/\A[a-f0-9]{32}\z/', $requestId) !== 1) {
            return self::failure('paymos_connect_device', __('Start a new Paymos connection.', 'paymos-stablecoin-donations'), 409);
        }

        return self::locked(static function ($lock) use ($requestId) {
            $scope = DeviceState::scope();
            $now = time();
            $state = DeviceState::owned($scope, $now);
            if ($state === null || !hash_equals($state['request_id'], $requestId)) {
                return self::failure('paymos_connect_device', __('Start a new Paymos connection.', 'paymos-stablecoin-donations'), 409);
            }
            if ($state['next_poll_at'] > $now) {
                return self::waiting($state, $now);
            }

            // Reserve the next slot before the request so early retries and parallel tabs never flood Paymos.
            $state['next_poll_at'] = $now + $state['interval'];
            DeviceState::save($state, $lock);
            $result = self::client()->poll($state['device_code']);
            $now = time();
            if ($state['expires_at'] <= $now) {
                DeviceState::clear($scope['site'], $lock);

                return self::failure('paymos_connect_expired', __('The Paymos approval expired.', 'paymos-stablecoin-donations'), 409);
            }
            switch ($result['status']) {
                case 'authorization_pending':
                    return self::waiting($state, $now);

                case 'slow_down':
                    $state['interval'] = min(60, $state['interval'] + 5);
                    $state['next_poll_at'] = $now + $state['interval'];
                    DeviceState::save($state, $lock);

                    return self::waiting($state, $now);

                case 'connected':
                    return self::complete($state, $result, $lock);

                default:
                    DeviceState::clear($scope['site'], $lock);

                    return self::failure('paymos_connect_denied', __('The Paymos approval was denied or expired.', 'paymos-stablecoin-donations'), 409);
            }
        });
    }

    public static function disable()
    {
        return self::locked(static function ($lock) {
            $blog = (int) get_current_blog_id();
            DeviceState::clear($blog, $lock);
            ConnectionStore::disable($blog, $lock);

            return self::reply(['connection' => self::connection($blog)]);
        });
    }

    public static function reset()
    {
        return self::locked(static function ($lock) {
            $blog = (int) get_current_blog_id();
            if (!ConnectionStore::reset($blog, $lock)) {
                return self::failure(
                    'paymos_connect_reset_blocked',
                    __('Disconnect first. Saved credentials can only be removed after unresolved donations and refunds are checked.', 'paymos-stablecoin-donations'),
                    409
                );
            }
            DeviceState::clear($blog, $lock);

            return self::reply(['connection' => self::connection($blog)]);
        });
    }

    private static function complete(array $state, array $result, $lock)
    {
        $scope = $state['scope'];
        if ($result['plugin'] !== self::CMS || $result['source_url'] !== $scope['source']) {
            DeviceState::clear($scope['site'], $lock);

            return self::failure('paymos_connect_binding', __('The approval does not match this GiveWP site.', 'paymos-stablecoin-donations'), 409);
        }

        $binding = ConnectionStore::connect($scope['site'], $scope['source'], $state['environment'], $result['credentials'], $lock);
        DeviceState::clear($scope['site'], $lock);
        if ($binding === null) {
            return self::failure('paymos_connect_project', __('This site already uses a different Paymos project.', 'paymos-stablecoin-donations'), 409);
        }

        return self::reply(['status' => 'connected', 'connection' => self::connection($scope['site'])]);
    }

    private static function connection(int $blog): array
    {
        $binding = ConnectionStore::active($blog);

        return [
            'environment' => $binding['environment'] ?? null,
            'project_hint' => $binding === null ? null : substr($binding['project_id'], -4),
            'retained' => $binding !== null || ConnectionStore::hasRetained($blog),
        ];
    }

    private static function waiting(array $state, int $now): WP_REST_Response
    {
        return self::reply([
            'status' => 'waiting',
            'retry_after' => max(1, $state['next_poll_at'] - $now),
        ]);
    }

    private static function locked(callable $operation)
    {
        try {
            $lock = ConnectionStore::lock((int) get_current_blog_id());
            if (!$lock->acquire()) {
                return self::failure('paymos_connect_busy', __('A connection change is in progress. Try again shortly.', 'paymos-stablecoin-donations'), 409);
            }
            try {
                return $operation($lock);
            } finally {
                $lock->release();
            }
        } catch (\Throwable $error) {
            // Never expose an OAuth response, upstream error description or credential in an admin reply.
            return self::unavailable();
        }
    }

    private static function unavailable(): WP_Error
    {
        return self::failure('paymos_connect_unavailable', __('Paymos could not complete this request. Check the HTTPS site address and try again.', 'paymos-stablecoin-donations'), 502);
    }

    private static function failure(string $code, string $message, int $status): WP_Error
    {
        return new WP_Error($code, $message, ['status' => $status]);
    }

    private static function reply(array $data): WP_REST_Response
    {
        $response = new WP_REST_Response($data);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    private static function client(): DeviceConnectClient
    {
        return new DeviceConnectClient(self::CONNECT_ORIGIN, new WordPressTransport(), 8);
    }

    private static function isSettingsScreen(): bool
    {
        return current_user_can('manage_give_settings')
            && function_exists('give_get_current_setting_section')
            && give_get_current_setting_page() === 'give-settings'
            && give_get_current_setting_tab() === 'gateways'
            && give_get_current_setting_section() === 'paymos';
    }

    private static function origin(string $url): ?string
    {
        $parts = wp_parse_url($url);
        if (
            !is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host'])
            || array_intersect_key($parts, array_flip(['user', 'pass', 'fragment']))
        ) {
            return null;
        }
        $port = $parts['port'] ?? 443;

        return 'https://' . strtolower($parts['host']) . ($port === 443 ? '' : ':' . $port);
    }

    private static function trustedApproval(string $url, string $code, string $source, ?string $returnUrl): bool
    {
        if (
            strlen($url) > 4096
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || self::origin($url) !== self::CONNECT_ORIGIN
            || wp_parse_url($url, PHP_URL_PATH) !== '/connect/cms/verify'
        ) {
            return false;
        }

        $expected = ['user_code' => $code, 'cms' => self::CMS, 'source_url' => $source];
        if ($returnUrl !== null) {
            $expected['return_url'] = $returnUrl;
        }
        $query = [];
        foreach (explode('&', (string) wp_parse_url($url, PHP_URL_QUERY)) as $parameter) {
            $pair = explode('=', $parameter, 2);
            $key = rawurldecode($pair[0]);
            if (count($pair) !== 2 || !isset($expected[$key]) || isset($query[$key])) {
                return false;
            }
            $query[$key] = rawurldecode($pair[1]);
        }

        return count($query) === count($expected) && array_diff_assoc($query, $expected) === [];
    }
}
