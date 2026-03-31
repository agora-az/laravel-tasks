<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdvisoryFeeTransaction extends Model
{
    protected $fillable = [
        'rep_code',
        'first_name',
        'last_name',
        'full_name',
        'plan_id',
        'plan_info',
        'transaction_type',
        'fund_code',
        'fund_id',
        'fund_description',
        'description',
        'trust_status',
        'effective_date',
        'settlement_date',
        'amount',
        'currency',
        'created_user_id',
        'last_modified_user_id',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'settlement_date' => 'date',
        'amount' => 'decimal:2',
    ];
}
