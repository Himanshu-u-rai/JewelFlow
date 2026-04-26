<x-app-layout>
    <x-page-header title="Add Bullion to Vault" subtitle="Record a fresh purchase, buyback, or opening stock entry" />

    <div class="content-inner">
        <x-app-alerts class="mb-4" />

        <form method="POST" action="{{ route('vault.lots.store') }}" class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 max-w-3xl"
              x-data="{ gross: 0, purity: 22, costPerGram: 0, source: 'purchase', metalType: 'gold',
                        get fine() {
                            if (this.metalType === 'silver') return this.gross * (this.purity / 1000);
                            return this.gross * (this.purity / 24);
                        },
                        get total() { return this.gross * this.costPerGram; } }">
            @csrf

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Metal Type *</label>
                    <select name="metal_type" required x-model="metalType" class="w-full rounded-md border-gray-300 text-sm">
                        <option value="gold">Gold</option>
                        <option value="silver">Silver</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Source *</label>
                    <select name="source" required x-model="source" class="w-full rounded-md border-gray-300 text-sm">
                        <option value="purchase">Purchase (from supplier)</option>
                        <option value="buyback">Buyback (from customer)</option>
                        <option value="opening">Opening Stock</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Gross Weight (g) *</label>
                    <input type="number" step="0.001" min="0.001" name="gross_weight" required x-model.number="gross" class="w-full rounded-md border-gray-300 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Purity *</label>
                    <input type="number" step="0.01" min="1" :max="metalType === 'silver' ? 1000 : 24" name="purity" required x-model.number="purity" class="w-full rounded-md border-gray-300 text-sm">
                    <p class="text-[10px] text-gray-400 mt-1" x-show="metalType === 'gold'">Karat: 24 = pure gold, 22K, 20K, 18K, 14K</p>
                    <p class="text-[10px] text-gray-400 mt-1" x-show="metalType === 'silver'">Fineness: 999 = fine silver, 925 = sterling</p>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Cost per Gram (₹)</label>
                    <input type="number" step="0.01" min="0" name="cost_per_gram" x-model.number="costPerGram" class="w-full rounded-md border-gray-300 text-sm">
                    <p class="text-[10px] text-gray-400 mt-1">Optional. Used to compute lot's average cost-per-fine-gram.</p>
                </div>
                <div x-show="source !== 'opening'">
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Supplier / Vendor</label>
                    <select name="vendor_id" class="w-full rounded-md border-gray-300 text-sm">
                        <option value="">— select vendor —</option>
                        @foreach($vendors as $vendor)
                            <option value="{{ $vendor->id }}" {{ old('vendor_id') == $vendor->id ? 'selected' : '' }}>{{ $vendor->name }}</option>
                        @endforeach
                    </select>
                    <p class="text-[10px] text-gray-400 mt-1">Optional. <a href="{{ route('vendors.create') }}" class="text-teal-600 hover:underline" target="_blank">Add vendor</a> if not listed.</p>
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Notes</label>
                    <textarea name="notes" rows="2" class="w-full rounded-md border-gray-300 text-sm"></textarea>
                </div>
            </div>

            <div class="bg-amber-50 rounded-lg p-4 mt-4 mb-5">
                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div><div class="text-[10px] uppercase tracking-wide text-amber-700 font-semibold">Fine Weight (computed)</div><div class="font-mono font-bold" x-text="fine.toFixed(3) + 'g'">0.000g</div></div>
                    <div><div class="text-[10px] uppercase tracking-wide text-amber-700 font-semibold">Total Cost</div><div class="font-mono font-bold" x-text="'₹' + total.toLocaleString('en-IN', { minimumFractionDigits: 2 })">₹0.00</div></div>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="btn btn-success btn-sm">Add to Vault</button>
                <a href="{{ route('vault.index') }}" class="text-sm text-gray-500">Cancel</a>
            </div>
        </form>
    </div>
</x-app-layout>
