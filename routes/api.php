<?php

use App\Http\Controllers\HoldController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

Route::apiResource('/products', ProductController::class)->only(['show']);
Route::apiResource('/holds', HoldController::class)->only(['store']);
Route::apiResource('/orders', OrderController::class)->only(['store']);
Route::post('/payments/webhook', [PaymentController::class, 'webhook']);
