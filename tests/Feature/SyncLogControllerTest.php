<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\SyncLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SyncLogControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login_from_logs()
    {
        $response = $this->get('/logs');
        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_logs_page_with_erp_payload_button()
    {
        $user = User::factory()->create();

        $erpPayload = [
            'order' => [
                'id' => '8209829119461',
                'order_number' => '#1055',
                'sales_channel' => 'shopify',
            ],
            'customer' => [
                'customer_id' => 'CUST-2026-ALEX',
                'customer_name' => 'Alex Johnson',
            ],
            'set_warehouse' => 'WH-1 - H',
            'items' => [
                [
                    'item_code' => 'ITEM-SKU-001',
                    'item_name' => 'Sample Product',
                    'quantity' => 2,
                    'unit_price' => 45.00,
                    'amount' => 90.00,
                ]
            ],
            'payment' => [
                'grand_total' => 90.00,
                'payment_status' => 'paid',
                'currency' => 'QAR',
            ]
        ];

        $log = SyncLog::create([
            'direction' => 'Shopify -> ERPNext',
            'entity_type' => 'Order',
            'entity_id' => '8209829119461',
            'status' => 'success',
            'payload' => json_encode($erpPayload),
            'error_message' => null,
        ]);

        $response = $this->actingAs($user)->get('/logs');

        $response->assertStatus(200)
            ->assertSee('ERPNext Sync Logs')
            ->assertSee('8209829119461')
            ->assertSee('View ERP Payload')
            ->assertSee('Alex Johnson')
            ->assertSee('ITEM-SKU-001');
    }

    public function test_filter_by_type_and_status()
    {
        $user = User::factory()->create();

        SyncLog::create([
            'direction' => 'Shopify -> ERPNext',
            'entity_type' => 'Order',
            'entity_id' => 'ORDER-101',
            'status' => 'success',
            'payload' => json_encode(['po_no' => '#101']),
        ]);

        SyncLog::create([
            'direction' => 'Shopify -> ERPNext',
            'entity_type' => 'Customer',
            'entity_id' => 'CUST-202',
            'status' => 'failed',
            'payload' => json_encode(['customer_name' => 'Fail']),
            'error_message' => 'Customer creation failed in ERPNext',
        ]);

        // Filter by Order
        $response = $this->actingAs($user)->get('/logs?type=Order');
        $response->assertStatus(200)
            ->assertSee('ORDER-101')
            ->assertDontSee('CUST-202');

        // Filter by failed status
        $response2 = $this->actingAs($user)->get('/logs?status=failed');
        $response2->assertStatus(200)
            ->assertSee('CUST-202')
            ->assertDontSee('ORDER-101');
    }

    public function test_payload_endpoint_returns_json_payload()
    {
        $user = User::factory()->create();

        $erpPayload = [
            'customer' => ['customer_id' => 'CUST-001'],
            'items' => [['item_code' => 'TEST-SKU', 'quantity' => 1]],
        ];

        $log = SyncLog::create([
            'direction' => 'Shopify -> ERPNext',
            'entity_type' => 'Order',
            'entity_id' => '999',
            'status' => 'success',
            'payload' => json_encode($erpPayload),
        ]);

        $response = $this->actingAs($user)->getJson("/logs/{$log->id}/payload");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'log' => [
                    'id' => $log->id,
                    'entity_type' => 'Order',
                    'entity_id' => '999',
                    'payload' => $erpPayload,
                ]
            ]);
    }

    public function test_search_filters_by_order_id_or_sku()
    {
        $user = User::factory()->create();

        SyncLog::create([
            'direction' => 'Shopify -> ERPNext',
            'entity_type' => 'Order',
            'entity_id' => 'SEARCH-ORD-555',
            'status' => 'success',
            'payload' => json_encode(['items' => [['item_code' => 'TARGET-SKU-99']]]),
        ]);

        SyncLog::create([
            'direction' => 'Shopify -> ERPNext',
            'entity_type' => 'Order',
            'entity_id' => 'OTHER-ORD-666',
            'status' => 'success',
            'payload' => json_encode(['items' => [['item_code' => 'DIFF-SKU-11']]]),
        ]);

        $response = $this->actingAs($user)->get('/logs?search=TARGET-SKU-99');
        $response->assertStatus(200)
            ->assertSee('SEARCH-ORD-555')
            ->assertDontSee('OTHER-ORD-666');
    }
}
