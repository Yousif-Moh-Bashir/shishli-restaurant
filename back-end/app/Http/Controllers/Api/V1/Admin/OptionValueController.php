<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Options\CreateOptionValueAction;
use App\Actions\Options\DeleteOptionValueAction;
use App\Actions\Options\UpdateOptionValueAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreOptionValueRequest;
use App\Http\Requests\Api\V1\Admin\UpdateOptionValueRequest;
use App\Http\Resources\Api\V1\OptionValueResource;
use App\Http\Responses\ApiResponse;
use App\Models\OptionGroup;
use App\Models\OptionValue;
use Illuminate\Http\JsonResponse;

class OptionValueController extends Controller
{
    public function store(StoreOptionValueRequest $request, OptionGroup $optionGroup, CreateOptionValueAction $create): JsonResponse
    {
        return ApiResponse::success(data: new OptionValueResource($create->handle($optionGroup, $request->validated())), statusCode: 201);
    }

    public function update(UpdateOptionValueRequest $request, OptionGroup $optionGroup, OptionValue $optionValue, UpdateOptionValueAction $update): JsonResponse
    {
        return ApiResponse::success(data: new OptionValueResource($update->handle($optionGroup, $optionValue, $request->validated())));
    }

    public function destroy(OptionGroup $optionGroup, OptionValue $optionValue, DeleteOptionValueAction $delete): JsonResponse
    {
        $delete->handle($optionGroup, $optionValue);

        return ApiResponse::success(message: 'تم حذف الخيار بنجاح.');
    }
}
