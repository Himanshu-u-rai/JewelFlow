<x-app-layout>
    <x-page-header class="installments-index-header">
        <div>
            <h1 class="page-title">EMI / Installments</h1>
            <p class="text-sm text-gray-500 mt-1">Track installment plans and EMI payments</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('installments.create') }}" class="btn btn-dark btn-sm installments-create-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Create EMI Plan
            </a>
        </div>
    </x-page-header>

    <div class="content-inner">
        @if(session('success'))
            <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg p-4 mb-6">{{ session('success') }}</div>
        @endif

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6 installments-top-kpi-grid">
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 installments-top-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="installments-top-kpi-icon bg-amber-100 text-amber-700">
                        <svg class="installments-top-kpi-icon-svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.5 3A2.5 2.5 0 0 0 3 5.5v9A2.5 2.5 0 0 0 5.5 17h9a2.5 2.5 0 0 0 2.5-2.5v-9A2.5 2.5 0 0 0 14.5 3h-9Zm1.75 4.25a.75.75 0 0 1 .75-.75h4a.75.75 0 0 1 0 1.5H8a.75.75 0 0 1-.75-.75Zm0 3.5a.75.75 0 0 1 .75-.75h4a.75.75 0 0 1 0 1.5H8a.75.75 0 0 1-.75-.75Z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500 installments-top-kpi-label">Active Plans</p>
                        <p class="text-xl font-semibold text-gray-900 installments-top-kpi-value">{{ $activePlansCount ?? 0 }}</p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 installments-top-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="installments-top-kpi-icon bg-rose-100 text-rose-700">
                        <svg class="installments-top-kpi-icon-svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M10 2.5a7.5 7.5 0 1 0 0 15 7.5 7.5 0 0 0 0-15Zm.75 3.75a.75.75 0 0 0-1.5 0v3.44c0 .2.08.39.22.53l2.25 2.25a.75.75 0 1 0 1.06-1.06l-2.03-2.03V6.25Z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500 installments-top-kpi-label">Overdue</p>
                        <p class="text-xl font-semibold text-rose-600 installments-top-kpi-value">{{ $overduePlans }}</p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 installments-top-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="installments-top-kpi-icon bg-amber-100 text-amber-700">
                        <svg class="installments-top-kpi-icon-svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.5 2.5A2.5 2.5 0 0 0 3 5v10a2.5 2.5 0 0 0 2.5 2.5h9A2.5 2.5 0 0 0 17 15V5a2.5 2.5 0 0 0-2.5-2.5h-9Zm.75 6a.75.75 0 0 1 .75-.75h6a.75.75 0 0 1 0 1.5H7a.75.75 0 0 1-.75-.75Zm0 3a.75.75 0 0 1 .75-.75h3.5a.75.75 0 0 1 0 1.5H7a.75.75 0 0 1-.75-.75Z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500 installments-top-kpi-label">Due In Next 7 Days</p>
                        <p class="text-xl font-semibold text-amber-700 installments-top-kpi-value">{{ $upcomingDues ?? 0 }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 installments-summary-card">
                <div class="flex items-center gap-3">
                    <div class="installments-summary-icon bg-emerald-100 text-emerald-700">
                        <svg class="installments-summary-icon-svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M10 2.5a7.5 7.5 0 1 0 0 15 7.5 7.5 0 0 0 0-15Zm3.03 6.47a.75.75 0 0 0-1.06-1.06L9.25 10.62l-1.22-1.22a.75.75 0 0 0-1.06 1.06l1.75 1.75a.75.75 0 0 0 1.06 0l3.25-3.25Z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500">Collected This Month</p>
                        <p class="text-xl font-semibold text-emerald-700 mt-1 installments-summary-value">₹{{ number_format($thisMonthCollected ?? 0, 2) }}</p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 installments-summary-card">
                <div class="flex items-center gap-3">
                    <div class="installments-summary-icon bg-amber-100 text-amber-700">
                        <span class="installments-summary-icon-svg installments-rupee-glyph" aria-hidden="true">₹</span>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500">Total Outstanding</p>
                        <p class="text-xl font-semibold text-gray-900 mt-1 installments-summary-value">₹{{ number_format($totalOutstanding, 2) }}</p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 installments-summary-card">
                <div class="flex items-center gap-3">
                    <div class="installments-summary-icon bg-rose-100 text-rose-700">
                        <svg class="installments-summary-icon-svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M10 2.5a7.5 7.5 0 1 0 0 15 7.5 7.5 0 0 0 0-15Zm0 3a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5.5Zm0 7a1 1 0 1 0 0 2 1 1 0 0 0 0-2Z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500">Defaulted Plans</p>
                        <p class="text-xl font-semibold text-rose-700 mt-1 installments-summary-value">{{ $defaultedPlans ?? 0 }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-6 ui-filter-enhanced-wrap">
            <form method="GET" action="{{ route('installments.index') }}" class="flex flex-wrap gap-3 items-end" data-enhance-selects="true" data-enhance-selects-variant="standard">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select name="status" class="rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 installments-status-filter">
                        <option value="active" {{ request('status', 'active') === 'active' ? 'selected' : '' }}>Active</option>
                        <option value="completed" {{ request('status') === 'completed' ? 'selected' : '' }}>Completed</option>
                        <option value="defaulted" {{ request('status') === 'defaulted' ? 'selected' : '' }}>Defaulted</option>
                        <option value="" {{ request('status') === '' ? 'selected' : '' }}>All</option>
                    </select>
                </div>
                @if(request()->has('status'))
                    <a href="{{ route('installments.index') }}" class="btn btn-secondary btn-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Clear</a>
                @else
                    <button type="submit" class="btn btn-secondary btn-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>Filter</button>
                @endif
            </form>
        </div>

        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">EMI</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Progress</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Next Due</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse($plans as $plan)
                        @php $isOverdue = $plan->next_due_date && $plan->next_due_date < now()->toDateString() && $plan->status === 'active'; @endphp
                        <tr class="hover:bg-gray-50 {{ $isOverdue ? 'bg-rose-50' : '' }}">
                            <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $plan->customer->name ?? '—' }}</td>
                            <td class="px-6 py-4 text-sm text-right">₹{{ number_format($plan->total_amount, 2) }}</td>
                            <td class="px-6 py-4 text-sm text-right">₹{{ number_format($plan->emi_amount, 2) }}</td>
                            <td class="px-6 py-4 text-center text-sm">{{ $plan->emis_paid }}/{{ $plan->total_emis }}</td>
                            <td class="px-6 py-4 text-sm {{ $isOverdue ? 'text-rose-600 font-medium' : 'text-gray-500' }}">
                                {{ $plan->next_due_date ? \Carbon\Carbon::parse($plan->next_due_date)->format('d M Y') : '—' }}
                                @if($isOverdue) <span class="text-xs">(overdue)</span> @endif
                            </td>
                            <td class="px-6 py-4 text-center">
                                @php
                                    $statusColors = ['active' => 'bg-blue-100 text-blue-800', 'completed' => 'bg-green-100 text-green-800', 'defaulted' => 'bg-red-100 text-red-800'];
                                @endphp
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $statusColors[$plan->status] ?? 'bg-gray-100' }}">
                                    {{ ucfirst($plan->status) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <a href="{{ route('installments.show', $plan) }}" class="btn btn-secondary btn-xs"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>View</a>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                <p class="text-lg font-medium mb-1">No installment plans found</p>
                                <p class="text-sm">Create your first plan from the button above or from an invoice.</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($plans->hasPages())
                <div class="px-6 py-4 border-t border-gray-200">{{ $plans->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
