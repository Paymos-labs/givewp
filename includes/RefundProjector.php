<?php

declare(strict_types=1);

namespace PaymosGivewp;

use Give\Donations\Models\Donation;
use Give\Donations\ValueObjects\DonationStatus;
use PaymosGivewpVendor\Paymos\Client;
use PaymosGivewpVendor\Paymos\ClientConfig;
use PaymosGivewpVendor\Paymos\Exception\ApiException;
use PaymosGivewpVendor\Paymos\Http\WordPressTransport;
use PaymosGivewpVendor\Paymos\Plugin\AmountGuard;
use PaymosGivewpVendor\Paymos\Plugin\CredentialSet;
use PaymosGivewpVendor\Paymos\Plugin\VerifiedRefund;
use PaymosGivewpVendor\Paymos\Plugin\WordPressOrderLock;

defined('ABSPATH') || exit;

final class RefundProjector
{
    private const ACTIVE = ['created', 'signed', 'pending_review', 'cancelling'];
    private const FINAL = ['completed', 'failed', 'cancelled'];

    /** Only a committed Paymos payment on this blog can authorize a refund. */
    public static function nativeEligible(array $attempt, bool $starting = false): bool
    {
        if ($attempt['site'] !== (int) get_current_blog_id() || !self::id($attempt['invoice_id'], 'inv')
            || !in_array($attempt['status'], $starting ? ['verified_paid'] : ['verified_paid', 'refunded'], true)) {
            return false;
        }
        $paid = EventStore::projection($attempt['id'], 'paid');
        $donation = Donation::find($attempt['donation_id']);
        if (
            $paid === null || !in_array($paid['stage'], ['native_committed', 'note_pending', 'done'], true)
            || !$donation instanceof Donation || !Checkout::matchesDonation($attempt, $donation)
            || $donation->gatewayTransactionId !== $attempt['invoice_id']
        ) {
            return false;
        }

        if ($donation->status->getValue() === DonationStatus::COMPLETE) {
            return $attempt['status'] === 'verified_paid';
        }
        if ($starting || $donation->status->getValue() !== DonationStatus::REFUNDED) {
            return false;
        }

        // A native commit can precede the refund marker and attempt updates.
        $refunded = EventStore::projection($attempt['id'], 'refunded');

        return $refunded !== null && ($attempt['status'] === 'verified_paid' || $refunded['stage'] !== 'awaiting_native');
    }

    public static function canRequest(array $attempt): bool
    {
        if (!self::nativeEligible($attempt, true)) {
            return false;
        }
        $active = ConnectionStore::active($attempt['site']);
        $keys = ConnectionStore::forAttempt($attempt);

        return $active !== null && $active['project_id'] === $attempt['project_id'] && $active['environment'] === $attempt['environment']
            && $keys !== null && CredentialSet::hasCapability($keys, 'invoice_refunds_v1')
            && ($keys['refund_contract_version'] ?? null) === 1;
    }

    /** An explicit authorized request is the only way to issue the first POST. */
    public static function request(array $attempt, string $mode, WordPressOrderLock $lock): ?array
    {
        $lock->assertOwned();
        $attempt = AttemptStore::byId($attempt['id']);
        if ($attempt === null || !self::nativeEligible($attempt) || !in_array($mode, RefundIntentStore::MODES, true)) {
            return null;
        }
        $intent = RefundIntentStore::byAttempt($attempt['id'], $mode);
        if ($intent !== null && $intent['state'] !== 'reserved') {
            return self::poll($intent, $lock);
        }
        if (!self::canRequest($attempt)) {
            return $intent;
        }
        $intent = RefundIntentStore::reserve($attempt, $mode, $lock);
        if (!RefundIntentStore::matches($intent, $attempt)) {
            return null;
        }

        $sibling = RefundIntentStore::byAttempt($attempt['id'], $mode === 'full' ? 'overpayment' : 'full');
        if ($sibling !== null && $sibling['submitted_at'] !== null
            && in_array($sibling['state'], ['unknown', 'pending', 'projection_pending', 'review'], true)) {
            return self::record($intent, 'reserved', 'refund_in_progress', null, $lock);
        }

        $client = self::client($attempt);
        if ($client === null) {
            return self::record($intent, 'reserved', 'original_connection_unavailable', null, $lock);
        }
        try {
            $invoice = self::paidInvoice($attempt, $client);
            if ($invoice === null) {
                return self::record($intent, 'reserved', 'payment_evidence_mismatch', null, $lock);
            }
            $history = self::history($client, $attempt, $invoice);
        } catch (\Throwable $error) {
            return self::record($intent, 'reserved', 'refund_snapshot_unavailable', null, $lock);
        }
        if ($history === null) {
            return self::record($intent, 'reserved', 'refund_snapshot_invalid', null, $lock);
        }
        $amount = self::availableAmount($invoice['payment'], $history, $intent);
        if ($amount['reason'] !== null) {
            return self::record($intent, 'reserved', $amount['reason'], null, $lock);
        }

        // Preserve the exact economic request before I/O. An unknown result is only ever read back.
        $intent = RefundIntentStore::update($intent, [
            'state' => 'unknown',
            'expected_amount' => $amount['amount'],
            'currency' => $invoice['payment']['currency'],
            'network' => $invoice['payment']['network'],
            'recipient_hash' => $amount['recipient_hash'],
            'submitted_at' => time(),
            'reconcile_after' => time() + 60,
            'review_reason' => null,
        ], $lock);
        try {
            $lock->assertOwned();
            $response = $client->refunds()->create($intent['invoice_id'], $intent['mode'], $intent['external_order_id']);
        } catch (ApiException $error) {
            if (in_array($error->statusCode(), [400, 401, 403, 404, 409, 410, 422], true)
                && $error->errorCode() !== 'idempotency_conflict') {
                return self::record($intent, 'failed', 'refund_request_rejected', null, $lock);
            }

            return self::record($intent, 'unknown', 'refund_request_unknown', time() + 60, $lock);
        } catch (\RuntimeException $error) {
            return self::record($intent, 'unknown', 'refund_request_unknown', time() + 60, $lock);
        }

        $snapshot = self::verifiedRefund($response, $invoice, $attempt);
        if ($snapshot === null) {
            return self::record($intent, 'unknown', 'refund_snapshot_invalid', time() + 120, $lock);
        }
        $pinned = self::pinRefund($intent, $snapshot, $lock);

        return $pinned === null
            ? self::record($intent, 'review', 'refund_evidence_mismatch', time() + 120, $lock)
            : self::readAndApply($pinned, $attempt, $invoice, $client, $lock);
    }

    /** Poll the original invoice and refund through the retained credential generation. */
    public static function poll(array $intent, WordPressOrderLock $lock): array
    {
        $lock->assertOwned();
        $intent = RefundIntentStore::byId($intent['id']) ?? $intent;
        if (in_array($intent['state'], ['reserved', 'completed', 'failed', 'cancelled', 'retained'], true)) {
            return $intent;
        }

        $attempt = AttemptStore::byId($intent['attempt_id']);
        if ($attempt === null || !RefundIntentStore::matches($intent, $attempt)) {
            return self::record($intent, 'review', 'refund_binding_mismatch', null, $lock);
        }
        $client = self::client($attempt);
        if ($client === null) {
            return self::record($intent, 'review', 'original_connection_unavailable', time() + 300, $lock);
        }
        try {
            $invoice = self::paidInvoice($attempt, $client);
            if ($invoice === null) {
                return self::record($intent, 'review', 'payment_evidence_mismatch', time() + 120, $lock);
            }
            if ($intent['withdrawal_id'] === null || $intent['recipient_hash'] === null) {
                $history = self::history($client, $attempt, $invoice);
                if ($history === null) {
                    return self::record($intent, 'review', 'refund_snapshot_invalid', time() + 120, $lock);
                }
                $snapshot = null;
                foreach ($history as $candidate) {
                    if ($candidate->externalOrderId() === $intent['external_order_id']) {
                        $snapshot = $candidate;
                        break;
                    }
                }
                if ($snapshot === null) {
                    return self::record($intent, 'unknown', 'refund_not_found_yet', time() + 120, $lock);
                }
                $pinned = self::pinRefund($intent, $snapshot, $lock);
                if ($pinned === null) {
                    return self::record($intent, 'review', 'refund_evidence_mismatch', time() + 120, $lock);
                }
                $intent = $pinned;
            }
        } catch (\Throwable $error) {
            return self::record($intent, $intent['state'], 'refund_snapshot_unavailable', time() + 120, $lock);
        }

        return self::readAndApply($intent, $attempt, $invoice, $client, $lock);
    }

    private static function pinRefund(array $intent, VerifiedRefund $snapshot, WordPressOrderLock $lock): ?array
    {
        if (!self::matchesIntent($intent, $snapshot)) {
            return null;
        }

        // Save the first matching POST or LIST evidence before the independent detail read.
        return RefundIntentStore::update($intent, [
            'withdrawal_id' => $snapshot->withdrawalId(),
            'recipient_hash' => self::recipientHash($snapshot),
        ], $lock);
    }

    private static function matchesIntent(array $intent, VerifiedRefund $snapshot): bool
    {
        return ($intent['withdrawal_id'] === null || $intent['withdrawal_id'] === $snapshot->withdrawalId())
            && $snapshot->mode() === $intent['mode'] && $snapshot->externalOrderId() === $intent['external_order_id']
            && $snapshot->currency() === $intent['currency'] && $snapshot->network() === $intent['network']
            && AmountGuard::amountsEqual($snapshot->principalDecimal(), $intent['expected_amount'])
            && ($intent['recipient_hash'] === null || hash_equals($intent['recipient_hash'], self::recipientHash($snapshot)));
    }

    private static function readAndApply(array $intent, array $attempt, array $invoice, Client $client, WordPressOrderLock $lock): array
    {
        $id = $intent['withdrawal_id'];
        try {
            $snapshot = self::verifiedRefund($client->refunds()->get($id), $invoice, $attempt);
        } catch (\Throwable $error) {
            return self::record($intent, $intent['state'], 'refund_snapshot_unavailable', time() + 120, $lock);
        }
        if ($snapshot === null || !self::matchesIntent($intent, $snapshot)) {
            return self::record($intent, 'review', 'refund_evidence_mismatch', time() + 120, $lock);
        }

        $status = $snapshot->status();
        if ($intent['state'] === 'projection_pending' && $status !== 'completed') {
            return self::record($intent, 'review', 'refund_state_changed', null, $lock);
        }
        $state = in_array($status, self::ACTIVE, true) ? 'pending' : ($status === 'completed' ? 'projection_pending' : $status);
        $intent = RefundIntentStore::update($intent, [
            'state' => $state,
            'review_reason' => in_array($state, ['failed', 'cancelled'], true) ? 'refund_' . $state : null,
            'reconcile_after' => in_array($state, ['pending', 'projection_pending'], true) ? time() + 60 : null,
        ], $lock);
        if ($state !== 'projection_pending') {
            return $intent;
        }
        if (!self::nativeEligible($attempt)) {
            return self::record($intent, 'review', 'native_refund_state_changed', null, $lock);
        }

        $proof = Settlement::proofBinding($attempt) + [
            'refund' => [
                'withdrawal_id' => $id,
                'external_order_id' => $intent['external_order_id'],
                'mode' => $intent['mode'],
                'amount' => $intent['expected_amount'],
                'currency' => $intent['currency'],
                'network' => $intent['network'],
                'recipient_hash' => $intent['recipient_hash'],
            ],
        ];
        try {
            $outcome = $intent['mode'] === 'full'
                ? Settlement::project($attempt, $proof, 'refunded', $lock)
                : self::projectOverpayment($attempt, $proof, $lock);
        } catch (\Throwable $error) {
            return self::record($intent, 'projection_pending', 'native_refund_projection_pending', time() + 60, $lock);
        }
        if (!in_array($outcome, ['refunded', 'overpayment'], true)) {
            return self::record($intent, 'review', 'native_refund_state_changed', null, $lock);
        }

        return self::record($intent, 'completed', null, null, $lock);
    }

    private static function projectOverpayment(array $attempt, array $proof, WordPressOrderLock $lock): string
    {
        $marker = EventStore::projection($attempt['id'], 'overpayment');
        ksort($proof);
        $proofHash = hash('sha256', json_encode($proof, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        if ($marker === null) {
            $marker = EventStore::reserveProjection($attempt, 'overpayment', $proofHash, $lock);
        } elseif (!hash_equals($marker['proof_hash'], $proofHash)) {
            return 'review';
        }
        if ($marker['stage'] === 'awaiting_native') {
            // The excess refund leaves the original native payment in its current verified state.
            $marker = EventStore::advance($marker, 'native_committed', false, $lock);
        }
        Settlement::repairNote($attempt, $marker['projection_id'], $lock);

        return 'overpayment';
    }

    private static function record(array $intent, string $state, ?string $reason, ?int $after, WordPressOrderLock $lock): array
    {
        return RefundIntentStore::update($intent, ['state' => $state, 'review_reason' => $reason, 'reconcile_after' => $after], $lock);
    }

    private static function client(array $attempt): ?Client
    {
        $keys = ConnectionStore::forAttempt($attempt);

        return $keys === null ? null : new Client(new ClientConfig($keys['api_key'], $keys['api_secret'], $keys['base_url'], 5), new WordPressTransport());
    }


    private static function paidInvoice(array $attempt, Client $client): ?array
    {
        $invoice = $client->invoices()->get($attempt['invoice_id']);
        if (!Checkout::invoiceMatches($attempt, $invoice) || $invoice['is_final'] !== true
            || !in_array($invoice['status'], ['paid', 'paid_over'], true) || ($invoice['paid_late_at'] ?? null) !== null) {
            return null;
        }
        $payment = $invoice['payment'] ?? null;
        if (!is_array($payment)) {
            return null;
        }
        $currency = $payment['currency'] ?? null;
        $network = $payment['network'] ?? null;
        $chainId = $payment['chain_id'] ?? null;
        $contract = $payment['contract_address'] ?? null;
        $expected = self::decimal($payment['expected'] ?? null);
        $paid = self::decimal($payment['paid'] ?? null);
        if (
            !is_string($currency) || !is_string($network) || !is_int($chainId) || $chainId === 0
            || $expected === null || $expected === '0' || $paid === null
        ) {
            return null;
        }

        // Reuse the exact asset and required amount already verified by the native paid projection.
        $proof = Settlement::proofBinding($attempt) + ['coverage' => compact('currency', 'network', 'chainId', 'contract', 'expected')];
        ksort($proof);
        $marker = EventStore::projection($attempt['id'], 'paid');
        $hash = hash('sha256', json_encode($proof, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        if ($marker === null || !hash_equals($marker['proof_hash'], $hash)) {
            return null;
        }

        return $invoice;
    }

    /** Return a complete, bounded view of the original invoice's refund history. */
    private static function history(Client $client, array $attempt, array $invoice): ?array
    {
        $filters = ['invoice_id' => $attempt['invoice_id'], 'limit' => 20];
        $history = [];
        $external = [];
        for ($index = 0; $index < 2; $index++) {
            $page = $client->refunds()->listPage($filters);
            if (!is_array($page['items'] ?? null) || count($page['items']) > 20 || !array_key_exists('next_cursor', $page)) {
                return null;
            }
            foreach ($page['items'] as $wire) {
                $item = is_array($wire) ? self::verifiedRefund($wire, $invoice, $attempt) : null;
                if ($item === null || isset($history[$item->withdrawalId()]) || isset($external[$item->externalOrderId()])) {
                    return null;
                }
                $history[$item->withdrawalId()] = $item;
                $external[$item->externalOrderId()] = true;
            }
            $cursor = $page['next_cursor'];
            if ($cursor === null) {
                return $history;
            }
            if (!is_string($cursor) || $cursor === '' || strlen($cursor) > 2048 || $cursor === ($filters['cursor'] ?? null)) {
                return null;
            }
            $filters['cursor'] = $cursor;
        }

        return null;
    }

    private static function withinUtf16Units(string $value, int $max): bool
    {
        // .NET string limits count UTF-16 code units, including two for astral characters.
        $characters = preg_match_all('/./us', $value);
        $surrogatePairs = preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $value);

        return $characters !== false && $surrogatePairs !== false && ($characters + $surrogatePairs) <= $max;
    }

    private static function verifiedRefund(array $wire, array $invoice, array $attempt): ?VerifiedRefund
    {
        $status = $wire['status'] ?? null;
        $recipient = $wire['first_sender'] ?? null;
        $external = $wire['external_order_id'] ?? null;
        $amount = self::decimal($wire['amount'] ?? null);
        if (
            ($wire['type'] ?? null) !== 'refund' || !self::id($wire['withdrawal_id'] ?? null, 'wdr')
            || ($wire['invoice_id'] ?? null) !== $attempt['invoice_id'] || ($wire['project_id'] ?? null) !== $attempt['project_id']
            || ($wire['is_test'] ?? null) !== ($attempt['environment'] === 'sandbox')
            || ($wire['currency'] ?? null) !== $invoice['payment']['currency'] || ($wire['network'] ?? null) !== $invoice['payment']['network']
            || !in_array($status, array_merge(self::ACTIVE, self::FINAL), true)
            || ($wire['is_final'] ?? null) !== in_array($status, self::FINAL, true)
            || ($status === 'completed' && (!is_int($wire['completed_at'] ?? null) || $wire['completed_at'] <= 0))
            || !is_string($external) || trim($external) === '' || !self::withinUtf16Units($external, 200) || preg_match('/[\x00-\x1f\x7f]/', $external)
            || !is_string($recipient) || preg_match('/\A[\x21-\x7e]{1,256}\z/', $recipient) !== 1
            || ($wire['destination_address'] ?? null) !== $recipient || $amount === null || $amount === '0'
        ) {
            return null;
        }

        try {
            return VerifiedRefund::fromApi($wire, $invoice, new \DateTimeImmutable());
        } catch (\UnexpectedValueException $error) {
            return null;
        }
    }

    private static function availableAmount(array $payment, array $history, array $intent): array
    {
        $amount = self::decimal($payment['paid']);
        // Paymos refunds only an invoice whose received amount covers the expected one. A donation it
        // completed inside its underpayment tolerance cannot be refunded in either mode.
        if (self::difference($amount, self::decimal($payment['expected'])) === null) {
            return ['reason' => 'refund_amount_unavailable'];
        }
        $recipientHash = null;
        foreach ($history as $refund) {
            $hash = self::recipientHash($refund);
            if (($recipientHash !== null && !hash_equals($recipientHash, $hash)) || $refund->externalOrderId() === $intent['external_order_id']) {
                return ['reason' => 'refund_history_conflict'];
            }
            $recipientHash = $hash;
            if (in_array($refund->status(), self::ACTIVE, true)) {
                return ['reason' => 'refund_in_progress'];
            }
            if ($refund->status() === 'completed') {
                if ($refund->mode() === 'full') {
                    return ['reason' => 'invoice_already_refunded'];
                }
                $amount = self::difference($amount, self::decimal($refund->principalDecimal()));
                if ($amount === null) {
                    return ['reason' => 'refund_history_conflict'];
                }
            }
        }
        if ($intent['mode'] === 'overpayment') {
            $amount = self::difference($amount, self::decimal($payment['expected']));
        }

        return $amount === null || $amount === '0'
            ? ['reason' => 'refund_amount_unavailable']
            : ['reason' => null, 'amount' => $amount, 'recipient_hash' => $recipientHash];
    }

    private static function recipientHash(VerifiedRefund $refund): string
    {
        return hash_hmac('sha256', 'paymos-givewp-refund|' . $refund->environment() . '|' . $refund->network() . '|' . $refund->recipient(), wp_salt('auth'));
    }

    private static function decimal($value): ?string
    {
        return is_string($value) && preg_match('/\A[0-9]{1,28}(?:\.[0-9]{1,28})?\z/', $value) === 1
            ? AmountGuard::normalizeDecimal($value) : null;
    }

    /** Subtract nonnegative decimals exactly without optional extensions or floats. */
    private static function difference(string $gross, string $deduction): ?string
    {
        $left = self::minorUnits($gross);
        $right = self::minorUnits($deduction);
        if (strcmp($left, $right) < 0) {
            return null;
        }

        $digits = '';
        $borrow = 0;
        for ($index = strlen($left) - 1; $index >= 0; $index--) {
            $value = (int) $left[$index] - (int) $right[$index] - $borrow;
            $borrow = (int) ($value < 0);
            $digits = (string) ($value + 10 * $borrow) . $digits;
        }

        return AmountGuard::normalizeDecimal(substr($digits, 0, -28) . '.' . substr($digits, -28));
    }

    private static function minorUnits(string $value): string
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return str_pad($whole, 28, '0', STR_PAD_LEFT) . str_pad($fraction, 28, '0');
    }

    private static function id($value, string $prefix): bool
    {
        return is_string($value) && preg_match('/\A' . $prefix . '_[A-Za-z0-9_-]{1,120}\z/', $value) === 1;
    }
}
