<x-app-layout>
    <style>
        [x-cloak] {
            display: none !important;
        }

        body.sp-mobile-filter-open {
            overflow: hidden;
        }

        .sp-mobile-filter-trigger-shell,
        .sp-mobile-filter-overlay,
        .sp-mobile-filter-drawer,
        .sp-mobile-list {
            display: none;
        }

        .sp-mobile-filter-trigger-shell {
            margin-bottom: 14px;
        }

        .sp-mobile-filter-trigger {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            width: 100%;
            border: 1px solid #dbe3ee;
            border-radius: 16px;
            background: #ffffff;
            padding: 12px 14px;
            text-align: left;
            box-shadow: 0 8px 20px rgba(15, 23, 42, .06);
        }

        .sp-mobile-filter-trigger-copy {
            min-width: 0;
        }

        .sp-mobile-filter-kicker {
            color: #64748b;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .sp-mobile-filter-summary {
            margin-top: 4px;
            color: #0f172a;
            font-size: 14px;
            font-weight: 700;
            line-height: 1.4;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .sp-mobile-filter-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }

        .sp-mobile-filter-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 28px;
            padding: 0 8px;
            border-radius: 999px;
            border: 1px solid #fde68a;
            background: #fffbeb;
            color: #92400e;
            font-size: 12px;
            font-weight: 800;
        }

        .sp-mobile-filter-open-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            border-radius: 999px;
            border: 1px solid #dbe3ee;
            background: #f8fafc;
            padding: 0 12px;
            color: #0f172a;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .sp-mobile-filter-overlay {
            position: fixed;
            inset: 0;
            z-index: 85;
            background: rgba(15, 23, 42, .5);
            backdrop-filter: blur(2px);
        }

        .sp-mobile-filter-drawer {
            position: fixed;
            right: 0;
            bottom: 0;
            left: 0;
            z-index: 90;
            padding: 0 0 env(safe-area-inset-bottom, 0px);
        }

        .sp-mobile-filter-drawer-enter,
        .sp-mobile-filter-drawer-leave {
            transition: transform .22s ease, opacity .22s ease;
        }

        .sp-mobile-filter-drawer-enter-start,
        .sp-mobile-filter-drawer-leave-end {
            opacity: 0;
            transform: translateY(24px);
        }

        .sp-mobile-filter-drawer-enter-end,
        .sp-mobile-filter-drawer-leave-start {
            opacity: 1;
            transform: translateY(0);
        }

        .sp-mobile-filter-sheet {
            display: flex;
            flex-direction: column;
            max-height: min(84vh, 740px);
            border-radius: 22px 22px 0 0;
            border: 1px solid #dbe3ee;
            background: #ffffff;
            box-shadow: 0 -20px 38px rgba(15, 23, 42, .22);
            overflow: hidden;
        }

        .sp-mobile-filter-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            padding: 16px;
            border-bottom: 1px solid #e2e8f0;
        }

        .sp-mobile-filter-title {
            color: #0f172a;
            font-size: 17px;
            font-weight: 800;
            line-height: 1.25;
        }

        .sp-mobile-filter-copy {
            margin-top: 4px;
            color: #64748b;
            font-size: 12px;
            line-height: 1.45;
        }

        .sp-mobile-filter-close {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border: 1px solid #dbe3ee;
            border-radius: 999px;
            background: #ffffff;
            color: #475569;
            flex-shrink: 0;
        }

        .sp-mobile-filter-body {
            overflow-y: auto;
            padding: 14px 16px 16px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .sp-mobile-filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .sp-mobile-filter-label {
            color: #475569;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .sp-mobile-search,
        .sp-mobile-native-select,
        .sp-mobile-date-input {
            width: 100%;
            min-height: 44px;
            border: 1px solid #d1d5db;
            border-radius: 12px;
            background: #ffffff;
            padding: 10px 12px;
            color: #0f172a;
            font-size: 15px;
        }

        .sp-mobile-date-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .sp-mobile-filter-footer {
            position: sticky;
            bottom: 0;
            display: flex;
            gap: 10px;
            padding: 12px 16px calc(12px + env(safe-area-inset-bottom, 0px));
            border-top: 1px solid #e2e8f0;
            background: rgba(255, 255, 255, .98);
        }

        .sp-mobile-filter-footer button,
        .sp-mobile-filter-footer a {
            flex: 1 1 0;
            min-height: 46px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .sp-mobile-filter-clear {
            border: 1px solid #dbe3ee;
            background: #f8fafc;
            color: #0f172a;
        }

        .sp-mobile-filter-apply {
            border: 1px solid #0f766e;
            background: #0f766e;
            color: #ffffff;
            box-shadow: 0 12px 24px rgba(15, 118, 110, .2);
        }

        .sp-filter-inline {
            position: relative;
            z-index: 1;
        }

        .sp-filter-field {
            display: flex;
            flex-direction: column;
            min-width: 130px;
        }

        .sp-filter-search {
            min-width: min(100%, 280px);
        }

        .sp-register-card {
            position: relative;
            z-index: 1;
            overflow: hidden;
        }

        .sp-register-table-wrap {
            overflow-x: auto;
            overscroll-behavior-x: contain;
        }

        .sp-register-table {
            width: 100%;
            min-width: 920px;
            border-collapse: separate;
            border-spacing: 0;
        }

        .sp-mobile-card {
            border: 1px solid #dbe3ee;
            border-radius: 14px;
            background: #ffffff;
            padding: 12px;
            box-shadow: 0 8px 18px rgba(15, 23, 42, .05);
        }

        .sp-mobile-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .sp-mobile-label {
            margin-bottom: 3px;
            color: #64748b;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .02em;
        }

        @media (max-width: 1024px) {
            .sp-register-table {
                min-width: 860px;
            }
        }

        @media (max-width: 680px) {
            .sp-filter-inline--desktop,
            .sp-register-table-wrap {
                display: none;
            }

            .sp-mobile-filter-trigger-shell,
            .sp-mobile-filter-overlay,
            .sp-mobile-filter-drawer,
            .sp-mobile-list {
                display: block;
            }

            .sp-mobile-list {
                padding: 12px;
            }

            .sp-mobile-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    @php
        $statusLabels = [
            'draft' => 'Draft',
            'confirmed' => 'Confirmed',
            'stocked' => 'Stocked',
        ];

        $activeFilters = [
            'search' => request('search'),
            'status' => request('status'),
            'vendor_id' => request('vendor_id'),
            'date_from' => request('date_from'),
            'date_to' => request('date_to'),
        ];

        $hasActiveFilters = collect($activeFilters)->contains(fn ($value) => filled($value));
        $selectedVendor = $vendors->firstWhere('id', (int) request('vendor_id'));
    @endphp

    <x-page-header title="Stock Purchases" subtitle="Record and manage incoming stock from suppliers">
        <x-slot:actions>
            @can('inventory.create')
            <a href="{{ route('inventory.purchases.create') }}" class="btn btn-success btn-sm">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                </svg>
                New Purchase
            </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner"
         x-data="{
             search: @js((string) request('search', '')),
             status: @js((string) request('status', '')),
             statusName: @js(request('status') ? ($statusLabels[request('status')] ?? ucfirst((string) request('status'))) : ''),
             vendorId: @js((string) request('vendor_id', '')),
             vendorName: @js($selectedVendor?->name ?? ''),
             fromDate: @js((string) request('date_from', '')),
             toDate: @js((string) request('date_to', '')),
             mobileFilterOpen: false,
             draftSearch: @js((string) request('search', '')),
             draftStatus: @js((string) request('status', '')),
             draftVendorId: @js((string) request('vendor_id', '')),
             draftFromDate: @js((string) request('date_from', '')),
             draftToDate: @js((string) request('date_to', '')),
             statusLabels: @js($statusLabels),
             vendorOptions: @js($vendors->map(fn ($vendor) => ['id' => (string) $vendor->id, 'name' => $vendor->name])->values()),
             initMobileDrawerWatcher() {
                 const drawer = document.querySelector('[data-mobile-drawer=\'tenant\']');
                 if (!drawer || this._spDrawerObserver) {
                     return;
                 }

                 this._spDrawerObserver = new MutationObserver(() => {
                     if (drawer.classList.contains('mobile-open')) {
                         this.closeMobileFilters();
                     }
                 });

                 this._spDrawerObserver.observe(drawer, { attributes: true, attributeFilter: ['class'] });
             },
             findVendorName(id) {
                 const match = this.vendorOptions.find((option) => option.id === String(id));
                 return match ? match.name : '';
             },
             statusLabel(value) {
                 return value ? (this.statusLabels[value] || value) : '';
             },
             copyCommittedToDraft() {
                 this.draftSearch = this.search;
                 this.draftStatus = this.status;
                 this.draftVendorId = this.vendorId;
                 this.draftFromDate = this.fromDate;
                 this.draftToDate = this.toDate;
             },
             activeFilterCount() {
                 let count = 0;
                 if (this.search && this.search.trim()) count++;
                 if (this.status) count++;
                 if (this.vendorId) count++;
                 if (this.fromDate) count++;
                 if (this.toDate) count++;
                 return count;
             },
             mobileFilterSummary() {
                 const parts = [];
                 if (this.search && this.search.trim()) parts.push('Search');
                 if (this.statusName) parts.push(this.statusName);
                 if (this.vendorName) parts.push(this.vendorName);
                 if (this.fromDate || this.toDate) {
                     parts.push(this.fromDate && this.toDate ? 'Date range' : (this.fromDate ? 'From date' : 'To date'));
                 }
                 return parts.length ? parts.join(' • ') : 'All purchases';
             },
             closeTenantDrawerIfNeeded() {
                 if (window.innerWidth > 680) {
                     return;
                 }

                 const drawer = document.querySelector('[data-mobile-drawer=\'tenant\']');
                 if (drawer?.classList.contains('mobile-open')) {
                     document.querySelector('[data-mobile-menu-toggle=\'tenant\']')?.click();
                 }
             },
             openMobileFilters() {
                 this.copyCommittedToDraft();
                 this.closeTenantDrawerIfNeeded();
                 this.mobileFilterOpen = true;
                 document.body.classList.add('sp-mobile-filter-open');
             },
             closeMobileFilters() {
                 this.mobileFilterOpen = false;
                 document.body.classList.remove('sp-mobile-filter-open');
             },
             applyMobileFilters() {
                 this.search = this.draftSearch;
                 this.status = this.draftStatus;
                 this.statusName = this.statusLabel(this.draftStatus);
                 this.vendorId = this.draftVendorId;
                 this.vendorName = this.findVendorName(this.draftVendorId);
                 this.fromDate = this.draftFromDate;
                 this.toDate = this.draftToDate;
                 this.closeMobileFilters();
                 this.$nextTick(() => this.$refs.mobileFilterForm.submit());
             },
             clearMobileFilters() {
                 this.draftSearch = '';
                 this.draftStatus = '';
                 this.draftVendorId = '';
                 this.draftFromDate = '';
                 this.draftToDate = '';
                 this.search = '';
                 this.status = '';
                 this.statusName = '';
                 this.vendorId = '';
                 this.vendorName = '';
                 this.fromDate = '';
                 this.toDate = '';
                 this.closeMobileFilters();
                 this.$nextTick(() => this.$refs.mobileFilterForm.submit());
             }
         }"
         x-init="initMobileDrawerWatcher()"
         @keydown.escape.window="closeMobileFilters()"
         @resize.window="if (window.innerWidth > 680) closeMobileFilters()">

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-5 sm:mb-6">
            <div class="rounded-xl border border-slate-200 bg-white p-3 sm:p-4 shadow-sm">
                <div class="flex items-center gap-2.5 sm:gap-3">
                    <div class="bg-amber-100 text-amber-700 rounded-lg p-2 sm:p-2.5">
                        <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-[10px] sm:text-[11px] uppercase tracking-[0.14em] sm:tracking-[0.18em] text-slate-500">Total Purchases</p>
                        <p class="text-lg sm:text-2xl font-semibold text-slate-900 leading-tight">{{ number_format($stats->total_confirmed ?? 0) }}</p>
                    </div>
                </div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-3 sm:p-4 shadow-sm">
                <div class="flex items-center gap-2.5 sm:gap-3">
                    <div class="bg-emerald-100 text-emerald-700 rounded-lg p-2 sm:p-2.5">
                        <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-[10px] sm:text-[11px] uppercase tracking-[0.14em] sm:tracking-[0.18em] text-slate-500">This Month</p>
                        <p class="text-lg sm:text-2xl font-semibold text-slate-900 leading-tight">₹{{ number_format($stats->month_amount ?? 0, 0) }}</p>
                    </div>
                </div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-3 sm:p-4 shadow-sm">
                <div class="flex items-center gap-2.5 sm:gap-3">
                    <div class="bg-blue-100 text-blue-700 rounded-lg p-2 sm:p-2.5">
                        <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-[10px] sm:text-[11px] uppercase tracking-[0.14em] sm:tracking-[0.18em] text-slate-500">Items This Month</p>
                        <p class="text-lg sm:text-2xl font-semibold text-slate-900 leading-tight">{{ number_format($monthItems ?? 0) }}</p>
                    </div>
                </div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-3 sm:p-4 shadow-sm">
                <div class="flex items-center gap-2.5 sm:gap-3">
                    <div class="bg-orange-100 text-orange-700 rounded-lg p-2 sm:p-2.5">
                        <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-[10px] sm:text-[11px] uppercase tracking-[0.14em] sm:tracking-[0.18em] text-slate-500">Drafts Pending</p>
                        <p class="text-lg sm:text-2xl font-semibold text-slate-900 leading-tight">{{ number_format($stats->drafts_pending ?? 0) }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="sp-mobile-filter-trigger-shell" x-cloak>
            <button type="button" class="sp-mobile-filter-trigger" @click="openMobileFilters()" :aria-expanded="mobileFilterOpen.toString()">
                <div class="sp-mobile-filter-trigger-copy">
                    <div class="sp-mobile-filter-kicker">Filters</div>
                    <div class="sp-mobile-filter-summary" x-text="mobileFilterSummary()">All purchases</div>
                </div>
                <div class="sp-mobile-filter-meta">
                    <span class="sp-mobile-filter-count" x-text="activeFilterCount()">0</span>
                    <span class="sp-mobile-filter-open-btn">Open</span>
                </div>
            </button>
        </div>

        <div class="sp-mobile-filter-overlay"
             x-show="mobileFilterOpen"
             x-transition.opacity
             x-cloak
             @click="closeMobileFilters()"></div>

        <div class="sp-mobile-filter-drawer"
             x-show="mobileFilterOpen"
             x-transition:enter="sp-mobile-filter-drawer-enter"
             x-transition:enter-start="sp-mobile-filter-drawer-enter-start"
             x-transition:enter-end="sp-mobile-filter-drawer-enter-end"
             x-transition:leave="sp-mobile-filter-drawer-leave"
             x-transition:leave-start="sp-mobile-filter-drawer-leave-start"
             x-transition:leave-end="sp-mobile-filter-drawer-leave-end"
             x-cloak>
            <form method="GET" action="{{ route('inventory.purchases.index') }}" class="sp-mobile-filter-sheet" x-ref="mobileFilterForm" @submit.prevent="applyMobileFilters()">
                <div class="sp-mobile-filter-head">
                    <div>
                        <div class="sp-mobile-filter-title">Filter Purchases</div>
                        <div class="sp-mobile-filter-copy">Set filters and apply them together.</div>
                    </div>
                    <button type="button" class="sp-mobile-filter-close" @click="closeMobileFilters()" aria-label="Close filters">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    </button>
                </div>

                <div class="sp-mobile-filter-body">
                    <div class="sp-mobile-filter-group">
                        <label class="sp-mobile-filter-label" for="sp-mobile-search">Search</label>
                        <input id="sp-mobile-search" type="text" name="search" x-model="draftSearch" class="sp-mobile-search" placeholder="Purchase #, invoice #, supplier...">
                    </div>

                    <div class="sp-mobile-filter-group">
                        <label class="sp-mobile-filter-label" for="sp-mobile-status">Status</label>
                        <select id="sp-mobile-status" name="status" class="sp-mobile-native-select" x-model="draftStatus">
                            <option value="">All</option>
                            @foreach($statusLabels as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="sp-mobile-filter-group">
                        <label class="sp-mobile-filter-label" for="sp-mobile-vendor">Vendor</label>
                        <select id="sp-mobile-vendor" name="vendor_id" class="sp-mobile-native-select" x-model="draftVendorId">
                            <option value="">All Vendors</option>
                            @foreach($vendors as $vendor)
                                <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="sp-mobile-filter-group">
                        <label class="sp-mobile-filter-label">Date Range</label>
                        <div class="sp-mobile-date-grid">
                            <input type="date" name="date_from" class="sp-mobile-date-input" x-model="draftFromDate">
                            <input type="date" name="date_to" class="sp-mobile-date-input" x-model="draftToDate">
                        </div>
                    </div>
                </div>

                <div class="sp-mobile-filter-footer">
                    <button type="button" class="sp-mobile-filter-clear" @click="clearMobileFilters()">Clear</button>
                    <button type="submit" class="sp-mobile-filter-apply">Apply Filters</button>
                </div>
            </form>
        </div>

        <div class="sp-filter-inline sp-filter-inline--desktop rounded-xl border border-slate-200 bg-white p-3.5 sm:p-4 shadow-sm mb-5 sm:mb-6">
            <form method="GET" action="{{ route('inventory.purchases.index') }}" class="flex flex-wrap items-end gap-3">
                <div class="sp-filter-field sp-filter-search flex-1 min-w-[210px] lg:min-w-[260px]">
                    <label class="mb-1.5 text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-500">Search</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-3 inline-flex items-center text-amber-500">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        </span>
                        <input type="text" name="search" x-model="search" placeholder="Purchase #, invoice #, supplier..." class="w-full rounded-lg border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-700 placeholder-slate-400 focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none">
                    </div>
                </div>

                <div class="sp-filter-field">
                    <label class="mb-1.5 text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-500">Status</label>
                    <select name="status" x-model="status" class="rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-700 focus:border-amber-500 focus:ring-amber-500">
                        <option value="">All</option>
                        @foreach($statusLabels as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="sp-filter-field min-w-[170px]">
                    <label class="mb-1.5 text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-500">Vendor</label>
                    <select name="vendor_id" x-model="vendorId" class="rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-700 focus:border-amber-500 focus:ring-amber-500">
                        <option value="">All Vendors</option>
                        @foreach($vendors as $vendor)
                            <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="sp-filter-field">
                    <label class="mb-1.5 text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-500">From</label>
                    <input type="date" name="date_from" x-model="fromDate" class="rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-700 focus:border-amber-500 focus:ring-amber-500">
                </div>

                <div class="sp-filter-field">
                    <label class="mb-1.5 text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-500">To</label>
                    <input type="date" name="date_to" x-model="toDate" class="rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-700 focus:border-amber-500 focus:ring-amber-500">
                </div>

                <div class="flex items-center gap-2">
                    <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">Apply</button>
                    @if($hasActiveFilters)
                        <a href="{{ route('inventory.purchases.index') }}" class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Clear</a>
                    @endif
                </div>
            </form>
        </div>

        <div class="sp-register-card rounded-xl border border-slate-200 bg-white shadow-sm">
            @if($purchases->isEmpty())
                <div class="px-5 py-12 text-center text-slate-400">
                    <svg class="mx-auto mb-3 h-12 w-12 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    <p class="text-sm font-medium text-slate-500">No purchases found</p>
                    <p class="mt-1 text-xs text-slate-400">Create your first stock purchase to get started</p>
                    @can('inventory.create')
                    <a href="{{ route('inventory.purchases.create') }}" class="mt-4 inline-flex items-center gap-2 rounded-lg bg-amber-500 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-600">New Purchase</a>
                    @endcan
                </div>
            @else
                <div class="sp-register-table-wrap">
                    <table class="sp-register-table">
                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr>
                                <th class="px-3.5 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-500">Purchase #</th>
                                <th class="px-3.5 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-500">Supplier</th>
                                <th class="px-3.5 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-500">Invoice #</th>
                                <th class="px-3.5 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-500">Date</th>
                                <th class="px-3.5 py-3 text-right text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-500">Items</th>
                                <th class="px-3.5 py-3 text-right text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-500">Total</th>
                                <th class="px-3.5 py-3 text-center text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-500">Status</th>
                                <th class="px-3.5 py-3 text-right text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($purchases as $purchase)
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="px-3.5 py-3">
                                        <a href="{{ route('inventory.purchases.show', $purchase) }}" class="font-mono text-sm font-semibold text-amber-600 hover:underline">{{ $purchase->purchase_number }}</a>
                                    </td>
                                    <td class="px-3.5 py-3 text-sm text-slate-700">{{ $purchase->supplier_label }}</td>
                                    <td class="px-3.5 py-3 text-sm text-slate-500">{{ $purchase->invoice_number ?: '—' }}</td>
                                    <td class="px-3.5 py-3 text-sm text-slate-600 whitespace-nowrap">{{ $purchase->purchase_date->format('d M Y') }}</td>
                                    <td class="px-3.5 py-3 text-right text-sm text-slate-700">{{ $purchase->lines_count }}</td>
                                    <td class="px-3.5 py-3 text-right text-sm font-semibold text-slate-900 whitespace-nowrap">₹{{ number_format($purchase->total_amount, 2) }}</td>
                                    <td class="px-3.5 py-3 text-center">
                                        @if($purchase->isDraft())
                                            <span class="inline-flex items-center rounded-full bg-orange-100 px-2.5 py-0.5 text-xs font-semibold text-orange-700">Draft</span>
                                        @elseif($purchase->isStocked())
                                            <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-700">Stocked</span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-semibold text-blue-700">Confirmed</span>
                                        @endif
                                    </td>
                                    <td class="px-3.5 py-3 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <a href="{{ route('inventory.purchases.show', $purchase) }}" class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">View</a>
                                            @if($purchase->isDraft())
                                                @can('inventory.edit')
                                                <a href="{{ route('inventory.purchases.edit', $purchase) }}" class="inline-flex items-center rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs font-semibold text-amber-700 hover:bg-amber-100">Edit</a>
                                                @endcan
                                                @can('inventory.delete')
                                                <form method="POST" action="{{ route('inventory.purchases.destroy', $purchase) }}" onsubmit="return confirm('Delete this draft purchase?')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="inline-flex items-center rounded-lg border border-red-200 bg-red-50 px-2.5 py-1.5 text-xs font-semibold text-red-600 hover:bg-red-100">Delete</button>
                                                </form>
                                                @endcan
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="sp-mobile-list space-y-3">
                    @foreach($purchases as $purchase)
                        <article class="sp-mobile-card">
                            <div class="mb-3 flex items-start justify-between gap-3">
                                <div>
                                    <a href="{{ route('inventory.purchases.show', $purchase) }}" class="font-mono text-sm font-semibold text-amber-600">{{ $purchase->purchase_number }}</a>
                                    <p class="mt-1 text-xs text-slate-500">{{ $purchase->supplier_label }}</p>
                                </div>
                                @if($purchase->isDraft())
                                    <span class="inline-flex items-center rounded-full bg-orange-100 px-2 py-0.5 text-[11px] font-semibold text-orange-700">Draft</span>
                                @elseif($purchase->isStocked())
                                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">Stocked</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-[11px] font-semibold text-blue-700">Confirmed</span>
                                @endif
                            </div>

                            <div class="sp-mobile-grid text-sm">
                                <div>
                                    <p class="sp-mobile-label">Invoice #</p>
                                    <p class="text-slate-700">{{ $purchase->invoice_number ?: '—' }}</p>
                                </div>
                                <div>
                                    <p class="sp-mobile-label">Date</p>
                                    <p class="text-slate-700">{{ $purchase->purchase_date->format('d M Y') }}</p>
                                </div>
                                <div>
                                    <p class="sp-mobile-label">Items</p>
                                    <p class="text-slate-800 font-semibold">{{ $purchase->lines_count }}</p>
                                </div>
                                <div>
                                    <p class="sp-mobile-label">Total</p>
                                    <p class="text-slate-900 font-semibold">₹{{ number_format($purchase->total_amount, 2) }}</p>
                                </div>
                            </div>

                            <div class="mt-3 border-t border-slate-100 pt-3">
                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="{{ route('inventory.purchases.show', $purchase) }}" class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700">View</a>
                                    @if($purchase->isDraft())
                                        @can('inventory.edit')
                                        <a href="{{ route('inventory.purchases.edit', $purchase) }}" class="inline-flex items-center rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs font-semibold text-amber-700">Edit</a>
                                        @endcan
                                        @can('inventory.delete')
                                        <form method="POST" action="{{ route('inventory.purchases.destroy', $purchase) }}" onsubmit="return confirm('Delete this draft purchase?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="inline-flex items-center rounded-lg border border-red-200 bg-red-50 px-2.5 py-1.5 text-xs font-semibold text-red-600">Delete</button>
                                        </form>
                                        @endcan
                                    @endif
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>

                @if($purchases->hasPages())
                    <div class="border-t border-slate-200 px-4 py-3">
                        {{ $purchases->links() }}
                    </div>
                @endif
            @endif
        </div>
    </div>
</x-app-layout>
