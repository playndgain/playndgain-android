<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Older seeds copied APP_NAME and the support mailbox into `settings`, which
 * then froze the product name after a .env rename. Identity now reads env
 * unless an admin saved an override, so the seed copies have to go.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->whereIn('key', ['platform_name', 'support_email'])->delete();

        Cache::forget(Setting::CACHE_KEY);
    }

    public function down(): void
    {
        //
    }
};
