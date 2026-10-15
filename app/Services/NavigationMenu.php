<?php

namespace App\Services;

use App\Models\ApplicationSetting;
use Illuminate\Support\Str;
use InvalidArgumentException;

class NavigationMenu
{
    public const SETTING_KEY = 'navigation.menu';

    private const PAGES = [
        'dashboard' => ['label' => 'Dashboard', 'route' => 'dashboard'],
        'all_transactions' => ['label' => 'All Transactions', 'route' => 'viefund-transactions.index'],
        'customer_transactions' => ['label' => 'Customer Transactions', 'route' => 'remote-viefund.index'],
        'eft_files' => ['label' => 'EFT Files', 'route' => 'eft-files.index'],
        'fsp_files' => ['label' => 'FSP Files', 'route' => 'settlement-instructions.index'],
        'asm_files' => ['label' => 'ASM Files', 'route' => 'asm-settlements.index'],
        'bank_statements' => ['label' => 'Bank Statements', 'route' => 'bank-entries.index'],
        'reconciliation' => ['label' => 'VieFund Daily Net + Running Balance', 'route' => 'reconciliations.transactions'],
        'reconciliation_customer_balances' => ['label' => 'VieFund Customer Balances', 'route' => 'reconciliations.customer-balances'],
        'reconciliation_bank_eft' => ['label' => 'Bank / EFT', 'route' => 'reconciliations.daily-totals'],
        'reconciliation_bank_fsp_agra' => [
            'label' => 'Bank / FSP AGRA',
            'route' => 'reconciliations.bank-fsp.source',
            'parameters' => ['source' => 'agra'],
        ],
        'reconciliation_bank_fsp_7960' => [
            'label' => 'Bank / FSP 7960',
            'route' => 'reconciliations.bank-fsp.source',
            'parameters' => ['source' => '7960'],
        ],
        'reports' => ['label' => 'Reports', 'route' => 'reports.index'],
        'docs' => ['label' => 'Docs', 'route' => 'docs.index'],
        'transaction_data' => ['label' => 'Transaction Data', 'route' => 'imports.transactions'],
        'matches' => ['label' => 'Matches', 'route' => 'reconciliations.matches'],
    ];

    private const RESERVED_GROUP_SLUGS = [
        'admin',
        'api',
        'asm-files',
        'bank-entries',
        'dashboard',
        'docs',
        'eft-files',
        'imports',
        'login',
        'logout',
        'reconciliations',
        'remote-viefund',
        'reports',
        'settlement-instructions',
    ];

    private const RESERVED_STANDALONE_PATHS = [
        'asm-files' => 'asm_files',
        'bank-entries' => 'bank_statements',
        'dashboard' => 'dashboard',
        'docs' => 'docs',
        'eft-files' => 'eft_files',
        'remote-viefund' => 'customer_transactions',
        'reports' => 'reports',
        'settlement-instructions' => 'fsp_files',
        'viefund-transactions' => 'all_transactions',
    ];

    private ?array $resolvedMenu = null;

    /** @return array<string, array{label: string, route: string}> */
    public function pages(): array
    {
        return self::PAGES;
    }

    /** @return list<array<string, mixed>> */
    public function current(): array
    {
        if ($this->resolvedMenu !== null) {
            return $this->resolvedMenu;
        }

        $stored = ApplicationSetting::query()
            ->where('setting_key', self::SETTING_KEY)
            ->first()
            ?->value;

        if (!is_array($stored)) {
            return $this->resolvedMenu = $this->defaults();
        }

        try {
            $normalized = $this->normalize($this->upgradeLegacyReconciliation($stored), false);
            $this->validatePaths($normalized);

            return $this->resolvedMenu = $normalized;
        } catch (InvalidArgumentException) {
            return $this->resolvedMenu = $this->defaults();
        }
    }

    /** @return list<array<string, mixed>> */
    public function defaults(): array
    {
        $hidden = collect((array) config('app.nav_hide'))
            ->map(fn($key) => strtolower(trim((string) $key)))
            ->filter()
            ->values();
        $page = function (string $key, string|false|null $legacyHideKey = null) use ($hidden): array {
            $visible = $legacyHideKey === false || !$hidden->contains($legacyHideKey ?? $key);

            return [
                'type' => 'page',
                'key' => $key,
                'label' => self::PAGES[$key]['label'],
                'visible' => $visible,
                'admin_visible' => $visible,
            ];
        };

        return [
            $page('dashboard'),
            [
                'type' => 'group',
                'id' => 'viefund-transactions',
                'label' => 'VieFund Transactions',
                'items' => [
                    $page('all_transactions'),
                    $page('customer_transactions'),
                ],
            ],
            [
                'type' => 'group',
                'id' => 'data-sources',
                'label' => 'Data Sources',
                'items' => [
                    $page('eft_files'),
                    $page('fsp_files', 'settlement_instructions'),
                    $page('asm_files'),
                    $page('bank_statements'),
                ],
            ],
            [
                'type' => 'group',
                'id' => 'reconciliation',
                'label' => 'Reconciliation',
                'items' => [
                    $page('reconciliation', false),
                    $page('reconciliation_customer_balances', false),
                    $page('reconciliation_bank_eft', false),
                    $page('reconciliation_bank_fsp_agra', false),
                    $page('reconciliation_bank_fsp_7960', false),
                ],
            ],
            $page('reports'),
            $page('docs'),
            $page('transaction_data'),
            $page('matches', 'reconciliation'),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function normalizeSubmitted(array $entries): array
    {
        $normalized = $this->normalize($entries, true);
        $this->validatePaths($normalized);

        return $normalized;
    }

    public function save(array $entries, ?int $userId): void
    {
        ApplicationSetting::query()->updateOrCreate(
            ['setting_key' => self::SETTING_KEY],
            ['value' => $this->normalizeSubmitted($entries), 'updated_by' => $userId]
        );
        $this->resolvedMenu = null;
    }

    public function forget(): void
    {
        ApplicationSetting::query()->where('setting_key', self::SETTING_KEY)->delete();
        $this->resolvedMenu = null;
    }

    public function hasOverride(): bool
    {
        return ApplicationSetting::query()->where('setting_key', self::SETTING_KEY)->exists();
    }

    public function url(string $pageKey): string
    {
        if (!isset(self::PAGES[$pageKey])) {
            throw new InvalidArgumentException("Unknown navigation page: {$pageKey}");
        }

        return url('/' . $this->pathFor($pageKey));
    }

    public function pathFor(string $pageKey): string
    {
        foreach ($this->current() as $entry) {
            if ($entry['type'] === 'page' && $entry['key'] === $pageKey) {
                return $this->slug($entry['label'], 'page');
            }
            if ($entry['type'] !== 'group') {
                continue;
            }
            foreach ($entry['items'] as $item) {
                if ($item['key'] === $pageKey) {
                    return $this->slug($entry['label'], 'group') . '/' . $this->slug($item['label'], 'page');
                }
            }
        }

        throw new InvalidArgumentException("Navigation page is not configured: {$pageKey}");
    }

    public function resolvePath(string $path): ?string
    {
        $path = trim($path, '/');
        foreach (array_keys(self::PAGES) as $pageKey) {
            if (hash_equals($this->pathFor($pageKey), $path)) {
                return $pageKey;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function normalize(array $entries, bool $strict): array
    {
        if (count($entries) > count(self::PAGES) + 20) {
            throw new InvalidArgumentException('Navigation contains too many groups.');
        }

        $normalized = [];
        $seenPages = [];
        $seenGroups = [];
        foreach (array_values($entries) as $entry) {
            if (!is_array($entry)) {
                throw new InvalidArgumentException('Navigation entries must be objects.');
            }

            $type = strtolower(trim((string) ($entry['type'] ?? '')));
            if ($type === 'page') {
                $normalized[] = $this->normalizePage($entry, $seenPages, $strict);
                continue;
            }
            if ($type !== 'group') {
                throw new InvalidArgumentException('Navigation entries must be pages or groups.');
            }

            $id = strtolower(trim((string) ($entry['id'] ?? '')));
            if (!preg_match('/^[a-z0-9][a-z0-9-]{0,49}$/', $id)) {
                throw new InvalidArgumentException('Each navigation group needs a valid identifier.');
            }
            if (isset($seenGroups[$id])) {
                throw new InvalidArgumentException('Navigation group identifiers must be unique.');
            }
            $seenGroups[$id] = true;
            $items = [];
            foreach (array_values((array) ($entry['items'] ?? [])) as $item) {
                if (!is_array($item)) {
                    throw new InvalidArgumentException('Navigation group entries must be pages.');
                }
                $items[] = $this->normalizePage($item + ['type' => 'page'], $seenPages, $strict);
            }
            $normalized[] = [
                'type' => 'group',
                'id' => $id,
                'label' => $this->label($entry['label'] ?? null, 'Navigation Group', $strict),
                'items' => $items,
            ];
        }

        $missing = array_values(array_diff(array_keys(self::PAGES), array_keys($seenPages)));
        if ($missing !== [] && $strict) {
            throw new InvalidArgumentException('Every navigation page must appear exactly once.');
        }
        foreach ($missing as $key) {
            $normalized[] = [
                'type' => 'page',
                'key' => $key,
                'label' => self::PAGES[$key]['label'],
                'visible' => false,
                'admin_visible' => false,
            ];
        }

        return $normalized;
    }

    /** @return list<array<string, mixed>> */
    private function upgradeLegacyReconciliation(array $entries): array
    {
        $newPageKeys = [
            'reconciliation_customer_balances',
            'reconciliation_bank_eft',
            'reconciliation_bank_fsp_agra',
            'reconciliation_bank_fsp_7960',
        ];
        $existingPageKeys = collect($entries)->flatMap(
            fn($entry) => ($entry['type'] ?? null) === 'group'
                ? (array) ($entry['items'] ?? [])
                : [$entry]
        )->pluck('key');
        if ($existingPageKeys->intersect($newPageKeys)->isNotEmpty()) {
            return array_values($entries);
        }
        $groupIds = collect($entries)
            ->where('type', 'group')
            ->pluck('id')
            ->map(fn($id) => (string) $id);
        $groupId = $groupIds->contains('reconciliation') ? 'reconciliation-pages' : 'reconciliation';

        foreach ($entries as $index => $entry) {
            if (($entry['type'] ?? null) !== 'page' || ($entry['key'] ?? null) !== 'reconciliation') {
                continue;
            }

            $visible = filter_var($entry['visible'] ?? false, FILTER_VALIDATE_BOOL);
            $adminVisible = filter_var($entry['admin_visible'] ?? $visible, FILTER_VALIDATE_BOOL);
            $page = fn(string $key): array => [
                'type' => 'page',
                'key' => $key,
                'label' => self::PAGES[$key]['label'],
                'visible' => $visible,
                'admin_visible' => $adminVisible,
            ];
            $entries[$index] = [
                'type' => 'group',
                'id' => $groupId,
                'label' => trim((string) ($entry['label'] ?? '')) ?: 'Reconciliation',
                'items' => [
                    $page('reconciliation'),
                    $page('reconciliation_customer_balances'),
                    $page('reconciliation_bank_eft'),
                    $page('reconciliation_bank_fsp_agra'),
                    $page('reconciliation_bank_fsp_7960'),
                ],
            ];

            break;
        }

        return array_values($entries);
    }

    private function normalizePage(array $page, array &$seenPages, bool $strict): array
    {
        $key = strtolower(trim((string) ($page['key'] ?? '')));
        if (!isset(self::PAGES[$key])) {
            throw new InvalidArgumentException("Unknown navigation page: {$key}");
        }
        if (isset($seenPages[$key])) {
            throw new InvalidArgumentException('Every navigation page must appear exactly once.');
        }
        $seenPages[$key] = true;
        $visible = filter_var($page['visible'] ?? false, FILTER_VALIDATE_BOOL);

        return [
            'type' => 'page',
            'key' => $key,
            'label' => $this->label($page['label'] ?? null, self::PAGES[$key]['label'], $strict),
            'visible' => $visible,
            'admin_visible' => filter_var($page['admin_visible'] ?? $visible, FILTER_VALIDATE_BOOL),
        ];
    }

    private function label(mixed $label, string $fallback, bool $strict): string
    {
        $label = trim((string) $label);
        if ($label === '') {
            if ($strict) {
                throw new InvalidArgumentException('Navigation labels may not be empty.');
            }

            return $fallback;
        }
        if (mb_strlen($label) > 80) {
            throw new InvalidArgumentException('Navigation labels may not exceed 80 characters.');
        }

        return $label;
    }

    private function validatePaths(array $entries): void
    {
        $paths = [];
        $groupSlugs = [];
        foreach ($entries as $entry) {
            if ($entry['type'] === 'page') {
                $path = $this->slug($entry['label'], 'page');
                $reservedFor = self::RESERVED_STANDALONE_PATHS[$path] ?? null;
                if ($reservedFor !== null && $reservedFor !== $entry['key']) {
                    throw new InvalidArgumentException("The URL /{$path} is reserved by another application page.");
                }
                $this->recordPath($paths, $path);
                continue;
            }

            $groupSlug = $this->slug($entry['label'], 'group');
            if (in_array($groupSlug, self::RESERVED_GROUP_SLUGS, true)) {
                throw new InvalidArgumentException("The group URL /{$groupSlug} is reserved by the application.");
            }
            if (isset($groupSlugs[$groupSlug])) {
                throw new InvalidArgumentException("Group names must produce unique URL slugs.");
            }
            $groupSlugs[$groupSlug] = true;
            foreach ($entry['items'] as $item) {
                $this->recordPath($paths, $groupSlug . '/' . $this->slug($item['label'], 'page'));
            }
        }
    }

    private function recordPath(array &$paths, string $path): void
    {
        if (isset($paths[$path])) {
            throw new InvalidArgumentException("Navigation labels produce the duplicate URL /{$path}.");
        }
        $paths[$path] = true;
    }

    private function slug(string $label, string $type): string
    {
        $slug = Str::slug($label);
        if ($slug === '') {
            throw new InvalidArgumentException("The {$type} label must contain letters or numbers for its URL.");
        }

        return $slug;
    }
}
