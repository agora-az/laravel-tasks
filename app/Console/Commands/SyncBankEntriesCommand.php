<?php

namespace App\Console\Commands;

use App\Models\Import;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use phpseclib3\Net\SFTP;

class SyncBankEntriesCommand extends Command
{
    protected $signature = 'bank:sync-entries
        {--host= : SFTP host (defaults to BANK_SFTP_HOST)}
        {--port=22 : SFTP port (defaults to BANK_SFTP_PORT)}
        {--username= : SFTP username (defaults to BANK_SFTP_USERNAME)}
        {--password= : SFTP password (defaults to BANK_SFTP_PASSWORD)}
        {--remote-path=/ : Remote directory path (defaults to BANK_SFTP_REMOTE_PATH)}
        {--local-path=resources/data/cibc : Local directory for downloaded CAMT files}
        {--pattern= : Remote filename glob (defaults to BANK_SFTP_FILE_PATTERN)}
        {--parser=v2 : Parser version for analyze:bank-entries}
        {--skip-analysis : Skip analyze:bank-entries step}
        {--dry-run : Preview only (no download, import, analysis, or local file deletion)}
        {--keep-local : Keep processed files in local-path after successful sync}
        {--force : Import files even if filename already imported}
        {--rebuild : Truncate existing bank raw/analysis/summary tables before importing selected files}
        {--status-file= : Optional path to write sync progress JSON}
        {--lock-file= : Optional lock file path to mark sync in progress}
        {--run-id= : Identifier supplied by the initiating web session}
        {--trigger=Command line : Description of what initiated the sync}';

    protected $description = 'Download bank CAMT files from SFTP, import raw entries, and analyze parsed fields';

    private ?string $runId = null;

    private string $trigger = 'Command line';

    public function handle(): int
    {
        $lockFile = $this->resolveStringOptionValue($this->option('lock-file'));
        $statusFile = $this->resolveStringOptionValue($this->option('status-file'));
        $this->runId = $this->resolveStringOptionValue($this->option('run-id'));
        $this->trigger = $this->resolveStringOptionValue($this->option('trigger')) ?? 'Command line';
        if ($lockFile) {
            @file_put_contents($lockFile, date('c'));
        }

        try {
            return $this->runSync($statusFile);
        } catch (\Throwable $e) {
            $this->writeStatus($statusFile, [
                'inProgress' => false,
                'success' => false,
                'message' => 'Bank sync failed: ' . $e->getMessage(),
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
        $this->writeStatus($statusFile, [
            'inProgress' => true,
            'success' => null,
            'message' => 'Preparing bank sync...',
            'processed_files' => 0,
            'total_files' => null,
            'progress_pct' => 0,
            'started_at' => $startedAt,
            'updated_at' => now()->toIso8601String(),
        ]);

        $host = $this->resolveOption('host', 'sftp.bank.host');
        $port = (int) $this->resolveOption('port', 'sftp.bank.port', '22');
        $username = $this->resolveOption('username', 'sftp.bank.username');
        $password = $this->resolveOption('password', 'sftp.bank.password');
        $remotePath = $this->normalizeRemotePath($this->resolveOption('remote-path', 'sftp.bank.remote_path', '/'));
        $localPath = $this->resolveOption('local-path', 'sftp.bank.local_path', 'resources/data/cibc');
        $pattern = $this->resolveOption('pattern', 'sftp.bank.file_pattern', '*.xml');
        $parserVersion = $this->resolveStringOptionValue($this->option('parser')) ?? 'v2';
        $force = (bool) $this->option('force');
        $rebuild = (bool) $this->option('rebuild');
        $keepLocal = (bool) $this->option('keep-local');
        $dryRun = (bool) $this->option('dry-run') || (bool) config('sftp.bank.dry_run', false);

        if ($rebuild) {
            $force = true;
        }

        $localDir = base_path($localPath);
        if (!$dryRun && !is_dir($localDir) && !mkdir($localDir, 0775, true) && !is_dir($localDir)) {
            $this->error("Unable to create local directory: {$localDir}");
            $this->writeStatus($statusFile, [
                'inProgress' => false,
                'success' => false,
                'message' => "Unable to create local directory: {$localDir}",
                'updated_at' => now()->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
            ]);
            return self::FAILURE;
        }

        if (!$host || !$username || !$password) {
            $this->error('Missing SFTP credentials. Set BANK_SFTP_HOST, BANK_SFTP_USERNAME, and BANK_SFTP_PASSWORD.');
            $this->writeStatus($statusFile, [
                'inProgress' => false,
                'success' => false,
                'message' => 'Missing SFTP credentials. Set BANK_SFTP_HOST, BANK_SFTP_USERNAME, and BANK_SFTP_PASSWORD.',
                'updated_at' => now()->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
            ]);
            return self::FAILURE;
        }

        $completedImportNames = Import::query()
            ->where('type', 'bank-camt-raw')
            ->where('status', 'completed')
            ->distinct()
            ->pluck('filename')
            ->all();

        $processedNames = Import::query()
            ->where('type', 'bank-camt-raw')
            ->where('status', 'completed')
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('bank_statement_summaries')
                    ->whereColumn('bank_statement_summaries.import_id', 'imports.id');
            })
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('bank_statement_entries')
                    ->whereColumn('bank_statement_entries.source_file', 'imports.filename')
                    ->whereNull('bank_statement_entries.bank_statement_summary_id');
            })
            ->whereNotExists(function ($query) use ($parserVersion) {
                $query->selectRaw('1')
                    ->from('bank_statement_entries as entries_without_analysis')
                    ->whereColumn('entries_without_analysis.source_file', 'imports.filename')
                    ->whereNotExists(function ($analysisQuery) use ($parserVersion) {
                        $analysisQuery->selectRaw('1')
                            ->from('bank_statement_entry_analyses as required_analysis')
                            ->whereColumn('required_analysis.bank_statement_entry_id', 'entries_without_analysis.id')
                            ->where('required_analysis.parser_version', $parserVersion);
                    });
            })
            ->distinct()
            ->pluck('filename')
            ->all();

        if ($rebuild) {
            $processedNames = [];
        }

        $localCandidates = collect((is_dir($localDir) ? glob($localDir . '/' . $pattern) : []) ?: [])
            ->map(fn($path) => basename($path))
            ->sort()
            ->values();

        $localProcessed = $localCandidates
            ->filter(fn($name) => in_array($name, $processedNames, true))
            ->values();

        $deletedProcessedLocalCount = 0;
        if (!$dryRun && !$keepLocal && $localProcessed->isNotEmpty()) {
            foreach ($localProcessed as $fileName) {
                $localFilePath = $localDir . DIRECTORY_SEPARATOR . $fileName;
                if (is_file($localFilePath) && @unlink($localFilePath)) {
                    $deletedProcessedLocalCount++;
                }
            }

            if ($deletedProcessedLocalCount > 0) {
                $this->info("Deleted {$deletedProcessedLocalCount} already-processed local file(s).");
            }

            // Refresh local candidates after cleanup.
            $localCandidates = collect((is_dir($localDir) ? glob($localDir . '/' . $pattern) : []) ?: [])
                ->map(fn($path) => basename($path))
                ->sort()
                ->values();
        }

        $localPending = $force
            ? $localCandidates
            : $localCandidates->reject(fn($name) => in_array($name, $processedNames, true))->values();

        $sftp = new SFTP($host, $port);
        try {
            $loggedIn = $sftp->login($username, $password);
        } catch (\Throwable $e) {
            $connectMessage = "Unable to connect to SFTP host {$host}:{$port}. " . trim($e->getMessage());
            if (str_contains(strtolower($e->getMessage()), 'identification string')) {
                $connectMessage .= ' The remote server closed the SSH handshake. Verify your public IP is allowlisted and the SFTP account is active.';
            }

            $this->error($connectMessage);
            $this->writeStatus($statusFile, [
                'inProgress' => false,
                'success' => false,
                'message' => $connectMessage,
                'updated_at' => now()->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
            ]);
            return self::FAILURE;
        }

        if (!$loggedIn) {
            $this->error('SFTP authentication failed.');
            $this->writeStatus($statusFile, [
                'inProgress' => false,
                'success' => false,
                'message' => 'SFTP authentication failed.',
                'updated_at' => now()->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
            ]);
            return self::FAILURE;
        }

        $remoteFiles = $sftp->nlist($remotePath);
        if ($remoteFiles === false) {
            $this->error("Unable to list remote path: {$remotePath}");
            $this->writeStatus($statusFile, [
                'inProgress' => false,
                'success' => false,
                'message' => "Unable to list remote path: {$remotePath}",
                'updated_at' => now()->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
            ]);
            return self::FAILURE;
        }

        $candidateFiles = collect($remoteFiles)
            ->map(fn($name) => basename((string) $name))
            ->filter(fn($name) => $name !== '' && $name !== '.' && $name !== '..')
            ->filter(fn($name) => fnmatch($pattern, (string) $name))
            ->unique()
            ->sort()
            ->values();

        $repairCandidateNames = $rebuild
            ? collect()
            : $candidateFiles
                ->filter(fn($name) => in_array($name, $completedImportNames, true))
                ->reject(fn($name) => in_array($name, $processedNames, true))
                ->values();

        if ($candidateFiles->isEmpty()) {
            $this->info("No remote files matched pattern {$pattern}.");
            $this->writeStatus($statusFile, [
                'inProgress' => false,
                'success' => true,
                'message' => "No remote files matched pattern {$pattern}.",
                'processed_files' => 0,
                'total_files' => 0,
                'progress_pct' => 100,
                'started_at' => $startedAt,
                'updated_at' => now()->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
            ]);
            return self::SUCCESS;
        }

        $toDownload = $force
            ? $candidateFiles->reject(fn($name) => $localCandidates->contains($name))->values()
            : $candidateFiles
            ->reject(fn($name) => in_array($name, $processedNames, true))
            ->reject(fn($name) => $localCandidates->contains($name))
            ->values();

        if ($dryRun) {
            $localPendingCount = $localPending->count();
            $toDownloadCount = $toDownload->count();
            $eligibleCount = $localPendingCount + $toDownloadCount;
            $alreadyProcessedCount = $candidateFiles
                ->filter(fn($name) => in_array($name, $processedNames, true))
                ->count();

            $message = sprintf(
                'Dry run complete. %d remote files matched, %d complete, %d need summary repair, %d eligible to process (%d local pending, %d to download). No files were downloaded or imported.',
                $candidateFiles->count(),
                $alreadyProcessedCount,
                $repairCandidateNames->count(),
                $eligibleCount,
                $localPendingCount,
                $toDownloadCount
            );

            $this->info($message);
            $this->writeStatus($statusFile, [
                'inProgress' => false,
                'success' => true,
                'dry_run' => true,
                'message' => $message,
                'processed_files' => 0,
                'total_files' => $eligibleCount,
                'progress_pct' => 100,
                'started_at' => $startedAt,
                'updated_at' => now()->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
            ]);

            return self::SUCCESS;
        }

        $totalFiles = $localPending->count() + $toDownload->count();
        if ($totalFiles === 0) {
            $this->info('No new unprocessed bank statement files found.');
            $this->writeStatus($statusFile, [
                'inProgress' => false,
                'success' => true,
                'message' => 'No new unprocessed bank statement files found.',
                'processed_files' => 0,
                'total_files' => 0,
                'progress_pct' => 100,
                'started_at' => $startedAt,
                'updated_at' => now()->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
            ]);
            return self::SUCCESS;
        }

        $this->writeStatus($statusFile, [
            'inProgress' => true,
            'success' => null,
            'message' => 'Downloading new or repairable files from SFTP...',
            'processed_files' => 0,
            'total_files' => $totalFiles,
            'progress_pct' => 0,
            'started_at' => $startedAt,
            'updated_at' => now()->toIso8601String(),
        ]);

        $downloaded = [];
        $failed = [];

        foreach ($toDownload as $fileName) {
            $remoteFilePath = $remotePath === '/' ? "/{$fileName}" : "{$remotePath}/{$fileName}";
            $localFilePath = $localDir . DIRECTORY_SEPARATOR . $fileName;
            if (!$sftp->get($remoteFilePath, $localFilePath)) {
                $failed[] = $fileName;
                $this->warn("Failed to download remote file: {$fileName}");
                continue;
            }

            $downloaded[] = $fileName;
            $this->line("Downloaded {$fileName}");
        }

        $filesToProcess = $localPending->concat(collect($downloaded))->unique()->values();

        if ($filesToProcess->isEmpty()) {
            $this->error('No local files available to process after download step.');
            $this->writeStatus($statusFile, [
                'inProgress' => false,
                'success' => false,
                'message' => 'No local files available to process after download step.',
                'processed_files' => 0,
                'total_files' => $totalFiles,
                'progress_pct' => 0,
                'started_at' => $startedAt,
                'updated_at' => now()->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
            ]);
            return self::FAILURE;
        }

        $runId = now()->format('Ymd_His');
        $stagingRelativeDir = "storage/app/bank-sync/{$runId}";
        $stagingDir = base_path($stagingRelativeDir);
        if (!is_dir($stagingDir) && !mkdir($stagingDir, 0775, true) && !is_dir($stagingDir)) {
            $this->error("Unable to create staging directory: {$stagingDir}");
            $this->writeStatus($statusFile, [
                'inProgress' => false,
                'success' => false,
                'message' => "Unable to create staging directory: {$stagingDir}",
                'processed_files' => 0,
                'total_files' => $totalFiles,
                'progress_pct' => 0,
                'started_at' => $startedAt,
                'updated_at' => now()->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
            ]);
            return self::FAILURE;
        }

        $stagedFiles = [];
        foreach ($filesToProcess as $fileName) {
            $sourcePath = $localDir . DIRECTORY_SEPARATOR . $fileName;
            $targetPath = $stagingDir . DIRECTORY_SEPARATOR . $fileName;
            if (is_file($sourcePath) && @copy($sourcePath, $targetPath)) {
                $stagedFiles[] = $fileName;
            } else {
                $failed[] = $fileName;
                $this->warn("Failed to stage local file: {$fileName}");
            }
        }

        if (empty($stagedFiles)) {
            $this->error('No files were staged successfully for import.');
            $this->cleanupDirectory($stagingDir);
            $this->writeStatus($statusFile, [
                'inProgress' => false,
                'success' => false,
                'message' => 'No files were staged successfully for import.',
                'processed_files' => 0,
                'total_files' => $totalFiles,
                'progress_pct' => 0,
                'started_at' => $startedAt,
                'updated_at' => now()->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
            ]);
            return self::FAILURE;
        }

        $this->writeStatus($statusFile, [
            'inProgress' => true,
            'success' => null,
            'message' => 'Importing CAMT files...',
            'processed_files' => 0,
            'total_files' => count($stagedFiles),
            'progress_pct' => 10,
            'started_at' => $startedAt,
            'updated_at' => now()->toIso8601String(),
        ]);

        $importExit = Artisan::call('import:cibc-camt-raw', [
            '--path' => $stagingRelativeDir,
            '--pattern' => '*.xml',
            '--truncate' => $rebuild,
            '--replace-existing' => true,
        ]);

        $this->line(Artisan::output());

        $rawImportFailed = $importExit !== 0;
        if ($rawImportFailed) {
            $this->warn('Raw CAMT import completed with one or more file failures. Continuing analysis so successfully imported files remain usable.');
        }

        $processedCount = 0;
        $analysisFailures = 0;
        if (!$this->option('skip-analysis')) {
            foreach ($stagedFiles as $fileName) {
                $analyzeExit = Artisan::call('analyze:bank-entries', [
                    '--parser' => $parserVersion,
                    '--source-file' => $fileName,
                ]);

                $this->line(Artisan::output());

                if ($analyzeExit !== 0) {
                    $this->warn("Analysis failed for {$fileName}");
                    $analysisFailures++;
                }

                $processedCount++;
                $progressPct = (int) floor(($processedCount / max(1, count($stagedFiles))) * 100);
                $this->writeStatus($statusFile, [
                    'inProgress' => true,
                    'success' => null,
                    'message' => "Analyzing {$fileName} ({$processedCount}/" . count($stagedFiles) . ')',
                    'processed_files' => $processedCount,
                    'total_files' => count($stagedFiles),
                    'progress_pct' => min(99, $progressPct),
                    'started_at' => $startedAt,
                    'updated_at' => now()->toIso8601String(),
                ]);
            }
        } else {
            $processedCount = count($stagedFiles);
        }

        if (!$keepLocal) {
            foreach ($stagedFiles as $fileName) {
                $localFilePath = $localDir . DIRECTORY_SEPARATOR . $fileName;
                if (is_file($localFilePath)) {
                    @unlink($localFilePath);
                }
            }
        }

        $this->cleanupDirectory($stagingDir);

        $repairedCount = collect($stagedFiles)
            ->filter(fn($name) => $repairCandidateNames->contains($name))
            ->count();
        $newFileCount = count($stagedFiles) - $repairedCount;

        $this->info('Bank entries sync complete.');
        $this->info('Processed files: ' . count($stagedFiles));
        $this->info('Repaired files: ' . $repairedCount);
        $this->info('New files: ' . $newFileCount);
        $this->info('Downloaded from SFTP this run: ' . count($downloaded));
        if ($deletedProcessedLocalCount > 0) {
            $this->info('Deleted already-processed local files: ' . $deletedProcessedLocalCount);
        }
        if (!empty($failed)) {
            $this->warn('Failed downloads: ' . count($failed));
        }

        $success = !$rawImportFailed && empty($failed) && $analysisFailures === 0;
        $this->writeStatus($statusFile, [
            'inProgress' => false,
            'success' => $success,
            'message' => $success
                ? sprintf('Completed bank sync. Processed %d files (%d repaired, %d new), deleted %d already-processed local files.', count($stagedFiles), $repairedCount, $newFileCount, $deletedProcessedLocalCount)
                : sprintf('Completed with issues. Processed %d files (%d repaired, %d new), deleted %d already-processed local files, raw import failure: %s, %d download/stage failures, %d analysis failures.', count($stagedFiles), $repairedCount, $newFileCount, $deletedProcessedLocalCount, $rawImportFailed ? 'yes' : 'no', count($failed), $analysisFailures),
            'processed_files' => count($stagedFiles),
            'total_files' => count($stagedFiles),
            'repaired_files' => $repairedCount,
            'new_files' => $newFileCount,
            'raw_import_failed' => $rawImportFailed,
            'progress_pct' => 100,
            'started_at' => $startedAt,
            'updated_at' => now()->toIso8601String(),
            'completed_at' => now()->toIso8601String(),
        ]);

        return $success ? self::SUCCESS : self::FAILURE;
    }

    private function writeStatus(?string $statusFile, array $payload): void
    {
        if (!$statusFile) {
            return;
        }

        @file_put_contents($statusFile, json_encode(array_merge([
            'run_id' => $this->runId,
            'trigger' => $this->trigger,
        ], $payload), JSON_PRETTY_PRINT));
    }

    private function resolveOption(string $option, string $configKey, ?string $default = null): ?string
    {
        $value = $this->option($option);
        $value = $this->resolveStringOptionValue($value);
        if ($value !== null && $value !== '') {
            return (string) $value;
        }

        $configuredValue = $this->resolveStringOptionValue(config($configKey));
        if ($configuredValue !== null && $configuredValue !== '') {
            return $configuredValue;
        }

        return $default;
    }

    private function resolveStringOptionValue(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = collect($value)
                ->first(fn($v) => $v !== null && $v !== '');
        }

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function normalizeRemotePath(string $remotePath): string
    {
        $trimmed = trim($remotePath);
        if ($trimmed === '' || $trimmed === '/') {
            return '/';
        }

        return '/' . trim($trimmed, '/');
    }

    private function cleanupDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/*') ?: [];
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($dir);
    }
}
