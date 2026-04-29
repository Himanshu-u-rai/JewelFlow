<x-app-layout>
    <style>
        :root {
            --r-ink: #0f172a;
            --r-ink-soft: #334155;
            --r-muted: #64748b;
            --r-border: #e2e8f0;
            --r-bg: #f8fafc;
            --r-accent: #0d9488;
            --r-card: #ffffff;
        }
        .r-label {
            display: flex; align-items: center; gap: 6px;
            font-size: 12px; font-weight: 600;
            color: var(--r-ink-soft); margin-bottom: 6px;
        }
        .r-label svg { width: 14px; height: 14px; color: var(--r-muted); flex-shrink: 0; }
        .r-input, .r-select, .r-textarea {
            width: 100%; padding: 10px 12px; font-size: 13px; font-weight: 500;
            border: 1.5px solid var(--r-border); border-radius: 12px;
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
        .r-checkbox {
            width: 16px; height: 16px; border-radius: 4px;
            border: 1.5px solid var(--r-border); accent-color: var(--r-accent);
            cursor: pointer;
        }
        .r-checkbox:checked { border-color: var(--r-accent); }
        .r-form-group { margin-bottom: 0; }
        .r-modal-card {
            border-radius: 14px; box-shadow: 0 20px 60px rgba(0,0,0,.15);
            border: 1px solid var(--r-border); overflow: hidden;
        }

        /* Custom dropdown */
        .r-dd-wrap { position: relative; z-index: 2; }
        .r-dd-wrap.dd-open { z-index: 34; }
        .r-dd-trigger {
            width: 100%; padding: 10px 36px 10px 12px; font-size: 13px; font-weight: 500;
            border: 1.5px solid var(--r-border); border-radius: 12px;
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
            border-radius: 0 0 12px 12px;
            box-shadow: 0 8px 24px rgba(0,0,0,.1); max-height: 220px; overflow: hidden;
        }
        .r-dd-panel.open { display: block; }
        .r-dd-wrap.dd-dropup .r-dd-trigger.open {
            border-top-left-radius: 0;
            border-top-right-radius: 0;
            border-bottom-left-radius: 12px;
            border-bottom-right-radius: 12px;
        }
        .r-dd-wrap.dd-dropup .r-dd-panel {
            top: auto;
            bottom: 100%;
            border-top: 1.5px solid var(--r-accent);
            border-bottom: none;
            border-radius: 12px 12px 0 0;
            box-shadow: 0 -8px 24px rgba(0,0,0,.1);
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

        .repairs-table-shell {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            overscroll-behavior-x: contain;
            max-width: 100%;
        }

        .repairs-table {
            min-width: 940px;
        }

        .repairs-layout > * {
            min-width: 0;
        }

        .repairs-form-card,
        .repairs-table-card {
            min-width: 0;
        }

        .repairs-form-card {
            overflow: visible !important;
        }

        @media (max-width: 768px) {
            .content-inner .repairs-kpi-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                gap: 6px;
                margin-bottom: 12px;
                align-items: stretch;
            }

            .repairs-kpi-grid > div {
                min-width: 0;
                width: 100%;
                height: 72px;
                min-height: 72px;
                padding: 7px 6px;
                border-radius: 10px;
                display: flex;
                align-items: stretch;
                justify-content: flex-start;
            }

            .repairs-kpi-grid > div .flex {
                display: grid;
                grid-template-columns: auto 1fr;
                grid-template-rows: 1fr auto;
                column-gap: 5px;
                row-gap: 4px;
                width: 100%;
                min-width: 0;
                height: 100%;
                align-items: start;
            }

            .repairs-kpi-grid > div [class*="p-2"] {
                padding: 4px;
                border-radius: 999px;
                flex-shrink: 0;
                grid-column: 1;
                grid-row: 1;
                align-self: start;
                justify-self: start;
            }

            .repairs-kpi-grid > div [class*="p-2"] svg {
                width: 10px;
                height: 10px;
            }

            .repairs-kpi-grid > div .text-xs {
                font-size: 8px;
                letter-spacing: 0.02em;
                line-height: 1.1;
                white-space: normal;
                text-align: center;
                overflow: hidden;
                text-overflow: clip;
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                min-height: 0;
                grid-column: 1 / span 2;
                grid-row: 2;
                justify-self: center;
                align-self: end;
            }

            .repairs-kpi-grid > div .text-xl {
                font-size: clamp(17px, 4.2vw, 21px);
                line-height: 1;
                margin: 0;
                grid-column: 1 / span 2;
                grid-row: 1;
                align-self: center;
                justify-self: center;
                text-align: center;
                white-space: nowrap;
                max-width: 100%;
                overflow: hidden;
                text-overflow: clip;
                font-variant-numeric: tabular-nums;
                transform: translateY(8px);
            }

            .repairs-kpi-grid > div .flex > div:last-child {
                min-width: 0;
                display: contents;
            }

            .repairs-layout {
                gap: 14px;
            }

            .repairs-form-card,
            .repairs-table-card {
                border-radius: 14px;
            }

            .repairs-form-card > .p-6,
            .repairs-table-card > .p-6 {
                padding: 14px;
            }

            .repairs-form {
                padding: 14px;
                gap: 14px;
            }

            .repairs-table {
                min-width: 860px;
            }

            .repairs-table th,
            .repairs-table td {
                padding: 10px 12px;
            }

            .repairs-table td {
                font-size: 12px;
            }

            .repairs-table .repairs-item-col {
                min-width: 220px;
                white-space: normal;
            }

            .repairs-table .repairs-action-row {
                flex-wrap: nowrap;
                gap: 6px;
            }

            .repairs-table .repairs-action-row .btn {
                min-height: 30px;
                padding: 5px 8px;
                font-size: 11px;
                white-space: nowrap;
            }

            .repairs-table .repairs-action-row .btn svg {
                width: 11px;
                height: 11px;
                margin-right: 3px;
            }
        }
    </style>
    <x-page-header class="repairs-page-header" title="Repairs Management" subtitle="Receive items, track progress, and deliver repairs">
        <x-slot:actions>
            <a href="{{ route('report.repairs') }}"
               class="btn btn-success btn-sm repairs-report-btn">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 20v-6m-6 6V4m-6 16v-4"/>
                </svg>
                Repairs Report
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner repairs-management-page">
        <x-app-alerts class="mb-6" />
        @php
            $pendingCount = $statusCounts['pending'] ?? 0;
            $deliveredCount = $statusCounts['delivered'] ?? 0;
        @endphp

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6 repairs-kpi-grid">
            <div class="bg-white shadow-sm border border-gray-200 p-4 repairs-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-amber-100 text-amber-700 p-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500">Pending</p>
                        <p class="text-xl font-semibold text-gray-900">{{ $pendingCount }}</p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 repairs-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-emerald-100 text-emerald-700 rounded-lg p-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500">Delivered</p>
                        <p class="text-xl font-semibold text-gray-900">{{ $deliveredCount }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 repairs-layout">
            <!-- New Repair Form -->
            <div class="lg:col-span-1">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden repairs-form-card repairs-surface-card">
                    <div class="p-6 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                            <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            Receive New Repair
                        </h2>
                        <p class="text-sm text-gray-500 mt-1">Register item for repair work</p>
                    </div>
                    
                    <form method="POST" action="{{ route('repairs.store') }}" class="p-6 space-y-6 repairs-form" enctype="multipart/form-data">
                        @csrf
                        
                        <!-- Customer Selection -->
                        <div class="r-form-group">
                            <label class="r-label"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>Customer</label>
                            <input type="hidden" name="customer_id" id="customer_id" required>
                            <div class="r-dd-wrap" id="custDdWrap">
                                <button type="button" class="r-dd-trigger" id="custDdTrigger" onclick="toggleDd('cust')">
                                    <span class="r-dd-placeholder">Select Customer</span>
                                </button>
                                <div class="r-dd-panel" id="custDdPanel">
                                    <div style="position:relative">
                                        <span class="r-dd-search-icon"><svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg></span>
                                        <input type="text" class="r-dd-search" id="custDdSearch" placeholder="Search customer… (press Enter to add new)" oninput="filterCustDd()" onkeydown="handleCustSearchKey(event)">
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

                        <!-- Item Description -->
                        <div class="r-form-group">
                            <label for="item_description" class="r-label"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>Item Description</label>
                            <input type="text" name="item_description" id="item_description" 
                                   placeholder="e.g., Gold Ring, Chain, Bracelet"
                                   class="r-input" required>
                            @error('item_description')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Repair Description -->
                        <div class="r-form-group">
                            <label for="description" class="r-label"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>Repair Description</label>
                            <textarea name="description" id="description" rows="3"
                                      placeholder="e.g., Resize from size 6 to size 7, Broken link in chain needs soldering"
                                      class="r-textarea"></textarea>
                            @error('description')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Gross Weight + Purity row -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div class="r-form-group">
                                <label for="gross_weight" class="r-label"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3"/></svg>Gross Weight (g)</label>
                                <input type="number" step="0.001" name="gross_weight" id="gross_weight" 
                                       placeholder="0.000"
                                       class="r-input" required>
                                @error('gross_weight')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="r-form-group">
                                <label class="r-label"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>Purity (Karat) <span style="color:var(--muted);font-weight:normal">(optional)</span></label>
                                <input type="hidden" name="purity" id="purity">
                                <div class="r-dd-wrap" id="purityDdWrap">
                                    <button type="button" class="r-dd-trigger" id="purityDdTrigger" onclick="toggleDd('purity')">
                                        <span class="r-dd-placeholder">Select Purity</span>
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
                                
                                <!-- Custom purity input (hidden by default) -->
                                <input type="number" step="0.01" id="custom_purity" placeholder="Enter custom purity (e.g., 19.5)"
                                       class="hidden r-input mt-2" oninput="document.getElementById('purity').value = this.value">
                                
                                @error('purity')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <!-- Item Photo -->
                        <div class="r-form-group">
                            <label for="repair_image" class="r-label">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                Item Photo <span class="text-gray-400 text-xs font-normal">(optional)</span>
                            </label>
                            <input type="file" name="image" id="repair_image" accept="image/*" class="r-input" onchange="previewRepairImage(event)">
                            <div id="repair_image_preview" class="mt-2 hidden">
                                <img id="repair_image_preview_img" src="" alt="Preview" style="max-width:100%;max-height:180px;border-radius:8px;border:1px solid #e5e7eb;">
                                <button type="button" class="text-xs text-red-600 mt-1 underline" onclick="clearRepairImage()">Remove</button>
                            </div>
                            @error('image')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Estimated Cost -->
                        <div class="r-form-group">
                            <label for="estimated_cost" class="r-label"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>Estimated Cost (₹)</label>
                            <input type="number" step="0.01" name="estimated_cost" id="estimated_cost" 
                                   placeholder="0.00"
                                   class="r-input">
                            @error('estimated_cost')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Submit Button -->
                        <button type="submit" class="btn btn-dark w-full mt-2">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            Receive Repair Item
                        </button>
                    </form>
                </div>
            </div>

            <!-- Repairs List -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden repairs-table-card repairs-surface-card">
                    <div class="p-4 border-b border-gray-200 flex items-center justify-between gap-3 flex-wrap">
                        <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2 shrink-0">
                            <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
                            Active Repairs
                        </h2>
                        <div class="flex items-center gap-2 flex-1 justify-end">
                            <div style="position:relative;flex:1;max-width:360px">
                                <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#94a3b8">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                                </span>
                                <input type="text" id="repairsSearchInput"
                                       placeholder="Search repair #, item, customer, mobile…"
                                       class="r-input"
                                       style="padding-left:32px;width:100%"
                                       oninput="filterRepairsList()"
                                       onkeydown="if(event.key==='Enter'){event.preventDefault();}">
                            </div>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="clearRepairsSearch()" id="repairsSearchClear" style="display:none">Clear</button>
                        </div>
                    </div>

                    <div class="overflow-auto repairs-table-shell repairs-data-table-shell" style="height:560px;padding-bottom:12px">
                        <table class="w-full repairs-table repairs-data-table">
                            <thead class="bg-gray-50 border-b border-gray-200" style="position:sticky;top:0;z-index:1;background:#f9fafb">
                                <tr>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Repair #</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Weight</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Purity</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200" id="repairsTbody">
                                @forelse($repairs as $r)
                                    @php
                                        $searchBlob = strtolower(trim(implode(' ', array_filter([
                                            'rep-'.str_pad($r->repair_number, 3, '0', STR_PAD_LEFT),
                                            (string) $r->repair_number,
                                            $r->customer?->name,
                                            $r->customer?->mobile,
                                            $r->item_description,
                                            $r->description,
                                        ]))));
                                    @endphp
                                    <tr class="hover:bg-gray-50 transition-colors" data-search="{{ $searchBlob }}">
                                        <td class="px-3 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-800 font-mono">
                                                REP-{{ str_pad($r->repair_number, 3, '0', STR_PAD_LEFT) }}
                                            </span>
                                        </td>
                                        <td class="px-3 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">{{ $r->customer->name }}</div>
                                            <div class="text-sm text-gray-500">{{ $r->customer->mobile }}</div>
                                        </td>
                                        <td class="px-3 py-4 repairs-item-col">
                                            <div class="text-sm font-medium text-gray-900">{{ $r->item_description }}</div>
                                            @if($r->description)
                                                <div class="text-xs text-gray-500 mt-0.5">{{ Str::limit($r->description, 60) }}</div>
                                            @endif
                                        </td>
                                        <td class="px-3 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900">{{ number_format($r->gross_weight, 3) }} g</div>
                                        </td>
                                        <td class="px-3 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                                {{ $r->purity ? $r->purity.'K' : '—' }}
                                            </span>
                                        </td>
                                        <td class="px-3 py-4 whitespace-nowrap">
                                            @if($r->status === 'delivered')
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">
                                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>Delivered
                                                </span>
                                            @else
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">
                                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>Pending
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-4 whitespace-nowrap text-sm text-center">
                                            <div class="flex items-center justify-center gap-2 repairs-action-row">
                                                @if($r->status !== 'delivered')
                                                    <a href="{{ route('repairs.edit', $r) }}"
                                                       class="btn btn-secondary btn-xs">
                                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                                        </svg>
                                                        Edit
                                                    </a>
                                                    <button onclick="openDeliverModal({{ $r->id }}, @js($r->customer->name), @js($r->item_description), {{ $r->estimated_cost ?? 0 }})"
                                                            class="btn btn-success btn-xs" title="Complete & Bill">
                                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>Bill
                                                    </button>
                                                    <form method="POST" action="{{ route('repairs.destroy', $r) }}" class="inline" onsubmit="return confirm('Delete repair REP-{{ str_pad($r->repair_number, 3, '0', STR_PAD_LEFT) }} for {{ addslashes($r->customer->name) }}? This cannot be undone.');">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="btn btn-danger btn-xs" title="Delete repair">
                                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M8 7V4a1 1 0 011-1h6a1 1 0 011 1v3"/></svg>
                                                        </button>
                                                    </form>
                                                @endif
                                                @if($r->status === 'delivered' && $r->invoice_id)
                                                    <a href="{{ route('invoices.show', $r->invoice_id) }}"
                                                       class="btn btn-primary btn-xs">
                                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>View Invoice
                                                    </a>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="px-6 py-12 text-center text-gray-400">
                                            <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                            <div class="text-sm font-medium">No repairs registered yet</div>
                                            <div class="text-xs mt-1">Receive an item to get started</div>
                                        </td>
                                    </tr>
                                @endforelse
                                <tr id="repairsNoMatchRow" style="display:none">
                                    <td colspan="7" class="px-6 py-8 text-center text-gray-400 text-sm">No repairs match your search.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    
                    @if($repairs->hasPages())
                        <div class="px-6 py-4 border-t border-gray-200">
                            {{ $repairs->links() }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Delivery Modal -->
    <div id="deliverModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 z-50">
        <div class="min-h-full w-full flex items-center justify-center p-4">
            <div class="w-full max-w-md p-5 bg-white r-modal-card">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg leading-6 font-medium text-gray-900 flex items-center gap-2"><svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>Deliver Repair Item</h3>
                    <button onclick="closeDeliverModal()" class="text-gray-400 hover:text-gray-600">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                
                <div class="mb-4 p-3 bg-gray-50 rounded-lg border border-gray-200">
                    <div class="text-sm text-gray-600 flex items-center gap-1"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>Customer</div>
                    <div class="font-medium" id="modalCustomerName"></div>
                    <div class="text-sm text-gray-600 mt-2 flex items-center gap-1"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>Item</div>
                    <div class="font-medium" id="modalItemDesc"></div>
                </div>

                <form id="deliverForm" method="POST" action="">
                    @csrf
                    <div class="mb-4">
                        <label for="amount" class="r-label"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>Service Amount (Before GST) (₹)</label>
                        <input type="number" step="0.01" name="amount" id="amount" 
                               class="r-input" required>
                    </div>

                    <div class="mb-4 space-y-3">
                        <input type="hidden" name="include_gst" value="0">
                        <label class="inline-flex items-center gap-2 text-sm font-medium text-gray-700 cursor-pointer">
                            <input type="checkbox" id="include_gst" name="include_gst" value="1" class="r-checkbox">
                            Include GST in Repair Invoice
                        </label>

                        <div id="gstRateWrap" class="hidden">
                            <label for="gst_rate" class="r-label"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>GST Rate (%)</label>
                            <input type="number" step="0.01" min="0" max="100" name="gst_rate" id="gst_rate"
                                   value="{{ auth()->user()->shop->gst_rate ?? 3 }}"
                                   class="r-input">
                        </div>
                    </div>

                    <div class="flex gap-3">
                        <button type="button" onclick="closeDeliverModal()"
                                class="btn btn-secondary flex-1">
                            <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>Cancel
                        </button>
                        <button type="submit"
                                class="btn btn-success flex-1">
                            <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>Deliver & Bill
                        </button>
                    </div>
                </form>
            </div>
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

            // Close all dropdowns first
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

                    // Avoid mobile viewport jump caused by auto-focusing the search field.
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

        // Close dropdowns on outside click
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.r-dd-wrap')) closeAllDd();
        });

        // Close dropdowns when the page scrolls so panels don't drift into sticky header.
        document.querySelector('.content-body')?.addEventListener('scroll', closeAllDd, { passive: true });
        window.addEventListener('scroll', closeAllDd, { passive: true });
        window.addEventListener('resize', () => {
            document.querySelectorAll('.r-dd-panel.open').forEach(updateDdDirection);
        }, { passive: true });

        /* ─── Customer Dropdown ──────────── */
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
            // Show/hide empty state
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
            } else if (empty) {
                empty.style.display = 'none';
            }
        }

        function selectCust(el) {
            const id = el.dataset.id;
            const name = el.dataset.name;
            const mobile = el.dataset.mobile;
            document.getElementById('customer_id').value = id;
            const trigger = document.getElementById('custDdTrigger');
            trigger.innerHTML = '<span style="font-weight:600;color:var(--r-ink)">' + escHtml(name) + '</span> <span style="font-size:11px;color:var(--r-muted);margin-left:4px">' + escHtml(mobile) + '</span>';
            closeAllDd();
        }

        /* Enter in customer search with no match → offer quick-add. */
        function handleCustSearchKey(e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            const input = document.getElementById('custDdSearch');
            const name = (input.value || '').trim();
            if (!name) return;
            const visible = Array.from(document.querySelectorAll('#custDdList .r-dd-opt'))
                .filter(o => o.style.display !== 'none');
            if (visible.length > 0) {
                selectCust(visible[0]);
                return;
            }
            if (!confirm('No matching customer. Add "' + name + '" as a new customer?')) return;
            quickAddCustomer(name);
        }

        function quickAddCustomer(name) {
            const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const fd = new FormData();
            fd.append('name', name);
            fd.append('_token', token);
            fetch('{{ route('customers.quick-store') }}', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: fd,
                credentials: 'same-origin',
            }).then(r => r.ok ? r.json() : r.json().then(j => Promise.reject(j)))
              .then(c => {
                  const list = document.getElementById('custDdList');
                  const opt = document.createElement('div');
                  opt.className = 'r-dd-opt';
                  opt.dataset.id = c.id;
                  opt.dataset.name = c.name;
                  opt.dataset.mobile = c.mobile || '';
                  opt.onclick = function () { selectCust(opt); };
                  const nameDiv = document.createElement('div');
                  nameDiv.className = 'r-dd-opt-name';
                  nameDiv.textContent = c.name;
                  const subDiv = document.createElement('div');
                  subDiv.className = 'r-dd-opt-sub';
                  subDiv.textContent = c.mobile || '—';
                  opt.appendChild(nameDiv);
                  opt.appendChild(subDiv);
                  list.insertBefore(opt, list.firstChild);
                  const empty = document.getElementById('custDdEmpty');
                  if (empty) empty.style.display = 'none';
                  document.getElementById('custDdSearch').value = '';
                  filterCustDd();
                  selectCust(opt);
              })
              .catch(err => {
                  const msg = (err && err.message) || 'Failed to add customer.';
                  alert(msg);
              });
        }

        function previewRepairImage(e) {
            const file = e.target.files && e.target.files[0];
            const wrap = document.getElementById('repair_image_preview');
            const img = document.getElementById('repair_image_preview_img');
            if (!file) { wrap.classList.add('hidden'); img.src = ''; return; }
            const reader = new FileReader();
            reader.onload = ev => { img.src = ev.target.result; wrap.classList.remove('hidden'); };
            reader.readAsDataURL(file);
        }
        function clearRepairImage() {
            const input = document.getElementById('repair_image');
            input.value = '';
            document.getElementById('repair_image_preview').classList.add('hidden');
            document.getElementById('repair_image_preview_img').src = '';
        }

        function filterRepairsList() {
            const input = document.getElementById('repairsSearchInput');
            const q = (input?.value || '').trim().toLowerCase();
            const rows = document.querySelectorAll('#repairsTbody tr[data-search]');
            let visible = 0;
            rows.forEach(r => {
                const hay = r.dataset.search || '';
                const show = !q || hay.includes(q);
                r.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            const noMatch = document.getElementById('repairsNoMatchRow');
            if (noMatch) noMatch.style.display = (q && visible === 0) ? '' : 'none';
            const clearBtn = document.getElementById('repairsSearchClear');
            if (clearBtn) clearBtn.style.display = q ? '' : 'none';
        }
        function clearRepairsSearch() {
            const input = document.getElementById('repairsSearchInput');
            if (!input) return;
            input.value = '';
            filterRepairsList();
            input.focus();
        }

        /* ─── Purity Dropdown ────────────── */
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
                hiddenPurity.value = '';
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

        /* ─── Helpers ────────────────────── */
        function escHtml(str) {
            const d = document.createElement('div');
            d.textContent = str;
            return d.innerHTML;
        }

        /* ─── Delivery Modal ─────────────── */
        function openDeliverModal(repairId, customerName, itemDesc, estimatedCost) {
            document.getElementById('deliverModal').classList.remove('hidden');
            document.getElementById('modalCustomerName').textContent = customerName;
            document.getElementById('modalItemDesc').textContent = itemDesc;
            document.getElementById('amount').value = estimatedCost || '';
            document.getElementById('include_gst').checked = false;
            document.getElementById('gstRateWrap').classList.add('hidden');
            document.getElementById('deliverForm').action = `/repairs/${repairId}/deliver`;
        }

        function closeDeliverModal() {
            document.getElementById('deliverModal').classList.add('hidden');
        }

        document.getElementById('deliverModal')?.addEventListener('click', function(e) {
            if (e.target === this) closeDeliverModal();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') { closeAllDd(); closeDeliverModal(); }
        });

        document.getElementById('include_gst')?.addEventListener('change', function() {
            document.getElementById('gstRateWrap')?.classList.toggle('hidden', !this.checked);
        });
    </script>
</x-app-layout>
