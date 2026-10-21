<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Shop;
use App\Models\SyncLog;
use App\Services\ERPNext\ErpnextApiService;
use App\Services\ERPNext\SalesOrderService;
use App\Services\Shopify\ShopifyGraphQLService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class SyncShopifyOrdersCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_command_is_scheduled_every_minute()
    {
        $schedule = app(Schedule::class);
        $events = collect($schedule->events());

        $orderSyncEvent = $events->first(function ($event) {
            return str_contains($event->command, 'sync:shopify-orders');
        });

        $this->assertNotNull($orderSyncEvent, 'sync:shopify-orders command is not registered in the schedule.');
        $this->assertEquals('* * * * *', $orderSyncEvent->expression, 'sync:shopify-orders is not scheduled every minute.');
    }

    public function test_command_warns_when_no_shopify_store_connected()
    {
        config(['services.shopify.domain' => null, 'services.shopify.access_token' => null]);

        $this->artisan('sync:shopify-orders')
            ->expectsOutputToContain('Checking for new orders from Shopify')
            ->expectsOutputToContain('No connected Shopify store found')
            ->assertSuccessful();
    }

    public function test_command_syncs_new_shopify_orders_successfully()
    {
        // 1. Arrange a connected shop
        Shop::create([
            'shop_domain' => 'test-store.myshopify.com',
            'access_token' => 'shpat_test_token_123',
        ]);

        // 2. Mock Shopify GraphQL Service
        $shopifyMock = Mockery::mock(ShopifyGraphQLService::class);
        $shopifyMock->shouldReceive('getOrders')
            ->andReturn([
                'orders' => [
                    [
                        'id' => 8881234567,
                        'name' => '#1099',
                        'order_number' => 1099,
                        'created_at' => '2026-10-11T10:00:00Z',
                        'customer' => [
                            'id' => 555666777,
                            'first_name' => 'John',
                            'last_name' => 'Cron',
                            'email' => 'cron.john@example.com',
                        ],
                        'line_items' => [
                            [
                                'id' => 112233,
                                'variant_id' => 445566,
                                'product_id' => 778899,
                                'sku' => 'SKU-CRON-01',
                                'name' => 'Cron Widget',
                                'quantity' => 1,
                                'price' => '120.00'
                            ]
                        ]
                    ]
                ]
            ]);

        // 3. Mock ERPNext API Service
        $erpnextMock = Mockery::mock(ErpnextApiService::class);
        $erpnextMock->shouldReceive('createCustomer')
            ->once()
            ->andReturn(['name' => 'CUST-CRON-JOHN']);

        $erpnextMock->shouldReceive('createSalesOrder')
            ->once()
            ->with(Mockery::on(function ($data) {
                $custId = is_array($data['customer']) ? ($data['customer']['customer_id'] ?? null) : $data['customer'];
                return $custId === 'CUST-CRON-JOHN'
                    && $data['po_no'] === '#1099'
                    && $data['items'][0]['item_code'] === 'SKU-CRON-01';
            }))
            ->andReturn(['name' => 'SO-CRON-001']);

        $this->app->instance(ShopifyGraphQLService::class, $shopifyMock);
        $this->app->instance(ErpnextApiService::class, $erpnextMock);

        // 4. Run the Artisan command
        $this->artisan('sync:shopify-orders')
            ->expectsOutputToContain('Checking for new orders from Shopify')
            ->expectsOutputToContain('Successfully synced 1 new order(s) to ERPNext.')
            ->expectsOutputToContain('Synced: #1099 -> ERPNext Sales Order: SO-CRON-001')
            ->assertSuccessful();

        // 5. Assert SyncLog has the record with the ERP payload
        $log = SyncLog::where('entity_id', '8881234567')->first();
        $this->assertNotNull($log);
        $this->assertEquals('success', $log->status);
        $this->assertEquals('Order', $log->entity_type);
        $this->assertStringContainsString('CUST-CRON-JOHN', $log->payload);
        $this->assertStringContainsString('SKU-CRON-01', $log->payload);
    }

    public function test_command_skips_already_synced_orders()
    {
        Shop::create([
            'shop_domain' => 'test-store.myshopify.com',
            'access_token' => 'shpat_test_token_123',
        ]);

        // Pre-mark order as already synced
        SyncLog::create([
            'entity_type' => 'Order',
            'entity_id' => '777888999',
            'direction' => 'Shopify -> ERPNext',
            'status' => 'success',
            'payload' => '{}',
        ]);

        $shopifyMock = Mockery::mock(ShopifyGraphQLService::class);
        $shopifyMock->shouldReceive('getOrders')
            ->andReturn([
                'orders' => [
                    [
                        'id' => 777888999,
                        'name' => '#1088',
                        'order_number' => 1088,
                        'customer' => ['id' => 1],
                        'line_items' => []
                    ]
                ]
            ]);

        $this->app->instance(ShopifyGraphQLService::class, $shopifyMock);

        $this->artisan('sync:shopify-orders')
            ->expectsOutputToContain('No new orders to sync. (Skipped 1 already-synced orders).')
            ->assertSuccessful();
    }
}
