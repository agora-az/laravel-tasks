<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class VieFundTransaction extends Model
{
    use HasFactory;

    protected $table = 'viefund_transactions';

    protected $fillable = [
        'client_name',
        'rep_code',
        'plan_description',
        'institution',
        'account_id',
        'trx_id',
        'created_date',
        'trx_type',
        'trade_date',
        'settlement_date',
        'processing_date',
        'source_id',
        'status',
        'amount',
        'balance',
        'fund_code',
        'fund_trx_type',
        'fund_trx_amount',
        'fund_settlement_source',
        'fund_wo_number',
        'fund_source_id',
        'available_cad',
        'balance_cad',
        'currency',
        'record_hash',
    ];

    protected $casts = [
        'created_date' => 'datetime',
        'trade_date' => 'date',
        'settlement_date' => 'date',
        'processing_date' => 'date',
        'amount' => 'decimal:2',
        'balance' => 'decimal:2',
        'fund_trx_amount' => 'decimal:2',
        'available_cad' => 'decimal:2',
        'balance_cad' => 'decimal:2',
    ];
}
