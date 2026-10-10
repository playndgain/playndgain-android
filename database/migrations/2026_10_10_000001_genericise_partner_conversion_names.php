<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Partner-wall conversions whose postback named no offer were stored with the
 * network's own name as `offer_name`. That column reaches the publisher panel,
 * the {offer_name} postback macro and end-user history, so the name leaked the
 * network (AGENTS.md, network anonymity). Rewrite those rows to the generic
 * label ConversionProcessor now writes. Only embed conversions (no offer of
 * ours) whose name equals their network's name are touched.
 *
 * Not reversible: restoring the leak is not a thing a rollback should do.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('conversions')
            ->join('networks', 'networks.id', '=', 'conversions.network_id')
            ->whereNull('conversions.offer_id')
            ->whereColumn('conversions.offer_name', 'networks.name')
            ->update(['conversions.offer_name' => 'Partner wall offer']);
    }

    public function down(): void
    {
        //
    }
};
