<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankEftFile extends Model
{
    protected $fillable = [
        'import_id', 'source_file', 'sequence_number', 'file_date', 'client_number', 'currency',
        'logical_record_count', 'declared_transaction_count', 'parsed_transaction_count',
        'declared_total_amount', 'parsed_total_amount', 'header_hash', 'trailer_hash',
    ];

    protected $casts = [
        'file_date' => 'date',
        'sequence_number' => 'integer',
        'logical_record_count' => 'integer',
        'declared_transaction_count' => 'integer',
        'parsed_transaction_count' => 'integer',
        'declared_total_amount' => 'decimal:2',
        'parsed_total_amount' => 'decimal:2',
    ];

    public function transactions()
    {
        return $this->hasMany(BankEftTransaction::class);
    }

    public function import()
    {
        return $this->belongsTo(Import::class);
    }
}
