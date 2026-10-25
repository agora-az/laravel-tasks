<div class="card report-card" style="margin-bottom:20px;">
    <div style="font-size:16px; font-weight:700; color:#2d3748; margin-bottom:6px;">CASHagch Purchases and Redemptions</div>
    <p style="font-size:13px; color:#4a5568; margin-bottom:14px;">
        Exports CASHagch transaction details with overall and monthly purchase and redemption totals, using VieFund cash amounts.
    </p>
    <form method="POST" action="{{ route('reports.cashagch.run') }}" id="cashagch-report-form"
          data-status-url="{{ route('reports.cashagch.status', ['runId' => '__RUN_ID__']) }}"
          data-inception-dates='@json($inceptionDates)'
          data-user-id="{{ auth()->id() }}">
        @csrf
        <div class="cashagch-report-layout">
        <div class="cashagch-report-fields">
        <div class="cashagch-report-filter-grid">
            <div>
                <label for="cashagch-start-date">Start Date</label>
                <input id="cashagch-start-date" name="date_from" type="date" max="{{ now()->toDateString() }}" aria-describedby="cashagch-date-scope">
            </div>
            <div>
                <span id="cashagch-date-scope" style="display:block; font-size:12px; color:#718096; margin-bottom:4px;">Inception Date: {{ $inceptionDates['settlement_date'] ?? 'N/A' }}</span>
                <button id="cashagch-use-inception" type="button" aria-pressed="true" style="width:100%; height:35px; padding:0 10px; border:1px solid #cbd5e0; border-radius:4px; background:#e2e8f0; color:#2d3748; white-space:nowrap; font-size:13px; text-align:left;">&laquo; Use Inception Date</button>
            </div>
            <div>
                <label for="cashagch-end-date">End Date</label>
                <input id="cashagch-end-date" name="date_to" type="date" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" required>
            </div>
            <div>
                <label for="cashagch-currency">Currency</label>
                <select id="cashagch-currency" name="currency_code">
                    <option value="00" selected>CAD</option>
                    <option value="01">USD</option>
                </select>
            </div>
            <div>
                <label for="cashagch-date-basis">Date Basis</label>
                <select id="cashagch-date-basis" name="date_basis">
                    @foreach($dateBasisOptions as $value => $label)
                        <option value="{{ $value }}" @selected($value === 'settlement_date')>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <fieldset class="report-options" style="border:1px solid; border-radius:6px; margin:10px 0 0; padding:10px 12px; font-size:12px;">
            <legend style="font-weight:700;">Cash Transaction Status</legend>
            <div style="display:flex; flex-wrap:wrap; gap:12px;">
                @foreach($fundStatusOptions as $value => $label)
                    <label><input type="checkbox" name="status_ids[]" value="{{ $value }}" @checked($value === 6)> {{ $label }}</label>
                @endforeach
            </div>
        </fieldset>
        </div>
        <div class="report-run-action">
            <button class="report-action-button" type="submit" style="padding:5px 14px; border:1px solid; border-radius:6px; font-size:13px; font-weight:600; white-space:nowrap;">Run Report</button>
        </div>
        </div>
    </form>
    <div id="cashagch-feedback" class="report-feedback" hidden aria-live="polite">
        <span data-cashagch-message></span>
        @include('reports.cancel-button', ['cancelId' => 'cashagch-report-cancel'])
        <div data-cashagch-eta hidden style="margin-top:6px; font-size:13px;"></div>
        <div data-cashagch-source hidden style="margin-top:6px; font-size:13px;"></div>
        <a data-cashagch-download hidden style="margin-left:12px; font-weight:700;">Download Excel report</a>
        <span data-cashagch-storage-warning hidden> Keep this page open: your browser cannot remember report progress after a refresh.</span>
    </div>
</div>
@include('reports.download-helper')
<script>
(() => {
    const form = document.getElementById('cashagch-report-form');
    const feedback = document.getElementById('cashagch-feedback');
    const message = feedback.querySelector('[data-cashagch-message]');
    const download = feedback.querySelector('[data-cashagch-download]');
    const button = form.querySelector('button[type="submit"]');
    const storageWarning = feedback.querySelector('[data-cashagch-storage-warning]');
    const eta = feedback.querySelector('[data-cashagch-eta]');
    const source = feedback.querySelector('[data-cashagch-source]');
    const cancelButton = document.getElementById('cashagch-report-cancel');
    const startDate = document.getElementById('cashagch-start-date');
    const endDate = document.getElementById('cashagch-end-date');
    const dateBasis = document.getElementById('cashagch-date-basis');
    const inceptionButton = document.getElementById('cashagch-use-inception');
    const dateScope = document.getElementById('cashagch-date-scope');
    const inceptionDates = JSON.parse(form.dataset.inceptionDates || '{}');
    const syncDateRange = () => {
        const inception = inceptionDates[dateBasis.value] || null;
        startDate.max = endDate.value || endDate.max;
        endDate.min = startDate.value;
        dateScope.textContent = inception ? `Inception Date: ${inception}` : 'Inception Date: N/A';
        inceptionButton.disabled = !inception;
        inceptionButton.setAttribute('aria-pressed', !startDate.value || startDate.value === inception ? 'true' : 'false');
    };
    startDate.addEventListener('input', syncDateRange);
    endDate.addEventListener('input', syncDateRange);
    dateBasis.addEventListener('change', syncDateRange);
    inceptionButton.addEventListener('click', () => {
        const inception = inceptionDates[dateBasis.value] || null;
        if (!inception) return;
        startDate.value = inception;
        syncDateRange();
        startDate.focus();
    });
    syncDateRange();
    const storageKey = `cashagch-report-run:${form.dataset.userId}`;
    let timer = null;
    let activeRunId = null;
    let autoDownloadPending = false;
    let downloadedUrl = null;
    const formatDuration = (seconds) => {
        if (seconds < 60) return 'less than a minute';
        const minutes = Math.ceil(seconds / 60);
        if (minutes < 60) return `${minutes} minute${minutes === 1 ? '' : 's'}`;
        const hours = Math.floor(minutes / 60);
        const remainder = minutes % 60;
        return `${hours} hour${hours === 1 ? '' : 's'}${remainder ? ` ${remainder} minute${remainder === 1 ? '' : 's'}` : ''}`;
    };
    const showEta = (status, active) => {
        eta.hidden = !active;
        eta.textContent = '';
        if (!active) return;
        if (status.state === 'queued') {
            eta.textContent = 'ETA: waiting for the report worker.';
        } else if (status.phase === 'counting') {
            eta.textContent = 'ETA: calculating the total number of matching transactions...';
        } else if (status.phase === 'finalizing') {
            eta.textContent = 'Finalizing workbook. No remaining-time estimate for this step.';
        } else if (Number.isFinite(status.eta_seconds) && status.eta_seconds > 0) {
            const completion = status.estimated_completion_at
                ? ` (around ${new Date(status.estimated_completion_at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })})`
                : '';
            eta.textContent = `Estimated time remaining: ${formatDuration(status.eta_seconds)}${completion}. Excludes final Excel packaging.`;
        } else if (!status.phase) {
            eta.textContent = 'ETA is available for newly started reports.';
        } else if (status.expected_transactions !== null && status.processed_transactions >= status.expected_transactions) {
            eta.textContent = 'Finishing the transaction read before Excel packaging...';
        } else {
            eta.textContent = 'ETA: calculating after the first few batches...';
        }
    };
    const warnStorage = (error) => {
        feedback.hidden = false;
        storageWarning.hidden = false;
        console.warn('Unable to save or restore CASHagch report progress in this browser.', error);
    };
    const showError = (error) => {
        autoDownloadPending = false;
        feedback.hidden = false;
        message.textContent = error.message;
        download.hidden = true;
        eta.hidden = true;
        eta.textContent = '';
        button.disabled = false;
    };
    const poll = async (runId) => {
        try {
            const response = await fetch(form.dataset.statusUrl.replace('__RUN_ID__', encodeURIComponent(runId)), {
                headers: { 'Accept': 'application/json' },
                cache: 'no-store',
            });
            const status = await response.json();
            if (runId !== activeRunId) return;
            if (!response.ok) throw new Error(status.message || 'Unable to read report progress.');
            feedback.hidden = false;
            message.textContent = status.message;
            source.hidden = !status.source_label;
            source.textContent = status.source_label
                ? `Source: ${status.source_label}.${status.mirror_synced_at ? ` Mirror last data sync: ${new Date(status.mirror_synced_at).toLocaleString()}.` : ''}${status.source_fallback_reason ? ` Fallback reason for this run: ${status.source_fallback_reason}` : ''}`
                : '';
            const active = ['queued', 'running', 'cancelling'].includes(status.state);
            window.updateReportCancelButton(cancelButton, status);
            showEta(status, active && !status.cancellation_requested && status.state !== 'cancelling');
            button.disabled = active;
            if (active && status.state !== 'cancelling' && !status.cancellation_requested && downloadedUrl === null) {
                autoDownloadPending = true;
            }
            const ready = status.state === 'completed' && status.success === true
                && !status.cancellation_requested && !status.cancelled && Boolean(status.download_url);
            download.hidden = !ready;
            if (ready) {
                download.href = status.download_url;
                if (autoDownloadPending && downloadedUrl !== status.download_url) {
                    autoDownloadPending = false;
                    downloadedUrl = status.download_url;
                    try {
                        window.downloadVieFundReport(status.download_url);
                    } catch (error) {
                        console.warn('Unable to start the automatic CASHagch report download.', error);
                        message.textContent += ' Automatic download could not start. Use the Download Excel report link.';
                    }
                }
            }
            if (!active) autoDownloadPending = false;
            if (active) timer = window.setTimeout(() => poll(runId), 5000);
        } catch (error) {
            if (runId === activeRunId) showError(error);
        }
    };
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!form.reportValidity()) return;
        if (timer) window.clearTimeout(timer);
        activeRunId = null;
        autoDownloadPending = false;
        downloadedUrl = null;
        window.updateReportCancelButton(cancelButton, {});
        button.disabled = true;
        feedback.hidden = false;
        download.hidden = true;
        eta.hidden = true;
        eta.textContent = '';
        source.hidden = true;
        source.textContent = '';
        message.textContent = 'Submitting CASHagch report...';
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.errors ? Object.values(result.errors).flat()[0] : result.message);
            activeRunId = result.run_id;
            autoDownloadPending = true;
            try {
                localStorage.setItem(storageKey, result.run_id);
            } catch (error) {
                warnStorage(error);
            }
            poll(result.run_id);
        } catch (error) {
            showError(error);
        }
    });
    try {
        activeRunId = localStorage.getItem(storageKey);
        if (activeRunId) poll(activeRunId);
    } catch (error) {
        warnStorage(error);
    }
})();
</script>
