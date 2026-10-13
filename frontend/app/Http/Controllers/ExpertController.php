<?php

namespace App\Http\Controllers;

use App\Exceptions\NexusApiException;
use App\Services\NexusApiClient;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

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
}
