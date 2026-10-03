<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;

/**
 * Wpis dziennika zmian: kto, kiedy i co zmienił w obiekcie.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string $event
 * @property array<string, mixed>|null $properties
 */
#[Fillable(['user_id', 'subject_type', 'subject_id', 'event', 'properties'])]
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'activity_log';

    private static bool $paused = false;

    /**
     * Wykonuje operację bez zapisu w dzienniku (np. import tysięcy rekordów, opisany jednym wpisem).
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public static function withoutLogging(callable $callback): mixed
    {
        $previous = self::$paused;
        self::$paused = true;

        try {
            return $callback();
        } finally {
            self::$paused = $previous;
        }
    }

    public static function isPaused(): bool
    {
        return self::$paused;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public static function record(Model $subject, string $event, array $properties = []): ?self
    {
        if (self::$paused) {
            return null;
        }

        return static::create([
            'user_id' => Auth::id(),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'event' => $event,
            'properties' => $properties ?: null,
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
