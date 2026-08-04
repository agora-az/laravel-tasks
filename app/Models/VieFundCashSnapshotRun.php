<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VieFundCashSnapshotRun extends Model
{
    use HasFactory;

    protected $table = 'viefund_cash_snapshot_runs';

    protected $fillable = [
        'run_type',
        'status',
        'criteria_key',
        'algorithm_version',
        'date_basis',
        'currency_code',
        'status_ids',
        'requested_from',
        'requested_to',
        'source_observed_at',
        'days_checked',
        'days_inserted',
        'days_changed',
        'days_unchanged',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status_ids' => 'array',
            'requested_from' => 'date',
            'requested_to' => 'date',
            'source_observed_at' => 'datetime',
            'days_checked' => 'integer',
            'days_inserted' => 'integer',
            'days_changed' => 'integer',
            'days_unchanged' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function changes(): HasMany
    {
        return $this->hasMany(VieFundCashDailySnapshotChange::class, 'run_id');
    }
}