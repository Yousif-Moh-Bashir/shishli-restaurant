<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RevenueLine extends Model
{
    public $timestamps = false;

    protected $table = 'finance_revenue_lines';

    protected $fillable = ['source_id', 'amount'];

    protected $hidden = ['id', 'revenue_day_id', 'source_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(RevenueSource::class, 'source_id');
    }
}
