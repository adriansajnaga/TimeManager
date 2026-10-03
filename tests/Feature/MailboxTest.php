<?php

use App\Models\MailSetting;
use App\Models\User;
use App\Services\Mailbox\ImapMailbox;
use App\Services\Mailbox\MailAttachment;
use App\Services\Mailbox\Mailbox;
use App\Services\Mailbox\MailboxException;
use App\Services\Mailbox\MailFolder;
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
        public function __construct(private readonly ?string $failWith) {}

        public function folders(): array
        {
            $this->failIfNeeded();

            return [new MailFolder('INBOX', 'INBOX'), new MailFolder('INBOX.Sent', 'Sent')];
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

test('a message is shown in a sandboxed frame without scripts and remote images', function () {
    fakeMailbox();

    $page = Livewire::actingAs($this->admin)
        ->test('pages::mailbox.show', ['folder' => 'INBOX', 'uid' => 12])
        ->assertSee('Zahlungsavis 4/8/2026')
        ->assertSee('Avis.pdf (2 kB)')
        ->assertSee('sandbox="allow-popups allow-popups-to-escape-sandbox"', false)
        ->assertSee('Show images');

    $document = MailView::document(app(Mailbox::class)->message('INBOX', 12));

    expect($document)->toContain("default-src 'none'")
        ->toContain('img-src data: cid:;')
        ->and(MailView::document(app(Mailbox::class)->message('INBOX', 12), remoteImages: true))->toContain('img-src data: cid: https: http:');

    $page->call('showImages')->assertDontSee('Show images');
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
