const assert = require('assert').strict;
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const blade = fs.readFileSync(path.join(__dirname, '../../resources/views/admin/settings/index.blade.php'), 'utf8');
const script = new vm.Script(blade.slice(
    blade.indexOf('const mirrorStatusSection ='),
    blade.indexOf('const navigationList =')
));
const elements = {};
const section = {
    dataset: { mirrorStatusUrl: '/mirror/status' },
    querySelector(selector) {
        elements[selector] ??= { textContent: '', style: {} };
        return elements[selector];
    },
};
let refresh;
let status = {
    state: 'running', phase: 'cashagch_verification', phase_label: 'CASHagch historical coverage',
    rows_label: 'Backfill rows (complete)', rows_processed: 1241867, expected_rows: 1241867,
    fund_ids_ready: true, cashagch_ready: false,
    cashagch_status_label: 'Historical coverage verification in progress',
    throughput_label: 'Row backfill complete', rows_per_second: null,
    eta_label: 'Coverage verification (ETA unavailable)', eta_seconds: null,
    estimated_completion_at: null, progress_pct: 100, maintenance_phase: 'available',
    activity_message: 'Backfill complete. Finding the first CASHagch transaction for Created date / USD.',
};
script.runInNewContext({
    document: { querySelector: () => section },
    fetch: async () => ({ ok: true, json: async () => status }),
    window: { setInterval(callback, delay) { assert.equal(delay, 5000); refresh = callback; } },
    console,
});

(async () => {
    await refresh();
    await refresh();
    assert.equal(elements['[data-mirror-fund-ids]'].textContent, 'Verified');
    assert.equal(elements['[data-mirror-cashagch]'].textContent, 'Historical coverage verification in progress');
    assert.equal(elements['[data-mirror-rows-label]'].textContent, 'Backfill rows (complete)');
    assert.match(elements['[data-mirror-eta]'].textContent, /ETA unavailable/);
    assert.match(elements['[data-mirror-detail]'].textContent, /Created date \/ USD/);
    assert.doesNotMatch(elements['[data-mirror-detail]'].textContent, /Estimated completion/);
    status = { ...status, activity_message: 'Backfill complete. Checking CASHagch historical coverage for Trade date / CAD / 2026-01.' };
    await refresh();
    assert.match(elements['[data-mirror-detail]'].textContent, /2026-01/);
    status = { ...status, maintenance_phase: 'blackout' };
    await refresh();
    assert.match(elements['[data-mirror-detail]'].textContent, /scheduled blackout/);
    status = {
        ...status, state: 'ready', phase: 'ready', phase_label: 'ready', cashagch_ready: true,
        cashagch_status_label: 'Verified', eta_label: 'Complete', activity_message: null,
        rows_label: 'Rows', throughput_label: null, rows_per_second: 100,
        maintenance_phase: 'available', synced_through_at: '2026-10-25T03:40:00Z',
    };
    await refresh();
    assert.equal(elements['[data-mirror-cashagch]'].textContent, 'Verified');
    assert.equal(elements['[data-mirror-eta]'].textContent, 'Complete');
    assert.match(elements['[data-mirror-detail]'].textContent, /Last verified synchronization/);
    console.log('PASS: separate backfill/coverage progress, stable ETA, current audit scope, maintenance and verified completion');
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
