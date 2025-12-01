<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateOrderRequest;
use App\Http\Resources\OrderResource;
use App\Services\HoldService;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    public function __construct(
        private OrderService $orderService,
        private HoldService $holdService
    ) {}

    public function store(CreateOrderRequest $request): JsonResponse
    {
        $data = $request->validated();
        $holdId = $data['hold_id'];

        // Get hold status before request
        $hold = $this->holdService->findById($holdId);
        $holdStatus = $hold ? [
            'is_used' => $hold->is_used,
            'expires_at' => $hold->expires_at?->toIso8601String(),
            'is_expired' => $hold->expires_at && $hold->expires_at <= now(),
        ] : null;

        try {
            $order = $this->orderService->createOrderFromHold($holdId);

            Log::info('Order created successfully', [
                'operation' => 'create_order',
                'hold_id' => $holdId,
                'order_id' => $order->id,
                'hold_status_before' => $holdStatus,
                'result' => 'success',
                'timestamp' => now()->toIso8601String(),
            ]);

            return (new OrderResource($order))->response()->setStatusCode(201);
        } catch (\Exception $e) {
            Log::warning('Order creation failed', [
                'operation' => 'create_order',
                'hold_id' => $holdId,
                'hold_status' => $holdStatus,
                'result' => 'failure',
                'error' => $e->getMessage(),
                'status_code' => $e->getCode() ?: 400,
                'timestamp' => now()->toIso8601String(),
            ]);

            throw $e;
        }
    }
}
