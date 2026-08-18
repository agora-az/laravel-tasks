<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SettlementInstructionSummary extends Model
{
    use HasFactory;

    protected $fillable = [
        'import_id',
        'source_file',
        'source_type',
        'record_count',
        'total_gross_amount',
        'total_net_amount',
        'total_settlement_amount',
        'min_create_date',
        'max_create_date',
        'min_trade_date',
        'max_trade_date',
        'min_settlement_date',
        'max_settlement_date',
        'raw_summary_json',
    ];

    protected $casts = [
        'record_count' => 'integer',
        'total_gross_amount' => 'decimal:2',
        'total_net_amount' => 'decimal:2',
        'total_settlement_amount' => 'decimal:2',
        'min_create_date' => 'date',
        'max_create_date' => 'date',
        'min_trade_date' => 'date',
        'max_trade_date' => 'date',
        'min_settlement_date' => 'date',
        'max_settlement_date' => 'date',
        'raw_summary_json' => 'array',
    ];

    public function import()
    {
        return $this->belongsTo(Import::class);
    }

    public function entries()
    {
        return $this->hasMany(SettlementInstruction::class, 'settlement_instruction_summary_id');
    }
}
