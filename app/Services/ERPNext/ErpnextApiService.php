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
        $response = $this->client()->post('/api/resource/Sales Order', $data);

        if ($response->failed()) {
            throw new \Exception("ERPNext API Error: " . $response->body());
        }

        return $response->json();
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
}

