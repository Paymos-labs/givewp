<?php

declare(strict_types=1);

use Give\Donations\Models\Donation;
use PaymosGivewp\AttemptStore;
use PaymosGivewp\ConnectionStore;
use PaymosGivewp\EventStore;
use PaymosGivewp\Reconciler;

require_once __DIR__ . '/WebhookTest.php';

final class GivewpReconcile
{
    public static function testCrashWindows(): void
    {
        foreach ([
            'before_native' => ['awaiting_native', 0, 0, 0, 1],
            'after_native' => ['awaiting_native', 1, 0, 1, 0],
            'after_note' => ['note_pending', 1, 1, 0, 1],
        ] as $fault => [$stage, $writes, $notes, $receiptUnknown, $effects]) {
            GivewpSettlementFixture::reset();
            GivewpSettlementFixture::$fault = $fault;
            $event = GivewpSettlementFixture::event();
            self::check(GivewpSettlementFixture::deliver($event)->get_status() === 503, 'The interrupted event was acknowledged: ' . $fault);
            $marker = EventStore::projection(1, 'paid');
            self::check($marker['stage'] === $stage && GivewpSettlementFixture::$writes === $writes
                && count(GivewpSettlementFixture::$notes) === $notes, 'The interruption was not recorded in its actual stage: ' . $fault);

            $run = Reconciler::run(1);
            $row = AttemptStore::byId(1);
            $marker = EventStore::projection(1, 'paid');
            self::check($run === ['checked' => 1, 'busy' => 0, 'errors' => 0], 'The bounded worker did not recover the pending result.');
            self::check($row['status'] === 'verified_paid' && Donation::find(51)->status->isComplete() && $marker['stage'] === 'done', 'Recovery did not commit verified native payment.');
            self::check(GivewpSettlementFixture::$writes === 1 && GivewpSettlementFixture::$effects === $effects
                && count(GivewpSettlementFixture::$notes) === 1, 'Recovery repeated the native transition, a native effect or the note.');
            self::check((int) $marker['receipt_delivery_unknown'] === $receiptUnknown
                && $row['review_reason'] === ($receiptUnknown === 1 ? 'receipt_delivery_unknown' : null), 'Receipt delivery uncertainty was lost.');
            self::check(GivewpSettlementFixture::deliver($event)->get_status() === 200
                && GivewpSettlementFixture::deliver($event)->data['result'] === 'duplicate'
                && Reconciler::run(1)['checked'] === 0, 'Recovered work remained uncommitted or was scheduled twice.');
        }

        echo "PASS interrupted native commit and note boundaries without repeated status or receipt effects\n";
    }

    public static function testMissedWebhookUsesOriginalGeneration(): void
    {
        $original = GivewpSettlementFixture::reset(33, true);
        $siteLock = ConnectionStore::lock(7);
        self::check($siteLock->acquire(), 'The fixture connection mutex is unavailable.');
        try {
            ConnectionStore::disable(7, $siteLock);
        } finally {
            $siteLock->release();
        }

        $run = Reconciler::run(1);
        $row = AttemptStore::byId(1);
        self::check($run['checked'] === 1 && $run['errors'] === 0, 'The missed callback was not reconciled.');
        self::check($row['status'] === 'verified_paid' && $row['invoice_id'] === 'inv_fixture'
            && $row['generation'] === $original['generation'], 'The ambiguous invoice was not resolved with the original connection.');
        self::check(GivewpSettlementFixture::$requests === [
            '/v1/invoices?external_order_id=givewp_fixture&limit=2&project_id=prj_fixture',
            '/v1/invoices/inv_fixture',
            '/v1/refunds?invoice_id=inv_fixture&limit=20',
        ], 'Missed delivery did not use only exact read-only recovery.');
        self::check(GivewpSettlementFixture::$writes === 1 && count(GivewpSettlementFixture::$notes) === 1, 'Missed delivery produced the wrong native effect count.');
        echo "PASS bounded missed delivery recovery after disable using only the original scoped API reads\n";
    }

    public static function testMissedTerminalPaymentNeedsReview(): void
    {
        foreach ([
            ['expired', '4', null, 'expired', 'terminal_funded'],
            ['cancelled', '4', null, 'cancelled', 'terminal_funded'],
            ['underpaid', '4', null, 'open', 'terminal_funded'],
            ['expired', '0', time(), 'expired', 'late_payment'],
            ['expired', '0', null, 'expired', null],
        ] as [$status, $paid, $lateAt, $expectedStatus, $expectedReason]) {
            GivewpSettlementFixture::reset(33);
            GivewpSettlementFixture::$invoice['status'] = $status;
            GivewpSettlementFixture::$invoice['paid_late_at'] = $lateAt;
            GivewpSettlementFixture::$invoice['payment']['paid'] = $paid;
            GivewpSettlementFixture::$invoice['payment']['remaining'] = '5';

            $run = Reconciler::run(1);
            $row = AttemptStore::byId(1);
            self::check($run === ['checked' => 1, 'busy' => 0, 'errors' => 0]
                && GivewpSettlementFixture::$requests === ['/v1/invoices/inv_fixture'], 'Terminal recovery did not use one exact GET.');
            self::check($row['status'] === $expectedStatus && $row['review_reason'] === $expectedReason
                && $row['reconcile_after'] === null, 'Missed terminal event lost its review outcome: ' . $status . '/' . $expectedReason);
            self::check(Donation::find(51)->status->isPending() && GivewpSettlementFixture::$writes === 0
                && GivewpSettlementFixture::$notes === [] && EventStore::projection(1, 'paid') === null
                && Reconciler::run(1)['checked'] === 0, 'Terminal review projected payment or remained scheduled.');
        }

        echo "PASS due exact GET records terminal funding or late payment and leaves empty final invoices resolved\n";
    }

    public static function testFinalPaidTrustsServerTolerance(): void
    {
        foreach ([
            'tolerance' => ['paid', '10', '9.95', '0.05'],
            'tolerance_over' => ['paid_over', '10', '9.95', '0.05'],
            'near' => ['paid', '1.000000000000000000000001', '1.000000000000000000000001', '0'],
        ] as $case => [$status, $expected, $paid, $remaining]) {
            GivewpSettlementFixture::reset();
            GivewpSettlementFixture::$invoice['status'] = $status;
            GivewpSettlementFixture::$invoice['payment']['expected'] = $expected;
            GivewpSettlementFixture::$invoice['payment']['paid'] = $paid;
            GivewpSettlementFixture::$invoice['payment']['remaining'] = $remaining;

            $run = Reconciler::run(1);
            $row = AttemptStore::byId(1);
            self::check($run === ['checked' => 1, 'busy' => 0, 'errors' => 0], 'The final paid invoice was not reconciled: ' . $case);
            self::check($row['status'] === 'verified_paid' && $row['review_reason'] === null
                && Donation::find(51)->status->isComplete() && EventStore::projection(1, 'paid')['stage'] === 'done',
                'A final paid invoice was held for review instead of completing the donation: ' . $case);
            self::check(GivewpSettlementFixture::$writes === 1 && count(GivewpSettlementFixture::$notes) === 1, 'The paid projection did not run once: ' . $case);
        }

        echo "PASS final paid and paid_over complete the donation within the server tolerance and with 24 NEAR decimals\n";
    }

    public static function testUnsettledPaidEvidenceStaysPending(): void
    {
        foreach ([
            'late' => ['paid', true, time()],
            'not_final' => ['underpaid_waiting', false, null],
            'final_flag_missing' => ['paid', false, null],
        ] as $case => [$status, $final, $lateAt]) {
            GivewpSettlementFixture::reset();
            GivewpSettlementFixture::$invoice['status'] = $status;
            GivewpSettlementFixture::$invoice['is_final'] = $final;
            GivewpSettlementFixture::$invoice['paid_late_at'] = $lateAt;
            GivewpSettlementFixture::$invoice['payment']['expected'] = '10';
            GivewpSettlementFixture::$invoice['payment']['paid'] = '9.95';
            GivewpSettlementFixture::$invoice['payment']['remaining'] = '0.05';

            Reconciler::run(1);
            self::check(AttemptStore::byId(1)['status'] !== 'verified_paid' && Donation::find(51)->status->isPending()
                && GivewpSettlementFixture::$writes === 0 && EventStore::projection(1, 'paid') === null,
                'Late or unfinished evidence completed the donation: ' . $case);
        }

        echo "PASS late payment and unfinished invoices with partial funds never complete the donation\n";
    }

    private static function check(bool $condition, string $failure): void
    {
        GivewpWebhook::check($condition, $failure);
    }
}

loadGivewpSettlementFixture($argv);
GivewpReconcile::testCrashWindows();
GivewpReconcile::testMissedWebhookUsesOriginalGeneration();
GivewpReconcile::testMissedTerminalPaymentNeedsReview();
GivewpReconcile::testFinalPaidTrustsServerTolerance();
GivewpReconcile::testUnsettledPaidEvidenceStaysPending();
