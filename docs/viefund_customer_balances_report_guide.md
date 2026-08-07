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

The selected cash transaction statuses are evaluated from the replica's current `UB_CashTrx.iStatus` values. The report does not infer historical status from linked record modification timestamps.

The normal production setting is **Confirmed** status and **CAD** currency.

### Eligible account population

A row is produced when:

- the plan has a non-empty VieFund dealer account ID
- the plan does not belong to the internal Agora customer
- the plan has a cash account in the selected currency
- the cash account existed by the simulated generation time, when one is supplied
- the plan is not in the configured explicit exclusion list

Every eligible VieFund cash-account source row is reported. When a plan has multiple cash-account rows, the report retains each row instead of deduplicating the plan. The plan-level transaction balance and transaction count appear only on the preferred source row; additional source rows show zero so totals are not duplicated. Duplicate patterns remain listed on **Cutoff Review**.

A leading `#` is removed from cash account IDs in the output so they can be compared consistently with client files.

## Date Basis

The date basis determines which `UB_CashTrx` date controls whether a transaction falls on or before the reporting date.

| Selection       | VieFund field  | Meaning                                                 |
| --------------- | -------------- | ------------------------------------------------------- |
| Created date    | `dtCreated`    | When the cash transaction record was created in VieFund |
| Trade date      | `dtTrade`      | The cash transaction's effective trade date             |
| Processing date | `dtProcessing` | When VieFund processed the cash transaction             |
| Settlement date | `dtSettlement` | When the cash transaction settles                       |

Use the same basis as the client report being reconciled. **Settlement date** is the default and is the appropriate basis for a settled-cash position. **Trade date** can include transactions before they settle, subject to the availability rule described below.

## Historical Report Generation Time

**Simulated Report Generation Time** is optional, but it is important when reproducing a historical client report from the current replica.

The reporting date answers, "Which transaction dates are in scope?" The simulated generation time answers, "What records existed when the historical report was run?"

When supplied, the report:

- excludes cash accounts opened after that timestamp
- excludes transactions created after that timestamp
- lists known post-cutoff accounts and relevant backdated transactions on the **Cutoff Review** sheet

The settled balance does not reconstruct historical transaction statuses. Confirmed-only output excludes transactions that are currently Deleted, even if they may have had another status when an older client report was generated.

When a simulated generation time is supplied, the report separately identifies currently Deleted cash transactions whose linked fund or trust record changed after the cutoff. Their amounts appear as **Historical Inference Adjustment (Review Required)** and are added only to **Inferred Client Balance (Review Required)**. Linked modification timestamps do not identify the changed field, so these rows are candidates for review rather than proof of historical status.

Enter the client report's actual generation time when known. If only an email or delivery time is known, it can be used as an upper bound, but the Summary sheet should be reviewed to confirm the timestamp used. VieFund timestamps and validated report times are interpreted in Eastern time.

Leaving this field blank uses the replica's current account population and current transaction statuses. That is suitable for a current report, but it may not reproduce a historical file exactly.

## Future-Settlement Cash

The main report flags positive Confirmed cash linked to an Unsettled trust transaction when its settlement date is after the reporting date. These fields are informational:

- **Future Settlement Transactions**
- **Future Settlement Cash (Info)**
- **Next Settlement Date**
- **Clarification Note**

Future-settlement cash is not automatically added to the settled balance.

The Summary reports one **Future Settlement Cash (Review Required)** amount. It no longer classifies this evidence as included in or excluded from the client report. On a trade-date report, eligible future-settling cash may already be represented in the strict balance through the selected date basis.

## Inferred Client Balance

The Summary sheet includes **Inferred Client Balance (Review Required)** as a provisional comparison value:

```text
Inferred Client Balance (Review Required)
	= Total Settled Balance
	+ Historical Inference Adjustment (Review Required)
```

The inference adjustment sums historical inference candidates after applying the linked-trust evidence rule described below. It does not automatically add accounts or transactions created after the cutoff, and it does not assert that the inferred historical status is proven. The **Cutoff Review** sheet is the evidence and decision surface for resolving those rows.

For historical inference candidates, **Linked Trust Amount Left** shows the linked trust transaction's current `mAmountLeft`. A positive value suppresses that row's suggested inference adjustment because the linked amount remains available rather than being consumed. **Suggested Inference Treatment** makes the applied rule explicit for client review. The row remains on **Cutoff Review** because `mAmountLeft` is a current snapshot value rather than a value preserved at the simulated generation time.

## Output Columns

| Column                         | Description                                                     |
| ------------------------------ | --------------------------------------------------------------- |
| Client Name                    | Customer name from the owning VieFund plan                      |
| Rep Code                       | Representative code found through the included cash activity    |
| Plan Account ID                | VieFund dealer/plan account identifier                          |
| Account ID                     | Normalized cash account identifier                              |
| Account Status                 | Current selected cash-account status, such as `A` or `T`        |
| Cash Transactions              | Number of included cash ledger transactions                     |
| Settled Balance                | Sum of included cash transaction amounts for the selected basis |
| Future Settlement Transactions | Count of flagged positive future-settlement cash transactions   |
| Future Settlement Cash (Info)  | Informational total of those flagged transactions               |
| Next Settlement Date           | Earliest settlement date among the flagged transactions         |
| Clarification Note             | Explains why future-settlement cash is shown separately         |

## Excel And CSV Output

### Excel

Excel is recommended for reconciliation work. The workbook contains:

- **Customer Balances** sheet with numeric count and currency cells
- frozen header row
- filters across the full report table
- **Summary** sheet containing criteria, strict totals, review counts, and the inferred client balance
- **Cutoff Review** sheet containing historical inference candidates, accounts opened after the cutoff, relevant transactions created after the cutoff, and duplicate cash-account patterns
- blank **Review Decision** and **Review Notes** columns for offline review

Because balances are numeric cells rather than formatted text, they can be sorted, filtered, summed, and used in formulas.

### CSV

CSV contains the customer-balance rows and Summary fields. The **Cutoff Review** worksheet is available only in Excel because CSV does not support multiple sheets.

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

| Value | Status     |
| ----: | ---------- |
|     0 | Deleted    |
|     1 | Rejected   |
|     2 | Cancelled  |
|     3 | Pending    |
|     4 | Accepted   |
|     5 | Contracted |
|     6 | Confirmed  |

Use **Confirmed** only for the standard customer balance reconciliation. Select **Deleted** only when intentionally reviewing current deleted transactions; simulated generation time does not reconstruct historical status.

### Output Format

- Choose **Excel** for sorting, filtering, numeric analysis, and a separate Summary sheet.
- Choose **CSV** for integrations or tools that require plain comma-separated data.

## Reading The Summary Sheet

Confirm these values before comparing totals:

- **Report Date**
- **Date Basis**
- **Balance Source**: should be `Direct Cash Ledger`
- **Cash Statuses**: normally `Confirmed`
- **Status Evaluation**: current replica status with historical inference shown separately
- **Simulated Generation Time**
- **Plan Accounts**
- **Deduped Accounts (AGRA / AGRA CASH Pattern)**
- **Inferred Client Plan Accounts**
- **Total Settled Balance**
- **Future Settlement Cash (Review Required)**
- **Cutoff Review Records**
- **Historical Inference Candidates**
- **Historical Inference Adjustment (Review Required)**
- **Inferred Client Balance (Review Required)**

The generated-at time records when this application completed the export. It is different from the simulated generation time.

### Duplicate cash-account pattern

VieFund can contain two pre-cutoff CAD cash-account rows for the same plan and the same AccountID. The client export can render these as separate `AGRA <id>` and `AGRA CASH <id>` rows, while this report selects one representative cash account for the plan.

The **Cutoff Review** sheet lists each matching plan with its source row count and deduped account count. The Summary calculates:

```text
Inferred Client Plan Accounts
	= Plan Accounts
	+ Deduped Accounts (AGRA / AGRA CASH Pattern)
```

This inference adjusts the expected client row count only. It does not duplicate the plan balance or change any balance total.

## Caveats And Special Circumstances

### The replica is mutable

The VieFund replica is refreshed from production. Current balances, statuses, and linked trust usage can differ from their historical state. A historical reporting date alone is not enough to recreate what a user saw months or years earlier.

### Historical status inference requires review

The strict report applies selected current cash transaction statuses. The separate inference treats a currently Deleted cash transaction as a candidate when a linked fund or trust record changed after the simulated generation time. That timestamp does not identify which field changed, so unrelated linked changes can produce false positives. Review the candidate rows before relying on the inferred balance.

### Future-settlement information is not a settled balance

Do not add the entire **Future Settlement Cash (Review Required)** amount to the settled total. The report intentionally does not decide whether each future-settlement row appeared in a historical client report.

### Account Status is current descriptive data

The displayed cash-account status is selected from the eligible cash account record. It should not be interpreted as a fully versioned historical account status unless the source system provides that history.

### Precision

VieFund stores transaction amounts with more precision than the two decimals displayed as currency. Use displayed two-decimal values for client file comparison, while recognizing that sub-cent source precision can affect unrounded internal sums.

### Estimate versus evidence

The inferred client balance is a guesstimate based on linked-record timestamps. The direct ledger total remains the auditable output, and the **Cutoff Review** rows remain unresolved until reviewed.

## Validation Status

Available client cash-balance reports have been cross-checked against this implementation. Historical files may not match exactly because the replica does not retain direct cash-status history; the inferred balance and review sheet make that uncertainty visible.

Technical investigation details and reproducible command-line examples are retained in [VieFund Customer Balances Report Criteria](viefund_customer_balances_report_criteria.md).
