<x-app-layout>
    <x-page-header :title="'Edit Karigar — ' . $karigar->name" />

    <div class="content-inner">
        <x-app-alerts class="mb-4" />

        <form method="POST" action="{{ route('karigars.update', $karigar) }}" class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 max-w-3xl">
            @csrf @method('PUT')
            @include('karigars._form')

            <div class="mt-5 flex items-center gap-3">
                <button type="submit" class="btn btn-success btn-sm">Update</button>
                <a href="{{ route('karigars.show', $karigar) }}" class="text-sm text-gray-500">Cancel</a>
            </div>
        </form>
    </div>
</x-app-layout>
