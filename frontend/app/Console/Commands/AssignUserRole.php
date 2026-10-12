<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AssignUserRole extends Command
{
    protected $signature = 'app:assign-user-role {email} {role : admin, user или viewer}';

    protected $description = 'Назначить роль существующему пользователю';

    public function handle(): int
    {
        $role = UserRole::tryFrom(Str::lower($this->argument('role')));

        if (! $role) {
            $this->error('Допустимые роли: admin, user или viewer.');

            return self::INVALID;
        }

        $user = User::query()
            ->where('email', Str::lower($this->argument('email')))
            ->first();

        if (! $user) {
            $this->error('Пользователь с таким адресом электронной почты не найден.');

            return self::FAILURE;
        }

        $user->forceFill(['role' => $role])->save();

        $this->info("Пользователю {$user->email} назначена роль «{$role->label()}».");

        return self::SUCCESS;
    }
}
