<?php

namespace App\Http\Controllers;

use App\Services\NavigationMenu;
use Illuminate\Http\Request;

class NavigationPageController extends Controller
{
    private const HANDLERS = [
        'dashboard' => [ReconciliationController::class, 'dashboard'],
        'all_transactions' => [RemoteVieFundController::class, 'allTransactions'],
        'customer_transactions' => [RemoteVieFundController::class, 'index'],
        'eft_files' => [EftFileController::class, 'index'],
        'fsp_files' => [SettlementInstructionController::class, 'index'],
        'asm_files' => [AsmSettlementController::class, 'index'],
        'bank_statements' => [BankStatementEntryController::class, 'index'],
        'reconciliation' => [TransactionReconciliationController::class, 'index'],
        'reconciliation_customer_balances' => [CustomerBalanceReconciliationController::class, 'index'],
        'reconciliation_bank_eft' => [DailyTotalsComparisonController::class, 'index'],
        'reconciliation_bank_fsp_agra' => [DailyTotalsComparisonController::class, 'fspIndex', ['source' => 'agra']],
        'reconciliation_bank_fsp_7960' => [DailyTotalsComparisonController::class, 'fspIndex', ['source' => '7960']],
        'reports' => [VieFundReportsController::class, 'index'],
        'docs' => [DocumentationController::class, 'index'],
        'transaction_data' => [ImportController::class, 'transactions'],
        'matches' => [ReconciliationController::class, 'matches'],
    ];

    public function standalone(
        Request $request,
        NavigationMenu $navigation,
        string $navigationPage
    ): mixed {
        return $this->dispatch($navigation, $navigationPage);
    }

    public function grouped(
        Request $request,
        NavigationMenu $navigation,
        string $navigationGroup,
        string $navigationPage
    ): mixed {
        return $this->dispatch($navigation, $navigationGroup . '/' . $navigationPage);
    }

    private function dispatch(NavigationMenu $navigation, string $path): mixed
    {
        $pageKey = $navigation->resolvePath($path);
        abort_if($pageKey === null, 404);

        [$controller, $method, $parameters] = self::HANDLERS[$pageKey] + [2 => []];
        if ($pageKey === 'transaction_data') {
            $parameters['type'] = request()->query('type', 'viefund');
        }

        return app()->call([app($controller), $method], $parameters);
    }
}
