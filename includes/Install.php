<?php

declare(strict_types=1);

namespace PaymosGivewp;

defined('ABSPATH') || exit;

final class Install
{
    private const SCHEMA_OPTION = 'paymos_givewp_schema';
    private const SCHEMA_VERSION = '1';
    private const SETTLEMENT_OPTION = 'paymos_givewp_settlement_schema';
    private const REFUND_OPTION = 'paymos_givewp_refund_schema';

    public static function ready(): bool
    {
        return function_exists('get_option') && get_option(self::SCHEMA_OPTION) === self::SCHEMA_VERSION;
    }

    public static function settlementReady(): bool
    {
        return self::ready() && get_option(self::SETTLEMENT_OPTION) === self::SCHEMA_VERSION;
    }

    public static function refundsReady(): bool
    {
        return self::settlementReady() && get_option(self::REFUND_OPTION) === self::SCHEMA_VERSION;
    }

    public static function activate(bool $networkWide = false): void
    {
        if ($networkWide) {
            wp_die(esc_html__('Activate Paymos separately on each WordPress site.', 'paymos-stablecoin-donations'));
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = AttemptStore::table();
        $events = EventStore::eventsTable();
        $projections = EventStore::projectionsTable();
        $refunds = RefundIntentStore::table();
        $collate = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            site bigint(20) unsigned NOT NULL,
            form_id bigint(20) unsigned NOT NULL,
            form_version tinyint unsigned NOT NULL,
            donation_id bigint(20) unsigned NOT NULL,
            donor_id bigint(20) unsigned NOT NULL,
            principal_id bigint(20) unsigned NOT NULL,
            native_mode varchar(8) NOT NULL,
            native_session_hash char(64) DEFAULT NULL,
            purchase_hash char(64) DEFAULT NULL,
            intent_hash char(64) DEFAULT NULL,
            cookie_hash char(64) DEFAULT NULL,
            handle_hash char(64) DEFAULT NULL,
            source varchar(2048) NOT NULL,
            generation char(32) NOT NULL,
            environment varchar(8) NOT NULL,
            project_id varchar(128) NOT NULL,
            amount varchar(64) NOT NULL,
            currency char(3) NOT NULL,
            external_order_id varchar(80) NOT NULL,
            invoice_id varchar(128) DEFAULT NULL,
            payment_url varchar(2048) DEFAULT NULL,
            status varchar(24) NOT NULL,
            remote_status varchar(32) DEFAULT NULL,
            review_reason varchar(40) DEFAULT NULL,
            reconcile_after bigint(20) unsigned DEFAULT 0,
            created_at bigint(20) unsigned NOT NULL,
            updated_at bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY native_intent (form_id,intent_hash),
            UNIQUE KEY donation_id (donation_id),
            UNIQUE KEY external_order_id (external_order_id),
            UNIQUE KEY invoice_id (invoice_id),
            UNIQUE KEY handle_hash (handle_hash),
            KEY reconcile_due (reconcile_after,id),
            KEY donor_lookup (donor_id)
        ) ENGINE=InnoDB $collate;");
        dbDelta("CREATE TABLE $events (
            event_key char(64) NOT NULL,
            attempt_id bigint(20) unsigned NOT NULL,
            body_hash char(64) NOT NULL,
            status varchar(12) NOT NULL,
            updated_at bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (event_key),
            KEY purge_scan (status,updated_at)
        ) ENGINE=InnoDB $collate;");
        dbDelta("CREATE TABLE $projections (
            projection_id char(64) NOT NULL,
            attempt_id bigint(20) unsigned NOT NULL,
            target varchar(16) NOT NULL,
            proof_hash char(64) NOT NULL,
            stage varchar(20) NOT NULL,
            receipt_delivery_unknown tinyint unsigned NOT NULL DEFAULT 0,
            updated_at bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (projection_id),
            UNIQUE KEY attempt_target (attempt_id,target)
        ) ENGINE=InnoDB $collate;");
        dbDelta("CREATE TABLE $refunds (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            site bigint(20) unsigned NOT NULL,
            attempt_id bigint(20) unsigned NOT NULL,
            donation_id bigint(20) unsigned NOT NULL,
            generation char(32) NOT NULL,
            environment varchar(8) NOT NULL,
            project_id varchar(128) NOT NULL,
            invoice_id varchar(128) NOT NULL,
            mode varchar(16) NOT NULL,
            external_order_id varchar(80) NOT NULL,
            withdrawal_id varchar(128) DEFAULT NULL,
            expected_amount varchar(64) DEFAULT NULL,
            currency varchar(24) DEFAULT NULL,
            network varchar(40) DEFAULT NULL,
            recipient_hash char(64) DEFAULT NULL,
            state varchar(24) NOT NULL,
            review_reason varchar(48) DEFAULT NULL,
            submitted_at bigint(20) unsigned DEFAULT NULL,
            reconcile_after bigint(20) unsigned DEFAULT NULL,
            created_at bigint(20) unsigned NOT NULL,
            updated_at bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY attempt_mode (attempt_id,mode),
            UNIQUE KEY external_order_id (external_order_id),
            UNIQUE KEY withdrawal_id (withdrawal_id),
            KEY reconcile_due (reconcile_after,id)
        ) ENGINE=InnoDB $collate;");
        foreach ([$table, $events, $projections, $refunds] as $required) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Validate all installed tables before registering settlement.
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($required))) !== $required) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Only the translated error message mentions table creation. The query is read-only.
                wp_die(esc_html__('Paymos could not create its donation tables.', 'paymos-stablecoin-donations'));
            }
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirm the in-place attempt table migration.
        if ($wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM %i LIKE %s', $table, $wpdb->esc_like('reconcile_after'))) !== 'reconcile_after') {
            wp_die(esc_html__('Paymos could not upgrade its donation table.', 'paymos-stablecoin-donations'));
        }
        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
        update_option(self::SETTLEMENT_OPTION, self::SCHEMA_VERSION, false);
        update_option(self::REFUND_OPTION, self::SCHEMA_VERSION, false);
        Reconciler::schedule();
    }

    public static function maybeUpgrade(): void
    {
        if (current_user_can('manage_give_settings') && !self::refundsReady()) {
            self::activate();
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(Reconciler::HOOK);
    }

    public static function unresolvedPayments(): int
    {
        return AttemptStore::unresolvedCount();
    }

    public static function retainedPayments(): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Only sanitized references from a previous uninstall.
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, invoice_id, external_order_id, project_id, environment FROM %i
             WHERE site = %d AND form_id = 0 AND handle_hash IS NULL AND purchase_hash IS NULL ORDER BY id LIMIT 25',
            AttemptStore::table(), (int) get_current_blog_id()
        ), ARRAY_A);

        return is_array($rows) && $wpdb->last_error === '' ? $rows : [];
    }

    public static function eraseRetainedPayment(int $id): bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Remove an explicitly selected sanitized record and its orphan markers together.
        $deleted = $wpdb->query($wpdb->prepare(
            'DELETE a, p FROM %i a LEFT JOIN %i p ON p.attempt_id = a.id
             WHERE a.id = %d AND a.site = %d AND a.form_id = 0 AND a.handle_hash IS NULL AND a.purchase_hash IS NULL',
            AttemptStore::table(), EventStore::projectionsTable(), $id, (int) get_current_blog_id()
        ));

        return is_int($deleted) && $deleted > 0;
    }

    public static function uninstall(): void
    {
        if (!is_multisite()) {
            self::uninstallSite();

            return;
        }

        $offset = 0;
        do {
            $sites = get_sites(['fields' => 'ids', 'number' => 100, 'offset' => $offset]);
            foreach ($sites as $site) {
                switch_to_blog((int) $site);
                try {
                    self::uninstallSite();
                } finally {
                    restore_current_blog();
                }
            }
            $offset += count($sites);
        } while (count($sites) === 100);
    }

    private static function uninstallSite(): void
    {
        $schema = get_option(self::SCHEMA_OPTION, null);
        $settlement = get_option(self::SETTLEMENT_OPTION, null);
        $refunds = get_option(self::REFUND_OPTION, null);
        if ($schema === null && $settlement === null && $refunds === null
            && get_option(ConnectionStore::OPTION, null) === null && get_option(DeviceState::OPTION, null) === null) {
            return;
        }

        self::deactivate();
        delete_option(ConnectionStore::OPTION);
        delete_option(DeviceState::OPTION);
        if ($schema !== null) {
            AttemptStore::eraseBrowserBindings();
            delete_option(self::SCHEMA_OPTION);
        }
        if ($settlement !== null) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Never schedule preserved references after reinstall.
            $wpdb->query($wpdb->prepare('UPDATE %i SET reconcile_after = NULL WHERE handle_hash IS NULL', AttemptStore::table()));
            EventStore::uninstall();
            delete_option(self::SETTLEMENT_OPTION);
        }
        if ($refunds !== null) {
            RefundIntentStore::uninstall();
            delete_option(self::REFUND_OPTION);
        }
    }
}
