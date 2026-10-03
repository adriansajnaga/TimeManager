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
 * @property CarbonImmutable|null $verified_at
 */
#[Fillable(['host', 'port', 'encryption', 'username', 'password', 'from_address', 'from_name', 'bcc', 'verified_at'])]
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
            'password' => 'encrypted',
            'verified_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        return static::query()->first() ?? new self(['port' => 465, 'encryption' => 'ssl']);
    }

    public function isConfigured(): bool
    {
        return filled($this->host) && filled($this->from_address);
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
