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

ZIP deployment waits for package deployment to complete (`--async false`) but
disables the CLI's separate Linux startup-status polling (`--track-status false`).
That polling can report a startup timeout even when Kudu marks the deployment
active and the app is healthy. Package deployment errors still fail the job;
the subsequent explicit restart and `/up` check verify application startup.

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
VIEFUND_TRANSACTION_MIRROR_LOOKBACK_DAYS=180
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

Manual **Sync now**, scheduled incremental syncs, and the post-population catchup
refresh 180 calendar days by transaction creation date, including today, by
default. This captures status changes on transactions created up to that window
ago. If Azure already defines `VIEFUND_TRANSACTION_MIRROR_LOOKBACK_DAYS=90`,
change that App Service setting to `180`; an explicit setting overrides the
deployed default. Existing in-progress runs retain their selected range.

### Enabling fund-filtered mirror queries and exports

After deploying the fund-ID upgrade, run migrations (the production startup
script does this automatically). For an existing verified mirror, use **Admin /
Application Settings / Upgrade / verify fund metadata**, or queue the same operation:

```bash
php artisan viefund:sync-transaction-mirror --backfill-fund-ids
php artisan viefund:sync-transaction-mirror --status
```

Let any active mirror synchronization finish before starting the backfill.
The existing mirror worker processes bounded cash-ID batches across the entire
local mirror, including history older than 180 days. It updates the indexed fund
column, transaction payload and fingerprint without rebuilding the mirror or
recomputing matching enrichment. Separate linked-fund metadata preserves
transactions linked to more than one fund: a filter can match any linked fund,
while each cash transaction is still counted/exported once. Displayed fund,
source, transaction type and fund-transaction ID follow the matching fund group.

Progress, errors and **Fund / Unit IDs: Verified** appear in the mirror status
panel. Existing **Pause** and **Resume** controls (or `--pause` / `--resume`) retain
committed progress, including after a failed lookup. The job observes the same
maintenance window and overlap lock as ordinary mirror synchronization.
Missing source cash transactions fail explicitly and do not get marked as
fund-less; cash-only transactions with a verified null fund are valid.

Fund-filtered tables, counts, totals and exports retain the current period-cache
path until every mirror row has been checked against the source. Once verified,
they follow the existing data-source setting; verification does **not** switch
that setting automatically. Validate the relevant fund/date/status filters under
**Local mirror - administrators only**, then select **Local mirror - all users**.
New initial populations and subsequent incremental syncs maintain the same
verification metadata automatically. Direct CLI fund-filtered mirror exports
are rejected until this verification is complete.

Before production rollout, update an explicit Azure lookback override to `180`
if present, deploy/migrate, backfill, validate in admin preview, then enable for
all users. No mirror rebuild or deletion is required. The 180-day refresh is by
**creation date**, not a guarantee to revisit arbitrarily old transactions.

Isolated regression checks (in-memory SQLite and temporary XLSX files, without
touching application or remote databases):

```bash
php tests/mirror/sync_lookback.php
php tests/mirror/fund_id_backfill.php
php tests/exports/all_transactions_fund_id.php
```

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

If any deployment step fails, the workflow attempts to collect the App Service
container startup log and per-instance troubleshooting status before logging
out of Azure. The detailed script log is also available through authenticated
Kudu at `/api/vfs/LogFiles/startup.log`.

The fund-ID cache migration checks each column and index independently so it
can resume after interrupted MySQL DDL, which is not rolled back as a unit.
For a `Duplicate column name 'fund_id'` startup failure on the original
deployment, deploy the corrected migration and let `migrate --force` finish
the missing steps. Do not drop the cache tables or manually mark the migration
complete. Run its isolated regression checks locally with
`php tests/migrations/fund_id_cache_migration.php` (uses in-memory SQLite).

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

`laravel-queue-cache-00`, `laravel-queue-general-00`,
`laravel-queue-reports-00`, and `laravel-scheduler`
must report `RUNNING`. Cache hydration uses the `viefund-cache` queue with a
300-second reservation. Imports and other background jobs use the general
worker with a 3,700-second reservation for long-running work. CASHagch uses
the dedicated `cashagch-reports` queue on `database_reports`, with one worker,
a 600-second per-stage timeout and a 660-second reservation. Increase worker
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

The Reports page uses a single **Run Report** action per report card and generates Excel workbooks only; CSV format menus are no longer offered, and report web endpoints reject CSV submissions. CASHagch uses the same compact filters and right-aligned action layout as the other report cards. The Snapshot Monitor's separate audit CSV download is unchanged, as are CSV imports/exports elsewhere and command-line report compatibility.

### All Transactions period cache

With **Current source**, All Transactions builds its period cache on the background cache queue and can display already-cached rows before completion. Normal date-basis sorting uses the existing indexed pagination date; alternate date and amount sorts retain their selected ordering. The page reads only the requested rows plus one for next-page navigation, without a full-result count. Numbered pagination and totals load separately; unfiltered totals use the saved cache row count, except when an older generation remains readable during a refresh. A new period with no cached rows still reads its first page from VieFund.

If caching fails or is paused, available rows remain browsable, totals are marked as partial, and summaries show explicit retry/resume guidance instead of waiting indefinitely. Use the play button above the transaction table (**Retry period cache** or **Resume period cache**) to continue the saved generation and cursor without discarding cached rows. The page reloads after a successful retry/resume so summary polling restarts. This pagination correction requires no additional migration or index build. Regression checks: `php tests/exports/all_transactions_cache.php` and `node tests/exports/all_transactions_cache_ui.js`.

Hydration updates the saved row count by checking only the current batch's cash IDs before its upsert, under the working-set transaction lock. Duplicate or overlapping retries do not inflate progress, and neither each batch nor final publication recounts the entire cached generation.

**Filtered Transaction Totals** and **Match Status Summary** are calculated on the existing `viefund-cache` worker, not inside a web request. A completed period cache automatically queues its unfiltered summary; existing caches and other filter selections queue summaries on demand. The page polls lightweight persisted status, displays processed-transaction progress, and offers **Retry summaries** after failure or stalled progress. Each continuation processes at most 1,000 transactions with its own retry budget. Saved cursors, unrounded financial totals and cross-batch linked-record deduplication are committed together, so retries cannot double-count transactions, EFT/FSP batch totals or bank records.

Results are stored in MySQL for the exact source generation, search and row filters, rather than expiring after five minutes. Match-status checkboxes reuse the same breakdown and filter its cash totals; sorting and pagination do not create another summary. Period-cache replacement or mirror changes select a new result, and mirror reads wait for a stable completed synchronization. Summaries with no progress for 14 days are pruned, including abandoned runs; deleting a period cache cascades to its summaries. This requires the new summary/record tables, but does not alter or rebuild existing transaction tables. Keep the cache worker and scheduler running. Regression checks include `php tests/exports/transaction_summaries.php`.

### CASHagch purchases and redemptions

On the **Reports** page, use **CASHagch Purchases and Redemptions** to generate an Excel workbook. The default scope is inception through today, CAD, Confirmed cash transactions, by settlement date. Enter an optional **Start Date** for a narrower range, or choose **Use Inception Date** to populate it with the same basis-specific report inception date shown on the other report cards. The displayed inception date updates with the date basis; the button is disabled if that date is unavailable. Leaving or clearing the start date still includes all available history without assuming a fixed historical date. The start date cannot be after the end date; both dates apply to the selected date basis. The end date, date basis, currency and statuses can also be changed. There is no recent-sync-window cutoff, and the Summary and download filename identify the selected period.

The workbook starts with the **Transactions** detail sheets, followed by **Summary** and **Monthly Summary**. The Summary contains separate **Total Purchases**, **Standard Redemptions**, **Fee Redemptions**, and **Total Redemptions (Including Fees)** amounts and transaction counts. All monetary cells throughout the workbook use dollar accounting formatting with thousands separators, two displayed decimal places, and red parentheses for negative amounts. The underlying numeric values retain their original precision; transaction counts are not formatted as money. Purchases include Purchase, Purchase PAC and Rebalancing purchase; standard redemptions include Redemption and Rebalancing redemption. Purchase totals negate the original signed amounts; redemption totals sum their signed amounts. Opposite-sign reversals reduce the applicable total rather than being counted as additional positive activity.

**Monthly Summary** groups the same included transactions by calendar month using the selected date basis, currency, statuses and inclusive date range. Purchases are **in**, and redemptions are **out**, based on their transaction categories rather than the cash sign. Standard and fee redemptions are shown separately and combined; other/review signed amounts and net signed transaction amounts remain separate. Reversals reduce their category in the month of their selected transaction date. Monetary totals and transaction counts reconcile to the overall Summary, including a Grand Total row. Zero-activity months are included from the selected start month (or the first matching transaction month for inception) through the selected end month; partial months contain only transactions within the selected dates. An empty inception report has no assumed starting month and shows zero grand totals with an explicit no-activity note.

Amounts come exclusively from the VieFund cash transaction amount (`UB_CashTrx.mAmount`), never bank amounts. No bank match is required. Each cash transaction is counted once. Other, unknown, and conflicting linked fund transaction types are placed in **Other / review required**, with a warning in the Summary rather than being classified by cash direction. The detail sheets preserve original signed amounts, identifiers, transaction types, dates, statuses and summary categories.

The report prefers the **verified local VieFund transaction mirror**, independently of the All Transactions source-mode setting. The mirror must be ready (not actively syncing), have verified fund IDs, strict CASHagch transaction-type metadata and historical coverage, cover the selected currency/statuses, and have a completed data sync **no more than 24 hours old**. Otherwise the report uses **Direct VieFund**, with an explicit fallback reason. The page and workbook Summary identify the chosen source and mirror's last data-sync time; metadata verification alone does not refresh that timestamp. No period-cache hydration is required. Both sources retain the application's cash-account eligibility/exclusions, and neither applies the simulated cash-account-opened cutoff.

Existing fund-ID backfills did not retain conflicting fund transaction types or the trust amounts needed for historical trade-date cash availability. After deploying and running migrations, refresh long-lived workers and use **Admin / Application Settings / Upgrade / verify fund metadata**, or `php artisan viefund:sync-transaction-mirror --backfill-fund-ids`. This upgrades unverified fund IDs and CASHagch-linked rows missing report metadata in bounded batches, restores fractional indexed dates from the original payload, and verifies coverage against VieFund one calendar month, date basis and currency at a time. It does not recompute bank-match enrichment. Coverage mismatches remain visible in Admin and force a report fallback rather than enabling incomplete local reports. New mirror populations and incremental syncs maintain this metadata; missing/changed-scope verification is completed automatically after data synchronization. An uninitialized mirror must first complete its normal initial population. A metadata upgrade is not a substitute for populating missing history or refreshing stale data.

The completed backfill row count is separate from CASHagch historical coverage verification. During that final phase, Admin identifies the date basis, currency and month being checked and does not display a row-based ETA or throughput. First-date discovery uses an exact-fund `MIN` aggregate, not an ordered full-history row probe, so a currency with no matching CASHagch transactions does not inherit an expensive `TOP(1)` scan plan. Completed checks save their own activity timestamp. Reports remain on Direct VieFund until verification and the other source-readiness checks pass; an already-running report retains its selected source and does not switch mid-report. These changes do not require another migration or a new metadata backfill.

Source identity is saved in the report checkpoint. Each local count/read holds a shared lock on the mirror sync record, preventing a concurrent sync from modifying that batch. If a refresh, state change or accounting-scope change is detected between stages, saved mirror batches are discarded and the same run explicitly restarts from VieFund; sources are never mixed in one workbook. Once all batches are staged, finalization can safely use that frozen data even if the mirror subsequently refreshes.

The report runs on the dedicated `cashagch-reports` queue using the `database_reports` connection; keep the report worker running (`scripts/workers.sh` locally, `laravel-queue-reports-00` in production). The page shows saved-transaction progress and remembers the latest run for that user after a refresh. Reports started on this page, or resumed while still in progress, automatically download once when completed while the page remains open, using the same download mechanism as the other reports. The **Download Excel report** link remains available if the browser blocks the automatic download or another copy is needed. Revisiting an already-completed run does not automatically download it again. Failed or cancelled reports never trigger a download. Run/download status expires after 12 hours without an update. Only direct VieFund reads pause for source maintenance; active remote reports retain saved batches and automatically resume afterwards. Verified mirror reads and local workbook assembly can complete during maintenance.

Full history no longer has to finish inside one hour-long job. The worker saves the first source batch, counts matching transactions one calendar month per job, then reads at most 1,000 transactions per job (or the smaller configured export batch size). Exact-fund queries select candidate cash IDs before aggregating linked fund metadata for just those IDs; counting does not aggregate full-history fund metadata. Each completed stage atomically saves its cursor and private, checksummed transaction batches under `storage/app/reports/cashagch-{userId}-{runId}` before queuing the next stage. An interrupted worker retries from that checkpoint rather than starting over; each stage has up to three attempts. A hard-killed worker's reservation becomes retryable after 660 seconds, longer than its 600-second execution limit. Separate continuation jobs prevent long reports from exhausting attempts or overflowing the database queue's one-byte attempt counter.

After two saved read batches and completion of the monthly counts, the page estimates remaining transaction-reading time from cumulative measured batch throughput. Estimates exclude queue wait, counting, maintenance pauses and final Excel packaging. Finalization streams the saved batches into one workbook without further VieFund queries and retries independently if interrupted. Only the closed workbook is atomically published for download. Completed, failed and cancelled runs clear ETA; cancellation and terminal failure discard staged transactions, partial output and writer temporary files. Terminal checkpoints remain to prevent delayed jobs from restarting finished runs. Source outages, storage failures or an individual stage repeatedly exceeding its limit still fail explicitly; partial workbooks are never offered. Runs already executing older code must be generated again after deploying this change.

Details stream to Excel and split using the existing **All Transactions export rows per sheet** setting (up to 1,000,000 transactions per sheet). Both summary sheets cover the complete result across every detail sheet. Run `php tests/exports/cashagch_report.php` for isolated workbook, monthly reconciliation, accounting-format, worksheet-order, calculation, source-query and request-lifecycle regression checks, `php tests/mirror/cashagch_report_source.php` for local/remote workbook parity across all four date bases, coverage/freshness gates, metadata upgrades, snapshot changes, maintenance and a 100,001-row local-read benchmark, `node tests/exports/cashagch_report_ui.js` for the report form's JavaScript lifecycle, visible sources and automatic downloads, and `node tests/exports/report_controls_ui.js` for Excel-only button and keyboard submissions on the other report cards.

Run `php tests/exports/cashagch_report_resumable.php` for a real isolated SQLite queue and Laravel worker-timeout recovery test, transient failures, duplicate-worker protection, interrupted workbook assembly/publication and integrity checks. It builds and streams back a 100,001-transaction workbook covering 262 months, verifies every transaction exactly once and reconciles the financial summaries. It also checks a report lifetime exceeding one hour, more than 255 continuation stages, local assembly under two minutes and peak PHP memory under 256 MiB. PHP `pcntl` is required for its actual worker-termination test. This fixture measures local assembly and recovery, not production SQL Server latency; verify an inception run after deployment.

### Cancelling a report

Each report card (CASHagch, Daily Net + Running Balance, Legacy Daily Balance, and Customer Balances) shows **Cancel report** while a supported run belonging to the signed-in user is queued or running. Cancellation is tied to that report's unique run ID; it never terminates a shared worker or another user's report.

Queued CASHagch runs cancel immediately and are skipped by the worker. Running reports show **Stopping...** until the current database operation finishes, then stop before further work, discard partial output, clear their ETA and release their report lock. A database query or Excel packaging operation already executing cannot be interrupted instantly by this cooperative mechanism. No partial report is offered for download. Cancellation and completion use the same per-run state lock so an accepted cancellation cannot subsequently publish a completed report.

Runs launched before cancellation support was installed do not advertise a Cancel button. Reload long-running queue workers after updating the application; new runs include cancellation support. Cancellation markers are retained with report artifacts to prevent delayed jobs from reviving a cancelled run.

Run `php tests/exports/report_cancellation.php` and `node tests/exports/cashagch_report_ui.js` to verify cancellation ownership, queued/running behavior, partial-file cleanup, completion races, status locks and shared button behavior.

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
