<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\CustomerMap;
use App\Models\ProductMap;
use App\Models\Shop;
use App\Models\SyncLog;
use App\Services\ERPNext\ErpnextApiService;
use App\Services\Shopify\ShopifyGraphQLService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class SalesOrderControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_fails_validation_for_missing_required_fields_when_body_is_provided()
    {
        $response = $this->postJson('/api/method/middleware.apis.sales_order.create_sales_order', [
            'transaction_date' => '2026-05-20'
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'success',
                'errors' => ['customer', 'items']
            ]);
    }

    public function test_no_body_content_returns_error_when_no_shop_connected()
    {
        config(['services.shopify.domain' => null, 'services.shopify.access_token' => null]);
        $response = $this->postJson('/api/method/middleware.apis.sales_order.create_sales_order', []);

        $response->assertStatus(500)
            ->assertJson([
                'success' => false,
            ]);
        $this->assertStringContainsString('No connected Shopify store found', $response->json('message'));
    }

    public function test_no_body_content_fetches_and_forwards_new_and_unsynced_shopify_orders()
    {
        // 1. Arrange a connected shop
        Shop::create([
            'shop_domain' => 'test-store.myshopify.com',
            'access_token' => 'shpat_test_token_123',
        ]);

        // 2. Pre-mark order #1054 as already synced
        SyncLog::create([
            'entity_type' => 'Order',
            'entity_id' => '8209829119460',
            'direction' => 'Shopify -> ERPNext',
            'status' => 'success',
            'payload' => '{}',
        ]);

        // 3. Mock Shopify GraphQL Service returning 2 orders:
        // - Order #1054 (ID: 8209829119460) -> already synced, should be skipped
        // - Order #1055 (ID: 8209829119461) -> new order, should be forwarded
        $shopifyMock = Mockery::mock(ShopifyGraphQLService::class);
        $shopifyMock->shouldReceive('query')
            ->once()
            ->with(Mockery::type('string'), Mockery::type('array'))
            ->andReturn([
                'data' => [
                    'orders' => [
                        'edges' => [
                            [
                                'node' => [
                                    'id' => 'gid://shopify/Order/8209829119460',
                                    'name' => '#1054',
                                    'createdAt' => '2026-10-04T10:00:00Z',
                                    'customer' => [
                                        'id' => 'gid://shopify/Customer/111111',
                                        'firstName' => 'Old',
                                        'lastName' => 'Customer',
                                    ],
                                    'lineItems' => [
                                        'edges' => []
                                    ]
                                ]
                            ],
                            [
                                'node' => [
                                    'id' => 'gid://shopify/Order/8209829119461',
                                    'name' => '#1055',
                                    'createdAt' => '2026-10-05T12:00:00Z',
                                    'customer' => [
                                        'id' => 'gid://shopify/Customer/11223344',
                                        'firstName' => 'Alex',
                                        'lastName' => 'Johnson',
                                        'email' => 'alex@example.com',
                                        'phone' => '+971501234567',
                                        'defaultAddress' => [
                                            'company' => 'Acme Corp',
                                        ]
                                    ],
                                    'lineItems' => [
                                        'edges' => [
                                            [
                                                'node' => [
                                                    'title' => 'Widget',
                                                    'quantity' => 2,
                                                    'sku' => 'ITEM-SKU-001',
                                                    'originalUnitPriceSet' => [
                                                        'shopMoney' => [
                                                            'amount' => '45.00'
                                                        ]
                                                    ],
                                                    'variant' => [
                                                        'id' => 'gid://shopify/ProductVariant/43219876',
                                                        'product' => [
                                                            'id' => 'gid://shopify/Product/87654321'
                                                        ]
                                                    ]
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

        $this->app->instance(ShopifyGraphQLService::class, $shopifyMock);

        // 4. Mock ErpnextApiService
        $erpnextServiceMock = Mockery::mock(ErpnextApiService::class);

        // Only Order #1055 should trigger customer creation and sales order creation
        $erpnextServiceMock->shouldReceive('createCustomer')
            ->once()
            ->with([
                'customer_name' => 'Alex Johnson',
                'customer_type' => 'Company',
            ])
            ->andReturn(['name' => 'CUST-ALEX-1055']);

        $erpnextServiceMock->shouldReceive('createSalesOrder')
            ->once()
            ->with(Mockery::on(function ($data) {
                return $data['customer'] === 'CUST-ALEX-1055'
                    && $data['po_no'] === '#1055'
                    && $data['items'][0]['item_code'] === 'ITEM-SKU-001'
                    && $data['items'][0]['qty'] == 2
                    && $data['items'][0]['rate'] == 45.00;
            }))
            ->andReturn(['name' => 'SAL-ORD-1055']);

        $this->app->instance(ErpnextApiService::class, $erpnextServiceMock);

        // 5. Act: send request with NO body content
        $response = $this->postJson('/api/method/middleware.apis.sales_order.create_sales_order', []);

        // 6. Assert: completed successfully, 1 synced, 1 skipped
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'synced_count' => 1,
                'skipped_count' => 1,
                'failed_count' => 0,
            ]);

        $this->assertDatabaseHas('sync_logs', [
            'entity_type' => 'Order',
            'entity_id' => '8209829119461',
            'status' => 'success'
        ]);
    }

    public function test_creates_sales_order_from_user_requested_shopify_order_payload()
    {
        $erpnextServiceMock = Mockery::mock(ErpnextApiService::class);

        // Auto-create customer in ERPNext with company type
        $erpnextServiceMock->shouldReceive('createCustomer')
            ->once()
            ->with([
                'customer_name' => 'Alex Johnson',
                'customer_type' => 'Company',
            ])
            ->andReturn(['name' => 'CUST-2026-ALEX']);

        // Expect createSalesOrder with customer and items details
        $erpnextServiceMock->shouldReceive('createSalesOrder')
            ->once()
            ->with(Mockery::on(function ($data) {
                return $data['customer'] === 'CUST-2026-ALEX'
                    && $data['transaction_date'] === '2026-10-05'
                    && $data['po_no'] === '#1055'
                    && $data['items'][0]['item_code'] === 'ITEM-SKU-001'
                    && $data['items'][0]['qty'] == 2
                    && $data['items'][0]['rate'] == 45.00;
            }))
            ->andReturn(['name' => 'SO-1055-ERP']);

        $this->app->instance(ErpnextApiService::class, $erpnextServiceMock);

        $payload = [
            'id' => 8209829119461,
            'name' => '#1055',
            'order_number' => 1055,
            'created_at' => '2026-10-05T12:00:00Z',
            'customer' => [
                'id' => 11223344,
                'first_name' => 'Alex',
                'last_name' => 'Johnson',
                'email' => 'alex@example.com',
                'phone' => '+971501234567',
                'default_address' => [
                    'company' => 'Acme Corp'
                ]
            ],
            'line_items' => [
                [
                    'variant_id' => 43219876,
                    'product_id' => 87654321,
                    'sku' => 'ITEM-SKU-001',
                    'quantity' => 2,
                    'price' => '45.00'
                ]
            ]
        ];

        $response = $this->postJson('/api/method/middleware.apis.sales_order.create_sales_order', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Sales Order created successfully in ERPNext',
                'sales_order_id' => 'SO-1055-ERP',
                'customer_id' => 'CUST-2026-ALEX',
            ]);

        // Second call with same order should detect already_synced to avoid duplicates
        $secondResponse = $this->postJson('/api/method/middleware.apis.sales_order.create_sales_order', $payload);
        $secondResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'already_synced' => true,
            ]);
    }

    public function test_creates_sales_order_with_existing_customer_id()
    {
        $erpnextServiceMock = Mockery::mock(ErpnextApiService::class);

        // Expect getCustomer call to return customer record (exists)
        $erpnextServiceMock->shouldReceive('getCustomer')
            ->once()
            ->with('CUST-2026-00001')
            ->andReturn(['name' => 'CUST-2026-00001']);

        // Expect createSalesOrder to be called with resolved customer ID
        $erpnextServiceMock->shouldReceive('createSalesOrder')
            ->once()
            ->with(Mockery::on(function ($data) {
                return $data['customer'] === 'CUST-2026-00001'
                    && $data['transaction_date'] === '2026-05-20'
                    && $data['delivery_date'] === '2026-05-25'
                    && $data['items'][0]['item_code'] === '106320642'
                    && $data['items'][0]['qty'] === 2
                    && $data['items'][0]['rate'] === 32;
            }))
            ->andReturn(['name' => 'SO-0001']);

        $this->app->instance(ErpnextApiService::class, $erpnextServiceMock);

        $payload = [
            'customer' => 'CUST-2026-00001',
            'transaction_date' => '2026-05-20',
            'delivery_date' => '2026-05-25',
            'items' => [
                [
                    'item_code' => '106320642',
                    'qty' => 2,
                    'rate' => 32
                ]
            ]
        ];

        $response = $this->postJson('/api/method/middleware.apis.sales_order.create_sales_order', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Sales Order created successfully in ERPNext',
                'data' => ['name' => 'SO-0001']
            ]);
    }

    public function test_creates_sales_order_and_auto_creates_customer_when_not_found()
    {
        $erpnextServiceMock = Mockery::mock(ErpnextApiService::class);

        // getCustomer returns null
        $erpnextServiceMock->shouldReceive('getCustomer')
            ->once()
            ->with('New Customer')
            ->andReturn(null);

        // findCustomerByName returns null
        $erpnextServiceMock->shouldReceive('findCustomerByName')
            ->once()
            ->with('New Customer')
            ->andReturn(null);

        // createCustomer gets called
        $erpnextServiceMock->shouldReceive('createCustomer')
            ->once()
            ->with([
                'customer_name' => 'New Customer',
                'customer_type' => 'Individual'
            ])
            ->andReturn(['name' => 'CUST-2026-99999']);

        // createSalesOrder gets called with new customer ID
        $erpnextServiceMock->shouldReceive('createSalesOrder')
            ->once()
            ->with(Mockery::on(function ($data) {
                return $data['customer'] === 'CUST-2026-99999';
            }))
            ->andReturn(['name' => 'SO-0002']);

        $this->app->instance(ErpnextApiService::class, $erpnextServiceMock);

        $payload = [
            'customer' => 'New Customer',
            'items' => [
                [
                    'item_code' => '106320642',
                    'qty' => 2,
                    'rate' => 32
                ]
            ]
        ];

        $response = $this->postJson('/api/method/middleware.apis.sales_order.create_sales_order', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => ['name' => 'SO-0002']
            ]);
    }

    public function test_creates_sales_order_from_shopify_order_payload()
    {
        // Setup product mapping
        ProductMap::create([
            'erp_item_code' => 'ERP-ITEM-001',
            'shopify_product_id' => '777777',
            'shopify_variant_id' => '888888',
        ]);

        $erpnextServiceMock = Mockery::mock(ErpnextApiService::class);

        // Auto-create customer in ERPNext
        $erpnextServiceMock->shouldReceive('createCustomer')
            ->once()
            ->with([
                'customer_name' => 'Alex Johnson',
                'customer_type' => 'Individual',
            ])
            ->andReturn(['name' => 'CUST-2026-00088']);

        // Expect createSalesOrder with mapped item_code and po_no
        $erpnextServiceMock->shouldReceive('createSalesOrder')
            ->once()
            ->with(Mockery::on(function ($data) {
                return $data['customer'] === 'CUST-2026-00088'
                    && $data['transaction_date'] === '2026-10-05'
                    && $data['po_no'] === '#1055'
                    && $data['items'][0]['item_code'] === 'ERP-ITEM-001'
                    && $data['items'][0]['qty'] == 3
                    && $data['items'][0]['rate'] == 45.00;
            }))
            ->andReturn(['name' => 'SO-SHOP-001']);

        $this->app->instance(ErpnextApiService::class, $erpnextServiceMock);

        $shopifyPayload = [
            'id' => 99887766,
            'name' => '#1055',
            'order_number' => 1055,
            'created_at' => '2026-10-05T10:00:00+04:00',
            'customer' => [
                'id' => 11223344,
                'first_name' => 'Alex',
                'last_name' => 'Johnson',
                'email' => 'alex@example.com',
                'phone' => '+971501234567',
            ],
            'line_items' => [
                [
                    'variant_id' => 888888,
                    'product_id' => 777777,
                    'sku' => 'SHOPIFY-SKU-001',
                    'quantity' => 3,
                    'price' => '45.00',
                ]
            ]
        ];

        $response = $this->postJson('/api/method/middleware.apis.sales_order.create_sales_order', $shopifyPayload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Sales Order created successfully in ERPNext',
                'sales_order_id' => 'SO-SHOP-001',
                'customer_id' => 'CUST-2026-00088',
            ]);

        // Verify customer mapping was created
        $this->assertDatabaseHas('customer_maps', [
            'shopify_customer_id' => '11223344',
            'erp_customer_id' => 'CUST-2026-00088'
        ]);

        // Verify sync log was created
        $this->assertDatabaseHas('sync_logs', [
            'entity_type' => 'Order',
            'entity_id' => '99887766',
            'status' => 'success'
        ]);
    }

    public function test_creates_sales_order_from_nested_order_key()
    {
        // Pre-existing customer mapping
        CustomerMap::create([
            'shopify_customer_id' => '556677',
            'erp_customer_id' => 'CUST-EXISTS-01'
        ]);

        $erpnextServiceMock = Mockery::mock(ErpnextApiService::class);

        $erpnextServiceMock->shouldReceive('createSalesOrder')
            ->once()
            ->with(Mockery::on(function ($data) {
                return $data['customer'] === 'CUST-EXISTS-01'
                    && $data['po_no'] === '#2020'
                    && $data['items'][0]['item_code'] === 'DIRECT-SKU-99';
            }))
            ->andReturn(['name' => 'SO-NESTED-01']);

        $this->app->instance(ErpnextApiService::class, $erpnextServiceMock);

        $nestedPayload = [
            'order' => [
                'id' => 443322,
                'name' => '#2020',
                'created_at' => '2026-10-05T12:00:00Z',
                'customer' => [
                    'id' => 556677,
                    'first_name' => 'Existing',
                    'last_name' => 'Customer',
                ],
                'line_items' => [
                    [
                        'sku' => 'DIRECT-SKU-99',
                        'quantity' => 1,
                        'price' => '100.00',
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/method/middleware.apis.sales_order.create_sales_order', $nestedPayload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'sales_order_id' => 'SO-NESTED-01',
                'customer_id' => 'CUST-EXISTS-01',
            ]);
    }

    public function test_creates_sales_order_for_guest_checkout_with_billing_address()
    {
        $erpnextServiceMock = Mockery::mock(ErpnextApiService::class);

        $erpnextServiceMock->shouldReceive('createCustomer')
            ->once()
            ->with([
                'customer_name' => 'Sarah Connor',
                'customer_type' => 'Company',
            ])
            ->andReturn(['name' => 'CUST-GUEST-99']);

        $erpnextServiceMock->shouldReceive('createSalesOrder')
            ->once()
            ->with(Mockery::on(function ($data) {
                return $data['customer'] === 'CUST-GUEST-99';
            }))
            ->andReturn(['name' => 'SO-GUEST-01']);

        $this->app->instance(ErpnextApiService::class, $erpnextServiceMock);

        $guestPayload = [
            'id' => 776655,
            'name' => '#3030',
            'email' => 'sarah@skynet.com',
            'billing_address' => [
                'first_name' => 'Sarah',
                'last_name' => 'Connor',
                'company' => 'Resistance Inc',
            ],
            'line_items' => [
                [
                    'sku' => 'DEFENSE-01',
                    'quantity' => 5,
                    'price' => '250.00',
                ]
            ]
        ];

        $response = $this->postJson('/api/method/middleware.apis.sales_order.create_sales_order', $guestPayload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'customer_id' => 'CUST-GUEST-99',
            ]);

        $this->assertDatabaseHas('customer_maps', [
            'shopify_customer_id' => 'guest:sarah@skynet.com',
            'erp_customer_id' => 'CUST-GUEST-99'
        ]);
    }

    public function test_fails_validation_for_shopify_order_with_empty_line_items()
    {
        $response = $this->postJson('/api/method/middleware.apis.sales_order.create_sales_order', [
            'id' => 12345,
            'line_items' => [],
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'success',
                'errors' => ['line_items']
            ]);
    }

    public function test_direct_method_route_without_api_prefix_works()
    {
        $response = $this->postJson('/method/middleware.apis.sales_order.create_sales_order', [
            'invalid_key' => 123
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'success',
                'errors' => ['customer', 'items']
            ]);
    }

    public function test_sync_unsynced_orders_without_body_returns_exact_shopify_order_format()
    {
        $shopifyMock = Mockery::mock(ShopifyGraphQLService::class);
        $sampleOrder = [
            'id' => 7182069563636,
            'admin_graphql_api_id' => 'gid://shopify/Order/7182069563636',
            'name' => '#1055',
            'order_number' => 1055,
            'created_at' => '2026-09-23T11:19:25+03:00',
            'currency' => 'QAR',
            'email' => 'nandu@halamama.com',
            'customer' => [
                'id' => 9672314093812,
                'first_name' => 'Nandu',
                'last_name' => 'test 2',
                'email' => 'nandu@halamama.com',
                'default_address' => [
                    'city' => 'Doha',
                    'country' => 'Qatar',
                    'phone' => '77532802'
                ]
            ],
            'line_items' => [
                [
                    'id' => 16949305508084,
                    'sku' => '160149',
                    'name' => 'Audi R8',
                    'price' => '449.00',
                    'quantity' => 1,
                ]
            ]
        ];

        $shopifyMock->shouldReceive('getOrders')
            ->once()
            ->andReturn(['orders' => [$sampleOrder]]);

        $this->app->instance(ShopifyGraphQLService::class, $shopifyMock);

        $erpMock = Mockery::mock(ErpnextApiService::class);
        $erpMock->shouldReceive('findCustomerByName')
            ->with('Nandu test 2')
            ->andReturn('CUST-2026-00017');

        $erpMock->shouldReceive('createSalesOrder')
            ->once()
            ->with(Mockery::on(function ($data) {
                return $data['customer'] === 'CUST-2026-00017'
                    && $data['po_no'] === '#1055'
                    && $data['set_warehouse'] === 'WH-1 - H'
                    && $data['items'][0]['item_code'] === '160149'
                    && $data['items'][0]['warehouse'] === 'WH-1 - H';
            }))
            ->andReturn(['name' => 'SAL-ORD-1055-OK']);

        $this->app->instance(ErpnextApiService::class, $erpMock);

        $response = $this->postJson('/method/middleware.apis.sales_order.create_sales_order', []);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'synced_count' => 1,
                'order' => [
                    'id' => 7182069563636,
                    'name' => '#1055',
                    'customer' => [
                        'first_name' => 'Nandu',
                        'last_name' => 'test 2',
                    ]
                ],
                'synced_orders' => [
                    [
                        'id' => 7182069563636,
                        'name' => '#1055',
                        'sales_order_id' => 'SAL-ORD-1055-OK',
                        'customer_id' => 'CUST-2026-00017',
                    ]
                ]
            ]);
    }

    public function test_creates_sales_order_from_exact_user_payload_without_default_customer()
    {
        $erpMock = Mockery::mock(ErpnextApiService::class);
        $erpMock->shouldReceive('findCustomerByName')
            ->with('Nandu test 2')
            ->andReturn('CUST-2026-00017');

        $erpMock->shouldReceive('createSalesOrder')
            ->once()
            ->with(Mockery::on(function ($data) {
                return $data['customer'] === 'CUST-2026-00017'
                    && $data['customer'] !== 'CUST-2026-00001'
                    && $data['po_no'] === '#1055'
                    && $data['set_warehouse'] === 'WH-1 - H'
                    && count($data['items']) === 2;
            }))
            ->andReturn(['name' => 'SAL-ORD-USER-EXACT']);

        $this->app->instance(ErpnextApiService::class, $erpMock);

        $payload = [
            'id' => 7182069563636,
            'name' => '#1055',
            'order_number' => 1055,
            'created_at' => '2026-09-23T11:19:25+03:00',
            'customer' => [
                'id' => 9672314093812,
                'first_name' => 'Nandu',
                'last_name' => 'test 2',
                'email' => 'nandu@halamama.com',
                'phone' => '77532802'
            ],
            'line_items' => [
                [
                    'id' => 16949305508084,
                    'sku' => '160149',
                    'quantity' => 1,
                    'price' => '449.00'
                ],
                [
                    'id' => 16949305540852,
                    'sku' => 'BD028BL',
                    'quantity' => 1,
                    'price' => '169.00'
                ]
            ]
        ];

        $response = $this->postJson('/method/middleware.apis.sales_order.create_sales_order', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'sales_order_id' => 'SAL-ORD-USER-EXACT',
                'customer_id' => 'CUST-2026-00017',
                'order' => [
                    'id' => 7182069563636,
                    'name' => '#1055',
                ]
            ]);
    }
}
