<?php

namespace App\Services;

use App\Repositories\BaseRepository;
use App\Repositories\ProductRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class ProductService extends BaseService
{
    /**
     * @var ProductRepository
     */
    protected BaseRepository $repository;
    /**
     * ProductService constructor.
     *
     * @param ProductRepository $repository
     */
    public function __construct(ProductRepository $repository)
    {
        $this->repository = $repository;
        parent::__construct($repository);
    }

    /**
     * Get metrics service instance.
     *
     * @return MetricsService
     */
    private function getMetricsService(): MetricsService
    {
        return app(MetricsService::class);
    }

    /**
     * Find products by name.
     *
     * @param string $name
     * @return Collection
     */
    public function findByName(string $name): Collection
    {
        return $this->repository->findByName($name);
    }

    /**
     * Find products with stock greater than zero.
     *
     * @return Collection
     */
    public function findInStock(): Collection
    {
        return $this->repository->findInStock();
    }

    /**
     * Check if product has sufficient stock.
     *
     * @param int $productId
     * @param int $quantity
     * @return bool
     */
    public function hasSufficientStock(int $productId, int $quantity): bool
    {
        $product = $this->findByIdOrFail($productId);
        return $product->stock >= $quantity;
    }

    /**
     * Decrease product stock.
     *
     * @param int $productId
     * @param int $quantity
     * @return bool
     */
    public function decreaseStock(int $productId, int $quantity): bool
    {
        $product = $this->findByIdOrFail($productId);
        $newStock = $product->stock - $quantity;

        if ($newStock < 0) {
            return false;
        }

        return $this->update($productId, ['stock' => $newStock]);
    }

    /**
     * Increase product stock.
     *
     * @param int $productId
     * @param int $quantity
     * @return bool
     */
    public function increaseStock(int $productId, int $quantity): bool
    {
        $product = $this->findByIdOrFail($productId);
        $newStock = $product->stock + $quantity;

        return $this->update($productId, ['stock' => $newStock]);
    }

    /**
     * Get product with available stock calculation (with caching).
     * 
     * Caches the result for 5-10 seconds to improve read performance.
     * Falls back to direct database query if cache operations fail.
     *
     * @param int $id
     * @return array|null
     */
    public function getWithAvailableStock(int $id): ?array
    {
        $cacheKey = $this->getAvailableStockCacheKey($id);
        $cacheDuration = 10; // seconds (configurable: 5-10 seconds)

        try {
            // Check if cache exists
            if (cache()->has($cacheKey)) {
                $this->getMetricsService()->recordCacheHit();
                return cache()->get($cacheKey);
            }

            // Cache miss - record and fetch from database
            $this->getMetricsService()->recordCacheMiss();

            $result = $this->repository->findWithAvailableStock($id);

            // Store in cache
            cache()->put($cacheKey, $result, $cacheDuration);

            return $result;
        } catch (\Exception $e) {
            // Log cache failure and fall back to direct database query
            Log::warning('Cache operation failed for available stock', [
                'operation' => 'get_available_stock_cache',
                'product_id' => $id,
                'cache_key' => $cacheKey,
                'error' => $e->getMessage(),
                'fallback' => 'direct_database_query',
                'timestamp' => now()->toIso8601String(),
            ]);

            // Fall back to direct database query
            return $this->repository->findWithAvailableStock($id);
        }
    }

    /**
     * Get available stock for a product without cache (for real-time validation).
     * 
     * This method bypasses cache to ensure real-time accuracy during critical operations
     * like hold creation validation.
     *
     * @param int $productId
     * @return int|null Returns null if product doesn't exist
     */
    public function getAvailableStock(int $productId): ?int
    {
        try {
            $productData = $this->repository->findWithAvailableStock($productId);

            if (!$productData) {
                return null;
            }

            return $productData['available_stock'];
        } catch (\Exception $e) {
            Log::error('Failed to get available stock from database', [
                'operation' => 'get_available_stock',
                'product_id' => $productId,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ]);

            throw $e;
        }
    }

    /**
     * Invalidate product available stock cache.
     * 
     * Called immediately after events that change stock availability:
     * - New hold created
     * - Hold expires
     * - Order cancelled and hold released
     * - Order marked as paid
     * 
     * If cache invalidation fails, logs the error but doesn't break the operation.
     *
     * @param int $productId
     * @return void
     */
    public function invalidateAvailableStockCache(int $productId): void
    {
        $cacheKey = $this->getAvailableStockCacheKey($productId);

        try {
            cache()->forget($cacheKey);
        } catch (\Exception $e) {
            // Log cache failure but don't break the operation
            Log::warning('Failed to invalidate available stock cache', [
                'operation' => 'invalidate_cache',
                'product_id' => $productId,
                'cache_key' => $cacheKey,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ]);
        }
    }

    /**
     * Invalidate available stock cache for multiple products (batch operation).
     * 
     * More efficient than calling invalidateAvailableStockCache() multiple times.
     * Used when processing expired holds that affect multiple products.
     * 
     * If cache invalidation fails for any product, logs the error but continues
     * with remaining products.
     *
     * @param array $productIds
     * @return void
     */
    public function invalidateAvailableStockCacheBatch(array $productIds): void
    {
        if (empty($productIds)) {
            return;
        }

        $failed = [];

        foreach ($productIds as $productId) {
            try {
                $this->invalidateAvailableStockCache($productId);
            } catch (\Exception $e) {
                $failed[] = $productId;
                Log::warning('Failed to invalidate cache in batch operation', [
                    'operation' => 'invalidate_cache_batch',
                    'product_id' => $productId,
                    'error' => $e->getMessage(),
                    'timestamp' => now()->toIso8601String(),
                ]);
            }
        }

        if (!empty($failed)) {
            Log::warning('Some products failed cache invalidation in batch', [
                'operation' => 'invalidate_cache_batch',
                'total_products' => count($productIds),
                'failed_products' => $failed,
                'failed_count' => count($failed),
                'timestamp' => now()->toIso8601String(),
            ]);
        }
    }

    /**
     * Get the cache key for product available stock.
     * 
     * Format: product_{id}_available_stock
     *
     * @param int $productId
     * @return string
     */
    private function getAvailableStockCacheKey(int $productId): string
    {
        return "product_{$productId}_available_stock";
    }

    /**
     * Find product by ID with pessimistic lock (SELECT ... FOR UPDATE).
     * This locks the product row to prevent concurrent modifications.
     *
     * @param int $productId
     * @return Model|null
     */
    public function findWithLockForUpdate(int $productId): ?Model
    {
        return $this->repository->findWithLockForUpdate($productId);
    }
}
