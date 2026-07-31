<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VieFundDailyTotal extends Model
{
    use HasFactory;

    protected $table = 'viefund_daily_totals';

    /** Allowed VieFund date bases (key => label). */
    public const DATE_BASIS_OPTIONS = [
        'create_date' => 'Created date',
        'trade_date' => 'Trade date',
        'processing_date' => 'Processing date',
        'settlement_date' => 'Settlement date',
    ];

    protected $fillable = [
        'total_date',
        'net_total',
        'transaction_count',
        'date_basis',
        'variant_key',
        'status_ids',
        'trust_status_names',
        'source_window_start',
        'source_window_end',
        'synced_at',
    ];

    protected $casts = [
        'total_date' => 'date',
        'net_total' => 'decimal:4',
        'transaction_count' => 'integer',
        'status_ids' => 'array',
        'trust_status_names' => 'array',
        'source_window_start' => 'date',
        'source_window_end' => 'date',
        'synced_at' => 'datetime',
    ];

    /**
     * Canonical signature for a cached snapshot variant. The SAME (date basis,
     * fund statuses, trust statuses) always hashes identically regardless of
     * input order, so the sync, the daily-totals page, and the drilldown all
     * resolve to the same rows. This is the single source of truth for the hash.
     */
    public static function variantKey(string $dateBasis, array $statusIds, array $trustStatusNames): string
    {
        $statuses = array_values(array_unique(array_map('intval', $statusIds)));
        sort($statuses, SORT_NUMERIC);

        $trust = array_values(array_unique(array_map('strval', $trustStatusNames)));
        sort($trust, SORT_STRING);

        return sha1(json_encode([
            'basis' => $dateBasis,
            'statuses' => $statuses,
            'trust' => $trust,
        ]));
    }
}
