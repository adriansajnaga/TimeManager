<?php

namespace Database\Factories;

use App\Enums\ContractorType;
use App\Enums\InvoiceLanguage;
use App\Enums\InvoiceLineMode;
use App\Enums\Language;
use App\Enums\PackageDocument;
use App\Enums\VatCode;
use App\Models\Contractor;
use App\Support\DefaultTemplates;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contractor>
 */
class ContractorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => ContractorType::Client,
            'name' => fake()->company(),
            'street' => fake()->streetAddress(),
            'zip' => fake()->postcode(),
            'city' => fake()->city(),
            'country_code' => 'PL',
            'vat_prefix' => 'PL',
            'tax_id' => fake()->numerify('##########'),
            'email' => fake()->companyEmail(),
            'document_language' => Language::Polish,
            'invoice_language' => InvoiceLanguage::Polish,
            'currency' => 'PLN',
            'vat_code' => VatCode::Rate23,
            'invoice_line_mode' => InvoiceLineMode::Single,
            'invoice_description_template' => DefaultTemplates::invoiceDescription(Language::Polish),
            'payment_days' => 14,
            'package_documents' => PackageDocument::defaultOrder(),
            'hourly_rate' => '100.00',
            'km_rate' => '1.1500',
            'is_active' => true,
        ];
    }

    /**
     * Klient z Niemiec rozliczany jak Gärtner (EUR, dokumenty DE, faktura PL/EN).
     */
    public function german(): static
    {
        return $this->state(fn (array $attributes) => [
            'country_code' => 'DE',
            'vat_prefix' => 'DE',
            'tax_id' => fake()->numerify('#########'),
            'document_language' => Language::German,
            'invoice_language' => InvoiceLanguage::PolishEnglish,
            'currency' => 'EUR',
            'vat_code' => VatCode::OutsideScopeEuServices,
            'invoice_description_template' => DefaultTemplates::invoiceDescription(Language::German),
            'hourly_rate' => '38.00',
            'km_rate' => '0.3000',
        ]);
    }

    public function supplier(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ContractorType::Supplier,
        ]);
    }
}
