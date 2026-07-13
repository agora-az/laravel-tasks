<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BankStatementEntryAnalysis extends Model
{
    use HasFactory;

    protected $fillable = [
        'bank_statement_entry_id',
        'parser_version',
        'memo_type',
        'settlement_number',
        'wire_payment_reference',
        'counterparty',
        'inferred_channel',
        'confidence',
        'normalized_additional_info',
        'parse_flags',
        'parsed_at',
    ];

    protected $casts = [
        'confidence' => 'decimal:2',
        'parse_flags' => 'array',
        'parsed_at' => 'datetime',
    ];

    public function bankStatementEntry()
    {
        return $this->belongsTo(BankStatementEntry::class);
    }
}
