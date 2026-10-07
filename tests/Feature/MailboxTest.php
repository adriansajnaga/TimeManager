<?php

use App\Models\Invoice;
use App\Models\MailSetting;
use App\Models\User;
use App\Services\Invoices\InvoiceException;
use App\Services\Invoices\InvoiceMailer;
use App\Services\Mailbox\ImapMailbox;
use App\Services\Mailbox\MailAttachment;
use App\Services\Mailbox\Mailbox;
use App\Services\Mailbox\MailboxException;
use App\Services\Mailbox\MailFolder;
use App\Services\Mailbox\MailHeader;
use App\Services\Mailbox\MailMessage;
use App\Services\Mailbox\MailSummary;
use App\Services\Mailbox\MailView;
use Carbon\CarbonImmutable;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Email;

/**
 * Skrzynka w pamięci zamiast serwera IMAP.
 */
function fakeMailbox(?string $failWith = null): object
{
    $fake = new class($failWith) implements Mailbox
    {
        /** @var list<array{string, int, bool}> */
        public array $seenCalls = [];

        /** @var list<array{string, int}> */
        public array $deleted = [];

        public function __construct(private readonly ?string $failWith) {}

        public function setSeen(string $folder, int $uid, bool $seen): void
        {
            $this->seenCalls[] = [$folder, $uid, $seen];
        }

        /** @var list<string> */
        public array $appended = [];

        public function appendToSent(string $rawMessage): void
        {
            $this->appended[] = $rawMessage;
        }

        /** @var list<string> */
        public array $emptied = [];

        public function emptyFolder(string $folder): int
        {
            $this->emptied[] = $folder;

            return 3;
        }

        public function delete(string $folder, int $uid): bool
        {
            $this->deleted[] = [$folder, $uid];

            return ! MailFolder::looksLikeTrash($folder);
        }

        public function folders(): array
        {
            $this->failIfNeeded();

            return [new MailFolder('INBOX', 'Inbox', 1), new MailFolder('INBOX.Sent', 'Sent', 0), new MailFolder('INBOX.spam', 'Spam', 0, isSpam: true), new MailFolder('INBOX.Trash', 'Trash', 0, true)];
        }

        public function messages(string $folder, int $page, int $perPage, string $search = ''): array
        {
            $this->failIfNeeded();

            $messages = [
                new MailSummary(12, 'Gärtner Buchhaltung <buchhaltung@gaertner.test>', 'Zahlungsavis 4/8/2026', CarbonImmutable::parse('2026-09-02 10:15'), false, true),
                new MailSummary(11, 'Hurtownia <faktury@hurtownia.test>', 'Faktura FV 1234/09/2026', CarbonImmutable::parse('2026-09-01 08:00'), true, false),
            ];

            if ($search !== '') {
                $messages = array_values(array_filter($messages, fn (MailSummary $message) => str_contains(mb_strtolower($message->subject), mb_strtolower($search))));
            }

            // Kosz i Spam mają wiadomości (przycisk „Usuń wszystkie”), Wysłane są puste.
            $withMessages = in_array($folder, ['INBOX', 'INBOX.Trash', 'INBOX.spam'], true);

            return ['messages' => $withMessages ? $messages : [], 'total' => $withMessages ? count($messages) : 0];
        }

        public function message(string $folder, int $uid): MailMessage
        {
            $this->failIfNeeded();

            return new MailMessage(
                uid: $uid,
                from: 'Gärtner Buchhaltung <buchhaltung@gaertner.test>',
                to: ['faktury@ascomm.test'],
                cc: [],
                subject: 'Zahlungsavis 4/8/2026',
                date: CarbonImmutable::parse('2026-09-02 10:15'),
                html: '<p>Zahlung erfolgt.</p><script>alert(1)</script><img src="https://tracker.test/pixel.gif">',
                text: 'Zahlung erfolgt.',
                attachments: [new MailAttachment(0, 'Avis.pdf', 'application/pdf', 2048)],
            );
        }

        public function attachment(string $folder, int $uid, int $index): MailAttachment
        {
            $this->failIfNeeded();

            return new MailAttachment($index, 'Avis.pdf', 'application/pdf', 9, '%PDF-1.4 ');
        }

        public function ping(): void
        {
            $this->failIfNeeded();
        }

        private function failIfNeeded(): void
        {
            if ($this->failWith !== null) {
                throw new MailboxException($this->failWith);
            }
        }
    };

    app()->instance(Mailbox::class, $fake);

    return $fake;
}

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

test('the mailbox lists folders and messages, newest first', function () {
    fakeMailbox();

    Livewire::actingAs($this->admin)
        ->test('pages::mailbox.index')
        ->assertSee('Sent')
        ->assertSee('Zahlungsavis 4/8/2026')
        ->assertSee('Faktura FV 1234/09/2026')
        ->set('search', 'FV 1234')
        ->assertDontSee('Zahlungsavis 4/8/2026')
        ->call('openFolder', 'INBOX.Sent')
        ->assertSee('No messages.');
});

test('a message opens next to the list in a sandboxed frame without scripts and remote images', function () {
    fakeMailbox();

    $page = Livewire::actingAs($this->admin)
        ->test('pages::mailbox.index')
        ->assertSee('Select a message to read it.')
        ->call('open', 12)
        ->assertSee('Zahlung')
        ->assertSee('Avis.pdf')
        ->assertSee('PDF · 2 kB')
        ->assertSee('sandbox="allow-popups allow-popups-to-escape-sandbox"', false)
        ->assertSee('Show images');

    $document = MailView::document(app(Mailbox::class)->message('INBOX', 12));

    expect($document)->toContain("default-src 'none'")
        ->toContain('img-src data: cid:;')
        ->and(MailView::document(app(Mailbox::class)->message('INBOX', 12), remoteImages: true))->toContain('img-src data: cid: https: http:');

    $page->call('showImages')->assertDontSee('Show images');
});

test('messages can be marked unread and deleted to the trash', function () {
    $fake = fakeMailbox();

    Livewire::actingAs($this->admin)
        ->test('pages::mailbox.index', ['uid' => 12])
        ->call('toggleSeen', 12, false)
        ->assertSet('uid', 0)
        ->call('delete', 11)
        ->call('openFolder', 'INBOX.Trash')
        ->call('delete', 5);

    expect($fake->seenCalls)->toBe([['INBOX', 12, false]])
        ->and($fake->deleted)->toBe([['INBOX', 11], ['INBOX.Trash', 5]]);
});

test('encoded mail headers are decoded, also when a character is split between words', function () {
    expect(MailHeader::decode('=?UTF-8?Q?Fwd=3A_Sajanga_pe=C5=82n?='))->toBe('Fwd: Sajanga pełn')
        ->and(MailHeader::decode('=?UTF-8?Q?Fwd=3A_Sajanga_pe=C5?=
 =?UTF-8?Q?=82nomocnictwo?='))->toBe('Fwd: Sajanga pełnomocnictwo')
        ->and(MailHeader::decode('=?iso-8859-2?Q?Zam=F3wienie_nr_5?='))->toBe('Zamówienie nr 5')
        ->and(MailHeader::decode('=?UTF-8?B?R8OkcnRuZXIgRWxla3Ryb3RlY2huaWs=?= <a@b.de>'))->toBe('Gärtner Elektrotechnik <a@b.de>')
        ->and(MailHeader::decode('Re: =?utf-8?q?Za=C5=BC=C3=B3=C5=82=C4=87?= test'))->toBe('Re: Zażółć test')
        ->and(MailHeader::field('From: a@b
Subject: =?UTF-8?Q?Faktura_?=
 =?UTF-8?Q?4/8/2026?=
To: x', 'Subject'))->toBe('Faktura 4/8/2026');
});

test('attachments get an icon by type and PDFs open in the browser', function () {
    expect((new MailAttachment(0, 'Faktura.pdf', 'application/octet-stream', 1))->icon()[0])->toBe('document-text')
        ->and((new MailAttachment(0, 'Oferta.docx', 'application/octet-stream', 1))->kind())->toBe('word')
        ->and((new MailAttachment(0, 'Lista.xlsx', 'application/octet-stream', 1))->kind())->toBe('excel')
        ->and((new MailAttachment(0, 'Zdjęcie.JPG', 'image/jpeg', 1))->previewable())->toBeTrue()
        ->and((new MailAttachment(0, 'Makro.docm', 'application/octet-stream', 1))->previewable())->toBeFalse();

    fakeMailbox();

    $this->actingAs($this->admin)
        ->get(route('mailbox.attachment', ['folder' => 'INBOX', 'uid' => 12, 'index' => 0, 'inline' => 1]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('attachments are downloaded, never rendered inline', function () {
    fakeMailbox();

    $this->actingAs($this->admin)
        ->get(route('mailbox.attachment', ['folder' => 'INBOX', 'uid' => 12, 'index' => 0]))
        ->assertOk()
        ->assertDownload('Avis.pdf')
        ->assertHeader('Content-Type', 'application/octet-stream');
});

test('mailbox errors are shown instead of the list', function () {
    fakeMailbox('Login failed.');

    Livewire::actingAs($this->admin)
        ->test('pages::mailbox.index')
        ->assertSee('Cannot open the mailbox')
        ->assertSee('Login failed.');
});

test('only administrators read the company mailbox', function () {
    fakeMailbox();

    $this->actingAs(User::factory()->create())->get(route('mailbox.index'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('mailbox.index'))->assertOk();
});

test('the IMAP mailbox needs a server and credentials', function () {
    MailSetting::query()->create(['host' => 'mail.ascomm.test', 'from_address' => 'faktury@ascomm.test', 'username' => 'faktury@ascomm.test']);

    expect(fn () => ImapMailbox::fromSettings()->folders())->toThrow(MailboxException::class, 'Configure the mailbox (IMAP)');

    Livewire::actingAs($this->admin)
        ->test('pages::admin.mail')
        ->set('host', 'mail.ascomm.test')
        ->set('from_address', 'faktury@ascomm.test')
        ->set('imap_host', 'mail.ascomm.test')
        ->set('imap_port', 993)
        ->call('save')
        ->assertHasNoErrors();

    expect(MailSetting::current()->imap_host)->toBe('mail.ascomm.test');
});

test('sent e-mails are saved to the Sent folder of the mailbox', function () {
    $fake = fakeMailbox();
    $settings = MailSetting::query()->create([
        'host' => 'mail.ascomm.test', 'from_address' => 'faktury@ascomm.test',
        'username' => 'faktury@ascomm.test', 'password' => 'secret', 'imap_host' => 'mail.ascomm.test',
    ]);

    $email = (new Email)->from('faktury@ascomm.test')->to('kunde@example.test')->subject('Rechnung 4/9/2026')->text('Anbei');
    $sent = new SentMessage(new Symfony\Component\Mailer\SentMessage($email, Envelope::create($email)));

    InvoiceMailer::saveToSent($sent, $settings);

    expect($fake->appended)->toHaveCount(1)
        ->and($fake->appended[0])->toContain('Subject: Rechnung 4/9/2026');

    expect(MailFolder::looksLikeSent('INBOX.Sent'))->toBeTrue()
        ->and(MailFolder::looksLikeSent('Wysłane'))->toBeTrue()
        ->and(MailFolder::looksLikeSent('INBOX'))->toBeFalse();
});

test('after a rejected login the app never retries on its own, only after new credentials or a manual test', function () {
    $settings = MailSetting::query()->create([
        'host' => 'mail.ascomm.test', 'from_address' => 'faktury@ascomm.test',
        'username' => 'faktury@ascomm.test', 'password' => 'secret', 'imap_host' => 'imap.invalid',
    ]);
    $blocked = __('The mail server rejected the login, so the app no longer tries to log in. Check the password in webmail, then save it in Administration → E-mail or use “Test the mailbox”.');

    // Stan po odrzuconym logowaniu — zapisany w bazie, więc przetrwa wdrożenie (czyszczenie cache) i upływ czasu.
    MailSetting::blockLogin();
    Cache::flush();
    $this->travel(2)->days();

    expect(fn () => ImapMailbox::fromSettings()->ping())->toThrow(MailboxException::class, $blocked);

    // Wysyłka SMTP (ten sam login) też nie próbuje.
    $admin = User::factory()->admin()->create();
    expect(fn () => app(InvoiceMailer::class)->send(new Invoice, $admin, ['klient@example.test'], [], 'Rechnung', 'Text'))
        ->toThrow(InvoiceException::class, $blocked);

    // Ponowne zapisanie tych samych danych blokady nie zdejmuje.
    Livewire::actingAs($admin)->test('pages::admin.mail')->call('save')->assertHasNoErrors();
    expect($settings->fresh()->loginBlocked())->toBeTrue();

    // Nowe hasło — tak.
    Livewire::actingAs($admin)->test('pages::admin.mail')->set('password', 'new-secret')->call('save')->assertHasNoErrors();
    expect($settings->fresh()->loginBlocked())->toBeFalse();
});

test('trash and spam can be emptied at once, other folders cannot', function () {
    $fake = fakeMailbox();

    $page = Livewire::actingAs($this->admin)->test('pages::mailbox.index')
        ->assertDontSee(__('Delete all'))
        ->call('openFolder', 'INBOX.Trash')
        ->assertSee(__('Delete all'))
        ->call('emptyFolder');

    $page->call('openFolder', 'INBOX.spam')
        ->assertSee(__('Delete all'))
        ->call('emptyFolder');

    expect($fake->emptied)->toBe(['INBOX.Trash', 'INBOX.spam'])
        ->and(MailFolder::looksLikeSpam('INBOX.Junk'))->toBeTrue()
        ->and(MailFolder::looksLikeSpam('INBOX.spam'))->toBeTrue()
        ->and(MailFolder::looksLikeSpam('INBOX'))->toBeFalse();
});
