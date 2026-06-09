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
            @if(auth()->user()?->isOwner())
            <a href="{{ route('store-credit.adjust.create', $customer) }}" class="btn btn-secondary btn-sm" data-turbo-frame="_top">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                Store Credit
            </a>
            @endif
            <a href="{{ route('customers.index') }}" class="btn btn-secondary btn-sm customers-show-back-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                <span class="customers-show-back-label-full">Back to Customers</span>
                <span class="customers-show-back-label-short">Back</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    @if($isRetailer)
        <div class="content-inner customers-show-page customers-show-page--retailer customers-show-retailer-shell">
            @if(session('success'))
                <div class="customers-show-retailer-alert customers-show-retailer-alert--success">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="customers-show-retailer-alert customers-show-retailer-alert--error">{{ session('error') }}</div>
            @endif

            <section class="customers-show-retailer-summary" aria-label="Customer summary">
                <div class="customers-show-retailer-stat">
                    <span>Total Spent</span>
                    <strong>₹{{ number_format($totalSpent, 2) }}</strong>
                    <small>{{ $invoices->count() }} recent {{ Str::plural('invoice', $invoices->count()) }}</small>
                </div>
                <div class="customers-show-retailer-stat">
                    <span>Loyalty Points</span>
                    <strong>{{ number_format($customer->loyalty_points ?? 0) }}</strong>
                    <small>Worth ₹{{ number_format(($customer->loyalty_points ?? 0) * 0.25, 2) }}</small>
                </div>
                <div class="customers-show-retailer-stat">
                    <span>Member Since</span>
                    <strong>{{ $customer->created_at->format('M Y') }}</strong>
                    <small>{{ $customer->created_at->format('d M Y') }}</small>
                </div>
                <div class="customers-show-retailer-stat">
                    <span>Recent Invoices</span>
                    <strong>{{ number_format($invoices->count()) }}</strong>
                    <small>Latest 5 shown below</small>
                </div>
            </section>

            <nav class="customers-show-retailer-actions" aria-label="Customer quick actions">
                <a href="{{ url('/pos/customer/' . $customer->id) }}" class="customers-show-retailer-action">
                    <span>Sell Item</span>
                    <small>Open POS for this customer</small>
                </a>
                <a href="{{ route('invoices.index', ['customer' => $customer->id]) }}" class="customers-show-retailer-action">
                    <span>View Invoices</span>
                    <small>See full invoice history</small>
                </a>
            </nav>

            @include('customers.partials.compliance-card')

            <div class="customers-show-retailer-grid">
                <main class="customers-show-retailer-primary">
                    <section class="customers-show-retailer-panel customers-show-retailer-panel--info">
                        <div class="customers-show-retailer-panel-head">
                            <div>
                                <h2>Customer Information</h2>
                                <p>Contact details and important customer dates.</p>
                            </div>
                        </div>

                        <dl class="customers-show-retailer-meta">
                            <div>
                                <dt>Name</dt>
                                <dd>{{ $customer->first_name }} {{ $customer->last_name }}</dd>
                            </div>
                            <div>
                                <dt>Mobile</dt>
                                <dd>{{ $customer->mobile }}</dd>
                            </div>
                            <div>
                                <dt>Email</dt>
                                <dd>{{ $customer->email ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt>Member Since</dt>
                                <dd>{{ $customer->created_at->format('d M Y') }}</dd>
                            </div>
                            <div class="customers-show-retailer-meta-wide">
                                <dt>Address</dt>
                                <dd>{{ $customer->address ?? '—' }}</dd>
                            </div>
                            @if($customer->date_of_birth)
                                <div>
                                    <dt>Date of Birth</dt>
                                    <dd>{{ $customer->date_of_birth->format('d M Y') }}</dd>
                                </div>
                            @endif
                            @if($customer->anniversary_date)
                                <div>
                                    <dt>Anniversary</dt>
                                    <dd>{{ $customer->anniversary_date->format('d M Y') }}</dd>
                                </div>
                            @endif
                            @if($customer->wedding_date)
                                <div>
                                    <dt>Wedding Anniversary</dt>
                                    <dd>{{ $customer->wedding_date->format('d M Y') }}</dd>
                                </div>
                            @endif
                            @if($customer->notes)
                                <div class="customers-show-retailer-meta-wide">
                                    <dt>Notes</dt>
                                    <dd>{{ $customer->notes }}</dd>
                                </div>
                            @endif
                        </dl>
                    </section>

                    <section class="customers-show-retailer-panel customers-show-retailer-panel--loyalty" x-data="{ showAdjust: false }">
                        <div class="customers-show-retailer-panel-head customers-show-retailer-panel-head--split">
                            <div>
                                <h2>Loyalty Points History</h2>
                                <p>Recent points earned and redeemed for this customer.</p>
                            </div>
                            <button @click="showAdjust = !showAdjust" type="button" class="customers-show-retailer-secondary-btn" x-text="showAdjust ? 'Cancel' : 'Adjust Points'"></button>
                        </div>

                        <div x-show="showAdjust" x-cloak class="customers-show-retailer-adjust">
                            <form method="POST" action="{{ route('loyalty.adjust', $customer) }}" class="customers-loyalty-adjust-form customers-show-retailer-adjust-form">
                                @csrf
                                <div class="customers-loyalty-adjust-field">
                                    <label>Action</label>
                                    <select name="type" required>
                                        <option value="earn">Add Points</option>
                                        <option value="redeem">Deduct Points</option>
                                    </select>
                                </div>
                                <div class="customers-loyalty-adjust-field">
                                    <label>Points</label>
                                    <input type="number" name="points" min="1" required placeholder="0">
                                </div>
                                <div class="customers-loyalty-adjust-field customers-show-retailer-adjust-reason">
                                    <label>Reason</label>
                                    <input type="text" name="description" required placeholder="e.g. Goodwill bonus, Error correction">
                                </div>
                                <button type="submit" class="customers-loyalty-adjust-submit customers-show-retailer-primary-btn">Adjust</button>
                            </form>
                        </div>

                        <div class="customers-show-retailer-table-wrap customers-show-retailer-desktop-table">
                            <table class="customers-show-retailer-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th class="text-right">Points</th>
                                        <th class="text-right">Balance</th>
                                        <th>Description</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($loyaltyTransactions as $ltxn)
                                        <tr>
                                            <td>{{ $ltxn->created_at->format('d M Y') }}</td>
                                            <td>
                                                <span class="customers-show-retailer-chip {{ $ltxn->type === 'earn' ? 'is-earned' : 'is-redeemed' }}">
                                                    {{ $ltxn->type === 'earn' ? '+Earned' : '-Redeemed' }}
                                                </span>
                                            </td>
                                            <td class="text-right customers-show-retailer-points {{ $ltxn->type === 'earn' ? 'is-earned' : 'is-redeemed' }}">
                                                {{ $ltxn->type === 'earn' ? '+' : '-' }}{{ number_format($ltxn->points) }}
                                            </td>
                                            <td class="text-right">{{ number_format($ltxn->balance_after) }}</td>
                                            <td>
                                                {{ $ltxn->description }}
                                                @if($ltxn->invoice)
                                                    <a href="{{ route('invoices.show', $ltxn->invoice_id) }}">#{{ $ltxn->invoice_id }}</a>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="customers-show-retailer-empty">No loyalty transactions yet.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        <div class="customers-show-retailer-mobile-cards">
                            @forelse($loyaltyTransactions as $ltxn)
                                <article class="customers-show-retailer-history-card">
                                    <div class="customers-show-retailer-history-card-head">
                                        <div>
                                            <strong>{{ $ltxn->created_at->format('d M Y') }}</strong>
                                            <span>{{ $ltxn->description }}</span>
                                        </div>
                                        <span class="customers-show-retailer-chip {{ $ltxn->type === 'earn' ? 'is-earned' : 'is-redeemed' }}">
                                            {{ $ltxn->type === 'earn' ? '+Earned' : '-Redeemed' }}
                                        </span>
                                    </div>
                                    <div class="customers-show-retailer-history-card-grid">
                                        <div>
                                            <span>Points</span>
                                            <strong class="{{ $ltxn->type === 'earn' ? 'is-earned' : 'is-redeemed' }}">{{ $ltxn->type === 'earn' ? '+' : '-' }}{{ number_format($ltxn->points) }}</strong>
                                        </div>
                                        <div>
                                            <span>Balance</span>
                                            <strong>{{ number_format($ltxn->balance_after) }}</strong>
                                        </div>
                                        @if($ltxn->invoice)
                                            <a href="{{ route('invoices.show', $ltxn->invoice_id) }}">Invoice #{{ $ltxn->invoice_id }}</a>
                                        @endif
                                    </div>
                                </article>
                            @empty
                                <div class="customers-show-retailer-empty-card">No loyalty transactions yet.</div>
                            @endforelse
                        </div>
                    </section>
                </main>

                <aside class="customers-show-retailer-side">
                    <section class="customers-show-retailer-panel">
                        <div class="customers-show-retailer-panel-head">
                            <div>
                                <h2>Recent Invoices</h2>
                                <p>Latest customer billing activity.</p>
                            </div>
                        </div>
                        <div class="customers-show-retailer-invoices">
                            @forelse($invoices as $invoice)
                                <article class="customers-show-retailer-invoice">
                                    <div>
                                        <strong>{{ $invoice->invoice_number }}</strong>
                                        <span>{{ $invoice->created_at->format('d M Y') }}</span>
                                    </div>
                                    <div>
                                        <b>₹{{ number_format($invoice->total, 2) }}</b>
                                        <a href="{{ route('invoices.print', $invoice) }}">View</a>
                                    </div>
                                </article>
                            @empty
                                <div class="customers-show-retailer-empty-card">No invoices found.</div>
                            @endforelse
                        </div>
                    </section>

                    <section class="customers-show-retailer-panel">
                        <div class="customers-show-retailer-panel-head">
                            <div>
                                <h2>Customer Stats</h2>
                                <p>Compact account summary.</p>
                            </div>
                        </div>
                        <dl class="customers-show-retailer-stat-list">
                            <div>
                                <dt>Total Spent</dt>
                                <dd>₹{{ number_format($totalSpent, 2) }}</dd>
                            </div>
                            <div>
                                <dt>Loyalty Points</dt>
                                <dd>{{ number_format($customer->loyalty_points ?? 0) }} pts</dd>
                            </div>
                            <div>
                                <dt>Member Since</dt>
                                <dd>{{ $customer->created_at->format('M Y') }}</dd>
                            </div>
                        </dl>
                    </section>
                </aside>
            </div>
        </div>
    @else
        <div class="content-inner customers-show-page customers-show-page--manufacturer space-y-6">
            @if(session('success'))
                <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg p-4">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg p-4">{{ session('error') }}</div>
            @endif

            <div class="customers-show-top-grid customers-show-kpi-grid grid grid-cols-1 lg:grid-cols-4 gap-4">
                <div class="customers-show-card customers-show-kpi-card bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                    <p class="text-xs uppercase tracking-wider text-gray-500">Gold Balance</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1">{{ number_format($goldBalance, 3) }} g</p>
                    <p class="text-sm text-gray-500">Fine gold</p>
                </div>
                <a href="{{ url('/pos/customer/' . $customer->id) }}" class="customers-show-card customers-show-kpi-card customers-show-quick-action customers-show-quick-action-sale bg-white rounded-lg shadow-sm border border-gray-200 p-4 hover:border-gray-300">
                    <p class="text-sm text-gray-500">Quick Action</p>
                    <p class="text-lg font-semibold text-gray-900 mt-1">Sell Item</p>
                </a>
                <a href="{{ url('/pos/customer/' . $customer->id) }}?mode=exchange" class="customers-show-card customers-show-kpi-card bg-white rounded-lg shadow-sm border border-gray-200 p-4 hover:border-gray-300">
                    <p class="text-sm text-gray-500">Quick Action</p>
                    <p class="text-lg font-semibold text-gray-900 mt-1">Exchange</p>
                </a>
                <a href="{{ route('customers.gold.create', $customer) }}" class="customers-show-card customers-show-kpi-card bg-white rounded-lg shadow-sm border border-gray-200 p-4 hover:border-gray-300">
                    <p class="text-sm text-gray-500">Quick Action</p>
                    <p class="text-lg font-semibold text-gray-900 mt-1">Add Gold</p>
                </a>
            </div>

            @include('customers.partials.compliance-card')

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
                            <div class="flex items-center justify-between"><span class="text-gray-500">Member Since</span><span class="font-semibold">{{ $customer->created_at->format('M Y') }}</span></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

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
