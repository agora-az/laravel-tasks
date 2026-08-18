<?php

namespace App\Console\Commands;

use App\Models\Import;
use App\Models\SettlementInstruction;
use App\Models\SettlementInstructionSummary;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportSettlementInstructionsCommand extends Command
{
    protected $signature = 'import:settlement-instructions
        {--path=resources/data/cibc : Directory containing settlement instruction files}
        {--pattern=FSP* : Glob pattern for files}
        {--truncate : Truncate settlement instructions table before import}
        {--chunk=500 : Insert chunk size}';

    protected $description = 'Import FundSERV/LTM settlement instruction files into raw storage';

    public function handle(): int
    {
        $directory = base_path((string) $this->option('path'));
        $pattern = (string) $this->option('pattern');
        $chunkSize = max(1, (int) $this->option('chunk'));

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
            SettlementInstruction::query()->truncate();
            SettlementInstructionSummary::query()->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            $this->info('Truncated settlement_instructions');
        }

        $totalInserted = 0;
        $errors = 0;

        foreach ($files as $filePath) {
            $filename = basename($filePath);
            $this->line("Processing {$filename}");

            $import = Import::create([
                'type' => 'settlement-instructions-raw',
                'filename' => $filename,
                'file_size' => filesize($filePath) ?: 0,
                'status' => 'processing',
                'import_started_at' => now(),
            ]);

            try {
                $inserted = $this->importSingleFile($filePath, (int) $import->id, $chunkSize);

                $import->update([
                    'status' => 'completed',
                    'total_rows' => $inserted,
                    'imported_count' => $inserted,
                    'error_count' => 0,
                    'import_completed_at' => now(),
                ]);

                $totalInserted += $inserted;
                $this->info("Inserted {$inserted} records from {$filename}");
            } catch (\Throwable $e) {
                $errors++;
                $import->update([
                    'status' => 'failed',
                    'error_count' => 1,
                    'error_details' => $e->getMessage(),
                    'import_completed_at' => now(),
                ]);
                $this->error("Failed {$filename}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Done. Inserted records: {$totalInserted}");
        if ($errors > 0) {
            $this->warn("Files with errors: {$errors}");
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function importSingleFile(string $filePath, int $importId, int $chunkSize): int
    {
        $previousUseInternalErrors = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($filePath, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseInternalErrors);

        if ($xml === false) {
            throw new \RuntimeException('Invalid XML');
        }

        $namespaces = $xml->getDocNamespaces(true);
        $defaultNs = $namespaces[''] ?? null;
        if (!$defaultNs) {
            throw new \RuntimeException('Missing default XML namespace');
        }

        $xml->registerXPathNamespace('f', $defaultNs);
        $records = $xml->xpath('/f:SettlInstr/f:SettlRec') ?: [];

        $sourceFile = basename($filePath);
        $sourceType = str_contains(strtoupper($sourceFile), 'AGRA') ? 'fundserv_agra' : 'ltm';

        $summary = SettlementInstructionSummary::create([
            'import_id' => $importId,
            'source_file' => $sourceFile,
            'source_type' => $sourceType,
            'record_count' => 0,
            'raw_summary_json' => [
                'path' => $filePath,
                'parser' => 'import:settlement-instructions',
            ],
        ]);

        $batch = [];
        $inserted = 0;
        $recordCount = 0;
        $totalGross = 0.0;
        $totalNet = 0.0;
        $totalSettlement = 0.0;
        $minCreateDate = null;
        $maxCreateDate = null;
        $minTradeDate = null;
        $maxTradeDate = null;
        $minSettlementDate = null;
        $maxSettlementDate = null;

        foreach ($records as $index => $record) {
            $record->registerXPathNamespace('f', $defaultNs);

            $buyCon = $record->xpath('./f:BuyCon')[0] ?? null;
            $sellCon = $record->xpath('./f:SellCon')[0] ?? null;
            $switchCon = $record->xpath('./f:SwitchCon')[0] ?? null;

            $side = $buyCon ? 'BUY' : ($sellCon ? 'SELL' : ($switchCon ? 'SWITCH' : null));
            $con = $buyCon ?: ($sellCon ?: $switchCon);

            if ($con) {
                $con->registerXPathNamespace('f', $defaultNs);
            }

            $fundNode = null;
            if ($buyCon) {
                $fundNode = $buyCon->xpath('./f:BuyFund')[0] ?? null;
            } elseif ($sellCon) {
                $fundNode = $sellCon->xpath('./f:SellFund')[0] ?? null;
            } elseif ($switchCon) {
                $fundNode = $switchCon->xpath('./f:SwitchIn/f:BuyFund')[0]
                    ?? $switchCon->xpath('./f:SwitchOut/f:SellFund')[0]
                    ?? null;
            }

            if ($fundNode) {
                $fundNode->registerXPathNamespace('f', $defaultNs);
            }

            $switchFromFundId = $switchCon
                ? $this->nullIfEmpty($this->xpathString($switchCon, './f:SwitchOut/f:SellFund/f:FundID'))
                : null;
            $switchToFundId = $switchCon
                ? $this->nullIfEmpty($this->xpathString($switchCon, './f:SwitchIn/f:BuyFund/f:FundID'))
                : null;

            $createDate = $this->parseDate($this->xpathString($record, './f:CreateDate'));
            $tradeDate = $this->parseDate($this->xpathString($con, './f:TradeDate'));
            $settlementDate = $this->parseDate($this->xpathString($con, './f:SettlDate'));
            $transactionType = $this->nullIfEmpty(
                $this->xpathString($con, './f:TrxnTyp')
                ?? $this->xpathString($con, './f:SwitchIn/f:TrxnTyp')
                ?? $this->xpathString($con, './f:SwitchOut/f:TrxnTyp')
            );
            $grossAmount = $this->parseAmount($this->xpathString($fundNode, './f:GrossAmt'));
            $netAmount = $this->parseAmount($this->xpathString($fundNode, './f:NetAmt'));
            $settlementAmount = $this->parseAmount($this->xpathString($fundNode, './f:SettlAmt'));

            $recordCount++;
            $totalGross += $grossAmount ?? 0.0;
            $totalNet += $netAmount ?? 0.0;
            $totalSettlement += $settlementAmount ?? 0.0;
            $minCreateDate = $this->minDate($minCreateDate, $createDate);
            $maxCreateDate = $this->maxDate($maxCreateDate, $createDate);
            $minTradeDate = $this->minDate($minTradeDate, $tradeDate);
            $maxTradeDate = $this->maxDate($maxTradeDate, $tradeDate);
            $minSettlementDate = $this->minDate($minSettlementDate, $settlementDate);
            $maxSettlementDate = $this->maxDate($maxSettlementDate, $settlementDate);

            $batch[] = [
                'import_id' => $importId,
                'settlement_instruction_summary_id' => $summary->id,
                'source_file' => $sourceFile,
                'source_type' => $sourceType,
                'record_index' => $index,
                'create_date' => $createDate,
                'trade_date' => $tradeDate,
                'settlement_date' => $settlementDate,
                'management_code' => $this->nullIfEmpty($this->xpathString($record, './f:MgmtCode')),
                'fund_account_id' => $this->nullIfEmpty($this->xpathString($record, './f:FundAcctID')),
                'dealer_code' => $this->nullIfEmpty($this->xpathString($record, './f:DlrCode')),
                'dealer_account_id' => $this->nullIfEmpty($this->xpathString($record, './f:DlrAcctID')),
                'rep_code' => $this->nullIfEmpty($this->xpathString($record, './f:RepCode')),
                'intermediary_code' => $this->nullIfEmpty($this->xpathString($record, './f:Int/f:IntCode')),
                'intermediary_account_id' => $this->nullIfEmpty($this->xpathString($record, './f:Int/f:IntAcctID')),
                'account_type' => $this->nullIfEmpty($this->xpathString($record, './f:AcctType')),
                'order_id' => $this->nullIfEmpty($this->xpathString($record, './f:OrdID')),
                'order_source' => $this->nullIfEmpty($this->xpathString($record, './f:OrdSrc')),
                'order_type' => $this->nullIfEmpty($this->xpathString($record, './f:OrdType')),
                'source_id' => $this->nullIfEmpty($this->xpathString($record, './f:SrcID')),
                'order_status' => $this->nullIfEmpty($this->xpathString($record, './f:OrdStatus')),
                'side' => $side,
                'transaction_type' => $transactionType,
                'fund_id' => $switchCon ? null : $this->nullIfEmpty($this->xpathString($fundNode, './f:FundID')),
                'switch_from_fund_id' => $switchFromFundId,
                'switch_to_fund_id' => $switchToFundId,
                'currency' => $this->normalizeCurrency($this->xpathString($fundNode, './f:Currency')),
                'gross_amount' => $grossAmount,
                'net_amount' => $netAmount,
                'nav' => $this->parseAmount($this->xpathString($fundNode, './f:NAV')),
                'units_transacted' => $this->parseAmount($this->xpathString($fundNode, './f:UnitTrxnd')),
                'settlement_method' => $this->nullIfEmpty($this->xpathString($fundNode, './f:SettlMethd')),
                'settlement_amount' => $settlementAmount,
                'settlement_source' => $this->nullIfEmpty($this->xpathString($con, './f:SettlSrc')),
                'raw_xml' => $record->asXML() ?: null,
                'raw_json' => json_encode($this->xmlToArray($record)),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($batch) >= $chunkSize) {
                DB::table('settlement_instructions')->insert($batch);
                $inserted += count($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            DB::table('settlement_instructions')->insert($batch);
            $inserted += count($batch);
        }

        $summary->update([
            'record_count' => $recordCount,
            'total_gross_amount' => $recordCount > 0 ? $totalGross : null,
            'total_net_amount' => $recordCount > 0 ? $totalNet : null,
            'total_settlement_amount' => $recordCount > 0 ? $totalSettlement : null,
            'min_create_date' => $minCreateDate,
            'max_create_date' => $maxCreateDate,
            'min_trade_date' => $minTradeDate,
            'max_trade_date' => $maxTradeDate,
            'min_settlement_date' => $minSettlementDate,
            'max_settlement_date' => $maxSettlementDate,
        ]);

        return $inserted;
    }

    private function normalizeCurrency(?string $currency): ?string
    {
        return match (strtoupper(trim((string) $currency))) {
            '00', 'CAD' => 'CAD',
            '01', 'USD' => 'USD',
            '' => null,
            default => strtoupper(trim((string) $currency)),
        };
    }

    private function minDate(?string $current, ?string $candidate): ?string
    {
        if ($candidate === null) {
            return $current;
        }
        if ($current === null) {
            return $candidate;
        }

        return strcmp($candidate, $current) < 0 ? $candidate : $current;
    }

    private function maxDate(?string $current, ?string $candidate): ?string
    {
        if ($candidate === null) {
            return $current;
        }
        if ($current === null) {
            return $candidate;
        }

        return strcmp($candidate, $current) > 0 ? $candidate : $current;
    }

    private function xpathString(?\SimpleXMLElement $node, string $path): ?string
    {
        if ($node === null) {
            return null;
        }

        $result = $node->xpath($path);
        if (!$result || !isset($result[0])) {
            return null;
        }

        return trim((string) $result[0]);
    }

    private function parseDate(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            if (preg_match('/^\d{8}$/', $value)) {
                return Carbon::createFromFormat('Ymd', $value)->toDateString();
            }
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseAmount(?string $value): ?float
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $normalized = str_replace([',', ' '], '', $value);
        if (!is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    private function nullIfEmpty(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function xmlToArray(\SimpleXMLElement $xml): mixed
    {
        $json = json_encode($xml);
        return json_decode($json ?: 'null', true);
    }
}
