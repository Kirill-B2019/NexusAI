<x-auth-layout eyebrow="ВОССТАНОВЛЕНИЕ ДОСТУПА" title="Новый пароль" subtitle="Придумайте надёжный пароль, который нигде больше не используете.">
    <form class="auth-form" method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <div class="field">
            <label for="email">Электронная почта</label>
            <input id="email" name="email" type="email" value="{{ old('email', $request->email) }}" autocomplete="username" required autofocus>
            @error('email') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="password">Новый пароль</label>
            <input id="password" name="password" type="password" autocomplete="new-password" required>
            @error('password') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="password_confirmation">Повторите новый пароль</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
        </div>
        <button class="button button-primary button-wide" type="submit">
            Сохранить пароль <span aria-hidden="true">→</span>
        </button>
    </form>
</x-auth-layout>
