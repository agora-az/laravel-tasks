# Scheduled Processes Guide

## Purpose

The application performs a series of automatic updates so that VieFund, bank, EFT, and settlement data are ready for reconciliation and reporting. This guide explains **when** each process normally runs, **what** it updates, and **why** it matters.

All times below are **Eastern Time (EST/EDT)**. The schedule automatically follows daylight saving time. A “daily” process runs every calendar day, including weekends and holidays.

## Schedule at a glance

| When | Process | What happens | Why it matters |
| --- | --- | --- | --- |
| Every hour | VieFund dashboard summary | Refreshes the summary figures used on the VieFund dashboard. | Keeps dashboard totals and activity indicators current without making users wait for a live VieFund query. |
| Daily at 8:45 p.m. | VieFund report start dates | Refreshes the earliest available date for each supported report date basis. | Keeps the **Use Inception Date** option accurate and quick to load. |
| Daily at 9:00 p.m. | VieFund daily reconciliation totals | Rebuilds the most recent 90 days of locally stored daily VieFund totals using the configured reconciliation criteria. | Keeps daily reconciliation views responsive and captures recent transactions or status changes. |
| Daily at 9:15 p.m. | Settlement-date cash balances | Refreshes the most recent 90 days of confirmed CAD cash activity using settlement date. | Supports the settlement-date Daily Net and Running Balance report, including balances calculated from inception. |
| Daily at 9:30 p.m. | Trade-date cash balances | Refreshes the most recent 90 days of confirmed CAD cash activity using trade date. | Supports the same balance analysis when trade date is selected. |
| Daily at 9:35 p.m. | Bank EFT files | Checks the secure bank folder for new EFT files, validates them, and imports files that have not already been processed. | Provides the bank-issued EFT detail needed to compare EFT activity with VieFund. |
| Daily at 9:45 p.m. | FSP settlement instructions | Checks the secure file location for new settlement-instruction files and imports files that have not already been processed. | Keeps AGRA, 7960, and other supported FSP settlement information available for comparison. |
| Daily at 9:55 p.m. | Bank statement entries | Checks for new bank statement files, imports the entries, and applies the current transaction analysis rules. | Keeps the bank side of reconciliation current, including balances, summaries, and transaction classifications. |
| Mondays at 10:00 p.m. | Full settlement-date balance check | Rechecks confirmed CAD settlement-date cash history from inception through the current date. | Detects older VieFund changes that fall outside the normal 90-day nightly refresh and recalculates later running balances where necessary. |
| Mondays at 10:15 p.m. | Full trade-date balance check | Rechecks confirmed CAD trade-date cash history from inception through the current date. | Provides the same full-history safeguard for trade-date reporting. |
| Daily at 10:30 p.m. | VieFund customer and account lookup data | Refreshes the local customer, plan-account, and cash-transaction lookup data from VieFund. | Keeps customer searches and account-level transaction drill-downs responsive and current. |

## How the overnight sequence works

The nightly processes are staggered to spread the work across the evening and reduce load on VieFund, the bank file service, and the application database. Most updates focus on recent activity, while the Monday full-history checks provide an additional control for older changes.

By the following morning, the application should reflect files and VieFund information that were available when the applicable process ran. The exact completion time can vary with file size, transaction volume, and source-system availability.

## VieFund database snapshot timing

The VieFund database available to this application is a read-only replica. Its replication is managed outside this application and is scheduled to produce a nightly snapshot at **7:00 p.m. Eastern**. Because the application cannot read the replication job itself, the dashboard reports that scheduled snapshot after a 15-minute completion allowance, beginning at 7:15 p.m.

The separate **Dashboard summary refreshed** time shows when the application last recalculated its dashboard cards from the available replica. It does not mean that the VieFund database itself was replicated at that time. The dashboard displays both times so that users can distinguish the nightly source-data snapshot from the hourly dashboard-summary refresh.

## Balance calculations and historical changes

The running daily balance is always based on activity from the applicable inception date. Selecting a later report start date only limits the rows displayed; it does not reset the running balance to zero.

The nightly 90-day refresh captures normal recent changes efficiently. Once each week, the full-history checks look back to inception. If an older daily amount has changed, the application records the change and recalculates the running balances that follow it. This provides both timely reporting and a regular historical control.

Before using stored cash snapshots, the report also confirms that the requested dates are covered by a recent completed refresh. If the applicable nightly refresh or weekly full-history verification is stale, the report automatically reads the live VieFund cash ledger instead. This prevents an old snapshot from being presented as current while preserving the faster snapshot path under normal operation.

## Import and refresh safeguards

The scheduled processes include controls intended to protect the completeness and consistency of the accounting data:

- A second copy of the same scheduled process will not start while the first copy is still running.
- Previously completed bank, EFT, and settlement files are skipped so that they are not imported twice.
- File transfers are checked before import. Incomplete downloads are retained for investigation and downloaded again.
- Cash snapshot changes retain an audit history showing the prior and refreshed daily values.
- If there is no new file or activity, the process completes without creating duplicate data.
- A temporary source-system failure does not erase the information already stored in the application. The next scheduled run, or an authorized manual sync, can try again.

These processes read and store source information for reconciliation; they do not post accounting entries back to VieFund or the bank.

## Items that remain on demand

Some activities are intentionally initiated by a user rather than scheduled:

- Running or exporting the **VieFund Daily Net + Running Balance** report.
- Running or exporting the **VieFund Customer Balances** report.
- Reviewing reconciliation differences and confirming or recording matches.
- Starting a manual sync when an authorized user needs newly delivered information before the next scheduled run.

The report screens may use locally refreshed data for speed, but the chosen reporting criteria still determine the displayed or exported result.

## What to check if information appears out of date

1. Confirm that the expected source file or VieFund activity existed before the scheduled time.
2. Review the latest sync date and status shown on the applicable application screen.
3. Remember that a file delivered after its nightly check will normally be collected on the next run unless an authorized user starts a manual sync.
4. If a process shows a failure or the displayed date remains stale, contact application support with the process name, expected date, and source filename where applicable.

Repeatedly importing the same file is not normally required; the application tracks completed files and prevents duplicate processing.
