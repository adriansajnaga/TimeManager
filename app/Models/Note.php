<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use App\Models\Contracts\Attachable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
class Note extends Model implements Attachable
{
    use HasAttachments;

    public function attachmentDirectory(): string
    {
        return 'notes/'.$this->id;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
