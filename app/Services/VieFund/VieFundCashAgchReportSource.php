<?php

namespace App\Services\VieFund;

use App\Exceptions\ReportMirrorChanged;
use App\Models\VieFundTransactionMirrorSync;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class VieFundCashAgchReportSource
{
    public static function scopeHash(array $currencies, array $statuses): string
    {
        $currencies = array_map('strval', $currencies);
        $statuses = array_map('intval', $statuses);
        sort($currencies);
        sort($statuses);
        $scope = config('viefund.balance_report_cash_account_scope', []);
        $scope['excluded_plan_accounts'] = (array) ($scope['excluded_plan_accounts'] ?? []);
        sort($scope['excluded_plan_accounts']);

        return hash('sha256', json_encode(['version' => 1, $currencies, $statuses, $scope], JSON_THROW_ON_ERROR));
    }

    public function select(array $criteria): array
    {
        if (!Schema::hasTable('viefund_transaction_mirror_syncs')
            || !Schema::hasColumn('viefund_transaction_mirror_syncs', 'cashagch_verified_at')) {
            return $this->remote('The local mirror report upgrade has not been installed.');
        }
        $sync = VieFundTransactionMirrorSync::query()->find(VieFundTransactionMirrorSync::SINGLETON_ID);
        $asOf = $sync?->synced_through_at?->toIso8601String();
        $reason = match (true) {
            !$sync?->isReadable() => 'The local mirror has not completed its initial population.',
            $sync->state !== 'ready' && $sync->phase === 'cashagch_verification' && $sync->fund_ids_verified_at !== null
                => 'Fund/unit IDs are verified, but CASHagch historical coverage verification has not finished.',
            $sync->state !== 'ready' => 'The local mirror is being synchronized or is not ready.',
            !empty(config('viefund.balance_report_cash_account_scope.opened_before'))
                => 'The mirror cash-account cutoff cannot guarantee full CASHagch history.',
            $sync->fund_ids_verified_at === null => 'Mirror fund/unit IDs have not been verified.',
            VieFundTransactionMirrorManager::pendingFundMetadata(1)->exists() => 'Mirror fund and CASHagch transaction-type metadata still needs verification.',
            $sync->cashagch_verified_at === null => $sync->cashagch_verification_error
                ?: 'CASHagch metadata and historical coverage need verification. Run the mirror fund metadata upgrade.',
            $sync->cashagch_scope_hash !== self::scopeHash((array) $sync->currencies, (array) $sync->status_ids)
                => 'The mirror accounting scope has changed since CASHagch verification.',
            !in_array($criteria['currency_code'], (array) $sync->currencies, true)
                => 'The selected currency is not covered by the mirror.',
            array_diff($criteria['status_ids'], (array) $sync->status_ids) !== []
                => 'The selected cash statuses are not covered by the mirror.',
            $sync->synced_through_at === null || $sync->synced_through_at->lt(now()->subHours(24))
                || $sync->synced_through_at->gt(now())
                => 'The mirror has no verified data sync within the last 24 hours.',
            default => null,
        };
        if ($reason !== null) {
            return $this->remote($reason, $asOf);
        }

        return [
            'data_source' => 'mirror', 'source_label' => 'Verified local VieFund mirror',
            'mirror_synced_at' => $asOf, 'source_fallback_reason' => null,
            'mirror_generation' => $sync->sync_generation,
            'mirror_verified_at' => $sync->cashagch_verified_at->toIso8601String(),
            'mirror_scope_hash' => $sync->cashagch_scope_hash,
        ];
    }

    public function remote(string $reason, ?string $asOf = null): array
    {
        return [
            'data_source' => 'viefund', 'source_label' => 'Direct VieFund',
            'mirror_synced_at' => $asOf, 'source_fallback_reason' => $reason,
        ];
    }

    public function snapshot(array $source, callable $read): mixed
    {
        return DB::transaction(function () use ($source, $read): mixed {
            $sync = VieFundTransactionMirrorSync::query()->sharedLock()->find(VieFundTransactionMirrorSync::SINGLETON_ID);
            if (!$sync?->isReady() || !$sync->isReadable() || $sync->fund_ids_verified_at === null
                || $sync->sync_generation !== $source['mirror_generation']
                || $sync->synced_through_at?->toIso8601String() !== $source['mirror_synced_at']
                || $sync->cashagch_verified_at?->toIso8601String() !== $source['mirror_verified_at']
                || $sync->cashagch_scope_hash !== $source['mirror_scope_hash']
                || self::scopeHash((array) $sync->currencies, (array) $sync->status_ids) !== $source['mirror_scope_hash']) {
                throw new ReportMirrorChanged('The mirror changed while the report was being read.');
            }

            return $read();
        });
    }

    public function count(array $criteria): int
    {
        return $this->query($criteria)->count();
    }

    public function firstDate(array $criteria): ?string
    {
        $date = $this->query($criteria)->min($this->dateColumn($criteria));

        return $date === null ? null : (string) $date;
    }

    public function rows(array $criteria, ?array $cursor, int $limit): Collection
    {
        $date = $this->dateColumn($criteria);
        $rows = $this->query($criteria)
            ->when($cursor !== null, fn(Builder $query) => $query->where(function (Builder $after) use ($date, $cursor): void {
                $after->where($date, '>', $cursor['basis_date'])
                    ->orWhere(fn(Builder $same) => $same->where($date, $cursor['basis_date'])->where('r.cash_transaction_id', '>', $cursor['sort_id']));
            }))
            ->orderBy($date)->orderBy('r.cash_transaction_id')->limit($limit)
            ->get(['r.payload', 'r.cash_transaction_id', $date.' as basis_date']);
        $funds = DB::table('viefund_transaction_mirror_funds')
            ->where('fund_id', VieFundCashAgchReport::FUND_ID)
            ->whereIn('cash_transaction_id', $rows->pluck('cash_transaction_id'))->get()->keyBy('cash_transaction_id');

        return $rows->map(function (object $cached) use ($funds): object {
            $fund = $funds->get($cached->cash_transaction_id);
            $row = json_decode($cached->payload, false, 512, JSON_THROW_ON_ERROR);
            if (!is_object($row) || !$fund || (int) $fund->transaction_type_count < 1) {
                throw new RuntimeException('The CASHagch mirror contains unverified report metadata.');
            }
            $row->fund_id = $fund->fund_id;
            $row->fund_transaction_id = $fund->fund_transaction_id;
            $row->source_id = $fund->source_id ?? $fund->cash_source_id;
            $row->transaction_type = (int) $fund->transaction_type_count > 1
                ? 'Multiple fund transaction types' : ($fund->transaction_type ?? 'Unknown');
            $row->basis_date = $cached->basis_date;
            $row->sort_id = (int) $cached->cash_transaction_id;
            return $row;
        });
    }

    private function query(array $criteria): Builder
    {
        $date = $this->dateColumn($criteria);
        $end = CarbonImmutable::parse($criteria['date_to'])->addDay()->startOfDay()->toDateTimeString();
        $availability = CarbonImmutable::parse($criteria['availability_date_to'] ?? $criteria['date_to'])
            ->addDay()->startOfDay()->toDateTimeString();
        return DB::table('viefund_transaction_mirror_rows as r')
            ->whereExists(fn(Builder $fund) => $fund->selectRaw('1')->from('viefund_transaction_mirror_funds as f')
                ->whereColumn('f.cash_transaction_id', 'r.cash_transaction_id')->where('f.fund_id', VieFundCashAgchReport::FUND_ID))
            ->where('r.currency_code', $criteria['currency_code'])->whereIn('r.cash_status_id', $criteria['status_ids'])
            ->where($date, '>=', ($criteria['date_from'] ?? '1900-01-01').' 00:00:00')
            ->where($date, '<', $end)
            ->when($criteria['date_basis'] === 'trade_date', fn(Builder $query) => $query->where(function (Builder $available) use ($availability): void {
                $available->whereNull('r.settlement_date')->orWhere('r.settlement_date', '<', $availability)
                    ->orWhere('r.amount', '<=', 0)->orWhereNull('r.trust_transaction_id')->orWhereNull('r.trust_status')
                    ->orWhere('r.trust_status', '<>', 'Unsettled')
                    ->orWhere('r.trust_amount_used', '>', 0)
                    ->orWhereRaw('COALESCE(r.trust_amount_left, 0) <= 0');
            }));
    }

    private function dateColumn(array $criteria): string
    {
        return 'r.'.match ($criteria['date_basis']) {
            'create_date' => 'created_date', 'trade_date' => 'trade_date',
            'processing_date' => 'processing_date', 'settlement_date' => 'settlement_date',
            default => throw new RuntimeException('Invalid CASHagch mirror date basis.'),
        };
    }
}
