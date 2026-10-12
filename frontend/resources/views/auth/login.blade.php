<x-auth-layout eyebrow="ЗАЩИЩЁННЫЙ ВХОД" title="С возвращением" subtitle="Войдите, чтобы продолжить работу в NEXUS AI.">
    @if (session('status'))
        <div class="notice notice-success" role="status">{{ session('status') }}</div>
    @endif

    <form class="auth-form" method="POST" action="{{ route('login') }}">
        @csrf
        <div class="field">
            <label for="email">Электронная почта</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
            @error('email') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <div class="field-label-row">
                <label for="password">Пароль</label>
                <a class="text-link" href="{{ route('password.request') }}">Забыли пароль?</a>
            </div>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
            @error('password') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <label class="check-row">
            <input type="checkbox" name="remember" value="1">
            <span>Оставаться в системе</span>
        </label>
        <button class="button button-primary button-wide" type="submit">
            Войти <span aria-hidden="true">→</span>
        </button>
    </form>
    <p class="auth-switch">Доступ к системе выдаёт администратор.</p>
</x-auth-layout>
