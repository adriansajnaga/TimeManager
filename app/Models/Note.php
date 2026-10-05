<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Notatka w kartotekach z dowolnymi plikami.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $title
 * @property string|null $body
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['user_id', 'title', 'body'])]
class Note extends Model
{
    protected static function booted(): void
    {
        // Pliki znikają razem z notatką (wiersze usuwa kaskada w bazie).
        static::deleting(fn (Note $note) => Storage::disk('local')->deleteDirectory(NoteAttachment::DIRECTORY.'/'.$note->id));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<NoteAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(NoteAttachment::class)->orderBy('name');
    }
}
