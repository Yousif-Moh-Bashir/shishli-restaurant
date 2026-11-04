<?php

namespace App\Services\Finance;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use ZipArchive;

class ReportExport
{
    public function html(array $report): string
    {
        $logo = 'data:image/png;base64,'.base64_encode(file_get_contents(resource_path('images/finance/shishli-logo.png')));

        return view('finance.report', ['report' => $report, 'logo' => $logo])->render();
    }

    public function pdf(array $report): string
    {
        $directory = storage_path('app/private/finance/pdf-temp');
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Cannot create private PDF temporary directory.');
        }
        $pdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L',
            'tempDir' => $directory,
            'fontDir' => [...(new ConfigVariables)->getDefaults()['fontDir'], resource_path('fonts/finance')],
            'fontdata' => [
                ...(new FontVariables)->getDefaults()['fontdata'],
                'tajawal' => ['R' => 'Tajawal-Regular.ttf', 'B' => 'Tajawal-Bold.ttf', 'useOTL' => 0xFF],
            ],
            'default_font' => 'tajawal',
            'autoScriptToLang' => true,
            'autoLangToFont' => false,
            'margin_left' => 14,
            'margin_right' => 14,
            'margin_top' => 14,
            'margin_bottom' => 20,
            'margin_footer' => 9,
        ]);
        $pdf->SetDirectionality('rtl');
        $pdf->SetTitle('Shishli Finance');
        $pdf->SetAuthor('Shishli Fresh');
        $pdf->SetHTMLFooter('<div dir="rtl" style="font-family:tajawal;border-top:1px solid #e9dfd8;padding-top:8px;font-size:9px;color:#77696d;text-align:center">Shishli Finance · للاستخدام الداخلي فقط &nbsp; | &nbsp; صفحة {PAGENO} من {nbpg}</div>');
        $pdf->WriteHTML($this->html($report));

        return $pdf->Output('', 'S');
    }

    public function xlsx(array $report): string
    {
        $rows = [
            ['Shishli Finance', $report['filters']['from'], $report['filters']['to']],
            [$report['scope']['branch'], $report['scope']['source'], $report['scope']['currency'], $report['scope']['timezone']],
            [$report['basis']],
            ['الفترة', 'الإيرادات المعتمدة', 'المصروفات', 'المشتريات', 'صافي التدفق المسجل'],
        ];
        foreach ($report['periods'] as $row) {
            $rows[] = [$row['period'], $row['revenues'], $row['expenses'], $row['purchases'], $row['net_flow']];
        }
        $total = $report['totals'];
        $rows[] = ['الإجمالي', $total['revenues'], $total['expenses'], $total['purchases'], $total['net_flow']];
        $rows[] = ['مصدر الإيراد', 'المبلغ'];
        foreach ($report['sources'] as $source) {
            $rows[] = [$source['name'], $source['amount']];
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0" rightToLeft="1"/></sheetViews><sheetData>';
        foreach ($rows as $row) {
            $xml .= '<row>';
            foreach ($row as $column => $value) {
                if ($column > 0 && preg_match('/\A-?\d+\.\d{2}\z/', (string) $value)) {
                    $xml .= '<c t="n"><v>'.$value.'</v></c>';

                    continue;
                }
                // Inline strings prevent spreadsheet formula injection in user-defined source names.
                $xml .= '<c t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData></worksheet>';
        $path = tempnam(sys_get_temp_dir(), 'finance-xlsx-');
        try {
            $zip = new ZipArchive;
            throw_unless($zip->open($path, ZipArchive::OVERWRITE) === true, \RuntimeException::class, 'Cannot create Excel file.');
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
            $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Shishli Finance" sheetId="1" r:id="rId1"/></sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
            $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
            $zip->close();

            return file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }
}
