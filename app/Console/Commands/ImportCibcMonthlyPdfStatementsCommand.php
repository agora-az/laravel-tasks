<?php

namespace App\Console\Commands;

use App\Services\Bank\BankSftpFileDownloader;
use App\Services\Bank\CibcMonthlyPdfStatementParser;

class ImportCibcMonthlyPdfStatementsCommand extends ImportMonthlyPdfStatementsCommand
{
    protected $signature = 'import:cibc-monthly-pdf
        {--path=resources/data/cibc : Local PDF file or directory}
        {--pattern=*.pdf : Glob used when path is a local directory}
        {--sftp-pattern= : Bank SFTP filename glob; for example *.pdf or 2023*.pdf}
        {--remote-path= : SFTP directory override; defaults to BANK_SFTP_REMOTE_PATH}
        {--currency=CAD : Statement currency when the PDF displays only a dollar symbol}
        {--parser=v2 : Parser version for analyze:bank-entries}
        {--dry-run : Download, parse, validate, and compare without changing the database}
        {--replace-existing : Atomically replace prior PDF imports with the same filenames}
        {--skip-analysis : Skip analyze:bank-entries after importing}';

    protected $description = 'Import reusable CIBC monthly PDF statements from local storage or bank SFTP';

    public function handle(
        BankSftpFileDownloader $downloader,
        CibcMonthlyPdfStatementParser $parser
    ): int {
        return $this->runImport($downloader, $parser);
    }

    protected function bankName(): string
    {
        return 'CIBC';
    }

    protected function acceptsFilename(string $filename): bool
    {
        return preg_match('/^ADSC Trust AC .+ Bank Stmt\.pdf$/i', $filename) !== 1;
    }

    protected function importType(): string
    {
        return 'bank-statement-pdf';
    }

    protected function messagePrefix(): string
    {
        return 'CIBC-MONTHLY-PDF';
    }

    protected function statementPrefix(): string
    {
        return 'CIBC-PDF';
    }
}
