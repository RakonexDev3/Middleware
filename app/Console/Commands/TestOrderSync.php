<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\SyncOrderToErpnextJob;
use App\Services\ERPNext\ErpnextApiService;

class TestOrderSync extends Command
{
    protected $signature = 'sync:test-order {--customer= : Custom customer name}';
    protected $description = 'Trigger a mock Shopify order sync to ERPNext synchronously for testing';

    public function handle(ErpnextApiService $erpnextService)
    {
        $this->info('Starting test order synchronization to ERPNext...');

        // Define mock Shopify payload matching the structure we map
        $shopifyPayload = [
            'id' => 'test_' . time(),
            'created_at' => now()->toIso8601String(),
            'customer' => [
                'id' => 999999, // Unmapped ID to test auto-creation
                'first_name' => $this->option('customer') ?: 'Test-Customer-' . rand(100, 999),
                'last_name' => 'Demo',
                'email' => 'test.' . rand(100, 999) . '@example.com'
            ],
            'line_items' => [
                [
                    'variant_id' => 888888,
                    'product_id' => 777777,
                    'sku' => '106320642', // Example SKU from your request
                    'quantity' => 2,
                    'price' => '32.00'
                ],
                [
                    'variant_id' => 555555,
                    'product_id' => 444444,
                    'sku' => 'TRU-2297512', // Second example SKU from your request
                    'quantity' => 1,
                    'price' => '30.00'
                ]
            ]
        ];

        $this->comment('Mock Shopify Payload constructed:');
        $this->line(json_encode($shopifyPayload, JSON_PRETTY_PRINT));

        try {
            $this->comment('Executing SyncOrderToErpnextJob synchronously...');
            
            $job = new SyncOrderToErpnextJob($shopifyPayload);
            $job->handle($erpnextService);

            // Fetch the latest sync log to verify the result
            $log = \App\Models\SyncLog::latest()->first();

            if ($log && $log->status === 'success') {
                $this->info('Sync Successful! Log ID: ' . $log->id);
                $this->comment('ERPNext Request Data:');
                $this->line($log->payload);
            } else {
                $this->error('Sync Failed! Log ID: ' . ($log ? $log->id : 'none'));
                if ($log) {
                    $this->error('Error Message: ' . $log->error_message);
                }
            }
        } catch (\Exception $e) {
            $this->error('Exception encountered: ' . $e->getMessage());
        }
    }
}
