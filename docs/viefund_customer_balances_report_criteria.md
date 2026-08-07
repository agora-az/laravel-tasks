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

The report applies the selected current `UB_CashTrx.iStatus` values. Selecting Confirmed excludes transactions that are currently Deleted, even when reproducing a historical report.

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

Do not select `Deleted` for the standard client comparison. The report does not infer historical cash status from linked fund or trust modification timestamps because those timestamps do not identify the changed field or establish when a cash transaction became Deleted. A simulated generation time excludes transactions created after that time but does not reconstruct earlier statuses.

### Unsettled Transactions

The client workbook includes three balances that a strict June 24 settlement-date report must exclude:

| Account      |  Amount | Cash transaction | Settlement date | Trust state at report time |
| ------------ | ------: | ---------------: | --------------- | -------------------------- |
| `5102846PAO` | `$6.78` |        `4796889` | `2026-08-04`    | Unsettled                  |
| `5116553PAF` | `$6.78` |        `4797271` | `2026-08-04`    | Unsettled                  |
| `5126783PAF` | `$6.30` |        `4798119` | `2026-08-04`    | Unsettled                  |

All three were created on June 22, but their linked fund trade date was July 31 and their settlement date was August 4. They are not added to the June 24 settled balance. The report exposes positive confirmed cash linked to an Unsettled trust transaction in separate informational columns:

- `Future Settlement Transactions`
- `Future Settlement Cash (Info)`
- `Next Settlement Date`
- `Clarification Note`

This category also identifies account `5118337PAO` with `$15,260.68` settling on August 4. For a trade-date report, VieFund excludes future-settling cash when its linked trust remains Unsettled with no amount used and an amount still left. The other three August 4 rows are fully used and remain included by trade date. Applying that availability rule reproduces the client's June 24 trade-date total without hard-coding an account or amount.

The export reports future-settling cash as `Future Settlement Cash (Review Required)`. It does not classify these rows as included in or excluded from the client report, and it does not automatically add them to the inferred balance.

When a simulated generation time is supplied, the Excel workbook also contains a `Cutoff Review` sheet with:

- currently Deleted cash transactions whose linked fund or trust record changed after the cutoff
- cash accounts opened after the cutoff
- relevant transactions created after the cutoff whose selected date falls within the reporting period
- plans with duplicate pre-cutoff cash-account rows sharing the same normalized cash AccountID

The first category is summed as `Historical Inference Adjustment (Review Required)`. The inferred balance equals the strict settled balance plus that adjustment. Linked modification timestamps do not prove a cash-status transition, so all rows remain review candidates and are not labeled as client-included or client-excluded.

The production transaction model now:

- joins `UB_CashTrx` directly to its cash account
- includes all cash transaction types rather than only types 22 and 45
- scopes cash by the selected cash-account currency
- applies the selected cash date basis and report-date upper bound
- applies selected current cash transaction statuses to the strict settled balance
- exposes historical status inference as a separate, review-required adjustment
- exposes positive future-settlement cash linked to Unsettled trust as informational fields only

## June 30, 2026 Cutoff Review Validation

Using a simulated generation time of `2026-08-04 15:07:00` Eastern:

- strict settled balance: `$43,372,898.25`
- distinct plan accounts: `37,541`
- duplicate cash-account rows included in the report: `5`
- reported account rows: `37,546`
- historical inference candidates: `10`
- historical inference adjustment: `($792.75)`
- inferred client balance: `$43,372,105.50`
- client file total: `$43,372,105.50`
- remaining review difference: `$0.00`
- accounts opened after cutoff: `41`
- relevant transactions created after cutoff: `0`

The five duplicate-pattern accounts are:

- `5107037PAR`
- `5110078PAT`
- `5110486PARE`
- `5110581PAR`
- `5122843PAO`

Each has two pre-cutoff CAD cash-account source rows with the same plan and AccountID. Both rows are included in the customer-balance output, but the plan balance remains represented once.

The remaining `$25.20` consists of two `$12.60` historical inference candidates whose linked records changed after the cutoff but which the client file did not include:

- cash record `3594797`, plan `1100818AT`
- cash record `3596253`, plan `5119808PAR`

For each plan, the current Confirmed-only ledger balance is `$12.60`, exactly matching the client balance. Adding the deleted `$12.60` candidate would double the account balance to `$25.20`. Both candidates are isolated fee-redemption credits whose linked fund and trust records are also currently Deleted. This differs from the other eight candidates, which form multi-leg fee, tax, purchase, and redemption groups and are required to reproduce their client account balances. The evidence supports excluding these two rows from the historical adjustment, but it does not establish when their status changed. This demonstrates why the inferred balance is a guesstimate and the review sheet is required.

These are also the only two historical inference candidates with a positive current linked trust `mAmountLeft`: `$12.60` for each record. The other eight candidates have `$0.00` left. The **Linked Trust Amount Left** and **Suggested Inference Treatment** cutoff-review columns expose the evidence and applied rule. Positive linked trust amounts suppress the two rows' suggested adjustments while preserving both rows for client review.

## May 29, 2026 Account-Population Validation

The client settlement report was generated on August 7, 2026 at 7:30 AM Eastern. Using that simulated generation time:

- client rows: `37,644`
- report rows after retaining duplicate cash-account source rows: `37,621`
- client distinct normalized account IDs: `37,552`
- report distinct normalized account IDs: `37,529`
- duplicate source rows retained by the report: `5`
- client-only account IDs: `23`
- report-only account IDs: `0`
- client and report balance: `$40,704,006.65`

The five duplicate source rows explain five of the original 28-row difference. Each eligible `UB_CashAccount` row is now reported, while the plan-level balance and transaction counts remain on one preferred row so totals are not duplicated.

All 23 remaining client-only accounts are Active zero-balance rows. None currently exists in `UB_Plan.DealerAccountID` or `UB_CashAccount.AccountID`, and no matching entry was found in `UB_AuditTrail`, `UB_AuditTrailGroup`, `UB_EventLog`, or `AAA_Log`. Their account-number sequences align with plans created around August 5-6. The evidence is consistent with transient plans or cash accounts that existed when the client generated the report and were physically removed afterward, but the current replica cannot prove when or why they were removed.

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
