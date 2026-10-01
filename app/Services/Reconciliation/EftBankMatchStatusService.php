<?php

namespace App\Services\Reconciliation;

use App\Services\VieFund\Repositories\SqlServerEftRemoteRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EftBankMatchStatusService
{
    public const COMPLETE = 'Complete';
    public const TO_BE_VERIFIED = 'To be verified';
    public const POSSIBLE_MATCH = 'Possible match';
    public const UNKNOWN = 'Unknown';

    private const BANK_PARSER_VERSION = 'v2';

    /** @var array<string, string> */
    private array $statusBySequence = [];

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
                continue;
            }

            $variances = $sequenceBankTotals->map(
                fn($bankTotal) => abs((float) $bankTotal->net_total - (float) $eftTotal)
            );

            if ($variances->contains(fn(float $variance) => $variance < .01)) {
                $this->statusBySequence[$sequence] = self::COMPLETE;
                continue;
            }

            if ($variances->contains(fn(float $variance) => abs($variance - 15.0) < .01)) {
                $this->statusBySequence[$sequence] = self::TO_BE_VERIFIED;
                continue;
            }

            $this->statusBySequence[$sequence] = self::POSSIBLE_MATCH;
        }
    }
}
