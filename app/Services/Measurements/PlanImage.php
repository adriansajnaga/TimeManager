<?php

namespace App\Services\Measurements;

use App\Models\Attachment;
use App\Models\MeasurementMarker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Rzut z naniesionymi znacznikami punktów (czerwone kółka z numerem) — obraz do raportu PDF.
 */
final class PlanImage
{
    private const FONT = 'vendor/mpdf/mpdf/ttfonts/DejaVuSans-Bold.ttf';

    /**
     * Ścieżka do tymczasowego PNG z naniesionymi znacznikami albo null, gdy obrazu nie da się odczytać.
     *
     * @param  Collection<int, MeasurementMarker>  $markers
     */
    public function annotate(Attachment $plan, Collection $markers): ?string
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($plan->path) || ! function_exists('imagecreatefromstring')) {
            return null;
        }

        $image = @imagecreatefromstring((string) $disk->get($plan->path));

        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        // Duże rzuty pomniejszamy (szybszy PDF), małe zostawiamy.
        if ($width > 2400) {
            $scaled = imagescale($image, 2400);

            if ($scaled !== false) {
                $image = $scaled;
                $width = imagesx($image);
                $height = imagesy($image);
            }
        }

        imagealphablending($image, true);
        $radius = (int) max(12, round(min($width, $height) / 55));
        $red = (int) imagecolorallocate($image, 200, 30, 30);
        $white = (int) imagecolorallocate($image, 255, 255, 255);
        $font = base_path(self::FONT);

        foreach ($markers as $marker) {
            $x = (int) round((float) $marker->x / 100 * $width);
            $y = (int) round((float) $marker->y / 100 * $height);

            imagefilledellipse($image, $x, $y, 2 * $radius + 6, 2 * $radius + 6, $white);
            imagefilledellipse($image, $x, $y, 2 * $radius, 2 * $radius, $red);

            $label = (string) $marker->number;
            $size = $radius * (strlen($label) > 2 ? 0.75 : 0.95);
            $box = imagettfbbox($size, 0, $font, $label);

            if ($box !== false) {
                $textWidth = $box[2] - $box[0];
                $textHeight = $box[1] - $box[7];
                imagettftext($image, $size, 0, (int) ($x - $textWidth / 2 - $box[0]), (int) ($y + $textHeight / 2), $white, $font, $label);
            }
        }

        $directory = storage_path('app/mpdf');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/plan-'.$plan->id.'-'.bin2hex(random_bytes(4)).'.png';
        imagepng($image, $path);

        return $path;
    }
}
