<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">GSTR-1 (Sales)</h1>
            <p class="text-sm text-gray-500 mt-1">B2B, B2CS and HSN summary for GST filing — {{ $period->label() }}</p>
        </div>
        <div class="page-actions">
            <form method="GET" action="{{ route('report.gstr1') }}" class="flex flex-wrap gap-2 items-end">
                <select name="month" class="rounded-lg border-slate-200 text-sm h-10">
                    @for($i = 1; $i <= 12; $i++)
                        <option value="{{ $i }}" {{ $month === $i ? 'selected' : '' }}>{{ \Carbon\Carbon::create()->month($i)->format('F') }}</option>
                    @endfor
                </select>
                <select name="year" class="rounded-lg border-slate-200 text-sm h-10">
                    @for($y = now()->year; $y >= now()->year - 5; $y--)
                        <option value="{{ $y }}" {{ $year === $y ? 'selected' : '' }}>{{ $y }}</option>
                    @endfor
                </select>
                <button type="submit" class="btn btn-secondary btn-sm">View</button>
                <a href="{{ route('report.gstr1.csv', ['month' => $month, 'year' => $year]) }}" class="btn btn-success btn-sm">Export CSV</a>
            </form>
        </div>
    </x-page-header>

    <div class="content-inner space-y-6">
        {{-- Summary band --}}
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Taxable</p><p class="text-lg font-semibold">₹{{ number_format($data->taxable, 2) }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">CGST</p><p class="text-lg font-semibold text-emerald-600">₹{{ number_format($data->cgst, 2) }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">SGST</p><p class="text-lg font-semibold text-emerald-600">₹{{ number_format($data->sgst, 2) }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">IGST</p><p class="text-lg font-semibold text-emerald-600">₹{{ number_format($data->igst, 2) }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Invoices</p><p class="text-lg font-semibold">{{ $data->invoiceCount }}</p></div>
        </div>

        {{-- B2B --}}
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100"><h2 class="font-semibold text-gray-900">B2B — Registered Buyers</h2></div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50"><tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Invoice</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Buyer GSTIN</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Taxable</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">CGST</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">SGST</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">IGST</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($data->b2b as $r)
                            <tr>
                                <td class="px-4 py-2 font-mono">{{ $r->invoice_number }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ \Carbon\Carbon::parse($r->doc_date)->format('d M Y') }}</td>
                                <td class="px-4 py-2 font-mono text-gray-600">{{ $r->buyer_gstin }}</td>
                                <td class="px-4 py-2 text-right">₹{{ number_format($r->taxable, 2) }}</td>
                                <td class="px-4 py-2 text-right text-emerald-600">₹{{ number_format($r->cgst, 2) }}</td>
                                <td class="px-4 py-2 text-right text-emerald-600">₹{{ number_format($r->sgst, 2) }}</td>
                                <td class="px-4 py-2 text-right text-emerald-600">₹{{ number_format($r->igst, 2) }}</td>
                                <td class="px-4 py-2 text-right font-semibold">₹{{ number_format($r->total, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-4 py-6 text-center text-gray-400 text-sm">No B2B (registered-buyer) invoices this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- B2CS --}}
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100"><h2 class="font-semibold text-gray-900">B2CS — Consumers (rate-wise)</h2></div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50"><tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Rate</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Place of Supply</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Taxable</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">CGST</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">SGST</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">IGST</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Invoices</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($data->b2cs as $r)
                            <tr>
                                <td class="px-4 py-2"><span class="px-2 py-0.5 rounded bg-amber-100 text-amber-800 text-xs">{{ number_format($r->gst_rate, 2) }}%</span></td>
                                <td class="px-4 py-2 text-gray-600">{{ $r->place_of_supply_state_code ?? '—' }}</td>
                                <td class="px-4 py-2 text-right">₹{{ number_format($r->taxable, 2) }}</td>
                                <td class="px-4 py-2 text-right text-emerald-600">₹{{ number_format($r->cgst, 2) }}</td>
                                <td class="px-4 py-2 text-right text-emerald-600">₹{{ number_format($r->sgst, 2) }}</td>
                                <td class="px-4 py-2 text-right text-emerald-600">₹{{ number_format($r->igst, 2) }}</td>
                                <td class="px-4 py-2 text-right text-gray-600">{{ $r->count }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-6 text-center text-gray-400 text-sm">No B2CS sales this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- HSN --}}
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100"><h2 class="font-semibold text-gray-900">HSN Summary</h2></div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50"><tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">HSN</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Taxable</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">GST</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Lines</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($data->hsnSummary as $r)
                            <tr>
                                <td class="px-4 py-2 font-mono">{{ $r->hsn_code }}</td>
                                <td class="px-4 py-2 text-right">₹{{ number_format($r->taxable, 2) }}</td>
                                <td class="px-4 py-2 text-right text-emerald-600">₹{{ number_format($r->gst, 2) }}</td>
                                <td class="px-4 py-2 text-right text-gray-600">{{ $r->lines }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400 text-sm">No HSN data this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if($data->cnCount > 0)
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm text-amber-800">
            {{ $data->cnCount }} credit note(s) this period reversed ₹{{ number_format($data->cnGst, 2) }} GST on ₹{{ number_format($data->cnTaxable, 2) }} taxable —
            see the <a href="{{ route('report.cn-register', ['month' => $month, 'year' => $year]) }}" class="underline font-medium">Credit Note Register</a>.
        </div>
        @endif
    </div>
</x-app-layout>
