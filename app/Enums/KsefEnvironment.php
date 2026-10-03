<?php

namespace App\Enums;

/**
 * Środowiska KSeF 2.0. Adresy z dokumentacji Ministerstwa Finansów
 * (https://github.com/CIRFMF/ksef-api/blob/main/srodowiska.md).
 */
enum KsefEnvironment: string
{
    case Test = 'test';
    case Demo = 'demo';
    case Prod = 'prod';

    public function label(): string
    {
        return match ($this) {
            self::Test => __('Test (integration)'),
            self::Demo => __('Pre-production (demo)'),
            self::Prod => __('Production — legally binding invoices'),
        };
    }

    public function baseUrl(): string
    {
        return match ($this) {
            self::Test => 'https://api-test.ksef.mf.gov.pl/v2',
            self::Demo => 'https://api-demo.ksef.mf.gov.pl/v2',
            self::Prod => 'https://api.ksef.mf.gov.pl/v2',
        };
    }

    /** Adres, z którego korzystają kody QR weryfikujące fakturę. */
    public function qrBaseUrl(): string
    {
        return match ($this) {
            self::Test => 'https://qr-test.ksef.mf.gov.pl',
            self::Demo => 'https://qr-demo.ksef.mf.gov.pl',
            self::Prod => 'https://qr.ksef.mf.gov.pl',
        };
    }

    public function isProduction(): bool
    {
        return $this === self::Prod;
    }
}
