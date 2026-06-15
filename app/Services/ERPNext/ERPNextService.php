<?php

namespace App\Services\ERPNext;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;

class ERPNextService
{
    protected string $baseUrl;
    protected string $apiKey;
    protected string $apiSecret;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.erpnext.url', ''), '/');
        $this->apiKey = config('services.erpnext.api_key', '');
        $this->apiSecret = config('services.erpnext.api_secret', '');
    }

    /**
     * Get a configured HTTP client for ERPNext REST API.
     */
    protected function client(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => 'token ' . $this->apiKey . ':' . $this->apiSecret,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->baseUrl($this->baseUrl . '/api/resource');
    }

    /**
     * Get a configured HTTP client for ERPNext RPC methods.
     */
    protected function rpcClient(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => 'token ' . $this->apiKey . ':' . $this->apiSecret,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->baseUrl($this->baseUrl . '/api/method');
    }
}
