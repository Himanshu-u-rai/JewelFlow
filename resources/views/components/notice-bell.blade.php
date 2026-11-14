{{-- The one bell (2026-11-14): everything that needs this user, on every page. Rows come from ShopNoticeService (the
     same rules as the app's Shop notices, plus price reviews and ready downloads); NoticeController redraws them in
     place on opening, after a download, and every 90 seconds while the tab is visible. --}}
@php
    $noticeUser = auth()->user();
    $notices = $noticeUser?->shop_id ? \App\Services\ShopNoticeService::forUser($noticeUser) : null;
@endphp

@if($notices)
    @php $noticeCount = $notices['count']; @endphp
    <div class="notice-bell"
         x-data="noticeBell(@js(route('notices.show')), @js(route('notices.downloads.clear')), {{ $noticeCount }})"
         @keydown.escape.window="if (open) { open = false; $refs.toggle.focus(); }"
         @click.outside="open = false"
         data-notice-bell data-notice-count="{{ $noticeCount }}" data-reorder-count="{{ $notices['reorder_count'] }}">
        <button type="button" class="notice-bell-toggle" x-ref="toggle" :class="{ 'is-open': open }"
                @click="toggle()" aria-expanded="false" :aria-expanded="open ? 'true' : 'false'" aria-controls="notice-bell-panel"
                aria-label="{{ $noticeCount > 0 ? "Notices, {$noticeCount}" : 'Notices' }}"
                :aria-label="count > 0 ? `Notices, ${count}` : 'Notices'">
            <x-icon name="bell" size="18" />
            <span class="notice-bell-count" aria-hidden="true" x-show="count > 0" x-text="count > 99 ? '99+' : count"
                  @if($noticeCount === 0) style="display: none" @endif>{{ $noticeCount > 99 ? '99+' : $noticeCount }}</span>
        </button>

        <div id="notice-bell-panel" class="notice-bell-panel" role="region" aria-labelledby="notice-bell-title"
             x-show="open" x-cloak x-transition.origin.top.right>
            <div class="notice-bell-head">
                <p class="notice-bell-title" id="notice-bell-title">Notices</p>
                <span class="notice-bell-head-count" x-show="count > 0" x-text="count"
                      @if($noticeCount === 0) style="display: none" @endif>{{ $noticeCount }}</span>
            </div>

            <div class="notice-bell-body" x-ref="body" aria-live="polite" @click="onBodyClick($event)">
                @include('components.notice-bell-body', ['notices' => $notices])
            </div>

            @if($notices['reorder_href'])
                <a href="{{ $notices['reorder_href'] }}" class="notice-bell-foot">
                    Manage reorder rules
                    <x-icon name="chevron-right" size="14" />
                </a>
            @endif
        </div>

    {{-- Inside the bell, not beside it: page headers lay out and count their own children. --}}
    <script>
        // Defined once: Turbo keeps window across visits. Alpine reads it from x-data above.
        window.noticeBell ??= function (showUrl, clearUrl, initialCount) {
            return {
                open: false,
                count: initialCount,
                _inflight: null,
                _timer: null,
                _epoch: 0,

                init() { this._schedule(); },
                // Alpine calls this when Turbo removes the page: the timer of a gone bell never fires again.
                destroy() { this._epoch++; clearTimeout(this._timer); },

                toggle() {
                    this.open = !this.open;
                    if (this.open) this.refresh();
                },

                // One request at a time; a failed one keeps what is shown (the rows are still true as of page load).
                refresh() {
                    if (this._inflight) return this._inflight;
                    this._inflight = fetch(showUrl, {
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    })
                        .then((res) => (res.ok ? res.json() : null))
                        .then((data) => { if (data) this.apply(data); })
                        .catch(() => {})
                        .finally(() => { this._inflight = null; });
                    return this._inflight;
                },

                apply(data) {
                    if (!this.$el.isConnected) return;
                    this.count = data.count;
                    this.$refs.body.innerHTML = data.html;
                    this.$el.dataset.noticeCount = String(data.count);
                    this.$el.dataset.reorderCount = String(data.reorder_count);
                    // The sidebar is turbo-permanent: its Stock pill shows the reorder count too.
                    const pill = document.querySelector('[data-reorder-pill]');
                    if (pill) {
                        pill.textContent = String(data.reorder_count);
                        pill.hidden = data.reorder_count === 0;
                    }
                },

                clearDownloads() {
                    const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                    return fetch(clearUrl, {
                        method: 'POST',
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token },
                        credentials: 'same-origin',
                    })
                        .then((res) => (res.ok ? res.json() : null))
                        .then((data) => { if (data) this.apply(data); })
                        .catch(() => {});
                },

                onBodyClick(event) {
                    if (event.target.closest('[data-notice-clear]')) {
                        event.preventDefault();
                        this.clearDownloads();
                    } else if (event.target.closest('[data-notice-download]')) {
                        // The download marks its message read on the server; show that once it has started.
                        setTimeout(() => this.refresh(), 2000);
                    }
                },

                // A slow, self-scheduling refresh while the tab is visible: a finished export or a fixed warning
                // shows without a reload. A newer schedule (epoch) or a removed bell ends the old one.
                _schedule() {
                    const epoch = this._epoch;
                    this._timer = setTimeout(async () => {
                        if (epoch !== this._epoch || !this.$el.isConnected) return;
                        if (!document.hidden) await this.refresh();
                        if (epoch === this._epoch && this.$el.isConnected) this._schedule();
                    }, 90000);
                },
            };
        };
    </script>
    </div>
@endif
