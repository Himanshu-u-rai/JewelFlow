<x-app-layout>
    <x-page-header
        title="Operations"
        subtitle="What needs your attention right now">
        <x-slot:actions>
            <a href="{{ route('returns.index') }}"
               class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                All Returns
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner">

        {{-- KPI chips --}}
        <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mb-6">
            @php
                $needsDecisionCount = $pendingApprovals->count() + $returnedAwaitingDecision->count();
                $incompleteStepsCount = $goldPendingRecovery->count() + $withKarigar->count() + $orphanedWithKarigar->count();

                $overdueDecision = $returnedAwaitingDecision->filter(
                    fn($item) => \Carbon\Carbon::parse($item->updated_at)->diffInDays(now()) > 14
                )->count();
                $overdueGold = $goldPendingRecovery->filter(
                    fn($disp) => \Carbon\Carbon::parse($disp->dispositioned_at)->diffInDays(now()) > 7
                )->count();
                $overdueKarigar = $withKarigar->filter(
                    fn($disp) => \Carbon\Carbon::parse($disp->dispositioned_at)->diffInDays(now()) > 7
                )->count();
                $overdueCount = $overdueDecision + $overdueGold + $overdueKarigar;

                // "Incomplete Steps" is only urgent if items have been waiting more than 3 days
                $incompleteOldCount = $goldPendingRecovery->filter(
                    fn($disp) => \Carbon\Carbon::parse($disp->dispositioned_at)->diffInDays(now()) > 3
                )->count() + $withKarigar->filter(
                    fn($disp) => \Carbon\Carbon::parse($disp->dispositioned_at)->diffInDays(now()) > 3
                )->count();

                // Age label for "Incomplete Steps": oldest item waiting
                $incompleteOldestDays = 0;
                foreach ($goldPendingRecovery as $_disp) {
                    $d = (int) \Carbon\Carbon::parse($_disp->dispositioned_at)->diffInDays(now());
                    if ($d > $incompleteOldestDays) $incompleteOldestDays = $d;
                }
                foreach ($withKarigar as $_disp) {
                    $d = (int) \Carbon\Carbon::parse($_disp->dispositioned_at)->diffInDays(now());
                    if ($d > $incompleteOldestDays) $incompleteOldestDays = $d;
                }

                $incompleteLabel = 'Incomplete Steps';
                if ($incompleteStepsCount > 0 && $incompleteOldestDays > 0) {
                    $incompleteLabel = 'Incomplete Steps · oldest ' . $incompleteOldestDays . 'd';
                }

                $chips = [
                    ['label' => 'Needs Decision',   'count' => $needsDecisionCount,   'urgent' => $needsDecisionCount > 0],
                    ['label' => $incompleteLabel,    'count' => $incompleteStepsCount, 'urgent' => $incompleteOldCount > 0],
                    ['label' => 'Overdue Items',     'count' => $overdueCount,         'urgent' => $overdueCount > 0],
                ];
            @endphp
            @foreach($chips as $chip)
                @php $isUrgent = $chip['urgent'] && $chip['count'] > 0; @endphp
                <div class="rounded-2xl border {{ $isUrgent ? 'border-amber-200 bg-amber-50' : 'border-slate-200 bg-white' }} px-5 py-4">
                    <div class="text-2xl font-bold {{ $isUrgent ? 'text-amber-700' : 'text-slate-400' }}">
                        {{ $chip['count'] }}
                    </div>
                    <div class="mt-1 text-xs font-semibold uppercase tracking-[0.15em] {{ $isUrgent ? 'text-amber-600' : 'text-slate-400' }}">
                        {{ $chip['label'] }}
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Group 1: Blocked — needs your decision --}}
        <section id="blocked" class="mb-10">
            <div class="mb-4">
                <h2 class="text-base font-semibold text-slate-900">Blocked — needs your decision</h2>
                <p class="text-sm text-slate-500 mt-1">The refund or next step is on hold until you act. These are your bottleneck.</p>
            </div>

            {{-- Approval queue --}}
            <div class="mb-6">
                <div class="flex items-center gap-3 mb-1">
                    <h3 class="text-sm font-semibold text-slate-700">Needs My Approval</h3>
                    @if($pendingApprovals->count() > 0)
                        <span class="inline-flex items-center justify-center min-w-[22px] h-[20px] px-1.5 rounded-full bg-amber-500 text-white text-[10px] font-bold leading-none">
                            {{ $pendingApprovals->count() }}
                        </span>
                    @endif
                </div>

                @if($pendingApprovals->isEmpty())
                    <x-empty-state
                        title="Nothing waiting"
                        description="Nothing waiting — all returns are up to date."
                        :compact="true"
                    />
                @else
                    <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
                        <table class="w-full">
                            <thead class="bg-slate-50 border-b border-slate-200 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                <tr>
                                    <th class="px-5 py-3 text-left">Invoice</th>
                                    <th class="px-5 py-3 text-left">Customer</th>
                                    <th class="px-5 py-3 text-right">Est. Refund</th>
                                    <th class="px-5 py-3 text-left">Submitted by</th>
                                    <th class="px-5 py-3 text-left">Waiting since</th>
                                    <th class="px-5 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($pendingApprovals as $returnOrder)
                                    @php
                                        $customer = $returnOrder->customer;
                                        $customerName = $customer
                                            ? trim($customer->first_name . ' ' . $customer->last_name)
                                            : null;
                                    @endphp
                                    <tr class="align-top">
                                        <td class="px-5 py-4">
                                            @if($returnOrder->invoice)
                                                <a href="{{ route('returns.show', $returnOrder) }}"
                                                   class="text-sm font-semibold text-amber-700 hover:underline">
                                                    {{ $returnOrder->invoice->invoice_number }}
                                                </a>
                                            @else
                                                <span class="text-sm text-slate-400">—</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-4 text-sm text-slate-700">
                                            {{ $customerName ?? '—' }}
                                        </td>
                                        <td class="px-5 py-4 text-right text-sm text-slate-700">
                                            @if($returnOrder->creditNote)
                                                ₹{{ number_format((float) $returnOrder->creditNote->total, 2) }}
                                            @else
                                                <span class="text-slate-400">—</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-4 text-sm text-slate-600">
                                            {{ $returnOrder->createdBy?->name ?? '—' }}
                                        </td>
                                        <td class="px-5 py-4 text-sm text-slate-500">
                                            {{ \Carbon\Carbon::parse($returnOrder->created_at)->diffForHumans() }}
                                        </td>
                                        <td class="px-5 py-4 text-right">
                                            <a href="{{ route('returns.approve-review', $returnOrder) }}"
                                               class="inline-flex items-center rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700 transition">
                                                Review &amp; Decide
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- Returned items awaiting decision --}}
            <div>
                <div class="flex items-center gap-3 mb-1">
                    <h3 class="text-sm font-semibold text-slate-700">Returned Items — Decide What To Do</h3>
                    @if($returnedAwaitingDecision->count() > 0)
                        <span class="inline-flex items-center justify-center min-w-[22px] h-[20px] px-1.5 rounded-full bg-amber-500 text-white text-[10px] font-bold leading-none">
                            {{ $returnedAwaitingDecision->count() }}
                        </span>
                    @endif
                </div>
                <p class="text-sm text-slate-500 mb-3">These pieces were set aside for inspection before restocking. Confirm they're ready, or redirect to melt/rework/write-off.</p>

                @if($returnedAwaitingDecision->isEmpty())
                    <x-empty-state
                        title="No items waiting"
                        description="No returned items waiting for a decision."
                        :compact="true"
                    />
                @else
                    @php
                        $goodConditionItems = $returnedAwaitingDecision->filter(function ($item) {
                            $condition = $item->latestReturnDisposition?->returnLineItem?->condition;
                            return $condition === 'good_condition';
                        })->values();
                    @endphp

                    @if($goodConditionItems->count() >= 2)
                        <div
                            x-data="{ showPreview: false }"
                            class="mb-4"
                        >
                            <button
                                type="button"
                                x-show="!showPreview"
                                @click="showPreview = true"
                                class="inline-flex items-center gap-2 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-2.5 text-sm font-semibold text-emerald-800 hover:bg-emerald-100 transition"
                            >
                                ⚡ Send all good-condition items back to stock
                                <span class="ml-1 inline-flex items-center justify-center min-w-[20px] h-[18px] px-1.5 rounded-full bg-emerald-600 text-white text-[10px] font-bold leading-none">
                                    {{ $goodConditionItems->count() }}
                                </span>
                            </button>

                            <div x-show="showPreview" x-transition class="rounded-2xl border border-emerald-200 bg-emerald-50 overflow-hidden">
                                <div class="px-5 py-4 border-b border-emerald-200 flex items-center justify-between">
                                    <div>
                                        <p class="text-sm font-semibold text-emerald-900">
                                            These {{ $goodConditionItems->count() }} items will go back in stock:
                                        </p>
                                        <p class="text-xs text-emerald-700 mt-0.5">Check the list below, then confirm.</p>
                                    </div>
                                    <a href="#" @click.prevent="showPreview = false" class="text-xs text-slate-500 hover:text-slate-700 underline">Cancel</a>
                                </div>

                                <table class="w-full">
                                    <thead class="bg-emerald-100 text-xs font-semibold uppercase tracking-[0.15em] text-emerald-700">
                                        <tr>
                                            <th class="px-5 py-2.5 text-left">Item</th>
                                            <th class="px-5 py-2.5 text-left">Condition</th>
                                            <th class="px-5 py-2.5 text-left">Waiting since</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-emerald-100">
                                        @foreach($goodConditionItems as $batchItem)
                                            @php
                                                $batchDisp = $batchItem->latestReturnDisposition;
                                                $batchReturnedAt = $batchDisp?->dispositioned_at ?? $batchItem->updated_at;
                                                $batchDays = (int) \Carbon\Carbon::parse($batchReturnedAt)->diffInDays(now());
                                            @endphp
                                            <tr>
                                                <td class="px-5 py-3">
                                                    <div class="text-sm font-semibold text-slate-900">{{ $batchItem->barcode ?? '—' }}</div>
                                                    <div class="text-xs text-slate-500">{{ $batchItem->design ?? '—' }}</div>
                                                </td>
                                                <td class="px-5 py-3 text-sm text-emerald-800">Good condition</td>
                                                <td class="px-5 py-3 text-sm text-slate-600">
                                                    @if($batchDays === 0)
                                                        Today
                                                    @elseif($batchDays === 1)
                                                        1 day
                                                    @else
                                                        {{ $batchDays }} days
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>

                                <div class="px-5 py-4 border-t border-emerald-200 flex items-center justify-between bg-emerald-50">
                                    <a href="#" @click.prevent="showPreview = false" class="text-sm text-slate-500 hover:text-slate-700 underline">Cancel</a>
                                    <form method="POST" action="{{ route('returns.batch-restock') }}">
                                        @csrf
                                        @foreach($goodConditionItems as $batchItem)
                                            <input type="hidden" name="item_ids[]" value="{{ $batchItem->id }}">
                                        @endforeach
                                        <button type="submit"
                                                class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700 transition">
                                            ✓ Confirm — Send back to stock
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endif

                    <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
                        <table class="w-full">
                            <thead class="bg-slate-50 border-b border-slate-200 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                <tr>
                                    <th class="px-5 py-3 text-left">Item</th>
                                    <th class="px-5 py-3 text-left">Condition</th>
                                    <th class="px-5 py-3 text-left">Returned</th>
                                    <th class="px-5 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($returnedAwaitingDecision as $item)
                                    @php
                                        $disp = $item->latestReturnDisposition;
                                        $returnedAt = $disp?->dispositioned_at ?? $item->updated_at;
                                        $condition = $disp?->returnLineItem?->condition ?? null;
                                        $suggestedDisp = match($condition) {
                                            'good_condition' => 'restocked',
                                            'minor_wear'     => 'restocked',
                                            'damaged'        => 'sent_to_melt',
                                            'non_sellable'   => 'written_off',
                                            default          => null,
                                        };
                                        $conditionLabel = match($condition) {
                                            'good_condition' => 'Good condition',
                                            'minor_wear'     => 'Minor wear',
                                            'damaged'        => 'Damaged',
                                            'non_sellable'   => 'Non-sellable',
                                            default          => $condition ? ucfirst(str_replace('_', ' ', $condition)) : null,
                                        };
                                        $suggestionReason = match($condition) {
                                            'good_condition' => 'Good condition — safe to put back on display.',
                                            'minor_wear'     => 'Minor wear — usually still sellable; inspect before restocking.',
                                            'damaged'        => 'Damaged — not suitable for resale; recovering the gold is typical.',
                                            'non_sellable'   => 'Non-sellable — no resale value; write off or melt.',
                                            default          => null,
                                        };
                                    @endphp
                                    <tr class="align-top">
                                        <td class="px-5 py-4">
                                            <div class="text-sm font-semibold text-slate-900">{{ $item->barcode ?? '—' }}</div>
                                            <div class="text-xs text-slate-500">{{ $item->design ?? '—' }}</div>
                                        </td>
                                        <td class="px-5 py-4 text-sm text-slate-600">
                                            {{ $conditionLabel ?? '—' }}
                                        </td>
                                        <td class="px-5 py-4 text-sm text-slate-600">
                                            {{ \Carbon\Carbon::parse($returnedAt)->diffForHumans() }}
                                        </td>
                                        <td class="px-5 py-4 text-right">
                                            <form method="POST"
                                                  action="{{ route('returns.items.redispose', $item) }}"
                                                  class="inline-flex items-center gap-2 justify-end flex-wrap">
                                                @csrf
                                                <button type="submit" name="disposition" value="restocked"
                                                        class="inline-flex items-center rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-800 hover:bg-emerald-100 transition {{ $suggestedDisp === 'restocked' ? 'ring-2 ring-emerald-400 ring-offset-1' : '' }}">
                                                    Back to Stock{{ $suggestedDisp === 'restocked' ? ' ✓' : '' }}
                                                </button>
                                                <button type="submit" name="disposition" value="sent_to_melt"
                                                        class="inline-flex items-center rounded-lg border border-orange-300 bg-orange-50 px-3 py-1.5 text-xs font-semibold text-orange-800 hover:bg-orange-100 transition {{ $suggestedDisp === 'sent_to_melt' ? 'ring-2 ring-orange-400 ring-offset-1' : '' }}">
                                                    Send to Melt{{ $suggestedDisp === 'sent_to_melt' ? ' ✓' : '' }}
                                                </button>
                                                {{-- "Send to Karigar" retired (M11): the rework job-work backend was
                                                     never built, so this only trapped items in an unclearable queue.
                                                     To rework a returned piece: Send to Melt → record recovery into the
                                                     vault → create a karigar job from that lot. --}}
                                                <button type="submit" name="disposition" value="written_off"
                                                        class="inline-flex items-center rounded-lg border border-slate-300 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-100 transition {{ $suggestedDisp === 'written_off' ? 'ring-2 ring-slate-400 ring-offset-1' : '' }}">
                                                    Write Off{{ $suggestedDisp === 'written_off' ? ' ✓' : '' }}
                                                </button>
                                            </form>
                                            @if($suggestionReason)
                                                <p class="mt-1.5 text-xs text-slate-400 text-right">{{ $suggestionReason }}</p>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </section>

        {{-- Group 2: Started but not finished --}}
        <section id="incomplete" class="mb-10">
            <div class="mb-4">
                <h2 class="text-base font-semibold text-slate-900">Started But Not Finished</h2>
                <p class="text-sm text-slate-500 mt-1">A step was started but not finished. These need to be done before everything is properly closed.</p>
            </div>

            {{-- Gold melt unrecorded --}}
            <div class="mb-6">
                <div class="flex items-center gap-3 mb-1">
                    <h3 class="text-sm font-semibold text-slate-700">Gold melt unrecorded</h3>
                    @if($goldPendingRecovery->count() > 0)
                        <span class="inline-flex items-center justify-center min-w-[22px] h-[20px] px-1.5 rounded-full bg-amber-500 text-white text-[10px] font-bold leading-none">
                            {{ $goldPendingRecovery->count() }}
                        </span>
                    @endif
                </div>
                <p class="text-sm text-slate-500 mb-3">These pieces have been set aside for melting. Record the gold recovery to add the weight back to your stock.</p>

                @if($goldPendingRecovery->isNotEmpty())
                    @if($goldRatePerGram > 0)
                        <div class="mb-4 inline-flex items-center gap-2 rounded-xl border border-blue-200 bg-blue-50 px-4 py-2.5 text-sm font-semibold text-blue-800">
                            Estimated recoverable value: ₹{{ number_format($goldPendingValue, 0) }}
                            <span class="text-xs font-normal text-blue-600">(based on today's rate ₹{{ number_format($goldRatePerGram, 0) }}/g)</span>
                        </div>
                    @else
                        <div class="mb-4 inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-500">
                            Set today's gold rate to see estimated value.
                        </div>
                    @endif
                @endif

                @if($goldPendingRecovery->isEmpty())
                    <x-empty-state
                        title="No gold waiting"
                        description="No gold waiting for recovery — all melts have been recorded."
                        :compact="true"
                    />
                @else
                    <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
                        <table class="w-full">
                            <thead class="bg-slate-50 border-b border-slate-200 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                <tr>
                                    <th class="px-5 py-3 text-left">Item</th>
                                    <th class="px-5 py-3 text-right">Est. Fine Weight</th>
                                    <th class="px-5 py-3 text-right">Est. Value</th>
                                    <th class="px-5 py-3 text-left">Sitting since</th>
                                    <th class="px-5 py-3 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($goldPendingRecovery as $disp)
                                    @php
                                        $ro = $disp->returnLineItem?->returnOrder;
                                        $item = $disp->item;
                                        $fineWeight = null;
                                        $estValue = null;
                                        if ($item && $item->net_metal_weight && $item->purity) {
                                            $fineWeight = (float) $item->net_metal_weight * ((float) $item->purity / 24);
                                            $estValue = $goldRatePerGram > 0 ? round($fineWeight * $goldRatePerGram, 2) : null;
                                        }
                                    @endphp
                                    <tr class="align-top">
                                        <td class="px-5 py-4">
                                            <div class="text-sm font-semibold text-slate-900">{{ $item?->barcode ?? '—' }}</div>
                                            <div class="text-xs text-slate-500">{{ $item?->design ?? '—' }}</div>
                                            @if($ro)
                                                <a href="{{ route('returns.show', $ro->id) }}"
                                                   class="text-xs font-semibold text-amber-700 hover:underline">
                                                    RO#{{ $ro->id }}
                                                </a>
                                            @endif
                                        </td>
                                        <td class="px-5 py-4 text-right text-sm text-slate-700">
                                            @if($fineWeight !== null)
                                                {{ number_format($fineWeight, 2) }} g
                                            @else
                                                <span class="text-slate-400">—</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-4 text-right text-sm text-slate-700">
                                            @if($estValue !== null)
                                                ₹{{ number_format($estValue, 0) }}
                                            @else
                                                <span class="text-slate-400">—</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-4 text-sm text-slate-500">
                                            {{ \Carbon\Carbon::parse($disp->dispositioned_at)->diffForHumans() }}
                                        </td>
                                        <td class="px-5 py-4 text-right">
                                            <a href="{{ route('returns.items.recover', $disp) }}"
                                               class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition">
                                                Record Recovery
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- Items orphaned by cancelled job orders --}}
            @if($orphanedWithKarigar->isNotEmpty())
            <div class="mb-6">
                <div class="flex items-center gap-3 mb-1">
                    <h3 class="text-sm font-semibold text-rose-700">Items stuck after cancelled job</h3>
                    <span class="inline-flex items-center justify-center min-w-[22px] h-[20px] px-1.5 rounded-full bg-rose-500 text-white text-[10px] font-bold leading-none">
                        {{ $orphanedWithKarigar->count() }}
                    </span>
                </div>
                <p class="text-sm text-slate-500 mb-3">These items are still marked "with karigar" but the job order was cancelled. Their status needs to be corrected manually.</p>
                <div class="rounded-2xl border border-rose-200 bg-white overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-rose-50 border-b border-rose-100 text-xs font-semibold uppercase tracking-[0.18em] text-rose-600">
                            <tr>
                                <th class="px-5 py-3 text-left">Item</th>
                                <th class="px-5 py-3 text-left">Cancelled Job</th>
                                <th class="px-5 py-3 text-left">Karigar</th>
                                <th class="px-5 py-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($orphanedWithKarigar as $job)
                                <tr class="align-top">
                                    <td class="px-5 py-4">
                                        <div class="flex items-center gap-2">
                                            <span class="text-sm font-semibold text-slate-900">{{ $job->sourceItem?->barcode ?? '—' }}</span>
                                            @if($job->job_type === \App\Models\JobOrder::JOB_TYPE_REPAIR)
                                                <span class="text-xs px-1.5 py-0.5 rounded bg-blue-100 text-blue-700">Repair</span>
                                            @endif
                                        </div>
                                        <div class="text-xs text-slate-500">{{ $job->sourceItem?->design ?? '—' }}</div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <a href="{{ route('job-orders.show', $job->id) }}"
                                           class="text-sm font-semibold text-rose-700 hover:underline">
                                            {{ $job->job_order_number ?? 'JO#'.$job->id }}
                                        </a>
                                        <div class="text-xs text-slate-500 capitalize">{{ $job->job_type }} · cancelled</div>
                                    </td>
                                    <td class="px-5 py-4 text-sm text-slate-600">
                                        {{ $job->karigar?->name ?? '—' }}
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        @php $fixLabel = $job->job_type === \App\Models\JobOrder::JOB_TYPE_REPAIR ? 'in stock' : 'returned'; @endphp
                                        <div class="flex items-center justify-end gap-2">
                                            <form method="POST"
                                                  action="{{ route('returns.items.fix-orphan-status', $job->source_item_id) }}"
                                                  onsubmit="return confirm('Mark {{ $job->sourceItem?->barcode ?? $job->source_item_id }} as {{ $fixLabel }}?')">
                                                @csrf
                                                <button type="submit"
                                                        class="inline-flex items-center rounded-lg border border-green-200 bg-green-50 px-3 py-1.5 text-xs font-semibold text-green-700 hover:bg-green-100 transition">
                                                    Fix Status
                                                </button>
                                            </form>
                                            <a href="{{ route('inventory.items.show', $job->source_item_id) }}"
                                               class="inline-flex items-center rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-100 transition">
                                                View
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @endif

            {{-- Legacy rework items (sent_to_rework retired in M11) --}}
            <div>
                <div class="flex items-center gap-3 mb-1">
                    <h3 class="text-sm font-semibold text-slate-700">Pieces marked for rework (legacy)</h3>
                    @if($withKarigar->count() > 0)
                        <span class="inline-flex items-center justify-center min-w-[22px] h-[20px] px-1.5 rounded-full bg-slate-400 text-white text-[10px] font-bold leading-none">
                            {{ $withKarigar->count() }}
                        </span>
                    @endif
                </div>
                <p class="text-sm text-slate-500 mb-3">These pieces were marked for rework under the old flow. To rework a returned piece now: choose <span class="font-semibold">Send to Melt</span> above, record the recovery into the vault, then create a karigar job from that gold lot.</p>

                @if($withKarigar->isEmpty())
                    <x-empty-state
                        title="None out"
                        description="No pieces currently out with karigar."
                        :compact="true"
                    />
                @else
                    <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
                        <table class="w-full">
                            <thead class="bg-slate-50 border-b border-slate-200 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                <tr>
                                    <th class="px-5 py-3 text-left">Item</th>
                                    <th class="px-5 py-3 text-left">From Return</th>
                                    <th class="px-5 py-3 text-left">Sent out</th>
                                    <th class="px-5 py-3 text-center">Days out</th>
                                    <th class="px-5 py-3 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($withKarigar as $disp)
                                    @php
                                        $ro = $disp->returnLineItem?->returnOrder;
                                        $daysOut = (int) \Carbon\Carbon::parse($disp->dispositioned_at)->diffInDays(now());
                                        $daysColor = $daysOut > 14
                                            ? 'text-rose-700 font-bold'
                                            : ($daysOut > 7 ? 'text-amber-700 font-semibold' : 'text-slate-600');
                                    @endphp
                                    <tr class="align-top">
                                        <td class="px-5 py-4">
                                            <div class="text-sm font-semibold text-slate-900">
                                                {{ $disp->item?->barcode ?? '—' }}
                                            </div>
                                            <div class="text-xs text-slate-500">
                                                {{ $disp->item?->design ?? '—' }}
                                            </div>
                                        </td>
                                        <td class="px-5 py-4">
                                            @if($ro)
                                                <a href="{{ route('returns.show', $ro->id) }}"
                                                   class="text-sm font-semibold text-amber-700 hover:underline">
                                                    RO#{{ $ro->id }}
                                                </a>
                                            @else
                                                <span class="text-sm text-slate-400">—</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-4 text-sm text-slate-600">
                                            {{ \Carbon\Carbon::parse($disp->dispositioned_at)->diffForHumans() }}
                                        </td>
                                        <td class="px-5 py-4 text-center text-sm {{ $daysColor }}">
                                            {{ $daysOut }}d
                                        </td>
                                        <td class="px-5 py-4 text-right">
                                            <span class="inline-flex items-center rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-400 cursor-not-allowed"
                                                  title="In-app rework job creation is not available yet — track the karigar rework manually.">
                                                Rework (manual)
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </section>

        {{-- Group 3: Time-sensitive --}}
        <section id="time-sensitive" class="mb-10">
            <div class="mb-4">
                <h2 class="text-base font-semibold text-slate-900">Time-sensitive</h2>
                <p class="text-sm text-slate-500 mt-1">Items that have been waiting a long time. These will become problems if not resolved soon.</p>
            </div>

            @php
                // $overdueDecision, $overdueGold, $overdueKarigar already computed above
            @endphp

            @if($overdueCount === 0)
                <x-empty-state
                    title="All good"
                    description="Nothing has been waiting too long."
                    :compact="true"
                />
            @else
                <div class="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4">
                    <ul class="space-y-2">
                        @if($overdueDecision > 0)
                            <li class="text-sm text-amber-800">
                                <span class="font-semibold">{{ $overdueDecision }} {{ Str::plural('item', $overdueDecision) }}</span>
                                awaiting a decision for more than 14 days —
                                <a href="#blocked" class="font-semibold underline hover:text-amber-900">Go to Blocked</a>
                            </li>
                        @endif
                        @if($overdueGold > 0)
                            <li class="text-sm text-amber-800">
                                <span class="font-semibold">{{ $overdueGold }} {{ Str::plural('melt', $overdueGold) }}</span>
                                unrecorded for more than 7 days —
                                <a href="#incomplete" class="font-semibold underline hover:text-amber-900">Go to Incomplete</a>
                            </li>
                        @endif
                        @if($overdueKarigar > 0)
                            <li class="text-sm text-amber-800">
                                <span class="font-semibold">{{ $overdueKarigar }} rework {{ Str::plural('job', $overdueKarigar) }}</span>
                                not created for more than 7 days —
                                <a href="#incomplete" class="font-semibold underline hover:text-amber-900">Go to Incomplete</a>
                            </li>
                        @endif
                    </ul>
                </div>
            @endif
        </section>

    </div>
</x-app-layout>
