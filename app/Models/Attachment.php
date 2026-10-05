<?php

namespace App\Models;

use App\Support\DescribesFile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Plik dołączony do notatki albo kartoteki kontrahenta (dysk local, poza katalogiem publicznym).
 *
 * @property int $id
 * @property string $attachable_type
 * @property int $attachable_id
 * @property string $name
 * @property string $path
 * @property string $mime
 * @property int $size
 * @property-read Model|null $attachable
 */
#[Fillable(['name', 'path', 'mime', 'size'])]
class Attachment extends Model
{
    use DescribesFile;

    /** Największy plik w kB (musi się zmieścić w limicie PHP upload_max_filesize na serwerze). */
    public const MAX_KB = 20480;

    /** Kto może otworzyć pliki danego właściciela (Gate). */
    public const PERMISSIONS = [
        Note::class => 'manage-notes',
        Contractor::class => 'manage-contractors',
    ];

    protected static function booted(): void
    {
        static::deleting(fn (Attachment $attachment) => Storage::disk('local')->delete($attachment->path));
    }

    /**
     * Zapisuje wgrany plik w katalogu właściciela pod losową nazwą (oryginalna zostaje w bazie).
     */
    public static function store(Note|Contractor $owner, UploadedFile $file): self
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
        return ['size' => 'integer', 'mime' => 'string'];
    }
}
