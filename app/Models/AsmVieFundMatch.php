<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsmVieFundMatch extends Model
{
    protected $table = 'asm_viefund_matches';

    protected $guarded = [];

    protected $casts = [
        'confidence' => 'decimal:4',
        'metadata' => 'array',
    ];

    public function transaction()
    {
        return $this->belongsTo(AsmSettlementTransaction::class, 'asm_settlement_transaction_id');
    }
}
