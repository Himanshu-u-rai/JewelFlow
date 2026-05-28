{{--
    Material-aware pricing behaviour for retailer item forms.

    Self-contained. Does not alter the existing rate-derived (gold/silver) JS.
    When a piece-price metal (platinum/copper) is selected, the selling price
    becomes a direct operator input instead of being derived from daily rates.

    Requires: $metalUxModes (map of metal => 'rate_derived'|'piece_price').
              $metalPurity  (map of metal => {mode, label}).
    Expects these element IDs in the parent form: metal_type, selling_price,
    selling_price_display, cost_price, and (optional) purity_field_wrap,
    purity_field_label, purity_required_star, purity.
--}}
<script>
(function () {
    const UX_MODES = @json($metalUxModes ?? []);
    const PURITY = @json($metalPurity ?? []);
    // Class B reference-price hints (display-only memory aid). Keyed by metal.
    // Missing entry = no reference noted yet (a normal state). Hint is NEVER
    // auto-filled into selling_price and NEVER used as a rate.
    const REFERENCE_HINTS = @json($referenceHints ?? []);
    const metalSelect = document.getElementById('metal_type');
    const sellingDisplay = document.getElementById('selling_price_display');
    const sellingHidden = document.getElementById('selling_price');
    const costInput = document.getElementById('cost_price');

    // Purity field hooks (P3 — purity selector mode per identity class).
    const purityWrap = document.getElementById('purity_field_wrap');
    const puritySelect = document.getElementById('purity');
    const purityLabelEl = document.getElementById('purity_field_label');
    const purityStar = document.getElementById('purity_required_star');

    if (!metalSelect || !sellingDisplay || !sellingHidden) {
        return;
    }

    // Adapt the purity field to the selected metal's identity class:
    //   mandatory   (gold/silver) — required, page-managed profile options
    //   lightweight (platinum)    — optional hallmark-grade spec, never required
    //   hidden      (copper)      — no purity field
    function applyPurityMode() {
        const cfg = PURITY[metalSelect.value];
        if (!purityWrap || !puritySelect || !cfg) {
            return;
        }

        if (cfg.mode === 'hidden') {
            purityWrap.style.display = 'none';
            puritySelect.required = false;
            puritySelect.value = '';
            return;
        }

        purityWrap.style.display = '';

        if (cfg.mode === 'lightweight') {
            if (purityLabelEl) purityLabelEl.textContent = cfg.label || 'Hallmark grade';
            if (purityStar) purityStar.style.display = 'none';
            puritySelect.required = false;
            // Hallmark grade is a spec, not a profile — offer the standard grades.
            puritySelect.innerHTML =
                '<option value="">Select grade (optional)</option>' +
                '<option value="95">Pt950</option>' +
                '<option value="90">Pt900</option>';
        } else { // mandatory — gold/silver
            if (purityLabelEl) purityLabelEl.textContent = cfg.label || 'Purity';
            if (purityStar) purityStar.style.display = '';
            puritySelect.required = true;
            // gold/silver options are repopulated by the page's own handler.
        }
    }

    let hintEl = null;
    let referenceHintEl = null;

    function ensureHint() {
        if (hintEl) {
            return hintEl;
        }
        hintEl = document.createElement('p');
        hintEl.className = 'mt-1 text-xs text-blue-600';
        hintEl.textContent = 'This metal is sold at a fixed price — type the selling price directly. Daily metal rate is not used.';
        sellingDisplay.parentElement.appendChild(hintEl);
        return hintEl;
    }

    function ensureReferenceHint() {
        if (referenceHintEl) {
            return referenceHintEl;
        }
        referenceHintEl = document.createElement('p');
        referenceHintEl.className = 'mt-1 text-xs text-slate-500 italic';
        referenceHintEl.setAttribute('data-role', 'reference-hint');
        sellingDisplay.parentElement.appendChild(referenceHintEl);
        return referenceHintEl;
    }

    function applyReferenceHint(metal) {
        const hint = REFERENCE_HINTS[metal];
        if (!hint || !isPiecePrice(metal)) {
            if (referenceHintEl) {
                referenceHintEl.style.display = 'none';
            }
            return;
        }
        const el = ensureReferenceHint();
        const priceStr = '₹' + Number(hint.price).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        let text = 'Recent reference: ' + priceStr + ' / g';
        if (hint.noted_at_human) text += ' (noted ' + hint.noted_at_human + ')';
        text += '. Memory aid only — not a rate.';
        el.textContent = text;
        el.style.display = '';
    }

    function isPiecePrice(metal) {
        return UX_MODES[metal] === 'piece_price';
    }

    function applyMode() {
        const metal = metalSelect.value;

        if (isPiecePrice(metal)) {
            sellingDisplay.readOnly = false;
            sellingDisplay.classList.remove('bg-amber-50');
            sellingDisplay.classList.add('bg-white');
            sellingDisplay.placeholder = 'Enter selling price';
            if (sellingHidden.value && sellingHidden.value !== '0') {
                sellingDisplay.value = sellingHidden.value;
            }
            if (costInput) {
                costInput.readOnly = false;
                costInput.placeholder = 'Optional — defaults to selling price';
            }
            ensureHint().style.display = '';
        } else {
            sellingDisplay.readOnly = true;
            sellingDisplay.classList.add('bg-amber-50');
            sellingDisplay.classList.remove('bg-white');
            sellingDisplay.placeholder = 'Sum of all charges above';
            if (costInput) {
                costInput.readOnly = true;
                costInput.placeholder = "Calculated from today's rates";
            }
            if (hintEl) {
                hintEl.style.display = 'none';
            }
        }
    }

    // Mirror the manual selling price into the submitted hidden field.
    sellingDisplay.addEventListener('input', function () {
        if (isPiecePrice(metalSelect.value)) {
            sellingHidden.value = sellingDisplay.value || '0';
        }
    });

    function applyAll() {
        applyMode();
        applyPurityMode();
        applyReferenceHint(metalSelect.value);
    }

    // Run AFTER the form's own metal-change handler (which derives from rates
    // and repopulates gold/silver purity options).
    metalSelect.addEventListener('change', function () {
        setTimeout(applyAll, 0);
    });

    document.addEventListener('DOMContentLoaded', function () {
        setTimeout(applyAll, 0);
    });

    // Fallback in case DOMContentLoaded already fired.
    setTimeout(applyAll, 50);
})();
</script>
