<x-app-layout>
    @php
        $metalType = $line->metal_type;
        $purity    = (float) $line->purity;
        $gross     = (float) $line->gross_weight;
        $fineWeight = $metalType === 'silver'
            ? round($gross * ($purity / 1000), 6)
            : round($gross * ($purity / 24), 6);
        $purityLabel = rtrim(rtrim(number_format($purity, 2), '0'), '.') . ($metalType === 'silver' ? '‰' : 'K');
    @endphp

    <x-page-header
        title="Add Bullion to Vault"
        :subtitle="$purchase->purchase_number . ' · Line ' . ($line->sort_order + 1) . ' · ' . number_format($gross, 3) . 'g ' . ucfirst($metalType) . ' ' . $purityLabel">
        <x-slot:actions>
            <a href="{{ route('inventory.purchases.show', $purchase) }}" class="btn btn-secondary btn-sm">← Back to Purchase</a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner max-w-2xl">
        <x-app-alerts class="mb-4" />

        {{-- Line summary --}}
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-6 grid grid-cols-3 gap-4 text-sm">
            <div>
                <div class="text-[10px] uppercase tracking-wide text-amber-700 font-semibold">Metal / Purity</div>
                <div class="font-semibold text-amber-900 mt-0.5 capitalize">{{ $metalType }} {{ $purityLabel }}</div>
            </div>
            <div>
                <div class="text-[10px] uppercase tracking-wide text-amber-700 font-semibold">Gross Weight</div>
                <div class="font-mono font-semibold text-amber-900 mt-0.5">{{ number_format($gross, 3) }}g</div>
            </div>
            <div>
                <div class="text-[10px] uppercase tracking-wide text-amber-700 font-semibold">Fine Weight</div>
                <div class="font-mono font-bold text-amber-900 mt-0.5">{{ number_format($fineWeight, 3) }}g</div>
            </div>
        </div>

        <form method="POST" action="{{ route('inventory.purchases.vault-line', [$purchase, $line]) }}"
              x-data="{ action: 'new_lot' }">
            @csrf

            {{-- Toggle --}}
            <div class="bg-white border border-gray-200 rounded-xl p-5 mb-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-3">Vault Action</div>
                <div class="flex gap-3">
                    <label class="flex-1 flex items-start gap-3 border-2 rounded-xl p-3 cursor-pointer transition"
                           :class="action === 'new_lot' ? 'border-amber-400 bg-amber-50' : 'border-gray-200 hover:border-gray-300'">
                        <input type="radio" name="vault_action" value="new_lot" x-model="action" class="mt-0.5">
                        <div>
                            <div class="text-sm font-semibold text-gray-800">Create New Lot</div>
                            <div class="text-xs text-gray-500 mt-0.5">Opens a fresh vault lot for this bullion</div>
                        </div>
                    </label>
                    <label class="flex-1 flex items-start gap-3 border-2 rounded-xl p-3 cursor-pointer transition"
                           :class="action === 'existing_lot' ? 'border-teal-400 bg-teal-50' : 'border-gray-200 hover:border-gray-300'">
                        <input type="radio" name="vault_action" value="existing_lot" x-model="action" class="mt-0.5">
                        <div>
                            <div class="text-sm font-semibold text-gray-800">Add to Existing Lot</div>
                            <div class="text-xs text-gray-500 mt-0.5">Merge into an existing vault lot</div>
                        </div>
                    </label>
                </div>
            </div>

            {{-- New lot: auto-filled info card --}}
            <div x-show="action === 'new_lot'" x-cloak
                 class="bg-white border border-gray-200 rounded-xl p-5 mb-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-3">New Lot Details</div>
                <div class="grid grid-cols-2 gap-4 text-sm mb-4">
                    <div>
                        <div class="text-[10px] uppercase tracking-wide text-gray-500 font-semibold">Source</div>
                        <div class="mt-0.5 text-gray-800">Purchase</div>
                    </div>
                    <div>
                        <div class="text-[10px] uppercase tracking-wide text-gray-500 font-semibold">Supplier / Vendor</div>
                        <div class="mt-0.5 text-gray-800">{{ $purchase->supplier_label ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-[10px] uppercase tracking-wide text-gray-500 font-semibold">Metal Type</div>
                        <div class="mt-0.5 text-gray-800 capitalize">{{ $metalType }}</div>
                    </div>
                    <div>
                        <div class="text-[10px] uppercase tracking-wide text-gray-500 font-semibold">Purity</div>
                        <div class="mt-0.5 text-gray-800 font-mono">{{ $purityLabel }}</div>
                    </div>
                    <div>
                        <div class="text-[10px] uppercase tracking-wide text-gray-500 font-semibold">Fine Weight</div>
                        <div class="mt-0.5 text-gray-800 font-mono font-semibold">{{ number_format($fineWeight, 3) }}g</div>
                    </div>
                    @if($line->purchase_rate_per_gram > 0)
                    <div>
                        <div class="text-[10px] uppercase tracking-wide text-gray-500 font-semibold">Cost / Fine Gram</div>
                        <div class="mt-0.5 text-gray-800 font-mono">
                            ₹{{ $fineWeight > 0 ? number_format((float)$line->purchase_line_amount / $fineWeight, 2) : '—' }}
                        </div>
                    </div>
                    @endif
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Notes <span class="text-gray-400 font-normal">(optional)</span></label>
                    <textarea name="notes" rows="2"
                              class="w-full rounded-lg border-gray-300 text-sm focus:ring-amber-400 focus:border-amber-400"
                              placeholder="E.g. BIS hallmarked bar, assay certificate #...">{{ old('notes') }}</textarea>
                </div>
            </div>

            {{-- Existing lot dropdown --}}
            <div x-show="action === 'existing_lot'" x-cloak
                 class="bg-white border border-gray-200 rounded-xl p-5 mb-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-3">Choose Existing Lot</div>
                @if($existingLots->isEmpty())
                    <p class="text-sm text-gray-500">No existing {{ $metalType }} lots in your vault. Choose "Create New Lot" instead.</p>
                @else
                    <div class="space-y-2">
                        @foreach($existingLots as $lot)
                            <label class="flex items-center gap-3 border rounded-xl px-4 py-3 cursor-pointer hover:border-teal-300 hover:bg-teal-50 transition"
                                   :class="$el.querySelector('input').checked ? 'border-teal-400 bg-teal-50' : 'border-gray-200'">
                                <input type="radio" name="metal_lot_id" value="{{ $lot->id }}"
                                       {{ old('metal_lot_id') == $lot->id ? 'checked' : '' }}
                                       class="text-teal-600">
                                <div class="flex-1 min-w-0">
                                    <span class="font-mono font-semibold text-teal-700">Lot #{{ $lot->lot_number }}</span>
                                    <span class="text-xs text-gray-500 ml-2 capitalize">{{ $lot->metal_type }}</span>
                                    <span class="text-xs text-gray-500 ml-1">
                                        {{ rtrim(rtrim(number_format((float)$lot->purity, 2), '0'), '.') }}{{ $lot->metal_type === 'silver' ? '‰' : 'K' }}
                                    </span>
                                </div>
                                <div class="text-right">
                                    <div class="text-xs font-semibold text-emerald-700 font-mono">{{ number_format($lot->fine_weight_remaining, 3) }}g</div>
                                    <div class="text-[10px] text-gray-400">remaining</div>
                                </div>
                            </label>
                        @endforeach
                    </div>
                    @error('metal_lot_id')
                        <p class="text-xs text-red-600 mt-2">{{ $message }}</p>
                    @enderror
                @endif
            </div>

            @error('vault_action')
                <p class="text-xs text-red-600 mb-3">{{ $message }}</p>
            @enderror

            <div class="flex gap-3">
                <button type="submit"
                        class="btn btn-success btn-sm px-6">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    Add to Vault
                </button>
                <a href="{{ route('inventory.purchases.show', $purchase) }}" class="btn btn-secondary btn-sm">Cancel</a>
            </div>
        </form>
    </div>
</x-app-layout>
