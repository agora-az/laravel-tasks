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
php artisan serve
```

The application will be available at [http://127.0.0.1:8000](http://127.0.0.1:8000)

## Usage

1. Navigate to `/reconciliations` to view all reports
2. Click "Create New Report" to generate a new reconciliation report
3. Fill in the report details including title, period start/end dates, and description
4. View and export reports as needed

## Project Structure

- `app/Models/Reconciliation.php` - Reconciliation model
- `app/Http/Controllers/ReconciliationController.php` - Report controller
- `database/migrations/` - Database migrations
- `resources/views/reconciliations/` - Report views
- `routes/web.php` - Application routes

## License

This project is open-sourced software.
