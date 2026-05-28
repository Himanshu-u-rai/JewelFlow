<x-app-layout>
    <x-page-header title="Record Repair Return" :subtitle="'Job ' . $jobOrder->job_order_number . ' · ' . $jobOrder->karigar?->name" />

    <div class="max-w-xl mx-auto px-4 pb-16 pt-6">

        @if(session('error'))
        <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-5 py-3 text-sm text-red-800">{{ session('error') }}</div>
        @endif

        {{-- Source item summary --}}
        @if($jobOrder->sourceItem)
        <div class="mb-4 rounded-xl border border-slate-200 bg-slate-50 px-5 py-4">
            <p class="text-xs font-semibold uppercase text-slate-500 mb-1">Item Returning</p>
            <p class="text-sm font-medium text-slate-800">{{ $jobOrder->sourceItem->barcode }}</p>
            <p class="text-xs text-slate-500 mt-0.5">
                {{ $jobOrder->sourceItem->design ?? '—' }}
                · {{ number_format((float) $jobOrder->sourceItem->gross_weight, 3) }}g
                · {{ $jobOrder->sourceItem->purity_label ?? $jobOrder->purity . 'k' }}
            </p>
        </div>
        @endif

        <form method="POST" action="{{ route('job-orders.repair.receipt', $jobOrder) }}">
            @csrf
            <div class="rounded-xl border border-slate-200 bg-white overflow-hidden mb-4">
                <div class="px-5 py-4 border-b border-slate-100 bg-slate-50">
                    <h2 class="text-sm font-semibold text-slate-700">Receipt Details</h2>
                </div>
                <div class="px-5 py-4 grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Receipt Date <span class="text-red-500">*</span></label>
                        <input type="date" name="receipt_date" value="{{ old('receipt_date', now()->toDateString()) }}"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500">
                        @error('receipt_date')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs font-medium text-slate-600 mb-1">Notes</label>
                        <textarea name="notes" rows="2"
                                  class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500">{{ old('notes') }}</textarea>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-blue-100 bg-blue-50 px-5 py-3 mb-6 text-xs text-blue-800">
                Confirming this receipt will mark <strong>{{ $jobOrder->sourceItem?->barcode }}</strong> as back in stock.
                No vault or metal changes are recorded for a repair job.
            </div>

            <div class="flex gap-3">
                <button type="submit" class="px-5 py-2 rounded-lg bg-emerald-600 text-white text-sm font-medium hover:bg-emerald-700">
                    Confirm Item Returned
                </button>
                <a href="{{ route('job-orders.show', $jobOrder) }}" class="px-5 py-2 rounded-lg border border-slate-300 text-slate-600 text-sm font-medium hover:bg-slate-50">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</x-app-layout>
