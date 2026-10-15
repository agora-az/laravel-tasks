<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsmSettlementTransaction extends Model
{
    protected $guarded = [];

    protected $casts = [
        'settlement_date' => 'date',
        'settlement_amount' => 'decimal:2',
        'sender_locked_in_account' => 'boolean',
        'sender_spousal_account' => 'boolean',
        'create_date' => 'datetime',
        'raw_json' => 'array',
    ];

    public function import()
    {
        return $this->belongsTo(Import::class);
    }

    public function summary()
    {
        return $this->belongsTo(AsmSettlementSummary::class, 'asm_settlement_summary_id');
    }

    public function vieFundMatches()
    {
        return $this->hasMany(AsmVieFundMatch::class);
    }
}
