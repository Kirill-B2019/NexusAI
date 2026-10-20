<?php

namespace App\Http\Controllers;

use App\Exceptions\NexusApiException;
use App\Services\NexusApiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LoraAdapterController extends Controller
{
    public function store(Request $request, NexusApiClient $nexus): RedirectResponse
    {
        $request->validate([
            'expert' => ['required', 'string', 'max:100'],
            'base_model' => ['required', 'string', 'max:100'],
            'file' => ['required', 'file', 'max:262144', 'extensions:gguf'],
            'dataset_release_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $file = $request->file('file');
        $expert = $request->string('expert')->toString();
        $releaseId = $request->filled('dataset_release_id')
            ? $request->string('dataset_release_id')->toString()
            : null;

        try {
            $adapter = $nexus->uploadLoraAdapter(
                $expert,
                $request->string('base_model')->toString(),
                $file->getPathname(),
                $file->getClientOriginalName(),
                $releaseId,
                $request->filled('notes') ? $request->string('notes')->toString() : null,
            );

            return redirect()
                ->route('admin.training-datasets', ['expert' => $expert])
                ->with('status', ($adapter['reused'] ?? false)
                    ? 'lora_adapter_reused'
                    : 'lora_adapter_uploaded');
        } catch (NexusApiException $exception) {
            Log::warning('NEXUS AI could not upload the LoRA adapter.', [
                'status' => $exception->statusCode,
                'configuration_error' => $exception->configurationError,
            ]);

            return back()->withInput()->withErrors([
                'adapter' => match ($exception->statusCode) {
                    404 => 'Эксперт или снимок датасета не найден в NEXUS AI.',
                    413 => 'Файл превышает ограничение 256 МБ.',
                    422 => 'Проверьте расширение .gguf и принадлежность снимка датасета выбранному эксперту.',
                    default => 'Не удалось загрузить адаптер в NEXUS AI.',
                },
            ]);
        }
    }

    public function publish(string $adapterId, NexusApiClient $nexus): RedirectResponse
    {
        try {
            $adapter = $nexus->publishLoraAdapter($adapterId);

            return redirect()
                ->route('admin.training-datasets', ['expert' => $adapter['expert'] ?? null])
                ->with('status', 'lora_adapter_published')
                ->with('lora_adapter_version', $adapter['version'] ?? null);
        } catch (NexusApiException $exception) {
            Log::warning('NEXUS AI could not publish the LoRA adapter.', [
                'status' => $exception->statusCode,
            ]);

            return back()->withErrors([
                'adapter' => match ($exception->statusCode) {
                    404 => 'Адаптер не найден — обновите страницу.',
                    409 => 'Адаптер уже опубликован или находится в архиве.',
                    default => 'Не удалось опубликовать адаптер в NEXUS AI.',
                },
            ]);
        }
    }

    public function rollback(string $adapterId, NexusApiClient $nexus): RedirectResponse
    {
        try {
            $adapter = $nexus->rollbackLoraAdapter($adapterId);

            return redirect()
                ->route('admin.training-datasets', ['expert' => $adapter['expert'] ?? null])
                ->with('status', 'lora_adapter_rolled_back')
                ->with('lora_adapter_version', $adapter['version'] ?? null);
        } catch (NexusApiException $exception) {
            Log::warning('NEXUS AI could not roll back the LoRA adapter.', [
                'status' => $exception->statusCode,
            ]);

            return back()->withErrors([
                'adapter' => match ($exception->statusCode) {
                    404 => 'Адаптер не найден — обновите страницу.',
                    409 => 'Откатить можно только опубликованный адаптер.',
                    default => 'Не удалось выполнить откат адаптера в NEXUS AI.',
                },
            ]);
        }
    }

    public function destroy(string $adapterId, NexusApiClient $nexus): RedirectResponse
    {
        try {
            $nexus->deleteLoraAdapter($adapterId);

            return redirect()
                ->route('admin.training-datasets')
                ->with('status', 'lora_adapter_deleted');
        } catch (NexusApiException $exception) {
            Log::warning('NEXUS AI could not delete the LoRA adapter.', [
                'status' => $exception->statusCode,
            ]);

            return back()->withErrors([
                'adapter' => match ($exception->statusCode) {
                    404 => 'Адаптер не найден — обновите страницу.',
                    409 => 'Нельзя удалить опубликованный адаптер — сначала выполните откат.',
                    default => 'Не удалось удалить адаптер в NEXUS AI.',
                },
            ]);
        }
    }
}
