<?php

namespace App\Services\Invoices;

use App\Enums\InvoiceDirection;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\VatCode;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Ksef\KsefRejectedException;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Wystawienie szkicu faktury sprzedaży: kontrola kompletności, kopia danych stron, numer.
 * Faktury VAT/KOR/ZAL/ROZ dostają numer z KSeF, proformy — z własnej serii „PF {nr}/{miesiąc}/{rok}”.
 */
final class InvoiceIssuer
{
    public function __construct(
        private readonly InvoiceNumbering $numbering,
        private readonly InvoiceTransmitter $transmitter,
    ) {}

    /**
     * @throws InvoiceException
     */
    public function issue(Invoice $invoice, User $user): Invoice
    {
        if (! $invoice->isSales() || ! $invoice->isDraft()) {
            throw new InvoiceException(__('Only draft sales invoices can be issued.'));
        }

        Parties::apply($invoice);
        $invoice->load(['items', 'advances.items']);

        $problems = $this->problems($invoice);

        if ($problems !== []) {
            throw new InvoiceException(implode(' ', $problems));
        }

        DB::transaction(function () use ($invoice, $user) {
            $number = $invoice->kind === InvoiceKind::Proforma
                ? $this->proformaNumber($invoice->issue_date)
                : $this->numbering->next($invoice);

            $invoice->forceFill([
                'number' => $number,
                'status' => InvoiceStatus::Issued,
                'issued_at' => now(),
                'issued_by' => $user->id,
                'ksef_error' => null,
            ])->save();

            $invoice->refreshTotals();
        });

        if ($invoice->kind->goesToKsef()) {
            try {
                $this->transmitter->send($invoice);
            } catch (InvoiceException $exception) {
                // Dokumentu nie ma w KSeF — wraca do szkicu, numer zostaje wolny.
                $this->backToDraft($invoice, $exception->getMessage());

                throw $exception;
            }
        }

        return $invoice;
    }

    /**
     * Ponowne pytanie KSeF o fakturę, która czekała na weryfikację.
     *
     * @throws InvoiceException
     */
    public function refreshKsefStatus(Invoice $invoice): Invoice
    {
        try {
            $this->transmitter->refresh($invoice);
        } catch (KsefRejectedException $exception) {
            $this->backToDraft($invoice, $exception->getMessage());

            throw $exception;
        }

        return $invoice;
    }

    private function backToDraft(Invoice $invoice, string $error): void
    {
        $invoice->forceFill([
            'number' => null,
            'status' => InvoiceStatus::Draft,
            'issued_at' => null,
            'issued_by' => null,
            'ksef_status' => null,
            'ksef_environment' => null,
            'ksef_session' => null,
            'ksef_reference' => null,
            'ksef_sent_at' => null,
            'ksef_error' => $error,
            'xml' => null,
        ])->save();
    }

    /**
     * Braki uniemożliwiające wystawienie (puste = można wystawić).
     *
     * @return list<string>
     */
    public function problems(Invoice $invoice): array
    {
        $problems = [];
        $items = $invoice->items->where('is_before', false);

        if (blank($invoice->buyer['name'] ?? null)) {
            $problems[] = __('Choose the buyer.');
        }

        if ($items->isEmpty()) {
            $problems[] = __('Add at least one item.');
        }

        if ($items->contains(fn ($item) => $item->vat_code === VatCode::Exempt) && blank($invoice->vat_exemption_basis)) {
            $problems[] = __('Enter the legal basis of the VAT exemption.');
        }

        if ($invoice->kind === InvoiceKind::Correction) {
            if (blank($invoice->correction_reason)) {
                $problems[] = __('Enter the reason for the correction.');
            }

            if (blank($invoice->corrected_number) || $invoice->corrected_issue_date === null) {
                $problems[] = __('Enter the number and date of the corrected invoice.');
            }
        }

        if ($invoice->kind === InvoiceKind::Advance) {
            $advance = BigDecimal::of($invoice->advance_amount ?? '0');

            if (! $advance->isPositive() || $advance->isGreaterThan($invoice->itemsSummary()->gross())) {
                $problems[] = __('The advance must be greater than zero and not exceed the order value.');
            }
        }

        if ($invoice->kind === InvoiceKind::Final && $invoice->advances->isEmpty()) {
            $problems[] = __('Choose the advance invoices settled by this invoice.');
        }

        if ($invoice->currency !== 'PLN' && $invoice->exchange_rate === null && ! $invoice->summary()->vat()->isZero()) {
            $problems[] = __('Foreign currency invoices with VAT need the exchange rate (VAT is shown in PLN).');
        }

        return $problems;
    }

    /**
     * Kolejny numer proformy w miesiącu wystawienia.
     */
    private function proformaNumber(CarbonInterface $issueDate): string
    {
        $highest = Invoice::query()
            ->where('direction', InvoiceDirection::Sales)
            ->where('kind', InvoiceKind::Proforma)
            ->whereNotNull('number')
            ->whereYear('issue_date', $issueDate->year)
            ->whereMonth('issue_date', $issueDate->month)
            ->lockForUpdate()
            ->pluck('number')
            ->map(fn (string $number) => preg_match('#^PF (\d+)/#', $number, $matches) ? (int) $matches[1] : 0)
            ->max() ?? 0;

        return sprintf('PF %d/%d/%d', $highest + 1, $issueDate->month, $issueDate->year);
    }
}
