<?php

namespace App\Enums;

enum ContractorType: string
{
    case Client = 'client';
    case Supplier = 'supplier';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Client => __('Client'),
            self::Supplier => __('Supplier'),
            self::Both => __('Client and supplier'),
        };
    }

    public function isClient(): bool
    {
        return $this !== self::Supplier;
    }
}
