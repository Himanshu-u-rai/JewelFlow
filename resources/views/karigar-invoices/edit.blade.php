<x-app-layout>
    <x-page-header :title="'Edit Karigar Invoice — ' . $invoice->karigar_invoice_number" />

    <div class="content-inner">
        <x-app-alerts class="mb-4" />

        @php
            $jobOrder = $invoice->jobOrder;
            $receipt = null;
        @endphp

        <form method="POST" action="{{ route('karigar-invoices.update', $invoice) }}" enctype="multipart/form-data" class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
            @csrf @method('PUT')
            @include('karigar-invoices._form')

            <div class="flex items-center gap-3 mt-4">
                <button type="submit" class="btn btn-success btn-sm">Update</button>
                <a href="{{ route('karigar-invoices.show', $invoice) }}" class="text-sm text-gray-500">Cancel</a>
            </div>
        </form>
    </div>
</x-app-layout>
