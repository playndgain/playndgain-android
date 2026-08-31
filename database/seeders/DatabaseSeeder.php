<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SettingsSeeder::class,
            NetworkSeeder::class,
            FraudRuleSeeder::class,
            IpProviderSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
