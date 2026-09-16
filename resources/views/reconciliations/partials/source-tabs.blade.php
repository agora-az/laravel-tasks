<div style="display:flex;gap:8px;margin:20px 0 24px;padding-bottom:12px;border-bottom:1px solid #e2e8f0;">
    <a href="{{ route('reconciliations.transactions') }}"
       style="padding:9px 14px;border-radius:6px;font-size:13px;font-weight:700;text-decoration:none;{{ ($activeReconciliation ?? '') === 'transactions' ? 'background:#2b6cb0;color:#fff;' : 'background:#e2e8f0;color:#2d3748;' }}">
        Transactions
    </a>
    <a href="{{ route('reconciliations.daily-totals') }}"
       style="padding:9px 14px;border-radius:6px;font-size:13px;font-weight:700;text-decoration:none;{{ ($activeReconciliation ?? '') === 'eft' ? 'background:#2b6cb0;color:#fff;' : 'background:#e2e8f0;color:#2d3748;' }}">
        Bank / EFT
    </a>
    <a href="{{ route('reconciliations.bank-fsp.source', ['source' => 'agra']) }}"
       style="padding:9px 14px;border-radius:6px;font-size:13px;font-weight:700;text-decoration:none;{{ ($activeReconciliation ?? '') === 'fsp-agra' ? 'background:#2b6cb0;color:#fff;' : 'background:#e2e8f0;color:#2d3748;' }}">
        Bank / FSP AGRA
    </a>
    <a href="{{ route('reconciliations.bank-fsp.source', ['source' => '7960']) }}"
       style="padding:9px 14px;border-radius:6px;font-size:13px;font-weight:700;text-decoration:none;{{ ($activeReconciliation ?? '') === 'fsp-7960' ? 'background:#2b6cb0;color:#fff;' : 'background:#e2e8f0;color:#2d3748;' }}">
        Bank / FSP 7960
    </a>
</div>
