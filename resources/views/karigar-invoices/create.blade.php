<x-app-layout>
    <x-page-header title="Add Karigar Invoice" subtitle="Capture a tax invoice received from a karigar" />

    <div class="content-inner">
        <x-app-alerts class="mb-4" />

        <form method="POST" action="{{ route('karigar-invoices.store') }}" enctype="multipart/form-data" class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
            @csrf
            @include('karigar-invoices._form')

            <div class="flex items-center gap-3 mt-4">
                <button type="submit" class="btn btn-success btn-sm">Save Invoice</button>
                <a href="{{ route('karigar-invoices.index') }}" class="text-sm text-gray-500">Cancel</a>
            </div>
        </form>
    </div>
</x-app-layout>
