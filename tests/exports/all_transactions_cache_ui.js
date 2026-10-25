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

function createPage(initialState) {
    const state = { cache: initialState, requests: [], timers: [], reloads: 0, elements: {}, schedules: [] };
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
                data = { state: 'complete', currency: 'CAD', totals: { transaction_count: 1272452 }, statuses: [] };
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
    console.log('PASS: failed/paused caches stop polling and show guidance; retry/resume reloads; active summaries recover on completion');
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
