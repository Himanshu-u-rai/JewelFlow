{{--
    Material-aware pricing behaviour for retailer item forms.

    Self-contained. Does not alter the existing rate-derived (gold/silver) JS.
    When a piece-price metal (platinum/copper) is selected, the selling price
    becomes a direct operator input instead of being derived from daily rates.

    Requires: $metalUxModes (map of metal => 'rate_derived'|'piece_price').
    Expects these element IDs in the parent form: metal_type, selling_price,
    selling_price_display, cost_price.
--}}
<script>
(function () {
    const UX_MODES = @json($metalUxModes ?? []);
    const metalSelect = document.getElementById('metal_type');
    const sellingDisplay = document.getElementById('selling_price_display');
    const sellingHidden = document.getElementById('selling_price');
    const costInput = document.getElementById('cost_price');

    if (!metalSelect || !sellingDisplay || !sellingHidden) {
        return;
    }

    let hintEl = null;

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

    // Run AFTER the form's own metal-change handler (which derives from rates).
    metalSelect.addEventListener('change', function () {
        setTimeout(applyMode, 0);
    });

    document.addEventListener('DOMContentLoaded', function () {
        setTimeout(applyMode, 0);
    });

    // Fallback in case DOMContentLoaded already fired.
    setTimeout(applyMode, 50);
})();
</script>
