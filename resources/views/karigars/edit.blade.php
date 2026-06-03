<x-app-layout>
    <x-page-header :title="'Edit Karigar — ' . $karigar->name" />

    <div class="content-inner kf-shell">
        <form method="POST" action="{{ route('karigars.update', $karigar) }}" class="kf-card kf-form">
            @csrf @method('PUT')
            @include('karigars._form')

            <div class="kf-actions">
                <button type="submit" class="kf-submit">Update Karigar</button>
                <a href="{{ route('karigars.show', $karigar) }}" class="kf-cancel">Cancel</a>
            </div>
        </form>
    </div>
</x-app-layout>
