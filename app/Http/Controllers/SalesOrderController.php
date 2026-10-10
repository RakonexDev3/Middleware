<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\ERPNext\ErpnextApiService;
use App\Services\ERPNext\SalesOrderService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SalesOrderController extends Controller
{
    protected ErpnextApiService $erpnextService;
    protected SalesOrderService $salesOrderService;

    public function __construct(ErpnextApiService $erpnextService, ?SalesOrderService $salesOrderService = null)
    {
        $this->erpnextService = $erpnextService;
        $this->salesOrderService = $salesOrderService ?? new SalesOrderService($erpnextService);
    }

    /**
     * Create a Sales Order in ERPNext when an order is received from Shopify or API.
     *
     * If no body content is provided, it automatically fetches orders from Shopify,
     * identifies new and unsynced orders, and forwards them to ERPNext.
     *
     * Endpoint: /method/middleware.apis.sales_order.create_sales_order
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createSalesOrder(Request $request)
    {
        $payload = $request->input('order') ?? $request->all();

        // 1. If NO body content is sent: automatically forward orders from Shopify (new + unsynced)
        if (empty($payload)) {
            Log::info('No body content provided to create_sales_order. Automatically fetching and forwarding unsynced orders from Shopify.');

            try {
                $result = $this->salesOrderService->syncUnsyncedOrdersFromShopify();
                $statusCode = $result['success'] ? 200 : 500;
                return response()->json($result, $statusCode);
            } catch (\Exception $e) {
                Log::error('Automatic Shopify order sync failed', [
                    'error' => $e->getMessage()
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Failed to sync orders from Shopify: ' . $e->getMessage()
                ], 500);
            }
        }

        // 2. If body content IS sent:
        // Detect if payload is Shopify order format
        $isShopifyOrder = isset($payload['line_items'])
            || (isset($payload['customer']) && is_array($payload['customer']))
            || (isset($payload['id']) && (isset($payload['order_number']) || isset($payload['name'])));

        if ($isShopifyOrder) {
            $validator = Validator::make($payload, [
                'line_items' => 'required|array|min:1',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            // Check if this Shopify order was already synced to avoid duplicate Sales Orders
            $orderId = $payload['id'] ?? null;
            if ($orderId && !$request->boolean('force') && $this->salesOrderService->isOrderSynced($orderId)) {
                $orderName = $payload['name'] ?? ('#' . ($payload['order_number'] ?? $orderId));
                Log::info("Order {$orderName} (ID: {$orderId}) was already synced to ERPNext. Skipping duplicate creation.");

                return response()->json([
                    'success' => true,
                    'already_synced' => true,
                    'message' => "Order {$orderName} (ID: {$orderId}) has already been synced to ERPNext.",
                    'order_id' => $orderId,
                    'order' => $payload,
                ], 200);
            }

        } else {
            $validator = Validator::make($payload, [
                'customer' => 'required',
                'transaction_date' => 'nullable|date_format:Y-m-d',
                'delivery_date' => 'nullable|date_format:Y-m-d',
                'items' => 'required|array|min:1',
                'items.*.item_code' => 'required|string',
                'items.*.qty' => 'required|numeric|min:0.01',
                'items.*.rate' => 'required|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }
        }

        try {
            $result = $this->salesOrderService->processOrder($payload);
            $result['order'] = $payload;

            return response()->json($result, 201);

        } catch (\Exception $e) {
            Log::error('Failed to process Sales Order', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);

            return response()->json([
                'success' => false,
                'error' => 'ERPNext API Error: ' . $e->getMessage()
            ], 500);
        }
    }
}
