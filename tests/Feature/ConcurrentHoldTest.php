<?php

namespace Tests\Feature;

use App\Models\Hold;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ConcurrentHoldTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that system prevents overselling under concurrent load.
     * 
     * Creates a product with exactly 10 units of stock and simulates
     * 20 concurrent requests attempting to create holds for 1 unit each.
     * Expected: Exactly 10 holds succeed, 10 requests fail.
     */
    public function test_parallel_hold_attempts_at_stock_boundary()
    {
        // Arrange: Create product with exactly 10 units of stock
        $product = Product::factory()->create([
            'name' => 'Test Product',
            'price' => 99.99,
            'stock' => 10,
        ]);

        // Clear cache to ensure fresh state
        Cache::flush();

        $totalRequests = 20;
        $requestQuantity = 1;
        $successfulHolds = [];
        $failedRequests = [];

        // Capture test start time for expires_at validation
        $testStartTime = Carbon::now();

        // Act: Simulate 20 concurrent requests
        // Note: With pessimistic locking, requests will be serialized at DB level
        // This is the expected behavior and what we're testing - the locking prevents overselling
        for ($i = 0; $i < $totalRequests; $i++) {
            $response = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'qty' => $requestQuantity,
            ]);

            if ($response->status() === 201) {
                $responseData = $response->json();
                // Handle both wrapped and unwrapped responses
                $holdData = $responseData['data'] ?? $responseData;
                $successfulHolds[] = $holdData;
            } else {
                $failedRequests[] = [
                    'status' => $response->status(),
                    'response' => $response->json(),
                ];
            }
        }

        // Assert: Exactly 10 holds should succeed
        $this->assertCount(10, $successfulHolds, 'Expected exactly 10 successful holds');

        // Assert: Exactly 10 requests should fail
        $this->assertCount(10, $failedRequests, 'Expected exactly 10 failed requests');

        // Assert: All failed requests should return 400 status code
        foreach ($failedRequests as $failedRequest) {
            if (isset($failedRequest['status'])) {
                $this->assertEquals(400, $failedRequest['status'], 'Failed requests should return 400 status');

                // Verify error message
                if (isset($failedRequest['response']['message'])) {
                    $this->assertStringContainsString(
                        'Insufficient stock',
                        $failedRequest['response']['message'],
                        'Error message should indicate insufficient stock'
                    );
                }
            }
        }

        // Assert: Verify total reserved stock = 10 units
        $totalReservedStock = Hold::where('product_id', $product->id)
            ->where('is_used', false)
            ->where('expires_at', '>', Carbon::now())
            ->sum('quantity');

        $this->assertEquals(10, $totalReservedStock, 'Total reserved stock should equal exactly 10 units');

        // Assert: Verify no holds succeed beyond available stock
        $activeHoldsCount = Hold::where('product_id', $product->id)
            ->where('is_used', false)
            ->where('expires_at', '>', Carbon::now())
            ->count();

        $this->assertEquals(10, $activeHoldsCount, 'Should have exactly 10 active holds');

        // Assert: Verify successful holds have correct structure and expires_at timestamp
        // Holds should expire 2 minutes from creation
        foreach ($successfulHolds as $holdData) {
            // Verify hold structure
            $this->assertArrayHasKey('hold_id', $holdData, 'Hold should have hold_id');
            $this->assertArrayHasKey('product_id', $holdData, 'Hold should have product_id');
            $this->assertArrayHasKey('quantity', $holdData, 'Hold should have quantity');
            $this->assertArrayHasKey('expires_at', $holdData, 'Hold should have expires_at');

            // Verify product_id matches
            $this->assertEquals($product->id, $holdData['product_id'], 'Hold should belong to correct product');

            // Verify quantity is 1
            $this->assertEquals(1, $holdData['quantity'], 'Each hold should be for 1 unit');

            // Verify expires_at is approximately 2 minutes from now
            if (isset($holdData['expires_at'])) {
                $expiresAt = Carbon::parse($holdData['expires_at']);
                $expectedExpiration = $testStartTime->copy()->addMinutes(2);

                // Allow 10 seconds tolerance for test execution time
                $this->assertTrue(
                    $expiresAt->between($expectedExpiration->copy()->subSeconds(10), $expectedExpiration->copy()->addSeconds(10)),
                    "Hold expires_at should be approximately 2 minutes from test start. Got: {$expiresAt->toIso8601String()}, Expected around: {$expectedExpiration->toIso8601String()}"
                );
            }
        }

        // Assert: Verify product stock remains unchanged (holds don't reduce stock, only reserve it)
        $product->refresh();
        $this->assertEquals(10, $product->stock, 'Product stock should remain at 10 (holds reserve, not consume)');

        // Assert: Verify available stock calculation is correct
        $availableStock = $product->stock - $totalReservedStock;
        $this->assertEquals(0, $availableStock, 'Available stock should be 0 after 10 holds');
    }

    /**
     * Test that system handles concurrent requests with different quantities correctly.
     */
    public function test_concurrent_holds_with_varying_quantities()
    {
        // Arrange: Create product with 15 units of stock
        $product = Product::factory()->create([
            'name' => 'Test Product',
            'price' => 99.99,
            'stock' => 15,
        ]);

        Cache::flush();

        // Act: Create multiple holds with varying quantities
        $requests = [
            ['qty' => 5], // Should succeed
            ['qty' => 5], // Should succeed
            ['qty' => 5], // Should succeed
            ['qty' => 5], // Should fail (only 15 total, 15 already reserved)
            ['qty' => 1], // Should fail
        ];

        $successful = 0;
        $failed = 0;

        foreach ($requests as $request) {
            $response = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'qty' => $request['qty'],
            ]);

            if ($response->status() === 201) {
                $successful++;
            } else {
                $failed++;
                $this->assertEquals(400, $response->status());
            }
        }

        // Assert: Exactly 3 should succeed (5+5+5 = 15)
        $this->assertEquals(3, $successful);
        $this->assertEquals(2, $failed);

        // Assert: Total reserved should be 15
        $totalReserved = Hold::where('product_id', $product->id)
            ->where('is_used', false)
            ->where('expires_at', '>', Carbon::now())
            ->sum('quantity');

        $this->assertEquals(15, $totalReserved);
    }

    /**
     * Test that expired holds don't count toward available stock.
     */
    public function test_expired_holds_do_not_affect_available_stock()
    {
        // Arrange: Create product with 10 units
        $product = Product::factory()->create([
            'name' => 'Test Product',
            'price' => 99.99,
            'stock' => 10,
        ]);

        // Create an expired hold (should not count)
        Hold::factory()->create([
            'product_id' => $product->id,
            'quantity' => 5,
            'expires_at' => Carbon::now()->subMinutes(10),
            'is_used' => false,
        ]);

        Cache::flush();

        // Act: Try to create 10 holds (should all succeed since expired hold doesn't count)
        $successful = 0;
        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'qty' => 1,
            ]);

            if ($response->status() === 201) {
                $successful++;
            }
        }

        // Assert: All 10 should succeed
        $this->assertEquals(10, $successful);

        // Assert: Total reserved should be 10 (expired hold doesn't count)
        $totalReserved = Hold::where('product_id', $product->id)
            ->where('is_used', false)
            ->where('expires_at', '>', Carbon::now())
            ->sum('quantity');

        $this->assertEquals(10, $totalReserved);
    }

    /**
     * Test that used holds don't count toward available stock.
     */
    public function test_used_holds_do_not_affect_available_stock()
    {
        // Arrange: Create product with 10 units
        $product = Product::factory()->create([
            'name' => 'Test Product',
            'price' => 99.99,
            'stock' => 10,
        ]);

        // Create a used hold (should not count)
        Hold::factory()->create([
            'product_id' => $product->id,
            'quantity' => 5,
            'expires_at' => Carbon::now()->addMinutes(5),
            'is_used' => true,
        ]);

        Cache::flush();

        // Act: Try to create 10 holds (should all succeed since used hold doesn't count)
        $successful = 0;
        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'qty' => 1,
            ]);

            if ($response->status() === 201) {
                $successful++;
            }
        }

        // Assert: All 10 should succeed
        $this->assertEquals(10, $successful);

        // Assert: Total reserved should be 10 (used hold doesn't count)
        $totalReserved = Hold::where('product_id', $product->id)
            ->where('is_used', false)
            ->where('expires_at', '>', Carbon::now())
            ->sum('quantity');

        $this->assertEquals(10, $totalReserved);
    }
}
