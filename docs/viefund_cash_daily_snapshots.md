# VieFund Cash Daily Snapshots

## Purpose

The cash snapshot system stores a verified daily net series from VieFund cash-ledger inception. It supports faster Daily Net + Running Balance reports, detects historical source changes, and preserves an audit trail instead of silently overwriting prior observations.

This system is separate from `viefund_daily_totals`, which supports bank reconciliation using the older configurable fund/trust transaction model.

## Source Criteria

Snapshots use the same direct `UB_CashTrx` population as the VieFund Customer Balances report:

- Cash transactions are joined through `UB_CashAccount` and `UB_Plan`.
- Currency and cash transaction statuses are part of the criteria key.
- Internal Agora customer plans are excluded.
- The validated trade-date availability rule is applied.
- All cash transaction types are eligible.
- Zero-transaction dates are stored with a count and net value of zero.

The current algorithm identifier is `direct-cash-v1`. Changing source rules requires a new algorithm version so incompatible series cannot be mixed.

## Tables

### `viefund_cash_daily_snapshots`

Stores the current verified value for each criteria/date combination, including transaction count, daily net, inception-based closing balance, observation times, change flags, and review details.

### `viefund_cash_daily_snapshot_changes`

Append-only history created whenever a previously stored count or net value changes. Each row records the previous value, new value, deltas, detection time, algorithm version, and synchronization run.

### `viefund_cash_snapshot_runs`

Records every baseline, incremental refresh, full verification, or manual run. It includes the requested range, source observation time, outcome, and inserted/changed/unchanged counts.

## Synchronization

Build or refresh a series with:

```shell
php artisan viefund:sync-cash-daily-snapshots \
  --date-basis=settlement_date \
  --currency=00 \
  --statuses=6
```

The first run begins at direct-cash inception. Existing series refresh the latest 90 days by default.

Force an inception-to-current verification with:

```shell
php artisan viefund:sync-cash-daily-snapshots \
  --full \
  --date-basis=settlement_date \
  --currency=00 \
  --statuses=6
```

Scheduled CAD/Confirmed settlement and trade series receive nightly 90-day refreshes and weekly full verification.

## Running Balances

The stored closing balance for a date is:

```text
Closing Balance(date) = SUM(Daily Net from inception through date)
```

When a historical daily net changes, closing balances are recalculated from the complete current series. Reports beginning after inception obtain their opening balance from the prior day's stored closing balance rather than subtracting the selected period from an end-date total.

## Report Cache Rules

The Daily Net + Running Balance report uses snapshots only when the requested criteria and date coverage are complete.

It falls back to the live direct cash ledger when:

- A simulated report generation time is supplied.
- No matching snapshot series exists.
- The series has incomplete date coverage.
- The requested end date is newer than the snapshot horizon.
- A historical trade-date report is requested.

Historical trade reports remain live because trust usage can evolve after trade date. Current-horizon trade snapshots retain those changes through the audit history.

## Monitoring and Review

The Reports page shows recent synchronization runs and unreviewed changed days. Acknowledging a day clears its current review flag and records the review time and session user. It does not delete or modify the immutable change history.

## Legacy Comparison Report

The Reports page also provides **Legacy VieFund Daily Net + Running Balance** as an independent comparison export. It reproduces the previous implementation without changing or bypassing the direct-cash report.

The legacy export:

- Queries the fund and trust transaction reconstruction live in 31-day chunks.
- Provides separate Fund Status and Trust Status filters.
- Defaults to Confirmed fund transactions and Settled trust transactions.
- Excludes trust transactions when every Trust Status option is unchecked.
- Starts its running balance at zero on the selected start date.
- Does not use `UB_CashTrx`, cash daily snapshots, or the snapshot audit cache.
- Identifies itself as legacy in the report title, source metadata, and filename.
- Runs in a detached Artisan process while the Reports page polls progress, preventing long inception-range queries from holding the web request open.

Because the legacy running balance is selected-period activity from zero, it should not be interpreted as an inception cash balance. Compare daily transaction counts and daily net values first; compare running balances only after accounting for the different opening-balance method.
