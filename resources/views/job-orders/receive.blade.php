<x-app-layout>
    <x-page-header :title="'Receive Items — ' . $jobOrder->job_order_number" :subtitle="'Karigar: ' . ($jobOrder->karigar?->name ?? '—')" class="job-record-header">
        <x-slot:actions>
            <a href="{{ route('job-orders.show', $jobOrder) }}" class="btn btn-secondary btn-sm"><x-icon name="arrow-left" size="16" />Back to Job Order</a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner job-receive-page job-record-page">
        <x-app-alerts />

        <section class="job-record-surface" aria-label="Current job weights">
            <div class="job-record-metrics">
                <dl class="job-record-metric" data-receive-position="issued"><dt>Issued fine</dt><dd>{{ number_format($jobOrder->issued_fine_weight, 3) }}g</dd></dl>
                <dl class="job-record-metric" data-receive-position="returned"><dt>Already received</dt><dd>{{ number_format($jobOrder->returned_fine_weight, 3) }}g</dd></dl>
                <dl class="job-record-metric" data-receive-position="outstanding"><dt>Outstanding</dt><dd>{{ number_format($jobOrder->outstanding_fine, 3) }}g</dd></dl>
                <dl class="job-record-metric" data-receive-position="allowed"><dt>Allowed wastage</dt><dd>{{ number_format($jobOrder->allowed_wastage_fine, 3) }}g</dd><small>{{ $jobOrder->allowed_wastage_percent }}%</small></dl>
            </div>
        </section>

        <form method="POST" action="{{ route('job-orders.receive.store', $jobOrder) }}" class="job-receive-form job-record-surface"
              x-data="receiveForm({{ (float) $jobOrder->purity }}, '{{ $jobOrder->metal_type }}')">
            @csrf
            <div class="job-receive-fields">
                <div>
                    <label class="job-record-label" for="job-receipt-date">Receipt Date *</label>
                    <input id="job-receipt-date" type="date" name="receipt_date" required value="{{ now()->toDateString() }}" class="job-record-control">
                </div>
                <div>
                    <label class="job-record-label" for="job-receipt-notes">Notes</label>
                    <input id="job-receipt-notes" type="text" name="notes" class="job-record-control">
                </div>
                <div>
                    <label class="job-record-label" for="job-retained">Metal kept by karigar (g)</label>
                    <input id="job-retained" type="number" step="0.001" min="0" name="retained_fine_weight" value="0" class="job-record-control" aria-describedby="job-retained-help">
                    <p id="job-retained-help" class="job-record-hint">Fine gold the karigar is keeping from this job. It stays in their balance for future work (not wastage). Leave 0 if none.</p>
                </div>
            </div>

            <section class="job-receive-lines" aria-labelledby="job-finished-title">
                <header class="job-record-head">
                    <h2 id="job-finished-title">Finished Items Received</h2>
                    <button type="button" @click="addLine" class="btn btn-secondary btn-sm"><x-icon name="plus" size="16" />Add Line</button>
                </header>
                <div class="job-receive-line-table job-record-scroll jf-scrollbar" tabindex="0" role="region" aria-label="Finished items received">
                    <table class="jf-grid job-receive-grid">
                        <thead>
                            <tr>
                                <th scope="col" class="job-grid-index">#</th>
                                <th scope="col" class="job-grid-description">Description *</th>
                                <th scope="col" class="job-grid-code">HSN</th>
                                <th scope="col" class="job-grid-pieces">Pcs *</th>
                                <th scope="col" class="job-grid-weight">Gross (g) *</th>
                                <th scope="col" class="job-grid-weight">Stone (g)</th>
                                <th scope="col" class="job-grid-weight">Net (g) *</th>
                                <th scope="col" class="job-grid-purity">Purity *</th>
                                <th scope="col" class="job-grid-action"><span class="jf-sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(line, idx) in lines" :key="idx">
                                <tr data-turbo-temporary>
                                    <td class="job-grid-index" x-text="idx + 1"></td>
                                    <td><input type="text" :name="'items[' + idx + '][description]'" required x-model="line.description" :aria-label="'Description, item line ' + (idx + 1)"></td>
                                    <td><input type="text" :name="'items[' + idx + '][hsn_code]'" x-model="line.hsn_code" placeholder="7113" :aria-label="'HSN, item line ' + (idx + 1)"></td>
                                    <td><input type="number" min="1" :name="'items[' + idx + '][pieces]'" required x-model="line.pieces" :aria-label="'Pieces, item line ' + (idx + 1)"></td>
                                    <td><input type="number" step="0.001" min="0.001" :name="'items[' + idx + '][gross_weight]'" required x-model="line.gross_weight" @input="recompute(idx)" :aria-label="'Gross weight in grams, item line ' + (idx + 1)"></td>
                                    <td><input type="number" step="0.001" min="0" :name="'items[' + idx + '][stone_weight]'" x-model="line.stone_weight" @input="recompute(idx)" :aria-label="'Stone weight in grams, item line ' + (idx + 1)"></td>
                                    <td><input type="number" step="0.001" min="0.001" :name="'items[' + idx + '][net_weight]'" required x-model="line.net_weight" :aria-label="'Net weight in grams, item line ' + (idx + 1)"></td>
                                    <td><input type="number" step="0.01" min="1" :max="metalType === 'silver' ? 1000 : 24" :name="'items[' + idx + '][purity]'" required x-model="line.purity" :aria-label="(metalType === 'silver' ? 'Purity in per mille' : 'Purity in karats') + ', item line ' + (idx + 1)"></td>
                                    <td><button type="button" x-show="lines.length > 1" @click="removeLine(idx)" class="jf-row-action jf-row-action--danger" data-tip="Remove line" :aria-label="'Remove item line ' + (idx + 1)"><x-icon name="trash-2" size="16" /></button></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="job-receive-totals" aria-label="Receipt totals">
                <div class="job-record-metrics">
                    <dl class="job-record-metric"><dt>Total Pcs</dt><dd x-text="totalPieces">0</dd></dl>
                    <dl class="job-record-metric"><dt>Total Gross</dt><dd x-text="totalGross.toFixed(3) + 'g'">0.000g</dd></dl>
                    <dl class="job-record-metric"><dt>Total Net</dt><dd x-text="totalNet.toFixed(3) + 'g'">0.000g</dd></dl>
                    <dl class="job-record-metric"><dt>Total Fine (est.)</dt><dd x-text="totalFine.toFixed(3) + 'g'">0.000g</dd></dl>
                </div>
                <p class="job-receive-tolerance" x-show="exceedsTolerance" x-cloak><x-icon name="triangle-alert" size="18" class="flex-none" />Wastage may exceed tolerance. The system will flag this; you can still submit and acknowledge later.</p>
            </section>

            <footer class="job-receive-actions">
                <button type="submit" class="btn btn-primary btn-sm">Save Receipt</button>
                <a href="{{ route('job-orders.show', $jobOrder) }}" class="btn btn-secondary btn-sm">Cancel</a>
            </footer>
        </form>
    </div>

    <script>
        function receiveForm(defaultPurity, metalType) {
            return {
                metalType: metalType,
                lines: [{ description: '', hsn_code: '7113', pieces: 1, gross_weight: '', stone_weight: 0, net_weight: '', purity: defaultPurity }],
                get totalPieces() { return this.lines.reduce((s, l) => s + (parseInt(l.pieces) || 0), 0); },
                get totalGross() { return this.lines.reduce((s, l) => s + (parseFloat(l.gross_weight) || 0), 0); },
                get totalNet() { return this.lines.reduce((s, l) => s + (parseFloat(l.net_weight) || 0), 0); },
                // FIX #1 (JS) + #6: use karat-aware formula; gold = purity/24, silver = purity/1000
                get totalFine() {
                    return this.lines.reduce((s, l) => {
                        const net = parseFloat(l.net_weight) || 0;
                        const p   = parseFloat(l.purity) || 0;
                        return s + (this.metalType === 'silver' ? net * p / 1000 : net * p / 24);
                    }, 0);
                },
                // FIX #18: warn when wastage (issued - returned) would EXCEED allowed wastage
                get issuedFine() { return {{ (float) $jobOrder->issued_fine_weight }}; },
                get alreadyReturned() { return {{ (float) $jobOrder->returned_fine_weight }}; },
                get allowedWastage() { return {{ (float) $jobOrder->allowed_wastage_fine }}; },
                get exceedsTolerance() {
                    const actualWastage = this.issuedFine - this.alreadyReturned - this.totalFine;
                    return actualWastage > this.allowedWastage + 0.001;
                },
                addLine() { this.lines.push({ description: '', hsn_code: '7113', pieces: 1, gross_weight: '', stone_weight: 0, net_weight: '', purity: defaultPurity }); },
                removeLine(i) { this.lines.splice(i, 1); },
                recompute(idx) {
                    const g = parseFloat(this.lines[idx].gross_weight) || 0;
                    const s = parseFloat(this.lines[idx].stone_weight) || 0;
                    this.lines[idx].net_weight = Math.max(0, g - s).toFixed(3);
                },
            };
        }
    </script>
</x-app-layout>
