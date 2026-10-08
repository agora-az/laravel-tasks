<?php

namespace App\Services;

use App\Models\ApplicationSetting;
use InvalidArgumentException;

class RuntimeSettings
{
    public const COLUMN_GROUP_SETTING_KEYS = [
        'match' => 'viefund.columns.match',
        'viefund' => 'viefund.columns.transactions',
        'eft' => 'viefund.columns.eft',
        'fsp' => 'viefund.columns.fsp',
        'bank' => 'viefund.columns.bank',
    ];

    private ?array $overrides = null;

    public static function columnGroupSettingKeys(): array
    {
        return self::COLUMN_GROUP_SETTING_KEYS;
    }

    public function definitions(): array
    {
        return [
            'viefund.export.rows_per_sheet' => $this->integerDefinition(
                'Rows per worksheet',
                'Maximum transaction rows written to each Excel worksheet.',
                'viefund.all_transactions_export_rows_per_sheet',
                5000,
                1000000,
                'Export'
            ),
            'viefund.export.split_target_rows' => $this->integerDefinition(
                'Split worksheet target',
                'Target transaction rows per worksheet for split exports.',
                'viefund.all_transactions_export_split_target_rows',
                5000,
                250000,
                'Export'
            ),
            'viefund.export.batch_size' => $this->integerDefinition(
                'Export database batch size',
                'Rows loaded from the local reporting cache per export batch.',
                'viefund.all_transactions_export_batch_size',
                100,
                20000,
                'Export'
            ),
            'viefund.working_set.ttl_minutes' => $this->integerDefinition(
                'Working-set TTL (minutes)',
                'How long a completed period cache remains fresh.',
                'viefund.all_transactions_working_set.ttl_minutes',
                15,
                10080,
                'Working-set cache'
            ),
            'viefund.working_set.batch_size' => $this->integerDefinition(
                'Working-set batch size',
                'Rows fetched and persisted during each cache hydration batch.',
                'viefund.all_transactions_working_set.batch_size',
                100,
                5000,
                'Working-set cache'
            ),
            'viefund.link_cache.ttl_minutes' => $this->integerDefinition(
                'Linked-data TTL (minutes)',
                'How long remote EFT and fund-link lookups remain fresh.',
                'viefund.all_transactions_link_cache.ttl_minutes',
                5,
                10080,
                'Linked-data cache'
            ),
            'viefund.link_cache.retention_days' => $this->integerDefinition(
                'Linked-data retention (days)',
                'Age at which stale linked-data cache records are pruned.',
                'viefund.all_transactions_link_cache.retention_days',
                1,
                90,
                'Linked-data cache'
            ),
            'viefund.link_cache.remote_batch_size' => $this->integerDefinition(
                'Remote query batch size',
                'IDs sent in each bounded VieFund linked-data query.',
                'viefund.all_transactions_link_cache.remote_batch_size',
                1000,
                50000,
                'Linked-data cache'
            ),
            'viefund.link_cache.write_batch_size' => $this->integerDefinition(
                'Local write batch size',
                'Linked-data records written to MySQL per batch.',
                'viefund.all_transactions_link_cache.local_write_batch_size',
                100,
                5000,
                'Linked-data cache'
            ),
            'viefund.link_cache.query_batch_size' => $this->integerDefinition(
                'Local query batch size',
                'Linked-data IDs loaded from MySQL per query.',
                'viefund.all_transactions_link_cache.local_query_batch_size',
                100,
                20000,
                'Linked-data cache'
            ),
            'viefund.columns.group_order' => [
                'label' => 'Column group order',
                'description' => 'Table and workbook order for the five column groups.',
                'config' => 'viefund.all_transactions_column_group_order',
                'type' => 'group_order',
                'group' => 'VieFund All Transactions - Column Settings',
                'options' => array_keys(self::COLUMN_GROUP_SETTING_KEYS),
            ],
            'viefund.columns.match' => $this->columnsDefinition(
                'Match',
                'Match status and reconciliation result columns shown first.',
                'viefund.all_transactions_match_visible_columns'
            ),
            'viefund.columns.transactions' => $this->columnsDefinition(
                'VieFund',
                'Visible VieFund transaction columns, in display and export order.',
                'viefund.all_transactions_visible_columns'
            ),
            'viefund.columns.eft' => $this->columnsDefinition(
                'EFT',
                'Visible EFT column keys, in display and export order.',
                'viefund.all_transactions_eft_visible_columns'
            ),
            'viefund.columns.fsp' => $this->columnsDefinition(
                'FSP',
                'Visible FSP column keys, in display and export order.',
                'viefund.all_transactions_fsp_visible_columns'
            ),
            'viefund.columns.bank' => $this->columnsDefinition(
                'Bank',
                'Visible bank detail column keys, in display and export order.',
                'viefund.all_transactions_bank_visible_columns'
            ),
        ];
    }

    public function get(string $key): mixed
    {
        $definition = $this->definition($key);
        $value = $this->overrides()[$key] ?? config($definition['config']);

        return $this->normalize($definition, $value);
    }

    public function set(string $key, mixed $value, ?int $userId): void
    {
        $normalized = $this->normalize($this->definition($key), $value);

        ApplicationSetting::query()->updateOrCreate(
            ['setting_key' => $key],
            ['value' => $normalized, 'updated_by' => $userId]
        );
        $this->overrides = null;
    }

    public function forget(string $key): void
    {
        $this->definition($key);
        ApplicationSetting::query()->where('setting_key', $key)->delete();
        $this->overrides = null;
    }

    public function hasOverride(string $key): bool
    {
        $this->definition($key);

        return array_key_exists($key, $this->overrides());
    }

    public function default(string $key): mixed
    {
        $definition = $this->definition($key);

        return $this->normalize($definition, config($definition['config']));
    }

    private function definition(string $key): array
    {
        $definition = $this->definitions()[$key] ?? null;
        if ($definition === null) {
            throw new InvalidArgumentException("Unknown runtime setting: {$key}");
        }

        return $definition;
    }

    private function overrides(): array
    {
        return $this->overrides ??= ApplicationSetting::query()
            ->whereIn('setting_key', array_keys($this->definitions()))
            ->pluck('value', 'setting_key')
            ->all();
    }

    private function normalize(array $definition, mixed $value): mixed
    {
        if ($definition['type'] === 'integer') {
            $value = (int) $value;
            if ($value < $definition['min'] || $value > $definition['max']) {
                throw new InvalidArgumentException(
                    "Value must be between {$definition['min']} and {$definition['max']}."
                );
            }

            return $value;
        }

        $values = is_array($value) ? $value : preg_split('/\s*,\s*/', trim((string) $value));
        $values = array_values(array_unique(array_filter(array_map(
            static fn($item): string => strtolower(trim((string) $item)),
            $values ?: []
        ))));
        if ($definition['type'] !== 'group_order') {
            return $values;
        }

        $allowed = $definition['options'];
        $configured = array_values(array_intersect($values, $allowed));

        return array_values(array_merge($configured, array_diff($allowed, $configured)));
    }

    private function integerDefinition(
        string $label,
        string $description,
        string $config,
        int $min,
        int $max,
        string $group
    ): array {
        return compact('label', 'description', 'config', 'min', 'max', 'group') + ['type' => 'integer'];
    }

    private function columnsDefinition(string $label, string $description, string $config): array
    {
        return compact('label', 'description', 'config') + [
            'type' => 'columns',
            'group' => 'VieFund All Transactions - Column Settings',
        ];
    }
}