# Production Worker Scaling

## Queue ownership

Production uses two independent database-queue worker pools:

| Queue | Connection | Workloads | Default workers |
| --- | --- | --- | ---: |
| `viefund-cache` | `database_viefund_cache` | All Transactions working-set hydration | 1 |
| `imports,default` | `database` | File imports, dashboard refreshes, report metadata, matching | 1 |

Hydration jobs for one working-set generation form a cursor-based serial chain.
Keep one cache worker unless concurrent remote VieFund queries have been tested.
The general pool can scale independently without changing cache ordering.

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

| App Service plan | General workers | Cache workers | Notes |
| --- | ---: | ---: | --- |
| P1V3, 2 vCPU / 8 GB | 1 | 1 | Uses both cores while limiting VieFund concurrency |
| P2V3, 4 vCPU / 16 GB | 2 | 1 | Add a general worker first |
| P3V3, 8 vCPU / 32 GB | 4 | 1 | Benchmark before adding a second cache worker |

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

The active startup script is persisted outside the deployment root at
`/home/site/startup.sh`. After deploying a startup-script change:

```bash
cp /home/site/startup.sh /home/site/startup.sh.bak
install -m 755 /home/site/wwwroot/startup.sh /home/site/startup.sh
```

Restart the App Service, then verify:

```bash
cd /home/site/wwwroot
supervisorctl status
php artisan schedule:list
php artisan queue:failed
```

Expected Supervisor programs are `laravel-queue-cache-00`,
`laravel-queue-general-00`, and `laravel-scheduler`.