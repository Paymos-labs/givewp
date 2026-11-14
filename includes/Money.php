<?php

declare(strict_types=1);

namespace PaymosGivewp;

use Give\Donations\Models\Donation;
use PaymosGivewpVendor\Paymos\Plugin\AmountGuard;

defined('ABSPATH') || exit;

final class Money
{
    /** GiveWP's persisted charged amount includes its native fee recovery. */
    public static function fromDonation(Donation $donation): array
    {
        $money = $donation->amount;
        $amount = $money->formatToDecimal();
        $currency = $money->getCurrency()->getCode();
        $mode = $donation->mode->getValue();
        if (
            !is_string($amount) || preg_match('/\A[0-9]{1,28}(?:\.[0-9]{1,18})?\z/', $amount) !== 1
            || AmountGuard::normalizeDecimal($amount) === '0'
            || !is_string($currency) || preg_match('/\A[A-Z]{3}\z/', $currency) !== 1
            || self::environment($mode) === null
        ) {
            throw new \UnexpectedValueException('Invalid native donation payment details.');
        }

        return ['amount' => $amount, 'currency' => $currency, 'native_mode' => $mode];
    }

    public static function environment(string $mode): ?string
    {
        if ($mode === 'test') {
            return 'sandbox';
        }

        return $mode === 'live' ? 'live' : null;
    }
}
