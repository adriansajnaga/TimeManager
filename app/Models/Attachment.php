<?php

namespace App\Models;

use App\Models\Contracts\Attachable;
use App\Support\DescribesFile;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Plik dołączony do notatki albo kartoteki kontrahenta (dysk local, poza katalogiem publicznym).
 *
 * @property int $id
 * @property string $attachable_type
 * @property int $attachable_id
 * @property string $name
 * @property string|null $description
 * @property string $path
 * @property string $mime
 * @property int $size
 * @property CarbonImmutable|null $expires_at
 * @property Carbon|null $created_at
 * @property-read Model|null $attachable
 */
#[Fillable(['name', 'description', 'path', 'mime', 'size', 'expires_at'])]
class Attachment extends Model
{
    use DescribesFile;

    /** Największy plik w kB (musi się zmieścić w limicie PHP upload_max_filesize na serwerze). */
    public const MAX_KB = 20480;

    /** Ile dni przed upływem ważności pokazujemy alert. */
    public const WARN_DAYS = 30;

    /** Kto może otworzyć pliki danego właściciela (Gate). */
    public const PERMISSIONS = [
        Note::class => 'manage-notes',
        Contractor::class => 'manage-contractors',
        MeasurementProtocol::class => 'manage-measurements',
        MeasurementInstrument::class => 'manage-measurements',
        MeasurementPerformer::class => 'manage-measurements',
    ];

    protected static function booted(): void
    {
        static::deleting(fn (Attachment $attachment) => Storage::disk('local')->delete($attachment->path));
    }

    /**
     * Zapisuje wgrany plik w katalogu właściciela pod losową nazwą (oryginalna zostaje w bazie).
     */
    public static function store(Model&Attachable $owner, UploadedFile $file): self
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $stored = $file->storeAs(
            $owner->attachmentDirectory(),
            Str::uuid()->toString().($extension !== '' ? '.'.$extension : ''),
            'local',
        );

        return $owner->attachments()->create([
            'name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'path' => (string) $stored,
            'mime' => (string) ($file->getMimeType() ?? 'application/octet-stream'),
            'size' => (int) $file->getSize(),
        ]);
    }

    /**
     * Pliki z datą ważności, która minęła albo mija w ciągu WARN_DAYS dni.
     *
     * @param  Builder<self>  $query
     */
    public function scopeExpiringSoon(Builder $query): void
    {
        $query->whereNotNull('expires_at')->whereDate('expires_at', '<=', CarbonImmutable::today()->addDays(self::WARN_DAYS));
    }

    /**
     * Dni do końca ważności (ujemne = po terminie), null = bez daty.
     */
    public function daysToExpiry(): ?int
    {
        return $this->expires_at !== null ? (int) CarbonImmutable::today()->diffInDays($this->expires_at, false) : null;
    }

    /**
     * Stan ważności do odznaki: expired, soon, valid albo null (bez daty).
     */
    public function expiryState(): ?string
    {
        $days = $this->daysToExpiry();

        return match (true) {
            $days === null => null,
            $days < 0 => 'expired',
            $days <= self::WARN_DAYS => 'soon',
            default => 'valid',
        };
    }

    /** Nazwa do wyświetlenia: podpis, a bez niego nazwa pliku. */
    public function label(): string
    {
        return filled($this->description) ? (string) $this->description : $this->name;
    }

    public function permission(): ?string
    {
        return self::PERMISSIONS[$this->attachable_type] ?? null;
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['size' => 'integer', 'mime' => 'string', 'expires_at' => 'immutable_date'];
    }
}
