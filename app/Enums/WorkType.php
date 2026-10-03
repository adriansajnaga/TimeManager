<?php

namespace App\Enums;

/**
 * Rodzaj pracy (kolumna „Art der Arbeiten” w Stundennachweis).
 */
enum WorkType: string
{
    case Montage = 'montage';
    case Demontage = 'demontage';

    public function label(): string
    {
        return match ($this) {
            self::Montage => __('Installation'),
            self::Demontage => __('Dismantling'),
        };
    }

    /**
     * Oznaczenie na dokumentach dla klienta (jak w starej aplikacji).
     */
    public function documentLabel(string $locale): string
    {
        return trans('workdocs.work_type.'.$this->value, [], $locale);
    }
}
