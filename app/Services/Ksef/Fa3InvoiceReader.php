<?php

namespace App\Services\Ksef;

use App\Enums\InvoiceKind;
use App\Enums\PaymentMethod;
use App\Enums\VatCode;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use SimpleXMLElement;

/**
 * Odczyt faktury FA(2)/FA(3) z KSeF do danych aplikacji (nagłówek, strony, pozycje, sumy).
 * Sumy dokumentu bierzemy z XML — to one są wiążące, nie przeliczenie pozycji.
 */
class Fa3InvoiceReader
{
    /**
     * @return array{
     *     attributes: array<string, mixed>,
     *     items: list<array{is_before: bool, name: string, unit: string|null, quantity: string, unit_price: string, vat_code: VatCode, net: string}>,
     *     advance_ksef_numbers: list<string>,
     *     advance_numbers: list<string>
     * }
     */
    public function read(string $xml): array
    {
        $document = new SimpleXMLElement($xml);
        $namespace = $document->getDocNamespaces()[''] ?? Fa3InvoiceBuilder::NAMESPACE;
        $document->registerXPathNamespace('fa', $namespace);

        $value = function (string $path, ?SimpleXMLElement $context = null) use ($document, $namespace): ?string {
            $node = $context ?? $document;
            $node->registerXPathNamespace('fa', $namespace);
            $found = $node->xpath($path);

            if ($found === false || $found === null || $found === []) {
                return null;
            }

            $text = trim((string) $found[0]);

            return $text === '' ? null : $text;
        };

        $number = $value('/fa:Faktura/fa:Fa/fa:P_2');

        if ($number === null) {
            throw new KsefException(__('The KSeF document has no invoice number (P_2).'));
        }

        $kind = match ($value('/fa:Faktura/fa:Fa/fa:RodzajFaktury')) {
            'KOR', 'KOR_ZAL', 'KOR_ROZ' => InvoiceKind::Correction,
            'ZAL' => InvoiceKind::Advance,
            'ROZ' => InvoiceKind::Final,
            default => InvoiceKind::Vat,
        };

        $net = BigDecimal::zero();
        $vat = BigDecimal::zero();

        foreach ($document->xpath('/fa:Faktura/fa:Fa/*') ?: [] as $field) {
            $name = $field->getName();

            if (preg_match('/^P_13_\d+(_\d)?$/', $name) === 1) {
                $net = $net->plus($this->decimal((string) $field));
            } elseif (preg_match('/^P_14_\d$/', $name) === 1) {
                $vat = $vat->plus($this->decimal((string) $field));
            }
        }

        $gross = $value('/fa:Faktura/fa:Fa/fa:P_15');
        $issueDate = $value('/fa:Faktura/fa:Fa/fa:P_1');
        $saleDate = $value('/fa:Faktura/fa:Fa/fa:P_6') ?? $value('/fa:Faktura/fa:Fa/fa:OkresFa/fa:P_6_Do');
        $paid = $value('/fa:Faktura/fa:Fa/fa:Platnosc/fa:Zaplacono') === '1';

        $lineRate = $value('/fa:Faktura/fa:Fa/fa:FaWiersz/fa:KursWaluty') ?? $value('/fa:Faktura/fa:Fa/fa:KursWalutyZ');

        $attributes = [
            'kind' => $kind,
            'number' => $number,
            'seller' => $this->party($document, $namespace, 'Podmiot1'),
            'buyer' => $this->party($document, $namespace, 'Podmiot2'),
            'issue_date' => $issueDate,
            'sale_date' => $saleDate,
            'issue_place' => $value('/fa:Faktura/fa:Fa/fa:P_1M'),
            'due_date' => $value('/fa:Faktura/fa:Fa/fa:Platnosc/fa:TerminPlatnosci/fa:Termin'),
            'paid_on' => $paid ? $value('/fa:Faktura/fa:Fa/fa:Platnosc/fa:DataZaplaty') : null,
            'payment_method' => match ($value('/fa:Faktura/fa:Fa/fa:Platnosc/fa:FormaPlatnosci')) {
                '1' => PaymentMethod::Cash,
                '2' => PaymentMethod::Card,
                '7' => PaymentMethod::Mobile,
                default => PaymentMethod::Transfer,
            },
            'bank_account' => ($iban = $value('/fa:Faktura/fa:Fa/fa:Platnosc/fa:RachunekBankowy/fa:NrRB')) !== null
                ? ['label' => $value('/fa:Faktura/fa:Fa/fa:Platnosc/fa:RachunekBankowy/fa:NazwaBanku') ?? '', 'iban' => $iban, 'swift' => $value('/fa:Faktura/fa:Fa/fa:Platnosc/fa:RachunekBankowy/fa:SWIFT')]
                : null,
            'currency' => $value('/fa:Faktura/fa:Fa/fa:KodWaluty') ?? 'PLN',
            'exchange_rate' => $lineRate !== null ? (string) $this->decimal($lineRate)->toScale(4, RoundingMode::HalfUp) : null,
            'net' => (string) $net->toScale(2, RoundingMode::HalfUp),
            'vat' => (string) $vat->toScale(2, RoundingMode::HalfUp),
            'gross' => (string) ($gross !== null ? $this->decimal($gross) : $net->plus($vat))->toScale(2, RoundingMode::HalfUp),
            'advance_amount' => $kind === InvoiceKind::Advance && $gross !== null ? (string) $this->decimal($gross)->toScale(2, RoundingMode::HalfUp) : null,
            'corrected_number' => $value('/fa:Faktura/fa:Fa/fa:DaneFaKorygowanej/fa:NrFaKorygowanej'),
            'corrected_issue_date' => $value('/fa:Faktura/fa:Fa/fa:DaneFaKorygowanej/fa:DataWystFaKorygowanej'),
            'corrected_ksef_number' => $value('/fa:Faktura/fa:Fa/fa:DaneFaKorygowanej/fa:NrKSeFFaKorygowanej'),
            'correction_reason' => $value('/fa:Faktura/fa:Fa/fa:PrzyczynaKorekty'),
            'vat_exemption_basis' => $value('/fa:Faktura/fa:Fa/fa:Adnotacje/fa:Zwolnienie/fa:P_19A')
                ?? $value('/fa:Faktura/fa:Fa/fa:Adnotacje/fa:Zwolnienie/fa:P_19B')
                ?? $value('/fa:Faktura/fa:Fa/fa:Adnotacje/fa:Zwolnienie/fa:P_19C'),
            'notes' => $value('/fa:Faktura/fa:Stopka/fa:Informacje/fa:StopkaFaktury'),
        ];

        $items = [];
        $rows = $kind === InvoiceKind::Advance
            ? ($document->xpath('/fa:Faktura/fa:Fa/fa:Zamowienie/fa:ZamowienieWiersz') ?: [])
            : ($document->xpath('/fa:Faktura/fa:Fa/fa:FaWiersz') ?: []);
        $suffix = $kind === InvoiceKind::Advance ? 'Z' : '';

        foreach ($rows as $row) {
            $quantity = $this->decimal($value('fa:P_8B'.$suffix, $row) ?? '1');
            $lineNet = $value($kind === InvoiceKind::Advance ? 'fa:P_11NettoZ' : 'fa:P_11', $row);
            $unitPrice = $value('fa:P_9A'.$suffix, $row);

            if ($unitPrice === null && $lineNet !== null) {
                $unitPrice = (string) ($quantity->isZero() ? $this->decimal($lineNet) : $this->decimal($lineNet)->dividedBy($quantity, 2, RoundingMode::HalfUp));
            }

            $items[] = [
                'is_before' => $value('fa:StanPrzed'.$suffix, $row) === '1',
                'name' => $value('fa:P_7'.$suffix, $row) ?? '—',
                'unit' => $value('fa:P_8A'.$suffix, $row),
                'quantity' => (string) $quantity,
                'unit_price' => (string) $this->decimal($unitPrice ?? '0')->toScale(2, RoundingMode::HalfUp),
                'vat_code' => self::vatCode($value('fa:P_12'.$suffix, $row)),
                'net' => (string) $this->decimal($lineNet ?? '0')->toScale(2, RoundingMode::HalfUp),
            ];
        }

        $advanceKsef = [];
        $advanceNumbers = [];

        foreach ($document->xpath('/fa:Faktura/fa:Fa/fa:FakturaZaliczkowa') ?: [] as $reference) {
            if (($ksef = $value('fa:NrKSeFFaZaliczkowej', $reference)) !== null) {
                $advanceKsef[] = $ksef;
            } elseif (($plain = $value('fa:NrFaZaliczkowej', $reference)) !== null) {
                $advanceNumbers[] = $plain;
            }
        }

        return [
            'attributes' => $attributes,
            'items' => $items,
            'advance_ksef_numbers' => $advanceKsef,
            'advance_numbers' => $advanceNumbers,
        ];
    }

    /**
     * Stawka z P_12; stawki historyczne (22%, 7%) mapowane na obecne odpowiedniki.
     */
    public static function vatCode(?string $value): VatCode
    {
        $value = trim((string) $value);

        return VatCode::tryFrom($value) ?? match ($value) {
            '22' => VatCode::Rate23,
            '7' => VatCode::Rate8,
            '4', '3' => VatCode::Rate5,
            '0' => VatCode::ZeroDomestic,
            'np' => VatCode::OutsideScope,
            default => VatCode::OutsideScope,
        };
    }

    /**
     * @return array<string, string|null>
     */
    private function party(SimpleXMLElement $document, string $namespace, string $element): array
    {
        $nodes = $document->xpath('/fa:Faktura/fa:'.$element);
        $node = $nodes === false || $nodes === null || $nodes === [] ? null : $nodes[0];

        if ($node === null) {
            return [];
        }

        $node->registerXPathNamespace('fa', $namespace);
        $get = function (string $path) use ($node): ?string {
            $found = $node->xpath($path);

            return $found === false || $found === null || $found === [] ? null : (trim((string) $found[0]) ?: null);
        };

        $country = $get('fa:Adres/fa:KodKraju') ?? 'PL';
        $line2 = $get('fa:Adres/fa:AdresL2');
        [$zip, $city] = [null, $line2];

        if ($line2 !== null && preg_match('/^(\S*\d\S*)\s+(.+)$/u', $line2, $matches) === 1) {
            [$zip, $city] = [$matches[1], $matches[2]];
        }

        $nip = $get('fa:DaneIdentyfikacyjne/fa:NIP');

        return [
            'name' => $get('fa:DaneIdentyfikacyjne/fa:Nazwa'),
            'street' => $get('fa:Adres/fa:AdresL1'),
            'zip' => $zip,
            'city' => $city,
            'country_code' => $country,
            'vat_prefix' => $nip !== null ? 'PL' : ($get('fa:DaneIdentyfikacyjne/fa:KodUE') ?? $get('fa:DaneIdentyfikacyjne/fa:KodKraju')),
            'tax_id' => $nip ?? $get('fa:DaneIdentyfikacyjne/fa:NrVatUE') ?? $get('fa:DaneIdentyfikacyjne/fa:NrID'),
            'email' => $get('fa:DaneKontaktowe/fa:Email'),
            'phone' => $get('fa:DaneKontaktowe/fa:Telefon'),
        ];
    }

    private function decimal(string $value): BigDecimal
    {
        return BigDecimal::of(str_replace(',', '.', trim($value)));
    }
}
