<?php

namespace App\Models\Concerns;

use App\Models\ActivityLog;
use Illuminate\Support\Arr;

/**
 * Zapisuje utworzenie, zmianę i usunięcie modelu w dzienniku zmian.
 * Wartości są zapisywane po rzutowaniu modelu (np. kwota "38.00", enum jako wartość),
 * więc dziennik wygląda tak samo niezależnie od bazy. Pola ukryte ($hidden) są pomijane.
 */
trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        static::created(function (self $model) {
            ActivityLog::record($model, 'created', ['attributes' => $model->loggableAttributes()]);
        });

        static::updated(function (self $model) {
            $keys = array_keys(Arr::except($model->getChanges(), [...$model->getHidden(), ...self::IGNORED_FOR_ACTIVITY]));

            if ($keys === []) {
                return;
            }

            $old = [];
            $new = [];

            foreach ($keys as $key) {
                $old[$key] = $model->getOriginal($key);
                $new[$key] = $model->getAttribute($key);
            }

            ActivityLog::record($model, 'updated', ['old' => $old, 'attributes' => $new]);
        });

        static::deleted(function (self $model) {
            ActivityLog::record($model, 'deleted', ['attributes' => $model->loggableAttributes()]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function loggableAttributes(): array
    {
        return Arr::except($this->attributesToArray(), self::IGNORED_FOR_ACTIVITY);
    }

    private const IGNORED_FOR_ACTIVITY = ['created_at', 'updated_at', 'remember_token'];
}
