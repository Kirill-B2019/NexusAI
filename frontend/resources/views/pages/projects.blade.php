<x-app-layout title="Проекты">
    <section class="welcome-row page-title-row">
        <div>
            <div class="eyebrow"><span class="eyebrow-line"></span> РАБОЧАЯ ОБЛАСТЬ</div>
            <h1>Ваши <span>проекты</span></h1>
            <p class="welcome-copy">Здесь отображаются только проекты, назначенные вашей учётной записи.</p>
        </div>
        @can('manage-users')
            <a class="button button-primary" href="{{ route('admin.projects') }}">Назначить проект</a>
        @endcan
    </section>

    @if ($projects->isEmpty())
        <section class="panel empty-state">
            <span class="empty-state-icon" aria-hidden="true">◎</span>
            <strong>Проекты ещё не назначены</strong>
            <p>Обратитесь к администратору, чтобы получить доступ к проекту NEXUS AI.</p>
            @can('manage-users')
                <a class="text-link" href="{{ route('admin.projects') }}">Назначить первый проект <span aria-hidden="true">→</span></a>
            @endcan
        </section>
    @else
        <section class="overview-grid" aria-label="Назначенные проекты">
            @foreach ($projects as $project)
                <article class="overview-card">
                    <div class="overview-card-top">
                        <span class="card-icon card-icon-lime" aria-hidden="true">N</span>
                        <span class="card-overline">ПРОЕКТ NEXUS AI</span>
                    </div>
                    <strong class="card-value">{{ $project->name }}</strong>
                    <span class="card-caption">Проект доступен согласно вашей роли.</span>
                    <a class="text-link" href="{{ route('chat', ['project' => $project->id]) }}">Открыть чат <span aria-hidden="true">→</span></a>
                </article>
            @endforeach
        </section>
    @endif
</x-app-layout>
