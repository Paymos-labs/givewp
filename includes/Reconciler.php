<?php

declare(strict_types=1);

namespace PaymosGivewp;

defined('ABSPATH') || exit;

final class Reconciler
{
    public const HOOK = 'paymos_givewp_reconcile';
    private const INTERVAL = 'paymos_givewp_5min';

    public static function registerHooks(): void
    {
        add_filter('cron_schedules', [self::class, 'interval']);
        add_action(self::HOOK, [self::class, 'run'], 10, 0);
    }

    public static function interval(array $schedules): array
    {
        $schedules[self::INTERVAL] = ['interval' => 300, 'display' => __('Paymos every five minutes', 'paymos-stablecoin-donations')];

        return $schedules;
    }

    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 60, self::INTERVAL, self::HOOK);
        }
    }

    /** Payment and refund recovery share the donation mutex and bounded worker. */
    public static function run(int $limit = 5): array
    {
        $summary = ['checked' => 0, 'busy' => 0, 'errors' => 0];
        if (!Install::settlementReady() || !Plugin::supportsNativeRuntime()) {
            return $summary;
        }

        $limit = max(1, min($limit, 10));
        $payments = AttemptStore::due($limit);
        $refunds = Install::refundsReady() ? RefundIntentStore::due($limit) : [];
        $work = [];
        for ($index = 0; $index < $limit; $index++) {
            if (isset($refunds[$index])) {
                $work[] = ['refund', $refunds[$index]];
            }
            if (isset($payments[$index])) {
                $work[] = ['payment', $payments[$index]];
            }
        }

        $deadline = microtime(true) + 18;
        foreach (array_slice($work, 0, $limit) as [$kind, $row]) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $lock = AttemptStore::lock($row['donation_id']);
            if (!$lock->acquire()) {
                $summary['busy']++;
                continue;
            }
            try {
                $current = $kind === 'refund' ? RefundIntentStore::byId($row['id']) : AttemptStore::byId($row['id']);
                if ($current === null || $current['reconcile_after'] === null || (int) $current['reconcile_after'] > time()) {
                    continue;
                }

                $summary['checked']++;
                try {
                    if ($kind === 'refund') {
                        RefundProjector::poll($current, $lock);
                    } else {
                        self::reconcilePayment($current, $lock);
                    }
                } catch (\Throwable $error) {
                    $summary['errors']++;
                }
            } finally {
                $lock->release();
            }
        }
        EventStore::prune();

        return $summary;
    }

    private static function reconcilePayment(array $attempt, \PaymosGivewpVendor\Paymos\Plugin\WordPressOrderLock $lock): void
    {
        try {
            Settlement::verifyAndApply($attempt, $lock);
        } finally {
            $current = AttemptStore::byId($attempt['id']);
            if ($current !== null && $current['reconcile_after'] !== null && (int) $current['reconcile_after'] <= time()) {
                AttemptStore::update($current, ['reconcile_after' => time() + 60], $lock);
            }
        }
    }
}
