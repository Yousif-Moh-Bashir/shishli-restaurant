<?php

namespace App\Models;

use Database\Factories\BranchProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class BranchProduct extends Pivot
{
    /** @use HasFactory<BranchProductFactory> */
    use HasFactory;

    protected $table = 'branch_products';

    public $incrementing = true;

    protected $fillable = ['branch_id', 'product_id', 'price_override', 'is_available'];

    protected $hidden = ['id', 'branch_id', 'product_id'];

    protected function casts(): array
    {
        return ['price_override' => 'decimal:2', 'is_available' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
