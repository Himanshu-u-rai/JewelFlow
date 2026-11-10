<x-app-layout>
    <x-page-header title="Add Karigar Invoice" class="ki-page-header">
        <x-slot:actions><a href="{{ route('karigar-invoices.index') }}" class="btn btn-secondary btn-sm ki-header-action" aria-label="Invoice Register"><x-icon name="list" size="16" /><span class="ki-header-action-label">Invoice Register</span></a></x-slot:actions>
    </x-page-header>

    <div class="content-inner karigar-invoice-form-page">
        <x-app-alerts />

        <form method="POST" action="{{ route('karigar-invoices.store') }}" enctype="multipart/form-data" class="ki-form-shell">
            @csrf
            @include('karigar-invoices._form')

            <div class="ki-form-actions">
                <button type="submit" class="btn btn-primary btn-sm">Save Invoice</button>
                <a href="{{ route('karigar-invoices.index') }}" class="btn btn-secondary btn-sm">Cancel</a>
            </div>
        </form>
    </div>
</x-app-layout>
