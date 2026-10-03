<?php

namespace App\Documents;

use App\Models\CompanySetting;
use App\Models\Contractor;
use Closure;
use Illuminate\Support\Facades\Storage;

/**
 * Wspólne elementy dokumentów roboczych: tłumaczenia w języku klienta, formaty, dane firmy i logo.
 */
abstract class WorkDocument implements Document
{
    public function orientation(): string
    {
        return 'P';
    }

    /**
     * @return array<string, mixed>
     */
    protected function common(): array
    {
        $company = CompanySetting::current();

        return [
            't' => $this->translator(),
            'format' => new DocumentFormat($this->language()),
            'company' => $company,
            'companyLogo' => self::logoPath($company->logo_path),
        ];
    }

    /**
     * Tłumaczenie etykiety dokumentu w języku klienta, np. $t('client') → „Auftraggeber”.
     */
    protected function translator(): Closure
    {
        $locale = $this->language()->value;

        return fn (string $key): mixed => trans('workdocs.'.$key, [], $locale);
    }

    protected static function logoPath(?string $path): ?string
    {
        return $path !== null && Storage::disk('local')->exists($path)
            ? Storage::disk('local')->path($path)
            : null;
    }

    /**
     * Numer podatkowy z właściwą etykietą: polski NIP albo numer VAT UE.
     */
    protected static function taxLine(Contractor $contractor, Closure $t): ?string
    {
        if ($contractor->vatId() === null) {
            return null;
        }

        return ($contractor->country_code === 'PL' ? $t('tax_id_pl') : $t('vat_id')).': '.$contractor->vatId();
    }
}
