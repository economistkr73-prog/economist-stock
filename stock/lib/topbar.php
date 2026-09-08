<?php
/**
 * stock/topbar.inc — 「주식」 섹션 상단 헤더 단일 소스 (메뉴 정의 + 마크업 + CSS)
 *
 * stock/index.php 안에 있던 것을 2026-08-04 에 끄집어냈다. 이유:
 *   섹션 밖에 있지만 주식 이야기인 화면(etf_stock.php · market/report.php)이
 *   같은 헤더를 달아야 「주식 안에 들어와 있다」가 성립한다. 예전처럼 공통 네비(env/nav.inc)를 달면
 *   그 화면으로 들어가는 순간 주식 메뉴가 통째로 사라져 서로 오갈 수가 없었다.
 *
 * ★메뉴 정의는 pf_menus() 한 곳뿐이다 — 세 화면(그리고 /stock 안의 모든 화면)이 이걸 읽는다.
 *   화면을 늘리려면 여기 한 줄 + (섹션 안이면) stock/index.php 의 $routes 에 라우트 한 줄.
 *
 * 소비 방법:
 *   <head> 에서  pf_topbar_css()      · <body> 안에서  pf_topbar('<key>')
 *   다크 화면(단타)은 <body class="dt-dark"> 를 주면 어두운 팔레트로 바뀐다.
 */

require_once __DIR__ . "/fmt.php";   // pf_h()

/** 모바일 기기 판정 — 서버 $mobile(env/cnt.inc) 우선, 없으면 같은 UA 정규식으로 직접 판정.
 *  ★market/report.php 는 cnt.inc 를 안 불러 $mobile 이 없다. 폴백이 없으면 모바일에서
 *    주식ETF분석이 PC 화면(mode=ef)으로 열린다 — nav.inc 가 2026-07-01 에 겪은 그 함정이다. */
function pf_topbar_is_mobile(): bool
{
    if (isset($GLOBALS['mobile'])) return (bool)$GLOBALS['mobile'];
    return (bool)preg_match("/(Windows CE|Nokia|SonyEricsson|webOS|PalmOS|Android|Mobile|Macintosh)/",
                            $_SERVER['HTTP_USER_AGENT'] ?? '');
}

/**
 * 섹션 메뉴 정의 (단일 소스).
 * 기능이 늘어나면 여기에 한 줄 추가하고 stock/index.php 의 $routes 에 라우트만 붙이면 된다.
 */
function pf_menus(): array
{
    /* 사이트 공통 네비(env/nav.inc)에서 이리로 옮겨 온 주식계열 3화면(2026-08-04 사용자).
     * 주식 이야기는 전부 이 섹션 안에서 끝나야 해서 공통 헤더가 아니라 여기 둔다.
     * ★모바일이면 주식ETF분석은 모바일 전용 화면(mode=m) — 폭 아닌 기기(UA) 기준.
     *   nav.inc 가 하던 판정을 링크와 함께 옮겨 왔다(판정은 링크 있는 곳에 하나만 둔다). */
    $etf = pf_topbar_is_mobile() ? '/etf_stock.php?mode=m' : '/etf_stock.php?mode=ef';

    return [
        ['key' => 'etf',       'href' => $etf,                             'label' => '주식ETF분석'],
        ['key' => 'brief',     'href' => '/market/report.php',             'label' => '모닝브리핑'],
        /* 단타를 (섹션 안에서) 맨 앞에 둔 이유(2026-08-04 사용자) — 장중에 가장 먼저 여는 화면이다.
         * 포트폴리오(들고 있는 것)와 <b>다른 시간축</b>의 일이라 섞지 않고 별도 섹션으로 둔다.
         * (위 3개는 /stock 밖 화면이라 순서 기준이 다르다 — 여기부터가 /stock 안이다.) */
        ['key' => 'short',     'href' => '/stock/index.php?mode=short',   'label' => '단타'],
        /* 멀티차트는 처음 하루(2026-09-04) 단타의 하위탭이었다 — 같은 날 사용자 지시로 상단 메뉴로 올렸다
         * (「모닝브리핑·단타·멀티차트·포트폴리오」). 단타와 같은 종목 집합을 «전부 한눈에» 보는 자리라
         * 단타 «안»이 아니라 단타 «옆»이다. 하위탭 구역('short')은 함께 걷어냈다(탭 하나짜리 줄은 소음). */
        ['key' => 'multi',     'href' => '/stock/index.php?mode=multi',   'label' => '멀티차트'],
        ['key' => 'dashboard', 'href' => '/stock/index.php',              'label' => '포트폴리오'],
        ['key' => 'sim',       'href' => '/stock/index.php?mode=sim',     'label' => '시뮬레이터'],
        ['key' => 'fund',      'href' => '/stock/index.php?mode=fund',    'label' => '재무분석'],
        ['key' => 'quant',     'href' => '/stock/index.php?mode=quant',   'label' => '퀀트'],
        // 설정을 누르면 첫 하위탭인 '포트폴리오 관리'로 들어간다 (수수료 설정은 mode=setting).
        ['key' => 'setting',   'href' => '/stock/index.php?mode=portfolio', 'label' => '설정'],
    ];
}

/** 주식 섹션 전용 상단 헤더 (사이트 공통 네비와 분리) */
function pf_topbar(string $active): void
{
    global $current_user;
    $user = $current_user ?? ($_SESSION['usr_name'] ?? '');

    echo '<header class="pf-topbar">';
    echo '<a class="pf-brand" href="/stock/index.php"><span class="pf-brand-ico">📈</span> 주식 포트폴리오</a>';

    echo '<nav class="pf-menu">';
    foreach (pf_menus() as $m) {
        $cls = ($m['key'] === $active) ? ' class="on"' : '';
        echo '<a href="' . pf_h($m['href']) . '"' . $cls . '>' . pf_h($m['label']) . '</a>';
    }
    echo '</nav>';

    echo '<div class="pf-topbar-right">';
    if ($user !== '') echo '<span class="pf-uname">' . pf_h($user) . '님</span>';
    echo '<a class="pf-tb-link" href="/">메인</a>';
    echo '<a class="pf-tb-link pf-logout" href="/logout.php">로그아웃</a>';
    echo '</div></header>';
}

/**
 * 헤더 전용 CSS.
 *
 * ★/stock 안에서는 pf_css() 가 이 함수를 불러 쓴다 — 규칙을 두 벌 적지 않기 위함이다.
 *   (섹션 밖 화면은 pf_css() 전체가 필요 없다. 그 화면들의 본문 스타일을 덮어쓰면 안 되므로
 *    여기에는 헤더 규칙만 들어간다 — body·표·입력칸 같은 공통 규칙을 절대 넣지 말 것.)
 */
function pf_topbar_css(): void
{
    echo <<<'CSS'
<style>
/* ── 주식 섹션 전용 헤더 (stock/topbar.inc 단일 소스) ── */
.pf-topbar{background:linear-gradient(90deg,#123c63,#1d5c93);color:#fff;display:flex;align-items:center;
  gap:18px;height:58px;box-shadow:0 2px 10px rgba(10,30,50,.2);position:sticky;top:0;z-index:30;
  box-sizing:border-box;flex:0 0 auto;
  /* 배경은 화면 전체, 내용은 본문과 같은 폭으로 가운데 정렬 */
  padding:0 max(20px, calc((100% - 2200px) / 2))}
.pf-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:800;letter-spacing:-.02em;
  display:flex;align-items:center;gap:8px;white-space:nowrap}
.pf-brand:hover{opacity:.92}
.pf-brand-ico{font-size:19px}
.pf-menu{display:flex;gap:3px;flex:1;overflow-x:auto;scrollbar-width:none}
.pf-menu::-webkit-scrollbar{display:none}
.pf-menu a{color:#d7e6f4;text-decoration:none;font-size:14px;font-weight:700;padding:8px 14px;
  border-radius:8px;white-space:nowrap}
.pf-menu a:hover{background:rgba(255,255,255,.13);color:#fff}
.pf-menu a.on{background:#fff;color:#12406b}
.pf-topbar-right{display:flex;align-items:center;gap:9px;font-size:13px;white-space:nowrap}
.pf-uname{color:#bcd6ec;font-weight:700}
.pf-tb-link{color:#e6f0f9;text-decoration:none;font-weight:700;padding:6px 11px;border-radius:7px}
.pf-tb-link:hover{background:rgba(255,255,255,.16)}
.pf-logout{background:rgba(255,255,255,.13)}

/* 다크 화면(단타)이 body 에 dt-dark 클래스를 줘서 켠다.
   --accent 를 안 정의한 화면에서도 깨지지 않게 기본값을 함께 준다.
   ★이 파일의 CSS·주석에 "<"+"body" 같은 태그 글자를 적지 말 것 — market/report.php 가
     이 CSS 를 HTML 문자열에 정규식으로 끼워 넣는데, 그 글자가 진짜 body 태그로 잡힌다
     (2026-08-04 실제로 모닝브리핑 헤더가 통째로 head 안에 박혀 사라졌다). */
body.dt-dark .pf-topbar{background:linear-gradient(90deg,#0b1220,#16203a);box-shadow:none;
  border-bottom:1px solid var(--line,#26304a);flex:0 0 58px}
body.dt-dark .pf-menu a{color:#93a3bd}
body.dt-dark .pf-menu a:hover{background:rgba(255,255,255,.08);color:#fff}
body.dt-dark .pf-menu a.on{background:var(--accent,#d9a441);color:#0b1020}

@media (max-width:860px){
  .pf-topbar{height:auto;flex-wrap:wrap;gap:8px;padding:9px 12px}
  .pf-brand{font-size:16px}
  .pf-menu{order:3;width:100%;flex:none}
  .pf-topbar-right{margin-left:auto}
  .pf-uname{display:none}
}
</style>
CSS;
}
?>
