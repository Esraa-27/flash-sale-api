<?php

namespace App\Repositories;

use App\Models\Hold;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class HoldRepository extends BaseRepository
{
    /**
     * HoldRepository constructor.
     *
     * @param Hold $model
     */
    public function __construct(Hold $model)
    {
        parent::__construct($model);
    }

    /**
     * Find holds by product ID.
     *
     * @param int $productId
     * @return Collection
     */
    public function findByProductId(int $productId): Collection
    {
        return $this->model->where('product_id', $productId)->get();
    }

    /**
     * Find active (unused) holds.
     *
     * @return Collection
     */
    public function findActive(): Collection
    {
        return $this->model->where('is_used', false)
            ->where('expires_at', '>', Carbon::now())
            ->get();
    }

    /**
     * Find expired holds.
     *
     * @return Collection
     */
    public function findExpired(): Collection
    {
        return $this->model->where('expires_at', '<=', Carbon::now())
            ->orWhere('is_used', true)
            ->get();
    }

    /**
     * Find expired holds that need processing (not yet marked as used).
     *
     * @return Collection
     */
    public function findExpiredUnprocessed(): Collection
    {
        return $this->model->where('expires_at', '<=', Carbon::now())
            ->where('is_used', false)
            ->get();
    }

    /**
     * Mark multiple holds as used (idempotent operation).
     *
     * @param array $holdIds
     * @return int Number of holds updated
     */
    public function markMultipleAsUsed(array $holdIds): int
    {
        return $this->model->whereIn('id', $holdIds)
            ->where('is_used', false)
            ->update(['is_used' => true]);
    }

    /**
     * Find hold by ID with pessimistic lock (SELECT ... FOR UPDATE).
     * This locks the hold row to prevent concurrent modifications.
     *
     * @param int $id
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function findWithLockForUpdate(int $id): ?\Illuminate\Database\Eloquent\Model
    {
        return $this->model->where('id', $id)->lockForUpdate()->first();
    }
}
