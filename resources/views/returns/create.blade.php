@php
    $customerName = $invoice->customer
        ? trim($invoice->customer->first_name . ' ' . $invoice->customer->last_name)
        : null;
    $shopPolicy = $shopPolicy ?? auth()->user()->shop?->preferences;
    $returnableLines = $invoice->items->filter(fn ($line) => $line->returned_at === null);
    $returnedLines = $invoice->items->filter(fn ($line) => $line->returned_at !== null);
    $estimatedRefundTotal = $returnableLines->sum(function ($line) use ($policyBreakdowns) {
        $policyResult = $policyBreakdowns[$line->id] ?? null;

        return $policyResult
            ? (float) $policyResult->refundTotal
            : ((float) $line->line_total + (float) $line->gst_amount - (float) $line->allocated_discount + (float) $line->allocated_round_off);
    });
    $hasDeductions = $shopPolicy && (
        !($shopPolicy->refund_making_charges ?? true) ||
        !($shopPolicy->refund_stone_charges ?? true) ||
        !($shopPolicy->refund_gst ?? true) ||
        ($shopPolicy->wear_loss_pct ?? 0) > 0 ||
        ($shopPolicy->restocking_fee_pct ?? 0) > 0
    );
    $settlementModeLabel = match ($allowedSettlement ?? 'cash_or_credit') {
        'cash_only' => 'Cash only',
        'store_credit_only' => 'Store credit only',
        default => 'Cash or store credit',
    };
@endphp

<x-app-layout>
    <x-page-header
        class="returns-create-header"
        :title="'Process return'"
        :subtitle="$invoice->invoice_number . ' · ' . ($customerName ?: 'No customer linked')">
        <x-slot:actions>
            <a href="{{ route('invoices.show', $invoice) }}"
               class="returns-create-back-btn"
               aria-label="Back to invoice">
                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <line x1="19" y1="12" x2="5" y2="12" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/>
                    <polyline points="12 19 5 12 12 5" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/>
                </svg>
                <span>Back to invoice</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner returns-create-page">
        <x-return-policy-banner />

        @if($errors->any())
            <section class="returns-create-alert returns-create-alert--danger" role="alert">
                <strong>Review the return details</strong>
                <ul>
                    @foreach($errors->all() as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="returns-create-summary" aria-label="Return context">
            <article>
                <span>Invoice</span>
                <strong>{{ $invoice->invoice_number }}</strong>
                <small>{{ optional($invoice->finalized_at ?? $invoice->created_at)->format('d M Y') }}</small>
            </article>
            <article>
                <span>Customer</span>
                <strong>{{ $customerName ?: 'Walk-in' }}</strong>
                <small>{{ $invoice->customer?->mobile ?: 'No mobile number' }}</small>
            </article>
            <article>
                <span>Returnable lines</span>
                <strong>{{ $returnableLines->count() }}</strong>
                <small>{{ $returnedLines->count() }} already returned</small>
            </article>
            <article class="returns-create-summary__refund">
                <span>Estimated refund</span>
                <strong>₹{{ number_format($estimatedRefundTotal, 2) }}</strong>
                <small>If all returnable lines are selected</small>
            </article>
        </section>

        @if($hasDeductions)
            <section class="returns-create-policy" aria-label="Active return policy">
                <div>
                    <h2>Active return policy</h2>
                    <p>Refund estimates already include policy deductions.</p>
                </div>
                <ul>
                    @if(!($shopPolicy->refund_making_charges ?? true))
                        <li>Making charges not refundable</li>
                    @endif
                    @if(!($shopPolicy->refund_stone_charges ?? true))
                        <li>Stone charges not refundable</li>
                    @endif
                    @if(!($shopPolicy->refund_gst ?? true))
                        <li>GST not refunded</li>
                    @endif
                    @if(($shopPolicy->wear_loss_pct ?? 0) > 0)
                        <li>Wear loss {{ $shopPolicy->wear_loss_pct }}%</li>
                    @endif
                    @if(($shopPolicy->restocking_fee_pct ?? 0) > 0)
                        <li>Restocking fee {{ $shopPolicy->restocking_fee_pct }}%</li>
                    @endif
                </ul>
            </section>
        @endif

        <form method="POST"
              action="{{ route('returns.store', $invoice) }}"
              class="returns-create-form"
              onsubmit="return confirm('Process this return? A credit note will be issued.');">
            @csrf

            <div class="returns-create-layout">
                <main class="returns-create-main" aria-label="Invoice lines">
                    <section class="returns-create-card returns-create-lines">
                        <div class="returns-create-section-head">
                            <div>
                                <h2>Items on this invoice</h2>
                                <p>Select only the items the customer is returning.</p>
                            </div>
                            <label class="returns-create-select-all">
                                <input type="checkbox" id="selectAll">
                                <span>Select all returnable</span>
                            </label>
                        </div>

                        <div class="returns-create-line-list">
                            @foreach($invoice->items as $idx => $line)
                                @php
                                    $alreadyReturned = $line->returned_at !== null;
                                    $policyResult = $policyBreakdowns[$line->id] ?? null;
                                    $originalRefundBase = (float) $line->line_total + (float) $line->gst_amount - (float) $line->allocated_discount + (float) $line->allocated_round_off;
                                    $refundEstimate = $policyResult ? (float) $policyResult->refundTotal : $originalRefundBase;
                                    $lineHasDeductions = $policyResult && abs($policyResult->refundTotal - $originalRefundBase) > 0.005;
                                    $isSelected = (bool) old("lines.$idx.selected");
                                @endphp

                                <article class="returns-create-line {{ $alreadyReturned ? 'is-returned' : '' }} {{ $isSelected ? 'is-selected' : '' }}"
                                         data-return-line-card
                                         data-return-estimate="{{ $alreadyReturned ? '0' : $refundEstimate }}">
                                    <div class="returns-create-line__top">
                                        <div class="returns-create-line__select">
                                            @if($alreadyReturned)
                                                <span class="returns-create-returned-badge">Returned</span>
                                            @else
                                                <label>
                                                    <input type="checkbox"
                                                           name="lines[{{ $idx }}][selected]"
                                                           value="1"
                                                           class="line-checkbox"
                                                           data-line-idx="{{ $idx }}"
                                                           @checked($isSelected)>
                                                    <span>Return</span>
                                                </label>
                                                <input type="hidden"
                                                       name="lines[{{ $idx }}][invoice_item_id]"
                                                       value="{{ $line->id }}"
                                                       data-return-line-control="{{ $idx }}"
                                                       @disabled(!$isSelected)>
                                            @endif
                                        </div>

                                        <div class="returns-create-line__identity">
                                            <strong>{{ $line->item?->barcode ?? 'Item #' . $line->id }}</strong>
                                            <span>{{ $line->item?->design ?? $line->item?->category ?? 'No item description' }}</span>
                                        </div>

                                        <dl class="returns-create-line__money">
                                            <div>
                                                <dt>Line total</dt>
                                                <dd>₹{{ number_format((float) $line->line_total, 2) }}</dd>
                                            </div>
                                            <div class="{{ $lineHasDeductions ? 'has-deduction' : 'has-full-refund' }}">
                                                <dt>Refund estimate</dt>
                                                <dd>₹{{ number_format($refundEstimate, 2) }}</dd>
                                            </div>
                                        </dl>
                                    </div>

                                    @if(!$alreadyReturned)
                                        <div class="returns-create-line__controls">
                                            <label>
                                                <span>Condition</span>
                                                <select name="lines[{{ $idx }}][condition]"
                                                        required
                                                        data-return-line-control="{{ $idx }}"
                                                        @disabled(!$isSelected)>
                                                    <option value="" disabled {{ old("lines.$idx.condition") ? '' : 'selected' }}>Select condition</option>
                                                    @foreach($conditions as $val => $label)
                                                        <option value="{{ $val }}" @selected(old("lines.$idx.condition") === $val)>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </label>

                                            <label>
                                                <span>Destination</span>
                                                <select name="lines[{{ $idx }}][disposition]"
                                                        data-return-line-control="{{ $idx }}"
                                                        @disabled(!$isSelected)>
                                                    @foreach($dispositions as $val => $label)
                                                        <option value="{{ $val }}" @selected(old("lines.$idx.disposition") === $val)>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </label>

                                            <label>
                                                <span>Line note <small>optional</small></span>
                                                <input type="text"
                                                       name="lines[{{ $idx }}][reason]"
                                                       value="{{ old("lines.$idx.reason") }}"
                                                       maxlength="255"
                                                       placeholder="e.g. wrong size"
                                                       data-return-line-control="{{ $idx }}"
                                                       @disabled(!$isSelected)>
                                            </label>
                                        </div>

                                        <div class="returns-create-line__details">
                                            @if(isset($policyBreakdowns[$line->id]))
                                                @php $bd = $policyBreakdowns[$line->id]; @endphp
                                                <details class="returns-create-details">
                                                    <summary>Refund breakdown</summary>
                                                    <dl>
                                                        <div><dt>Original</dt><dd>₹{{ number_format($bd->breakdown['original_line_total'] ?? 0, 2) }}</dd></div>
                                                        @if(($bd->breakdown['making_retained'] ?? 0) > 0)
                                                            <div class="is-negative"><dt>Making retained</dt><dd>-₹{{ number_format($bd->breakdown['making_retained'], 2) }}</dd></div>
                                                        @endif
                                                        @if(($bd->breakdown['stone_retained'] ?? 0) > 0)
                                                            <div class="is-negative"><dt>Stone retained</dt><dd>-₹{{ number_format($bd->breakdown['stone_retained'], 2) }}</dd></div>
                                                        @endif
                                                        @if(($bd->breakdown['gst_charged'] ?? 0) - ($bd->breakdown['gst_refunded'] ?? 0) > 0.005)
                                                            <div class="is-negative"><dt>GST retained</dt><dd>-₹{{ number_format($bd->breakdown['gst_charged'] - $bd->breakdown['gst_refunded'], 2) }}</dd></div>
                                                        @endif
                                                        @if(($bd->breakdown['wear_loss_amount'] ?? 0) > 0)
                                                            <div class="is-negative"><dt>Wear loss</dt><dd>-₹{{ number_format($bd->breakdown['wear_loss_amount'], 2) }}</dd></div>
                                                        @endif
                                                        @if(($bd->breakdown['restocking_fee_amount'] ?? 0) > 0)
                                                            <div class="is-negative"><dt>Restock fee</dt><dd>-₹{{ number_format($bd->breakdown['restocking_fee_amount'], 2) }}</dd></div>
                                                        @endif
                                                        <div class="is-total"><dt>Refund</dt><dd>₹{{ number_format($bd->refundTotal, 2) }}</dd></div>
                                                    </dl>
                                                </details>
                                            @endif

                                            @can('returns.approve')
                                                @php $maxRefundable = $originalRefundBase; @endphp
                                                <details class="returns-create-details returns-create-adjustments">
                                                    <summary>Adjust refund</summary>
                                                    <div class="returns-create-adjustment-grid">
                                                        <label>
                                                            <input type="checkbox"
                                                                   name="lines[{{ $idx }}][override_making_charges]"
                                                                   value="1"
                                                                   data-return-line-control="{{ $idx }}"
                                                                   @checked(old("lines.$idx.override_making_charges"))
                                                                   @disabled(!$isSelected)>
                                                            <span>Refund making charges</span>
                                                        </label>
                                                        <label>
                                                            <input type="checkbox"
                                                                   name="lines[{{ $idx }}][override_stone_charges]"
                                                                   value="1"
                                                                   data-return-line-control="{{ $idx }}"
                                                                   @checked(old("lines.$idx.override_stone_charges"))
                                                                   @disabled(!$isSelected)>
                                                            <span>Refund stone charges</span>
                                                        </label>
                                                        <label>
                                                            <input type="checkbox"
                                                                   name="lines[{{ $idx }}][override_gst]"
                                                                   value="1"
                                                                   data-return-line-control="{{ $idx }}"
                                                                   @checked(old("lines.$idx.override_gst"))
                                                                   @disabled(!$isSelected)>
                                                            <span>Refund GST</span>
                                                        </label>
                                                        <label>
                                                            <input type="checkbox"
                                                                   name="lines[{{ $idx }}][override_waive_restocking]"
                                                                   value="1"
                                                                   data-return-line-control="{{ $idx }}"
                                                                   @checked(old("lines.$idx.override_waive_restocking"))
                                                                   @disabled(!$isSelected)>
                                                            <span>Waive restocking fee</span>
                                                        </label>
                                                    </div>

                                                    <div class="returns-create-adjustment-fields">
                                                        <label>
                                                            <span>Wear loss %</span>
                                                            <input type="number"
                                                                   name="lines[{{ $idx }}][override_wear_loss_pct]"
                                                                   min="0"
                                                                   max="25"
                                                                   step="0.1"
                                                                   value="{{ old("lines.$idx.override_wear_loss_pct") }}"
                                                                   placeholder="0-25"
                                                                   data-return-line-control="{{ $idx }}"
                                                                   @disabled(!$isSelected)>
                                                        </label>
                                                        <label>
                                                            <span>Manual refund <small>max ₹{{ number_format($maxRefundable, 2) }}</small></span>
                                                            <input type="number"
                                                                   name="lines[{{ $idx }}][override_manual_total]"
                                                                   min="0"
                                                                   max="{{ $maxRefundable }}"
                                                                   step="0.01"
                                                                   value="{{ old("lines.$idx.override_manual_total") }}"
                                                                   placeholder="0.00"
                                                                   data-return-line-control="{{ $idx }}"
                                                                   @disabled(!$isSelected)>
                                                        </label>
                                                        <label class="returns-create-adjustment-fields__wide">
                                                            <span>Override reason <small>required if adjusted</small></span>
                                                            <textarea name="lines[{{ $idx }}][override_reason]"
                                                                      rows="2"
                                                                      minlength="5"
                                                                      maxlength="500"
                                                                      placeholder="Reason for manual adjustment"
                                                                      data-return-line-control="{{ $idx }}"
                                                                      @disabled(!$isSelected)>{{ old("lines.$idx.override_reason") }}</textarea>
                                                        </label>
                                                    </div>
                                                </details>
                                            @endcan
                                        </div>
                                    @endif
                                </article>
                            @endforeach
                        </div>
                    </section>
                </main>

                <aside class="returns-create-rail" aria-label="Return details">
                    <section class="returns-create-card returns-create-submit-card">
                        <div class="returns-create-section-head returns-create-section-head--stacked">
                            <div>
                                <h2>Return details</h2>
                                <p>Credit note and settlement are created after submission.</p>
                            </div>
                        </div>

                        <label class="returns-create-field">
                            <span>Reason for return <small>required</small></span>
                            <textarea name="reason"
                                      required
                                      minlength="5"
                                      maxlength="500"
                                      rows="4"
                                      placeholder="e.g. Wrong size, damaged item, customer changed mind">{{ old('reason') }}</textarea>
                            @error('reason')<small class="returns-create-error">{{ $message }}</small>@enderror
                        </label>

                        <fieldset class="returns-create-settlement">
                            <legend>Refund settlement</legend>
                            <p>{{ $settlementModeLabel }}</p>

                            @if(in_array($allowedSettlement ?? 'cash_or_credit', ['cash_or_credit', 'cash_only']))
                                <label>
                                    <input type="radio"
                                           name="refund_settlement"
                                           value="cash"
                                           @checked(old('refund_settlement', 'cash') === 'cash')>
                                    <span>
                                        <strong>Cash</strong>
                                        <small>Refund the credit note amount now.</small>
                                    </span>
                                </label>
                            @endif

                            @if(in_array($allowedSettlement ?? 'cash_or_credit', ['cash_or_credit', 'store_credit_only']))
                                <label class="{{ !$invoice->customer_id ? 'is-disabled' : '' }}">
                                    <input type="radio"
                                           name="refund_settlement"
                                           value="store_credit"
                                           @checked(old('refund_settlement', ($allowedSettlement === 'store_credit_only' ? 'store_credit' : '')) === 'store_credit')
                                           @disabled(!$invoice->customer_id)>
                                    <span>
                                        <strong>Store credit</strong>
                                        <small>
                                            @if($invoice->customer_id)
                                                Credit the customer's wallet for future purchase.
                                            @else
                                                Unavailable without a linked customer.
                                            @endif
                                        </small>
                                    </span>
                                </label>
                            @endif

                            @error('refund_settlement')<small class="returns-create-error">{{ $message }}</small>@enderror
                            @error('approval')<small class="returns-create-error">{{ $message }}</small>@enderror
                            @error('override')<small class="returns-create-error">{{ $message }}</small>@enderror
                            @error('return')<small class="returns-create-error">{{ $message }}</small>@enderror
                        </fieldset>

                        <dl class="returns-create-selected-summary">
                            <div>
                                <dt>Selected lines</dt>
                                <dd><span data-selected-count>0</span> / {{ $returnableLines->count() }}</dd>
                            </div>
                            <div>
                                <dt>Estimated selected refund</dt>
                                <dd>₹<span data-selected-refund>0.00</span></dd>
                            </div>
                        </dl>

                        <div class="returns-create-actions">
                            <a href="{{ route('invoices.show', $invoice) }}" class="returns-create-secondary-action">
                                Cancel
                            </a>
                            <button type="submit" class="returns-create-primary-action" data-return-submit>
                                Process return
                            </button>
                        </div>
                    </section>
                </aside>
            </div>
        </form>
    </div>

    <script>
        function initReturnsCreate() {
            const form = document.querySelector('form[action="{{ route('returns.store', $invoice) }}"]');
            // Idempotent: Turbo can fire both DOMContentLoaded and turbo:load; bind once.
            if (!form || form.dataset.returnsInit) {
                return;
            }
            form.dataset.returnsInit = '1';

            const selectAll = document.getElementById('selectAll');
            const checkboxes = Array.from(document.querySelectorAll('.line-checkbox'));
            const selectedCount = document.querySelector('[data-selected-count]');
            const selectedRefund = document.querySelector('[data-selected-refund]');
            const submitButton = document.querySelector('[data-return-submit]');

            const money = new Intl.NumberFormat('en-IN', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });

            const setLineEnabled = (checkbox) => {
                const idx = checkbox.dataset.lineIdx;
                const enabled = checkbox.checked;
                const card = checkbox.closest('[data-return-line-card]');

                if (card) {
                    card.classList.toggle('is-selected', enabled);
                }

                if (!form) {
                    return;
                }

                form.querySelectorAll(`[data-return-line-control="${idx}"]`).forEach((el) => {
                    el.disabled = !enabled;
                });
            };

            const updateSummary = () => {
                let count = 0;
                let refund = 0;

                checkboxes.forEach((checkbox) => {
                    setLineEnabled(checkbox);

                    if (checkbox.checked) {
                        count += 1;
                        const card = checkbox.closest('[data-return-line-card]');
                        refund += Number(card?.dataset.returnEstimate || 0);
                    }
                });

                if (selectedCount) {
                    selectedCount.textContent = String(count);
                }
                if (selectedRefund) {
                    selectedRefund.textContent = money.format(refund);
                }
                if (submitButton) {
                    submitButton.disabled = count === 0;
                    submitButton.classList.toggle('is-disabled', count === 0);
                }
                if (selectAll) {
                    selectAll.checked = checkboxes.length > 0 && count === checkboxes.length;
                    selectAll.indeterminate = count > 0 && count < checkboxes.length;
                }
            };

            if (selectAll) {
                selectAll.addEventListener('change', (event) => {
                    checkboxes.forEach((checkbox) => {
                        checkbox.checked = event.target.checked;
                    });
                    updateSummary();
                });
            }

            checkboxes.forEach((checkbox) => {
                checkbox.addEventListener('change', updateSummary);
            });

            if (form) {
                form.addEventListener('submit', () => {
                    checkboxes.forEach((checkbox) => {
                        if (!checkbox.checked) {
                            const idx = checkbox.dataset.lineIdx;
                            form.querySelectorAll(`[data-return-line-control="${idx}"]`).forEach((el) => {
                                el.disabled = true;
                            });
                        }
                    });
                });
            }

            updateSummary();
        }

        // Run on a hard load AND on Turbo navigations (Turbo Drive does not fire
        // DOMContentLoaded on subsequent visits, which left the line fields stuck
        // disabled when the page was reached via a Turbo link). initReturnsCreate
        // is idempotent, so binding both events is safe.
        document.addEventListener('DOMContentLoaded', initReturnsCreate);
        document.addEventListener('turbo:load', initReturnsCreate);
        if (document.readyState !== 'loading') {
            initReturnsCreate();
        }
        // Turbo caches the HTML snapshot but not the JS listeners; clear the init
        // flag before caching so the handlers are re-bound when the page is restored.
        document.addEventListener('turbo:before-cache', () => {
            const form = document.querySelector('form[action="{{ route('returns.store', $invoice) }}"]');
            if (form) {
                delete form.dataset.returnsInit;
            }
        });
    </script>
</x-app-layout>
