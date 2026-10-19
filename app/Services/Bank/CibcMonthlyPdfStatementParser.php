<?php

namespace App\Services\Bank;

use Carbon\CarbonImmutable;
use RuntimeException;
use Smalot\PdfParser\Parser;

class CibcMonthlyPdfStatementParser
{
    public function __construct(private readonly PdfOcrTextExtractor $ocrTextExtractor)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function parseFile(string $path, string $currency = 'CAD'): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("PDF file is not readable: {$path}");
        }

        try {
            $text = (new Parser())->parseFile($path)->getText();
        } catch (\Throwable $e) {
            throw new RuntimeException('Unable to extract PDF text: ' . $e->getMessage(), 0, $e);
        }

        $textSource = 'embedded-text';
        if (trim($text) === '') {
            $text = $this->ocrTextExtractor->extract($path);
            $textSource = 'local-ocr';
        }

        $statement = $this->parseText($text, $currency);
        $statement['text_source'] = $textSource;

        return $statement;
    }

    /**
     * @return array<string, mixed>
     */
    public function parseText(string $text, string $currency = 'CAD'): array
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text) ?? $text;
        $statementPeriod = $this->extractStatementPeriod($text);
        $account = $this->extractAccount($text);
        $openingBalanceCents = $this->extractSummaryAmount($text, 'Opening balance on', 'opening balance');
        $withdrawalTotalCents = $this->extractSummaryAmount($text, 'Withdrawals', 'withdrawal total');
        $depositTotalCents = $this->extractSummaryAmount($text, 'Deposits', 'deposit total');
        $closingBalanceCents = $this->extractSummaryAmount($text, 'Closing balance on', 'closing balance');

        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $transactions = [];
        $currentDate = null;
        $descriptionParts = [];
        $runningBalanceCents = $openingBalanceCents;
        $insideTransactions = false;
        $sawClosingBalance = false;

        foreach ($lines as $index => $rawLine) {
            $lineNumber = $index + 1;
            $line = $this->normalizeSpaces($rawLine);
            if ($line === '') {
                continue;
            }

            if (!$insideTransactions) {
                if (str_starts_with(strtolower($line), 'transaction details')) {
                    $insideTransactions = true;
                }
                continue;
            }

            if (preg_match('/^Closing balance\s+\$?([\d,]+\.\d{2})$/i', $line, $matches)) {
                $reportedClosingCents = $this->moneyToCents($matches[1]);
                if ($reportedClosingCents !== $closingBalanceCents) {
                    throw new RuntimeException(
                        "Transaction section closing balance on line {$lineNumber} does not match the account summary."
                    );
                }
                $sawClosingBalance = true;
                break;
            }

            if ($this->isStatementScaffolding($line)) {
                continue;
            }

            $datePrefix = $this->extractDatePrefix($line, $statementPeriod['end']->year);
            if ($datePrefix !== null) {
                if (!empty($descriptionParts)) {
                    throw new RuntimeException(
                        "A new transaction date began on line {$lineNumber} before the prior transaction was complete."
                    );
                }

                $currentDate = $datePrefix['date'];
                if (
                    $currentDate->lessThan($statementPeriod['start'])
                    || $currentDate->greaterThan($statementPeriod['end'])
                ) {
                    throw new RuntimeException(
                        "Transaction date {$currentDate->toDateString()} on line {$lineNumber} "
                        . 'falls outside the statement period.'
                    );
                }
                $line = $datePrefix['remainder'];

                if (preg_match('/^(Opening balance|Balance forward)\s+\$?([\d,]+\.\d{2})$/i', $line, $matches)) {
                    $forwardBalanceCents = $this->moneyToCents($matches[2]);
                    if ($forwardBalanceCents !== $runningBalanceCents) {
                        throw new RuntimeException(
                            "Balance forward on line {$lineNumber} does not match the prior running balance."
                        );
                    }
                    continue;
                }

                if ($line === '') {
                    continue;
                }
            }

            if ($currentDate === null) {
                continue;
            }

            $moneyTokens = $this->moneyTokens($line);
            if (count($moneyTokens) < 2) {
                $descriptionParts[] = $line;
                continue;
            }

            $amountToken = $moneyTokens[count($moneyTokens) - 2];
            $balanceToken = $moneyTokens[count($moneyTokens) - 1];
            $amountCents = $this->moneyToCents($amountToken['value']);
            $newBalanceCents = $this->moneyToCents($balanceToken['value']);
            $lineDescription = $this->normalizeSpaces(substr($line, 0, $amountToken['offset']));
            if ($lineDescription !== '') {
                $descriptionParts[] = $lineDescription;
            }

            $description = $this->formatDescription($descriptionParts);
            if ($description === '') {
                throw new RuntimeException("Transaction ending on line {$lineNumber} has no description.");
            }

            $indicator = $this->resolveIndicator(
                $runningBalanceCents,
                $amountCents,
                $newBalanceCents,
                $lineNumber
            );

            $transactions[] = [
                'date' => $currentDate->toDateString(),
                'description' => $description,
                'credit_debit_indicator' => $indicator,
                'amount_cents' => $amountCents,
                'balance_cents' => $newBalanceCents,
                'source_line' => $lineNumber,
            ];

            $runningBalanceCents = $newBalanceCents;
            $descriptionParts = [];
        }

        if (!$sawClosingBalance) {
            throw new RuntimeException('The transaction section closing balance was not found.');
        }
        if (!empty($descriptionParts)) {
            throw new RuntimeException('The PDF ended with an incomplete transaction description.');
        }
        if (empty($transactions)) {
            throw new RuntimeException('No transactions were parsed from the PDF.');
        }
        if ($runningBalanceCents !== $closingBalanceCents) {
            throw new RuntimeException('Parsed transactions do not reach the statement closing balance.');
        }

        $debitTransactions = array_filter(
            $transactions,
            fn(array $transaction) => $transaction['credit_debit_indicator'] === 'DBIT'
        );
        $creditTransactions = array_filter(
            $transactions,
            fn(array $transaction) => $transaction['credit_debit_indicator'] === 'CRDT'
        );
        $calculatedWithdrawals = array_sum(array_column($debitTransactions, 'amount_cents'));
        $calculatedDeposits = array_sum(array_column($creditTransactions, 'amount_cents'));

        if ($calculatedWithdrawals !== $withdrawalTotalCents) {
            throw new RuntimeException(
                'Parsed withdrawals total ' . $this->centsToDecimal($calculatedWithdrawals)
                . ', but the statement reports ' . $this->centsToDecimal($withdrawalTotalCents) . '.'
            );
        }
        if ($calculatedDeposits !== $depositTotalCents) {
            throw new RuntimeException(
                'Parsed deposits total ' . $this->centsToDecimal($calculatedDeposits)
                . ', but the statement reports ' . $this->centsToDecimal($depositTotalCents) . '.'
            );
        }

        $days = $this->buildDays(
            $statementPeriod['start'],
            $statementPeriod['end'],
            $transactions,
            $openingBalanceCents
        );

        return [
            'account_number' => $account['canonical'],
            'display_account_number' => $account['display'],
            'branch_transit_number' => $account['transit'],
            'currency' => strtoupper($currency),
            'statement_start_date' => $statementPeriod['start']->toDateString(),
            'statement_end_date' => $statementPeriod['end']->toDateString(),
            'opening_balance_cents' => $openingBalanceCents,
            'closing_balance_cents' => $closingBalanceCents,
            'withdrawal_total_cents' => $withdrawalTotalCents,
            'deposit_total_cents' => $depositTotalCents,
            'debit_count' => count($debitTransactions),
            'credit_count' => count($creditTransactions),
            'transaction_count' => count($transactions),
            'transactions' => $transactions,
            'days' => $days,
        ];
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    private function extractStatementPeriod(string $text): array
    {
        if (!preg_match(
            '/\bFor\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s*(\d{1,2})'
            . '\s+to\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s*(\d{1,2}),\s*(\d{4})\b/i',
            $text,
            $matches
        )) {
            throw new RuntimeException('Unable to identify the statement date range.');
        }

        $endYear = (int) $matches[5];
        $startMonth = CarbonImmutable::parse($matches[1] . ' 1')->month;
        $endMonth = CarbonImmutable::parse($matches[3] . ' 1')->month;
        $startYear = $startMonth > $endMonth ? $endYear - 1 : $endYear;
        $start = CarbonImmutable::create($startYear, $startMonth, (int) $matches[2])->startOfDay();
        $end = CarbonImmutable::create($endYear, $endMonth, (int) $matches[4])->startOfDay();

        if ($end->lessThan($start)) {
            throw new RuntimeException('The statement date range is invalid.');
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * @return array{canonical: string, display: string, transit: string}
     */
    private function extractAccount(string $text): array
    {
        if (!preg_match('/Account number(?:\s*:)?\s*([0-9-]+)/i', $text, $accountMatches)) {
            throw new RuntimeException('Unable to identify the statement account number.');
        }
        if (!preg_match('/Branch transit number(?:\s*:)?\s*([0-9]+)/i', $text, $transitMatches)) {
            throw new RuntimeException('Unable to identify the branch transit number.');
        }

        $display = trim($accountMatches[1]);
        $transit = str_pad(preg_replace('/\D/', '', $transitMatches[1]) ?? '', 5, '0', STR_PAD_LEFT);
        $accountDigits = preg_replace('/\D/', '', $display) ?? '';
        if ($accountDigits === '' || $transit === '') {
            throw new RuntimeException('The statement account or branch transit number is invalid.');
        }

        return [
            'canonical' => $transit . $accountDigits,
            'display' => $display,
            'transit' => $transit,
        ];
    }

    private function extractSummaryAmount(string $text, string $heading, string $label): int
    {
        $pattern = '/' . preg_quote($heading, '/') . '.{0,160}?\$?'
            . '(\d{1,3}(?:,\d{3})*\.\d{2})/is';
        if (!preg_match($pattern, $text, $matches)) {
            throw new RuntimeException("Unable to identify the statement {$label}.");
        }

        return $this->moneyToCents($matches[1]);
    }

    /**
     * @return array{date: CarbonImmutable, remainder: string}|null
     */
    private function extractDatePrefix(string $line, int $year): ?array
    {
        if (!preg_match('/^(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s*(\d{1,2})(.*)$/i', $line, $matches)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!M j Y', "{$matches[1]} {$matches[2]} {$year}");
        } catch (\Throwable $e) {
            throw new RuntimeException("Invalid transaction date: {$matches[1]} {$matches[2]} {$year}", 0, $e);
        }

        return [
            'date' => $date,
            'remainder' => $this->normalizeSpaces($matches[3]),
        ];
    }

    private function isStatementScaffolding(string $line): bool
    {
        $lower = strtolower($line);

        return str_starts_with($lower, 'transaction details')
            || str_starts_with($lower, 'date description')
            || str_starts_with($lower, 'cibc account statement')
            || str_starts_with($lower, 'account number:')
            || str_starts_with($lower, 'branch transit number:')
            || str_starts_with($lower, '(continued on next page)')
            || preg_match('/^Page\s+\d+\s+of\s+\d+$/i', $line) === 1
            || preg_match('/^\d+[A-Z]\s+BUS-/i', $line) === 1
            || preg_match(
                '/^(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s*\d{1,2}'
                . '\s+to\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s*\d{1,2},\s*\d{4}$/i',
                $line
            ) === 1;
    }

    /**
     * @return array<int, array{value: string, offset: int}>
     */
    private function moneyTokens(string $line): array
    {
        preg_match_all(
            '/(?<![\d.])\$?(\d{1,3}(?:,\d{3})*\.\d{2})(?!\d)/',
            $line,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        return array_map(
            fn(array $match) => ['value' => $match[0], 'offset' => $match[1]],
            $matches[0] ?? []
        );
    }

    private function resolveIndicator(
        int $previousBalanceCents,
        int $amountCents,
        int $newBalanceCents,
        int $lineNumber
    ): string {
        if ($previousBalanceCents - $amountCents === $newBalanceCents) {
            return 'DBIT';
        }
        if ($previousBalanceCents + $amountCents === $newBalanceCents) {
            return 'CRDT';
        }

        throw new RuntimeException(
            "Transaction ending on line {$lineNumber} does not reconcile: previous balance "
            . $this->centsToDecimal($previousBalanceCents)
            . ', amount ' . $this->centsToDecimal($amountCents)
            . ', reported balance ' . $this->centsToDecimal($newBalanceCents) . '.'
        );
    }

    /**
     * @param array<int, string> $parts
     */
    private function formatDescription(array $parts): string
    {
        $parts = array_values(array_filter(
            array_map(fn(string $part) => $this->normalizeSpaces($part), $parts),
            fn(string $part) => $part !== ''
        ));

        if (empty($parts)) {
            return '';
        }
        if (count($parts) === 1) {
            return $parts[0];
        }

        return $parts[0] . ', ' . implode(' ', array_slice($parts, 1));
    }

    /**
     * @param array<int, array<string, mixed>> $transactions
     * @return array<string, array<string, mixed>>
     */
    private function buildDays(
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $transactions,
        int $openingBalanceCents
    ): array {
        $transactionsByDate = [];
        foreach ($transactions as $transaction) {
            $transactionsByDate[$transaction['date']][] = $transaction;
        }

        $days = [];
        $currentBalanceCents = $openingBalanceCents;
        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $dateString = $date->toDateString();
            $dayTransactions = $transactionsByDate[$dateString] ?? [];
            $dayOpeningCents = $currentBalanceCents;
            $debitCount = 0;
            $creditCount = 0;
            $debitSumCents = 0;
            $creditSumCents = 0;

            foreach ($dayTransactions as $transaction) {
                if ($transaction['credit_debit_indicator'] === 'DBIT') {
                    $debitCount++;
                    $debitSumCents += $transaction['amount_cents'];
                    $currentBalanceCents -= $transaction['amount_cents'];
                } else {
                    $creditCount++;
                    $creditSumCents += $transaction['amount_cents'];
                    $currentBalanceCents += $transaction['amount_cents'];
                }

                if ($currentBalanceCents !== $transaction['balance_cents']) {
                    throw new RuntimeException(
                        "Daily balance reconstruction failed on {$dateString}."
                    );
                }
            }

            $days[$dateString] = [
                'date' => $dateString,
                'opening_balance_cents' => $dayOpeningCents,
                'closing_balance_cents' => $currentBalanceCents,
                'debit_count' => $debitCount,
                'credit_count' => $creditCount,
                'debit_sum_cents' => $debitSumCents,
                'credit_sum_cents' => $creditSumCents,
                'transactions' => $dayTransactions,
            ];
        }

        return $days;
    }

    private function moneyToCents(string $value): int
    {
        $normalized = str_replace(['$', ',', ' '], '', trim($value));
        if (!preg_match('/^\d+\.\d{2}$/', $normalized)) {
            throw new RuntimeException("Invalid money value: {$value}");
        }

        [$whole, $fraction] = explode('.', $normalized, 2);

        return ((int) $whole * 100) + (int) $fraction;
    }

    private function centsToDecimal(int $cents): string
    {
        $absolute = abs($cents);
        $sign = $cents < 0 ? '-' : '';

        return $sign . intdiv($absolute, 100) . '.' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    private function normalizeSpaces(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
