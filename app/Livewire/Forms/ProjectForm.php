<?php

namespace App\Livewire\Forms;

use App\Enums\ProjectBillingType;
use App\Enums\ProjectStatus;
use App\Models\Contractor;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Form;

class ProjectForm extends Form
{
    public ?Project $project = null;

    public ?string $contractor_id = null;

    public string $number = '';

    public string $name = '';

    public string $invoice_label = '';

    public string $site_name = '';

    public string $site_street = '';

    public string $site_zip = '';

    public string $site_city = '';

    public string $site_country = '';

    public string $billing_type = 'hourly';

    public string $km_one_way = '';

    public bool $mileage_default = false;

    public string $status = 'active';

    public string $contract_value = '';

    public string $contract_currency = '';

    public string $notes = '';

    /** @var list<string> */
    public array $user_ids = [];

    public function setProject(?Project $project): void
    {
        $this->project = $project;

        if ($project === null) {
            return;
        }

        $this->fill([
            'contractor_id' => (string) $project->contractor_id,
            'number' => $project->number,
            'name' => $project->name,
            'invoice_label' => (string) $project->invoice_label,
            'site_name' => (string) $project->site_name,
            'site_street' => (string) $project->site_street,
            'site_zip' => (string) $project->site_zip,
            'site_city' => (string) $project->site_city,
            'site_country' => (string) $project->site_country,
            'billing_type' => $project->billing_type->value,
            'km_one_way' => $project->km_one_way === null ? '' : rtrim(rtrim((string) $project->km_one_way, '0'), '.'),
            'mileage_default' => $project->mileage_default,
            'status' => $project->status->value,
            'contract_value' => (string) $project->contract_value,
            'contract_currency' => (string) $project->contract_currency,
            'notes' => (string) $project->notes,
            'user_ids' => $project->users()->pluck('users.id')->map(fn (int $id) => (string) $id)->all(),
        ]);
    }

    /**
     * Nowy projekt dziedziczy walutę umowy i kraj budowy po kliencie.
     */
    public function applyContractorDefaults(): void
    {
        $contractor = Contractor::find($this->contractor_id);

        if ($contractor === null) {
            return;
        }

        if ($this->site_country === '') {
            $this->site_country = $contractor->country_code;
        }

        if ($this->contract_currency === '') {
            $this->contract_currency = $contractor->currency;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'contractor_id' => ['required', 'integer', Rule::exists('contractors', 'id')],
            'number' => [
                'required', 'string', 'max:30',
                Rule::unique('projects', 'number')
                    ->where('contractor_id', $this->contractor_id)
                    ->ignore($this->project?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'invoice_label' => ['nullable', 'string', 'max:255'],
            'site_name' => ['nullable', 'string', 'max:255'],
            'site_street' => ['nullable', 'string', 'max:255'],
            'site_zip' => ['nullable', 'string', 'max:20'],
            'site_city' => ['nullable', 'string', 'max:255'],
            'site_country' => ['nullable', 'string', 'size:2', 'alpha'],
            'billing_type' => ['required', Rule::enum(ProjectBillingType::class)],
            'km_one_way' => ['nullable', 'numeric', 'min:0', 'max:99999', 'decimal:0,1'],
            'mileage_default' => ['boolean'],
            'status' => ['required', Rule::enum(ProjectStatus::class)],
            'contract_value' => [
                Rule::requiredIf($this->billing_type === ProjectBillingType::Fixed->value),
                'nullable', 'numeric', 'min:0', 'max:999999999999', 'decimal:0,2',
            ],
            'contract_currency' => ['nullable', 'required_with:contract_value', 'string', 'size:3', 'alpha'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'user_ids' => ['array'],
            'user_ids.*' => ['integer', Rule::exists('users', 'id')],
        ];
    }

    public function save(): Project
    {
        $this->validate();

        return DB::transaction(function () {
            $project = $this->project ?? new Project;

            $project->fill([
                'contractor_id' => (int) $this->contractor_id,
                'number' => trim($this->number),
                'name' => trim($this->name),
                'invoice_label' => $this->clean($this->invoice_label),
                'site_name' => $this->clean($this->site_name),
                'site_street' => $this->clean($this->site_street),
                'site_zip' => $this->clean($this->site_zip),
                'site_city' => $this->clean($this->site_city),
                'site_country' => $this->clean(strtoupper($this->site_country)),
                'billing_type' => $this->billing_type,
                'km_one_way' => $this->clean($this->km_one_way),
                'mileage_default' => $this->mileage_default,
                'status' => $this->status,
                'contract_value' => $this->clean($this->contract_value),
                'contract_currency' => $this->clean(strtoupper($this->contract_currency)),
                'notes' => $this->clean($this->notes),
            ])->save();

            $project->users()->sync(array_map('intval', $this->user_ids));

            return $this->project = $project;
        });
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
