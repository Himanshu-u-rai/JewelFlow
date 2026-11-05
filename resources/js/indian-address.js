export function createIndianAddress() {
    return {
        loading: false,
        message: '',
        lookupFailed: false,
        request: null,
        revision: 0,
        edits: { city: 0, state: 0 },
        auto: { city: null, state: null },
        enhanced: false,
        states: [],
        stateQuery: '',
        stateOpen: false,
        activeState: -1,
        init() {
            if (typeof this.$refs.stateMenu?.showPopover !== 'function') return;
            this.states = Array.from(this.$refs.states.options, option => option.value);
            this.$refs.state.removeAttribute('list');
            this.enhanced = true;
        },
        get stateMatches() {
            const query = this.stateQuery.trim().toLowerCase();
            return this.states.filter(name => name.toLowerCase().includes(query));
        },
        openStates(filter = false) {
            if (!this.enhanced) return;
            this.stateQuery = filter ? this.$refs.state.value : '';
            this.activeState = -1;
            this.stateOpen = true;
            if (!this.$refs.stateMenu.matches(':popover-open')) this.$refs.stateMenu.showPopover();
            this.$nextTick(() => this.placeStates());
        },
        closeStates() {
            this.stateOpen = false;
            this.activeState = -1;
            if (this.enhanced && this.$refs.stateMenu?.matches(':popover-open')) this.$refs.stateMenu.hidePopover();
        },
        moveState(direction) {
            if (!this.stateOpen) this.openStates(true);
            const count = this.stateMatches.length;
            if (!count) return;
            this.activeState = this.activeState < 0 ? (direction > 0 ? 0 : count - 1) : (this.activeState + direction + count) % count;
            this.$nextTick(() => this.$refs.stateMenu.querySelector(`[data-index="${this.activeState}"]`)?.scrollIntoView({ block: 'nearest' }));
        },
        chooseState(name = this.stateMatches[this.activeState]) {
            if (!name) return;
            this.$refs.state.value = name;
            this.$refs.state.dispatchEvent(new Event('input', { bubbles: true }));
            this.closeStates();
            this.$refs.state.focus();
        },
        placeStates(win = window) {
            const box = this.$refs.state.getBoundingClientRect();
            const menu = this.$refs.stateMenu;
            const below = Math.max(0, win.innerHeight - box.bottom - 12);
            const above = Math.max(0, box.top - 12);
            const down = below >= Math.min(224, above);
            const height = Math.min(224, down ? below : above);
            const width = Math.min(box.width, win.innerWidth - 16);
            menu.style.width = `${width}px`;
            menu.style.left = `${Math.max(8, Math.min(box.left, win.innerWidth - width - 8))}px`;
            menu.style.maxHeight = `${height}px`;
            menu.style.top = `${down ? box.bottom + 4 : box.top - Math.min(height, menu.scrollHeight) - 4}px`;
            menu.style.visibility = 'visible';
        },
        edited(field) {
            this.edits[field]++;
            this.auto[field] = null;
        },
        cancel() {
            this.revision++;
            this.request?.abort();
            this.request = null;
            this.loading = false;
            this.message = '';
            this.lookupFailed = false;
            this.closeStates();
        },
        destroy() { this.cancel(); },
        async lookup(fetcher = fetch) {
            this.cancel();
            const pin = this.$refs.pin.value.trim();
            if (!/^[1-9]\d{5}$/.test(pin)) return;
            const revision = this.revision;
            const edits = { ...this.edits };
            this.request = new AbortController();
            this.loading = true;
            this.message = 'Looking up PIN…';
            try {
                const response = await fetcher(this.$el.dataset.lookupUrl.replace('__PIN__', pin), {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal: this.request.signal,
                });
                if (!response.ok) throw new Error('Lookup unavailable');
                const data = await response.json();
                if (revision !== this.revision || this.$refs.pin.value.trim() !== pin) return;
                if (data?.found === false && data.reason === 'not_found') {
                    this.lookupFailed = true;
                    this.message = 'No area found for this PIN. Please type your city and state.';
                    return;
                }
                if (data?.found !== true || !['city', 'state'].every(field => typeof data[field] === 'string' && data[field].trim() && data[field].length <= 100)) {
                    throw new Error('Area not found');
                }
                for (const field of ['city', 'state']) {
                    const input = this.$refs[field];
                    if (this.edits[field] !== edits[field] || (input.value && input.value !== this.auto[field])) continue;
                    input.value = data[field].trim();
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    this.auto[field] = input.value;
                }
                this.message = `PIN suggests ${data.city}, ${data.state} (postal district). Check city/locality; existing edits were kept.`;
            } catch (error) {
                if (revision === this.revision) {
                    this.lookupFailed = true;
                    this.message = 'PIN lookup is temporarily unavailable. Please type your city and state, or retry.';
                }
            } finally {
                if (revision === this.revision) {
                    this.loading = false;
                    this.request = null;
                }
            }
        },
    };
}
