<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">GSTR-3B Support</h1>
            <p class="text-sm text-gray-500 mt-1">Net output-tax position for the month — {{ $period->label() }}</p>
        </div>
        <div class="page-actions">
            <form method="GET" action="{{ route('report.gstr3b') }}" class="flex flex-wrap gap-2 items-end">
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
                <button type="button" onclick="window.print()" class="btn btn-secondary btn-sm">Print</button>
            </form>
        </div>
    </x-page-header>

    <div class="content-inner">
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden max-w-3xl">
            <div class="px-5 py-3 border-b border-gray-100"><h2 class="font-semibold text-gray-900">Tax Summary</h2></div>
            <table class="min-w-full text-sm">
                <tbody class="divide-y divide-gray-100">
                    <tr><td class="px-5 py-3 text-gray-600">3.1(a) Outward taxable supplies</td><td class="px-5 py-3 text-right font-medium">₹{{ number_format($data->outwardTaxable, 2) }}</td></tr>
                    <tr><td class="px-5 py-3 pl-10 text-gray-500">CGST</td><td class="px-5 py-3 text-right text-emerald-600">₹{{ number_format($data->outwardCgst, 2) }}</td></tr>
                    <tr><td class="px-5 py-3 pl-10 text-gray-500">SGST</td><td class="px-5 py-3 text-right text-emerald-600">₹{{ number_format($data->outwardSgst, 2) }}</td></tr>
                    <tr><td class="px-5 py-3 pl-10 text-gray-500">IGST</td><td class="px-5 py-3 text-right text-emerald-600">₹{{ number_format($data->outwardIgst, 2) }}</td></tr>
                    <tr><td class="px-5 py-3 font-medium text-gray-700">Total output tax</td><td class="px-5 py-3 text-right font-semibold text-emerald-700">₹{{ number_format($data->outwardGst, 2) }}</td></tr>
                    <tr class="bg-rose-50"><td class="px-5 py-3 text-gray-600">Less: GST reversed via credit notes</td><td class="px-5 py-3 text-right text-rose-600">−₹{{ number_format($data->cnGst, 2) }}</td></tr>
                    <tr><td class="px-5 py-3 text-gray-600">Less: ITC (input tax credit)</td><td class="px-5 py-3 text-right text-gray-500">−₹{{ number_format($data->itc, 2) }} <span class="text-xs">(not tracked in JewelFlow)</span></td></tr>
                    <tr class="bg-emerald-50 border-t-2 border-emerald-200"><td class="px-5 py-4 font-bold text-emerald-900">Net GST payable</td><td class="px-5 py-4 text-right font-bold text-lg text-emerald-700">₹{{ number_format($data->netGst, 2) }}</td></tr>
                </tbody>
            </table>
        </div>
        <p class="text-xs text-gray-500 mt-3 max-w-3xl">ITC is not tracked inside JewelFlow — apply your purchase-side input credit in your filing software. Net payable above is output tax less credit-note reversals only.</p>
    </div>
</x-app-layout>
