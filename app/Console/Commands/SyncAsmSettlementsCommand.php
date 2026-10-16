<?php

namespace App\Console\Commands;

use App\Models\AsmSettlementSummary;
use App\Models\AsmSettlementTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use phpseclib3\Net\SFTP;

class SyncAsmSettlementsCommand extends Command
{
    protected $signature = 'asm:sync-settlements
        {--host= : SFTP host (defaults to ASM_SFTP_HOST, then SETTLEMENT_SFTP_HOST, then BANK_SFTP_HOST)}
        {--port= : SFTP port (defaults to ASM_SFTP_PORT, then SETTLEMENT_SFTP_PORT, then BANK_SFTP_PORT)}
        {--username= : SFTP username (defaults to ASM_SFTP_USERNAME, then SETTLEMENT_SFTP_USERNAME, then BANK_SFTP_USERNAME)}
        {--password= : SFTP password (defaults to ASM_SFTP_PASSWORD, then SETTLEMENT_SFTP_PASSWORD, then BANK_SFTP_PASSWORD)}
        {--remote-path= : Remote directory (defaults to ASM_SFTP_REMOTE_PATH or /ASM_Files)}
        {--local-path= : Local download directory (defaults to ASM_SFTP_LOCAL_PATH or resources/data/asm)}
        {--pattern= : Filename glob (defaults to ASM_SFTP_FILE_PATTERN or asm_AGRA_*.xlsx)}
        {--chunk=500 : Import insert chunk size}
        {--dry-run : Preview remote, imported, and eligible file counts without changing anything}
        {--keep-local : Keep processed files in the local download directory}
        {--status-file= : Optional path for sync progress JSON}
        {--lock-file= : Optional lock file marking a sync in progress}
        {--run-id= : Identifier supplied by the initiating web session}
        {--trigger=Command line : Description of what initiated the sync}';

    protected $description = 'Download new ASM settlement workbooks from SFTP, import them, and map them to VieFund';

    private ?string $runId = null;

    private string $trigger = 'Command line';

    private ?string $lockFile = null;

    public function handle(): int
    {
        $statusFile = $this->stringOption('status-file');
        $this->lockFile = $this->stringOption('lock-file');
        $this->runId = $this->stringOption('run-id');
        $this->trigger = $this->stringOption('trigger') ?? 'Command line';

        if ($this->lockFile) {
            @file_put_contents($this->lockFile, date('c'));
        }

        try {
            return $this->runSync($statusFile);
        } catch (\Throwable $e) {
            $this->writeStatus($statusFile, [
                'inProgress' => false,
                'success' => false,
                'message' => 'ASM sync failed: ' . $e->getMessage(),
                'updated_at' => now()->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
            ]);

            throw $e;
        } finally {
            if ($this->lockFile && file_exists($this->lockFile)) {
                @unlink($this->lockFile);
            }
        }
    }

    private function runSync(?string $statusFile): int
    {
        $startedAt = now()->toIso8601String();
        $this->writeProgress($statusFile, 'Preparing ASM sync...', 0, null, 0, $startedAt);

        $host = $this->connectionOption('host', [
            'sftp.asm.host',
            'sftp.settlement.host',
            'sftp.bank.host',
        ]);
        $port = (int) ($this->connectionOption('port', [
            'sftp.asm.port',
            'sftp.settlement.port',
            'sftp.bank.port',
        ], '22') ?? 22);
        $username = $this->connectionOption('username', [
            'sftp.asm.username',
            'sftp.settlement.username',
            'sftp.bank.username',
        ]);
        $password = $this->connectionOption('password', [
            'sftp.asm.password',
            'sftp.settlement.password',
            'sftp.bank.password',
        ]);
        $remotePath = $this->normalizeRemotePath(
            $this->connectionOption('remote-path', ['sftp.asm.remote_path'], '/ASM_Files') ?? '/ASM_Files'
        );
        $localPath = $this->connectionOption(
            'local-path',
            ['sftp.asm.local_path'],
            'resources/data/asm'
        ) ?? 'resources/data/asm';
        $pattern = $this->connectionOption(
            'pattern',
            ['sftp.asm.file_pattern'],
            'asm_AGRA_*.xlsx'
        ) ?? 'asm_AGRA_*.xlsx';
        $chunkSize = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run') || (bool) config('sftp.asm.dry_run', false);
        $keepLocal = (bool) $this->option('keep-local');
        $localDir = base_path($localPath);

        if (!$host || !$username || !$password) {
            return $this->failSync(
                $statusFile,
                'Missing ASM SFTP credentials. Configure ASM_SFTP_*, SETTLEMENT_SFTP_*, or BANK_SFTP_* credentials.',
                $startedAt
            );
        }

        if (!$dryRun && !is_dir($localDir) && !mkdir($localDir, 0775, true) && !is_dir($localDir)) {
            return $this->failSync($statusFile, "Unable to create local directory: {$localDir}", $startedAt);
        }

        $connect = function () use ($host, $port, $username, $password): SFTP {
            $client = new SFTP($host, $port);
            if (!$client->login($username, $password)) {
                throw new \RuntimeException('ASM SFTP authentication failed.');
            }

            return $client;
        };

        try {
            $sftp = $connect();
        } catch (\Throwable $e) {
            return $this->failSync(
                $statusFile,
                "Unable to connect to ASM SFTP host {$host}:{$port}. " . trim($e->getMessage()),
                $startedAt
            );
        }

        $remoteFiles = $sftp->nlist($remotePath);
        if ($remoteFiles === false) {
            return $this->failSync($statusFile, "Unable to list ASM remote path: {$remotePath}", $startedAt);
        }

        $remoteCandidates = collect($remoteFiles)
            ->map(fn($name) => basename((string) $name))
            ->filter(fn($name) => $name !== '' && $name !== '.' && $name !== '..')
            ->filter(fn($name) => fnmatch($pattern, $name))
            ->unique()
            ->sort()
            ->values();
        $importedNames = AsmSettlementSummary::query()
            ->whereIn('source_file', $remoteCandidates->all())
            ->pluck('source_file')
            ->all();
        $alreadyImported = $remoteCandidates
            ->filter(fn(string $name) => in_array($name, $importedNames, true))
            ->values();
        $localCandidates = $this->localCandidates($localDir, $pattern);
        $localPending = $localCandidates
            ->reject(fn(string $name) => in_array($name, $importedNames, true))
            ->values();
        $toDownload = $remoteCandidates
            ->reject(fn(string $name) => in_array($name, $importedNames, true))
            ->reject(fn(string $name) => $localPending->contains($name))
            ->values();
        $eligibleCount = $localPending->count() + $toDownload->count();

        if ($dryRun) {
            $message = sprintf(
                'ASM dry run complete. %d remote files matched; %d are already in the database and %d are eligible (%d local, %d to download).',
                $remoteCandidates->count(),
                $alreadyImported->count(),
                $eligibleCount,
                $localPending->count(),
                $toDownload->count()
            );
            $this->finish($statusFile, true, $message, 0, $eligibleCount, $startedAt, true);

            return self::SUCCESS;
        }

        if ($eligibleCount === 0) {
            if (AsmSettlementTransaction::query()->whereDoesntHave('vieFundMatches')->exists()) {
                $this->writeProgress(
                    $statusFile,
                    'Retrying pending ASM mapping to VieFund...',
                    0,
                    0,
                    90,
                    $startedAt,
                    ['phase' => 'mapping']
                );
                $mapExitCode = Artisan::call('asm:map-viefund');
                $this->output->write(Artisan::output());
                if ($mapExitCode !== self::SUCCESS) {
                    return $this->failSync(
                        $statusFile,
                        'No new ASM files were found, and retrying the pending VieFund mapping failed.',
                        $startedAt
                    );
                }
            }

            $message = sprintf(
                'No new ASM files found. %d remote files matched and are already in the database.%s',
                $alreadyImported->count(),
                isset($mapExitCode) ? ' Pending VieFund mapping completed.' : ''
            );
            $this->finish($statusFile, true, $message, 0, 0, $startedAt);

            return self::SUCCESS;
        }

        $downloaded = [];
        $failed = [];
        $downloadTotal = $toDownload->count();
        foreach ($toDownload as $index => $fileName) {
            $this->writeProgress(
                $statusFile,
                'Downloading ASM files from SFTP...',
                $index,
                $downloadTotal,
                $this->percentage($index, $downloadTotal),
                $startedAt,
                ['phase' => 'downloading', 'current_file' => $fileName]
            );

            $remoteFile = $remotePath === '/' ? "/{$fileName}" : "{$remotePath}/{$fileName}";
            $localFile = $localDir . DIRECTORY_SEPARATOR . $fileName;
            $temporaryFile = $localFile . '.part';
            $success = false;

            for ($attempt = 1; $attempt <= 3; $attempt++) {
                @unlink($temporaryFile);
                try {
                    $success = $sftp->get($remoteFile, $temporaryFile);
                    $remoteSize = $success ? $sftp->filesize($remoteFile) : false;
                    if ($success && $remoteSize !== false) {
                        $success = filesize($temporaryFile) === (int) $remoteSize;
                    }
                    if ($success && @rename($temporaryFile, $localFile)) {
                        break;
                    }
                    $success = false;
                } catch (\Throwable $e) {
                    $this->warn("Download attempt {$attempt} failed for {$fileName}: {$e->getMessage()}");
                }

                @unlink($temporaryFile);
                if ($attempt < 3) {
                    try {
                        $sftp = $connect();
                    } catch (\Throwable $e) {
                        $this->warn('ASM SFTP reconnect failed: ' . $e->getMessage());
                    }
                }
            }

            @unlink($temporaryFile);
            if ($success) {
                $downloaded[] = $fileName;
            } else {
                $failed[] = $fileName;
            }
        }

        $filesToImport = $localPending->concat($downloaded)->unique()->values();
        if ($filesToImport->isEmpty()) {
            return $this->failSync(
                $statusFile,
                'No ASM files were available after download.',
                $startedAt,
                $eligibleCount
            );
        }

        $stagingRelativePath = 'storage/app/asm-sync/' . now()->format('Ymd_His_u');
        $stagingDir = base_path($stagingRelativePath);
        if (!mkdir($stagingDir, 0775, true) && !is_dir($stagingDir)) {
            return $this->failSync(
                $statusFile,
                "Unable to create ASM staging directory: {$stagingDir}",
                $startedAt,
                $eligibleCount
            );
        }

        $staged = [];
        foreach ($filesToImport as $fileName) {
            if (@copy(
                $localDir . DIRECTORY_SEPARATOR . $fileName,
                $stagingDir . DIRECTORY_SEPARATOR . $fileName
            )) {
                $staged[] = $fileName;
            } else {
                $failed[] = $fileName;
            }
        }

        if ($staged === []) {
            $this->cleanupDirectory($stagingDir);

            return $this->failSync(
                $statusFile,
                'ASM files were downloaded but could not be staged for import.',
                $startedAt,
                $eligibleCount
            );
        }

        $this->writeProgress(
            $statusFile,
            'Importing downloaded ASM files...',
            0,
            count($staged),
            70,
            $startedAt,
            ['phase' => 'importing']
        );
        $importExitCode = Artisan::call('asm:import-settlements', [
            '--path' => $stagingRelativePath,
            '--pattern' => $pattern,
            '--chunk' => $chunkSize,
            '--trigger' => $this->trigger,
        ]);
        $this->output->write(Artisan::output());

        $importedAfterSync = AsmSettlementSummary::query()
            ->whereIn('source_file', $staged)
            ->pluck('source_file')
            ->all();
        $failed = array_values(array_unique(array_merge(
            $failed,
            array_values(array_diff($staged, $importedAfterSync))
        )));

        $mapExitCode = self::FAILURE;
        if ($importExitCode === self::SUCCESS) {
            $this->writeProgress(
                $statusFile,
                'Mapping ASM files to VieFund...',
                count($importedAfterSync),
                count($staged),
                90,
                $startedAt,
                ['phase' => 'mapping']
            );
            $mapExitCode = Artisan::call('asm:map-viefund');
            $this->output->write(Artisan::output());
        }

        if (!$keepLocal) {
            foreach ($importedAfterSync as $fileName) {
                @unlink($localDir . DIRECTORY_SEPARATOR . $fileName);
            }
        }
        $this->cleanupDirectory($stagingDir);

        $success = $importExitCode === self::SUCCESS && $mapExitCode === self::SUCCESS && $failed === [];
        $message = $success
            ? sprintf(
                'Completed ASM sync. Imported and mapped %d new files; %d remote files were already in the database.',
                count($importedAfterSync),
                $alreadyImported->count()
            )
            : sprintf(
                'ASM sync completed with issues: %d files imported and %d files failed; VieFund mapping %s.',
                count($importedAfterSync),
                count($failed),
                $mapExitCode === self::SUCCESS ? 'completed' : 'failed'
            );
        $this->finish(
            $statusFile,
            $success,
            $message,
            count($importedAfterSync),
            $eligibleCount,
            $startedAt
        );

        return $success ? self::SUCCESS : self::FAILURE;
    }

    private function localCandidates(string $directory, string $pattern): Collection
    {
        return collect((is_dir($directory) ? glob($directory . DIRECTORY_SEPARATOR . $pattern) : []) ?: [])
            ->map(fn($path) => basename($path))
            ->sort()
            ->values();
    }

    /** @param array<int, string> $configKeys */
    private function connectionOption(
        string $option,
        array $configKeys,
        ?string $default = null
    ): ?string {
        $optionValue = $this->stringOption($option);
        if ($optionValue !== null) {
            return $optionValue;
        }

        foreach ($configKeys as $configKey) {
            $configured = $this->stringValue(config($configKey));
            if ($configured !== null) {
                return $configured;
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

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function normalizeRemotePath(string $path): string
    {
        $path = '/' . trim($path, '/');

        return $path === '//' ? '/' : $path;
    }

    private function cleanupDirectory(string $directory): void
    {
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }

    private function writeProgress(
        ?string $statusFile,
        string $message,
        int $processed,
        ?int $total,
        int $percentage,
        string $startedAt,
        array $details = []
    ): void {
        $this->writeStatus($statusFile, array_merge([
            'inProgress' => true,
            'success' => null,
            'message' => $message,
            'processed_files' => $processed,
            'total_files' => $total,
            'progress_pct' => $percentage,
            'started_at' => $startedAt,
            'updated_at' => now()->toIso8601String(),
        ], $details));
    }

    private function finish(
        ?string $statusFile,
        bool $success,
        string $message,
        int $processed,
        int $total,
        string $startedAt,
        bool $dryRun = false
    ): void {
        $this->writeStatus($statusFile, [
            'inProgress' => false,
            'success' => $success,
            'dry_run' => $dryRun,
            'message' => $message,
            'processed_files' => $processed,
            'total_files' => $total,
            'progress_pct' => 100,
            'started_at' => $startedAt,
            'updated_at' => now()->toIso8601String(),
            'completed_at' => now()->toIso8601String(),
        ]);
        $success ? $this->info($message) : $this->error($message);
    }

    private function failSync(
        ?string $statusFile,
        string $message,
        string $startedAt,
        int $total = 0
    ): int {
        $this->finish($statusFile, false, $message, 0, $total, $startedAt);

        return self::FAILURE;
    }

    private function percentage(int $processed, int $total): int
    {
        return $total > 0 ? min(100, (int) floor(($processed / $total) * 100)) : 0;
    }

    private function writeStatus(?string $statusFile, array $payload): void
    {
        if ($statusFile) {
            $temporaryFile = $statusFile . '.tmp';
            $json = json_encode(array_merge([
                'run_id' => $this->runId,
                'trigger' => $this->trigger,
            ], $payload), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            if (@file_put_contents($temporaryFile, $json, LOCK_EX) !== false) {
                @rename($temporaryFile, $statusFile);
            }
        }

        if ($this->lockFile && file_exists($this->lockFile)) {
            @touch($this->lockFile);
        }
    }
}
