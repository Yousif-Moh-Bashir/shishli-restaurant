<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PublicPromotionResource;
use App\Http\Responses\ApiResponse;
use App\Models\Branch;
use App\Models\Promotion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromotionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['branch_uuid' => ['sometimes', 'uuid', 'exists:branches,uuid'], 'registration_offer' => ['sometimes', 'boolean']]);
        $query = Promotion::publicOffers();
        if (isset($data['branch_uuid'])) {
            $query->forBranch(Branch::where('uuid', $data['branch_uuid'])->firstOrFail()->id);
        }
        if ($request->boolean('registration_offer')) {
            $query->where('is_registration_offer', true)->where('first_order_only', true)
                ->where('type', 'automatic')->where('application_type', 'cart')->where('discount_type', 'percentage');
            if (! isset($data['branch_uuid'])) {
                $query->whereDoesntHave('branches');
            }
        }

        return ApiResponse::paginated(PublicPromotionResource::collection($query->orderByDesc('priority')->orderBy('uuid')->paginate(20)));
    }
}
