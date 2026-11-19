<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Printing\PrintJobBroker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PrintAgentController extends Controller
{
    public function connectivity(): JsonResponse
    {
        return ApiResponse::success(data: ['connected' => true]);
    }

    public function claim(Request $request, PrintJobBroker $broker): JsonResponse
    {
        $data = $request->validate(['mode' => ['required', Rule::in(['preview', 'live'])]]);

        return ApiResponse::success(data: $broker->claim($request->attributes->get('print_agent'), $data['mode']));
    }

    public function start(Request $request, string $job, PrintJobBroker $broker): JsonResponse
    {
        $data = $request->validate(['attempt_id' => ['required', 'uuid'], 'mode' => ['required', Rule::in(['preview', 'live'])]]);

        return ApiResponse::success(data: $broker->start($request->attributes->get('print_agent'), $job, $data['attempt_id'], $data['mode']));
    }

    public function result(Request $request, string $job, PrintJobBroker $broker): JsonResponse
    {
        $data = $request->validate(['attempt_id' => ['required', 'uuid'],
            'outcome' => ['required', Rule::in(['simulated', 'spooler_accepted', 'not_submitted', 'uncertain'])],
            'error_code' => ['nullable', Rule::in(['PRINTER_UNAVAILABLE', 'RENDER_FAILED', 'SPOOLER_FAILED', 'AGENT_RESTART', 'NETWORK_INTERRUPTED'])],
            'spooler_job_id' => ['nullable', 'string', 'regex:/\A[0-9]{1,10}\z/']]);

        return ApiResponse::success(data: $broker->result($request->attributes->get('print_agent'), $job,
            $data['attempt_id'], $data['outcome'], $data['error_code'] ?? null, $data['spooler_job_id'] ?? null));
    }
}
