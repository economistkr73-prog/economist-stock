<?php
/**
 * market/report.php — 스냅샷 → 전일대비 → 이상치탐지 → Claude 브리핑 → HTML 리포트
 *
 * 실행:
 *   CLI : php market/report.php [YYYY-MM-DD]
 *   WEB : /market/report.php?key=econ-mkt-7x3k[&date=YYYY-MM-DD]
 *
 * daily-brief-mockup.html 디자인을 서버 사이드 정적 HTML 로 렌더한다.
 * Claude 브리핑은 ANTHROPIC_API_KEY 가 있을 때만 호출하고, 실패 시 이상치 텍스트로 폴백한다.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/db.php';

/**
 * 리포트 생성 오케스트레이터 (report.php 직접 실행·crawl.php &report=1 공용)
 * @return array|null ['html','brief','anom','raw'] · 스냅샷 없으면 null
 */
function mkt_generate_report(string $date, array $opt = []): ?array {
    $snap = mkt_load_snap($date);
    if (!$snap) return null;
    $prev  = ($p = mkt_prev_date($date)) ? mkt_load_snap($p) : null;
    $anom  = mkt_detect_anomalies($snap);
    // 브리핑: 저장된 게 있으면 재사용(Claude 호출 안 함). rebrief=true(데이터 갱신·강제) 면 새로 생성.
    $brief = ($opt['rebrief'] ?? false) ? null : mkt_load_brief($date);
    $briefCached = $brief !== null;
    if (!$brief) { $brief = mkt_gen_brief($snap, $anom); if ($opt['save'] ?? true) mkt_save_brief($date, $brief); }
    $brief = mkt_brief_normalize($brief);   // 원소가 객체여도 렌더는 항상 문자열만 받게
    $html  = mkt_render($snap, $prev, $anom, $brief);
    // 완전한 HTML 일 때만 캐시(렌더 중단 시 잘린 결과 저장 방지)
    if (($opt['save'] ?? true) && str_contains($html, '</html>')) mkt_save_html($date, $html);
    if (!empty($opt['notify'])) {
        require_once __DIR__ . '/../classes/Notify.class';
        require_once __DIR__ . '/../classes/PushoverNotify.class';
        $viewUrl = 'https://economist.kr/market/report.php?key=' . MKT_KEY . '&date=' . $date;
        Notify::send("📊 {$date} 모닝브리핑\n" . ($brief['head'] ?? '모닝브리핑'),
            $viewUrl, ['title' => '모닝브리핑']);
    }
    return ['html' => $html, 'brief' => $brief, 'anom' => $anom, 'raw' => $GLOBALS['mkt_brief_raw'] ?? '', 'briefCached' => $briefCached];
}

// crawl.php 가 라이브러리로 include 할 땐(MKT_LIB_ONLY) 아래 웹/CLI 엔트리를 건너뛴다.
if (!defined('MKT_LIB_ONLY')) {
    $cli = (PHP_SAPI === 'cli');
    // 인증: 크론·외부 호출은 ?key=MKT_KEY, 사람(헤더 메뉴 클릭)은 로그인 세션 허용
    if (!$cli && ($_GET['key'] ?? '') !== MKT_KEY) {
        require_once __DIR__ . '/../env/auth_fnc.php';
        require_login();   // 미로그인 시 /lg.php 리다이렉트
        $GLOBALS['current_user'] = $_SESSION['usr_name'] ?? '';   // 공통 헤더(nav)에 사용자명 표시
    }

    $date  = $cli ? ($argv[1] ?? '') : ($_GET['date'] ?? '');
    if ($date === '') $date = mkt_latest_date() ?? date('Y-m-d');   // 기본 = 최신 거래일 스냅샷
    $debug   = !$cli && isset($_GET['briefdebug']);
    $rebrief = isset($_GET['rebrief']) || $debug || ($cli && in_array('--rebrief', $argv ?? [], true));  // Claude 새로 호출
    $force   = isset($_GET['force']) || $rebrief || ($cli && in_array('--force', $argv ?? [], true));     // HTML 재생성(브리핑은 캐시 재사용)

    // 생성된 리포트가 DB에 있으면 즉시 서빙(웹). 재생성은 ?force=1·briefdebug 또는 크론에서만.
    if (!$cli && !$force && ($cachedHtml = mkt_load_html($date)) !== null) {
        header('Content-Type: text/html; charset=utf-8');
        echo mkt_inject_nav($cachedHtml);
        exit;
    }
    if (!mkt_load_snap($date)) {
        $msg = "no snapshot for {$date} — 먼저 crawl.php 를 실행하세요.";
        if ($cli) exit($msg . "\n");
        http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit($msg);
    }
    if ($debug) {
        $r = mkt_generate_report($date, ['save' => false, 'notify' => false, 'rebrief' => true]);
        header('Content-Type: text/plain; charset=utf-8');
        echo "head: " . ($r['brief']['head'] ?? '') . "\n";
        echo "fallback? " . (($r['brief']['head'] ?? '') === '자동 브리핑(이상치 요약)' ? 'YES (Claude 파싱실패)' : 'NO (Claude 정상)') . "\n\n";
        echo "=== Claude 원문 ===\n" . ($r['raw'] ?: '(없음)') . "\n";
        exit;
    }
    $r = mkt_generate_report($date, [
        'notify'  => isset($_GET['notify']) || ($cli && in_array('--notify', $argv ?? [], true)),
        'rebrief' => $rebrief,
    ]);
    if ($cli) {
        echo "report ready: market_snapshot[{$date}].html ({$date})\n";
        echo "  brief: " . (($r['briefCached'] ?? false) ? '캐시 재사용(Claude 미호출)' : 'Claude 새로 생성') . "\n";
        echo "  brief.head: " . ($r['brief']['head'] ?? '-') . "\n  anomalies: " . count($r['anom']) . "\n";
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo mkt_inject_nav($r['html']);
    }
}

/* 스냅샷 로드(mkt_load_snap)·전일자(mkt_prev_date)·HTML 캐시는 db.php 로 이동 */

/* ════════════════════════ 이상치 탐지 (spec 4장) ════════════════════════ */

function mkt_detect_anomalies(array $d): array {
    $out = [];
    $cfg = ['jumpPrice' => 0.015, 'jumpFx' => 0.010, 'jumpBp' => 10, 'extreme' => 0.50, 'credit' => 400, 'oil' => -0.20];

    $miss = $d['integrity']['missingCount'] ?? 0;
    if ($miss > 0)  $out[] = ['code' => 'MISSING', 'level' => 'warn', 'text' => "미수집 {$miss}건 — 데이터 누락(하락 아님)"];
    $dum = $d['integrity']['dummyCount'] ?? 0;
    if ($dum > 0)   $out[] = ['code' => 'DUMMY', 'level' => 'info', 'text' => "더미(±1.00) {$dum}건 — 전일비 검증 필요"];

    $prices = array_merge($d['indices'] ?? [], $d['indicesKr'] ?? [], $d['fx'] ?? [], $d['commodities'] ?? []);
    foreach ($prices as $i) {
        if (($i['quality'] ?? '') !== 'ok') continue;
        $y1 = $i['ctx']['y1'] ?? null;
        $m6 = $i['ctx']['m6'] ?? null;
        $m1 = $i['ctx']['m1'] ?? null;
        $pct = $i['day']['pct'] ?? null;
        if ($y1 !== null && abs($y1) >= $cfg['extreme'])
            $out[] = ['code' => 'EXTREME', 'level' => 'info', 'text' => "{$i['name']} 1년 " . mkt_signpct($y1)];
        if ($m6 !== null && $m1 !== null && $m6 >= 0.10 && $m1 < 0)
            $out[] = ['code' => 'MOMENTUM', 'level' => 'info', 'text' => "{$i['name']} 6개월 강세나 최근 1개월 반락(" . mkt_signpct($m1) . ")"];
        if ($pct !== null && abs($pct) >= $cfg['jumpPrice'] && ($i['type'] ?? '') === 'price')
            $out[] = ['code' => 'DAY_JUMP', 'level' => 'warn', 'text' => "{$i['name']} 당일 급변동 " . mkt_signpct($pct)];
    }
    // 유가 6개월 약세
    foreach (($d['commodities'] ?? []) as $i) {
        if (in_array($i['id'] ?? '', ['wti', 'brent', 'dubai', 'gasoil'], true) && ($i['ctx']['m6'] ?? null) !== null && $i['ctx']['m6'] <= $cfg['oil'])
            $out[] = ['code' => 'OIL', 'level' => 'info', 'text' => "{$i['name']} 6개월 약세 " . mkt_signpct($i['ctx']['m6'])];
    }
    // 금리 급변동 + 커브
    foreach (($d['bonds'] ?? []) as $b) {
        $bp = $b['day']['bp'] ?? null;
        if (($b['quality'] ?? '') === 'ok' && $bp !== null && abs($bp) >= $cfg['jumpBp'])
            $out[] = ['code' => 'DAY_JUMP', 'level' => 'warn', 'text' => "{$b['name']} 금리 급변동 " . sprintf('%+.0fbp', $bp)];
    }
    $sp = $d['bondSpreads'] ?? [];
    if (($sp['kr10y_kr1y'] ?? null) !== null && $sp['kr10y_kr1y'] < 0)
        $out[] = ['code' => 'CURVE_INVERT', 'level' => 'warn', 'text' => "수익률곡선 역전 10Y−1Y {$sp['kr10y_kr1y']}bp"];
    if (($sp['kr30y_kr20y'] ?? null) !== null && abs($sp['kr30y_kr20y']) < 2)
        $out[] = ['code' => 'CURVE_FLAT', 'level' => 'info', 'text' => "초장기 평탄화 30Y−20Y {$sp['kr30y_kr20y']}bp"];
    if (($sp['creditBBBm_kr3y'] ?? null) !== null && $sp['creditBBBm_kr3y'] >= $cfg['credit'])
        $out[] = ['code' => 'CREDIT', 'level' => 'warn', 'text' => "신용스프레드 과대 BBB−−회사채3년 {$sp['creditBBBm_kr3y']}bp"];
    // 외국인 순매도
    $fk = $d['investors']['kospi']['foreign'] ?? null;
    if ($fk !== null && $fk < 0)
        $out[] = ['code' => 'FLOW_FOREIGN', 'level' => 'info', 'text' => "외국인 코스피 순매도 " . number_format($fk) . "억"];

    return $out;
}

/* ════════════════════════ Claude 브리핑 ════════════════════════ */

function mkt_api_key(): string {
    // schedule_api.php(음성 해석)와 동일한 키 로딩 — 기존에 등록된 env/anthropic.inc 재사용
    $inc = __DIR__ . '/../env/anthropic.inc';
    if (is_file($inc)) require_once $inc;
    return getenv('ANTHROPIC_API_KEY') ?: (defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '');
}

function mkt_brief_input(array $d, array $anom): string {
    $L = [];
    // ★시간 순서 선언 — 이걸 안 주면 Claude가 "뉴욕 반등에도 코스피 하락" 식으로 인과를 뒤집는다.
    //   실제 순서: 국내 전일 15:30 마감 → (간밤) 뉴욕 오늘 새벽 마감 → 오늘 아침 이 브리핑 작성.
    $L[] = "[시간 순서] 이 브리핑은 오늘 아침 작성한다. 아래 '국내' 수치는 전일({$d['date']}) 15:30에 마감한 결과이고, "
        . "'해외' 지수와 뉴욕 뉴스는 그 이후 간밤(오늘 새벽)에 마감한 결과다. "
        . "즉 국내 마감이 먼저이고 뉴욕 마감이 나중이므로, 간밤 뉴욕 흐름은 전일 국내 장에 영향을 줄 수 없었고 오늘 국내 장의 재료다.";
    foreach (($d['indicesKr'] ?? []) as $i)
        if (($i['quality'] ?? '') === 'ok') $L[] = "국내 {$i['name']} {$i['close']} (" . mkt_signpct($i['day']['pct']) . ")";
    $inv = $d['investors']['kospi'] ?? null;
    if ($inv) $L[] = "코스피 투자자 순매수(억): 개인 {$inv['individual']} / 외국인 {$inv['foreign']} / 기관 {$inv['institution']}";
    foreach (($d['indices'] ?? []) as $i)
        if (($i['quality'] ?? '') === 'ok') $L[] = "해외 {$i['name']} " . mkt_signpct($i['day']['pct']);
    foreach (($d['commodities'] ?? []) as $i)
        if (($i['quality'] ?? '') === 'ok' && in_array($i['id'], ['wti', 'gold', 'brent', 'copper'], true))
            $L[] = "원자재 {$i['name']} " . mkt_signpct($i['day']['pct']);
    foreach (($d['bonds'] ?? []) as $b)
        if (($b['quality'] ?? '') === 'ok' && in_array($b['id'], ['kr3y', 'kr10y', 'corpBBBm', 'us10y'], true))
            $L[] = "금리 {$b['name']} {$b['level']}% (" . sprintf('%+.0fbp', $b['day']['bp'] ?? 0) . ")";
    $sp = $d['bondSpreads'] ?? [];
    if (($sp['kr10y_kr1y'] ?? null) !== null) $L[] = "커브 10Y−1Y {$sp['kr10y_kr1y']}bp, 신용 BBB−스프레드 " . ($sp['creditBBBm_kr3y'] ?? '?') . "bp";
    if ($anom) $L[] = "이상치: " . implode(' / ', array_map(fn($a) => $a['text'], $anom));
    $news = $d['news'] ?? [];
    if ($news) {
        $heads = array_map(fn($a) => "- {$a['title']}" . ($a['summary'] !== '' ? " ({$a['summary']})" : ''), array_slice($news, 0, 8));
        $L[] = "국내 시황 뉴스 헤드라인:\n" . implode("\n", $heads);
    }
    $us = $d['newsUs'] ?? [];
    if ($us) {
        $heads = array_map(fn($a) => "- {$a['title']}", array_slice($us, 0, 5));
        $L[] = "뉴욕·해외 증시 뉴스 헤드라인:\n" . implode("\n", $heads);
    }
    return implode("\n", $L);
}

/**
 * Claude가 change/watch 원소를 {item,desc} 객체로 반환하는 날이 있다(스키마 미고정 시 재량).
 * strict_types 하에서 h(?string)에 배열이 들어가면 TypeError → 렌더 중단이므로 항상 문자열로 정규화한다.
 */
function mkt_brief_normalize(array $b): array {
    foreach (['change', 'watch'] as $k) {
        $b[$k] = array_values(array_map(function ($x) {
            if (is_array($x)) {
                $item = trim((string) ($x['item'] ?? ''));
                $desc = trim((string) ($x['desc'] ?? ''));
                if ($item !== '' && $desc !== '') return "{$item} — {$desc}";
                return $desc !== '' ? $desc : ($item !== '' ? $item : json_encode($x, JSON_UNESCAPED_UNICODE));
            }
            return (string) $x;
        }, (array) ($b[$k] ?? [])));
    }
    if (!is_string($b['head'] ?? '')) $b['head'] = json_encode($b['head'] ?? '', JSON_UNESCAPED_UNICODE);
    return $b;
}

function mkt_gen_brief(array $d, array $anom): array {
    $fallback = [
        'head'   => '자동 브리핑(이상치 요약)',
        'change' => array_values(array_map(fn($i) => "{$i['name']} " . mkt_signpct($i['day']['pct']), array_filter($d['indicesKr'] ?? [], fn($i) => ($i['quality'] ?? '') === 'ok'))),
        'watch'  => array_map(fn($a) => $a['text'], array_slice($anom, 0, 5)),
    ];
    $key = mkt_api_key();
    if (!$key) return $fallback;

    $sys = "너는 개인 투자자용 데스크다. 주어진 스냅샷·이상치·주요 뉴스 헤드라인을 근거로 한국어 데일리 브리핑을 쓴다. "
        . "반드시 JSON만 출력(코드펜스·앞뒤 설명 금지): {\"head\":\"문장\",\"change\":[\"문장\",\"문장\",\"문장\"],\"watch\":[\"문장\",\"문장\",\"문장\"]}. "
        . "change·watch 원소는 객체({item,desc} 등)가 아니라 순수 문자열. "
        . "head 1문장. change 정확히 3개, watch 정확히 3개. 각 항목은 한 문장(공백포함 50자 이내)·핵심 수치 1~2개만, 장황 금지. "
        . "당일 등락은 전일 종가 대비 위주. 채권 금리 상승=가격 하락으로 해석. 뉴스는 수치 해석의 맥락으로만 활용(헤드라인 나열 금지). "
        . "[시간 순서 엄수] 국내 증시가 먼저 마감했고 뉴욕은 그 뒤 간밤에 마감했다. "
        . "'뉴욕 상승에도 코스피 하락'처럼 뉴욕 결과가 국내 마감보다 먼저 있었던 듯한 역인과 서술 금지. "
        . "간밤 뉴욕은 '코스피 하락 마감 후 뉴욕은 반등' 식으로 순서대로 쓰거나 오늘 국내 장의 관전 재료로 서술. "
        . "미수집은 누락(하락 아님)이라고 watch에 명시. 과장·투자권유 금지.";
    $payload = [
        'model' => MKT_MODEL, 'max_tokens' => 1500, 'system' => $sys,
        'messages' => [['role' => 'user', 'content' => mkt_brief_input($d, $anom)]],
    ];
    $body = mkt_http('https://api.anthropic.com/v1/messages', [
        'timeout' => 40,
        'headers' => [
            'Content-Type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ],
        'post' => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    if ($body === null) { $GLOBALS['mkt_brief_raw'] = '(HTTP 실패: null)'; return $fallback; }
    $res = json_decode($body, true);
    $text = '';
    foreach (($res['content'] ?? []) as $b) if (($b['type'] ?? '') === 'text') $text .= $b['text'];
    $GLOBALS['mkt_brief_raw'] = $text !== '' ? $text : ('(content 없음) ' . substr($body, 0, 300));
    // ```펜스 제거 후 첫 { ~ 마지막 } 만 추출(앞뒤 설명문 대응)
    $clean = preg_replace('/```(json)?/i', '', $text);
    if (preg_match('/\{.*\}/s', $clean, $mm)) $clean = $mm[0];
    $json = json_decode(trim($clean), true);
    return (is_array($json) && isset($json['head'])) ? $json : $fallback;
}

/* ════════════════════════ 포맷 헬퍼 ════════════════════════ */

function mkt_cls(?string $dir): string { return $dir === 'up' ? 'up' : ($dir === 'down' ? 'down' : 'flat'); }
function mkt_signpct(?float $f): string { return $f === null ? '—' : sprintf('%+.2f%%', $f * 100); }
function mkt_jo(?float $eok): string { return $eok === null ? '—' : number_format($eok / 10000, 1) . '조'; }
function mkt_eok(?float $v): string { return $v === null ? '—' : number_format($v); }
function mkt_arrow(?string $dir): string { return $dir === 'up' ? '▲' : ($dir === 'down' ? '▼' : '·'); }
function h(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

/**
 * 서빙 시점에 공통 상단 네비게이션(env/nav.inc)을 HTML 문자열에 주입한다.
 * 캐시 HTML에는 nav를 굽지 않고(메뉴 변경 자동반영·로그인 사용자명 정확) 출력 직전에만 삽입한다.
 */
function mkt_inject_nav(string $html): string {
    $navInc = __DIR__ . '/../env/nav.inc';
    if (!is_file($navInc)) return $html;
    require_once $navInc;
    if (!function_exists('nav_css') || !function_exists('render_nav')) return $html;
    ob_start(); nav_css();                 $css = ob_get_clean();
    ob_start(); render_nav('morningbrief'); $nav = ob_get_clean();
    // CSS는 </head> 직전, 네비 바는 <body> 직후에 1회 삽입
    $html = preg_replace('/<\/head>/i', $css . '</head>', $html, 1);
    $html = preg_replace('/<body[^>]*>/i', '$0' . addcslashes($nav, '\\$'), $html, 1);
    return $html;
}

/* ════════════════════════ HTML 렌더 ════════════════════════ */

function mkt_render(array $d, ?array $prev, array $anom, array $brief): string {
    $css = mkt_css();
    $asofKr = $d['asof']['indicesKr'] ?? $d['date'];
    $integ  = $d['integrity'] ?? ['missingCount' => 0, 'dummyCount' => 0];
    $ok = ($integ['missingCount'] == 0 && $integ['dummyCount'] == 0);

    ob_start(); ?>
<!DOCTYPE html><html lang="ko"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>모닝브리핑 · <?=h($d['date'])?></title>
<?=$css?>
</head><body><div class="db"><div class="wrap">

  <header class="head-row" style="margin-bottom:6px;">
    <div>
      <div class="eyebrow" style="margin-bottom:6px;">시장동향 · 자동 생성</div>
      <h1 style="margin:0;font-size:30px;font-weight:800;letter-spacing:-.02em;">모닝브리핑</h1>
      <div class="mono" style="font-size:12px;color:var(--muted);margin-top:4px;"><?=h($d['date'])?> · 출처 한경 데이터센터 · 네이버 증권</div>
    </div>
    <div style="text-align:right;border:1px solid var(--line);border-radius:10px;padding:8px 11px;min-width:120px;">
      <div style="font-size:9.5px;letter-spacing:.12em;color:var(--faint);text-transform:uppercase;">데이터 정합성</div>
      <div class="mono" style="font-size:20px;color:<?=$ok?'var(--ok)':'var(--amber)'?>;font-weight:700;margin-top:2px;"><?=$ok?'정상':'주의'?></div>
      <div style="font-size:9.5px;color:var(--faint);">미수집 <?=$integ['missingCount']?> · 더미 <?=$integ['dummyCount']?></div>
    </div>
  </header>

  <!-- 데스크 코멘트 -->
  <div style="border-left:3px solid var(--amber);padding-left:16px;margin:18px 0 28px;">
    <div class="eyebrow" style="font-size:11px;letter-spacing:.16em;margin-bottom:8px;">데스크 코멘트 <span style="color:var(--faint);letter-spacing:0;text-transform:none;font-weight:400;">· 자동 생성</span></div>
    <p style="margin:0 0 12px;font-size:17px;font-weight:700;"><?=h($brief['head'] ?? '')?></p>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr));">
      <div>
        <div style="font-size:10.5px;letter-spacing:.12em;color:var(--faint);text-transform:uppercase;margin-bottom:6px;">전일 대비 변화</div>
        <?php foreach (($brief['change'] ?? []) as $c): ?><p style="margin:0 0 6px;font-size:13.5px;">· <?=h($c)?></p><?php endforeach; ?>
      </div>
      <div>
        <div style="font-size:10.5px;letter-spacing:.12em;color:var(--amber);text-transform:uppercase;margin-bottom:6px;">주의 · 이상치</div>
        <?php foreach (($brief['watch'] ?? []) as $w): ?><p style="margin:0 0 6px;font-size:13.5px;">· <?=h($w)?></p><?php endforeach; ?>
      </div>
    </div>
  </div>

  <?=mkt_news_panel($d)?>

  <!-- 국내지수 hero (좌 국내 / 우 미국) -->
  <div class="panel" style="margin-bottom:16px;margin-top:28px;">
    <div class="mkt-hero">
      <div class="mkt-hero-col">
        <div class="mkt-hero-cat">국내지수 <span class="mkt-hero-date">· <?=h($asofKr)?></span></div>
        <div class="mkt-hero-cards">
          <?php foreach (($d['indicesKr'] ?? []) as $i): if (($i['quality'] ?? '') !== 'ok') continue; $cl = mkt_cls($i['day']['dir']); ?>
          <div>
            <div style="font-size:12px;color:var(--muted);"><?=h($i['name'])?></div>
            <div class="mono <?=$cl?>" style="font-size:20px;font-weight:800;"><?=h(number_format($i['close'], 2))?></div>
            <div class="mono <?=$cl?>" style="font-size:12px;"><?=mkt_arrow($i['day']['dir'])?> <?=number_format(abs($i['day']['chg']), 2)?> &nbsp; <?=mkt_signpct($i['day']['pct'])?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?=mkt_us_index_box($d)?>
    </div>
  </div>

  <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(330px,1fr));">
    <!-- 투자자별 매매동향 -->
    <?php $inv = $d['investors']['kospi'] ?? null; if ($inv): ?>
    <div class="panel">
      <div class="sechdr"><span class="eyebrow">투자자별 매매동향 · 코스피</span>
        <span class="asof mono"><?=h($d['investors']['kospi']['asof'] ?? '')?> 순매수(억)
          <button type="button" onclick="mktOpenInvModal()" title="120일 누적·일별 추이" style="cursor:pointer;background:none;border:1px solid var(--line);border-radius:5px;color:var(--muted);font-size:12px;padding:1px 6px;margin-left:6px;">📊</button>
        </span>
      </div>
      <div>
      <?php
        $rows = [['개인', $inv['individual']], ['외국인', $inv['foreign']], ['기관계', $inv['institution']], ['기타법인', $inv['etcCorp']]];
        $max = 1; foreach ($rows as $r) $max = max($max, abs((float) $r[1]));
        foreach ($rows as $r): [$nm, $val] = $r; $val = (float) $val; $w = round(abs($val) / $max * 50, 1); $cl = $val >= 0 ? 'up' : 'down'; ?>
        <div style="margin-bottom:9px;">
          <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:3px;"><span><?=h($nm)?></span><span class="mono <?=$cl?>"><?=($val >= 0 ? '+' : '') . number_format($val)?></span></div>
          <div class="bar"><span class="center"></span><span class="fill <?=$cl?>" style="<?=$val >= 0 ? 'left:50%' : 'right:50%'?>;width:<?=$w?>%;background:var(--<?=$cl?>);"></span></div>
        </div>
      <?php endforeach; ?>
      </div>
      <div style="margin-top:10px;font-size:11px;color:var(--faint);">기관 내역: 금융투자 <?=mkt_eok($inv['finInvest'])?> · 연기금 <?=mkt_eok($inv['pension'])?> (억)</div>
    </div>
    <?php endif; ?>

    <!-- 증시 자금동향 -->
    <?php $fl = $d['flows'] ?? null; if ($fl && ($fl['quality'] ?? '') !== 'missing'): ?>
    <div class="panel">
      <div class="sechdr"><span class="eyebrow">증시 자금동향</span><span class="asof mono">기준 <?=h($fl['asof'] ?? '')?></span></div>
      <div class="grid" style="grid-template-columns:1fr 1fr;gap:12px;">
        <div>
          <div style="font-size:11px;color:var(--muted);">고객예탁금</div>
          <div class="mono" style="font-size:21px;font-weight:700;"><?=mkt_jo($fl['deposit']['level'])?></div>
          <div class="mono <?=($fl['deposit']['chg'] ?? 0) >= 0 ? 'up' : 'down'?>" style="font-size:12px;"><?=($fl['deposit']['chg'] >= 0 ? '+' : '') . mkt_eok($fl['deposit']['chg'])?></div>
        </div>
        <div>
          <div style="font-size:11px;color:var(--muted);">신용잔고</div>
          <div class="mono" style="font-size:21px;font-weight:700;"><?=mkt_jo($fl['credit']['level'])?></div>
          <div class="mono <?=($fl['credit']['chg'] ?? 0) >= 0 ? 'up' : 'down'?>" style="font-size:12px;"><?=($fl['credit']['chg'] >= 0 ? '+' : '') . mkt_eok($fl['credit']['chg'])?></div>
        </div>
      </div>
      <div style="margin-top:10px;font-size:12px;color:var(--muted);">펀드 주식형 <span class="mono"><?=mkt_jo($fl['funds']['equity'])?></span> · 혼합형 <span class="mono"><?=mkt_jo($fl['funds']['mixed'])?></span> · 채권형 <span class="mono"><?=mkt_jo($fl['funds']['bond'])?></span></div>
    </div>
    <?php endif; ?>
  </div>

  <div class="divider"><span class="tag">해외 · 환율 · 원자재 · 금리</span><span class="ln"></span></div>

  <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(330px,1fr));margin-top:16px;">
    <?=mkt_price_table('해외 주식지수', $d['indices'] ?? [], $d['asof']['indices'] ?? '', false, true, true)?>
    <?=mkt_price_table('원자재 · 유가 · 금속', $d['commodities'] ?? [], $d['asof']['commodities'] ?? '', false, true, true)?>
  </div>
  <?=mkt_us_largecap_table($d)?>
  <?=mkt_jp_largecap_table($d)?>
  <?=mkt_price_table('환율 · 원화', $d['fx'] ?? [], $d['asof']['fx'] ?? '', true)?>
  <?=mkt_bond_section($d)?>

  <?=mkt_tofill_note($d)?>

  <p style="font-size:11px;color:var(--faint);margin-top:22px;text-align:center;">
    상승 <span class="up">▲</span> / 하락 <span class="down">▼</span> (한국 관습) · 전일비 중심 · 미수집은 0이 아닌 누락 · 생성 <?=h($d['generatedAt'] ?? '')?>
  </p>

</div></div>

<!-- 투자자별 누적·일별 추이 모달 (📊 클릭 시 api.php 라이브 조회) -->
<div id="mkt-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:9999;overflow:auto;" onclick="if(event.target===this)mktCloseInvModal()">
  <div style="max-width:920px;margin:28px auto;background:#151B2B;border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:18px 20px 22px;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px;">
      <div style="font-weight:800;font-size:16px;color:#E6E9F0;">투자자별 순매수 추이 <span id="mkt-modal-sub" style="font-size:12px;color:#8A93A6;font-weight:400;">· 120일</span></div>
      <div style="display:flex;gap:6px;">
        <button type="button" id="mkt-mk-kospi"  onclick="mktInvMarket('kospi')"  class="mkt-mk on">코스피</button>
        <button type="button" id="mkt-mk-kosdaq" onclick="mktInvMarket('kosdaq')" class="mkt-mk">코스닥</button>
        <button type="button" onclick="mktCloseInvModal()" class="mkt-mk" style="font-weight:700;">✕</button>
      </div>
    </div>
    <div id="mkt-modal-loading" style="padding:40px 0;text-align:center;color:#8A93A6;font-size:13px;">불러오는 중…</div>
    <div id="mkt-modal-body" style="display:none;">
      <div style="font-size:11px;color:#8A93A6;margin:6px 0 2px;">누적 순매수 <span style="color:#5A6377;">— 120일 합계 (0 기준 우상향=순매수 누적)</span></div>
      <div id="mkt-cum-sum" style="display:flex;gap:18px;flex-wrap:wrap;align-items:center;margin:2px 0 4px;"></div>
      <div id="mkt-cum-chart"></div>
      <div style="font-size:11px;color:#8A93A6;margin:8px 0 0;">일별 순매수 (억)</div>
      <div id="mkt-daily-chart"></div>
    </div>
  </div>
</div>
<style>
  .mkt-mk{cursor:pointer;background:none;border:1px solid rgba(255,255,255,.12);border-radius:6px;color:#8A93A6;font-size:12px;padding:3px 10px;}
  .mkt-mk.on{background:#E0A340;border-color:#E0A340;color:#0E1320;font-weight:700;}
</style>
<script src="/env/js/apexcharts.js"></script>
<script>
var MKT_KEY = <?=json_encode(MKT_KEY)?>;
var mktMarket = 'kospi', mktCh = {cum:null, daily:null};
function mktOpenInvModal(){ document.getElementById('mkt-modal').style.display='block'; mktInvLoad('kospi'); }
function mktCloseInvModal(){ document.getElementById('mkt-modal').style.display='none'; }
function mktInvMarket(m){
  mktMarket=m;
  document.getElementById('mkt-mk-kospi').className='mkt-mk'+(m==='kospi'?' on':'');
  document.getElementById('mkt-mk-kosdaq').className='mkt-mk'+(m==='kosdaq'?' on':'');
  mktInvLoad(m);
}
function mktInvLoad(market){
  var L=document.getElementById('mkt-modal-loading'), B=document.getElementById('mkt-modal-body');
  L.style.display='block'; L.textContent='불러오는 중…'; B.style.display='none';
  document.getElementById('mkt-modal-sub').textContent='· '+(market==='kosdaq'?'코스닥':'코스피')+' 120일';
  fetch('/market/api.php?key='+encodeURIComponent(MKT_KEY)+'&action=invtrend&days=120&market='+market)
    .then(function(r){return r.json();})
    .then(function(d){
      var s=(d&&d.series)||[];
      if(!s.length){ L.textContent='데이터 없음'; return; }
      L.style.display='none'; B.style.display='block';
      mktDrawInv(s);
    })
    .catch(function(e){ L.textContent='오류: '+e; });
}
function mktDrawInv(s){
  if(typeof ApexCharts==='undefined') return;
  var cats=s.map(function(r){return (r.date||'').slice(2);});      // YY-MM-DD
  var keys=['individual','foreign','institution'], names={individual:'개인',foreign:'외국인',institution:'기관'};
  var acc={individual:0,foreign:0,institution:0}, cum={individual:[],foreign:[],institution:[]};
  s.forEach(function(r){ keys.forEach(function(k){ acc[k]+=Number(r[k])||0; cum[k].push(Math.round(acc[k])); }); });
  var daily=function(k){return s.map(function(r){return Math.round(Number(r[k])||0);});};
  var colors=['#E5484D','#3E7BFA','#E0A340'];
  var jo=function(v){return Math.round(v/10000*10)/10+'조';}, jo2=function(v){return (v/10000).toFixed(2)+'조';};
  // 현재까지 누적 합계 칩 (점=투자자색 / 값=부호색 매수red·매도blue)
  document.getElementById('mkt-cum-sum').innerHTML = keys.map(function(k,i){
    var v=cum[k][cum[k].length-1]||0, sc=(v>=0?'#E5484D':'#3E7BFA');
    var val=(v>=0?'+':'−')+Math.abs(Math.round(v/10000*10)/10)+'조';
    return '<span style="display:inline-flex;align-items:center;gap:6px;font-size:13.5px;color:#E6E9F0;">'
      +'<span style="width:9px;height:9px;border-radius:50%;background:'+colors[i]+';"></span>'
      +names[k]+' <b style="color:'+sc+';font-variant-numeric:tabular-nums;">'+val+'</b></span>';
  }).join('');
  var xax={categories:cats,tickAmount:8,labels:{style:{colors:'#8A93A6',fontSize:'10px'},rotate:0},axisBorder:{show:false},axisTicks:{show:false}};
  if(mktCh.cum) mktCh.cum.destroy(); if(mktCh.daily) mktCh.daily.destroy();
  mktCh.cum=new ApexCharts(document.getElementById('mkt-cum-chart'),{
    chart:{type:'line',height:300,background:'transparent',toolbar:{show:false},animations:{enabled:false}},
    theme:{mode:'dark'}, colors:colors, stroke:{width:2,curve:'straight'}, dataLabels:{enabled:false},
    series:keys.map(function(k){return {name:names[k],data:cum[k]};}),
    xaxis:xax, yaxis:{labels:{style:{colors:'#8A93A6'},formatter:jo}},
    legend:{labels:{colors:'#E6E9F0'},position:'top'}, grid:{borderColor:'rgba(255,255,255,.08)'},
    tooltip:{theme:"dark",y:{formatter:jo2}},
  }); mktCh.cum.render();
  mktCh.daily=new ApexCharts(document.getElementById('mkt-daily-chart'),{
    chart:{type:'bar',height:200,background:'transparent',toolbar:{show:false},animations:{enabled:false}},
    theme:{mode:'dark'}, colors:colors, dataLabels:{enabled:false}, stroke:{width:0},
    plotOptions:{bar:{columnWidth:'85%'}},
    series:keys.map(function(k){return {name:names[k],data:daily(k)};}),
    xaxis:xax, yaxis:{labels:{style:{colors:'#8A93A6'},formatter:jo}},
    legend:{show:false}, grid:{borderColor:'rgba(255,255,255,.08)'},
    tooltip:{theme:"dark",y:{formatter:jo2}},
  }); mktCh.daily.render();
}
// 뉴스 링크: PC=팝업창(etf_stock.php etf_d5 네이버기사와 동일) / 모바일=target=_blank 새창 유지
// 서버 $mobile(cnt.inc) UA 정규식을 그대로 미러링 — HTML이 캐시 서빙이라 서버 분기 불가, 런타임 JS로 판정
(function(){
  var isMobile = /(Windows CE|Nokia|SonyEricsson|webOS|PalmOS|Android|Mobile|Macintosh)/.test(navigator.userAgent);
  if (isMobile) return;   // 모바일: 현재처럼 새창(target=_blank) 그대로
  document.querySelectorAll('a.mkt-news-a').forEach(function(a){
    a.addEventListener('click', function(e){
      e.preventDefault();
      window.open(a.href, 'news_popup', 'width=800,height=900,left=200,top=100,scrollbars=yes');
    });
  });
})();
</script>
</body></html>
    <?php
    return ob_get_clean();
}

/** 국내지수 hero 우측 — 미국 주요지수(나스닥·S&P500·필라델피아 반도체) 미니 박스 */
function mkt_us_index_box(array $d): string {
    $by = [];
    foreach (($d['indices'] ?? []) as $i) $by[$i['id']] = $i;
    $pick = [];
    foreach (['nasdaq', 'sp500', 'sox'] as $id)
        if (($by[$id]['quality'] ?? '') === 'ok') $pick[] = $by[$id];
    if (!$pick) return '';
    ob_start(); ?>
      <div class="mkt-hero-col mkt-hero-us">
        <div class="mkt-hero-cat">미국지수 <span class="mkt-hero-date">· 간밤 <?=h($d['asof']['indices'] ?? '')?></span></div>
        <div class="mkt-hero-cards">
          <?php foreach ($pick as $i): $cl = mkt_cls($i['day']['dir']); $chg = $i['day']['chg']; ?>
          <div>
            <div style="font-size:12px;color:var(--muted);"><?=h($i['name'])?></div>
            <div class="mono <?=$cl?>" style="font-size:20px;font-weight:800;"><?=h(number_format($i['close'], 2))?></div>
            <div class="mono <?=$cl?>" style="font-size:12px;"><?=mkt_arrow($i['day']['dir'])?> <?=$chg === null ? '—' : number_format(abs($chg), 2)?> &nbsp; <?=mkt_signpct($i['day']['pct'])?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php return ob_get_clean();
}

function mkt_news_list(array $items): string {
    ob_start(); ?>
    <ol style="margin:0;padding-left:20px;list-style:decimal;">
      <?php foreach ($items as $a): ?>
      <li style="margin:0 0 11px;">
        <a href="<?=h($a['link'])?>" target="_blank" rel="noopener" class="mkt-news-a" style="color:var(--text);font-size:14px;font-weight:600;text-decoration:none;"><?=h($a['title'])?></a>
        <?php if (!empty($a['summary'])): ?><div style="font-size:12px;color:var(--muted);margin-top:2px;"><?=h($a['summary'])?></div><?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ol>
    <?php return ob_get_clean();
}

function mkt_news_panel(array $d): string {
    $news = $d['news'] ?? [];
    $us   = $d['newsUs'] ?? [];
    if (!$news && !$us) return '';
    ob_start(); ?>
  <div class="panel" style="margin:0 0 28px;">
    <div class="sechdr"><span class="eyebrow">주요 뉴스</span><span class="asof mono">네이버 증권 · <?=h($d['asof']['news'] ?? $d['date'])?></span></div>
    <?php if ($news): ?>
      <div style="font-size:11px;letter-spacing:.06em;color:var(--faint);text-transform:uppercase;margin-bottom:6px;">국내 시황</div>
      <?=mkt_news_list($news)?>
    <?php endif; ?>
    <?php if ($us): ?>
      <div style="font-size:11px;letter-spacing:.06em;color:var(--amber);text-transform:uppercase;margin:14px 0 6px;border-top:1px solid var(--line);padding-top:12px;">뉴욕 · 해외 증시 <span style="color:var(--faint);text-transform:none;letter-spacing:0;">간밤 <?=h($d['asof']['newsUs'] ?? '')?></span></div>
      <?=mkt_news_list($us)?>
    <?php endif; ?>
  </div>
    <?php return ob_get_clean();
}

/** 야후 시총("5.058T"/"932.5B") → 조달러(숫자). 없으면 null. */
function mkt_mcap_usd_jo(string $s): ?float {
    $s = trim($s);
    if ($s === '' || $s === '--') return null;
    if (!preg_match('/([\d.]+)\s*([TBM]?)/i', $s, $m)) return null;
    $mult = ['T' => 1.0, 'B' => 0.001, 'M' => 0.000001, '' => 1.0];
    return (float) $m[1] * ($mult[strtoupper($m[2])] ?? 1.0);
}

function mkt_jp_largecap_table(array $d): string {
    $rows = $d['jpLargeCap'] ?? [];
    if (!$rows) return '';
    $jpy = $usd = null;
    foreach (($d['fx'] ?? []) as $f) {
        if (($f['id'] ?? '') === 'jpy') $jpy = $f['close'];  // 100엔당 원
        if (($f['id'] ?? '') === 'usd') $usd = $f['close'];  // 1달러당 원
    }
    $rate = $jpy ? $jpy / 100 : null;                        // 1엔당 원
    ob_start(); ?>
  <div class="panel" style="margin-top:16px;">
    <div class="sechdr"><span class="eyebrow">일본 시총 상위 25</span><span class="asof mono">야후 재팬 · <?=h($d['asof']['jpLargeCap'] ?? '')?><?=$jpy ? ' · ￥100=₩' . number_format($jpy) : ''?></span></div>
    <table>
      <thead><tr><th>종목</th><th>주가($)</th><th>등락률</th><th>시총(조원)</th><th>PER</th><th>52주</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $n => $r): $pc = $r['pct']; $wc = $r['w52'];
        $cap = ($rate && ($r['capJpy'] ?? null) !== null) ? $r['capJpy'] * $rate / 1e12 : null;
        $priceUsd = ($rate && $usd && ($r['priceJpy'] ?? null) !== null) ? $r['priceJpy'] * $rate / $usd : null; ?>
        <tr>
          <td><span class="mono" style="color:var(--faint);"><?=$n + 1?></span> <b><?=h($r['code'])?></b> <span style="color:var(--muted);font-size:11px;"><?=h($r['name'])?></span></td>
          <td class="num"><?=$priceUsd === null ? '—' : '$' . number_format($priceUsd, 2)?></td>
          <td class="num <?=$pc === null ? 'flat' : ($pc >= 0 ? 'up' : 'down')?>"><?=$pc === null ? '—' : sprintf('%+.2f%%', $pc)?></td>
          <td class="num"><?=$cap === null ? '—' : number_format(round($cap)) . '조'?></td>
          <td class="num"><?=($r['pe'] ?? null) !== null ? number_format((float) $r['pe'], 2) : '—'?></td>
          <td class="num <?=$wc === null ? 'flat' : ($wc >= 0 ? 'up' : 'down')?>"><?=$wc === null ? '—' : sprintf('%+.1f%%', $wc)?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
    <?php return ob_get_clean();
}

function mkt_us_largecap_table(array $d): string {
    $us = $d['usLargeCap'] ?? [];
    if (!$us) return '';
    $usd = null;
    foreach (($d['fx'] ?? []) as $f) if (($f['id'] ?? '') === 'usd') $usd = $f['close'];

    // 미국 + 국내 대형주를 조원 시총으로 통일해 한 리스트로 병합·정렬
    $list = [];
    foreach ($us as $r) {
        $uj = mkt_mcap_usd_jo($r['marketCap']);
        $list[] = ['sym' => $r['symbol'], 'name' => (MKT_US_NAME_KR[$r['symbol']] ?? $r['name']),
            'cap' => ($usd && $uj !== null) ? $uj * $usd : null, 'pct' => $r['pct'],
            'priceUsd' => $r['price'] ?? null,                                          // 이미 달러
            'pe' => ($r['pe'] !== '' && $r['pe'] !== '--') ? $r['pe'] : '', 'w52' => $r['w52'], 'kr' => false];
    }
    foreach (($d['krMega'] ?? []) as $k) {
        $list[] = ['sym' => $k['symbol'], 'name' => '',
            'cap' => ($k['capEok'] ?? null) !== null ? $k['capEok'] / 10000 : null, 'pct' => $k['rate'],
            'priceUsd' => ($usd && ($k['price'] ?? null) !== null) ? $k['price'] / $usd : null,  // 원→달러
            'pe' => (($k['per'] ?? 0) > 0) ? number_format((float) $k['per'], 2) : '', 'w52' => null, 'kr' => true];
    }
    usort($list, fn($a, $b) => ($b['cap'] ?? -1) <=> ($a['cap'] ?? -1));

    ob_start(); ?>
  <div class="panel" style="margin-top:16px;">
    <div class="sechdr"><span class="eyebrow">글로벌 시총 상위 <span style="color:var(--faint);font-weight:400;text-transform:none;letter-spacing:0;">미국 25 + 삼성·하이닉스</span></span><span class="asof mono">야후·KRX<?=$usd ? ' · ₩' . number_format($usd) : ''?></span></div>
    <table>
      <thead><tr><th>종목</th><th>주가($)</th><th>등락률</th><th>시총(조원)</th><th>PER</th><th>52주</th></tr></thead>
      <tbody>
      <?php foreach ($list as $n => $r): $pc = $r['pct']; $wc = $r['w52']; ?>
        <tr<?=$r['kr'] ? ' style="background:rgba(224,163,64,.10);"' : ''?>>
          <td><span class="mono" style="color:var(--faint);"><?=$n + 1?></span> <b><?=h($r['sym'])?></b>
            <?=$r['name'] !== '' ? '<span style="color:var(--muted);font-size:11px;">' . h($r['name']) . '</span>' : ''?><?=$r['kr'] ? ' <span style="font-size:10px;color:var(--amber);font-weight:700;">KR</span>' : ''?></td>
          <td class="num"><?=($r['priceUsd'] ?? null) === null ? '—' : '$' . number_format($r['priceUsd'], 2)?></td>
          <td class="num <?=$pc === null ? 'flat' : ($pc >= 0 ? 'up' : 'down')?>"><?=$pc === null ? '—' : sprintf('%+.2f%%', $pc)?></td>
          <td class="num"><?=$r['cap'] === null ? '—' : number_format(round($r['cap'])) . '조'?></td>
          <td class="num"><?=$r['pe'] !== '' ? h($r['pe']) : '—'?></td>
          <td class="num <?=$wc === null ? 'flat' : ($wc >= 0 ? 'up' : 'down')?>"><?=$wc === null ? '—' : sprintf('%+.1f%%', $wc)?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
    <?php return ob_get_clean();
}

function mkt_price_table(string $title, array $items, string $asof, bool $isFx = false, bool $compact = false, bool $bare = false): string {
    $items = array_filter($items, fn($i) => ($i['quality'] ?? '') === 'ok');
    if (!$items) return '';
    ob_start(); ?>
  <div class="panel"<?=$bare ? '' : ' style="margin-top:16px;"'?>>
    <div class="sechdr"><span class="eyebrow"><?=h($title)?></span><span class="asof mono"><?=h($asof)?></span></div>
    <table>
      <thead><tr><th>항목</th><?php if (!$compact): ?><th><?=$isFx ? '매매기준율' : '종가'?></th><th>전일비</th><?php endif; ?><th>등락률</th><th>1개월</th><th>1년</th></tr></thead>
      <tbody>
      <?php foreach ($items as $i): $cl = mkt_cls($i['day']['dir']); ?>
        <tr>
          <td><?=h($i['name'])?></td>
          <?php if (!$compact): ?>
          <td class="num"><?=h(number_format($i['close'], 2))?></td>
          <td class="num <?=$cl?>"><?=mkt_arrow($i['day']['dir'])?> <?=number_format(abs((float) $i['day']['chg']), 2)?></td>
          <?php endif; ?>
          <td class="num <?=$cl?>"><?=mkt_signpct($i['day']['pct'])?></td>
          <td class="num <?=($i['ctx']['m1'] ?? 0) >= 0 ? 'up' : 'down'?>"><?=mkt_signpct($i['ctx']['m1'] ?? null)?></td>
          <td class="num <?=($i['ctx']['y1'] ?? 0) >= 0 ? 'up' : 'down'?>"><?=mkt_signpct($i['ctx']['y1'] ?? null)?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
    <?php return ob_get_clean();
}

function mkt_bond_rows(array $bonds): string {
    ob_start(); ?>
    <table>
      <thead><tr><th>상품</th><th>금리</th><th>전일</th></tr></thead>
      <tbody>
      <?php foreach ($bonds as $b): $cl = mkt_cls($b['day']['dir']); ?>
        <tr>
          <td><?=h($b['name'])?></td>
          <td class="num"><?=h(number_format($b['level'], 2))?></td>
          <td class="num <?=$b['day']['bp'] == 0 ? 'flat' : $cl?>"><?=$b['day']['bp'] === null ? '—' : sprintf('%+.0fbp', $b['day']['bp'])?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php return ob_get_clean();
}

function mkt_bond_section(array $d): string {
    $bonds = array_filter($d['bonds'] ?? [], fn($b) => ($b['quality'] ?? '') === 'ok');
    if (!$bonds) return '';
    $oversea = fn($b) => str_starts_with($b['id'], 'us') || str_starts_with($b['id'], 'jp');
    $dom = array_filter($bonds, fn($b) => !$oversea($b));
    $ovs = array_filter($bonds, $oversea);
    $sp  = $d['bondSpreads'] ?? [];

    $lvl = []; foreach ($bonds as $b) $lvl[$b['id']] = $b['level'];
    $krus = (isset($lvl['kr10y'], $lvl['us10y'])) ? (int) round(($lvl['kr10y'] - $lvl['us10y']) * 100) : null;

    ob_start(); ?>
  <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(330px,1fr));margin-top:16px;">
    <div class="panel">
      <div class="sechdr"><span class="eyebrow">채권 · 금리 (국내)</span><span class="asof mono">기준 <?=h($d['asof']['bonds'] ?? '')?> · bp</span></div>
      <?=mkt_bond_rows($dom)?>
      <div style="margin-top:8px;font-size:11px;color:var(--faint);">
        커브 10Y−1Y <?=$sp['kr10y_kr1y'] ?? '—'?>bp · 30Y−20Y <?=$sp['kr30y_kr20y'] ?? '—'?>bp · BBB− 스프레드 <?=$sp['creditBBBm_kr3y'] ?? '—'?>bp
      </div>
    </div>
    <?php if ($ovs): ?>
    <div class="panel">
      <div class="sechdr"><span class="eyebrow">채권 · 금리 (해외)</span><span class="asof mono">미국·일본 국채</span></div>
      <?=mkt_bond_rows($ovs)?>
      <div style="margin-top:8px;font-size:11px;color:var(--faint);">
        한·미 10년 금리차 <?=$krus === null ? '—' : sprintf('%+d', $krus)?>bp <?=($krus !== null && $krus < 0) ? '(역전)' : ''?>
      </div>
    </div>
    <?php endif; ?>
  </div>
    <?php return ob_get_clean();
}

function mkt_tofill_note(array $d): string {
    $miss = $d['missing'] ?? [];
    $fill = $d['toFill'] ?? [];
    $rows = [];
    foreach ($miss as $sec => $names) if ($names) $rows[] = h($sec) . ': ' . h(implode(', ', $names));
    foreach ($fill as $sec => $names) if ($names) $rows[] = h($sec) . ' (함께 채울 항목): ' . h(implode(', ', $names));
    if (!$rows) return '';
    ob_start(); ?>
  <div class="panel" style="margin-top:16px;border-color:rgba(224,163,64,.35);">
    <div class="sechdr"><span class="eyebrow" style="color:var(--amber);">미수집 · 함께 채울 항목</span></div>
    <div style="font-size:12px;color:var(--muted);line-height:1.9;">
      <?php foreach ($rows as $r): ?>· <?=$r?><br><?php endforeach; ?>
    </div>
    <div style="margin-top:6px;font-size:11px;color:var(--faint);">위 항목은 0%가 아니라 데이터 누락입니다. 수동으로 함께 채워나갈 예정.</div>
  </div>
    <?php return ob_get_clean();
}

function mkt_css(): string {
    return <<<'CSS'
<style>
  /* 브라우저 기본 body 여백(8px) 제거 — 다른 페이지처럼 네비/콘텐츠가 가장자리까지 꽉 차게(흰 테두리 박스 제거) */
  html,body { margin:0; padding:0; background:#0E1320; }
  .db * { box-sizing:border-box; }
  .db { --ink:#0E1320;--panel:#151B2B;--line:rgba(255,255,255,.08);--text:#E6E9F0;--muted:#8A93A6;--faint:#5A6377;--amber:#E0A340;--up:#E5484D;--down:#3E7BFA;--ok:#4ED88B;
    background:var(--ink);color:var(--text);padding:24px 16px 48px;font-family:'Apple SD Gothic Neo','Pretendard',system-ui,sans-serif;line-height:1.55;min-height:100vh; }
  .db .wrap { max-width:880px;margin:0 auto; }
  .db .mono { font-family:ui-monospace,'SF Mono',Menlo,monospace;font-variant-numeric:tabular-nums; }
  .db .up { color:var(--up); } .db .down { color:var(--down); } .db .flat { color:var(--muted); }
  .db .eyebrow { font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:var(--amber);font-weight:700; }
  .db .panel { background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:16px; }
  .db .grid { display:grid;gap:16px; }
  .db .head-row { display:flex;justify-content:space-between;align-items:flex-start; }
  .db .sechdr { display:flex;align-items:baseline;justify-content:space-between;border-bottom:1px solid var(--line);padding-bottom:8px;margin-bottom:14px; }
  .db .asof { font-size:10px;color:var(--faint); }
  .db table { width:100%;border-collapse:collapse; }
  .db th { font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:var(--faint);text-align:right;font-weight:600;padding:4px 0; }
  .db th:first-child,.db td:first-child { text-align:left; }
  .db td { font-size:13px;padding:6px 0;border-top:1px solid var(--line); }
  .db td.num { font-family:ui-monospace,monospace;font-variant-numeric:tabular-nums;text-align:right; }
  .db .divider { display:flex;align-items:center;gap:14px;margin:34px 0 18px; }
  .db .divider .ln { flex:1;height:1px;background:var(--line); }
  .db .divider .tag { font-size:11px;letter-spacing:.2em;text-transform:uppercase;color:var(--muted); }
  .db .bar { position:relative;height:18px;background:rgba(255,255,255,.04);border-radius:4px; }
  .db .bar .center { position:absolute;left:50%;top:0;bottom:0;width:1px;background:var(--line); }
  .db .bar .fill { position:absolute;top:3px;bottom:3px;border-radius:3px;opacity:.9; }
  /* 국내지수 hero: PC=국내 좌 / 미국 우(각자 카테고리·기준일, 한줄), 모바일=미국이 아래로 */
  .db .mkt-hero { display:flex;align-items:flex-start;gap:24px; }
  .db .mkt-hero-col { flex-shrink:0; }
  .db .mkt-hero-us { margin-left:auto;border-left:1px solid var(--line);padding-left:22px; }
  .db .mkt-hero-cat { font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--amber);font-weight:700;margin-bottom:12px; }
  .db .mkt-hero-date { color:var(--faint);font-weight:400;letter-spacing:0;text-transform:none;font-size:11px; }
  .db .mkt-hero-cards { display:flex;gap:18px;flex-wrap:nowrap; }
  @media (max-width:760px){
    .db .mkt-hero { flex-direction:column;gap:18px; }
    .db .mkt-hero-col { flex-shrink:1; }
    .db .mkt-hero-us { margin-left:0;width:100%;border-left:0;border-top:1px solid var(--line);padding-left:0;padding-top:16px; }
    .db .mkt-hero-cards { flex-wrap:wrap; }
  }
</style>
CSS;
}
