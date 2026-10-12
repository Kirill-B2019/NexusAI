<x-auth-layout eyebrow="ЗАЩИТА СЕССИИ" title="Подтвердите пароль" subtitle="Для продолжения подтвердите свой пароль.">
    <form class="auth-form" method="POST" action="{{ route('password.confirm') }}">
        @csrf
        <div class="field">
            <label for="password">Текущий пароль</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required autofocus>
            @error('password') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <button class="button button-primary button-wide" type="submit">
            Подтвердить <span aria-hidden="true">→</span>
        </button>
    </form>
    <p class="auth-switch"><a class="text-link" href="{{ route('dashboard') }}">← Вернуться в рабочую область</a></p>
</x-auth-layout>
