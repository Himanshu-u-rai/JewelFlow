<x-app-layout>
    <x-page-header class="ops-treatment-header">
        <div>
            <h1 class="page-title">Reorder Alerts</h1>
            <p class="text-sm text-gray-600 mt-1">Stock threshold rules & low-stock alerts</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('reorder.create') }}"
               class="inline-flex items-center px-4 py-2 rounded-full transition-colors text-sm font-semibold shadow-sm"
               style="background: #0d9488; color: white; box-shadow: 0 8px 18px rgba(13,148,136,0.28);">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Add Rule
            </a>
        </div>
    </x-page-header>

    <div class="content-inner ops-treatment-page">
        @if(session('success'))
            <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg p-4 mb-6">{{ session('success') }}</div>
        @endif

        @if($alerts->count())
        <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 mb-6">
            <h3 class="text-sm font-semibold text-rose-800 mb-2 flex items-center"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>{{ $alerts->count() }} Low Stock Alert{{ $alerts->count() > 1 ? 's' : '' }}</h3>
            <div class="space-y-2">
                @foreach($alerts as $alert)
                <div class="flex items-center justify-between bg-white rounded-xl p-3 border border-rose-100">
                    <div>
                        <span class="text-sm font-medium text-gray-900">{{ $alert['category'] ?? 'All' }}</span>
                        @if($alert['sub_category'])
                            <span class="text-sm text-gray-500">› {{ $alert['sub_category'] }}</span>
                        @endif
                    </div>
                    <div class="text-sm">
                        <span class="text-rose-600 font-semibold">{{ $alert['current_stock'] }}</span>
                        <span class="text-gray-400">/</span>
                        <span class="text-gray-600">{{ $alert['threshold'] }} min</span>
                        @if($alert['vendor'])
                            <span class="text-xs text-gray-500 ml-2">→ {{ $alert['vendor']->name }}</span>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>
        </div>
        @else
        <div class="rounded-2xl border border-green-200 bg-green-50 p-4 mb-6">
            <p class="text-sm text-green-800 flex items-center"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>All stock levels are within configured thresholds.</p>
        </div>
        @endif

        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div class="p-5 border-b border-slate-200 bg-gradient-to-r from-white via-slate-50 to-white">
                <h3 class="text-lg font-semibold text-slate-900">Reorder Rules</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Category</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Sub-Category</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Min Threshold</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Preferred Vendor</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Active</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($rules as $rule)
                        <tr class="hover:bg-slate-50/70" data-deletable-row>
                            <td class="px-6 py-4 text-sm text-slate-900">{{ $rule->category ?? 'All' }}</td>
                            <td class="px-6 py-4 text-sm text-slate-500">{{ $rule->sub_category ?? '—' }}</td>
                            <td class="px-6 py-4 text-sm text-center font-medium text-slate-700">{{ $rule->min_stock_threshold }}</td>
                            <td class="px-6 py-4 text-sm text-slate-500">{{ $rule->vendor->name ?? '—' }}</td>
                            <td class="px-6 py-4 text-center">
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $rule->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">
                                    {{ $rule->is_active ? 'Yes' : 'No' }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <a href="{{ route('reorder.edit', $rule) }}" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>Edit</a>
                                        <form method="POST" action="{{ route('reorder.destroy', $rule) }}" data-confirm-message="Delete this rule?" data-ajax-delete>
                                        @csrf @method('DELETE')
                                        <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-700 shadow-sm transition hover:bg-rose-100"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-slate-500">
                                <p class="text-lg font-semibold mb-1 text-slate-700">No reorder rules</p>
                                <p class="text-sm">Set up minimum stock thresholds to receive alerts.</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
