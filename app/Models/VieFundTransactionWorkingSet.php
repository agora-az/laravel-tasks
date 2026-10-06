<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VieFundTransactionWorkingSet extends Model
{
    protected $table = 'viefund_transaction_working_sets';

    protected $guarded = [];

    protected $casts = [
        'status_ids' => 'array',
        'date_from' => 'date',
        'date_to' => 'date',
        'rows_cached' => 'integer',
        'total_rows' => 'integer',
        'started_at' => 'datetime',
        'ready_at' => 'datetime',
        'refreshed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function isReady(): bool
    {
        return $this->active_generation !== null;
    }

    public function readableGeneration(): ?string
    {
        if ($this->active_generation !== null) {
            return $this->active_generation;
        }

        if ($this->state === 'warming' && $this->rows_cached > 0) {
            return $this->build_generation;
        }

        return null;
    }

    public function hasQueryableGeneration(): bool
    {
        return $this->readableGeneration() !== null;
    }
}