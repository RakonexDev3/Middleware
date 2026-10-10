<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\Shopify\ProductSyncService;
use App\Models\SyncLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    /**
     * Synchronize a product, variants, and metafields from Laravel middleware to Shopify.
     */
    public function syncProduct(Request $request)
    {
        // 1. Validate the incoming payload structure
        $validator = Validator::make($request->all(), [
            'product' => 'required|array',
            'product.title' => 'required|string',
            'product.body_html' => 'nullable|string',
            'product.vendor' => 'nullable|string',
            'product.product_type' => 'nullable|string',
            'product.status' => 'nullable|string',
            'product.tags' => 'nullable',
            'product.metafields' => 'nullable|array',
            'product.variants' => 'required|array|min:1',
            'product.variants.*.price' => 'required|string',
            'product.variants.*.sku' => 'required|string',
            'product.variants.*.inventory_quantity' => 'nullable|integer',
            'product.variants.*.inventory_management' => 'nullable|string',
            'product.variants.*.barcode' => 'nullable|string',
            'product.variants.*.weight' => 'nullable',
            'product.variants.*.weight_unit' => 'nullable|string',
            'product.variants.*.requires_shipping' => 'nullable|boolean',
            'product.variants.*.taxable' => 'nullable|boolean',
            'product.variants.*.metafields' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $payload = $request->all();
        $firstVariantSku = $payload['product']['variants'][0]['sku'] ?? 'unknown_sku';

        // 2. Create the sync log record
        $syncLog = SyncLog::create([
            'entity_type' => 'product',
            'entity_id' => $firstVariantSku,
            'direction' => 'erp_to_shopify',
            'status' => 'pending',
            'payload' => json_encode($payload),
        ]);

        try {
            Log::info("Starting Shopify product sync", ['sku' => $firstVariantSku]);

            // 3. Initialize and execute the sync service
            $syncService = new ProductSyncService();
            $result = $syncService->syncToShopify($payload);

            // 4. Update the sync log as success
            $syncLog->update([
                'status' => 'success',
                'error_message' => null
            ]);

            Log::info("Product sync completed successfully", [
                'sku' => $firstVariantSku,
                'shopify_product_id' => $result['shopify_product_id']
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Product synced successfully to Shopify',
                'shopify_product_id' => $result['shopify_product_id'],
                'synced_variants' => $result['synced_variants']
            ], 200);

        } catch (\Exception $e) {
            // Log the failure
            Log::error("Failed to sync product to Shopify", [
                'sku' => $firstVariantSku,
                'error' => $e->getMessage()
            ]);

            $syncLog->update([
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Product sync failed: ' . $e->getMessage()
            ], 500);
        }
    }
}
