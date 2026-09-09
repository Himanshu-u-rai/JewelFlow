export function registerHistoricalManual(Alpine) {
    Alpine.data('historicalManualForm', (config) => ({
        lines: [],
        payments: [],
        enabledMetals: config.enabledMetals || [],
        purityProfiles: config.purityProfiles || [],
        minimumRows: config.minimumRows || 1,
        isMobile: window.matchMedia('(max-width: 1279px)').matches,
        documentTotals: { ...config.documentTotals },
        documentModes: { ...config.documentModes },
        submitting: false,

        // Fast-entry guard: one real submit per page load. The browser is about
        // to navigate away on success (or reload the page with errors on
        // failure) either way, so this only needs to block a second click
        // before that happens — no reset method is needed.
        submitOnce(event) {
            if (this.submitting) {
                event.preventDefault();
                return;
            }
            this.submitting = true;
        },

        init() {
            this.lines = this.seedLines(config.lines || []);
            this.lines.forEach((line) => this.calculateLine(line));
            this.payments = this.seedPayments(config.payments || []);
            this.syncDocumentTotals();

            this._mobileQuery = window.matchMedia('(max-width: 1279px)');
            this._mobileListener = (event) => { this.isMobile = event.matches; };
            this._mobileQuery.addEventListener('change', this._mobileListener);
        },

        destroy() {
            this._mobileQuery?.removeEventListener('change', this._mobileListener);
        },

        blankLine() {
            return {
                line_calculation_enabled: '1',
                line_item_name: '', line_sku: '', line_hsn: '', line_quantity: '',
                line_metal_choice: '', line_metal_type: '', line_custom_metal: '',
                line_purity_choice: '', line_purity: '', line_purity_value: '',
                line_fine_weight: '',
                line_gross_weight: '', line_net_weight: '', line_stone_weight: '',
                line_billable_weight_basis: '', line_billable_weight: '', line_billable_weight_mode: 'auto',
                // 'as_printed' = rate already carries this purity (what a paper bill
                // shows). 'pure_reference' = a 24K/999 rate to be scaled by purity.
                // Deliberately excluded from isBlank(): it always holds a value, so
                // counting it would make every empty row look filled.
                line_rate: '', line_rate_basis: 'as_printed', line_metal_value: '', line_metal_value_mode: 'auto',
                line_stone_rate: '', line_stone_value: '', line_stone_value_mode: 'auto',
                line_making_label: '', line_making_value: '', line_making_basis: '', line_making_amount: '', line_making_amount_mode: 'auto',
                line_wastage_basis: '', line_wastage_value: '', line_wastage_amount: '', line_wastage_amount_mode: 'auto',
                line_hallmark_charge: '', line_rhodium_charge: '', line_other_charge: '',
                line_discount_type: '', line_discount_value: '', line_discount_amount: '', line_discount_amount_mode: 'auto',
                line_tax_mode: 'no_gst', line_gst_rate: '', line_taxable: '', line_taxable_mode: 'auto',
                line_total: '', line_total_mode: 'auto', line_notes: '', line_missing: '', advanced: false,
            };
        },

        seedLines(raw) {
            const rows = Object.values(raw || {}).map((source) => {
                const line = { ...this.blankLine(), ...(source || {}) };

                // Mirrors HistoricalManualCalculationService::rateBasisFor(). A
                // seeded row without the key predates this distinction, so it was
                // priced as a 24K/999 reference — keep it that way rather than
                // restating its metal value upward by 24/purity on reopen.
                if (source && source.line_rate_basis === undefined) line.line_rate_basis = 'pure_reference';

                const knownMetal = this.enabledMetals.includes(line.line_metal_type);
                line.line_metal_choice = knownMetal ? line.line_metal_type : (line.line_metal_type ? '__custom' : '');
                line.line_custom_metal = knownMetal ? '' : line.line_metal_type;

                const profile = this.purityProfiles.find((item) => item.metal === line.line_metal_type && Number(item.value) === Number(line.line_purity_value));
                line.line_purity_choice = profile ? String(profile.value) : (line.line_purity_value !== '' ? '__custom' : '');

                for (const field of ['line_billable_weight', 'line_metal_value', 'line_stone_value', 'line_making_amount', 'line_wastage_amount', 'line_discount_amount', 'line_taxable', 'line_total']) {
                    const mode = `${field}_mode`;
                    if (source?.[field] !== undefined && source?.[field] !== '' && !source?.[mode]) line[mode] = 'manual';
                }

                return line;
            });

            while (rows.length < this.minimumRows) rows.push(this.blankLine());
            if (rows.length && !this.isBlank(rows[rows.length - 1])) rows.push(this.blankLine());

            return rows;
        },

        isBlank(line) {
            return [
                'line_item_name', 'line_sku', 'line_hsn', 'line_quantity', 'line_metal_type',
                'line_purity_value', 'line_gross_weight', 'line_net_weight', 'line_stone_weight',
                'line_billable_weight_basis', 'line_billable_weight', 'line_rate', 'line_metal_value',
                'line_stone_rate', 'line_stone_value', 'line_making_label', 'line_making_basis',
                'line_making_value', 'line_making_amount', 'line_wastage_basis', 'line_wastage_value',
                'line_wastage_amount', 'line_hallmark_charge', 'line_rhodium_charge', 'line_other_charge',
                'line_discount_type', 'line_discount_value', 'line_discount_amount', 'line_gst_rate',
                'line_taxable', 'line_total', 'line_notes',
            ].every((field) => line[field] === null || line[field] === undefined || String(line[field]).trim() === '');
        },

        padLines() {
            while (this.lines.length < this.minimumRows) this.lines.push(this.blankLine());
            if (!this.isBlank(this.lines[this.lines.length - 1])) this.lines.push(this.blankLine());
        },

        addLine() {
            this.lines.push(this.blankLine());
        },

        duplicateLine(index) {
            const copy = JSON.parse(JSON.stringify(this.lines[index]));
            copy.advanced = false;
            this.lines.splice(index + 1, 0, copy);
            this.padLines();
            this.syncDocumentTotals();
        },

        removeLine(index) {
            this.lines.splice(index, 1);
            this.padLines();
            this.syncDocumentTotals();
        },

        blankPayment() {
            return {
                mode: '', amount: '', account_choice: '', shop_payment_method_id: '',
                account_label_snapshot: '', reference: '', payment_date: '', note: '',
            };
        },

        seedPayments(raw) {
            const rows = Object.values(raw || {}).map((source) => {
                const payment = { ...this.blankPayment(), ...(source || {}) };
                payment.account_choice = payment.shop_payment_method_id
                    ? String(payment.shop_payment_method_id)
                    : (payment.account_label_snapshot ? '__custom' : '');

                return payment;
            });

            // Payment rows are genuinely optional (a typed bill with no payment
            // breakdown is the normal case) — unlike item lines, an empty set
            // stays empty. Only the very first render gets one blank starter row.
            if (rows.length === 0) rows.push(this.blankPayment());

            return rows;
        },

        isBlankPayment(payment) {
            return ['mode', 'amount', 'reference', 'payment_date', 'note', 'account_label_snapshot', 'shop_payment_method_id']
                .every((field) => payment[field] === null || payment[field] === undefined || String(payment[field]).trim() === '');
        },

        addPayment() {
            this.payments.push(this.blankPayment());
        },

        removePayment(index) {
            this.payments.splice(index, 1);
            this.syncDocumentTotals();
        },

        accountChanged(payment) {
            if (payment.account_choice === '__custom') {
                payment.shop_payment_method_id = '';
            } else {
                payment.shop_payment_method_id = payment.account_choice;
                payment.account_label_snapshot = '';
            }
        },

        get paidTotal() {
            return this.sum(this.payments.filter((payment) => !this.isBlankPayment(payment)), 'amount');
        },

        get paymentStatusLabel() {
            const paid = this.paidTotal;
            // Not `|| 0`: number() returns null for a blank field and 0 for a
            // field that says zero, and `|| 0` collapses the two. An unknown
            // total is not a zero total — nothing can be settled or exceeded
            // against a figure the operator has not given us yet.
            const grand = this.number(this.documentTotals.grand_total);

            if (paid <= 0) return 'Unpaid';
            if (grand === null) return 'Partially paid';
            // Overpaid before Fully paid: `paid >= grand` is true for both, and
            // reporting an overpayment as "Fully paid" hides the one number the
            // operator most needs to check against the paper bill. A known ₹0
            // total is included: money tendered against a nil bill is an
            // overpayment of exactly that amount, and saying "Partially paid"
            // there both understates it and hides the excess.
            if (paid > grand) return 'Overpaid';
            if (paid >= grand) return 'Fully paid';

            return 'Partially paid';
        },

        /**
         * Display only. This creates NO wallet credit, NO ledger entry and NO
         * receivable — historical payments are a snapshot of what the paper bill
         * recorded (see HistoricalSalesPayment's docblock). The server-side
         * overpayment warning and its acknowledgement digest remain the gate on
         * saving; this figure just stops the pill from claiming "Fully paid" on
         * a bill that was over-tendered.
         *
         * ponytail: a number next to the pill, not an advance/credit feature.
         */
        get paymentExcess() {
            const grand = this.number(this.documentTotals.grand_total);

            // Blank total: no excess can be asserted, so show none. Known zero:
            // the whole payment is the excess. Same null-vs-zero distinction as
            // paymentStatusLabel — the two must never disagree.
            return grand === null ? 0 : Math.max(0, this.paidTotal - grand);
        },

        get paymentExcessLabel() {
            return `Excess ₹${this.paymentExcess.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        },

        lineChanged(index, event) {
            const line = this.lines[index];
            line.line_calculation_enabled = '1';

            const derivedField = event?.target?.dataset?.derivedField;
            if (derivedField) {
                line[`${derivedField}_mode`] = 'manual';
                line[`${derivedField}_recalculate`] = '';
            }
            if (derivedField === 'line_billable_weight') line.line_billable_weight_basis = 'manual';

            this.calculateLine(line);
            this.padLines();
            this.syncDocumentTotals();
        },

        metalChanged(line) {
            if (line.line_metal_choice === '__custom') {
                line.line_metal_type = line.line_custom_metal || '';
            } else {
                line.line_metal_type = line.line_metal_choice;
                line.line_custom_metal = '';
            }
            line.line_purity_choice = '';
            line.line_purity = '';
            line.line_purity_value = '';
        },

        customMetalChanged(line) {
            line.line_metal_type = line.line_custom_metal.trim();
        },

        purityOptions(line) {
            return this.purityProfiles.filter((profile) => profile.metal === line.line_metal_type);
        },

        purityChanged(line) {
            if (line.line_purity_choice === '__custom') {
                line.line_purity_value = '';
                line.line_purity = '';
                return;
            }

            const profile = this.purityOptions(line).find((item) => String(item.value) === String(line.line_purity_choice));
            line.line_purity_value = profile ? profile.value : '';
            line.line_purity = profile ? profile.label : '';
        },

        customPurityChanged(line) {
            line.line_purity = line.line_purity_value === '' ? '' : String(line.line_purity_value);
        },

        recalculate(line, field) {
            line[`${field}_mode`] = 'auto';
            line[`${field}_recalculate`] = '';
            this.calculateLine(line);
            this.syncDocumentTotals();
        },

        calculateLine(line) {
            if (line.line_calculation_enabled !== '1' || this.isBlank(line)) return;

            const gross = this.number(line.line_gross_weight);
            const net = this.number(line.line_net_weight);
            if (line.line_billable_weight_mode === 'auto') {
                if (line.line_billable_weight_basis === 'gross') line.line_billable_weight = this.format(gross, 3);
                if (line.line_billable_weight_basis === 'net') line.line_billable_weight = this.format(net, 3);
            }

            const weight = this.number(line.line_billable_weight);
            const rate = this.number(line.line_rate);
            const purity = this.number(line.line_purity_value);
            const multiplier = this.fineMultiplier(line.line_metal_type, purity);
            // Fine weight always uses the TRUE multiplier — it is metal-content
            // bookkeeping and does not care how the rate was quoted. Only the
            // PRICE multiplier depends on the rate basis.
            line.line_fine_weight = weight === null || multiplier === null ? '' : this.format(weight * multiplier, 3);
            const priceMultiplier = line.line_rate_basis === 'pure_reference' ? multiplier : 1;
            const metal = weight === null || rate === null || multiplier === null ? null : weight * rate * priceMultiplier;
            this.applyAuto(line, 'line_metal_value', metal);

            const stoneWeight = this.number(line.line_stone_weight);
            const stoneRate = this.number(line.line_stone_rate);
            const stone = stoneWeight === null || stoneRate === null ? null : stoneWeight * stoneRate;
            this.applyAuto(line, 'line_stone_value', stone);

            const metalValue = this.number(line.line_metal_value);
            const makingValue = this.number(line.line_making_value);
            const quantity = this.number(line.line_quantity);
            let making = null;
            if (makingValue !== null) {
                making = {
                    fixed_invoice: makingValue,
                    fixed_line: makingValue,
                    per_item: quantity === null ? null : makingValue * quantity,
                    per_gram: net === null ? null : makingValue * net,
                    percent: metalValue === null ? null : makingValue * metalValue / 100,
                    included: 0,
                    informational: 0,
                }[line.line_making_basis] ?? null;
            }
            this.applyAuto(line, 'line_making_amount', making);

            const wastageValue = this.number(line.line_wastage_value);
            const wastage = line.line_wastage_basis === 'flat'
                ? wastageValue
                : (line.line_wastage_basis === 'percent' && wastageValue !== null && metalValue !== null ? wastageValue * metalValue / 100 : null);
            this.applyAuto(line, 'line_wastage_amount', wastage);

            const charges = ['line_hallmark_charge', 'line_rhodium_charge', 'line_other_charge']
                .reduce((sum, field) => sum + (this.number(line[field]) || 0), 0);
            const subtotal = (metalValue || 0)
                + (this.number(line.line_making_amount) || 0)
                + (this.number(line.line_wastage_amount) || 0)
                + (this.number(line.line_stone_value) || 0)
                + charges;
            const discountInput = this.number(line.line_discount_value) || 0;
            const discount = line.line_discount_type === 'percent'
                ? Math.min(subtotal, Math.max(0, subtotal * discountInput / 100))
                : (line.line_discount_type === 'fixed' ? Math.min(subtotal, Math.max(0, discountInput)) : 0);
            this.applyAuto(line, 'line_discount_amount', discount);

            const afterDiscount = Math.max(0, subtotal - (this.number(line.line_discount_amount) || 0));
            const gstRate = this.number(line.line_gst_rate) || 0;
            let taxable = afterDiscount;
            let total = afterDiscount;
            if (line.line_tax_mode === 'gst_inclusive') {
                taxable = afterDiscount / (1 + gstRate / 100);
            } else if (line.line_tax_mode === 'gst_exclusive') {
                total = afterDiscount + afterDiscount * gstRate / 100;
            }
            this.applyAuto(line, 'line_taxable', taxable);
            this.applyAuto(line, 'line_total', total);

            const missing = [];
            if (!line.line_billable_weight_basis) missing.push('choose billable-weight basis');
            if (weight === null) missing.push('enter billable weight');
            if (rate === null) missing.push('enter historical rate');
            if (['gold', 'silver'].includes(line.line_metal_type) && purity === null) missing.push('choose purity');
            line.line_missing = missing.join(' · ');
        },

        fineMultiplier(metal, purity) {
            if (metal === 'gold') return purity === null ? null : purity / 24;
            if (metal === 'silver') return purity === null ? null : purity / 1000;
            return 1;
        },

        makingUnit(basis) {
            return {
                percent: '%',
                fixed_invoice: '₹',
                fixed_line: '₹',
                per_gram: '₹/g',
                per_item: '₹/piece',
            }[basis] || '';
        },

        wastageUnit(basis) {
            return { percent: '%', flat: '₹' }[basis] || '';
        },

        discountUnit(type) {
            return { percent: '%', fixed: '₹' }[type] || '';
        },

        outlierWarnings(line) {
            const warnings = [];
            const makingValue = this.number(line.line_making_value);
            const wastageValue = this.number(line.line_wastage_value);
            const discountValue = this.number(line.line_discount_value);
            const number = (value) => Number(value).toLocaleString('en-IN', { maximumFractionDigits: 2 });
            const rupees = (value) => `₹${number(value)}`;

            if (line.line_making_basis === 'percent' && makingValue > 100) {
                warnings.push(`${number(makingValue)} with Percent selected means ${number(makingValue)}%. Did you intend a flat ${rupees(makingValue)} charge?`);
            }
            if (line.line_wastage_basis === 'percent' && wastageValue > 100) {
                warnings.push(`${number(wastageValue)} with Percent selected means ${number(wastageValue)}% wastage. Check the original bill.`);
            }
            if (line.line_discount_type === 'percent' && discountValue > 100) {
                warnings.push(`${number(discountValue)} with Percent selected means a ${number(discountValue)}% discount. Check the original bill.`);
            }

            const metalValue = this.number(line.line_metal_value);
            if (metalValue > 0) {
                for (const [label, value] of [
                    ['Making amount', this.number(line.line_making_amount)],
                    ['Wastage amount', this.number(line.line_wastage_amount)],
                    ['Other charges', ['line_hallmark_charge', 'line_rhodium_charge', 'line_other_charge'].reduce((sum, field) => sum + (this.number(line[field]) || 0), 0)],
                ]) {
                    if (value > 10000 && value > metalValue * 2) warnings.push(`${label} ${rupees(value)} is more than twice the metal value. Confirm the selected basis and unit.`);
                }
            }

            const total = this.number(line.line_total);
            if (total > 10000000 && this.number(line.line_rate) !== null && this.number(line.line_billable_weight) !== null) {
                warnings.push(`Line total ${rupees(total)} is unusually large. Confirm the weight, rate and selected units.`);
            }

            return warnings;
        },

        applyAuto(line, field, value) {
            if (line[`${field}_mode`] === 'auto') line[field] = this.format(value, field === 'line_billable_weight' ? 3 : 2);
        },

        syncDocumentTotals() {
            const rows = this.lines.filter((line) => !this.isBlank(line));
            const sums = {
                taxable_amount: this.sum(rows, 'line_taxable'),
                tax_total: rows.reduce((sum, line) => sum + Math.max(0, (this.number(line.line_total) || 0) - (this.number(line.line_taxable) || 0)), 0),
                discount: this.sum(rows, 'line_discount_amount'),
                metal_value: this.sum(rows, 'line_metal_value'),
                stone_value: this.sum(rows, 'line_stone_value'),
                grand_total: this.sum(rows, 'line_total'),
            };

            // Batch 3 §7/§9 — paid/outstanding follow the same auto/manual state
            // machine as every other document total. "Effective" reads whichever
            // value currently governs (a manual override, or the fresh auto sum)
            // so outstanding cascades correctly even when grand_total itself is
            // a manual override, not the just-computed line sum above.
            const effectiveGrandTotal = this.documentModes.grand_total === 'manual'
                ? this.number(this.documentTotals.grand_total) : sums.grand_total;
            const effectivePaid = this.documentModes.paid_amount === 'manual'
                ? this.number(this.documentTotals.paid_amount) : this.paidTotal;

            sums.paid_amount = this.paidTotal;
            sums.outstanding_amount = Math.max((effectiveGrandTotal || 0) - (effectivePaid || 0), 0);

            for (const [field, value] of Object.entries(sums)) {
                if ((this.documentModes[field] || 'auto') === 'auto') this.documentTotals[field] = this.format(value, 2);
            }
        },

        markDocumentManual(field) {
            this.documentModes[field] = 'manual';
        },

        recalculateDocument(field) {
            this.documentModes[field] = 'auto';
            this.syncDocumentTotals();
        },

        sum(rows, field) {
            return rows.reduce((sum, line) => sum + (this.number(line[field]) || 0), 0);
        },

        number(value) {
            if (value === '' || value === null || value === undefined) return null;
            const number = Number(value);
            return Number.isFinite(number) ? number : null;
        },

        format(value, precision = 2) {
            if (value === null || value === undefined || !Number.isFinite(Number(value))) return '';
            return String(Number(Number(value).toFixed(precision)));
        },
    }));

    Alpine.data('historicalCustomerPicker', (config) => ({
        searchUrl: config.searchUrl,
        search: '',
        results: [],
        open: false,
        loading: false,
        activeIndex: -1,
        customerId: config.customerId || '',
        selectedLabel: config.selectedLabel || '',
        addOnPublish: Boolean(config.addOnPublish),
        archivedMatch: false,
        request: null,

        init() {
            this.updateStatus();
        },

        get status() {
            if (this.customerId) return 'existing';
            if (this.archivedMatch) return 'archived';

            const name = this.$root.querySelector('[name="customer_name"]')?.value.trim() || '';
            const mobile = this.$root.querySelector('[name="customer_mobile"]')?.value.replace(/\D/g, '') || '';

            return this.addOnPublish && name && mobile.length === 10 ? 'new' : 'snapshot';
        },

        updateStatus() {
            this.addOnPublish = this.$root.querySelector('[name="add_customer_on_publish"]:checked') !== null;
        },

        clearSelection(clearSearch = true) {
            this.customerId = '';
            this.selectedLabel = '';
            if (clearSearch) this.search = '';
        },

        async findCustomers() {
            this.clearSelection(false);
            this.archivedMatch = false;
            this.activeIndex = -1;

            const query = this.search.trim();
            if (query.length < 2) {
                this.results = [];
                this.open = false;
                return;
            }

            if (this.request) this.request.abort();
            this.request = new AbortController();
            this.loading = true;
            this.open = true;

            try {
                const response = await fetch(`${this.searchUrl}?q=${encodeURIComponent(query)}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    signal: this.request.signal,
                });
                const data = await response.json();
                this.results = response.ok ? data.results || [] : [];
                this.archivedMatch = data.status === 'archived_action_required';
                this.open = true;
            } catch (error) {
                if (error.name !== 'AbortError') this.open = false;
            } finally {
                this.loading = false;
            }
        },

        move(direction) {
            if (!this.open || this.results.length === 0) return;
            this.activeIndex = (this.activeIndex + direction + this.results.length) % this.results.length;
        },

        chooseActive() {
            if (this.activeIndex >= 0) this.select(this.results[this.activeIndex]);
        },

        select(customer) {
            this.customerId = customer.id;
            this.selectedLabel = `${customer.name} · ${customer.mobile_masked}`;
            this.search = customer.name;
            this.results = [];
            this.archivedMatch = false;
            this.open = false;
        },

        close() {
            this.open = false;
            this.activeIndex = -1;
        },
    }));
}
