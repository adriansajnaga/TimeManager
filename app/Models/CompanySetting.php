<?php

namespace App\Models;

use App\Enums\VatCode;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Dane sprzedawcy (jeden wiersz).
 *
 * @property int $id
 * @property string|null $name
 * @property string|null $street
 * @property string|null $zip
 * @property string|null $city
 * @property string $country_code
 * @property string|null $nip
 * @property string $vat_prefix
 * @property string|null $regon
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $website
 * @property string|null $logo_path
 * @property string|null $document_footer
 * @property string|null $issue_place
 * @property int $default_payment_days
 * @property VatCode $default_vat_code
 */
#[Fillable([
    'name', 'street', 'zip', 'city', 'country_code', 'nip', 'vat_prefix', 'regon', 'email', 'phone',
    'website', 'logo_path', 'document_footer', 'issue_place', 'default_payment_days', 'default_vat_code',
])]
class CompanySetting extends Model
{
    use LogsActivity;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_payment_days' => 'integer',
            'default_vat_code' => VatCode::class,
        ];
    }

    /**
     * Zapisany wiersz ustawień albo pusty obiekt z wartościami domyślnymi.
     */
    public static function current(): self
    {
        return static::query()->first() ?? new self([
            'country_code' => 'PL',
            'vat_prefix' => 'PL',
            'default_payment_days' => 14,
            'default_vat_code' => VatCode::Rate23,
        ]);
    }

    /**
     * Stopka dokumentów, np. „ASCOMM Adrian Sajnaga, NIP: …, REGON: …”, gdy nie wpisano własnej.
     */
    public function footer(): string
    {
        if (filled($this->document_footer)) {
            return $this->document_footer;
        }

        return collect([
            $this->name,
            filled($this->nip) ? 'NIP: '.$this->nip : null,
            filled($this->regon) ? 'REGON: '.$this->regon : null,
        ])->filter()->implode(', ');
    }
}
