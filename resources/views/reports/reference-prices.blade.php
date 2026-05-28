<x-app-layout>
    <x-page-header>
        <h1 class="page-title">Reference Prices</h1>
        <div class="page-actions">
            <span class="header-badge">Memo · Class B only</span>
        </div>
    </x-page-header>

    <div class="content-inner space-y-6">
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm text-amber-900">
            These are <strong>operator notes</strong> for platinum and copper —
            "what we are asking per gram this week." They are <strong>not</strong>
            daily rates and never feed pricing, vault, GST, or reconciliation.
            Gold and silver use the Daily Rates screen and are not shown here.
        </div>

        @foreach($tier2 as $metal)
            @php
                $rows = $timelines[$metal] ?? collect();
            @endphp
            <section class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                <header class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-slate-900 capitalize">{{ $metal }} — reference price history</h2>
                    @if($rows->isNotEmpty())
                        <span class="text-xs text-slate-500">{{ $rows->count() }} note(s) — newest first</span>
                    @endif
                </header>

                @if($rows->isEmpty())
                    <div class="px-5 py-8 text-center text-slate-500 text-sm">
                        {{ __('No reference noted for :metal yet.', ['metal' => $metal]) }}
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-5 py-2 text-left text-xs font-semibold text-slate-600 uppercase tracking-wide">When noted</th>
                                    <th class="px-5 py-2 text-right text-xs font-semibold text-slate-600 uppercase tracking-wide">Reference price (₹ / g)</th>
                                    <th class="px-5 py-2 text-left text-xs font-semibold text-slate-600 uppercase tracking-wide">Noted by</th>
                                    <th class="px-5 py-2 text-left text-xs font-semibold text-slate-600 uppercase tracking-wide">Note</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($rows as $row)
                                    <tr>
                                        <td class="px-5 py-2 text-slate-700 whitespace-nowrap">{{ optional($row->noted_at)->format('d M Y, h:i A') }}</td>
                                        <td class="px-5 py-2 text-right font-mono text-slate-900">₹{{ number_format((float) $row->reference_price, 2) }}</td>
                                        <td class="px-5 py-2 text-slate-700">{{ $row->notedBy->name ?? '—' }}</td>
                                        <td class="px-5 py-2 text-slate-600">{{ $row->note ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endforeach
    </div>
</x-app-layout>
