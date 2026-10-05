<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Punkt oględzin: zgodny, niezgodny albo nie dotyczy.
 *
 * @property int $id
 * @property int $protocol_id
 * @property int $position
 * @property string $section
 * @property string $item
 * @property string|null $standard
 * @property string $result
 */
#[Fillable(['position', 'section', 'item', 'standard', 'result'])]
class MeasurementInspection extends Model
{
    public const RESULTS = ['compliant', 'non_compliant', 'not_applicable'];

    public $timestamps = false;

    public function resultLabel(): string
    {
        return match ($this->result) {
            'non_compliant' => 'niezgodny',
            'not_applicable' => 'nie dotyczy',
            default => 'zgodny',
        };
    }
}
