{{-- Shared progress indicator for the file-import lifecycle. $currentStep is
     one of: upload, map, review, publish. Text-labelled, not colour-only. --}}
@php
    $steps = ['upload' => 'Upload', 'map' => 'Map', 'review' => 'Review', 'publish' => 'Publish'];
    $order = array_keys($steps);
    $currentIndex = array_search($currentStep, $order, true) ?: 0;
@endphp
<ol class="grid grid-cols-2 gap-2 rounded-xl border border-slate-200 bg-white p-2 sm:grid-cols-4" aria-label="Import progress" data-historical-workflow>
    @foreach($steps as $key => $label)
        @php $isDone = array_search($key, $order, true) < $currentIndex; $isCurrent = $key === $currentStep; @endphp
        <li class="min-w-0">
            <span class="flex min-h-[44px] items-center gap-2 rounded-lg px-3 py-2
                {{ $isCurrent ? 'bg-teal-700 text-white' : ($isDone ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-50 text-slate-500') }}"
                @if($isCurrent) aria-current="step" @endif>
                <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-slate-200 bg-white text-xs font-semibold text-slate-700" aria-hidden="true">{{ $isDone ? '✓' : $loop->iteration }}</span>
                <span class="text-sm font-semibold">{{ $label }}</span>
            </span>
        </li>
    @endforeach
</ol>
