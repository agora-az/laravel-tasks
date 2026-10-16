<?php

namespace App\Services\VieFund;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class VieFundAvailabilityWindow
{
    public function phase(?CarbonInterface $now = null): string
    {
        $local = $this->localTime($now);
        $drain = $this->atTime($local, config('viefund.mirror.maintenance_drain_start'));
        $offline = $this->atTime($local, config('viefund.mirror.maintenance_start'));
        $online = $this->atTime($local, config('viefund.mirror.maintenance_end'));
        $resume = $this->atTime($local, config('viefund.mirror.post_snapshot_sync_at'));

        return match (true) {
            $local->greaterThanOrEqualTo($drain) && $local->lessThan($offline) => 'draining',
            $local->greaterThanOrEqualTo($offline) && $local->lessThan($online) => 'offline',
            $local->greaterThanOrEqualTo($online) && $local->lessThan($resume) => 'stabilizing',
            default => 'available',
        };
    }

    public function isBlocked(?CarbonInterface $now = null): bool
    {
        return $this->phase($now) !== 'available';
    }

    public function nextAvailableAt(?CarbonInterface $now = null): CarbonImmutable
    {
        $local = $this->localTime($now);
        $resume = $this->atTime($local, config('viefund.mirror.post_snapshot_sync_at'));

        if (!$this->isBlocked($local)) {
            return $local;
        }

        return $resume;
    }

    public function timezone(): string
    {
        return (string) config('viefund.mirror.maintenance_timezone', 'America/Toronto');
    }

    private function localTime(?CarbonInterface $now): CarbonImmutable
    {
        return ($now ? CarbonImmutable::instance($now) : CarbonImmutable::now())
            ->setTimezone($this->timezone());
    }

    private function atTime(CarbonImmutable $date, mixed $time): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', (string) $time));

        return $date->startOfDay()->setTime($hour, $minute);
    }
}
