<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Reconciliation extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'period_start',
        'period_end',
        'description',
        'status',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
    ];
}
