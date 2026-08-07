# VieFund `Trx` Data Source Inventory

## Scope

The schema export contains 114 tables whose names include `Trx` (case-insensitive):

- 76 populated tables
- 38 empty tables
- 32 core transaction or linkage tables
- 26 definition tables
- 16 tax/reporting tables
- 11 compliance/confirmation tables
- 18 staging/integration tables
- 11 other tables

The exported row counts are discovery metadata, not historical facts. The live database is refreshed from production daily, so current mutable values cannot be treated as the state that existed when a historical client report ran.

To reproduce the June 24, 2026 client report, a row must satisfy both timelines:

1. Its applicable business date must be on or before June 24, 2026.
2. It must have existed by the latest defensible client report generation time: July 30, 2026 at 8:51 AM Eastern.

## Primary Ledger Tables

These tables currently drive cash reconstruction:

| Table              | Exported rows | Role                                                        |
| ------------------ | ------------: | ----------------------------------------------------------- |
| `UB_FundTrx`       |     4,670,859 | Fund transaction facts                                      |
| `UB_FundTrxDetail` |     4,670,859 | One-to-one settlement, fee, and confirmation detail         |
| `UB_FundTrxLookup` |     4,670,859 | Report-oriented fund transaction lookup and allocation rows |
| `UB_TrustTrx`      |     4,347,885 | Trust cash ledger                                           |
| `UB_CashTrx`       |     4,333,224 | Cash-account ledger                                         |
| `UB_FundTrxCash`   |     3,789,986 | Fund-to-cash transaction linkage                            |
| `UB_RESPTrx`       |        44,970 | RESP lifecycle and reversal linkage                         |

`UB_Def_TrxType`, `UB_Def_TrxStatus`, and `UB_Def_RESP_TrxType` decode the relevant type and status IDs.

## High-Value Unused Sources

### `UB_TrustTrxDetail`

- 3,265,799 exported rows.
- Columns: `iTrustID`, `iTrustDepositID`, and `mAmount`.
- Records how trust deposits are allocated or consumed.
- This is the strongest unchecked source for reconstructing historical trust availability without relying on mutable `mAmountLeft` or `mAmountUsed` fields.
- It is an allocation ledger, not an independent cash amount to add to `UB_TrustTrx`.

### `UB_AssetAllocationTrxHeader` and `UB_AssetAllocationTrxDetail`

- 52,044 and 544,646 live rows respectively when inspected on August 2, 2026.
- Header has `iPlanID`, `dtCreated`, `mAmount`, `iTrxType`, and `iTrustTrxID`.
- Detail links the header to `iTrxID`, `iBasketID`, and `iOrderID`.
- Every header linked to a trust transaction settled by June 24 and available by the report cutoff was type 22, cash-in, and trust-funded.
- The historical slice contained 25,841 headers across 7,445 plans and linked to 25,717 distinct trust rows.
- The residual set contained 107 headers across 16 of 83 mapped residual plans.
- These rows explain transaction intent and fund allocation, but their linked trust amounts are already represented in the trust ledger. They must not be added as separate cash.

### `UB_GICAccountTrx`

- 5,978 live rows when inspected on August 2, 2026.
- Contains plan/account IDs, transaction type/status, amounts, effective and settlement dates, creation timestamps, and trust linkage.
- 3,739 rows met the June 24 business cutoff and July 30 availability cutoff.
- 3,723 of those rows had a matching `UB_TrustTrx.iGICTrxID` row.
- The 16 unmatched rows were all status 0, had `iTrustID = 0`, and had no funding link. They are incomplete workflow records, not independent settled cash.
- Four residual plans contain GIC transactions, but adding GIC amounts would usually double-count their trust-ledger counterparts.

## Linkage and Workflow Tables

These tables can explain lineage but are not independent balance ledgers:

| Tables                                                                                       | Purpose                                                                   |
| -------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------- |
| `UB_FundTrxOrder`, `UB_FundTrxOrderARC`, `UB_FundTrxOrderTrx`                                | Order workflow, archived order state, and order-to-fund-transaction links |
| `UB_FundTrxOrderTransfer`                                                                    | Transfer source-account metadata                                          |
| `UB_FundTrxConversion`                                                                       | Conversion source/destination links and rebate amounts                    |
| `UB_FundTrxFeeInfo`                                                                          | Fees attached to a fund transaction                                       |
| `UB_TrustTrxBank`, `UB_TrustTrxCheque`, `UB_TrustTrxEstate`                                  | Payment destination and estate metadata                                   |
| `UB_TrxConfirmation`, `UB_TrxConfirmationClient`                                             | Confirmation object/client delivery metadata; no transaction amount       |
| `UB_CompTrxApprovalStatus`, `UB_CompTrxApprovalStatusARC`, `UB_CompTrxApprovalStatusHistory` | Compliance approval state and history                                     |

## Derived Sources

The following populated families derive from primary transactions and should be used for verification or metadata, not summed into cash:

- Tax/reporting: `UB_RRSP_TRX`, `UB_RRSPTaxReceiptTrx`, `UB_T4RIF_TRX`, `UB_T4RSP_TRX`, `UB_T4FHSA_TRX`, `UB_T5008_TRX`, `UB_T3_TRX`, `UB_T5_TRX`, `UB_T4A_TRX`, `UB_NR4_TRX`, `UB_RL2_TRX`, `UB_RL3_TRX`, `UB_RL16_TRX`, and `UB_RL18_TRX`.
- Compliance/reporting: `UB_ComplianceTrxTrend`, `UB_CompliancePlanCommFeeTrx`, and `UB_CompTrxApprovalPlanInfo`.
- Search/materialized helpers: `UB_GICTrxSearchList` and `UB_FundTrxOrderSearchList`.
- Import definitions and staging: `UB_FS_DefTrxRec`, `UB_FS_DefXMLTrxRec`, `UB_FS_DefXMLTrxRecDetail`, `UB_FS_DefXMLTrxRecDetailFee`, `UB_FS_DefXMLTrxRecReject`, and `SK_TrxFileItem`.

The remaining `Trx`-named tables in the export are definition tables or empty staging/legacy tables. The authoritative complete list and point-in-time row counts remain in `resources/data/viefund_db_schema/viefund_row_counts.csv`.

## Audit Trail Finding

`UB_AuditTrail` and `UB_AuditTrailGroup` do not contain groups under the core transaction table names. Audit groups do exist for `UB_CashAccount` and may help recover changes to mutable account fields if the audited field IDs can be decoded. The audit trail should therefore be investigated as account-state history, not assumed to be transaction-level change data capture.

## Recommended Investigation Order

1. Use the direct settled `UB_CashTrx` rollup as the baseline and preserve the three known unsettled client-file residuals.
2. Decode `UB_TrustTrxDetail` only where it helps explain those residual transaction lifecycles.
3. Decode `UB_AuditTrail.iFieldID` for `UB_CashAccount` and determine whether cash balance fields are versioned.
4. Use asset-allocation, order, GIC, RESP, and confirmation tables only to explain lineage or validate lifecycle state.
5. Compare every candidate rule against the full normalized client population before changing production report logic.

## Direct Cash Transaction Baseline

The simplest full-population method produced the strongest result found so far:

1. Build the plan/account universe from `UB_Plan` and CAD `UB_CashAccount` rows available by the report-generation cutoff.
2. Join `UB_CashTrx` directly to `UB_CashAccount`.
3. Include all transaction types confirmed at the historical report-generation time. Do not generally include currently deleted transactions.
4. Include `dtSettlement < 2026-06-25`.
5. Include only transactions available no later than the email-derived upper bound of `2026-07-30 08:51:00` Eastern.
6. Sum `UB_CashTrx.mAmount` by plan and map the plan to its normalized cash account ID.

One transaction, `UB_CashTrx.ID = 4733867` for `6106341PPR`, is currently deleted but was not deleted until 1:54 PM Eastern on July 30. Because the report was emailed by 8:51 AM Eastern, this row must be reconstructed as confirmed for the historical run. This does not justify selecting the general `Deleted` report option.

Three client balances totaling `$19.86` correspond to transactions settling on August 4, 2026. A strict June 24 settlement-date report excludes them even though the client workbook includes the amounts.

Against the normalized client file, with duplicate normalized account IDs aggregated and the post-report deletion reconstructed, this method produced:

- 37,314 common accounts
- 37,301 exact common-account balances
- 13 different common-account balances
- 3 material differences totaling `$19.86`
- 10 sub-cent precision differences that display as equal at two decimals
- Client total: $41,913,836.18
- Direct rollup total: $41,913,816.32
- Delta (client minus rollup): $19.86

Restricting the direct rollup to transaction types 22 and 45 produced 97 differences and understated the client by $1,333,818.17. The client report therefore appears to roll up all settled CAD cash transaction types, not only the fund-linked deposit types.

The production customer-balances report now uses this direct cash-ledger rollup. The three material residuals remain excluded from settled balance because their settlement date is August 4, 2026; they appear in separate future-settlement informational columns.

## Programmable Database Objects

The replica login can see no stored procedures in `sys.procedures`, and the Laravel application does not execute `EXEC`, `EXECUTE`, or `CALL` statements. The login does not have database-level `VIEW DEFINITION`, so module bodies are unavailable.

Visible programmable objects include:

- 75 table-valued functions
- 180 triggers
- 322 synonyms, mainly redirecting temporary and document objects to `VieFUNDTMP` and `VieFUNDDoc`
- Enabled insert/update/delete triggers on the core fund and trust ledgers, plus a delete trigger on `UB_CashTrx`

Relevant visible functions include `GetCashAccountPendingCashList`, `GetPlanPendingCashList`, and `GetPlanListPendingCashList`. Their names indicate pending-cash workflow, while the client parity result points to settled `UB_CashTrx` as the balance source. Procedure discovery is therefore lower priority than explaining the four direct-rollup differences.
