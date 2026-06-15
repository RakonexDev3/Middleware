@extends('layouts.app')

@section('title', 'Orders')
@section('page_title', 'Orders')

@section('content')

    @if(isset($error) && $error)
        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-6 shadow-sm rounded-r-md flex items-start">
            <div class="flex-shrink-0 pt-0.5">
                <i class="fa-solid fa-triangle-exclamation text-yellow-400"></i>
            </div>
            <div class="ml-3">
                <p class="text-sm text-yellow-700 font-medium">
                    {{ $error }}
                </p>
            </div>
        </div>
    @endif

    <!-- Data Table -->
    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex justify-between items-center">
            <h2 class="text-lg font-medium text-gray-800">Recent Shopify Orders</h2>
            <span class="bg-blue-100 text-blue-800 text-xs font-semibold px-2.5 py-0.5 rounded-full">{{ count($orders) }} Records</span>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-white">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Order</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Date</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Customer</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Total</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Payment Status</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Fulfillment</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($orders as $order)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="text-sm font-bold text-blue-600">{{ $order['name'] ?? 'N/A' }}</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-500 flex items-center">
                                    <i class="fa-regular fa-calendar mr-2 text-gray-400"></i>
                                    {{ isset($order['createdAt']) ? \Carbon\Carbon::parse($order['createdAt'])->format('M d, g:i A') : 'N/A' }}
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900 font-medium">
                                    @if(isset($order['customer']))
                                        {{ $order['customer']['firstName'] ?? '' }} {{ $order['customer']['lastName'] ?? '' }}
                                    @else
                                        <span class="text-gray-400 italic">Guest / Unknown</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900 font-bold">
                                    @if(isset($order['totalPriceSet']) && isset($order['totalPriceSet']['shopMoney']))
                                        {{ $order['totalPriceSet']['shopMoney']['currencyCode'] ?? '$' }} {{ $order['totalPriceSet']['shopMoney']['amount'] ?? '0.00' }}
                                    @else
                                        $0.00
                                    @endif
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                @php
                                    $paymentStatus = $order['displayFinancialStatus'] ?? 'UNKNOWN';
                                    $badgeColor = match($paymentStatus) {
                                        'PAID' => 'bg-green-100 text-green-800',
                                        'PENDING' => 'bg-yellow-100 text-yellow-800',
                                        'REFUNDED', 'VOIDED' => 'bg-red-100 text-red-800',
                                        default => 'bg-gray-100 text-gray-800',
                                    };
                                @endphp
                                <span class="{{ $badgeColor }} text-xs font-semibold px-2.5 py-0.5 rounded-full">
                                    {{ str_replace('_', ' ', $paymentStatus) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                @php
                                    $fulfillStatus = $order['displayFulfillmentStatus'] ?? 'UNFULFILLED';
                                    $badgeColorF = match($fulfillStatus) {
                                        'FULFILLED' => 'bg-green-100 text-green-800',
                                        'PARTIALLY_FULFILLED' => 'bg-blue-100 text-blue-800',
                                        default => 'bg-yellow-100 text-yellow-800',
                                    };
                                @endphp
                                <span class="{{ $badgeColorF }} text-xs font-semibold px-2.5 py-0.5 rounded-full">
                                    {{ str_replace('_', ' ', $fulfillStatus) }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                                <div class="flex flex-col items-center">
                                    <i class="fa-solid fa-box-open text-4xl text-gray-300 mb-3"></i>
                                    <p>No orders found.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

@endsection
