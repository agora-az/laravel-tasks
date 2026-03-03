<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MatchCriteria extends Model
{
    use HasFactory;

    protected $table = 'match_criteria';

    protected $fillable = [
        'code',
        'description',
        'weight',
        'priority',
    ];

    protected $casts = [
        'weight' => 'decimal:4',
    ];

    /**
     * Get all criteria ordered by priority.
     */
    public static function getOrderedCriteria()
    {
        return self::orderBy('priority')->get();
    }

    /**
     * Get a specific criterion by code.
     */
    public static function getByCriteria(string $code)
    {
        return self::where('code', $code)->first();
    }
}
