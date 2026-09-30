<?php

namespace App\Actions\Options;

use App\Models\OptionGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteOptionGroupAction
{
    public function handle(OptionGroup $group): void
    {
        DB::transaction(function () use ($group): void {
            $group = OptionGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
            if (DB::table('product_option_groups')->where('option_group_id', $group->id)->exists()) {
                throw ValidationException::withMessages(['option_group' => 'لا يمكن حذف مجموعة الخيارات لأنها مرتبطة بمنتجات.']);
            }
            $group->delete();
        }, 3);
    }
}
