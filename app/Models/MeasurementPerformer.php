<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use App\Models\Contracts\Attachable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Osoba wykonująca pomiary: świadectwa kwalifikacyjne (po jednym w wierszu), skany jako załączniki.
 *
 * @property int $id
 * @property string $name
 * @property string|null $certificates
 * @property bool $is_active
 */
#[Fillable(['name', 'certificates', 'is_active'])]
class MeasurementPerformer extends Model implements Attachable
{
    use HasAttachments;

    public function attachmentDirectory(): string
    {
        return 'measurement-performers/'.$this->id;
    }

    /**
     * @return list<string>
     */
    public function certificateLines(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $this->certificates) ?: [])));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
