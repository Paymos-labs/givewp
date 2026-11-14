<?php

declare(strict_types=1);

namespace PaymosGivewp;

use PaymosGivewpVendor\Paymos\Plugin\AmountGuard;
use PaymosGivewpVendor\Paymos\Webhook\WebhookEvent;
use PaymosGivewpVendor\Paymos\Webhook\WebhookVerifier;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

final class WebhookController
{
    private const STATUS_BY_TYPE = [
        'invoice.awaiting_payment' => ['awaiting_payment'],
        'invoice.confirming' => ['confirming'],
        'invoice.underpaid_waiting' => ['underpaid_waiting'],
        'invoice.paid' => ['paid'],
        'invoice.paid_over' => ['paid_over'],
        'invoice.underpaid' => ['underpaid'],
        'invoice.expired' => ['expired'],
        'invoice.cancelled' => ['cancelled'],
        'invoice.paid_late' => ['expired', 'underpaid'],
    ];

    public static function registerRoutes(): void
    {
        if (!Install::settlementReady()) {
            return;
        }
        register_rest_route(ConnectController::REST_NAMESPACE, '/webhook', [
            'methods' => 'POST',
            // A Paymos server signs the exact body. This route has no WordPress browser session.
            'permission_callback' => '__return_true',
            'callback' => [self::class, 'handle'],
        ]);
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        if (!Install::settlementReady() || !Plugin::supportsNativeRuntime()) {
            return self::reply(503, 'unavailable');
        }
        $raw = $request->get_body();
        $signature = $request->get_header('x-webhook-signature');
        if ($raw === '' || strlen($raw) > 65536) {
            return self::reply(strlen($raw) > 65536 ? 413 : 400, 'invalid_body');
        }
        if (!is_string($signature) || $signature === '' || strlen($signature) > 768) {
            return self::reply(401, 'invalid_signature');
        }
        $payload = json_decode($raw, true, 24);
        if (!self::validEnvelope($payload)) {
            return self::reply(400, 'invalid_event');
        }
        $headerId = $request->get_header('x-webhook-id');
        if ($headerId !== null && $headerId !== '' && $headerId !== $payload['event_id']) {
            return self::reply(400, 'invalid_event');
        }

        $type = $payload['event_type'];
        $invoiceEvent = isset(self::STATUS_BY_TYPE[$type]);
        $data = $payload['data'];
        $invoiceId = $data['invoice_id'] ?? null;
        if ($invoiceEvent && !self::validInvoiceData($data, $type)) {
            return self::reply(400, 'invalid_event');
        }
        if (!self::id($invoiceId, 'inv')) {
            return self::reply(200, 'ignored');
        }

        try {
            $attempt = AttemptStore::byInvoice($invoiceId);
            if ($attempt === null && $invoiceEvent) {
                $attempt = AttemptStore::byExternalOrder($data['order']['external_id']);
            }
            if ($attempt === null) {
                // No local association to authenticate or mutate.
                return self::reply(200, 'ignored');
            }

            $keys = ConnectionStore::forAttempt($attempt);
            if ($keys === null) {
                return self::reply(503, 'unavailable');
            }
            if (!(new WebhookVerifier($keys['webhook_secret']))->verify($signature, $raw)) {
                return self::reply(401, 'invalid_signature');
            }
            if (!$invoiceEvent) {
                // Refunds are reconciled through their own signed API reads.
                return self::reply(200, 'ignored');
            }
            if (!self::matches($attempt, $data)) {
                return self::reply(409, 'binding_mismatch');
            }

            $lock = AttemptStore::lock($attempt['donation_id']);
            if (!$lock->acquire()) {
                return self::reply(409, 'busy');
            }
            try {
                $current = AttemptStore::byId($attempt['id']);
                if ($current === null || $current['generation'] !== $attempt['generation'] || !self::matches($current, $data)) {
                    return self::reply(409, 'binding_mismatch');
                }

                $key = EventStore::eventKey($current, $payload['event_id']);
                $bodyHash = hash('sha256', $raw);
                $reservation = EventStore::begin($current, $key, $bodyHash, $lock);
                if ($reservation === 'conflict') {
                    return self::reply(409, 'event_conflict');
                }
                if ($reservation === 'duplicate') {
                    return self::reply(200, 'duplicate');
                }

                Settlement::verifyAndApply($current, $lock, new WebhookEvent($payload));
                EventStore::finish($current, $key, $bodyHash, $lock);

                return self::reply(200, 'accepted');
            } finally {
                $lock->release();
            }
        } catch (\Throwable $error) {
            // Retain the exact event reservation and durable marker for a retry.
            return self::reply(503, 'retry');
        }
    }

    private static function validEnvelope($payload): bool
    {
        return is_array($payload)
            && self::id($payload['event_id'] ?? null, 'evt')
            && is_string($payload['event_type'] ?? null)
            && preg_match('/\A[a-z][a-z_]{0,24}\.[a-z][a-z_]{0,32}\z/', $payload['event_type']) === 1
            && ($payload['version'] ?? null) === 1
            && is_int($payload['occurred_at'] ?? null) && $payload['occurred_at'] > 0
            && is_array($payload['data'] ?? null);
    }

    private static function validInvoiceData(array $data, string $type): bool
    {
        $order = $data['order'] ?? null;
        $status = $data['status'] ?? null;

        return self::id($data['invoice_id'] ?? null, 'inv')
            && self::id($data['project_id'] ?? null, 'prj')
            && is_string($status) && in_array($status, self::STATUS_BY_TYPE[$type], true)
            && ($data['is_final'] ?? null) === in_array($status, ['paid', 'paid_over', 'underpaid', 'expired', 'cancelled'], true)
            && is_bool($data['is_test'] ?? null)
            && is_array($order) && is_string($order['external_id'] ?? null)
            && preg_match('/\A[A-Za-z0-9_-]{1,80}\z/', $order['external_id']) === 1
            && is_string($order['amount'] ?? null) && preg_match('/\A[0-9]{1,28}(?:\.[0-9]{1,18})?\z/', $order['amount']) === 1
            && is_string($order['currency'] ?? null) && preg_match('/\A[A-Z]{3}\z/', $order['currency']) === 1;
    }

    private static function matches(array $attempt, array $data): bool
    {
        return $attempt['site'] === (int) get_current_blog_id()
            && ($attempt['invoice_id'] === $data['invoice_id'] || ($attempt['invoice_id'] === null && $attempt['status'] === 'creating'))
            && $attempt['project_id'] === $data['project_id']
            && ($attempt['environment'] === 'sandbox') === $data['is_test']
            && $attempt['external_order_id'] === $data['order']['external_id']
            && $attempt['currency'] === $data['order']['currency']
            && AmountGuard::amountsEqual($attempt['amount'], $data['order']['amount']);
    }

    private static function id($value, string $prefix): bool
    {
        return is_string($value) && preg_match('/\A' . $prefix . '_[A-Za-z0-9_-]{1,120}\z/', $value) === 1;
    }

    private static function reply(int $code, string $result): WP_REST_Response
    {
        $response = new WP_REST_Response(['result' => $result], $code);
        $response->header('Cache-Control', 'private, no-store');

        return $response;
    }
}
