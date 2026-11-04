<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\OutflowRequest;
use App\Http\Resources\Finance\RecordResource;
use App\Http\Responses\ApiResponse;
use App\Models\Finance\Outflow;
use App\Services\Finance\FinanceAccess;
use App\Services\Finance\FinanceQuery;
use App\Services\Finance\OutflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OutflowController extends Controller
{
    public function __construct(private OutflowService $service, private FinanceAccess $access, private FinanceQuery $query) {}

    private function relations(string $kind): array
    {
        return ['branch', 'creator', $kind === 'purchases' ? 'supplier' : 'category'];
    }

    private function find(Request $request, string $kind, string $uuid): Outflow
    {
        $class = $this->service->model($kind);

        return $this->access->scope($class::query(), $request->user())->where('uuid', $uuid)->firstOrFail();
    }

    public function index(Request $request): JsonResponse
    {
        $kind = $request->route('kind');
        abort_unless($request->user()->can('finance.'.$kind.'.view'), 403);
        $class = $this->service->model($kind);
        $query = $this->query->apply($class::query(), $request, $this->query->filters($request));

        return ApiResponse::paginated(RecordResource::collection($query->with($this->relations($kind))->orderByDesc('date')->orderByDesc('id')->paginate(25)));
    }

    public function store(OutflowRequest $request): JsonResponse
    {
        $kind = $request->route('kind');
        $record = $this->service->save($request->user(), $kind, $request->validated(), null, $request->file('attachment'));

        return ApiResponse::success(new RecordResource($record->load($this->relations($kind))), statusCode: 201);
    }

    public function update(OutflowRequest $request): JsonResponse
    {
        $kind = $request->route('kind');
        $uuid = $request->route('uuid');
        $record = $this->service->save($request->user(), $kind, $request->validated(), $uuid, $request->file('attachment'));

        return ApiResponse::success(new RecordResource($record->load($this->relations($kind))));
    }

    public function attachment(Request $request): BinaryFileResponse
    {
        $kind = $request->route('kind');
        $uuid = $request->route('uuid');
        abort_unless($request->user()->can('finance.'.$kind.'.view'), 403);
        $record = $this->find($request, $kind, $uuid);
        abort_unless($record->attachment_path && Storage::disk('local')->exists($record->attachment_path), 404);

        return response()->download(Storage::disk('local')->path($record->attachment_path), 'attachment.'.pathinfo($record->attachment_path, PATHINFO_EXTENSION), ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
