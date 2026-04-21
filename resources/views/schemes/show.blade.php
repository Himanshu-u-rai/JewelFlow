<x-app-layout>
    <x-page-header class="schemes-show-header schemes-show-header-mobile-fab ops-treatment-header">
        <div>
            <h1 class="page-title">{{ $scheme->name }}</h1>
            <p class="text-sm text-gray-500 mt-1">
                @php
                    $typeLabels = ['gold_savings' => 'Gold Savings Scheme', 'festival_sale' => 'Festival Sale', 'discount_offer' => 'Discount Offer'];
                @endphp
                {{ $typeLabels[$scheme->type] ?? $scheme->type }}
            </p>
        </div>
        <div class="page-actions flex flex-wrap items-center gap-2">
            <a href="{{ route('schemes.edit', $scheme) }}" class="btn btn-secondary btn-sm scheme-edit-action"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>Edit</a>
            @if($scheme->isGoldSavings())
                <a href="{{ route('schemes.enroll.form', $scheme) }}" class="btn btn-dark btn-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>Enroll Customer</a>
            @endif
            <button type="button"
                    onclick="document.getElementById('delete-scheme-modal').classList.remove('hidden')"
                    class="btn btn-sm scheme-delete-action" style="background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5;">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                Delete
            </button>
            <a href="{{ route('schemes.index') }}" class="btn btn-secondary btn-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>Back</a>
        </div>
    </x-page-header>

    <div x-data="{ schemeQuickFabOpen: false }" class="invoice-emi-mobile-fab">
        <div class="invoice-emi-mobile-fab-shell" x-bind:class="{ 'is-open': schemeQuickFabOpen }" @click.outside="schemeQuickFabOpen = false">
            <nav class="invoice-emi-mobile-fab-nav" aria-label="Scheme quick actions">
                <a href="{{ route('schemes.edit', $scheme) }}" class="invoice-emi-mobile-fab-link" @click="schemeQuickFabOpen = false">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
                    <span>Edit Scheme</span>
                </a>
                <button
                    type="button"
                    class="invoice-emi-mobile-fab-link scheme-mobile-fab-delete"
                    x-on:click="schemeQuickFabOpen = false; document.getElementById('delete-scheme-modal').classList.remove('hidden')"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                    <span>Delete Scheme</span>
                </button>
            </nav>
            <button type="button" class="invoice-emi-mobile-fab-toggle" x-on:click="schemeQuickFabOpen = !schemeQuickFabOpen" x-bind:aria-expanded="schemeQuickFabOpen.toString()" aria-label="Toggle scheme actions">
                <span class="invoice-emi-mobile-fab-bars" aria-hidden="true">
                    <span></span>
                    <span></span>
                    <span></span>
                </span>
            </button>
        </div>
    </div>

    {{-- Delete confirmation modal --}}
    <div id="delete-scheme-modal" class="hidden fixed inset-0 z-50 scheme-delete-modal flex items-center justify-center bg-black/40">
        <div class="bg-white rounded-xl shadow-xl p-6 max-w-md w-full mx-4">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Delete Scheme?</h3>
            <p class="text-sm text-gray-600 mb-5">
                This will permanently delete <strong>{{ $scheme->name }}</strong>.
                Schemes with active or matured enrollments cannot be deleted.
            </p>
            <div class="flex justify-end gap-3">
                <button type="button"
                        onclick="document.getElementById('delete-scheme-modal').classList.add('hidden')"
                        class="px-4 py-2 rounded-lg border border-gray-300 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <form method="POST" action="{{ route('schemes.destroy', $scheme) }}">
                    @csrf @method('DELETE')
                    <button type="submit"
                            class="px-4 py-2 rounded-lg text-sm font-medium text-white"
                            style="background:#dc2626;">
                        Yes, Delete
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="content-inner ops-treatment-page">
        @if(session('success'))
            <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg p-4 mb-6">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg p-4 mb-6">{{ session('error') }}</div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <div class="lg:col-span-2 bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Scheme Details</h3>
                <dl class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Start Date</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $scheme->start_date->format('d M Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">End Date</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $scheme->end_date ? $scheme->end_date->format('d M Y') : 'Open-ended' }}</dd>
                    </div>
                    @if($scheme->isGoldSavings())
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Total Installments</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $scheme->total_installments ?? 11 }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Bonus Amount</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $scheme->bonus_month_value ? '₹' . number_format($scheme->bonus_month_value, 2) : 'Equals 1 month' }}</dd>
                    </div>
                    @else
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Discount</dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            @if($scheme->discount_type === 'percentage')
                                {{ $scheme->discount_value }}%
                            @elseif($scheme->discount_type === 'flat')
                                ₹{{ number_format($scheme->discount_value, 2) }}
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Min Purchase</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $scheme->min_purchase_amount ? '₹' . number_format($scheme->min_purchase_amount, 2) : 'None' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Max Discount</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $scheme->max_discount_amount ? '₹' . number_format($scheme->max_discount_amount, 2) : 'No cap' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Offer Target</dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            @if(($scheme->applies_to ?? 'all_items') === 'all_items')
                                All items
                            @elseif(($scheme->applies_to ?? 'all_items') === 'category')
                                Category: {{ $scheme->applies_to_value }}
                            @else
                                Sub-category: {{ $scheme->applies_to_value }}
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Auto Apply</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $scheme->auto_apply ? 'Yes' : 'No' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Priority</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $scheme->priority ?? 100 }}</dd>
                    </div>
                    @endif
                </dl>
                @if($scheme->description)
                    <div class="mt-4 pt-4 border-t border-gray-100">
                        <p class="text-sm text-gray-600">{{ $scheme->description }}</p>
                    </div>
                @endif
                @if($scheme->terms)
                    <div class="mt-4 pt-4 border-t border-gray-100">
                        <h4 class="text-xs uppercase tracking-wide text-gray-500 mb-1">Terms & Conditions</h4>
                        <p class="text-sm text-gray-600 whitespace-pre-line">{{ $scheme->terms }}</p>
                    </div>
                @endif
            </div>

            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Summary</h3>
                <div class="space-y-3">
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-500">Status</span>
                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $scheme->is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' }}">
                            {{ $scheme->isRunning() ? 'Running' : ($scheme->is_active ? 'Active' : 'Inactive') }}
                        </span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-500">Enrollments</span>
                        <span class="text-sm font-semibold text-gray-900">{{ $enrollments->total() }}</span>
                    </div>
                </div>
            </div>
        </div>

        @if($scheme->isGoldSavings() && $enrollments->count())
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
            <div class="p-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">Enrollments</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Monthly (₹)</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Paid</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total Paid</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach($enrollments as $enrollment)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3 text-sm">{{ $enrollment->customer->name ?? 'Unknown' }}</td>
                            <td class="px-6 py-3 text-sm text-right">₹{{ number_format($enrollment->monthly_amount, 2) }}</td>
                            <td class="px-6 py-3 text-sm text-center">{{ $enrollment->installments_paid }}/{{ $enrollment->total_installments }}</td>
                            <td class="px-6 py-3 text-sm text-right">₹{{ number_format($enrollment->total_paid, 2) }}</td>
                            <td class="px-6 py-3 text-center">
                                @php
                                    $statusColors = ['active' => 'bg-blue-100 text-blue-800', 'matured' => 'bg-green-100 text-green-800', 'cancelled' => 'bg-red-100 text-red-800', 'redeemed' => 'bg-purple-100 text-purple-800'];
                                @endphp
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $statusColors[$enrollment->status] ?? 'bg-gray-100' }}">
                                    {{ ucfirst($enrollment->status) }}
                                </span>
                            </td>
                            <td class="px-6 py-3 text-center">
                                <a href="{{ route('schemes.enrollment.show', $enrollment) }}" class="btn btn-secondary btn-xs"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>View</a>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($enrollments->hasPages())
                <div class="px-6 py-4 border-t border-gray-200">{{ $enrollments->links() }}</div>
            @endif
        </div>
        @endif
    </div>
</x-app-layout>
