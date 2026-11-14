<?php

declare(strict_types=1);

namespace PaymosGivewp;

use Give\DonationForms\Exceptions\DonationFormForbidden;
use Give\DonationForms\Repositories\DonationFormRepository;
use Give\Framework\PaymentGateways\PaymentGateway;
use Give\Framework\PaymentGateways\PaymentGatewayRegister;

defined('ABSPATH') || exit;

final class Plugin
{
    private static $ownsGateway = false;

    public static function registerHooks(): void
    {
        add_action('givewp_register_payment_gateway', [self::class, 'registerGateway']);
        add_filter('give_enabled_payment_gateways', [self::class, 'filterEnabledGateways'], PHP_INT_MAX);
        add_filter('givewp_donation_form_enabled_gateways', [self::class, 'filterEnabledGateways'], PHP_INT_MAX);
        add_action('admin_init', [Install::class, 'maybeUpgrade']);
        ConnectController::register();
        Checkout::registerHooks();
        add_action('rest_api_init', [WebhookController::class, 'registerRoutes']);
        Settlement::registerNativeGuards();
        Reconciler::registerHooks();
        RefundAdmin::register();
    }

    public static function registerGateway(PaymentGatewayRegister $registry): void
    {
        if (!self::supportsNativeRuntime()) {
            return;
        }

        $id = Gateway::id();
        if (!$registry->hasPaymentGateway($id)) {
            $registry->registerGateway(Gateway::class);
        }

        self::$ownsGateway = ($registry->getPaymentGateways()[$id] ?? null) === Gateway::class;
    }

    public static function supportsNativeRuntime(): bool
    {
        return defined('GIVE_VERSION')
            && version_compare(GIVE_VERSION, '4.19.1.1', '>=')
            && class_exists(PaymentGateway::class)
            && class_exists(DonationFormForbidden::class);
    }

    public static function filterEnabledGateways(array $gateways): array
    {
        // Keep the native registry available to historical donations.
        if (self::$ownsGateway && isset($gateways[Gateway::id()]) && !self::connectionAvailable()) {
            unset($gateways[Gateway::id()]);
        }

        return $gateways;
    }

    public static function checkoutEnabled(int $formId, int $version): bool
    {
        if (!self::connectionAvailable() || get_post_type($formId) !== 'give_forms' || get_post_status($formId) !== 'publish') {
            return false;
        }

        if ($version === 3) {
            $gateways = give(DonationFormRepository::class)->getEnabledPaymentGateways($formId);

            return ($gateways[Gateway::id()] ?? null) instanceof Gateway;
        }

        return $version === 2 && isset(give_get_enabled_payment_gateways($formId, 2)[Gateway::id()]);
    }

    private static function connectionAvailable(): bool
    {
        if (!self::$ownsGateway || !Install::ready()) {
            return false;
        }
        try {
            $binding = ConnectionStore::active((int) get_current_blog_id());

            return $binding !== null && $binding['environment'] === (give_is_test_mode() ? 'sandbox' : 'live');
        } catch (\Throwable $error) {
            return false;
        }
    }
}
