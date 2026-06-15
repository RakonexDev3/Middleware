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
        $this->domain = preg_replace('#^https?://#', '', rtrim($shopDomain ?? config('services.shopify.domain', ''), '/'));
        
        $shop = \App\Models\Shop::where('shop_domain', $this->domain)->first();
        if (!$shop || !$shop->access_token) {
            throw new \Exception("Shopify OAuth access token not found for shop: {$this->domain}. Please run the installation flow first.");
        }
        
        $this->accessToken = $shop->access_token;
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
}
