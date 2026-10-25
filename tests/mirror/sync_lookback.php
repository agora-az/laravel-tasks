<?php

use App\Jobs\SyncVieFundTransactionMirror;
use App\Models\VieFundTransactionMirrorSync;
use App\Services\RuntimeSettings;
use App\Services\VieFund\Repositories\SqlServerVieFundRemoteRepository;
use App\Services\VieFund\VieFundAvailabilityWindow;
use App\Services\VieFund\VieFundRemoteService;
use App\Services\VieFund\VieFundTransactionMirrorManager;
use App\Support\ApplicationAdmin;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Foundation\Application;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';

Env::getRepository()->clear('VIEFUND_TRANSACTION_MIRROR_LOOKBACK_DAYS');
$app = new Application(dirname(__DIR__, 2));
$app->instance('config', new Repository([
    'viefund' => require dirname(__DIR__, 2).'/config/viefund.php',
]));
Facade::setFacadeApplication($app);
$database = new Manager($app);
$database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$database->setAsGlobal();
$database->bootEloquent();
DB::swap($database->getDatabaseManager());
Schema::swap($database->schema());

$migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_15_193500_create_viefund_transaction_mirror_tables.php';
$migration->up();
$verificationMigration = require dirname(__DIR__, 2).'/database/migrations/2026_10_24_000001_add_fund_id_verification_to_viefund_mirror.php';
$verificationMigration->up();
$reportMigration = require dirname(__DIR__, 2).'/database/migrations/2026_10_25_000001_add_cashagch_mirror_verification.php';
$reportMigration->up();
Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00', 'UTC'));
CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-24 12:00:00', 'UTC'));

$check = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$manager = new class(new RuntimeSettings(), new ApplicationAdmin(), new VieFundAvailabilityWindow()) extends VieFundTransactionMirrorManager
{
    public int $dispatchCount = 0;

    public function dispatch(?DateTimeInterface $delay = null): void
    {
        ++$this->dispatchCount;
    }
};
$remote = new class(new SqlServerVieFundRemoteRepository()) extends VieFundRemoteService
{
    public function countAllTransactions(?string $search = null, array $filters = []): int
    {
        return 0;
    }
};
$verification = new ReflectionMethod(SyncVieFundTransactionMirror::class, 'verifyOrContinue');

try {
    $check(config('viefund.mirror.incremental_lookback_days') === 180, 'Default mirror lookback must be 180 days.');
    foreach ([
        'default' => [180, '2026-04-28'],
        'missing-config-fallback' => [null, '2026-04-28'],
        'configured-override' => [30, '2026-09-25'],
        'minimum-one-day' => [0, '2026-10-24'],
    ] as $name => [$lookback, $expectedFrom]) {
        if ($lookback === null) {
            $mirrorConfig = config('viefund.mirror');
            unset($mirrorConfig['incremental_lookback_days']);
            config(['viefund.mirror' => $mirrorConfig]);
        } else {
            config(['viefund.mirror.incremental_lookback_days' => $lookback]);
        }
        VieFundTransactionMirrorSync::query()->delete();
        VieFundTransactionMirrorSync::query()->create([
            'id' => 1,
            'mode' => 'incremental',
            'state' => 'ready',
            'ready_at' => now(),
            'verified_at' => now(),
        ]);
        $manager->dispatchCount = 0;
        $sync = $manager->startIncremental();
        $check($sync->target_from_date->toDateString() === $expectedFrom, "$name: incorrect incremental start");
        $check($sync->target_through_date->toDateString() === '2026-10-24', "$name: incorrect incremental end");
        $check($sync->mode === 'incremental' && $sync->phase === 'daily_snapshot_sync', "$name: incorrect incremental mode");
        $check($manager->dispatchCount === 1, "$name: incremental sync was not dispatched once");
        $generation = $sync->sync_generation;
        $manager->startIncremental();
        $check($sync->fresh()->sync_generation === $generation && $manager->dispatchCount === 1, "$name: active sync was restarted");

        $sync->forceFill([
            'mode' => 'initial',
            'state' => 'verifying',
            'phase' => 'verification',
            'target_from_date' => '2020-01-01',
            'target_through_date' => '2026-10-24',
            'currencies' => ['00', '01'],
            'status_ids' => range(0, 6),
            'expected_rows' => 0,
            'ready_at' => null,
            'verified_at' => null,
        ])->save();
        $manager->dispatchCount = 0;
        $verification->invoke(new SyncVieFundTransactionMirror(), $sync, $remote, $manager);
        $sync->refresh();
        $check($sync->mode === 'catchup' && $sync->state === 'catching_up', "$name: initial sync did not start catchup");
        $check($sync->target_from_date->toDateString() === $expectedFrom, "$name: incorrect catchup start");
        $check($sync->target_through_date->toDateString() === '2026-10-24', "$name: incorrect catchup end");
        $check($manager->dispatchCount === 1, "$name: catchup was not dispatched once");
        echo "PASS: $name -- incremental and post-population catchup cover $expectedFrom through 2026-10-24\n";
    }
} finally {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
}
