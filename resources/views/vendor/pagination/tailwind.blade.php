@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="jf-pagination">

        {{-- Mobile --}}
        <div class="flex gap-2 items-center justify-between sm:hidden">
            @if ($paginator->onFirstPage())
                <span class="jf-page-btn jf-page-btn--disabled">{!! __('pagination.previous') !!}</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="jf-page-btn">{!! __('pagination.previous') !!}</a>
            @endif

            <span class="text-sm text-gray-600">
                {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}
            </span>

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="jf-page-btn">{!! __('pagination.next') !!}</a>
            @else
                <span class="jf-page-btn jf-page-btn--disabled">{!! __('pagination.next') !!}</span>
            @endif
        </div>

        {{-- Desktop --}}
        <div class="hidden sm:flex-1 sm:flex sm:gap-3 sm:items-center sm:justify-between">
            <div>
                <p class="text-sm text-gray-600 leading-5">
                    {!! __('Showing') !!}
                    @if ($paginator->firstItem())
                        <span class="font-semibold text-gray-800">{{ $paginator->firstItem() }}</span>
                        {!! __('to') !!}
                        <span class="font-semibold text-gray-800">{{ $paginator->lastItem() }}</span>
                    @else
                        {{ $paginator->count() }}
                    @endif
                    {!! __('of') !!}
                    <span class="font-semibold text-gray-800">{{ $paginator->total() }}</span>
                    {!! __('results') !!}
                </p>
            </div>

            <div class="jf-pager">
                {{-- Previous --}}
                @if ($paginator->onFirstPage())
                    <span aria-disabled="true" aria-label="{{ __('pagination.previous') }}" class="jf-pager-btn jf-pager-btn--disabled">
                        <x-icon name="chevron-left" class="w-4 h-4" />
                    </span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="jf-pager-btn" aria-label="{{ __('pagination.previous') }}">
                        <x-icon name="chevron-left" class="w-4 h-4" />
                    </a>
                @endif

                {{-- Elements --}}
                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span class="jf-pager-ellipsis" aria-disabled="true">…</span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span class="jf-pager-btn jf-pager-btn--active" aria-current="page">{{ $page }}</span>
                            @else
                                <a href="{{ $url }}" class="jf-pager-btn" aria-label="{{ __('Go to page :page', ['page' => $page]) }}">{{ $page }}</a>
                            @endif
                        @endforeach
                    @endif
                @endforeach

                {{-- Next --}}
                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="jf-pager-btn" aria-label="{{ __('pagination.next') }}">
                        <x-icon name="chevron-right" class="w-4 h-4" />
                    </a>
                @else
                    <span aria-disabled="true" aria-label="{{ __('pagination.next') }}" class="jf-pager-btn jf-pager-btn--disabled">
                        <x-icon name="chevron-right" class="w-4 h-4" />
                    </span>
                @endif
            </div>
        </div>
    </nav>
@endif
