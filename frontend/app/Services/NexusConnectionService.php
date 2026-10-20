<?php

namespace App\Services;

use App\Exceptions\NexusApiException;

/**
 * Сервис проверки подключения к NEXUS AI.
 * Проверяет доступность API и возвращает статус подключения.
 */
class NexusConnectionService
{
    /**
     * Проверка подключения к NEXUS AI API.
     *
     * @return array{connected: bool, message: string, status: string}
     */
    public function checkConnection(): array
    {
        // Проверяем наличие конфигурации
        $url = config('services.nexus_ai.url');
        $adminKey = config('services.nexus_ai.admin_key');

        if (! is_string($url) || trim($url) === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return [
                'connected' => false,
                'message' => 'Адрес NEXUS AI не настроен в .env (NEXUS_AI_URL)',
                'status' => 'not_configured',
            ];
        }

        if (! is_string($adminKey) || trim($adminKey) === '') {
            return [
                'connected' => false,
                'message' => 'Admin API key не настроен в .env (NEXUS_AI_ADMIN_KEY)',
                'status' => 'no_key',
            ];
        }

        // Пробуем подключиться к API
        try {
            $httpClient = \Illuminate\Support\Facades\Http::baseUrl($url)
                ->acceptJson()
                ->withHeader('X-API-Key', $adminKey)
                ->connectTimeout(5)
                ->timeout(10);

            $response = $httpClient->get('/v1/experts/all');

            if ($response->successful()) {
                return [
                    'connected' => true,
                    'message' => 'NEXUS AI подключён и отвечает',
                    'status' => 'connected',
                ];
            }

            // API доступен, но вернул ошибку
            return [
                'connected' => false,
                'message' => 'NEXUS AI ответил с ошибкой HTTP ' . $response->status(),
                'status' => 'api_error',
            ];

        } catch (\Illuminate\Http\Client\ConnectionException $exception) {
            return [
                'connected' => false,
                'message' => 'Не удалось подключиться к NEXUS AI: ' . $exception->getMessage(),
                'status' => 'connection_failed',
            ];

        } catch (\Throwable $exception) {
            return [
                'connected' => false,
                'message' => 'Ошибка подключения: ' . $exception->getMessage(),
                'status' => 'error',
            ];
        }
    }
}
