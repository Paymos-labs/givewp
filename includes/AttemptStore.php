<?php

declare(strict_types=1);

namespace PaymosGivewp;

use PaymosGivewpVendor\Paymos\Plugin\WordPressOrderLock;

defined('ABSPATH') || exit;

final class AttemptStore
{
    public const REVIEW_LATE_PAYMENT = 'late_payment';
    public const REVIEW_TERMINAL_FUNDED = 'terminal_funded';

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'paymos_givewp_attempts';
    }

    public static function lock(int $donationId): WordPressOrderLock
    {
        return new WordPressOrderLock('paymos-givewp-donation', $donationId);
    }

    public static function byIntent(int $formId, string $intentHash): ?array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the original intent under its native donation mutex.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE form_id = %d AND intent_hash = %s', self::table(), $formId, $intentHash), ARRAY_A);

        return self::row($row);
    }

    public static function byHandle(string $handle): ?array
    {
        return self::by('handle_hash', hash('sha256', $handle));
    }

    public static function byId(int $id): ?array
    {
        return self::by('id', $id);
    }

    public static function byDonation(int $donationId): ?array
    {
        return self::by('donation_id', $donationId);
    }

    public static function hasBoundDonor(int $donorId): bool
    {
        return self::by('donor_id', $donorId) !== null;
    }

    public static function due(int $limit): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the oldest eligible reconciliation work.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM %i WHERE status IN ('creating','open','verified_paid') AND handle_hash IS NOT NULL
             AND reconcile_after <= %d ORDER BY reconcile_after,id LIMIT %d",
            self::table(), time(), $limit
        ), ARRAY_A);
        if ($wpdb->last_error !== '' || !is_array($rows)) {
            throw new \RuntimeException('Paymos reconciliation scan failed.');
        }

        return array_map([self::class, 'normalize'], $rows);
    }

    public static function byInvoice(string $invoiceId): ?array
    {
        return self::by('invoice_id', $invoiceId);
    }

    public static function byExternalOrder(string $externalId): ?array
    {
        return self::by('external_order_id', $externalId);
    }

    public static function insert(array $row, WordPressOrderLock $lock): void
    {
        global $wpdb;
        $lock->assertOwned();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- An authoritative write to the plugin's per-site payment table.
        if ($wpdb->insert(self::table(), $row) !== 1) {
            throw new \RuntimeException('Paymos could not save the donation intent.');
        }
    }

    public static function markCreating(array $row, WordPressOrderLock $lock): void
    {
        if ($row['status'] === 'prepared') {
            self::update($row, ['status' => 'creating'], $lock);
        }
    }

    public static function bindInvoice(array $row, array $invoice, WordPressOrderLock $lock): void
    {
        if ($row['invoice_id'] !== null && $row['invoice_id'] !== $invoice['invoice_id']) {
            throw new \UnexpectedValueException('Paymos returned a different invoice for this intent.');
        }
        if (!in_array($row['status'], ['creating', 'open'], true)) {
            throw new \UnexpectedValueException('The donation attempt cannot receive an invoice.');
        }

        self::update($row, [
            'invoice_id' => $invoice['invoice_id'],
            'payment_url' => $invoice['payment_url'],
            'remote_status' => $invoice['status'],
            'status' => 'open',
        ], $lock);
    }

    public static function hasUnresolvedBindings(int $site): bool
    {
        if ($site !== (int) get_current_blog_id() || !Install::ready()) {
            return true;
        }

        global $wpdb;
        $unresolved = self::unresolvedPaymentCondition();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- The shared predicate only contains fixed statuses and fixed review reasons. Prepared intents also protect their binding.
        $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM %i WHERE status = 'prepared' OR $unresolved LIMIT 1", self::table()));

        return $id !== null || $wpdb->last_error !== '';
    }

    public static function unresolvedCount(): int
    {
        global $wpdb;
        $unresolved = self::unresolvedPaymentCondition();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- The shared predicate only contains fixed statuses and fixed review reasons. Retained references use a separate review list.
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE handle_hash IS NOT NULL AND $unresolved", self::table()));
        if ($wpdb->last_error !== '' || !is_numeric($count)) {
            throw new \RuntimeException('Paymos payment store is unavailable.');
        }

        return (int) $count;
    }

    /** The writable columns are provided by payment services, never by a request. */
    public static function update(array $row, array $values, WordPressOrderLock $lock): void
    {
        global $wpdb;
        $lock->assertOwned();
        $values['updated_at'] = time();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact attempt write under the native donation mutex.
        $updated = $wpdb->update(self::table(), $values, ['id' => $row['id'], 'status' => $row['status']]);
        if ($updated === false) {
            throw new \RuntimeException('Paymos could not save the donation state.');
        }
        if ($updated === 0) {
            $current = self::byId($row['id']);
            if ($current === null || array_diff_assoc($values, $current) !== []) {
                throw new \RuntimeException('The Paymos donation state changed before saving.');
            }
        }
    }

    public static function eraseBrowserBindings(): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall removes browser access from this site's payment table.
        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET handle_hash = NULL, cookie_hash = NULL, intent_hash = NULL,
            purchase_hash = NULL, native_session_hash = NULL, donor_id = 0, principal_id = 0, form_id = 0, payment_url = NULL',
            self::table()
        ));
        $unresolved = self::unresolvedPaymentCondition();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- The shared predicate only contains fixed statuses and fixed review reasons.
        $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE NOT $unresolved", self::table()));
    }

    private static function unresolvedPaymentCondition(): string
    {
        $condition = "status NOT IN ('prepared', 'verified_paid', 'cancelled', 'expired', 'refunded')";
        // Checkout-only installations do not yet have the settlement review column.
        if (Install::settlementReady()) {
            $condition .= " OR COALESCE(review_reason, '') IN ('" . self::REVIEW_LATE_PAYMENT . "', '" . self::REVIEW_TERMINAL_FUNDED . "')";
        }

        return '(' . $condition . ')';
    }

    private static function by(string $column, $value): ?array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read authoritative payment state.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE %i = %s', self::table(), $column, (string) $value), ARRAY_A);

        return self::row($row);
    }

    private static function row($row): ?array
    {
        global $wpdb;
        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('The Paymos donation store is unavailable.');
        }
        if (!is_array($row)) {
            return null;
        }
        return self::normalize($row);
    }

    private static function normalize(array $row): array
    {
        foreach (['id', 'site', 'form_id', 'form_version', 'donation_id', 'donor_id', 'principal_id', 'created_at', 'updated_at'] as $field) {
            $row[$field] = (int) $row[$field];
        }

        return $row;
    }
}
