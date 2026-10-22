<?php

namespace App\Services\Bank;

use phpseclib3\Net\SFTP;
use RuntimeException;
use Throwable;

class BankSftpFileDownloader
{
    private ?SFTP $client = null;

    public function listFiles(string $pattern, ?string $remotePath = null): array
    {
        $directory = $this->normalizeRemotePath($remotePath ?? $this->configuredString('sftp.bank.remote_path') ?? '/');
        $files = $this->client()->nlist($directory);

        if ($files === false) {
            throw new RuntimeException("Unable to list bank SFTP directory: {$directory}");
        }

        return collect($files)
            ->map(fn(mixed $name) => basename((string) $name))
            ->filter(fn(string $name) => $name !== '' && $name !== '.' && $name !== '..')
            ->filter(fn(string $name) => basename($name) === $name)
            ->filter(fn(string $name) => fnmatch($pattern, $name))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array{path: string, directory: string, filename: string, remote_path: string}
     */
    public function download(string $filename, string $extension, ?string $remotePath = null): array
    {
        $normalizedExtension = strtolower(ltrim($extension, '.'));
        if (
            $filename === ''
            || basename($filename) !== $filename
            || in_array($filename, ['.', '..'], true)
            || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== $normalizedExtension
        ) {
            throw new RuntimeException(
                "The SFTP filename must be one plain .{$normalizedExtension} filename without a directory path."
            );
        }

        $directory = $this->normalizeRemotePath($remotePath ?? $this->configuredString('sftp.bank.remote_path') ?? '/');
        $temporaryDirectory = storage_path(
            'app/bank-sftp-import/' . now()->format('Ymd_His_u') . '_' . bin2hex(random_bytes(4))
        );
        if (
            !is_dir($temporaryDirectory)
            && !mkdir($temporaryDirectory, 0700, true)
            && !is_dir($temporaryDirectory)
        ) {
            throw new RuntimeException("Unable to create temporary directory: {$temporaryDirectory}");
        }

        $localPath = $temporaryDirectory . DIRECTORY_SEPARATOR . $filename;
        $partialPath = $localPath . '.part';
        $remoteFile = $directory === '/' ? "/{$filename}" : "{$directory}/{$filename}";

        try {
            $lastError = null;
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                try {
                    $client = $this->client();
                    $remoteSize = $client->filesize($remoteFile);
                    if ($remoteSize === false) {
                        throw new RuntimeException("Remote bank file was not found: {$remoteFile}");
                    }

                    if (!$client->get($remoteFile, $partialPath)) {
                        throw new RuntimeException("Unable to download remote bank file: {$remoteFile}");
                    }

                    $localSize = filesize($partialPath);
                    if ($localSize === false || $localSize !== $remoteSize) {
                        throw new RuntimeException(
                            "Downloaded file size mismatch for {$filename}: expected {$remoteSize} bytes, received "
                            . ($localSize === false ? 'an unreadable file' : "{$localSize} bytes") . '.'
                        );
                    }

                    if (!rename($partialPath, $localPath)) {
                        throw new RuntimeException("Unable to finalize the temporary bank file: {$localPath}");
                    }

                    $lastError = null;
                    break;
                } catch (Throwable $e) {
                    $lastError = $e;
                    $this->client = null;
                    if (is_file($partialPath) && !unlink($partialPath)) {
                        throw new RuntimeException(
                            "Unable to remove partial bank download: {$partialPath}",
                            0,
                            $e
                        );
                    }
                }
            }

            if ($lastError !== null) {
                throw $lastError;
            }
        } catch (Throwable $e) {
            try {
                $this->cleanup($temporaryDirectory);
            } catch (Throwable $cleanupError) {
                throw new RuntimeException(
                    $e->getMessage() . ' Temporary file cleanup also failed: ' . $cleanupError->getMessage(),
                    0,
                    $e
                );
            }

            throw $e;
        }

        return [
            'path' => $localPath,
            'directory' => $temporaryDirectory,
            'filename' => $filename,
            'remote_path' => $remoteFile,
        ];
    }

    public function cleanup(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file) && !unlink($file)) {
                throw new RuntimeException("Unable to delete temporary file: {$file}");
            }
        }

        if (is_dir($directory) && !rmdir($directory)) {
            throw new RuntimeException("Unable to remove temporary directory: {$directory}");
        }
    }

    private function client(): SFTP
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $host = $this->configuredString('sftp.bank.host');
        $username = $this->configuredString('sftp.bank.username');
        $password = $this->configuredString('sftp.bank.password');
        $port = (int) ($this->configuredString('sftp.bank.port') ?? '22');

        if ($host === null || $username === null || $password === null) {
            throw new RuntimeException(
                'Missing bank SFTP credentials. Set BANK_SFTP_HOST, BANK_SFTP_USERNAME, and BANK_SFTP_PASSWORD.'
            );
        }

        $client = new SFTP($host, $port);
        try {
            $loggedIn = $client->login($username, $password);
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Unable to connect to bank SFTP host {$host}:{$port}. " . trim($e->getMessage()),
                0,
                $e
            );
        }

        if (!$loggedIn) {
            throw new RuntimeException('Bank SFTP authentication failed.');
        }

        $this->client = $client;

        return $this->client;
    }

    private function configuredString(string $key): ?string
    {
        $value = config($key);
        if (is_array($value)) {
            $value = collect($value)->first(
                fn(mixed $candidate) => $candidate !== null && trim((string) $candidate) !== ''
            );
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeRemotePath(string $remotePath): string
    {
        $trimmed = trim($remotePath);
        if ($trimmed === '' || $trimmed === '/') {
            return '/';
        }

        return '/' . trim($trimmed, '/');
    }
}
