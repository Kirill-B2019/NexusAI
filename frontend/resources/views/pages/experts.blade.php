<x-app-layout title="Эксперты">
    <section class="welcome-row page-title-row">
        <div>
            <div class="eyebrow"><span class="eyebrow-line"></span> NEXUS AI</div>
            <h1>Каталог <span>экспертов</span></h1>
            <p class="welcome-copy">Список экспертов загружается через защищённое серверное подключение к API.</p>
        </div>
        @if (! $error)
            <span class="section-caption">{{ count($experts) }} ЭКСПЕРТОВ</span>
        @endif
    </section>

    @if ($error)
        <div class="admin-note" role="alert"><span aria-hidden="true">!</span><p>{{ $error }}</p></div>
    @elseif ($experts === [])
        <section class="panel empty-state">
            <span class="empty-state-icon" aria-hidden="true">◎</span>
            <strong>Эксперты пока недоступны</strong>
            <p>Проверьте, что сервис NEXUS AI запущен и в нём включены эксперты.</p>
        </section>
    @else
        <section class="overview-grid" aria-label="Эксперты NEXUS AI">
            @foreach ($experts as $expert)
                <article class="overview-card">
                    <div class="overview-card-top">
                        <span class="card-icon card-icon-lime" aria-hidden="true">
                            {{ $expert->icon ?: '✳' }}
                        </span>
                        <span class="card-status {{ $expert->enabled ? '' : 'card-status-muted' }}">
                            <i></i>{{ $expert->enabled ? 'ВКЛЮЧЁН' : 'ОТКЛЮЧЁН' }}
                        </span>
                    </div>
                    <strong class="card-value">{{ $expert->name }}</strong>
                    <span class="card-caption">{{ $expert->description ?: 'Описание для этого эксперта пока не добавлено.' }}</span>
                    <div class="role-chip">Ключ: <span>{{ $expert->key }}</span></div>
                    @if ($expert->keywords !== [])
                        <p class="card-inline-note">
                            <span></span>
                            {{ implode(' · ', array_slice($expert->keywords, 0, 6)) }}
                        </p>
                    @endif
                </article>
            @endforeach
        </section>
    @endif
</x-app-layout>
