<?php

namespace App\Http\Controllers;

use App\Exceptions\NexusApiException;
use App\Exceptions\SecretDeliveryException;
use App\Http\Requests\AssignProjectRequest;
use App\Http\Requests\DeleteExternalProjectKeyRequest;
use App\Http\Requests\DeleteProjectRequest;
use App\Http\Requests\LinkProjectRequest;
use App\Http\Requests\StoreExternalProjectKeyRequest;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\ExternalApplicationSecret;
use App\Models\Project;
use App\Models\User;
use App\Services\ApplicationSecretStore;
use App\Services\NexusApiClient;
use App\Services\NexusProject;
use App\Services\NexusProjectApiKey;
use App\Services\NexusProjectKey;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

class AdminProjectController extends Controller
{
    public function index(NexusApiClient $nexus): Response
    {
        return $this->renderIndex($nexus);
    }

    public function show(string $nexusProjectId, NexusApiClient $nexus): Response
    {
        return $this->renderIndex($nexus, $nexusProjectId);
    }

    private function renderIndex(NexusApiClient $nexus, ?string $selectedProjectId = null): Response
    {
        $encryptedIssuedApplicationKey = session()->pull('issued_application_key');
        $issuedApplicationKey = is_string($encryptedIssuedApplicationKey)
            ? Crypt::decryptString($encryptedIssuedApplicationKey)
            : null;
        $issuedApplicationName = $issuedApplicationKey === null
            ? null
            : session()->pull('issued_application_name');
        $issuedProjectId = $issuedApplicationKey === null
            ? null
            : session()->pull('issued_project_id');
        $issuedSecretReference = $issuedApplicationKey === null
            ? null
            : session()->pull('issued_secret_reference');

        try {
            $nexusProjects = $nexus->listProjects();
            $error = null;
        } catch (NexusApiException $exception) {
            Log::warning('NEXUS AI project list request failed.', ['status' => $exception->statusCode]);
            $nexusProjects = [];
            $error = $this->apiErrorMessage($exception, 'загрузить список проектов');
        }

        if ($selectedProjectId !== null) {
            $nexusProjects = array_values(array_filter(
                $nexusProjects,
                static fn (NexusProject $project): bool => $project->id === $selectedProjectId,
            ));
            abort_if($error === null && $nexusProjects === [], 404);
        }
        $selectedProject = $selectedProjectId !== null
            ? ($nexusProjects[0] ?? null)
            : null;
        $assignments = Project::query()->with('owner')->get()->keyBy('nexus_project_id');
        $applicationSecrets = $selectedProject === null
            ? collect()
            : ExternalApplicationSecret::query()->where('project_id', $selectedProject->id)->get()->keyBy('key_id');
        $keysByProject = [];
        $keyErrors = [];
        $expertNames = [];
        $expertCatalogError = false;
        $keyDeliveryEnabled = (bool) config('services.application_key_delivery.enabled');

        foreach ($nexusProjects as $remoteProject) {
            if ($selectedProject === null) {
                continue;
            }

            try {
                $keys = $nexus->listProjectKeys($remoteProject->id);
                $mapping = $assignments->get($remoteProject->id);
                if ($mapping !== null && filled($mapping->api_key) && ! filled($mapping->nexus_api_key_id)) {
                    $keyErrors[$remoteProject->id] = 'У ключа интеграции Laravel отсутствует ID. Список скрыт, чтобы не допустить отзыва ключа чата как внешнего.';
                    $keysByProject[$remoteProject->id] = [];

                    continue;
                }

                $internalKeyIds = array_filter([
                    $mapping?->nexus_api_key_id,
                    $mapping?->pending_revoke_key_id,
                ]);
                $keysByProject[$remoteProject->id] = array_values(array_filter(
                    $keys,
                    static fn (NexusProjectApiKey $key): bool => ! in_array($key->id, $internalKeyIds, true),
                ));
            } catch (NexusApiException $exception) {
                Log::warning('NEXUS AI project key list request failed.', [
                    'status' => $exception->statusCode,
                    'project_id' => $remoteProject->id,
                ]);
                $keyErrors[$remoteProject->id] = $this->apiErrorMessage($exception, 'загрузить ключи приложений');
            }
        }

        if ($selectedProject !== null) {
            try {
                foreach ($nexus->listExperts() as $expert) {
                    $expertNames[$expert->key] = $expert->name;
                }
            } catch (NexusApiException $exception) {
                Log::warning('NEXUS AI expert names could not be loaded for application key permissions.', [
                    'status' => $exception->statusCode,
                ]);
                $expertCatalogError = true;
            }
        }

        return response()->view('pages.admin.projects', [
            'nexusProjects' => $nexusProjects,
            'selectedProject' => $selectedProject,
            'assignments' => $assignments,
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email', 'role']),
            'error' => $error,
            'keysByProject' => $keysByProject,
            'keyErrors' => $keyErrors,
            'expertNames' => $expertNames,
            'expertCatalogError' => $expertCatalogError,
            'keyDeliveryEnabled' => $keyDeliveryEnabled,
            'applicationSecrets' => $applicationSecrets,
            'issuedApplicationKey' => $issuedApplicationKey,
            'issuedApplicationName' => $issuedApplicationName,
            'issuedProjectId' => $issuedProjectId,
            'issuedSecretReference' => $issuedSecretReference,
        ])->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }

    public function store(StoreProjectRequest $request, NexusApiClient $nexus): RedirectResponse
    {
        try {
            $remote = $nexus->createProject(
                $request->string('name')->toString(),
                $request->input('description') ?: null,
                $request->metadataObject(),
            );
        } catch (NexusApiException $exception) {
            return $this->apiFailure($exception, 'создать проект');
        }

        try {
            $key = $nexus->createProjectKey($remote->id, $this->keyName($remote));
        } catch (NexusApiException $exception) {
            Log::warning('NEXUS project was created without an issued Laravel key.', [
                'status' => $exception->statusCode,
                'project_id' => $remote->id,
            ]);

            return $this->projectRedirect($remote->id)->with(
                'error',
                'Проект создан в NEXUS AI, но ключ не выпущен. Он отображается в списке; свяжите его с пользователем, чтобы повторить выпуск.',
            );
        }

        try {
            Project::query()->create([
                'owner_user_id' => $request->integer('owner_user_id'),
                'name' => $remote->name,
                'description' => $remote->description,
                'metadata' => $remote->metadata,
                'nexus_project_id' => $remote->id,
                'nexus_api_key_id' => $key->id,
                'api_key' => $key->revealForServerStorage(),
            ]);
        } catch (Throwable $exception) {
            $this->revokeUnstoredKey($nexus, $remote->id, $key);
            throw $exception;
        }

        return $this->projectRedirect($remote->id)->with('status', 'Проект создан и назначен. Project API key сохранён в зашифрованном виде.');
    }

    public function update(
        UpdateProjectRequest $request,
        string $nexusProjectId,
        NexusApiClient $nexus,
    ): RedirectResponse {
        $changes = [
            'name' => $request->string('name')->toString(),
            'description' => $request->input('description') ?: null,
            'metadata' => (object) $request->metadataObject(),
        ];

        try {
            $remote = $nexus->updateProject($nexusProjectId, $changes);
        } catch (NexusApiException $exception) {
            return $this->apiFailure($exception, 'обновить проект', $nexusProjectId);
        }

        Project::query()->where('nexus_project_id', $remote->id)->update([
            'name' => $remote->name,
            'description' => $remote->description,
            'metadata' => json_encode($remote->metadata, JSON_THROW_ON_ERROR),
        ]);

        return $this->projectRedirect($remote->id)->with('status', 'Параметры проекта обновлены в NEXUS AI.');
    }

    public function link(
        LinkProjectRequest $request,
        string $nexusProjectId,
        NexusApiClient $nexus,
    ): RedirectResponse {
        try {
            $remote = $nexus->getProject($nexusProjectId);
        } catch (NexusApiException $exception) {
            return $this->apiFailure($exception, 'открыть проект для назначения', $nexusProjectId);
        }

        $project = Project::query()->where('nexus_project_id', $remote->id)->first();

        if ($project?->owner_user_id !== null) {
            return $this->projectRedirect($nexusProjectId)->with('error', 'Проект уже назначен.');
        }

        $key = null;
        if (! filled($project?->api_key)) {
            try {
                $key = $nexus->createProjectKey($remote->id, $this->keyName($remote));
            } catch (NexusApiException $exception) {
                return $this->apiFailure($exception, 'выпустить ключ для проекта', $nexusProjectId);
            }
        }

        try {
            $attributes = [
                'owner_user_id' => $request->integer('owner_user_id'),
                'name' => $remote->name,
                'description' => $remote->description,
                'metadata' => $remote->metadata,
            ];
            if ($key instanceof NexusProjectKey) {
                $attributes['api_key'] = $key->revealForServerStorage();
                $attributes['nexus_api_key_id'] = $key->id;
            }

            if ($project === null) {
                $attributes['nexus_project_id'] = $remote->id;
                Project::query()->create($attributes);
            } else {
                $project->fill($attributes)->save();
            }
        } catch (Throwable $exception) {
            if ($key instanceof NexusProjectKey) {
                $this->revokeUnstoredKey($nexus, $remote->id, $key);
            }
            throw $exception;
        }

        return $this->projectRedirect($remote->id)->with('status', 'Проект назначен пользователю; его ключ хранится только в зашифрованном виде.');
    }

    public function assign(
        AssignProjectRequest $request,
        string $nexusProjectId,
        NexusApiClient $nexus,
    ): RedirectResponse {
        $project = Project::query()->where('nexus_project_id', $nexusProjectId)->firstOrFail();

        try {
            $remote = $nexus->getProject($nexusProjectId);
        } catch (NexusApiException $exception) {
            return $this->apiFailure($exception, 'проверить назначение проекта', $nexusProjectId);
        }

        $project->fill([
            'owner_user_id' => $request->filled('owner_user_id') ? $request->integer('owner_user_id') : null,
            'name' => $remote->name,
            'description' => $remote->description,
            'metadata' => $remote->metadata,
        ])->save();

        return $this->projectRedirect($remote->id)->with('status', 'Назначение проекта обновлено.');
    }

    public function rotateKey(string $nexusProjectId, NexusApiClient $nexus): RedirectResponse
    {
        $project = Project::query()->where('nexus_project_id', $nexusProjectId)->firstOrFail();

        if (filled($project->pending_revoke_key_id)) {
            return $this->projectRedirect($nexusProjectId)->with('error', 'Сначала повторите отзыв старого ключа, ожидающего завершения.');
        }

        try {
            $remote = $nexus->getProject($nexusProjectId);
            $oldKeyId = $this->resolveCurrentKeyId($project, $nexus);
            $newKey = $nexus->createProjectKey($remote->id, $this->keyName($remote));
        } catch (NexusApiException $exception) {
            return $this->apiFailure($exception, 'обновить project API key', $nexusProjectId);
        }

        $project->forceFill([
            'api_key' => $newKey->revealForServerStorage(),
            'nexus_api_key_id' => $newKey->id,
            'pending_revoke_key_id' => $oldKeyId,
        ]);

        try {
            $project->save();
        } catch (Throwable $exception) {
            $this->revokeUnstoredKey($nexus, $remote->id, $newKey);
            throw $exception;
        }

        if ($oldKeyId === null) {
            return $this->projectRedirect($remote->id)->with('status', 'Новый project API key выпущен и сохранён в зашифрованном виде.');
        }

        try {
            $nexus->revokeProjectKey($remote->id, $oldKeyId);
        } catch (NexusApiException $exception) {
            Log::warning('Project API key rotation completed but old key revocation failed.', [
                'status' => $exception->statusCode,
                'project_id' => $remote->id,
            ]);

            return $this->projectRedirect($remote->id)->with(
                'error',
                'Новый ключ сохранён и уже используется. Отзыв предыдущего ключа не завершился; повторите отзыв старого ключа в карточке проекта.',
            );
        }

        $project->forceFill(['pending_revoke_key_id' => null])->save();

        return $this->projectRedirect($remote->id)->with('status', 'Ключ заменён; старый ключ отозван.');
    }

    public function retryPendingRevoke(string $nexusProjectId, NexusApiClient $nexus): RedirectResponse
    {
        $project = Project::query()->where('nexus_project_id', $nexusProjectId)->firstOrFail();
        $pendingKeyId = $project->pending_revoke_key_id;

        if (! is_string($pendingKeyId) || $pendingKeyId === '') {
            return $this->projectRedirect($nexusProjectId)->with('error', 'Нет ожидающего отзыва ключа.');
        }

        try {
            $nexus->revokeProjectKey($nexusProjectId, $pendingKeyId);
        } catch (NexusApiException $exception) {
            return $this->apiFailure($exception, 'отозвать предыдущий ключ', $nexusProjectId);
        }

        $project->forceFill(['pending_revoke_key_id' => null])->save();

        return $this->projectRedirect($nexusProjectId)->with('status', 'Предыдущий project API key отозван.');
    }

    public function revokeKey(string $nexusProjectId, NexusApiClient $nexus): RedirectResponse
    {
        $project = Project::query()->where('nexus_project_id', $nexusProjectId)->firstOrFail();

        if (filled($project->pending_revoke_key_id)) {
            return $this->projectRedirect($nexusProjectId)->with('error', 'Сначала завершите ожидающий отзыв предыдущего ключа.');
        }

        try {
            $keyId = $this->resolveCurrentKeyId($project, $nexus);
            if ($keyId === null) {
                return $this->projectRedirect($nexusProjectId)->with('error', 'Для этого проекта нет активного назначенного ключа.');
            }

            $nexus->revokeProjectKey($nexusProjectId, $keyId);
        } catch (NexusApiException $exception) {
            return $this->apiFailure($exception, 'отозвать project API key', $nexusProjectId);
        }

        $project->forceFill(['api_key' => null, 'nexus_api_key_id' => null])->save();

        return $this->projectRedirect($nexusProjectId)->with('status', 'Project API key отозван; проект остаётся назначенным, но чат недоступен до выпуска нового ключа.');
    }

    public function destroy(
        DeleteProjectRequest $request,
        string $nexusProjectId,
        NexusApiClient $nexus,
    ): RedirectResponse {
        try {
            $remote = $nexus->getProject($nexusProjectId);
        } catch (NexusApiException $exception) {
            return $this->apiFailure($exception, 'проверить проект перед удалением', $nexusProjectId);
        }

        if (! hash_equals($remote->name, $request->string('confirmation')->toString())) {
            return $this->projectRedirect($remote->id)->with(
                'error',
                'Имя проекта не совпало. Удаление отменено; проект в NEXUS AI не изменён.',
            );
        }

        if (ExternalApplicationSecret::query()->where('project_id', $remote->id)->exists()) {
            return $this->projectRedirect($remote->id)->with(
                'error',
                'Сначала отзовите управляемые ключи приложений в карточке проекта, чтобы очистить их секреты AWS.',
            );
        }

        try {
            $nexus->deleteProject($remote->id);
        } catch (NexusApiException $exception) {
            return $this->apiFailure($exception, 'удалить проект', $remote->id);
        }

        Project::query()->where('nexus_project_id', $remote->id)->delete();

        return redirect()->route('admin.projects')->with('status', 'Проект и связанные с ним данные удалены из NEXUS AI.');
    }

    public function revokeExternalKey(
        DeleteExternalProjectKeyRequest $request,
        string $nexusProjectId,
        string $keyId,
        NexusApiClient $nexus,
        ApplicationSecretStore $secretStore,
    ): RedirectResponse {
        try {
            $keys = $nexus->listProjectKeys($nexusProjectId);
        } catch (NexusApiException $exception) {
            return $this->apiFailure($exception, 'проверить ключ внешнего приложения', $nexusProjectId);
        }

        $key = collect($keys)->firstWhere('id', $keyId);
        $managedSecret = ExternalApplicationSecret::query()
            ->where('project_id', $nexusProjectId)
            ->where('key_id', $keyId)
            ->first();
        $mapping = Project::query()->where('nexus_project_id', $nexusProjectId)->first();

        if (
            (! $key instanceof NexusProjectApiKey && $managedSecret === null)
            || in_array($keyId, [$mapping?->nexus_api_key_id, $mapping?->pending_revoke_key_id], true)
        ) {
            return $this->projectRedirect($nexusProjectId)->with('error', 'Этот ключ не найден или управляется интеграцией Laravel.');
        }

        $applicationName = $key instanceof NexusProjectApiKey
            ? $key->name
            : $managedSecret->application_name;
        if (! hash_equals($applicationName, $request->string('confirmation')->toString())) {
            return $this->projectRedirect($nexusProjectId)->with('error', 'Имя приложения не совпало. Отзыв ключа отменён.');
        }

        if ($key instanceof NexusProjectApiKey && $key->active) {
            try {
                $nexus->revokeProjectKey($nexusProjectId, $keyId);
            } catch (NexusApiException $exception) {
                return $this->apiFailure($exception, 'отозвать ключ внешнего приложения', $nexusProjectId);
            }
        }

        if ($managedSecret === null) {
            return $this->projectRedirect($nexusProjectId)->with('status', 'Ключ внешнего приложения отозван.');
        }

        $expectedTags = [
            'ManagedBy' => 'NEXUS-AI-Laravel',
            'ProjectId' => $nexusProjectId,
            'Application' => $managedSecret->application_name,
        ];

        try {
            $secretStore->delete($managedSecret->secret_name, $expectedTags);
        } catch (SecretDeliveryException $exception) {
            Log::error('NEXUS application key was revoked but its AWS secret could not be scheduled for deletion.', [
                'project_id' => $nexusProjectId,
                'key_id' => $keyId,
                'exception' => $exception::class,
            ]);

            return $this->projectRedirect($nexusProjectId)
                ->with('error', 'Ключ NEXUS AI отозван, но очистка секрета AWS не подтверждена. Повторите отзыв после проверки доступа AWS или удалите секрет вручную: '.$managedSecret->secret_name);
        }

        try {
            $managedSecret->delete();
        } catch (Throwable $exception) {
            Log::critical('AWS application secret was scheduled for deletion but local lifecycle metadata could not be removed.', [
                'project_id' => $nexusProjectId,
                'key_id' => $keyId,
                'exception' => $exception::class,
            ]);

            return $this->projectRedirect($nexusProjectId)->with(
                'error',
                'Ключ отозван, секрет AWS поставлен на удаление, но запись учёта не очищена. Повторите отзыв для завершения операции.',
            );
        }

        return $this->projectRedirect($nexusProjectId)->with(
            'status',
            'Ключ внешнего приложения отозван; секрет AWS недоступен для чтения и поставлен на удаление согласно recovery policy.',
        );
    }

    public function storeExternalKey(
        StoreExternalProjectKeyRequest $request,
        string $nexusProjectId,
        NexusApiClient $nexus,
        ApplicationSecretStore $secretStore,
    ): RedirectResponse {
        $allowedExperts = $request->input('allowed_experts');
        if ($allowedExperts !== null) {
            try {
                $knownExperts = array_map(
                    static fn ($expert): string => $expert->key,
                    $nexus->listExperts(),
                );
            } catch (NexusApiException $exception) {
                return $this->apiFailure($exception, 'проверить разрешённых советников', $nexusProjectId);
            }

            if (array_diff($allowedExperts, $knownExperts) !== []) {
                return $this->projectRedirect($nexusProjectId)->with(
                    'error',
                    'Выбран неизвестный советник. Обновите страницу и повторите выпуск ключа.',
                );
            }
        }

        $startsAt = $request->input('starts_at');
        $startsAt = is_string($startsAt) && $startsAt !== ''
            ? Carbon::parse($startsAt, config('app.timezone'))->utc()->toIso8601String()
            : null;
        $expiresAt = $request->input('expires_at');
        $expiresAt = is_string($expiresAt) && $expiresAt !== ''
            ? Carbon::parse($expiresAt, config('app.timezone'))->utc()->toIso8601String()
            : null;

        try {
            $key = $nexus->createProjectKey(
                $nexusProjectId,
                $request->string('name')->toString(),
                $allowedExperts,
                $request->integer('rate_limit_per_min'),
                $expiresAt,
                $startsAt,
            );
        } catch (NexusApiException $exception) {
            return $this->apiFailure($exception, 'выпустить ключ внешнего приложения', $nexusProjectId);
        }

        $secretName = trim((string) config('services.application_key_delivery.secret_prefix'), '/')
            .'/projects/'.$nexusProjectId.'/applications/'.$key->id;

        $secretArn = null;
        if (config('services.application_key_delivery.enabled')) {
            try {
                $secretArn = $secretStore->store(
                    $secretName,
                    [
                        'api_key' => $key->revealForServerStorage(),
                        'project_id' => $nexusProjectId,
                        'key_id' => $key->id,
                        'application_name' => $request->string('name')->toString(),
                    ],
                    'NEXUS AI application API key. Retrieve using the application IAM role.',
                    [
                        'ManagedBy' => 'NEXUS-AI-Laravel',
                        'ProjectId' => $nexusProjectId,
                        'Application' => $request->string('name')->toString(),
                    ],
                );
            } catch (SecretDeliveryException $exception) {
                Log::error('NEXUS application key could not be delivered to AWS Secrets Manager.', [
                    'project_id' => $nexusProjectId,
                    'key_id' => $key->id,
                    'exception' => $exception::class,
                ]);

                $keyRevoked = true;
                try {
                    $nexus->revokeProjectKey($nexusProjectId, $key->id);
                } catch (NexusApiException $revokeException) {
                    $keyRevoked = false;
                    Log::critical('Application key delivery failed and the issued NEXUS key could not be revoked.', [
                        'project_id' => $nexusProjectId,
                        'key_id' => $key->id,
                        'status' => $revokeException->statusCode,
                    ]);
                }

                $secretDeleted = true;
                try {
                    $secretStore->delete($secretName, [
                        'ManagedBy' => 'NEXUS-AI-Laravel',
                        'ProjectId' => $nexusProjectId,
                        'Application' => $request->string('name')->toString(),
                    ]);
                } catch (SecretDeliveryException $cleanupException) {
                    $secretDeleted = false;
                    Log::critical('Application key delivery failed and possible AWS secret cleanup could not be confirmed.', [
                        'project_id' => $nexusProjectId,
                        'key_id' => $key->id,
                        'exception' => $cleanupException::class,
                    ]);
                }

                $message = 'AWS Secrets Manager не подтвердил сохранение секрета.';
                if ($keyRevoked) {
                    $message .= ' Ключ NEXUS AI автоматически отозван.';
                } else {
                    $message .= ' Ключ NEXUS AI не удалось автоматически отозвать — проверьте его и отзовите вручную.';
                }
                if (! $secretDeleted) {
                    $message .= ' Очистка возможного секрета AWS не подтверждена; проверьте и удалите его вручную: '.$secretName;
                } else {
                    $message .= ' Очистка секрета проверена.';
                }

                return $this->projectRedirect($nexusProjectId)->with('error', $message);
            }
        }

        if ($secretArn !== null) {
            try {
                ExternalApplicationSecret::query()->create([
                    'project_id' => $nexusProjectId,
                    'key_id' => $key->id,
                    'application_name' => $request->string('name')->toString(),
                    'secret_name' => $secretName,
                    'secret_arn' => $secretArn,
                ]);
            } catch (Throwable $exception) {
                Log::critical('NEXUS application secret was delivered but its lifecycle metadata could not be saved.', [
                    'project_id' => $nexusProjectId,
                    'key_id' => $key->id,
                    'exception' => $exception::class,
                ]);

                $cleanupConfirmed = true;
                try {
                    $secretStore->delete($secretName, [
                        'ManagedBy' => 'NEXUS-AI-Laravel',
                        'ProjectId' => $nexusProjectId,
                        'Application' => $request->string('name')->toString(),
                    ]);
                } catch (SecretDeliveryException $cleanupException) {
                    $cleanupConfirmed = false;
                    Log::critical('Application secret cleanup failed after local lifecycle metadata persistence failed.', [
                        'project_id' => $nexusProjectId,
                        'key_id' => $key->id,
                        'exception' => $cleanupException::class,
                    ]);
                }

                $keyRevoked = true;
                try {
                    $nexus->revokeProjectKey($nexusProjectId, $key->id);
                } catch (NexusApiException $revokeException) {
                    $keyRevoked = false;
                    Log::critical('Application key could not be revoked after lifecycle metadata persistence failed.', [
                        'project_id' => $nexusProjectId,
                        'key_id' => $key->id,
                        'status' => $revokeException->statusCode,
                    ]);
                }

                $message = 'Не удалось сохранить метаданные жизненного цикла ключа.';
                $message .= $keyRevoked
                    ? ' Ключ NEXUS AI отозван.'
                    : ' Ключ NEXUS AI не удалось автоматически отозвать; проверьте его вручную.';
                $message .= $cleanupConfirmed
                    ? ' Секрет AWS очищен.'
                    : ' Секрет AWS не удалось очистить автоматически; удалите его вручную: '.$secretName;

                return $this->projectRedirect($nexusProjectId)->with('error', $message);
            }
        }

        $status = $secretArn === null
            ? 'Ключ приложения выпущен. Скопируйте его сейчас и сохраните в менеджере секретов приложения — повторно показать ключ нельзя.'
            : 'Ключ приложения выпущен и сохранён в AWS Secrets Manager. Он также показан здесь один раз для копирования.';

        $redirect = $this->projectRedirect($nexusProjectId)
            ->with('status', $status)
            ->with('issued_application_key', Crypt::encryptString($key->revealForServerStorage()))
            ->with('issued_application_name', $request->string('name')->toString())
            ->with('issued_project_id', $nexusProjectId);

        if ($secretArn !== null) {
            $redirect->with('issued_secret_reference', $secretArn);
        }

        return $redirect;
    }

    private function resolveCurrentKeyId(Project $project, NexusApiClient $nexus): ?string
    {
        if (is_string($project->nexus_api_key_id) && $project->nexus_api_key_id !== '') {
            return $project->nexus_api_key_id;
        }

        if (! filled($project->api_key)) {
            return null;
        }

        try {
            $prefix = substr($project->api_key, 0, 12);
        } catch (DecryptException $exception) {
            throw new NexusApiException('The stored project API key could not be decrypted.', previous: $exception);
        }

        $matches = array_values(array_filter(
            $nexus->listProjectKeys($project->nexus_project_id),
            static fn ($key): bool => $key->active && hash_equals($key->keyPrefix, $prefix),
        ));

        if (count($matches) !== 1) {
            throw new NexusApiException('The existing project key could not be safely matched for revocation.');
        }

        $project->forceFill(['nexus_api_key_id' => $matches[0]->id])->save();

        return $matches[0]->id;
    }

    private function revokeUnstoredKey(NexusApiClient $nexus, string $projectId, NexusProjectKey $key): void
    {
        try {
            $nexus->revokeProjectKey($projectId, $key->id);
        } catch (NexusApiException $exception) {
            Log::critical('A newly issued NEXUS project key could not be revoked after local persistence failed.', [
                'status' => $exception->statusCode,
                'project_id' => $projectId,
                'key_id' => $key->id,
            ]);
        }
    }

    private function keyName(NexusProject $project): string
    {
        return mb_substr($project->name.' Laravel integration', 0, 100);
    }

    private function projectRedirect(string $nexusProjectId): RedirectResponse
    {
        return redirect()->route('admin.projects.show', $nexusProjectId);
    }

    private function apiFailure(
        NexusApiException $exception,
        string $action,
        ?string $nexusProjectId = null,
    ): RedirectResponse {
        Log::warning('NEXUS AI project operation failed.', ['status' => $exception->statusCode]);

        $redirect = $nexusProjectId === null
            ? redirect()->route('admin.projects')
            : $this->projectRedirect($nexusProjectId);

        return $redirect->with(
            'error',
            $this->apiErrorMessage($exception, $action),
        );
    }

    private function apiErrorMessage(NexusApiException $exception, string $action): string
    {
        if ($exception->configurationError) {
            return 'NEXUS AI не настроен. Проверьте NEXUS_AI_URL и NEXUS_AI_ADMIN_KEY на сервере.';
        }

        return match ($exception->statusCode) {
            401, 403 => 'NEXUS AI отклонил серверный admin API key. Проверьте его права.',
            404 => 'Проект или ключ не найден в NEXUS AI; обновите список и повторите действие.',
            409 => 'Такой проект уже существует в NEXUS AI.',
            default => 'Не удалось '.$action.'. Проверьте доступность NEXUS AI и повторите попытку.',
        };
    }
}
