@extends('layouts.app')

@section('title', 'Reports')

@section('content')
<div style="display: flex; justify-content: space-between; align-items: center; margin: 20px 0;">
    <div>
        <h2 style="margin: 0;">Reports</h2>
        <div style="color: #718096; font-size: 13px; margin-top: 4px;">Export-ready reporting tools</div>
    </div>
</div>

@if($errors->any())
    <div style="margin-bottom: 16px; padding: 10px 14px; border-radius: 6px; background: #fff5f5; color: #742a2a; border: 1px solid #feb2b2; font-size: 13px;">
        {{ $errors->first() }}
    </div>
@endif

@if(session('report_success'))
    <div style="margin-bottom: 16px; padding: 10px 14px; border-radius: 6px; background: #c6f6d5; color: #22543d; border: 1px solid #9ae6b4; font-size: 13px;">
        {{ session('report_success') }}
    </div>
    <script>window.__reportJustStarted = true;</script>
@endif

@if(session('report_error'))
    <div style="margin-bottom: 16px; padding: 10px 14px; border-radius: 6px; background: #fff5f5; color: #742a2a; border: 1px solid #feb2b2; font-size: 13px;">
        {{ session('report_error') }}
    </div>
@endif

<div class="card" style="margin-bottom: 20px;">
    <div style="font-size: 16px; font-weight: 700; color: #2d3748; margin-bottom: 6px;">VieFund Daily Net + Running Balance</div>
    <div style="font-size: 13px; color: #4a5568; margin-bottom: 14px;">
        Builds a day-by-day net transaction report and cumulative running balance based on your selected date basis.
    </div>

    <div id="report-run-status-wrap" class="sync-chip sync-chip-progress" style="display:none; margin-bottom:10px; width:max-content; align-items:center; gap:8px;">
        <span id="report-run-status-text"></span>
        <button type="button" id="report-run-status-dismiss" aria-label="Dismiss report status" style="border:none; background:transparent; color:inherit; font-size:14px; font-weight:700; cursor:pointer; line-height:1; padding:0;">×</button>
    </div>

    <div id="report-run-progress-wrap" style="display:none; margin-bottom:14px; max-width: 760px;">
        <div style="height: 10px; border-radius: 999px; background: #e2e8f0; overflow: hidden;">
            <div id="report-run-progress-bar" style="height: 100%; width: 0%; background: linear-gradient(90deg, #2c5282 0%, #3182ce 100%);"></div>
        </div>
        <div id="report-run-progress-meta" style="margin-top: 6px; font-size: 12px; color: #4a5568;"></div>
    </div>

    <div id="report-download-wrap" style="display:none; margin-bottom: 12px;">
        <a id="report-download-link" href="#" class="btn" style="text-decoration: none; background: #2f855a;">Download Latest Report</a>
    </div>

    <form method="POST" action="{{ route('reports.viefund-daily-balance.run') }}" id="viefund-report-form" data-inception-dates='@json($inceptionDates)'>
        @csrf
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr auto; gap: 12px; align-items: end;">
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Start Date</label>
                <input type="date" id="report-date-from" name="date_from" value="{{ $dateFrom }}" required style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; font-size: 13px;">
            </div>

            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">End Date</label>
                <input type="date" name="date_to" value="{{ $dateTo }}" required style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; font-size: 13px;">
            </div>

            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Date Basis</label>
                <select id="report-date-basis" name="date_basis" required style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; font-size: 13px;">
                    @foreach($dateBasisOptions as $key => $label)
                        <option value="{{ $key }}" @selected($selectedDateBasis === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Output Order</label>
                <select name="output_order" required style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; font-size: 13px;">
                    @foreach($outputOrderOptions as $key => $label)
                        <option value="{{ $key }}" @selected($selectedOutputOrder === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div style="display: flex; gap: 8px; justify-content: flex-end;">
                <button type="submit" name="format" value="csv" class="btn" style="padding: 8px 16px; white-space: nowrap;">Run CSV Report</button>
                <button type="submit" name="format" value="excel" class="btn" style="padding: 8px 16px; background: #2f855a; white-space: nowrap;">Run Excel Report</button>
            </div>
        </div>

        <div style="margin-top: 8px; display: flex; flex-direction: column; align-items: flex-start; gap: 8px;">
            <div id="inception-date-note" style="font-size: 12px; color: #718096;"></div>
            <button type="button" id="set-inception-date-btn" class="btn" style="padding: 8px 10px; background: #2c5282; white-space: nowrap;">Set to Inception Date</button>
        </div>
    </form>
</div>

<script>
(function () {
    const form = document.getElementById('viefund-report-form');
    const dateBasis = document.getElementById('report-date-basis');
    const dateFrom = document.getElementById('report-date-from');
    const button = document.getElementById('set-inception-date-btn');
    const note = document.getElementById('inception-date-note');
    const runStatusWrap = document.getElementById('report-run-status-wrap');
    const runStatusText = document.getElementById('report-run-status-text');
    const runStatusDismiss = document.getElementById('report-run-status-dismiss');
    const progressWrap = document.getElementById('report-run-progress-wrap');
    const progressBar = document.getElementById('report-run-progress-bar');
    const progressMeta = document.getElementById('report-run-progress-meta');
    const downloadWrap = document.getElementById('report-download-wrap');
    const downloadLink = document.getElementById('report-download-link');
    if (!form || !dateBasis || !dateFrom || !button || !note) return;

    const inceptionDates = JSON.parse(form.dataset.inceptionDates || '{}');
    const DISMISS_KEY = 'viefundReportRunDismissedMessage';
    let currentRunMessage = '';
    let lastStatus = null;
    let cleanupRequested = false;
    let pollTimer = null;

    const refreshNote = () => {
        const selected = dateBasis.value;
        const inception = inceptionDates[selected] || null;
        note.textContent = inception
            ? `Inception date for selected basis: ${inception}`
            : 'No inception date found for selected basis.';
        button.disabled = !inception;
        button.style.opacity = inception ? '1' : '0.6';
        button.style.cursor = inception ? 'pointer' : 'not-allowed';
    };

    button.addEventListener('click', () => {
        const selected = dateBasis.value;
        const inception = inceptionDates[selected] || null;
        if (!inception) return;
        dateFrom.value = inception;
    });

    const setRunStatusVisible = (visible) => {
        if (!runStatusWrap) return;
        runStatusWrap.style.display = visible ? 'inline-flex' : 'none';
    };

    const setRunMessage = (message) => {
        if (!runStatusText) return;
        currentRunMessage = message;
        runStatusText.textContent = message;
        const dismissed = localStorage.getItem(DISMISS_KEY);
        setRunStatusVisible(dismissed !== message);
    };

    const cleanupLatestReportArtifacts = async () => {
        if (cleanupRequested) return;
        cleanupRequested = true;

        try {
            const tokenMeta = document.querySelector('meta[name="csrf-token"]');
            const csrfToken = tokenMeta ? tokenMeta.getAttribute('content') : '';
            await fetch('{{ route('reports.viefund-daily-balance.dismiss-latest') }}', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                },
            });
        } catch (_) {
            // Keep UI responsive even if cleanup call fails.
        }
    };

    if (runStatusDismiss) {
        runStatusDismiss.addEventListener('click', async () => {
            if (!currentRunMessage) return;

            const isCompleted = lastStatus && lastStatus.inProgress === false && lastStatus.success === true;
            if (isCompleted) {
                try {
                    await cleanupLatestReportArtifacts();
                } catch (_) {
                    // Keep UI responsive even if cleanup call fails.
                }

                if (progressWrap) progressWrap.style.display = 'none';
                if (downloadWrap) downloadWrap.style.display = 'none';
                if (progressBar) progressBar.style.width = '0%';
                if (progressMeta) progressMeta.textContent = '';
                lastStatus = null;
            }

            localStorage.setItem(DISMISS_KEY, currentRunMessage);
            setRunStatusVisible(false);
        });
    }

    const poll = () => {
        fetch('{{ route('reports.viefund-daily-balance.status') }}', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(data => {
                lastStatus = data;
                const pct = Number(data.progress_pct ?? 0);
                const processed = data.processed_days ?? 0;
                const total = data.total_days ?? '?';
                const dismissed = localStorage.getItem(DISMISS_KEY);

                if (data.inProgress) {
                    if (runStatusWrap) runStatusWrap.className = 'sync-chip sync-chip-progress';
                    localStorage.removeItem(DISMISS_KEY);
                    setRunMessage(data.message || 'Report in progress...');

                    if (progressWrap) progressWrap.style.display = 'block';
                    if (progressBar) progressBar.style.width = `${Math.max(0, Math.min(100, pct))}%`;
                    if (progressMeta) progressMeta.textContent = `${pct}% complete (${processed}/${total} days)`;

                    if (downloadWrap) downloadWrap.style.display = 'none';

                    if (pollTimer) {
                        clearInterval(pollTimer);
                    }
                    pollTimer = setInterval(poll, 1000);
                    return;
                }

                if (data.success === true) {
                    if (dismissed && dismissed === (data.message || 'Report complete.')) {
                        setRunStatusVisible(false);
                        if (progressWrap) progressWrap.style.display = 'none';
                        if (downloadWrap) downloadWrap.style.display = 'none';
                        if (progressBar) progressBar.style.width = '0%';
                        if (progressMeta) progressMeta.textContent = '';
                        cleanupLatestReportArtifacts();
                        return;
                    }

                    if (runStatusWrap) runStatusWrap.className = 'sync-chip sync-chip-success';
                    setRunMessage(data.message || 'Report complete.');

                    if (progressWrap) progressWrap.style.display = 'block';
                    if (progressBar) progressBar.style.width = '100%';
                    if (progressMeta) progressMeta.textContent = `100% complete (${data.total_days ?? processed}/${data.total_days ?? total} days)`;

                    if (data.download_url && downloadWrap && downloadLink) {
                        downloadLink.href = data.download_url;
                        downloadWrap.style.display = 'block';
                    }

                    if (pollTimer) {
                        clearInterval(pollTimer);
                    }
                    pollTimer = setInterval(poll, 5000);
                    return;
                }

                if (data.success === false) {
                    if (runStatusWrap) runStatusWrap.className = 'sync-chip sync-chip-error';
                    setRunMessage(data.message || 'Report failed.');

                    if (progressWrap) progressWrap.style.display = 'none';
                    if (downloadWrap) downloadWrap.style.display = 'none';

                    if (pollTimer) {
                        clearInterval(pollTimer);
                    }
                    pollTimer = setInterval(poll, 5000);
                    return;
                }

                setRunStatusVisible(false);
                if (progressWrap) progressWrap.style.display = 'none';
                if (downloadWrap) downloadWrap.style.display = 'none';

                if (pollTimer) {
                    clearInterval(pollTimer);
                }
                pollTimer = setInterval(poll, 5000);
            })
            .catch(() => {
                // Keep UI stable if polling fails.
            });
    };

    dateBasis.addEventListener('change', refreshNote);
    refreshNote();

    if (window.__reportJustStarted) {
        if (runStatusWrap) runStatusWrap.className = 'sync-chip sync-chip-progress';
        localStorage.removeItem(DISMISS_KEY);
        setRunMessage('Report is starting...');
        if (progressWrap) progressWrap.style.display = 'block';
        if (progressBar) progressBar.style.width = '5%';
        if (progressMeta) progressMeta.textContent = 'Starting report job...';
    }

    poll();
    pollTimer = setInterval(poll, 5000);
})();
</script>
@endsection
