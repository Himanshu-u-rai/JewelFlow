<x-app-layout>
    <x-page-header :title="'Job Order ' . $jobOrder->job_order_number" :subtitle="'Karigar: ' . ($jobOrder->karigar?->name ?? '—') . ' · DC: ' . $jobOrder->challan_number" class="job-record-header">
        <x-slot:actions>
            <a href="{{ route('job-orders.index') }}" class="btn btn-secondary btn-sm"><x-icon name="arrow-left" size="16" />Job Orders</a>
            <a href="{{ route('job-orders.challan', $jobOrder) }}" target="_blank" rel="noopener" class="btn btn-secondary btn-sm"><x-icon name="printer" size="16" />Print Challan</a>
            @if($jobOrder->isOpen())
                @can('job_order.manage')
                    <a href="{{ route('job-orders.receive.form', $jobOrder) }}" class="btn btn-primary btn-sm"><x-icon name="square-check-big" size="16" />Receive Items</a>
                @endcan
            @endif
            @if($jobOrder->returned_fine_weight > 0 || $jobOrder->status === 'completed')
                <a href="{{ route('job-orders.return-doc', $jobOrder) }}" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">Return Doc</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner job-order-show-page job-record-page">
        <section class="job-record-surface" aria-label="Job order position">
            <div class="job-record-metrics">
                <dl class="job-record-metric" data-job-position="issued">
                    <dt>Issued (fine)</dt><dd>{{ number_format($jobOrder->issued_fine_weight, 3) }}g</dd>
                    <small>{{ number_format($jobOrder->issued_gross_weight, 3) }}g gross</small>
                </dl>
                <dl class="job-record-metric" data-job-position="returned">
                    <dt>Returned (fine)</dt><dd>{{ number_format($jobOrder->returned_fine_weight, 3) }}g</dd>
                    <small>{{ number_format($jobOrder->returned_gross_weight, 3) }}g gross</small>
                </dl>
                <dl class="job-record-metric" data-job-position="wastage">
                    <dt>Wastage</dt><dd>{{ number_format($jobOrder->actual_wastage_fine, 3) }}g</dd>
                    <small>Allowed: {{ number_format($jobOrder->allowed_wastage_fine, 3) }}g ({{ $jobOrder->allowed_wastage_percent }}%)</small>
                </dl>
                <dl class="job-record-metric" data-job-position="status">
                    <dt>Status</dt>
                    <dd><span class="job-record-status {{ $jobOrder->status === 'completed' ? 'bg-emerald-100 text-emerald-800' : ($jobOrder->status === 'cancelled' ? 'bg-gray-200 text-gray-600' : ($jobOrder->status === 'partial_return' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800')) }}">{{ str_replace('_', ' ', $jobOrder->status) }}</span></dd>
                    <small>Issued: {{ $jobOrder->issue_date->format('d M Y') }}</small>
                </dl>
            </div>
        </section>

        @if(!empty($jobOrder->discrepancy_flags))
            <section class="job-record-warning" aria-label="Weight discrepancy review">
                <x-icon name="triangle-alert" size="22" class="flex-none" />
                <div>
                    <p>Weight mismatch — needs review on this job order</p>
                    <div class="job-record-flags">
                        @foreach($jobOrder->discrepancy_flags as $flag)
                            <span>{{ str_replace('_', ' ', $flag) }}</span>
                        @endforeach
                    </div>
                    @if(!$jobOrder->discrepancy_acknowledged && $jobOrder->isOpen())
                        @can('job_order.manage')
                            <form method="POST" action="{{ route('job-orders.acknowledge', $jobOrder) }}" onsubmit="return confirm('Acknowledge discrepancies and mark this job order completed?');">
                                @csrf
                                <button type="submit" class="btn btn-secondary btn-sm">Acknowledge & Complete</button>
                            </form>
                        @endcan
                    @endif
                </div>
            </section>
        @endif

        <section class="job-record-surface" aria-labelledby="job-issuances-title">
            <header class="job-record-head"><h2 id="job-issuances-title">Issuance Lines</h2></header>
            <div class="job-record-scroll jf-table-wrap jf-scrollbar" tabindex="0" role="region" aria-label="Bullion issuance lines">
                <table class="jf-table">
                    <thead>
                        <tr>
                            <th scope="col" class="jf-table-index">#</th>
                            <th scope="col" class="text-left">Lot</th>
                            <th scope="col" class="text-left">Purity</th>
                            <th scope="col" class="text-right">Gross (g)</th>
                            <th scope="col" class="text-right">Fine (g)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($jobOrder->issuances as $iss)
                            <tr>
                                <td class="jf-table-index">{{ $loop->iteration }}</td>
                                <td>Lot #{{ $iss->metalLot?->lot_number ?? $iss->metal_lot_id }}</td>
                                <td>{{ rtrim(rtrim(number_format($iss->purity, 2), '0'), '.') }}{{ $jobOrder->metal_type === 'silver' ? '‰' : 'K' }}</td>
                                <td class="text-right">{{ number_format($iss->gross_weight, 3) }}g</td>
                                <td class="text-right">{{ number_format($iss->fine_weight, 3) }}g</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="jf-table-empty">No vault issuance lines recorded for this job.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="job-record-surface" aria-labelledby="job-receipts-title">
            <header class="job-record-head">
                <h2 id="job-receipts-title">Receipts ({{ $jobOrder->receipts->count() }})</h2>
                @if($jobOrder->isOpen())
                    @can('job_order.manage')
                        <a href="{{ route('job-orders.receive.form', $jobOrder) }}" class="btn btn-secondary btn-sm"><x-icon name="plus" size="16" />Receive Items</a>
                    @endcan
                @endif
            </header>
            @forelse($jobOrder->receipts as $rcpt)
                <section class="job-record-receipt" aria-label="Receipt {{ $rcpt->receipt_number }}">
                    <div class="job-record-receipt-head">
                        <div><strong>{{ $rcpt->receipt_number }}</strong><time>{{ $rcpt->receipt_date->format('d M Y') }}</time></div>
                        <span>{{ $rcpt->total_pieces }} pcs · {{ number_format($rcpt->total_net_weight, 3) }}g net · {{ number_format($rcpt->total_fine_weight, 3) }}g fine</span>
                    </div>
                    <div class="job-record-scroll jf-table-wrap jf-scrollbar" tabindex="0" role="region" aria-label="Items in receipt {{ $rcpt->receipt_number }}">
                        <table class="jf-table">
                            <thead>
                                <tr>
                                    <th scope="col" class="jf-table-index">#</th>
                                    <th scope="col" class="text-left">Description</th>
                                    <th scope="col" class="text-left">HSN</th>
                                    <th scope="col" class="text-right">Pcs</th>
                                    <th scope="col" class="text-right">Gross (g)</th>
                                    <th scope="col" class="text-right">Stone (g)</th>
                                    <th scope="col" class="text-right">Net (g)</th>
                                    <th scope="col" class="text-right">Purity</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($rcpt->items as $i)
                                    <tr>
                                        <td class="jf-table-index">{{ $loop->iteration }}</td>
                                        <td class="job-record-description">{{ $i->description }}</td>
                                        <td>{{ $i->hsn_code }}</td>
                                        <td class="text-right">{{ $i->pieces }}</td>
                                        <td class="text-right">{{ number_format($i->gross_weight, 3) }}</td>
                                        <td class="text-right">{{ number_format($i->stone_weight, 3) }}</td>
                                        <td class="text-right">{{ number_format($i->net_weight, 3) }}</td>
                                        <td class="text-right">{{ rtrim(rtrim(number_format($i->purity, 2), '0'), '.') }}{{ $jobOrder->metal_type === 'silver' ? '‰' : 'K' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @empty
                <p class="job-record-empty">No receipts yet.</p>
            @endforelse
        </section>

        <section class="job-record-surface" aria-labelledby="job-invoices-title">
            <header class="job-record-head">
                <h2 id="job-invoices-title">Karigar Invoices ({{ $jobOrder->invoices->count() }})</h2>
                @can('karigar_invoice.manage')
                    <a href="{{ route('karigar-invoices.create') }}?job_order={{ $jobOrder->id }}" class="btn btn-secondary btn-sm"><x-icon name="plus" size="16" />Add Invoice</a>
                @endcan
            </header>
            @if($jobOrder->invoices->isEmpty())
                <p class="job-record-empty">No karigar invoice attached yet.</p>
            @else
                <div class="job-record-scroll jf-table-wrap jf-scrollbar" tabindex="0" role="region" aria-label="Karigar invoices">
                    <table class="jf-table">
                        <thead>
                            <tr>
                                <th scope="col" class="jf-table-index">#</th>
                                <th scope="col" class="text-left">Invoice #</th>
                                <th scope="col" class="text-left">Date</th>
                                <th scope="col" class="text-left">Mode</th>
                                <th scope="col" class="text-right">Total</th>
                                <th scope="col" class="text-center">Payment</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($jobOrder->invoices as $inv)
                                <tr>
                                    <td class="jf-table-index">{{ $loop->iteration }}</td>
                                    <td><a href="{{ route('karigar-invoices.show', $inv) }}">{{ $inv->karigar_invoice_number }}</a></td>
                                    <td>{{ $inv->karigar_invoice_date->format('d M Y') }}</td>
                                    <td>{{ str_replace('_', ' ', $inv->mode) }}</td>
                                    <td class="text-right">₹{{ number_format($inv->total_after_tax, 2) }}</td>
                                    <td class="text-center"><span class="job-record-status {{ $inv->payment_status === 'paid' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">{{ $inv->payment_status }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        @if($jobOrder->isOpen())
            @can('job_order.manage')
                <section class="job-record-surface" aria-labelledby="job-operations-title">
                    <header class="job-record-head"><h2 id="job-operations-title">Other Actions</h2></header>
                    <div class="job-record-operations">
                        <form method="POST" action="{{ route('job-orders.leftover', $jobOrder) }}" class="job-record-operation">
                            @csrf
                            <div>
                                <label class="job-record-label" for="job-leftover">Record Leftover Bullion (fine g)</label>
                                <input id="job-leftover" type="number" step="0.001" min="0.001" name="fine_weight" required class="job-record-control">
                            </div>
                            <button type="submit" class="btn btn-secondary btn-sm">Credit Vault</button>
                        </form>
                        @if(isset($otherKarigars) && $otherKarigars->isNotEmpty())
                            <form method="POST" action="{{ route('job-orders.reassign', $jobOrder) }}" class="job-record-operation" onsubmit="return confirm('Give this job (and its metal) to the chosen karigar?');">
                                @csrf
                                <div>
                                    <label class="job-record-label" for="job-reassign">Give job to another karigar</label>
                                    <select id="job-reassign" name="to_karigar_id" required class="job-record-control">
                                        <option value="" disabled selected>— Select karigar —</option>
                                        @foreach($otherKarigars as $k)
                                            <option value="{{ $k->id }}">{{ $k->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-secondary btn-sm">Reassign</button>
                            </form>
                        @endif
                        @if($jobOrder->status === 'issued')
                            <form method="POST" action="{{ route('job-orders.cancel', $jobOrder) }}" onsubmit="return confirm('Cancel job order? This will return all bullion to the source lot.');">
                                @csrf
                                <button type="submit" class="btn btn-secondary btn-sm job-record-danger">Cancel Job Order</button>
                            </form>
                        @endif
                    </div>
                </section>
            @endcan
        @endif
    </div>
</x-app-layout>
