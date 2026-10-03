<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $invoice_id
 * @property list<string> $to
 * @property list<string>|null $cc
 * @property string $subject
 * @property string $body
 * @property string|null $attachment
 * @property int|null $sent_by
 * @property CarbonImmutable|null $sent_at
 * @property string|null $error
 */
#[Fillable(['invoice_id', 'to', 'cc', 'subject', 'body', 'attachment', 'sent_by', 'sent_at', 'error'])]
class EmailLog extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
