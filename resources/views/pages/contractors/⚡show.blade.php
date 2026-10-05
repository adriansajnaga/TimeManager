<?php

use App\Models\Contracts\Attachable;
use App\Livewire\ComponentWithAttachments;
use App\Models\Contractor;
use App\Models\Invoice;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

new class extends ComponentWithAttachments {
    public Contractor $contractor;

    public function mount(Contractor $contractor): void
    {
        $this->contractor = $contractor;
    }

    /**
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projects(): Collection
    {
        return $this->contractor->projects()->latest('id')->limit(10)->get();
    }

    /**
     * @return Collection<int, Invoice>
     */
    #[Computed]
    public function invoices(): Collection
    {
        if (! auth()->user()->can('manage-invoices')) {
            return collect();
        }

        return Invoice::query()->where('contractor_id', $this->contractor->id)
            ->latest('issue_date')->latest('id')->limit(8)->get();
    }

    protected function attachmentOwner(): (Model&Attachable)|null
    {
        return $this->contractor;
    }

    protected function attachmentPermission(): string
    {
        return 'manage-contractors';
    }

    public function render()
    {
        return $this->view()->title($this->contractor->name);
    }
}; ?>

@php
    $c = $contractor;
    $address = collect([$c->street, trim($c->zip.' '.$c->city), strtoupper($c->country_code)])->filter()->implode(', ');
    $row = fn (string $label, ?string $value) => ['label' => $label, 'value' => filled($value) ? $value : '—'];
@endphp

<section class="w-full max-w-6xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-4">
            @if ($c->logo_path)
                <img src="{{ route('contractors.logo', $c) }}" alt="" class="h-12 max-w-32 rounded object-contain">
            @endif
            <div>
                <flux:heading size="xl" level="1" class="flex flex-wrap items-center gap-2">
                    {{ $c->name }}
                    <flux:badge size="sm">{{ $c->type->label() }}</flux:badge>
                    @unless ($c->is_active)
                        <flux:badge size="sm" color="zinc">{{ __('Inactive') }}</flux:badge>
                    @endunless
                </flux:heading>
                <flux:subheading>
                    <flux:link :href="route('contractors.index')" wire:navigate>{{ __('Contractors') }}</flux:link>
                    @if ($address !== '')
                        · {{ $address }}
                    @endif
                </flux:subheading>
            </div>
        </div>

        <flux:button variant="primary" icon="pencil-square" :href="route('contractors.edit', $c)" wire:navigate>{{ __('Edit') }}</flux:button>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <flux:card class="space-y-3">
            <flux:heading>{{ __('Contact') }}</flux:heading>
            <dl class="grid grid-cols-[9rem_1fr] gap-x-4 gap-y-2 text-sm">
                @foreach ([
                    $row(__('Tax ID'), $c->vatId()),
                    $row(__('E-mail'), $c->email),
                    $row(__('Phone'), $c->phone),
                    $row(__('Website'), $c->website),
                    $row(__('Invoice e-mails'), implode(', ', $c->email_to ?? [])),
                ] as $item)
                    <dt class="text-zinc-500">{{ $item['label'] }}</dt>
                    <dd class="min-w-0 break-words">{{ $item['value'] }}</dd>
                @endforeach
            </dl>
        </flux:card>

        <flux:card class="space-y-3">
            <flux:heading>{{ __('Billing') }}</flux:heading>
            <dl class="grid grid-cols-[9rem_1fr] gap-x-4 gap-y-2 text-sm">
                @foreach ([
                    $row(__('Hourly rate'), $c->hourly_rate !== null ? $c->hourly_rate.' '.$c->currency : null),
                    $row(__('Mileage rate'), $c->km_rate !== null ? $c->km_rate.' '.$c->currency.' / km' : null),
                    $row(__('VAT'), $c->vat_code->label()),
                    $row(__('Payment term'), trans_choice(':count day|:count days', $c->payment_days, ['count' => $c->payment_days])),
                    $row(__('Invoice language'), $c->invoice_language->label()),
                    $row(__('Invoice lines'), $c->invoice_line_mode->label()),
                ] as $item)
                    <dt class="text-zinc-500">{{ $item['label'] }}</dt>
                    <dd class="min-w-0 break-words">{{ $item['value'] }}</dd>
                @endforeach
            </dl>
        </flux:card>
    </div>

    <x-attachments :owner="$c" :uploads="$uploads" :heading="__('Attached documents')" />

    <div class="grid gap-6 lg:grid-cols-2">
        <flux:card class="space-y-3">
            <flux:heading>{{ __('Projects') }} <span class="text-zinc-400">({{ $c->projects()->count() }})</span></flux:heading>
            @forelse ($this->projects as $project)
                <div wire:key="project-{{ $project->id }}" class="flex items-center justify-between gap-3 text-sm">
                    @can('manage-projects')
                        <flux:link :href="route('projects.show', $project)" wire:navigate class="truncate">{{ $project->fullName() }}</flux:link>
                    @else
                        <span class="truncate">{{ $project->fullName() }}</span>
                    @endcan
                    <flux:badge size="sm">{{ $project->status->label() }}</flux:badge>
                </div>
            @empty
                <flux:text size="sm">{{ __('No projects yet.') }}</flux:text>
            @endforelse
        </flux:card>

        @can('manage-invoices')
            <flux:card class="space-y-3">
                <flux:heading>{{ __('Recent invoices') }}</flux:heading>
                @forelse ($this->invoices as $invoice)
                    <div wire:key="invoice-{{ $invoice->id }}" class="flex items-center justify-between gap-3 text-sm">
                        <flux:link :href="route('invoices.show', $invoice)" wire:navigate class="truncate">{{ $invoice->displayNumber() }}</flux:link>
                        <span class="text-zinc-500">{{ $invoice->issue_date->format('d.m.Y') }}</span>
                        <span class="whitespace-nowrap">{{ number_format((float) $invoice->gross, 2, ',', ' ') }} {{ $invoice->currency }}</span>
                        <flux:badge size="sm" :color="$invoice->status->color()">{{ $invoice->status->label() }}</flux:badge>
                    </div>
                @empty
                    <flux:text size="sm">{{ __('No invoices yet.') }}</flux:text>
                @endforelse
            </flux:card>
        @endcan
    </div>

    @if (filled($c->notes))
        <flux:card class="space-y-2">
            <flux:heading>{{ __('Notes') }}</flux:heading>
            <div class="whitespace-pre-line text-sm">{{ $c->notes }}</div>
        </flux:card>
    @endif
</section>
