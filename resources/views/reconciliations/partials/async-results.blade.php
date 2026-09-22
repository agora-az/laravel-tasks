@php
    $asyncResultsId = $resultsId ?? 'reconciliation-results';
    $asyncResultsFormId = $formId ?? null;
    $asyncLoadingMessage = $loadingMessage ?? 'Loading reconciliation data…';
@endphp

<div id="{{ $asyncResultsId }}" class="async-results-region is-loading" aria-live="polite" aria-busy="true">
    <div class="async-results-loading-overlay" role="status">
        <span class="async-results-spinner" aria-hidden="true"></span>
        <span class="async-results-loading-message">{{ $asyncLoadingMessage }}</span>
    </div>
    <div class="async-results-content"></div>
</div>

<script>
(() => {
    const region = document.getElementById(@json($asyncResultsId));
    const content = region?.querySelector('.async-results-content');
    const form = @json($asyncResultsFormId) ? document.getElementById(@json($asyncResultsFormId)) : null;
    if (!region || !content) return;

    let activeRequest = null;

    const visibleUrl = (value) => {
        const url = new URL(value, window.location.href);
        url.searchParams.delete('_results');
        return url;
    };

    const formUrl = (targetForm) => {
        const url = new URL(targetForm.action, window.location.href);
        const params = new URLSearchParams(new FormData(targetForm));
        url.search = params.toString();
        return visibleUrl(url);
    };

    const showError = (message) => {
        const alert = document.createElement('div');
        alert.className = 'async-results-error';
        alert.setAttribute('role', 'alert');
        alert.textContent = message;
        content.replaceChildren(alert);
    };

    const syncFormToUrl = (url) => {
        if (!form) return;
        const params = url.searchParams;
        for (const control of form.elements) {
            if (!control.name || control.type === 'submit' || control.type === 'button') continue;
            if (control.type === 'hidden' && Array.from(form.elements).some((candidate) =>
                candidate !== control
                && candidate.name === control.name
                && (candidate.type === 'checkbox' || candidate.type === 'radio')
            )) continue;
            const values = params.getAll(control.name);
            if (control.type === 'checkbox' || control.type === 'radio') {
                if (params.has(control.name)) {
                    control.checked = values.includes(control.value);
                }
            } else if (params.has(control.name)) {
                control.value = values[values.length - 1];
            }
        }
    };

    const loadResults = async (requestedUrl, updateHistory = false) => {
        const pageUrl = visibleUrl(requestedUrl);
        const fetchUrl = new URL(pageUrl);
        fetchUrl.searchParams.set('_results', '1');

        if (activeRequest) activeRequest.abort();
        const request = new AbortController();
        activeRequest = request;
        region.classList.add('is-loading');
        region.setAttribute('aria-busy', 'true');

        if (updateHistory) {
            window.history.pushState({}, '', pageUrl);
        }
        syncFormToUrl(pageUrl);

        try {
            const response = await fetch(fetchUrl, {
                headers: {
                    'Accept': 'application/json, text/html',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                cache: 'no-store',
                signal: request.signal,
            });
            if (response.redirected) {
                const redirectUrl = new URL(response.url);
                if (redirectUrl.pathname !== pageUrl.pathname) {
                    window.location.assign(redirectUrl);
                    return;
                }
                throw new Error('Unable to load the reconciliation data. Please review the filters and try again.');
            }
            const body = await response.text();
            if (!response.ok) {
                let message = 'Unable to load the reconciliation data. Please review the filters and try again.';
                try {
                    const error = JSON.parse(body);
                    message = Object.values(error.errors || {}).flat()[0] || error.message || message;
                } catch (_) {
                    // Keep the client-friendly fallback message for non-JSON errors.
                }
                throw new Error(message);
            }

            content.innerHTML = body;
            region.dispatchEvent(new CustomEvent('reconciliation:results-loaded', {
                bubbles: true,
                detail: { url: pageUrl.toString() },
            }));
        } catch (error) {
            if (error.name === 'AbortError') return;
            showError(error.message || 'Unable to load the reconciliation data.');
        } finally {
            if (activeRequest === request) {
                activeRequest = null;
                region.classList.remove('is-loading');
                region.setAttribute('aria-busy', 'false');
            }
        }
    };

    if (form) {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            loadResults(formUrl(form), true);
        });
    }

    region.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        if (!link || link.target === '_blank' || link.hasAttribute('download') || link.dataset.noAsync !== undefined) return;

        const url = visibleUrl(link.href);
        if (url.origin !== window.location.origin || url.pathname !== window.location.pathname) return;

        event.preventDefault();
        loadResults(url, true);
    });

    region.addEventListener('change', (event) => {
        const select = event.target.closest('[data-async-results-select]');
        if (!select) return;

        if (select.form) {
            loadResults(formUrl(select.form), true);
        } else if (select.value) {
            loadResults(select.value, true);
        }
    });

    window.addEventListener('popstate', () => window.location.reload());
    loadResults(window.location.href);
})();
</script>
