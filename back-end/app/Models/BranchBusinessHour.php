<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ISO weekday 1=Monday through 7=Sunday. One row per shift; missing days are closed. */
class BranchBusinessHour extends Model
{
    use HasFactory;

    protected $fillable = ['day_of_week', 'opens_at', 'closes_at'];

    protected function casts(): array
    {
        return ['day_of_week' => 'integer'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
