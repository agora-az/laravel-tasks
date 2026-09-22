# Opus Reconciliation

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

The local launcher starts both the web server and the Laravel queue listener. The
queue listener is required for background work such as dashboard-summary and
VieFund report-date refreshes. If the web server is started separately with
`php artisan serve`, run `php artisan queue:listen --queue=default` in a second
terminal.

## Application Logins

Users sign in with their email address and password. Email matching is case-insensitive, and passwords are stored only as secure hashes. Successful and failed sign-in attempts are recorded in `login_attempts` with the user, normalized email, time, IP address, and browser user agent.

Until account-management screens are added, provision or update a login from the command line:

```bash
php artisan user:provision person@example.com --name="Person Name" --generate
```

`--generate` creates an eight-character word-and-number password and displays it once. An explicit eight-character alphanumeric value can instead be supplied with `--password`.

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

The scheduler runs the FSP sync daily at 9:45 p.m. in the `America/Toronto`
timezone, after the bank EFT file sync and before the bank statement sync. Set
`SETTLEMENT_SFTP_REMOTE_PATH` if a dry run reports zero matching remote files.

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
