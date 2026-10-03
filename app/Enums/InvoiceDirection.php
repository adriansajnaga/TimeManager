<?php

namespace App\Enums;

enum InvoiceDirection: string
{
    case Sales = 'sales';
    case Purchase = 'purchase';

    public function label(): string
    {
        return match ($this) {
            self::Sales => __('Sales'),
            self::Purchase => __('Purchases'),
        };
    }
}
