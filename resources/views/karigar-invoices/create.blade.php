<x-app-layout>
    <x-page-header title="Add Karigar Invoice" />

    <div class="content-inner">
        <x-app-alerts class="mb-4" />

        <form method="POST" action="{{ route('karigar-invoices.store') }}" enctype="multipart/form-data" class="ki-form-shell">
            @csrf
            @include('karigar-invoices._form')

            <div class="ki-form-actions">
                <button type="submit" class="ki-submit">Save Invoice</button>
                <a href="{{ route('karigar-invoices.index') }}" class="ki-cancel">Cancel</a>
            </div>
        </form>
    </div>
</x-app-layout>
