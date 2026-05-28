<x-app-layout>
    <x-page-header title="Send Item for Repair" />

    <div class="max-w-2xl mx-auto px-4 pb-16 pt-6">

        {{-- Return context banner --}}
        @if(isset($repairContext) && $repairContext)
        @php $rc = $repairContext; @endphp
        <div class="mb-6 rounded-xl border border-blue-200 bg-blue-50 px-5 py-4">
            <p class="text-sm font-semibold text-blue-900">
                Sending returned item for repair/rework:
                <strong>{{ $rc->item?->barcode }}</strong>
                ({{ $rc->item?->design ?? 'item' }},
                {{ number_format((float) $rc->item?->gross_weight, 3) }}g,
                {{ $rc->item?->purity_label }})
            </p>
            <p class="text-xs text-blue-700 mt-1">
                From return for {{ $rc->returnLineItem?->returnOrder?->invoice?->customer?->name ?? '—' }}
                · Invoice {{ $rc->returnLineItem?->returnOrder?->invoice?->invoice_number ?? '—' }}.
            </p>
        </div>
        @endif

        @if(session('error'))
        <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-5 py-3 text-sm text-red-800">
            {{ session('error') }}
        </div>
        @endif

        <form method="POST" action="{{ route('job-orders.repair.store') }}">
            @csrf

            @if(isset($repairContext) && $repairContext)
                <input type="hidden" name="return_disposition_id" value="{{ $repairContext->id }}">
                <input type="hidden" name="item_barcode" value="{{ $repairContext->item?->barcode }}">
            @endif

            <div class="rounded-xl border border-slate-200 bg-white overflow-hidden mb-4">
                <div class="px-5 py-4 border-b border-slate-100 bg-slate-50">
                    <h2 class="text-sm font-semibold text-slate-700">Item to Repair</h2>
                </div>
                <div class="px-5 py-4">
                    @if(isset($repairContext) && $repairContext)
                    <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm">
                        <p class="font-medium text-slate-800">{{ $repairContext->item?->barcode }}</p>
                        <p class="text-slate-500 mt-0.5">
                            {{ $repairContext->item?->design ?? '—' }}
                            · {{ $repairContext->item?->category ?? '—' }}
                            · {{ number_format((float) $repairContext->item?->gross_weight, 3) }}g gross
                            · {{ $repairContext->item?->purity_label }}
                        </p>
                    </div>
                    @else
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Item Barcode <span class="text-red-500">*</span></label>
                        <input type="text" name="item_barcode"
                               value="{{ old('item_barcode') }}"
                               placeholder="Scan or type barcode"
                               class="w-full rounded-lg border @error('item_barcode') border-red-400 @else border-slate-300 @enderror px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        @error('item_barcode')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                        <p class="text-xs text-slate-400 mt-1">Item must be in stock, pending restock, or a returned item.</p>
                    </div>
                    @endif
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white overflow-hidden mb-4">
                <div class="px-5 py-4 border-b border-slate-100 bg-slate-50">
                    <h2 class="text-sm font-semibold text-slate-700">Karigar &amp; Schedule</h2>
                </div>
                <div class="px-5 py-4 grid grid-cols-2 gap-4">
                    <div class="col-span-2">
                        <label class="block text-xs font-medium text-slate-600 mb-1">Karigar <span class="text-red-500">*</span></label>
                        <select name="karigar_id"
                                class="w-full rounded-lg border @error('karigar_id') border-red-400 @else border-slate-300 @enderror px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Select karigar…</option>
                            @foreach($karigars as $k)
                            <option value="{{ $k->id }}" {{ old('karigar_id') == $k->id ? 'selected' : '' }}>
                                {{ $k->name }}
                            </option>
                            @endforeach
                        </select>
                        @error('karigar_id')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Issue Date <span class="text-red-500">*</span></label>
                        <input type="date" name="issue_date"
                               value="{{ old('issue_date', now()->toDateString()) }}"
                               class="w-full rounded-lg border @error('issue_date') border-red-400 @else border-slate-300 @enderror px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        @error('issue_date')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Expected Return</label>
                        <input type="date" name="expected_return_date"
                               value="{{ old('expected_return_date') }}"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>

                    <div class="col-span-2">
                        <label class="block text-xs font-medium text-slate-600 mb-1">Notes</label>
                        <textarea name="notes" rows="2"
                                  class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">{{ old('notes', isset($repairContext) && $repairContext ? 'Repair of returned item ' . ($repairContext->item?->barcode ?? '') : '') }}</textarea>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-amber-100 bg-amber-50 px-5 py-3 mb-6 text-xs text-amber-800">
                <strong>Repair job:</strong> No gold leaves the vault. The item is marked as being with the karigar
                until you record its return. Making charges are settled via karigar invoice as usual.
            </div>

            <div class="flex gap-3">
                <button type="submit"
                        class="px-5 py-2 rounded-lg bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    Issue Repair Job
                </button>
                <a href="{{ route('job-orders.index') }}"
                   class="px-5 py-2 rounded-lg border border-slate-300 text-slate-600 text-sm font-medium hover:bg-slate-50">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</x-app-layout>
