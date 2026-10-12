<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\BootstrapAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BootstrapAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_is_created_with_credentials_from_configuration(): void
    {
        config([
            'admin.name' => 'Локальный администратор',
            'admin.email' => 'admin@example.test',
            'admin.password' => 'test-only-bootstrap-password',
        ]);

        $this->seed(BootstrapAdminSeeder::class);

        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();

        $this->assertSame('Локальный администратор', $admin->name);
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertTrue(Hash::check('test-only-bootstrap-password', $admin->password));
    }

    public function test_seeder_rejects_missing_admin_credentials(): void
    {
        config(['admin.email' => null, 'admin.password' => null]);

        $this->expectException(\RuntimeException::class);

        $this->seed(BootstrapAdminSeeder::class);
    }
}
