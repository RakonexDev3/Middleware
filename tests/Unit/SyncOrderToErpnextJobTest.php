<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Jobs\SyncOrderToErpnextJob;
use App\Models\CustomerMap;
use App\Models\ProductMap;
use App\Models\SyncLog;
use App\Services\ERPNext\ErpnextApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class SyncOrderToErpnextJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_syncs_order_to_erpnext_correctly_with_mapped_customer()
    {
        // 1. Arrange mappings in database
        CustomerMap::create([
            'shopify_customer_id' => '12345',
            'erp_customer_id' => 'CUST-2026-00001'
        ]);

        ProductMap::create([
            'erp_item_code' => '106320642',
            'shopify_product_id' => '98765',
            'shopify_variant_id' => '54321'
        ]);

        // 2. Mock ErpnextApiService
        $erpnextServiceMock = Mockery::mock(ErpnextApiService::class);
        
        // Assert that the API service is called with the expected payload structure
        $erpnextServiceMock->shouldReceive('createSalesOrder')
            ->once()
            ->with(Mockery::on(function ($data) {
                return $data['customer'] === 'CUST-2026-00001'
                    && $data['transaction_date'] === '2026-05-20'
                    && $data['delivery_date'] === '2026-05-25'
                    && count($data['items']) === 2
                    && $data['items'][0]['item_code'] === '106320642'
                    && $data['items'][1]['item_code'] === 'TRU-2297512';
            }))
            ->andReturn(['name' => 'SO-0001']);

        // 3. Define the Shopify payload
        $shopifyPayload = [
            'id' => 'order_123',
            'created_at' => '2026-05-20T10:11:56+05:30',
            'customer' => [
                'id' => 12345,
                'first_name' => 'John',
                'last_name' => 'Doe'
            ],
            'line_items' => [
                [
                    'variant_id' => 54321,
                    'product_id' => 98765,
                    'sku' => '106320642',
                    'quantity' => 2,
                    'price' => '32.00'
                ],
                [
                    'variant_id' => 11111,
                    'product_id' => 22222,
                    'sku' => 'TRU-2297512',
                    'quantity' => 1,
                    'price' => '30.00'
                ]
            ]
        ];

        // 4. Act: run the job
        $job = new SyncOrderToErpnextJob($shopifyPayload);
        $job->handle($erpnextServiceMock);

        // 5. Assert: a successful sync log was created
        $this->assertDatabaseHas('sync_logs', [
            'entity_type' => 'Order',
            'entity_id' => 'order_123',
            'status' => 'success'
        ]);
    }

    public function test_auto_creates_customer_when_not_mapped()
    {
        // 1. Mock ErpnextApiService
        $erpnextServiceMock = Mockery::mock(ErpnextApiService::class);

        // Assert customer creation is called
        $erpnextServiceMock->shouldReceive('createCustomer')
            ->once()
            ->with([
                'customer_name' => 'Jane Smith',
                'customer_type' => 'Individual',
                // 'customer_group' => env('ERPNEXT_CUSTOMER_GROUP', 'Individual'),
                // 'territory' => env('ERPNEXT_TERRITORY', 'All Territories'),
            ])
            ->andReturn(['data' => ['name' => 'CUST-2026-00002']]);
        
        // Assert sales order is created with the new customer code
        $erpnextServiceMock->shouldReceive('createSalesOrder')
            ->once()
            ->with(Mockery::on(function ($data) {
                return $data['customer'] === 'CUST-2026-00002';
            }))
            ->andReturn(['name' => 'SO-0002']);

        // 2. Define Shopify payload
        $shopifyPayload = [
            'id' => 'order_456',
            'created_at' => '2026-05-20T10:11:56+05:30',
            'customer' => [
                'id' => 67890,
                'first_name' => 'Jane',
                'last_name' => 'Smith',
                'email' => 'jane.smith@example.com'
            ],
            'line_items' => [
                [
                    'sku' => '106320642',
                    'quantity' => 2,
                    'price' => '32.00'
                ]
            ]
        ];

        // 3. Act: run the job
        $job = new SyncOrderToErpnextJob($shopifyPayload);
        $job->handle($erpnextServiceMock);

        // 4. Assert customer mapping is stored
        $this->assertDatabaseHas('customer_maps', [
            'shopify_customer_id' => '67890',
            'erp_customer_id' => 'CUST-2026-00002'
        ]);
    }

    public function test_auto_creates_customer_for_guest_checkout()
    {
        // 1. Mock ErpnextApiService
        $erpnextServiceMock = Mockery::mock(ErpnextApiService::class);

        // Assert customer creation is called with guest name and email
        $erpnextServiceMock->shouldReceive('createCustomer')
            ->once()
            ->with([
                'customer_name' => 'Guest User',
                'customer_type' => 'Individual',
            ])
            ->andReturn(['name' => 'CUST-2026-00003']); // ERPNext custom format structure can return root key
        
        // Assert sales order is created with the guest's customer code
        $erpnextServiceMock->shouldReceive('createSalesOrder')
            ->once()
            ->with(Mockery::on(function ($data) {
                return $data['customer'] === 'CUST-2026-00003';
            }))
            ->andReturn(['name' => 'SO-0003']);

        // 2. Define Shopify payload (guest order has no customer ID but has email and billing address)
        $shopifyPayload = [
            'id' => 'order_789',
            'created_at' => '2026-05-20T10:11:56+05:30',
            'email' => 'guest@example.com',
            'billing_address' => [
                'first_name' => 'Guest',
                'last_name' => 'User'
            ],
            'line_items' => [
                [
                    'sku' => '106320642',
                    'quantity' => 2,
                    'price' => '32.00'
                ]
            ]
        ];

        // 3. Act: run the job
        $job = new SyncOrderToErpnextJob($shopifyPayload);
        $job->handle($erpnextServiceMock);

        // 4. Assert guest mapping is stored
        $this->assertDatabaseHas('customer_maps', [
            'shopify_customer_id' => 'guest:guest@example.com',
            'erp_customer_id' => 'CUST-2026-00003'
        ]);
    }
}
