<?php

declare(strict_types=1);

use Give\Donations\Models\Donation;
use Give\Donations\Models\DonationNote;
use Give\Donations\ValueObjects\DonationStatus;
use Give\Donations\ValueObjects\DonationType;
use Give\Framework\Support\ValueObjects\Money;
use PaymosGivewp\AttemptStore;
use PaymosGivewp\ConnectionStore;
use PaymosGivewp\EventStore;
use PaymosGivewp\Settlement;
use PaymosGivewp\WebhookController;

final class GivewpWebhook
{
    public static function testVerifiedPaymentOnce(): void
    {
        foreach ([22, 33] as $form) {
            $attempt = GivewpSettlementFixture::reset($form);
            $event = GivewpSettlementFixture::event();
            self::check(GivewpSettlementFixture::deliver($event)->get_status() === 200, 'The verified event was refused.');
            $stored = Donation::find(51);
            $row = AttemptStore::byId($attempt['id']);
            $projection = EventStore::projection($attempt['id'], 'paid');
            self::check($stored->status->isComplete() && $stored->gatewayTransactionId === $attempt['invoice_id'], 'Native status did not commit with the original invoice.');
            self::check($stored->amount->formatToDecimal() === '12.68' && $stored->feeAmountRecovered->formatToDecimal() === '0.34', 'The charged amount or Fee Recovery changed.');
            self::check($row['status'] === 'verified_paid' && $projection['stage'] === 'done' && (int) $projection['receipt_delivery_unknown'] === 0, 'The successful projection was not durably recorded.');
            self::check(count(GivewpSettlementFixture::$notes) === 1 && GivewpSettlementFixture::$effects === 1, 'The native side effect and keyed note did not run once.');
            self::check(substr(GivewpSettlementFixture::$notes[0]->content, -73) === '[paymos:' . $projection['projection_id'] . ']', 'The native note lacks its exact projection key.');
            self::check(GivewpSettlementFixture::$requests === ['/v1/invoices/inv_fixture', '/v1/refunds?invoice_id=inv_fixture&limit=20'], 'Payment was not backed by exact reverse invoice and refund reads.');
            self::check(GivewpSettlementFixture::deliver($event)->data['result'] === 'duplicate' && GivewpSettlementFixture::$writes === 1, 'The exact repeat entered native payment again.');
        }

        echo "PASS both native GiveWP models, exact charged Money, reverse evidence and one payment projection\n";
    }

    public static function testRejectInvalidOrInsufficientPayment(): void
    {
        GivewpSettlementFixture::reset();
        $event = GivewpSettlementFixture::event();
        self::check(GivewpSettlementFixture::deliver($event, 'whsec_wrong')->get_status() === 401, 'A forged HMAC was accepted.');
        self::check(GivewpSettlementFixture::deliver($event, 'whsec_original', time() - 301)->get_status() === 401, 'A stale signed delivery was accepted.');
        $event['data']['order']['amount'] = '12.34';
        self::check(GivewpSettlementFixture::deliver($event)->get_status() === 409 && GivewpSettlementFixture::$requests === [], 'The intended amount passed the Fee Recovery binding or reached Paymos.');
        self::assertPending();

        foreach (['project', 'environment', 'invoice', 'native_amount', 'coverage', 'asset', 'late', 'underpaid', 'refund_active', 'refund_complete', 'refund_foreign', 'refund_partial_page', 'manual_complete'] as $scenario) {
            GivewpSettlementFixture::reset();
            $event = GivewpSettlementFixture::event();
            switch ($scenario) {
                case 'project':
                    GivewpSettlementFixture::$invoice['project_id'] = 'prj_foreign';
                    break;
                case 'environment':
                    GivewpSettlementFixture::$invoice['is_test'] = false;
                    break;
                case 'invoice':
                    GivewpSettlementFixture::$invoice['invoice_id'] = 'inv_foreign';
                    break;
                case 'native_amount':
                    GivewpSettlementFixture::$native[51]->amount = new Money('1269', 'USD');
                    GivewpSettlementFixture::$native[51] = new Donation(GivewpSettlementFixture::$native[51]->getAttributes());
                    break;
                case 'coverage':
                    // The signed event and the exact GET must report the same coverage.
                    $event['data']['payment']['paid'] = '8.999999999999999999';
                    break;
                case 'asset':
                    $event['data']['payment']['network'] = 'TRC20';
                    break;
                case 'late':
                    GivewpSettlementFixture::$invoice['status'] = 'expired';
                    GivewpSettlementFixture::$invoice['paid_late_at'] = time();
                    $event = GivewpSettlementFixture::event('invoice.paid_late');
                    break;
                case 'underpaid':
                    GivewpSettlementFixture::$invoice['status'] = 'underpaid';
                    GivewpSettlementFixture::$invoice['payment']['paid'] = '8';
                    GivewpSettlementFixture::$invoice['payment']['remaining'] = '1';
                    $event = GivewpSettlementFixture::event('invoice.underpaid');
                    break;
                case 'refund_active':
                case 'refund_complete':
                case 'refund_foreign':
                    GivewpSettlementFixture::$refundPage['items'] = [GivewpSettlementFixture::refund($scenario)];
                    break;
                case 'refund_partial_page':
                    GivewpSettlementFixture::$refundPage['next_cursor'] = 'valid_but_unbounded';
                    break;
                case 'manual_complete':
                    GivewpSettlementFixture::$native[51]->status = DonationStatus::COMPLETE();
                    GivewpSettlementFixture::$native[51]->gatewayTransactionId = 'inv_fixture';
                    GivewpSettlementFixture::$native[51] = new Donation(GivewpSettlementFixture::$native[51]->getAttributes());
                    break;
            }
            GivewpSettlementFixture::deliver($event);
            self::check(GivewpSettlementFixture::$writes === 0 && GivewpSettlementFixture::$notes === [] && EventStore::projection(1, 'paid') === null, 'Unsafe evidence projected a paid donation: ' . $scenario);
            self::check(AttemptStore::byId(1)['status'] !== 'verified_paid', 'Unsafe evidence unlocked the receipt: ' . $scenario);
            if ($scenario !== 'manual_complete') {
                self::assertPending();
            }
        }

        echo "PASS signature, exact scope, amount, asset, coverage, late/partial funds, refund visibility and manual-state denials\n";
    }

    public static function testPaidWithinServerTolerance(): void
    {
        GivewpSettlementFixture::reset();
        GivewpSettlementFixture::$invoice['payment']['expected'] = '10';
        GivewpSettlementFixture::$invoice['payment']['paid'] = '9.95';
        GivewpSettlementFixture::$invoice['payment']['remaining'] = '0.05';
        self::check(GivewpSettlementFixture::deliver(GivewpSettlementFixture::event())->get_status() === 200, 'The final paid event was refused.');
        $row = AttemptStore::byId(1);
        self::check($row['status'] === 'verified_paid' && $row['review_reason'] === null && Donation::find(51)->status->isComplete()
            && GivewpSettlementFixture::$writes === 1, 'A final paid invoice with a remaining amount inside the server tolerance was held for review.');

        echo "PASS a final paid webhook completes the donation when the server accepted the remaining amount\n";
    }

    public static function testTerminalPaymentReview(): void
    {
        foreach ([
            ['expired', '0.000000000000000001', 'expired', 'terminal_funded'],
            ['cancelled', '8', 'cancelled', 'terminal_funded'],
            ['underpaid', '8', 'open', 'terminal_funded'],
            ['expired', '0.000', 'expired', null],
            ['expired', '1e-18', 'expired', null],
            ['cancelled', '-1', 'cancelled', null],
            ['cancelled', 1.25, 'cancelled', null],
            ['underpaid_waiting', '8', 'open', null],
        ] as [$status, $paid, $expectedStatus, $expectedReason]) {
            GivewpSettlementFixture::reset();
            $final = $status !== 'underpaid_waiting';
            GivewpSettlementFixture::$invoice['status'] = $status;
            GivewpSettlementFixture::$invoice['is_final'] = $final;
            GivewpSettlementFixture::$invoice['payment']['paid'] = $paid;
            GivewpSettlementFixture::$invoice['payment']['remaining'] = '8';
            $event = GivewpSettlementFixture::event('invoice.' . $status);
            // The signed event alone neither proves funds nor overrides the exact GET result.
            $event['data']['payment']['paid'] = $expectedReason === null ? '8' : '0';
            self::check(GivewpSettlementFixture::deliver($event)->data['result'] === 'accepted', 'The exact terminal event was refused.');
            $row = AttemptStore::byId(1);
            self::check($row['status'] === $expectedStatus && $row['review_reason'] === $expectedReason
                && ($row['reconcile_after'] === null) === $final, 'Unexpected terminal funding review for ' . $status . ' with ' . var_export($paid, true));
            self::check(GivewpSettlementFixture::$requests === ['/v1/invoices/inv_fixture'] && EventStore::projection(1, 'paid') === null, 'Terminal funds entered the paid projection path.');
            self::assertPending();
        }

        GivewpSettlementFixture::reset();
        GivewpSettlementFixture::$invoice['status'] = 'underpaid';
        GivewpSettlementFixture::$invoice['payment']['paid'] = '8';
        self::check(GivewpSettlementFixture::deliver(GivewpSettlementFixture::event('invoice.underpaid'))->data['result'] === 'accepted'
            && AttemptStore::byId(1)['review_reason'] === 'terminal_funded', 'The first confirmed funds were not retained.');
        GivewpSettlementFixture::$invoice['payment']['paid'] = '0';
        $event = GivewpSettlementFixture::event('invoice.underpaid');
        $event['event_id'] = 'evt_stale_empty';
        self::check(GivewpSettlementFixture::deliver($event)->data['result'] === 'accepted'
            && AttemptStore::byId(1)['review_reason'] === 'terminal_funded', 'A later empty read erased confirmed funds.');

        $event['event_id'] = 'evt_scope_mismatch';
        GivewpSettlementFixture::$invoice['project_id'] = 'prj_foreign';
        self::check(GivewpSettlementFixture::deliver($event)->data['result'] === 'accepted'
            && AttemptStore::byId(1)['review_reason'] === 'terminal_funded', 'A failed reverse binding erased confirmed funds.');

        GivewpSettlementFixture::$invoice['project_id'] = 'prj_fixture';
        $event = GivewpSettlementFixture::event('invoice.paid_late');
        $event['event_id'] = 'evt_late_underpaid';
        self::check(GivewpSettlementFixture::deliver($event)->data['result'] === 'accepted'
            && AttemptStore::byId(1)['review_reason'] === 'late_payment', 'Signed late evidence failed to record a distinct reason.');
        GivewpSettlementFixture::$invoice['payment']['paid'] = '8';
        $event = GivewpSettlementFixture::event('invoice.underpaid');
        $event['event_id'] = 'evt_stale_underpaid';
        self::check(GivewpSettlementFixture::deliver($event)->data['result'] === 'accepted'
            && AttemptStore::byId(1)['review_reason'] === 'late_payment', 'A stale read erased the late-payment reason.');
        self::assertPending();

        GivewpSettlementFixture::reset();
        GivewpSettlementFixture::$invoice['status'] = 'expired';
        GivewpSettlementFixture::$invoice['payment']['paid'] = '0';
        self::check(GivewpSettlementFixture::deliver(GivewpSettlementFixture::event('invoice.expired'))->data['result'] === 'accepted'
            && AttemptStore::byId(1)['status'] === 'expired', 'The empty terminal invoice was not closed.');
        $event = GivewpSettlementFixture::event('invoice.paid_late');
        $event['event_id'] = 'evt_late_expired';
        self::check(GivewpSettlementFixture::deliver($event)->data['result'] === 'accepted', 'The expired invoice did not accept the late event.');
        $row = AttemptStore::byId(1);
        self::check($row['status'] === 'expired' && $row['review_reason'] === 'late_payment'
            && $row['reconcile_after'] === null, 'Late payment reopened the expired invoice or failed to flag it for review.');
        self::assertPending();

        echo "PASS signed terminal funding review from exact decimal reverse evidence, stable across stale reads and distinct from late payment\n";
    }

    public static function testParallelDelivery(): void
    {
        GivewpSettlementFixture::reset();
        $event = GivewpSettlementFixture::event();
        $lock = AttemptStore::lock(51);
        self::check($lock->acquire(), 'The competing worker could not acquire its fixture mutex.');
        try {
            self::check(GivewpSettlementFixture::deliver($event)->get_status() === 409 && GivewpSettlementFixture::$requests === [], 'A busy delivery was acknowledged or contacted Paymos.');
        } finally {
            $lock->release();
        }
        self::check(GivewpSettlementFixture::deliver($event)->get_status() === 200, 'The retry did not reach the original donation.');
        $changedRaw = json_encode($event) . "\n";
        self::check(GivewpSettlementFixture::deliver($event, 'whsec_original', time(), $changedRaw)->get_status() === 409, 'Same ID with different signed bytes did not conflict.');
        self::check(GivewpSettlementFixture::$writes === 1 && count(GivewpSettlementFixture::$notes) === 1, 'Competing deliveries duplicated native effects.');

        echo "PASS busy and repeat delivery, raw-byte conflict and exact event commit\n";
    }

    public static function testNativeGuards(): void
    {
        GivewpSettlementFixture::reset();
        $stale = Donation::find(51);
        $same = Donation::find(51);
        $same->comment = 'Safe administrative note';
        $same->save();
        self::check(Donation::find(51)->comment === 'Safe administrative note', 'A fresh nonfinancial edit was blocked.');
        $stale->comment = 'Stale administrative note';
        self::denied(static function () use ($stale): void { $stale->save(); });

        foreach (['status', 'donorId', 'gatewayTransactionId'] as $field) {
            $incoming = Donation::find(51);
            $incoming->$field = ['status' => DonationStatus::COMPLETE(), 'donorId' => 88, 'gatewayTransactionId' => 'inv_foreign'][$field];
            self::denied(static function () use ($incoming): void { $incoming->save(); });
        }
        self::check(Settlement::guardLegacyStatus(true, 51) === false && Settlement::guardLegacyStatus(true, 52) === true, 'Legacy status or unrelated gateway handling was altered.');
        self::check(Settlement::guardFinancialMeta(null, 51, '_give_payment_donor_id') === false
            && Settlement::guardFinancialMeta(null, 52, '_give_payment_donor_id') === null, 'Protected metadata or unrelated metadata handling was altered.');
        self::denied(static function (): void { Settlement::guardNativeDestructive(Donation::find(51)); });

        $lock = AttemptStore::lock(51);
        self::check($lock->acquire(), 'The competing projector did not get its lock.');
        try {
            $incoming = Donation::find(51);
            $incoming->comment = 'Concurrent edit';
            self::denied(static function () use ($incoming): void { $incoming->save(); });
        } finally {
            $lock->release();
        }
        self::assertPending();
        echo "PASS native protected edits, stale save, harmless edit, destructive guards and same mutex\n";
    }

    public static function testRefundExternalOrderIdUtf16Boundary(): void
    {
        $validRefs = [
            'ascii_128' => str_repeat('a', 128),
            'ascii_129' => str_repeat('b', 129),
            'ascii_200' => str_repeat('c', 200),
            'cyrillic_150' => str_repeat("\xD1\x8F", 150),
            'cyrillic_200' => str_repeat("\xD1\x8F", 200),
            'astral_100' => str_repeat("\xF0\x9F\x98\x80", 100),
        ];

        // Non-blocking refunds (failed/cancelled full, completed/active overpayment) with valid UTF-16 keys
        // must allow verified payment completion.
        foreach ($validRefs as $label => $ref) {
            foreach ([
                ['mode' => 'full', 'status' => 'failed', 'is_final' => true],
                ['mode' => 'full', 'status' => 'cancelled', 'is_final' => true],
                ['mode' => 'overpayment', 'status' => 'completed', 'is_final' => true],
            ] as $shape) {
                GivewpSettlementFixture::reset();
                GivewpSettlementFixture::$refundPage['items'] = [array_replace(
                    GivewpSettlementFixture::refund('refund_complete'),
                    $shape + ['external_order_id' => $ref]
                )];
                self::check(GivewpSettlementFixture::deliver(GivewpSettlementFixture::event())->get_status() === 200,
                    'Non-blocking refund with valid UTF-16 key refused: ' . $label);
                $row = AttemptStore::byId(1);
                self::check($row['status'] === 'verified_paid' && $row['review_reason'] === null && Donation::find(51)->status->isComplete(),
                    'Non-blocking refund with valid UTF-16 key blocked completion: ' . $label);
            }
        }

        // Active/completed full refund with valid 200 UTF-16 key MUST still block completion.
        foreach (['refund_active', 'refund_complete'] as $blocking) {
            GivewpSettlementFixture::reset();
            GivewpSettlementFixture::$refundPage['items'] = [array_replace(
                GivewpSettlementFixture::refund($blocking),
                ['external_order_id' => $validRefs['cyrillic_200']]
            )];
            GivewpSettlementFixture::deliver(GivewpSettlementFixture::event());
            self::check(AttemptStore::byId(1)['status'] !== 'verified_paid'
                && AttemptStore::byId(1)['review_reason'] === 'full_refund_detected',
                'Blocking full refund did not block completion: ' . $blocking);
            self::assertPending();
        }

        // Keys over 200 UTF-16 units (201 ASCII, 201 Cyrillic, 100 astral + 1 ASCII, 101 astral) must fail snapshot validation.
        foreach ([
            'ascii_201' => str_repeat('a', 201),
            'cyrillic_201' => str_repeat("\xD1\x8F", 201),
            'astral_100_plus_1' => str_repeat("\xF0\x9F\x98\x80", 100) . 'a',
            'astral_101' => str_repeat("\xF0\x9F\x98\x80", 101),
        ] as $label => $tooLong) {
            GivewpSettlementFixture::reset();
            GivewpSettlementFixture::$refundPage['items'] = [array_replace(
                GivewpSettlementFixture::refund('refund_complete'),
                ['mode' => 'full', 'status' => 'failed', 'is_final' => true, 'external_order_id' => $tooLong]
            )];
            GivewpSettlementFixture::deliver(GivewpSettlementFixture::event());
            self::check(AttemptStore::byId(1)['status'] !== 'verified_paid'
                && AttemptStore::byId(1)['review_reason'] === 'refund_snapshot_invalid',
                'Over-limit UTF-16 key was accepted: ' . $label);
            self::assertPending();
        }

        echo "PASS BUG-1269 refund external_order_id 200 UTF-16 code unit boundaries in Settlement\n";
    }

    private static function denied(callable $operation): void
    {
        try {
            $operation();
        } catch (PaymosGivewp\NativeMutationDenied $error) {
            return;
        }
        throw new LogicException('A blocked native operation unexpectedly succeeded.');
    }

    private static function assertPending(): void
    {
        self::check(Donation::find(51)->status->isPending() && EventStore::projection(1, 'paid') === null, 'Unsafe evidence changed native state or created a projection.');
    }

    public static function check(bool $condition, string $failure): void
    {
        if (!$condition) {
            throw new LogicException($failure);
        }
    }
}

final class GivewpSettlementFixture
{
    public static $options = [];
    public static $native = [];
    public static $notes = [];
    public static $invoice = [];
    public static $refundPage = [];
    public static $requests = [];
    public static $writes = 0;
    public static $effects = 0;
    public static $fault = '';
    private const SOURCE = 'https://example.invalid/shop';

    public static function reset(int $form = 22, bool $ambiguous = false): array
    {
        $GLOBALS['wpdb'] = new GivewpSettlementDb();
        self::$options = ['home' => self::SOURCE, 'paymos_givewp_schema' => '1', 'paymos_givewp_settlement_schema' => '1'];
        self::$native = self::$notes = self::$requests = [];
        self::$writes = self::$effects = 0;
        self::$fault = '';
        self::$refundPage = ['items' => [], 'next_cursor' => null];
        $lock = ConnectionStore::lock(7);
        GivewpWebhook::check($lock->acquire(), 'The fixture connection mutex is unavailable.');
        try {
            $binding = ConnectionStore::connect(7, self::SOURCE, 'sandbox', ['sandbox' => [
                'api_key' => 'pk_test_fixture', 'api_secret' => 'sk_test_fixture', 'project_id' => 'prj_fixture',
                'webhook_secret' => 'whsec_original',
            ]], $lock);
        } finally {
            $lock->release();
        }

        self::$native[51] = new Donation([
            'id' => 51, 'formId' => $form, 'donorId' => 77, 'purchaseKey' => 'native-purchase-51',
            'gatewayId' => 'paymos', 'status' => DonationStatus::PENDING(), 'type' => DonationType::SINGLE(),
            'amount' => new Money('1268', 'USD'), 'feeAmountRecovered' => new Money('34', 'USD'),
            'firstName' => 'Native', 'email' => 'fixture@example.invalid',
        ]);
        $attempt = $binding + [
            'id' => 1, 'form_id' => $form, 'form_version' => $form === 33 ? 3 : 2, 'donation_id' => 51, 'donor_id' => 77,
            'principal_id' => 0, 'native_mode' => 'test', 'purchase_hash' => hash_hmac('sha256', 'paymos-givewp-v1|native-receipt|native-purchase-51', wp_salt('nonce')),
            'amount' => '12.68', 'currency' => 'USD', 'external_order_id' => 'givewp_fixture', 'invoice_id' => $ambiguous ? null : 'inv_fixture',
            'status' => $ambiguous ? 'creating' : 'open', 'remote_status' => $ambiguous ? null : 'awaiting_payment',
            'payment_url' => $ambiguous ? null : 'https://checkout.paymos.io/invoice/inv_fixture',
            'handle_hash' => hash('sha256', 'fixture-handoff'),
            'review_reason' => null, 'reconcile_after' => 0, 'created_at' => time(), 'updated_at' => time(),
        ];
        $GLOBALS['wpdb']->attempts[1] = $attempt;
        self::$invoice = [
            'invoice_id' => 'inv_fixture', 'project_id' => 'prj_fixture', 'is_final' => true, 'is_test' => true,
            'status' => 'paid', 'payment_url' => 'https://checkout.paymos.io/invoice/inv_fixture',
            'order' => ['external_id' => 'givewp_fixture', 'amount' => '12.6800', 'currency' => 'USD'],
            'payment' => ['currency' => 'USDT', 'network' => 'SOL', 'chain_id' => -2, 'expected' => '9.00', 'paid' => '9', 'remaining' => '0.00'],
        ];

        return $attempt;
    }

    public static function event(string $type = 'invoice.paid'): array
    {
        return ['event_id' => 'evt_fixture', 'event_type' => $type, 'version' => 1, 'occurred_at' => time(), 'data' => self::$invoice];
    }

    public static function deliver(array $event, string $secret = 'whsec_original', ?int $time = null, ?string $raw = null): WP_REST_Response
    {
        $time = $time ?? time();
        $raw = $raw ?? json_encode($event);
        $signature = 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $raw, $secret);

        return WebhookController::handle(new WP_REST_Request($raw, $signature));
    }

    public static function refund(string $scenario): array
    {
        return [
            'withdrawal_id' => 'wdr_fixture', 'project_id' => $scenario === 'refund_foreign' ? 'prj_foreign' : 'prj_fixture',
            'invoice_id' => 'inv_fixture', 'type' => 'refund', 'mode' => 'full', 'external_order_id' => 'external_refund',
            'status' => $scenario === 'refund_complete' ? 'completed' : 'signed', 'is_final' => $scenario === 'refund_complete',
            'is_test' => true, 'currency' => 'USDT', 'network' => 'SOL', 'amount' => '9',
        ];
    }
}

final class GivewpSettlementDb
{
    public $prefix = 'wp_7_';
    public $last_error = '';
    public $attempts = [];
    public $events = [];
    public $projections = [];
    private $queries = [];
    private $locks = [];

    public function prepare(string $sql, ...$arguments): string
    {
        $key = 'query_' . count($this->queries);
        $this->queries[$key] = [$sql, $arguments];

        return $key;
    }

    public function query(string $key): int
    {
        [$sql, $args] = $this->queries[$key] ?? [$key, []];
        if (strpos($sql, 'INSERT IGNORE') === 0) {
            [, $eventKey, $attemptId, $hash, $status, $time] = $args;
            if (isset($this->events[$eventKey])) {
                return 0;
            }
            $this->events[$eventKey] = [
                'event_key' => $eventKey, 'attempt_id' => $attemptId, 'body_hash' => $hash,
                'status' => $status, 'updated_at' => $time,
            ];

            return 1;
        }
        GivewpWebhook::check(strpos($sql, 'DELETE FROM') === 0 || $sql === 'ROLLBACK', 'Unexpected query: ' . $sql);

        return 0;
    }

    public function get_var(string $key): ?string
    {
        [$sql, $args] = $this->queries[$key];
        if (strpos($sql, 'GET_LOCK(') !== false) {
            if (isset($this->locks[$args[0]])) {
                return '0';
            }
            $this->locks[$args[0]] = true;

            return '46';
        }
        if (strpos($sql, 'IS_USED_LOCK(') !== false) {
            $owned = isset($this->locks[$args[1]]);
            if ($owned && strpos($sql, 'RELEASE_LOCK(') !== false) {
                unset($this->locks[$args[1]]);
            }

            return $owned ? '1' : '0';
        }
        GivewpWebhook::check(strpos($sql, 'BINARY RIGHT(comment_content') !== false, 'Unexpected scalar query: ' . $sql);
        foreach (GivewpSettlementFixture::$notes as $note) {
            if ($note->donationId === $args[1] && substr($note->content, -$args[3]) === $args[4]) {
                return (string) $note->id;
            }
        }

        return null;
    }

    public function get_row(string $key, string $format): ?array
    {
        [$sql, $args] = $this->queries[$key];
        if (strpos($sql, 'WHERE event_key') !== false) {
            return $this->events[$args[1]] ?? null;
        }
        if (strpos($sql, 'WHERE projection_id') !== false) {
            return $this->projections[$args[1]] ?? null;
        }
        if (strpos($sql, 'WHERE attempt_id = %d AND target') !== false) {
            foreach ($this->projections as $row) {
                if ($row['attempt_id'] === $args[1] && $row['target'] === $args[2]) {
                    return $row;
                }
            }

            return null;
        }
        GivewpWebhook::check($sql === 'SELECT * FROM %i WHERE %i = %s' && $args[0] === AttemptStore::table(), 'Unexpected attempt query: ' . $sql);
        [, $column, $value] = $args;
        foreach ($this->attempts as $row) {
            if ($row[$column] !== null && (string) $row[$column] === (string) $value) {
                return $row;
            }
        }

        return null;
    }

    public function get_results(string $key, string $format): array
    {
        [$sql, $args] = $this->queries[$key];
        GivewpWebhook::check(strpos($sql, 'ORDER BY reconcile_after') !== false, 'Unexpected reconciliation query.');
        $rows = array_filter($this->attempts, static function (array $row) use ($args): bool {
            return in_array($row['status'], ['creating', 'open', 'verified_paid'], true)
                && $row['handle_hash'] !== null
                && $row['reconcile_after'] !== null && $row['reconcile_after'] <= $args[1];
        });

        return array_slice(array_values($rows), 0, $args[2]);
    }

    public function insert(string $table, array $row): int
    {
        GivewpWebhook::check($table === EventStore::projectionsTable() && !isset($this->projections[$row['projection_id']]), 'An unexpected or duplicate projection was reserved.');
        $this->projections[$row['projection_id']] = $row;

        return 1;
    }

    public function update(string $table, array $values, array $where): int
    {
        if ($table === AttemptStore::table()) {
            $rows = &$this->attempts;
            $key = $where['id'];
        } elseif ($table === EventStore::eventsTable()) {
            $rows = &$this->events;
            $key = $where['event_key'];
        } else {
            GivewpWebhook::check($table === EventStore::projectionsTable(), 'An unknown table was written.');
            $rows = &$this->projections;
            $key = $where['projection_id'];
        }
        $current = $rows[$key] ?? null;
        foreach ($where as $field => $value) {
            if ($current === null || $current[$field] !== $value) {
                return 0;
            }
        }
        $rows[$key] = $values + $current;

        return $rows[$key] == $current ? 0 : 1;
    }
}

final class WP_REST_Request
{
    private $body;
    private $signature;

    public function __construct(string $body, string $signature)
    {
        $this->body = $body;
        $this->signature = $signature;
    }

    public function get_body(): string
    {
        return $this->body;
    }

    public function get_header(string $key): ?string
    {
        return $key === 'x-webhook-signature' ? $this->signature : null;
    }
}

final class WP_REST_Response
{
    public $data;
    private $status;

    public function __construct(array $data, int $status)
    {
        $this->data = $data;
        $this->status = $status;
    }

    public function header(string $name, string $value): void {}
    public function get_status(): int { return $this->status; }
}

final class WP_Error {}

function wp_safe_remote_request(string $url, array $arguments)
{
    GivewpWebhook::check(parse_url($url, PHP_URL_HOST) === 'api.paymos.io' && $arguments['method'] === 'GET'
        && $arguments['redirection'] === 0, 'Settlement attempted a non-read-only or untrusted remote request.');
    $path = parse_url($url, PHP_URL_PATH);
    $query = parse_url($url, PHP_URL_QUERY);
    GivewpSettlementFixture::$requests[] = $path . ($query === null ? '' : '?' . $query);
    if ($path === '/v1/invoices') {
        parse_str((string) $query, $params);
        GivewpWebhook::check($params === ['external_order_id' => 'givewp_fixture', 'limit' => '2', 'project_id' => 'prj_fixture'], 'Reconciliation did not read the exact old invoice tuple.');
        $data = ['items' => [['invoice_id' => 'inv_fixture']], 'next_cursor' => null];
    } elseif ($path === '/v1/invoices/inv_fixture') {
        $data = GivewpSettlementFixture::$invoice;
    } else {
        GivewpWebhook::check($path === '/v1/refunds', 'An unknown endpoint was queried.');
        parse_str((string) $query, $params);
        GivewpWebhook::check($params['invoice_id'] === 'inv_fixture' && $params['limit'] === '20', 'Refund visibility was not requested for the original invoice.');
        $data = GivewpSettlementFixture::$refundPage;
    }

    return ['body' => json_encode($data)];
}

function add_action(string $tag, callable $callback, int $priority = 10, int $arguments = 1): void
{
    $GLOBALS['givewpSettlementHooks'][$tag][$priority][] = [$callback, $arguments];
}

function add_filter(string $tag, callable $callback, int $priority = 10, int $arguments = 1): void
{
    add_action($tag, $callback, $priority, $arguments);
}

function do_action(string $tag, ...$arguments): void
{
    $callbacks = $GLOBALS['givewpSettlementHooks'][$tag] ?? [];
    ksort($callbacks);
    foreach ($callbacks as $group) {
        foreach ($group as [$callback, $count]) {
            $callback(...array_slice($arguments, 0, $count));
        }
    }
}

function get_option(string $key, $default = false) { return GivewpSettlementFixture::$options[$key] ?? $default; }
function update_option(string $key, $value, bool $autoload): bool { GivewpSettlementFixture::$options[$key] = $value; return true; }
function get_current_blog_id(): int { return 7; }
function give_is_test_mode(): bool { return true; }
function give_get_currencies_list(): array { return []; }
function give_get_ip(): string { return '192.0.2.22'; }
function __(string $value, string $domain = ''): string { return $value; }
function wp_salt(string $kind): string { return 'fixture-only-salt-' . $kind; }
function wp_parse_url(string $url, int $component = -1) { return parse_url($url, $component); }
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code(array $response): int { return 200; }
function wp_remote_retrieve_body(array $response): string { return $response['body']; }
function wp_remote_retrieve_headers(array $response): array { return []; }

function give(?string $class = null)
{
    static $container;
    if ($container === null) {
        $container = new class {
            public $donations;
            public $form_meta;

            public function __construct()
            {
                $this->donations = new class {
                    public $notes;

                    public function __construct()
                    {
                        $this->notes = new class {
                            public function insert(DonationNote $note): void
                            {
                                $note->id = count(GivewpSettlementFixture::$notes) + 1;
                                GivewpSettlementFixture::$notes[] = clone $note;
                                if (GivewpSettlementFixture::$fault === 'after_note') {
                                    GivewpSettlementFixture::$fault = '';
                                    throw new RuntimeException('Fixture interruption after note commit.');
                                }
                            }
                        };
                    }

                    public function getById(int $id): ?Donation
                    {
                        return isset(GivewpSettlementFixture::$native[$id]) ? clone GivewpSettlementFixture::$native[$id] : null;
                    }

                    public function update(Donation $donation): void
                    {
                        if (GivewpSettlementFixture::$fault === 'before_native') {
                            GivewpSettlementFixture::$fault = '';
                            throw new RuntimeException('Fixture interruption before native commit.');
                        }
                        do_action('givewp_donation_updating', $donation);
                        $old = GivewpSettlementFixture::$native[$donation->id]->status->getValue();
                        $fresh = new Donation($donation->getAttributes());
                        GivewpSettlementFixture::$native[$donation->id] = $fresh;
                        GivewpSettlementFixture::$writes++;
                        if (GivewpSettlementFixture::$fault === 'after_native') {
                            GivewpSettlementFixture::$fault = '';
                            throw new RuntimeException('Fixture interruption between native commit and after-update hooks.');
                        }
                        if ($old !== $donation->status->getValue()) {
                            GivewpSettlementFixture::$effects++;
                        }
                        do_action('givewp_donation_updated', $donation);
                    }
                };
                $this->form_meta = new class {
                    public function get_meta(int $form, string $name, bool $single): bool { return $form === 33; }
                };
            }

            public function singletonIf(...$arguments): void {}
        };
    }

    return $class === null ? $container : new $class();
}

function loadGivewpSettlementFixture(array $arguments): void
{
    $path = $arguments[1] ?? '';
    if (!is_file($path) || hash_file('sha256', $path) !== 'e4c2e75aeb7225105950e5d1e251af5050fbcdac7fee6bb20dd0853b536f5ecf') {
        fwrite(STDERR, "Pass the pinned GiveWP 4.19.1.1 ZIP as the first argument.\n");
        exit(2);
    }

    define('ABSPATH', __DIR__);
    define('ARRAY_A', 'ARRAY_A');
    define('DAY_IN_SECONDS', 86400);
    define('GIVE_VERSION', '4.19.1.1');
    $native = 'phar://' . str_replace('\\', '/', realpath($path)) . '/give/';
    require $native . 'vendor/autoload.php';
    spl_autoload_register(static function (string $class) use ($native): void {
        if (strncmp($class, 'Give\\', 5) === 0) {
            $file = $native . 'src/' . str_replace('\\', '/', substr($class, 5)) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    });

    require dirname(__DIR__) . '/paymos-givewp.php';
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    loadGivewpSettlementFixture($argv);
    GivewpWebhook::testVerifiedPaymentOnce();
    GivewpWebhook::testRejectInvalidOrInsufficientPayment();
    GivewpWebhook::testPaidWithinServerTolerance();
    GivewpWebhook::testTerminalPaymentReview();
    GivewpWebhook::testParallelDelivery();
    GivewpWebhook::testNativeGuards();
    GivewpWebhook::testRefundExternalOrderIdUtf16Boundary();
}
