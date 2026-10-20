<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LoraAdapterAdminTest extends TestCase
{
    use RefreshDatabase;

    private const ADAPTER_ID = '5e0f2f7a-9f5a-4c1e-8f19-2b7f4d6a0c11';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.nexus_ai.url' => 'https://nexus.test/api',
            'services.nexus_ai.admin_key' => 'admin-test-key',
        ]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    public function test_adapter_upload_is_proxied_server_side_with_release_binding(): void
    {
        Http::fake([
            'https://nexus.test/api/v1/admin/training/adapters/' => Http::response([
                ...$this->adapterPayload(),
                'status' => 'candidate',
                'reused' => false,
            ], 201),
        ]);

        $this->post(route('admin.training-adapters.store'), [
            'expert' => 'system_architect',
            'base_model' => 'Qwen3-4B',
            'dataset_release_id' => '2f4f7d1c-1e3b-4a52-9c77-6a2f0c9a1b3d',
            'notes' => 'r=16, lr=1e-4',
            'file' => UploadedFile::fake()->createWithContent('adapter.gguf', 'gguf-bytes'),
        ])->assertRedirect(route('admin.training-datasets', ['expert' => 'system_architect']))
            ->assertSessionHas('status', 'lora_adapter_uploaded');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://nexus.test/api/v1/admin/training/adapters/'
            && $request->hasHeader('X-API-Key', 'admin-test-key')
            && str_contains($request->body(), 'name="expert"')
            && str_contains($request->body(), 'system_architect')
            && str_contains($request->body(), 'name="dataset_release_id"')
            && str_contains($request->body(), '2f4f7d1c-1e3b-4a52-9c77-6a2f0c9a1b3d')
            && str_contains($request->body(), 'filename="adapter.gguf"'));
    }

    public function test_adapter_upload_of_same_file_shows_reused_notice(): void
    {
        Http::fake([
            'https://nexus.test/api/v1/admin/training/adapters/' => Http::response([
                ...$this->adapterPayload(),
                'reused' => true,
            ], 201),
        ]);

        $this->post(route('admin.training-adapters.store'), [
            'expert' => 'system_architect',
            'base_model' => 'Qwen3-4B',
            'file' => UploadedFile::fake()->createWithContent('adapter.gguf', 'gguf-bytes'),
        ])->assertRedirect(route('admin.training-datasets', ['expert' => 'system_architect']))
            ->assertSessionHas('status', 'lora_adapter_reused');
    }

    public function test_adapter_upload_rejects_non_gguf_file_locally(): void
    {
        Http::fake();

        $this->from(route('admin.training-datasets'))
            ->post(route('admin.training-adapters.store'), [
                'expert' => 'system_architect',
                'base_model' => 'Qwen3-4B',
                'file' => UploadedFile::fake()->createWithContent('model.bin', 'bytes'),
            ])
            ->assertRedirect(route('admin.training-datasets'))
            ->assertSessionHasErrors('file');

        Http::assertNothingSent();
    }

    public function test_adapter_upload_rejects_missing_base_model_locally(): void
    {
        Http::fake();

        $this->from(route('admin.training-datasets'))
            ->post(route('admin.training-adapters.store'), [
                'expert' => 'system_architect',
                'file' => UploadedFile::fake()->createWithContent('adapter.gguf', 'gguf-bytes'),
            ])
            ->assertSessionHasErrors('base_model');

        Http::assertNothingSent();
    }

    public function test_adapter_upload_conflict_is_surfaced_to_the_admin(): void
    {
        Http::fake([
            'https://nexus.test/api/v1/admin/training/adapters/' => Http::response([
                'detail' => 'Снимок датасета относится к другому эксперту.',
            ], 422),
        ]);

        $this->post(route('admin.training-adapters.store'), [
            'expert' => 'system_architect',
            'base_model' => 'Qwen3-4B',
            'file' => UploadedFile::fake()->createWithContent('adapter.gguf', 'gguf-bytes'),
        ])->assertSessionHasErrors('adapter');
    }

    public function test_adapter_publish_and_rollback_are_proxied_with_server_side_key(): void
    {
        Http::fake([
            'https://nexus.test/api/v1/admin/training/adapters/'.self::ADAPTER_ID.'/publish' => Http::response([
                ...$this->adapterPayload(),
                'status' => 'published',
            ]),
            'https://nexus.test/api/v1/admin/training/adapters/'.self::ADAPTER_ID.'/rollback' => Http::response([
                ...$this->adapterPayload(),
                'status' => 'rolled_back',
            ]),
        ]);

        $this->post(route('admin.training-adapters.publish', ['adapterId' => self::ADAPTER_ID]))
            ->assertRedirect(route('admin.training-datasets', ['expert' => 'system_architect']))
            ->assertSessionHas('status', 'lora_adapter_published')
            ->assertSessionHas('lora_adapter_version', 3);

        $this->post(route('admin.training-adapters.rollback', ['adapterId' => self::ADAPTER_ID]))
            ->assertRedirect(route('admin.training-datasets', ['expert' => 'system_architect']))
            ->assertSessionHas('status', 'lora_adapter_rolled_back');

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/publish')
            && $request->method() === 'POST'
            && $request->hasHeader('X-API-Key', 'admin-test-key'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/rollback')
            && $request->method() === 'POST'
            && $request->hasHeader('X-API-Key', 'admin-test-key'));
    }

    public function test_adapter_publish_conflict_is_surfaced_to_the_admin(): void
    {
        Http::fake([
            'https://nexus.test/api/v1/admin/training/adapters/'.self::ADAPTER_ID.'/publish' => Http::response([
                'detail' => 'Адаптер уже опубликован.',
            ], 409),
        ]);

        $this->post(route('admin.training-adapters.publish', ['adapterId' => self::ADAPTER_ID]))
            ->assertSessionHasErrors('adapter');
    }

    public function test_adapter_delete_is_proxied_with_server_side_key(): void
    {
        $adapterUrl = 'https://nexus.test/api/v1/admin/training/adapters/'.self::ADAPTER_ID;

        Http::fake([
            $adapterUrl => Http::response('', 204),
        ]);

        $this->delete(route('admin.training-adapters.destroy', ['adapterId' => self::ADAPTER_ID]))
            ->assertRedirect(route('admin.training-datasets'))
            ->assertSessionHas('status', 'lora_adapter_deleted');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === $adapterUrl
            && $request->hasHeader('X-API-Key', 'admin-test-key'));
    }

    public function test_adapter_delete_conflict_is_surfaced_to_the_admin(): void
    {
        $adapterUrl = 'https://nexus.test/api/v1/admin/training/adapters/'.self::ADAPTER_ID;

        Http::fake([
            $adapterUrl => Http::response([
                'detail' => 'Нельзя удалить опубликованный адаптер — сначала выполните откат.',
            ], 409),
        ]);

        $this->delete(route('admin.training-adapters.destroy', ['adapterId' => self::ADAPTER_ID]))
            ->assertSessionHasErrors('adapter');
    }

    public function test_only_admin_can_manage_lora_adapters(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->create(['role' => UserRole::User]));

        $this->post(route('admin.training-adapters.store'), [
            'expert' => 'system_architect',
            'base_model' => 'Qwen3-4B',
            'file' => UploadedFile::fake()->createWithContent('adapter.gguf', 'gguf-bytes'),
        ])->assertForbidden();
        $this->post(route('admin.training-adapters.publish', ['adapterId' => self::ADAPTER_ID]))
            ->assertForbidden();
        $this->post(route('admin.training-adapters.rollback', ['adapterId' => self::ADAPTER_ID]))
            ->assertForbidden();
        $this->delete(route('admin.training-adapters.destroy', ['adapterId' => self::ADAPTER_ID]))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    private function adapterPayload(): array
    {
        return [
            'id' => self::ADAPTER_ID,
            'expert' => 'system_architect',
            'version' => 3,
            'status' => 'published',
            'filename' => 'system_architect-abc123.gguf',
            'sha256' => str_repeat('d', 64),
            'size_bytes' => 6291456,
            'base_model' => 'Qwen3-4B',
            'dataset_release_id' => '2f4f7d1c-1e3b-4a52-9c77-6a2f0c9a1b3d',
            'created_by' => 'admin',
            'created_at' => '2026-10-18T10:00:00+00:00',
            'published_by' => 'admin',
            'published_at' => '2026-10-18T11:00:00+00:00',
            'rolled_back_by' => null,
            'rolled_back_at' => null,
        ];
    }
}
