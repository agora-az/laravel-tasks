<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\NavigationMenu;
use App\Services\RuntimeSettings;
use App\Services\VieFund\VieFundTransactionMirrorManager;
use App\Support\AllTransactionColumns;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class ApplicationSettingsController extends Controller
{
    public function index(
        RuntimeSettings $settings,
        NavigationMenu $navigation,
        VieFundTransactionMirrorManager $mirrorManager
    ): View
    {
        $items = [];
        foreach ($settings->definitions() as $key => $definition) {
            if ($definition['type'] === 'group_order') {
                continue;
            }
            $items[$definition['group']][$key] = $definition + [
                'key' => $key,
                'value' => $settings->get($key),
                'default' => $settings->default($key),
                'overridden' => $settings->hasOverride($key),
                'options' => match ($definition['type']) {
                    'columns' => $this->orderedColumnOptions($key, $settings->get($key)),
                    'choice' => $definition['options'],
                    default => [],
                },
            ];
        }
        $columnGroup = 'VieFund All Transactions - Column Settings';
        $columnSettings = $items[$columnGroup] ?? [];
        $items[$columnGroup] = [];
        foreach ($settings->get('viefund.columns.group_order') as $groupKey) {
            $settingKey = RuntimeSettings::columnGroupSettingKeys()[$groupKey];
            if (isset($columnSettings[$settingKey])) {
                $items[$columnGroup][$settingKey] = $columnSettings[$settingKey] + ['column_group' => $groupKey];
            }
        }

        return view('admin.settings.index', [
            'groups' => $items,
            'navigationEntries' => $navigation->current(),
            'navigationDefaults' => $navigation->defaults(),
            'navigationOverridden' => $navigation->hasOverride(),
            'navigationPages' => $navigation->pages(),
            'mirrorStatus' => $mirrorManager->status(),
        ]);
    }

    public function update(
        Request $request,
        RuntimeSettings $settings,
        NavigationMenu $navigation,
        VieFundTransactionMirrorManager $mirrorManager
    ): RedirectResponse
    {
        $submitted = (array) $request->input('settings', []);
        $values = [];
        $errors = [];

        foreach ($settings->definitions() as $key => $definition) {
            $value = $submitted[$key] ?? null;
            if ($definition['type'] === 'integer') {
                if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                    $errors[$key] = "{$definition['label']} must be a whole number.";
                    continue;
                }
                $value = (int) $value;
                if ($value < $definition['min'] || $value > $definition['max']) {
                    $errors[$key] = "{$definition['label']} must be between {$definition['min']} and {$definition['max']}.";
                    continue;
                }
            } elseif ($definition['type'] === 'choice') {
                $value = trim((string) $value);
                if (!array_key_exists($value, $definition['options'])) {
                    $errors[$key] = "{$definition['label']} contains an unsupported option.";
                    continue;
                }
                try {
                    if ($key === 'viefund.all_transactions.data_source') {
                        $mirrorManager->assertSourceModeAllowed($value);
                    }
                } catch (InvalidArgumentException $exception) {
                    $errors[$key] = $exception->getMessage();
                    continue;
                }
            } elseif ($definition['type'] === 'group_order') {
                $value = array_values(array_unique(array_map('strval', (array) $value)));
                $allowed = $definition['options'];
                if (count($value) !== count($allowed) || array_diff($allowed, $value) !== []) {
                    $errors[$key] = 'Column groups must include Match, VieFund, EFT, FSP, ASM, and Bank exactly once.';
                    continue;
                }
            } else {
                $allowed = array_keys($this->columnDefinitions($key));
                $value = array_values(array_unique(array_map('strval', (array) $value)));
                if ($value === [] && $key !== 'viefund.columns.asm') {
                    $errors[$key] = "Select at least one {$definition['label']}.";
                    continue;
                }
                if (array_diff($value, $allowed) !== []) {
                    $errors[$key] = "{$definition['label']} contains an unsupported column.";
                    continue;
                }
            }

            $values[$key] = $value;
        }
        try {
            $navigationEntries = $navigation->normalizeSubmitted(
                (array) $request->input('navigation.entries', [])
            );
        } catch (InvalidArgumentException $e) {
            $errors['navigation'] = $e->getMessage();
            $navigationEntries = [];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($settings, $navigation, $navigationEntries, $values, $request): void {
            foreach ($values as $key => $value) {
                $settings->set($key, $value, (int) $request->user()->id);
            }
            $navigation->save($navigationEntries, (int) $request->user()->id);
        });

        return back()->with('success', 'Runtime settings updated. New requests and jobs will use these values.');
    }

    public function reset(
        Request $request,
        RuntimeSettings $settings,
        NavigationMenu $navigation,
        VieFundTransactionMirrorManager $mirrorManager
    ): RedirectResponse
    {
        $key = (string) $request->input('setting_key');
        if ($key === NavigationMenu::SETTING_KEY) {
            $navigation->forget();

            return back()->with('success', 'Navigation now uses its deployed default.');
        }
        if ($key === 'viefund.all_transactions.data_source') {
            try {
                $mirrorManager->assertSourceModeAllowed((string) $settings->default($key));
            } catch (InvalidArgumentException $exception) {
                return back()->withErrors([$key => $exception->getMessage()]);
            }
        }
        $settings->forget($key);

        return back()->with('success', 'The setting now uses its deployed environment default.');
    }

    public function mirrorStatus(VieFundTransactionMirrorManager $mirrorManager): \Illuminate\Http\JsonResponse
    {
        return response()->json($mirrorManager->status());
    }

    public function startMirror(VieFundTransactionMirrorManager $mirrorManager): RedirectResponse
    {
        try {
            $mirrorManager->startInitial();
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['mirror' => $exception->getMessage()]);
        }

        return back()->with('success', 'VieFund transaction mirror population queued.');
    }

    public function pauseMirror(VieFundTransactionMirrorManager $mirrorManager): RedirectResponse
    {
        $mirrorManager->pause();

        return back()->with('success', 'VieFund transaction mirror will remain paused at its last committed checkpoint.');
    }

    public function resumeMirror(VieFundTransactionMirrorManager $mirrorManager): RedirectResponse
    {
        $mirrorManager->resume();

        return back()->with('success', 'VieFund transaction mirror synchronization resumed.');
    }

    public function syncMirror(VieFundTransactionMirrorManager $mirrorManager): RedirectResponse
    {
        try {
            $mirrorManager->startIncremental();
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['mirror' => $exception->getMessage()]);
        }

        return back()->with('success', 'VieFund transaction mirror incremental synchronization queued.');
    }

    public function backfillMirrorFundIds(VieFundTransactionMirrorManager $mirrorManager): RedirectResponse
    {
        try {
            $mirrorManager->startFundIdBackfill();
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['mirror' => $exception->getMessage()]);
        }

        return back()->with('success', 'Mirror fund/unit ID backfill queued. Fund-filtered requests will use the current source until verification completes.');
    }

    private function orderedColumnOptions(string $settingKey, array $selected): array
    {
        $definitions = $this->columnDefinitions($settingKey);
        $selected = array_values(array_intersect($selected, array_keys($definitions)));
        $keys = array_values(array_unique(array_merge($selected, array_keys($definitions))));

        return array_map(
            fn(string $key): array => [
                'key' => $key,
                'label' => $definitions[$key]['label'],
                'selected' => in_array($key, $selected, true),
            ],
            $keys
        );
    }

    private function columnDefinitions(string $settingKey): array
    {
        return match ($settingKey) {
            'viefund.columns.match' => AllTransactionColumns::availableMatchColumns(),
            'viefund.columns.transactions' => AllTransactionColumns::availableTransactionColumns(),
            'viefund.columns.eft' => AllTransactionColumns::availableEftColumns(),
            'viefund.columns.bank' => AllTransactionColumns::availableBankColumns(),
            'viefund.columns.fsp' => AllTransactionColumns::availableFspColumns(),
            'viefund.columns.asm' => AllTransactionColumns::availableAsmColumns(),
            default => [],
        };
    }
}
