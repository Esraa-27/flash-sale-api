<?php

namespace Tests\Feature;

use App\Models\Hold;
use App\Models\Product;
use App\Services\HoldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ProcessExpiredHoldsTest extends TestCase
{
    use RefreshDatabase;

    private HoldService $holdService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->holdService = app(HoldService::class);
    }

    public function test_it_processes_expired_holds_successfully()
    {
        // Arrange: Create product and expired holds
        $product = Product::factory()->create(['stock' => 100]);

        $expiredHold1 = Hold::factory()->create([
            'product_id' => $product->id,
            'quantity' => 5,
            'expires_at' => Carbon::now()->subMinutes(10),
            'is_used' => false,
        ]);

        $expiredHold2 = Hold::factory()->create([
            'product_id' => $product->id,
            'quantity' => 3,
            'expires_at' => Carbon::now()->subMinutes(5),
            'is_used' => false,
        ]);

        // Act: Process expired holds
        $result = $this->holdService->processExpiredHolds();

        // Assert: Both holds should be marked as used
        $this->assertEquals(2, $result['count']);
        $this->assertContains($product->id, $result['product_ids']);

        $this->assertTrue($expiredHold1->fresh()->is_used);
        $this->assertTrue($expiredHold2->fresh()->is_used);
    }

    public function test_it_does_not_process_active_holds()
    {
        // Arrange: Create product with active and expired holds
        $product = Product::factory()->create(['stock' => 100]);

        $activeHold = Hold::factory()->create([
            'product_id' => $product->id,
            'quantity' => 5,
            'expires_at' => Carbon::now()->addMinutes(5),
            'is_used' => false,
        ]);

        $expiredHold = Hold::factory()->create([
            'product_id' => $product->id,
            'quantity' => 3,
            'expires_at' => Carbon::now()->subMinutes(5),
            'is_used' => false,
        ]);

        // Act: Process expired holds
        $result = $this->holdService->processExpiredHolds();

        // Assert: Only expired hold should be processed
        $this->assertEquals(1, $result['count']);
        $this->assertFalse($activeHold->fresh()->is_used);
        $this->assertTrue($expiredHold->fresh()->is_used);
    }

    public function test_it_does_not_process_already_used_holds()
    {
        // Arrange: Create expired hold that's already used
        $product = Product::factory()->create(['stock' => 100]);

        $usedHold = Hold::factory()->create([
            'product_id' => $product->id,
            'quantity' => 5,
            'expires_at' => Carbon::now()->subMinutes(10),
            'is_used' => true,
        ]);

        // Act: Process expired holds
        $result = $this->holdService->processExpiredHolds();

        // Assert: No holds should be processed
        $this->assertEquals(0, $result['count']);
        $this->assertTrue($usedHold->fresh()->is_used);
    }

    public function test_it_returns_zero_when_no_expired_holds_exist()
    {
        // Arrange: Create only active holds
        $product = Product::factory()->create(['stock' => 100]);

        Hold::factory()->create([
            'product_id' => $product->id,
            'quantity' => 5,
            'expires_at' => Carbon::now()->addMinutes(5),
            'is_used' => false,
        ]);

        // Act: Process expired holds
        $result = $this->holdService->processExpiredHolds();

        // Assert: No holds should be processed
        $this->assertEquals(0, $result['count']);
        $this->assertEmpty($result['product_ids']);
    }

    public function test_it_is_idempotent_when_run_multiple_times()
    {
        // Arrange: Create expired hold
        $product = Product::factory()->create(['stock' => 100]);

        $expiredHold = Hold::factory()->create([
            'product_id' => $product->id,
            'quantity' => 5,
            'expires_at' => Carbon::now()->subMinutes(10),
            'is_used' => false,
        ]);

        // Act: Run process multiple times
        $result1 = $this->holdService->processExpiredHolds();
        $result2 = $this->holdService->processExpiredHolds();
        $result3 = $this->holdService->processExpiredHolds();

        // Assert: Should only process once
        $this->assertEquals(1, $result1['count']);
        $this->assertEquals(0, $result2['count']);
        $this->assertEquals(0, $result3['count']);
        $this->assertTrue($expiredHold->fresh()->is_used);
    }

    public function test_it_invalidates_cache_for_affected_products()
    {
        // Arrange: Create products and expired holds
        $product1 = Product::factory()->create(['stock' => 100]);
        $product2 = Product::factory()->create(['stock' => 50]);

        // Set cache for products (using correct cache key format: product_{id}_available_stock)
        Cache::put("product_{$product1->id}_available_stock", ['available_stock' => 90], 10);
        Cache::put("product_{$product2->id}_available_stock", ['available_stock' => 45], 10);

        Hold::factory()->create([
            'product_id' => $product1->id,
            'quantity' => 5,
            'expires_at' => Carbon::now()->subMinutes(10),
            'is_used' => false,
        ]);

        Hold::factory()->create([
            'product_id' => $product2->id,
            'quantity' => 3,
            'expires_at' => Carbon::now()->subMinutes(5),
            'is_used' => false,
        ]);

        // Act: Process expired holds
        $result = $this->holdService->processExpiredHolds();

        // Assert: Cache should be invalidated (using correct cache key format)
        $this->assertFalse(Cache::has("product_{$product1->id}_available_stock"));
        $this->assertFalse(Cache::has("product_{$product2->id}_available_stock"));
        $this->assertCount(2, $result['product_ids']);
    }

    public function test_it_handles_multiple_products_correctly()
    {
        // Arrange: Create multiple products with expired holds
        $product1 = Product::factory()->create(['stock' => 100]);
        $product2 = Product::factory()->create(['stock' => 50]);
        $product3 = Product::factory()->create(['stock' => 75]);

        Hold::factory()->create([
            'product_id' => $product1->id,
            'quantity' => 5,
            'expires_at' => Carbon::now()->subMinutes(10),
            'is_used' => false,
        ]);

        Hold::factory()->create([
            'product_id' => $product2->id,
            'quantity' => 3,
            'expires_at' => Carbon::now()->subMinutes(5),
            'is_used' => false,
        ]);

        Hold::factory()->create([
            'product_id' => $product3->id,
            'quantity' => 2,
            'expires_at' => Carbon::now()->subMinutes(2),
            'is_used' => false,
        ]);

        // Act: Process expired holds
        $result = $this->holdService->processExpiredHolds();

        // Assert: All holds should be processed
        $this->assertEquals(3, $result['count']);
        $this->assertCount(3, $result['product_ids']);
        $this->assertContains($product1->id, $result['product_ids']);
        $this->assertContains($product2->id, $result['product_ids']);
        $this->assertContains($product3->id, $result['product_ids']);
    }

    public function test_it_handles_holds_expiring_exactly_at_current_time()
    {
        // Arrange: Create hold expiring exactly now
        $product = Product::factory()->create(['stock' => 100]);

        $expiredHold = Hold::factory()->create([
            'product_id' => $product->id,
            'quantity' => 5,
            'expires_at' => Carbon::now(),
            'is_used' => false,
        ]);

        // Act: Process expired holds
        $result = $this->holdService->processExpiredHolds();

        // Assert: Hold should be processed
        $this->assertEquals(1, $result['count']);
        $this->assertTrue($expiredHold->fresh()->is_used);
    }
}
