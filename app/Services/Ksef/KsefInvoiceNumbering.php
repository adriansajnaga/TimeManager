<?php

namespace App\Services\Ksef;

use App\Enums\InvoiceKind;
use App\Models\Invoice;
use App\Services\Invoices\InvoiceNumbering;
use Carbon\CarbonImmutable;

/**
 * Numer „{nr}/{miesiąc}/{rok}” = najwyższy numer z KSeF w miesiącu wystawienia + 1 (decyzja 5).
 * KSeF jest rejestrem faktur także spoza aplikacji (PM, Aplikacja Podatnika), więc lokalna baza
 * nie bierze udziału w liczeniu. Bez połączenia numeru nie zgadujemy (decyzja 4).
 */
class KsefInvoiceNumbering implements InvoiceNumbering
{
    public function __construct(private readonly KsefClient $client) {}

    public function next(Invoice $invoice): string
    {
        if (! $this->client->settings()->isConfigured()) {
            throw new KsefException(__('Invoice numbers are assigned from KSeF. Configure the KSeF connection first.'));
        }

        $month = CarbonImmutable::parse($invoice->issue_date);

        try {
            $highest = $this->highestInKsef($month);
        } catch (KsefException $exception) {
            throw new KsefException(__('Could not read the numbering from KSeF: :message Try again in a moment.', ['message' => $exception->getMessage()]));
        }

        $number = ($highest + 1).'/'.$month->month.'/'.$month->year;

        $this->refuseIfTaken($number, $invoice);

        return $number;
    }

    /**
     * KSeF nie wie o fakturze, która czeka w aplikacji na przyjęcie, więc podałby zajęty numer.
     */
    private function refuseIfTaken(string $number, Invoice $invoice): void
    {
        $taken = Invoice::query()
            ->sales()
            ->whereKeyNot($invoice->id)
            ->where('kind', '!=', InvoiceKind::Proforma)
            ->where('number', $number)
            ->first();

        if ($taken === null) {
            return;
        }

        throw new KsefException($taken->ksef_number === null
            ? __('KSeF gave number :number, but invoice :number is waiting in the application and is not in KSeF yet. Check its KSeF status first.', ['number' => $number])
            : __('KSeF gave number :number, which already exists in the application. Download invoices from KSeF and try again.', ['number' => $number]));
    }

    private function highestInKsef(CarbonImmutable $month): int
    {
        $highest = 0;
        $offset = 0;

        do {
            $page = $this->client->queryInvoiceMetadata($month->startOfMonth(), $month->endOfMonth(), 'Subject1', $offset);

            foreach ($page['invoices'] as $metadata) {
                $highest = max($highest, self::position((string) ($metadata['invoiceNumber'] ?? ''), $month));
            }

            $offset += count($page['invoices']);
        } while ($page['hasMore'] && $page['invoices'] !== []);

        return $highest;
    }

    /**
     * Pozycja numeru w serii danego miesiąca (inne formaty i miesiące = 0).
     */
    public static function position(string $number, CarbonImmutable $month): int
    {
        if (preg_match('#^(\d+)/(\d{1,2})/(\d{4})$#', trim($number), $matches) !== 1) {
            return 0;
        }

        return (int) $matches[2] === $month->month && (int) $matches[3] === $month->year ? (int) $matches[1] : 0;
    }
}
