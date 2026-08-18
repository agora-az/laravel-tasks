<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlement_instructions', function (Blueprint $table) {
            $table->string('switch_from_fund_id', 32)->nullable()->after('fund_id');
            $table->string('switch_to_fund_id', 32)->nullable()->after('switch_from_fund_id');
        });

        DB::table('settlement_instructions')
            ->where('side', 'SWITCH')
            ->whereNotNull('raw_xml')
            ->select(['id', 'raw_xml'])
            ->orderBy('id')
            ->chunkById(200, function ($instructions): void {
                foreach ($instructions as $instruction) {
                    $xml = simplexml_load_string($instruction->raw_xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOWARNING | LIBXML_NOERROR);
                    if ($xml === false) {
                        continue;
                    }

                    $namespace = $xml->getDocNamespaces(true)[''] ?? null;
                    if ($namespace) {
                        $xml->registerXPathNamespace('f', $namespace);
                        $fromFundId = $xml->xpath('./f:SwitchCon/f:SwitchOut/f:SellFund/f:FundID')[0] ?? null;
                        $toFundId = $xml->xpath('./f:SwitchCon/f:SwitchIn/f:BuyFund/f:FundID')[0] ?? null;
                    } else {
                        $fromFundId = $xml->xpath('./SwitchCon/SwitchOut/SellFund/FundID')[0] ?? null;
                        $toFundId = $xml->xpath('./SwitchCon/SwitchIn/BuyFund/FundID')[0] ?? null;
                    }

                    DB::table('settlement_instructions')
                        ->where('id', $instruction->id)
                        ->update([
                            'fund_id' => null,
                            'switch_from_fund_id' => $fromFundId !== null ? trim((string) $fromFundId) : null,
                            'switch_to_fund_id' => $toFundId !== null ? trim((string) $toFundId) : null,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('settlement_instructions', function (Blueprint $table) {
            $table->dropColumn(['switch_from_fund_id', 'switch_to_fund_id']);
        });
    }
};