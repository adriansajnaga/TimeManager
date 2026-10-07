<?php

namespace App\Enums;

/**
 * Role użytkowników. Nowa rola = nowy przypadek + lista jej uprawnień w permissions().
 */
enum Role: string
{
    case Admin = 'admin';
    case Employee = 'employee';
    case Client = 'client';

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            // Administrator ma wszystko poza podglądem klienta (ten wymaga przypisanej firmy).
            self::Admin => array_filter(Permission::cases(), fn (Permission $permission) => $permission !== Permission::ViewClientPortal),
            self::Employee => [
                Permission::LogOwnTime,
            ],
            self::Client => [
                Permission::ViewClientPortal,
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
            self::Client => __('Client (view only)'),
        };
    }
}
