<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VieFundTransactionMirrorSync extends Model
{
    public const SINGLETON_ID = 1;

    protected $table = 'viefund_transaction_mirror_syncs';

    protected $guarded = [];

    protected $casts = [
        'currencies' => 'array',
        'status_ids' => 'array',
        'cursor' => 'array',
        'rows_processed' => 'integer',
        'expected_rows' => 'integer',
        'active_seconds' => 'float',
        'batch_metrics' => 'array',
        'target_from_date' => 'date',
        'target_through_date' => 'date',
        'started_at' => 'datetime',
        'paused_at' => 'datetime',
        'last_batch_started_at' => 'datetime',
        'last_batch_completed_at' => 'datetime',
        'ready_at' => 'datetime',
        'verified_at' => 'datetime',
        'synced_through_at' => 'datetime',
    ];

    public static function singleton(): self
    {
        return self::query()->firstOrCreate(
            ['id' => self::SINGLETON_ID],
            ['state' => 'idle', 'mode' => 'initial']
        );
    }

    public function isReady(): bool
    {
        return $this->state === 'ready' && $this->verified_at !== null;
    }

    public function isReadable(): bool
    {
        return $this->ready_at !== null && $this->verified_at !== null;
    }
}
