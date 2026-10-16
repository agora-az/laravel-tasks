# Agora Reconciliation

A Laravel web application for generating and managing reconciliation reports.

## Features

- **Create Reconciliation Reports**: Generate reports for any time period
- **View Reports**: Browse and view all reconciliation reports
- **Export Reports**: Download reports in various formats
- **Period Management**: Track reconciliation data by date ranges

## Requirements

- PHP 8.2 or higher
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

The App Service **Startup Command** must be:

```bash
bash /home/site/startup.sh
```

The deployment package writes the repository copy to
`/home/site/wwwroot/startup.sh`. After changing that file, update the persistent
startup script through the Azure SSH console before restarting the App Service:

```bash
cp /home/site/startup.sh /home/site/startup.sh.bak
install -m 755 /home/site/wwwroot/startup.sh /home/site/startup.sh
```

Enable **Always On** for the App Service so Supervisor's queue worker and
scheduler continue running when the web application is idle. The startup script
runs `php artisan migrate --force`, rebuilds Laravel's configuration, route, and
view caches, and launches PHP-FPM, the queue worker, and scheduler under
Supervisor.

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
```

`laravel-queue-cache-00`, `laravel-queue-general-00`, and `laravel-scheduler`
must report `RUNNING`. Cache hydration uses the `viefund-cache` queue with a
300-second reservation. Imports and other background jobs use the general
worker with a 3,700-second reservation for long-running work. Increase worker
counts through App Service settings rather than editing Supervisor files; keep
`QUEUE_CACHE_WORKERS=1` unless concurrent VieFund load has been benchmarked.

See [Production worker scaling](docs/production-worker-scaling.md) for queue
ownership, plan-size defaults, deployment checks, and scaling guardrails.

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
imported. The **Data Sources → ASM Files** page shows file summaries above the
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
