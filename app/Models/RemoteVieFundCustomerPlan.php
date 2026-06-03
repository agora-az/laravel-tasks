<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RemoteVieFundCustomerPlan extends Model
{
    protected $table = 'remote_viefund_customer_plans';

    protected $fillable = [
        'viefund_customer_id',
        'viefund_plan_id',
        'plan_dealer_account_id',
        'synced_at',
    ];

    protected $casts = [
        'viefund_customer_id' => 'integer',
        'viefund_plan_id'     => 'integer',
        'synced_at'           => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(RemoteVieFundCustomer::class, 'viefund_customer_id', 'viefund_customer_id');
    }
}
