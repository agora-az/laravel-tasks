<?php

namespace App\Console\Commands;

use App\Models\BankStatementEntry;
use App\Models\BankStatementSummary;
use App\Models\Import;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportCibcCamtRawCommand extends Command
{
    protected $signature = 'import:cibc-camt-raw
        {--path=resources/data/cibc : Directory containing CAMT XML files}
        {--pattern=*.xml : Glob pattern for files}
        {--truncate : Truncate bank_statement_entries before import}
        {--chunk=500 : Insert chunk size}';

    protected $description = 'Import CIBC CAMT XML bank statements into raw storage with no dedupe/filtering';

    public function handle(): int
    {
        $pathOption = $this->scalarOption('path', 'resources/data/cibc');
        $patternOption = $this->scalarOption('pattern', '*.xml');
        $chunkOption = $this->scalarOption('chunk', '500');

        $directory = base_path($pathOption);
        $pattern = (string) $patternOption;
        $chunkSize = max(1, (int) $chunkOption);

        if (!is_dir($directory)) {
            $this->error("Directory not found: {$directory}");
            return self::FAILURE;
        }

        $files = glob($directory . '/' . $pattern);
        sort($files);

        if (empty($files)) {
            $this->warn("No files matched {$pattern} in {$directory}");
            return self::SUCCESS;
        }

        if ((bool) $this->option('truncate')) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            DB::table('bank_statement_entry_analyses')->truncate();
            DB::table('bank_statement_entries')->truncate();
            DB::table('bank_statement_summary_balances')->truncate();
            DB::table('bank_statement_summaries')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            $this->info('Truncated bank_statement_entries, bank_statement_summaries, and bank_statement_summary_balances');
        }

        $totalInserted = 0;
        $totalErrors = 0;

        foreach ($files as $filePath) {
            $this->line('Processing ' . basename($filePath));

            $import = Import::create([
                'type' => 'bank-camt-raw',
                'filename' => basename($filePath),
                'file_size' => filesize($filePath) ?: 0,
                'status' => 'processing',
                'import_started_at' => now(),
            ]);

            try {
                $inserted = $this->importSingleFile($filePath, $import->id, $chunkSize);

                $import->update([
                    'status' => 'completed',
                    'total_rows' => $inserted,
                    'imported_count' => $inserted,
                    'error_count' => 0,
                    'import_completed_at' => now(),
                ]);

                $totalInserted += $inserted;
                $this->info('Inserted ' . $inserted . ' entries from ' . basename($filePath));
            } catch (\Throwable $e) {
                $totalErrors++;

                $import->update([
                    'status' => 'failed',
                    'error_count' => 1,
                    'error_details' => $e->getMessage(),
                    'import_completed_at' => now(),
                ]);

                $this->error('Failed ' . basename($filePath) . ': ' . $e->getMessage());
            }
        }

        $this->newLine();
        $this->info('Done. Inserted entries: ' . $totalInserted);
        if ($totalErrors > 0) {
            $this->warn('Files with errors: ' . $totalErrors);
        }

        return $totalErrors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function importSingleFile(string $filePath, int $importId, int $chunkSize): int
    {
        $xml = simplexml_load_file($filePath, 'SimpleXMLElement', LIBXML_NOCDATA);

        if ($xml === false) {
            throw new \RuntimeException('Invalid XML');
        }

        $namespaces = $xml->getDocNamespaces(true);
        $defaultNs = $namespaces[''] ?? null;

        if (!$defaultNs) {
            throw new \RuntimeException('Missing default XML namespace');
        }

        $xml->registerXPathNamespace('c', $defaultNs);

        $messageId = $this->xpathString($xml, '/c:Document/c:BkToCstmrStmt/c:GrpHdr/c:MsgId');
        $groupCreatedAt = $this->parseDateTime($this->xpathString($xml, '/c:Document/c:BkToCstmrStmt/c:GrpHdr/c:CreDtTm'));
        $statements = $xml->xpath('/c:Document/c:BkToCstmrStmt/c:Stmt') ?: [];

        $batch = [];
        $inserted = 0;

        foreach ($statements as $statementIndex => $statement) {
            $statement->registerXPathNamespace('c', $defaultNs);

            $statementId = $this->xpathString($statement, './c:Id');
            $statementCreatedAt = $this->parseDateTime($this->xpathString($statement, './c:CreDtTm'));
            $accountNumber = $this->xpathString($statement, './c:Acct/c:Id/c:Othr/c:Id');
            $balances = $this->extractStatementBalances($statement);
            $openingBalance = $this->firstBalanceByCode($balances, 'OPBD');
            $closingBalance = $this->firstBalanceByCode($balances, 'CLBD');

            $summary = BankStatementSummary::create([
                'import_id' => $importId,
                'source_file' => basename($filePath),
                'statement_index' => $statementIndex,
                'message_id' => $this->nullIfEmpty($messageId),
                'group_created_at' => $groupCreatedAt,
                'statement_id' => $this->nullIfEmpty($statementId),
                'statement_created_at' => $statementCreatedAt,
                'account_number' => $this->nullIfEmpty($accountNumber),
                'opening_balance_type_code' => $openingBalance['balance_type_code'] ?? null,
                'opening_balance_amount' => $openingBalance['amount'] ?? null,
                'opening_balance_currency' => $openingBalance['currency'] ?? null,
                'opening_balance_indicator' => $openingBalance['credit_debit_indicator'] ?? null,
                'opening_balance_signed_amount' => $openingBalance['signed_amount'] ?? null,
                'opening_balance_date' => $openingBalance['balance_date'] ?? null,
                'closing_balance_type_code' => $closingBalance['balance_type_code'] ?? null,
                'closing_balance_amount' => $closingBalance['amount'] ?? null,
                'closing_balance_currency' => $closingBalance['currency'] ?? null,
                'closing_balance_indicator' => $closingBalance['credit_debit_indicator'] ?? null,
                'closing_balance_signed_amount' => $closingBalance['signed_amount'] ?? null,
                'closing_balance_date' => $closingBalance['balance_date'] ?? null,
                'total_credit_entries' => $this->parseInteger($this->xpathString($statement, './c:TxsSummry/c:TtlCdtNtries/c:NbOfNtries')),
                'total_credit_sum' => $this->parseAmount((string) $this->xpathString($statement, './c:TxsSummry/c:TtlCdtNtries/c:Sum')),
                'total_debit_entries' => $this->parseInteger($this->xpathString($statement, './c:TxsSummry/c:TtlDbtNtries/c:NbOfNtries')),
                'total_debit_sum' => $this->parseAmount((string) $this->xpathString($statement, './c:TxsSummry/c:TtlDbtNtries/c:Sum')),
                'raw_statement_xml' => $statement->asXML() ?: null,
                'raw_statement_json' => json_encode($this->xmlToArray($statement)),
            ]);

            if (!empty($balances)) {
                $summary->balances()->createMany($balances);
            }

            $entries = $statement->xpath('./c:Ntry') ?: [];

            foreach ($entries as $index => $entry) {
                $entry->registerXPathNamespace('c', $defaultNs);

                $amountNode = $entry->xpath('./c:Amt')[0] ?? null;
                $amount = $amountNode ? $this->parseAmount((string) $amountNode) : null;
                $currency = $amountNode ? (string) ($amountNode->attributes()['Ccy'] ?? null) : null;

                $additionalInfoNodes = $entry->xpath('./c:NtryDtls/c:TxDtls/c:AddtlTxInf') ?: [];
                $additionalInfo = $this->joinXmlTextNodes($additionalInfoNodes);

                $record = [
                    'import_id' => $importId,
                    'bank_statement_summary_id' => $summary->id,
                    'source_file' => basename($filePath),
                    'message_id' => $this->nullIfEmpty($messageId),
                    'statement_id' => $this->nullIfEmpty($statementId),
                    'account_number' => $this->nullIfEmpty($accountNumber),
                    'entry_index' => $index,
                    'entry_reference' => $this->nullIfEmpty($this->xpathString($entry, './c:NtryRef')),
                    'booking_date' => $this->parseDate($this->xpathString($entry, './c:BookgDt/c:Dt')),
                    'value_date' => $this->parseDate($this->xpathString($entry, './c:ValDt/c:Dt')),
                    'credit_debit_indicator' => $this->nullIfEmpty($this->xpathString($entry, './c:CdtDbtInd')),
                    'status' => $this->nullIfEmpty($this->xpathString($entry, './c:Sts')),
                    'currency' => $this->nullIfEmpty($currency),
                    'amount' => $amount,
                    'bank_domain_code' => $this->nullIfEmpty($this->xpathString($entry, './c:BkTxCd/c:Domn/c:Cd')),
                    'bank_family_code' => $this->nullIfEmpty($this->xpathString($entry, './c:BkTxCd/c:Domn/c:Fmly/c:Cd')),
                    'bank_sub_family_code' => $this->nullIfEmpty($this->xpathString($entry, './c:BkTxCd/c:Domn/c:Fmly/c:SubFmlyCd')),
                    'additional_info' => $this->nullIfEmpty($additionalInfo),
                    'raw_xml' => $entry->asXML() ?: null,
                    'raw_json' => json_encode($this->xmlToArray($entry)),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $batch[] = $record;

                if (count($batch) >= $chunkSize) {
                    BankStatementEntry::insert($batch);
                    $inserted += count($batch);
                    $batch = [];
                }
            }
        }

        if (!empty($batch)) {
            BankStatementEntry::insert($batch);
            $inserted += count($batch);
        }

        return $inserted;
    }

    private function xpathString(\SimpleXMLElement $node, string $path): ?string
    {
        $result = $node->xpath($path);
        if (!$result || !isset($result[0])) {
            return null;
        }

        return trim((string) $result[0]);
    }

    private function parseDate(?string $date): ?string
    {
        $value = trim((string) $date);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function parseDateTime(?string $dateTime): ?string
    {
        $value = trim((string) $dateTime);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function parseAmount(string $amount): ?float
    {
        $value = trim($amount);
        if ($value === '') {
            return null;
        }

        $normalized = str_replace([',', ' '], '', $value);

        if (!is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    private function parseInteger(?string $value): ?int
    {
        $normalized = trim((string) $value);
        if ($normalized === '' || !preg_match('/^-?\d+$/', $normalized)) {
            return null;
        }

        return (int) $normalized;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractStatementBalances(\SimpleXMLElement $statement): array
    {
        $balances = [];
        $balanceNodes = $statement->xpath('./c:Bal') ?: [];
        $docNamespaces = $statement->getDocNamespaces(true);
        $defaultNamespace = $docNamespaces[''] ?? null;

        foreach ($balanceNodes as $index => $balanceNode) {
            if ($defaultNamespace) {
                $balanceNode->registerXPathNamespace('c', $defaultNamespace);
            }

            $amountNode = $balanceNode->xpath('./c:Amt')[0] ?? null;
            $amount = $amountNode ? $this->parseAmount((string) $amountNode) : null;
            $currency = $amountNode ? $this->nullIfEmpty((string) ($amountNode->attributes()['Ccy'] ?? null)) : null;
            $indicator = $this->nullIfEmpty($this->xpathString($balanceNode, './c:CdtDbtInd'));
            $typeCode = $this->nullIfEmpty(
                $this->xpathString($balanceNode, './c:Tp/c:CdOrPrtry/c:Cd')
                ?? $this->xpathString($balanceNode, './c:Tp/c:CdOrPrtry/c:Prtry')
            );
            $balanceDate = $this->parseDate($this->xpathString($balanceNode, './c:Dt/c:Dt'));

            $balances[] = [
                'balance_index' => $index,
                'balance_type_code' => $typeCode,
                'amount' => $amount,
                'currency' => $currency,
                'credit_debit_indicator' => $indicator,
                'signed_amount' => $this->signedAmount($amount, $indicator),
                'balance_date' => $balanceDate,
                'raw_json' => $this->xmlToArray($balanceNode),
            ];
        }

        return $balances;
    }

    /**
     * @param array<int, array<string, mixed>> $balances
     * @return array<string, mixed>|null
     */
    private function firstBalanceByCode(array $balances, string $typeCode): ?array
    {
        foreach ($balances as $balance) {
            if (strtoupper((string) ($balance['balance_type_code'] ?? '')) === strtoupper($typeCode)) {
                return $balance;
            }
        }

        return null;
    }

    private function signedAmount(?float $amount, ?string $indicator): ?float
    {
        if ($amount === null) {
            return null;
        }

        $normalizedIndicator = strtoupper(trim((string) $indicator));
        if ($normalizedIndicator === 'DBIT') {
            return $amount * -1;
        }

        return $amount;
    }

    private function joinXmlTextNodes(array $nodes): ?string
    {
        if (empty($nodes)) {
            return null;
        }

        $parts = [];
        foreach ($nodes as $node) {
            $text = trim((string) $node);
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return empty($parts) ? null : implode(' | ', $parts);
    }

    private function xmlToArray(\SimpleXMLElement $node): array
    {
        $json = json_encode($node);
        if ($json === false) {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function nullIfEmpty(?string $value): ?string
    {
        $v = trim((string) $value);
        return $v === '' ? null : $v;
    }

    private function scalarOption(string $name, string $default = ''): string
    {
        $value = $this->option($name);

        if (is_array($value)) {
            $value = $value[0] ?? $default;
        }

        if ($value === null) {
            return $default;
        }

        return (string) $value;
    }
}
