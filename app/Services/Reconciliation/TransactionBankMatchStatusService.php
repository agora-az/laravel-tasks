<?php

namespace App\Services\Reconciliation;

use Illuminate\Support\Collection;

class TransactionBankMatchStatusService
{
    public function summarizeEftItems(Collection $items): ?string
    {
        if ($items->isEmpty()) {
            return null;
        }

        return $this->summarize($items->pluck('bank_match_status'));
    }

    public function summarizeFspItems(Collection $items): ?string
    {
        if ($items->isEmpty()) {
            return null;
        }

        return $this->summarize($items->pluck('bank_match_status'));
    }

    public function statusForFspBankEntry(?object $entry): string
    {
        if (!$entry) {
            return EftBankMatchStatusService::UNKNOWN;
        }
        if ((bool) ($entry->is_exact_match ?? false)) {
            return EftBankMatchStatusService::COMPLETE;
        }
        if ((bool) ($entry->is_possible_wire_fee_match ?? false)) {
            return EftBankMatchStatusService::TO_BE_VERIFIED;
        }

        return EftBankMatchStatusService::POSSIBLE_MATCH;
    }

    public function combine(?string $eftStatus, ?string $fspStatus): string
    {
        // A transaction linked to both channels is an exception that should be
        // reviewed rather than silently choosing one reconciliation result.
        if ($eftStatus !== null && $fspStatus !== null) {
            return EftBankMatchStatusService::TO_BE_VERIFIED;
        }

        return $eftStatus ?? $fspStatus ?? EftBankMatchStatusService::UNKNOWN;
    }

    private function summarize(Collection $statuses): string
    {
        $statuses = $statuses
            ->map(fn($status) => trim((string) $status))
            ->filter()
            ->values();

        if ($statuses->isEmpty()) {
            return EftBankMatchStatusService::UNKNOWN;
        }
        if ($statuses->every(fn(string $status) => $status === EftBankMatchStatusService::COMPLETE)) {
            return EftBankMatchStatusService::COMPLETE;
        }
        if ($statuses->contains(EftBankMatchStatusService::COMPLETE)) {
            return EftBankMatchStatusService::TO_BE_VERIFIED;
        }
        if ($statuses->contains(EftBankMatchStatusService::TO_BE_VERIFIED)) {
            return EftBankMatchStatusService::TO_BE_VERIFIED;
        }
        if ($statuses->contains(EftBankMatchStatusService::POSSIBLE_MATCH)) {
            return EftBankMatchStatusService::POSSIBLE_MATCH;
        }

        return EftBankMatchStatusService::UNKNOWN;
    }
}
