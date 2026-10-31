{{-- The way back for the pages that open from the Stock page's navigation row: Categories,
     Tag Printing, Reorder Alerts. No permission check: whoever may open one of them may open
     the Stock page (the same permission, and no edition gate). In a phone header the label
     shortens to "Back"; the link keeps its full name for a screen reader. --}}
<a href="{{ route('inventory.items.index') }}" class="btn btn-secondary btn-sm stock-back-btn" aria-label="{{ __('Back to Stock') }}">
    <x-icon name="chevron-left" size="14" />
    <span class="stock-back-label-full">{{ __('Back to Stock') }}</span>
    <span class="stock-back-label-short">{{ __('Back') }}</span>
</a>
