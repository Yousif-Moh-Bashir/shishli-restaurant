<?php

namespace App\Services\Reports;

use Illuminate\Database\Query\Builder;

class PaymentReportService
{
    public function __construct(private ReportQuery $queries) {}

    public function transactions(ReportFilterData $filter, bool $successful = false): Builder
    {
        $query = $this->queries->orders($filter, period: false)
            ->join('payments', 'payments.order_id', '=', 'orders.id')
            ->join('payment_transactions AS transactions', 'transactions.payment_id', '=', 'payments.id');
        if ($successful) {
            return $query->where('transactions.status', 'succeeded')->whereIn('transactions.type', ['sale', 'capture'])
                ->where('transactions.processed_at', '>=', $filter->from)->where('transactions.processed_at', '<', $filter->until);
        }

        return $query->whereRaw('COALESCE(transactions.processed_at, transactions.created_at) >= ?', [$filter->from])
            ->whereRaw('COALESCE(transactions.processed_at, transactions.created_at) < ?', [$filter->until]);
    }

    public function collected(ReportFilterData $filter): string
    {
        return ReportMath::decimal((int) $this->transactions($filter, true)->selectRaw('COALESCE(SUM('.ReportMath::cents('transactions.amount').'), 0) AS amount')->value('amount'));
    }

    public function summary(ReportFilterData $filter): array
    {
        $payments = $this->queries->orders($filter, false)->join('payments', 'payments.order_id', '=', 'orders.id')
            ->where('payments.created_at', '>=', $filter->from)->where('payments.created_at', '<', $filter->until)->count();
        $success = $this->transactions($filter, true)->selectRaw('COUNT(DISTINCT payments.id) AS successful_payments_count,
            COALESCE(SUM('.ReportMath::cents('transactions.amount').'), 0) AS collected_amount_minor')->first();
        $result = ReportMath::row($success);

        return ['payments_count' => $payments, ...$result,
            'failed_attempts_count' => $this->transactions($filter)->where('transactions.status', 'failed')->count(),
            'refunds_supported' => false, 'refunded_amount' => null];
    }

    /** Each transaction is counted once; operation and provider IDs are unique in the ledger. */
    public function breakdown(ReportFilterData $filter): Builder
    {
        $ledger = $this->transactions($filter)->select('transactions.payment_id')
            ->selectRaw('COUNT(*) AS transactions_count')
            ->selectRaw("COALESCE(SUM(CASE WHEN transactions.status = 'succeeded' AND transactions.type IN ('sale', 'capture') AND transactions.processed_at IS NOT NULL THEN ".ReportMath::cents('transactions.amount').' ELSE 0 END), 0) AS collected_amount_minor')
            ->groupBy('transactions.payment_id');

        return $this->queries->orders($filter, false)->join('payments', 'payments.order_id', '=', 'orders.id')
            ->leftJoinSub($ledger, 'ledger', 'ledger.payment_id', '=', 'payments.id')
            ->where(function (Builder $query) use ($filter): void {
                $query->where(function (Builder $query) use ($filter): void {
                    $query->where('payments.created_at', '>=', $filter->from)->where('payments.created_at', '<', $filter->until);
                })->orWhereNotNull('ledger.payment_id');
            })
            ->select('payments.method', 'payments.provider', 'payments.status')
            ->selectRaw('COUNT(*) AS payments_count, COALESCE(SUM(ledger.transactions_count), 0) AS transactions_count,
                COALESCE(SUM(ledger.collected_amount_minor), 0) AS collected_amount_minor')
            ->groupBy('payments.method', 'payments.provider', 'payments.status')
            ->orderBy('payments.method')->orderBy('payments.provider')->orderBy('payments.status');
    }

    public function exportQuery(ReportFilterData $filter): Builder
    {
        return $this->transactions($filter)->select('transactions.uuid AS transaction_uuid', 'orders.uuid AS order_uuid',
            'payments.method', 'transactions.provider', 'transactions.type', 'transactions.status', 'transactions.processed_at')
            ->selectRaw(ReportMath::cents('transactions.amount').' AS amount_minor')->orderBy('transactions.id');
    }
}
