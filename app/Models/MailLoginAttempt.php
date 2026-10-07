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
        $livewire = $request->is('livewire*', '*/livewire*');
        // Akcje Livewire idą na /livewire/update — stronę bierzemy z nagłówka Referer, a do niej
        // wywołane metody i zmienione pola (żeby było widać, co wysłało żądanie) oraz urządzenie.
        $page = $livewire
            ? parse_url((string) $request->headers->get('referer'), PHP_URL_PATH).' · Livewire: '.self::livewireActions()
            : '/'.ltrim($request->path(), '/');
        $page .= ' · '.self::device((string) $request->userAgent());

        static::query()->create([
            'protocol' => $protocol,
            'succeeded' => $succeeded,
            'message' => $message === null ? null : Str::limit($message, 490),
            'user_id' => Auth::id(),
            'page' => Str::limit($page, 250),
        ]);

        $cutoff = static::query()->latest('id')->skip(self::KEEP)->value('id');

        if ($cutoff !== null) {
            static::query()->where('id', '<=', $cutoff)->delete();
        }
    }

    /**
     * „open(12), search=” — metody i pola z żądania Livewire.
     */
    private static function livewireActions(): string
    {
        $actions = [];

        foreach ((array) request()->input('components', []) as $component) {
            foreach ((array) ($component['calls'] ?? []) as $call) {
                $actions[] = ($call['method'] ?? '?').'('.implode(', ', array_map(fn ($param) => is_scalar($param) ? (string) $param : '…', (array) ($call['params'] ?? []))).')';
            }

            foreach (array_keys((array) ($component['updates'] ?? [])) as $field) {
                $actions[] = $field.'=';
            }
        }

        return $actions === [] ? 'odświeżenie' : implode(', ', $actions);
    }

    private static function device(string $agent): string
    {
        $system = match (true) {
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS') => 'macOS',
            default => 'inne',
        };
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Safari/') => 'Safari',
            default => '?',
        };

        return $system.' / '.$browser;
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
