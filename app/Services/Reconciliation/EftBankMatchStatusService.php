<?php

namespace App\Services\Reconciliation;

use App\Services\VieFund\Repositories\SqlServerEftRemoteRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EftBankMatchStatusService
{
    public const COMPLETE = 'Complete';
    public const TO_BE_VERIFIED = 'Verify';
    public const POSSIBLE_MATCH = 'Possible';
    public const UNKNOWN = 'Unknown';

    private const BANK_PARSER_VERSION = 'v2';

    /** @var array<string, string> */
    private array $statusBySequence = [];

    /** @var array<string, object|null> */
    private array $comparisonBySequence = [];

    public function __construct(
        private readonly SqlServerEftRemoteRepository $eftRepository
    ) {}

    /**
     * @return Collection<string, string>
     */
    public function statusesForItems(Collection $items): Collection
    {
        return $this->statusesForSequences($items->pluck('sequence_number'));
    }

    /**
     * @return Collection<string, string>
     */
    public function statusesForSequences(iterable $sequences): Collection
    {
        $requested = collect($sequences)
            ->map(fn($sequence) => trim((string) $sequence))
            ->filter(fn(string $sequence) => $sequence !== '' && ctype_digit($sequence))
            ->map(fn(string $sequence) => (string) (int) $sequence)
            ->unique()
            ->values();

        $missing = $requested
            ->reject(fn(string $sequence) => array_key_exists($sequence, $this->statusBySequence))
            ->values();

        if ($missing->isNotEmpty()) {
            $this->loadStatuses($missing);
        }

        return $requested->mapWithKeys(fn(string $sequence) => [
            $sequence => $this->statusBySequence[$sequence] ?? self::UNKNOWN,
        ]);
    }

    public function annotateBankEntries(Collection $entries): Collection
    {
        $sequences = $entries
            ->pluck('settlement_number')
            ->filter(fn($sequence) => $sequence !== null && ctype_digit(trim((string) $sequence)))
            ->map(fn($sequence) => (string) (int) trim((string) $sequence))
            ->unique()
            ->values();
        $this->statusesForSequences($sequences);

        return $entries->each(function (object $entry): void {
            $rawSequence = trim((string) ($entry->settlement_number ?? ''));
            $sequence = ctype_digit($rawSequence) ? (string) (int) $rawSequence : $rawSequence;
            $comparison = $this->comparisonBySequence[$sequence] ?? null;
            if (
                !$comparison
                || strtoupper(trim((string) ($entry->currency ?? ''))) !== $comparison->currency
            ) {
                return;
            }

            $entry->variance = $comparison->variance;
            $entry->reconciliation_note = $comparison->note;
            $entry->is_possible_wire_fee_match = $comparison->is_possible_wire_fee_match;
        });
    }

    public function bankEntriesForSequences(iterable $sequences): Collection
    {
        $requested = collect($sequences)
            ->map(fn($sequence) => trim((string) $sequence))
            ->filter(fn(string $sequence) => $sequence !== '' && ctype_digit($sequence))
            ->map(fn(string $sequence) => (string) (int) $sequence)
            ->unique()
            ->values();
        if ($requested->isEmpty()) {
            return collect();
        }

        $entries = $requested
            ->chunk(500)
            ->flatMap(fn(Collection $chunk) => DB::table('bank_statement_entries as b')
                ->join('bank_statement_entry_analyses as a', function ($join) {
                    $join->on('a.bank_statement_entry_id', '=', 'b.id')
                        ->where('a.parser_version', self::BANK_PARSER_VERSION);
                })
                ->whereIn('a.settlement_number', $chunk->all())
                ->select([
                    'b.id',
                    'b.value_date',
                    'b.credit_debit_indicator',
                    'b.amount',
                    'b.currency',
                    'b.account_number',
                    'b.additional_info',
                    'b.source_file',
                    'a.settlement_number',
                    'a.memo_type',
                    'a.counterparty',
                    'a.wire_payment_reference',
                ])
                ->orderBy('b.value_date')
                ->orderBy('b.id')
                ->get())
            ->unique('id')
            ->values();

        $this->annotateBankEntries($entries);

        return $entries->groupBy(fn($entry) => ctype_digit(trim((string) $entry->settlement_number))
            ? (string) (int) trim((string) $entry->settlement_number)
            : trim((string) $entry->settlement_number));
    }

    private function loadStatuses(Collection $sequences): void
    {
        $eftTotals = $this->eftRepository
            ->totalsBySequences($sequences->all())
            ->groupBy(fn($row) => (string) (int) $row->sequence_number)
            ->map(fn(Collection $files) => $files->sum(fn($file) => (float) $file->net_total));

        $bankTotals = DB::table('bank_statement_entries as b')
            ->join('bank_statement_entry_analyses as a', function ($join) {
                $join->on('a.bank_statement_entry_id', '=', 'b.id')
                    ->where('a.parser_version', self::BANK_PARSER_VERSION);
            })
            ->whereIn('a.settlement_number', $sequences->all())
            ->selectRaw('a.settlement_number')
            ->selectRaw("COALESCE(NULLIF(b.currency, ''), '—') as currency")
            ->selectRaw("SUM(CASE WHEN b.credit_debit_indicator = 'DBIT' THEN -b.amount ELSE b.amount END) as net_total")
            ->selectRaw('COUNT(DISTINCT b.id) as transaction_count')
            ->groupBy('a.settlement_number', 'b.currency')
            ->get()
            ->groupBy(fn($row) => ctype_digit(trim((string) $row->settlement_number))
                ? (string) (int) trim((string) $row->settlement_number)
                : trim((string) $row->settlement_number));

        foreach ($sequences as $sequence) {
            $eftTotal = $eftTotals->get($sequence);
            $sequenceBankTotals = collect($bankTotals->get($sequence, collect()));

            if ($eftTotal === null || $sequenceBankTotals->isEmpty()) {
                $this->statusBySequence[$sequence] = self::UNKNOWN;
                $this->comparisonBySequence[$sequence] = null;
                continue;
            }

            $bankTotal = $sequenceBankTotals
                ->sortBy(fn($candidate) => abs((float) $candidate->net_total - (float) $eftTotal))
                ->first();
            $variance = (float) $bankTotal->net_total - (float) $eftTotal;
            $isExactMatch = abs($variance) < .01;
            $isPossibleWireFeeMatch = abs(abs($variance) - 15.0) < .01;
            $status = $isExactMatch
                ? self::COMPLETE
                : ($isPossibleWireFeeMatch ? self::TO_BE_VERIFIED : self::POSSIBLE_MATCH);

            $this->statusBySequence[$sequence] = $status;
            $this->comparisonBySequence[$sequence] = (object) [
                'variance' => $variance,
                'currency' => strtoupper((string) $bankTotal->currency),
                'is_possible_wire_fee_match' => $isPossibleWireFeeMatch,
                'note' => $this->varianceNote(
                    $variance,
                    'EFT total',
                    'sequence '.$sequence,
                    (string) $bankTotal->currency,
                    (int) $bankTotal->transaction_count
                ),
            ];
        }
    }

    private function varianceNote(
        float $variance,
        string $comparisonLabel,
        string $reference,
        string $currency,
        int $transactionCount
    ): string {
        $context = trim($reference.' '.$currency);
        $transactionContext = $transactionCount > 1
            ? sprintf(' Candidate bank total combines %d transactions.', $transactionCount)
            : '';

        if (abs($variance) < .01) {
            return sprintf('Candidate bank transaction matches the %s for %s.%s', $comparisonLabel, $context, $transactionContext);
        }

        $difference = number_format(abs($variance), 2);
        $direction = $variance > 0 ? 'higher' : 'lower';
        $wireFeeClue = abs(abs($variance) - 15.0) < .01
            ? ' Possible $15 wire transfer fee.'
            : '';

        return sprintf(
            'Candidate bank transaction is $%s %s than the %s for %s.%s%s',
            $difference,
            $direction,
            $comparisonLabel,
            $context,
            $wireFeeClue,
            $transactionContext
        );
    }
}
