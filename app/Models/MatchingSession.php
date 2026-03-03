<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MatchingSession extends Model
{
    protected $fillable = [
        'status',
        'total_records',
        'processed_records',
        'matched_count',
        'error_message',
        'started_at',
        'completed_at',
        'current_pass',
        'current_pass_number',
        'total_records_in_pass',
        'progress_percentage',
        'reconciliation_id',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
