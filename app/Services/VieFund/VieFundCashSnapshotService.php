<?php

namespace App\Services\VieFund;

use App\Models\VieFundCashDailySnapshot;
use App\Models\VieFundCashSnapshotRun;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class VieFundCashSnapshotService
{
    /**
     * @return array{rows: \Illuminate\Support\Collection, opening_balance: float, ending_balance: float, changed_days: int, last_verified_at: ?string}|null
     */
    public function completeSeries(
        CarbonInterface $fromDate,
        CarbonInterface $toDate,
        string $dateBasis,
        string $currencyCode,
        array $statusIds
    ): ?array {
        $criteriaKey = VieFundCashDailySnapshot::criteriaKey($dateBasis, $currencyCode, $statusIds);
        $baseline = VieFundCashSnapshotRun::query()
            ->where('criteria_key', $criteriaKey)
            ->where('status', 'completed')
            ->whereIn('run_type', ['baseline', 'full_verification'])
            ->latest('completed_at')
            ->first();

        if (!$baseline) {
            return null;
        }

        if (!$this->hasFreshRunCoverage($criteriaKey, $fromDate, $toDate)) {
            return null;
        }

        $firstDateValue = VieFundCashDailySnapshot::where('criteria_key', $criteriaKey)->min('total_date');
        $lastDateValue = VieFundCashDailySnapshot::where('criteria_key', $criteriaKey)->max('total_date');
        if (!$firstDateValue || !$lastDateValue) {
            return null;
        }

        $firstDate = Carbon::parse($firstDateValue)->startOfDay();
        $lastDate = Carbon::parse($lastDateValue)->startOfDay();
        $reportEnd = $toDate->copy()->startOfDay();
        if ($reportEnd->gt($lastDate)) {
            return null;
        }

        if ($dateBasis === 'trade_date') {
            $latestCompletedRunEnd = VieFundCashSnapshotRun::query()
                ->where('criteria_key', $criteriaKey)
                ->where('status', 'completed')
                ->max('requested_to');

            if (!$latestCompletedRunEnd || !$reportEnd->isSameDay(Carbon::parse($latestCompletedRunEnd))) {
                return null;
            }
        }

        $coverageEnd = $reportEnd->lt($firstDate) ? $firstDate : $reportEnd;
        $expectedDays = (int) $firstDate->diffInDays($coverageEnd, true) + 1;
        $actualDays = VieFundCashDailySnapshot::query()
            ->where('criteria_key', $criteriaKey)
            ->whereBetween('total_date', [$firstDate->toDateString(), $coverageEnd->toDateString()])
            ->count();
        if ($actualDays !== $expectedDays) {
            return null;
        }

        $rangeStart = $fromDate->copy()->startOfDay();
        $rows = VieFundCashDailySnapshot::query()
            ->where('criteria_key', $criteriaKey)
            ->whereBetween('total_date', [$rangeStart->toDateString(), $reportEnd->toDateString()])
            ->orderBy('total_date')
            ->get();

        $openingBalance = (float) (VieFundCashDailySnapshot::query()
            ->where('criteria_key', $criteriaKey)
            ->where('total_date', '<', $rangeStart->toDateString())
            ->orderByDesc('total_date')
            ->value('closing_balance') ?? 0);
        $endingBalance = (float) (VieFundCashDailySnapshot::query()
            ->where('criteria_key', $criteriaKey)
            ->where('total_date', '<=', $reportEnd->toDateString())
            ->orderByDesc('total_date')
            ->value('closing_balance') ?? 0);

        return [
            'rows' => $rows,
            'opening_balance' => $openingBalance,
            'ending_balance' => $endingBalance,
            'changed_days' => $rows->where('has_unreviewed_change', true)->count(),
            'last_verified_at' => $rows->max('last_verified_at')?->toIso8601String(),
        ];
    }

    /**
     * A snapshot series may be complete but stale. Recent rolling runs are
     * expected to cover the requested range nightly; older ranges may combine
     * the weekly full verification with a recent overlapping rolling run.
     */
    private function hasFreshRunCoverage(
        string $criteriaKey,
        CarbonInterface $fromDate,
        CarbonInterface $toDate
    ): bool {
        $recentRunHours = max(1, (int) config('viefund.cash_snapshot_freshness.recent_run_hours', 36));
        $fullVerificationDays = max(1, (int) config('viefund.cash_snapshot_freshness.full_verification_days', 8));
        $rangeStart = $fromDate->copy()->startOfDay();
        $rangeEnd = $toDate->copy()->startOfDay();

        $recentRuns = VieFundCashSnapshotRun::query()
            ->where('criteria_key', $criteriaKey)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', now()->subHours($recentRunHours))
            ->whereDate('requested_to', '>=', $rangeEnd->toDateString())
            ->orderByDesc('completed_at')
            ->get();

        if ($recentRuns->contains(
            fn(VieFundCashSnapshotRun $run) => $run->requested_from->lte($rangeStart)
        )) {
            return true;
        }

        $fullRun = VieFundCashSnapshotRun::query()
            ->where('criteria_key', $criteriaKey)
            ->where('status', 'completed')
            ->whereIn('run_type', ['baseline', 'full_verification'])
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', now()->subDays($fullVerificationDays))
            ->whereDate('requested_from', '<=', $rangeStart->toDateString())
            ->orderByDesc('completed_at')
            ->first();

        if (!$fullRun) {
            return false;
        }

        $fullCoverageEnd = $fullRun->requested_to->copy()->startOfDay();
        if ($fullCoverageEnd->gte($rangeEnd)) {
            return true;
        }

        $firstDateAfterFullCoverage = $fullCoverageEnd->copy()->addDay();

        return $recentRuns->contains(
            fn(VieFundCashSnapshotRun $run) => $run->requested_from->lte($firstDateAfterFullCoverage)
        );
    }
}
