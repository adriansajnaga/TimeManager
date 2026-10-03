<?php

namespace App\Services\Invoices;

use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Średnie kursy NBP (tabela A) przez API api.nbp.pl. Pobrane kursy zostają w bazie.
 */
final class NbpExchangeRates
{
    private const URL = 'https://api.nbp.pl/api/exchangerates/rates/a/%s/%s/%s/';

    /** Tyle dni wstecz szukamy kursu (długie weekendy i święta). */
    private const LOOKBACK_DAYS = 10;

    /**
     * Kurs z ostatniego dnia roboczego przed podaną datą (art. 31a ustawy o VAT).
     *
     * @throws InvoiceException
     */
    public function before(string $currency, CarbonInterface $date): ExchangeRate
    {
        $currency = strtoupper($currency);
        $day = CarbonImmutable::instance($date)->startOfDay();

        if ($currency === 'PLN') {
            throw new InvoiceException(__('No exchange rate is needed for PLN.'));
        }

        // Kurs z poprzedniego dnia jest na pewno ostatnim — wtedy nie pytamy NBP.
        $cached = ExchangeRate::query()
            ->where('currency', $currency)
            ->whereDate('effective_date', $day->subDay())
            ->first();

        if ($cached !== null) {
            return $cached;
        }

        foreach ($this->fetch($currency, $day->subDays(self::LOOKBACK_DAYS), $day->subDay()) as $rate) {
            // whereDate zamiast updateOrCreate: SQLite zapisuje datę z godziną.
            $cached = ExchangeRate::query()
                ->where('currency', $currency)
                ->whereDate('effective_date', $rate['effectiveDate'])
                ->first() ?? new ExchangeRate(['currency' => $currency, 'effective_date' => $rate['effectiveDate']]);

            $cached->fill(['rate' => (string) $rate['mid'], 'table_number' => $rate['no']])->save();
        }

        if ($cached === null) {
            throw new InvoiceException(__('NBP has no :currency rate before :date.', ['currency' => $currency, 'date' => $day->format('d.m.Y')]));
        }

        return $cached;
    }

    /**
     * @return list<array{no: string, effectiveDate: string, mid: float}>
     */
    private function fetch(string $currency, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $url = sprintf(self::URL, strtolower($currency), $from->toDateString(), $to->toDateString());

        try {
            $response = Http::timeout(10)->acceptJson()->get($url, ['format' => 'json']);
        } catch (ConnectionException) {
            throw new InvoiceException(__('Cannot reach the NBP exchange rate service. Enter the rate manually.'));
        }

        if ($response->notFound()) {
            return [];
        }

        if ($response->failed()) {
            throw new InvoiceException(__('The NBP service returned an error (:status). Enter the rate manually.', ['status' => $response->status()]));
        }

        /** @var list<array{no: string, effectiveDate: string, mid: float}> $rates */
        $rates = $response->json('rates', []);

        usort($rates, fn (array $a, array $b) => strcmp($a['effectiveDate'], $b['effectiveDate']));

        return $rates;
    }
}
