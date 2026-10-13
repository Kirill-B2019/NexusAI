<?php

namespace App\Http\Controllers;

use App\Exceptions\NexusApiException;
use App\Http\Requests\AssignProjectRequest;
use App\Http\Requests\DeleteExternalProjectKeyRequest;
use App\Http\Requests\DeleteProjectRequest;
use App\Http\Requests\LinkProjectRequest;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Project;
use App\Models\User;
use App\Services\NexusApiClient;
use App\Services\NexusProject;
use App\Services\NexusProjectApiKey;
use App\Services\NexusProjectKey;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class AdminProjectController extends Controller
{
    public function index(NexusApiClient $nexus): Response
    {
        return $this->renderIndex($nexus);
    }

    private function renderIndex(NexusApiClient $nexus): Response
    {
        try {
            $nexusProjects = $nexus->listProjects();
            $error = null;
        } catch (NexusApiException $exception) {
            Log::warning('NEXUS AI project list request failed.', ['status' => $exception->statusCode]);
            $nexusProjects = [];
            $error = $this->apiErrorMessage($exception, 'загрузить список проектов');
        }

        $assignments = Project::query()->with('owner')->get()->keyBy('nexus_project_id');
        $keysByProject = [];
        $keyErrors = [];

        foreach ($nexusProjects as $remoteProject) {
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

        return response()->view('pages.admin.projects', [
            'nexusProjects' => $nexusProjects,
            'assignments' => $assignments,
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email', 'role']),
            'error' => $error,
            'keysByProject' => $keysByProject,
            'keyErrors' => $keyErrors,
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

            return redirect()->route('admin.projects')->with(
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

        return redirect()->route('admin.projects')->with('status', 'Проект создан и назначен. Project API key сохранён в зашифрованном виде.');
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
            return $this->apiFailure($exception, 'обновить проект');
        }

        Project::query()->where('nexus_project_id', $remote->id)->update([
            'name' => $remote->name,
            'description' => $remote->description,
            'metadata' => json_encode($remote->metadata, JSON_THROW_ON_ERROR),
        ]);

        return redirect()->route('admin.projects')->with('status', 'Параметры проекта обновлены в NEXUS AI.');
    }

    public function link(
        LinkProjectRequest $request,
        string $nexusProjectId,
        NexusApiClient $nexus,
    ): RedirectResponse {
        try {
            $remote = $nexus->getProject($nexusProjectId);
        } catch (NexusApiException $exception) {
            return $this->apiFailure($exception, 'открыть проект для назначения');
        }

        $project = Project::query()->where('nexus_project_id', $remote->id)->first();

        if ($project?->owner_user_id !== null) {
            return redirect()->route('admin.projects')->with('error', 'Проект уже назначен. Измените назначение в его карточке.');
        }

        $key = null;
        if (! filled($project?->api_key)) {
            try {
                $key = $nexus->createProjectKey($remote->id, $this->keyName($remote));
            } catch (NexusApiException $exception) {
                return $this->apiFailure($exception, 'выпустить ключ для проекта');
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

        return redirect()->route('admin.projects')->with('status', 'Проект назначен пользователю; его ключ хранится только в зашифрованном виде.');
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
            return $this->apiFailure($exception, 'проверить назначение проекта');
        }

        $project->fill([
            'owner_user_id' => $request->filled('owner_user_id') ? $request->integer('owner_user_id') : null,
            'name' => $remote->name,
            'description' => $remote->description,
            'metadata' => $remote->metadata,
        ])->save();

        return redirect()->route('admin.projects')->with('status', 'Назначение проекта обновлено.');
    }

    public function rotateKey(string $nexusProjectId, NexusApiClient $nexus): RedirectResponse
    {
        $project = Project::query()->where('nexus_project_id', $nexusProjectId)->firstOrFail();

        if (filled($project->pending_revoke_key_id)) {
            return redirect()->route('admin.projects')->with('error', 'Сначала повторите отзыв старого ключа, ожидающего завершения.');
        }

        try {
            $remote = $nexus->getProject($nexusProjectId);
            $oldKeyId = $this->resolveCurrentKeyId($project, $nexus);
            $newKey = $nexus->createProjectKey($remote->id, $this->keyName($remote));
        } catch (NexusApiException $exception) {
            return $this->apiFailure($exception, 'обновить project API key');
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
            return redirect()->route('admin.projects')->with('status', 'Новый project API key выпущен и сохранён в зашифрованном виде.');
        }

        try {
            $nexus->revokeProjectKey($remote->id, $oldKeyId);
        } catch (NexusApiException $exception) {
            Log::warning('Project API key rotation completed but old key revocation failed.', [
                'status' => $exception->statusCode,
                'project_id' => $remote->id,
            ]);

            return redirect()->route('admin.projects')->with(
                'error',
                'Новый ключ сохранён и уже используется. Отзыв предыдущего ключа не завершился; повторите отзыв старого ключа в карточке проекта.',
            );
        }

        $project->forceFill(['pending_revoke_key_id' => null])->save();

        return redirect()->route('admin.projects')->with('status', 'Ключ заменён; старый ключ отозван.');
    }

    public function retryPendingRevoke(string $nexusProjectId, NexusApiClient $nexus): RedirectResponse
    {
        $project = Project::query()->where('nexus_project_id', $nexusProjectId)->firstOrFail();
        $pendingKeyId = $project->pending_revoke_key_id;

        if (! is_string($pendingKeyId) || $pendingKeyId === '') {
            return redirect()->route('admin.projects')->with('error', 'Нет ожидающего отзыва ключа.');
        }

        try {
            $nexus->revokeProjectKey($nexusProjectId, $pendingKeyId);
        } catch (NexusApiException $exception) {
            return $this->apiFailure($exception, 'отозвать предыдущий ключ');
        }

        $project->forceFill(['pending_revoke_key_id' => null])->save();

        return redirect()->route('admin.projects')->with('status', 'Предыдущий project API key отозван.');
    }

    public function revokeKey(string $nexusProjectId, NexusApiClient $nexus): RedirectResponse
    {
        $project = Project::query()->where('nexus_project_id', $nexusProjectId)->firstOrFail();

        if (filled($project->pending_revoke_key_id)) {
            return redirect()->route('admin.projects')->with('error', 'Сначала завершите ожидающий отзыв предыдущего ключа.');
        }

        try {
            $keyId = $this->resolveCurrentKeyId($project, $nexus);
            if ($keyId === null) {
                return redirect()->route('admin.projects')->with('error', 'Для этого проекта нет активного назначенного ключа.');
            }

            $nexus->revokeProjectKey($nexusProjectId, $keyId);
        } catch (NexusApiException $exception) {
            return $this->apiFailure($exception, 'отозвать project API key');
        }

        $project->forceFill(['api_key' => null, 'nexus_api_key_id' => null])->save();

        return redirect()->route('admin.projects')->with('status', 'Project API key отозван; проект остаётся назначенным, но чат недоступен до выпуска нового ключа.');
    }

    public function destroy(
        DeleteProjectRequest $request,
        string $nexusProjectId,
        NexusApiClient $nexus,
    ): RedirectResponse {
        try {
            $remote = $nexus->getProject($nexusProjectId);
        } catch (NexusApiException $exception) {
            return $this->apiFailure($exception, 'проверить проект перед удалением');
        }

        if (! hash_equals($remote->name, $request->string('confirmation')->toString())) {
            return redirect()->route('admin.projects')->with(
                'error',
                'Имя проекта не совпало. Удаление отменено; проект в NEXUS AI не изменён.',
            );
        }

        try {
            $nexus->deleteProject($remote->id);
        } catch (NexusApiException $exception) {
            return $this->apiFailure($exception, 'удалить проект');
        }

        Project::query()->where('nexus_project_id', $remote->id)->delete();

        return redirect()->route('admin.projects')->with('status', 'Проект и связанные с ним данные удалены из NEXUS AI.');
    }

    public function revokeExternalKey(
        DeleteExternalProjectKeyRequest $request,
        string $nexusProjectId,
        string $keyId,
        NexusApiClient $nexus,
    ): RedirectResponse {
        try {
            $keys = $nexus->listProjectKeys($nexusProjectId);
        } catch (NexusApiException $exception) {
            return $this->apiFailure($exception, 'проверить ключ внешнего приложения');
        }

        $key = collect($keys)->firstWhere('id', $keyId);
        $mapping = Project::query()->where('nexus_project_id', $nexusProjectId)->first();

        if (
            ! $key instanceof NexusProjectApiKey
            || in_array($keyId, [$mapping?->nexus_api_key_id, $mapping?->pending_revoke_key_id], true)
        ) {
            return redirect()->route('admin.projects')->with('error', 'Этот ключ не найден или управляется интеграцией Laravel.');
        }

        if (! hash_equals($key->name, $request->string('confirmation')->toString())) {
            return redirect()->route('admin.projects')->with('error', 'Имя приложения не совпало. Отзыв ключа отменён.');
        }

        try {
            $nexus->revokeProjectKey($nexusProjectId, $keyId);
        } catch (NexusApiException $exception) {
            return $this->apiFailure($exception, 'отозвать ключ внешнего приложения');
        }

        return redirect()->route('admin.projects')->with('status', 'Ключ внешнего приложения отозван.');
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

    private function apiFailure(NexusApiException $exception, string $action): RedirectResponse
    {
        Log::warning('NEXUS AI project operation failed.', ['status' => $exception->statusCode]);

        return redirect()->route('admin.projects')->with(
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
