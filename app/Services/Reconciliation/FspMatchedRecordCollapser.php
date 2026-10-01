<?php

namespace App\Services\Reconciliation;

use App\Models\SettlementInstruction;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class FspMatchedRecordCollapser
{
    /**
     * Delivery metadata is deliberately excluded. Records that differ only by
     * file, import, database ID, or record position represent the same FSP
     * instruction. A meaningful field change remains a separate revision.
     *
     * @var string[]
     */
    private const SEMANTIC_COLUMNS = [
        'source_type',
        'create_date',
        'trade_date',
        'settlement_date',
        'management_code',
        'fund_account_id',
        'dealer_code',
        'dealer_account_id',
        'rep_code',
        'intermediary_code',
        'intermediary_account_id',
        'account_type',
        'order_id',
        'order_source',
        'order_type',
        'source_id',
        'order_status',
        'side',
        'transaction_type',
        'fund_id',
        'switch_from_fund_id',
        'switch_to_fund_id',
        'currency',
        'gross_amount',
        'net_amount',
        'nav',
        'units_transacted',
        'settlement_method',
        'settlement_amount',
        'settlement_source',
    ];

    /** @return string[] */
    public static function queryColumns(): array
    {
        return array_merge(['id', 'source_file', 'record_index'], self::SEMANTIC_COLUMNS);
    }

    /**
     * A grouped query suitable for totals. Exact redeliveries collapse to one
     * row while records with a changed business value remain separate.
     */
    public function deduplicatedQuery(string $sourceType): Builder
    {
        return SettlementInstruction::query()
            ->where('source_type', $sourceType)
            ->select(self::SEMANTIC_COLUMNS)
            ->selectRaw('MAX(id) as id')
            ->groupBy(self::SEMANTIC_COLUMNS);
    }

    /**
     * Collapse exact redeliveries for matched output and annotate the retained
     * row. Raw imported SettlementInstruction records are never changed.
     */
    public function collapse(Collection $items): Collection
    {
        $groups = [];

        foreach ($items as $item) {
            $sourceIdentity = $this->sourceIdentity($item);
            $fingerprint = $this->semanticFingerprint($item);
            $file = trim((string) data_get($item, 'source_file'));

            if (!isset($groups[$sourceIdentity][$fingerprint])) {
                $groups[$sourceIdentity][$fingerprint] = [
                    'representative' => $item,
                    'count' => 1,
                    'files' => $file !== '' ? [$file => true] : [],
                ];
                continue;
            }

            ++$groups[$sourceIdentity][$fingerprint]['count'];
            if ($file !== '') {
                $groups[$sourceIdentity][$fingerprint]['files'][$file] = true;
            }
            if ((int) data_get($item, 'id') > (int) data_get($groups[$sourceIdentity][$fingerprint]['representative'], 'id')) {
                $groups[$sourceIdentity][$fingerprint]['representative'] = $item;
            }
        }

        $collapsed = [];
        foreach ($groups as $versionGroups) {
            $versions = array_values($versionGroups);
            usort($versions, fn(array $left, array $right): int =>
                (int) data_get($left['representative'], 'id') <=> (int) data_get($right['representative'], 'id')
            );
            $versionCount = count($versions);

            foreach ($versions as $index => $version) {
                $item = $version['representative'];
                $notes = [];

                if ($versionCount > 1) {
                    $status = trim((string) data_get($item, 'order_status'));
                    $is7960 = data_get($item, 'source_type') === 'ltm';
                    $notes[] = $is7960
                        ? sprintf(
                            'Distinct revision %d of %d%s; retained because business fields differ.',
                            $index + 1,
                            $versionCount,
                            $status !== '' ? ' (order status '.$status.')' : ''
                        )
                        : sprintf(
                            'Distinct related record %d of %d for this Source ID; retained because business fields differ.',
                            $index + 1,
                            $versionCount
                        );
                }

                if ($version['count'] > 1) {
                    $files = array_keys($version['files']);
                    $retainedFile = trim((string) data_get($item, 'source_file'));
                    $duplicateFiles = array_values(array_filter(
                        $files,
                        fn(string $file): bool => $file !== $retainedFile
                    ));
                    $notes[] = sprintf(
                        'Collapsed %d identical deliveries%s%s.',
                        $version['count'],
                        $retainedFile !== '' ? '; retained '.$retainedFile : '',
                        $duplicateFiles !== []
                            ? '; duplicate '.(count($duplicateFiles) === 1 ? 'file' : 'files').': '.implode(', ', $duplicateFiles)
                            : ''
                    );
                }

                data_set($item, 'fsp_note', implode(' ', $notes));
                $collapsed[] = $item;
            }
        }

        usort($collapsed, function ($left, $right): int {
            $dateComparison = strcmp(
                (string) data_get($left, 'settlement_date'),
                (string) data_get($right, 'settlement_date')
            );

            return $dateComparison ?: ((int) data_get($left, 'id') <=> (int) data_get($right, 'id'));
        });

        return collect($collapsed);
    }

    private function sourceIdentity(mixed $item): string
    {
        $sourceType = trim((string) data_get($item, 'source_type'));
        $sourceId = trim((string) data_get($item, 'source_id'));

        return $sourceId !== ''
            ? $sourceType.'|'.$sourceId
            : $sourceType.'|record:'.(int) data_get($item, 'id');
    }

    private function semanticFingerprint(mixed $item): string
    {
        $values = array_map(function (string $column) use ($item): mixed {
            $value = data_get($item, $column);
            if ($value instanceof DateTimeInterface) {
                return $value->format('Y-m-d');
            }
            if (is_string($value)) {
                $value = trim($value);

                return $value === '' ? null : $value;
            }

            return $value;
        }, self::SEMANTIC_COLUMNS);

        return hash('sha256', json_encode($values, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }
}
