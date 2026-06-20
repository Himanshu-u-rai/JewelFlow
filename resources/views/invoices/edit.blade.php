@php
    $lineCount = $invoice->items->count();
    $customerName = $invoice->customer?->name ?? 'Walk-in Customer';
    $statusLabel = ucfirst($invoice->status);
    $isDraft = $invoice->status === \App\Models\Invoice::STATUS_DRAFT;
    $isFinalized = $invoice->status === \App\Models\Invoice::STATUS_FINALIZED;
    $isCancelled = $invoice->status === \App\Models\Invoice::STATUS_CANCELLED;
@endphp

<x-app-layout>
    <x-page-header class="invoices-edit-header">
        <div>
            <h1 class="page-title">Edit invoice</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $invoice->invoice_number }} · {{ $statusLabel }} controls</p>
        </div>
        <div class="page-actions flex flex-wrap items-center gap-2">
            <a href="{{ route('invoices.show', $invoice) }}" class="btn btn-secondary btn-sm invoices-edit-back-action">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                Back to invoice
            </a>
        </div>
    </x-page-header>

    <div class="content-inner invoices-edit-page">
        @error('invoice')
            <div class="invoices-edit-alert" role="alert">{{ $message }}</div>
        @enderror

        <div class="invoices-edit-layout">
            <main class="invoices-edit-main" aria-label="Invoice edit actions">
                <section class="invoices-edit-hero invoices-edit-card">
                    <div>
                        <p class="invoices-edit-kicker">Invoice action</p>
                        <h2>{{ $invoice->invoice_number }}</h2>
                        <p>
                            @if($isDraft)
                                Review GST and finalize this draft, or cancel it before it enters the ledger.
                            @elseif($isFinalized)
                                This invoice is locked. Cancellation creates a reversal so the ledger remains auditable.
                            @else
                                This invoice is cancelled and no further invoice actions are available.
                            @endif
                        </p>
                    </div>
                    <span class="invoices-edit-status invoices-edit-status--{{ $invoice->status }}">{{ $statusLabel }}</span>
                </section>

                @if($isDraft)
                    <section class="invoices-edit-action-grid">
                        <article class="invoices-edit-card invoices-edit-action-card invoices-edit-action-card--primary">
                            <div class="invoices-edit-card-head">
                                <div>
                                    <p class="invoices-edit-kicker">Finalize</p>
                                    <h2>Finalize invoice</h2>
                                </div>
                                <span>Ledger entry</span>
                            </div>
                            <p>Lock this draft and record it in the ledger. Future cancellation must happen through a reversal.</p>

                            <form method="POST" action="{{ route('invoices.update', $invoice) }}" data-confirm-message="Finalize this invoice?">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="action" value="finalize">

                                <label for="gst_rate">
                                    <span>GST rate (%) <small>optional override</small></span>
                                    <input type="number" step="0.01" min="0" max="100" name="gst_rate" id="gst_rate"
                                           value="{{ old('gst_rate', $invoice->gst_rate) }}"
                                           placeholder="Leave blank to use default">
                                </label>
                                @error('gst_rate')<p class="invoices-edit-error">{{ $message }}</p>@enderror

                                <button type="submit" class="invoices-edit-button invoices-edit-button--primary">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                    Finalize invoice
                                </button>
                            </form>
                        </article>

                        @can('sales.void')
                            <article class="invoices-edit-card invoices-edit-action-card invoices-edit-action-card--danger">
                                <div class="invoices-edit-card-head">
                                    <div>
                                        <p class="invoices-edit-kicker">Void draft</p>
                                        <h2>Cancel draft</h2>
                                    </div>
                                    <span>No reversal</span>
                                </div>
                                <p>Void this draft invoice before it becomes a ledger record.</p>

                                <form method="POST" action="{{ route('invoices.update', $invoice) }}" data-confirm-message="Cancel this draft invoice?">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="action" value="cancel">

                                    <label for="cancellation_reason">
                                        <span>Reason <small>optional</small></span>
                                        <textarea name="cancellation_reason" id="cancellation_reason" rows="3"
                                                  placeholder="Why is this being cancelled?">{{ old('cancellation_reason') }}</textarea>
                                    </label>
                                    @error('cancellation_reason')<p class="invoices-edit-error">{{ $message }}</p>@enderror

                                    <button type="submit" class="invoices-edit-button invoices-edit-button--danger">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                        Cancel draft
                                    </button>
                                </form>
                            </article>
                        @endcan
                    </section>
                @elseif($isFinalized)
                    @can('sales.void')
                        <section class="invoices-edit-card invoices-edit-action-card invoices-edit-action-card--danger">
                            <div class="invoices-edit-card-head">
                                <div>
                                    <p class="invoices-edit-kicker">Reversal required</p>
                                    <h2>Cancel invoice via reversal</h2>
                                </div>
                                <span>Audit safe</span>
                            </div>
                            <p>A reversal invoice will offset this invoice. Both records remain visible for audit and ledger traceability.</p>

                            <form method="POST" action="{{ route('invoices.update', $invoice) }}" data-confirm-message="This will create a reversal invoice. Continue?">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="action" value="cancel">

                                <label for="cancellation_reason">
                                    <span>Reason <small>required</small></span>
                                    <textarea name="cancellation_reason" id="cancellation_reason" rows="4" required
                                              placeholder="State the reason for cancellation">{{ old('cancellation_reason') }}</textarea>
                                </label>
                                @error('cancellation_reason')<p class="invoices-edit-error">{{ $message }}</p>@enderror

                                <button type="submit" class="invoices-edit-button invoices-edit-button--danger">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.52"/></svg>
                                    Cancel via reversal
                                </button>
                            </form>
                        </section>
                    @else
                        <section class="invoices-edit-card invoices-edit-empty">
                            <p class="invoices-edit-kicker">No action available</p>
                            <h2>This finalized invoice is locked.</h2>
                            <p>You do not have permission to create a reversal for this invoice.</p>
                            <a href="{{ route('invoices.show', $invoice) }}" class="invoices-edit-button invoices-edit-button--secondary">View invoice</a>
                        </section>
                    @endcan
                @else
                    <section class="invoices-edit-card invoices-edit-empty">
                        <svg class="invoices-edit-empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 0 0 5.636 5.636m12.728 12.728A9 9 0 0 1 5.636 5.636m12.728 12.728L5.636 5.636"/>
                        </svg>
                        <p class="invoices-edit-kicker">Cancelled invoice</p>
                        <h2>No further actions are available.</h2>
                        <p>This invoice has already been cancelled.</p>
                        <a href="{{ route('invoices.show', $invoice) }}" class="invoices-edit-button invoices-edit-button--secondary">View invoice</a>
                    </section>
                @endif
            </main>

            <aside class="invoices-edit-rail" aria-label="Invoice summary">
                <section class="invoices-edit-card invoices-edit-summary-card">
                    <h2>Invoice facts</h2>
                    <dl>
                        <div>
                            <dt>Status</dt>
                            <dd><span class="invoices-edit-status invoices-edit-status--{{ $invoice->status }}">{{ $statusLabel }}</span></dd>
                        </div>
                        <div>
                            <dt>Customer</dt>
                            <dd>{{ $customerName }}</dd>
                        </div>
                        <div>
                            <dt>Date</dt>
                            <dd>{{ $invoice->created_at->format('d M Y') }}</dd>
                        </div>
                        <div>
                            <dt>Lines</dt>
                            <dd>{{ $lineCount }}</dd>
                        </div>
                        <div>
                            <dt>Subtotal</dt>
                            <dd>₹{{ number_format($invoice->subtotal, 2) }}</dd>
                        </div>
                        <div>
                            <dt>GST</dt>
                            <dd>₹{{ number_format($invoice->gst, 2) }}</dd>
                        </div>
                        <div>
                            <dt>Discount</dt>
                            <dd>₹{{ number_format($invoice->discount, 2) }}</dd>
                        </div>
                        <div class="invoices-edit-summary-total">
                            <dt>Total</dt>
                            <dd>₹{{ number_format($invoice->total, 2) }}</dd>
                        </div>
                    </dl>
                </section>

                <section class="invoices-edit-card invoices-edit-note-card">
                    <h2>Action rules</h2>
                    @if($isDraft)
                        <p>Finalizing locks the bill and records accounting. Cancelling a draft removes it from active billing without a reversal invoice.</p>
                    @elseif($isFinalized)
                        <p>Finalized invoices are not edited directly. A cancellation creates a reversal invoice so audit history stays intact.</p>
                    @else
                        <p>Cancelled invoices are retained for audit history and cannot be changed from this screen.</p>
                    @endif
                </section>
            </aside>
        </div>
    </div>
</x-app-layout>
