<?php

namespace App\Services\Bank;

interface MonthlyPdfStatementParser
{
    /**
     * @return array<string, mixed>
     */
    public function parseFile(string $path, string $currency = 'CAD'): array;
}
