@props(['id', 'label'])

<div class="jf-filter-shell" x-data="{}">
    <button type="button" class="btn btn-secondary btn-sm jf-filter-toggle" x-cloak
            data-filter-toggle aria-label="Open {{ $label }}" aria-haspopup="dialog" aria-controls="{{ $id }}"
            @click="$refs.filters.close(); $refs.filters.showModal()">
        <x-icon name="list-filter" size="16" />
        Filters
    </button>
    <dialog open id="{{ $id }}" x-ref="filters" class="jf-filter-panel" aria-label="{{ $label }}"
            x-init="$el.dataset.ready = 'true'; $el.open = window.innerWidth > 768"
            @resize.window="if (window.innerWidth > 768) { if ($el.matches(':modal')) $el.close(); $el.open = true; } else if (!$el.matches(':modal')) $el.close()"
            @turbo:before-cache.document="$el.close()" @turbo:before-render.document="$el.close()"
            @click="if ($event.target === $el && $event.clientY < $el.getBoundingClientRect().top) $el.close()">
        <div class="jf-filter-panel-head">
            <h3>Filters</h3>
            <button type="button" class="btn btn-secondary btn-sm" aria-label="Close filters" @click="$refs.filters.close()">
                <x-icon name="x" size="18" />
            </button>
        </div>
        {{ $slot }}
    </dialog>
</div>
