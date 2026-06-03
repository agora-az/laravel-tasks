<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property-read \Illuminate\Database\Eloquent\Collection<int, RemoteVieFundCustomerPlan> $plans */

class RemoteVieFundCustomer extends Model
{
    protected $table = 'remote_viefund_customers';

    protected $fillable = [
        'viefund_customer_id',
        'first_name',
        'last_name',
        'full_name',
        'transactions_completed',
        'synced_at',
    ];

    protected $casts = [
        'viefund_customer_id'    => 'integer',
        'transactions_completed' => 'boolean',
        'synced_at'              => 'datetime',
    ];

    public function plans(): HasMany
    {
        return $this->hasMany(RemoteVieFundCustomerPlan::class, 'viefund_customer_id', 'viefund_customer_id');
    }
}
