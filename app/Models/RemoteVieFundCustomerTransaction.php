<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Minimal lookup table: cash_trx_id → pre-computed running balance.
 * No timestamps, no surrogate key — cash_trx_id IS the primary key.
 */
class RemoteVieFundCustomerTransaction extends Model
{
    protected $table      = 'remote_viefund_customer_transactions';
    protected $primaryKey = 'cash_trx_id';
    public    $timestamps = false;
    public    $incrementing = false;
    protected $keyType    = 'integer';

    protected $fillable = [
        'cash_trx_id',
        'viefund_customer_id',
        'amount',
        'running_balance',
    ];

    protected $casts = [
        'cash_trx_id'         => 'integer',
        'viefund_customer_id' => 'integer',
        'amount'              => 'decimal:4',
        'running_balance'     => 'decimal:4',
    ];
}
