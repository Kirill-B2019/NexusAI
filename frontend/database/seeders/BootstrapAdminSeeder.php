<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class BootstrapAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('admin.email');
        $password = config('admin.password');

        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Укажите корректный NEXUS_ADMIN_EMAIL в локальном .env.');
        }

        if (! is_string($password) || strlen($password) < 16) {
            throw new RuntimeException('Задайте NEXUS_ADMIN_PASSWORD длиной не менее 16 символов в локальном .env.');
        }

        $admin = User::query()->firstOrNew(['email' => $email]);
        $admin->name = config('admin.name');
        $admin->password = $password;
        $admin->role = UserRole::Admin;
        $admin->save();
    }
}
