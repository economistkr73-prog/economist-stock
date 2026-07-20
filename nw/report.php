<?php
/**
 * nw/report.php — 노르웨이의숲 원룸 월납부 엑셀 보고서 (건물 하나 · 연도별 시트)
 *
 * 요청: /nw/report.php?building_id=N
 * 출력: 건물 1개의 연도별 시트를 담은 엑셀 파일(.xlsx). ZipArchive 없으면 SpreadsheetML(.xls)로 폴백.
 *
 * 각 시트 = 한 해. 행 = 호실별 1행(그 해 대표계약 기준 계약정보) + 월(1~12) 실제 입금총액.
 *  - 월 칸 = 그 달 pay_date 기준 그 호실에 실제 입금된 총액(월세+관리비+주차비+공과금 전부).
 *  - 소계  = 대표계약 월세+관리비+주차비(예상 월납부액).
 *  - 계산은 실입금일(pay_date) 기준 — .class 수정 없이 read-only 집계.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/cnt.inc';
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/auth_fnc.php';
require_login();

$buildingId = (int)($_GET['building_id'] ?? $_GET['id'] ?? 0);

$nw = new Nw($pdo);
$nw->ensureTable(); // water_fee/electric_fee/parking_fee 등 컬럼 보장(멱등)

$b = $nw->getBuilding($buildingId);
if (!$b) { header('Content-Type: text/plain; charset=UTF-8'); http_response_code(404); echo '건물을 찾을 수 없습니다.'; exit; }

$units    = $nw->listUnits($buildingId);              // 전 호실(공실 포함)
$allContr = $nw->listAllContractsByBuilding($buildingId); // 호실별 과거+현재 전 계약

// 호실별 계약 묶음
$byUnit = [];
foreach ($allContr as $c) { $byUnit[(int)$c['unit_id']][] = $c; }

// ── 월별 실제 입금 매트릭스: [unit_id][YYYY][M] => 입금총액(모든 항목 합) — pay_date 기준
$mat = [];
$stmt = $pdo->prepare("
    SELECT u.id AS unit_id,
           CAST(DATE_FORMAT(p.pay_date, '%Y') AS UNSIGNED) AS py,
           CAST(DATE_FORMAT(p.pay_date, '%c') AS UNSIGNED) AS pm,
           SUM(p.rent_fee + p.maintenance_fee + p.parking_fee
               + COALESCE(p.water_fee, 0) + COALESCE(p.electric_fee, 0)) AS amt
      FROM nw_payment p
      JOIN nw_contract c ON c.id = p.contract_id
      JOIN nw_unit u     ON u.id = c.room_id
     WHERE u.building_id = :b AND p.pay_date IS NOT NULL
     GROUP BY u.id, py, pm
");
$stmt->execute([':b' => $buildingId]);
foreach ($stmt->fetchAll() as $r) {
    $mat[(int)$r['unit_id']][(int)$r['py']][(int)$r['pm']] = (int)$r['amt'];
}

// ── 시트로 만들 연도: 입금이 있는 연도들(오름차순). 없으면 올해 1개.
$years = [];
foreach ($mat as $u) { foreach ($u as $y => $_) { $years[$y] = true; } }
$years = array_keys($years);
sort($years);
if (!$years) { $years = [(int)date('Y')]; }

// ── 대표계약: 그 해 재실기간이 걸치는 계약 중 시작이 가장 늦은(최신) 계약
function nwr_year(?string $d): int { return preg_match('/^(\d{4})/', (string)$d, $m) ? (int)$m[1] : 0; }
function nwr_repContract(array $contracts, int $Y): ?array {
    $rep = null; $repKey = '';
    foreach ($contracts as $c) {
        $s  = ($c['balance_date'] ?? '') ?: (($c['contract_date'] ?? '') ?: ($c['first_contract_date'] ?? ''));
        $sy = nwr_year($s);
        if (!$sy) continue;
        $e  = ($c['move_out_date'] ?? '') ?: ($c['contract_end_date'] ?? '');
        $ey = ($c['status'] ?? '') === 'active' ? 9999 : (nwr_year($e) ?: $sy);
        if ($sy <= $Y && $Y <= $ey) {
            $k = sprintf('%s-%08d', $s, (int)$c['id']); // 시작 늦은 것 우선, 동시작이면 id 큰 것
            if ($k > $repKey) { $repKey = $k; $rep = $c; }
        }
    }
    return $rep;
}

// ── 셀 모델: ['t'=>'s'|'n', 'v'=>값, 'st'=>스타일키] · 스타일키 H/C/L/N/FN/FL
$C = fn($v)  => ['t' => 's', 'v' => ($v === '' || $v === null) ? '-' : $v, 'st' => 'C'];
$H = fn($v)  => ['t' => 's', 'v' => $v, 'st' => 'H'];
$MONEY = fn($v) => ((int)$v > 0) ? ['t' => 'n', 'v' => (int)$v, 'st' => 'N'] : ['t' => 's', 'v' => '-', 'st' => 'C'];
$FN = fn($v) => ['t' => 'n', 'v' => (int)$v, 'st' => 'FN'];
$FL = fn($v) => ['t' => 's', 'v' => $v, 'st' => 'FL'];

$colWidths = [8, 11, 12, 10, 10, 11, 12, 12, 8];
for ($m = 1; $m <= 12; $m++) $colWidths[] = 9;   // 1~12월
$colWidths[] = 13;                               // 입금계
$header = ['호실', '세입자', '보증금', '월세', '관리비', '소계', '계약시작일', '계약종료일', '수금일'];
for ($m = 1; $m <= 12; $m++) $header[] = $m . '월';
$header[] = '입금계';

$sheets = [];
foreach ($years as $Y) {
    $rows = [];
    $rows[] = array_map($H, $header);

    // 합계 누적
    $tDep = $tRent = $tMnt = $tSub = $tGrand = 0;
    $tMon = array_fill(1, 12, 0);

    foreach ($units as $u) {
        $uid = (int)$u['id'];
        $rep = nwr_repContract($byUnit[$uid] ?? [], $Y);

        $dep  = $rep ? (int)$rep['deposit'] : 0;
        $rent = $rep ? (int)$rep['rent_fee'] : 0;
        $mnt  = $rep ? (int)$rep['maintenance_fee'] : 0;
        $park = $rep ? (int)($rep['parking_fee'] ?? 0) : 0;
        $sub  = $rep ? $rent + $mnt + $park : 0;

        $row = [
            $C($u['room_no']),
            $C($rep['tenant_name'] ?? ''),
            $MONEY($dep),
            $MONEY($rent),
            $MONEY($mnt),
            $MONEY($sub),
            $C($rep ? substr((string)$rep['contract_date'], 0, 10) : ''),
            $C($rep ? substr((string)$rep['contract_end_date'], 0, 10) : ''),
            $C($rep && (int)($rep['rent_pay_day'] ?? 0) ? (int)$rep['rent_pay_day'] . '일' : ''),
        ];

        $grand = 0;
        for ($m = 1; $m <= 12; $m++) {
            $amt = (int)($mat[$uid][$Y][$m] ?? 0);
            $row[] = $MONEY($amt);
            $grand += $amt;
            $tMon[$m] += $amt;
        }
        $row[] = $MONEY($grand);

        $tDep += $dep; $tRent += $rent; $tMnt += $mnt; $tSub += $sub; $tGrand += $grand;
        $rows[] = $row;
    }

    // 합계 행
    $foot = [$FL('합계'), $FL(''), $FN($tDep), $FN($tRent), $FN($tMnt), $FN($tSub), $FL(''), $FL(''), $FL('')];
    for ($m = 1; $m <= 12; $m++) $foot[] = $FN($tMon[$m]);
    $foot[] = $FN($tGrand);
    $rows[] = $foot;

    $sheets[] = ['name' => $Y . '년', 'cols' => $colWidths, 'rows' => $rows];
}

// ==========================================================
// 직렬화
// ==========================================================
function nwr_x($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }
function nwr_col(int $n): string { $s = ''; while ($n > 0) { $n--; $s = chr(65 + $n % 26) . $s; $n = intdiv($n, 26); } return $s; }

$fname = preg_replace('/[\\\\\/:*?"<>|]+/', '_', $b['name']) . '_월납부보고서';

// 파일 열 때 기본 선택 시트 = 현재연도, 없으면 최신(마지막) 연도 — 오래된 시트가 먼저 열려 착각하는 것 방지
$activeIdx = count($sheets) - 1;
$curName = date('Y') . '년';
foreach ($sheets as $i => $s) { if ($s['name'] === $curName) { $activeIdx = $i; break; } }

if (class_exists('ZipArchive')) {
    nwr_stream_xlsx($sheets, $fname . '.xlsx', $activeIdx);
} else {
    nwr_stream_spreadsheetml($sheets, $fname . '.xls', $activeIdx);
}
exit;

// ---------- .xlsx (OOXML) ----------
function nwr_styles_xlsx(): string {
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
      . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'                                                                                                            // 0 default
      . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' // 1 H
      . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>'                                       // 2 C
      . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="left"/></xf>'                                         // 3 L
      . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'                                                                      // 4 N
      . '<xf numFmtId="164" fontId="1" fillId="2" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>'                                          // 5 FN
      . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>'          // 6 FL
      . '</cellXfs>'
      . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
      . '</styleSheet>';
}

function nwr_sheet_xlsx(array $s, bool $isActive = false): string {
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
            $ref = nwr_col($c) . $r;
            $st  = $map[$cell['st']];
            if ($cell['t'] === 'n') {
                $cells .= '<c r="' . $ref . '" s="' . $st . '"><v>' . (int)$cell['v'] . '</v></c>';
            } else {
                $v = $cell['v'];
                if ($v === '' || $v === null) $cells .= '<c r="' . $ref . '" s="' . $st . '"/>';
                else $cells .= '<c r="' . $ref . '" s="' . $st . '" t="inlineStr"><is><t xml:space="preserve">' . nwr_x($v) . '</t></is></c>';
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

function nwr_stream_xlsx(array $sheets, string $filename, int $activeIdx = 0): void {
    $tmp = tempnam(sys_get_temp_dir(), 'nwx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);

    $ov = '';
    foreach ($sheets as $i => $s) { $n = $i + 1; $ov .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'; }
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
        $sh  .= '<sheet name="' . nwr_x($s['name']) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $rel .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
    }
    $rel .= '<Relationship Id="rId' . (count($sheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
      . '<bookViews><workbookView activeTab="' . $activeIdx . '"/></bookViews>'
      . '<sheets>' . $sh . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rel . '</Relationships>');
    $zip->addFromString('xl/styles.xml', nwr_styles_xlsx());
    foreach ($sheets as $i => $s) { $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', nwr_sheet_xlsx($s, $i === $activeIdx)); }
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="report.xlsx"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-cache');
    readfile($tmp);
    @unlink($tmp);
}

// ---------- SpreadsheetML 2003 (.xls) 폴백 ----------
function nwr_stream_spreadsheetml(array $sheets, string $filename, int $activeIdx = 0): void {
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
                    $cellsXml .= '<Cell ss:StyleID="' . $cell['st'] . '"><Data ss:Type="String">' . nwr_x($cell['v']) . '</Data></Cell>';
                }
            }
            $rowsXml .= '<Row>' . $cellsXml . '</Row>';
        }
        $colsXml = '';
        $ci = 1;
        foreach ($s['cols'] as $w) { $colsXml .= '<Column ss:Index="' . $ci . '" ss:Width="' . ($w * 7) . '"/>'; $ci++; }
        $ncol = count($s['cols']);
        $ws .= '<Worksheet ss:Name="' . nwr_x($s['name']) . '">'
             . '<Table ss:ExpandedColumnCount="' . $ncol . '">' . $colsXml . $rowsXml . '</Table>'
             . '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">' . ($si === $activeIdx ? '<Selected/>' : '') . '<FreezePanes/><FrozenNoSplit/><SplitHorizontal>1</SplitHorizontal><TopRowBottomPane>1</TopRowBottomPane><ActivePane>2</ActivePane></WorksheetOptions>'
             . '</Worksheet>';
    }

    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
      . '<?mso-application progid="Excel.Sheet"?>'
      . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
      . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
      . ' xmlns:x="urn:schemas-microsoft-com:office:excel"'
      . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"'
      . ' xmlns:html="http://www.w3.org/TR/REC-html40">'
      . '<ExcelWorkbook xmlns="urn:schemas-microsoft-com:office:excel"><ActiveSheet>' . $activeIdx . '</ActiveSheet></ExcelWorkbook>'
      . $styles . $ws . '</Workbook>';

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="report.xls"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Content-Length: ' . strlen($xml));
    header('Cache-Control: no-cache');
    echo $xml;
}
