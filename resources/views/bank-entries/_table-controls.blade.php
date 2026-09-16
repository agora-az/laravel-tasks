<div style="display:flex;align-items:flex-end;justify-content:flex-end;gap:10px;flex-wrap:nowrap;white-space:nowrap;">
    <label style="display:flex;flex-direction:column;gap:4px;color:#4a5568;font-size:11px;font-weight:700;">
        <span>View</span>
        <select aria-label="Bank statement data view" onchange="window.location.href=this.value" style="min-width:205px;padding:7px 30px 7px 10px;border:1px solid #cbd5e0;border-radius:5px;background:#fff;color:#2d3748;font-size:12px;font-weight:600;">
            <option value="{{ $bankViewUrl('summaries') }}" @selected($activeTab === 'summaries')>Statement Summaries</option>
            <option value="{{ $bankViewUrl('transactions') }}" @selected($activeTab === 'transactions')>Transactions</option>
        </select>
    </label>

    @include('bank-entries._export-menu')

    @if($showSyncButtons)
        <form method="POST" action="{{ route('bank-entries.sync') }}" style="margin:0;">
            @csrf
            @foreach(request()->query() as $key => $val)
                @if(is_array($val))
                    @foreach($val as $v)
                        <input type="hidden" name="{{ $key }}[]" value="{{ $v }}">
                    @endforeach
                @else
                    <input type="hidden" name="{{ $key }}" value="{{ $val }}">
                @endif
            @endforeach
            <button type="submit" data-bank-sync-button class="sync-action-pill sync-action-pill-primary">↻ Sync Bank Statements</button>
        </form>
    @endif
</div>
