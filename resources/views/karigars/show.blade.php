<x-app-layout>
    <x-page-header class="record-detail-header" :title="$karigar->name" :subtitle="collect([$karigar->shop_name, $karigar->gst_number ? 'GST ' . $karigar->gst_number : null])->filter()->implode(' · ') ?: null">
        <x-slot:actions>
            <a href="{{ route('karigars.index') }}" class="btn btn-secondary btn-sm" aria-label="All karigars"><x-icon name="arrow-left" size="16" /><span>Karigars</span></a>
            @can('karigar.manage')<a href="{{ route('karigars.edit', $karigar) }}" class="btn btn-secondary btn-sm"><x-icon name="pencil" size="16" /><span>Edit</span></a>@endcan
            @can('job_order.manage')<a href="{{ route('job-orders.create') }}?karigar={{ $karigar->id }}" class="btn btn-primary btn-sm"><x-icon name="plus" size="16" /><span>Issue Bullion</span></a>@endcan
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner karigar-show-page record-detail-page">

        <div class="rd-profile-layout">
            <div class="rd-surface rd-profile">
                <h3 class="rd-title">Profile</h3>
                <dl class="rd-profile-grid">
                    <div><dt class="text-[10px] uppercase tracking-wide text-gray-500">Owner</dt><dd class="text-gray-800">{{ $karigar->name }}</dd></div>
                    <div><dt class="text-[10px] uppercase tracking-wide text-gray-500">Workshop / Shop</dt><dd class="text-gray-800">{{ $karigar->shop_name ?? '—' }}</dd></div>
                    <div><dt class="text-[10px] uppercase tracking-wide text-gray-500">Day-to-day Contact</dt><dd class="text-gray-800">{{ $karigar->contact_person ?? '—' }}</dd></div>
                    <div><dt class="text-[10px] uppercase tracking-wide text-gray-500">Mobile</dt><dd class="text-gray-800">{{ \App\Support\Mobile::forDisplay($karigar->mobile) ?: '—' }}</dd></div>
                    <div><dt class="text-[10px] uppercase tracking-wide text-gray-500">Email</dt><dd class="text-gray-800">{{ $karigar->email ?? '—' }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-[10px] uppercase tracking-wide text-gray-500">Workshop address</dt><dd class="text-gray-800">{{ $karigar->address ?: '—' }}{{ $karigar->city ? ', ' . $karigar->city : '' }}{{ $karigar->state ? ', ' . $karigar->state : '' }} {{ $karigar->pincode }}</dd></div>
                    <div><dt class="text-[10px] uppercase tracking-wide text-gray-500">PAN</dt><dd class="text-gray-800 font-mono">{{ $karigar->pan_number ?? '—' }}</dd></div>
                    <div><dt class="text-[10px] uppercase tracking-wide text-gray-500">Default Wastage %</dt><dd class="text-gray-800">{{ $karigar->default_wastage_percent ?? '—' }}</dd></div>
                </dl>
            </div>
            <div class="rd-surface rd-outstanding">
                <h3 class="rd-title">Account balance</h3>
                <dl class="rd-metrics" aria-label="Karigar balances">
                    <div><dt>Outstanding</dt><dd>₹{{ number_format($karigar->outstanding_balance, 2) }}</dd></div>
                    <div><dt>Opening</dt><dd>₹{{ number_format($karigar->opening_balance, 2) }}</dd></div>
                </dl>
            </div>
        </div>

        @php
            $heldReusable = (float) ($goldHeld->sum('reusable') ?? 0);
            $heldInJobs   = (float) ($goldHeld->sum('in_jobs') ?? 0);
            $heldTotal    = (float) ($goldHeld->sum('total') ?? 0);
            $karatLabel = fn ($metal, $purity) => $metal === 'silver'
                ? rtrim(rtrim(number_format($purity, 2), '0'), '.') . '‰ silver'
                : rtrim(rtrim(number_format($purity, 2), '0'), '.') . 'K';
        @endphp
        <div class="rd-surface rd-holdings">
            <div class="rd-heading">
                <h3 class="text-sm font-semibold text-gray-800">Gold with {{ $karigar->name }}</h3>
                <span class="text-[11px] text-gray-500">Your gold currently in their hands</span>
            </div>

            @if($goldHeld->isEmpty())
                <p class="text-sm text-gray-500">{{ $karigar->name }} is not holding any of your gold right now.</p>
            @else
                <div class="rd-holding-grid">
                    {{-- Reusable leftover — the actionable number (largest, amber). --}}
                    <div>
                        <div class="text-[10px] uppercase tracking-wide text-gray-500 mb-1">Reusable leftover</div>
                        <div class="text-2xl font-bold text-amber-700">{{ number_format($heldReusable, 3) }}g</div>
                        <div class="mt-2 space-y-0.5">
                            @foreach($goldHeld->where('reusable', '>', 0) as $row)
                                <div class="text-xs text-gray-600">{{ $karatLabel($row['metal_type'], $row['purity']) }} — <span class="font-mono font-medium">{{ number_format($row['reusable'], 3) }}g</span></div>
                            @endforeach
                            @if($heldReusable <= 0)
                                <div class="text-xs text-gray-400">None</div>
                            @endif
                        </div>
                        <p class="text-[11px] text-gray-400 mt-1.5">Spare gold they can use for new work.</p>
                    </div>

                    {{-- In unfinished jobs — secondary, muted. --}}
                    <div>
                        <div class="text-[10px] uppercase tracking-wide text-gray-500 mb-1">In unfinished jobs</div>
                        <div class="text-2xl font-semibold text-gray-700">{{ number_format($heldInJobs, 3) }}g</div>
                        <div class="mt-2 space-y-0.5">
                            @foreach($goldHeld->where('in_jobs', '>', 0) as $row)
                                <div class="text-xs text-gray-500">{{ $karatLabel($row['metal_type'], $row['purity']) }} — <span class="font-mono">{{ number_format($row['in_jobs'], 3) }}g</span></div>
                            @endforeach
                            @if($heldInJobs <= 0)
                                <div class="text-xs text-gray-400">None</div>
                            @endif
                        </div>
                        <p class="text-[11px] text-gray-400 mt-1.5">Gold still being worked on, not yet returned.</p>
                    </div>

                    {{-- Total — the headline figure. --}}
                    <div class="sm:border-l sm:border-gray-100 sm:pl-4">
                        <div class="text-[10px] uppercase tracking-wide text-gray-500 mb-1">Total gold with them</div>
                        <div class="text-2xl font-bold text-gray-900">{{ number_format($heldTotal, 3) }}g</div>
                        <p class="text-[11px] text-gray-400 mt-1.5">Everything of yours they currently hold.</p>
                    </div>
                </div>
            @endif
        </div>

        <div class="rd-surface rd-records">
            <div class="rd-heading">
                <h3 class="text-sm font-semibold text-gray-800">Recent Job Orders</h3>
                <a href="{{ route('job-orders.index') }}?karigar_id={{ $karigar->id }}" class="text-xs text-amber-700 hover:underline">View all</a>
            </div>
            @if($karigar->jobOrders->isEmpty())
                <div class="py-10 text-center text-gray-400 text-sm">No job orders yet.</div>
            @else
                <div class="rd-scroll jf-scrollbar" data-detail-scroll tabindex="0" role="region" aria-label="Recent karigar records"><table class="jf-table rd-table" role="table">
                    <thead>
                        <tr class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500"><th scope="col" class="jf-table-index" aria-label="Row number">#</th>
                            <th scope="col" class="px-4 py-2 text-left font-semibold">Job #</th>
                            <th scope="col" class="px-4 py-2 text-left font-semibold">Issued</th>
                            <th scope="col" class="px-4 py-2 text-right font-semibold">Fine Wt</th>
                            <th scope="col" class="px-4 py-2 text-center font-semibold">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($karigar->jobOrders as $jo)
                            <tr class="hover:bg-gray-50"><td class="jf-table-index" data-label="#">{{ $loop->iteration }}</td>
                                <td class="px-4 py-2" data-label="Job #"><a href="{{ route('job-orders.show', $jo) }}" class="text-amber-700 font-mono hover:underline">{{ $jo->job_order_number }}</a></td>
                                <td class="px-4 py-2 text-gray-500" data-label="Issued">{{ $jo->issue_date->format('d M Y') }}</td>
                                <td class="px-4 py-2 text-right font-mono" data-label="Fine Wt">{{ number_format($jo->issued_fine_weight, 3) }}g</td>
                                <td class="px-4 py-2 text-center" data-label="Status"><span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-semibold bg-amber-100 text-amber-800">{{ str_replace('_', ' ', $jo->status) }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
            @endif
        </div>

        <div class="rd-surface rd-records">
            <div class="rd-heading">
                <h3 class="text-sm font-semibold text-gray-800">Recent Invoices</h3>
            </div>
            @if($karigar->invoices->isEmpty())
                <div class="py-10 text-center text-gray-400 text-sm">No invoices recorded.</div>
            @else
                <div class="rd-scroll jf-scrollbar" data-detail-scroll tabindex="0" role="region" aria-label="Recent karigar records"><table class="jf-table rd-table" role="table">
                    <thead>
                        <tr class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500"><th scope="col" class="jf-table-index" aria-label="Row number">#</th>
                            <th scope="col" class="px-4 py-2 text-left font-semibold">Invoice #</th>
                            <th scope="col" class="px-4 py-2 text-left font-semibold">Date</th>
                            <th scope="col" class="px-4 py-2 text-right font-semibold">Total</th>
                            <th scope="col" class="px-4 py-2 text-center font-semibold">Payment</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($karigar->invoices as $inv)
                            <tr class="hover:bg-gray-50"><td class="jf-table-index" data-label="#">{{ $loop->iteration }}</td>
                                <td class="px-4 py-2" data-label="Invoice #"><a href="{{ route('karigar-invoices.show', $inv) }}" class="text-amber-700 hover:underline font-mono">{{ $inv->karigar_invoice_number }}</a></td>
                                <td class="px-4 py-2 text-gray-500" data-label="Date">{{ $inv->karigar_invoice_date->format('d M Y') }}</td>
                                <td class="px-4 py-2 text-right font-mono" data-label="Total">₹{{ number_format($inv->total_after_tax, 2) }}</td>
                                <td class="px-4 py-2 text-center" data-label="Payment"><span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-semibold {{ $inv->payment_status === 'paid' ? 'bg-emerald-100 text-emerald-800' : ($inv->payment_status === 'partial' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800') }}">{{ $inv->payment_status }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
            @endif
        </div>
    </div>
</x-app-layout>
