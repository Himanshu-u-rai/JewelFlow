<x-app-layout>
    <x-page-header title="Add Karigar" subtitle="A job-work artisan linked to this shop" />

    <div class="content-inner">
        <x-app-alerts class="mb-4" />

        <form method="POST" action="{{ route('karigars.store') }}" class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 max-w-3xl">
            @csrf
            @include('karigars._form')

            <div class="mt-5 flex items-center gap-3">
                <button type="submit" class="btn btn-success btn-sm">Save Karigar</button>
                <a href="{{ route('karigars.index') }}" class="text-sm text-gray-500">Cancel</a>
            </div>
        </form>
    </div>
</x-app-layout>
