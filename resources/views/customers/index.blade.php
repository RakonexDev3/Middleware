@extends('layouts.app')

@section('title', 'Customers')
@section('page_title', 'Customers')

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
            <h2 class="text-lg font-medium text-gray-800">Shopify Customers</h2>
            <span class="bg-blue-100 text-blue-800 text-xs font-semibold px-2.5 py-0.5 rounded-full">{{ count($customers) }} Records</span>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-white">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Name</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Email</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">ERPNext ID</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Sync Status</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Orders</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Total Spent</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Joined</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($customers as $customer)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0 h-8 w-8 rounded-full bg-slate-200 flex items-center justify-center text-slate-600 font-bold text-xs">
                                        {{ substr($customer['firstName'] ?? '', 0, 1) }}{{ substr($customer['lastName'] ?? '', 0, 1) }}
                                    </div>
                                    <div class="ml-3">
                                        <div class="text-sm font-medium text-gray-900">
                                            {{ $customer['firstName'] ?? '' }} {{ $customer['lastName'] ?? '' }}
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-600 flex items-center">
                                    <i class="fa-regular fa-envelope mr-2 text-gray-400"></i>
                                    {{ $customer['email'] ?? 'N/A' }}
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                @if($customer['erp_customer_id'])
                                    <span class="text-sm font-semibold text-slate-700">{{ $customer['erp_customer_id'] }}</span>
                                @else
                                    <span class="text-sm text-gray-400 italic">Not Mapped</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                @php
                                    $status = $customer['sync_status'] ?? 'not_synced';
                                    $badgeClass = match($status) {
                                        'success' => 'bg-green-100 text-green-800',
                                        'failed' => 'bg-red-100 text-red-800',
                                        default => 'bg-slate-100 text-slate-600',
                                    };
                                    $iconClass = match($status) {
                                        'success' => 'fa-circle-check text-green-500',
                                        'failed' => 'fa-circle-xmark text-red-500',
                                        default => 'fa-circle-minus text-slate-400',
                                    };
                                    $label = match($status) {
                                        'success' => 'Synced',
                                        'failed' => 'Failed',
                                        default => 'Not Synced',
                                    };
                                @endphp
                                <span class="{{ $badgeClass }} text-xs font-semibold px-2.5 py-1 rounded-full inline-flex items-center cursor-help" 
                                      @if($customer['sync_error']) title="{{ $customer['sync_error'] }}" @endif>
                                    <i class="fa-solid {{ $iconClass }} mr-1"></i>
                                    {{ $label }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900 font-medium">{{ $customer['numberOfOrders'] ?? 0 }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900 font-medium">
                                    @if(isset($customer['amountSpent']) && isset($customer['amountSpent']['amount']))
                                        <span class="text-green-600">{{ $customer['amountSpent']['currencyCode'] ?? '$' }} {{ $customer['amountSpent']['amount'] }}</span>
                                    @else
                                        $ 0.00
                                    @endif
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                @if(isset($customer['createdAt']))
                                    <i class="fa-regular fa-calendar mr-1 text-gray-400"></i>
                                    {{ \Carbon\Carbon::parse($customer['createdAt'])->format('M d, Y') }}
                                @else
                                    N/A
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                <div class="flex flex-col items-center">
                                    <i class="fa-regular fa-folder-open text-4xl text-gray-300 mb-3"></i>
                                    <p>No customers found.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

@endsection
