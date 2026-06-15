<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Shopify\ProductSyncService;
use App\Models\SyncLog;
use App\Models\ProductMap;

class TestProductSync extends Command
{
    protected $signature = 'sync:test-product {--sku= : Custom SKU to test with} {--title= : Custom product title}';
    protected $description = 'Trigger a mock Product sync to Shopify synchronously for testing';

    public function handle()
    {
        $this->info('Starting test product synchronization to Shopify...');

        $sku = $this->option('sku') ?: 'WB-TEST-' . rand(100, 999);
        $title = $this->option('title') ?: 'Test Wooden blocks ' . rand(100, 999);

        // Define mock product payload matching the structure we map
        $payload = [
            'product' => [
                'title' => $title,
                'body_html' => '<p>Premium educational toy for testing.</p>',
                'vendor' => 'My Company',
                'product_type' => 'Educational Toys',
                'status' => 'active',
                'metafields' => [
                    'custom.age' => '3-5 years',
                    'specs.product_dimensions' => '30x20x10 cm',
                    'specs.product_weight' => '1.5 kg',
                    'specs.package_dimensions' => '32x22x12 cm',
                    'specs.package_weight' => '1.8 kg',
                    'custom.material' => 'Wood',
                    'custom.features_new' => '{"type":"root","children":[{"type":"paragraph","children":[{"type":"text","value":"Key highlights: hand-crafted and non-toxic."}]}]}',
                    'custom.overview_new' => '{"type":"root","children":[{"type":"paragraph","children":[{"type":"text","value":"A general summary describing the wooden toy blocks."}]}]}',
                    'theme.description_excerpt' => 'A brief description excerpt for quick reference.',
                    'faq.question_1' => 'Is it non-toxic?',
                    'faq.answer_1' => 'Yes, completely safe for children.',
                    'faq.question_2' => 'How many pieces?',
                    'faq.answer_2' => '50 pieces of wooden blocks.',
                    'faq.question_3' => 'Is a box included?',
                    'faq.answer_3' => 'Yes, a cardboard box is included.',
                ],
                'variants' => [
                    [
                        'price' => '29.99',
                        'sku' => $sku,
                        'inventory_quantity' => 42,
                        'inventory_management' => 'shopify',
                        'metafields' => [
                            'product.bin' => 'Bin-TEST-99',
                            'product.rack' => 'Rack-TEST-12',
                            'custom.arabic_name' => 'مكعبات خشبية تجريبية',
                        ]
                    ]
                ]
            ]
        ];

        $this->comment('Mock Payload constructed:');
        $this->line(json_encode($payload, JSON_PRETTY_PRINT));

        try {
            $this->comment('Executing ProductSyncService syncToShopify...');
            
            $syncService = new ProductSyncService();
            $result = $syncService->syncToShopify($payload);

            $this->info('Sync Successful!');
            $this->info('Shopify Product ID: ' . $result['shopify_product_id']);
            $this->info('Synced Variant IDs: ' . implode(', ', $result['synced_variants']));

            // Fetch product mapping to confirm database entry
            $mapping = ProductMap::where('erp_item_code', $sku)->first();
            if ($mapping) {
                $this->info('Mapping Created/Updated successfully in product_maps table:');
                $this->comment("SKU: {$mapping->erp_item_code} => Shopify Product ID: {$mapping->shopify_product_id} | Variant ID: {$mapping->shopify_variant_id}");
            } else {
                $this->error('Failed to locate Product mapping in DB.');
            }

            // Fetch the latest sync log
            $log = SyncLog::where('entity_type', 'product')->where('entity_id', $sku)->latest()->first();
            if ($log) {
                $this->info('Sync Log status in DB: ' . $log->status);
            }
        } catch (\Exception $e) {
            $this->error('Exception encountered during sync: ' . $e->getMessage());
            $this->comment('Checking latest SyncLog for failure details...');
            $log = SyncLog::where('entity_type', 'product')->where('entity_id', $sku)->latest()->first();
            if ($log) {
                $this->error('SyncLog Status: ' . $log->status);
                $this->error('SyncLog Error: ' . $log->error_message);
            }
        }
    }
}
