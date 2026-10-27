const assert = require('assert').strict;
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const blade = fs.readFileSync(path.join(__dirname, '../../resources/views/viefund-transactions/index.blade.php'), 'utf8');
const source = blade.slice(blade.indexOf('    let totalPollAttempts ='), blade.indexOf('    const periodSummary ='))
    .replace(/\{\{\s*route\('([^']+)'\)[^}]*\}\}/g, '/count')
    .replace(/\{\{\s*\$perPage\s*\}\}/g, '100');
const flush = () => new Promise(resolve => setImmediate(resolve));

function createPage(respond) {
    const state = { requests: [], timers: new Map(), nextTimer: 0, pagination: [], delays: [] };
    const totalSummary = { textContent: '', title: '' };
    const context = vm.createContext({
        totalSummary, knownTotalRecords: null, URL, AbortController,
        renderNumberedPagination(page, total) { state.pagination.push({ page, total }); },
        window: {
            location: { origin: 'https://example.test', href: 'https://example.test/transactions', search: '' },
            setTimeout(callback, delay) {
                const id = ++state.nextTimer;
                state.timers.set(id, { callback, delay });
                state.delays.push(delay);
                return id;
            },
            clearTimeout(id) { state.timers.delete(id); },
        },
        fetch: async (url, options) => {
            state.requests.push({ url, options });
            return respond(state.requests.length, options);
        },
    });
    vm.runInContext(source, context);
    state.tick = async () => {
        const [id, timer] = state.timers.entries().next().value || [];
        assert(timer, 'Expected a scheduled count poll or request timeout.');
        state.timers.delete(id);
        timer.callback();
        await flush();
    };
    return { state, totalSummary, context };
}

const response = (data, status = 200) => ({ ok: status < 400, status, json: async () => data });

(async () => {
    for (const [description, respond] of [
        ['network error', async () => { throw new TypeError('Failed to fetch'); }],
        ['non-JSON outage', async () => ({ ok: false, status: 503, json: async () => { throw new SyntaxError('HTML response'); } })],
        ['missing total', async () => response({ state: 'complete' })],
        ['invalid total', async () => response({ state: 'complete', total: -1 })],
        ['unknown state', async () => response({ state: 'idle' })],
    ]) {
        const page = createPage(respond);
        for (let attempt = 0; attempt < 3; ++attempt) await page.state.tick();
        assert.equal(page.state.requests.length, 3, `${description}: retries must be bounded.`);
        assert.equal(page.state.timers.size, 0, `${description}: failures must not keep polling.`);
        assert.match(page.totalSummary.textContent, /unavailable/);
        assert.equal(page.state.pagination.length, 0, `${description}: never invent a zero total.`);
        assert(page.state.delays.includes(10000) && page.state.delays.includes(20000), 'Network retries must back off.');
    }

    for (const status of [401, 403, 419, 503]) {
        const page = createPage(async () => response({ state: 'failed', message: 'Totals are temporarily unavailable.' }, status));
        await page.state.tick();
        assert.equal(page.state.requests.length, 1);
        assert.equal(page.state.timers.size, 0, 'Terminal HTTP failures must stop immediately.');
        assert.match(page.totalSummary.title, status === 503 ? /temporarily unavailable/ : /sign in/);
    }
    const invalidSuccess = createPage(async () => response({ state: 'complete', total: 999 }, 500));
    await invalidSuccess.state.tick();
    assert.equal(invalidSuccess.state.pagination.length, 0, 'Non-success HTTP responses cannot publish a successful count.');
    assert.equal(invalidSuccess.state.timers.size, 0);

    const aborted = createPage(async (_attempt, options) => new Promise((_resolve, reject) => {
        options.signal.addEventListener('abort', () => {
            const error = new Error('Request aborted');
            error.name = 'AbortError';
            reject(error);
        });
    }));
    for (let attempt = 0; attempt < 3; ++attempt) {
        await aborted.state.tick();
        await aborted.state.tick();
    }
    assert.equal(aborted.state.requests.length, 3);
    assert.equal(aborted.state.timers.size, 0);
    assert.match(aborted.totalSummary.title, /timed out/);
    assert(aborted.state.requests.every(request => request.options.credentials === 'same-origin'));

    for (const data of [
        { state: 'processing' },
        { state: 'partial', total: 25, cache_state: 'warming' },
    ]) {
        const page = createPage(async () => response(data, data.state === 'processing' ? 202 : 200));
        for (let attempt = 0; attempt < 121; ++attempt) await page.state.tick();
        assert.equal(page.state.requests.length, 120, 'Even growing partial counts must have a finite polling budget.');
        assert.equal(page.state.timers.size, 0);
        assert.match(page.totalSummary.title, /did not finish in time/);
    }

    for (const data of [
        { state: 'complete', total: 0 },
        { state: 'complete', total: 125 },
        { state: 'partial', total: 80, cache_state: 'paused' },
        { state: 'partial', total: 80, cache_state: 'failed' },
    ]) {
        const page = createPage(async attempt => {
            if (attempt === 1) throw new TypeError('Temporary network error');
            return response(data);
        });
        await page.state.tick();
        await page.state.tick();
        assert.equal(page.state.timers.size, 0);
        assert.equal(page.context.knownTotalRecords, data.total);
        assert.equal(page.state.pagination[0].total, Math.max(1, Math.ceil(data.total / 100)));
    }
    console.log('PASS: terminal errors, HTTP/session failures, bounded backoff, request aborts, finite partial polling and exact zero totals');
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
