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
        ],

        'cash_trx_type' => [
            // 'Example Type',
        ],

    ],

];
