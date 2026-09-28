<?php
declare(strict_types=1);

/** Generate an OOXML workbook in memory, without requiring the ZIP extension. */
function salesReceiptRenderExcel(array $receipt, bool $official = false): string
{
    $xml = static fn ($value): string => htmlspecialchars(
        preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $value) ?? '',
        ENT_XML1 | ENT_QUOTES, 'UTF-8'
    );
    $amount = round((float) ($receipt['amount_due'] ?? $receipt['total_cost'] ?? 0), 2);
    $paid = round((float) ($receipt['amount_paid'] ?? 0), 2);
    $rows = [
        1 => ['ERNI Development Center Philippines, Inc.'],
        2 => [$official ? 'OFFICIAL PAID RECEIPT' : 'BILLING RECEIPT FOR PAYMENT REVIEW'],
        4 => ['Receipt number', $receipt['receipt_number'] ?? ''],
        5 => ['Status', ucwords(str_replace('_', ' ', (string) ($receipt['status'] ?? 'draft')))],
        6 => ['Issued', $receipt['created_at'] ?? ''],
        7 => ['Due date', $receipt['due_date'] ?? 'Not set'],
        9 => ['Bill to', ($receipt['client_name'] ?? '') ?: ($receipt['fallback_client_name'] ?? 'Client')],
        10 => ['Email', ($receipt['client_email'] ?? '') ?: ($receipt['fallback_client_email'] ?? '')],
        11 => ['Project', $receipt['project_name'] ?? ''],
        13 => ['Description', 'Amount (PHP)'],
        14 => ['Final project delivery', $amount],
        16 => ['Total billed', $amount],
        17 => ['Amount paid', $paid],
        18 => ['Balance due', max(0, $amount - $paid)],
        20 => ['Notes', $receipt['notes'] ?? ''],
        22 => [$official ? 'Payment verified by Sales.' : 'Please send payment confirmation or proof of payment to Sales. The official paid receipt is issued after verification.'],
    ];
    if ($official && !empty($receipt['payment_verified_at'])) {
        $rows[23] = ['Payment verified', $receipt['payment_verified_at']];
    }
    $sheetRows = '';
    foreach ($rows as $row => $values) {
        $height = in_array($row, [1, 2, 20, 22], true) ? 48 : 32;
        $sheetRows .= '<row r="' . $row . '" ht="' . $height . '" customHeight="1">';
        foreach ($values as $column => $value) {
            $ref = ($column === 0 ? 'A' : 'B') . $row;
            $style = in_array($row, [1, 2, 13], true) ? 1 : 0;
            if (is_float($value) || is_int($value)) {
                $formula = match ($row) { 16 => '<f>SUM(B14)</f>', 18 => '<f>MAX(0,B16-B17)</f>', default => '' };
                $sheetRows .= '<c r="' . $ref . '" s="2">' . $formula . '<v>' . number_format($value, 2, '.', '') . '</v></c>';
            } else {
                // Inline strings keep user-entered text from becoming Excel formulas.
                $sheetRows .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . $xml($value) . '</t></is></c>';
            }
        }
        $sheetRows .= '</row>';
    }
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $entries = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="' . $ns . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Receipt" sheetId="1" r:id="rId1"/></sheets><calcPr calcId="0" fullCalcOnLoad="1"/></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="' . $ns . '"><numFmts count="1"><numFmt numFmtId="164" formatCode="&quot;₱&quot;#,##0.00"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="12"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1F6392"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
        'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="' . $ns . '"><sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><sheetViews><sheetView showGridLines="0" workbookViewId="0"/></sheetViews><cols><col min="1" max="1" width="38" customWidth="1"/><col min="2" max="2" width="58" customWidth="1"/></cols><sheetData>' . $sheetRows . '</sheetData><mergeCells count="3"><mergeCell ref="A1:B1"/><mergeCell ref="A2:B2"/><mergeCell ref="A22:B22"/></mergeCells><pageMargins left="0.3" right="0.3" top="0.4" bottom="0.4" header="0.2" footer="0.2"/><pageSetup paperSize="9" orientation="portrait" fitToWidth="1" fitToHeight="1"/></worksheet>',
    ];
    $data = '';
    $directory = '';
    foreach ($entries as $name => $content) {
        $offset = strlen($data);
        $size = strlen($content);
        $crc = crc32($content);
        $data .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 33, $crc, $size, $size, strlen($name), 0) . $name . $content;
        $directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 33, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
    }
    return $data . $directory . pack('VvvvvVVv', 0x06054b50, 0, 0, count($entries), count($entries), strlen($directory), strlen($data), 0);
}
