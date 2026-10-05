@props(['passes' => null])

{{-- Ocena pomiaru: zielona pozytywna, czerwona negatywna, szara — brak pomiaru. --}}
@if ($passes === true)
    <flux:badge size="sm" color="green" {{ $attributes }}>{{ __('Positive') }}</flux:badge>
@elseif ($passes === false)
    <flux:badge size="sm" color="red" {{ $attributes }}>{{ __('Negative') }}</flux:badge>
@else
    <flux:badge size="sm" color="zinc" {{ $attributes }}>—</flux:badge>
@endif
