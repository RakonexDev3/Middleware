<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ERPNext\SalesOrderService;
use Illuminate\Support\Facades\Log;

class SyncShopifyOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:shopify-orders {--limit=50 : Maximum number of orders to fetch from Shopify}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch new and unsynced orders from Shopify and create Sales Orders in ERPNext';

    /**
     * Execute the console command.
     */
    public function handle(SalesOrderService $salesOrderService): int
    {
        $limit = (int) $this->option('limit') ?: 50;
        $this->info("Checking for new orders from Shopify (limit: {$limit})...");

        try {
            $result = $salesOrderService->syncUnsyncedOrdersFromShopify(limit: $limit);

            if (!($result['success'] ?? false)) {
                $errorMessage = $result['message'] ?? 'Unknown error occurred during order sync.';
                $this->warn("Shopify order sync notice: {$errorMessage}");
                Log::warning("sync:shopify-orders: {$errorMessage}");
                return Command::SUCCESS;
            }

            $syncedCount = $result['synced_count'] ?? 0;
            $skippedCount = $result['skipped_count'] ?? 0;
            $failedCount = $result['failed_count'] ?? 0;

            if ($syncedCount > 0) {
                $this->info("Successfully synced {$syncedCount} new order(s) to ERPNext.");
                foreach (($result['synced_orders'] ?? []) as $order) {
                    $orderName = $order['name'] ?? ('#' . ($order['id'] ?? 'unknown'));
                    $salesOrderId = $order['sales_order_id'] ?? 'N/A';
                    $this->line(" [✓] Synced: {$orderName} -> ERPNext Sales Order: {$salesOrderId}");
                }
            } else {
                $this->line("No new orders to sync. (Skipped {$skippedCount} already-synced orders).");
            }

            if ($failedCount > 0) {
                $this->error("{$failedCount} order(s) failed to sync:");
                foreach (($result['failed_orders'] ?? []) as $failedOrder) {
                    $orderName = $failedOrder['name'] ?? ('#' . ($failedOrder['id'] ?? 'unknown'));
                    $error = $failedOrder['error'] ?? 'Unknown error';
                    $this->error(" [✗] Failed {$orderName}: {$error}");
                }
            }

            Log::info("sync:shopify-orders completed", [
                'synced' => $syncedCount,
                'skipped' => $skippedCount,
                'failed' => $failedCount,
            ]);

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $this->error("Failed to sync Shopify orders: " . $e->getMessage());
            Log::error("sync:shopify-orders exception", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }
}
