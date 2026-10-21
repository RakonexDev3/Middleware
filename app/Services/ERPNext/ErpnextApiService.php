<?php

namespace App\Services\ERPNext;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;

class ErpnextApiService
{
    protected string $baseUrl;
    protected string $apiKey;
    protected string $apiSecret;

    public function __construct()
    {
        $this->baseUrl = rtrim(env('ERPNEXT_URL', ''), '/');
        $this->apiKey = env('ERPNEXT_API_KEY', '');
        $this->apiSecret = env('ERPNEXT_API_SECRET', '');
    }

    /**
     * Get a configured HTTP client for ERPNext.
     */
    protected function client(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => "token {$this->apiKey}:{$this->apiSecret}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->baseUrl($this->baseUrl);
    }

    /**
     * Create a new Sales Order in ERPNext.
     *
     * @param array $data The payload containing customer, items, etc.
     * @return array The ERPNext API response body.
     * @throws \Exception If the API request fails.
     */
    public function createSalesOrder(array $data)
    {
        $payload = $this->formatForErpnextDocType($data);
        $response = $this->client()->post('api/method/middleware.apis.sales_order.create_sales_order', $payload);

        if ($response->failed()) {
            throw new \Exception("ERPNext API Error: " . $response->body());
        }

        return $response->json();
    }

    /**
     * Format a rich or nested payload for standard ERPNext DocType REST API.
     */
    public function formatForErpnextDocType(array $data): array
    {
        // If already flat format with string customer, return as-is
        if (isset($data['customer']) && is_string($data['customer']) && !isset($data['order'])) {
            return $data;
        }

        $customerId = is_array($data['customer'] ?? null) 
            ? ($data['customer']['customer_id'] ?? null) 
            : ($data['customer_id'] ?? ($data['customer'] ?? null));

        $order = $data['order'] ?? [];
        $deliveryDate = $order['delivery_date'] ?? $data['delivery_date'] ?? null;
        $transactionDate = isset($order['order_date']) ? date('Y-m-d', strtotime($order['order_date'])) : ($data['transaction_date'] ?? date('Y-m-d'));
        $poNo = $order['po_no'] ?? $order['order_number'] ?? $data['po_no'] ?? null;
        $salesChannel = $order['sales_channel'] ?? $data['sales_channel'] ?? 'shopify';

        $items = [];
        foreach (($data['items'] ?? []) as $item) {
            $warehouse = is_array($item['warehouse'] ?? null) 
                ? ($item['warehouse']['warehouse_id'] ?? null) 
                : ($item['warehouse'] ?? ($item['warehouse_code'] ?? null));

            $items[] = [
                'item_code' => $item['item_code'] ?? null,
                'qty' => $item['quantity'] ?? $item['qty'] ?? 1,
                'rate' => $item['unit_price'] ?? $item['rate'] ?? 0,
                'warehouse' => $warehouse ?: env('ERPNEXT_DEFAULT_WAREHOUSE', 'WH-1 - H'),
            ];
        }

        $formatted = [
            'customer' => $customerId,
            'transaction_date' => $transactionDate,
            'delivery_date' => $deliveryDate,
            'items' => $items,
            'sales_channel' => $salesChannel,
        ];

        if (!empty($poNo)) {
            $formatted['po_no'] = (string) $poNo;
        }

        $defaultWarehouse = env('ERPNEXT_DEFAULT_WAREHOUSE', 'WH-1 - H');
        if (!empty($defaultWarehouse)) {
            $formatted['set_warehouse'] = $defaultWarehouse;
        }

        return $formatted;
    }

    /**
     * Create a new Customer in ERPNext.
     *
     * @param array $data The payload containing customer details.
     * @return array The ERPNext API response body.
     * @throws \Exception If the API request fails.
     */
    public function createCustomer(array $data)
    {
        $response = $this->client()->post('/api/resource/Customer', $data);

        if ($response->failed()) {
            throw new \Exception("ERPNext API Error (Customer Creation): " . $response->body());
        }

        return $response->json();
    }

    /**
     * Get a customer by document name (ID) from ERPNext.
     *
     * @param string $name The Customer ID (e.g. CUST-2026-00001)
     * @return array|null The customer details or null if not found.
     */
    public function getCustomer(string $name): ?array
    {
        $response = $this->client()->get("/api/resource/Customer/" . urlencode($name));

        if ($response->status() === 404 || $response->failed()) {
            return null;
        }

        return $response->json();
    }

    /**
     * Find a Customer by customer_name field.
     *
     * @param string $customerName The customer's display name.
     * @return string|null The Customer ID (name) if found, otherwise null.
     */
    public function findCustomerByName(string $customerName): ?string
    {
        $filters = json_encode([['customer_name', '=', $customerName]]);
        
        $response = $this->client()->get('/api/resource/Customer', [
            'filters' => $filters,
            'limit' => 1
        ]);

        if ($response->failed()) {
            return null;
        }

        $data = $response->json();
        return $data['data'][0]['name'] ?? null;
    }

    /**
     * Get an item by item_code from ERPNext.
     *
     * @param string $itemCode
     * @return array|null
     */
    public function getItem(string $itemCode): ?array
    {
        $response = $this->client()->get("/api/resource/Item/" . urlencode($itemCode));

        if ($response->status() === 404 || $response->failed()) {
            return null;
        }

        return $response->json();
    }

    /**
     * Create a new Item in ERPNext.
     *
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function createItem(array $data): array
    {
        $response = $this->client()->post('/api/resource/Item', $data);

        if ($response->failed()) {
            throw new \Exception("ERPNext API Error (Item Creation): " . $response->body());
        }

        return $response->json();
    }

    /**
     * Get the default or first available non-group warehouse from ERPNext.
     *
     * @return string|null
     */
    public function getDefaultWarehouse(): ?string
    {
        try {
            $filters = json_encode([['is_group', '=', 0], ['disabled', '=', 0]]);
            $response = $this->client()->get('/api/resource/Warehouse', [
                'filters' => $filters,
                'limit' => 1
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['data'][0]['name'] ?? null;
            }
        } catch (\Throwable $e) {
            // Ignore
        }

        return null;
    }
}


