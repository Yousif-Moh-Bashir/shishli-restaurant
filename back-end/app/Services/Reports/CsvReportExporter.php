<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvReportExporter
{
    public function __construct(private ReportService $reports, private SalesReportService $sales, private PaymentReportService $payments) {}

    public function export(string $report, ReportFilterData $filter, string $userUuid): StreamedResponse
    {
        $query = $report === 'sales/trend' ? null : ($report === 'payments' ? $this->payments->exportQuery($filter) : $this->reports->rows($report, $filter));
        if ($query !== null) {
            $count = (clone $query)->reorder()->getCountForPagination();
            abort_if($count > config('business.export_max_rows'), 422, 'Export exceeds the synchronous row limit.');
        }
        $columns = match ($report) {
            'orders' => ['order_uuid', 'order_number', 'type', 'status', 'payment_method', 'placed_at', 'total'],
            'sales/trend' => ['period', 'orders_count', 'completed_orders_count', 'completed_sales', 'average_order_value'],
            'products' => ['product_uuid', 'product_name', 'quantity_sold', 'orders_count', 'gross_item_sales', 'discount_total', 'net_item_sales'],
            'payments' => ['transaction_uuid', 'order_uuid', 'method', 'provider', 'type', 'status', 'processed_at', 'amount'],
            'customers' => ['customer_uuid', 'name', 'orders_count', 'completed_orders_count', 'total_spent', 'last_order_at'],
            'promotions' => ['promotion_uuid', 'promotion_name', 'usage_count', 'orders_count', 'discount_total', 'sales_before_discount', 'sales_after_discount'],
            default => [],
        };
        abort_if($columns === [], 404);
        Log::info('Report export', ['user_uuid' => $userUuid, 'report' => $report, 'date_from' => $filter->dateFrom,
            'date_to' => $filter->dateTo, 'branch_uuid' => $filter->branchUuid]);

        return response()->streamDownload(function () use ($query, $filter, $columns): void {
            $stream = fopen('php://output', 'wb');
            try {
                fwrite($stream, "\xEF\xBB\xBF");
                fputcsv($stream, $columns, ',', '"', '');
                $rows = $query === null ? $this->sales->trend($filter) : $query->lazy(500);
                foreach ($rows as $row) {
                    $values = ReportMath::row($row);
                    $cells = [];
                    foreach ($columns as $column) {
                        $value = (string) ($values[$column] ?? '');
                        // Spreadsheet formula injection is possible even in correctly quoted CSV.
                        if (preg_match('/^[\\s\\x{FEFF}]*[=+@-]/u', $value)) {
                            $value = "'".$value;
                        }
                        $cells[] = $value;
                    }
                    fputcsv($stream, $cells, ',', '"', '');
                }
            } finally {
                fclose($stream);
            }
        }, str_replace('/', '-', $report).'-'.$filter->dateFrom.'-'.$filter->dateTo.'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
