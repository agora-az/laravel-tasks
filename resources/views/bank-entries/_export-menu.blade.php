<details style="position:relative;">
    <summary class="sync-action-pill sync-action-pill-secondary" style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;list-style:none;white-space:nowrap;">
        <span>↓ Export</span><span aria-hidden="true">▾</span>
    </summary>
    <div style="position:absolute;right:0;top:calc(100% + 6px);z-index:30;background:#fff;border:1px solid #e2e8f0;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.12);min-width:160px;overflow:hidden;">
        <a href="{{ route('bank-entries.export', array_merge(request()->query(), ['format' => 'csv'])) }}" style="display:block;padding:10px 16px;font-size:13px;font-weight:600;color:#2b6cb0;text-decoration:none;border-bottom:1px solid #f0f4f8;">CSV</a>
        <a href="{{ route('bank-entries.export', array_merge(request()->query(), ['format' => 'excel'])) }}" style="display:block;padding:10px 16px;font-size:13px;font-weight:600;color:#276749;text-decoration:none;">Excel</a>
    </div>
</details>
