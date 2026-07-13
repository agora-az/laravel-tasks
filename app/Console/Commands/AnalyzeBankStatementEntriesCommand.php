<?php

namespace App\Console\Commands;

use App\Models\BankStatementEntry;
use App\Models\BankStatementEntryAnalysis;
use Illuminate\Console\Command;

class AnalyzeBankStatementEntriesCommand extends Command
{
    protected $signature = 'analyze:bank-entries
        {--parser=v1 : Parser version to write}
        {--source-file= : Analyze only one source file}
        {--limit= : Max rows to process}
        {--chunk=500 : Chunk size for processing}
        {--rebuild : Delete existing rows for this parser before writing}';

    protected $description = 'Parse bank raw additional_info into structured analysis fields';

    public function handle(): int
    {
        $parserVersion = (string) $this->option('parser');
        $sourceFile = $this->option('source-file');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $chunk = max(1, (int) ($this->option('chunk') ?? 500));

        if ($this->option('rebuild')) {
            $deleted = BankStatementEntryAnalysis::where('parser_version', $parserVersion)->delete();
            $this->info("Deleted {$deleted} existing analysis rows for parser {$parserVersion}");
        }

        $query = BankStatementEntry::query()
            ->select(['id', 'additional_info', 'bank_domain_code', 'bank_family_code', 'bank_sub_family_code'])
            ->orderBy('id');

        if (!empty($sourceFile)) {
            $query->where('source_file', $sourceFile);
        }

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $processed = 0;
        $written = 0;

        $query->chunkById($chunk, function ($entries) use ($parserVersion, &$processed, &$written) {
            foreach ($entries as $entry) {
                $processed++;

                $analysis = $this->analyzeAdditionalInfo(
                    (string) ($entry->additional_info ?? ''),
                    (string) ($entry->bank_domain_code ?? ''),
                    (string) ($entry->bank_family_code ?? ''),
                    (string) ($entry->bank_sub_family_code ?? '')
                );

                BankStatementEntryAnalysis::updateOrCreate(
                    [
                        'bank_statement_entry_id' => $entry->id,
                        'parser_version' => $parserVersion,
                    ],
                    [
                        'memo_type' => $analysis['memo_type'],
                        'settlement_number' => $analysis['settlement_number'],
                        'wire_payment_reference' => $analysis['wire_payment_reference'],
                        'counterparty' => $analysis['counterparty'],
                        'inferred_channel' => $analysis['inferred_channel'],
                        'confidence' => $analysis['confidence'],
                        'normalized_additional_info' => $analysis['normalized_additional_info'],
                        'parse_flags' => $analysis['parse_flags'],
                        'parsed_at' => now(),
                    ]
                );

                $written++;
            }
        });

        $this->info("Processed {$processed} entries, wrote {$written} analysis rows (parser {$parserVersion}).");

        return self::SUCCESS;
    }

    private function analyzeAdditionalInfo(string $additionalInfo, string $domainCode, string $familyCode, string $subFamilyCode): array
    {
        $normalized = $this->normalizeSpaces($additionalInfo);
        $parts = array_values(array_filter(array_map(
            fn($part) => $this->normalizeSpaces($part),
            explode(',', $additionalInfo)
        ), fn($part) => $part !== ''));

        $memoType = $parts[0] ?? null;
        $settlementNumber = null;
        $wirePaymentReference = null;
        $counterparty = null;

        // Settlement number
        if (preg_match('/\bSETTLEMENT\s+([A-Z0-9-]+)\b/i', $normalized, $m)) {
            $settlementNumber = strtoupper($m[1]);
        }

        // WIRE PAYMENT reference (e.g. "WIRE PAYMENT3187214" or "WIRE PAYMENT 3187214")
        if (preg_match('/\bWIRE\s+PAYMENT\s*([0-9]+)\b/i', $normalized, $m)) {
            $wirePaymentReference = $m[1];
        }

        // WIRE TSF reference embedded directly in memo_type (e.g. "WIRE TSF 0133806")
        if ($wirePaymentReference === null && preg_match('/^WIRE\s+TSF\s+([0-9]+)$/i', (string) $memoType, $m)) {
            $wirePaymentReference = $m[1];
        }

        // Counterparty extraction
        if (preg_match('/\bTO:\s*([^,\n]+)/i', $normalized, $m)) {
            // Explicit "TO: <name>" pattern
            $counterparty = $this->normalizeSpaces($m[1]);
        } elseif ($memoType !== null && preg_match('/\b(DEBIT MEMO|CREDIT MEMO)\b/i', $memoType)) {
            // For memos the counterparty is NOT the settlement line — skip it and use later segments
            foreach (array_slice($parts, 1) as $part) {
                if (!preg_match('/\b(SETTLEMENT|CIBC DATA CENTRE|TN[0-9]+)\b/i', $part)) {
                    $counterparty = $part;
                    break;
                }
            }
        } elseif (isset($parts[1]) && !preg_match('/^TN[0-9]+$/i', $parts[1])) {
            // Use second segment when it is not a raw transaction number
            $counterparty = $parts[1];
        }

        $channel = $this->inferChannel($memoType, $domainCode, $familyCode, $subFamilyCode);

        $flags = [
            'has_additional_info' => $normalized !== '',
            'has_settlement_number' => $settlementNumber !== null,
            'has_wire_payment_reference' => $wirePaymentReference !== null,
            'has_counterparty' => $counterparty !== null,
            'has_memo_type' => $memoType !== null,
            'channel_inferred' => $channel !== null,
        ];

        $confidence = 0.0;
        $confidence += $flags['has_additional_info'] ? 0.30 : 0.0;
        $confidence += $flags['has_memo_type'] ? 0.20 : 0.0;
        $confidence += ($flags['has_settlement_number'] || $flags['has_wire_payment_reference']) ? 0.30 : 0.0;
        $confidence += $flags['has_counterparty'] ? 0.20 : 0.0;

        return [
            'memo_type' => $memoType,
            'settlement_number' => $settlementNumber,
            'wire_payment_reference' => $wirePaymentReference,
            'counterparty' => $counterparty,
            'inferred_channel' => $channel,
            'confidence' => min(1.0, $confidence),
            'normalized_additional_info' => $normalized !== '' ? $normalized : null,
            'parse_flags' => $flags,
        ];
    }

    private function inferChannel(?string $memoType, string $domainCode, string $familyCode, string $subFamilyCode): ?string
    {
        $memo = strtoupper((string) $memoType);
        $code = strtoupper(trim($domainCode . '/' . $familyCode . '/' . $subFamilyCode, '/'));

        // Wires — inline WIRE PAYMENT or WIRE TSF patterns
        if (str_starts_with($memo, 'WIRE PAYMENT') || str_starts_with($memo, 'WIRE TSF')) {
            return 'wire';
        }
        if (str_contains($code, 'PMNT/ICDT/DMCT') || str_contains($code, 'PMNT/ICDT/ATXN')) {
            return 'wire';
        }

        // Deposits
        if (str_starts_with($memo, 'INSTANT TELLER DEPOSIT') || str_contains($code, 'PMNT/CNTR/MIXD')) {
            return 'deposit';
        }

        // Transfers
        if (str_contains($memo, 'TRANSFER') || str_contains($code, '/BOOK')) {
            return 'transfer';
        }

        // Settlement memos (DEBIT MEMO / CREDIT MEMO from CIBC operations)
        if (str_contains($memo, 'DEBIT MEMO') || str_contains($memo, 'CREDIT MEMO')) {
            return 'memo';
        }

        // EFT / Pre-authorized
        if (str_starts_with($memo, 'EFT') || str_starts_with($memo, 'PREAUTHORIZED DEBIT')) {
            return 'eft';
        }

        // Cheques
        if (str_starts_with($memo, 'CHEQUE') || str_starts_with($memo, 'RETURNED CHEQUE')) {
            return 'cheque';
        }

        // Fees and service charges
        if (
            str_contains($memo, 'SERVICE CHARGE') ||
            str_contains($memo, 'FEE') ||
            str_starts_with($memo, 'ACCOUNT FEE') ||
            str_starts_with($memo, 'INSURANCE')
        ) {
            return 'fee';
        }

        // Payments (bill pay, misc, government, payroll)
        if (
            str_starts_with($memo, 'MISCELLANEOUS PAYMENT') ||
            str_starts_with($memo, 'BILL PAYMENT') ||
            str_starts_with($memo, 'GOVERNMENT') ||
            str_starts_with($memo, 'PAY')
        ) {
            return 'payment';
        }

        // Interest
        if (str_starts_with($memo, 'INTEREST')) {
            return 'interest';
        }

        return null;
    }

    private function normalizeSpaces(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
}
