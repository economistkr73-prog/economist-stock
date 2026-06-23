<?php
/**
 * market/crawl.php — 7개 소스 수집 → 정규화 → 품질게이트 → snapshots/YYYY-MM-DD.json (atomic)
 *
 * 실행:
 *   CLI : php market/crawl.php
 *   WEB : /market/crawl.php?key=econ-mkt-7x3k   (&date=YYYY-MM-DD 강제)
 */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/db.php';

$cli = (PHP_SAPI === 'cli');
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (($_GET['key'] ?? '') !== MKT_KEY) { http_response_code(403); exit("forbidden\n"); }
}

// ── bg=1: 즉시 200 응답 후 연결 종료, 나머지는 백그라운드에서 끝까지 실행 ──
//    (cron-job.org 20초 타임아웃 회피 — cron_keyword_collector.php 패턴)
if (!$cli && isset($_GET['bg'])) {
    ignore_user_abort(true);
    @set_time_limit(0);
    ob_start();
    echo "OK (background) — 수집·리포트 진행 중\n";
    header('Content-Length: ' . ob_get_length());
    header('Connection: close');
    ob_end_flush();
    flush();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
}

$crawlDate = $_GET['date'] ?? date('Y-m-d');   // 실행일 (투자자 조회·폴백용)
$bizdate   = str_replace('-', '', $crawlDate);
// $marketDate(거래일=스냅샷 기준일)는 투자자 데이터 최신일로 확정 (5단계 이후)

$missing   = [];
$dummyCnt  = 0;
$asof      = [];

/** 한경 소스 → 정규화 섹션 + missing/asof 집계 */
function mkt_build_section(string $sec, array $cfg, array &$missing, int &$dummyCnt, array &$asof): array {
    $rows  = mkt_hankyung($cfg['url']);     // 한경표기명 => row
    $items = [];
    foreach ($cfg['pick'] as $hkName => $meta) {
        $row = $rows[$hkName] ?? null;
        if ($cfg['type'] === 'rate') {
            [$id, $disp, $tenor] = $meta;
            $item = $row ? mkt_norm_rate($id, $disp, $tenor, $row)
                         : mkt_norm_rate($id, $disp, $tenor, []);
        } else {
            [$id, $disp] = $meta;
            $item = $row ? mkt_norm_price($id, $disp, $row)
                         : mkt_norm_price($id, $disp, []);
        }
        if ($item['quality'] === 'missing') $missing[$sec][] = $disp;
        if ($item['quality'] === 'dummy')   $dummyCnt++;
        if (!empty($item['asof']))          $asof[$sec] = $item['asof'];
        $items[] = $item;
    }
    return $items;
}

/* ── 1) 한경: 해외지수·환율·채권·원자재 ── */
$indices     = mkt_build_section('indices',     MKT_SRC['indices'],     $missing, $dummyCnt, $asof);
$fx          = mkt_build_section('fx',          MKT_SRC['fx'],          $missing, $dummyCnt, $asof);
$bonds       = mkt_build_section('bonds',       MKT_SRC['bonds'],       $missing, $dummyCnt, $asof);
$commodities = mkt_build_section('commodities', MKT_SRC['commodities'], $missing, $dummyCnt, $asof);

/* ── 2) 네이버 marketindex: 유가·금속(WTI·국제금) → commodities 에 합류 ── */
foreach (mkt_naver_marketindex() as $item) {
    if ($item['quality'] === 'missing') $missing['commodities'][] = $item['name'];
    if (!empty($item['asof'])) $asof['commodities'] = $item['asof'];
    $commodities[] = $item;
}

/* ── 3) 네이버: 국내지수 hero ── */
$indicesKr = mkt_naver_indices();
foreach ($indicesKr as $it) if ($it['quality'] === 'missing') $missing['indicesKr'][] = $it['name'];
$asof['indicesKr'] = date('Y-m-d');  // 장중/실시간

/* ── 4) 채권 커브 스프레드(bp) ── */
$lvl = [];
foreach ($bonds as $b) $lvl[$b['id']] = $b['level'];
$sp = fn($a, $bv) => (isset($lvl[$a], $lvl[$bv]) && $lvl[$a] !== null && $lvl[$bv] !== null)
    ? (int) round(($lvl[$a] - $lvl[$bv]) * 100) : null;
$bondSpreads = [
    'kr10y_kr1y'    => $sp('kr10y', 'kr1y'),
    'kr30y_kr20y'   => $sp('kr30y', 'kr20y'),
    'creditBBBm_kr3y' => $sp('corpBBBm', 'kr3y'),
];

/* ── 5) 네이버: 자금동향·투자자 ── */
$deposit = mkt_naver_deposit();
if ($deposit && !empty($deposit['asof'])) $asof['flows'] = $deposit['asof'];
$flows = $deposit ?: ['quality' => 'missing'];
if (!$deposit) $missing['flows'][] = '증시 자금동향';

$investors = mkt_naver_investors($bizdate);
if (($investors['kospi'] ?? null) && !empty($investors['kospi']['asof'])) $asof['investors'] = $investors['kospi']['asof'];
if (!($investors['kospi'] ?? null)) $missing['investors'][] = '투자자별 매매동향';
// (일자별 순매수 추이는 저장하지 않음 — 리포트에서 📊 클릭 시 api.php 로 라이브 조회)

/* ── 6) 거래일(기준일) 확정 + 그 날짜의 시황 뉴스 ── */
//  국내 마지막 거래일 = 투자자 매매동향 최신일. (아침 크롤이면 전일) 없으면 직전 평일.
$marketDate = !empty($investors['kospi']['asof']) ? $investors['kospi']['asof'] : mkt_prev_weekday($crawlDate);
// 국내 시황 = 거래일(전일 마감) / 뉴욕 = 크롤일(간밤 미국장, 새벽 발행이라 보통 page1)
$news   = mkt_naver_news(str_replace('-', '', $marketDate), 8, '401');
$newsUs = mkt_naver_news(str_replace('-', '', $crawlDate), 5, '403', MKT_NEWS_US_POS, true, 4);
if ($news)   $asof['news']   = $marketDate;
if ($newsUs) $asof['newsUs'] = $crawlDate;

// 야후 미국 시총 상위 25 (간밤 미국장)
$usLargeCap = mkt_yahoo_largecap(25);
if ($usLargeCap) $asof['usLargeCap'] = $crawlDate;

// 국내 대형주(삼성전자·SK하이닉스) 시총 — 미국 리스트에 시총순 삽입용 (all_stock_info)
$krMega = [];
try {
    $st = mkt_pdo()->query("SELECT stock_code, stock_name, stock_cap, stock_rate, stock_price, per FROM all_stock_info WHERE stock_code IN ('005930','000660')");
    foreach ($st as $row) {
        $per = mkt_naver_stock_per($row['stock_code']);            // 네이버 추정 PER
        $krMega[] = [
            'symbol' => $row['stock_name'],
            'code'   => $row['stock_code'],
            'capEok' => $row['stock_cap'] !== null ? (float) $row['stock_cap'] : null,  // 억원
            'rate'   => $row['stock_rate'] !== null ? (float) $row['stock_rate'] : null, // 등락률 %
            'price'  => $row['stock_price'] !== null ? (float) $row['stock_price'] : null, // 원
            'per'    => $per ?? ((float) $row['per'] > 0 ? (float) $row['per'] : null),
        ];
    }
} catch (\Throwable $e) {}

// 야후 재팬 시총 상위 25 (랭킹 + Quote API)
$jpLargeCap = mkt_yahoo_jp_largecap(25);
if ($jpLargeCap) $asof['jpLargeCap'] = $crawlDate;

/* ── 7) 스냅샷 조립 ── */
$missingCount = array_sum(array_map('count', $missing));
$snap = [
    'date'        => $marketDate,
    'generatedAt' => date('c'),
    'asof'        => $asof,
    'news'        => $news,
    'newsUs'      => $newsUs,
    'usLargeCap'  => $usLargeCap,
    'krMega'      => $krMega,
    'jpLargeCap'  => $jpLargeCap,
    'indicesKr'   => $indicesKr,
    'indices'     => $indices,
    'fx'          => $fx,
    'bonds'       => $bonds,
    'bondSpreads' => $bondSpreads,
    'commodities' => $commodities,
    'flows'       => $flows,
    'investors'   => $investors,
    'toFill'      => MKT_TOFILL,
    'missing'     => $missing,
    'integrity'   => ['missingCount' => $missingCount, 'dummyCount' => $dummyCnt],
];

/* ── 8) DB 저장 (market_snapshot, 거래일 PK upsert) ── */
mkt_save_snapshot($marketDate, $snap);

echo "saved snapshot to DB: market_snapshot[{$marketDate}]  (실행일 {$crawlDate})\n";
echo "  missing: {$missingCount}, dummy: {$dummyCnt}, news: " . count($news) . "+" . count($newsUs) . "(미), 야후시총: 미" . count($usLargeCap) . "/일" . count($jpLargeCap) . "\n";

/* ── 9) report=1 이면 이어서 리포트 생성(+notify) — cron 1개로 수집·리포트 일괄 ── */
if (isset($_GET['report']) || ($cli && in_array('--report', $argv ?? [], true))) {
    define('MKT_LIB_ONLY', 1);
    require_once __DIR__ . '/report.php';
    // 방금 수집해 데이터가 바뀌었으니 브리핑은 새로 생성(rebrief)
    $r = mkt_generate_report($marketDate, [
        'notify'  => isset($_GET['notify']) || ($cli && in_array('--notify', $argv ?? [], true)),
        'rebrief' => true,
    ]);
    echo $r ? "report generated: market_snapshot[{$marketDate}].html\n" : "report skipped (no snapshot)\n";
}
foreach ($missing as $sec => $names) echo "  - {$sec}: " . implode(', ', $names) . "\n";
