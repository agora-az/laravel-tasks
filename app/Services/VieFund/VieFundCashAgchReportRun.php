<?php

namespace App\Services\VieFund;

use App\Services\RuntimeSettings;
use App\Exceptions\ReportMirrorChanged;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use RuntimeException;

class VieFundCashAgchReportRun
{
    public function __construct(
        private readonly VieFundRemoteService $remote,
        private readonly RuntimeSettings $settings,
        private readonly VieFundCashAgchReport $report
    ) {}

    public static function directory(int $userId, string $runId): string
    {
        return storage_path("app/reports/cashagch-{$userId}-{$runId}");
    }

    public static function buildingPath(int $userId, string $runId): string
    {
        return VieFundCashAgchReport::outputPath($userId, $runId).'.building';
    }

    public function load(string $directory, array $criteria): array
    {
        $hash = hash('sha256', json_encode($criteria, JSON_THROW_ON_ERROR));
        if (is_file($directory.'/checkpoint.json')) {
            $state = json_decode(File::get($directory.'/checkpoint.json'), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($state) || ($state['criteria_hash'] ?? null) !== $hash
                || !in_array($state['phase'] ?? null, ['discovering', 'counting', 'exporting', 'finalizing', 'ready', 'completed', 'cancelled', 'failed'], true)) {
                throw new RuntimeException('The CASHagch checkpoint does not match this report.');
            }

            return $state;
        }

        return [
            'criteria_hash' => $hash, 'phase' => 'discovering', 'parts' => [],
            'processed_transactions' => 0, 'expected_transactions' => null,
            'cursor' => null, 'count_from' => null, 'counted_transactions' => 0,
            'read_seconds' => 0.0, 'read_batches' => 0,
        ];
    }

    public function prepare(string $directory, array $criteria, array $state): array
    {
        if (!isset($state['source']) && !in_array($state['phase'], ['ready', 'completed', 'cancelled', 'failed'], true)) {
            $source = app(VieFundCashAgchReportSource::class);
            $state['source'] = $state['processed_transactions'] > 0 || $state['phase'] !== 'discovering'
                ? $source->remote('Resuming a report that started with direct VieFund.')
                : $source->select($criteria);
            $this->save($directory, $state);
        }
        return $state;
    }

    public function requiresRemote(array $state): bool
    {
        return in_array($state['phase'], ['discovering', 'counting', 'exporting'], true)
            && ($state['source']['data_source'] ?? 'viefund') !== 'mirror';
    }

    /** Performs one source batch, one calendar-month count, or local workbook assembly. */
    public function advance(string $directory, string $buildingPath, array $criteria, array $state, callable $progress): array
    {
        File::ensureDirectoryExists($directory, 0700);
        $state = $this->prepare($directory, $criteria, $state);
        $progress($this->status($state));
        $previousOpenedBefore = config('viefund.balance_report_cash_account_scope.opened_before');
        if ($this->requiresRemote($state)) {
            config(['viefund.balance_report_cash_account_scope.opened_before' => null]);
        }
        $source = app(VieFundCashAgchReportSource::class);
        try {
            if ($state['phase'] === 'counting') {
                $from = CarbonImmutable::parse($state['count_from']);
                $to = min($from->endOfMonth()->toDateString(), $criteria['date_to']);
                $countCriteria = [
                    ...$criteria, 'date_from' => $from->toDateString(), 'date_to' => $to,
                    'availability_date_to' => $criteria['date_to'],
                ];
                $state['counted_transactions'] += $state['source']['data_source'] === 'mirror'
                    ? $source->snapshot($state['source'], fn() => $source->count($countCriteria))
                    : $this->remote->countAllTransactions(null, VieFundCashAgchReport::filters($countCriteria));
                $next = $from->startOfMonth()->addMonth()->toDateString();
                $state['count_from'] = $next;
                if ($next > $criteria['date_to']) {
                    $state['expected_transactions'] = $state['counted_transactions'];
                    $state['phase'] = 'exporting';
                }
            } elseif (in_array($state['phase'], ['discovering', 'exporting'], true)) {
                $started = hrtime(true);
                $limit = max(100, min(1000, (int) $this->settings->get('viefund.export.batch_size')));
                $rows = $state['source']['data_source'] === 'mirror'
                    ? $source->snapshot($state['source'], fn() => $source->rows($criteria, $state['cursor'], $limit))
                    : $this->remote->fetchAllTransactionExportRowsAfter(null, VieFundCashAgchReport::filters($criteria), $state['cursor'], $limit);
                $progress($this->status($state));
                if ($rows->isEmpty()) {
                    $state['phase'] = 'finalizing';
                    $state['expected_transactions'] = $state['processed_transactions'];
                } else {
                    $cursor = VieFundCashAgchReport::cursor($rows->last());
                    if ($cursor === $state['cursor']) {
                        throw new RuntimeException('The CASHagch report source cursor did not advance.');
                    }
                    $fields = array_fill_keys([...array_keys(VieFundCashAgchReport::DETAIL_FIELDS), 'basis_date', 'sort_id'], true);
                    $lines = '';
                    foreach ($rows as $row) {
                        $lines .= json_encode(array_intersect_key((array) $row, $fields), JSON_THROW_ON_ERROR)."\n";
                    }
                    $name = sprintf('batch-%08d.ndjson', count($state['parts']));
                    $this->atomicWrite($directory.'/'.$name, $lines);
                    $state['parts'][] = ['name' => $name, 'rows' => $rows->count(), 'sha256' => hash('sha256', $lines)];
                    $state['cursor'] = $cursor;
                    $state['processed_transactions'] += $rows->count();
                    $state['read_seconds'] += max(0, (hrtime(true) - $started) / 1_000_000_000);
                    ++$state['read_batches'];
                    if ($state['phase'] === 'discovering') {
                        $state['count_from'] = !empty($criteria['date_from'])
                            ? $criteria['date_from']
                            : CarbonImmutable::parse($rows->first()->basis_date)->toDateString();
                        $state['phase'] = 'counting';
                    }
                }
            } elseif ($state['phase'] === 'finalizing') {
                $temp = $directory.'/xlsx-tmp';
                if (is_dir($temp) && !File::deleteDirectory($temp)) {
                    throw new RuntimeException('Unable to clean up interrupted workbook packaging.');
                }
                File::ensureDirectoryExists($temp, 0700);
                $result = $this->report->writeWorkbook(
                    [...$criteria, 'source' => $state['source']], $buildingPath, $this->batches($directory, $state),
                    $state['processed_transactions'],
                    function (int $count) use ($progress, $state): void {
                        $progress([
                            ...$this->status($state),
                            'message' => 'Building Excel workbook: '.number_format($count).' of '.number_format($state['processed_transactions']).' transactions. No further VieFund reads are needed.',
                        ]);
                    },
                    $temp
                );
                if ($result['processed_transactions'] !== $state['processed_transactions']) {
                    throw new RuntimeException('The staged transaction count does not reconcile with the workbook.');
                }
                $state['result'] = $result;
                $state['phase'] = 'ready';
            }
            $this->save($directory, $state);
            $progress($this->status($state));

            return $state;
        } catch (ReportMirrorChanged $exception) {
            \Illuminate\Support\Facades\Log::warning('CASHagch mirror changed; restarting against VieFund.', ['directory' => $directory]);
            $this->cleanup($directory, $buildingPath);
            $state = [
                'criteria_hash' => $state['criteria_hash'], 'phase' => 'discovering', 'parts' => [],
                'processed_transactions' => 0, 'expected_transactions' => null,
                'cursor' => null, 'count_from' => null, 'counted_transactions' => 0,
                'read_seconds' => 0.0, 'read_batches' => 0,
                'source' => $source->remote('The mirror changed during generation. Restarted from VieFund to avoid mixing snapshots.', $state['source']['mirror_synced_at']),
            ];
            $this->save($directory, $state);
            $progress($this->status($state));
            return $state;
        } finally {
            config(['viefund.balance_report_cash_account_scope.opened_before' => $previousOpenedBefore]);
        }
    }

    public function status(array $state): array
    {
        $count = $state['processed_transactions'];
        $total = $state['expected_transactions'];
        $phase = $state['phase'];
        $rate = $phase === 'exporting' && $state['read_batches'] >= 2 && $state['read_seconds'] > 0
            ? $count / $state['read_seconds'] : null;
        $eta = $rate !== null && $total > $count ? (int) ceil(($total - $count) / $rate) : null;

        return [
            ...($state['source'] ?? []),
            'state' => 'running', 'phase' => $phase,
            'processed_transactions' => $count, 'expected_transactions' => $total,
            'rows_per_second' => $rate !== null ? round($rate, 2) : null,
            'eta_seconds' => $eta,
            'estimated_completion_at' => $eta !== null ? now()->addSeconds($eta)->toIso8601String() : null,
            'message' => match ($phase) {
                'discovering' => 'Reading the first CASHagch batch to locate the beginning of the selected history...',
                'counting' => number_format($count).' transactions saved. Counting matching transactions for '.substr($state['count_from'], 0, 7).'...',
                'exporting' => number_format($count).' of approximately '.number_format($total).' transactions saved. Reading the next batch...',
                default => number_format($count).' transactions saved. Finalizing Excel workbook...',
            },
        ];
    }

    public function save(string $directory, array $state): void
    {
        File::ensureDirectoryExists($directory, 0700);
        $this->atomicWrite($directory.'/checkpoint.json', json_encode($state, JSON_THROW_ON_ERROR));
    }

    public function cleanup(string $directory, string $buildingPath): void
    {
        if (is_file($buildingPath) && !File::delete($buildingPath)) {
            throw new RuntimeException('Unable to discard the partial CASHagch workbook.');
        }
        if (!is_dir($directory)) {
            return;
        }
        foreach (File::files($directory) as $file) {
            if (preg_match('/^batch-\d{8}\.ndjson(?:\.tmp)?$/D', $file->getFilename()) && !File::delete($file->getPathname())) {
                throw new RuntimeException('Unable to discard staged CASHagch transactions.');
            }
        }
        if (is_dir($directory.'/xlsx-tmp') && !File::deleteDirectory($directory.'/xlsx-tmp')) {
            throw new RuntimeException('Unable to discard temporary CASHagch workbook files.');
        }
    }

    private function batches(string $directory, array $state): \Generator
    {
        foreach ($state['parts'] as $part) {
            if (!preg_match('/^batch-\d{8}\.ndjson$/D', $part['name'])) {
                throw new RuntimeException('Invalid staged CASHagch transaction filename.');
            }
            $path = $directory.'/'.$part['name'];
            $lines = File::get($path);
            if (!hash_equals($part['sha256'], hash('sha256', $lines))) {
                throw new RuntimeException('A staged CASHagch batch failed its integrity check.');
            }
            $rows = [];
            foreach (explode("\n", rtrim($lines, "\n")) as $line) {
                $rows[] = json_decode($line, false, 512, JSON_THROW_ON_ERROR);
            }
            if (count($rows) !== $part['rows']) {
                throw new RuntimeException('A staged CASHagch batch has an incorrect transaction count.');
            }
            yield $rows;
        }
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $temp = $path.'.tmp';
        if (File::put($temp, $contents) !== strlen($contents) || !chmod($temp, 0600) || !rename($temp, $path)) {
            throw new RuntimeException('Unable to save CASHagch report progress.');
        }
    }
}
