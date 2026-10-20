<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\SecretDeliveryException;
use App\Models\ExternalApplicationSecret;
use App\Models\Project;
use App\Models\User;
use App\Services\ApplicationSecretStore;
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
            'services.application_key_delivery.enabled' => false,
        ]);
    }

    public function test_admin_can_list_external_key_status_and_masked_metadata_only(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $internalKeyId = '1a216802-1949-4e0b-9a3e-7bbfcb2fe982';
        $activeKeyId = '2a216802-1949-4e0b-9a3e-7bbfcb2fe982';
        $expiredKeyId = '3a216802-1949-4e0b-9a3e-7bbfcb2fe982';
        $revokedKeyId = '4a216802-1949-4e0b-9a3e-7bbfcb2fe982';
        $scheduledKeyId = '7a216802-1949-4e0b-9a3e-7bbfcb2fe982';
        $managedSecretArn = 'arn:aws:secretsmanager:us-east-1:123456789012:secret:managed-active-key';
        Project::query()->create([
            'owner_user_id' => $admin->id,
            'name' => 'Проект',
            'nexus_project_id' => self::PROJECT_ID,
            'nexus_api_key_id' => $internalKeyId,
            'api_key' => 'laravel-integration-secret',
        ]);
        ExternalApplicationSecret::query()->create([
            'project_id' => self::PROJECT_ID,
            'key_id' => $activeKeyId,
            'application_name' => 'Active app',
            'secret_name' => 'nexus-ai/application-keys/projects/'.self::PROJECT_ID.'/applications/'.$activeKeyId,
            'secret_arn' => $managedSecretArn,
        ]);

        Http::fake([
            'https://nexus.test/v1/projects' => Http::response([
                'projects' => [$this->remoteProject()],
            ]),
            'https://nexus.test/v1/experts/all' => Http::response(['experts' => [[
                'key' => 'expert_architect',
                'name' => 'Архитектор проекта',
                'system_prompt' => 'Ты эксперт по архитектуре проектов.',
                'sort_order' => 10,
                'is_system' => true,
            ]]]),
            'https://nexus.test/v1/projects/'.self::PROJECT_ID.'/keys' => Http::response([
                'keys' => [
                    $this->remoteKey($internalKeyId, 'Laravel integration', 'internal_12345'),
                    $this->remoteKey($activeKeyId, 'Active app', 'nexus_active', true, 'expert_architect', now()->addDay()->toIso8601String(), now()->subHour()->toIso8601String()),
                    $this->remoteKey($expiredKeyId, 'Expired app', 'nexus_expired', true, null, '2020-01-01T00:00:00+00:00'),
                    $this->remoteKey($revokedKeyId, 'Revoked app', 'nexus_revoked', false),
                    $this->remoteKey($scheduledKeyId, 'Scheduled app', 'nexus_scheduled', true, null, null, null, now()->addDay()->toIso8601String()),
                ],
            ]),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.projects.show', self::PROJECT_ID));

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('← Все проекты')
            ->assertSee('API-ключи внешних приложений')
            ->assertSee('Выпустить и показать ключ один раз')
            ->assertSee('Active app')
            ->assertSee('nexus_active…')
            ->assertSee('Архитектор проекта')
            ->assertSee('60 запросов/мин')
            ->assertSee('Подключался')
            ->assertSee('Ещё не подключался')
            ->assertSee('Активен')
            ->assertSee('Запланирован')
            ->assertSee('Scheduled app')
            ->assertSee('Истёк')
            ->assertSee('Отозван')
            ->assertSee('Доставлен в AWS Secrets Manager')
            ->assertSee($managedSecretArn)
            ->assertDontSee('Laravel integration')
            ->assertDontSee('internal_12345')
            ->assertDontSee('admin-key-must-stay-server-side')
            ->assertDontSee('full-key-must-not-be-rendered');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://nexus.test/v1/projects'
            && $request->hasHeader('X-API-Key', 'admin-key-must-stay-server-side'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://nexus.test/v1/projects/'.self::PROJECT_ID.'/keys'
            && $request->hasHeader('X-API-Key', 'admin-key-must-stay-server-side'));
    }

    public function test_projects_directory_is_a_compact_list_with_links_to_project_settings(): void
    {
        Http::fake([
            'https://nexus.test/v1/projects' => Http::response([
                'projects' => [$this->remoteProject()],
            ]),
        ]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->get(route('admin.projects'))
            ->assertOk()
            ->assertSee('Все проекты сервиса')
            ->assertSee('Project from NEXUS')
            ->assertSee(route('admin.projects.show', self::PROJECT_ID))
            ->assertSee('Открыть настройки')
            ->assertDontSee('API-ключи внешних приложений')
            ->assertDontSee('name="allowed_experts[]"', false);

        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/keys'));
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

        $secretStore = \Mockery::mock(ApplicationSecretStore::class);
        $secretStore->shouldReceive('delete')
            ->once()
            ->with(
                'nexus-ai/application-keys/projects/'.self::PROJECT_ID.'/applications/'.$keyId,
                [
                    'ManagedBy' => 'NEXUS-AI-Laravel',
                    'ProjectId' => self::PROJECT_ID,
                    'Application' => 'Partner integration',
                ],
            );
        $this->app->instance(ApplicationSecretStore::class, $secretStore);
        ExternalApplicationSecret::query()->create([
            'project_id' => self::PROJECT_ID,
            'key_id' => $keyId,
            'application_name' => 'Partner integration',
            'secret_name' => 'nexus-ai/application-keys/projects/'.self::PROJECT_ID.'/applications/'.$keyId,
            'secret_arn' => 'arn:aws:secretsmanager:us-east-1:123456789012:secret:managed-app-key',
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.projects.application-keys.revoke', [self::PROJECT_ID, $keyId]), [
                'confirmation' => 'Partner integration',
            ])
            ->assertRedirect(route('admin.projects.show', self::PROJECT_ID))
            ->assertSessionHas('status', 'Ключ внешнего приложения отозван; секрет AWS недоступен для чтения и поставлен на удаление согласно recovery policy.');

        Http::assertSent(fn (Request $request): bool => $request->url() === $keyListUrl && $request->method() === 'GET');
        Http::assertSent(fn (Request $request): bool => $request->url() === $revokeUrl
            && $request->method() === 'DELETE'
            && $request->hasHeader('X-API-Key', 'admin-key-must-stay-server-side'));
        $this->assertDatabaseMissing('external_application_secrets', ['key_id' => $keyId]);
    }

    public function test_revoking_a_key_created_outside_laravel_does_not_attempt_aws_cleanup(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $keyId = '2a216802-1949-4e0b-9a3e-7bbfcb2fe982';
        $keyListUrl = 'https://nexus.test/v1/projects/'.self::PROJECT_ID.'/keys';
        $revokeUrl = $keyListUrl.'/'.$keyId;
        Http::fake([
            $keyListUrl => Http::response(['keys' => [
                $this->remoteKey($keyId, 'Server-managed application', 'nexus_server'),
            ]]),
            $revokeUrl => Http::response([], 204),
        ]);

        $secretStore = \Mockery::mock(ApplicationSecretStore::class);
        $secretStore->shouldNotReceive('delete');
        $this->app->instance(ApplicationSecretStore::class, $secretStore);

        $this->actingAs($admin)
            ->delete(route('admin.projects.application-keys.revoke', [self::PROJECT_ID, $keyId]), [
                'confirmation' => 'Server-managed application',
            ])
            ->assertRedirect(route('admin.projects.show', self::PROJECT_ID))
            ->assertSessionHas('status', 'Ключ внешнего приложения отозван.');
    }

    public function test_secret_cleanup_failure_reports_manual_action_after_nexus_key_is_revoked(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $keyId = '2a216802-1949-4e0b-9a3e-7bbfcb2fe982';
        $keyListUrl = 'https://nexus.test/v1/projects/'.self::PROJECT_ID.'/keys';
        $revokeUrl = $keyListUrl.'/'.$keyId;
        $secretName = 'nexus-ai/application-keys/projects/'.self::PROJECT_ID.'/applications/'.$keyId;

        Http::fake([
            $keyListUrl => Http::response(['keys' => [
                $this->remoteKey($keyId, 'Partner integration', 'nexus_partner'),
            ]]),
            $revokeUrl => Http::response([], 204),
        ]);

        ExternalApplicationSecret::query()->create([
            'project_id' => self::PROJECT_ID,
            'key_id' => $keyId,
            'application_name' => 'Partner integration',
            'secret_name' => $secretName,
            'secret_arn' => 'arn:aws:secretsmanager:us-east-1:123456789012:secret:managed-app-key',
        ]);
        $secretStore = \Mockery::mock(ApplicationSecretStore::class);
        $secretStore->shouldReceive('delete')
            ->once()
            ->andThrow(new SecretDeliveryException('AWS denied the delete operation.'));
        $this->app->instance(ApplicationSecretStore::class, $secretStore);

        $this->actingAs($admin)
            ->delete(route('admin.projects.application-keys.revoke', [self::PROJECT_ID, $keyId]), [
                'confirmation' => 'Partner integration',
            ])
            ->assertRedirect(route('admin.projects.show', self::PROJECT_ID))
            ->assertSessionHas('error', 'Ключ NEXUS AI отозван, но очистка секрета AWS не подтверждена. Повторите отзыв после проверки доступа AWS или удалите секрет вручную: '.$secretName);

        Http::assertSent(fn (Request $request): bool => $request->url() === $revokeUrl
            && $request->method() === 'DELETE');
        $this->assertDatabaseHas('external_application_secrets', ['key_id' => $keyId]);
    }

    public function test_revoked_managed_key_can_retry_aws_cleanup_without_revoking_it_again(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $keyId = '2a216802-1949-4e0b-9a3e-7bbfcb2fe982';
        $keyListUrl = 'https://nexus.test/v1/projects/'.self::PROJECT_ID.'/keys';
        $revokeUrl = $keyListUrl.'/'.$keyId;
        $listCalls = 0;
        Http::fake(function (Request $request) use (&$listCalls, $keyListUrl, $keyId, $revokeUrl) {
            if ($request->url() === $keyListUrl) {
                $listCalls++;

                return Http::response(['keys' => [
                    $this->remoteKey($keyId, 'Partner integration', 'nexus_partner', $listCalls === 1),
                ]]);
            }

            if ($request->url() === $revokeUrl) {
                return Http::response([], 204);
            }

            return Http::response([], 404);
        });

        $secretName = 'nexus-ai/application-keys/projects/'.self::PROJECT_ID.'/applications/'.$keyId;
        ExternalApplicationSecret::query()->create([
            'project_id' => self::PROJECT_ID,
            'key_id' => $keyId,
            'application_name' => 'Partner integration',
            'secret_name' => $secretName,
            'secret_arn' => 'arn:aws:secretsmanager:us-east-1:123456789012:secret:managed-app-key',
        ]);

        $secretStore = \Mockery::mock(ApplicationSecretStore::class);
        $secretStore->shouldReceive('delete')
            ->once()
            ->with($secretName, [
                'ManagedBy' => 'NEXUS-AI-Laravel',
                'ProjectId' => self::PROJECT_ID,
                'Application' => 'Partner integration',
            ]);
        $this->app->instance(ApplicationSecretStore::class, $secretStore);

        $this->actingAs($admin);
        foreach (range(1, 2) as $attempt) {
            $this->delete(route('admin.projects.application-keys.revoke', [self::PROJECT_ID, $keyId]), [
                'confirmation' => 'Partner integration',
            ])->assertRedirect(route('admin.projects.show', self::PROJECT_ID));
        }

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => $request->url() === $revokeUrl
            && $request->method() === 'DELETE');
        $this->assertDatabaseMissing('external_application_secrets', ['key_id' => $keyId]);
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
            ->assertRedirect(route('admin.projects.show', self::PROJECT_ID))
            ->assertSessionHas('error', 'Имя приложения не совпало. Отзыв ключа отменён.');

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_non_admin_cannot_list_or_revoke_external_keys(): void
    {
        Http::fake();
        $keyId = '2a216802-1949-4e0b-9a3e-7bbfcb2fe982';

        foreach ([UserRole::User, UserRole::Viewer] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('admin.projects.show', self::PROJECT_ID))
                ->assertForbidden();

            $this->delete(route('admin.projects.application-keys.revoke', [self::PROJECT_ID, $keyId]), [
                'confirmation' => 'Partner integration',
            ])->assertForbidden();
        }

        Http::assertNothingSent();
    }

    public function test_end_date_must_be_later_than_start_date(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->post(route('admin.projects.application-keys.store', self::PROJECT_ID), [
                'name' => 'Partner integration',
                'rate_limit_per_min' => 60,
                'starts_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
                'expires_at' => now()->addDay()->format('Y-m-d\TH:i'),
            ])
            ->assertSessionHasErrors('expires_at');

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_admin_can_issue_application_keys_without_aws_delivery_configured(): void
    {
        Http::fake([
            'https://nexus.test/v1/projects' => Http::response([
                'projects' => [$this->remoteProject()],
            ]),
            'https://nexus.test/v1/experts/all' => Http::response(['experts' => [[
                'key' => 'expert_architect',
                'name' => 'Архитектор проекта',
                'system_prompt' => 'Ты эксперт по архитектуре проектов.',
                'sort_order' => 10,
                'is_enabled' => true,
                'is_system' => true,
            ]]]),
            'https://nexus.test/v1/projects/'.self::PROJECT_ID.'/keys' => Http::response(['keys' => []]),
        ]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->get(route('admin.projects.show', self::PROJECT_ID))
            ->assertOk()
            ->assertSee('name="allowed_experts[]"', false)
            ->assertSee('type="checkbox"', false)
            ->assertSee('name="starts_at"', false)
            ->assertSee('name="expires_at"', false)
            ->assertSee('Выпустить и показать ключ один раз');

        $this->assertTrue(Route::has('admin.projects.application-keys.store'));
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_configured_delivery_shows_application_key_form_with_known_expert_choices(): void
    {
        config(['services.application_key_delivery.enabled' => true]);
        Http::fake([
            'https://nexus.test/v1/projects' => Http::response([
                'projects' => [$this->remoteProject()],
            ]),
            'https://nexus.test/v1/experts/all' => Http::response(['experts' => [[
                'key' => 'expert_architect',
                'name' => 'Архитектор проекта',
                'system_prompt' => 'Ты эксперт по архитектуре проектов.',
                'sort_order' => 10,
                'icon' => '🏗',
                'is_enabled' => true,
                'is_system' => false,
            ]]]),
            'https://nexus.test/v1/projects/'.self::PROJECT_ID.'/keys' => Http::response(['keys' => []]),
        ]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->get(route('admin.projects.show', self::PROJECT_ID))
            ->assertOk()
            ->assertSee('name="allowed_experts[]"', false)
            ->assertSee('value="expert_architect"', false)
            ->assertSee('type="checkbox"', false)
            ->assertSee('name="rate_limit_per_min"', false)
            ->assertSee('name="starts_at"', false)
            ->assertSee('name="expires_at"', false)
            ->assertSee('Ключ сохранится в AWS Secrets Manager и будет показан один раз.')
            ->assertSee('Выпустить и показать ключ один раз')
            ->assertDontSee('type="submit" disabled', false);

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_admin_issues_external_key_delivers_it_to_aws_and_shows_it_only_once(): void
    {
        config(['services.application_key_delivery.enabled' => true]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $keyId = '5a216802-1949-4e0b-9a3e-7bbfcb2fe982';
        $rawSecret = 'nexus-one-time-secret-must-not-reach-the-browser';
        $secretArn = 'arn:aws:secretsmanager:us-east-1:123456789012:secret:nexus-ai/application-keys/projects/'.self::PROJECT_ID.'/applications/'.$keyId;

        $keysUrl = 'https://nexus.test/v1/projects/'.self::PROJECT_ID.'/keys';
        Http::fake(function (Request $request) use ($keysUrl, $keyId, $rawSecret) {
            if ($request->url() === 'https://nexus.test/v1/projects') {
                return Http::response(['projects' => [$this->remoteProject()]]);
            }

            if ($request->url() === 'https://nexus.test/v1/experts/all') {
                return Http::response(['experts' => [[
                    'key' => 'expert_architect',
                    'name' => 'Архитектор проекта',
                    'system_prompt' => 'Ты эксперт по архитектуре проектов.',
                    'sort_order' => 10,
                    'icon' => '🏗',
                    'is_enabled' => true,
                    'is_system' => false,
                ]]]);
            }

            if ($request->url() === $keysUrl && $request->method() === 'POST') {
                return Http::response(['id' => $keyId, 'key' => $rawSecret], 201);
            }

            if ($request->url() === $keysUrl && $request->method() === 'GET') {
                return Http::response(['keys' => [
                    $this->remoteKey($keyId, 'Partner integration', 'nexus_partner'),
                ]]);
            }

            return Http::response([], 404);
        });

        $secretStore = \Mockery::mock(ApplicationSecretStore::class);
        $secretStore->shouldReceive('store')
            ->once()
            ->with(
                'nexus-ai/application-keys/projects/'.self::PROJECT_ID.'/applications/'.$keyId,
                [
                    'api_key' => $rawSecret,
                    'project_id' => self::PROJECT_ID,
                    'key_id' => $keyId,
                    'application_name' => 'Partner integration',
                ],
                'NEXUS AI application API key. Retrieve using the application IAM role.',
                [
                    'ManagedBy' => 'NEXUS-AI-Laravel',
                    'ProjectId' => self::PROJECT_ID,
                    'Application' => 'Partner integration',
                ],
            )
            ->andReturn($secretArn);
        $this->app->instance(ApplicationSecretStore::class, $secretStore);

        $this->actingAs($admin)
            ->post(route('admin.projects.application-keys.store', self::PROJECT_ID), [
                'name' => 'Partner integration',
                'allowed_experts' => ['expert_architect'],
                'rate_limit_per_min' => 60,
                'starts_at' => null,
                'expires_at' => null,
            ])
            ->assertRedirect(route('admin.projects.show', self::PROJECT_ID))
            ->assertSessionHas('status', 'Ключ приложения выпущен и сохранён в AWS Secrets Manager. Он также показан здесь один раз для копирования.')
            ->assertSessionHas('issued_application_key', fn (string $encryptedKey): bool => $encryptedKey !== $rawSecret)
            ->assertSessionHas('issued_secret_reference', $secretArn)
            ->assertDontSee($rawSecret);

        $keyView = $this->get(route('admin.projects.show', self::PROJECT_ID))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee($rawSecret)
            ->assertSee('Скопируйте ключ и сохраните его в настройках секретов внешнего приложения сейчас.')
            ->assertSee($secretArn)
            ->assertSee('Копировать ключ');
        $keyView->assertSessionMissing('issued_application_key');

        $this->get(route('admin.projects.show', self::PROJECT_ID))
            ->assertOk()
            ->assertDontSee($rawSecret)
            ->assertDontSee('one-time-key-panel', false);

        $this->assertDatabaseHas('external_application_secrets', [
            'project_id' => self::PROJECT_ID,
            'key_id' => $keyId,
            'application_name' => 'Partner integration',
            'secret_arn' => $secretArn,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://nexus.test/v1/projects/'.self::PROJECT_ID.'/keys'
            && $request->hasHeader('X-API-Key', 'admin-key-must-stay-server-side')
            && $request->data()['name'] === 'Partner integration'
            && $request->data()['allowed_experts'] === ['expert_architect']
            && $request->data()['rate_limit_per_min'] === 60
            && $request->data()['starts_at'] === null
            && $request->data()['expires_at'] === null);
    }

    public function test_admin_can_issue_and_copy_a_key_once_without_aws_delivery(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $keyId = '7a216802-1949-4e0b-9a3e-7bbfcb2fe982';
        $rawSecret = 'manual-delivery-key-visible-once';
        $startsAt = now()->addDays(2)->startOfMinute();
        $expiresAt = now()->addDays(4)->startOfMinute();
        $expectedStartsAt = $startsAt->copy()->utc()->toIso8601String();
        $expectedExpiresAt = $expiresAt->copy()->utc()->toIso8601String();
        $keysUrl = 'https://nexus.test/v1/projects/'.self::PROJECT_ID.'/keys';

        Http::fake(function (Request $request) use ($keysUrl, $keyId, $rawSecret) {
            if ($request->url() === 'https://nexus.test/v1/projects') {
                return Http::response(['projects' => [$this->remoteProject()]]);
            }

            if ($request->url() === 'https://nexus.test/v1/experts/all') {
                return Http::response(['experts' => []]);
            }

            if ($request->url() === $keysUrl && $request->method() === 'POST') {
                return Http::response(['id' => $keyId, 'key' => $rawSecret], 201);
            }

            if ($request->url() === $keysUrl && $request->method() === 'GET') {
                return Http::response(['keys' => [
                    $this->remoteKey($keyId, 'Partner integration', 'nexus_manual'),
                ]]);
            }

            return Http::response([], 404);
        });

        $secretStore = \Mockery::mock(ApplicationSecretStore::class);
        $secretStore->shouldNotReceive('store');
        $this->app->instance(ApplicationSecretStore::class, $secretStore);

        $this->actingAs($admin)
            ->post(route('admin.projects.application-keys.store', self::PROJECT_ID), [
                'name' => 'Partner integration',
                'rate_limit_per_min' => 60,
                'starts_at' => $startsAt->format('Y-m-d\TH:i'),
                'expires_at' => $expiresAt->format('Y-m-d\TH:i'),
            ])
            ->assertRedirect(route('admin.projects.show', self::PROJECT_ID))
            ->assertSessionHas('issued_application_key', fn (string $encryptedKey): bool => $encryptedKey !== $rawSecret);

        $firstView = $this->get(route('admin.projects.show', self::PROJECT_ID))->assertOk()->assertSee($rawSecret);
        self::assertSame(1, substr_count((string) $firstView->getContent(), $rawSecret));
        $firstView->assertSee('AWS-доставка не включена');
        $firstView->assertSessionMissing('issued_application_key');

        $this->get(route('admin.projects.show', self::PROJECT_ID))
            ->assertOk()
            ->assertDontSee($rawSecret)
            ->assertDontSee('one-time-key-panel', false);

        $this->assertDatabaseMissing('external_application_secrets', ['key_id' => $keyId]);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === $keysUrl
            && $request->data()['starts_at'] === $expectedStartsAt
            && $request->data()['expires_at'] === $expectedExpiresAt);
    }

    public function test_failed_secret_delivery_revokes_the_new_nexus_key(): void
    {
        config(['services.application_key_delivery.enabled' => true]);
        $keyId = '6a216802-1949-4e0b-9a3e-7bbfcb2fe982';
        $keyUrl = 'https://nexus.test/v1/projects/'.self::PROJECT_ID.'/keys';

        Http::fake([
            'https://nexus.test/v1/experts/all' => Http::response(['experts' => []]),
            $keyUrl => Http::response(['id' => $keyId, 'key' => 'secret-not-for-logs'], 201),
            $keyUrl.'/'.$keyId => Http::response([], 204),
        ]);

        $secretStore = \Mockery::mock(ApplicationSecretStore::class);
        $secretStore->shouldReceive('store')->once()->andThrow(
            new SecretDeliveryException('AWS authorization denied.'),
        );
        $secretStore->shouldReceive('delete')
            ->once()
            ->with(
                'nexus-ai/application-keys/projects/'.self::PROJECT_ID.'/applications/'.$keyId,
                [
                    'ManagedBy' => 'NEXUS-AI-Laravel',
                    'ProjectId' => self::PROJECT_ID,
                    'Application' => 'Partner integration',
                ],
            );
        $this->app->instance(ApplicationSecretStore::class, $secretStore);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->post(route('admin.projects.application-keys.store', self::PROJECT_ID), [
                'name' => 'Partner integration',
                'rate_limit_per_min' => 60,
            ])
            ->assertRedirect(route('admin.projects.show', self::PROJECT_ID))
            ->assertSessionHas('error', 'AWS Secrets Manager не подтвердил сохранение секрета. Ключ NEXUS AI автоматически отозван. Очистка секрета проверена.');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === $keyUrl.'/'.$keyId
            && $request->hasHeader('X-API-Key', 'admin-key-must-stay-server-side'));
    }

    public function test_non_admin_cannot_issue_external_application_keys(): void
    {
        config(['services.application_key_delivery.enabled' => true]);
        Http::fake();

        foreach ([UserRole::User, UserRole::Viewer] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->post(route('admin.projects.application-keys.store', self::PROJECT_ID), [
                    'name' => 'Partner integration',
                    'rate_limit_per_min' => 60,
                ])
                ->assertForbidden();
        }

        Http::assertNothingSent();
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
        ?string $startsAt = null,
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
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'key' => 'full-key-must-not-be-rendered',
        ];
    }
}
