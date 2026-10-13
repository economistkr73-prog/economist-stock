<?php
// place_list.php — 📋 장소 목록 문서 (여행지도 좌측 리스트를 «읽는 문서»로)
//   ?t=<토큰>          화면(인쇄 = PDF 로 저장)
//   ?t=<토큰>&fmt=txt  텍스트 파일 내려받기
//   로그인 없이 링크만으로 읽는다 — 문지기는 토큰 하나(Place::listShareGet · 만료되면 410).
//   링크 발급은 place_api.php?action=list_share(소유자 전용) · 데이터는 Place::listRows().
//   ★문서는 «어느 장소를 어떤 순서로»만 기억한다 — 이름·주소·Claude 리뷰는 열 때마다 place 에서 읽는다.
ob_start();   // include 파일이 흘린 출력이 내려받기 헤더를 깨지 않게(place_api.php 와 같은 가드)
require_once "./env/cnt.inc";
ob_clean();

$place = new Place($pdo);
$token = trim((string)($_GET['t'] ?? ''));
$doc   = $place->listShareGet($token);
if ($doc === null) {
    http_response_code(410);
    echo "<!doctype html><html lang='ko'><meta charset='utf-8'><meta name='viewport' content='width=device-width, initial-scale=1'>"
       . "<div style='font-family:sans-serif;padding:48px 24px;text-align:center;color:#444'>"
       . "<h2 style='margin-bottom:8px'>🔗 만료되었거나 잘못된 링크입니다</h2>"
       . "<p style='color:#888'>목록을 보낸 사람에게 새 링크를 요청하세요.</p></div></html>";
    exit;
}

$rows  = $place->listRows($doc['ids']);
$title = $doc['title'] !== '' ? $doc['title'] : '장소 목록';
$made  = substr((string)$doc['created_at'], 0, 10);
$until = substr((string)$doc['expires_at'], 0, 10);
$CAT   = ['travel' => '여행지', 'stay' => '숙소', 'restaurant' => '맛집', 'camping' => '캠핑장', 'etc' => '기타'];

// 한 장소 → 문서에 쓸 값(화면·텍스트가 같은 값을 쓴다 — 두 번 풀지 않는다)
function pll_item(array $r, array $CAT): array
{
    $at = $r['attributes'];
    $nv = is_array($at['naver'] ?? null) ? $at['naver'] : [];
    $guides = [];
    foreach ($r['guides'] as $g) {
        $guides[] = FoodGuide::ko((string)$g['guide']) . (($g['grade'] ?? '') !== '' && $g['grade'] !== null ? ' ' . $g['grade'] : '');
    }
    $feats = [];
    foreach ((array)($at['features'] ?? []) as $f) { if (is_string($f) && trim($f) !== '') $feats[] = trim($f); }
    $nvParts = [];
    if (!empty($nv['score']))  $nvParts[] = '★ ' . $nv['score'];
    if (!empty($nv['review'])) $nvParts[] = '리뷰 ' . number_format((float)$nv['review']);
    if (!empty($nv['save']))   $nvParts[] = '저장 ' . $nv['save'];
    $url = (string)($nv['url'] ?? '');
    if (!preg_match('#^https?://#i', $url)) $url = 'https://map.naver.com/p/search/' . rawurlencode($r['name']);
    return [
        'name'    => $r['name'],
        'cat'     => $CAT[$r['category']] ?? '기타',
        'catKey'  => isset($CAT[$r['category']]) ? $r['category'] : 'etc',
        'address' => trim($r['address']),
        'phone'   => trim($r['phone']),
        'guides'  => $guides,
        'naver'   => implode(' · ', $nvParts),
        'micro'   => trim((string)($nv['micro'] ?? '')),
        'tags'    => $r['tags'],
        'summary' => trim((string)($at['summary'] ?? '')),
        'feats'   => $feats,
        'url'     => $url,
    ];
}
$items = [];
foreach ($rows as $r) $items[] = pll_item($r, $CAT);
$withSum = count(array_filter($items, fn($it) => $it['summary'] !== ''));

// ── 텍스트 파일 ──────────────────────────────────────────────
if (($_GET['fmt'] ?? '') === 'txt') {
    $L = [];
    $L[] = $title;
    $L[] = count($items) . '곳 · ' . $made . ' 만듦';
    $L[] = str_repeat('=', 48);
    foreach ($items as $i => $it) {
        $L[] = '';
        $L[] = ($i + 1) . '. ' . $it['name'] . '  [' . $it['cat'] . ']';
        if ($it['guides'])          $L[] = '   가이드: ' . implode(', ', $it['guides']);
        $L[] = '   주소: ' . ($it['address'] !== '' ? $it['address'] : '(주소 없음)');
        if ($it['phone'] !== '')    $L[] = '   전화: ' . $it['phone'];
        if ($it['naver'] !== '')    $L[] = '   네이버: ' . $it['naver'];
        if ($it['tags'])            $L[] = '   태그: #' . implode(' #', $it['tags']);
        if ($it['summary'] !== '') {
            $L[] = '   Claude 리뷰:';
            $L[] = '   ' . preg_replace('/\s*\R\s*/u', "\r\n   ", $it['summary']);
        } else {
            $L[] = '   Claude 리뷰: (아직 없음)';
        }
        if ($it['feats'])           $L[] = '   특징: ' . implode(', ', $it['feats']);
        if ($it['micro'] !== '')    $L[] = '   한줄평: "' . $it['micro'] . '"';
        $L[] = '   지도: ' . $it['url'];
        $L[] = str_repeat('-', 48);
    }
    $fn = preg_replace('/[\\\\\/:*?"<>|\s]+/u', '_', $title) . '_' . $made . '.txt';
    header('Content-Type: text/plain; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"place_list.txt\"; filename*=UTF-8''" . rawurlencode($fn));
    header('X-Robots-Tag: noindex');
    echo "\xEF\xBB\xBF" . implode("\r\n", $L) . "\r\n";   // BOM — 윈도 메모장에서 한글이 깨지지 않게
    exit;
}

// ── 화면 ────────────────────────────────────────────────────
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$txtUrl = 'place_list.php?t=' . $token . '&fmt=txt';
$desc = count($items) . '곳 · 이름·주소·Claude 리뷰';

$body = '';
foreach ($items as $i => $it) {
    $meta = '';
    if ($it['guides']) {
        foreach ($it['guides'] as $g) $meta .= '<span class="gd">' . $e($g) . '</span>';
    }
    $tags = '';
    foreach ($it['tags'] as $t) $tags .= '<span class="tg">#' . $e($t) . '</span>';
    $feats = '';
    foreach ($it['feats'] as $f) $feats .= '<span class="ft">' . $e($f) . '</span>';

    $body .= '<article class="it">'
        . '<header><span class="no c-' . $it['catKey'] . '">' . ($i + 1) . '</span>'
        . '<h2>' . $e($it['name']) . '</h2><span class="cat c-' . $it['catKey'] . '">' . $e($it['cat']) . '</span>' . $meta . '</header>'
        . '<dl>'
        . '<dt>주소</dt><dd>' . ($it['address'] !== '' ? $e($it['address']) : '<i class="na">주소 없음</i>') . '</dd>'
        . ($it['phone'] !== '' ? '<dt>전화</dt><dd><a href="tel:' . $e(preg_replace('/[^0-9+]/', '', $it['phone'])) . '">' . $e($it['phone']) . '</a></dd>' : '')
        . ($it['naver'] !== '' ? '<dt>네이버</dt><dd>' . $e($it['naver']) . '</dd>' : '')
        . '</dl>'
        . ($tags !== '' ? '<div class="tags">' . $tags . '</div>' : '')
        . ($it['summary'] !== ''
            ? '<section class="rv"><b class="rv-hd">Claude 리뷰</b><p>' . nl2br($e($it['summary'])) . '</p>'
              . ($feats !== '' ? '<div class="fts">' . $feats . '</div>' : '') . '</section>'
            : '<section class="rv none">Claude 리뷰가 아직 없습니다</section>')
        . ($it['micro'] !== '' ? '<p class="micro">“' . $e($it['micro']) . '”</p>' : '')
        . '<a class="map" href="' . $e($it['url']) . '" target="_blank" rel="noopener">네이버 지도에서 보기 ↗</a>'
        . '</article>';
}
if ($body === '') $body = '<p class="empty">목록의 장소가 지금은 남아 있지 않습니다.</p>';

$html = <<<HTML
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>{$e($title)}</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta property="og:title" content="{$e($title)}">
<meta property="og:description" content="{$e($desc)}">
<meta property="og:type" content="article">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Pretendard','Malgun Gothic','Apple SD Gothic Neo',sans-serif; background: #f0f2f5; color: #2c3e50; line-height: 1.6; -webkit-text-size-adjust: 100%; }
.wrap { max-width: 800px; margin: 0 auto; padding: 0 16px 60px; }
.bar { position: sticky; top: 0; z-index: 5; background: #fff; box-shadow: 0 1px 4px rgba(0,0,0,.1); }
.bar-in { max-width: 800px; margin: 0 auto; padding: 10px 16px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.bar button, .bar a { border: 1px solid #cdd4da; background: #fff; color: #2c3e50; font: inherit; font-size: 14px; font-weight: 600; padding: 8px 14px; border-radius: 6px; cursor: pointer; text-decoration: none; white-space: nowrap; }
.bar .pri { background: #3498db; border-color: #2980b9; color: #fff; }
.bar .msg { font-size: 13px; color: #27ae60; font-weight: 600; }
.doc-hd { padding: 28px 0 18px; }
.doc-hd h1 { font-size: 24px; line-height: 1.35; word-break: keep-all; }
.doc-hd p { margin-top: 6px; font-size: 13px; color: #7f8c8d; }
.it { background: #fff; border-radius: 10px; padding: 18px 20px; margin-bottom: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.06); break-inside: avoid; page-break-inside: avoid; }
.it header { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 10px; }
.it h2 { font-size: 18px; line-height: 1.35; word-break: keep-all; }
.no { flex-shrink: 0; min-width: 28px; height: 28px; padding: 0 6px; border-radius: 14px; border: 2px solid #3498db; color: #c0392b; font-size: 13px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; }
.cat { font-size: 11px; font-weight: 700; color: #fff; padding: 2px 8px; border-radius: 10px; background: #7f8c8d; }
.no.c-travel { border-color: #3498db; } .cat.c-travel { background: #3498db; }
.no.c-restaurant { border-color: #e74c3c; } .cat.c-restaurant { background: #e74c3c; }
.no.c-stay { border-color: #8e44ad; } .cat.c-stay { background: #8e44ad; }
.no.c-camping { border-color: #27ae60; } .cat.c-camping { background: #27ae60; }
.no.c-etc { border-color: #7f8c8d; }
.gd { font-size: 11px; font-weight: 700; color: #1f3a93; border: 1px solid #1f3a93; padding: 1px 7px; border-radius: 10px; }
dl { display: grid; grid-template-columns: 52px 1fr; gap: 3px 8px; font-size: 14.5px; }
dt { color: #95a5a6; font-size: 13px; padding-top: 1px; }
dd a { color: inherit; }
.na { color: #b0b8bf; font-style: normal; }
.tags { margin-top: 8px; display: flex; flex-wrap: wrap; gap: 4px 10px; }
.tg { font-size: 13px; color: #2980b9; }
.rv { margin-top: 12px; padding: 12px 14px; background: #f3f0fb; border: 1px solid #e0d7f5; border-left: 3px solid #7c5cd6; border-radius: 8px; font-size: 15px; line-height: 1.75; color: #3a3550; }
.rv-hd { display: inline-block; font-size: 11px; color: #fff; background: #7c5cd6; padding: 2px 9px; border-radius: 10px; margin-bottom: 6px; }
.rv p { word-break: keep-all; }
.rv.none { background: #f7f8f9; border-color: #e6e9ec; border-left-color: #cdd4da; color: #a0a8af; font-size: 13px; }
.fts { margin-top: 8px; display: flex; flex-wrap: wrap; gap: 5px; }
.ft { font-size: 12px; background: #fff; border: 1px solid #d9cff3; color: #5b45a8; padding: 2px 9px; border-radius: 11px; }
.micro { margin-top: 8px; font-size: 13.5px; color: #6b7783; }
.map { display: inline-block; margin-top: 10px; font-size: 13px; color: #03a64c; font-weight: 600; text-decoration: none; }
.empty { padding: 40px 0; text-align: center; color: #95a5a6; }
.foot { margin-top: 22px; font-size: 12px; color: #a0a8af; text-align: center; }
@media (max-width: 560px) {
    .doc-hd h1 { font-size: 20px; }
    .it { padding: 15px 15px; }
    .bar button, .bar a { padding: 8px 11px; font-size: 13px; }
}
@page { margin: 14mm 12mm; }
@media print {
    body { background: #fff; }
    .bar, .map { display: none; }
    .wrap { max-width: none; padding: 0; }
    .doc-hd { padding-top: 0; }
    .it { box-shadow: none; border: 1px solid #dfe4e8; margin-bottom: 9px; padding: 12px 14px; }
    .rv, .cat, .rv-hd, .no { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
</head>
<body>
<div class="bar"><div class="bar-in">
    <button class="pri" onclick="window.print()">PDF 로 저장 · 인쇄</button>
    <a href="{$e($txtUrl)}">TXT 내려받기</a>
    <button onclick="pllCopy()">링크 복사</button>
    <span class="msg" id="pllMsg"></span>
</div></div>
<div class="wrap">
    <div class="doc-hd">
        <h1>{$e($title)}</h1>
        <p>{$e(count($items))}곳 · Claude 리뷰 {$e($withSum)}곳 · {$e($made)} 만듦 · 이 링크는 {$e($until)} 까지 열립니다</p>
    </div>
    {$body}
    <p class="foot">Claude 리뷰는 기사·리뷰를 AI 가 간추린 글입니다 — 영업시간·가격은 가기 전에 확인하세요.</p>
</div>
<script>
function pllCopy() {
    var u = location.href.split('#')[0], m = document.getElementById('pllMsg');
    var ok = function () { m.textContent = '링크를 복사했습니다'; setTimeout(function () { m.textContent = ''; }, 2500); };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(u).then(ok, function () { window.prompt('링크 (Ctrl+C 로 복사)', u); });
    else window.prompt('링크 (Ctrl+C 로 복사)', u);
}
</script>
</body>
</html>
HTML;
header('X-Robots-Tag: noindex');
echo $html;
?>
