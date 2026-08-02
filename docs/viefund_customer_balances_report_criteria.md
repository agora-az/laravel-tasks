# VieFund Customer Balances Report Criteria

This document captures the validated report criteria used to reconcile against client files for both settlement-date and trade-date bases.

## Purpose

Use these criteria when generating the customer balances report to reproduce client-compatible account universe and counts.

## Core Report Definition

- Report command: `report:viefund-customer-balances`
- Report date: as requested (for example `2020-02-18`, `2020-07-21`)
- Date basis: `settlement_date` or `trade_date`
- Fund status filter: `6` (Confirmed)
- Trust status filter: excluded (no trust status option passed)
- Fund cash transaction types: internally constrained to `ct.iType IN (22, 45)`

## Account Universe Criteria

The report includes plans/accounts that satisfy all of the following:

- `UB_Plan.DealerAccountID` is present (not null, not empty)
- Internal Agora customer is excluded (`UB_Plan.iClientID <> 28`)
- At least one matching `UB_CashAccount` row exists for the plan

Client-compatible scope (enabled through config/env values):

- Cash account currency is CAD: `UB_CashAccount.CurrencyCode = '00'`
- Cash account open date cutoff: `UB_CashAccount.dtOpen <= '2026-07-30 20:00:00'`
- Explicit excluded plan accounts: `39697`
- Cash account ID normalization strips leading `#` before matching/output selection

## Environment Values Used In Validated Runs

Set these before running the report command:

- `VIEFUND_BALANCE_REPORT_CASH_CURRENCY_CODE=00`
- `VIEFUND_BALANCE_REPORT_CASH_OPENED_BEFORE='2026-07-30 20:00:00'`
- `VIEFUND_BALANCE_REPORT_EXCLUDED_PLAN_ACCOUNTS='39697'`

Important: `VIEFUND_BALANCE_REPORT_CASH_OPENED_BEFORE` must match the client report run timestamp (not just the report date). Accounts opened after that run time can appear as report-only rows.

For the June 24, 2026 client file run on July 30 around 8:00 AM Pacific, this value reconciled exactly:

- `VIEFUND_BALANCE_REPORT_CASH_OPENED_BEFORE='2026-07-30 08:00:00'`

## Reconciliation Comparison Rules

When comparing report output to client CSV:

- Compare by normalized account ID
- Remove prefixes `AGRA CASH`, `AGRP CASH`, `AGRA`, `AGRP`
- Remove optional leading `#`
- Ignore CSV header/summary rows (`Account ID`, `CASH AGCH`, empty)

## Verified Outcomes

Using the criteria above, reconciliation matched exactly:

- 2020-02-18 settlement-date: `report_only=0`, `client_only=0`
- 2020-02-18 trade-date: `report_only=0`, `client_only=0`
- 2020-07-21 settlement-date: `report_only=0`, `client_only=0`
- 2020-07-21 trade-date: `report_only=0`, `client_only=0`
- 2026-06-24 settlement-date (client run ~8:00 AM PT on 2026-07-30): `report_only=0`, `client_only=0`

## Run Commands

Settlement-date basis:

```bash
VIEFUND_BALANCE_REPORT_CASH_CURRENCY_CODE=00 \
VIEFUND_BALANCE_REPORT_CASH_OPENED_BEFORE='2026-07-30 20:00:00' \
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
VIEFUND_BALANCE_REPORT_CASH_OPENED_BEFORE='2026-07-30 20:00:00' \
VIEFUND_BALANCE_REPORT_EXCLUDED_PLAN_ACCOUNTS='39697' \
/opt/homebrew/opt/php@8.2/bin/php artisan report:viefund-customer-balances \
  --report-date=YYYY-MM-DD \
  --date-basis=trade_date \
  --status=6 \
  --format=csv \
  --output-file=reports/viefund_customer_balances_YYYYMMDD_trade_scope.csv
```
