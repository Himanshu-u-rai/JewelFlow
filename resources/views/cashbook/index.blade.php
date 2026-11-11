<x-app-layout>
    <div class="cb-shell" x-data="{}">
        <x-page-header class="cb-page-header" title="Cash Book" subtitle="Money movements and balances">
            <x-slot:actions>
                @can('cash.create')
                    <button type="button" class="btn btn-secondary btn-sm cb-header-action" @click="$refs.drawer.showModal()" aria-haspopup="dialog" aria-controls="cashbook-drawer" aria-label="Match your drawer">
                        <x-icon name="wallet" size="16" /><span>Match drawer</span>
                    </button>
                    <a href="{{ route('cashbook.create') }}" class="btn btn-primary btn-sm cb-header-action" aria-label="Add entry" aria-haspopup="dialog" aria-controls="cashbook-entry" data-turbo-prefetch="false"
                       @click="if (!$event.ctrlKey && !$event.metaKey && !$event.shiftKey && !$event.altKey) { $event.preventDefault(); $refs.entry.showModal(); }"><x-icon name="plus" size="16" /><span>Add entry</span></a>
                @endcan
                <a href="{{ route('report.cash') }}" class="btn btn-secondary btn-sm cb-header-action" aria-label="Open cash flow dashboard"><x-icon name="trending-up" size="16" /><span>Cash flow</span></a>
            </x-slot:actions>
        </x-page-header>

        <div class="content-inner cb-page" data-list-workspace>
            <x-app-alerts />
            @unless(auth()->user()->can('cash.create'))
                @include('partials.view-only-banner', ['permission' => 'cash.create', 'message' => 'adding entries'])
            @endunless
        @php
            $todayNet = $stats['today_in'] - $stats['today_out'];
            $monthNet = $stats['month_in'] - $stats['month_out'];
            $hasActiveFilters = collect(request()->only(['search', 'type', 'from_date', 'payment_mode', 'payment_method_id', 'to_date']))->contains(fn ($value) => filled($value));
            // Day headings in the list: "Today · In ₹… · Out ₹…".
            $dayHeading = function (string $day) use ($dayTotals) {
                $date = \Carbon\Carbon::parse($day);
                $label = $date->isToday() ? 'Today' : ($date->isYesterday() ? 'Yesterday' : $date->format('d M Y'));
                $t = $dayTotals[$day] ?? ['money_in' => 0, 'money_out' => 0];

                return [$label, 'In ₹' . number_format($t['money_in'], 2), 'Out ₹' . number_format($t['money_out'], 2)];
            };
            $accountList = collect($accounts)->flatten(1);

            $moneyRangeLabel = $balancePeriod->start()->format('d M Y') . ' – ' . $balancePeriod->end()->format('d M Y');

            // Plain-English labels for each money mode.
            $modeLabels = [
                'cash' => 'Cash in hand', 'upi' => 'UPI', 'bank' => 'Bank',
                'card' => 'Card', 'wallet' => 'Wallet', 'other' => 'Other',
            ];
            $typeLabels = ['in' => 'Money In', 'out' => 'Money Out'];
            $paymentModeLabels = ['cash' => 'Cash', 'upi' => 'UPI', 'bank' => 'Bank', 'card' => 'Card', 'wallet' => 'Wallet', 'other' => 'Other'];
            $activeFilters = [];

            if (filled(request('search'))) {
                $activeFilters[] = 'Search: ' . Str::limit(request('search'), 24);
            }
            if (request('type') && isset($typeLabels[request('type')])) {
                $activeFilters[] = 'Type: ' . $typeLabels[request('type')];
            }
            if (request('payment_mode') && isset($paymentModeLabels[request('payment_mode')])) {
                $activeFilters[] = 'Mode: ' . $paymentModeLabels[request('payment_mode')];
            }
            if ($pickedAccount = $accountList->firstWhere('id', (int) request('payment_method_id'))) {
                $activeFilters[] = 'Account: ' . $pickedAccount['account_label'];
            }
            if (request('from_date') || request('to_date')) {
                $activeFilters[] = 'Date: ' . (request('from_date') ?: 'start') . ' to ' . (request('to_date') ?: 'today');
            }

            $cashRow = $perMode->cash();
            $otherModes = $perMode->modes->reject(fn ($m) => $m->mode === 'cash');
        @endphp


            @if(session('drawer_check_result'))
                <div class="cb-result" role="status">{{ session('drawer_check_result') }}</div>
            @endif

            <section class="cb-activity" aria-labelledby="cb-activity-title">
                <div class="cb-section-heading">
                    <h2 id="cb-activity-title">Activity</h2>
                    <p>All payment modes · excludes opening balances · not affected by entry filters</p>
                </div>
                <div class="cb-metrics">
                    <dl data-cb-snapshot="today-in"><dt>Today · Money in</dt><dd class="cb-positive">₹{{ number_format($stats['today_in'], 2) }}</dd></dl>
                    <dl data-cb-snapshot="today-out"><dt>Today · Money out</dt><dd class="cb-negative">₹{{ number_format($stats['today_out'], 2) }}</dd></dl>
                    <dl data-cb-snapshot="today-net"><dt>Today · Net movement</dt><dd class="{{ $todayNet >= 0 ? 'cb-positive' : 'cb-negative' }}">{{ $todayNet >= 0 ? '+' : '−' }}₹{{ number_format(abs($todayNet), 2) }}</dd></dl>
                    <dl data-cb-snapshot="month-net"><dt>{{ now()->format('M Y') }} · Net movement</dt><dd class="{{ $monthNet >= 0 ? 'cb-positive' : 'cb-negative' }}">{{ $monthNet >= 0 ? '+' : '−' }}₹{{ number_format(abs($monthNet), 2) }}</dd></dl>
                </div>
            </section>

            <section class="cb-balances" aria-labelledby="cb-balances-title">
                <header class="cb-section-heading">
                    <div><h2 id="cb-balances-title">Balances by payment mode</h2><p>Opening + money in − money out = closing</p></div>
                    <p class="cb-period">{{ $moneyRangeLabel }}</p>
                </header>
                <div class="cb-balance-scroll jf-scrollbar" tabindex="0" role="region" aria-label="Payment mode balances">
                    <table class="cb-balance-table">
                        <caption class="jf-sr-only">Balances for {{ $moneyRangeLabel }}, across all payment modes. Only the date filters change this period.</caption>
                        <thead><tr><th scope="col">Payment mode</th><th scope="col">Opening</th><th scope="col">Money in</th><th scope="col">Money out</th><th scope="col">Closing</th></tr></thead>
                        <tbody>
                            @foreach(collect([$cashRow])->concat($otherModes) as $m)
                                <tr data-cb-balance="{{ $m->mode }}">
                                    <th scope="row">{{ $modeLabels[$m->mode] ?? ucfirst($m->mode) }}</th>
                                    <td data-label="Opening">₹{{ number_format($m->opening, 2) }}</td>
                                    <td data-label="Money in">₹{{ number_format($m->moneyIn, 2) }}</td>
                                    <td data-label="Money out">₹{{ number_format($m->moneyOut, 2) }}</td>
                                    <td data-label="Closing">₹{{ number_format($m->closing, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot><tr><th scope="row">All modes total</th><td data-label="Opening">₹{{ number_format($perMode->totalOpening, 2) }}</td><td data-label="Money in">₹{{ number_format($perMode->totalIn, 2) }}</td><td data-label="Money out">₹{{ number_format($perMode->totalOut, 2) }}</td><td data-label="Closing" data-cb-total-closing>₹{{ number_format($perMode->totalClosing, 2) }}</td></tr></tfoot>
                    </table>
                </div>
                <p class="cb-balance-note">Balances use the date range above and include earlier opening money. Search, direction, payment mode and account filters do not change these balances.</p>
            </section>

            <section class="cb-register" data-list-frame aria-labelledby="cb-register-title">
                <header class="cb-register-head">
                    <div><h2 id="cb-register-title">Transaction entries</h2><p>{{ number_format($transactions->total()) }} {{ Str::plural('entry', $transactions->total()) }}{{ $hasActiveFilters ? ' matching your filters' : ' · all dates' }}</p></div>
                    <x-responsive-filter-panel id="cashbook-filters" label="Cash Book filters" :desktop-popup="true">
                        <form method="GET" action="{{ route('cashbook.index') }}" class="cb-filter-form" x-data="{ dirty: false, hasFilters: @js($hasActiveFilters) }" @input="dirty = true" @change="dirty = true">
                            <label class="cb-filter-wide" for="cb-search">Search
                                <input id="cb-search" type="search" name="search" value="{{ request('search') }}" maxlength="100" placeholder="Description or source…" class="cb-control">
                            </label>
                            <label for="cb-type">Direction
                                <select id="cb-type" name="type" class="cb-control"><option value="">Money in &amp; out</option><option value="in" @selected(request('type') === 'in')>Money In</option><option value="out" @selected(request('type') === 'out')>Money Out</option></select>
                            </label>
                            <label for="cb-filter-mode">Payment mode
                                <select name="payment_mode" class="cb-control" id="cb-filter-mode" onchange="cbFilterAccounts()"><option value="">All modes</option>
                                    @foreach($paymentModeLabels as $val => $label)<option value="{{ $val }}" @selected(request('payment_mode') === $val)>{{ $label }}</option>@endforeach
                                </select>
                            </label>
                            @if($accountList->isNotEmpty())
                                <label for="cb-filter-account" id="cb-filter-account-field" class="cb-filter-wide">Account
                                    <select name="payment_method_id" class="cb-control" id="cb-filter-account"><option value="">All accounts</option>
                                        @foreach($accounts as $kind => $list)
                                            @if(count($list))
                                                <optgroup label="{{ $paymentModeLabels[$kind] }}" data-kind="{{ $kind }}">
                                                    @foreach($list as $account)<option value="{{ $account['id'] }}" @selected((int) request('payment_method_id') === $account['id'])>{{ $account['account_label'] }}</option>@endforeach
                                                </optgroup>
                                            @endif
                                        @endforeach
                                    </select>
                                </label>
                            @endif
                            <label for="cb-from">From date<input id="cb-from" type="date" name="from_date" aria-label="From date" value="{{ request('from_date') }}" class="cb-control"></label>
                            <label for="cb-to">To date<input id="cb-to" type="date" name="to_date" aria-label="To date" value="{{ request('to_date') }}" class="cb-control"></label>
                            <p class="cb-filter-note cb-filter-wide">Dates also set the balance period. Other filters apply only to transaction entries.</p>
                            <div class="cb-filter-actions cb-filter-wide">
                                <button type="submit" class="btn btn-primary btn-sm" x-show="dirty || !hasFilters">Apply</button>
                                <a href="{{ route('cashbook.index') }}" class="btn btn-primary btn-sm" x-show="!dirty && hasFilters" x-cloak>Clear</a>
                                @if($hasActiveFilters)<noscript><a href="{{ route('cashbook.index') }}" class="btn btn-secondary btn-sm">Clear filters</a></noscript>@endif
                            </div>
                        </form>
                    </x-responsive-filter-panel>
                </header>
                @if(count($activeFilters))
                    <div class="cb-active-filters" aria-label="Active entry filters">@foreach($activeFilters as $filter)<span>{{ $filter }}</span>@endforeach</div>
                @endif
                <p class="cb-entry-note">Daily totals cover matching entries across all pages, excluding opening balances.</p>

                <div class="cb-table-wrap jf-table-wrap" data-list-scroll tabindex="0" role="region" aria-label="Transaction records">
                    <table class="cb-table jf-table" aria-label="Transaction entries">
                        <thead><tr><th scope="col" class="jf-table-index">#</th><th scope="col">Date &amp; time</th><th scope="col">Direction</th><th scope="col">Source</th><th scope="col">Invoice #</th><th scope="col">Description</th><th scope="col">Payment mode / account</th><th scope="col" class="text-right">Amount</th></tr></thead>
                        <tbody>
                            @forelse($transactions as $tx)
                                @if($loop->first || $tx->created_at->toDateString() !== $transactions[$loop->index - 1]->created_at->toDateString())
                                    @php [$dayLabel, $dayIn, $dayOut] = $dayHeading($tx->created_at->toDateString()); @endphp
                                    <tr class="cb-day-row"><td colspan="8"><div><strong>{{ $dayLabel }}</strong><span>{{ $dayIn }}</span><span>{{ $dayOut }}</span></div></td></tr>
                                @endif
                                <tr>
                                    <td class="jf-table-index">{{ $transactions->firstItem() + $loop->index }}</td>
                                    <td class="cb-date">{{ $tx->created_at->format('d M Y, h:i A') }}</td>
                                    <td><span class="cb-direction {{ $tx->type === 'in' ? 'cb-positive' : 'cb-negative' }}">{{ $tx->type === 'in' ? 'Money In' : 'Money Out' }}</span></td>
                                    <td>{{ \App\Models\CashTransaction::sourceLabel($tx->source_type, $tx->type) }}</td>
                                    <td>{{ $tx->invoice?->invoice_number ?? '—' }}</td>
                                    <td class="cb-description">{{ $tx->description ?: '—' }}</td>
                                    <td>{{ $paymentModeLabels[$tx->payment_mode ?: 'cash'] ?? ucfirst($tx->payment_mode) }}@if($tx->payment_method_label_snapshot)<span class="cb-account">{{ $tx->payment_method_label_snapshot }}</span>@endif</td>
                                    <td class="text-right"><span class="cb-amount {{ $tx->type === 'in' ? 'cb-positive' : 'cb-negative' }}">{{ $tx->type === 'in' ? '+' : '−' }}₹{{ number_format($tx->amount, 2) }}</span></td>
                                </tr>
                            @empty
                                <tr><td colspan="8"><div class="cb-empty"><x-icon name="wallet" size="24" /><h3>{{ $hasActiveFilters ? 'No entries match your filters' : 'No transactions yet' }}</h3><p>{{ $hasActiveFilters ? 'Change or clear the entry filters to see more records.' : 'Recorded money movements will appear here.' }}</p>@if($hasActiveFilters)<a href="{{ route('cashbook.index') }}" class="btn btn-secondary btn-sm">Clear filters</a>@endif</div></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="cb-mobile-list">
                    @forelse($transactions as $tx)
                        @if($loop->first || $tx->created_at->toDateString() !== $transactions[$loop->index - 1]->created_at->toDateString())
                            @php [$dayLabel, $dayIn, $dayOut] = $dayHeading($tx->created_at->toDateString()); @endphp
                            <div class="cb-day-head"><strong>{{ $dayLabel }}</strong><span>{{ $dayIn }}</span><span>{{ $dayOut }}</span></div>
                        @endif
                        <article class="cb-mobile-card">
                            <header><span>#{{ $transactions->firstItem() + $loop->index }} · {{ $tx->type === 'in' ? 'Money In' : 'Money Out' }}</span><strong class="{{ $tx->type === 'in' ? 'cb-positive' : 'cb-negative' }}">{{ $tx->type === 'in' ? '+' : '−' }}₹{{ number_format($tx->amount, 2) }}</strong></header>
                            <p class="cb-mobile-date">{{ $tx->created_at->format('d M Y, h:i A') }}</p>
                            <dl>
                                <div><dt>Source</dt><dd>{{ \App\Models\CashTransaction::sourceLabel($tx->source_type, $tx->type) }}</dd></div>
                                <div><dt>Invoice</dt><dd>{{ $tx->invoice?->invoice_number ?? '—' }}</dd></div>
                                <div><dt>Payment mode / account</dt><dd>{{ $paymentModeLabels[$tx->payment_mode ?: 'cash'] ?? ucfirst($tx->payment_mode) }}{{ $tx->payment_method_label_snapshot ? ' · ' . $tx->payment_method_label_snapshot : '' }}</dd></div>
                                <div class="cb-mobile-description"><dt>Description</dt><dd>{{ $tx->description ?: '—' }}</dd></div>
                            </dl>
                        </article>
                    @empty
                        <div class="cb-empty"><h3>{{ $hasActiveFilters ? 'No entries match your filters' : 'No transactions yet' }}</h3><p>Change the filters or record an entry when money moves.</p></div>
                    @endforelse
                </div>

                @if($transactions->hasPages())
                    <footer class="cb-pagination" data-cb-pagination>{{ $transactions->links() }}</footer>
                @endif
            </section>
        </div>

        @can('cash.create')
            <dialog id="cashbook-entry" class="cb-drawer cb-entry-editor jf-scrollbar" x-ref="entry" aria-labelledby="cashbook-entry-title"
                    data-open-on-error="{{ $errors->hasAny(['type', 'amount', 'source_type', 'payment_mode', 'payment_method_id', 'description']) ? 'true' : 'false' }}"
                    x-init="if ($el.dataset.openOnError === 'true') $nextTick(() => $el.showModal())"
                    @turbo:before-cache.document="$el.close()" @turbo:before-render.document="$el.close()">
                <header><div><h2 id="cashbook-entry-title">Add entry</h2><p>Record money received or paid. Required fields marked *.</p></div><button type="button" class="btn btn-secondary btn-sm" aria-label="Close entry form" @click="$refs.entry.close()"><x-icon name="x" size="18" /></button></header>
                @include('cashbook._entry-form', ['entryModal' => true])
            </dialog>
            <dialog id="cashbook-drawer" class="cb-drawer jf-scrollbar" x-ref="drawer" aria-labelledby="cashbook-drawer-title"
                    @turbo:before-cache.document="$el.close()" @turbo:before-render.document="$el.close()"
                    @if($errors->has('counted_cash') || $errors->has('note')) x-init="$nextTick(() => $el.showModal())" @endif>
                <header><div><h2 id="cashbook-drawer-title">Match your drawer</h2><p>Today's physical cash only · {{ now()->format('d M Y') }}</p></div><button type="button" class="btn btn-secondary btn-sm" aria-label="Close drawer check" @click="$refs.drawer.close()"><x-icon name="x" size="18" /></button></header>
                <form method="POST" action="{{ route('cashbook.drawer-check') }}" class="cb-drawer-form" data-turbo-frame="_top">
                    @csrf
                    <dl class="cb-expected"><dt>Expected cash today</dt><dd>₹{{ number_format($expectedCashToday, 2) }}</dd></dl>
                    <p class="cb-filter-note">Uses all recorded cash through today, not the entry filters. Saving records a count; it does not adjust your ledger.</p>
                    <label for="cb-counted">Counted cash *<input id="cb-counted" type="number" step="0.01" min="0" max="99999999.99" name="counted_cash" required value="{{ old('counted_cash') }}" class="cb-control" autofocus></label>
                    @error('counted_cash')<p class="cb-error" role="alert">{{ $message }}</p>@enderror
                    <label for="cb-drawer-note">Note (optional)<input id="cb-drawer-note" type="text" name="note" maxlength="500" value="{{ old('note') }}" class="cb-control"></label>
                    @error('note')<p class="cb-error" role="alert">{{ $message }}</p>@enderror
                    <div class="cb-drawer-actions"><button type="button" class="btn btn-secondary btn-sm" @click="$refs.drawer.close()">Cancel</button><button type="submit" class="btn btn-primary btn-sm">Save drawer check</button></div>
                </form>
                @if($recentDrawerChecks->isNotEmpty())
                    <section class="cb-drawer-history" aria-labelledby="cb-counts-title"><h3 id="cb-counts-title">Recent counts</h3>
                        @foreach($recentDrawerChecks as $check)
                            @php $d = (float) $check->difference; @endphp
                            <div class="cb-history-row"><time>{{ $check->created_at->format('d M Y, h:i A') }}</time><p>Counted ₹{{ number_format($check->counted_cash, 2) }} · Expected ₹{{ number_format($check->expected_cash, 2) }}</p><strong class="{{ abs($d) < 0.01 ? 'cb-positive' : 'cb-negative' }}">@if(abs($d) < 0.01) Matched @elseif($d > 0) Over ₹{{ number_format($d, 2) }} @else Short ₹{{ number_format(abs($d), 2) }} @endif</strong></div>
                        @endforeach
                    </section>
                @endif
            </dialog>
        @endcan
    </div>
    <script>
        // Account filter follows Mode, as in the app: every account with "All Modes",
        // only that kind for UPI / Bank / Wallet, hidden for Cash / Card / Other.
        window.cbFilterAccounts = function () {
            const mode = document.getElementById('cb-filter-mode');
            const field = document.getElementById('cb-filter-account-field');
            const select = document.getElementById('cb-filter-account');
            if (!mode || !field || !select) return;

            let shown = 0;
            select.querySelectorAll('optgroup').forEach((group) => {
                const show = mode.value === '' || group.dataset.kind === mode.value;
                group.style.display = show ? '' : 'none';
                group.disabled = !show;
                shown += show ? 1 : 0;
            });
            if (select.selectedOptions[0]?.parentElement?.disabled) select.value = '';
            field.style.display = shown ? '' : 'none';
            select.disabled = shown === 0;
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', window.cbFilterAccounts, { once: true });
        } else {
            window.cbFilterAccounts();
        }
        document.addEventListener('turbo:load', window.cbFilterAccounts);
    </script>
</x-app-layout>
