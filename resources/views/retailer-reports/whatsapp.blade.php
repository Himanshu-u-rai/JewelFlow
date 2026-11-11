<x-app-layout>
    @php
        $shop = auth()->user()->shop;
        $shopName = $shop->name ?? 'Your Jewellery Store';
        $shopPhone = $shop->phone ?? '';
        $hasFilters = request()->anyFilled(['search', 'category', 'sub_category']);
    @endphp


    <x-page-header class="catalog-page-header">
        <div>
            <h1 class="page-title">Catalog</h1>
        </div>
    </x-page-header>

    <div class="content-inner wa-catalog-root" id="wa-catalog-root"
         data-shop-name="{{ e($shopName) }}"
         data-shop-phone="{{ e($shopPhone) }}"
         data-collection-endpoint="{{ route('catalog.collections.store') }}"
         data-all-matching-ids="{{ json_encode($allMatchingIds) }}">

        <section class="wa-workspace" aria-label="Catalog tools">
          <header class="wa-workspace-head">
            <dl class="wa-summary" aria-label="Matching items"><div><dt>Items</dt><dd>{{ number_format($items->total()) }}</dd></div></dl>
            <div class="wa-field wa-message-style" data-enhance-selects="true" data-enhance-selects-variant="standard" data-enhance-selects-placement="viewport">
                <label for="wa-message-style">Message style</label>
                <select id="wa-message-style" class="wa-select">
                    <option value="premium">Premium Pitch</option>
                    <option value="quick">Quick Quote</option>
                    <option value="newdrop">New Arrival</option>
                </select>
            </div>
            <div class="wa-field phone">
                <label for="wa-phone">Customer number <span>(optional)</span></label>
                <input id="wa-phone" type="tel" inputmode="tel" class="wa-input" value="{{ $shopPhone }}" placeholder="Mobile number" autocomplete="tel">
            </div>
            <div class="wa-selection-tools">
                <label class="wa-select-all-label" id="wa-select-all-label">
                    <input type="checkbox" id="wa-select-all" class="wa-select-all-checkbox" @disabled(count($allMatchingIds) === 0)>
                    <span id="wa-select-all-text">Select All ({{ count($allMatchingIds) }})</span>
                </label>
            </div>
            <x-responsive-filter-panel id="catalog-filter-panel" label="Catalog filters">
            <form method="GET" action="{{ route('catalog.index') }}" class="wa-tools"
                  data-enhance-selects="true" data-enhance-selects-variant="standard" data-enhance-selects-placement="viewport"
                  x-data="{ dirty: false, hasFilters: @js($hasFilters) }" @input="dirty = true" @change="dirty = true">
                <div class="wa-field search">
                    <label for="search">Search</label>
                    <input id="search" name="search" type="text" class="wa-input"
                           value="{{ request('search') }}"
                           placeholder="Barcode, design, category, HUID">
                </div>
                <div class="wa-field category">
                    <label for="category">Category</label>
                    <select id="category" name="category" class="wa-select" @change="document.getElementById('sub_category').value = ''">
                        <option value="">All Categories</option>
                        @foreach($categories as $cat)
                            <option value="{{ $cat }}" {{ request('category') === $cat ? 'selected' : '' }}>{{ $cat }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="wa-field category">
                    <label for="sub_category">Sub Category</label>
                    <select id="sub_category" name="sub_category" class="wa-select">
                        <option value="">All Sub Categories</option>
                        @foreach($subCategories as $subCategory)
                            <option value="{{ $subCategory }}" {{ request('sub_category') === $subCategory ? 'selected' : '' }}>{{ $subCategory }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="wa-field wa-field-actions">
                    <button type="submit" class="btn btn-primary btn-sm" x-show="dirty || !hasFilters">Apply</button>
                    <a href="{{ route('catalog.index') }}" class="btn btn-primary btn-sm" x-show="!dirty && hasFilters" x-cloak>Clear</a>
                    @if($hasFilters)
                        <noscript><a href="{{ route('catalog.index') }}" class="btn btn-secondary btn-sm">Clear</a></noscript>
                    @endif
                </div>
            </form>
            </x-responsive-filter-panel>
          </header>
        </section>

        <section class="wa-selection-shell" id="wa-selection-shell" hidden>
            <div class="wa-selection-bar">
                <div class="wa-selection-meta">
                    <div class="wa-selection-count"><span id="wa-selected-count">0</span> selected</div>
                    <div class="wa-selection-note" id="wa-bulk-note" role="status">Create one clean collection page for these selected products, then send that collection on WhatsApp.</div>
                    <div class="wa-selection-link" id="wa-collection-row">
                        <span>Collection ready:</span>
                        <a href="#" id="wa-view-collection" target="_blank" rel="noopener noreferrer">Open collection</a>
                        <span class="wa-selection-url" id="wa-collection-url"></span>
                    </div>
                </div>
                <div class="wa-selection-actions">
                    <button type="button" id="wa-create-collection" class="wa-btn wa-btn-primary">Create Collection</button>
                    <button type="button" id="wa-share-selected" class="wa-btn wa-btn-share">Send on WhatsApp</button>
                    <button type="button" id="wa-clear-selected" class="wa-btn wa-btn-copy">Clear selection</button>
                </div>
            </div>
        </section>

        <section class="wa-grid">
            @forelse($items as $item)
                @php
                    $displayName = $item->design ?: trim(($item->category ?? 'Jewellery') . ' ' . ($item->sub_category ?? ''));
                    $displayPurity = $item->purity_label ?: '—';
                    $displayWeight = number_format((float) ($item->gross_weight ?? 0), 3, '.', '');
                    $displayPrice = number_format((float) ($item->selling_price ?? 0), 2, '.', '');
                    $displayImage = null;
                    if ($item->image) {
                        $rawImage = trim((string) $item->image);
                        if (\Illuminate\Support\Str::startsWith($rawImage, ['http://', 'https://'])) {
                            $displayImage = $rawImage;
                        } else {
                            $normalizedImagePath = preg_replace('/^storage\//', '', ltrim($rawImage, '/'));
                            $storageRelativeUrl = \Illuminate\Support\Facades\Storage::disk('public')->url($normalizedImagePath);
                            $storagePathOnly = parse_url($storageRelativeUrl, PHP_URL_PATH) ?: $storageRelativeUrl;
                            $storagePathOnly = '/' . ltrim((string) $storagePathOnly, '/');
                            $displayImage = request()->getSchemeAndHttpHost() . $storagePathOnly;
                        }
                    }
                @endphp
                <article class="wa-item-card"
                         data-item-id="{{ $item->id }}"
                         data-design="{{ e($displayName) }}"
                         data-barcode="{{ e((string) $item->barcode) }}"
                         data-category="{{ e((string) ($item->category ?? 'Jewellery')) }}"
                         data-subcategory="{{ e((string) ($item->sub_category ?? '')) }}"
                         data-purity="{{ e($displayPurity) }}"
                         data-weight="{{ e($displayWeight) }}"
                         data-price="{{ e($displayPrice) }}"
                         data-huid="{{ e((string) ($item->huid ?? '')) }}"
                         data-share-url="{{ (string) ($item->public_share_url ?? '') }}">
                    <div class="wa-item-select-wrap">
                        <label class="wa-item-select">
                            <input type="checkbox" class="js-item-select" value="{{ $item->id }}" aria-label="Select item {{ $displayName }}">
                        </label>
                    </div>
                    <div class="wa-item-layout">
                        <div class="wa-media-pane">
                            @if($displayImage)
                                <div class="wa-thumb"><img src="{{ $displayImage }}" alt="{{ $displayName }}" loading="lazy" decoding="async"></div>
                            @else
                                <div class="wa-thumb-empty"><x-icon name="image" size="32" /><span>No photo</span></div>
                            @endif
                        </div>
                        <div class="wa-content-pane">
                            <div class="wa-item-top">
                                <h3 class="wa-name">{{ $displayName }}</h3>
                                <div class="wa-code">{{ $item->barcode }}</div>
                                <div class="wa-badges">
                                    <span class="wa-badge gold">{{ $displayPurity }}</span>
                                    <span class="wa-badge">{{ $item->category }}{{ $item->sub_category ? ' · ' . $item->sub_category : '' }}</span>
                                    @if($item->huid)
                                        <span class="wa-badge green">BIS HUID</span>
                                    @endif
                                </div>
                            </div>

                            <div class="wa-metrics">
                                <div class="wa-metric">
                                    <div class="wa-metric-label">Gross Wt</div>
                                    <div class="wa-metric-value">{{ number_format((float) ($item->gross_weight ?? 0), 3) }}g</div>
                                </div>
                                <div class="wa-metric">
                                    <div class="wa-metric-label">Purity</div>
                                    <div class="wa-metric-value">{{ $displayPurity }}</div>
                                </div>
                                <div class="wa-metric">
                                    <div class="wa-metric-label">Price</div>
                                    <div class="wa-metric-value">₹{{ number_format((float) ($item->selling_price ?? 0), 0) }}</div>
                                </div>
                            </div>

                            <div class="wa-actions">
                                <a class="wa-btn wa-btn-share js-wa-share" target="_blank" rel="noopener noreferrer" href="#">WhatsApp</a>
                                <a class="wa-btn wa-btn-page js-item-page" target="_blank" rel="noopener noreferrer" href="#">Public Page</a>
                                <div class="wa-menu-wrap">
                                    <button type="button" class="wa-btn wa-btn-menu js-item-menu-toggle" aria-expanded="false" aria-controls="wa-item-actions-{{ $item->id }}" aria-label="More actions for {{ $displayName }}"><x-icon name="chevron-down" size="18" /></button>
                                    <div class="wa-menu js-item-menu" id="wa-item-actions-{{ $item->id }}">
                                        <button type="button" class="js-link-copy">Copy Public Link</button>
                                        <button type="button" class="js-custom-msg">Custom Message</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </article>
            @empty
                <div class="wa-empty">
                    <div style="font-size:15px;font-weight:700;color:#334155;">No in-stock items found.</div>
                    <div style="font-size:13px;margin-top:4px;">
                        {{ request()->hasAny(['search', 'category', 'sub_category']) ? 'Try clearing filters or search text.' : 'Add stock items first, then share catalog messages.' }}
                    </div>
                </div>
            @endforelse
        </section>

        <footer class="wa-pagination">
            <p>{{ number_format($items->total()) }} matching {{ \Illuminate\Support\Str::plural('item', $items->total()) }}</p>
            @if($items->hasPages())
                {{ $items->withQueryString()->links() }}
            @endif
        </footer>

        <dialog id="wa-custom-modal" class="wa-custom-modal jf-scrollbar" aria-labelledby="modal-title">
            <header class="wa-custom-head">
                <h2 id="modal-title">Custom Message</h2>
                <button type="button" id="modal-dismiss" class="wa-btn wa-btn-menu" aria-label="Close message editor"><x-icon name="x" size="18" /></button>
            </header>
            <div class="wa-custom-content">

                <div class="wa-custom-grid">
                    <div class="wa-custom-field header">
                        <label for="modal-custom-header">Header (optional)</label>
                        <input id="modal-custom-header" type="text" class="wa-input" value="*{shop}*">
                    </div>
                    <div class="wa-custom-field footer">
                        <label for="modal-custom-footer">Footer (optional)</label>
                        <input id="modal-custom-footer" type="text" class="wa-input" value="Reply on WhatsApp to book now.">
                    </div>
                    <div class="wa-custom-field body">
                        <label for="modal-custom-body">Message body template</label>
                        <textarea id="modal-custom-body" class="wa-textarea" rows="8">*{design}*
Code: {barcode}
Category: {category}{subcategory_suffix}
Purity: {purity}
Weight: {weight} g
Price: ₹{price}
{offer_line}
{share_url_line}</textarea>
                    </div>
                </div>
                <div class="wa-custom-hint">
                    Placeholders: <code>{shop}</code> <code>{design}</code> <code>{barcode}</code> <code>{category}</code> <code>{subcategory}</code> <code>{subcategory_suffix}</code> <code>{purity}</code> <code>{weight}</code> <code>{price}</code> <code>{huid}</code> <code>{offer}</code> <code>{offer_line}</code> <code>{contact}</code> <code>{share_url}</code> <code>{share_url_line}</code>
                </div>

                <div style="margin-top: 1rem;">
                  <label class="wa-metric-label">Live Preview</label>
                  <pre id="modal-preview" class="wa-preview" style="margin-top: 4px; max-height: 150px;"></pre>
                  <p id="wa-copy-status" role="status"></p>
                </div>

            </div>
                <footer class="wa-custom-actions">
                    <a id="modal-wa-share" class="wa-btn wa-btn-share" target="_blank" rel="noopener noreferrer" href="#">Open WhatsApp</a>
                    <button type="button" id="modal-wa-copy" class="wa-btn wa-btn-copy">Copy Message</button>
                    <button type="button" id="modal-close" class="wa-btn wa-btn-copy">Close</button>
                </footer>
        </dialog>
    </div>

    <script>
        (() => {
            const root = document.getElementById('wa-catalog-root');
            if (!root) return;

            const templateSelect = root.querySelector('#wa-message-style');
            const cards = Array.from(root.querySelectorAll('.wa-item-card'));
            const phoneInput = root.querySelector('#wa-phone');
            const offerInput = root.querySelector('#wa-offer');
            const shopName = (root.dataset.shopName || 'Our Jewellery Store').trim();
            const collectionEndpoint = (root.dataset.collectionEndpoint || '').trim();
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const money = new Intl.NumberFormat('en-IN', { maximumFractionDigits: 0 });

            const selectionShell = root.querySelector('#wa-selection-shell');
            const selectedCountEl = root.querySelector('#wa-selected-count');
            const clearSelectedBtn = root.querySelector('#wa-clear-selected');
            const shareSelectedBtn = root.querySelector('#wa-share-selected');
            const createCollectionBtn = root.querySelector('#wa-create-collection');
            const bulkNoteEl = root.querySelector('#wa-bulk-note');
            const collectionRow = root.querySelector('#wa-collection-row');
            const collectionLinkEl = root.querySelector('#wa-view-collection');
            const collectionUrlEl = root.querySelector('#wa-collection-url');

            const modal = document.getElementById('wa-custom-modal');
            const modalTitle = document.getElementById('modal-title');
            const modalHeaderInput = document.getElementById('modal-custom-header');
            const modalBodyInput = document.getElementById('modal-custom-body');
            const modalFooterInput = document.getElementById('modal-custom-footer');
            const modalPreview = document.getElementById('modal-preview');
            const modalShareBtn = document.getElementById('modal-wa-share');
            const modalCopyBtn = document.getElementById('modal-wa-copy');
            const modalCloseBtn = document.getElementById('modal-close');

            const state = {
                template: 'premium',
            };

            const modalState = {
                itemData: null,
                opener: null,
            };

            const STORAGE_KEY = 'wa_catalog_selected';
            const selectedIds = new Set((() => {
                try {
                    const raw = sessionStorage.getItem(STORAGE_KEY);
                    return raw ? JSON.parse(raw) : [];
                } catch (_) { return []; }
            })());
            const persistSelection = () => {
                try { sessionStorage.setItem(STORAGE_KEY, JSON.stringify([...selectedIds])); } catch (_) {}
            };
            const allMatchingIds = (() => {
                try { return JSON.parse(root.dataset.allMatchingIds || '[]').map(String); }
                catch (_) { return []; }
            })();
            const selectAllCheckbox = document.getElementById('wa-select-all');
            const selectAllLabel = document.getElementById('wa-select-all-label');
            const selectAllText = document.getElementById('wa-select-all-text');

            const syncSelectAll = () => {
                if (!selectAllCheckbox) return;
                const allSelected = allMatchingIds.length > 0 && allMatchingIds.every(id => selectedIds.has(id));
                const someSelected = allMatchingIds.some(id => selectedIds.has(id));
                selectAllCheckbox.checked = allSelected;
                selectAllCheckbox.indeterminate = someSelected && !allSelected;
                selectAllLabel?.classList.toggle('all-selected', allSelected);
                if (selectAllText) {
                    selectAllText.textContent = allSelected
                        ? `All selected (${allMatchingIds.length})`
                        : `Select All (${allMatchingIds.length})`;
                }
            };

            let latestCollectionLink = '';
            let latestCollectionKey = '';

            // wa.me needs E.164 digits. A bare 10-digit Indian mobile — which is what
            // the app now stores and what an operator types — is not routable on its
            // own, so the country code goes back on here and nowhere else.
            const normalizePhone = (raw) => {
                const digits = (raw || '').replace(/\D+/g, '');
                return /^[6-9][0-9]{9}$/.test(digits) ? '91' + digits : digits;
            };

            const applyCustomTemplate = (templateText, vars) => {
                if (!templateText) return '';
                return templateText.replace(/\{([a-zA-Z_]+)\}/g, (_, key) => {
                    const value = vars[key];
                    return value === undefined || value === null ? '' : String(value);
                });
            };

            const normalizeLines = (text) =>
                (text || '')
                    .replace(/\n{3,}/g, '\n\n')
                    .split('\n')
                    .map((line) => line.trimEnd())
                    .join('\n')
                    .trim();

            const currentSelectionKey = () =>
                Array.from(selectedIds)
                    .map((id) => String(id))
                    .sort((a, b) => Number(a) - Number(b))
                    .join(',');

            const resetCollectionState = () => {
                latestCollectionLink = '';
                latestCollectionKey = '';
            };

            const setBulkNote = (message, isError = false) => {
                if (!bulkNoteEl) return;
                bulkNoteEl.textContent = message;
                bulkNoteEl.style.color = isError ? '#b91c1c' : '#475569';
            };

            const closeAllMenus = () => {
                root.querySelectorAll('.js-item-menu').forEach((menu) => {
                    menu.classList.remove('open');
                });
                root.querySelectorAll('.js-item-menu-toggle').forEach((button) => {
                    button.setAttribute('aria-expanded', 'false');
                });
            };

            async function copyToClipboard(text, buttonEl) {
                if (!text || !buttonEl) return;

                try {
                    await navigator.clipboard.writeText(text);
                    const old = buttonEl.textContent;
                    buttonEl.textContent = 'Copied';
                    window.setTimeout(() => {
                        buttonEl.textContent = old;
                    }, 1200);
                } catch (_) {
                    if (modal.open) document.getElementById('wa-copy-status').textContent = 'Copy failed. Please try again.';
                    else window.showToast?.('Copy failed. Please try again.', 'error');
                    setBulkNote('Copy failed. Please try again.', true);
                }
            }

            const buildMessage = (item, template, offer, contactNumber, shareUrl) => {
                const design = (item.design || 'Jewellery Item').trim();
                const category = (item.category || 'Jewellery').trim();
                const sub = (item.subcategory || '').trim();
                const categoryLine = sub ? `${category} · ${sub}` : category;
                const purity = (item.purity || '').trim();
                const weight = (item.weight || '').trim();
                const huid = (item.huid || '').trim();
                const priceRaw = parseFloat(item.price || '0');
                const hasPrice = Number.isFinite(priceRaw) && priceRaw > 0;

                const lines = [];

                if (template === 'premium') {
                    lines.push(`*${shopName}*`);
                    lines.push('*Premium Catalogue Selection*');
                    lines.push('');
                    lines.push(`*${design}*`);
                    lines.push(`Code: ${item.barcode}`);
                    lines.push(`Category: ${categoryLine}`);
                    if (purity) lines.push(`Purity: ${purity}`);
                    if (weight) lines.push(`Gross Weight: ${weight} g`);
                    if (huid) lines.push(`Hallmark HUID: ${huid}`);
                    if (hasPrice) lines.push(`Offer Price: ₹${money.format(priceRaw)}`);
                } else if (template === 'quick') {
                    lines.push(`*${design}*`);
                    lines.push(`Code: ${item.barcode} | ${categoryLine}`);
                    if (purity || weight) {
                        lines.push(`${purity || 'Purity —'} | ${weight ? `${weight} g` : 'Weight —'}`);
                    }
                    if (hasPrice) lines.push(`Price: ₹${money.format(priceRaw)}`);
                } else if (template === 'newdrop') {
                    lines.push(`*New Arrival - ${shopName}*`);
                    lines.push('');
                    lines.push(`Design: ${design}`);
                    lines.push(`Ref: ${item.barcode}`);
                    lines.push(`Category: ${categoryLine}`);
                    if (purity) lines.push(`Purity: ${purity}`);
                    if (weight) lines.push(`Weight: ${weight} g`);
                    if (hasPrice) lines.push(`Special Price: ₹${money.format(priceRaw)}`);
                    lines.push('Limited availability. Book your piece today.');
                } else {
                    lines.push(`*${design}*`);
                    if (hasPrice) lines.push(`Price: ₹${money.format(priceRaw)}`);
                }

                if (offer) {
                    lines.push('');
                    lines.push(`Offer: ${offer}`);
                }

                lines.push('');
                lines.push('Reply on WhatsApp for booking.');
                if (contactNumber) lines.push(`Contact: ${contactNumber}`);
                if (shareUrl) lines.push(`View Product: ${shareUrl}`);

                return lines.join('\n');
            };

            const buildCollectionMessage = (link, count) => {
                const offer = (offerInput?.value || '').trim();
                const contactDisplay = (phoneInput?.value || '').trim();
                const lines = [];

                if (state.template === 'premium') {
                    lines.push(`*${shopName}*`);
                    lines.push('*Curated Jewellery Collection*');
                    lines.push(`${count} handpicked designs are ready for you.`);
                } else if (state.template === 'quick') {
                    lines.push(`*${count} jewellery picks from ${shopName}*`);
                    lines.push('Open the collection link below for the full selection.');
                } else {
                    lines.push(`*New arrivals from ${shopName}*`);
                    lines.push(`${count} selected designs are now available.`);
                }

                if (offer) {
                    lines.push(`Offer: ${offer}`);
                }

                lines.push('');
                lines.push(`View collection: ${link}`);
                lines.push('Reply on WhatsApp to shortlist or reserve any design.');

                if (contactDisplay) {
                    lines.push(`Contact: ${contactDisplay}`);
                }

                return lines.join('\n');
            };

            const updateSelectionUI = () => {
                const count = selectedIds.size;
                const selectionKey = currentSelectionKey();
                const hasCollectionForCurrentSelection = !!latestCollectionLink && latestCollectionKey === selectionKey;
                syncSelectAll();

                cards.forEach((card) => {
                    const id = String(card.dataset.itemId || '');
                    const checked = selectedIds.has(id);
                    card.classList.toggle('selected', checked);
                    const checkbox = card.querySelector('.js-item-select');
                    if (checkbox && checkbox.checked !== checked) {
                        checkbox.checked = checked;
                    }
                });

                if (selectionShell) {
                    selectionShell.hidden = count === 0;
                }

                if (selectedCountEl) {
                    selectedCountEl.textContent = String(count);
                }

                if (createCollectionBtn) {
                    createCollectionBtn.disabled = count === 0;
                    createCollectionBtn.textContent = hasCollectionForCurrentSelection ? 'View Collection' : 'Create Collection';
                }

                if (shareSelectedBtn) {
                    shareSelectedBtn.disabled = count === 0;
                }

                if (collectionRow) {
                    collectionRow.classList.toggle('active', hasCollectionForCurrentSelection);
                }

                if (collectionLinkEl) {
                    collectionLinkEl.href = hasCollectionForCurrentSelection ? latestCollectionLink : '#';
                }

                if (collectionUrlEl) {
                    collectionUrlEl.textContent = hasCollectionForCurrentSelection ? latestCollectionLink : '';
                }

                if (count === 0) {
                    setBulkNote('Select one or more products to create a clean collection page for sharing.');
                } else if (hasCollectionForCurrentSelection) {
                    setBulkNote(`Collection ready for ${count} selected product${count > 1 ? 's' : ''}.`);
                } else {
                    setBulkNote(`Create one clean collection page for these ${count} selected product${count > 1 ? 's' : ''}, then share it on WhatsApp.`);
                }
            };

            const renderGlobal = () => {
                const offer = (offerInput?.value || '').trim();
                const contactDisplay = (phoneInput?.value || '').trim();
                const waPhone = normalizePhone(contactDisplay);

                cards.forEach((card) => {
                    const item = { ...card.dataset };
                    const shareUrl = (item.shareUrl || '').trim();
                    const message = buildMessage(item, state.template, offer, contactDisplay, shareUrl);

                    const shareBtn = card.querySelector('.js-wa-share');
                    if (shareBtn) {
                        const encoded = encodeURIComponent(message);
                        shareBtn.href = waPhone ? `https://wa.me/${waPhone}?text=${encoded}` : `https://wa.me/?text=${encoded}`;
                    }

                    const linkBtn = card.querySelector('.js-link-copy');
                    if (linkBtn) {
                        linkBtn.dataset.link = shareUrl;
                    }

                    const pageLink = card.querySelector('.js-item-page');
                    if (pageLink) {
                        pageLink.href = shareUrl || '#';
                        pageLink.style.pointerEvents = shareUrl ? 'auto' : 'none';
                        pageLink.style.opacity = shareUrl ? '1' : '0.6';
                    }
                });

                if (modalState.itemData) {
                    renderModalPreview();
                }

                updateSelectionUI();
            };

            const renderModalPreview = () => {
                if (!modalState.itemData) return;

                const item = modalState.itemData;
                const offerText = (offerInput?.value || '').trim();
                const contactDisplay = (phoneInput?.value || '').trim();
                const shareLink = (item.shareUrl || '').trim();
                const priceRaw = parseFloat(item.price || '0');
                const hasPrice = Number.isFinite(priceRaw) && priceRaw > 0;
                const formattedPrice = hasPrice ? money.format(priceRaw) : '';
                const subSuffix = item.subcategory ? ` · ${item.subcategory}` : '';

                const vars = {
                    shop: shopName,
                    design: item.design || '',
                    barcode: item.barcode || '',
                    category: item.category || '',
                    subcategory: item.subcategory || '',
                    subcategory_suffix: subSuffix,
                    purity: item.purity || '',
                    weight: item.weight || '',
                    price: formattedPrice,
                    huid: item.huid || '',
                    offer: offerText,
                    offer_line: offerText ? `Offer: ${offerText}` : '',
                    contact: contactDisplay,
                    share_url: shareLink,
                    share_url_line: shareLink ? `View Product: ${shareLink}` : '',
                };

                const head = applyCustomTemplate(modalHeaderInput.value, vars);
                const body = applyCustomTemplate(modalBodyInput.value, vars);
                const foot = applyCustomTemplate(modalFooterInput.value, vars);
                const finalMessage = normalizeLines([head, body, foot].filter(Boolean).join('\n\n'));

                modalPreview.textContent = finalMessage;
                modalCopyBtn.dataset.message = finalMessage;

                const waPhone = normalizePhone(contactDisplay);
                const encoded = encodeURIComponent(finalMessage);
                modalShareBtn.href = waPhone ? `https://wa.me/${waPhone}?text=${encoded}` : `https://wa.me/?text=${encoded}`;
            };

            const openModal = (itemCard, opener) => {
                modalState.opener = opener;
                modalState.itemData = { ...itemCard.dataset };
                document.getElementById('wa-copy-status').textContent = '';
                modalTitle.textContent = `Custom Message for ${modalState.itemData.design || 'Item'}`;
                renderModalPreview();
                closeAllMenus();
                modal.showModal();
                modalHeaderInput.focus();
            };

            const closeModal = () => {
                modal.close();
                modalState.itemData = null;
                modalState.opener?.focus();
            };
            modal.addEventListener('close', () => { modalState.itemData = null; modalState.opener?.focus(); });
            document.addEventListener('turbo:before-cache', () => { modal.close(); closeAllMenus(); }, { once: true });

            const createOrReuseCollectionLink = async () => {
                const selectionKey = currentSelectionKey();
                if (!selectionKey) {
                    throw new Error('Select products first.');
                }

                if (latestCollectionLink && latestCollectionKey === selectionKey) {
                    return latestCollectionLink;
                }

                if (!collectionEndpoint) {
                    throw new Error('Collection endpoint is unavailable.');
                }

                const itemIds = Array.from(selectedIds)
                    .map(Number)
                    .filter((id) => Number.isInteger(id) && id > 0);

                const response = await fetch(collectionEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        item_ids: itemIds,
                        title: `${shopName} Collection`,
                    }),
                });

                const data = await response.json().catch(() => ({}));

                if (!response.ok) {
                    throw new Error(data.message || 'Failed to create collection link.');
                }

                latestCollectionLink = (data.url || '').trim();
                latestCollectionKey = selectionKey;
                updateSelectionUI();

                if (!latestCollectionLink) {
                    throw new Error('Collection created but link is missing.');
                }

                return latestCollectionLink;
            };

            templateSelect?.addEventListener('change', () => {
                state.template = templateSelect.value;
                renderGlobal();
            });

            if (offerInput) {
                offerInput.addEventListener('input', renderGlobal);
            }

            if (phoneInput) {
                phoneInput.addEventListener('input', renderGlobal);
            }

            cards.forEach((card) => {
                card.querySelector('.js-link-copy')?.addEventListener('click', (event) => {
                    copyToClipboard(event.currentTarget.dataset.link, event.currentTarget);
                    closeAllMenus();
                });

                card.querySelector('.js-custom-msg')?.addEventListener('click', (event) => {
                    openModal(card, event.currentTarget.closest('.wa-menu-wrap').querySelector('.js-item-menu-toggle'));
                });

                card.querySelector('.js-item-select')?.addEventListener('change', (event) => {
                    const id = String(card.dataset.itemId || '');
                    const selectionBefore = currentSelectionKey();

                    if (!id) return;

                    if (event.currentTarget.checked) {
                        selectedIds.add(id);
                    } else {
                        selectedIds.delete(id);
                    }
                    persistSelection();

                    if (selectionBefore !== currentSelectionKey()) {
                        resetCollectionState();
                    }

                    updateSelectionUI();
                });

                card.querySelector('.js-item-menu-toggle')?.addEventListener('click', (event) => {
                    const button = event.currentTarget;
                    const menu = card.querySelector('.js-item-menu');
                    const isOpen = menu?.classList.contains('open');

                    closeAllMenus();

                    if (menu && !isOpen) {
                        menu.classList.add('open');
                        button.setAttribute('aria-expanded', 'true');
                    }
                });
            });

            selectAllCheckbox?.addEventListener('change', (e) => {
                if (e.currentTarget.checked) {
                    allMatchingIds.forEach(id => selectedIds.add(id));
                } else {
                    allMatchingIds.forEach(id => selectedIds.delete(id));
                }
                persistSelection();
                resetCollectionState();
                updateSelectionUI();
            });

            clearSelectedBtn?.addEventListener('click', () => {
                selectedIds.clear();
                persistSelection();
                resetCollectionState();
                updateSelectionUI();
                closeAllMenus();
            });

            createCollectionBtn?.addEventListener('click', async (event) => {
                const button = event.currentTarget;
                const selectionKey = currentSelectionKey();

                if (!selectionKey) {
                    setBulkNote('Select products first.', true);
                    return;
                }

                if (latestCollectionLink && latestCollectionKey === selectionKey) {
                    window.open(latestCollectionLink, '_blank', 'noopener,noreferrer');
                    return;
                }

                const oldLabel = button.textContent;
                let errorMessage = '';
                button.disabled = true;
                button.textContent = 'Creating...';

                try {
                    await createOrReuseCollectionLink();
                    setBulkNote(`Collection created for ${selectedIds.size} selected product${selectedIds.size > 1 ? 's' : ''}.`);
                } catch (error) {
                    errorMessage = error.message || 'Could not create collection link.';
                } finally {
                    button.disabled = false;
                    updateSelectionUI();
                    button.textContent = createCollectionBtn?.textContent || oldLabel;
                    if (errorMessage) setBulkNote(errorMessage, true);
                }
            });

            shareSelectedBtn?.addEventListener('click', async (event) => {
                const button = event.currentTarget;
                const oldLabel = button.textContent;
                button.disabled = true;
                button.textContent = 'Preparing...';

                try {
                    const link = await createOrReuseCollectionLink();
                    const message = buildCollectionMessage(link, selectedIds.size);
                    const waPhone = normalizePhone(phoneInput?.value || '');
                    const encoded = encodeURIComponent(message);
                    const url = waPhone ? `https://wa.me/${waPhone}?text=${encoded}` : `https://wa.me/?text=${encoded}`;
                    window.location.assign(url);
                } catch (error) {
                    setBulkNote(error.message || 'Could not prepare the WhatsApp share.', true);
                } finally {
                    button.disabled = false;
                    button.textContent = oldLabel;
                }
            });

            modalCloseBtn?.addEventListener('click', closeModal);
            document.getElementById('modal-dismiss')?.addEventListener('click', closeModal);
            modalCopyBtn?.addEventListener('click', (event) => {
                copyToClipboard(event.currentTarget.dataset.message, event.currentTarget);
            });

            [modalHeaderInput, modalBodyInput, modalFooterInput].forEach((element) => {
                element?.addEventListener('input', renderModalPreview);
            });

            document.addEventListener('click', (event) => {
                if (!event.target.closest('.wa-menu-wrap')) {
                    closeAllMenus();
                }

                if (event.target === modal) {
                    closeModal();
                }
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    root.querySelector('.js-item-menu.open')?.previousElementSibling?.focus();
                    closeAllMenus();
                }
            });

            renderGlobal();
            updateSelectionUI();
        })();
    </script>
</x-app-layout>
