const assert = require('assert').strict;
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const index = fs.readFileSync(path.join(__dirname, '../../resources/views/reports/index.blade.php'), 'utf8');
const source = [...index.matchAll(/<script>([\s\S]*?)<\/script>/g)].pop()[1]
    .replace(/\{\{[\s\S]*?\}\}/g, '/reports/test');
const script = new vm.Script(source);
const flush = () => new Promise(resolve => setImmediate(resolve));

function createPage(report) {
    const state = { requests: [], started: false, completed: false, elements: {}, downloads: [], timers: new Map() };
    let timerId = 0;
    const schedule = callback => {
        state.timers.set(++timerId, callback);
        return timerId;
    };
    const clearTimer = id => state.timers.delete(id);
    const element = () => ({
        handlers: {}, style: {}, disabled: false, textContent: '', value: '',
        addEventListener(event, handler) { this.handlers[event] = handler; },
    });
    const ids = {
        daily: [
            'viefund-report-form', 'report-date-basis', 'report-date-from', 'set-inception-date-btn',
            'inception-date-note', 'report-run-status-wrap', 'report-run-status-text', 'report-run-status-dismiss',
            'report-run-progress-wrap', 'report-run-progress-bar', 'report-run-progress-meta', 'report-feedback',
            'report-download-wrap', 'report-download-link', 'report-run-wrap', 'report-run-btn', 'report-format-input',
        ],
        legacy: [
            'legacy-viefund-report-form', 'legacy-report-date-basis', 'legacy-report-date-from',
            'legacy-set-inception-date-btn', 'legacy-inception-date-note', 'legacy-report-format-input',
            'legacy-report-status-wrap', 'legacy-report-status-text', 'legacy-report-status-dismiss',
            'legacy-report-progress-wrap', 'legacy-report-progress-bar', 'legacy-report-progress-meta',
            'legacy-report-feedback', 'legacy-report-download-wrap', 'legacy-report-download-link',
        ],
        customer: [
            'viefund-customer-balance-form', 'customer-balance-run-status-wrap', 'customer-balance-run-status-text',
            'customer-balance-run-status-dismiss', 'customer-balance-run-progress-wrap', 'customer-balance-run-progress-bar',
            'customer-balance-run-progress-meta', 'customer-balance-feedback', 'customer-balance-download-wrap',
            'customer-balance-download-link', 'customer-balance-run-wrap', 'customer-balance-run-btn', 'customer-balance-format-input',
        ],
    };
    for (const id of ids[report]) state.elements[id] = element();
    const formId = { daily: 'viefund-report-form', legacy: 'legacy-viefund-report-form', customer: 'viefund-customer-balance-form' }[report];
    const formatId = { daily: 'report-format-input', legacy: 'legacy-report-format-input', customer: 'customer-balance-format-input' }[report];
    const form = state.elements[formId];
    form.action = '/reports/run';
    form.dataset = { inceptionDates: JSON.stringify({ settlement_date: '2019-06-10' }) };
    form.reportValidity = () => true;
    state.elements[formatId].value = 'csv';
    for (const [id, control] of Object.entries(state.elements)) {
        if (id.endsWith('date-basis')) control.value = 'settlement_date';
        if (id.endsWith('date-from')) control.value = '2026-09-01';
    }
    const runButton = report === 'legacy' ? element()
        : state.elements[report === 'daily' ? 'report-run-btn' : 'customer-balance-run-btn'];
    const storage = new Map();
    const context = vm.createContext({
        document: {
            getElementById: id => state.elements[id] || null,
            querySelectorAll: selector => selector === '.legacy-report-run-button' && report === 'legacy' ? [runButton] : [],
            addEventListener() {},
        },
        localStorage: {
            getItem: key => storage.get(key) || null,
            setItem: (key, value) => storage.set(key, value),
            removeItem: key => storage.delete(key),
        },
        FormData: class {
            constructor() { this.format = state.elements[formatId].value; }
        },
        URL,
        setTimeout: schedule,
        clearTimeout: clearTimer,
        fetch: async (url, options = {}) => {
            state.requests.push({ url, options });
            if (options.method === 'POST') state.started = true;
            return {
                ok: true,
                json: async () => options.method === 'POST'
                    ? { success: true, run_id: 'test-run', message: 'Started.' }
                    : { inProgress: state.started && !state.completed, success: state.completed ? true : null,
                        run_id: state.started ? 'test-run' : null,
                        processed_days: 1, total_days: 1, processed_accounts: 40114, total_accounts: 40114,
                        message: state.completed ? 'Report completed.' : 'Waiting.',
                        download_url: state.completed ? '/reports/download-latest?run_id=test-run' : null },
            };
        },
        window: {
            updateReportCancelButton() {},
            location: { origin: 'https://reports.test' },
            downloadVieFundReport: url => state.downloads.push(url),
            setTimeout: schedule, clearTimeout: clearTimer, setInterval: schedule, clearInterval: clearTimer,
        },
        console,
    });
    script.runInContext(context);
    return { state, form, runButton };
}

(async () => {
    for (const report of ['daily', 'legacy', 'customer']) {
        for (const action of ['click', 'submit']) {
            const page = createPage(report);
            await flush();
            const handler = action === 'click' ? page.runButton.handlers.click : page.form.handlers.submit;
            assert.equal(typeof handler, 'function', `${report} must expose a working ${action} action without a format menu.`);
            await handler({ preventDefault() {} });
            await flush();
            const posts = page.state.requests.filter(request => request.options.method === 'POST');
            assert.equal(posts.length, 1, `${report} ${action} must submit exactly one report.`);
            assert.equal(posts[0].options.body.format, 'excel', `${report} must submit Excel even if an old format value was present.`);
            assert.equal(posts[0].options.headers.Accept, 'application/json');
            assert.equal(page.runButton.disabled, true, `${report} must poll the running report.`);
            assert.equal(page.state.downloads.length, 0, `${report} must not download incomplete output.`);
            page.state.completed = true;
            const polls = [...page.state.timers.values()];
            assert.equal(polls.length, 1, `${report} must schedule a status poll.`);
            polls[0]();
            await flush();
            const url = '/reports/download-latest?run_id=test-run';
            assert.deepEqual(page.state.downloads, [url], `${report} must automatically download its completed workbook.`);
            const prefix = { daily: 'report', legacy: 'legacy-report', customer: 'customer-balance' }[report];
            assert.equal(page.state.elements[`${prefix}-download-link`].href, url);
            assert.equal(page.state.elements[`${prefix}-download-wrap`].style.display, 'block',
                `${report} must offer a manual download link.`);
            polls[0]();
            await flush();
            assert.deepEqual(page.state.downloads, [url], `${report} must not automatically download the same workbook twice.`);
        }
    }
    console.log('PASS: daily, legacy and customer reports submit Excel, automatically download completed workbooks once and retain manual download links');
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
