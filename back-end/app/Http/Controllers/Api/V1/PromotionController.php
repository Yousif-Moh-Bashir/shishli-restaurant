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
        $data = $request->validate(['branch_uuid' => ['sometimes', 'uuid', 'exists:branches,uuid']]);
        $query = Promotion::publicOffers();
        if (isset($data['branch_uuid'])) {
            $query->forBranch(Branch::where('uuid', $data['branch_uuid'])->firstOrFail()->id);
        }

        return ApiResponse::paginated(PublicPromotionResource::collection($query->orderByDesc('priority')->orderBy('uuid')->paginate(20)));
    }
}
