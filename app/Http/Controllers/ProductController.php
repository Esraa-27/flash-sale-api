<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProductResource;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * ProductController constructor.
     *
     * @param ProductService $productService
     */
    public function __construct(
        private ProductService $productService
    ) {}

    /**
     * Get product by ID with available stock.
     *
     * @param int $id
     * @return ProductResource|JsonResponse
     */
    public function show(int $id)
    {
        $product = $this->productService->getWithAvailableStock($id);

        if (!$product) {
            return response()->json([
                'error' => 'Product not found'
            ], 404);
        }

        return new ProductResource($product);
    }
}
