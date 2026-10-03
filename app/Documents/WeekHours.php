<?php

namespace App\Documents;

use App\Models\TimeEntry;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/**
 * Godziny jednej osoby w tygodniu: suma na każdy dzień (pon = 0 … nd = 6) i łącznie.
 */
final class WeekHours
{
    /**
     * @param  array<int, BigDecimal>  $days
     */
    private function __construct(
        public readonly User $user,
        public readonly array $days,
        public readonly BigDecimal $total,
    ) {}

    /**
     * Wiersz na osobę, w kolejności nazwisk.
     *
     * @param  Collection<int, TimeEntry>  $entries
     * @return list<self>
     */
    public static function perUser(Collection $entries): array
    {
        return array_values($entries
            ->groupBy('user_id')
            ->map(function (Collection $userEntries) {
                /** @var Collection<int, TimeEntry> $userEntries */
                $days = [];
                $total = BigDecimal::zero();

                foreach ($userEntries as $entry) {
                    $index = $entry->work_date->dayOfWeekIso - 1;
                    $days[$index] = ($days[$index] ?? BigDecimal::zero())->plus($entry->hours);
                    $total = $total->plus($entry->hours);
                }

                return new self($userEntries->first()->user, $days, $total);
            })
            ->sortBy(fn (self $row) => $row->user->name)
            ->all());
    }

    public function day(int $index): ?BigDecimal
    {
        return $this->days[$index] ?? null;
    }
}
