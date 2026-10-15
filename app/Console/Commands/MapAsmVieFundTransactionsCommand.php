<?php

namespace App\Console\Commands;

use App\Services\Reconciliation\AsmVieFundMatcher;
use Illuminate\Console\Command;

class MapAsmVieFundTransactionsCommand extends Command
{
    protected $signature = 'asm:map-viefund';

    protected $description = 'Map imported ASM settlements to VieFund plans and confirmed cash transactions';

    public function handle(AsmVieFundMatcher $matcher): int
    {
        $counts = $matcher->rebuild();

        $this->info(sprintf(
            'Mapped %d ASM rows: %d exact cash matches, %d account-only matches, %d unresolved accounts.',
            $counts['total'],
            $counts[AsmVieFundMatcher::EXACT],
            $counts[AsmVieFundMatcher::ACCOUNT_ONLY],
            $counts[AsmVieFundMatcher::ACCOUNT_UNRESOLVED]
        ));

        return self::SUCCESS;
    }
}
