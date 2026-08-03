<?php
/**
 * gift/export.php — 선물 회차 엑셀 다운로드
 *
 *   ?id=<회차>&type=vendor  → ① 업체 발송용 (택배 건만 · 배송 라벨 기준)
 *   ?id=<회차>&type=full    → ② 전체 내역   (발송구분별 시트 분리 + 합계 행)
 *
 * ★ 이 서버에는 composer/PhpSpreadsheet 가 없다. nw/report.php 가 쓰는 순수 PHP
 *   OOXML 라이터를 그대로 가져왔다(gx_ 접두어). ZipArchive 가 없으면 SpreadsheetML(.xls)로 폴백.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/cnt.inc';
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/auth_fnc.php';
require_login();

$batchId = (int)($_GET['id'] ?? 0);
$type    = ($_GET['type'] ?? 'vendor') === 'full' ? 'full' : 'vendor';

$gift = new Gift($pdo);
$gift->ensureTable();

$batch = $gift->batchGet($batchId);
if (!$batch) {
    header('Content-Type: text/plain; charset=UTF-8');
    http_response_code(404);
    echo '회차를 찾을 수 없습니다.';
    exit;
}
$items = $gift->items($batchId);

// 셀 모델: ['t'=>'s'|'n', 'v'=>값, 'st'=>스타일키] · 스타일키 H/C/L/N/FN/FL
$H  = fn($v) => ['t' => 's', 'v' => $v, 'st' => 'H'];
$C  = fn($v) => ['t' => 's', 'v' => (string)$v, 'st' => 'C'];
$L  = fn($v) => ['t' => 's', 'v' => (string)$v, 'st' => 'L'];
$N  = fn($v) => ['t' => 'n', 'v' => (int)$v,    'st' => 'N'];
$FN = fn($v) => ['t' => 'n', 'v' => (int)$v,    'st' => 'FN'];
$FL = fn($v) => ['t' => 's', 'v' => (string)$v, 'st' => 'FL'];

$fullAddr = function (array $it): string {
    return trim(trim((string)$it['address1']) . ' ' . trim((string)$it['address2']));
};

$sheets = [];
$title  = preg_replace('/[\\\\\/:*?"<>|]+/', '_', (string)$batch['title']);

if ($type === 'vendor') {
    // ── ① 업체 발송용 — 택배 건만, 배송 라벨에 필요한 칸만
    $header = ['No', '받는분', '연락처', '우편번호', '주소', '상품명', '수량', '비고'];
    $rows   = [array_map($H, $header)];

    $no = 0;
    $qty = 0;
    foreach ($items as $it) {
        if ($it['delivery_type'] !== '택배') continue;
        $no++;
        $qty += (int)$it['total_qty'];
        $rows[] = [
            $N($no),
            $C($it['customer_name']),
            $C($it['phone']),
            $C($it['zipcode']),
            $L($fullAddr($it)),
            $L($it['product_name']),
            $N($it['total_qty']),
            $L($it['note']),
        ];
    }
    $rows[] = [$FL('합계'), $FL(''), $FL(''), $FL(''), $FL(''), $FL($no . '건'), $FN($qty), $FL('')];

    $sheets[] = ['name' => '업체발송용', 'cols' => [6, 14, 16, 10, 56, 22, 7, 20], 'rows' => $rows];
    $fname    = $title . '_발송명단_' . date('Ymd');

} else {
    // ── ② 전체 내역 — 화면 리스트 전 컬럼, 발송구분별 시트 분리
    $header = ['No', '고객명', '추가정보', '연락처', '우편번호', '주소', '발송구분',
               '물품', '단가', '추가', '총지급', '금액', '비고'];
    $cols   = [6, 16, 18, 16, 10, 50, 10, 22, 11, 7, 9, 12, 20];

    $mk = function (array $list, string $name) use ($header, $cols, $H, $C, $L, $N, $FN, $FL, $fullAddr) {
        $rows = [array_map($H, $header)];
        $no = 0; $qty = 0; $amt = 0;
        foreach ($list as $it) {
            $no++;
            $qty += (int)$it['total_qty'];
            $amt += (int)$it['amount'];
            $rows[] = [
                $N($no),
                $C($it['customer_name']),
                $L($it['customer_memo']),
                $C($it['phone']),
                $C($it['zipcode']),
                $L($fullAddr($it)),
                $C($it['delivery_type']),
                $L($it['product_name']),
                $N($it['unit_price']),
                $N($it['extra_qty']),
                $N($it['total_qty']),
                $N($it['amount']),
                $L($it['note']),
            ];
        }
        $rows[] = [
            $FL('합계'), $FL($no . '명'), $FL(''), $FL(''), $FL(''), $FL(''), $FL(''),
            $FL(''), $FL(''), $FL(''), $FN($qty), $FN($amt), $FL(''),
        ];
        return ['name' => $name, 'cols' => $cols, 'rows' => $rows];
    };

    $sheets[] = $mk($items, '전체');
    foreach (Gift::DELIVERY as $dt) {
        $sub = array_values(array_filter($items, fn($it) => $it['delivery_type'] === $dt));
        if ($sub) $sheets[] = $mk($sub, $dt);
    }
    $fname = $title . '_전체내역_' . date('Ymd');
}

if (class_exists('ZipArchive')) {
    gx_stream_xlsx($sheets, $fname . '.xlsx');
} else {
    gx_stream_spreadsheetml($sheets, $fname . '.xls');
}
exit;

// ==========================================================
// 직렬화 (원본: nw/report.php)
// ==========================================================
function gx_x($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }
function gx_col(int $n): string { $s = ''; while ($n > 0) { $n--; $s = chr(65 + $n % 26) . $s; $n = intdiv($n, 26); } return $s; }

// ---------- .xlsx (OOXML) ----------
function gx_styles_xlsx(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
      . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0"/></numFmts>'
      . '<fonts count="2">'
      . '<font><sz val="10"/><name val="맑은 고딕"/></font>'
      . '<font><b/><sz val="10"/><name val="맑은 고딕"/></font>'
      . '</fonts>'
      . '<fills count="3">'
      . '<fill><patternFill patternType="none"/></fill>'
      . '<fill><patternFill patternType="gray125"/></fill>'
      . '<fill><patternFill patternType="solid"><fgColor rgb="FFF2F2F2"/><bgColor indexed="64"/></patternFill></fill>'
      . '</fills>'
      . '<borders count="2">'
      . '<border><left/><right/><top/><bottom/><diagonal/></border>'
      . '<border><left style="thin"><color rgb="FFBFBFBF"/></left><right style="thin"><color rgb="FFBFBFBF"/></right><top style="thin"><color rgb="FFBFBFBF"/></top><bottom style="thin"><color rgb="FFBFBFBF"/></bottom><diagonal/></border>'
      . '</borders>'
      . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
      . '<cellXfs count="7">'
      . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
      . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
      . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
      . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="left"/></xf>'
      . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
      . '<xf numFmtId="164" fontId="1" fillId="2" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>'
      . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
      . '</cellXfs>'
      . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
      . '</styleSheet>';
}

function gx_sheet_xlsx(array $s, bool $isActive = false): string
{
    $map = ['H' => 1, 'C' => 2, 'L' => 3, 'N' => 4, 'FN' => 5, 'FL' => 6];
    $colsXml = '<cols>';
    $ci = 1;
    foreach ($s['cols'] as $w) { $colsXml .= '<col min="' . $ci . '" max="' . $ci . '" width="' . $w . '" customWidth="1"/>'; $ci++; }
    $colsXml .= '</cols>';

    $rowsXml = '';
    $r = 1;
    foreach ($s['rows'] as $row) {
        $cells = '';
        $c = 1;
        foreach ($row as $cell) {
            $ref = gx_col($c) . $r;
            $st  = $map[$cell['st']];
            if ($cell['t'] === 'n') {
                $cells .= '<c r="' . $ref . '" s="' . $st . '"><v>' . (int)$cell['v'] . '</v></c>';
            } else {
                $v = $cell['v'];
                if ($v === '' || $v === null) $cells .= '<c r="' . $ref . '" s="' . $st . '"/>';
                else $cells .= '<c r="' . $ref . '" s="' . $st . '" t="inlineStr"><is><t xml:space="preserve">' . gx_x($v) . '</t></is></c>';
            }
            $c++;
        }
        $rowsXml .= '<row r="' . $r . '">' . $cells . '</row>';
        $r++;
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
      . '<sheetViews><sheetView' . ($isActive ? ' tabSelected="1"' : '') . ' workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A2" sqref="A2"/></sheetView></sheetViews>'
      . '<sheetFormatPr defaultRowHeight="15"/>'
      . $colsXml
      . '<sheetData>' . $rowsXml . '</sheetData>'
      . '</worksheet>';
}

function gx_stream_xlsx(array $sheets, string $filename): void
{
    $tmp = tempnam(sys_get_temp_dir(), 'gfx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);

    $ov = '';
    foreach ($sheets as $i => $s) {
        $n = $i + 1;
        $ov .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
      . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
      . '<Default Extension="xml" ContentType="application/xml"/>'
      . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
      . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
      . $ov . '</Types>');

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
      . '</Relationships>');

    $sh = ''; $rel = '';
    foreach ($sheets as $i => $s) {
        $n = $i + 1;
        $sh  .= '<sheet name="' . gx_x($s['name']) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $rel .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
    }
    $rel .= '<Relationship Id="rId' . (count($sheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
      . '<bookViews><workbookView activeTab="0"/></bookViews>'
      . '<sheets>' . $sh . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rel . '</Relationships>');
    $zip->addFromString('xl/styles.xml', gx_styles_xlsx());
    foreach ($sheets as $i => $s) {
        $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', gx_sheet_xlsx($s, $i === 0));
    }
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="gift.xlsx"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-cache');
    readfile($tmp);
    @unlink($tmp);
}

// ---------- SpreadsheetML 2003 (.xls) 폴백 ----------
function gx_stream_spreadsheetml(array $sheets, string $filename): void
{
    $border = '<Borders>'
      . '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BFBFBF"/>'
      . '<Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BFBFBF"/>'
      . '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BFBFBF"/>'
      . '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BFBFBF"/>'
      . '</Borders>';
    $styles = '<Styles>'
      . '<Style ss:ID="Default" ss:Name="Normal"><Alignment ss:Vertical="Center"/><Font ss:FontName="맑은 고딕" ss:Size="10"/></Style>'
      . '<Style ss:ID="H"><Font ss:Bold="1" ss:FontName="맑은 고딕" ss:Size="10"/><Interior ss:Color="#F2F2F2" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center" ss:Vertical="Center"/>' . $border . '</Style>'
      . '<Style ss:ID="C"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/>' . $border . '</Style>'
      . '<Style ss:ID="L"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/>' . $border . '</Style>'
      . '<Style ss:ID="N"><NumberFormat ss:Format="#,##0"/><Alignment ss:Vertical="Center"/>' . $border . '</Style>'
      . '<Style ss:ID="FN"><Font ss:Bold="1"/><Interior ss:Color="#F2F2F2" ss:Pattern="Solid"/><NumberFormat ss:Format="#,##0"/>' . $border . '</Style>'
      . '<Style ss:ID="FL"><Font ss:Bold="1"/><Interior ss:Color="#F2F2F2" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center" ss:Vertical="Center"/>' . $border . '</Style>'
      . '</Styles>';

    $ws = '';
    foreach ($sheets as $si => $s) {
        $rowsXml = '';
        foreach ($s['rows'] as $row) {
            $cellsXml = '';
            foreach ($row as $cell) {
                if ($cell['t'] === 'n') {
                    $cellsXml .= '<Cell ss:StyleID="' . $cell['st'] . '"><Data ss:Type="Number">' . (int)$cell['v'] . '</Data></Cell>';
                } else {
                    $cellsXml .= '<Cell ss:StyleID="' . $cell['st'] . '"><Data ss:Type="String">' . gx_x($cell['v']) . '</Data></Cell>';
                }
            }
            $rowsXml .= '<Row>' . $cellsXml . '</Row>';
        }
        $colsXml = '';
        $ci = 1;
        foreach ($s['cols'] as $w) { $colsXml .= '<Column ss:Index="' . $ci . '" ss:Width="' . ($w * 7) . '"/>'; $ci++; }
        $ws .= '<Worksheet ss:Name="' . gx_x($s['name']) . '">'
             . '<Table ss:ExpandedColumnCount="' . count($s['cols']) . '">' . $colsXml . $rowsXml . '</Table>'
             . '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">' . ($si === 0 ? '<Selected/>' : '') . '<FreezePanes/><FrozenNoSplit/><SplitHorizontal>1</SplitHorizontal><TopRowBottomPane>1</TopRowBottomPane><ActivePane>2</ActivePane></WorksheetOptions>'
             . '</Worksheet>';
    }

    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
      . '<?mso-application progid="Excel.Sheet"?>'
      . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
      . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
      . ' xmlns:x="urn:schemas-microsoft-com:office:excel"'
      . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"'
      . ' xmlns:html="http://www.w3.org/TR/REC-html40">'
      . '<ExcelWorkbook xmlns="urn:schemas-microsoft-com:office:excel"><ActiveSheet>0</ActiveSheet></ExcelWorkbook>'
      . $styles . $ws . '</Workbook>';

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="gift.xls"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Content-Length: ' . strlen($xml));
    header('Cache-Control: no-cache');
    echo $xml;
}
?>
