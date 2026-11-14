{{-- The bell's rows, from ShopNoticeService::forUser(). Rendered with the page and again by NoticeController when the
     bell refreshes in place, so both always draw the same thing. Included with $notices (not used as a component). --}}
@php
    $noticeIcons = [
        'repair_backlog' => ['warn', 'wrench'],
        'low_stock' => ['warn', 'archive'],
        'overdue_emis' => ['danger', 'circle-alert'],
        'pricing' => ['warn', 'tag'],
        'download' => ['info', 'download'],
        'reorder' => ['warn', 'package'],
        'other' => ['warn', 'triangle-alert'],
    ];
    $attention = array_map(fn ($w) => ['icon' => $noticeIcons[$w['key']] ?? $noticeIcons['other'], 'title' => $w['message'], 'meta' => $w['note'], 'href' => $w['href']], $notices['warnings']);
    if ($notices['pricing']) {
        $attention[] = ['icon' => $noticeIcons['pricing']] + $notices['pricing'];
    }
    $sections = [
        ['key' => 'attention', 'label' => 'Needs attention', 'items' => $attention],
        ['key' => 'downloads', 'label' => 'Ready downloads', 'items' => array_map(fn ($d) => ['icon' => $noticeIcons['download'], 'download' => true] + $d, $notices['downloads'])],
        ['key' => 'reorder', 'label' => 'Reorder alerts', 'items' => array_map(fn ($r) => ['icon' => $noticeIcons['reorder']] + $r, $notices['reorder'])],
    ];
@endphp

@if($notices['count'] === 0)
    <div class="notice-bell-empty">
        <span class="notice-bell-empty-icon" aria-hidden="true">
            <x-icon name="circle-check" size="20" />
        </span>
        <p class="notice-bell-empty-title">All clear</p>
        <p class="notice-bell-empty-copy">Nothing needs attention right now.</p>
    </div>
@endif

@foreach($sections as $section)
    @continue(! $section['items'])
    <div class="notice-bell-section">
        <p>{{ $section['label'] }} <span>{{ count($section['items']) }}</span></p>
        @if($section['key'] === 'downloads')
            <button type="button" class="notice-bell-section-action" data-notice-clear>Clear</button>
        @endif
    </div>
    <ul class="notice-bell-list">
        @foreach($section['items'] as $item)
            @php $tag = $item['href'] ? 'a' : 'div'; @endphp
            <li>
                {{-- A download is a file, not a page: no Turbo visit; the bell refreshes after it. --}}
                <{{ $tag }} class="notice-bell-item" @if($item['href']) href="{{ $item['href'] }}" @endif
                    @if(! empty($item['download'])) data-turbo="false" data-notice-download @endif>
                    <span class="notice-bell-icon notice-bell-icon--{{ $item['icon'][0] }}" aria-hidden="true">
                        <x-icon :name="$item['icon'][1]" size="16" />
                    </span>
                    <span class="notice-bell-text">
                        <span class="notice-bell-item-title">{{ $item['title'] }}</span>
                        @if($item['meta'])
                            <span class="notice-bell-item-meta">{{ $item['meta'] }}</span>
                        @endif
                    </span>
                    @if($item['href'])
                        <x-icon :name="empty($item['download']) ? 'chevron-right' : 'download'" size="14" class="notice-bell-chevron" />
                    @endif
                </{{ $tag }}>
            </li>
        @endforeach
    </ul>
@endforeach
