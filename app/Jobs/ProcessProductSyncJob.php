<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

use App\Services\Shopify\ShopifyGraphQLService;
use App\Models\SyncLog;
use App\Models\ProductMap;

class ProcessProductSyncJob implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    protected array $data;
    protected string $direction;

    /**
     * Create a new job instance.
     */
    public function __construct(array $data, string $direction)
    {
        $this->data = $data;
        $this->direction = $direction;
    }

    /**
     * Execute the job.
     */
    public function handle(ShopifyGraphQLService $shopify): void
    {
        $syncLog = SyncLog::create([
            'entity_type' => 'product',
            'entity_id' => $this->data['item_code'] ?? null,
            'direction' => $this->direction,
            'status' => 'pending',
            'payload' => json_encode($this->data),
        ]);

        try {
            if ($this->direction === 'erp_to_shopify') {
                $this->syncErpToShopify($shopify);
            } else {
                // TODO: shopify_to_erp logic
            }

            $syncLog->update(['status' => 'success']);
        } catch (\Exception $e) {
            $syncLog->update([
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    protected function syncErpToShopify(ShopifyGraphQLService $shopify)
    {
        $itemCode = $this->data['item_code'];
        $productMap = ProductMap::where('erp_item_code', $itemCode)->first();

        if ($productMap && $productMap->shopify_product_id) {
            $query = <<<'GRAPHQL'
            mutation productUpdate($input: ProductInput!) {
                productUpdate(input: $input) {
                    product { id }
                    userErrors { field message }
                }
            }
            GRAPHQL;

            $variables = [
                'input' => [
                    'id' => $productMap->shopify_product_id,
                    'title' => $this->data['item_name'],
                    'bodyHtml' => $this->data['description'] ?? '',
                ]
            ];
            $mutationName = 'productUpdate';
        } else {
            $query = <<<'GRAPHQL'
            mutation productCreate($input: ProductInput!) {
                productCreate(input: $input) {
                    product { id }
                    userErrors { field message }
                }
            }
            GRAPHQL;

            $variables = [
                'input' => [
                    'title' => $this->data['item_name'],
                    'bodyHtml' => $this->data['description'] ?? '',
                    'status' => 'ACTIVE',
                    'vendor' => 'ERPNext',
                ]
            ];
            $mutationName = 'productCreate';
        }

        $result = $shopify->query($query, $variables);
        
        if (!empty($result['data'][$mutationName]['userErrors'])) {
            throw new \Exception("Shopify mutation error: " . json_encode($result['data'][$mutationName]['userErrors']));
        }

        if (!$productMap && !empty($result['data']['productCreate']['product']['id'])) {
            ProductMap::create([
                'erp_item_code' => $itemCode,
                'shopify_product_id' => $result['data']['productCreate']['product']['id']
            ]);
        }
    }
}
