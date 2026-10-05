<?php

namespace App\Services\Mailbox;

/**
 * Skrzynka firmowa: przeglądanie, oznaczanie przeczytanych i usuwanie do Kosza.
 */
interface Mailbox
{
    /**
     * @return list<MailFolder>
     *
     * @throws MailboxException
     */
    public function folders(): array;

    /**
     * Wiadomości folderu od najnowszych.
     *
     * @return array{messages: list<MailSummary>, total: int}
     *
     * @throws MailboxException
     */
    public function messages(string $folder, int $page, int $perPage, string $search = ''): array;

    /**
     * @throws MailboxException
     */
    public function message(string $folder, int $uid): MailMessage;

    /**
     * Załącznik z treścią.
     *
     * @throws MailboxException
     */
    public function attachment(string $folder, int $uid, int $index): MailAttachment;

    /**
     * Oznacza wiadomość jako przeczytaną albo nieprzeczytaną.
     *
     * @throws MailboxException
     */
    public function setSeen(string $folder, int $uid, bool $seen): void;

    /**
     * Przenosi do Kosza (true) albo — w Koszu lub bez Kosza — usuwa na stałe (false).
     *
     * @throws MailboxException
     */
    public function delete(string $folder, int $uid): bool;

    /**
     * Usuwa na stałe wszystkie wiadomości z Kosza albo Spamu; zwraca ich liczbę.
     *
     * @throws MailboxException gdy folder nie jest Koszem ani Spamem
     */
    public function emptyFolder(string $folder): int;

    /**
     * Zapisuje kopię wysłanej wiadomości (surowy MIME) w folderze Wysłane, jako przeczytaną.
     *
     * @throws MailboxException
     */
    public function appendToSent(string $rawMessage): void;

    /**
     * Sprawdza logowanie (test połączenia).
     *
     * @throws MailboxException
     */
    public function ping(): void;
}
