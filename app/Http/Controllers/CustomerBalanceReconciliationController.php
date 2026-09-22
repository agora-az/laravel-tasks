<?php

namespace App\Http\Controllers;

use App\Services\VieFund\VieFundRemoteService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerBalanceReconciliationController extends Controller
{
    private const DATE_BASIS_OPTIONS = [
        'create_date' => 'Created date',
        'trade_date' => 'Trade date',
        'processing_date' => 'Processing date',
        'settlement_date' => 'Settlement date',
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
        private readonly VieFundRemoteService $vieFundRemoteService,
    ) {}

    public function index(Request $request): View
    {
        $statuses = array_values(array_unique(array_map('intval', (array) $request->query('status'))));
        $statuses = array_values(array_filter($statuses, fn(int $id) => array_key_exists($id, self::STATUS_OPTIONS)));
        if ($statuses === []) {
            $statuses = (array) config('viefund.default_fund_status', [6]);
        }

        $filters = validator([
            'report_date' => $request->query('report_date', Carbon::today()->toDateString()),
            'date_basis' => $request->query('date_basis', 'settlement_date'),
            'currency' => $request->query('currency', 'CAD'),
            'opened_before' => trim((string) $request->query('opened_before', '')) ?: null,
            'status' => $statuses,
        ], [
            'report_date' => ['required', 'date', 'before_or_equal:today'],
            'date_basis' => ['required', 'in:' . implode(',', array_keys(self::DATE_BASIS_OPTIONS))],
            'currency' => ['required', 'in:CAD,USD'],
            'opened_before' => ['nullable', 'date', 'before_or_equal:now'],
            'status' => ['required', 'array'],
            'status.*' => ['integer', 'between:0,6'],
        ])->validate();

        $perPage = in_array((int) $request->query('per_page', 100), [50, 100, 250], true)
            ? (int) $request->query('per_page', 100)
            : 100;
        $page = max(1, (int) $request->query('page', 1));
        $openedBefore = $filters['opened_before']
            ? Carbon::parse($filters['opened_before'], config('viefund.simulated_report_timezone', 'America/Toronto'))
                ->format('Y-m-d H:i:s')
            : null;
        $currencyCode = $filters['currency'] === 'USD' ? '01' : '00';

        config([
            'viefund.balance_report_cash_account_scope.currency_code' => $currencyCode,
            'viefund.balance_report_cash_account_scope.opened_before' => $openedBefore,
        ]);

        $result = $this->vieFundRemoteService->fetchCustomerBalancesPageByDate(
            Carbon::parse($filters['report_date'])->startOfDay(),
            $filters['date_basis'],
            ['status_ids' => $filters['status']],
            $perPage,
            $page,
        );
        $balances = $result['items']->withQueryString();

        return view('reconciliations.customer-balances', [
            'balances' => $balances,
            'summary' => $result['summary'],
            'filters' => $filters,
            'perPage' => $perPage,
            'dateBasisOptions' => self::DATE_BASIS_OPTIONS,
            'statusOptions' => self::STATUS_OPTIONS,
        ]);
    }
}
