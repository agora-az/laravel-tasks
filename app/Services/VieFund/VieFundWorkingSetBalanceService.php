<?php

namespace App\Services\VieFund;

use App\Models\VieFundTransactionWorkingSet;

class VieFundWorkingSetBalanceService
{
    public function __construct(private readonly VieFundDailyBalanceService $dailyBalanceService)
    {
    }

    public function get(VieFundTransactionWorkingSet $workingSet): array
    {
        $generation = $workingSet->readableGeneration();
        if ($generation === null) {
            throw new \LogicException('A readable working-set generation is required for balance planning.');
        }

        if ($workingSet->balance_generation === $generation && is_array($workingSet->balance_report)) {
            return $workingSet->balance_report;
        }

        $report = $this->build($workingSet);
        VieFundTransactionWorkingSet::query()
            ->whereKey($workingSet->id)
            ->where('active_generation', $generation)
            ->update([
                'balance_generation' => $generation,
                'balance_report' => json_encode($report, JSON_THROW_ON_ERROR),
                'balance_calculated_at' => now(),
            ]);
        $workingSet->forceFill([
            'balance_generation' => $generation,
            'balance_report' => $report,
            'balance_calculated_at' => now(),
        ]);

        return $report;
    }

    public function build(VieFundTransactionWorkingSet $workingSet): array
    {
        $report = $this->dailyBalanceService->build(
            $workingSet->date_from->copy()->startOfDay(),
            $workingSet->date_to->copy()->startOfDay(),
            $workingSet->date_basis,
            $workingSet->currency_code,
            (array) $workingSet->status_ids,
            null,
            'asc'
        );

        return json_decode(json_encode($report, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}