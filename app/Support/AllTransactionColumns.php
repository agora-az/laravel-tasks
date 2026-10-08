<?php

namespace App\Support;

use App\Services\RuntimeSettings;

final class AllTransactionColumns
{
    private const BANK_SUMMARY_KEYS = ['reconciliation_variance', 'reconciliation_note'];

    private const MATCH_DEFINITIONS = [
        'matched_to_bank' => ['label' => 'Match', 'field' => null],
        'reconciliation_variance' => ['label' => 'Bank Txn Variance', 'field' => 'reconciliation_variance', 'width' => 165],
        'reconciliation_note' => ['label' => 'Variance Note', 'field' => 'reconciliation_note', 'width' => 360],
    ];

    /**
     * Stable configuration keys for the All Transactions table and export.
     * The field is the SQL result/property name; null denotes a derived UI value.
     */
    private const DEFINITIONS = [
        'cash_transaction_id' => ['label' => 'Cash Txn ID', 'field' => 'transaction_id'],
        'fund_transaction_id' => ['label' => 'Fund Txn ID', 'field' => 'fund_transaction_id'],
        'trust_transaction_id' => ['label' => 'Trust Txn ID', 'field' => 'trust_transaction_id'],
        'ledger_relationship' => ['label' => 'Relationship', 'field' => 'ledger_relationship'],
        'source_id' => ['label' => 'Source ID', 'field' => 'source_id'],
        'customer_name' => ['label' => 'Customer Name', 'field' => 'customer_name'],
        'plan_account_id' => ['label' => 'Plan Account ID', 'field' => 'plan_account_id'],
        'transaction_type' => ['label' => 'Txn Type', 'field' => 'transaction_type'],
        'status' => ['label' => 'Cash Status', 'field' => 'status'],
        'trust_status' => ['label' => 'Trust Status', 'field' => 'trust_status'],
        'notes' => ['label' => 'Notes', 'field' => 'notes'],
        'created_date' => ['label' => 'Created Date', 'field' => 'created_date', 'sort' => 'created_date'],
        'trade_date' => ['label' => 'Trade Date', 'field' => 'trade_date', 'sort' => 'trade_date'],
        'processing_date' => ['label' => 'Processing Date', 'field' => 'processing_date', 'sort' => 'processing_date'],
        'settlement_date' => ['label' => 'Settlement Date', 'field' => 'settlement_date', 'sort' => 'settlement_date'],
        'currency_code' => ['label' => 'Currency', 'field' => 'currency_code'],
        'amount' => ['label' => 'Amount', 'field' => 'amount', 'sort' => 'amount'],
    ];

    private const EFT_DEFINITIONS = [
        'file_name' => ['label' => 'EFT File', 'field' => 'file_name', 'width' => 260],
        'id' => ['label' => 'EFT Item ID', 'field' => 'id'],
        'sequence_number' => ['label' => 'EFT Sequence', 'field' => 'sequence_number'],
        'created_at' => ['label' => 'EFT Created', 'field' => 'created_at', 'width' => 145],
        'effective_date' => ['label' => 'EFT Effective', 'field' => 'effective_date', 'width' => 125],
        'trade_date' => ['label' => 'EFT Trade', 'field' => 'trade_date', 'width' => 125],
        'settlement_date' => ['label' => 'EFT Settlement', 'field' => 'settlement_date', 'width' => 125],
        'type' => ['label' => 'EFT Type', 'field' => 'type', 'width' => 170],
        'status_id' => ['label' => 'EFT Status', 'field' => 'status_id'],
        'holder_name' => ['label' => 'EFT Holder', 'field' => 'holder_name', 'width' => 180],
        'holder_id' => ['label' => 'EFT Holder ID', 'field' => 'holder_id', 'width' => 170],
        'source' => ['label' => 'EFT Source', 'field' => 'source', 'width' => 160],
        'amount' => ['label' => 'EFT Amount', 'field' => 'amount', 'width' => 130],
        'file_total' => ['label' => 'EFT File Total', 'field' => 'file_total', 'width' => 145],
        'notes' => ['label' => 'EFT Notes', 'field' => 'notes', 'width' => 220],
    ];

    private const BANK_DEFINITIONS = [
        'id' => ['label' => 'Bank Txn ID', 'field' => 'id'],
        'value_date' => ['label' => 'Bank Value Date', 'field' => 'value_date', 'width' => 130],
        'direction' => ['label' => 'Bank Direction', 'field' => 'direction'],
        'amount' => ['label' => 'Bank Txn Amount', 'field' => 'amount', 'width' => 145],
        'transaction_total' => ['label' => 'Bank Txn Total', 'field' => 'transaction_total', 'width' => 145],
        'currency' => ['label' => 'Bank Currency', 'field' => 'currency'],
        'account_number' => ['label' => 'Bank Account', 'field' => 'account_number', 'width' => 165],
        'settlement_number' => ['label' => 'Bank Sequence', 'field' => 'settlement_number', 'width' => 145],
        'memo_type' => ['label' => 'Bank Memo Type', 'field' => 'memo_type', 'width' => 150],
        'counterparty' => ['label' => 'Bank Counterparty', 'field' => 'counterparty', 'width' => 180],
        'wire_reference' => ['label' => 'Bank Wire Ref', 'field' => 'wire_reference', 'width' => 170],
        'description' => ['label' => 'Bank Description', 'field' => 'description', 'width' => 260],
        'source_file' => ['label' => 'Bank Source File', 'field' => 'source_file', 'width' => 220],
    ];

    private const FSP_DEFINITIONS = [
        'fsp_source' => ['label' => 'FSP Source', 'field' => 'fsp_source'],
        'source_file' => ['label' => 'FSP File', 'field' => 'source_file', 'width' => 260],
        'items_total' => ['label' => 'FSP Items Net', 'field' => 'items_total', 'width' => 155],
        'id' => ['label' => 'FSP Record ID', 'field' => 'id'],
        'record_index' => ['label' => 'FSP Record #', 'field' => 'record_index'],
        'create_date' => ['label' => 'FSP Created', 'field' => 'create_date', 'width' => 125],
        'trade_date' => ['label' => 'FSP Trade', 'field' => 'trade_date', 'width' => 125],
        'settlement_date' => ['label' => 'FSP Settlement', 'field' => 'settlement_date', 'width' => 125],
        'side' => ['label' => 'FSP Side', 'field' => 'side'],
        'transaction_type' => ['label' => 'FSP Txn Type', 'field' => 'transaction_type', 'width' => 130],
        'order_id' => ['label' => 'FSP Order ID', 'field' => 'order_id', 'width' => 155],
        'source_id' => ['label' => 'FSP Source ID', 'field' => 'source_id', 'width' => 180],
        'management_code' => ['label' => 'FSP Management Code', 'field' => 'management_code', 'width' => 160],
        'dealer_code' => ['label' => 'FSP Dealer Code', 'field' => 'dealer_code', 'width' => 140],
        'dealer_account_id' => ['label' => 'FSP Dealer Account', 'field' => 'dealer_account_id', 'width' => 175],
        'rep_code' => ['label' => 'FSP Rep Code', 'field' => 'rep_code', 'width' => 140],
        'intermediary_code' => ['label' => 'FSP Intermediary Code', 'field' => 'intermediary_code', 'width' => 175],
        'intermediary_account_id' => ['label' => 'FSP Intermediary Account', 'field' => 'intermediary_account_id', 'width' => 190],
        'account_type' => ['label' => 'FSP Account Type', 'field' => 'account_type', 'width' => 145],
        'fund_account_id' => ['label' => 'FSP Fund Account', 'field' => 'fund_account_id', 'width' => 175],
        'fund_id' => ['label' => 'FSP Fund ID', 'field' => 'fund_id'],
        'currency' => ['label' => 'FSP Currency', 'field' => 'currency'],
        'gross_amount' => ['label' => 'FSP Gross', 'field' => 'gross_amount', 'width' => 130],
        'net_amount' => ['label' => 'FSP Net', 'field' => 'net_amount', 'width' => 130],
        'settlement_amount' => ['label' => 'FSP Settlement Amount', 'field' => 'settlement_amount', 'width' => 165],
        'fsp_note' => ['label' => 'FSP Note', 'field' => 'fsp_note', 'width' => 360],
    ];

    /** @return array<string, array{label: string, field: ?string, sort?: string}> */
    public static function visible(): array
    {
        return array_merge(
            array_intersect_key(self::matchVisible(), ['matched_to_bank' => true]),
            self::configuredDefinitions(self::DEFINITIONS, 'all_transactions_visible_columns')
        );
    }

    public static function matchVisible(): array
    {
        return self::configuredDefinitions(self::MATCH_DEFINITIONS, 'all_transactions_match_visible_columns');
    }

    public static function groupOrder(): array
    {
        return app(RuntimeSettings::class)->get('viefund.columns.group_order');
    }

    public static function eftVisible(): array
    {
        return self::configuredDefinitions(self::EFT_DEFINITIONS, 'all_transactions_eft_visible_columns');
    }

    public static function bankVisible(): array
    {
        return self::configuredDefinitions(self::BANK_DEFINITIONS, 'all_transactions_bank_visible_columns');
    }

    public static function bankSummaryVisible(): array
    {
        return array_intersect_key(self::matchVisible(), array_flip(self::BANK_SUMMARY_KEYS));
    }

    public static function bankDetailVisible(): array
    {
        return self::bankVisible();
    }

    public static function fspVisible(): array
    {
        return self::configuredDefinitions(self::FSP_DEFINITIONS, 'all_transactions_fsp_visible_columns');
    }

    public static function availableTransactionColumns(): array
    {
        return self::DEFINITIONS;
    }

    public static function availableMatchColumns(): array
    {
        return self::MATCH_DEFINITIONS;
    }

    public static function availableEftColumns(): array
    {
        return self::EFT_DEFINITIONS;
    }

    public static function availableBankColumns(): array
    {
        return self::BANK_DEFINITIONS;
    }

    public static function availableFspColumns(): array
    {
        return self::FSP_DEFINITIONS;
    }

    public static function isVisible(string $key): bool
    {
        return array_key_exists($key, self::visible());
    }

    /** @return list<string> */
    public static function resultFields(bool $forExport): array
    {
        $visibleFields = array_filter(array_column(self::visible(), 'field'));
        $internalFields = $forExport
            ? ['cash_transaction_id', 'fund_transaction_id', 'trust_transaction_id', 'transaction_id', 'plan_account_id', 'amount', 'sort_id', 'basis_date']
            : ['cash_transaction_id', 'trust_transaction_id'];

        return array_values(array_unique(array_merge($internalFields, $visibleFields)));
    }

    /** @return list<string> */
    public static function visibleKeys(): array
    {
        return array_keys(self::visible());
    }

    public static function linkedColumnInsertionIndex(): int
    {
        $relationshipKeys = ['matched_to_bank', 'cash_transaction_id', 'fund_transaction_id', 'trust_transaction_id'];
        $positions = [];
        foreach (self::visibleKeys() as $position => $key) {
            if (in_array($key, $relationshipKeys, true)) {
                $positions[] = $position;
            }
        }

        return $positions === [] ? 0 : max($positions) + 1;
    }

    public static function matchSummaryInsertionIndex(): int
    {
        $position = array_search('matched_to_bank', self::visibleKeys(), true);

        return $position === false ? 0 : $position + 1;
    }

    /**
     * Keep only configured display fields plus fields required by links and
     * client-side row identification.
     */
    public static function filterLinkedPayload(array $record, string $group): array
    {
        $definitions = match ($group) {
            'eft' => self::eftVisible(),
            'bank' => self::bankVisible(),
            'fsp' => self::fspVisible(),
            default => [],
        };
        $keys = array_merge(array_keys($definitions), [
            'id',
            'file_id',
            'is_possible_wire_fee_match',
            'linked_item_url',
            'account_url',
            'file_url',
            'item_url',
            'url',
        ]);

        return array_intersect_key($record, array_flip($keys));
    }

    private static function configuredDefinitions(array $definitions, string $configKey): array
    {
        $runtimeKey = match ($configKey) {
            'all_transactions_match_visible_columns' => 'viefund.columns.match',
            'all_transactions_visible_columns' => 'viefund.columns.transactions',
            'all_transactions_eft_visible_columns' => 'viefund.columns.eft',
            'all_transactions_bank_visible_columns' => 'viefund.columns.bank',
            'all_transactions_fsp_visible_columns' => 'viefund.columns.fsp',
        };
        $configured = array_values(array_unique(array_map(
            'strtolower',
            (array) app(RuntimeSettings::class)->get($runtimeKey)
        )));
        if ($configured === []) {
            return $definitions;
        }

        $visible = [];
        foreach ($configured as $key) {
            if (array_key_exists($key, $definitions)) {
                $visible[$key] = $definitions[$key];
            }
        }

        return $visible ?: $definitions;
    }
}
