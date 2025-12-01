<?php

namespace App\Repositories;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class OrderRepository extends BaseRepository
{
    /**
     * OrderRepository constructor.
     *
     * @param Order $model
     */
    public function __construct(Order $model)
    {
        parent::__construct($model);
    }

    /**
     * Find orders by status.
     *
     * @param OrderStatus $status
     * @return Collection
     */
    public function findByStatus(OrderStatus $status): Collection
    {
        return $this->model->where('status', $status)->get();
    }

    /**
     * Find order by hold ID.
     *
     * @param int $holdId
     * @return Model|null
     */
    public function findByHoldId(int $holdId): ?Model
    {
        return $this->model->where('hold_id', $holdId)->first();
    }
}
