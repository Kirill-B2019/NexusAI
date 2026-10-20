<?php

namespace App\Http\Controllers;

use App\Exceptions\NexusApiException;
use App\Services\NexusApiClient;
use App\Services\NexusSystemHealth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Контроллер состояния системы.
 * Отображает статус всех сервисов NEXUS AI.
 */
class HealthController extends Controller
{
    /**
     * Отображение состояния системы.
     *
     * @param  NexusApiClient  $nexus  Клиент для вызовов NEXUS API
     * @return View Вид с карточками состояния сервисов
     */
    public function index(NexusApiClient $nexus): View
    {
        try {
            /** @var NexusSystemHealth $health */
            $health = $nexus->getSystemHealth();
            $error = null;
        } catch (NexusApiException $exception) {
            $health = null;

            if ($exception->configurationError) {
                $error = 'Подключение NEXUS AI не настроено. Проверьте NEXUS_AI_URL и ключ администратора на сервере.';
            } elseif ($exception->getMessage() === 'Could not connect to NEXUS AI.') {
                $error = 'Невозможно подключиться к NEXUS AI. Проверьте, что сервис запущен и NEXUS_AI_URL настроен правильно.';
            } elseif ($exception->getMessage() === 'NEXUS AI returned an unexpected experts response.') {
                $error = 'NEXUS AI вернул ответ в неожиданном формате. Проверьте логи Laravel для деталей.';
            } else {
                $error = 'Не удалось получить состояние NEXUS AI. Проверьте настройки API и логи Laravel.';
            }

            Log::warning('NEXUS AI system health request failed.', [
                'status' => $exception->statusCode,
                'configuration_error' => $exception->configurationError,
            ]);
        }

        $view = view('pages.admin.health', [
            'health' => $health,
            'error' => $error,
        ]);

        // Запрещаем кэширование страницы
        return $view->withHeaders([
            'Cache-Control' => 'no-cache, no-store, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }
}
