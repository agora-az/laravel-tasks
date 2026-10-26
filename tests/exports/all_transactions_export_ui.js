const assert = require('assert').strict;
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const { randomUUID } = require('crypto');

const blade = fs.readFileSync(path.join(__dirname, '../../resources/views/viefund-transactions/index.blade.php'), 'utf8');
const source = blade.replace(/\{\{\s*route\('([^']+)'[^}]*\}\}/g, '/$1')
    .replace(/\{\{\s*\(int\) auth\(\)->id\(\)\s*\}\}/g, '17');
const section = (from, to) => source.slice(source.indexOf(from), source.indexOf(to));
const script = new vm.Script([
    section('    const ACTIVE_RUN_KEY =', '    let reconciliationMatchesLoaded ='),
    section('    const parseJsonResponse =', '    const filterForm ='),
    source.slice(source.indexOf('    const showStatus ='), source.lastIndexOf('})();')),
].join('\n'));
const flush = async () => {
    for (let i = 0; i < 6; i += 1) await new Promise(resolve => setImmediate(resolve));
};
const response = (data, status = 200, extra = {}) => ({
    ok: status >= 200 && status < 300, status, redirected: false,
    text: async () => JSON.stringify(data), ...extra,
});

function page(responses, options = {}) {
    const state = { requests: [], diagnostics: [], timers: new Map(), downloads: [], warnings: [], handler: null };
    const storageKey = 'viefundAllTransactionsActiveRunId:17';
    const storage = new Map(Object.entries(options.storage || {}));
    if (options.runId) storage.set(storageKey, options.runId);
    const button = { dataset: { cacheReady: options.cacheReady === false ? '0' : '1' }, style: {}, disabled: false };
    const status = { style: {}, classList: { toggle() {} } };
    const form = { action: '/viefund-transactions.export.start' };
    let timerId = 0;
    const context = vm.createContext({
        URL, AbortController, navigator: { onLine: options.online !== false },
        console: { warn: (...args) => state.warnings.push(args) },
        formatCacheEta: value => value ? `${value}s` : '',
        document: {
            getElementById: id => ({
                'all-transactions-excel-export': button,
                'all-transactions-export-status': status,
                'all-transactions-excel-form': form,
            }[id] || null),
            querySelector: () => ({ content: 'test-csrf' }),
            createElement: () => ({ remove() {} }),
            body: { appendChild: frame => state.downloads.push(frame.src) },
            addEventListener: (name, handler) => { if (name === 'click') state.handler = handler; },
        },
        localStorage: {
            getItem(key) {
                if (options.storageBlocked) throw new Error('Storage blocked');
                return storage.get(key) || null;
            },
            setItem(key, value) {
                if (options.storageBlocked) throw new Error('Storage blocked');
                storage.set(key, value);
            },
            removeItem(key) {
                if (options.storageBlocked) throw new Error('Storage blocked');
                storage.delete(key);
            },
        },
        FormData: class extends Map {
            constructor() { super(); }
        },
        window: {
            crypto: { randomUUID },
            location: { origin: 'https://current.example.test', reload() {} },
            setTimeout(fn, delay) { state.timers.set(++timerId, { fn, delay }); return timerId; },
            clearTimeout(id) { state.timers.delete(id); },
        },
        fetch: async (url, request = {}) => {
            if (url.includes('.diagnostics')) {
                state.diagnostics.push(JSON.parse(request.body));
                return response({ received: true });
            }
            state.requests.push({ url, request });
            const next = responses.shift();
            assert(next, `Unexpected export request: ${url}`);
            if (next instanceof Error) throw next;
            if (typeof next === 'function') return next(request);
            return next;
        },
    });
    script.runInContext(context);
    return {
        state, button, status, storage,
        click: async () => {
            await state.handler({ target: { closest: () => button } });
            await flush();
        },
        retry: async () => {
            const timer = [...state.timers.entries()].find(([, value]) => value.delay <= 10000);
            assert(timer, 'A status retry must be scheduled.');
            state.timers.delete(timer[0]);
            await timer[1].fn();
            await flush();
        },
    };
}

(async () => {
    const runId = randomUUID();
    const unavailable = page([response({
        run_id: runId, state: 'unavailable', inProgress: false, success: null,
        message: 'The previous export is no longer available. Generate a new Excel export.',
    })], { runId });
    await flush();
    assert.match(unavailable.status.textContent, /no longer available/);
    assert.notEqual(unavailable.status.style.color, '#991b1b', 'An expired previous run is not a red failure.');
    assert(!unavailable.storage.has('viefundAllTransactionsActiveRunId:17'));
    assert.equal(unavailable.button.disabled, false);
    console.log('PASS: stale runs clear tracking and provide neutral, actionable guidance');

    const otherAccount = page([], { storage: {
        'viefundAllTransactionsActiveRunId': runId,
        'viefundAllTransactionsActiveRunId:18': runId,
    } });
    await flush();
    assert.equal(otherAccount.state.requests.length, 0);
    console.log('PASS: legacy and other-account tracking IDs are not resumed');

    const failedStart = page([new TypeError('Failed to fetch')], { online: false });
    await failedStart.click();
    assert.match(failedStart.status.textContent, /could not be reached/);
    assert.match(failedStart.status.textContent, /Startup could not be confirmed/);
    assert.match(failedStart.status.textContent, /Reference: [0-9a-f-]{36}/);
    assert.equal(failedStart.state.requests.length, 1, 'Failed starts must not automatically repeat the POST.');
    assert.equal(failedStart.state.diagnostics[0].category, 'network');
    assert.equal(failedStart.state.diagnostics[0].online, false);
    assert.equal(failedStart.state.diagnostics[0].request_id,
        failedStart.state.requests[0].request.body.get('request_id'));
    assert.equal(failedStart.state.requests[0].url, '/viefund-transactions.export.start');
    assert.equal(failedStart.state.requests[0].request.credentials, 'same-origin');
    console.log('PASS: start network failures have a correlation ID, diagnostics and no automatic duplicate POST');

    const running = () => response({
        run_id: runId, inProgress: true, success: null, processed_transactions: 20, total_transactions: 100,
        progress_pct: 20, started_at: new Date().toISOString(),
    });
    const reconnect = page([
        new TypeError('Failed to fetch'), new TypeError('Failed to fetch'), new TypeError('Failed to fetch'),
        running(),
    ], { runId, cacheReady: false });
    await flush();
    await reconnect.retry();
    await reconnect.retry();
    assert.equal(reconnect.button.disabled, false);
    assert.match(reconnect.status.textContent, /will not start a duplicate/);
    assert.equal(reconnect.storage.get('viefundAllTransactionsActiveRunId:17'), runId);
    await reconnect.click();
    assert.equal(reconnect.state.requests.length, 4);
    assert(reconnect.state.requests.every(({ request }) => request.method !== 'POST'));
    assert.match(reconnect.status.textContent, /Exporting: 20\/100/);
    assert.equal(reconnect.state.diagnostics[3].category, 'recovered');
    console.log('PASS: bounded poll retries preserve tracking and reconnect with GET rather than creating another export');

    for (const status of [401, 403, 419]) {
        const expired = page([response({}, status)], { runId });
        await flush();
        assert.match(expired.status.textContent, /session may have expired/);
        assert.equal(expired.state.timers.size, 0);
        assert.equal(expired.state.diagnostics[0].http_status, status);
    }
    const redirected = page([response({}, 200, { redirected: true })], { runId });
    await flush();
    assert.match(redirected.status.textContent, /sign in again/);
    assert.equal(redirected.state.timers.size, 0);
    console.log('PASS: expired sessions and login redirects stop polling with sign-in guidance');

    for (const badResponse of [
        response({}, 502, { text: async () => '<html>Bad gateway</html>' }),
        response([], 200),
        response({ run_id: randomUUID(), inProgress: true }),
    ]) {
        const invalid = page([badResponse], { runId });
        await flush();
        assert.equal(invalid.state.diagnostics[0].category, 'invalid_response');
        assert.equal(invalid.storage.get('viefundAllTransactionsActiveRunId:17'), runId);
        assert.equal(invalid.state.timers.size, 1);
    }
    console.log('PASS: proxy HTML, invalid JSON shapes and mismatched run IDs retain recoverable tracking');

    const timedOut = page([request => new Promise((resolve, reject) => {
        request.signal.addEventListener('abort', () => {
            const error = new Error('Aborted');
            error.name = 'AbortError';
            reject(error);
        });
    })], { runId });
    const timeout = [...timedOut.state.timers.values()].find(timer => timer.delay === 15000);
    assert(timeout);
    timeout.fn();
    await flush();
    assert.equal(timedOut.state.diagnostics[0].category, 'timeout');
    assert.match(timedOut.status.textContent, /too long to respond/);
    console.log('PASS: hung status requests time out and enter the reconnect path');

    const existing = page([
        response({ state: 'running', run_id: runId, request_id: randomUUID(), message: 'Previous export running.' }, 409),
        running(),
    ]);
    await existing.click();
    assert.equal(existing.state.requests.length, 2);
    assert.equal(existing.state.requests[0].request.method, 'POST');
    assert(existing.state.requests[1].url.startsWith('https://current.example.test/'));
    assert(existing.state.requests[1].url.includes(runId));
    assert.match(existing.status.textContent, /Exporting: 20\/100/);
    console.log('PASS: a lost startup response can reconnect to an existing run via the conflict response');

    const completed = page([response({
        run_id: runId, inProgress: false, success: true, download_url: '/export/download?run_id=' + runId,
    })], { runId });
    await flush();
    assert.equal(completed.state.downloads.length, 1);
    assert(!completed.storage.has('viefundAllTransactionsActiveRunId:17'));
    assert.equal(completed.button.disabled, false);
    const otherPeriod = page([response({
        run_id: runId, inProgress: false, success: true, download_url: '/export/download?run_id=' + runId,
    })], { runId, cacheReady: false });
    await flush();
    assert.equal(otherPeriod.button.disabled, true,
        'Completing a tracked export must not enable a new export for an uncached period.');
    const blockedStorage = page([], { storageBlocked: true });
    await flush();
    assert.equal(blockedStorage.state.diagnostics[0].category, 'storage');
    assert.equal(typeof blockedStorage.state.handler, 'function');
    console.log('PASS: successful downloads still clear tracking; blocked browser storage does not break export controls');
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
