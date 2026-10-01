<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemOption extends Model
{
    use HasFactory;

    protected $fillable = ['order_item_id', 'option_group_uuid', 'option_group_name', 'option_value_uuid', 'option_value_name', 'price_modifier'];

    protected $hidden = ['id', 'order_item_id'];

    protected function casts(): array
    {
        return ['price_modifier' => 'decimal:2'];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
