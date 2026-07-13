<?php

namespace App\Http\Controllers;

use App\Models\BankStatementEntry;
use App\Models\VieFundDailyTotal;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DailyTotalsComparisonController extends Controller
{
    public function index(Request $request): View
    {
        $earliestBankDate = BankStatementEntry::min('value_date');
        $defaultStart = $earliestBankDate
            ? Carbon::parse($earliestBankDate)->toDateString()
            : Carbon::today()->toDateString();

        $dateFrom = $request->filled('date_from') ? Carbon::parse($request->date_from)->toDateString() : $defaultStart;
        $dateTo = $request->filled('date_to') ? Carbon::parse($request->date_to)->toDateString() : Carbon::today()->toDateString();
        $showZeroDays = $request->boolean('show_zero_days');
        $sortField = in_array($request->query('sort'), ['total_date', 'bank_net_total', 'viefund_net_total', 'variance', 'discrepancy_pct'], true)
            ? $request->query('sort')
            : 'total_date';
        $sortDir = $request->query('sort_dir') === 'asc' ? 'asc' : 'desc';

        $bankRows = DB::table('bank_statement_entries')
            ->whereBetween('value_date', [$dateFrom, $dateTo])
            ->selectRaw("value_date as total_date, COUNT(*) as transaction_count, SUM(CASE WHEN credit_debit_indicator = 'DBIT' THEN -amount ELSE amount END) as net_total")
            ->groupBy('value_date')
            ->orderBy('value_date')
            ->get();

        $viefundRows = VieFundDailyTotal::query()
            ->whereBetween('total_date', [$dateFrom, $dateTo])
            ->orderBy('total_date')
            ->get();

        $byDate = [];
        foreach ($bankRows as $row) {
            $dateKey = Carbon::parse($row->total_date)->toDateString();

            $byDate[$dateKey] = [
                'total_date' => $dateKey,
                'bank_transaction_count' => (int) $row->transaction_count,
                'bank_net_total' => (float) $row->net_total,
                'viefund_transaction_count' => 0,
                'viefund_net_total' => 0.0,
            ];
        }

        foreach ($viefundRows as $row) {
            $dateKey = Carbon::parse($row->total_date)->toDateString();

            $byDate[$dateKey] = array_merge($byDate[$dateKey] ?? [
                'total_date' => $dateKey,
                'bank_transaction_count' => 0,
                'bank_net_total' => 0.0,
            ], [
                'viefund_transaction_count' => (int) $row->transaction_count,
                'viefund_net_total' => (float) $row->net_total,
            ]);
        }

        $rows = collect(array_values($byDate))->map(function (array $row) {
            $row['variance'] = $row['bank_net_total'] - $row['viefund_net_total'];
            $bankAbs = abs($row['bank_net_total']);
            $row['discrepancy_pct'] = $bankAbs < 0.0001
                ? null
                : (abs($row['variance']) / $bankAbs) * 100;
            $row['status'] = abs($row['variance']) < 0.01 ? 'match' : ($row['variance'] > 0 ? 'bank-higher' : 'viefund-higher');
            return $row;
        });

        if ($sortField === 'discrepancy_pct') {
            $rows = $sortDir === 'asc'
                ? $rows->sortBy(fn(array $row) => $row['discrepancy_pct'] ?? INF)->values()
                : $rows->sortByDesc(fn(array $row) => $row['discrepancy_pct'] ?? -INF)->values();
        } else {
            $rows = $sortDir === 'asc'
                ? $rows->sortBy($sortField)->values()
                : $rows->sortByDesc($sortField)->values();
        }

        // Default behavior: hide days where both sources net to zero.
        if (!$showZeroDays) {
            $rows = $rows->filter(function (array $row) {
                return !(abs($row['bank_net_total']) < 0.0001 && abs($row['viefund_net_total']) < 0.0001);
            })->values();
        }

        $summary = [
            'days' => $rows->count(),
            'bank_total' => $rows->sum('bank_net_total'),
            'viefund_total' => $rows->sum('viefund_net_total'),
            'variance_total' => $rows->sum('variance'),
            'mismatch_days' => $rows->where('status', '!=', 'match')->count(),
        ];

        return view('reconciliations.daily-totals', compact('rows', 'summary', 'dateFrom', 'dateTo', 'sortField', 'sortDir', 'showZeroDays'));
    }
}
