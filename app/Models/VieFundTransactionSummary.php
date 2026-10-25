<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VieFundTransactionSummary extends Model
{
    protected $table = 'viefund_transaction_summaries';

    protected $guarded = [];

    protected $casts = [
        'source_snapshot' => 'array',
        'filters' => 'array',
        'totals' => 'array',
        'result' => 'array',
        'cursor' => 'integer',
        'processed_rows' => 'integer',
        'expected_rows' => 'integer',
    ];
}
