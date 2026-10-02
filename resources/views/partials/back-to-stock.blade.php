{{-- The way back for the pages that open from the Stock page's navigation row: Categories,
     Tag Printing, Reorder Alerts. No permission check: whoever may open one of them may open
     the Stock page (the same permission, and no edition gate). In a phone header the label
     shortens to "Back"; the link keeps its full name for a screen reader. --}}
<a href="{{ route('inventory.items.index') }}" class="btn btn-secondary btn-sm stock-back-btn" aria-label="{{ __('Back to Stock') }}">
    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 19l-7-7 7-7"/></svg>
    <span class="stock-back-label-full">{{ __('Back to Stock') }}</span>
    <span class="stock-back-label-short">{{ __('Back') }}</span>
</a>
