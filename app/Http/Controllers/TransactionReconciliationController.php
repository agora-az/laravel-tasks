<?php

namespace App\Http\Controllers;

use App\Services\VieFund\VieFundDailyBalanceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class TransactionReconciliationController extends Controller
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

    private const OUTPUT_ORDER_OPTIONS = [
        'asc' => 'Earliest first',
        'desc' => 'Latest first',
    ];

    private const STATUS_OPTIONS = [
        0 => 'Deleted',
        1 => 'Rejected',
        2 => 'Cancelled',
        3 => 'Pending',
        4 => 'Accepted',
        5 => 'Contracted',
        6 => 'Confirmed',
    ];

    public function __construct(
        private readonly VieFundDailyBalanceService $dailyBalanceService,
    ) {}

    public function index(Request $request): View
    {
        $defaultFrom = Carbon::today()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $defaultTo = Carbon::today()->subMonthNoOverflow()->endOfMonth()->toDateString();
        $statuses = array_values(array_unique(array_map('intval', (array) $request->query('status'))));
        $statuses = array_values(array_filter($statuses, fn(int $id) => array_key_exists($id, self::STATUS_OPTIONS)));
        if ($statuses === []) {
            $statuses = (array) config('viefund.default_fund_status', [6]);
        }

        $filters = validator([
            'date_from' => $request->query('date_from', $defaultFrom),
            'date_to' => $request->query('date_to', $defaultTo),
            'date_basis' => $request->query('date_basis', 'settlement_date'),
            'output_order' => $request->query('output_order', 'asc'),
            'currency' => $request->query('currency', 'CAD'),
            'opened_before' => trim((string) $request->query('opened_before', '')) ?: null,
            'status' => $statuses,
        ], [
            'date_from' => ['required', 'date', 'before_or_equal:today'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from', 'before_or_equal:today'],
            'date_basis' => ['required', 'in:' . implode(',', array_keys(self::DATE_BASIS_OPTIONS))],
            'output_order' => ['required', 'in:' . implode(',', array_keys(self::OUTPUT_ORDER_OPTIONS))],
            'currency' => ['required', 'in:CAD,USD'],
            'opened_before' => ['nullable', 'date', 'before_or_equal:now'],
            'status' => ['required', 'array'],
            'status.*' => ['integer', 'between:0,6'],
        ])->validate();

        $inceptionDates = [];
        foreach (array_keys(self::DATE_BASIS_OPTIONS) as $dateBasis) {
            $inceptionDates[$dateBasis] = $this->resolveInceptionDate($dateBasis);
        }

        $viewData = [
            'filters' => $filters,
            'dateBasisOptions' => self::DATE_BASIS_OPTIONS,
            'outputOrderOptions' => self::OUTPUT_ORDER_OPTIONS,
            'statusOptions' => self::STATUS_OPTIONS,
            'inceptionDates' => $inceptionDates,
        ];

        if (!$request->boolean('_results')) {
            return view('reconciliations.transactions', $viewData);
        }

        $dateFrom = Carbon::parse($filters['date_from'])->startOfDay();
        $dateTo = Carbon::parse($filters['date_to'])->startOfDay();
        $openedBefore = $filters['opened_before']
            ? Carbon::parse($filters['opened_before'], config('viefund.simulated_report_timezone', 'America/Toronto'))
                ->format('Y-m-d H:i:s')
            : null;
        $currencyCode = $filters['currency'] === 'USD' ? '01' : '00';

        $report = $this->dailyBalanceService->build(
            $dateFrom,
            $dateTo,
            $filters['date_basis'],
            $currencyCode,
            $filters['status'],
            $openedBefore,
            $filters['output_order'],
        );

        return view('reconciliations.partials.transaction-results', [
            'rows' => $report['rows'],
            'report' => $report,
            'filters' => $filters,
        ]);
    }

    private function resolveInceptionDate(string $dateBasis): ?string
    {
        $specificEnvKey = self::DATE_BASIS_INCEPTION_ENV_KEYS[$dateBasis] ?? null;
        $configured = $specificEnvKey ? env($specificEnvKey) : null;
        $configured = $configured ?: env('VIEFUND_REPORT_INCEPTION_DATE');

        if (is_string($configured) && trim($configured) !== '') {
            try {
                return Carbon::createFromFormat('Y-m-d', trim($configured))->toDateString();
            } catch (\Throwable) {
                // Fall through to the cached value refreshed by the reports workflow.
            }
        }

        return Cache::get("viefund:inception-date:{$dateBasis}");
    }
}
