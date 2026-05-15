<x-app-layout>
    <style>
        [x-cloak] {
            display: none !important;
        }

        .jo-filter-select {
            position: relative;
            min-width: 180px;
        }

        body.jo-mobile-filter-open {
            overflow: hidden;
        }

        .jo-filter-trigger {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            width: 100%;
            min-height: 34px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: #ffffff;
            padding: 6px 10px;
            color: #111827;
            font-size: 14px;
            text-align: left;
        }

        .jo-filter-trigger:focus {
            outline: none;
            border-color: #0f766e;
            box-shadow: 0 0 0 3px rgba(15, 118, 110, .12);
        }

        .jo-filter-placeholder {
            color: #6b7280;
        }

        .jo-filter-menu {
            position: absolute;
            z-index: 2400;
            top: calc(100% + 8px);
            left: 0;
            right: 0;
            overflow: hidden;
            border: 1px solid #dbe3ee;
            border-radius: 14px;
            background: #ffffff;
            box-shadow: 0 18px 36px rgba(15, 23, 42, .16);
        }

        .jo-filter-menu[data-open-up="true"] {
            top: auto;
            bottom: calc(100% + 8px);
        }

        .jo-filter-list {
            max-height: var(--jo-filter-list-max-height, 240px);
            overflow-y: auto;
            padding: 6px;
        }

        .jo-filter-option {
            display: block;
            width: 100%;
            border-radius: 10px;
            padding: 9px 10px;
            color: #0f172a;
            font-size: 14px;
            font-weight: 800;
            text-align: left;
        }

        .jo-filter-option:hover,
        .jo-filter-option-selected {
            background: #f0fdfa;
            color: #0f766e;
        }

        .jo-filter-card {
            position: relative;
            z-index: 2200;
            isolation: isolate;
            overflow: visible;
        }

        .jo-mobile-filter-trigger-shell,
        .jo-mobile-filter-overlay,
        .jo-mobile-filter-drawer {
            display: none;
        }

        .jo-mobile-filter-trigger-shell {
            margin-bottom: 16px;
        }

        .jo-mobile-filter-trigger {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            width: 100%;
            border: 1px solid #dbe3ee;
            border-radius: 18px;
            background: #ffffff;
            padding: 14px 16px;
            text-align: left;
            box-shadow: 0 10px 24px rgba(15, 23, 42, .06);
        }

        .jo-mobile-filter-trigger:focus {
            outline: none;
            border-color: #0f766e;
            box-shadow: 0 0 0 4px rgba(15, 118, 110, .12);
        }

        .jo-mobile-filter-trigger-copy {
            min-width: 0;
        }

        .jo-mobile-filter-kicker {
            color: #64748b;
            font-size: 11px;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .jo-mobile-filter-summary {
            margin-top: 4px;
            color: #0f172a;
            font-size: 14px;
            font-weight: 800;
            line-height: 1.45;
        }

        .jo-mobile-filter-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }

        .jo-mobile-filter-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 28px;
            padding: 0 10px;
            border-radius: 999px;
            border: 1px solid #fde68a;
            background: #fffbeb;
            color: #92400e;
            font-size: 12px;
            font-weight: 900;
        }

        .jo-mobile-filter-open-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            border-radius: 999px;
            border: 1px solid #dbe3ee;
            background: #f8fafc;
            padding: 0 14px;
            color: #0f172a;
            font-size: 12px;
            font-weight: 900;
            white-space: nowrap;
        }

        .jo-mobile-filter-overlay {
            position: fixed;
            inset: 0;
            z-index: 85;
            background: rgba(15, 23, 42, .48);
            backdrop-filter: blur(2px);
        }

        .jo-mobile-filter-drawer {
            position: fixed;
            right: 0;
            bottom: 0;
            left: 0;
            z-index: 90;
            padding: 0 0 env(safe-area-inset-bottom, 0px);
        }

        .jo-mobile-filter-drawer-enter,
        .jo-mobile-filter-drawer-leave {
            transition: transform .2s ease, opacity .2s ease;
        }

        .jo-mobile-filter-drawer-enter-start,
        .jo-mobile-filter-drawer-leave-end {
            opacity: 0;
            transform: translateY(24px);
        }

        .jo-mobile-filter-drawer-enter-end,
        .jo-mobile-filter-drawer-leave-start {
            opacity: 1;
            transform: translateY(0);
        }

        .jo-mobile-filter-sheet {
            display: flex;
            flex-direction: column;
            max-height: min(82vh, 720px);
            border-radius: 24px 24px 0 0;
            border: 1px solid #dbe3ee;
            background: #ffffff;
            box-shadow: 0 -18px 40px rgba(15, 23, 42, .22);
            overflow: hidden;
        }

        .jo-mobile-filter-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 18px 14px;
            border-bottom: 1px solid #e2e8f0;
        }

        .jo-mobile-filter-title {
            color: #0f172a;
            font-size: 17px;
            font-weight: 900;
            line-height: 1.2;
        }

        .jo-mobile-filter-copy {
            margin-top: 4px;
            color: #64748b;
            font-size: 12px;
            line-height: 1.45;
        }

        .jo-mobile-filter-close {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border: 1px solid #dbe3ee;
            border-radius: 999px;
            background: #ffffff;
            color: #475569;
            flex-shrink: 0;
        }

        .jo-mobile-filter-body {
            overflow-y: auto;
            padding: 16px 18px 18px;
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .jo-mobile-filter-group {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .jo-mobile-filter-label {
            color: #475569;
            font-size: 11px;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .jo-mobile-status-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .jo-mobile-status-option {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            border: 1px solid #dbe3ee;
            border-radius: 14px;
            background: #ffffff;
            padding: 10px 12px;
            color: #0f172a;
            font-size: 13px;
            font-weight: 800;
            text-align: center;
        }

        .jo-mobile-status-option.is-active {
            border-color: #0f766e;
            background: #f0fdfa;
            color: #0f766e;
            box-shadow: 0 0 0 3px rgba(15, 118, 110, .08);
        }

        .jo-mobile-native-select,
        .jo-mobile-date-input {
            width: 100%;
            min-height: 46px;
            border: 1px solid #d1d5db;
            border-radius: 14px;
            background: #ffffff;
            padding: 10px 12px;
            color: #111827;
            font-size: 15px;
        }

        .jo-mobile-date-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .jo-mobile-filter-footer {
            position: sticky;
            bottom: 0;
            display: flex;
            gap: 12px;
            padding: 14px 18px calc(14px + env(safe-area-inset-bottom, 0px));
            border-top: 1px solid #e2e8f0;
            background: rgba(255, 255, 255, .98);
        }

        .jo-mobile-filter-footer button {
            flex: 1 1 0;
            min-height: 48px;
            border-radius: 14px;
            font-size: 14px;
            font-weight: 900;
        }

        .jo-mobile-filter-clear {
            border: 1px solid #dbe3ee;
            background: #f8fafc;
            color: #0f172a;
        }

        .jo-mobile-filter-apply {
            border: 1px solid #0f766e;
            background: #0f766e;
            color: #ffffff;
            box-shadow: 0 12px 24px rgba(15, 118, 110, .2);
        }

        .jo-filter-field {
            display: flex;
            flex-direction: column;
            min-width: 150px;
        }

        .jo-date-control {
            width: 100%;
            height: 34px;
        }

        .jo-table-card {
            position: relative;
            z-index: 1;
            overflow: visible;
        }

        .jo-table-wrap {
            overflow-x: auto;
            overscroll-behavior-x: contain;
        }

        .jo-table {
            min-width: 980px;
        }

        .jo-mobile-list {
            display: none;
        }

        .jo-mobile-card {
            border: 1px solid #dbe3ee;
            border-radius: 16px;
            background: #ffffff;
            padding: 14px;
            box-shadow: 0 8px 18px rgba(15, 23, 42, .05);
        }

        .jo-mobile-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .jo-mobile-label {
            margin-bottom: 4px;
            color: #64748b;
            font-size: 11px;
            font-weight: 900;
        }

        @media (max-width: 680px) {
            .jo-filter-card--desktop {
                display: none;
            }

            .jo-mobile-filter-trigger-shell,
            .jo-mobile-filter-overlay,
            .jo-mobile-filter-drawer {
                display: block;
            }

            .jo-table-wrap {
                display: none;
            }

            .jo-mobile-list {
                display: block;
                padding: 14px;
            }

            .jo-mobile-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    @php
        $statusLabels = [
            'issued' => 'Issued',
            'partial_return' => 'Partial return',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
        ];
        $selectedKarigar = $karigars->firstWhere('id', (int) $filterKarigar);
    @endphp

    <x-page-header title="Job Orders" subtitle="Bullion issued to karigars">
        <x-slot:actions>
            @can('job_order.manage')
            <a href="{{ route('job-orders.create') }}" class="btn btn-success btn-sm">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Issue Bullion
            </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner">

        @unless(auth()->user()->can('job_order.manage'))
            @include('partials.view-only-banner', ['permission' => 'job_order.manage', 'message' => 'job orders'])
        @endunless

        <div x-data="{
                  statusOpen: false,
                  statusMenuStyle: '',
                  status: @js($filterStatus ?? ''),
                  statusName: @js($filterStatus ? ($statusLabels[$filterStatus] ?? str_replace('_', ' ', $filterStatus)) : ''),
                  karigarOpen: false,
                  karigarMenuStyle: '',
                  karigarId: @js((string) ($filterKarigar ?? '')),
                  karigarName: @js($selectedKarigar?->name ?? ''),
                  fromDate: @js($filterFrom),
                  toDate: @js($filterTo),
                  mobileFilterOpen: false,
                  draftStatus: @js($filterStatus ?? ''),
                  draftStatusName: @js($filterStatus ? ($statusLabels[$filterStatus] ?? str_replace('_', ' ', $filterStatus)) : ''),
                  draftKarigarId: @js((string) ($filterKarigar ?? '')),
                  draftKarigarName: @js($selectedKarigar?->name ?? ''),
                  draftFromDate: @js($filterFrom),
                  draftToDate: @js($filterTo),
                  karigarOptions: @js($karigars->map(fn ($karigar) => ['id' => (string) $karigar->id, 'name' => $karigar->name])->values()),
                  initMobileDrawerWatcher() {
                      const drawer = document.querySelector('[data-mobile-drawer=\'tenant\']');
                      if (!drawer || this._joDrawerObserver) {
                          return;
                      }

                      this._joDrawerObserver = new MutationObserver(() => {
                          if (drawer.classList.contains('mobile-open')) {
                              this.closeMobileFilters();
                          }
                      });

                      this._joDrawerObserver.observe(drawer, { attributes: true, attributeFilter: ['class'] });
                  },
                  findKarigarName(id) {
                      const match = this.karigarOptions.find((option) => option.id === String(id));
                      return match ? match.name : '';
                  },
                  setStatus(value, label) {
                      this.status = value;
                      this.statusName = value ? label : '';
                      this.statusOpen = false;
                      this.statusMenuStyle = '';
                  },
                  setKarigar(value, label) {
                      this.karigarId = value;
                      this.karigarName = value ? label : '';
                      this.karigarOpen = false;
                      this.karigarMenuStyle = '';
                  },
                  setDraftStatus(value, label) {
                      this.draftStatus = value;
                      this.draftStatusName = value ? label : '';
                  },
                  syncDraftKarigarName() {
                      this.draftKarigarName = this.findKarigarName(this.draftKarigarId);
                  },
                  copyCommittedToDraft() {
                      this.draftStatus = this.status;
                      this.draftStatusName = this.statusName;
                      this.draftKarigarId = this.karigarId;
                      this.draftKarigarName = this.karigarName;
                      this.draftFromDate = this.fromDate;
                      this.draftToDate = this.toDate;
                  },
                  activeFilterCount() {
                      let count = 0;
                      if (this.status) count++;
                      if (this.karigarId) count++;
                      if (this.fromDate) count++;
                      if (this.toDate) count++;
                      return count;
                  },
                  mobileFilterSummary() {
                      const parts = [];
                      if (this.statusName) parts.push(this.statusName);
                      if (this.karigarName) parts.push(this.karigarName);
                      if (this.fromDate || this.toDate) {
                          parts.push(this.fromDate && this.toDate ? 'Date range' : (this.fromDate ? 'From date' : 'To date'));
                      }
                      return parts.length ? parts.join(' • ') : 'All job orders';
                  },
                  closeTenantDrawerIfNeeded() {
                      if (window.innerWidth > 680) {
                          return;
                      }

                      const drawer = document.querySelector('[data-mobile-drawer=\'tenant\']');
                      if (drawer?.classList.contains('mobile-open')) {
                          document.querySelector('[data-mobile-menu-toggle=\'tenant\']')?.click();
                      }
                  },
                  openMobileFilters() {
                      this.closeDropdowns();
                      this.copyCommittedToDraft();
                      this.closeTenantDrawerIfNeeded();
                      this.mobileFilterOpen = true;
                      document.body.classList.add('jo-mobile-filter-open');
                  },
                  closeMobileFilters() {
                      this.mobileFilterOpen = false;
                      document.body.classList.remove('jo-mobile-filter-open');
                  },
                  applyMobileFilters() {
                      this.status = this.draftStatus;
                      this.statusName = this.draftStatusName;
                      this.karigarId = this.draftKarigarId;
                      this.karigarName = this.draftKarigarName;
                      this.fromDate = this.draftFromDate;
                      this.toDate = this.draftToDate;
                      this.closeMobileFilters();
                      this.$nextTick(() => this.$refs.mobileFilterForm.submit());
                  },
                  clearMobileFilters() {
                      this.draftStatus = '';
                      this.draftStatusName = '';
                      this.draftKarigarId = '';
                      this.draftKarigarName = '';
                      this.draftFromDate = '';
                      this.draftToDate = '';
                      this.status = '';
                      this.statusName = '';
                      this.karigarId = '';
                      this.karigarName = '';
                      this.fromDate = '';
                      this.toDate = '';
                      this.closeMobileFilters();
                      this.$nextTick(() => this.$refs.mobileFilterForm.submit());
                  },
                  toggleDropdown(which, trigger) {
                      const openKey = which + 'Open';
                      const nextState = !this[openKey];
                      this.closeDropdowns();
                      if (!nextState) {
                          return;
                      }

                      if (window.innerWidth <= 680) {
                          const drawer = document.querySelector('[data-mobile-drawer=\'tenant\']');
                          if (drawer?.classList.contains('mobile-open')) {
                              document.querySelector('[data-mobile-menu-toggle=\'tenant\']')?.click();
                          }
                      }

                      this[openKey] = true;
                      this.$nextTick(() => this.positionDropdown(which, trigger));
                  },
                  positionDropdown(which, trigger) {
                      const styleKey = which + 'MenuStyle';
                      const menu = trigger?.parentElement?.querySelector('.jo-filter-menu');
                      if (!menu || window.innerWidth <= 680) {
                          this[styleKey] = '';
                          if (menu) {
                              menu.dataset.openUp = 'false';
                          }
                          return;
                      }

                      const gutter = 12;
                      const triggerRect = trigger.getBoundingClientRect();
                      const preferredHeight = Math.min(menu.scrollHeight || 240, 280);
                      const spaceBelow = window.innerHeight - triggerRect.bottom - gutter;
                      const spaceAbove = triggerRect.top - gutter;
                      const openUp = spaceBelow < Math.min(preferredHeight, 220) && spaceAbove > spaceBelow;
                      const maxHeight = Math.max(140, Math.min(preferredHeight, openUp ? spaceAbove : spaceBelow));
                      const width = Math.max(triggerRect.width, 180);
                      const left = Math.min(
                          Math.max(gutter, triggerRect.left),
                          Math.max(gutter, window.innerWidth - gutter - width)
                      );
                      const top = openUp
                          ? Math.max(gutter, triggerRect.top - maxHeight - 8)
                          : Math.min(window.innerHeight - gutter - maxHeight, triggerRect.bottom + 8);

                      menu.dataset.openUp = openUp ? 'true' : 'false';
                      this[styleKey] = `position: fixed; left: ${left}px; top: ${top}px; width: ${width}px; --jo-filter-list-max-height: ${maxHeight}px;`;
                  },
                  closeDropdowns() {
                      this.statusOpen = false;
                      this.karigarOpen = false;
                      this.statusMenuStyle = '';
                      this.karigarMenuStyle = '';
                  }
              }"
              x-init="initMobileDrawerWatcher()"
              @keydown.escape.window="closeDropdowns(); closeMobileFilters()"
              @resize.window="closeDropdowns(); if (window.innerWidth > 680) closeMobileFilters()">
            <div class="jo-mobile-filter-trigger-shell" x-cloak>
                <button type="button" class="jo-mobile-filter-trigger" @click="openMobileFilters()" :aria-expanded="mobileFilterOpen.toString()">
                    <div class="jo-mobile-filter-trigger-copy">
                        <div class="jo-mobile-filter-kicker">Filters</div>
                        <div class="jo-mobile-filter-summary" x-text="mobileFilterSummary()">All job orders</div>
                    </div>
                    <div class="jo-mobile-filter-meta">
                        <span class="jo-mobile-filter-count" x-text="activeFilterCount()">0</span>
                        <span class="jo-mobile-filter-open-btn">Open</span>
                    </div>
                </button>
            </div>

            <div class="jo-mobile-filter-overlay"
                 x-show="mobileFilterOpen"
                 x-transition.opacity
                 x-cloak
                 @click="closeMobileFilters()"></div>

            <div class="jo-mobile-filter-drawer"
                 x-show="mobileFilterOpen"
                 x-transition:enter="jo-mobile-filter-drawer-enter"
                 x-transition:enter-start="jo-mobile-filter-drawer-enter-start"
                 x-transition:enter-end="jo-mobile-filter-drawer-enter-end"
                 x-transition:leave="jo-mobile-filter-drawer-leave"
                 x-transition:leave-start="jo-mobile-filter-drawer-leave-start"
                 x-transition:leave-end="jo-mobile-filter-drawer-leave-end"
                 x-cloak>
                <form method="GET" class="jo-mobile-filter-sheet" x-ref="mobileFilterForm" @submit.prevent="applyMobileFilters()">
                    <div class="jo-mobile-filter-head">
                        <div>
                            <div class="jo-mobile-filter-title">Filter Job Orders</div>
                            <div class="jo-mobile-filter-copy">Refine the list without crowding the main screen.</div>
                        </div>
                        <button type="button" class="jo-mobile-filter-close" @click="closeMobileFilters()" aria-label="Close filters">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                        </button>
                    </div>

                    <div class="jo-mobile-filter-body">
                        <div class="jo-mobile-filter-group">
                            <label class="jo-mobile-filter-label">Status</label>
                            <input type="hidden" name="status" x-model="draftStatus">
                            <div class="jo-mobile-status-grid">
                                <button type="button" class="jo-mobile-status-option" :class="{ 'is-active': draftStatus === '' }" @click="setDraftStatus('', '')">All statuses</button>
                                @foreach($statusLabels as $value => $label)
                                    <button type="button" class="jo-mobile-status-option" :class="{ 'is-active': draftStatus === '{{ $value }}' }" @click="setDraftStatus('{{ $value }}', @js($label))">{{ $label }}</button>
                                @endforeach
                            </div>
                        </div>

                        <div class="jo-mobile-filter-group">
                            <label class="jo-mobile-filter-label" for="jo-mobile-karigar">Karigar</label>
                            <select id="jo-mobile-karigar" name="karigar_id" class="jo-mobile-native-select" x-model="draftKarigarId" @change="syncDraftKarigarName()">
                                <option value="">All karigars</option>
                                @foreach($karigars as $k)
                                    <option value="{{ $k->id }}">{{ $k->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="jo-mobile-filter-group">
                            <label class="jo-mobile-filter-label">Date Range</label>
                            <div class="jo-mobile-date-grid">
                                <input type="date" name="from" class="jo-mobile-date-input" x-model="draftFromDate">
                                <input type="date" name="to" class="jo-mobile-date-input" x-model="draftToDate">
                            </div>
                        </div>
                    </div>

                    <div class="jo-mobile-filter-footer">
                        <button type="button" class="jo-mobile-filter-clear" @click="clearMobileFilters()">Clear</button>
                        <button type="submit" class="jo-mobile-filter-apply">Apply Filters</button>
                    </div>
                </form>
            </div>

            <form method="GET" class="jo-filter-card jo-filter-card--desktop bg-white border border-gray-200 rounded-xl p-4 mb-4 flex flex-wrap items-end gap-3">
            <div class="jo-filter-field">
                <label class="text-[11px] uppercase tracking-wide text-gray-500 font-semibold">Status</label>
                <div class="jo-filter-select" @click.outside="statusOpen = false; statusMenuStyle = ''">
                    <input type="hidden" name="status" x-model="status">
                    <button type="button" class="jo-filter-trigger" @click="toggleDropdown('status', $el)" :aria-expanded="statusOpen.toString()">
                        <span :class="statusName ? '' : 'jo-filter-placeholder'" x-text="statusName || 'All statuses'">All statuses</span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div class="jo-filter-menu" x-show="statusOpen" :style="statusMenuStyle" x-transition.origin.top x-cloak>
                        <div class="jo-filter-list">
                            <button type="button" class="jo-filter-option" @click="setStatus('', 'All statuses')">All statuses</button>
                            @foreach($statusLabels as $value => $label)
                                <button type="button"
                                        class="jo-filter-option"
                                        :class="status === '{{ $value }}' ? 'jo-filter-option-selected' : ''"
                                        @click="setStatus('{{ $value }}', @js($label))">{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
            <div class="jo-filter-field">
                <label class="text-[11px] uppercase tracking-wide text-gray-500 font-semibold">Karigar</label>
                <div class="jo-filter-select" @click.outside="karigarOpen = false; karigarMenuStyle = ''">
                    <input type="hidden" name="karigar_id" x-model="karigarId">
                    <button type="button" class="jo-filter-trigger" @click="toggleDropdown('karigar', $el)" :aria-expanded="karigarOpen.toString()">
                        <span :class="karigarName ? '' : 'jo-filter-placeholder'" x-text="karigarName || 'All karigars'">All karigars</span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div class="jo-filter-menu" x-show="karigarOpen" :style="karigarMenuStyle" x-transition.origin.top x-cloak>
                        <div class="jo-filter-list">
                            <button type="button" class="jo-filter-option" @click="setKarigar('', 'All karigars')">All karigars</button>
                            @foreach($karigars as $k)
                                <button type="button"
                                        class="jo-filter-option"
                                        :class="karigarId === '{{ $k->id }}' ? 'jo-filter-option-selected' : ''"
                                        @click="setKarigar('{{ $k->id }}', @js($k->name))">{{ $k->name }}</button>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
            <div class="jo-filter-field">
                <label class="text-[11px] uppercase tracking-wide text-gray-500 font-semibold">From</label>
                <input type="date" name="from" x-model="fromDate" class="jo-date-control rounded-md border-gray-300 text-sm">
            </div>
            <div class="jo-filter-field">
                <label class="text-[11px] uppercase tracking-wide text-gray-500 font-semibold">To</label>
                <input type="date" name="to" x-model="toDate" class="jo-date-control rounded-md border-gray-300 text-sm">
            </div>
            <button type="submit" class="btn btn-secondary btn-sm" style="height:34px;">Filter</button>
            </form>

            <div class="jo-table-card bg-white rounded-xl border border-gray-200 shadow-sm">
            @if($orders->isEmpty())
                <div class="py-16 text-center text-gray-400">
                    <p class="text-sm mb-3">No job orders match your filter.</p>
                    @can('job_order.manage')
                    <a href="{{ route('job-orders.create') }}" class="text-teal-700 underline text-sm">Issue your first job order</a>
                    @endcan
                </div>
            @else
                <div class="jo-table-wrap">
                    <table class="jo-table w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                <th class="px-4 py-3 text-left font-semibold">Job #</th>
                                <th class="px-4 py-3 text-left font-semibold">Karigar</th>
                                <th class="px-4 py-3 text-left font-semibold">Issued</th>
                                <th class="px-4 py-3 text-right font-semibold">Gross / Fine</th>
                                <th class="px-4 py-3 text-right font-semibold">Returned (fine)</th>
                                <th class="px-4 py-3 text-right font-semibold">Wastage</th>
                                <th class="px-4 py-3 text-center font-semibold">Status</th>
                                <th class="px-4 py-3 text-left font-semibold">Flags</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($orders as $jo)
                                <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('job-orders.show', $jo) }}'"  >
                                    <td class="px-4 py-3">
                                        <a href="{{ route('job-orders.show', $jo) }}" class="text-teal-700 font-mono hover:underline">{{ $jo->job_order_number }}</a>
                                        <div class="text-[10px] text-gray-400">DC: {{ $jo->challan_number }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-gray-700">{{ $jo->karigar?->name }}</td>
                                    <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ $jo->issue_date->format('d M Y') }}</td>
                                    <td class="px-4 py-3 text-right font-mono">{{ number_format($jo->issued_gross_weight, 3) }} / {{ number_format($jo->issued_fine_weight, 3) }}g</td>
                                    <td class="px-4 py-3 text-right font-mono">{{ number_format($jo->returned_fine_weight, 3) }}g</td>
                                    <td class="px-4 py-3 text-right font-mono">{{ number_format($jo->actual_wastage_fine, 3) }}g</td>
                                    <td class="px-4 py-3 text-center">
                                        <span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-semibold {{ $jo->status === 'completed' ? 'bg-emerald-100 text-emerald-800' : ($jo->status === 'cancelled' ? 'bg-gray-200 text-gray-600' : ($jo->status === 'partial_return' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800')) }}">{{ str_replace('_', ' ', $jo->status) }}</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        @foreach($jo->discrepancy_flags ?? [] as $flag)
                                            <span class="inline-block px-1.5 py-0.5 rounded text-[10px] font-semibold bg-rose-100 text-rose-800 mr-1">{{ str_replace('_', ' ', $flag) }}</span>
                                        @endforeach
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="jo-mobile-list space-y-3">
                    @foreach($orders as $jo)
                        <article class="jo-mobile-card">
                            <div class="mb-3 flex items-start justify-between gap-3">
                                <div>
                                    <a href="{{ route('job-orders.show', $jo) }}" class="font-mono text-sm font-black text-teal-700">{{ $jo->job_order_number }}</a>
                                    <p class="mt-1 text-xs text-slate-500">DC: {{ $jo->challan_number }}</p>
                                </div>
                                <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-bold {{ $jo->status === 'completed' ? 'bg-emerald-100 text-emerald-800' : ($jo->status === 'cancelled' ? 'bg-gray-200 text-gray-600' : ($jo->status === 'partial_return' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800')) }}">{{ str_replace('_', ' ', $jo->status) }}</span>
                            </div>

                            <div class="jo-mobile-grid text-sm">
                                <div>
                                    <p class="jo-mobile-label">Karigar</p>
                                    <p class="font-semibold text-slate-800">{{ $jo->karigar?->name ?? '—' }}</p>
                                </div>
                                <div>
                                    <p class="jo-mobile-label">Issued</p>
                                    <p class="text-slate-700">{{ $jo->issue_date->format('d M Y') }}</p>
                                </div>
                                <div>
                                    <p class="jo-mobile-label">Gross / Fine</p>
                                    <p class="font-mono font-bold text-slate-800">{{ number_format($jo->issued_gross_weight, 3) }} / {{ number_format($jo->issued_fine_weight, 3) }}g</p>
                                </div>
                                <div>
                                    <p class="jo-mobile-label">Returned Fine</p>
                                    <p class="font-mono text-slate-700">{{ number_format($jo->returned_fine_weight, 3) }}g</p>
                                </div>
                                <div>
                                    <p class="jo-mobile-label">Wastage</p>
                                    <p class="font-mono text-slate-700">{{ number_format($jo->actual_wastage_fine, 3) }}g</p>
                                </div>
                            </div>

                            @if(! empty($jo->discrepancy_flags))
                                <div class="mt-3 border-t border-slate-100 pt-3">
                                    @foreach($jo->discrepancy_flags as $flag)
                                        <span class="mr-1 inline-flex rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-bold text-rose-800">{{ str_replace('_', ' ', $flag) }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>

                <div class="p-4">{{ $orders->links() }}</div>
            @endif
            </div>
        </div>
    </div>
</x-app-layout>
