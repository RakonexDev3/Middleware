<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Shop;

class ShopifyAuthController extends Controller
{
    public function install(Request $request)
    {
        $shop = $request->query('shop') ?? config('services.shopify.domain');
        if (!$shop) {
            return response('Missing shop parameter', 400);
        }

        // Strip https:// to prevent double https in the URL
        $shop = preg_replace('#^https?://#', '', rtrim($shop, '/'));

        $clientId = config('services.shopify.client_id');
        $scopes = 'write_products,write_orders,write_inventory,read_customers';
        $redirectUri = urlencode(url('/shopify/auth/callback'));

        $nonce = bin2hex(random_bytes(16));
        session(['shopify_nonce' => $nonce]);

        $installUrl = "https://{$shop}/admin/oauth/authorize?client_id={$clientId}&scope={$scopes}&redirect_uri={$redirectUri}&state={$nonce}";

        return redirect($installUrl);
    }

    public function callback(Request $request)
    {
        $shop = $request->query('shop');
        $code = $request->query('code');
        $state = $request->query('state');

        // Strip https:// for consistency in the database
        $shop = preg_replace('#^https?://#', '', rtrim($shop, '/'));

        if ($state !== session('shopify_nonce')) {
            return response('Invalid State parameter for security', 403);
        }

        $clientId = config('services.shopify.client_id');
        $clientSecret = config('services.shopify.client_secret');

        $response = Http::post("https://{$shop}/admin/oauth/access_token", [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
        ]);

        if ($response->failed()) {
            return response('Failed to get access token: ' . $response->body(), 500);
        }

        $accessToken = $response->json('access_token');

        Shop::updateOrCreate(
            ['shop_domain' => $shop],
            ['access_token' => $accessToken]
        );

        return response("App successfully installed and authenticated for {$shop}!");
    }
}
