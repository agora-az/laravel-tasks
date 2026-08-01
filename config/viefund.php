<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Remote VieFund Transaction Exclusions
    |--------------------------------------------------------------------------
    | Rows where the named column matches any listed value are excluded from
    | the Remote VieFund transactions view.
    |
    | Available keys and the query columns they map to:
    |
    |   'trx_type'      => tt.NameEN   — fund-level transaction type
    |                      (from UB_FundTrxLookup.iType → UB_Def_TrxType)
    |
    |   'cash_trx_type' => ctt.NameEN  — individual cash transaction type
    |                      (from UB_CashTrx.iType → UB_Def_TrxType)
    |
    | Add more string values to any array, or add new keys from the map above.
    | Rows where the column is NULL are always kept regardless of this config.
    */

    'exclusions' => [

        'trx_type' => [
            // 'Reinvested Distribution',
            // 'Rebalancing redemption',
            // 'Rebalancing purchase',
            // 'External Transfer-out',
            // 'External Transfer-in'
        ],

        'cash_trx_type' => [
            // 'Example Type',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Hide Zero-Amount Transactions
    |--------------------------------------------------------------------------
    | Global default for whether $0.00 transactions are hidden. Default false so
    | zero-value transactions are INCLUDED (they affect counts, never net totals).
    | Individual queries can still force-hide them via the repository's
    | $hideZeroAmount override on buildBaseQuery()/buildTrustBaseQuery().
    */

    'hide_zero_amount' => filter_var(env('VIEFUND_HIDE_ZERO_AMOUNT', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Default Status Filters (Daily Totals Sync + Reports)
    |--------------------------------------------------------------------------
    | Used whenever no explicit selection is supplied: the scheduled
    | viefund:sync-daily-totals command, the initial daily-totals / reports
    | form state, and the drilldown fallback for a day with no snapshot row.
    |
    | VIEFUND_DEFAULT_FUND_STATUS — comma-separated fund status IDs
    |   (UB_Def_TrxStatus): 0=Deleted 1=Rejected 2=Cancelled 3=Pending
    |   4=Accepted 5=Contracted 6=Confirmed.   e.g. "6,5,3"
    |
    | VIEFUND_DEFAULT_TRUST_STATUS — comma-separated trust statuses, given as
    |   names (Deleted|Unsettled|Settled) or their position 0|1|2.
    |   An empty value excludes trust.   e.g. "2" (Settled) or "Settled"
    */

    'default_fund_status' => (static function () {
        $ids = array_values(array_unique(array_filter(
            array_map(
                static fn ($v) => (int) trim($v),
                explode(',', (string) env('VIEFUND_DEFAULT_FUND_STATUS', '6'))
            ),
            static fn ($id) => $id >= 0 && $id <= 6
        )));

        return $ids ?: [6];
    })(),

    'default_trust_status' => (static function () {
        $names = ['Deleted', 'Unsettled', 'Settled'];
        $out = [];

        foreach (explode(',', (string) env('VIEFUND_DEFAULT_TRUST_STATUS', 'Settled')) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            if (is_numeric($token)) {
                $idx = (int) $token;
                if (isset($names[$idx])) {
                    $out[] = $names[$idx];
                }
                continue;
            }

            foreach ($names as $name) {
                if (strcasecmp($name, $token) === 0) {
                    $out[] = $name;
                }
            }
        }

        return array_values(array_unique($out));
    })(),

    /*
    |--------------------------------------------------------------------------
    | Default Daily-Totals Date Basis
    |--------------------------------------------------------------------------
    | Which VieFund date column the daily-totals snapshot is built on by
    | default: create_date | trade_date | processing_date | settlement_date.
    */

    'default_date_basis' => (static function () {
        $allowed = ['create_date', 'trade_date', 'processing_date', 'settlement_date'];
        $value = trim((string) env('VIEFUND_DEFAULT_DATE_BASIS', 'settlement_date'));

        return in_array($value, $allowed, true) ? $value : 'settlement_date';
    })(),

    /*
    |--------------------------------------------------------------------------
    | Experimental Cash-Account Scope For Balance Reports
    |--------------------------------------------------------------------------
    | Optional account-universe gates for customer balance reports. These are
    | intentionally off by default and can be enabled ad hoc when trying to
    | reproduce the client-side cash-account inquiry behavior.
    |
    | VIEFUND_BALANCE_REPORT_CASH_CURRENCY_CODE
    |   Restrict eligible cash accounts by CurrencyCode (for example "00" for
    |   CAD or "01" for USD).
    |
    | VIEFUND_BALANCE_REPORT_CASH_OPENED_BEFORE
    |   Restrict eligible cash accounts to rows whose dtOpen is before the
    |   supplied timestamp. Example: "2026-07-30 20:00:00".
    |
    | VIEFUND_BALANCE_REPORT_EXCLUDED_PLAN_ACCOUNTS
    |   Comma-separated DealerAccountID values to exclude from the report
    |   universe during reconciliation experiments.
    */

    'balance_report_cash_account_scope' => [
        'currency_code' => trim((string) env('VIEFUND_BALANCE_REPORT_CASH_CURRENCY_CODE', '')) ?: null,
        'opened_before' => trim((string) env('VIEFUND_BALANCE_REPORT_CASH_OPENED_BEFORE', '')) ?: null,
        'excluded_plan_accounts' => array_values(array_filter(array_map(
            static fn ($value) => trim((string) $value),
            explode(',', (string) env('VIEFUND_BALANCE_REPORT_EXCLUDED_PLAN_ACCOUNTS', ''))
        ))),
    ],

];
