{{-- Header bell: the shop's notices on every page (the app's Shop notices, same rules). --}}
@php
    $noticeUser = auth()->user();
    $notices = $noticeUser?->shop_id ? \App\Services\ShopNoticeService::forUser($noticeUser) : null;
    $noticeIcons = [
        'repair_backlog' => ['warn', 'wrench'],
        'low_stock' => ['warn', 'archive'],
        'overdue_emis' => ['danger', 'circle-alert'],
        'reorder' => ['warn', 'package'],
        'other' => ['warn', 'triangle-alert'],
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
            <x-icon name="bell" size="18" />
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
                            <x-icon name="circle-check" size="20" />
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
                                        <x-icon :name="$item['icon'][1]" size="16" />
                                    </span>
                                    <span class="notice-bell-text">
                                        <span class="notice-bell-item-title">{{ $item['title'] }}</span>
                                        @if($item['meta'])
                                            <span class="notice-bell-item-meta">{{ $item['meta'] }}</span>
                                        @endif
                                    </span>
                                    @if($item['href'])
                                        <x-icon name="chevron-right" size="14" class="notice-bell-chevron" />
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
                    <x-icon name="chevron-right" size="14" />
                </a>
            @endif
        </div>
    </div>
@endif
