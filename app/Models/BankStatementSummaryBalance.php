<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BankStatementSummaryBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'bank_statement_summary_id',
        'balance_index',
        'balance_type_code',
        'amount',
        'currency',
        'credit_debit_indicator',
        'signed_amount',
        'balance_date',
        'raw_json',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'signed_amount' => 'decimal:2',
        'balance_date' => 'date',
        'raw_json' => 'array',
    ];

    public function summary()
    {
        return $this->belongsTo(\App\Models\BankStatementSummary::class, 'bank_statement_summary_id');
    }
}
