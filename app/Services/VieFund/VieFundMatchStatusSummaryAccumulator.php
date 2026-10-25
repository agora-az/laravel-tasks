<?php

namespace App\Services\VieFund;

use App\Services\Reconciliation\AllTransactionMatchStatusResolver;

final class VieFundMatchStatusSummaryAccumulator
{
    public const STATUSES = [
        'Complete',
        'Verify',
        'Possible',
        AllTransactionMatchStatusResolver::ASM_ONLY,
        'Unknown',
    ];

    private array $summary = [];

    private array $seen = [];

    public function __construct(private readonly ?\Closure $recordClaim = null, ?array $totals = null)
    {
        foreach (self::STATUSES as $status) {
            $this->summary[$status] = [
                'viefund_count' => 0,
                'viefund_total' => 0.0,
                'viefund_outflow' => 0.0,
                'viefund_inflow' => 0.0,
                'eft_count' => 0,
                'eft_total' => 0.0,
                'fsp_count' => 0,
                'fsp_total' => 0.0,
                'asm_count' => 0,
                'asm_total' => 0.0,
                'bank_count' => 0,
                'bank_total' => 0.0,
                'variance' => 0.0,
            ];
            $this->seen[$status] = [
                'eft' => [],
                'eft_total' => [],
                'fsp' => [],
                'fsp_total' => [],
                'asm' => [],
                'bank' => [],
            ];
        }
        if ($totals !== null) {
            if (count($totals) !== count($this->summary) || array_diff_key($this->summary, $totals) !== []) {
                throw new \UnexpectedValueException('Invalid saved summary statuses.');
            }
            foreach ($this->summary as $status => $fields) {
                if (!is_array($totals[$status]) || count($totals[$status]) !== count($fields)
                    || array_diff_key($fields, $totals[$status]) !== []) {
                    throw new \UnexpectedValueException('Invalid saved summary fields.');
                }
                foreach ($totals[$status] as $value) {
                    if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
                        throw new \UnexpectedValueException('Invalid saved summary amount.');
                    }
                }
                foreach ($fields as $field => $value) {
                    $this->summary[$status][$field] = $totals[$status][$field];
                }
            }
        }
    }

    public function addRows(iterable $rows): void
    {
        foreach ($rows as $row) {
            $this->add($row);
        }
    }

    public function add(object $row): void
    {
        $status = in_array($row->match_status ?? null, self::STATUSES, true)
            ? $row->match_status
            : 'Unknown';
        ++$this->summary[$status]['viefund_count'];
        $amount = (float) ($row->amount ?? 0);
        $this->summary[$status]['viefund_total'] += $amount;
        if ($amount < 0) {
            $this->summary[$status]['viefund_outflow'] += abs($amount);
        } elseif ($amount > 0) {
            $this->summary[$status]['viefund_inflow'] += $amount;
        }

        $enrichment = $row->cached_enrichment ?? null;
        if (!is_array($enrichment)) {
            $enrichment = json_decode(
                (string) ($row->enrichment ?? '{}'),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        }
        foreach ((array) ($enrichment['asm_items'] ?? []) as $record) {
            $this->addSummaryRecord(
                $status,
                'asm',
                $record,
                fn(array $item): float => (float) ($item['settlement_amount'] ?? 0)
            );
        }

        if ($status === 'Unknown') {
            return;
        }

        foreach ((array) ($enrichment['eft_items'] ?? []) as $record) {
            $this->addSummaryRecord($status, 'eft', $record, fn(): float => 0.0);
            $hasFileTotal = array_key_exists('file_total', $record) && $record['file_total'] !== null;
            $amount = (float) ($hasFileTotal ? $record['file_total'] : ($record['amount'] ?? 0));
            $signedAmount = (int) ($record['type_id'] ?? 0) === 10 ? $amount : -$amount;
            $totalKey = $hasFileTotal
                ? 'file:' . (string) ($record['file_id'] ?? $record['sequence_number'] ?? $record['id'] ?? '')
                : 'item:' . (string) ($record['id'] ?? sha1(json_encode($record, JSON_THROW_ON_ERROR)));
            $this->addSummaryTotal($status, 'eft_total', $totalKey, $signedAmount);
        }
        foreach ((array) ($enrichment['fsp_items'] ?? []) as $record) {
            $this->addSummaryRecord($status, 'fsp', $record, fn(): float => 0.0);
        }
        $fspBankEntries = (array) ($enrichment['fsp_bank_entries'] ?? []);
        foreach ($fspBankEntries as $record) {
            $recordKey = (string) ($record['id'] ?? sha1(json_encode($record, JSON_THROW_ON_ERROR)));
            $this->addSummaryTotal(
                $status,
                'fsp_total',
                $recordKey,
                (float) ($record['fsp_net_total'] ?? 0)
            );
        }
        foreach (array_merge((array) ($enrichment['eft_bank_entries'] ?? []), $fspBankEntries) as $record) {
            $this->addSummaryRecord($status, 'bank', $record, function (array $entry): float {
                $amount = (float) ($entry['amount'] ?? 0);

                return strtoupper(trim((string) ($entry['credit_debit_indicator'] ?? ''))) === 'DBIT'
                    ? -$amount
                    : $amount;
            });
        }
    }

    public function totals(): array
    {
        return $this->summary;
    }

    public function result(): array
    {
        $summary = $this->summary;
        foreach (self::STATUSES as $status) {
            $summary[$status]['variance'] = $summary[$status]['bank_total']
                - $summary[$status]['eft_total']
                - $summary[$status]['fsp_total'];
            foreach (['viefund_total', 'eft_total', 'fsp_total', 'asm_total', 'bank_total', 'variance'] as $field) {
                $summary[$status][$field] = round((float) $summary[$status][$field], 2);
                if (abs($summary[$status][$field]) < 0.005) {
                    $summary[$status][$field] = 0.0;
                }
            }
        }

        return $summary;
    }

    private function addSummaryRecord(string $status, string $type, array $record, callable $amount): void
    {
        $recordKey = isset($record['id'])
            ? (string) $record['id']
            : sha1(json_encode($record, JSON_THROW_ON_ERROR));
        if (!$this->claim($status, $type, $recordKey)) {
            return;
        }

        ++$this->summary[$status][$type . '_count'];
        $this->summary[$status][$type . '_total'] += $amount($record);
    }

    private function addSummaryTotal(string $status, string $field, string $recordKey, float $amount): void
    {
        if (!$this->claim($status, $field, $recordKey)) {
            return;
        }

        $this->summary[$status][$field] += $amount;
    }

    private function claim(string $status, string $field, string $key): bool
    {
        if ($this->recordClaim !== null) {
            return ($this->recordClaim)($status, $field, $key);
        }
        if (isset($this->seen[$status][$field][$key])) {
            return false;
        }
        $this->seen[$status][$field][$key] = true;

        return true;
    }
}
