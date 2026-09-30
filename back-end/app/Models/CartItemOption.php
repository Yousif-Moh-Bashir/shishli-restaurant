<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItemOption extends Model
{
    protected $fillable = ['cart_item_id', 'option_group_id', 'option_value_id', 'option_group_name', 'option_value_name', 'price_modifier'];

    protected $hidden = ['id', 'cart_item_id', 'option_group_id', 'option_value_id'];

    protected function casts(): array
    {
        return ['price_modifier' => 'decimal:2'];
    }

    public function cartItem(): BelongsTo
    {
        return $this->belongsTo(CartItem::class);
    }

    public function optionGroup(): BelongsTo
    {
        return $this->belongsTo(OptionGroup::class)->withTrashed();
    }

    public function optionValue(): BelongsTo
    {
        return $this->belongsTo(OptionValue::class)->withTrashed();
    }
}
