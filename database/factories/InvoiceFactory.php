<?php

namespace Database\Factories;

use App\Enums\InvoiceDirection;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceLanguage;
use App\Enums\InvoiceStatus;
use App\Enums\VatCode;
use App\Models\Contractor;
use App\Models\Invoice;
use App\Services\Invoices\Parties;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Szkic faktury sprzedaży z dzisiejszą datą; pozycje dodaje withItem().
 *
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'direction' => InvoiceDirection::Sales,
            'kind' => InvoiceKind::Vat,
            'contractor_id' => Contractor::factory(),
            'issue_date' => today(),
            'sale_date' => today(),
            'due_date' => today()->addDays(14),
            'currency' => 'PLN',
            'language' => InvoiceLanguage::Polish,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Invoice $invoice) {
            Parties::apply($invoice);
        });
    }

    public function kind(InvoiceKind $kind): static
    {
        return $this->state(fn (array $attributes) => ['kind' => $kind]);
    }

    public function purchase(): static
    {
        return $this->state(fn (array $attributes) => [
            'direction' => InvoiceDirection::Purchase,
            'number' => fake()->bothify('FV ###/??/2026'),
            'contractor_id' => Contractor::factory()->supplier(),
        ]);
    }

    /**
     * Wystawiona (numer nadany poza KSeF — tylko w testach).
     */
    public function issued(?string $number = null): static
    {
        return $this->afterCreating(function (Invoice $invoice) use ($number) {
            $invoice->forceFill([
                'status' => InvoiceStatus::Issued,
                'number' => $number ?? $invoice->number ?? fake()->unique()->numerify('#/'.$invoice->issue_date->month.'/'.$invoice->issue_date->year),
                'issued_at' => now(),
            ])->save();
        });
    }

    public function withItem(string $unitPrice = '100.00', string $quantity = '1', VatCode $vatCode = VatCode::Rate23, string $name = 'Usługa'): static
    {
        return $this->afterCreating(function (Invoice $invoice) use ($unitPrice, $quantity, $vatCode, $name) {
            $invoice->items()->create([
                'position' => $invoice->items()->count() + 1,
                'name' => $name,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'vat_code' => $vatCode,
            ]);

            $invoice->refreshTotals();
        });
    }
}
