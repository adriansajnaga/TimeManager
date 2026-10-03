<?php

namespace App\Models;

use App\Models\Concerns\HasSingleDefault;
use App\Models\Concerns\LogsActivity;
use Database\Factories\BankAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $label
 * @property string $iban
 * @property string|null $swift
 * @property string $currency
 * @property bool $is_default
 */
#[Fillable(['label', 'iban', 'swift', 'currency', 'is_default'])]
class BankAccount extends Model
{
    /** @use HasFactory<BankAccountFactory> */
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

    /**
     * Tekst jak na fakturze, np. „REVOLT21 - LT51 3250 …”.
     */
    public function displayName(): string
    {
        return $this->label.' - '.$this->iban;
    }
}
