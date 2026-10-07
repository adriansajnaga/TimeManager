<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Serwer SMTP do wysyłki faktur (jeden wiersz). Hasło szyfrowane kluczem APP_KEY.
 *
 * @property int $id
 * @property string|null $host
 * @property int $port
 * @property string $encryption
 * @property string|null $username
 * @property string|null $password
 * @property string|null $from_address
 * @property string|null $from_name
 * @property string|null $bcc
 * @property string|null $imap_host
 * @property int $imap_port
 * @property string $imap_encryption
 * @property string|null $sent_folder
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $login_failed_at
 */
#[Fillable(['host', 'port', 'encryption', 'username', 'password', 'from_address', 'from_name', 'bcc', 'imap_host', 'imap_port', 'imap_encryption', 'sent_folder', 'verified_at'])]
#[Hidden(['password'])]
class MailSetting extends Model
{
    use LogsActivity;

    public const ENCRYPTIONS = ['ssl', 'tls', 'none'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'imap_port' => 'integer',
            'password' => 'encrypted',
            'verified_at' => 'datetime',
            'login_failed_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        return static::query()->first() ?? new self(['port' => 465, 'encryption' => 'ssl', 'imap_port' => 993, 'imap_encryption' => 'ssl']);
    }

    /**
     * Serwer odrzucił login lub hasło — aplikacja nie loguje się sama, dopóki ktoś nie zapisze
     * nowych danych albo nie kliknie „Testuj skrzynkę” (każda próba przedłuża blokadę cPHulk).
     */
    public function loginBlocked(): bool
    {
        return $this->login_failed_at !== null;
    }

    public static function blockLogin(): void
    {
        static::query()->update(['login_failed_at' => now()]);
    }

    public static function resumeLogin(): void
    {
        static::query()->update(['login_failed_at' => null]);
    }

    public function isConfigured(): bool
    {
        return filled($this->host) && filled($this->from_address);
    }

    /**
     * Skrzynka do przeglądania (IMAP) — ten sam login i hasło co SMTP.
     */
    public function hasMailbox(): bool
    {
        return filled($this->imap_host) && filled($this->username) && filled($this->password);
    }

    /**
     * Konfiguracja mailera Laravel dla tych ustawień.
     *
     * @return array<string, mixed>
     */
    public function mailerConfig(): array
    {
        return [
            'transport' => 'smtp',
            'scheme' => $this->encryption === 'ssl' ? 'smtps' : 'smtp',
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'password' => $this->password,
            'timeout' => 30,
            'auto_tls' => $this->encryption !== 'none',
        ];
    }
}
