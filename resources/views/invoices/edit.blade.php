<x-app-layout>
    <x-page-header class="invoices-edit-header">
        <div>
            <h1 class="page-title">Edit Invoice {{ $invoice->invoice_number }}</h1>
            <p class="text-sm text-gray-500 mt-1">Finalize or cancel this invoice</p>
        </div>
        <div class="page-actions flex flex-wrap items-center gap-2">
            <a href="{{ route('invoices.show', $invoice) }}" class="btn btn-secondary btn-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>Back to Invoice</a>
        </div>
    </x-page-header>

    <div class="content-inner invoices-edit-page">
        <div class="max-w-2xl space-y-6">

            @error('invoice')
                <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg p-4">{{ $message }}</div>
            @enderror

            @if($invoice->status === \App\Models\Invoice::STATUS_DRAFT)

                {{-- Finalize --}}
                <div class="bg-white shadow-sm border border-gray-200 rounded-lg invoices-edit-card">
                    <div class="p-4 border-b border-gray-200">
                        <h2 class="text-base font-semibold text-gray-900">Finalize Invoice</h2>
                        <p class="text-sm text-gray-500 mt-1">Lock this draft and record it in the ledger. This cannot be undone — use Cancel to reverse a finalized invoice.</p>
                    </div>
                    <form method="POST" action="{{ route('invoices.update', $invoice) }}" class="p-4" data-confirm-message="Cancel this invoice?">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="action" value="finalize">
                        <div class="mb-4">
                            <label for="gst_rate" class="block text-sm font-medium text-gray-700 mb-1">GST Rate (%) <span class="text-gray-400 text-xs">(optional override)</span></label>
                            <input type="number" step="0.01" min="0" max="100" name="gst_rate" id="gst_rate"
                                   value="{{ old('gst_rate', $invoice->gst_rate) }}"
                                   placeholder="Leave blank to use default"
                                   class="w-48 rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                            @error('gst_rate')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <button type="submit" class="btn btn-dark btn-sm">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><polyline points="20 6 9 17 4 12"/></svg>
                            Finalize Invoice
                        </button>
                    </form>
                </div>

                {{-- Cancel Draft --}}
                <div class="bg-white shadow-sm border border-gray-200 rounded-lg invoices-edit-card">
                    <div class="p-4 border-b border-gray-200">
                        <h2 class="text-base font-semibold text-gray-900">Cancel Draft</h2>
                        <p class="text-sm text-gray-500 mt-1">Void this draft invoice without creating a reversal.</p>
                    </div>
                    <form method="POST" action="{{ route('invoices.update', $invoice) }}" class="p-4">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="action" value="cancel">
                        <div class="mb-4">
                            <label for="cancellation_reason" class="block text-sm font-medium text-gray-700 mb-1">Reason <span class="text-gray-400 text-xs">(optional)</span></label>
                            <textarea name="cancellation_reason" id="cancellation_reason" rows="2"
                                      class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500"
                                      placeholder="Why is this being cancelled?">{{ old('cancellation_reason') }}</textarea>
                            @error('cancellation_reason')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <button type="submit" class="btn btn-secondary btn-sm">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            Cancel Draft
                        </button>
                    </form>
                </div>

            @elseif($invoice->status === \App\Models\Invoice::STATUS_FINALIZED)

                {{-- Cancel via Reversal --}}
                <div class="bg-white shadow-sm border border-gray-200 rounded-lg invoices-edit-card">
                    <div class="p-4 border-b border-gray-200">
                        <h2 class="text-base font-semibold text-gray-900">Cancel Invoice via Reversal</h2>
                        <p class="text-sm text-gray-500 mt-1">A reversal invoice will be created to offset this one. Both invoices remain in the ledger for audit purposes.</p>
                    </div>
                    <form method="POST" action="{{ route('invoices.update', $invoice) }}" class="p-4" data-confirm-message="This will create a reversal invoice. Continue?">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="action" value="cancel">
                        <div class="mb-4">
                            <label for="cancellation_reason" class="block text-sm font-medium text-gray-700 mb-1">Reason <span class="text-red-500">*</span></label>
                            <textarea name="cancellation_reason" id="cancellation_reason" rows="2" required
                                      class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500"
                                      placeholder="Required — state the reason for cancellation">{{ old('cancellation_reason') }}</textarea>
                            @error('cancellation_reason')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <button type="submit" class="btn btn-secondary btn-sm">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.52"/></svg>
                            Cancel via Reversal
                        </button>
                    </form>
                </div>

            @else

                {{-- Cancelled — no actions --}}
                <div class="bg-white shadow-sm border border-gray-200 rounded-lg p-6 text-center invoices-edit-card">
                    <svg class="w-10 h-10 mx-auto text-gray-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                    </svg>
                    <p class="text-gray-700 font-medium">This invoice has been cancelled.</p>
                    <p class="text-sm text-gray-500 mt-1">No further actions are available.</p>
                    <a href="{{ route('invoices.show', $invoice) }}" class="btn btn-secondary btn-sm mt-4 inline-flex">View Invoice</a>
                </div>

            @endif
        </div>
    </div>
</x-app-layout>
