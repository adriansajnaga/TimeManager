<?php

namespace App\Services\Mileage;

use App\Models\Contractor;
use App\Models\Project;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * Jeden przejazd dziennie na osobę u klienta: baza → miejsca projektów → baza (decyzja 1).
 */
final class MileageTrip
{
    /**
     * @param  list<Project>  $projects
     */
    public function __construct(
        public readonly User $user,
        public readonly Contractor $client,
        public readonly CarbonImmutable $date,
        public readonly array $projects,
        public readonly string $route,
        public readonly ?BigDecimal $km,
        public readonly bool $manualKm,
        public readonly bool $manualRoute,
    ) {}

    /**
     * Kilka projektów albo projekt bez odległości — km trzeba wpisać ręcznie.
     */
    public function needsKm(): bool
    {
        return $this->km === null;
    }

    /**
     * Kilometry bez zbędnych zer: „181”, „90.5”; pusty tekst, gdy brak.
     */
    public function kmLabel(): string
    {
        return $this->km === null ? '' : self::number($this->km);
    }

    public static function number(BigDecimal $value): string
    {
        $text = (string) $value;

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    public function key(): string
    {
        return $this->user->id.'-'.$this->client->id.'-'.$this->date->toDateString();
    }
}
