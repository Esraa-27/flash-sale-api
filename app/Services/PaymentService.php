<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Repositories\BaseRepository;
use App\Repositories\PaymentRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PaymentService extends BaseService
{
    /**
     * @var PaymentRepository
     */
    protected BaseRepository $repository;

    /**
     * PaymentService constructor.
     *
     * @param PaymentRepository $repository
     */
    public function __construct(PaymentRepository $repository)
    {
        $this->repository = $repository;
        parent::__construct($repository);
    }

    /**
     * Find payments by status.
     *
     * @param PaymentStatus $status
     * @return Collection
     */
    public function findByStatus(PaymentStatus $status): Collection
    {
        return $this->repository->findByStatus($status);
    }

    /**
     * Find payment by order ID.
     *
     * @param int $orderId
     * @return Model|null
     */
    public function findByOrderId(int $orderId): ?Model
    {
        return $this->repository->findByOrderId($orderId);
    }

    /**
     * Find payment by idempotency key.
     *
     * @param string $idempotencyKey
     * @return Model|null
     */
    public function findByIdempotencyKey(string $idempotencyKey): ?Model
    {
        return $this->repository->findByIdempotencyKey($idempotencyKey);
    }

    /**
     * Create a new payment with idempotency key.
     *
     * @param array $data
     * @param string|null $idempotencyKey
     * @return Model
     */
    public function createWithIdempotency(array $data, ?string $idempotencyKey = null): Model
    {
        $data['idempotency_key'] = $idempotencyKey ?? Str::uuid()->toString();

        return $this->create($data);
    }

    /**
     * Mark payment as successful.
     *
     * @param int $paymentId
     * @return bool
     */
    public function markAsSuccess(int $paymentId): bool
    {
        return $this->update($paymentId, ['status' => PaymentStatus::SUCCESS]);
    }

    /**
     * Mark payment as failed.
     *
     * @param int $paymentId
     * @return bool
     */
    public function markAsFailed(int $paymentId): bool
    {
        return $this->update($paymentId, ['status' => PaymentStatus::FAILED]);
    }

    /**
     * Get successful payments.
     *
     * @return Collection
     */
    public function getSuccessful(): Collection
    {
        return $this->findByStatus(PaymentStatus::SUCCESS);
    }

    /**
     * Get failed payments.
     *
     * @return Collection
     */
    public function getFailed(): Collection
    {
        return $this->findByStatus(PaymentStatus::FAILED);
    }
}
