<?php

namespace App\Models\Concerns;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Storage;

/**
 * Pliki dołączone do rekordu; usuwane z dysku razem z nim.
 */
trait HasAttachments
{
    /** Katalog plików rekordu na dysku local. */
    abstract public function attachmentDirectory(): string;

    public static function bootHasAttachments(): void
    {
        static::deleting(function (self $model) {
            $model->attachments()->get()->each->delete();
            Storage::disk('local')->deleteDirectory($model->attachmentDirectory());
        });
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->orderBy('name');
    }
}
