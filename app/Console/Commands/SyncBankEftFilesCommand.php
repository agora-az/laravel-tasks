<?php

namespace App\Console\Commands;

use App\Models\Import;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use phpseclib3\Net\SFTP;

class SyncBankEftFilesCommand extends Command
{
    protected $signature = 'bank:sync-eft-files
        {--host= : SFTP host (defaults to BANK_SFTP_HOST)}
        {--port= : SFTP port (defaults to BANK_SFTP_PORT or 22)}
        {--username= : SFTP username (defaults to BANK_SFTP_USERNAME)}
        {--password= : SFTP password (defaults to BANK_SFTP_PASSWORD)}
        {--remote-path=/EFT_Files : Remote bank EFT directory}
        {--local-path=storage/app/bank-eft-sync : Local download directory}
        {--pattern=CIBC1464.P*.dat : Filename glob}
        {--dry-run : List eligible files without downloading or importing}
        {--keep-local : Keep successfully imported local files}
        {--status-file= : Sync status JSON path}
        {--lock-file= : Sync lock path}
        {--run-id= : Identifier supplied by the initiating web session}
        {--trigger=Command line : Description of what initiated the sync}';

    protected $description = 'Download and import unprocessed fixed-width bank EFT files';

    private ?string $runId = null;

    private string $trigger = 'Command line';

    public function handle(): int
    {
        $lockFile = $this->stringOption('lock-file');
        $statusFile = $this->stringOption('status-file');
        $this->runId = $this->stringOption('run-id') ?? (string) Str::uuid();
        $this->trigger = $this->stringOption('trigger') ?? 'Command line';
        if ($lockFile) {
            @file_put_contents($lockFile, date('c'));
        }

        try {
            return $this->runSync($statusFile);
        } catch (\Throwable $e) {
            $this->writeStatus($statusFile, false, false, 'Bank EFT sync failed: ' . $e->getMessage());
            throw $e;
        } finally {
            if ($lockFile) {
                @unlink($lockFile);
            }
        }
    }

    private function runSync(?string $statusFile): int
    {
        $this->writeStatus($statusFile, true, null, 'Connecting to the bank EFT SFTP directory...');
        $host = $this->value('host', 'BANK_SFTP_HOST');
        $port = (int) ($this->value('port', 'BANK_SFTP_PORT', '22') ?? 22);
        $username = $this->value('username', 'BANK_SFTP_USERNAME');
        $password = $this->value('password', 'BANK_SFTP_PASSWORD');
        $remotePath = '/' . trim($this->stringOption('remote-path') ?? '/EFT_Files', '/');
        $localPath = $this->stringOption('local-path') ?? 'storage/app/bank-eft-sync';
        $pattern = $this->stringOption('pattern') ?? 'CIBC1464.P*.dat';
        $dryRun = (bool) $this->option('dry-run');
        $keepLocal = (bool) $this->option('keep-local');

        if (!$host || !$username || !$password) {
            return $this->failSync($statusFile, 'Missing BANK_SFTP_HOST, BANK_SFTP_USERNAME, or BANK_SFTP_PASSWORD.');
        }

        $localDir = base_path($localPath);
        if (!$dryRun && !is_dir($localDir) && !mkdir($localDir, 0775, true) && !is_dir($localDir)) {
            return $this->failSync($statusFile, "Unable to create {$localDir}.");
        }

        $connect = function () use ($host, $port, $username, $password): SFTP {
            $client = new SFTP($host, $port);
            if (!$client->login($username, $password)) {
                throw new \RuntimeException('SFTP authentication failed.');
            }
            return $client;
        };
        $sftp = $connect();
        $remoteNames = collect($sftp->nlist($remotePath) ?: [])
            ->map(fn($name) => basename((string) $name))
            ->filter(fn($name) => fnmatch($pattern, $name))
            ->unique()
            ->sort()
            ->values();
        $processed = Import::query()
            ->where('type', 'bank-eft-fixed-width')
            ->where('status', 'completed')
            ->pluck('filename')
            ->all();
        $eligible = $remoteNames->reject(fn($name) => in_array($name, $processed, true))->values();

        if ($dryRun) {
            $message = "Dry run complete: {$remoteNames->count()} bank EFT files found; {$eligible->count()} unprocessed.";
            $this->writeStatus($statusFile, false, true, $message);
            $this->info($message);
            return self::SUCCESS;
        }
        if ($eligible->isEmpty()) {
            $this->writeStatus($statusFile, false, true, 'No new unprocessed bank EFT files found.');
            $this->info('No new unprocessed bank EFT files found.');
            return self::SUCCESS;
        }

        $this->writeStatus($statusFile, true, null, "Downloading {$eligible->count()} unprocessed bank EFT files...");
        $downloaded = [];
        foreach ($eligible as $fileName) {
            $remoteFile = $remotePath . '/' . $fileName;
            $localFile = $localDir . DIRECTORY_SEPARATOR . $fileName;
            $temporaryFile = $localDir . DIRECTORY_SEPARATOR . '.download-' . $fileName . '.part';
            $complete = false;

            for ($attempt = 1; $attempt <= 3; $attempt++) {
                @unlink($temporaryFile);
                try {
                    $complete = $sftp->get($remoteFile, $temporaryFile);
                    $remoteSize = $complete ? $sftp->filesize($remoteFile) : false;
                    if ($complete && $remoteSize !== false) {
                        $complete = filesize($temporaryFile) === (int) $remoteSize;
                    }
                    if ($complete && @rename($temporaryFile, $localFile)) {
                        break;
                    }
                    $complete = false;
                } catch (\Throwable $e) {
                    $this->warn("Attempt {$attempt} failed for {$fileName}: {$e->getMessage()}");
                }
                @unlink($temporaryFile);
                if ($attempt < 3) {
                    $sftp = $connect();
                }
            }

            if (!$complete) {
                return $this->failSync($statusFile, "Unable to download {$fileName} completely after three attempts.");
            }
            $downloaded[] = $fileName;
        }

        $this->writeStatus($statusFile, true, null, 'Parsing and importing bank EFT files...');
        $exit = Artisan::call('bank:import-eft-files', [
            '--path' => $localPath,
            '--pattern' => $pattern,
        ]);
        $this->line(Artisan::output());
        if ($exit !== self::SUCCESS) {
            return $this->failSync($statusFile, 'One or more bank EFT files failed validation or import.');
        }

        if (!$keepLocal) {
            foreach ($downloaded as $fileName) {
                @unlink($localDir . DIRECTORY_SEPARATOR . $fileName);
            }
        }
        $message = 'Bank EFT sync completed. Imported ' . count($downloaded) . ' file(s).';
        $this->writeStatus($statusFile, false, true, $message);
        $this->info($message);

        return self::SUCCESS;
    }

    private function value(string $option, string $env, ?string $default = null): ?string
    {
        return $this->stringOption($option) ?? $this->clean(env($env)) ?? $default;
    }

    private function stringOption(string $option): ?string
    {
        return $this->clean($this->option($option));
    }

    private function clean(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function failSync(?string $statusFile, string $message): int
    {
        $this->writeStatus($statusFile, false, false, $message);
        $this->error($message);
        return self::FAILURE;
    }

    private function writeStatus(?string $path, bool $inProgress, ?bool $success, string $message): void
    {
        if (!$path) {
            return;
        }
        $existing = [
            'run_id' => $this->runId,
            'trigger' => $this->trigger,
            'started_at' => now()->toIso8601String(),
        ];
        if (is_file($path)) {
            $decoded = json_decode((string) @file_get_contents($path), true);
            if (is_array($decoded) && ($decoded['run_id'] ?? null) === $this->runId) {
                $existing = array_merge(
                    $existing,
                    array_intersect_key($decoded, array_flip(['run_id', 'trigger', 'started_at']))
                );
            }
        }

        @file_put_contents($path, json_encode(array_merge($existing, [
            'inProgress' => $inProgress,
            'success' => $success,
            'message' => $message,
            'updated_at' => now()->toIso8601String(),
            'completed_at' => !$inProgress ? now()->toIso8601String() : null,
        ]), JSON_PRETTY_PRINT));
    }
}
