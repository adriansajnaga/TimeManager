<?php

use App\Models\MailSetting;
use App\Models\User;
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
use Livewire\Livewire;

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

        public function delete(string $folder, int $uid): bool
        {
            $this->deleted[] = [$folder, $uid];

            return ! MailFolder::looksLikeTrash($folder);
        }

        public function folders(): array
        {
            $this->failIfNeeded();

            return [new MailFolder('INBOX', 'Inbox', 1), new MailFolder('INBOX.Sent', 'Sent', 0), new MailFolder('INBOX.Trash', 'Trash', 0, true)];
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

            return ['messages' => $folder === 'INBOX' ? $messages : [], 'total' => $folder === 'INBOX' ? count($messages) : 0];
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
