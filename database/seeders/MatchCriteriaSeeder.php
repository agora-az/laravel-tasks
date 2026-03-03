<?php

namespace Database\Seeders;

use App\Models\MatchCriteria;
use Illuminate\Database\Seeder;

class MatchCriteriaSeeder extends Seeder
{
    public function run(): void
    {
        $criteria = [
            [
                'code' => 'fund_wo_order_id',
                'description' => 'Match VieFund Fund WO with Fundserv Order ID',
                'weight' => 1,
                'priority' => 1,
            ],
            [
                'code' => 'settlement_date',
                'description' => 'Match VieFund Settlement Date with Fundserv Settlement Date',
                'weight' => 1,
                'priority' => 2,
            ],
            [
                'code' => 'amount_and_type',
                'description' => 'Match VieFund Amount with Fundserv Actual Amount (accounting for transaction type counter-balance)',
                'weight' => 1,
                'priority' => 3,
            ],
            [
                'code' => 'fund_code_and_fund_id',
                'description' => 'Match VieFund Fund Code with Fundserv Code + Fund ID concatenated',
                'weight' => 1,
                'priority' => 4,
            ],
            [
                'code' => 'source_identifier',
                'description' => 'Match VieFund Source ID with Fundserv Source Identifier',
                'weight' => 1,
                'priority' => 5,
            ],
        ];

        foreach ($criteria as $criterion) {
            MatchCriteria::updateOrCreate(
                ['code' => $criterion['code']],
                $criterion
            );
        }
    }
}
