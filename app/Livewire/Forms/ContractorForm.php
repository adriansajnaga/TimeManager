<?php

namespace App\Livewire\Forms;

use App\Enums\ContractorType;
use App\Enums\InvoiceLanguage;
use App\Enums\InvoiceLineMode;
use App\Enums\Language;
use App\Enums\PackageDocument;
use App\Enums\VatCode;
use App\Models\Contractor;
use App\Support\DefaultTemplates;
use Closure;
use Illuminate\Validation\Rule;
use Livewire\Form;

class ContractorForm extends Form
{
    public ?Contractor $contractor = null;

    public string $type = 'client';

    public string $name = '';

    public string $street = '';

    public string $zip = '';

    public string $city = '';

    public string $country_code = 'PL';

    public string $vat_prefix = '';

    public string $tax_id = '';

    public string $email = '';

    public string $phone = '';

    public string $document_language = 'pl';

    public string $invoice_language = 'pl';

    public string $currency = 'PLN';

    public string $vat_code = '23';

    public string $invoice_line_mode = 'single';

    public string $invoice_description_template = '';

    public int|string $payment_days = 14;

    public ?string $bank_account_id = null;

    /**
     * Wszystkie dokumenty pakietu w ustalonej kolejności.
     *
     * @var list<string>
     */
    public array $package_order = [];

    /**
     * Dokumenty zaznaczone do pakietu.
     *
     * @var list<string>
     */
    public array $package_included = [];

    public string $hourly_rate = '';

    public string $km_rate = '';

    public string $base_address = '';

    public ?string $vehicle_id = null;

    /** Adresy oddzielone przecinkiem, średnikiem lub nową linią. */
    public string $email_to = '';

    public string $email_cc = '';

    public string $email_subject_template = '';

    public string $email_body_template = '';

    public string $notes = '';

    public bool $is_active = true;

    public function setContractor(?Contractor $contractor): void
    {
        $this->contractor = $contractor;

        if ($contractor === null) {
            $this->package_included = PackageDocument::defaultOrder();
            $this->package_order = $this->completeOrder($this->package_included);
            $this->applyDefaultTemplates();

            return;
        }

        $this->fill([
            'type' => $contractor->type->value,
            'name' => $contractor->name,
            'street' => (string) $contractor->street,
            'zip' => (string) $contractor->zip,
            'city' => (string) $contractor->city,
            'country_code' => $contractor->country_code,
            'vat_prefix' => (string) $contractor->vat_prefix,
            'tax_id' => (string) $contractor->tax_id,
            'email' => (string) $contractor->email,
            'phone' => (string) $contractor->phone,
            'document_language' => $contractor->document_language->value,
            'invoice_language' => $contractor->invoice_language->value,
            'currency' => $contractor->currency,
            'vat_code' => $contractor->vat_code->value,
            'invoice_line_mode' => $contractor->invoice_line_mode->value,
            'invoice_description_template' => (string) $contractor->invoice_description_template,
            'payment_days' => $contractor->payment_days,
            'bank_account_id' => $contractor->bank_account_id ? (string) $contractor->bank_account_id : null,
            'hourly_rate' => (string) $contractor->hourly_rate,
            'km_rate' => $contractor->km_rate === null ? '' : rtrim(rtrim((string) $contractor->km_rate, '0'), '.'),
            'base_address' => (string) $contractor->base_address,
            'vehicle_id' => $contractor->vehicle_id ? (string) $contractor->vehicle_id : null,
            'email_to' => implode(', ', $contractor->email_to ?? []),
            'email_cc' => implode(', ', $contractor->email_cc ?? []),
            'email_subject_template' => (string) $contractor->email_subject_template,
            'email_body_template' => (string) $contractor->email_body_template,
            'notes' => (string) $contractor->notes,
            'is_active' => $contractor->is_active,
        ]);

        $this->package_included = array_map(fn (PackageDocument $document) => $document->value, $contractor->packageDocuments());
        $this->package_order = $this->completeOrder($this->package_included);
    }

    /**
     * Wstawia domyślne szablony opisu faktury i e-maila w języku dokumentów klienta.
     */
    public function applyDefaultTemplates(): void
    {
        $language = Language::tryFrom($this->document_language) ?? Language::Polish;

        $this->invoice_description_template = DefaultTemplates::invoiceDescription($language);
        $this->email_subject_template = DefaultTemplates::emailSubject($language);
        $this->email_body_template = DefaultTemplates::emailBody($language);
    }

    public function movePackageDocument(string $value, int $direction): void
    {
        $order = $this->package_order;
        $index = array_search($value, $order, true);

        if ($index === false || ! isset($order[$index + $direction])) {
            return;
        }

        [$order[$index], $order[$index + $direction]] = [$order[$index + $direction], $order[$index]];

        $this->package_order = array_values($order);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ContractorType::class)],
            'name' => ['required', 'string', 'max:255'],
            'street' => ['nullable', 'string', 'max:255'],
            'zip' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'country_code' => ['required', 'string', 'size:2', 'alpha'],
            'vat_prefix' => ['nullable', 'string', 'size:2', 'alpha'],
            'tax_id' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'document_language' => ['required', Rule::enum(Language::class)],
            'invoice_language' => ['required', Rule::enum(InvoiceLanguage::class)],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'vat_code' => ['required', Rule::enum(VatCode::class)],
            'invoice_line_mode' => ['required', Rule::enum(InvoiceLineMode::class)],
            'invoice_description_template' => ['nullable', 'string', 'max:2000'],
            'payment_days' => ['required', 'integer', 'min:0', 'max:365'],
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'package_included' => ['array'],
            'package_included.*' => [Rule::enum(PackageDocument::class)],
            'hourly_rate' => ['nullable', 'numeric', 'min:0', 'max:99999999', 'decimal:0,2'],
            'km_rate' => ['nullable', 'numeric', 'min:0', 'max:999999', 'decimal:0,4'],
            'base_address' => ['nullable', 'string', 'max:255'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'email_to' => ['nullable', 'string', 'max:1000', $this->emailListRule()],
            'email_cc' => ['nullable', 'string', 'max:1000', $this->emailListRule()],
            'email_subject_template' => ['nullable', 'string', 'max:255'],
            'email_body_template' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['boolean'],
        ];
    }

    public function save(): Contractor
    {
        $this->validate();

        $contractor = $this->contractor ?? new Contractor;

        $contractor->fill([
            'type' => $this->type,
            'name' => trim($this->name),
            'street' => $this->nullable($this->street),
            'zip' => $this->nullable($this->zip),
            'city' => $this->nullable($this->city),
            'country_code' => strtoupper($this->country_code),
            'vat_prefix' => $this->nullable(strtoupper($this->vat_prefix)),
            'tax_id' => $this->nullable(preg_replace('/\s+/', '', $this->tax_id)),
            'email' => $this->nullable($this->email),
            'phone' => $this->nullable($this->phone),
            'document_language' => $this->document_language,
            'invoice_language' => $this->invoice_language,
            'currency' => strtoupper($this->currency),
            'vat_code' => $this->vat_code,
            'invoice_line_mode' => $this->invoice_line_mode,
            'invoice_description_template' => $this->nullable($this->invoice_description_template),
            'payment_days' => (int) $this->payment_days,
            'bank_account_id' => $this->bank_account_id ?: null,
            'package_documents' => array_values(array_filter(
                $this->package_order,
                fn (string $value) => in_array($value, $this->package_included, true),
            )),
            'hourly_rate' => $this->nullable($this->hourly_rate),
            'km_rate' => $this->nullable($this->km_rate),
            'base_address' => $this->nullable($this->base_address),
            'vehicle_id' => $this->vehicle_id ?: null,
            'email_to' => $this->emailList($this->email_to) ?: null,
            'email_cc' => $this->emailList($this->email_cc) ?: null,
            'email_subject_template' => $this->nullable($this->email_subject_template),
            'email_body_template' => $this->nullable($this->email_body_template),
            'notes' => $this->nullable($this->notes),
            'is_active' => $this->is_active,
        ])->save();

        $this->contractor = $contractor;

        return $contractor;
    }

    /**
     * Uzupełnia kolejność o dokumenty niewybrane (na końcu), żeby dało się je dołączyć później.
     *
     * @param  list<string>  $included
     * @return list<string>
     */
    private function completeOrder(array $included): array
    {
        $all = array_map(fn (PackageDocument $document) => $document->value, PackageDocument::cases());

        return array_values(array_unique([...$included, ...$all]));
    }

    /**
     * @return list<string>
     */
    private function emailList(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $value) ?: [])));
    }

    private function emailListRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            foreach ($this->emailList((string) $value) as $address) {
                if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                    $fail(__('":address" is not a valid e-mail address.', ['address' => $address]));
                }
            }
        };
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
