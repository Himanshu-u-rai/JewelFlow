{{-- Table footer for every Dhiran list (owner 2026-11-08): always shows how many rows there are, a rows-per-page
     choice and page controls, even when everything fits on one page. Phones get "Page x of y" with big Prev/Next. --}}
@props(['paginator', 'noun' => 'rows', 'sizes' => \App\Services\Dhiran\DhiranListFilters::PER_PAGE])
@php
    $p = $paginator;
    $cur = $p->currentPage();
    $last = $p->lastPage();
    $pages = collect([1, $last])->merge(range(max(1, $cur - 2), min($last, $cur + 2)))->unique()->sort()->values();
    $keep = collect(request()->query())->except(['page', 'per_page'])->filter(fn ($v) => is_scalar($v) && $v !== '');
@endphp
@if($p->total() > 0)
    <nav class="dh-pager" data-dhiran-pager aria-label="Table pages">
        <p class="dh-pager-count">Showing <strong>{{ $p->firstItem() }}–{{ $p->lastItem() }}</strong> of <strong>{{ number_format($p->total()) }}</strong> {{ $noun }}</p>

        @if($sizes)
            <form method="GET" class="dh-pager-size">
                @foreach($keep as $k => $v)
                    <input type="hidden" name="{{ $k }}" value="{{ $v }}">
                @endforeach
                <label for="per-page-{{ $noun }}">Rows</label>
                <select id="per-page-{{ $noun }}" name="per_page" class="dh-pager-select" onchange="this.form.requestSubmit()">
                    @foreach($sizes as $size)
                        <option value="{{ $size }}" @selected($p->perPage() === $size)>{{ $size }}</option>
                    @endforeach
                </select>
            </form>
        @endif

        @if($last > 1)
            <div class="dh-pager-pages">
                @if($p->onFirstPage())
                    <span class="dh-pager-btn is-off" aria-disabled="true"><x-icon name="chevron-left" size="16" /><span class="dh-pager-word">Previous</span></span>
                @else
                    <a href="{{ $p->previousPageUrl() }}" class="dh-pager-btn" rel="prev"><x-icon name="chevron-left" size="16" /><span class="dh-pager-word">Previous</span></a>
                @endif

                <span class="dh-pager-nums">
                    @foreach($pages as $i => $n)
                        @if($i > 0 && $n - $pages[$i - 1] > 1)<span class="dh-pager-gap" aria-hidden="true">…</span>@endif
                        @if($n === $cur)
                            <span class="dh-pager-num is-current" aria-current="page">{{ $n }}</span>
                        @else
                            <a href="{{ $p->url($n) }}" class="dh-pager-num" aria-label="Page {{ $n }}">{{ $n }}</a>
                        @endif
                    @endforeach
                </span>
                <span class="dh-pager-of">Page {{ $cur }} of {{ $last }}</span>

                @if($p->hasMorePages())
                    <a href="{{ $p->nextPageUrl() }}" class="dh-pager-btn" rel="next"><span class="dh-pager-word">Next</span><x-icon name="chevron-right" size="16" /></a>
                @else
                    <span class="dh-pager-btn is-off" aria-disabled="true"><span class="dh-pager-word">Next</span><x-icon name="chevron-right" size="16" /></span>
                @endif
            </div>
        @endif
    </nav>
@endif
