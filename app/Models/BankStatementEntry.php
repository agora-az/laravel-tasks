<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BankStatementEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'import_id',
        'source_file',
        'message_id',
        'statement_id',
        'account_number',
        'entry_index',
        'entry_reference',
        'booking_date',
        'value_date',
        'credit_debit_indicator',
        'status',
        'currency',
        'amount',
        'bank_domain_code',
        'bank_family_code',
        'bank_sub_family_code',
        'additional_info',
        'raw_xml',
        'raw_json',
    ];

    protected $casts = [
        'booking_date' => 'date',
        'value_date' => 'date',
        'amount' => 'decimal:2',
        'raw_json' => 'array',
    ];

    public function import()
    {
        return $this->belongsTo(Import::class);
    }

    public function analyses()
    {
        return $this->hasMany(BankStatementEntryAnalysis::class);
    }
}
