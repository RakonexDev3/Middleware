<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\Shopify\ProductSyncService;
use App\Models\SyncLog;
use App\Models\ProductMap;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ExternalProductController extends Controller
{
    /**
     * Handle product sync requests from external ERP.
     */
    public function sync(Request $request)
    {
        // 1. Validate the structure of the incoming request
        $validator = Validator::make($request->all(), [
            'event' => 'required|string|in:create,update,delete',
            'item' => 'required|array',
            'item.SKU' => 'required|string',
            'item.product_title' => 'required_if:event,create,update|string',
            'item.parent_title' => 'nullable|string',
            'item.parent_body_html' => 'nullable|string',
            'item.product_body_html' => 'nullable|string',
            'item.arabic_title' => 'nullable|string',
            'item.product_category' => 'nullable|string',
            'item.body_html' => 'nullable|string',
            'item.brand' => 'nullable|string',
            'item.status' => 'nullable|string',
            'item.tags' => 'nullable',
            'item.default_uom' => 'nullable|string',
            'item.selling_price' => 'nullable|numeric',
            'item.price_list' => 'nullable|string',
            'item.recommended_age' => 'nullable|string',
            'item.material' => 'nullable|string',
            'item.dimensions' => 'nullable|string',
            'item.product_weight' => 'nullable|string',
            'item.package_dimensions' => 'nullable|string',
            'item.package_weight' => 'nullable|string',
            'item.installation_type' => 'nullable',
            'item.installation_level' => 'nullable|string',
            'item.features' => 'nullable|string',
            'item.overview' => 'nullable|string',
            'item.short_description' => 'nullable|string',
            'item.faq_question_1' => 'nullable|string',
            'item.faq_answer_1' => 'nullable|string',
            'item.faq_question_2' => 'nullable|string',
            'item.faq_answer_2' => 'nullable|string',
            'item.faq_question_3' => 'nullable|string',
            'item.faq_answer_3' => 'nullable|string',
            'item.rack' => 'nullable|string',
            'item.bin' => 'nullable|string',
            'item.barcode' => 'nullable|string',
            'item.is_variant' => 'nullable|boolean',
            'item.parent_sku' => 'required_if:item.is_variant,true|nullable|string',
            'item.attributes' => 'nullable|array',
            'item.attributes.*.attribute' => 'required_with:item.attributes|string',
            'item.attributes.*.value' => 'required_with:item.attributes|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $event = $request->input('event');
        $item = $request->input('item');
        $sku = $item['SKU'];
        $isVariant = filter_var($item['is_variant'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $parentSku = $item['parent_sku'] ?? null;

        // 2. Create the sync log record
        $syncLog = SyncLog::create([
            'entity_type' => 'product',
            'entity_id' => $sku,
            'direction' => 'erp_to_shopify',
            'status' => 'pending',
            'payload' => json_encode($request->all()),
        ]);

        try {
            Log::info("Starting external product sync: {$event} for SKU: {$sku}");
            $syncService = new ProductSyncService();

            // 3. Check for existence based on SKU and is_variant
            $exists = false;
            if ($isVariant && $parentSku) {
                if ($event === 'create') {
                    // For create, check if the variant itself already exists
                    $mapping = ProductMap::where('erp_item_code', $sku)->first();
                    if ($mapping && $mapping->shopify_product_id) {
                        $exists = true;
                    } else {
                        $exists = !is_null($syncService->findProductIdBySku($sku));
                    }
                } else {
                    // For update/delete, check if the parent product exists
                    $parentMapping = ProductMap::where('erp_item_code', $parentSku)->first();
                    if ($parentMapping && $parentMapping->shopify_product_id) {
                        $exists = true;
                    } else {
                        $exists = !is_null($syncService->findProductIdBySku($parentSku));
                    }
                }
            } else {
                // For normal item, check if it exists
                $mapping = ProductMap::where('erp_item_code', $sku)->first();
                $shopifyProductId = null;
                if ($mapping && $mapping->shopify_product_id) {
                    $shopifyProductId = $mapping->shopify_product_id;
                } else {
                    $shopifyProductId = $syncService->findProductIdBySku($sku);
                }
                $exists = !is_null($shopifyProductId);
            }

            // 4. Handle events
            if ($event === 'create') {
                if ($exists) {
                    $errorMsg = "Product with SKU {$sku} already exists.";
                    $syncLog->update([
                        'status' => 'failed',
                        'error_message' => $errorMsg
                    ]);
                    return response()->json([
                        'success' => false,
                        'error' => $errorMsg
                    ], 422);
                }
            } elseif ($event === 'update') {
                if (!$exists) {
                    $errorMsg = "Product with SKU {$sku} does not exist.";
                    $syncLog->update([
                        'status' => 'failed',
                        'error_message' => $errorMsg
                    ]);
                    return response()->json([
                        'success' => false,
                        'error' => $errorMsg
                    ], 404);
                }
            } elseif ($event === 'delete') {
                // Delete product from Shopify
                $deleted = $syncService->deleteProductBySku($sku, $isVariant, $parentSku);
                $syncLog->update([
                    'status' => 'success',
                    'error_message' => null
                ]);
                return response()->json([
                    'success' => true,
                    'message' => $deleted ? "Product with SKU {$sku} deleted successfully from Shopify." : "Product with SKU {$sku} not found, deletion skipped.",
                ], 200);
            }

            $productTitle = null;
            $productBodyHtml = null;

            if (isset($item['parent_title'])) {
                $productTitle = $item['parent_title'];
            } elseif (!$isVariant || !$exists) {
                $productTitle = $item['product_title'] ?? null;
            }

            if (isset($item['parent_body_html'])) {
                $productBodyHtml = $item['parent_body_html'];
            } elseif (!$isVariant || !$exists) {
                $productBodyHtml = $item['body_html'] ?? $item['product_body_html'] ?? null;
            }

            $installationType = null;
            if (isset($item['installation_type']) && $item['installation_type'] !== '') {
                $installationType = filter_var($item['installation_type'], FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
            }

            $installationLevel = (isset($item['installation_level']) && $item['installation_level'] !== '')
                ? (string)$item['installation_level']
                : null;

            // 5. Transform item payload into the standard ProductSyncService format
            $syncPayload = [
                'product' => [
                    'vendor' => $item['brand'] ?? '',
                    'product_type' => $item['product_category'] ?? '',
                    'status' => $item['status'] ?? 'active',
                    'tags' => $item['tags'] ?? 'Axanta Cloud',
                    'is_variant' => $isVariant,
                    'parent_sku' => $parentSku,
                    'metafields' => [
                        'custom.recommended_age' => $item['recommended_age'] ?? null,
                        'custom.product_dimensions' => $item['dimensions'] ?? null,
                        'custom.product_weight' => $item['product_weight'] ?? null,
                        'custom.package_dimensions' => $item['package_dimensions'] ?? null,
                        'custom.package_weight' => $item['package_weight'] ?? null,
                        'custom.material' => $item['material'] ?? null,
                        'custom.installation_type' => $installationType,
                        'custom.installation_level' => $installationLevel,
                        'custom.features' => $item['features'] ?? null,
                        'custom.overview' => $item['overview'] ?? null,
                        'custom.short_description' => $item['short_description'] ?? null,
                        'custom.faq_question_1' => $item['faq_question_1'] ?? null,
                        'custom.faq_answer_1' => $item['faq_answer_1'] ?? null,
                        'custom.faq_question_2' => $item['faq_question_2'] ?? null,
                        'custom.faq_answer_2' => $item['faq_answer_2'] ?? null,
                        'custom.faq_question_3' => $item['faq_question_3'] ?? null,
                        'custom.faq_answer_3' => $item['faq_answer_3'] ?? null,
                    ],
                    'variants' => [
                        [
                            'sku' => $sku,
                            'price' => isset($item['selling_price']) && is_numeric($item['selling_price']) ? number_format((float)$item['selling_price'], 2, '.', '') : '0.00',
                            'barcode' => $item['barcode'] ?? '',
                            'inventory_management' => 'shopify',
                            'attributes' => $item['attributes'] ?? [],
                            'metafields' => [
                                'custom.arabic_name' => $item['arabic_title'] ?? null,
                                'custom.default_uom' => $item['default_uom'] ?? null,
                                'custom.rack' => $item['rack'] ?? null,
                                'custom.bin' => $item['bin'] ?? null,
                                'custom.installation_type' => $installationType,
                                'custom.installation_level' => $installationLevel,
                            ]
                        ]
                    ],
                    'options' => isset($item['attributes']) ? array_column($item['attributes'], 'attribute') : [],
                ]
            ];

            if ($productTitle !== null) {
                $syncPayload['product']['title'] = $productTitle;
            }
            if ($productBodyHtml !== null) {
                $syncPayload['product']['body_html'] = $productBodyHtml;
            }

            // 6. Execute synchronization
            $result = $syncService->syncToShopify($syncPayload);

            // 7. Update sync log to success
            $syncLog->update([
                'status' => 'success',
                'error_message' => null
            ]);

            Log::info("External product sync successful for SKU: {$sku}", [
                'shopify_product_id' => $result['shopify_product_id']
            ]);

            return response()->json([
                'success' => true,
                'message' => "Product synced successfully to Shopify.",
                'shopify_product_id' => $result['shopify_product_id'],
                'synced_variants' => $result['synced_variants']
            ], 200);

        } catch (\Exception $e) {
            Log::error("Failed external product sync for SKU: {$sku}", [
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

    /**
     * Handle bulk product sync requests from external ERP.
     */
    public function bulkSync(Request $request)
    {
        // 1. Validate the structure of the incoming request
        $validator = Validator::make($request->all(), [
            'event' => 'required|string|in:create,update,delete',
            'items' => 'required|array|min:1',
            'items.*.SKU' => 'required|string',
            'items.*.product_title' => 'required_if:event,create,update|string',
            'items.*.parent_title' => 'nullable|string',
            'items.*.parent_body_html' => 'nullable|string',
            'items.*.product_body_html' => 'nullable|string',
            'items.*.arabic_title' => 'nullable|string',
            'items.*.product_category' => 'nullable|string',
            'items.*.body_html' => 'nullable|string',
            'items.*.brand' => 'nullable|string',
            'items.*.status' => 'nullable|string',
            'items.*.tags' => 'nullable',
            'items.*.default_uom' => 'nullable|string',
            'items.*.selling_price' => 'nullable|numeric',
            'items.*.price_list' => 'nullable|string',
            'items.*.recommended_age' => 'nullable|string',
            'items.*.material' => 'nullable|string',
            'items.*.dimensions' => 'nullable|string',
            'items.*.product_weight' => 'nullable|string',
            'items.*.package_dimensions' => 'nullable|string',
            'items.*.package_weight' => 'nullable|string',
            'items.*.installation_type' => 'nullable',
            'items.*.installation_level' => 'nullable|string',
            'items.*.features' => 'nullable|string',
            'items.*.overview' => 'nullable|string',
            'items.*.short_description' => 'nullable|string',
            'items.*.faq_question_1' => 'nullable|string',
            'items.*.faq_answer_1' => 'nullable|string',
            'items.*.faq_question_2' => 'nullable|string',
            'items.*.faq_answer_2' => 'nullable|string',
            'items.*.faq_question_3' => 'nullable|string',
            'items.*.faq_answer_3' => 'nullable|string',
            'items.*.rack' => 'nullable|string',
            'items.*.bin' => 'nullable|string',
            'items.*.barcode' => 'nullable|string',
            'items.*.is_variant' => 'nullable|boolean',
            'items.*.parent_sku' => 'required_if:items.*.is_variant,true|nullable|string',
            'items.*.attributes' => 'nullable|array',
            'items.*.attributes.*.attribute' => 'required_with:items.*.attributes|string',
            'items.*.attributes.*.value' => 'required_with:items.*.attributes|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $event = $request->input('event');
        $items = $request->input('items');
        
        $results = [];
        $syncService = new ProductSyncService();

        foreach ($items as $item) {
            $sku = $item['SKU'];
            $isVariant = filter_var($item['is_variant'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $parentSku = $item['parent_sku'] ?? null;

            // Create sync log for this item
            $syncLog = SyncLog::create([
                'entity_type' => 'product',
                'entity_id' => $sku,
                'direction' => 'erp_to_shopify',
                'status' => 'pending',
                'payload' => json_encode(['event' => $event, 'item' => $item]),
            ]);

            try {
                Log::info("Starting external bulk product sync: {$event} for SKU: {$sku}");

                // Check for existence based on SKU and is_variant
                $exists = false;
                if ($isVariant && $parentSku) {
                    if ($event === 'create') {
                        // For create, check if the variant itself already exists
                        $mapping = ProductMap::where('erp_item_code', $sku)->first();
                        if ($mapping && $mapping->shopify_product_id) {
                            $exists = true;
                        } else {
                            $exists = !is_null($syncService->findProductIdBySku($sku));
                        }
                    } else {
                        // For update/delete, check if the parent product exists
                        $parentMapping = ProductMap::where('erp_item_code', $parentSku)->first();
                        if ($parentMapping && $parentMapping->shopify_product_id) {
                            $exists = true;
                        } else {
                            $exists = !is_null($syncService->findProductIdBySku($parentSku));
                        }
                    }
                } else {
                    // For normal item, check if it exists
                    $mapping = ProductMap::where('erp_item_code', $sku)->first();
                    $shopifyProductId = null;
                    if ($mapping && $mapping->shopify_product_id) {
                        $shopifyProductId = $mapping->shopify_product_id;
                    } else {
                        $shopifyProductId = $syncService->findProductIdBySku($sku);
                    }
                    $exists = !is_null($shopifyProductId);
                }

                // Handle events
                if ($event === 'create') {
                    if ($exists) {
                        $errorMsg = "Product with SKU {$sku} already exists.";
                        $syncLog->update([
                            'status' => 'failed',
                            'error_message' => $errorMsg
                        ]);
                        $results[] = [
                            'sku' => $sku,
                            'success' => false,
                            'error' => $errorMsg
                        ];
                        continue;
                    }
                } elseif ($event === 'update') {
                    if (!$exists) {
                        $errorMsg = "Product with SKU {$sku} does not exist.";
                        $syncLog->update([
                            'status' => 'failed',
                            'error_message' => $errorMsg
                        ]);
                        $results[] = [
                            'sku' => $sku,
                            'success' => false,
                            'error' => $errorMsg
                        ];
                        continue;
                    }
                } elseif ($event === 'delete') {
                    // Delete product from Shopify
                    $deleted = $syncService->deleteProductBySku($sku, $isVariant, $parentSku);
                    $syncLog->update([
                        'status' => 'success',
                        'error_message' => null
                    ]);
                    $results[] = [
                        'sku' => $sku,
                        'success' => true,
                        'message' => $deleted ? "Product with SKU {$sku} deleted successfully from Shopify." : "Product with SKU {$sku} not found, deletion skipped.",
                    ];
                    continue;
                }

                $productTitle = null;
                $productBodyHtml = null;

                if (isset($item['parent_title'])) {
                    $productTitle = $item['parent_title'];
                } elseif (!$isVariant || !$exists) {
                    $productTitle = $item['product_title'] ?? null;
                }

                if (isset($item['parent_body_html'])) {
                    $productBodyHtml = $item['parent_body_html'];
                } elseif (!$isVariant || !$exists) {
                    $productBodyHtml = $item['body_html'] ?? $item['product_body_html'] ?? null;
                }

                $installationType = null;
                if (isset($item['installation_type']) && $item['installation_type'] !== '') {
                    $installationType = filter_var($item['installation_type'], FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
                }

                $installationLevel = (isset($item['installation_level']) && $item['installation_level'] !== '')
                    ? (string)$item['installation_level']
                    : null;

                // Transform item payload into the standard ProductSyncService format
                $syncPayload = [
                    'product' => [
                        'vendor' => $item['brand'] ?? '',
                        'product_type' => $item['product_category'] ?? '',
                        'status' => $item['status'] ?? 'active',
                        'tags' => $item['tags'] ?? 'Axanta Cloud',
                        'is_variant' => $isVariant,
                        'parent_sku' => $parentSku,
                        'metafields' => [
                            'custom.recommended_age' => $item['recommended_age'] ?? null,
                            'custom.product_dimensions' => $item['dimensions'] ?? null,
                            'custom.product_weight' => $item['product_weight'] ?? null,
                            'custom.package_dimensions' => $item['package_dimensions'] ?? null,
                            'custom.package_weight' => $item['package_weight'] ?? null,
                            'custom.material' => $item['material'] ?? null,
                            'custom.installation_type' => $installationType,
                            'custom.installation_level' => $installationLevel,
                            'custom.features' => $item['features'] ?? null,
                            'custom.overview' => $item['overview'] ?? null,
                            'custom.short_description' => $item['short_description'] ?? null,
                            'custom.faq_question_1' => $item['faq_question_1'] ?? null,
                            'custom.faq_answer_1' => $item['faq_answer_1'] ?? null,
                            'custom.faq_question_2' => $item['faq_question_2'] ?? null,
                            'custom.faq_answer_2' => $item['faq_answer_2'] ?? null,
                            'custom.faq_question_3' => $item['faq_question_3'] ?? null,
                            'custom.faq_answer_3' => $item['faq_answer_3'] ?? null,
                        ],
                        'variants' => [
                            [
                                'sku' => $sku,
                                'price' => isset($item['selling_price']) && is_numeric($item['selling_price']) ? number_format((float)$item['selling_price'], 2, '.', '') : '0.00',
                                'barcode' => $item['barcode'] ?? '',
                                'inventory_management' => 'shopify',
                                'attributes' => $item['attributes'] ?? [],
                                'metafields' => [
                                    'custom.arabic_name' => $item['arabic_title'] ?? null,
                                    'custom.default_uom' => $item['default_uom'] ?? null,
                                    'custom.rack' => $item['rack'] ?? null,
                                    'custom.bin' => $item['bin'] ?? null,
                                    'custom.installation_type' => $installationType,
                                    'custom.installation_level' => $installationLevel,
                                ]
                            ]
                        ],
                        'options' => isset($item['attributes']) ? array_column($item['attributes'], 'attribute') : [],
                    ]
                ];

                if ($productTitle !== null) {
                    $syncPayload['product']['title'] = $productTitle;
                }
                if ($productBodyHtml !== null) {
                    $syncPayload['product']['body_html'] = $productBodyHtml;
                }

                // Execute synchronization
                $result = $syncService->syncToShopify($syncPayload);

                // Update sync log to success
                $syncLog->update([
                    'status' => 'success',
                    'error_message' => null
                ]);

                Log::info("External product sync successful for SKU: {$sku}", [
                    'shopify_product_id' => $result['shopify_product_id']
                ]);

                $results[] = [
                    'sku' => $sku,
                    'success' => true,
                    'message' => "Product synced successfully to Shopify.",
                    'shopify_product_id' => $result['shopify_product_id'],
                    'synced_variants' => $result['synced_variants']
                ];

            } catch (\Exception $e) {
                Log::error("Failed external product sync for SKU: {$sku}", [
                    'error' => $e->getMessage()
                ]);

                $syncLog->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage()
                ]);

                $results[] = [
                    'sku' => $sku,
                    'success' => false,
                    'error' => 'Product sync failed: ' . $e->getMessage()
                ];
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Bulk product sync completed.",
            'results' => $results
        ], 200);
    }
}
