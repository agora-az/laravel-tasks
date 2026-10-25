<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateVieFundCashAgchReport;
use App\Services\VieFund\VieFundCashAgchReport;
use App\Services\VieFund\VieFundAvailabilityWindow;
use App\Services\VieFund\VieFundReportCancellation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class VieFundCashAgchReportController extends Controller
{
    public function run(Request $request, VieFundAvailabilityWindow $availability): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date', 'before_or_equal:date_to'],
            'date_to' => ['required', 'date', 'before_or_equal:today'],
            'date_basis' => ['required', 'in:'.implode(',', array_keys(VieFundCashAgchReport::DATE_BASES))],
            'currency_code' => ['required', 'in:00,01'],
            'status_ids' => ['required', 'array', 'min:1'],
            'status_ids.*' => ['required', 'integer', 'between:0,6'],
        ]);
        $criteria = [
            'date_from' => !empty($validated['date_from'])
                ? \Carbon\CarbonImmutable::parse($validated['date_from'])->toDateString()
                : null,
            'date_to' => \Carbon\CarbonImmutable::parse($validated['date_to'])->toDateString(),
            'date_basis' => $validated['date_basis'],
            'currency_code' => $validated['currency_code'],
            'status_ids' => array_values(array_unique(array_map('intval', $validated['status_ids']))),
        ];
        $userId = (int) $request->user()->id;
        $runId = (string) Str::uuid();
        $key = VieFundCashAgchReport::statusKey($userId, $runId);
        Cache::put($key, [
            'run_id' => $runId,
            'user_id' => $userId,
            'cancellation_supported' => true,
            'state' => 'queued',
            'phase' => 'queued',
            'success' => null,
            'processed_transactions' => 0,
            'expected_transactions' => null,
            'eta_seconds' => null,
            'estimated_completion_at' => null,
            'message' => 'CASHagch report queued. Selecting the verified data source...',
            'criteria' => $criteria,
            'started_at' => now()->toIso8601String(),
        ], now()->addHours(12));
        try {
            $job = GenerateVieFundCashAgchReport::dispatch($userId, $runId, $criteria)
                ->onConnection('database_reports')->onQueue('cashagch-reports');
            unset($job);
        } catch (\Throwable $exception) {
            Cache::put($key, ['state' => 'failed', 'success' => false, 'message' => 'Report could not be queued.'], now()->addHours(12));
            throw $exception;
        }

        return response()->json([
            'run_id' => $runId,
            'status_url' => route('reports.cashagch.status', ['runId' => $runId]),
        ], 202);
    }

    public function status(Request $request, string $runId): JsonResponse
    {
        $status = Cache::get(VieFundCashAgchReport::statusKey((int) $request->user()->id, $runId));
        abort_unless(is_array($status), 404, 'This report run was not found or has expired.');
        $status['download_url'] = ($status['success'] ?? null) === true
            ? route('reports.cashagch.download', ['runId' => $runId])
            : null;
        $status = VieFundReportCancellation::decorate($status, 'cashagch', (int) $request->user()->id, $runId);

        return response()->json($status)->withHeaders(['Cache-Control' => 'no-store, no-cache, must-revalidate']);
    }

    public function download(Request $request, string $runId): BinaryFileResponse
    {
        $userId = (int) $request->user()->id;
        $status = Cache::get(VieFundCashAgchReport::statusKey($userId, $runId));
        abort_unless(($status['success'] ?? null) === true, 404, 'No completed report is available.');
        $path = VieFundCashAgchReport::outputPath($userId, $runId);
        abort_unless(is_file($path), 404, 'The report file is no longer available.');

        $from = $status['criteria']['date_from'] ?? null;
        $period = $from !== null ? $from.'-to-' : 'since-inception-';

        return response()->download($path, 'CASHagch-'.$period.$status['criteria']['date_to'].'.xlsx');
    }
}
