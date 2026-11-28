<?php

declare(strict_types=1);

use Give\DonationForms\Repositories\DonationFormRepository;
use Give\Framework\PaymentGateways\PaymentGatewayRegister;
use Give\PaymentGateways\Gateways\TestOffsiteGateway\TestOffsiteGateway;
use PaymosGivewp\ConnectionStore;
use PaymosGivewp\ConnectController;
use PaymosGivewp\DeviceState;

require_once __DIR__ . '/bootstrap.php';

final class WP_REST_Request
{
    private $body;
    private $nonce;

    public function __construct(array $body, string $nonce)
    {
        $this->body = $body;
        $this->nonce = $nonce;
    }

    public function get_header(string $header): string
    {
        return $header === 'x-wp-nonce' ? $this->nonce : '';
    }

    public function get_param(string $name)
    {
        return $this->body[$name] ?? null;
    }
}

final class WP_REST_Response
{
    public $data;
    public $headers = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function header(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }
}

final class WP_Error
{
    public $code;
    public $message;
    public $data;

    public function __construct(string $code, string $message, array $data)
    {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }
}

final class GivewpConnectDbFixture
{
    public $prefix = 'wp_7_';
    public $busy = false;
    private $owner = false;

    public function prepare(string $sql, ...$values): array
    {
        return [$sql, $values];
    }

    public function get_var(array $query): string
    {
        if (strpos($query[0], 'GET_LOCK(') !== false) {
            if ($this->busy || $this->owner) {
                return '0';
            }
            $this->owner = true;

            return '71';
        }
        if (strpos($query[0], 'RELEASE_LOCK(') !== false) {
            $this->owner = false;

            return '1';
        }

        return $this->owner ? '1' : '0';
    }
}

function get_current_blog_id(): int
{
    return $GLOBALS['givewpConnectActor']['site'];
}

function get_current_user_id(): int
{
    return $GLOBALS['givewpConnectActor']['user'];
}

function current_user_can(string $capability): bool
{
    return $capability === 'manage_give_settings' && $GLOBALS['givewpConnectActor']['allowed'];
}

function wp_get_session_token(): string
{
    return $GLOBALS['givewpConnectActor']['session'];
}

function wp_salt(string $kind): string
{
    return 'fictional-wp-salt-for-connect-test-' . $kind;
}

function wp_create_nonce(string $action): string
{
    return substr(hash_hmac('sha256', $action . '|' . get_current_user_id() . '|' . wp_get_session_token(), wp_salt('nonce')), 0, 10);
}

function wp_verify_nonce(string $nonce, string $action)
{
    return hash_equals(wp_create_nonce($action), $nonce) ? 1 : false;
}

function register_rest_route(string $namespace, string $path, array $definition): void
{
    $GLOBALS['givewpConnectRoutes']['/' . $namespace . $path] = $definition;
}

function give_get_option(string $name, $default = false)
{
    return $GLOBALS['givewpConnectSettings'][$name] ?? $default;
}

function give_get_default_gateway(int $formId, int $version): string
{
    return 'test-offsite-gateway';
}

function get_option(string $name, $default = false)
{
    return $GLOBALS['givewpConnectOptions'][get_current_blog_id()][$name] ?? $default;
}

function update_option(string $name, $value, bool $autoload): bool
{
    givewpTestCheck(!$autoload, 'Credential storage was autoloaded.');
    $GLOBALS['givewpConnectOptions'][get_current_blog_id()][$name] = $value;

    return true;
}

function delete_option(string $name): void
{
    unset($GLOBALS['givewpConnectOptions'][get_current_blog_id()][$name]);
}

function admin_url(string $path): string
{
    return 'https://give-store.example/donate/wp-admin/' . $path;
}

function wp_parse_url(string $url, int $component = -1)
{
    return parse_url($url, $component);
}

function wp_safe_remote_request(string $url, array $options): array
{
    // WordPress takes the timeout in seconds as int or float; the bound is 8 seconds, not a PHP type.
    givewpTestCheck(
        $options['method'] === 'POST' && is_numeric($options['timeout']) && (float) $options['timeout'] === 8.0
        && $options['redirection'] === 0
        && $options['sslverify'] && $options['reject_unsafe_urls'],
        'The scoped SDK HTTP request was not bounded and verified.'
    );
    $GLOBALS['givewpConnectCalls'][] = $url;
    if ($url === 'https://app.paymos.io/connect/cms/device') {
        parse_str($options['body'], $fields);
        givewpTestCheck($fields === ['client_id' => 'paymos-cms', 'scope' => 'cms:bootstrap'], 'Unexpected device bootstrap data.');
    } elseif ($url === 'https://app.paymos.io/connect/token') {
        parse_str($options['body'], $fields);
        givewpTestCheck(($fields['device_code'] ?? '') === 'fixture.device_code-without-browser-exposure', 'The SDK did not reuse the stored device credential.');
    } else {
        throw new RuntimeException('Connect tried an untrusted URL.');
    }
    if (!$GLOBALS['givewpConnectReplies']) {
        throw new RuntimeException('Unexpected Connect request.');
    }

    return array_shift($GLOBALS['givewpConnectReplies']);
}

function is_wp_error($value): bool
{
    return $value instanceof WP_Error;
}

function wp_remote_retrieve_response_code(array $response): int
{
    return $response[0];
}

function wp_remote_retrieve_body(array $response): string
{
    return json_encode($response[1], JSON_THROW_ON_ERROR);
}

function wp_remote_retrieve_headers(array $response): array
{
    return [];
}

final class GivewpConnect
{
    private const SITE = 7;
    private const SOURCE = 'https://give-store.example/donate';
    private const PROJECT = 'prj_fixture_connect_a';
    private const DEVICE = 'fixture.device_code-without-browser-exposure';

    public static function testBoundSandboxGeneration(): array
    {
        $_SERVER['HTTP_HOST'] = 'untrusted-host.example';
        $device = self::start('sandbox');
        $parameters = [];
        parse_str((string) parse_url($device['verification_url'], PHP_URL_QUERY), $parameters);
        givewpTestCheck(
            $parameters['cms'] === 'givewp'
            && $parameters['source_url'] === self::SOURCE
            && strpos($parameters['return_url'], '/wp-admin/edit.php?post_type=give_forms') !== false,
            'The approval link was not bound to the configured WordPress site and settings.'
        );
        $calls = count($GLOBALS['givewpConnectCalls']);
        $repeat = self::ok(self::call('start', ['environment' => 'sandbox']));
        givewpTestCheck($repeat['request_id'] === $device['request_id'] && count($GLOBALS['givewpConnectCalls']) === $calls, 'A repeated start created another remote flow.');
        $waiting = self::ok(self::call('poll', ['request_id' => $device['request_id']]));
        givewpTestCheck($waiting['status'] === 'waiting' && count($GLOBALS['givewpConnectCalls']) === $calls, 'An early poll reached Paymos.');
        self::due();
        self::queue(400, ['error' => 'slow_down']);
        $slow = self::ok(self::call('poll', ['request_id' => $device['request_id']]));
        givewpTestCheck($slow['retry_after'] >= 9, 'The server did not lengthen the poll interval.');

        self::due();
        self::queueConnected(self::PROJECT, self::SOURCE, 'givewp', 'original');
        $finished = self::ok(self::call('poll', ['request_id' => $device['request_id']]));
        givewpTestCheck($finished['status'] === 'connected' && $finished['connection']['environment'] === 'sandbox', 'Sandbox was not the active mode.');
        givewpTestCheck(DeviceState::load(self::SITE) === null, 'A completed device state was kept.');
        $binding = ConnectionStore::active(self::SITE);
        givewpTestCheck($binding['source'] === self::SOURCE && $binding['project_id'] === self::PROJECT, 'The stored binding differs from the approved sandbox.');
        givewpTestCheck(
            ConnectionStore::forAttempt($binding)['api_secret'] === 'sk_test_original',
            'The scoped original Sandbox credential did not resolve.'
        );
        givewpTestCheck(ConnectionStore::forAttempt(array_replace($binding, ['environment' => 'live'])) === null, 'An attempt obtained credentials for another mode.');
        $stored = get_option(ConnectionStore::OPTION);
        givewpTestCheck(is_string($stored) && strpos($stored, 'sk_test_original') === false && strpos($stored, 'sk_live_original') === false, 'Credentials are visible in the database value.');

        return $binding;
    }

    public static function testRejectUnauthorizedAndRetainGeneration(array $old): void
    {
        $calls = count($GLOBALS['givewpConnectCalls']);
        $GLOBALS['givewpConnectActor']['allowed'] = false;
        self::error(self::call('start', ['environment' => 'sandbox']), 403);
        self::error(self::call('disable'), 403);
        $GLOBALS['givewpConnectActor']['allowed'] = true;
        self::error(self::call('start', ['environment' => 'sandbox'], 'invalid-nonce'), 403);
        self::error(self::call('start', ['environment' => 'sandbox'], ''), 403);
        $GLOBALS['wpdb']->busy = true;
        self::error(self::call('start', ['environment' => 'sandbox']), 409);
        $GLOBALS['wpdb']->busy = false;
        givewpTestCheck(count($GLOBALS['givewpConnectCalls']) === $calls, 'An unauthorized or busy request contacted Paymos.');

        $device = self::start('sandbox');
        $calls = count($GLOBALS['givewpConnectCalls']);
        $session = wp_get_session_token();
        $GLOBALS['givewpConnectActor']['session'] = 'another-authorized-wordpress-browser';
        $status = self::ok(self::call('status'));
        givewpTestCheck($status['pending'] === null, 'A different browser saw the pending approval.');
        self::error(self::call('poll', ['request_id' => $device['request_id']]), 409);
        $GLOBALS['givewpConnectActor']['session'] = $session;
        $GLOBALS['givewpConnectOptions'][self::SITE]['home'] = 'https://give-store.example/different';
        self::error(self::call('poll', ['request_id' => $device['request_id']]), 409);
        $GLOBALS['givewpConnectOptions'][self::SITE]['home'] = self::SOURCE . '/';
        self::expire();
        self::error(self::call('poll', ['request_id' => $device['request_id']]), 409);
        givewpTestCheck(count($GLOBALS['givewpConnectCalls']) === $calls, 'Foreign or expired device state reached Paymos.');

        foreach ([
            [self::PROJECT, self::SOURCE, 'woocommerce'],
            [self::PROJECT, self::SOURCE . '/different', 'givewp'],
            ['prj_different_project', self::SOURCE, 'givewp'],
        ] as [$project, $source, $plugin]) {
            $device = self::start('sandbox');
            self::due();
            self::queueConnected($project, $source, $plugin, 'rejected');
            self::error(self::call('poll', ['request_id' => $device['request_id']]), 409);
            givewpTestCheck(ConnectionStore::active(self::SITE) === $old, 'A foreign approval replaced the active binding.');
        }

        self::queue(200, self::device('https://attacker.example/connect/cms/verify?user_code=ABCD-EFGH'));
        self::error(self::call('start', ['environment' => 'sandbox']), 502);
        self::queue(200, self::device('https://app.paymos.io/connect/cms/verify?user_code=ABCD-EFGH&cms=woocommerce'));
        self::error(self::call('start', ['environment' => 'sandbox']), 502);

        $device = self::start('live');
        self::due();
        self::queue(500, ['error' => 'server_error', 'error_description' => 'sk_live_fixture_must_stay_private']);
        $failed = self::error(self::call('poll', ['request_id' => $device['request_id']]), 502);
        givewpTestCheck(strpos($failed->message, 'sk_live_') === false, 'An upstream error disclosed secret data.');
        self::due();
        self::queueConnected(self::PROJECT, self::SOURCE, 'givewp', 'replacement');
        self::ok(self::call('poll', ['request_id' => $device['request_id']]));
        $new = ConnectionStore::active(self::SITE);
        givewpTestCheck($new['environment'] === 'live' && $new['generation'] !== $old['generation'], 'Explicit Live reconnect did not create a new generation.');
        givewpTestCheck(ConnectionStore::forAttempt($old)['api_secret'] === 'sk_test_original', 'Live reconnect overwrote the old Sandbox key.');
        givewpTestCheck(ConnectionStore::forAttempt($new)['api_secret'] === 'sk_live_replacement', 'Live credential did not resolve on its own binding.');

        self::error(self::call('reset'), 409);
        self::ok(self::call('disable'));
        givewpTestCheck(ConnectionStore::active(self::SITE) === null && ConnectionStore::hasRetained(self::SITE), 'Disconnect deleted retained bindings.');
        givewpTestCheck(ConnectionStore::forAttempt($old)['api_secret'] === 'sk_test_original', 'Disconnect prevented old-generation reconciliation.');
        if (!class_exists('PaymosGivewp\\AttemptStore') && !class_exists('PaymosGivewp\\RefundIntentStore')) {
            self::error(self::call('reset'), 409);
        }

        $GLOBALS['givewpConnectOptions'][12] = [
            'home' => self::SOURCE,
            ConnectionStore::OPTION => get_option(ConnectionStore::OPTION),
        ];
        $GLOBALS['givewpConnectActor']['site'] = 12;
        givewpTestCheck(ConnectionStore::forAttempt($old) === null, 'A second blog could resolve the original binding.');
        self::error(self::call('status'), 502);
        $GLOBALS['givewpConnectActor']['site'] = self::SITE;
        givewpTestCheck(ConnectionStore::forAttempt($old)['api_secret'] === 'sk_test_original', 'Foreign-site checks modified the original blog.');
    }

    private static function call(string $action, array $body = [], ?string $nonce = null)
    {
        $route = $GLOBALS['givewpConnectRoutes']['/' . ConnectController::REST_NAMESPACE . '/connect/' . $action];
        givewpTestCheck($route['methods'] === ($action === 'status' ? 'GET' : 'POST'), 'Wrong REST method for a Connect route.');
        $request = new WP_REST_Request($body, $nonce ?? wp_create_nonce('wp_rest'));
        $permission = $route['permission_callback']($request);

        return $permission === true ? $route['callback']($request) : $permission;
    }

    private static function ok($result): array
    {
        givewpTestCheck($result instanceof WP_REST_Response, 'Expected a successful Connect response.');
        givewpTestCheck(($result->headers['Cache-Control'] ?? null) === 'no-store', 'An admin response could be cached.');
        $json = json_encode($result->data, JSON_THROW_ON_ERROR);
        givewpTestCheck(preg_match('/pk_(?:live|test)_|sk_(?:live|test)_|whsec_|fixture\.device_code/', $json) !== 1, 'A credential reached an admin response.');

        return $result->data;
    }

    private static function error($result, int $status): WP_Error
    {
        givewpTestCheck($result instanceof WP_Error && $result->data['status'] === $status, 'Connect did not fail closed with the expected status.');

        return $result;
    }

    private static function start(string $environment): array
    {
        self::queue(200, self::device('https://app.paymos.io/connect/cms/verify?user_code=ABCD-EFGH'));

        return self::ok(self::call('start', ['environment' => $environment]));
    }

    private static function device(string $url): array
    {
        return [
            'device_code' => self::DEVICE,
            'user_code' => 'ABCD-EFGH',
            'verification_uri_complete' => $url,
            'expires_in' => 600,
            'interval' => 5,
        ];
    }

    private static function queueConnected(string $project, string $source, string $plugin, string $suffix): void
    {
        $modes = [];
        foreach (['sandbox' => 'test', 'live' => 'live'] as $environment => $prefix) {
            $modes[$environment] = [
                'base_url' => 'https://api.paymos.io',
                'api_key' => 'pk_' . $prefix . '_' . $suffix,
                'api_secret' => 'sk_' . $prefix . '_' . $suffix,
                'project_id' => $project,
                'webhook_secret' => 'whsec_' . $prefix . '_' . $suffix,
            ];
        }
        self::queue(200, [
            'paymos_config' => ['plugin' => $plugin, 'source_url' => $source, 'environments' => $modes],
        ]);
    }

    private static function queue(int $status, array $payload): void
    {
        $GLOBALS['givewpConnectReplies'][] = [$status, $payload];
    }

    private static function due(): void
    {
        self::adjustState(['next_poll_at' => time() - 1]);
    }

    private static function expire(): void
    {
        self::adjustState(['expires_at' => time() - 1]);
    }

    private static function adjustState(array $fields): void
    {
        $lock = ConnectionStore::lock(self::SITE);
        givewpTestCheck($lock->acquire(), 'Could not control fixture device timing.');
        try {
            DeviceState::save(array_replace(DeviceState::load(self::SITE), $fields), $lock);
        } finally {
            $lock->release();
        }
    }
}

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php ConnectTest.php <givewp-zip>\n");
    exit(2);
}
define('ABSPATH', __DIR__);
define('GIVE_VERSION', '4.19.1.1');
givewpTestNativeSource($argv[1]);
$GLOBALS['givewpConnectActor'] = ['site' => 7, 'user' => 31, 'session' => 'original-wordpress-browser', 'allowed' => true];
$GLOBALS['givewpConnectOptions'] = [7 => ['home' => 'https://give-store.example/donate/']];
$GLOBALS['givewpConnectCalls'] = [];
$GLOBALS['givewpConnectReplies'] = [];
$GLOBALS['wpdb'] = new GivewpConnectDbFixture();
require dirname(__DIR__) . '/paymos-givewp.php';
do_action('rest_api_init');
givewpTestCheck(count($GLOBALS['givewpConnectRoutes']) === 5, 'The native REST router did not receive the five admin routes.');

$registry = new PaymentGatewayRegister();
$registry->registerGateway(TestOffsiteGateway::class);
do_action('givewp_register_payment_gateway', $registry);
$GLOBALS['givewpConnectSettings'] = ['gateways_v3' => ['paymos' => true, 'test-offsite-gateway' => true]];
$visualGateways = (new DonationFormRepository($registry))->getEnabledPaymentGateways(27);
givewpTestCheck(
    $visualGateways === ['test-offsite-gateway' => $registry->getPaymentGateway('test-offsite-gateway')],
    'Visual Builder exposed disconnected Paymos or removed an unrelated gateway.'
);

$old = GivewpConnect::testBoundSandboxGeneration();
GivewpConnect::testRejectUnauthorizedAndRetainGeneration($old);
givewpTestCheck($GLOBALS['givewpConnectReplies'] === [], 'Connect did not consume all expected SDK responses.');
echo "GiveWP Connect passed scoped REST, HTTPS source, browser, expiry, backoff, mode, generation and secret redaction checks.\n";
