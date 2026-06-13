<?php

require_once "./env/cnt.inc";
require_once "./env/auth_fnc.php";

// ── 공개 공유 링크(읽기 전용) ──────────────────────────────────
// analysis_model.php?mode=daily&k=<토큰> 로 접속하면 로그인 없이 데일리 리포트 열람.
// 데이터는 일반 시장 정보라 공개 가능. 토큰은 env/share_key.inc 단일 소스(유출 시 그 값만 교체).
require_once "./env/share_key.inc";   // DAILY_SHARE_KEY (keyword_news.php 와 공용)
$is_public = (($_REQUEST['mode'] ?? '') === 'daily'
    && isset($_GET['k']) && hash_equals(DAILY_SHARE_KEY, (string)$_GET['k']));
if (!$is_public) {
    require_login();
}
require "./env/e.fnc";


# error 표시
 error_reporting( E_ALL  & ~E_NOTICE);
 ini_set( "display_errors", 1 );
 ini_set("allow_url_fopen",1);

#변수정의
define('CUR_PHP', basename($_SERVER['PHP_SELF']));

// 1. 변수 안전하게 받기 (중복 선언 제거)
$mode = $_REQUEST["mode"] ?? ''; 



// ==========================================================
// 1. 라우팅 지도 (Mapping Table) : 모드명 => 실행할 함수명
// ==========================================================
$routes = [
    'ar'              => 'analysis_report',
    'daily'           => 'daily_market_report',
    'add_stopword'    => 'news_add_stopword',   // 주요 이슈 키워드 × 버튼(삭제)에서 사용
    'del_stopword'    => 'news_del_stopword',
    'test'            => 'test_naver_data_sample',
];

// ==========================================================
// 2. 실행 엔진
// ==========================================================

// ==========================================================
// 3. 공통 네비게이션 헤더 (daily / news 모드에서만 표시)
// ==========================================================
$nav_modes = ['daily'];
$embed = !empty($_REQUEST['embed']) || !empty($is_public);   // 대시보드 iframe 내장(embed=1) 또는 공개 공유 시 상단 nav 숨김
if (in_array($mode, $nav_modes)) {
    // ── 공통 상단 헤더 (PC nav + 모바일 햄버거 + viewport 메타 + 모바일 자동링크 JS) ──
    $current_user = $_SESSION['usr_name'] ?? '';   // header.php 환영문구용 (미정의 경고 방지)
    $expire_date  = $expire_date ?? '';
    if (!empty($is_public)) {   // 공개 공유 링크: 제목/메신저 미리보기를 친근한 문구로
        $page_title = '아현이를 위한 오늘의 증권뉴스';
    }
    require_once "./env/header.php";
    // daily 리포트는 긴 세로 스크롤 페이지 → header.php의 고정 뷰포트 body를 스크롤형으로 override
    echo "<style>body{overflow:auto !important; height:auto !important; min-height:100vh; display:block !important;}</style>";
    // 대시보드 iframe(폭 좁음) 내장 시: nav가 모바일 햄버거로 잘못 전환 → 통째로 숨김(부모에 이미 nav 존재)
    if ($embed) {
        echo "<style>.top-nav-bar{display:none !important;}</style>";
    }
    // 공개(읽기 전용) 모드: 키워드 뉴스는 로그인 밖 공개 뷰어로 우회, 그 외 로그인 필요한 링크는 비활성화
    if (!empty($is_public)) {
        $share_k = json_encode(DAILY_SHARE_KEY);   // JS 문자열 리터럴
        echo "<style>"
           . ".nik-del{display:none !important;}"                 // 제외단어 × 삭제(관리 기능) 숨김
           . "[onclick*=\"gsnb\"]{cursor:default !important;}"    // 종목 뉴스(비공개)는 클릭 affordance 제거
           . "</style>"
           . "<script>(function(){var K={$share_k};var o=window.open;"
           . "window.open=function(u){if(typeof u==='string'){"
           // 키워드 뉴스(mode=gknb) → 로그인 없는 keyword_news.php 팝업으로 우회
           . "var m=u.match(/etf_stock\\.php\\?mode=gknb&keyword=(.*)\$/);"
           . "if(m){return o.call(window,'keyword_news.php?k='+K+'&keyword='+m[1],'kn_popup','width=900,height=900,scrollbars=yes');}"
           . "if(u.indexOf('etf_stock.php')!==-1)return null;"    // 그 외(종목뉴스 등 로그인 필요) 차단
           . "}return o.apply(window,arguments);};})();</script>";
    }

    $cur_date = $_REQUEST['date'] ?? date('Y-m-d');
    if (!$embed) {   // 대시보드 임베드 시엔 단일 탭도 생략
        $tabs = [
            'daily' => ['label' => '📊 데일리 시장 테마 리포트', 'icon' => '📊'],
        ];
        echo "<style>
            .anav { display:flex; gap:0; background:#fff; border-bottom:2px solid #e2e8f0; font-family:'Malgun Gothic',sans-serif; }
            .anav a { display:flex; align-items:center; gap:7px; padding:13px 22px; font-size:0.95rem; font-weight:700; color:#64748b; text-decoration:none; border-bottom:3px solid transparent; margin-bottom:-2px; transition:color 0.15s; }
            .anav a:hover  { color:#2563eb; }
            .anav a.active { color:#2563eb; border-bottom-color:#2563eb; }
        </style>
        <nav class='anav'>";
        foreach ($tabs as $tab_mode => $tab) {
            $active = ($mode === $tab_mode) ? ' class="active"' : '';
            $href   = CUR_PHP . '?mode=' . $tab_mode . '&date=' . htmlspecialchars($cur_date);
            echo "<a href='{$href}'{$active}>{$tab['label']}</a>";
        }
        echo "</nav>";
    }
}

// ==========================================================
// 3-1. 공통 JS 로드 (HTML 모드에서만 — JSON 모드 제외)
//      openCommonFrames 등 공통 함수는 style/economist_claude.js 에 정의됨
// ==========================================================
$html_modes = ['ar', 'daily', 'test'];
if (in_array($mode, $html_modes)) {
    echo "<script src='/style/economist_claude.js'></script>";
}

// ==========================================================
// 4. 실행 엔진
// ==========================================================

if (isset($routes[$mode]) && function_exists($routes[$mode])) {
    $routes[$mode]($pdo);
} else {
    exit;
}



/**
 * analysis_report()
 *
 * 개선 사항 요약
 * ─────────────────────────────────────────────────────
 * [코드 품질]
 * - XSS 방어: 모든 사용자 데이터 → htmlspecialchars() 처리
 * - 끊긴 $html 문자열 버그 수정 (주석이 문자열 안에 섞이던 문제)
 * - 매직넘버 → 상단 옵션 변수로 일원화
 * - 반복 로직 → 헬퍼 함수로 분리 (build_keyword_radar, build_stock_card 등)
 * - 루프 변수 명확화 ($count → array_slice 사용)
 * - 불필요한 중복 조건 제거
 *
 * [UI / 디자인]
 * - 인라인 CSS → <style> 블록으로 분리 (유지보수 용이)
 * - CSS 변수(--color-*) 도입으로 테마 일관성 확보
 * - 반응형 그리드: 모바일(1열) / 태블릿(2열) / 데스크톱(3열)
 * - 키워드 레이더 레이아웃 개선 (gap, 줄바꿈 자연스럽게)
 * - ETF 테이블 → 모바일에서 가로 스크롤 처리
 * - 종목 칩 hover 효과 및 부드러운 전환 애니메이션 추가
 *
 * [성능]
 * - 뉴스 루프에서 array_slice()로 슬라이싱 후 foreach → 불필요한 break 제거
 * ─────────────────────────────────────────────────────
 */

// ──────────────────────────────────────────────────────
// 헬퍼: HTML 이스케이프 (XSS 방어)
// ──────────────────────────────────────────────────────
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// ──────────────────────────────────────────────────────
// 헬퍼: 키워드 레이더 섹션 HTML 생성
// ──────────────────────────────────────────────────────
function build_keyword_radar(array $hot_keywords): string
{
    if (empty($hot_keywords)) {
        return '';
    }

    $chips_html = '';
    $rank = 1;

    foreach ($hot_keywords as $keyword => $mentioned_count) {
        if ($rank <= 2) {
            $chip_class = 'chip chip--hot';
        } elseif ($rank <= 4) {
            $chip_class = 'chip chip--warm';
        } else {
            $chip_class = 'chip chip--cool';
        }

        // 칩 클릭 → etf_stock.php?mode=gknb 뉴스를 etf_d5 프레임에 열기 (데일리 뉴스와 동일)
        $chips_html .= sprintf(
            '<span class="%s" onclick="window.open(\'etf_stock.php?mode=gknb&keyword=\' + encodeURIComponent(\'%s\'), \'etf_d5\'); return false;" style="cursor: pointer;" title="관련 뉴스 보기"><span class="chip__tag">#%s</span><span class="chip__count">%d건</span></span>',
            $chip_class,
            addslashes($keyword), // 자바스크립트 에러 방지용 이스케이프
            h($keyword),
            (int)$mentioned_count
        );
        $rank++;
    }

    return <<<HTML
    <section class="card keyword-radar">
        <header class="card__header">
            <span class="card__icon" aria-hidden="true">🎯</span>
            <div>
                <h3 class="card__title">시장 주도 키워드 레이더</h3>
                <p class="card__subtitle">클릭하시면 해당 키워드가 포함된 뉴스를 확인할 수 있습니다.</p>
            </div>
        </header>
        <div class="chip-group">
            {$chips_html}
        </div>
    </section>
HTML;
}

// ──────────────────────────────────────────────────────
// 헬퍼: 직전 대비 변화량 HTML
// ──────────────────────────────────────────────────────
function build_rate_delta(float $now, float $prev): string
{
    if ($prev == 0.0) {
        return "<span style='color:#94a3b8; font-size:11px; font-weight:600;'>—</span>";
    }
    $delta = $now - $prev;
    if ($delta > 0) {
        return "<span style='color:#e1234a; font-size:11px; font-weight:700;'>▲" . number_format(abs($delta), 2) . "</span>";
    } elseif ($delta < 0) {
        return "<span style='color:#0052a4; font-size:11px; font-weight:700;'>▼" . number_format(abs($delta), 2) . "</span>";
    }
    return "<span style='color:#94a3b8; font-size:11px; font-weight:600;'>0.00</span>";
}

function build_stock_card(array $stock): string
{
    $rate = (float)$stock['stock_rate'];
    $rate_display = ($rate > 0 ? '▲' : ($rate < 0 ? '▼' : '')) . h(abs($rate)) . '%';
    $rate_class   = $rate > 0 ? 'rate--up' : ($rate < 0 ? 'rate--down' : 'rate--flat');

    $prev       = (float)($stock['stock_rate_rt'] ?? 0);
    $delta_html = build_rate_delta($rate, $prev);

    // 🔗 종목명 클릭 → etf_t1, etf_d1, etf_d2 프레임에 코드 전달 (etf_stock.php 동일 패턴)
    $code   = $stock['stock_code'];
    $ename  = urlencode($stock['stock_name']);
    $params = "stock_code={$code}&stock_name={$ename}";
    $js_click = "openCommonFrames(null, '{$code}', '{$params}', ['etf_t1','etf_d1','etf_d2','etf_d5'], 'etf_stock.php');";

    return sprintf(
        '<div class="stock-card">
            <div class="stock-card__top">
                <span class="stock-card__name"><a href="javascript:void(0);" onclick="%s" style="color:inherit; text-decoration:none; cursor:pointer;">%s</a></span>
                <span class="stock-card__rate %s">%s <span class="stock-card__delta">%s</span></span>
            </div>
            <div class="stock-card__sub">거래대금 <b>%s</b></div>
        </div>',
        h($js_click),
        h($stock['stock_name']),
        $rate_class,
        $rate_display,
        $delta_html,
        formatMarketCap($stock['stock_vol_cap'])
    );
}

// ──────────────────────────────────────────────────────
// 헬퍼: ETF 기여 종목 칩 리스트 HTML 생성
// ──────────────────────────────────────────────────────
function build_contributing_stocks(string $raw, int $max = 6): string
{
    if (empty($raw)) {
        return '';
    }

    $chips = '';
    $items = array_slice(explode('||', $raw), 0, $max);

    foreach ($items as $item) {
        // 형식: 종목명|등락률|종목코드
        $parts = explode('|', $item, 3);
        if (count($parts) < 2) {
            continue;
        }

        $s_name    = $parts[0];
        $s_rate    = (float)$parts[1];
        $s_code    = $parts[2] ?? '';
        $s_sign  = $s_rate > 0 ? '+' : '';
        $s_class = $s_rate > 0 ? 'stock-chip--up' : ($s_rate < 0 ? 'stock-chip--down' : '');

        // getDynamicRateStyle()는 기존 함수 그대로 활용
        $style_rate = getDynamicRateStyle($s_rate, 0, 30, 10, 20, 5);

        // 🔗 종목 칩 클릭 → etf_t1, etf_d1, etf_d2, etf_d5 연동 (종목명 클릭과 동일)
        $chip_click = '';
        if ($s_code !== '') {
            $ename  = urlencode($s_name);
            $params = "stock_code={$s_code}&stock_name={$ename}";
            $chip_click = "openCommonFrames(null, '{$s_code}', '{$params}', ['etf_t1','etf_d1','etf_d2','etf_d5'], 'etf_stock.php');";
        }

        $chips .= sprintf(
            '<div class="stock-chip %s" onclick="%s" style="cursor:pointer;">
                <span class="stock-chip__name">%s</span>
                <span class="stock-chip__rate" style="%s">%s%s%%</span>
            </div>',
            $s_class,
            h($chip_click),
            h($s_name),
            h($style_rate['style'] ?? ''),
            h($s_sign),
            h($s_rate)
        );
    }

    return $chips
        ? '<div class="contributing-stocks">' . $chips . '</div>'
        : '';
}

// 
function analysis_report($pdo)
{


    // ── 옵션 변수 (한 곳에서만 수정) ─────────────────
    $opt = [
        'etf_count'       => 10,   // 최대 불러올 ETF 수
        'etf_highlight'   => 5,    // 상세 카드 표시 ETF 수
        'stock_top'       => 9,    // 대형주 표시 수
        'news_per_stock'  => 10,   // 종목당 뉴스 수집 수
        'keyword_top'     => 8,    // 키워드 추출 수
        'stock_chip_max'  => 6,    // ETF 행당 종목 칩 수
        'min_cap'         => 1000, // 최소 시가총액 (억)
        'min_trading'     => 0,    // 최소 거래대금 (억)
    ];

    // ── 데이터 수집 ───────────────────────────────────
    $api = new NaverFinanceAPI();

    $rising_stocks = $api->get_analysis_report($pdo);
    $expected_etfs = $api->get_expected_rising_etfs(
        $pdo,
        $rising_stocks,
        $opt['etf_count'],
        $opt['min_cap'],
        $opt['min_trading']
    );

    // 🚀 뉴스 제목 수집 (전체 뉴스 데이터 JS 전달을 위해 $all_news_full 추가)
    $all_news_titles = [];
    $all_news_full = []; // JS로 넘길 전체 데이터 배열
    $top_stocks = array_slice($rising_stocks, 0, $opt['stock_top']);

    foreach ($top_stocks as $stock) {
        $news_list = $api->getNaverFinanceNews($stock['stock_code'], $opt['news_per_stock']);
        foreach ($news_list as $news) {
            $all_news_titles[] = $news['title'];
            $all_news_full[] = $news; // 뉴스 전체 정보 담기
        }
    }

    $hot_keywords       = $api->get_market_keywords($all_news_titles, $opt['keyword_top']);
    $total_rising_count = count($rising_stocks);
    
    // 🚀 자바스크립트용 JSON 변환
    $news_json = json_encode($all_news_full, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($news_json === false) { $news_json = '[]'; }

    // ── CSS 출력 (인라인 스타일 완전 제거) ───────────
   echo <<<CSS
    <style>
    :root {
        --c-bg:        #f8fafc;
        --c-surface:   #ffffff;
        --c-border:    #e2e8f0;
        --c-text-1:    #1e293b;
        --c-text-2:    #475569;
        --c-text-3:    #94a3b8;
        --c-up:        #ef4444;
        --c-down:      #3b82f6;
        --c-accent:    #8b5cf6;
        --c-accent-2:  #fde047;
        --c-green:     #10b981;
        --radius-lg:   16px;
        --radius-md:   12px;
        --radius-sm:   8px;
        --shadow-sm:   0 2px 4px rgba(0,0,0,0.04);
        --shadow-md:   0 4px 16px rgba(0,0,0,0.08);
        --transition:  0.18s ease;
    }

    /* iframe 스크롤바 숨김 */
    html { scrollbar-width: none; -ms-overflow-style: none; }
    html::-webkit-scrollbar { display: none; }
    body { margin: 0; }

    .card {
        background: var(--c-surface);
        border: 1px solid var(--c-border);
        border-radius: var(--radius-lg);
        padding: 14px 16px;
        box-shadow: var(--shadow-sm);
        margin-bottom: 14px;
    }
    .card__header { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 12px; }
    .card__icon  { font-size: 1.4rem; flex-shrink: 0; }
    .card__title { margin: 0; font-size: 1.05rem; color: var(--c-text-1); font-weight: 700; }
    .card__subtitle { margin: 3px 0 0; color: var(--c-text-2); font-size: 0.85rem; }

    .chip-group { display: flex; flex-wrap: wrap; gap: 10px; }
    .chip {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 6px 14px; border-radius: 20px; font-weight: 800;
        box-shadow: var(--shadow-sm); border: 1px solid transparent;
        letter-spacing: -0.01em; line-height: 1;
        transition: transform var(--transition), box-shadow var(--transition);
    }
    .chip:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
    .chip--hot  { background: #ef4444; color: #fff;     font-size: 1.02rem; box-shadow: 0 4px 12px rgba(239,68,68,0.28); }
    .chip--warm { background: #fde7e8; color: #a51b24;  font-size: 0.94rem; border-color: #f7cfd2; }
    .chip--cool { background: #fafaf8; color: #475569;  font-size: 0.88rem; border-color: #e2e8f0; }
    .chip__tag   { font-weight: 800; }
    .chip__count { background: rgba(0,0,0,0.10); border-radius: 999px; padding: 2px 8px; font-size: 0.74em; font-weight: 800; }
    .chip--hot .chip__count { background: rgba(255,255,255,0.26); }

    .section-banner {
        color: white; padding: 15px 22px;
        border-radius: var(--radius-lg) var(--radius-lg) 0 0;
        display: flex; justify-content: space-between; align-items: center;
        flex-wrap: wrap; gap: 12px;
    }
    .section-banner--dark   { background: linear-gradient(to right, #0f172a, #1e293b); }
    .section-banner--purple { background: linear-gradient(135deg, #4c1d95, #3b82f6); }
    .section-banner__title  { margin: 0; font-size: 1.4rem; letter-spacing: -0.5px; }
    .section-banner__desc   { margin: 6px 0 0; color: #94a3b8; font-size: 0.9rem; }
    .section-banner__badge  { background: rgba(255,255,255,0.12); padding: 10px 20px; border-radius: var(--radius-sm); text-align: center; flex-shrink: 0; }
    .section-banner__badge-label { font-size: 0.75rem; color: #cbd5e1; margin-bottom: 4px; }
    .section-banner__badge-value { font-size: 1.5rem; font-weight: 800; }

    .stock-grid {
        display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; padding: 14px;
        background: var(--c-bg); border: 1px solid var(--c-border); border-top: none;
        border-radius: 0 0 var(--radius-lg) var(--radius-lg);
    }
    @media (max-width: 700px) { .stock-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 560px) { .stock-grid { grid-template-columns: 1fr; } .section-banner { flex-direction: column; align-items: flex-start; } }

    .stock-card {
        position: relative; overflow: hidden;
        background: var(--c-surface); border: 1px solid var(--c-border);
        border-radius: var(--radius-md); padding: 11px 13px; box-shadow: var(--shadow-sm);
        transition: box-shadow var(--transition), transform var(--transition), border-color var(--transition);
    }
    .stock-card::before { content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: var(--c-border); transition: background var(--transition); }
    .stock-card:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }
    .stock-card:hover::before { background: var(--c-up); }
    .stock-card__top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
    .stock-card__name  { font-weight: 700; font-size: 1.02rem; color: var(--c-text-1); }
    .stock-card__rate  { font-weight: 800; font-size: 1.02rem; text-align: right; }
    .rate--up   { color: var(--c-up); }
    .rate--down { color: var(--c-down); }
    .rate--flat { color: var(--c-text-2); }
    .stock-card__bottom { display: flex; justify-content: space-between; border-top: 1px solid #f1f5f9; padding-top: 8px; }
    .stock-card__label { color: var(--c-text-2); font-size: 0.85rem; }
    .stock-card__value { font-weight: 600; color: #334155; font-size: 0.9rem; }

    .etf-table-wrap { overflow-x: auto; border: 1px solid var(--c-border); border-top: none; border-radius: 0 0 var(--radius-lg) var(--radius-lg); }
    .etf-table { width: 100%; border-collapse: collapse; font-size: 14px; background: var(--c-surface); min-width: 560px; }
    .etf-table thead tr { background: var(--c-surface); border-bottom: 2px solid var(--c-border); color: var(--c-text-2); }
    .etf-table th, .etf-table td { padding: 9px 14px; }
    .etf-table th  { font-weight: 600; white-space: nowrap; }
    .etf-table__rank   { text-align: center; width: 70px; }
    .etf-table__name   { text-align: left; }
    .etf-table__num    { text-align: right; }
    .etf-name   { font-weight: 700; color: var(--c-text-1); font-size: 1.05rem; }
    .etf-code   { font-size: 12px; color: var(--c-text-3); font-family: monospace; margin-left: 8px; }
    .rank-badge--top { background: var(--c-accent); color: white; padding: 4px 12px; border-radius: 12px; font-size: 13px; }
    .rank-badge--normal { font-size: 14px; font-weight: 700; color: var(--c-text-2); }
    .row--rank1, .row--rank2, .row--rank3 { background: #f5f3ff; }
    .row--highlight { background: #f8fafc; }
    .row--normal    { background: var(--c-surface); }

    .contributing-stocks { display: flex; gap: 10px; overflow-x: auto; padding: 6px 0 8px; scrollbar-width: none; }
    .contributing-stocks::-webkit-scrollbar { display: none; }
    .stock-chip {
        display: inline-flex; flex-direction: column; align-items: center; justify-content: center;
        background: var(--c-surface); border: 1px solid var(--c-border); border-radius: 10px;
        padding: 10px 14px; min-width: 65px; box-shadow: var(--shadow-sm); flex-shrink: 0;
        transition: transform var(--transition), box-shadow var(--transition);
    }
    .stock-chip:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
    .stock-chip__name { font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px; white-space: nowrap; }
    .stock-chip__rate { font-size: 12px; font-weight: 700; }

    .empty-state { padding: 48px; text-align: center; color: var(--c-text-2); grid-column: 1 / -1; }
    </style>
CSS;

    // ── 섹션 0: 키워드 레이더 ─────────────────────────
    echo build_keyword_radar($hot_keywords);

    // ── 섹션 1: 대형주 TOP N ──────────────────────────
    $stock_cards_html = '';
    $display_stocks   = array_slice($top_stocks, 0, $opt['stock_top']);

    if (!empty($display_stocks)) {
        foreach ($display_stocks as $stock) {
            $stock_cards_html .= build_stock_card($stock);
        }
    } else {
        $stock_cards_html = '<div class="empty-state">현재 2% 이상 급등 중인 대형주가 없습니다.</div>';
    }

    printf(
        '<section style="margin-bottom: 16px; box-shadow: var(--shadow-md); border-radius: var(--radius-lg);">
            <div class="section-banner section-banner--dark">
                <div>
                    <h2 class="section-banner__title">🔥 대형주 급등 TOP %d</h2>
                    <p class="section-banner__desc">현재 시가총액 상위 종목 중 상승세가 가장 강한 핵심 주도주입니다.</p>
                </div>
                <div class="section-banner__badge">
                    <div class="section-banner__badge-label">상승장(Bull) 지수</div>
                    <div class="section-banner__badge-value" style="color: var(--c-green);">%d%%</div>
                </div>
            </div>
            <div class="stock-grid">%s</div>
        </section>',
        $opt['stock_top'],
        $total_rising_count,
        $stock_cards_html
    );

    // ── 섹션 2: 수혜 ETF 예측 ─────────────────────────
    $etf_rows_html = '';

    if (!empty($expected_etfs)) {
        foreach ($expected_etfs as $index => $etf) {
            $rank         = $index + 1;
            $is_highlight = ($rank <= $opt['etf_highlight']);

            // 행 CSS 클래스 결정
            if ($rank <= 3) {
                $row_class = 'row--rank' . $rank;
            } elseif ($is_highlight) {
                $row_class = 'row--highlight';
            } else {
                $row_class = 'row--normal';
            }

            // 순위 배지
            $rank_badge = ($rank <= 3)
                ? '<span class="rank-badge--top">' . $rank . '위</span>'
                : '<span class="rank-badge--normal">' . $rank . '</span>';

            // 등락률 색상
            $etf_rate = (float)$etf['etf_rate'];
            if ($etf_rate > 0) {
                $rate_style = 'color: var(--c-up);';
                $rate_prefix = '▲ ';
            } elseif ($etf_rate < 0) {
                $rate_style  = 'color: var(--c-down);';
                $rate_prefix = '▼ ';
            } else {
                $rate_style  = 'color: var(--c-text-2);';
                $rate_prefix = '';
            }

            $turnover = ($etf['market_cap'] > 0)
                ? ($etf['trading_value'] / $etf['market_cap']) * 100
                : 0.0;

			  // 💡 ETF 직전 대비 변화량
            $etf_prev       = (float)($etf['etf_rate_prev'] ?? 0);
            $etf_delta_html = build_rate_delta($etf_rate, $etf_prev);

            // 하이라이트 행은 하단 테두리 제거 (기여 종목 행과 합쳐 보이도록)
            $border = $is_highlight ? 'border-bottom: none;' : '';

            // 🔗 ETF명 클릭 → etf_d2 프레임에 해당 ETF 편입종목 표시 (etf_stock.php 동일 패턴)
            $etf_ename   = urlencode($etf['etf_name']);
            $etf_js_click = "window.open('etf_stock.php?mode=ehbe&etf_code={$etf['etf_code']}&etf_name={$etf_ename}', 'etf_d2');";

            $etf_rows_html .= sprintf(
                '<tr class="%s">
                    <td class="etf-table__rank" style="%s">%s</td>
                    <td class="etf-table__name"  style="%s">
                        <a href="javascript:void(0);" onclick="%s" style="text-decoration:none; cursor:pointer;">
                            <span class="etf-name">%s</span>
                            <span class="etf-code">%s</span>
                        </a>
                    </td>
				    <td class="etf-table__num" style="%s font-weight: 700; font-size: 1.05rem; %s">%s%s%%<br>%s</td>
                    <td class="etf-table__num" style="%s font-weight: 500;">%s억</td>
                    <td class="etf-table__num" style="%s color: var(--c-text-2);">%s억</td>
                    <td class="etf-table__num" style="%s font-weight: 800; font-size: 1.05rem;">%s%%</td>
                </tr>',
                h($row_class),
                $border, $rank_badge,
                $border, h($etf_js_click), h($etf['etf_name']), h($etf['etf_code']),
				$border, $rate_style, h($rate_prefix), h(abs($etf_rate)), $etf_delta_html,
                $border, h(number_format($etf['trading_value'])),
                $border, h(number_format($etf['market_cap'])),
                $border, h(number_format($turnover, 2))
            );

            // 기여 종목 행 (하이라이트 대상만)
            if ($is_highlight) {
                $contributing = build_contributing_stocks(
                    $etf['raw_contributing_stocks'] ?? '',
                    $opt['stock_chip_max']
                );

                $etf_rows_html .= sprintf(
                    '<tr class="%s" style="border-bottom: 1px solid var(--c-border);">
                        <td></td>
                        <td colspan="5" style="padding: 0 16px 16px 0;">%s</td>
                    </tr>',
                    h($row_class),
                    $contributing
                );
            }
        }
    } else {
        $etf_rows_html = '<tr><td colspan="6" class="empty-state">조건(시가총액 등)을 만족하는 수혜 ETF를 찾을 수 없습니다.</td></tr>';
    }

    printf(
        '<section style="border-radius: var(--radius-lg); box-shadow: var(--shadow-md);">
            <div class="section-banner section-banner--purple">
                <div>
                    <h2 class="section-banner__title">🚀 급등 대형주 수혜 ETF 예측</h2>
                    <p class="section-banner__desc">상승 기여도가 높은 핵심 종목을 분석하여 수혜가 예상되는 ETF를 추천합니다.</p>
                </div>
                <div class="section-banner__badge">
                    <div class="section-banner__badge-label">추천 규모</div>
                    <div class="section-banner__badge-value" style="color: var(--c-accent-2);">TOP %d</div>
                </div>
            </div>
            <div class="etf-table-wrap">
                <table class="etf-table">
                    <thead>
                        <tr>
                            <th class="etf-table__rank">순위</th>
                            <th class="etf-table__name">ETF명</th>
                            <th class="etf-table__num">등락률</th>
                            <th class="etf-table__num">거래대금</th>
                            <th class="etf-table__num">시가총액</th>
                            <th class="etf-table__num">회전율</th>
                        </tr>
                    </thead>
                    <tbody>%s</tbody>
                </table>
            </div>
        </section>',
        $opt['etf_count'],
        $etf_rows_html
    );


    // =========================================================================
    // 🚀 [아이프레임 연동] 여기서부터 PHP 영역을 잠시 닫고, 순수 HTML/JS로 작성합니다.
    // =========================================================================

?>
    
    <script>
    // 1. PHP에서 수집한 뉴스 JSON 데이터
    const allNewsData = <?php echo $news_json ?: '[]'; ?>;

    // 2. 키워드 클릭 시 실행 (부모 창의 etf_d5 아이프레임에 바로 쓰기)
    function showRelatedNews(keyword) {
        // 해당 키워드가 포함된 뉴스 골라내기
        const filteredNews = allNewsData.filter(news => news.title.includes(keyword));
        
        // etf_d5 아이프레임 안에 들어갈 예쁜 HTML 문서를 JS로 만듭니다.
        let iframeHtml = `
            <!DOCTYPE html>
            <html lang="ko">
            <head>
                <meta charset="UTF-8">
                <style>
                    body { font-family: 'Malgun Gothic', sans-serif; background: #ffffff; padding: 20px; color: #1e293b; margin: 0; }
                    .header { border-bottom: 2px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 12px; position: sticky; top: 0; background: #fff; }
                    h4 { margin: 0; font-size: 1.1rem; color: #1e293b; }
                    ul { list-style: none; padding: 0; margin: 0; }
                    li { padding: 12px 0; border-bottom: 1px dashed #e2e8f0; line-height: 1.5; font-size: 0.95rem; }
                    a { text-decoration: none; color: #1e293b; font-weight: 600; transition: color 0.2s; display: block; }
                    a:hover { color: #3b82f6; }
                    .empty { color: #94a3b8; padding: 20px 0; text-align: center; }
                    
                    /* 스크롤바 디자인 */
                    ::-webkit-scrollbar { width: 6px; }
                    ::-webkit-scrollbar-track { background: transparent; }
                    ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
                    ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
                </style>
            </head>
            <body>
                <div class="header">
                    <h4>📌 <b style="color: #3b82f6;">#${keyword}</b> 관련 뉴스 <span style="font-size:0.9rem; color:#94a3b8;">(${filteredNews.length}건)</span></h4>
                </div>
                <ul>
        `;

        if (filteredNews.length === 0) {
            iframeHtml += `<li class="empty">관련 뉴스를 찾을 수 없습니다.</li>`;
        } else {
            // 중복 뉴스 제거 로직
            const seenLinks = new Set();
            filteredNews.forEach(news => {
                if (!seenLinks.has(news.link)) {
                    seenLinks.add(news.link);
                    // 뉴스를 클릭하면 새 창(_blank)으로 네이버 원문을 띄웁니다.
                    iframeHtml += `<li><a href="${news.link}" target="_blank">${news.title}</a></li>`;
                }
            });
        }

        iframeHtml += `</ul></body></html>`;

        // 3. 부모 창(parent)을 통해 etf_d5 아이프레임을 찾아 HTML 삽입!
        try {
            // parent = etf_stock.php 창
            const targetIframe = parent.document.querySelector('iframe[name="etf_d5"]');
            
            if (targetIframe) {
                const targetDoc = targetIframe.contentWindow.document;
                targetDoc.open();
                targetDoc.write(iframeHtml);
                targetDoc.close();
            } else {
                console.error("etf_d5 아이프레임을 찾을 수 없습니다.");
            }
        } catch(e) {
            console.error("아이프레임 접근 중 오류 발생:", e);
        }
    }
    </script>
    
    <?php
    // =========================================================================
    // 🚀 다시 PHP 영역을 열어주고 함수를 정상적으로 종료합니다.
    // =========================================================================
} 




   // =========================================================================
function daily_market_report($pdo) ########
{
    // ── 옵션 ──────────────────────────────────────────
    $bump_top = 7;   // 범프 차트에 그릴 키워드 수


// ── 등록된 날짜 목록 (드롭다운용, 최신순) ────────
    // 시장테마(장중·평일) + 주요이슈키워드(매일) 두 테이블의 날짜 합집합.
    // 주말은 시장테마 데이터가 없어도 뉴스 키워드는 존재하므로 같이 노출.
    $date_list = $pdo->query(
        "SELECT d FROM (
            SELECT check_date AS d FROM market_trend_snapshots
            UNION
            SELECT snap_date  AS d FROM news_trend_snapshots
         ) t ORDER BY d DESC"
    )->fetchAll(PDO::FETCH_COLUMN);

    // ── 날짜 결정 (없으면 가장 최근 수집일) ──────────
    $date = $_REQUEST['date'] ?? '';
    if ($date === '' || !in_array($date, $date_list, true)) {
        $date = $date_list[0] ?? date('Y-m-d');   // 최신일 (목록 첫 항목)
    }

    



    // ── ① 해당 날짜 스냅샷 (시간순) ──────────────────
    $stmt = $pdo->prepare(
        "SELECT id, check_time, source_stocks
         FROM market_trend_snapshots
         WHERE check_date = :date
         ORDER BY check_time ASC"
    );
    $stmt->execute([':date' => $date]);
    $snapshots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── 장중 데이터 없는 날(주말/휴장): 헤더 + 휴장 안내 + 주요 이슈 키워드만 표시 ──
    if (empty($snapshots)) {
        echo "<style>html{scrollbar-width:none;-ms-overflow-style:none;}html::-webkit-scrollbar{display:none;}body{margin:0;}</style>";
        echo build_daily_header($date, $date_list);
        echo "<section style='margin-bottom:24px; box-shadow:0 4px 16px rgba(0,0,0,0.08); border-radius:16px; overflow:hidden;'>"
           . "<div style='background:#f8fafc; border:1px solid #e2e8f0; border-radius:16px; padding:28px; text-align:center; color:#64748b;'>"
           . "🛌 <b>" . h($date) . "</b> 은(는) 휴장일(주말/공휴일)이라 시장 테마 데이터가 없습니다.<br>"
           . "<span style='font-size:0.88rem; color:#94a3b8;'>아래 주요 이슈 키워드(전체 뉴스 기준)는 정상 수집됩니다.</span>"
           . "</div></section>";
        echo build_news_issue_keywords($pdo, $date);
        return;
    }

    // 가로 스크롤 방지: 시장 스냅샷도 최근 8개만 사용 (범프 차트 + 급등 종목 추이 공통)
    $snapshots = array_slice($snapshots, -8);

    $snapshot_ids = array_column($snapshots, 'id');

    // ── ② 키워드 전부 ────────────────────────────────
    $ph   = implode(',', array_fill(0, count($snapshot_ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT snapshot_id, ranking, keyword, mention_count
         FROM market_trend_keywords
         WHERE snapshot_id IN ($ph)
         ORDER BY snapshot_id, ranking ASC"
    );
    $stmt->execute($snapshot_ids);
    $keyword_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── 데이터 가공 ───────────────────────────────────
    // 스냅샷별 keyword→ranking 맵, 그리고 종합 합산
    $rank_by_snap = [];   // [snapshot_id][keyword] = ranking
    $total_count  = [];   // [keyword] = 합산 mention_count
    foreach ($keyword_rows as $r) {
        $sid = $r['snapshot_id'];
        $kw  = $r['keyword'];
        $rank_by_snap[$sid][$kw] = (int)$r['ranking'];
        $total_count[$kw] = ($total_count[$kw] ?? 0) + (int)$r['mention_count'];
    }

    // 종합 상위 N개 키워드 = 범프 차트 대상
  //  arsort($total_count);
    //$bump_keywords = array_slice(array_keys($total_count), 0, $bump_top);
	// 범프 대상 = 종합 상위 N개 ∪ 마지막 스냅샷 상위 N개
    arsort($total_count);
    $by_total = array_slice(array_keys($total_count), 0, $bump_top);

    // 마지막 스냅샷에서 상위 $bump_top 안에 든 키워드
    $last_sid2 = $snapshots[count($snapshots) - 1]['id'];
    $last_top  = [];
    foreach (($rank_by_snap[$last_sid2] ?? []) as $kw => $rk) {
        if ($rk <= $bump_top) $last_top[] = $kw;
    }

    // 합집합 (순서: 종합 상위 먼저, 그다음 마지막 시점 신규)
    $bump_keywords = array_values(array_unique(array_merge($by_total, $last_top)));


// ── 급등 종목 추이 데이터 가공 ────────────────────
    // [code] => ['name'=>.., 'rates'=>[snapIdx => rate]]
    $stock_trend = [];
    foreach ($snapshots as $si => $snap) {
        $list = json_decode($snap['source_stocks'] ?? '[]', true);
        if (!is_array($list)) continue;
        foreach ($list as $st) {
            $code = $st['code'] ?? '';
            if ($code === '') continue;
            if (!isset($stock_trend[$code])) {
                $stock_trend[$code] = ['name' => $st['name'] ?? $code, 'rates' => []];
            }
            $stock_trend[$code]['rates'][$si] = (float)($st['rate'] ?? 0);
        }
    }
    // 마지막 스냅샷 등락률 기준 정렬 (없으면 0)
    $last_idx = count($snapshots) - 1;
    uasort($stock_trend, function($a, $b) use ($last_idx) {
        return ($b['rates'][$last_idx] ?? -999) <=> ($a['rates'][$last_idx] ?? -999);
    });
    // 상위 15개 종목만 표시
    $stock_trend = array_slice($stock_trend, 0, 15, true);


    echo "<style>"
       . "html { scrollbar-width: none; -ms-overflow-style: none; }"
       . "html::-webkit-scrollbar { display: none; }"
       . "body { margin: 0; }"
       . "</style>";

    // ── 범프 차트 출력 ────────────────────────────────

    echo build_daily_header($date, $date_list);
    echo build_news_issue_keywords($pdo, $date);     // 💡 ① 증권 뉴스 Hot 키워드
    echo build_bump_chart($snapshots, $rank_by_snap, $bump_keywords);  // 💡 ② 시간대별 상승 종목 연관 키워드 순위 흐름
    echo build_stock_trend_table($stock_trend, $snapshots);

}



function build_bump_chart(array $snapshots, array $rank_by_snap, array $keywords): string
{
    $n_snap = count($snapshots);
    $n_kw   = count($keywords);
    if ($n_snap === 0 || $n_kw === 0) return '';

    // ── 캔버스 좌표 설정 ──────────────────────────────

 $max_rank = 9;
    $x_start = 120; $x_end = 600;
    $y_top = 50;  $y_gap = 42;
    $W = 680;
    $H = $y_top + ($max_rank - 1) * $y_gap + 30;   // 💡 마지막 순위 + 하단 여백 30


    // x좌표: 스냅샷 인덱스 → 픽셀
    $x_of = function($i) use ($x_start, $x_end, $n_snap) {
        if ($n_snap <= 1) return $x_start;
        return $x_start + ($x_end - $x_start) * $i / ($n_snap - 1);
    };
    // y좌표: 순위(1~) → 픽셀
    $y_of = fn($rank) => $y_top + ($rank - 1) * $y_gap;

    // 색상 팔레트 (키워드별)
    $palette = ['#BA7517', '#D85A30', '#1D9E75', '#378ADD', '#993556', '#534AB7'];

    $svg  = "<svg viewBox='0 0 {$W} {$H}' xmlns='http://www.w3.org/2000/svg' role='img' style='width:100%;height:auto;'>";
    $svg .= "<desc>시간대별 키워드 순위 흐름 범프 차트</desc>";


    // 세로 격자 + 시간 라벨
    for ($i = 0; $i < $n_snap; $i++) {
        $x = round($x_of($i), 1);
        $t = substr($snapshots[$i]['check_time'], 0, 5);
		$svg .= "<line x1='{$x}' y1='{$y_top}' x2='{$x}' y2='" . ($y_top + ($max_rank) * $y_gap - $y_gap + 10) . "' stroke='#e2e8f0' stroke-width='1'/>";


        $svg .= "<text x='{$x}' y='" . ($y_top - 16) . "' text-anchor='middle' fill='#64748b' style='font-size:12px;'>" . h($t) . "</text>";
    }


// 키워드별 선 + 점
    foreach ($keywords as $ki => $kw) {
        $color = $palette[$ki % count($palette)];

        $points   = [];        // 순위권 안에 있는 시점들의 [x,y]
        for ($i = 0; $i < $n_snap; $i++) {
            $sid = $snapshots[$i]['id'];
            if (isset($rank_by_snap[$sid][$kw])) {
                $rank = $rank_by_snap[$sid][$kw];
                // 💡 표시 순위 밖(예: 7위 이하)이면 차트에 안 그림 (이탈로 간주)
                if ($rank <= $max_rank) {
                    $points[$i] = [round($x_of($i), 1), round($y_of($rank), 1)];
                }
            }
        }
        if (empty($points)) continue;

        // 연속 구간 polyline (점이 2개 이상일 때만 선)
        $poly = implode(' ', array_map(fn($p) => "{$p[0]},{$p[1]}", $points));
        $svg .= "<polyline points='{$poly}' fill='none' stroke='{$color}' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'/>";

        // 점 (단발 등장은 크게)
        $dot_r = (count($points) === 1) ? 7 : 5;
        foreach ($points as $p) {
            $svg .= "<circle cx='{$p[0]}' cy='{$p[1]}' r='{$dot_r}' fill='{$color}'/>";
        }

        // 라벨 위치 결정
        $last      = end($points);
        $last_i    = array_key_last($points);
        $is_at_end = ($last_i === $n_snap - 1);   // 마지막 점이 차트 오른쪽 끝인가

        // 키워드 클릭 → 네이버 뉴스 (증권뉴스 칩과 동일: mode=gknb)
        $kw_url  = 'etf_stock.php?mode=gknb&keyword=' . rawurlencode($kw);
        $kw_attr = "onclick=\"window.open('{$kw_url}','etf_d5')\" style='font-size:12px;font-weight:600;cursor:pointer;' "
                 . "onmouseover=\"this.style.textDecoration='underline'\" onmouseout=\"this.style.textDecoration='none'\"";

        if ($is_at_end) {
            // 오른쪽 끝 → 점 오른쪽에 라벨
            $svg .= "<text x='" . ($last[0] + 10) . "' y='" . ($last[1] + 4) . "' text-anchor='start' fill='{$color}' {$kw_attr}>" . h($kw) . "</text>";
        } else {
            // 중간에서 끝남(이탈) → 점 위쪽에 라벨, 가운데 정렬 (선 겹침 방지)
            $svg .= "<text x='{$last[0]}' y='" . ($last[1] - 12) . "' text-anchor='middle' fill='{$color}' {$kw_attr}>" . h($kw) . "</text>";
        }
    }


    // 순위 라벨 (좌측)
    for ($r = 1; $r <= $max_rank; $r++) {
        $y = round($y_of($r), 1);
        $svg .= "<text x='40' y='" . ($y + 4) . "' text-anchor='middle' fill='#94a3b8' style='font-size:11px;'>{$r}위</text>";
    }

$svg .= "</svg>";

    $banner = "<div style='background:linear-gradient(135deg, #4c1d95, #3b82f6); color:#fff; padding:24px 28px; border-radius:16px 16px 0 0;'>"
            . "<h2 style='margin:0; font-size:1.4rem; letter-spacing:-0.5px;'>📊 시간대별 상승 종목 연관 키워드 순위 흐름</h2>"
            . "<p style='margin:6px 0 0; color:#cbd5e1; font-size:0.9rem;'>개장부터 마감까지 키워드 순위가 어떻게 움직였는지 보여줍니다.</p>"
            . "</div>";

    return "<section style='margin-bottom:24px; box-shadow:0 4px 16px rgba(0,0,0,0.08); border-radius:16px;'>"
         . $banner
         . "<div style='background:#fff; border:1px solid #e2e8f0; border-top:none; border-radius:0 0 16px 16px; padding:20px 12px;'>{$svg}</div>"
         . "</section>";
}


// ── 주요 이슈 키워드(뉴스 기반) — news_trend_* 테이블에서 시간×키워드 매트릭스 생성 ──
// daily 리포트에 통합. 칩 클릭=뉴스 보기 / × 버튼=삭제(제외 등록)
function build_news_issue_keywords($pdo, string $date): string
{
    // 뉴스 스냅샷 조회 (시간순)
    $stmt = $pdo->prepare(
        "SELECT id, snap_time, article_count FROM news_trend_snapshots WHERE snap_date = :d ORDER BY snap_time ASC"
    );
    $stmt->execute([':d' => $date]);
    $snapshots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($snapshots)) return '';

    // 가로 스크롤 방지: 최근 8개 시간대만 표시 (집계는 전체 기준)
    $view_snapshots = array_slice($snapshots, -8);

    $snap_ids = array_column($snapshots, 'id');
    $ph = implode(',', array_fill(0, count($snap_ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT snapshot_id, ranking, keyword, mention_count
         FROM news_trend_keywords WHERE snapshot_id IN ($ph) ORDER BY snapshot_id, ranking"
    );
    $stmt->execute($snap_ids);
    $keyword_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_count  = [];  // [keyword] = 합산 mention_count
    $rank_by_snap = [];  // [snap_id][keyword] = ranking
    $cnt_by_snap  = [];  // [snap_id][keyword] = mention_count
    foreach ($keyword_rows as $r) {
        $sid = $r['snapshot_id'];
        $kw  = $r['keyword'];
        $rank_by_snap[$sid][$kw] = (int)$r['ranking'];
        $cnt_by_snap[$sid][$kw]  = (int)$r['mention_count'];
        $total_count[$kw] = ($total_count[$kw] ?? 0) + (int)$r['mention_count'];
    }
    arsort($total_count);

    // 제외 단어 필터
    foreach (get_db_stopwords($pdo) as $sw) {
        unset($total_count[$sw]);
    }

    $top_keywords = array_slice($total_count, 0, 15, true);
    if (empty($top_keywords)) return '';

    // ── 스타일 + 클릭 핸들러 (칩=뉴스, ×=삭제) ──
    $cur_php_js = addslashes(CUR_PHP);
    $html  = <<<CSS
    <style>
        .nik-chip   { display:inline-flex; align-items:center; gap:6px; padding:5px 12px; border-radius:20px; font-weight:700; font-size:0.85rem; cursor:pointer; }
        .nik-chip-1 { background:#ef4444; color:#fff; }
        .nik-chip-2 { background:#f97316; color:#fff; }
        .nik-chip-3 { background:#fca5a5; color:#7f1d1d; }
        .nik-chip-n { background:#f1f5f9; color:#475569; }
        .nik-badge  { background:rgba(255,255,255,0.35); border-radius:10px; padding:1px 6px; font-size:0.75em; }
        .nik-table  { width:100%; border-collapse:collapse; font-size:13px; }
        .nik-table th { background:#f8fafc; border-bottom:2px solid #e2e8f0; padding:10px 12px; text-align:center; }
        .nik-table td { border-bottom:1px solid #f1f5f9; padding:8px 12px; }
        .nik-del    { display:inline-flex; align-items:center; justify-content:center; width:18px; height:18px; margin-left:5px; border-radius:50%; background:#e2e8f0; color:#64748b; font-weight:700; font-size:13px; line-height:1; cursor:pointer; vertical-align:middle; opacity:0; transition:opacity 0.15s, background 0.15s; }
        .nik-table tr:hover .nik-del { opacity:1; }
        .nik-del:hover { background:#ef4444; color:#fff; }
    </style>
    <script>
    (function() {
        var nrPhp = '{$cur_php_js}';
        document.addEventListener('click', function(e) {
            var delEl = e.target.closest('[data-del]');
            if (delEl) {
                e.stopPropagation();
                var kw = delEl.getAttribute('data-del');
                fetch(nrPhp + '?mode=add_stopword&kw=' + encodeURIComponent(kw))
                    .then(function(r){ return r.json(); })
                    .then(function(d){
                        if (d.success) {
                            document.querySelectorAll('[data-del="' + kw + '"]').forEach(function(el){
                                var t = el.closest('tr') || el;
                                t.style.transition='opacity 0.3s'; t.style.opacity='0';
                                setTimeout(function(){ t.remove(); }, 320);
                            });
                        } else { alert('삭제 실패: ' + (d.msg || '오류')); }
                    })
                    .catch(function(){ alert('네트워크 오류'); });
                return;
            }
            var newsEl = e.target.closest('[data-news]');
            if (newsEl) {
                var kw = newsEl.getAttribute('data-news');
                window.open('etf_stock.php?mode=gknb&keyword=' + encodeURIComponent(kw), 'etf_d5');
                return;
            }
        });
    })();
    </script>
CSS;

    // ── 매트릭스 표 ──
    $thead = "<th style='text-align:left; width:180px;'>키워드</th>";
    foreach ($view_snapshots as $sn) {
        $hhmm = h(substr($sn['snap_time'], 0, 5));
        $acnt = (int)$sn['article_count'];
        $thead .= "<th style='white-space:nowrap;'>{$hhmm}"
                . "<br><span style='font-size:0.72rem; color:#94a3b8; font-weight:400;'>({$acnt}건)</span></th>";
    }

    $tbody = '';
    $rank  = 1;
    foreach ($top_keywords as $kw => $cnt) {
        if ($rank === 1)     $chip_class = 'nik-chip nik-chip-1';
        elseif ($rank === 2) $chip_class = 'nik-chip nik-chip-2';
        elseif ($rank <= 4)  $chip_class = 'nik-chip nik-chip-3';
        else                 $chip_class = 'nik-chip nik-chip-n';

        $tbody .= "<tr><td style='white-space:nowrap;'>"
                . "<span class='{$chip_class}' data-news='" . h($kw) . "' title='클릭 → 관련 뉴스 보기'>"
                . "#" . h($kw) . "<span class='nik-badge'>{$cnt}건</span></span>"
                . "<span class='nik-del' data-del='" . h($kw) . "' title='삭제(제외 등록)'>×</span></td>";

        foreach ($view_snapshots as $sn) {
            $rk = $rank_by_snap[$sn['id']][$kw] ?? null;
            if ($rk === null) {
                $tbody .= "<td style='text-align:center; color:#e2e8f0;'>·</td>";
                continue;
            }
            $size  = max(6, 22 - ($rk - 1) * 1.6);
            $color = $rk <= 3 ? '#ef4444' : ($rk <= 6 ? '#f97316' : '#3b82f6');
            $kcnt  = $cnt_by_snap[$sn['id']][$kw] ?? 0;
            $tbody .= "<td style='text-align:center;' title='" . h($kw) . " · {$rk}위 · {$kcnt}건'>"
                    . "<span style='display:inline-block; width:{$size}px; height:{$size}px; border-radius:50%; background:{$color};'></span></td>";
        }
        $tbody .= "</tr>";
        $rank++;
    }

    $banner = "<div style='background:linear-gradient(135deg, #4c1d95, #3b82f6); color:#fff; padding:24px 28px; border-radius:16px 16px 0 0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;'>"
            . "<div><h2 style='margin:0; font-size:1.4rem; letter-spacing:-0.5px;'>🎯 증권 뉴스 Hot 키워드</h2>"
            . "<p style='margin:6px 0 0; color:#cbd5e1; font-size:0.9rem;'>네이버 금융 섹션 뉴스 제목 분석 · 칩 클릭=뉴스 / × =삭제</p></div></div>";

    return "<section style='margin-bottom:24px; box-shadow:0 4px 16px rgba(0,0,0,0.08); border-radius:16px;'>"
         . $html
         . $banner
         . "<div style='background:#fff; border:1px solid #e2e8f0; border-top:none; border-radius:0 0 16px 16px; padding:20px;'>"
         . "<div style='overflow-x:auto;'><table class='nik-table'><thead><tr>{$thead}</tr></thead><tbody>{$tbody}</tbody></table></div>"
         . "</div></section>";
}

function build_daily_header(string $date, array $date_list): string
{
    // 날짜 표기: "6월 1일 리포트"
    $ts    = strtotime($date);
    $title = date('n', $ts) . '월 ' . date('j', $ts) . '일 시장 테마 리포트';

    // 드롭다운 옵션 (선택된 날짜 표시)
    $options = '';
    foreach ($date_list as $d) {
        $sel   = ($d === $date) ? ' selected' : '';
        $label = date('Y-m-d (D)', strtotime($d));   // 2026-06-01 (Mon)
        $options .= "<option value='" . h($d) . "'{$sel}>" . h($label) . "</option>";
    }

    // onchange → 같은 페이지를 ?mode=daily&date=선택값 으로 이동
    global $is_public;
    $cur   = CUR_PHP;
    $key_q = !empty($is_public) ? '&k=' . rawurlencode(DAILY_SHARE_KEY) : '';   // 공개 링크는 날짜 바꿔도 토큰 유지
    $js    = "location.href='{$cur}?mode=daily{$key_q}&date=' + this.value;";

    return "<div style='display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:18px;'>"
         . "<h1 style='margin:0; font-size:1.5rem; font-weight:800; color:#1e293b; letter-spacing:-0.5px;'>📅 " . h($title) . "</h1>"
         . "<select onchange=\"{$js}\" style='padding:8px 14px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; color:#1e293b; background:#fff; cursor:pointer; outline:none;'>"
         . $options
         . "</select>"
         . "</div>";
}

function build_stock_trend_table(array $stock_trend, array $snapshots): string
{
    if (empty($stock_trend)) return '';
    $n_snap = count($snapshots);

    // 헤더 (시간)
    $head = "<th style='text-align:left; padding:10px 8px; font-weight:600; color:#64748b;'>종목</th>";
    foreach ($snapshots as $snap) {
        $t = substr($snap['check_time'], 0, 5);
        $head .= "<th style='text-align:right; padding:10px 8px; font-weight:600; color:#64748b;'>" . h($t) . "</th>";
    }

    // 행 (종목별)
    $rows = '';
 // 행 (종목별)
    $rows = '';
    foreach ($stock_trend as $code => $s) {
        // 💡 최초 등장값 찾기 (가장 이른 시점의 rate)
        $first_rate = null;
        for ($k = 0; $k < $n_snap; $k++) {
            if (isset($s['rates'][$k])) {
                $first_rate = $s['rates'][$k];
                break;
            }
        }

        // 종목명 클릭 → 종목 뉴스 (mode=gsnb)
        $st_url = 'etf_stock.php?mode=gsnb&stock_code=' . rawurlencode($code) . '&stock_name=' . rawurlencode($s['name']);
        $rows .= "<tr style='border-top:1px solid #f1f5f9;'>";
        $rows .= "<td onclick=\"window.open('{$st_url}','etf_d5')\" style='text-align:left; padding:9px 8px; font-weight:600; color:#1e293b; cursor:pointer;' title='클릭 → 종목 뉴스 보기'>" . h($s['name']) . "</td>";

        for ($i = 0; $i < $n_snap; $i++) {
            if (isset($s['rates'][$i])) {
                $rate  = $s['rates'][$i];
                $color = $rate > 0 ? '#e1234a' : ($rate < 0 ? '#0052a4' : '#64748b');
                $sign  = $rate > 0 ? '+' : '';
                $is_last = ($i === $n_snap - 1);
                $bold  = $is_last ? 'font-weight:700;' : '';

                $cell = $sign . number_format($rate, 2);

                // 💡 마지막 칸: 최초 진입 대비 증감 표시
                if ($is_last && $first_rate !== null) {
                    $delta = $rate - $first_rate;
                    if (abs($delta) >= 0.01) {
                        $d_color = $delta > 0 ? '#e1234a' : '#0052a4';
                        $d_arrow = $delta > 0 ? '▲' : '▼';
                        $cell .= " <span style='font-size:11px; font-weight:600; color:{$d_color};'>"
                               . "({$d_arrow}" . number_format(abs($delta), 2) . ")</span>";
                    }
                }

                $rows .= "<td style='text-align:right; padding:9px 8px; color:{$color}; {$bold}'>{$cell}</td>";
            } else {
                $rows .= "<td style='text-align:right; padding:9px 8px; color:#cbd5e1;'>—</td>";
            }
        }
        $rows .= "</tr>";
    }

     $stock_count = count($stock_trend);

    $banner = "<div style='background:linear-gradient(135deg, #4c1d95, #3b82f6); color:#fff; padding:24px 28px; border-radius:16px 16px 0 0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;'>"
            . "<div>"
            . "<h2 style='margin:0; font-size:1.4rem; letter-spacing:-0.5px;'>📈 시간대별 급등 종목 추이</h2>"
            . "<p style='margin:6px 0 0; color:#cbd5e1; font-size:0.9rem;'>스냅샷별 급등 종목의 등락률 변화를 추적합니다.</p>"
            . "</div>"
            . "<div style='background:rgba(255,255,255,0.12); padding:10px 20px; border-radius:8px; text-align:center; flex-shrink:0;'>"
            . "<div style='font-size:0.75rem; color:#cbd5e1; margin-bottom:4px;'>추적 종목</div>"
            . "<div style='font-size:1.5rem; font-weight:800; color:#fde047;'>{$stock_count}개</div>"
            . "</div>"
            . "</div>";

    return "<section style='margin-bottom:24px; box-shadow:0 4px 16px rgba(0,0,0,0.08); border-radius:16px;'>"
         . $banner
         . "<div style='background:#fff; border:1px solid #e2e8f0; border-top:none; border-radius:0 0 16px 16px; padding:4px 16px; overflow-x:auto;'>"
         . "<table style='width:100%; border-collapse:collapse; font-size:13px;'>"
         . "<thead><tr>{$head}</tr></thead><tbody>{$rows}</tbody></table>"
         . "</div></section>";
}

// =========================================================================
// DB 제외단어 공통 헬퍼
// =========================================================================
function get_db_stopwords($pdo): array
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS news_stopwords (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            keyword    VARCHAR(100) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        return $pdo->query("SELECT keyword FROM news_stopwords ORDER BY keyword")
                   ->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return [];
    }
}

// =========================================================================
// 제외단어 추가 AJAX  (?mode=add_stopword&kw=삼성전자)
// =========================================================================
function news_add_stopword($pdo)
{
    header('Content-Type: application/json; charset=utf-8');
    $kw = trim($_REQUEST['kw'] ?? '');
    if ($kw === '') {
        echo json_encode(['success' => false, 'msg' => '키워드가 비어있습니다.']);
        exit;
    }
    try {
        get_db_stopwords($pdo); // 테이블 보장
        $stmt = $pdo->prepare("INSERT IGNORE INTO news_stopwords (keyword) VALUES (?)");
        $stmt->execute([$kw]);
        echo json_encode(['success' => true, 'keyword' => $kw]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// 제외단어 삭제 AJAX  (?mode=del_stopword&kw=삼성전자)
// =========================================================================
function news_del_stopword($pdo)
{
    header('Content-Type: application/json; charset=utf-8');
    $kw = trim($_REQUEST['kw'] ?? '');
    if ($kw === '') {
        echo json_encode(['success' => false, 'msg' => '키워드가 비어있습니다.']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("DELETE FROM news_stopwords WHERE keyword = ?");
        $stmt->execute([$kw]);
        echo json_encode(['success' => true, 'keyword' => $kw]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

?>