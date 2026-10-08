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
    public function annotate(Attachment $plan, Collection $markers, float $markerSize = 2.5): ?string
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
        // Rozmiary jak na ekranie (strona rozdzielnicy): bok symbolu = $markerSize % szerokości rysunku,
        // napisy 0,42 (punkt) i 0,45 (rozdzielnica) boku — wydruk wygląda tak samo jak podgląd.
        $size = $width * $markerSize / 100;
        $red = (int) imagecolorallocate($image, 220, 38, 38);
        $white = (int) imagecolorallocate($image, 255, 255, 255);
        $black = (int) imagecolorallocate($image, 24, 24, 27);
        $font = base_path(self::FONT);

        foreach ($markers as $marker) {
            $x = (int) round((float) $marker->x / 100 * $width);
            $y = (int) round((float) $marker->y / 100 * $height);

            // Rozdzielnica: czerwony prostokąt z nazwą (R1, UV1…).
            if ($marker->isBoard()) {
                $this->board($image, $x, $y, (string) $marker->board?->name, $size * 0.45, $font, $red, $white, $black);

                continue;
            }

            // Główna szyna wyrównawcza: zielony prostokąt „GSW”.
            if ($marker->isBonding()) {
                $this->board($image, $x, $y, 'GSW', $size * 0.45, $font, $this->color($image, MeasurementMarker::BONDING_COLOR), $white, $black);

                continue;
            }

            $kind = $marker->kind();
            $color = $this->color($image, MeasurementMarker::COLORS[$kind]);
            $count = $marker->points->count();
            $this->symbol($image, $kind, $x, $y, (int) round($size / 2), $color, $white, $font, $marker->rotation);
            $this->number($image, $marker->number.($count > 1 ? ' ×'.$count : ''), $x, (int) round($y + $size / 2 + $size * 0.042), $size * 0.42, $font, $color, $white);
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
    private function symbol(\GdImage $image, string $kind, int $x, int $y, int $radius, int $color, int $white, string $font, int $rotation = 0): void
    {
        $unit = 2 * $radius / 24;
        // Obrót wokół środka siatki (12, 12) zgodnie z ruchem wskazówek, jak transform: rotate() w przeglądarce.
        $cos = cos(deg2rad($rotation));
        $sin = sin(deg2rad($rotation));
        $at = fn (float $gx, float $gy): array => [
            $x + (($gx - 12) * $cos - ($gy - 12) * $sin) * $unit,
            $y + (($gx - 12) * $sin + ($gy - 12) * $cos) * $unit,
        ];

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
            // „3” w obróconym miejscu, zawsze pionowo — wyśrodkowana jak text-anchor="middle".
            [$tx, $ty] = $at(25, 25.5);
            $box = imagettfbbox(8 * $unit * 0.75, 0, $font, '3');
            $half = $box !== false ? ($box[2] - $box[0]) / 2 : 0;
            imagettftext($image, 8 * $unit * 0.75, 0, (int) round($tx - $half), (int) round($ty), $color, $font, '3');
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
     * Numer znacznika w plakietce koloru symbolu, wyśrodkowany pod nim (górna krawędź w $top).
     */
    private function number(\GdImage $image, string $label, int $x, int $top, float $fontPx, string $font, int $color, int $white): void
    {
        $points = $fontPx * 0.75; // GD liczy punkty przy 96 dpi
        $box = imagettfbbox($points, 0, $font, $label);

        if ($box === false) {
            return;
        }

        $textWidth = $box[2] - $box[0];
        $height = (int) round($fontPx * 1.25);
        $padX = (int) round($fontPx * 0.35);
        $left = (int) round($x - $textWidth / 2 - $padX);
        $right = (int) round($x + $textWidth / 2 + $padX);
        $radius = intdiv($height, 2);

        // Zaokrąglone końce jak „rounded-full” w przeglądarce.
        imagefilledrectangle($image, $left + $radius, $top, $right - $radius, $top + $height, $color);
        imagefilledellipse($image, $left + $radius, $top + $radius, $height, $height, $color);
        imagefilledellipse($image, $right - $radius, $top + $radius, $height, $height, $color);
        imagettftext($image, $points, 0, (int) round($x - $textWidth / 2 - $box[0]), (int) round($top + $height / 2 + ($box[1] - $box[7]) / 2), $white, $font, $label);
    }

    /**
     * Rozdzielnica: czerwony prostokąt z czarną ramką i nazwą (jak na ekranie: odstęp 0,15/0,5 em, ramka 0,12 em).
     */
    private function board(\GdImage $image, int $x, int $y, string $name, float $fontPx, string $font, int $red, int $white, int $black): void
    {
        $points = $fontPx * 0.75;
        $box = imagettfbbox($points, 0, $font, $name);
        $textWidth = $box !== false ? $box[2] - $box[0] : (int) ($fontPx * 0.6 * mb_strlen($name));
        $textHeight = $box !== false ? $box[1] - $box[7] : (int) $fontPx;
        $halfWidth = (int) round($textWidth / 2 + $fontPx * 0.5);
        $halfHeight = (int) round($fontPx * 1.25 / 2 + $fontPx * 0.15);
        $border = (int) max(1, round($fontPx * 0.12));

        imagefilledrectangle($image, $x - $halfWidth - $border, $y - $halfHeight - $border, $x + $halfWidth + $border, $y + $halfHeight + $border, $black);
        imagefilledrectangle($image, $x - $halfWidth, $y - $halfHeight, $x + $halfWidth, $y + $halfHeight, $red);

        if ($box !== false) {
            imagettftext($image, $points, 0, (int) round($x - $textWidth / 2 - $box[0]), (int) round($y + $textHeight / 2), $white, $font, $name);
        }
    }
}
