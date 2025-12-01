<?php

namespace Database\Factories;

use App\Models\Hold;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Hold>
 */
class HoldFactory extends Factory
{
    protected $model = Hold::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'quantity' => fake()->numberBetween(1, 10),
            'expires_at' => Carbon::now()->addMinutes(5),
            'is_used' => false,
        ];
    }
}
