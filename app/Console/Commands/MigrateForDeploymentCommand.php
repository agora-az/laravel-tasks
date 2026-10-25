<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

class MigrateForDeploymentCommand extends Command
{
    protected $signature = 'deploy:migrate {--wait=1800 : Maximum seconds to wait for another migration process}';

    protected $description = 'Run deployment migrations under a shared MySQL session lock';

    public function handle(DatabaseManager $database): int
    {
        $wait = filter_var($this->option('wait'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 1800],
        ]);
        if ($wait === false) {
            $this->error('The migration lock wait must be an integer between 0 and 1800 seconds.');

            return self::FAILURE;
        }

        $connection = $database->connection();
        if ($connection->getDriverName() !== 'mysql') {
            $this->error('Deployment migration locking requires the application MySQL connection.');

            return self::FAILURE;
        }

        $lockName = 'agora:migrate:'.substr(hash('sha256', $connection->getDatabaseName()), 0, 48);
        $pdo = $connection->getPdo();
        $this->info("Waiting up to {$wait} seconds for the deployment migration lock...");
        $lock = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $lock->execute([$lockName, $wait]);
        if ((int) $lock->fetchColumn() !== 1) {
            $this->error('Unable to obtain the deployment migration lock. No migrations were run.');

            return self::FAILURE;
        }

        // Reconnecting would lose the session lock and permit concurrent DDL.
        $connection->setReconnector(static function (): never {
            throw new RuntimeException('The database connection was lost while holding the deployment migration lock.');
        });

        try {
            $this->info('Deployment migration lock acquired. Running pending migrations...');

            return $this->call('migrate', ['--force' => true, '--no-interaction' => true]);
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
            if ((int) $release->fetchColumn() !== 1) {
                throw new RuntimeException('The deployment migration lock was not owned at release.');
            }
            $database->purge($connection->getName());
        }
    }
}
