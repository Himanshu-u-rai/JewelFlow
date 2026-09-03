export function registerHistoricalManual(Alpine) {
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
