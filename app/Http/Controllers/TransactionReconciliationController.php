<?php

namespace App\Http\Controllers;

use App\Services\VieFund\Repositories\SqlServerEftRemoteRepository;
use App\Services\VieFund\VieFundRemoteService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TransactionReconciliationController extends Controller
{
    private const BANK_PARSER_VERSION = 'v2';

    public function __construct(
        private readonly VieFundRemoteService $vieFundService,
        private readonly SqlServerEftRemoteRepository $eftRepository,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        $perPage = in_array((int) $request->query('per_page', 100), [50, 100, 250], true)
            ? (int) $request->query('per_page', 100)
            : 100;

        if ($request->query('jump') === 'latest_eft') {
            $location = $this->vieFundService->latestEftMatchedTransactionPage($perPage);
            if ($location === null) {
                return redirect()->route('reconciliations.transactions')
                    ->with('reconciliation_notice', 'No VieFund transaction with a related EFT item was found.');
            }

            return redirect()->to(route('reconciliations.transactions', [
                'page' => $location['page'],
                'per_page' => $perPage,
            ]) . '#transaction-' . $location['transaction_id']);
        }
        if ($request->query('jump') === 'latest_bank') {
            $sequences = DB::table('bank_statement_entry_analyses as a')
                ->join('bank_statement_entries as b', 'b.id', '=', 'a.bank_statement_entry_id')
                ->where('a.parser_version', self::BANK_PARSER_VERSION)
                ->whereNotNull('a.settlement_number')
                ->where('a.settlement_number', '<>', '')
                ->orderByDesc('b.value_date')
                ->orderByDesc('b.id')
                ->limit(500)
                ->pluck('a.settlement_number')
                ->unique()
                ->take(100)
                ->values();
            $location = $this->vieFundService->latestBankMatchedTransactionPage($sequences->all(), $perPage);
            if ($location === null) {
                return redirect()->route('reconciliations.transactions')
                    ->with('reconciliation_notice', 'No VieFund transaction with a related bank statement transaction was found.');
            }

            return redirect()->to(route('reconciliations.transactions', [
                'page' => $location['page'],
                'per_page' => $perPage,
            ]) . '#transaction-' . $location['transaction_id']);
        }
        $dateBasis = in_array($request->query('date_basis'), ['created', 'trade', 'processing', 'settlement'], true)
            ? (string) $request->query('date_basis')
            : 'created';
        $transactionStatuses = collect((array) $request->query('transaction_status', []))
            ->map(fn($status) => trim((string) $status))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $filters = array_filter([
            'trx_id' => trim((string) $request->query('transaction_id', '')),
            'created_from' => trim((string) $request->query('date_from', '')),
            'created_to' => trim((string) $request->query('date_to', '')),
            'date_basis' => $dateBasis,
            'transaction_status' => $transactionStatuses ?: null,
            'has_reconciliation_match' => $request->boolean('has_reconciliation_match') ? '1' : null,
        ]);

        $transactions = $this->vieFundService->fetchAllTransactions(
            $perPage,
            max(1, (int) $request->query('page', 1)),
            null,
            $filters,
        );

        $trustIds = $transactions->getCollection()->pluck('related_trust_id')->filter()->unique()->values();
        $eftByTrustId = $this->eftRepository->itemsByLinkedIds($trustIds->all())
            ->groupBy(fn($item) => (string) $item->linked_id);
        $sequences = $eftByTrustId->flatten()->pluck('sequence_number')->filter()->unique()->values();

        $bankBySequence = $sequences->isEmpty()
            ? collect()
            : DB::table('bank_statement_entries as b')
                ->join('bank_statement_entry_analyses as a', function ($join) {
                    $join->on('a.bank_statement_entry_id', '=', 'b.id')
                        ->where('a.parser_version', self::BANK_PARSER_VERSION);
                })
                ->whereIn('a.settlement_number', $sequences->map(fn($value) => (string) $value)->all())
                ->select([
                    'b.id',
                    'b.created_at',
                    'b.value_date',
                    'b.account_number',
                    'b.credit_debit_indicator',
                    'b.currency',
                    'b.amount',
                    'b.additional_info',
                    'a.settlement_number',
                    'a.memo_type',
                    'a.parsed_at',
                ])
                ->orderByDesc('b.value_date')
                ->orderByDesc('b.id')
                ->get()
                ->groupBy(fn($entry) => (string) $entry->settlement_number);

        $transactions->getCollection()->each(function ($transaction) use ($eftByTrustId, $bankBySequence): void {
            $transaction->eft_matches = $eftByTrustId->get((string) $transaction->related_trust_id, collect())->values();
            $transaction->bank_matches = $transaction->eft_matches
                ->flatMap(fn($eft) => $bankBySequence->get((string) $eft->sequence_number, collect()))
                ->unique('id')
                ->values();
        });

        $transactionStatusOptions = $this->vieFundService->fetchTransactionStatuses();

        return view('reconciliations.transactions', compact('transactions', 'filters', 'perPage', 'transactionStatusOptions'));
    }
}
