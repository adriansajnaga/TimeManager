<?php

use App\Documents\InvoicePdf;
use App\Enums\InvoiceDirection;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceSource;
use App\Enums\InvoiceStatus;
use App\Enums\KsefStatus;
use App\Enums\VatCode;
use App\Models\CompanySetting;
use App\Models\Contractor;
use App\Models\Invoice;
use App\Models\KsefSetting;
use App\Models\User;
use App\Services\Invoices\InvoiceException;
use App\Services\Invoices\InvoiceIssuer;
use App\Services\Ksef\Fa3InvoiceBuilder;
use App\Services\Ksef\Fa3InvoiceReader;
use App\Services\Ksef\Fa3Validator;
use App\Services\Ksef\InvoiceQrCode;
use App\Services\Ksef\KsefClient;
use App\Services\Ksef\KsefInvoiceImporter;
use App\Services\Ksef\KsefInvoiceSender;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use phpseclib3\Crypt\RSA;
use phpseclib3\File\X509;

/**
 * Klucz i certyfikat „Ministerstwa” do testów (generowane raz na przebieg).
 *
 * @return array{0: RSA\PrivateKey, 1: string}
 */
function ksefKeys(): array
{
    static $keys = null;

    if ($keys === null) {
        $private = RSA::createKey(2048);

        $subject = new X509;
        $subject->setDN('CN=KSeF Test');
        $subject->setPublicKey($private->getPublicKey());

        $issuer = new X509;
        $issuer->setPrivateKey($private);
        $issuer->setDN('CN=KSeF Test');

        $x509 = new X509;
        $keys = [$private, base64_encode((string) $x509->saveX509($x509->sign($issuer, $subject), X509::FORMAT_DER))];
    }

    return $keys;
}

/**
 * Atrapa API KSeF 2.0. Zapisuje wysłane faktury; status weryfikacji z $state->statuses (kolejno).
 */
function fakeKsef(array $metadata = [], array $documents = [], array $statuses = [['status' => ['code' => 200], 'ksefNumber' => '8792451081-20260915-ABCDEF123456-7A']]): object
{
    $state = new class
    {
        public array $sent = [];

        public array $statuses = [];

        public array $session = [];
    };
    $state->statuses = $statuses;

    Http::fake(function (Request $request) use ($state, $metadata, $documents) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $path = substr($path, strlen('/v2'));

        return match (true) {
            $path === '/security/public-key-certificates' => Http::response([
                ['certificate' => ksefKeys()[1], 'usage' => ['KsefTokenEncryption', 'SymmetricKeyEncryption'], 'publicKeyId' => 'key-1'],
            ]),
            $path === '/auth/challenge' => Http::response(['challenge' => 'CH-1', 'timestampMs' => 1790000000000]),
            $path === '/auth/ksef-token' => Http::response(['referenceNumber' => 'AUTH-1', 'authenticationToken' => ['token' => 'operation-token']]),
            $path === '/auth/AUTH-1' => Http::response(['status' => ['code' => 200]]),
            $path === '/auth/token/redeem' => Http::response(['accessToken' => ['token' => 'access-token', 'validUntil' => now()->addHour()->toIso8601String()]]),
            $path === '/auth/sessions' => Http::response(['items' => [['isCurrent' => true, 'referenceNumber' => 'AUTH-1']]]),
            $path === '/invoices/query/metadata' => Http::response([
                'invoices' => $metadata[$request->data()['subjectType']] ?? [],
                'hasMore' => false,
            ]),
            str_starts_with($path, '/invoices/ksef/') => isset($documents[substr($path, 15)])
                ? Http::response($documents[substr($path, 15)], 200, ['Content-Type' => 'application/xml'])
                : Http::response('', 404),
            $path === '/sessions/online' => (function () use ($state, $request) {
                $state->session = $request->data();

                return Http::response(['referenceNumber' => 'SES-1']);
            })(),
            $path === '/sessions/online/SES-1/invoices' => (function () use ($state, $request) {
                $state->sent[] = $request->data();

                return Http::response(['referenceNumber' => 'INV-1']);
            })(),
            $path === '/sessions/SES-1/invoices/INV-1' => Http::response(count($state->statuses) > 1 ? array_shift($state->statuses) : $state->statuses[0]),
            $path === '/sessions/online/SES-1/close' => Http::response([]),
            default => Http::response(['exception' => ['exceptionDescription' => 'Unexpected '.$path]], 500),
        };
    });

    return $state;
}

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();

    CompanySetting::query()->create([
        'name' => 'ASCOMM Adrian Sajnaga',
        'street' => 'ul. Testowa 1',
        'zip' => '87-100',
        'city' => 'Toruń',
        'nip' => '8792451081',
        'regon' => '871123082',
    ]);

    KsefSetting::query()->create(['environment' => 'test', 'nip' => '8792451081', 'token' => 'test-ksef-token']);

    app()->afterResolving(KsefInvoiceSender::class, function (KsefInvoiceSender $sender) {
        $sender->statusAttempts = 3;
        $sender->statusDelayMs = 0;
    });

    $this->gaertner = Contractor::factory()->german()->create(['name' => 'Gärtner Elektrotechnik GmbH', 'tax_id' => '286771111', 'street' => 'Zum Brook 9', 'zip' => '24143', 'city' => 'Kiel']);
    $this->client = Contractor::factory()->create(['name' => 'Firma Testowa Sp. z o.o.', 'tax_id' => '5250000000']);
});

/**
 * @param  list<array{0: string, 1: string, 2: VatCode}>  $items  [cena, ilość, stawka]
 */
function draftInvoice(Contractor $contractor, array $items, array $attributes = []): Invoice
{
    $invoice = Invoice::factory()->for($contractor)->create(array_merge(['issue_date' => '2026-09-15', 'sale_date' => '2026-09-15', 'currency' => $contractor->currency], $attributes));

    foreach ($items as $index => [$price, $quantity, $code]) {
        $invoice->items()->create(['position' => $index + 1, 'name' => 'Pozycja '.($index + 1), 'unit' => 'szt.', 'quantity' => $quantity, 'unit_price' => $price, 'vat_code' => $code]);
    }

    $invoice->refreshTotals();

    return $invoice->fresh();
}

function xmlOf(Invoice $invoice): DOMXPath
{
    $document = new DOMDocument;
    $document->loadXML(app(Fa3InvoiceBuilder::class)->build($invoice->fresh()));
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('fa', Fa3InvoiceBuilder::NAMESPACE);

    return $xpath;
}

test('FA(3) XML of every invoice kind matches the official schema', function () {
    $validator = app(Fa3Validator::class);
    $build = fn (Invoice $invoice) => app(Fa3InvoiceBuilder::class)->build($invoice->fresh());

    $gaertner = draftInvoice($this->gaertner, [['3977.80', '1', VatCode::OutsideScopeEuServices]], ['number' => '4/8/2026']);
    $domestic = draftInvoice($this->client, [['120.00', '12.5', VatCode::Rate23], ['1.15', '84', VatCode::Rate8], ['50.00', '1', VatCode::Exempt]], ['number' => '1/9/2026', 'vat_exemption_basis' => 'art. 43 ust. 1 pkt 37', 'notes' => 'Uwagi do faktury']);
    $euro = draftInvoice($this->client, [['100.00', '1', VatCode::Rate23]], ['number' => '2/9/2026', 'currency' => 'EUR', 'exchange_rate' => '4.2563']);
    $advance = draftInvoice($this->client, [['10000.00', '1', VatCode::Rate23]], ['number' => '3/9/2026', 'kind' => InvoiceKind::Advance, 'advance_amount' => '4920.00']);
    $advance->forceFill(['status' => InvoiceStatus::Issued, 'ksef_number' => '8792451081-20260915-0123456789AB-01'])->save();
    $final = draftInvoice($this->client, [['10000.00', '1', VatCode::Rate23]], ['number' => '4/9/2026', 'kind' => InvoiceKind::Final]);
    $final->advances()->sync([$advance->id]);
    $final->refreshTotals();
    $correction = draftInvoice($this->client, [['100.00', '8', VatCode::Rate23]], ['number' => '5/9/2026', 'kind' => InvoiceKind::Correction, 'corrected_number' => '1/9/2026', 'corrected_issue_date' => '2026-09-01', 'correction_reason' => 'Błędna ilość']);
    $correction->items()->create(['position' => 1, 'is_before' => true, 'name' => 'Pozycja 1', 'quantity' => '10', 'unit_price' => '100.00', 'vat_code' => VatCode::Rate23]);
    $correction->refreshTotals();

    foreach ([$gaertner, $domestic, $euro, $advance, $final, $correction] as $invoice) {
        expect($validator->errors($build($invoice)))->toBe([], 'Invoice '.$invoice->number);
    }

    $xml = xmlOf($gaertner);
    expect($xml->evaluate('string(//fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:KodUE)'))->toBe('DE')
        ->and($xml->evaluate('string(//fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:NrVatUE)'))->toBe('286771111')
        ->and($xml->evaluate('string(//fa:Fa/fa:P_13_9)'))->toBe('3977.8')
        ->and($xml->evaluate('string(//fa:Adnotacje/fa:P_18)'))->toBe('1')
        ->and($xml->evaluate('string(//fa:FaWiersz/fa:P_12)'))->toBe('np II');

    $xml = xmlOf($domestic);
    expect($xml->evaluate('string(//fa:Fa/fa:P_13_1)'))->toBe('1500')
        ->and($xml->evaluate('string(//fa:Fa/fa:P_14_1)'))->toBe('345')
        ->and($xml->evaluate('string(//fa:Fa/fa:P_13_7)'))->toBe('50')
        ->and($xml->evaluate('string(//fa:Zwolnienie/fa:P_19A)'))->toBe('art. 43 ust. 1 pkt 37')
        ->and($xml->evaluate('string(//fa:Adnotacje/fa:P_18)'))->toBe('2');

    expect(xmlOf($euro)->evaluate('string(//fa:Fa/fa:P_14_1W)'))->toBe('97.89');

    $xml = xmlOf($advance);
    expect($xml->evaluate('string(//fa:RodzajFaktury)'))->toBe('ZAL')
        ->and($xml->evaluate('count(//fa:FaWiersz)'))->toBe(0.0)
        ->and($xml->evaluate('string(//fa:Zamowienie/fa:WartoscZamowienia)'))->toBe('12300')
        ->and($xml->evaluate('string(//fa:Fa/fa:P_15)'))->toBe('4920');

    $xml = xmlOf($final);
    expect($xml->evaluate('string(//fa:FakturaZaliczkowa/fa:NrKSeFFaZaliczkowej)'))->toBe('8792451081-20260915-0123456789AB-01')
        ->and($xml->evaluate('string(//fa:Fa/fa:P_15)'))->toBe('7380');

    $xml = xmlOf($correction);
    expect($xml->evaluate('string(//fa:RodzajFaktury)'))->toBe('KOR')
        ->and($xml->evaluate('string(//fa:FaWiersz[1]/fa:StanPrzed)'))->toBe('1')
        ->and($xml->evaluate('string(//fa:DaneFaKorygowanej/fa:NrKSeFN)'))->toBe('1')
        ->and($xml->evaluate('string(//fa:Fa/fa:P_15)'))->toBe('-246');
});

test('invoices from KSeF are read with their own totals', function () {
    $sale = app(Fa3InvoiceReader::class)->read(file_get_contents(base_path('tests/Fixtures/ksef/sales-eu-reverse-charge.xml')));

    expect($sale['attributes']['number'])->toBe('5/8/2026')
        ->and($sale['attributes']['currency'])->toBe('EUR')
        ->and($sale['attributes']['exchange_rate'])->toBe('4.3014')
        ->and($sale['attributes']['gross'])->toBe('2090.00')
        ->and($sale['attributes']['buyer']['vat_prefix'])->toBe('DE')
        ->and($sale['attributes']['buyer']['zip'])->toBe('D-24143')
        ->and($sale['items'][0]['vat_code'])->toBe(VatCode::ReverseCharge);

    $purchase = app(Fa3InvoiceReader::class)->read(file_get_contents(base_path('tests/Fixtures/ksef/purchase-domestic.xml')));

    expect($purchase['attributes']['vat'])->toBe('287.50')
        ->and($purchase['attributes']['paid_on'])->toBe('2026-09-02')
        ->and($purchase['attributes']['seller']['tax_id'])->toBe('9561234567')
        ->and($purchase['items'][0]['quantity'])->toBe('100');
});

test('issuing takes the next number from KSeF and sends the encrypted invoice', function () {
    $ksef = fakeKsef(['Subject1' => [
        ['ksefNumber' => 'A', 'invoiceNumber' => '3/9/2026'],
        ['ksefNumber' => 'B', 'invoiceNumber' => '1/9/2026'],
        ['ksefNumber' => 'C', 'invoiceNumber' => '12/8/2026'],
        ['ksefNumber' => 'D', 'invoiceNumber' => 'FV/15/2026'],
    ]]);

    $invoice = draftInvoice($this->gaertner, [['3977.80', '1', VatCode::OutsideScopeEuServices]]);

    Livewire::actingAs($this->admin)
        ->test('pages::invoices.show', ['invoice' => $invoice])
        ->call('issue')
        ->assertHasNoErrors();

    $invoice->refresh();

    expect($invoice->number)->toBe('4/9/2026')
        ->and($invoice->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->ksef_status)->toBe(KsefStatus::Accepted)
        ->and($invoice->ksef_number)->toBe('8792451081-20260915-ABCDEF123456-7A')
        ->and(app(Fa3Validator::class)->errors((string) $invoice->xml))->toBe([]);

    // KSeF dostał treść zaszyfrowaną kluczem z sesji — po odszyfrowaniu to ten sam XML.
    $key = ksefKeys()[0]->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256')
        ->decrypt(base64_decode($ksef->session['encryption']['encryptedSymmetricKey']));
    $xml = openssl_decrypt(base64_decode($ksef->sent[0]['encryptedInvoiceContent']), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, base64_decode($ksef->session['encryption']['initializationVector']));

    expect($xml)->toBe($invoice->xml)
        ->and($ksef->sent[0]['invoiceHash'])->toBe(base64_encode(hash('sha256', $xml, true)));

    expect(app(InvoiceQrCode::class)->url($invoice))
        ->toStartWith('https://qr-test.ksef.mf.gov.pl/invoice/8792451081/15-09-2026/');
});

test('a rejected invoice returns to draft with the reason', function () {
    fakeKsef(statuses: [['status' => ['code' => 450, 'description' => 'Błąd weryfikacji semantyki dokumentu', 'details' => ['Nieprawidłowy NIP nabywcy']]]]);

    $invoice = draftInvoice($this->client, [['100.00', '1', VatCode::Rate23]]);

    expect(fn () => app(InvoiceIssuer::class)->issue($invoice, $this->admin))
        ->toThrow(InvoiceException::class, 'Nieprawidłowy NIP nabywcy');

    $invoice->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->number)->toBeNull()
        ->and($invoice->ksef_error)->toContain('Błąd weryfikacji');
});

test('an unconfirmed invoice waits for KSeF and can be checked later', function () {
    $ksef = fakeKsef(statuses: [
        ['status' => ['code' => 150]],
        ['status' => ['code' => 150]],
        ['status' => ['code' => 150]],
        ['status' => ['code' => 200], 'ksefNumber' => '8792451081-20260915-FEDCBA654321-11'],
    ]);

    $invoice = draftInvoice($this->client, [['100.00', '1', VatCode::Rate23]]);
    app(InvoiceIssuer::class)->issue($invoice, $this->admin);

    expect($invoice->refresh()->ksef_status)->toBe(KsefStatus::Pending)
        ->and($invoice->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->number)->toBe('1/9/2026');

    Livewire::actingAs($this->admin)
        ->test('pages::invoices.show', ['invoice' => $invoice])
        ->assertSee('Check KSeF status')
        ->call('refreshKsef')
        ->assertHasNoErrors();

    expect($invoice->refresh()->ksef_status)->toBe(KsefStatus::Accepted)
        ->and($invoice->ksef_number)->toBe('8792451081-20260915-FEDCBA654321-11');
});

test('a number waiting in the application is not given twice', function () {
    fakeKsef(['Subject1' => [['ksefNumber' => 'A', 'invoiceNumber' => '1/9/2026']]]);

    Invoice::factory()->for($this->client)->create(['issue_date' => '2026-09-10', 'number' => '2/9/2026', 'status' => InvoiceStatus::Issued]);
    $invoice = draftInvoice($this->client, [['100.00', '1', VatCode::Rate23]]);

    expect(fn () => app(InvoiceIssuer::class)->issue($invoice, $this->admin))
        ->toThrow(InvoiceException::class, 'KSeF gave number 2/9/2026');

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Draft);
});

test('downloading from KSeF stores sales and purchases once', function () {
    $supplier = Contractor::factory()->supplier()->create(['tax_id' => '956-123-45-67']);

    fakeKsef(
        ['Subject1' => [['ksefNumber' => 'S-1', 'invoiceNumber' => '5/8/2026']], 'Subject2' => [['ksefNumber' => 'P-1', 'invoiceNumber' => 'FV 1234/09/2026']]],
        [
            'S-1' => file_get_contents(base_path('tests/Fixtures/ksef/sales-eu-reverse-charge.xml')),
            'P-1' => file_get_contents(base_path('tests/Fixtures/ksef/purchase-domestic.xml')),
        ],
    );

    $summary = app(KsefInvoiceImporter::class)->import(now()->subMonths(2), now());

    expect($summary)->toMatchArray(['sales' => 1, 'purchases' => 1, 'known' => 0, 'failed' => []]);

    $sale = Invoice::query()->where('ksef_number', 'S-1')->sole();
    $purchase = Invoice::query()->where('ksef_number', 'P-1')->sole();

    expect($sale->direction)->toBe(InvoiceDirection::Sales)
        ->and($sale->source)->toBe(InvoiceSource::Ksef)
        ->and($sale->contractor_id)->toBe($this->gaertner->id)
        ->and($sale->gross)->toBe('2090.00')
        ->and($sale->isEditable())->toBeFalse()
        ->and($purchase->direction)->toBe(InvoiceDirection::Purchase)
        ->and($purchase->contractor_id)->toBe($supplier->id)
        ->and($purchase->counterparty_name)->toBe('Hurtownia Elektryczna Sp. z o.o.')
        ->and($purchase->paid_on?->toDateString())->toBe('2026-09-02')
        ->and($purchase->items->sole()->net)->toBe('1250.00');

    expect(app(KsefInvoiceImporter::class)->import(now()->subMonths(2), now()))
        ->toMatchArray(['sales' => 0, 'purchases' => 0, 'known' => 2]);
});

test('a pending invoice is confirmed by the download', function () {
    $waiting = Invoice::factory()->for($this->client)->create(['number' => '7/9/2026', 'status' => InvoiceStatus::Issued, 'ksef_status' => KsefStatus::Pending]);

    fakeKsef(['Subject1' => [['ksefNumber' => 'S-7', 'invoiceNumber' => '7/9/2026']]], ['S-7' => '<xml/>']);

    expect(app(KsefInvoiceImporter::class)->import(now()->subMonth(), now()))->toMatchArray(['confirmed' => 1, 'sales' => 0]);
    expect($waiting->refresh()->ksef_number)->toBe('S-7')
        ->and($waiting->ksef_status)->toBe(KsefStatus::Accepted);
});

test('the invoice list downloads from KSeF', function () {
    fakeKsef(['Subject2' => [['ksefNumber' => 'P-1', 'invoiceNumber' => 'FV 1234/09/2026']]], ['P-1' => file_get_contents(base_path('tests/Fixtures/ksef/purchase-domestic.xml'))]);

    Livewire::actingAs($this->admin)
        ->test('pages::invoices.index')
        ->assertSee('Download from KSeF')
        ->set('ksefFrom', '2026-09-01')
        ->set('ksefTo', today()->toDateString())
        ->call('importFromKsef')
        ->assertHasNoErrors()
        ->set('direction', 'purchase')
        ->assertSee('FV 1234/09/2026');

    expect(KsefSetting::current()->synced_until?->toDateString())->toBe(today()->toDateString());
});

test('KSeF settings keep the token encrypted and test the connection', function () {
    fakeKsef();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.ksef')
        ->set('nip', '8792451081')
        ->set('token', 'new-secret-ksef-token')
        ->call('save')
        ->assertHasNoErrors()
        ->call('test')
        ->assertHasNoErrors();

    expect(DB::table('ksef_settings')->value('token'))->not->toContain('new-secret-ksef-token')
        ->and(KsefSetting::current()->token)->toBe('new-secret-ksef-token')
        ->and(KsefSetting::current()->verified_at)->not->toBeNull();

    $this->actingAs(User::factory()->create())->get(route('admin.ksef'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.ksef'))->assertOk()->assertDontSee('new-secret-ksef-token');
});

test('the PDF of an invoice in KSeF shows its number and the verification code', function () {
    $invoice = draftInvoice($this->client, [['100.00', '1', VatCode::Rate23]], ['number' => '1/9/2026', 'status' => InvoiceStatus::Issued]);
    $invoice->forceFill([
        'ksef_number' => '8792451081-20260915-ABCDEF123456-7A',
        'ksef_status' => KsefStatus::Accepted,
        'ksef_environment' => 'test',
        'xml' => app(Fa3InvoiceBuilder::class)->build($invoice),
    ])->save();

    $document = new InvoicePdf($invoice->fresh(), app(InvoiceQrCode::class)->url($invoice));
    $html = view($document->view(), $document->data())->render();

    expect($html)->toContain('Numer KSeF: <span class="bold">8792451081-20260915-ABCDEF123456-7A</span>')
        ->toContain('<barcode code="https://qr-test.ksef.mf.gov.pl/invoice/8792451081/15-09-2026/')
        ->toContain('bez mocy prawnej');

    $this->actingAs($this->admin)->get(route('invoices.pdf', $invoice))->assertOk();
    $this->actingAs($this->admin)->get(route('invoices.xml', $invoice))->assertOk()->assertHeader('Content-Type', 'application/xml; charset=utf-8');
});

test('an invoice in KSeF links to the KSeF verification page', function () {
    $invoice = draftInvoice($this->client, [['100.00', '1', VatCode::Rate23]], ['number' => '1/9/2026', 'status' => InvoiceStatus::Issued]);
    $invoice->forceFill([
        'ksef_number' => '8792451081-20260915-ABCDEF123456-7A',
        'ksef_status' => KsefStatus::Accepted,
        'ksef_environment' => 'test',
        'xml' => app(Fa3InvoiceBuilder::class)->build($invoice),
    ])->save();

    $this->actingAs($this->admin)
        ->get(route('invoices.show', $invoice))
        ->assertSee('Check in KSeF')
        ->assertSee('https://qr-test.ksef.mf.gov.pl/invoice/8792451081/15-09-2026/', false);
});

test('a test KSeF invoice can be deleted and releases its settlement, a production one cannot', function () {
    $invoice = draftInvoice($this->client, [['100.00', '1', VatCode::Rate23]], ['number' => '1/9/2026', 'status' => InvoiceStatus::Issued]);
    $invoice->forceFill(['ksef_number' => 'T-1', 'ksef_status' => KsefStatus::Accepted, 'ksef_environment' => 'test'])->save();

    expect($invoice->fresh()->isDeletable())->toBeTrue();

    Livewire::actingAs($this->admin)
        ->test('pages::invoices.show', ['invoice' => $invoice])
        ->assertSee('Delete test invoice')
        ->call('delete');

    expect(Invoice::query()->find($invoice->id))->toBeNull();

    $production = draftInvoice($this->client, [['100.00', '1', VatCode::Rate23]], ['number' => '2/9/2026', 'status' => InvoiceStatus::Issued]);
    $production->forceFill(['ksef_number' => 'P-1', 'ksef_status' => KsefStatus::Accepted, 'ksef_environment' => 'prod'])->save();
    KsefSetting::query()->update(['environment' => 'prod']);

    Livewire::actingAs($this->admin)
        ->test('pages::invoices.show', ['invoice' => $production->fresh()])
        ->call('delete')
        ->assertForbidden();
});

test('downloads limited by KSeF are retried and failures come with a reason', function () {
    app()->afterResolving(KsefClient::class, fn ($client) => $client->rateLimitSleep = false);

    $xml = file_get_contents(base_path('tests/Fixtures/ksef/purchase-domestic.xml'));
    $calls = 0;

    // Pierwsze pobranie P-1 — limit KSeF (429), drugie — faktura; P-404 — brak dokumentu.
    // Te atrapy rejestrujemy przed ogólną atrapą KSeF, żeby miały pierwszeństwo.
    Http::fake([
        '*/invoices/ksef/P-1' => function () use (&$calls, $xml) {
            return ++$calls === 1
                ? Http::response(['exception' => ['exceptionDescription' => 'Too many requests']], 429, ['Retry-After' => '1'])
                : Http::response($xml, 200, ['Content-Type' => 'application/xml']);
        },
        '*/invoices/ksef/P-404' => Http::response('', 404),
    ]);
    fakeKsef(['Subject2' => [['ksefNumber' => 'P-1', 'invoiceNumber' => 'FV 1'], ['ksefNumber' => 'P-404', 'invoiceNumber' => 'FV 2']]]);

    $summary = app(KsefInvoiceImporter::class)->import(now()->subMonth(), now());

    expect($summary['purchases'])->toBe(1)
        ->and($calls)->toBe(2)
        ->and(array_keys($summary['failed']))->toBe(['P-404'])
        ->and($summary['failed']['P-404'])->toContain('HTTP 404');
});

test('invoices computed from gross prices are read with net values', function () {
    $xml = str_replace(
        ['<P_9A>12.5</P_9A><P_11>1250</P_11>', '<?xml'],
        ['<P_9B>15.375</P_9B><P_11A>1537.5</P_11A><P_11Vat>287.5</P_11Vat>', "\u{FEFF}<?xml"],
        file_get_contents(base_path('tests/Fixtures/ksef/purchase-domestic.xml')),
    );

    $item = app(Fa3InvoiceReader::class)->read($xml)['items'][0];

    expect($item['net'])->toBe('1250.00');
});

test('invoices from the production KSeF cannot be deleted', function () {
    KsefSetting::query()->update(['environment' => 'prod']);

    $fromKsef = Invoice::factory()->purchase()->create(['source' => InvoiceSource::Ksef, 'ksef_number' => 'P-9', 'ksef_environment' => 'prod', 'status' => InvoiceStatus::Issued]);
    $manual = Invoice::factory()->purchase()->create(['source' => InvoiceSource::Manual, 'status' => InvoiceStatus::Issued]);

    expect($fromKsef->isDeletable())->toBeFalse()
        ->and($manual->isDeletable())->toBeTrue();

    Livewire::actingAs($this->admin)
        ->test('pages::invoices.show', ['invoice' => $fromKsef])
        ->assertDontSee('Delete this invoice?')
        ->call('delete')
        ->assertForbidden();
});

test('an invoice from KSeF shows the totals of its XML even when the lines are gross', function () {
    $xml = str_replace(
        '<P_9A>12.5</P_9A><P_11>1250</P_11>',
        '<P_9B>15.375</P_9B><P_11A>1537.5</P_11A>',
        file_get_contents(base_path('tests/Fixtures/ksef/purchase-domestic.xml')),
    );

    $invoice = app(KsefInvoiceImporter::class)->store($xml, 'P-77', InvoiceDirection::Purchase);
    $summary = $invoice->fresh()->summary();

    expect((string) $summary->net())->toBe('1250.00')
        ->and((string) $summary->vat())->toBe('287.50')
        ->and((string) $summary->gross())->toBe('1537.50');

    $this->actingAs($this->admin)->get(route('invoices.show', $invoice))->assertSee('1 537,50 PLN');
});

test('a long KSeF request limit stops the download and keeps the rest for later', function () {
    app()->afterResolving(KsefClient::class, fn ($client) => $client->rateLimitSleep = false);
    $xml = file_get_contents(base_path('tests/Fixtures/ksef/purchase-domestic.xml'));

    Http::fake([
        '*/invoices/ksef/P-1' => Http::response($xml, 200, ['Content-Type' => 'application/xml']),
        '*/invoices/ksef/P-2' => Http::response(['exception' => ['exceptionDescription' => 'Too many requests']], 429, ['Retry-After' => '60']),
    ]);
    fakeKsef(['Subject2' => [['ksefNumber' => 'P-1'], ['ksefNumber' => 'P-2'], ['ksefNumber' => 'P-3']]]);

    $summary = app(KsefInvoiceImporter::class)->import(now()->subMonth(), now());

    expect($summary['purchases'])->toBe(1)
        ->and($summary['stopped'])->toContain('60 s')
        ->and($summary['remaining'])->toBe(2)
        ->and($summary['failed'])->toBe([]);
});

test('the download stops when its time is up', function () {
    fakeKsef(['Subject2' => [['ksefNumber' => 'P-1'], ['ksefNumber' => 'P-2']]]);

    $summary = app(KsefInvoiceImporter::class)->import(now()->subMonth(), now(), seconds: -1);

    expect($summary['remaining'])->toBe(2)
        ->and($summary['purchases'])->toBe(0)
        ->and($summary['stopped'])->not->toBeNull();
});
