<?php

use App\Services\Measurements\BoardSvg;

test('an empty rail keeps its full size with blank modules', function () {
    $svg = BoardSvg::rail([], 12, 18);

    // 12 × 18 mm szerokości; opis 1,8 × 18 + moduł 2,5 × 18 (+0,6) wysokości.
    expect($svg)->toContain('width="216mm"')
        ->toContain('height="78mm"')
        ->and(substr_count($svg, 'fill="#fafafa"'))->toBe(12);
});

test('modules show label, protection and wrapped description in the right orientation', function () {
    $svg = BoardSvg::rail([
        ['t' => 'rcd', 'label' => 'Fi1', 'sub' => 'A 30 mA', 'desc' => 'Wyłącznik różnicowoprądowy obwody 1F1–1F6', 'w' => 2],
        ['t' => 'circuit', 'label' => '1F1', 'sub' => 'B16', 'desc' => 'Gniazda kuchnia & jadalnia', 'w' => 1],
    ], 12, 18);

    expect($svg)->toContain('>Fi1</text>')->toContain('>A 30 mA</text>')->toContain('>B16</text>')
        ->toContain('Gniazda kuchnia &amp;')
        // Opis 1F1 pionowo w dwóch kolumnach, opis Fi1 (2 moduły) poziomo.
        ->and(substr_count($svg, 'rotate(-90'))->toBe(2)
        ->and(substr_count($svg, 'fill="#fafafa"'))->toBe(9);
});
