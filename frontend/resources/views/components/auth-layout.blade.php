@props([
    'eyebrow' => 'РАБОЧАЯ ОБЛАСТЬ NEXUS AI',
    'title' => 'С возвращением',
    'subtitle' => 'Войдите, чтобы продолжить работу.',
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#111411">
        <link rel="icon" type="image/webp" href="{{ asset('images/nexus-ai-brand.webp') }}">
        <title>{{ $title }} · NEXUS AI</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="auth-page">
        <main class="auth-shell">
            <a class="auth-brand" href="{{ route('login') }}" aria-label="Вход в NEXUS AI">
                <img src="{{ asset('images/nexus-ai-brand.webp') }}" alt="NEXUS AI — искусственный интеллект">
            </a>
            <section class="auth-card">
                <div class="auth-heading">
                    <span class="eyebrow">{{ $eyebrow }}</span>
                    <h1>{{ $title }}</h1>
                    <p>{{ $subtitle }}</p>
                </div>
                {{ $slot }}
            </section>
            <footer class="auth-footer">
                <span><i class="status-dot"></i> Защищённая рабочая область</span>
                <span>© {{ now()->year }} NEXUS AI</span>
            </footer>
        </main>
        @livewireScripts
    </body>
</html>
