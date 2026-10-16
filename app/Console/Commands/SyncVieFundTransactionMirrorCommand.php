<?php

namespace App\Console\Commands;

use App\Services\VieFund\VieFundTransactionMirrorManager;
use Illuminate\Console\Command;
use InvalidArgumentException;

class SyncVieFundTransactionMirrorCommand extends Command
{
    protected $signature = 'viefund:sync-transaction-mirror
        {--initial : Start the inception-to-date population}
        {--pause : Pause at the current committed checkpoint}
        {--resume : Resume a paused or failed synchronization}
        {--status : Display the current synchronization state}';

    protected $description = 'Populate and incrementally synchronize the canonical VieFund transaction mirror';

    public function handle(VieFundTransactionMirrorManager $manager): int
    {
        try {
            $sync = match (true) {
                (bool) $this->option('initial') => $manager->startInitial(),
                (bool) $this->option('pause') => $manager->pause(),
                (bool) $this->option('resume') => $manager->resume(),
                (bool) $this->option('status') => $manager->sync(),
                default => $manager->startIncremental(),
            };
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($manager->status($sync), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
