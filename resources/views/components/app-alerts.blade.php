@props([
    'floating' => false,
    'showSuccess' => true,
    'showError' => true,
    'showValidation' => true,
    'messageOnly' => false,
    'class' => '',
])

@php
    // NOT method_exists(): Laravel shares `$errors` as a ViewErrorBag, which
    // declares neither all() nor first() — both are forwarded to the default
    // MessageBag through __call(). method_exists() cannot see magic methods, so
    // the old guard was false for EVERY real validation failure and swapped in
    // an empty bag, silently blanking the errors on every screen using this
    // component. Normalize by type instead, and unwrap to the 'default' bag so
    // first('message') keeps its existing single-bag meaning.
    // Covered by tests/Feature/View/AppAlertsComponentTest.php.
    $errorBag = match (true) {
        ($errors ?? null) instanceof \Illuminate\Support\ViewErrorBag => $errors->getBag('default'),
        ($errors ?? null) instanceof \Illuminate\Contracts\Support\MessageBag => $errors,
        default => new \Illuminate\Support\MessageBag(),
    };

    $topMessage = $errorBag->first('message') ?: session('error');

    $validationMessages = collect($errorBag->all())
        ->filter(function ($message) use ($topMessage) {
            return $message !== $topMessage;
        })
        ->values();
@endphp

@if($floating)
    @if($topMessage)
        <div x-data="{ show: true }"
             x-show="show"
             x-init="setTimeout(() => show = false, 8000)"
             class="app-alert-fixed"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 -translate-y-full"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-300"
             x-transition:leave-start="opacity-100 translate-y-0"
             x-transition:leave-end="opacity-0 -translate-y-full">
            <div class="app-alert app-alert-error app-alert-fixed-inner">
                <span class="app-alert-message">{{ $topMessage }}</span>
                <button type="button" @click="show = false" class="app-alert-close" aria-label="Close alert">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
        </div>
    @endif
@else
    @php
        // $topMessage, not session('error'): the floating branch already renders
        // first('message') this way, but the inline branch only ever looked at
        // the session. A `message`-keyed validation error was therefore filtered
        // OUT of the list below (as a duplicate of $topMessage) and then never
        // rendered at all — swallowed. Same variable, both branches.
        $hasAny = ($showSuccess && session('success'))
            || ($showError && $topMessage)
            || ($showValidation && $validationMessages->isNotEmpty());
    @endphp

    @if($hasAny)
        <div {{ $attributes->merge(['class' => trim('app-alert-stack ' . $class)]) }}>
            @if($showSuccess && session('success'))
                <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)"
                     x-transition:leave="transition ease-in duration-300"
                     x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                     class="app-alert app-alert-success">
                    <span class="app-alert-message">{{ session('success') }}</span>
                    <button type="button" @click="show = false" class="app-alert-close" aria-label="Close alert">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
            @endif

            @if($showError && $topMessage)
                <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 6000)"
                     x-transition:leave="transition ease-in duration-300"
                     x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                     class="app-alert app-alert-error">
                    <span class="app-alert-message">{{ $topMessage }}</span>
                    <button type="button" @click="show = false" class="app-alert-close" aria-label="Close alert">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
            @endif

            @if(!$messageOnly && $showValidation && $validationMessages->isNotEmpty())
                <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 8000)"
                     x-transition:leave="transition ease-in duration-300"
                     x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                     class="app-alert app-alert-error">
                    <div class="app-alert-title">Please fix the following:</div>
                    <ul class="app-alert-list">
                        @foreach($validationMessages as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                    <button type="button" @click="show = false" class="app-alert-close" aria-label="Close alert">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
            @endif
        </div>
    @endif
@endif
