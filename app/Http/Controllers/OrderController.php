<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\Shopify\ShopifyGraphQLService;
use App\Models\Shop;

class OrderController extends Controller
{
    /**
     * Display a listing of Shopify orders.
     */
    public function index()
    {
        $orders = [];
        $error = null;
        
        try {
            // Get the first installed shop from our DB
            $shop = Shop::first();
            
            if ($shop) {
                $shopify = new ShopifyGraphQLService($shop->shop_domain);
                $query = '
                    query {
                        orders(first: 20, sortKey: CREATED_AT, reverse: true) {
                            edges {
                                node {
                                    id
                                    name
                                    createdAt
                                    displayFinancialStatus
                                    displayFulfillmentStatus
                                    totalPriceSet {
                                        shopMoney {
                                            amount
                                            currencyCode
                                        }
                                    }
                                    customer {
                                        firstName
                                        lastName
                                    }
                                }
                            }
                        }
                    }
                ';
                
                $response = $shopify->query($query);
                
                if (isset($response['data']['orders']['edges'])) {
                    foreach ($response['data']['orders']['edges'] as $edge) {
                        $orders[] = $edge['node'];
                    }
                }
            } else {
                // Fallback mock data if no shop is installed
                $error = "No Shopify store connected. Showing mock data for demonstration purposes. Please run the Shopify installation flow.";
                $orders = [
                    [
                        'id' => 'gid://shopify/Order/1',
                        'name' => '#1001',
                        'createdAt' => '2026-05-19T10:00:00Z',
                        'displayFinancialStatus' => 'PAID',
                        'displayFulfillmentStatus' => 'FULFILLED',
                        'totalPriceSet' => [
                            'shopMoney' => [
                                'amount' => '120.50',
                                'currencyCode' => 'USD'
                            ]
                        ],
                        'customer' => [
                            'firstName' => 'John',
                            'lastName' => 'Doe'
                        ]
                    ],
                    [
                        'id' => 'gid://shopify/Order/2',
                        'name' => '#1002',
                        'createdAt' => '2026-05-19T14:30:00Z',
                        'displayFinancialStatus' => 'PENDING',
                        'displayFulfillmentStatus' => 'UNFULFILLED',
                        'totalPriceSet' => [
                            'shopMoney' => [
                                'amount' => '45.00',
                                'currencyCode' => 'USD'
                            ]
                        ],
                        'customer' => [
                            'firstName' => 'Jane',
                            'lastName' => 'Smith'
                        ]
                    ],
                    [
                        'id' => 'gid://shopify/Order/3',
                        'name' => '#1003',
                        'createdAt' => '2026-05-20T09:15:00Z',
                        'displayFinancialStatus' => 'PAID',
                        'displayFulfillmentStatus' => 'PARTIALLY_FULFILLED',
                        'totalPriceSet' => [
                            'shopMoney' => [
                                'amount' => '250.00',
                                'currencyCode' => 'USD'
                            ]
                        ],
                        'customer' => null
                    ],
                ];
            }
        } catch (\Exception $e) {
            $error = "Failed to fetch orders from Shopify: " . $e->getMessage();
        }

        return view('orders.index', compact('orders', 'error'));
    }
}
