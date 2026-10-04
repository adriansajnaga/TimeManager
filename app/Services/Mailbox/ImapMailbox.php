<?php

namespace App\Services\Mailbox;

use App\Models\MailSetting;
use Carbon\CarbonImmutable;
use Throwable;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
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

                $folders[] = new MailFolder($folder->path, $this->folderName($folder), $unseen, MailFolder::looksLikeTrash($folder->path));
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

            $this->folder($path)->appendMessage($rawMessage, ['\\Seen'], now());
        });
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

        if ($this->client === null) {
            $this->client = (new ClientManager)->make([
                'host' => $this->settings->imap_host,
                'port' => $this->settings->imap_port,
                'encryption' => $this->settings->imap_encryption === 'none' ? false : $this->settings->imap_encryption,
                'validate_cert' => true,
                'username' => $this->settings->username,
                'password' => $this->settings->password,
                'protocol' => 'imap',
                'timeout' => 30,
            ]);
            $this->client->connect();
        }

        return $this->client;
    }

    private function folder(string $path): Folder
    {
        $folder = $this->client()->getFolderByPath($path);

        if ($folder === null) {
            throw new MailboxException(__('The folder :folder does not exist.', ['folder' => $path]));
        }

        return $folder;
    }

    private function sentPath(): ?string
    {
        foreach ($this->client()->getFolders(false) as $folder) {
            /** @var Folder $folder */
            if (MailFolder::looksLikeSent($folder->path)) {
                return $folder->path;
            }
        }

        return null;
    }

    private function trashPath(): ?string
    {
        foreach ($this->client()->getFolders(false) as $folder) {
            /** @var Folder $folder */
            if (MailFolder::looksLikeTrash($folder->path)) {
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
            MailFolder::looksLikeSent($folder->path) => __('Sent'),
            in_array($leaf, ['drafts'], true) => __('Drafts'),
            in_array($leaf, ['junk', 'spam'], true) => __('Spam'),
            in_array($leaf, ['archive'], true) => __('Archive folder'),
            MailFolder::looksLikeTrash($folder->path) => __('Trash'),
            default => MailHeader::decode($folder->name),
        };
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
