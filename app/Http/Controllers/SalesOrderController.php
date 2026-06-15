<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\ERPNext\ErpnextApiService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SalesOrderController extends Controller
{
    protected ErpnextApiService $erpnextService;

    public function __construct(ErpnextApiService $erpnextService)
    {
        $this->erpnextService = $erpnextService;
    }

    /**
     * Create a Sales Order in ERPNext, checking or auto-creating the customer first.
     */
    public function createSalesOrder(Request $request)
    {
        // 1. Validate the incoming request data
        $validator = Validator::make($request->all(), [
            'customer' => 'required|string',
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

        try {
            $customerInput = $request->input('customer');
            $customerId = null;

            Log::info('Checking customer in ERPNext', ['customer' => $customerInput]);

            // 2. Check if customer exists in ERPNext by ID/Document Name
            $customerDoc = $this->erpnextService->getCustomer($customerInput);
            if ($customerDoc) {
                $customerId = $customerInput;
                Log::info('Customer found in ERPNext by ID', ['customer_id' => $customerId]);
            } else {
                // Check if customer exists by Customer Name field
                $customerId = $this->erpnextService->findCustomerByName($customerInput);
                if ($customerId) {
                    Log::info('Customer found in ERPNext by Name', ['customer_id' => $customerId]);
                }
            }

            // 3. If customer does not exist, create it
            if (!$customerId) {
                Log::info('Customer not found in ERPNext. Auto-creating customer...', ['customer_name' => $customerInput]);
                
                $customerData = [
                    'customer_name' => $customerInput,
                    'customer_type' => 'Individual',
                ];

                $customerResponse = $this->erpnextService->createCustomer($customerData);
                $customerId = $customerResponse['data']['name'] ?? $customerResponse['name'] ?? null;

                if (!$customerId) {
                    throw new \Exception("Failed to retrieve ERPNext customer ID after auto-creation.");
                }

                Log::info('Successfully auto-created customer in ERPNext', ['customer_id' => $customerId]);
            }

            // 4. Construct ERPNext Sales Order Payload
            $transactionDate = $request->input('transaction_date') ?? date('Y-m-d');
            $deliveryDate = $request->input('delivery_date') ?? date('Y-m-d', strtotime($transactionDate . ' +5 days'));

            $salesOrderData = [
                'customer' => $customerId,
                'transaction_date' => $transactionDate,
                'delivery_date' => $deliveryDate,
                'items' => array_map(function ($item) {
                    return [
                        'item_code' => $item['item_code'],
                        'qty' => $item['qty'],
                        'rate' => $item['rate'],
                    ];
                }, $request->input('items')),
            ];

            Log::info('Creating Sales Order in ERPNext', ['payload' => $salesOrderData]);

            // 5. Submit Sales Order to ERPNext standard API
            $response = $this->erpnextService->createSalesOrder($salesOrderData);

            return response()->json([
                'success' => true,
                'message' => 'Sales Order created successfully in ERPNext',
                'data' => $response
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create Sales Order in ERPNext', [
                'error' => $e->getMessage(),
                'payload' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'ERPNext API Error: ' . $e->getMessage()
            ], 500);
        }
    }
}
