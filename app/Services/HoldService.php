<?php

namespace App\Services;

use App\Repositories\BaseRepository;
use App\Repositories\HoldRepository;
use App\Traits\HandlesDeadlocks;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class HoldService extends BaseService
{
    use HandlesDeadlocks;
    /**
     * @var HoldRepository
     */
    protected BaseRepository $repository;

    /**
     * HoldService constructor.
     *
     * @param HoldRepository $repository
     * @param ProductService $productService
     */
    public function __construct(
        HoldRepository $repository,
        private ProductService $productService
    ) {
        $this->repository = $repository;
        parent::__construct($repository);
    }

    // ==================== Query Methods ====================

    /**
     * Find holds by product ID.
     *
     * @param int $productId
     * @return Collection
     */
    public function findByProductId(int $productId): Collection
    {
        return $this->repository->findByProductId($productId);
    }

    /**
     * Find active (unused) holds.
     *
     * @return Collection
     */
    public function findActive(): Collection
    {
        return $this->repository->findActive();
    }

    /**
     * Find expired holds.
     *
     * @return Collection
     */
    public function findExpired(): Collection
    {
        return $this->repository->findExpired();
    }

    /**
     * Find hold by ID with pessimistic lock (SELECT ... FOR UPDATE).
     * This locks the hold row to prevent concurrent modifications.
     *
     * @param int $holdId
     * @return Model|null
     */
    public function findWithLockForUpdate(int $holdId): ?Model
    {
        return $this->repository->findWithLockForUpdate($holdId);
    }

    /**
     * Check if hold is valid (not expired and not used).
     *
     * @param int $holdId
     * @return bool
     */
    public function isValid(int $holdId): bool
    {
        $hold = $this->findByIdOrFail($holdId);

        return !$hold->is_used && $hold->expires_at > Carbon::now();
    }

    // ==================== Create Methods ====================

    /**
     * Create a new hold with expiration time.
     * 
     * Sets expiration time and is_used flag, then creates the hold.
     * Cache invalidation is handled by the create() method.
     *
     * @param array $data
     * @param int $expirationMinutes
     * @return Model
     */
    public function createWithExpiration(array $data, int $expirationMinutes = 2): Model
    {
        $data['expires_at'] = Carbon::now()->addMinutes($expirationMinutes);
        $data['is_used'] = false;

        // Use $this->create() to ensure cache invalidation
        return $this->create($data);
    }

    /**
     * Override create to invalidate product cache when a hold is created.
     *
     * @param array $data
     * @return Model
     */
    public function create(array $data): Model
    {
        $hold = parent::create($data);

        $this->invalidateProductCacheIfNeeded($data['product_id'] ?? null);

        return $hold;
    }

    /**
     * Create a hold with validation and pessimistic locking.
     * 
     * Uses database-level row locking (SELECT ... FOR UPDATE) to prevent race conditions
     * and overselling under high concurrent load. The product row is locked before
     * calculating available stock and creating the hold, ensuring only one concurrent
     * request can modify that product's availability at a time.
     * 
     * Includes deadlock retry logic with exponential backoff (up to 3 retries).
     * 
     * Note: This is a blocking mechanism; concurrent requests will wait for the lock
     * to be released. Under high load, requests will be serialized.
     *
     * @param int $productId
     * @param int $quantity
     * @return Model
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
     */
    public function createHoldWithValidation(int $productId, int $quantity): Model
    {
        return $this->executeWithDeadlockRetry(function () use ($productId, $quantity) {
            return DB::transaction(function () use ($productId, $quantity) {
                // Lock the product row to prevent concurrent modifications
                $product = $this->productService->findWithLockForUpdate($productId);

                if (!$product) {
                    abort(404, 'Product not found');
                }

                // Calculate available stock with the locked product
                $availableStock = $this->productService->getAvailableStock($productId);

                if ($availableStock === null || $quantity > $availableStock) {
                    abort(400, 'Insufficient stock available');
                }

                // Create the hold within the same transaction
                // Cache will be invalidated by the create() method
                return $this->createWithExpiration([
                    'product_id' => $productId,
                    'quantity' => $quantity,
                ], 2);
            });
        }, 'create_hold');
    }

    // ==================== Update Methods ====================

    /**
     * Mark hold as used.
     *
     * @param int $holdId
     * @return bool
     */
    public function markAsUsed(int $holdId): bool
    {
        return $this->update($holdId, ['is_used' => true]);
    }

    /**
     * Release hold (mark as unused) and invalidate product cache.
     * 
     * Optimized to fetch hold before updating to avoid duplicate queries.
     *
     * @param int $holdId
     * @return bool
     */
    public function releaseHold(int $holdId): bool
    {
        // Fetch hold before updating to get product_id for cache invalidation
        $hold = $this->findByIdOrFail($holdId);
        $productId = $hold->product_id;

        $result = $this->update($holdId, ['is_used' => false]);

        // Invalidate product cache when hold is released
        $this->invalidateProductCacheIfNeeded($productId);

        return $result;
    }

    // ==================== Batch Operations ====================

    /**
     * Process expired holds (idempotent).
     * 
     * Finds all holds that have expired but haven't been marked as used,
     * marks them as used, and invalidates cache for affected products.
     *
     * @return array Returns count of processed holds and product IDs for cache invalidation
     */
    public function processExpiredHolds(): array
    {
        $expiredHolds = $this->repository->findExpiredUnprocessed();

        if ($expiredHolds->isEmpty()) {
            return [
                'count' => 0,
                'product_ids' => [],
            ];
        }

        $holdIds = $expiredHolds->pluck('id')->toArray();
        $productIds = $expiredHolds->pluck('product_id')->unique()->toArray();

        // Mark as used (idempotent - only updates if is_used is false)
        $updatedCount = $this->repository->markMultipleAsUsed($holdIds);

        // Invalidate cache for affected products (batch operation for efficiency)
        $this->productService->invalidateAvailableStockCacheBatch($productIds);

        return [
            'count' => $updatedCount,
            'product_ids' => $productIds,
        ];
    }

    // ==================== Helper Methods ====================

    /**
     * Invalidate product cache if product ID is provided.
     *
     * @param int|null $productId
     * @return void
     */
    private function invalidateProductCacheIfNeeded(?int $productId): void
    {
        if ($productId !== null) {
            $this->productService->invalidateAvailableStockCache($productId);
        }
    }
}
