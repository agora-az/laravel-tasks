<?php

namespace App\Http\Controllers;

use App\Services\VieFund\VieFundRemoteService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VieFundReportsController extends Controller
{
    private const DATE_BASIS_OPTIONS = [
        'create_date' => 'Created date',
        'trade_date' => 'Trade date',
        'processing_date' => 'Processing date',
        'settlement_date' => 'Settlement date',
    ];

    private const DATE_BASIS_INCEPTION_ENV_KEYS = [
        'create_date' => 'VIEFUND_REPORT_INCEPTION_CREATE_DATE',
        'trade_date' => 'VIEFUND_REPORT_INCEPTION_TRADE_DATE',
        'processing_date' => 'VIEFUND_REPORT_INCEPTION_PROCESSING_DATE',
        'settlement_date' => 'VIEFUND_REPORT_INCEPTION_SETTLEMENT_DATE',
    ];

    public function __construct(
        private readonly VieFundRemoteService $vieFundRemoteService
    ) {}

    public function index(Request $request): View
    {
        $selectedDateBasis = $request->query('date_basis', 'create_date');
        if (!isset(self::DATE_BASIS_OPTIONS[$selectedDateBasis])) {
            $selectedDateBasis = 'create_date';
        }

        $inceptionDates = [];
        foreach (array_keys(self::DATE_BASIS_OPTIONS) as $basisKey) {
            $inceptionDates[$basisKey] = $this->resolveInceptionDate($basisKey);
        }

        $defaultFrom = Carbon::today()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $defaultTo = Carbon::today()->subMonthNoOverflow()->endOfMonth()->toDateString();

        return view('reports.index', [
            'dateBasisOptions' => self::DATE_BASIS_OPTIONS,
            'selectedDateBasis' => $selectedDateBasis,
            'dateFrom' => $request->query('date_from', $defaultFrom),
            'dateTo' => $request->query('date_to', $defaultTo),
            'inceptionDates' => $inceptionDates,
        ]);
    }

    private function resolveInceptionDate(string $dateBasis): ?string
    {
        $specificEnvKey = self::DATE_BASIS_INCEPTION_ENV_KEYS[$dateBasis] ?? null;
        $configured = $specificEnvKey ? env($specificEnvKey) : null;
        if (!$configured) {
            $configured = env('VIEFUND_REPORT_INCEPTION_DATE');
        }

        if (is_string($configured) && trim($configured) !== '') {
            try {
                return Carbon::createFromFormat('Y-m-d', trim($configured))->toDateString();
            } catch (\Throwable) {
                // Ignore invalid env override and fall back to remote lookup.
            }
        }

        return $this->vieFundRemoteService->fetchInceptionDateByDateColumn($dateBasis);
    }

    public function exportDailyBalance(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'date_basis' => ['required', 'in:' . implode(',', array_keys(self::DATE_BASIS_OPTIONS))],
            'format' => ['required', 'in:csv,excel'],
        ]);

        $dateFrom = Carbon::parse($validated['date_from'])->startOfDay();
        $dateTo = Carbon::parse($validated['date_to'])->startOfDay();
        $dateBasis = $validated['date_basis'];
        $format = $validated['format'];

        $dailyTotals = $this->vieFundRemoteService->fetchDailyNetTotalsByDateColumn($dateFrom, $dateTo, $dateBasis);

        $byDate = $dailyTotals
            ->mapWithKeys(function ($row) {
                $key = Carbon::parse($row->total_date)->toDateString();
                return [$key => [
                    'transaction_count' => (int) $row->transaction_count,
                    'net_total' => (float) $row->net_total,
                ]];
            });

        $rows = [];
        $runningBalance = 0.0;
        $cursor = $dateFrom->copy();

        while ($cursor->lte($dateTo)) {
            $dateKey = $cursor->toDateString();
            $day = $byDate->get($dateKey, [
                'transaction_count' => 0,
                'net_total' => 0.0,
            ]);

            $dailyNet = (float) $day['net_total'];
            $runningBalance += $dailyNet;

            $rows[] = [
                'report_date' => $dateKey,
                'date_basis' => self::DATE_BASIS_OPTIONS[$dateBasis],
                'transaction_count' => (int) $day['transaction_count'],
                'daily_net_transactions' => $dailyNet,
                'running_daily_balance' => $runningBalance,
            ];

            $cursor->addDay();
        }

        $safeBasis = Str::slug(str_replace('_', ' ', $dateBasis));
        $baseName = sprintf(
            'viefund-daily-balance-%s-%s-to-%s',
            $safeBasis,
            $dateFrom->toDateString(),
            $dateTo->toDateString()
        );

        if ($format === 'excel') {
            return $this->streamExcelTsv($rows, $baseName . '.xls');
        }

        return $this->streamCsv($rows, $baseName . '.csv');
    }

    private function streamCsv(array $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Report Date',
                'Date Basis',
                'Transaction Count',
                'Daily Net Transactions',
                'Running Daily Balance',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['report_date'],
                    $row['date_basis'],
                    $row['transaction_count'],
                    number_format($row['daily_net_transactions'], 2, '.', ''),
                    number_format($row['running_daily_balance'], 2, '.', ''),
                ]);
            }

            $finalBalance = !empty($rows)
                ? (float) ($rows[count($rows) - 1]['running_daily_balance'] ?? 0.0)
                : 0.0;

            fputcsv($out, []);
            fputcsv($out, ['Final Balance', '', '', '', number_format($finalBalance, 2, '.', '')]);

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function streamExcelTsv(array $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            $writeTsv = function (array $values) use ($out): void {
                $escaped = array_map(function ($value): string {
                    $text = (string) $value;
                    $text = str_replace(["\t", "\r", "\n"], ' ', $text);
                    return $text;
                }, $values);

                fwrite($out, implode("\t", $escaped) . "\r\n");
            };

            $writeTsv([
                'Report Date',
                'Date Basis',
                'Transaction Count',
                'Daily Net Transactions',
                'Running Daily Balance',
            ]);

            foreach ($rows as $row) {
                $writeTsv([
                    $row['report_date'],
                    $row['date_basis'],
                    $row['transaction_count'],
                    number_format($row['daily_net_transactions'], 2, '.', ''),
                    number_format($row['running_daily_balance'], 2, '.', ''),
                ]);
            }

            $finalBalance = !empty($rows)
                ? (float) ($rows[count($rows) - 1]['running_daily_balance'] ?? 0.0)
                : 0.0;

            $writeTsv([]);
            $writeTsv(['Final Balance', '', '', '', number_format($finalBalance, 2, '.', '')]);

            fclose($out);
        }, $filename, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
        ]);
    }
}
