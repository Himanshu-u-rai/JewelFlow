<x-app-layout>
    <div class="inventory-items-workspace">
    <x-stock-workspace-header active="items">
            @if($isRetailer && $pricingAlertCount > 0)
            <button type="button"
                    id="pricing-alert-bell"
                    onclick="if(window.__pricingAlerts){window.__pricingAlerts.open();}else{document.addEventListener('alpine:initialized',function(){window.__pricingAlerts&&window.__pricingAlerts.open();},{once:true});}"
                    class="relative inline-flex items-center justify-center w-9 h-9 rounded-lg border border-amber-300 bg-amber-50 text-amber-600 hover:bg-amber-100 transition-colors"
                    title="Pricing alerts — {{ $pricingAlertCount }} item(s) need attention"
                    aria-label="Pricing alerts — {{ $pricingAlertCount }} item(s) need attention">
                <x-icon name="bell" class="w-5 h-5" />
                <span class="absolute -top-1.5 -right-1.5 inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-amber-500 text-white text-[10px] font-bold leading-none">
                    {{ $pricingAlertCount > 99 ? '99+' : $pricingAlertCount }}
                </span>
            </button>
            @endif
            @can('inventory.create')
                <a href="{{ route('inventory.items.create') }}"
                    class="btn btn-success btn-sm inventory-items-create-btn">
                <x-icon name="plus" class="w-4 h-4 mr-2" />
                Create Item
            </a>
            @endcan
    </x-stock-workspace-header>

    @if($isRetailer && $pricingAlertCount > 0)
    @php $paStorageKey = 'pa_dismissed_' . (int) auth()->user()->shop_id . '_' . now()->toDateString(); @endphp
    {{-- Pricing Alert Drawer — own Alpine scope so it doesn't interfere with page x-data --}}
    <div
        id="pricing-alert-drawer"
        x-data="pricingAlertDrawer({{ $pricingAlertCount }}, '{{ route('inventory.items.pricing-alerts') }}', '{{ $paStorageKey }}')"
        @keydown.escape.window="isOpen && close()"
    >
        {{-- Backdrop --}}
        <div
            x-show="isOpen"
            x-cloak
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            @click="close()"
            class="fixed inset-0 bg-gray-900/40 z-40"
            aria-hidden="true"
        ></div>

        {{-- Slide-over panel --}}
        <div
            x-show="isOpen"
            x-cloak
            x-transition:enter="transition ease-out duration-250"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
            class="fixed top-0 right-0 h-full w-full max-w-md bg-white shadow-2xl z-50 flex flex-col transform"
            role="dialog"
            aria-modal="true"
            aria-labelledby="pricing-alert-title"
        >
            {{-- Header --}}
            <div class="flex items-center justify-between px-5 py-4 border-b border-gray-200 bg-amber-50">
                <div class="flex items-center gap-3">
                    <span class="flex items-center justify-center w-8 h-8 rounded-full bg-amber-100 text-amber-600">
                        <x-icon name="bell" class="w-4 h-4" stroke-width="2.2" />
                    </span>
                    <div>
                        <h2 id="pricing-alert-title" class="text-sm font-semibold text-gray-900">Pricing Alerts</h2>
                        <p class="text-xs text-amber-700"><span x-text="count"></span> item(s) could not be repriced today</p>
                    </div>
                </div>
                <button @click="close()" class="p-1.5 rounded-md text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors" aria-label="Close">
                    <x-icon name="x" class="w-5 h-5" />
                </button>
            </div>

            {{-- Info banner --}}
            <div class="px-5 py-3 bg-amber-50 border-b border-amber-100 text-xs text-amber-800">
                These items have <strong>stale prices</strong>. Click an item to edit it and fix the metal type or purity profile, then save today's rates again to reprice.
            </div>

            {{-- Item list. Whatever these templates draw is data-turbo-temporary: Turbo drops it
                 before it caches the page. Cached with the page, the drawn rows come back on Back
                 with no list around them: Alpine throws "item is not defined" for every binding in
                 every row, then draws the list again beside the dead copies. --}}
            <div class="flex-1 overflow-y-auto divide-y divide-gray-100">
                <template x-if="loading">
                    <div data-turbo-temporary class="flex items-center justify-center py-12 text-gray-400 text-sm">Loading…</div>
                </template>
                <template x-if="!loading && items.length === 0">
                    <div data-turbo-temporary class="flex flex-col items-center justify-center py-12 gap-2 text-gray-400">
                        <x-icon name="circle-check" class="w-8 h-8" stroke-width="1.5" />
                        <span>All items repriced successfully!</span>
                    </div>
                </template>
                <template x-for="item in items" :key="item.id">
                    <a data-turbo-temporary :href="item.edit_url" class="flex items-start gap-3 px-5 py-3.5 hover:bg-gray-50 transition-colors group">
                        <span class="mt-0.5 flex-shrink-0 w-7 h-7 rounded-full bg-red-50 text-red-400 flex items-center justify-center">
                            <x-icon name="circle-alert" class="w-3.5 h-3.5" stroke-width="2.5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 truncate group-hover:text-indigo-600" x-text="item.barcode"></p>
                            <p class="text-xs text-gray-500 truncate" x-text="[item.design, item.category].filter(Boolean).join(' · ') || 'No description'"></p>
                            <p class="mt-0.5 text-xs text-red-600 overflow-hidden" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical" x-text="item.pricing_review_notes || 'No metal type assigned'"></p>
                        </div>
                        <x-icon name="chevron-right" class="w-4 h-4 flex-shrink-0 mt-1 text-gray-300 group-hover:text-indigo-400 transition-colors" />
                    </a>
                </template>
            </div>

            {{-- Footer --}}
            <div class="px-5 py-3 border-t border-gray-200 bg-gray-50 flex items-center justify-between gap-3">
                <p class="text-xs text-gray-400">Dismiss to check later via the <strong>bell icon</strong> in the header.</p>
                <button @click="close()" class="flex-shrink-0 px-3 py-1.5 text-xs font-medium rounded-md bg-white border border-gray-300 text-gray-600 hover:bg-gray-50 transition-colors">
                    Close
                </button>
            </div>
        </div>
    </div>

    <script>
    function pricingAlertDrawer(initialCount, alertsUrl, storageKey) {
        return {
            isOpen: false,
            loading: false,
            count: initialCount,
            items: [],
            _storageKey: storageKey,

            // Alpine calls init() by itself. (An x-init="init()" beside it ran this twice:
            // the panel opened twice and asked for the list twice.)
            init() {
                // Expose open() on window so the bell button (rendered outside this
                // x-data scope in x-page-header) can call it after Alpine initialises.
                window.__pricingAlerts = this;

                // Leaving the page ends this panel. Alpine normally destroys a page's
                // components when Turbo swaps the page, but not on the page a session
                // started on, where this instance and its Escape handler live on. Closed,
                // it stays silent ("isOpen && close()") instead of marking today's alerts
                // dismissed from whatever page Escape is pressed on next.
                document.addEventListener('turbo:before-cache', () => { this.isOpen = false; }, { once: true });

                // Auto-open once per day — skip if already dismissed this session
                if (this.count > 0 && !localStorage.getItem(this._storageKey)) {
                    this.open();
                }
            },

            open() {
                this.isOpen = true;
                if (this.items.length === 0) {
                    this.fetchItems();
                }
            },

            close() {
                this.isOpen = false;
                localStorage.setItem(this._storageKey, '1');
            },

            fetchItems() {
                this.loading = true;
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
                fetch(alertsUrl, {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
                    },
                })
                .then(r => {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(data => {
                    this.count = data.count ?? 0;
                    this.items = Array.isArray(data.items) ? data.items : [];
                })
                .catch(() => {
                    // Network / server error — badge count on the bell is still visible
                    this.items = [];
                })
                .finally(() => { this.loading = false; });
            },
        };
    }
    </script>
    @endif

    <div class="content-inner inventory-items-page jf-skeleton-host is-loading" data-list-workspace>

        @php
            $canModifyInventory = auth()->user()->can('inventory.create')
                || auth()->user()->can('inventory.edit')
                || auth()->user()->can('inventory.delete');
        @endphp
        @unless($canModifyInventory)
            @include('partials.view-only-banner', ['permission' => 'inventory.edit', 'message' => 'inventory management'])
        @endunless

        {{-- Shared Stock navigation: the same shell continues on Categories,
             Tag Printing and Reorder Alerts. All require inventory.view;
             the last two remain retailer-only, matching their route gates. --}}
        @include('inventory.items.partials.navigation', ['placement' => 'body', 'active' => 'items'])

        {{-- ==================== STOCK ITEMS VIEW ==================== --}}
        <div data-list-panel class="items-stock-register {{ $isRetailer ? 'items-stock-register--retailer' : '' }}">
        <x-app-alerts class="mb-6" />

        <!-- Stats Cards -->
        @php
            $totalItems = (int) ($stats['total'] ?? 0);
            $inStockItems = (int) ($stats['in_stock'] ?? 0);
            $soldItems = (int) ($stats['sold'] ?? 0);
            $inventoryFiltersActive = request()->filled('category')
                || request()->filled('sub_category')
                || request()->filled('purity')
                || request()->filled('search')
                || request('status') === 'sold';
        @endphp
        <div class="ui-stats-grid items-kpi-grid items-kpi-grid--charcoal mb-6">
            <section class="items-kpi-card items-kpi-charcoal items-kpi-card-total" aria-label="Total items KPI">
                <div class="items-kpi-head">
                    <span class="items-kpi-icon" aria-hidden="true">
                        <x-icon name="layers" stroke-width="2.2" />
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
                        <x-icon name="circle-check" stroke-width="2.2" />
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
                        <x-icon name="shopping-bag" stroke-width="2.2" />
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
                    ['key'=>'gold','label'=>'Gold','dot'=>'#f59e0b','weight'=>(float) ($mh['gold'] ?? 0)],
                    ['key'=>'silver','label'=>'Silver','dot'=>'#94a3b8','weight'=>(float) ($mh['silver'] ?? 0)],
                    ['key'=>'platinum','label'=>'Platinum','dot'=>'#64748b','weight'=>(float) ($mh['platinum'] ?? 0)],
                ])->filter(fn($m) => $m['weight'] > 0)->values();
            @endphp
            <section class="items-kpi-card items-kpi-charcoal items-kpi-card-value items-kpi-card-metals" aria-label="Metal holdings KPI"
                     x-data="{ show: false, timer: null, toggle() { if (this.show) { this.show = false; clearTimeout(this.timer); } else { this.show = true; clearTimeout(this.timer); this.timer = setTimeout(() => this.show = false, 3000); } } }">
                <div class="items-kpi-head">
                    <span class="items-kpi-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12l4 6-10 13L2 9Z"/><path d="M11 3 8 9l4 13 4-13-3-6"/><path d="M2 9h20"/></svg>
                    </span>
                    <button type="button" class="items-kpi-eye" @click="toggle()" :aria-label="show ? 'Hide metal weights' : 'Show metal weights'" :title="show ? 'Hide' : 'Show'">
                        <x-icon name="eye" size="16" x-show="show" />
                        <x-icon name="eye-off" size="16" x-show="!show" x-cloak />
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
                        <x-icon name="database" stroke-width="2.2" />
                    </span>
                    <button type="button" class="items-kpi-eye" @click="toggle()" :aria-label="show ? 'Hide fine gold' : 'Show fine gold'" :title="show ? 'Hide' : 'Show'">
                        <x-icon name="eye" size="16" x-show="show" />
                        <x-icon name="eye-off" size="16" x-show="!show" x-cloak />
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

        @php
            $statusToggles = ['in_stock' => 'In Stock', 'sold' => 'Sold'];
            $toggleBaseParams = request()->except(['status', 'page', 'view', 'seller_period']);
            $hasStockFilters = $statusFilter !== 'in_stock' || request()->anyFilled(['category', 'sub_category', 'purity', 'search']);
        @endphp

        <!-- Stock register: controls stay outside the scrolling rows. -->
        <div class="items-table-card items-table-card--stock items-register-frame" data-list-frame>
            <div class="items-register-header">
                <div class="items-register-heading">
                    <div>
                        <h2 class="items-register-title">Stock Register</h2>
                        <p class="items-register-count">{{ number_format($items->total()) }} {{ Str::plural('item', $items->total()) }}</p>
                    </div>
                    <nav class="items-register-status" aria-label="Stock status">
                        @foreach($statusToggles as $value => $label)
                            @php $url = route('inventory.items.index', array_merge($toggleBaseParams, ['status' => $value])); @endphp
                            <a href="{{ $url }}" class="items-register-status-btn {{ $statusFilter === $value ? 'is-active' : '' }}"
                               aria-current="{{ $statusFilter === $value ? 'page' : 'false' }}">{{ $label }}</a>
                        @endforeach
                    </nav>
                </div>
                <form method="GET" action="{{ route('inventory.items.index') }}" class="items-register-filters" data-stock-filters
                      x-data="{ dirty: false, hasFilters: @js($hasStockFilters) }"
                      @input="dirty = true" @change="dirty = true"
                      data-enhance-selects="true" data-enhance-selects-variant="standard" data-enhance-selects-placement="viewport">
                    <input type="hidden" name="status" value="{{ $statusFilter }}">
                    <div class="items-register-field items-register-category">
                        <label for="stock-filter-category">Category</label>
                        <select id="stock-filter-category" name="category" class="items-register-control">
                            <option value="">All categories</option>
                            @foreach($categories as $cat)
                                <option value="{{ $cat->id }}" @selected(request('category') == $cat->id)>{{ $cat->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if(request()->filled('sub_category'))
                        <div class="items-register-field">
                            <span class="items-register-label">Sub-category</span>
                            <input type="hidden" name="sub_category" value="{{ request('sub_category') }}">
                            <a href="{{ request()->fullUrlWithoutQuery(['sub_category', 'page']) }}" class="items-register-subcategory"
                               title="Show every sub-category" aria-label="Remove sub-category filter: {{ request('sub_category') }}">
                                <span>{{ request('sub_category') }}</span>
                                <x-icon name="x" size="14" />
                            </a>
                        </div>
                    @endif
                    <div class="items-register-field items-register-purity">
                        <label for="stock-filter-purity">Purity</label>
                        @if($isRetailer)
                            <input id="stock-filter-purity" type="text" name="purity" value="{{ request('purity') }}"
                                   placeholder="22" title="Purity, e.g. 22 or 925" class="items-register-control">
                        @else
                            <select id="stock-filter-purity" name="purity" class="items-register-control">
                                <option value="">All purities</option>
                                <option value="24" @selected(request('purity') == '24')>24K</option>
                                <option value="22" @selected(request('purity') == '22')>22K</option>
                                <option value="18" @selected(request('purity') == '18')>18K</option>
                                <option value="14" @selected(request('purity') == '14')>14K</option>
                            </select>
                        @endif
                    </div>
                    <div class="items-register-field items-register-search">
                        <label for="stock-filter-search">Search</label>
                        <input id="stock-filter-search" type="text" name="search" value="{{ request('search') }}"
                               placeholder="Barcode, design..." class="items-register-control"
                               data-suggest="items" autocomplete="off">
                    </div>
                    <div class="items-register-actions">
                        <button type="submit" data-stock-filter-apply x-show="dirty || !hasFilters" class="btn btn-primary btn-sm">Apply</button>
                        <a href="{{ route('inventory.items.index') }}" data-stock-filter-clear x-show="!dirty && hasFilters" x-cloak class="btn btn-primary btn-sm">Clear</a>
                        @if($hasStockFilters)
                            <noscript><a href="{{ route('inventory.items.index') }}" class="btn btn-secondary btn-sm">Clear</a></noscript>
                        @endif
                    </div>
                </form>
            </div>
            <div class="overflow-x-auto ui-table-shell items-stock-table-shell" data-list-scroll tabindex="0" role="region" aria-label="Stock item records">
                <table class="w-full items-data-table items-data-table--stock jf-table">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th scope="col" class="jf-table-index" aria-label="Row number">#</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Item</th>
                            <th scope="col" class="px-6 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Purity</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Gross Wt</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Net Metal Wt</th>
                            @if($isRetailer)
                                <th scope="col" class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase">
                                    {{ ($stockValueDisplay ?? 'total') === 'per_gram' ? 'Rate/g' : 'Price' }}
                                </th>
                            @else
                                <th scope="col" class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Fine Gold</th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase">
                                    {{ ($stockValueDisplay ?? 'total') === 'per_gram' ? 'Rate/g' : 'Cost Price' }}
                                </th>
                            @endif
                            <th scope="col" class="px-6 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Status</th>
                            <th scope="col" class="px-6 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($items as $item)
                            @php
                                $fineGold = $item->net_metal_weight * ($item->purity / 24);
                            @endphp
                            <tr class="hover:bg-gray-50">
                                <td class="jf-table-index">{{ $items->firstItem() + $loop->index }}</td>
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
                                            <div class="text-xs text-gray-500">
                                                {{ $item->category }}
                                                @if($isRetailer && $item->metal_type)
                                                    • {{ ucfirst($item->metal_type) }}
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span class="inline-flex items-center px-2 py-0.5 text-xs font-medium bg-yellow-100 text-yellow-800">
                                        {{ $item->purity_label }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right text-sm text-gray-700">{{ number_format($item->gross_weight, 3) }} g</td>
                                <td class="px-6 py-4 text-right text-sm text-gray-700">{{ number_format($item->net_metal_weight, 3) }} g</td>
                                @if($isRetailer)
                                    <td class="px-6 py-4 text-right items-stock-price-cell">
                                        @if(($stockValueDisplay ?? 'total') === 'per_gram' && $item->net_metal_weight > 0)
                                            <span class="items-stock-price-value">₹{{ number_format($item->selling_price / $item->net_metal_weight, 0) }}</span><span class="items-stock-price-unit">/g</span>
                                        @else
                                            <span class="items-stock-price-value">₹{{ number_format($item->selling_price, 2) }}</span>
                                        @endif
                                    </td>
                                @else
                                    <td class="px-6 py-4 text-right text-sm font-semibold text-yellow-600">{{ number_format($fineGold, 3) }} g</td>
                                    <td class="px-6 py-4 text-right text-sm font-semibold text-gray-900">
                                        @if(($stockValueDisplay ?? 'total') === 'per_gram' && $item->net_metal_weight > 0)
                                            ₹{{ number_format($item->cost_price / $item->net_metal_weight, 0) }}<span class="text-xs text-gray-400 font-normal">/g</span>
                                        @else
                                            ₹{{ number_format($item->cost_price, 2) }}
                                        @endif
                                    </td>
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
                                    <div class="jf-table-actions jf-table-actions--center">
                                        <a href="{{ route('inventory.items.show', $item) }}" 
                                           class="jf-row-action jf-row-action--view" data-tip="View"><x-icon name="eye" size="16" /><span class="jf-sr-only">View</span></a>
                                        @if($item->status === 'in_stock')
                                            @can('inventory.edit')
                                                          <a href="{{ route('inventory.items.edit', $item) }}"
                                                              class="jf-row-action jf-row-action--edit" data-tip="Edit"><x-icon name="square-pen" size="16" /><span class="jf-sr-only">Edit</span></a>
                                            @endcan
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $isRetailer ? 8 : 9 }}" class="items-stock-empty-cell px-6 py-12 text-center">
                                    <div class="items-stock-empty-state">
                                        <span class="items-stock-empty-icon" aria-hidden="true">
                                            <x-icon name="box" size="22" />
                                        </span>
                                        <div>
                                            <p class="items-stock-empty-title">{{ $inventoryFiltersActive ? 'No matching items found' : 'No stock items yet' }}</p>
                                            <p class="items-stock-empty-copy">
                                                @if($inventoryFiltersActive)
                                                    Adjust the filters or clear them to return to the full stock register.
                                                @else
                                                    Create your first jewellery item to start tracking weight, purity, price, and stock status.
                                                @endif
                                            </p>
                                        </div>
                                        <div class="items-stock-empty-actions">
                                            @if($inventoryFiltersActive)
                                                <a href="{{ route('inventory.items.index') }}" class="items-stock-empty-secondary">
                                                    Clear Filters
                                                </a>
                                            @else
                                                @can('inventory.create')
                                                    <a href="{{ route('inventory.items.create') }}" class="items-stock-empty-primary">
                                                        Create Item
                                                    </a>
                                                @endcan
                                            @endif
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($isRetailer)
                <div class="items-stock-mobile-list" aria-label="Stock items mobile list">
                    @forelse($items as $item)
                        @php
                            $mobilePrice = (($stockValueDisplay ?? 'total') === 'per_gram' && $item->net_metal_weight > 0)
                                ? '₹' . number_format($item->selling_price / $item->net_metal_weight, 0) . '/g'
                                : '₹' . number_format($item->selling_price, 2);
                            $statusClass = $item->status === 'in_stock'
                                ? 'is-in-stock'
                                : ($item->status === 'sold' ? 'is-sold' : 'is-neutral');
                            $statusLabel = $item->status === 'in_stock'
                                ? 'In Stock'
                                : ($item->status === 'sold' ? 'Sold' : ucfirst($item->status));
                        @endphp
                        <article class="items-stock-mobile-card">
                            <div class="items-stock-mobile-card-main">
                                @if($item->image)
                                    <img src="{{ asset('storage/' . $item->image) }}" alt="{{ $item->design }}" class="items-stock-mobile-thumb">
                                @else
                                    <div class="items-stock-mobile-thumb items-stock-mobile-thumb--empty" aria-hidden="true">
                                        <x-icon name="box" size="18" stroke-width="2.1" />
                                    </div>
                                @endif
                                <div class="items-stock-mobile-identity">
                                    <div class="items-stock-mobile-title-row">
                                        <h3><span class="jf-record-index">#{{ $items->firstItem() + $loop->index }}</span> {{ $item->design ?: 'N/A' }}</h3>
                                        <span class="items-stock-mobile-status {{ $statusClass }}">{{ $statusLabel }}</span>
                                    </div>
                                    <p class="items-stock-mobile-barcode">{{ $item->barcode }}</p>
                                    <p class="items-stock-mobile-meta">
                                        {{ $item->category }}
                                        @if($item->metal_type)
                                            <span>• {{ ucfirst($item->metal_type) }}</span>
                                        @endif
                                    </p>
                                </div>
                            </div>

                            <div class="items-stock-mobile-metrics">
                                <div>
                                    <span>Purity</span>
                                    <strong>{{ $item->purity_label }}</strong>
                                </div>
                                <div>
                                    <span>Gross Wt</span>
                                    <strong>{{ number_format($item->gross_weight, 3) }} g</strong>
                                </div>
                                <div>
                                    <span>Net Metal</span>
                                    <strong>{{ number_format($item->net_metal_weight, 3) }} g</strong>
                                </div>
                                <div class="items-stock-mobile-price">
                                    <span>{{ ($stockValueDisplay ?? 'total') === 'per_gram' ? 'Rate' : 'Price' }}</span>
                                    <strong>{{ $mobilePrice }}</strong>
                                </div>
                            </div>

                            <div class="items-stock-mobile-actions">
                                <a href="{{ route('inventory.items.show', $item) }}" class="items-stock-mobile-action items-stock-mobile-action--view">View</a>
                                @if($item->status === 'in_stock')
                                    @can('inventory.edit')
                                    <a href="{{ route('inventory.items.edit', $item) }}" class="items-stock-mobile-action items-stock-mobile-action--edit">Edit</a>
                                    @endcan
                                @endif
                            </div>
                        </article>
                    @empty
                        <div class="items-stock-mobile-empty">
                            <span class="items-stock-empty-icon" aria-hidden="true">
                                <x-icon name="box" size="22" />
                            </span>
                            <strong>{{ $inventoryFiltersActive ? 'No matching items found' : 'No stock items yet' }}</strong>
                            <span>{{ $inventoryFiltersActive ? 'Adjust the filters or clear them to return to the full stock register.' : 'Create your first jewellery item to start tracking stock.' }}</span>
                            @if($inventoryFiltersActive)
                                <a href="{{ route('inventory.items.index') }}" class="items-stock-empty-secondary">Clear Filters</a>
                            @else
                                @can('inventory.create')
                                    <a href="{{ route('inventory.items.create') }}" class="items-stock-empty-primary">Create Item</a>
                                @endcan
                            @endif
                        </div>
                    @endforelse
                </div>
            @endif
            
            @if($items->hasPages())
                <div class="px-6 py-4 border-t border-gray-200">
                    {{ $items->links() }}
                </div>
            @endif
        </div>
        </div>{{-- end items view --}}

    </div>
    </div>
</x-app-layout>
