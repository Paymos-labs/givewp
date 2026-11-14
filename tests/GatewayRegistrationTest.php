<?php

declare(strict_types=1);

use Give\DonationForms\Exceptions\DonationFormForbidden;
use Give\Donations\Models\Donation;
use Give\Framework\PaymentGateways\PaymentGatewayRegister;
use PaymosGivewp\Gateway;

require_once __DIR__ . '/bootstrap.php';

if ($argc !== 4 || !in_array($argv[3], ['other-first', 'givewp-first'], true)) {
    fwrite(STDERR, "Usage: php GatewayRegistrationTest.php <givewp-zip> <unscoped-sdk-root> <other-first|givewp-first>\n");
    exit(2);
}

define('ABSPATH', __DIR__);
givewpTestNativeSource($argv[1]);

$otherSdkRoot = realpath($argv[2]);
givewpTestCheck($otherSdkRoot !== false, 'Expected a readable unscoped SDK fixture.');
$otherSdkLoader = static function (string $class) use ($otherSdkRoot): void {
    if (strncmp($class, 'Paymos\\', 7) === 0) {
        $file = $otherSdkRoot . '/src/' . str_replace('\\', '/', substr($class, 7)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
};

if ($argv[3] === 'other-first') {
    spl_autoload_register($otherSdkLoader);
    givewpTestCheck(class_exists('Paymos\\Version'), 'The other plugin SDK did not load.');
}

require dirname(__DIR__) . '/paymos-givewp.php';

$list = ['paymos' => ['checkout_label' => 'Paymos'], 'other' => ['checkout_label' => 'Other']];
$unavailable = new PaymentGatewayRegister();
do_action('givewp_register_payment_gateway', $unavailable);
givewpTestCheck(!$unavailable->hasPaymentGateway('paymos'), 'An unsupported GiveWP runtime registered Paymos.');
givewpTestCheck(apply_filters('give_enabled_payment_gateways', $list, 27, 2) === $list, 'An unregistered adapter altered the checkout list.');

define('GIVE_VERSION', '4.19.1.1');
$registry = new PaymentGatewayRegister();
do_action('givewp_register_payment_gateway', $registry);
do_action('givewp_register_payment_gateway', $registry);
givewpTestCheck($registry->getPaymentGateways(2) === ['paymos' => Gateway::class], 'The native legacy registry did not expose the installed adapter.');
givewpTestCheck($registry->getPaymentGateways(3) === ['paymos' => Gateway::class], 'The native Visual registry did not expose the installed adapter.');
$gateway = $registry->getPaymentGateway('paymos');
givewpTestCheck(!$gateway->supportsRefund() && !method_exists($gateway, 'refundDonation'), 'Native synchronous refunds were enabled.');
givewpTestCheck(!$gateway->supportsSubscriptions(), 'Subscriptions were enabled.');

$field = $gateway->getLegacyFormFieldMarkup(27, []);
givewpTestCheck(strpos($field, 'name="gatewayData[paymos_submit]"') !== false && strpos($field, 'data-paymos-givewp-form="27"') !== false, 'The legacy form did not receive the native gateway data field.');
$gateway->enqueueScript(27);
$visual = $GLOBALS['givewpTestScripts']['paymos-givewp-visual'];
givewpTestCheck($visual === ['https://example.invalid/wp-content/plugins/givewp-paymos/assets/js/visual.js', ['wp-element'], PAYMOS_GIVEWP_PLUGIN_VERSION, true], 'The Visual adapter did not enqueue the local asset.');
givewpTestCheck($gateway->formSettings(27)['formId'] === 27, 'The Visual adapter lost its native form ID.');

foreach ([2, 3] as $version) {
    givewpTestCheck(
        apply_filters('give_enabled_payment_gateways', $list, 27, $version) === ['other' => $list['other']],
        'A disconnected Paymos remained selectable, or the filter changed an unrelated gateway.'
    );
}

$donation = (new ReflectionClass(Donation::class))->newInstanceWithoutConstructor();
try {
    $gateway->createPayment($donation, ['paymos_submit' => 'untrusted']);
    throw new RuntimeException('A direct request reached an unavailable checkout.');
} catch (DonationFormForbidden $exception) {
    givewpTestCheck($exception->getCode() === 403, 'The unavailable checkout did not use the native 403 path.');
}

givewpTestCheck(class_exists('PaymosGivewpVendor\\Paymos\\Connect\\DeviceConnectClient'), 'The private Device Connect SDK did not load.');
givewpTestCheck(class_exists('PaymosGivewpVendor\\Paymos\\Http\\WordPressTransport'), 'The private WordPress transport did not load.');
if ($argv[3] === 'givewp-first') {
    givewpTestCheck(!class_exists('Paymos\\Version'), 'GiveWP claimed the shared Paymos namespace.');
    spl_autoload_register($otherSdkLoader);
}

givewpTestCheck(class_exists('Paymos\\Version'), 'The other plugin SDK did not load alongside GiveWP.');
$private = new ReflectionClass('PaymosGivewpVendor\\Paymos\\Version');
$shared = new ReflectionClass('Paymos\\Version');
givewpTestCheck($private->getFileName() !== $shared->getFileName(), 'The two SDKs resolved to the same source.');
givewpTestCheck(
    realpath($shared->getFileName()) === realpath($otherSdkRoot . '/src/Version.php'),
    'GiveWP replaced the other plugin SDK.'
);

echo "GiveWP 4.19.1.1 registry, unavailable checkout and SDK isolation passed ($argv[3]).\n";
