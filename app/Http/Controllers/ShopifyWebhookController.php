<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShopifyWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $topic = $request->header('X-Shopify-Topic');
        $payload = $request->all();

        Log::info('Shopify Webhook Received', [
            'topic' => $topic,
            'id' => $payload['id'] ?? null
        ]);

        // Dispatch to appropriate Job based on X-Shopify-Topic header
        if ($topic === 'orders/create') {
            \App\Jobs\SyncOrderToErpnextJob::dispatch($payload);
        } elseif ($topic === 'customers/create') {
            \App\Jobs\ProcessCustomerSyncJob::dispatch($payload);
        }

        return response()->json(['status' => 'Webhook received']);
    }
}
