<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VieFundTransactionMirrorRow extends Model
{
    protected $table = 'viefund_transaction_mirror_rows';

    protected $primaryKey = 'cash_transaction_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];

    protected $casts = [
        'fund_transaction_id' => 'integer',
        'trust_transaction_id' => 'integer',
        'cash_status_id' => 'integer',
        'amount' => 'decimal:4',
        'has_eft_match' => 'boolean',
        'has_agra_fsp_match' => 'boolean',
        'has_7960_fsp_match' => 'boolean',
        'has_asm_match' => 'boolean',
        'payload' => 'array',
        'enrichment' => 'array',
        'created_date' => 'datetime',
        'trade_date' => 'datetime',
        'processing_date' => 'datetime',
        'settlement_date' => 'datetime',
        'source_synced_at' => 'datetime',
    ];
}
