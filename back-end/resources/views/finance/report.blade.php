<!doctype html>
<html lang="ar" dir="rtl">
<head><meta charset="UTF-8"><title>Shishli Finance · التقرير المالي</title>
<style>
body{font-family:tajawal,sans-serif;color:#291b1d;font-size:10px;line-height:1.6}
table{border-collapse:collapse;width:100%}td,th{text-align:right;vertical-align:middle}
.brand{border-top:4px solid #5a1725;border-bottom:1px solid #e9dfd8;margin-bottom:18px}
.brand td{padding:12px 0}.brand .logo{width:95px;padding-left:18px}.logo img{width:82px}
.brand-name{font-size:20px;font-weight:bold;color:#5a1725}.brand-name span{color:#ad792b}
.muted{color:#77696d}.report-title{text-align:left}.report-title h1{font-size:18px;color:#5a1725;margin:0 0 5px}
.scope{background:#faf7f4;margin-bottom:18px}.scope td{padding:10px 14px;width:33.33%;border:1px solid #e9dfd8}
.label{font-size:9px;color:#77696d}.value{font-size:11px;color:#5a1725}
.metrics{margin-bottom:18px}.metrics td{width:25%;padding:12px;border:1px solid #e9dfd8;background:#fffdfa}
.metrics .amount{font-size:20px;font-weight:bold;color:#5a1725}.metrics small{font-size:9px;color:#77696d}
.metrics .net{background:#f6eeee}.negative{color:#a52e3a!important}
h2{font-size:13px;color:#5a1725;margin:20px 0 9px}
.data th{background:#5a1725;color:#fff;padding:10px 12px;font-size:10px;font-weight:normal;border:1px solid #5a1725}
.data td{padding:9px 12px;border:1px solid #e9dfd8}.data tr.even td{background:#faf7f4}
.data .total td{background:#f1e7dc;color:#5a1725;font-weight:bold;border-top:2px solid #c9963b}
.number{text-align:left;direction:ltr;white-space:nowrap}.period{direction:ltr;text-align:right;white-space:nowrap}
.basis{border-right:3px solid #c9963b;background:#faf7f4;padding:12px;margin-top:18px;font-size:9px;color:#77696d}
.empty{padding:18px!important;text-align:center;color:#77696d}
thead{display:table-header-group}tr{page-break-inside:avoid}
</style></head>
@php
    $money = static function ($amount): string {
        $parts = explode('.', (string) $amount);
        return preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $parts[0]).'.'.($parts[1] ?? '00');
    };
    $totals = $report['totals'];
@endphp
<body>
<table class="brand"><tr>
    <td class="logo"><img src="{{ $logo }}" alt="شعار مطعم شيشلي"></td>
    <td><div class="brand-name" dir="ltr">Shishli <span>Finance</span></div><div class="muted">مطعم شيشلي · الإدارة المالية الداخلية</div></td>
    <td class="report-title"><h1>تقرير الحركة المالية</h1><div class="muted">الفترة: <span dir="ltr">{{ $report['filters']['from'] }} — {{ $report['filters']['to'] }}</span></div></td>
</tr></table>
<table class="scope"><tr>
    <td><div class="label">الفرع</div><div class="value">{{ $report['scope']['branch'] }}</div></td>
    <td><div class="label">مصدر الإيراد</div><div class="value">{{ $report['scope']['source'] }}</div></td>
    <td><div class="label">العملة · المنطقة الزمنية</div><div class="value">{{ $report['scope']['currency'] }} · {{ $report['scope']['timezone'] }}</div></td>
</tr></table>
<table class="metrics"><tr>
    <td><div class="label">الإيرادات المعتمدة</div><div class="amount" dir="ltr">{{ $money($totals['revenues']) }}</div><small>ريال سعودي</small></td>
    <td><div class="label">المصروفات المدفوعة</div><div class="amount" dir="ltr">{{ $money($totals['expenses']) }}</div><small>ريال سعودي</small></td>
    <td><div class="label">المشتريات المدفوعة</div><div class="amount" dir="ltr">{{ $money($totals['purchases']) }}</div><small>ريال سعودي</small></td>
    <td class="net"><div class="label">صافي التدفق المسجل</div><div class="amount {{ str_starts_with($totals['net_flow'], '-') ? 'negative' : '' }}" dir="ltr">{{ $money($totals['net_flow']) }}</div><small>ريال سعودي</small></td>
</tr></table>
<h2>تفاصيل الفترات</h2>
<table class="data"><thead><tr><th>الفترة</th><th>الإيرادات المعتمدة</th><th>المصروفات</th><th>المشتريات</th><th>صافي التدفق المسجل</th></tr></thead><tbody>
@forelse($report['periods'] as $row)
<tr class="{{ $loop->even ? 'even' : '' }}"><td class="period">{{ $row['period'] }}</td><td class="number">{{ $money($row['revenues']) }}</td><td class="number">{{ $money($row['expenses']) }}</td><td class="number">{{ $money($row['purchases']) }}</td><td class="number {{ str_starts_with($row['net_flow'], '-') ? 'negative' : '' }}">{{ $money($row['net_flow']) }}</td></tr>
@empty
<tr><td colspan="5" class="empty">لا توجد حركة مالية مسجلة ضمن الفترة المختارة.</td></tr>
@endforelse
<tr class="total"><td>الإجمالي</td><td class="number">{{ $money($totals['revenues']) }}</td><td class="number">{{ $money($totals['expenses']) }}</td><td class="number">{{ $money($totals['purchases']) }}</td><td class="number {{ str_starts_with($totals['net_flow'], '-') ? 'negative' : '' }}">{{ $money($totals['net_flow']) }}</td></tr>
</tbody></table>
<h2>الإيرادات حسب المصدر</h2>
<table class="data"><thead><tr><th>مصدر الإيراد</th><th>المبلغ (ريال سعودي)</th></tr></thead><tbody>
@forelse($report['sources'] as $source)
<tr class="{{ $loop->even ? 'even' : '' }}"><td>{{ $source['name'] }}</td><td class="number">{{ $money($source['amount']) }}</td></tr>
@empty
<tr><td colspan="2" class="empty">لا توجد إيرادات معتمدة ضمن الفترة المختارة.</td></tr>
@endforelse
</tbody></table>
<div class="basis"><strong>أساس إعداد التقرير</strong><br>{{ $report['basis'] }}</div>
</body></html>
