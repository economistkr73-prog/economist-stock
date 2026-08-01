<?php
/**
 * stock/index.php — 주식 포트폴리오(분할매수) 라우터 + 화면
 *
 * 요건정의서 §4 화면 명세 구현. 계산은 전부 stock/lib/calc.php 에 위임하고
 * 여기서는 조회·조립·렌더만 한다. DB 는 classes/Pf.class.
 *
 * 이 섹션은 사이트 공통 네비(env/nav.inc)를 쓰지 않고 전용 헤더를 갖는다.
 * 메뉴 추가는 pf_menus() 한 곳만 고치면 된다.
 *
 * mode : dashboard(기본) | position | ruleset | portfolio | price
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/cnt.inc';
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/auth_fnc.php';
require_once __DIR__ . '/lib/calc.php';
require_once __DIR__ . '/lib/fmt.php';
require_once __DIR__ . '/lib/sim.php';
require_login();

/* 스크리너가 화면에 뿌리는 최대 행수 (거르기·정렬은 전체 모집단에서 끝난 뒤라 표시에만 걸린다).
 * ★ 라우터보다 <b>위에</b> 둬야 한다 — 함수와 달리 const 는 끌어올려지지 않아서,
 *   파일 아래쪽에 두면 라우터가 부른 화면 함수가 선언 전에 쓰게 되어 죽는다. */
const PF_FUND_TOP = 50;

/** 룰셋 차수 편집 표의 금액 미리보기 기준 한도 (입력칸을 없앤 대신 값을 고정해 밝힌다).
 *  ★ 위와 같은 이유로 <b>라우터보다 위</b>에 둔다 — const 는 끌어올려지지 않는다. */
const PF_RULE_PREVIEW_LIMIT = 200000000;

$pf = new Pf($pdo);
$pf->ensureTables();
$pf->seedRuleSets();
$pf->seedPortfolio();
$pf->seedMarkets();

$mode = $_GET['mode'] ?? 'dashboard';

$routes = [
    'dashboard' => 'pf_page_dashboard',   // 포트폴리오 목록 + 오늘의 신호
    'all'       => 'pf_page_all',         // 보유종목 — 전 포트폴리오 종목을 한 표에
    'hist'      => 'pf_page_hist',        // 매매히스토리 — 체결 뒤 가격이 어떻게 됐나
    'folio'     => 'pf_page_folio',       // 포트폴리오 상세 (종목 목록)
    'position'  => 'pf_page_position',    // 종목 상세
    'sim'       => 'pf_page_sim',         // 백테스트 시뮬레이터
    'simcy'     => 'pf_page_simcy',       // 시뮬레이터 — 전 종목 사이클 분석 (성공/물림)
    'fund'      => 'pf_page_fund',        // 재무분석 (DART 스크리너)
    'earn'      => 'pf_page_earn',        // 재무분석 > 어닝 서프라이즈 (최근 공시 SUE 목록)
    'earncase'  => 'pf_page_earncase',    // 재무분석 > 서프라이즈 사례 — 성공·실패 실사례 차트

    'watch'     => 'pf_page_watch',       // 관심종목 (재무분석에서 담아 둔 것)
    'quant'     => 'pf_page_quant',       // 퀀트 > 최고 거래대금 (krx_amt 전종목 원장)
    'pattern'   => 'pf_page_pattern',     // 퀀트 > 패턴분석 — 5개 패턴을 실제 차트로 (정의·특징·사례)
    'quantstat' => 'pf_page_quantstat',   // 퀀트 > 검증 — 신호가 수익으로 이어졌나 (백테스트 보고서)
    'portfolio' => 'pf_page_portfolio',   // 설정 > 포트폴리오
    'chart'     => 'pf_page_chart',       // 설정 > 차트 (차트 갤러리 embed)
    'setting'   => 'pf_page_setting',     // 설정 > 수수료
    'ruleset'   => 'pf_page_ruleset',     // 설정 > 룰셋
];

if (isset($routes[$mode]) && function_exists($routes[$mode])) {
    $routes[$mode]($pdo, $pf);
} else {
    pf_page_dashboard($pdo, $pf);
}

// ══════════════════════════════════════════════════════════════════════
//  전용 헤더 + 공통 레이아웃
// ══════════════════════════════════════════════════════════════════════
/**
 * 섹션 메뉴 정의 (단일 소스).
 * 기능이 늘어나면 여기에 한 줄 추가하고 $routes 에 라우트만 붙이면 된다.
 */
function pf_menus(): array
{
    return [
        ['key' => 'dashboard', 'href' => '/stock/index.php',              'label' => '포트폴리오'],
        ['key' => 'sim',       'href' => '/stock/index.php?mode=sim',     'label' => '시뮬레이터'],
        ['key' => 'fund',      'href' => '/stock/index.php?mode=fund',    'label' => '재무분석'],
        ['key' => 'quant',     'href' => '/stock/index.php?mode=quant',   'label' => '퀀트'],
        // 설정을 누르면 첫 하위탭인 '포트폴리오 관리'로 들어간다 (수수료 설정은 mode=setting).
        ['key' => 'setting',   'href' => '/stock/index.php?mode=portfolio', 'label' => '설정'],
    ];
}

/**
 * 상단 메뉴의 하위 탭. 섹션마다 다르다.
 *
 * 관심종목을 최상위에 두지 않고 재무분석 아래로 넣은 이유:
 * 스크리너로 <b>찾고</b> ☆ 로 <b>담는</b> 것이 한 흐름이라, 두 화면이 같은 자리에 있어야 오가기 쉽다.
 */
function pf_sub_menus(string $section = 'setting'): array
{
    $map = [
        /* 세 화면이 서로 다른 시점을 본다:
         *   현황       지금 — 포트폴리오 단위로 묶어서
         *   보유종목   지금 — 그 묶음을 풀어 종목을 한 줄로
         *   매매히스토리 지나간 것 — 체결한 뒤 가격이 어떻게 됐나 (판단의 되짚기) */
        'dashboard' => [
            ['key' => 'current', 'href' => '/stock/index.php',           'label' => '현황'],
            ['key' => 'all',     'href' => '/stock/index.php?mode=all',  'label' => '보유종목'],
            ['key' => 'hist',    'href' => '/stock/index.php?mode=hist', 'label' => '매매히스토리'],
        ],
        'fund' => [
            ['key' => 'screener', 'href' => '/stock/index.php?mode=fund',      'label' => '스크리너'],
            ['key' => 'earn',     'href' => '/stock/index.php?mode=earn',      'label' => '어닝 서프라이즈'],
            ['key' => 'earncase', 'href' => '/stock/index.php?mode=earncase',  'label' => '사례분석'],
            ['key' => 'watch',    'href' => '/stock/index.php?mode=watch',     'label' => '관심종목'],
        ],
        /* 퀀트: 목록(오늘 뜬 것)과 검증(그 신호가 수익이 됐나)을 나란히 —
         * 배지의 근거가 한 클릭 안에 있어야 배지를 믿고 쓸 수 있다. */
        'quant' => [
            ['key' => 'surge',   'href' => '/stock/index.php?mode=quant',     'label' => '최고 거래대금'],
            ['key' => 'pattern', 'href' => '/stock/index.php?mode=pattern',   'label' => '패턴분석'],
            ['key' => 'stat',    'href' => '/stock/index.php?mode=quantstat', 'label' => '검증 (백테스트)'],
        ],
        'setting' => [
            ['key' => 'portfolio', 'href' => '/stock/index.php?mode=portfolio', 'label' => '포트폴리오 관리'],
            ['key' => 'chart',     'href' => '/stock/index.php?mode=chart',     'label' => '차트 설정'],
            ['key' => 'ruleset',   'href' => '/stock/index.php?mode=ruleset',   'label' => '룰셋 설정'],
            ['key' => 'fee',       'href' => '/stock/index.php?mode=setting',   'label' => '수수료 설정'],
        ],
    ];
    return $map[$section] ?? [];
}

function pf_subtabs(string $active, string $section = 'setting'): void
{
    if (!empty($_GET['embed'])) return;   // 우측 패널(iframe)에서는 본문만 — pf_head 의 embed 가드와 짝
    $menus = pf_sub_menus($section);
    if (!$menus) return;

    echo '<div class="pf-sub">';
    foreach ($menus as $m) {
        $cls = ($m['key'] === $active) ? ' class="on"' : '';
        echo '<a href="' . pf_h($m['href']) . '"' . $cls . '>' . pf_h($m['label']) . '</a>';
    }
    echo '</div>';
}

/** @param string $width '' 기본(1440) | 'wide' 대시보드(2200) | 'narrow' 설정류(1100) */
function pf_head(string $title, string $active = 'dashboard', string $width = ''): void
{
    echo '<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8">';
    echo '<title>' . pf_h($title) . ' · 주식 포트폴리오</title>';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.css"/>';
    pf_css();
    pf_comma_js();
    echo '</head><body>';

    /* ★ embed=1 — 다른 화면의 우측 패널(iframe)에 얹힐 때: 상단바·서브탭 없이 본문만 그린다.
     * 퀀트 목록이 이 모드로 <b>재무분석 화면을 그대로</b> 띄운다 — 마크업을 복제하지 않으므로
     * 재무분석을 고치면 패널에도 저절로 반영된다. (pf_subtabs 도 같은 가드를 본다)
     * iframe 안에서 링크·폼으로 움직여도 embed 가 유지되게 embed=1 을 이어 붙인다. */
    if (!empty($_GET['embed'])) {
        echo '<style>.pf-wrap{margin-top:10px}</style>';
        echo <<<'JS'
<script>
document.addEventListener('click', function(e){
  var a = e.target.closest ? e.target.closest('a') : null;
  if (a && a.href && a.href.indexOf('/stock/index.php') >= 0
        && a.href.indexOf('embed=1') < 0 && !a.getAttribute('target')) {
    a.href += (a.href.indexOf('?') >= 0 ? '&' : '?') + 'embed=1';
  }
}, true);
document.addEventListener('submit', function(e){
  var f = e.target;
  if (f && f.action && f.action.indexOf('/stock/index.php') >= 0 && !f.querySelector('[name=embed]')) {
    var i = document.createElement('input');
    i.type = 'hidden'; i.name = 'embed'; i.value = '1';
    f.appendChild(i);
  }
}, true);
</script>
JS;
    } else {
        pf_topbar($active);
    }
    echo '<div class="pf-wrap' . ($width !== '' ? ' ' . $width : '') . '">';
}

/** 주식 포트폴리오 전용 상단 헤더 (사이트 공통 네비와 분리) */
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

function pf_foot(): void
{
    echo '</div></body></html>';
}

/**
 * 숫자 입력칸 천단위 콤마 — 전 화면 공용 (head 에서 한 번만 출력).
 *
 * 금액·가격·수량 입력칸은 <input type="text" class="num-comma" inputmode="numeric"> 로 만든다
 * (type="number" 는 콤마를 아예 못 넣는다). 입력 즉시 콤마를 붙이고,
 * 서버는 콤마째 받은 값을 걷어 읽는다(api.php pf_money() 등 — 새 소비처도 반드시 걷을 것).
 * ★ capture 단계에 건다 — 각 화면의 bubble 리스너(미리보기 재계산 등)가 값을 읽기 전에
 *   포맷이 먼저 끝나 있어야 한다. 소수점은 보존한다(수정주가 등 DECIMAL 값).
 */
function pf_comma_js(): void
{
    echo <<<'JS'
<script>
function pfComma(el){
  var v = el.value.replace(/[^\d.]/g, '');
  if (v === '') { el.value = ''; return; }
  var p = v.split('.');
  el.value = (p[0] ? Number(p[0]).toLocaleString() : '0')
           + (p.length > 1 ? '.' + p.slice(1).join('') : '');
}
document.addEventListener('input', function(e){
  if (e.target.classList && e.target.classList.contains('num-comma')) pfComma(e.target);
}, true);
document.addEventListener('DOMContentLoaded', function(){
  document.querySelectorAll('.num-comma').forEach(pfComma);
});
</script>
JS;
}

function pf_css(): void
{
    echo <<<'CSS'
<style>
*,*::before,*::after{box-sizing:border-box}
body{font-family:'Pretendard','Malgun Gothic',sans-serif;background:#f0f2f5;color:#22303f;margin:0}

/* ── 주식 포트폴리오 전용 헤더 ── */
.pf-topbar{background:linear-gradient(90deg,#123c63,#1d5c93);color:#fff;display:flex;align-items:center;
  gap:18px;height:58px;box-shadow:0 2px 10px rgba(10,30,50,.2);position:sticky;top:0;z-index:30;
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
.pf-sub{display:flex;gap:5px;margin-bottom:14px;border-bottom:1px solid #dfe7ee;padding-bottom:0}
.pf-sub a{padding:8px 16px;font-size:14px;font-weight:700;color:#7d8b99;text-decoration:none;
  border-radius:8px 8px 0 0;margin-bottom:-1px}
.pf-sub a:hover{background:#eef4fa;color:#12406b}
.pf-sub a.on{background:#fff;color:#12406b;border:1px solid #dfe7ee;border-bottom-color:#fff;box-shadow:inset 0 2px 0 #1d5c93}
.pf-topbar-right{display:flex;align-items:center;gap:9px;font-size:13px;white-space:nowrap}
.pf-uname{color:#bcd6ec;font-weight:700}
.pf-tb-link{color:#e6f0f9;text-decoration:none;font-weight:700;padding:6px 11px;border-radius:7px}
.pf-tb-link:hover{background:rgba(255,255,255,.16)}
.pf-logout{background:rgba(255,255,255,.13)}

.pf-wrap{max-width:1440px;margin:0 auto;padding:18px 14px 70px}
/* 대시보드 — 넓게 쓰되 초고해상도에서 과하게 퍼지지 않게 상한을 둔다 */
.pf-wrap.wide{max-width:2200px;padding:14px 16px 40px}
/* 설정류 — 입력 위주라 좁게 */
.pf-wrap.narrow{max-width:1100px}
.pf-head{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;margin-bottom:14px}
.pf-head h1{font-size:21px;margin:0;letter-spacing:-.02em}
.pf-head .sub{color:#7d8b99;font-size:13px;margin-top:4px}
.pf-head .act{display:flex;gap:7px;flex-wrap:wrap}

.btn{border:none;cursor:pointer;border-radius:7px;font-size:13px;font-weight:700;padding:8px 14px;text-decoration:none;display:inline-block;line-height:1.3}
.btn-primary{background:#1d5c93;color:#fff}
.btn-primary:hover{background:#164a78}
.btn-outline{background:#fff;border:1px solid #c9d4de;color:#3c4d5e}
.btn-outline:hover{background:#eef3f7}
/* 선택 상태 — .btn-outline 이 뒤에 선언돼 배경을 이겨버리므로 합성 선택자로 눌러 준다 (160/240/주봉 토글) */
.btn-outline.btn-primary{background:#1d5c93;border-color:#1d5c93;color:#fff}
.btn-outline.btn-primary:hover{background:#164a78}
.btn-danger{background:#fdecea;color:#d64535}
.btn-danger:hover{background:#fadbd8}
.btn-sm{padding:5px 10px;font-size:12px;border-radius:6px}

.card{background:#fff;border:1px solid #e3eaf0;border-radius:11px;padding:15px 16px;margin-bottom:14px;box-shadow:0 1px 3px rgba(20,40,60,.05)}
.card h2{font-size:15px;margin:0 0 11px;letter-spacing:-.01em}
.muted{color:#8b98a5}
.right{text-align:right}
.center{text-align:center}

table.pf{width:100%;border-collapse:collapse;font-size:13px;background:#fff}
table.pf th,table.pf td{padding:7px 8px;border-bottom:1px solid #eef2f6;white-space:nowrap}
table.pf thead th{background:#f5f8fb;color:#5f7183;font-weight:700;font-size:12px;border-bottom:1px solid #dfe7ee}
table.pf tbody tr:hover{background:#f8fbfe}
table.pf td.num,table.pf th.num{text-align:right;font-variant-numeric:tabular-nums}
table.pf tfoot td{background:#f5f8fb;font-weight:800;border-top:2px solid #d9e3ec;border-bottom:none}
.tbl-scroll{overflow-x:auto;border:1px solid #e3eaf0;border-radius:10px;background:#fff}

.up{color:#d32f2f;font-weight:700}
.down{color:#1565c0;font-weight:700}
.flat{color:#8b98a5}
.chg{font-size:11px;font-weight:700;margin-left:3px}
td.hit{background:#ffe9e6;border-radius:4px}
td.hit span{color:#c62828;font-weight:800}

/* ── 종목 리스트(table.pf.pos) — 행을 2줄로 쓴다 ──────────────────────
 * 종목명을 키우고 코드를 아래로 내리면 이름이 먼저 읽힌다.
 * 그러면 행이 어차피 2줄이 되므로, 차수의 "+N" 도 옆이 아니라 아래에 쌓아
 * 칸 폭을 전혀 늘리지 않는다. */
table.pf.pos td{padding:11px 8px}
table.pf.pos td.stk{line-height:1.25}
table.pf.pos td.stk a{display:block;font-size:14.5px;font-weight:700;color:#22303f;
  text-decoration:none;letter-spacing:-.015em}
table.pf.pos td.stk a:hover{color:#1d5c93}
table.pf.pos td.stk .code{display:block;margin-top:2px;font-size:11.5px;font-weight:600;color:#9aa7b4}
table.pf.pos td.step{line-height:1.2}
table.pf.pos td.step .no{display:block;font-weight:700}
/* 그 차수에 아직 남은 매수 몫 — 매수 신호와 같은 붉은 계열로 묶는다 */
table.pf.pos td.step .more{display:block;margin-top:2px;font-size:11px;font-weight:800;
  color:#c62828;cursor:help}
tr.watch td.stk a{color:#8b98a5}

/* ── 좌우 분할 (좌: 대시보드 / 우: 포트폴리오 상세) — 두 영역이 동급, 우측은 맨 위부터 ── */
.split{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.15fr);gap:16px;align-items:start}
@media(max-width:1400px){.split{grid-template-columns:1fr}}
.split-left{min-width:0}
.split-left .card:last-of-type{margin-bottom:0}

/* 리스트 행 선택 */
table.pf tbody tr.folio-row{cursor:pointer}
table.pf tbody tr.folio-row:hover{background:#f2f8fd}
table.pf tbody tr.folio-row.on{background:#eaf3fc;box-shadow:inset 3px 0 0 #1d5c93}
table.pf tbody tr.folio-row.on td{font-weight:700}

/* 오른쪽 상세 — 화면 맨 위부터 시작해 세로 전체를 쓰고, 길면 내부에서 스크롤 */
.split-right{position:sticky;top:14px;min-width:0}
.folio-detail{background:#fff;border:1px solid #e3eaf0;border-radius:12px;overflow:hidden;
  box-shadow:0 2px 10px rgba(20,40,60,.07);display:flex;flex-direction:column;
  height:calc(100vh - 86px);transition:opacity .12s}
.folio-detail.loading{opacity:.45}
@media(max-width:1400px){
  .split-right{position:static}
  .folio-detail{height:auto;max-height:none}
}

.fd-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;
  padding:13px 16px;background:linear-gradient(90deg,#123c63,#1d5c93);color:#fff;flex:0 0 auto}
.fd-title{font-size:17px;font-weight:800;letter-spacing:-.02em}
.fd-sub{font-size:12px;color:#bcd6ec;margin-top:4px}
.fd-note{font-size:12px;line-height:1.55;color:#dceaf7;margin-top:7px;max-width:640px;
  background:rgba(255,255,255,.09);border-left:3px solid rgba(255,255,255,.34);border-radius:0 6px 6px 0;padding:6px 10px}
.note-brief{font-size:11px;color:#9aa7b4;margin-top:2px;max-width:240px;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.fd-body{padding:13px 15px;overflow-y:auto;flex:1 1 auto;min-height:0}
.fd-empty{padding:80px 20px;text-align:center;color:#9aa7b4;font-size:14px}
.fd-sum{grid-template-columns:repeat(auto-fit,minmax(104px,1fr));gap:8px;margin-bottom:12px}
.fd-sum .sum-box{padding:8px 10px}
.fd-sum .sum-box .v{font-size:15px}

/* 분할 화면에서는 표를 조금 더 촘촘하게 — 가로 스크롤을 최대한 피한다 */
.split table.pf th,.split table.pf td{padding:6px 6px}
.split table.pf thead th{font-size:11px}
.split .sum-grid{grid-template-columns:repeat(auto-fit,minmax(126px,1fr));gap:8px}
.split .sum-box{padding:9px 11px}
.split .sum-box .v{font-size:16px}

/* ── 포트폴리오 카드 ── */
.folio{background:#fff;border:1px solid #e3eaf0;border-radius:12px;padding:14px 15px;margin-bottom:13px;
  box-shadow:0 1px 3px rgba(20,40,60,.05)}
.folio.off{opacity:.6}
.folio-head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;
  padding-bottom:11px;margin-bottom:11px;border-bottom:1px solid #eef2f6}
.folio-title{font-size:16px;font-weight:800;color:#12406b;letter-spacing:-.02em}
.folio-meta{font-size:12px;color:#7d8b99;margin-top:5px;display:flex;gap:14px;flex-wrap:wrap}
.folio-meta b{color:#3c4d5e;font-weight:700}
.folio-right{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.folio-stat{display:flex;gap:16px}
.folio-stat .st{text-align:right}
.folio-stat .st .k{font-size:11px;color:#8b98a5;font-weight:700}
.folio-stat .st .v{font-size:15px;font-weight:800;font-variant-numeric:tabular-nums;margin-top:2px}
.folio-empty{padding:20px 14px;text-align:center;color:#8b98a5;font-size:13px;
  background:#fafcfe;border:1px dashed #d5e1ea;border-radius:9px}
.folio-empty a{color:#1d5c93;font-weight:700}
tr.watch td{color:#9aa7b4}
tr.watch td .up,tr.watch td .down{color:#9aa7b4}

.badge{display:inline-block;padding:1px 7px;border-radius:20px;font-size:11px;font-weight:800}
.st-watch{background:#eceff2;color:#78868f}
.st-open{background:#e3f0fd;color:#1565c0}
.st-closed{background:#f0eef2;color:#7a7285}

.sum-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:14px}
.sum-box{background:#fff;border:1px solid #e3eaf0;border-radius:10px;padding:11px 13px}
.sum-box .k{font-size:12px;color:#8b98a5;font-weight:700}
.sum-box .v{font-size:18px;font-weight:800;margin-top:3px;font-variant-numeric:tabular-nums}
.sum-box .s{font-size:11px;color:#a3aeb9;margin-top:2px}

form.inline{display:inline}
input[type=text],input[type=number],input[type=date],select,textarea{
  font-family:inherit;font-size:13px;padding:6px 9px;border:1px solid #cfdae4;border-radius:6px;background:#fff;color:#22303f}
input[type=number]{text-align:right}
input:focus,select:focus,textarea:focus{outline:none;border-color:#1d5c93;box-shadow:0 0 0 2px rgba(29,92,147,.14)}
/* 입력을 감싸면 label, 버튼 등 다른 조작이 들어가면 div — 겉모습은 같다 */
label.fld,div.fld{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:700;color:#5f7183}
.fld-row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}

.chart-box{background:#fff;border:1px solid #e3eaf0;border-radius:11px;padding:12px;height:330px}
.chart-legend{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:8px;font-size:12px;color:#5f7183}
.cl-item{display:inline-flex;align-items:center;gap:5px;font-weight:600}
.cl-item i{width:14px;height:3px;border-radius:2px;display:inline-block}
/* 일봉 범례 글리프 — 상승 빨강 / 하락 파랑 봉 두 개를 반반 (선 글리프와 구별된다) */
.cl-item i.cl-candle{width:11px;height:11px;border-radius:1px;
  background:linear-gradient(90deg,#d32f2f 0 50%,#1565c0 50% 100%)}
.cl-item b{font-variant-numeric:tabular-nums;color:#22303f}
.warn{background:#fff8e6;border:1px solid #f3dfa8;color:#8a6d1f;padding:9px 12px;border-radius:8px;font-size:13px;font-weight:600;margin-bottom:12px}
.err{background:#fdecea;border:1px solid #f5c6c0;color:#b3261e;padding:9px 12px;border-radius:8px;font-size:13px;font-weight:600;margin-bottom:12px}
.ok{background:#e9f6ec;border:1px solid #b9e0c3;color:#1e6b33;padding:9px 12px;border-radius:8px;font-size:13px;font-weight:600;margin-bottom:12px}

/* ── 오늘의 신호 스트립 ────────────────────────────────────────────────
 * 포트폴리오를 하나씩 클릭해야 신호를 볼 수 있던 문제를 없애는 자리 — 화면 맨 위 전폭.
 *
 * ★ 색은 등락색(빨강=상승/파랑=하락)과 <b>겹치지 않게</b> 고른다.
 *   여기 색이 뜻하는 것은 손익 방향이 아니라 <b>해야 할 행동</b>이다.
 *   파랑을 매도에 쓰면 "손실" 로 읽히고 빨강을 상승으로 읽으면 매수와 충돌한다.
 *     매수 #c62828  ← 기존 td.hit·차수 +N 배지와 같은 붉은 계열(이미 매수 신호 색이다)
 *     잔여 #b96600  ← 같은 붉은 계열의 옆칸(주황). 매수의 부분집합이라 계열을 안 벗긴다
 *     매도 #0f8a5f  ← 등락색 어느 쪽도 아닌 초록. "목표 달성" 을 뜻한다
 * ★ .warn/.ok 는 이미 알림상자 클래스라 여기서 재사용하면 배경·여백이 딸려온다 → short/fine 로 쓴다 */
.sig-strip{background:#fff;border:1px solid #e3eaf0;border-radius:11px;padding:12px 14px;
  margin-bottom:14px;box-shadow:0 1px 3px rgba(20,40,60,.05)}
.sig-strip.quiet{background:#fafbfc}
.sig-hd{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px}
.sig-hd h2{font-size:15px;margin:0;letter-spacing:-.01em}
.sig-strip.quiet .sig-hd{margin-bottom:0}
.sig-pill{font-size:11.5px;font-weight:800;border-radius:999px;padding:3px 9px;line-height:1.35;white-space:nowrap}
.sig-cash{margin-left:auto;font-size:12px;color:#7d8b99;font-variant-numeric:tabular-nums}
.sig-cash b{color:#3c4d5e}
.sig-cash b.short{color:#c62828}
.sig-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(238px,1fr));gap:9px}
.sig-card{display:block;text-decoration:none;color:#22303f;background:#fcfdfe;
  border:1px solid #e3eaf0;border-left-width:4px;border-radius:9px;padding:9px 11px}
.sig-card:hover{background:#f4f9ff;border-color:#bcd3e8}
.sig-top{display:flex;align-items:center;gap:6px;margin-bottom:5px}
.sig-folio{font-size:11px;font-weight:700;color:#6c7c8c;background:#eef3f7;border-radius:5px;
  padding:2px 6px;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sig-name{font-size:14.5px;font-weight:800;letter-spacing:-.015em}
.sig-name .step{font-size:11.5px;font-weight:700;color:#7d8b99;margin-left:4px}
.sig-act{font-size:13px;margin-top:4px;font-variant-numeric:tabular-nums;color:#3c4d5e}
.sig-act b{font-size:15.5px;font-weight:800}
.sig-meta{font-size:11.5px;color:#8b98a5;margin-top:3px;font-variant-numeric:tabular-nums}
.sig-fund{font-size:11.5px;font-weight:800;margin-top:5px;font-variant-numeric:tabular-nums}
.sig-fund.fine{color:#0f8a5f}
.sig-fund.short{color:#c62828}
.sig-none{font-size:13px;color:#7d8b99}
/* 종류별 색 — 배지·카드 왼쪽 띠가 같은 값을 쓴다 */
.k-sell.sig-pill,.k-sell.sig-dot{background:#e6f5ee;color:#0f8a5f}
.k-buy.sig-pill,.k-buy.sig-dot{background:#fdeaea;color:#c62828}
.k-fill.sig-pill,.k-fill.sig-dot{background:#fdf1e0;color:#b96600}
.k-wait.sig-pill{background:#eef2f6;color:#8b98a5}
.sig-card.k-sell{border-left-color:#0f8a5f}
.sig-card.k-buy{border-left-color:#c62828}
.sig-card.k-fill{border-left-color:#e07c00}
.sig-card .sig-act b.k-sell,.sig-card .sig-act b.k-buy,.sig-card .sig-act b.k-fill{background:none;padding:0}
b.k-sell{color:#0f8a5f}
b.k-buy{color:#c62828}
b.k-fill{color:#b96600}

/* 대기 종목 — 평소엔 접혀 있다. <details> 라 JS 가 필요 없다 */
.sig-wait{margin-top:11px;border-top:1px dashed #e3eaf0;padding-top:9px}
.sig-strip.quiet .sig-wait{margin-top:9px}
.sig-wait>summary{cursor:pointer;font-size:12.5px;color:#5f7183;font-weight:700;list-style:none}
.sig-wait>summary::-webkit-details-marker{display:none}
.sig-wait>summary::before{content:'▸ ';color:#9aa7b4}
.sig-wait[open]>summary::before{content:'▾ '}
.sig-wait>summary:hover{color:#1d5c93}
.sig-wait .tbl-scroll{margin-top:8px}

/* 포트폴리오 목록 행의 신호 배지 */
.sig-dot{display:inline-block;font-size:10.5px;font-weight:800;border-radius:999px;
  padding:1px 6px;margin-left:3px;vertical-align:1px}

/* 전체 종목 표 — 거리 칸. 가까운 쪽만 굵게 해 눈이 그리로 간다 */
table.pf td.gap{font-variant-numeric:tabular-nums;color:#8b98a5}
table.pf td.gap.near{color:#3c4d5e;font-weight:800}
/* ── 시장 신호 배지 ────────────────────────────────────────────────────
 * ★ 계획 신호(k-buy/k-sell/k-fill)와 <b>다른 팔레트</b>를 쓴다. 같은 빨강이 한 화면에서
 *   "매수 계획"과 "급등"을 동시에 뜻하면 읽을 수 없다. 여기는 채도를 낮춘 테두리형이다. */
.mkt{display:inline-block;font-size:10.5px;font-weight:800;border-radius:5px;padding:1px 6px;
  margin:1px 2px 1px 0;border:1px solid;cursor:help;white-space:nowrap}
.mkt.t-buyish{border-color:#a8d8c2;background:#f2fbf7;color:#0f7a55}
.mkt.t-sellish{border-color:#f0c2bc;background:#fff5f3;color:#b3382c}
.mkt.t-risk{border-color:#e8b892;background:#fff7ef;color:#a95f16}
.mkt.t-warn{border-color:#e3d391;background:#fffbe8;color:#8a6d1a}
.mkt.t-info{border-color:#c9d4de;background:#f7fafc;color:#5f7183}
.mkt-row{margin-top:5px;line-height:1.6}
/* 계획 신호를 시장 상태로 보정한 한 줄 — 카드에서 가장 먼저 읽혀야 한다 */
.sig-conf{margin-top:5px;font-size:11.5px;font-weight:800;cursor:help}
.sig-conf.lv-strong{color:#0f7a55}
.sig-conf.lv-caution{color:#a95f16}

/* ── 체결 게이트 / 유동성 ──────────────────────────────────────────────
 * 예수금 게이트(.sig-fund)와 같은 무게로 보이게 크기·자리를 맞춘다 —
 * 둘은 "실행할 수 있나"라는 같은 질문의 두 축(돈 / 물량)이다. */
.sig-fill{margin-top:4px;font-size:11.5px;font-weight:800;cursor:help;font-variant-numeric:tabular-nums}
.sig-fill.split{color:#a95f16}
.sig-fill.hard{color:#c62828}
.sig-fill .fi-imp{font-weight:700;color:#8b98a5}
/* 유동성 등급 — 낮을수록 눈에 띄어야 한다(위험이 그쪽에 있다) */
.liq{display:inline-block;font-size:10.5px;font-weight:800;border-radius:5px;padding:1px 6px;border:1px solid}
.liq.g-deep{border-color:#c9d4de;background:#f7fafc;color:#7d8b99}
.liq.g-ok{border-color:#c9d4de;background:#f7fafc;color:#5f7183}
.liq.g-thin{border-color:#e8b892;background:#fff7ef;color:#a95f16}
.liq.g-very_thin{border-color:#f0b8b2;background:#fff4f2;color:#c62828}
.liq-sub{font-size:10.5px;color:#9aa7b4;margin-top:2px;font-variant-numeric:tabular-nums}

/* 매매 판정 배지 — 매수·매도에서 같은 방향이 반대 뜻이 되므로 색이 아니라 <b>말</b>이 먼저다 */
.vd{display:inline-block;font-size:11.5px;font-weight:800;border-radius:6px;padding:2px 7px;white-space:nowrap}
.vd-good{background:#e6f5ee;color:#0f8a5f}
.vd-bad{background:#fdeaea;color:#c62828}
.vd-wait{background:#eef2f6;color:#7d8b99}
.vd-hold{background:#fdf1e0;color:#b96600}

/* ── 배지 도움말 (접힌 <details>) ──────────────────────────────────────
 * 평소엔 한 줄이고 펼치면 표가 나온다. 본문 글자는 표보다 한 단계 작게 둔다 —
 * 읽을 것이 많은 영역이라 행간이 넉넉해야 눈이 버틴다. */
.mkt-help{background:#fff;border:1px solid #e3eaf0;border-radius:11px;padding:11px 14px;margin-top:14px;
  box-shadow:0 1px 3px rgba(20,40,60,.05)}
.mkt-help>summary{cursor:pointer;font-size:13px;font-weight:800;color:#12406b;list-style:none}
.mkt-help>summary::-webkit-details-marker{display:none}
.mkt-help>summary::before{content:'▸ ';color:#9aa7b4}
.mkt-help[open]>summary::before{content:'▾ '}
.mkt-help>summary:hover{color:#1d5c93}
.mh-body{margin-top:12px;font-size:12.5px;line-height:1.65;color:#3c4d5e}
.mh-legend{display:flex;gap:14px;flex-wrap:wrap;align-items:center;padding:9px 11px;margin-bottom:10px;
  background:#f7fafc;border:1px solid #e9eff5;border-radius:8px;font-size:12px}
.mh-legend>b{color:#12406b}
.mh-legend span{display:inline-flex;align-items:center;gap:4px;color:#5f7183;font-weight:600}
.mh-note{margin:8px 0 12px;color:#7d8b99;font-size:12px;line-height:1.6}
.mh-h{font-size:13px;margin:20px 0 6px;color:#12406b;letter-spacing:-.01em}
/* 설명 표는 줄바꿈이 필요하다 — table.pf 의 nowrap 을 여기서만 푼다 */
table.mh-tbl td,table.mh-tbl th{white-space:normal !important;vertical-align:top;line-height:1.6}
table.mh-tbl td{padding:9px 10px}
table.mh-tbl .mkt{margin-bottom:2px}
table.mh-tbl code{background:#f2f6fa;border-radius:4px;padding:1px 4px;font-size:11.5px}
.mh-lim{margin:6px 0 0;padding-left:20px}
.mh-lim li{margin-bottom:5px}

.filter-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:12px}
.filter-bar .chk{display:flex;align-items:center;gap:5px;font-size:12.5px;font-weight:700;color:#5f7183;padding-bottom:7px}

@media (max-width:860px){
  .pf-topbar{height:auto;flex-wrap:wrap;gap:8px;padding:9px 12px}
  .pf-brand{font-size:16px}
  .pf-menu{order:3;width:100%;flex:none}
  .pf-topbar-right{margin-left:auto}
  .pf-uname{display:none}
}
@media (max-width:760px){
  .pf-wrap{padding:14px 8px 60px}
  table.pf th,table.pf td{padding:6px 6px;font-size:12px}
  .pf-head h1{font-size:18px}
}
</style>
CSS;
}

/** 표의 순서 칸에 넣는 드래그 핸들 */
function pf_drag_handle(): string
{
    return '<span class="drag-handle" title="드래그해서 순서 변경">'
         . '<svg viewBox="0 0 10 16" fill="currentColor" aria-hidden="true">'
         . '<circle cx="2.5" cy="3" r="1.5"/><circle cx="7.5" cy="3" r="1.5"/>'
         . '<circle cx="2.5" cy="8" r="1.5"/><circle cx="7.5" cy="8" r="1.5"/>'
         . '<circle cx="2.5" cy="13" r="1.5"/><circle cx="7.5" cy="13" r="1.5"/>'
         . '</svg></span>';
}

/**
 * 표 행을 드래그로 정렬하는 스크립트를 출력한다.
 * 대상 표의 tbody 행에 data-id 가 있어야 하고, 놓는 즉시 $apiUrl 로 순서를 POST 한다.
 * 행 클릭(data-href)은 드래그와 구분해서 처리한다.
 */
function pf_sort_script(string $tableId, string $apiUrl): void
{
    static $toastDone = false;
    if (!$toastDone) {
        echo '<div class="pf-toast" id="pfToast"></div>';
        echo <<<'JS'
<script>
var pfToastTimer = null;
function pfToast(msg, isErr){
  var el = document.getElementById('pfToast');
  if (!el) return;
  el.textContent = msg;
  el.classList.toggle('err', !!isErr);
  el.classList.add('on');
  clearTimeout(pfToastTimer);
  pfToastTimer = setTimeout(function(){ el.classList.remove('on'); }, 2200);
}
</script>
JS;
        $toastDone = true;
    }

    $t = json_encode($tableId);
    $u = json_encode($apiUrl);

    echo "<script>(function(){var TBL={$t},URL={$u};";
    echo <<<'JS'
  var tbody = document.querySelector('#' + TBL + ' tbody');
  if (!tbody) return;
  var dragRow = null, moved = false;

  // 핸들을 눌렀을 때만 드래그가 시작되도록 (행 클릭은 선택 동작)
  tbody.querySelectorAll('.drag-handle').forEach(function(h){
    h.addEventListener('mousedown', function(){ h.closest('tr').draggable = true; });
    h.addEventListener('mouseup',   function(){ h.closest('tr').draggable = false; });
  });

  tbody.addEventListener('dragstart', function(e){
    dragRow = e.target.closest('tr');
    if (!dragRow) return;
    moved = false;
    dragRow.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', dragRow.dataset.id || '');
  });

  tbody.addEventListener('dragover', function(e){
    if (!dragRow) return;
    e.preventDefault();
    var over = e.target.closest('tr');
    if (!over || over === dragRow || over.parentNode !== tbody) return;
    var box = over.getBoundingClientRect();
    tbody.insertBefore(dragRow, (e.clientY - box.top) > box.height / 2 ? over.nextSibling : over);
    moved = true;
  });

  tbody.addEventListener('dragend', function(){
    if (!dragRow) return;
    dragRow.classList.remove('dragging');
    dragRow.draggable = false;
    dragRow = null;
    if (moved) save();
  });

  function save(){
    var body = new URLSearchParams();
    tbody.querySelectorAll('tr[data-id]').forEach(function(tr){ body.append('id[]', tr.dataset.id); });
    body.append('json', '1');

    fetch(URL, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function(r){ return r.json(); })
      .then(function(j){ pfToast(j.message || '순서를 저장했습니다.', !j.ok); })
      .catch(function(){ pfToast('순서 저장에 실패했습니다. 새로고침 후 다시 시도하세요.', true); });
  }

  // 행 클릭 = 선택. 핸들·버튼·링크는 제외한다.
  tbody.addEventListener('click', function(e){
    if (e.target.closest('.drag-handle, .no-sel, a, button, input, select')) return;
    var tr = e.target.closest('tr[data-href]');
    if (tr) location.href = tr.dataset.href;
  });
})();</script>
JS;
}

// ── 종목 자동완성 (종목 추가 폼 · 시뮬레이터 공용) ────────────────────
/**
 * 종목 검색 입력칸. stock_code / stock_name 을 hidden 으로 실어 보낸다.
 * 여러 폼에서 쓰므로 id 접두어를 받는다. 동작은 pf_stock_picker_script() 가 붙인다.
 */
function pf_stock_picker(string $prefix = 'stk', string $code = '', string $name = '',
                         string $label = '종목', string $hint = '(이름 또는 코드로 검색)',
                         string $minWidth = '260px'): void
{
    $shown = trim($name . ($code !== '' ? ' (' . $code . ')' : ''));

    echo '<input type="hidden" name="stock_code" id="' . $prefix . 'Code" value="' . pf_h($code) . '">';
    echo '<input type="hidden" name="stock_name" id="' . $prefix . 'Name" value="' . pf_h($name) . '">';
    echo '<div class="fld stk-wrap" style="flex:1;min-width:' . $minWidth . '"><span>' . pf_h($label);
    if ($hint !== '') echo ' <span class="muted">' . pf_h($hint) . '</span>';
    echo '</span>';
    echo '<input type="text" id="' . $prefix . 'Search" autocomplete="off"'
       . ($shown !== '' ? ' class="picked" value="' . pf_h($shown) . '"' : '')
       . ' placeholder="예: 한국주철관 또는 000970" style="width:100%">';
    echo '<ul id="' . $prefix . 'List" class="stk-list"></ul></div>';
}

/**
 * @param bool $require true 면 종목을 못 고른 채 제출하면 막는다.
 *                      false 면 그냥 친 글자를 종목명으로 남긴다 (코드 없이도 허용).
 */
function pf_stock_picker_script(string $prefix = 'stk', bool $require = true): void
{
    $js = <<<'JS'
<script>
(function(){
  var PFX = '__PFX__', REQ = __REQ__;
  var inp = document.getElementById(PFX + 'Search');
  if (!inp) return;

  var box   = document.getElementById(PFX + 'List');
  var code  = document.getElementById(PFX + 'Code');
  var name  = document.getElementById(PFX + 'Name');
  var price = document.querySelector('input[name="last_price"]');
  var timer = null, items = [], focus = -1;

  function close(){ box.innerHTML = ''; box.classList.remove('on'); focus = -1; }

  function pick(i){
    var it = items[i];
    if (!it) return;
    code.value = it.code;
    name.value = it.name;
    inp.value  = it.name + ' (' + it.code + ')';
    inp.classList.add('picked');
    // 현재가를 비워 뒀으면 검색 결과의 시세로 채워 준다
    if (price && !price.value && it.last_price) price.value = it.last_price;
    close();
  }

  function render(){
    box.innerHTML = '';
    if (!items.length) { close(); return; }
    items.forEach(function(it, i){
      var li = document.createElement('li');
      li.className = (i === focus) ? 'on' : '';
      li.innerHTML = '<b>' + it.name + '</b><span>' + it.code + '</span>' +
        (it.last_price ? '<em>' + Number(it.last_price).toLocaleString() + '</em>' : '');
      li.addEventListener('mousedown', function(e){ e.preventDefault(); pick(i); });
      box.appendChild(li);
    });
    box.classList.add('on');
  }

  inp.addEventListener('input', function(){
    code.value = ''; name.value = '';
    inp.classList.remove('picked');

    var q = inp.value.trim();
    clearTimeout(timer);
    if (q.length < 1) { close(); return; }

    timer = setTimeout(function(){
      fetch('/stock/api.php?module=stock&action=search&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(list){ items = list || []; focus = -1; render(); })
        .catch(close);
    }, 220);
  });

  inp.addEventListener('keydown', function(e){
    if (!box.classList.contains('on')) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); focus = Math.min(focus + 1, items.length - 1); render(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); focus = Math.max(focus - 1, 0); render(); }
    else if (e.key === 'Enter')  { if (focus >= 0) { e.preventDefault(); pick(focus); } }
    else if (e.key === 'Escape') { close(); }
  });

  inp.addEventListener('blur', function(){ setTimeout(close, 120); });

  // 목록에서 고르지 않고 직접 친 경우도 살려 준다
  inp.form.addEventListener('submit', function(e){
    if (code.value) return;
    var raw = inp.value.trim();
    var m = raw.match(/(\d{6})/);
    if (m) { code.value = m[1]; return; }          // 6자리 숫자면 종목코드로 인정
    if (!REQ) { name.value = raw; return; }        // 코드 없이 이름만 남겨도 되는 화면
    e.preventDefault();
    alert('종목을 검색해서 목록에서 선택하거나, 6자리 종목코드를 입력하세요.');
    inp.focus();
  });
})();
</script>
JS;

    echo str_replace(['__PFX__', '__REQ__'], [$prefix, $require ? 'true' : 'false'], $js);
}

function pf_stock_picker_css(): void
{
    echo <<<'CSS'
<style>
.stk-wrap{position:relative}
.stk-wrap input.picked{border-color:#1d5c93;background:#f7fbff;font-weight:700}
.stk-list{display:none;position:absolute;top:100%;left:0;right:0;margin:4px 0 0;padding:5px 0;
  list-style:none;background:#fff;border:1px solid #d7e2ec;border-radius:9px;
  box-shadow:0 8px 24px rgba(15,35,60,.14);max-height:320px;overflow-y:auto;z-index:50}
.stk-list.on{display:block}
.stk-list li{display:flex;align-items:baseline;gap:8px;padding:8px 12px;cursor:pointer;font-size:13px}
.stk-list li:hover,.stk-list li.on{background:#eaf3fc}
.stk-list li b{font-weight:800;color:#12406b}
.stk-list li span{color:#9aa7b4;font-size:12px}
.stk-list li em{margin-left:auto;font-style:normal;color:#5f7183;font-variant-numeric:tabular-nums}
</style>
CSS;
}

/**
 * 재무분석 「종목 바로가기」 — 스크리너 조건에 <b>안 걸리는</b> 종목을 열려고 두는 자리다.
 *
 * 상세 화면(mode=fund&code=…)은 처음부터 있었는데 들어가는 길이 목록 행 클릭뿐이라,
 * 조건에 안 걸리면 주소를 손으로 쳐야 했다. 그 입구를 만든다.
 *
 * ★ 원천이 Dart::searchCorp() 다 — pf_stock_picker() 가 쓰는 Pf::stockSearch()(네이버 목록)와 다르다.
 *   네이버 목록엔 누락이 있어(DL·경방 등) 하필 이 기능에서 못 찾는 종목이 생긴다.
 * ★ 폼 <b>바깥</b>에 둔다. pf_stock_picker_script() 는 inp.form 에 submit 훅을 거는데,
 *   조건 폼 안에 넣으면 엔터가 스크리너 검색과 섞인다. 여기선 고르는 즉시 그 종목으로 이동한다.
 */
function pf_fund_jump(string $prefix = 'fj'): void
{
    static $cssDone = false;
    if (!$cssDone) {
        pf_stock_picker_css();          // .stk-wrap / .stk-list 는 종목 자동완성과 같은 것을 쓴다
        echo <<<'CSS'
<style>
.fund-jump{width:252px}
.fund-jump input{width:100%;padding:8px 11px;border:1px solid #c9d4de;border-radius:7px;
  font-size:13px;color:#3c4d5e;background:#fff;outline:none}
.fund-jump input:focus{border-color:#1d5c93;background:#f7fbff}
.fund-jump .stk-list li em{font-size:11px;color:#8b9aa8}
.fund-jump .stk-list li em.off{color:#c0392b}
</style>
CSS;
        $cssDone = true;
    }

    echo '<div class="stk-wrap fund-jump" title="조건에 안 걸리는 종목도 이름·코드로 바로 열 수 있습니다">';
    echo '<input type="text" id="' . $prefix . 'Search" autocomplete="off"'
       . ' placeholder="🔍 종목 바로가기 (이름·코드)">';
    echo '<ul id="' . $prefix . 'List" class="stk-list"></ul></div>';

    $js = <<<'JS'
<script>
(function(){
  var PFX = '__PFX__';
  var inp = document.getElementById(PFX + 'Search');
  if (!inp) return;

  var box = document.getElementById(PFX + 'List');
  var timer = null, items = [], focus = -1;

  function close(){ box.innerHTML = ''; box.classList.remove('on'); focus = -1; }
  function go(code){ location.href = '/stock/index.php?mode=fund&code=' + code; }
  function pick(i){ if (items[i]) go(items[i].code); }

  function render(){
    box.innerHTML = '';
    if (!items.length) { close(); return; }
    items.forEach(function(it, i){
      var li = document.createElement('li');
      li.className = (i === focus) ? 'on' : '';
      // 왜 이 종목이 목록에 있는지 오른쪽에 밝힌다 — 상장폐지 종목도 DART 코드는 남아 있어서 뜬다
      var off = !Number(it.traded) || !Number(it.years);
      var tag = !Number(it.traded) ? '미거래'
              : (Number(it.years) ? Number(it.years) + '개년' : '재무 없음');
      // DART 는 법인 정식명이라 부르는 이름과 다를 때가 많다(현대자동차 ↔ 현대차).
      // 다르면 거래소 종목명을 같이 보여 준다 — 안 그러면 왜 이게 걸렸는지 알 수 없다.
      var alias = (it.kname && it.kname !== it.name) ? ' · ' + it.kname : '';
      li.innerHTML = '<b>' + it.name + '</b><span>' + it.code + alias + '</span>' +
                     '<em class="' + (off ? 'off' : '') + '">' + tag + '</em>';
      li.addEventListener('mousedown', function(e){ e.preventDefault(); pick(i); });
      box.appendChild(li);
    });
    box.classList.add('on');
  }

  inp.addEventListener('input', function(){
    var q = inp.value.trim();
    clearTimeout(timer);
    if (q.length < 1) { close(); return; }
    timer = setTimeout(function(){
      fetch('/stock/api.php?module=dart&action=search&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(list){ items = list || []; focus = -1; render(); })
        .catch(close);
    }, 220);
  });

  inp.addEventListener('keydown', function(e){
    if (e.key === 'Enter') {
      e.preventDefault();
      if (focus >= 0) { pick(focus); return; }
      // 6자리 코드를 알고 있으면 목록을 기다릴 것 없이 바로 연다
      var m = inp.value.trim().match(/^(\d{6})$/);
      if (m) { go(m[1]); return; }
      if (items.length) pick(0);
      return;
    }
    if (!box.classList.contains('on')) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); focus = Math.min(focus + 1, items.length - 1); render(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); focus = Math.max(focus - 1, 0); render(); }
    else if (e.key === 'Escape') { close(); }
  });

  inp.addEventListener('blur', function(){ setTimeout(close, 120); });
})();
</script>
JS;
    echo str_replace('__PFX__', $prefix, $js);
}

/** 플래시 메시지 (?msg=ok:저장됨) */
function pf_flash(): void
{
    $msg = $_GET['msg'] ?? '';
    if ($msg === '') return;

    [$kind, $text] = array_pad(explode(':', $msg, 2), 2, '');
    $cls = in_array($kind, ['ok', 'err', 'warn'], true) ? $kind : 'ok';
    echo '<div class="' . $cls . '">' . pf_h($text) . '</div>';
}

// ══════════════════════════════════════════════════════════════════════
//  §4.1 대시보드
// ══════════════════════════════════════════════════════════════════════
/**
 * 포지션을 읽어 계산까지 끝낸다.
 *
 * ★★ <b>종료(청산) 포지션도 빼지 않고 전부 계산한다.</b> 예전에는 여기서 걸러 냈는데,
 *    판 돈(현금흐름)과 실현손익은 <b>확정된 사실</b>이라 합계에서 빠지면 예수금·수익률이 틀린다
 *    (실측 2026-07-30 — 청산 2건이 빠져 현금흐름 1,772,382원, 실현손익 −175,793 vs +1,596,589).
 *    대신 종료 포지션의 <b>계획·신호는 지운다</b>(pf_calc_closed) — 보유 0 이라 계산엔진이
 *    "1차부터 다시 사라"는 잔상을 만들기 때문이다.
 *    ⇒ 합계는 항상 전부 세고, 「종료 보기」 토글은 <b>목록에 보일지</b>만 정한다(pf_visible).
 *
 * @return array [positions(종료 포함 전부), calc(position_id => 계산결과)]
 */
function pf_load_calc(Pf $pf, ?int $folioId = null): array
{
    /* 시세를 먼저 당겨 온다.
     *
     * pf_stock.last_price 는 스키마 주석대로 <b>캐시</b>인데, 지금까지 그 캐시를 채우는 길이
     * 설정 화면의 수동 버튼(module=stock&action=sync)뿐이었다. 그래서 화면에는 며칠 전 값이
     * 그대로 떠 있었다 — 실측(2026-07-29 12:56): 현대차 361,500(어제 18:38 복사) vs 네이버 352,000.
     * 캐시가 낡은 채로 수익률·다음 매수가가 계산되면 숫자가 조용히 거짓말을 한다.
     *
     * 여기는 대시보드·종목상세·조각요청이 모두 거쳐 가는 단일 입구다. 한 번만 붙이면 전부 최신이 된다.
     * 비용은 pf_stock 행 수만큼의 UPDATE …JOIN 한 번(보유 종목 몇 개)이라 무시할 수준이다.
     * high_price(관측 최고가) 누적도 이 호출이 함께 맡는다 — 그건 진짜 상태라 저장이 필요하다.
     *
     * 시세 원천은 all_stock_info(네이버) 하나다 — 재무분석과 같은 규칙이다.
     *
     * 그 원천에도 구멍이 있다: 장 마감 뒤 all_stock_info 를 갱신하는 NXT 크론이 604종목만 훑어서,
     * NXT 에 없는 종목은 15:30 종가조차 못 받고 장중 값에 멈춘다(실측 — 현대차우가 2% 어긋나 있었다).
     * 보유 종목은 몇 개뿐이니 refreshQuotes() 로 그때그때 직접 받아 메운다 —
     * 이미 신선하면 호출조차 하지 않으므로 새로고침을 연타해도 네이버를 두드리지 않는다. */
    $pf->refreshQuotes($pf->stockCodes());
    $pf->syncPrices();

    $positions = $pf->positions($folioId);

    $stepsMap  = $pf->ruleStepsMap(array_column($positions, 'rule_set_id'));
    $tradesMap = $pf->tradesMap(array_column($positions, 'id'));
    $feeMap    = $pf->brokerFeesMap(array_column($positions, 'broker_id'));
    $lvMap     = $pf->positionLevelsMap(array_column($positions, 'id'));   // 박스 사다리 (있는 포지션만)

    $calc = [];
    foreach ($positions as $p) {
        $steps = $stepsMap[(int)$p['rule_set_id']] ?? [];
        $rows  = $tradesMap[(int)$p['id']] ?? [];
        $lvs   = $lvMap[(int)$p['id']] ?? [];
        $last  = ($p['last_price'] !== null) ? (float)$p['last_price'] : null;
        // 증권사 수수료(구간 or 단일요율) + 시장 세율
        $prm   = pf_cost_params($p, $feeMap[(int)$p['broker_id']] ?? []);
        $c     = ($steps || $lvs)
            ? pf_position_calc($steps, pf_trades_by_step($rows), (float)$p['limit_amt'], $last, $prm, pf_ledger($rows, $prm), $lvs)
            : null;
        // 종료 포지션은 확정된 돈만 남기고 계획·신호를 지운다
        if ($p['status'] === 'closed') $c = pf_calc_closed($c);
        // 차수 지연(룰셋 delay_days) — 만료된 다음 차수는 건너뛰고 계획을 한 차수 아래로
        elseif ($c !== null) $c = pf_delay_adjust($c, $steps, pf_last_buy_at($rows), date('Y-m-d'));
        /* 사이클 나이(첫 매수일부터) — 「재평가」 경보가 이 값과 차수로 판정된다.
         * 여기서 한 번 실어 두면 현황·보유종목·상세가 같은 값을 본다. */
        if ($c !== null) {
            $age = pf_cycle_age($rows);
            $c['cycle_start'] = $age['start'];
            $c['cycle_age']   = $age['days'];
        }
        $calc[(int)$p['id']] = $c;
    }
    return [$positions, $calc];
}

/**
 * 목록에 보일 포지션만 골라 낸다.
 *
 * 「종료 보기」 토글이 건드리는 것은 <b>표시</b>뿐이다 — 합계는 pf_load_calc() 가 준
 * 전체 calc 로 항상 종료까지 센다(그 이유는 pf_load_calc 주석 참조).
 */
function pf_visible(array $positions, bool $showClosed): array
{
    if ($showClosed) return $positions;
    return array_values(array_filter($positions, fn($p) => $p['status'] !== 'closed'));
}

/**
 * 계산결과 묶음의 합계.
 *
 * ★ 넘기는 $calc 는 <b>종료 포지션까지 포함</b>한 것이어야 한다. 청산분의 현금흐름·실현손익은
 *   확정된 사실이라 빠지면 예수금과 수익률이 실제와 어긋난다.
 *   종료 포지션은 보유가 0 이라 총매입·총평가·평가손익에 0 을 더할 뿐이고,
 *   '다음 차수 소요'(reserve)는 pf_calc_closed 가 next_amount 를 null 로 지워 두므로 섞이지 않는다.
 */
function pf_sum_calc(array $calc): array
{
    $t = ['cost' => 0.0, 'eval' => 0.0, 'pl' => 0.0, 'real' => 0.0,
          'flow' => 0.0, 'net' => 0.0, 'reserve' => 0.0];

    foreach ($calc as $c) {
        if (!$c) continue;
        $t['cost'] += (float)($c['cost_amount'] ?? 0);
        $t['eval'] += (float)($c['eval_amount'] ?? 0);
        $t['pl']   += (float)($c['eval_pl'] ?? 0);
        $t['real'] += (float)($c['realized_pl'] ?? 0);
        $t['flow'] += (float)($c['cash_flow'] ?? 0);
        $t['net']  += (float)($c['net_value'] ?? 0);
        if (($c['next_amount'] ?? null) !== null) $t['reserve'] += (float)$c['next_amount'];
    }
    return $t;
}

// ── 신호 조립 (여러 포트폴리오를 한 화면에) ───────────────────────────
/**
 * 포트폴리오별 소계. 목록·신호 스트립·예수금 게이트가 <b>같은 값</b>을 쓰도록 한 곳에서 만든다.
 * (예전에는 목록 루프 안에서만 계산해서, 스트립이 예수금을 다시 구하면 두 벌이 될 판이었다.)
 *
 * ★ $byFolio 는 <b>종료 포함 전 포지션</b>을 넘긴다 — 돈은 청산분까지 세야 맞다.
 *   다만 'n'(담긴 종목 수)은 <b>종료를 빼고</b> 센다. 그건 "지금 굴리고 있는 종목이 몇 개인가"라서
 *   화면에 보이는 목록과 같은 수여야 한다.
 */
function pf_folio_stats(array $folios, array $byFolio, array $calc): array
{
    $out = [];
    foreach ($folios as $f) {
        $fid  = (int)$f['id'];
        $rows = $byFolio[$fid] ?? [];
        $sub  = pf_sum_calc(array_intersect_key($calc, array_flip(array_column($rows, 'id'))));
        $prin = (float)$f['principal'];
        $cash = $prin + $sub['flow'];        // 예수금 = 원금 − 매수 + 매도
        $out[$fid] = [
            'name'  => (string)$f['name'],
            'prin'  => $prin,
            'cash'  => $cash,
            'asset' => $cash + $sub['net'],
            'rate'  => ($prin > 0) ? (($cash + $sub['net']) / $prin - 1) : null,
            'sub'   => $sub,
            'n'     => count(pf_visible($rows, false)),
        ];
    }
    return $out;
}

/**
 * 전 포지션을 "지금 무엇을 해야 하나" 한 줄로 세운다.
 *
 * 정렬: 매도 → 매수 → 잔여 → 대기.
 *   신호가 있는 것끼리는 <b>금액이 큰 순</b>(틀리면 손해가 큰 것부터 본다),
 *   대기끼리는 <b>임박한 순</b>(거리가 가까운 것부터).
 *
 * ★ 행 단위는 포지션(종목 × 포트폴리오)이다. 같은 종목을 두 포트폴리오에서 들고 있으면
 *   룰셋·한도·차수가 달라 신호도 서로 다른 별개의 건이다 — 종목으로 합치면 안 된다.
 */
function pf_signal_list(array $positions, array $calc, array $stats, array $dailyMap = []): array
{
    $rows = [];
    foreach ($positions as $p) {
        $pid  = (int)$p['id'];
        $fid  = (int)$p['portfolio_id'];
        $c    = $calc[$pid] ?? null;
        $last = ($p['last_price'] !== null) ? (float)$p['last_price'] : null;
        $sig  = pf_signal($c, $last);

        /* 시장 지표 — 일봉 이력이 있는 종목만. 없으면 빈 배열이라 화면이 조용히 비운다.
         * 지표는 저장하지 않는다(이 레포의 규칙) — 볼 때마다 계산한다. 종목당 400봉 O(n) 이라 무게가 없다. */
        $bars = $dailyMap[(string)$p['stock_code']] ?? [];
        $ind  = $bars ? pf_indicators($bars, $last) : [];

        /* 유동성 판정에 쓸 수량: 지금 신호가 있으면 그 수량, 없으면 <b>다음 차수 계획 수량</b>.
         * 대기 종목도 미리 알아야 쓸모가 있다 — "다음 차수에 닿으면 이만큼 필요한데 이 종목은 얇다"를
         * 신호가 뜨기 <b>전에</b> 알면 한도나 룰셋을 미리 손볼 수 있다. */
        $planQty = ((int)$sig['qty'] > 0) ? (int)$sig['qty'] : (int)($c['next_qty'] ?? 0);
        $liq  = $bars ? pf_liquidity($bars, $planQty, $ind['sd20'] ?? null) : [];
        $rows[] = [
            'p' => $p, 'c' => $c, 's' => $sig,
            'pid' => $pid, 'fid' => $fid, 'last' => $last,
            'fname' => (string)($stats[$fid]['name'] ?? ''),
            'fund_ok' => null, 'fund_left' => null,
            'ind'  => $ind,
            'liq'  => $liq,
            'gate' => $liq ? pf_fill_gate($liq) : ['level' => 'ok', 'label' => '', 'why' => ''],
            'mkt'  => $ind ? pf_market_signals($ind, 3, $liq ?: null) : [],
            'conf' => $ind ? pf_signal_confidence($sig['kind'], $ind) : ['level' => 'normal', 'label' => '', 'why' => ''],
        ];
    }

    usort($rows, function (array $a, array $b) {
        $ra = pf_signal_rank($a['s']['kind']);
        $rb = pf_signal_rank($b['s']['kind']);
        if ($ra !== $rb) return $ra <=> $rb;
        if ($a['s']['kind'] !== null) return $b['s']['amount'] <=> $a['s']['amount'];
        return ($a['s']['gap'] ?? INF) <=> ($b['s']['gap'] ?? INF);
    });

    return pf_fund_gate($rows, $stats);
}

/**
 * 예수금 게이트 — 신호가 떴어도 <b>돈이 없으면 실행할 수 없다</b>.
 *
 * 실측(2026-07-30) 스윙 포트폴리오: 다음 차수 소요 10,970,800 vs 예수금 4,469,971.
 * 이걸 안 보여주면 신호를 믿고 계획을 세우다 주문 단계에서 틀어진다.
 *
 * ★ 한 포트폴리오의 여러 신호는 <b>같은 예수금</b>을 두고 다툰다. 그래서 정렬된 순서대로
 *   누적 배정하고, 앞 신호가 쓴 만큼을 빼고 남은 돈으로 다음 신호를 판정한다.
 *   (앞 신호가 예수금을 넘겨도 배정은 남은 돈까지만 — 없는 돈을 쓴 것으로 치면 뒤가 더 틀어진다.)
 * ★ 매도는 현금이 늘어나는 쪽이라 게이트를 걸지 않는다.
 */
function pf_fund_gate(array $rows, array $stats): array
{
    $used = [];
    foreach ($rows as &$g) {
        if ($g['s']['kind'] !== 'buy' && $g['s']['kind'] !== 'fill') continue;
        $fid  = $g['fid'];
        $left = (float)($stats[$fid]['cash'] ?? 0) - (float)($used[$fid] ?? 0);
        $need = (float)$g['s']['amount'];
        $g['fund_left'] = $left;
        $g['fund_ok']   = ($need <= $left + 0.5);
        $used[$fid]     = (float)($used[$fid] ?? 0) + min($need, max(0.0, $left));
    }
    unset($g);
    return $rows;
}

/**
 * 시장 신호 배지 — tone 별 색만 다르다.
 *
 * ★ tone 은 "살 쪽에 유리(buyish)/팔 쪽에 유리(sellish)/경고(risk)/참고(info)" 다.
 *   등락색(빨강=상승)과 뜻이 다르므로 계획 신호 배지와 같은 팔레트를 쓰지 않는다 —
 *   같은 색이 화면에서 두 가지를 뜻하면 읽을 수 없게 된다.
 */
function pf_mkt_badges(array $mkt): string
{
    $h = '';
    foreach ($mkt as $m) {
        $h .= '<span class="mkt t-' . pf_h($m['tone']) . '" title="' . pf_h($m['why']) . '">'
            . pf_h($m['label']) . '</span>';
    }
    return $h;
}

/**
 * 사이클 나이 셀 — 나이 + (걸리면) 「재평가」 경보 배지.
 *
 * 경보 기준·근거는 pf_cycle_alert() 주석 참조(2년 경과 또는 5차 도달 — 사이클 226개 실측).
 * ★ 자동으로 매수를 막거나 손절하지 않는다 — 백테스트에서 기계식 규칙은 수익을 깎았다.
 *   배지는 「판단을 소집하는 신호」이고, 근거 전체는 배지에 마우스를 올리면 나온다.
 * ★ 종료 포지션에는 경보를 붙이지 않는다(끝난 사이클에 재평가할 계획이 없다).
 */
/**
 * 「지연」 배지 — 차수 지연으로 다음 차수가 만료돼 계획이 한 차수 아래로 옮겨졌음을 알린다.
 * 판정·값 이동은 pf_delay_adjust(calc.php) — 여기는 표시만 한다.
 */
function pf_delay_badge(?array $c): string
{
    $d = $c['delay_skip'] ?? null;
    if (!$d) return '';
    return '<br><span class="mkt t-warn" title="' . pf_h(sprintf(
        '%d차 만료 — 직전 매수 후 %s일 경과(룰셋 지연 %d일 초과). 느린 한 차수 하락은 추세로 보고 건너뜁니다. '
        . '다음 매수는 %s 가격부터이고, 건너뛴 금액은 그 차수 매수가 흡수합니다(누적목표). '
        . '현재가가 그 아래 차수까지 급락하면 규칙대로 즉시 매수 신호가 다시 섭니다.',
        $d['step'], number_format($d['elapsed']), $d['limit'],
        ($c['next_step'] ?? null) !== null ? $c['next_step'] . '차' : '없음(마지막 차수 만료)'))
        . '">' . $d['step'] . '차 지연</span>';
}

/**
 * 박스 사다리 상세표 HTML — pf_box_ladder_detail() 결과를 룰셋 화면처럼 차수별로 펼친다.
 * 확정본(수정 화면)과 JS 미리보기(pfBoxSolve)가 같은 열 구성을 쓴다 — 열을 바꾸면 둘 다 고칠 것.
 */
function pf_box_ladder_table(array $detail): string
{
    $h = '<table class="pf"><thead><tr><th>차수</th><th class="num">가격</th>'
       . '<th class="num" title="직전 차수 가격 대비">변동율</th>'
       . '<th class="num">비중</th><th class="num">누적</th>'
       . '<th class="num" title="그 차수까지 계획대로 다 샀을 때의 평균단가">평단</th>'
       . '<th class="num" title="그 차수 가격에서의 평가손익 — 손익분기까지의 거리">손실률</th>'
       . '<th class="num">목표</th>'
       . '<th class="num" title="그 차수 평단 × (1+목표) — 자동매도가가 걸리는 자리(수수료 제외)">탈출가</th>'
       . '<th class="num">지연</th></tr></thead><tbody>';
    foreach ($detail as $n => $r) {
        $h .= '<tr><td>' . (int)$n . '차</td>'
            . '<td class="num">' . pf_n($r['price']) . '</td>'
            . '<td class="num">' . ($r['chg'] === null ? '-' : pf_pct($r['chg'], 1)) . '</td>'
            . '<td class="num">' . pf_pct0($r['weight'], 1) . '</td>'
            . '<td class="num">' . pf_pct0($r['cum'], 1) . '</td>'
            . '<td class="num">' . pf_n(round($r['avg'])) . '</td>'
            . '<td class="num">' . ($r['be'] === null ? '-' : pf_pct($r['be'], 1)) . '</td>'
            . '<td class="num">' . pf_pct0($r['target_rate'], 0) . '</td>'
            . '<td class="num">' . pf_n(round($r['exit'])) . '</td>'
            . '<td class="num">' . (int)$r['delay_days'] . '일</td></tr>';
    }
    return $h . '</tbody></table>';
}

function pf_age_cell(array $c, string $status, string $extra = ''): string
{
    $days = $c['cycle_age'] ?? null;
    if ($days === null) return '<td class="num muted">-</td>';

    $tip = '첫 매수 ' . ($c['cycle_start'] ?? '?') . ' 부터';
    $h   = '<td class="num" title="' . pf_h($tip) . '">' . pf_h(pf_age_txt($days));

    if ($status !== 'closed') {
        $al = pf_cycle_alert($days, (int)($c['cur_step'] ?? 0));
        if ($al['level'] !== 'none') {
            $h .= '<br><span class="mkt t-risk" title="' . pf_h($al['why']) . '">' . pf_h($al['label']) . '</span>';
        }
        if ($extra !== '') $h .= '<br>' . $extra;   // 계단관통↓ 등 추가 경보 (종료 포지션 제외는 동일)
    }
    return $h . '</td>';
}

/**
 * 「계단관통↓」 경보 지도 — 보유 종목코드 → 배지 HTML (해당 없으면 키 없음).
 *
 * 사다리×퀀트 결합 연구(2026-08-01) 의 화면 반영: 사다리(분할매수)의 전제는 「떨어져도 반등한다」인데,
 * 그 종목의 최근 최고 거래대금 박스에서 아래 계단(지지구조)까지 <b>전부</b> 종가로 뚫렸다면
 * (KrxAmt::boxStatusMany 의 「관통↓」 — 붕괴 전수의 2~3%인 드문 사건) 그 전제가 깨졌다는 실측 신호다.
 * 원장 백테스트: 이 신호에서 사다리를 중단·청산하면 물림 11.3%→5.2%(양 기간 일관) · 비용 중앙 −0.8%p.
 *
 * ★자동으로 매도하지 않는다 — 재평가 배지와 같은 「판단 소집」 철학.
 * ★krx_surge 에 신호 이력이 없는 종목(저유동 등)은 판정 불가 = 배지 없음.
 * ★$pdo 는 전역(cnt.inc) — 이 함수는 pf_render_folio_detail 처럼 PDO 를 안 받는 렌더러에서도 불린다.
 */
function pf_stair_alert_map(array $codes): array
{
    $codes = array_values(array_unique(array_filter($codes)));
    if (!$codes) return [];
    try {
        $pdo = $GLOBALS['pdo'];
        $in  = implode(',', array_fill(0, count($codes), '?'));
        $st  = $pdo->prepare("SELECT code, MAX(d) d FROM krx_surge WHERE code IN ($in) GROUP BY code");
        $st->execute($codes);
        $sigs = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $sigs[] = ['code' => $r['code'], 'd' => $r['d']];
        if (!$sigs) return [];

        $box = (new KrxAmt($pdo))->boxStatusMany($sigs);
        $map = [];
        foreach ($sigs as $s) {
            $b = $box[$s['code'] . '|' . $s['d']] ?? null;
            if ($b && $b['st'] === 'bx-dn' && str_contains((string)$b['txt'], '관통')) {
                $map[$s['code']] = '<span class="mkt t-risk" title="' . pf_h(
                    '최근 최고 거래대금 박스(' . $s['d'] . ')의 아래 계단(지지구조)이 전부 종가로 뚫렸습니다 — '
                    . '붕괴 전수의 2~3%인 드문 사건. 사다리 백테스트(2026-08-01): 이 신호에서 중단·청산하면 '
                    . '물림 11.3%→5.2%·비용 중앙 −0.8%p. 자동 매도는 없습니다 — 분할매수의 전제(반등)가 '
                    . '깨졌는지 재평가하라는 소집 신호입니다.') . '">계단관통↓</span>';
            }
        }
        return $map;
    } catch (Throwable $e) {
        return [];   // 경보가 없어도 화면은 뜬다 (krx_amt 미구축 환경 포함)
    }
}

/** 신호 종류 → 라벨 */
function pf_sig_label(?string $kind): string
{
    return ['sell' => '매도', 'buy' => '매수', 'fill' => '잔여매수'][$kind ?? ''] ?? '대기';
}

/** 포트폴리오 목록 행에 붙는 신호 배지 (D안) — 어디를 클릭할지 즉시 알게 한다 */
function pf_sig_dots(array $cnt): string
{
    $h = '';
    foreach (['sell', 'buy', 'fill'] as $k) {
        if (empty($cnt[$k])) continue;
        $h .= '<span class="sig-dot k-' . $k . '" title="' . pf_sig_label($k) . ' 신호 ' . $cnt[$k] . '건">'
            . pf_sig_label($k) . ' ' . $cnt[$k] . '</span>';
    }
    return $h;
}

/**
 * 거리 셀 — "그 가격까지 얼마나 움직여야 하나".
 *
 * ★ 등락색(빨강/파랑)을 쓰지 않는다. 이 값은 오르내린 결과가 아니라 <b>남은 거리</b>다.
 *   방향은 ▼(내려야 함)·▲(올라야 함) 글리프가 말하고, 가까운 쪽만 굵게 해 눈이 그리로 가게 한다.
 */
function pf_gap_cell(?float $gap, string $dir, bool $near): string
{
    if ($gap === null) return '<td class="num gap">-</td>';
    $txt = ($gap <= 0)
        ? '도달'
        : ($dir === 'down' ? '▼' : '▲') . number_format(abs($gap) * 100, 1) . '%';
    return '<td class="num gap' . ($near ? ' near' : '') . '">' . $txt . '</td>';
}

/**
 * 오늘의 신호 스트립 — 화면 맨 위 전폭.
 *
 * 여기가 이 화면의 존재 이유다: 포트폴리오를 하나씩 클릭하지 않고도
 * "지금 살 것 / 팔 것"이 로그인 직후 <b>클릭 0회</b>로 보여야 한다.
 * 신호가 없는 날은 한 줄로 접혀 자리를 거의 먹지 않는다(quiet).
 */
function pf_render_signal_strip(array $sigs, array $stats): void
{
    $act  = array_values(array_filter($sigs, fn($g) => $g['s']['kind'] !== null));
    $wait = array_values(array_filter($sigs, fn($g) => $g['s']['kind'] === null));

    $cnt = ['sell' => 0, 'buy' => 0, 'fill' => 0];
    $need = 0.0;
    $short = 0;
    foreach ($act as $g) {
        $cnt[$g['s']['kind']]++;
        if ($g['s']['kind'] === 'sell') continue;
        $need += (float)$g['s']['amount'];
        if ($g['fund_ok'] === false) $short++;
    }

    echo '<section class="sig-strip' . ($act ? '' : ' quiet') . '">';
    echo '<div class="sig-hd"><h2>오늘의 신호</h2>';
    if ($act) {
        foreach (['sell', 'buy', 'fill'] as $k) {
            if (!$cnt[$k]) continue;
            echo '<span class="sig-pill k-' . $k . '">' . pf_sig_label($k) . ' ' . $cnt[$k] . '건</span>';
        }
        if ($need > 0) {
            echo '<span class="sig-cash">매수 소요 <b>' . pf_n(round($need)) . '</b>';
            if ($short > 0) echo ' · <b class="short">예수금 부족 ' . $short . '건</b>';
            echo '</span>';
        }
    } else {
        echo '<span class="sig-none">지금 실행할 매수·매도 신호가 없습니다.</span>';
    }
    echo '</div>';

    if ($act) {
        echo '<div class="sig-list">';
        foreach ($act as $g) {
            $s    = $g['s'];
            $c    = $g['c'];
            $kind = $s['kind'];
            $last = $g['last'];

            echo '<a class="sig-card k-' . $kind . '" href="/stock/index.php?mode=position&id=' . $g['pid'] . '">';
            echo '<div class="sig-top"><span class="sig-pill k-' . $kind . '">' . pf_sig_label($kind) . '</span>';
            echo '<span class="sig-folio">' . pf_h($g['fname']) . '</span></div>';

            // 차수 표기 — 매수만 "올라가는" 것이라 화살표를 쓴다
            $step = ($kind === 'buy' && (int)$c['reach_step'] > (int)$c['cur_step'])
                ? ((int)$c['cur_step'] > 0 ? $c['cur_step'] . '차 → ' : '') . $c['reach_step'] . '차'
                : ((int)$c['cur_step'] > 0 ? $c['cur_step'] . '차' : '진입');
            echo '<div class="sig-name">' . pf_h($g['p']['stock_name'])
               . '<span class="step">' . pf_h($step) . '</span></div>';

            if ($kind === 'sell') {
                echo '<div class="sig-act"><b class="k-sell">' . pf_n($s['qty']) . '주</b> 전량 매도 · '
                   . pf_n(round($s['amount'])) . '원</div>';
                echo '<div class="sig-meta">현재가 ' . pf_n($last) . ' ≥ 자동매도가 '
                   . pf_n($c['sell_price']) . '</div>';
            } else {
                $tp = ($kind === 'buy') ? ($c['steps'][$c['reach_step']]['theory_price'] ?? null)
                                        : ($c['steps'][$c['cur_step']]['theory_price'] ?? null);
                echo '<div class="sig-act"><b class="k-' . $kind . '">' . pf_n($s['qty']) . '주</b> '
                   . ($kind === 'fill' ? '추가 매수' : '매수') . ' · ' . pf_n(round($s['amount'])) . '원</div>';
                echo '<div class="sig-meta">현재가 ' . pf_n($last) . ' ≤ 이론가 ' . pf_n($tp)
                   . ($kind === 'fill' ? ' · 그 차수 누적목표 미달' : '') . '</div>';

                $left = (float)$g['fund_left'];
                if ($g['fund_ok']) {
                    echo '<div class="sig-fund fine">✓ 실행가능 · 예수금 잔여 '
                       . pf_n(round($left - (float)$s['amount'])) . '</div>';
                } else {
                    echo '<div class="sig-fund short">⚠ 예수금 ' . pf_n(round(max(0.0, $left)))
                       . ' — ' . pf_n(round((float)$s['amount'] - max(0.0, $left))) . ' 부족</div>';
                }

                /* ── 체결 게이트. 예수금 게이트의 짝이다 — <b>돈이 있어도 물량이 없으면 못 산다.</b>
                 *   실측(2026-07-30): 매일홀딩스는 일평균 거래대금이 0.2억이고 다음 차수 267주가
                 *   일평균 거래량의 11.5%다. 하루에 담으면 내가 가격을 밀어올린다.
                 *   유동성이 충분한 종목(대형주)은 조용히 넘어간다 — 늘 뜨는 표시는 표시가 아니다. */
                $gate = $g['gate'];
                if ($gate['level'] !== 'ok' && $gate['label'] !== '') {
                    echo '<div class="sig-fill ' . pf_h($gate['level']) . '" title="' . pf_h($gate['why']) . '">'
                       . ($gate['level'] === 'hard' ? '⚠ ' : '△ ') . pf_h($gate['label']);
                    if (($g['liq']['impact'] ?? null) !== null) {
                        echo ' <span class="fi-imp">추정 슬리피지 ~'
                           . number_format((float)$g['liq']['impact'] * 100, 1) . '%</span>';
                    }
                    echo '</div>';
                }
            }

            /* ── 시장 상태. 계획 신호를 <b>보정</b>하는 자리다.
             *   신뢰도(근거 강함 / 주의)를 먼저 쓰고 그 아래에 근거가 된 배지를 늘어놓는다 —
             *   지표만 늘어놓으면 "그래서 어쩌라고"가 남는다. */
            if (($g['conf']['label'] ?? '') !== '') {
                echo '<div class="sig-conf lv-' . pf_h($g['conf']['level']) . '" title="'
                   . pf_h($g['conf']['why']) . '">'
                   . ($g['conf']['level'] === 'strong' ? '◎ ' : '△ ') . pf_h($g['conf']['label']) . '</div>';
            }
            if (!empty($g['mkt'])) echo '<div class="mkt-row">' . pf_mkt_badges($g['mkt']) . '</div>';
            echo '</a>';
        }
        echo '</div>';
    }

    // ── 대기 종목 — 접어 둔다. <details> 라 JS 가 필요 없다
    if ($wait) {
        $near = $wait[0];
        $hint = ($near['s']['gap'] !== null)
            ? ' — 가장 임박한 것은 ' . pf_h($near['p']['stock_name']) . ' '
              . (($near['s']['near'] === 'sell') ? '매도' : '매수') . '까지 '
              . number_format(abs($near['s']['gap']) * 100, 1) . '%'
            : '';
        echo '<details class="sig-wait"><summary>대기 ' . count($wait) . '종목' . $hint . '</summary>';
        echo '<div class="tbl-scroll"><table class="pf"><thead><tr>';
        foreach ([['종목', ''], ['포트폴리오', ''], ['차수', 'num'], ['현재가', 'num'],
                  ['매수까지', 'num'], ['다음매수가', 'num'], ['매도까지', 'num'],
                  ['자동매도가', 'num'], ['수익률', 'num'], ['시장', '']] as [$l, $cl]) {
            echo '<th class="' . $cl . '">' . pf_h($l) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($wait as $g) {
            $c = $g['c'];
            $s = $g['s'];
            echo '<tr><td><a href="/stock/index.php?mode=position&id=' . $g['pid'] . '">'
               . pf_h($g['p']['stock_name']) . '</a></td>';
            echo '<td class="muted">' . pf_h($g['fname']) . '</td>';
            echo '<td class="num">' . ($c && (int)$c['cur_step'] > 0 ? $c['cur_step'] . '차' : '-') . '</td>';
            echo '<td class="num">' . pf_n($g['last']) . '</td>';
            echo pf_gap_cell($s['buy_gap'], 'down', $s['near'] === 'buy');
            echo '<td class="num muted">' . pf_n($c['next_price'] ?? null) . '</td>';
            echo pf_gap_cell($s['sell_gap'], 'up', $s['near'] === 'sell');
            echo '<td class="num muted">' . pf_n($c['sell_price'] ?? null) . '</td>';
            echo '<td class="num">' . pf_signed_pct($c['rate'] ?? null) . '</td>';
            /* ★ 대기 종목에도 시장 배지를 붙인다 — 계획 신호가 없어도 "지금 뭔가 일어난" 종목은
             *   봐야 한다. 급락·거래량 급증은 다음 차수가 임박했다는 뜻이기도 하다. */
            echo '<td>' . (empty($g['mkt']) ? '<span class="flat">-</span>' : pf_mkt_badges($g['mkt'])) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></details>';
    }
    echo '</section>';
}

/**
 * 대시보드 — 좌우 분할. 왼쪽 포트폴리오 목록, 오른쪽 선택한 포트폴리오 상세.
 *
 * ?id=N     선택한 포트폴리오 (없으면 첫 번째)
 * ?frag=1   오른쪽 상세만 HTML 조각으로 반환 (JS fetch 용, iframe 대체)
 */
function pf_page_dashboard(PDO $pdo, Pf $pf): void
{
    $showClosed = !empty($_GET['closed']);
    $folios     = $pf->portfolios();

    $selId = (int)($_GET['id'] ?? 0);
    if (!$selId && $folios) $selId = (int)$folios[0]['id'];

    // ── 조각 요청: 상세 영역만 그린다 (헤더·푸터 없음)
    if (!empty($_GET['frag'])) {
        pf_render_folio_detail($pf, $selId, $showClosed);
        return;
    }

    /* 합계는 종료 포지션까지 전부 센다(확정된 돈이다) — 목록·신호에서만 종료를 뺀다. */
    [$positions, $calc] = pf_load_calc($pf, null);
    $active = pf_visible($positions, false);   // 신호 대상 — 청산된 종목엔 오늘 할 일이 없다

    $byFolio = [];
    foreach ($positions as $p) $byFolio[(int)$p['portfolio_id']][] = $p;

    pf_head('포트폴리오', 'dashboard', 'wide');

    $tPrincipal = 0.0;
    foreach ($folios as $f) $tPrincipal += (float)$f['principal'];

    $t      = pf_sum_calc($calc);
    $tCash  = $tPrincipal + $t['flow'];   // 예수금 = 원금 − 매수지출 + 매도수취
    $tAsset = $tCash + $t['net'];         // 추정자산 = 예수금 + 보유종목 현재가치
    $tRate  = ($tPrincipal > 0) ? ($tAsset / $tPrincipal - 1) : null;

    /* 포트폴리오별 소계는 한 번만 만들어 목록·신호 스트립·예수금 게이트가 함께 쓴다. */
    $stats = pf_folio_stats($folios, $byFolio, $calc);
    /* 시장 지표용 일봉을 한 쿼리로 당겨 온다 (종목당 쿼리를 내면 N+1 이다).
     * 크론(job=daily)이 채워 두는 pf_daily 를 읽을 뿐이라 네이버를 부르지 않는다. */
    $daily = $pf->dailyMap(array_column($active, 'stock_code'), 400);
    $sigs  = pf_signal_list($active, $calc, $stats, $daily);

    // 포트폴리오별 신호 개수 — 목록 행 배지용
    $sigCnt = [];
    foreach ($sigs as $g) {
        if ($g['s']['kind'] === null) continue;
        $sigCnt[$g['fid']][$g['s']['kind']] = ($sigCnt[$g['fid']][$g['s']['kind']] ?? 0) + 1;
    }

    pf_subtabs('current', 'dashboard');
    pf_flash();

    /* 제목·버튼은 좌우분할 <b>바깥</b>에 둔다. 신호 스트립이 전폭이라
     * 제목이 왼쪽 컬럼에 갇혀 있으면 스트립 아래로 밀려 읽는 순서가 뒤집힌다. */
    echo '<div class="pf-head"><div>';
    echo '<h1>포트폴리오</h1>';
    echo '<div class="sub">위에 지금 실행할 신호가 모여 있습니다. 아래에서 포트폴리오를 고르면 오른쪽에 담긴 종목이 나옵니다.</div>';
    echo '</div><div class="act">';
    echo '<a class="btn btn-outline" href="/stock/index.php?mode=all">보유종목 보기</a>';
    echo '<a class="btn btn-outline" href="/stock/index.php?mode=portfolio">포트폴리오 관리</a>';
    $closedHref = '/stock/index.php?id=' . $selId . ($showClosed ? '' : '&closed=1');
    echo '<a class="btn btn-outline" href="' . $closedHref . '">' . ($showClosed ? '종료 숨기기' : '종료 보기') . '</a>';
    echo '</div></div>';

    if ($folios) pf_render_signal_strip($sigs, $stats);

    // ── 좌우 컬럼. 오른쪽 상세는 맨 위부터 시작하는 독립 영역이다.
    echo '<div class="split">';
    echo '<div class="split-left">';

    // 전체 합계
    echo '<div class="sum-grid">';
    foreach ([
        ['원금',     pf_n($tPrincipal),         '',                       ''],
        ['예수금',   pf_n(round($tCash)),       $tCash < 0 ? 'down' : '', '원금 − 매수 + 매도'],
        ['총매입',   pf_n(round($t['cost'])),   '',                       '보유분 원가'],
        ['총평가',   pf_n(round($t['eval'])),   '',                       ''],
        ['평가손익', pf_n(round($t['pl'])),     pf_updown($t['pl']),      ''],
        ['실현손익', pf_n(round($t['real'])),   pf_updown($t['real']),    ''],
        ['추정자산', pf_n(round($tAsset)),      '',                       '예수금 + 현재가치'],
        ['수익률',   $tRate === null ? '-' : pf_pct($tRate), pf_updown($tRate), '원금 대비'],
    ] as [$k, $v, $cls, $sub]) {
        echo '<div class="sum-box"><div class="k">' . pf_h($k) . '</div>';
        echo '<div class="v ' . $cls . '">' . pf_h($v) . '</div>';
        if ($sub !== '') echo '<div class="s">' . pf_h($sub) . '</div>';
        echo '</div>';
    }
    echo '</div>';

    if (!$folios) {
        echo '<div class="card"><p class="muted">포트폴리오가 없습니다. '
           . '<a href="/stock/index.php?mode=portfolio">포트폴리오를 먼저 만들어</a> 주세요.</p></div>';
        echo '</div></div>';
        pf_foot();
        return;
    }

    // 포트폴리오 리스트
    echo '<div class="card"><h2>포트폴리오</h2>';
    echo '<div class="tbl-scroll"><table class="pf"><thead><tr>';
    foreach ([
        ['포트폴리오', ''], ['원금', 'num'], ['예수금', 'num'], ['총매입', 'num'],
        ['종목', 'num'], ['메모', ''], ['총평가', 'num'], ['평가손익', 'num'],
        ['추정자산', 'num'], ['수익률', 'num'],
    ] as [$label, $cls]) {
        echo '<th class="' . $cls . '">' . pf_h($label) . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($folios as $f) {
        $fid    = (int)$f['id'];
        $st     = $stats[$fid];
        $sub    = $st['sub'];
        $prin   = $st['prin'];
        $sCash  = $st['cash'];
        $sAsset = $st['asset'];
        $sRate  = $st['rate'];

        $cls = 'folio-row' . ($fid === $selId ? ' on' : '') . ((int)$f['is_active'] ? '' : ' watch');
        echo '<tr class="' . $cls . '" data-id="' . $fid . '">';

        echo '<td><a href="/stock/index.php?id=' . $fid . ($showClosed ? '&closed=1' : '') . '">'
           . '<b>' . pf_h($f['name']) . '</b></a>';
        /* 신호 배지 — 새 열을 만들지 않고 이름 옆에 붙인다.
         * 이 표는 좌우분할의 왼쪽에 들어가 폭이 빠듯해서, 열을 하나 늘리면 가로 스크롤이 생긴다. */
        if (!empty($sigCnt[$fid])) echo ' ' . pf_sig_dots($sigCnt[$fid]);
        if (!(int)$f['is_active']) echo ' <span class="badge st-closed">미사용</span>';
        $ident = array_filter([$f['broker'], $f['acct_no']]);
        if ($ident) echo '<br><span class="muted" style="font-size:11px">' . pf_h(implode(' ', $ident)) . '</span>';
        echo '</td>';

        echo '<td class="num">' . pf_n($prin) . '</td>';
        echo '<td class="num' . ($sCash < 0 ? ' down' : '') . '">' . pf_n(round($sCash)) . '</td>';
        echo '<td class="num">' . pf_n(round($sub['cost'])) . '</td>';
        echo '<td class="num">' . $st['n'] . '</td>';   // 종료 제외 — 목록에 보이는 수와 같게
        echo '<td class="muted">' . pf_h($f['memo']) . '</td>';
        echo '<td class="num">' . pf_n(round($sub['eval'])) . '</td>';
        echo '<td class="num">' . pf_signed($sub['pl']) . '</td>';
        echo '<td class="num"><b>' . pf_n(round($sAsset)) . '</b></td>';
        echo '<td class="num">' . ($sRate === null ? '<span class="flat">-</span>' : pf_signed_pct($sRate)) . '</td>';
        echo '</tr>';
    }

    echo '</tbody><tfoot><tr>';
    echo '<td>합계</td>';
    echo '<td class="num">' . pf_n($tPrincipal) . '</td>';
    echo '<td class="num">' . pf_n(round($tCash)) . '</td>';
    echo '<td class="num">' . pf_n(round($t['cost'])) . '</td>';
    echo '<td class="num">' . count($active) . '</td>';
    echo '<td></td>';
    echo '<td class="num">' . pf_n(round($t['eval'])) . '</td>';
    echo '<td class="num">' . pf_signed($t['pl']) . '</td>';
    echo '<td class="num">' . pf_n(round($tAsset)) . '</td>';
    echo '<td class="num">' . ($tRate === null ? '' : pf_signed_pct($tRate)) . '</td>';
    echo '</tr></tfoot></table></div>';
    echo '<p class="sub muted" style="margin:9px 0 0;font-size:12px">'
       . '행을 클릭하면 오른쪽에 상세가 표시됩니다.</p>';
    echo '</div>';   // .card
    echo '</div>';   // .split-left

    // 오른쪽 — 선택한 포트폴리오 상세
    echo '<div class="split-right"><div id="pfDetail" class="folio-detail">';
    pf_render_folio_detail($pf, $selId, $showClosed);
    echo '</div></div>';

    echo '</div>';   // .split

    /* 배지가 뜨는 화면이므로 여기에도 같은 도움말·데이터 상태를 둔다.
     * 접힌 <details> 라 한 줄이고, 로그인 직후 첫 화면에서 궁금한 것을 그 자리에서 풀 수 있어야 한다. */
    pf_daily_note($pf);
    pf_render_badge_help();

    // 클릭 시 상세만 교체 (iframe 대신 fetch + History API). JS 없으면 링크가 그대로 동작한다.
    echo '<script>const PF_CLOSED=' . ($showClosed ? 'true' : 'false') . ';</script>';
    echo <<<'JS'
<script>
function pfFolioUrl(id, frag){
  return '/stock/index.php?id=' + id + (PF_CLOSED ? '&closed=1' : '') + (frag ? '&frag=1' : '');
}
function pfSelectFolio(id, push){
  var box = document.getElementById('pfDetail');
  if (!box) return;
  box.classList.add('loading');

  fetch(pfFolioUrl(id, true), { credentials: 'same-origin' })
    .then(function(r){ return r.text(); })
    .then(function(html){
      // 세션이 끊겨 로그인 페이지가 오면 그냥 이동시킨다
      if (html.indexOf('<!DOCTYPE') !== -1) { location.href = pfFolioUrl(id, false); return; }
      box.innerHTML = html;
      var body = box.querySelector('.fd-body');
      if (body) body.scrollTop = 0;
      box.classList.remove('loading');
      document.querySelectorAll('.folio-row').forEach(function(el){
        el.classList.toggle('on', el.dataset.id === String(id));
      });
      if (push) history.pushState({ pfId: id }, '', pfFolioUrl(id, false));
    })
    .catch(function(){ location.href = pfFolioUrl(id, false); });
}
document.addEventListener('click', function(e){
  var tr = e.target.closest ? e.target.closest('.folio-row') : null;
  if (!tr) return;
  e.preventDefault();
  pfSelectFolio(tr.dataset.id, true);
});
window.addEventListener('popstate', function(){
  var id = new URLSearchParams(location.search).get('id');
  if (id) pfSelectFolio(id, false);
});
</script>
JS;

    pf_foot();
}

/**
 * 포트폴리오 상세 영역 (요약 + 종목 목록).
 * 전체 페이지와 조각(frag) 양쪽에서 같은 함수를 쓴다.
 */
function pf_render_folio_detail(Pf $pf, int $fid, bool $showClosed): void
{
    $f = $fid ? $pf->portfolioGet($fid) : null;
    if (!$f) {
        echo '<div class="fd-head"><div class="fd-title">포트폴리오를 선택하세요</div></div>';
        echo '<div class="fd-empty">◀ 왼쪽 목록에서 포트폴리오를 클릭하면<br>여기에 담긴 종목과 매수·매도 신호가 표시됩니다</div>';
        return;
    }

    /* 합계는 <b>종료 포함 전부</b>로 낸 뒤, 표에 세울 행만 골라 낸다.
     * (청산분의 예수금·실현손익이 빠지면 왼쪽 목록과 값이 갈린다 — 실측 1,772,382원 차이가 그 사고였다) */
    [$all, $calc] = pf_load_calc($pf, $fid);
    $sub    = pf_sum_calc($calc);
    $rows   = pf_visible($all, $showClosed);

    $prin   = (float)$f['principal'];
    $sCash  = $prin + $sub['flow'];
    $sAsset = $sCash + $sub['net'];
    $sRate  = ($prin > 0) ? ($sAsset / $prin - 1) : null;
    $addUrl = '/stock/index.php?mode=position&id=new&pid=' . $fid;

    echo '<div class="fd-head"><div>';
    echo '<div class="fd-title">' . pf_h($f['name']) . '</div>';
    $ident = array_filter([$f['broker'], $f['acct_no'], $f['memo']]);
    if ($ident) echo '<div class="fd-sub">' . pf_h(implode(' · ', $ident)) . '</div>';
    $note = trim((string)($f['note'] ?? ''));
    if ($note !== '') echo '<div class="fd-note">' . nl2br(pf_h($note)) . '</div>';
    echo '</div>';
    echo '<a class="btn btn-primary btn-sm" href="' . $addUrl . '">＋ 종목 추가</a>';
    echo '</div>';

    echo '<div class="fd-body">';
    echo '<div class="sum-grid fd-sum">';
    foreach ([
        ['원금',     pf_n($prin),               ''],
        ['예수금',   pf_n(round($sCash)),       $sCash < 0 ? 'down' : ''],
        ['총매입',   pf_n(round($sub['cost'])), ''],
        ['총평가',   pf_n(round($sub['eval'])), ''],
        ['평가손익', pf_n(round($sub['pl'])),   pf_updown($sub['pl'])],
        ['실현손익', pf_n(round($sub['real'])), pf_updown($sub['real'])],
        ['추정자산', pf_n(round($sAsset)),      ''],
        ['수익률',   $sRate === null ? '-' : pf_pct($sRate), pf_updown($sRate)],
    ] as [$k, $v, $cls]) {
        echo '<div class="sum-box"><div class="k">' . pf_h($k) . '</div>';
        echo '<div class="v ' . $cls . '">' . pf_h($v) . '</div></div>';
    }
    echo '</div>';

    /* ★ 요약카드·소계는 <b>청산분까지</b> 센다 — 판 돈과 실현손익은 확정된 사실이다.
     *   그런데 종료 행은 표에 없으니, 그냥 두면 "표에 없는 실현손익 1,699,744원은 어디서 나왔나"가 된다.
     *   숫자를 표에 맞춰 깎는 대신 <b>출처를 한 줄로 밝힌다</b>(합계는 하나여야 한다). */
    $closedNote = function () use ($all, $calc, $showClosed, $fid): void {
        if ($showClosed) return;
        $hidden = array_filter($all, fn($p) => $p['status'] === 'closed');
        if (!$hidden) return;
        $hReal = 0.0;
        foreach ($hidden as $p) $hReal += (float)($calc[(int)$p['id']]['realized_pl'] ?? 0);
        echo '<p class="sub muted" style="margin:8px 0 0;font-size:12px">청산한 '
           . count($hidden) . '종목의 실현손익 ' . pf_signed($hReal)
           . '원이 위 합계에 들어 있습니다 (<a href="/stock/index.php?id=' . $fid
           . '&closed=1">종료 보기</a>로 표에 함께 표시).</p>';
    };

    if (!$rows) {
        echo '<div class="folio-empty">담긴 종목이 없습니다. '
           . '<a href="' . $addUrl . '">이 포트폴리오에 종목 추가</a>하면 차수·다음매수가가 자동 계산됩니다.</div>';
        $closedNote();
        echo '</div>';
        return;
    }

    // .pos = 종목 리스트 전용 — 행을 2줄로 쓰므로 줄간격·글자 크기를 따로 준다
    echo '<div class="tbl-scroll"><table class="pf pos"><thead><tr>';
    foreach ([
        ['종목명', ''], ['차수', 'num'], ['나이', 'num'], ['수익률', 'num'], ['현재가', 'num'],
        ['보유수량', 'num'], ['평가금액', 'num'], ['평가손익', 'num'], ['실현손익', 'num'],
        ['다음매수가', 'num'], ['다음수량', 'num'], ['누적단가', 'num'], ['자동매도가', 'num'],
    ] as [$label, $cls]) {
        echo '<th class="' . $cls . '">' . pf_h($label) . '</th>';
    }
    echo '</tr></thead><tbody>';

    $stairAl = pf_stair_alert_map(array_column($rows, 'stock_code'));

    foreach ($rows as $p) {
        $pid   = (int)$p['id'];
        $c     = $calc[$pid];
        $last  = ($p['last_price'] !== null) ? (float)$p['last_price'] : null;
        $watch = ($p['status'] !== 'open');

        echo '<tr class="' . ($watch ? 'watch' : '') . '">';

        /* 종목명은 크게, 코드는 그 아래 작게. 「보유」 배지는 뺀다 —
         * 대부분이 보유라 정보가 없고 자리만 먹는다. 관심·종료는 남긴다(그게 예외라서 눈에 띄어야 한다). */
        echo '<td class="stk"><a href="/stock/index.php?mode=position&id=' . $pid . '">'
           . pf_h($p['stock_name']) . '</a>'
           . '<span class="code">' . pf_h($p['stock_code'])
           . ($p['status'] !== 'open' ? ' ' . pf_status_badge($p['status']) : '')
           . '</span></td>';

        if (!$c) {
            echo '<td colspan="12" class="muted">룰셋에 차수가 없습니다 — '
               . '<a href="/stock/index.php?mode=ruleset">룰셋 설정</a></td></tr>';
            continue;
        }

        /* 차수 + 그 차수에 남은 매수 수량.
         * 옆에 붙이면 칸 폭을 잡아먹어 표가 밀린다 — 행이 이미 2줄이니 <b>아래로 쌓는다</b>.
         * 「다음매수」 열은 말 그대로 <b>다음</b> 차수라 이미 친 차수에 남은 몫을 담을 자리가 없다. */
        echo '<td class="num step"><span class="no">'
           . ($c['cur_step'] > 0 ? $c['cur_step'] . '차' : '#') . '</span>';
        if (!empty($c['fill_signal'])) {
            echo '<span class="more" title="'
               . $c['cur_step'] . '차 누적목표 ' . pf_n(round($c['plan_cum'][$c['cur_step']]))
               . ' − 투입 ' . pf_n(round($c['used_amount']))
               . ' = ' . pf_n(round($c['fill_amount'])) . '원'
               . ' · 현재가 ' . pf_n($last) . '원 기준 ' . pf_n($c['fill_qty']) . '주 더 살 수 있습니다">+'
               . pf_n($c['fill_qty']) . '주</span>';
        }
        echo '</td>';
        echo pf_age_cell($c, (string)$p['status'], $stairAl[$p['stock_code']] ?? '');
        echo '<td class="num">' . pf_signed_pct($c['rate']) . '</td>';
        echo '<td class="num">' . pf_n($last) . '</td>';
        echo '<td class="num">' . pf_n($c['filled_qty'] ?: null) . '</td>';
        echo '<td class="num">' . pf_n($c['eval_amount'] === null ? null : round($c['eval_amount'])) . '</td>';
        echo '<td class="num">' . pf_signed($c['eval_pl']) . '</td>';
        echo '<td class="num">' . (abs((float)$c['realized_pl']) > 0.5 ? pf_signed($c['realized_pl']) : '<span class="flat">-</span>') . '</td>';

        // 매수 구간이면 현재가 기준 수량(급락 시 여러 차수 합산), 아니면 이론가 기준 계획수량
        $buyCls = $c['buy_signal'] ? ' class="num hit"' : ' class="num"';
        echo '<td' . $buyCls . '><span>' . pf_n($c['next_price']) . '</span>' . pf_delay_badge($c) . '</td>';
        $showQty = $c['buy_signal'] ? $c['buy_qty'] : $c['next_qty'];
        echo '<td' . $buyCls . '><span>' . pf_n($showQty ?: null) . '</span></td>';
        echo '<td class="num">' . pf_n($c['avg_cost'] === null ? null : round($c['avg_cost'])) . '</td>';

        $sellCls = $c['sell_signal'] ? ' class="num hit"' : ' class="num"';
        echo '<td' . $sellCls . '><span>' . pf_n($c['sell_price']) . '</span></td>';
        echo '</tr>';
    }

    echo '</tbody><tfoot><tr>';
    echo '<td>소계</td><td colspan="5" class="num muted">다음 차수 소요 ' . pf_n(round($sub['reserve'])) . '</td>';
    echo '<td class="num">' . pf_n(round($sub['eval'])) . '</td>';
    echo '<td class="num">' . pf_signed($sub['pl']) . '</td>';
    echo '<td class="num">' . (abs($sub['real']) > 0.5 ? pf_signed($sub['real']) : '') . '</td>';
    echo '<td colspan="4"></td>';
    echo '</tr></tfoot></table></div>';
    $closedNote();
    echo '</div>';   // .fd-body
}

/**
 * 보유종목 — 포트폴리오 묶음을 풀어 <b>모든 종목을 한 표에</b> 세운다.
 *
 * 「현황」이 "포트폴리오별로 얼마인가"에 답한다면 여기는 "내 종목 전부를 한 줄로 세워 견주면"에 답한다.
 * 그래서 기본 정렬이 신호순이고, 거리(매수까지·매도까지) 열이 여기에만 다 있다.
 *
 * ?fid=N   포트폴리오 한 개만  ?sig=1 신호 있는 것만  ?closed=1 종료 포함  ?sort=…
 */
function pf_page_all(PDO $pdo, Pf $pf): void
{
    $showClosed = !empty($_GET['closed']);
    $onlySig    = !empty($_GET['sig']);
    $fid        = (int)($_GET['fid'] ?? 0);
    $sort       = (string)($_GET['sort'] ?? 'sig');

    $folios = $pf->portfolios();
    /* 예수금 게이트가 쓰는 포트폴리오 소계는 종료 포함 전부로 낸다(청산분도 계좌의 돈이다).
     * 표에 세우는 행만 「종료 포함」 체크박스를 따른다. */
    [$positions, $calc] = pf_load_calc($pf, $fid ?: null);

    $byFolio = [];
    foreach ($positions as $p) $byFolio[(int)$p['portfolio_id']][] = $p;

    $stats = pf_folio_stats($folios, $byFolio, $calc);
    $listed = pf_visible($positions, $showClosed);
    $daily  = $pf->dailyMap(array_column($listed, 'stock_code'), 400);
    $rows   = pf_signal_list($listed, $calc, $stats, $daily);   // 기본이 신호순
    $total = count($rows);
    $nSig  = count(array_filter($rows, fn($g) => $g['s']['kind'] !== null));

    if ($onlySig) $rows = array_values(array_filter($rows, fn($g) => $g['s']['kind'] !== null));

    /* 정렬. 'sig' 는 pf_signal_list() 가 이미 만들어 둔 순서라 손대지 않는다.
     * 내림차순 항목의 null 은 −INF 로 밀어 맨 뒤로 보낸다 (시세 미수집 종목이 위에 오면 안 된다). */
    $sorters = [
        'gap'   => fn($a, $b) => ($a['s']['gap'] ?? INF) <=> ($b['s']['gap'] ?? INF),
        'rate'  => fn($a, $b) => ($b['c']['rate'] ?? -INF) <=> ($a['c']['rate'] ?? -INF),
        'pl'    => fn($a, $b) => ($b['c']['eval_pl'] ?? -INF) <=> ($a['c']['eval_pl'] ?? -INF),
        'eval'  => fn($a, $b) => ($b['c']['eval_amount'] ?? -INF) <=> ($a['c']['eval_amount'] ?? -INF),
        'step'  => fn($a, $b) => ($b['c']['cur_step'] ?? 0) <=> ($a['c']['cur_step'] ?? 0),
        'name'  => fn($a, $b) => strcmp((string)$a['p']['stock_name'], (string)$b['p']['stock_name']),
        'folio' => fn($a, $b) => strcmp($a['fname'], $b['fname'])
                              ?: strcmp((string)$a['p']['stock_name'], (string)$b['p']['stock_name']),
    ];
    if (isset($sorters[$sort])) usort($rows, $sorters[$sort]);

    pf_head('보유종목', 'dashboard', 'wide');
    pf_subtabs('all', 'dashboard');
    pf_flash();

    echo '<div class="pf-head"><div>';
    echo '<h1>보유종목</h1>';
    echo '<div class="sub">담긴 종목 ' . $total . '개 중 지금 신호가 있는 것은 <b>' . $nSig . '건</b>입니다. '
       . '「매수까지·매도까지」는 그 가격에 닿기까지 남은 거리입니다.</div>';
    echo '</div><div class="act">';
    echo '<a class="btn btn-outline" href="/stock/index.php">현황으로</a>';
    echo '</div></div>';

    // ── 거르기 / 정렬 (GET 폼 하나로 처리 — JS 없이 동작한다)
    echo '<form class="filter-bar" method="get" action="/stock/index.php">';
    echo '<input type="hidden" name="mode" value="all">';

    echo '<label class="fld">포트폴리오<select name="fid">';
    echo '<option value="0"' . ($fid === 0 ? ' selected' : '') . '>전체</option>';
    foreach ($folios as $f) {
        $v = (int)$f['id'];
        echo '<option value="' . $v . '"' . ($v === $fid ? ' selected' : '') . '>' . pf_h($f['name']) . '</option>';
    }
    echo '</select></label>';

    echo '<label class="fld">정렬<select name="sort">';
    foreach ([
        'sig'   => '신호순 (매도→매수→잔여)',
        'gap'   => '임박한 순 (거리)',
        'rate'  => '수익률 높은 순',
        'pl'    => '평가손익 큰 순',
        'eval'  => '평가금액 큰 순',
        'step'  => '차수 높은 순',
        'name'  => '종목명',
        'folio' => '포트폴리오',
    ] as $k => $label) {
        echo '<option value="' . $k . '"' . ($k === $sort ? ' selected' : '') . '>' . pf_h($label) . '</option>';
    }
    echo '</select></label>';

    echo '<label class="chk"><input type="checkbox" name="sig" value="1"' . ($onlySig ? ' checked' : '') . '> 신호 있는 것만</label>';
    echo '<label class="chk"><input type="checkbox" name="closed" value="1"' . ($showClosed ? ' checked' : '') . '> 종료 포함</label>';
    echo '<button class="btn btn-primary" type="submit">적용</button>';
    echo '</form>';

    if (!$rows) {
        echo '<div class="card"><p class="muted">'
           . ($onlySig ? '지금 신호가 있는 종목이 없습니다.' : '담긴 종목이 없습니다.') . '</p></div>';
        pf_foot();
        return;
    }

    echo '<div class="tbl-scroll"><table class="pf pos"><thead><tr>';
    foreach ([
        ['종목명', ''], ['포트폴리오', ''], ['차수', 'num'], ['나이', 'num'], ['신호', ''], ['시장', ''], ['유동성', ''],
        ['현재가', 'num'], ['수익률', 'num'], ['보유수량', 'num'], ['평가금액', 'num'], ['평가손익', 'num'],
        ['다음매수가', 'num'], ['매수까지', 'num'], ['자동매도가', 'num'], ['매도까지', 'num'], ['누적단가', 'num'],
    ] as [$label, $cls]) {
        echo '<th class="' . $cls . '">' . pf_h($label) . '</th>';
    }
    echo '</tr></thead><tbody>';

    $sumEval = $sumPl = 0.0;
    $stairAl = pf_stair_alert_map(array_map(fn($g) => (string)$g['p']['stock_code'], $rows));
    foreach ($rows as $g) {
        $p = $g['p'];
        $c = $g['c'];
        $s = $g['s'];
        $sumEval += (float)($c['eval_amount'] ?? 0);
        $sumPl   += (float)($c['eval_pl'] ?? 0);

        echo '<tr class="' . ($p['status'] !== 'open' ? 'watch' : '') . '">';
        echo '<td class="stk"><a href="/stock/index.php?mode=position&id=' . $g['pid'] . '">'
           . pf_h($p['stock_name']) . '</a><span class="code">' . pf_h($p['stock_code'])
           . ($p['status'] !== 'open' ? ' ' . pf_status_badge($p['status']) : '') . '</span></td>';
        echo '<td class="muted"><a href="/stock/index.php?id=' . $g['fid'] . '">' . pf_h($g['fname']) . '</a></td>';

        if (!$c) {
            echo '<td colspan="15" class="muted">룰셋에 차수가 없습니다 — '
               . '<a href="/stock/index.php?mode=ruleset">룰셋 설정</a></td></tr>';
            continue;
        }

        echo '<td class="num">' . ((int)$c['cur_step'] > 0 ? $c['cur_step'] . '차' : '#') . '</td>';
        echo pf_age_cell($c, (string)$p['status'], $stairAl[$p['stock_code']] ?? '');

        // 신호 — 종류 + 수량. 대기는 비워 둔다(잡음을 줄인다)
        echo '<td>';
        if ($s['kind'] !== null) {
            echo '<span class="sig-pill k-' . $s['kind'] . '">' . pf_sig_label($s['kind'])
               . ' ' . pf_n($s['qty']) . '주</span>';
            if (($g['conf']['label'] ?? '') !== '') {
                echo '<div class="sig-conf lv-' . pf_h($g['conf']['level']) . '" title="'
                   . pf_h($g['conf']['why']) . '">'
                   . ($g['conf']['level'] === 'strong' ? '◎ ' : '△ ') . pf_h($g['conf']['label']) . '</div>';
            }
        } else {
            echo '<span class="flat">-</span>';
        }
        echo '</td>';

        echo '<td>' . (empty($g['mkt']) ? '<span class="flat">-</span>' : pf_mkt_badges($g['mkt'])) . '</td>';

        /* 유동성 — 등급 + 일평균 거래대금, 아래에 참여율.
         * ★ 배지가 아니라 <b>열</b>로 둔 이유: 유동성은 오늘 일어난 사건이 아니라 상시 특성이라
         *   늘 같은 자리에 있어야 종목끼리 견줄 수 있다. 배지로 두면 3개 슬롯을 상시 점유한다. */
        $liq = $g['liq'];
        echo '<td>';
        if (empty($liq) || ($liq['grade'] ?? null) === null) {
            echo '<span class="flat">-</span>';
        } else {
            $part = $liq['part'];
            echo '<span class="liq g-' . pf_h($liq['grade']) . '" title="'
               . pf_h('일평균 거래대금 ' . number_format($liq['avg_val'] / 100000000, 2) . '억 · '
                      . '거래대금 1억 미만인 날 ' . number_format($liq['thin_ratio'] * 100, 0) . '%'
                      . ($part !== null ? ' · 계획 ' . number_format($liq['plan_qty']) . '주 = 일평균의 '
                                          . number_format($part * 100, 1) . '%' : ''))
               . '">' . pf_liq_label($liq['grade']) . '</span>';
            echo '<div class="liq-sub">' . number_format($liq['avg_val'] / 100000000, 1) . '억'
               . ($part !== null ? ' · <b class="' . ($part >= 0.10 ? 'down' : '') . '">'
                                   . number_format($part * 100, 1) . '%</b>' : '') . '</div>';
        }
        echo '</td>';

        echo '<td class="num">' . pf_n($g['last']) . '</td>';
        echo '<td class="num">' . pf_signed_pct($c['rate']) . '</td>';
        echo '<td class="num">' . pf_n($c['filled_qty'] ?: null) . '</td>';
        echo '<td class="num">' . pf_n($c['eval_amount'] === null ? null : round($c['eval_amount'])) . '</td>';
        echo '<td class="num">' . pf_signed($c['eval_pl']) . '</td>';

        $buyCls = ($s['kind'] === 'buy') ? ' hit' : '';
        echo '<td class="num' . $buyCls . '"><span>' . pf_n($c['next_price']) . '</span>' . pf_delay_badge($c) . '</td>';
        echo pf_gap_cell($s['buy_gap'], 'down', $s['near'] === 'buy');

        $sellCls = ($s['kind'] === 'sell') ? ' hit' : '';
        echo '<td class="num' . $sellCls . '"><span>' . pf_n($c['sell_price']) . '</span></td>';
        echo pf_gap_cell($s['sell_gap'], 'up', $s['near'] === 'sell');

        echo '<td class="num">' . pf_n($c['avg_cost'] === null ? null : round($c['avg_cost'])) . '</td>';
        echo '</tr>';
    }

    echo '</tbody><tfoot><tr>';
    echo '<td>소계 ' . count($rows) . '종목</td><td colspan="9"></td>';   // 「나이」 열이 늘어 8→9
    echo '<td class="num">' . pf_n(round($sumEval)) . '</td>';
    echo '<td class="num">' . pf_signed($sumPl) . '</td>';
    echo '<td colspan="5"></td>';
    echo '</tr></tfoot></table></div>';

    pf_daily_note($pf);
    pf_render_badge_help();
    pf_foot();
}

/**
 * 전략 판단 한 줄 — 종목 상세용. 현황 화면의 신호 카드에 있던 것을 그대로 옮긴 것이다.
 *
 * ★ 값을 여기서 다시 만들지 않는다. 인자로 받은 $me 는 pf_signal_list() 가 만든 행이라
 *   현황 카드와 <b>같은 판정</b>이 보장된다 — 예수금 게이트의 누적 배정까지 같다.
 *
 * 순서가 곧 읽는 순서다: 무엇을 할까(신뢰도) → 할 수 있나(돈 · 물량) → 왜(시장 상태 · 유동성).
 */
function pf_render_strategy_row(array $me): void
{
    $s    = $me['s']    ?? ['kind' => null, 'amount' => 0];
    $gate = $me['gate'] ?? ['level' => 'ok', 'label' => '', 'why' => ''];
    $conf = $me['conf'] ?? ['level' => 'normal', 'label' => '', 'why' => ''];
    $liq  = $me['liq']  ?? [];

    // 아무것도 말할 것이 없으면 자리를 먹지 않는다
    if (($conf['label'] ?? '') === '' && ($gate['label'] ?? '') === ''
        && empty($me['mkt']) && ($liq['grade'] ?? null) === null) return;

    echo '<div class="strat-row"><span class="sr-k">전략 판단</span>';

    if (($conf['label'] ?? '') !== '') {
        echo '<span class="sig-conf lv-' . pf_h($conf['level']) . '" title="' . pf_h($conf['why']) . '">'
           . ($conf['level'] === 'strong' ? '◎ ' : '△ ') . pf_h($conf['label']) . '</span>';
    }

    // 예수금 게이트 — 신호가 있고 매수 쪽일 때만 값이 실린다
    if (($me['fund_ok'] ?? null) !== null) {
        $left = (float)$me['fund_left'];
        $amt  = (float)$s['amount'];
        echo $me['fund_ok']
            ? '<span class="sig-fund fine">✓ 실행가능 · 예수금 잔여 ' . pf_n(round($left - $amt)) . '</span>'
            : '<span class="sig-fund short">⚠ 예수금 ' . pf_n(round(max(0.0, $left)))
              . ' — ' . pf_n(round($amt - max(0.0, $left))) . ' 부족</span>';
    }

    // 체결 게이트 — 충분하면 굳이 말하지 않는다(늘 뜨는 표시는 표시가 아니다)
    if ($gate['level'] !== 'ok' && ($gate['label'] ?? '') !== '') {
        echo '<span class="sig-fill ' . pf_h($gate['level']) . '" title="' . pf_h($gate['why']) . '">'
           . ($gate['level'] === 'hard' ? '⚠ ' : '△ ') . pf_h($gate['label']);
        if (($liq['impact'] ?? null) !== null) {
            echo ' <span class="fi-imp">슬리피지 ~' . number_format((float)$liq['impact'] * 100, 1) . '%</span>';
        }
        echo '</span>';
    }

    if (!empty($me['mkt'])) echo pf_mkt_badges($me['mkt']);

    if (($liq['grade'] ?? null) !== null) {
        $part = $liq['part'];
        echo '<span class="liq g-' . pf_h($liq['grade']) . '" title="'
           . pf_h('일평균 거래대금 ' . number_format($liq['avg_val'] / 100000000, 2) . '억 · '
                  . '거래대금 1억 미만인 날 ' . number_format($liq['thin_ratio'] * 100, 0) . '%')
           . '">유동성 ' . pf_liq_label($liq['grade'])
           . ' ' . number_format($liq['avg_val'] / 100000000, 1) . '억'
           . ($part !== null ? ' · 계획 ' . number_format($part * 100, 1) . '%' : '') . '</span>';
    }
    echo '</div>';
}

/**
 * 배지 도움말 — 접혀 있다. 배지가 뜨는 화면(현황·보유종목) 아래에 같은 것을 둔다.
 *
 * ★ 배지의 title 속성에도 한 줄 근거가 있지만 그것만으로는 부족하다. 마우스를 올려야 보이고,
 *   무엇보다 <b>"이게 내 분할매수에 어떤 뜻인가"</b>는 한 줄에 안 들어간다.
 *   그 판단이 이 시스템의 전부이므로 표로 펼쳐 둔다.
 * ★ <details> 라 JS 가 없다. 접힌 상태에서는 한 줄이라 화면을 먹지 않는다.
 */
function pf_render_badge_help(): void
{
    echo <<<'HTML'
<details class="mkt-help">
<summary>배지 도움말 — 이 표시가 무엇이고, 내 매매에 어떤 뜻인지</summary>
<div class="mh-body">

<div class="mh-legend">
  <b>색이 먼저입니다</b>
  <span><span class="mkt t-buyish">초록</span> 사는 쪽에 유리</span>
  <span><span class="mkt t-sellish">붉은</span> 파는 쪽에 유리</span>
  <span><span class="mkt t-risk">주황</span> 경고</span>
  <span><span class="mkt t-info">회색</span> 참고 (방향 없음)</span>
</div>
<p class="mh-note">주가 등락색(빨강=상승 / 파랑=하락)과 <b>일부러 다른 팔레트</b>를 씁니다.
같은 빨강이 한 화면에서 "주가 상승"과 "매수 계획"과 "급등"을 동시에 뜻하면 읽을 수 없기 때문입니다.</p>

<div class="tbl-scroll"><table class="pf mh-tbl"><thead><tr>
  <th style="min-width:118px">배지</th><th style="min-width:150px">기준</th>
  <th>왜 보는가</th><th>분할매수에서의 뜻 · 행동</th>
</tr></thead><tbody>

<tr><td><span class="mkt t-risk">재평가</span></td>
  <td>사이클 나이 <b>2년 경과</b><br>또는 <b>5차 도달</b><br><span class="muted">먼저 오는 쪽</span></td>
  <td>사이클 226개 실측 — 물림비율이 4차까지 9% 이하인데 <b>5차 23% · 6차 42% · 7차 50%</b> 로 꺾이고,
      2년을 넘긴 사이클의 3분의 1은 끝내 닫히지 않았습니다(3년이면 동전던지기).</td>
  <td><b>자동으로 막지 않습니다.</b> 시간손절·비중동결을 백테스트했더니 기계식 규칙은 수익을 깎았습니다
      (1년 손절은 승자까지 자르고, 물타기를 멈추면 탈출 목표가 영영 안 내려옵니다).<br>
      → 이 배지가 뜨면 <b>이 종목이 정말 반등형인지</b>(박스권 이력·거래량) 다시 판단하세요.
      가설이 무너졌으면 그때 손절이 맞습니다.</td></tr>

<tr><td><span class="mkt t-risk">계단관통↓</span></td>
  <td>최근 최고 거래대금 박스의<br><b>아래 계단(지지)이 전부</b><br>종가로 뚫림</td>
  <td>지지 붕괴 전수의 <b>2~3%</b>인 드문 사건 — 「떨어져도 반등한다」는 분할매수의 전제가
      깨졌다는 가격 구조 신호입니다(퀀트 원장 실측).</td>
  <td><b>자동으로 팔지 않습니다.</b> 사다리 백테스트(2026-08-01)에서 이 신호에 중단·청산하면
      <b>물림이 11.3%→5.2%로 절반</b>(양 기간 일관), 비용은 중앙 −0.8%p 였습니다.<br>
      → 시간·낙폭 기반이 아니라 <b>지지구조 소멸</b> 기준이라 승자를 거의 안 자릅니다.
      시뮬레이터의 「계단관통 손절」 옵션으로 이 종목에서의 효과를 직접 확인해 보세요.</td></tr>

<tr><td><span class="mkt t-risk">역배열</span><span class="mkt t-info">정배열</span></td>
  <td>5일선 &lt; 20일선 &lt; 60일선<br><span class="muted">반대면 정배열 · 섞이면 표시 없음</span></td>
  <td>추세의 <b>방향과 나이</b>를 한 번에 말합니다. 5일선이 20일선 아래면 "최근 1주가 최근 1달보다 나쁘다",
      20일선이 60일선 아래면 "최근 1달이 최근 3달보다 나쁘다" — 즉 <b>나빠지는 속도가 붙었다</b>는 뜻입니다.</td>
  <td><b>가장 중요한 배지입니다.</b> 분할매수는 떨어지면 더 사는 <b>역추세 전략</b>이라,
      하락 추세가 살아 있으면 총알을 다 쓴 뒤에도 계속 떨어지는 상황이 생깁니다.
      룰셋이 −30%까지 깔아 뒀어도 <b>그게 바닥이 아닐</b> 수 있다는 경고입니다.<br>
      → 역배열이면 급락 시 <b>여러 차수를 한 번에 채우지 말고</b> 한 칸씩.</td></tr>

<tr><td><span class="mkt t-info">이격 ±N%</span></td>
  <td>현재가 ÷ 20일선 − 1<br><span class="muted">±10% 이상일 때만</span></td>
  <td>가격은 평균에서 무한정 멀어지지 않습니다(평균회귀). 20일선은 <b>한 달 평균 매매가</b>라,
      이격이 크게 음수면 최근 한 달 참여자 대부분이 손실 중이라는 뜻이기도 합니다.</td>
  <td><b>"룰셋 몇 차 수준까지 빠졌나"의 대용치</b>입니다 — 하락률이 −9/−12/−16/−20/−25/−30%이니
      이격 −17%면 3~4차, −29%면 6~7차 구간의 하락 속도입니다.<br>
      → 단독으로 사면 안 됩니다. <b>20일선이 계속 내려오면 가격이 안 올라도 이격은 줄어듭니다</b>.
      반드시 역배열 여부와 함께 보세요.</td></tr>

<tr><td><span class="mkt t-buyish">과매도 RSI</span><span class="mkt t-sellish">과매수 RSI</span></td>
  <td>RSI(14) ≤ 30 / ≥ 70<br><span class="muted">Wilder 평활</span></td>
  <td>가장 널리 쓰이는 오실레이터입니다. 최근 14일의 상승분 ÷ (상승분+하락분)이라
      "<b>내려간 날이 올라간 날을 압도했다</b>"를 0~100으로 압축합니다. 30 이하는 단기 반등이 잦은 구간입니다.
      <span class="muted">(단순평균 RSI 는 같은 이름으로 다른 숫자가 나와 증권사 화면과 어긋나므로 쓰지 않습니다)</span></td>
  <td>룰셋은 <b>가격</b>으로만 매수 시점을 정합니다. RSI 는 거기에 <b>속도</b>를 더합니다 —
      같은 −16% 하락이라도 RSI 28이면 급하게 빠진 것(반등 여력), 45면 천천히 빠진 것(추세적 하락)입니다.<br>
      → 매수 신호 + 과매도 = <b>◎ 근거 강함</b>. 자동매도가 도달 + 과매수 = 팔 근거가 강함.</td></tr>

<tr><td><span class="mkt t-risk">52주 최저권</span><span class="mkt t-sellish">52주 최고권</span></td>
  <td>(현재가 − 52주최저) ÷ (52주최고 − 52주최저)<br><span class="muted">하위 5% 이하 / 상위 95% 이상</span></td>
  <td><b>과매도와 방향은 같지만 뜻이 다릅니다.</b> RSI 가 단기(14일) <b>속도</b>라면 이건 1년치 <b>구조적 위치</b>입니다.
      52주 최저가를 계속 깨는 종목은 "싸진 것"이 아니라 <b>나빠지고 있는 것</b>일 수 있습니다 —
      그래서 색도 기회(초록)가 아니라 경고(주황)입니다.</td>
  <td><b>52주 최저권 + 역배열</b>이 이 시스템이 가장 강하게 경고하는 조합입니다(<b>△ 주의</b>).<br>
      → 계획대로 담되 차수를 건너뛰지 말고 한 칸씩. 추세 훼손인지 투매 바닥인지는 <b>거래량</b>이 갈라 줍니다.</td></tr>

<tr><td><span class="mkt t-info">거래량 N배</span><span class="mkt t-info">★ 거래량 N배</span></td>
  <td>당일 거래량 ÷ 최근 20일 평균<br><span class="muted">2배 이상 · ★ 는 얇은 종목</span></td>
  <td><i>Volume confirms price</i> — 거래량은 <b>그 가격 변화에 얼마나 많은 사람이 동의했는지</b>를 말합니다.
      거래량 없는 급등은 되돌림이 잦고(몇 명이 올린 것), 바닥권에서 <b>거래량 실린 급락</b>은
      투매(capitulation) — 팔 사람이 다 팔았다는 신호로 봅니다.<br>
      <b>★</b> 는 유동성이 얇은 종목입니다. 평소 하루 2천만원어치만 거래되는 종목에 3배가 붙는 것은
      대형주의 3배와 <b>전혀 다른 사건</b>입니다 — <b>없던 관심이 생겼다</b>는 뜻이라 크게 읽어야 합니다.</td>
  <td><b>이 배지만 방향이 없습니다(회색).</b> 단독으로는 아무 뜻이 없고 다른 배지와 결합해야 뜻이 생깁니다 —
      신뢰도 규칙이 <code>(과매도 또는 급락) + 거래량 급증 = 투매</code> 인 이유입니다.<br>
      → <b>장중에는 오늘 거래량이 하루의 일부</b>라 이 배수는 늘 <b>하한</b>입니다.
      뜬 배지는 진짜이고, 아직 안 찬 것만 마감 뒤에 잡힙니다.<br>
      → 배수는 <b>주수로 재도 됩니다</b> — 같은 종목의 자기 비교라 단위가 상쇄됩니다.
      거래대금이 필요한 자리는 종목끼리 견주는 「유동성」이고 그건 아래 열이 맡습니다.</td></tr>

<tr><td><span class="mkt t-risk">유동성 매우 얇음</span><br>
        <span class="liq g-deep">풍부</span><span class="liq g-thin">얇음</span><span class="liq g-very_thin">매우 얇음</span></td>
  <td>최근 20일 <b>평균 거래대금</b><br>
      풍부 ≥50억 · 보통 ≥10억 · 얇음 ≥1억 · <b>매우 얇음 &lt;1억</b><br>
      <span class="muted">배지는 「매우 얇음」만 · 등급은 보유종목 표의 열에 항상 있습니다</span></td>
  <td><b>신호가 떠도 물량이 없으면 살 수 없습니다.</b> 지금까지 매수 비용은 수수료+세금(0.34%)만 셌지만,
      실측(2026-07-30) 한국주철관 849주를 호가를 훑어 사면 평균 체결가가 계획가보다 <b>약 1.4%</b> 높습니다 —
      <b>수수료의 4배</b>입니다.<br>
      척도는 거래량(주수)이 아니라 <b>거래대금</b>입니다. 주수로 재면 저가주가 유동성 좋아 보입니다.<br>
      <span class="muted">호가 잔량은 쓰지 않습니다 — 네이버 호가 API 는 폐지됐고, 있어도 초 단위로 변해
      "며칠에 나눠 담자"는 계획을 세울 수 없습니다. 기관도 ADV 대비 참여율로 집행을 관리합니다.</span></td>
  <td>유동성이 <b>한도의 상한</b>을 정합니다. 일평균 거래대금이 0.2억인 종목에 한도 1,700만원을 그대로 쓰면
      후반 차수(24%·29% = 400만·490만)는 <b>애초에 실행 불가능한 계획</b>입니다.<br>
      → 열 아래 숫자는 <b>일평균 거래대금 · 참여율</b>입니다. 참여율이 10%를 넘으면 붉게 표시됩니다.</td></tr>

<tr><td><span class="sig-fill split">△ 분할 권장</span><br><span class="sig-fill hard">⚠ 3일 분할 필요</span></td>
  <td><b>참여율</b> = 계획 수량 ÷ 일평균 거래량<br>
      5% 미만 충분 · 5~10% 분할 권장 · <b>10% 이상 분할 필요</b><br>
      <span class="muted">얇은 종목은 2% 부터 분할 권장</span></td>
  <td><b>예수금 게이트의 짝</b>입니다 — 둘은 "실행할 수 있나"라는 같은 질문의 두 축(돈 / 물량)입니다.
      하루 거래량의 10%를 차지하면 <b>내가 가격을 밀어올려서</b> 사게 됩니다.<br>
      얇은 종목은 한 단계 올려 봅니다 — 평균 거래량은 "하루에 여러 번 나눠 거래된 결과"일 뿐,
      지금 호가에 그만큼이 걸려 있지 않습니다.</td>
  <td>→ <b>추정 슬리피지</b>는 √법칙(변동성 × √참여율)입니다. <b>하루에 나눠 담는 기준</b>이라
      즉시 시장가로 치면 이보다 큽니다 — <b>하한</b>으로 읽으세요.<br>
      → 실측: 매일홀딩스는 다음 차수 267주가 일평균 거래량의 <b>11.5%</b>이고 거래대금 1억 미만인 날이
      <b>85%</b>입니다. 이 종목은 계획을 며칠에 걸쳐 나눠야 합니다.</td></tr>

<tr><td><span class="mkt t-sellish">급등 +N%</span><span class="mkt t-buyish">급락 −N%</span></td>
  <td>|일간 등락| ≥ <b>2σ</b>(최근 20일 표준편차)<br><b>또는</b> ≥ <b>5%</b></td>
  <td>"±5% 급등" 같은 고정 기준은 삼성전자와 코스닥 소형주에 같은 뜻이 아닙니다. 평소 0.8%씩 움직이는 종목의
      +3%는 사건이고 평소 3%씩 움직이는 종목의 +5%는 평범합니다 — 그래서 <b>평소 변동성으로 정규화</b>합니다.<br>
      <b>단 σ 하나로는 놓칩니다</b>: 실측(2026-07-30) 삼성전자 <b>+5.52% 가 0.8σ</b> 였습니다.
      최근 20일이 출렁이면 σ 가 커져 큰 움직임도 작아 보입니다(변동성 클러스터링). 그날 보유 14종목 중
      2σ 를 넘은 종목이 하나도 없었습니다 — 그건 기준이 아니라 체입니다. 그래서 <b>절대 5% 와 OR</b> 로 묶었습니다.</td>
  <td>급락은 <b>다음 차수 도달이 임박</b>했다는 신호, 급등은 <b>자동매도가 도달이 가까워졌다</b>는 신호입니다.<br>
      → 급락은 <b>왜 빠졌는지</b>(실적·뉴스·시장 전체)를 반드시 따로 확인하세요. 배지는 이유를 모릅니다.</td></tr>

<tr><td><span class="mkt t-buyish">N일 연속 하락</span><span class="mkt t-sellish">N일 연속 상승</span></td>
  <td>종가 기준 3일 이상</td>
  <td>가장 단순한 단기 과냉/과열 지표입니다. 3~4일 연속 하락은 단기 반등 확률이 통계적으로 높고,
      심리적으로는 <b>투매가 아직 진행 중</b>이라는 뜻이라 하루 더 기다릴 근거가 됩니다.</td>
  <td>→ 매수 신호 + 연속 하락 중이면 <b>오늘 다 담지 않고</b> 반등을 확인하는 판단이 가능합니다.
      시스템은 자동으로 미루지 않습니다 — 판단은 사용자가 합니다.</td></tr>

</tbody></table></div>

<h4 class="mh-h">◎ 근거 강함 / △ 주의 — 계획 신호를 시장 상태로 보정한 것</h4>
<p class="mh-note">지표만 늘어놓으면 "그래서 어쩌라고"가 남습니다. 이 시스템의 매매 판단은 <b>룰셋이 이미 내립니다</b> —
시장 배지의 역할은 그 판단의 <b>신뢰도를 보정</b>하는 것입니다.</p>
<div class="tbl-scroll"><table class="pf mh-tbl"><tbody>
<tr><td style="min-width:300px">매수 신호 + (과매도 또는 급락) + 거래량 급증</td>
    <td><span class="sig-conf lv-strong">◎ 근거 강함 — 투매</span></td>
    <td>팔 사람이 다 팔았을 자리입니다. 계획대로 담을 근거가 강합니다.</td></tr>
<tr><td>매수 신호 + <b>역배열 + 52주 최저권</b></td>
    <td><span class="sig-conf lv-caution">△ 주의 — 하락추세</span></td>
    <td><b>과매도라도 이쪽이 이깁니다.</b> 하락추세 초기 물타기가 이 전략의 가장 큰 위험이기 때문입니다.</td></tr>
<tr><td>매도 신호 + (과매수 또는 급등)</td>
    <td><span class="sig-conf lv-strong">◎ 근거 강함 — 과열</span></td>
    <td>목표 도달과 과열이 겹쳤습니다. 팔 근거가 강합니다.</td></tr>
<tr><td>매도 신호 + <b>정배열 + 52주 최고권</b></td>
    <td><span class="sig-conf lv-caution">△ 분할매도 고려</span></td>
    <td>추세가 살아 있습니다. 전량 매도하면 남은 추세를 놓칠 수 있습니다.</td></tr>
</tbody></table></div>

<h4 class="mh-h">배지가 말하지 <b>않는</b> 것 — 한계를 알고 쓰세요</h4>
<ol class="mh-lim">
<li><b>뉴스·실적을 모릅니다.</b> 급락이 실적 쇼크인지 시장 전체 조정인지 구별하지 못합니다. 이유는 직접 확인해야 합니다.</li>
<li><b>표본이 필요합니다.</b> 정배열/역배열은 60봉, 52주 위치는 200봉이 있어야 계산합니다.
    새로 담은 종목은 배지가 적게 뜨는데 그건 "특이한 게 없다"가 아니라 <b>아직 모른다</b>입니다.</li>
<li><b>지수를 보지 않습니다.</b> 여러 종목이 동시에 역배열이면 개별 종목 문제가 아니라 시장 전체일 가능성이 큽니다 —
    그러면 종목별 판단보다 <b>한도를 얼마나 쓸지</b>가 더 중요한 결정이 됩니다.</li>
<li><b>되돌림을 보지 못합니다.</b> 어제 −20% 갔다가 오늘 회복했으면 배지는 조용합니다 — 현재가 <b>한 점</b>만 봅니다.</li>
<li><b>호가를 보지 못합니다.</b> 유동성은 <b>과거 20일 평균</b>으로 잰 것이라 "지금 이 순간 호가에 몇 주가
    걸려 있나"는 모릅니다. 얇은 종목은 주문 전에 <b>반드시 호가창을 직접 확인</b>하세요.</li>
</ol>

<p class="mh-note">배지에 마우스를 올리면 그 종목의 실제 값이 담긴 한 줄 근거가 나옵니다.</p>
</div>
</details>
HTML;
}

/**
 * 일봉 백데이터 상태를 화면에 밝힌다.
 *
 * ★ 시장 배지가 안 뜨는 이유가 "특이한 게 없어서"인지 "데이터가 없어서"인지 구별되어야 한다.
 *   구별이 안 되면 조용히 신호를 놓친 걸 알아챌 방법이 없다.
 */
function pf_daily_note(Pf $pf): void
{
    $st  = $pf->dailyStatus();
    $n   = (int)$st['codes'];
    $to  = (string)($st['to_d'] ?? '');
    $old = ($to !== '' && $to < date('Y-m-d', strtotime('-4 days')));

    echo '<p class="sub muted" style="margin:10px 0 0;font-size:12px">';
    echo '<b>시장</b> 배지는 그 종목의 <b>평소와 견준</b> 값입니다 — 등락은 σ(최근 20일 표준편차), '
       . '거래량은 20일 평균 대비 배수입니다. "±5% 급등" 같은 고정 기준은 종목마다 뜻이 달라 쓰지 않습니다.<br>';
    echo '<b>◎ 근거 강함 / △ 주의</b>는 계획 신호를 시장 상태로 보정한 것입니다 — '
       . '매수 신호 + 과매도·급락 + 거래량 급증이면 투매 구간이고, '
       . '매수 신호 + 역배열 + 52주 최저권이면 하락추세 초기라 <b>천천히</b> 담는 편이 안전합니다.<br>';
    if ($n === 0) {
        echo '<b class="down">일봉 이력이 아직 없습니다</b> — '
           . '<code>cron_job.php?task=dart_daily</code> 를 한 번 돌리면 채워집니다. '
           . '그때까지 시장 배지는 비어 있습니다.';
    } else {
        echo '일봉 <b>' . $n . '종목</b> · ' . number_format((int)$st['rows_n']) . '행 ('
           . pf_h((string)$st['from_d']) . ' ~ <b>' . pf_h($to) . '</b>) 기준입니다. '
           . '장 마감 뒤 크론(<code>job=eod</code>)이 하루 한 번 이어받고, 오늘 값은 실시간 주가로 갈아 반영합니다. '
           . '장중에는 오늘 거래량이 <b>하루의 일부</b>라 「거래량 N배」는 <b>하한</b>입니다 — '
           . '뜬 배지는 진짜이고, 아직 안 찬 것만 마감 뒤에 잡힙니다.'
           . ($old ? ' <b class="down">★ 마지막 일봉이 나흘 넘게 지났습니다 — 크론을 확인하세요.</b>' : '');
    }
    echo '</p>';
}

/**
 * 매매히스토리 — 체결한 뒤 가격이 <b>어느 쪽으로 갔는지</b> 되짚는다.
 *
 * 「현황」·「보유종목」이 지금을 보는 화면이라면 여기는 <b>지나간 판단을 채점하는</b> 화면이다.
 *
 * ★ 매수와 매도를 한 표에 둔다. 묻는 것이 같기 때문이다 — "그 뒤 가격이 내 편이었나?"
 *   매수는 올라야, 매도는 내려야 잘한 것이라 <b>부호만 반대</b>다(pf_trade_review 가 흡수한다).
 *   표를 둘로 쪼개면 같은 열을 두 벌 만들고, 한 종목의 매수→매도가 시간순으로 읽히는
 *   이야기(사이클)가 끊긴다. 대신 <b>적중률은 매수·매도를 나눠</b> 센다 —
 *   섞으면 "매수는 늘 이르고 매도는 늘 빨랐다" 같은 서로 다른 두 습관이 평균 하나로 뭉개진다.
 *
 * ?fid=N 포트폴리오  ?side=buy|sell  ?sort=…  ?wait=N ?drop=N (재진입 판정 기준)
 */
function pf_page_hist(PDO $pdo, Pf $pf): void
{
    $fid  = (int)($_GET['fid'] ?? 0);
    $side = (string)($_GET['side'] ?? '');
    $sort = (string)($_GET['sort'] ?? 'recent');
    $wait = max(0, (int)($_GET['wait'] ?? PF_SIM_WAIT_DEFAULT));
    // 재진입 하락요건(%) — 비어 있으면 룰셋의 2차 하락률을 쓴다 (계획상 한 차수 아래)
    $dropIn = ($_GET['drop'] ?? '');
    $dropSet = ($dropIn !== '' && is_numeric($dropIn)) ? max(0.0, (float)$dropIn) / 100 : null;

    /* 시세를 먼저 최신으로 — 이 화면의 모든 숫자가 현재가 기준이다.
     * 여기만 빼먹으면 며칠 전 값으로 "매도 잘했다/못했다"를 채점하게 된다. */
    $pf->refreshQuotes($pf->stockCodes());
    $pf->syncPrices();

    $folios = $pf->portfolios();
    $rows   = $pf->tradeHistory($fid, $side);
    $today  = new DateTimeImmutable('today');

    /* ── 체결 이후 최고·최저(MFE/MAE)용 일봉.
     *
     * 가장 오래된 체결까지 덮을 만큼 넉넉히 당겨 온다 — 400일만 받으면 2022년 체결이
     * <b>일봉 시작 이후만</b> 본 값이 되어 "생각보다 안 빠졌다"는 거꾸로 된 결론이 난다.
     * (그래도 못 덮는 건은 pf_trade_excursion 이 covered=false 로 알리고 집계에서 빠진다)
     * 크론이 채워 둔 pf_daily 를 한 쿼리로 읽을 뿐이라 네이버를 부르지 않는다. */
    $oldest = $rows ? min(array_map(fn($r) => (string)$r['traded_at'], $rows)) : null;
    $span   = $oldest ? (int)$today->diff(new DateTimeImmutable($oldest))->days + 30 : 400;
    $daily  = $pf->dailyMap(array_column($rows, 'stock_code'), max(400, $span));

    // 되짚기 값을 행에 실어 둔다 (집계와 표가 같은 값을 쓴다)
    foreach ($rows as &$r) {
        $last = ($r['last_price'] !== null) ? (float)$r['last_price'] : null;
        $rv   = pf_trade_review((string)$r['side'], (float)$r['price'], (int)$r['qty'], $last);
        $r    = $r + $rv;
        $r['last'] = $last;
        $r['days'] = (int)$today->diff(new DateTimeImmutable((string)$r['traded_at']))->days;
        $r['exc']  = pf_trade_excursion((string)$r['side'], (float)$r['price'],
                                        (string)$r['traded_at'], $daily[(string)$r['stock_code']] ?? []);
    }
    unset($r);

    $sorters = [
        'old'    => fn($a, $b) => strcmp((string)$a['traded_at'], (string)$b['traded_at']),
        'worst'  => fn($a, $b) => ($a['edge']   ?? INF) <=> ($b['edge']   ?? INF),   // 반성할 것 먼저
        'best'   => fn($a, $b) => ($b['edge']   ?? -INF) <=> ($a['edge']   ?? -INF),
        'impact' => fn($a, $b) => ($a['impact'] ?? INF) <=> ($b['impact'] ?? INF),   // 손해 큰 것 먼저
        // 체결 뒤 가장 깊이 빠진 것 먼저 — 현재가로는 회복돼 보이는 건까지 드러난다
        'mae'    => fn($a, $b) => ($a['exc']['mae'] ?? INF) <=> ($b['exc']['mae'] ?? INF),
        'amount' => fn($a, $b) => ((float)$b['price'] * (int)$b['qty']) <=> ((float)$a['price'] * (int)$a['qty']),
        'stock'  => fn($a, $b) => strcmp((string)$a['stock_name'], (string)$b['stock_name'])
                              ?: strcmp((string)$b['traded_at'], (string)$a['traded_at']),
    ];
    if (isset($sorters[$sort])) usort($rows, $sorters[$sort]);   // 'recent' 는 쿼리 순서 그대로

    $sc = pf_trade_score($rows);

    pf_head('매매히스토리', 'dashboard', 'wide');
    pf_subtabs('hist', 'dashboard');
    pf_flash();

    echo '<div class="pf-head"><div>';
    echo '<h1>매매히스토리</h1>';
    echo '<div class="sub">체결한 뒤 가격이 어느 쪽으로 갔는지 <b>현재가로 되짚습니다</b>. '
       . '판정은 <b>＋면 잘한 매매</b>입니다 — 매수는 오른 것이, 매도는 <b>내린 것이</b> 잘한 것입니다.</div>';
    echo '</div><div class="act">';
    echo '<a class="btn btn-outline" href="/stock/index.php">현황으로</a>';
    echo '</div></div>';

    // ── 스코어카드: 매수·매도를 나눠 센다
    $pct = fn($v) => ($v === null) ? '-' : number_format($v * 100, 1) . '%';
    echo '<div class="sum-grid">';
    foreach ([
        ['매수 적중률', $pct($sc['buy']['rate']),  pf_updown($sc['buy']['rate']  === null ? null : $sc['buy']['rate']  - 0.5),
         $sc['buy']['hit'] . ' / ' . $sc['buy']['n'] . '건 · 담은 뒤 올랐나'],
        ['매도 적중률', $pct($sc['sell']['rate']), pf_updown($sc['sell']['rate'] === null ? null : $sc['sell']['rate'] - 0.5),
         $sc['sell']['hit'] . ' / ' . $sc['sell']['n'] . '건 · 판 뒤 내렸나'],
        ['매수 평균우위', $pct($sc['buy']['edge_avg']),  pf_updown($sc['buy']['edge_avg']),  '체결가 대비'],
        ['매도 평균우위', $pct($sc['sell']['edge_avg']), pf_updown($sc['sell']['edge_avg']), '＋면 잘 팔았음'],
        ['매수 금액영향', pf_n(round($sc['buy']['impact'])),  pf_updown($sc['buy']['impact']),  '담은 뒤 평가증감'],
        ['매도 금액영향', pf_n(round($sc['sell']['impact'])), pf_updown($sc['sell']['impact']), '＋아낀 손실 / −놓친 이익'],
        ['합계', pf_n(round($sc['impact'])), pf_updown($sc['impact']), '매매 타이밍이 만든 차이'],
    ] as [$k, $v, $cls, $sub]) {
        echo '<div class="sum-box"><div class="k">' . pf_h($k) . '</div>';
        echo '<div class="v ' . $cls . '">' . pf_h($v) . '</div>';
        echo '<div class="s">' . pf_h($sub) . '</div></div>';
    }
    echo '</div>';

    pf_render_reentry($pf, $fid, $wait, $dropSet, $today);
    /* 차수별 추가하락 — 룰셋의 계획 하락률과 견주려면 그 체결 종목들의 룰셋이 필요하다 */
    pf_render_step_excursion($rows, $pf->ruleStepsMap(array_column($rows, 'rule_set_id')));

    // ── 거르기 / 정렬
    echo '<form class="filter-bar" method="get" action="/stock/index.php">';
    echo '<input type="hidden" name="mode" value="hist">';
    echo '<label class="fld">포트폴리오<select name="fid">';
    echo '<option value="0"' . ($fid === 0 ? ' selected' : '') . '>전체</option>';
    foreach ($folios as $f) {
        $v = (int)$f['id'];
        echo '<option value="' . $v . '"' . ($v === $fid ? ' selected' : '') . '>' . pf_h($f['name']) . '</option>';
    }
    echo '</select></label>';

    echo '<label class="fld">구분<select name="side">';
    foreach (['' => '매수 + 매도', 'buy' => '매수만', 'sell' => '매도만'] as $k => $lbl) {
        echo '<option value="' . $k . '"' . ($k === $side ? ' selected' : '') . '>' . pf_h($lbl) . '</option>';
    }
    echo '</select></label>';

    echo '<label class="fld">정렬<select name="sort">';
    foreach ([
        'recent' => '최신순', 'old' => '오래된순',
        'worst'  => '반성할 것 먼저 (우위 낮은 순)', 'best' => '잘한 것 먼저',
        'mae'    => '체결후 최악 깊은 순 (MAE)',
        'impact' => '금액손해 큰 순', 'amount' => '체결금액 큰 순', 'stock' => '종목별',
    ] as $k => $lbl) {
        echo '<option value="' . $k . '"' . ($k === $sort ? ' selected' : '') . '>' . pf_h($lbl) . '</option>';
    }
    echo '</select></label>';
    echo '<label class="fld">재진입 대기(일)<input type="number" name="wait" min="0" max="365" style="width:90px" value="' . $wait . '"></label>';
    echo '<label class="fld">재진입 하락요건(%)<input type="number" name="drop" min="0" max="90" step="0.1" style="width:110px" value="'
       . ($dropSet === null ? '' : rtrim(rtrim(number_format($dropSet * 100, 1), '0'), '.')) . '" placeholder="룰셋 2차"></label>';
    echo '<button class="btn btn-primary" type="submit">적용</button>';
    echo '</form>';

    if (!$rows) {
        echo '<div class="card"><p class="muted">체결 기록이 없습니다.</p></div>';
        pf_foot();
        return;
    }

    echo '<div class="tbl-scroll"><table class="pf pos"><thead><tr>';
    foreach ([
        ['일자', ''], ['종목명', ''], ['포트폴리오', ''], ['구분', ''], ['차수', 'num'],
        ['체결가', 'num'], ['수량', 'num'], ['체결금액', 'num'],
        ['현재가', 'num'], ['체결후', 'num'],
        // 체결 이후 <b>최저·최고</b> — 현재가 한 점으로는 안 보이는 지나간 위험·기회
        ['최악', 'num'], ['최고', 'num'],
        ['판정', ''], ['금액영향', 'num'], ['메모', ''],
    ] as [$label, $cls]) {
        echo '<th class="' . $cls . '">' . pf_h($label) . '</th>';
    }
    echo '</tr></thead><tbody>';

    $sumAmt = $sumImp = 0.0;
    foreach ($rows as $r) {
        $isSell = ($r['side'] === 'sell');
        $amt    = (float)$r['price'] * (int)$r['qty'];
        $sumAmt += $amt;
        $sumImp += (float)($r['impact'] ?? 0);

        echo '<tr>';
        echo '<td class="stk"><b>' . pf_h($r['traded_at']) . '</b>'
           . '<span class="code">' . ($r['days'] > 0 ? $r['days'] . '일 전' : '오늘') . '</span></td>';
        echo '<td class="stk"><a href="/stock/index.php?mode=position&id=' . (int)$r['position_id'] . '">'
           . pf_h($r['stock_name']) . '</a><span class="code">' . pf_h($r['stock_code']) . '</span></td>';
        echo '<td class="muted">' . pf_h($r['portfolio_name']) . '</td>';
        echo '<td><span class="sig-pill k-' . ($isSell ? 'sell' : 'buy') . '">'
           . ($isSell ? '매도' : '매수') . '</span></td>';
        echo '<td class="num">' . ((int)$r['step_no'] > 0 ? (int)$r['step_no'] . '차' : '<span class="flat">-</span>') . '</td>';
        echo '<td class="num">' . pf_n(round((float)$r['price'])) . '</td>';
        echo '<td class="num">' . pf_n((int)$r['qty']) . '</td>';
        echo '<td class="num">' . pf_n(round($amt)) . '</td>';
        echo '<td class="num">' . pf_n($r['last']) . '</td>';
        // 체결후 등락은 <b>방향 그대로</b>(등락색), 판정은 부호를 뒤집은 뒤의 뜻
        echo '<td class="num">' . pf_signed_pct($r['after']) . '</td>';
        // 최악·최고는 "내 편이었나"라서 등락색을 쓰지 않는다 (pf_exc_cell 주석 참조)
        echo pf_exc_cell($r['exc'], 'mae');
        echo pf_exc_cell($r['exc'], 'mfe');
        echo '<td>' . pf_verdict_badge($isSell, $r['good']) . '</td>';
        echo '<td class="num">' . pf_signed($r['impact']) . '</td>';
        echo '<td class="muted" style="max-width:180px;overflow:hidden;text-overflow:ellipsis">' . pf_h($r['memo']) . '</td>';
        echo '</tr>';
    }

    echo '</tbody><tfoot><tr>';
    echo '<td>' . count($rows) . '건</td><td colspan="6"></td>';
    echo '<td class="num">' . pf_n(round($sumAmt)) . '</td>';
    echo '<td colspan="5"></td>';                       // 현재가·체결후·최악·최고·판정
    echo '<td class="num">' . pf_signed($sumImp) . '</td>';
    echo '<td></td>';
    echo '</tr></tfoot></table></div>';

    echo '<p class="sub muted" style="margin:10px 0 0;font-size:12px">'
       . '<b>체결후</b>는 그 뒤 주가가 실제로 간 방향이고(오르면 빨강), <b>판정</b>은 그것이 '
       . '<b>그 매매에 유리했는지</b>입니다 — 매도는 방향이 같아도 판정이 반대가 됩니다.<br>'
       . '<b>금액영향</b>은 매수는 담은 뒤 평가증감, 매도는 <b>＋아낀 손실 / −놓친 이익</b>입니다. '
       . '실현손익과는 다릅니다 — 실현손익은 이미 확정된 결과이고, 여기는 <b>그 시점 선택이 만든 차이</b>입니다.<br>'
       . '<b>최악·최고</b>는 체결 <b>다음 거래일부터</b> 오늘까지 일봉으로 되짚은 '
       . '가장 불리했던·유리했던 지점입니다(MAE/MFE). 「체결후」가 현재가 <b>한 점</b>이라 놓치는 것을 여기서 봅니다 — '
       . '담은 뒤 반토막까지 갔다가 회복한 매매나 <b>판 뒤 크게 올랐다 되돌아온</b> 매매는 '
       . '「체결후」만 보면 무승부로 보입니다. 부호는 여기서도 <b>＋면 내 편</b>이라 매도는 내린 쪽이 ＋입니다 '
       . '(그래서 이 두 열에는 등락색을 쓰지 않습니다). <b>*</b> 는 일봉이 체결 시점을 못 덮어 실제보다 얕은 값입니다.<br>'
       . '최대 400건까지 보여 줍니다.</p>';

    pf_foot();
}

/**
 * MFE/MAE 셀 — 체결 이후 가장 유리했던·불리했던 지점.
 *
 * ★ <b>등락색(빨강/파랑)을 쓰지 않는다.</b> 이 값의 부호는 "올랐나"가 아니라 "내 편이었나"다 —
 *   매도는 <b>내려야</b> ＋다. 같은 빨강이 화면에서 두 뜻을 가지면 읽을 수 없게 된다.
 *   그래서 거리(gap) 셀과 같은 무채색을 쓰고, 뜻은 열 이름과 하단 설명이 맡는다.
 * ★ 일봉이 체결 시점을 못 덮은 건은 <b>`*` 를 붙이고 흐리게</b> 둔다 — 값이 실제보다 얕아서
 *   집계에서 빠진 건이라는 사실이 화면에 남아야 한다(조용히 섞으면 반대 결론이 난다).
 */
function pf_exc_cell(array $exc, string $key): string
{
    $v = $exc[$key] ?? null;
    if ($v === null) return '<td class="num gap">-</td>';

    $at  = (string)($exc[$key . '_at'] ?? '');
    $cov = !empty($exc['covered']);
    $tip = $cov
        ? $at . (($key === 'mae' && ($exc['mae_days'] ?? null) !== null) ? ' · ' . (int)$exc['mae_days'] . '일 뒤' : '')
        : '일봉이 체결 시점을 덮지 못했습니다 — 실제로는 이보다 더 갔을 수 있어 차수 집계에서 뺐습니다 (' . $at . ')';

    return '<td class="num gap' . ($cov ? '' : ' flat') . '" title="' . pf_h($tip) . '">'
         . pf_h(sprintf('%+.1f%%', (float)$v * 100)) . ($cov ? '' : '*') . '</td>';
}

/**
 * 차수별 추가하락(MAE) — <b>"차수 하락률이 얕은가"에 답하는 표</b>.
 *
 * 매수 적중률 31%(실측 2026-07-30)의 원인 가설이 "차수 간격이 좁아 하락 초기에 총알을 다 쓴다"였다.
 * 그 가설은 <b>그 차수를 담은 뒤 실제로 얼마나 더 빠졌는가</b>로만 확정된다 — 그것을 재는 표다.
 *
 * ★ 견주는 상대는 <b>계획상 다음 차수 하락률</b>이다. 실측 추가하락이 그 트리거를 지나쳤다면
 *   다음 차수는 이르게 잡힌 것이다(그 값에 사고 나서도 더 빠진다).
 * ★ 포지션마다 룰셋이 다를 수 있으므로 그 차수를 담은 체결들의 룰셋을 모아 쓴다 —
 *   값이 갈리면 <b>범위로</b> 보여 준다(하나로 뭉치면 어느 룰셋 이야기인지 알 수 없다).
 * ★ 「소진」은 그 차수까지의 누적 비중이다. 얕다는 판정의 무게는 여기서 나온다 —
 *   −16% 에서 이미 30% 를 썼다면 그 뒤 하락은 남은 70% 로만 버텨야 한다.
 */
function pf_render_step_excursion(array $rows, array $stepsMap): void
{
    $agg = pf_step_excursion($rows);
    if (!$agg) return;

    // 계획 쪽 값(다음 차수 하락률·누적 비중)을 그 차수를 담은 체결들의 룰셋에서 모은다
    $plan = [];
    $past = 0;   // 계획상 다음 트리거보다 더 빠진 건수
    $seen = 0;
    foreach ($rows as $r) {
        if ((string)($r['side'] ?? '') !== 'buy') continue;
        $n = (int)($r['step_no'] ?? 0);
        if ($n <= 0) continue;
        $steps = $stepsMap[(int)($r['rule_set_id'] ?? 0)] ?? [];
        if (!$steps) continue;

        $nd = isset($steps[$n + 1]) ? (float)$steps[$n + 1]['drop_rate'] : null;
        $cum = 0.0;
        foreach ($steps as $k => $s) if ((int)$k <= $n) $cum += (float)$s['weight'];

        if ($nd !== null) $plan[$n]['next'][] = $nd;
        $plan[$n]['cum'][] = $cum;

        $e = $r['exc'] ?? null;
        if ($nd !== null && $e && $e['mae'] !== null && !empty($e['covered'])) {
            $seen++;
            if ((float)$e['mae'] < $nd) $past++;
        }
    }

    $pct1 = fn(?float $v) => ($v === null) ? '<span class="flat">-</span>'
                                          : pf_h(sprintf('%+.1f%%', $v * 100));

    echo '<div class="card"><h2>차수별 추가하락 — 담은 뒤 얼마나 더 빠졌나 <span class="muted" style="font-weight:400;font-size:13px">(MAE/MFE)</span></h2>';

    if ($seen > 0) {
        echo '<p class="sub" style="margin:0 0 10px">매수 <b>' . $seen . '건</b> 중 <b class="down">' . $past . '건</b>이 '
           . '계획상 <b>다음 차수 트리거를 지나</b> 더 빠졌습니다'
           . ' (' . number_format($past / $seen * 100, 0) . '%). '
           . '이 비율이 높으면 차수 간격이 좁아 하락 초기에 총알이 먼저 나갑니다.</p>';
    }

    echo '<div class="tbl-scroll"><table class="pf"><thead><tr>';
    foreach ([
        ['차수', 'num'], ['매수', 'num'], ['중앙 추가하락', 'num'], ['평균', 'num'], ['최악', 'num'],
        ['바닥까지', 'num'], ['최대상승', 'num'], ['계획 다음하락', 'num'], ['소진', 'num'], ['판정', ''],
    ] as [$label, $cls]) {
        echo '<th class="' . $cls . '">' . pf_h($label) . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($agg as $n => $a) {
        $nexts = array_values(array_unique($plan[$n]['next'] ?? []));
        $cums  = array_values(array_unique($plan[$n]['cum']  ?? []));
        // 판정에 쓸 대표값 — 체결 건별 평균(룰셋이 갈리면 그 비중대로 섞인다)
        $ndAll = $plan[$n]['next'] ?? [];
        $ndAvg = $ndAll ? array_sum($ndAll) / count($ndAll) : null;
        $vd    = pf_exc_verdict($a['mae_med'], $ndAvg, $a['n']);

        echo '<tr>';
        echo '<td class="num"><b>' . $n . '차</b></td>';
        /* 빠진 건은 사유를 나눠 밝힌다 — 오늘 담은 것과 일봉이 없는 것은 뜻이 다르다.
         * (전자는 시간이 지나면 들어오고, 후자는 일봉을 더 받아야 들어온다) */
        $out = (int)$a['skipped'] + (int)$a['fresh'];
        echo '<td class="num">' . $a['n'];
        if ($out > 0) {
            $why = [];
            if ($a['fresh'])   $why[] = '아직 되짚을 날이 없음 ' . $a['fresh'] . '건(최근 체결)';
            if ($a['skipped']) $why[] = '일봉이 체결 시점을 못 덮음 ' . $a['skipped'] . '건';
            echo ' <span class="flat" title="' . pf_h('집계에서 뺀 ' . $out . '건 — ' . implode(' · ', $why))
               . '">(−' . $out . ')</span>';
        }
        echo '</td>';
        echo '<td class="num"><b>' . $pct1($a['mae_med']) . '</b></td>';
        echo '<td class="num gap">' . $pct1($a['mae_avg']) . '</td>';
        echo '<td class="num gap">' . $pct1($a['mae_worst']) . '</td>';
        echo '<td class="num muted">' . ($a['days_avg'] === null ? '-' : $a['days_avg'] . '일') . '</td>';
        echo '<td class="num gap">' . $pct1($a['mfe_avg']) . '</td>';

        // 계획 다음하락 — 룰셋이 갈리면 범위로
        if (!$nexts) {
            echo '<td class="num muted" title="마지막 차수라 다음 트리거가 없습니다">-</td>';
        } elseif (count($nexts) === 1) {
            echo '<td class="num muted">' . $pct1((float)$nexts[0]) . '</td>';
        } else {
            echo '<td class="num muted" title="이 차수를 담은 종목들의 룰셋이 서로 다릅니다">'
               . $pct1(min($nexts)) . ' ~ ' . $pct1(max($nexts)) . '</td>';
        }

        if (!$cums) {
            echo '<td class="num muted">-</td>';
        } elseif (count($cums) === 1) {
            echo '<td class="num muted">' . pf_pct0((float)$cums[0], 0) . '</td>';
        } else {
            echo '<td class="num muted">' . pf_pct0(min($cums), 0) . ' ~ ' . pf_pct0(max($cums), 0) . '</td>';
        }

        /* 표본 부족(thin)은 <b>초록으로 칠하지 않는다</b> — "넉넉"과 같은 색이면 안심하고 넘긴다 */
        if ($vd['level'] === 'none') {
            echo '<td><span class="flat">-</span></td>';
        } elseif ($vd['level'] === 'thin') {
            echo '<td><span class="badge st-watch" title="' . pf_h($vd['why']) . '">'
               . pf_h($vd['label']) . '</span></td>';
        } else {
            echo '<td><span class="vd vd-' . ($vd['level'] === 'shallow' ? 'bad' : 'good')
               . '" title="' . pf_h($vd['why']) . '">' . pf_h($vd['label']) . '</span></td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';

    echo '<p class="sub muted" style="margin:9px 0 0;font-size:12px">'
       . '<b>추가하락(MAE)</b>은 그 차수를 담은 <b>다음 거래일부터</b> 오늘까지의 최저가 대비입니다 '
       . '(체결일은 세지 않습니다 — 그 날 저가는 내 주문 전에 지나갔을 수도 있습니다).<br>'
       . '<b>중앙값</b>을 먼저 봅니다 — 차수당 표본이 몇 건뿐이라 한 건의 폭락이 평균을 통째로 끌고 갑니다. '
       . '<b>판정</b>은 중앙 추가하락을 <b>계획상 다음 차수 하락률</b>과 견준 것이고 여유 3%p 를 둡니다.<br>'
       . '★ <b>노출 기간이 서로 다릅니다</b> — 오래된 체결은 빠질 시간이 더 많았습니다. '
       . '차수가 높을수록 최근 체결이라 추가하락이 작게 보이는 <b>편향</b>이 있습니다. '
       . '표본에서 뺀 건은 <b>(−N)</b> 으로 세어 두었습니다 — 사유(최근 체결이라 되짚을 날이 없음 / '
       . '일봉이 체결 시점을 못 덮음)는 그 숫자에 마우스를 올리면 나옵니다.</p>';
    echo '</div>';
}

/** 판정 배지 — 매수·매도에서 같은 방향이 반대 뜻이 되므로 말로 적는다 */
function pf_verdict_badge(bool $isSell, ?bool $good): string
{
    if ($good === null) return '<span class="flat">-</span>';
    if ($isSell) {
        return $good
            ? '<span class="vd vd-good">잘 팔았음</span>'
            : '<span class="vd vd-bad">이르게 팔았음</span>';
    }
    return $good
        ? '<span class="vd vd-good">잘 담았음</span>'
        : '<span class="vd vd-bad">이르게 담았음</span>';
}

/**
 * 재진입 후보 — 청산(전량매도)한 종목을 되사도 될 때가 됐는지.
 *
 * 시뮬레이터의 `청산 후 재진입 + 대기(거래일)` 규칙이 실전 화면에는 없었다. 그래서 전량 매도한 종목은
 * 종료(closed)로 사라진 뒤 아무도 다시 보지 않았다. 여기서 되살린다.
 *
 * ★ 시뮬레이터는 대기일만 지나면 곧바로 1차를 담는다(진입에 하락 조건이 없다). 실전에서 그대로 하면
 *   <b>팔았던 값보다 비싸게 되사는</b> 일이 생긴다 — 그래서 「하락요건」을 하나 더 건다.
 *   기본값은 그 종목 룰셋의 <b>2차 하락률</b>이다: 계획상 한 차수 아래면 다시 시작할 만하다는 뜻.
 * ★ 경과일은 달력일, 시뮬레이터의 대기는 거래일이다 — 며칠 어긋난다. 화면에 그대로 밝힌다.
 */
function pf_render_reentry(Pf $pf, int $fid, int $wait, ?float $dropSet, DateTimeImmutable $today): void
{
    $closed = $pf->closedPositions($fid);
    if (!$closed) return;

    $stepsMap = $pf->ruleStepsMap(array_column($closed, 'rule_set_id'));

    $list = [];
    foreach ($closed as $p) {
        $last  = ($p['last_price'] !== null) ? (float)$p['last_price'] : null;
        $steps = $stepsMap[(int)$p['rule_set_id']] ?? [];
        // 하락요건 기본값 = 룰셋 2차 하락률(음수로 저장돼 있다 → 절대값)
        $auto  = isset($steps[2]['drop_rate']) ? abs((float)$steps[2]['drop_rate']) : 0.0;
        $req   = $dropSet ?? $auto;
        $days  = (int)$today->diff(new DateTimeImmutable((string)$p['last_sell_at']))->days;

        $chk = pf_reentry_check($p['last_sell_price'], $last, $days, $wait, $req);
        $list[] = $p + ['chk' => $chk, 'req' => $req, 'last' => $last, 'days' => $days];
    }
    // 후보(ready) 먼저, 그 안에서는 많이 빠진 순
    usort($list, function ($a, $b) {
        $ra = ($a['chk']['state'] === 'ready') ? 0 : 1;
        $rb = ($b['chk']['state'] === 'ready') ? 0 : 1;
        if ($ra !== $rb) return $ra <=> $rb;
        return ($a['chk']['gap'] ?? INF) <=> ($b['chk']['gap'] ?? INF);
    });
    $nReady = count(array_filter($list, fn($x) => $x['chk']['state'] === 'ready'));

    echo '<section class="sig-strip' . ($nReady ? '' : ' quiet') . '">';
    echo '<div class="sig-hd"><h2>재진입 검토</h2>';
    if ($nReady) echo '<span class="sig-pill k-buy">후보 ' . $nReady . '건</span>';
    echo '<span class="sig-none">전량 매도한 ' . count($list) . '종목 — 대기 ' . $wait . '일 경과 + 청산가보다 충분히 낮으면 후보</span>';
    echo '</div>';

    echo '<div class="tbl-scroll"><table class="pf"><thead><tr>';
    foreach ([['종목', ''], ['포트폴리오', ''], ['청산일', ''], ['경과', 'num'], ['청산가', 'num'],
              ['현재가', 'num'], ['청산가 대비', 'num'], ['하락요건', 'num'], ['상태', '']] as [$l, $cl]) {
        echo '<th class="' . $cl . '">' . pf_h($l) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($list as $x) {
        $st = $x['chk']['state'];
        echo '<tr>';
        echo '<td><a href="/stock/index.php?mode=position&id=' . (int)$x['id'] . '">' . pf_h($x['stock_name']) . '</a></td>';
        echo '<td class="muted">' . pf_h($x['portfolio_name']) . '</td>';
        echo '<td>' . pf_h($x['last_sell_at']) . '</td>';
        echo '<td class="num">' . $x['days'] . '일</td>';
        echo '<td class="num">' . pf_n($x['last_sell_price'] === null ? null : round($x['last_sell_price'])) . '</td>';
        echo '<td class="num">' . pf_n($x['last']) . '</td>';
        echo '<td class="num">' . pf_signed_pct($x['chk']['gap']) . '</td>';
        echo '<td class="num muted">−' . number_format($x['req'] * 100, 1) . '%'
           . ($x['req'] === 0.0 ? '' : '') . '</td>';
        echo '<td>';
        if ($st === 'ready')      echo '<span class="vd vd-good">재진입 검토</span>';
        elseif ($st === 'wait')   echo '<span class="vd vd-wait">대기 ' . $x['chk']['left'] . '일 남음</span>';
        else                      echo '<span class="vd vd-hold">아직 비쌈</span>';
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<p class="sub muted" style="margin:9px 0 0;font-size:12px">'
       . '<b>하락요건</b>의 기본값은 그 종목 <b>룰셋의 2차 하락률</b>입니다 — 계획상 한 차수 아래면 다시 시작할 만하다는 뜻입니다. '
       . '위 입력칸으로 바꿀 수 있습니다.<br>'
       . '경과는 <b>달력일</b>이고 시뮬레이터의 대기는 <b>거래일</b>이라 며칠 어긋납니다. '
       . '재진입은 새 사이클이므로 지금 구조에서는 <b>그 종목을 다시 추가</b>해 시작합니다.</p>';
    echo '</section>';
}

/** 예전 링크 호환 — ?mode=folio&id=N → 대시보드 선택 상태로 넘긴다 */
function pf_page_folio(PDO $pdo, Pf $pf): void
{
    $fid = (int)($_GET['id'] ?? 0);
    header('Location: /stock/index.php' . ($fid ? '?id=' . $fid : ''));
    exit;
}

// ══════════════════════════════════════════════════════════════════════
//  §4.2 종목 상세 + §4.4 매매 입력
// ══════════════════════════════════════════════════════════════════════
function pf_page_position(PDO $pdo, Pf $pf): void
{
    $id = $_GET['id'] ?? '';

    if ($id === 'new' || $id === '') {
        pf_render_position_form($pf, null);
        return;
    }

    /* ★ 시세를 <b>positionGet 보다 먼저</b> 당겨 온다.
     *   이 화면은 pf_load_calc 을 거치지 않고 positionGet 이 읽은 pf_stock.last_price 를 그대로 쓴다 —
     *   신호 카드에서 바로 들어오면 대시보드를 거치지 않으므로 갱신 기회가 없었다.
     *   그러면 며칠 전 값으로 수익률·다음매수가가 계산된다(이 레포에서 한 번 겪은 사고다).
     *   이미 신선하면 네이버를 부르지 않으므로 비용은 0 이다. */
    $pf->refreshQuotes($pf->stockCodes());
    $pf->syncPrices();

    $pos = $pf->positionGet((int)$id);
    if (!$pos) {
        pf_head('종목 없음', 'dashboard');
        echo '<div class="err">해당 종목을 찾을 수 없습니다.</div>';
        pf_foot();
        return;
    }

    if (($_GET['edit'] ?? '') === '1') {
        pf_render_position_form($pf, $pos);
        return;
    }

    $steps    = $pf->ruleSteps((int)$pos['rule_set_id']);
    $tradeRow = $pf->trades((int)$pos['id']);
    $trades   = pf_trades_by_step($tradeRow);
    // 증권사 수수료(구간 or 단일요율) + 시장 세율
    $prm      = pf_cost_params($pos, $pf->brokerFees((int)$pos['broker_id']));
    $ledger   = pf_ledger($tradeRow, $prm);
    $last     = ($pos['last_price'] !== null) ? (float)$pos['last_price'] : null;
    $posLv    = $pf->positionLevels((int)$pos['id']);   // 박스 사다리 (있으면 룰셋 차수를 대체)
    $c        = ($steps || $posLv) ? pf_position_calc($steps, $trades, (float)$pos['limit_amt'], $last, $prm, $ledger, $posLv) : null;
    /* 종료 포지션은 계획·신호를 지운다 — 목록·신호 스트립과 <b>같은 규칙</b>이어야 한다.
     * 안 지우면 보유 0 을 "아직 안 산 종목"으로 읽어 이 화면에만 "1차 매수 구간입니다" 가 뜬다. */
    if ($pos['status'] === 'closed') $c = pf_calc_closed($c);
    // 차수 지연 — pf_load_calc 와 같은 규칙 (안 맞추면 같은 종목이 화면마다 다른 판정)
    elseif ($c !== null) $c = pf_delay_adjust($c, $steps, pf_last_buy_at($tradeRow), date('Y-m-d'));

    // 변동율 = 최고가 대비 현재가 낙폭
    $high = ($pos['high_price'] !== null) ? (float)$pos['high_price'] : null;
    $draw = ($high && $last) ? ($last / $high - 1) : null;

    pf_head($pos['stock_name'] . ' 상세', 'dashboard');
    pf_flash();

    echo '<div class="pf-head"><div>';
    echo '<h1>' . pf_h($pos['stock_name']) . ' <span class="muted">' . pf_h($pos['stock_code']) . '</span> '
       . pf_status_badge($pos['status']) . '</h1>';
    echo '<div class="sub">' . pf_h($pos['portfolio_name']) . ' · 룰셋 ' . pf_h($pos['rule_name'])
       . ' · 투자한도 ' . pf_n($pos['limit_amt']) . '원</div>';
    echo '</div><div class="act">';
    echo '<a class="btn btn-outline" href="/stock/index.php?id=' . (int)$pos['portfolio_id'] . '">← '
       . pf_h($pos['portfolio_name']) . '</a>';
    echo '<a class="btn btn-outline" href="/stock/index.php?mode=position&id=' . (int)$pos['id'] . '&edit=1">설정 수정</a>';

    /* 이 종목의 시세를 시뮬레이터에 올려 뒀으면 <b>이 포지션의 실제 설정 그대로</b> 바로 돌린다.
     *   기간 시작 = 1차 매수일 · 한도·룰셋·증권사·시장 = 이 포지션 값.
     *   1차 매수일부터 시작하면 시뮬레이터의 1차 진입이 실제 1차와 같은 날에 일어나
     *   (진입에는 하락 조건이 없다 — 보유 0이면 즉시 1차) "그때부터 규칙대로 했다면"이 바로 견줘진다.
     *
     * ★ 주소를 손으로 조립하면 안 된다. go=1 이 붙는 순간 pf_sim_options() 는 체크박스를
     *   "폼에서 왔다"로 읽어서, 링크에 re·intraday 가 없으면 <b>재진입·장중체결이 조용히 꺼진다</b>.
     *   예전 링크가 그랬고, 그래서 이 버튼으로 넘어가면 조건이 초기화된 것처럼 보였다.
     *   조립은 pf_sim_url() 한 곳에서만 한다. */
    $simD = $pf->simDataFindByCode((string)$pos['stock_code']);
    if ($simD) {
        /* 1차(=첫 체결) 매수일. 미진입 종목이면 받아 둔 시세의 시작일부터 본다.
         * ★ $c 는 여기서 null 일 수 있다 — 룰셋에 차수가 없다는 안내는 이 헤더 <b>아래</b>에서 하므로
         *   그 경우에도 헤더는 그려진다. ?? [] 를 빼면 그 종목에서 페이지가 죽는다. */
        $simFrom = '';
        foreach (($c['steps'] ?? []) as $s) {
            if ($s['traded'] && !empty($s['traded_at'])) { $simFrom = (string)$s['traded_at']; break; }
        }
        if ($simFrom === '') $simFrom = (string)$simD['from_date'];

        $simHref = pf_sim_url((int)$simD['id'], [
            'rs'    => (int)$pos['rule_set_id'],
            'limit' => (float)$pos['limit_amt'],
            'b'     => (int)$pos['broker_id'],
            'mk'    => (string)$pos['market'],
            'from'  => $simFrom,
            'to'    => (string)$simD['to_date'],
            // 재진입·장중체결·대기·계단관통은 포지션에 없는 개념이라 그 종목에 저장된 시뮬레이터 설정을 따른다
            'wait'     => (int)($simD['wait_days'] ?? PF_SIM_WAIT_DEFAULT),
            're'       => ((int)($simD['reenter']  ?? 1) === 1),
            'intraday' => ((int)($simD['intraday'] ?? 1) === 1) && !empty($simD['has_ohlc']),
            'ss'       => ((int)($simD['stair_stop'] ?? 0) === 1),
        ]);
        echo '<a class="btn btn-outline" href="' . pf_h($simHref) . '" title="'
           . pf_h('1차 매수일 ' . $simFrom . ' ~ ' . $simD['to_date']
                  . ' · 한도 ' . number_format((float)$pos['limit_amt']) . '원 · 룰셋 ' . $pos['rule_name'])
           . '">📊 시뮬레이션</a>';
    } else {
        echo '<a class="btn btn-outline" href="/stock/index.php?mode=sim">📊 시뮬레이터</a>';
    }
    echo '</div></div>';

    if (!$c) {
        echo '<div class="err">이 종목의 룰셋(' . pf_h($pos['rule_name']) . ')에 차수가 없습니다. '
           . '<a href="/stock/index.php?mode=ruleset">룰셋 설정</a>에서 차수를 먼저 등록하세요.</div>';
        pf_foot();
        return;
    }

    /* ── 전략 블록에 쓸 값 — 현황 화면의 신호 카드와 <b>같은 함수 체인</b>으로 만든다.
     *
     * ★ 여기서 지표·게이트를 따로 계산하면 안 된다. 같은 종목이 두 화면에서 다른 판정을 내는 순간
     *   둘 중 어느 것을 믿어야 할지 알 수 없게 된다. 그래서 그 포트폴리오를 통째로 pf_signal_list 에
     *   넣고 <b>이 포지션의 행을 찾아 쓴다</b> — 예수금 게이트의 누적 배정까지 그대로 따라온다.
     * ★ pf_load_calc 는 종료 포지션까지 준다 — 대시보드와 <b>같은 예수금</b>이 되고,
     *   이 종목이 종료 상태여도 목록에서 제 행을 찾을 수 있다(계획·신호는 이미 지워진 상태다). */
    $fid  = (int)$pos['portfolio_id'];
    $me   = null;
    $dmap = [];
    [$fPos, $fCalc] = pf_load_calc($pf, $fid);
    if ($fPos) {
        $byF = [];
        foreach ($fPos as $x) $byF[(int)$x['portfolio_id']][] = $x;
        $fRow  = $pf->portfolioGet($fid);
        $fStat = $fRow ? pf_folio_stats([$fRow], $byF, $fCalc) : [];
        // 체결 시점 지표까지 계산하므로 이 종목은 넉넉히(3년) 받아 온다
        $dmap  = $pf->dailyMap(array_column($fPos, 'stock_code'), 1200);
        foreach (pf_signal_list($fPos, $fCalc, $fStat, $dmap) as $g) {
            if ($g['pid'] === (int)$pos['id']) { $me = $g; break; }
        }
    }
    if ($dmap === [] || !isset($dmap[(string)$pos['stock_code']])) {
        $dmap = $pf->dailyMap([$pos['stock_code']], 1200);
    }
    $myBars = $dmap[(string)$pos['stock_code']] ?? [];
    if ($me === null && $myBars) {
        // 폴백 — 종료된 종목 등. 예수금 게이트만 없고 지표·유동성은 같은 함수로 만든다
        $fInd = pf_indicators($myBars, $last);
        $fLiq = pf_liquidity($myBars, (int)($c['next_qty'] ?? 0), $fInd['sd20'] ?? null);
        $me = [
            'ind' => $fInd, 'liq' => $fLiq, 'gate' => pf_fill_gate($fLiq),
            'mkt' => pf_market_signals($fInd, 3, $fLiq),
            'conf' => pf_signal_confidence(pf_signal($c, $last)['kind'], $fInd),
            's' => pf_signal($c, $last), 'fund_ok' => null, 'fund_left' => null,
        ];
    }

    /* 보유수량 — 요약카드(전량 매도 진입)·매도 폼·PF_POS 가 함께 쓰므로 여기서 한 번만 잡는다.
     * ★ 예전에는 차트 아래(일봉 직전)에서 잡혀 있었다. 그러면 요약카드가 선언 전에 읽어
     *   전량 매도 버튼이 조용히 붙지 않는다(실제로 그랬다 — 카드에 onclick 이 없었다). */
    $held = (int)$c['filled_qty'];

    /* ── 「현재차수」 카드에 <b>지금 살 수 있는 주수</b>를 붙인다.
     *
     * 목록 화면의 차수 배지(`4차 +109주`)와 같은 값·같은 규칙이다 —
     *   차수가 올라가는 매수(buy_signal)면 그 수량, 같은 차수의 잔여분(fill_signal)이면 그 수량.
     * ★ 신호가 없으면 붙이지 않는다. 금액만 남았고 현재가가 이론가보다 <b>위</b>면
     *   지금 사는 것은 계획보다 비싸게 담는 것이라 권해서는 안 된다(fill_signal 의 조건).
     * ★ 카드는 "<b>지금</b> 몇 주", 아래 차수 사다리는 "<b>그 차수에 닿으면</b> 몇 주"를 맡는다 —
     *   둘은 다른 질문이라 겹치지 않는다. */
    $nowQty = 0;
    $nowTip = '';
    if (!empty($c['buy_signal']) && (int)$c['buy_qty'] > 0) {
        $nowQty = (int)$c['buy_qty'];
        $nowTip = $c['reach_step'] . '차 도달 · 누적목표 ' . pf_n(round($c['plan_cum'][$c['reach_step']]))
                . ' − 투입 ' . pf_n(round($c['used_amount'])) . ' = ' . pf_n(round($c['buy_amount']))
                . '원을 현재가 ' . pf_n($last) . '원으로 나눈 수량입니다';
    } elseif (!empty($c['fill_signal']) && (int)$c['fill_qty'] > 0) {
        $nowQty = (int)$c['fill_qty'];
        $nowTip = $c['cur_step'] . '차 누적목표 ' . pf_n(round($c['plan_cum'][$c['cur_step']]))
                . ' − 투입 ' . pf_n(round($c['used_amount'])) . ' = ' . pf_n(round($c['fill_amount']))
                . '원 — 그 차수에 남은 몫입니다 (현재가가 아직 이론가 이하)';
    }
    $stepSuf = $nowQty > 0
        ? ' <span class="v-add" title="' . pf_h($nowTip) . '">+' . pf_n($nowQty) . '주</span>'
        : '';

    // 요약 카드
    $cards = [
        ['현재가',     pf_n($last), ''],
        ['최고가',     pf_n($high), ''],
        ['변동율',     $draw === null ? '-' : pf_pct($draw), pf_updown($draw)],
        ['현재차수',   $c['cur_step'] > 0 ? $c['cur_step'] . '차' : '미진입', '', '', '', '', $stepSuf],
        ['보유수량',   pf_n($c['filled_qty'] ?: null), ''],
        ['누적단가',   pf_n($c['avg_cost'] === null ? null : round($c['avg_cost'])), ''],
        ['수익률',     $c['rate'] === null ? '-' : pf_pct($c['rate']), pf_updown($c['rate'])],
        ['평가손익',   pf_n($c['eval_pl'] === null ? null : round($c['eval_pl'])), pf_updown($c['eval_pl'])],
    ];
    if ((int)$c['sold_qty'] > 0) {
        $cards[] = ['실현손익', pf_n(round($c['realized_pl'])), pf_updown($c['realized_pl'])];
        $cards[] = ['총손익',   pf_n(round($c['total_pl'])),    pf_updown($c['total_pl'])];
    }
    /* 자동매도가 카드는 <b>누르면 전량 매도 입력창</b>이 열린다.
     * 차수 사다리 행을 눌러도 매도할 수 있지만 그건 "그 차수분"이 기본값이라,
     * 목표에 닿아 전부 파는 순간에는 매번 전량 버튼을 다시 눌러야 했다.
     * ★ 현재가가 자동매도가에 닿았으면(sell_signal) 카드를 붉게 강조한다 — 그때가 실제로 누를 때다.
     *   닿지 않았어도 보유가 있으면 누를 수 있게 둔다 (계획보다 일찍 파는 것도 정상적인 판단이다). */
    $sellHit    = !empty($c['sell_signal']);
    $canSellAll = ($held > 0 && $c['sell_price'] !== null);
    $cards[] = [
        '자동매도가', pf_n($c['sell_price']), $sellHit ? 'up' : '',
        $canSellAll
            ? 'onclick="pfOpenSellAll()" title="'
              . pf_h('클릭하면 보유 ' . number_format($held) . '주 전량 매도 입력창이 열립니다'
                     . ($sellHit ? ' — 현재가가 자동매도가에 닿았습니다' : ''))
              . '"'
            : '',
        ($canSellAll ? ' clickable' : '') . ($sellHit ? ' sell-hit' : ''),
        $sellHit ? '<span class="badge st-sell">도달</span>' : '',
    ];

    echo '<div class="sum-grid">';
    foreach ($cards as $cd) {
        /* 뒤 칸들은 일부 카드만 쓴다 (없으면 빈 값):
         *   3 속성(onclick 등) · 4 박스 클래스 · 5 라벨 옆 배지 · 6 값 뒤에 붙는 HTML */
        [$k, $v, $cls] = $cd;
        $attr  = $cd[3] ?? '';
        $box   = $cd[4] ?? '';
        $badge = $cd[5] ?? '';
        $vsuf  = $cd[6] ?? '';
        echo '<div class="sum-box' . $box . '"' . ($attr !== '' ? ' ' . $attr : '') . '>';
        echo '<div class="k">' . pf_h($k) . ($badge !== '' ? ' ' . $badge : '') . '</div>';
        echo '<div class="v ' . $cls . '">' . pf_h($v) . $vsuf . '</div></div>';
    }
    echo '</div>';

    // 다음 매수 안내 — 누적목표에서 투입액을 뺀 금액이 매수액, 수량은 실제 가격으로 나눈다
    if ($c['next_step'] !== null) {
        $hit = $c['buy_signal'];
        echo '<div class="' . ($hit ? 'err' : 'warn') . ' clickable" '
           . 'onclick="pfOpenStep(' . ($hit ? $c['reach_step'] : $c['next_step']) . ')" title="클릭해서 매수 입력">';

        if ($hit) {
            echo '지금 매수 구간입니다 — <b>' . $c['reach_step'] . '차</b> 도달 · ';
            echo '누적목표 ' . pf_n(round($c['plan_cum'][$c['reach_step']])) . ' − 투입 '
               . pf_n(round($c['used_amount'])) . ' = <b>' . pf_n(round($c['buy_amount'])) . '원</b>';
            echo ' → 현재가 ' . pf_n($last) . '원 기준 <b>' . pf_n($c['buy_qty']) . '주</b>';
            if ($c['reach_step'] > $c['next_step']) {
                echo ' <span class="muted">(' . $c['next_step'] . '~' . $c['reach_step'] . '차를 한 번에)</span>';
            }
            if ((int)$c['buy_qty'] === 0) echo ' <b>· 이미 목표를 채웠습니다</b>';
        } else {
            echo '다음 매수 계획 — <b>' . $c['next_step'] . '차</b> · ';
            echo '<b>' . pf_n($c['next_price']) . '원</b> 도달 시 ';
            echo '누적목표 ' . pf_n(round($c['plan_cum'][$c['next_step']])) . ' − 투입 '
               . pf_n(round($c['used_amount'])) . ' = <b>' . pf_n(round($c['next_amount'])) . '원</b>';
            echo ' → <b>' . pf_n($c['next_qty']) . '주</b>';
            if ((int)$c['next_qty'] === 0) echo ' <b>· 이미 목표를 채웠습니다</b>';
        }
        echo '</div>';
    } else {
        echo '<div class="warn">모든 차수를 소진했습니다.</div>';
    }

    if ($me !== null) pf_render_strategy_row($me);

    echo '<div style="display:grid;grid-template-columns:1.35fr 1fr;gap:14px" class="pf-detail-grid">';

    // ── 차수 사다리
    // 매도가 있으면 차수별 수량·금액을 안분된 "보유" 기준으로 보여준다
    $hasSold = ((int)$c['sold_qty'] > 0);
    $qtyLabel = $hasSold ? '보유수량' : '매수수량';
    $amtLabel = $hasSold ? '보유원가' : '매수금액';

    echo '<div class="card"><h2>차수 사다리 '
       . ($posLv
            ? '<span class="mkt t-buyish" title="편입 시 확정한 이 종목의 실제 박스 지지선이 차수 가격입니다 — 룰셋 하락률 대신 이 표를 씁니다. 재조정은 「수정」 화면에서">박스 사다리 ' . count($posLv) . '차</span>'
            : '<a class="muted" style="font-weight:600;font-size:12px" '
              . 'href="/stock/index.php?mode=ruleset&rid=' . (int)$pos['rule_set_id'] . '" '
              . 'title="이 룰셋 설정 열기">' . pf_h($pos['rule_name']) . '</a>') . '</h2>';
    echo '<div class="tbl-scroll"><table class="pf"><thead><tr>';
    foreach ([['차수',''],['일자',''],['매수가(실)','num'],['가격(이론)','num'],[$qtyLabel,'num'],
              [$amtLabel,'num'],['누적목표','num'],['과부족','num'],['누적단가','num']] as [$l, $cl]) {
        echo '<th class="' . $cl . '">' . pf_h($l) . '</th>';
    }
    echo '</tr></thead><tbody>';

    $hit = (bool)$c['buy_signal'];

    foreach ($c['steps'] as $n => $s) {
        $isCur = ($n === $c['cur_step']);

        // 지금 채워야 할 구간 — 트리거됐으면 다음차수~도달차수, 아니면 다음차수만 예고
        $inBuy    = $hit && $n > $c['cur_step'] && $n <= $c['reach_step'];
        $isTarget = $hit ? ($n === $c['reach_step']) : ($n === $c['next_step']);
        // 사지 않고 지나친 차수 (뒤 차수 누적목표에 이미 흡수됨)
        $skipped  = (!$s['traded'] && $n < $c['cur_step']);

        $qty = $hasSold ? $s['held_qty']    : $s['qty'];
        $amt = $hasSold ? $s['held_amount'] : $s['amount'];

        $trCls = 'stp';
        if (!$s['traded'] && !$inBuy && !$isTarget) $trCls .= ' watch';
        if ($isCur)               $trCls .= ' cur';
        if ($inBuy)               $trCls .= ' buy';
        if ($isTarget && $hit)    $trCls .= ' buy-target';
        elseif ($isTarget)        $trCls .= ' next-target';

        echo '<tr class="' . $trCls . '" onclick="pfOpenStep(' . $n . ')" title="클릭해서 매수/매도 입력">';

        echo '<td><b>' . $n . '차</b> <span class="muted">' . pf_pct0($s['weight'], 2) . '</span>';
        if ($skipped) {
            echo ' <span class="badge st-skip" title="사지 않고 지나친 차수 — 이 차수의 목표는 뒤 차수 누적목표에 포함됩니다">건너뜀</span>';
        } elseif ($isTarget && $hit) {
            echo ' <span class="badge st-buy">지금 매수</span>';
        } elseif ($isTarget) {
            echo ' <span class="badge st-next">다음</span>';
        }
        echo '</td>';
        echo '<td>' . pf_h($s['traded_at'] ?? '') . '</td>';
        echo '<td class="num">' . pf_n($s['price'] === null ? null : round($s['price'])) . '</td>';

        // 이론가 옆에 직전 기준가 대비 실제 변동률 (10원 절사 때문에 룰셋 하락률과 미세하게 다를 수 있다)
        $chg = ($n > 1 && $s['theory_price'] !== null && $s['base_price'] > 0)
            ? ($s['theory_price'] / $s['base_price'] - 1) : null;

        echo '<td class="num"';
        if ($chg !== null) {
            echo ' title="기준가 ' . pf_n($s['base_price']) . ' → ' . pf_n($s['theory_price'])
               . ' · 룰셋 ' . pf_h(pf_pct($s['drop_rate'], 2)) . '"';
        }
        echo '>' . pf_n($s['theory_price']);
        if ($chg !== null) {
            echo ' <span class="chg ' . pf_updown($chg) . '">(' . pf_arrow($chg)
               . number_format(abs($chg) * 100, 1) . '%)</span>';
        }
        echo '</td>';

        // 안분된 보유수량 — 원래 매수수량은 hover 로 확인
        if ($hasSold && $s['traded']) {
            $cut = (int)$s['qty'] - (int)$s['held_qty'];
            echo '<td class="num" title="매수 ' . pf_n($s['qty']) . '주 − 안분매도 ' . pf_n($cut) . '주">'
               . pf_n($qty) . ' <span class="muted" style="font-size:11px">(' . pf_n($s['qty']) . ')</span></td>';
        } elseif (!$s['traded'] && !$skipped && (float)$s['gap'] < 0 && (float)$s['theory_price'] > 0) {
            /* 아직 안 산 차수 — <b>그 차수에 닿으면 몇 주</b>인지 예고한다.
             *
             * 수량 = (누적목표 − 지금까지 투입액) ÷ 그 차수 이론가.
             *   분자는 옆 「과부족」 칸의 금액과 <b>같은 값</b>이다(gap) — 금액은 있는데 주수가 없어서
             *   매번 머리로 나눠야 했다. 그걸 없애는 것이 이 칸의 전부다.
             * ★ 분모는 <b>이론가</b>다. 계획대로 그 가격에 닿았을 때를 말하는 자리이기 때문이다.
             *   실제 체결 수량은 현재가로 나누므로 달라진다 — 그 값은 위 「현재차수 +N주」 배지가 맡는다.
             * ★ 실제 체결값과 구별되게 회색 ≈ 로 적는다. 확정된 숫자가 아니라 예고다. */
            $planQty = (int)floor(round(abs((float)$s['gap']) / (float)$s['theory_price'], 6));
            echo '<td class="num"><span class="plan-qty" title="'
               . pf_h('이 차수에 도달하면 ' . pf_n(round(abs((float)$s['gap']))) . '원 매수 ÷ 이론가 '
                      . pf_n($s['theory_price']) . '원 = ' . pf_n($planQty) . '주 (예고)')
               . '">≈' . pf_n($planQty) . '</span></td>';
        } else {
            echo '<td class="num">' . pf_n($qty) . '</td>';
        }

        echo '<td class="num">' . pf_n($amt === null ? null : round($amt)) . '</td>';

        // 누적목표 = 한도 × 누적비중. 그 지점에서 들어가 있어야 할 총액
        echo '<td class="num muted">' . pf_n(round($s['plan_cum'])) . '</td>';

        // 과부족 = 그 차수까지 투입 − 누적목표. 미체결 차수는 "도달 시 더 사야 할 금액"
        $gap = (float)$s['gap'];
        if ($skipped) {
            $tip = '건너뛴 차수 — 이 금액은 뒤 차수 누적목표에 흡수됩니다';
        } elseif ($s['traded']) {
            $tip = '투입 ' . pf_n(round($s['plan_cum'] + $gap)) . ' vs 목표 ' . pf_n(round($s['plan_cum']));
        } else {
            $tip = '이 차수에 도달하면 ' . pf_n(round(abs($gap))) . '원 매수';
        }
        echo '<td class="num" title="' . pf_h($tip) . '">';
        echo '<span class="' . ($skipped ? 'flat' : pf_updown($gap)) . '">'
           . ($gap > 0 ? '+' : '') . pf_n(round($gap)) . '</span></td>';
        echo '<td class="num">' . pf_n($s['avg_cost_upto'] === null ? null : round($s['avg_cost_upto'])) . '</td>';
        echo '</tr>';
    }

    $execUpto = $c['exec_rate_upto'];
    $execCls  = ($execUpto === null) ? 'flat' : (($execUpto > 1.02) ? 'up' : (($execUpto < 0.98) ? 'down' : 'flat'));

    $gapUpto = $c['used_amount'] - $c['plan_upto'];   // 도달 차수까지의 과부족

    echo '</tbody><tfoot>';
    if (!$hasSold) {
        echo '<tr><td colspan="4">매수 합계</td>';
        echo '<td class="num">' . pf_n($c['bought_qty']) . '</td>';
        echo '<td class="num">' . pf_n(round($c['filled_amount'])) . '</td>';
        echo '<td class="num">' . pf_n(round($c['plan_upto'])) . '</td>';
        echo '<td class="num"><span class="' . pf_updown($gapUpto) . '">'
           . ($gapUpto > 0 ? '+' : '') . pf_n(round($gapUpto)) . '</span></td>';
        echo '<td class="num">' . pf_n($c['avg_cost'] === null ? null : round($c['avg_cost'])) . '</td></tr>';
    } else {
        echo '<tr><td colspan="4">보유 합계</td>';
        echo '<td class="num">' . pf_n($c['filled_qty']) . '</td>';
        echo '<td class="num">' . pf_n(round($c['held_cost'])) . '</td>';
        echo '<td class="num">' . pf_n(round($c['plan_upto'])) . '</td>';
        echo '<td class="num"><span class="' . pf_updown($gapUpto) . '">'
           . ($gapUpto > 0 ? '+' : '') . pf_n(round($gapUpto)) . '</span></td>';
        echo '<td class="num">' . pf_n($c['avg_cost'] === null ? null : round($c['avg_cost'])) . '</td></tr>';

        echo '<tr><td colspan="9" class="muted" style="font-weight:600;text-align:right">';
        echo '총 매수 ' . pf_n($c['bought_qty']) . '주 · 매도 <span class="down">−' . pf_n($c['sold_qty'])
           . '주</span> · 실현손익 ' . pf_signed($c['realized_pl'])
           . ' · 평가금액 ' . pf_n($c['eval_amount'] === null ? null : round($c['eval_amount']));
        echo '</td></tr>';
    }
    echo '</tfoot></table></div>';

    // ── 계획 대비 집행 현황
    if ($c['cur_step'] > 0) {
        $wSum   = pf_weight_sum($steps);
        $over   = ($execUpto !== null && $execUpto > 1.02);
        $under  = ($execUpto !== null && $execUpto < 0.98);
        $short  = (float)$c['plan_shortfall'];

        echo '<div class="plan-box">';
        echo '<div class="plan-head">계획 대비 집행 <span class="muted">' . $c['cur_step'] . '차까지</span></div>';

        $usedLabel = $hasSold ? '현재 보유' : '실제 투입';
        $usedRate  = ($c['limit_amt'] > 0) ? $c['used_amount'] / $c['limit_amt'] : null;

        echo '<div class="plan-grid">';
        foreach ([
            ['계획 투입', pf_n(round($c['plan_upto'])),     pf_pct0($c['plan_upto'] / max(1, $c['limit_amt']), 2) . ' of 한도', ''],
            [$usedLabel,  pf_n(round($c['used_amount'])),   pf_pct0($usedRate, 2) . ' of 한도', ''],
            ['계획대비',  ($execUpto === null ? '-' : number_format($execUpto * 100, 1) . '%'),
                          ($over ? '계획보다 많이 담음' : ($under ? '계획보다 덜 담음' : '계획대로')), $execCls],
            ['남은 한도', pf_n(round($c['limit_remain'])),   '한도 ' . pf_n($c['limit_amt']), ''],
            ['남은 차수 계획', pf_n(round($c['plan_remain'])),
                          ($c['next_step'] !== null ? $c['next_step'] . '~' . array_key_last($c['steps']) . '차' : '없음'), ''],
        ] as [$k, $v, $sub, $cls]) {
            echo '<div class="plan-cell"><div class="k">' . pf_h($k) . '</div>';
            echo '<div class="v ' . $cls . '">' . pf_h($v) . '</div>';
            echo '<div class="s">' . $sub . '</div></div>';
        }
        echo '</div>';

        if ($short > 0) {
            echo '<div class="plan-warn">남은 차수를 계획대로 채우려면 <b>' . pf_n(round($short))
               . '원</b>이 모자랍니다.';
            if ($over) {
                echo ' 앞 차수를 계획보다 <b>' . number_format(($execUpto - 1) * 100, 0) . '%</b> 많이 담은 결과입니다.';
            }
            if ($wSum > 1.0001) {
                echo ' (이 룰셋은 비중 합계가 <b>' . pf_h(pf_pct0($wSum, 2)) . '</b>라 계획대로 채워도 한도를 '
                   . pf_n(round(($wSum - 1) * $c['limit_amt'])) . '원 넘깁니다)';
            }
            echo '</div>';
        } elseif ($under) {
            echo '<div class="plan-note">계획보다 <b>' . number_format((1 - $execUpto) * 100, 0)
               . '%</b> 적게 담았습니다. 남은 한도는 여유가 있습니다.</div>';
        }
        echo '</div>';
    }

    if ($hasSold) {
        echo '<p class="sub muted" style="margin:9px 0 0;font-size:12px">'
           . '매도수량을 차수별 매수수량에 <b>비례 안분</b>해 보유수량을 표시합니다 '
           . '(절사분은 최초 차수에 반영). 괄호 안이 원래 매수수량입니다. '
           . '<b>계획대비</b>도 매수분이 아니라 <b>보유분</b> 기준입니다 — 판 물량은 한도를 더 이상 차지하지 않기 때문입니다.</p>';
    }
    echo '</div>';

    // ── 하락 곡선
    $labels  = [];
    $theory  = [];
    $actual  = [];
    $avgRun  = [];
    $sellRun = [];
    $curStep = (int)$c['cur_step'];
    $cumQty  = 0;
    foreach ($c['steps'] as $n => $s) {
        $labels[] = $n . '차';
        $theory[] = $s['theory_price'];
        $actual[] = $s['traded'] ? round((float)$s['price']) : null;   // 미체결 차수는 null
        $avgRun[] = ($s['avg_cost_upto'] === null) ? null : round((float)$s['avg_cost_upto']);

        /* 자동매도가 = 그 차수까지의 누적단가 × (1 + 그 차수 목표수익률) ÷ (1 − 매도비용률).
         *
         * ★ 지나간 차수만 그 차수의 값으로 그리고, 앞으로의 차수는 <b>지금 값을 그대로 눕힌다</b>.
         *   미래 차수에 그 차수의 목표수익률을 곱해 그리면 "차수가 오를수록 탈출가가 비싸진다"는
         *   거짓 그림이 된다 — 누적단가는 그대로인데 목표(15→50%)만 올라가기 때문이다.
         *   실제로 그 차수에 닿으면 매수가 일어나 누적단가가 내려가므로 탈출가도 내려간다.
         * ★ 현재차수는 공식을 다시 쓰지 않고 $c['sell_price'] 를 그대로 쓴다 —
         *   상단 카드·일봉 가격선과 반드시 한 값이어야 한다. 같은 공식을 두 곳에서 쓰면 언젠가 갈린다. */
        $cumQty += (int)($s['qty'] ?? 0);
        if ($n < $curStep) {
            $sp = pf_auto_sell_price($s['avg_cost_upto'], $s['target_rate'], $prm, $cumQty);
            $sellRun[] = ($sp === null) ? null : round($sp);
        } else {
            $sellRun[] = ($c['sell_price'] === null) ? null : round((float)$c['sell_price']);
        }
    }
    echo '<div class="card"><h2>차수별 이론가</h2>';
    echo '<div class="sub muted" style="font-size:12px;margin:-6px 0 8px">'
       . '자동매도가는 <b>체결한 차수까지 실선</b>, 그 뒤 점선은 <b>지금 값을 눕힌 것</b>입니다 — '
       . '그 차수에 닿으면 매수가 일어나 누적단가와 함께 내려갑니다.</div>';
    echo '<div class="chart-box"><canvas id="pfChart"></canvas></div></div>';
    echo '</div>';

    // ── 일봉 차트 (기존 stock_analysis_api.php 의 daily 엔드포인트 재사용)
    echo '<div class="card"><div class="pf-head" style="margin-bottom:10px"><div>';
    echo '<h2 style="margin:0" id="pfDailyTitle">일봉 차트</h2>';
    echo '<div class="sub">누적단가·자동매도가·다음매수가를 가격선으로, 체결 지점을 마커로 표시합니다.</div>';
    echo '</div><div class="act">';
    // 기간 바 [일봉|주봉 ┃ 160일 240일 480일 전체] + 사용자 지표 바 — DailyChart 공용 컴포넌트
    echo '<span id="pfPBar"></span> <span id="pfIBar"></span>';
    echo '</div></div>';
    // 가격선 범례 — 차트 위에 글자를 얹지 않고 여기서 설명한다 (지표 값도 여기에 붙는다)
    echo '<div class="chart-legend" id="pfLegend">';
    foreach ([
        ['#1e9e74', '누적단가',   $c['avg_cost']   === null ? null : round($c['avg_cost'])],
        ['#d32f2f', '자동매도가', $c['sell_price']],
        ['#1d5c93', '다음매수가', $c['next_price']],
    ] as [$col, $lab, $val]) {
        if ($val === null) continue;
        echo '<span class="cl-item"><i style="background:' . $col . '"></i>'
           . pf_h($lab) . ' <b>' . pf_n($val) . '</b></span>';
    }
    echo '</div>';

    echo '<div id="pfDailyChart" style="height:430px;position:relative"></div>';
    echo '<div class="muted" id="pfDailyNote" style="font-size:12px;margin-top:8px">불러오는 중…</div>';
    echo '</div>';

    /* 차트에 얹을 체결 마커.
     *
     * 같은 날 같은 차수를 여러 번에 나눠 체결했으면 <b>한 칩으로 합친다</b> —
     * 안 합치면 "2차 1주 @528,000 / 2차 2주 @528,000" 처럼 칩이 겹겹이 쌓여 캔들을 가린다.
     * 단가는 <b>가중평균</b>(금액합 ÷ 수량합)이다. 단순평균을 쓰면 수량이 다를 때 틀린다.
     *
     * 합친 칩의 단가는 실제로 체결된 적 없는 값일 수 있으므로 "(2건)" 을 붙여 그 사실을 드러낸다.
     * 매수·매도는 물론, 같은 날의 다른 차수도 따로 둔다 — 합칠 이유가 없다. */
    $grp = [];
    foreach ($tradeRow as $t) {
        $isSell = ($t['side'] === 'sell');
        $key    = $t['traded_at'] . '|' . ($isSell ? 'S' : 'B') . '|' . (int)$t['step_no'];
        if (!isset($grp[$key])) {
            $grp[$key] = ['time' => $t['traded_at'], 'sell' => $isSell,
                          'step' => (int)$t['step_no'], 'qty' => 0.0, 'amt' => 0.0, 'n' => 0];
        }
        $q = (float)$t['qty'];
        $grp[$key]['qty'] += $q;
        $grp[$key]['amt'] += $q * (float)$t['price'];
        $grp[$key]['n']++;
    }

    // lightweight-charts 의 setMarkers 는 시간 오름차순을 요구한다
    uasort($grp, fn($a, $b) => strcmp($a['time'], $b['time']));

    /* ── 체결 <b>시점</b>의 시장 상태.
     *
     * 지금 상태가 아니라 <b>그 날까지의 봉으로 지표를 다시 계산</b>한다 —
     * "내가 산 그 날 시장은 역배열 + 과매도였다"를 알면 매매 품질을 되짚을 수 있다.
     * 오늘 상태를 옛 체결에 붙이면 아무 뜻도 없는 라벨이 된다.
     *
     * ★ 현재가를 갈아끼우지 않는다(pf_indicators 2번째 인자 없음) — 그 날의 종가가 그 날의 종가다.
     * ★ 표본이 모자라면 빈 문자열이다. pf_daily 는 3년치라 그 이전 체결에는 라벨이 없다.
     * ★ 칩이 좁으니 2개까지만. 우선순위는 pf_market_signals 가 이미 행동 순으로 정렬해 준다. */
    // 계산은 lib/calc.php 의 pf_state_at 이 맡는다 (시뮬레이터 차트도 같은 함수를 쓴다)
    $stateAt = fn(string $date): string =>
        implode(' · ', array_column(pf_state_at($myBars, $date, 2), 'label'));

    $marks = [];
    foreach ($grp as $g) {
        $avg = $g['qty'] > 0 ? $g['amt'] / $g['qty'] : 0.0;
        $marks[] = [
            'time' => $g['time'],
            'sell' => $g['sell'],
            'text' => ($g['sell'] ? '매도' : $g['step'] . '차')
                     . ' ' . number_format($g['qty']) . '주 @' . number_format(round($avg))
                     . ($g['n'] > 1 ? ' (' . $g['n'] . '건)' : ''),
            'state' => $stateAt((string)$g['time']),
        ];
    }

    // 차트는 공용 모듈(style/dailychart.js)이 그린다 — 라이브러리 로드도 모듈이 맡는다
    echo '<script src="/style/dailychart.js?v=27"></script>';
    echo '<script>';
    echo 'const PF_CODE=' . json_encode($pos['stock_code']) . ';';
    echo 'const PF_MARKS=' . json_encode($marks, JSON_UNESCAPED_UNICODE) . ';';
    echo 'const PF_PLINES=' . json_encode([
        ['v' => $c['avg_cost']   === null ? null : round($c['avg_cost']),  'c' => '#1e9e74', 't' => '누적단가',   'd' => 0],
        ['v' => $c['sell_price'] === null ? null : (float)$c['sell_price'],'c' => '#d32f2f', 't' => '자동매도가', 'd' => 1],
        ['v' => $c['next_price'] === null ? null : (float)$c['next_price'],'c' => '#1d5c93', 't' => '다음매수가', 'd' => 1],
    ], JSON_UNESCAPED_UNICODE) . ';';

    echo <<<'JS'
DailyChart.load().then(function(){
  var note = document.getElementById('pfDailyNote');
  var dc = DailyChart.create('pfDailyChart', {
    theme: 'light', markers: { chips: true },
    key: 'position', legend: 'pfLegend'      // 차트틀 기억 + 지표 값을 가격선 범례에 표시
  });
  if (!dc) { if (note) note.textContent = '차트 라이브러리를 불러오지 못했습니다.'; return; }

  // 누적단가 · 자동매도가 · 다음매수가 가로선 (설명은 위 범례로 — 차트 안 글자 금지)
  dc.setPriceLines(PF_PLINES.map(function(L){
    return { price: L.v, color: L.c, style: L.d ? 'dashed' : 'solid' };
  }));
  // 체결 마커 — 화살표는 라이브러리, 글자는 모듈의 HTML 칩(충돌회피)
  dc.setMarkers(PF_MARKS);

  function pfDailyNote(){
    var r = dc.range();
    if (!r) return;
    var unit = dc.tf() === 'week' ? '주' : '거래일';
    note.textContent = r.n + unit + ' (' + r.from + ' ~ ' + r.to + ') · 체결 마커 '
      + dc.markerCount() + '개';
  }

  // 기간 바 = 공용 컴포넌트. 데이터는 1000영업일을 한 번 받아 두고 슬라이스만 한다
  DailyChart.periodBar('pfPBar', dc, {
    theme: 'light',
    defaultIndex: 0,   // 160일 (기존 기본값 유지)
    onChange: function(info){
      var t = document.getElementById('pfDailyTitle');
      if (t) t.textContent = info.tf === 'week' ? '주봉 차트' : '일봉 차트';
      pfDailyNote();
    }
  });
  DailyChart.indicatorBar('pfIBar', dc, { theme: 'light', key: 'position' });   // 사용자 지표 + 차트틀

  note.textContent = '불러오는 중…';
  DailyChart.fetchDaily(PF_CODE, 1000).then(function(rows){
    if (!rows.length) {
      note.textContent = '일봉 데이터를 가져오지 못했습니다 (종목코드 ' + PF_CODE + ').';
      return;
    }
    dc.setData(rows);
    pfDailyNote();
  }).catch(function(e){
    console.error('[pf daily]', e);
    note.textContent = '일봉 데이터를 불러오지 못했습니다. (' + e + ')';
  });
}).catch(function(){
  var n = document.getElementById('pfDailyNote');
  if (n) n.textContent = '차트 라이브러리를 불러오지 못했습니다.';
});
JS;
    echo '</script>';


    // ── 매수/매도 모달 (차수 사다리 행 클릭으로 열림)
    echo '<div class="pf-modal-back" id="pfModal" onclick="if(event.target===this)pfCloseModal()">';
    echo '<div class="pf-modal" role="dialog" aria-modal="true">';

    echo '<div class="pfm-head"><div><span id="pfmTitle"></span>'
       . '<span class="muted" id="pfmSub" style="font-weight:600;font-size:12px;margin-left:8px"></span></div>';
    echo '<button type="button" class="pfm-x" onclick="pfCloseModal()" aria-label="닫기">✕</button></div>';

    echo '<div class="pfm-info" id="pfmInfo"></div>';

    echo '<div class="pfm-tabs" id="pfmTabs">';
    echo '<button type="button" data-tab="buy"  onclick="pfTab(\'buy\')">매수</button>';
    echo '<button type="button" data-tab="sell" onclick="pfTab(\'sell\')">매도</button>';
    echo '</div>';

    // 매수 폼
    echo '<form class="pfm-body" id="pfmBuy" method="post" action="/stock/api.php?module=trade&action=save">';
    echo '<input type="hidden" name="position_id" value="' . (int)$pos['id'] . '">';
    echo '<input type="hidden" name="side" value="buy">';
    echo '<input type="hidden" name="step_no" id="pfmBuyStep" value="">';
    echo '<div class="fld-row">';
    echo '<label class="fld">일자<input type="date" name="traded_at" value="' . date('Y-m-d') . '" required></label>';
    echo '<label class="fld">체결가<input type="text" class="num-comma" inputmode="numeric" id="pfmBuyPrice" name="price" style="width:120px" required></label>';
    echo '<label class="fld">수량<input type="text" class="num-comma" inputmode="numeric" id="pfmBuyQty" name="qty" style="width:110px" required></label>';
    echo '<label class="fld" style="flex:1;min-width:150px">메모<input type="text" name="memo"></label>';
    echo '</div>';
    echo '<div class="pfm-preview" id="pfmBuyPreview"></div>';
    echo '<div class="pfm-foot"><span class="muted" id="pfmBuyNote"></span>';
    echo '<button class="btn btn-primary" type="submit">매수 등록</button></div>';
    echo '</form>';

    // 매도 폼
    echo '<form class="pfm-body" id="pfmSell" method="post" action="/stock/api.php?module=trade&action=save">';
    echo '<input type="hidden" name="position_id" value="' . (int)$pos['id'] . '">';
    echo '<input type="hidden" name="side" value="sell">';
    echo '<div class="fld-row">';
    echo '<label class="fld">일자<input type="date" name="traded_at" value="' . date('Y-m-d') . '" required></label>';
    echo '<label class="fld">매도가<input type="text" class="num-comma" inputmode="numeric" id="pfmSellPrice" name="price" style="width:120px" required></label>';
    echo '<label class="fld">수량<input type="text" class="num-comma" inputmode="numeric" id="pfmSellQty" name="qty" style="width:110px" required></label>';
    echo '<label class="fld" style="flex:1;min-width:150px">메모<input type="text" name="memo"></label>';
    echo '</div>';
    echo '<div class="qbtns" id="pfmQuick"></div>';
    echo '<div class="sell-preview" id="pfmSellPreview"></div>';
    echo '<div class="pfm-foot"><span class="muted">매도는 보유 전체에서 이루어지고 차수별로 안분됩니다.</span>';
    echo '<button class="btn btn-sell" type="submit">매도 등록</button></div>';
    echo '</form>';

    echo '</div></div>';

    // ── 체결 내역
    echo '<div class="card"><h2>체결 내역</h2>';

    if (!$tradeRow) {
        echo '<p class="muted" style="font-size:13px;margin:0">아직 체결 기록이 없습니다.</p>';
    } else {
        echo '<div class="tbl-scroll"><table class="pf"><thead><tr>';
        echo '<th>일자</th><th class="num">차수</th><th>구분</th><th class="num">체결가</th><th class="num">수량</th>';
        echo '<th class="num">금액</th><th>메모</th><th></th></tr></thead><tbody>';
        foreach ($tradeRow as $t) {
            $isSell = ($t['side'] === 'sell');
            echo '<tr' . ($isSell ? ' style="background:#f4f8fd"' : '') . '>';
            echo '<td>' . pf_h($t['traded_at']) . '</td>';
            echo '<td class="num">' . ((int)$t['step_no'] > 0 ? (int)$t['step_no'] : '<span class="muted">-</span>') . '</td>';
            echo '<td>' . ($isSell ? '<span class="down">매도</span>' : '<span class="up">매수</span>') . '</td>';
            echo '<td class="num">' . pf_n(round((float)$t['price'])) . '</td>';
            echo '<td class="num">' . ($isSell ? '−' : '') . pf_n($t['qty']) . '</td>';
            echo '<td class="num">' . pf_n(round((float)$t['price'] * (int)$t['qty'])) . '</td>';
            echo '<td>' . pf_h($t['memo']) . '</td>';
            echo '<td class="right"><form class="inline" method="post" action="/stock/api.php?module=trade&action=delete" '
               . 'onsubmit="return confirm(\'이 체결 기록을 삭제할까요? 이후 계산이 모두 다시 계산됩니다.\')">';
            echo '<input type="hidden" name="id" value="' . (int)$t['id'] . '">';
            echo '<button class="btn btn-danger btn-sm" type="submit">삭제</button></form></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    echo '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>';
    echo '<script>';
    echo 'const PF_LABELS=' . json_encode($labels, JSON_UNESCAPED_UNICODE) . ';';
    echo 'const PF_THEORY=' . json_encode($theory) . ';';
    echo 'const PF_ACTUAL=' . json_encode($actual) . ';';
    echo 'const PF_AVG='    . json_encode($avgRun) . ';';
    echo 'const PF_SELL='   . json_encode($sellRun) . ';';
    /* ★ 이름을 PF_CUR 로 두면 안 된다 — 아래 모달 스크립트의 `var PF_CUR`(현재 열린 차수)와
     *   같은 전역 이름이 되어 "Identifier 'PF_CUR' has already been declared" 로 그 블록이 통째로 죽는다.
     *   그러면 pfOpenStep 이 정의되지 않아 <b>차수 사다리 클릭이 아무 반응도 하지 않는다</b>(실제로 그랬다). */
    echo 'const PF_CHART_CUR=' . $curStep . ';';
    echo 'const PF_LAST='   . json_encode($last) . ';';
    echo <<<'JS'
(function(){
  var el = document.getElementById('pfChart');
  if (!el || !window.Chart) return;
  var ds = [{
    label: '이론 매수가', data: PF_THEORY, borderColor: '#1d5c93',
    backgroundColor: 'rgba(29,92,147,.10)', fill: true, tension: .25,
    pointRadius: 4, pointBackgroundColor: '#1d5c93', order: 3
  }];

  // 실제 체결한 차수만 점으로 찍는다 (선 없이 — 연속된 값이 아니라 개별 체결 지점이므로)
  if (PF_ACTUAL.some(function(v){ return v !== null; })) {
    ds.push({
      label: '실제 매수가', data: PF_ACTUAL,
      showLine: false, fill: false,
      borderColor: '#d32f2f', backgroundColor: '#d32f2f',
      pointStyle: 'circle', pointRadius: 6, pointHoverRadius: 8,
      pointBackgroundColor: '#d32f2f', pointBorderColor: '#fff', pointBorderWidth: 2, order: 1
    });
  }

  // 차수가 쌓일수록 누적단가가 어떻게 내려오는지 (물타기 효과)
  if (PF_AVG.some(function(v){ return v !== null; })) {
    ds.push({
      label: '누적단가', data: PF_AVG, borderColor: '#1e9e74',
      backgroundColor: '#1e9e74', fill: false, tension: .2, spanGaps: true,
      borderWidth: 2, pointRadius: 3, pointBackgroundColor: '#1e9e74', order: 2
    });
  }

  /* 자동매도가 — 일봉 차트와 같은 빨강으로 맞춘다.
     '실제 매수가' 도 빨강이지만 그건 <b>점만</b>(showLine:false) 찍으므로 선과 헷갈리지 않는다.
     체결 구간은 실선, 앞으로의 차수는 지금 값을 눕힌 것이라 점선으로 갈라 그린다. */
  if (PF_SELL.some(function(v){ return v !== null; })) {
    ds.push({
      label: '자동매도가', data: PF_SELL,
      borderColor: '#d32f2f', backgroundColor: '#d32f2f', fill: false, tension: 0,
      spanGaps: true, borderWidth: 2,
      pointRadius: function(ctx){ return ctx.dataIndex <= PF_CHART_CUR - 1 ? 3 : 0; },
      pointBackgroundColor: '#d32f2f',
      segment: {
        // p0 이 현재차수(0-based: PF_CHART_CUR-1) 이후면 그 구간은 "눕힌" 부분이다
        borderDash: function(ctx){ return (ctx.p0DataIndex >= PF_CHART_CUR - 1) ? [6, 4] : undefined; }
      },
      order: 5
    });
  }

  // 빨강은 실제 매수 지점에 쓰므로 현재가 기준선은 회색 점선으로
  if (PF_LAST !== null) {
    ds.push({
      label: '현재가', data: PF_LABELS.map(function(){ return PF_LAST; }),
      borderColor: '#94a3b8', borderDash: [6,4], borderWidth: 2,
      pointRadius: 0, fill: false, tension: 0, order: 4
    });
  }

  new Chart(el, {
    type: 'line',
    data: { labels: PF_LABELS, datasets: ds },
    options: {
      responsive: true, maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { labels: { boxWidth: 12, font: { size: 11 }, usePointStyle: true } },
        tooltip: {
          callbacks: {
            label: function(ctx){
              if (ctx.parsed.y === null) return null;
              return ctx.dataset.label + ' ' + Math.round(ctx.parsed.y).toLocaleString() + '원';
            },
            afterBody: function(items){
              // 이론가 대비 실제 체결가가 얼마나 유리/불리했는지
              var t = null, a = null;
              items.forEach(function(it){
                if (it.dataset.label === '이론 매수가') t = it.parsed.y;
                if (it.dataset.label === '실제 매수가') a = it.parsed.y;
              });
              if (t === null || a === null || !t) return '';
              var d = a / t - 1;
              return '이론가 대비 ' + (d > 0 ? '+' : '') + (d * 100).toFixed(1) + '%'
                   + (d < 0 ? ' (계획보다 싸게 매수)' : (d > 0 ? ' (계획보다 비싸게 매수)' : ''));
            }
          }
        }
      },
      scales: { y: { ticks: { callback: function(v){ return v.toLocaleString(); }, font: { size: 11 } } },
                x: { ticks: { font: { size: 11 } } } }
    }
  });
})();
JS;
    echo '</script>';

    // ── 차수 모달 스크립트
    $stepJs = [];
    foreach ($c['steps'] as $n => $s) {
        $stepJs[$n] = [
            'no'      => $n,
            'weight'  => (float)$s['weight'],
            'tPrice'  => $s['theory_price'],
            'tQty'    => (int)$s['theory_qty'],
            'plan'    => (float)$s['plan_amount'],
            'traded'  => (bool)$s['traded'],
            'price'   => $s['price'],
            'qty'     => $s['qty'],
            'held'    => $s['held_qty'],
            'amount'  => $s['amount'],
            'date'    => $s['traded_at'],
            'exec'    => $s['exec_rate'],
            'target'  => $s['target_rate'],
            'drop'    => (float)$s['drop_rate'],
            'planCum' => (float)$s['plan_cum'],
            // 이 차수 도달 시 채워야 할 금액 (누적목표 − 현재 투입액)
            'need'    => max(0.0, (float)$s['plan_cum'] - (float)$c['used_amount']),
        ];
    }
    /* 매도가 기본값은 <b>현재가</b>다.
     * 예전에는 자동매도가에 닿았으면 자동매도가를 넣었는데, 그러면 이미 목표를 훌쩍 넘긴 종목에서
     * 실제보다 낮은 값이 기본으로 들어간다 — 실측(LF): 현재가 22,300인데 18,710이 미리 채워졌다.
     * 그대로 등록하면 실현손익이 실제보다 작게 기록된다. 계획가가 아니라 <b>지금 팔리는 값</b>이 기본이어야 한다.
     * (시세가 없을 때만 자동매도가로 물러선다 — 빈 칸보다는 낫다) */
    $sellDefault = ($last !== null) ? $last : $c['sell_price'];

    echo '<script>';
    echo 'const PF_STEPS=' . json_encode($stepJs, JSON_UNESCAPED_UNICODE) . ';';
    echo 'const PF_POS='   . json_encode([
        'held'      => $held,
        'avg'       => $c['avg_cost'],
        'last'      => $last,
        'sellDef'   => $sellDefault,
        'sellCost'  => $prm['sell_cost_rate'],
        'buyCost'   => $prm['buy_cost_rate'],
        'sellFee'   => $prm['sell_fee_rate'],
        'taxRate'   => $prm['tax_rate'],
        'feeTiers'  => $prm['fee_tiers'],
        'tick'      => $prm['tick'],
        'curStep'   => $c['cur_step'],
        'used'      => $c['used_amount'],
        'limitLeft' => $c['limit_remain'],
        'sellPrice' => $c['sell_price'],
        'rate'      => $c['rate'],
        'evalPl'    => $c['eval_pl'],
    ], JSON_UNESCAPED_UNICODE) . ';';

    echo <<<'JS'
var PF_CUR = null;   // 현재 모달에 열린 차수

/* 사용자가 수량 칸을 <b>직접</b> 고쳤나.
 *
 * 모달은 두 가지로 쓰인다: ① "얼마에 몇 주 살까"(계획) ② "실제로 이 값에 이만큼 체결했다"(기록).
 * ①에서는 체결가를 고치면 수량이 따라와야 맞고, ②에서는 <b>절대 건드리면 안 된다</b> — 사실이기 때문이다.
 * 그래서 수량을 직접 입력하기 전까지만 가격을 따라가게 한다.
 */
var PF_QTY_DIRTY = false;

function pfN(v){ return (v === null || v === undefined) ? '-' : Math.round(v).toLocaleString(); }

/** 콤마 입력칸(.num-comma)의 숫자값 — 콤마를 걷고 읽는다 (parseFloat("1,630,000")=1 사고 방지) */
function pfInVal(id){
  var el = document.getElementById(id);
  return el ? (parseFloat(String(el.value).replace(/,/g, '')) || 0) : 0;
}

/** 이 체결가에서 목표(누적목표 − 투입액)를 채우는 수량. 채울 것이 없으면 null */
function pfRecommendQty(pr){
  var s = PF_STEPS[PF_CUR];
  if (!s || !(pr > 0) || !(s.need > 0)) return null;
  return Math.floor(s.need / pr);
}

/** 「목표 맞추기」 — 지금 체결가 기준 권장 수량으로 채운다. 이후 다시 가격을 따라가게 둔다. */
function pfFitQty(){
  var pr = pfInVal('pfmBuyPrice');
  var rq = pfRecommendQty(pr);
  if (rq === null || rq <= 0) return;
  document.getElementById('pfmBuyQty').value = rq.toLocaleString();
  PF_QTY_DIRTY = false;
  pfBuyCalc();
}

function pfOpenStep(n){
  var s = PF_STEPS[n];
  if (!s) return;
  PF_CUR = n;

  document.getElementById('pfmTitle').textContent = n + '차';
  document.getElementById('pfmSub').textContent   =
    '비중 ' + (s.weight * 100).toFixed(2) + '%' + (s.date ? ' · ' + s.date : '');

  var info = [
    ['이론가',   pfN(s.tPrice)],
    ['누적목표', pfN(s.planCum)],
    ['매수 필요', pfN(s.need)]
  ];
  if (s.traded) {
    info.push(['매수단가', pfN(s.price)]);
    info.push(['매수수량', pfN(s.qty) + '주']);
    info.push(['보유수량', pfN(s.held) + '주']);
    info.push(['집행률',   (s.exec * 100).toFixed(0) + '%']);
  }
  document.getElementById('pfmInfo').innerHTML = info.map(function(x){
    return '<div class="pfm-i"><div class="k">' + x[0] + '</div><div class="v">' + x[1] + '</div></div>';
  }).join('');

  // ── 매수 기본값 — 누적목표를 채우는 금액을, 현재가가 더 싸면 현재가로 나눈다
  var basePrice = (PF_POS.last !== null && s.tPrice !== null && PF_POS.last <= s.tPrice)
    ? PF_POS.last : s.tPrice;
  var q = (basePrice > 0) ? Math.floor(s.need / basePrice) : 0;

  document.getElementById('pfmBuyStep').value  = n;
  document.getElementById('pfmBuyPrice').value = (basePrice !== null) ? Number(basePrice).toLocaleString() : '';
  document.getElementById('pfmBuyQty').value   = (q > 0) ? q.toLocaleString() : '';
  PF_QTY_DIRTY = false;   // 열 때마다 초기화 — 아직 사용자가 손대지 않은 상태다
  document.getElementById('pfmBuyNote').textContent = (s.need > 0)
    ? '누적목표 ' + pfN(s.planCum) + ' − 투입 ' + pfN(PF_POS.used) + ' = ' + pfN(s.need)
      + '원을 ' + pfN(basePrice) + '원으로 나눠 ' + q.toLocaleString() + '주. 실제 체결값으로 덮어쓰면 다시 계산됩니다.'
    : '이 차수의 누적목표는 이미 채웠습니다. 추가로 담으면 다음 차수 매수액이 줄어듭니다.';

  // ── 매도 탭은 그 차수에 보유분이 있을 때만
  var canSell = !!(s.traded && s.held > 0 && PF_POS.held > 0);
  document.getElementById('pfmTabs').style.display = canSell ? '' : 'none';

  if (canSell) {
    document.getElementById('pfmSellPrice').value = (PF_POS.sellDef !== null) ? Number(PF_POS.sellDef).toLocaleString() : '';
    document.getElementById('pfmSellQty').value   = s.held.toLocaleString();

    var quick = [['이 차수분 ' + s.held.toLocaleString() + '주', s.held]];
    [['¼', 0.25], ['⅓', 1/3], ['½', 0.5]].forEach(function(x){
      quick.push([x[0], Math.max(1, Math.floor(PF_POS.held * x[1]))]);
    });
    quick.push(['전량 ' + PF_POS.held.toLocaleString() + '주', PF_POS.held]);

    document.getElementById('pfmQuick').innerHTML = quick.map(function(x){
      return '<button type="button" class="btn btn-outline btn-sm" onclick="pfSellSet(' + x[1] + ')">' + x[0] + '</button>';
    }).join('');
  }

  pfTab('buy');
  document.getElementById('pfModal').classList.add('on');
  document.body.style.overflow = 'hidden';
}

/**
 * 전량 매도 — 요약카드의 「자동매도가」를 눌렀을 때 열린다.
 *
 * 차수 사다리와 달리 <b>특정 차수가 아니라 보유 전체</b>를 파는 흐름이다.
 * 매도 폼에 step_no 가 없는 것도 같은 이유다 — 매도는 어차피 차수별로 비례 안분된다.
 * ★ PF_CUR 를 null 로 되돌린다. 남겨 두면 매수 탭 미리보기(pfBuyCalc)가 엉뚱한 차수를 물고 계산한다.
 */
function pfOpenSellAll(){
  if (!PF_POS.held) return;
  PF_CUR = null;

  document.getElementById('pfmTitle').textContent = '전량 매도';
  document.getElementById('pfmSub').textContent   =
    '보유 ' + PF_POS.held.toLocaleString() + '주'
    + (PF_POS.curStep ? ' · ' + PF_POS.curStep + '차까지' : '');

  var info = [
    ['자동매도가', pfN(PF_POS.sellPrice)],
    ['현재가',     pfN(PF_POS.last)],
    ['누적단가',   pfN(PF_POS.avg)],
    ['보유수량',   pfN(PF_POS.held) + '주']
  ];
  document.getElementById('pfmInfo').innerHTML = info.map(function(x){
    return '<div class="pfm-i"><div class="k">' + x[0] + '</div><div class="v">' + x[1] + '</div></div>';
  }).join('');

  // 매수 탭은 이 흐름에서 뜻이 없으므로 탭 줄을 숨기고 매도 폼만 띄운다
  document.getElementById('pfmTabs').style.display = 'none';

  document.getElementById('pfmSellPrice').value = (PF_POS.sellDef !== null) ? Number(PF_POS.sellDef).toLocaleString() : '';
  document.getElementById('pfmSellQty').value   = PF_POS.held.toLocaleString();

  // 전량이 기본이니 전량을 맨 앞에 두고, 일부만 팔 선택지도 남긴다
  var quick = [['전량 ' + PF_POS.held.toLocaleString() + '주', PF_POS.held]];
  [['½', 0.5], ['⅓', 1/3], ['¼', 0.25]].forEach(function(x){
    quick.push([x[0], Math.max(1, Math.floor(PF_POS.held * x[1]))]);
  });
  document.getElementById('pfmQuick').innerHTML = quick.map(function(x){
    return '<button type="button" class="btn btn-outline btn-sm" onclick="pfSellSet(' + x[1] + ')">' + x[0] + '</button>';
  }).join('');

  pfTab('sell');
  document.getElementById('pfModal').classList.add('on');
  document.body.style.overflow = 'hidden';
}

function pfCloseModal(){
  document.getElementById('pfModal').classList.remove('on');
  document.body.style.overflow = '';
}

function pfTab(name){
  document.getElementById('pfmBuy').style.display  = (name === 'buy')  ? '' : 'none';
  document.getElementById('pfmSell').style.display = (name === 'sell') ? '' : 'none';
  Array.prototype.forEach.call(document.querySelectorAll('#pfmTabs button'), function(b){
    b.classList.toggle('on', b.dataset.tab === name);
  });
  if (name === 'buy') pfBuyCalc(); else pfSellCalc();
}

function pfSellSet(q){
  document.getElementById('pfmSellQty').value = Math.max(1, Math.min(q, PF_POS.held)).toLocaleString();
  pfSellCalc();
}

function pfFloorTick(v){ return Math.floor(Math.round(v / PF_POS.tick * 1e6) / 1e6) * PF_POS.tick; }
function pfCeilTick(v){  return Math.ceil( Math.round(v / PF_POS.tick * 1e6) / 1e6) * PF_POS.tick; }

/* 위탁수수료 — 구간표가 있으면 구간별 요율+정액, 없으면 정률 (calc.php pf_fee_amount 와 동일) */
function pfFeeAmount(amount, flatRate){
  if (amount <= 0) return 0;
  var tiers = PF_POS.feeTiers || [];
  if (!tiers.length) return amount * flatRate;

  var sel = null;
  tiers.forEach(function(t){
    if (amount < t.min_amt) return;
    if (sel === null || t.min_amt >= sel.min_amt) sel = t;
  });
  if (sel === null) sel = tiers[0];
  return amount * sel.fee_rate + sel.fee_fixed;
}
function pfBuyFee(amount){  return pfFeeAmount(amount, PF_POS.buyCost); }
function pfSellCost(amount){ return pfFeeAmount(amount, PF_POS.sellFee) + amount * PF_POS.taxRate; }

/** 변화량 셀 — 부호와 색을 붙인다 (양수 빨강 / 음수 파랑) */
function pfDelta(v, unit, dec){
  if (v === null || v === undefined || !isFinite(v)) return '<span class="flat">-</span>';
  var cls  = (v > 0) ? 'up' : ((v < 0) ? 'down' : 'flat');
  var sign = (v > 0) ? '+' : '';
  var txt  = (dec === undefined) ? Math.round(v).toLocaleString() : v.toFixed(dec);
  return '<span class="' + cls + '">' + sign + txt + (unit || '') + '</span>';
}
function pfPctTxt(v){ return (v === null || !isFinite(v)) ? '-' : (v * 100).toFixed(2) + '%'; }

/**
 * 매수 미리보기 — 물타기 효과를 현재 / 매수 후 / 변화 로 보여준다.
 *
 *   차수 평균단가 = (기존 매수금액 + 신규 매수금액) ÷ (기존 수량 + 신규 수량)
 *   전체 누적단가 = (누적단가 × 보유수량 + 체결가 × 수량 × (1+매수비용률)) ÷ (보유수량 + 수량)
 *   수익률       = 현재가 ÷ 누적단가 - 1
 *   평가손익     = 보유수량 × (현재가 - 누적단가)
 */
function pfBuyCalc(){
  var s  = PF_STEPS[PF_CUR]; if (!s) return;
  var q  = Math.floor(pfInVal('pfmBuyQty'));
  var pr = pfInVal('pfmBuyPrice');
  var el = document.getElementById('pfmBuyPreview');

  if (q <= 0 || pr <= 0) {
    el.innerHTML = '<div class="sp-note">체결가와 수량을 입력하면 ' +
      '<b>차수 평균단가·전체 누적단가·수익률</b>이 어떻게 바뀌는지 계산해 드립니다.</div>';
    return;
  }

  var last = PF_POS.last;
  var amt  = pr * q;
  var fee  = pfBuyFee(amt);          // 이번 매수의 위탁수수료

  // ── 이 차수
  var sAvgOld = s.traded ? s.price : null;
  var sQtyOld = s.traded ? s.qty   : 0;
  var sAmtOld = s.traded ? s.amount : 0;
  var sQtyNew = sQtyOld + q;
  var sAvgNew = (sAmtOld + amt) / sQtyNew;
  var execOld = (s.plan > 0 && s.traded) ? sAmtOld / s.plan : 0;
  var execNew = (s.plan > 0) ? (sAmtOld + amt) / s.plan : 0;

  // ── 전체 (이동평균)
  var held    = PF_POS.held;
  var avgOld  = PF_POS.avg;
  var heldNew = held + q;
  var avgNew  = (held > 0 && avgOld !== null)
    ? (avgOld * held + amt + fee) / heldNew
    : (amt + fee) / q;

  var rateOld = (avgOld !== null && last !== null) ? (last / avgOld - 1) : null;
  var rateNew = (last !== null) ? (last / avgNew - 1) : null;
  var plOld   = (avgOld !== null && last !== null) ? held * (last - avgOld) : null;
  var plNew   = (last !== null) ? heldNew * (last - avgNew) : null;

  // ── 자동매도가 (도달 차수가 올라가면 목표수익률도 바뀐다)
  var curNew  = Math.max(PF_POS.curStep, s.no);
  var tgtNew  = (PF_STEPS[curNew] && PF_STEPS[curNew].target !== null) ? PF_STEPS[curNew].target : null;
  var sellNew = null;
  if (tgtNew !== null) {
    // 구간 수수료면 예상 매도대금에서 실효율을 구해 쓴다 (calc.php pf_auto_sell_price 와 동일)
    var gross   = avgNew * (1 + tgtNew) * heldNew;
    var effRate = (gross > 0) ? pfSellCost(gross) / gross : PF_POS.sellCost;
    sellNew = pfCeilTick(avgNew * (1 + tgtNew) / (1 - effRate));
  }

  // ── 다음 매수가 (이 차수가 최전선이면 사다리 기준가가 바뀐다)
  var nextNew = null, nextNo = null;
  if (s.no >= PF_POS.curStep && PF_STEPS[s.no + 1]) {
    var base = (s.tPrice !== null) ? Math.min(pr, s.tPrice) : pr;
    nextNo  = s.no + 1;
    nextNew = pfFloorTick(base * (1 + PF_STEPS[nextNo].drop));
  }

  var left = PF_POS.limitLeft - amt;

  function row(label, oldTxt, newTxt, deltaHtml){
    return '<tr><td class="k">' + label + '</td><td class="o">' + oldTxt +
           '</td><td class="n">' + newTxt + '</td><td class="d">' + deltaHtml + '</td></tr>';
  }

  var html = '<div class="sp-row"><span class="sp-k">매수 수수료</span><span class="sp-v">' + pfN(fee) + '</span>' +
    '<span class="sp-x">매수금액 ' + pfN(amt) + '원의 ' + (fee / amt * 100).toFixed(3) + '%</span></div>';

  /* ── 목표 대비 ─────────────────────────────────────────────────────────
   * 체결가가 움직이면 같은 수량으로도 금액이 목표(누적목표 − 투입액)를 빗나간다.
   *
   * ★ 판정 기준은 "금액이 남았나"가 아니라 <b>"현황에 잔여매수로 뜨나"</b>다.
   *   floor 때문에 목표에 맞춰도 늘 몇 천 원이 남는데(1주 값 미만), 그건 잔여매수 신호를 만들지 않는다
   *   (fill_qty = floor(남은금액 ÷ 현재가) 라 0 이 된다). 남은 금액만 보고 경고하면 늘 경고가 뜬다.
   * ★ 초과는 경고하지 않는다 — 다음 차수 누적목표에 흡수돼 그만큼 덜 사게 되므로 계획이 깨지지 않는다.
   *   (누적목표 방식의 장점이다. 차수별 고정금액 방식이면 초과분이 그대로 사고였다) */
  var need = s.need || 0;
  if (need > 0) {
    var resid   = need - amt;                                       // 양수 = 부족
    var leftQty = (last > 0) ? Math.floor(Math.max(0, resid) / last) : 0;
    var rq      = Math.floor(need / pr);
    var overQty = q - rq;
    var cls, txt;

    if (leftQty >= 1) {
      cls = 'short';
      txt = '<b>' + leftQty.toLocaleString() + '주가 모자랍니다</b> — 이대로 등록하면 현황에 '
          + '<b>잔여매수 +' + leftQty.toLocaleString() + '주</b>로 남습니다. 권장 ' + rq.toLocaleString() + '주'
          + ' <button type="button" class="btn btn-outline btn-sm" onclick="pfFitQty()">목표 맞추기</button>';
    } else if (resid > 0) {
      cls = 'fit';
      txt = '목표에 맞습니다 — 남는 ' + pfN(resid) + '원은 1주 값 미만이라 잔여매수로 뜨지 않습니다.';
    } else if (overQty > 0) {
      cls = 'over';
      txt = overQty.toLocaleString() + '주 더 담습니다 (' + pfN(-resid) + '원 초과) — '
          + '다음 차수 누적목표에 흡수돼 그만큼 덜 사게 되므로 계획은 깨지지 않습니다.';
    } else {
      cls = 'fit';
      txt = (resid < 0)
        ? '목표에 맞습니다 — ' + pfN(-resid) + '원 초과지만 1주 값 미만입니다.'
        : '목표에 딱 맞습니다.';
    }
    html += '<div class="sp-row goal ' + cls + '"><span class="sp-k">목표 대비</span>'
          + '<span class="sp-v ' + (resid > 0 ? 'down' : (resid < 0 ? 'up' : 'flat')) + '">'
          + (resid < 0 ? '+' : (resid > 0 ? '−' : '')) + pfN(Math.abs(resid)) + '</span>'
          + '<span class="sp-x">필요 ' + pfN(need) + ' → 이번 ' + pfN(amt) + '</span>'
          + '<span class="sp-msg">' + txt + '</span></div>';
  }

  html += '<table class="ba"><thead><tr><th></th><th>현재</th><th>매수 후</th><th>변화</th></tr></thead><tbody>';

  html += '<tr class="grp"><td colspan="4">' + s.no + '차</td></tr>';
  html += row('평균단가',
    (sAvgOld !== null ? pfN(sAvgOld) : '-'), pfN(sAvgNew),
    (sAvgOld !== null ? pfDelta(sAvgNew - sAvgOld) : '<span class="flat">신규</span>'));
  html += row('수량',
    sQtyOld.toLocaleString() + '주', sQtyNew.toLocaleString() + '주', pfDelta(q, '주'));
  html += row('집행률',
    (s.traded ? (execOld * 100).toFixed(0) + '%' : '0%'), (execNew * 100).toFixed(0) + '%',
    pfDelta((execNew - execOld) * 100, '%p', 0));

  html += '<tr class="grp"><td colspan="4">전체</td></tr>';
  html += row('누적단가',
    (avgOld !== null ? pfN(avgOld) : '-'), pfN(avgNew),
    (avgOld !== null ? pfDelta(avgNew - avgOld) : '<span class="flat">신규</span>'));
  html += row('보유수량',
    held.toLocaleString() + '주', heldNew.toLocaleString() + '주', pfDelta(q, '주'));
  html += row('수익률',
    pfPctTxt(rateOld), pfPctTxt(rateNew),
    (rateOld !== null && rateNew !== null) ? pfDelta((rateNew - rateOld) * 100, '%p', 2) : '-');
  html += row('평가손익',
    (plOld !== null ? pfN(plOld) : '-'), (plNew !== null ? pfN(plNew) : '-'),
    (plOld !== null && plNew !== null) ? pfDelta(plNew - plOld) : '-');
  html += row('자동매도가',
    (PF_POS.sellPrice !== null ? pfN(PF_POS.sellPrice) : '-'),
    (sellNew !== null ? pfN(sellNew) : '-'),
    (PF_POS.sellPrice !== null && sellNew !== null) ? pfDelta(sellNew - PF_POS.sellPrice) : '-');
  if (nextNew !== null) {
    html += row('다음매수가 (' + nextNo + '차)',
      (PF_STEPS[nextNo].tPrice !== null ? pfN(PF_STEPS[nextNo].tPrice) : '-'), pfN(nextNew),
      pfDelta(nextNew - (PF_STEPS[nextNo].tPrice || 0)));
  }
  html += '</tbody></table>';

  // 물타기 효과 한 줄 요약
  var note;
  if (avgOld === null) {
    note = '첫 매수입니다. 누적단가는 <b>' + pfN(avgNew) + '원</b>(매수비용 포함)이 됩니다.';
  } else if (avgNew < avgOld) {
    note = '누적단가가 <b>' + pfN(avgOld - avgNew) + '원</b> 낮아집니다 — 물타기 효과. ' +
           '수익률은 ' + pfPctTxt(rateOld) + ' → <b>' + pfPctTxt(rateNew) + '</b>';
  } else {
    note = '<span class="down">체결가가 누적단가(' + pfN(avgOld) + ')보다 높아 평균단가가 <b>' +
           pfN(avgNew - avgOld) + '원</b> 올라갑니다.</span> 수익률 ' +
           pfPctTxt(rateOld) + ' → <b>' + pfPctTxt(rateNew) + '</b>';
  }
  html += '<div class="sp-note">' + note + '<br>투입 <b>' + pfN(amt) + '원</b> · ' +
    (left < 0 ? '<span class="down">투자한도 ' + pfN(-left) + '원 초과</span>'
              : '매수 후 남은 한도 <b>' + pfN(left) + '원</b>') + '</div>';

  el.innerHTML = html;
}

/* 매도 미리보기 — 실현손익 = 매도가 × 수량 × (1-매도비용률) - 누적단가 × 수량 (이동평균법) */
function pfSellCalc(){
  var q  = Math.floor(pfInVal('pfmSellQty'));
  var pr = pfInVal('pfmSellPrice');
  var el = document.getElementById('pfmSellPreview');
  if (!el) return;

  if (q <= 0 || pr <= 0) { el.innerHTML = ''; return; }
  if (q > PF_POS.held) {
    el.innerHTML = '<div class="sp-note"><span class="down">보유 ' +
      PF_POS.held.toLocaleString() + '주보다 많이 매도할 수 없습니다.</span></div>';
    return;
  }

  var gross    = pr * q;
  var sellCost = pfSellCost(gross);           // 위탁수수료 + 증권거래세
  var proceeds = gross - sellCost;
  var cost     = PF_POS.avg * q;
  var pl       = proceeds - cost;
  var rate     = cost > 0 ? (pl / cost) : 0;
  var cls      = pl > 0 ? 'up' : (pl < 0 ? 'down' : 'flat');
  var left     = PF_POS.held - q;

  el.innerHTML =
    '<div class="sp-row"><span class="sp-k">매도금액</span><span class="sp-v">' + pfN(gross) + '</span>' +
      '<span class="sp-x">' + pr.toLocaleString() + ' × ' + q.toLocaleString() + '주</span></div>' +
    '<div class="sp-row"><span class="sp-k">수수료+세금</span><span class="sp-v down">−' + pfN(sellCost) + '</span>' +
      '<span class="sp-x">' + (sellCost / gross * 100).toFixed(3) + '%</span></div>' +
    '<div class="sp-row"><span class="sp-k">수취액</span><span class="sp-v">' + pfN(proceeds) + '</span>' +
      '<span class="sp-x"></span></div>' +
    '<div class="sp-row"><span class="sp-k">원가</span><span class="sp-v">' + pfN(cost) + '</span>' +
      '<span class="sp-x">누적단가 ' + pfN(PF_POS.avg) + ' × ' + q.toLocaleString() + '주</span></div>' +
    '<div class="sp-row sp-total"><span class="sp-k">예상 실현손익</span>' +
      '<span class="sp-v ' + cls + '">' + (pl > 0 ? '+' : '') + pfN(pl) + '</span>' +
      '<span class="sp-x ' + cls + '">' + (rate * 100).toFixed(2) + '%</span></div>' +
    '<div class="sp-note">' + (left === 0
      ? '전량 매도 → <b>종료(closed)</b>로 바뀝니다.'
      : '잔여 <b>' + left.toLocaleString() + '주</b> · 차수·누적단가·다음매수가는 그대로 유지됩니다.') + '</div>';
}

document.addEventListener('input', function(e){
  if (e.target.id === 'pfmBuyQty') {
    PF_QTY_DIRTY = true;          // 여기서부터는 사용자의 값이다 — 다시 덮어쓰지 않는다
    pfBuyCalc();
  }
  if (e.target.id === 'pfmBuyPrice') {
    /* 체결가만 고쳤을 때 수량이 <b>옛 가격 기준</b>으로 남아 목표를 빗나가는 것이 이 화면의 잔 사고였다.
     * (실측: 6,450원 기준 710주로 열어 두고 체결가만 6,510 으로 고치면 6주를 초과 매수한다)
     * 아직 수량을 손대지 않았으면 가격을 따라가게 한다. */
    if (!PF_QTY_DIRTY) {
      var rq = pfRecommendQty(parseFloat(e.target.value.replace(/,/g, '')) || 0);
      if (rq !== null && rq > 0) document.getElementById('pfmBuyQty').value = rq.toLocaleString();
    }
    pfBuyCalc();
  }
  if (e.target.id === 'pfmSellQty' || e.target.id === 'pfmSellPrice') pfSellCalc();
});
document.addEventListener('keydown', function(e){
  if (e.key === 'Escape') pfCloseModal();
});
document.getElementById('pfmSell').addEventListener('submit', function(e){
  var q = Math.floor(pfInVal('pfmSellQty'));
  if (q > PF_POS.held) { e.preventDefault(); alert('보유 수량을 초과했습니다.'); return; }
  if (q === PF_POS.held && !confirm('전량 매도합니다. 이 종목은 종료(closed) 처리됩니다. 진행할까요?')) e.preventDefault();
});
JS;
    echo '</script>';

    echo <<<'CSS'
<style>
@media(max-width:960px){.pf-detail-grid{grid-template-columns:1fr !important}}
.btn-sell{background:#1565c0;color:#fff}
.btn-sell:hover{background:#114f9c}
/* 요약카드 값 뒤에 붙는 보조 수치 — 「현재차수 1차 +710주」의 +710주.
   목록 화면의 차수 배지(table.pf.pos td.step .more)와 같은 붉은 계열로 묶는다(같은 값이다) */
.sum-box .v .v-add{font-size:12.5px;font-weight:800;color:#c62828;margin-left:5px;cursor:help;
  vertical-align:2px;white-space:nowrap}
/* 아직 안 산 차수의 예고 수량 — 확정값과 구별되게 회색 ≈ */
table.pf .plan-qty{color:#9aa7b4;font-weight:600;cursor:help}
/* 자동매도가 카드 — 눌러서 전량 매도. 도달했으면(sell_signal) 카드째 붉게 강조한다 */
.sum-box.clickable:hover{border-color:#f0b8b2;box-shadow:0 1px 6px rgba(198,40,40,.15)}
.sum-box.sell-hit{background:#fff4f2;border-color:#f2c2bc}
.badge.st-sell{background:#c62828;color:#fff}
.qbtns{display:flex;gap:4px}
.sell-preview{margin-top:12px}
.sell-preview:empty{display:none}
.sp-row{display:flex;align-items:baseline;gap:10px;padding:5px 12px;font-size:13px;border-bottom:1px dashed #e6edf4}
.sp-row:first-child{border-top:1px solid #e6edf4}
.sp-k{width:96px;color:#7d8b99;font-weight:700;font-size:12px}
/* 목표 대비 줄 — 설명이 길고 버튼이 붙으므로 둘째 줄로 흘린다 */
.sp-row.goal{flex-wrap:wrap;border-radius:6px}
.sp-row.goal.short{background:#fff4f2}
.sp-row.goal.fit{background:#f2fbf7}
.sp-row.goal.over{background:#f7fafc}
.sp-row.goal .sp-msg{flex:1 1 100%;font-size:12px;line-height:1.7;color:#5f7183;margin-top:2px}
.sp-row.goal.short .sp-msg{color:#b3382c}
.sp-row.goal .sp-msg .btn{padding:2px 9px;font-size:11.5px;vertical-align:1px}
.sp-v{min-width:120px;text-align:right;font-weight:800;font-variant-numeric:tabular-nums}
.sp-x{color:#9aa7b4;font-size:12px}
.sp-total{background:#f7fafd;border-bottom:none;padding:8px 12px}
.sp-total .sp-v{font-size:16px}
.sp-note{padding:7px 12px;font-size:12px;color:#7d8b99;background:#fafcfe;border-radius:0 0 8px 8px}

/* 계획 대비 집행 */
.plan-box{margin-top:12px;border:1px solid #e3eaf0;border-radius:10px;overflow:hidden;background:#fff}
.plan-head{padding:8px 12px;background:#f5f8fb;font-size:13px;font-weight:800;color:#3c4d5e;border-bottom:1px solid #e6edf4}
.plan-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(122px,1fr))}
.plan-cell{padding:9px 12px;border-right:1px solid #f0f4f8}
.plan-cell:last-child{border-right:none}
.plan-cell .k{font-size:11px;color:#8b98a5;font-weight:700}
.plan-cell .v{font-size:15px;font-weight:800;margin-top:2px;font-variant-numeric:tabular-nums}
.plan-cell .s{font-size:11px;color:#a3aeb9;margin-top:2px}
/* 드래그 정렬 */
.drag-handle{display:inline-flex;align-items:center;justify-content:center;cursor:grab;color:#b9c4cf;
  width:26px;height:26px;border-radius:6px;user-select:none;transition:.12s}
.drag-handle svg{width:11px;height:18px;pointer-events:none}
.drag-handle:hover{color:#1d5c93;background:#e8f1fa}
.drag-handle:active{cursor:grabbing;background:#d7e7f5}
tr.dragging{opacity:.4;background:#eaf3fc !important}
tr.folio-sort{transition:background .1s}

/* 토스트 */
.pf-toast{position:fixed;left:50%;bottom:26px;transform:translateX(-50%) translateY(8px);
  background:#12406b;color:#fff;padding:10px 20px;border-radius:9px;font-size:13px;font-weight:700;
  z-index:300;opacity:0;transition:.18s;pointer-events:none;box-shadow:0 6px 20px rgba(10,25,45,.3)}
.pf-toast.on{opacity:1;transform:translateX(-50%) translateY(0)}
.pf-toast.err{background:#b3261e}

.fee-mode{display:flex;gap:6px;margin-bottom:12px}
.fee-mode span{font-size:12px;font-weight:700;padding:4px 11px;border-radius:20px;background:#f0f4f8;color:#9aa7b4}
.fee-mode span.on{background:#e3f0fd;color:#1565c0}
table.tier-tbl td{padding:5px 8px}
.formula{background:#f7fafd;border:1px solid #e6edf4;border-radius:9px;padding:11px 14px;font-size:13.5px;
  line-height:2;font-variant-numeric:tabular-nums}
.formula .fk{display:inline-block;min-width:88px;font-weight:800;color:#12406b}
.formula .op{color:#8b98a5;font-weight:800;margin:0 3px}
.plan-warn{padding:9px 12px;background:#fff5f4;border-top:1px solid #f6dcd8;color:#b3261e;font-size:12.5px;font-weight:600}
.plan-note{padding:9px 12px;background:#f4f8fd;border-top:1px solid #dde8f4;color:#1565c0;font-size:12.5px;font-weight:600}

/* 차수 클릭 */
table.pf tbody tr.stp{cursor:pointer}
table.pf tbody tr.stp:hover{background:#eaf3fc !important;box-shadow:inset 3px 0 0 #1d5c93}
table.pf tbody tr.cur{background:#ffe9e6}

/* 지금 채워야 할 차수 */
table.pf tbody tr.buy{background:#f2f8ff}
table.pf tbody tr.buy-target{background:#dcecfd;box-shadow:inset 4px 0 0 #1d5c93}
table.pf tbody tr.buy-target td{font-weight:700;color:#12406b}
table.pf tbody tr.next-target{box-shadow:inset 4px 0 0 #c9d4de}
.badge.st-buy{background:#1d5c93;color:#fff}
.badge.st-next{background:#eceff2;color:#78868f}
.badge.st-skip{background:#f4f1e8;color:#9a8a5f}
.clickable{cursor:pointer}
.clickable:hover{filter:brightness(.97)}

/* 일봉 위 체결 라벨(.dc-mk) 스타일은 style/dailychart.js 가 주입한다 */

/* 종목 상세의 전략 판단 한 줄 — 현황 카드의 내용을 옮긴 자리 */
.strat-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:#fff;
  border:1px solid #e3eaf0;border-radius:10px;padding:9px 12px;margin-bottom:14px;
  box-shadow:0 1px 3px rgba(20,40,60,.05)}
.strat-row .sr-k{font-size:12px;font-weight:800;color:#12406b;white-space:nowrap}
.strat-row .sig-conf,.strat-row .sig-fund,.strat-row .sig-fill{margin-top:0}

/* 차수 모달 */
.pf-modal-back{display:none;position:fixed;inset:0;background:rgba(16,32,48,.5);z-index:200;
  align-items:flex-start;justify-content:center;padding:40px 14px;overflow-y:auto}
.pf-modal-back.on{display:flex}
.pf-modal{background:#fff;border-radius:13px;width:100%;max-width:660px;box-shadow:0 18px 50px rgba(10,25,45,.3);overflow:hidden}
.pfm-head{display:flex;justify-content:space-between;align-items:center;padding:13px 16px;
  background:linear-gradient(90deg,#123c63,#1d5c93);color:#fff;font-size:17px;font-weight:800}
.pfm-head .muted{color:#bcd6ec}
.pfm-x{background:rgba(255,255,255,.16);border:none;color:#fff;font-size:14px;cursor:pointer;
  width:28px;height:28px;border-radius:7px;line-height:1}
.pfm-x:hover{background:rgba(255,255,255,.3)}
.pfm-info{display:grid;grid-template-columns:repeat(auto-fit,minmax(96px,1fr));background:#f5f8fb;
  border-bottom:1px solid #e6edf4}
.pfm-i{padding:8px 12px;border-right:1px solid #e9eff5}
.pfm-i:last-child{border-right:none}
.pfm-i .k{font-size:11px;color:#8b98a5;font-weight:700}
.pfm-i .v{font-size:14px;font-weight:800;margin-top:2px;font-variant-numeric:tabular-nums}
.pfm-tabs{display:flex;gap:4px;padding:10px 14px 0;border-bottom:1px solid #e6edf4}
.pfm-tabs button{border:none;background:none;padding:8px 16px;font-size:14px;font-weight:800;
  color:#8b98a5;cursor:pointer;border-radius:8px 8px 0 0;font-family:inherit}
.pfm-tabs button.on{background:#eaf3fc;color:#12406b;box-shadow:inset 0 -2px 0 #1d5c93}
.pfm-body{padding:14px 16px 0}
.pfm-preview{margin-top:12px}
.pfm-preview:empty{display:none}

/* 현재 → 매수 후 → 변화 비교표 */
table.ba{width:100%;border-collapse:collapse;font-size:13px;border:1px solid #e6edf4;border-radius:8px;overflow:hidden}
table.ba thead th{background:#f5f8fb;color:#8b98a5;font-size:11px;font-weight:700;padding:5px 10px;text-align:right;
  border-bottom:1px solid #e6edf4}
table.ba thead th:first-child{text-align:left;width:34%}
table.ba td{padding:5px 10px;border-bottom:1px solid #f2f6fa;text-align:right;
  font-variant-numeric:tabular-nums;white-space:nowrap}
table.ba td.k{text-align:left;color:#5f7183;font-weight:700}
table.ba td.o{color:#9aa7b4}
table.ba td.n{font-weight:800;color:#22303f}
table.ba td.d{width:23%}
table.ba tr.grp td{background:#eef4fa;color:#2b4a68;font-weight:800;font-size:11px;
  text-align:left;padding:4px 10px;letter-spacing:.02em}
table.ba tbody tr:last-child td{border-bottom:none}
.pfm-foot{display:flex;justify-content:space-between;align-items:center;gap:12px;
  padding:13px 0;margin-top:12px;border-top:1px solid #eef2f6}
.pfm-foot .muted{font-size:12px;line-height:1.4}
#pfmQuick{margin-top:10px;flex-wrap:wrap}
@media(max-width:560px){
  .pf-modal-back{padding:14px 8px}
  .pfm-foot{flex-direction:column;align-items:stretch}
  .pfm-foot .btn{width:100%}
}
</style>
CSS;

    pf_foot();
}

/** 종목 등록/수정 폼 */
function pf_render_position_form(Pf $pf, ?array $pos): void
{
    $folios  = $pf->portfolios();
    $rules   = $pf->ruleSets();
    $markets = $pf->markets();
    $isNew   = ($pos === null);
    /* 박스 사다리 — 매수 방식은 편입 때 정한다(룰셋 종목을 박스로 전환하는 흐름이 아니다).
     * 수정 화면에서는 이미 박스인 포지션만 같은 섹션으로 재조정할 수 있다. */
    $posLvF  = $isNew ? [] : $pf->positionLevels((int)$pos['id']);
    $buyMode = $posLvF ? 'box' : 'rule';

    // 대시보드에서 "＋ 종목 추가"로 들어오면 해당 포트폴리오를 미리 선택해 둔다
    $preFid = $isNew ? (int)($_GET['pid'] ?? 0) : (int)$pos['portfolio_id'];
    $preName = '';
    foreach ($folios as $f) if ((int)$f['id'] === $preFid) $preName = $f['name'];

    pf_head($isNew ? '종목 추가' : '종목 설정 수정', 'dashboard');
    pf_flash();

    echo '<div class="pf-head"><div><h1>' . ($isNew ? '종목 추가' : '종목 설정 수정') . '</h1>';
    echo '<div class="sub">';
    if ($preName !== '') echo '<b>' . pf_h($preName) . '</b>에 담습니다. ';
    echo '종목은 이름이나 코드로 검색해 고르면 현재가까지 자동으로 채워집니다.</div>';
    $backUrl = $preFid ? ('/stock/index.php?id=' . $preFid) : '/stock/index.php';
    echo '</div><div class="act"><a class="btn btn-outline" href="' . $backUrl . '">← 돌아가기</a></div></div>';

    if (!$folios) {
        echo '<div class="err">먼저 <a href="/stock/index.php?mode=portfolio">포트폴리오</a>를 등록하세요.</div>';
        pf_foot();
        return;
    }
    if (!$rules) {
        echo '<div class="err">먼저 <a href="/stock/index.php?mode=ruleset">룰셋</a>을 등록하세요.</div>';
        pf_foot();
        return;
    }

    // 룰셋도 미리 정해 둔다 (?rid= 로 지정 가능, 없으면 첫 번째)
    $preRid = $isNew ? (int)($_GET['rid'] ?? 0) : (int)$pos['rule_set_id'];
    if (!$preRid && $rules) $preRid = (int)$rules[0]['id'];
    $preRule = null;
    foreach ($rules as $r) if ((int)$r['id'] === $preRid) $preRule = $r;

    echo '<div class="card"><form method="post" action="/stock/api.php?module=position&action=save">';
    if (!$isNew) echo '<input type="hidden" name="id" value="' . (int)$pos['id'] . '">';

    echo '<div class="fld-row" style="margin-bottom:12px">';

    // ── 포트폴리오 · 룰셋 — 들어올 때 이미 정해져 있으므로 고정 표시
    if ($isNew) {
        echo '<input type="hidden" name="portfolio_id" value="' . $preFid . '">';
        echo '<div class="fld"><span>포트폴리오</span><div class="fld-fixed">'
           . pf_h($preName !== '' ? $preName : '-') . '</div></div>';

        echo '<input type="hidden" name="rule_set_id" value="' . $preRid . '">';
        echo '<div class="fld"><span>룰셋</span><div class="fld-fixed">'
           . pf_h($preRule['name'] ?? '-')
           . ($preRule ? ' <span class="muted">(' . (int)$preRule['step_count'] . '차수)</span>' : '')
           . '</div></div>';

        // 매수 방식 — 편입 때 정한다. 박스 사다리면 아래 섹션에서 지지선을 고르고 저장 한 번으로 확정.
        echo '<div class="fld"><span>매수 방식</span><div style="padding:7px 0;font-size:13px;white-space:nowrap">'
           . '<label style="margin-right:12px"><input type="radio" name="buy_mode" value="rule" checked onchange="pfBoxToggle()"> 룰셋 하락률</label>'
           . '<label title="이 종목의 실제 박스 지지선(3~5개)을 골라 차수 가격표를 확정합니다 — 1차부터 지지에 닿아야 사는 매복형"><input type="radio" name="buy_mode" value="box" onchange="pfBoxToggle()"> 박스 사다리</label>'
           . '</div></div>';
    } else {
        echo '<label class="fld">포트폴리오<select name="portfolio_id" required>';
        foreach ($folios as $f) {
            $sel = ((int)$f['id'] === $preFid) ? ' selected' : '';
            echo '<option value="' . (int)$f['id'] . '"' . $sel . '>' . pf_h($f['name']) . '</option>';
        }
        echo '</select></label>';

        echo '<label class="fld">룰셋<select name="rule_set_id" required>';
        foreach ($rules as $r) {
            $sel = ((int)$r['id'] === $preRid) ? ' selected' : '';
            echo '<option value="' . (int)$r['id'] . '"' . $sel . '>' . pf_h($r['name'])
               . ' (' . (int)$r['step_count'] . '차수)</option>';
        }
        echo '</select></label>';

        // 매수 방식은 편입 때 정한 값 고정 — 전환은 없다 (박스 포지션만 아래 섹션으로 재조정)
        echo '<input type="hidden" name="buy_mode" value="' . $buyMode . '">';
        echo '<div class="fld"><span>매수 방식</span><div class="fld-fixed">'
           . ($buyMode === 'box' ? '📦 박스 사다리 <span class="muted">(' . count($posLvF) . '차)</span>' : '룰셋 하락률')
           . '</div></div>';
    }

    // ── 종목 — 코드/이름을 하나로 합치고 자동완성으로 고른다 (시뮬레이터와 공용 위젯)
    if ($isNew) {
        pf_stock_picker('stk');
    } else {
        echo '<input type="hidden" name="stock_code" value="' . pf_h($pos['stock_code']) . '">';
        echo '<input type="hidden" name="stock_name" value="' . pf_h($pos['stock_name']) . '">';
        echo '<div class="fld"><span>종목</span><div class="fld-fixed">'
           . pf_h($pos['stock_name']) . ' <span class="muted">' . pf_h($pos['stock_code']) . '</span></div></div>';
    }

    // 투자한도 — 포트폴리오 원금의 배수로 조절한다
    $principal = 0.0;
    if ($isNew) {
        foreach ($folios as $f) if ((int)$f['id'] === $preFid) $principal = (float)$f['principal'];
    } else {
        $principal = (float)($pos['principal'] ?? 0);
    }
    $limitVal = (float)($pos['limit_amt'] ?? 0);
    if ($isNew && $limitVal <= 0) $limitVal = $principal;   // 신규는 원금 ×1 로 시작

    echo '<label class="fld">투자한도(원)<input type="text" class="num-comma" inputmode="numeric" '
       . 'id="limitAmt" name="limit_amt" style="width:150px" value="'
       . pf_h(number_format($limitVal)) . '" required></label>';

    echo '<div class="fld"><span>&nbsp;</span>';
    echo '<div class="mult-box" title="포트폴리오 원금 ' . pf_n($principal) . ' 대비 배수">';
    echo '<button type="button" onclick="pfMult(-0.5)" title="0.5배 낮추기">−</button>';
    echo '<span id="multVal" onclick="pfMultSet(1)" title="클릭하면 ×1">×1</span>';
    echo '<button type="button" onclick="pfMult(0.5)" title="0.5배 올리기">＋</button>';
    echo '</div></div>';

    echo '<label class="fld">시작일<input type="date" name="started_at" value="'
       . pf_h($pos['started_at'] ?? date('Y-m-d')) . '"></label>';

    echo '<label class="fld">메모<input type="text" name="memo" style="width:200px" value="'
       . pf_h($pos['memo'] ?? '') . '"></label>';
    echo '</div>';

    // 종목 정보 — 시장(증권거래세 결정)과 시세
    echo '<h2 style="font-size:14px;margin:4px 0 10px;padding-top:12px;border-top:1px solid #eef2f6">종목 정보</h2>';
    echo '<div class="fld-row" style="margin-bottom:12px">';

    echo '<label class="fld">시장 <span class="muted">(증권거래세 결정)</span><select name="market">';
    $curMarket = $pos['market'] ?? 'KOSPI';
    foreach ($markets as $m) {
        $sel = ($m['code'] === $curMarket) ? ' selected' : '';
        echo '<option value="' . pf_h($m['code']) . '"' . $sel . '>' . pf_h($m['code'])
           . ' · ' . pf_h($m['name']) . ' (' . pf_h(pf_pct0((float)$m['tax_rate'], 2)) . ')</option>';
    }
    echo '</select></label>';

    echo '<label class="fld">현재가 <span class="muted">(비우면 자동)</span>'
       . '<input type="text" class="num-comma" inputmode="numeric" name="last_price" style="width:120px" value="'
       . pf_h(($pos['last_price'] ?? null) === null ? '' : (float)$pos['last_price']) . '"></label>';

    echo '<label class="fld">최고가<input type="text" class="num-comma" inputmode="numeric" name="high_price" style="width:120px" value="'
       . pf_h(($pos['high_price'] ?? null) === null ? '' : (float)$pos['high_price']) . '"></label>';

    if (!$isNew && ($pos['priced_at'] ?? '') !== '') {
        echo '<div class="fld"><span>시세 갱신시각</span><span class="muted" style="font-size:13px;padding:6px 0">'
           . pf_h($pos['priced_at']) . '</span></div>';
    }
    echo '</div>';

    /* ── 박스 사다리 섹션 — 폼 안(하단)이라 고른 가격이 저장 한 번에 같이 실린다.
     * 후보는 박스의 하단(L=지지)·상단(H — 옛 박스 상단도 계단 정의상 지지). 일봉이 하루에
     * 하나씩 생겨 변별력이 없으면 주봉(24주 최고 거래대금)으로. 차트에 후보선을 그려 자리를 보고 고른다. */
    $bxShow = ($buyMode === 'box') || false;
    echo '<div id="boxSection" style="display:' . ($bxShow ? 'block' : 'none') . ';margin:14px 0 12px;'
       . 'padding:12px;border:1px solid #dfe8f0;border-radius:10px;background:#fbfdff">';
    echo '<h2 style="font-size:14px;margin:0 0 8px">📦 박스 사다리 — 지지선 선택</h2>';
    echo '<div class="fld-row" style="margin-bottom:8px">'
       . '<label class="fld">기간<select id="bxMonths"><option value="6">6개월</option><option value="12">1년</option></select></label>'
       . '<label class="fld">축<select id="bxTf"><option value="day">일봉</option><option value="week">주봉</option></select></label>'
       . '<div class="fld"><span>&nbsp;</span><button type="button" class="btn btn-outline btn-sm" onclick="pfBoxLoad()">후보 불러오기</button></div>'
       . '<div class="fld"><span>&nbsp;</span><span class="muted" id="bxPickCnt" style="font-size:12px;padding:7px 0"></span></div></div>';
    echo '<div class="muted" style="font-size:12px;margin-bottom:8px">종목을 먼저 고른 뒤 후보를 불러오세요. '
       . '지지선을 <b>3~5개</b> 고르면(높은 값이 1차) 그 가격 간격으로 비중·목표·지연을 풀어 미리보기로 보여 줍니다. '
       . '일봉 박스가 너무 촘촘하면 주봉으로 바꿔 보세요.</div>';
    echo '<div id="bxChart" style="height:300px;position:relative;margin-bottom:10px"></div>';
    echo '<div style="display:flex;gap:14px;flex-wrap:wrap">';
    echo '<div id="bxList" style="font-size:13px;flex:1 1 300px"><span class="muted">'
       . ($posLvF ? '현재 확정된 가격표가 아래에 있습니다 — 재조정하려면 후보를 불러와 다시 고르세요.' : '「후보 불러오기」를 누르세요.')
       . '</span></div>';
    echo '<div style="flex:1 1 430px"><div class="fld-row" style="margin-bottom:6px">'
       . '<button type="button" class="btn btn-primary btn-sm" onclick="pfBoxSolve()">선택한 가격으로 비중 풀기</button></div>'
       . '<div id="bxPreview" style="font-size:13px">';
    if ($posLvF) {
        echo pf_box_ladder_table(pf_box_ladder_detail($posLvF));
        echo '<div class="muted" style="font-size:12px">현재 확정본 — 저장하면 그대로 유지됩니다.</div>';
    }
    echo '</div></div></div>';
    // 저장에 실릴 가격들 — 재조정 전에는 현재 확정본이 그대로 실려 「그냥 저장」이 가격표를 지우지 않는다
    echo '<span id="bxHidden">';
    foreach ($posLvF as $lv) echo '<input type="hidden" name="box_prices[]" value="' . (float)$lv['price'] . '">';
    echo '</span>';
    echo '</div>';   // #boxSection

    echo '<button class="btn btn-primary" type="submit">저장</button> ';
    if (!$isNew) {
        echo '<a class="btn btn-outline" href="/stock/index.php?mode=position&id=' . (int)$pos['id'] . '">취소</a>';
    }
    echo '</form></div>';

    // ── 종목 자동완성 + 금액 콤마 + 한도 배수
    echo '<script>const PF_PRINCIPAL=' . json_encode($principal) . ';</script>';
    echo <<<'JS'
<script>
/* 콤마 포맷은 공용(pf_comma_js)의 pfComma 가 맡는다 — 여기는 한도 배수 동기화만 */
document.addEventListener('input', function(e){
  if (e.target.id === 'limitAmt') pfSyncMult();
});

/* ── 투자한도 = 포트폴리오 원금 × 배수 ── */
function pfLimitVal(){
  var el = document.getElementById('limitAmt');
  return el ? (parseInt(el.value.replace(/[^\d]/g, ''), 10) || 0) : 0;
}
function pfSyncMult(){
  var el = document.getElementById('multVal');
  if (!el) return;
  if (PF_PRINCIPAL <= 0) { el.textContent = '원금 미설정'; return; }
  var m = pfLimitVal() / PF_PRINCIPAL;
  el.textContent = '×' + (Math.round(m * 100) / 100).toString();
}
function pfMultSet(m){
  var el = document.getElementById('limitAmt');
  if (!el || PF_PRINCIPAL <= 0) return;
  el.value = Math.round(PF_PRINCIPAL * Math.max(0, m)).toLocaleString();
  pfSyncMult();
}
function pfMult(step){
  if (PF_PRINCIPAL <= 0) return;
  var m = pfLimitVal() / PF_PRINCIPAL;
  pfMultSet(Math.max(0, Math.round((m + step) * 2) / 2));   // 0.5 단위로 맞춤
}
pfSyncMult();

/* ── 박스 사다리 — 후보 불러오기(차트 동반) → 3~5개 선택 → 비중 풀기 → 저장에 실림 ── */
var BX_CANDS = [], BX_DC = null;
function pfBoxToggle(){
  var sec = document.getElementById('boxSection');
  // ★라디오가 있을 때만 토글 — 수정 화면은 hidden 이라 서버가 정한 초기 표시를 유지해야 한다
  var radio = document.querySelector('input[type="radio"][name="buy_mode"][value="box"]');
  if (!sec || !radio) return;
  sec.style.display = radio.checked ? 'block' : 'none';
}
function pfBoxCode(){
  var el = document.getElementById('stkCode') || document.querySelector('input[name="stock_code"]');
  return el ? el.value.trim() : '';
}
function pfBoxLoad(){
  var code = pfBoxCode();
  var list = document.getElementById('bxList');
  if (!/^\d{6}$/.test(code)) { list.innerHTML = '<span class="down">종목을 먼저 고르세요.</span>'; return; }
  var m = document.getElementById('bxMonths').value, tf = document.getElementById('bxTf').value;
  list.innerHTML = '<span class="muted">불러오는 중…</span>';
  fetch('/stock/api.php?module=position&action=boxlv&code=' + code + '&months=' + m + '&tf=' + tf)
    .then(function(r){ return r.json(); })
    .then(function(j){
      if (j.err) { list.innerHTML = '<span class="down">' + j.err + '</span>'; return; }
      BX_CANDS = j.candidates || [];
      if (!BX_CANDS.length) { list.innerHTML = '<span class="muted">이 기간에 박스가 없습니다 — 기간을 늘리거나 주봉으로 바꿔 보세요.</span>'; return; }
      var h = '<table class="pf"><thead><tr><th>박스</th><th class="num">상단 H</th><th class="num">하단 L(지지)</th></tr></thead><tbody>';
      BX_CANDS.forEach(function(b){
        h += '<tr><td>' + b.d + '</td>'
           + '<td class="num"><label><input type="checkbox" class="bx-pick" value="' + b.h + '"> ' + Number(b.h).toLocaleString() + '</label></td>'
           + '<td class="num"><label><input type="checkbox" class="bx-pick" value="' + b.l + '"> <b>' + Number(b.l).toLocaleString() + '</b></label></td></tr>';
      });
      list.innerHTML = h + '</tbody></table>';
      list.querySelectorAll('.bx-pick').forEach(function(cb){ cb.addEventListener('change', pfBoxPickChange); });
      pfBoxPickChange();
      pfBoxChart(code);
    })
    .catch(function(){ list.innerHTML = '<span class="down">조회 실패</span>'; });
}
function pfBoxPicked(){
  return Array.prototype.map.call(document.querySelectorAll('.bx-pick:checked'), function(cb){ return parseFloat(cb.value); });
}
function pfBoxPickChange(){
  var n = pfBoxPicked().length;
  var el = document.getElementById('bxPickCnt');
  if (el) el.textContent = n ? '선택 ' + n + '개' + (n > 5 ? ' — 5개까지만!' : '') : '';
  pfBoxDrawLines();
}
/* 차트 — 일봉 위에 후보선(회색 파선)·선택선(파랑 실선)을 그려 자리를 보고 고른다 */
function pfBoxChart(code){
  if (!window.DailyChart) return;
  DailyChart.load().then(function(){
    if (!BX_DC) {
      BX_DC = DailyChart.create('bxChart', { theme: 'light', height: 300 });
      if (!BX_DC) return;
    }
    DailyChart.fetchDaily(code, 480).then(function(rows){
      if (!rows.length) return;
      BX_DC.setData(rows);
      pfBoxDrawLines();
    }).catch(function(){});
  }).catch(function(){});
}
function pfBoxDrawLines(){
  if (!BX_DC) return;
  var picked = pfBoxPicked();
  var lines = [];
  BX_CANDS.forEach(function(b){
    [b.h, b.l].forEach(function(v){
      var on = picked.indexOf(parseFloat(v)) >= 0;
      lines.push({ price: v, color: on ? '#1d5c93' : '#b9c6d2', width: on ? 2 : 1, style: on ? 'solid' : 'dashed' });
    });
  });
  BX_DC.setPriceLines(lines);
}
function pfBoxSolve(){
  var picks = pfBoxPicked();
  var pv = document.getElementById('bxPreview');
  if (picks.length < 3 || picks.length > 5) { pv.innerHTML = '<span class="down">3~5개를 고르세요 (지금 ' + picks.length + '개)</span>'; return; }
  var fd = new FormData();
  picks.forEach(function(p){ fd.append('prices[]', p); });
  fetch('/stock/api.php?module=position&action=boxsolve', { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(j){
      if (j.err) { pv.innerHTML = '<span class="down">' + j.err + '</span>'; return; }
      /* 열 구성은 서버 확정본 표(pf_box_ladder_table)와 같다 — 열을 바꾸면 둘 다 고칠 것 */
      var h = '<table class="pf"><thead><tr><th>차수</th><th class="num">가격</th>'
            + '<th class="num" title="직전 차수 가격 대비">변동율</th>'
            + '<th class="num">비중</th><th class="num">누적</th>'
            + '<th class="num" title="그 차수까지 계획대로 다 샀을 때의 평균단가">평단</th>'
            + '<th class="num" title="그 차수 가격에서의 평가손익 — 손익분기까지의 거리">손실률</th>'
            + '<th class="num">목표</th>'
            + '<th class="num" title="그 차수 평단 × (1+목표) — 자동매도가가 걸리는 자리(수수료 제외)">탈출가</th>'
            + '<th class="num">지연</th></tr></thead><tbody>';
      var hidden = '';
      var pct = function(v, d){ return (v === null || v === undefined) ? '-' : (v > 0 ? '+' : '') + (v * 100).toFixed(d) + '%'; };
      Object.keys(j.detail).forEach(function(n){
        var v = j.detail[n];
        h += '<tr><td>' + n + '차</td><td class="num">' + Number(v.price).toLocaleString() + '</td>'
           + '<td class="num">' + pct(v.chg, 1) + '</td>'
           + '<td class="num">' + (v.weight * 100).toFixed(1) + '%</td>'
           + '<td class="num">' + (v.cum * 100).toFixed(1) + '%</td>'
           + '<td class="num">' + Math.round(v.avg).toLocaleString() + '</td>'
           + '<td class="num">' + pct(v.be, 1) + '</td>'
           + '<td class="num">' + (v.target_rate * 100).toFixed(0) + '%</td>'
           + '<td class="num">' + Math.round(v.exit).toLocaleString() + '</td>'
           + '<td class="num">' + v.delay_days + '일</td></tr>';
        hidden += '<input type="hidden" name="box_prices[]" value="' + v.price + '">';
      });
      h += '</tbody></table><div class="muted" style="font-size:12px">최종 손익분기 '
         + (j.be[j.be.length - 1] * 100).toFixed(1) + '% · 깊이 ' + (j.depth * 100).toFixed(1) + '%'
         + (j.mono ? '' : ' · <span class="down">⚠ 계단형(내려갈수록 비중 증가) 해가 없는 가격 조합 — 상세표에서 얇은 차수를 확인하세요</span>')
         + ' — <b>저장</b>을 누르면 확정됩니다.</div>';
      pv.innerHTML = h;
      document.getElementById('bxHidden').innerHTML = hidden;
    })
    .catch(function(){ pv.innerHTML = '<span class="down">계산 실패</span>'; });
}
/* 저장 가드 — 박스 방식인데 가격이 3개 미만이면 막는다 (서버도 한 번 더 검증) */
document.querySelector('form[action*="action=save"]').addEventListener('submit', function(e){
  var radio = document.querySelector('input[type="radio"][name="buy_mode"][value="box"]');
  var isBox = radio ? radio.checked
                    : ((document.querySelector('input[name="buy_mode"]') || {}).value === 'box');
  if (!isBox) return;
  var n = document.querySelectorAll('#bxHidden input').length;
  if (n < 3) { e.preventDefault(); alert('박스 사다리: 지지선 3~5개를 고르고 「비중 풀기」까지 눌러 주세요.'); }
});
pfBoxToggle();
</script>
<script src="/style/dailychart.js?v=27"></script>
<style>
.fld-fixed{padding:7px 10px;background:#f2f6fa;border:1px solid #e0e8f0;border-radius:6px;
  font-size:13px;font-weight:700;color:#22303f;min-width:120px}

/* 투자한도 배수 조절 */
.mult-box{display:inline-flex;align-items:stretch;border:1px solid #cfdae4;border-radius:6px;overflow:hidden;background:#fff}
.mult-box button{border:none;background:#f2f6fa;color:#3c4d5e;font-size:15px;font-weight:800;
  width:30px;cursor:pointer;font-family:inherit;line-height:1}
.mult-box button:hover{background:#e2ecf5;color:#12406b}
.mult-box span{min-width:56px;text-align:center;padding:7px 6px;font-size:13px;font-weight:800;
  color:#12406b;cursor:pointer;font-variant-numeric:tabular-nums;user-select:none}
.mult-box span:hover{background:#f7fbff}
</style>
JS;

    pf_stock_picker_css();
    pf_stock_picker_script('stk', true);   // 신규 등록 폼에만 입력칸이 있다 (없으면 스크립트가 그냥 빠진다)

    if (!$isNew) {
        echo '<div class="card"><h2>종목 삭제</h2>';
        echo '<p class="muted" style="font-size:13px">체결 기록까지 함께 삭제됩니다.</p>';
        echo '<form method="post" action="/stock/api.php?module=position&action=delete" '
           . 'onsubmit="return confirm(\'체결 기록까지 모두 삭제합니다. 진행할까요?\')">';
        echo '<input type="hidden" name="id" value="' . (int)$pos['id'] . '">';
        echo '<button class="btn btn-danger" type="submit">삭제</button></form></div>';
    }

    pf_foot();
}

// ══════════════════════════════════════════════════════════════════════
//  §4.3 룰셋 관리
// ══════════════════════════════════════════════════════════════════════
/**
 * 룰셋 조건값 뱃지 — 차수 편집 화면 맨 위.
 *
 * 자동 생성 화면에서 넣는 값들과 <b>같은 것</b>을 보여 준다(단계 수·탈출가·손익분기·곡률·하락률).
 * 값은 pf_rule_conditions() 가 <b>차수에서 역산</b>한 것이라 손으로 고쳐도 늘 맞는다.
 */
function pf_rule_cond_badges(array $c, string $tail = ''): string
{
    if (!$c) return '';

    $pct = fn(?float $v, int $d = 1) => ($v === null) ? '-' : sprintf('%+.' . $d . 'f%%', $v * 100);

    $items = [
        ['단계',        $c['n'] . '단계'],
        ['1차 탈출가',  $pct($c['exit_first'])],
        ['마지막 탈출가', $pct($c['exit_last'])],
        ['최종 손익분기', $pct($c['be_last'], 2)],
        ['곡률',        ($c['be_k'] === null) ? '-' : '≈' . number_format($c['be_k'], 2)],
        ['2차 하락률',  $pct($c['drop_first'])],
        ['차수당',      ($c['drop_step'] === null) ? '-' : $pct($c['drop_step']) . 'p'],
        ['최종 깊이',   $pct($c['depth'])],
        ['비중합',      number_format($c['weight_sum'] * 100, 1) . '%'],
    ];

    $h = '<div class="cond-badges" style="display:flex;flex-wrap:wrap;gap:6px;margin:0 0 12px">';
    foreach ($items as [$k, $v]) {
        /* 비중합이 100% 가 아니면 그 뱃지만 눈에 띄게 — 아래 경고 박스와 짝이다 */
        $off = ($k === '비중합' && abs($c['weight_sum'] - 1.0) > 0.0001);
        $h .= '<span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;'
            . 'padding:4px 9px;border-radius:999px;border:1px solid '
            . ($off ? '#f0c2c2;background:#fdf3f3' : '#dfe7ee;background:#f7fafc') . '">'
            . '<b style="color:#7d8b99;font-weight:600">' . pf_h($k) . '</b>'
            . '<span style="color:' . ($off ? '#c62828' : '#22303f') . ';font-weight:700">' . pf_h($v) . '</span>'
            . '</span>';
    }
    return $h . $tail . '</div>';
}

/**
 * 「이 조건으로 자동 생성」 링크 — 역산한 조건값을 그대로 생성 폼에 실어 보낸다.
 *
 * 이 버튼이 있으면 흐름이 닫힌다: <b>자동 생성 → 저장 → 편집(뱃지) → 이 버튼 → 값이 채워진 폼 →
 * 한두 값만 고쳐 또 새 룰셋</b>. 없으면 기존 룰셋을 손보려 할 때마다 조건값을 손으로 옮겨 적어야 한다.
 *
 * ★ 생성기는 하락률을 「2차 + 차수당 증가폭」 <b>등차</b>로만 만든다. 원본이 등차가 아니었다면
 *   (rs4 는 −9/−12/−16/−20/−25/−30 으로 증가폭이 3~5%p 사이를 오간다) 결과가 조금 달라진다 —
 *   그래서 이 버튼은 「똑같이 재현」이 아니라 「이 조건에서 <b>출발</b>」이다. 화면에 그대로 밝힌다.
 * ★ 생성기로 만든 룰셋이면 왕복이 정확하다(pf_rule_conditions 왕복 테스트로 확인).
 */
function pf_gen_url(array $c, int $fromRid = 0): string
{
    if (!$c) return '';

    $q = [
        'mode' => 'ruleset',
        'gen'  => 1,
        'g_n'  => (int)$c['n'],
        'g_ef' => round($c['exit_first'] * 100, 1),
        'g_el' => round($c['exit_last']  * 100, 1),
        'g_be' => round($c['be_last']    * 100, 2),
        'g_k'  => ($c['be_k']      === null) ? 1.4  : round($c['be_k'], 2),
        'g_d1' => round($c['drop_first'] * 100, 1),
        'g_ds' => ($c['drop_step'] === null) ? -4.2 : round($c['drop_step'] * 100, 1),
    ];
    if ($fromRid > 0) $q['g_from'] = $fromRid;

    return '/stock/index.php?' . http_build_query($q) . '#gen';
}

/*
 * 자동 생성 폼의 입력 파싱(pf_gen_input)·비율 변환(pf_gen_opt)은 <b>lib/calc.php</b> 에 있다 —
 * 미리보기(여기·GET)와 저장(api.php·POST)이 같은 파싱을 써야 하고, api.php 는 이 파일을
 * include 하지 않기 때문이다(여기 두었다가 저장이 undefined function 으로 죽었다).
 */

/**
 * 룰셋의 로직을 <b>한 장의 그림</b>으로 — 인라인 SVG (라이브러리·JS 0).
 *
 * 표만 보면 숫자가 열 개씩 늘어서 있어 "이 룰셋이 어떤 모양인지"가 안 보인다.
 * 위 칸에 세 선을 겹쳐 그리면 사다리의 논리가 그대로 드러난다:
 *   <b>가격</b>(내려가는 사다리) · <b>평균단가</b>(더 완만하게 내려간다) · <b>탈출가</b>(평균단가 위에 붙어 흐른다)
 * ★ <b>손익분기율은 따로 그리지 않는다</b> — 정의상 「가격 ÷ 평균단가 − 1」이므로
 *   가격선과 평균단가선의 <b>수직 간격</b>이 바로 그 값이다. 선을 하나 더 그리면 같은 것을 두 번 말하는 셈이다.
 * 아래 칸은 차수별 <b>비중</b> 막대 — 뒤로 갈수록 커지는지(물타기 형태인지)가 한눈에 보인다.
 *
 * 왜 서버에서 SVG 로 그리나: 값이 이미 계산돼 있고 점이 열 개 미만이라 라이브러리를 부를 이유가 없다.
 * 페이지에 500KB 를 얹지 않고, 화면 캡처·인쇄에도 그대로 나온다.
 */
function pf_gen_chart(array $rows): string
{
    $n = count($rows);
    if ($n < 2) return '';

    // ── 판 크기 (viewBox 로 반응형)
    $W = 940; $L = 46; $R = 152;
    $topA = 16;  $hA = 210;              // 위 칸 — 가격/평균단가/탈출가
    $gap  = 34;
    $hB   = 96;                          // 아래 칸 — 비중 막대
    $topB = $topA + $hA + $gap;
    $H    = $topB + $hB + 26;
    $pw   = $W - $L - $R;

    $x = fn(int $i) => $L + ($pw * ($i - 1) / ($n - 1));      // 1차 → N차

    /* ── 위 칸 스케일 — <b>최초 진입가 = 100</b> 기준 지수.
     *   −73.4% 보다 26.6 이 읽기 쉽다(100 넣었으면 얼마가 되는지가 바로 보인다). */
    $vals = [];
    foreach ($rows as $r) {
        $vals[] = $r['price'] * 100;
        $vals[] = $r['avg']   * 100;
        $vals[] = $r['exit']  * 100;
    }
    $hi = ceil((max($vals) + 4) / 10) * 10;
    $lo = max(0, floor((min($vals) - 4) / 10) * 10);
    $yA = fn(float $v) => $topA + $hA * (($hi - $v) / max(1e-9, $hi - $lo));

    $s  = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" style="width:100%;height:auto;'
        . 'font-family:Pretendard,sans-serif;background:#fff" role="img">';

    // 가로 격자 + 왼쪽 눈금 (20 간격). <b>100</b> 은 최초 진입가라 진하게 긋는다
    for ($v = $lo; $v <= $hi + 1e-9; $v += 20) {
        $yy   = $yA((float)$v);
        $base = (abs($v - 100) < 1e-9);
        $s .= '<line x1="' . $L . '" y1="' . round($yy, 1) . '" x2="' . ($L + $pw)
            . '" y2="' . round($yy, 1) . '" stroke="' . ($base ? '#b9c6d4' : '#eef3f8') . '" stroke-width="1"/>';
        $s .= '<text x="' . ($L - 8) . '" y="' . round($yy + 4, 1)
            . '" text-anchor="end" font-size="11" fill="' . ($base ? '#5f7183' : '#8b9aa8') . '">'
            . (int)$v . '</text>';
    }

    // 세 선
    $series = [
        ['exit',  '탈출가',   '#d32f2f', fn($r) => $r['exit']  * 100],
        ['avg',   '평균단가', '#1e9e74', fn($r) => $r['avg']   * 100],
        ['price', '가격',     '#1565c0', fn($r) => $r['price'] * 100],
    ];
    foreach ($series as [$key, $label, $col, $get]) {
        $pts = [];
        $i = 1;
        foreach ($rows as $r) { $pts[] = round($x($i), 1) . ',' . round($yA($get($r)), 1); $i++; }
        $s .= '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="' . $col
            . '" stroke-width="2.2" stroke-linejoin="round"/>';
        $i = 1;
        foreach ($rows as $r) {
            $s .= '<circle cx="' . round($x($i), 1) . '" cy="' . round($yA($get($r)), 1)
                . '" r="3" fill="#fff" stroke="' . $col . '" stroke-width="2"/>';
            $i++;
        }
        // 오른쪽 끝에 이름 + 마지막 값 (지수와 등락률을 함께 — 표와 같은 꼴로 읽히게)
        $lastV = $get(end($rows));
        $s .= '<text x="' . ($L + $pw + 8) . '" y="' . round($yA($lastV) + 4, 1)
            . '" font-size="11.5" fill="' . $col . '" font-weight="700">' . pf_h($label) . ' '
            . number_format($lastV, 1) . '</text>';
        $s .= '<text x="' . ($L + $pw + 8) . '" y="' . round($yA($lastV) + 17, 1)
            . '" font-size="10.5" fill="#8b9aa8">' . sprintf('%+.1f%%', $lastV - 100) . '</text>';
    }

    /* ★ 손익분기 = 가격선과 평균단가선의 수직 간격. 마지막 차수에서 그 간격을 화살표로 짚어 준다 —
     *   "왜 −35% 인가"를 말이 아니라 그림으로 보여 주는 자리다. */
    $lastR  = end($rows);
    $xEnd   = $x($n);
    $yPrice = $yA($lastR['price'] * 100);
    $yAvg   = $yA($lastR['avg'] * 100);
    $s .= '<line x1="' . round($xEnd - 16, 1) . '" y1="' . round($yAvg, 1) . '" x2="'
        . round($xEnd - 16, 1) . '" y2="' . round($yPrice, 1)
        . '" stroke="#7d8b99" stroke-width="1" stroke-dasharray="3 2"/>';
    $s .= '<text x="' . round($xEnd - 20, 1) . '" y="' . round(($yAvg + $yPrice) / 2 + 4, 1)
        . '" text-anchor="end" font-size="11" fill="#5f7183">손익분기 '
        . number_format($lastR['breakeven'] * 100, 1) . '%</text>';

    // ── 아래 칸: 비중 막대
    $maxW = 0.0;
    foreach ($rows as $r) $maxW = max($maxW, (float)$r['weight']);
    $bw = max(10, min(46, $pw / $n * 0.52));

    $s .= '<text x="' . $L . '" y="' . ($topB - 10) . '" font-size="11.5" fill="#5f7183" font-weight="700">'
        . '차수별 비중</text>';
    $i = 1;
    foreach ($rows as $r) {
        $bh = ($maxW > 0) ? $hB * ((float)$r['weight'] / $maxW) : 0;
        $bx = $x($i) - $bw / 2;
        $s .= '<rect x="' . round($bx, 1) . '" y="' . round($topB + $hB - $bh, 1) . '" width="'
            . round($bw, 1) . '" height="' . round($bh, 1) . '" rx="2" fill="#9dbfe0"/>';
        $s .= '<text x="' . round($x($i), 1) . '" y="' . round($topB + $hB - $bh - 5, 1)
            . '" text-anchor="middle" font-size="10.5" fill="#5f7183">'
            . number_format($r['weight'] * 100, 1) . '</text>';
        $i++;
    }
    // 바닥선 + 차수 라벨
    $s .= '<line x1="' . $L . '" y1="' . ($topB + $hB) . '" x2="' . ($L + $pw) . '" y2="' . ($topB + $hB)
        . '" stroke="#b9c6d4" stroke-width="1"/>';
    for ($i = 1; $i <= $n; $i++) {
        $s .= '<text x="' . round($x($i), 1) . '" y="' . ($topB + $hB + 17)
            . '" text-anchor="middle" font-size="11.5" fill="#5f7183" font-weight="700">' . $i . '차</text>';
    }

    return $s . '</svg>';
}

/**
 * 룰셋 자동 생성 — <b>원하는 결과를 주면 차수표를 만든다</b>.
 *
 * 여기까지는 차수를 손으로 하나씩 넣어야 했다. 그런데 실제로 정하고 싶은 것은 비중·하락률이 아니라
 * 「7단계 · 목표는 최초가 +15% 에서 −10% 까지 · 손익분기는 최대 −35%」 같은 <b>결과</b>다.
 * 비중은 그 결과에서 <b>수식으로 풀린다</b>(pf_weights_from_be 주석 — rs4 의 비중이 정확히 복원된다).
 *
 * ★ 미리보기는 <b>GET</b> 이다 — 값을 바꿔 보는 동안 DB 를 건드리지 않고, 주소를 그대로 남길 수 있다.
 *   저장은 POST 로 <b>새 룰셋</b>을 만든다(쓰는 룰셋을 덮어쓸 길을 아예 두지 않는다).
 * ★ 목표수익률은 입력이 아니라 결과다: 탈출가 ÷ 평균단가 − 1. 그래서 폼에서 받는 것은 <b>탈출가</b>다.
 */
function pf_render_ruleset_gen(Pf $pf, array $g, array $in, array $kr = []): void
{
    echo '<div class="card"><h2>룰셋 자동 생성</h2>';

    /* 어디서 온 값인지 밝힌다 — 「이 조건으로 자동 생성」 으로 들어온 경우.
     * ★ 하락률은 등차로 정규화되므로 <b>재현이 아니라 출발점</b>이라는 것도 함께 적는다. */
    $fromRid = (int)($_REQUEST['g_from'] ?? 0);
    if ($fromRid > 0 && ($src = $pf->ruleSetGet($fromRid))) {
        echo '<p class="sub" style="margin:0 0 10px;font-size:12px">'
           . '「<b>' . pf_h($src['name']) . '</b>」 의 조건을 가져왔습니다. '
           . '값을 고쳐 <b>새 룰셋</b>으로 저장하세요 — 원본은 그대로입니다.<br>'
           . '<span class="muted">단, 하락률은 「2차 + 차수당 증가폭」 <b>등차</b>로 정규화됩니다. '
           . '원본이 등차가 아니었다면 차수표가 조금 달라집니다 — <b>재현이 아니라 출발점</b>입니다.</span></p>';
    }

    echo '<p class="sub muted" style="margin:0 0 12px;font-size:12px">'
       . '<b>단계 수 · 목표(탈출가) · 손익분기</b> 를 주면 비중을 계산해 차수표를 만듭니다. '
       . '비중은 손익분기 곡선에서 <b>수식으로 풀립니다</b>(현재 룰셋의 비중이 정확히 복원되는 것으로 검증했습니다).<br>'
       . '<b>목표수익률은 입력이 아닙니다</b> — 자동매도가 = 평균단가 × (1+목표) 이므로 '
       . '「어느 가격에 팔지」(탈출가)를 정하면 목표수익률은 <b>탈출가 ÷ 평균단가 − 1</b> 로 따라 나옵니다. '
       . '차수가 깊어질수록 평균단가가 내려가므로 목표수익률은 <b>저절로 커집니다</b>.</p>';

    // ── 입력 (GET — 미리보기). JS 가 있으면 이 폼을 그대로 조각 요청에 쓴다
    echo '<form method="get" action="/stock/index.php" id="genForm">';
    echo '<input type="hidden" name="mode" value="ruleset">';
    echo '<input type="hidden" name="gen" value="1">';
    if (!empty($_GET['rid'])) echo '<input type="hidden" name="rid" value="' . (int)$_GET['rid'] . '">';
    // 미리보기를 눌러도 "어디서 온 조건인지"가 남게 한다
    if ($fromRid > 0) echo '<input type="hidden" name="g_from" value="' . $fromRid . '">';

    echo '<div class="fld-row">';
    echo '<label class="fld">단계 수<input type="number" name="g_n" min="2" max="12" style="width:80px" value="'
       . (int)$in['n'] . '"></label>';
    echo '<label class="fld">1차 탈출가 (최초가 대비 %)<input type="number" name="g_ef" step="0.1" style="width:110px" value="'
       . pf_h($in['ef']) . '"></label>';
    echo '<label class="fld">마지막 탈출가 (%)<input type="number" name="g_el" step="0.1" style="width:110px" value="'
       . pf_h($in['el']) . '"></label>';
    echo '<label class="fld">최종 손익분기율 (%)<input type="number" name="g_be" step="0.1" style="width:110px" value="'
       . pf_h($in['be']) . '"></label>';
    echo '</div>';

    /* ★ 곡률은 <b>바를 끌어</b> 맞춘다 — 1차 비중을 좌우하는 손잡이인데 어느 값이 좋은지는
     *   숫자로 짚기 어렵고 「끌어 보면」 보인다. 숫자칸과 바를 양방향으로 묶어 둔다.
     *   전송되는 것은 <b>숫자칸(name=g_k)</b> 하나다 — 바에는 name 을 주지 않는다(두 값이 실리면 안 된다). */
    echo '<div class="fld-row" style="margin-top:8px;align-items:flex-end">';
    echo '<label class="fld" style="flex:0 0 auto">손익분기 곡률'
       . '<input type="number" name="g_k" id="gkNum" step="0.05" min="0.5" max="3" style="width:90px" value="'
       . pf_h($in['k']) . '"></label>';
    echo '<div class="fld" style="flex:1 1 220px;min-width:180px">'
       . '<span class="muted" style="font-size:11px">0.8 &nbsp;←&nbsp; 끌어서 조절 &nbsp;→&nbsp; 2.0</span>'
       . '<input type="range" id="gkRange" min="0.8" max="2" step="0.05" value="' . pf_h($in['k'])
       . '" style="width:100%;margin:6px 0 0"></div>';
    echo '<label class="fld">2차 하락률 (%)<input type="number" name="g_d1" step="0.1" style="width:100px" value="'
       . pf_h($in['d1']) . '"></label>';
    echo '<label class="fld">차수마다 더 깊게 (%p)<input type="number" name="g_ds" step="0.1" style="width:110px" value="'
       . pf_h($in['ds']) . '"></label>';
    echo '<button class="btn btn-primary" type="submit">미리보기</button>';
    echo '</div>';
    echo '<p class="sub muted" style="margin:8px 0 0;font-size:12px">'
       . '<b>곡률</b>은 손익분기가 내려가는 모양입니다 — 1.0 은 등차, 크면 앞이 완만하고 뒤가 급합니다. '
       . '<b>1차 비중을 좌우하는 손잡이</b>가 이것입니다(1.0 쪽으로 낮추면 1차가 커집니다). '
       . '하락률은 3개 입력으로 결정되지 않아(같은 손익분기를 만드는 조합이 여럿) 여기서 따로 받습니다.</p>';
    echo '</form>';

    /* ★★ 계산 결과는 <b>이 상자만</b> 갈아끼운다.
     *
     * 바를 끌 때마다 서버가 다시 계산해 이 조각을 보내 준다(?frag=gen). 화면에서 JS 로 다시 계산하면
     * <b>같은 수식이 두 벌</b>이 되어 미리보기와 저장이 갈릴 수 있다 — 비중은 손익분기에서 푸는
     * 만만치 않은 식이라 특히 위험하다. 그래서 계산은 서버 한 곳에만 둔다.
     * ★ 입력칸은 이 상자 <b>밖</b>에 있다 — 끌고 있는 바를 갈아끼우면 드래그가 끊긴다. */
    echo '<div id="genOut">';
    pf_render_gen_out($g, $in, $kr);
    echo '</div>';

    // JS 가 없으면 위 「미리보기」 버튼이 그대로 동작한다 (조각 갱신은 편의일 뿐)
    echo <<<'JS'
<script>
(function(){
  var form = document.getElementById('genForm');
  var out  = document.getElementById('genOut');
  var num  = document.getElementById('gkNum');
  var bar  = document.getElementById('gkRange');
  if (!form || !out || !num || !bar) return;

  var timer = null, seq = 0;

  function refresh(){
    var q = new URLSearchParams(new FormData(form)).toString();
    var my = ++seq;
    out.style.opacity = 0.5;
    fetch('/stock/index.php?' + q + '&frag=gen', { credentials: 'same-origin' })
      .then(function(r){ return r.text(); })
      .then(function(html){
        if (my !== seq) return;                      // 늦게 온 응답은 버린다 (바를 빠르게 끌면 겹친다)
        if (html.lastIndexOf('<!DOCTYPE', 0) === 0) { location.reload(); return; }  // 세션 끊김
        out.innerHTML = html;
        out.style.opacity = 1;
      })
      .catch(function(){ out.style.opacity = 1; });
  }

  form.addEventListener('input', function(e){
    // 바 ↔ 숫자칸 양방향 동기화 (전송은 숫자칸 하나만)
    if (e.target === bar) num.value = bar.value;
    if (e.target === num) bar.value = num.value;
    clearTimeout(timer);
    timer = setTimeout(refresh, 160);
  });
})();
</script>
JS;

    echo '</div>';   // .card
}

/** 자동 생성의 <b>계산 결과</b>만 그린다 — 조각 요청(?frag=gen)과 첫 렌더가 같은 함수를 쓴다 */
function pf_render_gen_out(array $g, array $in, array $kr = []): void
{
    /* ★ 곡률은 방향이 반대인 두 벽(비중 역전 ↔ 고가주 1주 불가) 사이에 끼어 있다.
     *   손으로 찾게 두면 못 찾으므로 통과 구간을 계산해 알려 준다.
     *   ★ 이 줄은 단계 수·손익분기를 바꾸면 함께 달라지므로 <b>결과 쪽</b>에 둔다. */
    if (($kr['from'] ?? null) !== null) {
        echo '<p class="sub" style="margin:8px 0 0;font-size:12px">★ 지금 입력에서 '
           . '<b>비중 역전 없이 1차에 1주가 들어가는</b> 곡률은 <b>'
           . number_format($kr['from'], 2) . ' ~ ' . number_format($kr['to'], 2) . '</b> 입니다.</p>';
    } elseif ($kr !== []) {
        echo '<p class="sub" style="margin:8px 0 0;font-size:12px">★ 지금 입력에서는 '
           . '<b>어떤 곡률로도</b> 비중 역전과 1주 불가를 함께 피할 수 없습니다 — '
           . '단계 수를 늘리거나 최종 손익분기를 얕게 해 보세요.</p>';
    }

    if (!$g) return;

    if (empty($g['ok'])) {
        echo '<div class="warn" style="margin-top:12px">' . pf_h($g['error']) . '</div>';
        return;
    }

    foreach ($g['warn'] as $wtext) {
        echo '<div class="warn" style="margin-top:10px">★ ' . $wtext . '</div>';
    }

    /* ★ 표보다 <b>그림을 먼저</b> — 숫자 열 개를 읽기 전에 모양이 먼저 들어와야 판단이 된다 */
    echo '<div style="margin-top:14px;border:1px solid #e3eaf0;border-radius:8px;padding:10px 8px 4px">';
    echo pf_gen_chart($g['rows']);
    echo '<p class="sub muted" style="margin:2px 6px 6px;font-size:12px">'
       . '위 칸은 <b>최초 진입가를 100</b> 으로 본 값입니다 — 10만원에 시작했다면 26.6 은 2만 6,600원입니다. '
       . '<b>가격</b>이 사다리대로 내려가고, <b>평균단가</b>는 그보다 완만하게 따라 내려가며, '
       . '<b>탈출가</b>는 평균단가 위에 붙어 흐릅니다. '
       . '두 선(가격·평균단가)의 <b>벌어진 간격이 곧 손익분기율</b>입니다 — '
       . '간격이 벌어질수록 되돌아오기까지 더 큰 반등이 필요합니다.</p>';
    echo '</div>';

    echo '<div class="tbl-scroll" style="margin-top:12px"><table class="pf"><thead><tr>';
    /* 가격·평균단가·탈출가는 <b>최초 진입가 = 100</b> 기준 지수로 적는다(괄호에 등락률).
     * −22.2% 보다 77.8 (−22.2%) 가 읽기 쉽고, 실제 진입가에 곱해 보기도 쉽다. */
    foreach ([['차수','num'], ['비중','num'], ['누적비중','num'], ['하락률','num'],
              ['가격','num'], ['평균단가','num'], ['손익분기','num'], ['탈출가','num'], ['목표수익률','num']]
             as [$h, $c]) {
        echo '<th class="' . $c . '">' . pf_h($h) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($g['rows'] as $r) {
        echo '<tr><td class="num"><b>' . (int)$r['step_no'] . '차</b></td>';
        echo '<td class="num"><b>' . number_format($r['weight'] * 100, 2) . '%</b></td>';
        echo '<td class="num muted">' . number_format($r['cum_w'] * 100, 1) . '%</td>';
        echo '<td class="num">' . number_format($r['drop_rate'] * 100, 1) . '%</td>';
        echo '<td class="num">' . pf_idx($r['price']) . '</td>';
        echo '<td class="num">' . pf_idx($r['avg']) . '</td>';
        echo '<td class="num gap">' . number_format($r['breakeven'] * 100, 2) . '%</td>';
        echo '<td class="num">' . pf_idx($r['exit']) . '</td>';
        echo '<td class="num"><b>' . number_format($r['target'] * 100, 1) . '%</b></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    echo '<p class="sub muted" style="margin:8px 0 0;font-size:12px">'
       . '「가격 · 평균단가 · 탈출가」는 <b>최초 진입가를 100 으로 본 지수</b>이고 괄호가 등락률입니다. '
       . '<b>손익분기</b>는 그 차수 가격이 평균단가보다 얼마나 낮은지, '
       . '<b>목표수익률</b>만 평균단가 대비입니다.</p>';

    // ── 저장 (POST — 새 룰셋으로만)
    echo '<form method="post" action="/stock/api.php?module=ruleset&action=gen" class="fld-row" style="margin-top:12px">';
    foreach (['n' => 'g_n', 'ef' => 'g_ef', 'el' => 'g_el', 'be' => 'g_be',
              'k' => 'g_k', 'd1' => 'g_d1', 'ds' => 'g_ds'] as $key => $field) {
        echo '<input type="hidden" name="' . $field . '" value="' . pf_h($in[$key]) . '">';
    }
    echo '<label class="fld">새 룰셋 이름<input type="text" name="name" style="width:260px" value="'
       . pf_h('자동 ' . (int)$in['n'] . '단계 · 손익분기 ' . $in['be'] . '%') . '" required></label>';
    echo '<button class="btn btn-outline" type="submit">이 값으로 새 룰셋 저장</button>';
    echo '<span class="muted" style="font-size:12px">항상 <b>새 룰셋</b>으로 만듭니다 — 쓰는 룰셋은 덮어쓰지 않습니다.</span>';
    echo '</form>';
    // 카드를 닫는 것은 부르는 쪽(pf_render_ruleset_gen)이다 — 조각 요청에는 카드가 없다
}

function pf_page_ruleset(PDO $pdo, Pf $pf): void
{
    $sets = $pf->ruleSets();
    $sel  = (int)($_GET['rid'] ?? ($sets[0]['id'] ?? 0));
    $rs   = $sel ? $pf->ruleSetGet($sel) : null;

    /* 자동 생성의 「1차에 1주 못 담음」 경고에 쓸 포지션(한도·현재가)만 읽는다.
     *
     * ★ 「변동율」 칸과 「이 룰셋을 쓰는 종목의 실측 변동성」 블록은 <b>걷어냈다</b>(사용자 요청).
     *   룰셋의 성격은 이제 차수에서 역산한 <b>조건값 뱃지</b>가 말해 준다.
     *   계산 함수(pf_vol20 · pf_vol_match)는 lib/calc.php 에 남아 있으니 필요해지면 다시 붙일 수 있다. */
    $pos = $pf->positions();

    /* 자동 생성 경고에 쓸 <b>가장 빡빡한 조합</b>: 한도 대비 1주 값이 가장 비싼 포지션.
     * (한도가 큰 곳과 비싼 종목을 따로 집으면 있지도 않은 조합을 경고한다) */
    $tight = ['limit' => 0.0, 'price' => 0.0, 'need' => 0.0];
    foreach ($pos as $p) {
        $lim = (float)$p['limit_amt'];
        $px  = ($p['last_price'] !== null) ? (float)$p['last_price'] : 0.0;
        if ($lim <= 0 || $px <= 0 || $p['status'] === 'closed') continue;
        $need = $px / $lim;
        if ($need > $tight['need']) $tight = ['limit' => $lim, 'price' => $px, 'need' => $need];
    }

    /* ── 조각 요청: 자동 생성의 <b>계산 결과만</b> 돌려준다 (곡률 바를 끌 때 여기로 온다).
     *
     * 헤더·푸터·다른 카드는 내보내지 않는다 — 화면 JS 가 이 HTML 을 상자에 그대로 넣는다.
     * ★ 첫 렌더와 <b>같은 함수</b>(pf_render_gen_out)를 쓴다. 조각을 따로 만들면 둘이 갈린다. */
    if (!empty($_GET['gen']) && ($_GET['frag'] ?? '') === 'gen') {
        $in  = pf_gen_input($_REQUEST);
        $opt = pf_gen_opt($in, $tight);
        pf_render_gen_out(pf_rule_gen($opt), $in, pf_rule_gen_k_range($opt));
        return;
    }

    pf_head('룰셋 설정', 'setting', 'narrow');
    pf_subtabs('ruleset');
    pf_flash();

    echo '<div class="pf-head"><div><h1>룰셋 설정</h1>';
    echo '<div class="sub">하락률은 <b>직전 차수 대비 단계 하락률</b>입니다 (최초가 대비 누적 아님).</div>';
    echo '</div><div class="act">';
    echo '<form class="inline" method="post" action="/stock/api.php?module=ruleset&action=create">';
    echo '<button class="btn btn-outline" type="submit">＋ 새 룰셋</button></form>';
    echo '<a class="btn btn-outline" href="/stock/index.php?mode=ruleset&gen=1'
       . ($sel ? '&rid=' . $sel : '') . '#gen">⚙ 자동 생성</a>';
    echo '</div></div>';

    echo '<div class="card"><h2>룰셋 목록</h2><div class="tbl-scroll"><table class="pf" id="ruleTbl"><thead><tr>';
    echo '<th class="center" style="width:34px">순서</th>';
    echo '<th>이름</th><th class="num">차수</th><th class="num">비중합</th>';
    echo '<th class="num">사용 종목</th><th>메모</th><th></th></tr></thead><tbody>';
    foreach ($sets as $s) {
        $steps = $pf->ruleSteps((int)$s['id']);
        $sum   = pf_weight_sum($steps);
        $rid   = (int)$s['id'];
        $on    = ($rid === $sel);
        $href  = '/stock/index.php?mode=ruleset&rid=' . $rid;

        echo '<tr class="stp' . ($on ? ' on' : '') . '" data-id="' . $rid . '" data-href="' . pf_h($href) . '">';
        echo '<td class="center">' . pf_drag_handle() . '</td>';
        echo '<td><a href="' . $href . '"><b>' . pf_h($s['name']) . '</b></a></td>';
        echo '<td class="num">' . (int)$s['step_count'] . '</td>';
        echo '<td class="num">' . pf_h(pf_pct0($sum, 1)) . '</td>';
        echo '<td class="num">' . (int)$s['pos_count'] . '</td>';
        echo '<td>' . pf_h($s['memo']) . '</td>';
        echo '<td class="right">';
        /* 복제는 <b>사용중이어도</b> 할 수 있다 — 오히려 쓰는 룰셋을 시험할 때 가장 필요하다.
         * 저장된 값을 그대로 복사하므로 원본은 손대지 않는다. */
        echo '<form class="inline no-sel" method="post" action="/stock/api.php?module=ruleset&action=copy">';
        echo '<input type="hidden" name="id" value="' . $rid . '">';
        echo '<button class="btn btn-outline btn-sm" type="submit" '
           . 'title="차수까지 그대로 복사해 새 룰셋을 만듭니다 (원본은 그대로)">복제</button></form> ';
        if ((int)$s['pos_count'] === 0) {
            echo '<form class="inline no-sel" method="post" action="/stock/api.php?module=ruleset&action=delete" '
               . 'onsubmit="return confirm(\'이 룰셋을 삭제할까요?\')">';
            echo '<input type="hidden" name="id" value="' . $rid . '">';
            echo '<button class="btn btn-danger btn-sm" type="submit">삭제</button></form>';
        } else {
            echo '<span class="muted" style="font-size:12px">사용중</span>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<p class="sub muted" style="margin:9px 0 0;font-size:12px">'
       . '왼쪽 <b>점 아이콘</b>을 잡고 끌어 순서를 바꿀 수 있습니다 (놓는 즉시 저장). '
       . '행을 클릭하면 아래에서 차수를 편집합니다.</p>';
    echo '</div>';

    pf_sort_script('ruleTbl', '/stock/api.php?module=ruleset&action=reorder');

    /* ── 자동 생성 (?gen=1 로 켠다 — 평소 화면은 그대로 둔다) */
    if (!empty($_GET['gen'])) {
        echo '<a id="gen"></a>';
        $in  = pf_gen_input($_REQUEST);
        $opt = pf_gen_opt($in, $tight);
        pf_render_ruleset_gen($pf, pf_rule_gen($opt), $in, pf_rule_gen_k_range($opt));

        /* ★ 자동 생성 중에는 <b>여기서 끝낸다</b> — 아래의 「차수 편집」과 「이 룰셋을 쓰는 종목의
         *   실측 변동성」은 <b>기존</b> 룰셋 이야기라 새로 만드는 화면에 있을 이유가 없다.
         *   (위의 룰셋 목록은 남긴다 — 만든 것이 목록에 어떻게 앉는지 보이는 편이 낫다) */
        pf_foot();
        return;
    }

    if (!$rs) { pf_foot(); return; }

    // ── 룰셋 편집
    $steps = $rs['steps'];
    $sum   = pf_weight_sum($steps);

    echo '<div class="card"><h2>' . pf_h($rs['name']) . ' — 차수 편집</h2>';

    /* ★ 룰셋의 조건값을 뱃지로 맨 위에 — 자동 생성 화면의 입력값과 같은 것들이다.
     *   ★★ <b>메모를 파싱하지 않고 차수에서 역산</b>한다(pf_rule_conditions):
     *      메모는 생성 당시의 기록이라 차수를 손으로 고치면 곧 거짓이 되고,
     *      역산은 사람이 손으로 만든 룰셋에도 그대로 쓸 수 있다. */
    $cond = pf_rule_conditions($rs['steps']);
    /* 뱃지 줄 끝에 「이 조건으로 자동 생성」 — 조건값을 손으로 옮겨 적지 않게 한다 */
    echo pf_rule_cond_badges($cond, $cond
        ? '<a class="btn btn-outline btn-sm" style="margin-left:4px" href="'
          . pf_h(pf_gen_url($cond, (int)$rs['id']))
          . '" title="이 조건값을 자동 생성 폼에 채워 넣습니다 (원본은 그대로)">⚙ 이 조건으로 자동 생성</a>'
        : '');

    /* ★★ 이름·메모와 차수 표를 <b>한 폼 · 한 버튼</b>으로 저장한다.
     *
     * 전에는 폼이 둘이고 저장 버튼도 둘이었다. 브라우저는 누른 버튼이 속한 폼의 값만 보내므로
     * <b>차수를 고친 뒤 「이름/메모 저장」 을 누르면 차수 수정이 조용히 사라졌다</b>
     * (저장 뒤 화면이 DB 값으로 다시 그려져 경고조차 없었다. 게다가 표 오른쪽 금액·손익분기율이
     *  입력하는 즉시 다시 계산돼서 이미 저장된 것처럼 보인다).
     * 나눠 둘 실익이 없어 합쳤다 — 이름만 바꿔도 차수는 화면 값 그대로 다시 저장되니 결과가 같다. */
    echo '<form method="post" action="/stock/api.php?module=ruleset&action=save">';
    echo '<input type="hidden" name="id" value="' . (int)$rs['id'] . '">';
    // 차수 표가 실려 온 요청임을 알리는 표시 — 「행을 다 지웠다」와 「차수를 안 보냈다」를 가른다
    echo '<input type="hidden" name="has_steps" value="1">';
    echo '<div class="fld-row" style="margin-bottom:12px">';
    echo '<label class="fld">이름<input type="text" name="name" style="width:220px" value="' . pf_h($rs['name']) . '" required></label>';
    echo '<label class="fld">메모<input type="text" name="memo" style="width:420px" value="' . pf_h($rs['memo']) . '"></label>';
    echo '</div>';

    if (abs($sum - 1.0) > 0.0001) {
        echo '<div class="warn">비중 합계가 <b>' . pf_h(pf_pct0($sum, 2)) . '</b> 입니다 (100% '
           . ($sum > 1 ? '초과' : '미달') . '). 경고만 표시하며 강제하지 않습니다.</div>';
    }

    /* 차수 추가·삭제는 뺐다 — 단계 수는 자동 생성에서 정한다(손으로 늘리고 줄일 일이 없다).
     * 그래서 삭제 버튼 열도 없다. */
    echo '<div class="tbl-scroll"><table class="pf" id="stepTbl"><thead><tr>';
    echo '<th class="num">차수</th><th class="num">비중(%)</th><th class="num">누적비중</th>';
    echo '<th class="num">단계하락률(%)</th><th class="num">가격</th>';
    echo '<th class="num" title="직전 매수 후 이 달력일을 넘겨 이 차수가 「홀로」 오면 건너뛰고 다음 차수에서 삽니다'
       . ' (0=규칙 없음 · 같은 날 두 차수 이상 급락은 그대로 매수 · 건너뛴 금액은 다음 매수가 흡수).'
       . ' 느린 하락=추세라는 가정의 매수 속도 조절 — 20종목 실측은 20일 부근이 중립, 종목별 편차 큼(시뮬레이터 비교표 참조)">지연(일)</th>';
    echo '<th class="num">목표수익률(%)</th><th class="num">금액</th><th class="num">누적금액</th>';
    echo '<th class="num">손익분기율</th></tr></thead><tbody>';

    $sim  = $steps ? pf_simulate($steps, PF_RULE_PREVIEW_LIMIT) : [];
    $cumW = 0.0;
    foreach ($steps as $n => $s) {
        $cumW += (float)$s['weight'];
        $pfac  = $sim[$n]['price_factor'] ?? null;

        echo '<tr data-step="' . $n . '">';
        echo '<td class="num"><b>' . $n . '차</b><input type="hidden" name="step_no[]" value="' . $n . '"></td>';
        echo '<td class="num"><input type="number" class="w-weight" name="weight[]" step="0.0001" style="width:90px" value="'
           . pf_h(round($s['weight'] * 100, 4)) . '"></td>';
        echo '<td class="num c-cumw muted">' . number_format($cumW * 100, 2) . '%</td>';
        echo '<td class="num"><input type="number" class="w-drop" name="drop_rate[]" step="0.0001" style="width:90px" value="'
           . pf_h(round($s['drop_rate'] * 100, 4)) . '"' . ($n === 1 ? ' readonly title="1차는 기준점이라 하락률 없음"' : '') . '></td>';
        // 가격 — 최초 진입가 = 100 기준 지수 (자동 생성 화면·그래프와 같은 꼴)
        echo '<td class="num c-cumd">' . ($pfac === null ? '<span class="flat">-</span>' : pf_idx($pfac)) . '</td>';
        // 지연(일) — 1차는 직전 매수가 없어 규칙이 성립하지 않는다 (readonly 라도 값은 전송되므로 저장쪽에서 0 강제)
        echo '<td class="num"><input type="number" class="w-delay" name="delay_days[]" min="0" max="365" step="5" style="width:70px" value="'
           . (int)($s['delay_days'] ?? 0) . '"'
           . ($n === 1 ? ' readonly title="1차는 직전 매수가 없어 지연 규칙이 없습니다"' : '') . '></td>';
        echo '<td class="num"><input type="number" class="w-target" name="target_rate[]" step="0.0001" style="width:90px" value="'
           . pf_h(round($s['target_rate'] * 100, 4)) . '"></td>';
        echo '<td class="num c-amt">' . pf_mil($sim[$n]['amount'] ?? null) . '</td>';
        echo '<td class="num c-cum">' . pf_mil($sim[$n]['cum_amount'] ?? null) . '</td>';
        echo '<td class="num c-be">' . (isset($sim[$n]) ? pf_signed_pct($sim[$n]['breakeven_rate'], 2) : '-') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';

    echo '<div class="fld-row" style="margin-top:11px">';
    echo '<button class="btn btn-primary" type="submit">저장</button>';
    echo '<span class="muted" style="font-size:12px">이 <b>저장</b> 하나가 위의 이름·메모와 차수를 '
       . '<b>함께</b> 저장합니다. <b>가격·금액·누적금액·손익분기율</b>은 미리보기라 저장되지 않습니다 '
       . '(금액은 한도 ' . number_format(PF_RULE_PREVIEW_LIMIT / 100000000, 0) . '억 기준 백만원 단위).</span>';
    echo '</div></form>';
    echo '</div>';   // .card

    /* 차수 추가·삭제를 없앴으므로 pfAddRow/pfDelRow/pfRenumber 도 함께 지웠다 —
     * 기능을 빼면 그 기능을 위한 코드도 남기지 않는다. 입력 즉시 재계산(pfSim)만 남는다. */
    $previewLimit = (int)PF_RULE_PREVIEW_LIMIT;
    echo <<<JS
<script>
/* 요건정의서 §2.6 — 단계하락률 누적 곱으로 손익분기율 즉시 재계산.
   한도 입력칸을 없앴으므로 미리보기 기준은 서버가 정한 상수 하나다. */
var PF_RULE_LIMIT = {$previewLimit};
function pfSim(){
  var limit = PF_RULE_LIMIT;
  var rows  = Array.prototype.slice.call(document.querySelectorAll('#stepTbl tbody tr'));
  var pf = 1.0, totQty = 0, totAmt = 0, cumW = 0, first = true;

  rows.forEach(function(tr){
    var w = (parseFloat(tr.querySelector('.w-weight').value) || 0) / 100;
    var d = (parseFloat(tr.querySelector('.w-drop').value)   || 0) / 100;
    if (!first) pf *= (1 + d);
    cumW += w;

    var amt = limit * w;
    totAmt += amt;
    if (pf > 0) totQty += amt / pf;
    var avg = totQty > 0 ? totAmt / totQty : null;
    var be  = avg > 0 ? (pf / avg - 1) : null;

    // 누적비중 · 가격(최초 진입가 = 100 기준 지수 + 등락률) — 서버 렌더와 같은 꼴로 맞춘다
    tr.querySelector('.c-cumw').textContent = (cumW * 100).toFixed(2) + '%';
    tr.querySelector('.c-cumd').innerHTML =
      '<b>' + (pf * 100).toFixed(1) + '</b>' +
      ' <span class="muted" style="font-size:11px">(' +
      ((pf - 1) >= 0 ? '+' : '') + ((pf - 1) * 100).toFixed(1) + '%)</span>';
    first = false;

    tr.querySelector('.c-amt').textContent = (amt/1e6).toFixed(1);
    tr.querySelector('.c-cum').textContent = (totAmt/1e6).toFixed(1);
    var el = tr.querySelector('.c-be');
    if (be === null) { el.textContent = '-'; el.className = 'num c-be'; }
    else {
      el.innerHTML = '<span class="'+(be>0?'up':(be<0?'down':'flat'))+'">'+
                     (be>0?'▲ +':(be<0?'▼ ':''))+(be*100).toFixed(2)+'%</span>';
    }
  });
}
document.addEventListener('input', function(e){
  if (e.target.matches('.w-weight,.w-drop,.w-target')) pfSim();
});
pfSim();
</script>
JS;

    pf_foot();
}

// ══════════════════════════════════════════════════════════════════════
//  백테스트 시뮬레이터 — 네이버 일봉 + 룰셋 → 성과 분석
//
//  매매 판단은 화면과 똑같이 pf_position_calc() 에 맡긴다 (stock/lib/sim.php).
//  종가만 있는 데이터라 체결가는 항상 그 날 종가로 가정한다.
// ══════════════════════════════════════════════════════════════════════

/**
 * 투자한도 빠른 선택 버튼 [금액, 이름]. 자릿수를 세어 타이핑하는 수고를 줄이려는 것뿐이다.
 *
 * ★ const 로 두면 안 된다 — 라우터가 이 파일 첫머리에서 페이지 함수를 부르는데,
 *   함수 선언과 달리 const 문은 순차 실행이라 그 시점엔 아직 정의되지 않는다.
 */
function pf_sim_limits(): array
{
    return [
        [  10000000, '1천만'], [  30000000, '3천만'], [  50000000, '5천만'],
        [ 100000000, '1억'],   [ 200000000, '2억'],   [ 300000000, '3억'],
        [ 500000000, '5억'],   [1000000000, '10억'],
    ];
}

/**
 * 시뮬레이터 — <b>전 종목 사이클 분석</b>: 성공한 경우와 물린 경우를 가른다.
 *
 * 2026-07-30 일회성 분석(20종목 226사이클)을 상시 화면으로 올린 것. 그때 확정된 사실:
 *   · 이 엔진에서 닫힌 사이클은 <b>정의상 전부 이익</b>(자동매도가 도달로만 닫힌다)
 *     → 성공/실패의 진짜 경계는 수익률이 아니라 <b>닫혔나 vs 물렸나</b>다.
 *   · 물림비율은 차수 계단을 따라 오른다(1차 0% → 5차 23% → 7차 50%)
 *   · 물림을 가르는 것은 진입 타이밍이 아니라 <b>그 뒤 종목이 어디로 갔나</b> (종목 선택)
 *
 * ★ 조건(룰셋·한도)은 <b>전 종목 통일</b>이다 — 종목마다 저장된 값을 쓰면 한도가 1천만~2억으로
 *   제각각이라 「잠긴 돈」 비교가 무의미해진다. 통일값은 화면에서 고른다.
 * ★ 매번 다시 계산한다(캐시 없음) — 종목 수 × 10년 봉이라 수십 초 걸릴 수 있어 화면에 밝힌다.
 */
function pf_page_simcy(PDO $pdo, Pf $pf): void
{
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');

    $rules = $pf->ruleSets();
    $rs    = (int)($_GET['rs'] ?? ($rules[0]['id'] ?? 0));
    $limit = (float)str_replace(',', '', (string)($_GET['limit'] ?? 30000000));
    if ($limit <= 0) $limit = 30000000;

    $steps = $rs ? $pf->ruleSteps($rs) : [];
    $OPT   = ['limit_amt' => $limit, 'reenter' => true, 'wait' => PF_SIM_WAIT_DEFAULT, 'intraday' => true];

    pf_head('사이클 분석', 'sim', 'wide');
    pf_flash();

    echo '<div class="pf-head"><div><h1>사이클 분석 — 성공과 물림</h1>';
    echo '<div class="sub">등록된 <b>전 종목</b>을 같은 조건으로 돌려, 닫힌 사이클과 물린 사이클을 가릅니다. '
       . '이 엔진에서 <b>닫힌 사이클은 정의상 전부 이익</b>이므로(자동매도가 도달로만 닫힙니다) '
       . '성공/실패의 경계는 수익률이 아니라 <b>닫혔나 vs 물렸나</b>입니다.</div></div>';
    echo '<div class="act"><a class="btn btn-outline" href="/stock/index.php?mode=sim">← 시뮬레이터</a></div>';
    echo '</div>';

    // ── 조건 (전 종목 통일)
    echo '<form class="filter-bar" method="get" action="/stock/index.php">';
    echo '<input type="hidden" name="mode" value="simcy">';
    echo '<label class="fld">룰셋<select name="rs">';
    foreach ($rules as $r) {
        echo '<option value="' . (int)$r['id'] . '"' . ((int)$r['id'] === $rs ? ' selected' : '') . '>'
           . pf_h($r['name']) . '</option>';
    }
    echo '</select></label>';
    echo '<label class="fld">한도(원)<input type="text" class="num-comma" inputmode="numeric" name="limit" style="width:150px" value="'
       . pf_h((int)$limit) . '"></label>';
    echo '<button class="btn btn-primary" type="submit">다시 계산</button>';
    echo '<span class="muted" style="font-size:12px">조건은 <b>전 종목에 같게</b> 적용됩니다 — '
       . '종목마다 저장된 한도를 쓰면 「잠긴 돈」 비교가 무의미해집니다. '
       . '재진입 O · 대기 ' . PF_SIM_WAIT_DEFAULT . '거래일 · 장중체결 O 고정. 종목이 많으면 수십 초 걸립니다.</span>';
    echo '</form>';

    if (!$steps) {
        echo '<div class="warn">룰셋에 차수가 없습니다 — <a href="/stock/index.php?mode=ruleset">룰셋 설정</a></div>';
        pf_foot();
        return;
    }

    // ── 전 종목 실행
    $all   = [];     // 사이클 전부 (닫힘 + 물림)
    $stock = [];     // 종목 요약
    $skip  = [];
    foreach ($pf->simDataList() as $row0) {
        $r = $pf->simDataGet((int)$row0['id']);
        if (!$r) continue;
        /* 정지일·결측 제거 — sim 저장분은 o/h/l 이 없는 옛 형식(has_ohlc=0)이 있어
         * pf_bar_valid(o>0 요구)로 거르면 전멸한다. 종가 0 만 거른다. */
        $rows = array_values(array_filter($r['rows'], fn($x) => (float)$x['c'] > 0));
        if (count($rows) < 300) { $skip[] = (string)$r['stock_name']; continue; }

        $s = pf_sim_run($rows, $steps, $OPT);
        if (empty($s['ok'])) { $skip[] = (string)$r['stock_name']; continue; }

        $name = (string)$r['stock_name'];
        foreach (pf_sim_all_cycles($s) as $cy) {
            // 진입 시점의 시장 상태 — 그 날까지의 봉으로 (사후판단 아님)
            $all[] = [
                'name' => $name, 'sim_id' => (int)$row0['id'],
                'open' => !empty($cy['open']),
                'ret'  => (float)($cy['return'] ?? 0), 'days' => (int)($cy['days'] ?? 0),
                'step' => (int)($cy['max_step'] ?? 0), 'inv' => (float)($cy['invested'] ?? 0),
                'entry' => (string)$cy['entry_date'],
                'st'   => array_column(pf_state_at($rows, (string)$cy['entry_date'], 3), 'key'),
            ];
        }
        $stock[$name] = [
            'sim_id' => (int)$row0['id'], 'cyc' => (int)$s['cycle_count'],
            'rate' => (float)$s['total_rate'], 'bh' => (float)$s['bh_rate'],
            'mdd' => (float)$s['mdd'], 'held' => (int)$s['held_qty'],
        ];
    }

    $closed = array_values(array_filter($all, fn($c) => !$c['open']));
    $openC  = array_values(array_filter($all, fn($c) => $c['open']));
    $locked = array_sum(array_column($openC, 'inv'));

    // ── 요약 카드
    echo '<div class="sum-grid">';
    foreach ([
        ['사이클', number_format(count($all)), '', count($closed) . ' 닫힘 · ' . count($openC) . ' 물림'],
        ['닫힌 사이클 수익', count($closed) ? pf_pct(pf_median(array_column($closed, 'ret'))) : '-', 'up', '중앙값'],
        ['닫히기까지', count($closed) ? number_format(pf_median(array_column($closed, 'days'))) . '일' : '-', '', '중앙값'],
        ['물린 사이클 평가', $openC ? pf_pct(pf_median(array_column($openC, 'ret'))) : '-', 'down', '중앙값 · 미청산'],
        ['물린 지', $openC ? number_format(pf_median(array_column($openC, 'days'))) . '일' : '-', '', '중앙값'],
        ['잠긴 돈', pf_n(round($locked)), $locked > 0 ? 'down' : '',
         '한도 합계 ' . pf_n($limit * count($stock)) . ' 중'],
    ] as [$k, $v, $cls, $sub]) {
        echo '<div class="sum-box"><div class="k">' . pf_h($k) . '</div>';
        echo '<div class="v ' . $cls . '">' . pf_h($v) . '</div>';
        if ($sub !== '') echo '<div class="s">' . pf_h($sub) . '</div>';
        echo '</div>';
    }
    echo '</div>';

    // ── ① 차수별 — 위험의 계단
    echo '<div class="card"><h2>차수별 — 깊이 내려간 사이클일수록 못 돌아온다</h2>';
    echo '<div class="tbl-scroll"><table class="pf"><thead><tr>';
    foreach ([['최고차수','num'], ['닫힘','num'], ['물림','num'], ['물림비율','num'],
              ['수익 중앙','num'], ['닫히기까지 중앙','num'], ['투입 중앙','num']] as [$h, $c]) {
        echo '<th class="' . $c . '">' . pf_h($h) . '</th>';
    }
    echo '</tr></thead><tbody>';
    $maxStep = 0;
    foreach ($steps as $n => $x) $maxStep = max($maxStep, (int)$n);
    for ($n = 1; $n <= $maxStep; $n++) {
        $c = array_values(array_filter($closed, fn($x) => $x['step'] === $n));
        $o = array_values(array_filter($openC,  fn($x) => $x['step'] === $n));
        $tot = count($c) + count($o);
        if (!$tot) continue;
        $ratio = count($o) / $tot;
        echo '<tr>';
        echo '<td class="num"><b>' . $n . '차</b></td>';
        echo '<td class="num">' . count($c) . '</td>';
        echo '<td class="num">' . (count($o) ?: '<span class="flat">-</span>') . '</td>';
        // 물림비율이 20% 를 넘으면 붉게 — 「더 담는 규칙」이 아니라 「탈출이 어려워진 신호」인 구간
        echo '<td class="num' . ($ratio >= 0.2 ? ' down' : '') . '"><b>' . number_format($ratio * 100) . '%</b></td>';
        echo '<td class="num">' . ($c ? pf_h(pf_pct(pf_median(array_column($c, 'ret')))) : '-') . '</td>';
        echo '<td class="num">' . ($c ? number_format(pf_median(array_column($c, 'days'))) . '일' : '-') . '</td>';
        echo '<td class="num muted">' . pf_n(round(pf_median(array_merge(
            array_column($c, 'inv'), array_column($o, 'inv'))) ?? 0)) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    echo '<p class="sub muted" style="margin:9px 0 0;font-size:12px">물림비율이 <b class="down">붉은</b> 구간부터는 '
       . '「더 담는 규칙」이 아니라 <b>탈출이 어려워졌다는 신호</b>로 읽어야 합니다. '
       . '닫히더라도 깊은 차수는 몇 년이 걸립니다.</p>';
    echo '</div>';

    // ── ② 물린 사이클 명단
    if ($openC) {
        usort($openC, fn($a, $b) => $a['ret'] <=> $b['ret']);
        echo '<div class="card"><h2>물린 사이클 ' . count($openC) . '건 — 어디에 얼마가 잠겼나</h2>';
        echo '<div class="tbl-scroll"><table class="pf"><thead><tr>';
        foreach ([['종목',''], ['진입일',''], ['경과','num'], ['최고차수','num'],
                  ['평가수익','num'], ['잠긴 돈','num'], ['진입일 시장','']] as [$h, $c]) {
            echo '<th class="' . $c . '">' . pf_h($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($openC as $c) {
            /* 1년 미만은 「아직 어림」 — 실패 판정이 이르다. 오래 물린 것과 섞어 읽으면 안 된다 */
            $young = ($c['days'] < 365);
            echo '<tr' . ($young ? ' class="watch"' : '') . '>';
            echo '<td class="stk"><a href="/stock/index.php?mode=sim&id=' . $c['sim_id'] . '">'
               . pf_h($c['name']) . '</a></td>';
            echo '<td>' . pf_h($c['entry']) . ($young ? ' <span class="badge st-watch">아직 어림</span>' : '') . '</td>';
            echo '<td class="num">' . number_format($c['days']) . '일</td>';
            echo '<td class="num">' . $c['step'] . '차</td>';
            echo '<td class="num down">' . pf_h(pf_pct($c['ret'])) . '</td>';
            echo '<td class="num">' . pf_n(round($c['inv'])) . '</td>';
            echo '<td>' . ($c['st'] ? pf_h(implode(' · ', array_map('pf_mkt_short', $c['st'])))
                                     : '<span class="flat">-</span>') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="sub muted" style="margin:9px 0 0;font-size:12px">'
           . '<b>아직 어림</b>(1년 미만)은 실패 판정이 이릅니다 — 닫힌 사이클도 깊은 차수는 1년 넘게 걸렸습니다. '
           . '평가수익은 마지막 봉 종가 기준입니다.</p>';
        echo '</div>';
    }

    // ── ③ 종목별 — 사다리가 이긴 곳과 진 곳
    if ($stock) {
        uasort($stock, fn($a, $b) => ($b['rate'] - $b['bh']) <=> ($a['rate'] - $a['bh']));
        echo '<div class="card"><h2>종목별 — 사다리가 이긴 곳과 진 곳</h2>';
        echo '<div class="tbl-scroll"><table class="pf"><thead><tr>';
        foreach ([['종목',''], ['사이클','num'], ['사다리','num'], ['단순보유','num'],
                  ['차이','num'], ['MDD','num'], ['끝상태','']] as [$h, $c]) {
            echo '<th class="' . $c . '">' . pf_h($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($stock as $name => $s) {
            $diff = $s['rate'] - $s['bh'];
            echo '<tr>';
            echo '<td class="stk"><a href="/stock/index.php?mode=sim&id=' . $s['sim_id'] . '">'
               . pf_h($name) . '</a></td>';
            echo '<td class="num">' . $s['cyc'] . '</td>';
            echo '<td class="num">' . pf_h(pf_pct($s['rate'], 1)) . '</td>';
            echo '<td class="num muted">' . pf_h(pf_pct($s['bh'], 1)) . '</td>';
            echo '<td class="num">' . pf_signed_pct($diff, 1) . '</td>';
            echo '<td class="num muted">' . pf_h(pf_pct($s['mdd'], 1)) . '</td>';
            echo '<td>' . ($s['held'] > 0 ? '<span class="badge st-closed">물려있음</span>'
                                          : '<span class="badge st-open">청산</span>') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="sub muted" style="margin:9px 0 0;font-size:12px">'
           . '<b>차이 = 사다리 − 단순보유.</b> 사다리는 <b>오르내리는 횡보·박스권</b>에서 이기고(변동성을 수확), '
           . '<b>반등 없는 추락</b>에서는 함께 지며, <b>강한 상승 추세</b>에서는 1차 몇 %만 담고 일찍 팔아 '
           . '크게 뒤집니다 — 상승 추세용 도구가 아닙니다. '
           . '분할매수는 「떨어져도 반등하는 종목」에서만 작동합니다 — 그 판별이 룰셋 튜닝보다 중요합니다.</p>';
        echo '</div>';
    }

    if ($skip) {
        echo '<p class="sub muted" style="font-size:12px">시세가 짧아 뺀 종목: ' . pf_h(implode(', ', $skip)) . '</p>';
    }

    pf_foot();
}

function pf_page_sim(PDO $pdo, Pf $pf): void
{
    $list    = $pf->simDataList();
    $rules   = $pf->ruleSets();
    $brokers = $pf->brokers();
    $markets = $pf->markets();

    /*
     * 대상 시세 (종목코드가 키라 종목당 1건뿐이다).
     * id 가 없으면 아무것도 열지 않는다 — 종목이 100개일 때 첫 종목을 자동으로 열면
     * 목록만 보려는 사람도 매번 백테스트 한 판(+룰셋 비교)을 기다리게 된다.
     */
    $id   = (int)($_GET['id'] ?? 0);
    $data = $id ? $pf->simDataGet($id) : null;

    pf_head('시뮬레이터', 'sim', 'wide');
    pf_flash();

    echo '<div class="pf-head"><div><h1>백테스트 시뮬레이터</h1>';
    echo '<div class="sub">분할매수 규칙대로 매매했다면 어땠을지 종목별로 계산합니다. '
       . '판단 로직은 종목 상세 화면과 <b>같은 계산엔진</b>을 씁니다. '
       . '왼쪽 목록은 <b>마지막으로 돌려 저장한 결과</b>이고, 종목을 클릭해 돌리면 그 종목만 갱신됩니다.</div></div>';
    echo '<div class="act"><a class="btn btn-outline" href="/stock/index.php?mode=simcy" '
       . 'title="등록된 전 종목을 같은 조건으로 돌려 성공한 사이클과 물린 사이클을 가릅니다">📊 사이클 분석</a></div>';
    echo '</div>';

    if (!$rules) {
        echo '<div class="warn">룰셋이 없습니다. <a href="/stock/index.php?mode=ruleset">룰셋 설정</a>에서 먼저 만드세요.</div>';
    }

    echo '<div class="split sim-split">';
    echo '<div class="split-left">';

    // ── 종목리스트: 종목 · 총수익률 · 사이클 (등록 버튼도 이 카드에 둔다)
    echo '<div class="card sim-left-card">';
    echo '<div class="sim-head"><h2 style="margin:0">종목리스트 '
       . '<span class="muted" style="font-weight:600;font-size:12px">' . count($list) . '종목</span></h2>';
    echo '<button type="button" class="btn btn-primary btn-sm" onclick="pfOpenSim()">＋ 자료 등록</button>';
    echo '</div>';

    if (!$list) {
        echo '<p class="muted" style="font-size:13px;margin:0">아직 등록한 종목이 없습니다. '
           . '위 <b>＋ 자료 등록</b>에서 종목을 고르면 네이버에서 일봉을 받아옵니다.</p>';
    } else {
        echo '<div class="tbl-scroll sim-list"><table class="pf"><thead><tr>';
        echo '<th>종목</th><th class="num">총수익률</th><th class="num">사이클</th></tr></thead><tbody>';

        /*
         * ★ 목록에서는 어떤 계산도 하지 않는다.
         *   종목 하나당 4천 봉 × 150ms 라 100종목이면 15초, rows_json 만 12MB 를 읽게 된다.
         *   그래서 "그 종목을 마지막으로 돌렸을 때의 결과"를 저장해 뒀다가 그대로 읽어 온다.
         *   갱신은 종목을 열어 돌릴 때만 일어난다 (그 종목 한 건만).
         *   조건·룰셋이 그 사이 바뀌었는지는 지문 비교로 표시만 하고, 다시 돌리지는 않는다.
         */
        $stepCache = [];
        $costCache = [];
        $stale     = 0;

        foreach ($list as $d) {
            $on  = ((int)$d['id'] === $id);
            $o   = pf_sim_options($d, $rules, $brokers, $markets);
            $rn  = '';
            foreach ($rules as $r) if ((int)$r['id'] === $o['rs']) $rn = $r['name'];

            $sum = ($d['cache_json'] !== '') ? json_decode((string)$d['cache_json'], true) : null;

            // 저장된 결과가 지금 조건과 같은 조건에서 나온 것인지 (계산 없이 해시 비교만)
            $fresh = false;
            if (is_array($sum)) {
                $ck = "{$o['b']}|{$o['mk']}";
                if (!isset($stepCache[$o['rs']])) $stepCache[$o['rs']] = $pf->ruleSteps($o['rs']);
                if (!isset($costCache[$ck]))      $costCache[$ck]      = $pf->simCostParams($o['b'], $o['mk']);
                $fresh = ($d['cache_key'] === pf_sim_fingerprint($o, $stepCache[$o['rs']], $costCache[$ck], $d));
                if (!$fresh) $stale++;
            }

            // 좁은 칸이라 3열만 두고 나머지는 아랫줄 보조정보로 접어 넣는다 (가로 스크롤 방지)
            $href = '/stock/index.php?mode=sim&id=' . (int)$d['id'];
            echo '<tr class="stp' . ($on ? ' on' : '') . '" data-href="' . pf_h($href) . '" title="클릭해서 열기">';

            echo '<td><a href="' . $href . '"><b>' . pf_h($d['stock_name'] ?: $d['stock_code']) . '</b></a>'
               . ' <span class="muted" style="font-size:11px">' . pf_h($d['stock_code']) . '</span>'
               . '<div class="note-brief">' . pf_h($rn) . ' · 한도 ' . pf_n($o['limit']) . '</div></td>';

            if (is_array($sum)) {
                echo '<td class="num"><b>' . pf_signed_pct($sum['rate']) . '</b>'
                   . '<div class="note-brief">MDD ' . pf_h(pf_pct($sum['mdd'])) . '</div></td>';

                echo '<td class="num">' . (int)$sum['cyc'] . '회'
                   . '<div class="note-brief">'
                   . ($sum['step'] ? (int)$sum['step'] . '차 · ' : '')
                   . ($d['cached_at'] ? pf_h(substr((string)$d['cached_at'], 5, 5)) : '-')
                   . (!$fresh ? ' <span style="color:#c98a1a">↻</span>' : '')
                   . '</div></td>';
            } else {
                echo '<td class="num muted" colspan="2">아직 안 돌림</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="sub muted" style="margin:9px 0 0;font-size:12px">'
           . '<b>마지막으로 돌려 저장한 결과</b>이며, 옆 숫자는 계산한 날짜(월-일)입니다. '
           . '목록에서는 다시 계산하지 않습니다'
           . ($stale > 0 ? ' — <b>' . $stale . '종목</b>은 저장 이후 조건이 바뀌었습니다(<span style="color:#c98a1a">↻</span>)' : '')
           . '.</p>';
    }
    echo '</div>';

    echo '</div>';   // .split-left
    echo '<div class="split-right sim-right">';

    if (!$data || !$rules) {
        echo '<div class="card sim-empty">'
           . ($list && $rules
                ? '왼쪽에서 <b>종목을 클릭</b>하면 여기에 상세 결과가 나옵니다.<br>'
                  . '<span class="muted" style="font-size:13px">조건·룰셋을 바꿔 돌리면 그 종목만 다시 계산해 저장합니다.</span>'
                : '왼쪽 <b>＋ 자료 등록</b>으로 종목을 먼저 등록하세요.')
           . '</div>';
        echo '</div></div>';   // .split-right / .split
        pf_sim_scripts($rules);
        pf_foot();
        return;
    }

    // ── 조건 (주소창 파라미터 > 저장된 마지막 조건 > 기본값)
    $o = pf_sim_options($data, $rules, $brokers, $markets, $_GET);

    /*
     * 기간이 시세 전 구간을 덮으면 빈 값(=전체)으로 되돌린다.
     * 화면에는 실제 날짜를 채워 보여 주되, 저장은 "전체"로 해 둬야
     * 나중에 시세를 더 받아왔을 때 옛 종료일에 잘리지 않는다.
     */
    if ($o['from'] !== '' && $o['from'] <= (string)$data['from_date']) $o['from'] = '';
    if ($o['to']   !== '' && $o['to']   >= (string)$data['to_date'])   $o['to']   = '';

    $steps = $pf->ruleSteps($o['rs']);
    $rsName = '';
    foreach ($rules as $r) if ((int)$r['id'] === $o['rs']) $rsName = (string)$r['name'];
    $p     = $pf->simCostParams($o['b'], $o['mk']);
    $rows  = pf_sim_slice($data['rows'], $o['from'], $o['to']);

    // 거래량은 나중에 형식을 넓힌 것이라 옛 데이터에는 없다 (조건줄·차트에서 갈린다)
    $hasVol = false;
    foreach ($rows as $r) { if (!empty($r['v'])) { $hasVol = true; break; } }
    // 차수 지연은 룰셋 차수(delay_days)에 실려 온다 — 옵션이 아니라 룰셋의 속성
    $res   = ($rows && $steps) ? pf_sim_run($rows, $steps,
        ['limit_amt' => $o['limit'], 'reenter' => $o['re'], 'wait' => $o['wait'],
         'intraday' => $o['intraday'], 'stair_stop' => $o['ss']], $p) : pf_sim_empty();

    // 조건만 기억해 둔다 (결과는 저장하지 않는다)
    if (isset($_GET['go'])) $pf->simDataSaveOptions($id, $o);

    // 어차피 돌린 김에 목록용 요약을 캐시에 넣어 둔다 (이 종목은 다음 목록에서 즉시 표시)
    $pf->simDataCacheSave($id,
        pf_sim_fingerprint($o, $steps, $p, $data), pf_sim_summary($res));

    $linked = $pf->positionFindByCode((string)$data['stock_code']);

    echo '<div class="card"><div class="sim-head">';
    echo '<h2 style="margin:0">' . pf_h($data['stock_name'] ?: $data['stock_code'])
       . ' <span class="muted" style="font-weight:600;font-size:12px">' . pf_h($data['stock_code']) . '</span>'
       . ' <span class="muted" style="font-weight:600;font-size:12px">· ' . pf_h($data['from_date'])
       . ' ~ ' . pf_h($data['to_date']) . ' · ' . pf_n($data['bar_count']) . '거래일</span></h2>';
    echo '<div style="display:flex;gap:6px">';

    // 네이버에서 같은 종목을 다시 받아 시세만 갱신 (조건·저장결과는 유지)
    echo '<form class="inline" method="post" action="/stock/api.php?module=sim&action=fetch">';
    echo '<input type="hidden" name="stock_code" value="' . pf_h($data['stock_code']) . '">';
    echo '<input type="hidden" name="stock_name" value="' . pf_h($data['stock_name']) . '">';
    echo '<input type="hidden" name="from" value="' . pf_h($data['from_date']) . '">';
    echo '<input type="hidden" name="to" value="' . date('Y-m-d') . '">';
    echo '<button class="btn btn-outline btn-sm" type="submit" title="네이버에서 다시 받아 최근 시세까지 채웁니다">'
       . '↻ 시세 갱신</button></form>';

    echo '<form class="inline" method="post" action="/stock/api.php?module=sim&action=delete" '
       . 'onsubmit="return confirm(\'' . pf_h($data['stock_name']) . ' 시세 데이터를 삭제할까요?\')">';
    echo '<input type="hidden" name="id" value="' . $id . '">';
    echo '<button class="btn btn-danger btn-sm" type="submit">시세 삭제</button></form>';
    echo '</div></div>';

    echo '<form method="get" action="/stock/index.php" class="fld-row">';
    echo '<input type="hidden" name="mode" value="sim">';
    echo '<input type="hidden" name="id" value="' . $id . '">';
    echo '<input type="hidden" name="go" value="1">';

    // 룰셋은 아래 '룰셋 비교표'에서 성적을 보고 고르는 편이 빠르므로 선택창을 두지 않는다.
    // 다만 go=1 폼이라 값을 안 실어 보내면 조건이 흔들린다 — hidden 으로 그대로 넘긴다.
    echo '<input type="hidden" name="rs" value="' . $o['rs'] . '">';

    // 한도는 자릿수를 세어 치기가 번거롭다 — 칸을 누르면 금액 목록 모달이 열린다(직접 입력도 그 안에서)
    // onclick 은 라벨에 건다 — 글자든 칸이든 아무 데나 눌러도 열리고, 칸을 눌러도 한 번만 올라온다
    echo '<label class="fld" onclick="pfOpenLimit()" title="누르면 금액을 고를 수 있습니다">투자한도'
       . '<input type="text" name="limit" id="simLimitBox" class="lim-open" readonly '
       . 'style="width:150px" value="' . pf_h(number_format($o['limit'])) . '"></label>';

    echo '<label class="fld">증권사<select name="b" style="width:130px">';
    foreach ($brokers as $b) {
        echo '<option value="' . (int)$b['id'] . '"' . ((int)$b['id'] === $o['b'] ? ' selected' : '') . '>'
           . pf_h($b['name']) . '</option>';
    }
    echo '</select></label>';

    // 시장은 종목이 정해지면 바뀔 일이 없다 — 화면에서 빼고 값만 넘긴다(세율은 아래 조건줄에서 읽힌다)
    echo '<input type="hidden" name="mk" value="' . pf_h($o['mk']) . '">';

    // 비워 뒀으면(=전체 기간) 시세가 실제로 있는 구간을 채워 보여 준다
    $dFrom = (string)$data['from_date'];
    $dTo   = (string)$data['to_date'];
    echo '<label class="fld">시작일<input type="date" name="from" min="' . pf_h($dFrom) . '" max="' . pf_h($dTo)
       . '" value="' . pf_h($o['from'] !== '' ? $o['from'] : $dFrom) . '"></label>';
    echo '<label class="fld">종료일<input type="date" name="to" min="' . pf_h($dFrom) . '" max="' . pf_h($dTo)
       . '" value="' . pf_h($o['to'] !== '' ? $o['to'] : $dTo) . '"></label>';
    echo '<label class="fld">청산 후 재진입<span style="padding:7px 0"><input type="checkbox" name="re" value="1"'
       . ($o['re'] ? ' checked' : '') . '></span></label>';
    echo '<label class="fld">대기(거래일)<input type="number" name="wait" min="0" max="60" style="width:80px" value="'
       . $o['wait'] . '"></label>';

    // 고가·저가가 있어야 장중 체결을 흉내낼 수 있다 (종가만 있는 옛 데이터는 잠근다)
    $hasOhlc = !empty($data['has_ohlc']);
    echo '<label class="fld" title="'
       . ($hasOhlc ? '저가가 이론가에 닿으면 지정가로 매수, 고가가 자동매도가에 닿으면 매도로 봅니다.'
                   : '이 데이터에는 고가·저가가 없습니다. 네이버에서 다시 받으면 켤 수 있습니다.')
       . '">장중 체결<span style="padding:7px 0"><input type="checkbox" name="intraday" value="1"'
       . ($o['intraday'] ? ' checked' : '') . ($hasOhlc ? '' : ' disabled') . '></span></label>';

    // 계단관통 손절 — 사다리×퀀트 결합 연구(2026-08-01). 거래량이 있어야 최고 거래대금·계단 계산이 된다.
    echo '<label class="fld" title="'
       . ($hasVol
            ? '최고 거래대금 박스의 아래 계단(지지구조)이 전부 종가로 뚫리면 사다리를 중단하고 전량 청산합니다.'
              . ' 원장 백테스트(2026-08-01): 물림 11.3→5.2%로 절반 · 비용 중앙 −0.8%p. 판정·체결 모두 종가.'
            : '이 데이터에는 거래량이 없습니다. ↻ 시세 갱신을 누르면 켤 수 있습니다.')
       . '">계단관통 손절<span style="padding:7px 0"><input type="checkbox" name="ss" value="1"'
       . ($o['ss'] ? ' checked' : '') . ($hasVol ? '' : ' disabled') . '></span></label>';

    echo '<button class="btn btn-primary" type="submit">시뮬레이션</button>';
    echo '</form>';

    if ($linked) {
        // 룰셋·한도만 실제 설정으로 갈아끼우고 나머지 조건(기간·재진입·장중)은 지금 것을 유지
        $applied = pf_sim_url($id, $o, [
            'rs'    => (int)$linked['rule_set_id'],
            'limit' => (int)$linked['limit_amt'],
        ]);
        echo '<p class="sub" style="margin:11px 0 0;font-size:13px">'
           . '<b>' . pf_h($linked['portfolio_name']) . '</b> 에 실제로 등록된 종목입니다 — '
           . '룰셋 <b>' . pf_h($linked['rule_name']) . '</b> · 한도 <b>' . pf_n($linked['limit_amt']) . '</b> '
           . '<a class="btn btn-outline btn-sm" style="margin-left:6px" href="'
           . pf_h('/stock/index.php?mode=position&id=' . (int)$linked['id']) . '">종목 화면 열기</a> '
           . '<a class="btn btn-outline btn-sm" href="' . pf_h($applied) . '">실제 설정으로 시뮬레이션</a></p>';
    }

    echo '<p class="sub muted" style="margin:9px 0 0;font-size:12px">'
       . pf_n(count($rows)) . '거래일 '
       . ($rows ? '(' . pf_h($rows[0]['d']) . ' ~ ' . pf_h($rows[count($rows) - 1]['d']) . ')' : '')
       // 룰셋 선택창을 없앴으므로 지금 적용 중인 룰셋 이름을 여기서 밝힌다
       . ' · 룰셋 <b>' . pf_h($rsName) . '</b> (비중합 ' . pf_pct0(pf_weight_sum($steps)) . ')'
       . ' · 매수비용 ' . pf_pct0((float)$p['buy_cost_rate'])
       . ' / 매도비용 ' . pf_pct0((float)$p['sell_cost_rate'])
       . ' · 체결 기준 <b>' . ($o['intraday'] ? '장중(고가·저가)' : '종가') . '</b>'
       // 재진입이 꺼져 있으면 첫 청산에서 끝나므로 사이클이 1회로 나온다 — 헷갈리기 쉬워 명시한다
       . ' · 재진입 <b class="' . ($o['re'] ? 'up' : 'down') . '">' . ($o['re'] ? 'ON' : 'OFF') . '</b>'
       . ($o['re']
            ? ($o['wait'] > 0 ? ' (청산 후 ' . $o['wait'] . '거래일 쉬고 재진입)' : '')
            : ' (첫 청산에서 종료 — 사이클이 1회로 끝납니다)')
       . ($o['ss']
            ? ' · 계단관통 손절 <b class="up">ON</b>'
              . (empty($res['stair_used']) ? ' <b class="down">(적용 불가 — 거래량/이력 부족)</b>'
                 : ((int)($res['stair_count'] ?? 0) > 0 ? ' (관통 청산 ' . (int)$res['stair_count'] . '회)' : ''))
            : '')
       . (($rsDlyTxt = implode('·', array_map(
                fn($s) => $s['step_no'] . '차 ' . $s['delay_days'] . '일',
                array_filter($steps, fn($s) => !empty($s['delay_days']))))) !== ''
            ? ' · 차수 지연 <b>' . pf_h($rsDlyTxt) . '</b>'
              . ((int)($res['delay_skips'] ?? 0) > 0 ? ' (스킵 ' . (int)$res['delay_skips'] . '회)' : ' (스킵 없음)')
            : '')
       . (!$hasVol ? ' · <b>거래량 없음</b> — <b>↻ 시세 갱신</b>을 누르면 함께 받아 옵니다' : '')
       . '</p>';
    echo '</div>';

    if (empty($res['ok'])) {
        echo '<div class="warn">시뮬레이션할 데이터가 없습니다. 기간이나 룰셋을 확인하세요.</div>';
        echo '</div></div>';   // .split-right / .split
        pf_sim_scripts($rules);
        pf_foot(); return;
    }

    // ── 성과 요약
    echo '<div class="card"><h2>성과 요약</h2>';
    echo '<div class="sum-grid">';
    foreach ([
        ['원금(투자한도)', pf_n($res['principal']),        '',                         ''],
        ['최종 추정자산',  pf_n(round($res['asset'])),     '',                         '예수금 ' . pf_n(round($res['cash']))],
        ['총 손익',        pf_n(round($res['total_pl'])),  pf_updown($res['total_pl']), ''],
        ['총 수익률',      pf_pct($res['total_rate']),     pf_updown($res['total_rate']), ''],
        ['연환산(CAGR)',   $res['cagr'] === null ? '-' : pf_pct($res['cagr']), pf_updown($res['cagr']),
                           $res['days'] . '일'],
        ['최대 낙폭(MDD)', pf_pct($res['mdd']),            'down',                     '추정자산 기준'],
        ['실현손익',       pf_n(round($res['realized_pl'])), pf_updown($res['realized_pl']),
                           $res['sell_count'] . '회 매도'],
        ['평가손익',       pf_n(round($res['eval_pl'])),   pf_updown($res['eval_pl']),
                           $res['held_qty'] > 0 ? pf_n($res['held_qty']) . '주 보유' : '보유 없음'],
        ['그냥 보유했다면', pf_pct($res['bh_rate']),       pf_updown($res['bh_rate']),
                           '주가 ' . pf_pct($res['price_return'])],
        ['최대 도달 차수', $res['max_step'] . '차',        '',                         ''],
        ['최대 소진액',    pf_n(round($res['max_used'])),  '',                         '한도의 ' . pf_pct0((float)$res['max_used_rate'])],
        ['사이클',         $res['cycle_count'] . '회',     '',
                           $res['win_rate'] === null ? '미청산' : '승률 ' . pf_pct0((float)$res['win_rate'])],
    ] as [$k, $v, $cls, $sub]) {
        echo '<div class="sum-box"><div class="k">' . pf_h($k) . '</div>';
        echo '<div class="v ' . $cls . '">' . pf_h($v) . '</div>';
        if ($sub !== '') echo '<div class="s">' . pf_h($sub) . '</div>';
        echo '</div>';
    }
    echo '</div>';

    $vs = $res['total_rate'] - $res['bh_rate'];
    echo '<p class="sub muted" style="margin:2px 0 0;font-size:12px">'
       . '같은 원금으로 시작일에 전액 매수해 끝까지 들고 있었다면 ' . pf_n(round($res['bh_asset'])) . '원'
       . ' → 분할매수가 <b class="' . pf_updown($vs) . '">' . pf_h(pf_pct($vs)) . '</b> 우위입니다. '
       . '매수 ' . $res['buy_count'] . '회 / 매도 ' . $res['sell_count'] . '회'
       . ($res['avg_days'] !== null ? ' · 평균 보유 ' . round($res['avg_days']) . '일' : '') . '</p>';
    echo '</div>';

    // ── 룰셋 비교 — 같은 종목·같은 조건에서 룰셋만 바꿔 전부 돌린다.
    //    ★제동장치(계단관통·시간손절)도 위 결과와 같게 싣는다 — 안 실으면 요약과 비교표가 다른 말을 한다
    //      (실제로 시간손절 200일을 걸었는데 비교표만 옛 수익률이 나와 사용자가 잡아냈다 · 2026-08-01).
    $best = null;
    $cmp  = [];
    $anyDly = false;
    foreach ($rules as $r) {
        $rid = (int)$r['id'];
        $st  = $pf->ruleSteps($rid);
        if (!$st) continue;
        $cmpOpt = ['limit_amt' => $o['limit'], 'reenter' => $o['re'], 'wait' => $o['wait'],
                   'intraday' => $o['intraday'], 'stair_stop' => $o['ss']];
        $rr  = pf_sim_run($rows, $st, $cmpOpt, $p);
        if (empty($rr['ok'])) continue;
        // 차수 지연은 룰셋의 속성 — 지연이 정의된 룰셋은 「지연을 걷어낸」 값도 함께 돌려 영향(Δ)을 보여 준다
        $hasDly = (bool)array_filter($st, fn($s) => !empty($s['delay_days']));
        $rr0 = $hasDly
            ? pf_sim_run($rows, array_map(fn($s) => ['delay_days' => 0] + $s, $st), $cmpOpt, $p)
            : null;
        if ($hasDly) $anyDly = true;
        $cmp[$rid] = ['name' => $r['name'], 'steps' => count($st), 'r' => $rr, 'r0' => $rr0,
                      'st' => $st, 'dly' => $hasDly];
        if ($best === null || $rr['total_rate'] > $cmp[$best]['r']['total_rate']) $best = $rid;
    }

    echo '<div class="card"><h2>룰셋 비교</h2>';
    echo '<div class="tbl-scroll"><table class="pf"><thead><tr>';
    echo '<th>룰셋</th><th class="num">단계</th><th class="num">총수익률</th>';
    if ($anyDly) echo '<th class="num" title="그 룰셋의 차수 지연만 걷어낸 값과 그 차이">지연 없이</th>';
    echo '<th class="num">CAGR</th>';
    echo '<th class="num">MDD</th><th class="num">사이클</th><th class="num">승률</th>';
    echo '<th class="num">최고차수</th><th class="num">최대소진</th><th></th></tr></thead><tbody>';

    foreach ($cmp as $rid => $c) {
        $rr  = $c['r'];
        $cur = ($rid === $o['rs']);
        // 행을 누르면 그 룰셋의 차수표가 모달로 열린다 (적용 버튼은 눌러도 모달이 안 뜨게 걸러 낸다)
        echo '<tr class="rs-row" data-rid="' . $rid . '" title="클릭하면 차수표를 봅니다"'
           . ($cur ? ' style="background:#eaf3fc"' : '') . '>';
        echo '<td><b>' . pf_h($c['name']) . '</b>'
           . ($rid === $best ? ' <span class="badge st-open">최고</span>' : '')
           . ($cur ? ' <span class="muted" style="font-size:11px">적용중</span>' : '') . '</td>';
        echo '<td class="num">' . $c['steps'] . '</td>';
        echo '<td class="num"' . ($c['dly']
            ? ' title="' . pf_h('차수 지연(룰셋 정의) 적용 · 스킵 ' . (int)($rr['delay_skips'] ?? 0) . '회') . '"'
            : '') . '><b>' . pf_signed_pct($rr['total_rate']) . '</b></td>';
        if ($anyDly) {
            if ($c['dly']) {
                // Δ = 차수 지연의 영향 (적용 − 미적용). 음수면 그 룰셋에선 지연이 수익을 깎는다는 뜻
                $d0 = $rr['total_rate'] - $c['r0']['total_rate'];
                echo '<td class="num">' . pf_signed_pct($c['r0']['total_rate'])
                   . '<br><span class="' . pf_updown($d0) . '" style="font-size:11px">Δ ' . pf_signed_pct($d0) . '</span></td>';
            } else {
                echo '<td class="num muted" title="이 룰셋에는 차수 지연이 없습니다">-</td>';
            }
        }
        echo '<td class="num">' . ($rr['cagr'] === null ? '-' : pf_signed_pct($rr['cagr'])) . '</td>';
        echo '<td class="num"><span class="down">' . pf_h(pf_pct($rr['mdd'])) . '</span></td>';
        echo '<td class="num">' . (int)$rr['cycle_count'] . '</td>';
        echo '<td class="num">' . ($rr['win_rate'] === null ? '-' : pf_pct0((float)$rr['win_rate'])) . '</td>';
        echo '<td class="num">' . (int)$rr['max_step'] . '차</td>';
        echo '<td class="num">' . pf_pct0((float)$rr['max_used_rate']) . '</td>';
        echo '<td class="right">' . ($cur ? '' : '<a class="btn btn-outline btn-sm" href="'
           . pf_h(pf_sim_url($id, $o, ['rs' => $rid])) . '">적용</a>') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    echo '<p class="sub muted" style="margin:9px 0 0;font-size:12px">'
       . '<b>행을 클릭</b>하면 그 룰셋의 차수표를 봅니다. '
       . '같은 종목·같은 조건(한도·기간·수수료·재진입·<b>제동장치</b>)에서 룰셋만 바꿔 돌린 결과입니다. '
       . ($anyDly
            ? '「지연 없이」 열은 그 룰셋의 차수 지연만 걷어낸 값이고, Δ 는 지연이 수익률을 얼마나 바꿨는지입니다. '
            : '')
       . '저장하지 않고 매번 계산하므로 <a href="/stock/index.php?mode=ruleset">룰셋 설정</a>을 고치면 여기 숫자도 바로 바뀝니다.</p>';
    echo '</div>';

    /*
     * 모달에 뿌릴 차수표. 금액은 지금 화면의 투자한도로 환산해 둔다 —
     * 룰셋 설정 화면의 미리보기와 달리 "이 시뮬레이션에서 실제로 얼마씩"이 바로 읽힌다.
     * 표시 문자열까지 PHP 에서 만들어 넘긴다 (JS 에서 자릿수·부호를 다시 맞출 일이 없게).
     */
    $ruleDetail = [];
    foreach ($cmp as $rid => $c) {
        // 비용은 넘기지 않는다 — pf_simulate 의 손익분기율은 "계획대로 하락했을 때의 가격 위치"라
        // 수수료와 무관하다 (룰셋 설정 화면의 미리보기와 같은 숫자여야 한다)
        $sim  = pf_simulate($c['st'], (float)$o['limit']);
        $cumW = 0.0;
        $body = [];
        foreach ($c['st'] as $n => $s) {
            $cumW += (float)$s['weight'];
            $body[] = [
                $n . '차',
                pf_pct0((float)$s['weight']),
                pf_pct0($cumW),
                $n === 1 ? '<span class="muted">기준</span>' : pf_pct0((float)$s['drop_rate']),
                isset($sim[$n]) ? pf_pct0($sim[$n]['price_factor'] - 1, 1) : '-',
                pf_pct0((float)$s['target_rate']),
                pf_n(round($sim[$n]['amount']     ?? 0)),
                pf_n(round($sim[$n]['cum_amount'] ?? 0)),
                isset($sim[$n]) ? pf_signed_pct($sim[$n]['breakeven_rate']) : '-',
            ];
        }
        $ruleDetail[$rid] = [
            'name' => (string)$c['name'],
            'sum'  => pf_pct0(pf_weight_sum($c['st'])),
            'cur'  => ($rid === $o['rs']),
            'url'  => pf_sim_url($id, $o, ['rs' => $rid]),
            'rows' => $body,
        ];
    }
    echo '<script>const SIM_RULES=' . json_encode($ruleDetail) . ';</script>';

    // ── 차트
    echo '<div class="card"><div class="sim-head"><h2 style="margin:0">주가 · 체결</h2>';
    echo '<span id="simIBar"></span>';
    // 기간 바(일봉/주봉·160일~전체·±·전체화면)는 모듈이 통째로 그린다 — 옛 주봉·⛶ 버튼 대체
    echo '<span id="simPBar"></span>';
    echo '<button type="button" class="btn btn-outline btn-sm" id="simZoomAll" onclick="pfSimZoomAll()">전체 기간</button>';
    echo '</div>';
    echo '<div class="chart-legend">';
    /* 봉으로 그릴 수 있는지는 「장중 체결」과 <b>같은 조건</b>($hasOhlc)으로 가른다 —
     * 두 기준이 어긋나면 "장중 체결은 되는데 봉은 안 나온다" 같은 설명 못 할 상태가 생긴다. */
    echo $hasOhlc
        ? '<span class="cl-item"><i class="cl-candle"></i> 일봉(시·고·저·종)</span>'
        : '<span class="cl-item"><i style="background:#22303f"></i> 종가</span>';
    echo '<span class="cl-item"><i style="background:#1e9e74"></i> 누적단가</span>';
    echo '<span class="cl-item"><i style="background:#1565c0"></i> 자동매도가</span>';
    echo '<span class="cl-item"><span class="up">▲</span> 매수</span>';
    echo '<span class="cl-item"><span class="down">▼</span> 매도</span>';
    if ($hasVol) echo '<span class="cl-item"><i style="background:#c9d4de"></i> 거래량</span>';
    echo '<span id="simLegend"></span>';   // 지표 값 표시 자리
    echo '</div>';
    echo '<div id="simPrice" style="height:340px;position:relative"></div>';
    echo '</div>';

    // ── 사이클
    $cyAll = $res['cycles'];
    if ($res['open_cycle']) $cyAll[] = $res['open_cycle'];

    echo '<div class="card"><h2>사이클 ' . count($cyAll) . '건</h2>';
    if (!$cyAll) {
        echo '<p class="muted" style="font-size:13px;margin:0">매수가 한 번도 일어나지 않았습니다.</p>';
    } else {
        echo '<div class="tbl-scroll" style="max-height:420px;overflow-y:auto"><table class="pf"><thead><tr>';
        echo '<th class="num">#</th><th>진입</th><th>청산</th><th class="num">보유일</th>';
        echo '<th class="num">최고차수</th><th class="num">매수</th><th class="num">투입금액</th>';
        echo '<th class="num">손익</th><th class="num">수익률</th></tr></thead><tbody>';
        $lastDay = $rows ? $rows[count($rows) - 1]['d'] : '';
        foreach ($cyAll as $cy) {
            $open = !empty($cy['open']);
            // 행을 누르면 차트를 이 구간으로 옮긴다 (JS 가 60거래일까지는 넓혀서 본다)
            echo '<tr class="cyc-row' . ($open ? ' open' : '') . '"'
               . ' data-from="' . pf_h($cy['entry_date']) . '"'
               . ' data-to="'   . pf_h($cy['exit_date'] ?: $lastDay) . '"'
               . ' title="클릭하면 위 차트가 이 구간으로 이동합니다">';
            echo '<td class="num">' . (int)$cy['no'] . '</td>';
            echo '<td>' . pf_h($cy['entry_date']) . '<br><span class="muted" style="font-size:11px">'
               . pf_n($cy['entry_price']) . '</span></td>';
            echo '<td>' . ($open ? '<span class="muted">보유중</span>'
                : pf_h($cy['exit_date'])
                  . (!empty($cy['stair']) ? ' <span class="mkt t-risk" title="계단관통 손절 — 지지구조가 전부 뚫려 목표 도달 전에 강제 청산">관통</span>' : '')
                  . '<br><span class="muted" style="font-size:11px">' . pf_n($cy['exit_price']) . '</span>') . '</td>';
            echo '<td class="num">' . pf_n($cy['days']) . '</td>';
            echo '<td class="num">' . (int)$cy['max_step'] . '차</td>';
            echo '<td class="num">' . (int)$cy['buys'] . '</td>';
            echo '<td class="num">' . pf_n(round($cy['invested'])) . '</td>';
            echo '<td class="num">' . pf_signed(round($cy['realized_pl'])) . '</td>';
            echo '<td class="num">' . ($cy['return'] === null ? '-' : pf_signed_pct($cy['return'])) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="sub muted" style="margin:9px 0 0;font-size:12px">'
           . '<b>행을 클릭</b>하면 위 차트가 그 사이클 구간으로 이동합니다 (짧으면 60거래일까지 넓혀서 보여 줍니다). '
           . '노란 줄은 아직 청산되지 않은 사이클로, 손익은 마지막 날 종가 기준 평가액입니다.</p>';
    }
    echo '</div>';

    /* ── 체결 <b>시점</b>의 시장 상태를 한 번만 계산해 차트·표가 함께 쓴다.
     *
     * 그 날까지의 봉으로 지표를 다시 계산한다(pf_state_at) — 오늘 상태를 옛 체결에 붙이면
     * 아무 뜻도 없는 라벨이 된다. 시뮬레이터가 돌린 <b>그 시세</b>로 재는 것이라 사후판단이 아니다.
     * ★ 체결이 수십 건이라 날짜별로 한 번만 구해 캐시한다(같은 날 여러 체결이 있다). */
    $stateOf = [];
    foreach ($res['trades'] as $t) {
        $d = (string)$t['d'];
        if (!array_key_exists($d, $stateOf)) $stateOf[$d] = pf_state_at($rows, $d, 3);
    }

    // ── 체결 내역
    echo '<div class="card"><h2>체결 내역 ' . count($res['trades']) . '건</h2>';
    if (!$res['trades']) {
        echo '<p class="muted" style="font-size:13px;margin:0">체결이 없습니다.</p>';
    } else {
        echo '<div class="tbl-scroll" style="max-height:420px;overflow-y:auto"><table class="pf"><thead><tr>';
        echo '<th>일자</th><th>구분</th><th class="num">차수</th><th class="num">체결가</th>';
        echo '<th class="num">수량</th><th class="num">금액</th><th class="num">비용</th>';
        echo '<th class="num">예수금</th><th>그 날 시장</th></tr></thead><tbody>';
        foreach ($res['trades'] as $t) {
            $sell = ($t['side'] === 'sell');
            echo '<tr' . ($sell ? ' style="background:#f4f8fd"' : '') . '>';
            echo '<td>' . pf_h($t['d']) . '</td>';
            echo '<td>' . ($sell ? '<span class="down">매도</span>' : '<span class="up">매수</span>') . '</td>';
            echo '<td class="num">' . ($t['step'] > 0 ? $t['step'] . '차' : '<span class="muted">-</span>') . '</td>';
            echo '<td class="num">' . pf_n($t['price']) . '</td>';
            echo '<td class="num">' . pf_n($t['qty']) . '</td>';
            echo '<td class="num">' . pf_n(round($t['amount'])) . '</td>';
            echo '<td class="num muted">' . pf_n(round($t['fee'])) . '</td>';
            echo '<td class="num">' . pf_n(round($t['cash'])) . '</td>';
            // 그 날의 시장 상태 — 배지 팔레트는 현황·보유종목과 같은 것을 쓴다
            $st = $stateOf[(string)$t['d']] ?? [];
            echo '<td>' . ($st ? pf_mkt_badges($st) : '<span class="flat">-</span>') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="sub muted" style="margin:9px 0 0;font-size:12px">'
           . '<b>그 날 시장</b>은 <b>그 체결일까지의 봉</b>으로 다시 계산한 상태입니다 — '
           . '오늘 상태를 옛 체결에 붙이면 뜻이 없어지므로 날짜로 잘라 계산합니다. '
           . '시뮬레이터가 돌린 그 시세로 재는 것이라 <b>사후판단이 아닙니다</b>. '
           . '앞 25봉은 이동평균·RSI 가 아직 뜻을 갖지 못해 비어 있습니다. '
           . '차트 마커에는 폭 때문에 <b>가장 앞선 하나만</b> 붙습니다.</p>';
    }
    echo '</div>';

    // ── 차트 데이터 (배열로 압축해서 넘긴다 — 수천 행이라 키 이름이 아깝다)
    //    [일자, 종가, 누적단가, 거래량, 상승여부, 자동매도가, 시가, 고가, 저가]
    //    누적단가·자동매도가는 보유 중일 때만 값이 있다 (비보유 구간은 null → 선이 끊긴다)
    //    ★ 시·고·저는 뒤에 붙였다 — 앞 인덱스를 밀면 사이클 구간이동(DATES)·거래량 코드가 통째로 어긋난다
    $bar = [];
    foreach ($rows as $r) $bar[$r['d']] = $r;   // 거래량·시가를 일자로 찾아 쓰려고

    $series = [];
    foreach ($res['equity'] as $e) {
        $b   = $bar[$e['d']] ?? [];
        $vol = isset($b['v']) ? (int)$b['v'] : null;
        /* 거래정지일은 시·고·저·거래량이 0 이고 종가만 온다 — 0 을 그대로 넘기면 봉이 바닥까지 늘어난다.
         * null 로 넘겨 그리는 쪽에서 종가를 네 값으로 쓰게 한다(도지). 레포의 낙폭 계산과 같은 규칙이다. */
        $o  = (isset($b['o']) && $b['o'] > 0) ? (float)$b['o'] : null;
        $hi = (isset($b['h']) && $b['h'] > 0) ? (float)$b['h'] : null;
        $lo = (isset($b['l']) && $b['l'] > 0) ? (float)$b['l'] : null;
        $series[] = [
            $e['d'],
            $e['price'] + 0,
            $e['avg'] === null ? null : round($e['avg'], 2),
            $vol,
            ($o !== null) ? ($e['price'] >= $o ? 1 : 0) : 1,
            ($e['sell'] ?? null) === null ? null : round($e['sell'], 2),
            $o, $hi, $lo,
        ];
    }
    /* 마커 — [일자, 매도여부, 차수, 수량, 그 날 시장(짧은 이름 1개)].
     * ★ 차트가 이미 촘촘해서 상태는 <b>가장 앞선 하나만</b>, 그것도 <b>짧은 이름</b>으로 붙인다
     *   (「과매도 RSI 25」 대신 「과매도」). 수치까지 넣으면 10년 구간에서 라벨이 겹쳐 못 읽는다 —
     *   자세한 것은 아래 체결표에 3개까지 있다. */
    $marks = [];
    foreach ($res['trades'] as $t) {
        $st = $stateOf[(string)$t['d']] ?? [];
        $marks[] = [$t['d'], $t['side'] === 'sell' ? 1 : 0, $t['step'], $t['qty'],
                    $st ? pf_mkt_short((string)$st[0]['key']) : ''];
    }

    echo '<script>';
    echo 'const SIM_SERIES=' . json_encode($series) . ';';
    // 마커 라벨에 한글(역배열·과매도)이 들어가므로 이스케이프하지 않는다
    echo 'const SIM_MARKS='  . json_encode($marks, JSON_UNESCAPED_UNICODE) . ';';
    echo '</script>';

    echo '</div></div>';   // .split-right / .split

    pf_sim_scripts($rules);
    pf_foot();
}

/**
 * 조건을 그대로 실은 시뮬레이터 주소.
 *
 * ★ 체크박스(재진입·장중)는 주소에서 빠지면 "꺼짐"으로 읽힌다(go=1 이 붙는 순간 폼 제출로 보므로).
 *   그래서 링크를 손으로 조립하면 조용히 옵션이 꺼지는 사고가 난다 — 반드시 이 함수로 만든다.
 *
 * @param array $override 바꿀 항목만 (예: ['rs' => 3])
 */
function pf_sim_url(int $id, array $o, array $override = []): string
{
    $o = array_merge($o, $override);

    $q = [
        'mode'  => 'sim',
        'id'    => $id,
        'go'    => 1,
        'rs'    => (int)$o['rs'],
        'limit' => (int)$o['limit'],
        'b'     => (int)$o['b'],
        'mk'    => (string)$o['mk'],
        'from'  => (string)$o['from'],
        'to'    => (string)$o['to'],
        'wait'  => (int)$o['wait'],
    ];
    if (!empty($o['re']))       $q['re']       = 1;
    if (!empty($o['intraday'])) $q['intraday'] = 1;
    if (!empty($o['ss']))       $q['ss']       = 1;

    return '/stock/index.php?' . http_build_query($q);
}

/**
 * 시뮬레이터 화면의 자료등록 모달 + 차트·입력 스크립트.
 * 페이지가 여러 지점에서 끝나므로(데이터 없음/결과 없음) 모달은 여기서 한 번에 찍는다.
 */
function pf_sim_scripts(array $rules = []): void
{
    // ── 자료 등록 모달 (종목이 곧 키라 종목은 필수)
    echo '<div class="pf-modal-back" id="simModal" onclick="if(event.target===this)pfCloseSim()">';
    echo '<div class="pf-modal" role="dialog" aria-modal="true">';
    echo '<div class="pfm-head"><div>시세 자료 등록</div>';
    echo '<button type="button" class="pfm-x" onclick="pfCloseSim()" aria-label="닫기">✕</button></div>';

    // 기본 경로: 종목만 고르면 네이버에서 일봉(시·고·저·종)을 받아 온다
    echo '<form class="pfm-body" method="post" action="/stock/api.php?module=sim&action=fetch">';
    echo '<div class="fld-row">';
    pf_stock_picker('nv', '', '', '종목', '(이름 또는 코드로 검색)', '100%');
    echo '<label class="fld">시작일<input type="date" name="from" value="'
       . date('Y-m-d', strtotime('-10 years')) . '"></label>';
    echo '<label class="fld">종료일<input type="date" name="to" value="' . date('Y-m-d') . '"></label>';
    echo '</div>';
    echo '<div class="pfm-foot"><span class="muted">'
       . '네이버 금융에서 일봉을 받아옵니다 — <b>시·고·저·종</b>이 다 들어와 장중 체결까지 반영할 수 있습니다. '
       . '이미 있는 종목이면 <b>시세만 갱신</b>되고 조건은 그대로 유지됩니다.</span>';
    echo '<button class="btn btn-primary" type="submit">가져오기</button></div>';
    echo '</form>';

    echo '</div></div>';

    // ── 투자한도 모달 (한도 칸을 누르면 열린다)
    //    고르면 곧바로 조건 폼을 제출한다 — 금액만 바꿔 다시 돌리는 게 거의 유일한 용도라서.
    echo '<div class="pf-modal-back" id="limitModal" onclick="if(event.target===this)pfCloseLimit()">';
    echo '<div class="pf-modal" role="dialog" aria-modal="true" style="max-width:430px">';
    echo '<div class="pfm-head"><div>투자한도</div>';
    echo '<button type="button" class="pfm-x" onclick="pfCloseLimit()" aria-label="닫기">✕</button></div>';

    echo '<div class="pfm-body"><div class="lim-grid">';
    foreach (pf_sim_limits() as [$v, $lab]) {
        echo '<button type="button" class="lim-chip" data-v="' . $v . '">'
           . '<b>' . $lab . '</b><span>' . number_format($v) . '</span></button>';
    }
    echo '</div>';
    echo '<label class="fld" style="margin-top:13px">목록에 없는 금액'
       . '<input type="text" class="num-comma" id="limitCustom" inputmode="numeric" '
       . 'placeholder="예: 150,000,000"></label>';
    echo '</div>';
    echo '<div class="pfm-foot"><span class="muted">금액을 고르면 바로 다시 계산합니다.</span>';
    echo '<button type="button" class="btn btn-primary" onclick="pfApplyLimit()">적용</button></div>';
    echo '</div></div>';

    // ── 룰셋 차수표 모달 (룰셋 비교 표의 행을 누르면 열린다). 내용은 SIM_RULES 로 채운다
    echo '<div class="pf-modal-back" id="ruleModal" onclick="if(event.target===this)pfCloseRule()">';
    echo '<div class="pf-modal" role="dialog" aria-modal="true" style="max-width:820px">';
    echo '<div class="pfm-head"><div><span id="rmName"></span>'
       . ' <span class="muted" style="font-size:12px" id="rmSum"></span></div>';
    echo '<button type="button" class="pfm-x" onclick="pfCloseRule()" aria-label="닫기">✕</button></div>';

    echo '<div class="pfm-body"><div class="tbl-scroll"><table class="pf"><thead><tr>';
    echo '<th class="num">차수</th><th class="num">비중</th><th class="num">누적비중</th>';
    echo '<th class="num">단계하락률</th><th class="num">최초대비</th><th class="num">목표수익률</th>';
    echo '<th class="num">매수금액</th><th class="num">누적금액</th><th class="num">손익분기율</th>';
    echo '</tr></thead><tbody id="rmBody"></tbody></table></div></div>';

    echo '<div class="pfm-foot"><span class="muted">'
       . '금액은 지금 화면의 <b>투자한도</b> 기준이고, 최초대비·손익분기율은 <b>계획대로 하락했을 때</b>의 '
       . '가격 위치입니다(수수료 무관). 차수 내용을 고치려면 '
       . '<a href="/stock/index.php?mode=ruleset">룰셋 설정</a>으로 가세요.</span>';
    echo '<a class="btn btn-primary" id="rmApply" href="#">이 룰셋으로 돌리기</a></div>';
    echo '</div></div>';

    pf_stock_picker_css();
    pf_stock_picker_script('nv', true);

    echo <<<'CSS'
<style>
.pf-modal-back{display:none;position:fixed;inset:0;background:rgba(16,32,48,.5);z-index:200;
  align-items:flex-start;justify-content:center;padding:40px 14px;overflow-y:auto}
.pf-modal-back.on{display:flex}
.pf-modal{background:#fff;border-radius:13px;width:100%;max-width:560px;
  box-shadow:0 18px 50px rgba(10,25,45,.3)}
.pfm-head{display:flex;justify-content:space-between;align-items:center;padding:13px 16px;
  background:linear-gradient(90deg,#123c63,#1d5c93);color:#fff;font-size:17px;font-weight:800;
  border-radius:13px 13px 0 0}
.pfm-x{background:rgba(255,255,255,.16);border:none;color:#fff;font-size:14px;cursor:pointer;
  width:28px;height:28px;border-radius:7px;line-height:1}
.pfm-x:hover{background:rgba(255,255,255,.3)}
.pfm-body{padding:14px 16px 0}
.pfm-foot{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;
  padding:13px 0;margin-top:12px;border-top:1px solid #eef2f6}
.pfm-foot .muted{font-size:12px;line-height:1.45}
.note-brief{font-size:11px;color:#9aa7b4;margin-top:2px}

/* 좌우분할 — 왼쪽은 목록(짧고 고정), 오른쪽은 상세(길다) */
.sim-split{grid-template-columns:minmax(300px,380px) minmax(0,1fr)}
.sim-split .split-right{position:static}
.sim-left-card{position:sticky;top:14px}
/* 목록은 세로로만 스크롤한다. 칸이 좁으니 줄바꿈을 허용하고 가로 스크롤은 없앤다 */
.sim-list{max-height:calc(100vh - 220px);overflow-y:auto;overflow-x:hidden}
.sim-list table.pf th,.sim-list table.pf td{white-space:normal;padding:7px 6px}
.sim-list .note-brief{white-space:normal;overflow:visible;max-width:none;line-height:1.35}
/* 숫자 칸의 보조줄(MDD·차수·계산일)은 한 줄로 — 쪼개지면 읽기 나쁘다 */
.sim-list td.num{white-space:nowrap}
.sim-list td.num .note-brief{white-space:nowrap}
.sim-list thead th{position:sticky;top:0;z-index:1}
.sim-right .card:last-of-type{margin-bottom:0}
.sim-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:11px}
.sim-empty{padding:60px 20px;text-align:center;color:#7d8b99;font-size:15px;line-height:1.7}
/* 투자한도 — 칸은 누르는 것이지 치는 것이 아니라는 게 보이게 한다 */
.lim-open{cursor:pointer;background:#f7fafd}
.lim-open:hover{border-color:#1d5c93;background:#eef4fa}
/* 한도 모달의 금액 버튼 */
.lim-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:7px}
.lim-chip{display:flex;flex-direction:column;align-items:center;gap:1px;padding:9px 4px;cursor:pointer;
          background:#f2f6fa;border:1px solid #dbe4ec;border-radius:7px;font-family:inherit}
.lim-chip b{font-size:13px;font-weight:800;color:#22303f}
.lim-chip span{font-size:10px;font-weight:600;color:#8496a6}
.lim-chip:hover{background:#e7eef6;border-color:#1d5c93}
.lim-chip:hover b{color:#1d5c93}
.lim-chip.on{background:#1d5c93;border-color:#1d5c93}
.lim-chip.on b,.lim-chip.on span{color:#fff}
/* 사이클 표 — 행을 누르면 차트가 그 구간으로 간다 */
tr.cyc-row{cursor:pointer}
tr.cyc-row.open{background:#fff8e6}
tr.cyc-row:hover{background:#f2f8fd}
tr.cyc-row.on{background:#eaf3fc;box-shadow:inset 3px 0 0 #1d5c93}
tr.cyc-row.on td{font-weight:700}
/* 룰셋 비교 표 — 행을 누르면 차수표 모달 */
tr.rs-row{cursor:pointer}
tr.rs-row:hover{background:#f2f8fd}
@media(max-width:1200px){
  .sim-split{grid-template-columns:1fr}
  .sim-left-card{position:static}
  .sim-list{max-height:none}
}
@media(max-width:560px){
  .pf-modal-back{padding:14px 8px}
  .pfm-foot{flex-direction:column;align-items:stretch}
  .pfm-foot .btn{width:100%}
  .lim-grid{grid-template-columns:repeat(2,1fr)}
}
</style>
CSS;

    // 차트는 공용 모듈(style/dailychart.js)이 그린다 — 라이브러리 로드도 모듈이 맡는다
    echo '<script src="/style/dailychart.js?v=27"></script>';
    echo <<<'JS'
<script>
/* 콤마 포맷은 공용(pf_comma_js)의 pfComma 가 맡는다 */

/* ── 투자한도 모달 ─────────────────────────────────────────────
   한도 칸은 readonly 라 키보드로 못 고친다. 금액은 전부 여기서 정한다.
   고르면 조건 폼을 그대로 제출하므로 재진입·장중 체크박스도 화면 상태 그대로 실려 간다
   (링크로 조립하면 체크박스가 조용히 꺼지는 사고가 난다). */
function pfOpenLimit(){
  var m = document.getElementById('limitModal');
  var i = document.getElementById('simLimitBox');
  if (!m || !i) return;

  var n = Number(String(i.value).replace(/[^\d]/g, '')) || 0;
  Array.prototype.forEach.call(m.querySelectorAll('.lim-chip'), function(b){
    b.classList.toggle('on', Number(b.getAttribute('data-v')) === n);
  });

  var c = document.getElementById('limitCustom');
  if (c) c.value = i.value;

  m.classList.add('on');
  document.body.style.overflow = 'hidden';
}

function pfCloseLimit(){
  var m = document.getElementById('limitModal');
  if (!m) return;
  m.classList.remove('on');
  document.body.style.overflow = '';
}

/** 한도를 넣고 곧바로 다시 돌린다 */
function pfSetLimit(v){
  var i = document.getElementById('simLimitBox');
  var n = Number(v) || 0;
  if (!i || n <= 0) return;
  i.value = n.toLocaleString();
  pfCloseLimit();
  if (i.form) i.form.submit();
}

function pfApplyLimit(){
  var c = document.getElementById('limitCustom');
  pfSetLimit(c ? String(c.value).replace(/[^\d]/g, '') : 0);
}

document.addEventListener('click', function(e){
  var b = e.target.closest ? e.target.closest('.lim-chip') : null;
  if (b) pfSetLimit(b.getAttribute('data-v'));
});

/* ── 룰셋 차수표 모달 ────────────────────────────────────────────
   룰셋 비교 표의 행을 누르면 그 룰셋의 차수 구성을 보여 준다.
   숫자는 PHP 가 만들어 둔 문자열이라 여기서는 칸에 넣기만 한다. */
function pfOpenRule(rid){
  var m = document.getElementById('ruleModal');
  var d = (typeof SIM_RULES !== 'undefined') ? SIM_RULES[rid] : null;
  if (!m || !d) return;

  document.getElementById('rmName').textContent = d.name + (d.cur ? ' (적용중)' : '');
  document.getElementById('rmSum').textContent  = '비중합 ' + d.sum + ' · ' + d.rows.length + '단계';

  var tb = document.getElementById('rmBody');
  tb.innerHTML = d.rows.map(function(r){
    return '<tr>' + r.map(function(c){ return '<td class="num">' + c + '</td>'; }).join('') + '</tr>';
  }).join('');

  var a = document.getElementById('rmApply');
  a.href = d.url;
  a.style.display = d.cur ? 'none' : '';     // 이미 적용 중이면 버튼이 할 일이 없다

  m.classList.add('on');
  document.body.style.overflow = 'hidden';
}

function pfCloseRule(){
  var m = document.getElementById('ruleModal');
  if (!m) return;
  m.classList.remove('on');
  document.body.style.overflow = '';
}

document.addEventListener('click', function(e){
  if (!e.target.closest) return;
  if (e.target.closest('a,button')) return;          // '적용' 링크는 그대로 따라가게 둔다
  var tr = e.target.closest('tr.rs-row');
  if (tr) pfOpenRule(tr.getAttribute('data-rid'));
});

document.addEventListener('keydown', function(e){
  if (e.key === 'Escape') { pfCloseLimit(); pfCloseRule(); }
  if (e.key === 'Enter' && e.target && e.target.id === 'limitCustom') { e.preventDefault(); pfApplyLimit(); }
});

function pfOpenSim(){
  var m = document.getElementById('simModal');
  if (!m) return;
  m.classList.add('on');
  document.body.style.overflow = 'hidden';
  var f = document.getElementById('nvSearch');
  if (f) f.focus();
}
function pfCloseSim(){
  var m = document.getElementById('simModal');
  if (!m) return;
  m.classList.remove('on');
  document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e){ if (e.key === 'Escape') pfCloseSim(); });

// 목록 행 클릭으로 열기 (삭제 버튼 등 no-sel 은 제외)
document.addEventListener('click', function(e){
  if (e.target.closest && e.target.closest('.no-sel, a, button')) return;
  var tr = e.target.closest ? e.target.closest('tr.stp[data-href]') : null;
  if (tr) location.href = tr.dataset.href;
});

(function(){
  if (typeof SIM_SERIES === 'undefined' || !window.DailyChart) return;

  // ── 주가(일봉) + 누적단가 + 자동매도가 계단선 + 체결 마커 — 공용 모듈이 그린다
  var ph = document.getElementById('simPrice');
  var pc = null;   // DailyChart 핸들 (pfSimZoom 이 쓴다)

  DailyChart.load().then(function(){
    if (!ph) return;
    // 마커 글자는 라이브러리 text 그대로(chips:false) — 10년 구간이라 칩은 겹쳐 못 읽는다
    pc = DailyChart.create(ph, { theme: 'light', volAlpha: '44', markers: { chips: false },
                                 key: 'sim', legend: 'simLegend' });
    if (!pc) return;

    /* SIM_SERIES = [일자, 종가, 누적단가, 거래량, 상승여부, 자동매도가, 시가, 고가, 저가].
       시·고·저가 전부 없으면(옛 종가전용 데이터) 모듈이 선차트로 폴백한다.
       거래정지일(시·고·저 null)의 종가 도지 처리도 모듈이 맡는다. */
    pc.setData(SIM_SERIES.map(function(r){
      return { time: r[0], open: r[6], high: r[7], low: r[8], close: r[1],
               vol: (r[3] !== null && r[3] > 0) ? r[3] : null };
    }));

    var avg = pc.addLine({ color: '#1e9e74', width: 2, style: 'dashed' });
    avg.setData(SIM_SERIES.filter(function(r){ return r[2] !== null; })
                          .map(function(r){ return { time: r[0], value: r[2] }; }));

    /* 자동매도가 — 차수가 늘 때마다 계단처럼 바뀌므로 계단선.
       보유 중일 때만 값이 있고, 비보유 구간(null)은 모듈이 whitespace 로 선을 끊는다. */
    var sell = pc.addLine({ color: '#1565c0', width: 1, style: 'dotted', stepped: true });
    sell.setData(SIM_SERIES.map(function(r){
      return { time: r[0], value: (r[5] === null || r[5] === undefined) ? null : r[5] };
    }));

    // 체결 마커 — m[4] = 그 날 시장 상태 1개 (역배열·과매도 …). 없으면 차수만
    pc.setMarkers(SIM_MARKS.map(function(m){
      var isSell = (m[1] === 1);
      var base = isSell ? '매도' : (m[2] + '차');
      return { time: m[0], sell: isSell, text: m[4] ? (base + ' ' + m[4]) : base };
    }));

    /* 기간 바 — 다른 화면과 같은 세그먼트 [일봉|주봉 ┃ 160일…전체 ┃ ± ┃ ⛶].
       시뮬은 구간 전체를 보는 게 기본이라 「전체」(index 3)로 시작한다 (옛 동작 유지).
       주봉 토글·전체화면 버튼은 이 바가 대체했고, 바는 전체화면에 스스로 따라간다. */
    DailyChart.periodBar('simPBar', pc, { theme: 'light', defaultIndex: 3 });
    DailyChart.indicatorBar('simIBar', pc, { theme: 'light', key: 'sim' });   // 사용자 지표 + 차트틀
    pc.fsCarry('simZoomAll');   // 「전체 기간」 버튼도 전체화면에 같이
  });

  /* ── 사이클 표에서 행을 누르면 그 구간으로 이동.
        구간이 60거래일보다 짧으면 진입일부터 60거래일까지 넓혀서 본다.
        (차트에 전체 데이터가 실려 있어 — HTS 방식 — 옛 사이클도 바로 간다) */
  window.pfSimZoom = function(from, to){
    if (!pc) return;
    pc.zoomRange(from, to, { minBars: 60, pad: 3 });
    ph.scrollIntoView({ behavior: 'smooth', block: 'center' });
  };

  window.pfSimZoomAll = function(){ if (pc) pc.zoomAll(); };

  document.addEventListener('click', function(e){
    var tr = e.target.closest ? e.target.closest('tr.cyc-row') : null;
    if (!tr) return;
    Array.prototype.forEach.call(document.querySelectorAll('tr.cyc-row.on'),
      function(x){ x.classList.remove('on'); });
    tr.classList.add('on');
    window.pfSimZoom(tr.getAttribute('data-from'), tr.getAttribute('data-to'));
  });
})();
</script>
JS;
}

// ══════════════════════════════════════════════════════════════════════
//  재무분석 — DART 재무제표 스크리너
//
//  code 가 붙으면 그 종목의 연도별 재무, 없으면 조건 검색 화면.
//  비율(영업이익률·ROE·부채비율…)은 DB 에 없다 — Dart::ratio() 가 금액에서 매번 만든다.
// ══════════════════════════════════════════════════════════════════════

/** 스크리너 조건 정의 (단일 소스). [키, 라벨, 방향, 단위, 도움말] */
/**
 * SUE(표준화 이익 서프라이즈) 맵 — 기준 (연도×보고서)가 가리키는 <b>당분기</b> 영업이익의
 * 전년동기 대비 변화를, 그 종목 자신의 과거 2년 변화 변동성(σ)으로 나눈 값. [stock_code => sue]
 *
 * 컨센서스 없이 자기 이력만 쓰는 원조 정의(Foster 1977)라 전종목 계산이 된다.
 * 백테스트(2024-08~2026-07 · 분기 8개 · 17,220건 · 시장중앙 대비): 5분위가 단조이고
 * 효과는 <b>상위 20%에 집중</b>(+20일 +1.6%·+60일 +3.6%·유동성 10억↑), 하위 20%는 음수(쇼크 회피).
 * 시총 버킷 안에서도 스프레드가 유지됨(대형주 랠리 교란 통제 확인). 품질(순익÷영익≤1.5)·
 * 매출성장과 결합할 때 가장 강했다 — 이 화면의 기존 조건들과 조합하면 그 규칙이 재현된다.
 *
 * ★비율 무저장 원칙 — 재무를 다시 받으면 값도 따라온다. 전종목 한 번에 계산(수십 ms).
 * ★자리는 Dart.class 가 맞지만 .class 는 도구가 못 읽어 패치가 위험하다 — 소비자가
 *   이 화면뿐이라 여기 둔다. 두 번째 소비자가 생기면 그때 옮긴다.
 */
function pf_sue_build(PDO $pdo, int $year, string $reprt): array
{
    $qNo = ['11013' => 1, '11012' => 2, '11014' => 3, '11011' => 4][$reprt] ?? null;
    if ($qNo === null) return [];
    $qk = $year * 4 + $qNo;

    // 당분기 = YTD 뺄셈이라 과거 12분기(σ 이력 8 + 전년동기 4)까지 거슬러 읽는다
    $st = $pdo->prepare("
        SELECT stock_code, bsns_year, reprt_code, fs_div, revenue, op_income, net_income
          FROM stock_financial
         WHERE bsns_year BETWEEN ? AND ? AND op_income IS NOT NULL
         ORDER BY stock_code, bsns_year, reprt_code, fs_div");
    $st->execute([$year - 4, $year]);

    $ytd = [];   // [code][year][reprt] = ['fs','rev','op','ni'] — 연결(CFS) 우선, 백테스트와 같은 규칙
    foreach ($st as $r) {
        $c = $r['stock_code']; $y = (int)$r['bsns_year']; $rc = $r['reprt_code'];
        if (isset($ytd[$c][$y][$rc]) && ($ytd[$c][$y][$rc]['fs'] === 'CFS' || $r['fs_div'] !== 'CFS')) continue;
        $ytd[$c][$y][$rc] = ['fs' => $r['fs_div'],
            'rev' => $r['revenue'] !== null ? (float)$r['revenue'] : null,
            'op'  => (float)$r['op_income'],
            'ni'  => $r['net_income'] !== null ? (float)$r['net_income'] : null];
    }

    $map = [];
    foreach ($ytd as $c => $ys) {
        $qv = pf_sue_qv($ys, 'op');    // 당분기: Q1=1Q누적 · Q2=반기−1Q · Q3=3Q−반기 · Q4=연간−3Q
        $rv = pf_sue_qv($ys, 'rev');
        if (!isset($qv[$qk], $qv[$qk - 4])) continue;
        $hist = [];
        for ($i = 1; $i <= 8; $i++) {
            if (isset($qv[$qk - $i], $qv[$qk - $i - 4])) $hist[] = $qv[$qk - $i] - $qv[$qk - $i - 4];
        }
        if (count($hist) < 4) continue;   // 이력 부족이면 값 없음 — 0 으로 채우면 "서프라이즈 없음"으로 잘못 읽힌다
        $m = array_sum($hist) / count($hist);
        $var = 0.0;
        foreach ($hist as $h) $var += ($h - $m) ** 2;
        $sd = sqrt($var / count($hist));
        if ($sd <= 0) continue;

        // 품질·매출동반은 백테스트 규칙 그대로 — 품질 = 그 보고서 YTD 영업흑자 ∧ |순익|÷영익 ≤ 1.5
        $yr = $ytd[$c][$year][$reprt] ?? null;
        $map[$c] = [
            'sue'     => ($qv[$qk] - $qv[$qk - 4]) / $sd,
            'rev_up'  => (isset($rv[$qk], $rv[$qk - 4]) && $rv[$qk] !== null && $rv[$qk - 4] !== null)
                            ? ($rv[$qk] > $rv[$qk - 4]) : null,
            'quality' => ($yr !== null && $yr['op'] > 0 && $yr['ni'] !== null)
                            ? (abs($yr['ni']) / $yr['op'] <= 1.5) : false,
            'ni_op'   => ($yr !== null && $yr['op'] > 0 && $yr['ni'] !== null) ? $yr['ni'] / $yr['op'] : null,
        ];
    }
    return $map;
}

/** 스크리너용 — SUE 값만 [code => float] */
function pf_sue_map(PDO $pdo, int $year, string $reprt): array
{
    return array_map(fn($v) => $v['sue'], pf_sue_build($pdo, $year, $reprt));
}

/**
 * YTD 보고서 4벌(1Q·반기·3Q·연간)에서 <b>그 분기 3개월</b> 값을 만든다 [연도*4+분기 => 값].
 * Q1=1Q누적 · Q2=반기−1Q · Q3=3Q−반기 · Q4=연간−3Q. 앞 보고서가 없으면 그 분기는 만들지 않고,
 * 값이 한쪽이라도 null 이면(매출 미신고) null — 소비자의 isset() 검사에서 자연히 빠진다.
 */
function pf_sue_qv(array $ys, string $key): array
{
    $qv = [];
    foreach ($ys as $y => $rc) {
        $q1 = $rc['11013'] ?? null; $q2 = $rc['11012'] ?? null;
        $q3 = $rc['11014'] ?? null; $q4 = $rc['11011'] ?? null;
        if ($q1) $qv[$y * 4 + 1] = $q1[$key];
        if ($q2 && $q1) {
            $qv[$y * 4 + 2] = ($q2[$key] !== null && $q1[$key] !== null) ? $q2[$key] - $q1[$key] : null;
        }
        if ($q3 && $q2) {
            $qv[$y * 4 + 3] = ($q3[$key] !== null && $q2[$key] !== null) ? $q3[$key] - $q2[$key] : null;
        }
        if ($q4 && $q3) {
            $qv[$y * 4 + 4] = ($q4[$key] !== null && $q3[$key] !== null) ? $q4[$key] - $q3[$key] : null;
        }
    }
    return $qv;
}

/**
 * 종목 하나의 분기별 SUE 시계열 [연도*4+분기 => sue] — 정의는 pf_sue_build 와 동일한 단일 종목판
 * (CFS 우선 · σ 이력 8개 최소 4개 · pf_sue_qv 공용). 소비자는 재무상세 차트의 공시 마커.
 * 전종목판을 분기 수만큼 돌리면 수백 ms 라 종목 하나만 읽는 판을 따로 둔다 (1~2ms).
 */
function pf_sue_stock(PDO $pdo, string $code): array
{
    $st = $pdo->prepare("
        SELECT bsns_year, reprt_code, fs_div, op_income
          FROM stock_financial
         WHERE stock_code = ? AND op_income IS NOT NULL
         ORDER BY bsns_year, reprt_code, fs_div");
    $st->execute([$code]);

    $ytd = [];
    foreach ($st as $r) {
        $y = (int)$r['bsns_year']; $rc = $r['reprt_code'];
        if (isset($ytd[$y][$rc]) && ($ytd[$y][$rc]['fs'] === 'CFS' || $r['fs_div'] !== 'CFS')) continue;
        $ytd[$y][$rc] = ['fs' => $r['fs_div'], 'op' => (float)$r['op_income']];
    }
    $qv = pf_sue_qv($ytd, 'op');

    $out = [];
    foreach ($qv as $qk => $v) {
        if (!isset($qv[$qk - 4])) continue;
        $hist = [];
        for ($i = 1; $i <= 8; $i++) {
            if (isset($qv[$qk - $i], $qv[$qk - $i - 4])) $hist[] = $qv[$qk - $i] - $qv[$qk - $i - 4];
        }
        if (count($hist) < 4) continue;   // 이력 부족이면 값 없음 — pf_sue_build 와 같은 원칙
        $m = array_sum($hist) / count($hist);
        $var = 0.0;
        foreach ($hist as $h) $var += ($h - $m) ** 2;
        $sd = sqrt($var / count($hist));
        if ($sd <= 0) continue;
        $out[$qk] = ($v - $qv[$qk - 4]) / $sd;
    }
    return $out;
}

function pf_fund_filters(): array
{
    return [
        ['cap',    '시가총액',    'min', '억원', 'mktcap',     'KRX 기준 시가총액'],
        ['rev',    '매출액',      'min', '억원', 'revenue',    '규모가 너무 작은 종목을 걷어냅니다'],
        ['per',    'PER',         'max', '배',   'per',        '주가 ÷ EPS — 적자면 계산하지 않습니다'],
        ['pbr',    'PBR',         'max', '배',   'pbr',        '주가 ÷ BPS — 자본잠식이면 계산하지 않습니다'],
        ['opm',    '영업이익률',  'min', '%',    'op_margin',  '영업이익 ÷ 매출액'],
        ['npm',    '순이익률',    'min', '%',    'net_margin', '당기순이익 ÷ 매출액'],
        ['roe',    'ROE',         'min', '%',    'roe',        '당기순이익 ÷ 자본총계'],
        ['growth', '매출성장률',  'min', '%',    'rev_growth', '전년 대비'],
        ['debt',   '부채비율',    'max', '%',    'debt_ratio', '부채총계 ÷ 자본총계 — 낮을수록 안전'],
        ['cur',    '유동비율',    'min', '%',    'cur_ratio',  '유동자산 ÷ 유동부채 — 높을수록 안전'],
        ['nio',    '순익÷영익',   'max', '배',   'ni_op',
         '순이익 ÷ 영업이익. 1.5배를 넘으면 자산매각 같은 일회성 이익이 섞였을 수 있습니다 — '
         . '그런 해엔 PER 이 뚝 떨어져 싸 보이지만 이듬해 원위치합니다'],
        ['sue',    'SUE',         'min', 'σ',    'sue',
         '이익 서프라이즈 — 기준 분기의 (당분기 영업이익 − 전년동기) ÷ 자기 과거 2년 변동성. '
         . '1 을 넣으면 대략 상위 20% (실측 경계 0.8~1.4). 백테스트(2024~26 · 8개 분기): '
         . '상위 20%가 +60거래일 시장중앙 대비 +3.6%·승률 57%, 하위 20%는 −1.6% — '
         . '품질(순익÷영익≤1.5)·매출성장률과 함께 걸 때 가장 강했습니다'],

        /* 아래 둘만 재무가 아니라 <b>시세</b> 조건이다. 재무만 보면 "좋은 회사"는 찾아도
         * "지금 싼가"는 못 본다 — 실적이 좋은데 주가가 빠진 자리를 찾는 축이다. */
        ['dd',     '고점대비낙폭', 'min', '%',   'dd_hi',
         '최근 6개월 최고가에서 몇 % 빠져 있는지. 30 을 넣으면 고점 대비 30% 넘게 하락한 종목만 남습니다'],
        ['rb',     '저점대비상승', 'max', '%',   'rb_lo',
         '최근 6개월 최저가에서 몇 % 올라와 있는지. 30 을 넣으면 바닥에서 30% 이내로 '
         . '아직 덜 오른 종목만 남습니다 — 이미 두 배 오른 종목을 걸러 냅니다'],
    ];
}

/**
 * 지금 걸려 있는 조건을 쿼리스트링으로 만든다 (저장·비교용).
 *
 * ★ 기준연도·보고서(y·rc)와 go 는 <b>넣지 않는다</b> — 저장하는 것은 「조건」이지 「무엇을 보고 있나」가 아니다.
 * ★ 빈 값은 빼서, 같은 조건이면 <b>글자까지 같은</b> 문자열이 나오게 한다.
 *   그래야 지금 조건과 저장된 배지를 문자열 비교만으로 맞춰 볼 수 있다(현재 배지 강조).
 * ★ 체크박스는 켜져 있을 때만 넣는다 — 폼이 체크된 것만 보내는 것과 결을 맞춘다.
 */
function pf_fund_cond_qs(array $q, bool $profit, bool $listed, bool $noSpac,
                         string $sort, bool $desc): string
{
    $p = [];
    foreach (pf_fund_filters() as [$k]) {
        if ($q[$k] === null) continue;
        // 1.50 · 10.00 처럼 꼬리 0 이 붙으면 같은 조건이 다른 문자열이 된다
        $p[$k] = rtrim(rtrim(number_format($q[$k], 2, '.', ''), '0'), '.');
    }
    /* 영업흑자만·거래종목만·스팩제외는 늘 켜져 있으므로 <b>꺼진 경우에만</b> 싣는다.
     * 늘 붙이면 저장 문자열에 의미 없는 잡음이 끼고, 조건 비교(현재 배지 강조)도 흐려진다. */
    if (!$profit) $p['profit'] = '0';
    if (!$listed) $p['listed'] = '0';
    if (!$noSpac) $p['nospac'] = '0';
    $p['sort'] = $sort;
    $p['dir']  = $desc ? 'desc' : 'asc';

    return http_build_query($p);
}

/**
 * 어닝 서프라이즈 — 최근 정기공시 중 SUE(이익 서프라이즈)가 큰 종목을 공시일 기준으로 본다.
 *
 * 스크리너와의 분업: 스크리너 = 조건을 걸어 <b>탐색</b>(기준 분기 전체) /
 * 여기 = <b>신호</b>(최근 N일 공시된 것만 · 실제 접수일 기준 · 드리프트 경과 표시).
 * PEAD 는 공시 후 60거래일을 가므로 「언제 공시됐고 며칠 지났나」가 이 화면의 알맹이다.
 *
 * 데이터: dart_rcept(실제 접수일 · cron job=rcept/fresh 가 채움) × pf_sue_build × krx_amt(시세).
 * 규칙 배지 = 백테스트 확정 규칙 그대로: SUE≥1(≈상위 20%) ∧ 품질 ∧ 매출동반 ∧ 거래대금 10억↑.
 * ★접수일은 원본 = MIN(rcept_dt) — [기재정정] 재접수가 공시일을 뒤로 미루면 안 된다.
 */
function pf_page_earn(PDO $pdo, Pf $pf): void
{
    $days = max(7, min(120, (int)($_GET['days'] ?? 30)));

    pf_head('어닝 서프라이즈', 'fund');
    pf_subtabs('earn', 'fund');
    pf_flash();

    echo '<div class="pf-head"><div><h1>어닝 서프라이즈</h1>';
    echo '<div class="sub">최근 <b>' . $days . '일</b> 안에 정기보고서를 낸 종목의 <b>SUE(이익 서프라이즈)</b> 목록 — '
       . '실제 공시일(DART 접수일) 기준입니다. 백테스트(2019~2026 · 8년 · 접수일 진입): '
       . '규칙 충족은 공시 후 +60거래일 시장중앙 대비 <b>8년 중 7년 양수</b>(최근 2년 +4%대·그 전엔 +0.2~1.9%), '
       . '어닝 쇼크(하위 20%)는 거의 매년 음수입니다. 드리프트가 두 달을 가므로 공시 직후가 아니어도 늦지 않습니다.</div></div>';
    echo '<div class="act"><form class="inline" method="get" action="/stock/index.php">'
       . '<input type="hidden" name="mode" value="earn">'
       . '<label class="fld" style="flex-direction:row;align-items:center;gap:6px"><span>공시 창</span>'
       . '<select name="days" onchange="this.form.submit()">';
    foreach ([14, 30, 60, 90] as $d) {
        echo '<option value="' . $d . '"' . ($d === $days ? ' selected' : '') . '>' . $d . '일</option>';
    }
    echo '</select></label></form></div></div>';

    $hasTbl = (bool)$pdo->query("SHOW TABLES LIKE 'dart_rcept'")->fetchColumn();
    if (!$hasTbl) {
        echo '<div class="warn">접수일 원장(dart_rcept)이 아직 없습니다 — SSH 에서 '
           . '<code>php cron/dart_collect.php job=rcept from=20160101</code> 을 한 번 돌리세요. '
           . '이후에는 매일 새벽 fresh 크론이 최근 21일을 같이 받습니다.</div>';
        pf_foot(); return;
    }

    /* 최근 공시 — 원본 접수일(MIN) 이 창 안에 든 (종목 × 보고서)만. 정정공시는 같은 그룹으로 접힌다. */
    $st = $pdo->prepare("
        SELECT stock_code, bsns_year, reprt_code, MIN(rcept_dt) dt
          FROM dart_rcept
         WHERE reprt_code IS NOT NULL
         GROUP BY stock_code, bsns_year, reprt_code
        HAVING dt >= DATE_SUB(CURDATE(), INTERVAL ? DAY)");
    $st->execute([$days]);
    $filings = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!$filings) {
        echo '<div class="card"><p class="muted" style="margin:0;font-size:13px">최근 ' . $days . '일 안의 정기공시가 없습니다 — '
           . '공시 창을 넓히거나, 분기 시즌(5월·8월·11월·3~4월)에 다시 보세요.</p></div>';
        pf_foot(); return;
    }

    // 그룹(연도×보고서)별로 SUE 를 한 번씩만 계산 — 시즌엔 보통 1~3개 그룹이다
    $groups = [];
    foreach ($filings as $f) $groups[$f['bsns_year'] . ':' . $f['reprt_code']] = true;
    $sue = [];
    foreach (array_keys($groups) as $g) {
        [$gy, $grc] = explode(':', $g);
        $sue[$g] = pf_sue_build($pdo, (int)$gy, $grc);
    }

    // 시세 — krx_amt 최신 거래일 스냅샷 + 공시 다음 거래일 진입가(드리프트 실측)
    $lastD = (string)$pdo->query("SELECT MAX(d) FROM krx_amt")->fetchColumn();
    $tds   = $pdo->query("SELECT DISTINCT d FROM krx_amt WHERE d >= DATE_SUB(CURDATE(), INTERVAL " . ($days + 40) . " DAY) ORDER BY d")
                 ->fetchAll(PDO::FETCH_COLUMN);
    $names = [];
    foreach ($pdo->query("SELECT stock_code, stock_name FROM all_stock_info") as $r) {
        $names[$r['stock_code']] = $r['stock_name'];
    }
    $qLast  = $pdo->prepare("SELECT c, amt, mktcap FROM krx_amt WHERE code = ? AND d = ?");
    $qEntry = $pdo->prepare("SELECT c FROM krx_amt WHERE code = ? AND d = ?");

    $rows = [];
    foreach ($filings as $f) {
        $g = $f['bsns_year'] . ':' . $f['reprt_code'];
        $s = $sue[$g][$f['stock_code']] ?? null;
        if ($s === null) continue;                       // SUE 이력 부족(신규상장 등)

        $qLast->execute([$f['stock_code'], $lastD]);
        $mk = $qLast->fetch(PDO::FETCH_ASSOC) ?: null;

        // 진입 = 공시 다음 거래일 종가 (백테스트와 같은 정의). 그날 이후 거래일 수 = 드리프트 경과
        $entryD = null;
        foreach ($tds as $i => $d) if ($d > $f['dt']) { $entryD = $d; $entryIx = $i; break; }
        $drift = null; $elapsed = null;
        if ($entryD !== null && $mk) {
            $qEntry->execute([$f['stock_code'], $entryD]);
            $c0 = (float)($qEntry->fetchColumn() ?: 0);
            if ($c0 > 0 && (float)$mk['c'] > 0) $drift = (float)$mk['c'] / $c0 - 1;
            $elapsed = count($tds) - 1 - $entryIx;
        }
        $liq = $mk !== null && (float)$mk['amt'] >= 1e9;
        $rows[] = [
            'code' => $f['stock_code'], 'name' => $names[$f['stock_code']] ?? $f['stock_code'],
            'dt' => $f['dt'], 'y' => (int)$f['bsns_year'], 'rc' => $f['reprt_code'],
            'sue' => $s['sue'], 'rev_up' => $s['rev_up'], 'quality' => $s['quality'], 'ni_op' => $s['ni_op'],
            'cap' => $mk['mktcap'] ?? null, 'amt' => $mk['amt'] ?? null,
            'drift' => $drift, 'elapsed' => $elapsed,
            'ok' => ($s['sue'] >= 1.0 && $s['quality'] && $s['rev_up'] === true && $liq),
        ];
    }

    // 규칙 충족 먼저 · 그 안에서 SUE 큰 순 — "지금 볼 것"이 맨 위에 오게
    usort($rows, fn($a, $b) => [$b['ok'], $b['sue']] <=> [$a['ok'], $a['sue']]);
    $okN = count(array_filter($rows, fn($r) => $r['ok']));

    /* 표시는 상위 200행 — 시즌의 90일 창은 2천 건이 넘는데, 정렬이 (충족 → SUE 큰 순)이라
     * 잘리는 것은 SUE 하위(볼 일 없는 쪽)뿐이다. ★상한 없이 그리면 박스 판정(boxStatusMany)이
     * 수천 종목의 봉을 읽다 메모리를 터뜨린다 — 실측 90일 창 256MB 초과. */
    $total = count($rows);
    $rows  = array_slice($rows, 0, 200);

    /* 변동성(진입 전 60일 일σ) — 팩터 스윕(2026-08-02)의 두 번째 생존자.
     * 규칙 충족을 변동성 ⅓로 가르면 고변동⅓만 −1.6%(독)이고 저·중은 8년 전부 양수 —
     * 특히 2022 하락장 음수가 고변동에서 왔다. 경계는 절대값이 아니라 <b>목록 내 상대 ⅓</b>
     * (국면 따라 66백분위가 3.8~6.0%로 움직여 절대 임계는 국면이 바뀌면 틀린다 — 백테스트 정의와 동일).
     * ★표시분만 계산(200행 × 봉 61개 — 가볍다). 목록이 30행 미만이면 ⅓ 판정이 노이즈라 게이트 없음. */
    $stVol = $pdo->prepare("SELECT c FROM krx_amt WHERE code = ? AND d <= ? AND c > 0 ORDER BY d DESC LIMIT 61");
    foreach ($rows as &$r) {
        $r['vol'] = null;
        $stVol->execute([$r['code'], $lastD]);
        $px = array_reverse(array_map('floatval', $stVol->fetchAll(PDO::FETCH_COLUMN)));
        if (count($px) < 46) continue;
        $rets = [];
        for ($i = 1; $i < count($px); $i++) $rets[] = $px[$i] / $px[$i - 1] - 1;
        $m = array_sum($rets) / count($rets);
        $var = 0.0;
        foreach ($rets as $x) $var += ($x - $m) ** 2;
        $r['vol'] = sqrt($var / count($rets));
    }
    unset($r);
    $volBound = null;
    $vols = array_values(array_filter(array_map(fn($r) => $r['vol'], $rows), fn($v) => $v !== null));
    if (count($vols) >= 30) {
        sort($vols);
        $volBound = $vols[(int)(count($vols) * 0.66)];
    }
    $okHighVol = 0;
    foreach ($rows as &$r) {
        $r['high_vol'] = ($volBound !== null && $r['vol'] !== null && $r['vol'] > $volBound);
        if ($r['ok'] && $r['high_vol']) $okHighVol++;
    }
    unset($r);

    /* 박스 상태(퀀트 교차) — 각 종목의 최근 최고 거래대금 박스가 지금 어떤 상태인가.
     * 「실적(SUE)으로 고르고 수급(박스)으로 타이밍」 활용 흐름의 다리. 신호 이력이 없으면 '-'
     * (저유동 등 — 판정 불가지 나쁨이 아니다). 배지·툴팁은 퀀트 목록과 같은 boxStatusMany 재사용.
     * ★표시분(≤200종목)만 판정한다 — 위 상한의 이유와 같다. */
    $earnBox = [];
    try {
        $codes = array_values(array_unique(array_map(fn($r) => $r['code'], $rows)));
        if ($codes) {
            $in = implode(',', array_fill(0, count($codes), '?'));
            $sg = $pdo->prepare("SELECT code, MAX(d) d FROM krx_surge WHERE code IN ($in) GROUP BY code");
            $sg->execute($codes);
            $sigs = [];
            foreach ($sg->fetchAll(PDO::FETCH_ASSOC) as $r) $sigs[] = ['code' => $r['code'], 'd' => $r['d']];
            if ($sigs) {
                $bx = (new KrxAmt($pdo))->boxStatusMany($sigs);
                foreach ($sigs as $s) {
                    $b = $bx[$s['code'] . '|' . $s['d']] ?? null;
                    if ($b) $earnBox[$s['code']] = '<span class="bx ' . pf_h($b['st']) . '" title="'
                        . pf_h('최고 거래대금 신호일 ' . $s['d'] . ' — ' . $b['tip']) . '">' . pf_h($b['txt']) . '</span>';
                }
            }
        }
    } catch (Throwable $e) { /* 박스 없이도 목록은 뜬다 (krx_surge 미구축 환경 포함) */ }

    // 배지 색은 퀀트 화면의 정의 그대로 (열이 하나뿐이라 스타일만 가져온다)
    echo '<style>.bx{display:inline-block;padding:2px 7px;border-radius:9px;font-size:12px;font-weight:600;white-space:nowrap}'
       . '.bx-new{background:#eef1f4;color:#567}.bx-in{background:#eef4fb;color:#28527a}'
       . '.bx-brk{background:#e6f4ea;color:#1e7e34}.bx-fake{background:#fdf3e0;color:#b26a00}'
       . '.bx-lad{background:#e8f0fe;color:#1a56b0}.bx-dn{background:#fdecea;color:#c62828}.bx-na{background:#f4f4f4;color:#9aa}</style>';

    echo '<div class="card"><h2>최근 공시 ' . number_format($total) . '건'
       . ' · <span class="up">규칙 충족 ' . $okN . '건</span>'
       . ($okHighVol > 0 ? ' <span class="muted" style="font-size:13px">(그중 고변동⚠ ' . $okHighVol . '건)</span>' : '')
       . ($total > count($rows) ? ' <span class="muted" style="font-size:13px">(SUE 상위 ' . count($rows) . '건만 표시)</span>' : '')
       . '</h2>';
    echo '<p class="sub muted" style="margin:0 0 8px;font-size:12px">'
       . '규칙 = <b>SUE ≥ 1(≈상위 20%) ∧ 품질(영업흑자·순익÷영익≤1.5) ∧ 매출 동반 증가 ∧ 거래대금 10억↑</b>'
       . ' <b>∧ 고변동 아님</b>(목록 내 60일 변동성 상위 ⅓ 제외 — 그 무리만 8년 실측 음수) — '
       . '전부 백테스트 실측 조건입니다. <b>공시후</b>는 공시 다음 거래일 종가에서 지금까지의 수익률, '
       . '<b>경과</b>는 그 뒤 지난 거래일 수입니다 (드리프트 실측 구간은 60거래일).</p>';

    if (!$rows) {
        echo '<p class="muted" style="font-size:13px;margin:0">SUE 를 계산할 수 있는 공시가 없습니다 (이력 4분기 미만 종목만 있음).</p>';
    } else {
        $watched = $pf->watchCodes();
        echo '<div class="tbl-scroll" style="max-height:70vh;overflow-y:auto"><table class="pf"><thead><tr>'
           . '<th class="center" style="width:26px" title="관심종목">☆</th>'
           . '<th>종목</th><th class="num">공시일</th><th>보고서</th><th class="num">SUE</th>'
           . '<th class="num" title="당분기 매출이 전년동기보다 늘었나">매출</th>'
           . '<th class="num" title="YTD 순이익 ÷ 영업이익 — 1.5 초과면 일회성 의심">순÷영</th>'
           . '<th class="num">시총</th><th class="num" title="최근 거래일 거래대금">거래대금</th>'
           . '<th class="num" title="공시 다음 거래일 종가 → 현재">공시후</th>'
           . '<th class="num" title="공시 후 지난 거래일 수 / 드리프트 실측 구간 60일">경과</th>'
           . '<th class="num" title="최근 60거래일 일수익률 표준편차 — 이 목록 안에서 상위 ⅓이면 고변동⚠ (백테스트: 규칙 충족이라도 고변동⅓은 −1.6%로 독)">변동성</th>'
           . '<th class="center" title="그 종목의 최근 최고 거래대금 박스 상태 (퀀트 탭과 같은 판정) — 실적으로 고르고 수급으로 타이밍을 봅니다">박스</th>'
           . '<th>판정</th></tr></thead><tbody>';
        $rcName = ['11013' => '1Q', '11012' => '반기', '11014' => '3Q', '11011' => '연간'];
        foreach ($rows as $r) {
            $won = isset($watched[$r['code']]);
            echo '<tr class="fund-row' . ($won ? ' watched' : '') . '" data-code="' . pf_h($r['code']) . '" style="cursor:pointer" '
               . 'title="클릭하면 재무 상세를 봅니다">';
            // ☆ 는 행 클릭(상세 이동)과 겹치므로 JS 캡처 단계에서 전파를 끊는다 (스크리너와 같은 패턴)
            echo '<td class="center"><button type="button" class="wl-star' . ($won ? ' on' : '') . '"'
               . ' data-code="' . pf_h($r['code']) . '" data-name="' . pf_h($r['name']) . '"'
               . ' title="관심종목에 담기/빼기">' . ($won ? '★' : '☆') . '</button></td>';
            echo '<td><b>' . pf_h($r['name']) . '</b> <span class="muted" style="font-size:11px">' . pf_h($r['code']) . '</span></td>';
            echo '<td class="num">' . pf_h($r['dt']) . '</td>';
            echo '<td>' . $r['y'] . ' ' . ($rcName[$r['rc']] ?? $r['rc']) . '</td>';
            echo '<td class="num">' . ($r['sue'] >= 1.0 ? '<b>' . number_format($r['sue'], 2) . '</b>' : number_format($r['sue'], 2)) . '</td>';
            echo '<td class="num">' . ($r['rev_up'] === null ? '-' : ($r['rev_up'] ? '▲' : '<span class="down">▼</span>')) . '</td>';
            echo '<td class="num">' . ($r['ni_op'] === null ? '<span class="down">적자</span>'
                    : ($r['ni_op'] > 1.5 ? '<b class="down">' . number_format($r['ni_op'], 2) . '</b>' : number_format($r['ni_op'], 2))) . '</td>';
            echo '<td class="num muted">' . pf_eok($r['cap']) . '</td>';
            echo '<td class="num muted">' . ($r['amt'] === null ? '-' : pf_eok($r['amt'])) . '</td>';
            echo '<td class="num">' . ($r['drift'] === null ? '-' : pf_signed_pct($r['drift'], 1)) . '</td>';
            echo '<td class="num">' . ($r['elapsed'] === null ? '-'
                    : ($r['elapsed'] . '일' . ($r['elapsed'] > 60 ? ' <span class="muted">(구간 밖)</span>' : ''))) . '</td>';
            echo '<td class="num">' . ($r['vol'] === null ? '-'
                    : (($r['high_vol'] ? '<b class="down">' : '') . number_format($r['vol'] * 100, 1) . '%' . ($r['high_vol'] ? '</b>' : ''))) . '</td>';
            echo '<td class="center">' . ($earnBox[$r['code']] ?? '<span class="muted" style="font-size:12px" '
                    . 'title="최근 최고 거래대금 신호가 없는 종목 — 판정 불가(나쁨이 아님)">-</span>') . '</td>';
            // 규칙 충족이라도 고변동⅓이면 배지를 갈아 끼운다 — 백테스트에서 그 ⅓만 음수(독)였다
            if ($r['ok'] && $r['high_vol']) {
                echo '<td><span class="badge" style="background:#fff3e0;color:#b26a00" title="'
                   . pf_h('SUE·품질·매출은 충족했지만 변동성이 이 목록 상위 ⅓ — 백테스트(8년)에서 이 무리만 '
                   . '+60일 −1.6%로 음수였습니다. 저·중변동 충족만 8년 전부 양수(2022 하락장 포함).')
                   . '">고변동⚠</span></td>';
            } else {
                echo '<td>' . ($r['ok'] ? '<span class="badge" style="background:#e8f5e9;color:#1b5e20">🟢 규칙 충족</span>'
                        : '<span class="muted" style="font-size:12px">관망</span>') . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '<p class="sub muted" style="margin:9px 0 0;font-size:12px">'
       . '행을 클릭하면 재무 상세를 봅니다. 근거·한계(2019~2026 8년 · 6만 이벤트 실측): '
       . '<b>상위−하위 스프레드는 8년 내내 실재</b>하지만 절대 수익은 시기를 탑니다 — '
       . '<b>2022 하락장에서 규칙 충족은 +60일 −1.2%</b>(시장 −1.6%·쇼크 −3.2%보다 덜 빠지는 <b>상대 방어</b>지 '
       . '절대 수익이 아닙니다). 최근 2년 성적(+4%대)은 임계값을 그 구간에서 정한 몫이 섞여 있으니 보수적으로 읽으세요. '
       . '초과수익의 가장 큰 몫은 시총(시장 국면)이고 SUE 는 <b>같은 시총 안에서의 선별</b>이 검증된 부분입니다. '
       . '비12월 결산(~2%)의 분기보고서는 1Q·3Q 구분이 안 돼 빠집니다.</p>';
    echo '</div>';

    // ☆·행 클릭 — 스크리너와 같은 패턴 (☆ 는 캡처 단계에서 전파를 끊는다)
    echo <<<'JS'
<script>
document.addEventListener('click', function(e){
  var b = e.target.closest ? e.target.closest('.wl-star') : null;
  if (!b) return;
  e.stopPropagation();
  e.preventDefault();
  var body = new URLSearchParams({json:'1', code:b.getAttribute('data-code'), name:b.getAttribute('data-name')});
  fetch('/stock/api.php?module=watch&action=toggle',
    {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body})
    .then(function(r){ return r.json(); })
    .then(function(j){
      if (!j || !j.ok) { alert((j && j.message) || '실패했습니다.'); return; }
      var on = j.message.indexOf('담았습니다') >= 0;
      b.classList.toggle('on', on);
      b.textContent = on ? '★' : '☆';
    })
    .catch(function(){ alert('통신에 실패했습니다.'); });
}, true);
document.addEventListener('click', function(e){
  var tr = e.target.closest ? e.target.closest('tr.fund-row') : null;
  if (!tr || (e.target.closest && (e.target.closest('a') || e.target.closest('.wl-star')))) return;
  location.href = '/stock/index.php?mode=fund&code=' + tr.getAttribute('data-code');
});
</script>
<style>
.wl-star{border:0;background:none;cursor:pointer;font-size:15px;color:#c8d3dd;padding:0;line-height:1}
.wl-star:hover,.wl-star.on{color:#f0a500}
tr.fund-row.watched td{background:#fffbea}
</style>
JS;
    pf_foot();
}

/**
 * 서프라이즈 사례분석 — 퀀트 패턴분석과 같은 방식: 실제 사례를 차트로, 성공과 실패를 나란히.
 *
 * 사례는 전부 백테스트 이벤트(2024-09 이후·접수일 진입·+60거래일 잣대)에서 발굴한 실측이고
 * (`~/_earn_cases.php`), 상장주식수 ±5% 변동 종목은 제외해 차트(네이버 수정주가)와
 * 수익률(KRX 원본가)이 어긋나지 않음을 확인했다. 마커는 날짜에만 스냅하므로 가격 정합 문제가 없다.
 * 분류: 규칙 충족의 성공/실패(승률의 양면) · 고변동⚠ 강등의 이유 · 어닝 쇼크 · 일회성 미끼.
 */
function pf_page_earncase(PDO $pdo, Pf $pf): void
{
    pf_head('재무분석 · 서프라이즈 사례', 'fund', 'wide');
    pf_subtabs('earncase', 'fund');
    pf_flash();
    echo '<style>
.pt-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-top:10px}
@media(max-width:1500px){.pt-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:980px){.pt-grid{grid-template-columns:1fr}}
.pt-case{border:1px solid #dfe6ec;border-radius:10px;padding:10px 12px;background:#fff;min-width:0}
.pt-ct{font-size:14px;margin-bottom:6px}.pt-ct .code{color:#9ab;font-size:12px;margin-left:2px}
.pt-chart{height:340px;position:relative}
.pt-loading{position:absolute;top:45%;left:0;right:0;text-align:center;color:#9ab;font-size:13px}
.pt-note{font-size:13px;line-height:1.7;color:#334;margin-top:8px;border-top:1px dashed #e3e9ef;padding-top:8px}
.pt-badge{display:inline-block;padding:2px 9px;border-radius:10px;font-size:12px;font-weight:700}
.pt-ok{background:#e6f4ea;color:#1e7e34}.pt-bad{background:#fdecea;color:#c62828}
.pt-bait{background:#fdf3e0;color:#b26a00}.pt-warn{background:#fff3cd;color:#8a6d1a}
.pt-def{background:#f4f7fa;border-radius:8px;padding:10px 14px;font-size:13px;line-height:1.8;margin:8px 0}
.pt-act{font-size:13px;font-weight:700;margin-left:8px}
</style>';

    echo '<div class="pf-head"><div><h1>서프라이즈 사례분석</h1>'
       . '<div class="sub"><b>어닝 서프라이즈 규칙</b>이 실제 종목에서 어떻게 작동했는지 — 성공과 실패를 나란히 본다.'
       . ' 모든 사례는 8년 백테스트 이벤트에서 실측 발굴했고, 수익률은 <b>공시 다음 거래일 종가 매수 → +60거래일</b>'
       . ' 기준이다. 목록은 <a href="/stock/index.php?mode=earn">어닝 서프라이즈 탭</a>에서 매 분기 자동으로 뜬다.</div></div></div>';

    /* ── 사례 — 2026-08-02 백테스트 발굴 (~/_earn_cases.php · 상장주식수 ±5% 변동 제외) ── */
    $CASES = [
        // ① 규칙 충족 — 성공
        ['id' => 'e1', 'kind' => 'ok', 'name' => '삼성전기', 'code' => '009150', 'dt' => '2026-03-10',
         'buy' => '2026-03-11', 'exit' => '2026-06-09',
         'note' => '2025년 4분기 영업이익이 자기 평소 출렁임의 <b>7.7배</b>(SUE 7.7)로 튀며 3/10 사업보고서 공시.'
                 . ' 다음날 종가 매수 → +60거래일 <b>+385%</b>(시장 대비 +398%p). ★두 달 뒤(5/12) 퀀트의 매집형'
                 . ' 최고 거래대금 신호도 같은 종목을 잡았다(패턴분석 탭 사례 1) — <b>재무가 먼저 말하고 수급이 뒤따른</b> 교과서.'],
        ['id' => 'e2', 'kind' => 'ok', 'name' => 'SK하이닉스', 'code' => '000660', 'dt' => '2026-03-17',
         'buy' => '2026-03-18', 'exit' => '2026-06-16',
         'note' => 'SUE 5.0 · 3/17 사업보고서 → +60거래일 <b>+125.6%</b>. 온 시장이 지켜보는 대형주조차 발표 당일에'
                 . ' 다 반영되지 않는다 — 공시가 끝이 아니라 <b>드리프트의 시작</b>이라는 PEAD 의 전형.'],
        ['id' => 'e3', 'kind' => 'ok', 'name' => '티엘비', 'code' => '356860', 'dt' => '2025-08-12',
         'buy' => '2025-08-13', 'exit' => '2025-11-13',
         'note' => 'SUE 1.3 — 경계(1.0)를 갓 넘는 수준이어도 품질(순익÷영익 0.62)·매출동반·저변동이 받치면 작동한다.'
                 . ' 8/12 반기 공시 → +60거래일 <b>+162.8%</b>. 화려한 SUE 만 볼 필요가 없다는 사례.'],
        // ① 규칙 충족 — 실패 (정직)
        ['id' => 'e4', 'kind' => 'bad', 'name' => '케이씨텍', 'code' => '281820', 'dt' => '2025-03-18',
         'buy' => '2025-03-19', 'exit' => '2025-06-18',
         'note' => 'SUE 2.1 · 품질 · 매출동반 전부 통과 — 그런데 <b>−29.7%</b>. 규칙 승률 55~58%의 <b>지는 42%가 이런'
                 . ' 얼굴</b>이다. 그래서 이 규칙은 몰빵이 아니라 여러 종목 분산 + 사다리 분할매수와 세트다.'],
        ['id' => 'e5', 'kind' => 'bad', 'name' => '알테오젠', 'code' => '196170', 'dt' => '2025-11-14',
         'buy' => '2025-11-17', 'exit' => '2026-02-12',
         'note' => '조건 전부 통과 후 <b>−28.8%</b>. 이미 크게 오른 고밸류 구간에서는 실적이 좋아도 주가가 쉬어 갈 수'
                 . ' 있다 — SUE 는 이익의 방향을 재지, 밸류에이션은 재지 않는다.'],
        ['id' => 'e6', 'kind' => 'bad', 'name' => '파마리서치', 'code' => '214450', 'dt' => '2025-11-14',
         'buy' => '2025-11-17', 'exit' => '2026-02-12',
         'note' => 'SUE 4.0 — 서프라이즈가 커도 보증이 아니다(<b>−24.2%</b>). 상위 20% 안에서는 SUE 가 크다고 수익이'
                 . ' 더 큰 것도 아니다 — 문턱(상위 20%)을 넘느냐가 중요하지 크기 경쟁이 아니다.'],
        // ② 고변동⚠ 강등
        ['id' => 'e7', 'kind' => 'warn', 'name' => '삼천당제약', 'code' => '000250', 'dt' => '2026-03-20',
         'buy' => '2026-03-23', 'exit' => '2026-06-19',
         'note' => 'SUE 2.6 · 품질 · 매출동반 통과 — 옛 규칙이면 🟢였다. 그러나 일변동성 <b>7.6%(그 분기 상위⅓)</b>'
                 . ' → +60거래일 <b>−73.1%</b>. 규칙 충족 중 고변동⅓만 8년 실측 음수(−1.6%)라는 발견이'
                 . ' 「고변동⚠」 강등 배지가 된 바로 그 자리.'],
        ['id' => 'e8', 'kind' => 'warn', 'name' => '대주전자재료', 'code' => '078600', 'dt' => '2025-03-18',
         'buy' => '2025-03-19', 'exit' => '2025-06-18',
         'note' => '같은 강등 사례 — 조건은 통과했지만 변동성이 그 분기 상위⅓(4.3%)에 걸리자 <b>−37.4%</b>.'
                 . ' 이익이 좋아져도 <b>주가가 복권처럼 움직이는 종목</b>은 서프라이즈 드리프트가 노이즈에 묻힌다.'],
        // ③ 어닝 쇼크
        ['id' => 'e9', 'kind' => 'bad', 'name' => '데브시스터즈', 'code' => '194480', 'dt' => '2026-03-16',
         'buy' => '2026-03-17', 'exit' => '2026-06-15',
         'note' => 'SUE <b>−1.1</b> 어닝 쇼크 → +60거래일 <b>−61.9%</b>. 쇼크도 서프라이즈처럼 <b>천천히</b> 반영된다 —'
                 . ' SUE 하위 20%는 8년간 거의 매년 음수였다. 이 도구의 절반은 「사는 목록」이 아니라 <b>「피하는 목록」</b>이다.'],
        ['id' => 'e10', 'kind' => 'bad', 'name' => '앱클론', 'code' => '174900', 'dt' => '2026-03-13',
         'buy' => '2026-03-16', 'exit' => '2026-06-12',
         'note' => '매출은 늘었는데(▲) 이익이 쇼크 → <b>−66.3%</b>. 매출동반은 서프라이즈의 <b>보조 조건</b>이지'
                 . ' 그 자체로 방어가 아니다 — 이익 방향(SUE)이 항상 첫 번째 질문이다.'],
        // ④ 일회성 미끼 (품질 필터)
        ['id' => 'e11', 'kind' => 'bait', 'name' => '로킷헬스케어', 'code' => '376900', 'dt' => '2026-03-23',
         'buy' => '2026-03-24', 'exit' => '2026-06-22',
         'note' => 'SUE 2.0으로 서프라이즈처럼 보이지만 순이익은 영업이익의 <b>−6.2배</b>(대규모 순손실) —'
                 . ' 품질 필터(|순익÷영익|≤1.5)가 거른 자리다. 실제 +60거래일 <b>−68.2%</b>. 영업이익 한 줄만 보면 속는다.'],
        ['id' => 'e12', 'kind' => 'bait', 'name' => '젬백스', 'code' => '082270', 'dt' => '2026-03-20',
         'buy' => '2026-03-23', 'exit' => '2026-06-19',
         'note' => 'SUE 1.6 + 순익÷영익 <b>−3.0</b> → 화면 판정은 관망 → 실제 <b>−62.8%</b>. 영업이익과 순이익의'
                 . ' 큰 괴리는 <b>방향 불문</b> 경고다(일회성 이익이든 대규모 영업외 손실이든).'],
    ];

    /* 최고 거래대금 박스(퀀트와 같은 정의: 신호일 고가 H·저가 L) — `~/_earn_boxes.php` 실측.
     * 재무(SUE)와 수급(최고 거래대금)이 한 차트에서 겹쳐 보인다 — 삼성전기는 3/11 공시 매수 뒤 4~5월에
     * 박스가 연쇄로 생기는(수급이 뒤따르는) 장면이 그대로 찍힌다. e8·e11 은 신호 이력 없음(박스 없음).
     * ★박스 값은 KRX 원본가 — 각 종목의 박스 구간 상장주식수 ±5% 불변을 확인해 수정주가 차트와 정합. */
    $BOXES = [
        'e1' => [['2026-02-02', 300000, 280000], ['2026-02-19', 363000, 321000], ['2026-02-23', 436500, 392000], ['2026-04-21', 779000, 686000], ['2026-04-23', 796000, 753000], ['2026-05-06', 971000, 898500], ['2026-05-12', 995000, 899000], ['2026-05-26', 1610000, 1447000], ['2026-05-27', 1734000, 1580000], ['2026-05-28', 1880000, 1554000], ['2026-05-29', 2192000, 1912000]],
        'e2' => [['2025-10-02', 404500, 384000], ['2025-11-03', 624000, 555000], ['2025-11-04', 614000, 583000], ['2025-11-05', 587000, 532000], ['2026-01-28', 854000, 803000], ['2026-01-29', 884000, 819000], ['2026-01-30', 931000, 853000], ['2026-03-04', 954000, 846000], ['2026-05-06', 1614000, 1557000], ['2026-05-11', 1949000, 1826000], ['2026-05-12', 1967000, 1804000], ['2026-05-29', 2379000, 2290500]],
        'e3' => [['2025-02-14', 18560, 14850], ['2025-02-17', 22600, 20200], ['2025-03-21', 24800, 19300]],
        'e4' => [['2025-02-13', 40800, 34100]],
        'e5' => [['2025-09-22', 529000, 495500], ['2025-11-04', 563000, 506000], ['2025-12-05', 514000, 433000], ['2026-01-21', 463500, 364000]],
        'e6' => [['2025-06-13', 493500, 431000], ['2026-01-30', 542000, 464500], ['2026-02-05', 389500, 331500]],
        'e7' => [['2026-01-22', 356000, 281500], ['2026-01-23', 375000, 315000], ['2026-02-26', 757000, 574000], ['2026-03-31', 1141000, 829000]],
        'e9' => [['2026-03-25', 40900, 35600], ['2026-03-26', 40500, 33650]],
        'e10' => [['2025-09-29', 18430, 15510], ['2025-10-01', 20350, 18230], ['2025-11-11', 26000, 23600], ['2025-11-17', 31000, 27650], ['2025-11-20', 34900, 31650], ['2025-12-19', 42450, 37800], ['2026-01-02', 50500, 43500], ['2026-01-21', 54500, 48800], ['2026-01-23', 64700, 54500], ['2026-03-13', 94700, 79900], ['2026-03-16', 95800, 82000], ['2026-03-26', 79200, 66900]],
        'e12' => [['2025-11-10', 43250, 38200]],
    ];
    foreach ($CASES as &$c) $c['boxes'] = $BOXES[$c['id']] ?? [];
    unset($c);

    $SECS = [
        ['t' => '① 🟢 규칙 충족 — 성공과 실패', 'ids' => ['e1', 'e2', 'e3', 'e4', 'e5', 'e6'],
         'def' => '<b>규칙</b> = SUE ≥ 1(상위 20%) ∧ 영업흑자·순익÷영익≤1.5 ∧ 매출 동반 증가 ∧ 거래대금 10억↑ ∧ 고변동⅓ 아님.'
                . ' 8년 실측 +60거래일 시장중앙 대비 <b>8년 중 7년 양수 · 승률 55~58%</b>. 아래 성공 3건과 실패 3건이'
                . ' 그 승률의 양면이다 — <b>전형적 결과는 중앙값(+1~2%대)</b>이지 +385%도 −30%도 아니다.'],
        ['t' => '② 고변동⚠ — 왜 강등하는가', 'ids' => ['e7', 'e8'],
         'def' => 'SUE·품질·매출을 다 통과해도 <b>일변동성이 목록 상위 ⅓</b>이면 배지를 ⚠로 강등한다 —'
                . ' 그 무리만 8년 실측 <b>−1.6%(음수)</b>였고, 규칙의 2022년 하락장 손실도 전부 여기서 나왔다.'
                . ' 저변동성은 이번 팩터 스윕에서 가장 견고한 생존자다(저변동⅓ 연 7년 전부 양수).'],
        ['t' => '③ 어닝 쇼크 — 피하는 목록', 'ids' => ['e9', 'e10'],
         'def' => 'SUE <b>하위 20%</b>(대략 −1 이하)는 +60거래일 <b>−2.9%·거의 매년 음수</b> — 가장 견고한 결과다.'
                . ' 보유 종목이 어닝 쇼크를 내면 「기다리면 회복하겠지」가 아니라 <b>쇼크도 두 달을 드리프트한다</b>고 읽어야 한다.'],
        ['t' => '④ 일회성 미끼 — 품질 필터가 거르는 것', 'ids' => ['e11', 'e12'],
         'def' => 'SUE 가 높아도 <b>순이익과 영업이익의 괴리가 크면</b>(|순익÷영익| &gt; 1.5) 무효 — 자산매각·평가이익 같은'
                . ' 일회성이거나 대규모 영업외 손실이 숨어 있다. 품질 미달 무리는 실측 <b>음수</b>(스크리너의 저PER 함정과 같은 뿌리).'],
    ];
    $byId = [];
    foreach ($CASES as $c) $byId[$c['id']] = $c;
    $KB = ['ok' => ['pt-ok', '성공'], 'bad' => ['pt-bad', '실패'], 'warn' => ['pt-warn', '고변동⚠'], 'bait' => ['pt-bait', '미끼']];

    foreach ($SECS as $s) {
        echo '<div class="card"><h2>' . $s['t'] . '</h2><div class="pt-def">' . $s['def'] . '</div><div class="pt-grid">';
        foreach ($s['ids'] as $id) {
            $c = $byId[$id];
            [$bc, $bt] = $KB[$c['kind']];
            echo '<div class="pt-case"><div class="pt-ct"><span class="pt-badge ' . $bc . '">' . $bt . '</span> '
               . '<b>' . pf_h($c['name']) . '</b><span class="code">' . pf_h($c['code']) . '</span>'
               . '<span class="code" style="margin-left:6px">공시 ' . pf_h($c['dt']) . '</span></div>'
               . '<div class="pt-chart" id="ec_' . $c['id'] . '"><div class="pt-loading">차트 준비 중…</div></div>'
               . '<div class="pt-note">' . $c['note'] . '</div></div>';
        }
        echo '</div></div>';
    }

    echo '<div class="card"><h2>사례 읽는 법 (중요)</h2><div style="font-size:13px;line-height:1.8">'
       . '· 여기 실린 사례는 각 무리의 <b>상·하위 극단</b>이다 — 눈에 잘 보이라고 골랐다. <b>전형적 결과는 중앙값</b>'
       . '(규칙 충족 +60일 +1~2%대)이지 +385%나 −73%가 아니다.<br>'
       . '· 수익률은 전부 <b>공시 다음 거래일 종가 매수 → +60거래일, KRX 원장 실측</b>이다. 차트는 네이버 수정주가라'
       . ' 값이 다를 수 있는데, 이 12종목은 사례 구간에 상장주식수 변동(±5%)이 없음을 확인해 정합이 맞다 —'
       . ' 사례를 추가할 때는 반드시 재확인.<br>'
       . '· 차트의 <b>박스는 퀀트의 최고 거래대금 박스</b>(신호일 고가 H = 저항 · 저가 L = 지지)다 — 회색은 지나간'
       . ' 박스(계단), 노랑은 마지막 박스. 재무(공시 마커)와 수급(박스)이 한 화면에서 겹쳐 보인다. 박스가 없는 사례는'
       . ' 최고 거래대금 신호가 없었던 종목이다.<br>'
       . '· 근거 분포(6만 이벤트 · 8년 · 기간분할)와 한계(최근 2년 in-sample 성격 · 2022 상대 방어)는'
       . ' <a href="/stock/index.php?mode=earn">어닝 서프라이즈 탭</a> 하단에 있다.<br>'
       . '· 오늘 시장의 신호는 같은 탭이 매 분기 자동으로 보여 준다 — 이 페이지는 그 배지를 <b>믿어도 되는 이유</b>다.</div></div>';

    /* ── 차트 — dailychart.js 재사용. 마커는 날짜 스냅뿐이라 수정주가 정합 문제가 없다 ── */
    echo '<script src="/style/dailychart.js?v=27"></script>';
    echo '<script>const EC_CASES=' . json_encode($CASES, JSON_UNESCAPED_UNICODE) . ';</script>';
    echo <<<'JS'
<script>
DailyChart.load().then(function () {
  function renderCase(c) {
    var host = document.getElementById('ec_' + c.id);
    var dc = DailyChart.create('ec_' + c.id, { theme: 'light' });
    if (!dc) { if (host) host.textContent = '차트 라이브러리를 불러오지 못했습니다.'; return Promise.resolve(); }
    return DailyChart.fetchDaily(c.code, 480).then(function (rows) {
      if (!rows.length) { host.textContent = '일봉 데이터를 가져오지 못했습니다.'; return; }
      var ld = host.querySelector('.pt-loading'); if (ld) ld.remove();
      dc.setData(rows);
      var last = rows[rows.length - 1].time;
      function snap(d) { for (var i = 0; i < rows.length; i++) if (rows[i].time >= d) return rows[i].time; return last; }
      /* 최고 거래대금 박스 — 퀀트 패턴분석과 같은 그리기: 각 박스는 다음 박스가 생길 때까지(계단 모양),
         마지막 박스는 노랑으로 화면 끝까지. 재무 신호(공시 마커)와 수급 신호(박스)가 한 화면에서 겹친다. */
      var bs = (c.boxes || []).map(function (b, i) {
        var lastOne = (i === c.boxes.length - 1);
        return lastOne
          ? { from: snap(b[0]), to: last, top: b[1], bottom: b[2], fill: 'rgba(240,165,0,0.10)',
              topColor: '#d33', bottomColor: '#1565c0', sideColor: '#c9b37e', label: '최고 거래대금 박스' }
          : { from: snap(b[0]), to: snap(c.boxes[i + 1][0]), top: b[1], bottom: b[2],
              fill: 'rgba(110,135,160,0.09)', topColor: 'rgba(211,51,51,0.45)',
              bottomColor: 'rgba(21,101,192,0.45)', topW: 1, bottomW: 1 };
      });
      if (bs.length) dc.setBoxes(bs);
      dc.setMarkers([{ time: c.buy, text: '공시매수' }, { time: c.exit, sell: true, text: '+60일' }]);
      var iBuy = rows.findIndex(function (r) { return r.time >= c.buy; });
      if (iBuy < 0) iBuy = rows.length - 1;
      var iEnd = rows.findIndex(function (r) { return r.time >= c.exit; });
      if (iEnd < 0) iEnd = rows.length - 1;
      iEnd = Math.min(rows.length - 1, iEnd + 15);
      dc.zoomRange(rows[Math.max(0, iBuy - 40)].time, rows[iEnd].time, { minBars: 40, pad: 2 });
    }).catch(function () { host.textContent = '차트를 불러오지 못했습니다.'; });
  }
  /* 지연 로드 + 동시 2개 — 열자마자 네이버 일봉을 한꺼번에 때리지 않게 (패턴분석과 같은 방식) */
  var active = 0, waitq = [];
  function kick(c) {
    if (active >= 2) { waitq.push(c); return; }
    active++;
    renderCase(c).then(function () { active--; if (waitq.length) kick(waitq.shift()); });
  }
  var pending = {};
  EC_CASES.forEach(function (c) { pending[c.id] = c; });
  if (window.IntersectionObserver) {
    var io = new IntersectionObserver(function (es) {
      es.forEach(function (en) {
        if (!en.isIntersecting) return;
        var id = en.target.id.slice(3);
        var c = pending[id];
        if (!c) return;
        delete pending[id];
        io.unobserve(en.target);
        kick(c);
      });
    }, { rootMargin: '500px 0px' });
    EC_CASES.forEach(function (c) {
      var h = document.getElementById('ec_' + c.id);
      if (h) io.observe(h); else delete pending[c.id];
    });
  } else {
    EC_CASES.forEach(kick);
  }
});
</script>
JS;
    pf_foot();
}

function pf_page_fund(PDO $pdo, Pf $pf): void
{
    require_once $_SERVER['DOCUMENT_ROOT'] . '/env/dart.inc';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/env/krx.inc';
    $dart = new Dart($pdo);
    $dart->ensureTables();
    $krx = new Krx($pdo);
    $krx->ensureTables();
    $krxDate = $krx->lastDate();      // PER·PBR·시총의 기준일 (없으면 그 열이 빈다)

    $code = preg_replace('/[^0-9]/', '', (string)($_GET['code'] ?? ''));
    if (preg_match('/^\d{6}$/', $code)) { pf_page_fund_detail($pdo, $dart, $code, $pf); return; }

    $bases = $dart->reportBases();
    if (!$bases) {
        pf_head('재무분석', 'fund');
        pf_subtabs('screener', 'fund');
        pf_flash();
        echo '<div class="pf-head"><div><h1>재무분석</h1></div></div>';
        echo '<div class="warn">아직 수집된 재무제표가 없습니다. '
           . 'DART 에서 먼저 받아야 합니다.</div>';
        pf_foot(); return;
    }

    /* 기준 = (사업연도 × 보고서). 연간을 고르면 그 해 사업보고서,
     * 분기를 고르면 그 분기 기준 최근 4분기(TTM)로 본다.
     *
     * 기본은 <b>가장 최근 연간</b>이다 — 지금까지 쓰던 기준이라 옛 북마크(y=…만 있는 주소)가 그대로 열린다.
     * TTM 은 아직 결산이 끝나지 않은 기간이라, 고르는 것은 사용자 몫으로 둔다. */
    $have = [];
    foreach ($bases as $b) $have[(int)$b['bsns_year'] . ':' . $b['reprt_code']] = true;

    /* 고를 수 있는 기준만 추린다.
     * TTM 은 <b>전년 사업보고서</b>와 <b>전년 같은 분기</b>가 둘 다 있어야 만들어진다
     * (그 둘을 빼고 더하는 식이라 하나만 없어도 결과가 통째로 0행이 된다).
     * 가장 오래된 해의 분기가 그렇다 — 분기는 2016년부터라 2016년 기준 TTM 은 만들 수 없다.
     * 수집 현황 표는 $bases 원본을 그대로 쓴다 — 거기선 "받았는지"가 알고 싶은 것이라 다르다. */
    $pick = [];
    foreach ($bases as $b) {
        $by = (int)$b['bsns_year']; $rc = (string)$b['reprt_code'];
        if ($rc !== Dart::REPRT_ANNUAL
            && (!isset($have[($by - 1) . ':' . $rc]) || !isset($have[($by - 1) . ':' . Dart::REPRT_ANNUAL]))) continue;
        $pick[] = $b;
    }
    if (!$pick) $pick = $bases;

    $valid = [];
    foreach ($pick as $b) $valid[(int)$b['bsns_year'] . ':' . $b['reprt_code']] = true;

    $year  = (int)($_GET['y']  ?? 0);
    $reprt = (string)($_GET['rc'] ?? Dart::REPRT_ANNUAL);
    if (!isset($valid[$year . ':' . $reprt])) {
        $reprt = Dart::REPRT_ANNUAL;
        $year  = 0;
        foreach ($pick as $b) {
            if ($b['reprt_code'] === Dart::REPRT_ANNUAL) { $year = (int)$b['bsns_year']; break; }
        }
        if (!$year) { $year = (int)$pick[0]['bsns_year']; $reprt = (string)$pick[0]['reprt_code']; }
    }
    $isTtm    = ($reprt !== Dart::REPRT_ANNUAL);
    $basisLbl = $isTtm
        ? $year . '년 ' . Dart::reprtName($reprt) . ' 기준 최근 4분기(TTM)'
        : $year . '년 연간 기준';

    // ── 조건 읽기 (빈 칸은 조건 없음)
    $q = [];
    foreach (pf_fund_filters() as [$k]) {
        $v = trim((string)($_GET[$k] ?? ''));
        $q[$k] = ($v === '') ? null : (float)str_replace(',', '', $v);
    }
    /* 영업흑자만·거래종목만·스팩제외는 <b>화면에서 뺐다</b> — 늘 켜 두는 편이 맞다.
     * 끄면 상장폐지 종목과 껍데기 회사가 섞여 목록이 못 쓰게 되고, 백테스트에서도
     * 이 셋은 모두 성적을 올렸다. 조건 칸이 열한 개라 체크박스 셋이 줄을 넘기게 만들기도 했다.
     *
     * 다만 주소에 `profit=0` 처럼 명시하면 끌 수 있게 남겨 둔다 —
     * 적자 턴어라운드를 찾는 것 같은 예외가 생기면 그때 주소로 열면 된다. */
    $profitOnly = (($_GET['profit'] ?? '1') !== '0');
    $listedOnly = (($_GET['listed'] ?? '1') !== '0');
    $noSpac     = (($_GET['nospac'] ?? '1') !== '0');
    $sort       = (string)($_GET['sort'] ?? 'roe');
    $desc       = ((string)($_GET['dir'] ?? 'desc') !== 'asc');

    // ── 거르기. 3천 행 남짓이라 PHP 에서 도는 편이 조건을 붙이기 쉽다.
    //    TTM 은 전년 자료가 있어야 만들어지므로 신규상장 종목은 여기서 빠진다.
    $src = $isTtm
        ? $dart->screenRowsTtm($year, $reprt, $listedOnly)
        : $dart->screenRows($year, Dart::REPRT_ANNUAL, $listedOnly);

    // SUE 는 기준 (연도×보고서)가 가리키는 당분기에서 계산한다 — 연간 기준이면 그 해 4분기
    $sueMap = pf_sue_map($pdo, $year, $reprt);

    $rows = [];
    foreach ($src as $r) {
        $m = Dart::ratio($r);
        $r['sue'] = $sueMap[$r['stock_code']] ?? null;

        /* 주가·시총은 ratio() 가 실제로 쓴 값으로 갈아끼운다.
         * 필터와 표시가 PER 과 <b>같은 주가</b>를 봐야 한다 — 저장된 KRX 시총을 그대로 두면
         * "시총 1조 이상 & PER 10 이하" 가 서로 다른 날짜를 보게 된다. */
        $r['close_prc'] = $m['price'];
        $r['mktcap']    = $m['mktcap'];

        if ($profitOnly && (float)$r['op_income'] <= 0) continue;
        // 스팩은 껍데기라 매출이 없고 이자수익만 있어 PER·ROE 가 엉뚱하게 잡힌다
        if ($noSpac && Dart::isSpac($r['corp_name'])) continue;
        if ($q['cap']    !== null && (float)$r['mktcap']  < $q['cap'] * 100000000) continue;
        if ($q['rev']    !== null && (float)$r['revenue'] < $q['rev'] * 100000000) continue;
        if ($q['per']    !== null && ($m['per'] === null || $m['per'] > $q['per'])) continue;
        if ($q['pbr']    !== null && ($m['pbr'] === null || $m['pbr'] > $q['pbr'])) continue;
        if ($q['opm']    !== null && ($m['op_margin']  === null || $m['op_margin']  * 100 < $q['opm']))    continue;
        if ($q['npm']    !== null && ($m['net_margin'] === null || $m['net_margin'] * 100 < $q['npm']))    continue;
        if ($q['roe']    !== null && ($m['roe']        === null || $m['roe']        * 100 < $q['roe']))    continue;
        if ($q['growth'] !== null && ($m['rev_growth'] === null || $m['rev_growth'] * 100 < $q['growth'])) continue;
        if ($q['debt']   !== null && ($m['debt_ratio'] === null || $m['debt_ratio'] * 100 > $q['debt']))   continue;
        if ($q['cur']    !== null && ($m['cur_ratio']  === null || $m['cur_ratio']  * 100 < $q['cur']))    continue;
        // 순익÷영익은 배수라 100 을 곱하지 않는다 (다른 조건들과 단위가 다르다)
        if ($q['nio']    !== null && ($m['ni_op']      === null || $m['ni_op'] > $q['nio']))               continue;
        // SUE 도 σ 배수 그대로 견준다 — 이력 부족(null)은 조건을 걸면 빠진다 (모르는 값 통과 금지)
        if ($q['sue']    !== null && ($r['sue']        === null || $r['sue'] < $q['sue']))                 continue;
        /* 낙폭(dd_hi)은 음수 비율이라 부호를 뒤집어 "몇 % 빠졌나"로 견준다.
         * 고가·저가가 아직 없는 종목(크론이 안 돈 신규상장 등)은 조건을 걸면 빠진다 —
         * 모르는 값을 통과시키면 조건이 조용히 헐거워진다. */
        if ($q['dd']     !== null && ($m['dd_hi']      === null || -$m['dd_hi'] * 100 < $q['dd']))         continue;
        if ($q['rb']     !== null && ($m['rb_lo']      === null ||  $m['rb_lo'] * 100 > $q['rb']))         continue;

        $rows[] = $r + $m;
    }

    // ── 정렬. null 은 항상 뒤로 보낸다 (없는 값이 1등으로 올라오면 안 된다)
    $sortable = ['mktcap' => '시총', 'revenue' => '매출액', 'op_income' => '영업이익',
                 'op_margin' => '영업이익률', 'net_margin' => '순이익률', 'roe' => 'ROE',
                 'rev_growth' => '매출성장률', 'debt_ratio' => '부채비율', 'cur_ratio' => '유동비율',
                 'per' => 'PER', 'pbr' => 'PBR', 'ni_op' => '순익÷영익', 'sue' => 'SUE',
                 'dd_hi' => '고점대비', 'rb_lo' => '저점대비'];
    if (!isset($sortable[$sort])) $sort = 'roe';

    /* 관심종목을 <b>정렬 1순위</b>로 올린다.
     *
     * 조건을 바꿔 가며 볼 때 담아 둔 종목이 이번 조건에도 걸리는지가 제일 궁금한데,
     * 목록 아래 어딘가에 묻혀 있으면 확인할 길이 없다. 맨 위로 올려 두면
     * <b>상위 N개 자르기에서도 절대 빠지지 않는다</b>.
     * 조건에 안 맞는 관심종목은 여기 없다 — 그건 「관심종목」 화면이 맡는다. */
    $watched = $pf->watchCodes();

    usort($rows, function ($a, $b) use ($sort, $desc, $watched) {
        $wa = isset($watched[$a['stock_code']]) ? 0 : 1;
        $wb = isset($watched[$b['stock_code']]) ? 0 : 1;
        if ($wa !== $wb) return $wa <=> $wb;          // 1순위 — 담아 둔 것 먼저

        $x = $a[$sort]; $y = $b[$sort];               // 2순위 — 고른 정렬 기준
        if ($x === null && $y === null) return 0;
        if ($x === null) return 1;
        if ($y === null) return -1;
        return $desc ? ($y <=> $x) : ($x <=> $y);
    });

    $total    = count($rows);
    $watchHit = count(array_filter($rows, fn($x) => isset($watched[$x['stock_code']])));
    // 몇 종목이 KRX 종가로 대체됐는지 — 자르기 전, 조건을 통과한 것만 센다
    $navFb = count(array_filter($rows, fn($x) => ($x['price_src'] ?? '') !== 'naver'));
    // 고점·저점 열이 채워진 종목 수와 마지막 수집 시각 — 비어 있으면 왜 비었는지 알려 줘야 한다
    $rngN    = count(array_filter($rows, fn($x) => $x['dd_hi'] !== null));
    $rngDate = (string)$pdo->query("SELECT MAX(updated_at) FROM stock_price_range")->fetchColumn();

    /* 상위 50개만 보여 준다.
     * 300개는 스크롤로도 다 못 보는 수라 사실상 "안 본 목록"이었다.
     * 거르기·정렬은 <b>전체 모집단</b>에서 이미 끝난 뒤라, 자르는 것은 표시뿐이다 —
     * 조건을 좁히면 원하는 종목이 이 안으로 들어온다. */
    $rows  = array_slice($rows, 0, PF_FUND_TOP);

    /* ══ 화면
     * 폭은 기본(1440)이다 — 대시보드처럼 'wide'(2200)를 쓰면 초고해상도에서 열이 과하게 벌어진다.
     * 목록(13열)과 종목상세(18열)를 같은 폭으로 둬야 오가며 볼 때 표가 흔들리지 않는다. */
    pf_head('재무분석', 'fund');
    pf_subtabs('screener', 'fund');
    pf_flash();

    echo '<div class="pf-head"><div><h1>재무분석</h1>';
    echo '<div class="sub">DART 사업보고서에서 받은 전종목 재무제표로 조건에 맞는 종목을 찾습니다. '
       . '비율은 저장하지 않고 <b>금액에서 매번 계산</b>합니다 — 재무제표를 다시 받으면 숫자도 바로 따라옵니다.</div></div>';

    echo '<div class="act">';

    /* 조건 검색과 나란히 두는 <b>다른 입구</b> — 조건에 안 걸리는 종목을 바로 연다.
     * 조건 폼 안이 아니라 여기(폼 바깥)에 있어야 엔터가 스크리너 검색과 섞이지 않는다. */
    pf_fund_jump('fj');

    // KRX 는 당일 자료를 바로 주지 않는다 — 최근 거래일을 받아 두면 PER·PBR·시총이 채워진다
    echo '<form class="inline" method="post" action="/stock/api.php?module=krx&action=collect">';
    echo '<button class="btn btn-outline" type="submit" title="KRX 오픈API 에서 최근 거래일의 시세·상장주식수를 받아옵니다">'
       . '↻ 시세 갱신' . ($krxDate !== '' ? ' <span class="muted" style="font-size:11px">(' . pf_h($krxDate) . ')</span>' : '')
       . '</button></form></div>';
    echo '</div>';

    /* ── 저장한 조건 배지
     *
     * 조건만 담고 기준(y·rc)은 담지 않는다 — 배지를 누르면 <b>보고 있던 기준은 그대로</b> 두고
     * 조건만 갈아 끼운다. 그래야 "같은 조건을 2018년 기준으로도 걸어 보기"가 드롭다운 한 번으로 된다. */
    $condQs = pf_fund_cond_qs($q, $profitOnly, $listedOnly, $noSpac, $sort, $desc);
    $presets = $pf->presetList();

    echo '<div class="card" style="padding-top:12px;padding-bottom:12px">';
    echo '<div class="preset-row">';
    echo '<span class="preset-lab">저장한 조건</span>';
    if (!$presets) {
        echo '<span class="muted" style="font-size:12px">아직 없습니다 — 조건을 넣고 검색한 뒤 '
           . '<b>조건 저장</b>을 누르면 여기 배지로 남습니다.</span>';
    }
    foreach ($presets as $p) {
        $href = '/stock/index.php?mode=fund&y=' . $year . '&rc=' . pf_h($reprt) . '&go=1&' . $p['cond_qs'];
        $on   = ($p['cond_qs'] === $condQs);
        echo '<span class="preset' . ($on ? ' on' : '') . '">'
           . '<a href="' . pf_h($href) . '" title="' . pf_h($p['cond_qs']) . '">' . pf_h($p['name']) . '</a>'
           . '<button type="button" class="preset-x" data-id="' . (int)$p['id'] . '" '
           . 'data-name="' . pf_h($p['name']) . '" title="지우기">×</button></span>';
    }
    echo '<button type="button" class="btn btn-outline btn-sm preset-save" '
       . 'data-cond="' . pf_h($condQs) . '">＋ 조건 저장</button>';
    echo '</div></div>';

    // ── 조건 폼
    echo '<div class="card">';
    echo '<form method="get" action="/stock/index.php" class="fld-row">';
    echo '<input type="hidden" name="mode" value="fund">';
    echo '<input type="hidden" name="go" value="1">';
    echo '<input type="hidden" name="sort" value="' . pf_h($sort) . '">';
    echo '<input type="hidden" name="dir" value="' . ($desc ? 'desc' : 'asc') . '">';

    /* 기준 하나로 연도와 보고서를 같이 고른다 — 값은 "2025:11011" 꼴이고 아래 JS 가 y/rc 로 쪼갠다.
     * 두 개의 select 로 나누면 "2026년 × 연간"처럼 있지도 않은 조합을 고를 수 있다. */
    echo '<input type="hidden" name="y"  id="fundY"  value="' . $year . '">';
    echo '<input type="hidden" name="rc" id="fundRc" value="' . pf_h($reprt) . '">';
    echo '<label class="fld" title="분기를 고르면 그 분기 기준 최근 4분기(TTM)로 계산합니다">'
       . '<span class="fl-lab">기준</span><select id="fundBasis" style="width:172px">';

    $optAnnual = $optTtm = '';
    foreach ($pick as $b) {
        $by = (int)$b['bsns_year']; $bc = (string)$b['reprt_code'];
        $sel = ($by === $year && $bc === $reprt) ? ' selected' : '';
        $o   = '<option value="' . $by . ':' . pf_h($bc) . '"' . $sel . '>';
        if ($bc === Dart::REPRT_ANNUAL) {
            $optAnnual .= $o . $by . '년 연간 (' . number_format((int)$b['n']) . ')</option>';
        } else {
            $optTtm .= $o . $by . '년 ' . Dart::reprtName($bc) . ' 기준 TTM (' . number_format((int)$b['n']) . ')</option>';
        }
    }
    echo '<optgroup label="사업보고서 (연간)">' . $optAnnual . '</optgroup>';
    if ($optTtm !== '') echo '<optgroup label="최근 4분기 (TTM)">' . $optTtm . '</optgroup>';
    echo '</select></label>';

    /* 입력값은 대개 두세 자리(10 · 100 · 1000)라 칸을 넓게 둘 이유가 없다.
     * 좁혀야 조건 열한 개가 한 줄에 들어간다 — 줄이 넘어가면 조건 전체를 한눈에 못 본다. */
    foreach (pf_fund_filters() as [$k, $lab, $dir, $unit, , $help]) {
        echo '<label class="fld fld-num" title="' . pf_h($help) . '">'
           . '<span class="fl-lab">' . pf_h($lab)
           . '<i>' . ($dir === 'min' ? '≥' : '≤') . $unit . '</i></span>'
           . '<input type="text" inputmode="decimal" name="' . $k . '" value="'
           . pf_h($q[$k] === null ? '' : rtrim(rtrim(number_format($q[$k], 2, '.', ''), '0'), '.')) . '"></label>';
    }

    // 영업흑자만·거래종목만·스팩제외는 늘 켜 둔다 (위 주석 참조) — 주소로 끈 경우에만 폼에 실어 유지한다
    foreach (['profit' => $profitOnly, 'listed' => $listedOnly, 'nospac' => $noSpac] as $k => $on) {
        if (!$on) echo '<input type="hidden" name="' . $k . '" value="0">';
    }
    echo '<button class="btn btn-primary" type="submit">검색</button>';
    echo '<a class="btn btn-outline" href="/stock/index.php?mode=fund&y=' . $year . '&rc=' . pf_h($reprt) . '">조건 지우기</a>';
    echo '</form>';

    echo '<p class="sub muted" style="margin:9px 0 0;font-size:12px">'
       . '<b>' . number_format($total) . '종목</b>이 조건에 맞습니다'
       . ($total > PF_FUND_TOP ? ' (정렬 기준 <b>상위 ' . PF_FUND_TOP . '개</b>만 표시)' : '')
       . ($watchHit > 0 ? ' · <b class="up">관심종목 ' . $watchHit . '개</b>를 맨 위로 올렸습니다' : '')
       . ' · <b>' . pf_h($basisLbl) . '</b>'
       . ($isTtm ? ' (전년 연간 ＋ 당해 누적 － 전년 동기 누적)' : '')
       . ' · 금융업은 매출액 계정이 없어 매출 관련 조건에서 빠집니다'
       // 주가와 주식수의 출처·시점이 다르므로 둘 다 밝힌다. 하나로 뭉뚱그리면 기준을 오해한다.
       . ' · 주가는 <b>네이버 실시간</b>'
       . ($navFb > 0 ? ' <span class="muted">(' . number_format($navFb) . '종목은 KRX 종가로 대체 — 거래정지 등)</span>' : '')
       . ($krxDate !== ''
            ? ' · 상장주식수는 <b>KRX ' . pf_h($krxDate) . '</b> 기준'
              . ($listedOnly ? ' (그날 거래된 종목만)' : ' · <b class="down">미거래 종목 포함</b>')
            : ' · <b class="down">KRX 상장주식수가 아직 없습니다</b> — PER·PBR·시총이 비어 있습니다')
       // 고점·저점은 크론이 미리 채워 두는 값이라, 언제 채웠는지를 밝혀야 믿고 쓸 수 있다
       . ($rngDate !== ''
            ? ' · 고점·저점은 <b>최근 6개월 네이버 일봉</b> (' . pf_h(substr($rngDate, 0, 16)) . ' 수집'
              . ($total > 0 && $rngN < $total ? ' · ' . number_format($total - $rngN) . '종목 미수집' : '') . ')'
            : ' · <b class="down">6개월 고점·저점이 아직 없습니다</b> — '
              . '<code>cron_job.php task=dart_range_full</code> 를 한 번 돌리세요')
       . '</p>';
    echo '</div>';

    // ── 결과
    echo '<div class="card"><h2>검색 결과</h2>';
    if (!$rows) {
        echo '<p class="muted" style="font-size:13px;margin:0">조건에 맞는 종목이 없습니다. 조건을 느슨하게 해 보세요.</p>';
    } else {
        $head = function (string $key, string $label) use ($sort, $desc, $year, $reprt) {
            $d   = ($sort === $key && $desc) ? 'asc' : 'desc';
            $qs  = $_GET;
            $qs['mode'] = 'fund'; $qs['y'] = $year; $qs['rc'] = $reprt;
            $qs['sort'] = $key; $qs['dir'] = $d;
            $arw = ($sort === $key) ? ($desc ? ' ▾' : ' ▴') : '';
            return '<th class="num"><a href="/stock/index.php?' . pf_h(http_build_query($qs)) . '">'
                 . pf_h($label) . $arw . '</a></th>';
        };

        echo '<div class="tbl-scroll" style="max-height:70vh;overflow-y:auto"><table class="pf"><thead><tr>';
        echo '<th class="center" style="width:26px" title="관심종목">☆</th>';
        echo '<th>종목</th>';
        echo $head('mktcap', '시총');
        echo $head('per', 'PER');
        echo $head('pbr', 'PBR');
        // 시세 두 열은 PER·PBR 옆에 둔다 — 셋 다 "지금 주가"를 보는 열이라 같이 읽힌다
        echo $head('dd_hi', '고점대비');
        echo $head('rb_lo', '저점대비');
        echo $head('revenue', '매출액' . ($isTtm ? ' TTM' : ''));
        echo $head('op_income', '영업이익' . ($isTtm ? ' TTM' : ''));
        echo $head('op_margin', '영업이익률');
        echo $head('net_margin', '순이익률');
        echo $head('roe', 'ROE');
        echo $head('rev_growth', '매출성장률');
        echo $head('sue', 'SUE');
        echo $head('ni_op', '순익÷영익');
        echo $head('debt_ratio', '부채비율');
        echo $head('cur_ratio', '유동비율');
        echo '<th class="num">기준</th></tr></thead><tbody>';

        foreach ($rows as $r) {
            // 담아 둔 종목은 맨 위에 모여 있으므로 옅은 배경으로 경계를 보인다
            $on = isset($watched[$r['stock_code']]);
            echo '<tr class="fund-row' . ($on ? ' watched' : '') . '" data-code="' . pf_h($r['stock_code'])
               . '" title="클릭하면 연도별 재무를 봅니다">';
            // ☆ 는 행 클릭(상세 이동)과 겹치므로 JS 에서 전파를 끊는다
            echo '<td class="center"><button type="button" class="wl-star' . ($on ? ' on' : '') . '"'
               . ' data-code="' . pf_h($r['stock_code']) . '"'
               . ' data-name="' . pf_h($r['corp_name'] ?: $r['stock_code']) . '"'
               . ' title="관심종목에 담기/빼기">' . ($on ? '★' : '☆') . '</button></td>';
            echo '<td><b>' . pf_h($r['corp_name'] ?: $r['stock_code']) . '</b> '
               . '<span class="muted" style="font-size:11px">' . pf_h($r['stock_code']) . '</span>'
               // 필터를 껐을 때만 나타난다 — 시세에 없는 종목은 상장폐지일 가능성이 높다
               . (empty($r['listed']) ? ' <span class="badge st-closed" title="KRX 최근 거래일에 없습니다">미거래</span>' : '')
               . '</td>';
            echo '<td class="num muted">' . pf_eok($r['mktcap']) . '</td>';
            echo '<td class="num">' . ($r['per'] === null ? '-' : number_format($r['per'], 2)) . '</td>';
            echo '<td class="num">' . ($r['pbr'] === null ? '-' : number_format($r['pbr'], 2)) . '</td>';

            /* 고점대비 · 저점대비 — 색을 칠하지 않는다.
             * 낙폭은 늘 음수, 상승률은 늘 양수라 부호로 색을 주면 모든 행이 빨강·파랑으로 갈려
             * "좋다/나쁘다"로 잘못 읽힌다. 여기서 큰 낙폭은 오히려 찾고 있던 것이다. */
            $rngTip = ($r['hi_6m'] && $r['lo_6m'])
                ? '6개월 최고 ' . number_format((float)$r['hi_6m']) . '원(' . pf_h((string)$r['hi_date']) . ')'
                  . ' · 최저 ' . number_format((float)$r['lo_6m']) . '원(' . pf_h((string)$r['lo_date']) . ')'
                  . ' · 현재 ' . number_format((float)$r['close_prc']) . '원'
                : '6개월 고가·저가가 없습니다 — 장기 거래정지이거나 아직 수집하지 않은 종목입니다';
            $ddTd = ' class="num"' . ($rngTip !== '' ? ' title="' . pf_h($rngTip) . '"' : '');
            echo '<td' . $ddTd . '>' . ($r['dd_hi'] === null ? '-'
                    : ($r['dd_hi'] > -0.0005 ? '<b>신고가</b>' : number_format($r['dd_hi'] * 100, 1) . '%')) . '</td>';
            echo '<td' . $ddTd . '>' . ($r['rb_lo'] === null ? '-'
                    : ($r['rb_lo'] < 0.0005 ? '<b>신저가</b>' : '+' . number_format($r['rb_lo'] * 100, 1) . '%')) . '</td>';

            echo '<td class="num">' . pf_eok($r['revenue']) . '</td>';
            echo '<td class="num">' . pf_eok($r['op_income']) . '</td>';
            echo '<td class="num">' . pf_signed_pct($r['op_margin']) . '</td>';
            echo '<td class="num">' . pf_signed_pct($r['net_margin']) . '</td>';
            echo '<td class="num">' . pf_signed_pct($r['roe']) . '</td>';
            echo '<td class="num">' . pf_signed_pct($r['rev_growth']) . '</td>';
            /* SUE — 이익 서프라이즈. 상위 20% 경계(≈1) 이상만 굵게. 색은 칠하지 않는다 —
             * 표의 색은 등락에 쓰고 있어서, 같은 빨강이 두 뜻이 되면 못 읽는다. */
            $sue = $r['sue'];
            echo '<td class="num"' . ($sue !== null && $sue >= 1.0
                    ? ' title="이익 서프라이즈 상위 20% 수준 — 백테스트에서 +60거래일 시장중앙 대비 +3.6%"'
                    : '') . '>'
               . ($sue === null ? '-'
                    : ($sue >= 1.0 ? '<b>' . number_format($sue, 2) . '</b>' : number_format($sue, 2)))
               . '</td>';
            /* 순익÷영익 — 1.5 를 넘으면 일회성 이익이 섞였을 수 있다는 뜻이라 눈에 띄게 둔다.
             * 값 자체는 중립이므로 색을 칠하지 않고, 의심 구간만 표시한다. */
            $nio = $r['ni_op'];
            echo '<td class="num"' . ($nio !== null && $nio > 1.5
                    ? ' title="순이익이 영업이익의 ' . number_format($nio, 2) . '배 — 자산매각 같은 일회성 이익일 수 있습니다"'
                    : '') . '>'
               . ($nio === null ? '-'
                    : ($nio > 1.5 ? '<b class="down">' . number_format($nio, 2) . '</b>'
                                  : number_format($nio, 2)))
               . '</td>';
            echo '<td class="num">' . ($r['debt_ratio'] === null ? '-' : pf_h(pf_pct0($r['debt_ratio'], 0))) . '</td>';
            echo '<td class="num">' . ($r['cur_ratio']  === null ? '-' : pf_h(pf_pct0($r['cur_ratio'], 0))) . '</td>';
            echo '<td class="num muted" style="font-size:11px">' . ($r['fs_div'] === 'CFS' ? '연결' : '별도') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="sub muted" style="margin:9px 0 0;font-size:12px">'
           . '<b>행을 클릭</b>하면 그 종목의 연도별·분기별 재무를 봅니다. 금액 단위는 <b>억원</b>입니다. '
           . '자본총계가 0 이하(자본잠식)면 ROE·부채비율은 <b>-</b> 로 둡니다 — 계산하면 숫자가 거짓말을 합니다.<br>'
           . '<b>순익÷영익</b>이 <b class="down">1.5 를 넘으면</b> 자산매각 같은 <b>일회성 이익</b>이 섞였을 수 있습니다 — '
           . '그런 해엔 PER 이 뚝 떨어져 싸 보이지만 이듬해 원위치합니다. '
           . '<b>SUE</b>는 기준 분기의 <b>(당분기 영업이익 − 전년동기) ÷ 자기 과거 2년 변동성</b> — '
           . '이익이 "좋아지는 중"인 강도입니다. 백테스트(2019~2026 · 8년 · 접수일 진입)에서 상위 20%(대략 1 이상)와 '
           . '하위 20%의 스프레드가 <b>8년 내내 유지</b>됐고(+60일 기준 상위−하위 +3.5%p·최근 2년은 상위 단독 +3.8%), '
           . '같은 시총 안에서도 성립합니다. 단 <b>품질 없이 SUE 만 걸면 무효</b>입니다(순익÷영익 초과 무리는 음수) — '
           . '<b>순익÷영익 ≤1.5 · 매출성장률 ≥0</b> 과 함께 거세요. 이력 4분기 미만(신규상장 등)은 <b>-</b> 입니다.<br>'
           . '<b>고점대비</b>는 최근 6개월 <b>장중 최고가</b>에서 지금 주가가 몇 % 빠져 있는지, '
           . '<b>저점대비</b>는 <b>장중 최저가</b>에서 몇 % 올라와 있는지입니다 — '
           . '종가가 아니라 실제로 그 값이 있었던 자리를 씁니다. '
           . '고가·저가는 하루 한 번 받아 두고 <b>낙폭은 볼 때마다 실시간 주가로 다시 계산</b>합니다. '
           . '오늘 장중에 6개월 고점을 뚫었으면 저장된 고가 대신 지금 주가를 고점으로 잡아 <b>신고가</b>로 표시합니다.<br>'
           . '실측(4개 연도 백테스트)에서 <b>PER 만 낮은 종목</b>은 5년 뒤 영업이익 중앙값이 '
           . '−32%·−32%·−39%·−39% 로 <b>네 번 모두</b> 전체 평균(+34~+44%)에 크게 졌습니다. '
           . 'PER 을 걸 때는 <b>영업이익률과 함께</b> 거세요.<br>'
           . ($isTtm
                ? 'TTM 은 <b>전년 사업보고서(12개월) ＋ 당해 누적 － 전년 동기 누적</b> 입니다. '
                  . '자산·부채·자본은 흐름이 아니라 시점값이라 더하지 않고 <b>' . $year . '년 '
                  . pf_h(Dart::reprtName($reprt)) . '말</b> 값을 그대로 씁니다 — ROE 분모도 그 값입니다. '
                  . '성장률은 <b>한 해 전 같은 분기의 TTM</b> 과 견준 것입니다. '
                  . '전년 자료가 없는 종목(신규상장 등)은 TTM 을 만들 수 없어 목록에서 빠집니다.<br>'
                : '')
           . 'PER·PBR 은 <b>네이버 실시간 주가 ÷ (순이익·자본총계 ÷ 지금 주식수)</b> 로 계산하고, '
           . '<b>시가총액도 그 주가 × 상장주식수</b>로 다시 만듭니다 — 시총 조건과 PER 조건이 '
           . '서로 다른 시점을 보면 안 되기 때문입니다. '
           . '주가는 네이버(장중 5~10회 갱신), 상장주식수와 거래종목 판정은 KRX 가 맡습니다 — '
           . '네이버엔 상장주식수 컬럼이 없고, KRX 주가는 T+1 이라 하루 묵습니다. '
           . '네이버에 없거나 갱신이 3일 넘게 멈춘 종목만 KRX 종가로 대체합니다. '
           . '주가가 지금 값이므로 분모도 지금 주식수를 씁니다 — 액면분할이 있었던 종목에서 '
           . '그 해 주식수로 나누면 PER 이 수십 배 찌그러집니다. '
           . '자기주식이 빠진 유통주식수는 <b>주식 수 체계가 그대로인 종목에 한해</b> 씁니다. '
           . '적자·자본잠식이면 계산하지 않습니다.</p>';
    }
    echo '</div>';

    pf_fund_collect_card($dart, $bases, $krxDate);

    echo <<<'JS'
<script>
// ☆ 담기/빼기 — 행 클릭(상세 이동)보다 먼저 잡아 전파를 끊는다
document.addEventListener('click', function(e){
  var b = e.target.closest ? e.target.closest('.wl-star') : null;
  if (!b) return;
  e.stopPropagation();
  e.preventDefault();
  var body = new URLSearchParams({json:'1', code:b.getAttribute('data-code'), name:b.getAttribute('data-name')});
  fetch('/stock/api.php?module=watch&action=toggle',
    {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body})
    .then(function(r){ return r.json(); })
    .then(function(j){
      if (!j || !j.ok) { alert((j && j.message) || '실패했습니다.'); return; }
      var on = j.message.indexOf('담았습니다') >= 0;
      b.classList.toggle('on', on);
      b.textContent = on ? '★' : '☆';
    })
    .catch(function(){ alert('통신에 실패했습니다.'); });
}, true);

document.addEventListener('click', function(e){
  var tr = e.target.closest ? e.target.closest('tr.fund-row') : null;
  if (!tr || (e.target.closest && (e.target.closest('a') || e.target.closest('.wl-star')))) return;
  location.href = '/stock/index.php?mode=fund&code=' + tr.getAttribute('data-code');
});
// 기준 select 는 "연도:보고서" 한 값이라, 폼이 실제로 실어 보내는 y·rc 로 쪼개 넣는다
(function(){
  var b = document.getElementById('fundBasis');
  if (!b) return;
  b.addEventListener('change', function(){
    var p = b.value.split(':');
    document.getElementById('fundY').value  = p[0];
    document.getElementById('fundRc').value = p[1];
    b.form.submit();
  });
})();

// 조건 저장·삭제 — 폼 POST 로 보내고 화면을 다시 그린다(배지 목록이 바뀌므로)
function pfPresetPost(action, data){
  var f = document.createElement('form');
  f.method = 'post';
  f.action = '/stock/api.php?module=preset&action=' + action;
  data.back = location.href.replace(/[?&]msg=[^&]*/, '');
  Object.keys(data).forEach(function(k){
    var i = document.createElement('input');
    i.type = 'hidden'; i.name = k; i.value = data[k];
    f.appendChild(i);
  });
  document.body.appendChild(f);
  f.submit();
}
document.addEventListener('click', function(e){
  var s = e.target.closest && e.target.closest('.preset-save');
  if (s) {
    var cond = s.getAttribute('data-cond') || '';
    // 정렬(sort·dir)만 남았으면 거른 것이 하나도 없다는 뜻이다
    if (!cond || /^sort=/.test(cond)) {
      alert('저장할 조건이 없습니다.\n값을 하나 이상 넣고 검색한 뒤 저장하세요.');
      return;
    }
    var name = prompt('이 조건을 뭐라고 부를까요?\n(같은 이름이면 덮어씁니다)', '');
    if (name === null) return;
    pfPresetPost('save', { name: name, cond: cond });
    return;
  }
  var x = e.target.closest && e.target.closest('.preset-x');
  if (x) {
    e.preventDefault();
    if (!confirm('「' + x.getAttribute('data-name') + '」 조건을 지울까요?')) return;
    pfPresetPost('del', { id: x.getAttribute('data-id') });
  }
});
</script>
<style>
tr.fund-row{cursor:pointer}
tr.fund-row:hover{background:#f2f8fd}
/* 담아 둔 종목 — 맨 위에 모여 있으므로 옅은 노랑으로 경계를 보인다 */
tr.fund-row.watched{background:#fffbef}
tr.fund-row.watched:hover{background:#fff5da}
tr.fund-row.watched td:first-child{box-shadow:inset 3px 0 0 #f0a500}
.wl-star{border:0;background:none;cursor:pointer;font-size:15px;color:#c8d3dd;padding:0;line-height:1}
.wl-star:hover{color:#f0a500}
.wl-star.on{color:#f0a500}

/* 조건 줄 — 열한 개를 한 줄에 담는다. 값이 두세 자리라 칸은 좁아도 된다 */
.fld-row .fld-num{gap:3px}
.fld-row .fl-lab{display:block;font-size:11.5px;font-weight:700;color:#5f7183;white-space:nowrap;line-height:1.2}
.fld-row .fl-lab i{font-style:normal;font-weight:600;color:#9aa7b4;margin-left:2px}
.fld-row .fld-num input{width:62px;text-align:right;font-variant-numeric:tabular-nums}
@media(max-width:1500px){ .fld-row .fld-num input{width:56px} .fld-row{gap:7px} }

/* 저장한 조건 배지 */
.preset-row{display:flex;flex-wrap:wrap;align-items:center;gap:6px}
.preset-lab{font-size:12px;font-weight:700;color:#5f7183;margin-right:2px}
.preset{display:inline-flex;align-items:center;border:1px solid #cfdce8;border-radius:20px;
  background:#f4f8fc;overflow:hidden}
.preset a{padding:5px 4px 5px 12px;font-size:12.5px;font-weight:700;color:#1d5c93;text-decoration:none}
.preset:hover{border-color:#1d5c93;background:#e8f1fa}
/* 지금 걸려 있는 조건과 같으면 채워서 보인다 */
.preset.on{background:#1d5c93;border-color:#1d5c93}
.preset.on a{color:#fff}
.preset-x{border:0;background:none;cursor:pointer;color:#9aa7b4;font-size:14px;
  padding:3px 9px 4px 4px;line-height:1}
.preset-x:hover{color:#c62828}
.preset.on .preset-x{color:#bcd6ee}
.preset.on .preset-x:hover{color:#fff}
</style>
JS;

    pf_foot();
}

/**
 * 관심종목 — 재무분석에서 담아 둔 종목을 모아 본다.
 *
 * 지표는 담을 때 값을 저장하지 않고 <b>최신 재무로 매번 계산</b>한다(비율 무저장).
 * 저장하는 것은 「담은 날」과 「담을 때 주가」뿐이다 — 그 뒤 얼마나 움직였는지가 이 화면의 알맹이다.
 */
function pf_page_watch(PDO $pdo, Pf $pf): void
{
    require_once $_SERVER['DOCUMENT_ROOT'] . '/env/dart.inc';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/env/krx.inc';
    $dart = new Dart($pdo);
    $dart->ensureTables();

    // 담아 둔 종목의 시세도 최신으로 (보유 종목과 같은 규칙 — 낡은 것만 네이버에서 채운다)
    $rows = $pf->watchList();
    if ($rows) {
        $pf->refreshQuotes(array_column($rows, 'stock_code'));
        $rows = $pf->watchList();
    }

    /* 지표는 가장 최근 사업연도 기준. 재무분석 목록의 기본값과 같게 둔다 —
     * 두 화면에서 같은 종목의 PER 이 다르게 보이면 안 된다.
     * ★ 전종목을 읽으므로 <b>담은 게 없으면 아예 부르지 않는다</b>.
     *   빈 화면을 그리려고 2,700행을 읽던 것이 처음 버전의 실수였다. */
    $year = 0;
    $fin  = [];
    if ($rows) {
        foreach ($dart->reportBases() as $b) {
            if ($b['reprt_code'] === Dart::REPRT_ANNUAL) { $year = (int)$b['bsns_year']; break; }
        }
        if ($year) {
            foreach ($dart->screenRows($year, Dart::REPRT_ANNUAL, false) as $r) $fin[$r['stock_code']] = $r;
        }
    }

    pf_head('관심종목', 'fund');       // 상단은 재무분석, 하위 탭에서 관심종목
    pf_subtabs('watch', 'fund');
    pf_flash();

    echo '<div class="pf-head"><div><h1>관심종목</h1>';
    echo '<div class="sub">재무분석에서 담아 둔 종목입니다. 지표는 저장하지 않고 '
       . ($year ? '<b>' . $year . '년 사업보고서</b>로 ' : '')
       . '매번 계산합니다 — 재무가 갱신되면 숫자도 따라옵니다.</div></div>';
    echo '<div class="act"><a class="btn btn-outline" href="/stock/index.php?mode=fund">＋ 재무분석에서 찾기</a></div>';
    echo '</div>';

    if (!$rows) {
        echo '<div class="warn">아직 담아 둔 종목이 없습니다. '
           . '<a href="/stock/index.php?mode=fund">재무분석</a>에서 조건에 맞는 종목을 찾아 '
           . '<b>☆</b> 를 누르면 여기 모입니다.</div>';
        pf_foot(); return;
    }

    echo '<div class="card"><h2>담아 둔 종목 ' . count($rows) . '개</h2>';
    echo '<div class="tbl-scroll"><table class="pf pos"><thead><tr>';
    foreach ([['', 'center'], ['종목명', ''], ['담은날', 'num'], ['담을때가', 'num'], ['현재가', 'num'],
              ['담은뒤', 'num'], ['PER', 'num'], ['PBR', 'num'], ['ROE', 'num'],
              ['영업이익률', 'num'], ['매출액', 'num'], ['메모', '']] as [$l, $cl]) {
        echo '<th class="' . $cl . '">' . pf_h($l) . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($rows as $w) {
        $code = $w['stock_code'];
        $f    = $fin[$code] ?? null;
        $m    = $f ? Dart::ratio($f) : null;

        $now   = (float)($w['last_price'] ?? 0);
        $added = (float)($w['added_price'] ?? 0);
        // 담은 뒤 등락 — 담을 때 가격을 못 잡았으면(네이버에 없던 종목) 비워 둔다
        $chg   = ($now > 0 && $added > 0) ? $now / $added - 1 : null;

        echo '<tr>';
        echo '<td class="center"><button type="button" class="wl-del" data-code="' . pf_h($code)
           . '" title="관심종목에서 빼기">★</button></td>';
        echo '<td class="stk"><a href="/stock/index.php?mode=fund&code=' . pf_h($code) . '">'
           . pf_h($w['stock_name']) . '</a><span class="code">' . pf_h($code) . '</span></td>';
        echo '<td class="num muted">' . pf_h(substr((string)$w['added_at'], 2, 8)) . '</td>';
        echo '<td class="num muted">' . pf_n($added ?: null) . '</td>';
        echo '<td class="num">' . pf_n($now ?: null) . '</td>';
        echo '<td class="num">' . pf_signed_pct($chg) . '</td>';
        echo '<td class="num">' . ($m && $m['per'] !== null ? number_format($m['per'], 2) : '-') . '</td>';
        echo '<td class="num">' . ($m && $m['pbr'] !== null ? number_format($m['pbr'], 2) : '-') . '</td>';
        echo '<td class="num">' . ($m ? pf_ratio_pct($m['roe']) : '-') . '</td>';
        echo '<td class="num">' . ($m ? pf_ratio_pct($m['op_margin']) : '-') . '</td>';
        echo '<td class="num muted">' . ($f ? pf_eok($f['revenue']) : '-') . '</td>';
        echo '<td><input type="text" class="wl-memo" data-code="' . pf_h($code) . '" value="'
           . pf_h($w['memo']) . '" placeholder="왜 담았는지" maxlength="200"></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';

    echo '<p class="sub muted" style="margin:9px 0 0;font-size:12px">'
       . '<b>★</b> 을 누르면 목록에서 뺍니다. 메모는 칸을 벗어나면 저장됩니다. '
       . '종목명을 누르면 그 종목의 연도별·분기별 재무를 봅니다.<br>'
       . '「담은뒤」는 <b>담을 때 주가 대비</b> 등락입니다 — 여기서 고른 판단이 맞았는지 되짚는 자리입니다. '
       . 'PER·ROE 등은 담을 때 값이 아니라 <b>지금</b> 값입니다.</p>';
    echo '</div>';

    echo <<<'JS'
<script>
function wlPost(action, data){
  var body = new URLSearchParams(Object.assign({json:'1'}, data));
  return fetch('/stock/api.php?module=watch&action=' + action,
    {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body})
    .then(function(r){ return r.json(); });
}
document.addEventListener('click', function(e){
  var b = e.target.closest && e.target.closest('.wl-del');
  if (!b) return;
  var tr = b.closest('tr');
  wlPost('del', {code: b.getAttribute('data-code')})
    .then(function(j){ if (j && j.ok) tr.remove(); else alert((j && j.message) || '실패했습니다.'); })
    .catch(function(){ alert('통신에 실패했습니다.'); });
});
// 메모는 칸을 벗어날 때만 보낸다 (글자마다 보내면 요청이 쏟아진다)
document.addEventListener('focusout', function(e){
  var i = e.target;
  if (!i.classList || !i.classList.contains('wl-memo')) return;
  if (i.value === i.defaultValue) return;
  wlPost('memo', {code: i.getAttribute('data-code'), memo: i.value})
    .then(function(j){ if (j && j.ok) i.defaultValue = i.value; else alert((j && j.message) || '실패했습니다.'); });
});
</script>
<style>
.wl-del{border:0;background:none;cursor:pointer;font-size:16px;color:#f0a500;padding:0 2px;line-height:1}
.wl-del:hover{color:#c62828}
.wl-memo{width:100%;min-width:150px;border:1px solid #e3eaf0;border-radius:6px;padding:5px 7px;font-size:12px}
.wl-memo:focus{outline:none;border-color:#1d5c93}
</style>
JS;

    pf_foot();
}

/**
 * 수집 현황 — 어느 연도·보고서가 채워졌는지 한눈에 보고, 빈 곳을 그 자리에서 받는다.
 *
 * 여기 버튼은 <b>한 번에 한 덩어리</b>만 받는다. 화면 요청이 견딜 수 있는 길이여야 하기 때문이다.
 *   분기 재무제표 : 100종목씩 묶어 부르므로 한 (연도 × 보고서) 가 40회·20초쯤
 *   주식수        : 다중회사 API 가 없어 종목마다 1회 — 300종목이 1분쯤
 * 11년치를 통째로 받는 것 같은 긴 작업은 서버에서 cron/dart_collect.php (cron_job.php?task=dart_*) 로 돌린다.
 */
function pf_fund_collect_card(Dart $dart, array $bases, string $krxDate): void
{
    // (연도 → 보고서 → 종목수) · 갱신 시각은 따로 모은다
    $grid = $upd = [];
    foreach ($bases as $b) {
        $grid[(int)$b['bsns_year']][$b['reprt_code']] = (int)$b['n'];
        $upd[(int)$b['bsns_year']][$b['reprt_code']]  = (string)($b['updated_at'] ?? '');
    }
    krsort($grid);

    // 매일 도는 갱신이 실제로 돌고 있는지 — 가장 최근 갱신 시각으로 본다
    $last = '';
    foreach ($upd as $c) foreach ($c as $t) if ($t > $last) $last = $t;
    $ago  = $last !== '' ? (int)floor((time() - strtotime($last)) / 86400) : -1;

    $shares = [];
    foreach ($dart->sharesStatus() as $s) $shares[(int)$s['bsns_year']] = $s;

    $codes = ['11013' => '1분기', '11012' => '반기', '11014' => '3분기', '11011' => '연간'];

    echo '<div class="card"><h2>수집 현황</h2>';

    /* 갱신이 멈춰 있으면 화면 숫자는 멀쩡해 보이는데 속이 옛날 것이다.
     * 그게 제일 위험하므로 맨 위에 못 박아 둔다. */
    if ($ago < 0) {
        echo '<div class="warn" style="margin-bottom:10px">아직 한 번도 수집하지 않았습니다.</div>';
    } elseif ($ago <= 1) {
        echo '<p class="sub" style="margin:0 0 10px;font-size:12px">'
           . '<b class="up">자동 갱신 정상</b> — 마지막 갱신 ' . pf_h(substr($last, 0, 16))
           . ' <span class="muted">(' . ($ago === 0 ? '오늘' : '어제') . ')</span></p>';
    } else {
        echo '<div class="warn" style="margin-bottom:10px">'
           . '마지막 갱신이 <b>' . $ago . '일 전</b>(' . pf_h(substr($last, 0, 16)) . ')입니다. '
           . '매일 도는 크론이 멈춰 있을 수 있습니다.</div>';
    }

    /* KRX 기준일이 며칠 묵었는지.
     * KRX 는 T+1 인데 다음 날 <b>몇 시에</b> 올라오는지가 공개돼 있지 않다 —
     * 실측으로 아는 것은 01:19 엔 없고 11:58 엔 있다는 것뿐이다.
     * 그래서 크론 시각이 이른 편이면 여기가 2일로 뜬다. 그때 크론을 오후로 옮기면 된다. */
    if ($krxDate !== '') {
        $kAgo = (int)floor((strtotime(date('Y-m-d')) - strtotime($krxDate)) / 86400);
        $wk   = (int)date('N');                       // 월요일이면 금요일치가 최신이라 3일이 정상이다
        $ok   = $kAgo <= ($wk === 1 ? 3 : ($wk === 7 ? 2 : 1));
        echo '<p class="sub" style="margin:0 0 10px;font-size:12px">'
           . 'KRX 상장주식수 기준일 <b>' . pf_h($krxDate) . '</b> '
           . '<span class="muted">(' . $kAgo . '일 전)</span> — '
           . ($ok ? '<b class="up">정상</b>'
                  : '<b class="down">한 박자 늦습니다</b> · KRX 는 다음 날 오전에 올라옵니다. '
                    . '<code>job=krx</code> 크론이 그보다 이른 시각이면 오후로 옮기세요')
           . '</p>';
    }

    echo '<div class="tbl-scroll"><table class="pf" style="max-width:720px"><thead><tr>';
    echo '<th class="num">사업연도</th>';
    foreach ($codes as $lab) echo '<th class="num">' . pf_h($lab) . '</th>';
    echo '<th class="num">주식수</th></tr></thead><tbody>';

    foreach ($grid as $y => $c) {
        echo '<tr><td class="num"><b>' . $y . '</b></td>';
        foreach ($codes as $rc => $lab) {
            $n = $c[$rc] ?? 0;
            // 분기보고서는 2016년부터다. 그 전 해의 빈칸은 "안 받은 것"이 아니라 "없는 것"이다
            $none = ($rc !== Dart::REPRT_ANNUAL && $y < Dart::MIN_QUARTER_YEAR);
            $tip  = ($upd[$y][$rc] ?? '') !== ''
                  ? ' title="마지막 갱신 ' . pf_h(substr($upd[$y][$rc], 0, 16)) . '"' : '';
            echo '<td class="num"' . $tip . '>' . ($n ? number_format($n)
                 : ($none ? '<span class="muted" style="font-size:11px">없음</span>'
                          : '<span class="muted">–</span>')) . '</td>';
        }
        $s = $shares[$y] ?? null;
        if (!$s || !(int)$s['total']) {
            echo '<td class="num muted">–</td>';
        } else {
            $pct = (int)round((int)$s['done'] * 100 / (int)$s['total']);
            echo '<td class="num">' . number_format((int)$s['ok']) . ' <span class="muted" style="font-size:11px">('
               . $pct . '%)</span></td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';

    // ── 받기
    $yearOpts = '';
    foreach (array_keys($grid) as $y) $yearOpts .= '<option value="' . $y . '">' . $y . '년</option>';

    echo '<div class="fld-row" style="margin-top:12px">';

    echo '<form class="inline fld-row" method="post" action="/stock/api.php?module=dart&action=quarter" style="gap:6px">';
    echo '<label class="fld">재무제표 받기<select name="year" style="width:100px">' . $yearOpts . '</select></label>';
    echo '<label class="fld">&nbsp;<select name="rc" style="width:100px">';
    foreach ($codes as $rc => $lab) echo '<option value="' . $rc . '">' . pf_h($lab) . '</option>';
    echo '</select></label>';
    echo '<label class="fld">&nbsp;<button class="btn btn-outline" type="submit" '
       . 'title="그 연도·보고서의 전종목 재무제표를 DART 에서 받습니다 (20초쯤 걸립니다)">받기</button></label>';
    echo '</form>';

    echo '<form class="inline fld-row" method="post" action="/stock/api.php?module=dart&action=shares" style="gap:6px">';
    echo '<label class="fld">주식수 채우기<select name="year" style="width:100px">' . $yearOpts . '</select></label>';
    echo '<input type="hidden" name="limit" value="300">';
    echo '<label class="fld">&nbsp;<button class="btn btn-outline" type="submit" '
       . 'title="그 해 사업보고서의 유통·발행주식수를 받습니다. 한 번에 300종목씩이라 다 찰 때까지 여러 번 누릅니다 (1분쯤)">'
       . '300종목 받기</button></label>';
    echo '</form>';

    echo '</div>';

    echo '<p class="sub muted" style="margin:10px 0 0;font-size:12px">'
       . '<b>평소에는 손댈 일이 없습니다</b> — 크론 두 줄이 알아서 받습니다. '
       . '<code>job=fresh</code>(새벽)가 DART 재무의 최신 5개 슬롯을, '
       . '<code>job=krx</code>(오전·오후)가 거래소 시세와 상장주식수를 받습니다. '
       . '위 버튼과 <b>↻ 시세 갱신</b>은 <b>그걸 기다리지 않고 지금 받고 싶을 때</b> 씁니다.<br>'
       . '<b>KRX 는 T+1</b> 입니다 — 어제 종가가 오늘 <b>오전에야</b> 올라옵니다. 그래서 KRX 크론만 '
       . '새벽이 아니라 오전 이후에 두고, 올라오는 시각을 몰라도 그날 안에 잡히도록 하루 두 번 돌립니다. '
       . '시세는 최근 며칠치만 남기고 지웁니다 — 화면이 쓰는 것은 가장 최근 하루뿐이고, '
       . '옛날 것이 필요하면 KRX 가 2년 전까지 언제든 다시 줍니다.<br>'
       . '공시는 마감일 하루에 72~80% 가 몰리지만 <b>거기서 끝나지 않습니다</b> — 마감일까지 누적 97% 안팎이고 '
       . '나머지 2~3%(60~90종목)가 그 뒤 2주에 걸쳐 들어옵니다. 사업보고서는 주총 일정 때문에 '
       . '3월 중순부터 넓게 퍼지고 꼬리가 4월 말까지 갑니다. 결산월이 12월이 아닌 회사는 '
       . '연중 아무 때나 냅니다. 그래서 <b>한 번 받고 끝내지 않고 매일 다시 받습니다</b> '
       . '(5슬롯 × 40회 = 200회로 DART 하루 한도의 1%).<br>'
       . '<b>분기보고서는 2016년부터</b> 제공됩니다. 손익은 DART 가 <b>누적</b>으로 신고하므로 그대로 저장하고, '
       . '분기별 3개월 값과 TTM 은 볼 때 뺄셈으로 만듭니다 — 저장은 원본 하나뿐입니다.<br>'
       . '<b>주식수</b>는 다중회사 API 가 없어 종목마다 한 번씩 불러야 합니다(그 해 유통·발행주식수). '
       . '이미 채운 종목은 건너뛰므로 다 찰 때까지 여러 번 누르면 됩니다. '
       . '여러 해를 통째로 받는 것처럼 긴 작업은 서버에서 '
       . '<code>php cron_job.php task=dart_shares budget=1200</code> 으로 조각내 돌립니다.</p>';
    echo '</div>';
}

/** 한 종목의 연도별 재무 */
function pf_page_fund_detail(PDO $pdo, Dart $dart, string $code, ?Pf $pf = null): void
{
    $series = $dart->financialSeries($code);
    $name   = $dart->corpName($code);
    $funds  = Dart::adjust($dart->rows($code));       // DART 가 신고한 EPS·BPS·DPS (받아 둔 종목만)
    $byYear = [];
    foreach ($funds as $f) $byYear[(int)$f['bsns_year']] = $f;

    /* EPS·BPS 는 <b>여기서 계산</b>한다 — stock_fundamental(DART 신고값)은 종목별 API 호출이 필요해
     * 3,058종목 중 14종목(0.5%)밖에 없어서 대부분 빈칸이었다.
     *
     * 순이익·자본총계는 이미 전종목 다 있고, 분모(주식수)만 있으면 되므로 <b>추가 호출이 0회</b>다.
     * ★ 분모를 <b>지금 상장주식수</b>로 쓰면 액면분할이 저절로 보정된다 —
     *   삼성전자 2017 EPS 가 그해 주식수로는 352,472원(분할 전)이라 시계열이 끊기는데,
     *   지금 주식수로는 7,216원이라 앞뒤가 이어진다.
     * ★ 스크리너 PER 이 쓰는 분모와 같아져 두 화면이 어긋나지 않는다. */
    $nowShrs = $dart->listedShares($code);

    // 폭은 목록 화면과 같은 기본(1440) — 오가며 볼 때 표가 흔들리지 않아야 한다
    pf_head('재무분석 · ' . ($name ?: $code), 'fund');
    pf_subtabs('screener', 'fund');    // 상세는 스크리너에서 들어오는 자리다
    pf_flash();

    echo '<div class="pf-head"><div><h1>' . pf_h($name ?: $code)
       . ' <span class="muted" style="font-size:14px;font-weight:600">' . pf_h($code) . '</span></h1>';
    echo '<div class="sub">DART 사업보고서 기준 <b>연도별</b> 재무와, 분기보고서에서 만든 <b>분기별</b> 추이입니다. '
       . '금액 단위는 <b>억원</b>.</div></div>';
    echo '<div class="act">';

    // 상세를 보다 다른 종목이 궁금해지는 일이 잦다 — 목록으로 돌아갔다 올 것 없이 여기서 바로 옮겨 간다
    pf_fund_jump('fjd');

    if ($pf) {
        // 여기가 실제로 "담을지" 정하는 자리다 — 목록의 ☆ 와 같은 토글을 크게 둔다
        $on = isset($pf->watchCodes()[$code]);
        echo '<button type="button" class="btn ' . ($on ? 'btn-primary' : 'btn-outline') . ' wl-star2"'
           . ' data-code="' . pf_h($code) . '" data-name="' . pf_h($name ?: $code) . '">'
           . ($on ? '★ 관심종목' : '☆ 관심종목') . '</button> ';
    }
    echo '<a class="btn btn-outline" href="/stock/index.php?mode=fund">← 재무분석</a></div>';
    echo '</div>';

    if (!$series) {
        echo '<div class="warn">이 종목의 재무제표가 아직 없습니다.</div>';
        pf_foot(); return;
    }

    echo '<div class="card"><h2>연도별 재무</h2>';
    echo '<div class="tbl-scroll"><table class="pf"><thead><tr>';
    echo '<th class="num">연도</th><th class="num">매출액</th><th class="num">매출 증감</th>'
       . '<th class="num">영업이익</th><th class="num">영업이익 증감</th>'
       . '<th class="num">영업이익률</th><th class="num">순이익</th><th class="num">순이익률</th>'
       . '<th class="num">ROE</th><th class="num">자산총계</th><th class="num">부채총계</th>'
       . '<th class="num">자본총계</th><th class="num">부채비율</th><th class="num">유동비율</th>'
       . '<th class="num">EPS</th><th class="num">BPS</th><th class="num">DPS</th>'
       . '<th class="num">기준</th></tr></thead><tbody>';

    // 성장률을 내려면 전년 값이 필요하다 — 연도 내림차순이라 다음 행이 전년이다
    foreach ($series as $i => $r) {
        $prev = $series[$i + 1] ?? null;
        $r['prev_revenue']   = $prev['revenue']   ?? null;
        $r['prev_op_income'] = $prev['op_income'] ?? null;
        $r['list_shrs']      = $nowShrs;          // ratio() 가 EPS·BPS 분모로 쓴다
        $m  = Dart::ratio($r);
        $fd = $byYear[(int)$r['bsns_year']] ?? null;

        echo '<tr>';
        echo '<td class="num"><b>' . (int)$r['bsns_year'] . '</b></td>';
        echo '<td class="num">' . pf_eok($r['revenue']) . '</td>';
        // 증감은 화살표로 방향을 보이고, 비율 자체는 숫자만 (성격이 다른 값이라 표기도 가른다)
        echo '<td class="num">' . pf_delta_pct($m['rev_growth']) . '</td>';
        echo '<td class="num">' . pf_eok($r['op_income']) . '</td>';
        // 영업이익은 음수가 될 수 있어 백분율이 뜻을 잃는다 — 적자전환·흑자전환 따위로 말을 바꾼다
        echo '<td class="num">' . pf_profit_delta($r['prev_op_income'] ?? null, $r['op_income']) . '</td>';
        echo '<td class="num">' . pf_ratio_pct($m['op_margin']) . '</td>';
        echo '<td class="num">' . pf_eok($r['net_income']) . '</td>';
        echo '<td class="num">' . pf_ratio_pct($m['net_margin']) . '</td>';
        echo '<td class="num">' . pf_ratio_pct($m['roe']) . '</td>';
        echo '<td class="num">' . pf_eok($r['asset_total']) . '</td>';
        echo '<td class="num">' . pf_eok($r['debt_total']) . '</td>';
        echo '<td class="num">' . pf_eok($r['equity_total']) . '</td>';
        echo '<td class="num">' . ($m['debt_ratio'] === null ? '-' : pf_h(pf_pct0($m['debt_ratio'], 0))) . '</td>';
        echo '<td class="num">' . ($m['cur_ratio']  === null ? '-' : pf_h(pf_pct0($m['cur_ratio'], 0))) . '</td>';
        /* EPS·BPS 는 계산값 — 순이익·자본총계 ÷ 지금 상장주식수. 전 종목에 있다.
         * DPS(배당)만 DART 신고값이라 받아 둔 종목에서만 나온다. */
        echo '<td class="num">' . pf_n($m['eps']) . '</td>';
        echo '<td class="num">' . pf_n($m['bps']) . '</td>';
        echo '<td class="num">' . pf_n($fd['adj_dps'] ?? null) . '</td>';
        echo '<td class="num muted" style="font-size:11px">' . ($r['fs_div'] === 'CFS' ? '연결' : '별도') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';

    echo '<p class="sub muted" style="margin:9px 0 0;font-size:12px">'
       . '<b>EPS·BPS</b> 는 <b>순이익·자본총계 ÷ ' . pf_n($nowShrs ?: null) . '주(지금 상장주식수)</b> 로 계산한 값입니다. '
       . '분모가 늘 지금 주식수라 <b>액면분할이 저절로 보정</b>됩니다 — 그 해 주식수로 나누면 분할 전 연도가 '
       . '수십 배로 튀어 시계열이 끊깁니다. 재무분석 목록의 PER 도 같은 분모를 쓰므로 두 화면이 어긋나지 않습니다.<br>'
       . 'DART 가 <b>신고한</b> EPS 와는 다를 수 있습니다 — 신고값은 <b>지배주주 순이익 ÷ 가중평균 유통주식수</b> 인데, '
       . '여기는 <b>연결 당기순이익(비지배 포함) ÷ 상장주식수</b> 입니다.<br>'
       . '<b>DPS</b>(주당배당금)만은 계산으로 낼 수 없어 DART 에서 따로 받아야 합니다 — '
       . ($funds ? '이 종목은 받아 둔 상태입니다.' : '<b class="down">이 종목은 아직 받지 않았습니다</b> (보유·관심 종목만 받아 둔 상태).')
       . '</p>';
    echo '</div>';

    pf_fund_detail_quarters($dart, $code);
    pf_fund_detail_range($pdo, $code);
    pf_fund_detail_chart($pdo, $code);

    echo <<<'JS'
<script>
document.addEventListener('click', function(e){
  var b = e.target.closest ? e.target.closest('.wl-star2') : null;
  if (!b) return;
  var body = new URLSearchParams({json:'1', code:b.getAttribute('data-code'), name:b.getAttribute('data-name')});
  fetch('/stock/api.php?module=watch&action=toggle',
    {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body})
    .then(function(r){ return r.json(); })
    .then(function(j){
      if (!j || !j.ok) { alert((j && j.message) || '실패했습니다.'); return; }
      var on = j.message.indexOf('담았습니다') >= 0;
      b.textContent = on ? '★ 관심종목' : '☆ 관심종목';
      b.classList.toggle('btn-primary', on);
      b.classList.toggle('btn-outline', !on);
    })
    .catch(function(){ alert('통신에 실패했습니다.'); });
});
</script>
JS;

    pf_foot();
}

/**
 * 일봉 차트 — 재무는 분기·연간이라 느리게 움직이는데 주가는 매일 바뀐다. 둘을 한 화면에서 본다.
 *
 * 종목 상세(mode=position)의 차트와 달리 <b>가격선도 체결 마커도 없다</b> —
 * 여기 오는 종목은 아직 사지 않은 것이 대부분이라 얹을 것이 없다.
 * 그래서 마커 HTML 레이어도 통째로 뺐다(그쪽 코드의 절반이 그 레이어다).
 */
/**
 * 최근 6개월 고점·저점 요약.
 *
 * 목록은 크론이 채워 둔 표(stock_price_range)를 읽지만, 여기는 <b>그 자리에서 받는다</b> —
 * 한 종목이라 25ms 밖에 안 걸리고, 크론이 아직 안 돈 종목도 빈칸 없이 나온다.
 * 현재가는 목록과 같은 소스(네이버 실시간 all_stock_info)를 써야 두 화면이 어긋나지 않는다.
 */
function pf_fund_detail_range(PDO $pdo, string $code, int $months = 6): void
{
    $api = new NaverFinanceAPI();
    $r   = $api->getDailyOhlcRange($code, date('Ymd', strtotime("-{$months} months")), date('Ymd'));
    $bars = $r['success'] ?? [];
    if (!$bars) return;

    /* 거래정지일은 시·고·저·거래량이 0 이고 종가만 온다 — 그대로 두면 저가가 0 이 된다.
     * 그날은 종가를 고·저로 본다 (수집 쪽 NaverFinanceAPI::refreshPriceRange 와 같은 규칙). */
    $hi = $lo = null; $hid = $lod = ''; $used = 0;
    foreach ($bars as $x) {
        $h = $x['h'] > 0 ? $x['h'] : $x['c'];
        $l = $x['l'] > 0 ? $x['l'] : $x['c'];
        if ($h <= 0 || $l <= 0) continue;
        $used++;
        if ($hi === null || $h > $hi) { $hi = $h; $hid = $x['t']; }
        if ($lo === null || $l < $lo) { $lo = $l; $lod = $x['t']; }
    }
    if (!$used) return;
    $st = $pdo->prepare("SELECT stock_price FROM all_stock_info WHERE stock_code = ?");
    $st->execute([$code]);
    $px = (float)$st->fetchColumn();
    if ($px <= 0) $px = (float)end($bars)['c'];        // 네이버 목록에 없는 종목 — 마지막 종가로 대신한다
    // 오늘 장중에 뚫었으면 저장된 고가보다 지금이 위다 (목록과 같은 규칙)
    $hi = max((float)$hi, $px);
    $lo = min((float)$lo, $px);

    $cell = function (string $lab, string $val, string $sub = '') {
        return '<div style="flex:1;min-width:118px"><div class="muted" style="font-size:11.5px;font-weight:700">'
             . $lab . '</div><div style="font-size:17px;font-weight:800;color:#22303f;line-height:1.35">' . $val . '</div>'
             . ($sub !== '' ? '<div class="muted" style="font-size:11px">' . $sub . '</div>' : '') . '</div>';
    };
    $dd = $px / $hi - 1;
    $rb = $px / $lo - 1;

    echo '<div class="card"><h2>최근 ' . $months . '개월 주가 위치</h2>';
    echo '<div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-start;margin-top:4px">';
    echo $cell('현재가', number_format($px) . '원', '네이버 실시간');
    echo $cell('6개월 최고', number_format($hi) . '원', pf_h($hid) . ' 장중고가');
    echo $cell('6개월 최저', number_format($lo) . '원', pf_h($lod) . ' 장중저가');
    echo $cell('고점대비', $dd > -0.0005 ? '신고가' : number_format($dd * 100, 1) . '%',
               $dd > -0.0005 ? '6개월 최고가입니다' : '고점에서 ' . number_format(-$dd * 100, 1) . '% 하락');
    echo $cell('저점대비', $rb < 0.0005 ? '신저가' : '+' . number_format($rb * 100, 1) . '%',
               $rb < 0.0005 ? '6개월 최저가입니다' : '저점에서 ' . number_format($rb * 100, 1) . '% 상승');
    echo '</div>';
    echo '<p class="sub muted" style="margin:10px 0 0;font-size:12px">'
       . '일봉 <b>' . $used . '개</b>(' . pf_h($bars[0]['t']) . ' ~ ' . pf_h((string)end($bars)['t']) . ')로 계산했습니다. '
       . (count($bars) > $used ? '거래정지 ' . (count($bars) - $used) . '일은 뺐습니다. ' : '')
       . '종가가 아니라 <b>장중 고가·저가</b>가 기준입니다 — 고점은 실제로 그 값이 있었던 자리여야 합니다.</p>';
    echo '</div>';
}

/**
 * 차트에 얹을 SUE 공시 마커 — 이 종목의 정기공시(원본 접수일 MIN · [기재정정] 재접수 무시)에
 * 그 분기 SUE 를 붙인 것. [['d'=>접수일, 'q'=>'25.4Q', 'sue'=>7.7], …] 접수일 오름차순.
 *
 * ★서프라이즈(SUE ≥ 1)·쇼크(≤ −1)만 — 어닝 탭의 매수·회피 경계와 같은 상·하위 20% 문턱이고,
 *   중간값까지 다 찍으면 분기마다 마커가 생겨 소음이 된다. 매수 시점(다음 거래일) 스냅은 JS 몫.
 */
function pf_fund_sue_marks(PDO $pdo, string $code): array
{
    if (!$pdo->query("SHOW TABLES LIKE 'dart_rcept'")->fetchColumn()) return [];   // 원장 아직 없음 — 마커 없이 그린다
    $st = $pdo->prepare("
        SELECT bsns_year, reprt_code, MIN(rcept_dt) dt
          FROM dart_rcept
         WHERE stock_code = ? AND reprt_code IS NOT NULL
         GROUP BY bsns_year, reprt_code
        HAVING dt >= DATE_SUB(CURDATE(), INTERVAL 1600 DAY)");   // 차트 데이터가 1000영업일(≈4.2년)까지다
    $st->execute([$code]);

    $qNoMap = ['11013' => 1, '11012' => 2, '11014' => 3, '11011' => 4];
    $sue = null;                                   // 공시가 있을 때만 계산 (1~2ms 지만 습관)
    $out = [];
    foreach ($st as $r) {
        $qNo = $qNoMap[$r['reprt_code']] ?? null;
        if ($qNo === null) continue;
        if ($sue === null) $sue = pf_sue_stock($pdo, $code);
        $v = $sue[(int)$r['bsns_year'] * 4 + $qNo] ?? null;
        if ($v === null || abs($v) < 1) continue;
        $out[] = ['d' => $r['dt'], 'q' => ((int)$r['bsns_year'] % 100) . '.' . $qNo . 'Q', 'sue' => round($v, 1)];
    }
    usort($out, fn($a, $b) => strcmp($a['d'], $b['d']));
    return $out;
}

function pf_fund_detail_chart(PDO $pdo, string $code): void
{
    $marks = pf_fund_sue_marks($pdo, $code);

    echo '<div class="card"><div class="pf-head" style="margin-bottom:10px"><div>';
    echo '<h2 style="margin:0" id="fdDailyTitle">일봉 차트</h2>';
    echo '<div class="sub">네이버 일봉입니다. 위 재무와 견주어 보세요 — '
       . '실적이 좋아지는데 주가가 빠졌다면 그게 이 화면을 만든 이유입니다.'
       . ($marks ? '<br>▲<b>공시매수</b>(SUE≥1 서프라이즈)·▼<b>어닝쇼크</b>(SUE≤−1) 마커는 '
                 . '정기공시 <b>다음 거래일</b> — 백테스트의 매수 시점 그대로입니다.' : '')
       . '</div>';
    echo '</div><div class="act">';
    // 기간 바 [일봉|주봉 ┃ 160일 240일 480일 전체] + 사용자 지표 바 — DailyChart 공용 컴포넌트
    if ($marks) {
        // 사례분석과 같은 문법의 공시 마커 — 기본 켜짐, 누르면 숨김 (지표와 겹쳐 볼 때 끈다)
        echo '<button type="button" class="btn btn-primary" id="fdMkBtn">SUE 공시 ' . count($marks) . '</button> ';
    }
    echo '<span id="fdPBar"></span> <span id="fdIBar"></span>';
    echo '</div></div>';
    echo '<div class="chart-legend" id="fdLegend"></div>';   // 지표 값 표시 자리
    echo '<div id="fdChart" style="height:380px;position:relative"></div>';
    echo '<div class="muted" id="fdNote" style="font-size:12px;margin-top:8px">불러오는 중…</div>';
    echo '</div>';

    // 차트는 공용 모듈(style/dailychart.js)이 그린다 — 라이브러리 로드도 모듈이 맡는다
    echo '<script src="/style/dailychart.js?v=27"></script>';
    echo '<script>const FD_CODE=' . json_encode($code) . ';'
       . 'const FD_SUE=' . json_encode($marks, JSON_UNESCAPED_UNICODE) . ';</script>';
    echo <<<'JS'
<script>
DailyChart.load().then(function(){
  var note = document.getElementById('fdNote');
  var dc = DailyChart.create('fdChart', { theme: 'light', key: 'fund', legend: 'fdLegend' });
  if (!dc) { if (note) note.textContent = '차트 라이브러리를 불러오지 못했습니다.'; return; }

  function fdNote(){
    var r = dc.range();
    if (!r) return;
    var unit = dc.tf() === 'week' ? '주' : '거래일';
    note.textContent = r.n + unit + ' (' + r.from + ' ~ ' + r.to + ')'
      + ' · 종가 ' + Math.round(r.lastClose).toLocaleString()
      + (r.chg === null ? '' : ' · 구간 ' + (r.chg >= 0 ? '+' : '') + r.chg.toFixed(1) + '%');
  }

  // 기간 바 = 공용 컴포넌트. 데이터는 1000영업일을 한 번 받아 두고 슬라이스만 한다
  DailyChart.periodBar('fdPBar', dc, {
    theme: 'light',
    defaultIndex: 0,   // 160일 (기존 기본값 유지)
    onChange: function(info){
      var t = document.getElementById('fdDailyTitle');
      if (t) t.textContent = info.tf === 'week' ? '주봉 차트' : '일봉 차트';
      fdNote();
    }
  });
  DailyChart.indicatorBar('fdIBar', dc, { theme: 'light', key: 'fund' });   // 사용자 지표 + 차트틀

  note.textContent = '불러오는 중…';
  DailyChart.fetchDaily(FD_CODE, 1000).then(function(rows){
    if (!rows.length) {
      note.textContent = '일봉 데이터를 가져오지 못했습니다 (종목코드 ' + FD_CODE + ').';
      return;
    }
    dc.setData(rows);
    fdNote();

    /* SUE 공시 마커 — 사례분석(mode=earncase)과 같은 문법. 위치는 공시 <b>다음 거래일</b>(백테스트
       매수 시점)이라 접수일보다 뒤 첫 봉에 스냅한다. 공시 뒤 장이 아직 안 열렸으면 마지막 봉에. */
    if (FD_SUE.length) {
      var mk = FD_SUE.map(function(m){
        var t = null;
        for (var i = 0; i < rows.length; i++) if (rows[i].time > m.d) { t = rows[i].time; break; }
        if (!t) t = rows[rows.length - 1].time;
        return { time: t, sell: m.sue <= -1,
                 text: m.sue <= -1 ? '어닝쇼크' : '공시매수',
                 state: m.q + ' SUE ' + m.sue };
      });
      var mkOn = true, mkBtn = document.getElementById('fdMkBtn');
      function applyMk(){
        dc.setMarkers(mkOn ? mk : []);
        if (mkBtn) {
          mkBtn.classList.toggle('btn-primary', mkOn);
          mkBtn.classList.toggle('btn-outline', !mkOn);
        }
      }
      applyMk();
      if (mkBtn) mkBtn.addEventListener('click', function(){ mkOn = !mkOn; applyMk(); });
    }
  }).catch(function(){ note.textContent = '일봉을 불러오지 못했습니다.'; });
}).catch(function(){
  var n = document.getElementById('fdNote');
  if (n) n.textContent = '차트 라이브러리를 불러오지 못했습니다.';
});
</script>
JS;
}

/**
 * 분기 추이 — 저장된 누적(YTD)에서 <b>그 분기 3개월</b>을 만들어 보여 준다.
 *
 * 4분기는 보고서가 따로 없다. 사업보고서(12개월 누적)에서 3분기 누적을 뺀 것이 4분기다.
 * 앞 분기가 없으면 그 분기는 아예 만들지 않는다 — 0 으로 채우면 적자로 읽힌다.
 */
function pf_fund_detail_quarters(Dart $dart, string $code): void
{
    $qs = Dart::quarterly($dart->reportSeries($code));

    echo '<div class="card"><h2>분기 추이</h2>';
    if (!$qs) {
        echo '<p class="muted" style="font-size:13px;margin:0">분기 재무제표가 아직 없습니다. '
           . '<span class="muted">(DART 분기보고서는 2016년부터 제공됩니다)</span></p></div>';
        return;
    }

    /* 비교 상대 색인은 <b>자르기 전</b>에 만든다.
     * 자른 뒤에 만들면 표의 맨 아랫줄이 견줄 상대를 잃는다. */
    $byKey = [];
    foreach ($qs as $q) $byKey[$q['bsns_year'] . '-' . $q['quarter']] = $q;

    /* TTM(최근 4분기 합) — <b>분기 출렁임에 속지 않으려고</b> 같이 보여 준다.
     *
     * 실측(동아엘텍) 분기 매출이 265 → 2,105 → 1,064 → 2,390 → 409 처럼 널뛴다(수주 인식형).
     * 직전분기 대비만 보면 +694% 였다가 −82.9% 라 실적이 무너진 것처럼 읽히는데,
     * TTM 은 1,710 → 3,145 → 3,961 → 5,824 → 5,968 로 <b>계속 우상향</b>이다.
     * 스크리너의 「매출액 TTM」과 같은 값이라 두 화면이 여기서 이어진다.
     *
     * ★ 배열이 최근순이므로 i 행의 TTM 은 i…i+3 의 합이다. 넉 자리가 다 차지 않으면(상장 초기) null.
     * ★ 자르기 전에 계산해야 아랫줄도 제 값을 갖는다. */
    $ttm = [];
    foreach ($qs as $i => $q) {
        foreach (['revenue', 'op_income'] as $col) {
            $sum = 0.0; $ok = true;
            for ($k = 0; $k < 4; $k++) {
                if (!isset($qs[$i + $k]) || $qs[$i + $k][$col] === null) { $ok = false; break; }
                $sum += (float)$qs[$i + $k][$col];
            }
            $ttm[$i][$col] = $ok ? $sum : null;
        }
    }

    /* 직전 분기 — 1분기의 앞은 <b>전년 4분기</b>다.
     * 배열이 최근순이라 "다음 원소"를 집으면 될 것 같지만, 중간에 빠진 분기가 있으면
     * 엉뚱한 분기와 견주게 된다. 연도·분기로 짚어야 안전하다. */
    $prevOf = function (array $q) use ($byKey) {
        $key = ($q['quarter'] > 1)
             ? $q['bsns_year'] . '-' . ($q['quarter'] - 1)
             : ($q['bsns_year'] - 1) . '-4';
        return $byKey[$key] ?? null;
    };

    // 최근 12분기(3년)면 추세를 보기에 넉넉하다
    $qs = array_slice($qs, 0, 12);

    echo '<div class="tbl-scroll"><table class="pf"><thead><tr>';
    // 증감은 <b>직전 분기</b> 대비 (연도별 표가 직전 연도와 견주는 것과 같은 결)
    echo '<th class="num">분기</th><th class="num">매출액</th><th class="num">매출 증감</th>'
       . '<th class="num">매출 TTM</th>'
       . '<th class="num">영업이익</th><th class="num">영업이익 증감</th>'
       . '<th class="num">영업이익 TTM</th>'
       . '<th class="num">영업이익률</th><th class="num">순이익</th><th class="num">순이익률</th>'
       . '<th class="num">자산총계</th><th class="num">부채총계</th><th class="num">자본총계</th>'
       . '<th class="num">부채비율</th><th class="num">기준</th></tr></thead><tbody>';

    foreach ($qs as $i => $q) {
        // 증감은 <b>직전 분기</b> 대비 — 그래야 "적자전환·흑자전환"이 말 그대로 읽힌다
        $pv = $prevOf($q);
        $m  = Dart::ratio($q + [
            'prev_revenue'   => $pv['revenue']   ?? null,
            'prev_op_income' => $pv['op_income'] ?? null,
        ]);

        echo '<tr>';
        echo '<td class="num"><b>' . pf_h($q['label']) . '</b></td>';
        echo '<td class="num">' . pf_eok($q['revenue']) . '</td>';
        echo '<td class="num">' . pf_delta_pct($m['rev_growth']) . '</td>';
        // TTM — 분기 하나가 튀어도 12개월치는 흐름을 보여 준다. 스크리너의 「매출액 TTM」과 같은 값
        echo '<td class="num" style="color:#5f7183">' . pf_eok($ttm[$i]['revenue'] ?? null) . '</td>';
        echo '<td class="num">' . pf_eok($q['op_income']) . '</td>';
        echo '<td class="num">' . pf_profit_delta($pv['op_income'] ?? null, $q['op_income']) . '</td>';
        echo '<td class="num" style="color:#5f7183">' . pf_eok($ttm[$i]['op_income'] ?? null) . '</td>';
        echo '<td class="num">' . pf_ratio_pct($m['op_margin']) . '</td>';
        echo '<td class="num">' . pf_eok($q['net_income']) . '</td>';
        echo '<td class="num">' . pf_ratio_pct($m['net_margin']) . '</td>';
        echo '<td class="num muted">' . pf_eok($q['asset_total']) . '</td>';
        echo '<td class="num muted">' . pf_eok($q['debt_total']) . '</td>';
        echo '<td class="num muted">' . pf_eok($q['equity_total']) . '</td>';
        echo '<td class="num">' . ($m['debt_ratio'] === null ? '-' : pf_h(pf_pct0($m['debt_ratio'], 0))) . '</td>';
        echo '<td class="num muted" style="font-size:11px">' . ($q['fs_div'] === 'CFS' ? '연결' : '별도') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';

    echo '<p class="sub muted" style="margin:9px 0 0;font-size:12px">'
       . 'DART 는 분기보고서에 <b>누적</b>으로 신고합니다. 위 값은 거기서 앞 분기를 빼 만든 '
       . '<b>그 분기 3개월</b>입니다 — <b>4분기</b>는 보고서가 없어 <b>사업보고서 － 3분기 누적</b>으로 계산했습니다. '
       . '「매출 증감」·「영업이익 증감」은 <b>직전 분기</b>와 견준 것입니다 '
       . '(1분기는 전년 4분기가 상대). 영업이익은 부호가 뒤집히면 백분율이 뜻을 잃으므로 '
       . '<b>적자전환·흑자전환·적자확대·적자축소</b>로 적습니다.<br>'
       . '★ <b>분기 하나만 보면 속기 쉽습니다.</b> 수주를 몰아 인식하는 업종은 매출이 분기마다 널뜁니다 — '
       . '실측(동아엘텍) 265 → 2,105 → 1,064 → 2,390 → 409억이라 직전분기 대비가 '
       . '<b>+694%</b> 였다가 <b>−82.9%</b> 로 나옵니다. 그래서 <b>TTM(최근 4분기 합)</b> 을 나란히 뒀습니다 — '
       . '같은 구간의 TTM 은 1,710 → 3,145 → 3,961 → 5,824 → 5,968억으로 <b>계속 늘고 있었습니다</b>. '
       . '재무분석 목록의 「매출액 TTM」과 같은 값이라, 거기서 본 성장률과 여기가 이어집니다.<br>'
       . '자산·부채·자본은 흐름이 아니라 <b>그 분기말 시점</b>값이라 뺄셈하지 않았습니다. '
       . '회사가 중간에 회계 기준을 바꾸거나 중단영업을 재분류하면 누적끼리 어긋나 분기 값이 음수로 나올 수 있습니다 '
       . '(2024년 실측 전종목의 약 1%).</p>';
    echo '</div>';
}

// ══════════════════════════════════════════════════════════════════════
//  설정 > 차트 — 차트 갤러리(/chart_gallery.php)를 그대로 embed
//
//  퀀트 우측 재무분석 패널과 같은 방식: 마크업을 복제하지 않고 iframe 으로 얹는다.
//  갤러리(지표 만들기·검사·스타일 확인)를 고치면 이 탭에도 저절로 반영되고,
//  /chart_gallery.php 단독 접근도 그대로 살아 있다.
// ══════════════════════════════════════════════════════════════════════
function pf_page_chart(PDO $pdo, Pf $pf): void
{
    pf_head('차트 설정', 'setting');
    pf_subtabs('chart');
    echo '<iframe src="/chart_gallery.php?embed=1" '
       . 'style="width:100%;height:calc(100vh - 150px);border:0;border-radius:10px;background:#f4f7fa"></iframe>';
    pf_foot();
}

// ══════════════════════════════════════════════════════════════════════
//  포트폴리오 관리
// ══════════════════════════════════════════════════════════════════════
function pf_page_portfolio(PDO $pdo, Pf $pf): void
{
    $folios  = $pf->portfolios();
    $brokers = $pf->brokers();

    /** 증권사 선택 드롭다운 */
    $brokerSelect = function (string $name, int $selected) use ($brokers): string {
        $h = '<select name="' . $name . '" style="width:130px">';
        $h .= '<option value="0">— 선택 —</option>';
        foreach ($brokers as $b) {
            $sel = ((int)$b['id'] === $selected) ? ' selected' : '';
            $h  .= '<option value="' . (int)$b['id'] . '"' . $sel . '>' . pf_h($b['name']) . '</option>';
        }
        return $h . '</select>';
    };

    pf_head('포트폴리오 관리', 'setting', 'narrow');
    pf_subtabs('portfolio');
    pf_flash();

    echo '<div class="pf-head"><div><h1>포트폴리오 관리</h1>';
    echo '<div class="sub">종목을 담는 단위입니다. 증권사 계좌와 1:1로 써도 되고, 한 계좌를 전략별로 쪼개 여러 개를 둬도 됩니다. '
       . '증권사를 고르면 <a href="/stock/index.php?mode=setting">수수료 설정</a>에 등록한 수수료가 자동으로 적용됩니다. '
       . '원금은 포트폴리오 화면의 합계에 쓰입니다.</div></div>';
    echo '<div class="act"><button type="button" class="btn btn-primary" onclick="pfOpenFolio()">＋ 포트폴리오 추가</button></div>';
    echo '</div>';

    if (!$brokers) {
        echo '<div class="warn">등록된 증권사가 없습니다. '
           . '<a href="/stock/index.php?mode=setting">수수료 설정</a>에서 증권사와 수수료를 먼저 등록하세요.</div>';
    }

    $selId = (int)($_GET['fid'] ?? 0);
    $sel   = $selId ? $pf->portfolioGet($selId) : null;

    // ── 목록 (읽기 전용, 클릭하면 아래 폼에서 수정)
    echo '<div class="card"><h2>포트폴리오 목록</h2>';
    echo '<div class="tbl-scroll"><table class="pf" id="folioTbl"><thead><tr>';
    echo '<th class="center" style="width:34px">순서</th><th>포트폴리오명</th><th>증권사</th>';
    echo '<th class="num">수수료</th><th>계좌번호</th><th class="num">원금</th><th>메모</th>';
    echo '<th class="center">사용</th><th class="num">종목수</th><th></th></tr></thead><tbody>';

    foreach ($folios as $f) {
        $fid   = (int)$f['id'];
        $cnt   = $pf->portfolioPositionCount($fid);
        $bid   = (int)$f['broker_id'];
        $tiers = $bid ? $pf->brokerFees($bid) : [];
        $href  = '/stock/index.php?mode=portfolio&fid=' . $fid;

        echo '<tr class="stp' . ($fid === $selId ? ' on' : '') . '" '
           . 'data-id="' . $fid . '" data-href="' . pf_h($href) . '" title="클릭해서 수정">';

        echo '<td class="center">' . pf_drag_handle() . '</td>';
        echo '<td><a href="' . $href . '"><b>' . pf_h($f['name']) . '</b></a></td>';
        echo '<td>' . pf_h($f['broker_name'] ?? '-') . '</td>';

        // 선택된 증권사의 수수료 요약 (1천만원 매수 기준)
        echo '<td class="num muted" style="font-size:12px">';
        if (!$bid) {
            echo '-';
        } else {
            $prm = pf_cost_params(['buy_fee_rate' => $f['buy_fee_rate'], 'sell_fee_rate' => $f['sell_fee_rate']], $tiers);
            echo ($tiers ? '구간 ' . count($tiers) . '개' : '단일')
               . '<br>' . number_format(pf_buy_cost(10000000, $prm) / 10000000 * 100, 3) . '%';
        }
        echo '</td>';

        echo '<td>' . pf_h($f['acct_no']) . '</td>';
        echo '<td class="num">' . pf_n($f['principal']) . '</td>';
        echo '<td class="muted">' . pf_h($f['memo']);
        $note = trim((string)($f['note'] ?? ''));
        if ($note !== '') {
            // 목록에서는 한 줄로 줄여 보여주고, 전문은 툴팁으로 (전체는 대시보드 상세에)
            echo '<div class="note-brief" title="' . pf_h($note) . '">'
               . pf_h(mb_strimwidth(preg_replace('/\s+/u', ' ', $note), 0, 46, '…')) . '</div>';
        }
        echo '</td>';
        echo '<td class="center">' . ((int)$f['is_active']
            ? '<span class="badge st-open">사용</span>'
            : '<span class="badge st-closed">미사용</span>') . '</td>';
        echo '<td class="num">' . $cnt . '</td>';
        echo '<td class="right">';
        if ($cnt === 0) {
            echo '<form class="inline no-sel" method="post" action="/stock/api.php?module=portfolio&action=delete" '
               . 'onsubmit="return confirm(\'이 포트폴리오를 삭제할까요?\')">';
            echo '<input type="hidden" name="id" value="' . $fid . '">';
            echo '<button class="btn btn-danger btn-sm" type="submit">삭제</button></form>';
        } else {
            echo '<span class="muted" style="font-size:12px">종목 ' . $cnt . '개</span>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<p class="sub muted" style="margin:9px 0 0;font-size:12px">'
       . '왼쪽 <b>점 아이콘</b>을 잡고 끌어 순서를 바꿀 수 있습니다 (놓는 즉시 저장). '
       . '행을 클릭하면 아래에서 수정합니다. 수수료는 1천만원 매수 기준 실효율입니다 '
       . '(<a href="/stock/index.php?mode=setting">수수료 설정</a>에서 변경).</p>';
    echo '</div>';

    // 추가(모달)와 수정(하단 카드)이 같은 입력칸을 쓰므로 한 곳에서 찍는다
    $folioFields = function (?array $sel) use ($brokerSelect) {
        echo '<label class="fld">포트폴리오명<input type="text" name="name" style="width:160px" value="'
           . pf_h($sel['name'] ?? '') . '" required></label>';
        echo '<label class="fld">증권사' . $brokerSelect('broker_id', (int)($sel['broker_id'] ?? 0)) . '</label>';
        echo '<label class="fld">계좌번호<input type="text" name="acct_no" style="width:140px" value="'
           . pf_h($sel['acct_no'] ?? '') . '"></label>';
        if ($sel === null) {
            // 신규 등록에서 넣은 원금은 입출금 이력의 첫 줄('최초 원금')이 된다
            echo '<label class="fld">원금<input type="text" class="num-comma" inputmode="numeric" name="principal" '
               . 'style="width:150px" value="0"></label>';
        } else {
            // 원금은 직접 못 고친다 — 증액/인출 이력으로만 움직인다
            echo '<label class="fld">원금'
               . '<button type="button" class="fld-fixed" onclick="pfOpenFlow()" '
               . 'title="클릭하면 증액·인출 창이 열립니다">' . pf_n($sel['principal'] ?? 0)
               . ' <span class="muted">✎</span></button></label>';
        }
        echo '<label class="fld">메모<input type="text" name="memo" style="width:180px" value="'
           . pf_h($sel['memo'] ?? '') . '"></label>';
        echo '<label class="fld">순서<input type="number" name="sort_no" style="width:70px" value="'
           . (int)($sel['sort_no'] ?? 0) . '"></label>';
        echo '<label class="fld">사용<span style="padding:7px 0"><input type="checkbox" name="is_active" value="1"'
           . ((!$sel || (int)$sel['is_active']) ? ' checked' : '') . '></span></label>';

        // 상세메모 — flex:1 0 100% 라 줄바꿈돼 폭 전체를 차지한다 (뒤의 버튼도 자동으로 다음 줄)
        $note = (string)($sel['note'] ?? '');
        echo '<label class="fld" style="flex:1 0 100%">상세메모'
           . '<textarea name="note" rows="3" maxlength="200" class="note-in" '
           . 'placeholder="투자 원칙·목표·주의사항 등을 자유롭게 적어 두세요 (최대 200자)."'
           . ' style="resize:vertical;line-height:1.5">' . pf_h($note) . '</textarea>'
           . '<span class="muted" style="font-weight:400;font-size:11px">'
           . '<b class="note-cnt">' . mb_strlen($note) . '</b> / 200자</span></label>';
    };

    // ── 수정 폼 (행을 클릭했을 때만)
    if ($sel) {
        echo '<div class="card"><h2>포트폴리오 수정 — ' . pf_h($sel['name']) . '</h2>';
        echo '<form method="post" action="/stock/api.php?module=portfolio&action=save" class="fld-row">';
        echo '<input type="hidden" name="id" value="' . (int)$sel['id'] . '">';
        $folioFields($sel);
        echo '<button class="btn btn-primary" type="submit">저장</button>';
        echo '<a class="btn btn-outline" href="/stock/index.php?mode=portfolio">선택 해제</a>';
        echo '</form></div>';

        // ── 원금 변동 이력 — 원금은 이 표의 합계다 (컬럼으로 들고 있지 않는다)
        $flows = $pf->principalFlows($selId);
        $prin  = (int)($sel['principal'] ?? 0);

        echo '<div class="card"><h2>원금 변동 이력 — ' . pf_h($sel['name'])
           . ' <span class="muted" style="font-weight:600;font-size:12px">' . count($flows) . '건</span></h2>';
        echo '<div class="fld-row" style="margin-bottom:11px">';
        echo '<div class="prin-now"><div class="k">현재 원금</div><div class="v">' . pf_n($prin) . '</div></div>';
        echo '<button type="button" class="btn btn-primary" onclick="pfOpenFlow()">＋ 증액 / 인출</button>';
        echo '<span class="muted" style="font-size:12px;align-self:center">'
           . '원금은 직접 고칠 수 없고, 넣고 뺀 기록의 합계로 계산됩니다.</span>';
        echo '</div>';

        if (!$flows) {
            echo '<p class="muted" style="font-size:13px;margin:0">아직 원금 변동 기록이 없습니다.</p>';
        } else {
            echo '<div class="tbl-scroll"><table class="pf"><thead><tr>';
            echo '<th style="width:110px">일자</th><th class="center" style="width:60px">구분</th>';
            echo '<th class="num" style="width:130px">금액</th><th>사유</th>';
            echo '<th class="center" style="width:70px">삭제</th></tr></thead><tbody>';
            foreach ($flows as $w) {
                $amt = (int)$w['amount'];
                echo '<tr>';
                echo '<td>' . pf_h($w['flow_at']) . '</td>';
                echo '<td class="center">' . ($amt < 0
                    ? '<span class="down">인출</span>'
                    : '<span class="up">증액</span>') . '</td>';
                echo '<td class="num"><span class="' . pf_updown($amt) . '">'
                   . ($amt > 0 ? '+' : '') . pf_n($amt) . '</span></td>';
                echo '<td style="white-space:normal">' . pf_h($w['reason']) . '</td>';
                echo '<td class="center">';
                echo '<form class="inline" method="post" action="/stock/api.php?module=portfolio&action=flow_del" '
                   . 'onsubmit="return confirm(\'이 기록을 지우면 원금이 그만큼 되돌아갑니다. 삭제할까요?\')">';
                echo '<input type="hidden" name="fid" value="' . $selId . '">';
                echo '<input type="hidden" name="id" value="' . (int)$w['id'] . '">';
                echo '<button class="btn btn-danger btn-sm" type="submit">삭제</button></form>';
                echo '</td></tr>';
            }
            echo '</tbody><tfoot><tr><td colspan="2">현재 원금</td>';
            echo '<td class="num">' . pf_n($prin) . '</td><td colspan="2"></td></tr></tfoot>';
            echo '</table></div>';
        }
        echo '</div>';

        // ── 원금 증액/인출 모달
        echo '<div class="pf-modal-back" id="flowModal" onclick="if(event.target===this)pfCloseFlow()">';
        echo '<div class="pf-modal" role="dialog" aria-modal="true">';
        echo '<div class="pfm-head"><div>원금 변동 — ' . pf_h($sel['name']) . '</div>';
        echo '<button type="button" class="pfm-x" onclick="pfCloseFlow()" aria-label="닫기">✕</button></div>';
        echo '<form class="pfm-body" method="post" action="/stock/api.php?module=portfolio&action=flow_add">';
        echo '<input type="hidden" name="fid" value="' . $selId . '">';
        echo '<div class="fld-row">';
        echo '<label class="fld">구분<select name="kind" style="width:110px">'
           . '<option value="in">증액 (+)</option><option value="out">인출 (−)</option></select></label>';
        echo '<label class="fld">금액<input type="text" class="num-comma" inputmode="numeric" name="amount" '
           . 'style="width:160px" placeholder="0" required autocomplete="off"></label>';
        echo '<label class="fld">일자<input type="date" name="flow_at" value="' . date('Y-m-d') . '" required></label>';
        echo '<label class="fld" style="flex:1;min-width:200px">사유'
           . '<input type="text" name="reason" maxlength="80" autocomplete="off" '
           . 'placeholder="예: 상여금 입금, 생활비 인출"></label>';
        echo '</div>';
        echo '<div class="pfm-foot"><span class="muted">현재 원금 <b>' . pf_n($prin) . '</b>'
           . ' → 반영 후 <b id="flowAfter">' . pf_n($prin) . '</b></span>';
        echo '<button class="btn btn-primary" type="submit">반영</button></div>';
        echo '</form></div></div>';
        echo '<script>const PF_PRIN=' . json_encode($prin) . ';</script>';

        // ── 메모 이력 — 짧은 한 줄 기록. 수정은 없고 신규/삭제만.
        $memos = $pf->portfolioMemos($selId);
        echo '<div class="card"><h2>메모 이력 — ' . pf_h($sel['name'])
           . ' <span class="muted" style="font-weight:600;font-size:12px">' . count($memos) . '건</span></h2>';

        echo '<form method="post" action="/stock/api.php?module=portfolio&action=memo_add" '
           . 'class="fld-row" style="margin-bottom:11px">';
        echo '<input type="hidden" name="fid" value="' . $selId . '">';
        echo '<label class="fld" style="flex:1;min-width:240px">내용'
           . '<input type="text" name="content" maxlength="60" required autocomplete="off" '
           . 'placeholder="20자 내외로 짧게 (예: 3차 매수 완료, 실적발표 확인)"></label>';
        echo '<button class="btn btn-primary" type="submit">＋ 메모 등록</button>';
        echo '</form>';

        if (!$memos) {
            echo '<p class="muted" style="font-size:13px;margin:0">아직 남긴 메모가 없습니다.</p>';
        } else {
            echo '<div class="tbl-scroll"><table class="pf"><thead><tr>';
            echo '<th style="width:150px">일자(시간)</th><th>내용</th>';
            echo '<th class="center" style="width:70px">삭제</th></tr></thead><tbody>';
            foreach ($memos as $m) {
                echo '<tr>';
                echo '<td class="muted">' . pf_h(date('Y-m-d H:i', strtotime($m['created_at']))) . '</td>';
                echo '<td style="white-space:normal">' . pf_h($m['content']) . '</td>';
                echo '<td class="center">';
                echo '<form class="inline" method="post" action="/stock/api.php?module=portfolio&action=memo_del" '
                   . 'onsubmit="return confirm(\'이 메모를 삭제할까요?\')">';
                echo '<input type="hidden" name="fid" value="' . $selId . '">';
                echo '<input type="hidden" name="id" value="' . (int)$m['id'] . '">';
                echo '<button class="btn btn-danger btn-sm" type="submit">삭제</button></form>';
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div>';
    } else {
        echo '<div class="card"><p class="muted" style="font-size:13px;margin:0">'
           . '위 목록에서 <b>행을 클릭</b>하면 여기에서 수정하고 메모 이력을 남길 수 있습니다. '
           . '새로 만들려면 오른쪽 위 <b>＋ 포트폴리오 추가</b> 버튼을 누르세요.</p></div>';
    }

    // ── 추가 모달
    echo '<div class="pf-modal-back" id="folioModal" onclick="if(event.target===this)pfCloseFolio()">';
    echo '<div class="pf-modal" role="dialog" aria-modal="true">';
    echo '<div class="pfm-head"><div>포트폴리오 추가</div>';
    echo '<button type="button" class="pfm-x" onclick="pfCloseFolio()" aria-label="닫기">✕</button></div>';
    echo '<form class="pfm-body" method="post" action="/stock/api.php?module=portfolio&action=save">';
    echo '<div class="fld-row">';
    $folioFields(null);
    echo '</div>';
    echo '<div class="pfm-foot"><span class="muted">증권사를 고르면 설정에 등록한 수수료가 자동 적용됩니다.</span>';
    echo '<button class="btn btn-primary" type="submit">추가</button></div>';
    echo '</form></div></div>';

    // 추가 모달
    echo <<<'CSS'
<style>
.pf-modal-back{display:none;position:fixed;inset:0;background:rgba(16,32,48,.5);z-index:200;
  align-items:flex-start;justify-content:center;padding:40px 14px;overflow-y:auto}
.pf-modal-back.on{display:flex}
.pf-modal{background:#fff;border-radius:13px;width:100%;max-width:720px;
  box-shadow:0 18px 50px rgba(10,25,45,.3);overflow:hidden}
.pfm-head{display:flex;justify-content:space-between;align-items:center;padding:13px 16px;
  background:linear-gradient(90deg,#123c63,#1d5c93);color:#fff;font-size:17px;font-weight:800}
.pfm-x{background:rgba(255,255,255,.16);border:none;color:#fff;font-size:14px;cursor:pointer;
  width:28px;height:28px;border-radius:7px;line-height:1}
.pfm-x:hover{background:rgba(255,255,255,.3)}
.pfm-body{padding:14px 16px 0}
.pfm-foot{display:flex;justify-content:space-between;align-items:center;gap:12px;
  padding:13px 0;margin-top:12px;border-top:1px solid #eef2f6}
.pfm-foot .muted{font-size:12px;line-height:1.4}
@media(max-width:560px){
  .pf-modal-back{padding:14px 8px}
  .pfm-foot{flex-direction:column;align-items:stretch}
  .pfm-foot .btn{width:100%}
}

/* 원금 — 직접 입력하지 않고 눌러서 증액/인출 창을 연다 */
.fld-fixed{padding:6px 10px;min-width:150px;text-align:right;font-family:inherit;font-size:13px;
  font-weight:800;color:#22303f;background:#f2f6fa;border:1px solid #cfdae4;border-radius:6px;
  cursor:pointer;font-variant-numeric:tabular-nums}
.fld-fixed:hover{background:#e7eef6;border-color:#1d5c93}
.prin-now{padding:5px 14px;background:#f5f8fb;border:1px solid #e3eaf0;border-radius:8px}
.prin-now .k{font-size:11px;color:#8b98a5;font-weight:700}
.prin-now .v{font-size:17px;font-weight:800;font-variant-numeric:tabular-nums}
</style>
CSS;

    // 원금 입력 천단위 콤마
    echo <<<'JS'
<script>
/* 콤마 포맷은 공용(pf_comma_js)의 pfComma 가 맡는다 */
function pfNoteCnt(el){
  var c = el.parentNode.querySelector('.note-cnt');
  if (c) c.textContent = Array.from(el.value).length;
}
document.addEventListener('input', function(e){
  if (e.target.classList && e.target.classList.contains('note-in')) pfNoteCnt(e.target);
});
document.querySelectorAll('.note-in').forEach(pfNoteCnt);

function pfModal(id, on){
  var m = document.getElementById(id);
  if (!m) return null;
  m.classList.toggle('on', on);
  document.body.style.overflow = on ? 'hidden' : '';
  if (on){
    var f = m.querySelector('input:not([type=hidden]), select');
    if (f) f.focus();
  }
  return m;
}
function pfOpenFolio(){ pfModal('folioModal', true); }
function pfCloseFolio(){ pfModal('folioModal', false); }
function pfOpenFlow(){ pfModal('flowModal', true); pfFlowPreview(); }
function pfCloseFlow(){ pfModal('flowModal', false); }
document.addEventListener('keydown', function(e){
  if (e.key === 'Escape'){ pfCloseFolio(); pfCloseFlow(); }
});

// 증액/인출을 반영하면 원금이 얼마가 되는지 미리 보여준다
function pfFlowPreview(){
  var m = document.getElementById('flowModal');
  if (!m || typeof PF_PRIN === 'undefined') return;
  var amt   = Number((m.querySelector('[name=amount]').value || '').replace(/[^\d]/g, '')) || 0;
  var out   = m.querySelector('[name=kind]').value === 'out';
  var after = PF_PRIN + (out ? -amt : amt);
  var box   = document.getElementById('flowAfter');
  box.textContent = after.toLocaleString();
  box.className   = after < 0 ? 'down' : '';
}
document.addEventListener('input',  function(e){ if (e.target.closest && e.target.closest('#flowModal')) pfFlowPreview(); });
document.addEventListener('change', function(e){ if (e.target.closest && e.target.closest('#flowModal')) pfFlowPreview(); });
</script>
JS;

    pf_sort_script('folioTbl', '/stock/api.php?module=portfolio&action=reorder');

    pf_foot();
}

// ══════════════════════════════════════════════════════════════════════
//  설정 — 증권사 수수료 / 시장별 세율
// ══════════════════════════════════════════════════════════════════════
function pf_page_setting(PDO $pdo, Pf $pf): void
{
    $brokers = $pf->brokers();
    $markets = $pf->markets();
    $inUse   = $pf->marketsInUse();

    $bid = (int)($_GET['bid'] ?? 0);
    $br  = $bid ? $pf->brokerGet($bid) : null;

    pf_head('수수료 설정', 'setting', 'narrow');
    pf_subtabs('fee');
    pf_flash();

    echo '<div class="pf-head"><div><h1>수수료 · 세금 설정</h1>';
    echo '<div class="sub">매매비용은 두 축으로 나뉩니다 — <b>위탁수수료는 증권사마다</b>, '
       . '<b>증권거래세는 시장(코스피·코스닥)마다</b> 다릅니다. '
       . '여기서 등록한 증권사를 포트폴리오에서 선택하면 수수료가 따라옵니다.</div></div></div>';

    echo '<div class="card"><h2>비용 구성</h2>';
    echo '<div class="formula">';
    echo '<div><span class="fk">매수비용</span> = 위탁수수료<span class="op">(증권사)</span></div>';
    echo '<div><span class="fk">매도비용</span> = 위탁수수료<span class="op">(증권사)</span> '
       . '<span class="op">+</span> 증권거래세<span class="op">(시장)</span></div>';
    echo '</div>';
    echo '<p class="sub muted" style="margin:9px 0 0;font-size:12px">'
       . '매수비용은 <b>누적단가</b>에, 매도비용은 <b>자동매도가·실현손익</b>에 반영됩니다. '
       . '값을 바꾸면 해당 증권사를 쓰는 모든 포트폴리오가 즉시 다시 계산됩니다.</p>';
    echo '</div>';

    // ── 증권사 목록
    echo '<div class="card"><h2>증권사 수수료</h2>';
    echo '<div class="tbl-scroll"><table class="pf"><thead><tr>';
    echo '<th>증권사</th><th>방식</th><th class="num">1백만</th><th class="num">1천만</th><th class="num">1억</th>';
    echo '<th class="num">포트폴리오</th><th>메모</th><th></th></tr></thead><tbody>';

    if (!$brokers) {
        echo '<tr><td colspan="8" class="muted center" style="padding:18px">'
           . '등록된 증권사가 없습니다. 아래에서 추가하세요.</td></tr>';
    }
    foreach ($brokers as $b) {
        $tiers  = $pf->brokerFees((int)$b['id']);
        $prm    = pf_cost_params(['buy_fee_rate' => $b['buy_fee_rate'], 'sell_fee_rate' => $b['sell_fee_rate']], $tiers);
        $on     = ((int)$b['id'] === $bid);

        echo '<tr' . ($on ? ' style="background:#eef4fa"' : '') . '>';
        echo '<td><a href="/stock/index.php?mode=setting&bid=' . (int)$b['id'] . '"><b>' . pf_h($b['name']) . '</b></a></td>';
        echo '<td>' . ($tiers
            ? '<span class="badge st-open">구간 ' . count($tiers) . '개</span>'
            : '<span class="badge st-watch">단일 요율</span>') . '</td>';
        foreach ([1000000, 10000000, 100000000] as $amt) {
            $fee = pf_buy_cost($amt, $prm);
            echo '<td class="num">' . pf_n(round($fee))
               . ' <span class="muted" style="font-size:11px">' . number_format($fee / $amt * 100, 3) . '%</span></td>';
        }
        echo '<td class="num">' . (int)$b['folio_count'] . '</td>';
        echo '<td class="muted">' . pf_h($b['memo']) . '</td>';
        echo '<td class="right"><a class="btn btn-outline btn-sm" href="/stock/index.php?mode=setting&bid='
           . (int)$b['id'] . '">수정</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';

    // 증권사 추가
    echo '<form method="post" action="/stock/api.php?module=broker&action=create" class="fld-row" '
       . 'style="margin-top:13px;padding-top:12px;border-top:1px solid #eef2f6">';
    echo '<label class="fld">증권사명<input type="text" name="name" style="width:150px" placeholder="키움증권" required></label>';
    echo '<label class="fld">프리셋<select name="preset">';
    echo '<option value="">단일 요율로 시작</option>';
    echo '<option value="kiwoom">키움증권 — 전 구간 0.015%</option>';
    echo '<option value="samsung">삼성증권 MTS/HTS — 0.147216%+1,500원 ~ 0.077216%</option>';
    echo '</select></label>';
    echo '<button class="btn btn-primary" type="submit">＋ 증권사 추가</button>';
    echo '</form></div>';

    // ── 선택된 증권사 편집
    if ($br) {
        $tiers  = $pf->brokerFees($bid);
        $isTier = (bool)$tiers;

        echo '<div class="card"><h2>' . pf_h($br['name']) . ' — 수수료 편집 '
           . '<span class="muted" style="font-weight:600;font-size:12px">포트폴리오 '
           . $pf->brokerFolioCount($bid) . '개가 사용 중</span></h2>';

        echo '<div class="fee-mode">';
        echo '<span class="' . (!$isTier ? 'on' : '') . '">단일 요율</span>';
        echo '<span class="' . ($isTier ? 'on' : '') . '">거래금액 구간별'
           . ($isTier ? ' (' . count($tiers) . '구간)' : '') . '</span>';
        echo '</div>';

        // 이름·메모·단일요율
        echo '<form method="post" action="/stock/api.php?module=broker&action=save" class="fld-row">';
        echo '<input type="hidden" name="id" value="' . $bid . '">';
        echo '<label class="fld">증권사명<input type="text" name="name" style="width:140px" value="'
           . pf_h($br['name']) . '" required></label>';
        echo '<label class="fld">매수 수수료(%)<input type="number" name="buy_fee" step="0.000001" min="0" style="width:120px" value="'
           . pf_h(pf_rate_pct((float)$br['buy_fee_rate'])) . '"' . ($isTier ? ' disabled' : '') . '></label>';
        echo '<label class="fld">매도 수수료(%)<input type="number" name="sell_fee" step="0.000001" min="0" style="width:120px" value="'
           . pf_h(pf_rate_pct((float)$br['sell_fee_rate'])) . '"' . ($isTier ? ' disabled' : '') . '></label>';
        echo '<label class="fld" style="flex:1;min-width:160px">메모<input type="text" name="memo" value="'
           . pf_h($br['memo']) . '"></label>';
        echo '<button class="btn btn-primary" type="submit">저장</button>';
        echo '</form>';
        if ($isTier) {
            echo '<p class="sub muted" style="margin:7px 0 0;font-size:12px">'
               . '구간표를 쓰는 중이라 단일 요율은 사용되지 않습니다. 구간을 모두 지우면 다시 활성화됩니다.</p>';
        }

        // 프리셋
        echo '<form method="post" action="/stock/api.php?module=broker&action=preset" class="fld-row" style="margin-top:14px">';
        echo '<input type="hidden" name="id" value="' . $bid . '">';
        echo '<label class="fld">프리셋 적용<select name="preset">';
        echo '<option value="flat">단일 요율로 되돌리기 (구간 삭제)</option>';
        echo '<option value="kiwoom">키움증권 — 전 구간 0.015%</option>';
        echo '<option value="samsung">삼성증권 MTS/HTS — 0.147216%+1,500원 ~ 0.077216%</option>';
        echo '</select></label>';
        echo '<button class="btn btn-outline" type="submit" '
           . 'onclick="return confirm(\'현재 구간을 덮어씁니다. 진행할까요?\')">적용</button>';
        echo '</form>';

        // 구간 편집
        echo '<form method="post" action="/stock/api.php?module=broker&action=save_tier" style="margin-top:14px">';
        echo '<input type="hidden" name="id" value="' . $bid . '">';
        echo '<div class="tbl-scroll"><table class="pf tier-tbl"><thead><tr>';
        echo '<th class="num">거래금액 이상</th><th class="num">요율(%)</th><th class="num">정액(원)</th>';
        echo '<th class="num">1천만원 거래 시</th><th></th></tr></thead><tbody>';

        foreach ($tiers as $t) {
            echo '<tr>';
            echo '<td class="num"><input type="text" class="num-comma" inputmode="numeric" name="min_amt[]" style="width:150px" value="'
               . (int)$t['min_amt'] . '"></td>';
            echo '<td class="num"><input type="number" name="fee_rate[]" step="0.000001" min="0" style="width:120px" value="'
               . pf_h(pf_rate_pct((float)$t['fee_rate'])) . '"></td>';
            echo '<td class="num"><input type="text" class="num-comma" inputmode="numeric" name="fee_fixed[]" style="width:100px" value="'
               . (int)$t['fee_fixed'] . '"></td>';
            echo '<td class="num muted">' . pf_n(round(pf_fee_amount(10000000, [$t]))) . '</td>';
            echo '<td class="right"><button type="button" class="btn btn-danger btn-sm" onclick="pfTierDel(this)">삭제</button></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '<div class="fld-row" style="margin-top:10px">';
        echo '<button class="btn btn-primary" type="submit">구간 저장</button>';
        echo '<button class="btn btn-outline" type="button" onclick="pfTierAdd(this)">＋ 구간 추가</button>';
        echo '<span class="muted" style="font-size:12px">'
           . '구간을 모두 지우고 저장하면 단일 요율로 돌아갑니다. 정액이 있으면 소액 거래일수록 실효율이 높아집니다.</span>';
        echo '</div></form>';

        if ((int)$pf->brokerFolioCount($bid) === 0) {
            echo '<form method="post" action="/stock/api.php?module=broker&action=delete" style="margin-top:14px;padding-top:12px;border-top:1px solid #eef2f6">';
            echo '<input type="hidden" name="id" value="' . $bid . '">';
            echo '<button class="btn btn-danger" type="submit" '
               . 'onclick="return confirm(\'이 증권사를 삭제할까요?\')">증권사 삭제</button>';
            echo '<span class="muted" style="font-size:12px;margin-left:10px">사용 중인 포트폴리오가 없어 삭제할 수 있습니다.</span>';
            echo '</form>';
        }
        echo '</div>';
    }

    // ── 시장별 증권거래세
    echo '<div class="card"><h2>시장별 증권거래세</h2>';
    echo '<form method="post" action="/stock/api.php?module=setting&action=save_tax">';
    echo '<div class="tbl-scroll"><table class="pf"><thead><tr>';
    echo '<th>코드</th><th>이름</th><th class="num">세율(%)</th><th>비고</th><th class="num">순서</th><th></th>';
    echo '</tr></thead><tbody>';

    foreach ($markets as $m) {
        echo '<tr><input type="hidden" name="code[]" value="' . pf_h($m['code']) . '">';
        echo '<td><b>' . pf_h($m['code']) . '</b></td>';
        echo '<td><input type="text" name="name[]" style="width:110px" value="' . pf_h($m['name']) . '"></td>';
        echo '<td class="num"><input type="number" name="tax[]" step="0.0001" min="0" style="width:100px" value="'
           . pf_h(rtrim(rtrim(number_format((float)$m['tax_rate'] * 100, 4, '.', ''), '0'), '.')) . '"></td>';
        echo '<td><input type="text" name="memo[]" style="width:280px" value="' . pf_h($m['memo']) . '"></td>';
        echo '<td class="num"><input type="number" name="sort_no[]" style="width:60px" value="' . (int)$m['sort_no'] . '"></td>';
        echo '<td class="right"><button class="btn btn-danger btn-sm" type="submit" formnovalidate '
           . 'formaction="/stock/api.php?module=setting&action=delete_tax" name="del_code" value="' . pf_h($m['code']) . '" '
           . 'onclick="return confirm(\'' . pf_h($m['code']) . ' 세율을 삭제할까요?\')">삭제</button></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    echo '<div class="fld-row" style="margin-top:11px">';
    echo '<button class="btn btn-primary" type="submit">세율 저장</button>';
    echo '</form>';
    echo '<form class="inline" method="post" action="/stock/api.php?module=setting&action=reset_tax">';
    echo '<button class="btn btn-outline" type="submit" '
       . 'onclick="return confirm(\'2026년 기준값으로 덮어씁니다. 직접 수정한 값이 사라집니다. 진행할까요?\')">'
       . '2026년 기준값으로 되돌리기</button></form>';
    echo '<span class="muted" style="font-size:12px">코스피·코스닥 0.20% · 코넥스 0.10% · 비상장 0.35% · ETF 면제</span>';
    echo '</div>';

    echo '<form method="post" action="/stock/api.php?module=setting&action=add_tax" class="fld-row" style="margin-top:14px;padding-top:13px;border-top:1px solid #eef2f6">';
    echo '<label class="fld">코드<input type="text" name="code" style="width:100px" placeholder="KOSDAQ" required></label>';
    echo '<label class="fld">이름<input type="text" name="name" style="width:110px"></label>';
    echo '<label class="fld">세율(%)<input type="number" name="tax" step="0.0001" min="0" style="width:100px" value="0.15"></label>';
    echo '<label class="fld">비고<input type="text" name="memo" style="width:240px"></label>';
    echo '<button class="btn btn-outline" type="submit">＋ 시장 추가</button>';
    echo '</form>';

    // 종목이 쓰는데 세율이 없는 시장 경고
    $known   = array_column($markets, 'code');
    $missing = [];
    foreach ($inUse as $u) {
        if ($u['market'] !== '' && !in_array($u['market'], $known, true)) $missing[] = $u;
    }
    if ($missing) {
        echo '<div class="warn" style="margin:12px 0 0">세율이 등록되지 않은 시장이 있습니다 — ';
        foreach ($missing as $i => $u) {
            echo ($i ? ', ' : '') . '<b>' . pf_h($u['market']) . '</b> (' . (int)$u['cnt'] . '종목)';
        }
        echo '. 등록 전까지 기본 ' . pf_h(pf_pct0(PF_TAX_RATE, 2)) . '가 적용됩니다.</div>';
    }
    echo '</div>';

    echo <<<'JS'
<script>
function pfTierAdd(btn){
  var tb = btn.closest('form').querySelector('tbody');
  var tr = document.createElement('tr');
  tr.innerHTML =
    '<td class="num"><input type="text" class="num-comma" inputmode="numeric" name="min_amt[]" style="width:150px" value="0"></td>' +
    '<td class="num"><input type="number" name="fee_rate[]" step="0.000001" min="0" style="width:120px" value="0"></td>' +
    '<td class="num"><input type="text" class="num-comma" inputmode="numeric" name="fee_fixed[]" style="width:100px" value="0"></td>' +
    '<td class="num muted">-</td>' +
    '<td class="right"><button type="button" class="btn btn-danger btn-sm" onclick="pfTierDel(this)">삭제</button></td>';
  tb.appendChild(tr);
}
function pfTierDel(btn){ btn.closest('tr').remove(); }
</script>
JS;

    pf_foot();
}

/** 비율(0.00015) → 화면용 % 문자열(0.015). 꼬리 0 제거 */
function pf_rate_pct(float $rate): string
{
    return rtrim(rtrim(number_format($rate * 100, 6, '.', ''), '0'), '.') ?: '0';
}

// 시세 화면은 삭제했다. 시세 수집은 all_stock_info 연동 로직으로 따로 만든다.
// 종목의 시장·현재가·최고가는 종목 설정 폼(pf_render_position_form)에서 관리한다.

// ══════════════════════════════════════════════════════════════════════
//  퀀트 — 거래대금 60거래일 신고가 (2026-07-31)
//  데이터 = krx_amt 전종목 일별 원장 (2024-08~ · 확정 T+1 KRX · 당일은 네이버 잠정 src='n')
// ══════════════════════════════════════════════════════════════════════

/**
 * 배지 판정 — <b>2026-07-31 백테스트로 확정한 임계</b> (창 120거래일 · 하한 100억 · 신호 6,942건).
 * 근거 수치는 mode=quantstat 에 전문이 있다. 우선순위는 "나쁜 쪽 먼저" —
 * 추격주의(중앙 −7.23%)가 매집형 조건과 겹치면 위험 쪽으로 판정해야 안전하다.
 *
 * ★ 120일 창에서는 <b>매집형 단독(20평비≤5)이 경계선</b>이었다(후반기 중앙 −0.13%로 부호 반전).
 *   견고하게 이긴 것은 <b>매집형 ∧ 등락 0~10%</b> 뿐(+1.74% · 55.2% · 두 기간 모두 ＋)
 *   → 🟢 배지에 등락 조건까지 넣는다. 등락이 밖이면(하락하며·급등하며 찍은 최고 거래대금) 중립.
 * @return array [정렬순서, 클래스, 라벨, 툴팁]
 */
function pf_surge_badge(?float $avgMul, ?float $chg): array
{
    if ($chg !== null && $chg >= 0.20) {
        return [3, 'qb-chase', '추격주의', '신호일 등락 +20% 이상 — 실측 +20일 초과수익 중앙 -7.23% · 승률 34.6%'];
    }
    if ($avgMul !== null && $avgMul >= 20) {
        return [2, 'qb-exp', '폭발형', '20일 평균의 20배 이상 폭발 — 실측 중앙 -4.24% · 승률 36.7%'];
    }
    if ($avgMul !== null && $avgMul <= 5 && $chg !== null && $chg >= 0 && $chg < 0.10) {
        return [0, 'qb-acc', '매집형', '20일 평균의 5배 이하 + 등락 0~10%로 조용히 차오른 최고 거래대금 — 실측 중앙 +1.74% · 승률 55.2%'];
    }
    return [1, 'qb-neu', '중립', '실측상 우위도 열위도 뚜렷하지 않은 구간 (매집형인데 하락·급등하며 찍은 경우 포함)'];
}

/** 공용 스타일 — 목록·검증 두 화면이 같이 쓴다 */
function pf_quant_css(): void
{
    echo '<style>
.qb{display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:700;white-space:nowrap}
.qb-acc{background:#e6f4ea;color:#1e7e34}.qb-neu{background:#eef1f4;color:#667}
.qb-exp{background:#fdecea;color:#c62828}.qb-chase{background:#c62828;color:#fff}
.qb-hot{background:#fff3e0;color:#b26a00}.qb+.qb,.bx+.qb{margin-left:4px}
.wl-star{border:0;background:none;cursor:pointer;font-size:15px;color:#c8d3dd;padding:0;line-height:1}
.wl-star:hover,.wl-star.on{color:#f0a500}
tr.q-row{cursor:pointer}tr.q-row:hover td{background:#f7fafc}
tr.q-row.q-sel td{background:#fff6dd}
.q-note{font-size:12px;color:#89a;margin:6px 0 0}
.q-prov{background:#fff8e6;border:1px solid #f0dfae;border-radius:8px;padding:8px 12px;margin:0 0 12px;font-size:13px}
table.qstat{border-collapse:collapse;margin:8px 0 16px}
table.qstat th,table.qstat td{border:1px solid #dfe6ec;padding:4px 10px;font-size:13px;text-align:right}
table.qstat th{background:#f4f7fa;text-align:center}table.qstat td:first-child{text-align:left}
/* 박스 상태 (지지·저항 경로) */
.bx{display:inline-block;padding:2px 7px;border-radius:9px;font-size:12px;font-weight:600;white-space:nowrap}
.bx-new{background:#eef1f4;color:#567}.bx-in{background:#eef4fb;color:#28527a}
.bx-brk{background:#e6f4ea;color:#1e7e34}.bx-fake{background:#fdf3e0;color:#b26a00}
.bx-lad{background:#e8f0fe;color:#1a56b0}.bx-dn{background:#fdecea;color:#c62828}.bx-na{background:#f4f4f4;color:#9aa}
/* 좌우 2단 — 좌측 목록은 좁게, 우측은 재무분석 상세(iframe)를 sticky 로 */
.q-split{display:flex;gap:14px;align-items:flex-start}
.q-left{flex:0 1 960px;min-width:0}
.q-right{flex:1 1 540px;min-width:460px;position:sticky;top:10px}
.q-right iframe{display:block;width:100%;height:calc(100vh - 30px);min-height:640px;
  border:1px solid #dfe6ec;border-radius:10px;background:#fff}
.q-ph{border:1px dashed #cfd8e0;border-radius:10px;padding:70px 20px;text-align:center;color:#9ab;font-size:14px}
.q-left table.pf th,.q-left table.pf td{padding:6px 8px;font-size:13px}
@media(max-width:1500px){.q-split{flex-wrap:wrap}.q-right{position:static;min-width:100%}.q-right iframe{height:760px}}
</style>';
}

function pf_page_quant(PDO $pdo, Pf $pf): void
{
    $krx = new KrxAmt($pdo);

    $dates = [];
    $data  = ['date' => '', 'rows' => []];
    $err   = '';
    try {
        $dates = $krx->recentDates(40);
        $d     = (string)($_GET['d'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $d = '';
        // 캐시 우선 — 크론이 미리 계산해 둔다. 없는 날짜는 첫 조회자가 몇 초 내고 채운다.
        $data  = $krx->surgeCached($d !== '' ? $d : null);
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
    $rows = $data['rows'];
    $day  = $data['date'];

    /* 최소 거래대금(억) — 기본 100억.
     * 실측(2026-07-31): 하한 없으면 하루 평균 55.7종목, 100억이면 26.3종목 — 잡음이 절반으로 준다.
     * 콤마째 올 수 있으므로 걷어서 읽는다(num-comma 규칙). 0 이면 제한 없음. */
    $minEok = (float)str_replace(',', '', (string)($_GET['min'] ?? '100'));
    if ($minEok < 0 || !is_finite($minEok)) $minEok = 0.0;
    $hidden = 0;
    if ($minEok > 0) {
        $keep = [];
        foreach ($rows as $r) {
            if ((float)$r['amt'] >= $minEok * 1e8) $keep[] = $r;
            else $hidden++;
        }
        $rows = $keep;
    }

    /* ── 박스 상태(지지·저항 경로) + 매집형 박스 추적 ──
     * 경로 연구(2026-07-31)·위치 연구(2026-08-01)의 승자 규칙을 화면으로:
     *   매집형 × 돌파 매수 +2.26%·57.9% / 매집형 × 계단지지(아래층 박스 3개↑만) +2.40%·58.5%
     * 추적 카드 하한은 측정 조건(100억) 고정 — min 필터를 낮춰도 규칙의 전제는 안 바뀐다. */
    $box = []; $acc = []; $accBox = [];
    try {
        $box = $krx->boxStatusMany(array_map(fn($r) => ['code' => $r['code'], 'd' => $day], $rows));
        $accRaw = $krx->accBoxes($day);
        $seen = [];
        foreach ($accRaw as $a) {                       // 같은 종목이면 최신 박스만 (계단의 맨 위층)
            if (isset($seen[$a['code']])) continue;
            $seen[$a['code']] = 1;
            $acc[] = $a;
        }
        $accBox = $krx->boxStatusMany(array_map(fn($a) => ['code' => $a['code'], 'd' => $a['d']], $acc));
    } catch (Throwable $e) { /* 상태 없이도 목록은 뜬다 */ }

    /* 단기급등 ⚠ — 신호일까지 20일 +80% 또는 40일 +100% 급등(검증 탭 ⑧: 엣지 없는 복권 자리) */
    $mom = [];
    try {
        $sigsAll = array_map(fn($r) => ['code' => $r['code'], 'd' => $day], $rows);
        foreach ($acc as $a) $sigsAll[] = ['code' => $a['code'], 'd' => $a['d']];
        $mom = $krx->momMany($sigsAll);
    } catch (Throwable $e) { /* 급등 표시는 없어도 목록은 뜬다 */ }
    $hotChip = static function (?array $mm): string {
        if (!$mm || !$mm['hot']) return '';
        $tip = sprintf('신호일까지 20거래일 %s · 40거래일 %s 급등 — 20일 +80%% 또는 40일 +100%% 뒤의 돌파 매수는'
            . ' 실측 중앙 0.00%%·승률 51.6%%로 엣지가 없다(평균은 +7%%로 복권꼬리 — 검증 탭 ⑧).'
            . ' 손절 규칙은 그대로(급등만 타이트 손절은 기각).',
            $mm['m20'] !== null ? sprintf('%+.0f%%', $mm['m20'] * 100) : '-',
            $mm['m40'] !== null ? sprintf('%+.0f%%', $mm['m40'] * 100) : '-');
        return '<span class="qb qb-hot" title="' . pf_h($tip) . '">급등⚠</span>';
    };

    // 이름·관심종목·배지·정렬 — 배지 그룹(매집형 먼저) 안에서 거래대금 큰 순
    $nameCodes = array_unique(array_merge(array_column($rows, 'code'), array_column($acc, 'code')));
    $names = $nameCodes ? $krx->names($nameCodes) : [];
    $watch = [];
    try { $watch = array_flip($pf->watchCodes()); } catch (Throwable $e) { /* 없으면 별 없이 */ }

    $hasProv = false;
    foreach ($rows as &$r) {
        $r['badge'] = pf_surge_badge(
            $r['avg_mul'] !== null ? (float)$r['avg_mul'] : null,
            $r['chg']     !== null ? (float)$r['chg']     : null);
        if ($r['src'] === 'n') $hasProv = true;
    }
    unset($r);
    usort($rows, static fn($a, $b) => [$a['badge'][0], -(float)$a['amt']] <=> [$b['badge'][0], -(float)$b['amt']]);

    $cnt = [0 => 0, 1 => 0, 2 => 0, 3 => 0];
    foreach ($rows as $r) $cnt[$r['badge'][0]]++;

    // 우측 패널의 초기 선택 — 주소에 실려 새로고침·북마크에서도 유지된다
    $selCode = preg_replace('/[^0-9]/', '', (string)($_GET['code'] ?? ''));
    if (!preg_match('/^\d{6}$/', $selCode)) $selCode = '';

    pf_head('퀀트 · 최고 거래대금', 'quant', 'wide');
    pf_subtabs('surge', 'quant');
    pf_flash();
    pf_quant_css();

    echo '<div class="pf-head"><div><h1>최고 거래대금</h1>';
    echo '<div class="sub">그 종목의 <b>최근 ' . KrxAmt::SURGE_WIN . '거래일 중 최고 거래대금</b>을 찍은 종목입니다'
       . ' (이력 ' . KrxAmt::SURGE_WIN . '일 미만 제외 · 코스피/코스닥 · 차트 지표 「최고_거래대금선」과 같은 창).'
       . ' 배지는 백테스트로 판정 근거를 검증했습니다 — <a href="/stock/index.php?mode=quantstat">검증 탭</a>.</div></div>';

    // 날짜 이동 + 최소 거래대금 — 조건이 주소에 실려 북마크로 재현된다
    if ($dates) {
        echo '<div class="act"><form method="get" action="/stock/index.php" class="fld-row">'
           . '<input type="hidden" name="mode" value="quant">'
           . ($selCode !== '' ? '<input type="hidden" name="code" value="' . pf_h($selCode) . '">' : '')
           . '<select name="d" onchange="this.form.submit()">';
        foreach ($dates as $dd) {
            echo '<option value="' . pf_h($dd) . '"' . ($dd === $day ? ' selected' : '') . '>' . pf_h($dd) . '</option>';
        }
        echo '</select>'
           . ' <label style="font-size:13px;white-space:nowrap">최소 거래대금 '
           . '<input type="text" class="num-comma" inputmode="numeric" name="min" value="'
           . pf_h($minEok > 0 ? number_format($minEok) : '0')
           . '" style="width:64px;text-align:right"> 억</label>'
           . ' <button class="btn btn-outline btn-sm">보기</button></form></div>';
    }
    echo '</div>';

    if ($err !== '')  { echo '<div class="warn">조회 실패: ' . pf_h($err) . '</div>'; pf_foot(); return; }
    if ($day === '')  { echo '<div class="warn">아직 원장이 비어 있습니다 — 백필(cron/krx_amt.php job=backfill)을 먼저 돌리세요.</div>'; pf_foot(); return; }

    /* ── 좌우 2단 — 좌측 목록 · 우측 = <b>재무분석 상세를 embed 모드로 그대로</b>(iframe).
     * 마크업을 복제하지 않으므로 재무분석 화면을 고치면 이 패널에도 저절로 반영된다.
     * JS 가 없으면 종목명 링크가 재무분석 페이지로 가는 폴백이 그대로 남는다. */
    $panel = $selCode !== ''
        ? '<iframe id="qFundFrame" src="/stock/index.php?mode=fund&code=' . pf_h($selCode) . '&embed=1"></iframe>'
          . '<div class="q-ph" id="qPh" style="display:none"></div>'
        : '<iframe id="qFundFrame" style="display:none"></iframe>'
          . '<div class="q-ph" id="qPh">왼쪽 목록에서 종목을 클릭하면<br>재무분석 상세(11년 재무 + 일봉차트)가 여기에 열립니다.</div>';

    echo '<div class="q-split"><div class="q-left">';

    if ($hasProv) {
        echo '<div class="q-prov">★ <b>' . pf_h($day) . ' 은 당일 마감 잠정치</b>(네이버 스냅샷)입니다 — '
           . '내일 13:05 KRX 확정값으로 자동 대체됩니다. 시가·고가·저가는 잠정치에 없습니다.</div>';
    }

    /* ── 매집형 박스 추적 — 경로 연구의 승자 규칙을 매일 보는 자리 ── */
    if ($acc) {
        $prio = ['bx-brk' => 0, 'bx-in' => 1, 'bx-new' => 2, 'bx-lad' => 3, 'bx-fake' => 4, 'bx-dn' => 5, 'bx-na' => 6];
        usort($acc, function ($x, $y) use ($accBox, $prio) {
            $sx = $prio[$accBox[$x['code'] . '|' . $x['d']]['st'] ?? 'bx-na'] ?? 9;
            $sy = $prio[$accBox[$y['code'] . '|' . $y['d']]['st'] ?? 'bx-na'] ?? 9;
            return [$sx, $y['d']] <=> [$sy, $x['d']];
        });
        echo '<div class="card"><h2>🟢 매집형 박스 추적 <span class="q-note" style="display:inline">'
           . '(최근 45일 · 하한 100억 · 종목당 최신 박스)</span></h2>';
        echo '<div class="tbl-scroll"><table class="pf pos"><thead><tr>';
        foreach ([['박스 상태', 'center'], ['종목', ''], ['신호일', 'center'],
                  ['저항 H', 'num'], ['지지 L', 'num']] as [$l, $cl]) {
            echo '<th class="' . $cl . '">' . pf_h($l) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach (array_slice($acc, 0, 15) as $a2) {
            $b2 = $accBox[$a2['code'] . '|' . $a2['d']] ?? ['st' => 'bx-na', 'txt' => '-', 'tip' => '', 'h' => 0, 'l' => 0];
            echo '<tr class="q-row" data-code="' . pf_h($a2['code']) . '">';
            echo '<td class="center"><span class="bx ' . $b2['st'] . '" title="' . pf_h($b2['tip']) . '">' . pf_h($b2['txt']) . '</span>'
               . $hotChip($mom[$a2['code'] . '|' . $a2['d']] ?? null) . '</td>';
            echo '<td class="stk"><a class="q-name" href="/stock/index.php?mode=fund&code=' . pf_h($a2['code']) . '">'
               . pf_h($names[$a2['code']] ?? $a2['code']) . '</a><span class="code">' . pf_h($a2['code']) . '</span></td>';
            echo '<td class="center">' . pf_h(substr($a2['d'], 5)) . '</td>';
            echo '<td class="num">' . ($b2['h'] > 0 ? pf_n($b2['h']) : '-') . '</td>';
            echo '<td class="num">' . ($b2['l'] > 0 ? pf_n($b2['l']) : '-') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '<div class="q-note">실측 규칙(검증 탭 ④~⑥): 매수 = <b>돌파✓</b>(+2.26%·57.9%) 또는 <b>계단지지 1단</b>'
           . '(<b>아래층 박스 3개 이상일 때만</b> +2.40%·58.5% — 1~2개는 실측 음수라 ⚠표시·관망) 확인일. '
           . '매도 = 손절선(진입박스 L·새 박스 생기면 상향) 기준 — <b>돌파 진입은 바로 아래 계단이 종가로 깨질 때</b>(시간 규칙 없음), '
           . '<b>계단지지 진입은 20거래일 보유 후 손절선 이탈 시</b>. 폭발형·추격주의의 돌파는 금지(−5.7~−7.6%).</div></div>';
    }

    echo '<div class="card"><h2>' . pf_h($day) . ' — ' . count($rows) . '종목 '
       . '<span class="q-note" style="display:inline">'
       . '(🟢매집형 ' . $cnt[0] . ' · 중립 ' . $cnt[1] . ' · 폭발형 ' . $cnt[2] . ' · 추격주의 ' . $cnt[3]
       . ($hidden > 0 ? ' · 거래대금 ' . number_format($minEok) . '억 미만 <b>' . $hidden . '종목 숨김</b>' : '')
       . ')</span></h2>';

    if (!$rows) {
        echo '<div class="warn">이 날짜에는 ' . ($hidden > 0
            ? '거래대금 ' . number_format($minEok) . '억 이상인 신호가 없습니다 (' . $hidden . '종목이 하한에 걸림 — 하한을 낮춰 보세요).'
            : '신호가 없습니다.') . '</div></div>';
        echo '</div><div class="q-right">' . $panel . '</div></div>';   // 좌측 닫고 우측 패널
        pf_foot(); return;
    }

    echo '<div class="tbl-scroll"><table class="pf pos"><thead><tr>';
    foreach ([['배지', 'center'], ['박스', 'center'], ['', 'center'], ['종목', ''], ['시장', 'center'], ['종가', 'num'],
              ['등락률', 'num'], ['거래대금(억)', 'num'], [KrxAmt::SURGE_WIN . '일최고 대비', 'num'],
              ['20일평균 대비', 'num'], ['시총(억)', 'num']] as [$l, $cl]) {
        echo '<th class="' . $cl . '">' . pf_h($l) . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($rows as $r) {
        [, $bc, $bl, $bt] = $r['badge'];
        $code = $r['code'];
        $on   = isset($watch[$code]);
        $name = $names[$code] ?? $code;
        $bx = $box[$code . '|' . $day] ?? null;
        echo '<tr class="q-row' . ($code === $selCode ? ' q-sel' : '') . '" data-code="' . pf_h($code) . '">';
        echo '<td class="center"><span class="qb ' . $bc . '" title="' . pf_h($bt) . '">' . pf_h($bl) . '</span>'
           . $hotChip($mom[$code . '|' . $day] ?? null) . '</td>';
        echo '<td class="center">' . ($bx
            ? '<span class="bx ' . $bx['st'] . '" title="' . pf_h($bx['tip']) . '">' . pf_h($bx['txt']) . '</span>'
            : '-') . '</td>';
        echo '<td class="center"><button type="button" class="wl-star' . ($on ? ' on' : '') . '" data-code="'
           . pf_h($code) . '" data-name="' . pf_h($name) . '" title="관심종목 담기/빼기">' . ($on ? '★' : '☆') . '</button></td>';
        // href 는 JS 없을 때의 폴백 — JS 가 있으면 우측 패널에 embed 로 연다
        echo '<td class="stk"><a class="q-name" href="/stock/index.php?mode=fund&code=' . pf_h($code) . '">' . pf_h($name)
           . '</a><span class="code">' . pf_h($code) . '</span></td>';
        echo '<td class="center">' . ($r['mkt'] === 'Q' ? '코스닥' : '코스피') . '</td>';
        echo '<td class="num">' . pf_n($r['c']) . '</td>';
        $chg = $r['chg'] !== null ? (float)$r['chg'] : null;
        echo '<td class="num">' . ($chg === null ? '-'
            : '<span class="' . pf_updown($chg) . '">' . pf_pct($chg, 1) . '</span>') . '</td>';
        echo '<td class="num"><b>' . pf_eok($r['amt']) . '</b></td>';
        echo '<td class="num">' . number_format((float)$r['mul'], 2) . '배</td>';
        echo '<td class="num">' . ($r['avg_mul'] === null ? '-' : number_format((float)$r['avg_mul'], 1) . '배') . '</td>';
        echo '<td class="num">' . ($r['mktcap'] ? pf_eok($r['mktcap']) : '-') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    echo '<div class="q-note">행 클릭 = 재무분석 상세(11년 재무 + 일봉차트) · ☆ = 관심종목 · '
       . '정렬 = 배지(매집형 먼저) → 거래대금 큰 순 · 거래대금 0(거래정지)인 날은 판정에서 제외 · '
       . '급등⚠ = 신호일까지 20일 +80% 또는 40일 +100% 급등 — 이 무리의 돌파 매수는 실측 엣지 없음(검증 탭 ⑧)</div>';
    echo '</div>';

    // 배지 근거 요약 — 전문은 검증 탭
    echo '<details class="card" style="padding:12px 16px"><summary style="cursor:pointer;font-weight:700">'
       . '배지는 어떻게 정했나 (백테스트 요약 · 창 120일 · 하한 100억)</summary>'
       . '<div style="font-size:13px;line-height:1.7;margin-top:8px">'
       . '신호 6,942건(2025-02~2026-07 · 하한 100억)의 <b>다음날 매수 → +20거래일 수익률에서 같은 날 전종목 중앙값을 뺀 초과수익</b>으로 판정했습니다.<br>'
       . '· 최고 거래대금 전체는 중앙 <b>−3.36%</b> · 승률 41.7% — "터진 종목 추격"은 절반 이상이 시장보다 못 갑니다.<br>'
       . '· 🟢 <b>매집형</b>(20일 평균의 5배 이하 <b>+ 등락 0~10%</b>): 중앙 <b>+1.74%</b> · 승률 55.2% — 유일하게 견고히 이기는 무리.<br>'
       . '· <b>폭발형</b>(20배 이상): 중앙 −4.24% · 승률 36.7% / <b>추격주의</b>(등락 +20%↑): 중앙 <b>−7.23%</b> · 승률 34.6%.<br>'
       . '· 두 기간으로 갈라도 부호가 유지됐습니다(매집형 단독은 후반기 반전이라 등락 조건을 붙였습니다).'
       . ' 자세한 표·방법·한계는 <a href="/stock/index.php?mode=quantstat">검증 탭</a>.'
       . '</div></details>';

    // 좌측 닫고 우측 패널(재무분석 embed)
    echo '</div><div class="q-right">' . $panel . '</div></div>';

    // ☆ 토글(재무분석과 같은 API) + 행/종목명 클릭 → 우측 패널. 캡처 단계 — 행 클릭보다 별이 먼저 먹어야 한다.
    echo <<<'JS'
<script>
document.addEventListener('click', function(e){
  var b = e.target.closest ? e.target.closest('.wl-star') : null;
  if (!b) return;
  e.stopPropagation();
  e.preventDefault();
  var body = new URLSearchParams({json:'1', code:b.getAttribute('data-code'), name:b.getAttribute('data-name')});
  fetch('/stock/api.php?module=watch&action=toggle',
    {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body})
    .then(function(r){ return r.json(); })
    .then(function(j){
      if (!j || !j.ok) { alert((j && j.message) || '실패했습니다.'); return; }
      var on = j.message.indexOf('담았습니다') >= 0;
      b.classList.toggle('on', on);
      b.textContent = on ? '★' : '☆';
    })
    .catch(function(){ alert('통신에 실패했습니다.'); });
}, true);

/* 우측 패널에 재무분석 상세를 embed 로 연다. 주소의 code 도 갱신해 새로고침에도 남는다. */
function qOpen(code, tr){
  var f = document.getElementById('qFundFrame'), ph = document.getElementById('qPh');
  if (!f) { location.href = '/stock/index.php?mode=fund&code=' + code; return; }
  if (ph) ph.style.display = 'none';
  f.style.display = 'block';
  f.src = '/stock/index.php?mode=fund&code=' + code + '&embed=1';
  var old = document.querySelectorAll('tr.q-row.q-sel');
  for (var i = 0; i < old.length; i++) old[i].classList.remove('q-sel');
  if (tr) tr.classList.add('q-sel');
  try {
    var u = new URL(location.href);
    u.searchParams.set('code', code);
    history.replaceState(null, '', u);
  } catch (err) {}
}
document.addEventListener('click', function(e){
  if (e.target.closest && e.target.closest('.wl-star')) return;
  var a = e.target.closest ? e.target.closest('a.q-name') : null;
  if (a) {                                        // 종목명 클릭도 패널로 (href 는 JS 없을 때 폴백)
    e.preventDefault();
    qOpen(a.closest('tr').getAttribute('data-code'), a.closest('tr'));
    return;
  }
  var tr = e.target.closest ? e.target.closest('tr.q-row') : null;
  if (!tr || (e.target.closest && e.target.closest('a'))) return;
  qOpen(tr.getAttribute('data-code'), tr);
});
</script>
JS;

    pf_foot();
}

/**
 * 검증 보고서 — <b>측정치를 코드에 박아 둔 정적 화면</b>이다.
 * 전 이력 백테스트는 13~15초라 웹 요청(30초 한도)에서 매번 돌릴 물건이 아니고,
 * 원장이 하루 한 줄씩 늘 뿐이라 결론이 날마다 바뀌지도 않는다.
 * 재측정하려면 SSH 로 검증 스크립트를 다시 돌려 이 표를 갱신한다 (측정일을 함께 바꾼다).
 */
function pf_page_quantstat(PDO $pdo, Pf $pf): void
{
    pf_head('퀀트 · 검증', 'quant');
    pf_subtabs('stat', 'quant');
    pf_quant_css();

    echo '<div class="pf-head"><div><h1>검증 — 최고 거래대금이 수익으로 이어졌나</h1>';
    echo '<div class="sub"><b>측정 2026-07-31</b> · 창 <b>120거래일</b> · 신호 하한 <b>100억</b> (화면 기본값과 동일 조건) · '
       . '신호 6,942건 · 2025-02-04 ~ 2026-07-31 · krx_amt 전종목 원장(KRX 공식값)</div></div></div>';

    echo '<div class="card"><h2>방법</h2><div style="font-size:13px;line-height:1.8">'
       . '· 신호 = 그 날 거래대금 ≥ 100억이고, 그 종목 직전 119거래일 최고를 넘음 (이력 120행 미만·거래정지일 제외)<br>'
       . '· 수익 = <b>다음날 종가 매수 → +20거래일 종가</b> (신호는 마감 후에 아는 것이라 실전 기준으로 잰다)<br>'
       . '· ★ <b>초과수익</b> = 수익률 − <b>같은 날 판정가능 전종목의 중앙값</b> — 시장이 통째로 오른 달의 착시를 제거.<br>'
       . '· ★ 상장주식수가 5% 넘게 바뀐 구간은 제외(액면분할이 만드는 가짜 −90% 방지). 앞날 없는 신호(상폐·정지)는 따로 셈.<br>'
       . '· 60일 창·하한 없음(신호 23,605건)으로도 같은 구조를 확인했다 — 임계와 수치만 다르고 결론 방향은 같았다.</div></div>';

    echo '<div class="card"><h2>① 전체 — 추격은 지는 게임 (120일 창에서 더 뚜렷하다)</h2>';
    echo '<table class="qstat"><tr><th>모집단</th><th>표본</th><th>초과 중앙</th><th>초과 평균</th><th>승률</th></tr>'
       . '<tr><td>하한 100억 (화면 기본)</td><td>6,501</td><td class="down"><b>−3.36%</b></td><td>+1.04%</td><td>41.7%</td></tr>'
       . '<tr><td>(참고) 하한 없음</td><td>10,720</td><td class="down">−1.24%</td><td>+2.33%</td><td>45.9%</td></tr></table>'
       . '<div class="q-note">★100억 하한을 걸면 오히려 <b>더 나빠진다</b> — 100억을 넘기며 120일 최고 거래대금을 찍는 날은'
       . ' 대개 급등·폭등일이라(전체의 49%), 하한이 요란한 신호를 골라 담는 셈이다.'
       . ' 하한은 "살 만한 유동성"을 거르는 도구이지 수익 필터가 아니다. 중앙 음수·평균 양수 = 복권 분포.</div></div>';

    echo '<div class="card"><h2>② 요란할수록 나쁘다</h2>';
    echo '<table class="qstat"><tr><th colspan="5">신호일 등락률별 (+20일 · 다음날 매수)</th></tr>'
       . '<tr><th>등락</th><th>표본</th><th>초과 중앙</th><th>초과 평균</th><th>승률</th></tr>'
       . '<tr><td>하락 (−2%↓)</td><td>624</td><td class="down">−3.85%</td><td>−1.29%</td><td>40.1%</td></tr>'
       . '<tr><td><b>보합 (±2%)</b></td><td>471</td><td>−0.09%</td><td>+3.58%</td><td>49.3%</td></tr>'
       . '<tr><td>상승 (2~10%)</td><td>1,979</td><td>−1.44%</td><td>+2.61%</td><td>46.3%</td></tr>'
       . '<tr><td>급등 (10~20%)</td><td>2,082</td><td class="down">−3.92%</td><td>+1.59%</td><td>40.7%</td></tr>'
       . '<tr><td><b>폭등 (20%↑)</b></td><td>1,345</td><td class="down"><b>−7.23%</b></td><td>−1.89%</td><td><b>34.6%</b></td></tr></table>'
       . '<table class="qstat"><tr><th colspan="5">거래대금이 20일 평균의 몇 배였나 (+20일)</th></tr>'
       . '<tr><th>20일평균 대비</th><th>표본</th><th>초과 중앙</th><th>초과 평균</th><th>승률</th></tr>'
       . '<tr><td>0~3배</td><td>400</td><td>−1.08%</td><td>+3.04%</td><td>46.8%</td></tr>'
       . '<tr><td><b>3~5배</b></td><td>824</td><td class="up">+1.15%</td><td>+4.68%</td><td><b>53.2%</b></td></tr>'
       . '<tr><td>5~10배</td><td>1,611</td><td class="down">−3.26%</td><td>+1.07%</td><td>42.4%</td></tr>'
       . '<tr><td>10~20배</td><td>1,430</td><td class="down">−4.79%</td><td>+0.45%</td><td>40.8%</td></tr>'
       . '<tr><td><b>20배↑ (폭발형)</b></td><td>2,236</td><td class="down"><b>−4.24%</b></td><td>−0.29%</td><td><b>36.7%</b></td></tr></table></div>';

    echo '<div class="card"><h2>③ 배지 조합 — 기간을 갈라도 부호가 유지되나</h2>';
    echo '<table class="qstat"><tr><th>조합 (+20일 · 다음날 매수)</th><th>표본</th><th>초과 중앙</th><th>승률</th>'
       . '<th>~2025-10</th><th>2025-11~</th></tr>'
       . '<tr><td>매집형 단독 (20평비 ≤5)</td><td>1,224</td><td>+0.50%</td><td>51.1%</td><td>+1.15%</td>'
       . '<td class="down"><b>−0.13%</b> ⚠</td></tr>'
       . '<tr><td>🟢 <b>매집형 + 등락 0~10%</b> (배지 기준)</td><td>569</td><td class="up"><b>+1.74%</b></td>'
       . '<td><b>55.2%</b></td><td>+2.26%</td><td>+1.46%</td></tr>'
       . '<tr><td>폭발형 (20평비 20↑)</td><td>2,236</td><td class="down">−4.24%</td><td>36.7%</td><td>−4.36%</td><td>−4.08%</td></tr>'
       . '<tr><td>추격주의 (등락 20%↑)</td><td>1,345</td><td class="down">−7.23%</td><td>34.6%</td><td>−9.47%</td><td>−5.85%</td></tr></table>'
       . '<div class="q-note">★120일 창에서 <b>매집형 단독은 후반기에 부호가 뒤집혔다</b>(레포 규칙: 그런 것은 믿지 않는다)'
       . ' → 🟢 배지에는 <b>등락 0~10% 조건을 함께</b> 건다. 이 조합은 두 기간 모두 ＋이고 60일판(+1.27%)보다도 강했다.'
       . ' 매집형은 475개 종목에 분산(종목당 2.7건) — 소수 종목의 반복이 아니다.</div></div>';

    echo '<div class="card"><h2>④ 경로 연구 — 지지·저항 (신호 6,058건 · 60거래일 추적)</h2>'
       . '<div style="font-size:13px;line-height:1.8">'
       . '신호일 <b>고가 H = 저항</b> · <b>저가 L = 지지</b>. <b>계단</b> = 현재 지지선 아래 레벨을 주는'
       . ' <b>최근 박스 2개</b>의 H·L(아래층 지지 — 옛 박스도 살아있는 지지선). '
       . '전부 <b>종가 기준</b>, 레벨 허용오차 ±2%. "먼저 일어난 일"로 경로를 가른다.</div>'
       . '<table class="qstat"><tr><th>신호 후 60일, 무엇이 먼저?</th><th>비율</th></tr>'
       . '<tr><td>돌파 (종가 &gt; H)</td><td>45.8% (직행 36.4 + 지지시험 후 9.4)</td></tr>'
       . '<tr><td>붕괴 (종가 &lt; L)</td><td>52.8%</td></tr>'
       . '<tr><td>60일 내 박스 체류</td><td>1.3%</td></tr></table>'
       . '<div class="q-note" style="margin-bottom:10px">★붕괴해도 <b>계단이 있으면 74.9%가 계단에서 지지</b>되고 관통은 <b>2.5%뿐</b>'
       . ' — "옛 박스도 지지선"이 실측으로 확인됐다. 위험한 것은 계단이 아예 없는 <b>첫 폭발</b>(받아줄 곳이 없다).'
       . ' 무차별 지지 매수는 손절 발동률 78%로 실패.</div>'
       . '<table class="qstat"><tr><th>규칙 (확인일 종가 매수 · +20일 초과)</th><th>표본</th><th>중앙</th><th>승률</th>'
       . '<th>~25-10</th><th>25-11~</th></tr>'
       . '<tr><td>🟢 <b>매집형 × 돌파 확인</b></td><td>304</td><td class="up"><b>+2.26%</b></td><td><b>57.9%</b></td>'
       . '<td>+1.69%</td><td>+2.97%</td></tr>'
       . '<tr><td>🟢 매집형 × 계단지지 확인 (1단 지지 +1.76%·55.9% 가 주력 — 2단 이하는 n=7 로 판정 보류'
       . ' · <b>⑥ 위치 연구로 개선: 아래층 박스 3개 이상만 +2.40%·58.5%</b>)</td>'
       . '<td>134</td><td class="up">+0.97%</td><td>54.5%</td><td>+0.88%</td><td>+1.76%</td></tr>'
       . '<tr><td>🚫 추격주의 × 돌파 (돌파율은 67.7%로 최고인데 사면 진다)</td><td>821</td>'
       . '<td class="down">−7.60%</td><td>35.7%</td><td>−10.68%</td><td>−5.44%</td></tr>'
       . '<tr><td>🚫 폭발형 × 돌파</td><td>431</td><td class="down">−5.72%</td><td>37.4%</td><td>−4.88%</td><td>−6.78%</td></tr></table>'
       . '<div class="q-note">네 규칙 모두 기간 2분할에서 부호 유지 · 매집형 돌파는 178개 종목에 분산.'
       . ' 「계단 번호·대금 가속으로 막차를 미리 가려내기」는 판별력이 약해 채택하지 않았다(정직하게 기각).'
       . ' 계단 정의는 삼성물산 사례(직전 박스가 위에 겹쳐 진짜 아래층을 잃음)로 한 번 고쳐 재측정했다.'
       . ' 목록의 「박스」 열과 「매집형 박스 추적」 카드가 이 규칙을 매일 보여 준다.</div></div>';

    echo '<div class="card"><h2>⑤ 청산 연구 — 매도도 박스 기준으로 (2026-08-01)</h2>'
       . '<div style="font-size:13px;line-height:1.8">'
       . '진입은 위 두 트리거 그대로 두고 <b>언제 파나</b>를 쟀다. 잣대는 보유구간 수익 − 같은 구간 시장'
       . '(전종목 일중앙 누적) — <b>④의 숫자와 직접 비교하지 말 것</b>(기준선이 다르다). 최대 120거래일.</div>'
       . '<table class="qstat"><tr><th>돌파 진입(n=296)의 청산</th><th>초과중앙</th><th>평균</th><th>승률</th><th>보유중앙</th></tr>'
       . '<tr><td>+20거래일 고정</td><td>+6.13%</td><td>+8.72%</td><td>63.2%</td><td>20일</td></tr>'
       . '<tr><td>처음부터 박스 스톱(종가&lt;L)</td><td class="down">−1.00%</td><td>+20.28%</td><td>47.0%</td><td>30일</td></tr>'
       . '<tr><td>즉시 재진입 청산(종가&lt;H)</td><td class="down">−1.81%</td><td>+8.69%</td><td>32.4%</td><td>5일</td></tr>'
       . '<tr><td>20일 보유 → L+계단 트레일링</td><td class="up">+4.48%</td><td>+17.90%</td>'
       . '<td><b>61.8%</b></td><td>23일</td></tr>'
       . '<tr><td>🟢 <b>한 계단 유예 (시간 규칙 0)</b></td><td class="up"><b>+4.59%</b></td><td><b>+26.01%</b></td>'
       . '<td>57.4%</td><td>37일</td></tr></table>'
       . '<div class="q-note" style="margin-bottom:8px">★박스 레벨은 주가가 출렁이는 자리라 <b>처음부터 스톱을 붙이면'
       . ' 노이즈에 5~6일 만에 털린다</b>(승률 32%). 재탈환 유예(G일 내 회복 시 계속)도 잠금보다 못했다.'
       . ' <b>이긴 것은 「한 계단 유예」</b> — 손절선이 깨져도 바로 아래 계단이 살아있으면 버티고, 그 계단마저 종가로'
       . ' 깨질 때만 청산. 기간분할 +11.71/+3.66 양쪽 ＋. 대가: 승률 4%p↓ · 보유 길어짐 · 21%는 120일 넘게 보유.'
       . ' ★단 <b>계단지지 진입(T②)에는 안 맞는다</b>(+0.45%·51.9%) — 이미 깊은 계단에서 산 자리라 한 계단 더는'
       . ' 너무 깊다(케이씨텍이면 −27%). T②는 20일 잠금이 우세(+3.00 · 60.7% · 분할 +0.44/+3.53).</div>'
       . '<div style="font-size:13px;line-height:1.8;background:#f4f9f4;border-radius:8px;padding:10px 14px">'
       . '<b>확정 매도 규칙</b> — 공통: 손절선 = 진입 박스의 지지 L, 보유 중 <b>새 신호 박스가 생기면 그 박스의 L 로'
       . ' 상향</b>(계단 트레일링 — 옛 손절선은 아래 계단으로 남는다).<br>'
       . '· <b>돌파 진입(T①)</b>: 시간 규칙 없음 — 손절선 <b>바로 아래 계단이 종가로 깨지는 날</b> 청산.<br>'
       . '· <b>계단지지 진입(T②)</b>: 20거래일 무조건 보유 → 21일째부터 종가가 손절선 아래면 청산.</div></div>';

    echo '<div class="card"><h2>⑥ 위치 연구 — 저점권 vs 고점권 매집형 (2026-08-01)</h2>'
       . '<div style="font-size:13px;line-height:1.8">'
       . '계단 꼭대기의 매집형(삼성물산 6/19 같은)은 사실상 분산이니 미리 걸러야 하나? 위치 지표 두 개로 쟀다 —'
       . ' <b>아래층 박스 수</b>(현재 지지 L 아래에 레벨을 주는 과거 박스 수 · "계단 몇 층 위")와'
       . ' <b>120일 가격범위 내 위치</b>(0=저점권·1=고점권). 잣대는 ④와 동일(+20일 초과).</div>'
       . '<table class="qstat"><tr><th>계단지지 확인 매수 × 아래층 박스 수</th><th>표본</th><th>중앙</th><th>승률</th>'
       . '<th>~25-10</th><th>25-11~</th></tr>'
       . '<tr><td>아래층 1~2개</td><td>40</td><td class="down">−2.47%</td><td>45.0%</td>'
       . '<td>−0.12%</td><td>−2.79%</td></tr>'
       . '<tr><td>🟢 <b>아래층 3개 이상</b> (85개 종목 분산 · 1단이 받는 케이스가 90/94)</td><td>94</td>'
       . '<td class="up"><b>+2.40%</b></td><td><b>58.5%</b></td><td>+2.30%</td><td>+2.40%</td></tr></table>'
       . '<div class="q-note" style="margin-bottom:10px">★<b>가설은 기각, 방향이 반대였다</b> — 계단이 많이 쌓인 자리일수록'
       . ' 좋다. 매집형은 애초에 거의 전부 고점권이라(범위위치 중앙 0.91 · ¾이 0.81 위) "저점권 매집형" 필터는'
       . ' 표본 자체가 없어 성립하지 않고, 저점 대비 상승률로 갈라도 <b>이미 많이 오른 상위⅓(+93%↑)이 최고</b>'
       . '(돌파 +5.10%·승률 60%). 결론: <b>규칙2(계단지지 매수)에 「아래층 박스 3개 이상」 조건을 채택</b>'
       . '(+0.97%→+2.40%) — 목록의 계단지지 배지에 미충족 ⚠를 단다. 청산 규칙(⑤)은 그대로.</div>'
       . '<div class="q-note">함정 둘을 걸러냈다: ①아래층 수는 신호 이력이 2025-02 시작이라 <b>초기 신호일수록 기계적으로'
       . ' 0</b>이 된다 — 이력 6개월 이상 쌓인 2025-08 이후 표본만으로 재확인했다(같은 결론). ②돌파 매수의'
       . ' 첫 박스(아래층 0개)는 그 구간에서 −1.94%(n=19)로 약했지만 표본이 작고 시기별로 엇갈려 <b>규칙 변경은'
       . ' 보류</b>했다(레포 규칙: 견고하지 않으면 믿지 않는다). 삼성물산 6/19은 아래층 3개↑ 무리(좋은 쪽)의'
       . ' 일원이다 — 그 −20%는 규칙이 지는 41.5% 안의 정상 사례로, 개별 사례 하나로 필터를 만들지 않는다.</div></div>';

    echo '<div class="card"><h2>⑦ 박스 기간 연구 — 「오래 버틴 박스 = 강한 지지선」인가 (2026-08-01)</h2>'
       . '<div style="font-size:13px;line-height:1.8">'
       . '가설(삼성물산 차트에서 출발): 박스가 n일 이상 버티면 그동안 쌓인 매집·지지의 힘으로 <b>더 강한 지지선</b>이'
       . ' 되고, 그런 박스는 확률을 갖고 들어가도 되지 않나. 둘로 갈라 쟀다 — <b>기각</b>, 그것도 양방향에서.</div>'
       . '<table class="qstat"><tr><th>계단 지지율 × 1단 계단의 원천 박스 체류기간 (붕괴 전수 2,235건 · 전 배지)</th>'
       . '<th>표본</th><th>지지</th><th>관통</th></tr>'
       . '<tr><td>원천 1~2일</td><td>1,091</td><td>76.7%</td><td>2.5%</td></tr>'
       . '<tr><td>원천 3~5일</td><td>400</td><td>73.0%</td><td>3.0%</td></tr>'
       . '<tr><td>원천 6~10일</td><td>311</td><td>75.2%</td><td>2.6%</td></tr>'
       . '<tr><td>원천 11일 이상</td><td>433</td><td>71.6%</td><td>2.1%</td></tr></table>'
       . '<div class="q-note" style="margin-bottom:10px">★<b>완전히 평평하다</b> — 오래 버틴 박스라고 더 잘 받아주지 않는다'
       . '(오히려 근소하게 낮다). 지지의 힘은 박스의 <b>기간</b>이 아니라 <b>층수</b>에서 온다 — 그건 ⑥의'
       . ' 「아래층 3개 이상」으로 이미 채택돼 있다. 직관의 실체는 시간이 아니라 겹이었다.</div>'
       . '<table class="qstat"><tr><th>매집형 박스는 어느 쪽으로 풀리나 × 체류기간 (544건)</th><th>돌파</th><th>붕괴</th></tr>'
       . '<tr><td>1~3일 만에 해소</td><td class="up"><b>59.9%</b></td><td>40.1%</td></tr>'
       . '<tr><td>4~10일</td><td>47.4%</td><td>52.6%</td></tr>'
       . '<tr><td>11~20일</td><td class="down">37.5%</td><td>62.5%</td></tr>'
       . '<tr><td>21일 이상 (n=3)</td><td class="down">0%</td><td>100%</td></tr></table>'
       . '<div class="q-note" style="margin-bottom:10px">★오히려 반대 — <b>매집형 박스에는 유통기한이 있다</b>.'
       . ' 최고 거래대금는 에너지 이벤트라, 그 힘으로 2주 안에 못 오르면 소진된 것으로 읽어야 데이터에 맞다.'
       . ' 늦은 돌파는 사도 나쁘다: 1~3일 내 돌파 매수 +2.85%·58.3%(양기간 ＋) / 4~10일 +2.16%·60.3% /'
       . ' <b>11일 넘긴 돌파 −6.68%·승률 16.7%</b>(n=6·1승 5패 — 표본이 작아 금지 규칙 대신 ⚠주의로만 반영).'
       . ' 「박스가 길어서 강해진」 것이 아니라 <b>「강했기 때문에 박스가 짧았던」</b> 것이다.</div>'
       . '<div class="q-note">덧붙여 「긴 박스면 중립 배지도 사볼까」도 기각: 중립 × 돌파는 전체가 동전 던지기'
       . '(1,215건 · 중앙 −1.23% · 승률 47.5%)고, 체류 21일 이상으로 좁혀도(n=36) 기간 2분할에서 부호가 뒤집힌다.'
       . ' 준매집형(등락 10~20%)도 −3.30/+3.17 반전 — <b>기간은 배지를 이기지 못한다</b>. 배지 임계 10%는 유효하다.'
       . ' 반영: 목록의 「박스안」 상태에 체류 10일 초과 시 경과일 ⚠를 단다.</div></div>';

    echo '<div class="card"><h2>⑧ 단기급등 연구 — 급하게 온 최고 거래대금은 엣지가 없다 (2026-08-01)</h2>'
       . '<div style="font-size:13px;line-height:1.8">'
       . '발단은 SK하이닉스 6/22 돌파 매수 −19.8%: 진입 전 40거래일 <b>+138%</b> 급등의 꼭대기였다'
       . ' (같은 규칙의 실패 사례인 툴젠도 20일 +110%, 프럼파스트도 40일 +91%). <b>진입 직전 20거래일'
       . ' 수익률(급등 속도)</b>로 규칙1(매집형×돌파) 470건을 갈랐다 — 잣대는 V6 근사 청산(계단 트레일링 포함)의'
       . ' 보유구간 초과수익이라 <b>④의 +20일 잣대와 수치를 직접 비교하면 안 된다</b>.</div>'
       . '<table class="qstat"><tr><th>진입 전 20거래일 수익률</th><th>표본</th><th>중앙</th><th>승률</th></tr>'
       . '<tr><td>+10% 미만</td><td>56</td><td class="up"><b>+14.86%</b></td><td><b>69.6%</b></td></tr>'
       . '<tr><td>+10~25%</td><td>159</td><td>+6.76%</td><td>59.7%</td></tr>'
       . '<tr><td>+25~50%</td><td>171</td><td>+3.72%</td><td>56.7%</td></tr>'
       . '<tr><td>+50~80%</td><td>53</td><td>+10.37%</td><td>56.6%</td></tr>'
       . '<tr><td>🔥 <b>+80% 이상</b></td><td>31</td><td class="down"><b>0.00%</b></td><td><b>51.6%</b></td></tr></table>'
       . '<div class="q-note" style="margin-bottom:10px">★승률이 단조로 악화한다 — ⑥(120일 저점대비 「얼마나 올랐나」는'
       . ' 무해·오히려 상위가 최고)과 대비되는 결과로, <b>「얼마나 빨리 올랐나」가 유해</b>하다. 극단(20일 +80% 또는'
       . ' 40일 +100% — 40일 기준 ≥100%도 중앙 0.00%·승률 51.0%)은 동전 던지기가 된다. 단 이 무리도 <b>평균은'
       . ' +7.1%로 양수</b>(복권 꼬리)라 자동 차단이 아니라 <b>목록 배지 옆 급등⚠ 표시만 채택</b>했다 — 사는 건'
       . ' 복권임을 알고 사는 것.</div>'
       . '<div class="q-note" style="margin-bottom:10px">처방 기각 2건(정직 기록): ①<b>급등 진입만 유예 제거(타이트 손절)</b>'
       . ' — 해당 부분집합 승률 56.8→45.1%로 명확 악화. 급등주일수록 흔들림(셰이크아웃)도 커서 한 계단 유예가'
       . ' 제 몫을 한다. 손절 규칙(⑤)은 급등주에도 그대로. ②<b>장대음봉 즉시손절</b>(고가대비 저가 −12%↓ ∧'
       . ' 평균종가대비 −8%↓ ∧ 종가가 봉 하단 — 6개 변형 전부) — 승자에게도 장대음봉이 흔해서 실패'
       . ' (SK하이닉스 2026-03-04 고가대비 −23% 투매 캔들 후 +144% 사례가 반례). 패자 개선 +4~7%p ≪ 승자 훼손'
       . ' −15~34%p(181건)·승률 59→54.5%.</div>'
       . '<div class="q-note">급등⚠ 임계(20일 +80%·40일 +100%)는 이 측정에서 나온 값이다(KrxAmt::MOM_HOT20/40).'
       . ' 신호일 종가 기준으로 계산하며(백테스트는 돌파일 기준 — 통상 신호 후 며칠 내라 근사 수용),'
       . ' 실패 3사례 중 2건(SK하이닉스 6월·툴젠)이 이 기준에 걸린다. 단 <b>패턴1 최고 성공사례인 삼성전기(+66.6%)도'
       . ' 같은 무리다</b>(신호 전 40일 +139% 실측) — ⚠가 붙어도 큰 승자가 나온다는 것이 정확히 「중앙 0·평균 +7%'
       . ' 복권 분포」의 뜻이다. ⚠는 금지가 아니라 <b>기대값 없이 복권을 사는 자리라는 고지</b>다.</div></div>';

    echo '<div class="card"><h2>한계 (읽고 쓸 것)</h2><div style="font-size:13px;line-height:1.8">'
       . '· 120일 창은 이력 요건 탓에 <b>표본이 18개월</b>이다(2025-02~). 깊은 하락장은 여전히 없다.<br>'
       . '· 슬리피지·수수료 미반영. 초과수익 중앙 +1.7%는 <b>엣지이지 보증이 아니다</b> — 45%는 여전히 시장보다 못 간다.<br>'
       . '· 🟢 표본 569건은 앞선 표들보다 작다 — 원장이 쌓이면 재측정해 임계를 다시 확인한다.<br>'
       . '· 원장이 쌓이면 이 표는 낡는다. 재측정은 SSH 검증 스크립트로(측정일 갱신).<br>'
       . '· 배지 임계(≤5배·≥20배·0~10%·≥20%)는 이 측정에서 나온 값이다 — 임계를 바꾸려면 먼저 재측정한다.</div></div>';

    pf_foot();
}

// ══════════════════════════════════════════════════════════════════════
//  퀀트 > 패턴분석 — 5개 패턴을 실제 차트로 (정의 · 특징 · 성공/실패 사례)
// ══════════════════════════════════════════════════════════════════════
/**
 * 사례는 <b>전부 실측에서 발굴한 실화</b>다 — krx_amt 원장 6,058건 백테스트에서 각 패턴의
 * 상·하위 대표를 뽑았고(2026-08-01), 시세 정합(네이버 수정주가 = KRX 원본가)을 종목별로 확인했다.
 * 차트 H/L·계단 값은 그때 확인한 원장 값을 그대로 박는다(측정치 고정 — quantstat 과 같은 원칙).
 * ⚠성공 사례 = 그 패턴의 «최상위» 결과다. 전형은 중앙값(+2%대)이다 — 페이지 끝 「사례 읽는 법」.
 */
function pf_pt_case(array $c): void
{
    $cls = ['ok' => 'pt-ok', 'bad' => 'pt-bad', 'bait' => 'pt-bait', 'warn' => 'pt-warn'][$c['kind']];
    $lbl = ['ok' => '성공 사례', 'bad' => '실패 사례', 'bait' => '미끼 사례', 'warn' => '경고 사례'][$c['kind']];
    echo '<div class="pt-case"><div class="pt-ct"><span class="pt-badge ' . $cls . '">' . $lbl . '</span> '
       . '<b>' . pf_h($c['name']) . '</b> <span class="code">' . pf_h($c['code']) . '</span>'
       . ' · 신호 ' . pf_h($c['sig']) . '</div>'
       . '<div id="ptc_' . pf_h($c['id']) . '" class="pt-chart"><span class="pt-loading">차트 불러오는 중…</span></div>'
       . '<div class="pt-note">' . $c['note'] . '</div></div>';
}

function pf_page_pattern(PDO $pdo, Pf $pf): void
{
    pf_head('퀀트 · 패턴분석', 'quant', 'wide');
    pf_subtabs('pattern', 'quant');
    pf_flash();
    pf_quant_css();
    echo '<style>
.pt-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-top:10px}
@media(max-width:1500px){.pt-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:980px){.pt-grid{grid-template-columns:1fr}}
.pt-case{border:1px solid #dfe6ec;border-radius:10px;padding:10px 12px;background:#fff;min-width:0}
.pt-ct{font-size:14px;margin-bottom:6px}.pt-ct .code{color:#9ab;font-size:12px;margin-left:2px}
.pt-chart{height:340px;position:relative}
.pt-loading{position:absolute;top:45%;left:0;right:0;text-align:center;color:#9ab;font-size:13px}
.pt-note{font-size:13px;line-height:1.7;color:#334;margin-top:8px;border-top:1px dashed #e3e9ef;padding-top:8px}
.pt-badge{display:inline-block;padding:2px 9px;border-radius:10px;font-size:12px;font-weight:700}
.pt-ok{background:#e6f4ea;color:#1e7e34}.pt-bad{background:#fdecea;color:#c62828}
.pt-bait{background:#fdf3e0;color:#b26a00}.pt-warn{background:#fff3cd;color:#8a6d1a}
.pt-def{background:#f4f7fa;border-radius:8px;padding:10px 14px;font-size:13px;line-height:1.8;margin:8px 0}
.pt-act{font-size:13px;font-weight:700;margin-left:8px}
.pt-flow{display:flex;flex-direction:column;gap:6px;font-size:13px;margin:10px 0}
.pt-fr{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.pt-fb{border:1px solid #dfe6ec;border-radius:8px;padding:5px 10px;background:#fff;white-space:nowrap}
.pt-fa{color:#9ab}.pt-fk{font-weight:700}
.pt-legend{font-size:12px;color:#667;margin:4px 0 0}
.pt-legend .sw{display:inline-block;width:16px;height:0;border-top:2px solid;vertical-align:middle;margin:0 4px 2px 8px}
</style>';

    echo '<div class="pf-head"><div><h1>패턴분석</h1>'
       . '<div class="sub">120일 최고 거래대금에서 갈라지는 <b>5개 패턴</b> — 정의 · 특징 · 실제 사례(성공과 실패 모두).'
       . ' 모든 수치는 원장 6,058건 백테스트 실측이며 근거 전문은 <a href="/stock/index.php?mode=quantstat">검증 탭</a>에 있다.</div></div></div>';

    /* ── 패턴 지도 — 신호 하나가 어느 패턴으로 갈라지는가 (판정 순서 그대로) ── */
    echo '<div class="card"><h2>패턴 지도 — 판정 순서</h2><div class="pt-flow">'
       . '<div class="pt-fr"><span class="pt-fb pt-fk">120일 최고 거래대금 발생</span><span class="pt-fa">→ 신호일 고가 H = 저항 · 저가 L = 지지 (박스)</span></div>'
       . '<div class="pt-fr"><span class="pt-fa">①</span><span class="pt-fb">등락 +20% 이상 폭등?</span><span class="pt-fa">→ 예:</span><span class="pt-fb pt-fk">패턴4 마지막 불꽃 🚫</span></div>'
       . '<div class="pt-fr"><span class="pt-fa">②</span><span class="pt-fb">거래대금 20일 평균의 20배 이상?</span><span class="pt-fa">→ 예:</span><span class="pt-fb pt-fk">패턴5 소문난 잔치 🚫</span></div>'
       . '<div class="pt-fr"><span class="pt-fa">③</span><span class="pt-fb">20평비 ≤5배 그리고 등락 0~+10%?</span><span class="pt-fa">→ 예: 🟢매집형 — 아래로 계속 · 아니오:</span><span class="pt-fb">중립 → 관망</span></div>'
       . '<div class="pt-fr"><span class="pt-fa">④</span><span class="pt-fb">매집형인데 아래 계단이 0개?</span><span class="pt-fa">→ 예:</span><span class="pt-fb pt-fk">패턴3 첫 폭발 → 관망</span></div>'
       . '<div class="pt-fr"><span class="pt-fa">⑤</span><span class="pt-fb">이후 종가가 H 돌파?</span><span class="pt-fa">→</span><span class="pt-fb pt-fk">패턴1 매집형 돌파 🟢 매수</span></div>'
       . '<div class="pt-fr"><span class="pt-fa">⑥</span><span class="pt-fb">붕괴 후 계단에서 지지 확인?</span><span class="pt-fa">→ 아래층 3개 이상:</span><span class="pt-fb pt-fk">패턴2 매집+바닥 🟢 매수</span><span class="pt-fa">· 1~2개: ⚠관망</span></div>'
       . '</div><div class="q-note">차트 읽는 법: <span style="display:inline-block;width:15px;height:11px;background:rgba(240,165,0,0.18);'
       . 'border-top:2px solid #d33;border-bottom:2px solid #1565c0;vertical-align:middle;margin:0 4px 2px 8px"></span>'
       . '노란 박스 = <b>이번 신호의 박스</b>(위 빨강 = 저항 H · 아래 파랑 = 지지 L)'
       . ' <span style="display:inline-block;width:15px;height:11px;background:rgba(110,135,160,0.15);'
       . 'border-top:1px solid rgba(211,51,51,.5);border-bottom:1px solid rgba(21,101,192,.5);vertical-align:middle;margin:0 4px 2px 8px"></span>'
       . '회색 박스 = <b>그 전의 박스들</b>(계단이 쌓여 온 모양 — 다음 박스가 생길 때까지)'
       . ' <span class="sw" style="border-color:#9aa7b4;border-top-style:dashed"></span>회색 파선 = 신호 이후에도 살아있는 계단 레벨.'
       . ' ▲매수 ▼청산 마커는 실측 시점(+20거래일 잣대) 그대로.'
       . ' 초기 화면은 신호 전후만 확대해 보여 준다 — <b>왼쪽으로 드래그하면 이전 계단</b>이, 휠로 확대·축소가 된다.</div></div>';

    /* ── 사례 데이터 — 2026-08-01 백테스트에서 발굴·시세 정합 확인 완료 ── */
    $CASES = [
        // 패턴1 매집형 돌파
        ['id' => 'p1s', 'kind' => 'ok', 'name' => '삼성전기', 'code' => '009150', 'sig' => '2026-05-12',
         'H' => 995000, 'L' => 899000, 'lv' => [796000, 779000, 753000, 686000],
         'buy' => '2026-05-13', 'exit' => '2026-06-12', 'from' => '2026-02-01', 'to' => '2026-07-10', 'pat' => 1,
         'note' => '5/12 매집형 신호(등락 +6.4% · 20평비 5배 이하 · 아래층 박스 7개). 다음날 종가 1,029,000원으로'
                 . ' 저항 995,000원을 돌파 → <b>매수</b>. +20거래일 뒤 1,714,000원 — <b>+66.6%</b>(시장 대비 +80.9%p).'
                 . ' 계단을 층층이 쌓아 올린 뒤의 조용한 최고 거래대금이 교과서적으로 이어진 사례.'],
        ['id' => 'p1f', 'kind' => 'bad', 'name' => 'SK하이닉스', 'code' => '000660', 'sig' => '2026-06-19',
         'H' => 2891000, 'L' => 2688000, 'lv' => [2379000, 2290500, 1967000, 1804000],
         'buy' => '2026-06-22', 'exit' => '2026-07-21', 'from' => '2026-03-01', 'to' => '', 'pat' => 1,
         'note' => '6/19 매집형 신호(등락 +2.9% · 아래층 13개 — 조건은 완벽했다). 6/22 돌파 매수 2,919,000원'
                 . ' → +20거래일 1,836,000원 — <b>−37.1%</b>. 대형주도, 계단이 많아도 <b>규칙대로 사서 이렇게 진다</b>.'
                 . ' 승률 57.9%의 나머지 42%가 이런 얼굴이다. 실전 청산규칙(한 계단 유예)은 계단 이탈에서 더 일찍 끊는다.'],
        // 패턴2 매집+바닥 (계단지지)
        ['id' => 'p2s', 'kind' => 'ok', 'name' => '주성엔지니어링', 'code' => '036930', 'sig' => '2026-03-24',
         'H' => 85700, 'L' => 74100, 'lv' => [67200, 64000],
         'buy' => '2026-03-27', 'exit' => '2026-04-24', 'from' => '2025-12-15', 'to' => '2026-05-29', 'pat' => 2,
         'note' => '3/24 매집형 신호(아래층 7개) 후 지지 74,100원이 무너짐 → 3/27 계단 67,200원에 저가가 닿고'
                 . ' 종가 70,000원으로 버팀 = <b>계단지지 확인 매수</b>. +20거래일 123,900원 — <b>+77.0%</b>.'
                 . ' 붕괴가 곧 실패가 아니다 — 계단이 받아주면(실측 74.9%) 거기가 바닥이 된다.'],
        ['id' => 'p2f', 'kind' => 'bad', 'name' => 'LG전자', 'code' => '066570', 'sig' => '2026-06-02',
         'H' => 438000, 'L' => 330000, 'lv' => [293000, 248500, 194900, 160400],
         'buy' => '2026-06-05', 'exit' => '2026-07-03', 'from' => '2026-03-02', 'to' => '', 'pat' => 2,
         'note' => '6/2 신호 후 붕괴 → 6/5 계단 293,000원 지지 확인 매수 303,000원. 그런데 계단이 <b>연쇄로</b>'
                 . ' 무너지며 +20거래일 191,000원 — <b>−37.0%</b>. 아래층 6개도 폭락 국면은 못 막는다 —'
                 . ' 계단은 확률(승률 58.5%)이지 보증이 아니다. 이런 낙폭이 중앙 +2.40%의 실체다.'],
        ['id' => 'p2w', 'kind' => 'warn', 'name' => '와이지엔터테인먼트', 'code' => '122870', 'sig' => '2026-02-27',
         'H' => 77600, 'L' => 71400, 'lv' => [62000, 56300],
         'buy' => '2026-03-04', 'exit' => '2026-04-01', 'from' => '2025-11-20', 'to' => '2026-05-08', 'pat' => 2,
         'note' => '⚠<b>아래층 1개뿐인 계단지지</b> — 3/4 계단 62,000원 지지가 확인돼 62,600원에 샀다면'
                 . ' +20거래일 53,300원 — <b>−14.9%</b>. 아래층 1~2개의 계단지지는 실측 중앙 −2.47%·승률 45%로'
                 . ' <b>지는 자리</b>다. 그래서 매수 규칙에 「아래층 3개 이상」 조건이 붙었다(목록의 ⚠ 표시).'],
        // 패턴3 첫 폭발
        ['id' => 'p3c', 'kind' => 'bad', 'name' => '한미반도체', 'code' => '042700', 'sig' => '2025-02-19',
         'H' => 111000, 'L' => 100100, 'lv' => [],
         'low' => '2025-04-09', 'from' => '2024-12-01', 'to' => '2025-06-15', 'pat' => 3,
         'note' => '2/19 매집형 최고 거래대금 — 그러나 <b>첫 박스(아래 계단 0개)</b>. 지지 100,100원이 깨지자 받아줄 곳이'
                 . ' 없이 4/9 59,500원까지 — <b>지지 대비 −40.6%</b>. 계단 없는 붕괴가 위험한 이유의 전형.'],
        ['id' => 'p3a', 'kind' => 'bad', 'name' => '엠로', 'code' => '058970', 'sig' => '2025-02-04',
         'H' => 80100, 'L' => 74600, 'lv' => [],
         'buy' => '2025-02-06', 'exit' => '2025-03-07', 'from' => '2024-11-15', 'to' => '2025-04-30', 'pat' => 3,
         'note' => '첫 박스인데 돌파해서 2/6 83,000원에 샀다면 → +20거래일 55,000원 — <b>−33.7%</b>.'
                 . ' 첫 박스는 돌파해도 이력 쌓인 구간 실측이 −1.94%(표본 19건이라 규칙화는 보류)로 근거가 약하다.'
                 . ' <b>관망이 기본</b> — 이 박스는 훗날 다음 박스의 계단이 된다.'],
        // 패턴4 마지막 불꽃
        ['id' => 'p4f', 'kind' => 'bad', 'name' => '시공테크', 'code' => '020710', 'sig' => '2025-04-11',
         'H' => 7290, 'L' => 6300, 'lv' => [6140, 4580],
         'buy' => '2025-04-14', 'exit' => '2025-05-16', 'from' => '2025-01-10', 'to' => '2025-07-01', 'pat' => 4,
         'note' => '4/11 <b>+25.7% 폭등하며</b> 최고 거래대금(추격주의). 4/14 돌파 매수 8,060원 → 5/16 3,900원 —'
                 . ' <b>−51.6%</b>. 폭등일의 최고 거래대금은 돌파율이 67.7%로 가장 높아 「잘 가는 것처럼 보이는」 것이'
                 . ' 함정이다 — 사면 3번 중 2번 진다(중앙 −7.60%).'],
        ['id' => 'p4w', 'kind' => 'bait', 'name' => '광전자', 'code' => '017900', 'sig' => '2026-03-25',
         'H' => 3395, 'L' => 2560, 'lv' => [2160, 2055, 2040],
         'buy' => '2026-04-02', 'exit' => '2026-05-06', 'from' => '2025-12-15', 'to' => '2026-06-15', 'pat' => 4,
         'note' => '같은 추격주의 돌파인데 <b>+205.8%</b>. <b>이런 사례가 미끼다</b> — 눈에 남는 건 이 한 방이지만'
                 . ' 실측 분포는 중앙 −7.60%·승률 35.7%. 한 번의 +205%를 보고 들어가는 것은 기대값이 아니라'
                 . ' 복권을 사는 것이고, 복권값은 위 시공테크가 치른다.'],
        // 패턴5 소문난 잔치
        ['id' => 'p5f', 'kind' => 'bad', 'name' => '보해양조', 'code' => '000890', 'sig' => '2026-06-22',
         'H' => 2765, 'L' => 1981, 'lv' => [],
         'buy' => '2026-06-23', 'exit' => '2026-07-22', 'from' => '2026-03-20', 'to' => '', 'pat' => 5,
         'note' => '6/22 거래대금이 20일 평균의 <b>20배 이상 폭발</b>(폭발형). 6/23 돌파 매수 3,040원 →'
                 . ' 7/22 1,442원 — <b>−52.6%</b>. 온 시장이 쳐다보는 날의 거래대금 폭발은 관심의 정점 ='
                 . ' 물량 소화의 자리다.'],
        ['id' => 'p5w', 'kind' => 'bait', 'name' => '동양고속', 'code' => '084670', 'sig' => '2025-11-24',
         'H' => 20000, 'L' => 12390, 'lv' => [12110, 10710, 10000, 8440],
         'buy' => '2025-12-03', 'exit' => '2026-01-09', 'from' => '2025-08-20', 'to' => '2026-02-27', 'pat' => 5,
         'note' => '폭발형인데 <b>+177.3%</b> — 역시 미끼 사례. 폭발형 돌파의 실측 분포는 중앙 −5.72%·'
                 . '승률 37.4%·두 기간 모두 마이너스다. 승자 몇이 눈에 띄고, 다수의 패자가 조용히 사라진다.'],
        // ── 확장 사례 20건 (2026-08-01 · 시세 정합 확인 · RF머트리얼즈는 수정주가 불일치로 좋은사람들로 교체) ──
        ['id' => 'p1s2', 'kind' => 'ok', 'name' => 'DSC인베스트먼트', 'code' => '241520', 'sig' => '2026-01-29',
         'H' => 9060, 'L' => 7950, 'lv' => [7160, 6000, 4495],
         'buy' => '2026-02-04', 'exit' => '2026-03-10', 'from' => '2025-10-20', 'to' => '2026-04-17', 'pat' => 1,
         'note' => '1/29 매집형 신호(아래층 3개). 2/4 종가 9,280원으로 저항 9,060원 돌파 매수'
                 . ' → +20거래일 16,100원 — <b>+73.5%</b>.'],
        ['id' => 'p1s3', 'kind' => 'ok', 'name' => '한국석유', 'code' => '004090', 'sig' => '2026-01-29',
         'H' => 15100, 'L' => 14350, 'lv' => [14030],
         'buy' => '2026-01-30', 'exit' => '2026-03-05', 'from' => '2025-10-20', 'to' => '2026-04-10', 'pat' => 1,
         'note' => '1/30 돌파 매수 15,950원 → 3/5 28,150원 — <b>+76.5%</b>. 다만 아래층 1개의 얕은 이력'
                 . ' — 이런 구조가 항상 통하는 건 아니라는 게 패턴3의 교훈이다.'],
        ['id' => 'p1f2', 'kind' => 'bad', 'name' => '툴젠', 'code' => '199800', 'sig' => '2026-05-06',
         'H' => 108900, 'L' => 90800, 'lv' => [82900, 67200, 56000, 48550],
         'buy' => '2026-05-07', 'exit' => '2026-06-08', 'from' => '2026-01-25', 'to' => '2026-07-10', 'pat' => 1,
         'note' => '5/7 돌파 매수 118,500원 → 6/8 43,050원 — <b>−63.7%</b>, 이 규칙 표본의 최악 낙폭.'
                 . ' 아래층 4개도 재료 소멸은 못 막는다 — 그래서 청산 규칙(계단 이탈)이 세트다.'],
        ['id' => 'p1f3', 'kind' => 'bad', 'name' => '프럼파스트', 'code' => '035200', 'sig' => '2025-04-03',
         'H' => 6800, 'L' => 5600, 'lv' => [4985, 3730],
         'buy' => '2025-04-07', 'exit' => '2025-05-08', 'from' => '2024-12-20', 'to' => '2025-06-15', 'pat' => 1,
         'note' => '4/7 돌파 매수 6,990원 → 5/8 4,515원 — <b>−35.4%</b>(시장 대비 −45.1%p).'
                 . ' 돌파 직후 되꺾여 계단까지 함께 무너진 전형.'],
        ['id' => 'p2s2', 'kind' => 'ok', 'name' => '흥구석유', 'code' => '024060', 'sig' => '2026-01-30',
         'H' => 15950, 'L' => 14320, 'lv' => [13720, 12240],
         'buy' => '2026-02-02', 'exit' => '2026-03-06', 'from' => '2025-10-20', 'to' => '2026-04-10', 'pat' => 2,
         'note' => '1/30 신호 후 붕괴 → 2/2 계단 13,720원에서 지지 확인 매수 13,610원 → 3/6 27,600원'
                 . ' — <b>+102.8%</b>, 이 규칙 표본의 최고 성과.'],
        ['id' => 'p2s3', 'kind' => 'ok', 'name' => '좋은사람들', 'code' => '033340', 'sig' => '2025-06-25',
         'H' => 1710, 'L' => 1439, 'lv' => [1358, 1293, 1127],
         'buy' => '2025-07-22', 'exit' => '2025-08-20', 'from' => '2025-03-15', 'to' => '2025-10-02', 'pat' => 2,
         'note' => '석 달에 걸쳐 박스 6층을 쌓은 뒤 6/25 신호 → 붕괴 → 7/22 계단 지지 확인 매수 1,325원'
                 . ' → 8/20 2,510원 — <b>+89.4%</b>. 층층이 쌓인 계단의 교과서.'],
        ['id' => 'p2f2', 'kind' => 'bad', 'name' => '오리엔탈정공', 'code' => '014940', 'sig' => '2025-10-27',
         'H' => 12900, 'L' => 11380, 'lv' => [11070, 10100, 8740],
         'buy' => '2025-10-28', 'exit' => '2025-11-25', 'from' => '2025-07-20', 'to' => '2025-12-31', 'pat' => 2,
         'note' => '10/28 계단 11,070원 지지 확인 매수 11,260원 → 11/25 8,240원 — <b>−26.8%</b>.'
                 . ' 아래층 3개로 조건을 충족해도 지는 41.5%는 있다.'],
        ['id' => 'p2f3', 'kind' => 'bad', 'name' => '중앙에너비스', 'code' => '000440', 'sig' => '2026-03-12',
         'H' => 35650, 'L' => 30000, 'lv' => [28750, 24850],
         'buy' => '2026-03-18', 'exit' => '2026-04-15', 'from' => '2025-12-01', 'to' => '2026-05-22', 'pat' => 2,
         'note' => '3/18 계단 28,750원 지지 확인 매수 28,700원 → 4/15 21,500원 — <b>−25.1%</b>.'
                 . ' 아래층 7개였지만 그 계단 대부분이 직전 2~3주 급등 중에 생긴 것이었다.'],
        ['id' => 'p2w2', 'kind' => 'warn', 'name' => '갤럭시아머니트리', 'code' => '094480', 'sig' => '2025-06-18',
         'H' => 15880, 'L' => 14110, 'lv' => [13100, 11300],
         'buy' => '2025-06-23', 'exit' => '2025-07-21', 'from' => '2025-03-10', 'to' => '2025-08-29', 'pat' => 2,
         'note' => '⚠아래층 1개 — 6/23 계단 13,100원 지지가 확인돼 13,900원에 샀다면 → 7/21 10,940원'
                 . ' — <b>−21.3%</b>. 규칙(3개 이상)이 걸러 주는 자리.'],
        ['id' => 'p2w3', 'kind' => 'warn', 'name' => '제이앤티씨', 'code' => '204270', 'sig' => '2025-11-03',
         'H' => 29850, 'L' => 27450, 'lv' => [22900, 22000],
         'buy' => '2025-11-14', 'exit' => '2025-12-12', 'from' => '2025-07-25', 'to' => '2026-01-23', 'pat' => 2,
         'note' => '⚠아래층 2개 — 11/14 계단 22,900원 지지 22,600원 매수했다면 → 12/12 20,650원 — <b>−8.6%</b>.'
                 . ' 1~2개 구역의 전형(실측 중앙 −2.47%·승률 45%).'],
        ['id' => 'p3c2', 'kind' => 'bad', 'name' => '에이직랜드', 'code' => '445090', 'sig' => '2026-06-02',
         'H' => 32200, 'L' => 28400, 'lv' => [],
         'low' => '2026-07-30', 'from' => '2026-02-20', 'to' => '', 'pat' => 3,
         'note' => '6/2 첫 박스 → 지지 28,400원 붕괴 → 받아줄 계단 없이 7/30 14,720원까지 —'
                 . ' <b>지지 대비 −48.2%</b>, 이 표본 최악의 첫 폭발 붕괴.'],
        ['id' => 'p3a2', 'kind' => 'bad', 'name' => '인투셀', 'code' => '287840', 'sig' => '2025-11-28',
         'H' => 69400, 'L' => 60300, 'lv' => [],
         'buy' => '2025-12-02', 'exit' => '2026-01-02', 'from' => '2025-08-20', 'to' => '2026-02-13', 'pat' => 3,
         'note' => '첫 박스 돌파를 12/2 69,500원에 샀다면 → 1/2 53,500원 — <b>−23.0%</b>.'
                 . ' 첫 박스는 돌파해도 근거가 약하다는 또 하나의 실측.'],
        ['id' => 'p4f2', 'kind' => 'bad', 'name' => '성호전자', 'code' => '043260', 'sig' => '2026-06-02',
         'H' => 49600, 'L' => 39700, 'lv' => [38600, 30200], 'wf' => 1,
         'buy' => '2026-06-04', 'exit' => '2026-07-02', 'from' => '2025-11-20', 'to' => '', 'pat' => 4,
         'note' => '6/2 +20.4% 폭등하며 최고 거래대금 → 6/4 돌파 매수 51,400원 → 7/2 21,900원 — <b>−57.4%</b>.'
                 . ' ★차트 왼쪽을 보라 — <b>같은 종목이 반년 전(2025-12월) 같은 패턴으로 +123%</b> 갔었다.'
                 . ' 그 기억이 이번 추격을 부르고, 이번엔 반토막이 났다. 미끼가 작동하는 방식 그 자체.'],
        ['id' => 'p4f3', 'kind' => 'bad', 'name' => '아모센스', 'code' => '357580', 'sig' => '2026-03-11',
         'H' => 20050, 'L' => 15300, 'lv' => [8460, 7380, 6960, 5600],
         'buy' => '2026-03-12', 'exit' => '2026-04-09', 'from' => '2025-12-01', 'to' => '2026-05-15', 'pat' => 4,
         'note' => '3/11 +29.8% 폭등하며 최고 거래대금 → 3/12 돌파 매수 24,050원 → 4/9 12,580원 — <b>−47.7%</b>.'],
        ['id' => 'p4w2', 'kind' => 'bait', 'name' => '다원넥스뷰', 'code' => '323350', 'sig' => '2026-01-08',
         'H' => 6630, 'L' => 5040, 'lv' => [],
         'buy' => '2026-01-20', 'exit' => '2026-02-20', 'from' => '2025-10-01', 'to' => '2026-03-31', 'pat' => 4,
         'note' => '+30% 폭등하며 최고 거래대금 → 1/20 돌파 매수 7,200원 → 2/20 18,700원 — <b>+159.7%</b>.'
                 . ' 미끼 사례: 이 무리 전체의 중앙은 −7.60%다.'],
        ['id' => 'p4w3', 'kind' => 'bait', 'name' => '한스바이오메드', 'code' => '042520', 'sig' => '2025-09-15',
         'H' => 14900, 'L' => 11860, 'lv' => [11490, 10170, 9300, 8450],
         'buy' => '2025-09-18', 'exit' => '2025-10-23', 'from' => '2025-06-05', 'to' => '2025-11-28', 'pat' => 4,
         'note' => '9/18 돌파 매수 16,000원 → 10/23 33,800원 — <b>+111.2%</b>. 승자의 기억이 다음 패자를'
                 . ' 만든다 — 위 성호전자가 정확히 그 다음 장면이다.'],
        ['id' => 'p5f2', 'kind' => 'bad', 'name' => '부방', 'code' => '014470', 'sig' => '2025-04-03',
         'H' => 2410, 'L' => 1910, 'lv' => [],
         'buy' => '2025-04-07', 'exit' => '2025-05-08', 'from' => '2024-12-20', 'to' => '2025-06-15', 'pat' => 5,
         'note' => '거래대금 20일 평균의 28배 폭발 → 4/7 돌파 매수 2,615원 → 5/8 1,575원 —'
                 . ' <b>−39.8%</b>(시장 대비 −49.4%p).'],
        ['id' => 'p5f3', 'kind' => 'bad', 'name' => '모헨즈', 'code' => '006920', 'sig' => '2025-04-11',
         'H' => 4950, 'L' => 3980, 'lv' => [3595, 3320, 3310],
         'buy' => '2025-04-28', 'exit' => '2025-05-29', 'from' => '2025-01-05', 'to' => '2025-07-10', 'pat' => 5,
         'note' => '34배 폭발 → 4/28 돌파 매수 5,070원 → 5/29 2,965원 — <b>−41.5%</b>.'],
        ['id' => 'p5w2', 'kind' => 'bait', 'name' => '서산', 'code' => '079650', 'sig' => '2026-06-11',
         'H' => 1653, 'L' => 1273, 'lv' => [974],
         'buy' => '2026-06-12', 'exit' => '2026-07-14', 'from' => '2026-03-01', 'to' => '', 'pat' => 5,
         'note' => '92배 폭발 → 6/12 돌파 매수 1,690원 → 7/14 5,530원 — <b>+227.2%</b>.'
                 . ' 이 페이지에서 가장 큰 수익이자 가장 위험한 착시 — 같은 자리의 중앙은 −5.72%다.'],
        ['id' => 'p5w3', 'kind' => 'bait', 'name' => '나무에이엑스', 'code' => '242040', 'sig' => '2025-12-30',
         'H' => 1552, 'L' => 1308, 'lv' => [],
         'buy' => '2026-01-16', 'exit' => '2026-02-13', 'from' => '2025-09-20', 'to' => '2026-03-27', 'pat' => 5,
         'note' => '175배 폭발 → 1/16 돌파 매수 1,585원 → 2/13 3,610원 — <b>+127.8%</b>. 미끼 사례.'],
    ];
    /* 과거 박스 이력(차트 창 안·krx_surge×krx_amt 실측 2026-08-01) — [신호일, H, L].
     * 화면에서 회색 음영 박스로 그려져 「계단이 쌓여 온 모양」을 첨부 차트처럼 보여 준다.
     * 창 밖의 더 옛 박스(와이지엔터·보해양조 등)는 계단 파선으로만 남는다. */
    $BOXES = [
        'p1s' => [['2026-02-02', 300000, 280000], ['2026-02-19', 363000, 321000], ['2026-02-23', 436500, 392000],
                  ['2026-04-21', 779000, 686000], ['2026-04-23', 796000, 753000], ['2026-05-06', 971000, 898500]],
        'p1f' => [['2026-03-04', 954000, 846000], ['2026-05-06', 1614000, 1557000], ['2026-05-11', 1949000, 1826000],
                  ['2026-05-12', 1967000, 1804000], ['2026-05-29', 2379000, 2290500]],
        'p2s' => [['2026-01-08', 35900, 30700], ['2026-03-03', 64900, 54600], ['2026-03-04', 63900, 53700],
                  ['2026-03-05', 69900, 56800], ['2026-03-10', 74300, 64000], ['2026-03-20', 78500, 67200]],
        'p2f' => [['2026-05-12', 194900, 160400], ['2026-05-29', 293000, 248500]],
        'p4f' => [['2025-04-08', 6140, 4580]],
        'p4w' => [['2026-01-13', 1911, 1819], ['2026-02-25', 2160, 2040], ['2026-03-24', 2615, 2055]],
        'p5w' => [['2025-11-20', 12110, 10710], ['2025-11-21', 15740, 14180]],
        'p1s2' => [['2026-01-16', 8180, 7160]],
        'p1s3' => [['2026-01-06', 14800, 14030], ['2026-01-14', 15230, 14310]],
        'p1f3' => [['2025-03-07', 4985, 3730]],
        'p2s2' => [['2026-01-05', 13440, 11830], ['2026-01-06', 14100, 12240], ['2026-01-14', 15230, 13720]],
        'p2s3' => [['2025-04-03', 723, 612], ['2025-04-14', 869, 750], ['2025-05-30', 1012, 785],
                   ['2025-06-05', 1240, 941], ['2025-06-09', 1358, 1127], ['2025-06-12', 1498, 1293]],
        'p2f2' => [['2025-08-07', 8190, 6340], ['2025-09-08', 11070, 8740], ['2025-10-21', 11710, 10100]],
        'p2f3' => [['2026-02-20', 21150, 18200], ['2026-02-23', 23200, 18460], ['2026-03-04', 32800, 28050],
                   ['2026-03-05', 38000, 24850], ['2026-03-06', 37700, 28750]],
        'p2w2' => [['2025-06-10', 13100, 11300]],
        'p2w3' => [['2025-10-15', 28000, 22900]],
        'p4f2' => [['2025-12-08', 3860, 3120], ['2025-12-10', 6510, 4780], ['2026-03-06', 38600, 30200],
                   ['2026-03-10', 49700, 41000], ['2026-03-11', 59600, 45850]],
        'p4w3' => [['2025-06-11', 10600, 9120], ['2025-09-09', 10170, 8450], ['2025-09-10', 11490, 9300]],
        'p5f3' => [['2025-04-07', 3595, 3320], ['2025-04-10', 4240, 3310]],
        'p5w2' => [['2026-06-10', 1272, 974]],
    ];
    foreach ($CASES as &$c) $c['boxes'] = $BOXES[$c['id']] ?? [];
    unset($c);

    // 그리드 순서 = 성공 → 실패 → 경고 → 미끼 (usort 는 PHP 8 부터 안정 정렬이라 같은 무리 안 순서 유지)
    $kord = ['ok' => 0, 'bad' => 1, 'warn' => 2, 'bait' => 3];
    usort($CASES, fn($x, $y) => [$x['pat'], $kord[$x['kind']]] <=> [$y['pat'], $kord[$y['kind']]]);

    $byPat = [];
    foreach ($CASES as $c) $byPat[$c['pat']][] = $c;

    /* ── 패턴 카드 5장 — ①정의 ②특징 ③사례 ── */
    $PATS = [
        1 => ['t' => '패턴1 「조용한 매집 → 돌파」', 'act' => '🟢 매수', 'actc' => '#1e7e34',
              'def' => '<b>①정의</b> — 120일 최고 거래대금인데 <b>요란하지 않다</b>: 거래대금이 20일 평균의 5배 이하,'
                     . ' 등락 0~+10% (=🟢매집형 배지). 신호일에 사지 않고, 이후 <b>종가가 저항 H를 넘는 날</b> 산다.',
              'feat' => '<b>②특징</b> — 표본 304건 · 초과수익 중앙 <b>+2.26%</b> · 승률 <b>57.9%</b> · 기간 2분할 +1.69/+2.97 모두 ＋ ·'
                      . ' 178개 종목 분산. 핵심은 돌파를 <b>확인하고</b> 사는 것 — 신호일 추격은 다른 패턴(전체 중앙 −3.36%)이다.'
                      . ' 청산은 「한 계단 유예」(시간 규칙 없음 · 평균 +26.01%): 손절선이 깨져도 바로 아래 계단이 살아있으면 버티고,'
                      . ' 그 계단마저 종가로 깨질 때 나온다.'],
        2 => ['t' => '패턴2 「매집 + 바닥」 (계단지지)', 'act' => '🟢 매수 (아래층 3개 이상일 때만)', 'actc' => '#1e7e34',
              'def' => '<b>①정의</b> — 매집형 박스가 <b>붕괴</b>(종가 &lt; L)한 뒤, 아래 계단(옛 박스의 H·L 레벨)에 저가가 닿고'
                     . ' <b>종가가 그 계단 위에서 버틴 날</b> 산다. 단 <b>아래층 박스가 3개 이상</b> 쌓여 있을 때만.',
              'feat' => '<b>②특징</b> — 아래층 3개↑ 표본 94건 · 중앙 <b>+2.40%</b> · 승률 <b>58.5%</b> · 양기간 ＋ · 85개 종목 분산.'
                      . ' 붕괴해도 계단이 있으면 <b>74.9%는 계단에서 지지</b>되고 끝까지 관통은 2.5%뿐. 반면 아래층 1~2개는'
                      . ' 실측 <b>중앙 −2.47%·승률 45%</b>로 지는 자리(위치 연구 2026-08-01) — 목록에서 ⚠로 표시된다.'
                      . ' 청산은 「20거래일 잠금 → 손절선」: 이미 깊은 계단에서 산 자리라 한 계단 더 기다리면 너무 깊다.'],
        3 => ['t' => '패턴3 「첫 폭발」 (계단 없는 첫 박스)', 'act' => '관망', 'actc' => '#667',
              'def' => '<b>①정의</b> — 매집형 조건은 다 갖췄는데 <b>아래 계단이 하나도 없다</b>: 이 종목의 첫 거래대금 폭발.',
              'feat' => '<b>②특징</b> — 붕괴하면 <b>받아줄 곳이 없다</b>(계단 있는 붕괴와 정반대). 돌파해도 이력이 쌓인 구간'
                      . ' 실측 −1.94%(표본 19건 — 작아서 금지 규칙까지는 안 갔다). 기본 행동은 <b>관망</b> — 지금 박스가'
                      . ' 훗날 다음 박스의 계단이 되고, 계단이 쌓인 뒤의 신호(패턴1·2)가 우리가 사는 자리다.'],
        4 => ['t' => '패턴4 「마지막 불꽃」 (추격주의)', 'act' => '🚫 매수 금지', 'actc' => '#c62828',
              'def' => '<b>①정의</b> — <b>등락 +20% 이상 폭등하며</b> 최고 거래대금 (=추격주의 배지). 돌파 여부와 무관하게 안 산다.',
              'feat' => '<b>②특징</b> — 표본 821건 · 돌파 시 중앙 <b>−7.60%</b> · 승률 35.7% · 양기간 −. 역설이 핵심이다:'
                      . ' <b>돌파율은 67.7%로 전 패턴 중 최고</b> — 가장 잘 가는 것처럼 보이는 자리가 가장 지는 자리다.'
                      . ' 온 시장이 이미 알아버린 재료의 마지막 불꽃.'],
        5 => ['t' => '패턴5 「소문난 잔치」 (폭발형)', 'act' => '🚫 매수 금지', 'actc' => '#c62828',
              'def' => '<b>①정의</b> — 거래대금이 직전 20일 평균의 <b>20배 이상</b>으로 폭발하며 찍은 최고 거래대금 (=폭발형 배지).',
              'feat' => '<b>②특징</b> — 표본 431건 · 돌파 시 중앙 <b>−5.72%</b> · 승률 37.4% · 양기간 −. 매집형과 정반대 극 —'
                      . ' 같은 최고 거래대금이라도 <b>조용히 찍는가, 온 동네가 알게 찍는가</b>가 승부를 가른다. 소문난 잔치에 먹을 것 없다.'],
    ];
    foreach ($PATS as $no => $p) {
        echo '<div class="card"><h2>' . $p['t'] . '<span class="pt-act" style="color:' . $p['actc'] . '">' . $p['act'] . '</span></h2>'
           . '<div class="pt-def">' . $p['def'] . '</div>'
           . '<div style="font-size:13px;line-height:1.8">' . $p['feat'] . '</div>'
           . '<div style="font-size:13px;margin-top:10px"><b>③실제 사례</b></div><div class="pt-grid">';
        foreach ($byPat[$no] ?? [] as $c) pf_pt_case($c);
        echo '</div></div>';
    }

    /* ── 사례 읽는 법 — 체리픽 오해 방지 ── */
    echo '<div class="card"><h2>사례 읽는 법 (중요)</h2><div style="font-size:13px;line-height:1.8">'
       . '· 여기 실린 성공·실패 사례는 각 패턴의 <b>상·하위 대표</b>다 — 눈에 잘 보이라고 극단을 골랐다.'
       . ' <b>전형적인 결과는 중앙값(+2%대)</b>이지 +66%나 −37%가 아니다.<br>'
       . '· 패턴의 힘은 개별 사례가 아니라 <b>분포</b>에 있다: 매수 패턴(1·2)은 수백 건에서 중앙 ＋·승률 58%,'
       . ' 금지 패턴(4·5)은 수백 건에서 중앙 −6~−8%·승률 36%. 근거 전문은 <a href="/stock/index.php?mode=quantstat">검증 탭</a>.<br>'
       . '· 수익률은 전부 <b>+20거래일 · 같은 날 시장(전종목 일중앙) 대비 초과</b> 잣대의 실측이고, 실전 청산 규칙'
       . '(한 계단 유예 / 20일 잠금)은 이보다 길게 들고 간다 — 검증 탭 ⑤절.<br>'
       . '· 차트 가격은 네이버 수정주가, H·L·계단 값은 KRX 원장 — 이 11개 종목은 <b>두 값이 일치함을 종목별로 확인</b>했다'
       . '(액면분할 등이 끼면 어긋날 수 있어, 사례 추가 시 반드시 재확인).<br>'
       . '· 오늘 시장에서 이 패턴들이 어디 있는지는 <a href="/stock/index.php?mode=quant">최고 거래대금 목록</a>의'
       . ' 배지·박스 열이 실시간으로 보여 준다.</div></div>';

    /* ── 차트 렌더 — dailychart.js 공용모듈. 값(H/L/계단)은 서버가 박고, 그리기만 JS ── */
    echo '<script src="/style/dailychart.js?v=27"></script>';
    echo '<script>const PT_CASES=' . json_encode($CASES, JSON_UNESCAPED_UNICODE) . ';</script>';
    echo <<<'JS'
<script>
DailyChart.load().then(function () {
  function renderCase(c) {
    var host = document.getElementById('ptc_' + c.id);
    var dc = DailyChart.create('ptc_' + c.id, { theme: 'light' });
    if (!dc) { if (host) host.textContent = '차트 라이브러리를 불러오지 못했습니다.'; return Promise.resolve(); }
    return DailyChart.fetchDaily(c.code, 480).then(function (rows) {
      if (!rows.length) { host.textContent = '일봉 데이터를 가져오지 못했습니다.'; return; }
      var ld = host.querySelector('.pt-loading'); if (ld) ld.remove();
      dc.setData(rows);
      var last = rows[rows.length - 1].time;
      // 시간값은 전부 실제 봉에 스냅 — 비거래일을 선 데이터에 넣으면 시간축에 유령 칸이 생긴다
      function snap(d) { for (var i = 0; i < rows.length; i++) if (rows[i].time >= d) return rows[i].time; return last; }
      var end = c.to && c.to < last ? snap(c.to) : last;
      function seg(fromT, v, col, w, st) {
        dc.addLine({ color: col, width: w, style: st || 'solid' })
          .setData([{ time: fromT, value: v }, { time: end, value: v }]);
      }
      var sig = snap(c.sig);
      // 박스 오버레이 — 과거 박스는 다음 박스가 생길 때까지(계단 모양), 신호 박스는 화면 끝까지
      var boxes = (c.boxes || []).map(function (b, i) {
        var nx = c.boxes[i + 1] ? c.boxes[i + 1][0] : c.sig;
        return { from: snap(b[0]), to: snap(nx), top: b[1], bottom: b[2],
                 fill: 'rgba(110,135,160,0.09)', topColor: 'rgba(211,51,51,0.45)',
                 bottomColor: 'rgba(21,101,192,0.45)', topW: 1, bottomW: 1 };
      });
      boxes.push({ from: sig, to: end, top: c.H, bottom: c.L, fill: 'rgba(240,165,0,0.10)',
                   topColor: '#d33', bottomColor: '#1565c0', sideColor: '#c9b37e', label: '신호박스' });
      dc.setBoxes(boxes);
      (c.lv || []).forEach(function (v) {
        if (v >= c.L * 0.55) seg(sig, v, '#9aa7b4', 1, 'dashed');   // 계단 연장선 — 신호 이후에도 살아있는 옛 레벨
      });
      var mk = [];
      if (c.buy)  mk.push({ time: c.buy, text: '매수' });
      if (c.exit) mk.push({ time: c.exit, sell: true, text: '청산' });
      if (c.low)  mk.push({ time: c.low, sell: true, text: '저점' });
      dc.setMarkers(mk);
      /* 초기 화면 = 신호 40봉 전 ~ 청산 15봉 후 — 봉을 크게. 데이터·박스는 c.from 부터
         전부 있으니 이전 계단은 차트를 왼쪽으로 드래그하면 나온다. wf=1 이면 넓게 시작
         (성호전자처럼 노트가 차트 왼쪽 장면을 가리키는 사례). */
      var iSig = rows.findIndex(function (r) { return r.time >= c.sig; });
      if (iSig < 0) iSig = rows.length - 1;
      var endKey = c.exit || c.low || '';
      var iEnd = endKey ? rows.findIndex(function (r) { return r.time >= endKey; }) : -1;
      if (iEnd < 0) iEnd = rows.length - 1;
      iEnd = Math.min(rows.length - 1, iEnd + 15);
      var zFrom = c.wf ? c.from : rows[Math.max(0, iSig - 40)].time;
      dc.zoomRange(zFrom, rows[iEnd].time, { minBars: 40, pad: 2 });
    }).catch(function () { host.textContent = '차트를 불러오지 못했습니다.'; });
  }

  /* 지연 로드 — 차트 30여 개가 페이지를 열자마자 네이버 일봉을 한꺼번에 때리지 않게:
     화면에 가까워진(±500px) 차트만 큐에 넣고, 동시 실행은 2개로 제한한다. */
  var active = 0, waitq = [];
  function kick(c) {
    if (active >= 2) { waitq.push(c); return; }
    active++;
    renderCase(c).then(function () { active--; if (waitq.length) kick(waitq.shift()); });
  }
  var pending = {};
  PT_CASES.forEach(function (c) { pending[c.id] = c; });
  if (window.IntersectionObserver) {
    var io = new IntersectionObserver(function (es) {
      es.forEach(function (en) {
        if (!en.isIntersecting) return;
        var id = en.target.id.slice(4);   // 'ptc_' 접두 제거
        var c = pending[id];
        if (!c) return;
        delete pending[id];
        io.unobserve(en.target);
        kick(c);
      });
    }, { rootMargin: '500px 0px' });
    PT_CASES.forEach(function (c) {
      var h = document.getElementById('ptc_' + c.id);
      if (h) io.observe(h); else delete pending[c.id];
    });
  } else {
    PT_CASES.forEach(kick);
  }
}).catch(function () {});
</script>
JS;

    pf_foot();
}
?>
