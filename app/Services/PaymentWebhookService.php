<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Traits\HandlesDeadlocks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentWebhookService
{
    use HandlesDeadlocks;

    public function __construct(
        private PaymentService $paymentService,
        private OrderService $orderService,
        private HoldService $holdService,
        private MetricsService $metricsService
    ) {}

    /**
     * Process payment webhook with idempotency.
     *
     * @param int $orderId
     * @param string $idempotencyKey
     * @param string $status
     * @return array
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
     */
    public function processWebhook(int $orderId, string $idempotencyKey, string $status): array
    {
        $startTime = microtime(true);

        // Get order status before processing
        $order = $this->orderService->findById($orderId);
        $previousStatus = $order?->status?->value ?? null;

        $paymentStatus = $this->mapStatusToEnum($status);

        // Check for duplicate/idempotent request
        $duplicateResult = $this->handleDuplicateRequest($idempotencyKey, $orderId, $status, $startTime, $previousStatus);
        if ($duplicateResult !== null) {
            $this->metricsService->incrementWebhookDuplicates();
            $processingTime = $this->calculateProcessingTime($startTime);
            $this->metricsService->recordWebhookProcessingTime($processingTime);
            return $duplicateResult;
        }

        // Process new webhook in transaction
        $result = $this->processNewWebhook($orderId, $idempotencyKey, $paymentStatus, $previousStatus);

        $processingTime = $this->calculateProcessingTime($startTime);
        $this->metricsService->recordWebhookProcessingTime($processingTime);

        return $result;
    }

    /**
     * Map string status to PaymentStatus enum.
     *
     * @param string $status
     * @return PaymentStatus
     * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
     */
    private function mapStatusToEnum(string $status): PaymentStatus
    {
        $paymentStatus = PaymentStatus::tryFrom($status);

        if ($paymentStatus === null) {
            abort(400, 'Status must be either "success" or "failed"');
        }

        return $paymentStatus;
    }

    /**
     * Handle duplicate/idempotent webhook request.
     *
     * @param string $idempotencyKey
     * @param int $orderId
     * @param string $status
     * @param float $startTime
     * @param string|null $previousStatus
     * @return array|null Returns result if duplicate, null otherwise
     */
    private function handleDuplicateRequest(
        string $idempotencyKey,
        int $orderId,
        string $status,
        float $startTime,
        ?string $previousStatus = null
    ): ?array {
        $existingPayment = $this->paymentService->findByIdempotencyKey($idempotencyKey);

        if ($existingPayment === null) {
            return null;
        }

        $order = $this->orderService->findByIdOrFail($existingPayment->order_id);
        $currentStatus = $order->status->value;

        $this->logWebhookProcessed($orderId, $idempotencyKey, $status, $startTime, true, $previousStatus, $currentStatus);

        return [
            'order_id' => $order->id,
            'status' => $currentStatus,
        ];
    }

    /**
     * Process new webhook request within a transaction.
     * 
     * Includes deadlock retry logic with exponential backoff (up to 3 retries).
     *
     * @param int $orderId
     * @param string $idempotencyKey
     * @param PaymentStatus $paymentStatus
     * @param string|null $previousStatus
     * @return array
     */
    private function processNewWebhook(
        int $orderId,
        string $idempotencyKey,
        PaymentStatus $paymentStatus,
        ?string $previousStatus = null
    ): array {
        $startTime = microtime(true);

        $result = $this->executeWithDeadlockRetry(function () use ($orderId, $idempotencyKey, $paymentStatus) {
            return DB::transaction(function () use ($orderId, $idempotencyKey, $paymentStatus) {
                $order = $this->validateAndGetOrder($orderId);

                // Double-check idempotency within transaction (race condition protection)
                $duplicateResult = $this->checkIdempotencyInTransaction($idempotencyKey);
                if ($duplicateResult !== null) {
                    return $duplicateResult;
                }

                $this->createPaymentRecord($orderId, $idempotencyKey, $paymentStatus);

                return $this->processPaymentStatus($order, $paymentStatus);
            });
        }, 'payment_webhook');

        // Get new status after processing
        $order = $this->orderService->findById($orderId);
        $newStatus = $order?->status?->value ?? null;

        $this->logWebhookProcessed($orderId, $idempotencyKey, $paymentStatus->value, $startTime, false, $previousStatus, $newStatus);

        return $result;
    }

    /**
     * Validate order exists and return it.
     *
     * @param int $orderId
     * @return Model
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    private function validateAndGetOrder(int $orderId): Model
    {
        $order = $this->orderService->findById($orderId);

        if ($order === null) {
            abort(404, 'Order not found');
        }

        return $order;
    }

    /**
     * Check for duplicate payment within transaction.
     *
     * @param string $idempotencyKey
     * @return array|null
     */
    private function checkIdempotencyInTransaction(string $idempotencyKey): ?array
    {
        $existingPayment = $this->paymentService->findByIdempotencyKey($idempotencyKey);

        if ($existingPayment === null) {
            return null;
        }

        $order = $this->orderService->findByIdOrFail($existingPayment->order_id);

        return [
            'order_id' => $order->id,
            'status' => $order->status->value,
        ];
    }

    /**
     * Create payment record.
     *
     * @param int $orderId
     * @param string $idempotencyKey
     * @param PaymentStatus $paymentStatus
     * @return void
     */
    private function createPaymentRecord(int $orderId, string $idempotencyKey, PaymentStatus $paymentStatus): void
    {
        $this->paymentService->create([
            'order_id' => $orderId,
            'idempotency_key' => $idempotencyKey,
            'status' => $paymentStatus,
        ]);
    }

    /**
     * Process payment based on status (success or failed).
     *
     * @param Model $order
     * @param PaymentStatus $paymentStatus
     * @return array
     */
    private function processPaymentStatus(Model $order, PaymentStatus $paymentStatus): array
    {
        if ($paymentStatus === PaymentStatus::SUCCESS) {
            return $this->processSuccessPayment($order);
        }

        return $this->processFailedPayment($order);
    }

    /**
     * Process successful payment.
     *
     * @param Model $order
     * @return array
     */
    private function processSuccessPayment(Model $order): array
    {
        $this->orderService->markAsPaid($order->id);

        return [
            'order_id' => $order->id,
            'status' => OrderStatus::PAID->value,
        ];
    }

    /**
     * Process failed payment.
     *
     * @param Model $order
     * @return array
     */
    private function processFailedPayment(Model $order): array
    {
        $this->orderService->cancel($order->id);

        if ($order->hold_id) {
            $this->holdService->releaseHold($order->hold_id);
        }

        return [
            'order_id' => $order->id,
            'status' => OrderStatus::CANCELLED->value,
        ];
    }

    /**
     * Calculate processing time in milliseconds.
     *
     * @param float $startTime
     * @return float
     */
    private function calculateProcessingTime(float $startTime): float
    {
        return round((microtime(true) - $startTime) * 1000, 2);
    }

    /**
     * Log webhook processing.
     *
     * @param int $orderId
     * @param string $idempotencyKey
     * @param string $status
     * @param float $startTime
     * @param bool $isDuplicate
     * @param string|null $previousStatus
     * @param string|null $newStatus
     * @return void
     */
    private function logWebhookProcessed(
        int $orderId,
        string $idempotencyKey,
        string $status,
        float $startTime,
        bool $isDuplicate,
        ?string $previousStatus = null,
        ?string $newStatus = null
    ): void {
        $processingTime = $this->calculateProcessingTime($startTime);
        $logMessage = $isDuplicate ? 'Payment webhook duplicate detected' : 'Payment webhook processed';

        Log::info($logMessage, [
            'operation' => 'payment_webhook',
            'order_id' => $orderId,
            'idempotency_key' => $idempotencyKey,
            'status' => $status,
            'is_duplicate' => $isDuplicate,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'processing_time_ms' => $processingTime,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
