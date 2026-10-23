# Agora Reconciliation

A Laravel web application for generating and managing reconciliation reports.

## Features

- **Create Reconciliation Reports**: Generate reports for any time period
- **View Reports**: Browse and view all reconciliation reports
- **Export Reports**: Download reports in various formats
- **Period Management**: Track reconciliation data by date ranges

## Requirements

- PHP 8.4
- Composer
- MySQL database
- Node.js (optional, for frontend assets)

## Installation

1. **Install Dependencies**

    ```bash
    composer install
    ```

2. **Configure Environment**

    Copy the `.env.example` file to `.env` and update the database settings:

    ```
    DB_CONNECTION=mysql
    DB_HOST=127.0.0.1
    DB_PORT=3306
    DB_DATABASE=opus_reconciliation
    DB_USERNAME=root
    DB_PASSWORD=your_password
    ```

3. **Generate Application Key**

    ```bash
    php artisan key:generate
    ```

4. **Run Migrations**
    ```bash
    php artisan migrate
    ```

## Running the Application

### Development Server

Use the VS Code task "Serve Laravel Application" or run:

```bash
bash scripts/dev.sh
```

The application will be available at [http://127.0.0.1:8000](http://127.0.0.1:8000)

The local launcher starts both the web server and the Laravel queue worker. The
worker is required for background work such as dashboard-summary, transaction
cache hydration, and VieFund report-date refreshes. If the web server is started
separately with `php artisan serve`, run `bash scripts/workers.sh` in a second
terminal.

## Azure Production Deployment

The GitHub Actions workflow deploys the application on pushes to `main`. Before
deploying, confirm these App Service settings:

The workflow builds with PHP 8.4 and configures the Linux App Service runtime as
`PHP|8.4` before deploying the application package.

```dotenv
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=3700
DB_VIEFUND_CACHE_QUEUE_RETRY_AFTER=300
QUEUE_GENERAL_WORKERS=1
QUEUE_CACHE_WORKERS=1
CACHE_STORE=database
APP_ADMIN_ACCEESS=authorized@example.com
VIEFUND_ALL_TRANSACTIONS_DATA_SOURCE=current
VIEFUND_TRANSACTION_MIRROR_INCEPTION_DATE=
VIEFUND_TRANSACTION_MIRROR_CURRENCIES=00,01
VIEFUND_TRANSACTION_MIRROR_STATUS_IDS=0,1,2,3,4,5,6
VIEFUND_TRANSACTION_MIRROR_BATCH_SIZE=1000
VIEFUND_TRANSACTION_MIRROR_LOOKBACK_DAYS=90
VIEFUND_MAINTENANCE_TIMEZONE=America/Toronto
VIEFUND_MAINTENANCE_DRAIN_START=18:55
VIEFUND_MAINTENANCE_START=19:00
VIEFUND_MAINTENANCE_END=19:20
VIEFUND_POST_SNAPSHOT_SYNC_AT=19:25
```

Set `VIEFUND_TRANSACTION_MIRROR_INCEPTION_DATE` to the earliest VieFund
transaction date that must be available. Keep
`VIEFUND_ALL_TRANSACTIONS_DATA_SOURCE=current` during the initial population.
From **Admin / Application Settings**, start the population, monitor its
maintenance-adjusted ETA, and select **Local mirror - administrators only** for
validation after it reports ready. Select **Local mirror - all users** only
after that validation succeeds. The current source remains available as an
immediate rollback setting.

Mirror batches stop starting at 6:55 p.m. Eastern, remain paused during the
7:00–7:20 p.m. VieFund snapshot window, and resume after the five-minute
stabilization buffer at 7:25 p.m. A scheduled incremental synchronization runs
at 7:25 p.m.; a second pass at 10:10 p.m. refreshes matching enrichment after
the nightly EFT, FSP, ASM, and bank imports. During initial population and
incremental synchronization, users continue reading the last verified source.

The deployment workflow enforces this App Service **Startup Command**:

```bash
bash /home/site/wwwroot/startup.sh || exit 1
```

Invoking the script through `bash` intentionally avoids depending on executable
permission bits from the ZIP deployment. It also removes the previous manual
copy to `/home/site/startup.sh`, which could leave Azure running a stale script.
The old persistent copy may remain on disk but is no longer used.

The explicit failure branch prevents Azure's generated startup wrapper from
silently falling through to PHP-FPM when Supervisor setup fails. The workflow
also enables **Always On**, waits for `/up` after the resulting restart, and
fails the deployment if the application does not become healthy. The startup
script runs `php artisan migrate --force`, rebuilds Laravel's configuration,
route, and view caches, and launches PHP-FPM, the queue worker, and scheduler
under Supervisor. Early startup diagnostics are written to
`/home/LogFiles/startup.log`.

Avoid deploying while a matching, import, export, or transaction-cache job is
actively running. Pause transaction caching and allow the current batch to stop
before pushing. An abruptly terminated database-queue job remains reserved until
`DB_QUEUE_RETRY_AFTER` elapses.

After deployment, verify the Azure SSH console:

```bash
cd /home/site/wwwroot
php artisan migrate:status
php artisan about --only=environment
supervisorctl status
php artisan queue:failed
curl -fsS http://127.0.0.1:8080/up
tail -100 /home/LogFiles/startup.log
```

`laravel-queue-cache-00`, `laravel-queue-general-00`, and `laravel-scheduler`
must report `RUNNING`. Cache hydration uses the `viefund-cache` queue with a
300-second reservation. Imports and other background jobs use the general
worker with a 3,700-second reservation for long-running work. Increase worker
counts through App Service settings rather than editing Supervisor files; keep
`QUEUE_CACHE_WORKERS=1` unless concurrent VieFund load has been benchmarked.

See [Production worker scaling](docs/production-worker-scaling.md) for queue
ownership, plan-size defaults, deployment checks, and scaling guardrails.

## One-off CIBC Transaction Balance CSV

The historical CIBC `TransactionBalance.csv` export can be imported into the
same bank entry, daily statement summary, balance, and analysis tables used by
CAMT statements. The importer validates the complete file before writing,
including headers, debit and credit totals, transaction counts, row-level
running balances, daily closing balances, and balance continuity between days.

CAMT data is authoritative. For the same account, currency, and business date,
the CSV importer skips the entire CSV day whenever an existing CAMT summary or
entry covers that date. If a CAMT statement is imported later, the CAMT
importer atomically removes lower-priority CSV and monthly PDF entries and
summaries for its covered dates. The CSV importer similarly replaces
lower-priority monthly PDF data for the dates it covers. This prevents
overlapping activity from being imported twice and keeps the precedence
`CAMT > Transaction Balance CSV > monthly PDF` independent of import order.

The `resources/data` directory is intentionally excluded from Git and the
deployment package. For production, upload the CSV to the configured bank SFTP
folder. The normal CAMT synchronization only matches XML files, so it will
ignore the CSV. The one-off importer downloads the exact filename to temporary
App Service storage, validates or imports it, and removes the temporary
web-server copy in all outcomes. It does not delete or modify the SFTP source.

```bash
cd /home/site/wwwroot
php artisan import:cibc-transaction-balance-csv \
  --sftp-file=bank_txns_09102025-04232026.csv \
  --dry-run

php artisan import:cibc-transaction-balance-csv \
  --sftp-file=bank_txns_09102025-04232026.csv
```

The command also runs the current `v2` bank-entry analysis. Use
`--replace-existing` to atomically rerun this same CSV import; replacement only
deletes rows associated with the CSV import and never deletes CAMT data. Use
`--skip-analysis` only when analysis will be run separately. The local
`--path=resources/data/cibc/TransactionBalance.csv` mode remains available for
development and offline validation.

## Historical CIBC Monthly PDF Statements

Text-based monthly CIBC account-statement PDFs can be imported into the current
bank entry, daily summary, balance, and analysis tables. The importer validates
the statement period, account and transit numbers, account-summary totals,
every transaction's running balance, daily balance continuity, and the final
closing balance before writing. Debit and credit direction is determined from
the exact balance arithmetic rather than transaction-description wording. When
a PDF has no embedded text, the importer automatically renders it with
Ghostscript and reads it with local Tesseract OCR. Financial statement images
remain on the application server and are never sent to an external OCR
service. OCR output must pass the same exact totals and running-balance checks
as embedded PDF text before it can be imported.

The importer creates one summary per calendar date, including no-activity
dates. This supports partial months safely: any date already covered by CAMT
or the Transaction Balance CSV is verified against that higher-priority source
and skipped, while uncovered dates from the same PDF remain importable. A
mismatch in daily counts, totals, or balances stops that PDF without changing
its bank data.

For production, upload the PDFs to the configured bank SFTP folder and preview
the complete batch before importing:

```bash
cd /home/site/wwwroot
php artisan import:cibc-monthly-pdf \
  --sftp-pattern='*.pdf' \
  --dry-run

php artisan import:cibc-monthly-pdf \
  --sftp-pattern='*.pdf'
```

The pattern can target one exact filename or a smaller batch, such as
`--sftp-pattern='2023*.pdf'`. Use `--remote-path=/another/folder` only when the
PDFs are outside `BANK_SFTP_REMOTE_PATH`. Each matching file is downloaded to
private temporary App Service storage and the web-server copy is removed after
that file succeeds or fails. OCR page images use a separate private temporary
directory and are also removed in all outcomes. The SFTP source is never
modified or deleted. Scanned statements take longer to process than text-based
PDFs; run the dry run before the production import.

Completed filenames are skipped on later batch runs. Use `--replace-existing`
to atomically rebuild PDF data for matching filenames; higher-priority CAMT and
CSV dates are still verified and preserved. The command runs the current `v2`
bank-entry analysis by default. Local development can use either one file or a
directory:

```bash
php artisan import:cibc-monthly-pdf \
  --path='resources/data/cibc/2025.10 - ADSC - CIBC Trust Account.pdf' \
  --dry-run

php artisan import:cibc-monthly-pdf \
  --path=resources/data/cibc \
  --pattern='2023*.pdf'
```

## Historical TD Monthly PDF Statements

TD Business Chequing statements use a separate importer because their secured
PDF format, transaction columns, and per-page totals differ from CIBC. The TD
importer extracts text locally with Ghostscript, identifies debit and credit
amounts from the statement columns, and validates every reported running
balance. It also verifies the combined credit/debit counts and totals from all
statement pages and requires the opening balance, transactions, totals, and
closing balance to reconcile exactly before writing.

TD account `0690-5544789` is stored separately from the CIBC account. The
legitimate July-October 2020 period in which both accounts were active is
preserved; activity is not deduplicated across different account numbers.
Consecutive TD statements share a balance-forward date, so the importer starts
each statement's daily summaries on the following day. This preserves one
summary per account and calendar date while retaining exact month-to-month
balance continuity.

For production, upload the TD PDFs to the configured bank SFTP folder, preview
the complete batch, and then run the import:

```bash
cd /home/site/wwwroot
php artisan import:td-monthly-pdf \
  --sftp-pattern='ADSC Trust AC * Bank Stmt.pdf' \
  --dry-run

php artisan import:td-monthly-pdf \
  --sftp-pattern='ADSC Trust AC * Bank Stmt.pdf'
```

Completed filenames are skipped, `--replace-existing` atomically rebuilds only
matching TD imports, and parser-v2 analysis runs by default. SFTP downloads are
retried with a fresh connection when a long-running session is interrupted.
Temporary server copies are removed after each file. Local validation uses the
same parser:

```bash
php artisan import:td-monthly-pdf \
  --path=resources/data/cibc \
  --dry-run
```

## Application Logins

Users sign in with their email address and password. Email matching is case-insensitive, and passwords are stored only as secure hashes. Successful and failed sign-in attempts are recorded in `login_attempts` with the user, normalized email, time, IP address, and browser user agent.

Until account-management screens are added, provision or update a login from the command line:

```bash
php artisan user:provision person@example.com --name="Person Name" --generate
```

`--generate` creates an eight-character word-and-number password and displays it once. An explicit eight-character alphanumeric value can instead be supplied with `--password`.

## Runtime Administration

Authorize administrators with a comma-separated Azure application setting. The intentionally supported `APP_ADMIN_ACCEESS` spelling matches the deployed configuration contract; `APP_ADMIN_ACCESS` is also accepted as an alias.

```dotenv
APP_ADMIN_ACCEESS=stacey@coldsnakedigital.com,other@example.com
```

Authorized accounts receive an **Admin** navigation link to `/admin/settings`. Export batch sizes, cache TTLs, hydration batch sizes, the All Transactions source and mirror controls, All Transactions column visibility/order, and the global main-menu layout are stored in MySQL and apply without restarting the application. The menu editor can rename, reorder, and place pages into one-level dropdown groups. Each page has independent **Visible** and **Admin Visible** controls for regular-user and administrator menus. Dropdown groups provide matching bulk controls that update every page in the group and show an indeterminate state when their pages are mixed; the Admin link remains fixed and cannot be hidden there.

Menu URLs are generated from the current hierarchy and presentation labels using lowercase kebab-case slugs. Standalone pages use `/{page}`, while grouped pages use `/{group}/{page}`; for example, Bank / FSP AGRA under Reconciliation is `/reconciliation/bank-fsp-agra`. Renaming a group or page, or moving a page, changes its URL immediately, and previous generated URLs are not retained. Admin saves reject empty slugs, duplicate paths, duplicate group slugs, and names reserved by application endpoints. Stable internal page routes redirect to the current presentation URL and preserve query-string filters.

Administrators also receive an **Impersonate** selector beneath the header contact information. It lists non-administrator accounts and switches the authenticated session to the selected user without carrying administrator permissions into that session. Selecting **None** restores the original administrator. Impersonation start and stop events are recorded in the application log.

`NAV_HIDE` supplies legacy visibility defaults only while no menu override has been saved. After an administrator saves the menu, its stored visibility takes precedence. Resetting the navigation removes that override and reapplies the deployed `NAV_HIDE` defaults. Run `php artisan migrate --force` during deployment before opening the page.

## Usage

1. Navigate to `/reconciliations` to view all reports
2. Click "Create New Report" to generate a new reconciliation report
3. Fill in the report details including title, period start/end dates, and description
4. View and export reports as needed

## Settlement Instruction Sync

The Settlement Instructions page can download and import FundSERV FSP files from SFTP. By default it reuses the `BANK_SFTP_*` connection settings. Define any of these variables when the FSP feed uses a different location or credentials:

```dotenv
SETTLEMENT_SFTP_HOST=
SETTLEMENT_SFTP_PORT=22
SETTLEMENT_SFTP_USERNAME=
SETTLEMENT_SFTP_PASSWORD=
SETTLEMENT_SFTP_REMOTE_PATH=/
SETTLEMENT_SFTP_LOCAL_PATH=resources/data/cibc
SETTLEMENT_SFTP_FILE_PATTERN=FSP*
SETTLEMENT_SFTP_DRY_RUN=false
```

Verify file discovery without downloading, importing, or deleting files:

```bash
php artisan settlement:sync-instructions --dry-run
```

## ASM Settlement Synchronization

The ASM Files page and scheduled synchronization pull Fundserv ASM workbooks
from SFTP, skip filenames already represented in the database, import new file
content, and map the resulting rows to VieFund automatically. By default the
connection reuses settlement or bank SFTP credentials and reads `/ASM_Files`:

```dotenv
VIEFUND_ALL_TRANSACTIONS_ASM_VISIBLE_COLUMNS=match_status,payment_id,payment_type,payment_type_description,settlement_date,currency,settlement_amount,agra_role,agra_account,source_file
ASM_SFTP_HOST=
ASM_SFTP_PORT=
ASM_SFTP_USERNAME=
ASM_SFTP_PASSWORD=
ASM_SFTP_REMOTE_PATH=/ASM_Files
ASM_SFTP_LOCAL_PATH=resources/data/asm
ASM_SFTP_FILE_PATTERN=asm_AGRA_*.xlsx
ASM_SFTP_DRY_RUN=false
```

ASM-specific credentials are optional when the existing `SETTLEMENT_SFTP_*` or
`BANK_SFTP_*` credentials access the same server. Leave the ASM host, port,
username, and password unset to reuse that connection. Preview remote, previously
imported, and eligible file counts without downloading anything:

```bash
php artisan asm:sync-settlements --dry-run
```

Run the complete download, import, and mapping process:

```bash
php artisan asm:sync-settlements
```

Files can still be placed in `resources/data/asm` and imported manually when
needed:

```bash
php artisan asm:import-settlements
```

The importer validates the ASM headers, records file-level summaries, imports
row-level payment data, and skips workbooks whose file content was already
imported. If a workbook's settlement-date header is damaged, the importer only
recovers the column when exactly one unmapped column contains the date encoded
in the `asm_AGRA_YYYYMMDD.xlsx` filename on every populated row. Successful
workbooks are still mapped to VieFund when another workbook in the same sync
fails. The **Data Sources → ASM Files** page shows file summaries above the
filterable transaction table and provides the SFTP sync action.

After importing new ASM workbooks, map their AGRA-side accounts to VieFund plans
and confirmed cash-ledger transactions:

```bash
php artisan asm:map-viefund
```

The **Map to VieFund** action on the ASM Files page runs the same mapping. Exact
matches require the VieFund dealer account, currency, settlement date, and signed
amount to agree on a confirmed cash transaction. ASM fields can then be selected
as a configurable column group in **All Transactions** and are included separately
in the match summary. An exact ASM match with no bank match receives the distinct
**ASM Only** status, an **N/A** bank variance, and the note **ASM match only, no
bank match**. Bank reconciliation remains authoritative: Complete, Verify, or
Possible bank results are not upgraded or downgraded by ASM evidence.

The scheduler runs the FSP sync daily at 9:45 p.m. and the ASM sync at 9:50 p.m.
in the `America/Toronto` timezone, after the bank EFT file sync and before the
bank statement sync.

## Project Structure

- `app/Models/Reconciliation.php` - Reconciliation model
- `app/Http/Controllers/ReconciliationController.php` - Report controller
- `database/migrations/` - Database migrations
- `resources/views/reconciliations/` - Report views
- `routes/web.php` - Application routes

## VieFund Report Notes

- Customer balances reconciliation criteria and run recipes:
    - `docs/viefund_customer_balances_report_criteria.md`
- Customer balances report overview and operating guide:
    - `docs/viefund_customer_balances_report_guide.md`
- Cash daily snapshot architecture and operations:
    - `docs/viefund_cash_daily_snapshots.md`
- Exclusion investigation notes for historic outlier analysis:
    - `docs/viefund_customer_balances_exclusion_review.md`

## License

This project is open-sourced software.
