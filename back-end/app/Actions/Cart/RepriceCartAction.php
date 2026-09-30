<?php

namespace App\Actions\Cart;

use App\Enums\CartIssueCode;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartItemOption;
use App\Services\CartMutation;
use App\Services\CartStateService;
use App\Services\PricingService;
use Illuminate\Validation\ValidationException;

class RepriceCartAction
{
    public function __construct(private CartMutation $mutation, private CartStateService $state, private PricingService $pricing) {}

    public function handle(Cart $cart): Cart
    {
        $priceIssues = [];
        $result = $this->mutation->run($cart, function (Cart $cart) use (&$priceIssues): void {
            $this->state->load($cart);
            if (in_array(CartIssueCode::PriceLimitExceeded->value, array_column($cart->issues, 'code'), true)) {
                return;
            }
            $itemRows = [];
            $optionRows = [];
            foreach ($cart->items as $item) {
                if ($item->issues !== []) {
                    continue;
                }
                try {
                    $price = $this->pricing->calculate($item->product, $cart->branch, $item->assignment, $item->trustedOptions, $item->quantity);
                } catch (ValidationException) {
                    $priceIssues[$item->uuid] = CartIssueCode::PriceLimitExceeded->issue();

                    continue;
                }
                $item->fill($price->attributes());
                if ($item->isDirty()) {
                    $itemRows[] = $item->getAttributes();
                }
                $values = $item->trustedOptions->keyBy('id');
                foreach ($item->options as $option) {
                    $option->price_modifier = $values->get($option->option_value_id)->price_modifier;
                    if ($option->isDirty()) {
                        $optionRows[] = $option->getAttributes();
                    }
                }
            }
            if ($itemRows !== []) {
                CartItem::upsert($itemRows, ['id'], ['base_price', 'options_total', 'unit_price', 'line_total', 'updated_at']);
            }
            if ($optionRows !== []) {
                CartItemOption::upsert($optionRows, ['id'], ['price_modifier', 'updated_at']);
            }
        }, requiresOrders: false);
        foreach ($result->items as $item) {
            if (isset($priceIssues[$item->uuid])) {
                $item->issues[] = $priceIssues[$item->uuid];
            }
        }

        return $result;
    }
}
