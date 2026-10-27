<?php

namespace App\Jobs;

use App\Services\VieFund\VieFundAllTransactionsCountService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use RuntimeException;
use Throwable;

class CountVieFundAllTransactions implements ShouldQueue
{
    use Queueable;

    public int $tries = 120;
    public int $maxExceptions = 1;
    public int $timeout = 45;
    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $signature,
        public readonly array $payload,
        public readonly string $token
    ) {
        $this->onConnection('database_viefund_cache');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('viefund-all-transactions-count'))->shared()
            ->releaseAfter(5)->expireAfter(90)];
    }

    public function handle(VieFundAllTransactionsCountService $service): void
    {
        if (!function_exists('pcntl_alarm')) {
            throw new RuntimeException('Background transaction counts require queue worker timeout support.');
        }

        $service->build($this->signature, $this->payload, $this->token);
    }

    public function failed(?Throwable $exception): void
    {
        app(VieFundAllTransactionsCountService::class)->fail(
            $this->signature, $this->token, $exception?->getMessage() ?? 'The background count failed.'
        );
    }
}
