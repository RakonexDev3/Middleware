@extends('layouts.app')

@section('title', 'Sync History')
@section('page_title', 'Sync History')

@section('content')

<style>
    [x-cloak] { display: none !important; }
</style>

<div x-data="syncLogsViewer()" x-cloak class="space-y-6">

    <!-- Notification Alert -->
    <div x-show="syncNotification" 
         x-transition 
         class="p-4 rounded-lg border text-sm flex items-start justify-between shadow-sm"
         :class="syncNotification && syncNotification.type === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-red-50 border-red-200 text-red-800'">
        <div class="flex items-center gap-2">
            <i class="fa-solid" :class="syncNotification && syncNotification.type === 'success' ? 'fa-circle-check text-emerald-600' : 'fa-circle-xmark text-red-600'"></i>
            <span x-text="syncNotification ? syncNotification.message : ''"></span>
        </div>
        <button @click="syncNotification = null" class="text-gray-400 hover:text-gray-600">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>

    <!-- Data Table Card -->
    <div class="bg-white shadow-sm rounded-xl border border-gray-200 overflow-hidden">
        <!-- Card Header & Filters -->
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-800">ERPNext Sync Logs</h2>
                <p class="text-xs text-gray-500 mt-0.5">Track payload history between Shopify and ERPNext</p>
            </div>

            <div class="flex flex-wrap items-center gap-3 w-full md:w-auto">
                <form method="GET" action="{{ url('/logs') }}" class="flex flex-wrap items-center gap-2 w-full sm:w-auto">
                    <!-- Search input -->
                    <div class="relative">
                        <input type="text" 
                               name="search" 
                               value="{{ request('search') }}" 
                               placeholder="Search ID, SKU, error..." 
                               class="bg-white border border-gray-300 rounded-md pl-8 pr-3 py-1.5 text-xs text-gray-700 focus:outline-none focus:ring-1 focus:ring-blue-500 w-44">
                        <i class="fa-solid fa-magnifying-glass absolute left-2.5 top-2.5 text-xs text-gray-400"></i>
                    </div>

                    <!-- Entity filter -->
                    <select name="type" onchange="this.form.submit()" class="bg-white border border-gray-300 rounded-md px-3 py-1.5 text-xs text-gray-700 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        <option value="">All Entities</option>
                        <option value="Order" {{ strtolower(request('type')) === 'order' ? 'selected' : '' }}>Orders</option>
                        <option value="Customer" {{ strtolower(request('type')) === 'customer' ? 'selected' : '' }}>Customers</option>
                        <option value="product" {{ strtolower(request('type')) === 'product' ? 'selected' : '' }}>Products</option>
                    </select>

                    <!-- Status filter -->
                    <select name="status" onchange="this.form.submit()" class="bg-white border border-gray-300 rounded-md px-3 py-1.5 text-xs text-gray-700 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        <option value="">All Statuses</option>
                        <option value="success" {{ request('status') === 'success' ? 'selected' : '' }}>Success</option>
                        <option value="failed" {{ request('status') === 'failed' ? 'selected' : '' }}>Failed</option>
                    </select>
                    
                    @if(request('type') || request('status') || request('search'))
                        <a href="{{ url('/logs') }}" class="text-xs text-red-600 hover:text-red-800 hover:underline px-1">Clear</a>
                    @endif
                </form>

                <div class="flex items-center gap-2 ml-auto sm:ml-0">
                    <span class="bg-blue-100 text-blue-800 text-xs font-semibold px-2.5 py-1.5 rounded-full">{{ $logs->total() }} Records</span>

                    <!-- Trigger New Order Sync Button -->
                    <button type="button" 
                            @click="syncNewOrders()" 
                            :disabled="syncingOrders"
                            class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold px-3 py-1.5 rounded-md transition flex items-center gap-1.5 shadow-sm disabled:opacity-50">
                        <i class="fa-solid fa-rotate" :class="{ 'fa-spin': syncingOrders }"></i>
                        <span x-text="syncingOrders ? 'Syncing...' : 'Sync Orders'"></span>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Table -->
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50/50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Date</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Direction</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Entity</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Summary</th>
                        <th scope="col" class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">ERP Payload</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($logs as $log)
                        @php
                            $isOrder = strtolower($log->entity_type) === 'order';
                            $payloadArr = $log->payload_array;
                            $hasPayload = !empty($log->payload);
                        @endphp
                        <tr class="hover:bg-slate-50/80 transition-colors">
                            <!-- Date -->
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-600 flex items-center" title="{{ $log->created_at->diffForHumans() }}">
                                    <i class="fa-regular fa-clock mr-2 text-gray-400"></i>
                                    {{ $log->created_at->format('M d, g:i A') }}
                                </div>
                            </td>

                            <!-- Direction -->
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-medium bg-slate-100 text-slate-700">
                                    <i class="fa-solid fa-arrow-right-arrow-left text-[10px] text-slate-400"></i>
                                    {{ $log->direction }}
                                </span>
                            </td>

                            <!-- Entity -->
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900">
                                    <div class="flex items-center gap-1.5 font-semibold text-gray-800">
                                        @if($isOrder)
                                            <i class="fa-solid fa-shopping-bag text-blue-500 text-xs"></i>
                                        @elseif(strtolower($log->entity_type) === 'customer')
                                            <i class="fa-solid fa-user text-emerald-500 text-xs"></i>
                                        @else
                                            <i class="fa-solid fa-box text-purple-500 text-xs"></i>
                                        @endif
                                        <span>{{ ucfirst($log->entity_type) }}</span>
                                    </div>
                                    <span class="font-mono text-gray-500 text-xs block mt-0.5" title="Entity ID">
                                        ID: {{ $log->entity_id }}
                                    </span>
                                </div>
                            </td>

                            <!-- Status -->
                            <td class="px-6 py-4 whitespace-nowrap">
                                @php
                                    $badgeColor = match($log->status) {
                                        'success' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
                                        'failed' => 'bg-rose-100 text-rose-800 border-rose-200',
                                        default => 'bg-amber-100 text-amber-800 border-amber-200',
                                    };
                                    $icon = match($log->status) {
                                        'success' => 'fa-check',
                                        'failed' => 'fa-xmark',
                                        default => 'fa-spinner fa-spin',
                                    };
                                @endphp
                                <span class="{{ $badgeColor }} border text-xs font-semibold px-2.5 py-1 rounded-full inline-flex items-center">
                                    <i class="fa-solid {{ $icon }} mr-1 text-[10px]"></i>
                                    {{ ucfirst($log->status) }}
                                </span>
                            </td>

                            <!-- Details / Summary -->
                            <td class="px-6 py-4">
                                @if($log->status === 'failed' && $log->error_message)
                                    <div class="text-xs text-rose-700 bg-rose-50 p-2 rounded-md border border-rose-100 max-w-sm break-words" title="{{ $log->error_message }}">
                                        <span class="font-semibold block mb-0.5">Error:</span>
                                        {{ Str::limit($log->error_message, 85) }}
                                    </div>
                                @elseif($isOrder && $payloadArr)
                                    @php
                                        $custName = $payloadArr['customer']['customer_name'] 
                                            ?? (is_string($payloadArr['customer'] ?? null) ? $payloadArr['customer'] : null);
                                        $itemsCount = count($payloadArr['items'] ?? []);
                                        $poNo = $payloadArr['order']['order_number'] ?? ($payloadArr['po_no'] ?? null);
                                    @endphp
                                    <div class="text-xs text-gray-600 space-y-0.5">
                                        <span class="text-emerald-700 font-medium flex items-center gap-1">
                                            <i class="fa-solid fa-circle-check text-[11px]"></i>
                                            Synced to ERPNext
                                            @if($poNo) <span class="font-mono text-gray-700">({{ $poNo }})</span> @endif
                                        </span>
                                        <div class="text-gray-500 text-[11px] flex items-center gap-2">
                                            @if($custName)
                                                <span>Customer: <strong class="text-gray-700">{{ Str::limit($custName, 22) }}</strong></span>
                                            @endif
                                            @if($itemsCount)
                                                <span>• {{ $itemsCount }} {{ Str::plural('item', $itemsCount) }}</span>
                                            @endif
                                        </div>
                                    </div>
                                @elseif($log->status === 'success')
                                    <span class="text-xs text-gray-600 flex items-center gap-1">
                                        <i class="fa-solid fa-check text-emerald-500"></i> Synced successfully
                                    </span>
                                @else
                                    <span class="text-xs text-gray-400">-</span>
                                @endif
                            </td>

                            <!-- ERP Payload Action Button -->
                            <td class="px-6 py-4 whitespace-nowrap text-right">
                                @if($hasPayload)
                                    <button type="button" 
                                            @click="openPayload({{ $log->id }})" 
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold shadow-sm transition
                                                   {{ $isOrder 
                                                        ? 'bg-blue-50 text-blue-700 hover:bg-blue-100 border border-blue-200 hover:border-blue-300' 
                                                        : 'bg-slate-100 text-slate-700 hover:bg-slate-200 border border-slate-200' }}">
                                        <i class="fa-solid fa-code {{ $isOrder ? 'text-blue-600' : 'text-slate-500' }}"></i>
                                        <span>{{ $isOrder ? 'View ERP Payload' : 'View Payload' }}</span>
                                    </button>

                                    <!-- Hidden JSON Payload Storage for Instant Reactive Access -->
                                    <script type="application/json" id="log-payload-{{ $log->id }}">
                                    {!! json_encode([
                                        'id' => $log->id,
                                        'entity_type' => $log->entity_type,
                                        'entity_id' => $log->entity_id,
                                        'direction' => $log->direction,
                                        'status' => $log->status,
                                        'created_at' => $log->created_at->format('M d, Y h:i:s A'),
                                        'error_message' => $log->error_message,
                                        'payload' => $payloadArr ?? $log->payload,
                                        'raw_json' => $log->formatted_payload,
                                        'url' => route('logs.payload', $log->id),
                                    ]) !!}
                                    </script>
                                @else
                                    <span class="text-xs text-gray-400 italic">No payload</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                                <div class="flex flex-col items-center">
                                    <i class="fa-solid fa-clipboard-list text-4xl text-gray-300 mb-3"></i>
                                    <p class="font-medium text-gray-700">No sync logs found.</p>
                                    <p class="text-xs text-gray-400 mt-1">Logs will appear here once Shopify orders or webhooks are processed.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        @if($logs->hasPages())
            <div class="px-6 py-4 border-t border-gray-200 bg-white">
                {{ $logs->links() }}
            </div>
        @endif
    </div>

    <!-- ========================================================================= -->
    <!-- ERPNext Payload Viewer Modal                                             -->
    <!-- ========================================================================= -->
    <div x-show="showModal" 
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @keydown.escape.window="closeModal()"
         class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 sm:p-6"
         style="display: none;">

        <div @click.away="closeModal()" 
             x-show="showModal"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95"
             class="bg-white rounded-xl shadow-2xl border border-gray-200 w-full max-w-4xl flex flex-col max-h-[90vh] overflow-hidden">

            <!-- Modal Header -->
            <div class="px-6 py-4 border-b border-gray-200 bg-slate-50 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-blue-100 text-blue-600 flex items-center justify-center text-lg">
                        <i class="fa-solid fa-code"></i>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-base font-bold text-gray-900" 
                                x-text="selectedLog && selectedLog.entity_type === 'Order' ? 'ERPNext Sales Order Payload' : 'ERPNext Sync Payload'"></h3>
                            <span class="text-xs font-semibold px-2 py-0.5 rounded-full"
                                  :class="selectedLog && selectedLog.status === 'success' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'"
                                  x-text="selectedLog ? selectedLog.status.toUpperCase() : ''"></span>
                        </div>
                        <p class="text-xs text-gray-500 mt-0.5 flex items-center gap-2">
                            <span>Entity: <strong class="text-gray-700" x-text="selectedLog ? (selectedLog.entity_type + ' #' + selectedLog.entity_id) : ''"></strong></span>
                            <span>•</span>
                            <span x-text="selectedLog ? selectedLog.direction : ''"></span>
                            <span>•</span>
                            <span x-text="selectedLog ? selectedLog.created_at : ''"></span>
                        </p>
                    </div>
                </div>

                <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 p-2 rounded-lg hover:bg-gray-200/50 transition">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>

            <!-- Modal Body (Scrollable) -->
            <div class="p-6 overflow-y-auto space-y-5 flex-1 bg-white">

                <!-- Error Message Banner (if sync failed) -->
                <div x-show="selectedLog && selectedLog.status === 'failed' && selectedLog.error_message" 
                     class="bg-rose-50 border border-rose-200 text-rose-800 p-3.5 rounded-lg text-xs flex items-start gap-2.5">
                    <i class="fa-solid fa-triangle-exclamation text-rose-600 mt-0.5 text-sm flex-shrink-0"></i>
                    <div>
                        <span class="font-bold text-rose-900">ERPNext Sync Failure Reason:</span>
                        <p class="mt-0.5 break-words font-mono" x-text="selectedLog ? selectedLog.error_message : ''"></p>
                    </div>
                </div>

                <!-- Order Payload Quick Stats Strip (if Order entity and structured payload exists) -->
                <div x-show="selectedLog && selectedLog.entity_type === 'Order' && selectedLog.payload && typeof selectedLog.payload === 'object'" 
                     class="grid grid-cols-2 sm:grid-cols-4 gap-3 bg-slate-50 p-3 rounded-lg border border-slate-200">
                    <div class="p-2">
                        <span class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider block">ERP Customer</span>
                        <p class="text-xs font-bold text-slate-800 truncate mt-0.5" 
                           x-text="(selectedLog?.payload?.customer?.customer_name || selectedLog?.payload?.customer_id || selectedLog?.payload?.customer || 'N/A')"></p>
                        <span class="text-[10px] text-slate-500 font-mono block truncate"
                              x-text="(selectedLog?.payload?.customer?.customer_id ? 'ID: ' + selectedLog.payload.customer.customer_id : '')"></span>
                    </div>

                    <div class="p-2">
                        <span class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider block">Warehouse</span>
                        <p class="text-xs font-bold text-slate-800 truncate mt-0.5" 
                           x-text="(selectedLog?.payload?.set_warehouse || selectedLog?.payload?.warehouse || 'WH-1')"></p>
                        <span class="text-[10px] text-slate-500 block"
                              x-text="'Delivery: ' + (selectedLog?.payload?.delivery_date || selectedLog?.payload?.order?.delivery_date || 'Standard')"></span>
                    </div>

                    <div class="p-2">
                        <span class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider block">Items Count</span>
                        <p class="text-xs font-bold text-slate-800 mt-0.5" 
                           x-text="(selectedLog?.payload?.items ? selectedLog.payload.items.length : (selectedLog?.payload?.line_items ? selectedLog.payload.line_items.length : 0)) + ' Items'"></p>
                        <span class="text-[10px] text-slate-500 block truncate"
                              x-text="'PO: ' + (selectedLog?.payload?.po_no || selectedLog?.payload?.order?.po_no || '#' + selectedLog?.entity_id)"></span>
                    </div>

                    <div class="p-2">
                        <span class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider block">Grand Total</span>
                        <p class="text-xs font-bold text-blue-700 mt-0.5" 
                           x-text="(selectedLog?.payload?.payment?.currency || 'QAR') + ' ' + (selectedLog?.payload?.payment?.grand_total || '0.00')"></p>
                        <span class="text-[10px] text-slate-500 uppercase font-semibold block"
                              x-text="(selectedLog?.payload?.payment?.payment_status || 'Pending')"></span>
                    </div>
                </div>

                <!-- Tab Navigation Buttons -->
                <div class="flex items-center justify-between border-b border-gray-200 pb-2">
                    <div class="flex items-center gap-4">
                        <button type="button" 
                                @click="activeTab = 'json'"
                                class="text-xs font-semibold pb-2 border-b-2 transition flex items-center gap-1.5"
                                :class="activeTab === 'json' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'">
                            <i class="fa-solid fa-file-code"></i> Raw ERP JSON Payload
                        </button>

                        <button type="button" 
                                x-show="selectedLog && selectedLog.entity_type === 'Order' && selectedLog.payload && typeof selectedLog.payload === 'object' && selectedLog.payload.items"
                                @click="activeTab = 'details'"
                                class="text-xs font-semibold pb-2 border-b-2 transition flex items-center gap-1.5"
                                :class="activeTab === 'details' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'">
                            <i class="fa-solid fa-list-check"></i> Structured Breakdown
                        </button>
                    </div>

                    <!-- Toolbar actions for JSON Tab -->
                    <div class="flex items-center gap-2" x-show="activeTab === 'json'">
                        <button type="button" 
                                @click="wrapLines = !wrapLines" 
                                class="text-xs text-gray-600 hover:text-gray-900 border border-gray-300 rounded px-2.5 py-1 bg-white hover:bg-gray-50 transition flex items-center gap-1">
                            <i class="fa-solid fa-align-left text-[10px]"></i>
                            <span x-text="wrapLines ? 'No Wrap' : 'Wrap Lines'"></span>
                        </button>

                        <button type="button" 
                                @click="copyJson()" 
                                class="text-xs font-medium border rounded px-2.5 py-1 transition flex items-center gap-1.5"
                                :class="copied ? 'bg-emerald-50 border-emerald-300 text-emerald-700' : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50'">
                            <i class="fa-solid" :class="copied ? 'fa-check text-emerald-600' : 'fa-copy'"></i>
                            <span x-text="copied ? 'Copied!' : 'Copy JSON'"></span>
                        </button>

                        <a :href="selectedLog ? selectedLog.url : '#'" 
                           target="_blank" 
                           class="text-xs text-blue-600 hover:text-blue-800 border border-blue-200 bg-blue-50/50 hover:bg-blue-100/50 rounded px-2.5 py-1 transition flex items-center gap-1">
                            <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                            <span>New Tab</span>
                        </a>
                    </div>
                </div>

                <!-- Tab 1: Raw ERP JSON Content -->
                <div x-show="activeTab === 'json'">
                    <div class="relative rounded-lg overflow-hidden border border-slate-700 shadow-inner bg-slate-900">
                        <div class="bg-slate-800/90 px-4 py-2 flex items-center justify-between text-[11px] text-slate-400 border-b border-slate-700">
                            <div class="flex items-center gap-2">
                                <span class="w-2.5 h-2.5 rounded-full bg-rose-500"></span>
                                <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
                                <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                                <span class="ml-2 font-mono">erpnext_sales_order_payload.json</span>
                            </div>
                            <span class="text-[10px]">UTF-8 • Application/JSON</span>
                        </div>
                        <pre class="p-4 font-mono text-xs text-emerald-400 overflow-auto max-h-[50vh] leading-relaxed select-all"
                             :class="{ 'whitespace-pre-wrap break-words': wrapLines, 'whitespace-pre': !wrapLines }"><code x-text="selectedLog ? selectedLog.raw_json : ''"></code></pre>
                    </div>
                </div>

                <!-- Tab 2: Structured Details (Order Items & Addresses) -->
                <div x-show="activeTab === 'details'" class="space-y-4">
                    <!-- Line Items Table -->
                    <div class="border border-gray-200 rounded-lg overflow-hidden">
                        <div class="bg-gray-50 px-4 py-2.5 border-b border-gray-200 flex items-center justify-between">
                            <span class="text-xs font-semibold text-gray-700 uppercase tracking-wider">ERP Sales Order Items</span>
                            <span class="text-xs text-gray-500" x-text="(selectedLog?.payload?.items?.length || 0) + ' items in ERP payload'"></span>
                        </div>
                        <table class="min-w-full divide-y divide-gray-200 text-xs">
                            <thead class="bg-white text-gray-500 uppercase tracking-wider font-semibold text-[11px]">
                                <tr>
                                    <th class="px-4 py-2.5 text-left">SKU / Item Code</th>
                                    <th class="px-4 py-2.5 text-left">Item Name</th>
                                    <th class="px-4 py-2.5 text-center">Qty</th>
                                    <th class="px-4 py-2.5 text-right">Rate</th>
                                    <th class="px-4 py-2.5 text-right">Amount</th>
                                    <th class="px-4 py-2.5 text-left">Warehouse</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-150 bg-white">
                                <template x-for="(item, idx) in (selectedLog?.payload?.items || [])" :key="idx">
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-4 py-2.5 font-mono font-bold text-blue-700" x-text="item.item_code || item.sku || 'UNKNOWN'"></td>
                                        <td class="px-4 py-2.5 text-gray-800" x-text="item.item_name || item.title || item.item_code"></td>
                                        <td class="px-4 py-2.5 text-center font-semibold text-gray-700" x-text="item.quantity || item.qty || 1"></td>
                                        <td class="px-4 py-2.5 text-right text-gray-600 font-mono" x-text="Number(item.unit_price || item.rate || 0).toFixed(2)"></td>
                                        <td class="px-4 py-2.5 text-right font-semibold text-gray-800 font-mono" 
                                            x-text="Number(item.amount || ((item.quantity || item.qty || 1) * (item.unit_price || item.rate || 0))).toFixed(2)"></td>
                                        <td class="px-4 py-2.5 text-gray-600 truncate max-w-[150px]" 
                                            x-text="(typeof item.warehouse === 'object' ? item.warehouse?.warehouse_name || item.warehouse?.warehouse_id : item.warehouse) || selectedLog?.payload?.set_warehouse || 'WH-1'"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <!-- Customer & Delivery Address Card -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- Customer Card -->
                        <div class="border border-gray-200 rounded-lg p-3 bg-gray-50/50">
                            <span class="text-xs font-semibold text-gray-700 uppercase tracking-wider block mb-2">Customer Information</span>
                            <div class="text-xs space-y-1 text-gray-600">
                                <p><strong class="text-gray-800">Name:</strong> <span x-text="selectedLog?.payload?.customer?.customer_name || selectedLog?.payload?.customer || 'N/A'"></span></p>
                                <p><strong class="text-gray-800">ERP Customer ID:</strong> <span class="font-mono text-blue-600" x-text="selectedLog?.payload?.customer?.customer_id || selectedLog?.payload?.customer_id || 'Auto-created'"></span></p>
                                <p><strong class="text-gray-800">Email:</strong> <span x-text="selectedLog?.payload?.customer?.contact_email || 'N/A'"></span></p>
                                <p><strong class="text-gray-800">Phone:</strong> <span x-text="selectedLog?.payload?.customer?.contact_phone || 'N/A'"></span></p>
                            </div>
                        </div>

                        <!-- Delivery Address Card -->
                        <div class="border border-gray-200 rounded-lg p-3 bg-gray-50/50">
                            <span class="text-xs font-semibold text-gray-700 uppercase tracking-wider block mb-2">Delivery Details</span>
                            <div class="text-xs space-y-1 text-gray-600">
                                <p><strong class="text-gray-800">Address:</strong> <span x-text="selectedLog?.payload?.delivery_address?.address_line1 || 'N/A'"></span></p>
                                <p><strong class="text-gray-800">City / Zone:</strong> <span x-text="(selectedLog?.payload?.delivery_address?.city || 'Doha') + ' / ' + (selectedLog?.payload?.delivery_address?.zone || 'N/A')"></span></p>
                                <p><strong class="text-gray-800">Country:</strong> <span x-text="selectedLog?.payload?.delivery_address?.country || 'Qatar'"></span></p>
                                <p><strong class="text-gray-800">Delivery Date:</strong> <span class="font-semibold text-emerald-700" x-text="selectedLog?.payload?.delivery_date || selectedLog?.payload?.order?.delivery_date || 'N/A'"></span></p>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Modal Footer -->
            <div class="px-6 py-3.5 bg-gray-50 border-t border-gray-200 flex items-center justify-between">
                <span class="text-xs text-gray-500 flex items-center gap-1.5">
                    <i class="fa-solid fa-database text-gray-400"></i>
                    Sync Log Record #<span x-text="selectedLog ? selectedLog.id : ''"></span>
                </span>

                <div class="flex items-center gap-2">
                    <button type="button" 
                            @click="copyJson()" 
                            class="px-3.5 py-2 rounded-md text-xs font-semibold bg-white border border-gray-300 text-gray-700 hover:bg-gray-100 transition flex items-center gap-1.5">
                        <i class="fa-solid" :class="copied ? 'fa-check text-emerald-600' : 'fa-copy'"></i>
                        <span x-text="copied ? 'Copied to Clipboard' : 'Copy Full Payload'"></span>
                    </button>

                    <button type="button" 
                            @click="closeModal()" 
                            class="px-4 py-2 rounded-md text-xs font-semibold bg-slate-800 text-white hover:bg-slate-900 transition shadow-sm">
                        Close
                    </button>
                </div>
            </div>

        </div>
    </div>

</div>

<script>
function syncLogsViewer() {
    return {
        showModal: false,
        selectedLog: null,
        activeTab: 'json',
        copied: false,
        wrapLines: false,
        syncingOrders: false,
        syncNotification: null,

        openPayload(id) {
            const el = document.getElementById('log-payload-' + id);
            if (!el) return;
            try {
                const data = JSON.parse(el.textContent);
                this.selectedLog = data;
                this.activeTab = 'json';
                this.copied = false;
                this.showModal = true;
            } catch (e) {
                console.error('Failed to parse log payload', e);
            }
        },

        closeModal() {
            this.showModal = false;
            this.selectedLog = null;
        },

        copyJson() {
            if (!this.selectedLog) return;
            const textToCopy = this.selectedLog.raw_json || JSON.stringify(this.selectedLog.payload, null, 2);
            navigator.clipboard.writeText(textToCopy).then(() => {
                this.copied = true;
                setTimeout(() => { this.copied = false; }, 2000);
            }).catch(err => {
                console.error('Copy failed:', err);
            });
        },

        syncNewOrders() {
            this.syncingOrders = true;
            this.syncNotification = null;

            fetch('{{ url("/method/middleware.apis.sales_order.create_sales_order") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({})
            })
            .then(res => res.json())
            .then(data => {
                this.syncingOrders = false;
                if (data.success) {
                    const count = data.synced_count ?? 0;
                    this.syncNotification = { 
                        type: 'success', 
                        message: `Order synchronization completed! ${count} new order(s) processed. Refreshing...` 
                    };
                    setTimeout(() => { window.location.reload(); }, 1500);
                } else {
                    this.syncNotification = { 
                        type: 'error', 
                        message: data.error || data.message || 'Order sync failed.' 
                    };
                }
            })
            .catch(err => {
                this.syncingOrders = false;
                this.syncNotification = { 
                    type: 'error', 
                    message: err.message || 'Network error triggering order sync.' 
                };
            });
        }
    };
}
</script>

@endsection
