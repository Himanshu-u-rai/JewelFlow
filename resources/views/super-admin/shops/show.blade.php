<x-super-admin.layout title="Shop Management" subtitle="Search, inspect, activate, and deactivate tenant shops.">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div>
            <h2 class="text-xl font-semibold text-white">{{ $shop->name }}</h2>
            <div class="flex flex-wrap items-center gap-2 mt-1 text-sm text-slate-400">
                <span>Owner Mobile: {{ $shop->owner_mobile ?? 'N/A' }}</span>
                @forelse(($activeEditions ?? []) as $ed)
                    @php
                        $cls = match($ed) {
                            'retailer'     => 'admin-badge-sky',
                            'manufacturer' => 'admin-badge-amber',
                            'dhiran'       => 'admin-badge-emerald',
                            default        => 'admin-badge-slate',
                        };
                    @endphp
                    <span class="admin-badge {{ $cls }}">{{ ucfirst($ed) }}</span>
                @empty
                    <span class="admin-badge admin-badge-rose">No editions</span>
                @endforelse
                <x-super-admin.access-mode-badge :shop="$shop" />
            </div>
        </div>
        <div class="flex items-center gap-2">
            @if(auth('platform_admin')->user()?->isSuperAdmin())
                <form method="POST" action="{{ route('admin.shops.impersonate', $shop) }}">
                    @csrf
                    <button class="admin-btn admin-btn-primary">Login as Shop Owner</button>
                </form>
            @endif
            <a href="{{ route('admin.shops.index') }}" class="admin-btn admin-btn-secondary">Back to Shops</a>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
        <div class="admin-kpi"><div class="admin-kpi__label">Users</div><div class="admin-kpi__value text-2xl">{{ $stats['users'] }}</div></div>
        <div class="admin-kpi"><div class="admin-kpi__label">Customers</div><div class="admin-kpi__value text-2xl">{{ $stats['customers'] }}</div></div>
        <div class="admin-kpi"><div class="admin-kpi__label">Items</div><div class="admin-kpi__value text-2xl">{{ $stats['items'] }}</div></div>
        <div class="admin-kpi"><div class="admin-kpi__label">Invoices</div><div class="admin-kpi__value text-2xl">{{ $stats['invoices'] }}</div></div>
        <div class="admin-kpi"><div class="admin-kpi__label">Repairs</div><div class="admin-kpi__value text-2xl">{{ $stats['repairs'] }}</div></div>
    </div>

    {{-- ── Shop Details ──────────────────────────────────────── --}}
    <div class="admin-panel p-4 mb-6">
        <h3 class="font-semibold text-white mb-4">Shop Details</h3>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

            {{-- Contact --}}
            <div>
                <div class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-3">Contact</div>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-400 shrink-0">Phone</dt>
                        <dd class="text-slate-100 text-right break-all">{{ $shop->phone ?: '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-400 shrink-0">WhatsApp</dt>
                        <dd class="text-slate-100 text-right break-all">{{ $shop->shop_whatsapp ?: '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-400 shrink-0">Email</dt>
                        <dd class="text-slate-100 text-right break-all">{{ $shop->shop_email ?: '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-400 shrink-0">Est. Year</dt>
                        <dd class="text-slate-100 text-right">{{ $shop->established_year ?: '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-400 shrink-0">Reg. No.</dt>
                        <dd class="text-slate-100 text-right break-all">{{ $shop->shop_registration_number ?: '—' }}</dd>
                    </div>
                </dl>
            </div>

            {{-- Owner --}}
            <div>
                <div class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-3">Owner</div>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-400 shrink-0">Name</dt>
                        <dd class="text-slate-100 text-right">
                            {{ trim(($shop->owner_first_name ?? '') . ' ' . ($shop->owner_last_name ?? '')) ?: '—' }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-400 shrink-0">Mobile</dt>
                        <dd class="text-slate-100 text-right break-all">{{ $shop->owner_mobile ?: '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-400 shrink-0">Email</dt>
                        <dd class="text-slate-100 text-right break-all">{{ $shop->owner_email ?: '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-400 shrink-0">GST No.</dt>
                        <dd class="text-slate-100 text-right font-mono text-xs tracking-wide">{{ $shop->gst_number ?: '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-400 shrink-0">GST Rate</dt>
                        <dd class="text-slate-100 text-right">{{ $shop->gst_rate ? $shop->gst_rate . '%' : '—' }}</dd>
                    </div>
                </dl>
            </div>

            {{-- Address --}}
            <div>
                <div class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-3">Address</div>
                <dl class="space-y-2 text-sm">
                    @php
                        $addressFull = collect([
                            $shop->address_line1 ?: $shop->address,
                            $shop->address_line2,
                            $shop->city,
                            $shop->state,
                            $shop->pincode,
                            $shop->country,
                        ])->filter()->implode(', ');
                    @endphp
                    @if($addressFull)
                        <div class="text-slate-100 leading-relaxed">{{ $addressFull }}</div>
                    @else
                        <div class="text-slate-500">No address on file</div>
                    @endif
                    @if($shop->state_code)
                        <div class="flex justify-between gap-3 mt-2">
                            <dt class="text-slate-400 shrink-0">State Code</dt>
                            <dd class="text-slate-100 text-right font-mono">{{ $shop->state_code }}</dd>
                        </div>
                    @endif
                    @if($shop->catalog_slug)
                        <div class="flex justify-between gap-3 mt-2">
                            <dt class="text-slate-400 shrink-0">Catalog Slug</dt>
                            <dd class="text-slate-100 text-right font-mono text-xs">{{ $shop->catalog_slug }}</dd>
                        </div>
                    @endif
                    @if($shop->wastage_recovery_percent)
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-400 shrink-0">Wastage Recovery</dt>
                            <dd class="text-slate-100 text-right">{{ $shop->wastage_recovery_percent }}%</dd>
                        </div>
                    @endif
                </dl>
            </div>

        </div>
    </div>

    <div class="admin-panel p-4 mb-6">
        <h3 class="font-semibold text-white mb-1">Platform Control</h3>
        <p class="text-xs text-slate-400 mb-3">
            <strong class="text-slate-300">Active</strong> — full access &nbsp;·&nbsp;
            <strong class="text-slate-300">Read-Only</strong> — can view, writes blocked &nbsp;·&nbsp;
            <strong class="text-slate-300">Suspended</strong> — fully blocked, logged out
        </p>

        {{--
            Applying Read-Only or Suspended here records this administrator as the
            actor, which moves the shop onto the administrative axis. That axis is
            not self-service recoverable by design, so the note below states the
            consequence up front. The controls themselves are unchanged.
        --}}
        <p class="text-xs text-amber-300/90 mb-3">
            ⚠ Read-Only and Suspended are recorded as <strong>administrator</strong>
            restrictions. An administrator restriction is not self-service
            recoverable — while it is in place the owner
            <strong>cannot buy or renew a plan</strong> and is told to contact
            support. Use it for deliberate holds only, never to reflect an
            unpaid or expired subscription.
        </p>

        @if(($shop->access_mode ?? 'active') === 'read_only' && $shop->suspensionIsSubscriptionManaged())
            {{--
                A read_only row that the authoritative classifier attributes to the
                subscription lifecycle rather than to an administrator. Nothing is
                wrong with this shop's access path — saying so here stops an
                operator "correcting" it with a control that would break renewal.
            --}}
            <p class="text-xs text-sky-300/90 mb-4">
                This shop's subscription term ended — this is <strong>not</strong> an
                administrator restriction. The owner is already sent to the plan
                picker on login and can choose a plan to restore access on their own.
                <strong>No action is needed here.</strong> Applying a mode below would
                record an administrator restriction and remove that recovery path.
            </p>
        @endif

        <form method="POST" action="{{ route('admin.shops.status', $shop) }}" class="space-y-3">
            @csrf
            @method('PATCH')

            <div class="flex flex-wrap gap-3">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="access_mode" value="active"
                        {{ ($shop->access_mode ?? 'active') === 'active' ? 'checked' : '' }}
                        class="accent-emerald-500">
                    <span class="text-sm font-medium px-3 py-1.5 rounded-lg border
                        {{ ($shop->access_mode ?? 'active') === 'active'
                            ? 'border-emerald-500 bg-emerald-500/20 text-emerald-200'
                            : 'border-slate-700 bg-slate-800 text-slate-300' }}">
                        ✅ Active
                    </span>
                </label>

                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="access_mode" value="read_only"
                        {{ $shop->access_mode === 'read_only' ? 'checked' : '' }}
                        class="accent-amber-500">
                    <span class="text-sm font-medium px-3 py-1.5 rounded-lg border
                        {{ $shop->access_mode === 'read_only'
                            ? 'border-amber-500 bg-amber-500/20 text-amber-200'
                            : 'border-slate-700 bg-slate-800 text-slate-300' }}">
                        🔒 Read-Only
                    </span>
                </label>

                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="access_mode" value="suspended"
                        {{ $shop->access_mode === 'suspended' ? 'checked' : '' }}
                        class="accent-rose-500">
                    <span class="text-sm font-medium px-3 py-1.5 rounded-lg border
                        {{ $shop->access_mode === 'suspended'
                            ? 'border-rose-500 bg-rose-500/20 text-rose-200'
                            : 'border-slate-700 bg-slate-800 text-slate-300' }}">
                        ⛔ Suspended
                    </span>
                </label>
            </div>

            <div>
                <label class="block text-xs text-slate-400 mb-1">Reason (optional — shown in audit log)</label>
                <input type="text" name="reason"
                       value="{{ old('reason', $shop->suspension_reason) }}"
                       placeholder="e.g. Payment overdue, compliance hold..."
                       maxlength="500"
                       class="admin-control">
            </div>

            <div class="flex items-center gap-3">
                <button type="submit"
                        class="admin-btn admin-btn-primary">
                    Apply Access Mode
                </button>

                @if($shop->suspended_at)
                    <span class="text-xs text-slate-400">
                        Last changed {{ $shop->suspended_at->format('d M Y, h:i A') }}
                        @if($shop->suspension_reason)
                            · {{ $shop->suspension_reason }}
                        @endif
                    </span>
                @endif
            </div>
        </form>
    </div>

    {{-- ── Edition management ──────────────────────────────── --}}
    <div class="admin-panel p-4 mb-6">
        <div class="flex items-start justify-between gap-3 mb-3">
            <div>
                <h3 class="font-semibold text-white mb-1">Services (Editions)</h3>
                <p class="text-xs text-slate-400">
                    Active services determine which features and modules this shop can use.
                    Every grant or revoke requires a reason and is recorded in the audit log.
                </p>
            </div>
        </div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4">
            {{-- Active editions with revoke controls --}}
            <div class="rounded-lg border border-slate-700 bg-slate-900/40 p-3">
                <div class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Active</div>
                @forelse($activeEditions as $ed)
                    <details class="mb-2 last:mb-0">
                        <summary class="flex items-center justify-between cursor-pointer list-none rounded-md bg-slate-800/60 px-3 py-2">
                            <span class="text-sm text-slate-100 font-medium">{{ ucfirst($ed) }}</span>
                            <span class="text-xs text-rose-300 hover:text-rose-200">Revoke →</span>
                        </summary>
                        <form method="POST" action="{{ route('admin.shops.editions.revoke', $shop) }}" class="mt-2 space-y-2 rounded-md border border-slate-700 bg-slate-900/60 p-3">
                            @csrf
                            <input type="hidden" name="edition" value="{{ $ed }}">
                            <label class="block text-xs text-slate-400">Reason (required, audit log)</label>
                            <textarea name="reason" rows="2" required minlength="4" maxlength="500"
                                      class="admin-control w-full"
                                      placeholder="e.g. Customer requested removal of Dhiran service on 2026-04-18 ticket #4421"></textarea>
                            <div class="flex items-center gap-2">
                                <button type="submit" class="admin-btn admin-btn-danger admin-btn-xs">Revoke {{ ucfirst($ed) }}</button>
                                <span class="text-xs text-slate-500">Shop must keep at least one active edition.</span>
                            </div>
                        </form>
                    </details>
                @empty
                    <div class="text-xs text-rose-300">No active editions. Grant one below.</div>
                @endforelse
            </div>

            {{-- Grant (available) editions --}}
            <div class="rounded-lg border border-slate-700 bg-slate-900/40 p-3">
                <div class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Available to grant</div>
                @forelse($availableEditions as $ed)
                    <details class="mb-2 last:mb-0">
                        <summary class="flex items-center justify-between cursor-pointer list-none rounded-md bg-slate-800/60 px-3 py-2">
                            <span class="text-sm text-slate-100 font-medium">{{ ucfirst($ed) }}</span>
                            <span class="text-xs text-emerald-300 hover:text-emerald-200">Grant →</span>
                        </summary>
                        <form method="POST" action="{{ route('admin.shops.editions.grant', $shop) }}" class="mt-2 space-y-2 rounded-md border border-slate-700 bg-slate-900/60 p-3">
                            @csrf
                            <input type="hidden" name="edition" value="{{ $ed }}">
                            <label class="block text-xs text-slate-400">Reason (required, audit log)</label>
                            <textarea name="reason" rows="2" required minlength="4" maxlength="500"
                                      class="admin-control w-full"
                                      placeholder="e.g. Shop upgraded to include manufacturing on 2026-04-19 via support ticket #4521"></textarea>
                            <button type="submit" class="admin-btn admin-btn-primary admin-btn-xs">Grant {{ ucfirst($ed) }}</button>
                        </form>
                    </details>
                @empty
                    <div class="text-xs text-slate-500">All editions already granted.</div>
                @endforelse
            </div>
        </div>

        {{-- History (audit trail) --}}
        @if($editionHistory->isNotEmpty())
            <div class="mt-3">
                <div class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">History</div>
                <div class="overflow-x-auto rounded-lg border border-slate-800">
                    <table class="w-full text-xs admin-table">
                        <thead class="bg-slate-800/70 text-slate-300">
                            <tr>
                                <th class="px-3 py-2 text-left w-14">#</th>
                                <th class="px-3 py-2 text-left">Edition</th>
                                <th class="px-3 py-2 text-left">Granted</th>
                                <th class="px-3 py-2 text-left">Revoked</th>
                                <th class="px-3 py-2 text-left">Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($editionHistory as $entry)
                                <tr class="border-t border-slate-800 text-slate-200">
                                    <td class="px-3 py-2 admin-table-index">{{ $loop->iteration }}</td>
                                    <td class="px-3 py-2 font-medium">{{ ucfirst($entry->edition) }}</td>
                                    <td class="px-3 py-2">{{ optional($entry->activated_at)->format('d M Y, H:i') ?? '—' }}</td>
                                    <td class="px-3 py-2">
                                        @if($entry->deactivated_at)
                                            <span class="text-rose-300">{{ $entry->deactivated_at->format('d M Y, H:i') }}</span>
                                        @else
                                            <span class="text-emerald-300">Active</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-slate-400">{{ $entry->deactivation_reason ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="admin-table-footer">Showing {{ $editionHistory->count() }} edition history row{{ $editionHistory->count() === 1 ? '' : 's' }}.</div>
            </div>
        @endif
    </div>

    <div class="admin-panel admin-table-panel">
        <div class="admin-panel-header">
            <h3 class="font-semibold text-white">Shop Users</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm admin-table">
                <thead class="bg-slate-800/80 text-slate-300">
                    <tr>
                        <th class="px-4 py-2 text-left w-16">#</th>
                        <th class="px-4 py-2 text-left">Name</th>
                        <th class="px-4 py-2 text-left">Mobile</th>
                        <th class="px-4 py-2 text-left">Role</th>
                        <th class="px-4 py-2 text-left">Status</th>
                        <th class="px-4 py-2 text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $ownerName = trim(($shop->owner_first_name ?? '') . ' ' . ($shop->owner_last_name ?? ''));
                    @endphp
                    @foreach($shop->users as $user)
                        <tr class="border-t border-slate-800 text-slate-200">
                            @php
                                $userFullName = $user->name ?? '';
                                if ($user->mobile_number === $shop->owner_mobile && $ownerName !== '') {
                                    $userFullName = $ownerName;
                                }
                            @endphp
                            <td class="px-4 py-3 admin-table-index">{{ $loop->iteration }}</td>
                            <td class="px-4 py-3">{{ $userFullName !== '' ? $userFullName : '-' }}</td>
                            <td class="px-4 py-3">{{ $user->mobile_number }}</td>
                            <td class="px-4 py-3">{{ $user->role?->display_name ?? '-' }}</td>
                            <td class="px-4 py-2">
                                <span class="admin-badge {{ $user->is_active ? 'admin-badge-emerald' : 'admin-badge-rose' }}">
                                    {{ $user->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="inline-flex items-center gap-2">
                                    <a href="{{ route('admin.users.show', $user) }}" class="admin-btn admin-btn-secondary admin-btn-xs">Inspect</a>
                                    @if(auth('platform_admin')->user()?->isSuperAdmin())
                                        <form method="POST" action="{{ route('admin.shops.impersonate', $shop) }}">
                                            @csrf
                                            <input type="hidden" name="user_id" value="{{ $user->id }}">
                                            <button class="admin-btn admin-btn-primary admin-btn-xs">Impersonate</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="admin-table-footer">Showing {{ $shop->users->count() }} shop user{{ $shop->users->count() === 1 ? '' : 's' }}.</div>
    </div>

    {{-- ── Subscription Management ──────────────────────────── --}}
    <div class="admin-panel p-4 mt-6">
        <div class="flex items-start justify-between gap-3 mb-4">
            <div>
                <h3 class="font-semibold text-white mb-1">Subscription Management</h3>
                <p class="text-xs text-slate-400">Manually assign or update this shop's subscription. Setting a price generates an invoice and sends a receipt email.</p>
            </div>
            @if($currentSubscription)
                <div class="text-right text-xs text-slate-400 shrink-0">
                    <div>Current: <span class="text-slate-200 font-medium">{{ $currentSubscription->plan?->name ?? 'Unknown' }}</span></div>
                    <div class="mt-0.5">
                        <span class="admin-badge admin-badge-{{ match($currentSubscription->status) {
                            'active', 'trial'        => 'emerald',
                            'grace', 'read_only'     => 'amber',
                            default                  => 'rose'
                        } }}">{{ ucfirst($currentSubscription->status) }}</span>
                    </div>
                </div>
            @endif
        </div>

        <form method="POST" action="{{ route('admin.shops.subscription', $shop) }}" class="space-y-4">
            @csrf
            @method('PATCH')

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">

                <div>
                    <label class="block text-xs text-slate-400 mb-1">Plan <span class="text-rose-400">*</span></label>
                    <select name="plan_id" id="sub-plan" required class="admin-control admin-select">
                        <option value="">— Select plan —</option>
                        @foreach($plans as $plan)
                            <option value="{{ $plan->id }}" data-grace-days="{{ $plan->grace_days }}"
                                {{ old('plan_id', $currentSubscription?->plan_id) == $plan->id ? 'selected' : '' }}>
                                {{ $plan->name }}
                                (₹{{ number_format($plan->price_monthly, 0) }}/mo · ₹{{ number_format($plan->price_yearly, 0) }}/yr)
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs text-slate-400 mb-1">Status <span class="text-rose-400">*</span></label>
                    <select name="status" required class="admin-control admin-select">
                        @foreach(['trial', 'active', 'grace', 'read_only', 'suspended', 'cancelled', 'expired'] as $s)
                            <option value="{{ $s }}"
                                {{ old('status', $currentSubscription?->status) === $s ? 'selected' : '' }}>
                                {{ ucfirst(str_replace('_', ' ', $s)) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs text-slate-400 mb-1">Billing Cycle</label>
                    <select name="billing_cycle" id="sub-cycle" class="admin-control admin-select">
                        <option value="">— Not set —</option>
                        <option value="monthly" {{ old('billing_cycle', $currentSubscription?->billing_cycle) === 'monthly' ? 'selected' : '' }}>Monthly</option>
                        <option value="yearly"  {{ old('billing_cycle', $currentSubscription?->billing_cycle) === 'yearly'  ? 'selected' : '' }}>Yearly</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs text-slate-400 mb-1">From <span class="text-rose-400">*</span></label>
                    <input type="date" name="starts_at" id="sub-from" required
                           value="{{ old('starts_at', $suggestedTerm['from']->format('Y-m-d')) }}"
                           class="admin-control">
                </div>

                <div>
                    <label class="block text-xs text-slate-400 mb-1">To <span class="text-rose-400">*</span></label>
                    <input type="date" name="ends_at" id="sub-to" required
                           value="{{ old('ends_at', $suggestedTerm['to']?->format('Y-m-d')) }}"
                           class="admin-control">
                    <p class="text-xs text-slate-500 mt-1">Suggested from the billing cycle. Edit freely — your exact dates are saved as-is. Grace begins after To.</p>
                </div>

                {{-- Guided-extension preview: previous vs proposed term, grace, invoice period, and warnings. --}}
                <div class="md:col-span-2 lg:col-span-3 rounded-lg border border-slate-700/60 bg-slate-800/40 p-3 text-xs"
                     id="sub-preview"
                     data-prev-from="{{ $currentSubscription?->starts_at?->format('Y-m-d') }}"
                     data-prev-to="{{ $currentSubscription?->ends_at?->format('Y-m-d') }}"
                     data-prev-grace="{{ $currentSubscription?->grace_ends_at?->format('Y-m-d') }}"
                     data-today="{{ now()->format('Y-m-d') }}">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <div>
                            <div class="text-slate-500">Previous term</div>
                            <div class="text-slate-300">{{ $currentSubscription?->starts_at?->format('d M Y') ?? '—' }} → {{ $currentSubscription?->ends_at?->format('d M Y') ?? '—' }}</div>
                            <div class="text-slate-500 mt-1">Previous grace end</div>
                            <div class="text-slate-300">{{ $currentSubscription?->grace_ends_at?->format('d M Y') ?? '—' }}</div>
                        </div>
                        <div>
                            <div class="text-slate-500">Proposed term</div>
                            <div class="text-emerald-300" id="sub-proposed-term">—</div>
                            <div class="text-slate-500 mt-1">Proposed grace end</div>
                            <div class="text-emerald-300" id="sub-proposed-grace">—</div>
                            <div class="text-slate-500 mt-1">Invoice period (if priced)</div>
                            <div class="text-slate-300" id="sub-invoice-period">—</div>
                        </div>
                    </div>
                    <ul id="sub-warnings" class="mt-2 space-y-1 text-amber-300"></ul>
                </div>

                <div>
                    <label class="block text-xs text-slate-400 mb-1">
                        Price Paid (₹)
                        <span class="text-slate-500 font-normal">— leave blank for free/comp</span>
                    </label>
                    <input type="number" name="price_paid" min="0.01" max="9999999.99" step="0.01"
                           value="{{ old('price_paid') }}"
                           placeholder="e.g. 2999.00"
                           class="admin-control">
                    <p class="text-xs text-slate-500 mt-1">Setting a price generates a GST invoice and sends a receipt email to the shop owner.</p>
                </div>

                <div>
                    <label class="block text-xs text-slate-400 mb-1">Internal Notes</label>
                    <input type="text" name="notes" maxlength="1000"
                           value="{{ old('notes') }}"
                           placeholder="e.g. Discounted onboarding pricing"
                           class="admin-control">
                </div>

                <div>
                    <label class="block text-xs text-slate-400 mb-1">Reason (audit log)</label>
                    <input type="text" name="reason" maxlength="500"
                           value="{{ old('reason') }}"
                           placeholder="e.g. Renewed via support ticket #4521"
                           class="admin-control">
                </div>

            </div>

            <div>
                <button type="submit" class="admin-btn admin-btn-primary">Apply Subscription Change</button>
            </div>
        </form>

        <script>
        (function () {
            const from = document.getElementById('sub-from');
            const to = document.getElementById('sub-to');
            const cycle = document.getElementById('sub-cycle');
            const planSel = document.getElementById('sub-plan');
            const price = document.querySelector('input[name="price_paid"]');
            const box = document.getElementById('sub-preview');
            if (!from || !to || !cycle || !box) return;

            const termEl = document.getElementById('sub-proposed-term');
            const graceEl = document.getElementById('sub-proposed-grace');
            const invEl = document.getElementById('sub-invoice-period');
            const warnEl = document.getElementById('sub-warnings');
            const today = box.dataset.today || '';
            const prevTo = box.dataset.prevTo || '';

            let toDirty = false;
            to.addEventListener('input', () => { toDirty = true; refresh(); });

            const parse = s => s ? new Date(s + 'T00:00:00') : null;
            const fmt = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
            const human = d => d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
            const addCycle = (d, c) => { const n = new Date(d); c === 'yearly' ? n.setFullYear(n.getFullYear() + 1) : n.setMonth(n.getMonth() + 1); return n; };
            const graceDays = () => parseInt(planSel?.selectedOptions[0]?.dataset.graceDays || '0', 10) || 0;

            function refresh() {
                const f = parse(from.value);
                const c = cycle.value;
                if (f && c && !toDirty) to.value = fmt(addCycle(f, c));
                const t = parse(to.value);

                termEl.textContent = (f && t) ? human(f) + ' → ' + human(t) : '—';
                if (t) { const g = new Date(t); g.setDate(g.getDate() + graceDays()); graceEl.textContent = human(g); } else graceEl.textContent = '—';
                invEl.textContent = (f && t && parseFloat(price?.value || '0') > 0) ? human(f) + ' → ' + human(t) : '—';

                const w = [];
                if (f && today && from.value < today) w.push('Backdating: From is before today (' + today + ').');
                if (f && prevTo) { if (from.value > prevTo) w.push('Gap: From is after the previous term end (' + prevTo + ').'); else if (from.value < prevTo) w.push('Overlap: From is before the previous term end (' + prevTo + ').'); }
                if (f && t && c && fmt(addCycle(f, c)) !== to.value) w.push('Duration differs from the selected ' + c + ' cycle — an override reason will be required.');
                if (t && f && to.value <= from.value) w.push('To must be after From.');
                warnEl.replaceChildren(...w.map(x => { const li = document.createElement('li'); li.textContent = '⚠ ' + x; return li; }));
            }

            [from, cycle, planSel, price].forEach(el => el && el.addEventListener('input', refresh));
            refresh();
        })();
        </script>
    </div>

    {{-- Billing & Invoices --}}
    <div class="admin-panel admin-table-panel mt-6">
        <div class="admin-panel-header">
            <h3 class="font-semibold text-white">Billing & Invoices</h3>
            <span class="text-xs text-slate-400">Last 10 platform invoices</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm admin-table">
                <thead class="bg-slate-800/80 text-slate-300">
                    <tr>
                        <th class="px-4 py-2 text-left w-16">#</th>
                        <th class="px-4 py-2 text-left">Invoice #</th>
                        <th class="px-4 py-2 text-left">Plan</th>
                        <th class="px-4 py-2 text-left">Cycle</th>
                        <th class="px-4 py-2 text-left">Period</th>
                        <th class="px-4 py-2 text-right">Before Tax</th>
                        <th class="px-4 py-2 text-right">GST</th>
                        <th class="px-4 py-2 text-right">Total</th>
                        <th class="px-4 py-2 text-left">Method</th>
                        <th class="px-4 py-2 text-left">Date</th>
                        <th class="px-4 py-2 text-left">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($billingInvoices as $inv)
                        <tr class="border-t border-slate-800 text-slate-200">
                            <td class="px-4 py-3 admin-table-index">{{ $loop->iteration }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $inv->invoice_number }}</td>
                            <td class="px-4 py-3">{{ $inv->plan?->name ?? '—' }}</td>
                            <td class="px-4 py-3 capitalize text-slate-400">{{ $inv->billing_cycle }}</td>
                            <td class="px-4 py-3 text-xs text-slate-400">
                                {{ $inv->billing_period_start->format('d M Y') }} – {{ $inv->billing_period_end->format('d M Y') }}
                            </td>
                            <td class="px-4 py-3 text-right text-slate-300">₹{{ number_format($inv->amount_before_tax, 2) }}</td>
                            <td class="px-4 py-3 text-right text-slate-400 text-xs">
                                {{ number_format($inv->gst_rate, 0) }}% · ₹{{ number_format($inv->gst_amount, 2) }}
                            </td>
                            <td class="px-4 py-3 text-right font-semibold text-white">₹{{ number_format($inv->total_amount, 2) }}</td>
                            <td class="px-4 py-3 capitalize text-slate-400 text-xs">{{ $inv->payment_method }}</td>
                            <td class="px-4 py-3 text-xs text-slate-400">{{ $inv->issued_at->format('d M Y') }}</td>
                            <td class="px-4 py-3">
                                @if($inv->status === 'issued')
                                    <span class="admin-badge admin-badge-emerald">Issued</span>
                                @else
                                    <span class="admin-badge admin-badge-rose">Cancelled</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="px-4 py-8 text-center text-slate-500">No invoices generated yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="admin-table-footer">
            Showing latest {{ $billingInvoices->count() }} platform invoice{{ $billingInvoices->count() === 1 ? '' : 's' }}.
            @if($billingInvoices->count() === 10)
                View all in
                <a href="{{ route('admin.invoices.index', ['shop' => $shop->id]) }}" class="admin-inline-link">Invoices</a>.
            @endif
        </div>
    </div>

    {{-- ── Storage Usage ────────────────────────────────────── --}}
    <div class="admin-panel p-4 mt-6">
        <h3 class="font-semibold text-white mb-4">Storage Usage</h3>
        @if($storageStat)
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                <div class="admin-kpi">
                    <div class="admin-kpi__label">Total Files</div>
                    <div class="admin-kpi__value text-2xl">{{ number_format($storageStat->file_count) }}</div>
                </div>
                <div class="admin-kpi">
                    <div class="admin-kpi__label">Total Size</div>
                    <div class="admin-kpi__value text-2xl">{{ $storageStat->humanSize() }}</div>
                </div>
            </div>

            @if($storageStat->breakdown)
                <div class="overflow-x-auto rounded-lg border border-slate-800">
                    <table class="w-full text-xs admin-table">
                        <thead class="bg-slate-800/70 text-slate-300">
                            <tr>
                                <th class="px-3 py-2 text-left w-14">#</th>
                                <th class="px-3 py-2 text-left">Directory</th>
                                <th class="px-3 py-2 text-right">Files</th>
                                <th class="px-3 py-2 text-right">Size</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($storageStat->breakdown as $dir => $info)
                                <tr class="border-t border-slate-800 text-slate-200">
                                    <td class="px-3 py-2 admin-table-index">{{ $loop->iteration }}</td>
                                    <td class="px-3 py-2 font-mono">{{ $dir }}/{{ $shop->id }}</td>
                                    <td class="px-3 py-2 text-right">{{ number_format($info['files'] ?? 0) }}</td>
                                    <td class="px-3 py-2 text-right text-slate-400">
                                        @php
                                            $b = $info['bytes'] ?? 0;
                                            if ($b >= 1073741824) echo number_format($b/1073741824, 2).' GB';
                                            elseif ($b >= 1048576) echo number_format($b/1048576, 2).' MB';
                                            elseif ($b >= 1024) echo number_format($b/1024, 2).' KB';
                                            else echo $b.' B';
                                        @endphp
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="admin-table-footer">Showing {{ count($storageStat->breakdown) }} storage director{{ count($storageStat->breakdown) === 1 ? 'y' : 'ies' }}.</div>
            @endif

            <p class="text-xs text-slate-500 mt-3">
                Last computed: {{ $storageStat->computed_at?->format('d M Y, H:i') ?? '—' }}
            </p>
        @else
            <p class="text-sm text-slate-400">Not yet computed. Runs daily.</p>
        @endif
    </div>

    {{-- GDPR / Data Controls --}}
    <div class="admin-panel p-4 mt-6">
        <h3 class="font-semibold text-white mb-4">Data Controls (GDPR)</h3>
        <div class="flex flex-wrap gap-4">
            {{-- Export --}}
            <form method="POST" action="{{ route('admin.shops.gdpr-export', $shop) }}">
                @csrf
                <div class="mb-2">
                    <input type="text" name="reason" required placeholder="Reason for export (required)" class="admin-input text-xs w-72">
                </div>
                <button type="submit" onclick="return confirm('Export all shop data as JSON?')"
                        class="px-3 py-1.5 rounded bg-sky-700 hover:bg-sky-600 text-white text-xs font-medium">
                    Export Data (JSON)
                </button>
            </form>

            {{-- Schedule Deletion --}}
            <form method="POST" action="{{ route('admin.shops.gdpr-delete', $shop) }}">
                @csrf
                <div class="mb-2">
                    <input type="text" name="reason" required placeholder="Reason for deletion (required)" class="admin-input text-xs w-72">
                </div>
                <button type="submit" onclick="return confirm('WARNING: This will suspend the shop and schedule data deletion after 30 days. Continue?')"
                        class="px-3 py-1.5 rounded bg-rose-700 hover:bg-rose-600 text-white text-xs font-medium">
                    Schedule GDPR Deletion
                </button>
            </form>
        </div>
        <p class="text-xs text-slate-500 mt-3">Export generates a JSON snapshot of all customer, invoice, and user data. Deletion suspends the shop and anonymises PII after a 30-day grace period.</p>
    </div>
</x-super-admin.layout>
