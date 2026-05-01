<x-app-layout>
    <x-page-header class="ops-treatment-header">
        <div>
            <h1 class="page-title">Tag / Label Printing</h1>
            <p class="text-sm text-gray-600 mt-1">Select items to generate printable price tags</p>
        </div>
    </x-page-header>

    <div class="content-inner ops-treatment-page">

        {{-- ── Filter bar ─────────────────────────────────────────────────── --}}
        <div class="border border-slate-200 bg-white p-4 shadow-sm mb-6 rounded-xl overflow-hidden ui-filter-enhanced-wrap">
            <form method="GET" action="{{ route('tags.index') }}" class="flex flex-wrap gap-3 items-end" data-enhance-selects="true" data-enhance-selects-variant="standard">
                <div class="flex-1 min-w-[220px]">
                    <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Search</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-3 inline-flex items-center text-amber-500">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        </span>
                        <input type="text" name="search" value="{{ request('search') }}" placeholder="Barcode, design, category, HUID…" class="w-full rounded-xl border-2 border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-700 placeholder-slate-400 shadow-sm transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Category</label>
                    <select name="category" class="border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700 shadow-sm rounded-md focus:border-amber-500 focus:ring-amber-500">
                        <option value="">All</option>
                        @foreach($categories as $cat)
                            <option value="{{ $cat }}" {{ request('category') === $cat ? 'selected' : '' }}>{{ $cat }}</option>
                        @endforeach
                    </select>
                </div>
                @if(request()->hasAny(['search', 'category']))
                    <a href="{{ route('tags.index') }}" class="inline-flex items-center gap-2 border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 rounded-lg">
                        Clear
                    </a>
                @else
                    <button type="submit" class="inline-flex items-center gap-2 bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 rounded-lg">
                        Filter
                    </button>
                @endif
            </form>
        </div>

        {{-- ── Print form ──────────────────────────────────────────────────── --}}
        {{--
            Root-cause architecture:
            • `selected` (Alpine array) is the single source of truth for which item IDs are chosen.
            • It is persisted to localStorage under `storeKey` (scoped to shop + current filter).
              Changing the filter changes the key, so a new filter starts with a clean slate.
            • The form submits hidden <input name="item_ids[]"> elements rendered by Alpine x-for —
              NOT the visual checkboxes.  This means items selected on page 1 are still submitted
              even when the user has navigated to page 2.
            • "Select All" loads the full list of matching IDs provided by the server ($allMatchingIds)
              so it spans every page of results, not just the visible one.
        --}}
        <form
            method="POST"
            action="{{ route('tags.print') }}"
            id="print-form"
            data-enhance-selects="true"
            data-enhance-selects-variant="compact"
            target="_blank"
            x-data="tagPrint({
                storeKey:        '{{ $storeKey }}',
                allMatchingIds:  {{ Js::from($allMatchingIds) }},
                labelSize:       'medium',
                includeBarcodeImage: true,
                printFormat:     'standard',
                foldedSize:      '95x12'
            })"
            @submit="injectHiddenInputs"
        >
            @csrf

            {{-- Hidden inputs for label options --}}
            <input type="hidden" name="label_size"            :value="labelSize">
            <input type="hidden" name="include_barcode_image" :value="includeBarcodeImage ? 1 : 0">
            <input type="hidden" name="print_format"          :value="printFormat">
            <input type="hidden" name="folded_size"           :value="printFormat === 'folded' ? foldedSize : ''">

            {{-- ── Toolbar ──────────────────────────────────────────────── --}}
            <div class="flex flex-wrap items-center gap-3 mb-4">
                <div class="flex items-center gap-2">
                    <label class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Print Method</label>
                    <div class="inline-flex items-center rounded-lg border border-slate-300 bg-slate-200/70 p-1">
                        <button type="button"
                                @click="printFormat = 'standard'"
                                :aria-pressed="printFormat === 'standard'"
                                class="rounded-md px-3 py-1.5 text-sm font-semibold transition"
                                :class="printFormat === 'standard'
                                    ? 'bg-slate-900 text-white shadow-[0_6px_16px_rgba(15,23,42,0.24)]'
                                    : 'text-slate-700 hover:text-slate-900 hover:bg-slate-100/80'">
                            Standard
                        </button>
                        <button type="button"
                                @click="printFormat = 'folded'"
                                :aria-pressed="printFormat === 'folded'"
                                class="rounded-md px-3 py-1.5 text-sm font-semibold transition"
                                :class="printFormat === 'folded'
                                    ? 'bg-slate-900 text-white shadow-[0_6px_16px_rgba(15,23,42,0.24)]'
                                    : 'text-slate-700 hover:text-slate-900 hover:bg-slate-100/80'">
                            Folded
                        </button>
                    </div>
                </div>

                <div class="flex items-center gap-2" x-show="printFormat === 'standard'">
                    <label class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Label Size</label>
                    <select x-model="labelSize" class="border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700 shadow-sm rounded-md focus:border-amber-500 focus:ring-amber-500">
                        <option value="small">Small (25×50mm)</option>
                        <option value="medium">Medium (40×70mm)</option>
                        <option value="large">Large (50×90mm)</option>
                    </select>
                </div>

                <div class="flex items-center gap-2" x-show="printFormat === 'folded'">
                    <label class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Folded Size</label>
                    <select x-model="foldedSize" class="border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700 shadow-sm rounded-md focus:border-amber-500 focus:ring-amber-500">
                        <option value="95x12">95x12 mm</option>
                        <option value="95x15">95x15 mm</option>
                    </select>
                </div>

                <label class="inline-flex items-center gap-2 text-sm text-slate-700 px-3 py-1.5 bg-white border border-slate-200 shadow-sm cursor-pointer rounded-lg">
                    <input type="checkbox" x-model="includeBarcodeImage" class="rounded border-slate-300 text-amber-600 focus:ring-amber-500">
                    <span>Print scannable barcode image</span>
                </label>

                <button type="submit"
                    class="inline-flex items-center gap-2 bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:opacity-50 disabled:cursor-not-allowed rounded-lg"
                    :disabled="selected.length === 0">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    Print
                    <span x-show="selected.length > 0" x-text="selected.length + ' Label' + (selected.length === 1 ? '' : 's')"></span>
                </button>

                {{-- Select All — uses ALL matching IDs from server, not just current page --}}
                <button type="button" @click="selectAll()"
                    class="inline-flex items-center gap-2 border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 rounded-lg">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                    Select All
                    <span class="text-xs text-slate-400">({{ count($allMatchingIds) }})</span>
                </button>

                <button type="button" @click="clearAll()"
                    class="inline-flex items-center gap-2 border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 rounded-lg">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="9" y1="9" x2="15" y2="15"/><line x1="15" y1="9" x2="9" y2="15"/></svg>
                    Clear
                </button>

                {{-- Cross-page selection indicator --}}
                <span x-show="selected.length > 0 && selected.length < allMatchingIds.length"
                      class="text-xs text-amber-600 font-medium">
                    <span x-text="selected.length"></span> of {{ count($allMatchingIds) }} selected — selection preserved across pages
                </span>
                <span x-show="selected.length === allMatchingIds.length && allMatchingIds.length > 0"
                      class="text-xs text-amber-600 font-medium">
                    All {{ count($allMatchingIds) }} items selected
                </span>
            </div>

            {{-- ── Item table ───────────────────────────────────────────── --}}
            <div class="border border-slate-200 bg-white shadow-sm overflow-hidden rounded-xl">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr>
                                <th class="px-4 py-3 w-10"></th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Barcode</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Design</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Category</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Weight (g)</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Purity</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">HUID</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">MRP (₹)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($items as $item)
                            <tr class="hover:bg-slate-50/70 cursor-pointer"
                                :class="isSelected('{{ $item->id }}') ? 'bg-amber-50/40' : ''"
                                @click="toggle('{{ $item->id }}')">
                                <td class="px-4 py-3 text-center" @click.stop="toggle('{{ $item->id }}')">
                                    {{-- Visual-only checkbox — form submission uses hidden inputs, not this --}}
                                    <input type="checkbox"
                                           class="rounded border-slate-300 text-amber-600 focus:ring-amber-500 pointer-events-none"
                                           :checked="isSelected('{{ $item->id }}')"
                                           tabindex="-1"
                                           readonly>
                                </td>
                                <td class="px-4 py-3 text-sm font-mono text-slate-700">{{ $item->barcode }}</td>
                                <td class="px-4 py-3 text-sm text-slate-700">{{ $item->design ?? '—' }}</td>
                                <td class="px-4 py-3 text-sm text-slate-700">{{ $item->category }}{{ $item->sub_category ? ' · ' . $item->sub_category : '' }}</td>
                                <td class="px-4 py-3 text-sm text-right text-slate-700">{{ number_format($item->gross_weight, 3) }}</td>
                                <td class="px-4 py-3 text-sm text-slate-700">{{ $item->purity }}%</td>
                                <td class="px-4 py-3 text-sm font-mono text-slate-700">{{ $item->huid ?? '—' }}</td>
                                <td class="px-4 py-3 text-sm text-right font-medium text-slate-800">₹{{ number_format($item->selling_price, 2) }}</td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="8" class="px-6 py-12 text-center text-slate-500">No items in stock.</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($items->hasPages())
                    {{-- withQueryString() already appended search+category to pagination links --}}
                    <div class="px-6 py-4 border-t border-slate-200">{{ $items->links() }}</div>
                @endif
            </div>
        </form>
    </div>

    <script>
    /**
     * tagPrint Alpine component
     *
     * State is persisted to localStorage under `storeKey`.
     * The key is scoped to the current shop + filter combination,
     * so changing the search/category filter starts a fresh selection.
     *
     * Form submission injects hidden inputs for every selected ID
     * so items from other pages are included even if their checkboxes
     * are not currently in the DOM.
     */
    function tagPrint({ storeKey, allMatchingIds, labelSize, includeBarcodeImage, printFormat, foldedSize }) {
        return {
            storeKey,
            allMatchingIds,
            labelSize,
            includeBarcodeImage,
            printFormat,
            foldedSize,

            // Restore persisted selection; filter out any IDs that are
            // no longer in the current result set (e.g. item sold since).
            selected: (() => {
                try {
                    const saved = JSON.parse(localStorage.getItem(storeKey) || '[]');
                    const valid = new Set(allMatchingIds);
                    return saved.filter(id => valid.has(id));
                } catch (_) {
                    return [];
                }
            })(),

            init() {
                // Persist selection on every change.
                this.$watch('selected', val => {
                    try { localStorage.setItem(this.storeKey, JSON.stringify(val)); } catch (_) {}
                });
            },

            isSelected(id) {
                return this.selected.includes(id);
            },

            toggle(id) {
                if (this.selected.includes(id)) {
                    this.selected = this.selected.filter(i => i !== id);
                } else {
                    this.selected.push(id);
                }
            },

            selectAll() {
                this.selected = [...this.allMatchingIds];
            },

            clearAll() {
                this.selected = [];
                try { localStorage.removeItem(this.storeKey); } catch (_) {}
            },

            /**
             * Before the form submits, inject one hidden input per selected ID.
             * This is the only thing the server reads — the visual checkboxes
             * carry no `name` attribute so they are never submitted.
             */
            injectHiddenInputs(event) {
                if (this.selected.length === 0) {
                    event.preventDefault();
                    return;
                }
                const form = event.target;
                // Remove any previously injected inputs (e.g. double-click).
                form.querySelectorAll('.js-sel-input').forEach(el => el.remove());
                this.selected.forEach(id => {
                    const inp = document.createElement('input');
                    inp.type      = 'hidden';
                    inp.name      = 'item_ids[]';
                    inp.value     = id;
                    inp.className = 'js-sel-input';
                    form.appendChild(inp);
                });
            },
        };
    }
    </script>
</x-app-layout>
