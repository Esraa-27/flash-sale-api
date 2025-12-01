<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Repositories\BaseRepository;
use App\Repositories\OrderRepository;
use App\Services\HoldService;
use App\Traits\HandlesDeadlocks;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OrderService extends BaseService
{
    use HandlesDeadlocks;
    /**
     * @var OrderRepository
     */
    protected BaseRepository $repository;

    /**
     * OrderService constructor.
     *
     * @param OrderRepository $repository
     * @param ProductService $productService
     * @param HoldService $holdService
     */
    public function __construct(
        OrderRepository $repository,
        private ProductService $productService,
        private HoldService $holdService
    ) {
        $this->repository = $repository;
        parent::__construct($repository);
    }

    /**
     * Find orders by status.
     *
     * @param OrderStatus $status
     * @return Collection
     */
    public function findByStatus(OrderStatus $status): Collection
    {
        return $this->repository->findByStatus($status);
    }

    /**
     * Find order by hold ID.
     *
     * @param int $holdId
     * @return Model|null
     */
    public function findByHoldId(int $holdId): ?Model
    {
        return $this->repository->findByHoldId($holdId);
    }

    /**
     * Create a new order with pending status.
     *
     * @param array $data
     * @return Model
     */
    public function createPending(array $data): Model
    {
        $data['status'] = OrderStatus::PENDING;
        return $this->create($data);
    }

    /**
     * Mark order as paid.
     *
     * @param int $orderId
     * @return bool
     */
    public function markAsPaid(int $orderId): bool
    {
        $order = $this->findByIdOrFail($orderId);
        $result = $this->update($orderId, ['status' => OrderStatus::PAID]);

        // Invalidate product cache when order is completed (paid)
        if ($result && $order->hold && $order->hold->product_id) {
            $this->productService->invalidateAvailableStockCache($order->hold->product_id);
        }

        return $result;
    }

    /**
     * Cancel an order.
     *
     * @param int $orderId
     * @return bool
     */
    public function cancel(int $orderId): bool
    {
        return $this->update($orderId, ['status' => OrderStatus::CANCELLED]);
    }

    /**
     * Get pending orders.
     *
     * @return Collection
     */
    public function getPending(): Collection
    {
        return $this->findByStatus(OrderStatus::PENDING);
    }

    /**
     * Get paid orders.
     *
     * @return Collection
     */
    public function getPaid(): Collection
    {
        return $this->findByStatus(OrderStatus::PAID);
    }

    /**
     * Get cancelled orders.
     *
     * @return Collection
     */
    public function getCancelled(): Collection
    {
        return $this->findByStatus(OrderStatus::CANCELLED);
    }

    /**
     * Validate hold exists.
     *
     * @param int $holdId
     * @return void
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function validateHoldExists(int $holdId): void
    {
        $hold = $this->holdService->findById($holdId);

        if (!$hold) {
            abort(404, 'Hold not found');
        }
    }

    /**
     * Validate hold is not expired.
     *
     * @param int $holdId
     * @return void
     * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
     */
    public function validateHoldNotExpired(int $holdId): void
    {
        $hold = $this->holdService->findByIdOrFail($holdId);

        if ($hold->expires_at <= Carbon::now()) {
            abort(400, 'Hold has expired');
        }
    }

    /**
     * Validate hold is not already used.
     *
     * @param int $holdId
     * @return void
     * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
     */
    public function validateHoldNotUsed(int $holdId): void
    {
        $hold = $this->holdService->findByIdOrFail($holdId);

        if ($hold->is_used) {
            abort(400, 'Hold has already been used');
        }
    }

    /**
     * Create order from hold with validation and pessimistic locking.
     * 
     * Uses database-level row locking (SELECT ... FOR UPDATE) on the hold row to prevent
     * the same hold from being used twice concurrently. The hold row is locked before
     * validation and order creation, ensuring only one concurrent request can process
     * a specific hold at a time.
     * 
     * Includes deadlock retry logic with exponential backoff (up to 3 retries).
     * 
     * Note: This is a blocking mechanism; concurrent requests will wait for the lock
     * to be released. Under high load, requests will be serialized.
     *
     * @param int $holdId
     * @return Model
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
     */
    public function createOrderFromHold(int $holdId): Model
    {
        return $this->executeWithDeadlockRetry(function () use ($holdId) {
            return DB::transaction(function () use ($holdId) {
                // Lock the hold row to prevent concurrent modifications
                $hold = $this->holdService->findWithLockForUpdate($holdId);

                if (!$hold) {
                    abort(404, 'Hold not found');
                }

                // Validate hold is not expired
                if ($hold->expires_at <= Carbon::now()) {
                    abort(400, 'Hold has expired');
                }

                // Validate hold is not already used
                if ($hold->is_used) {
                    abort(400, 'Hold has already been used');
                }

                // Create order with pending status
                $order = $this->createPending([
                    'hold_id' => $holdId,
                ]);

                // Mark hold as used
                $this->holdService->markAsUsed($holdId);

                // Invalidate product cache
                if ($hold->product_id) {
                    $this->productService->invalidateAvailableStockCache($hold->product_id);
                }

                return $order;
            });
        }, 'create_order');
    }
}
