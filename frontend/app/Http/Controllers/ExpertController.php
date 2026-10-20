<?php

namespace App\Http\Controllers;

use App\Exceptions\NexusApiException;
use App\Http\Requests\StoreExpertRequest;
use App\Services\NexusApiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Контроллер управления экспертами.
 * Отображает каталог экспертов и управляет созданием, обновлением, удалением.
 */
class ExpertController extends Controller
{
    public function index(NexusApiClient $nexus): View
    {
        try {
            $experts = $nexus->listExperts();
            $error = null;
        } catch (NexusApiException $exception) {
            Log::warning('NEXUS AI experts request failed.', [
                'status' => $exception->statusCode,
                'configuration_error' => $exception->configurationError,
            ]);

            $experts = [];
            $error = $exception->configurationError
                ? 'Подключение NEXUS AI не настроено. Укажите адрес API и admin API key в локальном frontend/.env.'
                : match ($exception->statusCode) {
                    401, 403 => 'NEXUS AI не принял серверный admin API key. Проверьте его права и настройки.',
                    default => 'Не удалось загрузить экспертов NEXUS AI. Проверьте доступность API и повторите попытку.',
                };
        }

        return view('pages.experts', compact('experts', 'error'));
    }

    /**
     * Создание нового эксперта.
     *
     * @param  StoreExpertRequest  $request  Запрос с валидацией данных эксперта
     * @param  NexusApiClient  $nexus  Клиент для вызовов NEXUS API
     * @return RedirectResponse Перенаправление на каталог экспертов
     */
    public function store(StoreExpertRequest $request, NexusApiClient $nexus): RedirectResponse
    {
        try {
            $attributes = $request->safe()->only([
                'key',
                'name',
                'description',
                'system_prompt',
                'keywords',
                'icon',
                'color',
                'sort_order',
                'is_enabled',
            ]);
            $nexus->createExpert($attributes);

            return redirect()->route('admin.experts')->with('status', 'expert_created');
        } catch (NexusApiException $exception) {
            return back()->withErrors(['create' => $exception->getMessage()]);
        }
    }

    /**
     * Обновление существующего эксперта.
     *
     * @param  StoreExpertRequest  $request  Запрос с валидацией данных эксперта
     * @param  string  $expertKey  Ключ эксперта
     * @param  NexusApiClient  $nexus  Клиент для вызовов NEXUS API
     * @return RedirectResponse Перенаправление на каталог экспертов
     */
    public function update(StoreExpertRequest $request, string $expertKey, NexusApiClient $nexus): RedirectResponse
    {
        try {
            $changes = $request->safe()->only([
                'name',
                'description',
                'system_prompt',
                'keywords',
                'icon',
                'color',
                'sort_order',
                'is_enabled',
            ]);
            $nexus->updateExpert($expertKey, $changes);

            return redirect()->route('admin.experts')->with('status', 'expert_updated');
        } catch (NexusApiException $exception) {
            return back()->withErrors(['update' => $exception->getMessage()]);
        }
    }

    /**
     * Удаление эксперта.
     *
     * @param  string  $expertKey  Ключ эксперта
     * @param  NexusApiClient  $nexus  Клиент для вызовов NEXUS API
     * @return RedirectResponse Перенаправление на каталог экспертов
     */
    public function destroy(string $expertKey, NexusApiClient $nexus): RedirectResponse
    {
        try {
            $nexus->deleteExpert($expertKey);

            return redirect()->route('admin.experts')->with('status', 'expert_deleted');
        } catch (NexusApiException $exception) {
            return back()->withErrors(['delete' => $exception->getMessage()]);
        }
    }
}
