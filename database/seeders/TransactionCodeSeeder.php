<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TransactionCode;

class TransactionCodeSeeder extends Seeder
{
    public function run(): void
    {
        $codes = [
            ['alpha_id' => 'ADJST', 'name' => 'Adjustment'],
            ['alpha_id' => 'PC-RIF', 'name' => 'Paid to Client RIF'],
            ['alpha_id' => 'PC-SWP', 'name' => 'Paid to Client SWP'],
            ['alpha_id' => 'PC', 'name' => 'Paid to Client'],
            ['alpha_id' => 'IPC-CR', 'name' => 'Internal paid to client (change of registration)'],
            ['alpha_id' => 'ESTATE', 'name' => 'Estate Payment'],
            ['alpha_id' => 'TRS-FEE', 'name' => 'Trustee Fee'],
            ['alpha_id' => 'ADV-FEE', 'name' => 'Advisor Fee'],
            ['alpha_id' => 'FND-REDM', 'name' => 'Fund redemption'],
            ['alpha_id' => 'TAX', 'name' => 'Tax'],
            ['alpha_id' => 'EFT-DEP', 'name' => 'EFT Deposit'],
            ['alpha_id' => 'EFT-FEE', 'name' => 'EFT Fee'],
            ['alpha_id' => 'FEE-ADV', 'name' => 'Fee paid by advisor'],
            ['alpha_id' => 'DEP-FEE', 'name' => 'Deposit for Fee'],
            ['alpha_id' => 'OTH-FEE', 'name' => 'Other Fee'],
            ['alpha_id' => 'PRT-FEE', 'name' => 'Portfolio Fee'],
            ['alpha_id' => 'PROV-WHT', 'name' => 'Provincial Withholding Tax'],
            ['alpha_id' => 'WHT', 'name' => 'Withholding Tax'],
            ['alpha_id' => 'PAC-DEP', 'name' => 'PAC Deposit'],
            ['alpha_id' => 'OTHER', 'name' => 'Other'],
            ['alpha_id' => 'FFS-OCT', 'name' => 'FFS - Oct2025'],
            ['alpha_id' => 'IT-OUT', 'name' => 'Internal transfer out (same registration)'],
            ['alpha_id' => 'ET-OUT', 'name' => 'External transfer out'],
            ['alpha_id' => 'TRNSOUT', 'name' => 'transfer out'],
            ['alpha_id' => 'ET-OUTCR', 'name' => 'External transfer out (change of registration)'],
            ['alpha_id' => 'TRNSFEE', 'name' => 'Transfer Fee'],
            ['alpha_id' => 'TR-REBTE', 'name' => 'Transfer fee rebate'],
            ['alpha_id' => 'GIC-MAT', 'name' => 'GIC Maturity Payment'],
            ['alpha_id' => 'INTEREST', 'name' => 'Interest'],
            ['alpha_id' => 'RRIF-RED', 'name' => 'RRIF Redemption transfer'],
            ['alpha_id' => 'BCTESG', 'name' => 'BCTESG Grant'],
            ['alpha_id' => 'CLB', 'name' => 'CLB Grant'],
            ['alpha_id' => 'RESP-EAP', 'name' => 'RESP Payment - EAP'],
            ['alpha_id' => 'RESP-PSE', 'name' => 'RESP Payment - PSE'],
            ['alpha_id' => 'CESP', 'name' => 'CESP Grant'],
            ['alpha_id' => 'RESP-REP', 'name' => 'RESP Repayment'],
            ['alpha_id' => 'RESP-OUT', 'name' => 'RESP Transfer-Out'],
            ['alpha_id' => 'RESP-IN', 'name' => 'RESP Trasfer -In'],
            ['alpha_id' => 'PROD-UNW', 'name' => 'Product Unwinding'],
            ['alpha_id' => 'ETF-SELL', 'name' => 'ETF Sell'],
            ['alpha_id' => 'ETF-FEE', 'name' => 'ETF-Fee'],
            ['alpha_id' => 'REBAL', 'name' => 'Rebalancing Sell'],
            ['alpha_id' => 'FND-PUR', 'name' => 'Fund Purchase'],
            ['alpha_id' => 'CASH-DST', 'name' => 'Cash Distribution'],
            ['alpha_id' => 'SELL-CNV', 'name' => 'Sell Conversion'],
            ['alpha_id' => 'RIF-SELL', 'name' => 'RIF Sell of fund'],
            ['alpha_id' => 'AWD-SELL', 'name' => 'AWD Sell of fund'],
            ['alpha_id' => 'SELL-FND', 'name' => 'Sell of fund'],
            ['alpha_id' => 'SELL-FEE', 'name' => 'Sell of fund for Fee'],
            ['alpha_id' => 'DEPOSIT', 'name' => 'Deposit'],
        ];

        foreach ($codes as $code) {
            TransactionCode::create($code);
        }
    }
}
