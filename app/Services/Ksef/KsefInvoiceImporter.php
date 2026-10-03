<?php

namespace App\Services\Ksef;

use App\Enums\InvoiceDirection;
use App\Enums\InvoiceLanguage;
use App\Enums\InvoiceSource;
use App\Enums\InvoiceStatus;
use App\Enums\KsefStatus;
use App\Models\Contractor;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Invoices\Parties;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pobiera z KSeF faktury sprzedaży (Subject1 — także wystawione w PM i Aplikacji Podatnika)
 * i zakupu (Subject2). KSeF jest źródłem prawdy: dokument zapisujemy tak, jak tam leży.
 */
class KsefInvoiceImporter
{
    /** Zakres jednego zapytania o metadane (KSeF ogranicza go do ok. 3 miesięcy). */
    private const MAX_RANGE_DAYS = 90;

    public function __construct(
        private readonly KsefClient $client,
        private readonly Fa3InvoiceReader $reader,
    ) {}

    /**
     * @return array{sales: int, purchases: int, known: int, confirmed: int, failed: array<string, string>}
     */
    public function import(CarbonInterface $from, CarbonInterface $to): array
    {
        $summary = ['sales' => 0, 'purchases' => 0, 'known' => 0, 'confirmed' => 0, 'failed' => []];

        foreach (['Subject1' => InvoiceDirection::Sales, 'Subject2' => InvoiceDirection::Purchase] as $subject => $direction) {
            foreach ($this->windows($from, $to) as [$windowFrom, $windowTo]) {
                $offset = 0;

                do {
                    $page = $this->client->queryInvoiceMetadata($windowFrom, $windowTo, $subject, $offset);

                    foreach ($page['invoices'] as $metadata) {
                        $this->importOne($metadata, $direction, $summary);
                    }

                    $offset += count($page['invoices']);
                } while ($page['hasMore'] && $page['invoices'] !== []);
            }
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array{sales: int, purchases: int, known: int, confirmed: int, failed: array<string, string>}  $summary
     */
    private function importOne(array $metadata, InvoiceDirection $direction, array &$summary): void
    {
        $ksefNumber = isset($metadata['ksefNumber']) ? (string) $metadata['ksefNumber'] : null;

        if ($ksefNumber === null) {
            return;
        }

        if (Invoice::query()->withoutGlobalScopes()->where('ksef_number', $ksefNumber)->exists()) {
            $summary['known']++;

            return;
        }

        // Faktura wystawiona tutaj, która czekała na weryfikację — uzupełniamy numer KSeF.
        $waiting = $direction === InvoiceDirection::Sales
            ? Invoice::query()->sales()->whereNull('ksef_number')->where('status', InvoiceStatus::Issued)
                ->where('number', (string) ($metadata['invoiceNumber'] ?? ''))->first()
            : null;

        try {
            $xml = $this->client->downloadInvoice($ksefNumber);

            if ($waiting !== null) {
                $waiting->forceFill(['ksef_number' => $ksefNumber, 'ksef_status' => KsefStatus::Accepted, 'xml' => $xml, 'ksef_error' => null])->save();
                $summary['confirmed']++;

                return;
            }

            $this->store($xml, $ksefNumber, $direction);
            $summary[$direction === InvoiceDirection::Sales ? 'sales' : 'purchases']++;
        } catch (Throwable $exception) {
            report($exception);
            $summary['failed'][$ksefNumber] = self::reason($exception);
        }
    }

    /**
     * Krótki powód błędu dla użytkownika (bez treści zapytań SQL).
     */
    public static function reason(Throwable $exception): string
    {
        $message = trim(strtok($exception->getMessage(), PHP_EOL) ?: '');

        if ($exception instanceof QueryException) {
            $message = (string) preg_replace('/\s*\(Connection:.*$/s', '', $message);
        }

        return mb_substr(class_basename($exception).': '.$message, 0, 300);
    }

    public function store(string $xml, string $ksefNumber, InvoiceDirection $direction): Invoice
    {
        $data = $this->reader->read($xml);
        $attributes = $data['attributes'];
        $counterparty = $direction === InvoiceDirection::Sales ? $attributes['buyer'] : $attributes['seller'];

        return DB::transaction(function () use ($data, $attributes, $counterparty, $direction, $ksefNumber, $xml) {
            $invoice = new Invoice;
            $invoice->forceFill([
                ...$attributes,
                'direction' => $direction,
                'status' => InvoiceStatus::Issued,
                'source' => InvoiceSource::Ksef,
                'language' => InvoiceLanguage::Polish,
                'contractor_id' => $this->contractorId($counterparty),
                'counterparty_name' => $counterparty['name'] ?? null,
                'counterparty_tax_id' => Parties::taxId($counterparty),
                'corrected_invoice_id' => $attributes['corrected_ksef_number'] !== null
                    ? Invoice::query()->where('ksef_number', $attributes['corrected_ksef_number'])->value('id')
                    : null,
                'issued_at' => CarbonImmutable::parse((string) $attributes['issue_date']),
                'ksef_status' => KsefStatus::Accepted,
                'ksef_number' => $ksefNumber,
                'ksef_environment' => $this->client->settings()->environment,
                'xml' => $xml,
            ])->save();

            foreach ($data['items'] as $index => $item) {
                $created = $invoice->items()->create([...$item, 'position' => $index + 1]);
                // Wartość wiersza z XML (wiążąca), nie przeliczona z ceny jednostkowej.
                InvoiceItem::query()->whereKey($created->id)->update(['net' => $item['net']]);
            }

            $advances = Invoice::query()
                ->where('direction', $direction)
                ->where(fn ($query) => $query->whereIn('ksef_number', $data['advance_ksef_numbers'])->orWhereIn('number', $data['advance_numbers']))
                ->pluck('id');

            $invoice->advances()->sync($advances);

            return $invoice;
        });
    }

    /**
     * Kontrahent z kartoteki po numerze podatkowym (bez zakładania nowych).
     *
     * @param  array<string, string|null>  $party
     */
    private function contractorId(array $party): ?int
    {
        $taxId = preg_replace('/[^0-9A-Za-z]/', '', (string) ($party['tax_id'] ?? '')) ?? '';

        if ($taxId === '') {
            return null;
        }

        $id = Contractor::query()
            ->whereRaw("REPLACE(REPLACE(tax_id, ' ', ''), '-', '') = ?", [$taxId])
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function windows(CarbonInterface $from, CarbonInterface $to): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->endOfDay();
        $windows = [];

        while ($start->lte($end)) {
            $stop = $start->addDays(self::MAX_RANGE_DAYS - 1)->endOfDay();
            $windows[] = [$start, $stop->gt($end) ? $end : $stop];
            $start = $stop->addSecond();
        }

        return $windows;
    }
}
