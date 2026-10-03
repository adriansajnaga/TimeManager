<?php

namespace App\Services\Ksef;

use App\Enums\KsefStatus;
use App\Models\Invoice;
use App\Services\Invoices\InvoiceTransmitter;

/**
 * Wysyłka faktury sesją interaktywną: XML szyfrowany AES-256-CBC, klucz symetryczny —
 * RSA-OAEP SHA-256 kluczem publicznym MF (jak w aplikacji PM).
 */
class KsefInvoiceSender implements InvoiceTransmitter
{
    public int $statusAttempts = 12;

    public int $statusDelayMs = 900;

    public function __construct(
        private readonly KsefClient $client,
        private readonly Fa3InvoiceBuilder $builder,
        private readonly Fa3Validator $validator,
    ) {}

    public function send(Invoice $invoice): void
    {
        if ($invoice->isInKsef()) {
            return;
        }

        $xml = $this->builder->build($invoice);
        $this->validator->assertValid($xml);

        $key = random_bytes(32);
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($xml, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            throw new KsefException(__('Could not encrypt the invoice before sending.'));
        }

        [$encryptedKey, $publicKeyId] = $this->client->encryptWithPublicKey($key, 'SymmetricKeyEncryption');
        $session = $this->client->openOnlineSession($encryptedKey, base64_encode($iv), $publicKeyId);

        try {
            $reference = $this->client->sendInvoice($session, [
                'invoiceHash' => base64_encode(hash('sha256', $xml, true)),
                'invoiceSize' => strlen($xml),
                'encryptedInvoiceHash' => base64_encode(hash('sha256', $encrypted, true)),
                'encryptedInvoiceSize' => strlen($encrypted),
                'encryptedInvoiceContent' => base64_encode($encrypted),
            ]);

            $invoice->forceFill([
                'xml' => $xml,
                'ksef_status' => KsefStatus::Pending,
                'ksef_environment' => $this->client->settings()->environment,
                'ksef_session' => $session,
                'ksef_reference' => $reference,
                'ksef_sent_at' => now(),
                'ksef_error' => null,
            ])->save();

            try {
                $status = $this->await($session, $reference);
            } catch (KsefRejectedException $exception) {
                throw $exception;
            } catch (KsefException) {
                // Faktura jest w sesji, a nie wiemy jeszcze, czy przeszła — zostaje „czeka na KSeF”.
                return;
            }

            $this->apply($invoice, $status);
        } finally {
            $this->closeQuietly($session);
        }
    }

    public function refresh(Invoice $invoice): void
    {
        if ($invoice->ksef_status !== KsefStatus::Pending || $invoice->ksef_session === null || $invoice->ksef_reference === null) {
            return;
        }

        $this->apply($invoice, $this->client->invoiceStatus($invoice->ksef_session, $invoice->ksef_reference));
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function apply(Invoice $invoice, array $status): void
    {
        if (filled($status['ksefNumber'] ?? null)) {
            $invoice->forceFill([
                'ksef_status' => KsefStatus::Accepted,
                'ksef_number' => (string) $status['ksefNumber'],
                'ksef_error' => null,
            ])->save();

            return;
        }

        $code = (int) ($status['status']['code'] ?? 0);

        if ($code >= 400) {
            $details = implode(' ', array_map('strval', (array) ($status['status']['details'] ?? [])));

            throw new KsefRejectedException(__('KSeF rejected the invoice: :reason', [
                'reason' => trim(($status['status']['description'] ?? 'code '.$code).' '.$details),
            ]), $code);
        }
    }

    /**
     * Weryfikacja jest asynchroniczna — czekamy na numer KSeF albo odmowę.
     *
     * @return array<string, mixed>
     */
    private function await(string $session, string $reference): array
    {
        $status = [];

        for ($attempt = 1; $attempt <= $this->statusAttempts; $attempt++) {
            $status = $this->client->invoiceStatus($session, $reference);

            if (filled($status['ksefNumber'] ?? null) || (int) ($status['status']['code'] ?? 0) >= 400) {
                return $status;
            }

            usleep($this->statusDelayMs * 1000);
        }

        return $status;
    }

    private function closeQuietly(string $session): void
    {
        try {
            $this->client->closeOnlineSession($session);
        } catch (KsefException) {
            // Sesja wygaśnie sama; błąd sprzątania nie może przesłonić wyniku wysyłki.
        }
    }
}
