<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Add Gold Advance</h1>
            <p class="text-sm text-gray-500 mt-1">Customer: {{ $customer->first_name }} {{ $customer->last_name }}</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('customers.show', $customer->id) }}" class="btn btn-secondary btn-sm">
                <x-icon name="arrow-left" size="14" class="mr-1" />Back to Customer
            </a>
        </div>
    </x-page-header>

    <div class="content-inner">
        <div class="max-w-2xl">
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <form method="POST" action="{{ route('customers.gold.store', $customer->id) }}" class="space-y-5">
                    @csrf

                    {{-- Hidden customer id so server knows which customer to credit --}}
                    <input type="hidden" name="customer_id" value="{{ $customer->id }}">

                    {{-- Flash / validation messages --}}
@if($errors->any())
                        <div class="mb-2 p-3 bg-red-100 text-red-800 rounded">
                            <ul class="list-disc list-inside text-sm">
                                @foreach($errors->all() as $err)
                                    <li>{{ $err }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Gross Weight (grams)</label>
                        <input type="number" step="0.001" name="gross_weight" value="{{ old('gross_weight') }}" required
                            class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                    </div>

                    @php
                        $purityOptions = [24, 22, 20, 18, 16];
                        $oldPurity = old('purity');
                        $useCustomPurity = $oldPurity !== null && !in_array((string) $oldPurity, array_map('strval', $purityOptions), true);
                    @endphp
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Purity</label>
                        <select id="purity_select"
                                name="{{ $useCustomPurity ? 'purity_select' : 'purity' }}"
                                required
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                            @foreach($purityOptions as $option)
                                <option value="{{ $option }}" {{ (string) old('purity', '24') === (string) $option ? 'selected' : '' }}>
                                    {{ $option }}K
                                </option>
                            @endforeach
                            <option value="custom" {{ $useCustomPurity ? 'selected' : '' }}>Custom Purity</option>
                        </select>

                        <div id="custom_purity_wrap" class="{{ $useCustomPurity ? '' : 'hidden' }} mt-3">
                            <label for="custom_purity" class="block text-sm font-medium text-gray-700 mb-2">Custom Purity (K)</label>
                            <input type="number"
                                   step="0.01"
                                   min="1"
                                   max="24"
                                   id="custom_purity"
                                   name="{{ $useCustomPurity ? 'purity' : 'custom_purity' }}"
                                   value="{{ $useCustomPurity ? old('purity') : '' }}"
                                   placeholder="e.g., 19.5"
                                   class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                            <p class="text-xs text-gray-500 mt-1">Enter a value between 1 and 24.</p>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Notes (optional)</label>
                        <textarea name="notes" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500" rows="3"></textarea>
                    </div>

                    <div class="flex gap-3 justify-end pt-2">
                        <a href="{{ route('customers.show', $customer->id) }}" class="btn btn-secondary">
                            <x-icon name="x" size="14" class="mr-1" />Cancel
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <x-icon name="circle-plus" size="14" class="mr-1" />Add Gold
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const select = document.getElementById('purity_select');
            const wrap = document.getElementById('custom_purity_wrap');
            const customInput = document.getElementById('custom_purity');

            if (!select || !wrap || !customInput) return;

            function syncPurityInputs() {
                const isCustom = select.value === 'custom';

                if (isCustom) {
                    wrap.classList.remove('hidden');
                    customInput.name = 'purity';
                    customInput.required = true;
                    select.name = 'purity_select';
                } else {
                    wrap.classList.add('hidden');
                    customInput.name = 'custom_purity';
                    customInput.required = false;
                    select.name = 'purity';
                }
            }

            select.addEventListener('change', syncPurityInputs);
            syncPurityInputs();
        });
    </script>
</x-app-layout>
