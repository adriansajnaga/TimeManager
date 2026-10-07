<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Próba logowania aplikacji do serwera poczty (Administracja → E-mail).
 *
 * @property int $id
 * @property string $protocol
 * @property bool $succeeded
 * @property string|null $message
 * @property int|null $user_id
 * @property string|null $page
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['protocol', 'succeeded', 'message', 'user_id', 'page'])]
class MailLoginAttempt extends Model
{
    public const UPDATED_AT = null;

    /** Tyle ostatnich prób trzymamy. */
    private const KEEP = 300;

    public static function record(string $protocol, bool $succeeded, ?string $message = null): void
    {
        $request = request();
        // Akcje Livewire idą na /livewire/update — stronę bierzemy z nagłówka Referer.
        $page = $request->is('livewire*', '*/livewire*')
            ? parse_url((string) $request->headers->get('referer'), PHP_URL_PATH)
            : '/'.ltrim($request->path(), '/');

        static::query()->create([
            'protocol' => $protocol,
            'succeeded' => $succeeded,
            'message' => $message === null ? null : Str::limit($message, 490),
            'user_id' => Auth::id(),
            'page' => is_string($page) ? Str::limit($page, 250) : null,
        ]);

        $cutoff = static::query()->latest('id')->skip(self::KEEP)->value('id');

        if ($cutoff !== null) {
            static::query()->where('id', '<=', $cutoff)->delete();
        }
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['succeeded' => 'boolean'];
    }
}
