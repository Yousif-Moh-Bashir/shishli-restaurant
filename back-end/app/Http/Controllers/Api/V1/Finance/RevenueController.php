<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\RevenueRequest;
use App\Http\Resources\Finance\RecordResource;
use App\Http\Responses\ApiResponse;
use App\Models\Finance\RevenueDay;
use App\Services\Finance\FinanceAccess;
use App\Services\Finance\FinanceQuery;
use App\Services\Finance\RevenueService;
use App\Services\Finance\ValidationMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RevenueController extends Controller
{
    public function __construct(private RevenueService $service, private FinanceQuery $query, private FinanceAccess $access) {}

    private function resource(RevenueDay $day): RecordResource
    {
        return new RecordResource($day->load(['branch', 'creator', 'approver', 'lines.source']));
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $this->query->filters($request);
        $query = $this->query->apply(RevenueDay::query(), $request, $filters);
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['source_uuid'])) {
            $query->whereHas('lines.source', fn ($q) => $q->where('uuid', $filters['source_uuid']));
        }

        return ApiResponse::paginated(RecordResource::collection($query->with(['branch', 'creator', 'approver', 'lines.source'])->orderByDesc('date')->orderByDesc('id')->paginate(25)));
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        return ApiResponse::success($this->resource($this->access->scope(RevenueDay::query(), $request->user())->where('uuid', $uuid)->firstOrFail()));
    }

    public function store(RevenueRequest $request): JsonResponse
    {
        return ApiResponse::success($this->resource($this->service->save($request->user(), $request->validated())), statusCode: 201);
    }

    public function update(RevenueRequest $request, string $uuid): JsonResponse
    {
        return ApiResponse::success($this->resource($this->service->save($request->user(), $request->validated(), $uuid)));
    }

    public function correct(RevenueRequest $request, string $uuid): JsonResponse
    {
        return ApiResponse::success($this->resource($this->service->save($request->user(), $request->validated(), $uuid, true)));
    }

    public function approve(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']], ValidationMessages::messages(), ValidationMessages::attributes());

        return ApiResponse::success($this->resource($this->service->approve($request->user(), $uuid, (int) $data['version'])));
    }
}
