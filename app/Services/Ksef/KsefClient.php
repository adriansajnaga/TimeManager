<?php

namespace App\Services\Ksef;

use App\Models\KsefSetting;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use phpseclib3\Crypt\RSA;
use phpseclib3\File\X509;

/**
 * Klient API KSeF 2.0 (na wzór aplikacji PM, dokumentacja MF: CIRFMF/ksef-api).
 *
 * Uwierzytelnienie tokenem KSeF:
 *   1. POST /auth/challenge                → challenge + timestampMs
 *   2. „token|timestampMs” szyfrowane RSA-OAEP SHA-256 kluczem publicznym KSeF
 *   3. POST /auth/ksef-token               → numer referencyjny + token operacyjny
 *   4. GET  /auth/{referenceNumber}        → status (200 = sukces)
 *   5. POST /auth/token/redeem             → accessToken do kolejnych wywołań
 */
class KsefClient
{
    private const STATUS_ATTEMPTS = 10;

    private const STATUS_DELAY_MS = 700;

    /** Ile razy ponawiamy zapytanie odrzucone limitem KSeF (429). */
    private const RATE_LIMIT_RETRIES = 4;

    /** Najdłuższe czekanie na limit KSeF w sekundach (dłuższe = błąd, spróbuj później). */
    private const RATE_LIMIT_MAX_WAIT = 30;

    /** Wyłączane w testach — ponowienia bez czekania. */
    public bool $rateLimitSleep = true;

    public function __construct(private readonly KsefSetting $settings) {}

    public static function forCurrentSettings(): self
    {
        return new self(KsefSetting::current());
    }

    public function settings(): KsefSetting
    {
        return $this->settings;
    }

    /**
     * Token dostępowy, trzymany w cache do wygaśnięcia (uwierzytelnienie to cztery wywołania).
     */
    public function accessToken(): string
    {
        $key = $this->cacheKey();
        $cached = Cache::get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $token = $this->authenticate();

        $validUntil = isset($token['validUntil']) ? strtotime($token['validUntil']) : false;
        $seconds = $validUntil !== false ? max(60, $validUntil - time() - 60) : 600;

        Cache::put($key, $token['token'], $seconds);

        return $token['token'];
    }

    public function cacheKey(): string
    {
        return 'ksef.access-token.'.$this->settings->environment->value.'.'.$this->settings->nip;
    }

    public function forgetAccessToken(): void
    {
        Cache::forget($this->cacheKey());
    }

    /**
     * @return array{token: string, validUntil?: string}
     */
    public function authenticate(): array
    {
        if (! $this->settings->isConfigured()) {
            throw new KsefException(__('Enter the NIP and the KSeF token in Administration → KSeF.'));
        }

        $challenge = $this->json(fn () => $this->request()->post('/auth/challenge'));

        [$encryptedToken, $publicKeyId] = $this->encryptWithPublicKey(
            $this->settings->token.'|'.(int) ($challenge['timestampMs'] ?? 0),
            'KsefTokenEncryption',
        );

        $init = $this->json(fn () => $this->request()->post('/auth/ksef-token', array_filter([
            'challenge' => $challenge['challenge'] ?? null,
            'contextIdentifier' => ['type' => 'Nip', 'value' => $this->settings->nip],
            'encryptedToken' => $encryptedToken,
            'publicKeyId' => $publicKeyId,
        ])));

        $operationToken = $init['authenticationToken']['token'] ?? null;
        $reference = $init['referenceNumber'] ?? null;

        if (! is_string($operationToken) || ! is_string($reference)) {
            throw new KsefException(__('KSeF did not return an operation token.'));
        }

        $this->awaitAuthentication($reference, $operationToken);

        $tokens = $this->json(fn () => $this->request($operationToken)->post('/auth/token/redeem'));

        if (! isset($tokens['accessToken']['token']) || ! is_string($tokens['accessToken']['token'])) {
            throw new KsefException(__('KSeF did not return an access token.'));
        }

        return $tokens['accessToken'];
    }

    /**
     * Bieżąca sesja uwierzytelnienia — test, czy konfiguracja działa.
     *
     * @return array<string, mixed>
     */
    public function currentSession(): array
    {
        $response = $this->json(fn () => $this->request($this->accessToken())->get('/auth/sessions', ['pageSize' => 20]));
        $sessions = (array) ($response['items'] ?? []);

        foreach ($sessions as $session) {
            if ($session['isCurrent'] ?? false) {
                return $session;
            }
        }

        return (array) ($sessions[0] ?? []);
    }

    public function openOnlineSession(string $encryptedKey, string $initializationVector, ?string $publicKeyId): string
    {
        $response = $this->json(fn () => $this->request($this->accessToken())->post('/sessions/online', [
            'formCode' => ['systemCode' => 'FA (3)', 'schemaVersion' => '1-0E', 'value' => 'FA'],
            'encryption' => array_filter([
                'encryptedSymmetricKey' => $encryptedKey,
                'initializationVector' => $initializationVector,
                'publicKeyId' => $publicKeyId,
            ]),
        ]));

        if (! isset($response['referenceNumber'])) {
            throw new KsefException(__('KSeF did not open a session.'));
        }

        return (string) $response['referenceNumber'];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sendInvoice(string $session, array $payload): string
    {
        $response = $this->json(fn () => $this->request($this->accessToken())->post('/sessions/online/'.$session.'/invoices', $payload));

        if (! isset($response['referenceNumber'])) {
            throw new KsefException(__('KSeF did not accept the invoice into the session.'));
        }

        return (string) $response['referenceNumber'];
    }

    /**
     * @return array<string, mixed>
     */
    public function invoiceStatus(string $session, string $invoiceReference): array
    {
        return $this->json(fn () => $this->request($this->accessToken())->get('/sessions/'.$session.'/invoices/'.$invoiceReference));
    }

    public function closeOnlineSession(string $session): void
    {
        $this->json(fn () => $this->request($this->accessToken())->post('/sessions/online/'.$session.'/close'));
    }

    /**
     * Metadane faktur: Subject1 = jesteśmy sprzedawcą, Subject2 = nabywcą (zakupy).
     *
     * @return array{invoices: list<array<string, mixed>>, hasMore: bool}
     */
    public function queryInvoiceMetadata(
        CarbonInterface $from,
        CarbonInterface $to,
        string $subjectType = 'Subject1',
        int $pageOffset = 0,
        int $pageSize = 100,
    ): array {
        $response = $this->json(fn () => $this->request($this->accessToken())->post(
            '/invoices/query/metadata?'.http_build_query(['pageOffset' => $pageOffset, 'pageSize' => $pageSize]),
            [
                'subjectType' => $subjectType,
                'dateRange' => [
                    'dateType' => 'Issue',
                    'from' => $from->toIso8601String(),
                    'to' => $to->toIso8601String(),
                ],
            ],
        ));

        return [
            'invoices' => array_values((array) ($response['invoices'] ?? [])),
            'hasMore' => (bool) ($response['hasMore'] ?? false),
        ];
    }

    /**
     * Treść faktury (XML) spod numeru KSeF.
     */
    public function downloadInvoice(string $ksefNumber): string
    {
        $response = $this->call(fn () => $this->request($this->accessToken())->accept('application/xml')->get('/invoices/ksef/'.$ksefNumber));

        if ($response->failed()) {
            throw new KsefException(__('Could not download invoice :number from KSeF (HTTP :status).', ['number' => $ksefNumber, 'status' => $response->status()]), $response->status());
        }

        return $response->body();
    }

    /**
     * Szyfruje dane kluczem publicznym KSeF o danym przeznaczeniu
     * („KsefTokenEncryption” albo „SymmetricKeyEncryption”).
     *
     * @return array{0: string, 1: string|null} szyfrogram Base64 i identyfikator klucza
     */
    public function encryptWithPublicKey(string $data, string $usage): array
    {
        $certificate = $this->publicKeyCertificate($usage);

        $x509 = new X509;
        $x509->loadX509((string) $certificate['certificate']);
        $publicKey = $x509->getPublicKey();

        if (! $publicKey instanceof RSA\PublicKey) {
            throw new KsefException(__('The KSeF certificate does not contain an RSA key.'));
        }

        $rsa = $publicKey->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256');

        return [base64_encode((string) $rsa->encrypt($data)), isset($certificate['publicKeyId']) ? (string) $certificate['publicKeyId'] : null];
    }

    /**
     * @return array<string, mixed>
     */
    public function publicKeyCertificate(string $usage): array
    {
        /** @var list<array<string, mixed>> $certificates */
        $certificates = Cache::remember(
            'ksef.public-keys.'.$this->settings->environment->value,
            now()->addDay(),
            fn () => array_values($this->json(fn () => $this->request()->get('/security/public-key-certificates'))),
        );

        foreach ($certificates as $certificate) {
            if (in_array($usage, (array) ($certificate['usage'] ?? []), true)) {
                return $certificate;
            }
        }

        throw new KsefException(__('KSeF has no public key for :usage.', ['usage' => $usage]));
    }

    private function awaitAuthentication(string $reference, string $operationToken): void
    {
        for ($attempt = 1; $attempt <= self::STATUS_ATTEMPTS; $attempt++) {
            $status = $this->json(fn () => $this->request($operationToken)->get('/auth/'.$reference));
            $code = (int) ($status['status']['code'] ?? 0);

            if ($code === 200) {
                return;
            }

            if ($code >= 400) {
                throw new KsefException(__('KSeF rejected the authentication: :reason', ['reason' => $status['status']['description'] ?? $code]));
            }

            usleep(self::STATUS_DELAY_MS * 1000);
        }

        throw new KsefException(__('KSeF did not confirm the authentication in time.'));
    }

    private function request(?string $bearer = null): PendingRequest
    {
        $request = Http::baseUrl($this->settings->baseUrl())->acceptJson()->timeout(30);

        return $bearer !== null ? $request->withToken($bearer) : $request;
    }

    /**
     * @param  Closure(): Response  $send
     */
    /**
     * KSeF ogranicza liczbę zapytań (HTTP 429 z nagłówkiem Retry-After) — czekamy i ponawiamy.
     */
    private function call(Closure $send): Response
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $send();
            } catch (ConnectionException $exception) {
                throw new KsefException(__('Cannot connect to KSeF: :message', ['message' => $exception->getMessage()]));
            }

            if ($response->status() !== 429 || $attempt > self::RATE_LIMIT_RETRIES) {
                return $response;
            }

            $wait = (int) ($response->header('Retry-After') ?: 0);
            $this->pause(min(max($wait, $attempt * 2), self::RATE_LIMIT_MAX_WAIT));
        }
    }

    /**
     * Przerwa przed ponowieniem (w testach nadpisywana, żeby nie czekać).
     */
    protected function pause(int $seconds): void
    {
        if ($this->rateLimitSleep) {
            sleep($seconds);
        }
    }

    /**
     * Błędy KSeF niosą opis w polu `exception` — pokazujemy go zamiast samego kodu HTTP.
     *
     * @param  Closure(): Response  $send
     * @return array<array-key, mixed>
     */
    private function json(Closure $send): array
    {
        $response = $this->call($send);

        if ($response->failed()) {
            $body = (array) ($response->json() ?? []);

            $details = collect((array) ($body['exception']['exceptionDetailList'] ?? []))
                ->map(fn ($detail) => trim(($detail['exceptionDescription'] ?? '').' '.implode(' ', (array) ($detail['details'] ?? []))))
                ->filter()
                ->implode(' ');

            $message = $details !== '' ? $details : (string) ($body['exception']['exceptionDescription'] ?? $body['message'] ?? $body['title'] ?? 'HTTP '.$response->status());

            throw new KsefException(__('KSeF rejected the request: :message', ['message' => $message]), $response->status());
        }

        return (array) $response->json();
    }
}
