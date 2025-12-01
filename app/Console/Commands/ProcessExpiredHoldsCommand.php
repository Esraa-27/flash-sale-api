<?php

namespace App\Console\Commands;

use App\Services\HoldService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessExpiredHoldsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'holds:process-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process expired holds and mark them as used';

    /**
     * Execute the console command.
     */
    public function handle(HoldService $holdService): int
    {
        $this->info('Starting expired holds processing...');
        Log::info('Expired holds handler started');

        try {
            $result = $holdService->processExpiredHolds();

            $count = $result['count'];
            $productIds = $result['product_ids'];

            if ($count > 0) {
                $this->info("Processed {$count} expired hold(s)");
                $this->info("Invalidated cache for " . count($productIds) . " product(s)");

                Log::info('Hold expiry processed', [
                    'operation' => 'process_expired_holds',
                    'count' => $count,
                    'product_ids' => $productIds,
                    'timestamp' => now()->toIso8601String(),
                ]);
            } else {
                $this->info('No expired holds to process');
                Log::info('Hold expiry check completed', [
                    'operation' => 'process_expired_holds',
                    'count' => 0,
                    'timestamp' => now()->toIso8601String(),
                ]);
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error processing expired holds: ' . $e->getMessage());
            Log::error('Error processing expired holds', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return self::FAILURE;
        }
    }
}
