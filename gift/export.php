<?php
/**
 * gift/export.php — 선물 회차 엑셀 다운로드
 *
 *   ?id=<회차>&type=vendor  → ① 업체 발송용 (택배 건만 · 배송 라벨 기준)
 *   ?id=<회차>&type=full    → ② 전체 내역   (발송구분별 시트 분리 + 합계 행)
 *   ?id=<회차>&type=print   → ③ 큰 글씨 인쇄용 HTML (연락처·주소·물품 없이 종이로 보는 명단)
 *   …&preview=1              → ①②를 내려받기 전에 «같은 행 배열»을 HTML 표로 보여 준다(새 탭 · 안에서 내려받기)
 *
 * ★ 이 서버에는 composer/PhpSpreadsheet 가 없다. nw/report.php 가 쓰는 순수 PHP
 *   OOXML 라이터를 그대로 가져왔다(gx_ 접두어). ZipArchive 가 없으면 SpreadsheetML(.xls)로 폴백.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/cnt.inc';
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/auth_fnc.php';
require_login();

$batchId = (int)($_GET['id'] ?? 0);
$typeIn  = (string)($_GET['type'] ?? 'vendor');
$type    = in_array($typeIn, ['full', 'print'], true) ? $typeIn : 'vendor';

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

// ── ③ 큰 글씨 인쇄용 — 엑셀이 아니라 HTML. 브라우저 인쇄(Ctrl+P)로 종이에 뽑는다.
//    연로하신 분이 종이로 보는 명단이라 우편번호·연락처·주소·물품을 뺐다(2026-08-30 사용자 지시).
if ($type === 'print') {
    gx_print_html($batch, $items);
    exit;
}

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

// ── 미리보기 — 직렬화 «직전»의 같은 $sheets 를 HTML 로 그린다. 데이터를 두 번 만들지 않으니
//    미리보기와 파일이 다른 말을 할 수 없다(2026-08-31 사용자 요청 — 업체발송용은 택배 건만 담겨 행 수가 목록과 다르다).
if (!empty($_GET['preview'])) {
    gx_preview_html($batch, $sheets, $type, $batchId);
    exit;
}

if (class_exists('ZipArchive')) {
    gx_stream_xlsx($sheets, $fname . '.xlsx');
} else {
    gx_stream_spreadsheetml($sheets, $fname . '.xls');
}
exit;

// ==========================================================
// 엑셀 미리보기 HTML — 시트 배열을 표로. 스타일키(H/C/L/N/FN/FL)는 xlsx 와 같은 뜻으로 그린다.
// ==========================================================
function gx_preview_html(array $batch, array $sheets, string $type, int $batchId): void
{
    $h     = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $label = $type === 'vendor' ? '업체 발송용 리스트' : '전체 내역 리스트';
    $dl    = '/gift/export.php?id=' . $batchId . '&type=' . $h($type);
    $cls   = ['H' => 'hd', 'C' => 'c', 'L' => 'l', 'N' => 'n', 'FN' => 'fn', 'FL' => 'fl'];

    $body = '';
    foreach ($sheets as $sh) {
        $n = max(0, count($sh['rows']) - 2); // 머리글·합계 행 제외
        $body .= '<section class="sheet"><h2>' . $h($sh['name']) . ' <span>' . $n . '행</span></h2>'
               . '<div class="wrap"><table>';
        foreach ($sh['rows'] as $row) {
            $body .= '<tr>';
            foreach ($row as $cell) {
                $st  = $cell['st'] ?? 'L';
                $txt = ($cell['t'] ?? 's') === 'n' ? number_format((int)$cell['v']) : (string)$cell['v'];
                $tag = $st === 'H' ? 'th' : 'td';
                $body .= '<' . $tag . ' class="' . ($cls[$st] ?? 'l') . '">' . $h($txt) . '</' . $tag . '>';
            }
            $body .= '</tr>';
        }
        $body .= '</table></div></section>';
    }
    if (!$sheets) $body = '<p class="empty">내보낼 행이 없습니다.</p>';

    $title = $h($batch['title']);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . $title . ' · ' . $label . '</title>'
       . '<style>'
       . "body{font-family:'맑은 고딕','Malgun Gothic',sans-serif;color:#2c3e50;margin:0;padding:18px 22px;background:#f6f7f9}"
       . '.bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 14px}'
       . '.bar h1{font-size:17px;margin:0 12px 0 0}.bar h1 small{font-weight:400;color:#7f8c8d;font-size:13px;margin-left:6px}'
       . '.bar .note{color:#7f8c8d;font-size:12px;flex-basis:100%}'
       . '.btn{display:inline-block;border:1px solid #bdc3c7;background:#fff;color:#2c3e50;border-radius:6px;padding:7px 14px;font-size:13px;cursor:pointer;text-decoration:none}'
       . '.btn:hover{background:#ecf0f1}.btn.pri{background:#3498db;border-color:#3498db;color:#fff}.btn.pri:hover{background:#2980b9}'
       . '.sheet{background:#fff;border:1px solid #e1e4e8;border-radius:8px;padding:12px 14px;margin:0 0 16px}'
       . '.sheet h2{font-size:14px;margin:0 0 8px}.sheet h2 span{font-weight:400;color:#7f8c8d;font-size:12px}'
       . '.wrap{overflow-x:auto}table{border-collapse:collapse;font-size:12.5px;white-space:nowrap}'
       . 'th,td{border:1px solid #d5d8dc;padding:4px 8px}'
       . 'th.hd{background:#f2f2f2;font-weight:700;text-align:center}'
       . 'td.c{text-align:center}td.l{text-align:left}td.n{text-align:right;font-variant-numeric:tabular-nums}'
       . 'td.fn{text-align:right;background:#f2f2f2;font-weight:700}td.fl{background:#f2f2f2;font-weight:700}'
       . 'tr:hover td{background:#fbfcfd}tr:hover td.fn,tr:hover td.fl{background:#f2f2f2}'
       . '.empty{color:#7f8c8d}'
       . '</style></head><body>'
       . '<div class="bar"><h1>' . $title . '<small>' . $label . '</small></h1>'
       . '<a class="btn pri" href="' . $dl . '">⬇ 엑셀 내려받기</a>'
       . '<button class="btn" onclick="window.close()">닫기</button>'
       . '<div class="note">엑셀에 담기는 행과 같습니다'
       . ($type === 'vendor' ? ' — 업체 발송용은 <b>「택배」 건만</b> 담깁니다(일괄·별도는 제외).' : ' — 시트마다 한 표로 폈습니다.')
       . '</div></div>'
       . $body
       . '</body></html>';
}

// ==========================================================
// 큰 글씨 인쇄용 HTML
// ==========================================================
function gx_print_html(array $batch, array $items): void
{
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

    // 한 사람 = 셀 4칸. 한 행에 두 사람을 나란히 — 왼단 1..절반, 오른단 절반+1.. (신문 단 조판)
    $cells = function (?array $it, int $no) use ($h): string {
        if ($it === null) return '<td class="c"></td><td></td><td></td><td></td>';
        return '<td class="c">' . $no . '</td>'
             . '<td class="nm">' . $h($it['customer_name']) . '</td>'
             . '<td class="memo">' . $h($it['customer_memo']) . '</td>'
             . '<td class="c">' . (int)$it['total_qty'] . '</td>';
    };

    // 발송구분별로 페이지를 가른다 — 구분은 절 제목이 말하므로 칸에서는 뺐다. 번호는 구분마다 1부터.
    $groups = [];
    foreach (Gift::DELIVERY as $dt) {
        $sub = array_values(array_filter($items, fn($it) => $it['delivery_type'] === $dt));
        if ($sub) $groups[] = [$dt, $sub];
    }
    $etc = array_values(array_filter($items, fn($it) => !in_array($it['delivery_type'], Gift::DELIVERY, true)));
    if ($etc) $groups[] = ['기타', $etc];

    $thHalf = '<th>No</th><th>고객명</th><th>추가정보</th><th>수량</th>';
    $colHalf = '<col class="w-no"><col class="w-nm"><col class="w-memo"><col class="w-q">';

    $sectHtml = '';
    foreach ($groups as [$dt, $list]) {
        $n    = count($list);
        $half = (int)ceil($n / 2);
        $rowsHtml = '';
        for ($i = 0; $i < $half; $i++) {
            $right = $list[$i + $half] ?? null;
            $rowsHtml .= '<tr>' . $cells($list[$i], $i + 1)
                       . '<td class="gap"></td>'
                       . $cells($right, $i + $half + 1)
                       . "</tr>\n";
        }
        $sectHtml .= '<div class="sect">'
            . '<h2>' . $h($dt) . ' <span>· ' . $n . '명</span></h2>'
            . '<table>'
            . '<colgroup>' . $colHalf . '<col style="width:8px">' . $colHalf . '</colgroup>'
            . '<thead><tr>' . $thHalf . '<th class="gap"></th>' . $thHalf . "</tr></thead><tbody>\n"
            . $rowsHtml
            . "</tbody></table></div>\n";
    }

    $title = $h($batch['title']);

    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . $title . ' 명단 인쇄</title>'
       . '<style>'
       . '@page{size:A4 portrait;margin:10mm}'
       . "body{font-family:'맑은 고딕','Malgun Gothic',sans-serif;color:#000;margin:0;padding:20px}"
       . '.sect+.sect{page-break-before:always}'
       . 'h2{font-size:17pt;margin:0 0 8px}'
       . 'h2 span{font-size:12pt;font-weight:400;color:#333}'
       . 'table{width:100%;border-collapse:collapse;font-size:13pt;table-layout:fixed}'
       . 'th,td{border:1.2px solid #444;padding:3px 6px;vertical-align:middle;word-break:break-all}'
       . 'thead th{background:#eee;font-size:11pt;text-align:center;padding:4px 6px}'
       . 'td.c{text-align:center}'
       . 'td.nm{font-weight:700;font-size:15pt}'
       . 'td.memo{font-size:9.5pt;color:#333}'
       . 'th.gap,td.gap{border:0;background:none;width:8px;padding:0}'
       . 'col.w-no{width:6%}col.w-nm{width:22%}col.w-memo{width:14%}col.w-q{width:7%}'
       . '.noprint{margin:0 0 14px}'
       . '.noprint button{font-size:14pt;padding:8px 22px;cursor:pointer;margin-right:8px}'
       . '@media print{.noprint{display:none}body{padding:0}}'
       . '</style></head><body>'
       . '<div class="noprint">'
       . '<button onclick="window.print()">🖨 인쇄</button>'
       . '<button onclick="window.close()">닫기</button>'
       . '</div>'
       . $sectHtml
       . '</body></html>';
}

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
