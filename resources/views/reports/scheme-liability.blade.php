<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Scheme Liability</h1>
            <p class="text-sm text-gray-500 mt-1">What the shop owes on active &amp; matured gold-savings enrollments (contributions + accrued bonus)</p>
        </div>
        <div class="page-actions">
            <x-print-button />
            <a href="{{ route('report.scheme-liability.csv') }}" class="btn btn-success btn-sm">Export CSV</a>
        </div>
    </x-page-header>

    <div class="content-inner space-y-6">
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Total Liability</p><p class="text-lg font-semibold text-rose-600">₹{{ number_format($data->totalLiability, 2) }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Contributions</p><p class="text-lg font-semibold">₹{{ number_format($data->totalContributions, 2) }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Bonus Accrued</p><p class="text-lg font-semibold text-amber-600">₹{{ number_format($data->bonusAccrued, 2) }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Enrollments</p><p class="text-lg font-semibold">{{ $data->enrollmentCount }} <span class="text-xs font-normal text-gray-400">({{ $data->maturedCount }} matured)</span></p></div>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100"><h2 class="font-semibold text-gray-900">Open Enrollments</h2></div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50"><tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Scheme</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Contributed</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Bonus</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Balance Owed</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Maturity</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($data->rows as $r)
                            <tr>
                                <td class="px-4 py-2 text-gray-800">{{ $r->customer_name }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ $r->scheme_name ?? '—' }}</td>
                                <td class="px-4 py-2"><span class="px-2 py-0.5 rounded text-xs {{ $r->status === 'matured' ? 'bg-emerald-100 text-emerald-800' : 'bg-blue-100 text-blue-800' }}">{{ ucfirst($r->status) }}</span></td>
                                <td class="px-4 py-2 text-right">₹{{ number_format($r->total_paid, 2) }}</td>
                                <td class="px-4 py-2 text-right {{ $r->bonus_accrued > 0 ? 'text-amber-600' : 'text-gray-400' }}">{{ $r->bonus_accrued > 0 ? '₹' . number_format($r->bonus_accrued, 2) : '—' }}</td>
                                <td class="px-4 py-2 text-right font-semibold">₹{{ number_format($r->current_balance, 2) }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ $r->maturity_date ? \Carbon\Carbon::parse($r->maturity_date)->format('d M Y') : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">No open scheme enrollments.</td></tr>
                        @endforelse
                    </tbody>
                    @if($data->rows->isNotEmpty())
                    <tfoot class="bg-gray-50 font-semibold">
                        <tr>
                            <td class="px-4 py-2" colspan="3">Total</td>
                            <td class="px-4 py-2 text-right">₹{{ number_format($data->totalContributions, 2) }}</td>
                            <td class="px-4 py-2 text-right">₹{{ number_format($data->bonusAccrued, 2) }}</td>
                            <td class="px-4 py-2 text-right">₹{{ number_format($data->totalLiability, 2) }}</td>
                            <td class="px-4 py-2"></td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
