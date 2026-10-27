# Production Worker Scaling

## Queue ownership

Production uses three independent database-queue worker pools:

| Queue             | Connection               | Workloads                                                    | Default workers |
| ----------------- | ------------------------ | ------------------------------------------------------------ | --------------: |
| `viefund-cache`   | `database_viefund_cache` | Working-set hydration, transaction mirror sync, metadata verification, transaction summaries and filtered counts |        1 |
| `imports,default` | `database`               | File imports, dashboard refreshes, report metadata, matching |               1 |
| `cashagch-reports` | `database_reports`       | Resumable CASHagch source batches, monthly counts and Excel assembly |          1 |

Hydration jobs for one working-set generation form a cursor-based serial chain.
Keep one cache worker unless concurrent remote VieFund queries have been tested.
The general pool can scale independently without changing cache ordering.
The report pool remains one worker per instance, with a 600-second stage timeout,
660-second reservation and up to three attempts per stage. Checkpoints and per-run
execution locks make retries and duplicate deliveries safe; the whole report has
no one-hour lifetime limit. Report reads can overlap cache hydration, so include
both pools when assessing remote SQL Server load.

CASHagch prefers a ready, report-verified transaction mirror with a completed
data sync within 24 hours. It falls back visibly to VieFund when that gate fails.
Only the remote path consumes SQL Server capacity or waits for its maintenance
window. Mirror synchronization can invalidate a report's pinned local source;
that run then discards local batches and restarts against VieFund.

For an existing populated mirror, run migrations and gracefully refresh workers
before queuing `php artisan viefund:sync-transaction-mirror --backfill-fund-ids`.
The cache worker upgrades missing CASHagch type/availability metadata, then
verifies history in separate monthly stages for all date bases and configured
currencies. It shares capacity with hydration. The migration widens indexed
timestamps to retain fractional seconds and adds cursor indexes, so allow for
DDL time on a large mirror. Fund-ID verification alone does not enable the
report's local source. Check `cashagch_ready` and any verification error in the
mirror status; the last **data** sync must also meet the freshness rule.

## App Service settings

```dotenv
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=3700
DB_VIEFUND_CACHE_QUEUE=viefund-cache
DB_VIEFUND_CACHE_QUEUE_RETRY_AFTER=300
QUEUE_GENERAL_WORKERS=1
QUEUE_CACHE_WORKERS=1
CACHE_STORE=database
```

`CACHE_STORE=database` is required for scheduler leadership and overlap locks
to work across multiple App Service instances.

## Starting points by plan

| App Service plan     | General workers | Cache workers | Notes                                              |
| -------------------- | --------------: | ------------: | -------------------------------------------------- |
| P1V3, 2 vCPU / 8 GB  |               1 |             1 | Uses both cores while limiting VieFund concurrency |
| P2V3, 4 vCPU / 16 GB |               2 |             1 | Add a general worker first                         |
| P3V3, 8 vCPU / 32 GB |               4 |             1 | Benchmark before adding a second cache worker      |

Worker counts apply per App Service instance. Scaling out multiplies the total
worker count, so review remote SQL Server and MySQL load before adding instances.
Scheduled tasks use shared database locks and `onOneServer()` to prevent each
instance from launching the same schedule.

## Hydration tuning

The working-set batch size is runtime-configurable in Admin and defaults to
1,000 rows. Test 2,000 rows before larger values. Compare:

- Rows cached per minute
- Batch duration relative to the 110-second job timeout
- App Service CPU and memory
- VieFund SQL Server query latency
- MySQL write latency

Increase the job timeout, the overlap-lock expiry, and the cache queue
`retry_after` together if a larger batch approaches the current timeout.

## Deployment

The deployment workflow enforces the fail-closed startup command and enables
Always On:

```bash
bash /home/site/wwwroot/startup.sh || exit 1
```

Running the deployed script through `bash` requires read access but not an
executable permission bit, so no post-deployment copy into `/home/site` is
needed. This also prevents a persistent copied script from falling behind the
repository version.

Production workflows are serialized without cancelling an active deployment.
Runtime settings are applied before ZIP deployment; the ZIP deployment performs
the restart, without a second explicit restart that could interrupt migrations.
Startup runs `php artisan deploy:migrate`, which holds a MySQL session advisory
lock before checking pending migrations. Other startup processes wait up to
30 minutes for that lock and fail closed if they cannot acquire it. Losing the
database connection also fails closed rather than reconnecting without the lock.
Use this command, rather than unguarded `migrate --force`, for manual production
migrations.

The CASHagch migration combines missing mirror-row columns, timestamp widening,
and cursor indexes into one MySQL `ALTER TABLE`, and skips timestamp changes
already applied by an interrupted attempt. A populated mirror can still require
a long rebuild. The workflow checks `/up` for up to 60 minutes using an elapsed-time
deadline; it does not start PHP-FPM or workers before migrations finish. Migration
and cache-command output is also streamed to container logs, with startup phase
markers, while retaining `/home/LogFiles/startup.log`.

Run `php tests/migrations/deployment_migrations.php` with the local MySQL server
available to test cross-process serialization, failure cleanup, and resumable
MySQL schema changes. The test creates and drops its own temporary database and
refuses non-local database hosts. The test account must be able to create that
temporary database. Alternatively, point `DEPLOYMENT_TEST_DB_SOCKET` at a
disposable, socket-only MySQL instance initialized with an empty root password;
this keeps the application database and its account entirely out of the test.

After deployment, verify:

```bash
cd /home/site/wwwroot
supervisorctl status
php artisan schedule:list
php artisan queue:failed
tail -100 /home/LogFiles/startup.log
```

Expected Supervisor programs are `laravel-queue-cache-00`,
`laravel-queue-general-00`, `laravel-queue-reports-00`, and `laravel-scheduler`.

## All Transactions count isolation

The optional record/page total must never scan transaction rows inside PHP-FPM.
Unfiltered period caches can use their saved count when it belongs to the readable
generation. During refresh, that saved value belongs to the new build and cannot
be used as the old active generation's total. All other totals use
`CountVieFundAllTransactions` on the existing `database_viefund_cache` connection,
regardless of the default `QUEUE_CONNECTION`. A synchronous cache-queue driver is
rejected rather than silently running a count inside the web request.

Counts are deduplicated with shared cache locks per source, readable generation,
search and filters. Page/per-page changes reuse the result. A global execution
lock serializes counts across cache workers/instances; scaling the web/general
workers does not increase count concurrency. Keep `CACHE_STORE=database`.
Immutable results last one hour; incomplete-generation totals last five seconds
and remain explicitly partial. Promotion, generation changes and mirror sync or
verification changes invalidate the source identity. A mirror that is currently
syncing waits rather than publishing an obsolete count.

Count SQL uses MySQL `MAX_EXECUTION_TIME(30000)` or the dedicated
`viefund_count_sqlsrv` connection's 30-second PDO statement timeout. SQL Server
counts refuse to run without the expected driver deadline. The count job also
requires PCNTL worker-timeout support and has a 45-second timeout, fails on its
first exception/timeout, and uses a 90-second execution lock. Overlap releases
can retry without retrying a failed SQL query. Missing workers become an explicit
failure after ten minutes queued; an unresponsive running count is marked failed
after 75 seconds. Stale/late jobs cannot overwrite another dispatch's status.

Previous/next browsing uses simple pagination on both period caches and the
mirror. Numbered pagination is filled in only after a real count arrives. A
missing/failed total is not reported as zero and does not disable browsing or
change transaction/report data. Browser count requests time out after 15 seconds,
retry transport/invalid-response failures at most twice with backoff, stop on
terminal/server/session failures, and have a 120-request budget including partial
results. Refresh later to retry an unavailable total; failed status is retained
for two minutes.

Queued, started, completed and failed count events include `count_signature`.
Started events include queue wait; completed events include source, total and
elapsed milliseconds. The `viefund_transaction_counts` channel rotates daily
and retains 14 files. On App Service it writes outside the webroot to
`/home/LogFiles/viefund-transaction-counts-YYYY-MM-DD.log`; elsewhere it uses
`storage/logs`. `VIEFUND_TRANSACTION_COUNTS_LOG_PATH` overrides the base path.
Check this log, the cache-worker log and `php artisan queue:failed` when totals
become unavailable. Do not retry a timed-out expensive count repeatedly; review
its scope and query plan first.

Focused regressions:

```bash
php tests/exports/all_transactions_count.php
php tests/exports/all_transactions_count_deadlines.php
php tests/exports/all_transactions_cache.php
node tests/exports/all_transactions_count_ui.js
node tests/exports/all_transactions_cache_ui.js
```

The deadline test uses only local MySQL (`127.0.0.1`, root with an empty password,
or `DEPLOYMENT_TEST_DB_SOCKET` for a disposable local instance). It creates no
tables and checks that a 31-second sleeping aggregate is interrupted at 30
seconds. Its SQL Server checks validate SQL routing/configuration, not live
VieFund performance.

### Rolling out after emergency count isolation

The October 27 recovery guard is an exact nginx location returning JSON
`state: failed` and HTTP 503 for `/viefund-transactions/count`. Leave it active
until the replacement is verified. It exists only in the live container:
`startup.sh` overwrites nginx configuration from the deployed `default` file,
so a restart or ordinary release can remove it.

Before a controlled deployment, obtain approval for the restart, check active
exports/report jobs, and prepare a rollback of the **currently deployed** code
that also retains count isolation. Use a non-clean deployment to preserve
runtime reports/uploads. Preserve the nginx guard in the rollout package or
equivalent startup protection; do not rely on the current live edit surviving.
Refresh the cache worker to load the new job/service. Before removing the guard,
verify deployed file hashes, log-directory access as `www-data`, healthy web
responses, and an isolated background count using the new code. Then remove only
the temporary exact-location guard, validate/reload nginx, and verify an
authenticated filtered count returns promptly as processing, completes or fails
explicitly within its budget, and never creates PHP-FPM count queries. Also
check worker status, PHP backlog and `/up`. Do not automatically roll back
without approval, and do not roll back to unprotected synchronous count code.

## All Transactions export diagnostics

All Transactions exports still run as background CLI processes, not on the
resumable CASHagch queue. A restart can interrupt them, and a clean deployment
can remove report files. These changes improve diagnosis and reconnection; they
do not make an interrupted All Transactions export resumable.

The export form, status requests and download URLs use the current origin to
avoid cross-host or mixed-content requests behind Azure/custom-domain proxies.
Browser tracking is scoped to the signed-in account. Old unscoped tracking IDs
are not imported because they may belong to another account.

Start requests have a browser-generated request UUID. Accepted exports also have
a run UUID. Connection/startup failures show the available reference so support
can correlate browser reports with server events. A lost startup response is not
automatically retried: clicking Export Excel again can reconnect to an existing
run through the server's conflict response. Interrupted status requests retry
twice, then retain their tracking ID and offer reconnection via Export Excel
without another start request. Session errors instead instruct the user to
reload/sign in. Unavailable old runs are informational, not red "Idle" errors.

The `all_transactions_export` logging channel rotates daily and retains 14 files.
On Azure App Service (`WEBSITE_SITE_NAME` present), it defaults to
`/home/LogFiles/all-transactions-export-YYYY-MM-DD.log`, outside the clean-deployed
webroot. Elsewhere it uses `storage/logs`. Override the base path with
`ALL_TRANSACTIONS_EXPORT_LOG_PATH` and rebuild the configuration cache. Log level
follows `LOG_LEVEL`; use `info` to retain lifecycle events.

Events cover start receipt, dispatch, worker startup/completion/failure, invalid
or missing status/workbook files, failed startup acknowledgements and confirmed
dead workers. Events include account/reference and instance/PID metadata where
available; completion includes duration, rows, sheets, output size, stage timings
and peak PHP memory. PID liveness is checked only on the worker's own instance.
Status publication failures now fail explicitly instead of silently losing
progress. PHP failures before the worker boots still go to
`storage/logs/viefund-all-transactions-export.log`.

An authenticated, CSRF-protected endpoint, throttled to 20 requests/minute, records
allowlisted browser metadata: phase, failure category, HTTP status, retry attempt,
online flag and UUID references. It does not collect form filters, customer or
transaction data, browser URLs, arbitrary error text or CSRF tokens. If the browser
cannot reach that endpoint, it records a console warning instead; a total network
outage cannot reliably be reported to the server at that moment.

For an incident, collect the time (including timezone) and displayed reference:

```bash
grep -F 'REFERENCE-UUID' /home/LogFiles/all-transactions-export-*.log
tail -100 /home/site/wwwroot/storage/logs/viefund-all-transactions-export.log
tail -100 /home/LogFiles/startup.log
```

If the start event exists without a browser response, compare dispatch and worker
events. Dispatch without worker startup points to CLI/bootstrap/process failure;
worker startup without completion/failure points to an interruption or hard kill.
Correlate these with App Service restarts, deployments and memory/CPU metrics.
Browser network/timeout reports alone do not establish the underlying cause.
If no start event exists, inspect the browser Network/Console records and Azure
access/platform logs; the request may not have reached Laravel.

Regression checks:

```bash
node tests/exports/all_transactions_export_ui.js
php tests/exports/all_transactions_export_status.php
php tests/exports/all_transactions_fund_id.php
```
