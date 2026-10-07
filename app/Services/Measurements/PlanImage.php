<?php

namespace App\Services\Measurements;

use App\Models\Attachment;
use App\Models\MeasurementMarker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Rzut z naniesionymi znacznikami punktów (symbol gniazda, oświetlenia, punktu i numer) — obraz do raportu PDF.
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

        $black = (int) imagecolorallocate($image, 20, 20, 20);

        foreach ($markers as $marker) {
            $x = (int) round((float) $marker->x / 100 * $width);
            $y = (int) round((float) $marker->y / 100 * $height);

            // Rozdzielnica: czerwony prostokąt z nazwą (R1, UV1…).
            if ($marker->isBoard()) {
                $this->board($image, $x, $y, (string) $marker->board?->name, $radius, $font, $red, $white, $black);

                continue;
            }

            // Symbol większy od dawnego kółka — kreski gniazda trójfazowego muszą być czytelne.
            $symbol = (int) round($radius * 1.6);
            $this->symbol($image, $marker->kind(), $x, $y, $symbol, $red, $white);
            $this->number($image, (string) $marker->number, $x + (int) round($symbol * 0.75), $y - (int) round($symbol * 0.45), $radius, $font, $red, $white);
        }

        $directory = storage_path('app/mpdf');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/plan-'.$plan->id.'-'.bin2hex(random_bytes(4)).'.png';
        imagepng($image, $path);

        return $path;
    }

    /**
     * Symbol jak w przeglądarce (components/plan-symbol): siatka 24×24 wpisana w kwadrat 2r wokół punktu.
     *
     * @param  'socket'|'socket3'|'light'|'point'  $kind
     */
    private function symbol(\GdImage $image, string $kind, int $x, int $y, int $radius, int $red, int $white): void
    {
        $unit = 2 * $radius / 24;
        $thickness = max(2, (int) round(2.2 * $unit));
        $at = fn (float $gx, float $gy): array => [(int) round($x - $radius + $gx * $unit), (int) round($y - $radius + $gy * $unit)];
        $line = function (float $x1, float $y1, float $x2, float $y2, ?int $width = null) use ($image, $at, $red, $thickness): void {
            [$ax, $ay] = $at($x1, $y1);
            [$bx, $by] = $at($x2, $y2);
            imagesetthickness($image, $width ?? $thickness);
            imageline($image, $ax, $ay, $bx, $by, $red);
        };
        // Pierścień / półpierścień: czerwone koło o promieniu r + t/2, w środku białe r − t/2 — gładki obrys bez szczerb.
        $ring = function (float $cx, float $cy, float $r, bool $half) use ($image, $at, $unit, $thickness, $red, $white): void {
            [$px, $py] = $at($cx, $cy);
            $outer = (int) round(2 * $r * $unit + $thickness);
            $inner = (int) round(2 * $r * $unit - $thickness);
            imagesetthickness($image, 1);

            if ($half) {
                imagefilledarc($image, $px, $py, $outer, $outer, 180, 360, $red, IMG_ARC_PIE);
                imagefilledarc($image, $px, $py, $inner, $inner, 180, 360, $white, IMG_ARC_PIE);
            } else {
                imagefilledellipse($image, $px, $py, $outer, $outer, $red);
                imagefilledellipse($image, $px, $py, $inner, $inner, $white);
            }
        };

        switch ($kind) {
            case 'socket':
            case 'socket3':
                // Półkole (kopuła) zamknięte średnicą, bolec ochronny nad nim, przewód w dół.
                $ring(12, 13, 8, true);
                $line(3, 13, 21, 13);
                $line(6, 3.5, 18, 3.5);
                $line(12, 13, 12, 23);

                if ($kind === 'socket3') {
                    $slash = max(2, (int) round(1.6 * $unit));
                    $line(9.5, 16.5, 14.5, 14.5, $slash);
                    $line(9.5, 19.5, 14.5, 17.5, $slash);
                    $line(9.5, 22.5, 14.5, 20.5, $slash);
                }
                break;

            case 'light':
                $ring(12, 12, 9, false);
                $line(5.6, 5.6, 18.4, 18.4);
                $line(18.4, 5.6, 5.6, 18.4);
                break;

            default:
                [$ax, $ay] = $at(5, 5);
                [$bx, $by] = $at(19, 19);
                $half = intdiv($thickness, 2);
                imagefilledrectangle($image, $ax - $half, $ay - $half, $bx + $half, $by + $half, $red);
                imagefilledrectangle($image, $ax + $half, $ay + $half, $bx - $half, $by - $half, $white);
        }

        imagesetthickness($image, 1);
    }

    /**
     * Numer znacznika w czerwonej plakietce przy symbolu (lewy dolny róg plakietki w punkcie $left, $bottom).
     */
    private function number(\GdImage $image, string $label, int $left, int $bottom, int $radius, string $font, int $red, int $white): void
    {
        $size = $radius * 0.8;
        $box = imagettfbbox($size, 0, $font, $label);

        if ($box === false) {
            return;
        }

        $textWidth = $box[2] - $box[0];
        $textHeight = $box[1] - $box[7];
        $pad = (int) max(2, round($radius * 0.18));
        $top = $bottom - $textHeight - 2 * $pad;

        imagefilledrectangle($image, $left, $top, $left + $textWidth + 2 * $pad, $bottom, $red);
        imagettftext($image, $size, 0, $left + $pad - $box[0], $bottom - $pad, $white, $font, $label);
    }

    private function board(\GdImage $image, int $x, int $y, string $name, int $radius, string $font, int $red, int $white, int $black): void
    {
        $size = $radius * 1.0;
        $box = imagettfbbox($size, 0, $font, $name);
        $textWidth = $box !== false ? $box[2] - $box[0] : (int) ($size * strlen($name));
        $textHeight = $box !== false ? $box[1] - $box[7] : (int) $size;
        $halfWidth = (int) ($textWidth / 2 + $radius * 0.8);
        $halfHeight = (int) ($textHeight / 2 + $radius * 0.5);

        imagefilledrectangle($image, $x - $halfWidth - 3, $y - $halfHeight - 3, $x + $halfWidth + 3, $y + $halfHeight + 3, $black);
        imagefilledrectangle($image, $x - $halfWidth, $y - $halfHeight, $x + $halfWidth, $y + $halfHeight, $red);

        if ($box !== false) {
            imagettftext($image, $size, 0, (int) ($x - $textWidth / 2 - $box[0]), (int) ($y + $textHeight / 2), $white, $font, $name);
        }
    }
}
