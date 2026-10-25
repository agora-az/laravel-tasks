# Production Worker Scaling

## Queue ownership

Production uses three independent database-queue worker pools:

| Queue             | Connection               | Workloads                                                    | Default workers |
| ----------------- | ------------------------ | ------------------------------------------------------------ | --------------: |
| `viefund-cache`   | `database_viefund_cache` | Working-set hydration, transaction mirror sync and metadata verification |        1 |
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

The workflow restarts the App Service as part of applying those settings and
waits for the `/up` health endpoint. After deployment, verify:

```bash
cd /home/site/wwwroot
supervisorctl status
php artisan schedule:list
php artisan queue:failed
tail -100 /home/LogFiles/startup.log
```

Expected Supervisor programs are `laravel-queue-cache-00`,
`laravel-queue-general-00`, `laravel-queue-reports-00`, and `laravel-scheduler`.
