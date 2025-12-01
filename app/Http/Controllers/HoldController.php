<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateHoldRequest;
use App\Http\Resources\HoldResource;
use App\Services\HoldService;
use App\Services\MetricsService;
use App\Services\ProductService;
use Illuminate\Support\Facades\Log;

class HoldController extends Controller
{
    public function __construct(
        private HoldService $holdService,
        private ProductService $productService,
        private MetricsService $metricsService
    ) {}

    public function store(CreateHoldRequest $request): HoldResource
    {
        $startTime = microtime(true);
        $data = $request->validated();
        $productId = $data['product_id'];
        $quantity = $data['qty'];

        // Get available stock before request
        $availableStockBefore = $this->productService->getAvailableStock($productId);

        try {
            $hold = $this->holdService->createHoldWithValidation($productId, $quantity);

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);
            $this->metricsService->recordHoldCreationTime($processingTime);

            Log::info('Hold created successfully', [
                'operation' => 'create_hold',
                'product_id' => $productId,
                'quantity' => $quantity,
                'hold_id' => $hold->id,
                'available_stock_before' => $availableStockBefore,
                'available_stock_after' => $this->productService->getAvailableStock($productId),
                'result' => 'success',
                'processing_time_ms' => $processingTime,
                'timestamp' => now()->toIso8601String(),
            ]);

            return new HoldResource($hold);
        } catch (\Exception $e) {
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::warning('Hold creation failed', [
                'operation' => 'create_hold',
                'product_id' => $productId,
                'quantity' => $quantity,
                'available_stock_before' => $availableStockBefore,
                'result' => 'failure',
                'error' => $e->getMessage(),
                'status_code' => $e->getCode() ?: 400,
                'processing_time_ms' => $processingTime,
                'timestamp' => now()->toIso8601String(),
            ]);

            throw $e;
        }
    }
}
