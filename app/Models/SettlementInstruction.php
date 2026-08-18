<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SettlementInstruction extends Model
{
    use HasFactory;

    protected $fillable = [
        'import_id',
        'settlement_instruction_summary_id',
        'source_file',
        'source_type',
        'record_index',
        'create_date',
        'trade_date',
        'settlement_date',
        'management_code',
        'fund_account_id',
        'dealer_code',
        'dealer_account_id',
        'rep_code',
        'intermediary_code',
        'intermediary_account_id',
        'account_type',
        'order_id',
        'order_source',
        'order_type',
        'source_id',
        'order_status',
        'side',
        'transaction_type',
        'fund_id',
        'switch_from_fund_id',
        'switch_to_fund_id',
        'currency',
        'gross_amount',
        'net_amount',
        'nav',
        'units_transacted',
        'settlement_method',
        'settlement_amount',
        'settlement_source',
        'raw_xml',
        'raw_json',
    ];

    protected $casts = [
        'create_date' => 'date',
        'trade_date' => 'date',
        'settlement_date' => 'date',
        'gross_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'nav' => 'decimal:6',
        'units_transacted' => 'decimal:6',
        'settlement_amount' => 'decimal:2',
        'raw_json' => 'array',
    ];

    public function import()
    {
        return $this->belongsTo(Import::class);
    }

    public function summary()
    {
        return $this->belongsTo(SettlementInstructionSummary::class, 'settlement_instruction_summary_id');
    }
}
