<?php

namespace App\Http\Controllers;

use App\Services\NexusConnectionService;
use Illuminate\View\View;

/**
 * Контроллер dashboard.
 * Отображает обзорную страницу с реальным статусом подключения NEXUS AI.
 */
class DashboardController extends Controller
{
    /**
     * Отображение dashboard с проверкой подключения NEXUS AI.
     *
     * @param  NexusConnectionService  $connection  Сервис проверки подключения
     * @return View Вид dashboard с данными о подключении
     */
    public function __invoke(NexusConnectionService $connection): View
    {
        $connectionStatus = $connection->checkConnection();

        return view('dashboard.index', [
            'nexusConnection' => $connectionStatus,
        ]);
    }
}
