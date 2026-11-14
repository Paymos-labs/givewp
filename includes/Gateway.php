<?php

declare(strict_types=1);

namespace PaymosGivewp;

use Give\DonationForms\Exceptions\DonationFormForbidden;
use Give\Donations\Models\Donation;
use Give\Framework\PaymentGateways\Commands\RedirectOffsite;
use Give\Framework\PaymentGateways\PaymentGateway;

defined('ABSPATH') || exit;

final class Gateway extends PaymentGateway
{
    public static function id(): string
    {
        return 'paymos';
    }

    public function getId(): string
    {
        return self::id();
    }

    public function getName(): string
    {
        return __('Paymos', 'paymos-stablecoin-donations');
    }

    public function getPaymentMethodLabel(): string
    {
        return __('Pay with stablecoins', 'paymos-stablecoin-donations');
    }

    public function supportsSubscriptions(): bool
    {
        return false;
    }

    public function supportsRefund(): bool
    {
        return false;
    }

    public function formSettings(int $formId): array
    {
        return [
            'formId' => $formId,
            'message' => __('Continue to Paymos to pay with stablecoins.', 'paymos-stablecoin-donations'),
            'unavailable' => __('Paymos is unavailable for this donation.', 'paymos-stablecoin-donations'),
        ];
    }

    public function enqueueScript(int $formId): void
    {
        $dependencies = ['wp-element'];
        if (Install::ready()) {
            Checkout::enqueue($formId);
            $dependencies[] = 'paymos-givewp-checkout';
        }
        wp_enqueue_script(
            'paymos-givewp-visual',
            plugin_dir_url(PAYMOS_GIVEWP_PLUGIN_FILE) . 'assets/js/visual.js',
            $dependencies,
            PAYMOS_GIVEWP_PLUGIN_VERSION,
            true
        );
    }

    public function getLegacyFormFieldMarkup(int $formId, array $args): string
    {
        return sprintf(
            '<input type="hidden" name="gatewayData[paymos_submit]" data-paymos-givewp-form="%s" value="">',
            esc_attr((string) $formId)
        );
    }

    public function createPayment(Donation $donation, $gatewayData)
    {
        if (!Install::ready()) {
            throw new DonationFormForbidden(esc_html__('Paymos is unavailable for this donation.', 'paymos-stablecoin-donations'));
        }

        try {
            $url = Checkout::register($donation, is_array($gatewayData) ? $gatewayData : []);
            if ($url !== null) {
                return new RedirectOffsite($url);
            }
        } catch (\Throwable $error) {
            // Native GiveWP logs the complete donation DTO for gateway exceptions.
        }

        try {
            return new RedirectOffsite(Checkout::failureUrl());
        } catch (\Throwable $error) {
            return new RedirectOffsite(add_query_arg('paymos_givewp', 'unavailable', home_url('/')));
        }
    }
}
