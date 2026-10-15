<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsmSettlementSummary extends Model
{
    protected $fillable = [
        'import_id',
        'source_file',
        'file_hash',
        'record_count',
        'duplicate_count',
        'total_settlement_amount',
        'min_settlement_date',
        'max_settlement_date',
        'min_create_date',
        'max_create_date',
        'currency_totals',
        'payment_type_totals',
        'raw_summary_json',
    ];

    protected $casts = [
        'record_count' => 'integer',
        'duplicate_count' => 'integer',
        'total_settlement_amount' => 'decimal:2',
        'min_settlement_date' => 'date',
        'max_settlement_date' => 'date',
        'min_create_date' => 'datetime',
        'max_create_date' => 'datetime',
        'currency_totals' => 'array',
        'payment_type_totals' => 'array',
        'raw_summary_json' => 'array',
    ];

    public function import()
    {
        return $this->belongsTo(Import::class);
    }

    public function transactions()
    {
        return $this->hasMany(AsmSettlementTransaction::class);
    }
}
