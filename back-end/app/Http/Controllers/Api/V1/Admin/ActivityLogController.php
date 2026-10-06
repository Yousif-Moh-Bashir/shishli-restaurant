<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AdminListRequest;
use App\Http\Resources\Api\V1\AdminActivityLogResource;
use App\Http\Responses\ApiResponse;
use App\Models\AdminActivityLog;
use App\Services\Admin\AdminListQuery;
use App\Services\RestaurantSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

class ActivityLogController extends Controller
{
    public function index(AdminListRequest $request, AdminListQuery $lists): JsonResponse
    {
        $data = $request->validated();
        $query = AdminActivityLog::query()->with('actor');
        $timezone = app(RestaurantSettingsService::class)->getString('restaurant.timezone');
        foreach (['action', 'subject_type'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (isset($data['user_uuid'])) {
            $query->whereHas('actor', fn (Builder $q) => $q->where('uuid', $data['user_uuid']));
        }
        if (isset($data['date_from'])) {
            $query->where('created_at', '>=', CarbonImmutable::parse($data['date_from'], $timezone)->startOfDay()->utc());
        }
        if (isset($data['date_to'])) {
            $query->where('created_at', '<', CarbonImmutable::parse($data['date_to'], $timezone)->addDay()->startOfDay()->utc());
        }

        return ApiResponse::paginated(AdminActivityLogResource::collection($lists->apply($query, Arr::only($data, ['search', 'sort', 'direction']), ['action', 'description', 'subject_uuid'], ['created_at'], 'created_at', 'desc')->paginate($request->integer('per_page', 20))->withQueryString()));
    }
}
