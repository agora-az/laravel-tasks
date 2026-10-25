<?php

namespace App\Services\VieFund;

use App\Jobs\SyncVieFundTransactionMirror;
use App\Models\User;
use App\Models\VieFundTransactionMirrorSync;
use App\Services\RuntimeSettings;
use App\Support\ApplicationAdmin;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;

class VieFundTransactionMirrorManager
{
    public const SOURCE_CURRENT = 'current';

    public const SOURCE_ADMIN_PREVIEW = 'admin_preview';

    public const SOURCE_LOCAL = 'local';

    public function __construct(
        private readonly RuntimeSettings $runtimeSettings,
        private readonly ApplicationAdmin $applicationAdmin,
        private readonly VieFundAvailabilityWindow $availabilityWindow
    ) {}

    public function sync(): ?VieFundTransactionMirrorSync
    {
        return $this->available() ? VieFundTransactionMirrorSync::singleton()->fresh() : null;
    }

    public function startInitial(): VieFundTransactionMirrorSync
    {
        if (!$this->available()) {
            throw new InvalidArgumentException('The transaction mirror tables have not been migrated.');
        }

        $inceptionDate = $this->inceptionDate();

        $shouldDispatch = false;
        $sync = DB::transaction(function () use ($inceptionDate, &$shouldDispatch): VieFundTransactionMirrorSync {
            $sync = VieFundTransactionMirrorSync::query()
                ->lockForUpdate()
                ->find(VieFundTransactionMirrorSync::SINGLETON_ID);
            $sync ??= VieFundTransactionMirrorSync::query()->create([
                'id' => VieFundTransactionMirrorSync::SINGLETON_ID,
                'state' => 'idle',
                'mode' => 'initial',
            ]);

            if (in_array($sync->state, $this->activeStates(), true)) {
                return $sync;
            }
            if ($sync->isReadable()) {
                throw new InvalidArgumentException('The local mirror is already ready. Run an incremental sync instead.');
            }

            $this->initializeRun($sync, 'initial', $inceptionDate, now()->toDateString(), 'historical_backfill');
            $shouldDispatch = true;

            return $sync->fresh();
        });
        if ($shouldDispatch) {
            $this->dispatch($this->availabilityWindow->isBlocked()
                ? $this->availabilityWindow->nextAvailableAt()
                : null);
        }

        return $sync;
    }

    public function startIncremental(): VieFundTransactionMirrorSync
    {
        if (!$this->available()) {
            throw new InvalidArgumentException('The transaction mirror tables have not been migrated.');
        }

        $shouldDispatch = false;
        $sync = DB::transaction(function () use (&$shouldDispatch): VieFundTransactionMirrorSync {
            $sync = VieFundTransactionMirrorSync::query()
                ->lockForUpdate()
                ->find(VieFundTransactionMirrorSync::SINGLETON_ID);
            if (!$sync) {
                throw new InvalidArgumentException('Start the initial mirror population before running an incremental sync.');
            }
            if (in_array($sync->state, $this->activeStates(), true)) {
                return $sync;
            }
            if (!$sync->isReady()) {
                throw new InvalidArgumentException('Complete and verify the initial mirror population first.');
            }

            $from = now()
                ->subDays(max(1, (int) config('viefund.mirror.incremental_lookback_days', 180)) - 1)
                ->toDateString();
            $this->initializeRun($sync, 'incremental', $from, now()->toDateString(), 'daily_snapshot_sync');
            $shouldDispatch = true;

            return $sync->fresh();
        });
        if ($shouldDispatch) {
            $this->dispatch($this->availabilityWindow->isBlocked()
                ? $this->availabilityWindow->nextAvailableAt()
                : null);
        }

        return $sync;
    }

    public function pause(): VieFundTransactionMirrorSync
    {
        return DB::transaction(function (): VieFundTransactionMirrorSync {
            $sync = VieFundTransactionMirrorSync::query()
                ->lockForUpdate()
                ->findOrFail(VieFundTransactionMirrorSync::SINGLETON_ID);
            if (in_array($sync->state, $this->activeStates(), true)) {
                $sync->forceFill(['state' => 'paused', 'paused_at' => now()])->save();
            }

            return $sync->fresh();
        });
    }

    public function startFundIdBackfill(): VieFundTransactionMirrorSync
    {
        if (!$this->available()) {
            throw new InvalidArgumentException('The transaction mirror tables have not been migrated.');
        }

        $sync = DB::transaction(function (): VieFundTransactionMirrorSync {
            $sync = VieFundTransactionMirrorSync::query()
                ->lockForUpdate()
                ->find(VieFundTransactionMirrorSync::SINGLETON_ID);
            if (!$sync?->isReadable()) {
                throw new InvalidArgumentException('Complete and verify the initial mirror population first.');
            }
            if ($sync->state !== 'ready') {
                throw new InvalidArgumentException('Finish or resume the existing mirror synchronization before starting the fund/unit ID backfill.');
            }
            if ($sync->fund_ids_verified_at !== null && $sync->cashagch_verified_at !== null
                && $sync->cashagch_scope_hash === VieFundCashAgchReportSource::scopeHash((array) $sync->currencies, (array) $sync->status_ids)
                && !self::pendingFundMetadata(1)->exists()) {
                throw new InvalidArgumentException('Mirror fund/unit IDs and CASHagch report metadata have already been verified.');
            }

            $from = DB::table('viefund_transaction_mirror_rows')->min('created_date');
            $this->initializeRun(
                $sync,
                'fund_id_backfill',
                $from ? CarbonImmutable::parse($from)->toDateString() : now()->toDateString(),
                now()->toDateString(),
                'fund_id_backfill'
            );
            $sync->forceFill([
                'expected_rows' => self::pendingFundMetadata()->count(),
                'current_currency' => null,
            ])->save();

            return $sync->fresh();
        });
        $this->dispatch($this->availabilityWindow->isBlocked()
            ? $this->availabilityWindow->nextAvailableAt()
            : null);

        return $sync;
    }

    public function resume(): VieFundTransactionMirrorSync
    {
        $shouldDispatch = false;
        $sync = DB::transaction(function () use (&$shouldDispatch): VieFundTransactionMirrorSync {
            $sync = VieFundTransactionMirrorSync::query()
                ->lockForUpdate()
                ->findOrFail(VieFundTransactionMirrorSync::SINGLETON_ID);
            if (in_array($sync->state, ['paused', 'failed', 'verifying'], true) && $sync->sync_generation) {
                $sync->forceFill([
                    'state' => $this->availabilityWindow->isBlocked()
                        ? 'maintenance_wait'
                        : ($sync->phase === 'verification' ? 'verifying' : 'queued'),
                    'paused_at' => null,
                    'last_error' => null,
                ])->save();
                $shouldDispatch = true;
            }

            return $sync->fresh();
        });
        if ($shouldDispatch) {
            $this->dispatch($this->availabilityWindow->isBlocked()
                ? $this->availabilityWindow->nextAvailableAt()
                : null);
        }

        return $sync;
    }

    public static function pendingFundMetadata(?int $limit = null): Builder
    {
        $unchecked = DB::table('viefund_transaction_mirror_rows')
            ->whereNull('fund_id_checked_at')->select('cash_transaction_id');
        $report = DB::table('viefund_transaction_mirror_funds')
            ->where('fund_id', VieFundCashAgchReport::FUND_ID)
            ->whereNull('transaction_type_count')->select('cash_transaction_id');
        if ($limit !== null) {
            $unchecked->orderBy('cash_transaction_id')->limit($limit);
            $report->orderBy('cash_transaction_id')->limit($limit);
        }

        return DB::query()->fromSub($unchecked->union($report), 'pending_fund_metadata');
    }

    public function sourceMode(): string
    {
        $mode = (string) $this->runtimeSettings->get('viefund.all_transactions.data_source');

        return in_array($mode, [self::SOURCE_CURRENT, self::SOURCE_ADMIN_PREVIEW, self::SOURCE_LOCAL], true)
            ? $mode
            : self::SOURCE_CURRENT;
    }

    public function shouldUseMirror(?User $user, array $filters = []): bool
    {
        $sync = $this->sync();
        if (!$sync?->isReadable()) {
            return false;
        }
        if (trim((string) ($filters['fund_id'] ?? '')) !== '' && $sync->fund_ids_verified_at === null) {
            return false;
        }

        return match ($this->sourceMode()) {
            self::SOURCE_LOCAL => true,
            self::SOURCE_ADMIN_PREVIEW => $user !== null && $this->applicationAdmin->allows($user),
            default => false,
        };
    }

    public function fundIdsReady(): bool
    {
        $sync = $this->sync();

        return $sync?->isReadable() && $sync->fund_ids_verified_at !== null;
    }

    public function assertSourceModeAllowed(string $mode): void
    {
        if (
            in_array($mode, [self::SOURCE_ADMIN_PREVIEW, self::SOURCE_LOCAL], true)
            && !$this->sync()?->isReadable()
        ) {
            throw new InvalidArgumentException('The local mirror cannot be enabled until population and verification finish.');
        }
    }

    public function status(?VieFundTransactionMirrorSync $sync = null): array
    {
        $sync ??= $this->sync();
        if (!$sync) {
            return ['available' => false, 'state' => 'unavailable', 'data_source' => self::SOURCE_CURRENT];
        }

        $metrics = collect((array) $sync->batch_metrics);
        $metricRows = (int) $metrics->sum(fn(array $metric) => (int) ($metric['rows'] ?? 0));
        $metricSeconds = (float) $metrics->sum(fn(array $metric) => (float) ($metric['seconds'] ?? 0));
        $rowsPerSecond = $metricSeconds > 0 ? $metricRows / $metricSeconds : null;
        $remainingRows = $sync->expected_rows !== null
            ? max(0, (int) $sync->expected_rows - (int) $sync->rows_processed)
            : null;
        $etaSeconds = $rowsPerSecond && $metrics->count() >= 3 && $remainingRows !== null
            ? (int) ceil($remainingRows / $rowsPerSecond)
            : null;
        $estimatedCompletion = $etaSeconds !== null
            ? $this->addActiveSeconds(CarbonImmutable::now($this->availabilityWindow->timezone()), $etaSeconds)
            : null;
        $progress = $sync->expected_rows !== null
            ? ((int) $sync->expected_rows === 0
                ? 100.0
                : round(min(1, (int) $sync->rows_processed / (int) $sync->expected_rows) * 100, 1))
            : null;

        return [
            'available' => true,
            'state' => $sync->state,
            'mode' => $sync->mode,
            'phase' => $sync->phase,
            'ready' => $sync->isReadable(),
            'data_source' => $this->sourceMode(),
            'fund_ids_ready' => $sync->isReadable() && $sync->fund_ids_verified_at !== null,
            'fund_ids_verified_at' => optional($sync->fund_ids_verified_at)->toIso8601String(),
            'cashagch_ready' => $sync->cashagch_verified_at !== null
                && $sync->cashagch_scope_hash === VieFundCashAgchReportSource::scopeHash((array) $sync->currencies, (array) $sync->status_ids)
                && !self::pendingFundMetadata(1)->exists(),
            'cashagch_verification_error' => $sync->cashagch_verification_error,
            'maintenance_phase' => $this->availabilityWindow->phase(),
            'target_from_date' => optional($sync->target_from_date)->toDateString(),
            'target_through_date' => optional($sync->target_through_date)->toDateString(),
            'current_currency' => $sync->current_currency,
            'rows_processed' => (int) $sync->rows_processed,
            'expected_rows' => $sync->expected_rows !== null ? (int) $sync->expected_rows : null,
            'progress_pct' => $progress,
            'rows_per_second' => $rowsPerSecond !== null ? round($rowsPerSecond, 1) : null,
            'eta_seconds' => $etaSeconds,
            'estimated_completion_at' => $estimatedCompletion?->toIso8601String(),
            'started_at' => optional($sync->started_at)->toIso8601String(),
            'last_batch_completed_at' => optional($sync->last_batch_completed_at)->toIso8601String(),
            'ready_at' => optional($sync->ready_at)->toIso8601String(),
            'verified_at' => optional($sync->verified_at)->toIso8601String(),
            'synced_through_at' => optional($sync->synced_through_at)->toIso8601String(),
            'last_error' => $sync->last_error,
        ];
    }

    public function dispatch(?\DateTimeInterface $delay = null): void
    {
        $pending = SyncVieFundTransactionMirror::dispatch()->onConnection('database_viefund_cache');
        if ($delay !== null) {
            $pending->delay($delay);
        }
    }

    private function initializeRun(
        VieFundTransactionMirrorSync $sync,
        string $mode,
        string $from,
        string $through,
        string $phase
    ): void {
        $currencies = array_values((array) config('viefund.mirror.currencies', ['00', '01']));
        $statusIds = array_values((array) config('viefund.mirror.status_ids', range(0, 6)));
        if ($currencies === [] || $statusIds === []) {
            throw new InvalidArgumentException('Mirror currencies and status IDs must be configured.');
        }

        $sync->forceFill([
            'mode' => $mode,
            'state' => $this->availabilityWindow->isBlocked() ? 'maintenance_wait' : 'queued',
            'phase' => $phase,
            'target_from_date' => $from,
            'target_through_date' => $through,
            'current_currency' => $currencies[0],
            'currencies' => $currencies,
            'status_ids' => $statusIds,
            'cursor' => null,
            'sync_generation' => (string) Str::uuid(),
            'rows_processed' => 0,
            'expected_rows' => null,
            'active_seconds' => 0,
            'batch_metrics' => [],
            'started_at' => now(),
            'paused_at' => null,
            'last_batch_started_at' => null,
            'last_batch_completed_at' => null,
            'last_error' => null,
            'cashagch_verification' => null,
            'cashagch_verified_at' => $sync->cashagch_scope_hash === VieFundCashAgchReportSource::scopeHash($currencies, $statusIds)
                ? $sync->cashagch_verified_at : null,
            'cashagch_verification_error' => null,
        ])->save();
    }

    private function inceptionDate(): string
    {
        $configured = trim((string) config('viefund.mirror.inception_date'));
        $cached = trim((string) Cache::get('viefund:inception-date:create_date', ''));
        $date = $configured !== '' ? $configured : $cached;
        if ($date === '') {
            throw new InvalidArgumentException(
                'Configure VIEFUND_TRANSACTION_MIRROR_INCEPTION_DATE or refresh the report inception dates first.'
            );
        }

        return CarbonImmutable::parse($date)->toDateString();
    }

    private function available(): bool
    {
        return Schema::hasTable('viefund_transaction_mirror_rows')
            && Schema::hasTable('viefund_transaction_mirror_syncs');
    }

    /** @return list<string> */
    private function activeStates(): array
    {
        return ['queued', 'running', 'maintenance_wait', 'verifying', 'catching_up'];
    }

    private function addActiveSeconds(CarbonImmutable $from, int $seconds): CarbonImmutable
    {
        $cursor = $from;
        $remaining = $seconds;
        while ($remaining > 0) {
            if ($this->availabilityWindow->isBlocked($cursor)) {
                $cursor = $this->availabilityWindow->nextAvailableAt($cursor);
                continue;
            }

            $drain = $cursor->startOfDay()->setTimeFromTimeString(
                (string) config('viefund.mirror.maintenance_drain_start', '18:55')
            );
            if ($cursor->greaterThanOrEqualTo($drain)) {
                $drain = $drain->addDay();
            }
            $availableSeconds = max(0, $cursor->diffInSeconds($drain, false));
            if ($remaining <= $availableSeconds) {
                return $cursor->addSeconds($remaining);
            }
            $remaining -= $availableSeconds;
            $cursor = $this->availabilityWindow->nextAvailableAt($drain);
        }

        return $cursor;
    }
}
