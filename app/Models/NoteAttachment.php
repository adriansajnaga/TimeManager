<?php

namespace App\Models;

use App\Support\DescribesFile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Plik dołączony do notatki (dysk local, poza katalogiem publicznym).
 *
 * @property int $id
 * @property int $note_id
 * @property string $name
 * @property string $path
 * @property string $mime
 * @property int $size
 */
#[Fillable(['note_id', 'name', 'path', 'mime', 'size'])]
class NoteAttachment extends Model
{
    use DescribesFile;

    public const DIRECTORY = 'notes';

    /** Największy plik w kB (musi się zmieścić w limicie PHP upload_max_filesize na serwerze). */
    public const MAX_KB = 20480;

    protected static function booted(): void
    {
        static::deleting(fn (NoteAttachment $attachment) => Storage::disk('local')->delete($attachment->path));
    }

    /**
     * @return BelongsTo<Note, $this>
     */
    public function note(): BelongsTo
    {
        return $this->belongsTo(Note::class);
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
