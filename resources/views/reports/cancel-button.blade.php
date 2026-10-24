<button id="{{ $cancelId }}" type="button" data-report-cancel hidden
        style="margin:8px 0; padding:7px 12px; border:1px solid #b91c1c; border-radius:4px; color:#b91c1c; background:#fff;">
    Cancel report
</button>
<span id="{{ $cancelId }}-message" role="status" style="font-size:13px;"></span>
@once
<script>
window.updateReportCancelButton = (button, status) => {
    if (!button) return;
    const active = Boolean(status.cancel_url);
    button.hidden = !active;
    button.dataset.cancelUrl = status.cancel_url || '';
    button.disabled = active && (status.cancellation_requested || button.dataset.cancelPending === 'true');
    button.textContent = button.disabled ? 'Stopping...' : 'Cancel report';
    if (!active) {
        delete button.dataset.cancelPending;
        document.getElementById(`${button.id}-message`).textContent = '';
    }
};
document.addEventListener('click', async (event) => {
    const button = event.target.closest?.('[data-report-cancel]');
    if (!button || button.disabled || !button.dataset.cancelUrl) return;
    const message = document.getElementById(`${button.id}-message`);
    button.disabled = true;
    button.dataset.cancelPending = 'true';
    button.textContent = 'Stopping...';
    message.textContent = '';
    try {
        const response = await fetch(button.dataset.cancelUrl, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'Unable to cancel this report.');
        message.textContent = data.message;
        if (data.cancelled) button.hidden = true;
    } catch (error) {
        delete button.dataset.cancelPending;
        button.disabled = false;
        button.textContent = 'Cancel report';
        message.textContent = `Cancellation failed: ${error.message}`;
    }
});
</script>
@endonce
