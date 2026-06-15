<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sync:shopify-products')]
#[Description('Sync updated products from ERPNext to Shopify')]
class SyncShopifyProducts extends Command
{
    public function handle(\App\Services\ERPNext\ProductService $productService)
    {
        $this->info('Starting ERPNext to Shopify product sync...');

        try {
            $items = $productService->getUpdatedItems();
            
            $this->info('Found ' . count($items) . ' items to sync.');

            foreach ($items as $item) {
                // Dispatch job for each item
                \App\Jobs\ProcessProductSyncJob::dispatch($item, 'erp_to_shopify');
                $this->line("Dispatched job for item: {$item['item_code']}");
            }

            $this->info('Sync jobs dispatched successfully.');
        } catch (\Exception $e) {
            $this->error('Sync failed: ' . $e->getMessage());
        }
    }
}
