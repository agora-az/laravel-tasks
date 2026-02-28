<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ReconciliationMatch extends Model
{
    use HasFactory;

    protected $table = 'reconciliation_matches';

    protected $fillable = [
        'left_type',
        'left_id',
        'right_type',
        'right_id',
        'match_rule',
        'confidence',
        'matched_amount',
        'status',
        'metadata',
    ];

    protected $casts = [
        'confidence' => 'decimal:4',
        'matched_amount' => 'decimal:2',
        'metadata' => 'array',
    ];
}
