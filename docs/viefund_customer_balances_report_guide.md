# VieFund Customer Balances Report Guide

## Overview

The VieFund Customer Balances report produces one cash-balance row per eligible customer plan account as of a selected reporting date. It is intended for:

- reconciling Agora cash balances with the VieFund client report
- reviewing balances by customer, representative, plan, and cash account
- identifying positive cash that has a future settlement date
- reproducing a historical report using the time at which that report was generated

The report reads the VieFund SQL Server replica. It does not import or calculate balances from a client-supplied CSV.

## How The Balance Is Produced

### Source ledger

Balances are calculated directly from `UB_CashTrx`, joined to `UB_CashAccount` and the owning plan/customer records. The report includes all cash transaction types that meet the selected criteria; it does not reconstruct cash by adding fund and trust transactions separately.

For each plan, the report:

1. Selects the cash transaction date represented by the chosen **Date Basis**.
2. Includes transactions whose selected date is earlier than midnight after the **Reporting Date**. The reporting date is therefore inclusive.
3. Applies the selected cash transaction statuses and currency.
4. If a **Simulated Report Generation Time** is supplied, excludes transactions and cash accounts that did not yet exist at that time.
5. Groups the remaining cash transactions by plan and sums `UB_CashTrx.mAmount`.

The normal production setting is **Confirmed** status and **CAD** currency.

### Eligible account population

A row is produced when:

- the plan has a non-empty VieFund dealer account ID
- the plan does not belong to the internal Agora customer
- the plan has a cash account in the selected currency
- the cash account existed by the simulated generation time, when one is supplied
- the plan is not in the configured explicit exclusion list

A leading `#` is removed from cash account IDs in the output so they can be compared consistently with client files.

## Date Basis

The date basis determines which `UB_CashTrx` date controls whether a transaction falls on or before the reporting date.

| Selection | VieFund field | Meaning |
| --- | --- | --- |
| Created date | `dtCreated` | When the cash transaction record was created in VieFund |
| Trade date | `dtTrade` | The cash transaction's effective trade date |
| Processing date | `dtProcessing` | When VieFund processed the cash transaction |
| Settlement date | `dtSettlement` | When the cash transaction settles |

Use the same basis as the client report being reconciled. **Settlement date** is the default and is the appropriate basis for a settled-cash position. **Trade date** can include transactions before they settle, subject to the availability rule described below.

## Historical Report Generation Time

**Simulated Report Generation Time** is optional, but it is important when reproducing a historical client report from the current replica.

The reporting date answers, "Which transaction dates are in scope?" The simulated generation time answers, "What records and statuses were available when the historical report was run?"

When supplied, the report:

- excludes cash accounts opened after that timestamp
- excludes transactions created after that timestamp
- may reconstruct a currently Deleted cash transaction as Confirmed when a linked fund or trust record has a last-modified time after the simulated generation time

The linked timestamps come from `UB_FundTrx.dtLastModified` and `UB_TrustTrx.dtLastModified`, not from the cash transaction's status history. A cash transaction reaches `UB_FundTrx` through `UB_FundTrxCash`; it reaches `UB_TrustTrx` through `UB_CashTrx.iTrustTrxID`. These fields show that a related record changed after the simulated generation time, but they do not identify what changed or prove that the change was the deletion. The reconstruction is therefore an inference used to reproduce a validated historical report, not independently verified status history.

Enter the client report's actual generation time when known. If only an email or delivery time is known, it can be used as an upper bound, but the Summary sheet should be reviewed to confirm the timestamp used. VieFund timestamps and validated report times are interpreted in Eastern time.

Leaving this field blank uses the replica's current account population and current transaction statuses. That is suitable for a current report, but it may not reproduce a historical file exactly.

## Future-Settlement Cash

The main report flags positive Confirmed cash linked to an Unsettled trust transaction when its settlement date is after the reporting date. These fields are informational:

- **Future Settlement Transactions**
- **Future Settlement Cash (Info)**
- **Next Settlement Date**
- **Clarification Note**

Future-settlement cash is not automatically added to the settled balance.

The report classifies each flagged cash transaction from the current linked Unsettled trust state. The Summary labels refer to the full future-settlement cash amount linked to each trust transaction, not the numeric value stored in `mAmountUsed`:

- **FSC Linked to Used Trust (Client Reported)** on a Direct Cash Ledger settlement-date report when the linked trust has an amount used (`mAmountUsed > 0`) or no amount left (`mAmountLeft <= 0`). This cash is added to the client-compatible estimate because the validated client report included it.
- **FSC Linked to Unused Trust** when the linked trust has nothing used (`mAmountUsed = 0`) and still has an amount left (`mAmountLeft > 0`). This cash remains excluded from the client-compatible estimate.

For trade-date and other report modes, no separate future amount is added to the estimate, so the Summary classifies all informational future cash as excluded from the estimate. On a trade-date report, eligible future-settling cash is already included in the report total. This reproduces the observed VieFund client-report treatment without using an account-specific exception.

## Potential Client VieFund Balance

The Summary sheet includes **Potential Client VieFund Balance (Estimate)** as a quick spot-check value.

- For **Settlement date**, the estimate is the settled report total plus future-settling cash whose linked trust has an amount used or no amount left.
- For **Trade date**, eligible future-settling cash is already represented in the report total, so the estimate equals the report total.
- For other date bases, the estimate currently equals the report total.

The Summary sheet exposes the reconciliation directly:

```text
Future Settlement Cash (Info)
	= FSC Linked to Used Trust (Client Reported)
	+ FSC Linked to Unused Trust

Potential Client VieFund Balance (Estimate)
	= Total Settled Balance
	+ FSC Linked to Used Trust (Client Reported)  [Settlement date only]
```

This value is derived from the current ledger and linked trust state. It is an estimate of what the client VieFund report may display, not a stored VieFund report total and not a substitute for account-level reconciliation.

## Output Columns

| Column | Description |
| --- | --- |
| Client Name | Customer name from the owning VieFund plan |
| Rep Code | Representative code found through the included cash activity |
| Plan Account ID | VieFund dealer/plan account identifier |
| Account ID | Normalized cash account identifier |
| Account Status | Current selected cash-account status, such as `A` or `T` |
| Cash Transactions | Number of included cash ledger transactions |
| Settled Balance | Sum of included cash transaction amounts for the selected basis |
| Future Settlement Transactions | Count of flagged positive future-settlement cash transactions |
| Future Settlement Cash (Info) | Informational total of those flagged transactions |
| Next Settlement Date | Earliest settlement date among the flagged transactions |
| Clarification Note | Explains why future-settlement cash is shown separately |

## Excel And CSV Output

### Excel

Excel is recommended for reconciliation work. The workbook contains:

- **Customer Balances** sheet with numeric count and currency cells
- frozen header row
- filters across the full report table
- **Summary** sheet containing criteria, generation details, totals, and the potential client estimate

Because balances are numeric cells rather than formatted text, they can be sorted, filtered, summed, and used in formulas.

### CSV

CSV contains the same report values, with Summary fields placed beside the report rows. CSV does not support multiple sheets, frozen rows, filters, or Excel number formats.

## How To Run The Report

1. Open the application **Reports** page.
2. Locate **VieFund Customer Balances**.
3. Complete the report fields described below.
4. Select **Run Report**.
5. Choose **Excel** for reconciliation or **CSV** for a flat-file export.
6. Wait for the progress indicator to complete.
7. Select the download link when it appears.

Only one customer balances report can run at a time. A second request is rejected while the first report has an active lock.

### Reporting Date

The final calendar day included under the selected date basis. For example, `2026-06-24` includes eligible transactions dated through June 24 and excludes transactions dated June 25 or later.

The date cannot be later than today.

### Date Basis

Select the transaction date that matches the client report: Created, Trade, Processing, or Settlement. Do not compare reports that use different date bases.

### Currency Code

Select **CAD** or **USD**. The selection limits both cash accounts and transactions to that currency. Client cash-balance comparisons validated to date have used CAD.

### Simulated Report Generation Time

Use this when reproducing a historical report. Enter the date and time when the client report was generated, or the best defensible upper bound. Leave it blank for a current-state report.

The value cannot be later than the current time.

### Cash Transaction Status

This control filters `UB_CashTrx.iStatus`. Available values are:

| Value | Status |
| ---: | --- |
| 0 | Deleted |
| 1 | Rejected |
| 2 | Cancelled |
| 3 | Pending |
| 4 | Accepted |
| 5 | Contracted |
| 6 | Confirmed |

Use **Confirmed** only for the standard customer balance reconciliation. Do not add **Deleted** merely because a historical transaction is currently deleted; the simulated generation-time logic handles supported post-report deletions narrowly.

### Output Format

- Choose **Excel** for sorting, filtering, numeric analysis, and a separate Summary sheet.
- Choose **CSV** for integrations or tools that require plain comma-separated data.

## Reading The Summary Sheet

Confirm these values before comparing totals:

- **Report Date**
- **Date Basis**
- **Balance Source**: should be `Direct Cash Ledger`
- **Cash Statuses**: normally `Confirmed`
- **Simulated Generation Time**
- **Plan Accounts**
- **Total Settled Balance**
- **Future Settlement Cash (Info)**
- **FSC Linked to Used Trust (Client Reported)**
- **FSC Linked to Unused Trust**
- **Potential Client VieFund Balance (Estimate)**

The generated-at time records when this application completed the export. It is different from the simulated generation time.

## Caveats And Special Circumstances

### The replica is mutable

The VieFund replica is refreshed from production. Current balances, statuses, and linked trust usage can differ from their historical state. A historical reporting date alone is not enough to recreate what a user saw months or years earlier.

### Historical status reconstruction is intentionally narrow

The report does not generally include Deleted transactions. With a simulated generation time, it currently includes a deleted cash transaction when a linked `UB_FundTrx.dtLastModified` or `UB_TrustTrx.dtLastModified` is later than that time. This indicates that a related record changed later; it does not prove which field changed or when the cash status became Deleted. Without a later linked timestamp, the deleted transaction remains excluded unless Deleted was explicitly selected.

### Future-settlement information is not a settled balance

Do not add the entire **Future Settlement Cash (Info)** amount to the settled total. Some future-settlement transactions remain wholly unused and are correctly excluded from the client-compatible balance.

### Account Status is current descriptive data

The displayed cash-account status is selected from the eligible cash account record. It should not be interpreted as a fully versioned historical account status unless the source system provides that history.

### Precision

VieFund stores transaction amounts with more precision than the two decimals displayed as currency. Use displayed two-decimal values for client file comparison, while recognizing that sub-cent source precision can affect unrounded internal sums.

### Estimate versus evidence

The potential client balance is a convenience check based on behavior observed and validated against available client reports. The direct ledger total and account-level rows remain the auditable report output.

## Validation Status

The available client cash-balance reports for settlement-date and trade-date bases have been cross-checked against this reporting implementation and match to the penny when run with the corresponding report date, date basis, currency, status, and historical generation time.

Technical investigation details and reproducible command-line examples are retained in [VieFund Customer Balances Report Criteria](viefund_customer_balances_report_criteria.md).