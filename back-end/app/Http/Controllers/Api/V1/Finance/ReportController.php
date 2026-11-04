<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Finance\AuditLog;
use App\Services\Finance\ReportExport;
use App\Services\Finance\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReportController extends Controller
{
    public function __construct(private ReportService $reports, private ReportExport $exporter) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(DB::transaction(fn (): array => $this->reports->build($request)));
    }

    public function export(Request $request): Response
    {
        $format = $request->route('format');
        $report = DB::transaction(fn (): array => $this->reports->build($request));
        $body = $format === 'pdf' ? $this->exporter->pdf($report) : $this->exporter->xlsx($report);
        AuditLog::create([
            'user_id' => $request->user()->id, 'branch_id' => null, 'action' => 'reports.export',
            'subject_type' => 'finance_reports', 'subject_uuid' => (string) Str::uuid(),
            'old_values' => [], 'new_values' => ['filters' => $report['filters'], 'format' => $format],
        ]);

        return response($body, 200, [
            'Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="shishli-finance.'.$format.'"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
