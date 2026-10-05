<?php

namespace App\Models\Contracts;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Rekord z plikami (trait HasAttachments): notatka, kontrahent, protokół pomiarów, przyrząd, osoba.
 */
interface Attachable
{
    /** Katalog plików rekordu na dysku local. */
    public function attachmentDirectory(): string;

    /**
     * @return MorphMany<Attachment, covariant \Illuminate\Database\Eloquent\Model>
     */
    public function attachments(): MorphMany;
}
