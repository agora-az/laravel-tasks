<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VieFundCashDailySnapshotChange extends Model
{
    use HasFactory;

    protected $table = 'viefund_cash_daily_snapshot_changes';

    protected $fillable = [
        'snapshot_id',
        'run_id',
        'previous_transaction_count',
        'new_transaction_count',
        'transaction_count_delta',
        'previous_net_total',
        'new_net_total',
        'net_total_delta',
        'algorithm_version',
        'detected_at',
    ];

    protected function casts(): array
    {
        return [
            'previous_transaction_count' => 'integer',
            'new_transaction_count' => 'integer',
            'transaction_count_delta' => 'integer',
            'previous_net_total' => 'decimal:4',
            'new_net_total' => 'decimal:4',
            'net_total_delta' => 'decimal:4',
            'detected_at' => 'datetime',
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(VieFundCashDailySnapshot::class, 'snapshot_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(VieFundCashSnapshotRun::class, 'run_id');
    }
}
