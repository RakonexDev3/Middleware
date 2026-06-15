@extends('layouts.app')

@section('title', 'Products Sync')
@section('page_title', 'Products Sync')

@section('content')

    <!-- Stats Grid -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <!-- Card 1 -->
        <div class="bg-white p-6 rounded-xl border border-gray-200 shadow-sm flex items-center">
            <div class="p-3 rounded-lg bg-blue-50 text-blue-600 mr-4">
                <i class="fa-solid fa-box text-2xl"></i>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500 uppercase">Mapped Products</p>
                <p class="text-2xl font-bold text-gray-900">{{ $stats['total'] }}</p>
            </div>
        </div>
        <!-- Card 2 -->
        <div class="bg-white p-6 rounded-xl border border-gray-200 shadow-sm flex items-center">
            <div class="p-3 rounded-lg bg-green-50 text-green-600 mr-4">
                <i class="fa-solid fa-circle-check text-2xl"></i>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500 uppercase">Successful Syncs</p>
                <p class="text-2xl font-bold text-gray-900">{{ $stats['success'] }}</p>
            </div>
        </div>
        <!-- Card 3 -->
        <div class="bg-white p-6 rounded-xl border border-gray-200 shadow-sm flex items-center">
            <div class="p-3 rounded-lg bg-red-50 text-red-600 mr-4">
                <i class="fa-solid fa-circle-exclamation text-2xl"></i>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500 uppercase">Failed Syncs</p>
                <p class="text-2xl font-bold text-gray-900">{{ $stats['failed'] }}</p>
            </div>
        </div>
    </div>

    <!-- Main Table Card -->
    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden mb-8" x-data="{ showModal: false }">
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <h2 class="text-lg font-medium text-gray-800">Synced Products Mapping</h2>
            
            <div class="flex flex-wrap items-center gap-3 w-full sm:w-auto">
                <form method="GET" action="{{ route('products.index') }}" class="flex items-center gap-2 flex-1 sm:flex-none">
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Search SKU..." class="bg-white border border-gray-300 rounded-md px-3 py-1.5 text-xs text-gray-700 focus:outline-none focus:ring-1 focus:ring-blue-500 w-full sm:w-48">
                    @if(request('search'))
                        <a href="{{ route('products.index') }}" class="text-xs text-gray-500 hover:text-gray-700 mr-2">Clear</a>
                    @endif
                </form>

                <button @click="showModal = true" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold px-4 py-2 rounded-md transition flex items-center gap-1.5 shadow-sm">
                    <i class="fa-solid fa-flask"></i> Trigger Test Sync
                </button>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-white">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">SKU (ERP Item Code)</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Shopify Product</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Shopify Variant</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Last Sync Status</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Last Sync Date</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($products as $product)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="font-mono text-sm font-semibold text-gray-800">{{ $product->erp_item_code }}</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                @if($product->shopify_product_id)
                                    @php
                                        $prodId = preg_match('/Product\/(\d+)/', $product->shopify_product_id, $matches) ? $matches[1] : null;
                                    @endphp
                                    @if($prodId)
                                        <a href="https://{{ $shopDomain }}/admin/products/{{ $prodId }}" target="_blank" class="text-sm text-blue-600 hover:text-blue-800 hover:underline flex items-center gap-1">
                                            <span>#{{ $prodId }}</span>
                                            <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                                        </a>
                                    @else
                                        <span class="text-sm text-gray-500">{{ $product->shopify_product_id }}</span>
                                    @endif
                                @else
                                    <span class="text-sm text-gray-400">-</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                @if($product->shopify_variant_id)
                                    @php
                                        $prodId = preg_match('/Product\/(\d+)/', $product->shopify_product_id, $matches) ? $matches[1] : null;
                                        $varId = preg_match('/ProductVariant\/(\d+)/', $product->shopify_variant_id, $matches) ? $matches[1] : null;
                                    @endphp
                                    @if($prodId && $varId)
                                        <a href="https://{{ $shopDomain }}/admin/products/{{ $prodId }}/variants/{{ $varId }}" target="_blank" class="text-sm text-slate-600 hover:text-slate-800 hover:underline flex items-center gap-1">
                                            <span>#{{ $varId }}</span>
                                            <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                                        </a>
                                    @else
                                        <span class="text-sm text-gray-500">{{ $product->shopify_variant_id }}</span>
                                    @endif
                                @else
                                    <span class="text-sm text-gray-400">-</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                @php
                                    $badgeColor = match($product->sync_status) {
                                        'success' => 'bg-green-100 text-green-800',
                                        'failed' => 'bg-red-100 text-red-800',
                                        default => 'bg-gray-100 text-gray-800',
                                    };
                                    $icon = match($product->sync_status) {
                                        'success' => 'fa-check-circle',
                                        'failed' => 'fa-exclamation-circle',
                                        default => 'fa-question-circle',
                                    };
                                @endphp
                                <span class="{{ $badgeColor }} text-xs font-semibold px-2.5 py-1 rounded-full inline-flex items-center">
                                    <i class="fa-solid {{ $icon }} mr-1"></i>
                                    {{ ucfirst($product->sync_status) }}
                                </span>
                                @if($product->error_message)
                                    <span class="text-xs text-red-600 block mt-1 max-w-xs truncate" title="{{ $product->error_message }}">
                                        {{ $product->error_message }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                {{ $product->last_sync_at ? \Carbon\Carbon::parse($product->last_sync_at)->format('M d, g:i A') : 'Never' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-gray-500">
                                <div class="flex flex-col items-center">
                                    <i class="fa-solid fa-box-open text-4xl text-gray-300 mb-3"></i>
                                    <p>No product mappings found yet.</p>
                                    <p class="text-xs text-gray-400 mt-1">Products will appear here once synchronized to Shopify.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($products->hasPages())
            <div class="px-6 py-4 border-t border-gray-200 bg-white">
                {{ $products->links() }}
            </div>
        @endif

        <!-- Test Sync Modal -->
        <div x-show="showModal" class="fixed inset-0 z-50 overflow-y-auto" style="display: none;">
            <!-- Backdrop -->
            <div class="fixed inset-0 bg-black bg-opacity-50 transition-opacity" @click="showModal = false"></div>

            <div class="flex min-h-screen items-center justify-center p-4">
                <div class="relative w-full max-w-2xl bg-white rounded-xl shadow-2xl border border-gray-100 overflow-hidden transform transition-all"
                     x-data="{ 
                        loading: false, 
                        responseSuccess: null, 
                        responseMessage: '', 
                        shopifyId: '',
                        payloadJson: `{
  &quot;product&quot;: {
    &quot;title&quot;: &quot;Hackerlily HipSurfer Hip Carrier (Sand Vegan Leather)&quot;,
    &quot;body_html&quot;: &quot;&lt;p&gt;Using all-natural, plant-based xylitol and fruit extract, a swipe of a Dr. Brown's Pacifier and Bottle Wipe cleans what your baby spits out.&lt;/p&gt;&quot;,
    &quot;vendor&quot;: &quot;Hackerlily&quot;,
    &quot;status&quot;: &quot;active&quot;,
    &quot;tags&quot;: &quot;Axanta Cloud&quot;,
    &quot;variants&quot;: [
      {
        &quot;price&quot;: &quot;575.00&quot;,
        &quot;sku&quot;: &quot;HS-SAN-LTH24&quot;,
        &quot;inventory_management&quot;: &quot;shopify&quot;,
        &quot;inventory_quantity&quot;: 1,
        &quot;barcode&quot;: &quot;&quot;,
        &quot;weight&quot;: 0.05,
        &quot;weight_unit&quot;: &quot;kg&quot;,
        &quot;requires_shipping&quot;: true,
        &quot;taxable&quot;: true,
        &quot;metafields&quot;: [
          {
            &quot;namespace&quot;: &quot;custom&quot;,
            &quot;key&quot;: &quot;arabic_name&quot;,
            &quot;type&quot;: &quot;single_line_text_field&quot;,
            &quot;value&quot;: &quot;&quot;
          },
          {
            &quot;namespace&quot;: &quot;custom&quot;,
            &quot;key&quot;: &quot;rack&quot;,
            &quot;type&quot;: &quot;single_line_text_field&quot;,
            &quot;value&quot;: &quot;456&quot;
          },
          {
            &quot;namespace&quot;: &quot;custom&quot;,
            &quot;key&quot;: &quot;bin&quot;,
            &quot;type&quot;: &quot;single_line_text_field&quot;,
            &quot;value&quot;: &quot;5&quot;
          }
        ]
      }
    ],
    &quot;metafields&quot;: [
      {
        &quot;namespace&quot;: &quot;custom&quot;,
        &quot;key&quot;: &quot;recommended_age&quot;,
        &quot;type&quot;: &quot;single_line_text_field&quot;,
        &quot;value&quot;: &quot;0-6 months&quot;
      },
      {
        &quot;namespace&quot;: &quot;custom&quot;,
        &quot;key&quot;: &quot;product_dimensions&quot;,
        &quot;type&quot;: &quot;single_line_text_field&quot;,
        &quot;value&quot;: &quot;34X65X45&quot;
      },
      {
        &quot;namespace&quot;: &quot;custom&quot;,
        &quot;key&quot;: &quot;product_weight&quot;,
        &quot;type&quot;: &quot;single_line_text_field&quot;,
        &quot;value&quot;: &quot;50g&quot;
      },
      {
        &quot;namespace&quot;: &quot;custom&quot;,
        &quot;key&quot;: &quot;package_dimensions&quot;,
        &quot;type&quot;: &quot;single_line_text_field&quot;,
        &quot;value&quot;: &quot;23X78X656&quot;
      },
      {
        &quot;namespace&quot;: &quot;custom&quot;,
        &quot;key&quot;: &quot;package_weight&quot;,
        &quot;type&quot;: &quot;single_line_text_field&quot;,
        &quot;value&quot;: &quot;100g&quot;
      },
      {
        &quot;namespace&quot;: &quot;custom&quot;,
        &quot;key&quot;: &quot;material&quot;,
        &quot;type&quot;: &quot;single_line_text_field&quot;,
        &quot;value&quot;: &quot;Cotton&quot;
      },
      {
        &quot;namespace&quot;: &quot;custom&quot;,
        &quot;key&quot;: &quot;features&quot;,
        &quot;type&quot;: &quot;multi_line_text_field&quot;,
        &quot;value&quot;: &quot;&lt;p&gt;3-in-1 Multi-Functionality: Serves as a supportive hip carrier...&lt;/p&gt;&quot;
      },
      {
        &quot;namespace&quot;: &quot;custom&quot;,
        &quot;key&quot;: &quot;overview&quot;,
        &quot;type&quot;: &quot;multi_line_text_field&quot;,
        &quot;value&quot;: &quot;&lt;p&gt;3-in-1 Multi-Functionality: Serves as a supportive hip carrier...&lt;/p&gt;&quot;
      },
      {
        &quot;namespace&quot;: &quot;custom&quot;,
        &quot;key&quot;: &quot;short_description&quot;,
        &quot;type&quot;: &quot;multi_line_text_field&quot;,
        &quot;value&quot;: &quot;The Hackerlily HipSurfer in Sand Vegan Leather is a revolutionary baby carrier.&quot;
      },
      {
        &quot;namespace&quot;: &quot;custom&quot;,
        &quot;key&quot;: &quot;faq_question_1&quot;,
        &quot;type&quot;: &quot;single_line_text_field&quot;,
        &quot;value&quot;: &quot;What is HipSurfer?&quot;
      },
      {
        &quot;namespace&quot;: &quot;custom&quot;,
        &quot;key&quot;: &quot;faq_answer_1&quot;,
        &quot;type&quot;: &quot;multi_line_text_field&quot;,
        &quot;value&quot;: &quot;3-in-1 Multi-Functionality: Serves as a supportive hip carrier.&quot;
      },
      {
        &quot;namespace&quot;: &quot;custom&quot;,
        &quot;key&quot;: &quot;faq_question_2&quot;,
        &quot;type&quot;: &quot;single_line_text_field&quot;,
        &quot;value&quot;: &quot;How much weight can it carry?&quot;
      },
      {
        &quot;namespace&quot;: &quot;custom&quot;,
        &quot;key&quot;: &quot;faq_answer_2&quot;,
        &quot;type&quot;: &quot;multi_line_text_field&quot;,
        &quot;value&quot;: &quot;Supports babies comfortably.&quot;
      },
      {
        &quot;namespace&quot;: &quot;custom&quot;,
        &quot;key&quot;: &quot;faq_question_3&quot;,
        &quot;type&quot;: &quot;single_line_text_field&quot;,
        &quot;value&quot;: &quot;Is it washable?&quot;
      },
      {
        &quot;namespace&quot;: &quot;custom&quot;,
        &quot;key&quot;: &quot;faq_answer_3&quot;,
        &quot;type&quot;: &quot;multi_line_text_field&quot;,
        &quot;value&quot;: &quot;Yes, follow care instructions.&quot;
      }
    ]
  }
}`
                      }">
                    
                    <!-- Modal Header -->
                    <div class="px-6 py-4 bg-gray-50 border-b border-gray-150 flex items-center justify-between">
                        <h3 class="text-base font-semibold text-gray-900 flex items-center gap-2">
                            <i class="fa-solid fa-flask text-blue-500"></i> Trigger Shopify Product Sync Test
                        </h3>
                        <button @click="showModal = false" class="text-gray-400 hover:text-gray-600 focus:outline-none">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>

                    <!-- Modal Body -->
                    <div class="p-6">
                        <p class="text-xs text-gray-500 mb-4">
                            You can edit this JSON payload to test the synchronization. It will trigger product/variant creation or updates in Shopify, map variant inventory, and set the 15 product metafields and 3 variant metafields.
                        </p>

                        <!-- Response Banners -->
                        <div x-show="responseSuccess === true" class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg text-green-800 text-xs flex items-start gap-2" style="display: none;">
                            <i class="fa-solid fa-circle-check text-base text-green-500 mt-0.5"></i>
                            <div>
                                <span class="font-bold">Sync Successful!</span>
                                <p class="mt-1" x-text="responseMessage"></p>
                                <p class="font-mono mt-1" x-text="'Product ID: ' + shopifyId"></p>
                            </div>
                        </div>

                        <div x-show="responseSuccess === false" class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-800 text-xs flex items-start gap-2" style="display: none;">
                            <i class="fa-solid fa-circle-xmark text-base text-red-500 mt-0.5"></i>
                            <div>
                                <span class="font-bold">Sync Failed!</span>
                                <p class="mt-1" x-text="responseMessage"></p>
                            </div>
                        </div>

                        <!-- JSON Textarea -->
                        <div class="mb-4">
                            <label class="block text-xs font-semibold text-gray-700 uppercase mb-2">JSON Request Payload</label>
                            <textarea x-model="payloadJson" rows="12" class="w-full font-mono text-xs p-3 bg-gray-900 text-gray-100 rounded-lg border border-gray-700 focus:outline-none focus:ring-1 focus:ring-blue-500"></textarea>
                        </div>
                    </div>

                    <!-- Modal Footer -->
                    <div class="px-6 py-4 bg-gray-50 border-t border-gray-150 flex justify-end gap-3">
                        <button @click="showModal = false" class="px-4 py-2 border border-gray-300 rounded-md text-xs font-semibold text-gray-700 bg-white hover:bg-gray-50 transition" :disabled="loading">
                            Close
                        </button>
                        <button 
                            @click="
                                loading = true;
                                responseSuccess = null;
                                fetch('{{ url("/api/method/middleware.apis.product.sync_product") }}', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'Accept': 'application/json'
                                    },
                                    body: payloadJson
                                })
                                .then(response => response.json().then(data => ({ status: response.status, data })))
                                .then(res => {
                                    loading = false;
                                    if (res.status === 200 && res.data.success) {
                                        responseSuccess = true;
                                        responseMessage = res.data.message;
                                        shopifyId = res.data.shopify_product_id;
                                    } else {
                                        responseSuccess = false;
                                        responseMessage = res.data.error || 'Server error occurred during sync.';
                                    }
                                })
                                .catch(err => {
                                    loading = false;
                                    responseSuccess = false;
                                    responseMessage = err.message || 'Network error.';
                                })
                            " 
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-xs font-semibold shadow-sm transition flex items-center gap-1.5" 
                            :disabled="loading"
                        >
                            <i class="fa-solid fa-spinner fa-spin" x-show="loading" style="display: none;"></i>
                            <span x-text="loading ? 'Syncing...' : 'Submit Sync'"></span>
                        </button>
                    </div>

                </div>
            </div>
        </div>

    </div>

@endsection
