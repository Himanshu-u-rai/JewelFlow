<x-app-layout>
    <x-page-header class="cashbook-page-header" title="Add Ledger Entry" subtitle="Record a cash inflow or outflow in the ledger">
        <x-slot:actions>
            <a href="{{ route('cashbook.index') }}" class="btn btn-secondary btn-sm cashbook-dashboard-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                <span class="cashbook-dashboard-label-full">Back to Cash Ledger</span>
                <span class="cashbook-dashboard-label-short">Back</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner cashbook-create-page">
        <div class="max-w-2xl mx-auto bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden cashbook-create-card">
            <div class="p-5 border-b border-gray-200 cashbook-create-head">
                <h2 class="text-lg font-semibold text-gray-900">New Transaction</h2>
                <p class="text-sm text-gray-500 mt-1">Use this form for manual cash entries.</p>
            </div>

            <form method="POST" action="{{ route('cashbook.store') }}" class="p-6 space-y-6 cashbook-create-form">
                @csrf

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-3">Transaction Type *</label>
                    <div class="grid grid-cols-2 gap-4">
                        <label class="relative cursor-pointer">
                            <input type="radio" name="type" value="in" class="peer sr-only" {{ old('type', 'in') === 'in' ? 'checked' : '' }}>
                            <div class="p-4 border rounded-lg peer-checked:border-green-500 peer-checked:bg-green-50 hover:bg-gray-50 transition cashbook-type-card">
                                <div class="font-semibold text-gray-900">Cash In</div>
                                <div class="text-xs text-gray-500 mt-1">Money received</div>
                            </div>
                        </label>
                        <label class="relative cursor-pointer">
                            <input type="radio" name="type" value="out" class="peer sr-only" {{ old('type') === 'out' ? 'checked' : '' }}>
                            <div class="p-4 border rounded-lg peer-checked:border-red-500 peer-checked:bg-red-50 hover:bg-gray-50 transition cashbook-type-card">
                                <div class="font-semibold text-gray-900">Cash Out</div>
                                <div class="text-xs text-gray-500 mt-1">Money paid out</div>
                            </div>
                        </label>
                    </div>
                    @error('type')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="amount" class="block text-sm font-medium text-gray-700 mb-2">Amount (₹) *</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-500">₹</span>
                        <input type="number" step="0.01" name="amount" id="amount"
                               value="{{ old('amount') }}"
                               placeholder="0.00"
                               class="w-full pl-8 rounded-lg border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500"
                               required>
                    </div>
                    @error('amount')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="source_type" class="block text-sm font-medium text-gray-700 mb-2">Source / Reason *</label>
                    @php
                        $knownSources = ['opening_balance','loan_received','owner_investment','other_income','salary','rent','utility_bills','gold_purchase','supplier_payment','loan_repayment','owner_withdrawal','other_expense'];
                        $oldSource = old('source_type', '');
                        $isCustom = $oldSource !== '' && !in_array($oldSource, $knownSources);
                    @endphp
                    <select name="source_type" id="source_type"
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500"
                            required onchange="handleSourceChange(this)">
                        <option value="">Select source</option>
                        <optgroup label="Cash In Sources">
                            <option value="opening_balance" {{ $oldSource === 'opening_balance' ? 'selected' : '' }}>Opening Balance</option>
                            <option value="loan_received" {{ $oldSource === 'loan_received' ? 'selected' : '' }}>Loan Received</option>
                            <option value="owner_investment" {{ $oldSource === 'owner_investment' ? 'selected' : '' }}>Owner Investment</option>
                            <option value="other_income" {{ $oldSource === 'other_income' ? 'selected' : '' }}>Other Income</option>
                        </optgroup>
                        <optgroup label="Cash Out Sources">
                            <option value="salary" {{ $oldSource === 'salary' ? 'selected' : '' }}>Salary Payment</option>
                            <option value="rent" {{ $oldSource === 'rent' ? 'selected' : '' }}>Rent</option>
                            <option value="utility_bills" {{ $oldSource === 'utility_bills' ? 'selected' : '' }}>Utility Bills</option>
                            <option value="gold_purchase" {{ $oldSource === 'gold_purchase' ? 'selected' : '' }}>Gold Purchase (Supplier)</option>
                            <option value="supplier_payment" {{ $oldSource === 'supplier_payment' ? 'selected' : '' }}>Supplier Payment</option>
                            <option value="loan_repayment" {{ $oldSource === 'loan_repayment' ? 'selected' : '' }}>Loan Repayment</option>
                            <option value="owner_withdrawal" {{ $oldSource === 'owner_withdrawal' ? 'selected' : '' }}>Owner Withdrawal</option>
                            <option value="other_expense" {{ $oldSource === 'other_expense' ? 'selected' : '' }}>Other Expense</option>
                        </optgroup>
                        <option value="custom" {{ $isCustom ? 'selected' : '' }}>Custom (Enter below)</option>
                    </select>
                    <input type="text" id="custom_source"
                           class="{{ $isCustom ? '' : 'hidden' }} w-full mt-2 rounded-lg border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500"
                           placeholder="Enter custom source/reason"
                           maxlength="100"
                           value="{{ $isCustom ? $oldSource : '' }}">
                    @error('source_type')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-2">Description (Optional)</label>
                    <textarea name="description" id="description" rows="3"
                              placeholder="Add any additional notes..."
                              class="w-full rounded-lg border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500">{{ old('description') }}</textarea>
                    @error('description')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex justify-end gap-3 pt-4 border-t border-gray-200 cashbook-create-actions">
                    <a href="{{ route('cashbook.index') }}" class="btn btn-secondary btn-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Cancel</a>
                    <button type="submit" class="btn btn-dark btn-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>Record Transaction</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function handleSourceChange(select) {
            const customInput = document.getElementById('custom_source');
            if (select.value === 'custom') {
                customInput.classList.remove('hidden');
                customInput.required = true;
                customInput.name = 'source_type';
                select.name = '';
            } else {
                customInput.classList.add('hidden');
                customInput.required = false;
                customInput.name = '';
                select.name = 'source_type';
            }
        }

        // On page load with a custom value, wire up the hidden input name correctly.
        document.addEventListener('DOMContentLoaded', () => {
            const select = document.getElementById('source_type');
            if (select && select.value === 'custom') {
                const customInput = document.getElementById('custom_source');
                customInput.required = true;
                customInput.name = 'source_type';
                select.name = '';
            }
        });
    </script>
</x-app-layout>
