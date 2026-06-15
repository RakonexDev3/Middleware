<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Services\ERPNext\ErpnextApiService;
use Mockery;

class SalesOrderControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_fails_validation_for_missing_required_fields()
    {
        $response = $this->postJson('/api/method/middleware.apis.sales_order.create_sales_order', []);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'success',
                'errors' => ['customer', 'items']
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
}
