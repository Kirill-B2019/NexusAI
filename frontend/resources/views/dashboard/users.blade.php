<x-app-layout title="Пользователи">
    <section class="welcome-row page-title-row">
        <div>
            <div class="eyebrow"><span class="eyebrow-line"></span> АДМИНИСТРИРОВАНИЕ</div>
            <h1>Доступ <span>пользователей</span></h1>
            <p class="welcome-copy">Просмотр учётных записей рабочей области и назначенных им ролей.</p>
        </div>
        <span class="section-caption">{{ $users->total() }} ПОЛЬЗОВАТЕЛЕЙ</span>
    </section>

    <section class="panel users-panel">
        <div class="panel-heading users-heading">
            <div><span class="eyebrow">СПИСОК ДОСТУПА</span><h2>Участники рабочей области</h2></div>
            <span class="directory-count">Всего: {{ $users->total() }}</span>
        </div>
        @if ($users->isEmpty())
            <div class="empty-state">
                <span class="empty-state-icon">◎</span>
                <strong>Пока нет учётных записей</strong>
                <p>Публичная регистрация отключена; дополнительных учётных записей пока нет.</p>
            </div>
        @else
            <div class="table-scroll">
                <table class="user-table">
                    <thead><tr><th>УЧАСТНИК</th><th>РОЛЬ</th><th>ДАТА РЕГИСТРАЦИИ</th><th>СТАТУС</th></tr></thead>
                    <tbody>
                        @foreach ($users as $member)
                            <tr>
                                <td>
                                    <span class="member-cell">
                                        <span class="avatar avatar-table">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($member->name, 0, 1)) }}</span>
                                        <span><strong>{{ $member->name }}</strong><small>{{ $member->email }}</small></span>
                                    </span>
                                </td>
                                <td><span class="role-badge role-{{ $member->role->value }}">{{ $member->role->label() }}</span></td>
                                <td class="joined-cell">{{ $member->created_at->locale(app()->getLocale())->isoFormat('D MMM YYYY') }}</td>
                                <td><span class="member-status"><i></i> АКТИВЕН</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="pagination-row">{{ $users->links() }}</div>
        @endif
    </section>

    <div class="admin-note"><span>i</span><p>Назначить или изменить роль может доверенный оператор командой <code>php artisan app:assign-user-role</code>.</p></div>
</x-app-layout>
