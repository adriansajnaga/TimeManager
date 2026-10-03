<?php

use App\Documents\InvoicePdf;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\KsefStatus;
use App\Enums\VatCode;
use App\Models\BankAccount;
use App\Models\CompanySetting;
use App\Models\Contractor;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Invoices\InvoiceException;
use App\Services\Invoices\InvoiceIssuer;
use App\Services\Invoices\InvoiceNumbering;
use App\Services\Invoices\InvoiceTransmitter;
use App\Services\Invoices\NbpExchangeRates;
use App\Services\Invoices\VatSummary;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Numeracja jak z KSeF, bez połączenia: kolejne numery w miesiącu wystawienia;
 * wysyłka do KSeF przyjmuje każdą fakturę.
 */
function fakeNumbering(): void
{
    app()->instance(InvoiceTransmitter::class, new class implements InvoiceTransmitter
    {
        public function send(Invoice $invoice): void
        {
            $invoice->forceFill(['ksef_status' => KsefStatus::Accepted, 'ksef_number' => '8792451081-20260806-'.strtoupper(bin2hex(random_bytes(6))).'-00'])->save();
        }

        public function refresh(Invoice $invoice): void {}
    });

    app()->instance(InvoiceNumbering::class, new class implements InvoiceNumbering
    {
        private int $next = 7;

        public function next(Invoice $invoice): string
        {
            return ($this->next++).'/'.$invoice->issue_date->month.'/'.$invoice->issue_date->year;
        }
    });
}

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();

    CompanySetting::query()->create([
        'name' => 'ASCOMM Adrian Sajnaga',
        'street' => 'ul. Konstytucji 3 Maja 15/12',
        'zip' => '87-100',
        'city' => 'Toruń',
        'nip' => '8792451081',
        'regon' => '871123082',
    ]);
});

test('VAT is calculated per rate from the sum of net values', function () {
    $summary = VatSummary::fromLines([
        ['net' => '10.05', 'vat_code' => VatCode::Rate23],
        ['net' => '10.05', 'vat_code' => VatCode::Rate23],
        ['net' => '100.00', 'vat_code' => '8'],
        ['net' => '3977.80', 'vat_code' => VatCode::OutsideScopeEuServices],
    ]);

    expect(array_map(fn ($row) => [$row['code']->value, (string) $row['net'], (string) $row['vat']], $summary->rows()))
        ->toBe([['23', '20.10', '4.62'], ['8', '100.00', '8.00'], ['np II', '3977.80', '0.00']])
        ->and((string) $summary->gross())->toBe('4110.52')
        ->and($summary->hasReverseCharge())->toBeTrue();
});

test('advance is split across rates in proportion to the order', function () {
    $order = VatSummary::fromLines([
        ['net' => '1000.00', 'vat_code' => VatCode::Rate23],
        ['net' => '1000.00', 'vat_code' => VatCode::Rate8],
    ]);

    $advance = VatSummary::advance($order, '1000.00');

    // 1000 × 1230/2310 = 532.47 brutto przy 23%, reszta 467.53 przy 8%.
    expect(array_map(fn ($row) => [(string) $row['net'], (string) $row['vat']], $advance->rows()))
        ->toBe([['432.90', '99.57'], ['432.90', '34.63']])
        ->and((string) $advance->gross())->toBe('1000.00');
});

test('admin creates a sales draft with the buyer\'s invoice settings', function () {
    $account = BankAccount::factory()->create(['label' => 'REVOLT21', 'iban' => 'LT51 3250 0000 0000 0000']);
    $gaertner = Contractor::factory()->german()->create([
        'name' => 'Gärtner Elektrotechnik GmbH',
        'tax_id' => '286771111',
        'payment_days' => 14,
        'bank_account_id' => $account->id,
    ]);

    $this->actingAs($this->admin);

    Livewire::test('pages::invoices.form')
        ->set('form.issue_date', '2026-08-06')
        ->set('form.contractor_id', (string) $gaertner->id)
        ->assertSet('form.currency', 'EUR')
        ->assertSet('form.language', 'pl_en')
        ->assertSet('form.due_date', '2026-08-20')
        ->assertSet('form.bank_account_id', (string) $account->id)
        ->assertSet('form.items.0.vat_code', VatCode::OutsideScopeEuServices->value)
        ->set('form.sale_date', '2026-08-06')
        ->set('form.items.0.name', "Ausführungszeitraum: 27.07.2026 - 06.08.2026\n- TKMS Halle 9")
        ->set('form.items.0.unit_price', '3977.80')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $invoice = Invoice::sole();

    expect($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->number)->toBeNull()
        ->and($invoice->gross)->toBe('3977.80')
        ->and($invoice->vat)->toBe('0.00')
        ->and($invoice->buyer['name'])->toBe('Gärtner Elektrotechnik GmbH')
        ->and($invoice->seller['tax_id'])->toBe('8792451081')
        ->and($invoice->counterparty_tax_id)->toBe('DE 286771111')
        ->and($invoice->bank_account['label'])->toBe('REVOLT21');
});

test('only users who manage invoices open the module', function () {
    $employee = User::factory()->create();
    $invoice = Invoice::factory()->create();

    $this->actingAs($employee)->get(route('invoices.index'))->assertForbidden();
    $this->actingAs($employee)->get(route('invoices.show', $invoice))->assertForbidden();
    $this->actingAs($employee)->get(route('invoices.pdf', $invoice))->assertForbidden();
    $this->actingAs($this->admin)->get(route('invoices.index'))->assertOk();
});

test('issuing assigns the KSeF number and freezes the draft', function () {
    fakeNumbering();
    $invoice = Invoice::factory()->withItem('1000.00')->create(['issue_date' => '2026-08-06']);

    Livewire::actingAs($this->admin)
        ->test('pages::invoices.show', ['invoice' => $invoice])
        ->call('issue')
        ->assertHasNoErrors();

    $invoice->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->number)->toBe('7/8/2026')
        ->and($invoice->gross)->toBe('1230.00')
        ->and($invoice->issued_by)->toBe($this->admin->id);

    $this->actingAs($this->admin)->get(route('invoices.edit', $invoice))->assertForbidden();
});

test('without KSeF the invoice is not issued', function () {
    $invoice = Invoice::factory()->withItem()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::invoices.show', ['invoice' => $invoice])
        ->call('issue')
        ->assertHasErrors('issue');

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->number)->toBeNull();
});

test('incomplete drafts list what is missing', function () {
    fakeNumbering();
    $invoice = Invoice::factory()->create();

    expect(fn () => app(InvoiceIssuer::class)->issue($invoice, $this->admin))
        ->toThrow(InvoiceException::class, 'Add at least one item.');

    $exempt = Invoice::factory()->withItem('100.00', '1', VatCode::Exempt)->create();

    expect(app(InvoiceIssuer::class)->problems($exempt))->toBe(['Enter the legal basis of the VAT exemption.']);
});

test('pro formas use their own monthly series', function () {
    $first = Invoice::factory()->kind(InvoiceKind::Proforma)->withItem()->create(['issue_date' => '2026-09-02']);
    $second = Invoice::factory()->kind(InvoiceKind::Proforma)->withItem()->create(['issue_date' => '2026-09-15']);

    $issuer = app(InvoiceIssuer::class);

    expect($issuer->issue($first, $this->admin)->number)->toBe('PF 1/9/2026')
        ->and($issuer->issue($second, $this->admin)->number)->toBe('PF 2/9/2026');
});

test('a correction keeps the original lines and invoices the difference', function () {
    fakeNumbering();
    $original = Invoice::factory()->withItem('100.00', '10')->issued('3/8/2026')->create(['issue_date' => '2026-08-03']);

    $this->actingAs($this->admin);

    Livewire::withQueryParams(['correct' => $original->id])
        ->test('pages::invoices.form')
        ->assertSet('form.kind', 'kor')
        ->assertSet('form.corrected_number', '3/8/2026')
        ->assertCount('form.before_items', 1)
        ->set('form.items.0.quantity', '8')
        ->call('save')
        ->assertHasErrors('form.correction_reason')
        ->set('form.correction_reason', 'Błędna liczba godzin')
        ->call('save')
        ->assertHasNoErrors();

    $correction = Invoice::query()->where('kind', InvoiceKind::Correction)->sole();

    expect($correction->corrected_invoice_id)->toBe($original->id)
        ->and($correction->items->where('is_before', true))->toHaveCount(1)
        ->and($correction->net)->toBe('-200.00')
        ->and($correction->vat)->toBe('-46.00')
        ->and($correction->gross)->toBe('-246.00');

    expect(app(InvoiceIssuer::class)->issue($correction, $this->admin)->number)->toBe('7/'.today()->month.'/'.today()->year);
});

test('advance and final invoices settle the order', function () {
    fakeNumbering();
    $client = Contractor::factory()->create();

    $advance = Invoice::factory()->for($client)->kind(InvoiceKind::Advance)
        ->withItem('10000.00')
        ->create(['advance_amount' => '4920.00']);
    $advance->refreshTotals();

    expect($advance->net)->toBe('4000.00')->and($advance->vat)->toBe('920.00');

    $advance->forceFill(['advance_amount' => '20000.00'])->save();
    expect(app(InvoiceIssuer::class)->problems($advance))
        ->toContain('The advance must be greater than zero and not exceed the order value.');

    $advance->forceFill(['advance_amount' => '4920.00'])->save();
    app(InvoiceIssuer::class)->issue($advance, $this->admin);

    $this->actingAs($this->admin);

    Livewire::withQueryParams(['kind' => 'roz'])
        ->test('pages::invoices.form')
        ->set('form.contractor_id', (string) $client->id)
        ->set('form.items.0.name', 'Instalacja elektryczna')
        ->set('form.items.0.unit_price', '10000.00')
        ->set('form.items.0.vat_code', '23')
        ->set('form.advance_ids', [(string) $advance->id])
        ->call('save')
        ->assertHasNoErrors();

    $final = Invoice::query()->where('kind', InvoiceKind::Final)->sole();

    expect($final->advances->pluck('id')->all())->toBe([$advance->id])
        ->and($final->net)->toBe('6000.00')
        ->and($final->vat)->toBe('1380.00')
        ->and($final->gross)->toBe('7380.00');
});

test('the NBP rate comes from the last business day before the date and is stored', function () {
    Http::fake([
        'api.nbp.pl/*' => Http::response(['table' => 'A', 'code' => 'EUR', 'rates' => [
            ['no' => '146/A/NBP/2026', 'effectiveDate' => '2026-07-31', 'mid' => 4.2563],
            ['no' => '145/A/NBP/2026', 'effectiveDate' => '2026-07-30', 'mid' => 4.2611],
        ]]),
    ]);

    // Poniedziałek 3.08 → piątek 31.07.
    $rate = app(NbpExchangeRates::class)->before('eur', CarbonImmutable::parse('2026-08-03'));

    expect($rate->rate)->toBe('4.2563')
        ->and($rate->table_number)->toBe('146/A/NBP/2026')
        ->and(ExchangeRate::query()->count())->toBe(2);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/rates/a/eur/2026-07-24/2026-08-02/'));

    // 1.08 (sobota): kurs z 31.07 jest w bazie — bez zapytania do NBP.
    expect(app(NbpExchangeRates::class)->before('EUR', CarbonImmutable::parse('2026-08-01'))->rate)->toBe('4.2563');
    Http::assertSentCount(1);
});

test('NBP errors are shown on the form', function () {
    Http::fake(['api.nbp.pl/*' => Http::response('Not Found', 404)]);
    $client = Contractor::factory()->german()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::invoices.form')
        ->set('form.contractor_id', (string) $client->id)
        ->call('fetchExchangeRate')
        ->assertHasErrors('form.exchange_rate');
});

test('the bilingual PDF carries the reverse charge annotation', function () {
    $gaertner = Contractor::factory()->german()->create(['name' => 'Gärtner Elektrotechnik GmbH', 'tax_id' => '286771111']);
    $invoice = Invoice::factory()->for($gaertner)
        ->withItem('3977.80', '1', VatCode::OutsideScopeEuServices, 'Ausführungszeitraum: 27.07.2026 - 06.08.2026')
        ->issued('4/8/2026')
        ->create(['currency' => 'EUR', 'language' => 'pl_en', 'issue_date' => '2026-08-06']);

    $document = new InvoicePdf($invoice->fresh());
    $html = view($document->view(), $document->data())->render();

    expect($html)->toContain('FAKTURA VAT / INVOICE 4/8/2026')
        ->toContain('odwrotne obciążenie / reverse charge')
        ->toContain('VAT ID: DE286771111')
        ->toContain('NIP/VAT: PL8792451081')
        ->toContain("3\u{00A0}977,80 EUR")
        ->toContain('art. 28b');

    $this->actingAs($this->admin)
        ->get(route('invoices.pdf', $invoice))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('a domestic PDF is Polish only and drafts are marked', function () {
    $invoice = Invoice::factory()->withItem('100.00')->create();

    $document = new InvoicePdf($invoice->fresh());
    $html = view($document->view(), $document->data())->render();

    expect($html)->toContain('FAKTURA VAT')
        ->toContain('Projekt')
        ->not->toContain('INVOICE')
        ->not->toContain('odwrotne obciążenie');
});

test('purchase invoices are recorded with the supplier as seller', function () {
    $supplier = Contractor::factory()->supplier()->create(['name' => 'Hurtownia Elektryczna Sp. z o.o.']);

    Livewire::actingAs($this->admin)
        ->withQueryParams(['direction' => 'purchase'])
        ->test('pages::invoices.form')
        ->set('form.contractor_id', (string) $supplier->id)
        ->set('form.items.0.name', 'Kabel NYM-J 5x6')
        ->set('form.items.0.quantity', '100')
        ->set('form.items.0.unit', 'm')
        ->set('form.items.0.unit_price', '12.50')
        ->call('save')
        ->assertHasErrors('form.number')
        ->set('form.number', 'FV 1234/09/2026')
        ->call('save')
        ->assertHasNoErrors();

    $invoice = Invoice::sole();

    expect($invoice->isSales())->toBeFalse()
        ->and($invoice->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->seller['name'])->toBe('Hurtownia Elektryczna Sp. z o.o.')
        ->and($invoice->buyer['name'])->toBe('ASCOMM Adrian Sajnaga')
        ->and($invoice->gross)->toBe('1537.50');
});

test('the list separates sales and purchases and sums issued invoices', function () {
    Invoice::factory()->withItem('1000.00')->issued('1/9/2026')->create(['issue_date' => '2026-09-01']);
    Invoice::factory()->withItem('500.00')->create(['issue_date' => '2026-09-02']);
    Invoice::factory()->purchase()->withItem('200.00')->issued('FV 77/2026')->create(['issue_date' => '2026-09-03']);

    Livewire::actingAs($this->admin)
        ->test('pages::invoices.index')
        ->assertSee('1/9/2026')
        ->assertDontSee('FV 77/2026')
        ->assertSee('1 230,00 PLN')
        ->set('direction', 'purchase')
        ->assertSee('FV 77/2026')
        ->assertDontSee('1/9/2026');
});

test('drafts can be deleted, issued sales invoices cannot', function () {
    $draft = Invoice::factory()->withItem()->create();
    $issued = Invoice::factory()->withItem()->issued('5/9/2026')->create();

    Livewire::actingAs($this->admin)->test('pages::invoices.show', ['invoice' => $draft])->call('delete');
    Livewire::actingAs($this->admin)->test('pages::invoices.show', ['invoice' => $issued])->call('delete')->assertForbidden();

    expect(Invoice::query()->pluck('id')->all())->toBe([$issued->id]);

    Livewire::actingAs($this->admin)->test('pages::invoices.show', ['invoice' => $issued])->call('markPaid');

    expect($issued->refresh()->paid_on?->toDateString())->toBe(today()->toDateString());
});

test('VAT in PLN is required for foreign currency invoices with VAT', function () {
    fakeNumbering();
    $invoice = Invoice::factory()->withItem('100.00')->create(['currency' => 'EUR']);

    expect(app(InvoiceIssuer::class)->problems($invoice))
        ->toBe(['Foreign currency invoices with VAT need the exchange rate (VAT is shown in PLN).']);

    $invoice->forceFill(['exchange_rate' => '4.2563', 'exchange_rate_date' => '2026-07-31', 'exchange_rate_table' => '146/A/NBP/2026'])->save();

    expect(app(InvoiceIssuer::class)->problems($invoice))->toBe([])
        ->and((string) $invoice->toPln(BigDecimal::of('23.00')))->toBe('97.89');
});
