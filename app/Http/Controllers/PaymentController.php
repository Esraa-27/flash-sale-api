<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaymentWebhookRequest;
use App\Services\PaymentWebhookService;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    public function __construct(
        private PaymentWebhookService $webhookService
    ) {}

    public function webhook(PaymentWebhookRequest $request): JsonResponse
    {
        $data = $request->validated();

        $result = $this->webhookService->processWebhook(
            $data['order_id'],
            $data['idempotency_key'],
            $data['status']
        );

        return response()->json($result, 200);
    }
}
