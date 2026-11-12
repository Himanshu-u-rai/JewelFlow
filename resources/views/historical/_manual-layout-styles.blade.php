{{-- Page-scoped layout for the manual historical entry form (audit F12).
     Selectors hang off [data-historical-manual-layout] so nothing leaks to
     other screens. The scroll wrapper deliberately uses its own class (not the
     Tailwind overflow-x-auto utility) because the item grid card keeps its
     overflow-hidden rounded frame. --}}
<style>
    [data-historical-manual-layout] .historical-line-scroll { max-width: 100%; overflow-x: auto; overscroll-behavior-x: contain; -webkit-overflow-scrolling: touch; }
    [data-historical-manual-layout] .historical-line-scroll > table[data-historical-item-table] { min-width: 68rem; }
    [data-historical-manual-layout] .historical-line-select { min-width: 7rem; }
    [data-historical-manual-layout] .historical-line-total { min-width: 7rem; }

    /* Amount / payment: let the 13 amount fields wrap by available width instead of a fixed 3 narrow columns. */
    [data-historical-manual-layout] .historical-amount-grid { grid-template-columns: repeat(auto-fill, minmax(9.5rem, 1fr)); }
    @media (min-width: 1024px) {
        [data-historical-manual-layout] [data-historical-financial-row] { grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr) minmax(0, 1fr); }
    }
    @media (max-width: 1023px) {
        [data-historical-manual-layout] [data-historical-financial-row] { grid-template-columns: minmax(0, 1fr); }
    }
    [data-historical-manual-layout] fieldset { min-width: 0; }
    [data-historical-manual-layout] input[type="search"].jf-control { border-radius: 0.5rem; }
</style>
