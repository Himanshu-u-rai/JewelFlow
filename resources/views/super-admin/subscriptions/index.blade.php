<x-super-admin.layout>
    @php
        $allStatuses = ['active', 'trial', 'grace', 'read_only', 'expired', 'cancelled'];
        $statusTone = [
            'active' => 'admin-badge-emerald',
            'trial' => 'admin-badge-sky',
            'grace' => 'admin-badge-amber',
            'read_only' => 'admin-badge-amber',
            'expired' => 'admin-badge-rose',
            'cancelled' => 'admin-badge-slate',
            'suspended' => 'admin-badge-rose',
        ];
    @endphp

    <div class="mb-4">
        <h3 class="text-lg font-semibold text-white">Subscription Management</h3>
        <p class="text-sm text-slate-400">Track lifecycle states, renewals, and billing risk.</p>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
        @foreach($allStatuses as $status)
            <div class="admin-kpi {{ $status === 'active' ? 'admin-tone-emerald' : ($status === 'trial' ? 'admin-tone-sky' : ($status === 'grace' || $status === 'read_only' ? 'admin-tone-amber' : ($status === 'expired' ? 'admin-tone-rose' : 'admin-tone-slate'))) }}">
                <div class="admin-kpi__label">{{ ucfirst(str_replace('_', ' ', $status)) }}</div>
                <div class="admin-kpi__value text-3xl">{{ $stats[$status] ?? 0 }}</div>
            </div>
        @endforeach
    </div>

    <div class="admin-panel p-4 mb-6">
        <form method="GET" action="{{ route('admin.subscriptions.index') }}">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label for="q" class="block text-sm font-medium text-slate-300">Shop Name</label>
                    <input type="text" name="q" id="q" class="admin-control mt-2" value="{{ request('q') }}" placeholder="Search by shop name...">
                </div>
                <div>
                    <label for="status" class="block text-sm font-medium text-slate-300">Status</label>
                    <select name="status" id="status" class="admin-control admin-select mt-2">
                        <option value="">All Statuses</option>
                        @foreach($allStatuses as $status)
                            <option value="{{ $status }}" @if(request('status') === $status) selected @endif>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="plan_id" class="block text-sm font-medium text-slate-300">Plan</label>
                    <select name="plan_id" id="plan_id" class="admin-control admin-select mt-2">
                        <option value="">All Plans</option>
                        @foreach($allPlans as $plan)
                            <option value="{{ $plan->id }}" @if(request('plan_id') == $plan->id) selected @endif>{{ $plan->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="admin-btn admin-btn-primary">Filter</button>
                    <a href="{{ route('admin.subscriptions.index') }}" class="admin-btn admin-btn-secondary">Clear</a>
                </div>
            </div>
        </form>
    </div>

    <div class="admin-panel overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm admin-table">
                <thead class="bg-slate-800/80 text-slate-300">
                    <tr>
                        <th class="py-3.5 pl-4 pr-3 text-left sm:pl-6">Shop</th>
                        <th class="px-3 py-3.5 text-left">Plan</th>
                        <th class="px-3 py-3.5 text-left">Status</th>
                        <th class="px-3 py-3.5 text-left">Started</th>
                        <th class="px-3 py-3.5 text-left">Expires/Renews</th>
                        <th class="px-3 py-3.5 text-left">Days Left</th>
                        <th class="py-3.5 pl-3 pr-4 text-right sm:pr-6">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($subscriptions as $sub)
                        @php
                            $rowClass = '';
                            if ($sub->is_trial_ending) $rowClass = 'bg-yellow-900/30';
                            if ($sub->status === 'expired' || $sub->status === 'grace' || $sub->status === 'suspended') $rowClass = 'bg-rose-950/30';
                        @endphp
                        <tr class="{{ $rowClass }} border-t border-slate-800 text-slate-200">
                            <td class="whitespace-nowrap py-4 pl-4 pr-3 font-medium sm:pl-6">
                                @if($sub->shop_id)
                                    <a href="{{ route('admin.shops.show', ['shop' => $sub->shop_id]) }}" class="hover:text-amber-300 transition-colors">
                                        {{ $sub->shop->name ?? 'N/A' }}
                                    </a>
                                @else
                                    <span class="text-slate-400">{{ $sub->shop->name ?? 'N/A' }}</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-slate-300">{{ $sub->plan->name ?? 'N/A' }}</td>
                            <td class="whitespace-nowrap px-3 py-4">
                                <span class="admin-badge {{ $statusTone[$sub->status] ?? 'admin-badge-slate' }}">
                                    {{ ucfirst(str_replace('_', ' ', $sub->status)) }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-slate-300">{{ $sub->starts_at->format('d M, Y') }}</td>
                            <td class="whitespace-nowrap px-3 py-4 text-slate-300">{{ $sub->ends_at->format('d M, Y') }}</td>
                            <td class="whitespace-nowrap px-3 py-4 font-semibold {{ $sub->days_remaining < 0 ? 'text-rose-300' : 'text-slate-200' }}">
                                {{ $sub->days_remaining }}
                            </td>
                            <td class="whitespace-nowrap py-4 pl-3 pr-4 text-right sm:pr-6">
                                @if($sub->shop_id)
                                    <a href="{{ route('admin.shops.show', ['shop' => $sub->shop_id]) }}" class="admin-btn admin-btn-secondary admin-btn-xs">Manage</a>
                                @else
                                    <span class="text-slate-500 text-xs">N/A</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-12 text-slate-400">No subscriptions found matching criteria.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4 px-4 pb-4">
            {{ $subscriptions->links() }}
        </div>
    </div>
</x-super-admin.layout>
