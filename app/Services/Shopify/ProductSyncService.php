<?php

namespace App\Services\Shopify;

use App\Models\Shop;
use App\Models\ProductMap;
use Illuminate\Support\Facades\Log;

class ProductSyncService
{
    protected ShopifyGraphQLService $shopify;

    protected array $productMetafieldDefinitions = [
        'custom.recommended_age' => ['namespace' => 'custom', 'key' => 'recommended_age', 'type' => 'single_line_text_field'],
        'custom.product_dimensions' => ['namespace' => 'custom', 'key' => 'product_dimensions', 'type' => 'single_line_text_field'],
        'custom.product_weight' => ['namespace' => 'custom', 'key' => 'product_weight', 'type' => 'single_line_text_field'],
        'custom.package_dimensions' => ['namespace' => 'custom', 'key' => 'package_dimensions', 'type' => 'single_line_text_field'],
        'custom.package_weight' => ['namespace' => 'custom', 'key' => 'package_weight', 'type' => 'single_line_text_field'],
        'custom.material' => ['namespace' => 'custom', 'key' => 'material', 'type' => 'single_line_text_field'],
        'custom.installation_type' => ['namespace' => 'custom', 'key' => 'installation_type', 'type' => 'boolean'],
        'custom.installation_level' => ['namespace' => 'custom', 'key' => 'installation_level', 'type' => 'single_line_text_field'],
        'custom.features' => ['namespace' => 'custom', 'key' => 'features', 'type' => 'multi_line_text_field'],
        'custom.overview' => ['namespace' => 'custom', 'key' => 'overview', 'type' => 'multi_line_text_field'],
        'custom.short_description' => ['namespace' => 'custom', 'key' => 'short_description', 'type' => 'multi_line_text_field'],
        'custom.faq_question_1' => ['namespace' => 'custom', 'key' => 'faq_question_1', 'type' => 'single_line_text_field'],
        'custom.faq_answer_1' => ['namespace' => 'custom', 'key' => 'faq_answer_1', 'type' => 'multi_line_text_field'],
        'custom.faq_question_2' => ['namespace' => 'custom', 'key' => 'faq_question_2', 'type' => 'single_line_text_field'],
        'custom.faq_answer_2' => ['namespace' => 'custom', 'key' => 'faq_answer_2', 'type' => 'multi_line_text_field'],
        'custom.faq_question_3' => ['namespace' => 'custom', 'key' => 'faq_question_3', 'type' => 'single_line_text_field'],
        'custom.faq_answer_3' => ['namespace' => 'custom', 'key' => 'faq_answer_3', 'type' => 'multi_line_text_field'],

        // Fallback / legacy definitions
        'custom.age' => ['namespace' => 'custom', 'key' => 'age', 'type' => 'single_line_text_field'],
        'specs.product_dimensions' => ['namespace' => 'specs', 'key' => 'product_dimensions', 'type' => 'single_line_text_field'],
        'specs.product_weight' => ['namespace' => 'specs', 'key' => 'product_weight', 'type' => 'single_line_text_field'],
        'specs.package_dimensions' => ['namespace' => 'specs', 'key' => 'package_dimensions', 'type' => 'single_line_text_field'],
        'specs.package_weight' => ['namespace' => 'specs', 'key' => 'package_weight', 'type' => 'single_line_text_field'],
        'custom.features_new' => ['namespace' => 'custom', 'key' => 'features_new', 'type' => 'rich_text_field'],
        'custom.overview_new' => ['namespace' => 'custom', 'key' => 'overview_new', 'type' => 'rich_text_field'],
        'theme.description_excerpt' => ['namespace' => 'theme', 'key' => 'description_excerpt', 'type' => 'single_line_text_field'],
        'faq.question_1' => ['namespace' => 'faq', 'key' => 'question_1', 'type' => 'single_line_text_field'],
        'faq.answer_1' => ['namespace' => 'faq', 'key' => 'answer_1', 'type' => 'multi_line_text_field'],
        'faq.question_2' => ['namespace' => 'faq', 'key' => 'question_2', 'type' => 'single_line_text_field'],
        'faq.answer_2' => ['namespace' => 'faq', 'key' => 'answer_2', 'type' => 'multi_line_text_field'],
        'faq.question_3' => ['namespace' => 'faq', 'key' => 'question_3', 'type' => 'single_line_text_field'],
        'faq.answer_3' => ['namespace' => 'faq', 'key' => 'answer_3', 'type' => 'multi_line_text_field'],
    ];

    protected array $variantMetafieldDefinitions = [
        'custom.arabic_name' => ['namespace' => 'custom', 'key' => 'arabic_name', 'type' => 'single_line_text_field'],
        'custom.rack' => ['namespace' => 'custom', 'key' => 'rack', 'type' => 'single_line_text_field'],
        'custom.bin' => ['namespace' => 'custom', 'key' => 'bin', 'type' => 'single_line_text_field'],
        'custom.default_uom' => ['namespace' => 'custom', 'key' => 'default_uom', 'type' => 'single_line_text_field'],
        'custom.installation_type' => ['namespace' => 'custom', 'key' => 'installation_type', 'type' => 'boolean'],
        'custom.installation_level' => ['namespace' => 'custom', 'key' => 'installation_level', 'type' => 'single_line_text_field'],
    ];

    protected array $payloadKeyMap = [
        // Product Metafields
        'custom.recommended_age' => 'custom.recommended_age',
        'recommended_age' => 'custom.recommended_age',
        'custom.age' => 'custom.age',
        'age' => 'custom.age',
        
        'custom.product_dimensions' => 'custom.product_dimensions',
        'product_dimensions' => 'custom.product_dimensions',
        'specs.product_dimensions' => 'specs.product_dimensions',
        'dimensions' => 'custom.product_dimensions',
        
        'custom.product_weight' => 'custom.product_weight',
        'product_weight' => 'custom.product_weight',
        'specs.product_weight' => 'specs.product_weight',
        
        'custom.package_dimensions' => 'custom.package_dimensions',
        'package_dimensions' => 'custom.package_dimensions',
        'specs.package_dimensions' => 'specs.package_dimensions',
        
        'custom.package_weight' => 'custom.package_weight',
        'package_weight' => 'custom.package_weight',
        'specs.package_weight' => 'specs.package_weight',
        
        'custom.material' => 'custom.material',
        'material' => 'custom.material',

        'custom.installation_type' => 'custom.installation_type',
        'installation_type' => 'custom.installation_type',

        'custom.installation_level' => 'custom.installation_level',
        'installation_level' => 'custom.installation_level',
        
        'custom.features' => 'custom.features',
        'features' => 'custom.features',
        'custom.features_new' => 'custom.features_new',
        'features_new' => 'custom.features_new',
        
        'custom.overview' => 'custom.overview',
        'overview' => 'custom.overview',
        'custom.overview_new' => 'custom.overview_new',
        'overview_new' => 'custom.overview_new',
        
        'custom.short_description' => 'custom.short_description',
        'short_description' => 'custom.short_description',
        'theme.description_excerpt' => 'theme.description_excerpt',
        'description_excerpt' => 'custom.short_description',
        
        'custom.faq_question_1' => 'custom.faq_question_1',
        'faq_question_1' => 'custom.faq_question_1',
        'faq.question_1' => 'faq.question_1',
        'custom.faq_answer_1' => 'custom.faq_answer_1',
        'faq_answer_1' => 'custom.faq_answer_1',
        'faq.answer_1' => 'faq.answer_1',
        
        'custom.faq_question_2' => 'custom.faq_question_2',
        'faq_question_2' => 'custom.faq_question_2',
        'faq.question_2' => 'faq.question_2',
        'custom.faq_answer_2' => 'custom.faq_answer_2',
        'faq_answer_2' => 'custom.faq_answer_2',
        'faq.answer_2' => 'faq.answer_2',
        
        'custom.faq_question_3' => 'custom.faq_question_3',
        'faq_question_3' => 'custom.faq_question_3',
        'faq.question_3' => 'faq.question_3',
        'custom.faq_answer_3' => 'custom.faq_answer_3',
        'faq_answer_3' => 'custom.faq_answer_3',
        'faq.answer_3' => 'faq.answer_3',

        // Variant Metafields
        'custom.arabic_name' => 'custom.arabic_name',
        'arabic_name' => 'custom.arabic_name',
        'arabic_title' => 'custom.arabic_name',
        'custom.rack' => 'custom.rack',
        'rack' => 'custom.rack',
        'custom.bin' => 'custom.bin',
        'bin' => 'custom.bin',
        'custom.default_uom' => 'custom.default_uom',
        'default_uom' => 'custom.default_uom',
    ];

    public function __construct(?string $shopDomain = null)
    {
        if (!$shopDomain) {
            $shop = Shop::first();
            if (!$shop) {
                throw new \Exception("No shop connection found. Please connect your Shopify store first.");
            }
            $shopDomain = $shop->shop_domain;
        }
        $this->shopify = app(ShopifyGraphQLService::class, ['shopDomain' => $shopDomain]);
    }

    /**
     * Synchronizes product, variants and metafields to Shopify.
     */
    public function syncToShopify(array $payload): array
    {
        $productData = $payload['product'] ?? [];
        if (empty($productData)) {
            throw new \Exception("Invalid payload: 'product' object is required.");
        }

        $isVariant = $productData['is_variant'] ?? false;
        $parentSku = $productData['parent_sku'] ?? null;

        $shopifyProductId = null;
        $existingVariants = [];
        $existingOptions = [];

        if ($isVariant && $parentSku) {
            // First search DB mapping for parent_sku
            $parentMapping = ProductMap::where('erp_item_code', $parentSku)->first();
            if ($parentMapping && $parentMapping->shopify_product_id) {
                $shopifyProductId = $parentMapping->shopify_product_id;
            } else {
                // Fallback: Search Shopify for parent_sku
                $shopifyProductId = $this->findProductIdBySku($parentSku);
            }
        } else {
            // Use existing logic for firstVariantSku
            $firstVariantSku = $productData['variants'][0]['sku'] ?? null;
            if ($firstVariantSku) {
                $mapping = ProductMap::where('erp_item_code', $firstVariantSku)->first();
                if ($mapping && $mapping->shopify_product_id) {
                    $shopifyProductId = $mapping->shopify_product_id;
                } else {
                    $shopifyProductId = $this->findProductIdBySku($firstVariantSku);
                }
            }
        }

        // If product ID is resolved, fetch its existing details to get variant GIDs and option names
        if ($shopifyProductId) {
            $details = $this->getExistingProductDetails($shopifyProductId);
            $existingVariants = $details['variants'];
            $existingOptions = $details['options'];
        }

        // Title is only required if the product doesn't exist yet on Shopify (creation)
        if (!$shopifyProductId && empty($productData['title'])) {
            throw new \Exception("Product 'title' is required to create a new product.");
        }

        // Align variant attributes with option names
        $optionsList = !empty($existingOptions) ? $existingOptions : ($productData['options'] ?? []);
        if (isset($productData['variants']) && is_array($productData['variants'])) {
            foreach ($productData['variants'] as &$varData) {
                if (!empty($varData['attributes']) && !empty($optionsList)) {
                    $varData['optionValues'] = $this->alignVariantOptions($varData['attributes'], $optionsList);
                }
            }
            unset($varData);
        }

        // Upsert Product
        $shopifyProduct = $this->upsertProduct($shopifyProductId, $productData, $existingVariants, $optionsList);
        $shopifyProductId = $shopifyProduct['id'];

        // Build list of Shopify Variant details (GID mapped to SKU)
        $syncedVariants = [];
        $variantEdges = $shopifyProduct['variants']['edges'] ?? [];
        foreach ($variantEdges as $edge) {
            $variantNode = $edge['node'];
            if (isset($variantNode['sku'])) {
                $syncedVariants[$variantNode['sku']] = [
                    'id' => $variantNode['id'],
                    'inventoryItem' => $variantNode['inventoryItem'] ?? null
                ];
            }
        }

        // Save DB mappings for each variant SKU
        foreach ($syncedVariants as $sku => $varDetails) {
            ProductMap::updateOrCreate(
                ['erp_item_code' => $sku],
                [
                    'shopify_product_id' => $shopifyProductId,
                    'shopify_variant_id' => $varDetails['id']
                ]
            );
        }

        // If this is a variant sync, also map parentSku to the shopify product ID
        if ($isVariant && $parentSku) {
            ProductMap::updateOrCreate(
                ['erp_item_code' => $parentSku],
                [
                    'shopify_product_id' => $shopifyProductId,
                    'shopify_variant_id' => null
                ]
            );
        }

        // Sync Inventory quantities if specified
        $this->syncInventory($productData['variants'] ?? [], $syncedVariants);

        // Sync Metafields (Product-level and Variant-level)
        $metafieldsToSet = [];

        // 1. Process Product Metafields
        $productMetafields = $productData['metafields'] ?? [];
        foreach ($productMetafields as $key => $value) {
            $metaNamespace = null;
            $metaKey = null;
            $metaValue = null;
            $metaType = null;

            if (is_array($value) && isset($value['key'])) {
                // List of objects structure
                $namespace = $value['namespace'] ?? 'custom';
                $metaNamespace = $namespace;
                $metaKey = $value['key'];
                $metaType = $value['type'] ?? null;
                $metaValue = $value['value'] ?? '';
            } else {
                // Key-value dictionary structure
                $metaNamespace = null;
                $metaKey = $key;
                $metaValue = $value;
            }

            if ($metaValue === null || $metaValue === '') continue;

            $lookupKey = $metaNamespace ? "{$metaNamespace}.{$metaKey}" : $metaKey;
            $mappedKey = $this->payloadKeyMap[$lookupKey] ?? $this->payloadKeyMap[$metaKey] ?? $lookupKey;

            if (isset($this->productMetafieldDefinitions[$mappedKey])) {
                $def = $this->productMetafieldDefinitions[$mappedKey];
                $valStr = (string)$metaValue;
                if ($def['type'] === 'rich_text_field') {
                    $valStr = $this->formatRichText($valStr);
                } elseif ($def['type'] === 'boolean') {
                    $valStr = filter_var($metaValue, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
                }

                $metafieldsToSet[] = [
                    'ownerId' => $shopifyProductId,
                    'namespace' => $def['namespace'],
                    'key' => $def['key'],
                    'type' => $def['type'],
                    'value' => $valStr
                ];
            } elseif ($metaNamespace && $metaKey && $metaType) {
                $valStr = (string)$metaValue;
                if ($metaType === 'boolean') {
                    $valStr = filter_var($metaValue, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
                }
                $metafieldsToSet[] = [
                    'ownerId' => $shopifyProductId,
                    'namespace' => $metaNamespace,
                    'key' => $metaKey,
                    'type' => $metaType,
                    'value' => $valStr
                ];
            }
        }

        // 2. Process Variant Metafields
        $payloadVariants = $productData['variants'] ?? [];
        foreach ($payloadVariants as $varData) {
            $sku = $varData['sku'] ?? null;
            if (!$sku || !isset($syncedVariants[$sku])) continue;

            $variantGid = $syncedVariants[$sku]['id'];
            $varMetafields = $varData['metafields'] ?? [];

            foreach ($varMetafields as $key => $value) {
                $metaNamespace = null;
                $metaKey = null;
                $metaValue = null;
                $metaType = null;

                if (is_array($value) && isset($value['key'])) {
                    // List of objects structure
                    $namespace = $value['namespace'] ?? 'custom';
                    $metaNamespace = $namespace;
                    $metaKey = $value['key'];
                    $metaType = $value['type'] ?? null;
                    $metaValue = $value['value'] ?? '';
                } else {
                    // Key-value dictionary structure
                    $metaNamespace = null;
                    $metaKey = $key;
                    $metaValue = $value;
                }

                if ($metaValue === null || $metaValue === '') continue;

                $lookupKey = $metaNamespace ? "{$metaNamespace}.{$metaKey}" : $metaKey;
                $mappedKey = $this->payloadKeyMap[$lookupKey] ?? $this->payloadKeyMap[$metaKey] ?? $lookupKey;

                if (isset($this->variantMetafieldDefinitions[$mappedKey])) {
                    $def = $this->variantMetafieldDefinitions[$mappedKey];
                    $valStr = (string)$metaValue;
                    if ($def['type'] === 'boolean') {
                        $valStr = filter_var($metaValue, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
                    }
                    $metafieldsToSet[] = [
                        'ownerId' => $variantGid,
                        'namespace' => $def['namespace'],
                        'key' => $def['key'],
                        'type' => $def['type'],
                        'value' => $valStr
                    ];
                } elseif ($metaNamespace && $metaKey && $metaType) {
                    $valStr = (string)$metaValue;
                    if ($metaType === 'boolean') {
                        $valStr = filter_var($metaValue, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
                    }
                    $metafieldsToSet[] = [
                        'ownerId' => $variantGid,
                        'namespace' => $metaNamespace,
                        'key' => $metaKey,
                        'type' => $metaType,
                        'value' => $valStr
                    ];
                }
            }
        }

        // Execute batch metafield update
        if (!empty($metafieldsToSet)) {
            $this->setMetafields($metafieldsToSet);
        }

        return [
            'shopify_product_id' => $shopifyProductId,
            'synced_variants' => array_map(function($var) {
                return $var['id'];
            }, $syncedVariants)
        ];
    }

    /**
     * Finds the Shopify Product ID associated with a SKU.
     */
    public function findProductIdBySku(string $sku): ?string
    {
        $query = <<<'GRAPHQL'
        query findProductBySku($query: String!) {
            productVariants(first: 1, query: $query) {
                edges {
                    node {
                        product {
                            id
                        }
                    }
                }
            }
        }
        GRAPHQL;

        $variables = ['query' => "sku:{$sku}"];
        
        try {
            $result = $this->shopify->query($query, $variables);
            $edges = $result['data']['productVariants']['edges'] ?? [];
            if (!empty($edges)) {
                return $edges[0]['node']['product']['id'];
            }
        } catch (\Exception $e) {
            Log::warning("Could not search product by SKU: {$sku}. " . $e->getMessage());
        }

        return null;
    }

    /**
     * Finds the Shopify Product ID and Variant ID associated with a SKU.
     */
    public function findProductAndVariantBySku(string $sku): array
    {
        $query = <<<'GRAPHQL'
        query findProductAndVariantBySku($query: String!) {
            productVariants(first: 1, query: $query) {
                edges {
                    node {
                        id
                        product {
                            id
                        }
                    }
                }
            }
        }
        GRAPHQL;

        $variables = ['query' => "sku:{$sku}"];
        
        try {
            $result = $this->shopify->query($query, $variables);
            $edges = $result['data']['productVariants']['edges'] ?? [];
            if (!empty($edges)) {
                return [
                    'productId' => $edges[0]['node']['product']['id'] ?? null,
                    'variantId' => $edges[0]['node']['id'] ?? null
                ];
            }
        } catch (\Exception $e) {
            Log::warning("Could not search product and variant by SKU: {$sku}. " . $e->getMessage());
        }

        return [
            'productId' => null,
            'variantId' => null
        ];
    }

    /**
     * Deletes a product or variant from Shopify by SKU.
     */
    public function deleteProductBySku(string $sku, bool $isVariant = false, ?string $parentSku = null): bool
    {
        $productId = null;
        $variantGid = null;
        
        // 1. Check DB mapping first
        $mapping = ProductMap::where('erp_item_code', $sku)->first();
        if ($mapping && $mapping->shopify_product_id) {
            $productId = $mapping->shopify_product_id;
            $variantGid = $mapping->shopify_variant_id;
        }

        // 2. If not fully resolved, search Shopify
        if (!$productId || ($isVariant && !$variantGid)) {
            $resolved = $this->findProductAndVariantBySku($sku);
            if ($resolved['productId']) {
                $productId = $resolved['productId'];
            }
            if ($resolved['variantId']) {
                $variantGid = $resolved['variantId'];
            }
        }

        // 3. If still no product ID and it is a variant, try to resolve via parent SKU
        if (!$productId && $isVariant && $parentSku) {
            $parentMapping = ProductMap::where('erp_item_code', $parentSku)->first();
            if ($parentMapping && $parentMapping->shopify_product_id) {
                $productId = $parentMapping->shopify_product_id;
            } else {
                $productId = $this->findProductIdBySku($parentSku);
            }
        }

        if (!$productId) {
            return false;
        }

        // 4. Handle variant deletion if a variant ID is resolved
        if ($variantGid) {
            // Check if there are other variants for this product
            $hasOtherVariants = false;
            $otherVariantsCount = ProductMap::where('shopify_product_id', $productId)
                ->whereNotNull('shopify_variant_id')
                ->where('erp_item_code', '!=', $sku)
                ->count();

            if ($otherVariantsCount > 0) {
                $hasOtherVariants = true;
            } else {
                $existing = $this->getExistingVariants($productId);
                if (isset($existing[$sku])) {
                    $variantGid = $existing[$sku];
                }
                if (count($existing) > 1) {
                    $hasOtherVariants = true;
                }
            }

            if ($hasOtherVariants && $variantGid) {
                $query = <<<'GRAPHQL'
                mutation productVariantsBulkDelete($productId: ID!, $variantsIds: [ID!]!) {
                    productVariantsBulkDelete(productId: $productId, variantsIds: $variantsIds) {
                        product {
                            id
                        }
                        userErrors {
                            field
                            message
                        }
                    }
                }
                GRAPHQL;

                $result = $this->shopify->query($query, [
                    'productId' => $productId,
                    'variantsIds' => [$variantGid]
                ]);
                
                if (!empty($result['data']['productVariantsBulkDelete']['userErrors'])) {
                    throw new \Exception("Shopify error deleting variant: " . json_encode($result['data']['productVariantsBulkDelete']['userErrors']));
                }

                // Delete variant from ProductMap db table
                ProductMap::where('erp_item_code', $sku)->delete();
                return true;
            }
        }

        // 5. Otherwise delete the entire product (if standalone, or if it was the last variant)
        $query = <<<'GRAPHQL'
        mutation productDelete($input: ProductDeleteInput!) {
            productDelete(input: $input) {
                deletedProductId
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;

        $variables = [
            'input' => [
                'id' => $productId
            ]
        ];

        $result = $this->shopify->query($query, $variables);
        
        if (!empty($result['data']['productDelete']['userErrors'])) {
            throw new \Exception("Shopify error deleting product: " . json_encode($result['data']['productDelete']['userErrors']));
        }

        // Delete from ProductMap db table
        ProductMap::where('shopify_product_id', $productId)->delete();
        ProductMap::where('erp_item_code', $sku)->delete();

        return true;
    }

    /**
     * Gets existing variants of a product from Shopify.
     */
    protected function getExistingVariants(string $productId): array
    {
        $query = <<<'GRAPHQL'
        query getProductVariants($id: ID!) {
            product(id: $id) {
                variants(first: 50) {
                    edges {
                        node {
                            id
                            sku
                        }
                    }
                }
            }
        }
        GRAPHQL;

        try {
            $result = $this->shopify->query($query, ['id' => $productId]);
            $edges = $result['data']['product']['variants']['edges'] ?? [];
            $variants = [];
            foreach ($edges as $edge) {
                $node = $edge['node'];
                if (isset($node['sku'])) {
                    $variants[$node['sku']] = $node['id'];
                }
            }
            return $variants;
        } catch (\Exception $e) {
            Log::warning("Could not fetch existing variants for product: {$productId}. " . $e->getMessage());
        }

        return [];
    }

    /**
     * Gets existing product details (options and variants) from Shopify.
     */
    protected function getExistingProductDetails(string $productId): array
    {
        $query = <<<'GRAPHQL'
        query getProductDetails($id: ID!) {
            product(id: $id) {
                options {
                    name
                }
                variants(first: 50) {
                    edges {
                        node {
                            id
                            sku
                        }
                    }
                }
            }
        }
        GRAPHQL;

        try {
            $result = $this->shopify->query($query, ['id' => $productId]);
            $optionsData = $result['data']['product']['options'] ?? [];
            $options = array_column($optionsData, 'name');

            $edges = $result['data']['product']['variants']['edges'] ?? [];
            $variants = [];
            foreach ($edges as $edge) {
                $node = $edge['node'];
                if (isset($node['sku'])) {
                    $variants[$node['sku']] = $node['id'];
                }
            }
            return [
                'options' => $options,
                'variants' => $variants
            ];
        } catch (\Exception $e) {
            Log::warning("Could not fetch details for product: {$productId}. " . $e->getMessage());
        }

        return [
            'options' => [],
            'variants' => []
        ];
    }

    /**
     * Aligns incoming variant attributes to match parent product options order.
     */
    protected function alignVariantOptions(array $attributes, array $optionNames): array
    {
        $optionValues = [];
        foreach ($optionNames as $name) {
            $foundVal = '';
            foreach ($attributes as $attr) {
                if (isset($attr['attribute']) && strcasecmp($attr['attribute'], $name) === 0) {
                    $foundVal = $attr['value'] ?? '';
                    break;
                }
            }
            $optionValues[] = [
                'optionName' => $name,
                'name' => (string)$foundVal
            ];
        }
        return $optionValues;
    }

    /**
     * Creates or updates a product in Shopify.
     */
    protected function upsertProduct(?string $shopifyProductId, array $productData, array $existingVariants, array $optionsList = []): array
    {
        $status = strtoupper($productData['status'] ?? 'ACTIVE');
        if (!in_array($status, ['ACTIVE', 'DRAFT', 'ARCHIVED'])) {
            $status = 'ACTIVE';
        }

        $input = [
            'vendor' => $productData['vendor'] ?? '',
            'productType' => $productData['product_type'] ?? '',
            'status' => $status,
        ];

        if (array_key_exists('title', $productData)) {
            $input['title'] = $productData['title'];
        }

        if (array_key_exists('body_html', $productData)) {
            $input['descriptionHtml'] = $productData['body_html'] ?? '';
        }

        if (array_key_exists('tags', $productData) && !empty($productData['tags'])) {
            $tags = $productData['tags'];
            if (is_string($tags)) {
                $tags = array_map('trim', explode(',', $tags));
            }
            if (is_array($tags)) {
                $input['tags'] = array_values(array_filter($tags, fn($t) => $t !== ''));
            }
        }

        $defaultVariantGid = null;
        $payloadVariants = $productData['variants'] ?? [];

        if ($shopifyProductId) {
            // Update base product
            $input['id'] = $shopifyProductId;
            $query = <<<'GRAPHQL'
            mutation productUpdate($input: ProductInput!) {
                productUpdate(input: $input) {
                    product {
                        id
                    }
                    userErrors { field message }
                }
            }
            GRAPHQL;
            
            $result = $this->shopify->query($query, ['input' => $input]);
            if (!empty($result['data']['productUpdate']['userErrors'])) {
                throw new \Exception("Shopify error updating product: " . json_encode($result['data']['productUpdate']['userErrors']));
            }
        } else {
            // Create base product
            if (!empty($optionsList)) {
                $optionValues = [];
                foreach ($optionsList as $idx => $optName) {
                    $valuesForOpt = [];
                    foreach ($payloadVariants as $varData) {
                        $foundVal = null;
                        if (isset($varData['optionValues']) && is_array($varData['optionValues'])) {
                            foreach ($varData['optionValues'] as $ov) {
                                if (strcasecmp($ov['optionName'], $optName) === 0) {
                                    $foundVal = $ov['name'];
                                    break;
                                }
                            }
                        }
                        if ($foundVal !== null) {
                            $valuesForOpt[] = $foundVal;
                        }
                    }
                    if (empty($valuesForOpt)) {
                        $valuesForOpt[] = "Default";
                    }
                    $optionValues[] = [
                        'name' => $optName,
                        'values' => array_map(function($val) {
                            return ['name' => (string)$val];
                        }, array_values(array_unique($valuesForOpt)))
                    ];
                }
                $input['productOptions'] = $optionValues;
            }

            $query = <<<'GRAPHQL'
            mutation productCreate($input: ProductInput!) {
                productCreate(input: $input) {
                    product {
                        id
                        variants(first: 1) {
                            edges {
                                node {
                                    id
                                }
                            }
                        }
                    }
                    userErrors { field message }
                }
            }
            GRAPHQL;

            $result = $this->shopify->query($query, ['input' => $input]);
            if (!empty($result['data']['productCreate']['userErrors'])) {
                throw new \Exception("Shopify error creating product: " . json_encode($result['data']['productCreate']['userErrors']));
            }

            $product = $result['data']['productCreate']['product'];
            $shopifyProductId = $product['id'];
            
            // Get the default variant ID created by Shopify
            $defaultEdges = $product['variants']['edges'] ?? [];
            if (!empty($defaultEdges)) {
                $defaultVariantGid = $defaultEdges[0]['node']['id'];
            }
        }

        // Now upsert the variants
        $syncedVariants = [];

        // If product was newly created, map the first payload variant to the default variant
        if ($defaultVariantGid && !empty($payloadVariants)) {
            $firstVar = $payloadVariants[0];
            $firstSku = $firstVar['sku'] ?? null;
            if ($firstSku) {
                $existingVariants[$firstSku] = $defaultVariantGid;
            }
        }

        $variantsToUpdate = [];
        $variantsToCreate = [];

        foreach ($payloadVariants as $varData) {
            $sku = $varData['sku'] ?? null;
            if (!$sku) continue;

            $price = $varData['price'] ?? '0.00';

            $varInput = [
                'price' => $price,
                'inventoryItem' => [
                    'sku' => $sku
                ]
            ];

            if (!empty($varData['optionValues'])) {
                $varInput['optionValues'] = $varData['optionValues'];
            }

            if (isset($existingVariants[$sku])) {
                $varInput['id'] = $existingVariants[$sku];
                $variantsToUpdate[] = $varInput;
            } else {
                $variantsToCreate[] = $varInput;
            }
        }

        // Perform bulk update
        if (!empty($variantsToUpdate)) {
            $updateQuery = <<<'GRAPHQL'
            mutation productVariantsBulkUpdate($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
                productVariantsBulkUpdate(productId: $productId, variants: $variants) {
                    productVariants {
                        id
                        sku
                        inventoryItem {
                            id
                            sku
                        }
                    }
                    userErrors { field message }
                }
            }
            GRAPHQL;

            $updateResult = $this->shopify->query($updateQuery, [
                'productId' => $shopifyProductId,
                'variants' => $variantsToUpdate
            ]);

            if (!empty($updateResult['data']['productVariantsBulkUpdate']['userErrors'])) {
                throw new \Exception("Shopify error updating variants: " . json_encode($updateResult['data']['productVariantsBulkUpdate']['userErrors']));
            }

            $updatedNodes = $updateResult['data']['productVariantsBulkUpdate']['productVariants'] ?? [];
            foreach ($updatedNodes as $node) {
                $skuVal = $node['sku'] ?? $node['inventoryItem']['sku'] ?? null;
                $syncedVariants[] = [
                    'node' => [
                        'id' => $node['id'],
                        'sku' => $skuVal,
                        'inventoryItem' => $node['inventoryItem'] ?? null
                    ]
                ];
            }
        }

        // Perform bulk create
        if (!empty($variantsToCreate)) {
            $createQuery = <<<'GRAPHQL'
            mutation productVariantsBulkCreate($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
                productVariantsBulkCreate(productId: $productId, variants: $variants) {
                    productVariants {
                        id
                        sku
                        inventoryItem {
                            id
                            sku
                        }
                    }
                    userErrors { field message }
                }
            }
            GRAPHQL;

            $createResult = $this->shopify->query($createQuery, [
                'productId' => $shopifyProductId,
                'variants' => $variantsToCreate
            ]);

            if (!empty($createResult['data']['productVariantsBulkCreate']['userErrors'])) {
                throw new \Exception("Shopify error creating variants: " . json_encode($createResult['data']['productVariantsBulkCreate']['userErrors']));
            }

            $createdNodes = $createResult['data']['productVariantsBulkCreate']['productVariants'] ?? [];
            foreach ($createdNodes as $node) {
                $skuVal = $node['sku'] ?? $node['inventoryItem']['sku'] ?? null;
                $syncedVariants[] = [
                    'node' => [
                        'id' => $node['id'],
                        'sku' => $skuVal,
                        'inventoryItem' => $node['inventoryItem'] ?? null
                    ]
                ];
            }
        }

        return [
            'id' => $shopifyProductId,
            'variants' => [
                'edges' => $syncedVariants
            ]
        ];
    }

    /**
     * Syncs inventory quantities to Shopify.
     */
    protected function syncInventory(array $payloadVariants, array $syncedVariants): void
    {
        $locationId = null;

        // Try to get default location
        try {
            $locationId = $this->getDefaultLocationId();
        } catch (\Exception $e) {
            Log::warning("Could not fetch default Shopify location for inventory: " . $e->getMessage());
        }

        if (!$locationId) {
            return;
        }

        $inventoryQuantities = [];

        foreach ($payloadVariants as $varData) {
            $sku = $varData['sku'] ?? null;
            $qty = $varData['inventory_quantity'] ?? null;

            if ($sku && $qty !== null && isset($syncedVariants[$sku])) {
                $inventoryItemId = $syncedVariants[$sku]['inventoryItem']['id'] ?? null;
                if ($inventoryItemId) {
                    $inventoryQuantities[] = [
                        'inventoryItemId' => $inventoryItemId,
                        'locationId' => $locationId,
                        'quantity' => (int)$qty
                    ];
                }
            }
        }

        if (empty($inventoryQuantities)) {
            return;
        }

        $query = <<<'GRAPHQL'
        mutation inventorySetQuantities($input: InventorySetQuantitiesInput!) {
            inventorySetQuantities(input: $input) {
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;

        $variables = [
            'input' => [
                'name' => 'available',
                'reason' => 'correction',
                'quantities' => $inventoryQuantities
            ]
        ];

        try {
            $result = $this->shopify->query($query, $variables);
            if (!empty($result['data']['inventorySetQuantities']['userErrors'])) {
                Log::error("Failed to set Shopify inventory: " . json_encode($result['data']['inventorySetQuantities']['userErrors']));
            }
        } catch (\Exception $e) {
            Log::error("Shopify inventory set exception: " . $e->getMessage());
        }
    }

    /**
     * Gets default Shopify location ID.
     */
    public function getDefaultLocationId(): ?string
    {
        $query = <<<'GRAPHQL'
        query {
            locations(first: 1, query: "active:true") {
                edges {
                    node {
                        id
                    }
                }
            }
        }
        GRAPHQL;

        $response = $this->shopify->query($query);
        $edges = $response['data']['locations']['edges'] ?? [];
        if (!empty($edges)) {
            return $edges[0]['node']['id'];
        }
        return null;
    }

    /**
     * Batch sets metafields in Shopify.
     */
    protected function setMetafields(array $metafields): void
    {
        $query = <<<'GRAPHQL'
        mutation metafieldsSet($metafields: [MetafieldsSetInput!]!) {
            metafieldsSet(metafields: $metafields) {
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;

        $variables = ['metafields' => $metafields];
        $result = $this->shopify->query($query, $variables);

        if (!empty($result['data']['metafieldsSet']['userErrors'])) {
            throw new \Exception("Shopify error setting metafields: " . json_encode($result['data']['metafieldsSet']['userErrors']));
        }
    }

    /**
     * Wraps raw text / HTML into a valid Shopify Rich Text JSON block.
     */
    protected function formatRichText(string $value): string
    {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($decoded['type']) && $decoded['type'] === 'root') {
            return $value;
        }

        $plainText = strip_tags($value);

        $structure = [
            'type' => 'root',
            'children' => [
                [
                    'type' => 'paragraph',
                    'children' => [
                        [
                            'type' => 'text',
                            'value' => $plainText
                        ]
                    ]
                ]
            ]
        ];

        return json_encode($structure);
    }
}
