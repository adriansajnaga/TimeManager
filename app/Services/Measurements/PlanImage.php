<?php

namespace App\Services\Measurements;

use App\Models\Attachment;
use App\Models\MeasurementMarker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Rzut z naniesionymi znacznikami punktów (symbol gniazda, oświetlenia, punktu w kolorze rodzaju i numer) — obraz do raportu PDF.
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
            $kind = $marker->kind();
            $color = $this->color($image, MeasurementMarker::COLORS[$kind]);
            $this->symbol($image, $kind, $x, $y, $symbol, $color, $white, $font);
            $this->number($image, (string) $marker->number, $x + (int) round($symbol * 0.75), $y - (int) round($symbol * 0.45), $radius, $font, $color, $white);
        }

        $directory = storage_path('app/mpdf');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/plan-'.$plan->id.'-'.bin2hex(random_bytes(4)).'.png';
        imagepng($image, $path);

        return $path;
    }

    /**
     * Symbol jak w przeglądarce (components/plan-symbol): siatka 24×24 wpisana w kwadrat 2r wokół punktu,
     * najpierw biała obwódka, potem kreski w kolorze rodzaju.
     *
     * @param  'socket'|'socket3'|'light'|'point'  $kind
     */
    private function symbol(\GdImage $image, string $kind, int $x, int $y, int $radius, int $color, int $white, string $font): void
    {
        $unit = 2 * $radius / 24;
        $at = fn (float $gx, float $gy): array => [$x - $radius + $gx * $unit, $y - $radius + $gy * $unit];

        // Linia łamana grubości $width: odcinki + kółka w węzłach (bez szczerb na łukach).
        $stroke = function (array $points, int $paint, float $width) use ($image, $at): void {
            $pixels = array_map(fn (array $point) => $at(...$point), $points);
            imagesetthickness($image, max(1, (int) round($width)));

            for ($i = 1; $i < count($pixels); $i++) {
                imageline($image, (int) round($pixels[$i - 1][0]), (int) round($pixels[$i - 1][1]), (int) round($pixels[$i][0]), (int) round($pixels[$i][1]), $paint);
            }

            if (count($pixels) > 2) {
                foreach ($pixels as [$px, $py]) {
                    imagefilledellipse($image, (int) round($px), (int) round($py), (int) round($width), (int) round($width), $paint);
                }
            }

            imagesetthickness($image, 1);
        };
        $arc = function (float $cx, float $cy, float $r, int $from, int $to): array {
            $points = [];

            for ($angle = $from; $angle <= $to; $angle += 6) {
                $points[] = [$cx + $r * cos(deg2rad($angle)), $cy + $r * sin(deg2rad($angle))];
            }

            return $points;
        };

        $shapes = match ($kind) {
            // Łuk otwarty u dołu, pozioma kreska (styk ochronny) i przewód w górę.
            'socket', 'socket3' => array_filter([
                $arc(12, 23, 8.6, 180, 360),
                [[3, 14], [21, 14]],
                [[12, 1], [12, 14]],
                $kind === 'socket3' ? [[17.7, 21.4], [25.4, 13.5]] : null,
            ]),
            'light' => [$arc(12, 12, 9, 0, 360), [[5.6, 5.6], [18.4, 18.4]], [[18.4, 5.6], [5.6, 18.4]]],
            default => [[[5, 5], [19, 5], [19, 19], [5, 19], [5, 5]]],
        };

        foreach ([[$white, 3.6], [$color, 1.8]] as [$paint, $width]) {
            foreach ($shapes as $points) {
                $stroke($points, $paint, max(2, $width * $unit));
            }
        }

        if ($kind === 'socket3') {
            [$tx, $ty] = $at(23, 25.5);
            imagettftext($image, 8 * $unit * 0.75, 0, (int) round($tx), (int) round($ty), $color, $font, '3');
        }
    }

    /**
     * Kolor GD z zapisu „#rrggbb”.
     */
    private function color(\GdImage $image, string $hex): int
    {
        $channel = fn (int $offset): int => max(0, min(255, (int) hexdec(substr($hex, $offset, 2))));

        return (int) imagecolorallocate($image, $channel(1), $channel(3), $channel(5));
    }

    /**
     * Numer znacznika w czerwonej plakietce przy symbolu (lewy dolny róg plakietki w punkcie $left, $bottom).
     */
    private function number(\GdImage $image, string $label, int $left, int $bottom, int $radius, string $font, int $color, int $white): void
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

        imagefilledrectangle($image, $left, $top, $left + $textWidth + 2 * $pad, $bottom, $color);
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
