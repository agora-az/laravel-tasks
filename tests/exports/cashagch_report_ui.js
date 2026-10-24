const assert = require('assert').strict;
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const view = fs.readFileSync(path.join(__dirname, '../../resources/views/reports/cashagch.blade.php'), 'utf8');
const script = new vm.Script(view.match(/<script>([\s\S]*?)<\/script>/)[1]);
const cancellationView = fs.readFileSync(path.join(__dirname, '../../resources/views/reports/cancel-button.blade.php'), 'utf8');
const cancellationScript = new vm.Script(cancellationView.match(/<script>([\s\S]*?)<\/script>/)[1]);
const flush = () => new Promise(resolve => setImmediate(resolve));

function createPage(responses, { previousRun = null, blockedStorage = false, initialStart = '' } = {}) {
    const state = { requests: [], timers: [], handlers: {}, documentHandlers: {}, warnings: [], storedRun: previousRun };
    const message = { textContent: '' };
    const download = { hidden: true };
    const warning = { hidden: true };
    const eta = { hidden: true, textContent: '' };
    const button = { disabled: false };
    const cancelButton = { id: 'cashagch-report-cancel', dataset: {}, hidden: true, disabled: false, textContent: '' };
    cancelButton.closest = () => cancelButton;
    const cancelMessage = { textContent: '' };
    const dateField = value => ({
        value, max: '2026-10-24', min: '', handlers: {},
        addEventListener(event, handler) { this.handlers[event] = handler; },
        focus() { this.focused = true; },
    });
    const startDate = dateField(initialStart);
    const endDate = dateField('2026-10-24');
    const dateScope = { textContent: '' };
    const inception = {
        handlers: {}, attributes: {},
        addEventListener(event, handler) { this.handlers[event] = handler; },
        setAttribute(key, value) { this.attributes[key] = value; },
    };
    const feedback = {
        hidden: true,
        querySelector: selector => ({
            '[data-cashagch-message]': message,
            '[data-cashagch-download]': download,
            '[data-cashagch-storage-warning]': warning,
            '[data-cashagch-eta]': eta,
        })[selector],
    };
    const form = {
        action: '/reports/cashagch/run',
        dataset: { statusUrl: '/reports/cashagch/__RUN_ID__/status', userId: '17' },
        querySelector: () => button,
        addEventListener: (event, handler) => { state.handlers[event] = handler; },
        reportValidity: () => true,
    };
    const context = vm.createContext({
        document: {
            addEventListener: (event, handler) => { state.documentHandlers[event] = handler; },
            querySelector: () => ({ getAttribute: () => 'test-csrf-token' }),
            getElementById: id => ({
            'cashagch-report-form': form,
            'cashagch-feedback': feedback,
            'cashagch-start-date': startDate,
            'cashagch-end-date': endDate,
            'cashagch-use-inception': inception,
            'cashagch-date-scope': dateScope,
            'cashagch-report-cancel': cancelButton,
            'cashagch-report-cancel-message': cancelMessage,
        })[id] },
        console: { warn: (...args) => state.warnings.push(args) },
        FormData: class {},
        localStorage: {
            getItem: () => {
                if (blockedStorage) throw new Error('Browser storage unavailable');
                return state.storedRun;
            },
            setItem: (key, value) => {
                if (blockedStorage) throw new Error('Browser storage unavailable');
                assert.equal(key, 'cashagch-report-run:17');
                state.storedRun = value;
            },
        },
        fetch: async (url, options) => {
            state.requests.push({ url, options });
            assert.ok(responses.length, `Unexpected request: ${url}`);
            const { ok = true, data } = await responses.shift();
            return { ok, json: async () => data };
        },
        window: {
            setTimeout: (callback, delay) => {
                state.timers.push({ callback, delay });
                return state.timers.length;
            },
            clearTimeout: () => {},
        },
    });
    cancellationScript.runInContext(context);
    script.runInContext(context);
    return { ...state, message, download, warning, eta, button, feedback, startDate, endDate, dateScope, inception, cancelButton, cancelMessage, state };
}

(async () => {
    const page = createPage([
        { data: { run_id: 'run-1' } },
        { data: { state: 'running', phase: 'exporting', message: '3 transactions written.', eta_seconds: 125, estimated_completion_at: '2026-10-24T21:00:00Z', download_url: null } },
        { data: { state: 'completed', message: 'Report ready.', eta_seconds: 125, download_url: '/reports/cashagch/run-1/download' } },
    ]);
    assert.equal(page.startDate.value, '');
    assert.equal(page.inception.attributes['aria-pressed'], 'true');
    assert.ok(page.dateScope.textContent.includes('Since inception'));
    page.startDate.value = '2026-09-01';
    page.startDate.handlers.input();
    assert.equal(page.endDate.min, '2026-09-01');
    assert.equal(page.inception.attributes['aria-pressed'], 'false');
    page.endDate.value = '2026-09-30';
    page.endDate.handlers.input();
    assert.equal(page.startDate.max, '2026-09-30');
    page.inception.handlers.click();
    assert.equal(page.startDate.value, '');
    assert.equal(page.endDate.min, '');
    assert.equal(page.inception.attributes['aria-pressed'], 'true');
    assert.equal(page.startDate.focused, true);
    await page.handlers.submit({ preventDefault() {} });
    await flush();
    assert.equal(page.button.disabled, true);
    assert.equal(page.message.textContent, '3 transactions written.');
    assert.equal(page.state.storedRun, 'run-1');
    assert.equal(page.eta.hidden, false);
    assert.ok(page.eta.textContent.includes('3 minutes'));
    assert.ok(page.eta.textContent.includes('around'));
    assert.ok(page.eta.textContent.includes('Excludes final Excel packaging'));
    assert.equal(page.timers[0].delay, 5000);
    await page.timers[0].callback();
    await flush();
    assert.equal(page.button.disabled, false);
    assert.equal(page.download.hidden, false);
    assert.equal(page.download.href, '/reports/cashagch/run-1/download');
    assert.equal(page.requests.length, 3);
    assert.equal(page.eta.hidden, true);
    assert.equal(page.eta.textContent, '');

    for (const [status, expected] of [
        [{ state: 'queued', phase: 'queued' }, 'waiting for the report worker'],
        [{ state: 'running', phase: 'counting' }, 'total number of matching transactions'],
        [{ state: 'running', phase: 'exporting', processed_transactions: 100, expected_transactions: 1000, eta_seconds: null }, 'first few batches'],
        [{ state: 'running', phase: 'exporting', processed_transactions: 1000, expected_transactions: 1000, eta_seconds: null }, 'Finishing the transaction read'],
        [{ state: 'running', phase: 'finalizing', eta_seconds: 0 }, 'Finalizing workbook'],
        [{ state: 'running' }, 'newly started reports'],
        [{ state: 'running', phase: 'exporting', eta_seconds: 59 }, 'less than a minute'],
        [{ state: 'running', phase: 'exporting', eta_seconds: 60 }, '1 minute'],
        [{ state: 'running', phase: 'exporting', eta_seconds: 3600 }, '1 hour'],
        [{ state: 'running', phase: 'exporting', eta_seconds: 3661 }, '1 hour 2 minutes'],
    ]) {
        const phase = createPage([{ data: { ...status, message: 'Report progress.', download_url: null } }], { previousRun: 'phase-run' });
        await flush();
        assert.equal(phase.eta.hidden, false);
        assert.ok(phase.eta.textContent.includes(expected), phase.eta.textContent);
    }
    for (const state of ['completed', 'failed']) {
        const terminal = createPage([{ data: { state, eta_seconds: 999, message: 'Report stopped.', download_url: null } }], { previousRun: 'terminal-run' });
        await flush();
        assert.equal(terminal.eta.hidden, true);
        assert.equal(terminal.eta.textContent, '');
        assert.equal(terminal.timers.length, 0);
    }

    const privacy = createPage([
        { data: { run_id: 'private-run' } },
        { data: { state: 'completed', message: 'Report ready.', download_url: '/private-download' } },
    ], { blockedStorage: true });
    await privacy.handlers.submit({ preventDefault() {} });
    await flush();
    assert.equal(privacy.warning.hidden, false);
    assert.equal(privacy.warnings.length, 2);
    assert.equal(privacy.download.href, '/private-download');
    assert.equal(privacy.message.textContent, 'Report ready.');

    const invalid = createPage([{ ok: false, data: { errors: { status_ids: ['Select at least one status.'] } } }]);
    await invalid.handlers.submit({ preventDefault() {} });
    assert.equal(invalid.message.textContent, 'Select at least one status.');
    assert.equal(invalid.button.disabled, false);
    assert.equal(invalid.requests.length, 1);
    assert.equal(invalid.eta.hidden, true);

    const expired = createPage([{ ok: false, data: { message: 'This report run has expired.' } }], { previousRun: 'old-run' });
    await flush();
    assert.equal(expired.message.textContent, 'This report run has expired.');
    assert.equal(expired.download.hidden, true);
    assert.equal(expired.button.disabled, false);
    assert.equal(expired.eta.hidden, true);

    let finishOldPoll;
    const oldPoll = new Promise(resolve => { finishOldPoll = resolve; });
    const race = createPage([
        oldPoll,
        { data: { run_id: 'new-run' } },
        { data: { state: 'completed', message: 'New report ready.', download_url: '/new-download' } },
    ], { previousRun: 'old-run' });
    await race.handlers.submit({ preventDefault() {} });
    await flush();
    finishOldPoll({ data: { state: 'running', message: 'Old report is running.', download_url: null } });
    await flush();
    assert.equal(race.message.textContent, 'New report ready.');
    assert.equal(race.download.href, '/new-download');
    assert.equal(race.button.disabled, false);
    assert.equal(race.timers.length, 0);
    assert.equal(race.eta.hidden, true);
    const cancelling = createPage([
        { data: { run_id: 'cancel-run' } },
        { data: { state: 'running', phase: 'exporting', message: 'Writing...', cancel_url: '/reports/cashagch/cancel?run_id=cancel-run', download_url: null } },
        { data: { cancelled: false, message: 'Cancellation requested.' } },
        { data: { state: 'cancelling', phase: 'cancelling', cancellation_requested: true, message: 'Stopping...', cancel_url: '/reports/cashagch/cancel?run_id=cancel-run', download_url: null } },
        { data: { state: 'cancelled', success: false, message: 'Report cancelled.', download_url: null } },
    ]);
    await cancelling.handlers.submit({ preventDefault() {} });
    await flush();
    assert.equal(cancelling.cancelButton.hidden, false);
    await cancelling.documentHandlers.click({ target: cancelling.cancelButton });
    assert.equal(cancelling.requests[2].options.method, 'POST');
    assert.equal(cancelling.requests[2].options.headers['X-CSRF-TOKEN'], 'test-csrf-token');
    assert.equal(cancelling.cancelButton.disabled, true);
    assert.equal(cancelling.cancelButton.textContent, 'Stopping...');
    await cancelling.timers[0].callback();
    await flush();
    assert.equal(cancelling.button.disabled, true);
    assert.equal(cancelling.eta.hidden, true);
    await cancelling.timers[1].callback();
    await flush();
    assert.equal(cancelling.button.disabled, false);
    assert.equal(cancelling.cancelButton.hidden, true);
    assert.equal(cancelling.download.hidden, true);
    for (const report of ['daily-balance', 'daily-balance-legacy', 'customer-balances']) {
        const denied = createPage([
            { data: { state: 'running', phase: 'exporting', message: 'Writing...', cancel_url: `/reports/${report}/cancel?run_id=run-1` } },
            { ok: false, data: { message: 'This report has already finished.' } },
        ], { previousRun: 'run-1' });
        await flush();
        await denied.documentHandlers.click({ target: denied.cancelButton });
        assert.ok(denied.requests[1].url.includes(`/reports/${report}/cancel`));
        assert.equal(denied.cancelButton.disabled, false);
        assert.ok(denied.cancelMessage.textContent.includes('Cancellation failed: This report has already finished.'));
    }
    console.log('PASS: report submission, progress polling, download, user-scoped resume, blocked browser storage, validation/expiry errors and stale-response protection');
    console.log('PASS: ETA phases, duration formatting, estimated local completion, warm-up, finalization and terminal-state clearing');
    console.log('PASS: inception default/reset, optional custom start date, inclusive date boundaries and accessible date-range state');
    console.log('PASS: shared cancellation POST/CSRF, stopping state, terminal cleanup, hidden ETA/download and explicit cancellation errors for every report type');
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
