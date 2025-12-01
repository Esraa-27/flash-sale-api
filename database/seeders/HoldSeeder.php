<?php

namespace Database\Seeders;

use App\Models\Hold;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class HoldSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $product = Product::first();

        if ($product) {
            Hold::create([
                'product_id' => $product->id,
                'quantity' => 1,
                'expires_at' => Carbon::now()->addMinutes(5),
                'is_used' => false,
            ]);
        }
    }
}
