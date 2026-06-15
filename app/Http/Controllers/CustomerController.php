<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\Shopify\ShopifyGraphQLService;
use App\Models\Shop;

class CustomerController extends Controller
{
    /**
     * Display a listing of Shopify customers.
     */
    public function index()
    {
        $customers = [];
        $error = null;
        
        try {
            // Get the first installed shop from our DB
            $shop = Shop::first();
            
            if ($shop) {
                $shopify = new ShopifyGraphQLService($shop->shop_domain);
                $query = '
                    query {
                        customers(first: 20) {
                            edges {
                                node {
                                    id
                                    firstName
                                    lastName
                                    email
                                    createdAt
                                    numberOfOrders
                                    amountSpent {
                                        amount
                                        currencyCode
                                    }
                                }
                            }
                        }
                    }
                ';
                
                $response = $shopify->query($query);
                
                if (isset($response['data']['customers']['edges'])) {
                    foreach ($response['data']['customers']['edges'] as $edge) {
                        $customers[] = $edge['node'];
                    }
                }
            } else {
                // Fallback mock data if no shop is installed
                $error = "No Shopify store connected. Showing mock data for demonstration purposes. Please run the Shopify installation flow.";
                $customers = [
                    [
                        'id' => 'gid://shopify/Customer/1',
                        'firstName' => 'John',
                        'lastName' => 'Doe',
                        'email' => 'john.doe@example.com',
                        'createdAt' => '2026-01-15T10:00:00Z',
                        'numberOfOrders' => 5,
                        'amountSpent' => ['amount' => '120.50', 'currencyCode' => 'USD']
                    ],
                    [
                        'id' => 'gid://shopify/Customer/2',
                        'firstName' => 'Jane',
                        'lastName' => 'Smith',
                        'email' => 'jane.smith@example.com',
                        'createdAt' => '2026-02-20T14:30:00Z',
                        'numberOfOrders' => 2,
                        'amountSpent' => ['amount' => '45.00', 'currencyCode' => 'USD']
                    ],
                    [
                        'id' => 'gid://shopify/Customer/3',
                        'firstName' => 'Bob',
                        'lastName' => 'Johnson',
                        'email' => 'bob.j@example.com',
                        'createdAt' => '2026-03-05T09:15:00Z',
                        'numberOfOrders' => 0,
                        'amountSpent' => ['amount' => '0.00', 'currencyCode' => 'USD']
                    ],
                ];
            }

            // Enrich all customers (real or mock) with mapping and sync logs
            if (!empty($customers)) {
                $shopifyCustomerIds = [];
                foreach ($customers as $c) {
                    $idStr = $c['id'];
                    $shopifyCustomerIds[] = preg_match('/Customer\/(\d+)/', $idStr, $matches) ? $matches[1] : $idStr;
                }

                $mappings = \App\Models\CustomerMap::whereIn('shopify_customer_id', $shopifyCustomerIds)
                    ->get()
                    ->keyBy('shopify_customer_id');

                $syncLogs = \App\Models\SyncLog::where('entity_type', 'Customer')
                    ->whereIn('entity_id', $shopifyCustomerIds)
                    ->orderBy('created_at', 'desc')
                    ->get()
                    ->groupBy('entity_id');

                foreach ($customers as &$c) {
                    $idStr = $c['id'];
                    $rawId = preg_match('/Customer\/(\d+)/', $idStr, $matches) ? $matches[1] : $idStr;

                    $mapping = $mappings->get($rawId);
                    $logs = $syncLogs->get($rawId);
                    $latestLog = $logs ? $logs->first() : null;

                    $c['erp_customer_id'] = $mapping ? $mapping->erp_customer_id : null;
                    $c['sync_status'] = $latestLog ? $latestLog->status : ($mapping ? 'success' : 'not_synced');
                    $c['sync_error'] = ($latestLog && $latestLog->status === 'failed') ? $latestLog->error_message : null;
                }
            }
        } catch (\Exception $e) {
            $error = "Failed to fetch customers from Shopify: " . $e->getMessage();
        }

        return view('customers.index', compact('customers', 'error'));
    }
}
