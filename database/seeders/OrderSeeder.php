<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Models\Hold;
use App\Models\Order;
use Illuminate\Database\Seeder;

class OrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $hold = Hold::first();

        if ($hold) {
            Order::create([
                'hold_id' => $hold->id,
                'status' => OrderStatus::PENDING,
            ]);
        }
    }
}
