<?php

namespace App\Models;

use App\Enums\SettingType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantSetting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'group', 'is_public', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'json', 'type' => SettingType::class, 'is_public' => 'boolean'];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
