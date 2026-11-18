<x-app-layout>
    <x-page-header class="report-export-header" :title="$definition->title . ' Export'" subtitle="Choose period, profile, and file format">
        <x-slot:actions>
            <a href="{{ $screenRoute }}" class="report-export-header-action jf-header-action--neutral" aria-label="Back to report"><x-icon name="chevron-left" size="16" /><span>Back to report</span></a>
        </x-slot:actions>
    </x-page-header>
    @include('reporting.partials.export-form')
</x-app-layout>
