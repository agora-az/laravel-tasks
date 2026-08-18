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
        {--lock-file= : Optional lock file marking a sync in progress}';

    protected $description = 'Download FSP settlement instruction files from SFTP and import them';

    public function handle(): int
    {
        $lockFile = $this->stringOption('lock-file');
        $statusFile = $this->stringOption('status-file');

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

        $host = $this->connectionOption('host', 'SETTLEMENT_SFTP_HOST', 'BANK_SFTP_HOST');
        $port = (int) ($this->connectionOption('port', 'SETTLEMENT_SFTP_PORT', 'BANK_SFTP_PORT', '22') ?? 22);
        $username = $this->connectionOption('username', 'SETTLEMENT_SFTP_USERNAME', 'BANK_SFTP_USERNAME');
        $password = $this->connectionOption('password', 'SETTLEMENT_SFTP_PASSWORD', 'BANK_SFTP_PASSWORD');
        $remotePath = $this->normalizeRemotePath(
            $this->connectionOption('remote-path', 'SETTLEMENT_SFTP_REMOTE_PATH', 'BANK_SFTP_REMOTE_PATH', '/') ?? '/'
        );
        $localPath = $this->connectionOption(
            'local-path',
            'SETTLEMENT_SFTP_LOCAL_PATH',
            null,
            'resources/data/cibc'
        ) ?? 'resources/data/cibc';
        $pattern = $this->connectionOption('pattern', 'SETTLEMENT_SFTP_FILE_PATTERN', null, 'FSP*') ?? 'FSP*';
        $chunkSize = max(1, (int) $this->option('chunk'));
        $force = (bool) $this->option('force');
        $rebuild = (bool) $this->option('rebuild');
        $keepLocal = (bool) $this->option('keep-local');
        $dryRun = (bool) $this->option('dry-run') || $this->booleanEnv('SETTLEMENT_SFTP_DRY_RUN', false);

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

        $localPending = $force
            ? $localCandidates
            : $localCandidates->reject(fn($name) => in_array($name, $processedNames, true))->values();

        $sftp = new SFTP($host, $port);
        try {
            $loggedIn = $sftp->login($username, $password);
        } catch (\Throwable $e) {
            return $this->failSync(
                $statusFile,
                "Unable to connect to settlement SFTP host {$host}:{$port}. " . trim($e->getMessage()),
                $startedAt
            );
        }

        if (!$loggedIn) {
            return $this->failSync($statusFile, 'Settlement SFTP authentication failed.', $startedAt);
        }

        $remoteFiles = $sftp->nlist($remotePath);
        if ($remoteFiles === false) {
            return $this->failSync($statusFile, "Unable to list remote path: {$remotePath}", $startedAt);
        }

        $remoteCandidates = collect($remoteFiles)
            ->map(fn($name) => basename((string) $name))
            ->filter(fn($name) => $name !== '' && $name !== '.' && $name !== '..')
            ->filter(fn($name) => fnmatch($pattern, $name))
            ->unique()
            ->sort()
            ->values();

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
        foreach ($toDownload as $fileName) {
            $remoteFilePath = $remotePath === '/' ? "/{$fileName}" : "{$remotePath}/{$fileName}";
            $localFilePath = $localDir . DIRECTORY_SEPARATOR . $fileName;

            if (!$sftp->get($remoteFilePath, $localFilePath)) {
                $failed[] = $fileName;
                continue;
            }

            $downloaded[] = $fileName;
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
        foreach ($filesToProcess as $fileName) {
            if (@copy(
                $localDir . DIRECTORY_SEPARATOR . $fileName,
                $stagingDir . DIRECTORY_SEPARATOR . $fileName
            )) {
                $stagedFiles[] = $fileName;
            } else {
                $failed[] = $fileName;
            }
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

        $importExit = Artisan::call('import:settlement-instructions', [
            '--path' => $stagingRelativeDir,
            '--pattern' => $pattern,
            '--truncate' => $rebuild,
            '--chunk' => $chunkSize,
        ]);
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
        string $primaryEnv,
        ?string $fallbackEnv = null,
        ?string $default = null
    ): ?string {
        $optionValue = $this->stringOption($option);
        if ($optionValue !== null) {
            return $optionValue;
        }

        foreach (array_filter([$primaryEnv, $fallbackEnv]) as $envKey) {
            $envValue = $this->stringValue(env($envKey));
            if ($envValue !== null) {
                return $envValue;
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

    private function booleanEnv(string $key, bool $default): bool
    {
        $value = env($key);
        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
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
        bool $dryRun = false
    ): array {
        $now = now()->toIso8601String();

        return [
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
        ];
    }

    private function writeStatus(?string $statusFile, array $payload): void
    {
        if ($statusFile) {
            @file_put_contents($statusFile, json_encode($payload, JSON_PRETTY_PRINT));
        }
    }
}