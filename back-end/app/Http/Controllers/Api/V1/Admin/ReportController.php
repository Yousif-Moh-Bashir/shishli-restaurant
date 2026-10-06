<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ReportFilterRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Reports\CsvReportExporter;
use App\Services\Reports\ReportService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(ReportFilterRequest $request, ReportService $reports): JsonResponse
    {
        return ApiResponse::success(data: $reports->get($request->route()->defaults['report'], $request->filters()));
    }

    public function export(ReportFilterRequest $request, CsvReportExporter $exporter): StreamedResponse
    {
        return $exporter->export($request->route()->defaults['report'], $request->filters(), $request->user()->uuid);
    }
}
