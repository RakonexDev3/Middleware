<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Shop;
use App\Models\CustomerMap;
use App\Models\SyncLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CustomerControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_customers_index()
    {
        $response = $this->get('/customers');

        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_customers_list_with_shopify_and_erp_ids()
    {
        $user = User::factory()->create();

        // Seed customer map
        CustomerMap::create([
            'shopify_customer_id' => '1',
            'erp_customer_id' => 'CUST-ERP-001',
        ]);

        SyncLog::create([
            'direction' => 'Shopify to ERPNext',
            'entity_type' => 'Customer',
            'entity_id' => '1',
            'status' => 'success',
            'payload' => '{}',
        ]);

        $response = $this->actingAs($user)->get('/customers');

        $response->assertStatus(200);
        $response->assertSee('Shopify Customers');
        $response->assertSee('Shopify Customer ID');
        $response->assertSee('ERPNext ID');
        // Check Shopify customer ID displayed
        $response->assertSee('1');
        // Check ERPNext customer ID displayed
        $response->assertSee('CUST-ERP-001');
    }

    public function test_customers_list_renders_unmapped_customers_correctly()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/customers');

        $response->assertStatus(200);
        $response->assertSee('Shopify Customer ID');
        $response->assertSee('ERPNext ID');
        $response->assertSee('Not Mapped');
        $response->assertSee('2');
        $response->assertSee('3');
    }
}
