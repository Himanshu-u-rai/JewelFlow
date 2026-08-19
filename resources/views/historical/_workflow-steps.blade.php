{{-- Shared progress indicator for the file-import lifecycle. $currentStep is
     one of: upload, map, review, publish. Text-labelled, not colour-only. --}}
@php
    $steps = ['upload' => 'Upload', 'map' => 'Map', 'review' => 'Review', 'publish' => 'Publish'];
    $order = array_keys($steps);
    $currentIndex = array_search($currentStep, $order, true) ?: 0;
@endphp
<ol class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm mb-4" aria-label="Import progress">
    @foreach($steps as $key => $label)
        @php $isDone = array_search($key, $order, true) < $currentIndex; $isCurrent = $key === $currentStep; @endphp
        <li class="flex items-center gap-2">
            <span class="inline-flex items-center px-2.5 py-1 rounded-full font-medium
                {{ $isCurrent ? 'bg-teal-700 text-white' : ($isDone ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-500') }}"
                @if($isCurrent) aria-current="step" @endif>
                {{ $loop->iteration }}. {{ $label }}{{ $isDone ? ' ✓' : '' }}
            </span>
            @if(!$loop->last)<span class="text-slate-300" aria-hidden="true">→</span>@endif
        </li>
    @endforeach
</ol>
