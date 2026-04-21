<x-super-admin.layout>
    <div class="admin-toolbar mb-4">
        <div>
            <h3 class="text-lg font-semibold text-white">Subscription Plans</h3>
            <p class="text-sm text-slate-400">Configure products, billing cycles, and feature limits.</p>
        </div>
        <a href="{{ route('admin.plans.create') }}" class="admin-btn admin-btn-primary">Add Plan</a>
    </div>

    <div class="admin-panel overflow-hidden">
        <div class="admin-table-wrap">
            <table class="w-full text-sm admin-table">
                <thead class="bg-slate-800/80 text-slate-300">
                    <tr>
                        <th class="py-3.5 pl-4 pr-3 text-left sm:pl-6">Plan</th>
                        <th class="px-3 py-3.5 text-left">Code</th>
                        <th class="px-3 py-3.5 text-left">Monthly Price</th>
                        <th class="px-3 py-3.5 text-left">Yearly Price</th>
                        <th class="px-3 py-3.5 text-left">Trial Days</th>
                        <th class="px-3 py-3.5 text-left">Staff Limit</th>
                        <th class="px-3 py-3.5 text-left">Item Limit</th>
                        <th class="px-3 py-3.5 text-left">Subscribers</th>
                        <th class="px-3 py-3.5 text-left">Active Subscribers</th>
                        <th class="px-3 py-3.5 text-left">Status</th>
                        <th class="py-3.5 pl-3 pr-4 text-right sm:pr-6">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($plans as $plan)
                        @php
                            $canDeactivate = !($plan->is_active && (int) $plan->active_subscriptions_count > 0);
                        @endphp
                        <tr class="border-t border-slate-800 text-slate-200">
                            <td class="whitespace-nowrap py-4 pl-4 pr-3 font-medium sm:pl-6">{{ $plan->name }}</td>
                            <td class="whitespace-nowrap px-3 py-4 text-slate-400 font-mono">{{ $plan->code }}</td>
                            <td class="whitespace-nowrap px-3 py-4 text-slate-300">₹{{ number_format($plan->price_monthly) }}</td>
                            <td class="whitespace-nowrap px-3 py-4 text-slate-300">
                                {{ $plan->price_yearly ? '₹' . number_format($plan->price_yearly) : '—' }}
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-slate-300">{{ $plan->trial_days }}</td>
                            <td class="whitespace-nowrap px-3 py-4">
                                @php $sl = $plan->features['staff_limit'] ?? null; @endphp
                                @if($sl === null || $sl == -1)
                                    <span class="admin-badge admin-badge-emerald">Unlimited</span>
                                @else
                                    <span class="font-semibold text-amber-300">{{ $sl }}</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-3 py-4">
                                @php $mi = $plan->features['max_items'] ?? null; @endphp
                                @if($mi === null || $mi == -1)
                                    <span class="admin-badge admin-badge-emerald">Unlimited</span>
                                @else
                                    <span class="text-slate-300">{{ number_format($mi) }}</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-slate-300">{{ $plan->subscriptions_count }}</td>
                            <td class="whitespace-nowrap px-3 py-4 text-slate-300">{{ $plan->active_subscriptions_count }}</td>
                            <td class="whitespace-nowrap px-3 py-4">
                                @if ($plan->is_active)
                                    <span class="admin-badge admin-badge-emerald">Active</span>
                                @else
                                    <span class="admin-badge admin-badge-slate">Inactive</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap py-4 pl-3 pr-4 text-right sm:pr-6">
                                <div class="admin-action-row">
                                    <a href="{{ route('admin.plans.edit', $plan) }}" class="admin-btn admin-btn-secondary admin-btn-xs">
                                        Edit
                                    </a>
                                    <form action="{{ route('admin.plans.toggle', $plan) }}" method="POST" class="inline-block"
                                        data-confirm-message="{{ $plan->is_active ? 'Deactivate this plan? Existing active subscriptions must be zero.' : 'Activate this plan?' }}">
                                        @csrf
                                        @method('PATCH')
                                        <button
                                            type="submit"
                                            class="admin-btn admin-btn-xs {{ $plan->is_active ? 'admin-btn-danger' : 'admin-btn-success' }} {{ !$canDeactivate ? 'admin-btn-disabled' : '' }}"
                                            @if(!$canDeactivate) disabled title="Cannot deactivate while active subscribers exist." @endif
                                        >
                                            {{ $plan->is_active ? 'Deactivate' : 'Activate' }}
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-super-admin.layout>
