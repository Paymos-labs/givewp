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
use PaymosGivewp\Reconciler;
use PaymosGivewp\RefundAdmin;
use PaymosGivewp\RefundIntentStore;
use PaymosGivewp\Settlement;

final class GivewpRefund
{
    public static function testPendingResumeExactIntent(): void
    {
        RefundFixture::reset();
        RefundFixture::$losePostResponse = true;
        self::check(self::state(RefundFixture::call('request'), 'full') === 'unknown', 'A lost acceptance was not preserved.');
        $first = RefundIntentStore::byAttempt(1, 'full');
        self::check($first['state'] === 'unknown' && $first['expected_amount'] === '9' && $first['withdrawal_id'] === null
            && RefundFixture::$posts === [['invoice_id' => 'inv_fixture', 'mode' => 'full', 'external_order_id' => $first['external_order_id']]],
            'The first request was not the exact original three-field operation.');
        self::check(Donation::find(51)->status->isComplete(), 'Unknown funds marked the donation refunded.');

        $lock = ConnectionStore::lock(7);
        self::check($lock->acquire(), 'The connection mutex is unavailable.');
        try {
            ConnectionStore::disable(7, $lock);
        } finally {
            $lock->release();
        }
        self::check(self::state(RefundFixture::call('request'), 'full') === 'pending' && count(RefundFixture::$posts) === 1,
            'A repeated request after disable did not use GET-only recovery.');
        $pending = RefundIntentStore::byAttempt(1, 'full');
        self::check($pending['id'] === $first['id'] && $pending['external_order_id'] === $first['external_order_id']
            && $pending['withdrawal_id'] === 'wdr_full', 'Recovery did not bind the original accepted operation.');
        self::check(RefundFixture::call('request', 'overpayment') instanceof WP_Error && count(RefundFixture::$posts) === 1,
            'A disabled gateway accepted a new refund write.');

        RefundFixture::terminal('full', 'completed');
        RefundFixture::$noteFault = true;
        self::check(self::state(RefundFixture::call('poll'), 'full') === 'projection_pending', 'A fault after the native note was acknowledged as complete.');
        self::check(Donation::find(51)->status->isRefunded() && Donation::find(51)->gatewayTransactionId === 'inv_fixture',
            'The full refund lost its native status or original invoice marker.');
        $GLOBALS['wpdb']->refunds[$first['id']]['reconcile_after'] = time() - 1;
        self::check(Reconciler::run(1) === ['checked' => 1, 'busy' => 0, 'errors' => 0], 'Scheduled recovery did not pick up the interrupted refund.');
        $completed = RefundIntentStore::byAttempt(1, 'full');
        self::check($completed['state'] === 'completed' && count(RefundFixture::$notes) === 2 && RefundFixture::$nativeWrites === 2,
            'Refund recovery did not keep a single status transition and refund note.');
        self::check(self::state(RefundFixture::call('request'), 'full') === 'completed' && count(RefundFixture::$posts) === 1,
            'A terminal request did not return the same intent.');

        RefundFixture::reset('10.000000000000000001');
        self::check(self::state(RefundFixture::call('request', 'overpayment'), 'overpayment') === 'pending', 'The overpayment intent was not saved.');
        $before = count(RefundFixture::$posts);
        self::check(self::state(RefundFixture::call('request', 'full'), 'full') === 'reserved' && count(RefundFixture::$posts) === $before,
            'A second mode made a transfer while the earlier one remained pending.');
        RefundFixture::terminal('overpayment', 'completed');
        self::check(self::state(RefundFixture::call('poll', 'overpayment'), 'overpayment') === 'completed', 'Verified excess refund did not reconcile.');
        $over = RefundIntentStore::byAttempt(1, 'overpayment');
        self::check($over['expected_amount'] === '1.000000000000000001' && $over['mode'] === 'overpayment'
            && Donation::find(51)->status->isComplete() && Donation::find(51)->gatewayTransactionId === 'inv_fixture'
            && RefundFixture::$nativeWrites === 1 && count(RefundFixture::$notes) === 2, 'Excess was rounded or changed the original donation.');
        self::check(self::state(RefundFixture::call('request'), 'full') === 'pending' && RefundIntentStore::byAttempt(1, 'full')['expected_amount'] === '9',
            'A later full refund did not deduct the completed excess.');
        echo "PASS accepted-before-timeout, disabled GET-only recovery, note repair, separate excess and exact 18-decimal amounts\n";
    }

    public static function testFirstRefundSnapshotPinsRecipient(bool $losePost): void
    {
        RefundFixture::reset();
        RefundFixture::$losePostResponse = $losePost;
        if ($losePost) {
            self::check(self::state(RefundFixture::call('request'), 'full') === 'unknown', 'The fixture did not lose the POST response.');
            RefundFixture::terminal('full', 'completed');
        }
        RefundFixture::$readOverride = [
            'first_sender' => 'different-sender', 'destination_address' => 'different-sender',
            'status' => 'completed', 'is_final' => true, 'completed_at' => time(),
        ];

        $response = RefundFixture::call($losePost ? 'poll' : 'request');
        $intent = RefundIntentStore::byAttempt(1, 'full');
        $recipient = hash_hmac('sha256', 'paymos-givewp-refund|sandbox|SOL|first-original-sender', wp_salt('auth'));
        $source = $losePost ? 'LIST' : 'POST';
        self::check(self::state($response, 'full') === 'review' && $intent['review_reason'] === 'refund_evidence_mismatch'
            && Donation::find(51)->status->isComplete() && count(RefundFixture::$notes) === 1
            && RefundFixture::$nativeWrites === 1, $source . ' recipient mismatch projected a native refund.');
        self::check($intent['withdrawal_id'] === 'wdr_full' && $intent['recipient_hash'] === $recipient
            && RefundFixture::$detailPins === [['wdr_full', $recipient]], $source . ' evidence was not saved before the first detail GET.');

        RefundFixture::$readOverride = [];
        RefundFixture::terminal('full', 'completed');
        $before = count(RefundFixture::$requests);
        self::check(self::state(RefundFixture::call('request'), 'full') === 'completed'
            && array_slice(RefundFixture::$requests, $before) === ['GET /v1/invoices/inv_fixture', 'GET /v1/refunds/wdr_full'],
            $source . ' mismatch did not recover through the same pinned operation.');
        $completed = RefundIntentStore::byAttempt(1, 'full');
        self::check($completed['id'] === $intent['id'] && $completed['external_order_id'] === $intent['external_order_id']
            && $completed['recipient_hash'] === $recipient && count(RefundFixture::$posts) === 1
            && Donation::find(51)->status->isRefunded() && Donation::find(51)->gatewayTransactionId === 'inv_fixture'
            && count(RefundFixture::$notes) === 2 && RefundFixture::$nativeWrites === 2
            && self::state(RefundFixture::call('poll'), 'full') === 'completed', $source . ' recovery did not preserve exactly one refund and note.');

        echo "PASS first $source snapshot is pinned before detail, recipient mismatch is recoverable with one POST\n";
    }

    public static function testMissingOrConflictingFirstSnapshotNeedsList(): void
    {
        RefundFixture::reset();
        RefundFixture::$postSnapshot = static function (array $wire): array {
            return ['withdrawal_id' => $wire['withdrawal_id']];
        };
        self::check(self::state(RefundFixture::call('request'), 'full') === 'unknown' && RefundFixture::$detailPins === [],
            'An ID-only POST response authorized a detail read.');
        $intent = RefundIntentStore::byAttempt(1, 'full');
        self::check($intent['withdrawal_id'] === null && $intent['recipient_hash'] === null, 'An incomplete POST response was pinned.');

        // Legacy rows missing a recipient must corroborate their stored ID via LIST as well.
        $GLOBALS['wpdb']->refunds[$intent['id']]['withdrawal_id'] = 'wdr_full';
        $original = RefundFixture::$remote['wdr_full'];
        RefundFixture::$remote = [];
        $before = count(RefundFixture::$requests);
        self::check(self::state(RefundFixture::call('request'), 'full') === 'unknown'
            && array_slice(RefundFixture::$requests, $before) === ['GET /v1/invoices/inv_fixture', 'GET /v1/refunds'],
            'An absent LIST match trusted a legacy ID without a recipient pin.');

        foreach (['withdrawal_id' => 'wdr_foreign', 'amount' => '8.999999999999999999'] as $field => $value) {
            RefundFixture::$remote['wdr_full'] = array_replace($original, [$field => $value]);
            self::check(self::state(RefundFixture::call('poll'), 'full') === 'review' && RefundFixture::$detailPins === [],
                'A first LIST item with a conflicting ' . $field . ' authorized a detail read.');
            $row = RefundIntentStore::byAttempt(1, 'full');
            self::check($row['withdrawal_id'] === 'wdr_full' && $row['recipient_hash'] === null, 'A conflicting LIST replaced an existing pin.');
        }

        RefundFixture::$remote['wdr_full'] = $original;
        RefundFixture::terminal('full', 'completed');
        self::check(self::state(RefundFixture::call('poll'), 'full') === 'completed'
            && count(RefundFixture::$posts) === 1 && Donation::find(51)->status->isRefunded(),
            'A corroborated legacy row did not recover the original request.');

        RefundFixture::reset();
        RefundFixture::$postSnapshot = static function (array $wire): array {
            return array_replace($wire, ['external_order_id' => 'unrelated_intent']);
        };
        self::check(self::state(RefundFixture::call('request'), 'full') === 'review' && RefundFixture::$detailPins === []
            && RefundIntentStore::byAttempt(1, 'full')['withdrawal_id'] === null && Donation::find(51)->status->isComplete(),
            'A valid POST snapshot belonging to another operation authorized a detail read.');
        self::check(self::state(RefundFixture::call('request'), 'full') === 'pending' && count(RefundFixture::$posts) === 1,
            'Retry did not recover the original operation through LIST.');

        echo "PASS incomplete or conflicting first evidence and legacy ID-only rows require a matching LIST before detail\n";
    }

    public static function testCrossBindingAndCapability(): void
    {
        RefundFixture::reset();
        RefundFixture::$canEdit = false;
        self::denied(RefundFixture::call('request'), 403);
        RefundFixture::$canEdit = true;
        self::denied(RefundFixture::call('request', 'full', [], 'forged'), 403);
        self::denied(RefundFixture::call('request', 'full', ['blog_id' => 8]), 400);
        self::denied(RefundFixture::call('request', 'partial'), 400);
        self::denied(RefundFixture::call('request', 'full', ['destination_address' => 'forged']), 400);
        self::denied(RefundFixture::call('request', 'full', ['amount' => '1']), 400);
        RefundFixture::$native->donorId = 88;
        self::denied(RefundFixture::call('request'), 409);
        self::check(RefundFixture::$requests === [] && $GLOBALS['wpdb']->refunds === [], 'A rejected request reached the API or reserved a refund.');

        RefundFixture::reset();
        $GLOBALS['wpdb']->projections = [];
        self::denied(RefundFixture::call('request'), 409);
        self::check(RefundFixture::$requests === [], 'A manually completed native state was enough to issue a refund.');

        foreach ([
            ['project_id' => 'prj_foreign'], ['invoice_id' => 'inv_foreign'], ['is_test' => false],
            ['mode' => 'overpayment'], ['type' => 'withdrawal'], ['external_order_id' => 'foreign_operation'],
            ['amount' => '8.999999999999999999'], ['currency' => 'USDC'], ['network' => 'TRC20'],
            ['first_sender' => 'other-sender', 'destination_address' => 'other-sender'],
            ['destination_address' => 'different'], ['is_final' => false], ['completed_at' => null],
        ] as $mutation) {
            RefundFixture::reset();
            self::check(self::state(RefundFixture::call('request'), 'full') === 'pending', 'Fixture refund did not start pending.');
            RefundFixture::terminal('full', 'completed');
            RefundFixture::$readOverride = $mutation;
            self::check(self::state(RefundFixture::call('poll'), 'full') === 'review'
                && Donation::find(51)->status->isComplete() && count(RefundFixture::$notes) === 1
                && count(RefundFixture::$posts) === 1, 'A foreign or incomplete snapshot projected a native refund.');
        }

        foreach (['failed', 'cancelled'] as $terminal) {
            RefundFixture::reset();
            RefundFixture::call('request');
            RefundFixture::terminal('full', $terminal);
            self::check(self::state(RefundFixture::call('poll'), 'full') === $terminal
                && self::state(RefundFixture::call('request'), 'full') === $terminal
                && count(RefundFixture::$posts) === 1 && Donation::find(51)->status->isComplete(),
                'A definitive failed/cancelled payout retried or changed the original donation.');
        }
        echo "PASS native permission and nonce, strict bound shape, recipient pin, terminal failures and no second transfer\n";
    }

    public static function testFinalPaidWithinServerTolerance(): void
    {
        // The server finalised 9.95 of 10 as paid, so the donation completed. Paymos refunds only an invoice
        // whose received amount covers the expected one, so it refuses both modes for this donation.
        foreach (['full', 'overpayment'] as $mode) {
            RefundFixture::reset('9.95', '10', 'paid');
            RefundFixture::$invoice['payment']['remaining'] = '0.05';
            $response = RefundFixture::call('request', $mode);
            self::check(self::state($response, $mode) === 'reserved'
                && RefundIntentStore::byAttempt(1, $mode)['review_reason'] === 'refund_amount_unavailable'
                && RefundFixture::$posts === [], 'A refund the server always refuses was sent for a tolerance payment: ' . $mode);
            self::check($response->data['modes'][$mode]['can_request'] === false
                && RefundFixture::call('status')->data['modes'][$mode]['can_request'] === false,
                'A refund the server always refuses was offered again: ' . $mode);
        }

        foreach (['late' => ['paid_late_at' => time()], 'underpaid' => ['status' => 'underpaid']] as $case => $change) {
            RefundFixture::reset('9.95', '10', 'paid');
            RefundFixture::$invoice = array_replace(RefundFixture::$invoice, $change);
            self::check(self::state(RefundFixture::call('request'), 'full') === 'reserved'
                && RefundIntentStore::byAttempt(1, 'full')['review_reason'] === 'payment_evidence_mismatch'
                && RefundFixture::$posts === [], 'A refund was sent for unsettled evidence: ' . $case);
        }

        echo "PASS final paid inside the server tolerance offers no refund in either mode; no late or underpaid refund\n";
    }

    public static function testAcceptedRefundRereadAfterTemporaryDenial(): void
    {
        foreach ([401, 403, 404] as $status) {
            RefundFixture::reset();
            RefundFixture::$detailDenials = [$status];
            // Paymos accepted the POST; only the detail read right after it is denied, once.
            self::check(self::state(RefundFixture::call('request'), 'full') === 'unknown' && count(RefundFixture::$posts) === 1,
                'A temporary ' . $status . ' after an accepted refund ended the operation.');
            $intent = RefundIntentStore::byAttempt(1, 'full');
            self::check($intent['withdrawal_id'] === 'wdr_full' && $intent['reconcile_after'] !== null,
                'The accepted refund was not kept for another read after a ' . $status . '.');

            RefundFixture::terminal('full', 'completed');
            $GLOBALS['wpdb']->refunds[$intent['id']]['reconcile_after'] = time() - 1;
            self::check(Reconciler::run(1) === ['checked' => 1, 'busy' => 0, 'errors' => 0],
                'Scheduled recovery did not read the accepted refund again after a ' . $status . '.');
            self::check(RefundIntentStore::byAttempt(1, 'full')['state'] === 'completed' && Donation::find(51)->status->isRefunded()
                && count(RefundFixture::$posts) === 1, 'The accepted refund was not recorded after a ' . $status . ' read.');
        }

        echo "PASS an accepted refund whose first read gets 401/403/404 is read again and recorded with one POST\n";
    }

    public static function testNearPrecisionRefundHistory(): void
    {
        RefundFixture::reset('10.000000000000000000000001');
        self::check(self::state(RefundFixture::call('request', 'overpayment'), 'overpayment') === 'pending'
            && RefundIntentStore::byAttempt(1, 'overpayment')['expected_amount'] === '1.000000000000000000000001',
            'The 24-decimal excess was not computed exactly.');
        RefundFixture::terminal('overpayment', 'completed');
        self::check(self::state(RefundFixture::call('poll', 'overpayment'), 'overpayment') === 'completed', 'The 24-decimal excess refund did not complete.');
        self::check(self::state(RefundFixture::call('request'), 'full') === 'pending'
            && RefundIntentStore::byAttempt(1, 'full')['expected_amount'] === '9',
            'A completed 24-decimal refund was not subtracted exactly from the paid amount.');

        echo "PASS NEAR 24-decimal paid amount and refund history are subtracted exactly\n";
    }

    public static function testExternalRefundHistoryUtf16Boundary(): void
    {
        $validRefs = [
            'ascii_128' => str_repeat('a', 128),
            'ascii_129' => str_repeat('b', 129),
            'ascii_200' => str_repeat('c', 200),
            'cyrillic_150' => str_repeat("\xD1\x8F", 150),
            'cyrillic_200' => str_repeat("\xD1\x8F", 200),
            'astral_100' => str_repeat("\xF0\x9F\x98\x80", 100),
        ];

        // Non-blocking external history entries (failed full / completed overpayment) with valid <=200 UTF-16 keys
        // must be accepted by RefundProjector so a subsequent full refund can proceed.
        foreach ($validRefs as $label => $ref) {
            RefundFixture::reset('10', '9');
            RefundFixture::$remote['wdr_ext_failed'] = [
                'withdrawal_id' => 'wdr_ext_failed', 'invoice_id' => 'inv_fixture', 'project_id' => 'prj_fixture',
                'type' => 'refund', 'mode' => 'full', 'external_order_id' => $ref, 'status' => 'failed',
                'is_final' => true, 'is_test' => true, 'amount' => '10', 'currency' => 'USDT', 'network' => 'SOL',
                'first_sender' => 'first-original-sender', 'destination_address' => 'first-original-sender',
            ];
            self::check(self::state(RefundFixture::call('request', 'full'), 'full') === 'pending',
                'RefundProjector rejected valid UTF-16 history key: ' . $label);
        }

        // Over-limit (>200 UTF-16 units) history entries must still be rejected as unreadable history.
        foreach ([
            'ascii_201' => str_repeat('a', 201),
            'cyrillic_201' => str_repeat("\xD1\x8F", 201),
            'astral_100_plus_1' => str_repeat("\xF0\x9F\x98\x80", 100) . 'a',
            'astral_101' => str_repeat("\xF0\x9F\x98\x80", 101),
        ] as $label => $tooLong) {
            RefundFixture::reset('10', '9');
            RefundFixture::$remote['wdr_ext_long'] = [
                'withdrawal_id' => 'wdr_ext_long', 'invoice_id' => 'inv_fixture', 'project_id' => 'prj_fixture',
                'type' => 'refund', 'mode' => 'full', 'external_order_id' => $tooLong, 'status' => 'failed',
                'is_final' => true, 'is_test' => true, 'amount' => '10', 'currency' => 'USDT', 'network' => 'SOL',
                'first_sender' => 'first-original-sender', 'destination_address' => 'first-original-sender',
            ];
            self::check(self::state(RefundFixture::call('request', 'full'), 'full') === 'reserved'
                && RefundFixture::$posts === [],
                'RefundProjector accepted over-limit UTF-16 history key: ' . $label);
        }

        echo "PASS BUG-1269 refund external_order_id 200 UTF-16 code unit boundaries in RefundProjector\n";
    }

    public static function check(bool $condition, string $failure): void
    {
        if (!$condition) {
            throw new LogicException($failure);
        }
    }

    private static function state($response, string $mode): string
    {
        self::check($response instanceof WP_REST_Response, $response instanceof WP_Error ? $response->message : 'Unexpected response.');

        return $response->data['modes'][$mode]['state'];
    }

    private static function denied($response, int $status): void
    {
        self::check($response instanceof WP_Error && $response->data['status'] === $status, 'A denied refund returned the wrong result.');
    }
}

final class RefundFixture
{
    public static $options = [];
    public static $native;
    public static $invoice;
    public static $notes = [];
    public static $nativeWrites = 0;
    public static $canEdit = true;
    public static $losePostResponse = false;
    public static $noteFault = false;
    public static $posts = [];
    public static $remote = [];
    public static $requests = [];
    public static $readOverride = [];
    public static $detailPins = [];
    public static $detailDenials = [];
    public static $postSnapshot;

    public static function reset(string $paid = '9', string $expected = '9', ?string $status = null): void
    {
        $GLOBALS['wpdb'] = new GivewpRefundDb();
        self::$options = [
            'home' => 'https://example.invalid/shop',
            'paymos_givewp_schema' => '1',
            'paymos_givewp_settlement_schema' => '1',
            'paymos_givewp_refund_schema' => '1',
        ];
        self::$nativeWrites = 0;
        self::$losePostResponse = self::$noteFault = false;
        self::$canEdit = true;
        self::$notes = self::$posts = self::$remote = self::$requests = self::$readOverride = [];
        self::$detailPins = self::$detailDenials = [];
        self::$postSnapshot = null;
        $connection = ConnectionStore::lock(7);
        GivewpRefund::check($connection->acquire(), 'Could not lock the fixture connection.');
        try {
            $binding = ConnectionStore::connect(7, self::$options['home'], 'sandbox', ['sandbox' => [
                'api_key' => 'pk_test_fixture', 'api_secret' => 'sk_test_fixture', 'project_id' => 'prj_fixture',
                'webhook_secret' => 'whsec_fixture', 'capabilities' => ['invoice_refunds_v1'], 'refund_contract_version' => 1,
            ]], $connection);
        } finally {
            $connection->release();
        }

        $attempt = $binding + [
            'id' => 1, 'site' => 7, 'form_id' => 33, 'form_version' => 3, 'donation_id' => 51, 'donor_id' => 77,
            'principal_id' => 0, 'native_mode' => 'test', 'amount' => '12.68', 'currency' => 'USD',
            'purchase_hash' => hash_hmac('sha256', 'paymos-givewp-v1|native-receipt|test-native-receipt', wp_salt('nonce')),
            'external_order_id' => 'givewp_invoice', 'invoice_id' => 'inv_fixture', 'status' => 'open', 'remote_status' => 'paid',
            'payment_url' => 'https://checkout.paymos.io/invoice/inv_fixture', 'reconcile_after' => 0,
            'review_reason' => null, 'created_at' => time(), 'updated_at' => time(),
        ];
        $GLOBALS['wpdb']->attempt = $attempt;
        self::$native = new Donation([
            'id' => 51, 'formId' => 33, 'donorId' => 77, 'purchaseKey' => 'test-native-receipt',
            'gatewayId' => 'paymos', 'status' => DonationStatus::PENDING(), 'type' => DonationType::SINGLE(),
            'amount' => new Money('1268', 'USD'), 'firstName' => 'Fixture', 'email' => 'fixture@example.invalid',
        ]);
        self::$invoice = [
            'invoice_id' => 'inv_fixture', 'project_id' => 'prj_fixture', 'is_test' => true, 'is_final' => true,
            'status' => $status ?? ($paid === $expected ? 'paid' : 'paid_over'),
            'order' => ['external_id' => 'givewp_invoice', 'amount' => '12.68', 'currency' => 'USD'],
            'payment_url' => 'https://checkout.paymos.io/invoice/inv_fixture',
            'payment' => ['currency' => 'USDT', 'network' => 'SOL', 'chain_id' => -2, 'expected' => $expected, 'paid' => $paid, 'remaining' => '0'],
        ];

        $proof = Settlement::proofBinding($attempt) + ['coverage' => [
            'currency' => 'USDT', 'network' => 'SOL', 'chainId' => -2, 'contract' => null, 'expected' => $expected,
        ]];
        $lock = AttemptStore::lock(51);
        GivewpRefund::check($lock->acquire(), 'Could not lock the fixture donation.');
        try {
            GivewpRefund::check(Settlement::project($attempt, $proof, 'paid', $lock) === 'paid', 'Could not seed the native Paymos paid marker.');
        } finally {
            $lock->release();
        }
    }

    public static function call(string $action, string $mode = 'full', array $override = [], string $nonce = 'valid')
    {
        $body = ['blog_id' => 7, 'donation_id' => 51];
        if ($action !== 'status') {
            $body['mode'] = $mode;
        }

        return RefundAdmin::dispatch(new WP_REST_Request(array_replace($body, $override), $nonce), $action);
    }

    public static function terminal(string $mode, string $status): void
    {
        $id = 'wdr_' . $mode;
        self::$remote[$id]['status'] = $status;
        self::$remote[$id]['is_final'] = true;
        if ($status === 'completed') {
            self::$remote[$id]['completed_at'] = time();
        }
    }

    public static function create(array $body)
    {
        $mode = $body['mode'];
        $intent = RefundIntentStore::byAttempt(1, $mode);
        GivewpRefund::check(array_keys($body) === ['invoice_id', 'mode', 'external_order_id'] && $intent['state'] === 'unknown'
            && $intent['external_order_id'] === $body['external_order_id'], 'A payout was sent before its exact local intent.');
        self::$posts[] = $body;
        $id = 'wdr_' . $mode;
        // Paymos pays out exactly the amount the plugin computed and saved before the POST.
        $amount = $intent['expected_amount'];
        self::$remote[$id] = [
            'withdrawal_id' => $id, 'invoice_id' => $body['invoice_id'], 'mode' => $mode,
            'external_order_id' => $body['external_order_id'], 'project_id' => 'prj_fixture', 'type' => 'refund',
            'is_test' => true, 'is_final' => false, 'status' => 'created', 'currency' => 'USDT', 'network' => 'SOL',
            'amount' => $amount, 'first_sender' => 'first-original-sender', 'destination_address' => 'first-original-sender',
        ];
        if (self::$losePostResponse) {
            self::$losePostResponse = false;

            return new WP_Error('transport', 'Fixture transport timed out.', []);
        }

        $wire = self::$remote[$id];

        return ['body' => json_encode(self::$postSnapshot === null ? $wire : (self::$postSnapshot)($wire))];
    }
}

final class GivewpRefundDb
{
    public $prefix = 'wp_7_';
    public $last_error = '';
    public $attempt;
    public $projections = [];
    public $refunds = [];
    private $queries = [];
    private $locks = [];

    public function prepare(string $sql, ...$values): string
    {
        $key = 'prepared_' . count($this->queries);
        $this->queries[$key] = [$sql, $values];

        return $key;
    }

    public function get_row(string $query, string $format): ?array
    {
        [$sql, $args] = $this->queries[$query];
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
        if (strpos($sql, 'WHERE attempt_id = %d AND mode') !== false) {
            foreach ($this->refunds as $row) {
                if ($row['attempt_id'] === $args[1] && $row['mode'] === $args[2]) {
                    return $row;
                }
            }

            return null;
        }
        if (strpos($sql, 'WHERE id = %d') !== false && $args[0] === RefundIntentStore::table()) {
            return $this->refunds[$args[1]] ?? null;
        }
        GivewpRefund::check($sql === 'SELECT * FROM %i WHERE %i = %s' && $args[0] === AttemptStore::table(), 'Unexpected fixture query.');
        [, $column, $value] = $args;

        return (string) $this->attempt[$column] === (string) $value ? $this->attempt : null;
    }

    public function get_var(string $query): ?string
    {
        [$sql, $args] = $this->queries[$query];
        if (strpos($sql, 'GET_LOCK(') !== false) {
            if (isset($this->locks[$args[0]])) {
                return '0';
            }
            $this->locks[$args[0]] = true;

            return '47';
        }
        if (strpos($sql, 'IS_USED_LOCK(') !== false) {
            $held = isset($this->locks[$args[1]]);
            if ($held && strpos($sql, 'RELEASE_LOCK(') !== false) {
                unset($this->locks[$args[1]]);
            }

            return $held ? '1' : '0';
        }
        GivewpRefund::check(strpos($sql, 'BINARY RIGHT(comment_content') !== false, 'Unexpected fixture scalar query.');
        foreach (RefundFixture::$notes as $note) {
            if ($note->donationId === $args[1] && substr($note->content, -$args[3]) === $args[4]) {
                return (string) $note->id;
            }
        }

        return null;
    }

    public function query(string $query): int
    {
        GivewpRefund::check(strpos($this->queries[$query][0], 'DELETE FROM') === 0, 'Unexpected raw fixture write.');

        return 0;
    }

    public function get_results(string $query, string $format): array
    {
        [, $args] = $this->queries[$query];
        $rows = $args[0] === RefundIntentStore::table() ? $this->refunds : [$this->attempt];
        $due = array_filter($rows, static function (array $row) use ($args): bool {
            return $row['reconcile_after'] !== null && $row['reconcile_after'] <= $args[1];
        });

        return array_slice(array_values($due), 0, $args[2]);
    }

    public function insert(string $table, array $row): int
    {
        if ($table === EventStore::projectionsTable()) {
            $this->projections[$row['projection_id']] = $row;
        } else {
            GivewpRefund::check($table === RefundIntentStore::table(), 'Unexpected fixture table.');
            $id = count($this->refunds) + 1;
            $this->refunds[$id] = $row + [
                'id' => $id, 'withdrawal_id' => null, 'expected_amount' => null, 'currency' => null, 'network' => null,
                'recipient_hash' => null, 'submitted_at' => null, 'reconcile_after' => null, 'review_reason' => null,
            ];
        }

        return 1;
    }

    public function update(string $table, array $values, array $where): int
    {
        if ($table === AttemptStore::table()) {
            $row = &$this->attempt;
        } elseif ($table === EventStore::projectionsTable()) {
            $row = &$this->projections[$where['projection_id']];
        } else {
            GivewpRefund::check($table === RefundIntentStore::table(), 'Unexpected fixture update.');
            $row = &$this->refunds[$where['id']];
        }
        foreach ($where as $key => $value) {
            if ($row[$key] !== $value) {
                return 0;
            }
        }
        $changed = $values + $row;
        if (array_intersect_key($changed, $values) === array_intersect_key($row, $values)) {
            return 0;
        }
        $row = $changed;

        return 1;
    }
}

final class WP_REST_Request
{
    private $params;
    private $nonce;

    public function __construct(array $params, string $nonce)
    {
        $this->params = $params;
        $this->nonce = $nonce;
    }

    public function get_header(string $key): ?string { return $key === 'x-wp-nonce' ? $this->nonce : null; }
    public function get_json_params(): array { return $this->params; }
}

final class WP_REST_Response
{
    public $data;
    public function __construct(array $data) { $this->data = $data; }
    public function header(string $key, string $value): void {}
}

final class WP_Error
{
    public $code;
    public $message;
    public $data;
    public function __construct(string $code, string $message, array $data)
    {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }
}

function wp_safe_remote_request(string $url, array $options)
{
    GivewpRefund::check(parse_url($url, PHP_URL_HOST) === 'api.paymos.io' && $options['redirection'] === 0
        && strpos($options['headers']['Authorization'], 'pk_test_fixture') !== false, 'The refund used a wrong API generation or origin.');
    $method = $options['method'];
    $path = parse_url($url, PHP_URL_PATH);
    RefundFixture::$requests[] = $method . ' ' . $path;
    if ($method === 'POST' && $path === '/v1/refunds') {
        return RefundFixture::create(json_decode($options['body'], true));
    }
    GivewpRefund::check($method === 'GET', 'An unexpected remote mutation occurred.');
    if ($path === '/v1/invoices/inv_fixture') {
        $body = RefundFixture::$invoice;
    } elseif ($path === '/v1/refunds') {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        GivewpRefund::check($query === ['invoice_id' => 'inv_fixture', 'limit' => '20'], 'Recovery queried an unbound refund list.');
        $body = ['items' => array_values(RefundFixture::$remote), 'next_cursor' => null];
    } else {
        $id = basename($path);
        GivewpRefund::check(strpos($path, '/v1/refunds/wdr_') === 0 && isset(RefundFixture::$remote[$id]), 'An unrelated refund was read.');
        if (RefundFixture::$detailDenials !== []) {
            $status = array_shift(RefundFixture::$detailDenials);

            return ['response' => ['code' => $status], 'body' => json_encode(['status' => $status, 'code' => 'fixture_denied'])];
        }
        $intent = RefundIntentStore::byAttempt(1, RefundFixture::$remote[$id]['mode']);
        RefundFixture::$detailPins[] = [$intent['withdrawal_id'], $intent['recipient_hash']];
        $body = array_replace(RefundFixture::$remote[$id], RefundFixture::$readOverride);
    }

    return ['body' => json_encode($body)];
}

function add_action(string $tag, callable $callback, int $priority = 10, int $arguments = 1): void
{
    $GLOBALS['refundHooks'][$tag][$priority][] = [$callback, $arguments];
}

function add_filter(string $tag, callable $callback, int $priority = 10, int $arguments = 1): void
{
    add_action($tag, $callback, $priority, $arguments);
}

function do_action(string $tag, ...$args): void
{
    $callbacks = $GLOBALS['refundHooks'][$tag] ?? [];
    ksort($callbacks);
    foreach ($callbacks as $group) {
        foreach ($group as [$callback, $count]) {
            $callback(...array_slice($args, 0, $count));
        }
    }
}

function get_option(string $key, $default = false) { return RefundFixture::$options[$key] ?? $default; }
function update_option(string $key, $value, bool $autoload): bool { RefundFixture::$options[$key] = $value; return true; }
function get_current_blog_id(): int { return 7; }
function get_current_user_id(): int { return 61; }
function current_user_can(string $cap): bool { return RefundFixture::$canEdit && $cap === 'edit_give_payments'; }
function wp_get_session_token(): string { return 'local-administrator-session'; }
function wp_verify_nonce(string $value, string $action): bool { return $value === 'valid' && $action === 'wp_rest'; }
function wp_salt(string $kind): string { return 'fixture-salt-' . $kind; }
function wp_parse_url(string $url, int $component = -1) { return parse_url($url, $component); }
function give_is_test_mode(): bool { return true; }
function give_get_currencies_list(): array { return []; }
function give_get_ip(): string { return '192.0.2.22'; }
function __(string $value, string $domain = ''): string { return $value; }
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code(array $response): int { return $response['response']['code'] ?? 200; }
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
                                $note->id = count(RefundFixture::$notes) + 1;
                                RefundFixture::$notes[] = clone $note;
                                if (RefundFixture::$noteFault) {
                                    RefundFixture::$noteFault = false;
                                    throw new RuntimeException('Native note committed before fixture interruption.');
                                }
                            }
                        };
                    }

                    public function getById(int $id): ?Donation
                    {
                        return $id === 51 ? clone RefundFixture::$native : null;
                    }

                    public function update(Donation $donation): void
                    {
                        do_action('givewp_donation_updating', $donation);
                        RefundFixture::$native = new Donation($donation->getAttributes());
                        RefundFixture::$nativeWrites++;
                        do_action('givewp_donation_updated', $donation);
                    }
                };
                $this->form_meta = new class {
                    public function get_meta(int $form, string $name, bool $single): bool { return $form === 33; }
                };
            }

            public function singletonIf(...$args): void {}
        };
    }

    return $class === null ? $container : new $class();
}

$path = $argv[1] ?? '';
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
GivewpRefund::testPendingResumeExactIntent();
GivewpRefund::testFirstRefundSnapshotPinsRecipient(false);
GivewpRefund::testFirstRefundSnapshotPinsRecipient(true);
GivewpRefund::testMissingOrConflictingFirstSnapshotNeedsList();
GivewpRefund::testCrossBindingAndCapability();
GivewpRefund::testFinalPaidWithinServerTolerance();
GivewpRefund::testAcceptedRefundRereadAfterTemporaryDenial();
GivewpRefund::testNearPrecisionRefundHistory();
GivewpRefund::testExternalRefundHistoryUtf16Boundary();
