<?php

declare(strict_types=1);

namespace PaymosGivewp;

use Give\API\REST\V3\Routes\Donations\DonationController;
use Give\Donations\Models\Donation;
use Give\Donations\Models\DonationNote;
use Give\Donations\ValueObjects\DonationMetaKeys;
use Give\Donations\ValueObjects\DonationNoteType;
use Give\Donations\ValueObjects\DonationStatus;
use PaymosGivewpVendor\Paymos\Client;
use PaymosGivewpVendor\Paymos\ClientConfig;
use PaymosGivewpVendor\Paymos\Http\WordPressTransport;
use PaymosGivewpVendor\Paymos\Plugin\AmountGuard;
use PaymosGivewpVendor\Paymos\Plugin\InvoiceReverseVerifier;
use PaymosGivewpVendor\Paymos\Plugin\WordPressOrderLock;
use PaymosGivewpVendor\Paymos\Webhook\WebhookEvent;

defined('ABSPATH') || exit;

final class Settlement
{
    private const PENDING = [DonationStatus::PENDING, DonationStatus::PROCESSING];
    private const PAID = ['paid', 'paid_over'];
    private const REFUND_ACTIVE = ['created', 'signed', 'pending_review', 'cancelling'];
    private const REFUND_FINAL = ['completed', 'failed', 'cancelled'];

    private static $nativeWrites = [];
    private static $trustedWrites = [];

    /** Read only the original generation. A webhook is evidence alongside the current API result. */
    public static function verifyAndApply(array $attempt, WordPressOrderLock $lock, ?WebhookEvent $event = null): string
    {
        $lock->assertOwned();
        $attempt = AttemptStore::byId($attempt['id']);
        if ($attempt === null || $attempt['site'] !== (int) get_current_blog_id()) {
            return 'ignored';
        }
        $lateEvent = $event !== null && $event->type() === 'invoice.paid_late';
        if (!in_array($attempt['status'], ['creating', 'open', 'verified_paid'], true) && !($lateEvent && $attempt['status'] === 'expired')) {
            return 'ignored';
        }

        $marker = EventStore::projection($attempt['id'], 'paid');
        $donation = self::boundDonation($attempt);
        if ($donation === null || ($marker === null && !in_array($donation->status->getValue(), self::PENDING, true))) {
            return self::review($attempt, 'native_state_changed', $lock, false);
        }
        if ($attempt['status'] === 'verified_paid' && $marker !== null && $marker['stage'] !== 'awaiting_native') {
            if ($donation->status->getValue() !== DonationStatus::COMPLETE || $donation->gatewayTransactionId !== $attempt['invoice_id']) {
                return self::review($attempt, 'native_state_changed', $lock, false);
            }
            self::repairNote($attempt, $marker['projection_id'], $lock);
            self::stopReconciliation($attempt['id'], $lock);

            return 'paid';
        }

        $keys = ConnectionStore::forAttempt($attempt);
        if ($keys === null) {
            return self::review($attempt, 'original_connection_unavailable', $lock);
        }
        $client = new Client(new ClientConfig($keys['api_key'], $keys['api_secret'], $keys['base_url'], 5), new WordPressTransport());
        try {
            $invoice = self::readInvoice($attempt, $client, $event);
        } catch (\Throwable $error) {
            self::review($attempt, 'invoice_snapshot_unavailable', $lock);
            throw $error;
        }
        if ($invoice === null) {
            return $event === null && $attempt['invoice_id'] === null
                ? 'pending' : self::review($attempt, 'invoice_evidence_mismatch', $lock);
        }
        if (!Checkout::invoiceMatches($attempt, $invoice)) {
            return self::review($attempt, 'invoice_evidence_mismatch', $lock);
        }

        if ($attempt['status'] !== 'expired' && ($attempt['invoice_id'] === null || $attempt['remote_status'] !== $invoice['status'])) {
            AttemptStore::bindInvoice($attempt, $invoice, $lock);
            $attempt = self::reload($attempt['id']);
        }
        if (!in_array($invoice['status'], self::PAID, true)) {
            return self::recordUnpaid($attempt, $invoice, $lateEvent, $lock);
        }

        $payment = self::fullPayment($invoice);
        $signal = $event === null ? $payment : self::fullPayment($event->toArray()['data']);
        if ($payment === null || $signal !== $payment) {
            return self::review($attempt, 'payment_coverage_mismatch', $lock);
        }
        try {
            $refundReview = self::refundReview($client, $attempt, $payment);
        } catch (\Throwable $error) {
            self::review($attempt, 'refund_snapshot_unavailable', $lock);
            throw $error;
        }
        if ($refundReview !== null) {
            return self::review($attempt, $refundReview, $lock);
        }

        // The payment may gain excess later. The asset, required coverage and bound order remain immutable.
        unset($payment['paid']);
        $proof = self::proofBinding($attempt) + ['coverage' => $payment];

        return self::project($attempt, $proof, 'paid', $lock);
    }

    /** Apply only a verified proof supplied by the settlement or refund verifier under this lock. */
    public static function project(array $attempt, array $proof, string $target, WordPressOrderLock $lock): string
    {
        $lock->assertOwned();
        $attempt = self::reload($attempt['id']);
        if (!in_array($target, ['paid', 'refunded'], true) || $attempt['invoice_id'] === null) {
            throw new \UnexpectedValueException('Unsupported Paymos projection.');
        }
        foreach (self::proofBinding($attempt) as $field => $value) {
            if (($proof[$field] ?? null) !== $value) {
                return self::review($attempt, 'projection_binding_mismatch', $lock, false);
            }
        }

        $targetStatus = $target === 'paid' ? DonationStatus::COMPLETE : DonationStatus::REFUNDED;
        $before = $target === 'paid' ? self::PENDING : [DonationStatus::COMPLETE];
        $beforeAttempt = $target === 'paid' ? 'open' : 'verified_paid';
        $afterAttempt = $target === 'paid' ? 'verified_paid' : 'refunded';
        $marker = EventStore::projection($attempt['id'], $target);
        $current = self::boundDonation($attempt);
        if ($current === null || !in_array($attempt['status'], [$beforeAttempt, $afterAttempt], true)) {
            return self::review($attempt, 'native_state_changed', $lock, false);
        }
        $nativeStatus = $current->status->getValue();
        $nativeCommitted = $nativeStatus === $targetStatus && $current->gatewayTransactionId === $attempt['invoice_id'];
        $canWrite = $attempt['status'] === $beforeAttempt && in_array($nativeStatus, $before, true)
            && ($target === 'paid' || $current->gatewayTransactionId === $attempt['invoice_id']);
        if (($marker === null && !$canWrite) || ($marker !== null && !$nativeCommitted && (!$canWrite || $marker['stage'] !== 'awaiting_native'))) {
            return self::review($attempt, 'native_state_changed', $lock, false);
        }

        ksort($proof);
        $proofHash = hash('sha256', json_encode($proof, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        if ($marker === null) {
            $marker = EventStore::reserveProjection($attempt, $target, $proofHash, $lock);
        } elseif (!hash_equals($marker['proof_hash'], $proofHash)) {
            return self::review($attempt, 'projection_evidence_changed', $lock, false);
        }

        if ($marker['stage'] === 'awaiting_native') {
            if (!$nativeCommitted) {
                self::writeNative($attempt, $current, $targetStatus, $lock);
                $saved = self::boundDonation($attempt);
                if ($saved === null || $saved->status->getValue() !== $targetStatus || $saved->gatewayTransactionId !== $attempt['invoice_id']) {
                    throw new \RuntimeException('The native donation commit could not be verified.');
                }
            }

            // Recovering a committed status cannot establish which native after-commit hooks ran.
            $marker = EventStore::advance($marker, 'native_committed', $nativeCommitted && $target === 'paid', $lock);
        }

        if ($attempt['status'] !== $afterAttempt) {
            AttemptStore::update($attempt, [
                'status' => $afterAttempt,
                'review_reason' => (int) $marker['receipt_delivery_unknown'] === 1 ? 'receipt_delivery_unknown' : null,
            ], $lock);
            $attempt = self::reload($attempt['id']);
        }

        self::repairNote($attempt, $marker['projection_id'], $lock);
        self::stopReconciliation($attempt['id'], $lock);

        return $target;
    }

    /** Record the original tuple alongside typed proof in the corresponding verifier. */
    public static function proofBinding(array $attempt): array
    {
        return [
            'invoice_id' => $attempt['invoice_id'],
            'project_id' => $attempt['project_id'],
            'environment' => $attempt['environment'],
            'external_order_id' => $attempt['external_order_id'],
            'amount' => AmountGuard::normalizeDecimal($attempt['amount']),
            'currency' => $attempt['currency'],
        ];
    }

    public static function repairNote(array $attempt, string $projectionId, WordPressOrderLock $lock): void
    {
        $lock->assertOwned();
        $marker = EventStore::byProjectionId($projectionId);
        if ($marker === null || (int) $marker['attempt_id'] !== $attempt['id']) {
            throw new \RuntimeException('Paymos projection note is not bound.');
        }
        if ($marker['stage'] === 'done') {
            return;
        }
        if ($marker['stage'] === 'native_committed') {
            $marker = EventStore::advance($marker, 'note_pending', false, $lock);
        }
        if ($marker['stage'] !== 'note_pending') {
            throw new \RuntimeException('Paymos projection is not ready for a note.');
        }

        $key = '[paymos:' . $projectionId . ']';
        if (!self::noteExists($attempt['donation_id'], $key)) {
            $message = $marker['target'] === 'paid'
                ? __('Paymos verified payment for this donation.', 'paymos-stablecoin-donations')
                : __('Paymos confirmed the refund for this donation.', 'paymos-stablecoin-donations');
            $lock->assertOwned();
            DonationNote::create([
                'donationId' => $attempt['donation_id'],
                'content' => $message . ' ' . $key,
                'type' => DonationNoteType::ADMIN(),
            ]);
        }
        if (!self::noteExists($attempt['donation_id'], $key)) {
            throw new \RuntimeException('The Paymos donation note could not be verified.');
        }

        EventStore::advance($marker, 'done', false, $lock);
    }

    private static function noteExists(int $donationId, string $key): bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Search the exact native note marker even after an uncertain native insert.
        $id = $wpdb->get_var($wpdb->prepare(
            'SELECT comment_ID FROM %i WHERE comment_parent = %d AND comment_type = %s AND BINARY RIGHT(comment_content, %d) = BINARY %s LIMIT 1',
            $wpdb->prefix . 'give_comments', $donationId, 'donation', strlen($key), $key
        ));
        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('Native donation notes are unavailable.');
        }

        return $id !== null;
    }

    private static function readInvoice(array $attempt, Client $client, ?WebhookEvent $event): ?array
    {
        if ($event !== null) {
            $expected = self::proofBinding($attempt);
            if ($expected['invoice_id'] === null) {
                unset($expected['invoice_id']);
            }
            $verified = (new InvoiceReverseVerifier($client))->verify($event, $expected);

            return $verified->isVerified() ? $verified->invoice() : null;
        }
        if ($attempt['invoice_id'] !== null) {
            return $client->invoices()->get($attempt['invoice_id']);
        }

        $page = $client->invoices()->listPage([
            'project_id' => $attempt['project_id'],
            'external_order_id' => $attempt['external_order_id'],
            'limit' => 2,
        ]);
        if (!is_array($page['items'] ?? null) || count($page['items']) !== 1
            || !array_key_exists('next_cursor', $page) || $page['next_cursor'] !== null) {
            return null;
        }
        $id = $page['items'][0]['invoice_id'] ?? null;

        return is_string($id) && preg_match('/\Ainv_[A-Za-z0-9_-]{1,120}\z/', $id) === 1 ? $client->invoices()->get($id) : null;
    }

    /** Callers check paid/paid_over first; the server has already applied its underpayment tolerance. */
    private static function fullPayment(array $invoice): ?array
    {
        $payment = $invoice['payment'] ?? null;
        if (($invoice['is_final'] ?? null) !== true || ($invoice['paid_late_at'] ?? null) !== null || !is_array($payment)) {
            return null;
        }
        $currency = $payment['currency'] ?? null;
        $network = $payment['network'] ?? null;
        $chainId = $payment['chain_id'] ?? null;
        $contract = $payment['contract_address'] ?? null;
        $expected = self::decimal($payment['expected'] ?? null);
        $paid = self::decimal($payment['paid'] ?? null);
        if (
            !is_string($currency) || preg_match('/\A[A-Za-z0-9._-]{1,24}\z/', $currency) !== 1
            || !is_string($network) || preg_match('/\A[A-Za-z0-9_-]{1,40}\z/', $network) !== 1
            || !is_int($chainId) || $chainId === 0
            || ($contract !== null && (!is_string($contract) || preg_match('/\A[A-Za-z0-9:._-]{1,128}\z/', $contract) !== 1))
            || $expected === null || $expected === '0' || $paid === null
        ) {
            return null;
        }

        return compact('currency', 'network', 'chainId', 'contract', 'expected', 'paid');
    }

    private static function decimal($value): ?string
    {
        return is_string($value) && preg_match('/\A[0-9]{1,28}(?:\.[0-9]{1,28})?\z/', $value) === 1
            ? AmountGuard::normalizeDecimal($value) : null;
    }

    private static function refundReview(Client $client, array $attempt, array $payment): ?string
    {
        $filters = ['invoice_id' => $attempt['invoice_id'], 'limit' => 20];
        $seen = [];
        for ($pageNumber = 0; $pageNumber < 2; $pageNumber++) {
            $page = $client->refunds()->listPage($filters);
            if (!is_array($page['items'] ?? null) || count($page['items']) > 20 || !array_key_exists('next_cursor', $page)) {
                return 'refund_snapshot_invalid';
            }
            foreach ($page['items'] as $refund) {
                $id = $refund['withdrawal_id'] ?? null;
                $status = $refund['status'] ?? null;
                $mode = $refund['mode'] ?? null;
                $amount = self::decimal($refund['amount'] ?? null);
                if (
                    !is_string($id) || preg_match('/\Awdr_[A-Za-z0-9_-]{1,120}\z/', $id) !== 1 || isset($seen[$id])
                    || ($refund['invoice_id'] ?? null) !== $attempt['invoice_id'] || ($refund['project_id'] ?? null) !== $attempt['project_id']
                    || ($refund['is_test'] ?? null) !== ($attempt['environment'] === 'sandbox')
                    || ($refund['type'] ?? null) !== 'refund' || !in_array($mode, ['full', 'overpayment'], true)
                    || !in_array($status, array_merge(self::REFUND_ACTIVE, self::REFUND_FINAL), true)
                    || ($refund['is_final'] ?? null) !== in_array($status, self::REFUND_FINAL, true)
                    || ($refund['currency'] ?? null) !== $payment['currency'] || ($refund['network'] ?? null) !== $payment['network']
                    || $amount === null || $amount === '0'
                    || !is_string($refund['external_order_id'] ?? null) || $refund['external_order_id'] === '' || strlen($refund['external_order_id']) > 200
                ) {
                    return 'refund_snapshot_invalid';
                }
                $seen[$id] = true;
                if ($mode === 'full' && !in_array($status, ['failed', 'cancelled'], true)) {
                    return 'full_refund_detected';
                }
            }

            $cursor = $page['next_cursor'];
            if ($cursor === null) {
                return null;
            }
            if (!is_string($cursor) || $cursor === '' || strlen($cursor) > 2048 || $cursor === ($filters['cursor'] ?? null)) {
                return 'refund_snapshot_invalid';
            }
            $filters['cursor'] = $cursor;
        }

        return 'refund_snapshot_incomplete';
    }

    private static function recordUnpaid(array $attempt, array $invoice, bool $late, WordPressOrderLock $lock): string
    {
        $final = $invoice['is_final'] === true;
        $payment = $invoice['payment'] ?? null;
        $paid = $final && is_array($payment) ? self::decimal($payment['paid'] ?? null) : null;
        $reason = null;
        if ($late || ($invoice['paid_late_at'] ?? null) !== null) {
            $reason = AttemptStore::REVIEW_LATE_PAYMENT;
        } elseif ($paid !== null && $paid !== '0') {
            $reason = AttemptStore::REVIEW_TERMINAL_FUNDED;
        }

        if ($final && in_array($invoice['status'], ['expired', 'cancelled'], true) && EventStore::projection($attempt['id'], 'paid') === null) {
            $reason = self::preserveFundingReason($attempt, $reason);
            AttemptStore::update($attempt, [
                'status' => $invoice['status'],
                'review_reason' => $reason,
                'reconcile_after' => null,
            ], $lock);

            return $reason !== null ? 'review' : 'unpaid';
        }
        if ($reason !== null || $final || EventStore::projection($attempt['id'], 'paid') !== null) {
            return self::review($attempt, $reason ?? 'payment_not_final_and_full', $lock, !$final);
        }

        return 'pending';
    }

    private static function preserveFundingReason(array $attempt, ?string $reason): ?string
    {
        // Later incomplete or stale evidence cannot erase funds already confirmed for this invoice.
        $saved = $attempt['review_reason'] ?? null;
        if ($saved === AttemptStore::REVIEW_LATE_PAYMENT || $reason === AttemptStore::REVIEW_LATE_PAYMENT) {
            return AttemptStore::REVIEW_LATE_PAYMENT;
        }

        return $saved === AttemptStore::REVIEW_TERMINAL_FUNDED ? $saved : $reason;
    }

    private static function review(array $attempt, string $reason, WordPressOrderLock $lock, bool $retry = true): string
    {
        AttemptStore::update($attempt, [
            'review_reason' => self::preserveFundingReason($attempt, $reason),
            'reconcile_after' => $retry ? time() + 60 : null,
        ], $lock);

        return 'review';
    }

    private static function stopReconciliation(int $attemptId, WordPressOrderLock $lock): void
    {
        $attempt = self::reload($attemptId);
        if ($attempt['reconcile_after'] !== null) {
            AttemptStore::update($attempt, ['reconcile_after' => null], $lock);
        }
    }

    private static function reload(int $id): array
    {
        $row = AttemptStore::byId($id);
        if ($row === null) {
            throw new \RuntimeException('The Paymos donation attempt no longer exists.');
        }

        return $row;
    }

    private static function boundDonation(array $attempt): ?Donation
    {
        $donation = Donation::find($attempt['donation_id']);
        if (!$donation instanceof Donation || !Checkout::matchesDonation($attempt, $donation)) {
            return null;
        }
        $invoiceId = $donation->gatewayTransactionId;

        return $invoiceId === null || $invoiceId === '' || $invoiceId === $attempt['invoice_id'] ? $donation : null;
    }


    public static function registerNativeGuards(): void
    {
        add_action('givewp_donation_updating', [self::class, 'guardNativeUpdate'], PHP_INT_MAX);
        add_action('givewp_donation_updated', [self::class, 'releaseNativeWrite'], PHP_INT_MAX);
        foreach (['deleting' => 'deleted', 'trashing' => 'trashed', 'untrashing' => 'untrashed'] as $before => $after) {
            add_action('givewp_donation_' . $before, [self::class, 'guardNativeDestructive'], PHP_INT_MAX);
            add_action('givewp_donation_' . $after, [self::class, 'releaseNativeWrite'], PHP_INT_MAX);
        }
        add_filter('give_should_update_payment_status', [self::class, 'guardLegacyStatus'], PHP_INT_MAX, 2);
        add_action('give_update_payment_details', [self::class, 'guardLegacyEdit'], 1);
        add_action('give_delete_payment', [self::class, 'guardLegacyDelete'], 1);
        add_action('give_pre_delete_donor', [self::class, 'guardDonorDelete'], 1, 2);
        foreach (['donation', 'payment'] as $metaType) {
            foreach (['add', 'update', 'delete'] as $action) {
                add_filter($action . '_' . $metaType . '_metadata', [self::class, 'guardFinancialMeta'], PHP_INT_MAX, 3);
            }
        }
        add_filter('rest_dispatch_request', [self::class, 'dispatchNativeRest'], 10, 4);
        add_action('shutdown', [self::class, 'releaseNativeWrites']);
    }

    private static function writeNative(array $attempt, Donation $donation, string $target, WordPressOrderLock $lock): void
    {
        $from = $donation->status->getValue();
        $donation->status = new DonationStatus($target);
        $donation->gatewayTransactionId = $attempt['invoice_id'];
        $object = spl_object_id($donation);
        self::$trustedWrites[$object] = [
            'attempt' => $attempt,
            'from' => $from,
            'target' => $target,
            'lock' => $lock,
        ];
        try {
            $lock->assertOwned();
            $donation->save();
        } finally {
            unset(self::$trustedWrites[$object]);
        }
    }

    public static function guardNativeUpdate(Donation $incoming): void
    {
        if (!Install::settlementReady()) {
            return;
        }
        $id = (int) $incoming->id;
        $original = $incoming->getOriginal();
        $object = spl_object_id($incoming);
        $trusted = self::$trustedWrites[$object] ?? null;
        if ($trusted !== null) {
            $trusted['lock']->assertOwned();
            $current = self::boundDonation($trusted['attempt']);
            if (
                $current === null || $current->status->getValue() !== $trusted['from']
                || !self::sameAttributes($original, $current->getAttributes())
                || !Checkout::matchesDonation($trusted['attempt'], $incoming)
                || $incoming->status->getValue() !== $trusted['target']
                || $incoming->gatewayTransactionId !== $trusted['attempt']['invoice_id']
                || !self::sameAttributes($original, $incoming->getAttributes(), ['status', 'gatewayTransactionId'])
            ) {
                throw self::denied();
            }

            return;
        }

        if ($id <= 0 || !self::mightBeBound($incoming)) {
            return;
        }
        $lock = AttemptStore::lock($id);
        if (!$lock->acquire()) {
            throw self::denied();
        }
        try {
            $attempt = AttemptStore::byDonation($id);
            if ($attempt !== null) {
                $current = self::boundDonation($attempt);
                if (
                    $current === null || !Checkout::matchesDonation($attempt, $incoming)
                    || !self::sameAttributes($original, $current->getAttributes())
                    || !self::sameProtectedFields($current, $incoming)
                ) {
                    throw self::denied();
                }
            }

            self::$nativeWrites[$object] = ['site' => (int) get_current_blog_id(), 'donation' => $id, 'lock' => $lock];
        } catch (\Throwable $error) {
            $lock->release();
            throw $error;
        }
    }

    public static function guardNativeDestructive(Donation $donation): void
    {
        if (!Install::settlementReady()) {
            return;
        }
        $lock = null;
        $held = false;
        try {
            if (!self::mightBeBound($donation)) {
                return;
            }
            $lock = AttemptStore::lock((int) $donation->id);
            $held = $lock->acquire();
            if (!$held || AttemptStore::byDonation((int) $donation->id) !== null) {
                throw self::denied();
            }

            self::$nativeWrites[spl_object_id($donation)] = [
                'site' => (int) get_current_blog_id(), 'donation' => (int) $donation->id, 'lock' => $lock,
            ];
        } catch (\Throwable $error) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The pinned native trash/delete hooks run just after START TRANSACTION.
            $wpdb->query('ROLLBACK');
            if ($held) {
                $lock->release();
            }
            throw $error;
        }
    }

    public static function releaseNativeWrite(Donation $donation): void
    {
        $object = spl_object_id($donation);
        if (isset(self::$nativeWrites[$object])) {
            $write = self::$nativeWrites[$object];
            unset(self::$nativeWrites[$object]);
            $write['lock']->release();
        }
    }

    public static function releaseNativeWrites(): void
    {
        foreach (self::$nativeWrites as $object => $write) {
            unset(self::$nativeWrites[$object]);
            try {
                $write['lock']->release();
            } catch (\Throwable $error) {
                // The database session will release its own locks on disconnect.
            }
        }
    }

    private static function mightBeBound(Donation $donation): bool
    {
        $original = $donation->getOriginal();

        return $donation->gatewayId === Gateway::id() || ($original['gatewayId'] ?? null) === Gateway::id()
            || AttemptStore::byDonation((int) $donation->id) !== null;
    }

    private static function sameProtectedFields(Donation $current, Donation $incoming): bool
    {
        foreach ([
            'status', 'gatewayTransactionId', 'gatewayId', 'amount', 'formId', 'mode', 'type', 'donorId', 'purchaseKey',
            'feeAmountRecovered', 'exchangeRate', 'subscriptionId', 'levelId', 'campaignId',
        ] as $field) {
            if (!self::sameValue($current->$field, $incoming->$field)) {
                return false;
            }
        }

        return true;
    }

    private static function sameAttributes(array $original, array $current, array $except = []): bool
    {
        foreach (array_keys($original + $current) as $field) {
            if (!in_array($field, $except, true) && !self::sameValue($original[$field] ?? null, $current[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private static function sameValue($left, $right): bool
    {
        return is_object($left) && is_object($right) ? get_class($left) === get_class($right) && $left == $right : $left === $right;
    }

    public static function guardLegacyStatus($allow, $id)
    {
        return $allow && Install::settlementReady() && AttemptStore::byDonation((int) $id) !== null ? false : $allow;
    }

    public static function guardLegacyEdit($data): void
    {
        $id = absint($data['give_payment_id'] ?? 0);
        if (Install::settlementReady() && $id > 0 && current_user_can('edit_give_payments', $id) && AttemptStore::byDonation($id) !== null) {
            check_admin_referer('give_update_payment_details_nonce');
            self::denyAdmin();
        }
    }

    public static function guardLegacyDelete($data): void
    {
        $id = absint($data['purchase_id'] ?? 0);
        if (
            Install::settlementReady() && $id > 0 && current_user_can('delete_give_payments', $id)
            && wp_verify_nonce($data['_wpnonce'] ?? '', 'give_donation_nonce') && AttemptStore::byDonation($id) !== null
        ) {
            self::denyAdmin();
        }
    }

    public static function guardDonorDelete($id, $confirmed): void
    {
        if ($confirmed && Install::settlementReady() && AttemptStore::hasBoundDonor((int) $id)) {
            if (defined('REST_REQUEST') && REST_REQUEST) {
                throw self::denied();
            }
            self::denyAdmin();
        }
    }

    public static function guardFinancialMeta($decision, $id, $key)
    {
        if ($decision !== null || !Install::settlementReady() || !in_array($key, [
            DonationMetaKeys::AMOUNT, DonationMetaKeys::CURRENCY, DonationMetaKeys::EXCHANGE_RATE, DonationMetaKeys::FEE_AMOUNT_RECOVERED,
            DonationMetaKeys::GATEWAY, DonationMetaKeys::DONOR_ID, DonationMetaKeys::MODE, DonationMetaKeys::FORM_ID,
            DonationMetaKeys::PURCHASE_KEY, DonationMetaKeys::GATEWAY_TRANSACTION_ID, DonationMetaKeys::SUBSCRIPTION_ID,
            DonationMetaKeys::LEVEL_ID, DonationMetaKeys::CAMPAIGN_ID, DonationMetaKeys::IS_RECURRING, DonationMetaKeys::SUBSCRIPTION_INITIAL_DONATION,
        ], true)) {
            return $decision;
        }
        $attempt = AttemptStore::byDonation((int) $id);
        if ($attempt === null) {
            return $decision;
        }
        foreach (self::$trustedWrites as $write) {
            if ($write['attempt']['site'] === $attempt['site'] && $write['attempt']['donation_id'] === $attempt['donation_id']) {
                $write['lock']->assertOwned();

                return $decision;
            }
        }
        foreach (self::$nativeWrites as $write) {
            if ($write['site'] === $attempt['site'] && $write['donation'] === $attempt['donation_id']) {
                $write['lock']->assertOwned();

                return $decision;
            }
        }

        return false;
    }

    /** Keep GiveWP's registered permissions and arguments. Translate only our guard exception. */
    public static function dispatchNativeRest($result, \WP_REST_Request $request, string $route, array $handler)
    {
        $callback = $handler['callback'] ?? null;
        if ($result !== null || !Install::settlementReady() || !is_array($callback)
            || !($callback[0] instanceof DonationController) || !in_array($callback[1], ['update_item', 'delete_item', 'delete_items'], true)) {
            return $result;
        }
        try {
            if ($callback[1] === 'delete_items') {
                foreach ((array) $request->get_param('ids') as $id) {
                    if (AttemptStore::byDonation((int) $id) !== null) {
                        throw self::denied();
                    }
                }
            }

            return $callback($request);
        } catch (NativeMutationDenied $error) {
            return new \WP_Error('paymos_givewp_bound_donation', $error->getMessage(), ['status' => 409]);
        }
    }

    private static function denyAdmin(): void
    {
        wp_die(esc_html(self::denied()->getMessage()), '', ['response' => 409]);
    }

    private static function denied(): NativeMutationDenied
    {
        return new NativeMutationDenied(__('Paymos verifies this donation automatically. Its payment details and status are locked.', 'paymos-stablecoin-donations'));
    }
}

final class NativeMutationDenied extends \RuntimeException
{
}
