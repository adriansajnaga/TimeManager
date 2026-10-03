<?php

namespace App\Models;

use App\Models\Concerns\HasSingleDefault;
use App\Models\Concerns\LogsActivity;
use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Pojazd do kilometrówki.
 *
 * @property int $id
 * @property string $name
 * @property string|null $registration_no
 * @property bool $is_default
 */
#[Fillable(['name', 'registration_no', 'is_default'])]
class Vehicle extends Model
{
    /** @use HasFactory<VehicleFactory> */
    use HasFactory, HasSingleDefault, LogsActivity;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function displayName(): string
    {
        return trim($this->name.' '.$this->registration_no);
    }
}
