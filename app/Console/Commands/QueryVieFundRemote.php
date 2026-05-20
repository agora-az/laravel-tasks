<?php

namespace App\Console\Commands;

use App\Services\VieFund\VieFundRemoteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class QueryVieFundRemote extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'viefund:remote-query
                            {sql? : Read-only SQL query (SELECT/CTE) to run on remote SQL Server}
                            {--table= : Fetch sample rows from a table name}
                            {--schema= : Schema to query (defaults to VIEFUND_DB_SCHEMA)}
                            {--limit=20 : Row limit for --table mode}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run direct read-only SQL queries against remote VieFUND SQL Server';

    public function __construct(private readonly VieFundRemoteService $remoteService)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $sql = $this->argument('sql');
        $table = $this->option('table');
        $schema = $this->option('schema') ?: null;
        $limit = max(1, min((int) $this->option('limit'), 5000));

        if ($sql && $table) {
            $this->error('Use either {sql} or --table, not both.');
            return self::FAILURE;
        }

        $this->line('Testing remote SQL Server connection...');

        try {
            $this->remoteService->testConnection();
            $this->info('Connection successful.');
        } catch (Throwable $e) {
            $this->error('Connection failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        try {
            if ($table) {
                return $this->runTablePreview((string) $table, $limit, $schema);
            }

            if ($sql) {
                return $this->runSqlQuery((string) $sql);
            }

            $this->line('No query provided. Listing available tables:');
            $tables = $this->remoteService->discoverTables($schema);

            if ($tables->isEmpty()) {
                $this->warn('No tables found for the selected schema.');
                return self::SUCCESS;
            }

            $rows = $tables->map(fn ($t) => [(string) $t->TABLE_SCHEMA, (string) $t->TABLE_NAME])->all();
            $this->table(['Schema', 'Table'], $rows);
            $this->line('Use --table=<name> to preview rows or pass a SQL query as the first argument.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Query failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function runTablePreview(string $table, int $limit, ?string $schema): int
    {
        $this->line("Fetching up to {$limit} rows from {$table}...");

        $rows = $this->remoteService->fetchSampleRows($table, $limit, $schema);

        if ($rows->isEmpty()) {
            $this->warn('No rows returned.');
            return self::SUCCESS;
        }

        $this->renderRows($rows);

        return self::SUCCESS;
    }

    private function runSqlQuery(string $sql): int
    {
        if (!$this->isReadOnlySql($sql)) {
            $this->error('Only read-only SELECT/CTE queries are allowed.');
            return self::FAILURE;
        }

        $this->line('Running read-only SQL query remotely...');

        $rows = collect(DB::connection('viefund_sqlsrv')->select($sql));

        if ($rows->isEmpty()) {
            $this->warn('Query returned no rows.');
            return self::SUCCESS;
        }

        $this->renderRows($rows);

        return self::SUCCESS;
    }

    private function renderRows($rows): void
    {
        $first = (array) $rows->first();
        $headers = array_keys($first);

        $tableRows = $rows->map(function ($row) use ($headers) {
            $item = (array) $row;

            return array_map(
                fn ($header) => $this->formatCell($item[$header] ?? null),
                $headers
            );
        })->all();

        $this->table($headers, $tableRows);
        $this->line('Rows returned: ' . count($tableRows));
    }

    private function formatCell(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $string = (string) $value;

        return mb_strlen($string) > 140 ? mb_substr($string, 0, 137) . '...' : $string;
    }

    private function isReadOnlySql(string $sql): bool
    {
        $trimmed = trim($sql);
        if ($trimmed === '') {
            return false;
        }

        $normalized = strtolower($trimmed);

        if (!preg_match('/^(select|with)\b/', $normalized)) {
            return false;
        }

        if (preg_match('/\b(insert|update|delete|merge|drop|alter|truncate|create|exec|execute|grant|revoke)\b/', $normalized)) {
            return false;
        }

        // Prevent SELECT INTO patterns that write data.
        if (preg_match('/\binto\b/', $normalized)) {
            return false;
        }

        // Keep command execution to one statement for safety.
        if (substr_count($normalized, ';') > 0) {
            return false;
        }

        return true;
    }
}
