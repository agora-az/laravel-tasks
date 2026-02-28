<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BankTransaction extends Model
{
    use HasFactory;

    protected $table = 'bank_transactions';

    protected $fillable = [
        'account_number',
        'currency',
        'txn_date',
        'description',
        'type',
        'amount',
        'balance',
        'record_hash',
        'import_id',
    ];

    protected $casts = [
        'txn_date' => 'date',
        'amount' => 'decimal:2',
        'balance' => 'decimal:2',
    ];
}
