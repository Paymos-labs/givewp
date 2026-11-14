<?php

declare(strict_types=1);

namespace PaymosGivewp;

use PaymosGivewpVendor\Paymos\Plugin\WordPressOrderLock;

defined('ABSPATH') || exit;

final class EventStore
{
    public static function eventsTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'paymos_givewp_events';
    }

    public static function projectionsTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'paymos_givewp_projections';
    }

    public static function eventKey(array $attempt, string $eventId): string
    {
        return hash('sha256', implode('|', [$attempt['site'], $attempt['project_id'], $attempt['environment'], $eventId]));
    }

    /** Identical uncommitted events can retry only after acquiring the same donation lock. */
    public static function begin(array $attempt, string $key, string $bodyHash, WordPressOrderLock $lock): string
    {
        global $wpdb;
        $lock->assertOwned();
        $now = time();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Unique reservation also arbitrates conflicting events for different donations.
        $inserted = $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO %i (event_key,attempt_id,body_hash,status,updated_at) VALUES (%s,%d,%s,%s,%d)',
            self::eventsTable(), $key, $attempt['id'], $bodyHash, 'processing', $now
        ));
        if ($inserted === false) {
            throw new \RuntimeException('Paymos event reservation failed.');
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Authoritative replay record.
        $record = $wpdb->get_row($wpdb->prepare('SELECT attempt_id,body_hash,status FROM %i WHERE event_key = %s', self::eventsTable(), $key), ARRAY_A);
        if (!is_array($record) || $wpdb->last_error !== '') {
            throw new \RuntimeException('Paymos event reservation was lost.');
        }
        if ((int) $record['attempt_id'] !== $attempt['id'] || !hash_equals($record['body_hash'], $bodyHash)) {
            return 'conflict';
        }

        return $record['status'] === 'done' ? 'duplicate' : 'process';
    }

    public static function finish(array $attempt, string $key, string $bodyHash, WordPressOrderLock $lock): void
    {
        global $wpdb;
        $lock->assertOwned();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Acknowledge only our exact durable event after application.
        $updated = $wpdb->update(self::eventsTable(), ['status' => 'done', 'updated_at' => time()], [
            'event_key' => $key,
            'attempt_id' => $attempt['id'],
            'body_hash' => $bodyHash,
            'status' => 'processing',
        ]);
        if ($updated !== 1) {
            throw new \RuntimeException('Paymos event could not be committed.');
        }
    }

    public static function projection(int $attemptId, string $target): ?array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Current projection state cannot be cached across workers.
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE attempt_id = %d AND target = %s', self::projectionsTable(), $attemptId, $target
        ), ARRAY_A);
        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('Paymos projection store is unavailable.');
        }

        return is_array($row) ? $row : null;
    }

    public static function byProjectionId(string $id): ?array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify the exact projection before note recovery.
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE projection_id = %s', self::projectionsTable(), $id
        ), ARRAY_A);
        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('Paymos projection store is unavailable.');
        }

        return is_array($row) ? $row : null;
    }

    public static function reserveProjection(array $attempt, string $target, string $proofHash, WordPressOrderLock $lock): array
    {
        global $wpdb;
        $lock->assertOwned();
        $projectionId = hash('sha256', implode('|', [
            $attempt['site'], $attempt['id'], $attempt['donation_id'], $attempt['generation'], $attempt['invoice_id'], $target,
        ]));
        $marker = [
            'attempt_id' => $attempt['id'],
            'target' => $target,
            'projection_id' => $projectionId,
            'proof_hash' => $proofHash,
            'stage' => 'awaiting_native',
            'receipt_delivery_unknown' => 0,
            'updated_at' => time(),
        ];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Durable before the native transaction and its side effects.
        if ($wpdb->insert(self::projectionsTable(), $marker) !== 1) {
            throw new \RuntimeException('Paymos projection could not be reserved.');
        }

        return $marker;
    }

    public static function advance(array $marker, string $stage, bool $receiptUnknown, WordPressOrderLock $lock): array
    {
        global $wpdb;
        $lock->assertOwned();
        $changes = [
            'stage' => $stage,
            'receipt_delivery_unknown' => (int) ($receiptUnknown || (int) $marker['receipt_delivery_unknown'] === 1),
            'updated_at' => time(),
        ];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Do not overwrite a marker advanced by another worker.
        $updated = $wpdb->update(self::projectionsTable(), $changes, [
            'projection_id' => $marker['projection_id'],
            'stage' => $marker['stage'],
        ]);
        if ($updated !== 1) {
            throw new \RuntimeException('Paymos projection could not advance.');
        }

        return $changes + $marker;
    }

    public static function prune(): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Keep replay rows longer than signed delivery tolerance, in bounded batches.
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE status = %s AND updated_at < %d LIMIT 100', self::eventsTable(), 'done', time() - 30 * DAY_IN_SECONDS));
    }

    public static function uninstall(): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall drops this site's Paymos webhook replay table.
        $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', self::eventsTable()));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Remove markers without surviving unresolved financial provenance.
        $wpdb->query($wpdb->prepare(
            'DELETE p FROM %i p LEFT JOIN %i a ON a.id = p.attempt_id WHERE a.id IS NULL',
            self::projectionsTable(), AttemptStore::table()
        ));
    }
}
