<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Shop;
use App\Models\ProductMap;
use App\Models\SyncLog;
use App\Services\Shopify\ShopifyGraphQLService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class ProductControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a dummy shop in the database so the service can resolve it
        Shop::create([
            'shop_domain' => 'test-shop.myshopify.com',
            'access_token' => 'shpat_mock_access_token_123'
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_sync_product_fails_validation_for_missing_required_fields()
    {
        $response = $this->postJson('/api/method/middleware.apis.product.sync_product', []);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'success',
                'errors' => ['product']
            ]);
    }

    public function test_sync_product_creates_new_product_and_sets_metafields_and_inventory()
    {
        $shopifyMock = Mockery::mock(ShopifyGraphQLService::class);

        // 1. Mock search for product variant by SKU (return null/empty, meaning product doesn't exist)
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'findProductBySku');
            }), ['query' => 'sku:WB-TEST-100'])
            ->andReturn([
                'data' => [
                    'productVariants' => [
                        'edges' => []
                    ]
                ]
            ]);

        // 2. Mock productCreate mutation (no inline variants, descriptionHtml instead of bodyHtml)
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productCreate');
            }), Mockery::on(function ($variables) {
                return $variables['input']['title'] === 'Test Wooden Blocks'
                    && $variables['input']['status'] === 'ACTIVE'
                    && !isset($variables['input']['variants']);
            }))
            ->andReturn([
                'data' => [
                    'productCreate' => [
                        'product' => [
                            'id' => 'gid://shopify/Product/987654321',
                            'variants' => [
                                'edges' => [
                                    [
                                        'node' => [
                                            'id' => 'gid://shopify/ProductVariant/123456789_default'
                                        ]
                                    ]
                                ]
                            ]
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        // 2.5 Mock productVariantsBulkUpdate mutation (updating default variant to SKU/price)
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productVariantsBulkUpdate');
            }), Mockery::on(function ($variables) {
                return $variables['productId'] === 'gid://shopify/Product/987654321'
                    && $variables['variants'][0]['id'] === 'gid://shopify/ProductVariant/123456789_default'
                    && $variables['variants'][0]['inventoryItem']['sku'] === 'WB-TEST-100'
                    && $variables['variants'][0]['price'] === '35.00';
            }))
            ->andReturn([
                'data' => [
                    'productVariantsBulkUpdate' => [
                        'productVariants' => [
                            [
                                'id' => 'gid://shopify/ProductVariant/123456789',
                                'sku' => 'WB-TEST-100',
                                'inventoryItem' => [
                                    'id' => 'gid://shopify/InventoryItem/11223344',
                                    'sku' => 'WB-TEST-100'
                                ]
                            ]
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        // 3. Mock getDefaultLocationId query
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
                                    'id' => 'gid://shopify/Location/55443322'
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

        // 4. Mock inventorySetQuantities mutation
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'inventorySetQuantities');
            }), Mockery::on(function ($variables) {
                $qtyInput = $variables['input']['quantities'][0];
                return $qtyInput['inventoryItemId'] === 'gid://shopify/InventoryItem/11223344'
                    && $qtyInput['locationId'] === 'gid://shopify/Location/55443322'
                    && $qtyInput['quantity'] === 50;
            }))
            ->andReturn([
                'data' => [
                    'inventorySetQuantities' => [
                        'userErrors' => []
                    ]
                ]
            ]);

        // 5. Mock metafieldsSet mutation (verify that metafield types, namespaces, and values match)
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'metafieldsSet');
            }), Mockery::on(function ($variables) {
                $metafields = $variables['metafields'];
                
                // We passed 1 product metafield and 1 variant metafield in our test payload.
                // Let's check if they are mapped correctly.
                $hasAge = false;
                $hasArabicName = false;

                foreach ($metafields as $m) {
                    if ($m['ownerId'] === 'gid://shopify/Product/987654321' && $m['namespace'] === 'custom' && $m['key'] === 'age' && $m['value'] === '3-5 years') {
                        $hasAge = true;
                    }
                    if ($m['ownerId'] === 'gid://shopify/ProductVariant/123456789' && $m['namespace'] === 'custom' && $m['key'] === 'arabic_name' && $m['value'] === 'Arabic Name') {
                        $hasArabicName = true;
                    }
                }

                return $hasAge && $hasArabicName;
            }))
            ->andReturn([
                'data' => [
                    'metafieldsSet' => [
                        'userErrors' => []
                    ]
                ]
            ]);

        // Bind mock to the container
        $this->app->bind(ShopifyGraphQLService::class, function () use ($shopifyMock) {
            return $shopifyMock;
        });

        $payload = [
            'product' => [
                'title' => 'Test Wooden Blocks',
                'body_html' => '<p>Fun educational block set.</p>',
                'vendor' => 'Blocks R Us',
                'product_type' => 'Toys',
                'status' => 'active',
                'metafields' => [
                    'custom.age' => '3-5 years'
                ],
                'variants' => [
                    [
                        'price' => '35.00',
                        'sku' => 'WB-TEST-100',
                        'inventory_quantity' => 50,
                        'metafields' => [
                            'custom.arabic_name' => 'Arabic Name'
                        ]
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/method/middleware.apis.product.sync_product', $payload);

        // Assert response
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Product synced successfully to Shopify',
                'shopify_product_id' => 'gid://shopify/Product/987654321',
                'synced_variants' => [
                    'WB-TEST-100' => 'gid://shopify/ProductVariant/123456789'
                ]
            ]);

        // Assert database mapping record is created
        $this->assertDatabaseHas('product_maps', [
            'erp_item_code' => 'WB-TEST-100',
            'shopify_product_id' => 'gid://shopify/Product/987654321',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/123456789'
        ]);

        // Assert sync log is successful
        $this->assertDatabaseHas('sync_logs', [
            'entity_type' => 'product',
            'entity_id' => 'WB-TEST-100',
            'direction' => 'erp_to_shopify',
            'status' => 'success'
        ]);
    }

    public function test_sync_product_with_images_success()
    {
        $shopifyMock = Mockery::mock(ShopifyGraphQLService::class);

        // 1. Mock search for SKU
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'findProductBySku');
            }), ['query' => 'sku:WB-IMG-001'])
            ->andReturn(['data' => ['productVariants' => ['edges' => []]]]);

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
                            'id' => 'gid://shopify/Product/123000',
                            'variants' => ['edges' => [['node' => ['id' => 'gid://shopify/ProductVariant/123001']]]]
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
                            ['id' => 'gid://shopify/ProductVariant/123001', 'sku' => 'WB-IMG-001', 'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/123002', 'sku' => 'WB-IMG-001']]
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        // 4. Mock location query
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'locations');
            }))
            ->andReturn(['data' => ['locations' => ['edges' => [['node' => ['id' => 'gid://shopify/Location/10001']]]]]]);

        // 5. Mock getProductMedia
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'getProductMedia');
            }), ['id' => 'gid://shopify/Product/123000'])
            ->andReturn(['data' => ['product' => ['media' => ['edges' => []]]]]);

        // 6. Mock productCreateMedia
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'productCreateMedia');
            }), Mockery::any())
            ->andReturn([
                'data' => [
                    'productCreateMedia' => [
                        'media' => [
                            ['id' => 'gid://shopify/MediaImage/123999', 'status' => 'UPLOADED', 'mediaContentType' => 'IMAGE', 'alt' => 'Photo 1']
                        ],
                        'userErrors' => []
                    ]
                ]
            ]);

        $this->app->bind(ShopifyGraphQLService::class, function () use ($shopifyMock) {
            return $shopifyMock;
        });

        $payload = [
            'product' => [
                'title' => 'Product with Image',
                'variants' => [
                    [
                        'price' => '15.00',
                        'sku' => 'WB-IMG-001'
                    ]
                ],
                'images' => [
                    [
                        'url' => 'https://example.com/photo1.jpg',
                        'alt_text' => 'Photo 1'
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/method/middleware.apis.product.sync_product', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'shopify_product_id' => 'gid://shopify/Product/123000',
                'synced_images' => ['gid://shopify/MediaImage/123999']
            ]);
    }

    public function test_sync_product_validates_images_properly()
    {
        // 1. product.images is string instead of array
        $response = $this->postJson('/api/method/middleware.apis.product.sync_product', [
            'product' => [
                'title' => 'Test',
                'variants' => [['price' => '10', 'sku' => 'SKU-1']],
                'images' => 'invalid_str'
            ]
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['product.images']);

        // 2. product.images element missing url
        $response = $this->postJson('/api/method/middleware.apis.product.sync_product', [
            'product' => [
                'title' => 'Test',
                'variants' => [['price' => '10', 'sku' => 'SKU-1']],
                'images' => [
                    ['alt_text' => 'No URL']
                ]
            ]
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['product.images.0.url']);
    }

    public function test_products_web_page_requires_authentication()
    {
        $response = $this->get('/products');
        $response->assertRedirect('/login');
    }

    public function test_products_web_page_renders_successfully_for_authenticated_users()
    {
        $user = \App\Models\User::create([
            'name' => 'Test User',
            'email' => 'test-' . rand(1000, 9999) . '@example.com',
            'password' => bcrypt('password')
        ]);

        $response = $this->actingAs($user)->get('/products');

        $response->assertStatus(200)
            ->assertViewIs('products.index');
    }
}

