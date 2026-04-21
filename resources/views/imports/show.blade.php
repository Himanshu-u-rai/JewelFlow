<x-app-layout>
    <x-page-header class="ops-treatment-header">
        <div>
            <h1 class="page-title">Import {{ $import->import_reference ?? 'PENDING-REF' }}</h1>
            <p class="text-sm text-gray-500 mt-1">{{ ucfirst($import->type) }} import • Status: {{ ucfirst($import->status) }}</p>
        </div>
        <div class="page-actions flex gap-2">
            <a href="{{ route('imports.index') }}" class="btn btn-secondary btn-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>Back</a>
            @if($import->error_file_path)
                <a href="{{ route('imports.errors', $import) }}" class="btn btn-secondary btn-sm" data-turbo="false"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Download Errors</a>
            @endif
        </div>
    </x-page-header>

    <div class="content-inner space-y-6 ops-treatment-page">
        @if(session('success'))
            <div class="px-4 py-3 bg-green-50 border border-green-200 text-green-700 text-sm rounded-xl">{{ session('success') }}</div>
        @endif
        @if($errors->any())
            <div class="px-4 py-3 bg-red-50 border border-red-200 text-red-700 text-sm rounded-xl">
                <ul class="list-disc list-inside">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
            <div class="bg-white border border-gray-200 p-4 rounded-xl"><div class="text-xs text-gray-500">Total</div><div class="text-xl font-semibold">{{ $import->total_rows }}</div></div>
            <div class="bg-white border border-gray-200 p-4 rounded-xl"><div class="text-xs text-gray-500">Valid</div><div class="text-xl font-semibold text-green-700">{{ $import->valid_rows }}</div></div>
            <div class="bg-white border border-gray-200 p-4 rounded-xl"><div class="text-xs text-gray-500">Invalid</div><div class="text-xl font-semibold text-red-700">{{ $import->invalid_rows }}</div></div>
            <div class="bg-white border border-gray-200 p-4 rounded-xl"><div class="text-xs text-gray-500">Processed</div><div class="text-xl font-semibold">{{ $import->processed_rows }}</div></div>
            <div class="bg-white border border-gray-200 p-4 rounded-xl"><div class="text-xs text-gray-500">Mode</div><div class="text-xl font-semibold">{{ $import->mode ?: '-' }}</div></div>
        </div>

        @if($import->status === \App\Models\Import::STATUS_PREVIEW)
            <div class="bg-white border border-gray-200 p-4 rounded-xl">
                <h2 class="text-lg font-semibold text-gray-900">Execute Import</h2>
                <p class="text-sm text-gray-500 mt-1">Dry-run completed. Choose execution mode and run.</p>
                <div class="mt-4 flex flex-wrap items-end gap-3">
                    <form method="POST" action="{{ route('imports.execute', $import) }}" class="flex flex-wrap items-end gap-3" x-data="{ submitting: false }" @submit="submitting = true">
                        @csrf
                        <div>
                            <label class="block text-sm text-gray-700 mb-1">Mode</label>
                            <select name="mode" class="border-gray-300 text-sm" required>
                                <option value="strict">Strict (all-or-nothing)</option>
                                <option value="row">Row level (partial with errors)</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-dark" :disabled="submitting">
                            <template x-if="!submitting"><span><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1 inline"><polygon points="5 3 19 12 5 21 5 3"/></svg>Execute</span></template>
                            <template x-if="submitting"><span><svg class="animate-spin -ml-1 mr-2 h-4 w-4 inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>Importing...</span></template>
                        </button>
                    </form>
                    <form method="POST" action="{{ route('imports.cancel', $import) }}" class="flex items-end" data-confirm-message="Cancel this import? This cannot be undone.">
                        @csrf
                        <button type="submit" class="btn btn-secondary text-red-600 border-red-200 hover:bg-red-50"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1 inline"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>Cancel Import</button>
                    </form>
                </div>
            </div>
        @endif

        @if($import->type === \App\Models\Import::TYPE_MANUFACTURE && !empty(data_get($import->preview_summary, 'lot_summary')))
            <div class="bg-white border border-gray-200 overflow-hidden rounded-xl">
                <div class="p-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">Lot Impact Preview</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Lot</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Required Fine</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Available</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase">After Import</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach(data_get($import->preview_summary, 'lot_summary', []) as $lot)
                                <tr>
                                    <td class="px-4 py-3 text-sm">#{{ $lot['lot_number'] ?? '—' }}</td>
                                    <td class="px-4 py-3 text-sm text-right">{{ number_format($lot['required_fine'], 6) }}</td>
                                    <td class="px-4 py-3 text-sm text-right">{{ number_format($lot['available_fine'], 6) }}</td>
                                    <td class="px-4 py-3 text-sm text-right">{{ number_format($lot['after_import_fine'], 6) }}</td>
                                    <td class="px-4 py-3 text-sm text-center">{{ $lot['sufficient'] ? 'OK' : 'Insufficient' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="p-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">Rows</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Row</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Error</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Payload</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($rows as $row)
                            <tr>
                                <td class="px-4 py-3 text-sm">{{ $row->row_number }}</td>
                                <td class="px-4 py-3 text-sm">{{ ucfirst($row->status) }}</td>
                                <td class="px-4 py-3 text-sm text-red-600">{{ $row->error_message }}</td>
                                <td class="px-4 py-3 text-sm text-gray-700">
                                    @php
                                        $payload = (array) ($row->payload ?? []);
                                        $computed = (array) ($row->computed ?? []);
                                    @endphp

                                    @if($import->type === \App\Models\Import::TYPE_CATALOG)
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                                            <div><span class="text-xs text-gray-500">Design</span><div class="font-medium text-gray-900">{{ $payload['design_code'] ?? '—' }}</div></div>
                                            <div><span class="text-xs text-gray-500">Name</span><div class="font-medium text-gray-900">{{ $payload['name'] ?? '—' }}</div></div>
                                            <div><span class="text-xs text-gray-500">Category</span><div>{{ $payload['category'] ?? '—' }}</div></div>
                                            <div><span class="text-xs text-gray-500">Sub Category</span><div>{{ $payload['sub_category'] ?? '—' }}</div></div>
                                            <div><span class="text-xs text-gray-500">Purity</span><div>{{ $payload['default_purity'] ?? '—' }}</div></div>
                                            <div><span class="text-xs text-gray-500">Approx Weight</span><div>{{ $payload['approx_weight'] ?? '—' }}</div></div>
                                            <div><span class="text-xs text-gray-500">Default Making</span><div>{{ $payload['default_making'] ?? '—' }}</div></div>
                                            <div><span class="text-xs text-gray-500">Stone Type</span><div>{{ $payload['stone_type'] ?? '—' }}</div></div>
                                        </div>
                                        @if(!empty($computed['will_create_category']) || !empty($computed['will_create_sub_category']))
                                            <div class="mt-2 flex flex-wrap gap-2">
                                                @if(!empty($computed['will_create_category']))
                                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-amber-100 text-amber-800">Will create category</span>
                                                @endif
                                                @if(!empty($computed['will_create_sub_category']))
                                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-blue-100 text-blue-800">Will create sub-category</span>
                                                @endif
                                            </div>
                                        @endif
                                    @else
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                                            <div><span class="text-xs text-gray-500">Barcode</span><div class="font-medium text-gray-900">{{ $payload['barcode'] ?? '—' }}</div></div>
                                            <div><span class="text-xs text-gray-500">Design Code</span><div>{{ $payload['design_code'] ?? '—' }}</div></div>
                                            <div><span class="text-xs text-gray-500">Lot</span><div>{{ $payload['lot_number'] ?? '—' }}</div></div>
                                            <div><span class="text-xs text-gray-500">Gross Weight</span><div>{{ $payload['gross_weight'] ?? '—' }}</div></div>
                                            <div><span class="text-xs text-gray-500">Stone Weight</span><div>{{ $payload['stone_weight'] ?? '—' }}</div></div>
                                            <div><span class="text-xs text-gray-500">Purity</span><div>{{ $payload['purity'] ?? '—' }}</div></div>
                                            <div><span class="text-xs text-gray-500">Wastage %</span><div>{{ $payload['wastage_percent'] ?? '—' }}</div></div>
                                            <div><span class="text-xs text-gray-500">Making</span><div>{{ $payload['making_charge'] ?? '—' }}</div></div>
                                        </div>
                                        @if(!empty($computed['will_create_category']) || !empty($computed['will_create_sub_category']))
                                            <div class="mt-2 flex flex-wrap gap-2">
                                                @if(!empty($computed['will_create_category']))
                                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-amber-100 text-amber-800">Will create category</span>
                                                @endif
                                                @if(!empty($computed['will_create_sub_category']))
                                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-blue-100 text-blue-800">Will create sub-category</span>
                                                @endif
                                            </div>
                                        @endif
                                    @endif

                                    <details class="mt-2">
                                        <summary class="text-xs text-gray-500 cursor-pointer hover:text-gray-700">View raw payload</summary>
                                        <pre class="mt-1 text-xs text-gray-500 whitespace-pre-wrap">{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </details>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-8 text-sm text-center text-gray-500">No rows</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-4 border-t border-gray-200">{{ $rows->links() }}</div>
        </div>
    </div>
</x-app-layout>
