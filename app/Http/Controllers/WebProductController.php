<?php

namespace App\Http\Controllers;

use App\Models\ProductMap;
use App\Models\SyncLog;
use App\Models\Shop;
use Illuminate\Http\Request;

class WebProductController extends Controller
{
    /**
     * Display a listing of synced products and stats.
     */
    public function index(Request $request)
    {
        $shop = Shop::first();
        $shopDomain = $shop ? $shop->shop_domain : 'test-shop.myshopify.com';

        // Query product maps
        $query = ProductMap::latest();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('erp_item_code', 'like', "%{$search}%")
                  ->orWhere('shopify_product_id', 'like', "%{$search}%");
        }

        $products = $query->paginate(15)->withQueryString();

        // Attach last sync log status to each product mapping
        foreach ($products as $product) {
            $latestLog = SyncLog::where('entity_type', 'product')
                ->where('entity_id', $product->erp_item_code)
                ->latest()
                ->first();

            $product->sync_status = $latestLog ? $latestLog->status : 'unknown';
            $product->last_sync_at = $latestLog ? $latestLog->updated_at : null;
            $product->error_message = ($latestLog && $latestLog->status === 'failed') ? $latestLog->error_message : null;
        }

        // Stats
        $stats = [
            'total' => ProductMap::count(),
            'success' => SyncLog::where('entity_type', 'product')->where('status', 'success')->distinct('entity_id')->count('entity_id'),
            'failed' => SyncLog::where('entity_type', 'product')->where('status', 'failed')->distinct('entity_id')->count('entity_id'),
        ];

        return view('products.index', compact('products', 'stats', 'shopDomain'));
    }
}
