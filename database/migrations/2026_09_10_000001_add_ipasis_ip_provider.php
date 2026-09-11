<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('ip_providers')->insertOrIgnore([
            'key' => 'ipasis',
            'name' => 'IPASIS',
            'enabled' => false,
            'priority' => 15,
            'timeout_ms' => 600,
            'daily_cap' => 1000,
            'ttl_seconds' => 86400,
            'fail_open' => true,
            'circuit_failures' => 5,
            'circuit_cooldown_minutes' => 10,
            'consecutive_failures' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('ip_providers')->where('key', 'ipasis')->delete();
    }
};
