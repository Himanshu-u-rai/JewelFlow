<x-app-layout>
    <x-page-header
        :title="'Lot #' . $metalLot->lot_number"
        :subtitle="ucfirst($metalLot->metal_type ?? 'Gold') . ' · ' . rtrim(rtrim(number_format($metalLot->purity, 2), '0'), '.') . 'K · ' . ucfirst(str_replace('_', ' ', $metalLot->source))">
        <x-slot:actions>
            <a href="{{ route('vault.index') }}" class="btn btn-secondary btn-sm">← Vault</a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner">
        <style>
            .lot-facts { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); border:1px solid #e5e7eb; border-radius:10px; background:#fff; margin-bottom:16px; overflow:hidden; }
            @media (min-width:640px){ .lot-facts { grid-template-columns:repeat(4,minmax(0,1fr)); } }
            .lot-fact { padding:10px 14px; border-right:1px solid #f3f4f6; border-bottom:1px solid #f3f4f6; min-width:0; }
            .lot-fact dt { font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#6b7280; font-weight:600; }
            .lot-fact dd { margin:2px 0 0; font-size:14px; color:#111827; overflow-wrap:anywhere; }
            .lot-fact dd.lot-num { font-family:ui-monospace,monospace; font-weight:600; font-variant-numeric:tabular-nums; }
            .lot-fact small { display:block; font-size:11px; font-weight:400; color:#6b7280; font-family:inherit; }
            .lot-fact-wide { grid-column:1 / -1; }
            .lot-usage { height:6px; border-radius:999px; background:#f3f4f6; overflow:hidden; margin:8px 0 0; }
            .lot-usage > span { display:block; height:100%; background:#4b5563; }
            .lot-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; margin-bottom:16px; overflow:hidden; }
            .lot-card > header { padding:10px 14px; border-bottom:1px solid #f3f4f6; }
            .lot-card > header h2, .lot-card > header h3 { font-size:14px; font-weight:600; color:#111827; margin:0; }
            .lot-card > header p { font-size:12px; color:#6b7280; margin:2px 0 0; }
            .lot-card-body { padding:14px; }
            .lot-table-wrap { overflow-x:auto; max-width:100%; }
            .lot-table-wrap .lot-w { text-align:right; font-family:ui-monospace,monospace; font-variant-numeric:tabular-nums; white-space:nowrap; color:#111827; }
            .lot-chip { display:inline-block; padding:1px 8px; border-radius:999px; font-size:11px; font-weight:600; background:#f3f4f6; color:#374151; text-transform:capitalize; }
            .lot-adjust { display:grid; grid-template-columns:minmax(0,1fr); gap:12px; }
            @media (min-width:640px){ .lot-adjust { grid-template-columns:1fr 1fr 2fr; align-items:end; } .lot-adjust .lot-adjust-actions { grid-column:1 / -1; } }
            .lot-adjust label { display:block; font-size:12px; font-weight:500; color:#4b5563; margin-bottom:4px; }
            .lot-adjust select, .lot-adjust input { width:100%; max-width:100%; border-radius:6px; border:1px solid #d1d5db; font-size:14px; }
        </style>

        @php
            $pct = $metalLot->fine_weight_total > 0 ? round((float) $metalLot->fine_weight_remaining / (float) $metalLot->fine_weight_total * 100) : 0;
            $issued = (float) $metalLot->fine_weight_total - (float) $metalLot->fine_weight_remaining;
        @endphp

        {{-- Compact lot facts --}}
        <dl class="lot-facts" aria-label="Lot summary">
            <div class="lot-fact">
                <dt>Total fine</dt>
                <dd class="lot-num">{{ number_format($metalLot->fine_weight_total, 3) }} g<small>when received</small></dd>
            </div>
            <div class="lot-fact">
                <dt>Remaining</dt>
                <dd class="lot-num">{{ number_format($metalLot->fine_weight_remaining, 3) }} g<small>{{ $pct }}% left</small></dd>
            </div>
            <div class="lot-fact">
                <dt>Issued out</dt>
                <dd class="lot-num">{{ number_format($issued, 3) }} g<small>to karigars</small></dd>
            </div>
            <div class="lot-fact">
                <dt>Purity</dt>
                <dd class="lot-num">{{ rtrim(rtrim(number_format($metalLot->purity, 2), '0'), '.') }}K<small>{{ ucfirst($metalLot->metal_type ?? 'gold') }}</small></dd>
            </div>
            <div class="lot-fact">
                <dt>Source</dt>
                <dd class="capitalize">{{ str_replace('_', ' ', $metalLot->source) }}</dd>
            </div>
            <div class="lot-fact">
                <dt>Vendor / Supplier</dt>
                <dd>{{ $metalLot->vendor?->name ?? '—' }}</dd>
            </div>
            <div class="lot-fact">
                <dt>Cost / fine gram</dt>
                <dd class="lot-num">{{ $metalLot->cost_per_fine_gram ? '₹' . number_format($metalLot->cost_per_fine_gram, 2) : '—' }}</dd>
            </div>
            <div class="lot-fact">
                <dt>Added on</dt>
                <dd>{{ $metalLot->created_at->format('d M Y, H:i') }}</dd>
            </div>
            @if($metalLot->notes)
                <div class="lot-fact lot-fact-wide">
                    <dt>Notes</dt>
                    <dd>{{ $metalLot->notes }}</dd>
                </div>
            @endif
            <div class="lot-fact lot-fact-wide">
                <dt>Fine weight usage</dt>
                <dd class="lot-num">{{ number_format($issued, 3) }} g used of {{ number_format($metalLot->fine_weight_total, 3) }} g</dd>
                <div class="lot-usage" role="img" aria-label="{{ $pct }}% of this lot is still available"><span style="width: {{ 100 - $pct }}%"></span></div>
            </div>
        </dl>

        {{-- Movement history --}}
        <section class="lot-card" aria-labelledby="lot-movements-title">
            <header>
                <h2 id="lot-movements-title">Movement history</h2>
                <p>Every deduction and addition recorded against this lot</p>
            </header>
            @if($movements->isEmpty())
                <div class="py-10 text-center text-gray-500 text-sm">No movements recorded yet.</div>
            @else
                {{-- Running balance from the bottom up --}}
                @php
                    // Build running balance: start from current remaining, work backwards
                    $runningBalance = (float) $metalLot->fine_weight_remaining;
                    $rows = [];
                    foreach ($movements as $mv) {
                        $isDebit  = $mv->from_lot_id === $metalLot->id;  // gold left this lot
                        $isCredit = $mv->to_lot_id   === $metalLot->id;  // gold entered this lot
                        $amount   = (float) $mv->fine_weight;
                        $balanceAfter  = $runningBalance;
                        $balanceBefore = $isDebit ? $runningBalance + $amount : $runningBalance - $amount;

                        // Resolve reference label
                        $refLabel = $mv->reference_type . ' #' . $mv->reference_id;
                        if ($mv->reference_type === 'job_order' && isset($jobOrders[$mv->reference_id])) {
                            $refLabel = $jobOrders[$mv->reference_id]->job_order_number;
                        } elseif ($mv->reference_type === 'metal_lot') {
                            $refLabel = 'Lot #' . $mv->reference_id . ' (added)';
                        }

                        $rows[] = compact('mv', 'isDebit', 'isCredit', 'amount', 'balanceBefore', 'balanceAfter', 'refLabel');
                        $runningBalance = $balanceBefore;
                    }
                @endphp
                <div class="lot-table-wrap">
                    <table class="w-full text-sm jf-table" id="lot-movements-table">
                        <thead>
                            <tr class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                <th scope="col" class="jf-table-index" aria-label="Row number">#</th>
                                <th class="px-4 py-2 text-left font-semibold">When</th>
                                <th class="px-4 py-2 text-left font-semibold">Type</th>
                                <th class="px-4 py-2 text-left font-semibold">Reference</th>
                                <th class="px-4 py-2 text-right font-semibold">Deducted</th>
                                <th class="px-4 py-2 text-right font-semibold">Added</th>
                                <th class="px-4 py-2 text-right font-semibold">Balance After</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($rows as $row)
                                <tr class="hover:bg-gray-50 {{ $row['mv']->reference_type === 'job_order' && isset($jobOrders[$row['mv']->reference_id]) ? 'cursor-pointer' : '' }}"
                                    @if($row['mv']->reference_type === 'job_order' && isset($jobOrders[$row['mv']->reference_id]))
                                        onclick="window.location='{{ route('job-orders.show', $row['mv']->reference_id) }}'"
                                    @endif
                                >
                                    <td class="jf-table-index">{{ $loop->iteration }}</td>
                                    <td class="px-4 py-2 text-gray-500 whitespace-nowrap text-xs">{{ $row['mv']->created_at->format('d M Y, H:i') }}</td>
                                    <td class="px-4 py-2">
                                        <span class="lot-chip">{{ str_replace('_', ' ', $row['mv']->type) }}</span>
                                    </td>
                                    <td class="px-4 py-2 text-gray-600 text-xs">
                                        @if($row['mv']->reference_type === 'job_order' && isset($jobOrders[$row['mv']->reference_id]))
                                            <a href="{{ route('job-orders.show', $row['mv']->reference_id) }}" class="text-gray-900 underline font-mono">{{ $row['refLabel'] }}</a>
                                        @else
                                            <span class="text-gray-500">{{ $row['refLabel'] }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 lot-w {{ $row['isDebit'] ? '' : 'text-gray-400' }}">
                                        {{ $row['isDebit'] ? '− ' . number_format($row['amount'], 3) . 'g' : '—' }}
                                    </td>
                                    <td class="px-4 py-2 lot-w {{ $row['isCredit'] ? '' : 'text-gray-400' }}">
                                        {{ $row['isCredit'] ? '+ ' . number_format($row['amount'], 3) . 'g' : '—' }}
                                    </td>
                                    <td class="px-4 py-2 lot-w font-semibold">
                                        {{ number_format($row['balanceAfter'], 3) }}g
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="bg-gray-50 border-t-2 border-gray-200">
                                <td colspan="6" class="px-4 py-2 text-xs font-semibold text-gray-600 text-right">Current balance</td>
                                <td class="px-4 py-2 lot-w font-semibold">{{ number_format($metalLot->fine_weight_remaining, 3) }}g</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </section>

        @can('vault.manage')
        <section class="lot-card" aria-labelledby="lot-adjust-title">
            <header>
                <h3 id="lot-adjust-title">Correct vault balance</h3>
                <p>Use this only to fix a difference between the physical weight and the system. It records a tracked adjustment with your reason — it does not change past entries.</p>
            </header>
            <div class="lot-card-body">
                <form method="POST" action="{{ route('vault.lots.adjust', $metalLot) }}" data-turbo-frame="_top"
                      data-confirm-message="{{ __('Record this vault adjustment? It cannot be undone (only offset by another adjustment).') }}"
                      class="lot-adjust">
                    @csrf
                    <div>
                        <label for="vault-adjust-direction">Direction</label>
                        <select name="direction" id="vault-adjust-direction">
                            <option value="add">Add to vault (+)</option>
                            <option value="remove">Remove from vault (−)</option>
                        </select>
                    </div>
                    <div>
                        <label for="vault-adjust-fine-weight">Fine weight (g)</label>
                        <input type="number" name="fine_weight" id="vault-adjust-fine-weight" step="0.0001" min="0.0001" required class="font-mono">
                    </div>
                    <div>
                        <label for="vault-adjust-reason">Reason (required)</label>
                        <input type="text" name="reason" id="vault-adjust-reason" maxlength="500" required placeholder="e.g. physical count correction">
                    </div>
                    <div class="lot-adjust-actions">
                        <button type="submit" class="btn btn-primary btn-sm">Record adjustment</button>
                    </div>
                </form>
            </div>
        </section>
        @endcan
    </div>
</x-app-layout>
