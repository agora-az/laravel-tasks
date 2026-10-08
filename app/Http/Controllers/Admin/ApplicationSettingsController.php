<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\RuntimeSettings;
use App\Support\AllTransactionColumns;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ApplicationSettingsController extends Controller
{
    public function index(RuntimeSettings $settings): View
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
                'options' => $definition['type'] === 'columns'
                    ? $this->orderedColumnOptions($key, $settings->get($key))
                    : [],
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

        return view('admin.settings.index', ['groups' => $items]);
    }

    public function update(Request $request, RuntimeSettings $settings): RedirectResponse
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
            } elseif ($definition['type'] === 'group_order') {
                $value = array_values(array_unique(array_map('strval', (array) $value)));
                $allowed = $definition['options'];
                if (count($value) !== count($allowed) || array_diff($allowed, $value) !== []) {
                    $errors[$key] = 'Column groups must include Match, VieFund, EFT, FSP, and Bank exactly once.';
                    continue;
                }
            } else {
                $allowed = array_keys($this->columnDefinitions($key));
                $value = array_values(array_unique(array_map('strval', (array) $value)));
                if ($value === []) {
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

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($settings, $values, $request): void {
            foreach ($values as $key => $value) {
                $settings->set($key, $value, (int) $request->user()->id);
            }
        });

        return back()->with('success', 'Runtime settings updated. New requests and jobs will use these values.');
    }

    public function reset(Request $request, RuntimeSettings $settings): RedirectResponse
    {
        $key = (string) $request->input('setting_key');
        $settings->forget($key);

        return back()->with('success', 'The setting now uses its deployed environment default.');
    }

    private function orderedColumnOptions(string $settingKey, array $selected): array
    {
        $definitions = $this->columnDefinitions($settingKey);
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
            default => [],
        };
    }
}