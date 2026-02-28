<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FundservTransaction extends Model
{
    use HasFactory;

    protected $table = 'fundserv_transactions';

    protected $fillable = [
        'company',
        'settlement_date',
        'code',
        'src',
        'trade_date',
        'fund_id',
        'dealer_account_id',
        'order_id',
        'source_identifier',
        'tx_type',
        'settlement_amt',
        'actual_amount',
        'record_hash',
    ];

    protected $casts = [
        'settlement_date' => 'date',
        'trade_date' => 'date',
        'settlement_amt' => 'decimal:2',
        'actual_amount' => 'decimal:2',
    ];
}
