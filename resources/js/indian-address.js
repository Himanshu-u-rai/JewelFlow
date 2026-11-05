export function createIndianAddress() {
    return {
        loading: false,
        message: '',
        request: null,
        revision: 0,
        edits: { city: 0, state: 0 },
        auto: { city: null, state: null },
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
                if (revision === this.revision) this.message = 'Could not look up this PIN. Please type your city and state.';
            } finally {
                if (revision === this.revision) {
                    this.loading = false;
                    this.request = null;
                }
            }
        },
    };
}
