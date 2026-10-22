<?php

namespace App\Services\Bank;

use Carbon\CarbonImmutable;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class TdMonthlyPdfStatementParser implements MonthlyPdfStatementParser
{
    private const MONTH_PATTERN = 'JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC';

    /**
     * @return array<string, mixed>
     */
    public function parseFile(string $path, string $currency = 'CAD'): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("PDF file is not readable: {$path}");
        }

        $ghostscript = (new ExecutableFinder())->find('gs')
            ?? (new ExecutableFinder())->find('gswin64c');
        if ($ghostscript === null) {
            throw new RuntimeException(
                'Ghostscript is required to extract secured TD statement PDFs.'
            );
        }

        $process = new Process([
            $ghostscript,
            '-q',
            '-dSAFER',
            '-dBATCH',
            '-dNOPAUSE',
            '-sDEVICE=txtwrite',
            '-sOutputFile=-',
            $path,
        ]);
        $process->setTimeout(300);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new RuntimeException(
                'Unable to extract TD PDF text with Ghostscript: ' . trim($process->getErrorOutput())
            );
        }

        $text = $process->getOutput();
        if (trim($text) === '') {
            throw new RuntimeException('Ghostscript extracted no text from the TD statement PDF.');
        }

        $statement = $this->parseText($text, $currency);
        $statement['text_source'] = 'ghostscript-txtwrite';

        return $statement;
    }

    /**
     * @return array<string, mixed>
     */
    public function parseText(string $text, string $currency = 'CAD'): array
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text) ?? $text;
        $normalizedText = $this->normalizeSpaces($text);
        $statementPeriod = $this->extractStatementPeriod($normalizedText);
        $account = $this->extractAccount($normalizedText);
        $statementCurrency = $this->extractCurrency($normalizedText);
        $requestedCurrency = strtoupper($currency);
        if ($statementCurrency !== $requestedCurrency) {
            throw new RuntimeException(
                "The TD statement currency is {$statementCurrency}, not {$requestedCurrency}."
            );
        }

        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $transactions = [];
        $runningBalanceCents = null;
        $openingBalanceCents = null;
        $lastReportedBalanceIndex = null;
        $creditColumn = null;
        $dateColumn = null;
        $insideTransactions = false;
        $pageHasBalanceForward = false;
        $reportedCreditCount = 0;
        $reportedDebitCount = 0;
        $reportedCreditSumCents = 0;
        $reportedDebitSumCents = 0;
        $creditSummaryCount = 0;
        $debitSummaryCount = 0;

        foreach ($lines as $index => $rawLine) {
            $lineNumber = $index + 1;
            $line = ltrim(rtrim($rawLine));
            $normalizedLine = $this->normalizeSpaces($line);
            if ($normalizedLine === '') {
                continue;
            }

            if (preg_match('/\bCredits\s+(\d+)\s+(\d[\d,]*\.\d{2})\b/i', $normalizedLine, $matches)) {
                $reportedCreditCount += (int) $matches[1];
                $reportedCreditSumCents += $this->moneyToCents($matches[2]);
                $creditSummaryCount++;
            }
            if (preg_match('/\bDebits\s+(\d+)\s+(\d[\d,]*\.\d{2})\b/i', $normalizedLine, $matches)) {
                $reportedDebitCount += (int) $matches[1];
                $reportedDebitSumCents += $this->moneyToCents($matches[2]);
                $debitSummaryCount++;
            }

            if (
                stripos($line, 'DESCRIPTION') !== false
                && stripos($line, 'CHEQUE/DEBIT') !== false
                && stripos($line, 'DEPOSIT/CREDIT') !== false
                && stripos($line, 'DATE') !== false
                && stripos($line, 'BALANCE') !== false
            ) {
                $creditColumn = stripos($line, 'DEPOSIT/CREDIT');
                $dateColumn = stripos($line, 'DATE');
                $insideTransactions = true;
                $pageHasBalanceForward = false;
                continue;
            }

            if (!$insideTransactions) {
                continue;
            }

            if (preg_match('/^\d+\s+CHQS?\s+ENCLOSED\b/i', $normalizedLine)) {
                if (!$pageHasBalanceForward) {
                    throw new RuntimeException(
                        "TD transaction page ending on line {$lineNumber} had no balance forward."
                    );
                }
                $insideTransactions = false;
                continue;
            }

            if (preg_match(
                '/^BALANCE FORWARD\s+(' . self::MONTH_PATTERN . ')(\d{2})\s+(\d[\d,]*\.\d{2})$/i',
                $normalizedLine,
                $matches
            )) {
                $balanceDate = $this->resolveDate(
                    $matches[1],
                    (int) $matches[2],
                    $statementPeriod
                );
                $balanceCents = $this->moneyToCents($matches[3]);
                if ($openingBalanceCents === null) {
                    if (!$balanceDate->equalTo($statementPeriod['start'])) {
                        throw new RuntimeException(
                            'The first TD balance-forward date does not match the statement start date.'
                        );
                    }
                    $openingBalanceCents = $balanceCents;
                    $runningBalanceCents = $balanceCents;
                } elseif ($runningBalanceCents !== $balanceCents) {
                    throw new RuntimeException(
                        "Balance forward on line {$lineNumber} does not match the prior page balance."
                    );
                }

                $pageHasBalanceForward = true;
                continue;
            }

            if (
                !$pageHasBalanceForward
                || $creditColumn === null
                || $dateColumn === null
                || $runningBalanceCents === null
            ) {
                continue;
            }

            $transaction = $this->parseTransactionLine(
                $line,
                $lineNumber,
                $creditColumn,
                $dateColumn,
                $statementPeriod,
                $runningBalanceCents
            );
            if ($transaction === null) {
                continue;
            }

            $runningBalanceCents = $transaction['balance_cents'];
            if ($transaction['reported_balance']) {
                $lastReportedBalanceIndex = count($transactions);
            }
            unset($transaction['reported_balance']);
            $transactions[] = $transaction;
        }

        if ($insideTransactions) {
            throw new RuntimeException('The TD transaction table ended without its page summary.');
        }
        if ($openingBalanceCents === null) {
            throw new RuntimeException('The TD statement opening balance was not found.');
        }
        if (!empty($transactions) && $lastReportedBalanceIndex !== count($transactions) - 1) {
            throw new RuntimeException('The final TD transaction does not include a closing balance.');
        }
        if ($creditSummaryCount === 0 || $debitSummaryCount === 0) {
            throw new RuntimeException('The TD statement credit and debit summaries were not found.');
        }

        $debitTransactions = array_filter(
            $transactions,
            fn(array $transaction) => $transaction['credit_debit_indicator'] === 'DBIT'
        );
        $creditTransactions = array_filter(
            $transactions,
            fn(array $transaction) => $transaction['credit_debit_indicator'] === 'CRDT'
        );
        $calculatedDebitSumCents = array_sum(array_column($debitTransactions, 'amount_cents'));
        $calculatedCreditSumCents = array_sum(array_column($creditTransactions, 'amount_cents'));

        $this->assertSummaryMatches(
            'credits',
            count($creditTransactions),
            $calculatedCreditSumCents,
            $reportedCreditCount,
            $reportedCreditSumCents
        );
        $this->assertSummaryMatches(
            'debits',
            count($debitTransactions),
            $calculatedDebitSumCents,
            $reportedDebitCount,
            $reportedDebitSumCents
        );

        $closingBalanceCents = $runningBalanceCents;
        if (
            $openingBalanceCents + $calculatedCreditSumCents - $calculatedDebitSumCents
            !== $closingBalanceCents
        ) {
            throw new RuntimeException('TD statement totals do not reconcile to the closing balance.');
        }

        $days = $this->buildDays(
            $statementPeriod['start']->addDay(),
            $statementPeriod['end'],
            $transactions,
            $openingBalanceCents
        );

        return [
            'account_number' => $account['canonical'],
            'display_account_number' => $account['display'],
            'branch_transit_number' => $account['transit'],
            'currency' => $statementCurrency,
            'statement_start_date' => $statementPeriod['start']->toDateString(),
            'statement_end_date' => $statementPeriod['end']->toDateString(),
            'opening_balance_cents' => $openingBalanceCents,
            'closing_balance_cents' => $closingBalanceCents,
            'withdrawal_total_cents' => $calculatedDebitSumCents,
            'deposit_total_cents' => $calculatedCreditSumCents,
            'debit_count' => count($debitTransactions),
            'credit_count' => count($creditTransactions),
            'transaction_count' => count($transactions),
            'transactions' => $transactions,
            'days' => $days,
        ];
    }

    /**
     * @param array{start: CarbonImmutable, end: CarbonImmutable} $statementPeriod
     * @return array<string, mixed>|null
     */
    private function parseTransactionLine(
        string $line,
        int $lineNumber,
        int $creditColumn,
        int $dateColumn,
        array $statementPeriod,
        int $runningBalanceCents
    ): ?array {
        if (!preg_match_all(
            '/(' . self::MONTH_PATTERN . ')(\d{2})/i',
            $line,
            $dateMatches,
            PREG_OFFSET_CAPTURE
        )) {
            return null;
        }

        $dateMatchIndex = count($dateMatches[0]) - 1;
        $dateOffset = $dateMatches[0][$dateMatchIndex][1];
        $beforeDate = substr($line, 0, $dateOffset);
        if (!preg_match_all(
            '/(?<![\d.])(\d[\d,]*\.\d{2})(?!\d)/',
            $beforeDate,
            $moneyMatches,
            PREG_OFFSET_CAPTURE
        )) {
            return null;
        }

        $amountMatchIndex = count($moneyMatches[1]) - 1;
        $amountValue = $moneyMatches[1][$amountMatchIndex][0];
        $amountOffset = $moneyMatches[1][$amountMatchIndex][1];
        $description = $this->normalizeSpaces(substr($line, 0, $amountOffset));
        $description = preg_replace('/^\d{6}\s+(?=\d{6}[A-Z])/', '', $description) ?? $description;
        if ($description === '') {
            throw new RuntimeException("TD transaction on line {$lineNumber} has no description.");
        }

        $date = $this->resolveDate(
            $dateMatches[1][$dateMatchIndex][0],
            (int) $dateMatches[2][$dateMatchIndex][0],
            $statementPeriod
        );
        $amountCents = $this->moneyToCents($amountValue);
        $columnBoundary = intdiv($creditColumn + $dateColumn, 2);
        $indicator = $amountOffset < $columnBoundary ? 'DBIT' : 'CRDT';
        $newBalanceCents = $indicator === 'DBIT'
            ? $runningBalanceCents - $amountCents
            : $runningBalanceCents + $amountCents;

        $afterDate = substr(
            $line,
            $dateOffset + strlen($dateMatches[0][$dateMatchIndex][0])
        );
        $reportedBalance = false;
        if (preg_match('/^\s*(\d[\d,]*\.\d{2})\b/', $afterDate, $balanceMatches)) {
            $reportedBalanceCents = $this->moneyToCents($balanceMatches[1]);
            if ($reportedBalanceCents !== $newBalanceCents) {
                throw new RuntimeException(
                    "TD transaction on line {$lineNumber} does not reconcile: calculated balance "
                    . $this->centsToDecimal($newBalanceCents)
                    . ', reported balance ' . $this->centsToDecimal($reportedBalanceCents) . '.'
                );
            }
            $reportedBalance = true;
        }

        return [
            'date' => $date->toDateString(),
            'description' => $description,
            'credit_debit_indicator' => $indicator,
            'amount_cents' => $amountCents,
            'balance_cents' => $newBalanceCents,
            'source_line' => $lineNumber,
            'reported_balance' => $reportedBalance,
        ];
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    private function extractStatementPeriod(string $text): array
    {
        if (!preg_match(
            '/\b(' . self::MONTH_PATTERN . ')\s*(\d{1,2})\/(\d{2})\s*-\s*'
            . '(' . self::MONTH_PATTERN . ')\s*(\d{1,2})\/(\d{2})\b/i',
            $text,
            $matches
        )) {
            throw new RuntimeException('Unable to identify the TD statement date range.');
        }

        try {
            $start = CarbonImmutable::createFromFormat(
                '!M j Y',
                "{$matches[1]} {$matches[2]} " . (2000 + (int) $matches[3])
            );
            $end = CarbonImmutable::createFromFormat(
                '!M j Y',
                "{$matches[4]} {$matches[5]} " . (2000 + (int) $matches[6])
            );
        } catch (Throwable $e) {
            throw new RuntimeException('The TD statement date range is invalid.', 0, $e);
        }

        if ($end->lessThan($start)) {
            throw new RuntimeException('The TD statement date range is invalid.');
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * @return array{canonical: string, display: string, transit: string}
     */
    private function extractAccount(string $text): array
    {
        if (!preg_match('/\b(\d{4})\s+(\d{4}-\d{7})\s+BASIC\s+Page\b/i', $text, $matches)) {
            throw new RuntimeException('Unable to identify the TD branch and account numbers.');
        }

        $transit = str_pad($matches[1], 5, '0', STR_PAD_LEFT);
        $display = $matches[2];
        $accountDigits = preg_replace('/\D/', '', $display) ?? '';

        return [
            'canonical' => $transit . $accountDigits,
            'display' => $display,
            'transit' => $transit,
        ];
    }

    private function extractCurrency(string $text): string
    {
        if (!preg_match('/\bACCOUNT\s*-\s*([A-Z]{3})\b/i', $text, $matches)) {
            throw new RuntimeException('Unable to identify the TD statement currency.');
        }

        return strtoupper($matches[1]);
    }

    /**
     * @param array{start: CarbonImmutable, end: CarbonImmutable} $statementPeriod
     */
    private function resolveDate(string $month, int $day, array $statementPeriod): CarbonImmutable
    {
        foreach (array_unique([$statementPeriod['start']->year, $statementPeriod['end']->year]) as $year) {
            try {
                $date = CarbonImmutable::createFromFormat('!M j Y', "{$month} {$day} {$year}");
            } catch (Throwable) {
                continue;
            }

            if (
                $date->greaterThanOrEqualTo($statementPeriod['start'])
                && $date->lessThanOrEqualTo($statementPeriod['end'])
            ) {
                return $date;
            }
        }

        throw new RuntimeException(
            "TD transaction date {$month}{$day} falls outside the statement period."
        );
    }

    private function assertSummaryMatches(
        string $label,
        int $calculatedCount,
        int $calculatedSumCents,
        int $reportedCount,
        int $reportedSumCents
    ): void {
        if ($calculatedCount !== $reportedCount || $calculatedSumCents !== $reportedSumCents) {
            throw new RuntimeException(
                "Parsed TD {$label} total {$calculatedCount} / "
                . $this->centsToDecimal($calculatedSumCents)
                . ", but the statement reports {$reportedCount} / "
                . $this->centsToDecimal($reportedSumCents) . '.'
            );
        }
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
                } else {
                    $creditCount++;
                    $creditSumCents += $transaction['amount_cents'];
                }
                $currentBalanceCents = $transaction['balance_cents'];
            }

            $days[$dateString] = [
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
        $normalized = str_replace(',', '', trim($value));
        if (!preg_match('/^(\d+)\.(\d{2})$/', $normalized, $matches)) {
            throw new RuntimeException("Invalid TD statement amount: {$value}");
        }

        return ((int) $matches[1] * 100) + (int) $matches[2];
    }

    private function centsToDecimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function normalizeSpaces(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
