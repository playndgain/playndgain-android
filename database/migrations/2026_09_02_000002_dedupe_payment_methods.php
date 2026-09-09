<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One destination per type per publisher — docs/08 §10. The Payments page
 * edits one card per type, so a second wallet of the same type would be
 * invisible. The Active row wins, else the newest; the rest are removed with
 * an audit row each so support can answer "where did my other wallet go".
 * Paid history is untouched: a withdrawal carries its own encrypted snapshot
 * and `withdrawals.payment_method_id` nulls on delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        $groups = DB::table('payment_methods')
            ->select('publisher_id', 'type')
            ->groupBy('publisher_id', 'type')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $rows = DB::table('payment_methods')
                ->where('publisher_id', $group->publisher_id)
                ->where('type', $group->type)
                ->orderByDesc('is_default')
                ->orderByDesc('id')
                ->get();

            $keep = $rows->first();

            foreach ($rows->skip(1) as $row) {
                DB::table('payment_methods')->where('id', $row->id)->delete();

                DB::table('audit_logs')->insert([
                    'user_id' => DB::table('publishers')->where('id', $row->publisher_id)->value('user_id'),
                    'action' => 'payment_method.deduplicated',
                    'subject_type' => 'App\\Models\\PaymentMethod',
                    'subject_id' => $row->id,
                    'before' => json_encode(['type' => $row->type, 'label' => $row->label, 'is_default' => (bool) $row->is_default]),
                    'after' => json_encode(['kept_payment_method_id' => $keep->id]),
                    'ip' => null,
                    'created_at' => now(),
                ]);
            }
        }

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->unique(['publisher_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropUnique(['publisher_id', 'type']);
        });
    }
};
