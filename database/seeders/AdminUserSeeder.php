<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('advault.seed_admin_email');
        $password = config('advault.seed_admin_password') ?? Str::password(20);

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => config('app.name').' Admin',
                'password' => $password,
                'role' => UserRole::Admin,
                'email_verified_at' => now(),
            ],
        );

        if ($user->wasRecentlyCreated) {
            // Printed once, here, and nowhere else — a generated password that
            // is not read off this line is gone.
            $this->command->info("Admin user created: {$email}");
            $this->command->info("Password: {$password}");
            $this->command->warn('2FA is required before this account can reach /admin.');
        }
    }
}
