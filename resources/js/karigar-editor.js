export function createKarigarEditor(config = {}) {
    return {
        title: config.title || 'Add Karigar',
        url: '',
        loading: false,
        submitting: false,
        error: '',
        opener: null,
        init() {
            if (config.restore && this.$refs.editor.dataset.reopen !== 'false') {
                this.$refs.editor.showModal();
                this.$nextTick(() => this.focusForm());
            }
        },
        open(url, title, opener) {
            if (this.submitting) return;
            this.clear();
            this.opener = opener;
            this.url = url;
            this.title = title;
            this.loading = true;
            if (!this.$refs.editor.open) this.$refs.editor.showModal();
            this.$refs.frame.src = url;
        },
        focusForm() {
            if (this.$refs.editor.open) this.$refs.frame.querySelector('[data-editor-errors], [name="name"]')?.focus();
        },
        loaded(event) {
            if (event.target !== this.$refs.frame) return;
            this.loading = false;
            this.$nextTick(() => this.focusForm());
        },
        failed(event) {
            if (event.target !== this.$refs.frame) return;
            event.preventDefault();
            this.loading = false;
            this.error = 'The form could not load. Open the full page to continue or sign in again.';
        },
        close() {
            if (!this.submitting) this.$refs.editor.close();
        },
        clear() {
            // Removing src cancels Turbo's pending GET before another form opens.
            this.$refs.frame.removeAttribute('src');
            this.$refs.frame.querySelectorAll('[data-jf-date-picker="true"]').forEach(input => input._flatpickr?.destroy());
            this.$refs.frame.replaceChildren();
            this.loading = false;
            this.error = '';
        },
        dismissed() {
            this.clear();
            if (this.opener?.isConnected) this.opener.focus();
        },
        cleanup() {
            this.submitting = false;
            this.$refs.editor.close();
            this.clear();
            this.$refs.editor.dataset.reopen = 'false';
        },
    };
}

export function createKarigarFieldHelp() {
    return {
        help: false,
        place(win = window) {
            const bubble = this.$refs.bubble;
            const box = this.$refs.helpButton.getBoundingClientRect();
            const size = bubble.getBoundingClientRect();
            const above = box.top - size.height - 6;
            bubble.style.left = `${Math.max(8, Math.min(box.left + box.width / 2 - size.width / 2, win.innerWidth - size.width - 8))}px`;
            bubble.style.top = `${Math.max(8, Math.min(above >= 8 ? above : box.bottom + 6, win.innerHeight - size.height - 8))}px`;
            bubble.style.visibility = 'visible';
        },
        close() {
            if (this.$refs.bubble.matches(':popover-open')) this.$refs.bubble.hidePopover();
        },
    };
}
