<?php

declare(strict_types=1);

namespace PaymosGivewp;

use PaymosGivewpVendor\Paymos\IdempotencyKey;
use PaymosGivewpVendor\Paymos\Plugin\WordPressOrderLock;

defined('ABSPATH') || exit;

final class RefundIntentStore
{
    public const MODES = ['full', 'overpayment'];

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'paymos_givewp_refunds';
    }

    public static function byAttempt(int $attemptId, string $mode): ?array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the current refund identity for this attempt and mode.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE attempt_id = %d AND mode = %s', self::table(), $attemptId, $mode), ARRAY_A);

        return self::one($row);
    }

    public static function byId(int $id): ?array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read authoritative refund state.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::table(), $id), ARRAY_A);

        return self::one($row);
    }

    public static function reserve(array $attempt, string $mode, WordPressOrderLock $lock): array
    {
        global $wpdb;
        $lock->assertOwned();
        if (!in_array($mode, self::MODES, true) || $attempt['site'] !== (int) get_current_blog_id()) {
            throw new \UnexpectedValueException('Invalid Paymos refund scope.');
        }
        $existing = self::byAttempt($attempt['id'], $mode);
        if ($existing !== null) {
            return $existing;
        }
        $now = time();
        $row = [
            'site' => $attempt['site'],
            'attempt_id' => $attempt['id'],
            'donation_id' => $attempt['donation_id'],
            'generation' => $attempt['generation'],
            'environment' => $attempt['environment'],
            'project_id' => $attempt['project_id'],
            'invoice_id' => $attempt['invoice_id'],
            'mode' => $mode,
            'external_order_id' => IdempotencyKey::externalOrderId('givewp_refund'),
            'state' => 'reserved',
            'created_at' => $now,
            'updated_at' => $now,
        ];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Commit the unique refund identity before any outbound request.
        if ($wpdb->insert(self::table(), $row) !== 1) {
            throw new \RuntimeException('Paymos refund intent could not be reserved.');
        }
        $saved = self::byAttempt($attempt['id'], $mode);
        if ($saved === null) {
            throw new \RuntimeException('Paymos refund intent could not be read.');
        }

        return $saved;
    }

    public static function matches(array $intent, array $attempt): bool
    {
        return $intent['attempt_id'] === $attempt['id'] && $intent['site'] === (int) get_current_blog_id()
            && $intent['site'] === $attempt['site'] && $intent['donation_id'] === $attempt['donation_id']
            && $intent['invoice_id'] === $attempt['invoice_id'] && $intent['project_id'] === $attempt['project_id']
            && $intent['generation'] === $attempt['generation'] && $intent['environment'] === $attempt['environment'];
    }

    public static function update(array $intent, array $values, WordPressOrderLock $lock): array
    {
        global $wpdb;
        $lock->assertOwned();
        $values['updated_at'] = time();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact refund update under the donation mutex.
        $result = $wpdb->update(self::table(), $values, ['id' => $intent['id'], 'state' => $intent['state']]);
        $current = self::byId($intent['id']);
        if ($result === false || $current === null || array_diff_assoc($values, $current) !== []) {
            throw new \RuntimeException('Paymos refund state could not be saved.');
        }

        return $current;
    }

    public static function due(int $limit): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the bounded refund reconciliation queue.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM %i WHERE state IN ('unknown','pending','projection_pending','review') AND reconcile_after <= %d ORDER BY reconcile_after,id LIMIT %d",
            self::table(), time(), $limit
        ), ARRAY_A);

        return self::rows($rows);
    }

    public static function hasUnresolvedBindings(int $site): bool
    {
        return $site !== (int) get_current_blog_id() || !Install::refundsReady() || self::unresolvedCount() > 0;
    }

    public static function unresolvedCount(): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- This count protects key removal and informs the store administrator.
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE state IN ('unknown','pending','projection_pending','review') AND submitted_at IS NOT NULL",
            self::table()
        ));
        if ($wpdb->last_error !== '' || !is_numeric($count)) {
            throw new \RuntimeException('Paymos refund store is unavailable.');
        }

        return (int) $count;
    }

    public static function retained(): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read retained references for the current site.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM %i WHERE state = 'retained' AND site = %d ORDER BY id LIMIT 25",
            self::table(), (int) get_current_blog_id()
        ), ARRAY_A);

        return self::rows($rows);
    }

    public static function eraseRetained(int $id): bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Only explicitly selected provenance from a previous uninstall.
        return $wpdb->delete(self::table(), ['id' => $id, 'site' => (int) get_current_blog_id(), 'state' => 'retained']) === 1;
    }

    public static function uninstall(): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Remove the recipient fingerprint and all polling authorization from retained provenance.
        $wpdb->query($wpdb->prepare(
            "UPDATE %i SET state = 'retained', recipient_hash = NULL, reconcile_after = NULL
             WHERE state IN ('unknown','pending','projection_pending','review') AND submitted_at IS NOT NULL",
            self::table()
        ));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Native history already holds completed, failed or unused local intents.
        $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE state <> 'retained'", self::table()));
    }

    private static function one($row): ?array
    {
        global $wpdb;
        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('Paymos refund store is unavailable.');
        }

        return is_array($row) ? self::normalize($row) : null;
    }

    private static function rows($rows): array
    {
        global $wpdb;
        if ($wpdb->last_error !== '' || !is_array($rows)) {
            throw new \RuntimeException('Paymos refund store is unavailable.');
        }

        return array_map([self::class, 'normalize'], $rows);
    }

    private static function normalize(array $row): array
    {
        foreach (['id', 'site', 'attempt_id', 'donation_id', 'reconcile_after', 'submitted_at'] as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }

        return $row;
    }
}
