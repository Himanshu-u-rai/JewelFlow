<x-app-layout>
    <x-page-header class="customers-show-header" :title="$customer->first_name . ' ' . $customer->last_name" subtitle="Customer Profile">
        <x-slot:actions>
            <a href="{{ route('customers.edit', $customer) }}" class="btn btn-dark btn-sm customers-show-edit-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
                <span class="customers-show-edit-label-full">Edit Customer</span>
                <span class="customers-show-edit-label-short">Edit</span>
            </a>
            @if($invoices->count() === 0 && $transactions->count() === 0 && !$hasRepairs)
                <form method="POST" action="{{ route('customers.destroy', $customer) }}" data-confirm-message="Are you sure you want to delete this customer?" data-ajax-delete data-delete-redirect="{{ route('customers.index') }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-sm bg-red-600 text-white hover:bg-red-700 customers-show-delete-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        <span class="customers-show-delete-label-full">Delete Customer</span>
                        <span class="customers-show-delete-label-short">Delete</span>
                    </button>
                </form>
            @endif
            <a href="{{ route('customers.index') }}" class="btn btn-secondary btn-sm customers-show-back-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                <span class="customers-show-back-label-full">Back to Customers</span>
                <span class="customers-show-back-label-short">Back</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner customers-show-page space-y-6">
        @if(session('success'))
            <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg p-4">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg p-4">{{ session('error') }}</div>
        @endif

        <div class="customers-show-top-grid customers-show-kpi-grid grid grid-cols-1 lg:grid-cols-4 gap-4">
            @if($isRetailer)
                {{-- Retailer: Total Spent stat card --}}
                <div class="customers-show-card customers-show-kpi-card bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                    <p class="text-xs uppercase tracking-wider text-gray-500">Total Spent</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1">₹{{ number_format($totalSpent, 2) }}</p>
                    <p class="text-sm text-gray-500">{{ $invoices->count() }} recent {{ Str::plural('invoice', $invoices->count()) }}</p>
                </div>
            @else
                {{-- Manufacturer: Gold Balance --}}
                <div class="customers-show-card customers-show-kpi-card bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                    <p class="text-xs uppercase tracking-wider text-gray-500">Gold Balance</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1">{{ number_format($goldBalance, 3) }} g</p>
                    <p class="text-sm text-gray-500">Fine gold</p>
                </div>
            @endif
            <a href="{{ url('/pos/customer/' . $customer->id) }}" class="customers-show-card customers-show-kpi-card customers-show-quick-action customers-show-quick-action-sale bg-white rounded-lg shadow-sm border border-gray-200 p-4 hover:border-gray-300">
                <p class="text-sm text-gray-500">Quick Action</p>
                <p class="text-lg font-semibold text-gray-900 mt-1">Sell Item</p>
            </a>
            @if(!$isRetailer)
                <a href="{{ url('/pos/customer/' . $customer->id) }}?mode=exchange" class="customers-show-card customers-show-kpi-card bg-white rounded-lg shadow-sm border border-gray-200 p-4 hover:border-gray-300">
                    <p class="text-sm text-gray-500">Quick Action</p>
                    <p class="text-lg font-semibold text-gray-900 mt-1">Exchange</p>
                </a>
                <a href="{{ route('customers.gold.create', $customer) }}" class="customers-show-card customers-show-kpi-card bg-white rounded-lg shadow-sm border border-gray-200 p-4 hover:border-gray-300">
                    <p class="text-sm text-gray-500">Quick Action</p>
                    <p class="text-lg font-semibold text-gray-900 mt-1">Add Gold</p>
                </a>
            @else
                <a href="{{ route('invoices.index', ['customer' => $customer->id]) }}" class="customers-show-card customers-show-kpi-card customers-show-quick-action customers-show-quick-action-invoices bg-white rounded-lg shadow-sm border border-gray-200 p-4 hover:border-gray-300">
                    <p class="text-sm text-gray-500">Quick Action</p>
                    <p class="text-lg font-semibold text-gray-900 mt-1">View Invoices</p>
                </a>
                <div class="customers-show-card customers-show-kpi-card bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                    <p class="text-xs uppercase tracking-wider text-gray-500">Loyalty Points</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1">{{ number_format($customer->loyalty_points ?? 0) }}</p>
                    <p class="text-sm text-gray-500">Worth ₹{{ number_format(($customer->loyalty_points ?? 0) * 0.25, 2) }}</p>
                </div>
            @endif
        </div>

        <div class="customers-show-main-grid grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 space-y-6">
                <div class="customers-show-panel bg-white rounded-lg shadow-sm border border-gray-200">
                    <div class="p-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-900">Customer Information</h2>
                    </div>
                    <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                        <div>
                            <p class="text-gray-500">Name</p>
                            <p class="font-medium text-gray-900">{{ $customer->first_name }} {{ $customer->last_name }}</p>
                        </div>
                        <div>
                            <p class="text-gray-500">Mobile</p>
                            <p class="font-medium text-gray-900">{{ $customer->mobile }}</p>
                        </div>
                        <div>
                            <p class="text-gray-500">Email</p>
                            <p class="font-medium text-gray-900">{{ $customer->email ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-gray-500">Address</p>
                            <p class="font-medium text-gray-900">{{ $customer->address ?? '—' }}</p>
                        </div>
                        @if($customer->date_of_birth)
                        <div>
                            <p class="text-gray-500">Date of Birth</p>
                            <p class="font-medium text-gray-900">{{ $customer->date_of_birth->format('d M Y') }}</p>
                        </div>
                        @endif
                        @if($customer->anniversary_date)
                        <div>
                            <p class="text-gray-500">Anniversary</p>
                            <p class="font-medium text-gray-900">{{ $customer->anniversary_date->format('d M Y') }}</p>
                        </div>
                        @endif
                        @if($customer->wedding_date)
                        <div>
                            <p class="text-gray-500">Wedding Anniversary</p>
                            <p class="font-medium text-gray-900">{{ $customer->wedding_date->format('d M Y') }}</p>
                        </div>
                        @endif
                        @if($customer->notes)
                        <div class="md:col-span-2">
                            <p class="text-gray-500">Notes</p>
                            <p class="font-medium text-gray-900">{{ $customer->notes }}</p>
                        </div>
                        @endif
                    </div>
                </div>

                @if($isRetailer)
                <div class="customers-show-panel bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden customers-show-table-card customers-show-table-card--loyalty" x-data="{ showAdjust: false }">
                    <div class="p-4 border-b border-gray-200 flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-gray-900">Loyalty Points History</h2>
                        <button @click="showAdjust = !showAdjust" class="btn btn-secondary btn-xs" x-text="showAdjust ? 'Cancel' : 'Adjust Points'"></button>
                    </div>

                    {{-- Inline Adjust Form --}}
                    <div x-show="showAdjust" x-cloak class="p-4 bg-gray-50 border-b border-gray-200">
                        <form method="POST" action="{{ route('loyalty.adjust', $customer) }}" class="customers-loyalty-adjust-form flex flex-wrap gap-3 items-end">
                            @csrf
                            <div class="customers-loyalty-adjust-field min-w-[120px]">
                                <label class="block text-xs font-medium text-gray-600 mb-1">Action</label>
                                <select name="type" required class="w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                    <option value="earn">Add Points</option>
                                    <option value="redeem">Deduct Points</option>
                                </select>
                            </div>
                            <div class="customers-loyalty-adjust-field min-w-[100px]">
                                <label class="block text-xs font-medium text-gray-600 mb-1">Points</label>
                                <input type="number" name="points" min="1" required placeholder="0" class="w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                            </div>
                            <div class="customers-loyalty-adjust-field flex-1 min-w-[180px]">
                                <label class="block text-xs font-medium text-gray-600 mb-1">Reason</label>
                                <input type="text" name="description" required placeholder="e.g. Goodwill bonus, Error correction" class="w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                            </div>
                            <button type="submit" class="customers-loyalty-adjust-submit px-4 py-2 text-sm rounded-md font-medium text-white inline-flex items-center" style="background: #0d9488;"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><polyline points="20 6 9 17 4 12"/></svg>Adjust</button>
                        </form>
                    </div>

                    <div class="customers-show-table-wrap overflow-x-auto customers-show-table-shell">
                        <table class="customers-show-table w-full customers-show-data-table customers-show-data-table--loyalty">
                            <thead class="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Points</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Balance</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse($loyaltyTransactions as $ltxn)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 text-sm text-gray-900">{{ $ltxn->created_at->format('d M Y') }}</td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $ltxn->type === 'earn' ? 'bg-green-100 text-green-800' : 'bg-rose-100 text-rose-800' }}">
                                                {{ $ltxn->type === 'earn' ? '+Earned' : '-Redeemed' }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-right font-medium {{ $ltxn->type === 'earn' ? 'text-green-700' : 'text-rose-700' }}">
                                            {{ $ltxn->type === 'earn' ? '+' : '-' }}{{ number_format($ltxn->points) }}
                                        </td>
                                        <td class="px-4 py-3 text-sm text-right text-gray-700">{{ number_format($ltxn->balance_after) }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-600">
                                            {{ $ltxn->description }}
                                            @if($ltxn->invoice)
                                                <a href="{{ route('invoices.show', $ltxn->invoice_id) }}" class="text-amber-600 hover:underline ml-1">#{{ $ltxn->invoice_id }}</a>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">No loyalty transactions yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                @endif

                @if(!$isRetailer)
                <div class="customers-show-panel bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden customers-show-table-card customers-show-table-card--gold">
                    <div class="p-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-900">Recent Gold Transactions</h2>
                    </div>
                    <div class="customers-show-table-wrap overflow-x-auto customers-show-table-shell">
                        <table class="customers-show-table w-full customers-show-data-table customers-show-data-table--gold">
                            <thead class="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Fine Gold</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse($transactions as $txn)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 text-sm text-gray-900">{{ $txn->created_at->format('d M Y') }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-900">{{ ucfirst($txn->type) }}</td>
                                        <td class="px-4 py-3 text-sm text-right font-medium {{ $txn->fine_gold >= 0 ? 'text-green-600' : 'text-red-600' }}">{{ number_format($txn->fine_gold, 3) }} g</td>
                                        <td class="px-4 py-3 text-sm text-gray-600">{{ $txn->description ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="px-4 py-8 text-center text-sm text-gray-500">No transactions found.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                @endif
            </div>

            <div class="space-y-6">
                <div class="customers-show-panel bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                    <div class="p-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-900">Recent Invoices</h2>
                    </div>
                    <div class="divide-y divide-gray-200">
                        @forelse($invoices as $invoice)
                            <div class="p-4">
                                <p class="text-sm font-medium text-gray-900">{{ $invoice->invoice_number }}</p>
                                <p class="text-xs text-gray-500 mt-1">{{ $invoice->created_at->format('d M Y') }}</p>
                                <div class="mt-2 flex items-center justify-between">
                                    <p class="text-sm font-semibold text-gray-900">₹{{ number_format($invoice->total, 2) }}</p>
                                    <a href="{{ route('invoices.print', $invoice) }}" class="text-xs text-amber-600 hover:text-amber-800">View</a>
                                </div>
                            </div>
                        @empty
                            <div class="p-6 text-center text-sm text-gray-500">No invoices found.</div>
                        @endforelse
                    </div>
                </div>

                <div class="customers-show-panel bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                    <h2 class="text-lg font-semibold text-gray-900">Customer Stats</h2>
                    <div class="mt-3 space-y-2 text-sm">
                        <div class="flex items-center justify-between"><span class="text-gray-500">Total Spent</span><span class="font-semibold">₹{{ number_format($totalSpent, 2) }}</span></div>
                        @if($isRetailer)
                        <div class="flex items-center justify-between"><span class="text-gray-500">Loyalty Points</span><span class="font-semibold">{{ number_format($customer->loyalty_points ?? 0) }} pts</span></div>
                        @endif
                        <div class="flex items-center justify-between"><span class="text-gray-500">Member Since</span><span class="font-semibold">{{ $customer->created_at->format('M Y') }}</span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if($isRetailer)
        <div x-data="{ customerQuickFabOpen: false }" class="customers-show-mobile-fab">
            <div class="customers-show-mobile-fab-shell" x-bind:class="{ 'is-open': customerQuickFabOpen }" @click.outside="customerQuickFabOpen = false">
                <nav class="customers-show-mobile-fab-nav" aria-label="Customer quick actions">
                    <a href="{{ url('/pos/customer/' . $customer->id) }}" class="customers-show-mobile-fab-link" @click="customerQuickFabOpen = false">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="9" cy="21" r="1"></circle>
                            <circle cx="20" cy="21" r="1"></circle>
                            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
                        </svg>
                        <span>Sell Item</span>
                    </a>
                    <a href="{{ route('invoices.index', ['customer' => $customer->id]) }}" class="customers-show-mobile-fab-link" @click="customerQuickFabOpen = false">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                            <line x1="16" y1="13" x2="8" y2="13"></line>
                            <line x1="16" y1="17" x2="8" y2="17"></line>
                            <polyline points="10 9 9 9 8 9"></polyline>
                        </svg>
                        <span>View Invoices</span>
                    </a>
                </nav>
                <button type="button" class="customers-show-mobile-fab-toggle" x-on:click="customerQuickFabOpen = !customerQuickFabOpen" x-bind:aria-expanded="customerQuickFabOpen.toString()" aria-label="Toggle customer quick actions">
                    <span class="customers-show-mobile-fab-bars" aria-hidden="true">
                        <span></span>
                        <span></span>
                        <span></span>
                    </span>
                </button>
            </div>
        </div>
    @endif
</x-app-layout>
