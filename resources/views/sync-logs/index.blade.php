@extends('layouts.app')

@section('title', 'Sync History')
@section('page_title', 'Sync History')

@section('content')

    <!-- Data Table -->
    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <h2 class="text-lg font-medium text-gray-800">ERPNext Sync Logs</h2>
            <div class="flex flex-wrap items-center gap-3">
                <form method="GET" action="{{ url('/logs') }}" class="flex flex-wrap items-center gap-2">
                    <select name="type" onchange="this.form.submit()" class="bg-white border border-gray-300 rounded-md px-3 py-1.5 text-xs text-gray-700 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        <option value="">All Entities</option>
                        <option value="Order" {{ request('type') === 'Order' ? 'selected' : '' }}>Orders</option>
                        <option value="Customer" {{ request('type') === 'Customer' ? 'selected' : '' }}>Customers</option>
                        <option value="product" {{ request('type') === 'product' ? 'selected' : '' }}>Products</option>
                    </select>

                    <select name="status" onchange="this.form.submit()" class="bg-white border border-gray-300 rounded-md px-3 py-1.5 text-xs text-gray-700 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        <option value="">All Statuses</option>
                        <option value="success" {{ request('status') === 'success' ? 'selected' : '' }}>Success</option>
                        <option value="failed" {{ request('status') === 'failed' ? 'selected' : '' }}>Failed</option>
                    </select>
                    
                    @if(request('type') || request('status'))
                        <a href="{{ url('/logs') }}" class="text-xs text-red-600 hover:text-red-800 hover:underline ml-1">Clear Filters</a>
                    @endif
                </form>
                <span class="bg-blue-100 text-blue-800 text-xs font-semibold px-2.5 py-1.5 rounded-full">{{ $logs->total() }} Records</span>
            </div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-white">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Date</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Direction</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Entity</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Details</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($logs as $log)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-500 flex items-center">
                                    <i class="fa-regular fa-clock mr-2 text-gray-400"></i>
                                    {{ $log->created_at->format('M d, g:i A') }}
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">
                                    {{ $log->direction }}
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900">
                                    <span class="font-semibold">{{ $log->entity_type }}</span>
                                    <span class="text-gray-500 text-xs block mt-0.5">ID: {{ $log->entity_id }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                @php
                                    $badgeColor = match($log->status) {
                                        'success' => 'bg-green-100 text-green-800',
                                        'failed' => 'bg-red-100 text-red-800',
                                        default => 'bg-yellow-100 text-yellow-800',
                                    };
                                    $icon = match($log->status) {
                                        'success' => 'fa-check',
                                        'failed' => 'fa-times',
                                        default => 'fa-spinner fa-spin',
                                    };
                                @endphp
                                <span class="{{ $badgeColor }} text-xs font-semibold px-2.5 py-1 rounded-full inline-flex items-center">
                                    <i class="fa-solid {{ $icon }} mr-1"></i>
                                    {{ ucfirst($log->status) }}
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                @if($log->status === 'failed' && $log->error_message)
                                    <div class="text-xs text-red-600 bg-red-50 p-2 rounded border border-red-100 max-w-md break-words" title="{{ $log->error_message }}">
                                        {{ Str::limit($log->error_message, 80) }}
                                    </div>
                                @elseif($log->status === 'success')
                                    <span class="text-xs text-gray-500">Synced successfully.</span>
                                @else
                                    <span class="text-xs text-gray-500">-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-gray-500">
                                <div class="flex flex-col items-center">
                                    <i class="fa-solid fa-clipboard-list text-4xl text-gray-300 mb-3"></i>
                                    <p>No sync logs found yet.</p>
                                    <p class="text-xs text-gray-400 mt-1">Logs will appear here once Shopify Webhooks are processed.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        
        @if($logs->hasPages())
            <div class="px-6 py-4 border-t border-gray-200 bg-white">
                {{ $logs->links() }}
            </div>
        @endif
    </div>

@endsection
