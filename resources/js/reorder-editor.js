export function createReorderEditor(config) {
    const blank = { id: '', action: config.createUrl, category: '', sub_category: '', min_stock_threshold: '', vendor_id: '', is_active: true };
    return {
        form: { ...blank },
        showErrors: false,
        errors: config.errors ?? [],
        categories: config.categories ?? [],
        vendorIds: config.vendorIds ?? [],
        submitting: false,
        get editing() { return Boolean(this.form.id); },
        get subCategories() {
            const options = config.subcategories[this.form.category];
            return Array.isArray(options) ? options : [];
        },
        init() {
            if (config.hasErrors && this.$refs.ruleDialog.dataset.reopen !== 'false') this.open(config.initialRule, true);
        },
        open(rule = null, preserveErrors = false) {
            if (this.submitting) return;
            this.form = { ...blank, ...rule };
            this.showErrors = preserveErrors;
            this.$nextTick(() => {
                const dialog = this.$refs.ruleDialog;
                if (!dialog.open) dialog.showModal();
                dialog.querySelector(preserveErrors ? '[aria-invalid="true"]' : '[name="category"]')?.focus();
            });
        },
        close() {
            if (!this.submitting) this.$refs.ruleDialog.close();
        },
        cleanup() {
            const dialog = this.$refs.ruleDialog;
            dialog.close();
            dialog.dataset.reopen = 'false';
            this.submitting = false;
        },
    };
}
