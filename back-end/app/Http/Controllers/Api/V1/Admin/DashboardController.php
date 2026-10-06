<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ReportFilterRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Reports\DashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(ReportFilterRequest $request, DashboardService $dashboard): JsonResponse
    {
        return ApiResponse::success(data: $dashboard->get($request->filters(), $request->user()->can('reports.payments.view'), $request->user()->can('inventory.view'), $request->user()->can('reports.sales.view')));
    }
}
