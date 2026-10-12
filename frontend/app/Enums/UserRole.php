<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case User = 'user';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Администратор',
            self::User => 'Пользователь',
            self::Viewer => 'Только чтение',
        };
    }
}
