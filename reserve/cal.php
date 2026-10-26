<?php
/**
 * reserve/cal.php — 예약 › 캠핑장 «빈자리 표» (보기 + PDF) · index.php?mode=cal 이 부른다
 *
 * 모양(2026-10-23 사용자 2차 — 「눈에 안 들어온다 · 위 가로줄에 사이트, 아래 세로줄에 날짜, 가능한 곳은 이모티콘」):
 *   머리 두 줄 = 공간(A공간…) / 그 공간의 사이트 번호 · 줄 = 날짜 하나 · 칸 = ⛺ 빈자리 · ✕ 마감 · 빈 칸 예약 불가 · – 오픈 전.
 *   맨 오른쪽 열 = 그 날 빈 사이트 수. 금·토 밤 줄은 바탕을 깐다. 직전 기록에 없던 빈자리는 칸 바탕이 주황(NEW).
 *   (처음 만든 «월 달력» 칸에 사이트 이름을 적는 모양은 같은 날 이걸로 바꿨다 — 칸마다 글자가 많아 안 읽혔다.)
 *   사이트가 많으면(COLS_MAX 초과) 공간 단위로 끊어 여러 장으로 나눈다 — 한 공간은 되도록 한 장에.
 *
 * ★DB(rsv_snap)에서 그린다 — 캠핏을 다시 부르지 않고도 같은 그림이 나오고, 그림에 «언제 확인한 것인지»가 적힌다.
 *   다만 열 때 그 달 기록이 STALE_SEC 보다 묵었으면(또는 없으면) 화면 JS 가 캠핏에서 받아 저장한 뒤 한 번 다시 그린다
 *   (받기·저장은 reserve/rsv.js 단일본 · 저장이 성공하면 확인 시각이 새로 찍혀 다시 받지 않는다 — 되풀이 없음).
 * 첫 장 = 캠핑장 배치도(있을 때 · 기록 JSON 의 map — 이미지는 캠핏 이미지 서버에서 바로 띄운다 · 누르면 원본).
 * ★기간은 «하나로» 그린다 — 달마다 나누지 않는다(같은 날 사용자). 요약 장의 달력도, 상세 표도 기간 전체가 한 덩어리다.
 * PDF = 브라우저 인쇄의 「PDF 로 저장」(서버에 PDF 라이브러리가 없다 · place_list.php 와 같은 방식). A4 세로 · 한 달 한 장.
 *
 * 주소: ?mode=cal&c=<campId>&from=YYYY-MM&n=1~3
 */

const RSV_CAL_STALE_SEC = 300;
const RSV_CAL_MAX_N     = 3;
const RSV_CAL_COLS_MAX  = 32;   // A4 세로 한 장에 넣는 사이트 열 수 상한 (날짜 열 + 32열 ≈ 190mm)

function rsv_ch(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/** 공간 이름 줄임 — 「A공간」→「A」 (줄여서 빈 글자가 되면 원래 이름) */
function rsv_zone_short(string $n): string
{
    $s = trim(preg_replace('/\s*(공간|구역|존|사이트|영역|zone)$/iu', '', $n));
    return $s !== '' ? $s : $n;
}

/** 사이트 이름 줄임 — 공간 머리줄이 이미 「A공간」이라 「A-1」은 「1」만 (앞이 안 맞으면 그대로) */
function rsv_site_short(string $site, string $zoneShort): string
{
    if ($zoneShort !== '' && mb_strpos($site, $zoneShort, 0, 'UTF-8') === 0) {
        $rest = ltrim(mb_substr($site, mb_strlen($zoneShort, 'UTF-8'), null, 'UTF-8'), " -_.");
        if ($rest !== '') return $rest;
    }
    return $site;
}

/** 사이트를 공간 순서대로 묶고, 한 장에 COLS_MAX 열이 넘지 않게 공간 단위로 끊는다. @return array<int, array<int, array{zone:string, sites:array}>> */
function rsv_cal_chunks(array $snap): array
{
    $byZone = [];
    foreach ($snap['sites'] as $s) $byZone[$s['z']][] = $s;
    $groups = [];
    foreach ($snap['zones'] as $z) if (!empty($byZone[$z['id']])) { $groups[] = ['zone' => (string)$z['name'], 'sites' => $byZone[$z['id']]]; unset($byZone[$z['id']]); }
    foreach ($byZone as $ss) $groups[] = ['zone' => '', 'sites' => $ss];   // 공간 목록에 없는 사이트도 버리지 않는다

    $pages = []; $cur = []; $n = 0;
    foreach ($groups as $g) {
        foreach (array_chunk($g['sites'], RSV_CAL_COLS_MAX) as $part) {   // 한 공간이 한 장보다 크면 그 공간만 쪼갠다
            if ($n + count($part) > RSV_CAL_COLS_MAX && $cur) { $pages[] = $cur; $cur = []; $n = 0; }
            $cur[] = ['zone' => $g['zone'], 'sites' => $part];
            $n += count($part);
        }
    }
    if ($cur) $pages[] = $cur;
    return $pages;
}

/**
 * 미니 달력 한 달 — 일요일 시작(일~토) · 칸 = 날짜(작게) + 빈 사이트 수(크게) · 빈자리 있는 날만 초록(많을수록 진하게) · NEW 는 주황 점.
 * 빈자리 없는 날·지난 날은 옅게 남긴다(달력 모양이 있어야 날짜를 찾는다).
 * ★요약 장의 달력은 «월별»로 나란히다 — 기간을 한 달력으로 잇는 모양(rsv_span_cal)은 2026-10-23 사용자가 되돌렸다(「월별로 표시하는 게 좋겠다」).
 *   상세 표는 기간 하나로 합친 그대로다(그건 되돌리지 않았다).
 * @param array $av [date => 빈 수]  $nw [date => NEW 수]
 */
function rsv_mini_cal(string $ym, array $av, array $nw, int $nSites, string $today): string
{
    $first = strtotime($ym . '-01');
    $nDays = (int)date('t', $first);
    $lead  = (int)date('w', $first);   // 일요일 시작 (2026-10-23 사용자 — 「일~토 순으로」)
    $h = '<div class="mc"><div class="mc-t"><b>' . (int)substr($ym, 5) . '월</b><span>빈자리 ' . count($av) . '일</span></div><table><tr>';
    foreach (['일', '월', '화', '수', '목', '금', '토'] as $i => $w) $h .= '<th' . ($i === 0 ? ' class="su"' : ($i >= 5 ? ' class="fs"' : '')) . '>' . $w . '</th>';
    $h .= '</tr>';
    $cells = (int)ceil(($lead + $nDays) / 7) * 7;
    for ($c = 0; $c < $cells; $c++) {
        if ($c % 7 === 0) $h .= '<tr>';
        $dn = $c - $lead + 1;
        if ($dn < 1 || $dn > $nDays) { $h .= '<td class="e"></td>'; }
        else {
            $d = sprintf('%s-%02d', $ym, $dn);
            $n = $av[$d] ?? 0;
            if ($n > 0) {
                $r  = $nSites > 0 ? $n / $nSites : 1;
                $lv = $r <= 0.1 ? 1 : ($r <= 0.35 ? 2 : ($r <= 0.7 ? 3 : 4));
                $h .= '<td class="v l' . $lv . '" title="' . $d . ' 빈 사이트 ' . $n . '/' . $nSites . '"><i>' . $dn . '</i><b>' . $n . '</b>' . (!empty($nw[$d]) ? '<u></u>' : '') . '</td>';
            } else {
                $h .= '<td class="x' . ($d < $today ? ' p' : '') . '"><i>' . $dn . '</i></td>';
            }
        }
        if ($c % 7 === 6) $h .= '</tr>';
    }
    return $h . '</table></div>';
}
function rsv_page_cal(Reserve $rs): void
{
    $ext = strtolower((string)($_GET['c'] ?? ''));
    if (!preg_match('/^[0-9a-f]{24}$/', $ext)) { header('Location: /reserve/index.php?mode=camp'); exit; }
    $thisYm = date('Y-m');
    $from = (string)($_GET['from'] ?? $thisYm);
    if (!preg_match('/^\d{4}-\d{2}$/', $from) || $from < $thisYm) $from = $thisYm;
    $n = max(1, min(RSV_CAL_MAX_N, (int)($_GET['n'] ?? 3)));

    $camp = $rs->campGet($ext);
    try { $picks = $rs->pickGet($ext); } catch (Throwable $e) { $picks = []; }
    $months = [];
    for ($i = 0; $i < $n; $i++) {
        $ym = date('Y-m', strtotime($from . '-01 +' . $i . ' month'));
        $rows = $rs->snapLatest($ext, $ym, 2);
        $months[] = ['ym' => $ym, 'snap' => $rows[0] ?? null,
                     'newly' => $rows ? Reserve::newlyOpen($rows[0], $rows[1] ?? null) : []];
    }
    $name = $camp['name'] ?? '';
    $until = null;
    foreach ($months as $m) {
        if (!$m['snap']) continue;
        if ($name === '') $name = (string)$m['snap']['name'];
        if ($m['snap']['until']) $until = $m['snap']['until'];
    }
    $mapImg = ''; $mapFull = '';   // 배치도 — 가장 앞 달부터 처음 찾은 것
    foreach ($months as $m) if ($m['snap'] && $m['snap']['map'] !== '') { $mapImg = $m['snap']['map']; $mapFull = $m['snap']['mapFull'] ?: $mapImg; break; }
    $realName = $name;                     // 저장에 넘길 이름 — 아래 「캠핑장」 대체 글자를 기록에 남기지 않는다
    if ($name === '') $name = '캠핑장';

    /* 화면 JS 에 넘길 것 — 어느 달을 새로 받아야 하나 */
    $jsMonths = array_map(fn($m) => [
        'ym' => $m['ym'],
        'checked' => $m['snap'] ? strtotime((string)$m['snap']['checked_at']) * 1000 : 0,
    ], $months);

    echo '<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8">';
    echo '<title>' . rsv_ch($name) . ' 빈자리 표</title>';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    nav_css();
    echo <<<'CSS'
<style>
body { margin:0; font-family:'Pretendard','Segoe UI','Malgun Gothic',sans-serif; background:#f4f6f8; color:#222; }
.cl-tool { max-width:980px; margin:0 auto; padding:14px 14px 0; display:flex; flex-wrap:wrap; gap:8px 12px; align-items:center; font-size:14px; }
.cl-tool a.back { color:#1d74e0; text-decoration:none; font-weight:600; }
.cl-tool select { padding:6px 8px; border:1px solid #cfd8e0; border-radius:7px; font-size:14px; font-family:inherit; }
.cl-tool button { padding:7px 14px; border:0; border-radius:8px; font-size:14px; font-weight:700; cursor:pointer; font-family:inherit; }
.cl-tool .pri { background:#14b86c; color:#fff; }
.cl-tool .gray { background:#e9edf1; color:#4a5560; }
#clSt { font-size:13px; color:#7f8c8d; }
#clSt.err { color:#b03a2e; }
.cl-page { max-width:980px; margin:14px auto; background:#fff; border:1px solid #e3e8ee; border-radius:12px; padding:16px 18px 12px; box-sizing:border-box; overflow-x:auto; }
.cl-hd { display:flex; justify-content:space-between; align-items:flex-end; gap:12px; border-bottom:2px solid #222; padding-bottom:6px; margin-bottom:6px; }
.cl-hd h1 { margin:0; font-size:20px; }
.cl-hd h1 span { color:#14b86c; }
.cl-hd h1 em { font-style:normal; font-size:13px; color:#888; font-weight:600; }
.pk-box { margin:4px 0 10px; }
.pk-pick { background:#f7f9fa; border:1px solid #e3e8ee; border-radius:10px; padding:10px 12px; margin-bottom:6px; }
.pk-hd { font-size:12px; color:#666; margin-bottom:6px; display:flex; flex-wrap:wrap; gap:4px 8px; align-items:center; }
.pk-hd b { font-size:13.5px; color:#222; }
.pk-hd button { margin-left:auto; border:1px solid #cfd8e0; background:#fff; border-radius:6px; padding:3px 9px; font-size:12px; cursor:pointer; font-family:inherit; }
.pk-z { display:inline-flex; flex-wrap:wrap; gap:4px; align-items:center; margin:0 14px 6px 0; }
.pk-z i { font-style:normal; font-size:11.5px; font-weight:700; color:#555; margin-right:2px; }
.pk-s { display:inline-flex; align-items:center; gap:3px; padding:3px 8px; border:1px solid #cfd8e0; border-radius:999px; background:#fff; font-size:12.5px; cursor:pointer; user-select:none; }
.pk-s input { display:none; }
.pk-s small { font-size:10px; color:#0e9257; font-weight:700; }
.pk-s.none { color:#aab2b9; } .pk-s.none small { color:#c3c9ce; }
.pk-s.on { background:#14b86c; border-color:#14b86c; color:#fff; font-weight:700; } .pk-s.on small { color:#d6f5e5; }
.pk-empty { font-size:12.5px; color:#888; padding:8px 2px; }
table.pk-ls { border-collapse:collapse; width:100%; font-size:12.5px; }
table.pk-ls th, table.pk-ls td { border-bottom:1px solid #e6e9ec; padding:6px 6px; vertical-align:top; text-align:left; }
table.pk-ls th { width:56px; font-size:13.5px; white-space:nowrap; }
table.pk-ls td.c { width:40px; color:#0e9257; font-weight:800; white-space:nowrap; }
.pkd { display:inline-block; margin:0 5px 4px 0; padding:1px 7px; border:1px solid #dde2e6; border-radius:5px; background:#fff; font-weight:600; font-variant-numeric:tabular-nums; white-space:nowrap; }
.pkd.sun { color:#e5484d; } .pkd.sat { color:#1d74e0; }
.pkd.wk { background:#e3f6ec; border-color:#9ad8b8; }
.pkd.nw { background:#ffe2c2; border-color:#f5b77a; }
.pk-none { color:#aab2b9; }
.psc-wrap { display:grid; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); gap:10px 12px; }   /* 폭을 꽉 채운다 — 같은 날 사용자 「옆으로 길게」 · auto-fill 은 고른 게 적으면 빈 칸을 남긴다(2개일 때 오른쪽 1/3 이 비었다) */
.psc { border:1px solid #e3e8ee; border-radius:10px; padding:7px 9px 8px; break-inside:avoid; }
.psc-h { display:flex; align-items:baseline; gap:8px; margin-bottom:4px; }
.psc-h b { font-size:14.5px; }
.psc-h span { font-size:11px; color:#0e9257; font-weight:700; }
.psc-ms { display:flex; gap:12px; }
.psc-m { flex:1 1 0; min-width:0; }
.psc-mt { font-size:11.5px; font-weight:700; color:#333; margin-bottom:1px; }
.psc-mt span { font-weight:600; color:#0e9257; font-size:10.5px; margin-left:3px; }
.psc table { width:100%; border-collapse:separate; border-spacing:1.5px; table-layout:fixed; }
.psc th { font-size:9px; color:#888; font-weight:700; padding:0; }
.psc th.su { color:#e5484d; } .psc th.fs { color:#e07b00; }
.psc td { height:19px; text-align:center; font-size:10px; border-radius:3px; padding:0; font-variant-numeric:tabular-nums; }
.psc td.e { background:transparent; }
.psc td.x { background:#f3f5f7; color:#b5bcc3; } .psc td.x.p { background:#fafbfb; color:#dde1e4; }
.psc td.v { background:#7fd3a6; color:#064d2c; font-weight:800; }
.psc td.v.wk { background:#0e8f55; color:#fff; }
.psc td.v.nw { background:#ff9a2e; color:#fff; }
table.tg.pk { width:auto; min-width:40%; }
table.tg.pk col.pc { width:52px; }
table.tg.pk thead th small { display:block; font-size:9.5px; color:#0e9257; font-weight:600; }
table.tg.pk td.a { font-size:15px; }
.cl-note { font-size:11.5px; color:#7a6a3a; background:#fbf7e9; border-radius:6px; padding:5px 9px; margin:6px 0 0; }
tr.mb td { border-top:2px solid #6b7782; }
.cl-hd .sub { font-size:11.5px; color:#555; margin-top:3px; }
.cl-hd .asof { text-align:right; font-size:10.5px; color:#555; line-height:1.5; white-space:nowrap; }
.cl-leg { font-size:11px; color:#555; margin:0 0 6px; display:flex; gap:14px; flex-wrap:wrap; align-items:center; }
.cl-leg .k { display:inline-block; min-width:16px; text-align:center; margin-right:3px; }

table.tg { width:100%; border-collapse:collapse; table-layout:fixed; font-size:12px; }
table.tg th, table.tg td { border:1px solid #dde2e6; text-align:center; padding:0; }
table.tg col.dc { width:62px; } table.tg col.nc { width:34px; }
table.tg thead th { background:#f3f5f7; font-weight:700; }
table.tg thead tr.zr th { font-size:11.5px; height:22px; color:#222; }
table.tg thead tr.sr th { font-size:10.5px; height:20px; color:#555; font-weight:600; }
table.tg thead th.zb, table.tg td.zb { border-left:2px solid #6b7782; }
table.tg tbody td { height:22px; }
table.tg tbody td.d { text-align:left; padding-left:6px; font-weight:700; white-space:nowrap; font-size:12px; }
table.tg tbody td.d small { font-weight:600; margin-left:3px; color:#777; }
tr.sun td.d, tr.sun td.d small { color:#e5484d; }
tr.sat td.d, tr.sat td.d small { color:#1d74e0; }
tr.wk td { background:#f2fbf6; }
tr.wk td.d { box-shadow:inset 3px 0 0 #14b86c; }
td.a { font-size:14px; line-height:1; }
td.a.nw { background:#ffe2c2 !important; }
td.s { color:#c3c9ce; font-size:10px; }
td.u { background:#f6f7f8; }
tr.wk td.u { background:#eaf3ee; }
td.o { background:#fbfaf0; color:#cfc79a; font-size:10px; }
td.n { font-weight:800; color:#0e9257; }
td.n.z { color:#c3c9ce; font-weight:400; }
td.n small { color:#ff6a00; font-size:9px; display:block; line-height:1; }
.cl-ft { font-size:11.5px; color:#333; margin-top:6px; line-height:1.7; }
.cl-ft b { margin-right:4px; }
.cl-ft .wk { color:#0e9257; font-weight:700; }
.cl-map { display:block; width:100%; height:auto; border-radius:8px; border:1px solid #e3e8ee; margin-top:6px; }
.mc-wrap { display:flex; gap:18px; flex-wrap:wrap; margin:4px 0 2px; }
.mc { flex:1 1 0; min-width:230px; }
.mc-t { display:flex; justify-content:space-between; align-items:baseline; border-left:4px solid #14b86c; padding-left:7px; margin-bottom:5px; }
.mc-t b { font-size:15px; }
.mc-t span { font-size:11px; color:#0e9257; font-weight:700; }
.mc table { width:100%; border-collapse:separate; border-spacing:3px; table-layout:fixed; }
.mc th { font-size:11px; color:#666; font-weight:700; padding:2px 0; }
.mc th.fs { color:#e07b00; } .mc th.su { color:#e5484d; }
.mc td { height:38px; border-radius:5px; position:relative; vertical-align:top; padding:0; }
.mc td i { position:absolute; left:4px; top:2px; font-style:normal; font-size:9.5px; }
.mc td b { display:block; text-align:center; font-size:16px; line-height:1; padding-top:15px; font-variant-numeric:tabular-nums; }
.mc td u { position:absolute; right:3px; top:3px; width:7px; height:7px; border-radius:50%; background:#ff8a00; text-decoration:none; }
.mc td.e { background:transparent; }
.mc td.x { background:#f5f6f7; } .mc td.x i { color:#c3c9ce; }
.mc td.x.alt { background:#eef0f2; }
.mc td.x.p { background:#fafbfb; } .mc td.x.p i { color:#dde1e4; }
.mc td i.m { font-weight:800; color:#333; font-size:10.5px; }
.mc td.v i.m { color:inherit; }
.mc td.l1 { background:#dff4e8; color:#0e7a49; } .mc td.l1 i { color:#4f9a74; }
.mc td.l2 { background:#a9e0c3; color:#075c35; } .mc td.l2 i { color:#2f7d56; }
.mc td.l3 { background:#46b981; color:#fff; } .mc td.l3 i { color:#e3f7ec; }
.mc td.l4 { background:#0e8f55; color:#fff; } .mc td.l4 i { color:#d5f2e3; }
.cl-h2 { font-size:15px; margin:14px 0 6px; }
.cl-h2 small { font-size:11px; color:#888; font-weight:400; }
table.cl-wk { border-collapse:collapse; width:100%; font-size:12.5px; }
table.cl-wk td { border-bottom:1px solid #e6e9ec; padding:5px 6px; vertical-align:top; }
table.cl-wk td:first-child { width:78px; font-weight:700; white-space:nowrap; }
table.cl-wk td.sat { color:#1d74e0; }
table.cl-wk td.c { width:30px; text-align:right; color:#0e9257; font-weight:800; padding-right:12px; }
table.cl-wk b.nw { color:#ff6a00; }
.cl-none { padding:12px; background:#fdf3f2; color:#b03a2e; border-radius:8px; font-size:13px; margin:6px 0; }
@media print {
  @page { size:A4 portrait; margin:8mm 0; }
  body { background:#fff; }
  .top-nav-bar, .cl-tool, .noprint { display:none !important; }
  .pk-box.empty { display:none; }   /* 고른 사이트가 없으면 PDF 에서 이 칸째로 뺀다 — 「전체 빈자리」 제목도 함께 */
  .pk-box.empty + .pk-all { display:none; }
  .cl-page { max-width:none; margin:0; border:0; border-radius:0; padding:3mm 11mm 0; overflow:visible; page-break-after:always; break-after:page; }
  .cl-page:last-of-type { page-break-after:auto; break-after:auto; }
  table.tg tbody td { height:7.1mm; }
  .cl-map { border:0; border-radius:0; max-height:108mm; width:auto; max-width:100%; margin:0 auto; object-fit:contain; }
  .mc td { height:9.5mm; }
  .cl-h2 { margin:9px 0 4px; }
  * { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
}
@media (max-width:700px) {
  .cl-page { padding:10px; border-radius:0; }
  table.tg { width:auto; }
  table.tg col.sc { width:26px; }
  .cl-hd { flex-direction:column; align-items:flex-start; }
  .cl-hd .asof { text-align:left; white-space:normal; }
}
</style>
CSS;
    echo '</head><body>';
    render_nav('rsv_camp');

    /* ── 도구줄 (인쇄에선 숨김) ── */
    echo '<form class="cl-tool" method="get">';
    echo '<input type="hidden" name="mode" value="cal"><input type="hidden" name="c" value="' . rsv_ch($ext) . '">';
    echo '<a class="back" href="/reserve/index.php?mode=camp&c=' . rsv_ch($ext) . '&m=' . rsv_ch($from) . '">‹ 빈자리 화면</a>';
    echo '<select name="from" onchange="this.form.submit()">';
    for ($i = 0; $i < 12; $i++) {
        $ym = date('Y-m', strtotime($thisYm . '-01 +' . $i . ' month'));
        echo '<option value="' . $ym . '"' . ($ym === $from ? ' selected' : '') . '>' . (int)substr($ym, 0, 4) . '년 ' . (int)substr($ym, 5) . '월부터</option>';
    }
    echo '</select><select name="n" onchange="this.form.submit()">';
    for ($i = 1; $i <= RSV_CAL_MAX_N; $i++) echo '<option value="' . $i . '"' . ($i === $n ? ' selected' : '') . '>' . $i . '개월</option>';
    echo '</select>';
    echo '<button type="button" class="pri" onclick="window.print()">🖨 PDF 로 저장 · 인쇄</button>';
    echo '<button type="button" class="gray" id="clRe">🔄 지금 다시 받기</button>';
    echo '<span id="clSt"></span>';
    echo '</form>';

    $WD = ['일', '월', '화', '수', '목', '금', '토'];
    $today = date('Y-m-d');
    $legend = '<div class="cl-leg"><span><span class="k">⛺</span>빈자리</span><span><span class="k" style="color:#bbb">✕</span>마감</span>'
            . '<span><span class="k" style="background:#f6f7f8;border:1px solid #dde2e6">&nbsp;</span>예약 불가</span>'
            . '<span><span class="k" style="background:#fbfaf0;color:#cfc79a;border:1px solid #eee6b8">–</span>오픈 전</span>'
            . '<span><span class="k" style="background:#ffe2c2">⛺</span>NEW (직전 기록엔 없던 빈자리)</span>'
            . '<span><span class="k" style="background:#f2fbf6;box-shadow:inset 3px 0 0 #14b86c">&nbsp;</span>금·토 밤</span>'
            . '<span style="color:#888">· 빈자리가 없는 날은 뺐습니다</span></div>';

    /* ══ 기간을 하나로 합친다 (2026-10-23 사용자 — 「10월과 11월을 따로 구분하지 말고 합쳐서」) ══
     * 달마다 기록이 따로 있어도 그림은 하나 — 날짜는 이어지고, 사이트는 합집합(같은 캠핑장이라 거의 같다).
     * 확인 시각은 달마다 다를 수 있어 머리에 달별로 적는다. */
    $WD0 = ['일', '월', '화', '수', '목', '금', '토'];
    $today0 = date('Y-m-d');
    $ST = []; $NWS = []; $zonesU = []; $sitesU = []; $asof = []; $noRec = []; $emptyM = [];
    foreach ($months as $m) {
        $mo = (int)substr($m['ym'], 5);
        if (!$m['snap']) { $noRec[] = $mo . '월'; continue; }
        foreach ($m['snap']['zones'] as $z) $zonesU[$z['id']] ??= $z;
        foreach ($m['snap']['sites'] as $st) $sitesU[$st['id']] ??= $st;
        foreach (Reserve::statusMap($m['snap']) as $sid => $days) foreach ($days as $d => $v) $ST[$sid][$d] = $v;
        foreach ($m['newly'] as $k) $NWS[$k] = 1;
        $asof[] = (string)$m['snap']['checked_at'];
    }
    $nSites = count($sitesU);
    $AV = []; $NWC = [];
    foreach ($ST as $sid => $days) foreach ($days as $d => $v) {
        if ($v !== 'A' || $d < $today0) continue;
        $AV[$d] = ($AV[$d] ?? 0) + 1;
        if (isset($NWS[$sid . '|' . $d])) $NWC[$d] = ($NWC[$d] ?? 0) + 1;
    }
    ksort($AV);
    $showDates = array_keys($AV);   // 빈자리가 하나라도 있는 날만 (같은 날 사용자 — 「예약이 불가능한 날짜는 없애줘」)
    foreach ($months as $m) if ($m['snap'] && !array_filter($showDates, fn($d) => substr($d, 0, 7) === $m['ym'])) $emptyM[] = (int)substr($m['ym'], 5) . '월';
    $first = $months[0]['ym']; $last = $months[count($months) - 1]['ym'];
    $span = (int)substr($first, 5) . '월' . ($first !== $last ? ' ~ ' . (int)substr($last, 5) . '월' : '');
    $md = fn($d) => (int)substr($d, 5, 2) . '/' . (int)substr($d, 8);

    /* 머리 — 두 장이 같은 머리를 쓴다 */
    $head = function (string $title) use ($name, $camp, $nSites, $zonesU, $until, $asof) {
        $sub = [];
        if (!empty($camp['address'])) $sub[] = rsv_ch($camp['address']);
        if (!empty($camp['contact'])) $sub[] = '☎ ' . rsv_ch($camp['contact']);
        if ($nSites) $sub[] = count($zonesU) . '개 공간 · ' . $nSites . '사이트';
        if ($until) $sub[] = '예약 오픈 ' . rsv_ch($until) . '까지';
        return '<div class="cl-hd"><div><h1>' . rsv_ch($name) . ' ' . $title . '</h1><div class="sub">' . implode(' · ', $sub) . '</div></div>'
             . '<div class="asof">' . ($asof ? '확인 ' . rsv_ch(str_replace('-', '/', substr(min($asof), 5, 11))) : '기록 없음')   /* 달마다 다르면 가장 오래된 것 — 「이 시각 이후로는 다 확인했다」 */ . '<br>출처: 캠핏 빈자리 캘린더</div></div>';
    };
    $notes = [];
    if ($noRec)  $notes[] = rsv_ch(implode('·', $noRec)) . '은 아직 기록이 없습니다(화면을 열어 두면 캠핏에서 받아 채웁니다)';
    if ($emptyM) $notes[] = rsv_ch(implode('·', $emptyM)) . '은 빈자리가 있는 날이 없습니다' . ($until ? ' — 캠핏 예약 오픈은 ' . rsv_ch($until) . '까지' : '');
    $noteHtml = $notes ? '<div class="cl-note">' . implode('<br>', $notes) . '</div>' : '';

    if ($ST) {
        /* ── 1장: 요약 = 이어진 달력 · 배치도 · 금·토 밤 빈자리 ── */
        $rows = [];
        $nmOf = array_map(fn($x) => $x['name'], $sitesU);
        foreach ($showDates as $d) {
            $w = (int)date('w', strtotime($d));
            if ($w !== 5 && $w !== 6) continue;
            $names = [];
            foreach ($ST as $sid => $days) if (($days[$d] ?? '') === 'A') $names[] = isset($NWS[$sid . '|' . $d]) ? '<b class="nw">' . rsv_ch($nmOf[$sid] ?? '?') . '</b>' : rsv_ch($nmOf[$sid] ?? '?');
            natsort($names);
            $rows[] = '<tr><td class="' . ($w === 6 ? 'sat' : '') . '">' . $md($d) . ' (' . $WD0[$w] . ')</td><td class="c">' . count($names) . '</td><td>' . implode(' · ', $names) . '</td></tr>';
        }
        echo '<section class="cl-page cl-mappg">' . $head('<span>빈자리 요약</span> <em>' . $span . '</em>');
        echo '<h2 class="cl-h2">빈자리 있는 날 <small>(숫자 = 빈 사이트 수 · 진할수록 많음 · 주황 점 = NEW)</small></h2>';
        $minis = [];
        foreach ($months as $m) {
            $avM = array_filter($AV, fn($d) => substr($d, 0, 7) === $m['ym'], ARRAY_FILTER_USE_KEY);
            if ($avM) $minis[] = rsv_mini_cal($m['ym'], $avM, $NWC, $nSites, $today0);   // 빈자리가 하루도 없는 달은 달력도 안 그린다
        }
        echo $minis ? '<div class="mc-wrap">' . implode('', $minis) . '</div>' : '<div class="cl-ft">이 기간에는 빈자리가 있는 날이 없습니다.</div>';
        echo $noteHtml;
        if ($mapImg !== '') {
            echo '<h2 class="cl-h2">배치도</h2>';
            echo '<a href="' . rsv_ch($mapFull) . '" target="_blank" rel="noopener" title="원본 크게 보기"><img class="cl-map" src="' . rsv_ch($mapImg) . '" alt="배치도"></a>';
        }
        echo '<h2 class="cl-h2">금·토 밤 빈자리 <small>(주황 = NEW)</small></h2>';
        echo $rows ? '<table class="cl-wk">' . implode('', $rows) . '</table>' : '<div class="cl-ft">이 기간에는 금·토 밤 빈자리가 없습니다.</div>';
        echo '</section>';

        /* ── 2장~: 사이트 × 날짜 표 하나 (달이 바뀌는 줄에 굵은 선) ── */
        $merged = ['zones' => array_values($zonesU), 'sites' => array_values($sitesU)];
        foreach ($showDates ? rsv_cal_chunks($merged) : [] as $pi => $groups) {
            $pages = count(rsv_cal_chunks($merged));
            echo '<section class="cl-page">' . $head('<span>' . $span . '</span> 빈자리' . ($pages > 1 ? ' <em>(' . ($pi + 1) . '/' . $pages . ')</em>' : ''));
            if ($pi === 0) {
                /* 내가 고른 사이트 (같은 날 사용자 — 「체크한 사이트의 가능 날짜만 리스트로 · 전체 리스트 위에」).
                 * 고르기 칩은 화면에만(인쇄 X) · 고른 게 없으면 인쇄에서 이 칸째로 빠진다 · 그림은 아래 RSV_PICK 으로 JS 가 그린다 */
                echo '<div class="pk-box" id="pkBox"><div class="pk-pick noprint" id="pkPick"></div><div id="pkList"></div></div>';
                echo '<h2 class="cl-h2 pk-all">전체 빈자리</h2>';
            }
            echo $legend;
            $cols = '<col class="dc">'; $zr = '<th rowspan="2">날짜</th>'; $sr = '';
            $siteCols = [];
            foreach ($groups as $g) {
                $zs = rsv_zone_short($g['zone']);
                $zl = $g['zone'] !== '' ? $g['zone'] : '기타';
                if (count($g['sites']) === 1) $zl = $zs !== '' ? $zs : $zl;   // 한 칸짜리 공간은 「J공간」이 두 줄로 꺾인다
                $zr .= '<th class="zb" colspan="' . count($g['sites']) . '" title="' . rsv_ch($g['zone']) . '">' . rsv_ch($zl) . '</th>';
                foreach ($g['sites'] as $k => $st) {
                    $cols .= '<col class="sc">';
                    $sr .= '<th' . ($k === 0 ? ' class="zb"' : '') . ' title="' . rsv_ch($st['name']) . '">' . rsv_ch(rsv_site_short($st['name'], $zs)) . '</th>';
                    $siteCols[] = [$st, $k === 0];
                }
            }
            $cols .= '<col class="nc">';
            $zr .= '<th rowspan="2" class="zb">빈<br>자리</th>';
            echo '<table class="tg"><colgroup>' . $cols . '</colgroup><thead><tr class="zr">' . $zr . '</tr><tr class="sr">' . $sr . '</tr></thead><tbody>';
            $prevYm = '';
            foreach ($showDates as $d) {
                $w  = (int)date('w', strtotime($d));
                $tr = ($w === 0 ? 'sun' : ($w === 6 ? 'sat' : ''));
                if ($w === 5 || $w === 6) $tr .= ' wk';
                if ($prevYm !== '' && substr($d, 0, 7) !== $prevYm) $tr .= ' mb';   // 달이 바뀌는 줄
                $prevYm = substr($d, 0, 7);
                echo '<tr class="' . trim($tr) . '"><td class="d">' . $md($d) . '<small>' . $WD0[$w] . '</small></td>';
                $cnt = 0; $nwC = 0;
                foreach ($siteCols as [$st, $zb]) {
                    $v  = $ST[$st['id']][$d] ?? null;
                    $zc = $zb ? ' zb' : '';
                    if ($v === 'A') {
                        $cnt++;
                        $isNew = isset($NWS[$st['id'] . '|' . $d]);
                        if ($isNew) $nwC++;
                        echo '<td class="a' . ($isNew ? ' nw' : '') . $zc . '" title="' . rsv_ch($st['name']) . ' 빈자리">⛺</td>';
                    } elseif ($v === 'S') {
                        echo '<td class="s' . $zc . '">✕</td>';
                    } elseif ($v !== null && $until && $d > $until) {
                        echo '<td class="o' . $zc . '">–</td>';
                    } else {
                        echo '<td class="u' . $zc . '"></td>';
                    }
                }
                echo '<td class="n zb' . ($cnt ? '' : ' z') . '">' . ($cnt ?: '·') . ($nwC ? '<small>NEW' . $nwC . '</small>' : '') . '</td></tr>';
            }
            echo '</tbody></table>';
            $wkList = [];
            foreach ($showDates as $d) { $w = (int)date('w', strtotime($d)); if ($w === 5 || $w === 6) $wkList[] = $md($d) . '(' . $WD0[$w] . ') ' . $AV[$d]; }
            echo '<div class="cl-ft"><b>빈자리 있는 날 ' . count($showDates) . '일</b> · <span class="wk">금·토 밤 빈자리:</span> '
               . ($wkList ? rsv_ch(implode(' · ', $wkList)) : '없음') . '</div>';
            echo '</section>';
        }
    } else {
        echo '<section class="cl-page">' . $head('<span>' . $span . '</span> 빈자리') . '<div class="cl-none">이 기간은 아직 기록이 없습니다 — 화면을 열어 두면 캠핏에서 받아 채웁니다(지난 달은 캠핏이 주지 않습니다).</div></section>';
    }

    if (!empty($ST) && $showDates) {
        $pkSites = [];
        foreach (rsv_cal_chunks(['zones' => array_values($zonesU), 'sites' => array_values($sitesU)]) as $grp) foreach ($grp as $g) {
            foreach ($g['sites'] as $st) {
                $code = '';
                foreach ($showDates as $d) {
                    $v = $ST[$st['id']][$d] ?? null;
                    $code .= $v === 'A' ? (isset($NWS[$st['id'] . '|' . $d]) ? 'N' : 'A') : ($v === 'S' ? 'S' : (($v !== null && $until && $d > $until) ? 'O' : 'U'));
                }
                $pkSites[] = ['id' => $st['id'], 'name' => $st['name'], 'zone' => $g['zone'] !== '' ? $g['zone'] : '기타', 's' => $code];
            }
        }
        echo '<script>var RSV_PICK=' . json_encode([
            'dates' => array_map(fn($d) => [$md($d), (int)date('w', strtotime($d)), substr($d, 0, 7), $d], $showDates),
            'sites' => $pkSites, 'sel' => array_values(array_intersect($picks, array_column($pkSites, 'id'))),
        ], JSON_UNESCAPED_UNICODE) . ';</script>';
    }
    echo '<script>var RSV_CSRF=' . json_encode($_SESSION['rsv_csrf']) . ';var RSV_CAL=' . json_encode([
        'id' => $ext, 'name' => $realName, 'until' => $until, 'hasMap' => $mapImg !== '', 'stale' => RSV_CAL_STALE_SEC * 1000, 'months' => $jsMonths,
    ], JSON_UNESCAPED_UNICODE) . ';</script>';
    echo '<script src="/reserve/rsv.js?v=2"></script>';
    echo <<<'JS'
<script>
(function () {
  'use strict';
  var C = RSV_CAL, st = document.getElementById('clSt');
  function say(t, err) { st.textContent = t; st.className = err ? 'err' : ''; }
  /* 묵은 달(또는 기록 없는 달)만 차례로 받아 저장 → 하나라도 저장됐으면 한 번 다시 그린다 */
  function refresh(force) {
    var todo = C.months.filter(function (m) { return RsvSnap.range(m.ym) && (force || !m.checked || Date.now() - m.checked > C.stale); });
    var meta = { name: C.name, until: C.until };
    if (!todo.length) {
      /* 기록은 최신인데 배치도가 아직 없다(배치도 이전에 쌓인 기록) — 캠핏에 배치도가 «있을 때만» 한 달을 다시 저장해 얹는다(없는 캠핑장이면 되풀이하지 않는다) */
      var once = 'rsv_mapfill_' + C.id, tried = false;
      try { tried = sessionStorage.getItem(once) === '1'; sessionStorage.setItem(once, '1'); } catch (e) { tried = true; }
      if (C.hasMap || tried) { say('기록이 최신입니다'); return; }   // 한 세션에 한 번만 — 저장이 어떤 이유로 배치도를 못 얹어도 새로고침이 되풀이되지 않게
      RsvSnap.campMeta(C.id).then(function (cm) {
        var first = C.months.filter(function (m) { return RsvSnap.range(m.ym); })[0];
        if (!cm.map || !first) { say('기록이 최신입니다'); return; }
        C.hasMap = true; first.checked = 0; refresh(false);
      }).catch(function () { say('기록이 최신입니다'); });
      return;
    }
    var saved = 0, i = 0;
    (function next() {
      if (i >= todo.length) {
        if (saved) { say('저장했습니다 — 다시 그리는 중…'); location.reload(); }
        return;
      }
      var m = todo[i++];
      if (i === 1) {   // 첫 달 전에 캠핑장 정보(이름·오픈 한도·배치도)를 한 번 — 실패해도 빈자리는 받는다
        RsvSnap.campMeta(C.id).then(function (cm) { meta = { name: C.name || cm.name, until: C.until || cm.until, map: cm.map, mapFull: cm.mapFull }; }, function () {}).then(go);
      } else go();
      function go() {
      say('캠핏에서 최신으로 받는 중… (' + i + '/' + todo.length + ' · ' + m.ym + ')');
      RsvSnap.fetchMonth(C.id, m.ym).then(function (r) {
        return RsvSnap.save(C.id, m.ym, r.start, r.end, r.data, meta);
      }).then(function (j) {
        if (j && j.ok) saved++; else throw new Error((j && j.msg) || '저장하지 못했습니다');
        next();
      }).catch(function (e) {
        say(e.message + ' — 아래는 마지막 기록입니다.', true);
      });
      }
    })();
  }
  document.getElementById('clRe').onclick = function () { refresh(true); };

  /* ── 내가 고른 사이트 — 칩을 누르면 바로 다시 그리고, 0.6초 뒤 DB 에 저장(PC·폰 공용) ── */
  if (window.RSV_PICK && document.getElementById('pkBox')) (function () {
    var P = RSV_PICK, sel = {}, saveT = null, WD = ['일','월','화','수','목','금','토'];
    P.sel.forEach(function (id) { sel[id] = 1; });
    function esc(t) { return String(t).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    function picker() {
      var html = '<div class="pk-hd"><b>사이트 고르기</b> <span>누르면 아래 「내가 고른 사이트」에 그 사이트의 빈 날만 나옵니다 · 저장돼서 PC·폰 어디서나 같습니다</span>'
               + ' <button type="button" id="pkClr">모두 해제</button></div>', zone = null;
      P.sites.forEach(function (s) {
        if (s.zone !== zone) { if (zone !== null) html += '</div>'; zone = s.zone; html += '<div class="pk-z"><i>' + esc(zone) + '</i>'; }
        var n = (s.s.match(/[AN]/g) || []).length;
        html += '<label class="pk-s' + (sel[s.id] ? ' on' : '') + (n ? '' : ' none') + '" title="빈 날 ' + n + '일"><input type="checkbox" value="' + s.id + '"' + (sel[s.id] ? ' checked' : '') + '>' + esc(s.name) + '<small>' + n + '</small></label>';
      });
      document.getElementById('pkPick').innerHTML = html + '</div>';
    }
    /* 고른 사이트마다 «작은 달력» (2026-10-23 사용자 — 「사이트별 달력을 그려줘 · 작게」 · 한 달력에 배지로 모은 모양은 되돌렸다).
     * 묶음 = 사이트 이름·빈 날 수 + 기간의 달마다 미니 달력(일요일 시작) · 빈 날만 초록(금·토 밤은 진하게) · NEW 는 주황 ·
     * 달은 «기간 전체에서 빈자리가 있는 달» — 사이트끼리 같은 달을 나란히 견준다. */
    function list() {
      var ps = P.sites.filter(function (s) { return sel[s.id]; }), box = document.getElementById('pkBox');
      box.classList.toggle('empty', !ps.length);
      if (!ps.length) { document.getElementById('pkList').innerHTML = '<div class="pk-empty">위에서 사이트를 고르면 사이트마다 빈 날 달력이 그려집니다.</div>'; return; }
      var idx = {}, months = [];
      P.dates.forEach(function (dt, i) { idx[dt[3]] = i; if (months.indexOf(dt[2]) < 0) months.push(dt[2]); });
      var t = new Date(), today = t.getFullYear() + '-' + ('0' + (t.getMonth() + 1)).slice(-2) + '-' + ('0' + t.getDate()).slice(-2);
      var head = '<tr>' + WD.map(function (x, i) { return '<th' + (i === 0 ? ' class="su"' : i >= 5 ? ' class="fs"' : '') + '>' + x + '</th>'; }).join('') + '</tr>';
      var blocks = ps.map(function (s) {
        var total = (s.s.match(/[AN]/g) || []).length;
        var cals = months.map(function (ym) {
          var y = +ym.slice(0, 4), m = +ym.slice(5, 7), lead = new Date(y, m - 1, 1).getDay(), nd = new Date(y, m, 0).getDate(), days = 0, h = head;
          for (var c = 0; c < Math.ceil((lead + nd) / 7) * 7; c++) {
            if (c % 7 === 0) h += '<tr>';
            var dn = c - lead + 1;
            if (dn < 1 || dn > nd) h += '<td class="e"></td>';
            else {
              var d = ym + '-' + ('0' + dn).slice(-2), i = idx[d], code = i === undefined ? '' : s.s.charAt(i), w = c % 7;
              if (code === 'A' || code === 'N') { days++; h += '<td class="v' + (w === 5 || w === 6 ? ' wk' : '') + (code === 'N' ? ' nw' : '') + '">' + dn + '</td>'; }
              else h += '<td class="x' + (d < today ? ' p' : '') + '">' + dn + '</td>';
            }
            if (c % 7 === 6) h += '</tr>';
          }
          return '<div class="psc-m"><div class="psc-mt">' + m + '월 <span>' + days + '일</span></div><table>' + h + '</table></div>';
        }).join('');
        return '<div class="psc"><div class="psc-h"><b>' + esc(s.name) + '</b><span>빈 날 ' + total + '일</span></div><div class="psc-ms">' + cals + '</div></div>';
      }).join('');
      document.getElementById('pkList').innerHTML = '<h2 class="cl-h2">내가 고른 사이트 빈자리 <small>(초록 = 빈 날 · 진한 초록 = 금·토 밤 · 주황 = NEW)</small></h2><div class="psc-wrap">' + blocks + '</div>';
    }
    function save() {
      clearTimeout(saveT);
      saveT = setTimeout(function () {
        var fd = new FormData();
        fd.append('action', 'pick_save'); fd.append('csrf', RSV_CSRF); fd.append('ext_id', RSV_CAL.id);
        fd.append('sites', Object.keys(sel).join(','));
        fetch('/reserve/api.php', { method: 'POST', body: fd }).catch(function () {});
      }, 600);
    }
    document.getElementById('pkPick').addEventListener('change', function (e) {
      var cb = e.target; if (!cb || cb.type !== 'checkbox') return;
      if (cb.checked) sel[cb.value] = 1; else delete sel[cb.value];
      cb.parentNode.classList.toggle('on', cb.checked);
      list(); save();
    });
    document.getElementById('pkPick').addEventListener('click', function (e) {
      if (e.target.id !== 'pkClr') return;
      sel = {}; picker(); list(); save();
    });
    picker(); list();
  })();
  refresh(false);
})();
</script>
JS;
    echo '</body></html>';
}
?>
