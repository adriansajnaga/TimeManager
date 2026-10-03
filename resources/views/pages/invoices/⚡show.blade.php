<?php

use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\Invoices\InvoiceException;
use App\Services\Invoices\InvoiceIssuer;
use App\Services\Invoices\InvoiceMailer;
use App\Services\Invoices\Parties;
use Flux\Flux;
use Livewire\Component;

new class extends Component {
    public Invoice $invoice;

    public function mount(Invoice $invoice): void
    {
        $this->invoice = $invoice;
    }

    public function issue(InvoiceIssuer $issuer): void
    {
        $this->authorize('manage-invoices');

        try {
            $issuer->issue($this->invoice, auth()->user());
        } catch (InvoiceException $exception) {
            $this->addError('issue', $exception->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Invoice :number issued.', ['number' => $this->invoice->number]));
    }

    public function delete(): void
    {
        $this->authorize('manage-invoices');
        abort_unless($this->invoice->isSales() ? $this->invoice->isDraft() : true, 403);

        $direction = $this->invoice->direction;
        $this->invoice->delete();

        Flux::toast(variant: 'success', text: __('Invoice deleted.'));
        $this->redirectRoute('invoices.index', ['direction' => $direction->value], navigate: true);
    }

    /**
     * Anulować można wystawioną proformę; faktury z KSeF poprawia się korektą.
     */
    public function cancel(): void
    {
        $this->authorize('manage-invoices');
        abort_unless($this->invoice->kind === InvoiceKind::Proforma && $this->invoice->isIssued(), 403);

        $this->invoice->forceFill(['status' => InvoiceStatus::Cancelled, 'cancelled_at' => now()])->save();

        Flux::toast(text: __('Pro forma cancelled.'));
    }

    public string $mailTo = '';

    public string $mailCc = '';

    public string $mailSubject = '';

    public string $mailBody = '';

    public string $mailAttachment = '';

    /**
     * Podgląd e-maila z szablonów klienta — wysyłka dopiero po „Wyślij” (decyzja 6).
     */
    public function prepareEmail(InvoiceMailer $mailer): void
    {
        $this->authorize('manage-invoices');

        $draft = $mailer->draft($this->invoice, auth()->user());

        $this->mailTo = $draft['to'];
        $this->mailCc = $draft['cc'];
        $this->mailSubject = $draft['subject'];
        $this->mailBody = $draft['body'];
        $this->mailAttachment = $draft['attachment'];
        $this->resetErrorBag();

        Flux::modal('invoice-email')->show();
    }

    public function sendEmail(InvoiceMailer $mailer): void
    {
        $this->authorize('manage-invoices');

        $addresses = fn (string $value) => array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $value) ?: [])));

        $this->validate([
            'mailTo' => ['required', 'string', 'max:1000'],
            'mailCc' => ['nullable', 'string', 'max:1000'],
            'mailSubject' => ['required', 'string', 'max:255'],
            'mailBody' => ['required', 'string', 'max:10000'],
        ]);

        foreach (['mailTo', 'mailCc'] as $field) {
            foreach ($addresses($this->{$field}) as $address) {
                if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                    $this->addError($field, __('":address" is not a valid e-mail address.', ['address' => $address]));

                    return;
                }
            }
        }

        try {
            $mailer->send($this->invoice, auth()->user(), $addresses($this->mailTo), $addresses($this->mailCc), $this->mailSubject, $this->mailBody);
        } catch (InvoiceException $exception) {
            $this->addError('mail', $exception->getMessage());

            return;
        }

        Flux::modal('invoice-email')->close();
        Flux::toast(variant: 'success', text: __('E-mail sent to :to.', ['to' => $this->mailTo]));
    }

    public function refreshKsef(InvoiceIssuer $issuer): void
    {
        $this->authorize('manage-invoices');

        try {
            $issuer->refreshKsefStatus($this->invoice);
        } catch (InvoiceException $exception) {
            $this->addError('ksef', $exception->getMessage());

            return;
        }

        if ($this->invoice->isInKsef()) {
            Flux::toast(variant: 'success', text: __('The invoice is in KSeF: :number', ['number' => $this->invoice->ksef_number]));
        }
    }

    public function markPaid(bool $paid = true): void
    {
        $this->authorize('manage-invoices');
        abort_if($this->invoice->isDraft(), 403);

        $this->invoice->forceFill(['paid_on' => $paid ? today() : null])->save();
    }

    /**
     * Braki szkicu pokazywane przed wystawieniem.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->invoice->isSales() && $this->invoice->isDraft()
            ? app(InvoiceIssuer::class)->problems($this->invoice)
            : [];
    }

    public function render()
    {
        $this->invoice->load(['items', 'advances.items', 'contractor', 'correctedInvoice', 'corrections', 'settlements', 'settlement.workWeeks', 'emailLogs.sender']);

        return $this->view()->title($this->invoice->displayNumber());
    }
}; ?>

@php
    $summary = $invoice->summary();
    $money = fn ($value) => number_format((float) (string) $value, 2, ',', ' ').' '.$invoice->currency;
    $problems = $this->problems();
@endphp

<section class="w-full max-w-6xl space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <flux:heading size="xl" level="1">{{ $invoice->kind->label() }} {{ $invoice->displayNumber() }}</flux:heading>
                <flux:badge :color="$invoice->status->color()">{{ $invoice->status->label() }}</flux:badge>
                @if ($invoice->isIssued())
                    <flux:badge :color="$invoice->isPaid() ? 'green' : 'amber'">
                        {{ $invoice->isPaid() ? __('Paid :date', ['date' => $invoice->paid_on->format('d.m.Y')]) : __('Unpaid') }}
                    </flux:badge>
                @endif
                @if ($invoice->ksef_status)
                    <flux:badge :color="$invoice->ksef_status->color()" icon="shield-check">{{ $invoice->ksef_status->label() }}</flux:badge>
                @endif
                @if ($invoice->isFromTestKsef())
                    <flux:badge color="blue">{{ __('KSeF test') }}</flux:badge>
                @endif
            </div>
            @if ($invoice->ksef_number)
                <flux:text class="mt-1">
                    {{ __('KSeF number') }}: <strong>{{ $invoice->ksef_number }}</strong>
                    @if ($verifyUrl = app(\App\Services\Ksef\InvoiceQrCode::class)->url($invoice))
                        · <flux:link :href="$verifyUrl" target="_blank" rel="noopener">{{ __('Check in KSeF') }}</flux:link>
                    @endif
                </flux:text>
            @endif
            <flux:subheading>
                <flux:link :href="route('invoices.index', ['direction' => $invoice->direction->value])" wire:navigate>
                    {{ $invoice->isSales() ? __('Sales invoices') : __('Purchase invoices') }}
                </flux:link>
            </flux:subheading>
        </div>

        <div class="flex flex-wrap gap-2">
            @if ($invoice->isSales())
                <flux:button icon="document-arrow-down" :href="route('invoices.pdf', $invoice)" target="_blank">{{ __('PDF') }}</flux:button>
            @endif

            @if ($invoice->settlement)
                <flux:button icon="document-duplicate" :href="route('invoices.package', $invoice)" target="_blank">{{ __('Package (PDF)') }}</flux:button>
            @endif

            @if ($invoice->isSales() && $invoice->isIssued())
                <flux:button icon="envelope" wire:click="prepareEmail">{{ $invoice->emailed_at ? __('Send again') : __('Send by e-mail') }}</flux:button>
            @endif

            @if ($invoice->xml)
                <flux:button icon="code-bracket" :href="route('invoices.xml', $invoice)">{{ __('XML') }}</flux:button>
            @endif

            @if ($invoice->ksef_status === \App\Enums\KsefStatus::Pending)
                <flux:button icon="arrow-path" wire:click="refreshKsef">{{ __('Check KSeF status') }}</flux:button>
            @endif

            @if ($invoice->isEditable())
                <flux:button icon="pencil-square" :href="route('invoices.edit', $invoice)" wire:navigate>{{ __('Edit') }}</flux:button>
            @endif

            @if ($invoice->isSales() && $invoice->isDraft())
                <flux:button variant="primary" icon="check" wire:click="issue" wire:confirm="{{ __('Issue the invoice? A number will be assigned and the document can no longer be edited.') }}" data-test="issue-invoice-button">
                    {{ __('Issue') }}
                </flux:button>
            @endif

            <flux:dropdown position="bottom" align="end">
                <flux:button icon="ellipsis-horizontal" :aria-label="__('More')" />
                <flux:menu>
                    <flux:menu.item icon="document-duplicate" :href="route('invoices.create', ['copy' => $invoice->id])" wire:navigate>{{ __('Copy as new') }}</flux:menu.item>

                    @if ($invoice->isSales() && $invoice->isIssued() && $invoice->kind !== InvoiceKind::Proforma)
                        <flux:menu.item icon="receipt-refund" :href="route('invoices.create', ['correct' => $invoice->id])" wire:navigate>{{ __('Correct') }}</flux:menu.item>
                    @endif

                    @if ($invoice->isIssued())
                        @if ($invoice->isPaid())
                            <flux:menu.item icon="x-circle" wire:click="markPaid(false)">{{ __('Mark as unpaid') }}</flux:menu.item>
                        @else
                            <flux:menu.item icon="banknotes" wire:click="markPaid">{{ __('Mark as paid today') }}</flux:menu.item>
                        @endif
                    @endif

                    @if ($invoice->kind === InvoiceKind::Proforma && $invoice->isIssued())
                        <flux:menu.item icon="no-symbol" wire:click="cancel" wire:confirm="{{ __('Cancel this pro forma?') }}">{{ __('Cancel') }}</flux:menu.item>
                    @endif

                    @if (! $invoice->isSales() || $invoice->isDraft())
                        <flux:menu.separator />
                        <flux:menu.item icon="trash" variant="danger" wire:click="delete" wire:confirm="{{ __('Delete this invoice?') }}">{{ __('Delete') }}</flux:menu.item>
                    @endif
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    @error('issue')
        <flux:callout icon="exclamation-triangle" color="red" :heading="__('The invoice was not issued')">
            <flux:callout.text>{{ $message }}</flux:callout.text>
        </flux:callout>
    @else
        @if ($invoice->isDraft() && $invoice->ksef_error)
            <flux:callout icon="exclamation-triangle" color="red" :heading="__('Last attempt was rejected by KSeF')">
                <flux:callout.text>{{ $invoice->ksef_error }}</flux:callout.text>
            </flux:callout>
        @endif
    @enderror

    @error('ksef')
        <flux:callout icon="exclamation-triangle" color="red" :heading="__('KSeF')">
            <flux:callout.text>{{ $message }}</flux:callout.text>
        </flux:callout>
    @enderror

    @if ($invoice->ksef_status === \App\Enums\KsefStatus::Pending)
        <flux:callout icon="clock" color="amber" :heading="__('Waiting for KSeF')">
            <flux:callout.text>{{ __('KSeF received the invoice but has not confirmed it yet. Check the status in a moment.') }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($problems !== [])
        <flux:callout icon="information-circle" color="amber" :heading="__('Missing before issuing')">
            <flux:callout.text>
                <ul class="list-disc ps-5">
                    @foreach ($problems as $problem)
                        <li>{{ $problem }}</li>
                    @endforeach
                </ul>
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        @foreach ([__('Seller') => $invoice->seller, __('Buyer') => $invoice->buyer] as $label => $party)
            <flux:card class="space-y-1">
                <flux:text size="sm">{{ $label }}</flux:text>
                <flux:heading>{{ $party['name'] ?? '—' }}</flux:heading>
                @foreach (Parties::addressLines($party) as $line)
                    <flux:text>{{ $line }}</flux:text>
                @endforeach
                @if (Parties::taxId($party))
                    <flux:text>{{ __('Tax ID') }}: {{ Parties::taxId($party) }}</flux:text>
                @endif
            </flux:card>
        @endforeach

        <flux:card class="space-y-1">
            <flux:text>{{ __('Issue date') }}: <strong>{{ $invoice->issue_date->format('d.m.Y') }}</strong></flux:text>
            @if ($invoice->sale_date)
                <flux:text>{{ __('Sale / completion date') }}: <strong>{{ $invoice->sale_date->format('d.m.Y') }}</strong></flux:text>
            @endif
            @if ($invoice->due_date)
                <flux:text>{{ __('Payment due') }}: <strong>{{ $invoice->due_date->format('d.m.Y') }}</strong></flux:text>
            @endif
            <flux:text>{{ __('Payment method') }}: {{ $invoice->payment_method->label() }}</flux:text>
            @if ($invoice->bank_account)
                <flux:text>{{ $invoice->bank_account['label'] }} – {{ $invoice->bank_account['iban'] }}</flux:text>
            @endif
            @if ($invoice->exchange_rate)
                <flux:text>{{ __('Rate') }}: {{ $invoice->exchange_rate }} PLN/{{ $invoice->currency }} ({{ $invoice->exchange_rate_table }}, {{ $invoice->exchange_rate_date?->format('d.m.Y') }})</flux:text>
            @endif
            @if ($invoice->issued_at)
                <flux:text size="sm">{{ __('Issued :date by :name', ['date' => $invoice->issued_at->format('d.m.Y H:i'), 'name' => $invoice->issuer?->name ?? '—']) }}</flux:text>
            @endif
        </flux:card>
    </div>

    @if ($invoice->kind === InvoiceKind::Correction)
        <flux:card class="space-y-1">
            <flux:text>
                {{ __('Corrects invoice') }}:
                @if ($invoice->correctedInvoice)
                    <flux:link :href="route('invoices.show', $invoice->correctedInvoice)" wire:navigate>{{ $invoice->corrected_number }}</flux:link>
                @else
                    <strong>{{ $invoice->corrected_number }}</strong>
                @endif
                {{ __('of :date', ['date' => $invoice->corrected_issue_date?->format('d.m.Y')]) }}
            </flux:text>
            <flux:text>{{ __('Reason for correction') }}: {{ $invoice->correction_reason }}</flux:text>
        </flux:card>
    @endif

    @foreach ([true => __('Before correction'), false => $invoice->kind === InvoiceKind::Correction ? __('After correction') : ($invoice->kind === InvoiceKind::Advance || $invoice->kind === InvoiceKind::Final ? __('Order items') : __('Items'))] as $before => $heading)
        @php($rows = $invoice->items->where('is_before', (bool) $before))
        @continue($rows->isEmpty())
        <flux:card class="space-y-3">
            <flux:heading size="lg">{{ $heading }}</flux:heading>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>#</flux:table.column>
                    <flux:table.column>{{ __('Description') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Quantity') }}</flux:table.column>
                    <flux:table.column>{{ __('Unit') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Unit price') }}</flux:table.column>
                    <flux:table.column>{{ __('VAT') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Net') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($rows as $item)
                        <flux:table.row :key="$item->id">
                            <flux:table.cell>{{ $item->position }}</flux:table.cell>
                            <flux:table.cell class="whitespace-pre-line">{{ $item->name }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $item->quantityLabel() }}</flux:table.cell>
                            <flux:table.cell>{{ $item->unit }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $money($item->unit_price) }}</flux:table.cell>
                            <flux:table.cell>{{ $item->vat_code->shortLabel() }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $money($item->net) }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @endforeach

    @if ($invoice->kind === InvoiceKind::Final && $invoice->advances->isNotEmpty())
        <flux:card class="space-y-1">
            <flux:heading>{{ __('Settled advance invoices') }}</flux:heading>
            @foreach ($invoice->advances as $advance)
                <flux:text>
                    <flux:link :href="route('invoices.show', $advance)" wire:navigate>{{ $advance->number }}</flux:link>
                    — {{ $advance->issue_date->format('d.m.Y') }} — {{ $money($advance->gross) }}
                </flux:text>
            @endforeach
        </flux:card>
    @endif

    <flux:card class="space-y-3">
        <flux:heading size="lg">
            {{ match ($invoice->kind) {
                InvoiceKind::Correction => __('Difference'),
                InvoiceKind::Advance => __('Advance'),
                InvoiceKind::Final => __('Remaining to pay'),
                default => __('Summary'),
            } }}
        </flux:heading>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('VAT rate') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Net') }}</flux:table.column>
                <flux:table.column align="end">{{ __('VAT') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Gross') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($summary->rows() as $row)
                    <flux:table.row :key="'vat-'.$row['code']->value">
                        <flux:table.cell>{{ $row['code']->label() }}</flux:table.cell>
                        <flux:table.cell align="end">{{ $money($row['net']) }}</flux:table.cell>
                        <flux:table.cell align="end">{{ $money($row['vat']) }}</flux:table.cell>
                        <flux:table.cell align="end">{{ $money($row['gross']) }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
                <flux:table.row>
                    <flux:table.cell variant="strong">{{ __('Total') }}</flux:table.cell>
                    <flux:table.cell align="end" variant="strong">{{ $money($summary->net()) }}</flux:table.cell>
                    <flux:table.cell align="end" variant="strong">{{ $money($summary->vat()) }}</flux:table.cell>
                    <flux:table.cell align="end" variant="strong">{{ $money($summary->gross()) }}</flux:table.cell>
                </flux:table.row>
            </flux:table.rows>
        </flux:table>

        @if ($invoice->notes)
            <flux:text class="whitespace-pre-line">{{ $invoice->notes }}</flux:text>
        @endif
    </flux:card>

    @if ($invoice->corrections->isNotEmpty() || $invoice->settlements->isNotEmpty())
        <flux:card class="space-y-1">
            <flux:heading>{{ __('Related documents') }}</flux:heading>
            @foreach ($invoice->corrections->merge($invoice->settlements) as $related)
                <flux:text>
                    {{ $related->kind->label() }}
                    <flux:link :href="route('invoices.show', $related)" wire:navigate>{{ $related->displayNumber() }}</flux:link>
                    — {{ $related->status->label() }}
                </flux:text>
            @endforeach
        </flux:card>
    @endif
    @if ($invoice->emailLogs->isNotEmpty())
        <flux:card class="space-y-2">
            <flux:heading>{{ __('E-mails') }}</flux:heading>
            @foreach ($invoice->emailLogs as $log)
                <flux:text>
                    @if ($log->sent_at)
                        <flux:badge size="sm" color="green">{{ $log->sent_at->format('d.m.Y H:i') }}</flux:badge>
                    @else
                        <flux:badge size="sm" color="red">{{ __('Failed') }}</flux:badge>
                    @endif
                    {{ implode(', ', $log->to) }} — {{ $log->subject }} ({{ $log->attachment }})
                    @if ($log->sender) · {{ $log->sender->name }} @endif
                    @if ($log->error) <br><span class="text-red-600">{{ $log->error }}</span> @endif
                </flux:text>
            @endforeach
        </flux:card>
    @endif

    <flux:modal name="invoice-email" class="md:w-[40rem]">
        <form wire:submit="sendEmail" class="space-y-5">
            <flux:heading size="lg">{{ __('Send by e-mail') }}</flux:heading>

            <flux:input wire:model="mailTo" :label="__('To')" :description="__('Several addresses separated by commas.')" />
            <flux:input wire:model="mailCc" :label="__('Copy (CC)')" />
            <flux:input wire:model="mailSubject" :label="__('Subject')" />
            <flux:textarea wire:model="mailBody" :label="__('Message')" rows="8" />

            <flux:text size="sm">{{ __('Attachment') }}: <strong>{{ $mailAttachment }}</strong></flux:text>

            <flux:error name="mail" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" icon="paper-airplane" wire:loading.attr="disabled" data-test="send-invoice-email">{{ __('Send') }}</flux:button>
            </div>
        </form>
    </flux:modal>

</section>
