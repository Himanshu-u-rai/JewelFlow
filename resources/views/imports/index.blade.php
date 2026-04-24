<x-app-layout>
    <style>
        .import-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .import-card {
            background: #fff;
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 16px;
            padding: 18px;
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.05);
        }

        .import-title-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
        }

        .import-tag {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            border-radius: 9999px;
            padding: 3px 10px;
            border: 1px solid;
        }

        .tag-safe {
            color: #065f46;
            background: #ecfdf5;
            border-color: #a7f3d0;
        }

        .tag-ledger {
            color: #92400e;
            background: #fffbeb;
            border-color: #fde68a;
        }

        .upload-box {
            margin-top: 14px;
            padding: 14px;
            border: 1px dashed #cbd5e1;
            border-radius: 12px;
            background: #f8fafc;
        }

        .columns-strip {
            margin-top: 12px;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .columns-strip span {
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            color: #475569;
            background: #fff;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 9999px;
            padding: 3px 10px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .status-preview, .status-queued {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .status-running {
            background: #fef3c7;
            color: #92400e;
        }

        .status-completed {
            background: #dcfce7;
            color: #166534;
        }

        .status-failed {
            background: #fee2e2;
            color: #991b1b;
        }

        @media (max-width: 1024px) {
            .import-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <x-page-header
        class="ops-treatment-header"
        title="Bulk Imports"
        subtitle="Upload in the right CSV format, preview results, then execute safely."
        badge="CSV Templates Ready"
    />

    <div class="content-inner space-y-6 ops-treatment-page">
        <x-app-alerts />

        <div class="bg-white border border-gray-200 shadow-sm p-4" style="border-radius:16px;">
            <h2 class="text-base font-semibold text-gray-900">How It Works</h2>
            <p class="text-sm text-gray-500 mt-1">1. Download sample CSV template 2. Fill your rows 3. Upload and preview 4. Execute import.</p>
        </div>

        @php $isRetailer = auth()->user()->shop?->isRetailer(); @endphp

        <div class="import-grid">
            @if($isRetailer)
            {{-- ========== STOCK IMPORT (Retailers) ========== --}}
            <div class="import-card">
                <div class="import-title-row">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Stock Import</h2>
                        <p class="text-sm text-gray-500 mt-1">Bulk-add purchased items to your stock. No gold lot deduction.</p>
                    </div>
                    <span class="import-tag tag-safe">Safe</span>
                </div>

                <div class="columns-strip">
                    <span>barcode</span>
                    <span>category</span>
                    <span>sub_category</span>
                    <span>gross_weight</span>
                    <span>purity</span>
                    <span>making_charge</span>
                    <span>huid</span>
                    <span class="!border-dashed !text-gray-400">vendor_name</span>
                    <span class="!border-dashed !text-gray-400">cost_price</span>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <a href="{{ route('imports.template', ['type' => 'stock']) }}" class="btn btn-secondary btn-sm" data-turbo="false"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Download Sample CSV</a>
                </div>

                <form method="POST" action="{{ route('imports.stock.preview') }}" enctype="multipart/form-data" class="mt-4" x-data="{ uploading: false }" @submit="uploading = true">
                    @csrf
                    <div class="upload-box">
                        <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wide mb-2">Upload CSV</label>
                        <input
                            type="file"
                            name="file"
                            required
                            accept=".csv,text/csv"
                            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-gray-900 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-gray-800"
                        >
                        <p class="text-xs text-gray-500 mt-2">Barcode must be unique. Vendors will be auto-created if not found.</p>
                    </div>
                    <button type="submit" class="btn btn-dark mt-3" :disabled="uploading">
                        <template x-if="!uploading"><span><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1 inline"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>Create Preview</span></template>
                        <template x-if="uploading"><span><svg class="animate-spin -ml-1 mr-2 h-4 w-4 inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>Uploading &amp; Validating...</span></template>
                    </button>
                </form>
            </div>
            @endif

            @if(!$isRetailer)
            {{-- ========== CATALOG IMPORT (Manufacturers) ========== --}}
            <div class="import-card">
                <div class="import-title-row">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Catalog Import</h2>
                        <p class="text-sm text-gray-500 mt-1">Adds or updates product templates. No ledger movement.</p>
                    </div>
                    <span class="import-tag tag-safe">Safe</span>
                </div>

                <div class="columns-strip">
                    <span>design_code</span>
                    <span>name</span>
                    <span>category</span>
                    <span>sub_category</span>
                    <span>default_purity</span>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <a href="{{ route('imports.template', ['type' => 'catalog']) }}" class="btn btn-secondary btn-sm" data-turbo="false"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Download Sample CSV</a>
                </div>

                <form method="POST" action="{{ route('imports.catalog.preview') }}" enctype="multipart/form-data" class="mt-4" x-data="{ uploading: false }" @submit="uploading = true">
                    @csrf
                    <div class="upload-box">
                        <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wide mb-2">Upload CSV</label>
                        <input
                            type="file"
                            name="file"
                            required
                            accept=".csv,text/csv"
                            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-gray-900 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-gray-800"
                        >
                        <p class="text-xs text-gray-500 mt-2">Max file size: 10 MB. Only CSV allowed.</p>
                    </div>
                    <button type="submit" class="btn btn-dark mt-3" :disabled="uploading">
                        <template x-if="!uploading"><span><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1 inline"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>Create Preview</span></template>
                        <template x-if="uploading"><span><svg class="animate-spin -ml-1 mr-2 h-4 w-4 inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>Uploading &amp; Validating...</span></template>
                    </button>
                </form>
            </div>

            {{-- ========== MANUFACTURE IMPORT (Manufacturers) ========== --}}
            <div class="import-card">
                <div class="import-title-row">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Manufacture Import</h2>
                        <p class="text-sm text-gray-500 mt-1">Creates stock items and deducts lot gold on execution.</p>
                    </div>
                    <span class="import-tag tag-ledger">Ledger Impact</span>
                </div>

                <div class="columns-strip">
                    <span>barcode</span>
                    <span>design_code</span>
                    <span>lot_number</span>
                    <span>gross_weight</span>
                    <span>purity</span>
                    <span>making_charge</span>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <a href="{{ route('imports.template', ['type' => 'manufacture']) }}" class="btn btn-secondary btn-sm" data-turbo="false"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Download Sample CSV</a>
                </div>

                <form method="POST" action="{{ route('imports.manufacture.preview') }}" enctype="multipart/form-data" class="mt-4" x-data="{ uploading: false }" @submit="uploading = true">
                    @csrf
                    <div class="upload-box">
                        <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wide mb-2">Upload CSV</label>
                        <input
                            type="file"
                            name="file"
                            required
                            accept=".csv,text/csv"
                            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-gray-900 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-gray-800"
                        >
                        <p class="text-xs text-gray-500 mt-2">Make sure lot_number belongs to your shop and barcode is unique.</p>
                    </div>
                    <button type="submit" class="btn btn-dark mt-3" :disabled="uploading">
                        <template x-if="!uploading"><span><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1 inline"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>Create Preview</span></template>
                        <template x-if="uploading"><span><svg class="animate-spin -ml-1 mr-2 h-4 w-4 inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>Uploading &amp; Validating...</span></template>
                    </button>
                </form>
            </div>
            @endif
        </div>

        <div class="bg-white border border-gray-200 shadow-sm overflow-hidden" style="border-radius:16px;">
            <div class="p-4 border-b border-gray-200 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-gray-900">Import History</h2>
                <span class="text-xs text-gray-500">Latest first</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Reference</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Type</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Status</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Rows</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Valid</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Invalid</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Created</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($imports as $import)
                            @php
                                $statusClass = match($import->status) {
                                    'preview' => 'status-preview',
                                    'queued' => 'status-queued',
                                    'running' => 'status-running',
                                    'completed' => 'status-completed',
                                    'failed' => 'status-failed',
                                    default => 'status-preview'
                                };
                            @endphp
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3 text-sm text-gray-900 font-semibold">{{ $import->import_reference ?? 'PENDING-REF' }}</td>
                                <td class="px-4 py-3 text-sm text-gray-700">{{ ucfirst($import->type) }}</td>
                                <td class="px-4 py-3 text-sm">
                                    <span class="status-pill {{ $statusClass }}">{{ $import->status }}</span>
                                </td>
                                <td class="px-4 py-3 text-sm text-right text-gray-700">{{ $import->total_rows }}</td>
                                <td class="px-4 py-3 text-sm text-right text-green-700 font-semibold">{{ $import->valid_rows }}</td>
                                <td class="px-4 py-3 text-sm text-right text-red-700 font-semibold">{{ $import->invalid_rows }}</td>
                                <td class="px-4 py-3 text-sm text-gray-700">{{ $import->created_at?->format('d M Y, h:i A') }}</td>
                                <td class="px-4 py-3 text-sm text-right">
                                    <a href="{{ route('imports.show', $import) }}" class="btn btn-secondary btn-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>Open</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-8">
                                    <x-empty-state
                                        compact
                                        title="No imports yet"
                                        description="Start with a sample template above."
                                    />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-4 border-t border-gray-200">{{ $imports->links() }}</div>
        </div>
    </div>
</x-app-layout>
