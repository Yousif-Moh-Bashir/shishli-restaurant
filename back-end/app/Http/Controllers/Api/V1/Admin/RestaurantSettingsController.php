<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Settings\UpdateRestaurantSettingsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\SettingAuditRequest;
use App\Http\Requests\Api\V1\Admin\UpdateSettingsRequest;
use App\Http\Resources\Api\V1\RestaurantSettingResource;
use App\Http\Resources\Api\V1\SettingChangeLogResource;
use App\Http\Responses\ApiResponse;
use App\Models\RestaurantSetting;
use App\Models\SettingChangeLog;
use App\Services\RestaurantSettingDefinition;
use App\Services\RestaurantSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class RestaurantSettingsController extends Controller
{
    public function groups(RestaurantSettingsService $settings, RestaurantSettingDefinition $definitions): JsonResponse
    {
        $response = $this->index($settings, $definitions)->getData(true);

        return ApiResponse::success(data: collect($response['data'])->groupBy('group')->all());
    }

    public function index(RestaurantSettingsService $settings, RestaurantSettingDefinition $definitions): JsonResponse
    {
        $values = $settings->all();
        $rows = RestaurantSetting::query()->pluck('updated_at', 'key');
        $data = [];
        foreach ($definitions->all() as $key => $definition) {
            $data[] = ['key' => $key, 'value' => $values[$key], 'updated_at' => $rows->get($key)?->toISOString()] + $definition;
        }

        return ApiResponse::success(data: RestaurantSettingResource::collection($data));
    }

    public function update(UpdateSettingsRequest $request, UpdateRestaurantSettingsAction $action): JsonResponse
    {
        $action->handle($request->validated('settings'), $request->user());

        return ApiResponse::success(message: 'تم تحديث الإعدادات.');
    }

    public function audit(SettingAuditRequest $request, RestaurantSettingsService $settings): JsonResponse
    {
        $data = $request->validated();
        $query = SettingChangeLog::query()->with('changedBy:id,uuid');
        if (isset($data['key'])) {
            $query->where('setting_key', $data['key']);
        }
        if (isset($data['user_uuid'])) {
            $query->whereHas('changedBy', fn ($user) => $user->where('uuid', $data['user_uuid']));
        }
        $tz = $settings->getString('restaurant.timezone');
        if (isset($data['date_from'])) {
            $query->where('created_at', '>=', CarbonImmutable::parse($data['date_from'], $tz)->startOfDay()->utc());
        }
        if (isset($data['date_to'])) {
            $query->where('created_at', '<', CarbonImmutable::parse($data['date_to'], $tz)->startOfDay()->addDay()->utc());
        }

        return ApiResponse::paginated(SettingChangeLogResource::collection($query->orderByDesc('id')->paginate($data['per_page'] ?? 20)));
    }
}
