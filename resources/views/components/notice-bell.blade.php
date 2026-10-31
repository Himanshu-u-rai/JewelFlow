{{-- Header bell: the shop's notices on every page (the app's Shop notices, same rules). --}}
@php
    $noticeUser = auth()->user();
    $notices = $noticeUser?->shop_id ? \App\Services\ShopNoticeService::forUser($noticeUser) : null;
    $noticeIcons = [
        'repair_backlog' => ['warn', '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>'],
        'low_stock' => ['warn', '<polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/>'],
        'overdue_emis' => ['danger', '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>'],
        'reorder' => ['warn', '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>'],
        'other' => ['warn', '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>'],
    ];
@endphp

@if($notices)
    @php
        $noticeCount = $notices['count'];
        $noticeRows = [
            ['label' => 'Needs attention', 'items' => array_map(fn ($w) => ['icon' => $noticeIcons[$w['key']] ?? $noticeIcons['other'], 'title' => $w['message'], 'meta' => $w['note'], 'href' => $w['href']], $notices['warnings'])],
            ['label' => 'Reorder alerts', 'items' => array_map(fn ($r) => ['icon' => $noticeIcons['reorder']] + $r, $notices['reorder'])],
        ];
    @endphp
    <div class="notice-bell" x-data="{ open: false }"
         @keydown.escape.window="if (open) { open = false; $refs.toggle.focus(); }"
         @click.outside="open = false"
         data-notice-bell data-notice-count="{{ $noticeCount }}" data-reorder-count="{{ $notices['reorder_count'] }}">
        <button type="button" class="notice-bell-toggle" x-ref="toggle" :class="{ 'is-open': open }"
                @click="open = !open" aria-expanded="false" :aria-expanded="open ? 'true' : 'false'" aria-controls="notice-bell-panel"
                aria-label="{{ $noticeCount > 0 ? "Notices, {$noticeCount}" : 'Notices' }}">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            @if($noticeCount > 0)
                <span class="notice-bell-count" aria-hidden="true">{{ $noticeCount > 99 ? '99+' : $noticeCount }}</span>
            @endif
        </button>

        <div id="notice-bell-panel" class="notice-bell-panel" role="region" aria-labelledby="notice-bell-title"
             x-show="open" x-cloak x-transition.origin.top.right>
            <div class="notice-bell-head">
                <p class="notice-bell-title" id="notice-bell-title">Notices</p>
                @if($noticeCount > 0)
                    <span class="notice-bell-head-count">{{ $noticeCount }}</span>
                @endif
            </div>

            <div class="notice-bell-body">
                @if($noticeCount === 0)
                    <div class="notice-bell-empty">
                        <span class="notice-bell-empty-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        </span>
                        <p class="notice-bell-empty-title">All clear</p>
                        <p class="notice-bell-empty-copy">Nothing needs attention right now.</p>
                    </div>
                @endif

                @foreach($noticeRows as $section)
                    @continue(! $section['items'])
                    <p class="notice-bell-section">{{ $section['label'] }} <span>{{ count($section['items']) }}</span></p>
                    <ul class="notice-bell-list">
                        @foreach($section['items'] as $item)
                            @php $tag = $item['href'] ? 'a' : 'div'; @endphp
                            <li>
                                <{{ $tag }} class="notice-bell-item" @if($item['href']) href="{{ $item['href'] }}" @endif>
                                    <span class="notice-bell-icon notice-bell-icon--{{ $item['icon'][0] }}" aria-hidden="true">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! $item['icon'][1] !!}</svg>
                                    </span>
                                    <span class="notice-bell-text">
                                        <span class="notice-bell-item-title">{{ $item['title'] }}</span>
                                        @if($item['meta'])
                                            <span class="notice-bell-item-meta">{{ $item['meta'] }}</span>
                                        @endif
                                    </span>
                                    @if($item['href'])
                                        <svg class="notice-bell-chevron" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg>
                                    @endif
                                </{{ $tag }}>
                            </li>
                        @endforeach
                    </ul>
                @endforeach
            </div>

            @if($notices['reorder_href'])
                <a href="{{ $notices['reorder_href'] }}" class="notice-bell-foot">
                    Manage reorder rules
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg>
                </a>
            @endif
        </div>
    </div>
@endif
