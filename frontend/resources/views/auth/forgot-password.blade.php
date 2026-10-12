<x-auth-layout eyebrow="ВОССТАНОВЛЕНИЕ ДОСТУПА" title="Сброс пароля" subtitle="Отправим защищённую ссылку для смены пароля на вашу почту.">
    @if (session('status'))
        <div class="notice notice-success" role="status">{{ session('status') }}</div>
    @endif

    <form class="auth-form" method="POST" action="{{ route('password.email') }}">
        @csrf
        <div class="field">
            <label for="email">Электронная почта</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
            @error('email') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <button class="button button-primary button-wide" type="submit">
            Отправить ссылку <span aria-hidden="true">→</span>
        </button>
    </form>
    <p class="auth-switch"><a class="text-link" href="{{ route('login') }}">← Вернуться ко входу</a></p>
</x-auth-layout>
