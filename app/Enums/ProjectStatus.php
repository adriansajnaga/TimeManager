<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Active = 'active';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Closed => __('Closed'),
        };
    }
}
