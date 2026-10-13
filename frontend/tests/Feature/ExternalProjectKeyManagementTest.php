<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ExternalProjectKeyManagementTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT_ID = '81d7a413-2a59-48aa-9e3f-1cc00f2ab32a';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.nexus_ai.url' => 'https://nexus.test',
            'services.nexus_ai.admin_key' => 'admin-key-must-stay-server-side',
        ]);
    }

    public function test_admin_can_list_external_key_status_and_masked_metadata_only(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $internalKeyId = '1a216802-1949-4e0b-9a3e-7bbfcb2fe982';
        $activeKeyId = '2a216802-1949-4e0b-9a3e-7bbfcb2fe982';
        $expiredKeyId = '3a216802-1949-4e0b-9a3e-7bbfcb2fe982';
        $revokedKeyId = '4a216802-1949-4e0b-9a3e-7bbfcb2fe982';
        Project::query()->create([
            'owner_user_id' => $admin->id,
            'name' => 'Проект',
            'nexus_project_id' => self::PROJECT_ID,
            'nexus_api_key_id' => $internalKeyId,
            'api_key' => 'laravel-integration-secret',
        ]);

        Http::fake([
            'https://nexus.test/v1/projects' => Http::response([
                'projects' => [$this->remoteProject()],
            ]),
            'https://nexus.test/v1/projects/'.self::PROJECT_ID.'/keys' => Http::response([
                'keys' => [
                    $this->remoteKey($internalKeyId, 'Laravel integration', 'internal_12345'),
                    $this->remoteKey($activeKeyId, 'Active app', 'nexus_active', true, 'expert_architect', now()->addDay()->toIso8601String(), now()->subHour()->toIso8601String()),
                    $this->remoteKey($expiredKeyId, 'Expired app', 'nexus_expired', true, null, '2020-01-01T00:00:00+00:00'),
                    $this->remoteKey($revokedKeyId, 'Revoked app', 'nexus_revoked', false),
                ],
            ]),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.projects'));

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Выпуск новых ключей временно отключён')
            ->assertSee('Active app')
            ->assertSee('nexus_active…')
            ->assertSee('expert_architect')
            ->assertSee('Подключался')
            ->assertSee('Ещё не подключался')
            ->assertSee('Активен')
            ->assertSee('Истёк')
            ->assertSee('Отозван')
            ->assertDontSee('Laravel integration')
            ->assertDontSee('internal_12345')
            ->assertDontSee('admin-key-must-stay-server-side')
            ->assertDontSee('full-key-must-not-be-rendered');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://nexus.test/v1/projects'
            && $request->hasHeader('X-API-Key', 'admin-key-must-stay-server-side'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://nexus.test/v1/projects/'.self::PROJECT_ID.'/keys'
            && $request->hasHeader('X-API-Key', 'admin-key-must-stay-server-side'));
    }

    public function test_admin_can_revoke_an_external_key_only_after_exact_name_confirmation(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $keyId = '2a216802-1949-4e0b-9a3e-7bbfcb2fe982';
        $keyListUrl = 'https://nexus.test/v1/projects/'.self::PROJECT_ID.'/keys';
        $revokeUrl = $keyListUrl.'/'.$keyId;

        Http::fake([
            $keyListUrl => Http::response(['keys' => [
                $this->remoteKey($keyId, 'Partner integration', 'nexus_partner'),
            ]]),
            $revokeUrl => Http::response([], 204),
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.projects.application-keys.revoke', [self::PROJECT_ID, $keyId]), [
                'confirmation' => 'Partner integration',
            ])
            ->assertRedirect(route('admin.projects'))
            ->assertSessionHas('status', 'Ключ внешнего приложения отозван.');

        Http::assertSent(fn (Request $request): bool => $request->url() === $keyListUrl && $request->method() === 'GET');
        Http::assertSent(fn (Request $request): bool => $request->url() === $revokeUrl
            && $request->method() === 'DELETE'
            && $request->hasHeader('X-API-Key', 'admin-key-must-stay-server-side'));
    }

    public function test_wrong_revoke_confirmation_never_calls_nexus_delete(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $keyId = '2a216802-1949-4e0b-9a3e-7bbfcb2fe982';
        $keyListUrl = 'https://nexus.test/v1/projects/'.self::PROJECT_ID.'/keys';

        Http::fake([
            $keyListUrl => Http::response(['keys' => [
                $this->remoteKey($keyId, 'Partner integration', 'nexus_partner'),
            ]]),
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.projects.application-keys.revoke', [self::PROJECT_ID, $keyId]), [
                'confirmation' => 'Wrong name',
            ])
            ->assertRedirect(route('admin.projects'))
            ->assertSessionHas('error', 'Имя приложения не совпало. Отзыв ключа отменён.');

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_non_admin_cannot_list_or_revoke_external_keys(): void
    {
        Http::fake();
        $keyId = '2a216802-1949-4e0b-9a3e-7bbfcb2fe982';

        foreach ([UserRole::User, UserRole::Viewer] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('admin.projects'))
                ->assertForbidden();

            $this->delete(route('admin.projects.application-keys.revoke', [self::PROJECT_ID, $keyId]), [
                'confirmation' => 'Partner integration',
            ])->assertForbidden();
        }

        Http::assertNothingSent();
    }

    public function test_key_issuance_has_no_route_or_browser_action_until_server_delivery_is_configured(): void
    {
        Http::fake([
            'https://nexus.test/v1/projects' => Http::response([
                'projects' => [$this->remoteProject()],
            ]),
            'https://nexus.test/v1/projects/'.self::PROJECT_ID.'/keys' => Http::response(['keys' => []]),
        ]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->get(route('admin.projects'))
            ->assertOk()
            ->assertSee('Выпуск новых ключей временно отключён')
            ->assertDontSee('name="allowed_experts"', false)
            ->assertDontSee('name="expires_at"', false);

        $this->assertFalse(Route::has('admin.projects.application-keys.store'));
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    /**
     * @return array<string, mixed>
     */
    private function remoteProject(): array
    {
        return [
            'id' => self::PROJECT_ID,
            'name' => 'Project from NEXUS',
            'description' => null,
            'metadata' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function remoteKey(
        string $id,
        string $name,
        string $prefix,
        bool $active = true,
        ?string $allowedExpert = null,
        ?string $expiresAt = null,
        ?string $lastUsedAt = null,
    ): array {
        return [
            'id' => $id,
            'name' => $name,
            'key_prefix' => $prefix,
            'type' => 'project',
            'is_active' => $active,
            'allowed_experts' => $allowedExpert === null ? null : [$allowedExpert],
            'rate_limit_per_min' => 60,
            'created_at' => now()->subDay()->toIso8601String(),
            'last_used_at' => $lastUsedAt,
            'expires_at' => $expiresAt,
            'key' => 'full-key-must-not-be-rendered',
        ];
    }
}
