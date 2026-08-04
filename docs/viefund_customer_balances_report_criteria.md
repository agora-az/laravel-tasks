# VieFund Customer Balances Report Criteria

This document captures the report criteria used to compare client files for both settlement-date and trade-date bases.

## Purpose

Use these criteria when generating the customer balances report to reproduce the client-compatible account universe and to measure balance parity separately.

## Core Report Definition

- Report command: `report:viefund-customer-balances`
- Report date: as requested (for example `2020-02-18`, `2020-07-21`)
- Date basis: `settlement_date` or `trade_date`
- Cash Transaction Status: `6` (Confirmed)
- Currency: CAD (`00`)

For the June 24 comparison, do not select `Deleted` merely because the current replica shows a historically included transaction as deleted. Status must be evaluated as it existed when the client report was generated.

## Account Universe Criteria

The report includes plans/accounts that satisfy all of the following:

- `UB_Plan.DealerAccountID` is present (not null, not empty)
- Internal Agora customer is excluded (`UB_Plan.iClientID <> 28`)
- At least one matching `UB_CashAccount` row exists for the plan

Client-compatible scope (enabled through config/env values):

- Cash account currency is CAD: `UB_CashAccount.CurrencyCode = '00'`
- Cash account open date cutoff: no later than `UB_CashAccount.dtOpen <= '2026-07-30 08:51:00'` Eastern
- Explicit excluded plan accounts: `39697`
- Cash account ID normalization strips leading `#` before matching/output selection

## Environment Values Used In Validated Runs

Set these before running the report command:

- `VIEFUND_BALANCE_REPORT_CASH_CURRENCY_CODE=00`
- `VIEFUND_BALANCE_REPORT_CASH_OPENED_BEFORE='2026-07-30 08:51:00'`
- `VIEFUND_BALANCE_REPORT_EXCLUDED_PLAN_ACCOUNTS='39697'`

Important: `VIEFUND_BALANCE_REPORT_CASH_OPENED_BEFORE` must match the client report run timestamp (not just the report date). Accounts opened after that run time can appear as report-only rows.

For the June 24, 2026 client file comparison, the email timestamp establishes an upper bound of July 30, 2026 at 8:51 AM Eastern. The VieFund SQL Server also uses Eastern time. Matching the cutoff controls account membership; it does not prove balance parity or establish the exact generation second.

- `VIEFUND_BALANCE_REPORT_CASH_OPENED_BEFORE='2026-07-30 08:51:00'`

The previous 11:30 AM cutoff was an investigation assumption and is not supported by the source email. No June 24 balance transaction was created between 8:51 and 11:30, but two zero-balance CAD accounts were opened in that interval.

## Reconciliation Comparison Rules

When comparing report output to client CSV:

- Compare by normalized account ID
- Remove prefixes `AGRA CASH`, `AGRP CASH`, `AGRA`, `AGRP`
- Remove optional leading `#`
- Ignore CSV header/summary rows (`Account ID`, `CASH AGCH`, empty)

## Verified Account-Universe Outcomes

The following historical checks compared normalized account membership only. They did not compare per-account balances or report totals:

- 2020-02-18 settlement-date: `report_only=0`, `client_only=0`
- 2020-02-18 trade-date: `report_only=0`, `client_only=0`
- 2020-07-21 settlement-date: `report_only=0`, `client_only=0`
- 2020-07-21 trade-date: `report_only=0`, `client_only=0`
- 2026-06-24 settlement-date (client run on 2026-07-30): previously reported as `report_only=0`, `client_only=0`

## June 24, 2026 Balance Validation

### Required Run Options

- Date basis: `Settlement date`
- Cash Transaction Status: `Confirmed` only
- Currency: `CAD`
- Simulated report generation time: no later than `2026-07-30 08:51:00` Eastern

Do not select `Deleted` to reproduce the historical report. Cash transaction `4733867` for `6106341PPR` is currently deleted, but its linked fund and trust rows were modified at 1:54 PM Eastern on July 30, after the report had already been emailed. It was therefore still confirmed at report time and belongs in the historical balance without enabling the general `Deleted` option. This is a point-in-time status reconstruction rule, not a report-option choice.

When a simulated generation time is supplied, the report reconstructs this state narrowly: a currently deleted fund cash row is included only when its linked fund or trust row was modified after the simulated generation time. Transactions created after that time are excluded. Without a simulated generation time, current statuses are used as-is.

### Unsettled Transactions

The client workbook includes three balances that a strict June 24 settlement-date report must exclude:

| Account | Amount | Cash transaction | Settlement date | Trust state at report time |
| --- | ---: | ---: | --- | --- |
| `5102846PAO` | `$6.78` | `4796889` | `2026-08-04` | Unsettled |
| `5116553PAF` | `$6.78` | `4797271` | `2026-08-04` | Unsettled |
| `5126783PAF` | `$6.30` | `4798119` | `2026-08-04` | Unsettled |

All three were created on June 22, but their linked fund trade date was July 31 and their settlement date was August 4. They are not added to the June 24 settled balance. The report exposes positive confirmed cash linked to an Unsettled trust transaction in separate informational columns:

- `Future Settlement Transactions`
- `Future Settlement Cash (Info)`
- `Next Settlement Date`
- `Clarification Note`

This category also identifies account `5118337PAO` with `$15,260.68` settling on August 4. For a trade-date report, VieFund excludes future-settling cash when its linked trust remains Unsettled with no amount used and an amount still left. The other three August 4 rows are fully used and remain included by trade date. Applying that availability rule reproduces the client's June 24 trade-date total without hard-coding an account or amount.

The export summary separates future-settling cash into `Included in Client Estimate` and `Excluded from Client Estimate`. On a Direct Cash Ledger settlement-date report, a transaction is included when its linked Unsettled trust has `mAmountUsed > 0` or `mAmountLeft <= 0`; it is excluded when `mAmountUsed = 0` and `mAmountLeft > 0`. Thus `Future Settlement Cash (Info)` equals the included plus excluded amounts, and `Potential Client VieFund Balance (Estimate)` equals the settled report total plus the included amount. On trade-date and other report modes, no separate future amount is added; all informational future cash is classified as excluded from the estimate. Eligible trade-date cash is already represented by the selected date basis. The estimate is derived from current ledger and linked trust state and is not a stored client-report snapshot.

The production transaction model now:

- joins `UB_CashTrx` directly to its cash account
- includes all cash transaction types rather than only types 22 and 45
- scopes cash by the selected cash-account currency
- applies the selected cash date basis and report-date upper bound
- reconstructs transactions deleted after the simulated generation time
- exposes positive future-settlement cash linked to Unsettled trust as informational fields only

Against `resources/data/reports/Cash Balances -Jun 24 2026.csv`, the latest full comparison produced:

- client total: `$41,913,836.18`
- report total: `$41,913,816.32`
- total delta (client minus report): `$19.86`
- common normalized accounts: `37,314`
- exact common-account balance matches: `37,301`
- differing common-account balances: `13`
- material differences: `3`, totaling `$19.86`
- remaining differences: `10` sub-cent precision differences that display equally at two decimals
- client-only normalized accounts: `60`
- report-only normalized accounts: `126`

This is not exact file parity. The three material balance differences are shown in the report's future-settlement informational columns rather than added to settled balance. Account-membership differences remain visible for historical review.

## Run Commands

Settlement-date basis:

```bash
VIEFUND_BALANCE_REPORT_CASH_CURRENCY_CODE=00 \
VIEFUND_BALANCE_REPORT_CASH_OPENED_BEFORE='2026-07-30 08:51:00' \
VIEFUND_BALANCE_REPORT_EXCLUDED_PLAN_ACCOUNTS='39697' \
/opt/homebrew/opt/php@8.2/bin/php artisan report:viefund-customer-balances \
  --report-date=YYYY-MM-DD \
  --date-basis=settlement_date \
  --status=6 \
  --format=csv \
  --output-file=reports/viefund_customer_balances_YYYYMMDD_settle_scope.csv
```

Trade-date basis:

```bash
VIEFUND_BALANCE_REPORT_CASH_CURRENCY_CODE=00 \
VIEFUND_BALANCE_REPORT_CASH_OPENED_BEFORE='2026-07-30 08:51:00' \
VIEFUND_BALANCE_REPORT_EXCLUDED_PLAN_ACCOUNTS='39697' \
/opt/homebrew/opt/php@8.2/bin/php artisan report:viefund-customer-balances \
  --report-date=YYYY-MM-DD \
  --date-basis=trade_date \
  --status=6 \
  --format=csv \
  --output-file=reports/viefund_customer_balances_YYYYMMDD_trade_scope.csv
```
