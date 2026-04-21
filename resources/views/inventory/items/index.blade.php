<x-app-layout>
    @php
        $viewDefault = request('view', 'items');
    @endphp

    <x-page-header class="inventory-items-header" title="Stock / Items" subtitle="Manage jewellery items ready for sale">
        <x-slot:actions>
                <a href="{{ route('inventory.items.create') }}"
                    class="btn btn-success btn-sm inventory-items-create-btn">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                </svg>
                Create Item
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner inventory-items-page jf-skeleton-host is-loading" x-data="{ view: '{{ $viewDefault }}' }">

        {{-- View Toggle for Retailers --}}
        @if($isRetailer)
        <div class="mb-6">
            <div class="ui-toggle-strip items-view-toggle inline-flex flex-wrap items-center gap-1 rounded-2xl border border-slate-200 bg-white p-1 shadow-sm">
            <button @click="view = 'items'" :class="view === 'items' ? 'bg-slate-900 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50'" class="items-view-tab inline-flex items-center gap-1.5 rounded-xl px-4 py-2 text-sm font-semibold transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                Stock Items
            </button>
            <button @click="view = 'aging'" :class="view === 'aging' ? 'bg-slate-900 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50'" class="items-view-tab inline-flex items-center gap-1.5 rounded-xl px-4 py-2 text-sm font-semibold transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Stock Aging
            </button>
            <button @click="view = 'sellers'" :class="view === 'sellers' ? 'bg-slate-900 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50'" class="items-view-tab inline-flex items-center gap-1.5 rounded-xl px-4 py-2 text-sm font-semibold transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                Sell Trend
            </button>
            </div>
        </div>
        @endif

        {{-- ==================== STOCK ITEMS VIEW ==================== --}}
        <div x-show="view === 'items'" x-cloak>
        <x-app-alerts class="mb-6" />

        <!-- Stats Cards -->
        @php
            $totalItems = (int) ($stats['total'] ?? 0);
            $inStockItems = (int) ($stats['in_stock'] ?? 0);
            $soldItems = (int) ($stats['sold'] ?? 0);
        @endphp
        <div class="ui-stats-grid items-kpi-grid items-kpi-grid--charcoal mb-6">
            <section class="items-kpi-card items-kpi-charcoal items-kpi-card-total" aria-label="Total items KPI">
                <div class="items-kpi-head">
                    <span class="items-kpi-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
                    </span>
                    <p class="items-kpi-meta">{{ $totalItems > 0 ? 'Catalog live' : 'Add first SKU' }}</p>
                </div>
                <p class="items-kpi-value jf-skel jf-skel-value">{{ number_format($totalItems) }}</p>
                <div class="items-kpi-foot">
                    <p class="items-kpi-title">Total Items</p>
                    <p class="items-kpi-note">All SKUs</p>
                </div>
            </section>

            <section class="items-kpi-card items-kpi-charcoal items-kpi-card-stock" aria-label="In stock KPI">
                <div class="items-kpi-head">
                    <span class="items-kpi-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m8 12 2.6 2.6L16 9.2"/></svg>
                    </span>
                    <p class="items-kpi-meta">{{ $inStockItems > 0 ? 'Ready stock' : 'Out of stock' }}</p>
                </div>
                <p class="items-kpi-value jf-skel jf-skel-value">{{ number_format($inStockItems) }}</p>
                <div class="items-kpi-foot">
                    <p class="items-kpi-title">In Stock</p>
                    <p class="items-kpi-note">Available now</p>
                </div>
            </section>

            <section class="items-kpi-card items-kpi-charcoal items-kpi-card-sold" aria-label="Sold items KPI">
                <div class="items-kpi-head">
                    <span class="items-kpi-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h18"/><path d="M6 7V5a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v2"/><path d="M6 7l1 13h10l1-13"/><path d="M9 11h6"/></svg>
                    </span>
                    <p class="items-kpi-meta">{{ $soldItems > 0 ? 'Moved units' : 'No sales yet' }}</p>
                </div>
                <p class="items-kpi-value jf-skel jf-skel-value">{{ number_format($soldItems) }}</p>
                <div class="items-kpi-foot">
                    <p class="items-kpi-title">Sold</p>
                    <p class="items-kpi-note">Completed sales</p>
                </div>
            </section>

            @if($isRetailer)
            @php
                $mh = $stats['metal_holdings'] ?? ['gold'=>0,'silver'=>0,'platinum'=>0];
                $metals = collect([
                    ['key'=>'gold','label'=>'Gold','dot'=>'#f59e0b','weight'=>$mh['gold']],
                    ['key'=>'silver','label'=>'Silver','dot'=>'#94a3b8','weight'=>$mh['silver']],
                    ['key'=>'platinum','label'=>'Platinum','dot'=>'#64748b','weight'=>$mh['platinum']],
                ])->filter(fn($m) => $m['weight'] > 0)->values();
            @endphp
            <section class="items-kpi-card items-kpi-charcoal items-kpi-card-value items-kpi-card-metals" aria-label="Metal holdings KPI"
                     x-data="{ show: false, timer: null, toggle() { if (this.show) { this.show = false; clearTimeout(this.timer); } else { this.show = true; clearTimeout(this.timer); this.timer = setTimeout(() => this.show = false, 3000); } } }">
                <div class="items-kpi-head">
                    <span class="items-kpi-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12l4 6-10 13L2 9Z"/><path d="M11 3 8 9l4 13 4-13-3-6"/><path d="M2 9h20"/></svg>
                    </span>
                    <button type="button" class="items-kpi-eye" @click="toggle()" :aria-label="show ? 'Hide metal weights' : 'Show metal weights'" :title="show ? 'Hide' : 'Show'">
                        <svg x-show="show" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg x-show="!show" x-cloak xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-6.5 0-10-7-10-7a19.36 19.36 0 0 1 4.22-5.36"/><path d="M9.88 5.09A10.94 10.94 0 0 1 12 5c6.5 0 10 7 10 7a19.36 19.36 0 0 1-3.3 4.36"/><path d="M1 1l22 22"/><path d="M14.12 14.12A3 3 0 1 1 9.88 9.88"/></svg>
                    </button>
                </div>
                @if($metals->isEmpty())
                    <p class="items-kpi-value jf-skel jf-skel-value">0.000 g</p>
                    <div class="items-kpi-foot">
                        <p class="items-kpi-title">Metal Holdings</p>
                        <p class="items-kpi-note">No in-stock items</p>
                    </div>
                @else
                    <ul class="items-kpi-metals-list">
                        @foreach($metals as $m)
                            <li class="items-kpi-metals-row">
                                <span class="items-kpi-metals-label">
                                    <span class="items-kpi-metals-dot" style="background: {{ $m['dot'] }}"></span>
                                    {{ $m['label'] }}
                                </span>
                                <span class="items-kpi-metals-weight">
                                    <span x-show="show">{{ number_format($m['weight'], 3) }} g</span>
                                    <span x-show="!show" x-cloak class="items-kpi-metals-mask">••••••</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                    <div class="items-kpi-foot">
                        <p class="items-kpi-title">Metal Holdings</p>
                        <p class="items-kpi-note">Net metal weight in stock</p>
                    </div>
                @endif
            </section>
            @else
            <section class="items-kpi-card items-kpi-charcoal items-kpi-card-value" aria-label="Total fine gold KPI"
                     x-data="{ show: false, timer: null, toggle() { if (this.show) { this.show = false; clearTimeout(this.timer); } else { this.show = true; clearTimeout(this.timer); this.timer = setTimeout(() => this.show = false, 3000); } } }">
                <div class="items-kpi-head">
                    <span class="items-kpi-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
                    </span>
                    <button type="button" class="items-kpi-eye" @click="toggle()" :aria-label="show ? 'Hide fine gold' : 'Show fine gold'" :title="show ? 'Hide' : 'Show'">
                        <svg x-show="show" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg x-show="!show" x-cloak xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-6.5 0-10-7-10-7a19.36 19.36 0 0 1 4.22-5.36"/><path d="M9.88 5.09A10.94 10.94 0 0 1 12 5c6.5 0 10 7 10 7a19.36 19.36 0 0 1-3.3 4.36"/><path d="M1 1l22 22"/><path d="M14.12 14.12A3 3 0 1 1 9.88 9.88"/></svg>
                    </button>
                </div>
                <p class="items-kpi-value jf-skel jf-skel-value">
                    <span x-show="show">{{ number_format($stats['total_fine_gold'] ?? 0, 3) }} g</span>
                    <span x-show="!show" x-cloak class="items-kpi-metals-mask">••••••</span>
                </p>
                <div class="items-kpi-foot">
                    <p class="items-kpi-title">Total Fine Gold</p>
                    <p class="items-kpi-note">Across active stock</p>
                </div>
            </section>
            @endif
        </div>

        <!-- Filters -->
        <div class="bg-white shadow-sm border border-gray-200 p-4 mb-6 items-filter-wrap">
            @php
                $statusToggles = [
                    'in_stock' => 'In Stock',
                    'sold'     => 'Sold',
                ];
                $toggleBaseParams = request()->except(['status', 'page']);
            @endphp
            <div class="items-status-toggle mb-3">
                @foreach($statusToggles as $value => $label)
                    @php $url = route('inventory.items.index', array_merge($toggleBaseParams, ['status' => $value])); @endphp
                    <a href="{{ $url }}" class="items-status-toggle-btn {{ $statusFilter === $value ? 'is-active' : '' }}">{{ $label }}</a>
                @endforeach
            </div>
            <form method="GET" action="{{ route('inventory.items.index') }}" class="ui-filter-bar ui-filter-bar--items" data-enhance-selects="true" data-enhance-selects-variant="standard">
                <input type="hidden" name="status" value="{{ $statusFilter }}">
                <div class="ui-filter-field-sm ui-filter-field--category">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                    <select name="category" class="w-full border-gray-300 text-sm">
                        <option value="">All</option>
                        @foreach($categories as $cat)
                            <option value="{{ $cat->id }}" {{ request('category') == $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="ui-filter-field-sm ui-filter-field--purity">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Purity</label>
                    <select name="purity" class="w-full border-gray-300 text-sm">
                        <option value="">All</option>
                        <option value="24" {{ request('purity') == '24' ? 'selected' : '' }}>24K</option>
                        <option value="22" {{ request('purity') == '22' ? 'selected' : '' }}>22K</option>
                        <option value="18" {{ request('purity') == '18' ? 'selected' : '' }}>18K</option>
                        <option value="14" {{ request('purity') == '14' ? 'selected' : '' }}>14K</option>
                    </select>
                </div>
                <div class="ui-filter-field ui-filter-field--search">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                          <input type="text" name="search" value="{{ request('search') }}"
                              placeholder="Barcode, design..." class="w-full border-gray-300 text-sm"
                              data-suggest="items" autocomplete="off">
                </div>
                <div class="ui-filter-actions">
                    @if(request()->hasAny(['status', 'category', 'purity', 'search']))
                        <a href="{{ route('inventory.items.index') }}" class="btn btn-secondary btn-sm inline-flex items-center gap-1">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            Clear
                        </a>
                    @else
                        <button type="submit" class="btn btn-dark btn-sm inline-flex items-center gap-1.5">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                            Filter
                        </button>
                    @endif
                </div>
            </form>
        </div>

        <!-- Items Table -->
        <div class="bg-white shadow-sm border border-gray-200 items-table-card items-table-card--stock">
            <div class="overflow-x-auto ui-table-shell items-stock-table-shell">
                <table class="w-full items-data-table items-data-table--stock">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Item</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Purity</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Gross Wt</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Net Metal Wt</th>
                            @if($isRetailer)
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Selling Price</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Cost Price</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Margin</th>
                            @else
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Fine Gold</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Cost Price</th>
                            @endif
                            <th class="px-6 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Status</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($items as $item)
                            @php
                                $fineGold = $item->net_metal_weight * ($item->purity / 24);
                                $margin = $item->selling_price - $item->cost_price;
                                $marginPct = $item->cost_price > 0 ? ($margin / $item->cost_price) * 100 : 0;
                            @endphp
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        @if($item->image)
                                            <img src="{{ asset('storage/' . $item->image) }}" alt="{{ $item->design }}" class="ui-thumb-48 object-cover bg-gray-100 rounded-xl">
                                        @else
                                            <div class="ui-thumb-48 bg-gray-100 flex items-center justify-center rounded-xl">
                                                <span class="text-xl"></span>
                                            </div>
                                        @endif
                                        <div>
                                            <div class="text-sm font-semibold text-gray-900">{{ $item->design ?: 'N/A' }}</div>
                                            <div class="font-mono text-xs text-gray-500">{{ $item->barcode }}</div>
                                            <div class="text-xs text-gray-500">{{ $item->category }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span class="inline-flex items-center px-2 py-0.5 text-xs font-medium bg-yellow-100 text-yellow-800">
                                        {{ $item->purity }}K
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right text-sm text-gray-700">{{ number_format($item->gross_weight, 3) }} g</td>
                                <td class="px-6 py-4 text-right text-sm text-gray-700">{{ number_format($item->net_metal_weight, 3) }} g</td>
                                @if($isRetailer)
                                    <td class="px-6 py-4 text-right text-sm font-semibold text-amber-600">₹{{ number_format($item->selling_price, 2) }}</td>
                                    <td class="px-6 py-4 text-right text-sm text-gray-700">₹{{ number_format($item->cost_price, 2) }}</td>
                                    <td class="px-6 py-4 text-right text-sm font-semibold {{ $margin >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                        ₹{{ number_format($margin, 2) }}
                                        <span class="text-xs font-normal">({{ number_format($marginPct, 1) }}%)</span>
                                    </td>
                                @else
                                    <td class="px-6 py-4 text-right text-sm font-semibold text-yellow-600">{{ number_format($fineGold, 3) }} g</td>
                                    <td class="px-6 py-4 text-right text-sm font-semibold text-gray-900">₹{{ number_format($item->cost_price, 2) }}</td>
                                @endif
                                <td class="px-6 py-4 text-center">
                                    @if($item->status == 'in_stock')
                                        <span class="inline-flex items-center px-2.5 py-0.5 text-xs font-medium bg-green-100 text-green-800">In Stock</span>
                                    @elseif($item->status == 'sold')
                                        <span class="inline-flex items-center px-2.5 py-0.5 text-xs font-medium bg-blue-100 text-blue-800">Sold</span>
                                    @else
                                        <span class="inline-flex items-center px-2.5 py-0.5 text-xs font-medium bg-gray-100 text-gray-800">{{ ucfirst($item->status) }}</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <div class="flex flex-wrap items-center justify-center gap-2">
                                        <a href="{{ route('inventory.items.show', $item) }}" 
                                           class="btn btn-secondary btn-xs inline-flex items-center">
                                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                            </svg>
                                            View
                                        </a>
                                        @if($item->status === 'in_stock')
                                                          <a href="{{ route('inventory.items.edit', $item) }}" 
                                                              class="btn btn-success btn-xs inline-flex items-center">
                                                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                                </svg>
                                                Edit
                                            </a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $isRetailer ? 9 : 8 }}" class="px-6 py-12 text-center">
                                    <svg class="w-12 h-12 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                    </svg>
                                    <p class="text-gray-500 mb-4">No items found</p>
                                    <a href="{{ route('inventory.items.create') }}" class="btn btn-success btn-sm inline-flex items-center">
                                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                                        </svg>
                                        Create First Item
                                    </a>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            
            @if($items->hasPages())
                <div class="px-6 py-4 border-t border-gray-200">
                    {{ $items->links() }}
                </div>
            @endif
        </div>
        </div>{{-- end items view --}}

        {{-- ==================== STOCK AGING VIEW (Retailers Only) ==================== --}}
        @if($isRetailer && $stockAgingData)
        <div x-show="view === 'aging'" x-cloak>
            @php
                $buckets = $stockAgingData;
                $agingSummary = $buckets['__summary'] ?? ['avg_days' => 0, 'aged_pct' => 0, 'aged_count' => 0];
                $buckets = collect($buckets)->except('__summary')->toArray();
                $colors = [
                    '0-30 days' => ['bg' => 'bg-green-100', 'text' => 'text-green-700', 'border' => 'border-green-200'],
                    '31-60 days' => ['bg' => 'bg-blue-100', 'text' => 'text-blue-700', 'border' => 'border-blue-200'],
                    '61-90 days' => ['bg' => 'bg-amber-100', 'text' => 'text-amber-700', 'border' => 'border-amber-200'],
                    '91-180 days' => ['bg' => 'bg-orange-100', 'text' => 'text-orange-700', 'border' => 'border-orange-200'],
                    '180+ days' => ['bg' => 'bg-rose-100', 'text' => 'text-rose-700', 'border' => 'border-rose-200'],
                ];
                $totalItems = collect($buckets)->sum('count');
            @endphp

            <div class="items-aging-kpi-grid mb-6">
                @foreach($buckets as $label => $data)
                <div class="items-kpi-card items-kpi-charcoal items-aging-kpi-card">
                    <div class="items-kpi-head">
                        <span class="items-kpi-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
                        </span>
                        <span class="items-aging-badge {{ $colors[$label]['bg'] }} {{ $colors[$label]['text'] }}">{{ $label }}</span>
                    </div>
                    <p class="items-kpi-value">{{ number_format($data['count']) }}</p>
                    <div class="items-kpi-foot">
                        <p class="items-kpi-title">Items in bucket</p>
                        <p class="items-kpi-note">₹{{ number_format($data['value'], 0) }}</p>
                    </div>
                </div>
                @endforeach
            </div>

            <div class="items-aging-kpi-grid items-aging-kpi-grid--summary mb-6">
                <div class="items-kpi-card items-kpi-charcoal items-aging-kpi-card">
                    <div class="items-kpi-head">
                        <span class="items-kpi-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h18"/><path d="M6 7V5a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v2"/><path d="M6 7l1 13h10l1-13"/></svg>
                        </span>
                        <p class="items-kpi-meta">Stock health</p>
                    </div>
                    <p class="items-kpi-value">{{ number_format($totalItems) }}</p>
                    <div class="items-kpi-foot">
                        <p class="items-kpi-title">Total Items in Stock</p>
                    </div>
                </div>
                <div class="items-kpi-card items-kpi-charcoal items-aging-kpi-card">
                    <div class="items-kpi-head">
                        <span class="items-kpi-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
                        </span>
                        <p class="items-kpi-meta">Inventory velocity</p>
                    </div>
                    <p class="items-kpi-value">{{ $agingSummary['avg_days'] }}<span class="items-kpi-value-unit"> d</span></p>
                    <div class="items-kpi-foot">
                        <p class="items-kpi-title">Avg Days in Stock</p>
                        <p class="items-kpi-note">Mean age across in-stock items</p>
                    </div>
                </div>
                <div class="items-kpi-card items-kpi-charcoal items-aging-kpi-card">
                    <div class="items-kpi-head">
                        <span class="items-kpi-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
                        </span>
                        <p class="items-kpi-meta">Aging risk</p>
                    </div>
                    <p class="items-kpi-value">{{ number_format($agingSummary['aged_pct'], 1) }}<span class="items-kpi-value-unit">%</span></p>
                    <div class="items-kpi-foot">
                        <p class="items-kpi-title">% Aged ({{ '>' }}90 days)</p>
                        <p class="items-kpi-note">{{ $agingSummary['aged_count'] }} of {{ $totalItems }} items</p>
                    </div>
                </div>
            </div>

            @php $slowItems = collect($buckets['91-180 days']['items'])->merge($buckets['180+ days']['items']); @endphp
            @if($slowItems->count())
            <div class="bg-white shadow-sm border border-gray-200 overflow-hidden items-table-card items-table-card--slow">
                <div class="p-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">Slow-Moving Stock (90+ days)</h3>
                    <p class="text-sm text-gray-500">{{ $slowItems->count() }} items need attention</p>
                </div>
                <div class="overflow-x-auto ui-table-shell items-table-shell items-table-shell--slow">
                    <table class="w-full items-data-table items-data-table--slow">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Barcode</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Price</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Days in Stock</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach($slowItems->take(50) as $sItem)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-3 text-sm font-mono">{{ $sItem->barcode }}</td>
                                <td class="px-6 py-3 text-sm">{{ $sItem->category }}{{ $sItem->sub_category ? ' · ' . $sItem->sub_category : '' }}</td>
                                <td class="px-6 py-3 text-sm text-right">₹{{ number_format($sItem->selling_price, 2) }}</td>
                                <td class="px-6 py-3 text-sm text-right font-medium text-rose-600">{{ $sItem->created_at->diffInDays(now()) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @endif
        </div>
        @endif

        {{-- ==================== BEST & WORST SELLERS VIEW (Retailers Only) ==================== --}}
        @if($isRetailer && $sellersData)
        <div x-show="view === 'sellers'" x-cloak>
            @php
                $best = $sellersData['best'];
                $worst = $sellersData['worst'];
                $sellerPeriod = $sellersData['period'];
                $sellerByCategory = collect($best['by_category'] ?? []);
                $sellerTotalSold = (int) $sellerByCategory->sum('sold_count');
                $sellerTotalRevenue = (float) $sellerByCategory->sum('total_revenue');
                $sellerTopCategory = $sellerByCategory->sortByDesc('sold_count')->first();
                $sellerWeakCategory = collect($worst ?? [])->sortBy('sold_count')->first();
            @endphp

            <div class="bg-white shadow-sm border border-gray-200 p-4 mb-6 ui-filter-enhanced-wrap items-seller-period-wrap">
                <form method="GET" action="{{ route('inventory.items.index') }}" class="flex flex-wrap gap-3 items-end items-seller-period-filter" data-enhance-selects="true" data-enhance-selects-variant="compact">
                    <input type="hidden" name="view" value="sellers">
                    <div class="items-seller-period-field">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Period</label>
                        <select name="seller_period" class="border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 items-seller-period-select">
                            <option value="7" {{ $sellerPeriod === '7' ? 'selected' : '' }}>Last 7 days</option>
                            <option value="30" {{ $sellerPeriod === '30' ? 'selected' : '' }}>Last 30 days</option>
                            <option value="90" {{ $sellerPeriod === '90' ? 'selected' : '' }}>Last 90 days</option>
                            <option value="365" {{ $sellerPeriod === '365' ? 'selected' : '' }}>Last year</option>
                        </select>
                    </div>
                    @if(request()->has('seller_period'))
                        <a href="{{ route('inventory.items.index', ['view' => 'sellers']) }}" class="btn btn-secondary btn-sm items-seller-period-apply"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Clear</a>
                    @else
                        <button type="submit" class="btn btn-secondary btn-sm items-seller-period-apply"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>Apply</button>
                    @endif
                </form>
            </div>

            <div class="items-sellers-kpi-grid mb-6">
                <section class="items-kpi-card items-kpi-charcoal items-sellers-kpi-card">
                    <div class="items-kpi-head">
                        <span class="items-kpi-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4"/><path d="M16 2v4"/><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M3 10h18"/></svg>
                        </span>
                        <p class="items-kpi-meta">Selected period</p>
                    </div>
                    <p class="items-kpi-value">
                        @if($sellerPeriod === '7') 7D @elseif($sellerPeriod === '30') 30D @elseif($sellerPeriod === '90') 90D @else 1Y @endif
                    </p>
                    <div class="items-kpi-foot">
                        <p class="items-kpi-title">Sell Trend Window</p>
                    </div>
                </section>
                <section class="items-kpi-card items-kpi-charcoal items-sellers-kpi-card">
                    <div class="items-kpi-head">
                        <span class="items-kpi-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                        </span>
                        <p class="items-kpi-meta">Category movement</p>
                    </div>
                    <p class="items-kpi-value">{{ number_format($sellerTotalSold) }}</p>
                    <div class="items-kpi-foot">
                        <p class="items-kpi-title">Total Units Sold</p>
                    </div>
                </section>
                <section class="items-kpi-card items-kpi-charcoal items-sellers-kpi-card">
                    <div class="items-kpi-head">
                        <span class="items-kpi-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                        </span>
                        <p class="items-kpi-meta">Revenue total</p>
                    </div>
                    <p class="items-kpi-value">₹{{ number_format($sellerTotalRevenue, 0) }}</p>
                    <div class="items-kpi-foot">
                        <p class="items-kpi-title">Trend Revenue</p>
                    </div>
                </section>
                <section class="items-kpi-card items-kpi-charcoal items-sellers-kpi-card">
                    <div class="items-kpi-head">
                        <span class="items-kpi-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h18"/><path d="M7 7l-4 5 4 5"/><path d="M17 7l4 5-4 5"/></svg>
                        </span>
                        <p class="items-kpi-meta">Top vs weak</p>
                    </div>
                    <p class="items-kpi-value items-kpi-value--name">{{ $sellerTopCategory['category'] ?? '—' }}</p>
                    <div class="items-kpi-foot">
                        <p class="items-kpi-title">Top Category</p>
                        <p class="items-kpi-note">Low: {{ $sellerWeakCategory['category'] ?? '—' }}</p>
                    </div>
                </section>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Best Sellers by Category --}}
                <div class="bg-white shadow-sm border border-gray-200 overflow-hidden items-table-card items-table-card--best-cat">
                    <div class="p-4 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-green-800">Best Sellers — by Category</h3>
                    </div>
                    <div class="overflow-x-auto ui-table-shell items-table-shell items-table-shell--sellers">
                        <table class="w-full items-data-table items-data-table--sellers">
                            <thead class="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Sold</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Revenue</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @forelse($best['by_category'] as $i => $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ $i + 1 }}</td>
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $row['category'] ?? '—' }}</td>
                                    <td class="px-4 py-3 text-sm text-right">{{ $row['sold_count'] }}</td>
                                    <td class="px-4 py-3 text-sm text-right text-green-700">₹{{ number_format($row['total_revenue'] ?? 0, 0) }}</td>
                                </tr>
                                @empty
                                <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500 text-sm">No sales data in this period.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Best Sellers by Sub-Category --}}
                <div class="bg-white shadow-sm border border-gray-200 overflow-hidden items-table-card items-table-card--best-sub">
                    <div class="p-4 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-amber-800">Best Sellers — by Sub-Category</h3>
                    </div>
                    <div class="overflow-x-auto ui-table-shell items-table-shell items-table-shell--sellers">
                        <table class="w-full items-data-table items-data-table--sellers">
                            <thead class="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Sub-Cat</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Sold</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Revenue</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @forelse($best['by_sub_category'] as $i => $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ $i + 1 }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-900">{{ $row['category'] ?? '—' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-600">{{ $row['sub_category'] ?? '—' }}</td>
                                    <td class="px-4 py-3 text-sm text-right">{{ $row['sold_count'] }}</td>
                                    <td class="px-4 py-3 text-sm text-right text-green-700">₹{{ number_format($row['total_revenue'] ?? 0, 0) }}</td>
                                </tr>
                                @empty
                                <tr><td colspan="5" class="px-4 py-8 text-center text-gray-500 text-sm">No data.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Worst Sellers --}}
                <div class="lg:col-span-2 bg-white shadow-sm border border-gray-200 overflow-hidden items-table-card items-table-card--worst">
                    <div class="p-4 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-rose-800">Worst Sellers — Lowest Movement Categories</h3>
                    </div>
                    <div class="overflow-x-auto ui-table-shell items-table-shell items-table-shell--sellers">
                        <table class="w-full items-data-table items-data-table--sellers">
                            <thead class="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Items Sold</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Revenue</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @forelse($worst as $i => $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-3 text-sm text-gray-500">{{ $i + 1 }}</td>
                                    <td class="px-6 py-3 text-sm font-medium text-gray-900">{{ $row['category'] ?? '—' }}</td>
                                    <td class="px-6 py-3 text-sm text-right text-rose-600 font-medium">{{ $row['sold_count'] }}</td>
                                    <td class="px-6 py-3 text-sm text-right">₹{{ number_format($row['total_revenue'] ?? 0, 0) }}</td>
                                </tr>
                                @empty
                                <tr><td colspan="4" class="px-6 py-8 text-center text-gray-500 text-sm">No sales data in this period.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        @endif

    </div>
</x-app-layout>
