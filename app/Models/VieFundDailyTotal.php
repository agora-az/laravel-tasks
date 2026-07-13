<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VieFundDailyTotal extends Model
{
    use HasFactory;

    protected $table = 'viefund_daily_totals';

    protected $fillable = [
        'total_date',
        'net_total',
        'transaction_count',
        'source_window_start',
        'source_window_end',
        'synced_at',
    ];

    protected $casts = [
        'total_date' => 'date',
        'net_total' => 'decimal:4',
        'transaction_count' => 'integer',
        'source_window_start' => 'date',
        'source_window_end' => 'date',
        'synced_at' => 'datetime',
    ];
}
