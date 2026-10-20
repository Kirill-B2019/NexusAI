<x-app-layout title="Состояние системы">
    <section class="welcome-row page-title-row">
        <div>
            <div class="eyebrow"><span class="eyebrow-line"></span> СИСТЕМА · ЗДОРОВЬЕ</div>
            <h1>Состояние <span>системы</span></h1>
            <p class="welcome-copy">Мониторинг API, модели, эмбеддингов, базы данных и векторного хранилища NEXUS AI.</p>
        </div>
    </section>

    @if ($error)
        <div class="admin-note project-alert-error" role="alert">
            <span aria-hidden="true">!</span>
            <div>
                <p>{{ $error }}</p>
                @if (app()->environment('local'))
                    <small class="health-error-hint">Для диагностики проверьте логи Laravel.</small>
                @endif
            </div>
        </div>
    @endif

    @if ($health)
        @php
            $overallClass = match ($health->overallStatus) {
                'healthy' => 'health-healthy',
                'degraded' => 'health-degraded',
                'down' => 'health-down',
                default => 'health-degraded',
            };
            $overallLabel = match ($health->overallStatus) {
                'healthy' => 'Система здорова',
                'degraded' => 'Система деградирована',
                'down' => 'Система недоступна',
                default => 'Статус системы неизвестен',
            };
            $unhealthyCount = count($health->unhealthyServices());
        @endphp

        <section class="panel health-summary" aria-labelledby="health-summary-title">
            <div class="health-summary-main">
                <div class="health-summary-status">
                    <span
                        class="health-status-dot {{ $health->isHealthy() ? 'healthy' : ($health->overallStatus === 'down' ? 'down' : 'degraded') }}"
                        aria-hidden="true"
                    ></span>
                    <div>
                        <span class="card-overline">ОБЩИЙ СТАТУС</span>
                        <h2 id="health-summary-title" class="health-summary-title {{ $overallClass }}">{{ $overallLabel }}</h2>
                    </div>
                </div>

                <div class="health-summary-meta">
                    @if ($unhealthyCount > 0)
                        <span class="card-status health-status-label health-down">
                            <i class="health-status-dot down" aria-hidden="true"></i>
                            @if ($unhealthyCount === 1)
                                1 сервис недоступен
                            @elseif ($unhealthyCount < 5)
                                {{ $unhealthyCount }} сервиса недоступны
                            @else
                                {{ $unhealthyCount }} сервисов недоступны
                            @endif
                        </span>
                    @endif
                    @if ($health->updatedAt)
                        <span class="health-updated">
                            Обновлено {{ \Carbon\Carbon::parse($health->updatedAt)->format('d.m.Y H:i:s') }}
                        </span>
                    @endif
                    <a class="button button-secondary health-refresh" href="{{ route('admin.health') }}">Обновить</a>
                </div>
            </div>
        </section>

        <section class="section-heading health-section-heading">
            <div>
                <span class="eyebrow">NEXUS AI · МОНИТОРИНГ</span>
                <h2>Сервисы системы</h2>
            </div>
            <span class="directory-count">{{ count($health->services) }} сервисов</span>
        </section>

        @if ($health->services === [])
            <section class="panel empty-state">
                <span class="empty-state-icon" aria-hidden="true">◎</span>
                <strong>Список сервисов пуст</strong>
                <p>NEXUS AI не вернул сведения о состоянии компонентов.</p>
            </section>
        @else
            <div class="overview-grid health-grid" aria-label="Состояние сервисов NEXUS AI">
                @foreach ($health->services as $serviceName => $service)
                    @php
                        $serviceClass = match ($service->status) {
                            'healthy' => 'health-healthy',
                            'degraded' => 'health-degraded',
                            'down' => 'health-down',
                            default => 'health-degraded',
                        };
                        $serviceIcon = match ($serviceName) {
                            'nexus', 'api' => '⬡',
                            'embeddings', 'embedding' => '◎',
                            'qdrant' => '◈',
                            'postgres', 'postgresql', 'database' => '⛁',
                            'llama', 'llama.cpp', 'inference', 'model' => '◉',
                            default => '●',
                        };
                    @endphp
                    <article class="overview-card health-card">
                        <div class="overview-card-top">
                            <span class="card-icon card-icon-lime health-service-icon" aria-hidden="true">{{ $serviceIcon }}</span>
                            <span class="card-status health-status-label {{ $serviceClass }}">
                                <i class="health-status-dot {{ $service->isHealthy() ? 'healthy' : ($service->isDown() ? 'down' : 'degraded') }}" aria-hidden="true"></i>
                                {{ $service->statusLabel() }}
                            </span>
                        </div>
                        <h3 class="card-value health-service-name">{{ ucfirst($serviceName) }}</h3>
                        @if ($service->latencyMs !== null)
                            <p class="card-caption health-service-detail">Задержка: {{ $service->latencyMs }} мс</p>
                        @endif
                        @if ($service->message)
                            <p class="health-service-message">{{ $service->message }}</p>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    @else
        <section class="panel empty-state">
            <span class="empty-state-icon" aria-hidden="true">◎</span>
            <strong>Состояние недоступно</strong>
            <p>Не удалось загрузить статус сервисов NEXUS AI.</p>
        </section>
    @endif
</x-app-layout>
