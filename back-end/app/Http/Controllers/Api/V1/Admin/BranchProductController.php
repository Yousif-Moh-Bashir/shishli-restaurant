<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Branches\AttachBranchProductAction;
use App\Actions\Branches\BulkUpsertBranchProductsAction;
use App\Actions\Branches\DetachBranchProductAction;
use App\Actions\Branches\ListBranchProductsAction;
use App\Actions\Branches\UpdateBranchProductAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AttachBranchProductRequest;
use App\Http\Requests\Api\V1\Admin\BulkBranchProductsRequest;
use App\Http\Requests\Api\V1\Admin\ListBranchProductsRequest;
use App\Http\Requests\Api\V1\Admin\UpdateBranchProductRequest;
use App\Http\Resources\Api\V1\BranchProductResource;
use App\Http\Responses\ApiResponse;
use App\Models\Branch;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

class BranchProductController extends Controller
{
    public function index(ListBranchProductsRequest $request, Branch $branch, ListBranchProductsAction $list): JsonResponse
    {
        return ApiResponse::paginated(BranchProductResource::collection($list->handle($branch, $request->validated())));
    }

    public function store(AttachBranchProductRequest $request, Branch $branch, AttachBranchProductAction $attach): JsonResponse
    {
        return ApiResponse::success(data: new BranchProductResource($attach->handle($branch, $request->validated())), statusCode: 201);
    }

    public function update(UpdateBranchProductRequest $request, Branch $branch, Product $product, UpdateBranchProductAction $update): JsonResponse
    {
        return ApiResponse::success(data: new BranchProductResource($update->handle($branch, $product, $request->validated())));
    }

    public function destroy(Branch $branch, Product $product, DetachBranchProductAction $detach): JsonResponse
    {
        $detach->handle($branch, $product);

        return ApiResponse::success(message: 'تم فك ربط المنتج بالفرع بنجاح.');
    }

    public function bulk(BulkBranchProductsRequest $request, Branch $branch, BulkUpsertBranchProductsAction $bulk): JsonResponse
    {
        return ApiResponse::success(data: BranchProductResource::collection($bulk->handle($branch, $request->validated('products'))));
    }
}
