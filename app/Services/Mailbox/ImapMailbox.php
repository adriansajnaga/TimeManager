<?php

namespace App\Services\Mailbox;

use App\Models\MailSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

/**
 * Skrzynka IMAP z ustawień (Administracja → E-mail), biblioteka webklex/php-imap
 * (czysty PHP — rozszerzenie imap nie jest potrzebne). Nagłówki dekodujemy sami
 * (MailHeader), bo bez rozszerzenia imap tematy z polskimi znakami zostawały zakodowane.
 */
final class ImapMailbox implements Mailbox
{
    private ?Client $client = null;

    public function __construct(private readonly MailSetting $settings) {}

    public static function fromSettings(): self
    {
        return new self(MailSetting::current());
    }

    public function folders(): array
    {
        return $this->guard(function () {
            $folders = [];

            foreach ($this->client()->getFolders(false) as $folder) {
                /** @var Folder $folder */
                try {
                    $unseen = (int) ($folder->status()['unseen'] ?? 0);
                } catch (Throwable) {
                    $unseen = null;
                }

                $folders[] = new MailFolder($folder->path, $this->folderName($folder), $unseen, $this->isTrash($folder), $this->isSpam($folder));
            }

            usort($folders, fn (MailFolder $a, MailFolder $b) => [strtoupper($a->path) !== 'INBOX', $a->isTrash, $a->name] <=> [strtoupper($b->path) !== 'INBOX', $b->isTrash, $b->name]);

            return $folders;
        });
    }

    public function messages(string $folder, int $page, int $perPage, string $search = ''): array
    {
        return $this->guard(function () use ($folder, $page, $perPage, $search) {
            $query = function () use ($folder, $search) {
                $query = $this->folder($folder)->query();
                $query = $search !== '' ? $query->whereText($search) : $query->all();

                return $query->leaveUnread()->setFetchBody(false)->softFail();
            };

            $total = $query()->count();
            $messages = [];

            foreach ($query()->setFetchOrderDesc()->limit($perPage, $page)->get() as $message) {
                /** @var Message $message */
                $messages[] = new MailSummary(
                    uid: $message->getUid(),
                    from: $this->address($message->getFrom()->first()),
                    subject: $this->subject($message),
                    date: $this->date($message),
                    seen: $message->getFlags()->has('seen'),
                    hasAttachments: $message->hasAttachments(),
                );
            }

            // Biblioteka zwraca stronę rosnąco — najnowsze (najwyższy UID) mają być na górze.
            usort($messages, fn (MailSummary $a, MailSummary $b) => $b->uid <=> $a->uid);

            return ['messages' => $messages, 'total' => $total];
        });
    }

    public function message(string $folder, int $uid): MailMessage
    {
        return $this->guard(function () use ($folder, $uid) {
            $message = $this->fetch($folder, $uid);

            // Otwarcie = przeczytana (pobieramy z FT_PEEK, więc flagę ustawiamy sami).
            if (! $message->getFlags()->has('seen')) {
                $message->setFlag('Seen');
            }

            return new MailMessage(
                uid: $uid,
                from: $this->address($message->getFrom()->first()),
                to: $this->addresses($message->getTo()->all()),
                cc: $this->addresses($message->getCc()->all()),
                subject: $this->subject($message),
                date: $this->date($message),
                html: $message->hasHTMLBody() ? $message->getHTMLBody() : null,
                text: $message->getTextBody(),
                attachments: array_values($message->getAttachments()->values()->map(
                    fn (Attachment $attachment, int $index) => $this->attachmentOf($attachment, $index),
                )->all()),
            );
        });
    }

    public function attachment(string $folder, int $uid, int $index): MailAttachment
    {
        return $this->guard(function () use ($folder, $uid, $index) {
            $attachment = $this->fetch($folder, $uid)->getAttachments()->values()->get($index);

            if (! $attachment instanceof Attachment) {
                throw new MailboxException(__('The attachment does not exist.'));
            }

            return $this->attachmentOf($attachment, $index, withContent: true);
        });
    }

    public function setSeen(string $folder, int $uid, bool $seen): void
    {
        $this->guard(function () use ($folder, $uid, $seen) {
            $message = $this->fetch($folder, $uid, withBody: false);
            $seen ? $message->setFlag('Seen') : $message->unsetFlag('Seen');
        });
    }

    public function emptyFolder(string $folder): int
    {
        return $this->guard(function () use ($folder) {
            $imapFolder = $this->folder($folder);

            if (! $this->isTrash($imapFolder) && ! $this->isSpam($imapFolder)) {
                throw new MailboxException(__('Only Trash and Spam can be emptied.'));
            }

            $count = (int) ($imapFolder->status()['messages'] ?? 0);

            if ($count === 0) {
                return 0;
            }

            $connection = $this->client()->getConnection();

            if (! $connection instanceof ImapProtocol) {
                throw new MailboxException(__('Mail server error: :message', ['message' => 'IMAP']));
            }

            $this->client()->openFolder($imapFolder->path, true);
            $connection->requestAndResponse('STORE', ['1:*', '+FLAGS.SILENT', '(\Deleted)'])->validate();
            $connection->expunge()->validate();

            return $count;
        });
    }

    public function delete(string $folder, int $uid): bool
    {
        return $this->guard(function () use ($folder, $uid) {
            $message = $this->fetch($folder, $uid, withBody: false);
            $trash = $this->trashPath();

            if ($trash !== null && $trash !== $folder) {
                $message->move($trash, true);

                return true;
            }

            $message->delete(true);

            return false;
        });
    }

    public function appendToSent(string $rawMessage): void
    {
        $this->guard(function () use ($rawMessage) {
            $path = filled($this->settings->sent_folder) ? (string) $this->settings->sent_folder : $this->sentPath();

            if ($path === null) {
                throw new MailboxException(__('The mailbox has no Sent folder.'));
            }

            $this->append($this->folder($path)->path, $rawMessage);
        });
    }

    /**
     * APPEND napisany ręcznie: biblioteka oczekuje „+” zaraz po komendzie i nie pokazuje odpowiedzi
     * serwera („failed to send literal string”). Tu pomijamy linie nieoznaczone i zwracamy treść odmowy.
     */
    private function append(string $path, string $rawMessage): void
    {
        $connection = $this->client()->getConnection();

        if (! $connection instanceof ImapProtocol) {
            throw new MailboxException(__('Mail server error: :message', ['message' => 'IMAP']));
        }

        $response = new Response(0);
        $tag = 'TMA'.random_int(1000, 9999);
        $quotedPath = '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $path).'"';

        $connection->write($response, $tag.' APPEND '.$quotedPath.' (\\Seen) {'.strlen($rawMessage).'}');

        do {
            $line = trim($connection->nextLine($response));
        } while (str_starts_with($line, '* '));

        if (! str_starts_with($line, '+')) {
            throw new MailboxException(__('Mail server error: :message', ['message' => $this->stripTag($line, $tag)]));
        }

        $connection->write($response, $rawMessage);

        do {
            $line = trim($connection->nextLine($response));
        } while (! str_starts_with($line, $tag.' '));

        if (! str_starts_with($line, $tag.' OK')) {
            throw new MailboxException(__('Mail server error: :message', ['message' => $this->stripTag($line, $tag)]));
        }
    }

    private function stripTag(string $line, string $tag): string
    {
        return str_starts_with($line, $tag.' ') ? substr($line, strlen($tag) + 1) : $line;
    }

    public function ping(): void
    {
        $this->guard(fn () => $this->client()->getFolders(false));
    }

    private function client(): Client
    {
        if (! $this->settings->hasMailbox()) {
            throw new MailboxException(__('Configure the mailbox (IMAP) in Administration → E-mail first.'));
        }

        // Jedno logowanie naraz: równoległe żądania (np. załączniki) czekają i korzystają z wyniku pierwszego,
        // zamiast słać serię błędnych haseł.
        $this->client ??= Cache::lock('mailbox.login', 40)->block(35, fn () => $this->connect());

        return $this->client;
    }

    private function connect(): Client
    {
        // Po odrzuconym logowaniu nie próbujemy wcale (także po wdrożeniu czy po przerwie): każda kolejna
        // próba to dla serwera następny „atak” (cPHulk/Dovecot) i blokada konta się przedłuża.
        if (MailSetting::query()->whereNotNull('login_failed_at')->exists()) {
            throw new MailboxException(__('The mail server rejected the login, so the app no longer tries to log in. Check the password in webmail, then save it in Administration → E-mail or use “Test the mailbox”.'));
        }

        $client = (new ClientManager)->make([
            'host' => $this->settings->imap_host,
            'port' => $this->settings->imap_port,
            'encryption' => $this->settings->imap_encryption === 'none' ? false : $this->settings->imap_encryption,
            'validate_cert' => true,
            'username' => $this->settings->username,
            'password' => $this->settings->password,
            'protocol' => 'imap',
            'timeout' => 30,
        ]);

        try {
            $client->connect();
        } catch (Throwable $exception) {
            if (self::isAuthFailure($exception)) {
                MailSetting::blockLogin();
                Log::warning('IMAP login rejected; mailbox login blocked until new credentials or a manual test.', ['host' => $this->settings->imap_host, 'user' => $this->settings->username]);
            }

            throw $exception;
        }

        return $client;
    }

    /**
     * Zdejmuje blokadę logowania (zapis nowych danych, ręczny test skrzynki).
     */
    public static function resumeLogin(): void
    {
        MailSetting::resumeLogin();
    }

    /**
     * Sesja kończona poleceniem LOGOUT — serwer nie liczy zerwanych połączeń.
     */
    public function __destruct()
    {
        try {
            $this->client?->disconnect();
        } catch (Throwable) {
            // Połączenie i tak się zamyka.
        }
    }

    public static function isAuthFailure(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof AuthFailedException || str_contains(strtoupper($current->getMessage()), 'AUTHENTICATIONFAILED')) {
                return true;
            }
        }

        return false;
    }

    private function folder(string $path): Folder
    {
        // Ścieżki mamy z serwera (już w UTF-7 IMAP, np. „INBOX.Wys&AUI-ane”) — bez ponownego kodowania.
        $folder = $this->client()->getFolderByPath($path, utf7: true);

        if ($folder === null) {
            throw new MailboxException(__('The folder :folder does not exist.', ['folder' => $path]));
        }

        return $folder;
    }

    private function sentPath(): ?string
    {
        foreach ($this->client()->getFolders(false) as $folder) {
            /** @var Folder $folder */
            if ($this->isSent($folder)) {
                return $folder->path;
            }
        }

        return null;
    }

    private function trashPath(): ?string
    {
        foreach ($this->client()->getFolders(false) as $folder) {
            /** @var Folder $folder */
            if ($this->isTrash($folder)) {
                return $folder->path;
            }
        }

        return null;
    }

    private function fetch(string $folder, int $uid, bool $withBody = true): Message
    {
        $query = $this->folder($folder)->query()->leaveUnread();

        if (! $withBody) {
            $query->setFetchBody(false);
        }

        return $query->getMessageByUid($uid);
    }

    /**
     * Polska nazwa folderu dla znanych folderów serwera (cPanel/Dovecot: INBOX.Sent itd.).
     */
    private function folderName(Folder $folder): string
    {
        $leaf = strtolower((string) preg_replace('/^INBOX[.\/]/i', '', $folder->path));

        return match (true) {
            strtoupper($folder->path) === 'INBOX' => __('Inbox'),
            $this->isSent($folder) => __('Sent'),
            in_array($leaf, ['drafts'], true) => __('Drafts'),
            in_array($leaf, ['junk', 'spam'], true) => __('Spam'),
            in_array($leaf, ['archive'], true) => __('Archive folder'),
            $this->isTrash($folder) => __('Trash'),
            default => MailHeader::decode($folder->name),
        };
    }

    /**
     * Po nazwie odkodowanej z UTF-7 IMAP — „Wysłane” leży na serwerze jako „Wys&AUI-ane”.
     */
    private function isSent(Folder $folder): bool
    {
        return MailFolder::looksLikeSent($folder->path) || MailFolder::looksLikeSent($folder->full_name);
    }

    private function isSpam(Folder $folder): bool
    {
        return MailFolder::looksLikeSpam($folder->path) || MailFolder::looksLikeSpam($folder->full_name);
    }

    private function isTrash(Folder $folder): bool
    {
        return MailFolder::looksLikeTrash($folder->path) || MailFolder::looksLikeTrash($folder->full_name);
    }

    private function subject(Message $message): string
    {
        $raw = $message->getHeader()?->raw;

        return ($raw !== null ? MailHeader::field($raw, 'Subject') : null)
            ?? MailHeader::decode((string) $message->getSubject()->toString());
    }

    private function attachmentOf(Attachment $attachment, int $index, bool $withContent = false): MailAttachment
    {
        return new MailAttachment(
            index: $index,
            name: MailHeader::decode((string) ($attachment->name ?: $attachment->filename ?: 'attachment-'.($index + 1))),
            mime: (string) ($attachment->getMimeType() ?? 'application/octet-stream'),
            size: (int) $attachment->size,
            content: $withContent ? (string) $attachment->content : null,
        );
    }

    private function date(Message $message): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::instance($message->getDate()->toDate())->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    private function address(mixed $address): string
    {
        if (! $address instanceof Address) {
            return '';
        }

        $name = MailHeader::decode($address->personal);

        return $name !== '' && $name !== $address->mail ? $name.' <'.$address->mail.'>' : $address->mail;
    }

    /**
     * @param  array<int, mixed>  $addresses
     * @return list<string>
     */
    private function addresses(array $addresses): array
    {
        return array_values(array_filter(array_map(fn ($address) => $this->address($address), $addresses)));
    }

    /**
     * Błędy biblioteki (połączenie, logowanie, brak wiadomości) jako komunikat dla użytkownika.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function guard(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (MailboxException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw new MailboxException(__('Mail server error: :message', ['message' => $exception->getMessage()]));
        }
    }
}
