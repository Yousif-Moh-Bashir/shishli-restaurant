<?php

namespace App\Models;

use Database\Factories\OptionValueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['option_group_id', 'name', 'price_modifier', 'is_default', 'is_active', 'sort_order'])]
class OptionValue extends Model
{
    /** @use HasFactory<OptionValueFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return BelongsTo<OptionGroup, $this>
     */
    public function optionGroup(): BelongsTo
    {
        return $this->belongsTo(OptionGroup::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_modifier' => 'decimal:2',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
