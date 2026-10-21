<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Services\ERPNext\ErpnextApiService;
use App\Models\SyncLog;
use App\Models\CustomerMap;
use App\Models\ProductMap;
use Illuminate\Support\Facades\Log;

class SyncOrderToErpnextJob implements ShouldQueue
{
    use Queueable;

    protected $shopifyOrderPayload;

    /**
     * Create a new job instance.
     */
    public function __construct(array $shopifyOrderPayload)
    {
        $this->shopifyOrderPayload = $shopifyOrderPayload;
    }

    /**
     * Execute the job.
     */
    public function handle(ErpnextApiService $erpnextService): void
    {
        $erpnextData = null;
        try {
            $payload = $this->shopifyOrderPayload;

            // Extract customer details and mapping
            $erpCustomerId = null;
            $shopifyCustomerId = null;
            $customerName = 'Guest Customer';

            if (isset($payload['customer']) && isset($payload['customer']['id'])) {
                $shopifyCustomerId = (string) $payload['customer']['id'];
                if (isset($payload['customer']['first_name'])) {
                    $customerName = trim($payload['customer']['first_name'] . ' ' . ($payload['customer']['last_name'] ?? ''));
                }
            } elseif (isset($payload['email']) && !empty($payload['email'])) {
                $shopifyCustomerId = 'guest:' . $payload['email'];
                if (isset($payload['billing_address'])) {
                    $customerName = trim(($payload['billing_address']['first_name'] ?? '') . ' ' . ($payload['billing_address']['last_name'] ?? ''));
                }
                if (empty($customerName)) {
                    $customerName = 'Guest (' . $payload['email'] . ')';
                }
            }

            if ($shopifyCustomerId) {
                $customerMap = CustomerMap::where('shopify_customer_id', $shopifyCustomerId)
                    ->orWhere('shopify_customer_id', 'like', '%' . $shopifyCustomerId)
                    ->first();
                if ($customerMap) {
                    $erpCustomerId = $customerMap->erp_customer_id;
                } else {
                    // Try to auto-create customer in ERPNext
                    try {
                        $customerData = [
                            'customer_name' => $customerName,
                            'customer_type' => 'Individual',
                            // 'customer_group' => env('ERPNEXT_CUSTOMER_GROUP', 'Individual'),
                            // 'territory' => env('ERPNEXT_TERRITORY', 'All Territories'),
                        ];
                        // if (isset($payload['customer']['email'])) {
                        //     $customerData['email_id'] = $payload['customer']['email'];
                        // } elseif (isset($payload['email'])) {
                        //     $customerData['email_id'] = $payload['email'];
                        // }

                        $response = $erpnextService->createCustomer($customerData);
                        // ERPNext returns created customer ID in 'data.name' or 'name' field
                        $erpCustomerId = $response['data']['name'] ?? $response['name'] ?? null;

                        if ($erpCustomerId) {
                            CustomerMap::create([
                                'shopify_customer_id' => $shopifyCustomerId,
                                'erp_customer_id' => $erpCustomerId,
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::warning('Failed to auto-create customer in ERPNext, falling back to default.', [
                            'error' => $e->getMessage(),
                            'customer_name' => $customerName
                        ]);
                    }
                }
            }

            if (!$erpCustomerId) {
                $erpCustomerId = env('ERPNEXT_DEFAULT_CUSTOMER', 'CUST-2026-00001');
            }

            // Map Shopify line items to ERPNext expected item format using ProductMap
            $items = [];
            if (isset($payload['line_items']) && is_array($payload['line_items'])) {
                foreach ($payload['line_items'] as $lineItem) {
                    $variantId = isset($lineItem['variant_id']) ? (string) $lineItem['variant_id'] : null;
                    $productId = isset($lineItem['product_id']) ? (string) $lineItem['product_id'] : null;
                    $itemCode = null;

                    if ($variantId) {
                        $itemCode = ProductMap::where('shopify_variant_id', $variantId)
                            ->orWhere('shopify_variant_id', 'like', '%' . $variantId)
                            ->value('erp_item_code');
                    }

                    if (!$itemCode && $productId) {
                        $itemCode = ProductMap::where('shopify_product_id', $productId)
                            ->orWhere('shopify_product_id', 'like', '%' . $productId)
                            ->value('erp_item_code');
                    }

                    if (!$itemCode) {
                        $itemCode = !empty($lineItem['sku']) ? $lineItem['sku'] : 'UNKNOWN-SKU';
                    }

                    $items[] = [
                        'item_code' => $itemCode,
                        'qty' => $lineItem['quantity'] ?? 1,
                        'rate' => $lineItem['price'] ?? 0.00
                    ];
                }
            }

            // Construct exact ERPNext Sales Order JSON payload
            $transactionDate = isset($payload['created_at']) 
                ? date('Y-m-d', strtotime($payload['created_at'])) 
                : date('Y-m-d');
            $deliveryDate = date('Y-m-d', strtotime($transactionDate . ' +5 days'));

            $erpnextData = [
                'customer' => $erpCustomerId,
                'transaction_date' => $transactionDate,
                'delivery_date' => $deliveryDate,
                'items' => $items,
            ];

            // Send to ERPNext
            $response = $erpnextService->createSalesOrder($erpnextData);

            // Record Success in Database
            SyncLog::create([
                'entity_type' => 'Order',
                'entity_id' => $payload['id'] ?? 'unknown',
                'direction' => 'Shopify -> ERPNext',
                'status' => 'success',
                'payload' => json_encode($erpnextData),
                'error_message' => null
            ]);

            Log::info('Successfully synced Shopify Order to ERPNext', [
                'shopify_order_id' => $payload['id'] ?? 'unknown',
            ]);

        } catch (\Exception $e) {
            // Record Failure in Database
            SyncLog::create([
                'entity_type' => 'Order',
                'entity_id' => $this->shopifyOrderPayload['id'] ?? 'unknown',
                'direction' => 'Shopify -> ERPNext',
                'status' => 'failed',
                'payload' => json_encode($erpnextData ?? $this->shopifyOrderPayload),
                'error_message' => $e->getMessage()
            ]);

            Log::error('Failed to sync Shopify Order to ERPNext', [
                'error' => $e->getMessage(),
            ]);
            
            // Optionally re-throw to trigger job retries
            // throw $e; 
        }
    }
}
