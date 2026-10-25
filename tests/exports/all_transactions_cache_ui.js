const assert = require('assert').strict;
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const blade = fs.readFileSync(path.join(__dirname, '../../resources/views/viefund-transactions/index.blade.php'), 'utf8');
const source = blade.replace(/\{\{\s*route\('([^']+)'[^}]*\}\}/g, '/$1')
    .replace(/\{\{\s*\$perPage\s*\}\}/g, '100');
const section = (from, to) => source.slice(source.indexOf(from), source.indexOf(to));
const script = new vm.Script([
    section('    const parseJsonResponse =', '    const filterForm ='),
    section("    cacheControlButton?.addEventListener('click'", '    const totalSummary ='),
    section('    let totalPollAttempts =', '    const periodSummary ='),
    section('    const periodSummary =', '    const showStatus ='),
].join('\n'));
const flush = () => new Promise(resolve => setImmediate(resolve));

function createPage(initialState, summaryState = 'complete') {
    const state = { cache: initialState, summary: summaryState, requests: [], timers: [], reloads: 0, elements: {}, schedules: [] };
    const element = () => ({
        textContent: 'Loading', style: {}, dataset: {}, handlers: {},
        addEventListener(name, handler) { this.handlers[name] = handler; },
        removeAttribute() {}, replaceChildren() {}, append() {},
        classList: { toggle() {} },
    });
    const ids = [
        'all-transactions-summary', 'all-transactions-summary-opening', 'all-transactions-summary-period',
        'all-transactions-summary-net', 'all-transactions-summary-closing', 'all-transactions-summary-count',
        'all-transactions-summary-source', 'all-transactions-summary-source-badge', 'all-transactions-summary-error',
        'all-transactions-match-summary', 'all-transactions-filtered-summary', 'all-transactions-match-summary-body',
        'all-transactions-match-summary-state', 'all-transactions-filtered-state', 'all-transactions-filtered-count',
        'all-transactions-filtered-outflow', 'all-transactions-filtered-inflow', 'all-transactions-filtered-net',
        'all-transactions-summary-retry',
    ];
    ids.forEach(id => { state.elements[id] = element(); });
    const cells = [element(), element()];
    state.elements['all-transactions-match-summary-body'].querySelectorAll = () => cells;
    const button = element();
    button.dataset.action = initialState === 'failed' || initialState === 'paused' ? 'resume' : 'pause';
    const workingSetStatus = {
        dataset: { ready: '0', state: initialState, resumeUrl: '/cache/resume', pauseUrl: '/cache/pause', statusUrl: '/cache/status' },
    };
    const totalSummary = element();
    const context = vm.createContext({
        totalSummary, cacheControlButton: button, workingSetStatus,
        knownTotalRecords: null,
        renderNumberedPagination() {},
        renderWorkingSetStatus(status) { workingSetStatus.dataset.state = status.state; },
        scheduleWorkingSetPoll(delay) { state.schedules.push(delay); },
        setWorkingSetStatus() {},
        document: {
            getElementById: id => state.elements[id] || null,
            querySelector: () => ({ content: 'test-csrf' }),
            createElement: element, createTextNode: value => value,
        },
        URL, console,
        window: {
            location: {
                href: 'https://example.test/transactions', origin: 'https://example.test', search: '',
                reload() { state.reloads += 1; },
            },
            setTimeout(fn) { state.timers.push(fn); },
        },
        fetch: async (url, options = {}) => {
            state.requests.push({ url, options });
            let status = 200;
            let data;
            if (url === '/cache/resume') {
                state.cache = 'warming';
                data = { state: 'warming', ready: false, can_pause: true, queryable: true };
            } else if (url.includes('.count')) {
                data = { state: state.cache === 'ready' ? 'complete' : 'partial', total: 1248000, cache_state: state.cache };
            } else if (state.cache === 'failed' || state.cache === 'paused') {
                data = { state: state.cache, message: `Use ${state.cache === 'failed' ? 'Retry' : 'Resume'} period cache to continue.` };
            } else if (state.cache === 'warming') {
                status = 202;
                data = { state: 'processing', message: 'Waiting for period cache.' };
            } else if (url.includes('match-summary')) {
                if (state.summary === 'failed' && !url.includes('retry_summary=1')) {
                    data = { state: 'failed', retryable: true, message: 'Summary failed. Retry summaries to continue.' };
                } else if (state.summary === 'processing' || url.includes('retry_summary=1')) {
                    state.summary = 'processing';
                    status = 202;
                    data = { state: 'processing', message: 'Building summaries: 1,000 of 1,272,452 transactions processed.' };
                } else {
                    data = { state: 'complete', currency: 'CAD', totals: { transaction_count: 1272452 }, statuses: [] };
                }
            } else {
                data = { opening_balance: 10, period_net: 5, closing_balance: 15, transaction_count: 1272452, balance_source: 'Test cache' };
            }
            return { ok: true, status, json: async () => data, text: async () => JSON.stringify(data) };
        },
    });
    script.runInContext(context);
    return { state, button, totalSummary, cells };
}

(async () => {
    for (const cacheState of ['failed', 'paused']) {
        const page = createPage(cacheState);
        await flush();
        const countTimer = page.state.timers.shift();
        await countTimer();
        await flush();
        assert.equal(page.state.timers.length, 0, `${cacheState} summaries and partial counts must stop polling.`);
        assert.match(page.state.elements['all-transactions-summary-error'].textContent, /Retry|Resume/);
        assert.match(page.state.elements['all-transactions-match-summary-state'].textContent, /Retry|Resume/);
        assert.equal(page.state.elements['all-transactions-summary-net'].textContent, 'Unavailable');
        assert.equal(page.state.elements['all-transactions-filtered-net'].textContent, 'Unavailable');
        assert(page.cells.every(cell => cell.textContent === 'Unavailable'), 'Failed match cells must not remain Loading.');
        assert.equal(page.state.elements['all-transactions-summary-retry'].hidden, true,
            'Stopped period caches must use their cache controls, not offer a misleading summary retry.');
        assert.match(page.totalSummary.textContent, new RegExp(`cache ${cacheState}`));
        await page.button.handlers.click();
        assert.equal(page.state.reloads, 1, 'Successful retry/resume must reload and restart stopped summaries.');
        assert.equal(page.state.requests.filter(request => request.url === '/cache/resume').length, 1);
    }
    const page = createPage('warming');
    await flush();
    const pending = page.state.timers.splice(0);
    assert.equal(pending.length, 3, 'Active caches must retain summary and filtered-count polling.');
    page.state.cache = 'ready';
    for (const callback of pending) await callback();
    await flush();
    assert.equal(page.state.timers.length, 0, 'Completed summaries must stop polling.');
    assert.equal(page.state.elements['all-transactions-summary-net'].textContent, '$5.00');
    assert.equal(page.state.elements['all-transactions-filtered-count'].textContent, '1,272,452');
    assert.match(page.state.elements['all-transactions-match-summary-state'].textContent, /Complete/);
    const failedSummary = createPage('ready', 'failed');
    await flush();
    const retry = failedSummary.state.elements['all-transactions-summary-retry'];
    assert.equal(retry.hidden, false, 'A failed background summary must expose its own retry button.');
    assert.equal(failedSummary.state.elements['all-transactions-filtered-net'].textContent, 'Unavailable');
    await retry.handlers.click();
    assert.equal(retry.disabled, false);
    assert.equal(retry.hidden, true, 'Retry control should hide once the saved run is active again.');
    assert(failedSummary.state.requests.some(request => request.url.includes('retry_summary=1')));
    assert.equal(failedSummary.state.elements['all-transactions-filtered-net'].textContent, 'Loading…');
    assert.match(failedSummary.state.elements['all-transactions-match-summary-state'].textContent, /1,000 of 1,272,452/);
    failedSummary.state.summary = 'complete';
    const summaryPoll = failedSummary.state.timers.pop();
    await summaryPoll();
    await flush();
    assert.equal(failedSummary.state.elements['all-transactions-filtered-count'].textContent, '1,272,452');
    assert.equal(failedSummary.state.requests.filter(request => request.url.includes('retry_summary=1')).length, 1,
        'Normal progress polling must not repeatedly request a retry.');
    console.log('PASS: failed/paused caches stop polling and show guidance; retry/resume reloads; active summaries recover on completion');
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
