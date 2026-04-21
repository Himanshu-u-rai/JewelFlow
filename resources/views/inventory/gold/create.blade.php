<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Add Gold to Inventory</h1>
            <p class="text-sm text-gray-500 mt-1">Purchase, buyback, or opening stock</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('inventory.gold.index') }}" class="btn btn-secondary btn-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>Back to Inventory</a>
        </div>
    </x-page-header>
    <div class="max-w-2xl mx-auto bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="p-5 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">Gold Entry Form</h2>
                <p class="text-sm text-gray-500 mt-1">Enter details of gold being added.</p>
            </div>

            <form method="POST" action="{{ route('inventory.gold.store') }}" class="p-6 space-y-6">
                @csrf

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-3">Source Type *</label>
                    <div class="grid grid-cols-3 gap-3">
                        <label class="cursor-pointer">
                            <input type="radio" name="source" value="purchase" class="source-radio peer sr-only" {{ old('source', 'purchase') == 'purchase' ? 'checked' : '' }}>
                            <div class="source-choice-card p-3 border rounded-lg text-center hover:bg-gray-50 transition">
                                <div class="font-medium text-gray-900">Purchase</div>
                                <div class="text-xs text-gray-500">From supplier</div>
                            </div>
                        </label>
                        <label class="cursor-pointer">
                            <input type="radio" name="source" value="buyback" class="source-radio peer sr-only" {{ old('source') == 'buyback' ? 'checked' : '' }}>
                            <div class="source-choice-card p-3 border rounded-lg text-center hover:bg-gray-50 transition">
                                <div class="font-medium text-gray-900">Buyback</div>
                                <div class="text-xs text-gray-500">From customer</div>
                            </div>
                        </label>
                        <label class="cursor-pointer">
                            <input type="radio" name="source" value="opening" class="source-radio peer sr-only" {{ old('source') == 'opening' ? 'checked' : '' }}>
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

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="gross_weight" class="block text-sm font-medium text-gray-700 mb-2">Gross Weight (grams) *</label>
                        <input type="number" step="0.001" name="gross_weight" id="gross_weight"
                               value="{{ old('gross_weight') }}"
                               placeholder="e.g., 100.000"
                               class="w-full rounded-lg border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500"
                               required>
                        @error('gross_weight')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="purity" class="block text-sm font-medium text-gray-700 mb-2">Purity (Karat) *</label>
                        <select name="purity" id="purity"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500"
                                required>
                            <option value="">Select Purity</option>
                            <option value="24" {{ old('purity') == '24' ? 'selected' : '' }}>24K (Pure Gold)</option>
                            <option value="22" {{ old('purity') == '22' ? 'selected' : '' }}>22K</option>
                            <option value="21" {{ old('purity') == '21' ? 'selected' : '' }}>21K</option>
                            <option value="18" {{ old('purity') == '18' ? 'selected' : '' }}>18K</option>
                            <option value="14" {{ old('purity') == '14' ? 'selected' : '' }}>14K</option>
                        </select>
                        @error('purity')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-gray-600">Calculated Fine Gold:</span>
                        <span class="text-xl font-semibold text-gray-900" id="fineGoldPreview">0.000 g</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">Fine Gold = Gross Weight × (Purity / 24)</p>
                </div>

                <div>
                    <label for="cost_per_gram" class="block text-sm font-medium text-gray-700 mb-2">Cost per Gram (₹) <span class="text-gray-400">(optional)</span></label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-500">₹</span>
                        <input type="number" step="0.01" name="cost_per_gram" id="cost_per_gram"
                               value="{{ old('cost_per_gram') }}"
                               placeholder="e.g., 6500.00"
                               class="w-full pl-8 rounded-lg border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500">
                    </div>
                    @error('cost_per_gram')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-gray-600">Total Cost:</span>
                        <span class="text-xl font-semibold text-gray-900" id="totalCostPreview">₹ 0.00</span>
                    </div>
                </div>

                <div>
                    <label for="supplier_name" class="block text-sm font-medium text-gray-700 mb-2">Supplier / Source Name <span class="text-gray-400">(optional)</span></label>
                    <input type="text" name="supplier_name" id="supplier_name"
                           value="{{ old('supplier_name') }}"
                           placeholder="e.g., ABC Gold Suppliers"
                           class="w-full rounded-lg border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500">
                </div>

                <div>
                    <label for="notes" class="block text-sm font-medium text-gray-700 mb-2">Notes <span class="text-gray-400">(optional)</span></label>
                    <textarea name="notes" id="notes" rows="2"
                              placeholder="Any additional notes..."
                              class="w-full rounded-lg border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500">{{ old('notes') }}</textarea>
                </div>

                <div class="flex justify-end gap-3 pt-4 border-t border-gray-200">
                    <a href="{{ route('inventory.gold.index') }}" class="btn btn-secondary btn-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Cancel</a>
                    <button type="submit" class="btn btn-dark btn-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>Add Gold to Inventory</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function updateCalculations() {
            const grossWeight = parseFloat(document.getElementById('gross_weight').value) || 0;
            const purity = parseFloat(document.getElementById('purity').value) || 0;
            const costPerGram = parseFloat(document.getElementById('cost_per_gram').value) || 0;

            const fineGold = grossWeight * (purity / 24);
            const totalCost = grossWeight * costPerGram;

            document.getElementById('fineGoldPreview').textContent = fineGold.toFixed(3) + ' g';
            document.getElementById('totalCostPreview').textContent = '₹ ' + totalCost.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        document.getElementById('gross_weight').addEventListener('input', updateCalculations);
        document.getElementById('purity').addEventListener('change', updateCalculations);
        document.getElementById('cost_per_gram').addEventListener('input', updateCalculations);
        updateCalculations();

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
