<?php

namespace App\Http\Controllers;

use App\Services\VieFund\VieFundCashAgchReport;
use App\Services\VieFund\VieFundReportCancellation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class VieFundReportCancellationController extends Controller
{
    public function __invoke(Request $request, string $report): JsonResponse
    {
        $validated = $request->validate(['run_id' => ['required', 'uuid']]);
        abort_unless(in_array($report, VieFundReportCancellation::TYPES, true), 404);
        $runId = $validated['run_id'];
        $userId = (int) $request->user()->id;
        $marker = VieFundReportCancellation::path($report, $userId, $runId);

        return VieFundReportCancellation::locked($marker, function () use ($report, $runId, $userId, $marker): JsonResponse {
            $key = VieFundCashAgchReport::statusKey($userId, $runId);
            if ($report === 'cashagch') {
                $status = Cache::get($key);
            } else {
                $path = match ($report) {
                    'daily-balance' => storage_path("app/reports/viefund-daily-balance-{$userId}-{$runId}-status.json"),
                    'customer-balances' => storage_path("app/reports/viefund-customer-balances-{$userId}-{$runId}-status.json"),
                    'daily-balance-legacy' => storage_path('app/reports/viefund-daily-balance-legacy-status.json'),
                };
                $status = is_file($path) ? json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR) : null;
            }
            abort_unless(is_array($status)
                && ($status['run_id'] ?? null) === $runId
                && (int) ($status['user_id'] ?? 0) === $userId
                && !empty($status['cancellation_supported']), 404, 'This report run was not found or cannot be cancelled.');
            if (($status['cancelled'] ?? false) === true) {
                return response()->json(['cancelled' => true, 'message' => $status['message']]);
            }
            $active = ($status['inProgress'] ?? false) === true
                || in_array($status['state'] ?? null, ['queued', 'running', 'cancelling'], true);
            abort_unless($active && ($status['success'] ?? null) === null, 409, 'This report has already finished.');
            VieFundReportCancellation::request($marker);
            $queued = $report === 'cashagch' && ($status['state'] ?? null) === 'queued';
            if ($queued) {
                Cache::put($key, array_merge($status, VieFundReportCancellation::status()), now()->addHours(12));
            }

            return response()->json([
                'cancelled' => $queued,
                'message' => $queued ? 'Queued report cancelled.' : 'Cancellation requested. Waiting for the current database operation to finish...',
            ], $queued ? 200 : 202);
        });
    }
}
