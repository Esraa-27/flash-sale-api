<?php

namespace Database\Seeders;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PaymentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $order = Order::first();

        if ($order) {
            Payment::create([
                'order_id' => $order->id,
                'idempotency_key' => Str::uuid()->toString(),
                'status' => PaymentStatus::SUCCESS,
            ]);
        }
    }
}
