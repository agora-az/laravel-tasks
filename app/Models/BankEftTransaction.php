<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankEftTransaction extends Model
{
    protected $fillable = [
        'bank_eft_file_id', 'import_id', 'source_file', 'sequence_number', 'line_number',
        'segment_number', 'record_type', 'transaction_code', 'amount', 'effective_date', 'bank_code',
        'bank_transit', 'bank_account_last4', 'bank_account_hash', 'holder_name', 'holder_id',
        'originator_short_name', 'originator_long_name', 'originator_id', 'trust_account_last4',
        'trust_account_hash', 'record_hash',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'effective_date' => 'date',
        'sequence_number' => 'integer',
        'line_number' => 'integer',
        'segment_number' => 'integer',
    ];

    public function file()
    {
        return $this->belongsTo(BankEftFile::class, 'bank_eft_file_id');
    }
}
