export function compareReportValues(a, b, numeric, descending = false) {
    const emptyA = a == null || String(a).trim() === '';
    const emptyB = b == null || String(b).trim() === '';
    if (emptyA || emptyB) return emptyA === emptyB ? 0 : emptyA ? 1 : -1;
    const result = numeric && Number.isFinite(Number(a)) && Number.isFinite(Number(b))
        ? Number(a) - Number(b)
        : String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' });
    return descending ? -result : result;
}

const mounted = new WeakSet();
export function initializeReportScreens(doc = document) {
    const mount = () => doc.querySelectorAll('[data-report-table]').forEach(table => {
        if (mounted.has(table) || !table.tHead || !table.tBodies[0]) return;
        mounted.add(table);
        const headers = [...table.tHead.rows[0].cells];
        headers.forEach((header, index) => header.querySelector('[data-report-sort]')?.addEventListener('click', () => {
            const descending = header.getAttribute('aria-sort') === 'ascending';
            headers.forEach(h => h.setAttribute('aria-sort', 'none'));
            header.setAttribute('aria-sort', descending ? 'descending' : 'ascending');
            const body = table.tBodies[0];
            const rows = [...body.rows].filter(row => !row.hasAttribute('data-empty'));
            rows.sort((a, b) => compareReportValues(a.cells[index]?.dataset.sortValue, b.cells[index]?.dataset.sortValue, header.dataset.numeric === '1', descending));
            rows.forEach((row, index) => {
                body.appendChild(row);
                const number = row.querySelector('[data-row-index]');
                if (number) number.textContent = String(index + 1);
            });
            if (table.dataset.hasRunningBalance === '1') {
                // Original per-entry balances stay authoritative even in a non-date sort.
                const note = table.closest('[data-report-section]')?.querySelector('[data-balance-note]');
                if (note) note.textContent = 'Running balances belong to their original entries; sorting does not recalculate them.';
            }
        }));
    });
    doc.addEventListener('turbo:load', mount);
    if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', mount, { once: true });
    else mount();
}
