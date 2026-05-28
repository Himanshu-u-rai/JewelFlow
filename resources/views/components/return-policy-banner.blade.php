@if(auth()->check() && auth()->user()->shop?->preferences?->hasConfiguredReturnPolicy() === false)
<div class="mb-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 flex items-start gap-3 text-sm text-amber-800">
    <svg class="w-5 h-5 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <div>
        <span class="font-semibold">Return policy not configured.</span>
        By default the system refunds 100% of every return — including making charges, GST, and hallmark.
        @can('settings.edit')
            <a href="{{ route('settings.edit', ['tab' => 'return-policy']) }}" class="ml-1 underline font-semibold">Configure your return policy →</a>
        @endcan
    </div>
</div>
@endif
