<?php

namespace App\Http\Controllers;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DocumentationController extends Controller
{
    private const CATEGORIES = [
        'customer' => [
            'label' => 'Customer / End User',
            'description' => 'Guides for running reports and understanding their output.',
        ],
        'technical' => [
            'label' => 'Technical',
            'description' => 'Implementation criteria, investigations, data sources, and integration details.',
        ],
    ];

    private const DOCUMENTS = [
        'customer-balances-guide' => [
            'title' => 'Customer Balances Report Guide',
            'description' => 'Purpose, report criteria, output fields, operating instructions, and caveats.',
            'file' => 'viefund_customer_balances_report_guide.md',
            'category' => 'customer',
        ],
        'customer-balances-criteria' => [
            'title' => 'Customer Balances Technical Criteria',
            'description' => 'Validated query criteria, historical reconstruction details, and command-line recipes.',
            'file' => 'viefund_customer_balances_report_criteria.md',
            'category' => 'technical',
        ],
        'cash-daily-snapshots' => [
            'title' => 'Cash Daily Snapshot Architecture',
            'description' => 'Inception baselines, refresh scheduling, audit history, cache safeguards, and operations.',
            'file' => 'viefund_cash_daily_snapshots.md',
            'category' => 'technical',
        ],
        'customer-balances-exclusions' => [
            'title' => 'Customer Balances Exclusion Review',
            'description' => 'Historical account-population investigation and candidate exclusion flags.',
            'file' => 'viefund_customer_balances_exclusion_review.md',
            'category' => 'technical',
        ],
        'remote-viefund-query-flow' => [
            'title' => 'Remote VieFund Query Flow',
            'description' => 'Application flow for remote VieFund customer and transaction queries.',
            'file' => 'remote-viefund-query-flow.md',
            'category' => 'technical',
        ],
        'viefund-data-source-inventory' => [
            'title' => 'VieFund Transaction Data Sources',
            'description' => 'Inventory and assessment of VieFund transaction-related source tables.',
            'file' => 'viefund-trx-data-source-inventory.md',
            'category' => 'technical',
        ],
    ];

    public function index(): View
    {
        return view('docs.index', [
            'documents' => self::DOCUMENTS,
            'categories' => self::CATEGORIES,
        ]);
    }

    public function show(string $document): View
    {
        abort_unless(isset(self::DOCUMENTS[$document]), 404);

        $metadata = self::DOCUMENTS[$document];
        $path = base_path('docs/' . $metadata['file']);
        abort_unless(is_file($path), 404);

        $markdown = (string) file_get_contents($path);
        $html = Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        foreach (self::DOCUMENTS as $slug => $registeredDocument) {
            $html = str_replace(
                'href="' . $registeredDocument['file'] . '"',
                'href="' . route('docs.show', $slug) . '"',
                $html
            );
        }

        return view('docs.show', [
            'document' => $document,
            'metadata' => $metadata,
            'documents' => self::DOCUMENTS,
            'categories' => self::CATEGORIES,
            'content' => new HtmlString($html),
        ]);
    }
}