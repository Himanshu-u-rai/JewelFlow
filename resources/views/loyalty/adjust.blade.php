<x-app-layout>
    <x-page-header>
        <h1 class="page-title">Adjust Points — {{ $customer->name }}</h1>
        <div class="page-actions">
            <a href="{{ route('customers.show', $customer) }}" class="inline-flex items-center px-4 py-2 bg-gray-200 text-gray-700 hover:bg-gray-300 transition-colors text-sm font-medium"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>Back</a>
        </div>
    </x-page-header>

    <div class="content-inner">
        <div class="max-w-lg mx-auto">
            <div class="bg-white shadow-sm">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">Manual Adjustment</h2>
                    <p class="text-sm text-gray-500 mt-1">Current balance: <strong>{{ number_format($customer->loyalty_points) }}</strong> points</p>
                </div>
                <form method="POST" action="{{ route('loyalty.adjust', $customer) }}" class="p-6">
                    @csrf
                    <div class="space-y-6">
                        <div>
                            <label for="type" class="block text-sm font-medium text-gray-700 mb-2">Action</label>
                            <select name="type" id="type" required class="w-full border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                <option value="earn">Add Points</option>
                                <option value="redeem">Deduct Points</option>
                            </select>
                        </div>
                        <div>
                            <label for="points" class="block text-sm font-medium text-gray-700 mb-2">Points <span class="text-red-500">*</span></label>
                            <input type="number" name="points" id="points" value="{{ old('points') }}" min="1" required class="w-full border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                            @error('points')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="description" class="block text-sm font-medium text-gray-700 mb-2">Reason <span class="text-red-500">*</span></label>
                            <input type="text" name="description" id="description" value="{{ old('description') }}" required placeholder="e.g. Goodwill bonus, Error correction" class="w-full border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                            @error('description')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="mt-8 flex items-center justify-end gap-4 pt-6 border-t border-gray-200">
                        <a href="{{ route('customers.show', $customer) }}" class="px-6 py-2 bg-gray-200 text-gray-700 hover:bg-gray-300 font-medium"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline -mt-0.5 mr-1"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Cancel</a>
                        <button type="submit" class="px-6 py-2 font-medium" style="background: #0d9488; color: white;"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline -mt-0.5 mr-1"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>Adjust Points</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
