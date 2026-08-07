<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VieFundCashDailySnapshot extends Model
{
    use HasFactory;

    public const ALGORITHM_VERSION = 'direct-cash-v1';

    protected $table = 'viefund_cash_daily_snapshots';

    protected $fillable = [
        'total_date',
        'criteria_key',
        'algorithm_version',
        'date_basis',
        'currency_code',
        'status_ids',
        'transaction_count',
        'net_total',
        'closing_balance',
        'first_observed_at',
        'last_verified_at',
        'latest_changed_at',
        'change_count',
        'has_unreviewed_change',
        'reviewed_at',
        'reviewed_by',
        'reviewed_by_label',
    ];

    protected function casts(): array
    {
        return [
            'total_date' => 'date',
            'status_ids' => 'array',
            'transaction_count' => 'integer',
            'net_total' => 'decimal:4',
            'closing_balance' => 'decimal:4',
            'first_observed_at' => 'datetime',
            'last_verified_at' => 'datetime',
            'latest_changed_at' => 'datetime',
            'change_count' => 'integer',
            'has_unreviewed_change' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    public static function criteriaKey(string $dateBasis, string $currencyCode, array $statusIds): string
    {
        $statuses = array_values(array_unique(array_map('intval', $statusIds)));
        sort($statuses, SORT_NUMERIC);

        return sha1(json_encode([
            'algorithm' => self::ALGORITHM_VERSION,
            'basis' => $dateBasis,
            'currency' => strtoupper(trim($currencyCode)),
            'statuses' => $statuses,
        ]));
    }

    public function changes(): HasMany
    {
        return $this->hasMany(VieFundCashDailySnapshotChange::class, 'snapshot_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
