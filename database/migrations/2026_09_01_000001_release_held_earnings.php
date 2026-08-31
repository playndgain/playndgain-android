<?php

use App\Enums\LedgerEntryType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Policy change: rolling hold days are gone. Already-held earnings become
 * available now; new conversions clear at the start of the next month.
 *
 * Query builder, not Eloquent — ledger rows refuse updates at the model.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('conversions')
            ->where('clears_at', '>', $now)
            ->update(['clears_at' => $now]);

        DB::table('ledger_entries')
            ->where('type', LedgerEntryType::Earning->value)
            ->where('available_at', '>', $now)
            ->update(['available_at' => $now]);
    }

    public function down(): void
    {
        // Irreversible: the original clear dates are not stored elsewhere.
    }
};
