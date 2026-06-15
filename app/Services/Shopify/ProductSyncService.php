<?php

namespace App\Services\Shopify;

use App\Models\Shop;
use App\Models\ProductMap;
use Illuminate\Support\Facades\Log;

class ProductSyncService
{
    protected ShopifyGraphQLService $shopify;

    protected array $productMetafieldDefinitions = [
        'custom.age' => ['namespace' => 'custom', 'key' => 'age', 'type' => 'single_line_text_field'],
        'specs.product_dimensions' => ['namespace' => 'specs', 'key' => 'product_dimensions', 'type' => 'single_line_text_field'],
        'specs.product_weight' => ['namespace' => 'specs', 'key' => 'product_weight', 'type' => 'single_line_text_field'],
        'specs.package_dimensions' => ['namespace' => 'specs', 'key' => 'package_dimensions', 'type' => 'single_line_text_field'],
        'specs.package_weight' => ['namespace' => 'specs', 'key' => 'package_weight', 'type' => 'single_line_text_field'],
        'custom.material' => ['namespace' => 'custom', 'key' => 'material', 'type' => 'single_line_text_field'],
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
    ];

    protected array $payloadKeyMap = [
        // Product Metafields
        'custom.age' => 'custom.age',
        'age' => 'custom.age',
        'recommended_age' => 'custom.age',
        
        'specs.product_dimensions' => 'specs.product_dimensions',
        'product_dimensions' => 'specs.product_dimensions',
        
        'specs.product_weight' => 'specs.product_weight',
        'product_weight' => 'specs.product_weight',
        
        'specs.package_dimensions' => 'specs.package_dimensions',
        'package_dimensions' => 'specs.package_dimensions',
        
        'specs.package_weight' => 'specs.package_weight',
        'package_weight' => 'specs.package_weight',
        
        'custom.material' => 'custom.material',
        'material' => 'custom.material',
        
        'custom.features_new' => 'custom.features_new',
        'features' => 'custom.features_new',
        'features_new' => 'custom.features_new',
        
        'custom.overview_new' => 'custom.overview_new',
        'overview' => 'custom.overview_new',
        'overview_new' => 'custom.overview_new',
        
        'theme.description_excerpt' => 'theme.description_excerpt',
        'short_description' => 'theme.description_excerpt',
        'description_excerpt' => 'theme.description_excerpt',
        
        'faq.question_1' => 'faq.question_1',
        'faq_question_1' => 'faq.question_1',
        'faq.answer_1' => 'faq.answer_1',
        'faq_answer_1' => 'faq.answer_1',
        
        'faq.question_2' => 'faq.question_2',
        'faq_question_2' => 'faq.question_2',
        'faq.answer_2' => 'faq.answer_2',
        'faq_answer_2' => 'faq.answer_2',
        
        'faq.question_3' => 'faq.question_3',
        'faq_question_3' => 'faq.question_3',
        'faq.answer_3' => 'faq.answer_3',
        'faq_answer_3' => 'faq.answer_3',

        // Variant Metafields
        'custom.arabic_name' => 'custom.arabic_name',
        'arabic_name' => 'custom.arabic_name',
        'custom.rack' => 'custom.rack',
        'rack' => 'custom.rack',
        'custom.bin' => 'custom.bin',
        'bin' => 'custom.bin',
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

        $title = $productData['title'] ?? null;
        if (!$title) {
            throw new \Exception("Product 'title' is required.");
        }

        // Determine if product already exists by checking the first variant SKU in DB mapping
        $firstVariantSku = $productData['variants'][0]['sku'] ?? null;
        $shopifyProductId = null;
        $existingVariants = [];

        if ($firstVariantSku) {
            $mapping = ProductMap::where('erp_item_code', $firstVariantSku)->first();
            if ($mapping && $mapping->shopify_product_id) {
                $shopifyProductId = $mapping->shopify_product_id;
            } else {
                // Fallback: Query Shopify for variant with this SKU
                $shopifyProductId = $this->findProductIdBySku($firstVariantSku);
            }
        }

        // If product ID is resolved, fetch its existing details to get variant GIDs
        if ($shopifyProductId) {
            $existingVariants = $this->getExistingVariants($shopifyProductId);
        }

        // Upsert Product
        $shopifyProduct = $this->upsertProduct($shopifyProductId, $productData, $existingVariants);
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

            if (is_array($value) && isset($value['key'])) {
                // List of objects structure
                $namespace = $value['namespace'] ?? 'custom';
                $metaNamespace = $namespace;
                $metaKey = $value['key'];
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
                }

                $metafieldsToSet[] = [
                    'ownerId' => $shopifyProductId,
                    'namespace' => $def['namespace'],
                    'key' => $def['key'],
                    'type' => $def['type'],
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

                if (is_array($value) && isset($value['key'])) {
                    // List of objects structure
                    $namespace = $value['namespace'] ?? 'custom';
                    $metaNamespace = $namespace;
                    $metaKey = $value['key'];
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
                    $metafieldsToSet[] = [
                        'ownerId' => $variantGid,
                        'namespace' => $def['namespace'],
                        'key' => $def['key'],
                        'type' => $def['type'],
                        'value' => (string)$metaValue
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
    protected function findProductIdBySku(string $sku): ?string
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
     * Creates or updates a product in Shopify.
     */
    protected function upsertProduct(?string $shopifyProductId, array $productData, array $existingVariants): array
    {
        $status = strtoupper($productData['status'] ?? 'ACTIVE');
        if (!in_array($status, ['ACTIVE', 'DRAFT', 'ARCHIVED'])) {
            $status = 'ACTIVE';
        }

        $input = [
            'title' => $productData['title'],
            'descriptionHtml' => $productData['body_html'] ?? '',
            'vendor' => $productData['vendor'] ?? '',
            'productType' => $productData['product_type'] ?? '',
            'status' => $status,
        ];

        $defaultVariantGid = null;

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
        $payloadVariants = $productData['variants'] ?? [];
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

            if (isset($existingVariants[$sku])) {
                $variantsToUpdate[] = [
                    'id' => $existingVariants[$sku],
                    'price' => $price,
                    'inventoryItem' => [
                        'sku' => $sku
                    ]
                ];
            } else {
                $variantsToCreate[] = [
                    'price' => $price,
                    'inventoryItem' => [
                        'sku' => $sku
                    ]
                ];
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
