<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Admin Dashboard') - Shopify Middleware</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Alpine.js for dropdowns and interactions -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <!-- FontAwesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50 font-sans text-gray-900 antialiased h-screen flex overflow-hidden">

    <!-- Sidebar -->
    <aside class="w-64 bg-slate-800 text-white flex flex-col transition-all duration-300 z-20 shadow-xl hidden md:flex flex-col">
        <!-- Sidebar Header -->
        <div class="h-16 flex items-center px-6 bg-slate-900 border-b border-slate-700">
            <i class="fa-solid fa-server text-blue-400 text-xl mr-3"></i>
            <span class="text-xl font-bold tracking-wider">Middleware</span>
        </div>

        <!-- Sidebar Navigation -->
        <nav class="flex-1 px-4 py-6 space-y-2 overflow-y-auto">
            
            <p class="px-2 text-xs font-semibold text-slate-400 uppercase tracking-wider mb-4 mt-2">Shopify Data</p>

            <a href="{{ route('customers.index') }}" 
               class="flex items-center px-4 py-3 text-sm font-medium rounded-lg transition-colors {{ request()->routeIs('customers.*') ? 'bg-blue-600 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                <i class="fa-solid fa-users w-5 text-center mr-3"></i>
                Customers
            </a>

            <a href="{{ url('/orders') }}" 
               class="flex items-center px-4 py-3 text-sm font-medium rounded-lg transition-colors {{ request()->is('orders') ? 'bg-blue-600 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                <i class="fa-solid fa-shopping-cart w-5 text-center mr-3"></i>
                Orders
            </a>

            <a href="{{ route('products.index') }}" 
               class="flex items-center px-4 py-3 text-sm font-medium rounded-lg transition-colors {{ request()->routeIs('products.*') ? 'bg-blue-600 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                <i class="fa-solid fa-box w-5 text-center mr-3"></i>
                Products
            </a>

            <p class="px-2 text-xs font-semibold text-slate-400 uppercase tracking-wider mb-4 mt-6">System</p>

            <a href="{{ url('/logs') }}" 
               class="flex items-center px-4 py-3 text-sm font-medium rounded-lg transition-colors {{ request()->is('logs') ? 'bg-blue-600 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                <i class="fa-solid fa-clock-rotate-left w-5 text-center mr-3"></i>
                Sync History
            </a>

        </nav>

        <!-- Sidebar Footer -->
        <div class="p-4 bg-slate-900 border-t border-slate-700">
            <div class="flex items-center">
                <div class="w-8 h-8 rounded-full bg-blue-500 flex items-center justify-center text-white font-bold">
                    {{ substr(Auth::user()->name ?? 'U', 0, 1) }}
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium">{{ Auth::user()->name ?? 'User' }}</p>
                    <p class="text-xs text-slate-400 truncate w-40">{{ Auth::user()->email ?? '' }}</p>
                </div>
            </div>
        </div>
    </aside>

    <!-- Main Content Wrapper -->
    <div class="flex-1 flex flex-col overflow-hidden relative">
        
        <!-- Header -->
        <header class="h-16 bg-white border-b border-gray-200 shadow-sm flex items-center justify-between px-6 z-10">
            
            <!-- Left side Header (Mobile toggle & Title) -->
            <div class="flex items-center">
                <!-- Mobile menu button (placeholder for responsive) -->
                <button class="md:hidden text-gray-500 hover:text-gray-700 focus:outline-none">
                    <i class="fa-solid fa-bars text-xl"></i>
                </button>
                <h1 class="text-xl font-semibold text-gray-800 ml-2 md:ml-0">@yield('page_title', 'Dashboard')</h1>
            </div>

            <!-- Right side Header (User Dropdown) -->
            <div class="flex items-center">
                
                <div class="relative" x-data="{ open: false }">
                    <button @click="open = !open" @click.away="open = false" class="flex items-center focus:outline-none space-x-2 p-2 rounded-md hover:bg-gray-100 transition">
                        <span class="text-sm font-medium text-gray-700 hidden md:block">{{ Auth::user()->name ?? 'User' }}</span>
                        <i class="fa-solid fa-chevron-down text-xs text-gray-400"></i>
                    </button>

                    <!-- Dropdown Menu -->
                    <div x-show="open" 
                         x-transition:enter="transition ease-out duration-100"
                         x-transition:enter-start="transform opacity-0 scale-95"
                         x-transition:enter-end="transform opacity-100 scale-100"
                         x-transition:leave="transition ease-in duration-75"
                         x-transition:leave-start="transform opacity-100 scale-100"
                         x-transition:leave-end="transform opacity-0 scale-95"
                         class="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg border border-gray-100 py-1 z-50" style="display: none;">
                        
                        <div class="px-4 py-2 border-b border-gray-100 md:hidden">
                            <p class="text-sm font-medium text-gray-900">{{ Auth::user()->name ?? 'User' }}</p>
                            <p class="text-xs text-gray-500 truncate">{{ Auth::user()->email ?? '' }}</p>
                        </div>
                        
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="block w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-50 transition">
                                <i class="fa-solid fa-sign-out-alt mr-2"></i> Logout
                            </button>
                        </form>
                    </div>
                </div>

            </div>
        </header>

        <!-- Main Content Area -->
        <main class="flex-1 overflow-y-auto bg-slate-50 p-6">
            <div class="max-w-7xl mx-auto">
                @yield('content')
            </div>
        </main>
    </div>

</body>
</html>
