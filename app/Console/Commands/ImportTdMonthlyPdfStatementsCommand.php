<?php

namespace App\Console\Commands;

use App\Services\Bank\BankSftpFileDownloader;
use App\Services\Bank\TdMonthlyPdfStatementParser;

class ImportTdMonthlyPdfStatementsCommand extends ImportMonthlyPdfStatementsCommand
{
    protected $signature = 'import:td-monthly-pdf
        {--path=resources/data/cibc : Local PDF file or directory}
        {--pattern=ADSC Trust AC * Bank Stmt.pdf : Glob used when path is a local directory}
        {--sftp-pattern= : Bank SFTP filename glob; for example ADSC Trust AC * Bank Stmt.pdf}
        {--remote-path= : SFTP directory override; defaults to BANK_SFTP_REMOTE_PATH}
        {--currency=CAD : Expected statement currency}
        {--parser=v2 : Parser version for analyze:bank-entries}
        {--dry-run : Download, parse, validate, and compare without changing the database}
        {--replace-existing : Atomically replace prior TD PDF imports with the same filenames}
        {--skip-analysis : Skip analyze:bank-entries after importing}';

    protected $description = 'Import TD monthly PDF statements from local storage or bank SFTP';

    public function handle(
        BankSftpFileDownloader $downloader,
        TdMonthlyPdfStatementParser $parser
    ): int {
        return $this->runImport($downloader, $parser);
    }

    protected function bankName(): string
    {
        return 'TD';
    }

    protected function acceptsFilename(string $filename): bool
    {
        return preg_match('/^ADSC Trust AC .+ Bank Stmt\.pdf$/i', $filename) === 1;
    }

    protected function importType(): string
    {
        return 'bank-statement-td-pdf';
    }

    protected function messagePrefix(): string
    {
        return 'TD-MONTHLY-PDF';
    }

    protected function statementPrefix(): string
    {
        return 'TD-PDF';
    }
}
