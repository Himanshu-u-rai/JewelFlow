@php
    $customerName = $invoice->customer
        ? trim($invoice->customer->first_name . ' ' . $invoice->customer->last_name)
        : 'No customer linked';
    $approvalRuleCopy = 'Changing the valuation basis away from the shop default (' . str_replace('_', ' ', $defaultBasis) . ') is checked against user permissions during submit. Manual rate override requires exchange override permission. Line-level refund changes require Approve Returns permission.';
@endphp

<x-app-layout>
    <x-page-header class="exchange-create-header jf-header-auto-mobile">
        <div class="min-w-0 exchange-create-title-block">
            <div class="exchange-create-title-row">
                <h1 class="page-title">Process exchange</h1>
                <div class="exchange-rule-help" data-exchange-rule-help>
                    <button type="button"
                            class="exchange-rule-help__trigger"
                            data-exchange-rule-trigger
                            aria-label="Show exchange approval rules"
                            aria-expanded="false"
                            aria-controls="exchange-rule-popover">i</button>
                    <div id="exchange-rule-popover" class="exchange-rule-popover" role="tooltip" hidden>
                        <strong>Approval rules</strong>
                        <p>{{ $approvalRuleCopy }}</p>
                    </div>
                </div>
            </div>
            <p class="page-subtitle">{{ $invoice->invoice_number }} / {{ $customerName }}</p>
        </div>

        <div class="page-actions">
            <a href="{{ route('invoices.show', $invoice) }}" class="exchange-create-back-btn" data-turbo-frame="_top">
                <svg class="exchange-create-back-btn__icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
                <span>Cancel</span>
            </a>
        </div>
    </x-page-header>

    <div class="content-inner exchange-create-page">
        @if($errors->any())
            <div class="exchange-create-alert" role="alert">
                <strong>Exchange cannot be processed yet</strong>
                <ul>
                    @foreach($errors->all() as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="exchange-rule-notice" data-exchange-rule-notice role="status" aria-live="polite">
            <div>
                <strong>Approval rules for this exchange</strong>
                <p>{{ $approvalRuleCopy }}</p>
            </div>
            <button type="button" data-exchange-rule-close aria-label="Close approval rules notice">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 6l12 12M18 6L6 18"/>
                </svg>
            </button>
        </div>

        <form method="POST"
              action="{{ route('exchanges.unified.store', $invoice) }}"
              class="exchange-create-form"
              data-exchange-form
              novalidate>
            @csrf

            <div class="exchange-create-main-grid">
                <section class="exchange-create-card exchange-create-card--return" aria-labelledby="exchange-return-title">
                    <div class="exchange-create-section-head">
                        <div>
                            <p class="exchange-create-eyebrow">Original invoice</p>
                            <h2 id="exchange-return-title">Items being returned</h2>
                        </div>
                        <span class="exchange-create-count">{{ $invoice->items->count() }} line{{ $invoice->items->count() === 1 ? '' : 's' }}</span>
                    </div>
                    <p class="exchange-create-section-copy">Select only the items the customer is bringing back. Returned lines stay locked.</p>
                    <p class="exchange-create-inline-error" data-return-error hidden></p>

                    <div class="exchange-return-list">
                        @foreach($invoice->items as $idx => $line)
                            @php
                                $already = $line->returned_at !== null;
                                $lineTotal = (float) $line->line_total + (float) $line->gst_amount - (float) $line->allocated_discount + (float) $line->allocated_round_off;
                            @endphp

                            <article class="exchange-return-line {{ $already ? 'is-disabled' : '' }}" data-return-line>
                                <div class="exchange-return-line__select">
                                    @if($already)
                                        <span class="exchange-return-line__badge">Returned</span>
                                    @else
                                        <label class="exchange-return-check">
                                            <input type="checkbox"
                                                   name="lines[{{ $idx }}][selected]"
                                                   value="1"
                                                   class="line-checkbox"
                                                   data-line-idx="{{ $idx }}"
                                                   {{ old("lines.$idx.selected") ? 'checked' : '' }}>
                                            <span>Select</span>
                                        </label>
                                        <input type="hidden" name="lines[{{ $idx }}][invoice_item_id]" value="{{ $line->id }}">
                                        <input type="hidden" name="lines[{{ $idx }}][condition]" value="good_condition">
                                    @endif
                                </div>

                                <div class="exchange-return-line__item">
                                    <span class="exchange-return-line__barcode">{{ $line->item?->barcode ?? '—' }}</span>
                                    <strong>{{ $line->item?->design ?? $line->item?->category ?? 'Invoice item' }}</strong>
                                </div>

                                <div class="exchange-return-line__amount"
                                     data-return-total
                                     data-line-idx="{{ $idx }}"
                                     data-amount="{{ $lineTotal }}">
                                    <span>Refund value</span>
                                    <strong>₹{{ number_format($lineTotal, 2) }}</strong>
                                </div>

                                <div class="exchange-return-line__disposition">
                                    @if($already)
                                        <span class="exchange-return-line__locked">Already returned</span>
                                    @else
                                        <label for="line-disposition-{{ $idx }}">Disposition</label>
                                        <select id="line-disposition-{{ $idx }}" name="lines[{{ $idx }}][disposition]">
                                            @foreach($dispositions as $val => $label)
                                                <option value="{{ $val }}" @selected(old("lines.$idx.disposition", 'restocked') === $val)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                </div>

                                @can('returns.approve')
                                    @unless($already)
                                        @php
                                            $maxRefundable = $lineTotal;
                                        @endphp
                                        <div class="exchange-refund-options">
                                            <div class="exchange-refund-options__head">
                                                <span>Owner refund options</span>
                                                <small>Adjust only when policy needs an approved exception</small>
                                            </div>
                                            <div class="exchange-refund-options__body">
                                                <div class="exchange-refund-options__checks">
                                                    <label class="exchange-refund-check">
                                                        <input type="checkbox" name="lines[{{ $idx }}][override_making_charges]" value="1" @checked(old("lines.$idx.override_making_charges"))>
                                                        <span class="exchange-refund-check__box" aria-hidden="true"></span>
                                                        <span class="exchange-refund-check__text">Refund making charges</span>
                                                    </label>
                                                    <label class="exchange-refund-check">
                                                        <input type="checkbox" name="lines[{{ $idx }}][override_stone_charges]" value="1" @checked(old("lines.$idx.override_stone_charges"))>
                                                        <span class="exchange-refund-check__box" aria-hidden="true"></span>
                                                        <span class="exchange-refund-check__text">Refund stone charges</span>
                                                    </label>
                                                    <label class="exchange-refund-check">
                                                        <input type="checkbox" name="lines[{{ $idx }}][override_gst]" value="1" @checked(old("lines.$idx.override_gst"))>
                                                        <span class="exchange-refund-check__box" aria-hidden="true"></span>
                                                        <span class="exchange-refund-check__text">Refund GST</span>
                                                    </label>
                                                    <label class="exchange-refund-check">
                                                        <input type="checkbox" name="lines[{{ $idx }}][override_waive_restocking]" value="1" @checked(old("lines.$idx.override_waive_restocking"))>
                                                        <span class="exchange-refund-check__box" aria-hidden="true"></span>
                                                        <span class="exchange-refund-check__text">Waive restocking fee</span>
                                                    </label>
                                                </div>

                                                <div class="exchange-refund-options__grid">
                                                    <label>
                                                        <span>Custom wear loss %</span>
                                                        <input type="number"
                                                               name="lines[{{ $idx }}][override_wear_loss_pct]"
                                                               min="0"
                                                               max="25"
                                                               step="0.1"
                                                               value="{{ old("lines.$idx.override_wear_loss_pct") }}"
                                                               placeholder="0-25">
                                                    </label>
                                                    <label>
                                                        <span>Manual refund</span>
                                                        <input type="number"
                                                               name="lines[{{ $idx }}][override_manual_total]"
                                                               min="0"
                                                               max="{{ $maxRefundable }}"
                                                               step="0.01"
                                                               value="{{ old("lines.$idx.override_manual_total") }}"
                                                               placeholder="Max ₹{{ number_format($maxRefundable, 2) }}">
                                                    </label>
                                                </div>

                                                <label class="exchange-refund-options__reason">
                                                    <span>Override reason <em>required if adjusted</em></span>
                                                    <textarea name="lines[{{ $idx }}][override_reason]"
                                                              rows="2"
                                                              minlength="5"
                                                              maxlength="500"
                                                              data-reason-input
                                                              placeholder="Reason for approving this refund change">{{ old("lines.$idx.override_reason") }}</textarea>
                                                    <div class="exchange-reason-chips" data-reason-chips>
                                                        @foreach(['Approved by owner','Policy exception','Customer retention','Billing correction','Manual rate','Price commitment'] as $preset)
                                                            <button type="button"
                                                                    class="exchange-reason-chip"
                                                                    data-reason-chip="{{ $preset }}"
                                                                    data-reason-target="override">{{ $preset }}</button>
                                                        @endforeach
                                                    </div>
                                                </label>
                                            </div>
                                        </div>
                                    @endunless
                                @endcan
                            </article>
                        @endforeach
                    </div>
                </section>

                <aside class="exchange-create-side">
                    <section class="exchange-create-card exchange-create-card--sale" aria-labelledby="exchange-new-sale-title">
                        <div class="exchange-create-section-head">
                            <div>
                                <p class="exchange-create-eyebrow">Replacement sale</p>
                                <h2 id="exchange-new-sale-title">Add new item barcodes</h2>
                            </div>
                            <span class="exchange-create-count" data-barcode-count>0 items</span>
                        </div>
                        <p class="exchange-create-section-copy">Scan or type one code at a time. Press Enter or use Add. Review the chips before processing.</p>

                        <textarea name="new_item_barcodes"
                                  id="new_item_barcodes"
                                  class="exchange-barcode-hidden"
                                  aria-hidden="true">{{ old('new_item_barcodes') }}</textarea>

                        <div class="exchange-barcode-box">
                            <label for="exchange_barcode_entry">Barcode</label>
                            <div class="exchange-barcode-entry">
                                <input type="text"
                                       id="exchange_barcode_entry"
                                       data-barcode-entry
                                       inputmode="text"
                                       autocomplete="off"
                                       placeholder="Scan or type barcode">
                                <button type="button" data-barcode-add>Add</button>
                            </div>
                            <p class="exchange-create-inline-error" data-barcode-error hidden></p>
                            <div class="exchange-barcode-list" data-barcode-list aria-live="polite"></div>
                        </div>

                        <div class="exchange-sale-estimate">
                            <span>New sale estimate</span>
                            <strong id="newSaleEstimate">—</strong>
                            <small>Exact total is computed at submit using the current item price and GST.</small>
                        </div>
                    </section>

                    <section class="exchange-create-card exchange-create-card--summary" aria-label="Exchange summary">
                        <div class="exchange-summary-row">
                            <span>Return refund</span>
                            <strong class="is-refund">−₹<span id="returnEstimate">0.00</span></strong>
                        </div>
                        <div class="exchange-summary-row">
                            <span>New sale</span>
                            <strong id="newSaleEstimate2">—</strong>
                        </div>
                        <div class="exchange-summary-total">
                            <span>Net settlement</span>
                            <strong id="netLabel">—</strong>
                            <small id="netDirection"></small>
                        </div>
                    </section>
                </aside>
            </div>

            <section class="exchange-create-card exchange-create-card--settings" aria-labelledby="exchange-settings-title">
                <div class="exchange-create-section-head">
                    <div>
                        <p class="exchange-create-eyebrow">Settlement controls</p>
                        <h2 id="exchange-settings-title">Valuation and reason</h2>
                    </div>
                </div>

                <div class="exchange-settings-grid">
                    <div class="exchange-rate-group">
                        <span class="exchange-field-label">Valuation basis</span>
                        <div class="exchange-rate-options">
                            <label>
                                <input type="radio"
                                       id="basis_sale_day"
                                       name="valuation_basis_source"
                                       value="sale_day_rate"
                                       {{ old('valuation_basis_source', $defaultBasis) === 'sale_day_rate' ? 'checked' : '' }}
                                       onchange="toggleOverrideSection(this.value)">
                                <span>
                                    <strong>Sale-day rate{{ $defaultBasis === 'sale_day_rate' ? ' (default)' : '' }}</strong>
                                    <small>Value returned items at the rate they were originally sold.</small>
                                </span>
                            </label>
                            <label>
                                <input type="radio"
                                       id="basis_today"
                                       name="valuation_basis_source"
                                       value="today_rate"
                                       {{ old('valuation_basis_source', $defaultBasis) === 'today_rate' ? 'checked' : '' }}
                                       onchange="toggleOverrideSection(this.value)">
                                <span>
                                    <strong>Today's rate{{ $defaultBasis === 'today_rate' ? ' (default)' : '' }}</strong>
                                    <small>Use today's shop daily gold rate.</small>
                                </span>
                            </label>

                            @can('exchanges.override_rate')
                                @unless($shop->preferences?->exchange_rate_basis_locked ?? false)
                                    <label>
                                        <input type="radio"
                                               id="basis_manual"
                                               name="valuation_basis_source"
                                               value="manual_override"
                                               {{ old('valuation_basis_source') === 'manual_override' ? 'checked' : '' }}
                                               onchange="toggleOverrideSection(this.value)">
                                        <span>
                                            <strong>Manual rate override</strong>
                                            <small>Owner permission required. Must stay within the allowed rate band.</small>
                                        </span>
                                    </label>
                                @endunless
                            @endcan
                        </div>

                        <div id="override-rate-section" class="exchange-manual-rate {{ old('valuation_basis_source') === 'manual_override' ? '' : 'hidden' }}">
                            <label>
                                <span>Override rate (₹/gram, 24K)</span>
                                <input type="number"
                                       name="gold_rate_per_gram_override"
                                       step="0.01"
                                       min="0"
                                       value="{{ old('gold_rate_per_gram_override') }}"
                                       placeholder="0.00">
                            </label>
                            <label>
                                <span>Reason for rate override</span>
                                <input type="text"
                                       name="override_reason"
                                       maxlength="500"
                                       value="{{ old('override_reason') }}"
                                       placeholder="Agreed rate from prior negotiation">
                            </label>
                            @error('gold_rate_per_gram_override')<p class="exchange-create-inline-error">{{ $message }}</p>@enderror
                            @error('override_reason')<p class="exchange-create-inline-error">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <label class="exchange-reason-field">
                        <span>Exchange reason <em>*</em></span>
                        <textarea name="reason"
                                  rows="4"
                                  required
                                  minlength="5"
                                  maxlength="500"
                                  data-reason-input
                                  placeholder="e.g. Customer upgraded to a bigger chain">{{ old('reason') }}</textarea>
                        <div class="exchange-reason-chips" data-reason-chips>
                            @foreach(['Customer upgraded item','Wrong size','Changed design','Damaged item','Billing correction','Quality issue','Wrong item delivered'] as $preset)
                                <button type="button"
                                        class="exchange-reason-chip"
                                        data-reason-chip="{{ $preset }}"
                                        data-reason-target="exchange">{{ $preset }}</button>
                            @endforeach
                        </div>
                    </label>
                    <p class="exchange-create-inline-error" data-reason-error hidden></p>
                </div>
            </section>

            <div class="exchange-create-actions">
                <a href="{{ route('invoices.show', $invoice) }}" class="exchange-create-cancel" data-turbo-frame="_top">Cancel</a>
                <button type="submit" class="exchange-create-submit">Process exchange</button>
            </div>
        </form>
    </div>

    <script>
        function toggleOverrideSection(value) {
            const section = document.getElementById('override-rate-section');
            if (section) {
                section.classList.toggle('hidden', value !== 'manual_override');
            }
        }

        (() => {
            const initExchangeRuleUi = () => {
                document.querySelectorAll('[data-exchange-rule-help]').forEach((help) => {
                    if (help.dataset.exchangeRuleReady === '1') return;
                    help.dataset.exchangeRuleReady = '1';

                    const trigger = help.querySelector('[data-exchange-rule-trigger]');
                    const popover = help.querySelector('.exchange-rule-popover');
                    if (!trigger || !popover) return;
                    let pinned = false;

                    const open = () => {
                        popover.hidden = false;
                        trigger.setAttribute('aria-expanded', 'true');
                    };
                    const close = () => {
                        pinned = false;
                        popover.hidden = true;
                        trigger.setAttribute('aria-expanded', 'false');
                    };

                    trigger.addEventListener('click', (event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        if (pinned && !popover.hidden) {
                            close();
                        } else {
                            pinned = true;
                            open();
                        }
                    });
                    help.addEventListener('mouseenter', () => {
                        if (!pinned) open();
                    });
                    help.addEventListener('mouseleave', () => {
                        if (!pinned) close();
                    });
                    document.addEventListener('click', (event) => {
                        if (!help.contains(event.target)) close();
                    });
                    document.addEventListener('keydown', (event) => {
                        if (event.key === 'Escape') close();
                    });
                });

                document.querySelectorAll('[data-exchange-rule-notice]').forEach((notice) => {
                    if (notice.dataset.exchangeNoticeReady === '1') return;
                    notice.dataset.exchangeNoticeReady = '1';
                    notice.hidden = false;

                    notice.querySelector('[data-exchange-rule-close]')?.addEventListener('click', () => {
                        notice.hidden = true;
                    });
                });
            };

            const initExchangeForm = () => {
                initExchangeRuleUi();

                document.querySelectorAll('[data-exchange-form]').forEach((form) => {
                    if (form.dataset.exchangeReady === '1') return;
                    form.dataset.exchangeReady = '1';

                    const checkboxes = Array.from(form.querySelectorAll('.line-checkbox'));
                    const barcodes = form.querySelector('#new_item_barcodes');
                    const barcodeEntry = form.querySelector('[data-barcode-entry]');
                    const barcodeAdd = form.querySelector('[data-barcode-add]');
                    const barcodeList = form.querySelector('[data-barcode-list]');
                    const barcodeCount = form.querySelector('[data-barcode-count]');
                    const barcodeError = form.querySelector('[data-barcode-error]');
                    const returnError = form.querySelector('[data-return-error]');
                    const reasonError = form.querySelector('[data-reason-error]');
                    const reason = form.querySelector('[name="reason"]');
                    const returnEst = form.querySelector('#returnEstimate');
                    const newSaleEst = form.querySelector('#newSaleEstimate');
                    const newSaleEst2 = form.querySelector('#newSaleEstimate2');
                    const netLabel = form.querySelector('#netLabel');
                    const netDir = form.querySelector('#netDirection');
                    const barcodePattern = /^[A-Za-z0-9._/-]+$/;
                    const barcodeLookupBase = @json(url('/api/item-by-barcode'));
                    let barcodeTokens = [];
                    const barcodeDetails = new Map();
                    const barcodeLookupInFlight = new Set();

                    const fmt = (n) => Number(n).toLocaleString('en-IN', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2,
                    });
                    const money = (n) => Number(n || 0).toLocaleString('en-IN', {
                        style: 'currency',
                        currency: 'INR',
                        maximumFractionDigits: 2,
                    });
                    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (ch) => ({
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        '"': '&quot;',
                        "'": '&#039;',
                    }[ch]));
                    const escapeAttr = (value) => escapeHtml(value).replace(/`/g, '&#096;');

                    const parseBarcodeText = (value) => (value || '')
                        .split(/[\s,]+/)
                        .map((code) => code.trim())
                        .filter(Boolean);

                    const setError = (node, message) => {
                        if (!node) return;
                        node.textContent = message || '';
                        node.hidden = !message;
                    };

                    const splitPhrases = (value) => (value || '')
                        .split(',')
                        .map((phrase) => phrase.trim())
                        .filter(Boolean);

                    const syncReasonChips = (wrap, input) => {
                        const phrases = splitPhrases(input.value);
                        wrap.querySelectorAll('[data-reason-chip]').forEach((chip) => {
                            chip.classList.toggle('is-active', phrases.includes(chip.dataset.reasonChip));
                        });
                    };

                    form.querySelectorAll('[data-reason-chips]').forEach((wrap) => {
                        const field = wrap.closest('label') || wrap.parentElement;
                        const input = field ? field.querySelector('[data-reason-input]') : null;
                        if (!input) return;

                        syncReasonChips(wrap, input);
                        wrap.querySelectorAll('[data-reason-chip]').forEach((chip) => {
                            chip.addEventListener('click', () => {
                                const phrase = chip.dataset.reasonChip;
                                const phrases = splitPhrases(input.value);
                                const at = phrases.indexOf(phrase);
                                if (at === -1) {
                                    phrases.push(phrase);
                                } else {
                                    phrases.splice(at, 1);
                                }
                                input.value = phrases.join(', ');
                                input.dispatchEvent(new Event('input', { bubbles: true }));
                                syncReasonChips(wrap, input);
                            });
                        });
                        input.addEventListener('input', () => syncReasonChips(wrap, input));
                    });

                    const recompute = () => {
                        let returnTotal = 0;
                        checkboxes.forEach((cb) => {
                            if (!cb.checked) return;
                            const cell = form.querySelector(`[data-return-total][data-line-idx="${cb.dataset.lineIdx}"]`);
                            if (cell) returnTotal += parseFloat(cell.dataset.amount || '0');
                        });

                        returnEst.textContent = fmt(returnTotal);
                        newSaleEst.textContent = barcodeTokens.length > 0
                            ? `${barcodeTokens.length} item${barcodeTokens.length === 1 ? '' : 's'} - priced at submit`
                            : '-';
                        newSaleEst2.textContent = barcodeTokens.length > 0 ? 'Priced at submit' : '-';
                        netLabel.textContent = barcodeTokens.length > 0 ? 'Computed at submit' : '-';
                        netDir.textContent = barcodeTokens.length > 0
                            ? 'Server checks stock, price, GST, and barcode validity before settlement.'
                            : '';
                    };

                    const barcodeTitle = (item, code) => {
                        if (!item) return code;
                        return item.design
                            || [item.category, item.sub_category].filter(Boolean).join(' / ')
                            || code;
                    };

                    const barcodeMeta = (item) => {
                        if (!item) return [];
                        return [
                            item.category,
                            item.sub_category,
                            item.purity ? `${item.purity}K` : '',
                            item.gross_weight ? `${Number(item.gross_weight).toFixed(3)}g gross` : '',
                            item.weight ? `${Number(item.weight).toFixed(3)}g net` : '',
                            item.status ? String(item.status).replace(/_/g, ' ') : '',
                        ].filter(Boolean);
                    };

                    const renderBarcodeChip = (code) => {
                        const detail = barcodeDetails.get(code);
                        const safeCode = escapeHtml(code);
                        const safeCodeAttr = escapeAttr(code);
                        const removeButton = `<button type="button" data-remove-barcode="${safeCodeAttr}" aria-label="Remove ${safeCodeAttr}">Remove</button>`;

                        if (!detail || detail.state === 'loading') {
                            return (
                                `<article class="exchange-barcode-chip exchange-barcode-chip--detail is-loading" data-barcode-item-detail data-barcode="${safeCodeAttr}">` +
                                    `<div class="exchange-barcode-chip__main">` +
                                        `<strong>${safeCode}</strong>` +
                                        `<span>Checking item details...</span>` +
                                    `</div>` +
                                    removeButton +
                                `</article>`
                            );
                        }

                        if (detail.state === 'error') {
                            return (
                                `<article class="exchange-barcode-chip exchange-barcode-chip--detail is-error" data-barcode-item-detail data-barcode="${safeCodeAttr}">` +
                                    `<div class="exchange-barcode-chip__main">` +
                                        `<strong>${safeCode}</strong>` +
                                        `<span>${escapeHtml(detail.message || 'Item not found. Check the barcode before processing.')}</span>` +
                                    `</div>` +
                                    removeButton +
                                `</article>`
                            );
                        }

                        const item = detail.item || {};
                        const meta = barcodeMeta(item);
                        const price = item.selling_price ? money(item.selling_price) : '';
                        return (
                            `<article class="exchange-barcode-chip exchange-barcode-chip--detail" data-barcode-item-detail data-barcode="${safeCodeAttr}">` +
                                `<div class="exchange-barcode-chip__main">` +
                                    `<strong>${escapeHtml(barcodeTitle(item, code))}</strong>` +
                                    `<span>${safeCode}</span>` +
                                `</div>` +
                                `<div class="exchange-barcode-chip__meta">` +
                                    meta.map((entry) => `<small>${escapeHtml(entry)}</small>`).join('') +
                                `</div>` +
                                (price ? `<b>${escapeHtml(price)}</b>` : '') +
                                removeButton +
                            `</article>`
                        );
                    };

                    const lookupBarcodeDetail = async (code) => {
                        if (!code || barcodeDetails.has(code) || barcodeLookupInFlight.has(code)) return;

                        barcodeLookupInFlight.add(code);
                        try {
                            const response = await fetch(`${barcodeLookupBase}/${encodeURIComponent(code)}`, {
                                headers: {
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                            });

                            if (!response.ok) {
                                barcodeDetails.set(code, {
                                    state: 'error',
                                    message: response.status === 404
                                        ? 'Item not found. Check the barcode before processing.'
                                        : 'Could not load item details. The server will verify this barcode.',
                                });
                            } else {
                                const item = await response.json();
                                barcodeDetails.set(code, { state: 'loaded', item });
                            }
                        } catch (error) {
                            barcodeDetails.set(code, {
                                state: 'error',
                                message: 'Could not load item details. The server will verify this barcode.',
                            });
                        } finally {
                            barcodeLookupInFlight.delete(code);
                            renderBarcodes();
                        }
                    };

                    const renderBarcodes = () => {
                        barcodes.value = barcodeTokens.join(', ');
                        barcodeCount.textContent = `${barcodeTokens.length} item${barcodeTokens.length === 1 ? '' : 's'}`;

                        if (barcodeTokens.length === 0) {
                            barcodeList.innerHTML = '<p class="exchange-barcode-empty">No replacement items added yet.</p>';
                        } else {
                            barcodeList.innerHTML = barcodeTokens.map(renderBarcodeChip).join('');
                            barcodeTokens.forEach((code) => lookupBarcodeDetail(code));
                        }
                        recompute();
                    };

                    const addBarcodes = (value) => {
                        const codes = parseBarcodeText(value);
                        if (codes.length === 0) {
                            setError(barcodeError, 'Enter a barcode before adding.');
                            return;
                        }

                        const invalid = codes.filter((code) => !barcodePattern.test(code));
                        if (invalid.length > 0) {
                            setError(barcodeError, `Barcode contains unsupported characters: ${invalid.join(', ')}`);
                            return;
                        }

                        let added = 0;
                        codes.forEach((code) => {
                            if (!barcodeTokens.includes(code)) {
                                barcodeTokens.push(code);
                                added += 1;
                            }
                        });

                        setError(barcodeError, added === 0 ? 'This barcode is already in the replacement list.' : '');
                        barcodeEntry.value = '';
                        renderBarcodes();
                    };

                    barcodeTokens = parseBarcodeText(barcodes.value);
                    renderBarcodes();

                    checkboxes.forEach((cb) => cb.addEventListener('change', () => {
                        setError(returnError, '');
                        recompute();
                    }));

                    barcodeAdd.addEventListener('click', () => addBarcodes(barcodeEntry.value));
                    barcodeEntry.addEventListener('keydown', (event) => {
                        if (event.key === 'Enter' || event.key === ',') {
                            event.preventDefault();
                            addBarcodes(barcodeEntry.value);
                        }
                    });
                    barcodeEntry.addEventListener('paste', () => {
                        window.setTimeout(() => {
                            if (/[\s,]+/.test(barcodeEntry.value)) addBarcodes(barcodeEntry.value);
                        }, 0);
                    });
                    barcodeList.addEventListener('click', (event) => {
                        const button = event.target.closest('[data-remove-barcode]');
                        if (!button) return;
                        barcodeTokens = barcodeTokens.filter((code) => code !== button.dataset.removeBarcode);
                        barcodeDetails.delete(button.dataset.removeBarcode);
                        setError(barcodeError, '');
                        renderBarcodes();
                    });

                    form.addEventListener('submit', (event) => {
                        setError(returnError, '');
                        setError(barcodeError, '');
                        setError(reasonError, '');

                        if (!checkboxes.some((cb) => cb.checked)) {
                            event.preventDefault();
                            setError(returnError, 'Select at least one item from the original invoice.');
                            checkboxes[0]?.focus();
                            return;
                        }

                        if (barcodeTokens.length === 0) {
                            event.preventDefault();
                            setError(barcodeError, 'Add at least one replacement item barcode.');
                            barcodeEntry.focus();
                            return;
                        }

                        if (!reason.value.trim() || reason.value.trim().length < 5) {
                            event.preventDefault();
                            setError(reasonError, 'Enter an exchange reason with at least 5 characters.');
                            reason.focus();
                            return;
                        }

                        checkboxes.forEach((cb) => {
                            if (!cb.checked) {
                                const idx = cb.dataset.lineIdx;
                                form.querySelectorAll(`[name^="lines[${idx}]"]`).forEach((el) => {
                                    el.disabled = true;
                                });
                            }
                        });
                    });
                });
            };

            document.addEventListener('DOMContentLoaded', initExchangeForm);
            document.addEventListener('turbo:load', initExchangeForm);
            initExchangeForm();
        })();
    </script>
</x-app-layout>
