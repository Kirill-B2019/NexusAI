<x-app-layout title="Смена пароля">
    <section class="password-change-shell panel">
        <div class="eyebrow"><span class="eyebrow-line"></span> ЗАЩИТА УЧЁТНОЙ ЗАПИСИ</div>
        <h1>Задайте свой пароль</h1>
        <p class="welcome-copy">Администратор создал для вас временный пароль. Придумайте новый, чтобы продолжить работу.</p>

        <form class="password-change-form" method="POST" action="{{ route('account.password.update') }}">
            @csrf
            <div class="field">
                <label for="password">Новый пароль</label>
                <input id="password" name="password" type="password" autocomplete="new-password" required autofocus>
                @error('password') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="password_confirmation">Повторите новый пароль</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
            </div>
            <button class="button button-primary" type="submit">Сохранить пароль</button>
        </form>
    </section>
</x-app-layout>
