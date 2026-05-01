<x-app-layout>
    <style>
        :root {
            --r-ink: #0f172a;
            --r-ink-soft: #334155;
            --r-muted: #64748b;
            --r-border: #e2e8f0;
            --r-bg: #f8fafc;
            --r-accent: #0d9488;
        }
        .r-label {
            display: flex; align-items: center; gap: 6px;
            font-size: 12px; font-weight: 600;
            color: var(--r-ink-soft); margin-bottom: 6px;
        }
        .r-label svg { width: 14px; height: 14px; color: var(--r-muted); flex-shrink: 0; }
        .r-input, .r-select, .r-textarea {
            width: 100%; padding: 10px 12px; font-size: 13px; font-weight: 500;
            border: 1.5px solid var(--r-border); border-radius: 10px;
            background: var(--r-bg); color: var(--r-ink);
            transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
        }
        .r-input:focus, .r-select:focus, .r-textarea:focus {
            outline: none; border-color: var(--r-accent); background: #fff;
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.12);
        }
        .r-input::placeholder, .r-textarea::placeholder { color: #9ca3af; font-weight: 400; }
        .r-select {
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 12px center;
            padding-right: 36px; cursor: pointer;
        }
        .r-textarea { resize: vertical; min-height: 72px; line-height: 1.5; }
        .r-cost-wrap { position: relative; }
        .r-cost-wrap .r-cost-symbol {
            position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
            font-size: 13px; font-weight: 600; color: var(--r-muted);
        }
        .r-cost-wrap .r-input { padding-left: 28px; }

        /* Custom dropdown */
        .r-dd-wrap { position: relative; z-index: 2; }
        .r-dd-wrap.dd-open { z-index: 34; }
        .r-dd-trigger {
            width: 100%; padding: 10px 36px 10px 12px; font-size: 13px; font-weight: 500;
            border: 1.5px solid var(--r-border); border-radius: 10px;
            background: var(--r-bg); color: var(--r-ink); cursor: pointer;
            transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
            text-align: left; position: relative;
        }
        .r-dd-trigger:focus { outline: none; border-color: var(--r-accent); background: #fff; box-shadow: 0 0 0 3px rgba(13,148,136,.12); }
        .r-dd-trigger.open { border-color: var(--r-accent); background: #fff; box-shadow: 0 0 0 3px rgba(13,148,136,.12); border-bottom-left-radius: 0; border-bottom-right-radius: 0; }
        .r-dd-trigger::after {
            content: ''; position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
            width: 16px; height: 16px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: center; transition: transform .15s;
        }
        .r-dd-trigger.open::after { transform: translateY(-50%) rotate(180deg); }
        .r-dd-trigger .r-dd-placeholder { color: #9ca3af; font-weight: 400; }
        .r-dd-panel {
            display: none; position: absolute; top: 100%; left: 0; right: 0; z-index: 18;
            background: #fff; border: 1.5px solid var(--r-accent); border-top: none;
            border-radius: 0 0 10px 10px;
            box-shadow: 0 8px 24px rgba(0,0,0,.1); max-height: 220px; overflow: hidden;
        }
        .r-dd-panel.open { display: block; }
        .r-dd-wrap.dd-dropup .r-dd-trigger.open {
            border-top-left-radius: 0;
            border-top-right-radius: 0;
            border-bottom-left-radius: 10px;
            border-bottom-right-radius: 10px;
        }
        .r-dd-wrap.dd-dropup .r-dd-panel {
            top: auto;
            bottom: 100%;
            border-top: 1.5px solid var(--r-accent);
            border-bottom: none;
            border-radius: 10px 10px 0 0;
            box-shadow: 0 -8px 24px rgba(0,0,0,.1);
        }

        .repairs-edit-card {
            overflow: visible !important;
        }
        .r-dd-search {
            width: 100%; padding: 8px 12px 8px 34px; font-size: 12px; font-weight: 400;
            border: none; border-bottom: 1px solid #f1f5f9; background: #f8fafc;
            color: var(--r-ink); outline: none;
        }
        .r-dd-search::placeholder { color: #9ca3af; }
        .r-dd-search-icon { position: absolute; left: 12px; top: 8px; color: var(--r-muted); }
        .r-dd-list { max-height: 170px; overflow-y: auto; }
        .r-dd-opt {
            padding: 8px 12px; cursor: pointer; transition: background .1s;
            border-bottom: 1px solid #f8fafc;
        }
        .r-dd-opt:last-child { border-bottom: none; }
        .r-dd-opt:hover, .r-dd-opt.active { background: #f0fdfa; }
        .r-dd-opt-name { font-size: 13px; font-weight: 600; color: var(--r-ink); }
        .r-dd-opt-sub { font-size: 11px; color: var(--r-muted); margin-top: 1px; }
        .r-dd-empty { padding: 14px; text-align: center; color: var(--r-muted); font-size: 12px; }
    </style>
    <x-page-header>
        <div>
            <h1 class="page-title">Edit Repair #{{ $repair->repair_number ?? '—' }}</h1>
            <p class="text-sm text-gray-500 mt-1">Update repair details</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('repairs.index') }}" class="btn btn-secondary btn-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>Back to Repairs</a>
        </div>
    </x-page-header>

    <div class="content-inner repairs-edit-page">
        <div class="max-w-2xl mx-auto bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden repairs-edit-card">
            <div class="p-5 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                    <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    Edit Repair Details
                </h2>
                <p class="text-sm text-gray-500 mt-1">Received on {{ $repair->created_at->format('d M Y') }}</p>
            </div>

            <form method="POST" action="{{ route('repairs.update', $repair) }}" class="p-6 space-y-5" enctype="multipart/form-data">
                @csrf
                @method('PUT')

                <div class="bg-gray-50 rounded-lg p-4 border border-gray-200 repairs-edit-status-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Current Status</p>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-amber-100 text-amber-800">
                                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>Pending
                            </span>
                        </div>
                        <div class="text-sm font-mono font-semibold text-gray-600">REP-{{ str_pad($repair->repair_number, 3, '0', STR_PAD_LEFT) }}</div>
                    </div>
                </div>

                <div>
                    <label class="r-label"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>Customer *</label>
                    <input type="hidden" name="customer_id" id="customer_id" value="{{ old('customer_id', $repair->customer_id) }}" required>
                    <div class="r-dd-wrap" id="custDdWrap">
                        <button type="button" class="r-dd-trigger" id="custDdTrigger" onclick="toggleDd('cust')">
                            @php $selCust = $customers->firstWhere('id', old('customer_id', $repair->customer_id)); @endphp
                            @if($selCust)
                                <span style="font-weight:600;color:var(--r-ink)">{{ $selCust->name }}</span>
                                <span style="font-size:11px;color:var(--r-muted);margin-left:4px">{{ $selCust->mobile }}</span>
                            @else
                                <span class="r-dd-placeholder">Select Customer</span>
                            @endif
                        </button>
                        <div class="r-dd-panel" id="custDdPanel">
                            <div style="position:relative">
                                <span class="r-dd-search-icon"><svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg></span>
                                <input type="text" class="r-dd-search" id="custDdSearch" placeholder="Search customer…" oninput="filterCustDd()">
                            </div>
                            <div class="r-dd-list" id="custDdList">
                                @foreach($customers as $c)
                                    <div class="r-dd-opt" data-id="{{ $c->id }}" data-name="{{ $c->name }}" data-mobile="{{ $c->mobile }}" onclick="selectCust(this)">
                                        <div class="r-dd-opt-name">{{ $c->name }}</div>
                                        <div class="r-dd-opt-sub">{{ $c->mobile }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    @error('customer_id')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="item_description" class="r-label"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>Item Description *</label>
                    <input type="text" name="item_description" id="item_description"
                           value="{{ old('item_description', $repair->item_description) }}"
                           placeholder="e.g., Gold Ring, Chain, Bracelet"
                           class="r-input" required>
                    @error('item_description')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="description" class="r-label"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>Repair Description</label>
                    <textarea name="description" id="description" rows="3"
                              placeholder="e.g., Resize from size 6 to size 7, Broken link in chain needs soldering"
                              class="r-textarea">{{ old('description', $repair->description) }}</textarea>
                    @error('description')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="gross_weight" class="r-label"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3"/></svg>Gross Weight (g) *</label>
                        <input type="number" step="0.001" name="gross_weight" id="gross_weight"
                               value="{{ old('gross_weight', $repair->gross_weight) }}"
                               placeholder="0.000"
                               class="r-input" required>
                        @error('gross_weight')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="r-label"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>Purity (Karat) <span style="color:var(--r-muted);font-weight:normal">(optional)</span></label>
                        <input type="hidden" name="purity" id="purity" value="{{ old('purity', $repair->purity) }}">
                        @php
                            $currentPurity = old('purity', $repair->purity);
                            $stdPurities = [24, 22, 21, 18, 14];
                            $isCustom = !in_array($currentPurity, $stdPurities);
                        @endphp
                        <div class="r-dd-wrap" id="purityDdWrap">
                            <button type="button" class="r-dd-trigger" id="purityDdTrigger" onclick="toggleDd('purity')">
                                @if($isCustom && $currentPurity)
                                    <span style="font-weight:600;color:var(--r-ink)">Custom: {{ $currentPurity }}K</span>
                                @elseif($currentPurity)
                                    <span style="font-weight:600;color:var(--r-ink)">{{ $currentPurity }}K</span>
                                @else
                                    <span class="r-dd-placeholder">Select Purity</span>
                                @endif
                            </button>
                            <div class="r-dd-panel" id="purityDdPanel">
                                <div class="r-dd-list" id="purityDdList">
                                    <div class="r-dd-opt" data-value="24" onclick="selectPurity(this)"><div class="r-dd-opt-name">24K</div><div class="r-dd-opt-sub">99.9% Pure Gold</div></div>
                                    <div class="r-dd-opt" data-value="22" onclick="selectPurity(this)"><div class="r-dd-opt-name">22K</div><div class="r-dd-opt-sub">91.6% Pure Gold</div></div>
                                    <div class="r-dd-opt" data-value="21" onclick="selectPurity(this)"><div class="r-dd-opt-name">21K</div><div class="r-dd-opt-sub">87.5% Pure Gold</div></div>
                                    <div class="r-dd-opt" data-value="18" onclick="selectPurity(this)"><div class="r-dd-opt-name">18K</div><div class="r-dd-opt-sub">75.0% Pure Gold</div></div>
                                    <div class="r-dd-opt" data-value="14" onclick="selectPurity(this)"><div class="r-dd-opt-name">14K</div><div class="r-dd-opt-sub">58.3% Pure Gold</div></div>
                                    <div class="r-dd-opt" data-value="custom" onclick="selectPurity(this)"><div class="r-dd-opt-name">Custom Purity</div><div class="r-dd-opt-sub">Enter a custom karat value</div></div>
                                </div>
                            </div>
                        </div>

                        <input type="number" step="0.01" id="custom_purity"
                               value="{{ $isCustom ? $currentPurity : '' }}"
                               placeholder="Enter custom purity (e.g., 19.5)"
                               class="{{ $isCustom ? '' : 'hidden' }} r-input mt-2"
                               oninput="document.getElementById('purity').value = this.value">

                        @error('purity')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div>
                    <label class="r-label"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>Photo <span style="color:var(--r-muted);font-weight:normal">(optional)</span></label>
                    @php($existingRepairImageUrl = $repair->resolveImageUrl('public'))
                    @if($existingRepairImageUrl)
                        <div id="existingImageWrap" class="mb-2 flex items-center gap-3">
                            <img src="{{ $existingRepairImageUrl }}" alt="repair" style="max-height:120px;border-radius:8px;border:1px solid var(--r-border)">
                            <label style="display:inline-flex;align-items:center;gap:6px;font-size:12px;color:var(--r-ink-soft);cursor:pointer">
                                <input type="checkbox" name="remove_image" value="1" onchange="toggleRemoveImage(this)"> Remove current image
                            </label>
                        </div>
                    @endif
                    <input type="file" name="image" id="repair_image" accept="image/jpeg,image/png,image/webp" class="r-input" onchange="previewRepairImage(event)">
                    <div id="repairImagePreview" class="mt-2 hidden">
                        <img id="repairImagePreviewImg" alt="" style="max-height:120px;border-radius:8px;border:1px solid var(--r-border)">
                        <button type="button" onclick="clearRepairImage()" class="ml-2 text-xs text-red-600">Remove</button>
                    </div>
                    @error('image')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="estimated_cost" class="r-label"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>Estimated Cost (₹)</label>
                    <div class="r-cost-wrap">
                        <span class="r-cost-symbol">₹</span>
                        <input type="number" step="0.01" name="estimated_cost" id="estimated_cost"
                               value="{{ old('estimated_cost', $repair->estimated_cost) }}"
                               placeholder="0.00"
                               class="r-input">
                    </div>
                    @error('estimated_cost')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex justify-end gap-3 pt-4 border-t border-gray-200">
                    <a href="{{ route('repairs.index') }}" class="btn btn-secondary btn-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Cancel</a>
                    <button type="submit" class="btn btn-dark btn-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        /* ─── Custom Dropdown Logic ──────── */
        function shouldAutoFocusInlineSearch() {
            const isMobileViewport = window.matchMedia('(max-width: 768px)').matches;
            const isTouchDevice = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);
            return !(isMobileViewport || isTouchDevice);
        }

        function focusWithoutScroll(el) {
            if (!el) return;
            try {
                el.focus({ preventScroll: true });
            } catch (_) {
                el.focus();
            }
        }

        function toggleDd(type) {
            const trigger = document.getElementById(type + 'DdTrigger');
            const panel = document.getElementById(type + 'DdPanel');
            const isOpen = panel.classList.contains('open');
            const wrap = trigger?.closest('.r-dd-wrap');
            closeAllDd();
            if (!isOpen) {
                trigger.classList.add('open');
                panel.classList.add('open');
                if (wrap) {
                    wrap.classList.add('dd-open');
                }
                updateDdDirection(panel);
                const search = panel.querySelector('.r-dd-search');
                if (search) {
                    search.value = '';
                    filterCustDd();
                    if (shouldAutoFocusInlineSearch()) {
                        setTimeout(() => focusWithoutScroll(search), 50);
                    }
                }
            }
        }

        function closeAllDd() {
            document.querySelectorAll('.r-dd-wrap').forEach((wrap) => {
                wrap.classList.remove('dd-open', 'dd-dropup');
            });
            document.querySelectorAll('.r-dd-trigger').forEach(t => t.classList.remove('open'));
            document.querySelectorAll('.r-dd-panel').forEach(p => p.classList.remove('open'));
            const active = document.activeElement;
            if (active && active.classList && active.classList.contains('r-dd-search')) {
                active.blur();
            }
        }

        function updateDdDirection(panel) {
            if (!panel) return;
            const wrap = panel.closest('.r-dd-wrap');
            if (!wrap) return;

            wrap.classList.remove('dd-dropup');

            const panelStyles = window.getComputedStyle(panel);
            const maxHeight = parseFloat(panelStyles.maxHeight) || panel.scrollHeight || 220;
            const panelHeight = Math.min(panel.scrollHeight || maxHeight, maxHeight) + 12;
            const rect = wrap.getBoundingClientRect();
            const spaceBelow = window.innerHeight - rect.bottom;
            const spaceAbove = rect.top;

            if (spaceBelow < panelHeight && spaceAbove > spaceBelow) {
                wrap.classList.add('dd-dropup');
            }
        }

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.r-dd-wrap')) closeAllDd();
        });

        // Close dropdowns when the page scrolls so panels don't drift into sticky header.
        document.querySelector('.content-body')?.addEventListener('scroll', closeAllDd, { passive: true });
        window.addEventListener('scroll', closeAllDd, { passive: true });
        window.addEventListener('resize', () => {
            document.querySelectorAll('.r-dd-panel.open').forEach(updateDdDirection);
        }, { passive: true });

        function filterCustDd() {
            const q = (document.getElementById('custDdSearch')?.value || '').toLowerCase();
            const opts = document.querySelectorAll('#custDdList .r-dd-opt');
            let visible = 0;
            opts.forEach(opt => {
                const name = (opt.dataset.name || '').toLowerCase();
                const mobile = (opt.dataset.mobile || '').toLowerCase();
                const show = name.includes(q) || mobile.includes(q);
                opt.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            let empty = document.getElementById('custDdEmpty');
            if (visible === 0) {
                if (!empty) {
                    empty = document.createElement('div');
                    empty.id = 'custDdEmpty';
                    empty.className = 'r-dd-empty';
                    empty.textContent = 'No customer found';
                    document.getElementById('custDdList').appendChild(empty);
                }
                empty.style.display = '';
            } else if (empty) { empty.style.display = 'none'; }
        }

        function selectCust(el) {
            document.getElementById('customer_id').value = el.dataset.id;
            const trigger = document.getElementById('custDdTrigger');
            trigger.innerHTML = '<span style="font-weight:600;color:var(--r-ink)">' + escHtml(el.dataset.name) + '</span> <span style="font-size:11px;color:var(--r-muted);margin-left:4px">' + escHtml(el.dataset.mobile) + '</span>';
            closeAllDd();
        }

        function selectPurity(el) {
            const val = el.dataset.value;
            const label = el.querySelector('.r-dd-opt-name').textContent;
            const trigger = document.getElementById('purityDdTrigger');
            const customInput = document.getElementById('custom_purity');
            const hiddenPurity = document.getElementById('purity');
            if (val === 'custom') {
                trigger.innerHTML = '<span style="font-weight:600;color:var(--r-ink)">Custom Purity</span>';
                customInput.classList.remove('hidden');
                customInput.required = true;
                hiddenPurity.value = customInput.value || '';
                closeAllDd();
                if (shouldAutoFocusInlineSearch()) {
                    setTimeout(() => focusWithoutScroll(customInput), 50);
                }
            } else {
                trigger.innerHTML = '<span style="font-weight:600;color:var(--r-ink)">' + escHtml(label) + '</span>';
                hiddenPurity.value = val;
                customInput.classList.add('hidden');
                customInput.required = false;
                customInput.value = '';
                closeAllDd();
            }
        }

        function escHtml(str) { const d = document.createElement('div'); d.textContent = str; return d.innerHTML; }

        document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeAllDd(); });

        function previewRepairImage(e) {
            const file = e.target.files && e.target.files[0];
            const wrap = document.getElementById('repairImagePreview');
            const img = document.getElementById('repairImagePreviewImg');
            if (!file) { wrap.classList.add('hidden'); return; }
            img.src = URL.createObjectURL(file);
            wrap.classList.remove('hidden');
        }
        function clearRepairImage() {
            const input = document.getElementById('repair_image');
            input.value = '';
            document.getElementById('repairImagePreview').classList.add('hidden');
        }
        function toggleRemoveImage(cb) {
            const wrap = document.getElementById('existingImageWrap');
            if (!wrap) return;
            const img = wrap.querySelector('img');
            if (img) img.style.opacity = cb.checked ? '0.3' : '1';
        }
    </script>
</x-app-layout>
