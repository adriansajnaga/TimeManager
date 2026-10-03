<?php

namespace Database\Seeders;

use Illuminate\Support\Facades\Storage;

/**
 * Pliki danych testowych (logotypy) z database/seeders/assets.
 */
final class SeederAssets
{
    /**
     * Kopiuje plik do prywatnego storage i zwraca ścieżkę względną.
     */
    public static function store(string $asset, string $target): string
    {
        Storage::disk('local')->put($target, (string) file_get_contents(__DIR__.'/assets/'.$asset));

        return $target;
    }
}
