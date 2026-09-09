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
                        if ($m['key'] === 'age' && $m['value'] === '3+ Years') $hasAge = true;
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
                        if ($m['key'] === 'age' && $m['value'] === '3+ Years') $hasAge = true;
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
}

