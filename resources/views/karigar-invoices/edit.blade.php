<x-app-layout>
    <x-page-header :title="'Edit Karigar Invoice — ' . $invoice->karigar_invoice_number" />

    <div class="content-inner karigar-invoice-form-page">

        @php
            $jobOrder = $invoice->jobOrder;
            $receipt = null;
        @endphp

        <form method="POST" action="{{ route('karigar-invoices.update', $invoice) }}" enctype="multipart/form-data" class="ki-form-shell">
            @csrf @method('PUT')
            @include('karigar-invoices._form')

            <div class="ki-form-actions">
                <button type="submit" class="ki-submit">Update Invoice</button>
                <a href="{{ route('karigar-invoices.show', $invoice) }}" class="ki-cancel">Cancel</a>
            </div>
        </form>
    </div>
</x-app-layout>
