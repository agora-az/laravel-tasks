<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BankStatementSummary extends Model
{
    use HasFactory;

    protected $fillable = [
        'import_id',
        'source_file',
        'statement_index',
        'message_id',
        'group_created_at',
        'statement_id',
        'statement_created_at',
        'account_number',
        'opening_balance_type_code',
        'opening_balance_amount',
        'opening_balance_currency',
        'opening_balance_indicator',
        'opening_balance_signed_amount',
        'opening_balance_date',
        'closing_balance_type_code',
        'closing_balance_amount',
        'closing_balance_currency',
        'closing_balance_indicator',
        'closing_balance_signed_amount',
        'closing_balance_date',
        'total_credit_entries',
        'total_credit_sum',
        'total_debit_entries',
        'total_debit_sum',
        'raw_statement_xml',
        'raw_statement_json',
    ];

    protected $casts = [
        'group_created_at' => 'datetime',
        'statement_created_at' => 'datetime',
        'opening_balance_date' => 'date',
        'closing_balance_date' => 'date',
        'opening_balance_amount' => 'decimal:2',
        'opening_balance_signed_amount' => 'decimal:2',
        'closing_balance_amount' => 'decimal:2',
        'closing_balance_signed_amount' => 'decimal:2',
        'total_credit_sum' => 'decimal:2',
        'total_debit_sum' => 'decimal:2',
        'raw_statement_json' => 'array',
    ];

    public function import()
    {
        return $this->belongsTo(Import::class);
    }

    public function entries()
    {
        return $this->hasMany(BankStatementEntry::class);
    }

    public function balances()
    {
        return $this->hasMany(BankStatementSummaryBalance::class);
    }
}
