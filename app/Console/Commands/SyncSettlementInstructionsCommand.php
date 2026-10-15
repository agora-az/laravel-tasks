<?php

namespace App\Console\Commands;

use App\Models\Import;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use phpseclib3\Net\SFTP;

class SyncSettlementInstructionsCommand extends Command
{
    protected $signature = 'settlement:sync-instructions
        {--host= : SFTP host (defaults to SETTLEMENT_SFTP_HOST, then BANK_SFTP_HOST)}
        {--port= : SFTP port (defaults to SETTLEMENT_SFTP_PORT, then BANK_SFTP_PORT)}
        {--username= : SFTP username (defaults to SETTLEMENT_SFTP_USERNAME, then BANK_SFTP_USERNAME)}
        {--password= : SFTP password (defaults to SETTLEMENT_SFTP_PASSWORD, then BANK_SFTP_PASSWORD)}
        {--remote-path= : Remote directory (defaults to SETTLEMENT_SFTP_REMOTE_PATH, then BANK_SFTP_REMOTE_PATH)}
        {--local-path= : Local download directory (defaults to SETTLEMENT_SFTP_LOCAL_PATH or resources/data/cibc)}
        {--pattern= : Filename glob (defaults to SETTLEMENT_SFTP_FILE_PATTERN or FSP*)}
        {--chunk=500 : Import insert chunk size}
        {--dry-run : Preview without downloading, importing, or deleting files}
        {--keep-local : Keep processed files in the local download directory}
        {--force : Import files even when the filename was already completed}
        {--rebuild : Truncate settlement instruction tables before importing all matched files}
        {--status-file= : Optional path for sync progress JSON}
        {--lock-file= : Optional lock file marking a sync in progress}
        {--run-id= : Identifier supplied by the initiating web session}
        {--trigger=Command line : Description of what initiated the sync}';

    protected $description = 'Download FSP settlement instruction files from SFTP and import them';

    private ?string $runId = null;

    private string $trigger = 'Command line';

    private ?string $lockFile = null;

    public function handle(): int
    {
        $lockFile = $this->stringOption('lock-file');
        $statusFile = $this->stringOption('status-file');
        $this->lockFile = $lockFile;
        $this->runId = $this->stringOption('run-id');
        $this->trigger = $this->stringOption('trigger') ?? 'Command line';

        if ($lockFile) {
            @file_put_contents($lockFile, date('c'));
        }

        try {
            return $this->runSync($statusFile);
        } catch (\Throwable $e) {
            $this->writeStatus($statusFile, [
                'inProgress' => false,
                'success' => false,
                'message' => 'Settlement instruction sync failed: ' . $e->getMessage(),
                'updated_at' => now()->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
            ]);

            throw $e;
        } finally {
            if ($lockFile && file_exists($lockFile)) {
                @unlink($lockFile);
            }
        }
    }

    private function runSync(?string $statusFile): int
    {
        $startedAt = now()->toIso8601String();
        $this->writeStatus($statusFile, $this->statusPayload(
            true,
            null,
            'Preparing settlement instruction sync...',
            0,
            null,
            0,
            $startedAt
        ));

        $host = $this->connectionOption('host', 'sftp.settlement.host', 'sftp.bank.host');
        $port = (int) ($this->connectionOption('port', 'sftp.settlement.port', 'sftp.bank.port', '22') ?? 22);
        $username = $this->connectionOption('username', 'sftp.settlement.username', 'sftp.bank.username');
        $password = $this->connectionOption('password', 'sftp.settlement.password', 'sftp.bank.password');
        $remotePath = $this->normalizeRemotePath(
            $this->connectionOption('remote-path', 'sftp.settlement.remote_path', 'sftp.bank.remote_path', '/') ?? '/'
        );
        $localPath = $this->connectionOption(
            'local-path',
            'sftp.settlement.local_path',
            null,
            'resources/data/cibc'
        ) ?? 'resources/data/cibc';
        $pattern = $this->connectionOption('pattern', 'sftp.settlement.file_pattern', null, 'FSP*') ?? 'FSP*';
        $chunkSize = max(1, (int) $this->option('chunk'));
        $force = (bool) $this->option('force');
        $rebuild = (bool) $this->option('rebuild');
        $keepLocal = (bool) $this->option('keep-local');
        $dryRun = (bool) $this->option('dry-run') || (bool) config('sftp.settlement.dry_run', false);

        if ($rebuild) {
            $force = true;
        }

        $localDir = base_path($localPath);
        if (!$dryRun && !is_dir($localDir) && !mkdir($localDir, 0775, true) && !is_dir($localDir)) {
            return $this->failSync($statusFile, "Unable to create local directory: {$localDir}", $startedAt);
        }

        if (!$host || !$username || !$password) {
            return $this->failSync(
                $statusFile,
                'Missing settlement SFTP credentials. Configure SETTLEMENT_SFTP_* or BANK_SFTP_* credentials.',
                $startedAt
            );
        }

        $processedNames = $rebuild
            ? []
            : Import::query()
            ->where('type', 'settlement-instructions-raw')
            ->where('status', 'completed')
            ->pluck('filename')
            ->all();

        $localCandidates = $this->localCandidates($localDir, $pattern);
        $deletedProcessedLocalCount = 0;

        if (!$dryRun && !$keepLocal && !$force) {
            foreach ($localCandidates->filter(fn($name) => in_array($name, $processedNames, true)) as $fileName) {
                if (@unlink($localDir . DIRECTORY_SEPARATOR . $fileName)) {
                    $deletedProcessedLocalCount++;
                }
            }

            $localCandidates = $this->localCandidates($localDir, $pattern);
        }

        $connect = function () use ($host, $port, $username, $password): SFTP {
            $client = new SFTP($host, $port);
            if (!$client->login($username, $password)) {
                throw new \RuntimeException('Settlement SFTP authentication failed.');
            }

            return $client;
        };

        try {
            $sftp = $connect();
        } catch (\Throwable $e) {
            return $this->failSync(
                $statusFile,
                "Unable to connect to settlement SFTP host {$host}:{$port}. " . trim($e->getMessage()),
                $startedAt
            );
        }

        $remoteFiles = $sftp->nlist($remotePath);
        if ($remoteFiles === false) {
            return $this->failSync($statusFile, "Unable to list remote path: {$remotePath}", $startedAt);
        }

        $candidateNames = static fn(array $files) => collect($files)
            ->map(fn($name) => basename((string) $name))
            ->filter(fn($name) => $name !== '' && $name !== '.' && $name !== '..')
            ->filter(fn($name) => fnmatch($pattern, $name))
            ->unique()
            ->sort()
            ->values();

        $remoteCandidates = $candidateNames($remoteFiles);

        // When settlement-specific path configuration is absent, the bank SFTP
        // root is used for the connection. Current FSP deliveries are stored in
        // its Settlement_Files subdirectory, so discover that folder when the
        // configured directory contains no matching FSP files.
        if ($remoteCandidates->isEmpty()) {
            $settlementFilesPath = $remotePath === '/'
                ? '/Settlement_Files'
                : $remotePath . '/Settlement_Files';
            $settlementFiles = $sftp->nlist($settlementFilesPath);

            if ($settlementFiles !== false) {
                $subdirectoryCandidates = $candidateNames($settlementFiles);
                if ($subdirectoryCandidates->isNotEmpty()) {
                    $remotePath = $settlementFilesPath;
                    $remoteCandidates = $subdirectoryCandidates;
                }
            }
        }

        // A dropped SFTP transfer can leave a file with the final filename on
        // disk. Never treat it as importable until its size matches the remote
        // file. Preserve mismatches for inspection and download them again.
        $invalidLocalNames = collect();
        foreach ($remoteCandidates->filter(fn($name) => $localCandidates->contains($name)) as $fileName) {
            $remoteFilePath = $remotePath === '/' ? "/{$fileName}" : "{$remotePath}/{$fileName}";
            $localFilePath = $localDir . DIRECTORY_SEPARATOR . $fileName;

            try {
                $remoteSize = $sftp->filesize($remoteFilePath);
            } catch (\Throwable) {
                $remoteSize = false;
            }

            if ($remoteSize === false || (int) $remoteSize === (int) filesize($localFilePath)) {
                continue;
            }

            $localSize = (int) filesize($localFilePath);
            $invalidLocalNames->push($fileName);
            if (!$dryRun) {
                $incompleteDir = $localDir . DIRECTORY_SEPARATOR . '.incomplete';
                if (!is_dir($incompleteDir)) {
                    @mkdir($incompleteDir, 0775, true);
                }
                $preservedPath = $incompleteDir . DIRECTORY_SEPARATOR . $fileName . '.' . now()->format('Ymd_His');
                @rename($localFilePath, $preservedPath);
            }

            $this->warn(sprintf(
                'Local file %s is incomplete (%d of %d bytes); it will be downloaded again.',
                $fileName,
                $localSize,
                (int) $remoteSize
            ));
        }

        $localCandidates = $localCandidates->reject(fn($name) => $invalidLocalNames->contains($name))->values();
        $localPending = $force
            ? $localCandidates
            : $localCandidates->reject(fn($name) => in_array($name, $processedNames, true))->values();

        $toDownload = $force
            ? $remoteCandidates->reject(fn($name) => $localCandidates->contains($name))->values()
            : $remoteCandidates
            ->reject(fn($name) => in_array($name, $processedNames, true))
            ->reject(fn($name) => $localCandidates->contains($name))
            ->values();

        $totalFiles = $localPending->count() + $toDownload->count();

        if ($dryRun) {
            $message = sprintf(
                'Dry run complete. %d remote FSP files matched; %d files are eligible (%d local, %d to download).',
                $remoteCandidates->count(),
                $totalFiles,
                $localPending->count(),
                $toDownload->count()
            );
            $this->writeStatus($statusFile, $this->statusPayload(false, true, $message, 0, $totalFiles, 100, $startedAt, true));
            $this->info($message);

            return self::SUCCESS;
        }

        if ($totalFiles === 0) {
            $message = 'No new unprocessed FSP files found.';
            $this->writeStatus($statusFile, $this->statusPayload(false, true, $message, 0, 0, 100, $startedAt));
            $this->info($message);

            return self::SUCCESS;
        }

        $this->writeStatus($statusFile, $this->statusPayload(
            true,
            null,
            'Downloading unprocessed FSP files from SFTP...',
            0,
            $totalFiles,
            0,
            $startedAt
        ));

        $downloaded = [];
        $failed = [];
        $downloadStartedAt = microtime(true);
        $downloadTotal = $toDownload->count();
        $downloadProcessed = 0;
        foreach ($toDownload as $fileName) {
            $this->writeStatus($statusFile, $this->statusPayload(
                true,
                null,
                'Downloading FSP files from SFTP...',
                $downloadProcessed,
                $downloadTotal,
                $this->progressPercentage($downloadProcessed, $downloadTotal),
                $startedAt,
                false,
                [
                    'phase' => 'downloading',
                    'current_file' => $fileName,
                    'eta_seconds' => $this->estimateEtaSeconds($downloadStartedAt, $downloadProcessed, $downloadTotal),
                    'successful_files' => count($downloaded),
                    'failed_files' => count($failed),
                ]
            ));

            $remoteFilePath = $remotePath === '/' ? "/{$fileName}" : "{$remotePath}/{$fileName}";
            $localFilePath = $localDir . DIRECTORY_SEPARATOR . $fileName;
            $temporaryFilePath = $localDir . DIRECTORY_SEPARATOR . '.download-' . $fileName . '.part';
            $downloadSucceeded = false;

            for ($attempt = 1; $attempt <= 3; $attempt++) {
                @unlink($temporaryFilePath);
                try {
                    $downloadSucceeded = $sftp->get($remoteFilePath, $temporaryFilePath);
                    $remoteSize = $downloadSucceeded ? $sftp->filesize($remoteFilePath) : false;
                    if ($downloadSucceeded && $remoteSize !== false) {
                        $downloadSucceeded = (int) filesize($temporaryFilePath) === (int) $remoteSize;
                    }
                    if ($downloadSucceeded && @rename($temporaryFilePath, $localFilePath)) {
                        break;
                    }
                    $downloadSucceeded = false;
                } catch (\Throwable $e) {
                    $this->warn("Download attempt {$attempt} failed for {$fileName}: " . $e->getMessage());
                }

                @unlink($temporaryFilePath);
                if ($attempt < 3) {
                    try {
                        $sftp = $connect();
                    } catch (\Throwable $e) {
                        $this->warn('SFTP reconnect failed: ' . $e->getMessage());
                    }
                }
            }

            @unlink($temporaryFilePath);
            if (!$downloadSucceeded) {
                $failed[] = $fileName;
            } else {
                $downloaded[] = $fileName;
            }

            $downloadProcessed++;
            $this->writeStatus($statusFile, $this->statusPayload(
                true,
                null,
                'Downloading FSP files from SFTP...',
                $downloadProcessed,
                $downloadTotal,
                $this->progressPercentage($downloadProcessed, $downloadTotal),
                $startedAt,
                false,
                [
                    'phase' => 'downloading',
                    'current_file' => $fileName,
                    'eta_seconds' => $this->estimateEtaSeconds($downloadStartedAt, $downloadProcessed, $downloadTotal),
                    'successful_files' => count($downloaded),
                    'failed_files' => count($failed),
                ]
            ));
        }

        $filesToProcess = $localPending->concat($downloaded)->unique()->values();
        if ($filesToProcess->isEmpty()) {
            return $this->failSync($statusFile, 'No FSP files were available after download.', $startedAt, $totalFiles);
        }

        $stagingRelativeDir = 'storage/app/settlement-sync/' . now()->format('Ymd_His_u');
        $stagingDir = base_path($stagingRelativeDir);
        if (!mkdir($stagingDir, 0775, true) && !is_dir($stagingDir)) {
            return $this->failSync($statusFile, "Unable to create staging directory: {$stagingDir}", $startedAt, $totalFiles);
        }

        $stagedFiles = [];
        $stagingStartedAt = microtime(true);
        $stagingTotal = $filesToProcess->count();
        $stagingProcessed = 0;
        foreach ($filesToProcess as $fileName) {
            if (@copy(
                $localDir . DIRECTORY_SEPARATOR . $fileName,
                $stagingDir . DIRECTORY_SEPARATOR . $fileName
            )) {
                $stagedFiles[] = $fileName;
            } else {
                $failed[] = $fileName;
            }

            $stagingProcessed++;
            $this->writeStatus($statusFile, $this->statusPayload(
                true,
                null,
                'Preparing downloaded FSP files for import...',
                $stagingProcessed,
                $stagingTotal,
                $this->progressPercentage($stagingProcessed, $stagingTotal),
                $startedAt,
                false,
                [
                    'phase' => 'staging',
                    'current_file' => $fileName,
                    'eta_seconds' => $this->estimateEtaSeconds($stagingStartedAt, $stagingProcessed, $stagingTotal),
                    'successful_files' => count($stagedFiles),
                    'failed_files' => count($failed),
                ]
            ));
        }

        if (empty($stagedFiles)) {
            $this->cleanupDirectory($stagingDir);
            return $this->failSync($statusFile, 'No FSP files were staged successfully.', $startedAt, $totalFiles);
        }

        $this->writeStatus($statusFile, $this->statusPayload(
            true,
            null,
            'Importing FSP settlement instructions...',
            0,
            count($stagedFiles),
            20,
            $startedAt
        ));

        $importArguments = [
            '--path' => $stagingRelativeDir,
            '--pattern' => $pattern,
            '--chunk' => $chunkSize,
        ];
        if ($rebuild) {
            $importArguments['--truncate'] = true;
        }
        if ($statusFile) {
            $importArguments['--status-file'] = $statusFile;
            $importArguments['--lock-file'] = $this->lockFile;
            $importArguments['--started-at'] = $startedAt;
            $importArguments['--run-id'] = $this->runId;
            $importArguments['--trigger'] = $this->trigger;
        }

        $importExit = Artisan::call('import:settlement-instructions', $importArguments);
        $this->line(Artisan::output());

        if ($importExit !== self::SUCCESS) {
            $this->cleanupDirectory($stagingDir);
            return $this->failSync(
                $statusFile,
                'FSP settlement instruction import failed. Check the settlement sync log.',
                $startedAt,
                count($stagedFiles)
            );
        }

        if (!$keepLocal) {
            foreach ($stagedFiles as $fileName) {
                @unlink($localDir . DIRECTORY_SEPARATOR . $fileName);
            }
        }

        $this->cleanupDirectory($stagingDir);

        $success = empty($failed);
        $message = $success
            ? sprintf(
                'Completed FSP sync. Processed %d files and removed %d previously processed local files.',
                count($stagedFiles),
                $deletedProcessedLocalCount
            )
            : sprintf(
                'FSP sync completed with issues: %d processed and %d download/staging failures.',
                count($stagedFiles),
                count($failed)
            );

        $this->writeStatus($statusFile, $this->statusPayload(
            false,
            $success,
            $message,
            count($stagedFiles),
            count($stagedFiles),
            100,
            $startedAt
        ));
        $this->info($message);

        return $success ? self::SUCCESS : self::FAILURE;
    }

    private function localCandidates(string $directory, string $pattern): Collection
    {
        return collect((is_dir($directory) ? glob($directory . DIRECTORY_SEPARATOR . $pattern) : []) ?: [])
            ->map(fn($path) => basename($path))
            ->sort()
            ->values();
    }

    private function connectionOption(
        string $option,
        string $primaryConfig,
        ?string $fallbackConfig = null,
        ?string $default = null
    ): ?string {
        $optionValue = $this->stringOption($option);
        if ($optionValue !== null) {
            return $optionValue;
        }

        foreach (array_filter([$primaryConfig, $fallbackConfig]) as $configKey) {
            $configuredValue = $this->stringValue(config($configKey));
            if ($configuredValue !== null) {
                return $configuredValue;
            }
        }

        return $default;
    }

    private function stringOption(string $option): ?string
    {
        return $this->stringValue($this->option($option));
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeRemotePath(string $remotePath): string
    {
        $normalized = '/' . trim($remotePath, '/');

        return $normalized === '//' ? '/' : $normalized;
    }

    private function cleanupDirectory(string $directory): void
    {
        foreach ((glob($directory . DIRECTORY_SEPARATOR . '*') ?: []) as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }

    private function failSync(
        ?string $statusFile,
        string $message,
        string $startedAt,
        ?int $totalFiles = null
    ): int {
        $this->error($message);
        $this->writeStatus($statusFile, $this->statusPayload(
            false,
            false,
            $message,
            0,
            $totalFiles,
            0,
            $startedAt
        ));

        return self::FAILURE;
    }

    private function statusPayload(
        bool $inProgress,
        ?bool $success,
        string $message,
        int $processedFiles,
        ?int $totalFiles,
        int $progressPct,
        string $startedAt,
        bool $dryRun = false,
        array $details = []
    ): array {
        $now = now()->toIso8601String();

        return array_merge([
            'run_id' => $this->runId,
            'trigger' => $this->trigger,
            'inProgress' => $inProgress,
            'success' => $success,
            'dry_run' => $dryRun,
            'message' => $message,
            'processed_files' => $processedFiles,
            'total_files' => $totalFiles,
            'progress_pct' => $progressPct,
            'started_at' => $startedAt,
            'updated_at' => $now,
            'completed_at' => $inProgress ? null : $now,
        ], $details);
    }

    private function progressPercentage(int $processed, int $total): int
    {
        return $total > 0 ? min(100, (int) floor(($processed / $total) * 100)) : 0;
    }

    private function estimateEtaSeconds(float $startedAt, int $processed, int $total): ?int
    {
        if ($processed <= 0 || $total <= $processed) {
            return null;
        }

        $filesPerSecond = $processed / max(1, microtime(true) - $startedAt);

        return $filesPerSecond > 0 ? (int) ceil(($total - $processed) / $filesPerSecond) : null;
    }

    private function writeStatus(?string $statusFile, array $payload): void
    {
        if ($statusFile) {
            $temporaryFile = $statusFile . '.tmp';
            $json = json_encode(array_merge([
                'run_id' => $this->runId,
                'trigger' => $this->trigger,
            ], $payload), JSON_PRETTY_PRINT);

            if ($json !== false && @file_put_contents($temporaryFile, $json, LOCK_EX) !== false) {
                @rename($temporaryFile, $statusFile);
            }
        }

        if ($this->lockFile && file_exists($this->lockFile)) {
            @touch($this->lockFile);
        }
    }
}
