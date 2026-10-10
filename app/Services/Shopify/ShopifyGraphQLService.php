<?php

namespace App\Services\Shopify;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;

class ShopifyGraphQLService
{
    protected string $domain;
    protected string $accessToken;
    protected string $apiVersion;

    public function __construct(string $shopDomain = null)
    {
        $domain = $shopDomain ?? config('services.shopify.domain');
        $this->domain = preg_replace('#^https?://#', '', rtrim($domain ?? '', '/'));
        
        $token = config('services.shopify.access_token') ?? env('SHOPIFY_ACCESS_TOKEN');

        if (!$token && !empty($this->domain)) {
            try {
                $shop = \App\Models\Shop::where('shop_domain', $this->domain)->first();
                if ($shop && !empty($shop->access_token)) {
                    $token = $shop->access_token;
                }
            } catch (\Throwable $e) {
                // Ignore DB errors
            }
        }

        if (!$token) {
            try {
                $firstShop = \App\Models\Shop::whereNotNull('access_token')->first();
                if ($firstShop) {
                    if (empty($this->domain)) {
                        $this->domain = $firstShop->shop_domain;
                    }
                    $token = $firstShop->access_token;
                }
            } catch (\Throwable $e) {
                // Ignore DB errors
            }
        }

        if (!$token) {
            throw new \Exception("Shopify access token not found for shop: {$this->domain}. Please configure SHOPIFY_ACCESS_TOKEN or run the installation flow.");
        }
        
        $this->accessToken = $token;
        $this->apiVersion = config('services.shopify.api_version', '2024-01');
    }

    protected function client(): PendingRequest
    {
        return Http::withHeaders([
            'X-Shopify-Access-Token' => $this->accessToken,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->baseUrl("https://{$this->domain}/admin/api/{$this->apiVersion}/graphql.json");
    }

    public function query(string $query, array $variables = [])
    {
        $payload = ['query' => $query];
        
        if (!empty($variables)) {
            $payload['variables'] = $variables;
        }

        $response = $this->client()->post('', $payload);

        if ($response->failed()) {
            throw new \Exception("Shopify GraphQL Error: " . $response->body());
        }

        $json = $response->json();
        
        if (isset($json['errors'])) {
            throw new \Exception("Shopify GraphQL returned errors: " . json_encode($json['errors']));
        }

        return $json;
    }

    /**
     * Fetch orders using the Shopify REST Admin API.
     * Returns orders in standard Shopify REST format matching webhook payloads.
     *
     * @param int $limit
     * @param array $params
     * @return array
     * @throws \Exception
     */
    public function getOrders(int $limit = 50, array $params = []): array
    {
        $defaultParams = [
            'status' => 'any',
            'limit' => $limit,
        ];
        $queryParams = array_merge($defaultParams, $params);

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->accessToken,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->get("https://{$this->domain}/admin/api/{$this->apiVersion}/orders.json", $queryParams);

        if ($response->failed()) {
            throw new \Exception("Shopify Orders API Error: " . $response->body());
        }

        return $response->json();
    }

    /**
     * Get a single order by ID using the Shopify REST Admin API.
     *
     * @param string|int $orderId
     * @return array|null
     */
    public function getOrder($orderId): ?array
    {
        $idStr = (string) $orderId;
        $cleanId = preg_match('/Order\/(\d+)/', $idStr, $m) ? $m[1] : $idStr;

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->accessToken,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->get("https://{$this->domain}/admin/api/{$this->apiVersion}/orders/{$cleanId}.json");

        if ($response->status() === 404 || $response->failed()) {
            return null;
        }

        return $response->json()['order'] ?? null;
    }
}
