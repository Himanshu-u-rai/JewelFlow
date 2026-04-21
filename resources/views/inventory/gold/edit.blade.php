<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Edit Gold Lot #{{ $lot->lot_number ?? '—' }}</h1>
            <p class="text-sm text-gray-500 mt-1">Update source and cost information</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('inventory.gold.show', $lot) }}" class="btn btn-secondary btn-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>Back to Lot</a>
        </div>
    </x-page-header>
    <div class="max-w-2xl mx-auto bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="p-5 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">Edit Gold Lot</h2>
                <p class="text-sm text-gray-500 mt-1">Modify source and pricing metadata only.</p>
            </div>

            <form method="POST" action="{{ route('inventory.gold.update', $lot) }}" class="p-6 space-y-6">
                @csrf
                @method('PUT')

                <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                    <h4 class="font-medium text-gray-700 mb-3">Gold Details (Read-only)</h4>
                    <div class="grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <p class="text-gray-500">Purity</p>
                            <p class="font-semibold text-gray-900">{{ $lot->purity }}K</p>
                        </div>
                        <div>
                            <p class="text-gray-500">Total Fine Gold</p>
                            <p class="font-semibold text-gray-900">{{ number_format($lot->fine_weight_total, 3) }} g</p>
                        </div>
                        <div>
                            <p class="text-gray-500">Remaining</p>
                            <p class="font-semibold text-gray-900">{{ number_format($lot->fine_weight_remaining, 3) }} g</p>
                        </div>
                        <div>
                            <p class="text-gray-500">Created</p>
                            <p class="font-semibold text-gray-900">{{ $lot->created_at->format('d M Y') }}</p>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-3">Source Type *</label>
                    <div class="grid grid-cols-3 gap-3">
                        <label class="cursor-pointer">
                            <input type="radio" name="source" value="purchase" class="source-radio peer sr-only" {{ old('source', $lot->source) == 'purchase' ? 'checked' : '' }}>
                            <div class="source-choice-card p-3 border rounded-lg text-center hover:bg-gray-50 transition">
                                <div class="font-medium text-gray-900">Purchase</div>
                                <div class="text-xs text-gray-500">From supplier</div>
                            </div>
                        </label>
                        <label class="cursor-pointer">
                            <input type="radio" name="source" value="buyback" class="source-radio peer sr-only" {{ old('source', $lot->source) == 'buyback' ? 'checked' : '' }}>
                            <div class="source-choice-card p-3 border rounded-lg text-center hover:bg-gray-50 transition">
                                <div class="font-medium text-gray-900">Buyback</div>
                                <div class="text-xs text-gray-500">From customer</div>
                            </div>
                        </label>
                        <label class="cursor-pointer">
                            <input type="radio" name="source" value="opening" class="source-radio peer sr-only" {{ old('source', $lot->source) == 'opening' ? 'checked' : '' }}>
                            <div class="source-choice-card p-3 border rounded-lg text-center hover:bg-gray-50 transition">
                                <div class="font-medium text-gray-900">Opening</div>
                                <div class="text-xs text-gray-500">Existing stock</div>
                            </div>
                        </label>
                    </div>
                    @error('source')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="cost_per_fine_gram" class="block text-sm font-medium text-gray-700 mb-2">Cost per Fine Gram (₹)</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-500">₹</span>
                        <input type="number" step="0.01" name="cost_per_fine_gram" id="cost_per_fine_gram"
                               value="{{ old('cost_per_fine_gram', $lot->cost_per_fine_gram) }}"
                               placeholder="e.g., 6500.00"
                               class="w-full pl-8 rounded-lg border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500">
                    </div>
                    <p class="mt-1 text-sm text-gray-500">Used to value this lot in reports.</p>
                    @error('cost_per_fine_gram')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                @if($lot->cost_per_fine_gram)
                    <div class="bg-gray-50 rounded-lg p-4 border border-gray-200 text-sm text-gray-700">
                        <strong>Estimated Total Value:</strong>
                        ₹{{ number_format($lot->fine_weight_total * $lot->cost_per_fine_gram, 2) }}
                        <span class="text-gray-500">({{ number_format($lot->fine_weight_total, 3) }} g × ₹{{ number_format($lot->cost_per_fine_gram, 2) }})</span>
                    </div>
                @endif

                <div class="flex justify-end gap-3 pt-4 border-t border-gray-200">
                    <a href="{{ route('inventory.gold.show', $lot) }}" class="btn btn-secondary btn-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Cancel</a>
                    <button type="submit" class="btn btn-dark btn-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Ensure source card selection is always visibly highlighted.
        function syncSourceCards() {
            document.querySelectorAll('input[name="source"]').forEach((input) => {
                const card = input.closest('label')?.querySelector('.source-choice-card');
                if (!card) return;
                if (input.checked) {
                    card.classList.add('is-selected');
                    card.style.backgroundColor = '#eef2ff';
                    card.style.borderColor = '#0d9488';
                    card.style.boxShadow = '0 0 0 3px rgba(129, 140, 248, 0.28)';
                } else {
                    card.classList.remove('is-selected');
                    card.style.backgroundColor = '';
                    card.style.borderColor = '';
                    card.style.boxShadow = '';
                }
            });
        }

        document.querySelectorAll('input[name="source"]').forEach((input) => {
            input.addEventListener('change', syncSourceCards);
            input.addEventListener('click', syncSourceCards);
        });

        syncSourceCards();
    </script>
</x-app-layout>
