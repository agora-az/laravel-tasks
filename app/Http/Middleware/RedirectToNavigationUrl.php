<?php

namespace App\Http\Middleware;

use App\Services\NavigationMenu;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectToNavigationUrl
{
    private const ROUTE_PAGE_KEYS = [
        'dashboard' => 'dashboard',
        'viefund-transactions.index' => 'all_transactions',
        'remote-viefund.index' => 'customer_transactions',
        'eft-files.index' => 'eft_files',
        'settlement-instructions.index' => 'fsp_files',
        'asm-settlements.index' => 'asm_files',
        'bank-entries.index' => 'bank_statements',
        'reconciliations.transactions' => 'reconciliation',
        'reconciliations.customer-balances' => 'reconciliation_customer_balances',
        'reconciliations.daily-totals' => 'reconciliation_bank_eft',
        'reports.index' => 'reports',
        'docs.index' => 'docs',
        'imports.transactions' => 'transaction_data',
        'reconciliations.matches' => 'matches',
    ];

    public function __construct(private readonly NavigationMenu $navigation) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->isMethod('GET')) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();
        $pageKey = self::ROUTE_PAGE_KEYS[$routeName] ?? null;
        if ($routeName === 'reconciliations.bank-fsp.source') {
            $pageKey = $request->route('source') === '7960'
                ? 'reconciliation_bank_fsp_7960'
                : 'reconciliation_bank_fsp_agra';
        }
        if ($pageKey === null) {
            return $next($request);
        }

        $expectedPath = $this->navigation->pathFor($pageKey);
        if (trim($request->path(), '/') === $expectedPath) {
            return $next($request);
        }

        $url = $this->navigation->url($pageKey);
        $query = $request->query();
        if ($pageKey === 'transaction_data' && $request->route('type')) {
            $query['type'] = $request->route('type');
        }
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return redirect()->to($url);
    }
}
