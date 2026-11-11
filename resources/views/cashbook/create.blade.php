<x-app-layout>
    <div class="cb-shell">
        <x-page-header class="cb-page-header" title="Add entry" subtitle="Record money received or paid">
            <x-slot:actions><a href="{{ route('cashbook.index') }}" class="btn btn-secondary btn-sm"><x-icon name="arrow-left" size="16" />Cash Book</a></x-slot:actions>
        </x-page-header>
        <div class="content-inner cb-page">
            <section class="cb-entry-standalone" aria-labelledby="cb-entry-details-title">
                <header><h2 id="cb-entry-details-title">Entry details</h2><p>Record a manual money movement. Cash affects the drawer; other modes are tracked separately.</p></header>
                @include('cashbook._entry-form', ['entryModal' => false])
            </section>
        </div>
    </div>
</x-app-layout>
