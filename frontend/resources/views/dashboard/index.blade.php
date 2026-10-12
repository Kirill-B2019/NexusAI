<x-app-layout title="Обзор">
    @if (session('status'))
        <div class="notice notice-success" role="status">{{ session('status') }}</div>
    @endif

    <section class="welcome-row">
        <div>
            <div class="eyebrow"><span class="eyebrow-line"></span> ВАША ЗАЩИЩЁННАЯ AI-РАБОЧАЯ ОБЛАСТЬ</div>
            <h1>Рады видеть, <span>{{ \Illuminate\Support\Str::of(auth()->user()->name)->explode(' ')->first() }}</span></h1>
            <p class="welcome-copy">Рабочая область готова. Подключите сервис NEXUS AI, когда будете готовы перейти к следующему шагу.</p>
        </div>
        <div class="welcome-date">
            <span class="date-icon" aria-hidden="true">✳</span>
            <span><strong>{{ now()->locale(app()->getLocale())->isoFormat('D MMMM, dddd') }}</strong><small>ОБЗОР РАБОЧЕЙ ОБЛАСТИ</small></span>
        </div>
    </section>

    <section class="hero-panel">
        <div class="hero-grid"></div>
        <div class="hero-content">
            <span class="hero-kicker"><i></i> NEXUS AI · ОСНОВА</span>
            <h2>Идеи становятся<br><span>интеллектом.</span></h2>
            <p>Защищённая рабочая область готова. Следующий шаг — подключение к API NEXUS AI.</p>
            <span class="hero-note"><span class="hero-note-mark">N</span> Интеграция с API пока не настроена</span>
        </div>
        <div class="hero-orbit" aria-hidden="true">
            <div class="orbit orbit-one"></div>
            <div class="orbit orbit-two"></div>
            <div class="orbit-core"><span>N</span><i></i></div>
            <span class="orbit-node orbit-node-one"></span>
            <span class="orbit-node orbit-node-two"></span>
            <span class="orbit-node orbit-node-three"></span>
        </div>
        <div class="hero-index" aria-hidden="true">01 <span>/</span> 04</div>
    </section>

    <section class="section-heading">
        <div>
            <span class="eyebrow">ВАШ ДОСТУП</span>
            <h2>Рабочая область</h2>
        </div>
        <span class="section-caption">БЕЗОПАСНОСТЬ В ОСНОВЕ</span>
    </section>

    <section class="overview-grid">
        <article class="overview-card">
            <div class="overview-card-top">
                <span class="card-icon card-icon-lime">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4.5 6v5.2c0 4.5 3.2 8.2 7.5 9.8 4.3-1.6 7.5-5.3 7.5-9.8V6z"/><path d="m8.5 12 2.2 2.2 4.8-4.8"/></svg>
                </span>
                <span class="card-overline">СТАТУС УЧЁТНОЙ ЗАПИСИ</span>
                <span class="card-status"><i></i> АКТИВНА</span>
            </div>
            <strong class="card-value">Защищена</strong>
            <span class="card-caption">Вход выполнен с защищённой сессией</span>
            <div class="card-rule"><span style="width: 100%"></span></div>
        </article>

        <article class="overview-card">
            <div class="overview-card-top">
                <span class="card-icon card-icon-violet">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 20a8 8 0 0 1 16 0M17 4.5a3.5 3.5 0 0 1 0 6.8M20 15a6 6 0 0 1 2 4.5"/></svg>
                </span>
                <span class="card-overline">РОЛЬ ДОСТУПА</span>
                <span class="card-chevron">↗</span>
            </div>
            <strong class="card-value">{{ auth()->user()->role->label() }}</strong>
            <span class="card-caption">Разрешения зависят от назначенной роли</span>
            <div class="role-chip"><span>{{ auth()->user()->role->label() }}</span> · ваша роль</div>
        </article>

        <article class="overview-card">
            <div class="overview-card-top">
                <span class="card-icon card-icon-blue">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 5.5h15v13h-15zM8 9h8M8 12h5M8 15h3"/><path d="m16.5 15.5 1.5 1.5 3-3"/></svg>
                </span>
                <span class="card-overline">ПОДКЛЮЧЕНИЕ NEXUS AI</span>
                <span class="card-status card-status-muted"><i></i> ОЖИДАЕТ</span>
            </div>
            <strong class="card-value">Не подключено</strong>
            <span class="card-caption">Внешние сервисы пока не использовались</span>
            <div class="card-inline-note"><span></span> Ключи API хранятся только на сервере</div>
        </article>
    </section>

    <section class="lower-grid">
        <article class="panel getting-started">
            <div class="panel-heading">
                <div><span class="eyebrow">ПЛАН РАЗВИТИЯ</span><h2>Развиваем проект последовательно</h2></div>
                <span class="step-counter">01 <span>—</span> 04</span>
            </div>
            <div class="step-list">
                <div class="step-item is-complete">
                    <span class="step-marker"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="m3.5 8 3 3 6-6"/></svg></span>
                    <span class="step-copy"><strong>Защищённая учётная запись</strong><small>Авторизация через сессию настроена</small></span>
                    <span class="step-state">ГОТОВО</span>
                </div>
                <div class="step-item is-complete">
                    <span class="step-marker"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="m3.5 8 3 3 6-6"/></svg></span>
                    <span class="step-copy"><strong>Ролевой доступ</strong><small>Администратор, пользователь и чтение</small></span>
                    <span class="step-state">ГОТОВО</span>
                </div>
                <div class="step-item">
                    <span class="step-marker"><span>03</span></span>
                    <span class="step-copy"><strong>Подключить NEXUS AI</strong><small>Настроить серверное соединение с API</small></span>
                    <span class="step-state step-state-next">СЛЕДУЮЩИЙ ШАГ</span>
                </div>
                <div class="step-item step-item-last">
                    <span class="step-marker"><span>04</span></span>
                    <span class="step-copy"><strong>Запустить работу с проектами</strong><small>Документы, эксперты и диалоги</small></span>
                    <span class="step-state">В ПЛАНАХ</span>
                </div>
            </div>
        </article>

        <article class="panel access-panel">
            <div class="panel-heading">
                <div><span class="eyebrow">РАЗРЕШЕНИЯ</span><h2>Ваш доступ</h2></div>
                <span class="access-shield"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4.5 6v5.2c0 4.5 3.2 8.2 7.5 9.8 4.3-1.6 7.5-5.3 7.5-9.8V6z"/><path d="m8.5 12 2.2 2.2 4.8-4.8"/></svg></span>
            </div>
            <p class="access-intro">Учётной записи назначена роль <strong>{{ auth()->user()->role->label() }}</strong>.</p>
            <div class="permission-row">
                <span class="permission-check">✓</span><span>Доступ к рабочей области</span><span class="permission-yes">РАЗРЕШЕНО</span>
            </div>
            <div class="permission-row">
                <span class="permission-check">✓</span><span>Функции согласно роли</span><span class="permission-yes">РАЗРЕШЕНО</span>
            </div>
            <div class="permission-row permission-row-muted">
                <span class="permission-lock">⌑</span><span>Управление пользователями</span>
                <span class="permission-yes">{{ auth()->user()->can('manage-users') ? 'РАЗРЕШЕНО' : 'ТОЛЬКО АДМИН' }}</span>
            </div>
            @can('manage-users')
                <a class="text-action" href="{{ route('admin.users') }}">Настроить доступ <span aria-hidden="true">→</span></a>
            @else
                <span class="access-footnote">Доступом к учётным записям управляет администратор.</span>
            @endcan
        </article>
    </section>

    <footer class="page-footer">
        <span>БЕЗОПАСНОСТЬ ПО УМОЛЧАНИЮ <i></i> КОНФИДЕНЦИАЛЬНОСТЬ ПРЕЖДЕ ВСЕГО</span>
        <span>РАБОЧАЯ ОБЛАСТЬ NEXUS AI <span class="footer-version">0.1.0</span></span>
    </footer>
</x-app-layout>
