<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountFeeTransaction extends Model
{
    protected $fillable = [
        'rep_code',
        'client_name',
        'plan_description',
        'account_description',
        'transaction_type',
        'wire_number',
        'trade_date',
        'settlement_date',
        'amount',
        'order_status',
        'trust_status',
        'user_id',
    ];

    protected $casts = [
        'trade_date' => 'date',
        'settlement_date' => 'date',
        'amount' => 'decimal:2',
    ];
}
