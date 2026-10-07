<?php

namespace App\Services\Reconciliation;

use App\Models\SettlementInstruction;
use App\Services\VieFund\Repositories\SqlServerEftRemoteRepository;
use App\Services\VieFund\VieFundExportLinkCache;
use App\Services\VieFund\VieFundRemoteService;
use Illuminate\Support\Collection;

class AllTransactionMatchStatusResolver
{
    public function __construct(
        private readonly VieFundExportLinkCache $linkCache,
        private readonly SqlServerEftRemoteRepository $eftRepository,
        private readonly VieFundRemoteService $remoteService,
        private readonly EftBankMatchStatusService $eftBankMatchStatusService,
        private readonly FspMatchedRecordCollapser $fspRecordCollapser,
        private readonly AgraFspBankMatcher $agraFspBankMatcher,
        private readonly TransactionBankMatchStatusService $transactionBankMatchStatusService
    ) {}

    /** @return Collection<string, array<string, mixed>> */
    public function resolve(Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }

        $trustIds = $rows->pluck('trust_transaction_id')->filter()->map(fn($id) => (int) $id)->unique()->values();
        $eftItems = $this->linkCache->eftItemsByLinkedIds($trustIds->all(), $this->eftRepository);
        $eftStatusesBySequence = $this->eftBankMatchStatusService->statusesForItems($eftItems);
        $eftItems->each(function ($item) use ($eftStatusesBySequence): void {
            $sequence = $item->sequence_number !== null && ctype_digit((string) $item->sequence_number)
                ? (string) (int) $item->sequence_number
                : null;
            $item->bank_match_status = $sequence !== null
                ? $eftStatusesBySequence->get($sequence, EftBankMatchStatusService::UNKNOWN)
                : EftBankMatchStatusService::UNKNOWN;
        });
        $eftItemsByTrust = $eftItems->groupBy(fn($item) => (string) (int) $item->linked_id);
        $eftBankEntriesBySequence = $this->eftBankMatchStatusService->bankEntriesForSequences(
            $eftItems->pluck('sequence_number')
        );

        $cashIds = $rows->pluck('cash_transaction_id')->filter()->map(fn($id) => (int) $id)->unique()->values();
        $sourceMappings = $this->linkCache->fundSourceIdsForCashTransactions($cashIds->all(), $this->remoteService);
        $sourceIds = $sourceMappings->pluck('source_id')->map(fn($id) => trim((string) $id))->filter()->unique()->values();
        $fspItems = $sourceIds->isEmpty()
            ? collect()
            : $this->fspRecordCollapser->withFileNetTotals(
                $this->fspRecordCollapser->collapse(SettlementInstruction::query()
                    ->whereIn('source_type', ['fundserv_agra', 'ltm'])
                    ->whereIn('source_id', $sourceIds->all())
                    ->get(FspMatchedRecordCollapser::queryColumns()))
            );
        $bankMatchesBySourceType = collect(['fundserv_agra', 'ltm'])->mapWithKeys(fn(string $sourceType) => [
            $sourceType => $this->agraFspBankMatcher->matchesForItems(
                $fspItems->where('source_type', $sourceType)->values(),
                $sourceType
            ),
        ]);
        $fspItems->each(function (SettlementInstruction $item) use ($bankMatchesBySourceType): void {
            $entry = null;
            if (
                $item->settlement_date
                && $item->currency
                && ($item->source_type !== 'fundserv_agra'
                    || strtoupper(trim((string) $item->settlement_source)) === 'I')
            ) {
                $entry = $bankMatchesBySourceType->get($item->source_type, collect())->get(
                    $this->agraFspBankMatcher->key($item->settlement_date, $item->currency)
                );
            }
            $item->bank_match_status = $this->transactionBankMatchStatusService->statusForFspBankEntry($entry);
        });
        $fspItemsBySourceId = $fspItems->groupBy(fn($item) => trim((string) $item->source_id));
        $sourceIdsByCash = $sourceMappings
            ->groupBy(fn($mapping) => (string) (int) $mapping->cash_transaction_id)
            ->map(fn(Collection $mappings) => $mappings->pluck('source_id')->map(fn($id) => trim((string) $id))->filter()->unique()->values());

        return $rows->mapWithKeys(function ($row) use (
            $eftItemsByTrust,
            $eftBankEntriesBySequence,
            $sourceIdsByCash,
            $fspItemsBySourceId,
            $bankMatchesBySourceType
        ): array {
            $cashId = (string) (int) $row->cash_transaction_id;
            $trustId = (string) (int) ($row->trust_transaction_id ?? 0);
            $transactionEftItems = collect($trustId !== '0' ? $eftItemsByTrust->get($trustId, collect()) : collect())->values();
            $eftStatus = $transactionEftItems->isNotEmpty()
                ? $this->transactionBankMatchStatusService->summarizeEftItems($transactionEftItems)
                : null;
            $eftBankEntries = $transactionEftItems
                ->pluck('sequence_number')
                ->filter(fn($sequence) => $sequence !== null && ctype_digit((string) $sequence))
                ->map(fn($sequence) => (string) (int) $sequence)
                ->unique()
                ->flatMap(fn(string $sequence) => $eftBankEntriesBySequence->get($sequence, collect()))
                ->unique('id')
                ->values();
            $fspItems = collect($sourceIdsByCash->get($cashId, collect()))
                ->flatMap(fn(string $sourceId) => $fspItemsBySourceId->get($sourceId, collect()))
                ->unique(fn($item) => (int) $item->id)
                ->values();
            $fspStatus = $this->transactionBankMatchStatusService->summarizeFspItems($fspItems);
            $fspBankEntries = $fspItems
                ->map(function (SettlementInstruction $item) use ($bankMatchesBySourceType) {
                    if (
                        !$item->settlement_date
                        || !$item->currency
                        || ($item->source_type === 'fundserv_agra'
                            && strtoupper(trim((string) $item->settlement_source)) !== 'I')
                    ) {
                        return null;
                    }

                    return $bankMatchesBySourceType->get($item->source_type, collect())->get(
                        $this->agraFspBankMatcher->key($item->settlement_date, $item->currency)
                    );
                })
                ->filter()
                ->unique('id')
                ->values();

            return [$cashId => [
                'match_status' => $this->transactionBankMatchStatusService->combine($eftStatus, $fspStatus),
                'has_eft_match' => $eftStatus !== null,
                'has_agra_fsp_match' => $fspItems->contains(fn($item) => $item->source_type === 'fundserv_agra'),
                'has_7960_fsp_match' => $fspItems->contains(fn($item) => $item->source_type === 'ltm'),
                'enrichment' => [
                    'eft_items' => $this->snapshotRecords($transactionEftItems),
                    'eft_bank_entries' => $this->snapshotRecords($eftBankEntries),
                    'fsp_items' => $this->snapshotRecords($fspItems),
                    'fsp_bank_entries' => $this->snapshotRecords($fspBankEntries),
                ],
            ]];
        });
    }

    private function snapshotRecords(Collection $records): array
    {
        return json_decode($records->values()->toJson(JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}
