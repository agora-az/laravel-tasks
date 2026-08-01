# VieFund Customer Balances Exclusion Review

Report-only rows after removing cashless placeholders. Review this table for explicit source flags before choosing any exclusion rule.

## Summary

- Report-only rows: 30
- Distinct names: 27

## Candidate Flags

- dealership_1912: 29
- customer_flag_0: 29
- customer_status_1: 27
- cash_active: 24
- cash_terminated: 5
- customer_status_2: 2
- dealership_: 1
- customer_status_: 1
- customer_flag_: 1

## Review Table

| Name | Plan Account ID | Account ID | Dealer Account ID | Plan ID | DealerCode | Customer ID | Dealership ID | Customer Status | Customer Flag | Plan Start | Plan End | Cash Statuses | Flags | Balance |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 9518-5963 Québec Inc. | 5125310PAO | 5125310PAO | 5125310PAO | 33837 | 7741 | 14407 | 1912 | 1 | 0 | 2025-10-29 00:00:00.000 |  | A | plan_active, dealership_1912, customer_status_1, customer_flag_0, cash_active | $0.00 |
| AGORA DEALER SERVICES CORP. | 39697 | 39697 |  |  |  |  |  |  |  |  |  |  | plan_ended, dealership_, customer_status_, customer_flag_ | $0.00 |
| AMIT PRABHU | 5123429PAO | 5123429PAO | 5123429PAO | 30731 | 7686 | 13098 | 1912 | 1 | 0 | 2025-07-21 00:00:00.000 |  | A | plan_active, dealership_1912, customer_status_1, customer_flag_0, cash_active | $0.00 |
| ASPEN VETERINARY MEDICAL IMAGING INC. | 5121347PAO | 5121347PAO | 5121347PAO | 26946 | 9775 | 12198 | 1912 | 1 | 0 | 2025-03-11 00:00:00.000 |  | A | plan_active, dealership_1912, customer_status_1, customer_flag_0, cash_active | $0.00 |
| Alex Larouche | 5131684PAT | 5131684PAT | 5131684PAT | 41286 | 7741 | 17664 | 1912 | 1 | 0 | 2026-07-30 00:00:00.000 |  | A | plan_active, dealership_1912, customer_status_1, customer_flag_0, cash_active | $0.00 |
| Allan Winikoff | 5115465PAO | 5115465PAO | 5115465PAO | 18526 | 7686 | 8977 | 1912 | 1 | 0 | 2024-04-10 00:00:00.000 |  | A | plan_active, dealership_1912, customer_status_1, customer_flag_0, cash_active | $0.00 |
| Allwyn Pinto | 5103571PAO | 5103571PAO | 5103571PAO | 2428 | 7686 | 1300 | 1912 | 1 | 0 | 2021-08-27 00:00:00.000 | 2023-01-24 16:39:02.460 | T | plan_ended, dealership_1912, customer_status_1, customer_flag_0, cash_terminated | $0.00 |
| April Dejong | 5131623PAT | 5131623PAT | 5131623PAT | 41281 | 7686 | 17660 | 1912 | 1 | 0 | 2026-07-30 00:00:00.000 |  | A | plan_active, dealership_1912, customer_status_1, customer_flag_0, cash_active | $0.00 |
| CATHY TUPPER | 5131665PAR | 5131665PAR | 5131665PAR | 41288 | 9408 | 17666 | 1912 | 1 | 0 | 2026-07-30 00:00:00.000 |  | A | plan_active, dealership_1912, customer_status_1, customer_flag_0, cash_active | $0.00 |
| Christopher Blair | 5105576PAO | 5105576PAO | 5105576PAO | 4551 | 9775 | 140 | 1912 | 1 | 0 | 2022-05-02 00:00:00.000 |  | A | plan_active, dealership_1912, customer_status_1, customer_flag_0, cash_active | $0.00 |
| Dandylion Pet Inc. | 5123937PAO | 5123937PAO | 5123937PAO | 31086 | 7686 | 13572 | 1912 | 1 | 0 | 2025-08-07 00:00:00.000 |  | A | plan_active, dealership_1912, customer_status_1, customer_flag_0, cash_active | $0.00 |
| ESTRIC LTEE | 5107877PAO | 5107877PAO | 5107877PAO | 7477 | 7741 | 1976 | 1912 | 2 | 0 | 2023-02-20 00:00:00.000 | 2024-12-23 13:07:04.643 | T | plan_ended, dealership_1912, customer_status_2, customer_flag_0, cash_terminated | $0.00 |
| Gayle Murphy | 5131638PAF | 5131638PAF | 5131638PAF | 41279 | 7686 | 17658 | 1912 | 1 | 0 | 2026-07-30 00:00:00.000 |  | A | plan_active, dealership_1912, customer_status_1, customer_flag_0, cash_active | $0.00 |
| JULIET MARVIN | 5131590PARE | 5131590PARE | 5131590PARE | 41287 | 9408 | 17665 | 1912 | 1 | 0 | 2026-07-30 00:00:00.000 |  | A | plan_active, dealership_1912, customer_status_1, customer_flag_0, cash_active | $0.00 |
| Jonah Cristall-Clarke | 5103685PAO | 5103685PAO | 5103685PAO | 2536 | 7686 | 1285 | 1912 | 1 | 0 | 2021-09-13 00:00:00.000 | 2023-04-17 17:32:36.683 | T | plan_ended, dealership_1912, customer_status_1, customer_flag_0, cash_terminated | $0.00 |
| Jonathan Cant | 5131853PAR | 5131853PAR | 5131853PAR | 41285 | 7686 | 17663 | 1912 | 1 | 0 | 2026-07-30 00:00:00.000 |  | A | plan_active, dealership_1912, customer_status_1, customer_flag_0, cash_active | $0.00 |
| Lisa Riehl | 5131873PAA | 5131873PAA | 5131873PAA | 41278 | 9854 | 7617 | 1912 | 1 | 0 | 2026-07-30 00:00:00.000 |  | A | plan_active, dealership_1912, customer_status_1, customer_flag_0, cash_active | $0.00 |
| MOIRA MACRI | 5131834PAF | 5131834PAF | 5131834PAF | 41289 | 9153 | 14579 | 1912 | 1 | 0 | 2026-07-31 00:00:00.000 |  | A | plan_active, dealership_1912, customer_status_1, customer_flag_0, cash_active | $0.00 |
| Maurice Fleming | 5131825PAF | 5131825PAF | 5131825PAF | 41290 | 7686 | 17667 | 1912 | 1 | 0 | 2026-07-31 00:00:00.000 |  | A | plan_active, dealership_1912, customer_status_1, customer_flag_0, cash_active | $0.00 |
| P & J EDWARDS SERVICES INC | 5121489PAO | 5121489PAO | 5121489PAO | 27232 | 7686 | 12316 | 1912 | 1 | 0 | 2025-03-20 00:00:00.000 |  | A | plan_active, dealership_1912, customer_status_1, customer_flag_0, cash_active | $0.00 |
| Patricia Bow | 5131845PAR | 5131845PAR | 5131845PAR | 41283 | 7686 | 17662 | 1912 | 1 | 0 | 2026-07-31 00:00:00.000 |  | A | plan_active, dealership_1912, customer_status_1, customer_flag_0, cash_active | $0.00 |
| Patricia Bow | 5131846PAT | 5131846PAT | 5131846PAT | 41284 | 7686 | 17662 | 1912 | 1 | 0 | 2026-07-31 00:00:00.000 |  | A | plan_active, dealership_1912, customer_status_1, customer_flag_0, cash_active | $0.00 |
| Prophet Wealth Inc. | 5108304PAO | 5108304PAO | 5108304PAO | 7916 | 7686 | 4275 | 1912 | 2 | 0 | 2023-03-27 00:00:00.000 | 2024-01-05 16:26:50.323 | T | plan_ended, dealership_1912, customer_status_2, customer_flag_0, cash_terminated | $0.00 |
| Rosalyn Stockdale | 5131618PAF | 5131618PAF | 5131618PAF | 41280 | 7686 | 17659 | 1912 | 1 | 0 | 2026-07-30 00:00:00.000 |  | A | plan_active, dealership_1912, customer_status_1, customer_flag_0, cash_active | $0.00 |
| Samir Bashir Khalil | 5131722PAT | 5131722PAT | 5131722PAT | 41291 | 7686 | 12263 | 1912 | 1 | 0 | 2026-07-31 00:00:00.000 |  | A | plan_active, dealership_1912, customer_status_1, customer_flag_0, cash_active | $0.00 |
| Samir Bashir Khalil | 5131723PAT | 5131723PAT | 5131723PAT | 41292 | 7686 | 12263 | 1912 | 1 | 0 | 2026-07-31 00:00:00.000 |  | A | plan_active, dealership_1912, customer_status_1, customer_flag_0, cash_active | $0.00 |
| Stephen Bow | 5131844PAR | 5131844PAR | 5131844PAR | 41282 | 7686 | 17661 | 1912 | 1 | 0 | 2026-07-30 00:00:00.000 |  | A | plan_active, dealership_1912, customer_status_1, customer_flag_0, cash_active | $0.00 |
| Thierry Desmarais | 5105272PAT | 5105272PAT | 5105272PAT | 4260 | 7741 | 734 | 1912 | 1 | 0 | 2022-03-21 00:00:00.000 | 2024-12-23 13:01:55.327 | T | plan_ended, dealership_1912, customer_status_1, customer_flag_0, cash_terminated | $0.00 |
| Thierry Desmarais | 5108179PAO | 5108179PAO | 5108179PAO | 7758 | 7741 | 734 | 1912 | 1 | 0 | 2023-03-14 00:00:00.000 |  | A | plan_active, dealership_1912, customer_status_1, customer_flag_0, cash_active | $0.00 |
| Timothy Allan Blair | 5105577PAO | 5105577PAO | 5105577PAO | 4545 | 9775 | 32 | 1912 | 1 | 0 | 2022-05-01 00:00:00.000 |  | A | plan_active, dealership_1912, customer_status_1, customer_flag_0, cash_active | $0.00 |
