<?php

namespace Database\Seeders;

use App\Enums\VatCode;
use App\Models\BankAccount;
use App\Models\CompanySetting;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;

/**
 * Dane sprzedawcy jak na fakturze 4/8/2026. Numer konta jest przykładowy —
 * prawdziwy wpisuje się w Administracja → Konta bankowe.
 */
class CompanySeeder extends Seeder
{
    public function run(): void
    {
        CompanySetting::query()->updateOrCreate([], [
            'name' => 'ASCOMM Adrian Sajnaga',
            'street' => 'ul. Konstytucji 3 Maja 15/12',
            'zip' => '87-100',
            'city' => 'Toruń',
            'country_code' => 'PL',
            'nip' => '8792451081',
            'vat_prefix' => 'PL',
            'regon' => '871123082',
            'website' => 'www.ascomm.pl',
            'issue_place' => 'Toruń',
            'default_payment_days' => 14,
            'default_vat_code' => VatCode::Rate23,
            'logo_path' => SeederAssets::store('ascomm-logo.png', 'company/logo.png'),
        ]);

        BankAccount::query()->updateOrCreate(['label' => 'REVOLT21'], [
            'iban' => 'LT00 0000 0000 0000 0000',
            'swift' => 'REVOLT21',
            'currency' => 'EUR',
            'is_default' => true,
        ]);

        Vehicle::query()->updateOrCreate(['name' => 'Ford'], [
            'is_default' => true,
        ]);
    }
}
