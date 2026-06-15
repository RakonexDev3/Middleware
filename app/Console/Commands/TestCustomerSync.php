<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\ProcessCustomerSyncJob;
use App\Services\ERPNext\CustomerService;

class TestCustomerSync extends Command
{
    protected $signature = 'sync:test-customer {--first= : First Name} {--last= : Last Name} {--company= : Company Name}';
    protected $description = 'Trigger a mock Shopify customer sync to ERPNext synchronously for testing';

    public function handle(CustomerService $customerService)
    {
        $this->info('Starting test customer synchronization to ERPNext...');

        // Define mock Shopify customer payload matching the structure we map
        $shopifyPayload = [
            'id' => rand(100000000, 999999999),
            'first_name' => $this->option('first') ?: 'John',
            'last_name' => $this->option('last') ?: 'Doe',
            'email' => 'john.doe.' . rand(1000, 9999) . '@example.com',
            'phone' => '+1555' . rand(1000000, 9999999),
            'default_address' => [
                'company' => $this->option('company') ?: null,
                'address1' => '123 Test Street',
                'city' => 'Austin',
                'province' => 'Texas',
                'country' => 'United States',
                'zip' => '78701'
            ]
        ];

        $this->comment('Mock Shopify Payload constructed:');
        $this->line(json_encode($shopifyPayload, JSON_PRETTY_PRINT));

        try {
            $this->comment('Executing ProcessCustomerSyncJob synchronously...');
            
            $job = new ProcessCustomerSyncJob($shopifyPayload);
            $job->handle($customerService);

            // Fetch the latest sync log to verify the result
            $log = \App\Models\SyncLog::latest()->first();

            if ($log && $log->status === 'success') {
                $this->info('Sync Successful! Log ID: ' . $log->id);
                $this->comment('ERPNext Request Data:');
                $this->line($log->payload);

                // Fetch customer mapping to confirm database entry
                $mapping = \App\Models\CustomerMap::where('shopify_customer_id', $shopifyPayload['id'])->first();
                if ($mapping) {
                    $this->info('Mapping Created successfully:');
                    $this->comment("Shopify ID: {$mapping->shopify_customer_id} => ERPNext ID: {$mapping->erp_customer_id}");
                } else {
                    $this->error('Failed to locate Customer mapping in DB.');
                }
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
