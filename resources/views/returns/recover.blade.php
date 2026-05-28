<x-app-layout>
    <x-page-header
        title="Record Gold Recovery"
        subtitle="Add recovered fine gold from a returned item back into your vault">
        <x-slot:actions>
            <a href="{{ route('returns.control-center') }}"
               class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                Control Center
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner">

        @if($errors->any())
            <div class="mb-5 rounded-xl border border-rose-200 bg-rose-50 px-5 py-4">
                <p class="text-sm font-semibold text-rose-700">Please fix the following errors:</p>
                <ul class="mt-1 list-disc list-inside text-sm text-rose-600">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            {{-- Left column: Item & return context (read-only) --}}
            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <h2 class="text-sm font-bold uppercase tracking-[0.18em] text-slate-500 mb-4">Recovery Details</h2>

                @php
                    $ro     = $disposition->returnLineItem?->returnOrder;
                    $inv    = $ro?->invoice;
                    $cust   = $ro?->customer;
                    $custName = $cust ? trim($cust->first_name . ' ' . $cust->last_name) : null;
                    $purityLabel = $item?->purity ? number_format((float)$item->purity, 0) . 'K' : null;
                @endphp

                <dl class="divide-y divide-slate-100">
                    <div class="grid grid-cols-2 gap-2 py-3">
                        <dt class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Item</dt>
                        <dd class="text-sm text-slate-900">
                            @if($item)
                                <span class="font-semibold">{{ $item->barcode ?? '—' }}</span>
                                @if($item->design)
                                    <span class="text-slate-500"> — {{ $item->design }}</span>
                                @endif
                                @if($item->category)
                                    <div class="text-xs text-slate-400 mt-0.5">{{ $item->category }}</div>
                                @endif
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </dd>
                    </div>

                    <div class="grid grid-cols-2 gap-2 py-3">
                        <dt class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Purity</dt>
                        <dd class="text-sm text-slate-900">{{ $purityLabel ?? '—' }}</dd>
                    </div>

                    <div class="grid grid-cols-2 gap-2 py-3">
                        <dt class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Gross weight</dt>
                        <dd class="text-sm text-slate-900">
                            @if($item?->net_metal_weight)
                                {{ number_format((float)$item->net_metal_weight, 2) }}g
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </dd>
                    </div>

                    <div class="grid grid-cols-2 gap-2 py-3">
                        <dt class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Expected fine</dt>
                        <dd class="text-sm">
                            @if($expectedFineWeight !== null)
                                <span class="font-semibold text-emerald-700">{{ number_format($expectedFineWeight, 3) }}g</span>
                                @if($purityLabel && $item?->net_metal_weight)
                                    <span class="text-xs text-slate-400 ml-1">({{ $purityLabel }} × {{ number_format((float)$item->net_metal_weight, 2) }}g)</span>
                                @endif
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </dd>
                    </div>

                    <div class="grid grid-cols-2 gap-2 py-3">
                        <dt class="text-xs font-semibold text-slate-500 uppercase tracking-wider">From return</dt>
                        <dd class="text-sm text-slate-900">
                            @if($ro && $inv)
                                <a href="{{ route('returns.show', $ro) }}" class="font-semibold text-amber-700 hover:underline">RO#{{ $ro->id }}</a>
                                <span class="text-slate-500"> — {{ $inv->invoice_number }}</span>
                            @elseif($ro)
                                <a href="{{ route('returns.show', $ro) }}" class="font-semibold text-amber-700 hover:underline">RO#{{ $ro->id }}</a>
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </dd>
                    </div>

                    <div class="grid grid-cols-2 gap-2 py-3">
                        <dt class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Customer</dt>
                        <dd class="text-sm text-slate-900">{{ $custName ?? '—' }}</dd>
                    </div>

                    <div class="grid grid-cols-2 gap-2 py-3">
                        <dt class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Returned on</dt>
                        <dd class="text-sm text-slate-900">
                            @if($disposition->dispositioned_at)
                                {{ \Carbon\Carbon::parse($disposition->dispositioned_at)->diffForHumans() }}
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </dd>
                    </div>
                </dl>
            </div>

            {{-- Right column: Recovery form --}}
            <div class="rounded-2xl border border-amber-200 bg-white p-6">
                <h2 class="text-sm font-bold uppercase tracking-[0.18em] text-slate-500 mb-4">Record Recovery</h2>

                <form method="POST"
                      action="{{ route('returns.items.recover.store', $disposition) }}"
                      onsubmit="return confirm('Record gold recovery? This cannot be undone.')">
                    @csrf

                    {{-- Actual gross weight --}}
                    <div class="mb-5">
                        <label for="actual_gross_weight" class="block text-sm font-semibold text-slate-700 mb-1">
                            Actual gross weight recovered (g) <span class="text-rose-500">*</span>
                        </label>
                        <input type="number"
                               id="actual_gross_weight"
                               name="actual_gross_weight"
                               step="0.001"
                               min="0.001"
                               max="10000"
                               value="{{ old('actual_gross_weight') }}"
                               class="block w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm text-slate-900 focus:border-amber-400 focus:ring-2 focus:ring-amber-100 @error('actual_gross_weight') border-rose-400 @enderror"
                               placeholder="e.g. 8.400"
                               required>
                        <p class="mt-1 text-xs text-slate-400">Weigh the melted gold before recording</p>
                        @error('actual_gross_weight')
                            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Actual purity --}}
                    <div class="mb-5">
                        <label for="actual_purity" class="block text-sm font-semibold text-slate-700 mb-1">
                            Purity confirmed (K) <span class="text-rose-500">*</span>
                        </label>
                        <input type="number"
                               id="actual_purity"
                               name="actual_purity"
                               step="0.1"
                               min="1"
                               max="24"
                               value="{{ old('actual_purity', $item?->purity ? (float)$item->purity : '') }}"
                               class="block w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm text-slate-900 focus:border-amber-400 focus:ring-2 focus:ring-amber-100 @error('actual_purity') border-rose-400 @enderror"
                               placeholder="e.g. 22"
                               required>
                        <p class="mt-1 text-xs text-slate-400">Usually same as original; adjust if assay differs</p>
                        @error('actual_purity')
                            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Live-calculated fine weight + wastage --}}
                    <div class="mb-5 rounded-xl border border-slate-100 bg-slate-50 px-4 py-3 space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Actual fine weight</span>
                            <span id="calc_fine" class="text-sm font-bold text-slate-900">—</span>
                        </div>
                        @if($expectedFineWeight !== null)
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Wastage / loss</span>
                                <span id="calc_wastage" class="text-sm text-slate-500">—</span>
                            </div>
                        @endif
                    </div>

                    {{-- Hidden expected fine value for JS --}}
                    <span id="expected_fine"
                          data-value="{{ $expectedFineWeight ?? 0 }}"
                          class="hidden"></span>

                    {{-- Target lot --}}
                    <div class="mb-5">
                        <label for="target_lot_id" class="block text-sm font-semibold text-slate-700 mb-1">
                            Add recovered gold to lot <span class="text-rose-500">*</span>
                        </label>
                        @if($goldLots->isEmpty())
                            <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                                No active gold lots found. Please create a gold lot in the vault before recording recovery.
                            </div>
                        @else
                            <select id="target_lot_id"
                                    name="target_lot_id"
                                    class="block w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm text-slate-900 focus:border-amber-400 focus:ring-2 focus:ring-amber-100 @error('target_lot_id') border-rose-400 @enderror"
                                    required>
                                <option value="">— Select a lot —</option>
                                @foreach($goldLots as $lot)
                                    <option value="{{ $lot->id }}"
                                            {{ old('target_lot_id') == $lot->id ? 'selected' : '' }}>
                                        Lot #{{ $lot->lot_number }} — {{ number_format((float)$lot->purity, 0) }}K — {{ number_format((float)$lot->fine_weight_remaining, 3) }}g remaining
                                    </option>
                                @endforeach
                            </select>
                        @endif
                        @error('target_lot_id')
                            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Notes --}}
                    <div class="mb-6">
                        <label for="notes" class="block text-sm font-semibold text-slate-700 mb-1">
                            Notes <span class="text-slate-400 font-normal">(optional)</span>
                        </label>
                        <textarea id="notes"
                                  name="notes"
                                  rows="3"
                                  maxlength="500"
                                  class="block w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm text-slate-900 focus:border-amber-400 focus:ring-2 focus:ring-amber-100 @error('notes') border-rose-400 @enderror"
                                  placeholder="Any observations about the melt, e.g. solder content, stone residue…">{{ old('notes') }}</textarea>
                        @error('notes')
                            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <button type="submit"
                            {{ $goldLots->isEmpty() ? 'disabled' : '' }}
                            class="inline-flex items-center gap-2 rounded-xl bg-amber-600 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-amber-700 disabled:opacity-50 disabled:cursor-not-allowed">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M12 4v16m8-8H4"/>
                        </svg>
                        Record Recovery
                    </button>
                </form>
            </div>

        </div>
    </div>

    <script>
        function recalc() {
            const gross  = parseFloat(document.getElementById('actual_gross_weight').value) || 0;
            const purity = parseFloat(document.getElementById('actual_purity').value) || 0;
            const fine   = gross * (purity / 24);
            document.getElementById('calc_fine').textContent = fine > 0 ? fine.toFixed(3) + 'g' : '—';
            // wastage vs expected
            const expected = parseFloat(document.getElementById('expected_fine').dataset.value) || 0;
            if (expected > 0 && fine > 0) {
                const wastage = expected - fine;
                const pct = (wastage / expected) * 100;
                const el = document.getElementById('calc_wastage');
                if (el) {
                    el.textContent = wastage.toFixed(3) + 'g (' + pct.toFixed(1) + '%)';
                    el.className = pct > 5 ? 'text-amber-600 font-semibold' : 'text-slate-500';
                }
            }
        }
        document.getElementById('actual_gross_weight').addEventListener('input', recalc);
        document.getElementById('actual_purity').addEventListener('input', recalc);
        // Run once on load in case old() values are present
        recalc();
    </script>
</x-app-layout>
