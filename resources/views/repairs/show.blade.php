@php
    $status = $repair->status === 'pending' ? 'received' : $repair->status;
    $statusLabel = match($status) {
        'in_repair' => 'In repair',
        'ready' => 'Ready for pickup',
        'delivered' => 'Delivered',
        default => 'Received',
    };
    $statusClass = match($status) {
        'in_repair' => 'is-progress',
        'ready' => 'is-ready',
        'delivered' => 'is-delivered',
        default => 'is-received',
    };
    $workflow = [
        ['key' => 'received', 'label' => 'Received'],
        ['key' => 'in_repair', 'label' => 'In repair'],
        ['key' => 'ready', 'label' => 'Ready'],
        ['key' => 'delivered', 'label' => 'Delivered'],
    ];
    $statusIndex = collect($workflow)->search(fn ($step) => $step['key'] === $status);
    $statusIndex = $statusIndex === false ? 0 : $statusIndex;
    $repairImageUrl = $repair->resolveImageUrl();
@endphp

<x-app-layout>
    <x-page-header class="repairs-show-header">
        <div class="repairs-show-title-block">
            <h1 class="page-title">Repair #{{ $repair->repair_number ?? '—' }}</h1>
            <p class="page-subtitle">Opened {{ optional($repair->created_at)->format('d M Y') }}</p>
        </div>
        <div class="page-actions repairs-show-header-actions">
            <a href="{{ route('repairs.index') }}" class="repairs-show-action repairs-show-action--back" aria-label="Back to Repairs" data-tip="Back to Repairs">
                <x-icon name="arrow-left" />
                <span>Back to Repairs</span>
            </a>
            @if($repair->status !== 'delivered')
                @can('repairs.edit')
                <a href="{{ route('repairs.edit', $repair) }}" class="repairs-show-action repairs-show-action--primary" aria-label="Edit Repair" data-tip="Edit Repair">
                    <x-icon name="square-pen" />
                    <span>Edit Repair</span>
                </a>
                @endcan
            @elseif($repair->invoice_id)
                <a href="{{ route('invoices.show', $repair->invoice_id) }}" class="repairs-show-action repairs-show-action--primary" aria-label="View Invoice" data-tip="View Invoice">
                    <x-icon name="file-text" />
                    <span>View Invoice</span>
                </a>
            @endif
        </div>
    </x-page-header>

    <div class="content-inner repairs-show-page">
        <section class="repairs-show-workflow" aria-label="Repair progress">
            <div class="repairs-show-workflow-head">
                <h2 class="repairs-show-progress-title">Repair progress</h2>
                <span class="repairs-show-status {{ $statusClass }}">{{ $statusLabel }}</span>
            </div>
            <ol class="repairs-show-steps">
                @foreach($workflow as $index => $step)
                    <li class="{{ $index < $statusIndex ? 'is-complete' : ($index === $statusIndex ? 'is-current' : '') }}" @if($index === $statusIndex) aria-current="step" @endif>
                        <span class="repairs-show-step-dot">
                            @if($index < $statusIndex)
                                <x-icon name="check" />
                            @else
                                {{ $index + 1 }}
                            @endif
                        </span>
                        <span>{{ $step['label'] }}</span>
                    </li>
                @endforeach
            </ol>
        </section>

        <div class="repairs-show-layout">
            <div class="repairs-show-main">
                @php($challanRef = 'REP-' . str_pad((string) $repair->repair_number, 3, '0', STR_PAD_LEFT))
                {{-- Intake challan: read-only receipt for the customer. Printing, downloading
                     or sharing it never changes the repair, its number or any payment. --}}
                <section class="repairs-show-card" data-challan-share-box aria-labelledby="repair-challan-heading">
                    <header class="repairs-show-card-head">
                        <div>
                            <span class="repairs-show-label">Customer challan</span>
                            <h2 id="repair-challan-heading">Intake receipt {{ $challanRef }}</h2>
                        </div>
                    </header>
                    <div class="repairs-show-challan-body">
                        <div class="repairs-show-challan-actions">
                            <a href="{{ route('repairs.challan', $repair) }}" target="_blank" rel="noopener" class="repairs-show-action">
                                <x-icon name="printer" />
                                <span>Print challan</span>
                            </a>
                            <a href="{{ route('repairs.challan.pdf', $repair) }}" class="repairs-show-action" data-turbo="false">
                                <x-icon name="download" />
                                <span>Download PDF</span>
                            </a>
                            {{-- Hidden until the browser shows it can hand a PDF to its share menu (repair-challan-share.js). --}}
                            <button type="button" class="repairs-show-action" data-challan-share hidden
                                    data-url="{{ route('repairs.challan.pdf', $repair) }}" data-filename="{{ $challanRef }}-challan.pdf">
                                <x-icon name="share-2" />
                                <span data-challan-share-label>Share challan</span>
                            </button>
                            @if($customerWhatsApp['link'])
                                <a href="{{ $customerWhatsApp['link'] }}" target="_blank" rel="noopener noreferrer" class="repairs-show-action">
                                    <x-icon name="message-square-more" />
                                    <span>WhatsApp customer</span>
                                </a>
                            @endif
                        </div>
                        <p class="repairs-show-help">
                            @if($customerWhatsApp['link'])
                                WhatsApp customer opens a chat with {{ \App\Support\Mobile::forDisplay($repair->customer?->mobile) }} in the WhatsApp account on this device, with a short message filled in. Nothing is sent until you press Send.
                            @else
                                {{ $customerWhatsApp['problem'] }}
                            @endif
                        </p>
                        <details class="repairs-show-share-help">
                            <summary><x-icon name="chevron-down" size="16" />How to share the challan PDF</summary>
                            <p class="repairs-show-help">To send the challan on WhatsApp, tap Download PDF, then in the chat tap Attach (📎) → Document and choose {{ $challanRef }}-challan.pdf.</p>
                        </details>
                        <p data-challan-share-status role="status" aria-live="polite" class="repairs-show-help"></p>
                    </div>
                </section>

                <section class="repairs-show-card repairs-show-card--details">
                    <header class="repairs-show-card-head">
                        <div>
                            <span class="repairs-show-label">Repair item</span>
                            <h2>{{ $repair->item_description }}</h2>
                        </div>
                        <span class="repairs-show-ticket">REP-{{ str_pad($repair->repair_number, 3, '0', STR_PAD_LEFT) }}</span>
                    </header>

                    <div class="repairs-show-description">
                        <span class="repairs-show-label">Work requested</span>
                        <p>{{ $repair->description ?: 'No repair instructions recorded.' }}</p>
                    </div>

                    <dl class="repairs-show-facts">
                        <div>
                            <dt>Metal</dt>
                            <dd>{{ ucfirst($repair->metal_type ?? 'gold') }}</dd>
                        </div>
                        <div>
                            <dt>Purity</dt>
                            <dd>{{ $repair->purityLabel() ?? 'Not recorded' }}</dd>
                        </div>
                        <div>
                            <dt>Gross weight</dt>
                            <dd>{{ number_format((float) $repair->gross_weight, 3) }} g</dd>
                        </div>
                        <div>
                            <dt>Quantity (pieces)</dt>
                            <dd>{{ $repair->item_quantity ?? 'Not recorded' }}</dd>
                        </div>
                        <div>
                            <dt>Expected ready by</dt>
                            <dd>{{ $repair->due_date?->format('d M Y') ?? 'Not recorded' }}</dd>
                        </div>
                        <div>
                            <dt>Condition at receipt</dt>
                            <dd style="white-space:pre-line">{{ $repair->intake_condition ?: 'Not recorded' }}</dd>
                        </div>
                    </dl>
                </section>

                <section class="repairs-show-card repairs-show-card--customer">
                    <header class="repairs-show-card-head">
                        <div>
                            <span class="repairs-show-label">Customer</span>
                            <h2>{{ $repair->customer?->name ?? 'Customer unavailable' }}</h2>
                        </div>
                    </header>
                    <dl class="repairs-show-customer-grid">
                        <div>
                            <dt>Mobile</dt>
                            <dd>{{ \App\Support\Mobile::forDisplay($repair->customer?->mobile) ?: 'Not recorded' }}</dd>
                        </div>
                        <div>
                            <dt>Received</dt>
                            <dd>{{ optional($repair->created_at)->format('d M Y, h:i A') ?? 'Not recorded' }}</dd>
                        </div>
                    </dl>
                </section>
            </div>

            <aside class="repairs-show-rail">
                <section class="repairs-show-card repairs-show-card--cost">
                    <header class="repairs-show-card-head">
                        <div>
                            <span class="repairs-show-label">Repair value</span>
                            <h2>Cost summary</h2>
                        </div>
                    </header>
                    <dl class="repairs-show-cost-list">
                        <div>
                            <dt>Estimated cost</dt>
                            <dd>₹{{ number_format((float) ($repair->estimated_cost ?? 0), 2) }}</dd>
                        </div>
                        <div>
                            <dt>Final cost</dt>
                            <dd>{{ $repair->final_cost !== null ? '₹'.number_format((float) $repair->final_cost, 2) : 'Pending' }}</dd>
                        </div>
                        @if($repair->invoice_id)
                            <div>
                                <dt>Invoice</dt>
                                <dd><a href="{{ route('invoices.show', $repair->invoice_id) }}">View invoice</a></dd>
                            </div>
                        @endif
                    </dl>
                </section>

                <section class="repairs-show-card repairs-show-card--photo">
                    <header class="repairs-show-card-head">
                        <div>
                            <span class="repairs-show-label">Reference</span>
                            <h2>Item photo</h2>
                        </div>
                    </header>
                    <div class="repairs-show-photo">
                        @if($repairImageUrl)
                            <img src="{{ $repairImageUrl }}" alt="{{ $repair->item_description }}">
                        @else
                            <div class="repairs-show-photo-empty">
                                <x-icon name="image" />
                                <span>No photo uploaded</span>
                            </div>
                        @endif
                    </div>
                </section>
            </aside>
        </div>
    </div>
</x-app-layout>
