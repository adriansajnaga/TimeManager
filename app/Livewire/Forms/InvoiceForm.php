<?php

namespace App\Livewire\Forms;

use App\Enums\InvoiceDirection;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceLanguage;
use App\Enums\InvoiceSource;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\VatCode;
use App\Models\BankAccount;
use App\Models\CompanySetting;
use App\Models\Contractor;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Project;
use App\Services\Invoices\Parties;
use App\Services\Invoices\VatSummary;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Form;

class InvoiceForm extends Form
{
    public ?Invoice $invoice = null;

    public string $direction = 'sales';

    public string $kind = 'vat';

    public ?string $contractor_id = null;

    /** Projekt ryczałtowy (zaliczki i faktura końcowa). */
    public ?string $project_id = null;

    /** Numer faktury zakupu (sprzedaż dostaje numer przy wystawieniu). */
    public string $number = '';

    public string $issue_date = '';

    public string $sale_date = '';

    public string $due_date = '';

    public string $issue_place = '';

    public string $payment_method = 'transfer';

    public ?string $bank_account_id = null;

    public string $paid_on = '';

    public string $currency = 'PLN';

    public string $exchange_rate = '';

    public string $exchange_rate_date = '';

    public string $exchange_rate_table = '';

    public string $language = 'pl';

    public string $advance_amount = '';

    /** @var list<string> */
    public array $advance_ids = [];

    public ?int $corrected_invoice_id = null;

    public string $corrected_number = '';

    public string $corrected_issue_date = '';

    public string $corrected_ksef_number = '';

    public string $correction_reason = '';

    public string $vat_exemption_basis = '';

    public string $notes = '';

    /** @var list<array{name: string, unit: string, quantity: string, unit_price: string, vat_code: string}> */
    public array $items = [];

    /**
     * Korekta: pozycje faktury korygowanej (stan przed korektą), tylko do odczytu.
     *
     * @var list<array{name: string, unit: string, quantity: string, unit_price: string, vat_code: string}>
     */
    public array $before_items = [];

    public function setInvoice(?Invoice $invoice, InvoiceDirection $direction = InvoiceDirection::Sales, InvoiceKind $kind = InvoiceKind::Vat): void
    {
        $this->invoice = $invoice;

        if ($invoice === null) {
            $company = CompanySetting::current();
            $today = CarbonImmutable::today();

            $this->direction = $direction->value;
            $this->kind = $kind->value;
            $this->issue_date = $today->toDateString();
            $this->sale_date = $today->toDateString();
            $this->due_date = $direction === InvoiceDirection::Sales ? $today->addDays($company->default_payment_days)->toDateString() : '';
            $this->issue_place = (string) $company->issue_place;
            $defaultAccount = BankAccount::default();
            $this->bank_account_id = $defaultAccount !== null ? (string) $defaultAccount->id : null;
            $this->items = [$this->emptyItem($company->default_vat_code)];

            return;
        }

        $invoice->loadMissing(['items', 'advances']);

        $this->fill([
            'direction' => $invoice->direction->value,
            'kind' => $invoice->kind->value,
            'contractor_id' => $invoice->contractor_id !== null ? (string) $invoice->contractor_id : null,
            'project_id' => $invoice->project_id !== null ? (string) $invoice->project_id : null,
            'number' => (string) $invoice->number,
            'issue_date' => $invoice->issue_date->toDateString(),
            'sale_date' => (string) $invoice->sale_date?->toDateString(),
            'due_date' => (string) $invoice->due_date?->toDateString(),
            'issue_place' => (string) $invoice->issue_place,
            'payment_method' => $invoice->payment_method->value,
            'bank_account_id' => $this->bankAccountIdOf($invoice->bank_account),
            'paid_on' => (string) $invoice->paid_on?->toDateString(),
            'currency' => $invoice->currency,
            'exchange_rate' => (string) $invoice->exchange_rate,
            'exchange_rate_date' => (string) $invoice->exchange_rate_date?->toDateString(),
            'exchange_rate_table' => (string) $invoice->exchange_rate_table,
            'language' => $invoice->language->value,
            'advance_amount' => (string) $invoice->advance_amount,
            'advance_ids' => $invoice->advances->map(fn (Invoice $advance) => (string) $advance->id)->values()->all(),
            'corrected_invoice_id' => $invoice->corrected_invoice_id,
            'corrected_number' => (string) $invoice->corrected_number,
            'corrected_issue_date' => (string) $invoice->corrected_issue_date?->toDateString(),
            'corrected_ksef_number' => (string) $invoice->corrected_ksef_number,
            'correction_reason' => (string) $invoice->correction_reason,
            'vat_exemption_basis' => (string) $invoice->vat_exemption_basis,
            'notes' => (string) $invoice->notes,
        ]);

        $this->items = $this->itemRows($invoice, false);
        $this->before_items = $this->itemRows($invoice, true);
    }

    /**
     * Nowa korekta wystawionej faktury: pozycje przed = pozycje oryginału, po = ich kopia do zmiany.
     */
    public function setCorrectionOf(Invoice $original): void
    {
        $this->setInvoice($original);

        $this->invoice = null;
        $this->kind = InvoiceKind::Correction->value;
        $this->number = '';
        $this->issue_date = CarbonImmutable::today()->toDateString();
        $this->sale_date = '';
        $this->paid_on = '';
        $this->advance_amount = '';
        $this->advance_ids = [];
        $this->corrected_invoice_id = $original->id;
        $this->corrected_number = (string) $original->number;
        $this->corrected_issue_date = $original->issue_date->toDateString();
        $this->corrected_ksef_number = '';
        $this->correction_reason = '';
        $this->before_items = $this->itemRows($original, false);
        $this->items = $this->before_items;
    }

    /**
     * Kopia faktury jako nowy szkic z dzisiejszymi datami.
     */
    public function setCopyOf(Invoice $original): void
    {
        $this->setInvoice($original);

        $company = CompanySetting::current();
        $today = CarbonImmutable::today();
        $days = $original->contractor !== null ? $original->contractor->payment_days : $company->default_payment_days;

        $this->invoice = null;
        $this->number = '';
        $this->issue_date = $today->toDateString();
        $this->sale_date = $today->toDateString();
        $this->due_date = $this->direction === InvoiceDirection::Sales->value ? $today->addDays($days)->toDateString() : '';
        $this->paid_on = '';
        $this->exchange_rate = '';
        $this->exchange_rate_date = '';
        $this->exchange_rate_table = '';
        $this->advance_ids = [];
    }

    /**
     * Ustawienia faktur kontrahenta: waluta, język, termin płatności, rachunek, stawka VAT pustych pozycji.
     */
    public function applyContractor(): void
    {
        $contractor = $this->contractor_id ? Contractor::query()->find($this->contractor_id) : null;

        if ($contractor === null || $this->direction !== InvoiceDirection::Sales->value) {
            return;
        }

        $this->currency = $contractor->currency;
        $this->language = $contractor->invoice_language->value;

        if ($this->issue_date !== '') {
            $this->due_date = CarbonImmutable::parse($this->issue_date)->addDays($contractor->payment_days)->toDateString();
        }

        if ($contractor->bank_account_id !== null) {
            $this->bank_account_id = (string) $contractor->bank_account_id;
        }

        foreach ($this->items as $index => $item) {
            if (trim($item['name']) === '' && trim($item['unit_price']) === '') {
                $this->items[$index]['vat_code'] = $contractor->vat_code->value;
            }
        }

        if ($this->currency === 'PLN') {
            $this->exchange_rate = '';
            $this->exchange_rate_date = '';
            $this->exchange_rate_table = '';
        }
    }

    /**
     * Faktura projektu ryczałtowego: nabywca i pozycja z kartoteki projektu (wartość umowy).
     * Rozliczeniowa (ROZ) od razu odlicza wystawione zaliczkowe tego projektu.
     */
    public function applyProject(Project $project, InvoiceKind $kind): void
    {
        $this->contractor_id = (string) $project->contractor_id;
        $this->project_id = (string) $project->id;
        $this->kind = $kind->value;
        $this->applyContractor();

        if (filled($project->contract_currency)) {
            $this->currency = (string) $project->contract_currency;
        }

        $vatCode = $project->contractor->vat_code;

        $this->items = [[
            'name' => $project->fullName(),
            'unit' => 'szt.',
            'quantity' => '1',
            'unit_price' => (string) ($project->contract_value ?? ''),
            'vat_code' => $vatCode->value,
        ]];

        if ($kind === InvoiceKind::Final) {
            $this->advance_ids = array_values($project->invoices()
                ->where('kind', InvoiceKind::Advance)
                ->where('status', InvoiceStatus::Issued)
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all());
        }
    }

    public function addItem(): void
    {
        $lastCode = $this->items === [] ? null : VatCode::tryFrom(end($this->items)['vat_code']);

        $this->items[] = $this->emptyItem($lastCode ?? CompanySetting::current()->default_vat_code);
    }

    public function removeItem(int $index): void
    {
        $items = $this->items;
        unset($items[$index]);

        $this->items = array_values($items);
    }

    /**
     * Bieżące zestawienie VAT (podgląd w formularzu, przed zapisem).
     */
    public function summary(): VatSummary
    {
        $after = VatSummary::fromLines($this->lines($this->items));

        return match (InvoiceKind::tryFrom($this->kind)) {
            InvoiceKind::Correction => $after->minus(VatSummary::fromLines($this->lines($this->before_items))),
            InvoiceKind::Advance => is_numeric($this->advance_amount) ? VatSummary::advance($after, (string) $this->advance_amount) : VatSummary::empty(),
            InvoiceKind::Final => Invoice::query()
                ->with('items')
                ->whereIn('id', array_map('intval', $this->advance_ids))
                ->get()
                ->reduce(fn (VatSummary $rest, Invoice $advance) => $rest->minus($advance->summary()), $after),
            default => $after,
        };
    }

    /**
     * Wartość zamówienia (pełne pozycje) — przy zaliczkowej i rozliczeniowej.
     */
    public function orderSummary(): VatSummary
    {
        return VatSummary::fromLines($this->lines($this->items));
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $purchase = $this->direction === InvoiceDirection::Purchase->value;

        return [
            'direction' => ['required', Rule::enum(InvoiceDirection::class)],
            'kind' => ['required', Rule::enum(InvoiceKind::class)],
            'contractor_id' => ['required', 'integer', 'exists:contractors,id'],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('contractor_id', (int) $this->contractor_id)],
            'number' => [$purchase ? 'required' : 'nullable', 'string', 'max:100'],
            'issue_date' => ['required', 'date'],
            'sale_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'issue_place' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'paid_on' => ['nullable', 'date'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0', 'max:999999', 'decimal:0,4'],
            'exchange_rate_date' => ['nullable', 'date'],
            'exchange_rate_table' => ['nullable', 'string', 'max:30'],
            'language' => ['required', Rule::enum(InvoiceLanguage::class)],
            'advance_amount' => [Rule::requiredIf($this->kind === InvoiceKind::Advance->value), 'nullable', 'numeric', 'gt:0', 'max:999999999', 'decimal:0,2'],
            'advance_ids' => [Rule::requiredIf($this->kind === InvoiceKind::Final->value && ! $purchase), 'array'],
            'advance_ids.*' => ['integer', $this->advanceRule()],
            'corrected_number' => [Rule::requiredIf($this->kind === InvoiceKind::Correction->value), 'nullable', 'string', 'max:100'],
            'corrected_issue_date' => [Rule::requiredIf($this->kind === InvoiceKind::Correction->value), 'nullable', 'date'],
            'corrected_ksef_number' => ['nullable', 'string', 'max:50'],
            'correction_reason' => [Rule::requiredIf($this->kind === InvoiceKind::Correction->value), 'nullable', 'string', 'max:255'],
            'vat_exemption_basis' => [Rule::requiredIf($this->usesVatCode(VatCode::Exempt)), 'nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.name' => ['required', 'string', 'max:512'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.quantity' => ['required', 'numeric', 'between:-999999,999999', 'decimal:0,4'],
            'items.*.unit_price' => ['required', 'numeric', 'between:-999999999,999999999', 'decimal:0,2'],
            'items.*.vat_code' => ['required', Rule::enum(VatCode::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'items.*.name' => __('Description'),
            'items.*.unit' => __('Unit'),
            'items.*.quantity' => __('Quantity'),
            'items.*.unit_price' => __('Unit price'),
            'items.*.vat_code' => __('VAT rate'),
        ];
    }

    public function save(): Invoice
    {
        $this->validate();

        return DB::transaction(function () {
            $invoice = $this->invoice ?? new Invoice;
            $purchase = $this->direction === InvoiceDirection::Purchase->value;
            $bankAccount = $this->bank_account_id ? BankAccount::query()->find($this->bank_account_id) : null;

            $invoice->fill([
                'direction' => $this->direction,
                'kind' => $this->kind,
                'contractor_id' => (int) $this->contractor_id,
                'project_id' => $this->project_id ?: null,
                'number' => $purchase ? trim($this->number) : $invoice->number,
                'issue_date' => $this->issue_date,
                'sale_date' => $this->nullable($this->sale_date),
                'due_date' => $this->nullable($this->due_date),
                'issue_place' => $this->nullable($this->issue_place),
                'payment_method' => $this->payment_method,
                'bank_account' => $purchase ? null : Parties::bankAccount($bankAccount),
                'paid_on' => $this->nullable($this->paid_on),
                'currency' => strtoupper($this->currency),
                'exchange_rate' => strtoupper($this->currency) === 'PLN' ? null : $this->nullable($this->exchange_rate),
                'exchange_rate_date' => strtoupper($this->currency) === 'PLN' ? null : $this->nullable($this->exchange_rate_date),
                'exchange_rate_table' => strtoupper($this->currency) === 'PLN' ? null : $this->nullable($this->exchange_rate_table),
                'language' => $this->language,
                'advance_amount' => $this->kind === InvoiceKind::Advance->value ? $this->nullable($this->advance_amount) : null,
                'corrected_invoice_id' => $this->kind === InvoiceKind::Correction->value ? $this->corrected_invoice_id : null,
                'corrected_number' => $this->kind === InvoiceKind::Correction->value ? $this->nullable($this->corrected_number) : null,
                'corrected_issue_date' => $this->kind === InvoiceKind::Correction->value ? $this->nullable($this->corrected_issue_date) : null,
                'corrected_ksef_number' => $this->kind === InvoiceKind::Correction->value ? $this->nullable($this->corrected_ksef_number) : null,
                'correction_reason' => $this->kind === InvoiceKind::Correction->value ? $this->nullable($this->correction_reason) : null,
                'vat_exemption_basis' => $this->usesVatCode(VatCode::Exempt) ? $this->nullable($this->vat_exemption_basis) : null,
                'notes' => $this->nullable($this->notes),
            ]);

            Parties::apply($invoice);

            // Faktura zakupu nie ma szkicu — zapis = wprowadzenie do rejestru.
            if ($purchase && ! $invoice->exists) {
                $invoice->forceFill(['status' => InvoiceStatus::Issued, 'source' => InvoiceSource::Manual, 'issued_at' => now(), 'issued_by' => Auth::id()]);
            }

            $invoice->save();

            $invoice->items()->delete();

            foreach ([true => $this->before_items, false => $this->items] as $isBefore => $rows) {
                if ($isBefore && $this->kind !== InvoiceKind::Correction->value) {
                    continue;
                }

                foreach ($rows as $index => $row) {
                    $invoice->items()->create([
                        'position' => $index + 1,
                        'is_before' => (bool) $isBefore,
                        'name' => trim($row['name']),
                        'unit' => $this->nullable($row['unit']),
                        'quantity' => $row['quantity'],
                        'unit_price' => $row['unit_price'],
                        'vat_code' => $row['vat_code'],
                    ]);
                }
            }

            $invoice->advances()->sync($this->kind === InvoiceKind::Final->value ? array_map('intval', $this->advance_ids) : []);
            $invoice->refreshTotals();

            $this->invoice = $invoice;

            return $invoice;
        });
    }

    /**
     * @return array{name: string, unit: string, quantity: string, unit_price: string, vat_code: string}
     */
    private function emptyItem(VatCode $vatCode): array
    {
        return ['name' => '', 'unit' => 'szt.', 'quantity' => '1', 'unit_price' => '', 'vat_code' => $vatCode->value];
    }

    /**
     * @return list<array{name: string, unit: string, quantity: string, unit_price: string, vat_code: string}>
     */
    private function itemRows(Invoice $invoice, bool $before): array
    {
        return array_values($invoice->items
            ->where('is_before', $before)
            ->sortBy('position')
            ->map(fn (InvoiceItem $item) => [
                'name' => $item->name,
                'unit' => (string) $item->unit,
                'quantity' => $item->quantityLabel(),
                'unit_price' => $item->unit_price,
                'vat_code' => $item->vat_code->value,
            ])
            ->all());
    }

    /**
     * Pozycje z formularza jako wiersze do zestawienia VAT (błędne kwoty pomijane).
     *
     * @param  list<array{name: string, unit: string, quantity: string, unit_price: string, vat_code: string}>  $rows
     * @return list<array{net: string, vat_code: VatCode}>
     */
    private function lines(array $rows): array
    {
        $lines = [];

        foreach ($rows as $row) {
            $code = VatCode::tryFrom($row['vat_code']);

            if ($code === null || ! is_numeric($row['quantity']) || ! is_numeric($row['unit_price'])) {
                continue;
            }

            $lines[] = ['net' => (string) InvoiceItem::netOf((string) $row['quantity'], (string) $row['unit_price']), 'vat_code' => $code];
        }

        return $lines;
    }

    private function usesVatCode(VatCode $code): bool
    {
        foreach ($this->items as $item) {
            if ($item['vat_code'] === $code->value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rozliczana zaliczkowa: wystawiona, tego samego nabywcy i waluty.
     */
    private function advanceRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $valid = Invoice::query()
                ->sales()
                ->whereKey($value)
                ->where('kind', InvoiceKind::Advance)
                ->where('status', InvoiceStatus::Issued)
                ->where('contractor_id', $this->contractor_id)
                ->where('currency', strtoupper($this->currency))
                ->exists();

            if (! $valid) {
                $fail(__('Choose issued advance invoices of this buyer in the invoice currency.'));
            }
        };
    }

    /**
     * @param  array<string, string|null>|null  $snapshot
     */
    private function bankAccountIdOf(?array $snapshot): ?string
    {
        if ($snapshot === null || blank($snapshot['iban'] ?? null)) {
            return null;
        }

        $id = BankAccount::query()->where('iban', $snapshot['iban'])->value('id');

        return $id !== null ? (string) $id : null;
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
