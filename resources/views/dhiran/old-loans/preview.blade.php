@php
    $ready = collect($loans)->filter(fn ($l) => ! $l['problems'])->count();
    $bad = count($loans) - $ready;
@endphp
<x-dhiran-layout title="Check Old Loans">
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Check before bringing in</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $ready }} {{ \Illuminate\Support\Str::plural('loan', $ready) }} ready · {{ $bad }} with problems · nothing is saved yet</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('dhiran.old-loans.upload') }}" class="btn btn-secondary btn-sm">
                <x-icon name="arrow-left" size="14" class="mr-1" />
                Upload another file
            </a>
            @if($ready > 0)
                <form method="POST" action="{{ route('dhiran.old-loans.upload.confirm', $token) }}" data-turbo-frame="_top" class="inline"
                      onsubmit="return confirm('Bring in {{ $ready }} {{ \Illuminate\Support\Str::plural('loan', $ready) }}? Loans with problems are skipped.');">
                    @csrf <x-dhiran.once />
                    <button type="submit" class="btn btn-primary btn-sm"><x-icon name="check" size="14" class="mr-1" /> Bring in {{ $ready }} {{ \Illuminate\Support\Str::plural('loan', $ready) }}</button>
                </form>
            @endif
        </div>
    </x-dhiran.page-header>

    <div class="content-inner" data-list-workspace>
        <div class="dh-table-card" data-list-frame>
            <div class="dh-table-wrap" data-list-scroll tabindex="0" role="region" aria-label="Loans in the file">
                <table class="dh-table min-w-[900px] jf-table dh-stack">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th scope="col" class="dh-sno-th">#</th>
                            <th class="pl-6 pr-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Ticket #</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Rows</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Customer</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Mobile</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Lent</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Owed now</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Items</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Check</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($loans as $loan)
                            @php $d = $loan['data']; @endphp
                            <tr data-ticket="{{ $loan['ticket'] }}" data-ok="{{ $loan['problems'] ? 0 : 1 }}">
                                <td data-label="#" class="dh-sno">{{ $loop->iteration }}</td>
                                <td data-label="Ticket #" class="pl-6 pr-4 py-3 whitespace-nowrap font-mono text-slate-700">{{ $loan['ticket'] }}</td>
                                <td data-label="Rows" class="px-4 py-3 whitespace-nowrap text-sm text-slate-500">{{ min($loan['rows']) }}{{ count($loan['rows']) > 1 ? '–' . max($loan['rows']) : '' }}</td>
                                <td data-label="Customer" class="px-4 py-3 whitespace-nowrap text-sm text-slate-700">{{ trim(($d['borrower']['first_name'] ?? '') . ' ' . ($d['borrower']['last_name'] ?? '')) ?: '---' }}</td>
                                <td data-label="Mobile" class="px-4 py-3 whitespace-nowrap text-sm text-slate-600">{{ $d['borrower']['mobile'] ?? '---' }}</td>
                                <td data-label="Lent" class="px-4 py-3 whitespace-nowrap text-sm text-right">{{ is_numeric($d['principal_amount']) ? '₹' . \App\Support\IndianNumber::format($d['principal_amount']) : '---' }}</td>
                                <td data-label="Owed now" class="px-4 py-3 whitespace-nowrap text-sm text-right">{{ is_numeric($d['principal_owed']) ? '₹' . \App\Support\IndianNumber::format($d['principal_owed']) : '---' }}</td>
                                <td data-label="Items" class="px-4 py-3 whitespace-nowrap text-sm text-center">{{ count($d['items']) }}</td>
                                <td data-label="Check" class="px-4 py-3 text-sm">
                                    @if($loan['problems'])
                                        <span class="dh-status dh-status--danger">Will be skipped</span>
                                        <ul class="mt-1 text-xs text-red-700 space-y-0.5">
                                            @foreach($loan['problems'] as $problem)<li>{{ $problem }}</li>@endforeach
                                        </ul>
                                    @else
                                        <span class="dh-status dh-status--success">Ready</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-dhiran-layout>
