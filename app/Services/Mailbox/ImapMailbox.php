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
 * (czysty PHP — rozszerzenie imap nie jest potrzebne).
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
                $folders[] = new MailFolder($folder->path, $folder->full_name);
            }

            usort($folders, fn (MailFolder $a, MailFolder $b) => [strtoupper($a->path) !== 'INBOX', $a->name] <=> [strtoupper($b->path) !== 'INBOX', $b->name]);

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
                    subject: (string) $message->getSubject()->toString(),
                    date: $this->date($message),
                    seen: $message->getFlags()->has('seen'),
                    hasAttachments: $message->hasAttachments(),
                );
            }

            return ['messages' => $messages, 'total' => $total];
        });
    }

    public function message(string $folder, int $uid): MailMessage
    {
        return $this->guard(function () use ($folder, $uid) {
            $message = $this->fetch($folder, $uid);

            return new MailMessage(
                uid: $uid,
                from: $this->address($message->getFrom()->first()),
                to: $this->addresses($message->getTo()->all()),
                cc: $this->addresses($message->getCc()->all()),
                subject: (string) $message->getSubject()->toString(),
                date: $this->date($message),
                html: $message->hasHTMLBody() ? $message->getHTMLBody() : null,
                text: $message->getTextBody(),
                attachments: array_values($message->getAttachments()->values()->map(
                    fn (Attachment $attachment, int $index) => new MailAttachment(
                        index: $index,
                        name: (string) ($attachment->name ?: $attachment->filename ?: 'attachment-'.($index + 1)),
                        mime: (string) ($attachment->getMimeType() ?? 'application/octet-stream'),
                        size: (int) $attachment->size,
                    ),
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

            return new MailAttachment(
                index: $index,
                name: (string) ($attachment->name ?: $attachment->filename ?: 'attachment-'.($index + 1)),
                mime: (string) ($attachment->getMimeType() ?? 'application/octet-stream'),
                size: (int) $attachment->size,
                content: (string) $attachment->content,
            );
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

    private function fetch(string $folder, int $uid): Message
    {
        return $this->folder($folder)->query()->getMessageByUid($uid);
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

        return $address->personal !== '' ? $address->personal.' <'.$address->mail.'>' : $address->mail;
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
