<?php

namespace App\Models\Concerns;

/**
 * Tylko jeden rekord w tabeli może mieć is_default = true.
 */
trait HasSingleDefault
{
    public static function bootHasSingleDefault(): void
    {
        static::saved(function (self $model) {
            if ($model->getAttribute('is_default')) {
                static::query()->whereKeyNot($model->getKey())->where('is_default', true)->update(['is_default' => false]);
            }
        });
    }

    public static function default(): ?static
    {
        return static::query()->where('is_default', true)->first();
    }
}
