<?php

use App\Enums\InvoiceStatus;
use App\Enums\VatCode;
use App\Mail\InvoiceMail;
use App\Models\Contractor;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\MailSetting;
use App\Models\User;
use App\Models\WorkWeek;
use App\Services\Invoices\InvoiceException;
use App\Services\Invoices\InvoiceMailer;
use App\Services\Settlements\SettlementService;
use Database\Seeders\CompanySeeder;
use Database\Seeders\GaertnerSeeder;
use Database\Seeders\ReferencePackageSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['name' => 'Adrian Sajnaga', 'email' => 'adrian@ascomm.test']);
    $this->seed([CompanySeeder::class, GaertnerSeeder::class, ReferencePackageSeeder::class]);

    MailSetting::query()->create([
        'host' => 'mail.ascomm.test', 'port' => 465, 'encryption' => 'ssl',
        'username' => 'faktury@ascomm.test', 'password' => 'smtp-secret',
        'from_address' => 'faktury@ascomm.test', 'from_name' => 'Adrian Sajnaga',
    ]);

    $this->gaertner = Contractor::query()->where('name', 'Gärtner Elektrotechnik GmbH')->sole();
    $this->invoice = app(SettlementService::class)->createInvoice($this->gaertner, WorkWeek::query()->get(), $this->admin)->invoice;
    $this->invoice->forceFill(['status' => InvoiceStatus::Issued, 'number' => '4/9/2026', 'issue_date' => '2026-09-01'])->save();

    Mail::fake();
});

test('the e-mail is prepared from the client templates and sent only on demand', function () {
    $page = Livewire::actingAs($this->admin)
        ->test('pages::invoices.show', ['invoice' => $this->invoice])
        ->call('prepareEmail')
        ->assertSet('mailTo', '160_rechnungen_gaertner@handwerksgruppe.de')
        ->assertSet('mailSubject', 'Rechnung 4/9/2026')
        ->assertSet('mailAttachment', '2026_9_4 ASCOMM-zusammengefügt.pdf');

    expect($page->get('mailBody'))->toContain('anbei erhalten Sie Ihre Rechnung Nr. 4/9/2026 vom 01.09.2026.')
        ->toContain('Adrian Sajnaga');

    Mail::assertNothingSent();

    $page->set('mailCc', 'buchhaltung@ascomm.test')
        ->call('sendEmail')
        ->assertHasNoErrors();

    Mail::assertSent(InvoiceMail::class, function (InvoiceMail $mail) {
        return $mail->hasTo('160_rechnungen_gaertner@handwerksgruppe.de')
            && $mail->hasCc('buchhaltung@ascomm.test')
            && $mail->subjectLine === 'Rechnung 4/9/2026'
            && count($mail->attachments()) === 1;
    });

    $log = EmailLog::sole();

    expect($log->sent_at)->not->toBeNull()
        ->and($log->to)->toBe(['160_rechnungen_gaertner@handwerksgruppe.de'])
        ->and($log->attachment)->toBe('2026_9_4 ASCOMM-zusammengefügt.pdf')
        ->and($log->sent_by)->toBe($this->admin->id)
        ->and($this->invoice->fresh()->emailed_at)->not->toBeNull();
});

test('drafts are not sent and invalid addresses are rejected', function () {
    $draft = Invoice::factory()->for($this->gaertner)->create();

    expect(fn () => app(InvoiceMailer::class)->send($draft, $this->admin, ['a@b.de'], [], 'S', 'B'))
        ->toThrow(InvoiceException::class);

    Livewire::actingAs($this->admin)
        ->test('pages::invoices.show', ['invoice' => $this->invoice])
        ->call('prepareEmail')
        ->set('mailTo', 'not-an-address')
        ->call('sendEmail')
        ->assertHasErrors('mailTo');

    Mail::assertNothingSent();
});

test('a failed delivery is logged with the error', function () {
    MailSetting::query()->update(['host' => null]);

    Livewire::actingAs($this->admin)
        ->test('pages::invoices.show', ['invoice' => $this->invoice])
        ->call('prepareEmail')
        ->call('sendEmail')
        ->assertHasErrors('mail');

    expect($this->invoice->fresh()->emailed_at)->toBeNull();
});

test('mail settings keep the password encrypted and send a test message', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.mail')
        ->set('host', 'smtp.ascomm.test')
        ->set('password', 'new-smtp-password')
        ->call('save')
        ->assertHasNoErrors()
        ->call('test')
        ->assertHasNoErrors();

    expect(DB::table('mail_settings')->value('password'))->not->toContain('new-smtp-password')
        ->and(MailSetting::current()->password)->toBe('new-smtp-password')
        ->and(MailSetting::current()->verified_at)->not->toBeNull()
        ->and(config('mail.mailers.tm_smtp.host'))->toBe('smtp.ascomm.test');

    $this->actingAs(User::factory()->create())->get(route('admin.mail'))->assertForbidden();
});

test('a plain invoice is sent with its own PDF', function () {
    $invoice = Invoice::factory()->for(Contractor::factory()->create(['email' => 'biuro@klient.test', 'email_to' => null]))->create(['number' => '5/9/2026', 'status' => InvoiceStatus::Issued]);
    $invoice->items()->create(['position' => 1, 'name' => 'Usługa', 'quantity' => '1', 'unit_price' => '100', 'vat_code' => VatCode::Rate23]);

    $draft = app(InvoiceMailer::class)->draft($invoice, $this->admin);

    expect($draft['to'])->toBe('biuro@klient.test')
        ->and($draft['subject'])->toBe('Faktura 5/9/2026')
        ->and($draft['attachment'])->toBe('Faktura_5-9-2026.pdf');
});
