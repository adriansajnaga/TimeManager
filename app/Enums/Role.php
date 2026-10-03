<?php

namespace App\Enums;

/**
 * Role użytkowników. Nowa rola = nowy przypadek + lista jej uprawnień w permissions().
 */
enum Role: string
{
    case Admin = 'admin';
    case Employee = 'employee';

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),
            self::Employee => [
                Permission::LogOwnTime,
            ],
        };
    }

    public function allows(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Admin => __('Administrator'),
            self::Employee => __('Employee'),
        };
    }
}
