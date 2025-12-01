<?php

namespace App\Repositories;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class PaymentRepository extends BaseRepository
{
    /**
     * PaymentRepository constructor.
     *
     * @param Payment $model
     */
    public function __construct(Payment $model)
    {
        parent::__construct($model);
    }

    /**
     * Find payments by status.
     *
     * @param PaymentStatus $status
     * @return Collection
     */
    public function findByStatus(PaymentStatus $status): Collection
    {
        return $this->model->where('status', $status)->get();
    }

    /**
     * Find payment by order ID.
     *
     * @param int $orderId
     * @return Model|null
     */
    public function findByOrderId(int $orderId): ?Model
    {
        return $this->model->where('order_id', $orderId)->first();
    }

    /**
     * Find payment by idempotency key.
     *
     * @param string $idempotencyKey
     * @return Model|null
     */
    public function findByIdempotencyKey(string $idempotencyKey): ?Model
    {
        return $this->model->where('idempotency_key', $idempotencyKey)->first();
    }
}
