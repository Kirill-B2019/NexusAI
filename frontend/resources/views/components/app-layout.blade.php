@props(['title' => 'Обзор'])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#111411">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta
            name="session-idle-timeout-seconds"
            content="{{ (int) config('session.idle_timeout', 15) * 60 }}"
        >
        <meta
            name="session-idle-remaining-seconds"
            content="{{ request()->attributes->get('idle_timeout_remaining_seconds', (int) config('session.idle_timeout', 15) * 60) }}"
        >
        <meta
            name="session-idle-last-activity"
            content="{{ request()->attributes->get('idle_timeout_last_activity_at', now()->timestamp) }}"
        >
        <meta
            name="session-idle-server-now"
            content="{{ request()->attributes->get('idle_timeout_server_now', now()->timestamp) }}"
        >
        <meta name="session-activity-url" content="{{ route('session.activity.update') }}">
        <meta name="session-expire-url" content="{{ route('session.expire') }}">
        <meta name="session-home-url" content="{{ route('home') }}">
        <meta name="session-user-id" content="{{ auth()->id() }}">
        <title>{{ $title }} · NEXUS AI</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="app-page" x-data="{ sidebarOpen: false, profileOpen: false }">
        <div class="mobile-scrim" x-cloak x-show="sidebarOpen" x-transition.opacity @click="sidebarOpen = false"></div>
        <aside class="sidebar" :class="{ 'sidebar-open': sidebarOpen }">
            <a class="sidebar-brand" href="{{ route('dashboard') }}" aria-label="Главная страница NEXUS AI">
                <img src="{{ asset('images/nexus-logo.png') }}" alt="NEXUS AI">
            </a>

            <div class="workspace-switcher">
                <span class="workspace-mark">N</span>
                <span class="workspace-copy"><strong>Личная рабочая область</strong><small>ЛОКАЛЬНАЯ СРЕДА</small></span>
                <span class="switcher-caret" aria-hidden="true">⌄</span>
            </div>

            <nav class="side-navigation" aria-label="Основная навигация">
                <span class="nav-caption">РАБОЧАЯ ОБЛАСТЬ</span>
                <a class="nav-item {{ request()->routeIs('dashboard') ? 'is-active' : '' }}" href="{{ route('dashboard') }}">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="4" rx="1.5"/><rect x="13.5" y="10.5" width="7" height="10" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/></svg>
                    <span>Обзор</span>
                    <span class="nav-active-dot"></span>
                </a>
                <span class="nav-item nav-disabled" aria-disabled="true" title="Появится в следующих обновлениях">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.5 7.5h7l2 2h8v9a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2z"/><path d="M3.5 7.5v-2a2 2 0 0 1 2-2h4l2 2h7a2 2 0 0 1 2 2v2"/></svg>
                    <span>Проекты</span><span class="nav-soon">СКОРО</span>
                </span>
                <span class="nav-item nav-disabled" aria-disabled="true" title="Появится в следующих обновлениях">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5 4 8v8l8 4.5 8-4.5V8z"/><path d="m4.5 8.2 7.5 4.3 7.5-4.3M12 12.5v8"/></svg>
                    <span>AI-студия</span><span class="nav-soon">СКОРО</span>
                </span>
                <span class="nav-item nav-disabled" aria-disabled="true" title="Появится в следующих обновлениях">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4.5h14a1.5 1.5 0 0 1 1.5 1.5v13a1.5 1.5 0 0 1-1.5 1.5H5A1.5 1.5 0 0 1 3.5 19V6A1.5 1.5 0 0 1 5 4.5Z"/><path d="M7.5 8h9M7.5 12h9M7.5 16h5"/></svg>
                    <span>База знаний</span><span class="nav-soon">СКОРО</span>
                </span>
                <span class="nav-caption nav-caption-spaced">ИНТЕЛЛЕКТ</span>
                @can('manage-users')
                    <a class="nav-item {{ request()->routeIs('admin.experts') ? 'is-active' : '' }}" href="{{ route('admin.experts') }}">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5a6 6 0 0 0-3.5 10.9V18h7v-3.6A6 6 0 0 0 12 3.5Z"/><path d="M9.5 21h5M9.5 18h5M12 1.5v-1M4.6 4.6l-.8-.8M19.4 4.6l.8-.8"/></svg>
                        <span>Эксперты</span>
                    </a>
                @else
                    <span class="nav-item nav-disabled" aria-disabled="true" title="Появится в следующих обновлениях">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5a6 6 0 0 0-3.5 10.9V18h7v-3.6A6 6 0 0 0 12 3.5Z"/><path d="M9.5 21h5M9.5 18h5M12 1.5v-1M4.6 4.6l-.8-.8M19.4 4.6l.8-.8"/></svg>
                        <span>Эксперты</span><span class="nav-soon">СКОРО</span>
                    </span>
                @endcan

                @can('manage-users')
                    <span class="nav-caption nav-caption-spaced">АДМИНИСТРИРОВАНИЕ</span>
                    <a class="nav-item {{ request()->routeIs('admin.users') ? 'is-active' : '' }}" href="{{ route('admin.users') }}">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 20v-1.5a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4V20M9 10.5a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM16 3a4 4 0 0 1 0 7.8M22 20v-1.5a4 4 0 0 0-3-3.9"/></svg>
                        <span>Пользователи</span>
                    </a>
                @endcan
            </nav>

            <div class="sidebar-bottom">
                <div class="connection-card">
                    <span class="connection-icon"><i class="status-dot status-dot-muted"></i></span>
                    <span><strong>API не подключён</strong><small>Только интерфейс</small></span>
                    <span class="connection-indicator"></span>
                </div>
                <div class="sidebar-footnote">
                    <span>СБОРКА 0.1.0</span>
                    <span class="footnote-separator"></span>
                    <span>ОСНОВА</span>
                </div>
            </div>
        </aside>

        <div class="main-column">
            <header class="topbar">
                <div class="topbar-start">
                    <button class="icon-button mobile-menu" type="button" aria-label="Открыть навигацию" @click="sidebarOpen = true">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>
                    <div class="breadcrumb"><span>Рабочая область</span><span class="breadcrumb-slash">/</span><strong>{{ $title }}</strong></div>
                </div>
                <div class="topbar-end">
                    <span class="environment-pill"><i></i> ЛОКАЛЬНО</span>
                    <span class="topbar-divider"></span>
                    <div class="profile-wrap">
                        <button class="profile-button" type="button" @click="profileOpen = !profileOpen" @click.outside="profileOpen = false" :aria-expanded="profileOpen.toString()">
                            <span class="avatar">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr(auth()->user()->name, 0, 1)) }}</span>
                            <span class="profile-copy"><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->role->label() }}</small></span>
                            <svg class="profile-chevron" viewBox="0 0 16 16" aria-hidden="true"><path d="m4 6 4 4 4-4"/></svg>
                        </button>
                        <div class="profile-menu" x-cloak x-show="profileOpen" x-transition.origin.top.right>
                            <div class="profile-menu-heading">
                                <strong>{{ auth()->user()->name }}</strong>
                                <span>{{ auth()->user()->email }}</span>
                            </div>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="profile-menu-action">
                                    <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M8 3H4.5A1.5 1.5 0 0 0 3 4.5v11A1.5 1.5 0 0 0 4.5 17H8M12.5 6.5 16 10l-3.5 3.5M16 10H7"/></svg>
                                    Выйти
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>

            <main class="page-content">
                {{ $slot }}
            </main>
        </div>

        @livewireScripts
    </body>
</html>
