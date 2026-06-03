<x-app-layout>
    <x-page-header title="Add Karigar" subtitle="A job-work artisan linked to this shop" />

    <div class="content-inner kf-shell">
        <form method="POST" action="{{ route('karigars.store') }}" class="kf-card kf-form">
            @csrf
            @include('karigars._form')

            <div class="kf-actions">
                <button type="submit" class="kf-submit">Save Karigar</button>
                <a href="{{ route('karigars.index') }}" class="kf-cancel">Cancel</a>
            </div>
        </form>
    </div>
</x-app-layout>
