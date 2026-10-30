<x-app-layout>
    @php
        $name = trim($customer->first_name . ' ' . $customer->last_name);
        $tabLabels = ['invoices' => 'Invoices', 'quick-bills' => 'Quick bills', 'repairs' => 'Repairs'];
        $words = fn ($code) => ucfirst(str_replace('_', ' ', (string) $code));
    @endphp

    <x-page-header :title="$name . ' — history'" subtitle="Everything this customer bought or brought in">
        <x-slot:actions>
            <a href="{{ route('customers.show', $customer) }}" class="returns-show-back-btn" aria-label="Back to customer">
                <span>Back to customer</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner space-y-4">
        {{-- Only the lists this user may open (same permissions as each list page). --}}
        <nav class="flex flex-wrap gap-2" aria-label="History">
            @foreach($tabs as $key => $allowed)
                <a href="{{ route('customers.history', $customer) }}?tab={{ $key }}"
                   class="rounded-full border px-4 py-2 text-sm font-semibold {{ $tab === $key ? 'border-indigo-600 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-white text-slate-600' }}"
                   @if($tab === $key) aria-current="page" @endif>{{ $tabLabels[$key] }}</a>
            @endforeach
        </nav>

        <section class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100 text-sm text-slate-500">
                {{ $rows->total() }} {{ \Illuminate\Support\Str::plural('record', $rows->total()) }}
            </div>
            @if($rows->isEmpty())
                <p class="px-5 py-10 text-center text-sm text-slate-500">No {{ strtolower($tabLabels[$tab]) }} yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3 text-left">{{ $tab === 'repairs' ? 'Repair' : 'Bill' }}</th>
                                <th class="px-4 py-3 text-left">Date</th>
                                <th class="px-4 py-3 text-left">Status</th>
                                <th class="px-4 py-3 text-right">Amount</th>
                                @if($tab === 'quick-bills')<th class="px-4 py-3 text-right">Due</th>@endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($rows as $row)
                                @php
                                    [$label, $href, $date, $amount] = match ($tab) {
                                        'invoices'    => [$row->invoice_number, route('invoices.show', $row), $row->created_at, (float) $row->total],
                                        'quick-bills' => [$row->bill_number, route('quick-bills.show', $row), $row->bill_date ?? $row->created_at, (float) $row->total_amount],
                                        'repairs'     => [$row->reference() . ' · ' . $row->item_description, route('repairs.show', $row), $row->created_at, (float) ($row->final_cost ?? $row->estimated_cost)],
                                    };
                                @endphp
                                <tr>
                                    <td class="px-4 py-3"><a href="{{ $href }}" class="text-indigo-600 hover:underline font-semibold">{{ $label }}</a></td>
                                    <td class="px-4 py-3 text-slate-600">{{ optional($date)->format('d M Y') }}</td>
                                    <td class="px-4 py-3 text-slate-600">{{ $words($row->status) }}</td>
                                    <td class="px-4 py-3 text-right font-semibold text-slate-900">₹{{ number_format($amount, 2) }}</td>
                                    @if($tab === 'quick-bills')
                                        <td class="px-4 py-3 text-right {{ (float) $row->due_amount > 0 ? 'text-amber-700 font-semibold' : 'text-slate-400' }}">
                                            {{ (float) $row->due_amount > 0 ? '₹' . number_format((float) $row->due_amount, 2) : '—' }}
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="px-5 py-3">{{ $rows->links() }}</div>
            @endif
        </section>
    </div>
</x-app-layout>
