<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Shop;
use App\Models\ProductMap;
use App\Models\SyncLog;
use App\Services\Shopify\ShopifyGraphQLService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Mockery;

class ExternalProductControllerTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a dummy shop in the database
        Shop::create([
            'shop_domain' => 'test-shop.myshopify.com',
            'access_token' => 'shpat_mock_access_token_123'
        ]);

        // Configure default middleware api key in env config
        config(['services.middleware.api_key' => 'sec_tok_12345']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_sync_external_product_requires_authentication()
    {
        // 1. Missing Token
        $response = $this->postJson('/api/method/middleware.apis.product.sync_external_product', []);
        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => 'Unauthorized. Invalid API token.'
            ]);

        // 2. Invalid Token
        $response = $this->postJson('/api/method/middleware.apis.product.sync_external_product', [], [
            'Authorization' => 'Bearer invalid_token'
        ]);
        $response->assertStatus(401);
    }

    public function test_sync_external_product_validates_input()
    {
        $response = $this->postJson('/api/method/middleware.apis.product.sync_external_product', [], [
            'Authorization' => 'Bearer sec_tok_12345'
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'success',
                'errors' => ['event', 'item']
            ]);
    }

    public function test_sync_external_product_create_event_success()
    {
        $shopifyMock = Mockery::mock(ShopifyGraphQLService::class);

        // 1. Mock search for SKU (return null: does not exist)
        $shopifyMock->shouldReceive('query')
            ->twice()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'findProductBySku');
            }), ['query' => 'sku:TOY-001'])
            ->andReturn([
                'data' => [
                    'productVariants' => [
                        'edges' => []
                    ]
                ]
            ]);

        // 2. Mock productCreate mutation
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productCreate');
            }), Mockery::on(function ($variables) {
                return $variables['input']['title'] === 'Wooden Train'
                    && $variables['input']['status'] === 'ACTIVE'
                    && $variables['input']['vendor'] === 'Test';
            }))
            ->andReturn([
                'data' => [
                    'productCreate' => [
                        'product' => [
                            'id' => 'gid://shopify/Product/111111111',
                            'variants' => [
                                'edges' => [
                                    [
                                        'node' => [
                                            'id' => 'gid://shopify/ProductVariant/222222222_default'
                                        ]
                                    ]
                                ]
                            ]
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        // 3. Mock productVariantsBulkUpdate mutation
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productVariantsBulkUpdate');
            }), Mockery::on(function ($variables) {
                return $variables['productId'] === 'gid://shopify/Product/111111111'
                    && $variables['variants'][0]['id'] === 'gid://shopify/ProductVariant/222222222_default'
                    && $variables['variants'][0]['inventoryItem']['sku'] === 'TOY-001'
                    && $variables['variants'][0]['price'] === '250.00';
            }))
            ->andReturn([
                'data' => [
                    'productVariantsBulkUpdate' => [
                        'productVariants' => [
                            [
                                'id' => 'gid://shopify/ProductVariant/222222222',
                                'sku' => 'TOY-001',
                                'inventoryItem' => [
                                    'id' => 'gid://shopify/InventoryItem/333333333',
                                    'sku' => 'TOY-001'
                                ]
                            ]
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        // 4. Mock location GID query
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'locations');
            }))
            ->andReturn([
                'data' => [
                    'locations' => [
                        'edges' => [
                            [
                                'node' => [
                                    'id' => 'gid://shopify/Location/999999999'
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

        // 5. Mock metafieldsSet mutation
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'metafieldsSet');
            }), Mockery::on(function ($variables) {
                $metafields = $variables['metafields'];
                
                // Assert that default_uom, age, material, and dimensions are set correctly
                $hasUom = false;
                $hasAge = false;
                $hasArabic = false;
                $hasDimensions = false;

                foreach ($metafields as $m) {
                    if ($m['ownerId'] === 'gid://shopify/Product/111111111') {
                        if (($m['key'] === 'recommended_age' || $m['key'] === 'age') && $m['value'] === '3+ Years') $hasAge = true;
                        if ($m['key'] === 'product_dimensions' && $m['value'] === '23X36X76') $hasDimensions = true;
                    }
                    if ($m['ownerId'] === 'gid://shopify/ProductVariant/222222222') {
                        if ($m['key'] === 'default_uom' && $m['value'] === 'PCS') $hasUom = true;
                        if ($m['key'] === 'arabic_name' && $m['value'] === 'قطار خشبي') $hasArabic = true;
                    }
                }
                return $hasAge && $hasDimensions && $hasUom && $hasArabic;
            }))
            ->andReturn([
                'data' => [
                    'metafieldsSet' => [
                        'userErrors' => []
                    ]
                ]
            ]);

        $this->app->bind(ShopifyGraphQLService::class, function () use ($shopifyMock) {
            return $shopifyMock;
        });

        $payload = [
            'event' => 'create',
            'item' => [
                'SKU' => 'TOY-001',
                'product_title' => 'Wooden Train',
                'arabic_title' => 'قطار خشبي',
                'body_html' => 'Premium wooden toy train',
                'brand' => 'Test',
                'status' => 'active',
                'default_uom' => 'PCS',
                'selling_price' => 250.00,
                'recommended_age' => '3+ Years',
                'material' => 'Wood',
                'dimensions' => '23X36X76',
                'product_weight' => '5g',
                'package_dimensions' => '30X42X78',
                'package_weight' => '6g'
            ]
        ];

        $response = $this->postJson('/api/method/middleware.apis.product.sync_external_product', $payload, [
            'Authorization' => 'Bearer sec_tok_12345'
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Product synced successfully to Shopify.',
                'shopify_product_id' => 'gid://shopify/Product/111111111',
                'synced_variants' => [
                    'TOY-001' => 'gid://shopify/ProductVariant/222222222'
                ]
            ]);

        // Verify DB map entry
        $this->assertDatabaseHas('product_maps', [
            'erp_item_code' => 'TOY-001',
            'shopify_product_id' => 'gid://shopify/Product/111111111',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/222222222'
        ]);

        // Verify Sync Log
        $this->assertDatabaseHas('sync_logs', [
            'entity_id' => 'TOY-001',
            'status' => 'success'
        ]);
    }

    public function test_sync_external_product_create_event_already_exists()
    {
        $shopifyMock = Mockery::mock(ShopifyGraphQLService::class);

        // Mock search for SKU (return an ID: product exists)
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'findProductBySku');
            }), ['query' => 'sku:TOY-001'])
            ->andReturn([
                'data' => [
                    'productVariants' => [
                        'edges' => [
                            [
                                'node' => [
                                    'product' => [
                                        'id' => 'gid://shopify/Product/111111111'
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

        $this->app->bind(ShopifyGraphQLService::class, function () use ($shopifyMock) {
            return $shopifyMock;
        });

        $payload = [
            'event' => 'create',
            'item' => [
                'SKU' => 'TOY-001',
                'product_title' => 'Wooden Train'
            ]
        ];

        $response = $this->postJson('/api/method/middleware.apis.product.sync_external_product', $payload, [
            'Authorization' => 'Bearer sec_tok_12345'
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => 'Product with SKU TOY-001 already exists.'
            ]);

        // Sync Log must be updated to failed
        $this->assertDatabaseHas('sync_logs', [
            'entity_id' => 'TOY-001',
            'status' => 'failed',
            'error_message' => 'Product with SKU TOY-001 already exists.'
        ]);
    }

    public function test_sync_external_product_update_event_success()
    {
        $shopifyMock = Mockery::mock(ShopifyGraphQLService::class);

        // Mock search for SKU (return an ID: product exists)
        $shopifyMock->shouldReceive('query')
            ->twice()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'findProductBySku');
            }), ['query' => 'sku:TOY-001'])
            ->andReturn([
                'data' => [
                    'productVariants' => [
                        'edges' => [
                            [
                                'node' => [
                                    'product' => [
                                        'id' => 'gid://shopify/Product/111111111'
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

        // Mock getExistingProductDetails
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'getProductDetails');
            }), ['id' => 'gid://shopify/Product/111111111'])
            ->andReturn([
                'data' => [
                    'product' => [
                        'options' => [],
                        'variants' => [
                            'edges' => [
                                [
                                    'node' => [
                                        'id' => 'gid://shopify/ProductVariant/222222222',
                                        'sku' => 'TOY-001'
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

        // Mock productUpdate
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productUpdate');
            }), Mockery::any())
            ->andReturn([
                'data' => [
                    'productUpdate' => [
                        'product' => [
                            'id' => 'gid://shopify/Product/111111111'
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        // Mock productVariantsBulkUpdate
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productVariantsBulkUpdate');
            }), Mockery::any())
            ->andReturn([
                'data' => [
                    'productVariantsBulkUpdate' => [
                        'productVariants' => [
                            [
                                'id' => 'gid://shopify/ProductVariant/222222222',
                                'sku' => 'TOY-001',
                                'inventoryItem' => [
                                    'id' => 'gid://shopify/InventoryItem/333333333',
                                    'sku' => 'TOY-001'
                                ]
                            ]
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        $this->app->bind(ShopifyGraphQLService::class, function () use ($shopifyMock) {
            return $shopifyMock;
        });

        $payload = [
            'event' => 'update',
            'item' => [
                'SKU' => 'TOY-001',
                'product_title' => 'Wooden Train Updated'
            ]
        ];

        $response = $this->postJson('/api/method/middleware.apis.product.sync_external_product', $payload, [
            'Authorization' => 'Bearer sec_tok_12345'
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Product synced successfully to Shopify.',
                'shopify_product_id' => 'gid://shopify/Product/111111111',
                'synced_variants' => [
                    'TOY-001' => 'gid://shopify/ProductVariant/222222222'
                ]
            ]);

        $this->assertDatabaseHas('sync_logs', [
            'entity_id' => 'TOY-001',
            'status' => 'success'
        ]);
    }

    public function test_sync_external_product_update_event_not_found()
    {
        $shopifyMock = Mockery::mock(ShopifyGraphQLService::class);

        // Mock search for SKU (return null: not found)
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'findProductBySku');
            }), ['query' => 'sku:TOY-001'])
            ->andReturn([
                'data' => [
                    'productVariants' => [
                        'edges' => []
                    ]
                ]
            ]);

        $this->app->bind(ShopifyGraphQLService::class, function () use ($shopifyMock) {
            return $shopifyMock;
        });

        $payload = [
            'event' => 'update',
            'item' => [
                'SKU' => 'TOY-001',
                'product_title' => 'Wooden Train Updated'
            ]
        ];

        $response = $this->postJson('/api/method/middleware.apis.product.sync_external_product', $payload, [
            'Authorization' => 'Bearer sec_tok_12345'
        ]);

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'error' => 'Product with SKU TOY-001 does not exist.'
            ]);
    }

    public function test_sync_external_product_delete_event_success()
    {
        // Add mapping first so it's resolved via DB check
        ProductMap::create([
            'erp_item_code' => 'TOY-001',
            'shopify_product_id' => 'gid://shopify/Product/111111111',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/222222222'
        ]);

        $shopifyMock = Mockery::mock(ShopifyGraphQLService::class);

        // Mock productDelete mutation
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productDelete');
            }), [
                'input' => [
                    'id' => 'gid://shopify/Product/111111111'
                ]
            ])
            ->andReturn([
                'data' => [
                    'productDelete' => [
                        'deletedProductId' => 'gid://shopify/Product/111111111',
                        'userErrors' => []
                    ]
                ]
            ]);

        $this->app->bind(ShopifyGraphQLService::class, function () use ($shopifyMock) {
            return $shopifyMock;
        });

        $payload = [
            'event' => 'delete',
            'item' => [
                'SKU' => 'TOY-001'
            ]
        ];

        $response = $this->postJson('/api/method/middleware.apis.product.sync_external_product', $payload, [
            'Authorization' => 'Bearer sec_tok_12345'
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Product with SKU TOY-001 deleted successfully from Shopify.'
            ]);

        // DB Mapping must be deleted
        $this->assertDatabaseMissing('product_maps', [
            'erp_item_code' => 'TOY-001'
        ]);

        $this->assertDatabaseHas('sync_logs', [
            'entity_id' => 'TOY-001',
            'status' => 'success'
        ]);
    }

    public function test_bulk_sync_external_product_requires_authentication()
    {
        // 1. Missing Token
        $response = $this->postJson('/api/method/middleware.apis.product.bulk_sync_external_product', []);
        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => 'Unauthorized. Invalid API token.'
            ]);

        // 2. Invalid Token
        $response = $this->postJson('/api/method/middleware.apis.product.bulk_sync_external_product', [], [
            'Authorization' => 'Bearer invalid_token'
        ]);
        $response->assertStatus(401);
    }

    public function test_bulk_sync_external_product_validates_input()
    {
        $response = $this->postJson('/api/method/middleware.apis.product.bulk_sync_external_product', [], [
            'Authorization' => 'Bearer sec_tok_12345'
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'success',
                'errors' => ['event', 'items']
            ]);
    }

    public function test_bulk_sync_external_product_create_success()
    {
        $shopifyMock = Mockery::mock(ShopifyGraphQLService::class);

        // First item (TOY-001) mocks
        // 1. Mock search for SKU (return null: does not exist)
        $shopifyMock->shouldReceive('query')
            ->twice()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'findProductBySku');
            }), ['query' => 'sku:TOY-001'])
            ->andReturn([
                'data' => [
                    'productVariants' => [
                        'edges' => []
                    ]
                ]
            ]);

        // 2. Mock productCreate mutation
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productCreate');
            }), Mockery::on(function ($variables) {
                return $variables['input']['title'] === 'Wooden Train'
                    && $variables['input']['status'] === 'ACTIVE'
                    && $variables['input']['vendor'] === 'Test'
                    && $variables['input']['productType'] === 'Toys';
            }))
            ->andReturn([
                'data' => [
                    'productCreate' => [
                        'product' => [
                            'id' => 'gid://shopify/Product/111111111',
                            'variants' => [
                                'edges' => [
                                    [
                                        'node' => [
                                            'id' => 'gid://shopify/ProductVariant/222222222_default'
                                        ]
                                    ]
                                ]
                            ]
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        // 3. Mock productVariantsBulkUpdate mutation
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productVariantsBulkUpdate');
            }), Mockery::on(function ($variables) {
                return $variables['productId'] === 'gid://shopify/Product/111111111'
                    && $variables['variants'][0]['id'] === 'gid://shopify/ProductVariant/222222222_default'
                    && $variables['variants'][0]['inventoryItem']['sku'] === 'TOY-001'
                    && $variables['variants'][0]['price'] === '250.00';
            }))
            ->andReturn([
                'data' => [
                    'productVariantsBulkUpdate' => [
                        'productVariants' => [
                            [
                                'id' => 'gid://shopify/ProductVariant/222222222',
                                'sku' => 'TOY-001',
                                'inventoryItem' => [
                                    'id' => 'gid://shopify/InventoryItem/333333333',
                                    'sku' => 'TOY-001'
                                ]
                            ]
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        // 4. Mock location GID query
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'locations');
            }))
            ->andReturn([
                'data' => [
                    'locations' => [
                        'edges' => [
                            [
                                'node' => [
                                    'id' => 'gid://shopify/Location/999999999'
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

        // 5. Mock metafieldsSet mutation
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'metafieldsSet');
            }), Mockery::on(function ($variables) {
                $metafields = $variables['metafields'];
                $hasUom = false;
                $hasAge = false;
                $hasArabic = false;
                $hasDimensions = false;

                foreach ($metafields as $m) {
                    if ($m['ownerId'] === 'gid://shopify/Product/111111111') {
                        if (($m['key'] === 'recommended_age' || $m['key'] === 'age') && $m['value'] === '3+ Years') $hasAge = true;
                        if ($m['key'] === 'product_dimensions' && $m['value'] === '23X36X76') $hasDimensions = true;
                    }
                    if ($m['ownerId'] === 'gid://shopify/ProductVariant/222222222') {
                        if ($m['key'] === 'default_uom' && $m['value'] === 'PCS') $hasUom = true;
                        if ($m['key'] === 'arabic_name' && $m['value'] === 'قطار خشبي') $hasArabic = true;
                    }
                }
                return $hasAge && $hasDimensions && $hasUom && $hasArabic;
            }))
            ->andReturn([
                'data' => [
                    'metafieldsSet' => [
                        'userErrors' => []
                    ]
                ]
            ]);


        // Second item (TOY-002) mocks - Let's mock a failure: it already exists
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'findProductBySku');
            }), ['query' => 'sku:TOY-002'])
            ->andReturn([
                'data' => [
                    'productVariants' => [
                        'edges' => [
                            [
                                'node' => [
                                    'product' => [
                                        'id' => 'gid://shopify/Product/444444444'
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

        $this->app->bind(ShopifyGraphQLService::class, function () use ($shopifyMock) {
            return $shopifyMock;
        });

        $payload = [
            'event' => 'create',
            'items' => [
                [
                    'SKU' => 'TOY-001',
                    'product_title' => 'Wooden Train',
                    'arabic_title' => 'قطار خشبي',
                    'product_category' => 'Toys',
                    'body_html' => 'Premium wooden toy train',
                    'brand' => 'Test',
                    'status' => 'active',
                    'default_uom' => 'PCS',
                    'selling_price' => 250.00,
                    'recommended_age' => '3+ Years',
                    'material' => 'Wood',
                    'dimensions' => '23X36X76',
                    'product_weight' => '5g',
                    'package_dimensions' => '30X42X78',
                    'package_weight' => '6g'
                ],
                [
                    'SKU' => 'TOY-002',
                    'product_title' => 'Plastic Train',
                    'product_category' => 'Toys'
                ]
            ]
        ];

        $response = $this->postJson('/api/method/middleware.apis.product.bulk_sync_external_product', $payload, [
            'Authorization' => 'Bearer sec_tok_12345'
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Bulk product sync completed.',
                'results' => [
                    [
                        'sku' => 'TOY-001',
                        'success' => true,
                        'message' => 'Product synced successfully to Shopify.',
                        'shopify_product_id' => 'gid://shopify/Product/111111111',
                        'synced_variants' => [
                            'TOY-001' => 'gid://shopify/ProductVariant/222222222'
                        ]
                    ],
                    [
                        'sku' => 'TOY-002',
                        'success' => false,
                        'error' => 'Product with SKU TOY-002 already exists.'
                    ]
                ]
            ]);

        // Verify TOY-001 DB map entry
        $this->assertDatabaseHas('product_maps', [
            'erp_item_code' => 'TOY-001',
            'shopify_product_id' => 'gid://shopify/Product/111111111',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/222222222'
        ]);

        // Verify TOY-001 Sync Log
        $this->assertDatabaseHas('sync_logs', [
            'entity_id' => 'TOY-001',
            'status' => 'success'
        ]);

        // Verify TOY-002 Sync Log is failed
        $this->assertDatabaseHas('sync_logs', [
            'entity_id' => 'TOY-002',
            'status' => 'failed',
            'error_message' => 'Product with SKU TOY-002 already exists.'
        ]);
    }

    public function test_sync_external_product_variant_create_success()
    {
        $shopifyMock = Mockery::mock(ShopifyGraphQLService::class);

        // 1. Mock search for variant SKU (return null: does not exist)
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'findProductBySku');
            }), ['query' => 'sku:ITEM-0016-RED-S'])
            ->andReturn([
                'data' => [
                    'productVariants' => [
                        'edges' => []
                    ]
                ]
            ]);

        // 2. Mock search for parent SKU (return null: does not exist)
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'findProductBySku');
            }), ['query' => 'sku:ITEM-0016'])
            ->andReturn([
                'data' => [
                    'productVariants' => [
                        'edges' => []
                    ]
                ]
            ]);

        // 3. Mock productCreate mutation with options
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productCreate');
            }), Mockery::on(function ($variables) {
                $options = $variables['input']['productOptions'] ?? [];
                $hasColour = false;
                $hasSize = false;

                foreach ($options as $opt) {
                    $vals = array_column($opt['values'] ?? [], 'name');
                    if ($opt['name'] === 'Colour' && in_array('Red', $vals)) {
                        $hasColour = true;
                    }
                    if ($opt['name'] === 'Size' && in_array('Small', $vals)) {
                        $hasSize = true;
                    }
                }

                return $variables['input']['title'] === 'SHIRT-RED-S'
                    && $variables['input']['status'] === 'ACTIVE'
                    && $hasColour && $hasSize;
            }))
            ->andReturn([
                'data' => [
                    'productCreate' => [
                        'product' => [
                            'id' => 'gid://shopify/Product/999111',
                            'variants' => [
                                'edges' => [
                                    [
                                        'node' => [
                                            'id' => 'gid://shopify/ProductVariant/888111_default'
                                        ]
                                    ]
                                ]
                            ]
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        // 4. Mock productVariantsBulkUpdate mutation (default variant conversion)
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productVariantsBulkUpdate');
            }), Mockery::on(function ($variables) {
                return $variables['productId'] === 'gid://shopify/Product/999111'
                    && $variables['variants'][0]['id'] === 'gid://shopify/ProductVariant/888111_default'
                    && $variables['variants'][0]['inventoryItem']['sku'] === 'ITEM-0016-RED-S'
                    && $variables['variants'][0]['optionValues'] === [
                        ['optionName' => 'Colour', 'name' => 'Red'],
                        ['optionName' => 'Size', 'name' => 'Small']
                    ];
            }))
            ->andReturn([
                'data' => [
                    'productVariantsBulkUpdate' => [
                        'productVariants' => [
                            [
                                'id' => 'gid://shopify/ProductVariant/888111',
                                'sku' => 'ITEM-0016-RED-S',
                                'inventoryItem' => [
                                    'id' => 'gid://shopify/InventoryItem/777111',
                                    'sku' => 'ITEM-0016-RED-S'
                                ]
                            ]
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        // 5. Mock location GID query
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'locations');
            }))
            ->andReturn([
                'data' => [
                    'locations' => [
                        'edges' => [
                            [
                                'node' => [
                                    'id' => 'gid://shopify/Location/999999999'
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

        // 6. Mock metafieldsSet mutation
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'metafieldsSet');
            }), Mockery::any())
            ->andReturn([
                'data' => [
                    'metafieldsSet' => [
                        'userErrors' => []
                    ]
                ]
            ]);

        $this->app->bind(ShopifyGraphQLService::class, function () use ($shopifyMock) {
            return $shopifyMock;
        });

        $payload = [
            'event' => 'create',
            'item' => [
                'SKU' => 'ITEM-0016-RED-S',
                'is_variant' => true,
                'parent_sku' => 'ITEM-0016',
                'product_title' => 'SHIRT-RED-S',
                'arabic_title' => null,
                'product_category' => 'Products',
                'body_html' => 'SHIRT-RED-S',
                'brand' => null,
                'status' => 'active',
                'default_uom' => 'Nos',
                'selling_price' => null,
                'recommended_age' => null,
                'material' => null,
                'dimensions' => null,
                'product_weight' => null,
                'package_dimensions' => null,
                'package_weight' => null,
                'attributes' => [
                    [
                        'attribute' => 'Colour',
                        'value' => 'Red'
                    ],
                    [
                        'attribute' => 'Size',
                        'value' => 'Small'
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/method/middleware.apis.product.sync_external_product', $payload, [
            'Authorization' => 'Bearer sec_tok_12345'
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'shopify_product_id' => 'gid://shopify/Product/999111',
                'synced_variants' => [
                    'ITEM-0016-RED-S' => 'gid://shopify/ProductVariant/888111'
                ]
            ]);

        // Verify DB map entries
        $this->assertDatabaseHas('product_maps', [
            'erp_item_code' => 'ITEM-0016-RED-S',
            'shopify_product_id' => 'gid://shopify/Product/999111',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/888111'
        ]);

        $this->assertDatabaseHas('product_maps', [
            'erp_item_code' => 'ITEM-0016',
            'shopify_product_id' => 'gid://shopify/Product/999111',
            'shopify_variant_id' => null
        ]);
    }

    public function test_sync_external_product_variant_create_existing_parent()
    {
        // Pre-create parent map
        ProductMap::create([
            'erp_item_code' => 'ITEM-0016',
            'shopify_product_id' => 'gid://shopify/Product/999111',
            'shopify_variant_id' => null
        ]);

        $shopifyMock = Mockery::mock(ShopifyGraphQLService::class);

        // 1. Mock search for variant SKU (return null: does not exist)
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'findProductBySku');
            }), ['query' => 'sku:ITEM-0016-RED-S'])
            ->andReturn([
                'data' => [
                    'productVariants' => [
                        'edges' => []
                    ]
                ]
            ]);

        // 2. Mock getExistingProductDetails
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'getProductDetails');
            }), ['id' => 'gid://shopify/Product/999111'])
            ->andReturn([
                'data' => [
                    'product' => [
                        'options' => [
                            ['name' => 'Colour'],
                            ['name' => 'Size']
                        ],
                        'variants' => [
                            'edges' => []
                        ]
                    ]
                ]
            ]);

        // 3. Mock productUpdate
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productUpdate');
            }), Mockery::any())
            ->andReturn([
                'data' => [
                    'productUpdate' => [
                        'product' => [
                            'id' => 'gid://shopify/Product/999111'
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        // 4. Mock productVariantsBulkCreate
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productVariantsBulkCreate');
            }), Mockery::on(function ($variables) {
                return $variables['productId'] === 'gid://shopify/Product/999111'
                    && $variables['variants'][0]['inventoryItem']['sku'] === 'ITEM-0016-RED-S'
                    && $variables['variants'][0]['optionValues'] === [
                        ['optionName' => 'Colour', 'name' => 'Red'],
                        ['optionName' => 'Size', 'name' => 'Small']
                    ];
            }))
            ->andReturn([
                'data' => [
                    'productVariantsBulkCreate' => [
                        'productVariants' => [
                            [
                                'id' => 'gid://shopify/ProductVariant/888111',
                                'sku' => 'ITEM-0016-RED-S',
                                'inventoryItem' => [
                                    'id' => 'gid://shopify/InventoryItem/777111',
                                    'sku' => 'ITEM-0016-RED-S'
                                ]
                            ]
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        // 5. Mock location GID query
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'locations');
            }))
            ->andReturn([
                'data' => [
                    'locations' => [
                        'edges' => [
                            [
                                'node' => [
                                    'id' => 'gid://shopify/Location/999999999'
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

        // 6. Mock metafieldsSet mutation
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'metafieldsSet');
            }), Mockery::any())
            ->andReturn([
                'data' => [
                    'metafieldsSet' => [
                        'userErrors' => []
                    ]
                ]
            ]);

        $this->app->bind(ShopifyGraphQLService::class, function () use ($shopifyMock) {
            return $shopifyMock;
        });

        $payload = [
            'event' => 'create',
            'item' => [
                'SKU' => 'ITEM-0016-RED-S',
                'is_variant' => true,
                'parent_sku' => 'ITEM-0016',
                'product_title' => 'SHIRT-RED-S',
                'arabic_title' => null,
                'product_category' => 'Products',
                'body_html' => 'SHIRT-RED-S',
                'brand' => null,
                'status' => 'active',
                'default_uom' => 'Nos',
                'selling_price' => null,
                'recommended_age' => null,
                'material' => null,
                'dimensions' => null,
                'product_weight' => null,
                'package_dimensions' => null,
                'package_weight' => null,
                'attributes' => [
                    [
                        'attribute' => 'Colour',
                        'value' => 'Red'
                    ],
                    [
                        'attribute' => 'Size',
                        'value' => 'Small'
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/method/middleware.apis.product.sync_external_product', $payload, [
            'Authorization' => 'Bearer sec_tok_12345'
        ]);

        $response->assertStatus(200);

        // Verify DB variant map entry is created
        $this->assertDatabaseHas('product_maps', [
            'erp_item_code' => 'ITEM-0016-RED-S',
            'shopify_product_id' => 'gid://shopify/Product/999111',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/888111'
        ]);
    }

    public function test_sync_external_product_variant_delete_only_variant()
    {
        // Pre-create maps for parent and two variants
        ProductMap::create([
            'erp_item_code' => 'ITEM-0016',
            'shopify_product_id' => 'gid://shopify/Product/999111',
            'shopify_variant_id' => null
        ]);
        ProductMap::create([
            'erp_item_code' => 'ITEM-0016-RED-S',
            'shopify_product_id' => 'gid://shopify/Product/999111',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/888111'
        ]);
        ProductMap::create([
            'erp_item_code' => 'ITEM-0016-BLUE-M',
            'shopify_product_id' => 'gid://shopify/Product/999111',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/888222'
        ]);

        $shopifyMock = Mockery::mock(ShopifyGraphQLService::class);

        // Mock productVariantsBulkDelete mutation
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productVariantsBulkDelete');
            }), [
                'productId' => 'gid://shopify/Product/999111',
                'variantsIds' => ['gid://shopify/ProductVariant/888111']
            ])
            ->andReturn([
                'data' => [
                    'productVariantsBulkDelete' => [
                        'product' => [
                            'id' => 'gid://shopify/Product/999111'
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        $this->app->bind(ShopifyGraphQLService::class, function () use ($shopifyMock) {
            return $shopifyMock;
        });

        $payload = [
            'event' => 'delete',
            'item' => [
                'SKU' => 'ITEM-0016-RED-S'
            ]
        ];

        $response = $this->postJson('/api/method/middleware.apis.product.sync_external_product', $payload, [
            'Authorization' => 'Bearer sec_tok_12345'
        ]);

        $response->assertStatus(200);

        // Variant Map should be deleted
        $this->assertDatabaseMissing('product_maps', [
            'erp_item_code' => 'ITEM-0016-RED-S'
        ]);

        // Other variant map and parent map should still exist
        $this->assertDatabaseHas('product_maps', [
            'erp_item_code' => 'ITEM-0016-BLUE-M'
        ]);
        $this->assertDatabaseHas('product_maps', [
            'erp_item_code' => 'ITEM-0016'
        ]);
    }

    public function test_sync_external_product_variant_update_without_parent_title_keeps_title()
    {
        // Pre-create parent map
        ProductMap::create([
            'erp_item_code' => 'ITEM-0016',
            'shopify_product_id' => 'gid://shopify/Product/999111',
            'shopify_variant_id' => null
        ]);
        ProductMap::create([
            'erp_item_code' => 'ITEM-0016-RED-S',
            'shopify_product_id' => 'gid://shopify/Product/999111',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/888111'
        ]);

        $shopifyMock = Mockery::mock(ShopifyGraphQLService::class);

        // 1. Mock getExistingProductDetails
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'getProductDetails');
            }), ['id' => 'gid://shopify/Product/999111'])
            ->andReturn([
                'data' => [
                    'product' => [
                        'options' => [
                            ['name' => 'Colour'],
                            ['name' => 'Size']
                        ],
                        'variants' => [
                            'edges' => [
                                [
                                    'node' => [
                                        'id' => 'gid://shopify/ProductVariant/888111',
                                        'sku' => 'ITEM-0016-RED-S'
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

        // 2. Mock productUpdate - Assert that 'title' and 'descriptionHtml' are NOT in input
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productUpdate');
            }), Mockery::on(function ($variables) {
                return !array_key_exists('title', $variables['input'])
                    && !array_key_exists('descriptionHtml', $variables['input']);
            }))
            ->andReturn([
                'data' => [
                    'productUpdate' => [
                        'product' => [
                            'id' => 'gid://shopify/Product/999111'
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        // 3. Mock productVariantsBulkUpdate
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productVariantsBulkUpdate');
            }), Mockery::any())
            ->andReturn([
                'data' => [
                    'productVariantsBulkUpdate' => [
                        'productVariants' => [
                            [
                                'id' => 'gid://shopify/ProductVariant/888111',
                                'sku' => 'ITEM-0016-RED-S',
                                'inventoryItem' => [
                                    'id' => 'gid://shopify/InventoryItem/777111',
                                    'sku' => 'ITEM-0016-RED-S'
                                ]
                            ]
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        $this->app->bind(ShopifyGraphQLService::class, function () use ($shopifyMock) {
            return $shopifyMock;
        });

        $payload = [
            'event' => 'update',
            'item' => [
                'SKU' => 'ITEM-0016-RED-S',
                'is_variant' => true,
                'parent_sku' => 'ITEM-0016',
                'product_title' => 'SHIRT-RED-S',
                'body_html' => 'SHIRT-RED-S',
                'status' => 'active',
                'selling_price' => 120.00,
            ]
        ];

        $response = $this->postJson('/api/method/middleware.apis.product.sync_external_product', $payload, [
            'Authorization' => 'Bearer sec_tok_12345'
        ]);

        $response->assertStatus(200);
    }

    public function test_sync_external_product_variant_update_with_parent_title_updates_title()
    {
        // Pre-create parent map
        ProductMap::create([
            'erp_item_code' => 'ITEM-0016',
            'shopify_product_id' => 'gid://shopify/Product/999111',
            'shopify_variant_id' => null
        ]);
        ProductMap::create([
            'erp_item_code' => 'ITEM-0016-RED-S',
            'shopify_product_id' => 'gid://shopify/Product/999111',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/888111'
        ]);

        $shopifyMock = Mockery::mock(ShopifyGraphQLService::class);

        // 1. Mock getExistingProductDetails
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'getProductDetails');
            }), ['id' => 'gid://shopify/Product/999111'])
            ->andReturn([
                'data' => [
                    'product' => [
                        'options' => [
                            ['name' => 'Colour'],
                            ['name' => 'Size']
                        ],
                        'variants' => [
                            'edges' => [
                                [
                                    'node' => [
                                        'id' => 'gid://shopify/ProductVariant/888111',
                                        'sku' => 'ITEM-0016-RED-S'
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

        // 2. Mock productUpdate - Assert that 'title' and 'descriptionHtml' are in input
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productUpdate');
            }), Mockery::on(function ($variables) {
                return isset($variables['input']['title'])
                    && $variables['input']['title'] === 'New Shirt Parent'
                    && isset($variables['input']['descriptionHtml'])
                    && $variables['input']['descriptionHtml'] === 'New Parent Description';
            }))
            ->andReturn([
                'data' => [
                    'productUpdate' => [
                        'product' => [
                            'id' => 'gid://shopify/Product/999111'
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        // 3. Mock productVariantsBulkUpdate
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productVariantsBulkUpdate');
            }), Mockery::any())
            ->andReturn([
                'data' => [
                    'productVariantsBulkUpdate' => [
                        'productVariants' => [
                            [
                                'id' => 'gid://shopify/ProductVariant/888111',
                                'sku' => 'ITEM-0016-RED-S',
                                'inventoryItem' => [
                                    'id' => 'gid://shopify/InventoryItem/777111',
                                    'sku' => 'ITEM-0016-RED-S'
                                ]
                            ]
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        $this->app->bind(ShopifyGraphQLService::class, function () use ($shopifyMock) {
            return $shopifyMock;
        });

        $payload = [
            'event' => 'update',
            'item' => [
                'SKU' => 'ITEM-0016-RED-S',
                'is_variant' => true,
                'parent_sku' => 'ITEM-0016',
                'product_title' => 'SHIRT-RED-S',
                'parent_title' => 'New Shirt Parent',
                'parent_body_html' => 'New Parent Description',
                'status' => 'active',
                'selling_price' => 120.00,
            ]
        ];

        $response = $this->postJson('/api/method/middleware.apis.product.sync_external_product', $payload, [
            'Authorization' => 'Bearer sec_tok_12345'
        ]);

        $response->assertStatus(200);
    }

    public function test_sync_external_product_variant_delete_without_db_mapping()
    {
        $shopifyMock = Mockery::mock(ShopifyGraphQLService::class);

        // 1. Mock search for variant SKU (findProductAndVariantBySku)
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'findProductAndVariantBySku');
            }), ['query' => 'sku:ITEM-0016-RED-S'])
            ->andReturn([
                'data' => [
                    'productVariants' => [
                        'edges' => [
                            [
                                'node' => [
                                    'id' => 'gid://shopify/ProductVariant/888111',
                                    'product' => [
                                        'id' => 'gid://shopify/Product/999111'
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

        // 2. Mock getExistingVariants
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'getProductVariants');
            }), ['id' => 'gid://shopify/Product/999111'])
            ->andReturn([
                'data' => [
                    'product' => [
                        'variants' => [
                            'edges' => [
                                [
                                    'node' => [
                                        'id' => 'gid://shopify/ProductVariant/888111',
                                        'sku' => 'ITEM-0016-RED-S'
                                    ]
                                ],
                                [
                                    'node' => [
                                        'id' => 'gid://shopify/ProductVariant/888222',
                                        'sku' => 'ITEM-0016-BLUE-M'
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

        // 3. Mock productVariantsBulkDelete mutation
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productVariantsBulkDelete');
            }), [
                'productId' => 'gid://shopify/Product/999111',
                'variantsIds' => ['gid://shopify/ProductVariant/888111']
            ])
            ->andReturn([
                'data' => [
                    'productVariantsBulkDelete' => [
                        'product' => [
                            'id' => 'gid://shopify/Product/999111'
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        $this->app->bind(ShopifyGraphQLService::class, function () use ($shopifyMock) {
            return $shopifyMock;
        });

        $payload = [
            'event' => 'delete',
            'item' => [
                'SKU' => 'ITEM-0016-RED-S',
                'is_variant' => true,
                'parent_sku' => 'ITEM-0016'
            ]
        ];

        $response = $this->postJson('/api/method/middleware.apis.product.sync_external_product', $payload, [
            'Authorization' => 'Bearer sec_tok_12345'
        ]);

        $response->assertStatus(200);
    }

    public function test_bulk_sync_external_product_with_variants()
    {
        $shopifyMock = Mockery::mock(ShopifyGraphQLService::class);

        // --- Mocks for ITEM-0032-S (Variant, Parent does not exist) ---
        // 1. Controller checks variant existence: findProductBySku (not found)
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'findProductBySku');
            }), ['query' => 'sku:ITEM-0032-S'])
            ->andReturn([
                'data' => [
                    'productVariants' => [
                        'edges' => []
                    ]
                ]
            ]);

        // 2. Service checks parent existence: findProductBySku (not found)
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'findProductBySku');
            }), ['query' => 'sku:ITEM-0032'])
            ->andReturn([
                'data' => [
                    'productVariants' => [
                        'edges' => []
                    ]
                ]
            ]);

        // 3. Service creates parent product + variant
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productCreate');
            }), Mockery::on(function ($variables) {
                return $variables['input']['title'] === 'test'
                    && $variables['input']['status'] === 'ACTIVE';
            }))
            ->andReturn([
                'data' => [
                    'productCreate' => [
                        'product' => [
                            'id' => 'gid://shopify/Product/323232',
                            'variants' => [
                                'edges' => [
                                    [
                                        'node' => [
                                            'id' => 'gid://shopify/ProductVariant/323232001_default'
                                        ]
                                    ]
                                ]
                            ]
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        // 4. Service maps default variant to ITEM-0032-S
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productVariantsBulkUpdate');
            }), Mockery::on(function ($variables) {
                return $variables['productId'] === 'gid://shopify/Product/323232'
                    && $variables['variants'][0]['id'] === 'gid://shopify/ProductVariant/323232001_default'
                    && $variables['variants'][0]['inventoryItem']['sku'] === 'ITEM-0032-S';
            }))
            ->andReturn([
                'data' => [
                    'productVariantsBulkUpdate' => [
                        'productVariants' => [
                            [
                                'id' => 'gid://shopify/ProductVariant/323232001',
                                'sku' => 'ITEM-0032-S',
                                'inventoryItem' => [
                                    'id' => 'gid://shopify/InventoryItem/323232991',
                                    'sku' => 'ITEM-0032-S'
                                ]
                            ]
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);


        // --- Mocks for ITEM-0031 (Normal product) ---
        // 5. Controller checks product existence: findProductBySku (not found)
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'findProductBySku');
            }), ['query' => 'sku:ITEM-0031'])
            ->andReturn([
                'data' => [
                    'productVariants' => [
                        'edges' => []
                    ]
                ]
            ]);

        // 6. Service checks product existence: findProductBySku (not found)
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'findProductBySku');
            }), ['query' => 'sku:ITEM-0031'])
            ->andReturn([
                'data' => [
                    'productVariants' => [
                        'edges' => []
                    ]
                ]
            ]);

        // 7. Service creates product
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productCreate');
            }), Mockery::on(function ($variables) {
                return $variables['input']['title'] === 'test'
                    && $variables['input']['status'] === 'ACTIVE';
            }))
            ->andReturn([
                'data' => [
                    'productCreate' => [
                        'product' => [
                            'id' => 'gid://shopify/Product/313131',
                            'variants' => [
                                'edges' => [
                                    [
                                        'node' => [
                                            'id' => 'gid://shopify/ProductVariant/313131001_default'
                                        ]
                                    ]
                                ]
                            ]
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        // 8. Service maps default variant to ITEM-0031
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productVariantsBulkUpdate');
            }), Mockery::on(function ($variables) {
                return $variables['productId'] === 'gid://shopify/Product/313131'
                    && $variables['variants'][0]['id'] === 'gid://shopify/ProductVariant/313131001_default'
                    && $variables['variants'][0]['inventoryItem']['sku'] === 'ITEM-0031';
            }))
            ->andReturn([
                'data' => [
                    'productVariantsBulkUpdate' => [
                        'productVariants' => [
                            [
                                'id' => 'gid://shopify/ProductVariant/313131001',
                                'sku' => 'ITEM-0031',
                                'inventoryItem' => [
                                    'id' => 'gid://shopify/InventoryItem/313131991',
                                    'sku' => 'ITEM-0031'
                                ]
                            ]
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        // --- Common Mocks (Locations and Metafields) ---
        // 9. Fetch location GID (called for each successful sync where inventory is updated, though price/inventory is not explicitly tested here but service does fetch location)
        $shopifyMock->shouldReceive('query')
            ->andReturn([
                'data' => [
                    'locations' => [
                        'edges' => [
                            [
                                'node' => [
                                    'id' => 'gid://shopify/Location/999999999'
                                ]
                            ]
                        ]
                    ],
                    'metafieldsSet' => [
                        'userErrors' => []
                    ]
                ]
            ]);

        $this->app->bind(ShopifyGraphQLService::class, function () use ($shopifyMock) {
            return $shopifyMock;
        });

        $payload = [
            'event' => 'create',
            'items' => [
                [
                    'SKU' => 'ITEM-0032-S',
                    'is_variant' => true,
                    'parent_sku' => 'ITEM-0032',
                    'parent_title' => 'test',
                    'parent_body_html' => 'test',
                    'product_title' => 'test-S',
                    'arabic_title' => null,
                    'product_category' => 'Products',
                    'product_body_html' => 'test-S',
                    'brand' => null,
                    'status' => 'active',
                    'default_uom' => 'Nos',
                    'selling_price' => null,
                    'recommended_age' => null,
                    'material' => null,
                    'dimensions' => null,
                    'product_weight' => null,
                    'package_dimensions' => null,
                    'package_weight' => null,
                    'attributes' => [
                        [
                            'attribute' => 'Size',
                            'value' => 'Small'
                        ]
                    ]
                ],
                [
                    'SKU' => 'ITEM-0031',
                    'is_variant' => false,
                    'parent_sku' => null,
                    'parent_title' => null,
                    'parent_body_html' => null,
                    'product_title' => 'test',
                    'arabic_title' => '',
                    'product_category' => 'Products',
                    'product_body_html' => 'test',
                    'brand' => null,
                    'status' => 'active',
                    'default_uom' => 'Nos',
                    'selling_price' => null,
                    'recommended_age' => null,
                    'material' => null,
                    'dimensions' => null,
                    'product_weight' => null,
                    'package_dimensions' => null,
                    'package_weight' => null,
                    'attributes' => []
                ]
            ]
        ];

        $response = $this->postJson('/api/method/middleware.apis.product.bulk_sync_external_product', $payload, [
            'Authorization' => 'Bearer sec_tok_12345'
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('results.0.sku', 'ITEM-0032-S');
        $response->assertJsonPath('results.0.success', true);
        $response->assertJsonPath('results.1.sku', 'ITEM-0031');
        $response->assertJsonPath('results.1.success', true);
    }

    public function test_bulk_sync_external_product_with_variants_update_success()
    {
        $shopifyMock = Mockery::mock(ShopifyGraphQLService::class);

        // 1. Controller checks parent existence for ITEM-0032 (found)
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'findProductBySku');
            }), ['query' => 'sku:ITEM-0032'])
            ->andReturn([
                'data' => [
                    'productVariants' => [
                        'edges' => [
                            [
                                'node' => [
                                    'product' => [
                                        'id' => 'gid://shopify/Product/323232'
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

        // 2. Service checks parent existence: findProductBySku (found)
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'findProductBySku');
            }), ['query' => 'sku:ITEM-0032'])
            ->andReturn([
                'data' => [
                    'productVariants' => [
                        'edges' => [
                            [
                                'node' => [
                                    'product' => [
                                        'id' => 'gid://shopify/Product/323232'
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

        // 3. Service fetches existing product details
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'getProductDetails');
            }), ['id' => 'gid://shopify/Product/323232'])
            ->andReturn([
                'data' => [
                    'product' => [
                        'id' => 'gid://shopify/Product/323232',
                        'options' => [
                            ['name' => 'Size', 'values' => ['Small']]
                        ],
                        'variants' => [
                            'edges' => [
                                [
                                    'node' => [
                                        'id' => 'gid://shopify/ProductVariant/323232001',
                                        'sku' => 'ITEM-0032-S'
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

        // 4. Service updates product
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productUpdate');
            }), Mockery::any())
            ->andReturn([
                'data' => [
                    'productUpdate' => [
                        'product' => [
                            'id' => 'gid://shopify/Product/323232'
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        // 5. Service updates variants
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productVariantsBulkUpdate');
            }), Mockery::any())
            ->andReturn([
                'data' => [
                    'productVariantsBulkUpdate' => [
                        'productVariants' => [
                            [
                                'id' => 'gid://shopify/ProductVariant/323232001',
                                'sku' => 'ITEM-0032-S',
                                'inventoryItem' => [
                                    'id' => 'gid://shopify/InventoryItem/323232991',
                                    'sku' => 'ITEM-0032-S'
                                ]
                            ]
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        // Common mocks (locations and metafields)
        $shopifyMock->shouldReceive('query')
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'locations') || str_contains($query, 'metafieldsSet');
            }), Mockery::any())
            ->andReturn([
                'data' => [
                    'locations' => [
                        'edges' => [
                            [
                                'node' => [
                                    'id' => 'gid://shopify/Location/999999999'
                                ]
                            ]
                        ]
                    ],
                    'metafieldsSet' => [
                        'userErrors' => []
                    ]
                ]
            ]);

        $this->app->bind(ShopifyGraphQLService::class, function () use ($shopifyMock) {
            return $shopifyMock;
        });

        $payload = [
            'event' => 'update',
            'items' => [
                [
                    'SKU' => 'ITEM-0032-S',
                    'is_variant' => true,
                    'parent_sku' => 'ITEM-0032',
                    'product_title' => 'test-S',
                    'product_category' => 'Products',
                    'status' => 'active'
                ]
            ]
        ];

        $response = $this->postJson('/api/method/middleware.apis.product.bulk_sync_external_product', $payload, [
            'Authorization' => 'Bearer sec_tok_12345'
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('results.0.sku', 'ITEM-0032-S');
        $response->assertJsonPath('results.0.success', true);
    }

    public function test_bulk_sync_external_product_with_variants_create_fails_if_variant_exists()
    {
        $shopifyMock = Mockery::mock(ShopifyGraphQLService::class);

        // 1. Controller checks variant existence for ITEM-0032-S (found: already exists)
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'findProductBySku');
            }), ['query' => 'sku:ITEM-0032-S'])
            ->andReturn([
                'data' => [
                    'productVariants' => [
                        'edges' => [
                            [
                                'node' => [
                                    'product' => [
                                        'id' => 'gid://shopify/Product/323232'
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

        $this->app->bind(ShopifyGraphQLService::class, function () use ($shopifyMock) {
            return $shopifyMock;
        });

        $payload = [
            'event' => 'create',
            'items' => [
                [
                    'SKU' => 'ITEM-0032-S',
                    'is_variant' => true,
                    'parent_sku' => 'ITEM-0032',
                    'product_title' => 'test-S',
                    'product_category' => 'Products',
                    'status' => 'active'
                ]
            ]
        ];

        $response = $this->postJson('/api/method/middleware.apis.product.bulk_sync_external_product', $payload, [
            'Authorization' => 'Bearer sec_tok_12345'
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('results.0.sku', 'ITEM-0032-S');
        $response->assertJsonPath('results.0.success', false);
        $response->assertJson([
            'results' => [
                [
                    'sku' => 'ITEM-0032-S',
                    'success' => false,
                    'error' => 'Product with SKU ITEM-0032-S already exists.'
                ]
            ]
        ]);
    }

    public function test_sync_standalone_item_with_installation_metafields()
    {
        $shopifyMock = Mockery::mock(ShopifyGraphQLService::class);

        // 1. Mock search for SKU (does not exist)
        $shopifyMock->shouldReceive('query')
            ->twice()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'findProductBySku');
            }), ['query' => 'sku:test67'])
            ->andReturn([
                'data' => [
                    'productVariants' => ['edges' => []]
                ]
            ]);

        // 2. Mock productCreate mutation
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productCreate');
            }), Mockery::on(function ($variables) {
                return $variables['input']['title'] === 'test67'
                    && $variables['input']['status'] === 'ACTIVE'
                    && in_array('Axanta Cloud', $variables['input']['tags'] ?? []);
            }))
            ->andReturn([
                'data' => [
                    'productCreate' => [
                        'product' => [
                            'id' => 'gid://shopify/Product/670001',
                            'variants' => [
                                'edges' => [
                                    ['node' => ['id' => 'gid://shopify/ProductVariant/670002_default']]
                                ]
                            ]
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        // 3. Mock productVariantsBulkUpdate mutation
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productVariantsBulkUpdate');
            }), Mockery::on(function ($variables) {
                return $variables['productId'] === 'gid://shopify/Product/670001'
                    && $variables['variants'][0]['inventoryItem']['sku'] === 'test67'
                    && $variables['variants'][0]['price'] === '0.00';
            }))
            ->andReturn([
                'data' => [
                    'productVariantsBulkUpdate' => [
                        'productVariants' => [
                            [
                                'id' => 'gid://shopify/ProductVariant/670002',
                                'sku' => 'test67',
                                'inventoryItem' => [
                                    'id' => 'gid://shopify/InventoryItem/670003',
                                    'sku' => 'test67'
                                ]
                            ]
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        // 4. Mock location GID query
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'locations');
            }))
            ->andReturn([
                'data' => [
                    'locations' => [
                        'edges' => [
                            ['node' => ['id' => 'gid://shopify/Location/998877']]
                        ]
                    ]
                ]
            ]);

        // 5. Mock metafieldsSet mutation
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'metafieldsSet');
            }), Mockery::on(function ($variables) {
                $metafields = $variables['metafields'];
                $hasRecommendedAge = false;
                $hasDimensions = false;
                $hasWeight = false;
                $hasPkgDimensions = false;
                $hasPkgWeight = false;
                $hasMaterial = false;
                $hasInstallType = false;
                $hasInstallLevel = false;
                $hasUom = false;

                foreach ($metafields as $m) {
                    if ($m['ownerId'] === 'gid://shopify/Product/670001' && $m['namespace'] === 'custom') {
                        if ($m['key'] === 'recommended_age' && $m['value'] === '5') $hasRecommendedAge = true;
                        if ($m['key'] === 'product_dimensions' && $m['value'] === '1') $hasDimensions = true;
                        if ($m['key'] === 'product_weight' && $m['value'] === '2') $hasWeight = true;
                        if ($m['key'] === 'package_dimensions' && $m['value'] === '2') $hasPkgDimensions = true;
                        if ($m['key'] === 'package_weight' && $m['value'] === '3') $hasPkgWeight = true;
                        if ($m['key'] === 'material' && $m['value'] === 'test') $hasMaterial = true;
                        if ($m['key'] === 'installation_type' && $m['type'] === 'boolean' && $m['value'] === 'true') $hasInstallType = true;
                        if ($m['key'] === 'installation_level' && $m['value'] === 'level 1') $hasInstallLevel = true;
                    }
                    if ($m['ownerId'] === 'gid://shopify/ProductVariant/670002' && $m['namespace'] === 'custom') {
                        if ($m['key'] === 'default_uom' && $m['value'] === 'Nos') $hasUom = true;
                    }
                }

                return $hasRecommendedAge && $hasDimensions && $hasWeight && $hasPkgDimensions 
                    && $hasPkgWeight && $hasMaterial && $hasInstallType && $hasInstallLevel && $hasUom;
            }))
            ->andReturn([
                'data' => [
                    'metafieldsSet' => ['userErrors' => []]
                ]
            ]);

        $this->app->bind(ShopifyGraphQLService::class, function () use ($shopifyMock) {
            return $shopifyMock;
        });

        $payload = [
            'event' => 'create',
            'item' => [
                'SKU' => 'test67',
                'is_variant' => false,
                'parent_sku' => null,
                'parent_title' => null,
                'parent_body_html' => null,
                'product_title' => 'test67',
                'arabic_title' => null,
                'product_category' => 'Services',
                'product_body_html' => 'test67',
                'brand' => null,
                'status' => 'active',
                'default_uom' => 'Nos',
                'selling_price' => null,
                'price_list' => null,
                'recommended_age' => '5',
                'material' => 'test',
                'dimensions' => '1',
                'product_weight' => '2',
                'package_dimensions' => '2',
                'package_weight' => '3',
                'installation_type' => 1,
                'installation_level' => 'level 1',
                'attributes' => []
            ]
        ];

        $response = $this->postJson('/api/method/middleware.apis.product.sync_external_product', $payload, [
            'Authorization' => 'Bearer sec_tok_12345'
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Product synced successfully to Shopify.',
                'shopify_product_id' => 'gid://shopify/Product/670001',
                'synced_variants' => [
                    'test67' => 'gid://shopify/ProductVariant/670002'
                ]
            ]);
    }

    public function test_sync_variant_item_with_zero_installation_type()
    {
        $shopifyMock = Mockery::mock(ShopifyGraphQLService::class);

        // 1. Mock variant SKU search (does not exist)
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'findProductBySku');
            }), ['query' => 'sku:test99-BLU'])
            ->andReturn([
                'data' => [
                    'productVariants' => ['edges' => []]
                ]
            ]);

        // 2. Mock parent SKU search (does not exist either, so will create new parent product)
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'findProductBySku');
            }), ['query' => 'sku:test99'])
            ->andReturn([
                'data' => [
                    'productVariants' => ['edges' => []]
                ]
            ]);

        // 3. Mock productCreate mutation
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productCreate');
            }), Mockery::on(function ($variables) {
                return $variables['input']['title'] === 'test'
                    && $variables['input']['descriptionHtml'] === 'test';
            }))
            ->andReturn([
                'data' => [
                    'productCreate' => [
                        'product' => [
                            'id' => 'gid://shopify/Product/990001',
                            'variants' => [
                                'edges' => [
                                    ['node' => ['id' => 'gid://shopify/ProductVariant/990002_default']]
                                ]
                            ]
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        // 4. Mock productVariantsBulkUpdate mutation
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productVariantsBulkUpdate');
            }), Mockery::on(function ($variables) {
                return $variables['productId'] === 'gid://shopify/Product/990001'
                    && $variables['variants'][0]['inventoryItem']['sku'] === 'test99-BLU';
            }))
            ->andReturn([
                'data' => [
                    'productVariantsBulkUpdate' => [
                        'productVariants' => [
                            [
                                'id' => 'gid://shopify/ProductVariant/990002',
                                'sku' => 'test99-BLU',
                                'inventoryItem' => [
                                    'id' => 'gid://shopify/InventoryItem/990003',
                                    'sku' => 'test99-BLU'
                                ]
                            ]
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        // 5. Mock location query
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'locations');
            }))
            ->andReturn([
                'data' => [
                    'locations' => [
                        'edges' => [
                            ['node' => ['id' => 'gid://shopify/Location/998877']]
                        ]
                    ]
                ]
            ]);

        // 6. Mock metafieldsSet mutation (installation_type: "false" must be present, installation_level must NOT be present)
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'metafieldsSet');
            }), Mockery::on(function ($variables) {
                $metafields = $variables['metafields'];
                $hasInstallTypeZero = false;
                $hasInstallLevel = false;

                foreach ($metafields as $m) {
                    if ($m['key'] === 'installation_type' && $m['type'] === 'boolean' && $m['value'] === 'false') {
                        $hasInstallTypeZero = true;
                    }
                    if ($m['key'] === 'installation_level') {
                        $hasInstallLevel = true;
                    }
                }

                return $hasInstallTypeZero && !$hasInstallLevel;
            }))
            ->andReturn([
                'data' => [
                    'metafieldsSet' => ['userErrors' => []]
                ]
            ]);

        $this->app->bind(ShopifyGraphQLService::class, function () use ($shopifyMock) {
            return $shopifyMock;
        });

        $payload = [
            'event' => 'create',
            'item' => [
                'SKU' => 'test99-BLU',
                'is_variant' => true,
                'parent_sku' => 'test99',
                'parent_title' => 'test',
                'parent_body_html' => 'test',
                'product_title' => 'test-BLU',
                'arabic_title' => null,
                'product_category' => 'Products',
                'product_body_html' => 'test-BLU',
                'brand' => null,
                'status' => 'active',
                'default_uom' => 'Nos',
                'selling_price' => null,
                'price_list' => null,
                'recommended_age' => null,
                'material' => null,
                'dimensions' => null,
                'product_weight' => null,
                'package_dimensions' => null,
                'package_weight' => null,
                'installation_type' => 0,
                'installation_level' => null,
                'attributes' => [
                    [
                        'attribute' => 'Colour',
                        'value' => 'Blue'
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/method/middleware.apis.product.sync_external_product', $payload, [
            'Authorization' => 'Bearer sec_tok_12345'
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Product synced successfully to Shopify.',
                'shopify_product_id' => 'gid://shopify/Product/990001',
                'synced_variants' => [
                    'test99-BLU' => 'gid://shopify/ProductVariant/990002'
                ]
            ]);
    }

    public function test_sync_external_product_with_images_create_event_success()
    {
        $shopifyMock = Mockery::mock(ShopifyGraphQLService::class);

        // 1. Mock search for SKU (return null: does not exist)
        $shopifyMock->shouldReceive('query')
            ->twice()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'findProductBySku');
            }), ['query' => 'sku:IMG-PROD-001'])
            ->andReturn([
                'data' => [
                    'productVariants' => [
                        'edges' => []
                    ]
                ]
            ]);

        // 2. Mock productCreate mutation
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productCreate');
            }), Mockery::any())
            ->andReturn([
                'data' => [
                    'productCreate' => [
                        'product' => [
                            'id' => 'gid://shopify/Product/888111222',
                            'variants' => [
                                'edges' => [
                                    ['node' => ['id' => 'gid://shopify/ProductVariant/888333444']]
                                ]
                            ]
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        // 3. Mock productVariantsBulkUpdate mutation
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productVariantsBulkUpdate');
            }), Mockery::any())
            ->andReturn([
                'data' => [
                    'productVariantsBulkUpdate' => [
                        'productVariants' => [
                            [
                                'id' => 'gid://shopify/ProductVariant/888333444',
                                'sku' => 'IMG-PROD-001',
                                'inventoryItem' => [
                                    'id' => 'gid://shopify/InventoryItem/888555666',
                                    'sku' => 'IMG-PROD-001'
                                ]
                            ]
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        // 4. Mock location query for inventory
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'locations');
            }))
            ->andReturn([
                'data' => [
                    'locations' => [
                        'edges' => [
                            ['node' => ['id' => 'gid://shopify/Location/10001']]
                        ]
                    ]
                ]
            ]);


        // 6. Mock getProductMedia (initially empty)
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'getProductMedia');
            }), ['id' => 'gid://shopify/Product/888111222'])
            ->andReturn([
                'data' => [
                    'product' => [
                        'media' => [
                            'edges' => []
                        ]
                    ]
                ]
            ]);

        // 7. Mock productCreateMedia mutation
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productCreateMedia');
            }), Mockery::on(function ($vars) {
                return $vars['productId'] === 'gid://shopify/Product/888111222'
                    && count($vars['media']) === 2
                    && $vars['media'][0]['originalSource'] === 'https://www.w3schools.com/w3css/img_lights.jpg'
                    && $vars['media'][0]['alt'] === 'Front View'
                    && $vars['media'][1]['originalSource'] === 'https://www.w3schools.com/w3css/img_lights2.jpg'
                    && $vars['media'][1]['alt'] === 'Back View';
            }))
            ->andReturn([
                'data' => [
                    'productCreateMedia' => [
                        'media' => [
                            [
                                'id' => 'gid://shopify/MediaImage/555001',
                                'status' => 'UPLOADED',
                                'mediaContentType' => 'IMAGE',
                                'alt' => 'Front View'
                            ],
                            [
                                'id' => 'gid://shopify/MediaImage/555002',
                                'status' => 'UPLOADED',
                                'mediaContentType' => 'IMAGE',
                                'alt' => 'Back View'
                            ]
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        $this->app->bind(ShopifyGraphQLService::class, function () use ($shopifyMock) {
            return $shopifyMock;
        });

        $payload = [
            'event' => 'create',
            'item' => [
                'SKU' => 'IMG-PROD-001',
                'is_variant' => false,
                'product_title' => 'Product with Images',
                'selling_price' => 49.99,
                'images' => [
                    [
                        'url' => 'https://www.w3schools.com/w3css/img_lights.jpg',
                        'alt_text' => 'Front View'
                    ],
                    [
                        'url' => 'https://www.w3schools.com/w3css/img_lights2.jpg',
                        'alt_text' => 'Back View'
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/method/middleware.apis.product.sync_external_product', $payload, [
            'Authorization' => 'Bearer sec_tok_12345'
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Product synced successfully to Shopify.',
                'shopify_product_id' => 'gid://shopify/Product/888111222',
                'synced_variants' => [
                    'IMG-PROD-001' => 'gid://shopify/ProductVariant/888333444'
                ],
                'synced_images' => [
                    'gid://shopify/MediaImage/555001',
                    'gid://shopify/MediaImage/555002'
                ]
            ]);
    }

    public function test_sync_external_product_with_images_update_event_sample_payload()
    {
        $shopifyMock = Mockery::mock(ShopifyGraphQLService::class);

        // Pre-map product in DB
        ProductMap::create([
            'erp_item_code' => 'ABC-IMAGE-001 DEMO',
            'shopify_product_id' => 'gid://shopify/Product/777001',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/777002'
        ]);

        // 1. Mock getExistingProductDetails
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'getProductDetails');
            }), ['id' => 'gid://shopify/Product/777001'])
            ->andReturn([
                'data' => [
                    'product' => [
                        'options' => [
                            ['name' => 'Title']
                        ],
                        'variants' => [
                            'edges' => [
                                [
                                    'node' => [
                                        'id' => 'gid://shopify/ProductVariant/777002',
                                        'sku' => 'ABC-IMAGE-001 DEMO'
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

        // 2. Mock productUpdate mutation
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productUpdate');
            }), Mockery::any())
            ->andReturn([
                'data' => [
                    'productUpdate' => [
                        'product' => ['id' => 'gid://shopify/Product/777001'],
                        'userErrors' => []
                    ]
                ]
            ]);

        // 3. Mock productVariantsBulkUpdate mutation
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productVariantsBulkUpdate');
            }), Mockery::any())
            ->andReturn([
                'data' => [
                    'productVariantsBulkUpdate' => [
                        'productVariants' => [
                            [
                                'id' => 'gid://shopify/ProductVariant/777002',
                                'sku' => 'ABC-IMAGE-001 DEMO',
                                'inventoryItem' => [
                                    'id' => 'gid://shopify/InventoryItem/777003',
                                    'sku' => 'ABC-IMAGE-001 DEMO'
                                ]
                            ]
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        // 4. Mock location query for inventory
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'locations');
            }))
            ->andReturn([
                'data' => [
                    'locations' => [
                        'edges' => [
                            ['node' => ['id' => 'gid://shopify/Location/10001']]
                        ]
                    ]
                ]
            ]);


        // 6. Mock metafieldsSet
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'metafieldsSet');
            }), Mockery::any())
            ->andReturn([
                'data' => [
                    'metafieldsSet' => ['userErrors' => []]
                ]
            ]);

        // 7. Mock getProductMedia (returns an older image that needs replacement)
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'getProductMedia');
            }), ['id' => 'gid://shopify/Product/777001'])
            ->andReturn([
                'data' => [
                    'product' => [
                        'media' => [
                            'edges' => [
                                [
                                    'node' => [
                                        'id' => 'gid://shopify/MediaImage/old_999',
                                        'alt' => 'Old Image',
                                        'mediaContentType' => 'IMAGE',
                                        'status' => 'READY',
                                        'previewImage' => [
                                            'url' => 'https://cdn.shopify.com/s/files/1/0001/files/old_pic.jpg?v=1'
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

        // 8. Mock productDeleteMedia for the old image
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productDeleteMedia');
            }), [
                'productId' => 'gid://shopify/Product/777001',
                'mediaIds' => ['gid://shopify/MediaImage/old_999']
            ])
            ->andReturn([
                'data' => [
                    'productDeleteMedia' => [
                        'deletedMediaIds' => ['gid://shopify/MediaImage/old_999'],
                        'userErrors' => []
                    ]
                ]
            ]);

        // 9. Mock productCreateMedia for the new images
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productCreateMedia');
            }), Mockery::on(function ($vars) {
                return $vars['productId'] === 'gid://shopify/Product/777001'
                    && count($vars['media']) === 2
                    && $vars['media'][0]['originalSource'] === 'https://www.w3schools.com/w3css/img_lights.jpg'
                    && $vars['media'][0]['alt'] === 'Front View'
                    && $vars['media'][1]['originalSource'] === 'https://www.w3schools.com/w3css/img_lights2.jpg'
                    && $vars['media'][1]['alt'] === 'Back View';
            }))
            ->andReturn([
                'data' => [
                    'productCreateMedia' => [
                        'media' => [
                            [
                                'id' => 'gid://shopify/MediaImage/777101',
                                'status' => 'UPLOADED',
                                'mediaContentType' => 'IMAGE',
                                'alt' => 'Front View'
                            ],
                            [
                                'id' => 'gid://shopify/MediaImage/777102',
                                'status' => 'UPLOADED',
                                'mediaContentType' => 'IMAGE',
                                'alt' => 'Back View'
                            ]
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        $this->app->bind(ShopifyGraphQLService::class, function () use ($shopifyMock) {
            return $shopifyMock;
        });

        // The user's exact sample payload from USER_REQUEST
        $payload = [
            'event' => 'update',
            'item' => [
                'SKU' => 'ABC-IMAGE-001 DEMO',
                'is_variant' => false,
                'parent_sku' => null,
                'parent_title' => null,
                'parent_body_html' => null,
                'product_title' => 'ABC-IMAGE-001 DEMO',
                'arabic_title' => null,
                'product_category' => 'Products',
                'product_body_html' => 'ABC-IMAGE-001',
                'brand' => null,
                'status' => 'active',
                'default_uom' => 'Nos',
                'selling_price' => null,
                'price_list' => null,
                'recommended_age' => null,
                'material' => null,
                'dimensions' => null,
                'product_weight' => null,
                'package_dimensions' => null,
                'package_weight' => null,
                'installation_type' => 1,
                'installation_level' => 'Level 1',
                'attributes' => [],
                'images' => [
                    [
                        'url' => 'https://www.w3schools.com/w3css/img_lights.jpg',
                        'alt_text' => 'Front View'
                    ],
                    [
                        'url' => 'https://www.w3schools.com/w3css/img_lights2.jpg',
                        'alt_text' => 'Back View'
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/method/middleware.apis.product.sync_external_product', $payload, [
            'Authorization' => 'Bearer sec_tok_12345'
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Product synced successfully to Shopify.',
                'shopify_product_id' => 'gid://shopify/Product/777001',
                'synced_variants' => [
                    'ABC-IMAGE-001 DEMO' => 'gid://shopify/ProductVariant/777002'
                ],
                'synced_images' => [
                    'gid://shopify/MediaImage/777101',
                    'gid://shopify/MediaImage/777102'
                ]
            ]);
    }

    public function test_sync_external_product_with_identical_images_skips_reupload()
    {
        $shopifyMock = Mockery::mock(ShopifyGraphQLService::class);

        ProductMap::create([
            'erp_item_code' => 'ABC-IMAGE-001 DEMO',
            'shopify_product_id' => 'gid://shopify/Product/777001',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/777002'
        ]);

        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'getProductDetails');
            }), ['id' => 'gid://shopify/Product/777001'])
            ->andReturn([
                'data' => [
                    'product' => [
                        'options' => [['name' => 'Title']],
                        'variants' => [
                            'edges' => [
                                ['node' => ['id' => 'gid://shopify/ProductVariant/777002', 'sku' => 'ABC-IMAGE-001 DEMO']]
                            ]
                        ]
                    ]
                ]
            ]);

        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productUpdate');
            }), Mockery::any())
            ->andReturn(['data' => ['productUpdate' => ['product' => ['id' => 'gid://shopify/Product/777001'], 'userErrors' => []]]]);

        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productVariantsBulkUpdate');
            }), Mockery::any())
            ->andReturn([
                'data' => [
                    'productVariantsBulkUpdate' => [
                        'productVariants' => [
                            ['id' => 'gid://shopify/ProductVariant/777002', 'sku' => 'ABC-IMAGE-001 DEMO', 'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/777003', 'sku' => 'ABC-IMAGE-001 DEMO']]
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'locations');
            }))
            ->andReturn(['data' => ['locations' => ['edges' => [['node' => ['id' => 'gid://shopify/Location/10001']]]]]]);




        // getProductMedia returns the same two images
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'getProductMedia');
            }), ['id' => 'gid://shopify/Product/777001'])
            ->andReturn([
                'data' => [
                    'product' => [
                        'media' => [
                            'edges' => [
                                [
                                    'node' => [
                                        'id' => 'gid://shopify/MediaImage/777101',
                                        'alt' => 'Front View',
                                        'mediaContentType' => 'IMAGE',
                                        'status' => 'READY',
                                        'previewImage' => ['url' => 'https://cdn.shopify.com/s/files/1/0001/files/img_lights.jpg?v=1']
                                    ]
                                ],
                                [
                                    'node' => [
                                        'id' => 'gid://shopify/MediaImage/777102',
                                        'alt' => 'Back View',
                                        'mediaContentType' => 'IMAGE',
                                        'status' => 'READY',
                                        'previewImage' => ['url' => 'https://cdn.shopify.com/s/files/1/0001/files/img_lights2.jpg?v=1']
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

        // Should NOT call productDeleteMedia or productCreateMedia since identical!
        $shopifyMock->shouldNotReceive('query')
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productDeleteMedia') || str_contains($query, 'productCreateMedia');
            }), Mockery::any());

        $this->app->bind(ShopifyGraphQLService::class, function () use ($shopifyMock) {
            return $shopifyMock;
        });

        $payload = [
            'event' => 'update',
            'item' => [
                'SKU' => 'ABC-IMAGE-001 DEMO',
                'product_title' => 'ABC-IMAGE-001 DEMO',
                'images' => [
                    ['url' => 'https://www.w3schools.com/w3css/img_lights.jpg', 'alt_text' => 'Front View'],
                    ['url' => 'https://www.w3schools.com/w3css/img_lights2.jpg', 'alt_text' => 'Back View']
                ]
            ]
        ];

        $response = $this->postJson('/api/method/middleware.apis.product.sync_external_product', $payload, [
            'Authorization' => 'Bearer sec_tok_12345'
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'shopify_product_id' => 'gid://shopify/Product/777001',
                'synced_images' => [
                    'gid://shopify/MediaImage/777101',
                    'gid://shopify/MediaImage/777102'
                ]
            ]);
    }

    public function test_sync_external_product_validates_images_properly()
    {
        // 1. images is not array
        $response = $this->postJson('/api/method/middleware.apis.product.sync_external_product', [
            'event' => 'create',
            'item' => [
                'SKU' => 'TEST-SKU',
                'product_title' => 'Test',
                'images' => 'invalid_string'
            ]
        ], [
            'Authorization' => 'Bearer sec_tok_12345'
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['item.images']);

        // 2. images element missing url
        $response = $this->postJson('/api/method/middleware.apis.product.sync_external_product', [
            'event' => 'create',
            'item' => [
                'SKU' => 'TEST-SKU',
                'product_title' => 'Test',
                'images' => [
                    ['alt_text' => 'Front View']
                ]
            ]
        ], [
            'Authorization' => 'Bearer sec_tok_12345'
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['item.images.0.url']);
    }

    public function test_bulk_sync_external_product_with_images_success()
    {
        $shopifyMock = Mockery::mock(ShopifyGraphQLService::class);

        // Mock SKU search (not found)
        $shopifyMock->shouldReceive('query')
            ->twice()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'findProductBySku');
            }), ['query' => 'sku:BULK-IMG-1'])
            ->andReturn(['data' => ['productVariants' => ['edges' => []]]]);

        // Mock productCreate
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productCreate');
            }), Mockery::any())
            ->andReturn([
                'data' => [
                    'productCreate' => [
                        'product' => [
                            'id' => 'gid://shopify/Product/999111',
                            'variants' => ['edges' => [['node' => ['id' => 'gid://shopify/ProductVariant/999222']]]]
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        // Mock productVariantsBulkUpdate
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productVariantsBulkUpdate');
            }), Mockery::any())
            ->andReturn([
                'data' => [
                    'productVariantsBulkUpdate' => [
                        'productVariants' => [
                            ['id' => 'gid://shopify/ProductVariant/999222', 'sku' => 'BULK-IMG-1', 'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/999333', 'sku' => 'BULK-IMG-1']]
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        // Mock location query
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'locations');
            }))
            ->andReturn(['data' => ['locations' => ['edges' => [['node' => ['id' => 'gid://shopify/Location/10001']]]]]]);


        // Mock getProductMedia
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'getProductMedia');
            }), ['id' => 'gid://shopify/Product/999111'])
            ->andReturn(['data' => ['product' => ['media' => ['edges' => []]]]]);

        // Mock productCreateMedia
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productCreateMedia');
            }), Mockery::any())
            ->andReturn([
                'data' => [
                    'productCreateMedia' => [
                        'media' => [
                            ['id' => 'gid://shopify/MediaImage/999444', 'status' => 'UPLOADED', 'mediaContentType' => 'IMAGE', 'alt' => 'Bulk Pic']
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        $this->app->bind(ShopifyGraphQLService::class, function () use ($shopifyMock) {
            return $shopifyMock;
        });

        $payload = [
            'event' => 'create',
            'items' => [
                [
                    'SKU' => 'BULK-IMG-1',
                    'product_title' => 'Bulk Product',
                    'images' => [
                        ['url' => 'https://example.com/bulk.jpg', 'alt_text' => 'Bulk Pic']
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/method/middleware.apis.product.bulk_sync_external_product', $payload, [
            'Authorization' => 'Bearer sec_tok_12345'
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Bulk product sync completed.',
                'results' => [
                    [
                        'sku' => 'BULK-IMG-1',
                        'success' => true,
                        'shopify_product_id' => 'gid://shopify/Product/999111',
                        'synced_images' => ['gid://shopify/MediaImage/999444']
                    ]
                ]
            ]);
    }
}

