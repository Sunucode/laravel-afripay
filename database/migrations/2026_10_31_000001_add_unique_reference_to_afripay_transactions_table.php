<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
 * One transaction per gateway and reference: the lock taken when a payment is
 * settled then always targets a single row. Existing duplicates are never
 * touched: the index is skipped with a warning, to be added once they are merged.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('afripay.table', 'afripay_transactions');

        $duplicates = DB::table($table)
            ->select('gateway', 'reference')
            ->whereNotNull('reference')
            ->groupBy('gateway', 'reference')
            ->havingRaw('COUNT(*) > 1')
            ->limit(5)
            ->get();

        if ($duplicates->isNotEmpty()) {
            Log::warning('AfriPay: unique index on (gateway, reference) skipped, duplicates found', [
                'table' => $table,
                'examples' => $duplicates->map(fn ($row) => $row->gateway.':'.$row->reference)->all(),
            ]);

            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->unique(['gateway', 'reference'], 'afripay_gateway_reference_unique');
        });
    }

    public function down(): void
    {
        $table = config('afripay.table', 'afripay_transactions');

        if (Schema::hasIndex($table, 'afripay_gateway_reference_unique')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropUnique('afripay_gateway_reference_unique');
            });
        }
    }
};
