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
require_once __DIR__ . '/lib/sue.php';
require_once __DIR__ . '/lib/entry.php';   // M4 — 편입 스냅샷 (여기선 읽어서 「편입 당시」를 그린다)
require_once __DIR__ . '/lib/topbar.php';  // 섹션 헤더 — pf_menus / pf_topbar / pf_topbar_css (섹션 밖 화면과 공유)
require_once __DIR__ . '/lib/slowlog.php'; // 느린 렌더 계측 — 임계 초과 요청만 웹 루트 밖 파일에 한 줄
require_once __DIR__ . '/lib/note.php';    // 종목 개요·태그 단일본 — 재무 상세 카드·스크리너 &tag=·목록 칩
require_login();
pf_slowlog_boot();

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
/* DDL(ensureTables)·시드가 매 요청 도는 구간 — 백업(mysqldump)이 메타데이터 잠금을 쥐면
 * 여기서 상한 없이 기다린다. 느린 요청의 마크가 여기서 이미 크면 범인은 잠금 대기다. */
pf_slowlog_mark('ddl');

$mode = $_GET['mode'] ?? 'dashboard';

$routes = [
    'short'     => 'pf_page_short',       // 단타 — 1분봉 원장(dt_min) 3분할 화면 (다크)
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
    'boxbrk'    => 'pf_page_boxbrk',      // 퀀트 > 패턴분석 (박스 상향돌파) — 한 패턴을 매일 쌓는다
    'signal'    => 'pf_page_signal',      // 퀀트 > 신호분석 — 화면 곳곳의 배지·신호 전체의 기준을 한 자리에
    'quantstat' => 'pf_page_quantstat',   // 퀀트 > 검증 — 신호가 수익으로 이어졌나 (백테스트 보고서)
    'portfolio' => 'pf_page_portfolio',   // 설정 > 포트폴리오
    'chart'     => 'pf_page_chart',       // 설정 > 차트 (차트 갤러리 embed)
    'setting'   => 'pf_page_setting',     // 설정 > 수수료
    'ruleset'   => 'pf_page_ruleset',     // 설정 > 룰셋
    'quote'     => 'pf_page_quote',       // 설정 > 시세 — 화면별 시세 지도 + 원천·크론 현황
];

/* ★옛 주소를 살려 둔다 — 「패턴분석 (불꽃형)」은 2026-08-09 에, 옛 「패턴분석」(5개 패턴
 *   수기 사례 화면 · mode=pattern)은 2026-08-10 에 「패턴분석 (박스 상향돌파)」로 합쳐졌다.
 *   북마크·옛 알림 링크가 말없이 대시보드로 떨어지면 「사라졌다」로 읽힌다.
 *   옛 5개 패턴(매집형 돌파·계단지지·첫 폭발·불꽃형 둘)의 «근거 표»는 검증 탭 ④~⑥에 그대로다. */
if ($mode === 'flame' || $mode === 'pattern') {
    header('Location: /stock/index.php?mode=boxbrk', true, 301); exit;
}

if (isset($routes[$mode]) && function_exists($routes[$mode])) {
    $routes[$mode]($pdo, $pf);
} else {
    pf_page_dashboard($pdo, $pf);
}

// ══════════════════════════════════════════════════════════════════════
//  전용 헤더 + 공통 레이아웃
// ══════════════════════════════════════════════════════════════════════
/* ★섹션 메뉴 정의(pf_menus)·헤더 마크업(pf_topbar)·헤더 CSS(pf_topbar_css) 는
 *   stock/lib/topbar.php 로 옮겼다(2026-08-04). 섹션 밖 세 화면
 *   (etf_stock · stock_analysis · market/report)이 같은 헤더를 달아야 해서다.
 *   → 메뉴를 늘리려면 그 파일의 pf_menus() 한 줄 + 여기 $routes 한 줄. */

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
            /* ★2026-08-09 「패턴분석 (불꽃형)」과 「오늘의 후보」를 <b>하나로 합쳤고</b>,
             *   2026-08-10 옛 「패턴분석」(5개 패턴 수기 사례 화면)도 여기로 흡수했다(사용자 지시).
             *   불꽃형은 전수를 «훑는» 자리라 다 보고 나면 할 일이 없었고, 옛 패턴분석은 수기
             *   사례 31건의 1회성 문서였다. 이제 하나가 매일 쌓인다 — 패턴지도·사례도 이 화면 안이다. */
            ['key' => 'boxbrk',  'href' => '/stock/index.php?mode=boxbrk',   'label' => '패턴분석 (박스 상향돌파)'],
            /* ★신호분석은 2026-08-12 「설정」 하위로 옮겼다(사용자 지시) — 배지 표시 설정(BadgeFeat)이
             *   생기면서 «보는 문서»에서 «고르는 자리»가 됐다. mode=signal 주소는 그대로다. */
            ['key' => 'stat',    'href' => '/stock/index.php?mode=quantstat', 'label' => '검증 (백테스트)'],
        ],
        'setting' => [
            ['key' => 'portfolio', 'href' => '/stock/index.php?mode=portfolio', 'label' => '포트폴리오 관리'],
            ['key' => 'chart',     'href' => '/stock/index.php?mode=chart',     'label' => '차트 설정'],
            ['key' => 'ruleset',   'href' => '/stock/index.php?mode=ruleset',   'label' => '룰셋 설정'],
            ['key' => 'fee',       'href' => '/stock/index.php?mode=setting',   'label' => '수수료 설정'],
            /* 시세 설정은 「고치는 자리」가 아니라 <b>「어디서 오는지 보는 자리」</b>다 —
             * 화면 다섯이 각자 다른 표를 읽고 그것을 채우는 크론이 일곱이라, 그 사슬을 한 곳에 폈다. */
            ['key' => 'quote',     'href' => '/stock/index.php?mode=quote',     'label' => '시세 설정'],
            /* 신호분석 = 배지·용어의 단일 원천 문서 + ★배지 표시 설정(어느 목록에 어떤 배지를 그릴까).
             * 옛 자리는 퀀트 하위탭이었다(2026-08-12 이동 — 링크 주소는 안 바뀌었다). */
            ['key' => 'signal',    'href' => '/stock/index.php?mode=signal',    'label' => '신호분석 설정'],
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

/* pf_topbar() 는 stock/lib/topbar.php 에 있다 (섹션 밖 화면과 공유). */

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
  /* 음수 허용은 칸이 따로 선언한다(.neg-ok) — 수량·체결가 칸에 −가 살아남으면 안 된다.
     서버쪽 pf_money() 는 원래 −를 받으므로 여기만 열어 주면 왕복이 맞는다. */
  var neg = el.classList.contains('neg-ok') && /^\s*-/.test(el.value);
  var v = el.value.replace(/[^\d.]/g, '');
  if (v === '') { el.value = neg ? '-' : ''; return; }
  var p = v.split('.');
  el.value = (neg ? '-' : '')
           + (p[0] ? Number(p[0]).toLocaleString() : '0')
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
    pf_topbar_css();   // 헤더 규칙은 stock/lib/topbar.php 단일 소스 — 여기에 다시 적지 않는다

    echo <<<'CSS'
<style>
*,*::before,*::after{box-sizing:border-box}
body{font-family:'Pretendard','Malgun Gothic',sans-serif;background:#f0f2f5;color:#22303f;margin:0}

.pf-sub{display:flex;gap:5px;margin-bottom:14px;border-bottom:1px solid #dfe7ee;padding-bottom:0}
.pf-sub a{padding:8px 16px;font-size:14px;font-weight:700;color:#7d8b99;text-decoration:none;
  border-radius:8px 8px 0 0;margin-bottom:-1px}
.pf-sub a:hover{background:#eef4fa;color:#12406b}
.pf-sub a.on{background:#fff;color:#12406b;border:1px solid #dfe7ee;border-bottom-color:#fff;box-shadow:inset 0 2px 0 #1d5c93}

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
/* 눌러서 정렬하는 머리글 (보유종목) — gift 의 gf-th 패턴 */
table.pf thead th a.th-sort{color:inherit;text-decoration:none}
table.pf thead th a.th-sort:hover{color:#1c66c8}
table.pf thead th .th-arr{color:#1c66c8;font-size:10px;margin-left:2px}
table.pf tfoot td{background:#f5f8fb;font-weight:800;border-top:2px solid #d9e3ec;border-bottom:none}
.tbl-scroll{overflow-x:auto;border:1px solid #e3eaf0;border-radius:10px;background:#fff}

.up{color:#d32f2f;font-weight:700}
.down{color:#1565c0;font-weight:700}
.flat{color:#8b98a5}
.chg{font-size:11px;font-weight:700;margin-left:3px}
td.hit{background:#ffe9e6;border-radius:4px}
td.hit span{color:#c62828;font-weight:800}

/* ══ 종목 표시 정본 — 사이트 공통 (2026-08-04 사용자 지시) ═══════════════
 * <b>종목명은 키워서 위, 코드는 아래 회색, 밑줄 없음.</b> 이름이 먼저 읽힌다.
 * 마크업은 늘 이 한 가지다:
 *     <td class="stk"><a …>종목명</a><span class="code">코드</span></td>
 *   (링크가 없으면 <a> 대신 <b>. 두 요소 사이에 <b>공백을 넣지 않는다</b> — 둘 다 block 이다)
 *
 * ★★<b>table.pf 전체에 건다</b>. 예전에는 `table.pf.pos` 에만 걸려 있어서 같은 「종목 칸」이
 *   표마다 다르게 보였다 — 편입 관심종목·시뮬레이터·재무 스크리너는 이름 옆에 코드가
 *   밑줄과 함께 붙어 있었다(사용자 지적). 선택자를 넓히는 것이 <b>표마다 고치는 것보다 값이 싸고</b>
 *   앞으로 만들 표도 자동으로 따라온다.
 * ★ 행 높이(padding)만 .pos 에 남긴다 — 그건 「종목 목록」 표의 성질이지 종목 칸의 성질이 아니다. */
table.pf.pos td{padding:11px 8px}
table.pf td.stk{line-height:1.25}
table.pf td.stk a,table.pf td.stk>b{display:block;font-size:14.5px;font-weight:700;color:#22303f;
  text-decoration:none;letter-spacing:-.015em}
table.pf td.stk a:hover{color:#1d5c93}
/* 코드는 <b>보통 굵기</b>다(2026-08-04 사용자) — 이름이 굵으니 코드까지 굵으면 둘이 다툰다 */
table.pf td.stk .code{display:block;margin-top:2px;font-size:11.5px;font-weight:400;color:#9aa7b4}
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
/* 탐색 화면의 「포트폴리오」 칸 배지 — 편입(알약)이 안 서는 두 경우. 정의는 pf_held_badge() 한 곳이고
   여기(색)와 거기(말)가 갈리면 안 되므로 클래스로 잇는다. 안내 문구의 예시 배지도 같은 클래스다. */
.st-held{background:#fdecea;color:#c62828}
.st-slot{background:#eef3f8;color:#5f7183}
a.badge{text-decoration:none}

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
/* 카드 본문 한 줄 — 종목명(큼) · 차수(작게) · 주수(색). 좁으면 주수가 다음 줄로 넘어간다 */
.sig-name{font-size:18px;font-weight:800;letter-spacing:-.02em;line-height:1.3}
.sig-name .step{font-size:12px;font-weight:700;color:#7d8b99;margin-left:5px}
.sig-name .qty{font-size:16px;font-weight:800;margin-left:7px;font-variant-numeric:tabular-nums}
/* ⊖ .sig-meta(현재가 ≤ 이론가 / ≥ 자동매도가 줄)는 카드를 주수만 남기면서 2026-08-03 삭제.
   ⊖ 예수금 게이트 배지(.sig-fund)는 출력처가 없어 2026-08-02 삭제 — 스트립 머리의
   「예수금 부족 N건」(.sig-cash b.short)은 남아 있다. 그건 배지가 아니라 집계다. */
.sig-none{font-size:13px;color:#7d8b99}
/* 종류별 색 — 배지·카드 왼쪽 띠가 같은 값을 쓴다 */
.k-sell.sig-pill,.k-sell.sig-dot{background:#e6f5ee;color:#0f8a5f}
.k-buy.sig-pill,.k-buy.sig-dot{background:#fdeaea;color:#c62828}
.k-fill.sig-pill,.k-fill.sig-dot{background:#fdf1e0;color:#b96600}
.k-wait.sig-pill{background:#eef2f6;color:#8b98a5}
/* ── 편입 관심종목 행동 칩 (2026-08-04) — 표 안의 버튼은 <b>배지 크기</b>여야 한다.
   btn-sm 두 개(선택·삭제)를 나란히 두니 행마다 상자 두 개가 서서 목록이 「버튼 밭」이 됐다.
   ⇒ 고르는 것 하나만 알약으로 세우고, 빼는 것은 <b>× 하나</b>로 줄인다.
   ★ 둘을 <b>다른 열</b>로 갈랐다(2026-08-04) — 한 칸에 나란히 두면 알약이 없는 행에서 × 가
     왼쪽으로 밀려 열이 들쭉날쭉해진다. 열로 나누면 표가 폭을 맞춰 주므로 자리표시가 필요 없다. */
.wl-act{white-space:nowrap}
/* ★알약은 <b>한 벌</b>이다 — 「선택」(.wl-pill)과 「편입」(.pf-adopt)이 같은 정의를 쓴다.
   따로 그리면 같은 성격의 버튼이 화면마다 다른 모양이 된다(2026-08-04 사용자). 크기만 아래에서 달리한다. */
.wl-pill,.pf-adopt{display:inline-block;padding:5px 15px;border:0;border-radius:999px;font-family:inherit;
  font-size:12px;font-weight:800;letter-spacing:.02em;color:#fff;cursor:pointer;
  background:linear-gradient(135deg,#4a8ecb 0%,#1d5c93 100%);
  box-shadow:0 1px 2px rgba(29,92,147,.30),inset 0 1px 0 rgba(255,255,255,.25);
  transition:filter .12s ease,box-shadow .12s ease,transform .08s ease}
.wl-pill:hover,.pf-adopt:hover{filter:brightness(1.09);box-shadow:0 3px 9px rgba(29,92,147,.34),inset 0 1px 0 rgba(255,255,255,.3)}
.wl-pill:active,.pf-adopt:active{transform:translateY(1px);box-shadow:0 1px 2px rgba(29,92,147,.3)}
/* 지금 폼에 올라와 있는 종목 — 초록 계열로 「이미 된 일」임을 색으로 말한다(누를 것이 없다) */
.wl-pill.on{background:linear-gradient(135deg,#3fb98a 0%,#1e8a5f 100%);cursor:default;
  box-shadow:0 1px 2px rgba(30,138,95,.30),inset 0 1px 0 rgba(255,255,255,.28)}
.wl-pill.on:hover{filter:none;box-shadow:0 1px 2px rgba(30,138,95,.30),inset 0 1px 0 rgba(255,255,255,.28)}
/* 재진입 — 같은 알약, 빨강(2026-08-04 사용자). 이 카드에서 이웃은 「선택」(파랑)이라 빨강이 갈린다.
   ★현황 스트립의 k-re 는 <b>파랑 그대로</b> 둔다 — 거기서는 이웃이 「매수」(빨강)라 같은 색이면 안 된다.
     색은 개념의 이름표가 아니라 <b>그 자리에서 무엇과 구별되는가</b>로 정한다. */
a.wl-pill{text-decoration:none;line-height:1.6}
.wl-pill.re{background:linear-gradient(135deg,#e0605a 0%,#c62828 100%);
  box-shadow:0 1px 2px rgba(198,40,40,.30),inset 0 1px 0 rgba(255,255,255,.25)}
.wl-pill.re:hover{box-shadow:0 3px 9px rgba(198,40,40,.34),inset 0 1px 0 rgba(255,255,255,.3)}
/* 아직 때가 아닌 행(대기·아직 비쌈) — 모양은 같고 채도만 낮춘다. 오늘 할 것이 먼저 눈에 와야 한다 */
.wl-pill.re.dim{background:linear-gradient(135deg,#e8b4b1 0%,#cf8d8a 100%);
  box-shadow:0 1px 2px rgba(198,40,40,.16),inset 0 1px 0 rgba(255,255,255,.3)}
.wl-pill.re.dim:hover{filter:brightness(1.05);box-shadow:0 2px 7px rgba(198,40,40,.22),inset 0 1px 0 rgba(255,255,255,.34)}
/* 빼기 — 평소엔 흐릿하고 올리면 붉어진다(파괴적 동작이라 눈에 먼저 띌 필요는 없다) */
.wl-x{width:21px;height:21px;padding:0;line-height:1;border-radius:999px;border:1px solid #e3eaf0;
  background:#fff;color:#b7c2cd;font-size:13px;font-weight:700;cursor:pointer;vertical-align:middle;
  transition:all .12s ease}
.wl-x:hover{border-color:#f0b8b3;background:#fff5f3;color:#c62828}
tr.wl-on td{background:#eef7ff}
/* 고를 수 없는 행 — 글자로 「등록됨·청산됨」이라 적지 않고 <b>가라앉혀</b> 구별한다 */
tr.wl-off td{color:#98a5b2}
tr.wl-off .stk a{color:#8b98a5}
/* 가라앉은 행 중 「청산」만 ↓ 하나 — 보유·청산이 섞여 가라앉으면 화면만으로 못 가른다(2026-08-20 사용자).
   글자 없이 화살표만, 색은 재진입(k-re)과 같은 파랑. 누르면 아래 「재진입 후보」 카드로 간다 */
a.wl-re{display:inline-block;min-width:21px;height:21px;line-height:19px;border:1px solid #c9d6e4;border-radius:50%;
  background:#fff;color:#1f5fa8;font-size:12px;font-weight:700;text-decoration:none;text-align:center}
a.wl-re:hover{border-color:#1f5fa8;background:#eaf1fb}

/* 재진입 — 매수(빨강)와 <b>다른 색</b>이어야 한다. 아직 사이클이 시작되지 않은 「검토」라
   계획 매수 신호와 같은 무게로 읽히면 안 된다. */
.k-re.sig-pill,.k-re.sig-dot{background:#eaf1fb;color:#1f5fa8}
.sig-card.k-re{border-left-color:#1f5fa8}
b.k-re{color:#1f5fa8}
.sig-card.k-sell{border-left-color:#0f8a5f}
.sig-card.k-buy{border-left-color:#c62828}
.sig-card.k-fill{border-left-color:#e07c00}
.sig-name b.k-sell,.sig-name b.k-buy,.sig-name b.k-fill,.sig-name b.k-re{background:none;padding:0}
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
/* ★ 가격 움직임(%) 칩만 <b>등락색을 단색으로</b> 쓴다 — 빨강=올랐다 · 파랑=내렸다 · 글씨는 흰색
 * (2026-08-02 사용자 지시). 나머지 상태 배지는 위의 「의미색」 연한 팔레트다.
 * 같은 줄에 두 팔레트가 있는 이유: %는 방향이 곧 사실이라 등락색이 즉시 읽히고,
 * 나머지는 방향보다 <b>뜻</b>이 먼저이기 때문이다. 색값은 SUE 배지(.sue-b)와 <b>같은 빨강·파랑</b>을 쓴다 —
 * 사이트 전체에서 「오른 것」과 「내린 것」의 색이 하나여야 눈이 헷갈리지 않는다. */
.mkt.t-up{border-color:#c62828;background:#c62828;color:#fff}
.mkt.t-down{border-color:#1565c0;background:#1565c0;color:#fff}
/* 「편입」 — 탐색 화면(퀀트·어닝·관심종목)에서 종목 추가 폼으로 가는 다리 */
/* 편입 — 알약 본체는 위 .wl-pill 와 공유하고, 여기서는 <b>크기·자리</b>만 조정한다
   (★ 옆에 붙는 자리라 목록 알약보다 한 치수 작다) */
.pf-adopt{margin-left:5px;padding:3px 11px;font-size:11px;line-height:1.6;vertical-align:middle}
/* 관심종목처럼 <b>혼자</b> 칸을 쓰면 왼쪽 여백을 뺀다 — ★ 옆에 붙을 때만 필요한 간격이다 */
.pf-adopt:first-child{margin-left:0}
.mkt-row{margin-top:5px;line-height:1.6}
/* 계획 신호를 시장 상태로 보정한 한 줄 — 카드에서 가장 먼저 읽혀야 한다 */
.sig-conf{margin-top:5px;font-size:11.5px;font-weight:800;cursor:help}
.sig-conf.lv-strong{color:#0f7a55}
.sig-conf.lv-caution{color:#a95f16}

/* ⊖ 체결 게이트(.sig-fill)는 2026-08-02 삭제 — 배지 층 ③ 실행이 통째로 없어졌다 */
/* 유동성 등급 — 낮을수록 눈에 띄어야 한다(위험이 그쪽에 있다) */
.liq{display:inline-block;font-size:10.5px;font-weight:800;border-radius:5px;padding:1px 6px;border:1px solid}
.liq.g-deep{border-color:#c9d4de;background:#f7fafc;color:#7d8b99}
.liq.g-ok{border-color:#c9d4de;background:#f7fafc;color:#5f7183}
.liq.g-thin{border-color:#e8b892;background:#fff7ef;color:#a95f16}
.liq.g-very_thin{border-color:#f0b8b2;background:#fff4f2;color:#c62828}
.liq-sub{font-size:10.5px;color:#9aa7b4;margin-top:2px;font-variant-numeric:tabular-nums}

/* ★ 퀀트 배지 어휘 — <b>전역</b>이다(2026-08-02). 예전엔 pf_quant_css() 안에만 있어서,
 * 그걸 안 부르는 종목 상세에서 「중립」·「지지이탈↓」이 맨 글씨로 나왔다. 어닝 탭은 같은 규칙을
 * 인라인으로 복제하고 있었다 — 배지를 쓰는 화면이 늘 때마다 복제가 늘 구조였다. */
.qb{display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:700;white-space:nowrap}
.qb-acc{background:#e6f4ea;color:#1e7e34}.qb-neu{background:#eef1f4;color:#667}
/* 불꽃형 = 옛 폭발형+추격주의 (2026-08-02 통합) — 처방이 「매수 금지」 하나라 가장 강한 색 */
.qb-flame{background:#c62828;color:#fff}
.qb-hot{background:#fff3e0;color:#b26a00}.qb+.qb,.bx+.qb{margin-left:4px}
/* 배지 안의 신호일 꼬리표 — 부차 정보라 작고 옅게(연한 배경 위라 진짜 회색을 쓴다) */
.sig-d{font-size:9.5px;font-weight:700;opacity:.62;margin-left:2px}
/* 두 줄 표 머리 — 묶음 이름은 아래 갈래와 이어져 보이게(테두리 없음), 갈래는 한 단계 작게 */
table.pf th.th-grp{border-bottom:1px solid #e6ecf2;font-size:11.5px;letter-spacing:-.01em}
table.pf th.th-sub{font-size:11.5px;font-weight:700;color:#7d8b99}
/* 최고 거래대금 박스 (지지·저항 경로) */
.bx{display:inline-block;padding:2px 7px;border-radius:9px;font-size:12px;font-weight:600;white-space:nowrap}
.bx-new{background:#eef1f4;color:#567}.bx-in{background:#eef4fb;color:#28527a}
.bx-brk{background:#e6f4ea;color:#1e7e34}.bx-fake{background:#fdf3e0;color:#b26a00}
.bx-lad{background:#e8f0fe;color:#1a56b0}.bx-dn{background:#fdecea;color:#c62828}.bx-na{background:#f4f4f4;color:#9aa}

/* SUE 배지 — 이름·값·분기를 한 칩에. ★여기만 <b>한국 등락색</b>(빨강=좋은 소식·파랑=나쁜 소식)을
 * 단색으로 쓴다. 다른 배지 팔레트(연한 배경)와 일부러 다르게 해서 실적 신호가 눈에 먼저 들어오게 한다. */
.sue-b{display:inline-block;font-size:10.5px;font-weight:800;border-radius:5px;padding:1px 6px;
  white-space:nowrap;color:#fff;cursor:help;font-variant-numeric:tabular-nums;margin-left:4px}
.sue-b.up{background:#c62828}   /* 어닝서프라이즈 — 상승색 */
.sue-b.dn{background:#1565c0}   /* 어닝쇼크 — 하락색 */
/* 분기는 부차 정보라 작고 옅게. ★단색 배경 위에서는 진짜 회색이 탁해지므로 <b>흰색을 흐린</b> 것이 회색으로 읽힌다 */
.sue-b .sue-q{font-size:9.5px;font-weight:700;color:rgba(255,255,255,.68);margin-left:2px}

/* 매매 판정 배지 — 매수·매도에서 같은 방향이 반대 뜻이 되므로 색이 아니라 <b>말</b>이 먼저다 */
/* 재진입은 관문이 둘이라 칩이 나란히 두 개 뜰 수 있다 — 붙으면 한 낱말로 읽힌다 */
.vd{display:inline-block;font-size:11.5px;font-weight:800;border-radius:6px;padding:2px 7px;white-space:nowrap;margin-right:3px}
.vd:last-child{margin-right:0}
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

/* 헤더의 모바일 규칙은 stock/lib/topbar.php 안에 함께 있다 */
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
/**
 * 화면 아래에 잠깐 뜨는 알림(pfToast) 한 벌. once 가드가 있어 여러 번 불러도 한 번만 실린다.
 * ★단일본이다 — 예전에는 pf_sort_script() 안에만 있어서, 정렬 표가 없는 화면(관심차트 등)이
 *   pfToast 를 부르면 조용히 아무 일도 안 일어났다.
 */
function pf_toast_js(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
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
}

function pf_sort_script(string $tableId, string $apiUrl): void
{
    pf_toast_js();

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
                         string $minWidth = '260px', string $maxWidth = ''): void
{
    $shown = trim($name . ($code !== '' ? ' (' . $code . ')' : ''));

    echo '<input type="hidden" name="stock_code" id="' . $prefix . 'Code" value="' . pf_h($code) . '">';
    echo '<input type="hidden" name="stock_name" id="' . $prefix . 'Name" value="' . pf_h($name) . '">';
    echo '<div class="fld stk-wrap" style="flex:1;min-width:' . $minWidth
       . ($maxWidth !== '' ? ';max-width:' . $maxWidth : '') . '"><span>' . pf_h($label);
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
  var timer = null, items = [];

  function close(){ box.innerHTML = ''; box.classList.remove('on'); }

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
        .then(function(list){ items = list || []; render(); })
        .catch(close);
    }, 220);
  });

  /* ↓/↑/Enter/Esc — 공용 모듈(style/acnav.js)이 맡는다. 화면마다 다시 적지 않는다. */
  AcNav.attach(inp, { box: box, pick: function(li, i){ pick(i); }, close: close });

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

    pf_acnav_js();
    echo str_replace(['__PFX__', '__REQ__'], [$prefix, $require ? 'true' : 'false'], $js);
}

/**
 * 자동완성 «키보드 이동» 공용 모듈(style/acnav.js)을 한 번만 싣는다.
 *
 * ★검색 자동완성을 새로 만들면 반드시 이것을 부르고 AcNav.attach() 를 건다 —
 *   안 하면 «마우스로만 고를 수 있는» 검색창이 또 생긴다(2026-08-05 사용자 지적).
 */
function pf_acnav_js(): void
{
    static $done = false;
    if ($done) return;
    echo '<script src="/style/acnav.js?v=1"></script>';
    $done = true;
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
  var timer = null, items = [];

  function close(){ box.innerHTML = ''; box.classList.remove('on'); }
  function go(code){ location.href = '/stock/index.php?mode=fund&code=' + code; }
  function pick(i){ if (items[i]) go(items[i].code); }

  function render(){
    box.innerHTML = '';
    if (!items.length) { close(); return; }
    items.forEach(function(it, i){
      var li = document.createElement('li');
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
        .then(function(list){ items = list || []; render(); })
        .catch(close);
    }, 220);
  });

  /* ↓/↑/Enter/Esc — 공용 모듈(style/acnav.js). 아무것도 안 고른 채 Enter 면 첫 항목으로 간다. */
  AcNav.attach(inp, {
    box:        box,
    pick:       function(li, i){ pick(i); },
    close:      close,
    enterFirst: true,
    // 6자리 코드를 알고 있으면 목록을 기다릴 것 없이 바로 연다 (고른 자리가 있으면 그쪽이 이긴다)
    onEnter: function(e, i){
      if (i >= 0) return false;
      var m = inp.value.trim().match(/^(\d{6})$/);
      if (!m) return false;
      e.preventDefault(); go(m[1]); return true;
    }
  });

  inp.addEventListener('blur', function(){ setTimeout(close, 120); });
})();
</script>
JS;
    pf_acnav_js();
    echo str_replace('__PFX__', $prefix, $js);
}

/**
 * FnGuide 기업분석(wcomp) 바로가기 버튼.
 *
 * 우리 화면은 DART 원본 재무만 그린다 — 컨센서스·업종비교·지분구조처럼 <b>우리가 안 가진 것</b>을
 * 보고 싶을 때가 있어 밖으로 나가는 문을 하나 둔다.
 *
 * ★ <b>팝업</b>이다(같은 탭 이동 아님) — 보던 재무 표를 그대로 두고 옆에 띄워 견주는 게 이 버튼의 쓸모다.
 * ★ URL·창 크기를 화면에 다시 적지 않는다 — 다른 화면에도 달게 되면 여기 한 줄만 본다.
 * ★ 코드는 6자리 그대로 넣는다(우선주는 상세가 이미 본주로 옮겨 온 뒤라 본주 코드가 온다).
 */
function pf_fnguide_btn(string $code, string $cls = 'btn btn-outline'): void
{
    $code = preg_replace('/[^0-9A-Za-z]/', '', $code);
    if ($code === '') return;
    $url = 'https://wcomp.fnguide.com/?c_id=AA&menu_type=01&cmp_cd=' . rawurlencode($code);
    // href 를 남겨 둔다 — JS 가 없거나 팝업이 막히면 새 탭으로라도 열려야 한다
    echo '<a class="' . pf_h($cls) . '" href="' . pf_h($url) . '" target="_blank" rel="noopener"'
       . ' title="FnGuide 기업분석 — 컨센서스·업종비교 등 DART 에 없는 것을 팝업으로 봅니다"'
       . ' onclick="return !window.open(this.href,\'fnguide\','
       . '\'width=1180,height=920,scrollbars=yes,resizable=yes\')">FnGuide</a> ';
}

// ── 최근조회 종목 (etf_stock 과 표를 공유) ─────────────────────────────
/**
 * 종목 검색은 화면을 옮겨 다니며 하는 일이라, 화면마다 따로 이력을 남기면
 * 방금 ETF 화면에서 본 종목을 재무분석에서 또 쳐야 한다.
 * ⇒ 저장·조회를 <b>StockRepository(etf_recent_view_stocks)</b> 한 곳으로 맞춘다.
 *   etf_stock.php 가 쓰는 바로 그 표다 — 한쪽에서 본 종목이 다른 쪽 칩에도 뜬다.
 *
 * ★ 저장하는 자리는 <b>「상세를 연 순간」 한 곳뿐</b>(pf_page_fund_detail)이다.
 *   바로가기 검색·목록 행 클릭·관심종목 어디로 들어와도 결국 상세로 오므로 입구마다 흩뿌리지 않는다.
 * ★ 이름은 <b>거래소 종목명</b>을 우선한다 — DART 법인명(현대자동차)을 그대로 넣으면
 *   ETF 화면이 남긴 이름(현대차)과 같은 종목이 화면마다 다른 이름으로 보인다.
 *   (표의 키는 종목코드라 행이 갈리지는 않고, 이름만 마지막에 연 화면 것으로 덮인다)
 */
function pf_fund_recent_name(PDO $pdo, string $code, string $fallback = ''): string
{
    $st = $pdo->prepare("
        SELECT stock_name FROM krx_daily
         WHERE stock_code = ? AND bas_dd = (SELECT MAX(bas_dd) FROM krx_daily)
    ");
    $st->execute([$code]);
    $n = trim((string)$st->fetchColumn());
    return $n !== '' ? $n : $fallback;
}

/** 이력 저장. 곁다리 기능이라 실패해도 화면은 그대로 그린다. */
function pf_fund_recent_save(PDO $pdo, string $code, string $name): void
{
    if ($code === '' || $name === '') return;
    try { (new StockRepository($pdo))->saveRecentStock($code, $name); }
    catch (Throwable $e) { error_log('pf_fund_recent_save: ' . $e->getMessage()); }
}

/**
 * 최근조회 칩 줄. 누르면 그 종목 재무 상세로 간다.
 * @param string $cur 지금 보고 있는 종목코드 (있으면 그 칩을 채워서 보인다)
 * @param bool   $sep 위에 다른 줄이 있는 카드 안이면 true — 점선으로 갈라 준다
 */
function pf_fund_recent(PDO $pdo, string $cur = '', bool $sep = false): void
{
    static $cssDone = false;
    if (!$cssDone) {
        echo <<<'CSS'
<style>
.rec-row{display:flex;flex-wrap:wrap;align-items:center;gap:6px}
.rec-row.sep{margin-top:9px;padding-top:9px;border-top:1px dashed #e3ebf2}
.rec-lab{font-size:12px;font-weight:700;color:#5f7183;margin-right:2px}
.rchip{display:inline-block;padding:5px 12px;border:1px solid #dbe4ec;border-radius:20px;
  background:#fff;font-size:12.5px;color:#4a5c6d;text-decoration:none;white-space:nowrap}
.rchip:hover{border-color:#1d5c93;background:#f2f8fd;color:#1d5c93}
.rchip.on{background:#eef5fb;border-color:#1d5c93;color:#1d5c93;font-weight:700}
</style>
CSS;
        $cssDone = true;
    }

    try { $list = (new StockRepository($pdo))->getRecentStocks(12); }
    catch (Throwable $e) { $list = []; }

    echo '<div class="rec-row' . ($sep ? ' sep' : '') . '">';
    echo '<span class="rec-lab" title="ETF 화면과 같은 이력입니다 — 어디서 열어 본 종목이든 여기 남습니다">최근조회</span>';
    if (!$list) {
        echo '<span class="muted" style="font-size:12px">아직 없습니다 — 종목 상세를 열면 여기 남습니다.</span>';
    }
    foreach ($list as $it) {
        $c = (string)($it['stock_code'] ?? '');
        $n = (string)($it['stock_name'] ?? '');
        if ($c === '' || $n === '') continue;
        echo '<a class="rchip' . ($c === $cur ? ' on' : '') . '"'
           . ' href="/stock/index.php?mode=fund&code=' . rawurlencode($c) . '"'
           . ' title="' . pf_h($c) . '">' . pf_h($n) . '</a>';
    }
    echo '</div>';
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
    $lvMap     = $pf->positionLevelsMap(array_column($positions, 'id'));   // 퀀트 사다리 (있는 포지션만)

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
        /* 사이클 나이(첫 매수일부터) — 「장기물림」 경보가 이 값과 차수로 판정된다.
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

/**
 * 포트폴리오 한 개(또는 전체 합계)의 돈 셈 — 예수금·추정자산·실현손익·수익률을 <b>한 곳에서</b> 만든다.
 *
 * 목록 소계·전체 합계·상세 요약 세 곳이 각자 더하면 곧 갈린다. 실제로 청산분이 한 곳에서만
 * 빠져 1,772,382원이 어긋난 적이 있다(위 pf_calc_closed 주석).
 *
 * ★ <b>income = 손익성 입출금</b>(pf_income_flow 합계) — <b>체결기록으로는 만들 수 없는 돈</b>이다.
 *   ①이월 실현손익: 포트폴리오에 담기 전에 그 계좌에서 이미 난 손익
 *     (실측 관사장학회: 증권사 예수금 8,855,233 vs 화면 4,469,607 = 4,385,626원 차이가 그것이었다)
 *   ②배당금: 종목을 팔지 않아도 들어오므로 매도 기록에 영영 안 잡힌다.
 * ★ <b>원금이 아니라 이익이다.</b> 그래서 예수금·실현손익에는 더하고
 *   <b>수익률 분모(원금)는 건드리지 않는다</b>. 원금으로 넣으면 「2천만으로 시작해 이익이 났다」가
 *   「2천4백만을 넣었다」로 뒤바뀌고, 배당을 받을수록 수익률이 낮아진다.
 */
function pf_folio_money(float $prin, float $income, array $sub): array
{
    $cash  = $prin + $income + $sub['flow'];  // 예수금 = 원금 + 손익성입금 − 매수지출 + 매도수취
    $asset = $cash + $sub['net'];             // 추정자산 = 예수금 + 보유 현재가치
    return [
        'cash'  => $cash,
        'asset' => $asset,
        'real'  => $sub['real'] + $income,    // 실현손익 = 기록된 체결분 + 이월·배당
        'rate'  => ($prin > 0) ? ($asset / $prin - 1) : null,
    ];
}

/** 포트폴리오 행에서 손익성 입출금 합계를 읽는다 (표가 아직 없는 환경도 0 으로 흐르게). */
function pf_income(array $f): float
{
    return (float)($f['income_pl'] ?? 0);
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
        $prin  = (float)$f['principal'];
        $income = pf_income($f);
        $m      = pf_folio_money($prin, $income, $sub);
        $out[$fid] = [
            'name'  => (string)$f['name'],
            'prin'  => $prin,
            'income' => $income,
            'cash'  => $m['cash'],
            'asset' => $m['asset'],
            'real'  => $m['real'],
            'rate'  => $m['rate'],
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
function pf_signal_list(array $positions, array $calc, array $stats, array $dailyMap = [],
                        array &$used = []): array
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

        /* 유동성 — 이제 <b>등급(grade)만</b> 쓴다. 얇은 종목에서 「★ 거래량 N배」를 강조하는 데 필요하다.
         * ⊖ 계획 수량·참여율은 실행 게이트(2026-08-02 삭제)가 유일한 소비처였다 — 그래서 안 넘긴다. */
        $liq  = $bars ? pf_liquidity($bars) : [];
        $rows[] = [
            'p' => $p, 'c' => $c, 's' => $sig,
            'pid' => $pid, 'fid' => $fid, 'last' => $last,
            'fname' => (string)($stats[$fid]['name'] ?? ''),
            'fund_ok' => null, 'fund_left' => null,
            'ind'  => $ind,
            'liq'  => $liq,
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

    /* $used 를 밖으로 흘려 보낸다 — 재진입 후보가 <b>남은 예수금</b>으로 이어서 판정하기 위해서다.
     * (계획 신호가 먼저 가져간다: 이미 시작한 사이클이 새 사이클보다 앞선다) */
    return pf_fund_gate($rows, $stats, $used);
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
function pf_fund_gate(array $rows, array $stats, array &$used = []): array
{
    foreach ($rows as &$g) {
        if ($g['s']['kind'] !== 'buy' && $g['s']['kind'] !== 'fill') continue;
        $t = pf_fund_take($used, $stats, (int)$g['fid'], (float)$g['s']['amount']);
        $g['fund_left'] = $t['left'];
        $g['fund_ok']   = $t['ok'];
    }
    unset($g);
    return $rows;
}

/**
 * 예수금에서 <b>한 건을 떼어 간다</b> — 게이트의 셈 한 벌.
 *
 * 계획 신호(pf_fund_gate)와 재진입 후보(pf_page_dashboard)가 <b>같은 예수금</b>을 다투므로
 * 배정 규칙이 두 벌이면 "같은 돈을 두 곳이 각각 쓸 수 있다고 말하는" 화면이 된다.
 * 그래서 셈을 이 함수 하나에 가둔다.
 *
 * ★ $used 는 포트폴리오별 누적 배정액이다 — <b>부르는 순서가 곧 우선순위</b>다.
 *   넘겨받은 상태에서 이어 배정하므로, 먼저 부른 쪽이 먼저 돈을 가져간다.
 * ★ 배정은 <b>남은 돈까지만</b>. 앞 건이 예수금을 넘겨도 없는 돈을 쓴 것으로 치면 뒤가 더 틀어진다.
 */
function pf_fund_take(array &$used, array $stats, int $fid, float $need): array
{
    $prev = (float)($used[$fid] ?? 0);
    $left = (float)($stats[$fid]['cash'] ?? 0) - $prev;
    $used[$fid] = $prev + min($need, max(0.0, $left));
    return ['ok' => ($need <= $left + 0.5), 'left' => $left];
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
 * 사이클 나이 셀 — 나이 + (걸리면) 「장기물림」 경보 배지.
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
 * 퀀트 사다리 상세표 HTML — pf_box_ladder_detail() 결과를 룰셋 화면처럼 차수별로 펼친다.
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
 * 「계단관통↓」 경보 지도 — <b>포지션 id</b> → 배지 HTML (해당 없으면 키 없음).
 *
 * 사다리×퀀트 결합 연구(2026-08-01) 의 화면 반영: 사다리(분할매수)의 전제는 「떨어져도 반등한다」인데,
 * 기준 박스에서 아래 계단(지지구조)까지 <b>전부</b> 종가로 뚫렸다면
 * (KrxAmt::boxStatusMany 의 「지지이탈↓」 — 붕괴 전수의 2~3%인 드문 사건) 그 전제가 깨졌다는 실측 신호다.
 * 원장 백테스트: 이 신호에서 사다리를 중단·청산하면 물림 11.3%→5.2%(양 기간 일관) · 비용 중앙 −0.8%p.
 *
 * ★★<b>M3 (v0.3 §2.2) — 기준 박스는 `pf_position.surge_event_d` 로 고정됐다.</b>
 *   예전에는 매 요청 `SELECT MAX(d) FROM krx_surge` 로 <b>가장 최근 박스</b>를 다시 찾았다. 그래서 이 경보는
 *   "내가 산 이유였던 박스가 무너졌다"가 아니라 "나중에 뜬 아무 박스가 무너졌다"를 뜻하고 있었다
 *   (실측 2026-08-03: 보유 12건 중 8건이 편입일보다 3~9개월 <b>뒤</b>의 박스로 경보가 서 있었다).
 *   ⇒ 이제 편입 시점에 못박은 그 이벤트만 본다. 「지금」이 아니라 「그때」가 기준이다.
 *
 * ★★<b>키가 종목코드가 아니라 포지션 id 다.</b> 같은 종목을 여러 포트폴리오에 담을 수 있고
 *   포지션마다 기준 박스가 다르므로(실측: 000970 은 세 포지션 중 하나만 기준 박스가 있다)
 *   코드로 묶으면 남의 판정을 물려받는다.
 *
 * ★`surge_event_d` 가 NULL 이면 <b>판정하지 않는다</b>(경보 없음) — 「기준 박스 없음」이고,
 *   그 사실은 종목 상세 상태판이 따로 밝힌다. 빈 칸으로 두면 「이상 없음」과 구별되지 않는다.
 * ★자동으로 매도하지 않는다 — 장기물림 배지와 같은 「판단 소집」 철학.
 * ★$pdo 는 전역(cnt.inc) — 이 함수는 pf_render_folio_detail 처럼 PDO 를 안 받는 렌더러에서도 불린다.
 *
 * @param array $positions 포지션 행들 (id · stock_code · surge_event_d 를 갖는 것 — SELECT p.* 면 충분)
 */
function pf_stair_alert_map(array $positions): array
{
    $sigs = [];   // boxStatusMany 입력 (code,d) — 중복 제거
    $mine = [];   // 포지션 id => "code|d"
    foreach ($positions as $p) {
        $code = (string)($p['stock_code'] ?? '');
        $d    = (string)($p['surge_event_d'] ?? '');
        $id   = (int)($p['id'] ?? 0);
        if ($code === '' || $d === '' || $id <= 0) continue;
        $mine[$id] = $code . '|' . $d;
        $sigs[$code . '|' . $d] = ['code' => $code, 'd' => $d];
    }
    if (!$sigs) return [];

    try {
        /* ★$bxOnly=false — 경로 게이트(2026-08-10 박스 상향돌파 재정의)의 <b>유일한 예외</b>다.
         *   여기 기준 박스는 편입 시 못박은 «기록»(pf_position.surge_event_d)이고, 이 경보는
         *   지지 구조 소멸을 알리는 안전장치라 탐색 기준이 바뀌어도 계속 봐야 한다. */
        $box = (new KrxAmt($GLOBALS['pdo']))->boxStatusMany(array_values($sigs), false);
        $map = [];
        foreach ($mine as $id => $key) {
            $b = $box[$key] ?? null;
            if (!$b || $b['st'] !== 'bx-dn' || !str_contains((string)$b['txt'], '지지이탈')) continue;
            $d = explode('|', $key)[1];
            $map[$id] = '<span class="mkt t-risk" title="' . pf_h(
                '편입 시 기준으로 삼은 최고 거래대금 박스(' . $d . ')의 아래 계단(지지구조)이 전부 종가로 뚫렸습니다 — '
                . '붕괴 전수의 2~3%인 드문 사건. 사다리 백테스트(2026-08-01): 이 신호에서 중단·청산하면 '
                . '물림 11.3%→5.2%·비용 중앙 −0.8%p. 자동 매도는 없습니다 — 분할매수의 전제(반등)가 '
                . '깨졌는지 재평가하라는 소집 신호입니다.') . '">계단관통↓</span>';
        }
        return $map;
    } catch (Throwable $e) {
        return [];   // 경보가 없어도 화면은 뜬다 (krx_amt 미구축 환경 포함)
    }
}

/**
 * 두 줄 표 머리 — 붙어 있는 열 몇 개를 <b>한 이름 아래로</b> 묶는다 (2026-08-02).
 *
 * 퀀트신호와 박스 상태처럼 <b>같은 사건에서 나온 값</b>을 나란한 열로만 두면 서로 다른 것으로 읽힌다.
 * 그래서 위 줄에 묶음 이름(「퀀트 : 최고 거래대금」), 아래 줄에 갈래(「신호」·「경로」)를 둔다.
 *
 * @param array $cols 보통 열은 [label, class] · 묶음은 ['group'=>이름, 'cols'=>[[label,class], …]]
 */
function pf_thead_grouped(array $cols): void
{
    $subs = [];
    echo '<thead><tr>';
    foreach ($cols as $c) {
        if (isset($c['group'])) {
            echo '<th colspan="' . count($c['cols']) . '" class="center th-grp">' . pf_h($c['group']) . '</th>';
            foreach ($c['cols'] as $s) $subs[] = $s;
        } else {
            echo '<th rowspan="2" class="' . pf_h($c[1]) . '">' . pf_h($c[0]) . '</th>';
        }
    }
    echo '</tr><tr>';
    foreach ($subs as [$l, $cl]) echo '<th class="' . pf_h($cl) . ' th-sub">' . pf_h($l) . '</th>';
    echo '</tr></thead>';
}

/**
 * 이미 <b>자리가 있는</b> 종목 — 탐색 화면 넷(스크리너·어닝·퀀트·관심종목)의 <b>단일 판정</b> (2026-08-04).
 *
 * 발단: 관심종목만 이 판정을 갖고 있어서 <b>같은 종목이 화면마다 다른 말</b>을 했다 —
 * 이미 보유 중인 SK하이닉스가 관심종목에선 「보유」인데 재무 스크리너에선 「편입」 버튼이었다.
 * 판정을 화면에 다시 적지 않는다(Thr·ChartFeat 와 같은 「원본 → 화면 표시」 패턴).
 *
 * ★ 기준은 <b>살아 있는 포지션</b>(status ≠ closed)이다. 청산은 「담고 있지 않다」라 여기 안 담긴다 —
 *   그 포트폴리오엔 못 담아도 <b>다른 포트폴리오엔 새로 담을 수 있고</b>, 막히면 api 중복 가드가 알려 준다.
 *   종목 단위로 미리 막으면 멀쩡한 길까지 닫힌다.
 * ★ 「자리가 있다」와 「샀다」는 다르다 — status='watch' 는 포지션만 만들고 한 주도 안 산 상태다.
 *   버튼을 숨기는 이유는 «자리가 있어서»라 둘 다 숨기되, <b>배지 말은 갈라야</b> 거짓이 안 된다.
 * ★ <b>Pf::positions() 를 쓰지 않는다</b> — 그쪽은 pf_stock·pf_rule_set 과 INNER JOIN 이라
 *   어느 하나가 비면 행이 통째로 사라지고, 그러면 보유 종목이 조용히 「편입 가능」으로 보인다.
 *
 * @return array code => ['id'=>포지션 id, 'n'=>포지션 수, 'names'=>포트폴리오 이름들, 'open'=>매수 기록 있음]
 */
function pf_held_map(PDO $pdo, array $codes): array
{
    $codes = array_values(array_unique(array_filter(array_map('strval', $codes), 'strlen')));
    if (!$codes) return [];
    $map = [];
    try {
        $in = implode(',', array_fill(0, count($codes), '?'));
        $st = $pdo->prepare("SELECT p.id, p.stock_code, p.status, f.name AS fn
                               FROM pf_position  p
                               JOIN pf_portfolio f ON f.id = p.portfolio_id
                              WHERE p.status <> 'closed' AND p.stock_code IN ($in)
                              ORDER BY p.id");
        $st->execute($codes);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = (string)$r['stock_code'];
            if (!isset($map[$k])) $map[$k] = ['id' => (int)$r['id'], 'n' => 0, 'names' => '', 'open' => false];
            $map[$k]['n']++;
            $map[$k]['names'] .= ($map[$k]['names'] === '' ? '' : ' · ') . (string)$r['fn'];
            // 한 곳이라도 보유 중이면 「보유」 — 실제로 돈이 들어가 있는 쪽이 더 중요한 사실이다
            if ((string)$r['status'] === 'open') {
                $map[$k]['open'] = true;
                $map[$k]['id']   = (int)$r['id'];   // 링크는 보유 중인 포지션으로
            }
        }
    } catch (Throwable $e) {
        return [];   // 포지션 표가 없는 환경 — 편입 버튼을 그대로 둔다
    }
    return $map;
}

/** 보유 / 편입됨 배지 — 말과 색의 정본. 「포트폴리오」 칸과 어닝쇼크 목록이 같은 것을 쓴다. */
function pf_held_badge(array $hb): string
{
    $st = $hb['open']
        ? ['보유',   'st-held', '이미 담고 있습니다']
        : ['편입됨', 'st-slot', '포지션은 만들어 뒀지만 아직 매수 기록이 없습니다'];
    return '<a class="badge ' . $st[1] . '" href="/stock/index.php?mode=position&id=' . (int)$hb['id']
         . '" title="' . pf_h($st[2] . ' — ' . $hb['names'] . ' · 눌러서 그 종목 상세로') . '">'
         . $st[0] . ($hb['n'] > 1 ? ' ' . (int)$hb['n'] : '') . '</a>';
}

/**
 * 「포트폴리오」 칸 속 — 셋 중 <b>하나만</b> 온다: 편입(알약) / 보유 / 편입됨.
 * ★열 이름이 「상태」가 아닌 이유: 이 사이트는 포지션 상태·박스 상태에도 그 말을 써서
 *   무엇을 담은 열인지 알 수 없다. 이 칸이 답하는 것은 「내 포트폴리오에 있나」다(2026-08-04 사용자).
 *
 * ★★ 이미 자리가 있으면 <b>편입 버튼을 그리지 않는다</b>(편입 관심종목 카드의 「선택」과 같은 규칙).
 * ★ 화면마다 다른 것은 편입 버튼이 <b>실어 나르는 것</b>(M2 의 trig·sed)과 안내 문구뿐이라 그것만 인자로 받는다.
 *   어느 화면에서 눌렀는지(src)는 각 화면의 JS 가 이미 붙이고 있으므로 여기서 손대지 않는다.
 *
 * @param array|null $hb   pf_held_map() 의 그 종목 항목 (없으면 null = 담을 수 있음)
 * @param array      $data 편입 버튼에 실을 data-* (빈 값은 싣지 않는다 — 없으면 저장 쪽이 메운다)
 */
function pf_adopt_cell(string $code, string $name, ?array $hb, array $data = [], string $tip = ''): string
{
    if ($hb) return pf_held_badge($hb);
    $at = '';
    foreach ($data as $k => $v) {
        if ((string)$v === '') continue;
        $at .= ' data-' . pf_h((string)$k) . '="' . pf_h((string)$v) . '"';
    }
    return '<button type="button" class="pf-adopt" data-code="' . pf_h($code) . '" data-name="' . pf_h($name) . '"'
         . $at . ' title="' . pf_h($tip !== '' ? $tip
             : '포트폴리오에 편입 — 종목 추가 폼이 열립니다 · 포트폴리오 미지정 저장 = 편입 관심종목') . '">편입</button>';
}

/**
 * 신호일 꼬리표 — 배지 라벨 끝에 붙일 짧은 날짜 (2026-08-02 사용자 제안: 「매집형 7.21」).
 *
 * ★ <b>화면에서 신호일을 알 수 없는 곳에만</b> 붙인다:
 *   붙임 = 관심종목 · 종목 상세 (종목마다 신호일이 다르고 <b>반년 전일 수도</b> 있다)
 *   생략 = 퀀트 목록(카드 제목에 날짜) · 매집형 박스 추적(「신호일」 열) · 단타 목록(오늘만)
 *   — 이미 화면에 있는 날짜를 배지마다 되풀이하면 그건 정보가 아니라 잡음이다.
 * ★ 해가 다르면 연도를 붙인다 — 「12.5」만 보면 반년 전 신호를 올해 것으로 읽는다.
 */
function pf_sig_date_tag(string $d): string
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return '';
    $short = ((int)$m[2]) . '.' . ((int)$m[3]);
    if ($m[1] !== date('Y')) $short = substr($m[1], 2) . '.' . $short;
    return ' <span class="sig-d">' . $short . '</span>';
}

/**
 * 20·40거래일 모멘텀 칩 — <b>창별로 따로</b> 세운다 (2026-08-02 사용자 지시).
 *
 *   20일 +112%  20일 −52%   40일 +139%  40일 −60%
 *
 * ★ 이건 <b>① 시장 층</b>이다 — 예전엔 「급등⚠」 한 칩으로 퀀트신호(①b) 옆에 붙어 있었지만,
 *   재는 것이 거래대금이 아니라 <b>가격의 최근 움직임</b>이라 시장지표와 같은 계열이다.
 *   1-a 의 「+16.2%」(하루)와 시간축만 다르다 — 그래서 표기도 「기간 + 부호%」로 맞췄다.
 * ★ 색이 방향마다 다르다:
 *   급등 = 주황(경고) — 실측 근거가 있다(이 무리의 돌파 매수는 중앙 0%·승률 51.6%, 엣지 없음).
 *   급락 = 회색(정보) — <b>백테스트 근거가 없다</b>(사용자 지정 −30%/−50%). 그래서 경고색을 쓰지 않는다.
 *
 * ★★ <b>기준 시점을 반드시 밝힌다</b>(2026-08-02 실측으로 잡은 함정): 같은 「20일 +90%」라도
 *   momNow(오늘 기준)와 momMany(신호일 기준)는 전혀 다른 사실이다. 현대차는 신호일(2026-01-21)
 *   기준 +90.3% 인데 <b>오늘 기준 −19.5%</b> 였다 — 반년 전 값을 「지금」으로 읽으면 정반대가 된다.
 *   그래서 칩 라벨에 기준일을 접두로 붙이고(오늘이면 생략) 툴팁에도 다시 적는다.
 *
 * @param array|null $mm     KrxAmt::momNow() 또는 momMany() 의 한 항목
 * @param string     $asOf   기준일 'YYYY-MM-DD'. 빈 값이면 오늘 기준으로 본다.
 * @param bool       $isNow  true = 오늘 기준(라벨에 날짜를 안 붙인다) · false = 신호일 기준
 */
function pf_mom_chips(?array $mm, string $asOf = '', bool $isNow = true): string
{
    if (!$mm) return '';
    $out = '';
    $pct = static fn(?float $v): string => $v === null ? '-' : sprintf('%+.0f', $v * 100) . '%';
    $when = $isNow
        ? '오늘' . ($asOf !== '' ? '(' . $asOf . ')' : '') . ' 종가 기준'
        : '신호일' . ($asOf !== '' ? ' ' . $asOf : '') . ' 기준 — <b>지금 값이 아닙니다</b>';
    $tag  = $isNow ? '' : '신호일 ';   // 오늘이 아니면 라벨에서부터 드러낸다
    $why  = ' 급등. 20일 +80% 또는 40일 +100% 뒤의 돌파 매수는 실측 중앙 0.00%·승률 51.6%로 엣지가 없습니다'
          . '(평균만 +7%인 복권꼬리 — 검증 탭 ⑧). 금지가 아니라 고지이며 손절 규칙은 그대로입니다.';
    $cold = ' 급락. 임계(20일 −30% · 40일 −40%)는 백테스트 근거가 없는 지정값입니다 —'
          . ' 얼마나 빠르게 빠졌는지 보는 참고 표시입니다.';
    // 색 = 등락색(빨강 급등 · 파랑 급락). 근거의 유무는 색이 아니라 툴팁이 말한다
    $chip = static function (int $w, ?float $v, bool $hot) use ($pct, $when, $tag, $why, $cold): string {
        return '<span class="mkt ' . ($hot ? 't-up' : 't-down') . '" title="'
             . pf_h(strip_tags($when) . ' · ' . $w . '거래일 ' . $pct($v) . ($hot ? $why : $cold))
             . '">' . $tag . $w . '일 ' . $pct($v) . '</span>';
    };
    if (!empty($mm['hot20']))       $out .= $chip(20, $mm['m20'], true);
    elseif (!empty($mm['cold20']))  $out .= $chip(20, $mm['m20'], false);
    if (!empty($mm['hot40']))       $out .= $chip(40, $mm['m40'], true);
    elseif (!empty($mm['cold40']))  $out .= $chip(40, $mm['m40'], false);
    return $out;
}

/**
 * SUE 배지 지도 — 종목코드 → 배지 HTML (최신 분기 SUE 가 ±1 밖일 때만).
 *
 *   SUE ≥ +1  <span class="sue-b up">SUE +2.1<span class="sue-q">26.1Q</span></span>  상위 20% 안팎 — 공시 후 두 달 상방 드리프트
 *   SUE ≤ −1  <span class="sue-b dn">SUE -1.4<span class="sue-q">26.1Q</span></span>  회피 목록 — 거의 매년 음수·두 달 하방 드리프트
 *   ★ 색은 한국 등락색(빨강=좋은 소식·파랑=나쁜 소식) · 배지 하나에 값+분기를 담는다
 *   ★ 라벨은 「SUE」로 줄였다(2026-08-12 사용자 — 「어닝서프라이즈/어닝쇼크」는 목록에서 너무 길다.
 *     뜻은 배경색과 부호가 말한다). 긴 말을 그대로 두는 곳은 <b>재무분석 상세</b>(pf_fund_filing_cells)뿐.
 *
 * ★ 이것은 <b>경보(포트폴리오)가 아니라 SUE 층 배지</b>다(2026-08-02 사용자 정의) —
 *   포트폴리오 경보 셋(장기물림·N차 지연·계단관통↓)은 「내가 산 포지션」의 사정인데
 *   SUE 는 <b>그 종목 자체의 사실</b>이라 사지 않은 종목에도 그대로 성립한다. 그래서 자리가 다르다.
 * ★ 판정은 pf_sue_stock — 어닝 탭·스크리너·종목 상세와 같은 계산이라 화면끼리 어긋나지 않는다.
 * ★ 자동 매매는 없다 — 실적 전제가 유지되는지 다시 보라는 표시다.
 */
function pf_sue_badge_map(array $codes): array
{
    $codes = array_values(array_unique(array_filter($codes)));
    if (!$codes) return [];
    $map = [];
    try {
        $pdo = $GLOBALS['pdo'];
        foreach ($codes as $c) {
            $sq = pf_sue_stock($pdo, $c);          // 1~2ms/종목 — 보유 십수 종목이라 충분
            if (!$sq) continue;
            $qk = array_key_last($sq);
            $v  = (float)$sq[$qk];
            if ($v > Thr::SUE_SHOCK && $v < Thr::SUE_HIT) continue;   // ±1 안은 표시하지 않는다 (이례만 배지가 된다·M5)
            $sy = intdiv($qk - 1, 4); $sqn = $qk - $sy * 4;
            /* ★ 색은 <b>한국 등락색</b>을 따른다(2026-08-02 사용자 지시) — 빨강=좋은 소식(어닝서프라이즈)·
             *   파랑=나쁜 소식(어닝쇼크). 배지 하나에 <b>이름 + SUE 값 + 분기</b>를 다 넣어,
             *   옆에 숫자를 따로 두지 않아도 무슨 값인지 읽히게 한다. */
            $lbl  = ($sy % 100) . '.' . $sqn . 'Q';
            $head = '최신 분기(' . $lbl . ') 이익 서프라이즈 SUE ' . sprintf('%+.2f', $v);
            $map[$c] = ($v >= Thr::SUE_HIT)
                ? '<span class="sue-b up" title="' . pf_h($head
                    . ' ≥ +1 = 서프라이즈 무리(상위 20% 안팎) — 8년 백테스트에서 공시 후 두 달 상방 드리프트가'
                    . ' 실측된 자리입니다. 품질(영업흑자)·매출동반이 함께여야 효과가 견고합니다.')
                    . '">SUE ' . sprintf('%+.1f', $v) . '<span class="sue-q">' . $lbl . '</span></span>'
                : '<span class="sue-b dn" title="' . pf_h($head
                    . ' ≤ −1 = 어닝쇼크 무리 — 8년 백테스트에서 거의 매년 음수·공시 후 두 달 하방 드리프트.'
                    . ' 자동 매도는 없습니다 — 실적 전제가 깨졌는지 다시 보라는 표시입니다.')
                    . '">SUE ' . sprintf('%.1f', $v) . '<span class="sue-q">' . $lbl . '</span></span>';
        }
    } catch (Throwable $e) { /* 재무 미구축 환경 — 배지 없이 */ }
    return $map;
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
 *
 * ★★ <b>재진입 후보도 여기서 본다</b>(2026-08-04). 전에는 매매히스토리 탭 하단에만 있어서,
 *    청산한 종목은 그 탭을 일부러 열지 않으면 영영 안 보였다 — 사이클 반복이 이 엔진의 수익원인데
 *    (닫힌 사이클 207개 · 수익 중앙 +20.1%) 그 입구가 하루 동선에서 빠져 있었다.
 *    ★ 후보(ready)만 싣는다. 대기·「아직 비쌈」은 오늘 할 일이 아니라 매매히스토리의 몫이다.
 *
 * @param array $re   pf_reentry_list() 결과 (예수금 게이트를 <b>이미 통과시킨</b> 상태)
 * @param int   $wait 재진입 대기일 — 후보 문구에 근거로 적는다
 */
function pf_render_signal_strip(array $sigs, array $stats, array $re = [], int $wait = 0): void
{
    $act  = array_values(array_filter($sigs, fn($g) => $g['s']['kind'] !== null));
    $waitRows = array_values(array_filter($sigs, fn($g) => $g['s']['kind'] === null));
    $reReady  = array_values(array_filter($re, fn($x) => $x['chk']['state'] === 'ready'));

    $cnt = ['sell' => 0, 'buy' => 0, 'fill' => 0];
    $need = 0.0;
    $short = 0;
    foreach ($act as $g) {
        $cnt[$g['s']['kind']]++;
        if ($g['s']['kind'] === 'sell') continue;
        $need += (float)$g['s']['amount'];
        if ($g['fund_ok'] === false) $short++;
    }
    /* 재진입 1차도 <b>같은 예수금</b>을 쓴다 — 소요·부족 집계에 함께 넣는다.
     * 따로 세면 머리의 「매수 소요」가 실제로 필요한 돈보다 작게 나온다. */
    foreach ($reReady as $x) {
        if ($x['need'] !== null) $need += (float)$x['need'];
        if ($x['fund_ok'] === false) $short++;
    }

    $any = ($act || $reReady);
    echo '<section class="sig-strip' . ($any ? '' : ' quiet') . '">';
    echo '<div class="sig-hd"><h2>오늘의 신호</h2>';
    if ($any) {
        foreach (['sell', 'buy', 'fill'] as $k) {
            if (!$cnt[$k]) continue;
            echo '<span class="sig-pill k-' . $k . '">' . pf_sig_label($k) . ' ' . $cnt[$k] . '건</span>';
        }
        if ($reReady) {
            echo '<span class="sig-pill k-re" title="청산한 종목을 되살 때가 됐는지 — 대기 ' . $wait
               . '일 경과 + 청산가보다 충분히 낮음">재진입 ' . count($reReady) . '건</span>';
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

    if ($any) {
        echo '<div class="sig-list">';
        foreach ($act as $g) {
            $s    = $g['s'];
            $c    = $g['c'];
            $kind = $s['kind'];

            echo '<a class="sig-card k-' . $kind . '" href="/stock/index.php?mode=position&id=' . $g['pid'] . '">';
            echo '<div class="sig-top"><span class="sig-pill k-' . $kind . '">' . pf_sig_label($kind) . '</span>';
            echo '<span class="sig-folio">' . pf_h($g['fname']) . '</span></div>';

            // 차수 표기 — 매수만 "올라가는" 것이라 화살표를 쓴다
            $step = ($kind === 'buy' && (int)$c['reach_step'] > (int)$c['cur_step'])
                ? ((int)$c['cur_step'] > 0 ? $c['cur_step'] . '차 → ' : '') . $c['reach_step'] . '차'
                : ((int)$c['cur_step'] > 0 ? $c['cur_step'] . '차' : '진입');
            /* 카드 본문은 <b>「종목명 차수 ±주수」 한 줄</b>이다 (2026-08-03 사용자 지시).
             * 무엇을 하라는지는 위 배지(매수/잔여매수/매도)가 이미 말하고, 금액·현재가·이론가·자동매도가는
             * 카드를 눌러 들어간 종목 상세에 다 있다. 스트립은 「몇 주」만 보고 지나가는 자리다.
             * ★부호는 수량의 방향 — 담으면 ＋, 팔면 −. 색(k-*)과 같은 뜻이라 서로 어긋나지 않는다.
             * ⊖ 실행 게이트(예수금 게이트 표시 · 체결 게이트 분할 경고)는 2026-08-02 사용자 지시로 삭제.
             *   fund_ok 계산 자체는 유지 — 스트립 머리의 「예수금 부족 N건」 집계가 쓴다. */
            echo '<div class="sig-name">' . pf_h($g['p']['stock_name'])
               . '<span class="step">' . pf_h($step) . '</span>'
               . '<b class="qty k-' . $kind . '">' . ($kind === 'sell' ? '−' : '＋')
               . pf_n($s['qty']) . '주</b></div>';

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

        /* ── 재진입 후보. 계획 신호 <b>뒤에</b> 온다 — 이미 시작한 사이클이 새 사이클보다 앞선다
         *   (예수금 배정 순서와 같다). 카드를 누르면 그 종목의 지난 사이클 상세로 간다. */
        foreach ($reReady as $x) {
            echo '<a class="sig-card k-re" href="/stock/index.php?mode=position&id=' . (int)$x['id'] . '">';
            echo '<div class="sig-top"><span class="sig-pill k-re">재진입</span>';
            echo '<span class="sig-folio">' . pf_h($x['portfolio_name']) . '</span></div>';
            /* 본문 한 줄 = 「종목명 · 청산가 대비」. 계획 신호 카드가 「몇 주」를 말하는 자리에
             * 여기는 <b>얼마나 싸졌나</b>를 말한다 — 재진입은 수량이 아직 없는 판단이다. */
            echo '<div class="sig-name">' . pf_h($x['stock_name'])
               . '<span class="step">' . (int)$x['days'] . '일 전 청산</span>'
               . '<b class="qty k-re">' . pf_h(pf_pct($x['chk']['gap'], 1)) . '</b></div>';
            $tip = '청산가 ' . pf_n($x['last_sell_price'] === null ? null : round($x['last_sell_price']))
                 . ' → 현재가 ' . pf_n($x['last'])
                 . ' · 하락요건 −' . number_format($x['req'] * 100, 1) . '% 충족';
            echo '<div class="sig-conf lv-strong" title="' . pf_h($tip) . '">◎ 대기 ' . $wait . '일 경과 · 값도 내려왔음</div>';
            if ($x['need'] !== null) {
                echo '<div class="mkt-row"><span class="mkt t-info">1차 소요 ' . pf_n(round($x['need'])) . '</span>';
                if ($x['fund_ok'] === false) echo '<span class="mkt t-risk">예수금 부족</span>';
                echo '</div>';
            }
            echo '</a>';
        }
        echo '</div>';
    }

    // ── 대기 종목 — 접어 둔다. <details> 라 JS 가 필요 없다
    if ($waitRows) {
        $near = $waitRows[0];
        $hint = ($near['s']['gap'] !== null)
            ? ' — 가장 임박한 것은 ' . pf_h($near['p']['stock_name']) . ' '
              . (($near['s']['near'] === 'sell') ? '매도' : '매수') . '까지 '
              . number_format(abs($near['s']['gap']) * 100, 1) . '%'
            : '';
        echo '<details class="sig-wait"><summary>대기 ' . count($waitRows) . '종목' . $hint . '</summary>';
        echo '<div class="tbl-scroll"><table class="pf"><thead><tr>';
        foreach ([['종목', ''], ['포트폴리오', ''], ['차수', 'num'], ['현재가', 'num'],
                  ['매수까지', 'num'], ['다음매수가', 'num'], ['매도까지', 'num'],
                  ['자동매도가', 'num'], ['수익률', 'num'], ['시장', '']] as [$l, $cl]) {
            echo '<th class="' . $cl . '">' . pf_h($l) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($waitRows as $g) {
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
    $tIncome    = 0.0;
    foreach ($folios as $f) {
        $tPrincipal += (float)$f['principal'];
        $tIncome    += pf_income($f);
    }

    $t      = pf_sum_calc($calc);
    $tM     = pf_folio_money($tPrincipal, $tIncome, $t);
    $tCash  = $tM['cash'];
    $tAsset = $tM['asset'];
    $tRate  = $tM['rate'];
    $tReal  = $tM['real'];

    /* 포트폴리오별 소계는 한 번만 만들어 목록·신호 스트립·예수금 게이트가 함께 쓴다. */
    $stats = pf_folio_stats($folios, $byFolio, $calc);
    /* 시장 지표용 일봉을 한 쿼리로 당겨 온다 (종목당 쿼리를 내면 N+1 이다).
     * 크론(job=daily)이 채워 두는 pf_daily 를 읽을 뿐이라 네이버를 부르지 않는다. */
    $daily = $pf->dailyMap(array_column($active, 'stock_code'), 400);
    /* $used = 포트폴리오별로 이미 배정된 예수금. 계획 신호가 먼저 가져가고,
     * 그 나머지로 재진입 후보를 판정한다 — 같은 돈을 두 곳이 각각 쓸 수 있다고 말하면 안 된다. */
    $used  = [];
    $sigs  = pf_signal_list($active, $calc, $stats, $daily, $used);

    /* ── 재진입 후보(2026-08-04). 판정은 매매히스토리와 <b>같은 함수</b>다.
     * 하락요건은 룰셋 2차 하락률(기본값) — 조정은 매매히스토리 화면에서 한다. */
    $reWait = PF_SIM_WAIT_DEFAULT;
    $re     = pf_reentry_list($pf, 0, $reWait, null, new DateTimeImmutable('today'));
    foreach ($re as &$rx) {
        if ($rx['chk']['state'] !== 'ready' || $rx['need'] === null) continue;
        $t = pf_fund_take($used, $stats, (int)$rx['portfolio_id'], (float)$rx['need']);
        $rx['fund_ok']   = $t['ok'];
        $rx['fund_left'] = $t['left'];
    }
    unset($rx);

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

    if ($folios) pf_render_signal_strip($sigs, $stats, $re, $reWait);

    // ── 좌우 컬럼. 오른쪽 상세는 맨 위부터 시작하는 독립 영역이다.
    echo '<div class="split">';
    echo '<div class="split-left">';

    // 전체 합계
    echo '<div class="sum-grid">';
    foreach ([
        ['원금',     pf_n($tPrincipal),         '',                       ''],
        ['예수금',   pf_n(round($tCash)),       $tCash < 0 ? 'down' : '',
                                                 $tIncome != 0 ? '원금 + 이월·배당 − 매수 + 매도' : '원금 − 매수 + 매도'],
        ['총매입',   pf_n(round($t['cost'])),   '',                       '보유분 원가'],
        ['총평가',   pf_n(round($t['eval'])),   '',                       ''],
        ['평가손익', pf_n(round($t['pl'])),     pf_updown($t['pl']),      ''],
        ['실현손익', pf_n(round($tReal)),       pf_updown($tReal),
                                                 $tIncome != 0 ? '이월·배당 ' . pf_n(round($tIncome)) . ' 포함' : ''],
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
    $income = pf_income($f);
    $m      = pf_folio_money($prin, $income, $sub);
    $sCash  = $m['cash'];
    $sAsset = $m['asset'];
    $sRate  = $m['rate'];
    $sReal  = $m['real'];
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
        ['실현손익', pf_n(round($sReal)),       pf_updown($sReal)],
        ['추정자산', pf_n(round($sAsset)),      ''],
        ['수익률',   $sRate === null ? '-' : pf_pct($sRate), pf_updown($sRate)],
    ] as [$k, $v, $cls]) {
        echo '<div class="sum-box"><div class="k">' . pf_h($k) . '</div>';
        echo '<div class="v ' . $cls . '">' . pf_h($v) . '</div></div>';
    }
    echo '</div>';

    /* ★ 이월 실현손익이 있으면 <b>출처를 밝힌다</b> — 아래 표의 체결기록을 아무리 더해도
     *   이 금액이 안 나오기 때문이다(종료 포지션 합계와 같은 규칙: 숫자를 깎지 말고 출처를 적는다). */
    if ($income != 0) {
        echo '<p class="sub muted" style="margin:2px 0 10px;font-size:12px">'
           . '이월손익·배당금 <b>' . pf_h(pf_n(round($income))) . '원</b>이 예수금과 실현손익에 포함돼 있습니다 '
           . '(아래 체결기록에는 없는 금액입니다 · '
           . '<a href="/stock/index.php?mode=portfolio&fid=' . $fid . '">포트폴리오 관리</a>에서 이력 확인).</p>';
    }

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

    /* 자리를 나누는 규칙(2026-08-02): <b>종목명 칸 = 그 종목의 사실</b>(SUE 어닝서프라이즈·어닝쇼크) ·
     * <b>나이 칸 = 그 포지션의 사정</b>(포트폴리오 경보 — 장기물림·N차 지연·계단관통↓). */
    $stairAl = pf_stair_alert_map($rows);   // M3 — 포지션 기준(기준 박스가 포지션마다 다르다)
    $sueBadge = pf_sue_badge_map(array_column($rows, 'stock_code'));

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
           . '</span>'
           . ($sueBadge[$p['stock_code']] ?? '')   // SUE 는 종목의 사실이라 종목명 옆에 붙는다
           . '</td>';

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
        echo pf_age_cell($c, (string)$p['status'], (string)($stairAl[$pid] ?? ''));
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

/** 지금 걸린 거르기(fid·sig·closed)를 유지한 채 일부만 바꾼 보유종목 URL — 머리글 정렬 공용 (gift_cust_url 패턴) */
function pf_all_url(array $over = []): string
{
    $q = array_merge([
        'mode'   => 'all',
        'fid'    => (int)($_GET['fid'] ?? 0) ?: '',
        'sig'    => !empty($_GET['sig']) ? 1 : '',
        'closed' => !empty($_GET['closed']) ? 1 : '',
        'sort'   => (string)($_GET['sort'] ?? ''),
        'sd'     => !empty($_GET['sd']) ? 1 : '',
    ], $over);
    return '/stock/index.php?' . http_build_query(array_filter($q, fn($v) => (string)$v !== ''));
}

/**
 * 눌러서 정렬하는 표 머리글 — 처음 누르면 그 열의 기본 방향, 같은 열을 다시 누르면 역순(sd=1).
 * 화살표는 「지금 실제 방향」이다: ▲ 오름 · ▼ 내림 (기본방향 × sd).
 */
function pf_all_sort_th(string $label, string $key, string $sort, bool $sd, bool $defDesc): string
{
    $on   = ($sort === $key);
    $next = ['sort' => $key, 'sd' => ($on && !$sd) ? 1 : ''];
    $arr  = '';
    if ($on) {
        $arr = '<span class="th-arr">' . (($defDesc !== $sd) ? '▼' : '▲') . '</span>';
    }
    return '<a class="th-sort" href="' . pf_h(pf_all_url($next)) . '">' . pf_h($label) . $arr . '</a>';
}

/**
 * 보유종목 — 포트폴리오 묶음을 풀어 <b>모든 종목을 한 표에</b> 세운다.
 *
 * 「현황」이 "포트폴리오별로 얼마인가"에 답한다면 여기는 "내 종목 전부를 한 줄로 세워 견주면"에 답한다.
 * 그래서 기본 정렬이 신호순이고, 거리(매수까지·매도까지) 열이 여기에만 다 있다.
 *
 * ?closed=1 종료 포함(토글 링크)  ?sort=… ?sd=1 역순(머리글 클릭)
 * ?fid=N ?sig=1 은 주소 전용(UI 없음 — 들어오면 ✕ 칩으로 드러낸다)
 */
function pf_page_all(PDO $pdo, Pf $pf): void
{
    $showClosed = !empty($_GET['closed']);
    $onlySig    = !empty($_GET['sig']);
    $fid        = (int)($_GET['fid'] ?? 0);
    $sort       = (string)($_GET['sort'] ?? 'sig');
    $sd         = !empty($_GET['sd']);   // 역순 — 머리글을 다시 눌렀을 때

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

    /* 열 정의 = 머리글·정렬의 <b>단일본</b>. [라벨, 셀클래스, 정렬키, 값 추출기, 기본이 내림차순?]
     * 정렬키 null = 정렬 없는 열(시장 — 배지 묶음이라 한 줄로 세울 값이 없다).
     * 'sig' 는 pf_signal_list() 가 이미 만들어 둔 순서라 추출기가 없다(역순만 뒤집는다). */
    $cols = [
        ['종목명',     '',    'name',    fn($g) => (string)$g['p']['stock_name'],                 false],
        ['포트폴리오', '',    'folio',   fn($g) => [$g['fname'], (string)$g['p']['stock_name']],  false],
        ['차수',       'num', 'step',    fn($g) => $g['c'] ? (int)$g['c']['cur_step'] : null,     true],
        ['나이',       'num', 'age',     fn($g) => $g['c']['cycle_age'] ?? null,                  true],
        ['신호',       '',    'sig',     null,                                                    true],
        ['시장',       '',    null,      null,                                                    true],
        ['현재가',     'num', 'last',    fn($g) => $g['last'],                                    true],
        ['수익률',     'num', 'rate',    fn($g) => $g['c']['rate'] ?? null,                       true],
        ['보유수량',   'num', 'qty',     fn($g) => $g['c'] ? (float)$g['c']['filled_qty'] : null, true],
        ['평가금액',   'num', 'eval',    fn($g) => $g['c']['eval_amount'] ?? null,                true],
        ['평가손익',   'num', 'pl',      fn($g) => $g['c']['eval_pl'] ?? null,                    true],
        ['다음매수가', 'num', 'next',    fn($g) => $g['c']['next_price'] ?? null,                 true],
        ['매수까지',   'num', 'buygap',  fn($g) => $g['s']['buy_gap'] ?? null,                    false],
        ['자동매도가', 'num', 'sell',    fn($g) => $g['c']['sell_price'] ?? null,                 true],
        ['매도까지',   'num', 'sellgap', fn($g) => $g['s']['sell_gap'] ?? null,                   false],
        ['누적단가',   'num', 'avg',     fn($g) => $g['c']['avg_cost'] ?? null,                   true],
    ];
    /* 정렬 — 머리글 클릭(sort=키) · 같은 머리글 다시 클릭(sd=1 역순).
     * 값 없는 행(null)은 방향과 무관하게 <b>맨 뒤</b> — 시세 미수집 종목이 위에 오면 안 된다. */
    $sorters = ['gap' => [fn($g) => $g['s']['gap'] ?? null, false]];   // 주소 전용 「임박한 순」(옛 북마크용)
    foreach ($cols as [, , $key, $fn, $defDesc]) {
        if ($key !== null && $fn !== null) $sorters[$key] = [$fn, $defDesc];
    }
    if ($sort === 'sig') {
        if ($sd) $rows = array_reverse($rows);
    } elseif (isset($sorters[$sort])) {
        [$fn, $defDesc] = $sorters[$sort];
        /* ★ `$desc = $defDesc xor $sd` 는 함정 — xor 가 = 보다 약해 `($desc = $defDesc) xor $sd` 로 파싱된다 */
        $desc = ($defDesc !== $sd);
        usort($rows, function (array $a, array $b) use ($fn, $desc) {
            $va = $fn($a);
            $vb = $fn($b);
            if ($va === null || $vb === null) return ($va === null) <=> ($vb === null);
            return $desc ? ($vb <=> $va) : ($va <=> $vb);
        });
    }

    pf_head('보유종목', 'dashboard', 'wide');
    pf_subtabs('all', 'dashboard');
    pf_flash();

    echo '<div class="pf-head"><div>';
    echo '<h1>보유종목</h1>';
    echo '<div class="sub">담긴 종목 ' . $total . '개 중 지금 신호가 있는 것은 <b>' . $nSig . '건</b>입니다. '
       . '「매수까지·매도까지」는 그 가격에 닿기까지 남은 거리입니다.</div>';
    echo '</div><div class="act">';
    /* ⊖ 거르기·정렬 폼(2026-08-15 삭제 — 사용자 지시). 정렬 셀렉트는 머리글 클릭과 전부 겹쳤고,
     * 포트폴리오 필터는 「포트폴리오」 머리글 정렬(묶어 보기)·현황 상세가 대신하며,
     * 「신호 있는 것만」은 기본 신호순 정렬이 이미 위로 올린다. 옛 키(fid·sig·sort=gap …)는
     * 주소로 들어오면 여전히 동작한다 — 남은 UI 는 「종료 포함」 토글 링크 하나뿐이다. */
    echo '<a class="btn btn-outline" href="' . pf_h(pf_all_url(['closed' => $showClosed ? '' : 1])) . '">'
       . ($showClosed ? '종료 감추기' : '종료 포함') . '</a> ';
    echo '<a class="btn btn-outline" href="/stock/index.php">현황으로</a>';
    echo '</div></div>';

    /* 주소로 들어온 거르기(fid·sig)는 ✕ 칩으로 드러낸다 — 칩 없이 파라미터만 남기면
     * 옛 북마크가 「보이지 않는 필터」에 갇힌다 (패턴분석 화면과 같은 규칙). */
    if ($fid > 0 || $onlySig) {
        echo '<p class="muted" style="margin:0 0 10px">거르는 중: ';
        if ($fid > 0) {
            $fname = '';
            foreach ($folios as $f) { if ((int)$f['id'] === $fid) { $fname = (string)$f['name']; break; } }
            echo '<a class="btn btn-outline" href="' . pf_h(pf_all_url(['fid' => ''])) . '">포트폴리오 '
               . pf_h($fname !== '' ? $fname : '#' . $fid) . ' ✕</a> ';
        }
        if ($onlySig) {
            echo '<a class="btn btn-outline" href="' . pf_h(pf_all_url(['sig' => ''])) . '">신호 있는 것만 ✕</a>';
        }
        echo '</p>';
    }

    if (!$rows) {
        echo '<div class="card"><p class="muted">'
           . ($onlySig ? '지금 신호가 있는 종목이 없습니다.' : '담긴 종목이 없습니다.') . '</p></div>';
        pf_foot();
        return;
    }

    echo '<div class="tbl-scroll"><table class="pf pos"><thead><tr>';
    foreach ($cols as [$label, $cls, $key, , $defDesc]) {
        echo '<th class="' . $cls . '">'
           . ($key !== null ? pf_all_sort_th($label, $key, $sort, $sd, $defDesc) : pf_h($label))
           . '</th>';
    }
    echo '</tr></thead><tbody>';

    $sumEval = $sumPl = 0.0;
    // 종목명 칸 = 그 종목의 사실(SUE) · 나이 칸 = 그 포지션의 사정(포트폴리오 경보) — 현황 표와 같은 규칙
    $stairAl  = pf_stair_alert_map(array_map(fn($g) => $g['p'], $rows));   // M3 — 포지션 기준
    $sueBadge = pf_sue_badge_map(array_map(fn($g) => (string)$g['p']['stock_code'], $rows));
    foreach ($rows as $g) {
        $p = $g['p'];
        $c = $g['c'];
        $s = $g['s'];
        $sumEval += (float)($c['eval_amount'] ?? 0);
        $sumPl   += (float)($c['eval_pl'] ?? 0);

        echo '<tr class="' . ($p['status'] !== 'open' ? 'watch' : '') . '">';
        echo '<td class="stk"><a href="/stock/index.php?mode=position&id=' . $g['pid'] . '">'
           . pf_h($p['stock_name']) . '</a><span class="code">' . pf_h($p['stock_code'])
           . ($p['status'] !== 'open' ? ' ' . pf_status_badge($p['status']) : '') . '</span>'
           . ($sueBadge[$p['stock_code']] ?? '') . '</td>';
        echo '<td class="muted"><a href="/stock/index.php?id=' . $g['fid'] . '">' . pf_h($g['fname']) . '</a></td>';

        if (!$c) {
            echo '<td colspan="15" class="muted">룰셋에 차수가 없습니다 — '
               . '<a href="/stock/index.php?mode=ruleset">룰셋 설정</a></td></tr>';
            continue;
        }

        echo '<td class="num">' . ((int)$c['cur_step'] > 0 ? $c['cur_step'] . '차' : '#') . '</td>';
        echo pf_age_cell($c, (string)$p['status'], (string)($stairAl[(int)$g['pid']] ?? ''));

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

        /* ⊖ 유동성 열(2026-08-02 삭제) · 실행 게이트(같은 날 삭제) — 이 자리에 있던 둘 다 없다.
         *   유동성은 이제 「★ 거래량 N배」의 ★ 로만 드러난다. */

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
    echo '<td>소계 ' . count($rows) . '종목</td><td colspan="8"></td>';   // 유동성 열 삭제로 9→8
    echo '<td class="num">' . pf_n(round($sumEval)) . '</td>';
    echo '<td class="num">' . pf_signed($sumPl) . '</td>';
    echo '<td colspan="5"></td>';
    echo '</tr></tfoot></table></div>';

    pf_daily_note($pf);
    pf_render_badge_help();
    pf_foot();
}

/**
 * ★★ 종목 상세의 <b>한 줄 상태판</b> — 층을 순서대로 세운다.
 *
 *   시장 · SUE · <b>편입 당시</b> · 행동 · 포트폴리오
 *
 * ★ 왜 이렇게 바뀌었나: 예전엔 「전략 판단」 한 상자에 세 층이 섞여 있었고(용어에 없는 이름),
 *   목록에는 뜨는 어닝쇼크⚠·퀀트신호·박스가 상세에는 아예 없어서 화면끼리 말이 달랐다.
 * ★ <b>판정을 여기서 새로 만들지 않는다</b> — 전부 목록·관심종목·퀀트가 쓰는 그 함수를 부른다.
 *   (pf_market_signals · KrxAmt::momNow · pf_sue_stock ·
 *    pf_signal_confidence · pf_cycle_alert · pf_stair_alert_map · pf_sue_badge_map)
 *   그래서 두 화면이 구조적으로 어긋날 수 없다.
 * ★ 빈 층은 '-' 로 남긴다 — 자리를 지워 버리면 「없음」과 「이 화면엔 원래 안 나옴」이 구별되지 않는다.
 *
 * ⊖ <b>M4 (v0.3 §4.2)</b>: 「퀀트 : 최고 거래대금」 칸(2-a 신호 · 2-b 경로)을 제거했다 — 여기는
 *   <b>이미 산 종목</b>을 보는 자리라 오늘의 퀀트 판정이 있을 곳이 아니다(D8). 그 자리는
 *   「편입 당시」(pf_entry_snapshot · 다시 계산하지 않는 기록)가 대신한다.
 * ⊖ 「③ 실행」 층은 2026-08-02 사용자 지시로 삭제.
 */
function pf_render_status_row(array $pos, ?array $c, array $tradeRow, ?array $me, ?array $snap = null): void
{
    $code   = (string)($pos['stock_code'] ?? '');
    $closed = (($pos['status'] ?? '') === 'closed');
    $none   = '<span class="sr-none" title="해당 없음">-</span>';
    $g      = [];   // [라벨, 내용HTML]

    /* ⊖⊖ <b>V1 제거 (M4 · v0.3 §4.2) — 「퀀트 : 최고 거래대금」 칸(2-a 신호 + 2-b 경로)을 걷어냈다.</b>
     *
     * 여기 있던 것은 <b>오늘의 퀀트 판정</b>이었다(`SELECT … ORDER BY d DESC LIMIT 1` = 최신 신호).
     * 그런데 이 화면은 <b>내가 이미 산 종목</b>을 보는 자리다 — 오늘 새로 뜬 신호는 편입 판단과 무관하고,
     * 실측(2026-08-03)으로 보유 12건 중 8건이 편입일보다 3~9개월 <b>뒤</b>의 박스를 보고 있었다.
     * 「지금 이게 살 자리인가」는 퀀트 섹션(관심종목·퀀트 목록)이 답한다 — 그 경계가 D8 이다.
     *
     * 대신 아래 ② 자리에 <b>「편입 당시」</b>(pf_entry_snapshot)를 놓는다 — 회고용 기록이고
     * 다시 계산하지 않는다. 살아 있는 판정은 ④ 포트폴리오 층의 「계단관통↓」 하나뿐이며,
     * 그것도 M3 부터 <b>편입 시 못박은 기준 박스</b>로만 판정한다(§2.2 유일한 예외).
     * ★momNow(20·40일 모멘텀)는 남는다 — 오늘 기준으로 계산한 <b>가격</b> 지표라 ① 시장 층(공통)이다. */

    /* ── ① 시장 — 하루치 지표(최대 3개) + 20·40일 모멘텀.
     * ★ 모멘텀은 <b>momNow(오늘 기준)</b>다. 예전엔 momMany(신호일 기준)를 썼는데, 신호가 반년 전이면
     *   반년 전 값이 「지금」인 척했다 — 현대차 신호일 +90.3% vs 오늘 −19.5%(2026-08-02 실측). */
    $mom = '';
    try {
        $mmNow = (new KrxAmt($GLOBALS['pdo']))->momNow([$code])[$code] ?? null;
        $mom   = pf_mom_chips($mmNow, (string)($mmNow['d'] ?? ''), true);
    } catch (Throwable $e) { /* 원장 없으면 모멘텀 칩 없이 */ }
    $mkt = (!empty($me['mkt']) ? pf_mkt_badges($me['mkt']) : '') . $mom;
    $g[] = ['시장', $mkt !== '' ? $mkt : $none];

    // ── ① 실적(SUE · 최신 분기) — 어닝 탭과 같은 계산 ──────────────────
    $sue = $none;
    try {
        /* ±1 밖이면 배지 하나로 끝난다 — 배지가 <b>이름·값·분기</b>를 다 담으므로 숫자를 따로 쓰지 않는다.
         * 판정·문구는 목록(종목명 옆)과 같은 함수를 쓴다(pf_sue_badge_map) — 두 화면이 어긋날 수 없다.
         * ★ 어닝쇼크는 <b>경보가 아니라 SUE 층</b>이다: 포트폴리오 경보는 「내가 산 포지션」의 사정인데
         *   SUE 는 그 종목 자체의 사실이라 자리가 다르다. */
        $sb = pf_sue_badge_map([$code]);
        if (isset($sb[$code])) {
            $sue = $sb[$code];
        } else {
            // ±1 안쪽 — 배지를 만들지 않으므로 값만 옅게 보여 준다(계산이 됐다는 사실 자체가 정보다)
            $sq = pf_sue_stock($GLOBALS['pdo'], $code);
            if ($sq) {
                $qk = array_key_last($sq); $v = (float)$sq[$qk];
                $y  = intdiv($qk - 1, 4); $qn = $qk - $y * 4;
                $lbl = ($y % 100) . '.' . $qn . 'Q';
                /* ★ sprintf 의 형식문자열에 「상위 20%」 같은 % 를 넣지 않는다 — PHP8 이 ValueError 를
                 * 던지고 이 try 가 그걸 삼켜 칸이 조용히 '-' 로 비었다(2026-08-02 실측으로 잡음). */
                $sue = '<b title="' . pf_h('최신 분기(' . $lbl . ') 이익 서프라이즈 ' . number_format($v, 2)
                     . ' — 1 이상이면 어닝서프라이즈 · −1 이하면 어닝쇼크. 그 사이는 배지를 만들지 않습니다.')
                     . '">' . sprintf('%+.1f', $v) . '</b> <span class="muted" style="font-size:11px">'
                     . $lbl . '</span>';
            }
        }
    } catch (Throwable $e) { /* 재무 없음 */ }
    $g[] = ['SUE', $sue];

    /* ── ② 편입 당시 (M4) — <b>오늘의 판정이 아니라 그때의 기록</b>이다.
     *   편입 순간에 pf_entry_snapshot 으로 한 번 찍어 둔 값을 그대로 읽는다(다시 계산하지 않는다).
     * ★ 옛 「퀀트 : 최고 거래대금」 칸을 대신하는 자리다. 라벨을 「편입 당시」로 바꾼 이유는
     *   같은 모양의 배지가 <b>살아 있는 판정</b>으로 읽히면 안 되기 때문이다 — 그래서 무채색으로 그리고
     *   신호일·편입일을 함께 적는다.
     * ★ M4 이전 편입은 스냅샷이 없다(과거분 백필 안 함 · v0.3 §9-3) — 그 사실을 그대로 밝힌다. */
    if ($snap) {
        $lbl  = pf_entry_signal_label($snap['quant_signal'] ?? null);
        $bits = [];
        if ($lbl !== '') {
            $fl = pf_entry_flame_label($snap['flame_reason'] ?? null);
            $bits[] = '<span class="sr-sub">신호</span><span class="ent-b" title="'
                . pf_h('편입 시점(' . $snap['snapshot_at'] . ') 판정 — 기준 신호일 '
                    . ($snap['signal_date'] ?: '없음') . ($fl !== '' ? ' · ' . $fl : '')
                    . '. 오늘의 판정이 아니라 그때의 기록입니다.')
                . '">' . pf_h($lbl) . '</span>';
        }
        if ((string)($snap['box_path'] ?? '') !== '') {
            /* 계단 레벨을 값으로 들고 있으므로 <b>어느 자리였는지</b>까지 말할 수 있다 —
             * 개수만 있던 때는 「3개」까지가 전부였다. */
            $lvTxt = '';
            if ((string)($snap['box_levels'] ?? '') !== '') {
                $lvTxt = ' · 계단 ' . implode('/', array_map(
                    static fn($v) => number_format((float)$v), explode(',', (string)$snap['box_levels'])));
            }
            $bits[] = '<span class="sr-sub">경로</span><span class="ent-b" title="'
                . pf_h('편입 시점의 박스 상태'
                    . ($snap['floors_count'] !== null ? ' · 아래층 박스 ' . (int)$snap['floors_count'] . '개' : '')
                    . $lvTxt)
                . '">' . pf_h((string)$snap['box_path']) . '</span>';
        }
        if ($snap['ret_20d'] !== null) {
            $bits[] = '<span class="sr-sub">신호일 20일</span><span class="ent-b">'
                . sprintf('%+.1f%%', (float)$snap['ret_20d'] * 100) . '</span>';
        }
        if ($snap['sue_latest'] !== null) {
            $bits[] = '<span class="sr-sub">SUE</span><span class="ent-b">'
                . sprintf('%+.1f', (float)$snap['sue_latest']) . '</span>';
        }
        $g[] = ['편입 당시', $bits ? implode('', $bits)
            : '<span class="sr-none" title="편입 시점에 남길 판정 재료가 없었습니다(기준 박스·재무 없음)">기록 없음</span>', 'box'];
    } else {
        $g[] = ['편입 당시', '<span class="sr-none" title="'
            . pf_h('이 포지션은 편입 기록(스냅샷)을 남기기 전에 담았습니다 — 과거분은 되짚어 채우지 않습니다. '
                 . '되짚어 만든 값은 「그때 그랬다」가 아니라 「지금 보니 이렇다」가 되기 때문입니다.')
            . '">기록 이전</span>', 'box'];
    }

    // ── ③ 행동 — 오늘의 신호 + 신뢰도 (현황 카드와 같은 판정) ───────────
    $act = $none;
    if (!$closed && $me !== null) {
        $k    = $me['s']['kind'] ?? null;
        $conf = $me['conf'] ?? ['level' => 'normal', 'label' => '', 'why' => ''];
        $act  = '<span class="sig-pill k-' . ($k ?? 'wait') . '">' . pf_h(pf_sig_label($k)) . '</span>';
        if (($conf['label'] ?? '') !== '') {
            $act .= '<span class="sig-conf lv-' . pf_h($conf['level']) . '" title="' . pf_h($conf['why']) . '">'
                  . ($conf['level'] === 'strong' ? '◎ ' : '△ ') . pf_h($conf['label']) . '</span>';
        }
    }
    $g[] = ['행동', $act];

    /* ⊖ 「③ 실행」 칸은 2026-08-02 사용자 지시로 삭제 — 층 자체가 없어졌다(상태·행동·포트폴리오 3층). */

    /* ── ③ 포트폴리오 — <b>내가 산 포지션</b>에서만 성립하는 셋 (2026-08-02 사용자 정의).
     *   장기물림(2년 or 5차) · N차 지연(룰셋 만료) · 계단관통↓(지지구조 소멸).
     *   ★ 어닝쇼크는 여기가 아니라 SUE 층이다 — 그건 포지션이 아니라 종목의 사실이다.
     *   ★ 판정은 목록(보유종목·현황)과 같은 함수를 그대로 부른다. */
    $al = [];
    if (!$closed) {   // 끝난 사이클엔 다시 판단할 계획이 없다 — 목록과 같은 규칙
        $age = pf_cycle_age($tradeRow);   // 이 화면은 pf_load_calc 을 안 거쳐 나이를 직접 잰다
        $cy  = pf_cycle_alert($age['days'], (int)($c['cur_step'] ?? 0));
        if ($cy['level'] !== 'none') {
            $al[] = '<span class="mkt t-risk" title="' . pf_h($cy['why']) . '">' . pf_h($cy['label']) . '</span>';
        }
        $dly = pf_delay_badge($c);                      // 목록의 다음매수가 칸과 같은 판정
        if ($dly !== '') $al[] = str_replace('<br>', '', $dly);
        /* 계단관통↓ — M3 부터 <b>이 포지션의 기준 박스</b>로만 판정한다(목록과 같은 함수·같은 인자). */
        $st = pf_stair_alert_map([$pos]);
        if (isset($st[(int)$pos['id']])) $al[] = $st[(int)$pos['id']];

        /* ★기준 박스가 없으면 그 사실을 <b>밝힌다</b> (v0.3 §2.2) — 안 밝히면 「이상 없음」과
         *   「판정할 기준이 없음」이 같은 빈 칸으로 보인다. 목록은 훑는 화면이라 배지를 늘리지 않고
         *   여기(상세)에서만 말한다 — 한 번 클릭이면 닿는 자리다. */
        if ((string)($pos['surge_event_d'] ?? '') === '') {
            $al[] = '<span class="mkt" title="' . pf_h(
                '이 포지션에는 기준으로 삼을 최고 거래대금 박스가 없습니다 — 편입 시점 전후 '
                . Pf::SURGE_BACKFILL_MAX_DAYS . '일 안에 확정 신호가 없었거나, 퀀트 도입 이전에 담은 종목입니다. '
                . '그래서 「계단관통↓」은 이 포지션에서 판정하지 않습니다(경보 없음 ≠ 이상 없음).')
                . '">기준 박스 없음</span>';
        }
    }
    $g[] = ['포트폴리오', $al ? implode('', $al)
        : '<span class="sr-none" title="장기물림·N차 지연·계단관통↓ 어느 것도 걸리지 않았습니다">없음</span>'];

    echo '<div class="status-row">';
    foreach ($g as $row) {
        [$label, $html] = $row;
        if (($row[2] ?? '') === 'box') {
            // 상자형 — 좌측 진한 라벨이 갈래(신호·경로)를 통째로 감싼다
            echo '<span class="sr-g sr-box"><span class="sr-bk">' . pf_h($label) . '</span>'
               . '<span class="sr-bb">' . $html . '</span></span>';
        } else {
            echo '<span class="sr-g"><span class="sr-k">' . pf_h($label) . '</span>' . $html . '</span>';
        }
    }
    echo '<a class="sr-help" href="/stock/index.php?mode=signal" title="신호분석 — 이 배지들의 판정 기준">기준 ?</a>';
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

<tr><td><span class="mkt t-risk">장기물림</span></td>
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

<tr><td><span class="sig-pill k-re">재진입</span></td>
  <td>전량 매도한 종목이<br><b>대기일 경과</b> + <b>현재가 ≤ 청산가 × (1 − 하락요건)</b><br>
      <span class="muted">하락요건 기본값 = 그 종목 룰셋의 <b>2차 하락률</b></span></td>
  <td>이 엔진의 수익은 <b>사이클 반복</b>에서 납니다 — 닫힌 사이클 207개의 수익 중앙이 +20.1%(보유 67일)입니다.
      그런데 전량 매도한 종목은 목록에서 사라져, 되살 때가 와도 아무도 다시 보지 않았습니다.<br>
      시뮬레이터는 대기일만 지나면 곧바로 1차를 담지만(진입에 하락 조건이 없음), 실전에서 그대로 하면
      <b>팔았던 값보다 비싸게 되사게</b> 됩니다. 그래서 하락요건을 하나 더 겁니다.</td>
  <td><b>자동으로 사지 않습니다.</b> 재진입은 새 사이클이라 지금 구조에서는 <b>그 종목을 다시 추가</b>해 시작합니다.<br>
      → 카드의 「1차 소요」는 <b>계획 매수 신호가 먼저 가져간 뒤 남은 예수금</b>과 견준 값입니다.
      전체 목록(대기 중·아직 비쌈 포함)과 대기일·하락요건 조정은 <b>매매히스토리</b>에 있습니다.</td></tr>

<tr><td><span class="sue-b up">SUE +2.1<span class="sue-q">26.1Q</span></span><br><span class="sue-b dn">SUE -1.4<span class="sue-q">26.1Q</span></span></td>
  <td>최신 분기 이익 서프라이즈<br><b>어닝서프라이즈 ≥ +1</b>(빨강) / <b>어닝쇼크 ≤ −1</b>(파랑)<br>
      <span class="muted">종목명 옆에 붙습니다 — 포지션이 아니라 <b>종목의 사실</b>이라서</span></td>
  <td>8년·6만 이벤트 백테스트에서 <b>서프라이즈(≥1) 무리는 공시 후 두 달 상방</b>,
      <b>쇼크(≤−1) 무리는 거의 매년 음수·두 달 하방</b> 드리프트가 실측됐습니다(어닝 탭과 같은 계산).</td>
  <td><b>자동으로 사거나 팔지 않습니다.</b> 계단관통↓(수급 구조)과 어닝쇼크(실적 반증)가 겹치면
      손절을 판단하는 자리입니다 — 고정%·시간·장대음봉 손절은 전부 백테스트에서 기각됐습니다.<br>
      → 종목상세의 「재무상세」 버튼으로 공시 내용과 SUE 마커 차트를 확인하세요.</td></tr>

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
      → 배수는 <b>주수로 재도 됩니다</b> — 같은 종목의 자기 비교라 단위가 상쇄됩니다.</td></tr>

<tr><td><span class="mkt t-up">+16.2%</span><span class="mkt t-down">−15.4%</span><br><span class="muted">하루 · 등락색</span></td>
  <td>|일간 등락| ≥ <b>3σ</b>(최근 20일 표준편차)<br><b>또는</b> ≥ <b>15%</b></td>
  <td>고정 % 기준은 삼성전자와 코스닥 소형주에 같은 뜻이 아니라 <b>평소 변동성으로 정규화</b>(σ)하되,
      최근 20일이 출렁이면 σ 가 커져 큰 움직임도 작아 보이므로(변동성 클러스터링) <b>절대 15% 와 OR</b> 로 묶습니다.<br>
      임계를 3σ·15%로 높게 둔 이유 — 웬만한 등락에는 침묵하고 <b>진짜 이례만</b> 띄우기 위해서입니다.
      배지가 흔하면 표시가 아닙니다.</td>
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
    echo '<b>시장</b> 배지는 그 종목의 <b>평소와 견준</b> 값입니다 — 등락은 3σ(최근 20일 표준편차) 또는 절대 15%, '
       . '거래량은 20일 평균 대비 배수입니다. 임계를 높게 둬 진짜 이례만 띄웁니다.<br>';
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
    /* 같은 사실을 <b>돈으로</b> 재는 표 — 차수마다 실린 비중이 달라 % 표와 결론이 갈릴 수 있다 */
    pf_render_step_score($rows);

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
        $hm = pf_hm($r['traded_time'] ?? null);
        echo '<td class="stk"><b>' . pf_h($r['traded_at']) . ($hm !== '' ? ' ' . pf_h($hm) : '') . '</b>'
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

/**
 * 차수별 매매 품질 — <b>"어느 차수에서 돈이 새는가"</b>.
 *
 * 위 표(추가하락)가 「담은 뒤 얼마나 더 빠졌나」를 %로 재는 자리라면, 여기는 <b>같은 사실을 돈으로</b> 잰다.
 * 차수마다 실린 비중이 다르기 때문에 둘은 같은 표가 아니다 — 1차 비중 5% 에서 −30% 인 것과
 * 6차 비중 23% 에서 −10% 인 것은 % 로는 1차가 나빠 보이지만 잃은 돈은 6차가 크다.
 *
 * ★★ <b>이 표로 룰셋을 고치지 않는다.</b> 룰셋 재설계는 두 차례 백테스트(노출 교란 · 노출 중립 2요인 분해)에서
 *   모두 근거가 서지 않아 기각됐다(비중 = 위험 다이얼 / 간격 = 자본효율↔낙폭 트레이드오프).
 *   이 표가 답하는 것은 「지금 내 돈이 어느 차수에 얼마나 물려 있나」이지 「사다리를 어떻게 바꿀까」가 아니다.
 * ★ 적중률은 <b>현재가 한 점</b> 기준이라 오늘 시세에 따라 흔들린다 — 그래서 금액영향을 먼저 놓는다.
 * ★ 표본 5건 미만은 판정하지 않는다(회색). 6·7차는 실전 체결이 0 이라 행 자체가 없다 —
 *   비중 합계의 절반이 사실상 쓰인 적이 없다는 뜻이고, 그건 룰셋이 아니라 한도 관리의 문제다.
 */
function pf_render_step_score(array $rows): void
{
    $agg = pf_step_score($rows);
    if (!$agg) return;

    $tot = 0.0;
    /* 「영향 비중」의 분모 — 부호가 섞이면 뜻이 없으므로 <b>손실 쪽만</b> 모은다.
     * 이 열의 목적은 「가장 크게 새는 차수 찾기」다. */
    $lossTot = 0.0;
    foreach ($agg as $a) {
        $tot += (float)$a['impact'];
        if ($a['impact'] < 0) $lossTot += -(float)$a['impact'];
    }

    $pct1 = fn(?float $v) => ($v === null) ? '<span class="flat">-</span>'
                                          : pf_h(sprintf('%+.1f%%', $v * 100));

    echo '<div class="card"><h2>차수별 매매 품질 — 어느 차수에서 돈이 새나</h2>';
    echo '<p class="sub" style="margin:0 0 10px">'
       . '매수 전체를 한 덩이로 세면(위 스코어카드) <b>1차의 판단과 5차의 판단이 평균 하나로 뭉개집니다</b> — '
       . '1차는 「이 종목을 시작할까」이고 5차는 「여기서 더 담을까」라 서로 다른 결정입니다.</p>';

    echo '<div class="tbl-scroll"><table class="pf"><thead><tr>';
    foreach ([
        ['차수', 'num'], ['매수', 'num'], ['적중률', 'num'], ['중앙 우위', 'num'], ['평균 우위', 'num'],
        ['체결금액', 'num'], ['금액영향', 'num'], ['영향 비중', 'num'], ['판정', ''],
    ] as [$label, $cls]) {
        echo '<th class="' . $cls . '">' . pf_h($label) . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($agg as $n => $a) {
        echo '<tr>';
        echo '<td class="num"><b>' . $n . '차</b></td>';
        echo '<td class="num">' . $a['n'];
        if ($a['no_edge'] > 0) {
            echo ' <span class="flat" title="' . pf_h('시세가 없어 되짚을 수 없는 ' . $a['no_edge']
               . '건은 뺐습니다 — 0 으로 두면 「완벽한 판단」으로 읽혀 집계가 오염됩니다')
               . '">(−' . (int)$a['no_edge'] . ')</span>';
        }
        echo '</td>';

        /* 적중률에는 등락색을 쓰지 않는다 — 「올랐나」가 아니라 「내 편이었나」다(MFE/MAE 열과 같은 규칙).
         * 대신 50% 를 기준으로 굵기만 준다. */
        echo '<td class="num gap">' . ($a['rate'] === null ? '-'
            : '<b>' . number_format($a['rate'] * 100, 1) . '%</b>'
              . ' <span class="muted" style="font-size:11px">' . $a['hit'] . '/' . $a['n'] . '</span>') . '</td>';
        echo '<td class="num gap"><b>' . $pct1($a['edge_med']) . '</b></td>';
        echo '<td class="num gap">' . $pct1($a['edge_avg']) . '</td>';
        echo '<td class="num muted">' . pf_n(round($a['amount'])) . '</td>';
        echo '<td class="num">' . pf_signed(round($a['impact'])) . '</td>';
        // 영향 비중 — 손실 총액에서 이 차수가 차지하는 몫 (분모는 위에서 한 번만 모았다)
        echo '<td class="num muted">' . (($a['impact'] < 0 && $lossTot > 0)
            ? number_format(-$a['impact'] / $lossTot * 100, 0) . '%' : '-') . '</td>';

        if ($a['thin']) {
            echo '<td><span class="badge st-watch" title="'
               . pf_h('표본 ' . $a['n'] . '건뿐입니다 — ' . PF_EXC_MIN_N . '건 이상이어야 판정합니다. '
                    . '"괜찮다"가 아니라 아직 모른다는 뜻입니다') . '">표본 부족</span></td>';
        } elseif ($a['impact'] < 0) {
            echo '<td><span class="vd vd-bad" title="'
               . pf_h('이 차수에서 담은 뒤 평가액이 ' . pf_n(round(-$a['impact'])) . '원 줄었습니다')
               . '">이르게 담았음</span></td>';
        } else {
            echo '<td><span class="vd vd-good">잘 담았음</span></td>';
        }
        echo '</tr>';
    }
    echo '</tbody><tfoot><tr><td>합계</td><td class="num"></td><td class="num"></td><td class="num"></td>'
       . '<td class="num"></td><td class="num"></td><td class="num">' . pf_signed(round($tot)) . '</td>'
       . '<td class="num"></td><td></td></tr></tfoot></table></div>';

    echo '<p class="sub muted" style="margin:9px 0 0;font-size:12px">'
       . '<b>우위</b>는 체결가 대비 현재가입니다(＋면 담은 뒤 올랐음). <b>금액영향</b> = 우위 × 체결금액 = '
       . '<b>그 차수가 만든 평가증감</b>입니다 — 적중률이 같아도 실린 돈이 다르면 금액은 크게 갈립니다.<br>'
       . '★ 적중률·우위는 <b>현재가 한 점</b>과 견준 값이라 오늘 시세에 따라 흔들립니다. '
       . '「담은 뒤 반토막까지 갔다 회복」한 건은 여기서 무승부로 보이므로, 지나간 위험은 <b>위 추가하락(MAE) 표</b>로 봅니다.<br>'
       . '★ <b>노출 기간 편향</b>은 여기도 그대로입니다 — 오래된 체결일수록 움직일 시간이 더 많았습니다. '
       . '차수가 높을수록 최근 체결이라 영향이 작게 보입니다.<br>'
       . '★ <b>이 표로 룰셋을 바꾸지 않습니다.</b> 사다리 재설계는 백테스트 두 차례에서 근거가 서지 않아 기각됐습니다 — '
       . '여기서 읽을 것은 「내 돈이 어느 차수에 얼마나 물려 있나」입니다.</p>';
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
 * 재진입 후보 <b>판정</b> — 청산(전량매도)한 종목을 되사도 될 때가 됐는지.
 *
 * 시뮬레이터의 `청산 후 재진입 + 대기(거래일)` 규칙이 실전 화면에는 없었다. 그래서 전량 매도한 종목은
 * 종료(closed)로 사라진 뒤 아무도 다시 보지 않았다. 여기서 되살린다.
 *
 * ★ 시뮬레이터는 대기일만 지나면 곧바로 1차를 담는다(진입에 하락 조건이 없다). 실전에서 그대로 하면
 *   <b>팔았던 값보다 비싸게 되사는</b> 일이 생긴다 — 그래서 「하락요건」을 하나 더 건다.
 *   기본값은 그 종목 룰셋의 <b>2차 하락률</b>이다: 계획상 한 차수 아래면 다시 시작할 만하다는 뜻.
 * ★ 경과일은 달력일, 시뮬레이터의 대기는 거래일이다 — 며칠 어긋난다. 화면에 그대로 밝힌다.
 *
 * ★★ <b>판정은 여기 하나뿐이다</b>(2026-08-04). 현황의 「오늘의 신호」와 매매히스토리가 같은 목록을 쓴다 —
 *    두 화면이 같은 종목을 두고 다른 말을 하면 어느 쪽도 못 믿는다(신호 스트립·상세와 같은 원칙).
 *
 * @return array 각 행 = closedPositions 행 + ['chk','req','last','days','need','fund_ok','fund_left']
 *   need = 재진입 1차 소요액(예수금 게이트용) · 못 세면 null
 */
function pf_reentry_list(Pf $pf, int $fid, int $wait, ?float $dropSet, DateTimeImmutable $today): array
{
    $closed = $pf->closedPositions($fid);
    if (!$closed) return [];

    $stepsMap = $pf->ruleStepsMap(array_column($closed, 'rule_set_id'));
    /* 박스 사다리 포지션은 차수 비중이 룰셋이 아니라 <b>포지션</b>에 있다(pf_position_level).
     * 룰셋 비중으로 세면 그 종목만 1차 소요가 딴 값이 된다 — 있으면 레벨을 먼저 본다. */
    $lvMap = $pf->positionLevelsMap(array_column($closed, 'id'));

    $list = [];
    foreach ($closed as $p) {
        $last  = ($p['last_price'] !== null) ? (float)$p['last_price'] : null;
        $steps = $stepsMap[(int)$p['rule_set_id']] ?? [];
        // 하락요건 기본값 = 룰셋 2차 하락률(음수로 저장돼 있다 → 절대값)
        $auto  = isset($steps[2]['drop_rate']) ? abs((float)$steps[2]['drop_rate']) : 0.0;
        $req   = $dropSet ?? $auto;
        $days  = (int)$today->diff(new DateTimeImmutable((string)$p['last_sell_at']))->days;

        /* 재진입 1차 소요 = 한도 × 1차 비중. 비중을 못 찾으면 null 이고 게이트는 판정을 보류한다
         * — 「모른다」를 0 으로 적으면 "돈이 남는다"는 거짓말이 된다. */
        $w1   = $lvMap[(int)$p['id']][1]['weight'] ?? $steps[1]['weight'] ?? null;
        $need = ($w1 !== null) ? pf_step_amount((float)$p['limit_amt'], (float)$w1) : null;

        $chk = pf_reentry_check($p['last_sell_price'], $last, $days, $wait, $req);
        $list[] = $p + ['chk' => $chk, 'req' => $req, 'last' => $last, 'days' => $days,
                        'need' => $need, 'fund_ok' => null, 'fund_left' => null];
    }
    // 후보(ready) 먼저, 그 안에서는 많이 빠진 순
    usort($list, function ($a, $b) {
        $ra = ($a['chk']['state'] === 'ready') ? 0 : 1;
        $rb = ($b['chk']['state'] === 'ready') ? 0 : 1;
        if ($ra !== $rb) return $ra <=> $rb;
        return ($a['chk']['gap'] ?? INF) <=> ($b['chk']['gap'] ?? INF);
    });
    return $list;
}

/**
 * 재진입 상태 칩 — <b>남은 관문을 전부</b> 말한다 (요건은 둘이다).
 *
 * ★★예전에는 state 하나만 보고 「대기 6일 남음」 <b>또는</b> 「아직 비쌈」 하나만 찍었다.
 *   그런데 대기 중이면 값 요건은 판정조차 되지 않아, 삼성전자처럼 <b>둘 다 한참 모자란 종목</b>이
 *   「대기만 지나면 되는」 것처럼 보였다(실측 −2.23% vs 요건 −9.0%).
 *   ⇒ 두 관문을 각각 찍고, 값 쪽은 <b>얼마나 더 내려야 하는지</b>까지 적는다.
 * ★판정·수치는 전부 pf_reentry_check 가 낸 것을 쓴다 — 화면에서 조건을 다시 계산하지 않는다.
 */
function pf_reentry_state_badges(array $ck): string
{
    if (($ck['state'] ?? '') === 'ready') {
        return '<span class="vd vd-good" title="대기·값 두 요건을 모두 갖췄습니다">재진입 검토</span>';
    }
    $h = '';
    if (empty($ck['days_ok'])) {
        $h .= '<span class="vd vd-wait" title="청산일로부터 대기일이 지나야 합니다">대기 '
            . (int)($ck['left'] ?? 0) . '일</span> ';
    }
    if (empty($ck['price_ok'])) {
        $need = $ck['need_gap'] ?? null;
        $h .= '<span class="vd vd-hold" title="현재가가 재진입가(청산가 × (1 − 하락요건)) 이하로 내려와야 합니다">'
            . ($need === null ? '시세 없음' : '값 ' . number_format(abs($need) * 100, 1) . '%↓')
            . '</span>';
    }
    return trim($h);
}

/** 매매히스토리의 재진입 검토 표 — 판정은 pf_reentry_list() 단일본. */
function pf_render_reentry(Pf $pf, int $fid, int $wait, ?float $dropSet, DateTimeImmutable $today): void
{
    $list = pf_reentry_list($pf, $fid, $wait, $dropSet, $today);
    if (!$list) return;

    $nReady = count(array_filter($list, fn($x) => $x['chk']['state'] === 'ready'));

    echo '<section class="sig-strip' . ($nReady ? '' : ' quiet') . '">';
    echo '<div class="sig-hd"><h2>재진입 검토</h2>';
    if ($nReady) echo '<span class="sig-pill k-buy">후보 ' . $nReady . '건</span>';
    echo '<span class="sig-none">전량 매도한 ' . count($list) . '종목 — 대기 ' . $wait . '일 경과 + 청산가보다 충분히 낮으면 후보</span>';
    echo '</div>';

    echo '<div class="tbl-scroll"><table class="pf"><thead><tr>';
    foreach ([['종목', ''], ['포트폴리오', ''], ['청산일', ''], ['경과', 'num'], ['청산가', 'num'],
              ['현재가', 'num'], ['청산가 대비', 'num'], ['하락요건', 'num'], ['재진입가', 'num'],
              ['상태', '']] as [$l, $cl]) {
        echo '<th class="' . $cl . '">' . pf_h($l) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($list as $x) {
        $ck = $x['chk'];
        echo '<tr>';
        echo '<td><a href="/stock/index.php?mode=position&id=' . (int)$x['id'] . '">' . pf_h($x['stock_name']) . '</a></td>';
        echo '<td class="muted">' . pf_h($x['portfolio_name']) . '</td>';
        echo '<td>' . pf_h($x['last_sell_at']) . '</td>';
        // 관문을 통과한 칸만 진하게 — 표를 훑을 때 「무엇이 남았나」가 색으로 먼저 보인다
        echo '<td class="num' . ($ck['days_ok'] ? '' : ' muted') . '">' . $x['days'] . '일</td>';
        echo '<td class="num">' . pf_n($x['last_sell_price'] === null ? null : round($x['last_sell_price'])) . '</td>';
        echo '<td class="num">' . pf_n($x['last']) . '</td>';
        echo '<td class="num">' . pf_signed_pct($ck['gap']) . '</td>';
        echo '<td class="num muted">−' . number_format($x['req'] * 100, 1) . '%</td>';
        // 재진입가 = 청산가 × (1 − 하락요건). 요건을 <b>가격으로</b> 적는다(다음매수가와 같은 결)
        echo '<td class="num' . ($ck['price_ok'] ? '' : ' muted') . '">'
           . pf_n($ck['need_price'] === null ? null : round($ck['need_price'])) . '</td>';
        echo '<td>' . pf_reentry_state_badges($ck) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<p class="sub muted" style="margin:9px 0 0;font-size:12px">'
       . '<b>요건은 둘이고 <u>둘 다</u> 갖춰야 후보입니다</b> — ① 청산일로부터 <b>' . (int)$wait . '일</b> 경과 '
       . '② 현재가 ≤ <b>재진입가</b>(＝청산가 × (1 − 하락요건)). '
       . '상태 칸에는 <b>아직 못 갖춘 것만</b> 뜹니다(「대기 6일」·「값 6.9%↓」).<br>'
       . '<b>하락요건</b>의 기본값은 그 종목 <b>룰셋의 2차 하락률</b>입니다 — 계획상 한 차수 아래면 다시 시작할 만하다는 뜻입니다. '
       . '위 입력칸으로 바꿀 수 있습니다.<br>'
       . '경과는 <b>달력일</b>이고 시뮬레이터의 대기는 <b>거래일</b>이라 며칠 어긋납니다. '
       . '재진입은 새 사이클이지만 <b>새 종목을 추가하는 것이 아닙니다</b> — 한 포트폴리오에 같은 종목은 하나뿐이라, '
       . '<b>그 포지션에 매수를 기록</b>하면 종료 상태가 자동으로 풀리고 사이클이 다시 시작됩니다 '
       . '(종목명을 눌러 들어가세요).<br>'
       . '<b>후보</b>는 <a href="/stock/index.php">현황</a>의 「오늘의 신호」에도 함께 뜹니다 — '
       . '같은 판정이고, 거기서는 남은 <b>예수금</b>까지 견줍니다(계획 매수 신호가 먼저 가져갑니다).</p>';
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
    $posLv    = $pf->positionLevels((int)$pos['id']);   // 퀀트 사다리 (있으면 룰셋 차수를 대체)
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
    // 보유 ↔ 재무의 다리 — 급락이 실적 탓인지 이 화면만으로는 모른다. 재무·SUE·공시 마커는 저쪽에 있다.
    echo '<a class="btn btn-outline" href="/stock/index.php?mode=fund&code=' . pf_h($pos['stock_code'])
       . '" title="11년 재무 · 분기 추이 · SUE 공시 마커 일봉차트">재무상세</a>';

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
        // 폴백 — 종료된 종목 등. 예수금 집계만 없고 지표·유동성은 같은 함수로 만든다
        $fInd = pf_indicators($myBars, $last);
        $fLiq = pf_liquidity($myBars);
        $me = [
            'ind' => $fInd, 'liq' => $fLiq,
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

    // 한 줄 상태판 — 층 일곱(시장·퀀트신호·박스·SUE·행동·실행·경보)을 순서대로
    pf_render_status_row($pos, $c, $tradeRow, $me, $pf->entrySnapshot((int)$pos['id']));

    echo '<div style="display:grid;grid-template-columns:1.35fr 1fr;gap:14px" class="pf-detail-grid">';

    // ── 차수 사다리
    // 매도가 있으면 차수별 수량·금액을 안분된 "보유" 기준으로 보여준다
    $hasSold = ((int)$c['sold_qty'] > 0);
    $qtyLabel = $hasSold ? '보유수량' : '매수수량';
    $amtLabel = $hasSold ? '보유원가' : '매수금액';

    echo '<div class="card"><h2>차수 사다리 '
       . ($posLv
            ? '<span class="mkt t-buyish" title="편입 시 확정한 이 종목의 실제 박스 지지선이 차수 가격입니다 — 룰셋 하락률 대신 이 표를 씁니다. 재조정은 「수정」 화면에서">퀀트 사다리 ' . count($posLv) . '차</span>'
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
    // 기능 구성 — 차트설정 > 「화면별 구성」 (원본 카탈로그 = ChartFeat)
    $FEAT = ChartFeat::vals('position', $pdo);
    $fLine = (bool)$FEAT['overlay.position_lines'];
    $fMark = (bool)$FEAT['overlay.trade_markers'];
    $fStep = (bool)$FEAT['overlay.step_lines'];   // 차수가격(이론) 선 — 기간 바 토글로 켠다(기본 감춤)
    echo '<div class="card"><div class="pf-head" style="margin-bottom:10px"><div>';
    echo '<h2 style="margin:0" id="pfDailyTitle">일봉 차트</h2>';
    echo '<div class="sub">'
       . ($fLine ? '누적단가·자동매도가·다음매수가를 가격선으로' : '')
       . ($fLine && $fMark ? ', ' : '')
       . ($fMark ? '체결 지점을 마커로' : '')
       . ($fLine || $fMark ? ' 표시합니다.' : '일봉과 사용자 지표만 그립니다 (가격선·체결 마커는 차트설정에서 껐습니다).')
       . '</div>';
    echo '</div><div class="act">';
    // 기간 바 [일봉|주봉 ┃ 160일 240일 480일 전체] + 사용자 지표 바 — DailyChart 공용 컴포넌트
    echo '<span id="pfPBar"></span> <span id="pfIBar"></span>';
    echo '</div></div>';
    // 가격선 범례 — 차트 위에 글자를 얹지 않고 여기서 설명한다 (지표 값도 여기에 붙는다)
    echo '<div class="chart-legend" id="pfLegend">';
    foreach ($fLine ? [
        ['#1e9e74', '누적단가',   $c['avg_cost']   === null ? null : round($c['avg_cost'])],
        ['#d32f2f', '자동매도가', $c['sell_price']],
        ['#1d5c93', '다음매수가', $c['next_price']],
    ] : [] as [$col, $lab, $val]) {
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
                          'step' => (int)$t['step_no'], 'qty' => 0.0, 'amt' => 0.0, 'n' => 0,
                          'hm' => []];
        }
        $q = (float)$t['qty'];
        $grp[$key]['qty'] += $q;
        $grp[$key]['amt'] += $q * (float)$t['price'];
        $grp[$key]['n']++;
        /* 체결 시각은 <b>묶어도 잃지 않는다</b> — 일봉은 하루 한 봉이라 마커 자리는 못 바꾸지만,
         * "그 날 몇 시에 샀나"는 남겨 둔다(칩 툴팁). 분봉에서 쓰는 값과 같은 원본이다. */
        $hm = pf_hm($t['traded_time'] ?? null);
        if ($hm !== '' && !in_array($hm, $grp[$key]['hm'], true)) $grp[$key]['hm'][] = $hm;
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
            /* 시각은 <b>한 번에 체결된 칩에만</b> 붙인다 — 여러 건을 묶은 칩에 시각 하나를 적으면
             * 나머지 체결이 그 시각에 난 것처럼 읽힌다(단가를 가중평균하고 '(N건)' 을 붙이는 것과 같은 이유). */
            'text' => ($g['sell'] ? '매도' : $g['step'] . '차')
                     . ' ' . number_format($g['qty']) . '주 @' . number_format(round($avg))
                     . ($g['n'] > 1 ? ' (' . $g['n'] . '건)'
                                    : (count($g['hm']) === 1 ? ' ' . $g['hm'][0] : '')),
            'state' => $stateAt((string)$g['time']),
        ];
    }

    // 차트는 공용 모듈(style/dailychart.js)이 그린다 — 라이브러리 로드도 모듈이 맡는다
    echo '<script src="/style/dailychart.js?v=56"></script>';
    echo ChartFeat::boot('position', $pdo);   // DC_SCREEN·DC_FEATS·DC_VIEW (기능 구성 + 저장한 높이)
    echo '<script>';
    echo 'const PF_CODE=' . json_encode($pos['stock_code']) . ';';
    echo 'const PF_MARKS=' . json_encode($fMark ? $marks : [], JSON_UNESCAPED_UNICODE) . ';';
    echo 'const PF_PLINES=' . json_encode(!$fLine ? [] : [
        ['v' => $c['avg_cost']   === null ? null : round($c['avg_cost']),  'c' => '#1e9e74', 't' => '누적단가',   'd' => 0],
        ['v' => $c['sell_price'] === null ? null : (float)$c['sell_price'],'c' => '#d32f2f', 't' => '자동매도가', 'd' => 1],
        ['v' => $c['next_price'] === null ? null : (float)$c['next_price'],'c' => '#1d5c93', 't' => '다음매수가', 'd' => 1],
    ], JSON_UNESCAPED_UNICODE) . ';';

    /* 차수가격(이론) — 차수 사다리의 이론가 전부(1~마지막차). 값의 원천은 사다리 표와 같은
     * $c['steps'] 라 표와 선이 어긋날 수 없다. 그리기·토글은 모듈(setRefLines)이 맡는다. */
    $stepLines = [];
    if ($fStep) {
        foreach ($c['steps'] as $s) {
            if ($s['theory_price'] !== null && (float)$s['theory_price'] > 0) {
                $stepLines[] = round((float)$s['theory_price']);
            }
        }
    }
    echo 'const PF_STEP_PLINES=' . json_encode($stepLines) . ';';

    echo <<<'JS'
DailyChart.load().then(function(){
  var note = document.getElementById('pfDailyNote');
  var F = DailyChart.feats;   // 화면별 기능 구성 (차트설정 > 화면별 구성)
  var dc = DailyChart.create('pfDailyChart', {
    theme: 'light', markers: { chips: true, toggle: true },   // toggle — 기간 바에 「체결 N」 켬/끔
    key: 'position',                         // 차트틀 기억 + 지표 값을 가격선 범례에 표시
    code: PF_CODE,                           // SUE 공시 마커는 모듈이 스스로 얹는다(기능 켜졌을 때만)
    legend: F('legend.values') ? 'pfLegend' : null
  });
  if (!dc) { if (note) note.textContent = '차트 라이브러리를 불러오지 못했습니다.'; return; }

  // 누적단가 · 자동매도가 · 다음매수가 가로선 (설명은 위 범례로 — 차트 안 글자 금지)
  dc.setPriceLines(PF_PLINES.map(function(L){
    return { price: L.v, color: L.c, style: L.d ? 'dashed' : 'solid' };
  }));
  // 체결 마커 — 화살표는 라이브러리, 글자는 모듈의 HTML 칩(충돌회피)
  dc.setMarkers(PF_MARKS);
  // 차수가격(이론) 수평선 — 기본 감춤. 기간 바의 「차수가격 N」 토글이 켠다(SUE 공시와 같은 자리).
  // 색·굵기·종류는 여기 적지 않는다 — ⚙ 모달이 고르고 view_json.ref 에 저장된다(모듈 소유)
  if (PF_STEP_PLINES.length) dc.setRefLines(PF_STEP_PLINES.map(function(v){
    return { price: v };
  }), { label: '차수가격',
        title: '차수 사다리의 이론 매수가 수평선 — 차트에 보이는 가격 범위 안의 선만 그려진다 (⚙ 색·굵기)' });

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
    fullscreen: F('fullscreen'),
    onChange: function(info){
      var t = document.getElementById('pfDailyTitle');
      if (t) t.textContent = info.tf === 'week' ? '주봉 차트' : '일봉 차트';
      pfDailyNote();
    }
  });
  DailyChart.indicatorBar('pfIBar', dc, { theme: 'light', key: 'position',
                                          preset: F('preset.select') });   // 사용자 지표 + 차트틀

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
    echo '<label class="fld">일자<input type="date" id="pfmBuyDate" name="traded_at" value="' . date('Y-m-d') . '" required></label>';
    /* 시각은 <b>선택</b>이다 — 옛 체결은 모르고, 모르는 것을 00:00 으로 채우면 거짓이 된다.
     * step=1 이라야 초까지 받는다(HTS 체결시각이 15:02:46 처럼 초까지 나온다).
     * 기본값은 <b>모달을 열 때 pfNowFields() 가</b> 지금 시각으로 채운다(서버 렌더 시각이 아니다). */
    echo '<label class="fld">시각<input type="time" id="pfmBuyTime" name="traded_time" step="1" style="width:155px" '
       . 'title="HTS 체결시각. 지금 시각이 기본값입니다 — 다르면 고치고, 모르면 비우세요(분봉에 찍을 때만 씁니다)."></label>';
    echo '<label class="fld">체결가<input type="text" class="num-comma" inputmode="numeric" id="pfmBuyPrice" name="price" style="width:120px" required></label>';
    echo '<label class="fld">수량<input type="text" class="num-comma" inputmode="numeric" id="pfmBuyQty" name="qty" style="width:80px" required></label>';
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
    echo '<label class="fld">일자<input type="date" id="pfmSellDate" name="traded_at" value="' . date('Y-m-d') . '" required></label>';
    echo '<label class="fld">시각<input type="time" id="pfmSellTime" name="traded_time" step="1" style="width:155px" '
       . 'title="HTS 체결시각. 지금 시각이 기본값입니다 — 다르면 고치고, 모르면 비우세요(분봉에 찍을 때만 씁니다)."></label>';
    echo '<label class="fld">매도가<input type="text" class="num-comma" inputmode="numeric" id="pfmSellPrice" name="price" style="width:120px" required></label>';
    echo '<label class="fld">수량<input type="text" class="num-comma" inputmode="numeric" id="pfmSellQty" name="qty" style="width:80px" required></label>';
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
            /* 시각은 있을 때만 붙인다 — 없는 행에 '-' 를 채우면 「모른다」가 잡음이 된다 */
            $hm = pf_hm($t['traded_time'] ?? null);
            echo '<td>' . pf_h($t['traded_at'])
               . ($hm !== '' ? ' <span class="muted" style="font-size:11px">' . pf_h($hm) . '</span>' : '')
               . '</td>';
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

/**
 * 체결 일자·시각 기본값 = <b>지금</b>. 모달을 <b>열 때마다</b> 다시 채운다.
 *
 * ★서버 렌더 시각을 쓰지 않는 이유 — 화면을 열어 둔 채 몇 시간 뒤에 담으면 그 값이 낡는다.
 *   일자도 함께 갱신한다(시각만 지금이고 일자는 어제면 두 칸이 다른 말을 한다).
 * ★비우면 여전히 「모른다」(NULL) 다 — 기본값을 준 것이지 필수로 만든 것이 아니다.
 * ★브라우저 로컬 시각이라 서버 타임존 설정에 기대지 않는다(사용자가 보는 시계와 같다).
 */
function pfNowFields(){
  var d = new Date(), p = function(n){ return ('0' + n).slice(-2); };
  var ymd = d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
  var hms = p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());   // step=1 이라 초까지
  [['pfmBuyDate', ymd], ['pfmSellDate', ymd], ['pfmBuyTime', hms], ['pfmSellTime', hms]].forEach(function(x){
    var el = document.getElementById(x[0]);
    if (el) el.value = x[1];
  });
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

  pfNowFields();
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

  pfNowFields();
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

/* ★ 종목 상세의 한 줄 상태판 — 층 일곱을 왼쪽에서 오른쪽으로.
 * 각 층은 <span class="sr-g">(라벨 + 배지들)</span> 한 덩이라, 줄바꿈이 일어나도 층이 쪼개지지 않는다. */
.status-row{display:flex;align-items:center;gap:0 14px;flex-wrap:wrap;background:#fff;
  border:1px solid #e3eaf0;border-radius:10px;padding:9px 13px;margin-bottom:14px;
  box-shadow:0 1px 3px rgba(20,40,60,.05);row-gap:7px}
.status-row .sr-g{display:inline-flex;align-items:center;gap:4px;white-space:nowrap}
.status-row .sr-g+.sr-g{padding-left:14px;border-left:1px solid #eef2f6}
/* ★ 상자형 층 — 좌측 진한 라벨이 갈래를 통째로 감싼다. 퀀트신호·경로처럼 <b>한 사건에서 나온
 * 두 값</b>은 구분선으로 나누는 것보다 상자로 묶어야 「같은 것의 두 면」으로 읽힌다. */
.status-row .sr-box{padding:0;border:1px solid #d7e0e8;border-radius:8px;overflow:hidden;background:#fff}
.status-row .sr-box+.sr-g,.status-row .sr-g+.sr-box{padding-left:0;border-left:0}
.status-row .sr-bk{background:#2c3e50;color:#fff;font-size:11px;font-weight:800;letter-spacing:.02em;
  padding:6px 10px;align-self:stretch;display:inline-flex;align-items:center;white-space:nowrap}
.status-row .sr-bb{display:inline-flex;align-items:center;gap:3px;padding:3px 10px 3px 8px}
.status-row .sr-box .sr-sub{color:#8fa0b0}
.status-row .sr-k{font-size:11px;font-weight:800;color:#8fa0b0;letter-spacing:.02em}
/* 한 층 안의 갈래 이름(신호 / 경로) — 층 라벨보다 한 단계 옅게 */
.status-row .sr-sub{font-size:10px;font-weight:700;color:#b6c1cb;margin:0 1px 0 3px}
.status-row .sr-none{font-size:12px;color:#c2ccd6;font-weight:600}
/* ★「편입 당시」(M4) — <b>기록</b>이지 살아 있는 판정이 아니다. 그래서 퀀트 배지(.qb/.bx)의
   초록·빨강 팔레트를 쓰지 않고 무채색으로 둔다. 색이 같으면 오늘의 판정으로 읽힌다. */
.status-row .ent-b{display:inline-block;font-size:11px;font-weight:700;border-radius:5px;
  padding:1px 6px;border:1px solid #dbe3ea;background:#f6f8fa;color:#5f7183;
  vertical-align:middle;font-variant-numeric:tabular-nums}
.status-row .sig-conf{margin-top:0;cursor:help}
.status-row .mkt,.status-row .qb,.status-row .bx{vertical-align:middle}
.status-row .sr-help{margin-left:auto;font-size:11.5px;color:#9aa7b4;text-decoration:none;white-space:nowrap}
.status-row .sr-help:hover{color:#1d5c93;text-decoration:underline}
@media(max-width:900px){.status-row .sr-g+.sr-g{padding-left:0;border-left:0}}

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
    /* 퀀트 사다리 — 매수 방식은 편입 때 정한다(룰셋 종목을 퀀트 사다리로 전환하는 흐름이 아니다).
     * 수정 화면에서는 이미 박스인 포지션만 같은 섹션으로 재조정할 수 있다. */
    $posLvF  = $isNew ? [] : $pf->positionLevels((int)$pos['id']);
    $buyMode = $posLvF ? 'box' : 'rule';

    // 대시보드에서 "＋ 종목 추가"로 들어오면 해당 포트폴리오를 미리 선택해 둔다
    $preFid = $isNew ? (int)($_GET['pid'] ?? 0) : (int)$pos['portfolio_id'];

    /* 탐색 화면(재무분석·퀀트·어닝·관심종목)의 「편입」 버튼으로 들어오는 길 —
     * ?code=&name=&src=&bm= 을 받아 종목·출처·매수방식을 미리 채운다.
     * pid 없이 오면(=포트폴리오 정보 없는 편입): 폼은 그대로 열리되 포트폴리오 칸은 「미지정」 고정이고,
     * 저장하면 포지션이 아니라 <b>편입 관심종목</b>(pf_watchlist.adopt_at)으로 등록된다 — 2026-08-02 규칙.
     * 실제 편입(포트폴리오 지정)은 pid 를 갖고 온 이 폼 하단의 「편입 관심종목」 목록에서만 한다. */
    $preCode = $isNew ? preg_replace('/[^0-9A-Za-z]/', '', (string)($_GET['code'] ?? '')) : '';
    $preStk  = $isNew ? trim((string)($_GET['name'] ?? '')) : '';
    $preSrc  = $isNew ? preg_replace('/[^a-z]/', '', (string)($_GET['src'] ?? '')) : '';
    $preBm   = ($isNew && (($_GET['bm'] ?? '') === 'box')) ? 'box' : '';
    /* 편입 관심종목에 보관해 둔 박스 지지선 — 하단 목록의 「선택」이 &bxp=가격,가격,… 으로 실어 온다 */
    $preBxp = [];
    if ($isNew && $preBm === 'box' && isset($_GET['bxp'])) {
        foreach (explode(',', (string)$_GET['bxp']) as $v) {
            $v = (float)$v;
            if ($v > 0) $preBxp[] = $v;
        }
    }
    /* ── M2 (v0.3 §2.4) — 탐색 화면이 「지금 보고 있는 사실」을 편입 폼까지 실어 온다.
     *   &trig= 검증된 매수자리(관심종목의 트리거 열) · &sed= 그 판정의 기준 신호일.
     * ★ 여기서 다시 계산하지 않는다 — 편입 순간의 화면과 저장값이 어긋나면 기록의 뜻이 없다.
     *   안 실려 오면(재무분석·수동 편입) Pf::positionSave 가 'none' / 상한 안 최신 신호로 메운다. */
    $preTrig = ($isNew && in_array($_GET['trig'] ?? '', ['breakout', 'step_support'], true))
        ? (string)$_GET['trig'] : '';
    $preSed  = ($isNew && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['sed'] ?? '')))
        ? (string)$_GET['sed'] : '';

    if ($preCode !== '' && $preStk === '') {
        $nm = $pf->pdo()->prepare("SELECT stock_name FROM all_stock_info WHERE stock_code = ?");
        $nm->execute([$preCode]);
        $preStk = (string)($nm->fetchColumn() ?: $preCode);
    }
    /* 포트폴리오가 하나뿐이어도 자동 선택하지 않는다 — pid 없이 온 것은 「정보 없는 편입」이라
     * 미지정 저장(편입 관심종목)이 기본 경로여야 한다. 담으려면 select 에서 직접 고른다. */

    $preName = '';
    foreach ($folios as $f) if ((int)$f['id'] === $preFid) $preName = $f['name'];

    pf_head($isNew ? '종목 추가' : '종목 설정 수정', 'dashboard');
    pf_flash();

    echo '<div class="pf-head"><div><h1>' . ($isNew ? '종목 추가' : '종목 설정 수정') . '</h1>';
    echo '<div class="sub">';
    if ($preName !== '') echo '<b>' . pf_h($preName) . '</b>에 담습니다. ';
    echo '종목은 이름이나 코드로 검색해 고르면 현재가까지 자동으로 채워집니다.';
    if ($isNew && !$preFid) {
        echo '<br>포트폴리오 정보 없이 들어온 편입이라 저장하면 <b>편입 관심종목</b>으로 등록됩니다 — '
           . '여기서 고른 매수 방식·룰셋·박스 지지선도 함께 저장되어, 포트폴리오의 「＋ 종목 추가」 하단 목록에서 '
           . '「선택」하면 그대로 복원됩니다.';
    }
    echo '</div>';
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

    echo '<div class="card"><form method="post" action="/stock/api.php?module=position&action=save">';
    if (!$isNew) echo '<input type="hidden" name="id" value="' . (int)$pos['id'] . '">';

    echo '<div class="fld-row" style="margin-bottom:12px">';

    // ── 포트폴리오는 들어올 때 정해져 있어 고정 표시 · 룰셋은 여기서 고른다
    if ($isNew) {
        if ($preFid) {
            echo '<input type="hidden" name="portfolio_id" value="' . $preFid . '">';
            echo '<div class="fld"><span>포트폴리오</span><div class="fld-fixed">'
               . pf_h($preName !== '' ? $preName : '-') . '</div></div>';
        } else {
            // 탐색 화면에서 pid 없이 온 편입 — 포트폴리오는 여기서 고르지 않는다(고정 표시).
            // 저장 = 무조건 편입 관심종목. 실제 편입은 포트폴리오의 「＋ 종목 추가」 하단 목록에서 한다.
            echo '<div class="fld"><span>포트폴리오</span><div class="fld-fixed" '
               . 'title="포트폴리오 정보 없이 들어온 편입이라 여기서는 정하지 않습니다 — 저장하면 편입 관심종목이 됩니다">'
               . '미지정 (편입 관심종목)</div></div>';
        }

        // 룰셋은 ?rid= 로 미리 골라 두되 여기서 바꿀 수 있다 (퀀트 사다리를 골라도 목표수익률·지연은 룰셋에서 온다)
        echo '<label class="fld">룰셋<select name="rule_set_id" required>';
        foreach ($rules as $r) {
            $sel = ((int)$r['id'] === $preRid) ? ' selected' : '';
            echo '<option value="' . (int)$r['id'] . '"' . $sel . '>' . pf_h($r['name'])
               . ' (' . (int)$r['step_count'] . '차수)</option>';
        }
        echo '</select></label>';

        // 매수 방식 — 편입 때 정한다. 퀀트 사다리면 아래 섹션에서 지지선을 고르고 저장 한 번으로 확정.
        // 퀀트·어닝발 편입(bm=box)은 퀀트 사다리를 기본 선택해 둔다 — 지지 구조를 보고 온 흐름이라서.
        $ruleChk = $preBm === 'box' ? '' : ' checked';
        $boxChk  = $preBm === 'box' ? ' checked' : '';
        echo '<div class="fld"><span>매수 방식</span><div style="padding:7px 0;font-size:13px;white-space:nowrap">'
           . '<label style="margin-right:12px"><input type="radio" name="buy_mode" value="rule"' . $ruleChk . ' onchange="pfBoxToggle()"> 룰셋 하락률</label>'
           . '<label title="이 종목의 실제 박스 지지선(3~5개)을 골라 차수 가격표를 확정합니다 — 1차부터 지지에 닿아야 사는 매복형"><input type="radio" name="buy_mode" value="box"' . $boxChk . ' onchange="pfBoxToggle()"> 퀀트 사다리</label>'
           . '</div></div>';

        // 발굴 출처 — 어느 탐색 화면에서 왔나 (채널별 성과 측정용, 저장 시 pf_position.source 로)
        if ($preSrc !== '') echo '<input type="hidden" name="source" value="' . pf_h($preSrc) . '">';
        // 편입 3축의 나머지 둘 (M2) — 매수 방식은 위 라디오가, 이 둘은 탐색 화면이 정한다
        if ($preTrig !== '') echo '<input type="hidden" name="entry_trigger" value="' . pf_h($preTrig) . '">';
        if ($preSed  !== '') echo '<input type="hidden" name="surge_event_d" value="' . pf_h($preSed) . '">';
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
           . ($buyMode === 'box' ? '📦 퀀트 사다리 <span class="muted">(' . count($posLvF) . '차)</span>' : '룰셋 하락률')
           . '</div></div>';
    }

    // ── 종목 — 코드/이름을 하나로 합치고 자동완성으로 고른다 (시뮬레이터와 공용 위젯)
    if ($isNew) {
        // 폭 상한 — flex:1 로 두면 남는 폭을 혼자 다 먹어 메모가 다음 줄로 밀린다 (2026-08-11 사용자 지시)
        pf_stock_picker('stk', $preCode, $preCode !== '' ? $preStk : '', '종목', '(이름 또는 코드로 검색)', '180px', '240px');
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

    // 메모는 줄의 오른쪽 끝으로 민다 (margin-left:auto · 2026-08-11 사용자 지시 — 한 줄 배치)
    echo '<label class="fld" style="margin-left:auto">메모<input type="text" name="memo" style="width:200px" value="'
       . pf_h($pos['memo'] ?? '') . '"></label>';
    echo '</div>';

    /* ── 퀀트 사다리 섹션 — 폼 안(하단)이라 고른 가격이 저장 한 번에 같이 실린다.
     * 후보는 박스의 하단(L=지지)·상단(H — 옛 박스 상단도 계단 정의상 지지). 일봉이 하루에
     * 하나씩 생겨 변별력이 없으면 주봉(24주 최고 거래대금)으로. 차트에 후보선을 그려 자리를 보고 고른다.
     *
     * ★★용어(2026-08-03 사용자 지시): 화면에 쓰는 이름은 <b>「퀀트 사다리」</b> 하나다.
     *   옛 이름 「박스 사다리」는 전부 걷어냈다. 단 <b>내부 식별자는 그대로 box</b> —
     *   buy_mode='box' · pf_position_level · pf_box_ladder_build() · api boxlv/boxsolve ·
     *   JS pfBox* · pf_watchlist.adopt_bm 에 이미 'box' 가 <b>저장돼 있어</b> 바꾸면 기존 데이터가 끊긴다.
     *   ⇒ 「박스」라는 낱말이 화면에 남아도 되는 곳은 <b>가격 구조 자체</b>(최고 거래대금 박스·박스 지지선)뿐이다. */
    // 보관해 둔 지지선이 실려 오면 미리 풀어서 확정본처럼 보여 준다 — 그대로 저장하면 끝
    $preSolved = ($isNew && count($preBxp) >= 3) ? pf_box_ladder_build($preBxp) : null;

    $bxShow = ($buyMode === 'box') || ($isNew && $preBm === 'box');
    echo '<div id="boxSection" style="display:' . ($bxShow ? 'block' : 'none') . ';margin:14px 0 12px;'
       . 'padding:12px;border:1px solid #dfe8f0;border-radius:10px;background:#fbfdff">';
    echo '<h2 style="font-size:14px;margin:0 0 8px">📦 퀀트 사다리 — 지지선 선택</h2>';
    echo '<div class="fld-row" style="margin-bottom:8px">'
       . '<div class="fld"><span>&nbsp;</span><span id="bxPBar"></span></div>'
       . '<div class="fld"><span>&nbsp;</span><button type="button" class="btn btn-outline btn-sm" onclick="pfBoxLoad()">후보 불러오기</button></div>'
       . '<div class="fld"><span>&nbsp;</span><span class="muted" id="bxPickCnt" style="font-size:12px;padding:7px 0"></span></div>'
       . '<div class="fld"><span>&nbsp;</span><span id="bxIBar"></span></div></div>';
    echo '<div class="muted" style="font-size:12px;margin-bottom:8px">종목을 먼저 고른 뒤 후보를 불러오세요. '
       . '지지선을 <b>3~5개</b> 고르면(높은 값이 1차) 그 가격 간격으로 비중·목표·지연을 풀어 미리보기로 보여 줍니다. '
       . '일봉 박스가 너무 촘촘하면 주봉으로 바꿔 보세요. '
       . '후보에 마땅한 값이 없으면 목록 아래 <b>「직접 입력」</b>으로 지지선을 추가할 수 있습니다.</div>';
    echo '<div id="bxChart" style="height:300px;position:relative;margin-bottom:10px"></div>';
    echo '<div style="display:flex;gap:14px;flex-wrap:wrap">';
    echo '<div style="flex:1 1 300px">';
    echo '<div id="bxList" style="font-size:13px"><span class="muted">'
       . ($posLvF ? '현재 확정된 가격표가 아래에 있습니다 — 재조정하려면 후보를 불러와 다시 고르세요.'
                  : ($preSolved ? '편입 관심종목에 보관해 둔 지지선을 불러왔습니다 — 바꾸려면 후보를 불러와 다시 고르세요.'
                                : '「후보 불러오기」를 누르세요.'))
       . '</span></div>';
    /* 직접 입력 — 후보 박스에 없는 지지선(더 낮은 바닥 등)을 손으로 추가한다 (2026-08-12 사용자 요청).
     * 넣는 즉시 차트에 선이 서고 비중을 <b>자동으로 다시 푼다</b> — 후보 조합이 안 풀릴 때의 탈출구.
     * 값은 늘 「선택됨」으로 친다(빼려면 칩의 ×) — 저장은 기존 box_prices[] 경로 그대로라 서버 무변경. */
    echo '<div class="fld-row" style="margin-top:8px;align-items:center">'
       . '<input type="text" class="num-comma" inputmode="numeric" id="bxAddPx" placeholder="지지선 직접 입력(원)" style="width:150px">'
       . '<button type="button" class="btn btn-outline btn-sm" onclick="pfBoxAdd()">＋ 추가</button>'
       . '<span id="bxCust" style="font-size:13px"></span></div>';
    echo '</div>';
    echo '<div style="flex:1 1 430px"><div class="fld-row" style="margin-bottom:6px">'
       . '<button type="button" class="btn btn-primary btn-sm" onclick="pfBoxSolve()">선택한 가격으로 비중 풀기</button></div>'
       . '<div id="bxPreview" style="font-size:13px">';
    if ($posLvF) {
        echo pf_box_ladder_table(pf_box_ladder_detail($posLvF));
        echo '<div class="muted" style="font-size:12px">현재 확정본 — 저장하면 그대로 유지됩니다.</div>';
    } elseif ($preSolved) {
        echo pf_box_ladder_table(pf_box_ladder_detail($preSolved['levels']));
        echo '<div class="muted" style="font-size:12px">편입 관심종목에 보관해 둔 지지선입니다 — 이대로 저장하면 확정됩니다.</div>';
    } elseif ($preBxp) {
        echo '<div class="muted" style="font-size:12px;color:#c0392b">보관해 둔 지지선(' . count($preBxp)
           . '개)으로는 비중이 풀리지 않습니다 — 후보를 불러와 다시 고르세요.</div>';
    }
    echo '</div></div></div>';
    // 저장에 실릴 가격들 — 재조정 전에는 현재 확정본(또는 보관해 둔 지지선)이 그대로 실려
    // 「그냥 저장」이 가격표를 지우지 않는다
    echo '<span id="bxHidden">';
    foreach ($posLvF as $lv) echo '<input type="hidden" name="box_prices[]" value="' . (float)$lv['price'] . '">';
    if (!$posLvF && $preSolved) {
        foreach ($preSolved['levels'] as $lv) echo '<input type="hidden" name="box_prices[]" value="' . (float)$lv['price'] . '">';
    }
    echo '</span>';
    echo '</div>';   // #boxSection

    /* 시장(증권거래세 결정)·저장 — 한 줄 (2026-08-11 사용자 지시 · 「종목 정보」 섹션 제거).
     * 현재가·최고가 입력칸도 같은 날 삭제 — 시세는 저장 시 all_stock_info 에서 자동으로 채우고
     * (api.php position save), 이후는 syncPrices()/refreshQuotes() 가 맡는다.
     * 폼에 키가 없어도 stockUpsert 가 IFNULL 이라 기존 값을 밀지 않는다. */
    echo '<div class="fld-row" style="margin-top:12px;padding-top:12px;border-top:1px solid #eef2f6;align-items:center">';

    // .fld 는 column 이라 라벨이 셀렉트 «위»에 얹힌다 — 여기만 row 로 눕혀 글자·셀렉트·버튼을 한 줄에 (2026-08-11)
    echo '<label class="fld" style="flex-direction:row;align-items:center;gap:8px">'
       . '시장 <span class="muted">(증권거래세 결정)</span><select name="market">';
    $curMarket = $pos['market'] ?? 'KOSPI';
    foreach ($markets as $m) {
        $sel = ($m['code'] === $curMarket) ? ' selected' : '';
        echo '<option value="' . pf_h($m['code']) . '"' . $sel . '>' . pf_h($m['code'])
           . ' · ' . pf_h($m['name']) . ' (' . pf_h(pf_pct0((float)$m['tax_rate'], 2)) . ')</option>';
    }
    echo '</select></label>';

    if (!$isNew && ($pos['priced_at'] ?? '') !== '') {
        echo '<div class="fld" style="flex-direction:row;align-items:center;gap:8px"><span>시세 갱신시각</span>'
           . '<span class="muted" style="font-size:13px">' . pf_h($pos['priced_at']) . '</span></div>';
    }

    // 미지정 저장 = 편입 관심종목 등록 — 버튼 글자가 결과를 미리 말해 줘야 한다
    echo '<button class="btn btn-primary" type="submit">'
       . (($isNew && !$preFid) ? '편입 관심종목으로 저장' : '저장') . '</button>';
    if (!$isNew) {
        echo '<a class="btn btn-outline" href="/stock/index.php?mode=position&id=' . (int)$pos['id'] . '">취소</a>';
    }
    echo '</div>';
    echo '</form></div>';

    /* ── 편입 관심종목 (2026-08-02) — 신규 화면(포트폴리오 확정)에만.
     * 전체 관심종목이 아니라 <b>편입 대기로 지정한 것(adopt_at)</b>만 보여 준다 —
     * 포트폴리오 정보 없이 편입 저장한 종목이 여기 모이고, 「선택」 하면 위 폼에 채워진다(퀀트 사다리 기본).
     * 출처·담은날·담을때가는 저장 때 pf_watchlist 에서 자동 승계된다.
     * ★편입해도 목록에서 빼지 않는다(2026-08-03) — 같은 종목을 다른 포트폴리오에도 담을 수 있어야 한다.
     *   이 포트폴리오에 이미 있으면 「등록됨」으로만 표시하고, 목록에서 없애는 것은 「삭제」뿐이다. */
    if ($isNew && $preFid) {
        /* 재진입 후보를 <b>먼저</b> 구한다 — 편입 관심종목의 「청산됨 ↓」 앵커가 실제로 갈 곳이 있는지
         * 알아야 하기 때문이다. closedPositions() 는 <b>체결 기준</b>(매도>0 · 보유=0)이라
         * status 만 closed 인 포지션은 여기 안 잡힌다 — 그때는 화살표 없는 라벨로 둔다
         * (없는 자리를 가리키는 링크를 만들지 않는다). */
        $reForm = pf_reentry_list($pf, $preFid, PF_SIM_WAIT_DEFAULT, null, new DateTimeImmutable('today'));
        $reIds  = array_map('intval', array_column($reForm, 'id'));

        $wl = array_values(array_filter($pf->watchList(), fn($w) => !empty($w['adopt_at'])));
        if ($wl) {
            /* 이 포트폴리오에 이미 <b>행이</b> 있는 종목 — uk_pf_pos(portfolio_id, stock_code) 유니크라
             * 같은 자리에 새 포지션을 만들 수 없다.
             *
             * ★★<b>청산(status='closed')해도 행은 남는다.</b> 예전에는 존재 여부만 보고 「등록됨」이라 적어서
             *   <b>전량 매도해 목록에 없는 종목</b>까지 등록됨으로 보였다 — 화면과 배지가 서로 다른 말을 했고
             *   (사용자 신고 2026-08-04: 관사장학회 삼성전자), 「선택」 버튼을 가려 <b>재진입 경로까지 막혔다</b>.
             *   재진입은 이 엔진의 수익원인데(닫힌 사이클 207개·중앙 +20.1%) 그 문이 잠겨 있었다.
             * ⇒ 상태를 함께 읽어 청산이면 「청산됨 + 재진입」으로 <b>그 포지션</b>으로 보낸다.
             *   새 행을 만드는 것이 아니라 그 포지션에 매수를 기록하면 api 가 status 를 open 으로 되돌린다
             *   (api.php 「상태 자동 전환 — 재매수 → 보유」). 판정 기준은 pf_calc_closed 와 같은 status='closed' 다. */
            $inPf = [];
            $st = $pf->pdo()->prepare("SELECT id, stock_code, status FROM pf_position WHERE portfolio_id = ?");
            $st->execute([$preFid]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $inPf[(string)$r['stock_code']] = [
                    'id'     => (int)$r['id'],
                    'closed' => ((string)$r['status'] === 'closed'),
                ];
            }

            $srcMap = ['quant' => '퀀트', 'earn' => '어닝', 'fund' => '스크리너', 'watch' => '관심'];
            $ruleNames = [];
            foreach ($rules as $r) $ruleNames[(int)$r['id']] = $r['name'];
            echo '<div class="card"><h2>편입 관심종목 <span class="muted" style="font-size:12px;font-weight:600">'
               . count($wl) . '개 — 포트폴리오 없이 편입 저장한 종목</span></h2>';
            /* ★★<b>고를 수 있는 것이 위로</b>(2026-08-04 사용자). 「등록됨·청산됨」 같은 <b>글자를 없애는</b> 대신
             * 순서가 그 말을 한다 — 위쪽은 지금 담을 수 있는 것, 아래쪽은 이미 자리가 있어 담을 수 없는 것.
             * 이유는 칸에 마우스를 올리면 나온다(글자로 늘 떠 있을 값어치는 없다).
             * ★지금 폼에 올라와 있는 행은 맨 위 — 내가 방금 고른 것이 눈에서 사라지면 안 된다.
             * ★PHP 8 의 정렬은 안정(stable)이라 같은 무리 안의 순서는 담은 순 그대로다. */
            usort($wl, function ($a, $b) use ($inPf, $preCode) {
                $rank = function ($w) use ($inPf, $preCode) {
                    if ((string)$w['stock_code'] === $preCode) return 0;   // 지금 고른 것
                    return isset($inPf[(string)$w['stock_code']]) ? 2 : 1; // 담을 수 있는 것 → 자리가 있는 것
                };
                return $rank($a) <=> $rank($b);
            });

            /* 열 순서(2026-08-04 사용자): <b>종목명이 맨 왼쪽</b> · 「선택」은 그 오른쪽 칸 · <b>× 는 맨 끝(메모 옆)</b>.
             * ★읽는 순서가 판단의 순서와 같아야 한다 — 「무슨 종목인가」를 먼저 보고 「고를까」를 정한다.
             *   버튼이 맨 앞에 있으면 이름을 보기도 전에 누를 것부터 눈에 들어온다.
             * ★빼기(×)는 되돌릴 수 없는 쪽이라 <b>동선의 끝</b>에 둔다 — 고르는 손과 지우는 손이 겹치지 않게. */
            echo '<div class="tbl-scroll"><table class="pf"><thead><tr>'
               . '<th>종목명</th><th></th><th>출처</th><th>방식</th><th class="num">담은날</th><th class="num">담을때가</th>'
               . '<th class="num">현재가</th><th class="num">담은뒤</th><th>메모</th><th></th></tr></thead><tbody>';
            foreach ($wl as $w) {
                $c     = $w['stock_code'];
                $now   = (float)($w['last_price'] ?? 0);
                $added = (float)($w['added_price'] ?? 0);
                $chg   = ($now > 0 && $added > 0) ? $now / $added - 1 : null;
                $cur   = ($c === $preCode);
                $off   = isset($inPf[$c]) && !$cur;    // 이 포트폴리오에 자리가 있어 고를 수 없는 행
                echo '<tr class="' . ($cur ? 'wl-on' : ($off ? 'wl-off' : '')) . '">';

                // ① 종목명 — 맨 왼쪽
                echo '<td class="stk"><a href="/stock/index.php?mode=fund&code=' . pf_h($c) . '" title="재무분석 상세">'
                   . pf_h($w['stock_name']) . '</a><span class="code">' . pf_h($c) . '</span></td>';

                // ② 선택 — 종목명 바로 오른쪽
                if (isset($inPf[$c]) && !$cur) {
                    /* ★★자리가 이미 있는 행 — <b>글자를 쓰지 않는다</b>(2026-08-04 사용자: 「등록됨·청산됨은 불필요」).
                     * 이 표는 <b>종목</b> 목록이고, 청산한 자리를 다시 여는 것은 <b>포지션</b>의 일이라
                     * 아래 「재진입 후보」 카드가 맡는다. 왜 고를 수 없는지는 <b>칸의 툴팁</b>에 둔다.
                     * ⊕단 「보유 중」과 「청산」은 갈라 보인다(2026-08-20 사용자 — 가라앉은 행 셋이 똑같이 생겨
                     *   보유인지 재진입 대상인지 화면만으로 알 수 없었다): 청산이고 아래 카드에 실제 행이 있으면
                     *   ↓ 하나만 단다(누르면 재진입 후보로). 보유 중은 빈 칸 그대로 — 표시가 없는 것이 곧 「보유」다.
                     *   화살표는 갈 곳이 있을 때만(reIds) — 없는 자리를 가리키는 링크를 만들지 않는다. */
                    $tip = $inPf[$c]['closed']
                        ? '이 포트폴리오에서 전량 매도한 자리가 남아 있어 새로 담을 수 없습니다'
                          . (in_array($inPf[$c]['id'], $reIds, true) ? ' — 아래 「재진입 후보」에서 그 자리를 다시 엽니다' : '')
                        : '이 포트폴리오에 이미 담겨 있는 종목입니다';
                    $arrow = ($inPf[$c]['closed'] && in_array($inPf[$c]['id'], $reIds, true))
                        ? '<a class="wl-re" href="#reCard" title="아래 「재진입 후보」로 이동">&darr;</a>' : '';
                    echo '<td class="center wl-act" title="' . pf_h($tip) . '">' . $arrow . '</td>';
                } elseif ($cur) {
                    echo '<td class="center wl-act">'
                       . '<span class="wl-pill on" title="지금 위 폼에 올라와 있는 종목입니다">선택됨</span></td>';
                } else {
                    echo '<td class="center wl-act">'
                       . '<button type="button" class="wl-pill wl-pick" '
                       . 'data-code="' . pf_h($c) . '" data-name="' . pf_h($w['stock_name'])
                       . '" data-bm="' . pf_h((string)($w['adopt_bm'] ?? '')) . '" data-rid="' . (int)($w['adopt_rule'] ?? 0)
                       . '" data-bxp="' . pf_h((string)($w['adopt_prices'] ?? ''))
                       . '" title="위 폼에 이 종목과 보관해 둔 매수 방식·지지선을 채웁니다">선택</button></td>';
                }

                echo '<td class="muted" style="font-size:12px">' . pf_h($srcMap[(string)($w['source'] ?? '')] ?? '-') . '</td>';
                // 방식 — 편입 대기 때 골라 둔 매수방식(박스면 지지선 개수·가격을 툴팁으로)
                if (($w['adopt_bm'] ?? '') === 'box' && (string)($w['adopt_prices'] ?? '') !== '') {
                    $bmTxt = '퀀트 ' . (substr_count((string)$w['adopt_prices'], ',') + 1) . '차';
                    $bmTip = '보관한 지지선: ' . str_replace(',', ' · ', (string)$w['adopt_prices']);
                } else {
                    $rn    = $ruleNames[(int)($w['adopt_rule'] ?? 0)] ?? '';
                    $bmTxt = '룰셋';
                    $bmTip = $rn !== '' ? '룰셋: ' . $rn : '룰셋 하락률';
                }
                echo '<td class="muted" style="font-size:12px;white-space:nowrap" title="' . pf_h($bmTip) . '">'
                   . pf_h($bmTxt) . '</td>';
                echo '<td class="num muted">' . pf_h(substr((string)$w['added_at'], 2, 8)) . '</td>';
                echo '<td class="num muted">' . pf_n($added ?: null) . '</td>';
                echo '<td class="num">' . pf_n($now ?: null) . '</td>';
                echo '<td class="num">' . pf_signed_pct($chg) . '</td>';
                echo '<td class="muted" style="font-size:12px">' . pf_h((string)$w['memo']) . '</td>';
                // ⑩ 빼기 — 메모 옆 맨 끝. 편입 대기만 풀고 관심종목 목록에는 남긴다
                echo '<td class="center"><button type="button" class="wl-x wl-drop" data-code="' . pf_h($c)
                   . '" data-name="' . pf_h($w['stock_name'])
                   . '" title="편입 관심종목에서 빼기 — 관심종목 목록에는 남습니다">&times;</button></td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
            echo '<p class="sub muted" style="margin:8px 0 0;font-size:12px">'
               . '<b>「선택」</b>하면 위 폼에 종목과 <b>보관해 둔 매수 방식·룰셋·박스 지지선</b>까지 채워집니다'
               . '(위에 입력하던 값은 초기화). 출처·담은날·담을때가는 저장 때 자동 승계됩니다. '
               . '맨 오른쪽 <b>×</b> 는 편입 대기만 풀고 관심종목 목록에는 남깁니다.<br>'
               . '<b>편입해도 이 목록에 남습니다</b> — 같은 종목을 다른 포트폴리오에도 담을 수 있습니다. '
               . '<b>위쪽이 지금 담을 수 있는 종목</b>이고, 아래쪽은 이 포트폴리오에 이미 자리가 있어 고를 수 없는 것입니다'
               . '(사유는 그 칸에 마우스를 올리면 나옵니다). 전량 매도한 자리는 <b>↓</b> 로 표시되고 '
               . '아래 <b>재진입 후보</b>가 맡습니다(누르면 이동 · 표시 없이 가라앉은 행은 보유 중). '
               . '트리거·SUE 판정은 <a href="/stock/index.php?mode=watch">관심종목</a> 화면에서 보세요.</p>';
            echo '</div>';

            echo '<script>document.addEventListener("click",function(e){'
               . 'var b=e.target.closest&&e.target.closest(".wl-pick");if(!b)return;'
               . 'var u="?mode=position&id=new&pid=' . $preFid . '"'
               . '+"&code="+encodeURIComponent(b.getAttribute("data-code"))'
               . '+"&name="+encodeURIComponent(b.getAttribute("data-name"));'
               . 'var rid=b.getAttribute("data-rid");if(rid&&rid!=="0")u+="&rid="+rid;'
               . 'if(b.getAttribute("data-bm")==="box"){u+="&bm=box";'
               . 'var p=b.getAttribute("data-bxp");if(p)u+="&bxp="+encodeURIComponent(p);}'
               . 'location.href=u;});'
               . 'document.addEventListener("click",function(e){'
               . 'var d=e.target.closest&&e.target.closest(".wl-drop");if(!d)return;'
               . 'if(!confirm(d.getAttribute("data-name")+" 을(를) 편입 관심종목에서 뺄까요?\n(관심종목 목록에는 남습니다)"))return;'
               . 'var body=new URLSearchParams({json:"1",code:d.getAttribute("data-code")});'
               . 'fetch("/stock/api.php?module=watch&action=unadopt",{method:"POST",'
               . 'headers:{"Content-Type":"application/x-www-form-urlencoded"},body:body})'
               . '.then(function(r){return r.json();}).then(function(j){'
               . 'if(!j||!j.ok){alert((j&&j.message)||"실패했습니다.");return;}location.reload();})'
               . '.catch(function(){alert("통신에 실패했습니다.");});});</script>';
        }

        /* ── 재진입 후보 (2026-08-04) — 편입 관심종목과 <b>단위가 다른</b> 별도 카드.
         *
         * ★★★두 목록의 관계(이 화면의 핵심 규칙):
         *     편입 관심종목 = <b>종목</b> 목록 · 질문은 「무엇을 새로 시작할까」 · 행동은 「선택」(새 포지션 생성)
         *     재진입 후보   = <b>포지션</b> 목록 · 질문은 「어느 자리를 다시 열까」 · 행동은 「재진입」(매수 기록)
         *   한 (포트폴리오, 종목) 조합은 <b>정확히 한쪽에만</b> 속한다 — 자리가 없으면 위, 있으면 아래다.
         *   같은 종목이 두 카드에 다 보일 수 있는데 그것은 모순이 아니라 <b>관심종목이 종목 단위</b>여서다
         *   (삼성전자는 관사장학회에 자리가 있고 관일산업엔 없다). 그래서 라벨은 늘 「이 포트폴리오 기준」이다.
         *
         * ★판정은 pf_reentry_list() <b>단일본</b>을 이 포트폴리오로 걸러 쓴다 —
         *   현황 「오늘의 신호」·매매히스토리 「재진입 검토」와 같은 값이어야 한다.
         * ★대기 중·「아직 비쌈」도 <b>함께 보여 준다</b>(사용자 결정) — 여기 온 사람은 「이 포트폴리오에
         *   뭘 담을 수 있나」를 보는 것이라, 아직 때가 아니어도 <b>그 자리가 이미 있다</b>는 사실을 알아야 한다.
         *   (현황 스트립이 ready 만 싣는 것과 다르다 — 거기는 「오늘 할 일」이라서다) */
        if ($reForm) {
            $nReady = count(array_filter($reForm, fn($x) => $x['chk']['state'] === 'ready'));
            echo '<div class="card" id="reCard"><h2>재진입 후보 '
               . '<span class="muted" style="font-size:12px;font-weight:600">' . count($reForm) . '건 — '
               . pf_h($preName !== '' ? $preName : '이 포트폴리오') . '에서 전량 매도한 자리</span>';
            if ($nReady) echo ' <span class="sig-pill k-re">재진입 검토 ' . $nReady . '건</span>';
            echo '</h2>';
            /* 열 순서는 위 편입 관심종목과 같다 — 나란히 놓인 두 표가 서로 다른 순서면 눈이 두 번 적응해야 한다.
             * ★「재진입가」 = 청산가 × (1 − 하락요건). 요건을 <b>가격으로</b> 적어 두면
             *   「청산가 대비 −2.2% 인데 요건이 −9%」를 머리로 환산하지 않아도 된다(다음매수가와 같은 결). */
            echo '<div class="tbl-scroll"><table class="pf"><thead><tr>'
               . '<th>종목명</th><th></th><th class="num">청산일</th><th class="num">경과</th>'
               . '<th class="num">청산가</th><th class="num">현재가</th><th class="num">청산가 대비</th>'
               . '<th class="num">하락요건</th><th class="num">재진입가</th><th>상태</th></tr></thead><tbody>';
            foreach ($reForm as $x) {
                $ck  = $x['chk'];
                $hit = ($ck['state'] === 'ready');
                echo '<tr' . ($hit ? ' style="background:#fff4f3"' : '') . '>';
                echo '<td class="stk"><a href="/stock/index.php?mode=position&id=' . (int)$x['id'] . '">'
                   . pf_h($x['stock_name']) . '</a><span class="code">' . pf_h((string)$x['stock_code']) . '</span></td>';
                /* ★★버튼은 <b>요건을 다 갖췄을 때만</b>(2026-08-04 사용자). 위 「선택」과 같은 규칙 —
                 *   할 수 있을 때만 보인다. 못 갖춘 행에서 눌리는 버튼은 「눌러도 되나?」를 남긴다.
                 *   막다른 골목이 되지는 않는다 — 종목명이 그대로 그 포지션 링크다. */
                echo '<td class="center wl-act">'
                   . ($hit
                        ? '<a class="wl-pill re" href="/stock/index.php?mode=position&id=' . (int)$x['id'] . '" '
                          . 'title="그 포지션으로 — 차수 행을 눌러 매수를 기록하면 종료 상태가 자동으로 풀립니다'
                          . '(한 포트폴리오에 같은 종목은 하나뿐이라 새로 추가하지 않습니다)">재진입</a>'
                        : '')
                   . '</td>';
                echo '<td class="num muted">' . pf_h(substr((string)$x['last_sell_at'], 2, 8)) . '</td>';
                echo '<td class="num' . ($ck['days_ok'] ? '' : ' muted') . '">' . (int)$x['days'] . '일</td>';
                echo '<td class="num muted">' . pf_n($x['last_sell_price'] === null ? null : round($x['last_sell_price'])) . '</td>';
                echo '<td class="num">' . pf_n($x['last']) . '</td>';
                echo '<td class="num">' . pf_signed_pct($ck['gap']) . '</td>';
                echo '<td class="num muted">−' . number_format($x['req'] * 100, 1) . '%</td>';
                echo '<td class="num' . ($ck['price_ok'] ? '' : ' muted') . '">'
                   . pf_n($ck['need_price'] === null ? null : round($ck['need_price'])) . '</td>';
                echo '<td>' . pf_reentry_state_badges($ck) . '</td></tr>';
            }
            echo '</tbody></table></div>';
            echo '<p class="sub muted" style="margin:8px 0 0;font-size:12px">'
               . '<b>재진입 요건은 둘이고 <u>둘 다</u> 갖춰야 합니다</b> — ① 청산일로부터 <b>'
               . PF_SIM_WAIT_DEFAULT . '일</b> 경과 ② 현재가 ≤ <b>재진입가</b>(＝청산가 × (1 − 하락요건)). '
               . '<b>「재진입」 버튼은 둘 다 갖춘 행에만</b> 나오고, 아직이면 상태 칸에 <b>못 갖춘 것만</b> 뜹니다'
               . '(「대기 6일」·「값 6.9%↓」 = 여기서 그만큼 더 내려야 함). 그 전에도 종목명을 누르면 그 포지션으로 갑니다.<br>'
               . '위 <b>편입 관심종목</b>이 「무엇을 <b>새로</b> 시작할까」라면, 여기는 「어느 <b>자리를 다시</b> 열까」입니다. '
               . '한 포트폴리오에 같은 종목은 하나뿐이라 청산한 종목은 새로 추가되지 않고 <b>그 포지션이 다시 열립니다</b> — '
               . '「재진입」으로 들어가 차수 행을 눌러 매수를 기록하면 종료 상태가 자동으로 풀립니다.<br>'
               . '판정 기준(대기 ' . PF_SIM_WAIT_DEFAULT . '일 + 청산가 대비 하락요건)은 '
               . '<a href="/stock/index.php">현황</a>의 「오늘의 신호」·'
               . '<a href="/stock/index.php?mode=hist&fid=' . $preFid . '">매매히스토리</a>와 <b>같은 판정</b>이며, '
               . '기준을 바꿔 보려면 매매히스토리에서 조정합니다. 하락요건 기본값은 그 종목 <b>룰셋의 2차 하락률</b>입니다.</p>';
            echo '</div>';
        }
    }

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

/* ── 퀀트 사다리 — 후보 불러오기(차트 동반) → 3~5개 선택 → 비중 풀기 → 저장에 실림 ── */
var BX_CANDS = [], BX_DC = null, BX_PB = null, BX_LOADED = '';
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
  pfBoxEnsure().then(function(dc){
    if (dc && BX_LOADED !== code) {
      dc.setCode(code);                       // SUE 공시 마커 — 종목이 바뀌면 모듈이 다시 받는다
      DailyChart.fetchDaily(code, 1000).then(function(rows){
        if (!rows.length) return;
        BX_LOADED = code;
        dc.setData(rows);
        pfBoxDrawLines();
      }).catch(function(){});
    }
    pfBoxCands();
  });
}
/* 기간바 상태 → 탐지 창(개월수) — 차트에 보이는 창과 후보 목록이 항상 같은 기간을 본다 */
function pfBoxWin(){
  var st = BX_PB ? BX_PB.state() : null;
  var P = { day: [160, 240, 480, 0], week: [24, 48, 96, 0] };
  var tf = st ? st.tf : 'day';
  var n = (st && st.bars !== null) ? st.bars : (st ? P[tf][st.index] : 240);
  if (!n) return { tf: tf, m: 48 };                       // 전체 = 데이터가 있는 만큼
  return { tf: tf, m: Math.max(2, Math.round(tf === 'week' ? n / 4.33 : n / 20.8)) };
}
function pfBoxCands(){
  var code = pfBoxCode();
  if (!/^\d{6}$/.test(code)) return;
  var list = document.getElementById('bxList');
  var w = pfBoxWin();
  list.innerHTML = '<span class="muted">불러오는 중…</span>';
  fetch('/stock/api.php?module=position&action=boxlv&code=' + code + '&months=' + w.m + '&tf=' + w.tf)
    .then(function(r){ return r.json(); })
    .then(function(j){
      if (j.err) { list.innerHTML = '<span class="down">' + j.err + '</span>'; return; }
      BX_CANDS = j.candidates || [];
      if (!BX_CANDS.length) { list.innerHTML = '<span class="muted">이 기간에 박스가 없습니다 — 기간을 늘리거나 주봉으로 바꿔 보세요.</span>'; return; }
      var h = '<table class="pf"><thead><tr><th>최고 거래대금 박스<br><span class="muted">(신호일)</span></th>'
            + '<th class="num">상단 H</th><th class="num">하단 L(지지)</th></tr></thead><tbody>';
      BX_CANDS.forEach(function(b){
        h += '<tr><td>' + b.d + '</td>'
           + '<td class="num"><label><input type="checkbox" class="bx-pick" value="' + b.h + '"> ' + Number(b.h).toLocaleString() + '</label></td>'
           + '<td class="num"><label><input type="checkbox" class="bx-pick" value="' + b.l + '"> <b>' + Number(b.l).toLocaleString() + '</b></label></td></tr>';
      });
      list.innerHTML = h + '</tbody></table>';
      list.querySelectorAll('.bx-pick').forEach(function(cb){ cb.addEventListener('change', pfBoxPickChange); });
      pfBoxPickChange();
    })
    .catch(function(){ list.innerHTML = '<span class="down">조회 실패</span>'; });
}
function pfBoxPicked(){
  // 체크한 후보 + 직접 입력한 지지선 — 비중 풀기·선 그리기·개수 판정이 전부 이 하나를 본다
  return Array.prototype.map.call(document.querySelectorAll('.bx-pick:checked'), function(cb){ return parseFloat(cb.value); })
         .concat(BX_CUSTOM);
}
/* ── 지지선 직접 입력 — 후보 조합이 안 풀릴 때 더 낮은 바닥을 손으로 추가한다 ── */
var BX_CUSTOM = [];
function pfBoxAdd(){
  var el = document.getElementById('bxAddPx');
  var cnt = document.getElementById('bxPickCnt');
  var v = parseFloat(String(el.value).replace(/,/g, ''));
  if (!(v > 0)) { if (cnt) cnt.textContent = '가격을 숫자로 입력하세요'; return; }
  if (pfBoxPicked().indexOf(v) >= 0) { if (cnt) cnt.textContent = '이미 고른 가격입니다'; el.value = ''; return; }
  // 후보 목록에 같은 값이 있으면 그 체크를 켜는 것으로 갈음 — 같은 가격이 두 줄로 살면 헷갈린다
  var same = null;
  document.querySelectorAll('.bx-pick').forEach(function(cb){ if (parseFloat(cb.value) === v) same = cb; });
  if (same) same.checked = true;
  else {
    if (pfBoxPicked().length >= 5) { if (cnt) cnt.textContent = '5개까지만!'; return; }
    BX_CUSTOM.push(v);
    BX_CUSTOM.sort(function(a, b){ return b - a; });
    pfBoxCustRender();
  }
  el.value = '';
  pfBoxPickChange();
  pfBoxAutoSolve();
}
function pfBoxCustDel(i){
  BX_CUSTOM.splice(i, 1);
  pfBoxCustRender();
  pfBoxPickChange();
  pfBoxAutoSolve();
}
function pfBoxCustRender(){
  var box = document.getElementById('bxCust');
  if (!box) return;
  box.innerHTML = BX_CUSTOM.map(function(v, i){
    return '<span class="bx-cust">' + Number(v).toLocaleString()
         + '<button type="button" onclick="pfBoxCustDel(' + i + ')" title="이 지지선 빼기">×</button></span>';
  }).join('');
}
// 3~5개가 갖춰져 있을 때만 곧바로 다시 푼다 — 모자라면 조용히 기다린다(고르는 중이다)
function pfBoxAutoSolve(){
  var n = pfBoxPicked().length;
  if (n >= 3 && n <= 5) pfBoxSolve();
}
(function(){
  var el = document.getElementById('bxAddPx');
  if (el) el.addEventListener('keydown', function(e){
    // Enter 가 폼 제출로 새면 입력하던 화면이 통째로 날아간다
    if (e.key === 'Enter') { e.preventDefault(); pfBoxAdd(); }
  });
})();
function pfBoxPickChange(){
  var n = pfBoxPicked().length;
  var el = document.getElementById('bxPickCnt');
  if (el) el.textContent = n ? '선택 ' + n + '개' + (n > 5 ? ' — 5개까지만!' : '') : '';
  pfBoxDrawLines();
}
/* 차트 — 후보선(회색 파선)·선택선(파랑 실선)을 그려 자리를 보고 고른다.
 * 구성은 차트설정(갤러리) ②포트폴리오형 기준 + 차트틀·지표는 포지션 화면과 공유(key:'position').
 * 기간바는 다른 화면과 같은 공용 컴포넌트 — 일봉/주봉·기간을 바꾸면 후보 탐지도 그 창으로 따라간다. */
function pfBoxEnsure(){
  if (!window.DailyChart) return Promise.resolve(null);
  return DailyChart.load().then(function(){
    if (BX_DC) return BX_DC;
    BX_DC = DailyChart.create('bxChart', { theme: 'light', markers: { chips: true }, key: 'position', height: 300 });
    if (!BX_DC) return null;
    var initing = true;   // periodBar 생성 시 apply()가 한 번 도는데, 그때는 pfBoxLoad 쪽이 곧 탐지한다
    BX_PB = DailyChart.periodBar('bxPBar', BX_DC, {
      theme: 'light', defaultIndex: 1,                     // 240일 ≈ 옛 기본 「1년」과 같은 창
      fullscreen: DailyChart.feats('fullscreen'),
      onChange: function(){ if (!initing) pfBoxCands(); }  // 일봉/주봉·기간·± 클릭 = 즉시 재탐지
    });
    initing = false;
    DailyChart.indicatorBar('bxIBar', BX_DC, { theme: 'light', key: 'position',
                                               preset: DailyChart.feats('preset.select') });
    return BX_DC;
  }).catch(function(){ return null; });
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
  // 직접 입력한 지지선 — 늘 「선택됨」이므로 선택선과 같은 모양
  BX_CUSTOM.forEach(function(v){ lines.push({ price: v, color: '#1d5c93', width: 2, style: 'solid' }); });
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
      if (j.err) {
        pv.innerHTML = '<span class="down">' + j.err + '</span>'
                     + '<div class="muted" style="font-size:12px;margin-top:4px">맞는 후보가 없으면 왼쪽 '
                     + '「지지선 직접 입력」으로 더 낮은 바닥을 추가해 보세요 — 넣으면 바로 다시 풉니다.</div>';
        return;
      }
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
  if (n < 3) { e.preventDefault(); alert('퀀트 사다리: 지지선 3~5개를 고르고 「비중 풀기」까지 눌러 주세요.'); }
});
pfBoxToggle();
</script>
<script src="/style/dailychart.js?v=56"></script>
<style>
.fld-fixed{padding:7px 10px;background:#f2f6fa;border:1px solid #e0e8f0;border-radius:6px;
  font-size:13px;font-weight:700;color:#22303f;min-width:120px}
/* 직접 입력한 지지선 칩 — 선택선(파랑)과 같은 계열, ×로 뺀다 */
.bx-cust{display:inline-flex;align-items:center;gap:3px;margin:2px 6px 2px 0;padding:3px 5px 3px 10px;
  border:1px solid #1d5c93;border-radius:14px;background:#eef5fb;color:#12406b;
  font-weight:700;font-size:12.5px;font-variant-numeric:tabular-nums}
.bx-cust button{border:none;background:none;color:#8aa0b5;font-size:14px;line-height:1;
  cursor:pointer;padding:0 3px;font-family:inherit}
.bx-cust button:hover{color:#c0392b}
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
    // 기능 구성 — 이 화면(퀀트 사다리)의 도구모음. 박스 후보선은 화면 성립 조건이라 잠금(ChartFeat)
    echo ChartFeat::boot('boxladder');

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

            echo '<td class="stk"><a href="' . $href . '">' . pf_h($d['stock_name'] ?: $d['stock_code']) . '</a>'
               . '<span class="code">' . pf_h($d['stock_code']) . '</span>'
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

    /* ── 실계좌 체결 겹치기 (2026-08-04 · ChartFeat: overlay.real_trades).
     *
     * 왜: 시뮬레이터 성과와 실계좌 성과가 갈릴 때 <b>「룰셋이 나쁜 건가, 내가 계획을 안 지킨 건가」</b>를
     *     가를 화면이 없었다. 계획 체결(아래 $marks)과 실제 주문을 같은 시간축에 겹치면 그게 바로 보인다.
     *
     * ★★★ <b>두 체결은 같은 조건이 아니다</b> — 시뮬은 종목 단위·한도 통일·재진입 O·장중체결 O 이고,
     *     실계좌는 포트폴리오별 한도·차수 지연·박스 사다리·수동 개입이 섞여 있다.
     *     그래서 <b>겹쳐 보기까지만</b> 하고 「사다리 대비 −N%」 같은 수치는 만들지 않는다 —
     *     그 숫자는 내 이탈이 아니라 조건 차이를 재게 된다. 한계는 차트 아래에 그대로 밝힌다.
     * ★ 같은 날·같은 방향은 한 마커로 묶는다(단가는 <b>가중평균</b> — 수량이 다르면 단순평균은 틀린다).
     *   종목 상세 일봉 칩과 같은 규칙이다. 다만 여기는 폭이 없어 차수까지만 적는다.
     * ★★ 시세 구간 <b>밖</b>의 체결은 버린다 — 앞으로 당겨 붙이면 첫 봉에 몰려 왼쪽 끝에
     *   마커가 일렬로 서고 「그 날 그 값에 샀다」는 거짓 그림이 된다(SUE 마커에서 겪은 것과 같은 함정).
     * ★ 이 종목을 실제로 산 적이 없으면 <b>조용히 생략</b>한다 — 빈 범례·안내를 만들지 않는다.
     */
    $realMarks = [];
    $sDates    = array_column($res['equity'], 'd');
    if ($sDates && ChartFeat::vals('sim', $pdo)['overlay.real_trades']) {
        $first = (string)$sDates[0];
        $last  = (string)$sDates[count($sDates) - 1];

        $agg = [];
        foreach ($pf->tradeHistory(0, '', 2000) as $t) {
            if ((string)$t['stock_code'] !== (string)$data['stock_code']) continue;
            $d = substr((string)$t['traded_at'], 0, 10);
            if ($d < $first || $d > $last) continue;          // 실린 봉 밖 — 조용히 버린다
            $k = $d . '|' . $t['side'];
            if (!isset($agg[$k])) $agg[$k] = ['d' => $d, 'sell' => ($t['side'] === 'sell') ? 1 : 0,
                                              'qty' => 0, 'amt' => 0.0, 'step' => 0];
            $agg[$k]['qty']  += (int)$t['qty'];
            $agg[$k]['amt']  += (float)$t['price'] * (int)$t['qty'];
            $agg[$k]['step']  = max($agg[$k]['step'], (int)$t['step_no']);
        }
        ksort($agg);                                          // setMarkers 는 시간 오름차순을 요구한다
        foreach ($agg as $a) {
            // 체결일이 이 시세에 없는 날(정지·결손)이면 <b>다음</b> 거래일 봉에 붙인다
            $on = null;
            foreach ($sDates as $sd) { if ((string)$sd >= $a['d']) { $on = (string)$sd; break; } }
            if ($on === null) continue;
            $realMarks[] = [$on, $a['sell'], $a['step'], $a['qty'],
                            ($a['qty'] > 0) ? round($a['amt'] / $a['qty']) : null, $a['d']];
        }
    }

    // ── 차트
    echo '<div class="card"><div class="sim-head"><h2 style="margin:0">주가 · 체결</h2>';
    echo '<span id="simIBar"></span>';
    // 기간 바(일봉/주봉·160일~전체·±·전체화면)는 모듈이 통째로 그린다 — 옛 주봉·⛶ 버튼 대체
    echo '<span id="simPBar"></span>';
    // 「전체 기간」은 화면별 구성(ChartFeat: period.full_range) — 시뮬레이터만 기본 켜짐
    if (ChartFeat::vals('sim', $pdo)['period.full_range']) {
        echo '<button type="button" class="btn btn-outline btn-sm" id="simZoomAll" onclick="pfSimZoomAll()">전체 기간</button>';
    }
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
    if ($realMarks) echo '<span class="cl-item"><b>실</b> 실계좌 체결 ' . count($realMarks) . '</span>';
    if ($hasVol) echo '<span class="cl-item"><i style="background:#c9d4de"></i> 거래량</span>';
    echo '<span id="simLegend"></span>';   // 지표 값 표시 자리
    echo '</div>';
    echo '<div id="simPrice" style="height:340px;position:relative"></div>';
    /* ★★★ 해석 가드 — 이 한 줄이 없으면 겹친 그림이 곧바로 「사다리 대비 몇 % 손해」로 읽힌다.
     *   두 체결은 조건이 다르므로 <b>수치 비교가 성립하지 않는다</b>. 무엇이 다른지 그 자리에 적는다. */
    if ($realMarks) {
        echo '<p class="sub muted" style="margin:9px 0 0;font-size:12px">'
           . '<b>실</b> 로 시작하는 마커는 이 종목에서 <b>실제로 체결한 주문</b>입니다(같은 날·같은 방향은 묶고 단가는 가중평균). '
           . '계획 마커와 나란히 보면 <b>내가 계획보다 이르게/늦게 담았는지</b>가 그대로 보입니다.<br>'
           . '★ <b>수익률을 견주지 마세요.</b> 시뮬레이터는 <b>종목 단위 · 한도 통일 · 재진입 O · 장중 체결 O</b> 조건이고, '
           . '실계좌에는 포트폴리오별 한도 · 차수 지연 · 박스 사다리 · 수동 개입이 섞여 있습니다. '
           . '두 성과의 차이는 <b>내 이탈이 아니라 조건 차이</b>일 수 있어, 이 화면은 <b>시점만</b> 견주도록 만들었습니다.</p>';
    }
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
    echo 'const SIM_REAL='   . json_encode($realMarks, JSON_UNESCAPED_UNICODE) . ';';
    echo 'const SIM_CODE='   . json_encode((string)$data['stock_code']) . ';';   // SUE 공시 마커용
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
    echo '<script src="/style/dailychart.js?v=56"></script>';
    echo ChartFeat::boot('sim');   // DC_SCREEN·DC_FEATS·DC_VIEW 를 한 줄에
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
    var F = DailyChart.feats;   // 화면별 기능 구성 (차트설정 > 화면별 구성)
    pc = DailyChart.create(ph, { theme: 'light', volAlpha: '44', markers: { chips: false },
                                 key: 'sim', code: (typeof SIM_CODE === 'string' ? SIM_CODE : ''),
                                 legend: F('legend.values') ? 'simLegend' : null });
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

    /* 체결 마커 — m[4] = 그 날 시장 상태 1개 (역배열·과매도 …). 없으면 차수만.
       ★ 계획(시뮬)과 실계좌를 <b>한 번의 setMarkers</b>로 넘긴다 — 두 번 부르면 뒤가 앞을 덮는다.
         라이브러리가 시간 오름차순을 요구하므로 합친 뒤 정렬한다(양쪽 다 이미 오름차순이라 합병 정렬). */
    var mk = [];
    if (F('overlay.trade_markers')) {
      SIM_MARKS.forEach(function(m){
        var isSell = (m[1] === 1);
        var base = isSell ? '매도' : (m[2] + '차');
        mk.push({ time: m[0], sell: isSell, text: m[4] ? (base + ' ' + m[4]) : base });
      });
    }
    /* 실계좌 체결 — r = [붙인일자, 매도여부, 차수, 수량, 가중평균단가, 실제체결일].
       ★ 「실」 접두어로 계획 마커와 갈라 읽는다. 값(수량·단가)은 툴팁이 없는 자리라 글자에 넣지 않고
         차수까지만 — 10년 구간에서 라벨이 겹치면 둘 다 못 읽는다(계획 마커와 같은 원칙). */
    if (typeof SIM_REAL !== 'undefined' && F('overlay.real_trades')) {
      SIM_REAL.forEach(function(r){
        var isSell = (r[1] === 1);
        mk.push({ time: r[0], sell: isSell, text: '실 ' + (isSell ? '매도' : (r[2] > 0 ? r[2] + '차' : '매수')) });
      });
    }
    if (mk.length) {
      mk.sort(function(a, b){ return a.time < b.time ? -1 : (a.time > b.time ? 1 : 0); });
      pc.setMarkers(mk);
    }

    /* 기간 바 — 다른 화면과 같은 세그먼트 [일봉|주봉 ┃ 160일…전체 ┃ ± ┃ ⛶].
       시뮬은 구간 전체를 보는 게 기본이라 「전체」(index 3)로 시작한다 (옛 동작 유지).
       주봉 토글·전체화면 버튼은 이 바가 대체했고, 바는 전체화면에 스스로 따라간다. */
    DailyChart.periodBar('simPBar', pc, { theme: 'light', defaultIndex: 3,
                                          fullscreen: F('fullscreen') });
    DailyChart.indicatorBar('simIBar', pc, { theme: 'light', key: 'sim',
                                             preset: F('preset.select') });   // 사용자 지표 + 차트틀
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
/* SUE 계산(pf_sue_build/map/qv/stock)은 lib/sue.php 로 옮겼다(2026-08-02) —
 * 알림 크론(lib/alert.php)이 두 번째 소비자가 되어서다. 정의·규칙은 그 파일 머리에. */

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
    // 머리글 정렬 상태(SUE·공시후) — 공시 창을 바꿔도 유지되게 hidden 으로 같이 싣는다
    $eSort = in_array($_GET['sort'] ?? '', ['sue', 'drift'], true) ? (string)$_GET['sort'] : '';
    $eDesc = ((string)($_GET['dir'] ?? 'desc') !== 'asc');
    echo '<div class="act"><form class="inline" method="get" action="/stock/index.php">'
       . '<input type="hidden" name="mode" value="earn">'
       . ($eSort !== '' ? '<input type="hidden" name="sort" value="' . pf_h($eSort) . '">'
           . (!$eDesc ? '<input type="hidden" name="dir" value="asc">' : '') : '')
       . '<label class="fld" style="flex-direction:row;align-items:center;gap:6px"><span>공시 창</span>'
       . '<select name="days" onchange="this.form.submit()">';
    foreach ([14, 30, 60, 90] as $d) {
        echo '<option value="' . $d . '"' . ($d === $days ? ' selected' : '') . '>' . $d . '일</option>';
    }
    echo '</select></label></form></div></div>';

    /* ★ 목록 생성은 pf_earn_rows() 단일본이다(2026-08-05 · lib/sue.php).
     *   알림(⑥ 규칙 충족 신규)이 두 번째 소비자가 되면서 뺐다 — 특히 「고변동 아님」이
     *   목록 안의 <b>상대</b> 상위 ⅓ 이라, 조건을 알림에 다시 적으면 재현이 불가능하고
     *   「알림은 규칙 충족이라는데 화면에선 고변동⚠」로 두 곳이 딴소리를 하게 된다. */
    $E = pf_earn_rows($pdo, $days);
    if (!$E['hasTbl']) {
        echo '<div class="warn">접수일 원장(dart_rcept)이 아직 없습니다 — SSH 에서 '
           . '<code>php cron/dart_collect.php job=rcept from=20160101</code> 을 한 번 돌리세요. '
           . '이후에는 매일 새벽 fresh 크론이 최근 21일을 같이 받습니다.</div>';
        pf_foot(); return;
    }
    if (!$E['filings']) {
        echo '<div class="card"><p class="muted" style="margin:0;font-size:13px">최근 ' . $days . '일 안의 정기공시가 없습니다 — '
           . '공시 창을 넓히거나, 분기 시즌(5월·8월·11월·3~4월)에 다시 보세요.</p></div>';
        pf_foot(); return;
    }

    $rows       = $E['rows'];
    $shock      = $E['shock'];
    $total      = $E['total'];
    $shockTotal = $E['shockTotal'];
    $okN        = $E['okN'];
    $okHighVol  = $E['okHighVol'];

    /* 박스 상태(퀀트 교차) — 각 종목의 최근 최고 거래대금 박스가 지금 어떤 상태인가.
     * 「실적(SUE)으로 고르고 수급(박스)으로 타이밍」 활용 흐름의 다리. 신호 이력이 없으면 '-'
     * (저유동 등 — 판정 불가지 나쁨이 아니다). 배지·툴팁은 퀀트 목록과 같은 boxStatusMany 재사용.
     * ★표시분(≤250종목 — 서프라이즈 200 + 쇼크 50)만 판정한다. 위 상한의 이유와 같다:
     *   상한 없이 그리면 수천 종목의 봉을 읽다 메모리를 터뜨린다(실측 90일 창 256MB 초과). */
    /* 배지 표시 설정(BadgeFeat) — 박스 열을 끄면 계산도 건너뛴다(이 화면에서 표시 말고는 아무도 안 쓴다).
     * SUE·보유는 잠금이라 스위치가 없다 — SUE 는 이 화면의 알맹이다. */
    $be = BadgeFeat::vals('earn', $pdo);
    $earnBox = [];
    if ($be['qpath']) try {
        $codes = array_values(array_unique(array_map(fn($r) => $r['code'], array_merge($rows, $shock))));
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

    // 배지 스타일(.bx)은 전역 pf_css() 에 있다 — 화면마다 복제하지 않는다

    /* 보유·관심 — 두 표가 함께 쓴다. 쇼크 목록에서 <b>행동으로 이어지는 줄은 이것뿐</b>이다
     * (안 살 종목은 무한하지만, 이미 가진 종목의 실적 전제가 깨진 것은 지금 볼 일이다). */
    $watched = $pf->watchCodes();
    /* ★ 판정은 pf_held_map() 단일본이다(2026-08-04) — 스크리너·퀀트·관심종목이 같은 것을 본다.
     *   전에는 Pf::positions() 로 따로 셌는데, 그쪽은 pf_stock·pf_rule_set 과 INNER JOIN 이라
     *   어느 하나가 비면 행이 통째로 사라져 보유 종목을 조용히 놓친다.
     *   두 표(규칙 충족·어닝 쇼크)가 함께 쓰므로 양쪽 종목을 한 번에 묻는다. */
    $held = pf_held_map($pdo, array_merge(array_column($rows, 'code'), array_column($shock, 'code')));

    /* ★SUE 1 미만은 표시하지 않는다(2026-08-12 사용자 지시 — 「1 이하는 보여주지 마」).
     * ─ 경계는 규칙과 같은 Thr::SUE_HIT(≈상위 20%) — 숫자를 여기 다시 적으면 사본이 된다.
     * ─ 걸러도 판정은 안 바뀐다: ok·고변동⅓ 경계는 pf_earn_rows() 가 표시분 전체(≤200)로
     *   이미 계산을 끝낸 값이다. 화면에서 모집단을 다시 좁히면 「알림은 규칙 충족이라는데
     *   화면에선 고변동⚠」로 두 곳이 갈라지므로, 계산은 단일본에 두고 화면은 고르기만 한다.
     * ─ ★보유·관심 종목은 예외로 남긴다 — 「담아 둔 게 이번 시즌에도 걸렸나」가 이 목록의
     *   첫 물음이라(맨 위 정렬의 이유) SUE 가 낮다고 조용히 사라지면 안 된다.
     * ─ 어닝 쇼크(≤ −1) 카드는 「피하는 목록」이라 이 필터와 무관하게 그대로다. */
    $sueHidden = 0;
    $kept = [];
    foreach ($rows as $r) {
        /* 예외로 남긴 행은 «왜 남았나»를 행 자체에 배지로 밝힌다(2026-08-12 사용자 지적) —
         * 머리글의 「보유·관심은 예외」만으로는 어느 줄이 그 예외인지 알 수 없다. */
        $r['sue_exempt'] = null;
        if ($r['sue'] < Thr::SUE_HIT) {
            if     (isset($held[$r['code']]))    $r['sue_exempt'] = '보유종목';
            elseif (isset($watched[$r['code']])) $r['sue_exempt'] = '관심종목';
            else { $sueHidden++; continue; }
        }
        $kept[] = $r;
    }
    $rows = $kept;
    unset($kept);

    /* 종목 태그·개요 헤드라인 (stock/lib/note.php) — 칩은 배지 설정('tag')을 따르고,
     * 헤드라인은 행 툴팁 맨 앞에 붙는다(배지가 아니라 스위치 없음 · 표시분만 묻는다). */
    $eTags = $be['tag'] ? pf_tags_of($pdo, array_column($rows, 'code')) : [];
    $eHead = pf_note_head_map($pdo, array_column($rows, 'code'));
    if ($eTags) pf_tag_css();

    echo '<div class="card"><h2>최근 공시 ' . number_format($total) . '건'
       . ' · <span class="up">규칙 충족 ' . $okN . '건</span>'
       . ($okHighVol > 0 ? ' <span class="muted" style="font-size:13px">(그중 고변동⚠ ' . $okHighVol . '건)</span>' : '')
       . ($sueHidden > 0 ? ' <span class="muted" style="font-size:13px">(SUE 1 미만 '
           . number_format($sueHidden) . '건 숨김 — 보유·관심은 예외)</span>' : '')
       . ($total > count($rows) + $sueHidden ? ' <span class="muted" style="font-size:13px">(SUE 상위 200건 밖 '
           . number_format($total - count($rows) - $sueHidden) . '건 제외)</span>' : '')
       . '</h2>';
    echo '<p class="sub muted" style="margin:0 0 8px;font-size:12px">'
       . '규칙 = <b>SUE ≥ 1(≈상위 20%) ∧ 품질(영업흑자·순익÷영익≤1.5) ∧ 매출 동반 증가 ∧ 거래대금 10억↑</b>'
       . ' <b>∧ 고변동 아님</b>(목록 내 60일 변동성 상위 ⅓ 제외 — 그 무리만 8년 실측 음수) — '
       . '전부 백테스트 실측 조건입니다. <b>공시후</b>는 공시 다음 거래일 종가에서 지금까지의 수익률, '
       . '<b>경과</b>는 그 뒤 지난 거래일 수입니다 (드리프트 실측 구간은 60거래일).</p>';

    if (!$rows) {
        // 「원래 없다」와 「숨겨서 없다」를 갈라 적는다 — 뭉뚱그리면 필터가 조용한 거짓말이 된다
        echo $sueHidden > 0
            ? '<p class="muted" style="font-size:13px;margin:0">SUE 1 이상인 공시가 없습니다 — 1 미만 '
                . number_format($sueHidden) . '건은 숨겼습니다 (규칙 경계 = 상위 20%).</p>'
            : '<p class="muted" style="font-size:13px;margin:0">SUE 를 계산할 수 있는 공시가 없습니다 (이력 4분기 미만 종목만 있음).</p>';
    } else {
        /* 정렬 = ①관심종목 → ②판정 → ③공시일 최신 → ④SUE 큰 순 (2026-08-15 사용자 지시).
         * ①은 스크리너와 같은 규칙 — "담아 둔 게 이번 시즌에도 걸렸나"가 SUE 순서에 묻히면 안 된다.
         * ★②는 내부 ok 값이 아니라 <b>판정 칸에 보이는 그대로</b>다 — 규칙 충족 → 고변동⚠ → 관망.
         *   ok 로만 갈랐더니 ok=true 인 고변동⚠ 이 「규칙 충족」 사이에 섞여, 눈에는 정렬이 안 된
         *   것으로 보였다(같은 날 사용자 지적). 화면이 보여 주는 말과 정렬 기준이 같아야 한다.
         * ★③은 같은 날 2차 지시 — 「최근에 올라온 규칙 충족」이 이 화면의 첫 물음이라
         *   SUE 크기보다 공시일이 먼저다(드리프트가 60거래일을 가니 갓 뜬 것부터 검토한다).
         * SUE·공시후 머리글을 누르면 ②③④ 대신 그 값 순(다시 누르면 반대) — 그때도 관심종목은 맨 위다. */
        $vRank = fn(array $r) => $r['ok'] ? ($r['high_vol'] ? 1 : 0) : 2;   // 판정 칸과 같은 분기
        usort($rows, function ($a, $b) use ($watched, $eSort, $eDesc, $vRank) {
            $wa = isset($watched[$a['code']]) ? 0 : 1;
            $wb = isset($watched[$b['code']]) ? 0 : 1;
            if ($wa !== $wb) return $wa <=> $wb;
            if ($eSort !== '') {
                $x = $a[$eSort]; $y = $b[$eSort];
                // null(공시후 = 다음 거래일이 아직 안 온 공시)은 방향과 무관하게 항상 뒤로 — 사이트 정렬 규칙
                if ($x === null || $y === null) {
                    if (($x === null) !== ($y === null)) return $x === null ? 1 : -1;
                } elseif ($x != $y) {
                    return $eDesc ? ($y <=> $x) : ($x <=> $y);
                }
                return [$vRank($a), $b['dt']] <=> [$vRank($b), $a['dt']];   // 동률은 기본 정렬로
            }
            return [$vRank($a), $b['dt'], $b['sue']] <=> [$vRank($b), $a['dt'], $a['sue']];
        });
        /* SUE·공시후 머리글이 클릭 정렬을 연다 (공시후는 2026-08-30 사용자 요청).
         * 누르면 그 값 순 · 다시 누르면 반대, 링크를 떼면(mode=earn 만) 기본 정렬로 돌아온다. */
        $eTh = function (string $key, string $label, string $tip) use ($days, $eSort, $eDesc) {
            $on = ($eSort === $key);
            return '<th class="num"><a href="' . pf_h('/stock/index.php?mode=earn&days=' . $days
                . '&sort=' . $key . (($on && $eDesc) ? '&dir=asc' : '')) . '" '
                . 'title="' . pf_h($tip . ' — 누르면 이 값 순으로 정렬합니다 (다시 누르면 반대 방향 · 관심종목은 늘 맨 위)') . '">'
                . pf_h($label) . ($on ? ($eDesc ? ' ▾' : ' ▴') : '') . '</a></th>';
        };
        /* ★열 골격은 관심종목·스크리너와 같다(2026-08-04) — ①종목 → ②상태(늘 하나) → … → ③☆ 는 끝. */
        echo '<div class="tbl-scroll" style="max-height:70vh;overflow-y:auto"><table class="pf"><thead><tr>'
           . '<th>종목</th>'
           . '<th class="center" style="width:78px;white-space:nowrap" '
             . 'title="이 종목이 내 포트폴리오에 있나 — 편입 · 보유 · 편입됨 셋 중 하나">포트폴리오</th>'
           . '<th class="num">공시일</th><th>보고서</th>'
           . $eTh('sue', 'SUE', '표준화 이익 서프라이즈')
           . '<th class="num" title="당분기 매출이 전년동기보다 늘었나">매출</th>'
           . '<th class="num" title="YTD 순이익 ÷ 영업이익 — 1.5 초과면 일회성 의심">순÷영</th>'
           . '<th class="num">시총</th><th class="num" title="최근 거래일 거래대금">거래대금</th>'
           . $eTh('drift', '공시후', '공시 다음 거래일 종가 → 현재 수익률')
           . '<th class="num" title="공시 후 지난 거래일 수 / 드리프트 실측 구간 60일">경과</th>'
           . '<th class="num" title="최근 60거래일 일수익률 표준편차 — 이 목록 안에서 상위 ⅓이면 고변동⚠ (백테스트: 규칙 충족이라도 고변동⅓은 −1.6%로 독)">변동성</th>'
           . ($be['qpath'] ? '<th class="center" title="그 종목의 최근 최고 거래대금 박스가 지금 어떤 상태인가 (퀀트 탭과 같은 판정) — 실적으로 고르고 수급으로 타이밍을 봅니다">최고 거래대금 박스</th>' : '')
           . '<th>판정</th>'
           . '<th class="center" style="width:34px" title="관심종목에 담기/빼기">☆</th>'
           . '</tr></thead><tbody>';
        $rcName = ['11013' => '1Q', '11012' => '반기', '11014' => '3Q', '11011' => '연간'];
        foreach ($rows as $r) {
            $won = isset($watched[$r['code']]);
            echo '<tr class="fund-row' . ($won ? ' watched' : '') . '" data-code="' . pf_h($r['code']) . '" style="cursor:pointer" '
               . 'title="' . pf_h((isset($eHead[$r['code']]) ? $eHead[$r['code']] . ' — ' : '') . '클릭하면 재무 상세를 봅니다') . '">';
            echo '<td class="stk"><b>' . pf_h($r['name']) . '</b>'
               . ($r['sue_exempt'] !== null
                   ? ' <span class="badge" style="background:#fff8e1;color:#b26a00;font-size:11px" title="'
                     . pf_h('SUE 1 미만이지만 ' . $r['sue_exempt'] . '이라 숨기지 않았습니다 — 담아 둔 종목의 공시는 값과 무관하게 봐야 합니다')
                     . '">' . $r['sue_exempt'] . '</span>' : '')
               . '<span class="code">' . pf_h($r['code']) . '</span>'
               . (isset($eTags[$r['code']]) ? '<span class="stagln">' . pf_tag_chips($eTags[$r['code']], '', 2) . '</span>' : '')
               . '</td>';
            // ② 포트폴리오 — 편입 / 보유 / 편입됨 (편입 버튼은 행 클릭과 겹쳐 JS 가 캡처 단계에서 전파를 끊는다)
            echo '<td class="center" style="white-space:nowrap">'
               . pf_adopt_cell((string)$r['code'], (string)$r['name'], $held[$r['code']] ?? null, [],
                    '포트폴리오에 편입 — 종목 추가 폼이 열립니다 · '
                    . '포트폴리오 미지정 저장 = 편입 관심종목 (실적으로 골랐으니 지지선으로 매복)') . '</td>';
            /* NEW = 공시 후 1거래일 이내(경과 없음 = 다음 거래일이 아직 안 온 것 — 가장 새것).
             * 「최근에 올라온 규칙 충족」을 날짜를 읽지 않고도 집게 한다(2026-08-15 사용자 요청). */
            $isNew = ($r['elapsed'] === null || $r['elapsed'] <= 1);
            echo '<td class="num" style="white-space:nowrap">' . pf_h($r['dt'])
               . ($isNew ? ' <span class="badge" style="background:#fde7e9;color:#c62828;font-size:10px" '
                   . 'title="공시 후 1거래일 이내 — 갓 올라온 공시입니다">N</span>' : '') . '</td>';
            echo '<td>' . $r['y'] . ' ' . ($rcName[$r['rc']] ?? $r['rc']) . '</td>';
            echo '<td class="num">' . ($r['sue'] >= Thr::SUE_HIT ? '<b>' . number_format($r['sue'], 2) . '</b>' : number_format($r['sue'], 2)) . '</td>';
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
            if ($be['qpath']) {   // 배지 표시 설정 — 끄면 열째로 사라진다
                echo '<td class="center">' . ($earnBox[$r['code']] ?? '<span class="muted" style="font-size:12px" '
                        . 'title="최근 최고 거래대금 신호가 없는 종목 — 판정 불가(나쁨이 아님)">-</span>') . '</td>';
            }
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
            // ③ ☆ — 토글이라 동선의 끝(관심종목·스크리너와 같은 자리)
            echo '<td class="center"><button type="button" class="wl-star' . ($won ? ' on' : '') . '"'
               . ' data-code="' . pf_h($r['code']) . '" data-name="' . pf_h($r['name']) . '"'
               . ' title="관심종목에 담기/빼기">' . ($won ? '★' : '☆') . '</button></td>';
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

    pf_earn_shock_card($shock, $shockTotal, $days, $earnBox, $watched, $held);

    // ☆·편입·행 클릭 — 스크리너와 같은 패턴 (☆·편입은 캡처 단계에서 전파를 끊는다)
    echo <<<'JS'
<script>
document.addEventListener('click', function(e){
  var a = e.target.closest ? e.target.closest('.pf-adopt') : null;
  if (a) {
    e.stopPropagation(); e.preventDefault();
    location.href = '/stock/index.php?mode=position&id=new&src=earn&bm=box'
      + '&code=' + encodeURIComponent(a.getAttribute('data-code'))
      + '&name=' + encodeURIComponent(a.getAttribute('data-name'));
    return;
  }
  var b = e.target.closest ? e.target.closest('.wl-star') : null;
  if (!b) return;
  e.stopPropagation();
  e.preventDefault();
  var body = new URLSearchParams({json:'1', src:'earn', code:b.getAttribute('data-code'), name:b.getAttribute('data-name')});
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
  if (!tr || (e.target.closest && (e.target.closest('a') || e.target.closest('.wl-star') || e.target.closest('.pf-adopt')))) return;
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
 * 어닝 쇼크 — 어닝 서프라이즈 화면 <b>하단의 회피 목록</b> (2026-08-03 신설).
 *
 * ★ <b>왜 별도 메뉴가 아닌가</b> — 쇼크는 이미 위 목록 안에 있었다. 정렬이 (충족 → SUE 큰 순)이고
 *   200행에서 자르니 <b>시즌마다 꼬리째 잘려</b> 안 보였을 뿐이다. 상·하위는 한 분포의 두 끝이라
 *   같은 창(days)·같은 접수일 원장·같은 계산을 쓰는데, 화면을 가르면 두 화면이 어긋날 자리만 생긴다.
 * ★ <b>이 목록 자체엔 행동이 없다</b> — 안 살 종목은 무한하다. 행동으로 이어지는 줄은
 *   「내가 이미 가진 것에 쇼크가 떴다」 하나뿐이라 <b>보유·관심을 맨 위로</b> 올리고 태그를 붙인다.
 *   (같은 사실을 보유종목·현황의 종목명 옆 배지와 Pushover 알림도 말한다 — 여기는 시장 전체 조망.)
 * ★ ☆·편입 버튼은 두지 않는다 — 회피 목록에서 담기·편입은 뜻이 어긋난다. 행 클릭(재무 상세)만 산다.
 */
function pf_earn_shock_card(array $shock, int $total, int $days, array $box, array $watched, array $held): void
{
    echo '<div class="card" style="margin-top:14px">';
    echo '<h2>어닝 쇼크 <span class="muted" style="font-size:14px;font-weight:600">· 회피 목록</span> '
       . '<span class="down">' . number_format($total) . '건</span>'
       . ($total > count($shock) ? ' <span class="muted" style="font-size:13px">(SUE 낮은 순 '
            . count($shock) . '건만 표시)</span>' : '')
       . '</h2>';
    echo '<p class="sub muted" style="margin:0 0 8px;font-size:12px">'
       . '쇼크 = <b>SUE ≤ −1</b>(≈하위 20%) — 8년 백테스트에서 <b>거의 매년 음수</b>였고 '
       . '2022 하락장에서는 +60거래일 <b>−3.2%</b>(같은 구간 시장 −1.6%)로 시장보다 더 빠졌습니다. '
       . '서프라이즈처럼 <b>두 달에 걸쳐 천천히</b> 반영되므로 공시 당일을 놓쳐도 늦은 것이 아닙니다. '
       . '<b>자동 매도는 없습니다</b> — 실적 전제가 깨졌는지 다시 보라는 목록입니다.</p>';

    if (!$shock) {
        echo '<p class="muted" style="font-size:13px;margin:0">최근 ' . $days . '일 안에 SUE ≤ −1 인 공시가 없습니다.</p></div>';
        return;
    }

    /* 보유·관심을 맨 위로 — 위 표가 관심종목을 올리는 것과 같은 규칙.
     * 같은 무리 안에서는 SUE 나쁜 순(이미 그렇게 정렬돼 들어온다)을 지킨다. */
    usort($shock, function ($a, $b) use ($watched, $held) {
        $ra = isset($held[$a['code']]) ? 0 : (isset($watched[$a['code']]) ? 1 : 2);
        $rb = isset($held[$b['code']]) ? 0 : (isset($watched[$b['code']]) ? 1 : 2);
        return $ra <=> $rb;
    });
    $mineN = count(array_filter($shock, fn($r) => isset($held[$r['code']]) || isset($watched[$r['code']])));
    if ($mineN > 0) {
        echo '<p class="warn" style="margin:0 0 8px;font-size:13px">'
           . '<b>보유·관심 종목 ' . $mineN . '건</b>이 이 목록에 있습니다 — 맨 위에 올려 두었습니다. '
           . '실적 전제가 깨졌는지 확인하세요(수급 구조인 <b>계단관통↓</b>와 겹치면 손절을 판단하는 자리입니다).</p>';
    }

    echo '<div class="tbl-scroll" style="max-height:60vh;overflow-y:auto"><table class="pf"><thead><tr>'
       . '<th>종목</th><th class="num">공시일</th><th>보고서</th><th class="num">SUE</th>'
       . '<th class="num" title="당분기 매출이 전년동기보다 늘었나 — 매출까지 꺾였으면 이익만의 문제가 아닙니다">매출</th>'
       . '<th class="num" title="YTD 순이익 ÷ 영업이익">순÷영</th>'
       . '<th class="num">시총</th><th class="num" title="최근 거래일 거래대금">거래대금</th>'
       . '<th class="num" title="공시 다음 거래일 종가 → 현재. 쇼크에서는 이미 얼마나 빠졌나를 봅니다">공시후</th>'
       . '<th class="num" title="공시 후 지난 거래일 수 / 드리프트 실측 구간 60일">경과</th>'
       // 배지 표시 설정(BadgeFeat) — 본표와 같은 스위치를 본다(두 표가 다른 말을 하면 안 된다)
       . (BadgeFeat::vals('earn')['qpath'] ? '<th class="center" title="그 종목의 최근 최고 거래대금 박스 상태 (퀀트 탭과 같은 판정)">최고 거래대금 박스</th>' : '')
       . '</tr></thead><tbody>';

    $rcName = ['11013' => '1Q', '11012' => '반기', '11014' => '3Q', '11011' => '연간'];
    foreach ($shock as $r) {
        $mine = isset($held[$r['code']]) || isset($watched[$r['code']]);
        echo '<tr class="fund-row' . ($mine ? ' watched' : '') . '" data-code="' . pf_h($r['code']) . '" '
           . 'style="cursor:pointer" title="클릭하면 재무 상세를 봅니다">';
        // 보유/편입됨은 위 표의 「포트폴리오」 칸과 <b>같은 배지</b>다 — 여기만 「보유」로 뭉뚱그리면 말이 갈린다
        echo '<td class="stk"><b>' . pf_h($r['name']) . '</b><span class="code">' . pf_h($r['code'])
           . (isset($held[$r['code']]) ? ' ' . pf_held_badge($held[$r['code']]) : '')
           . (isset($watched[$r['code']]) ? ' <span class="badge" style="background:#fff8e1;color:#9a6b00">★ 관심</span>' : '')
           . '</span></td>';
        echo '<td class="num">' . pf_h($r['dt']) . '</td>';
        echo '<td>' . $r['y'] . ' ' . ($rcName[$r['rc']] ?? $r['rc']) . '</td>';
        // 배지 말·색은 사이트 공통(파랑 = 나쁜 소식) — 보고서 열이 옆에 있어 분기는 되풀이하지 않는다
        echo '<td class="num"><span class="sue-b dn" title="'
           . pf_h('이익 서프라이즈 SUE ' . sprintf('%+.2f', $r['sue'])
                . ' ≤ −1 = 어닝쇼크 무리. 공시 후 두 달 하방 드리프트가 8년 내내 실측된 자리입니다.')
           . '">SUE ' . sprintf('%.1f', $r['sue']) . '</span></td>';
        echo '<td class="num">' . ($r['rev_up'] === null ? '-' : ($r['rev_up'] ? '▲' : '<span class="down">▼</span>')) . '</td>';
        echo '<td class="num">' . ($r['ni_op'] === null ? '<span class="down">적자</span>'
                : ($r['ni_op'] > 1.5 ? '<b class="down">' . number_format($r['ni_op'], 2) . '</b>' : number_format($r['ni_op'], 2))) . '</td>';
        echo '<td class="num muted">' . pf_eok($r['cap']) . '</td>';
        echo '<td class="num muted">' . ($r['amt'] === null ? '-' : pf_eok($r['amt'])) . '</td>';
        echo '<td class="num">' . ($r['drift'] === null ? '-' : pf_signed_pct($r['drift'], 1)) . '</td>';
        echo '<td class="num">' . ($r['elapsed'] === null ? '-'
                : ($r['elapsed'] . '일' . ($r['elapsed'] > 60 ? ' <span class="muted">(구간 밖)</span>' : ''))) . '</td>';
        if (BadgeFeat::vals('earn')['qpath']) {
            echo '<td class="center">' . ($box[$r['code']] ?? '<span class="muted" style="font-size:12px" '
                    . 'title="최근 최고 거래대금 신호가 없는 종목 — 판정 불가(나쁨이 아님)">-</span>') . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';

    echo '<p class="sub muted" style="margin:9px 0 0;font-size:12px">'
       . '위 서프라이즈 목록과 <b>같은 공시 창(' . $days . '일)·같은 접수일 원장·같은 계산</b>입니다 — '
       . '한 분포의 두 끝이라 화면을 나누지 않았습니다. 위 목록은 SUE 높은 순이라 상한(200건)에 걸리면 '
       . '이쪽 꼬리가 잘려 나가므로, 여기서 <b>SUE 낮은 순으로 따로</b> 건집니다.</p>';
    echo '</div>';
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
                 . ' 최고 거래대금 신호도 같은 종목을 잡았다 — <b>재무가 먼저 말하고 수급이 뒤따른</b> 교과서.'],
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
     * 재무(SUE)와 수급(최고 거래대금)이 한 차트에서 겹쳐 보인다 — 삼성전기는 3/11 어닝서프라이즈 뒤 4~5월에
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
    echo '<script src="/style/dailychart.js?v=56"></script>';
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
      dc.setMarkers([{ time: c.buy, text: '어닝서프라이즈' }, { time: c.exit, sell: true, text: '+60일' }]);
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

    /* ★ 종목코드에 <b>글자가 섞인 것</b>이 있다 — 우선주 22종목이 끝자리에 K·L 을 쓴다
     *   (00088K 한화3우B · 33626L 두산퓨얼셀2우B). 숫자만 남기면 5자리가 되어
     *   상세로 안 가고 목록이 열렸다. 끝 한 자리만 글자를 허용한다. */
    $code = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string)($_GET['code'] ?? '')));
    if (preg_match('/^[0-9]{5}[0-9A-Z]$/', $code)) { pf_page_fund_detail($pdo, $dart, $code, $pf); return; }

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

    /* 태그 필터(&tag=) — 종목 개요에서 단 태그로 모집단을 좁힌다 (단일본 stock/lib/note.php).
     * ★「범위」지 「조건」이 아니다 — cond_qs(저장한 조건)에는 담지 않는다(기준 y·rc 를
     *   안 담는 것과 같은 이유). 조건 폼에는 hidden 으로 실어 검색을 반복해도 유지한다. */
    $tagQ   = trim((string)($_GET['tag'] ?? ''));
    $tagSet = ($tagQ !== '') ? array_flip(pf_tag_codes($pdo, $tagQ)) : null;

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

        if ($tagSet !== null && !isset($tagSet[$r['stock_code']])) continue;
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

    /* 이미 자리가 있는 종목 — 관심종목·어닝·퀀트와 <b>같은 판정</b>(pf_held_map · 2026-08-04).
     * 자르고 난 뒤에 묻는다 — 화면에 뜨는 것만 알면 되고, 조건이 넓을 때 수천 건을 물을 이유가 없다. */
    $fHeld = pf_held_map($pdo, array_column($rows, 'stock_code'));
    // 개요 헤드라인 — 행 툴팁에 앞세운다 (표시 50행만 묻는다 · stock/lib/note.php)
    $fHead = pf_note_head_map($pdo, array_column($rows, 'stock_code'));

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
    echo '</div>';

    /* 최근조회 — 조건(무엇을 거를까)과 이력(방금 무엇을 봤나)은 성격이 달라 줄을 나눈다.
     * 목록은 ETF 화면과 <b>같은 표</b>를 본다(pf_fund_recent 주석 참조). */
    pf_fund_recent($pdo, '', true);

    /* 태그 줄 — 종목 개요에서 단 태그 전부(건수 포함). 누르면 지금 조건은 그대로 두고
     * 그 태그 종목만 남는다. 태그가 하나도 없으면 줄 자체가 없다(빈 안내를 늘어놓지 않는다). */
    $allTags = pf_tag_all($pdo);
    if ($allTags) {
        pf_tag_css();
        echo '<div class="preset-row" style="margin-top:6px"><span class="preset-lab">태그</span>';
        foreach ($allTags as $t) {
            $qs = $_GET;
            $qs['mode'] = 'fund'; $qs['go'] = '1'; $qs['tag'] = $t['name'];
            echo '<a class="stag' . ($t['name'] === $tagQ ? ' on' : '') . '" href="/stock/index.php?'
               . pf_h(http_build_query($qs)) . '" title="' . pf_h('#' . $t['name'] . ' 태그가 달린 '
               . $t['cnt'] . '종목만 봅니다 — 태그는 종목 상세의 「종목 개요」에서 답니다')
               . '">#' . pf_h($t['name']) . ' <span style="opacity:.65">' . (int)$t['cnt'] . '</span></a>';
        }
        if ($tagQ !== '') {
            $qs = $_GET;
            unset($qs['tag']);
            $qs['mode'] = 'fund'; $qs['go'] = '1';
            echo '<a class="stag" style="background:#fde7e9;color:#c62828" href="/stock/index.php?'
               . pf_h(http_build_query($qs)) . '" title="태그 필터를 풉니다">✕ 태그 풀기</a>';
        }
        echo '</div>';
    }
    echo '</div>';

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
    // 태그 필터는 검색을 반복해도 유지한다 — 풀기는 태그 줄의 ✕ 하나뿐
    if ($tagQ !== '') echo '<input type="hidden" name="tag" value="' . pf_h($tagQ) . '">';
    echo '<button class="btn btn-primary" type="submit">검색</button>';
    echo '<a class="btn btn-outline" href="/stock/index.php?mode=fund&y=' . $year . '&rc=' . pf_h($reprt) . '">조건 지우기</a>';
    echo '</form>';

    echo '<p class="sub muted" style="margin:9px 0 0;font-size:12px">'
       . '<b>' . number_format($total) . '종목</b>이 조건에 맞습니다'
       . ($tagQ !== '' ? ' · <b>태그 #' . pf_h($tagQ) . '</b> 종목만' : '')
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
        // 「원래 없다」와 「태그로 좁혀서 없다」를 갈라 적는다 — 뒤엣것에는 푸는 길을 준다
        $qs = $_GET;
        unset($qs['tag']);
        $qs['mode'] = 'fund'; $qs['go'] = '1';
        echo '<p class="muted" style="font-size:13px;margin:0">조건에 맞는 종목이 없습니다. '
           . ($tagQ !== ''
               ? '<b>태그 #' . pf_h($tagQ) . '</b> 필터가 걸려 있습니다 — <a href="/stock/index.php?'
                 . pf_h(http_build_query($qs)) . '">태그만 풀어 보기</a>. '
               : '')
           . '조건을 느슨하게 해 보세요.</p>';
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
        /* ★열 골격은 <b>관심종목 표와 같다</b>(2026-08-04 사용자 「여긴 정리가 안 됐다」) —
         *   ①종목 맨 왼쪽 → ②그 오른쪽이 「포트폴리오」(늘 하나가 차서 열이 가지런하다) → … → ③☆ 는 동선의 끝.
         *   ☆ 와 편입이 한 칸에 같이 있으면 목록을 읽기도 전에 버튼 두 개부터 눈에 들어오고,
         *   행마다 「편입」만 서 있어 <b>이미 담은 종목까지 담을 수 있는 것처럼</b> 보였다. */
        echo '<th>종목</th>';
        // ★열 이름은 「상태」가 아니라 <b>「포트폴리오」</b>다(2026-08-04 사용자) — 이 칸이 답하는 것은
        //   「이 종목이 내 포트폴리오에 있나」이고, 「상태」는 이 사이트에서 포지션 상태·박스 상태 등
        //   여러 뜻으로 이미 쓰이는 말이라 무엇을 담은 열인지 알 수 없다.
        echo '<th class="center" style="width:78px;white-space:nowrap" '
           . 'title="이 종목이 내 포트폴리오에 있나 — 편입 · 보유 · 편입됨 셋 중 하나">포트폴리오</th>';
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
        echo '<th class="num">기준</th>';
        echo '<th class="center" style="width:34px" title="관심종목에 담기/빼기">☆</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $r) {
            // 담아 둔 종목은 맨 위에 모여 있으므로 옅은 배경으로 경계를 보인다
            $on = isset($watched[$r['stock_code']]);
            $nm = (string)($r['corp_name'] ?: $r['stock_code']);
            echo '<tr class="fund-row' . ($on ? ' watched' : '') . '" data-code="' . pf_h($r['stock_code'])
               . '" title="' . pf_h((isset($fHead[$r['stock_code']]) ? $fHead[$r['stock_code']] . ' — ' : '')
               . '클릭하면 연도별 재무를 봅니다') . '">';
            echo '<td class="stk"><b>' . pf_h($nm) . '</b>'
               . '<span class="code">' . pf_h($r['stock_code'])
               // 필터를 껐을 때만 나타난다 — 시세에 없는 종목은 상장폐지일 가능성이 높다
               . (empty($r['listed']) ? ' <span class="badge st-closed" title="KRX 최근 거래일에 없습니다">미거래</span>' : '')
               . '</span></td>';
            /* ② 포트폴리오 — 편입 / 보유 / 편입됨. 관심종목·어닝·퀀트와 <b>같은 판정</b>이라 화면끼리 어긋나지 않는다.
             * 편입 버튼은 행 클릭(상세 이동)과 겹치므로 JS 가 캡처 단계에서 전파를 끊는다. */
            echo '<td class="center" style="white-space:nowrap">'
               . pf_adopt_cell((string)$r['stock_code'], $nm, $fHeld[$r['stock_code']] ?? null) . '</td>';
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
            echo '<td class="num"' . ($sue !== null && $sue >= Thr::SUE_HIT
                    ? ' title="이익 서프라이즈 상위 20% 수준 — 백테스트에서 +60거래일 시장중앙 대비 +3.6%"'
                    : '') . '>'
               . ($sue === null ? '-'
                    : ($sue >= Thr::SUE_HIT ? '<b>' . number_format($sue, 2) . '</b>' : number_format($sue, 2)))
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
            // ③ ☆ — 목록에서 담고 빼는 토글이라 <b>동선의 끝</b>에 둔다(관심종목 표의 ★ 와 같은 자리)
            echo '<td class="center"><button type="button" class="wl-star' . ($on ? ' on' : '') . '"'
               . ' data-code="' . pf_h($r['stock_code']) . '" data-name="' . pf_h($nm) . '"'
               . ' title="관심종목에 담기/빼기">' . ($on ? '★' : '☆') . '</button></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="sub muted" style="margin:9px 0 0;font-size:12px">'
           . '<b>포트폴리오</b> 칸에는 셋 중 하나가 옵니다 — <b>편입</b>(담을 수 있음 · 종목 추가 폼이 열립니다) · '
           . '<span class="badge st-held">보유</span> 실제로 담고 있음 · '
           . '<span class="badge st-slot">편입됨</span> 포지션만 만들고 아직 매수 기록 없음'
           . '(여러 포트폴리오면 개수까지 · 눌러서 그 종목 상세로). 관심종목·어닝·퀀트 탭과 <b>같은 판정</b>입니다. '
           . '맨 오른쪽 <b>☆</b> 는 관심종목에 담기/빼기입니다.<br>'
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
  var body = new URLSearchParams({json:'1', src:'fund', code:b.getAttribute('data-code'), name:b.getAttribute('data-name')});
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

// 편입 — 행 클릭보다 먼저 먹어야 한다(캡처). 포트폴리오 정보가 없으므로
// ★ 안 담긴 종목은 관심종목 등록 화면으로, 담긴 종목은 포트폴리오 선택으로 이어진다.
document.addEventListener('click', function(e){
  var a = e.target.closest ? e.target.closest('.pf-adopt') : null;
  if (!a) return;
  e.stopPropagation();
  e.preventDefault();
  location.href = '/stock/index.php?mode=position&id=new&src=fund&bm=box'
    + '&code=' + encodeURIComponent(a.getAttribute('data-code'))
    + '&name=' + encodeURIComponent(a.getAttribute('data-name'));
}, true);

document.addEventListener('click', function(e){
  var tr = e.target.closest ? e.target.closest('tr.fund-row') : null;
  if (!tr || (e.target.closest && (e.target.closest('a') || e.target.closest('.wl-star') || e.target.closest('.pf-adopt')))) return;
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
    pf_slowlog_mark('watch.ddl');   // Dart DDL — 잠금 대기 용의 구간 (2026-08-19 「첫 로딩 20~30초」 추적)

    // 담아 둔 종목의 시세도 최신으로 (보유 종목과 같은 규칙 — 낡은 것만 네이버에서 채운다)
    $rows = $pf->watchList();
    if ($rows) {
        $pf->refreshQuotes(array_column($rows, 'stock_code'));
        $rows = $pf->watchList();
    }
    pf_slowlog_mark('watch.quotes');   // 네이버 시세 콜 — 외부 지연 용의 구간

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

    /* ── 관제탑 데이터 (2026-08-02) — 발굴한 후보가 "지금 살 수 있는 상태인가"를 이 화면에서 판정한다.
     * 탐색(퀀트·어닝)에서 담고, 여기서 트리거(돌파확인·계단지지)를 기다렸다가, 편입 버튼으로 넘어간다.
     * 전부 기존 판정 함수 재사용 — 임계·어휘가 퀀트·어닝 탭과 한 벌이어야 화면끼리 딴소리를 안 한다. */
    $wBadge = []; $wBox = []; $wHot = []; $wSue = []; $wTrig = []; $wMkt = []; $wHeld = [];
    if ($rows) {
        /* 이미 담고 있는 종목 — 편입 버튼을 띄울 이유가 없다.
         * 판정은 pf_held_map() 단일본이다(스크리너·어닝·퀀트가 같은 것을 본다 · 2026-08-04). */
        $wHeld = pf_held_map($pdo, array_column($rows, 'stock_code'));

        try {
            $ka    = new KrxAmt($pdo);
            $codes = array_column($rows, 'stock_code');
            $in    = implode(',', array_fill(0, count($codes), '?'));
            // 종목별 최근 최고 거래대금 신호 + 그 날의 유형 재료(20평비·등락)
            $sg = $pdo->prepare("
                SELECT s.code, s.d, s.avg_mul, s.chg FROM krx_surge s
                  JOIN (SELECT code, MAX(d) d FROM krx_surge WHERE code IN ($in) GROUP BY code) m
                    ON m.code = s.code AND m.d = s.d");
            $sg->execute($codes);
            $sigs = [];
            foreach ($sg->fetchAll(PDO::FETCH_ASSOC) as $s) {
                $sigs[] = ['code' => $s['code'], 'd' => $s['d']];
                $wBadge[$s['code']] = ['d' => $s['d'], 'b' => pf_surge_badge(
                    $s['avg_mul'] !== null ? (float)$s['avg_mul'] : null,
                    $s['chg'] !== null ? (float)$s['chg'] : null)];
            }
            if ($sigs) {
                $bxAll = $ka->boxStatusMany($sigs);
                $mmAll = $ka->momMany($sigs);
                foreach ($sigs as $s) {
                    $k = $s['code'] . '|' . $s['d'];
                    if (isset($bxAll[$k])) $wBox[$s['code']] = $bxAll[$k] + ['d' => $s['d']];
                    if (isset($mmAll[$k])) $wHot[$s['code']] = $mmAll[$k];   // 창별 칩 재료 그대로
                }
            }
        } catch (Throwable $e) { /* krx_surge 미구축 환경 — 수급 열 없이 목록만 */ }

        foreach ($rows as $w) {
            try {
                $sq = pf_sue_stock($pdo, $w['stock_code']);
                if ($sq) {
                    $qk = array_key_last($sq);
                    $wSue[$w['stock_code']] = ['qk' => $qk, 'v' => $sq[$qk]];
                }
            } catch (Throwable $e) { /* 재무 없음 — SUE 없이 */ }
        }

        /* 트리거 = 백테스트로 검증된 매수규칙 둘 (그 외 조합은 전부 관망 — 배지 없음).
         * 계단지지의 floors≥3 판정은 boxStatusMany 가 txt 에 ⚠ 로 실어 준다(미충족이면 ⚠). */
        foreach ($rows as $w) {
            $c = $w['stock_code'];
            $b = $wBadge[$c]['b'] ?? null;
            $x = $wBox[$c] ?? null;
            if (!$b || !$x || $b[2] !== '매집형') continue;
            /* 'k' = pf_position.entry_trigger 에 그대로 들어갈 값 (M2) — 라벨(이모지 붙은 한글)을
             * 저장 시점에 되짚어 파싱하면 문구를 바꿀 때마다 기록이 깨진다. 판정할 때 키를 같이 정한다. */
            if ($x['st'] === 'bx-brk') {
                $wTrig[$c] = ['t' => '🟢 돌파확인', 'k' => 'breakout', 'tip' =>
                    '매집형 × 돌파 종가 확인 — 실측 +2.26% · 승률 57.9%. 매도는 한 계단 유예(T①).'];
            } elseif ($x['st'] === 'bx-lad'
                      && mb_strpos($x['txt'], '계단지지') === 0 && mb_strpos($x['txt'], '⚠') === false) {
                $wTrig[$c] = ['t' => '🟢 계단지지', 'k' => 'step_support', 'tip' =>
                    '매집형 × 계단지지 확인 × 아래층 박스 3개↑ — 실측 +2.40% · 승률 58.5%. 매도는 20일 잠금 후 손절선(T②).'];
            }
        }

        /* ── ①a 시장 상태 (2026-08-04). 관제탑에는 퀀트(신호·박스·트리거)와 실적(SUE)은 있는데
         * <b>시장 상태</b>(역배열·과매도·급락·거래량 급증)만 빠져 있었다 — 같은 트리거라도
         * 「역배열 + 52주 최저권」에서 뜬 것과 그렇지 않은 것은 편입 판단이 다르다.
         *
         * ★ 데이터는 이미 있다 — pf_daily 는 signalCodes()(보유 + <b>관심</b>)를 대상으로 크론이
         *   채우므로 이 화면 종목의 봉이 그대로 쌓여 있다. 한 쿼리로 읽어 네이버를 부르지 않는다.
         * ★ 판정은 현황·보유종목과 <b>같은 함수·같은 인자</b>다(pf_indicators → pf_market_signals(…,3,liq)).
         *   여기서만 다르게 재면 같은 종목이 화면마다 다른 상태로 보인다.
         * ★ 유동성은 <b>열로 만들지 않는다</b>(사용자 지시 — 한도·유동성은 사용자 영역).
         *   pf_liquidity 는 「★ 거래량 N배」 강조 재료로만 넘긴다 — 그래야 현황과 같은 배지가 나온다.
         * ★ 일봉이 없거나 표본이 모자란 종목은 <b>조용히 비운다</b>. 「특이 없음」이 아니라 「아직 모름」이다. */
        try {
            $wDaily = $pf->dailyMap(array_column($rows, 'stock_code'), 400);
            foreach ($rows as $w) {
                $bars = $wDaily[(string)$w['stock_code']] ?? [];
                if (!$bars) continue;
                $last = ($w['last_price'] !== null) ? (float)$w['last_price'] : null;
                $ind  = pf_indicators($bars, $last);
                if (!$ind) continue;
                $mk = pf_market_signals($ind, 3, pf_liquidity($bars) ?: null);
                if ($mk) $wMkt[(string)$w['stock_code']] = $mk;
            }
        } catch (Throwable $e) { /* pf_daily 미구축 — 시장 열 없이 목록만 */ }
    }

    pf_head('관심종목', 'fund', 'wide');       // 상단은 재무분석, 하위 탭에서 관심종목
    pf_subtabs('watch', 'fund');
    pf_flash();
    pf_quant_css();   // .qb(유형)·.bx(박스)·.wl-star — 퀀트·어닝과 같은 배지 어휘

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

    // 트리거 뜬 종목을 맨 위로 — "오늘 살 수 있는 후보"가 이 화면의 알맹이다 (같은 무리 안은 담은 순 유지)
    usort($rows, function ($a, $b) use ($wTrig) {
        $ta = isset($wTrig[$a['stock_code']]) ? 0 : 1;
        $tb = isset($wTrig[$b['stock_code']]) ? 0 : 1;
        return $ta <=> $tb;
    });
    $srcTag = static function (string $s): string {
        $map = ['quant' => '퀀트', 'earn' => '어닝', 'fund' => '스크리너'];
        if (!isset($map[$s])) return '';
        return ' <span class="wl-src" title="발굴 채널 — 어느 화면의 ☆로 담았나">' . $map[$s] . '</span>';
    };

    echo '<div class="card"><h2>담아 둔 종목 ' . count($rows) . '개'
       . ($wTrig ? ' · <span class="up">트리거 ' . count($wTrig) . '개</span>' : '') . '</h2>';
    // 신호·경로는 같은 신호일에서 나온 한 덩이라 머리를 묶는다 (퀀트 목록·종목 상세와 같은 규칙)
    echo '<div class="tbl-scroll"><table class="pf pos">';
    /* ★★열 순서는 <b>편입 관심종목 카드와 같은 골격</b>이다(2026-08-04 사용자 「어수선하다」).
     *   ①종목명 맨 왼쪽 → ②그 오른쪽이 「포트폴리오」(편입 알약 / 보유 / 편입됨 — <b>늘 하나</b>) → … → ③★(빼기)는 맨 끝.
     * 어수선했던 원인 셋을 한 번에 없앤다:
     *   ⓐ 행동 칸이 들쭉날쭉 → 그 칸은 <b>항상 하나</b>가 차 있어 열이 가지런하다
     *   ⓑ 종목 칸이 3~4줄 → 「보유」 배지를 그 칸으로 옮기고 출처 태그를 <b>코드 줄 안</b>으로 넣어 2줄 고정
     *      (배지는 원래 「왜 편입 버튼이 없나」의 답이라 코드 줄이 아니라 <b>버튼 자리</b>가 제자리다)
     *   ⓒ 파괴적 동작이 맨 앞 → ★ 를 동선의 끝으로 (편입 관심종목의 × 와 같은 자리) */
    /* 배지 표시 설정(설정 > 신호분석 설정 · BadgeFeat) — 끈 배지는 열째로 사라진다(빈 열 금지).
     * ★트리거 열은 잠금이다 — 이 화면(관제탑)의 존재 이유. 판정 계산($wMkt·$wBadge·$wBox)은
     *   트리거가 2-a×2-b 조합이라 표시를 꺼도 그대로 돈다(끄는 것은 «그리기»뿐). */
    $bw = BadgeFeat::vals('watch', $pdo);
    /* 종목 태그·개요 헤드라인 (stock/lib/note.php) — 칩은 배지 설정('tag')을 따르고,
     * 헤드라인은 배지가 아니라 종목명 툴팁이라 스위치가 없다(호버 전엔 자리도 안 먹는다). */
    $wTags = $bw['tag'] ? pf_tags_of($pdo, array_column($rows, 'stock_code')) : [];
    $wHead = pf_note_head_map($pdo, array_column($rows, 'stock_code'));
    if ($wTags) pf_tag_css();
    $wSigCol = $bw['qsig'] || $bw['mom'];
    $wQCols = [];
    if ($wSigCol)      $wQCols[] = ['신호', 'center'];
    if ($bw['qpath'])  $wQCols[] = ['경로', 'center'];
    pf_thead_grouped(array_merge(
        [['종목명', ''], ['포트폴리오', 'center'], ['담은날', 'num'], ['담을때가', 'num'], ['현재가', 'num'],
         ['담은뒤', 'num']],
        $bw['mkt'] ? [['시장', '']] : [],
        $bw['sue'] ? [['SUE', 'num']] : [],
        $wQCols ? [['group' => '퀀트 : 최고 거래대금', 'cols' => $wQCols]] : [],
        [['트리거', 'center'],
         ['PER', 'num'], ['ROE', 'num'], ['영업이익률', 'num'], ['매출액', 'num'], ['메모', ''], ['', 'center']]
    ));
    echo '<tbody>';

    foreach ($rows as $w) {
        $code = $w['stock_code'];
        $f    = $fin[$code] ?? null;
        $m    = $f ? Dart::ratio($f) : null;

        $now   = (float)($w['last_price'] ?? 0);
        $added = (float)($w['added_price'] ?? 0);
        // 담은 뒤 등락 — 담을 때 가격을 못 잡았으면(네이버에 없던 종목) 비워 둔다
        $chg   = ($now > 0 && $added > 0) ? $now / $added - 1 : null;

        echo '<tr' . (isset($wTrig[$code]) ? ' class="wl-trig"' : '') . '>';

        // ① 종목명 — 코드와 출처를 <b>한 줄</b>에 · 개요 헤드라인은 툴팁으로.
        //   태그는 «셋째 줄»(2026-08-30 사용자 — 코드 줄에 섞으면 칸이 옆으로 밀린다)
        echo '<td class="stk"><a href="/stock/index.php?mode=fund&code=' . pf_h($code) . '"'
           . (isset($wHead[$code]) ? ' title="' . pf_h($wHead[$code]) . '"' : '') . '>'
           . pf_h($w['stock_name']) . '</a><span class="code">' . pf_h($code)
           . $srcTag((string)($w['source'] ?? '')) . '</span>'
           . (isset($wTags[$code]) ? '<span class="stagln">' . pf_tag_chips($wTags[$code], '', 2) . '</span>' : '')
           . '</td>';

        /* ② 포트폴리오 — 셋 중 <b>하나가 늘</b> 온다.
         *   편입 알약(담을 수 있음) / 보유(실제로 담고 있음) / 편입됨(자리는 있으나 매수 기록 없음).
         * ★★<b>이미 자리가 있으면 편입 버튼을 그리지 않는다</b> — 편입 카드의 「선택」과 같은 규칙.
         * ★기준은 <b>살아 있는 포지션</b>(status≠closed). 청산은 「담고 있지 않다」이므로 버튼을 남긴다 —
         *   그 포트폴리오엔 못 담지만 <b>다른 포트폴리오엔 새로 담을 수 있고</b>, 막히면 api 중복 가드가 알려 준다.
         *   여기서 종목 단위로 미리 막으면 멀쩡한 길까지 닫힌다. */
        echo '<td class="center" style="white-space:nowrap">'
           // M2 — 지금 화면에 뜬 트리거와 그 판정의 기준 신호일을 편입 폼까지 그대로 넘긴다
           . pf_adopt_cell($code, (string)$w['stock_name'], $wHeld[$code] ?? null,
                ['trig' => (string)($wTrig[$code]['k'] ?? ''), 'sed' => (string)($wBadge[$code]['d'] ?? '')],
                '포트폴리오에 편입 — 퀀트 사다리로 종목 추가 폼이 열립니다 · '
                . '포트폴리오 미지정 저장 = 편입 관심종목 (출처·담은날·담을때가는 자동 승계)')
           . '</td>';
        echo '<td class="num muted">' . pf_h(substr((string)$w['added_at'], 2, 8)) . '</td>';
        echo '<td class="num muted">' . pf_n($added ?: null) . '</td>';
        echo '<td class="num">' . pf_n($now ?: null) . '</td>';
        echo '<td class="num">' . pf_signed_pct($chg) . '</td>';

        /* 시장 — 현황·보유종목과 같은 배지(초록=살쪽 / 붉은=팔쪽 / 주황=경고 / 회색=참고).
         * 일봉이 없거나 표본이 모자라면 '-' 다(「특이 없음」이 아니라 「아직 모름」). */
        if ($bw['mkt']) {
            echo '<td>' . (empty($wMkt[$code])
                ? '<span class="flat" title="일봉 이력이 없거나 표본이 모자랍니다 — 판정 불가(나쁨이 아님)">-</span>'
                : pf_mkt_badges($wMkt[$code])) . '</td>';
        }

        // SUE — 최신 분기(어닝 탭과 같은 계산·단일 종목판). ≥1 굵게 · ≤−1 쇼크(파랑)
        if (!$bw['sue']) {
            // 열째로 접혔다 — 배지 표시 설정
        } elseif (isset($wSue[$code])) {
            $qk = $wSue[$code]['qk']; $sv = $wSue[$code]['v'];
            $sy = intdiv($qk - 1, 4); $sq = $qk - $sy * 4;
            $lbl = ($sy % 100) . '.' . $sq . 'Q';
            $txt = number_format($sv, 1);
            if ($sv >= 1)       $txt = '<b>' . $txt . '</b>';
            elseif ($sv <= Thr::SUE_SHOCK)  $txt = '<b class="down">' . $txt . '</b>';
            echo '<td class="num" title="최신 분기(' . $lbl . ') 이익 서프라이즈 — ≥1 ≈ 상위 20% · ≤−1 = 쇼크(회피 목록)">'
               . $txt . ' <span class="muted" style="font-size:11px">' . $lbl . '</span></td>';
        } else {
            echo '<td class="num muted" title="SUE 계산 불가(이력 4분기 미만)">-</td>';
        }

        /* 퀀트신호 — 최근 최고 거래대금 신호일의 유형 + 20·40일 모멘텀(시장 계열 칩).
         * ★ 라벨에 <b>신호일</b>을 붙인다 — 종목마다 신호일이 다르고 반년 전일 수도 있는데
         *   이 표에는 신호일 열이 없다(퀀트 목록·추적 카드는 화면에 날짜가 있어 생략한다). */
        if (!$wSigCol) {
            // 신호 열째로 접혔다 — 배지 표시 설정 (판정은 트리거가 계속 쓴다)
        } elseif (isset($wBadge[$code])) {
            [, $bc, $bl, $bt] = $wBadge[$code]['b'];
            $sigD = (string)($wBadge[$code]['d'] ?? '');
            $tag  = pf_sig_date_tag($sigD);
            /* ★ 중립도 배지로 그린다(2026-08-02 사용자) — 빈 칸으로 두면 「중립(판정됨·관망)」과
             *   「최근 신호 이력 없음(판정 불가)」이 같은 모양이 된다. 아래 else 가 그 '-' 다. */
            $chips = ($bw['qsig']
                      ? '<span class="qb ' . $bc . '" title="'
                        . pf_h('신호일 ' . $sigD . ' — ' . $bt) . '">' . pf_h($bl) . $tag . '</span>'
                      : '')
                   . ($bw['mom'] ? pf_mom_chips($wHot[$code] ?? null, $sigD, false) : '');   // 신호일 기준
            echo '<td class="center" style="white-space:nowrap">'
               . ($chips !== '' ? $chips : '<span class="muted">-</span>') . '</td>';
        } else {
            echo '<td class="center muted" title="최근 최고 거래대금 신호 없음 — 판정 불가(나쁨이 아님)">-</td>';
        }

        // 박스 — 그 신호 박스의 현재 상태 (퀀트·어닝 탭과 같은 boxStatusMany)
        if ($bw['qpath']) {
            echo '<td class="center">' . (isset($wBox[$code])
                ? '<span class="bx ' . pf_h($wBox[$code]['st']) . '" title="'
                  . pf_h('신호일 ' . $wBox[$code]['d'] . ' — ' . $wBox[$code]['tip']) . '">' . pf_h($wBox[$code]['txt']) . '</span>'
                : '<span class="muted">-</span>') . '</td>';
        }

        // 트리거 — 검증된 매수규칙 충족 (이게 뜨면 편입을 검토할 때)
        echo '<td class="center">' . (isset($wTrig[$code])
            ? '<span class="wl-go" title="' . pf_h($wTrig[$code]['tip']) . '">' . $wTrig[$code]['t'] . '</span>'
            : '<span class="muted" style="font-size:12px">관망</span>') . '</td>';

        echo '<td class="num">' . ($m && $m['per'] !== null ? number_format($m['per'], 2) : '-') . '</td>';
        echo '<td class="num">' . ($m ? pf_ratio_pct($m['roe']) : '-') . '</td>';
        echo '<td class="num">' . ($m ? pf_ratio_pct($m['op_margin']) : '-') . '</td>';
        echo '<td class="num muted">' . ($f ? pf_eok($f['revenue']) : '-') . '</td>';
        echo '<td><input type="text" class="wl-memo" data-code="' . pf_h($code) . '" value="'
           . pf_h($w['memo']) . '" placeholder="왜 담았는지" maxlength="200"></td>';
        // ⑰ 빼기 — 되돌릴 수 없는 쪽이라 <b>동선의 끝</b>에 둔다(편입 관심종목의 × 와 같은 자리)
        echo '<td class="center"><button type="button" class="wl-del" data-code="' . pf_h($code)
           . '" title="관심종목에서 빼기">★</button></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';

    echo '<p class="sub muted" style="margin:9px 0 0;font-size:12px">'
       . '<b>포트폴리오</b> 칸에는 셋 중 하나가 옵니다 — <b>편입</b>(담을 수 있음 · 종목 추가 폼이 열립니다. '
       . '퀀트 사다리 기본 · 출처와 담은 기록 승계 · 포트폴리오 미지정 저장 = 편입 관심종목) · '
       . '<span class="badge st-held">보유</span> 실제로 담고 있음 · '
       . '<span class="badge st-slot">편입됨</span> 포지션만 만들고 아직 매수 기록 없음'
       . '(여러 포트폴리오면 개수까지 · 눌러서 그 종목 상세로). '
       . '전량 매도해 <b>청산한 종목은 편입 버튼이 남습니다</b> — 그 포트폴리오엔 못 담아도 <b>다른 포트폴리오엔 새로 담을 수 있고</b>, '
       . '막혀 있으면 저장할 때 알려 줍니다. 맨 오른쪽 <b>★</b> 는 이 목록에서 빼기이고, 메모는 칸을 벗어나면 저장됩니다.<br>'
       . '<b>트리거</b> = 백테스트로 검증된 매수규칙 둘(🟢매집형×돌파확인 +2.26% / 🟢매집형×계단지지·아래층3개↑ +2.40%)만 띄웁니다 — '
       . '그 외 조합(중립·불꽃형, 트리거 전 매집형)은 전부 <b>관망</b>이 규칙입니다. '
       . 'SUE·신호·박스는 어닝·퀀트 탭과 같은 판정이라 화면끼리 어긋나지 않습니다.<br>'
       . '<b>시장</b>은 현황·보유종목과 <b>같은 배지</b>입니다(역배열·과매도·급락·거래량 급증 등 최대 3개). '
       . '트리거가 떴어도 <b>역배열 + 52주 최저권</b>이면 한 번에 채우지 않는 것이 이 시스템의 기본입니다 — '
       . '트리거는 「지금이 그 자리」를, 시장은 「지금이 어떤 판」인지를 말합니다. 자세한 뜻은 아래 배지 도움말에 있습니다.<br>'
       . '「담은뒤」는 <b>담을 때 주가 대비</b> 등락 — 여기서 고른 판단이 맞았는지 되짚는 자리입니다. '
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
  var a = e.target.closest && e.target.closest('.pf-adopt');
  if (a) {   // 편입 — 출처는 폼이 아니라 저장 시점에 pf_watchlist 에서 자동 승계된다
    var u = '/stock/index.php?mode=position&id=new&bm=box'
      + '&code=' + encodeURIComponent(a.getAttribute('data-code'))
      + '&name=' + encodeURIComponent(a.getAttribute('data-name'));
    // M2 — 트리거·기준 신호일은 있을 때만 싣는다 (없으면 저장 쪽이 'none'/파생으로 메운다)
    var tg = a.getAttribute('data-trig'), sd = a.getAttribute('data-sed');
    if (tg) u += '&trig=' + encodeURIComponent(tg);
    if (sd) u += '&sed='  + encodeURIComponent(sd);
    location.href = u;
    return;
  }
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
.wl-src{display:inline-block;margin-left:5px;padding:0 6px;border-radius:5px;font-size:10.5px;font-weight:700;
  background:#eef3f8;color:#5f7183;vertical-align:middle}
tr.wl-trig td{background:#f2fbf4}
.wl-go{display:inline-block;padding:2px 9px;border-radius:10px;font-size:12px;font-weight:800;
  background:#e6f4ea;color:#1e7e34;white-space:nowrap;cursor:help}
</style>
JS;

    /* 시장 배지가 뜨는 화면이 됐으니 도움말도 여기에 둔다 — 현황·보유종목과 같은 접힌 표다.
     * 「이 배지가 무슨 뜻인가」를 다른 화면까지 찾아가게 하면 아무도 안 읽는다. */
    pf_render_badge_help();
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

/**
 * 재무 표에 붙일 <b>공시일 · SUE</b> — [연도*4+분기 => ['dt' => '2026-03-20'|null, 'sue' => float|null]]
 *
 * 재무는 「언제 알려졌나」가 빠지면 주가와 견줄 수 없다. 사업보고서는 결산 뒤 석 달이 지나 나오므로
 * 「2025년 실적」을 2025년 자리에서 읽으면 선견 편향이 된다 — 그래서 <b>DART 접수일</b>을 옆에 둔다.
 * ★ 접수일 = <b>MIN(rcept_dt)</b> — 정정공시([기재정정])는 별도 rcept_no 로 다시 들어오므로
 *   원본(가장 이른) 접수일을 쓰는 것이 원장 소비자 공통 규칙(어닝 탭·차트 마커와 같다).
 * ★ SUE 는 pf_sue_stock — 스크리너 SUE 열·어닝 탭·아래 차트 마커와 <b>같은 계산</b>이라 화면끼리 어긋나지 않는다.
 * ★ 연도 행이 짚는 것은 그 해 <b>4분기</b>(사업보고서)다 — 연간 누적에는 서프라이즈 개념이 없다.
 * 비용: 접수일 1쿼리(ix_code 인덱스) + SUE 1~2ms. 원장·재무가 없는 환경이면 두 칸이 '-' 로 나갈 뿐이다.
 */
function pf_fund_filing_map(PDO $pdo, string $code): array
{
    $out = [];
    try {
        foreach (pf_sue_stock($pdo, $code) as $qk => $v) $out[$qk] = ['dt' => null, 'sue' => (float)$v];

        if (!$pdo->query("SHOW TABLES LIKE 'dart_rcept'")->fetchColumn()) return $out;   // 원장 아직 없음
        $st = $pdo->prepare("
            SELECT bsns_year, reprt_code, MIN(rcept_dt) dt
              FROM dart_rcept
             WHERE stock_code = ? AND reprt_code IS NOT NULL
             GROUP BY bsns_year, reprt_code");
        $st->execute([$code]);
        $qNoMap = ['11013' => 1, '11012' => 2, '11014' => 3, '11011' => 4];
        foreach ($st as $r) {
            $qNo = $qNoMap[$r['reprt_code']] ?? null;
            if ($qNo === null) continue;                      // 비12월 결산이라 분기를 가릴 수 없는 공시
            $k = (int)$r['bsns_year'] * 4 + $qNo;
            if (!isset($out[$k])) $out[$k] = ['dt' => null, 'sue' => null];
            $out[$k]['dt'] = (string)$r['dt'];
        }

        /* 공시일 기준 시가총액 — 「그 실적이 알려진 날 시장이 얼마로 매기고 있었나」.
         * 원천은 krx_amt(전종목 일별 원장 · 2019-01-02~ · mktcap 원 단위). 공시일이 휴장일이면
         * 직전 거래일로 물러서되 10일까지만 — 장기 거래정지의 낡은 시총을 공시일 것처럼 보이면 거짓이 된다.
         * 원장 시작 전(2018 이전 공시)은 빈칸으로 남는다.
         * ★ all_stock_info 를 끌어들이지 않는다 — utf8mb3 콜레이션 사고(15배)의 그 표다. krx_amt 는 CHAR(6). */
        $capSt = $pdo->prepare("
            SELECT mktcap FROM krx_amt
             WHERE code = ? AND d BETWEEN DATE_SUB(?, INTERVAL 10 DAY) AND ? AND mktcap > 0
             ORDER BY d DESC LIMIT 1");
        foreach ($out as $k => $v) {
            if (empty($v['dt'])) continue;
            $capSt->execute([$code, $v['dt'], $v['dt']]);
            $cap = $capSt->fetchColumn();
            if ($cap !== false) $out[$k]['cap'] = (float)$cap;
        }
    } catch (Throwable $e) { /* 재무·원장 미구축 환경 — 있는 만큼만 */ }
    return $out;
}

/**
 * 「공시일」·「시총」·「SUE」 세 칸 — 연도별 재무와 분기 추이가 <b>같은 함수</b>를 쓴다(두 표가 어긋날 수 없다).
 *
 * 시총 = 공시일 종가 기준 시가총액(억원) — 「그 실적이 알려진 날 시장이 얼마였나」라 공시일 옆에 산다.
 * SUE 표기는 다른 화면과 같은 규칙이다: <b>±1 밖이면 배지, 안쪽이면 값만</b>.
 * 배지 색도 사이트 공통 — 빨강 `어닝서프라이즈`(서프라이즈 SUE ≥ +1) · 파랑 `어닝쇼크`(SUE ≤ −1).
 * $showQ = 연도 행은 값의 출처가 <b>그 해 4분기</b>라 배지에 분기를 밝힌다(분기 표는 행이 곧 분기다).
 */
function pf_fund_filing_cells(?array $f, string $qLbl, bool $showQ): string
{
    $dt  = $f['dt']  ?? null;
    $v   = $f['sue'] ?? null;
    $cap = $f['cap'] ?? null;

    $out = '<td class="num muted" style="font-size:11.5px;white-space:nowrap"'
         . ($dt ? ' title="' . pf_h($qLbl . ' 정기보고서 DART 접수일 — 정정공시가 있으면 원본(가장 이른) 접수일입니다') . '"'
                : ' title="접수일 원장에 없는 공시입니다 (2016년 이전이거나 결산월이 12월이 아닌 회사)"')
         . '>' . ($dt ? pf_h($dt) : '-') . '</td>';

    // 공시일 종가 기준 시총 — 마감 뒤 공시면 시장이 아직 반영하기 «전» 값이다(그래서 「기준」이 된다)
    $out .= '<td class="num muted"'
          . ($cap !== null
              ? ' title="' . pf_h('공시일(' . $dt . ') 종가 기준 시가총액 ' . number_format($cap / 100000000)
                . '억원 — 실적이 알려진 날 시장이 매기고 있던 값입니다. 공시가 마감 뒤면 아직 반영 전 값입니다.') . '"'
              : ' title="공시일의 시세 원장이 없습니다 (원장은 2019년부터 — 그 이전 공시이거나 장기 거래정지)"')
          . '>' . ($cap !== null ? pf_eok($cap) : '-') . '</td>';

    $q = $showQ ? '<span class="sue-q">' . pf_h($qLbl) . '</span>' : '';
    if ($v === null) {
        return $out . '<td class="num muted" title="SUE 계산 불가 — 전년 동기나 σ 이력(최소 4개)이 모자랍니다">-</td>';
    }
    $head = $qLbl . ' 이익 서프라이즈 SUE ' . sprintf('%+.2f', $v)
          . ' = (당분기 영업이익 − 전년동기) ÷ 자기 과거 2년 변동성. ';
    if ($v >= Thr::SUE_HIT) {
        return $out . '<td class="num"><span class="sue-b up" title="' . pf_h($head
             . '≥ +1 = 서프라이즈 무리(상위 20% 안팎) — 8년 백테스트에서 공시 후 두 달 상방 드리프트가 실측된 자리입니다.')
             . '">어닝서프라이즈 ' . sprintf('%+.1f', $v) . $q . '</span></td>';
    }
    if ($v <= -1) {
        return $out . '<td class="num"><span class="sue-b dn" title="' . pf_h($head
             . '≤ −1 = 어닝쇼크 무리 — 8년 백테스트에서 거의 매년 음수·공시 후 두 달 하방 드리프트입니다.')
             . '">어닝쇼크 ' . sprintf('%.1f', $v) . $q . '</span></td>';
    }
    return $out . '<td class="num muted" title="' . pf_h($head . '±1 안쪽은 이례가 아니라 배지를 만들지 않습니다.')
         . '">' . number_format($v, 1) . '</td>';
}

/** 한 종목의 연도별 재무 */
/**
 * 종목 개요·태그 카드 — 사용자가 직접 적는 층 (2026-08-30 · 단일본 stock/lib/note.php).
 * FnGuide 팝업(옆 버튼)을 보고 요지를 옮겨 적는 동선 — 자동 수집이 아니라 «내가 정리한 것»이라
 * 목록 툴팁·태그 필터에 실어도 출처 걱정이 없다. 태그 칩은 어느 화면에서든 스크리너 필터로 간다.
 */
function pf_fund_note_card(PDO $pdo, string $code): void
{
    $note = pf_note_get($pdo, $code);
    $tags = pf_tags_of($pdo, [$code])[$code] ?? [];
    $has  = ($note !== null) || $tags;
    pf_tag_css();

    /* ★폼은 언제나 접혀 있다(2026-08-30 사용자 — 「입력화면이 바로 보이니까 부담스러워」).
     * 빈 상태는 슬림한 한 줄 + 「＋ 적기」 버튼뿐이고, 누를 때만 입력창이 펼쳐진다 —
     * 적기 싫은 날의 상세화면에서 빈 폼이 자리를 차지하면 안 된다. */
    echo '<div class="card"' . (!$has ? ' style="padding-top:11px;padding-bottom:11px"' : '') . '>';
    echo '<div style="display:flex;align-items:baseline;gap:10px"><h2 style="margin:0">종목 개요</h2>';
    if ($note) {
        echo '<span class="muted" style="font-size:11px">' . pf_h(substr((string)$note['updated_at'], 0, 10)) . ' 수정</span>';
    }
    if (!$has) {
        echo '<span class="muted" style="font-size:12px">아직 없습니다 — 적어 두면 관심종목·어닝 목록과 태그 필터에서 쓰입니다</span>';
    }
    echo '<button type="button" class="btn btn-outline btn-sm" style="margin-left:auto" '
       . 'onclick="pfNoteEdit(true)">' . ($has ? '수정' : '＋ 개요 적기') . '</button>';
    echo '</div>';

    if ($has) {
        echo '<div id="noteView" style="margin-top:8px">';
        if ($note && trim((string)$note['headline']) !== '') {
            echo '<div style="font-weight:700;margin-bottom:6px">' . pf_h($note['headline']) . '</div>';
        }
        if ($note && trim((string)$note['summary']) !== '') {
            // pre-wrap — 입력한 줄바꿈 그대로. 서식은 없다(적는 자리지 꾸미는 자리가 아니다)
            echo '<div style="font-size:13px;line-height:1.65;white-space:pre-wrap">' . pf_h($note['summary']) . '</div>';
        }
        if ($tags) echo '<div style="margin-top:8px">' . pf_tag_chips($tags) . '</div>';
        echo '</div>';
    }

    // 저장 폼 — 늘 접혀 있고 「수정 / ＋ 개요 적기」로만 연다
    echo '<form id="noteForm" method="post" action="/stock/api.php?module=note&action=save" '
       . 'style="margin-top:8px;display:none">'
       . '<input type="hidden" name="code" value="' . pf_h($code) . '">'
       . '<input type="hidden" name="back" value="' . pf_h('/stock/index.php?mode=fund&code=' . $code) . '">'
       . '<input type="text" name="headline" maxlength="200" value="' . pf_h($note['headline'] ?? '') . '" '
       . 'placeholder="한 줄 요약 — 예) AI·전장화 수요로 실적 개선" style="width:100%;margin-bottom:6px">'
       . '<textarea name="summary" rows="5" placeholder="사업 개요 — FnGuide 버튼으로 열어 보고 요지를 옮겨 적습니다" '
       . 'style="width:100%;margin-bottom:6px;font-size:13px;line-height:1.6">' . pf_h($note['summary'] ?? '') . '</textarea>'
       . '<div style="display:flex;gap:6px;align-items:center">'
       . '<input type="text" name="tags" value="'
       . pf_h(implode(' ', array_map(fn($t) => '#' . $t, $tags))) . '" '
       . 'placeholder="#HBM #반도체 — # 또는 쉼표로 구분 (다른 목록·태그 필터에서 쓰입니다)" style="flex:1">'
       . '<button class="btn btn-primary btn-sm" type="submit">저장</button>'
       . '<button type="button" class="btn btn-outline btn-sm" onclick="pfNoteEdit(false)">취소</button>'
       . '</div>'
       . '</form>';

    /* 지난 개요 — append-only 히스토리(stock_note_hist). 「2년 전 내가 이 회사를 뭐라고
     * 이해하고 샀나」가 매매 복기의 재료다(편입 스냅샷·공시일 시총과 같은 「당시 기록」 계열).
     * 태그는 그때의 글자 그대로다(사전과 무관한 스냅샷) — 지금 태그와 달라도 그게 기록이다. */
    $hist = pf_note_hist($pdo, $code);
    if ($hist) {
        echo '<details style="margin-top:10px"><summary class="muted" style="cursor:pointer;font-size:12px">'
           . '지난 개요 ' . count($hist) . '개 — 그때는 이 회사를 뭐라고 이해했나</summary>';
        foreach ($hist as $h) {
            $d1 = substr((string)($h['noted_at'] ?? ''), 0, 10);
            $d2 = substr((string)$h['archived_at'], 0, 10);
            echo '<div style="margin-top:8px;padding:8px 10px;border-left:3px solid #dde5ec;background:#fafbfc">'
               . '<div class="muted" style="font-size:11px">'
               . ($d1 !== '' ? pf_h($d1) . ' 작성 · ' : '') . pf_h($d2) . ' 까지 쓰던 판</div>';
            if (trim((string)$h['headline']) !== '') {
                echo '<div style="font-weight:700;font-size:13px;margin-top:3px">' . pf_h($h['headline']) . '</div>';
            }
            if (trim((string)$h['summary']) !== '') {
                echo '<div style="font-size:12px;line-height:1.6;white-space:pre-wrap;margin-top:3px">'
                   . pf_h($h['summary']) . '</div>';
            }
            if ((string)$h['tags_text'] !== '') {
                echo '<div class="muted" style="font-size:11px;margin-top:4px">' . pf_h($h['tags_text']) . '</div>';
            }
            echo '</div>';
        }
        echo '</details>';
    }
    echo '</div>';

    echo '<script>function pfNoteEdit(on){var v=document.getElementById("noteView");'
       . 'if(v)v.style.display=on?"none":"";document.getElementById("noteForm").style.display=on?"block":"none";}</script>';
}

function pf_page_fund_detail(PDO $pdo, Dart $dart, string $code, ?Pf $pf = null): void
{
    /* ★ 우선주로 들어오면 본주 화면으로 바꿔 준다 (2026-08-04).
     *
     * DART 는 법인 단위라 우선주에는 corp_code 가 없어, 예전에는 005385(현대차우)를 열면
     * 연도별 재무도 분기 추이도 통째로 빈 화면이었다. 재무는 본주 것 하나뿐이므로 그것을 보여 준다.
     *
     * ★ 재무만 본주로 바꾸는 게 아니라 <b>화면을 통째로</b> 본주로 옮긴다(주가·차트·PER 전부).
     *   우선주 주가에 본주 재무를 붙이면 PER·PBR 이 조용히 틀린 값이 되기 때문이다 —
     *   우선주는 대개 본주보다 싸서 「저PER 우량주」처럼 보이게 된다. 무엇을 보고 있는지는 안내문으로 밝힌다. */
    $prefCode = null;
    if (($base = $dart->baseStockCode($code)) !== null) {
        $prefCode = $code;
        $code     = $base;
    }

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

    /* 최근조회 이력 — 이 화면이 <b>유일한 저장 지점</b>이다.
     * 바로가기 검색이든 목록 행 클릭이든 결국 여기로 오므로 입구마다 기록을 흩뿌릴 필요가 없다.
     * 이름은 거래소 종목명 우선(현대자동차 → 현대차) — ETF 화면이 남긴 이름과 어긋나지 않게. */
    pf_fund_recent_save($pdo, $code, pf_fund_recent_name($pdo, $code, $name ?: $code));

    // 폭은 목록 화면과 같은 기본(1440) — 오가며 볼 때 표가 흔들리지 않아야 한다
    pf_head('재무분석 · ' . ($name ?: $code), 'fund');
    pf_subtabs('screener', 'fund');    // 상세는 스크리너에서 들어오는 자리다
    pf_flash();

    echo '<div class="pf-head"><div><h1>' . pf_h($name ?: $code)
       . ' <span class="muted" style="font-size:14px;font-weight:600">' . pf_h($code) . '</span></h1>';
    echo '<div class="sub">DART 사업보고서 기준 <b>연도별</b> 재무와, 분기보고서에서 만든 <b>분기별</b> 추이입니다. '
       . '금액 단위는 <b>억원</b>.';
    if ($prefCode !== null) {
        // 왜 다른 종목이 열렸는지 밝힌다 — 안 밝히면 "종목을 잘못 눌렀나" 로 읽힌다
        echo '<br><b>' . pf_h(pf_fund_recent_name($pdo, $prefCode, $prefCode)) . ' (' . pf_h($prefCode) . ')</b> 은 우선주라 '
           . 'DART 에 따로 된 재무가 없습니다 — <b>본주 ' . pf_h($name ?: $code) . ' (' . pf_h($code) . ')</b> 기준으로 보여 드립니다. '
           . '<span class="muted">주가·PER·차트도 본주 기준입니다.</span>';
    }
    echo '</div></div>';
    echo '<div class="act">';

    // 상세를 보다 다른 종목이 궁금해지는 일이 잦다 — 목록으로 돌아갔다 올 것 없이 여기서 바로 옮겨 간다
    pf_fund_jump('fjd');

    if ($pf) {
        // 여기가 실제로 "담을지" 정하는 자리다 — 목록의 ☆ 와 같은 토글을 크게 둔다
        $on = isset($pf->watchCodes()[$code]);
        echo '<button type="button" class="btn ' . ($on ? 'btn-primary' : 'btn-outline') . ' wl-star2"'
           . ' data-code="' . pf_h($code) . '" data-name="' . pf_h($name ?: $code) . '">'
           . ($on ? '★ 관심종목' : '☆ 관심종목') . '</button> ';
        // 편입 — 포트폴리오 정보가 없는 자리라, ★ 담긴 종목은 포트폴리오 선택으로·아니면 관심종목 등록으로
        echo '<a class="btn btn-outline" href="/stock/index.php?mode=position&id=new&src=fund&bm=box'
           . '&code=' . rawurlencode($code) . '&name=' . rawurlencode($name ?: $code)
           . '" title="포트폴리오에 편입 — 종목 추가 폼이 열립니다 · 포트폴리오 미지정 저장 = 편입 관심종목">편입</a> ';
    }
    // 밖으로 나가는 문 — 컨센서스·업종비교처럼 DART 원본에 없는 것을 옆에 띄워 견준다
    pf_fnguide_btn($code);
    echo '<a class="btn btn-outline" href="/stock/index.php?mode=fund">← 재무분석</a></div>';
    echo '</div>';

    // 방금 본 종목들 — 상세에서 상세로 옮겨 다니는 흐름이라 목록으로 되돌아갈 것 없이 여기서 건너뛴다
    echo '<div class="card" style="padding-top:11px;padding-bottom:11px">';
    pf_fund_recent($pdo, $code);
    echo '</div>';

    // 종목 개요·태그 — 재무가 없어도 뜬다(개요는 종목의 성질이지 재무의 부속이 아니다)
    pf_fund_note_card($pdo, $code);

    if (!$series) {
        echo '<div class="warn">이 종목의 재무제표가 아직 없습니다.</div>';
        pf_foot(); return;
    }

    /* 공시일·SUE — 재무 숫자만으로는 「언제 알려졌나」를 알 수 없다.
     * 아래 일봉 차트의 ▲▼ 마커와 같은 접수일·같은 SUE라, 표에서 본 분기를 차트에서 그대로 찾을 수 있다. */
    $fil = pf_fund_filing_map($pdo, $code);

    echo '<div class="card"><h2>연도별 재무</h2>';
    echo '<div class="tbl-scroll"><table class="pf"><thead><tr>';
    echo '<th class="num">연도</th>'
       . '<th class="num" title="그 해 사업보고서의 DART 접수일 — 실적이 시장에 알려진 날입니다">공시일</th>'
       . '<th class="num" title="공시일 종가 기준 시가총액(억원) — 그 실적이 알려진 날 시장이 매기고 있던 값입니다. 시세 원장이 2019년부터라 그 이전 공시는 빈칸입니다">시총</th>'
       . '<th class="num" title="그 해 4분기 이익 서프라이즈 — (4분기 영업이익 − 전년 4분기) ÷ 자기 과거 2년 변동성. ≥+1 서프라이즈 · ≤−1 어닝쇼크">SUE</th>'
       . '<th class="num">매출액</th><th class="num">매출 증감</th>'
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

        // 연도 행이 짚는 공시는 그 해 <b>사업보고서</b>(4분기) 하나다 — 연간 누적에는 서프라이즈가 없다
        $y  = (int)$r['bsns_year'];
        $fq = $fil[$y * 4 + 4] ?? null;

        echo '<tr>';
        echo '<td class="num"><b>' . $y . '</b></td>';
        echo pf_fund_filing_cells($fq, ($y % 100) . '.4Q', true);
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
       . '<br><b>공시일</b>은 그 해 <b>사업보고서의 DART 접수일</b>입니다 — 결산이 끝나고 석 달쯤 뒤에 나오므로, '
       . '재무를 그 해 주가와 바로 견주면 <b>아직 아무도 몰랐던 숫자</b>로 보는 셈이 됩니다. '
       . '정정공시가 있으면 <b>원본(가장 이른) 접수일</b>을 씁니다. '
       . '<b>시총</b>은 <b>공시일 종가 기준 시가총액</b>(억원)입니다 — 그 실적이 알려진 날 시장이 매기고 있던 값이라, '
       . '그 해 순이익과 견주면 「그때 PER」이 나옵니다. 시세 원장이 2019년부터라 그 이전 공시는 빈칸입니다.<br>'
       . '<b>SUE</b> 는 그 해 <b>4분기</b> 이익 서프라이즈입니다 — 연간 누적에는 「예상 대비」라는 개념이 없어 '
       . '공시가 실제로 놀라움이었는지는 분기로만 잽니다. <b>±1 밖일 때만 배지</b>가 붙습니다 '
       . '(<span class="sue-b up">어닝서프라이즈 +2.1</span> 서프라이즈 · <span class="sue-b dn">어닝쇼크 -1.4</span>). '
       . '스크리너 「SUE」 열·어닝 서프라이즈 탭·아래 차트의 ▲▼ 마커와 <b>같은 계산</b>입니다.'
       . '</p>';
    echo '</div>';

    pf_fund_detail_quarters($dart, $code, $fil);
    pf_fund_detail_range($pdo, $code);
    pf_fund_detail_chart($pdo, $code);
    pf_fund_detail_band($pdo, $code);       // 재무 → 주가 → 그 «비율» 순서라 맨 아래

    echo <<<'JS'
<script>
document.addEventListener('click', function(e){
  var b = e.target.closest ? e.target.closest('.wl-star2') : null;
  if (!b) return;
  var body = new URLSearchParams({json:'1', src:'fund', code:b.getAttribute('data-code'), name:b.getAttribute('data-name')});
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

function pf_fund_detail_chart(PDO $pdo, string $code): void
{
    /* 기능 구성 — 차트설정 > 「화면별 구성」에서 켜고 끈다 (원본 카탈로그 = ChartFeat).
       ★SUE 공시 마커는 2026-08-03 부터 모듈(style/dailychart.js)이 스스로 받아 얹는다 —
         화면은 종목코드만 넘긴다(create 의 code). 버튼·스냅·토글도 전부 모듈 몫이다. */
    $FEAT = ChartFeat::vals('fund', $pdo);
    $sue  = (bool)$FEAT['overlay.sue_markers'];

    echo '<div class="card"><div class="pf-head" style="margin-bottom:10px"><div>';
    echo '<h2 style="margin:0" id="fdDailyTitle">일봉 차트</h2>';
    echo '<div class="sub">네이버 일봉입니다. 위 재무와 견주어 보세요 — '
       . '실적이 좋아지는데 주가가 빠졌다면 그게 이 화면을 만든 이유입니다.'
       . ($sue ? '<br>▲<b>어닝서프라이즈</b>(SUE ≥ ' . Thr::SUE_HIT . ' 서프라이즈)·▼<b>어닝쇼크</b>'
               . '(SUE ≤ ' . Thr::SUE_SHOCK . ') 마커는 정기공시 <b>다음 거래일</b> — '
               . '백테스트의 매수 시점 그대로입니다. 공시가 있으면 기간 바에 「SUE 공시 N」 버튼이 뜹니다.' : '')
       . '</div>';
    echo '</div><div class="act">';
    // 기간 바 [일봉|주봉 ┃ 160일 240일 480일 전체] + 사용자 지표 바 — DailyChart 공용 컴포넌트
    echo '<span id="fdPBar"></span> <span id="fdIBar"></span>';
    echo '</div></div>';
    if ($FEAT['legend.values']) echo '<div class="chart-legend" id="fdLegend"></div>';   // 지표 값 표시 자리
    echo '<div id="fdChart" style="height:380px;position:relative"></div>';
    echo '<div class="muted" id="fdNote" style="font-size:12px;margin-top:8px">불러오는 중…</div>';
    echo '</div>';

    // 차트는 공용 모듈(style/dailychart.js)이 그린다 — 라이브러리 로드도 모듈이 맡는다
    echo '<script src="/style/dailychart.js?v=56"></script>';
    echo ChartFeat::boot('fund', $pdo);       // DC_SCREEN·DC_FEATS·DC_VIEW (기능 구성 + 저장한 높이)
    echo '<script>const FD_CODE=' . json_encode($code) . ';</script>';
    echo <<<'JS'
<script>
DailyChart.load().then(function(){
  var note = document.getElementById('fdNote');
  var F = DailyChart.feats;   // 화면별 기능 구성 (차트설정 > 화면별 구성)
  // code 를 주면 SUE 공시 마커·토글은 모듈이 알아서 (기능이 꺼져 있으면 조회조차 안 한다)
  var dc = DailyChart.create('fdChart', { theme: 'light', key: 'fund', code: FD_CODE,
                                          legend: F('legend.values') ? 'fdLegend' : null });
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
    fullscreen: F('fullscreen'),
    onChange: function(info){
      var t = document.getElementById('fdDailyTitle');
      if (t) t.textContent = info.tf === 'week' ? '주봉 차트' : '일봉 차트';
      fdNote();
    }
  });
  DailyChart.indicatorBar('fdIBar', dc, { theme: 'light', key: 'fund',
                                          preset: F('preset.select') });   // 사용자 지표 + 차트틀

  note.textContent = '불러오는 중…';
  DailyChart.fetchDaily(FD_CODE, 1000).then(function(rows){
    if (!rows.length) {
      note.textContent = '일봉 데이터를 가져오지 못했습니다 (종목코드 ' + FD_CODE + ').';
      return;
    }
    dc.setData(rows);
    fdNote();
    // SUE 공시 마커는 모듈이 그린다 (create 의 code) — 스냅·토글 버튼까지 한 곳에서
  }).catch(function(){ note.textContent = '일봉을 불러오지 못했습니다.'; });
}).catch(function(){
  var n = document.getElementById('fdNote');
  if (n) n.textContent = '차트 라이브러리를 불러오지 못했습니다.';
});
</script>
JS;
}

/**
 * PER·PBR 밴드 차트 — 재무와 주가의 «비율»을 시간축에 펴 놓은 그림 (2026-08-03).
 *
 * 위의 재무를 읽고 아래 일봉으로 주가를 봤다면, 마지막으로 보는 것이 그 둘의 비율이다.
 * 그래서 상세화면 맨 아래에 둔다.
 *
 * 계산은 stock/lib/band.php 단일본, 그림은 style/bandchart.js(구성 ⑥ 「밴드형」).
 * ★밴드가 없는 종목이 적지 않다(실측: 시총 상위 40 중 PER 24 · PBR 30). 그때는 빈 차트를
 *   그리지 않고 «왜 없는지»를 그 자리에 적는다 — 적자·자본잠식이냐, 분기 재무 이력이 짧으냐,
 *   DART 수집이 비었느냐에 따라 사용자가 할 일이 다르기 때문이다.
 */
function pf_fund_detail_band(PDO $pdo, string $code): void
{
    static $css = false;
    if (!$css) {
        $css = true;
        echo '<style>'
           . '.band-2{display:grid;grid-template-columns:1fr 1fr;gap:14px}'
           . '@media(max-width:900px){.band-2{grid-template-columns:1fr}}'
           . '.band-1 h3{margin:0 0 6px;font-size:14px;font-weight:700}'
           . '.band-host{height:300px;position:relative}'
           . '.band-empty{display:flex;align-items:center;justify-content:center;height:100%;'
           . 'padding:14px;text-align:center;font-size:13px;color:#8a94a0;'
           . 'background:#fafbfc;border:1px dashed #dde3ea;border-radius:6px}'
           . '.band-leg{display:flex;flex-wrap:wrap;gap:4px 12px;margin-top:7px;font-size:11px;color:#5a6673}'
           . '.band-leg .bl{display:inline-flex;align-items:center;gap:4px}'
           . '.band-leg .bl i{width:11px;height:2px;border-radius:1px;display:inline-block}'
           . '.band-leg .muted{color:#a0a8b2}'
           // ★ .band-cur(판독기)·.band-leg .st 는 여기 없다 — 그 요소를 만드는 것이 bandchart.js 라
           //   CSS 도 모듈이 들고 다닌다(갤러리처럼 이 화면 밖에서 써도 모양이 깨지지 않게).
           . '</style>';
    }

    echo '<div class="card"><div class="pf-head" style="margin-bottom:10px"><div>';
    echo '<h2 style="margin:0;display:flex;align-items:center;gap:10px;flex-wrap:wrap">밴드 차트'
       . '<span class="band-bar" id="bandBar"></span></h2>';
    echo '<div class="sub">색색 선은 <b>주가 예측선이 아니라</b> '
       . '「그 배수로 평가받았다면 주가가 얼마였을 자리」입니다 — 지금이 <b>자기 역사 대비</b> 싼지 비싼지를 봅니다. '
       . '배수는 그 종목 과거 분포의 10·30·50·70·90% 지점이라 <b>종목끼리 견주는 값이 아닙니다</b>.<br>'
       . '선이 <b>분기마다 계단으로 꺾입니다</b> — 꺾이는 날은 결산기가 아니라 <b>DART 공시 다음 거래일</b>이라, '
       . '위 표의 「공시일」·일봉 차트의 ▲▼ 마커와 같은 날입니다(그날 시장이 알 수 있었던 값만 씁니다).<br>'
       . '차트에 <b>마우스를 올리면 그날 실제 배수</b>가 왼쪽 위에 나옵니다 — 색선은 「그 배수였다면 얼마」일 뿐 '
       . '그날 몇 배였는지는 말해 주지 않습니다. 범례 끝의 <b>기간 최저·최고</b>는 밴드선(분위수)이 잘라 낸 양 끝입니다.'
       . '</div></div></div>';

    echo '<div class="band-2">';
    echo '<div class="band-1"><h3>PER Band</h3><div class="band-host" id="bandPer"></div>'
       . '<div class="band-leg" id="bandPerLeg"></div></div>';
    echo '<div class="band-1"><h3>PBR Band</h3><div class="band-host" id="bandPbr"></div>'
       . '<div class="band-leg" id="bandPbrLeg"></div></div>';
    echo '</div>';

    echo '<p class="sub muted" style="margin:11px 0 0;font-size:12px">'
       . '세로축은 <b>원(주당)</b>이지만 계산은 <b>시가총액</b>으로 합니다 — 그래야 주식수가 식에서 사라져 '
       . '<b>액면분할이 저절로 보정</b>됩니다(과거 수정주가가 없어도 됩니다). '
       . '표시할 때만 지금 상장주식수로 나눕니다.<br>'
       . 'PER 은 <b>TTM(최근 4분기 합) 순이익</b> 기준이라 위 「연도별 재무」의 연간 기준 PER 과 다를 수 있습니다. '
       . '선이 <b>끊긴 구간</b>은 값이 0 이하(적자·자본잠식)이거나 DART 재무가 비어 있는 때입니다 — '
       . '묵은 값으로 이어 그리지 않습니다.<br>'
       . '<b>배수 다섯 개의 분위수 선택에는 백테스트 근거가 없습니다</b> — 관례를 따른 값입니다. '
       . '증권사 컨센서스가 없어 <b>미래(추정) 구간은 그리지 않습니다</b>.<br>'
       . '<b>기간(5·3·2년)은 제목 옆에서 고릅니다</b> — 종목마다 맞는 창이 다릅니다. '
       . '옛 이상치가 상단을 밀어 올린 종목은 <b>기간을 줄이면 그림이 살아납니다</b>(실측 솔본: PER 상단 39.11x → 2년이면 3.63x). '
       . '다만 <b>이익이 0 근처를 오가는 종목은 어떤 기간으로도 PER 밴드가 뜻을 갖지 못합니다</b> '
       . '— 그때는 범례의 「기간 최저·최고」가 수백 배로 벌어져 있으니 <b>PBR 쪽을 보십시오</b>.'
       . '</p>';
    echo '</div>';

    echo '<script src="/style/bandchart.js?v=3"></script>';
    echo '<script>BandChart.mount(' . json_encode($code)
       . ', {per:"bandPer", pbr:"bandPbr", perLegend:"bandPerLeg", pbrLegend:"bandPbrLeg"}, "bandBar");</script>';
}

/**
 * 분기 추이 — 저장된 누적(YTD)에서 <b>그 분기 3개월</b>을 만들어 보여 준다.
 *
 * 4분기는 보고서가 따로 없다. 사업보고서(12개월 누적)에서 3분기 누적을 뺀 것이 4분기다.
 * 앞 분기가 없으면 그 분기는 아예 만들지 않는다 — 0 으로 채우면 적자로 읽힌다.
 */
function pf_fund_detail_quarters(Dart $dart, string $code, array $fil = []): void
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
    echo '<th class="num">분기</th>'
       . '<th class="num" title="그 분기 보고서의 DART 접수일 — 실적이 시장에 알려진 날입니다 (4분기는 사업보고서)">공시일</th>'
       . '<th class="num" title="공시일 종가 기준 시가총액(억원) — 그 실적이 알려진 날 시장이 매기고 있던 값입니다. 시세 원장이 2019년부터라 그 이전 공시는 빈칸입니다">시총</th>'
       . '<th class="num" title="이익 서프라이즈 — (당분기 영업이익 − 전년동기) ÷ 자기 과거 2년 변동성. ≥+1 서프라이즈 · ≤−1 어닝쇼크">SUE</th>'
       . '<th class="num">매출액</th><th class="num">매출 증감</th>'
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
        // 행이 곧 분기라 배지에 분기를 되풀이하지 않는다 (연도별 표는 4분기 값이라 밝힌다)
        echo pf_fund_filing_cells($fil[(int)$q['bsns_year'] * 4 + (int)$q['quarter']] ?? null,
                                  (string)$q['label'], false);
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
       . '(2024년 실측 전종목의 약 1%).<br>'
       . '<b>공시일</b>은 그 분기 보고서의 <b>DART 접수일</b>(정정공시가 있으면 원본 접수일)이고, '
       . '<b>시총</b>은 그 공시일 종가 기준 시가총액(억원)이며, '
       . '<b>SUE</b> 는 그날 알려진 <b>이익 서프라이즈</b>입니다 — <b>±1 밖일 때만 배지</b>가 붙습니다 '
       . '(<span class="sue-b up">어닝서프라이즈 +2.1</span> · <span class="sue-b dn">어닝쇼크 -1.4</span>). '
       . '아래 일봉 차트의 ▲▼ 마커가 <b>바로 이 줄</b>입니다 — 마커는 매수 시점 정의대로 공시 <b>다음 거래일</b>에 찍힙니다.</p>';
    echo '</div>';
}

// ══════════════════════════════════════════════════════════════════════
//  설정 > 시세 — 「이 숫자는 언제 것인가」를 한 자리에서 (2026-08-06 신설)
//
//  이 사이트의 시세는 한 곳에서 오지 않는다. 화면 다섯이 각자 다른 표를 읽고,
//  그 표를 채우는 크론이 일곱이며, 어떤 값은 DB 를 아예 거치지 않고 화면이 즉석에서 받는다.
//  사슬이 코드에 흩어져 있어서 「지금 이 등락률이 언제 것인가」를 매번 되짚어야 했다.
//
//  ★이 화면은 <b>아무것도 고치지 않는다</b> — 보는 자리다(단타 규칙 9 와 같은 성격).
//    시세를 만지는 손잡이는 크론(cron_job.php TASKS)과 상수(QUOTE_MAX_AGE 등)이고,
//    여기서는 「그 손잡이가 지금 어떤 값인가」와 「그래서 데이터가 지금 어떤가」만 보여 준다.
//
//  ★카탈로그·크론 시각을 이 파일에 적지 않는다 — stock/lib/quote.php 가 원본이고,
//    크론 시각은 그 파일이 cron_job.php 의 TASKS 를 읽어 온다. 손으로 옮겨 적으면
//    크론을 옮기는 날 이 화면이 조용히 거짓말을 시작한다.
// ══════════════════════════════════════════════════════════════════════
function pf_page_quote(PDO $pdo, Pf $pf): void
{
    require_once __DIR__ . '/lib/quote.php';

    $sources = quote_sources();
    $screens = quote_screens();
    $probes  = quote_probe_all($pdo);
    $tasks   = quote_cron_tasks();

    /** task 이름 → 「평일 15:50」 꼴 한 줄. 레지스트리에 없으면 이름만 */
    $cronLine = static function (string $name) use ($tasks): string {
        $t = $tasks[$name] ?? null;
        if (!$t) return '<code>' . pf_h($name) . '</code>';
        $c = (string)($t['cron'] ?? '');
        $s = '<code>' . pf_h($name) . '</code>';
        if ($c === '') return $s . ' <span class="muted">(크론 미등록 — 수동)</span>';
        return $s . ' <b>' . pf_h(quote_cron_when($c)) . '</b> <span class="qs-cron">' . pf_h($c) . '</span>'
             . (!empty($t['bg']) ? ' <span class="qs-bg" title="30초 우회 · 자기호출 백그라운드">bg</span>' : '');
    };

    pf_head('시세 설정', 'setting');
    pf_subtabs('quote');

    echo '<style>
    .qs-note{color:#5f7183;font-size:13px;line-height:1.75}
    .qs-note b{color:#22303f}
    .qs-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px}
    .qs-kpi{background:#fcfdfe;border:1px solid #e3eaf0;border-radius:9px;padding:11px 13px}
    .qs-kpi h3{margin:0 0 7px;font-size:12.5px;color:#5f7183;letter-spacing:-.01em;font-weight:800}
    .qs-kpi dl{margin:0;display:grid;grid-template-columns:auto 1fr;gap:3px 10px;font-size:12.5px}
    .qs-kpi dt{color:#8b98a5;white-space:nowrap}
    .qs-kpi dd{margin:0;text-align:right;color:#22303f;font-variant-numeric:tabular-nums}
    .qs-kpi dd.hot{color:#b3261e;font-weight:800}
    .qs-sec{margin:22px 0 9px;font-size:14px;font-weight:800;color:#22303f;letter-spacing:-.01em;
      padding-bottom:6px;border-bottom:2px solid #e3eaf0}
    .qs-sec span{font-weight:600;color:#8b98a5;font-size:12.5px;margin-left:8px}
    table.pf td.qs-w{white-space:normal;line-height:1.55;max-width:520px}
    table.pf td.qs-src{white-space:normal;font-size:12px;line-height:1.5}
    .qs-live{display:inline-block;padding:1px 6px;border-radius:5px;background:#eef6ff;color:#1d5c93;
      font-size:11px;font-weight:800;border:1px solid #cfe2f4}
    .qs-tbl{display:inline-block;padding:1px 6px;border-radius:5px;background:#f3f6f9;color:#44586b;
      font-size:11.5px;font-weight:800;font-family:ui-monospace,Menlo,Consolas,monospace}
    .qs-cron{color:#7d8b99;font-size:11.5px;font-family:ui-monospace,Menlo,Consolas,monospace}
    .qs-bg{display:inline-block;padding:0 5px;border-radius:4px;background:#fff3d6;color:#8a6d1f;
      font-size:10.5px;font-weight:800;border:1px solid #f0dfae}
    .qs-warn{margin:9px 0 0;padding:0 0 0 17px;font-size:12.5px;color:#6b7b8b;line-height:1.7}
    .qs-warn li{margin-bottom:3px}
    .qs-warn code,.qs-note code,table.pf code{background:#f2f6fa;border:1px solid #e1e9f0;border-radius:4px;
      padding:0 4px;font-size:11.5px;font-family:ui-monospace,Menlo,Consolas,monospace;color:#37506a}
    .qs-head{display:flex;align-items:baseline;gap:9px;flex-wrap:wrap;margin-bottom:9px}
    .qs-head h2{margin:0;font-size:15px}
    .qs-head .qs-what{color:#8b98a5;font-size:12.5px}
    .qs-two{display:grid;grid-template-columns:1fr 300px;gap:16px;align-items:start}
    @media (max-width:1000px){.qs-two{grid-template-columns:1fr}}
    </style>';

    echo '<div class="pf-head"><div><h1>시세 설정</h1>'
       . '<div class="sub">시세가 <b>어디서 와서 언제 갱신되는지</b>를 화면·표·크론 세 축으로 편 자리입니다. '
       . '이 화면은 값을 바꾸지 않습니다 — 보는 자리입니다.</div></div></div>';

    // ── ① 지금 상태 ─────────────────────────────────────────────────────
    echo '<div class="card"><h2>① 지금 상태</h2>';
    echo '<div class="qs-note" style="margin-bottom:11px">'
       . '가장 자주 어긋나는 네 곳입니다. <b>빨간 값</b>이면 그 표를 채우는 크론을 먼저 의심합니다 '
       . '(아래 ④ 하루 시간표).</div>';
    echo '<div class="qs-grid">';
    foreach (['all_stock_info', 'all_etf_price', 'krx_amt', 'dt_min'] as $k) {
        $s = $sources[$k] ?? null;
        $p = $probes[$k]['rows'] ?? [];
        if (!$s || !$p) continue;
        echo '<div class="qs-kpi"><h3>' . pf_h($s['title']) . ' <span class="muted">'
           . pf_h($s['label']) . '</span></h3><dl>';
        foreach ($p as $r) {
            echo '<dt>' . pf_h($r[0]) . '</dt><dd' . (!empty($r[2]) ? ' class="hot"' : '') . '>'
               . ($r[1] ?? '—') . '</dd>';
        }
        echo '</dl></div>';
    }
    echo '</div></div>';

    // ── ② 화면별 시세 지도 ───────────────────────────────────────────────
    echo '<div class="qs-sec">② 화면별 시세 지도<span>다섯 화면이 각 숫자를 어디서 읽나</span></div>';

    foreach ($screens as $sc) {
        echo '<div class="card">';
        echo '<div class="qs-head"><h2><a href="' . pf_h($sc['href']) . '">' . pf_h($sc['label']) . '</a></h2>'
           . '<span class="qs-what">' . $sc['note'] . '</span></div>';
        echo '<div class="tbl-scroll"><table class="pf"><thead><tr>'
           . '<th style="width:230px">화면에 보이는 것</th><th style="width:210px">원천</th>'
           . '<th>갱신 경로</th><th style="width:190px">실효 신선도</th></tr></thead><tbody>';
        foreach ($sc['rows'] as $r) {
            [$what, $src, $how, $fresh] = $r;

            /* 원천 표기 — DB 표는 회색 칩(그 표의 상세로 앵커), 즉석 수집은 파란 칩.
             * 둘을 눈으로 가르는 것이 이 표의 요점이다: 파란 칩은 <b>DB 를 안 거친다</b>. */
            if (strncmp($src, 'live:', 5) === 0) {
                $cell = '<span class="qs-live">즉석 수집</span> ' . substr($src, 5);
            } else {
                $parts = [];
                foreach (preg_split('/\s+·\s+/', $src) as $one) {
                    $key = trim(strip_tags($one));
                    $parts[] = isset($sources[$key])
                        ? '<a class="qs-tbl" href="#src-' . pf_h($key) . '">' . pf_h($key) . '</a>'
                        : '<span class="qs-tbl">' . pf_h($key) . '</span>';
                }
                $cell = implode(' ', $parts);
            }

            echo '<tr><td class="qs-w">' . $what . '</td>'
               . '<td class="qs-src">' . $cell . '</td>'
               . '<td class="qs-src">' . $how . '</td>'
               . '<td class="qs-src">' . $fresh . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    // ── ③ 원천 표 상세 ───────────────────────────────────────────────────
    echo '<div class="qs-sec">③ 원천 표<span>무엇을 담고 · 누가 채우고 · 얼마나 보관하나</span></div>';

    foreach ($sources as $key => $s) {
        echo '<div class="card" id="src-' . pf_h($key) . '">';
        echo '<div class="qs-head"><h2>' . pf_h($s['title']) . '</h2>'
           . '<span class="qs-tbl">' . pf_h($s['label']) . '</span>'
           . '<span class="qs-what">' . pf_h($s['grain']) . '</span></div>';

        echo '<div class="qs-two"><div>';
        echo '<table class="pf"><tbody>';
        echo '<tr><th style="width:110px;text-align:left">담는 것</th><td class="qs-w">' . $s['what'] . '</td></tr>';
        echo '<tr><th style="text-align:left">바깥 소스</th><td class="qs-w">' . $s['src'] . '</td></tr>';

        $cr = [];
        foreach (($s['tasks'] ?? []) as $t) $cr[] = $cronLine($t);
        echo '<tr><th style="text-align:left">채우는 크론</th><td class="qs-w">'
           . ($cr ? implode('<br>', $cr) : '<span class="muted">없음</span>') . '</td></tr>';

        if (!empty($s['ui'])) {
            echo '<tr><th style="text-align:left">화면이 하는 몫</th><td class="qs-w">'
               . '<span class="qs-live">화면</span> ' . $s['ui'] . '</td></tr>';
        }
        echo '<tr><th style="text-align:left">보관</th><td class="qs-w">' . $s['keep'] . '</td></tr>';
        echo '</tbody></table>';

        if (!empty($s['warn'])) {
            echo '<ul class="qs-warn">';
            foreach ($s['warn'] as $w) echo '<li>' . $w . '</li>';
            echo '</ul>';
        }
        echo '</div>';

        // 오른쪽 — 실측
        echo '<div class="qs-kpi"><h3>지금</h3><dl>';
        foreach (($probes[$key]['rows'] ?? []) as $r) {
            echo '<dt>' . pf_h($r[0]) . '</dt><dd' . (!empty($r[2]) ? ' class="hot"' : '') . '>'
               . ($r[1] ?? '—') . '</dd>';
        }
        echo '</dl></div>';
        echo '</div></div>';
    }

    // ── ④ 하루 시간표 ────────────────────────────────────────────────────
    echo '<div class="qs-sec">④ 하루 시간표<span>시세를 만드는 크론만 · 시각은 '
       . '<code>cron_job.php</code> 의 <code>TASKS</code> 에서 읽어 옵니다</span></div>';

    /* 어떤 task 가 시세를 만드나 — ③ 카탈로그가 그것을 이미 알고 있다. 여기 다시 적지 않는다. */
    $quoteTasks = [];
    foreach ($sources as $key => $s) {
        foreach (($s['tasks'] ?? []) as $t) {
            $quoteTasks[$t] = $quoteTasks[$t] ?? [];
            $quoteTasks[$t][] = $s['label'];
        }
    }
    // 등록된 것을 시각순으로, 미등록(수동)은 뒤로
    uksort($quoteTasks, static function ($a, $b) use ($tasks) {
        $ca = (string)($tasks[$a]['cron'] ?? '');
        $cb = (string)($tasks[$b]['cron'] ?? '');
        $ma = ($ca === '') ? 9999 : quote_cron_minute($ca);
        $mb = ($cb === '') ? 9999 : quote_cron_minute($cb);
        return ($ma <=> $mb) ?: strcmp($a, $b);
    });

    echo '<div class="card"><div class="tbl-scroll"><table class="pf"><thead><tr>'
       . '<th style="width:96px">시각</th><th style="width:140px">task</th>'
       . '<th style="width:150px">crontab</th><th>하는 일</th><th style="width:230px">채우는 표</th>'
       . '</tr></thead><tbody>';
    foreach ($quoteTasks as $name => $labels) {
        $t = $tasks[$name] ?? [];
        $c = (string)($t['cron'] ?? '');
        $when = ($c === '')
            ? '<span class="muted">수동</span>'
            : '<b>' . pf_h(quote_cron_when($c)) . '</b>';
        echo '<tr><td>' . $when . '</td>'
           . '<td><span class="qs-tbl">' . pf_h($name) . '</span>'
           . (!empty($t['bg']) ? ' <span class="qs-bg">bg</span>' : '') . '</td>'
           . '<td class="qs-cron">' . pf_h($c !== '' ? $c : '—') . '</td>'
           . '<td class="qs-w">' . pf_h((string)($t['desc'] ?? '')) . '</td>'
           . '<td class="qs-src">' . pf_h(implode(' · ', array_unique($labels))) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<div class="qs-note" style="margin-top:11px">'
       . '★ 시각 열은 <b>첫 실행</b>입니다 — <code>stock_news</code> 처럼 하루 여러 번 도는 잡은 '
       . 'crontab 원문을 보세요. 실제 스케줄의 진실은 cron-job.org 에 있고, 위 값은 <code>TASKS</code> 에 '
       . '적어 둔 <b>같은 값의 기록</b>입니다(시각을 바꿨으면 그 파일도 함께 고칩니다).<br>'
       . '★ <span class="qs-bg">bg</span> 는 응답 30초를 넘기는 잡입니다. 크론 사이트에는 0.07초 만에 '
       . '「성공」으로 보이므로, 실제로 무엇을 했는지는 <code>&amp;log=1</code> 로만 확인됩니다.'
       . '</div></div>';

    // ── ⑤ 외부 API ───────────────────────────────────────────────────────
    echo '<div class="qs-sec">⑤ 바깥 소스<span>어디가 끊기면 무엇이 멈추나</span></div>';
    echo '<div class="card"><div class="tbl-scroll"><table class="pf"><thead><tr>'
       . '<th style="width:120px">소스</th><th style="width:220px">주소</th>'
       . '<th style="width:300px">한도 · 차단 특성</th><th>끊기면</th></tr></thead><tbody>';
    foreach (quote_externals() as [$name, $host, $limit, $risk]) {
        echo '<tr><td><b>' . pf_h($name) . '</b></td>'
           . '<td class="qs-cron">' . pf_h($host) . '</td>'
           . '<td class="qs-w">' . $limit . '</td>'
           . '<td class="qs-w">' . $risk . '</td></tr>';
    }
    echo '</tbody></table></div></div>';

    // ── ⑥ 손잡이 ─────────────────────────────────────────────────────────
    echo '<div class="qs-sec">⑥ 손잡이<span>시세 동작을 바꾸려면 어디를 고치나</span></div>';
    echo '<div class="card"><div class="tbl-scroll"><table class="pf"><thead><tr>'
       . '<th style="width:230px">무엇</th><th style="width:110px">지금 값</th>'
       . '<th style="width:250px">고치는 곳</th><th>고치기 전에 알아야 할 것</th>'
       . '</tr></thead><tbody>';

    $knobs = [
        ['단타 장중 갱신 주기', Dt::TICK_SEC . '초', '<code>classes/Dt.class</code> <code>TICK_SEC</code>',
         '★★<b>한 숫자가 넷을 정한다</b> — 목록 타이머 · 차트 덧대기 타이머 · 서버 신선도 창'
         . '(<code>refreshQuotesLive</code>) · 「LIVE N초」 글자. 넷이 갈리면 <b>한 화면이 두 값을 말한다</b>. '
         . '대상은 <code>Dt::targetCodes()</code> 뿐이고 키움이 100종목을 한 요청에 주므로 '
         . '한 tick 이 <b>1콜</b>이다.'],
        ['단타 시세 소스', '키움 ka10095', '<code>classes/Kiwoom.class</code> <code>TR_MAP</code>·<code>quotes()</code>',
         '조회 TR 화이트리스트에 있어야 부를 수 있다(주문 API 차단 장치). '
         . '<code>_AL</code>(SOR)을 <b>붙이지 않는다</b> — 분봉과 같은 KRX 단독 기준이라야 한 종목이 '
         . '소스마다 다른 말을 하지 않는다. 실패하면 네이버로 폴백한다.'],
        ['단타 「관심종목·시총」 탐색 패널·다른 탐색 화면', '크론 주기', '—',
         '실시간 대상이 <b>아니다</b>. 전종목을 화면 타이머로 받으면 28콜 × ' . Dt::TICK_SEC
         . '초 = 하루 1만 콜이라 네이버 IP 차단 위험에 걸린다.'],
        ['그 밖 화면의 시세 신선도', NaverFinanceAPI::QUOTE_MAX_AGE . '초',
         '<code>classes/NaverFinanceAPI.class</code> <code>QUOTE_MAX_AGE</code>',
         '포트폴리오 계열(<code>pf_load_calc()</code>)·관심종목·크론 <code>dart_eod</code> 의 시세 메우기가 '
         . '<b>같은 판정</b>을 쓴다 — 그래야 NXT 갱신분과 부딪히는 규칙이 한 군데에만 있다. '
         . '<b>마감 뒤 규칙에는 영향이 없다</b> — 거기서는 늘 「오늘 '
         . NaverFinanceAPI::MARKET_CLOSE . ' 이전」이라야 NXT 갱신분과 안 부딪힌다.'],
        ['정규장 마감 기준시각', NaverFinanceAPI::MARKET_CLOSE,
         '<code>classes/NaverFinanceAPI.class</code> <code>MARKET_CLOSE</code>',
         '이 시각 뒤로는 「오늘 이 시각 이전인 것만」 다시 받는다. NXT 값(15:31~)이 저절로 빠져 '
         . '정규장 종가와 서로를 밀어내지 않는다.'],
        ['단타 분봉 보관 창', Dt::WINDOW_DAYS . '거래일',
         '<code>classes/Dt.class</code> <code>WINDOW_DAYS</code>',
         '★늘려도 <b>과거는 안 채워진다</b> — 네이버는 7거래일까지만 주고 그 뒤로는 어떤 파라미터로도 못 받는다. '
         . '키움이 1년을 보관하므로 구멍 치유로만 메워진다.'],
        ['단타 풀 상한', Dt::POOL_MAX . '종목',
         '<code>classes/Dt.class</code> <code>POOL_MAX</code>',
         '이 숫자는 <b>「단타 풀」의 것이지 수집 대상의 것이 아니다</b> — 보유 종목은 담지 않고 '
         . '<code>Dt::targetCodes()</code> 가 참조로 더한다.'],
        ['크론 시각 · 모드 · 옵션', '—', '<code>cron_job.php</code> 의 <code>TASKS</code>',
         '★<b>크론 사이트를 건드리지 않는다.</b> 등록되는 URL 은 영원히 '
         . '<code>cron_job.php?task=…&amp;k=…</code> 하나이고, 무엇을 어떻게 부를지는 그 레지스트리가 정한다. '
         . '단 <b>실행 시각 자체는 cron-job.org</b> 에 있다 — 시각을 바꿨으면 <code>TASKS</code> 의 '
         . '<code>cron</code> 기록도 함께 고쳐야 이 화면이 참말을 한다.'],
        ['네이버 호출 간격', '100ms 고정', '<code>cron/dart_collect.php</code> <code>gap=</code>',
         '★<b>줄이지 말 것.</b> 전 사이트의 시세가 네이버 단일 소스라, 차단되면 포트폴리오·시뮬레이터·'
         . '장중 크론이 통째로 멈춘다.'],
    ];
    foreach ($knobs as [$what, $val, $where, $note]) {
        echo '<tr><td class="qs-w"><b>' . pf_h($what) . '</b></td>'
           . '<td class="num"><b>' . pf_h($val) . '</b></td>'
           . '<td class="qs-src">' . $where . '</td>'
           . '<td class="qs-w">' . $note . '</td></tr>';
    }
    echo '</tbody></table></div></div>';

    // ── 진단 링크 ────────────────────────────────────────────────────────
    echo '<div class="card"><h2>진단 (읽기 전용 · 실행하지 않습니다)</h2>';
    echo '<div class="qs-note">각 URL 뒤에 <code>&amp;k=&lt;디스패처 토큰&gt;</code> 을 붙여 엽니다 '
       . '(토큰 값은 <code>cron_job.php</code> 의 <code>CRON_JOB_KEY</code>). '
       . '읽기 전용 조회(<code>log=1</code>·<code>status=1</code>·<code>explain=1</code>)는 bg 가 안 붙어 '
       . '결과가 화면에 바로 나옵니다.<br><br>'
       . '<code>/cron_job.php?task=list</code> — 등록된 잡 전체 (크론식·파일·인자·설명)<br>'
       . '<code>/cron_job.php?task=dart_status</code> — DART·KRX 적재 현황<br>'
       . '<code>/cron_job.php?task=dart_log&amp;n=80</code> — 마지막 dart bg 실행 로그<br>'
       . '<code>/cron_job.php?task=dt_min&amp;log=1</code> — 단타 분봉 수집 로그 '
       . '<span class="muted">(bg 라 크론 이력에는 <code>queued</code> 한 줄만 남습니다)</span><br>'
       . '<code>/cron_job.php?task=etf_update&amp;log=1</code> — ETF 편입종목 갱신 로그<br>'
       . '<code>/cron_job.php?task=&lt;이름&gt;&amp;explain=1</code> — 실행하지 않고 '
       . '「무엇을 어떻게 부를지」만 (부작용 0)</div></div>';

    pf_foot();
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
        if ($sel !== null) {
            /* 이월손익·배당도 원금과 같이 <b>이력의 합계</b>라 직접 못 고친다 — 아래 카드로 보낸다. */
            echo '<label class="fld">이월·배당'
               . '<button type="button" class="fld-fixed" onclick="pfOpenInc()" '
               . 'title="클릭하면 이월손익·배당 입력 창이 열립니다">' . pf_n((int)($sel['income_pl'] ?? 0))
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

        /* ── 이월·배당 이력 — 체결기록으로는 만들 수 없는 돈이다.
         *   ①이월손익 = 이 포트폴리오에 담기 전 그 계좌에서 이미 난 손익
         *   ②배당금  = 팔지 않아도 들어오므로 매도 기록에 영영 안 잡힌다
         *   원금 이력과 <b>같은 모양</b>으로 둔다(합계가 곧 값이고, 언제·얼마·왜가 남는다). */
        $incs   = $pf->incomeFlows($selId);
        $incSum = (int)($sel['income_pl'] ?? 0);
        $kinds  = Pf::INCOME_KINDS;

        echo '<div class="card"><h2>이월·배당 이력 — ' . pf_h($sel['name'])
           . ' <span class="muted" style="font-weight:600;font-size:12px">' . count($incs) . '건</span></h2>';
        echo '<div class="fld-row" style="margin-bottom:11px">';
        echo '<div class="prin-now"><div class="k">합계</div><div class="v">' . pf_n($incSum) . '</div></div>';
        echo '<button type="button" class="btn btn-primary" onclick="pfOpenInc()">＋ 이월손익 / 배당금</button>';
        echo '<span class="muted" style="font-size:12px;align-self:center">'
           . '예수금과 <b>실현손익</b>에 더해집니다. 원금(수익률 분모)은 그대로 둡니다 '
           . '— 번 돈이지 넣은 돈이 아니기 때문입니다.</span>';
        echo '</div>';

        if (!$incs) {
            echo '<p class="muted" style="font-size:13px;margin:0">아직 이월손익·배당 기록이 없습니다.</p>';
        } else {
            echo '<div class="tbl-scroll"><table class="pf"><thead><tr>';
            echo '<th style="width:110px">일자</th><th class="center" style="width:80px">구분</th>';
            echo '<th class="num" style="width:130px">금액</th><th>사유</th>';
            echo '<th class="center" style="width:70px">삭제</th></tr></thead><tbody>';
            foreach ($incs as $w) {
                $amt = (int)$w['amount'];
                echo '<tr>';
                echo '<td>' . pf_h($w['flow_at']) . '</td>';
                echo '<td class="center">' . pf_h($kinds[$w['kind']] ?? $kinds['etc']) . '</td>';
                echo '<td class="num"><span class="' . pf_updown($amt) . '">'
                   . ($amt > 0 ? '+' : '') . pf_n($amt) . '</span></td>';
                echo '<td style="white-space:normal">' . pf_h($w['reason']) . '</td>';
                echo '<td class="center">';
                echo '<form class="inline" method="post" action="/stock/api.php?module=portfolio&action=income_del" '
                   . 'onsubmit="return confirm(\'이 기록을 지우면 예수금과 실현손익이 그만큼 되돌아갑니다. 삭제할까요?\')">';
                echo '<input type="hidden" name="fid" value="' . $selId . '">';
                echo '<input type="hidden" name="id" value="' . (int)$w['id'] . '">';
                echo '<button class="btn btn-danger btn-sm" type="submit">삭제</button></form>';
                echo '</td></tr>';
            }
            echo '</tbody><tfoot><tr><td colspan="2">합계</td>';
            echo '<td class="num">' . pf_n($incSum) . '</td><td colspan="2"></td></tr></tfoot>';
            echo '</table></div>';
        }
        echo '</div>';

        // ── 이월손익/배당 입력 모달 (원금 모달과 같은 골격)
        echo '<div class="pf-modal-back" id="incModal" onclick="if(event.target===this)pfCloseInc()">';
        echo '<div class="pf-modal" role="dialog" aria-modal="true">';
        echo '<div class="pfm-head"><div>이월손익 · 배당금 — ' . pf_h($sel['name']) . '</div>';
        echo '<button type="button" class="pfm-x" onclick="pfCloseInc()" aria-label="닫기">✕</button></div>';
        echo '<form class="pfm-body" method="post" action="/stock/api.php?module=portfolio&action=income_add">';
        echo '<input type="hidden" name="fid" value="' . $selId . '">';
        echo '<div class="fld-row">';
        echo '<label class="fld">구분<select name="kind" style="width:120px">';
        foreach ($kinds as $k => $label) {
            echo '<option value="' . pf_h($k) . '"' . ($k === 'dividend' ? ' selected' : '') . '>'
               . pf_h($label) . '</option>';
        }
        echo '</select></label>';
        /* ★ 음수를 허용한다(.neg-ok) — 배당소득세를 따로 적거나 이월손익이 손실일 수 있다. */
        echo '<label class="fld">금액<input type="text" class="num-comma neg-ok" inputmode="numeric" name="amount" '
           . 'style="width:160px" placeholder="0" required autocomplete="off"></label>';
        echo '<label class="fld">일자<input type="date" name="flow_at" value="' . date('Y-m-d') . '" required></label>';
        echo '<label class="fld" style="flex:1;min-width:200px">사유'
           . '<input type="text" name="reason" maxlength="80" autocomplete="off" '
           . 'placeholder="예: 삼성전자 배당금, 등록 전 실현이익"></label>';
        echo '</div>';
        echo '<div class="pfm-foot"><span class="muted">손실·세금은 앞에 <b>−</b> 를 붙입니다 · '
           . '현재 합계 <b>' . pf_n($incSum) . '</b></span>';
        echo '<button class="btn btn-primary" type="submit">반영</button></div>';
        echo '</form></div></div>';

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
function pfOpenInc(){ pfModal('incModal', true); }
function pfCloseInc(){ pfModal('incModal', false); }
document.addEventListener('keydown', function(e){
  if (e.key === 'Escape'){ pfCloseFolio(); pfCloseFlow(); pfCloseInc(); }
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
// 종목의 시장은 종목 설정 폼(pf_render_position_form)에서 관리한다.
// 현재가·최고가 입력칸도 2026-08-11 삭제 — syncPrices()/refreshQuotes() 자동 갱신만 남는다.

// ══════════════════════════════════════════════════════════════════════
//  퀀트 — 거래대금 60거래일 신고가 (2026-07-31)
//  데이터 = krx_amt 전종목 일별 원장 (2024-08~ · 확정 T+1 KRX · 당일은 네이버 잠정 src='n')
// ══════════════════════════════════════════════════════════════════════

/**
 * 배지 판정 — <b>2026-07-31 백테스트로 확정한 임계</b> (창 120거래일 · 하한 100억 · 신호 6,942건).
 * 근거 수치는 mode=quantstat 에 전문이 있다. 우선순위는 "나쁜 쪽 먼저" —
 * 불꽃형(중앙 −4~−7%)이 매집형 조건과 겹치면 위험 쪽으로 판정해야 안전하다.
 *
 * ★ 120일 창에서는 <b>매집형 단독(20평비≤5)이 경계선</b>이었다(후반기 중앙 −0.13%로 부호 반전).
 *   견고하게 이긴 것은 <b>매집형 ∧ 등락 0~10%</b> 뿐(+1.74% · 55.2% · 두 기간 모두 ＋)
 *   → 🟢 배지에 등락 조건까지 넣는다. 등락이 밖이면(하락하며·급등하며 찍은 최고 거래대금) 중립.
 *
 * ★★ <b>불꽃형 = 옛 「폭발형」+「추격주의」</b> (2026-08-02 사용자: 둘은 같은 뜻이다).
 *   거래대금이 20배로 터지든 주가가 +20% 뛰든 <b>과열되어 터진 하루</b>라는 성격이 같고,
 *   처방도 같다(돌파해도 매수 금지 · −5.7~−7.6%). 그래서 <b>배지는 하나</b>로 합쳤다.
 *   단 두 조건의 <b>실측은 각각 잰 것</b>이고 모집단도 겹치므로 <b>툴팁에는 어느 조건이 걸렸는지</b>를
 *   그 조건의 수치와 함께 남긴다 — 합친 것은 이름이지 측정이 아니다.
 * @return array [정렬순서, 클래스, 라벨, 툴팁]
 */
function pf_surge_badge(?float $avgMul, ?float $chg): array
{
    // M5 — 임계 정본은 classes/Thr.class. 알림(pf_alert_badge)·스냅샷·accBoxes SQL 이 같은 상수를 본다.
    if ($chg !== null && $chg >= Thr::FLAME_CHG) {
        return [2, 'qb-flame', '불꽃형', '신호일 등락 +20% 이상 폭등 — 실측 +20일 초과수익 중앙 -7.23% · 승률 34.6%'];
    }
    if ($avgMul !== null && $avgMul >= Thr::FLAME_AVGMUL) {
        return [2, 'qb-flame', '불꽃형', '거래대금이 20일 평균의 20배 이상 폭발 — 실측 중앙 -4.24% · 승률 36.7%'];
    }
    if ($avgMul !== null && $avgMul <= Thr::ACC_AVGMUL_MAX
        && $chg !== null && $chg >= Thr::ACC_CHG_MIN && $chg < Thr::ACC_CHG_MAX) {
        return [0, 'qb-acc', '매집형', '20일 평균의 5배 이하 + 등락 0~10%로 조용히 차오른 최고 거래대금 — 실측 중앙 +1.74% · 승률 55.2%'];
    }
    return [1, 'qb-neu', '중립', '실측상 우위도 열위도 뚜렷하지 않은 구간 (매집형인데 하락·급등하며 찍은 경우 포함) — 중립×돌파도 실측 동전(-1.23% · 47.5%)'];
}

/** 공용 스타일 — 목록·검증 두 화면이 같이 쓴다 */
function pf_quant_css(): void
{
    echo '<style>
.wl-star{border:0;background:none;cursor:pointer;font-size:15px;color:#c8d3dd;padding:0;line-height:1}
.wl-star:hover,.wl-star.on{color:#f0a500}
tr.q-row{cursor:pointer}tr.q-row:hover td{background:#f7fafc}
tr.q-row.q-sel td{background:#fff6dd}
.q-note{font-size:12px;color:#89a;margin:6px 0 0}
.q-prov{background:#fff8e6;border:1px solid #f0dfae;border-radius:8px;padding:8px 12px;margin:0 0 12px;font-size:13px}
table.qstat{border-collapse:collapse;margin:8px 0 16px}
table.qstat th,table.qstat td{border:1px solid #dfe6ec;padding:4px 10px;font-size:13px;text-align:right}
table.qstat th{background:#f4f7fa;text-align:center}table.qstat td:first-child{text-align:left}
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
    // 기본값은 Thr 정본(원) 을 억으로 환산 — 화면 기본값과 accBoxes·알림의 하한이 갈리지 않게 (M5)
    $minEok = (float)str_replace(',', '', (string)($_GET['min'] ?? (string)(Thr::SURGE_MIN_AMT / 1e8)));
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

    /* ── 박스 상향돌파(5조건) 압축 보기 (2026-08-10 사용자 지시) ──
     * ★★<b>압축이 기본값이다</b>(같은 날 재지시 — 「하단에 박스상향돌파만, 다른 건 보여줄 필요 없어」).
     *   그 날 신호 전체는 `&bx=0`(「전체 보기」 칩)으로만 푼다.
     * ★판정을 여기서 다시 하지 않는다 — `bx_cand`(16:20 크론 적재)를 <b>읽기만</b> 한다.
     *   그래서 <b>오늘 날짜는 적재 전까지 빈다</b> — 잠정치에는 시가·고가·저가가 없어 장중에는
     *   5조건 판정 자체가 성립하지 않는다(③④⑤가 그 값을 본다). 다음날 13:05 확정값이 덮이면
     *   크론이 직전 거래일을 재판정하므로 어제까지는 늘 확정 판정이다. */
    $bxOn  = ((string)($_GET['bx'] ?? '1') !== '0');
    $bxMap = [];
    if ($day !== '') {
        try {
            $q3 = $pdo->prepare("SELECT code, grade, src FROM bx_cand WHERE d = ?");
            $q3->execute([$day]);
            foreach ($q3->fetchAll(PDO::FETCH_ASSOC) as $b3) $bxMap[$b3['code']] = $b3;
        } catch (Throwable $e) { /* 표가 없어도 목록은 뜬다 */ }
    }
    $bxHidden = 0;
    if ($bxOn) {
        $keep = [];
        foreach ($rows as $r) {
            if (isset($bxMap[$r['code']])) $keep[] = $r;
            else $bxHidden++;
        }
        $rows = $keep;
    }

    /* ── 📦 박스 상향돌파 추적 — 「패턴이 나왔을 때만 보이고, 결과(피드백)가 따라온다」
     *   (2026-08-10 사용자 지시: 「해당 패턴이 나왔을 때만 이 화면에 보여주고 피드백 받는 것」).
     * ★여기도 판정을 다시 하지 않는다 — bx_cand 의 후보·결과(brk_kind/brk_ret)를 «읽기만» 한다.
     *   결과 칸이 곧 피드백이다: 익절/손절/미도달은 브래킷(+15/−10·5일) 실측, 사후 미완은 진행중. */
    $bxTrack = []; $bxTrackBox = []; $bxLastD = '';
    if ($day !== '') {
        try {
            $q4 = $pdo->prepare("SELECT code, d, name, src, grade, chg_pct, amt,
                                        brk_kind, brk_ret, n_post, q_ohlc
                                   FROM bx_cand
                                  WHERE d <= ? AND d >= DATE_SUB(?, INTERVAL " . KrxAmt::TRACK_DAYS . " DAY)
                                  ORDER BY d DESC, z DESC LIMIT 20");
            $q4->execute([$day, $day]);
            $bxTrack = $q4->fetchAll(PDO::FETCH_ASSOC);
            if ($bxTrack) {
                $bxTrackBox = $krx->boxStatusMany(
                    array_map(fn($t) => ['code' => $t['code'], 'd' => $t['d']], $bxTrack));
            } else {
                $bxLastD = (string)($pdo->query("SELECT MAX(d) FROM bx_cand WHERE d <= "
                                              . $pdo->quote($day))->fetchColumn() ?: '');
            }
        } catch (Throwable $e) { /* 표가 없어도 목록은 뜬다 */ }
    }

    /* ── 박스 상태(지지·저항 경로) ──
     * ★2026-08-10 전면 재정의 — boxStatusMany 가 «박스 상향돌파(5조건) 박스»에만 경로를 단다
     *   (단일본 게이트 · KrxAmt 주석). 「매집형 박스 추적」 카드와 그 데이터(accBoxes·surgeEventStates)는
     *   같은 날 삭제했다 — 그 카드의 모집단(매집형 전체)이 새 기준 밖이다. */
    /* 배지 표시 설정(설정 > 신호분석 설정 · BadgeFeat) — 끈 배지는 계산도 건너뛴다.
     * ★끄는 것은 «그리기»뿐이다 — $box·$mom 은 이 화면에서 표시 말고는 아무도 안 쓰므로 안전하다
     *   (정렬·집계·📦압축 필터는 pf_surge_badge·bx_cand 소관이라 이 스위치와 무관하게 그대로다). */
    $bq = BadgeFeat::vals('quant', $pdo);

    $box = [];
    if ($bq['qpath']) {
        try {
            $box = $krx->boxStatusMany(array_map(fn($r) => ['code' => $r['code'], 'd' => $day], $rows));
        } catch (Throwable $e) { /* 상태 없이도 목록은 뜬다 */ }
    }

    /* 단기급등 ⚠ — 신호일까지 20일 +80% 또는 40일 +100% 급등(검증 탭 ⑧: 엣지 없는 복권 자리) */
    $mom = [];
    if ($bq['mom']) {
        try {
            $mom = $krx->momMany(array_map(fn($r) => ['code' => $r['code'], 'd' => $day], $rows));
        } catch (Throwable $e) { /* 급등 표시는 없어도 목록은 뜬다 */ }
    }
    // 창별 칩 — 단일본은 pf_mom_chips. 이 표는 <b>신호일</b> 기준이라 그 사실을 칩에 싣는다
    $hotChip = static fn(?array $mm, string $d): string => pf_mom_chips($mm, $d, false);

    // 이름·관심종목·배지·정렬 — 배지 그룹(매집형 먼저) 안에서 거래대금 큰 순
    $nameCodes = array_unique(array_merge(array_column($rows, 'code'), array_column($bxTrack, 'code')));
    $names = $nameCodes ? $krx->names($nameCodes) : [];
    /* ★ 예전엔 array_flip 을 한 번 더 걸었는데 watchCodes() 가 <b>이미</b> [코드 => n] 이라
     *   그 결과는 [n => 코드]가 됐다 — isset($watch[$code]) 가 늘 false 라 ☆ 가 켜지지 않았다
     *   (2026-08-04 발견 · 스크리너·어닝은 처음부터 그대로 썼다). */
    $watch = [];
    try { $watch = $pf->watchCodes(); } catch (Throwable $e) { /* 없으면 별 없이 */ }
    // 이미 자리가 있는 종목 — 스크리너·어닝·관심종목과 같은 판정(pf_held_map)
    $qHeld = pf_held_map($pdo, array_column($rows, 'code'));

    $hasProv = false;
    foreach ($rows as &$r) {
        $r['badge'] = pf_surge_badge(
            $r['avg_mul'] !== null ? (float)$r['avg_mul'] : null,
            $r['chg']     !== null ? (float)$r['chg']     : null);
        if ($r['src'] === 'n') $hasProv = true;
    }
    unset($r);
    usort($rows, static fn($a, $b) => [$a['badge'][0], -(float)$a['amt']] <=> [$b['badge'][0], -(float)$b['amt']]);

    $cnt = [0 => 0, 1 => 0, 2 => 0];   // 매집형 / 중립 / 불꽃형 — pf_surge_badge 의 정렬순서와 같은 자리
    foreach ($rows as $r) $cnt[$r['badge'][0]]++;

    // 우측 패널의 초기 선택 — 주소에 실려 새로고침·북마크에서도 유지된다
    $selCode = preg_replace('/[^0-9]/', '', (string)($_GET['code'] ?? ''));
    if (!preg_match('/^\d{6}$/', $selCode)) $selCode = '';

    pf_head('퀀트 · 최고 거래대금', 'quant', 'wide');
    pf_subtabs('surge', 'quant');
    pf_flash();
    pf_quant_css();
    /* 📦 배지·압축 토글·추적 카드 — 이 화면 전용이라 공용 CSS 에 넣지 않는다 */
    echo '<style>.qb-bx{background:#e8f0fe;color:#1a56c4;border:1px solid #c6dafc}
.bx-tgl{display:inline-block;margin-left:8px;padding:2px 10px;border:1px solid #c6dafc;border-radius:12px;
  background:#f4f8ff;color:#1a56c4;font-size:12.5px;font-weight:700;text-decoration:none;vertical-align:1px;white-space:nowrap}
.bx-tgl.on{background:#12406b;border-color:#12406b;color:#fff}
.bxr{display:inline-block;padding:2px 9px;border-radius:10px;font-size:12px;font-weight:700;white-space:nowrap}
.bxr-tp{background:#e6f4ea;color:#1e7e34}.bxr-sl{background:#fdecea;color:#c62828}
.bxr-non{background:#eef2f6;color:#55636f}.bxr-amb{background:#fdf3e0;color:#b26a00}
.bxr-wait{background:#eaf3fb;color:#1565c0}
.bxr-pick{background:#12406b;color:#fff}
.bxr-g{background:#dce9f7;color:#12406b}
details.card>summary{cursor:pointer;font-size:14px;font-weight:700;color:#12406b;list-style:none}
details.card>summary::before{content:"▸ ";color:#9ab}
details.card[open]>summary::before{content:"▾ "}</style>';

    echo '<div class="pf-head"><div><h1>최고 거래대금</h1>';
    echo '<div class="sub">그 종목의 <b>최근 ' . KrxAmt::SURGE_WIN . '거래일 중 최고 거래대금</b>을 찍은 종목입니다'
       . ' (이력 ' . KrxAmt::SURGE_WIN . '일 미만 제외 · 코스피/코스닥 · 차트 지표 「최고_거래대금선」과 같은 창).'
       . ' 배지는 백테스트로 판정 근거를 검증했습니다 — <a href="/stock/index.php?mode=quantstat">검증 탭</a>.</div></div>';

    // 날짜 이동 + 최소 거래대금 — 조건이 주소에 실려 북마크로 재현된다
    if ($dates) {
        echo '<div class="act"><form method="get" action="/stock/index.php" class="fld-row">'
           . '<input type="hidden" name="mode" value="quant">'
           . ($selCode !== '' ? '<input type="hidden" name="code" value="' . pf_h($selCode) . '">' : '')
           . '<input type="hidden" name="bx" value="' . ($bxOn ? '1' : '0') . '">'   // 날짜를 옮겨도 보기 상태 유지
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

    /* ── 📦 박스 상향돌파 추적 — 이 화면의 «얼굴». 패턴이 나왔을 때만 줄이 생긴다 ── */
    echo '<div class="card"><h2>📦 박스 상향돌파 추적 <span class="q-note" style="display:inline">'
       . '(최근 ' . KrxAmt::TRACK_DAYS . '일 · 나왔을 때만 실립니다 · 결과 = +15%/−10% 5거래일 브래킷 실측)</span></h2>';
    if (!$bxTrack) {
        echo '<div class="q-note">최근 ' . KrxAmt::TRACK_DAYS . '일 안에 이 패턴이 <b>없었습니다</b>'
           . ($bxLastD !== ''
              ? ' — 마지막 후보 <a href="/stock/index.php?mode=boxbrk&d=' . pf_h($bxLastD) . '">'
                . pf_h($bxLastD) . '</a>'
              : '')
           . '. 새 후보가 뜨면 여기와 <a href="/stock/index.php?mode=boxbrk">패턴분석</a>에 실리고,'
           . ' 등급 B↑는 Pushover 알림이 옵니다.'
           . ' <span class="muted">0건인 날이 흔합니다 — 시장 거래대금이 식으면 「직전 119거래일 최고」를'
           . ' 넘길 수가 없습니다(버그가 아니라 시장 상태).</span></div>';
    } else {
        echo '<div class="tbl-scroll"><table class="pf pos"><thead><tr>';
        foreach ([['결과', 'center'], ['경로', 'center'], ['종목', ''], ['신호일', 'center'],
                  ['등급', 'center'], ['등락', 'num'], ['거래대금(억)', 'num'], ['카드', 'center']] as [$l, $cl]) {
            echo '<th class="' . $cl . '">' . pf_h($l) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($bxTrack as $t) {
            $k4 = $t['code'] . '|' . $t['d'];
            $b4 = $bxTrackBox[$k4] ?? null;
            /* 결과 = bx_cand 가 담아 둔 브래킷 판정 — 이것이 「피드백」이다 */
            if     ($t['brk_kind'] === 'tp')  $res = '<span class="bxr bxr-tp">익절 ' . sprintf('%+.1f%%', (float)$t['brk_ret']) . '</span>';
            elseif ($t['brk_kind'] === 'sl')  $res = '<span class="bxr bxr-sl">손절 ' . sprintf('%+.1f%%', (float)$t['brk_ret']) . '</span>';
            elseif ($t['brk_kind'] === 'non') $res = '<span class="bxr bxr-non" title="5거래일 안에 익절·손절선 둘 다 안 닿음 — D+5 종가 기준">D+5 ' . sprintf('%+.1f%%', (float)$t['brk_ret']) . '</span>';
            elseif ($t['brk_kind'] === 'amb') $res = '<span class="bxr bxr-amb" title="같은 날 둘 다 닿아 일봉으로는 순서를 모릅니다">모호</span>';
            elseif ((int)$t['q_ohlc'] === 1)  $res = '<span class="bxr bxr-non">판정불가</span>';
            else $res = '<span class="bxr bxr-wait" title="사후 5거래일이 아직 안 찼습니다">사후 ' . (int)$t['n_post'] . '/5</span>';
            echo '<tr class="q-row" data-code="' . pf_h($t['code']) . '">'
               . '<td class="center">' . $res . '</td>'
               . '<td class="center">' . ($b4
                   ? '<span class="bx ' . $b4['st'] . '" title="' . pf_h($b4['tip']) . '">' . pf_h($b4['txt']) . '</span>'
                   : '-') . '</td>'
               . '<td class="stk"><a class="q-name" href="/stock/index.php?mode=fund&code=' . pf_h($t['code']) . '">'
               . pf_h($t['name'] !== '' ? $t['name'] : $t['code']) . '</a><span class="code">' . pf_h($t['code']) . '</span>'
               . ((string)$t['src'] === 'pick' ? ' <span class="bxr bxr-pick" title="직접 담으셨던 신호(정의 원본)">★내가 고름</span>' : '')
               . '</td>'
               . '<td class="center">' . pf_h(substr((string)$t['d'], 5)) . '</td>'
               . '<td class="center">' . ($t['grade'] !== null
                   ? '<span class="bxr bxr-g" title="「담은 것과 닮음」의 백분위 — 오를 확률이 아닙니다">'
                     . pf_h((string)$t['grade']) . '</span>' : '-') . '</td>'
               . '<td class="num"><span class="' . pf_updown((float)$t['chg_pct']) . '">'
               . pf_pct((float)$t['chg_pct'], 1) . '</span></td>'
               . '<td class="num"><b>' . pf_eok($t['amt']) . '</b></td>'
               . '<td class="center"><a href="/stock/index.php?mode=boxbrk&d=' . pf_h($t['d'])
               . '" title="패턴분석의 그 날 카드(일봉+1분봉)로">카드</a></td>'
               . '</tr>';
        }
        echo '</tbody></table></div>'
           . '<div class="q-note">★<b>성과 우위 미검증</b> — 8년 재측정에서 기준선과 사실상 같았습니다'
           . '(<a href="/stock/index.php?mode=quantstat">검증 탭 ⑨</a>). 매수 신호가 아니라 판단 소집입니다.'
           . ' 행 클릭 = 재무분석 상세 · 「카드」 = 패턴분석의 그 날 일봉+1분봉.</div>';
    }
    echo '</div>';

    /* ⛔「매집형 박스 추적」 카드는 2026-08-10 삭제 — 같은 날의 «전면 재정의»(경로 판정을 박스
     *   상향돌파 박스에만)로 그 카드의 모집단(매집형 전체)이 기준 밖이 됐다. 트리거 실측(검증 ④~⑥)은
     *   기록으로 남고, 관측은 위 📦 카드가 잇는다. accBoxes()·surgeEventStates() 호출도 함께 걷었다. */

    /* 보기 토글 — 기본이 «압축»이고, 전체는 명시적으로 푼다. 주소에 실려 북마크에서 유지된다 */
    $bxN = 0;
    foreach ($rows as $r) if (isset($bxMap[$r['code']])) $bxN++;
    $bxUrl = '/stock/index.php?' . http_build_query(array_filter(
        ['mode' => 'quant', 'd' => $day, 'min' => (string)$minEok,
         'code' => $selCode, 'bx' => $bxOn ? '0' : '1'],
        fn($v) => $v !== '' && $v !== null));
    $bxTip = $bxOn
        ? '지금은 박스 상향돌파 5조건 통과분만 보입니다(기본) — 누르면 그 날 신호 전체를 봅니다'
        : '기본 보기(5조건 통과분만)로 돌아갑니다 — 판정은 bx_cand(매일 16:20 적재)를 읽습니다.'
          . ' ★오늘 날짜는 적재 전까지 비고(잠정치엔 시고저가 없어 장중 판정 불가),'
          . ' 성과 우위는 미검증입니다(검증 탭 ⑨)';

    echo '<div class="card"><h2>' . pf_h($day) . ' — ' . count($rows) . '종목 '
       . '<span class="q-note" style="display:inline">'
       . '(🟢매집형 ' . $cnt[0] . ' · 중립 ' . $cnt[1] . ' · 불꽃형 ' . $cnt[2]
       . ($hidden > 0 ? ' · 거래대금 ' . number_format($minEok) . '억 미만 <b>' . $hidden . '종목 숨김</b>' : '')
       . ($bxOn && $bxHidden > 0 ? ' · 5조건 밖 <b>' . $bxHidden . '종목 숨김</b>' : '')
       . ')</span>'
       . '<a class="bx-tgl' . ($bxOn ? ' on' : '') . '" href="' . pf_h($bxUrl) . '" title="' . pf_h($bxTip) . '">'
       . ($bxOn ? '전체 보기 +' . $bxHidden : '📦 박스 상향돌파만 ' . $bxN) . '</a></h2>';

    if (!$rows) {
        echo '<div class="warn">이 날짜에는 ' . ($bxOn
            ? '박스 상향돌파 5조건에 맞는 신호가 없습니다'
              . ($bxHidden > 0 ? ' (' . $bxHidden . '종목이 조건 밖)' : '')
              . ' — 0건인 날이 흔합니다(시장 상태). ★오늘 날짜라면 <b>16:20 크론 적재 뒤</b>에야 판정이 붙습니다.'
              . ' 그 날 신호 전체는 위 <b>「전체 보기」</b>로 봅니다.'
            : ($hidden > 0
               ? '거래대금 ' . number_format($minEok) . '억 이상인 신호가 없습니다 (' . $hidden . '종목이 하한에 걸림 — 하한을 낮춰 보세요).'
               : '신호가 없습니다.')) . '</div></div>';
        echo '</div><div class="q-right">' . $panel . '</div></div>';   // 좌측 닫고 우측 패널
        pf_foot(); return;
    }

    echo '<div class="tbl-scroll"><table class="pf pos">';
    /* ★ 열 이름은 사용자가 정한 용어다(2026-08-02) — 「배지」는 화면의 모든 칩을 뜻하는 총칭이라
     *   열 이름으로 쓰면 무엇을 담은 열인지 알 수 없다.
     * ★ 신호·경로는 <b>같은 신호일에서 나온 한 덩이</b>라 머리를 묶는다(pf_thead_grouped). */
    /* 배지 표시 설정 — 신호 칸의 배지(퀀트신호·📦·모멘텀)를 전부 끄면 그 열째로 사라진다.
     * 빈 열을 남기면 「고장」으로 읽힌다(빈 칸 규칙). 경로 열도 같다. */
    $qSigCol = $bq['qsig'] || $bq['bxgrade'] || $bq['mom'];
    $qCols = [];
    if ($qSigCol)      $qCols[] = ['신호', 'center'];
    if ($bq['qpath'])  $qCols[] = ['경로', 'center'];
    pf_thead_grouped(array_merge(
        $qCols ? [['group' => '퀀트 : 최고 거래대금', 'cols' => $qCols]] : [],
        [
            ['', 'center'], ['종목', ''], ['시장', 'center'], ['종가', 'num'],
            ['등락률', 'num'], ['거래대금(억)', 'num'], [KrxAmt::SURGE_WIN . '일최고 대비', 'num'],
            ['20일평균 대비', 'num'], ['시총(억)', 'num'],
        ]
    ));
    echo '<tbody>';

    foreach ($rows as $r) {
        [, $bc, $bl, $bt] = $r['badge'];
        $code = $r['code'];
        $on   = isset($watch[$code]);
        $name = $names[$code] ?? $code;
        $bx = $box[$code . '|' . $day] ?? null;
        echo '<tr class="q-row' . ($code === $selCode ? ' q-sel' : '') . '" data-code="' . pf_h($code) . '">';
        /* ★ 중립도 배지로 그린다(2026-08-02 사용자) — 빈 칸은 「판정했더니 중립」과
         *   「판정할 재료가 없음」을 구별하지 못한다. 중립이 45.5%로 가장 흔한 판정이라 더 그렇다. */
        /* 📦 = 박스 상향돌파 5조건 통과 — bx_cand 를 «읽기만» 한 표시(등급 = 닮음, 오를 확률 아님) */
        if ($qSigCol) {
            $bx3 = $bxMap[$code] ?? null;
            $chips = ($bq['qsig']
                      ? '<span class="qb ' . $bc . '" title="' . pf_h($bt) . '">' . pf_h($bl) . '</span>'
                      : '')
                   . ($bq['bxgrade'] && $bx3 !== null
                      ? '<span class="qb qb-bx" title="' . pf_h('박스 상향돌파 5조건 통과 · 등급 '
                          . (string)($bx3['grade'] ?? '-') . ' = 「담은 것과 닮음」(오를 확률이 아닙니다)'
                          . ' · 성과 우위 미검증(검증 탭 ⑨) · 카드는 패턴분석 화면에'
                          . (($bx3['src'] ?? '') === 'pick' ? ' · ★직접 담으셨던 신호' : '')) . '">📦'
                        . pf_h((string)($bx3['grade'] ?? '')) . '</span>'
                      : '')
                   . $hotChip($mom[$code . '|' . $day] ?? null, (string)$day);
            echo '<td class="center">' . ($chips !== '' ? $chips : '<span class="muted">-</span>') . '</td>';
        }
        if ($bq['qpath']) {
            echo '<td class="center">' . ($bx
                ? '<span class="bx ' . $bx['st'] . '" title="' . pf_h($bx['tip']) . '">' . pf_h($bx['txt']) . '</span>'
                : '-') . '</td>';
        }
        /* ☆ + 상태. ★열 순서는 이 화면만 그대로 둔다 — 신호·경로가 맨 앞에 오는 다른 골격이고
         *   여기서는 「어떤 신호가 떴나」가 종목명보다 먼저 읽혀야 한다. 바뀐 것은 <b>편입 자리의 판정</b>뿐이다.
         * M2 — 이 목록은 「그 날($day) 신호」 한 벌이라 기준 신호일이 곧 $day 다.
         *      트리거는 이 화면이 판정하지 않으므로(관심종목 관제탑 소관) 싣지 않는다. */
        echo '<td class="center" style="white-space:nowrap"><button type="button" class="wl-star' . ($on ? ' on' : '') . '" data-code="'
           . pf_h($code) . '" data-name="' . pf_h($name) . '" title="관심종목 담기/빼기">' . ($on ? '★' : '☆') . '</button>'
           . pf_adopt_cell($code, (string)$name, $qHeld[$code] ?? null, ['sed' => (string)$day]) . '</td>';
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
       . '<b>편입</b> = 종목 추가 폼(포트폴리오 미지정 저장 = 편입 관심종목) — '
       . '이미 자리가 있으면 그 자리에 <span class="badge st-held">보유</span>·'
       . '<span class="badge st-slot">편입됨</span> 이 대신 서고 눌러서 그 종목 상세로 갑니다'
       . '(재무분석·어닝·관심종목과 같은 판정) · '
       . '<b>📦</b> = 박스 상향돌파 5조건 통과(글자는 등급 — 「담은 것과 닮음」이지 오를 확률이 아닙니다 · '
       . '판정은 <code>bx_cand</code> 16:20 적재라 <b>오늘 것은 마감 뒤에 붙습니다</b> · 성과 우위 미검증 — '
       . '<a href="/stock/index.php?mode=quantstat">검증 탭 ⑨</a>) · '
       . '이 목록은 <b>기본이 5조건 압축</b>이고 그 날 신호 전체는 「전체 보기」로 폅니다 · '
       . '정렬 = 퀀트신호(매집형 → 중립 → 불꽃형) → 거래대금 큰 순 · 거래대금 0(거래정지)인 날은 판정에서 제외 · '
       . '「20일 +N%」·「40일 +N%」 = 신호일까지의 모멘텀(시장 계열) — 20일 +80%·40일 +100% 이상이면 주황: 이 무리의 돌파 매수는 실측 엣지 없음(검증 탭 ⑧). 회색은 같은 창의 급락(20일 −30%·40일 −40% — 지정값·근거 없음)</div>';
    echo '</div>';

    // 배지 근거 요약 — 전문은 검증 탭
    echo '<details class="card" style="padding:12px 16px"><summary style="cursor:pointer;font-weight:700">'
       . '퀀트신호는 어떻게 정했나 (백테스트 요약 · 창 120일 · 하한 100억)</summary>'
       . '<div style="font-size:13px;line-height:1.7;margin-top:8px">'
       . '신호 6,942건(2025-02~2026-07 · 하한 100억)의 <b>다음날 매수 → +20거래일 수익률에서 같은 날 전종목 중앙값을 뺀 초과수익</b>으로 판정했습니다.<br>'
       . '· 최고 거래대금 전체는 중앙 <b>−3.36%</b> · 승률 41.7% — "터진 종목 추격"은 절반 이상이 시장보다 못 갑니다.<br>'
       . '· 🟢 <b>매집형</b>(20일 평균의 5배 이하 <b>+ 등락 0~10%</b>): 중앙 <b>+1.74%</b> · 승률 55.2% — 유일하게 견고히 이기는 무리.<br>'
       . '· <b>불꽃형</b> — 20배 이상: 중앙 −4.24% · 승률 36.7% / 등락 +20%↑: 중앙 <b>−7.23%</b> · 승률 34.6%'
       . ' <span class="muted">(둘은 같은 뜻이라 배지를 하나로 합쳤고, 수치는 조건별로 잰 것이라 따로 적습니다)</span>.<br>'
       . '· 두 기간으로 갈라도 부호가 유지됐습니다(매집형 단독은 후반기 반전이라 등락 조건을 붙였습니다).'
       . ' 자세한 표·방법·한계는 <a href="/stock/index.php?mode=quantstat">검증 탭</a>.'
       . '</div></details>';

    // 좌측 닫고 우측 패널(재무분석 embed)
    echo '</div><div class="q-right">' . $panel . '</div></div>';

    // ☆ 토글(재무분석과 같은 API) + 행/종목명 클릭 → 우측 패널. 캡처 단계 — 행 클릭보다 별이 먼저 먹어야 한다.
    echo <<<'JS'
<script>
document.addEventListener('click', function(e){
  var a = e.target.closest ? e.target.closest('.pf-adopt') : null;
  if (a) {                                        // 편입 — 행 클릭보다 먼저 먹어야 한다(캡처)
    e.stopPropagation(); e.preventDefault();
    var u = '/stock/index.php?mode=position&id=new&src=quant&bm=box'
      + '&code=' + encodeURIComponent(a.getAttribute('data-code'))
      + '&name=' + encodeURIComponent(a.getAttribute('data-name'));
    var sd = a.getAttribute('data-sed');          // M2 — 이 행이 선 그 신호일
    if (sd) u += '&sed=' + encodeURIComponent(sd);
    location.href = u;
    return;
  }
  var b = e.target.closest ? e.target.closest('.wl-star') : null;
  if (!b) return;
  e.stopPropagation();
  e.preventDefault();
  var body = new URLSearchParams({json:'1', src:'quant', code:b.getAttribute('data-code'), name:b.getAttribute('data-name')});
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

    /* ★이 화면의 숫자는 두 종류다 — 섞어 쓰면 안 된다(M5 3차).
     *   ① <b>측정 조건</b>(창·하한 등) = 지금 코드가 쓰는 임계 ⇒ Thr 에서 읽는다.
     *      화면 기본값과 다르면 「같은 조건으로 쟀다」는 말이 거짓이 되므로 손으로 적지 않는다.
     *   ② <b>측정 결과</b>(표본 수·중앙 −3.36% 등) = 2026-07-31 에 <b>실제로 잰 기록</b> ⇒ 리터럴로 둔다.
     *      임계를 바꾸면 이 수치는 「그때 그 조건의 결과」로 남아야지 따라 변하면 안 된다.
     *      ⇒ 임계를 고쳤는데 결과가 그대로면 <b>재측정이 필요하다는 신호</b>다. */
    $winTxt = KrxAmt::SURGE_WIN . '거래일';
    $minTxt = number_format(Thr::SURGE_MIN_AMT / 100000000) . '억';
    echo '<div class="pf-head"><div><h1>검증 — 최고 거래대금이 수익으로 이어졌나</h1>';
    echo '<div class="sub"><b>측정 2026-07-31</b> · 창 <b>' . $winTxt . '</b> · 신호 하한 <b>' . $minTxt . '</b> (화면 기본값과 동일 조건) · '
       . '신호 6,942건 · 2025-02-04 ~ 2026-07-31 · krx_amt 전종목 원장(KRX 공식값)<br>'
       . '<span class="muted">아래 <b>측정 조건</b>은 지금 코드가 쓰는 임계(<a href="/stock/index.php?mode=signal">임계 레지스트리</a>)를 '
       . '읽어 씁니다. <b>측정 결과</b>는 그때 실제로 잰 값이라 고정입니다 — 조건이 바뀌었는데 결과가 그대로면 '
       . '다시 재야 한다는 뜻입니다.</span></div></div></div>';

    echo '<div class="card"><h2>방법</h2><div style="font-size:13px;line-height:1.8">'
       . '· 신호 = 그 날 거래대금 ≥ ' . $minTxt . ' 이고, 그 종목 직전 ' . (KrxAmt::SURGE_WIN - 1)
       . '거래일 최고를 넘음 (이력 ' . KrxAmt::SURGE_WIN . '행 미만·거래정지일 제외)<br>'
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
       . '<tr><td><b>20배↑ (불꽃형)</b></td><td>2,236</td><td class="down"><b>−4.24%</b></td><td>−0.29%</td><td><b>36.7%</b></td></tr></table></div>';

    echo '<div class="card"><h2>③ 배지 조합 — 기간을 갈라도 부호가 유지되나</h2>';
    echo '<table class="qstat"><tr><th>조합 (+20일 · 다음날 매수)</th><th>표본</th><th>초과 중앙</th><th>승률</th>'
       . '<th>~2025-10</th><th>2025-11~</th></tr>'
       . '<tr><td>매집형 단독 (20평비 ≤5)</td><td>1,224</td><td>+0.50%</td><td>51.1%</td><td>+1.15%</td>'
       . '<td class="down"><b>−0.13%</b> ⚠</td></tr>'
       . '<tr><td>🟢 <b>매집형 + 등락 0~10%</b> (배지 기준)</td><td>569</td><td class="up"><b>+1.74%</b></td>'
       . '<td><b>55.2%</b></td><td>+2.26%</td><td>+1.46%</td></tr>'
       . '<tr><td><b>불꽃형</b> — 20평비 <b>20배↑</b></td><td>2,236</td><td class="down">−4.24%</td><td>36.7%</td><td>−4.36%</td><td>−4.08%</td></tr>'
       . '<tr><td><b>불꽃형</b> — 등락 <b>+20%↑</b></td><td>1,345</td><td class="down">−7.23%</td><td>34.6%</td><td>−9.47%</td><td>−5.85%</td></tr></table>'
       /* ★ 두 줄을 합치지 않는 이유 — 배지는 하나(불꽃형)로 합쳤지만 이 표는 <b>측정 기록</b>이고
        * 두 조건의 모집단은 서로 겹친다(같은 신호가 양쪽에 들어간다). 합친 수치는 잰 적이 없다. */
       . '<div class="q-note">★<b>불꽃형은 두 조건이 한 배지</b>(2026-08-02 통합) — 거래대금 20배 폭발이든 +20% 폭등이든'
       . ' 「과열되어 터진 하루」로 성격이 같고 처방도 하나(돌파해도 매수 금지)입니다. 위 표를 두 줄로 남긴 것은'
       . ' <b>측정이 조건별로 따로 이뤄졌고 두 모집단이 겹치기 때문</b>입니다 — 합친 수치는 잰 적이 없습니다.</div>'
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
       . '<tr><td>🚫 <b>불꽃형</b>(등락 +20%↑) × 돌파 (돌파율은 67.7%로 최고인데 사면 진다)</td><td>821</td>'
       . '<td class="down">−7.60%</td><td>35.7%</td><td>−10.68%</td><td>−5.44%</td></tr>'
       . '<tr><td>🚫 <b>불꽃형</b>(20평비 20배↑) × 돌파</td><td>431</td><td class="down">−5.72%</td><td>37.4%</td><td>−4.88%</td><td>−6.78%</td></tr></table>'
       . '<div class="q-note">네 규칙 모두 기간 2분할에서 부호 유지 · 매집형 돌파는 178개 종목에 분산.'
       . ' 「계단 번호·대금 가속으로 막차를 미리 가려내기」는 판별력이 약해 채택하지 않았다(정직하게 기각).'
       . ' 계단 정의는 삼성물산 사례(직전 박스가 위에 겹쳐 진짜 아래층을 잃음)로 한 번 고쳐 재측정했다.<br>'
       . ' ★<b>2026-08-10 전면 재정의(사용자 지시)</b>: 경로 배지·트리거는 이제 <b>박스 상향돌파(⑨) 박스에만</b>'
       . ' 붙는다(단일본 boxStatusMany 의 게이트). 이 절의 실측은 <b>전체 모집단 기준의 기록</b>이라 새 기준에서는'
       . ' 참고치다 — 5조건 부분집합에서의 트리거 성적은 따로 잰 적이 없다. 「매집형 박스 추적」 카드도 같은 날 내렸다.'
       . ' 예외는 포트폴리오 <b>계단관통↓ 경보</b> 하나(편입 시 기준 박스 = 기록 · 안전장치).</div></div>';

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
       . ' +7.1%로 양수</b>(복권 꼬리)라 자동 차단이 아니라 <b>목록의 「20일 +N%」·「40일 +N%」 표시만 채택</b>했다 — 사는 건'
       . ' 복권임을 알고 사는 것.</div>'
       . '<div class="q-note" style="margin-bottom:10px">처방 기각 2건(정직 기록): ①<b>급등 진입만 유예 제거(타이트 손절)</b>'
       . ' — 해당 부분집합 승률 56.8→45.1%로 명확 악화. 급등주일수록 흔들림(셰이크아웃)도 커서 한 계단 유예가'
       . ' 제 몫을 한다. 손절 규칙(⑤)은 급등주에도 그대로. ②<b>장대음봉 즉시손절</b>(고가대비 저가 −12%↓ ∧'
       . ' 평균종가대비 −8%↓ ∧ 종가가 봉 하단 — 6개 변형 전부) — 승자에게도 장대음봉이 흔해서 실패'
       . ' (SK하이닉스 2026-03-04 고가대비 −23% 투매 캔들 후 +144% 사례가 반례). 패자 개선 +4~7%p ≪ 승자 훼손'
       . ' −15~34%p(181건)·승률 59→54.5%.</div>'
       . '<div class="q-note">급등 임계(20일 +80%·40일 +100%)는 이 측정에서 나온 값이다(KrxAmt::MOM_HOT20/40). 화면에는 창별로 「20일 +N%」·「40일 +N%」 칩이 뜬다(시장 계열).'
       . ' 신호일 종가 기준으로 계산하며(백테스트는 돌파일 기준 — 통상 신호 후 며칠 내라 근사 수용),'
       . ' 실패 3사례 중 2건(SK하이닉스 6월·툴젠)이 이 기준에 걸린다. 단 <b>패턴1 최고 성공사례인 삼성전기(+66.6%)도'
       . ' 같은 무리다</b>(신호 전 40일 +139% 실측) — ⚠가 붙어도 큰 승자가 나온다는 것이 정확히 「중앙 0·평균 +7%'
       . ' 복권 분포」의 뜻이다. ⚠는 금지가 아니라 <b>기대값 없이 복권을 사는 자리라는 고지</b>다.</div></div>';

    /* ── ⑨ 박스 상향돌파 (2026-08-10 신설) — 수치는 그날 실측한 «측정 기록»이라 리터럴로 둔다.
     *   재측정은 SSH `php cron/bx_scan.php job=bt8y` (30초) — 정의(BoxBrk 상수)를 바꾸면 반드시 다시 잰다. */
    echo '<div class="card"><h2>⑨ 박스 상향돌파 — 「내가 고른 패턴」은 이기는 패턴인가 (2026-08-10)</h2>'
       . '<div style="font-size:13px;line-height:1.8">'
       . '관심차트에 담아 두신 「박스_상향돌파」 그룹을 되맞춰 정의(5조건 · 판정 단일본'
       . ' <code>stock/lib/boxbrk.php</code>)를 만들고, <a href="/stock/index.php?mode=boxbrk">패턴분석</a>이'
       . ' 매일 쌓습니다. 여기는 그 패턴의 <b>성과 검정 기록</b>입니다.</div>'

       . '<div class="q-note" style="margin:8px 0 10px">★★<b>먼저 알아야 할 발견 — 「담기」는 룩어헤드였습니다</b>'
       . ' (2026-08-09 · 세 갈래 검정). 옛 불꽃형 갤러리 카드는 신호일 <b>이후</b> 55거래일 차트와 결과'
       . ' 배지를 함께 띄웠고, 거기서 담긴 것의 승률 우위(55.9% vs 46.9%)는 «모양»이 아니라 <b>«결과»를 본'
       . ' 데서 왔습니다</b>: ①사전 모양을 되맞춘 모델의 상위 24%는 승률 43.6%로 전체(49.1%)보다 나쁘고'
       . ' ②8년 기간 밖에서 상위 10% +0.40% vs 기준선 −0.25%로 사실상 같으며 ③결정적으로, <b>모양이 가장'
       . ' «안» 닮은 4분위에서 담은 것이 가장 좋았습니다</b>(68.4% — 모양과 직교).'
       . ' ⇒ 일반화: <b>판단 표본을 만드는 화면이 결과를 함께 보여 주면, 그 표본은 판단의 기록이 아니라'
       . ' 결과의 사본입니다.</b> 그래서 지금 화면은 오늘 뜬 것(사후 미완)을 보고 담게 되어 있고,'
       . ' 나중에 깨끗한 표본만 가를 자(담은 시각과 신호일의 간격)가 남습니다.</div>'

       . '<div style="font-size:13px;line-height:1.8;margin-bottom:4px"><b>8년 재측정 (2026-08-10)</b> —'
       . ' 조건 ③④⑤를 조인 <b>최종 5조건 정의</b>로 다시 쟀습니다(옛 검정은 조이기 전 정의).'
       . ' 잣대는 불꽃형 8년 표와 같은 자: 신호일 <b>종가</b> 매수 → <b>5거래일 +15%/−10%</b> 브래킷 ·'
       . ' 모호는 낙관/비관 두 경계 · 거래정지 건너뜀 · 상장주식수 5% 변동 제외 · 왕복 비용 0.20% 가정.'
       . ' 재측정: <code>job=bt8y</code>(30초).</div>'
       . '<table class="qstat"><tr><th>모집단 (2019-07 ~ 2026-07 · 상한가 제외)</th><th>표본</th>'
       . '<th>익절</th><th>손절</th><th>미도달</th><th>비관 평균</th><th>중앙</th><th>승률</th><th>비용 뒤</th></tr>'
       . '<tr><td>기준선 — 최고 거래대금 신호 전체 (100억↑)</td><td>27,707</td>'
       . '<td>23.6%</td><td>31.3%</td><td>44.5%</td><td>−0.00%</td><td class="down">−2.28%</td>'
       . '<td>41.4~42.0%</td><td class="down">−0.20%</td></tr>'
       . '<tr><td><b>박스 상향돌파 (5조건)</b></td><td>4,426</td>'
       . '<td>29.0%</td><td>38.6%</td><td>31.8%</td><td>+0.21%</td><td class="down"><b>−3.30%</b></td>'
       . '<td><b>41.7~42.4%</b></td><td>+0.01%</td></tr>'
       . '<tr><td>└ 등급 A (닮음 상위 5%)</td><td>724</td><td colspan="3" style="color:#9ab">—</td>'
       . '<td>+0.94%</td><td class="down">−2.63%</td><td>45.2%</td><td>+0.74%</td></tr>'
       . '<tr><td>└ 등급 D (그 밖)</td><td>1,651</td><td colspan="3" style="color:#9ab">—</td>'
       . '<td class="down">−0.15%</td><td class="down">−4.12%</td><td>39.6%</td><td class="down">−0.35%</td></tr></table>'
       . '<div class="q-note">★<b>결론: 측정된 성과 우위가 없습니다.</b> 평균은 기준선보다 0.2%p쯤 위지만'
       . ' (비용 뒤 +0.01% vs −0.20%) 승률은 사실상 같고(41.7 vs 41.4%) <b>중앙은 오히려 더 나쁩니다</b>'
       . '(−3.30 vs −2.28% — 익절·손절 양쪽이 늘어난 «더 크게 움직이는» 무리라 평균만 끌립니다).'
       . ' 비용 뒤 평균이 양(+)인 해는 <b>2/8</b> · 기간 2분할은 +0.34/+0.09%로 부호는 유지되나 미미합니다.'
       . ' 등급 A(승률 45.2%·중앙 −2.63%)도 판을 뒤집지 못합니다 — 등급은 「담은 것과 닮았나」이지'
       . ' 「오를까」가 아닙니다.</div>'
       . '<div class="q-note">⇒ 그래서 이 패턴의 화면·알림은 <b>매수 신호가 아니라 판단 소집</b>입니다'
       . ' — 알림 본문 끝의 「※성과 우위 미검증」 한 줄이 그 기록이고, 지우지 않습니다.'
       . ' 몇 달 뒤 «결과를 못 본 채» 담은 표본이 쌓이면 「내 눈이 진짜인가」를 처음으로 정직하게'
       . ' 잴 수 있습니다.</div></div>';

    echo '<div class="card"><h2>한계 (읽고 쓸 것)</h2><div style="font-size:13px;line-height:1.8">'
       . '· 120일 창은 이력 요건 탓에 <b>표본이 18개월</b>이다(2025-02~). 깊은 하락장은 여전히 없다.<br>'
       . '· 슬리피지·수수료 미반영. 초과수익 중앙 +1.7%는 <b>엣지이지 보증이 아니다</b> — 45%는 여전히 시장보다 못 간다.<br>'
       . '· 🟢 표본 569건은 앞선 표들보다 작다 — 원장이 쌓이면 재측정해 임계를 다시 확인한다.<br>'
       . '· 원장이 쌓이면 이 표는 낡는다. 재측정은 SSH 검증 스크립트로(측정일 갱신).<br>'
       . '· 배지 임계(≤5배·≥20배·0~10%·≥20%)는 이 측정에서 나온 값이다 — 임계를 바꾸려면 먼저 재측정한다.</div></div>';

    pf_foot();
}

/* ⛔옛 「패턴분석」(mode=pattern · 5개 패턴 수기 사례 31건 · pf_page_pattern)은 2026-08-10 에
 *   삭제됐다(사용자 지시) — 「패턴분석 (박스 상향돌파)」(pf_page_boxbrk)가 패턴지도·사례를 잇고
 *   옛 주소는 301 로 그리 보낸다. 5개 패턴(매집형 돌파·계단지지·첫 폭발·불꽃형 둘)의 근거 표는
 *   검증 탭 ④~⑥(pf_page_quantstat)에 그대로 있다 — 트리거 규칙 자체는 살아 있다. */

/**
 * 관심차트 바 — 「그 그룹만 보기」 + 「선택을 그룹에 담기」 + 그룹 관리 (2026-08-07)
 *
 * ★그룹 목록을 화면에 다시 적지 않는다 — 서버가 준 $groups 한 벌을 셀렉트 셋(필터·담기·관리)이
 *   나눠 쓰고, 담기·삭제 응답이 그 한 벌을 통째로 갈아 준다(JS 의 renderGroups).
 *   세 곳이 각자 목록을 들면 새 그룹을 만든 뒤 한 곳만 옛 목록을 말한다.
 * ★「따로 불러서 보기」는 <b>주소</b>(`&g=`)다 — JS 로 숨기지 않는다.
 *   그래야 그 화면을 그대로 북마크·공유할 수 있고, 결과 필터와 겹쳐 쓸 수 있다.
 */
function pf_flame_fav_bar(callable $url, array $groups, int $gid): string
{
    $sel = '<select id="flGf" onchange="flGo(this.value)">'
         . '<option value="0"' . ($gid === 0 ? ' selected' : '') . '>전체 (관심차트 아님)</option>';
    foreach ($groups as $g) {
        $sel .= '<option value="' . (int)$g['id'] . '"' . ($gid === (int)$g['id'] ? ' selected' : '') . '>'
              . pf_h($g['name']) . ' (' . (int)$g['n'] . ')</option>';
    }
    $sel .= '</select>';

    /* ★체크칸이 곧 «담김»이다 (2026-08-07 사용자 지시) — 「담기」 버튼을 없앴다.
     *   버튼이 있으면 카드를 고르고 → 맨 위로 올라가 누르고 → 다시 내려와 다음 페이지를 눌러야 했다.
     *   그래서 체크칸은 「선택」이 아니라 «지금 담을 그룹에 들어 있나»를 뜻한다
     *   (체크 = 담기 · 해제 = 빼기 · 둘 다 즉시 저장). 그러면 아래에 버튼을 또 둘 이유가 없다. */
    $h = '<div class="fl-sel">'
       . '<b style="font-size:12.5px;color:#556">보기</b>' . $sel
       . '<button type="button" class="gh" onclick="flGmToggle()">⚙ 그룹 관리</button>'
       . '<span style="flex:1"></span>'
       . '<b style="font-size:12.5px;color:#556">담을 그룹</b>'
       . '<select id="flGadd" onchange="flTargetChange()"></select>'
       . '<label style="display:flex;align-items:center;gap:5px;cursor:pointer">'
       . '<input type="checkbox" id="flAll" onchange="flCheckAll(this.checked)"> 이 페이지 전체</label>'
       . '<span id="flSelN" class="muted"></span>'
       . '</div>';

    /* 그룹 관리 — 쓰는 일이 드물어 탭·모달을 차지할 값어치가 없다(gift 의 그룹 모달과 같은 판단) */
    $h .= '<div class="fl-gm" id="flGm" style="display:none">'
        . '<table id="flGmT"><tbody></tbody></table>'
        . '<div style="display:flex;gap:6px;margin-top:8px">'
        . '<input type="text" id="flGnew" placeholder="새 그룹 이름" maxlength="60" style="flex:1">'
        . '<button type="button" class="pri" onclick="flGroupSave(0)">＋ 새 그룹</button></div>'
        . '<div class="muted" style="margin-top:6px;font-size:12px">'
        . '카드의 체크칸은 위에서 고른 <b>「담을 그룹」</b>에 들어 있는지를 뜻합니다 —'
        . ' 체크하면 담기고 풀면 빠지며, <b>버튼 없이 바로 저장</b>됩니다.<br>'
        . '그룹을 지워도 <b>신호 자체는 그대로</b>입니다 — 담아 둔 목록만 사라집니다.</div>'
        . '</div>';
    return $h;
}

/**
 * 카드 한 장 — 「목록」과 「실제 사례」 절이 <b>같은 것</b>을 그린다 (2026-08-10 사례 절을 내며 분리).
 *
 * ★같은 신호가 목록과 사례에 동시에 실릴 수 있어 $idPfx 로 DOM id 를 가른다 —
 *   차트 컨테이너(flc_/flm_)가 겹치면 한쪽 차트가 조용히 안 그려진다.
 *   체크칸의 data-key 는 일부러 안 가른다 — 같은 신호면 두 체크칸이 함께 움직여야 맞다.
 * @param ?array $tag 사례 절이 다는 배지 [라벨, css클래스] — 목록에서는 null
 * @return array ['html' => 카드 마크업, 'card' => FL_CARDS 한 줄]
 */
function pf_bx_card(array $r, array $axes, array $favMember, int $mspan, string $idPfx = '', ?array $tag = null): array
{
    $KB = ['tp' => ['익절', 'fl-tp'], 'sl' => ['손절', 'fl-sl'], 'non' => ['미도달', 'fl-non'],
           'amb' => ['모호', 'fl-amb']];
    $id  = pf_h($idPfx . $r['code'] . '_' . str_replace('-', '', $r['d']));
    $key = $r['code'] . '|' . $r['d'];
    [$kl, $kc] = $KB[$r['brk_kind']]
        ?? ((int)$r['q_ohlc'] === 1 ? ['판정불가', 'fl-amb'] : ['사후 미완', 'fl-wait']);
    $ret = $r['brk_ret'] === null ? '' :
        ' <b style="color:' . ((float)$r['brk_ret'] >= 0 ? '#1e7e34' : '#c62828') . '">'
        . sprintf('%+.2f%%', (float)$r['brk_ret']) . '</b>';
    $isPick = ((string)$r['src'] === 'pick');
    $h = '<div class="fl-card' . ($isPick ? ' pick' : '') . '" id="flcard_' . $id . '"><div class="fl-hd">'
       . '<input type="checkbox" class="fl-ck" data-key="' . pf_h($key) . '"'
       . ' onchange="flCheck(this)"'
       . ' title="체크하면 위의 «담을 그룹»에 바로 담깁니다 — 체크를 풀면 그 그룹에서 뺍니다">'
       . ($tag ? '<span class="fl-b ' . pf_h($tag[1]) . '">' . pf_h($tag[0]) . '</span>' : '')
       /* ★출처를 카드에서도 밝힌다 — 목록을 섞어 볼 때 「이건 내가 고른 것」이 한눈에 보여야 한다 */
       . '<span class="fl-b ' . ($isPick ? 'fl-pick">★내가 고름' : 'fl-auto">자동') . '</span>'
       . ($r['grade'] !== null
          ? '<span class="fl-b fl-g' . pf_h((string)$r['grade']) . '" title="점수 '
            . number_format((float)$r['z'], 2) . ' — 「그 날이었다면 담으셨을 확률」 '
            . round((float)$r['prob'] * 100) . '%">' . pf_h((string)$r['grade']) . ' '
            . round((float)$r['prob'] * 100) . '%</span>' : '')
       . '<span class="fl-b ' . $kc . '">' . $kl . '</span>' . $ret
       . ' <b>' . pf_h($r['name'] !== '' ? $r['name'] : $r['code']) . '</b>'
       . '<span class="code">' . pf_h($r['code']) . '</span>'
       . ((int)$r['is_flame'] === 1
          ? '<span class="fl-b fl-fm" title="거래대금이 20일 평균의 20배 이상 — 불꽃형 배지가 붙는 조건입니다(점수에는 안 씁니다).">불꽃형</span>' : '')
       . ((float)$r['chg_pct'] >= 29 ? '<span class="fl-b fl-lim">상한가</span>' : '')
       /* ★차트에는 정지일이 «평평한 봉»으로 보인다 — 판정이 그 날을 건너뛴 것을 알려야 헤매지 않는다 */
       . ((int)($r['q_halt'] ?? 0) === 1
          ? '<span class="fl-b fl-halt" title="사후 5일 안에 거래가 멈춘 날이 있어 그 날을 빼고 판정했습니다">'
            . '거래정지 건너뜀</span>' : '')
       . (($r['brk_src'] ?? 'd') === 'm'
          ? '<span class="fl-b fl-msrc" title="일봉으로는 같은 날 익절선·손절선을 둘 다 닿아 순서를 몰랐습니다.'
            . ' 1분봉 순서로 갈랐습니다 — 수익률도 분봉 기준입니다.">분봉이 가름</span>' : '')
       . '<span class="fl-warn" id="fw_' . $id . '">가격기준 어긋남</span>'
       . '<span class="fl-gs" id="fg_' . $id . '">'
       . implode('', array_map(fn($g) => '<span class="fl-g">' . pf_h($g['name']) . '</span> ',
                               $favMember[$key] ?? []))
       . '</span>'
       . '<span class="dt">' . pf_h($r['d']) . '</span></div>'
       . '<div class="fl-meta">'
       . '<span>등락 <b>' . sprintf('%+.2f%%', (float)$r['chg_pct']) . '</b></span>'
       . '<span>거래대금 <b>' . number_format((int)$r['amt'] / 1e8) . '억</b></span>'
       . '<span>20평비 <b>' . number_format((float)$r['amt_mult'], 1) . '배</b></span>'
       . '<span>종가 <b>' . number_format((int)$r['close_prc']) . '</b></span>'
       . ($r['mktcap'] ? '<span>시총 <b>' . number_format((int)$r['mktcap'] / 1e8) . '억</b></span>' : '')
       /* ★기존 박스와 얼마나 붙어 있나 — 음수면 기존 박스 «안»에서 뚫은 것이다 */
       . ($r['m_gap_pct'] !== null
          ? '<span title="현재 박스 저가와 기존 박스 고가('
            . number_format((int)$r['m_prev_h']) . ')의 차이'
            . ' — 음수면 기존 박스 «안»에서 뚫은 것입니다">기존박스 대비 <b>'
            . sprintf('%+.1f%%', (float)$r['m_gap_pct']) . '</b></span>' : '')
       /* ★시가→종가 — 「등락(전일 종가 대비)」과 다른 자다. 갭으로 뜬 뒤 흘러내린 봉을 가른다. */
       . ($r['m_oc_pct'] !== null
          ? '<span title="그 날 시가 대비 종가 — 등락(전일 종가 대비)과 다릅니다.'
            . ' 갭으로 뜬 뒤 하루 종일 흘러내린 봉은 등락이 플러스여도 여기가 음수입니다">'
            . '시가→종가 <b>' . sprintf('%+.1f%%', (float)$r['m_oc_pct']) . '</b></span>' : '')
       . ($r['f_max5'] !== null
          ? '<span>5일 최고 <b style="color:#1e7e34">' . sprintf('%+.2f%%', (float)$r['f_max5'])
            . '</b>(D+' . (int)$r['f_max5_day'] . ')</span>'
            . '<span>최저 <b style="color:#c62828">' . sprintf('%+.2f%%', (float)$r['f_min5'])
            . '</b>(D+' . (int)$r['f_min5_day'] . ')</span>'
            . ($r['f_d5'] !== null ? '<span>D+5 <b>' . sprintf('%+.2f%%', (float)$r['f_d5']) . '</b></span>' : '')
          : ((int)$r['q_ohlc'] === 1
             ? '<span class="muted">사후 창에 <b>거래는 있었는데 고가·저가가 원장에 없는 날</b>이 있어'
               . ' 판정하지 않았습니다</span>'
             : '<span class="muted">사후 5거래일이 아직 안 찼습니다 ('
               . (int)$r['n_post'] . '/' . 5 . '일)</span>'))
       . '</div>'
       /* 점수 축 다섯 — 「왜 이것이 후보인가」를 카드에서 바로 읽는다 */
       . '<div class="fl-ax">'
       . '<div title="' . pf_h(strip_tags($axes['vola20']['w'])) . '"><i>직전 변동성</i><b>'
       . ($r['f_vola20'] === null ? '—' : number_format((float)$r['f_vola20'], 1) . '%') . '</b></div>'
       . '<div title="' . pf_h(strip_tags($axes['brkHi120']['w'])) . '"><i>120일 고가</i><b>'
       . ($r['f_brk_hi120'] === null ? '—' : sprintf('%+.1f%%', (float)$r['f_brk_hi120'])) . '</b></div>'
       . '<div title="' . pf_h(strip_tags($axes['dYHigh']['w'])) . '"><i>52주 고가</i><b>'
       . ($r['f_dyhigh'] === null ? '—' : sprintf('%+.1f%%', (float)$r['f_dyhigh'])) . '</b></div>'
       . '<div title="' . pf_h(strip_tags($axes['posPrev60']['w'])) . '"><i>60일 위치</i><b>'
       . ($r['f_pos60'] === null ? '—' : number_format((float)$r['f_pos60'], 0) . '%') . '</b></div>'
       . '<div title="' . pf_h(strip_tags($axes['stepsAbove']['w'])) . '"><i>머리 위 계단</i><b>'
       . ($r['f_steps_above'] === null ? '—' : (int)$r['f_steps_above'] . '개') . '</b></div>'
       . '</div>'
       /* ── 좌: 일봉(앞뒤 55거래일) · 우: 1분봉 ── */
       . '<div class="fl-body">'
       . '<div class="fl-pane"><div class="fl-ptit"><b>일봉</b> 앞뒤 55거래일</div>'
       . '<div id="flc_' . $id . '" class="fl-chart">'
       . '<span class="fl-loading">차트 불러오는 중…</span></div></div>'
       . '<div class="fl-pane"><div class="fl-ptit"><b>1분봉</b> '
       . '<span id="flmt_' . $id . '">' . ((int)$r['has_min'] ? '아카이브' : '없음') . '</span>'
       . ((int)$r['has_min'] ? '<span class="fl-mz" id="flz_' . $id . '">'
                          . '<button type="button" data-z="all" class="on">전체</button>'
                          . '<button type="button" data-z="sig">신호일</button>'
                          . '<button type="button" data-z="post">신호~D+5</button></span>' : '')
       . '</div>'
       . '<div id="flm_' . $id . '" class="fl-mchart">'
       . '<span class="fl-loading">' . ((int)$r['has_min'] ? '분봉 불러오는 중…'
          : ((int)$r['min_stage'] === 0
             ? '분봉을 아직 못 받았습니다 — 크론이 하루 예산 안에서 차례로 받아 옵니다.'
             : '이 신호는 분봉 아카이브에 없습니다.')) . '</span></div></div>'
       . '</div>'
       . '</div>';
    return ['html' => $h,
            'card' => ['id' => $id, 'code' => $r['code'], 'sig' => $r['d'],
                       'c' => (int)$r['close_prc'],
                       'tp' => (float)($r['brk_tp'] ?? 15), 'sl' => (float)($r['brk_sl'] ?? 10),
                       'hm' => (int)$r['has_min'], 'span' => $mspan]];
}

// ══════════════════════════════════════════════════════════════════════
//  퀀트 > 패턴분석 (박스 상향돌파) — 2026-08-09 (2026-08-10 옛 패턴분석 흡수: 지도·사례)
//
//  ★한 패턴만 «매일 쌓이는» 자리다. 옛 패턴분석(불꽃형)은 「불꽃형 전수 1,528건을 훑는」
//    화면이었고 필터 칩이 여덟 개였다. 여기는 하루 몇 건씩 쌓이므로 묻는 것이 다르다 —
//    축을 다섯으로 정리했다: 날짜 · 출처 · 등급 · 유형 · 결과.
//
//  ★★<b>「출처」가 새 축이고, 그것이 이 화면에서 가장 중요하다.</b>
//    'pick'  = 사용자가 직접 고른 365건 — 이 패턴의 «정의 원본»
//    'auto'  = 그것을 되맞춘 점수로 매일 자동으로 뽑은 것
//    둘을 섞어 결과를 세면 <b>「이 패턴 승률 좋네」로 잘못 읽힌다</b> — 'pick' 은 «고른 것»이라
//    승률 55.9% 인데 'auto' 는 8년 검정에서 기준선과 사실상 같다(stock/lib/boxbrk.php 머리말).
//
//  ★판정을 여기서 다시 하지 않는다 — `stock/lib/boxbrk.php` 가 정하고 `cron/bx_scan.php` 가
//    `bx_cand` 에 담는다(점수·등급·결과·분봉까지). 화면은 «고르기만» 한다.
//  ★차트는 옛 패턴분석과 <b>같은 로직</b>이다 — 좌 일봉(앞뒤 55거래일 · 익절/손절선 · 최고
//    거래대금 계단) + 우 1분봉(`qm_bar`). 사용자 지시로 그대로 옮겼다.
// ══════════════════════════════════════════════════════════════════════
function pf_page_boxbrk(PDO $pdo, Pf $pf): void
{
    require_once __DIR__ . '/lib/boxbrk.php';   // 축 카탈로그·컷을 화면이 «읽는다»(다시 적지 않는다)

    pf_head('퀀트 · 패턴분석 (박스 상향돌파)', 'quant', 'wide');
    pf_subtabs('boxbrk', 'quant');
    pf_flash();
    pf_quant_css();
    pf_toast_js();

    $PER     = 5;      // 한 줄에 한 건(좌 일봉 : 우 분봉 = 1:2)이라 페이지당 5건 — 옛 화면과 같다
    $INDBARS = 120;    // 조건지표 「최고_거래대금선」 변수_봉수 — 옛 화면과 같은 계단을 본다
    $MSPAN   = 10;     // 우측 분봉이 싣는 구간(거래일)
    /* ★일봉 차트가 보여 주는 앞뒤 거래일 수. <b>「박스」 필터가 이 값을 함께 쓴다</b> —
     *   「차트에 박스가 몇 개 보이나」는 이 창 안에서 세야 화면과 필터가 같은 말을 한다.
     *   그래서 JS 에도 여기서 심는다(예전엔 JS 안에 55 를 따로 적어 두 곳에 살았다). */
    $SPAN    = 55;

    $has = false;
    try { $pdo->query("SELECT 1 FROM bx_cand LIMIT 1"); $has = true; } catch (Throwable $e) { $has = false; }
    if (!$has) {
        echo '<div class="card"><div class="q-note">아직 적재되지 않았습니다 —'
           . ' <code>php cron/bx_scan.php job=migrate</code> 뒤'
           . ' <code>job=backfill from=2025-08-01</code> 을 돌립니다.</div></div>';
        pf_foot();
        return;
    }

    /* ── 필터 읽기 ── 축 다섯 + 정렬 + 관심차트 그룹 ── */
    $d   = (string)($_GET['d'] ?? '');
    $src = (string)($_GET['src'] ?? '');   if (!in_array($src, ['pick', 'auto'], true)) $src = '';
    /* ★'D'(컷 아래)도 고를 수 있어야 한다 — 직접 고른 것의 74%가 거기 있다. 칩을 안 두면
     *   A+B+C 가 전체와 안 맞아 「숫자가 새는」 화면이 된다. */
    $gr  = (string)($_GET['gr'] ?? '');    if (!in_array($gr, ['A', 'B', 'C', 'D'], true)) $gr = '';
    $fl  = (string)($_GET['fl'] ?? '');    if (!in_array($fl, ['0', '1'], true))        $fl  = '';
    /* 「박스」 — <b>차트에 계단이 몇 «벌» 그려지나</b> = 1(신호일 자신) + LEAST(3, 이전 박스 수).
     *
     * ★★2026-08-09 정정: 처음엔 「앞 55거래일 «안»의 박스 봉 수」로 셌는데 <b>화면과 달랐다</b>.
     *   지표가 옛 단계를 3개까지 «연장»해 그려서(lines_json 의 ext:3), 박스 봉이 창 밖이어도
     *   선은 보인다. 실제로 하나마이크론 2026-05-15 는 직전 박스가 72거래일 전(창 밖)이라
     *   내 필터는 「1개」라 했지만 화면엔 계단이 <b>2벌</b> 있었다.
     *   ⇒ 창으로 세지 않는다. `m_box_n`(이전 박스 «총» 개수)만 본다.
     * ★신호일은 <b>반드시</b> 박스이고(신호 하한 100억 > 박스 하한 10억), 이전 박스가 0개인 것은
     *   이제 아예 안 담는다(BoxBrk::MIN_PRIOR_BOX) — 그래서 auto 는 «2벌 이상»만 있다. */
    $bx  = (string)($_GET['bx'] ?? '');
    if (!in_array($bx, ['1', '2', '3', '4'], true)) $bx = '';
    $k   = (string)($_GET['k'] ?? '');
    if (!in_array($k, ['tp', 'sl', 'non', 'amb', 'wait', 'bad'], true)) $k = '';
    $sort = ($_GET['s'] ?? '') === 'old' ? 'old' : 'new';
    $pg   = max(1, (int)($_GET['p'] ?? 1));
    $gid  = max(0, (int)($_GET['g'] ?? 0));
    /* ★종목 검색(2026-08-10 사용자 지시) — 이름 «또는» 코드. 「날짜·관심차트」와 같은 <b>범위</b> 축이라
     *   아래 `$scope` 에 넣는다(칩 숫자도 함께 좁아진다 — 안 그러면 「검색 중인데 등급 A 12건」처럼
     *   화면이 두 말을 한다). 한 종목이 여러 날 신호를 내므로 결과는 <b>여러 건</b>이 정상이다. */
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) > 40) $q = mb_substr($q, 0, 40);
    /* ★자동완성에서 고르면 «코드»로 들어온다(이름은 겹칠 수 있다) — 그러면 칩에 이름을 함께 적는다.
     *   안 그러면 「047040 ✕」만 남아 무엇을 보고 있는지 코드를 외워야 안다. */
    $qLabel = $q;
    if ($q !== '' && preg_match('/^\d{6}$/', $q)) {
        $st = $pdo->prepare("SELECT name FROM bx_cand WHERE code = ? LIMIT 1");
        $st->execute([$q]);
        $nm = (string)$st->fetchColumn();
        if ($nm !== '') $qLabel = $nm . ' (' . $q . ')';
    }

    $fav = new ChartFav($pdo);
    $favGroups = $fav->groups('boxbrk');
    if ($gid > 0 && !$fav->group($gid)) $gid = 0;

    /* ★날짜 문지기 — 셀렉트를 걷어낸 뒤에도 `d` 는 <b>알림 링크</b>로 들어온다.
     *   없는 날이면 조용히 전체로 되돌린다(빈 화면을 보여 주는 것보다 낫다).
     *   예전엔 250행을 세어 목록을 만들었는데, 그 목록은 셀렉트 말고 쓰는 데가 없었다. */
    if ($d !== '') {
        $chk = $pdo->prepare("SELECT EXISTS(SELECT 1 FROM bx_cand WHERE d = ?)");
        $chk->execute([$d]);
        if (!(int)$chk->fetchColumn()) $d = '';
    }

    /* ★「범위」와 「분해」를 가른다 — 날짜·관심차트는 <b>범위</b>이고, 나머지 네 축은
     *   그 범위 «안의 분해»다. 그래서 칩의 숫자는 범위만 걸고 센다(지금 고른 등급까지 걸어
     *   세면 「A 0건」 같은 자기모순이 화면에 뜬다). */
    $scope = ['1=1'];
    if ($d !== '')  $scope[] = 'd = ' . $pdo->quote($d);
    if ($q !== '') {
        /* ★`%` `_` 를 그대로 두면 「_」 한 글자가 아무 글자나 되어 엉뚱한 종목이 섞인다 */
        $like = $pdo->quote('%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%');
        $scope[] = '(name LIKE ' . $like . ' OR code LIKE ' . $like . ')';
    }
    if ($gid > 0)   $scope[] = "EXISTS(SELECT 1 FROM chart_fav_item i WHERE i.fav_id = " . $gid
                             . " AND i.src = 'boxbrk' AND i.code = bx_cand.code AND i.d = bx_cand.d)";
    $scopeSql = implode(' AND ', $scope);

    $agg = $pdo->query("SELECT COUNT(*) n,
                          SUM(src='pick') pick, SUM(src='auto') auto,
                          SUM(grade='A') gA, SUM(grade='B') gB, SUM(grade='C') gC,
                          SUM(grade='D') gD,
                          SUM(src='pick' AND grade IN ('A','B','C')) pickHit,
                          SUM(is_flame=1) fl1, SUM(is_flame=0) fl0,
                          SUM(LEAST(4, 1 + IFNULL(m_box_n,0)) = 1) bx1,
                          SUM(LEAST(4, 1 + IFNULL(m_box_n,0)) = 2) bx2,
                          SUM(LEAST(4, 1 + IFNULL(m_box_n,0)) = 3) bx3,
                          SUM(LEAST(4, 1 + IFNULL(m_box_n,0)) = 4) bx4,
                          SUM(brk_kind='tp') tp, SUM(brk_kind='sl') sl, SUM(brk_kind='non') non,
                          SUM(brk_kind='amb') amb,
                          SUM(brk_kind IS NULL AND q_ohlc=0) wait, SUM(q_ohlc=1) bad,
                          SUM(has_min=1) hm
                        FROM bx_cand WHERE {$scopeSql}")->fetch(PDO::FETCH_ASSOC);

    $w = $scope;
    if ($src !== '') $w[] = 'src = ' . $pdo->quote($src);
    if ($gr  !== '') $w[] = 'grade = ' . $pdo->quote($gr);
    if ($fl  !== '') $w[] = 'is_flame = ' . (int)$fl;
    if ($bx !== '') $w[] = 'LEAST(4, 1 + IFNULL(m_box_n,0)) = ' . (int)$bx;
    if ($k === 'wait')     $w[] = 'brk_kind IS NULL AND q_ohlc = 0';
    elseif ($k === 'bad')  $w[] = 'q_ohlc = 1';
    elseif ($k !== '')     $w[] = 'brk_kind = ' . $pdo->quote($k);
    $where = implode(' AND ', $w);

    $tot   = (int)$pdo->query("SELECT COUNT(*) FROM bx_cand WHERE {$where}")->fetchColumn();
    $pages = max(1, (int)ceil($tot / $PER));
    if ($pg > $pages) $pg = $pages;
    $off = ($pg - 1) * $PER;
    $rows = $pdo->query("SELECT * FROM bx_cand WHERE {$where}
                          ORDER BY d " . ($sort === 'old' ? 'ASC' : 'DESC') . ", z DESC, code
                          LIMIT {$PER} OFFSET {$off}")->fetchAll(PDO::FETCH_ASSOC);

    /* 조건지표 — 원본은 `chart_indicator` 다. 이름·색·굵기를 화면에 다시 적지 않는다. */
    $flInd = null;
    try {
        foreach ((new ChartIndicator($pdo))->list() as $def) {
            if (strpos((string)$def['name'], '최고_거래대금선') === 0) { $flInd = $def; break; }
        }
    } catch (Throwable $e) { $flInd = null; }

    /* ── 문서층 (2026-08-10 옛 패턴분석 흡수) — 지도·특징·사례가 읽는 데이터 ──
     *
     * ★특징 표는 «필터와 무관하게 전체»를 센다 — 패턴의 정의·성적을 말하는 자리라
     *   지금 보는 범위를 따라 흔들리면 「같은 표가 날마다 다른 말」을 한다.
     * ★판정을 다시 하지 않는다 — bx_cand 가 담은 brk_kind/brk_ret 을 «세기만» 한다.
     * ★brk_ret 의 모호(amb)는 −10(비관) 으로 담겨 있다 — 표에 「비관 기준」이라 적는다. */
    $axes = boxbrk_axes();
    $docStat = [];
    foreach ($pdo->query("SELECT src, COUNT(*) n,
                            SUM(brk_kind='tp') tp, SUM(brk_kind='sl') sl,
                            SUM(brk_kind='non') non, SUM(brk_kind='amb') amb,
                            SUM(brk_kind IS NOT NULL) nj,
                            SUM(brk_kind IS NOT NULL AND brk_ret > 0) win
                          FROM bx_cand GROUP BY src")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $docStat[$r['src']] = $r;
    }
    foreach ($docStat as $s2 => $r) {
        $v = $pdo->query("SELECT brk_ret FROM bx_cand WHERE src = " . $pdo->quote($s2)
                       . " AND brk_kind IS NOT NULL ORDER BY brk_ret")->fetchAll(PDO::FETCH_COLUMN);
        $v = array_map('floatval', $v);
        $n2 = count($v);
        $docStat[$s2]['avg'] = $n2 ? array_sum($v) / $n2 : null;
        $docStat[$s2]['med'] = $n2 ? ($n2 % 2 ? $v[intdiv($n2, 2)] : ($v[$n2 / 2 - 1] + $v[$n2 / 2]) / 2) : null;
    }
    /* ⊖「실제 사례」 카드는 2026-08-10 사용자 지시로 <b>삭제</b>했다 — 극단 3+3 을 보여 주던 자리인데,
     *   전형은 위 표의 중앙값이고 낱건은 목록에서 얼마든지 본다. 뽑던 쿼리도 함께 지운다
     *   (화면만 지우고 조회를 남기면 «아무도 안 보는 쿼리»가 매 요청 돈다). */

    /* 관심차트 멤버십 — 목록의 것만 묻는다 */
    $favKeys = [];
    foreach ($rows as $r) $favKeys[] = $r['code'] . '|' . $r['d'];
    $favMember = $fav->memberOf('boxbrk', array_values(array_unique($favKeys)));
    $cards = [];

    echo '<style>
.fl-grid{display:grid;grid-template-columns:1fr;gap:14px;margin-top:10px}
.fl-card{border:1px solid #dfe6ec;border-radius:10px;padding:10px 12px;background:#fff;min-width:0}
.fl-card.sel{border-color:#12406b;box-shadow:0 0 0 2px rgba(18,64,107,.12)}
.fl-card.pick{border-left:4px solid #12406b}
.fl-body{display:grid;grid-template-columns:1fr 2fr;gap:12px;align-items:start}
@media(max-width:1100px){.fl-body{grid-template-columns:1fr}}
.fl-pane{min-width:0}
.fl-ptit{font-size:11.5px;color:#8496a6;margin:0 0 3px;display:flex;align-items:center;gap:6px}
.fl-ptit b{color:#556}
.fl-hd{display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;font-size:14px}
.fl-hd .code{color:#9ab;font-size:12px}
.fl-hd .dt{color:#556;font-size:12.5px;margin-left:auto}
.fl-hd .fl-ck{margin-right:2px;width:16px;height:16px;cursor:pointer;align-self:center}
.fl-meta{font-size:12px;color:#667;margin:4px 0 6px;display:flex;gap:10px;flex-wrap:wrap}
.fl-meta b{color:#334}
.fl-ax{display:grid;grid-template-columns:repeat(5,1fr);gap:4px;margin:2px 0 7px;font-size:11.5px;max-width:640px}
.fl-ax div{background:#f7fafc;border:1px solid #eef3f7;border-radius:6px;padding:3px 6px;text-align:center}
.fl-ax i{display:block;color:#93a2b0;font-style:normal;font-size:10.5px;white-space:nowrap;
  overflow:hidden;text-overflow:ellipsis}
.fl-ax b{color:#22303f;font-size:12.5px}
.fl-chart{height:300px;position:relative}
.fl-mchart{height:300px;position:relative;background:#12181f;border-radius:6px}
.fl-mchart .fl-loading{color:#7d8c9b}
.fl-loading{position:absolute;top:45%;left:0;right:0;text-align:center;color:#9ab;font-size:13px;
  padding:0 12px;line-height:1.5}
.fl-mz{display:flex;gap:4px;margin-left:auto}
.fl-mz button{border:1px solid #dfe6ec;background:#fff;color:#345;border-radius:11px;
  padding:1px 9px;font-size:11.5px;cursor:pointer;line-height:1.7}
.fl-mz button.on{background:#12406b;border-color:#12406b;color:#fff;font-weight:700}
.fl-g{display:inline-block;padding:1px 8px;border-radius:9px;font-size:11.5px;font-weight:700;
  background:#e8f0fe;color:#1a56c4;border:1px solid #c6dafc}
.fl-sel{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:8px 0 2px;font-size:13px;
  padding:7px 10px;background:#f4f7fa;border:1px solid #e3ebf2;border-radius:8px}
.fl-sel select,.fl-sel input[type=text]{border:1px solid #cfd9e2;border-radius:6px;padding:3px 7px;
  font-size:13px;background:#fff}
.fl-sel button{border:1px solid #12406b;background:#12406b;color:#fff;border-radius:6px;
  padding:4px 12px;font-size:13px;cursor:pointer}
.fl-sel button.gh{background:#fff;color:#345;border-color:#cfd9e2}
.fl-gm{margin:8px 0 2px;padding:9px 11px;background:#fff;border:1px solid #e3ebf2;border-radius:8px}
.fl-gm table{width:100%;border-collapse:collapse;font-size:13px}
.fl-gm td{padding:4px 6px;border-bottom:1px solid #eef3f7}
.fl-gm input[type=text]{border:1px solid #cfd9e2;border-radius:6px;padding:3px 7px;width:100%;
  font-size:13px;box-sizing:border-box}
.fl-gm button{border:1px solid #cfd9e2;background:#fff;color:#345;border-radius:6px;padding:3px 10px;
  font-size:12.5px;cursor:pointer}
.fl-gm button.pri{background:#12406b;border-color:#12406b;color:#fff}
.fl-gm button.del{color:#c62828;border-color:#f0c9c6}
.fl-b{display:inline-block;padding:2px 9px;border-radius:10px;font-size:12px;font-weight:700}
.fl-tp{background:#e6f4ea;color:#1e7e34}.fl-sl{background:#fdecea;color:#c62828}
.fl-non{background:#eef2f6;color:#55636f}.fl-amb{background:#fdf3e0;color:#b26a00}
.fl-wait{background:#eaf3fb;color:#1565c0}
.fl-lim{background:#fff3cd;color:#8a6d1a}
.fl-halt{background:#eceff1;color:#546e7a;border:1px solid #cfd8dc}
.fl-msrc{background:#ede7f6;color:#5e35b1;border:1px solid #d1c4e9}
.fl-pick{background:#12406b;color:#fff}
.fl-auto{background:#eef2f6;color:#55636f;border:1px solid #dfe6ec}
.fl-gA{background:#12406b;color:#fff}.fl-gB{background:#dce9f7;color:#12406b}
.fl-gC{background:#eef2f6;color:#55636f}
.fl-fm{background:#fdf3e0;color:#b26a00;border:1px solid #f0dcb4}
.fl-warn{background:#fdecea;color:#c62828;font-size:11.5px;padding:1px 7px;border-radius:9px;
  font-weight:700;display:none}
.fl-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:8px 0 2px;font-size:13px}
.fl-bar>b{font-size:12.5px;color:#556;min-width:34px}
.fl-bar a{display:inline-block;padding:3px 10px;border:1px solid #dfe6ec;border-radius:14px;
  background:#fff;color:#345;text-decoration:none}
.fl-bar a.on{background:#12406b;border-color:#12406b;color:#fff;font-weight:700}
.fl-bar select{border:1px solid #cfd9e2;border-radius:6px;padding:3px 7px;font-size:13px;background:#fff}
/* 종목 검색 (2026-08-10) — 칩과 같은 높이·같은 둥근 모서리라 한 줄에 섞여도 어색하지 않다 */
.fl-srch{display:inline-flex;gap:5px;align-items:center;margin:0}
.fl-srch input{border:1px solid #cfd9e2;border-radius:14px;padding:3px 11px;font-size:13px;
  background:#fff;width:150px}
.fl-srch button{border:1px solid #cfd9e2;border-radius:14px;padding:3px 11px;font-size:13px;
  background:#f4f7fa;color:#345;cursor:pointer}
.fl-srch button:hover{background:#e9eff5}
/* 자동완성 목록은 폼(≈230px)보다 넓어야 「이름·코드·건수」가 한 줄에 들어간다 */
.fl-srch .stk-list{min-width:280px}
/* 「기준」 버튼 — 찾기 옆에 붙지만 폼 밖이라 제출되지 않는다 */
.fl-crit{border:1px solid #cfd9e2;border-radius:14px;padding:3px 11px;font-size:13px;
  background:#fff;color:#12406b;font-weight:700;cursor:pointer}
.fl-crit:hover{background:#f4f7fa}
/* 모달 그릇 — 사이트의 다른 화면(pf-modal-back/pf-modal)과 같은 클래스·같은 모양 */
.pf-modal-back{display:none;position:fixed;inset:0;background:rgba(16,32,48,.5);z-index:200;
  align-items:flex-start;justify-content:center;padding:40px 14px;overflow-y:auto}
.pf-modal-back.on{display:flex}
.pf-modal{background:#fff;border-radius:13px;width:100%;max-width:660px;
  box-shadow:0 18px 50px rgba(10,25,45,.3);overflow:hidden}
.pfm-head{display:flex;justify-content:space-between;align-items:center;padding:13px 16px;
  background:linear-gradient(90deg,#123c63,#1d5c93);color:#fff;font-size:16px;font-weight:800}
.pfm-x{background:rgba(255,255,255,.16);border:none;color:#fff;font-size:14px;cursor:pointer;
  width:28px;height:28px;border-radius:7px;line-height:1}
.pfm-x:hover{background:rgba(255,255,255,.3)}
.fl-pg{display:flex;gap:5px;flex-wrap:wrap;justify-content:center;margin:18px 0 4px;font-size:13px}
.fl-pg a,.fl-pg span{display:inline-block;min-width:32px;text-align:center;padding:5px 8px;
  border:1px solid #dfe6ec;border-radius:6px;background:#fff;color:#345;text-decoration:none}
.fl-pg a:hover{background:#f4f7fa}
.fl-pg .cur{background:#12406b;border-color:#12406b;color:#fff;font-weight:700}
.fl-pg .gap{border:0;background:transparent;color:#9ab;min-width:16px}
/* ── 문서층 (옛 패턴분석에서 옮겨 온 그릇) ── */
.bx-flow{display:flex;flex-direction:column;gap:6px;font-size:13px;margin:10px 0 4px}
.bx-fr{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.bx-fb{border:1px solid #dfe6ec;border-radius:8px;padding:5px 10px;background:#fff;white-space:nowrap}
.bx-fa{color:#9ab}.bx-fk{font-weight:700}
.bx-no{color:#c62828;font-size:12.5px}
.bx-cs-ok{background:#e6f4ea;color:#1e7e34}.bx-cs-bad{background:#fdecea;color:#c62828}
details.card>summary{cursor:pointer;font-size:14px;font-weight:700;color:#12406b;list-style:none}
details.card>summary::before{content:"▸ ";color:#9ab}
details.card[open]>summary::before{content:"▾ "}
table.bx-doc{border-collapse:collapse;width:100%;margin:8px 0 4px;font-size:12.8px}
table.bx-doc th,table.bx-doc td{border:1px solid #e3eaf0;padding:6px 10px;text-align:right;white-space:nowrap}
table.bx-doc th{background:#f4f7fa}
table.bx-doc td:first-child,table.bx-doc th:first-child{text-align:left}
</style>';

    $url = function (array $ov) use ($d, $src, $gr, $fl, $bx, $k, $sort, $pg, $gid, $q) {
        $p = ['mode' => 'boxbrk', 'd' => $d, 'src' => $src, 'gr' => $gr, 'fl' => $fl, 'bx' => $bx,
              'k' => $k, 's' => $sort, 'p' => $pg, 'g' => $gid ?: '', 'q' => $q];
        foreach ($ov as $k2 => $v) $p[$k2] = $v;
        $p = array_filter($p, fn($v) => $v !== '' && $v !== null);
        return '/stock/index.php?' . http_build_query($p);
    };
    $chip = function (string $key, string $val, string $label, int $n) use ($url, $d, $src, $gr, $fl, $bx, $k) {
        $cur = ['src' => $src, 'gr' => $gr, 'fl' => $fl, 'bx' => $bx, 'k' => $k][$key] ?? '';
        return '<a href="' . pf_h($url([$key => $val, 'p' => 1])) . '"'
             . ($cur === $val ? ' class="on"' : '') . '>' . $label
             . ($n >= 0 ? ' ' . number_format($n) : '') . '</a>';
    };

    echo '<div class="pf-head"><div><h1>패턴분석 <span class="muted">(박스 상향돌파)</span></h1>'
       . '<div class="sub">직접 고르신 <b>' . number_format((int)$agg['pick']) . '건</b>이 이 패턴의'
       . ' <b>정의 원본</b>이고, 그것을 되맞춘 점수로 <b>매일 마감 뒤</b> 같은 패턴을 찾아 쌓습니다.'
       . ' <span class="muted">(사전 피처 11축 · 교차검증 AUC 0.750 · 판정 단일본'
       . ' <code>stock/lib/boxbrk.php</code> · 적재 <code>cron/bx_scan.php</code> 매일 16:20)</span><br>'
       . '<span class="muted">★<b>「출처」를 반드시 갈라 보세요</b> — 직접 고른 것은 «고른 것»이라'
       . ' 승률이 55.9%인데, 자동 수집분은 8년 재측정(2026-08-10 · 최종 5조건)에서도 기준선과 사실상'
       . ' 같습니다(비용 뒤 +0.01% vs −0.20% · 승률 41.7 vs 41.4% · 중앙은 더 나쁨 —'
       . ' <a href="/stock/index.php?mode=quantstat">검증 탭 ⑨</a>).'
       . ' 섞어서 세면 「이 패턴 승률 좋네」로 잘못 읽힙니다.</span></div></div></div>';

    /* ── ⚡장중 잠정 (2026-08-10 · 사용자 요청 「장중에 확인할 수 없어?」) ──────────────
     *
     * ★<b>잠정</b>이라고 화면이 먼저 말한다 — 고가·저가·현재가가 마감까지 바뀌므로 조건 ③④⑤가
     *   뒤집힐 수 있다. 그리고 <b>원장(`bx_cand`)에는 담지 않는다</b>(boxbrk_live 주석).
     * ★자동 갱신을 걸지 않는다 — 후보를 «지켜보는» 자리가 아니라 «지금 있나» 묻는 자리다.
     *   누를 때만 나간다(키움 1콜 · 전종목 훑기는 DB 뿐).
     * ★판정을 화면이 다시 하지 않는다 — 서버가 `boxbrk_live()` 한 곳에서 재고 화면은 그린다. */
    echo '<div class="card" id="bxlive"><h2>⚡ 장중 잠정 <span class="q-note" style="display:inline">'
       . '(09:00~15:30 · 지금 값으로 5조건을 재 봅니다 — <b>마감까지 바뀝니다</b> · 표에 담지 않습니다)'
       . '</span> <button type="button" id="bxlv-go" class="btn sm" style="float:right">다시 보기</button></h2>'
       . '<div id="bxlv-body" class="q-note">불러오는 중…</div></div>';

    /* ── 패턴 지도 — 판정 순서 그대로 (옛 패턴분석의 「패턴 지도」를 잇는다 · 2026-08-10) ──
     * ★임계를 손으로 적지 않는다 — 전부 BoxBrk 상수에서 보간한다. 상수를 고치면 지도가 따라온다. */
    /* ★기본은 «접힘»이다(2026-08-10 사용자 지시) — 정의는 한 번 읽으면 되는 글인데 늘 펴 있으면
     *   화면을 열 때마다 «오늘 뜬 후보»가 스크롤 아래로 밀린다. 사례 카드와 같은 그릇(`details.card`)이라
     *   여는 방법도 같다. ★없애지 않는 이유 — 조건을 잊었을 때 돌아올 자리가 있어야 한다. */
    $pcFmt = fn(float $v) => rtrim(rtrim(sprintf('%.1f', $v), '0'), '.');
    echo '<details class="card"><summary>패턴 지도 — 판정 순서 · 특징'
       . ' <span class="q-note" style="display:inline;font-weight:400">'
       . '(다섯 조건과 지금까지 쌓인 것의 실측 · 눌러서 펼칩니다)</span>'
       . '</summary><div class="bx-flow">'
       . '<div class="bx-fr"><span class="bx-fb bx-fk">최고 거래대금 신호</span>'
       .   '<span class="bx-fa">거래대금이 직전 ' . (BoxBrk::WIN - 1) . '거래일 최고 & '
       .   number_format(BoxBrk::MINAMT / 1e8) . '억↑ → 그 날 고가 H·저가 L = 새 «박스»'
       .   ' (우선주·ETF·스팩·ETN 제외)</span></div>'
       . '<div class="bx-fr"><span class="bx-fa">①</span><span class="bx-fb">이전에 '
       .   number_format(BoxBrk::PRIOR_BOX_AMT / 1e8) . '억↑ 박스가 있나?</span>'
       .   '<span class="bx-no">없으면 제외 — 뚫을 박스가 없으면 「돌파」가 성립하지 않는다 (첫 폭발)</span></div>'
       . '<div class="bx-fr"><span class="bx-fa">②</span><span class="bx-fb">등락 +'
       .   $pcFmt(BoxBrk::CHG_MAX) . '%↑ (상한가 근처)?</span>'
       .   '<span class="bx-no">예: 제외 — 종가에 실제로 살 수 없는 날</span></div>'
       . '<div class="bx-fr"><span class="bx-fa">③</span><span class="bx-fb">새 박스가 기존 박스에 붙어 있나?</span>'
       .   '<span class="bx-fa">새 L ≤ 기존 H +' . round(BoxBrk::BOX_GAP_MAX * 100) . '% ·</span>'
       .   '<span class="bx-no">멀리 뜬 것은 제외 — 돌파가 아니라 «다른 자리에서 새로 생긴 것»</span></div>'
       . '<div class="bx-fr"><span class="bx-fa">④</span><span class="bx-fb">새 박스가 직전 '
       .   BoxBrk::ABOVE_LAST_N_BOX . '개 박스보다 «위»인가?</span>'
       .   '<span class="bx-fa">L 은 직전 박스와, H 는 ' . BoxBrk::ABOVE_LAST_N_BOX . '개 전부와 ·</span>'
       .   '<span class="bx-no">내려선 것은 제외 — 「상향」이 아니다</span></div>'
       . '<div class="bx-fr"><span class="bx-fa">⑤</span><span class="bx-fb">그 날 시가→종가 +'
       .   $pcFmt(BoxBrk::OC_MIN) . '%↑?</span>'
       .   '<span class="bx-no">아니오: 제외 — 갭으로 뜬 뒤 흘러내린 봉은 밀어올린 돌파가 아니다</span></div>'
       . '<div class="bx-fr"><span class="bx-fb bx-fk">✔ 후보로 담는다</span>'
       .   '<span class="bx-fa">매일 16:20 적재 · 등급(A~D)은 «닮음» 점수라 담는 기준이 아니다 ·'
       .   ' B↑ 새 후보는 Pushover 알림</span></div>'
       . '</div><div class="q-note">★이 다섯 조건이 정의의 전부입니다 — 점수 컷은 없습니다.'
       . ' 그리고 <b>이 패턴에 측정된 성과 우위는 아직 없습니다</b> — 근거와 8년 실측은'
       . ' <a href="/stock/index.php?mode=quantstat">검증 탭 ⑨</a>.</div>';

    /* ── 특징·실측 — 필터와 무관한 «전체» 집계. 출처를 갈라 세는 것이 이 표의 존재 이유다.
     * ★2026-08-10 사용자 지시로 <b>패턴 지도 카드 «안»</b>(뒤쪽)으로 넣었다 — 둘 다 「정의를 설명하는
     *   글」이라 한 번 읽고 접어 두는 성질이 같다. 따로 두면 카드 하나는 접히고 하나는 늘 펴 있어
     *   매일 쓰는 목록이 계속 아래로 밀린다. ★그래서 이 블록은 `</details>` «앞»에 있어야 한다. */
    $dsRow = function (string $key, string $label, string $tip) use ($docStat) {
        $r = $docStat[$key] ?? null;
        if (!$r) return '';
        $nj = (int)$r['nj'];
        return '<tr><td title="' . pf_h($tip) . '">' . $label . '</td>'
             . '<td>' . number_format((int)$r['n']) . '</td>'
             . '<td>' . number_format((int)$r['tp']) . '</td>'
             . '<td>' . number_format((int)$r['sl']) . '</td>'
             . '<td>' . number_format((int)$r['non']) . '</td>'
             . '<td>' . number_format((int)$r['amb']) . '</td>'
             . '<td>' . ($nj ? sprintf('%.1f%%', (int)$r['win'] / $nj * 100) : '—') . '</td>'
             . '<td>' . ($r['avg'] !== null ? sprintf('%+.2f%%', $r['avg']) : '—') . '</td>'
             . '<td>' . ($r['med'] !== null ? sprintf('%+.2f%%', $r['med']) : '—') . '</td></tr>';
    };
    echo '<h3 style="font-size:13.5px;margin:16px 0 2px;color:#12406b">특징 — 지금까지 쌓인 것의 실측</h3>'
       . '<table class="bx-doc"><tr><th>출처</th><th>전체</th><th>익절</th><th>손절</th><th>미도달</th>'
       . '<th>모호</th><th>승률</th><th>평균</th><th>중앙</th></tr>'
       . $dsRow('pick', '★직접 고른 것 (정의 원본)',
                '패턴분석(불꽃형) 갤러리에서 결과 배지와 함께 보며 담은 표본입니다')
       . $dsRow('auto', '자동 수집 (이 패턴의 정직한 성적)',
                '5조건 판정만으로 매일 자동으로 담긴 것 — 결과를 본 사람이 없습니다')
       . '</table>'
       . '<div class="q-note">브래킷 = 신호일 종가 매수 → 5거래일 +15%/−10% · 모호는 −10%(비관)로 셌고,'
       . ' 사후 미완·판정불가는 분모에서 뺐습니다.<br>'
       . '★<b>직접 고른 것의 우위는 「눈」이 아니라 「결과」에서 왔습니다</b> — 옛 갤러리 카드가'
       . ' 사후 55거래일 차트와 결과 배지를 함께 띄웠고, 모양이 가장 «안» 닮은 무리에서 담은 것이'
       . ' 가장 좋았습니다(세 갈래 검정 · <a href="/stock/index.php?mode=quantstat">검증 탭 ⑨</a>).'
       . ' 그래서 이 표는 두 줄을 절대 합치지 않습니다.</div></details>';

    echo '<div class="card" style="padding:10px 14px">';

    /* ① 종목 검색 — <b>맨 앞</b>이다(2026-08-11 사용자 지시). 「무엇을 볼까」를 좁히는 자리라
     *   고르는 칩들보다 먼저 온다.
     * ★GET 폼이라 결과가 <b>주소에 남는다</b>(북마크·뒤로가기가 산다). 다른 축은 hidden 으로 지고 간다
     *   — 검색했다고 박스·결과가 조용히 풀리면 안 된다.
     * ★페이지(p)는 일부러 안 싣는다 — 3페이지를 보다 검색하면 1페이지부터다. */
    echo '<div class="fl-bar"><b>검색</b>'
       . '<form method="get" action="/stock/index.php" class="fl-srch stk-wrap">'
       . '<input type="hidden" name="mode" value="boxbrk">'
       . implode('', array_map(
            fn($n, $v) => $v === '' ? '' : '<input type="hidden" name="' . $n . '" value="' . pf_h((string)$v) . '">',
            ['d', 'src', 'gr', 'fl', 'bx', 'k', 's', 'g'],
            [$d, $src, $gr, $fl, $bx, $k, $sort, $gid ?: '']))
       . '<input type="text" id="bxqSearch" name="q" value="' . pf_h($q) . '"'
       . ' placeholder="종목명 또는 코드" autocomplete="off">'
       . '<button type="submit">찾기</button>'
       . '<ul id="bxqList" class="stk-list"></ul></form>'
       /* ★「기준」은 <b>폼 밖</b>에 둔다 — 안에 두면 버튼이 제출로 읽혀 엔터·클릭이 검색을 태운다 */
       . '<button type="button" class="fl-crit" id="bxCrit"'
       . ' title="브래킷(+15%/−10%)·등급·점수 축이 무슨 뜻인지">기준</button>'
       . ($q !== ''
          ? '<a class="on" href="' . pf_h($url(['q' => '', 'p' => 1])) . '"'
            . ' title="이 종목만 보고 있습니다 — 누르면 전체로 돌아갑니다">'
            . pf_h($qLabel) . ' ✕</a>'
          : '')
       . '</div>';

    /* ② 정렬 · 박스 · 결과 — <b>한 줄</b>(2026-08-11 사용자 지시). 셋 다 「고르는」 것이라 같은 줄이다.
     * ★뒤에 붙는 ✕ 칩들은 «칩을 내린 축»(날짜·출처·유형·등급)이 주소로 들어왔을 때만 나온다 —
     *   안 그러면 «보이지 않는 필터»가 된다(알림 링크가 `?mode=boxbrk&d=…` 로 들어온다). */
    $offChip = function (string $key, string $label, string $shown) use ($url) {
        return '<b style="margin-left:10px">' . $label . '</b>'
             . '<a class="on" href="' . pf_h($url([$key => '', 'p' => 1])) . '"'
             . ' title="주소로 들어온 ' . $label . ' 필터입니다 — 누르면 전체로 돌아갑니다">'
             . $shown . ' ✕</a>';
    };
    echo '<div class="fl-bar"><b>정렬</b>'
       . '<a href="' . pf_h($url(['s' => 'new', 'p' => 1])) . '"' . ($sort === 'new' ? ' class="on"' : '') . '>최신순</a>'
       . '<a href="' . pf_h($url(['s' => 'old', 'p' => 1])) . '"' . ($sort === 'old' ? ' class="on"' : '') . '>오래된순</a>'
       /* 박스 — 차트에 계단이 몇 «벌» 그려지나(신호일 + 옛 단계 최대 3). 창과 무관하다. */
       . '<b style="margin-left:10px">박스</b>'
       . $chip('bx', '', '전체', -1)
       . ((int)$agg['bx1'] > 0 ? $chip('bx', '1', '1벌(뚫을 박스 없음)', (int)$agg['bx1']) : '')
       . $chip('bx', '2', '2벌', (int)$agg['bx2'])
       . $chip('bx', '3', '3벌', (int)$agg['bx3'])
       . $chip('bx', '4', '4벌↑', (int)$agg['bx4'])
       . '<b style="margin-left:10px">결과</b>'
       . $chip('k', '', '전체', -1)
       . $chip('k', 'tp', '익절', (int)$agg['tp'])
       . $chip('k', 'sl', '손절', (int)$agg['sl'])
       . $chip('k', 'non', '미도달', (int)$agg['non'])
       . $chip('k', 'amb', '모호', (int)$agg['amb'])
       . $chip('k', 'wait', '사후 미완', (int)$agg['wait'])
       . $chip('k', 'bad', '판정불가', (int)$agg['bad'])
       . ($d   !== '' ? $offChip('d',   '날짜', pf_h($d)) : '')
       . ($src !== '' ? $offChip('src', '출처', $src === 'pick' ? '★직접 고른 것' : '자동 수집') : '')
       . ($fl  !== '' ? $offChip('fl',  '유형', $fl === '1' ? '불꽃형' : '그 밖') : '')
       . ($gr  !== '' ? $offChip('gr',  '등급', pf_h($gr)) : '')
       . '</div>';

    /* ⊖「출처」·「유형」 칩은 2026-08-10 사용자 지시로 <b>내렸다</b> — 둘 다 카드에 배지로 이미 붙어 있고
     *   (★내가 고름/자동 · 불꽃형), 하루 몇 건짜리 목록에서 그것으로 «거르는» 일은 없었다.
     * ★그래도 파라미터(`src`·`fl`)는 <b>살려 둔다</b> — 옛 북마크로 들어올 수 있는데 칩이 없으면
     *   보이지 않는 필터에 «갇힌» 화면이 된다. 들어오면 아래 정렬 줄에 ✕ 칩으로 드러난다.
     * ★출처를 «세는» 자리는 그대로다 — 패턴 지도 카드의 특징 표가 pick/auto 를 갈라 센다
     *   (CLAUDE.md 규칙 1 의 「섞어서 결과를 세지 않는다」는 그 표가 지킨다). */

    /* ⊖「등급」 칩도 2026-08-10 사용자 지시로 내렸다 — 카드에 등급 배지(A~D + 확률)가 이미 붙는다.
     *   ★덤으로 옛 걱정 하나가 사라졌다: 「A+B+C 만 칩으로 두면 전체와 안 맞아 조용히 거짓말한다」
     *     (그래서 D 칩을 뒀었다) — 칩 자체가 없으면 부분합을 보여 줄 일이 없다.
     *   ★파라미터 `gr` 은 살려 둔다(알림·북마크) → 들어오면 정렬 줄에 ✕ 칩으로 드러난다. */

    /* ★다섯 조건의 서술은 위 «패턴 지도» 카드로 옮겼다(2026-08-10) — 두 곳에 적으면
     *   상수를 고칠 때 한쪽이 조용히 거짓이 된다. 여기는 브래킷·등급·축 설명만 남긴다.
     * ★2026-08-11 사용자 지시로 이 글을 <b>「기준」 모달</b>로 옮겼다 — 매일 보는 자리는 목록이라
     *   설명이 목록 위에 늘 깔려 있을 이유가 없다. 지우지 않은 이유는 그대로다: 브래킷(+15/−10)과
     *   등급의 뜻을 모르면 카드의 배지가 읽히지 않는다. */
    $critHtml = '<div class="q-note">신호의 <b>다섯 조건</b>은 위 «패턴 지도»가 정의합니다 —'
       . ' <b>점수 컷은 없습니다</b>(등급은 표시·정렬·알림용).'
       . ' <span class="muted">불꽃형의 「20평비 20배↑」는 <b>안 걸었습니다</b> — 담기 취향은 오히려'
       . ' 20평비가 «낮은» 쪽이라 유형은 배지로만 구분합니다.</span><br>'
       . '브래킷 = 신호일 <b>종가</b>에 사서 <b>5거래일</b> 안에 <b>+15%</b> 익절 / <b>−10%</b> 손절,'
       . ' 안 닿으면 D+5 종가. <b>사후 미완</b>은 아직 5거래일이 안 찬 <b>최근</b> 신호입니다 —'
       . ' 그래서 <b>오늘 것을 보고 담으면 결과를 못 본 채 고르게 됩니다</b>.<br>'
       . '★<b>등급(z)은 「오를까」가 아니라 「내가 담은 것과 얼마나 닮았나」입니다</b> —'
       . ' 불꽃형 1,225건 중 담으신 348건을 되맞춘 로지스틱 점수입니다.'
       . ' <span class="muted">직접 고르신 ' . number_format((int)$agg['pick']) . '건 중 상위 25%(C)에'
       . ' 드는 것은 <b>' . number_format((int)$agg['pickHit']) . '건('
       . ((int)$agg['pick'] > 0 ? round(100 * (int)$agg['pickHit'] / (int)$agg['pick'], 1) : 0)
       . '%)</b>뿐입니다 — 학습은 «불꽃형 안에서» 했는데 지금 모집단은 박스 조건으로 걸러진 다른'
       . ' 무리라서입니다. 그래서 2026-08-09 에 <b>적재 컷을 없앴습니다</b>(같은 종목의 비슷한 돌파가'
       . ' 하나만 나오던 문제 — 씨어랩 189330 05-29 vs 07-09).</span><br>'
       . '점수 축(11개 중 카드에 적는 5개) — '
       . implode(' · ', array_map(function ($kk) use ($axes) {
             return '<b>' . pf_h($axes[$kk]['n']) . '</b>' . ($axes[$kk]['dir'] > 0 ? '↑' : '↓');
         }, ['vola20', 'brkHi120', 'dYHigh', 'posPrev60', 'stepsAbove']))
       . '</div>';

    echo pf_flame_fav_bar($url, $favGroups, $gid)
       . '</div>';

    /* 「기준」 모달 — 그릇은 사이트 공용(`pf-modal-back`/`pf-modal`)이라 여기서 새로 만들지 않는다 */
    echo '<div class="pf-modal-back" id="bxCritBack"><div class="pf-modal" style="max-width:760px">'
       . '<div class="pfm-head">기준 — 이 화면의 숫자를 읽는 법'
       . '<button type="button" class="pfm-x" id="bxCritX">✕</button></div>'
       . '<div style="padding:14px 16px">' . $critHtml . '</div></div></div>';

    if (!$rows) {
        /* ★왜 비었는지를 «갈라» 적는다 — 「검색어에 맞는 종목이 없다」와 「그 종목은 있는데 지금 건
         *   등급·결과 필터에 안 걸린다」는 전혀 다른 이야기다(빈 칸은 고장으로 읽힌다). */
        $qHit = 0;
        if ($q !== '') $qHit = (int)$agg['n'];      // $agg 는 «범위»(검색 포함)만 걸고 센 값이다
        echo '<div class="card"><div class="q-note">이 조건에 맞는 것이 없습니다.'
           . ($gid > 0 ? ' 이 <b>관심차트 그룹이 비어</b> 있거나 필터와 겹치는 것이 없습니다.' : '')
           . ($q !== ''
              ? ($qHit > 0
                 ? ' <b>「' . pf_h($q) . '」</b> 은 ' . number_format($qHit) . '건 있으나'
                   . ' 지금 고른 등급·유형·박스·결과 필터에 걸리는 것이 없습니다 —'
                   . ' <a href="' . pf_h($url(['gr' => '', 'fl' => '', 'bx' => '', 'k' => '', 'p' => 1]))
                   . '">필터만 풀기</a>.'
                 : ' <b>「' . pf_h($q) . '」</b> 로 잡히는 신호가 없습니다.'
                   . ' <span class="muted">이 표는 <b>박스 상향돌파 5조건을 통과한 날</b>만 담습니다 —'
                   . ' 그 종목에 신호가 없었다는 뜻이지 종목이 없다는 뜻이 아닙니다.</span>'
                   . ' <a href="' . pf_h($url(['q' => '', 'p' => 1])) . '">검색 지우기</a>.')
              : '')
           . '</div></div>';
    } else {
        echo '<div class="fl-grid">';
        foreach ($rows as $r) {
            $cc = pf_bx_card($r, $axes, $favMember, $MSPAN);
            echo $cc['html'];
            $cards[] = $cc['card'];
        }
        echo '</div>';

    /* ── 페이지 번호 — 앞뒤 2개씩 + 처음/끝 ── */
    $pgLink = function (int $i) use ($url, $pg) {
        return $i === $pg ? '<span class="cur">' . $i . '</span>'
                          : '<a href="' . pf_h($url(['p' => $i])) . '">' . $i . '</a>';
    };
    echo '<div class="fl-pg">';
    if ($pg > 1) echo '<a href="' . pf_h($url(['p' => $pg - 1])) . '">‹ 이전</a>';
    $shown = [];
    foreach (array_merge([1, 2], range(max(1, $pg - 2), min($pages, $pg + 2)), [$pages - 1, $pages]) as $i) {
        if ($i >= 1 && $i <= $pages) $shown[$i] = true;
    }
    ksort($shown);
    $prev = 0;
    foreach (array_keys($shown) as $i) {
        if ($prev && $i > $prev + 1) echo '<span class="gap">…</span>';
        echo $pgLink($i);
        $prev = $i;
    }
    if ($pg < $pages) echo '<a href="' . pf_h($url(['p' => $pg + 1])) . '">다음 ›</a>';
    echo '</div>';
    echo '<div class="q-note" style="text-align:center">' . number_format($tot) . '건 중 '
       . number_format($off + 1) . '~' . number_format($off + count($rows)) . '번째 · '
       . $pg . ' / ' . $pages . ' 페이지</div>';
    }   // ← 목록·페이지 번호는 결과가 있을 때만. 아래 스크립트는 «항상» — 사례 카드가 쓴다.

    echo '<script src="/style/dailychart.js?v=56"></script>';
    echo '<script>const FL_CARDS=' . json_encode($cards, JSON_UNESCAPED_UNICODE) . ';</script>';
    echo '<script>const FL_IND=' . json_encode(
        $flInd ? [['def' => $flInd, 'vars' => ['변수_봉수' => $INDBARS]]] : [], JSON_UNESCAPED_UNICODE)
       . ', FL_SPAN=' . (int)$SPAN . ';</script>';   // ★「박스」 필터와 «같은 창»을 쓴다
    $favIds = [];
    foreach ($favMember as $kk => $gs) $favIds[$kk] = array_map(fn($x) => (int)$x['id'], $gs);
    echo '<script>const FL_GROUPS=' . json_encode($favGroups, JSON_UNESCAPED_UNICODE)
       . ', FL_GID=' . $gid
       . ', FL_MEMBER=' . json_encode((object)$favIds, JSON_UNESCAPED_UNICODE) . ';</script>';

    echo <<<'JS'
<script>
DailyChart.load().then(function () {
  /* ── 좌: 일봉 — 옛 패턴분석과 «같은 로직»이다 (사용자 지시로 그대로 옮겼다) ── */
  function render(c) {
    var host = document.getElementById('flc_' + c.id);
    if (!host) return Promise.resolve();
    var dc = DailyChart.create('flc_' + c.id, { theme: 'light' });
    if (!dc) { host.textContent = '차트 라이브러리를 불러오지 못했습니다.'; return Promise.resolve(); }
    /* ★API 는 days 를 160/240/480/1000 «넷 중 하나»만 받는다(그 밖의 값은 조용히 깎인다) */
    return DailyChart.fetchDaily(c.code, 480).then(function (rows) {
      if (!rows.length) { host.textContent = '일봉 데이터를 가져오지 못했습니다.'; return; }
      var ld = host.querySelector('.fl-loading'); if (ld) ld.remove();
      if (rows[0].time > c.sig) {
        host.textContent = '신호일(' + c.sig + ')이 받아 온 일봉 구간(' + rows[0].time + '~)보다 앞이라 그리지 않습니다.';
        return;
      }
      dc.setData(rows);
      var iSig = rows.findIndex(function (r) { return r.time >= c.sig; });
      if (iSig < 0) iSig = rows.length - 1;
      var sig = rows[iSig].time;

      /* ★가격기준 대조 — 차트는 네이버 «수정주가», 우리 값은 KRX «그 날 값»이다 */
      var barC = rows[iSig].close;
      if (c.c > 0 && barC > 0 && Math.abs(barC / c.c - 1) > 0.005) {
        var w = document.getElementById('fw_' + c.id);
        if (w) { w.style.display = 'inline-block'; w.title =
          'KRX 종가 ' + c.c.toLocaleString() + ' vs 차트(수정주가) ' + Math.round(barC).toLocaleString()
          + ' — 분할·증자로 기준이 다릅니다. 위 % 값은 KRX 기준이라 맞고, 차트의 익절·손절선만 어긋납니다.'; }
      }
      /* 익절·손절선은 «차트가 쓰는 기준»으로 그린다 — 선과 캔들이 같은 자에 놓이도록 */
      var base = barC;
      var iEnd = Math.min(rows.length - 1, iSig + 5);
      dc.addLine({ color: '#1e7e34', width: 1, style: 'dashed' })
        .setData([{ time: sig, value: base * (1 + c.tp / 100) },
                  { time: rows[iEnd].time, value: base * (1 + c.tp / 100) }]);
      dc.addLine({ color: '#c62828', width: 1, style: 'dashed' })
        .setData([{ time: sig, value: base * (1 - c.sl / 100) },
                  { time: rows[iEnd].time, value: base * (1 - c.sl / 100) }]);
      dc.setBoxes([{ from: sig, to: rows[iEnd].time, top: base * (1 + c.tp / 100),
                     bottom: base * (1 - c.sl / 100), fill: 'rgba(240,165,0,0.07)',
                     topColor: 'rgba(30,126,52,0.0)', bottomColor: 'rgba(198,40,40,0.0)',
                     sideColor: '#c9b37e' }]);
      dc.setMarkers([{ time: sig, text: '신호' }]);
      /* ★setMarkers «뒤»에 부른다 — setIndicators 가 마커를 다시 합친다 */
      if (FL_IND.length) dc.setIndicators(FL_IND);
      /* ★보는 구간은 서버가 심는다(FL_SPAN) — 「박스 1개/2개↑」 필터가 이 창으로 세기 때문이다.
         여기 55 를 따로 적으면 필터와 그림이 조용히 어긋난다. */
      dc.zoomRange(rows[Math.max(0, iSig - FL_SPAN)].time,
                   rows[Math.min(rows.length - 1, iSig + FL_SPAN)].time,
                   { minBars: 40, pad: 2 });
    }).catch(function () { host.textContent = '차트를 불러오지 못했습니다.'; });
  }

  /* ── 우: 1분봉 — `qm_bar`. 기준가는 «신호일 마지막 분봉 종가»다 ── */
  function renderMin(c) {
    var host = document.getElementById('flm_' + c.id);
    if (!host || !c.hm) return Promise.resolve();
    return fetch('/stock/api.php?module=qm&action=bars&code=' + encodeURIComponent(c.code)
                 + '&d=' + encodeURIComponent(c.sig) + '&span=' + c.span,
                 { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (raw) {
        var rows = (Array.isArray(raw) ? raw : []).map(function (x) {
          return {   // KST 벽시계를 UTC 로 취급 — dailychart 의 분봉과 같은 'Z' 트릭
            time: Math.floor(Date.parse(x.t.replace(' ', 'T') + 'Z') / 1000),
            open: x.o, high: x.h, low: x.l, close: x.c, vol: x.v
          };
        });
        if (!rows.length) {
          host.innerHTML = '<span class="fl-loading">분봉을 가져오지 못했습니다.</span>'; return;
        }
        var ld = host.querySelector('.fl-loading'); if (ld) ld.remove();
        var dc = DailyChart.create('flm_' + c.id,
                                   { theme: 'dark', kind: 'minute', multiDay: true,
                                     screen: '', resize: false });
        if (!dc) { host.innerHTML = '<span class="fl-loading">차트 라이브러리 오류</span>'; return; }
        dc.setData(rows);

        var byDay = {}, days = [];
        rows.forEach(function (b) {
          var k = new Date(b.time * 1000).toISOString().slice(0, 10);
          if (!byDay[k]) { byDay[k] = []; days.push(k); }
          byDay[k].push(b);
        });
        days.sort();
        /* ★실린 구간을 제목에 적는다 — 「앞뒤 며칠」이라 못 박으면 그때그때 거짓이 된다 */
        var tit = document.getElementById('flmt_' + c.id);
        if (tit) {
          var bf = days.filter(function (k) { return k < c.sig; }).length;
          var af = days.filter(function (k) { return k > c.sig; }).length;
          tit.textContent = days[0] + ' ~ ' + days[days.length - 1]
                          + ' (' + days.length + '거래일 · 전 ' + bf + ' / 후 ' + af + ')';
        }
        var sigBars = byDay[c.sig];
        if (!sigBars) {   /* 신호일 봉이 없으면 선을 긋지 않는다 — 기준가가 없다 */
          dc.zoomRange(rows[0].time, rows[rows.length - 1].time, { minBars: 60, pad: 2 });
          return;
        }
        var base   = sigBars[sigBars.length - 1].close;
        var sigEnd = sigBars[sigBars.length - 1].time;
        var post   = days.filter(function (k) { return k > c.sig; }).slice(0, 5);
        var endT   = post.length ? byDay[post[post.length - 1]].slice(-1)[0].time
                                 : rows[rows.length - 1].time;
        try {
          dc.addLine({ color: '#31c48d', width: 1, style: 'dashed' })
            .setData([{ time: sigEnd, value: base * (1 + c.tp / 100) },
                      { time: endT,   value: base * (1 + c.tp / 100) }]);
          dc.addLine({ color: '#ff6b6b', width: 1, style: 'dashed' })
            .setData([{ time: sigEnd, value: base * (1 - c.sl / 100) },
                      { time: endT,   value: base * (1 - c.sl / 100) }]);
          dc.setBoxes([{ from: sigEnd, to: endT, top: base * (1 + c.tp / 100),
                         bottom: base * (1 - c.sl / 100), fill: 'rgba(240,165,0,0.06)',
                         topColor: 'rgba(49,196,141,0.0)', bottomColor: 'rgba(255,107,107,0.0)',
                         sideColor: '#8a7a4e' }]);
          dc.setMarkers([{ time: sigEnd, text: '신호 종가' }]);
        } catch (e) {}

        var zoom = {
          all:  [rows[0].time, rows[rows.length - 1].time],
          sig:  [sigBars[0].time, sigEnd],
          post: [sigBars[0].time, endT]
        };
        var bar = document.getElementById('flz_' + c.id);
        if (bar) {
          bar.addEventListener('click', function (e) {
            var b = e.target.closest('button'); if (!b) return;
            var z = zoom[b.dataset.z]; if (!z) return;
            bar.querySelectorAll('button').forEach(function (x) { x.classList.remove('on'); });
            b.classList.add('on');
            dc.zoomRange(z[0], z[1], { minBars: 30, pad: 2 });
          });
        }
        dc.zoomRange(zoom.all[0], zoom.all[1], { minBars: 60, pad: 2 });
      })
      .catch(function () {
        host.innerHTML = '<span class="fl-loading">분봉을 불러오지 못했습니다.</span>';
      });
  }

  /* 지연 로드 + 동시 2개 · 한 카드 안에서는 일봉 «다음» 분봉 (옛 화면과 같은 방식) */
  var active = 0, q = [];
  function kick(c) {
    if (active >= 2) { q.push(c); return; }
    active++;
    render(c)
      .then(function () { return renderMin(c); })
      .then(function () { active--; if (q.length) kick(q.shift()); });
  }
  var pending = {};
  FL_CARDS.forEach(function (c) { pending[c.id] = c; });
  if (window.IntersectionObserver) {
    var io = new IntersectionObserver(function (es) {
      es.forEach(function (en) {
        if (!en.isIntersecting) return;
        var id = en.target.id.slice(4), c = pending[id];
        if (!c) return;
        delete pending[id]; io.unobserve(en.target); kick(c);
      });
    }, { rootMargin: '400px 0px' });
    FL_CARDS.forEach(function (c) {
      var h = document.getElementById('flc_' + c.id);
      if (h) io.observe(h); else delete pending[c.id];
    });
  } else { FL_CARDS.forEach(kick); }
}).catch(function () {});
</script>
JS;

    pf_bx_live_js();
    pf_bx_search_js($url(['q' => '__Q__', 'p' => 1]));
    /* 「기준」 모달 — 열고 닫기만. ★Esc·배경 클릭으로도 닫힌다(모달을 열고 갇히는 자리를 만들지 않는다) */
    echo <<<'JS'
<script>
(function () {
  var btn = document.getElementById('bxCrit'), back = document.getElementById('bxCritBack');
  if (!btn || !back) return;
  function open()  { back.classList.add('on'); }
  function close() { back.classList.remove('on'); }
  btn.addEventListener('click', open);
  var x = document.getElementById('bxCritX');
  if (x) x.addEventListener('click', close);
  back.addEventListener('click', function (e) { if (e.target === back) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
})();
</script>
JS;
    pf_flame_fav_js('boxbrk');
    pf_foot();
}

/**
 * 패턴분석 종목 검색 자동완성 (2026-08-10).
 *
 * ★키보드 이동은 <b>`AcNav` 단일본</b>이 맡는다 — 화면에 keydown 을 다시 적지 않는다
 *   (사이트 공통 규칙 · 안 하면 「마우스로만 고를 수 있는 검색창」이 또 생긴다).
 * ★제안의 원천은 <b>`bx_cand` 자신</b>이다(`module=bx&action=search`) — 이 표에 없는 종목을
 *   제안하면 고르는 순간 빈 화면이 된다. 건수·마지막 신호일을 함께 보여 고를 거리를 준다.
 * ★고르면 <b>코드</b>로 옮긴다 — 이름은 겹칠 수 있고, 코드가 그 종목의 유일한 이름이다.
 *   그때도 <b>다른 필터는 지고 간다</b>(주소를 서버가 만들어 심는다 — JS 가 조립하지 않는다).
 * ★Enter 는 <b>친 글자 그대로 검색</b>이다(`enterFirst` 를 켜지 않는다) — 「대우」로 여럿을 보고
 *   싶을 수 있는데 첫 항목으로 튀면 그 길이 막힌다. 화살표로 고른 뒤의 Enter 만 «고르기»다.
 */
function pf_bx_search_js(string $urlTpl): void
{
    pf_stock_picker_css();      // .stk-wrap / .stk-list — 사이트의 자동완성과 같은 그릇
    pf_acnav_js();
    $js = <<<'JS'
<script>
(function () {
  var inp = document.getElementById('bxqSearch');
  var box = document.getElementById('bxqList');
  if (!inp || !box) return;
  var TPL = '__TPL__', timer = null, items = [];

  function close() { box.innerHTML = ''; box.classList.remove('on'); }
  function pick(i) { if (items[i]) location.href = TPL.replace('__Q__', encodeURIComponent(items[i].code)); }

  function render() {
    box.innerHTML = '';
    if (!items.length) { close(); return; }
    items.forEach(function (it, i) {
      var li = document.createElement('li');
      li.innerHTML = '<b>' + it.name + '</b><span>' + it.code + '</span>'
                   + '<em>' + it.n + '건 · ' + String(it.last_d).slice(2) + '</em>';
      li.addEventListener('mousedown', function (e) { e.preventDefault(); pick(i); });
      box.appendChild(li);
    });
    box.classList.add('on');
  }

  inp.addEventListener('input', function () {
    var v = inp.value.trim();
    clearTimeout(timer);
    if (v.length < 1) { close(); return; }
    timer = setTimeout(function () {
      fetch('/stock/api.php?module=bx&action=search&q=' + encodeURIComponent(v), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (l) { items = l || []; render(); })
        .catch(close);
    }, 220);
  });

  /* ↓/↑/Enter/Esc — 공용 모듈이 맡는다 */
  AcNav.attach(inp, { box: box, pick: function (li, i) { pick(i); }, close: close });
  inp.addEventListener('blur', function () { setTimeout(close, 120); });
})();
</script>
JS;
    echo str_replace('__TPL__', addslashes($urlTpl), $js);
}

/**
 * ⚡장중 잠정 후보 — 그리기만 한다 (2026-08-10).
 *
 * ★판정은 서버 `boxbrk_live()` 하나뿐이다. 여기서 조건을 다시 재지 않는다
 *   (Thr.class·ChartFeat 와 같은 「원본 → 화면 표시」 패턴).
 * ★장 밖이면 서버가 `off:1` 로 답한다 — 화면이 시각을 판단하지 않는다(서버가 거래일까지 본다).
 * ★한 줄은 <b>왜 후보인가</b>를 함께 적는다(기존 박스 대비·시가→종가) — 숫자 없이 이름만 뜨면
 *   「지금 뭘 보고 있나」를 알 수 없다.
 */
function pf_bx_live_js(): void
{
    echo <<<'JS'
<script>
(function () {
  var body = document.getElementById('bxlv-body'), btn = document.getElementById('bxlv-go');
  if (!body) return;
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
    return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]; }); }
  function pct(v, d) { return (v >= 0 ? '+' : '') + Number(v).toFixed(d == null ? 1 : d) + '%'; }

  function draw(j) {
    if (!j || j.error) { body.innerHTML = '<span class="muted">잴 수 없었습니다 — ' + esc(j && j.error) + '</span>'; return; }
    if (j.off) { body.innerHTML = '<span class="muted">' + esc(j.note) + '</span>'; return; }
    var rows = j.rows || [];
    /* ★「오늘 새 박스」와 「5조건 통과」를 갈라 적는다 — 박스 «생성»(조건②)은 거래대금이
     *   누적이라 장중에 한 번 넘으면 되돌아가지 않는다(=확정). 뒤집힐 수 있는 것은 그 뒤의
     *   조건들(④의 저가·⑤의 시가→현재가)이다. 「훑은 종목 수」로 적으면 이 사실이 숨는다. */
    var head = '<div class="muted" style="margin-bottom:6px">'
             + esc(j.at) + ' 기준 · <b>오늘 새 박스 ' + (j.cand || 0) + '종목</b>'
             + '<span title="거래대금은 누적이라 한 번 직전 119거래일 최고를 넘으면 되돌아가지 않습니다">(확정)</span>'
             + ' · 그중 5조건 통과 <b>' + rows.length + '</b>'
             + (j.src_at ? ' · 전종목 스냅샷 ' + esc(String(j.src_at).slice(11, 16)) : '')
             + '</div>';
    if (!rows.length) {
      body.innerHTML = head + '<span class="muted">지금은 5조건을 다 만족하는 종목이 <b>없습니다</b>. '
                     + esc(j.note || '') + '</span>';
      return;
    }
    var h = head + '<div class="tbl-scroll"><table class="pf pos"><thead><tr>'
          + '<th class="center">등급</th><th>종목</th><th class="num">현재가</th>'
          + '<th class="num">등락</th><th class="num">시가→현재</th><th class="num">거래대금(억)</th>'
          + '<th class="num">기존박스 대비</th><th class="center">박스</th></tr></thead><tbody>';
    rows.forEach(function (r) {
      var m = r.meta || {};
      h += '<tr>'
         + '<td class="center"><span class="bxr bxr-g">' + esc(r.grade) + '</span></td>'
         + '<td class="stk"><a class="q-name" href="/stock/index.php?mode=fund&code=' + esc(r.code) + '">'
         +   esc(r.name || r.code) + '</a><span class="code">' + esc(r.code) + '</span></td>'
         + '<td class="num">' + Number(m.close || 0).toLocaleString() + '</td>'
         + '<td class="num">' + pct(m.chg || 0) + '</td>'
         + '<td class="num"><b>' + pct(m.ocPct || 0) + '</b></td>'
         + '<td class="num">' + Math.round((m.amt || 0) / 1e8).toLocaleString() + '</td>'
         + '<td class="num">' + pct(m.gapPct || 0) + '</td>'
         + '<td class="center">' + (1 + Math.min(3, m.boxN || 0)) + '벌</td>'
         + '</tr>';
    });
    h += '</tbody></table></div>'
       + '<div class="q-note" style="margin-top:6px">★ <b>잠정입니다</b> — 고가·저가·현재가가 마감까지'
       + ' 바뀌므로 조건이 뒤집힐 수 있습니다. 확정 후보는 16:20 적재분이며 이 목록은 어디에도 담지 않습니다.</div>';
    body.innerHTML = h;
  }

  function load() {
    body.innerHTML = '<span class="muted">불러오는 중…</span>';
    fetch('/stock/api.php?module=bx&action=live', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); }).then(draw)
      .catch(function () { body.innerHTML = '<span class="muted">불러오지 못했습니다.</span>'; });
  }
  if (btn) btn.addEventListener('click', load);
  load();
})();
</script>
JS;
}

/**
 * 관심차트 동작 — 고르기 · 담기 · 그룹 관리 (2026-08-07)
 *
 * ★그룹 목록의 «단일본»은 서버 응답이다 — 담기·삭제·이름변경이 전부 groups 를 통째로 돌려주고
 *   renderGroups() 가 세 곳(필터·담기 셀렉트·관리 표)을 함께 다시 그린다.
 *   한 곳만 손으로 고치면 새 그룹을 만든 뒤 다른 곳이 옛 목록을 말한다.
 * ★카드가 0건인 화면(빈 그룹)에서도 실어야 한다 — 안 그러면 필터를 되돌릴 길이 없다.
 *   앞서 FL_CARDS · FL_GROUPS · FL_GID 가 심어져 있어야 한다.
 *
 * @param string $src 담을 곳 (`ChartFav::SRCS`) — 'flame' 패턴분석 · 'boxbrk' 오늘의 후보(결과 가림).
 *   ★두 화면이 이 함수를 나눠 쓴다. src 를 밖에서 받는 이유는 <b>그룹이 출처별로 갈려야</b> 하기
 *     때문이다 — 결과를 보고 담은 것과 안 보고 담은 것을 한 그룹에 섞으면 표본이 다시 오염된다.
 */
function pf_flame_fav_js(string $src = 'flame'): void
{
    $srcJs = json_encode($src);
    echo "<script>const FL_SRC={$srcJs};</script>";
    echo <<<'JS'
<script>
(function () {
  var groups = FL_GROUPS.slice();
  var member = FL_MEMBER || {};               // 'code|d' => [그룹 id, …]
  var byKey = {};                             // 'code|d' => 카드 id (배지를 다시 그리려고)
  FL_CARDS.forEach(function (c) { byKey[c.code + '|' + c.sig] = c.id; });

  function esc(s) { return String(s).replace(/[&<>"]/g, function (m) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]; }); }
  function target() { return +(document.getElementById('flGadd') || {}).value || 0; }

  /* ★체크칸 = 「담을 그룹」에 들어 있나. 화면이 기억하지 않고 member 를 다시 읽어 그린다 —
     그래야 담을 그룹을 바꾸면 체크가 그 그룹의 것으로 «갈아 끼워진다». */
  function paintChecks() {
    var g = target(), n = 0, all = document.querySelectorAll('.fl-ck');
    all.forEach(function (el) {
      var k = el.dataset.key;
      var on = g > 0 && (member[k] || []).indexOf(g) >= 0;
      el.checked = on; if (on) n++;
      var card = document.getElementById('flcard_' + byKey[k]);
      if (card) card.classList.toggle('sel', on);
    });
    var chk = document.getElementById('flAll');
    if (chk) chk.checked = (all.length > 0 && n === all.length);
    var lab = document.getElementById('flSelN');
    if (lab) lab.textContent = all.length ? ('이 페이지 ' + all.length + '건 중 ' + n + '건 담김') : '';
  }
  window.flTargetChange = paintChecks;

  /* 담긴 그룹 배지 + member 를 서버가 준 것으로 갈아 준다 */
  function applyMember(m, touched) {
    if (!m) return;
    touched.forEach(function (k) {
      var gs = m[k] || [];
      member[k] = gs.map(function (g) { return +g.id; });
      var box = document.getElementById('fg_' + byKey[k]);
      if (box) box.innerHTML = gs.map(function (g) {
        return '<span class="fl-g">' + esc(g.name) + '</span> '; }).join('');
    });
  }

  /* ★체크하는 순간 저장한다 — 「담기」 버튼이 없다(2026-08-07 사용자 지시).
     실패하면 체크를 «되돌린다» — 화면이 저장된 척하면 안 된다. */
  function save(want, ks, els) {
    if (!ks.length) return;
    var g = target();
    if (!g) {
      els.forEach(function (el) { el.checked = !want; });
      pfToast('먼저 ⚙ 그룹 관리에서 그룹을 만들고 「담을 그룹」을 고르세요.', true);
      return;
    }
    els.forEach(function (el) { el.disabled = true; });
    post(want ? 'add' : 'remove', { fav_id: g, keys: ks.join(',') }).then(function (j) {
      els.forEach(function (el) { el.disabled = false; });
      if (!j.ok) { pfToast(j.message || '저장하지 못했습니다.', true); paintChecks(); return; }
      groups = j.groups; renderGroups(); applyMember(j.member, ks); paintChecks();
      var n = want ? j.added : j.removed;
      pfToast(want ? (n + '건을 담았습니다.')
                   : (n + '건을 뺐습니다.'
                      + (FL_GID === g ? ' 새로고침하면 이 목록에서도 사라집니다.' : '')));
    }).catch(function () {
      els.forEach(function (el) { el.disabled = false; });
      pfToast('저장에 실패했습니다.', true); paintChecks();
    });
  }

  window.flCheck = function (el) { save(el.checked, [el.dataset.key], [el]); };
  window.flCheckAll = function (on) {
    /* ★한 번에 보낸다 — 5건이면 5콜이 아니라 1콜이다 */
    var els = [], ks = [], g = target();
    document.querySelectorAll('.fl-ck').forEach(function (el) {
      var has = g > 0 && (member[el.dataset.key] || []).indexOf(g) >= 0;
      if (has === on) return;                 // 이미 그 상태면 보내지 않는다
      el.checked = on; els.push(el); ks.push(el.dataset.key);
    });
    if (!ks.length) { paintChecks(); return; }
    save(on, ks, els);
  };
  window.flGo = function (v) {
    var u = new URL(location.href);
    if (+v > 0) u.searchParams.set('g', v); else u.searchParams.delete('g');
    u.searchParams.set('p', '1');
    location.href = u.toString();
  };

  function renderGroups() {
    /* 담을 그룹 셀렉트 — 고른 값을 지키며 다시 그린다.
       ★그룹을 «보고 있을 때»는 그 그룹이 기본 대상이다 — 체크를 풀면 보고 있는 그 목록에서 빠진다. */
    var add = document.getElementById('flGadd'), keep = add.value || (FL_GID > 0 ? String(FL_GID) : '');
    add.innerHTML = groups.length
      ? groups.map(function (g) {
          return '<option value="' + g.id + '">' + esc(g.name) + ' (' + g.n + ')</option>'; }).join('')
      : '<option value="0">그룹이 없습니다 — ⚙ 에서 만드세요</option>';
    if (keep && add.querySelector('option[value="' + keep + '"]')) add.value = keep;

    /* 필터 셀렉트 — 지금 보고 있는 그룹은 그대로 둔다 */
    var gf = document.getElementById('flGf');
    if (gf) {
      gf.innerHTML = '<option value="0">전체 (관심차트 아님)</option>'
        + groups.map(function (g) {
            return '<option value="' + g.id + '"' + (g.id == FL_GID ? ' selected' : '') + '>'
                 + esc(g.name) + ' (' + g.n + ')</option>'; }).join('');
    }

    var tb = document.querySelector('#flGmT tbody');
    tb.innerHTML = groups.length
      ? groups.map(function (g) {
          return '<tr data-id="' + g.id + '">'
            + '<td><input type="text" value="' + esc(g.name) + '" maxlength="60"></td>'
            + '<td style="width:70px;text-align:right;color:#8496a6">' + g.n + '건</td>'
            + '<td style="width:150px;text-align:right">'
            + '<button type="button" onclick="flGroupSave(' + g.id + ')">이름 저장</button> '
            + '<button type="button" class="del" onclick="flGroupDel(' + g.id + ',this)">삭제</button>'
            + '</td></tr>'; }).join('')
      : '<tr><td class="muted">아직 그룹이 없습니다.</td></tr>';
  }

  function post(action, extra) {
    var body = new URLSearchParams();
    body.set('module', 'fav'); body.set('action', action); body.set('src', FL_SRC);
    Object.keys(extra || {}).forEach(function (k) { body.set(k, extra[k]); });
    return fetch('/stock/api.php', { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (r) { return r.json(); });
  }

  window.flGmToggle = function () {
    var el = document.getElementById('flGm');
    el.style.display = (el.style.display === 'none') ? 'block' : 'none';
  };

  window.flGroupSave = function (id) {
    var name;
    if (id > 0) {
      var tr = document.querySelector('#flGmT tr[data-id="' + id + '"]');
      name = tr ? tr.querySelector('input').value : '';
    } else {
      name = document.getElementById('flGnew').value;
    }
    if (!String(name).trim()) { pfToast('그룹 이름을 적으세요.', true); return; }
    post('group_save', { id: id, name: name }).then(function (j) {
      if (!j.ok) { pfToast(j.message || '저장하지 못했습니다.', true); return; }
      groups = j.groups; renderGroups();
      if (id === 0) {
        document.getElementById('flGnew').value = '';
        document.getElementById('flGadd').value = j.id;   // 방금 만든 그룹을 담기 대상으로
      }
      pfToast(id > 0 ? '이름을 바꿨습니다.' : '그룹을 만들었습니다.');
    }).catch(function () { pfToast('저장에 실패했습니다.', true); });
  };

  window.flGroupDel = function (id, btn) {
    var tr = btn.closest('tr'), nm = tr ? tr.querySelector('input').value : '';
    if (!confirm('「' + nm + '」 그룹을 지웁니다.\n담아 둔 목록만 사라지고 신호 자체는 그대로입니다.')) return;
    post('group_del', { id: id }).then(function (j) {
      groups = j.groups; renderGroups();
      pfToast('그룹을 지웠습니다.');
      if (FL_GID === id) window.flGo(0);      // 보고 있던 그룹이 사라졌으면 전체로
    }).catch(function () { pfToast('삭제에 실패했습니다.', true); });
  };

  renderGroups();
  paintChecks();
})();
</script>
JS;
}

/* ══════════════════════════════════════════════════════════════════════
 *  퀀트 > 신호분석 — 화면 곳곳의 배지·신호 <b>전체</b>의 기준을 한 자리에.
 *
 *  왜 이 페이지가 필요한가(2026-08-02 사용자): 한 화면에 역배열·유동성·52주최저권(시장상태),
 *  잔여매수·예수금(계획·실행), 어닝쇼크·신호·박스(퀀트·실적)가 <b>섞여 보여</b> 기준이 흐렸다.
 *  → 배지는 서로 다른 네 가지 질문에 답한다는 것을 층으로 정리한다.
 *
 *  ★ 여기의 임계값·실측치는 <b>설명이지 정본이 아니다</b> — 정본은 각 판정 함수
 *    (pf_market_signals · pf_surge_badge · KrxAmt::boxStatusMany/momMany · pf_sue_build ·
 *     pf_liquidity · pf_cycle_alert · pf_stair_alert_map · pf_sue_badge_map).
 *    판정 로직을 바꾸면 이 페이지의 해당 줄도 같이 고친다.
 * ══════════════════════════════════════════════════════════════════════ */
function pf_page_signal(PDO $pdo, Pf $pf): void
{
    // ★2026-08-12 「설정」 하위로 이동(사용자) — 배지 표시 설정이 생기면서 «고르는 자리»가 됐다
    pf_head('설정 · 신호분석', 'setting');
    pf_subtabs('signal', 'setting');
    pf_flash();
    pf_quant_css();   // .qb(퀀트신호)·.bx(최고 거래대금 박스) — 실제 화면과 같은 배지 모양으로 보여 준다
    echo '<style>
.sg-layers{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:10px 0 4px}
@media(max-width:1100px){.sg-layers{grid-template-columns:repeat(2,1fr)}}
@media(max-width:640px){.sg-layers{grid-template-columns:1fr}}
.sg-ly{border:1px solid #dfe6ec;border-radius:10px;padding:10px 12px;background:#fff}
.sg-ly b.t{display:block;font-size:13.5px;margin-bottom:6px;color:#12406b}
/* 층 칸 안에 실제 배지 칩을 그대로 나열한다 — 줄 단위(시장/신호/박스…)로 갭 배치 */
.sg-ly .ex{display:flex;flex-wrap:wrap;gap:4px 5px;align-items:center;font-size:12px;color:#5f7183;line-height:1.6;margin-bottom:7px}
.sg-ly .ex:last-child{margin-bottom:0}
.sg-ly .cap{flex:0 0 100%;font-size:11px;font-weight:700;color:#9aa7b4;letter-spacing:.02em}
.sg-ly .nt{font-size:11.5px;color:#8a97a4;line-height:1.6;margin-top:8px;padding-top:7px;border-top:1px dashed #e6ecf2}
.sg-ly .sig-conf{margin-top:0;cursor:default}
/* 관심종목 트리거·출처 칩과 같은 모양 (그 화면의 .wl-go/.wl-src 사본) */
.wl-go{display:inline-block;padding:2px 9px;border-radius:10px;font-size:12px;font-weight:800;
  background:#e6f4ea;color:#1e7e34;white-space:nowrap}
.wl-src{display:inline-block;padding:0 6px;border-radius:5px;font-size:10.5px;font-weight:700;
  background:#eef3f8;color:#5f7183;vertical-align:middle}
/* 표 안의 견본 칩 — 원래 화면의 margin-top 을 걷어 한 줄에 나란히 */
table.sg .sig-conf{margin-top:0;display:inline-block;cursor:default}
/* 판정 규칙을 한 줄에 하나씩 — 라벨 칸 너비를 고정해 조건이 세로로 맞춰진다 */
.sg-rule{display:flex;gap:10px;align-items:flex-start;padding:5px 0;border-top:1px dashed #eef2f6}
.sg-rule:first-child{border-top:0;padding-top:0}
.sg-rule .sig-conf,.sg-rule .wl-go,.sg-rule .sg-none{flex:0 0 128px;line-height:1.5;text-align:center}
.sg-rule .c{flex:1;min-width:0;line-height:1.9}
.sg-rule .a{display:block;color:#7d8b99;font-size:12px;margin-top:1px;line-height:1.6}
/* 「관망」은 실제 화면에서도 칩이 아니라 옅은 글씨다 — 자리만 맞춘다 */
.sg-none{display:inline-block;padding:2px 9px;font-size:12px;font-weight:600;color:#9aa7b4}
/* 화면별 지도 — 층별 ○·✕ 매트릭스 (①은 a시장/b신호/c박스/d실적 으로 세분) */
table.sg-map td{vertical-align:middle}
table.sg-map th.ctr{text-align:center}
table.sg-map .sg-o{color:#0f8a5f;text-align:center;font-weight:800;font-size:15px;cursor:help;background:#f4fbf7}
table.sg-map .sg-x{color:#cfd8e0;text-align:center;font-weight:700;font-size:14px}
table.sg-map tr:hover td{background:#fbfdff}
table.sg-map tr:hover .sg-o{background:#eaf7f0}
table.sg{border-collapse:collapse;width:100%;margin:8px 0 4px}
table.sg th,table.sg td{border:1px solid #e3eaf0;padding:7px 10px;font-size:12.8px;text-align:left;
  vertical-align:top;line-height:1.65;white-space:normal}
table.sg th{background:#f4f7fa;white-space:nowrap}
table.sg td:first-child{white-space:nowrap}
table.sg .crit{color:#3c4d5e}
table.sg code{background:#f2f6fa;border-radius:4px;padding:1px 4px;font-size:11.5px}
.sg-h2{display:flex;align-items:baseline;gap:8px}
.sg-h2 .w{font-size:12px;color:#9aa7b4;font-weight:600}
.sg-note{font-size:12.5px;color:#7d8b99;line-height:1.7;margin:6px 0 0}
.sg-good{color:#0f8a5f;font-weight:700}.sg-bad{color:#c62828;font-weight:700}
</style>';

    echo '<div class="pf-head"><div><h1>신호분석 설정</h1>'
       . '<div class="sub">화면 곳곳에 뜨는 배지·신호 <b>전체의 판정 기준</b>을 한 자리에 —'
       . ' 탐색 목록의 배지는 아래 <b>배지 표시 설정</b>에서 화면별로 켜고 끕니다.'
       . ' 임계값의 근거 전문은 <a href="/stock/index.php?mode=quantstat">검증 탭</a>,'
       . ' 패턴의 정의·실제 사례는 <a href="/stock/index.php?mode=boxbrk">패턴분석 (박스 상향돌파)</a>에 있습니다.</div></div></div>';

    /* ── 0-0. 배지 표시 설정 — 탐색 목록에 어떤 배지를 그릴까 (2026-08-12 사용자 「종목리스트가 많아
     *   어수선하다 — 배지를 선택해서 보여 주고 싶다」).
     * ★카탈로그·차분 저장의 단일본은 classes/BadgeFeat.class — 이 매트릭스는 그 표를 «그리기만» 한다
     *   (화면별 지도의 하드코딩과 달리, 화면에 배지를 더하면 카탈로그 한 줄로 여기도 함께 바뀐다).
     * ★체크칸이 곧 저장이다(버튼 없음 — 관심차트 담기와 같은 규칙 · 실패하면 체크를 되돌린다).
     * ★끄는 것은 «그리기»뿐 — 판정·정렬·집계는 그대로 돈다. ③행동·④포트폴리오 경보는 아예 목록에 없다. */
    pf_toast_js();
    $bfCat  = BadgeFeat::catalog();
    $bfVals = [];
    foreach (array_keys(BadgeFeat::SCREENS) as $sk) $bfVals[$sk] = BadgeFeat::vals($sk, $pdo);

    echo '<div class="card"><h2>배지 표시 설정 — 탐색 목록에 어떤 배지를 그릴까</h2>'
       . '<div class="sg-note" style="margin-bottom:8px">체크를 바꾸면 <b>즉시 저장</b>되고 그 화면을 새로 열 때 적용됩니다.'
       . ' 끄는 것은 <b>그리기뿐</b> — 판정·정렬·집계는 그대로 돕니다.'
       . ' <b>○</b> = 그 화면의 존재 이유라 끌 수 없음 · <b>✕</b> = 그 화면에 원래 안 뜸.</div>'
       . '<div class="tbl-scroll"><table class="sg sg-map" id="bfTbl"><tr><th>배지</th><th>뜻 · 어디서 오나</th>';
    foreach (BadgeFeat::SCREENS as $sk => $s) {
        echo '<th class="ctr"><a href="' . pf_h($s['url']) . '" title="' . pf_h($s['note']) . '">'
           . pf_h($s['label']) . '</a></th>';
    }
    echo '</tr>';
    foreach ($bfCat['groups'] as $gk => $g) {
        echo '<tr><td colspan="' . (2 + count(BadgeFeat::SCREENS)) . '" style="background:#f4f7fa;font-weight:800;color:#12406b">'
           . pf_h($g['label']) . ' <span class="muted" style="font-weight:600">' . pf_h($g['desc']) . '</span></td></tr>';
        foreach ($bfCat['badges'] as $bk => $b) {
            if ($b['g'] !== $gk) continue;
            echo '<tr><td><b>' . pf_h($b['label']) . '</b></td>'
               . '<td style="font-size:12px">' . pf_h($b['desc'])
               . '<br><span class="muted" style="font-size:11px">' . pf_h($b['src']) . '</span></td>';
            foreach (array_keys(BadgeFeat::SCREENS) as $sk) {
                if (!BadgeFeat::has($sk, $bk)) {
                    echo '<td class="sg-x" title="이 화면에는 뜨지 않습니다">✕</td>';
                } elseif (BadgeFeat::locked($sk, $bk)) {
                    echo '<td class="sg-o" title="끌 수 없습니다 — 이 화면(또는 실수 방지)의 전제입니다">○</td>';
                } else {
                    echo '<td class="ctr" style="text-align:center"><input type="checkbox" class="bf-ck" data-s="'
                       . pf_h($sk) . '" data-b="' . pf_h($bk) . '"'
                       . (!empty($bfVals[$sk][$bk]) ? ' checked' : '') . '></td>';
                }
            }
            echo '</tr>';
        }
    }
    echo '</table></div>';
    // 설정 대상이 아닌 화면 — 지도에서 빠지면 「저긴 왜 없나」가 된다 (ChartFeat::BARELESS 와 같은 자리)
    echo '<details style="margin-top:8px"><summary style="cursor:pointer;font-size:12.5px;color:#5f7183">'
       . '설정 대상이 아닌 화면 ' . count($bfCat['fixed']) . '곳 — 왜 여기 없나</summary>'
       . '<table class="sg" style="margin-top:6px"><tr><th>화면</th><th>붙는 배지</th><th>왜 못 끄나</th></tr>';
    foreach ($bfCat['fixed'] as $f) {
        echo '<tr><td>' . pf_h($f[0]) . '</td><td style="font-size:12px">' . pf_h($f[1])
           . '</td><td style="font-size:12px">' . pf_h($f[2]) . '</td></tr>';
    }
    echo '</table></details></div>';

    echo <<<'JS'
<script>
(function(){
  // 체크 하나 = 그 «화면»의 전 체크칸을 모아 한 번에 저장 — 차분 계산은 서버(BadgeFeat::diff)가 한다
  document.querySelectorAll('.bf-ck').forEach(function(ck){
    ck.addEventListener('change', function(){
      var s = ck.dataset.s, vals = {};
      document.querySelectorAll('.bf-ck[data-s="' + s + '"]').forEach(function(c){
        vals[c.dataset.b] = c.checked ? 1 : 0;
      });
      var fd = new FormData();
      fd.append('screen', s); fd.append('vals', JSON.stringify(vals));
      fetch('/stock/api.php?module=badge&action=save', { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
          if (!d.ok) throw new Error(d.message || '저장 실패');
          pfToast('저장했습니다 — 그 화면을 새로 열면 적용됩니다');
        })
        .catch(function(e){
          ck.checked = !ck.checked;   // 실패하면 체크를 되돌린다 — 화면과 저장이 다른 말을 하면 안 된다
          pfToast('저장 실패: ' + e.message, true);
        });
    });
  });
})();
</script>
JS;

    /* ── 0-1. 용어 정의 — 화면마다 다른 이름으로 부르던 것을 하나로 못 박는다 (2026-08-02 사용자 정의).
     * ★ 여기가 <b>용어의 단일 원천</b>이다. 열 이름·문구를 새로 쓸 때 여기 없는 말을 만들지 않는다. */
    echo '<div class="card"><h2>용어 — 이 사이트에서 이 말은 이 뜻입니다</h2>'
       . '<table class="sg"><tr><th>용어</th><th>뜻</th><th>어디에 쓰나</th></tr>'
       . '<tr><td><b>배지</b></td><td>화면에 뜨는 <b>칩 전부</b>를 가리키는 총칭 — 특정 항목의 이름이 아닙니다.</td>'
       .   '<td>열 이름으로는 <b>쓰지 않습니다</b>(무엇을 담은 열인지 알 수 없으므로). 이 페이지처럼 "배지 전체"를 말할 때만.</td></tr>'
       . '<tr><td><b>퀀트신호</b><br><span class="qb qb-acc">매집형</span></td>'
       .   '<td>거래대금이 <b>120거래일 중 최고</b>인 날 하나의 성격 — <b>매집형 · 중립 · 불꽃형</b> 셋 중 하나입니다.</td>'
       .   '<td>퀀트 목록·관심종목의 <b>「퀀트신호」 열</b> · 단타 목록. 정의는 아래 <b>2-a</b>.</td></tr>'
       . '<tr><td><b>최고 거래대금 박스</b><br><span class="bx bx-lad">계단지지 1단</span></td>'
       .   '<td>그 신호일의 <b>고가 H(저항)·저가 L(지지)</b>가 만드는 박스와, 그 뒤 가격이 걸어간 <b>경로</b>.</td>'
       .   '<td>퀀트 목록·📦추적 카드·관심종목·어닝의 <b>「최고 거래대금 박스」 열</b> — ★박스 상향돌파(5조건) 박스에만 붙습니다(2026-08-10 재정의). 정의는 아래 <b>2-b</b>.</td></tr>'
       . '<tr><td><b>신호일</b></td><td>그 퀀트신호가 발생한 날 (박스가 만들어진 날).</td><td>추적 카드의 「신호일」 열 · 배지 툴팁.</td></tr>'
       . '<tr><td><b>신호</b> <span class="sig-pill k-buy">매수</span></td>'
       .   '<td>퀀트신호와 <b>다릅니다</b> — 룰셋 차수표가 내는 <b>오늘의 행동</b>(매수·잔여매수·매도·대기).</td>'
       .   '<td>현황 카드 · 보유종목의 「신호」 열. 정의는 아래 <b>③</b>.</td></tr>'
       . '<tr><td><b>트리거</b> <span class="wl-go">🟢 돌파확인</span></td>'
       .   '<td>검증된 <b>퀀트 매수규칙</b> 둘을 충족했다는 표시 — 퀀트신호 × 경로의 조합입니다.</td>'
       .   '<td>관심종목의 「트리거」 열. 정의는 아래 <b>2-c</b>.</td></tr>'
       . '<tr><td><b>포트폴리오</b> <span class="mkt t-risk">계단관통↓</span></td>'
       .   '<td><b>이미 산 포지션</b>에서 판단을 다시 소집하는 셋(장기물림·N차 지연·계단관통↓) — 자동 매매는 없습니다.</td>'
       .   '<td>현황·보유종목. 정의는 아래 <b>④</b>.</td></tr></table>'
       . '<div class="sg-note">★ <b>거래량</b>(주수)이 아니라 <b>거래대금</b>(원)이 기준입니다 — 주수로 재면 저가주가 크게 보입니다.'
       . ' 그리고 <b>「신고가」는 가격에만</b> 씁니다 — 거래대금이 최고인 것은 「<b>최고 거래대금</b>」이라고 부릅니다.</div></div>';

    /* ── 0-2. 큰 그림 — 층별 <b>실제 배지 전부</b>를 그대로 그린다 (2026-08-02 사용자: 텍스트 말고 뱃지로).
     * 여기 칩은 견본이라 숫자는 예시값 — 클래스·문구는 실제 화면과 같은 마크업을 쓴다. */
    echo '<div class="card"><h2>왜 여러 배지가 한 화면에 같이 뜨나 — 배지는 네 가지 질문에 답한다</h2>'
       . '<div class="sg-layers">'

       . '<div class="sg-ly"><b class="t">① 상태 — 지금 어떤 자리인가</b>'
       .   '<div class="ex"><span class="cap">시장</span>'
       .     '<span class="mkt t-up">+16.2%</span><span class="mkt t-down">−15.4%</span>'
       .     '<span class="mkt t-info">거래량 3.2배</span><span class="mkt t-info">★ 거래량 3.2배</span>'
       .     '<span class="mkt t-risk">52주 최저권</span><span class="mkt t-sellish">52주 최고권</span>'
       .     '<span class="mkt t-buyish">과매도 RSI 28</span><span class="mkt t-sellish">과매수 RSI 74</span>'
       .     '<span class="mkt t-risk">역배열</span><span class="mkt t-info">정배열</span>'
       .     '<span class="mkt t-info">이격 +12%</span>'
       .     '<span class="mkt t-buyish">4일 연속 하락</span><span class="mkt t-sellish">3일 연속 상승</span><span class="mkt t-up">20일 +112%</span><span class="mkt t-up">40일 +139%</span><span class="mkt t-down">20일 −52%</span></div>'
       .   '<div class="ex"><span class="cap">실적(SUE)</span><span class="sue-b up">SUE +2.1<span class="sue-q">26.1Q</span></span><span class="sue-b dn">SUE -1.4<span class="sue-q">26.1Q</span></span></div>'
       .   '<div class="nt">둘 다 <b>종목 자체의 사실</b>입니다 — 사지 않은 종목에도 그대로 성립합니다.'
       .     ' 시세(시장)와 실적(SUE)은 <b>보는 시간축</b>이 다를 뿐 같은 질문에 답합니다: 지금 어떤 자리인가.</div></div>'

       /* ★ 퀀트는 ① 상태에서 갈라낸 층이다(2026-08-02 사용자) — 시장·실적은 종목의 상태지만
        *   퀀트신호·경로는 <b>한 사건(최고 거래대금 신호일)의 두 면</b>이라 성격이 다르다. */
       . '<div class="sg-ly"><b class="t">② 퀀트 — 그 사건은 무엇이었고, 그 뒤 어떻게 됐나</b>'
       .   '<div class="ex"><span class="cap">a 퀀트신호</span>'
       .     '<span class="qb qb-acc">매집형</span><span class="qb qb-neu">중립</span>'
       .     '<span class="qb qb-flame">불꽃형</span></div>'
       .   '<div class="ex"><span class="cap">b 퀀트 경로</span>'
       .     '<span class="bx bx-new">新박스</span><span class="bx bx-in">박스안 H+3.2%</span>'
       .     '<span class="bx bx-brk">돌파✓ 3일</span><span class="bx bx-fake">가짜돌파</span>'
       .     '<span class="bx bx-fake">돌파후반락</span><span class="bx bx-lad">계단지지 1단</span>'
       .     '<span class="bx bx-lad">2단 시험중</span><span class="bx bx-dn">지지이탈↓</span>'
       .     '<span class="bx bx-dn">붕괴·표류</span><span class="bx bx-dn">붕괴↓</span></div>'
       .   '<div class="ex"><span class="cap">c 퀀트 매수원칙</span>'
       .     '<span class="wl-go">🟢 돌파확인</span><span class="wl-go">🟢 계단지지</span>'
       .     '<span class="muted" style="font-size:12px">관망</span></div>'
       .   '<div class="nt">a 와 b 는 <b>같은 신호일</b>에서 나온 한 덩이입니다 — 그 날의 성격이 <b>신호</b>,'
       .     ' 그 뒤 가격이 걸어간 길이 <b>경로</b>이고, <b>둘이 겹치는 두 자리</b>가 c 매수원칙입니다.<br>'
       .     '<b>⚠ 접미사</b>(<span class="bx bx-in">박스안 24일⚠</span> '
       .     '<span class="bx bx-lad">계단지지 1단⚠</span>)는 <b>경보가 아니라 경로 배지의 강등</b>입니다 — '
       .     '같은 경로로 보여도 <b>매수규칙이 적용되지 않는 부분집합</b>이라는 표시(④와 구별).</div></div>'

       . '<div class="sg-ly"><b class="t">③ 행동 — 오늘 무엇을 하라는 것인가</b>'
       .   '<div class="ex"><span class="cap">룰셋</span>'
       .     '<span class="sig-pill k-sell">매도</span><span class="sig-pill k-buy">매수</span>'
       .     '<span class="sig-pill k-fill">잔여매수</span><span class="sig-pill k-wait">대기</span>'
       .     '<span class="sig-pill k-re">재진입</span></div>'
       .   '<div class="ex"><span class="cap">신뢰도</span>'
       .     '<span class="sig-conf lv-strong">◎ 근거 강함 — 투매</span>'
       .     '<span class="sig-conf lv-caution">△ 주의 — 하락추세</span>'
       .     '<span class="sig-conf lv-caution">△ 분할매도 고려</span></div></div>'

       . '<div class="sg-ly"><b class="t">④ 포트폴리오 — 멈춰서 다시 판단하라</b>'
       .   '<div class="ex"><span class="mkt t-risk">장기물림</span><span class="mkt t-warn">4차 지연</span>'
       .     '<span class="mkt t-risk">계단관통↓</span></div>'
       .   '<div class="nt"><b>이 셋뿐입니다</b> — 셋 다 <b>내가 산 포지션</b>에서만 성립합니다(사지 않은 종목엔 뜻이 없습니다).<br>'
       .     '<span class="sue-b dn">SUE -1.4<span class="sue-q">26.1Q</span></span>(어닝쇼크)·<span class="mkt t-up">20일 +112%</span> 는 여기가 아닙니다 —'
       .     ' 그건 <b>종목 자체의 사실</b>이라 <b>① 상태</b>에 있습니다.</div></div>'
       . '</div>'
       . '<div class="sg-note">예: 「오늘의 신호」 카드 하나에 <b>잔여매수</b>(행동) + <b>역배열</b>(상태)이 같이 뜹니다'
       . ' — 중복이 아니라 <b>층이 다른</b> 것입니다.'
       . ' 읽는 순서도 이 순서입니다: <b>무엇을 할까(행동) → 어떤 자리인가(상태·퀀트) → 멈출 곳인가(포트폴리오)</b>.<br>'
       . '<span class="muted">⊖ 예전에 있던 <b>실행</b> 층(△ 분할 권장 · ⚠ N일 분할 필요)은 2026-08-02 삭제했습니다 —'
       . ' 참여율 임계가 백테스트가 아닌 지정값이었습니다.</span></div></div>';

    /* ── 1. 상태 배지 ── */
    echo '<div class="card"><div class="sg-h2"><h2>① 상태 배지</h2><span class="w">지금 이 종목이 어떤 자리인가 — 시세(시장)와 실적(SUE), 서로 다른 시간축의 두 계열</span></div>'

       . '<h3 style="font-size:13.5px;margin:14px 0 2px;color:#12406b">1-a. 시장지표 배지 — 최근 시세의 이례성 <span style="font-weight:600;color:#9aa7b4">(현황 카드 · 보유종목 · 매매히스토리)</span></h3>'
       . '<div class="sg-note" style="margin:2px 0 6px">임계치를 <b>넘은 것만</b>, 행동에 가까운 순으로 <b>최대 3개</b>만 띄웁니다 — 다 보여주면 아무것도 안 보입니다.'
       . ' 색은 두 갈래입니다 — <b>가격 움직임(%) 칩</b>은 <b>등락색</b>(<span class="mkt t-up">빨강</span> 올랐다 · <span class="mkt t-down">파랑</span> 내렸다), <b>나머지 상태 배지</b>는 <b>의미색</b>(<span class="mkt t-buyish">초록</span> 사는 쪽 유리 · <span class="mkt t-sellish">붉은</span> 파는 쪽 유리 · <span class="mkt t-risk">주황</span> 경고 · <span class="mkt t-info">회색</span> 정보)입니다. %는 방향이 곧 사실이라 등락색이 즉시 읽히고, 나머지는 방향보다 <b>뜻</b>이 먼저이기 때문입니다.</div>'
       . '<table class="sg"><tr><th>시장</th><th>판정 기준</th><th>어떻게 읽나</th></tr>'
       . '<tr><td><span class="mkt t-up">+16.2%</span> <span class="mkt t-down">−15.4%</span>'
       .   '<br><span class="muted" style="font-size:11px">하루</span></td>'
       .   '<td class="crit">평소 변동의 <b>±' . Thr::num(Thr::SIGMA) . 'σ</b> 이상 <b>또는</b> 절대 <b>±' . Thr::pct(Thr::CHG_ABS) . '%</b> 이상 (둘 중 하나면 신호)</td>'
       .   '<td>σ는 평소 변동성으로 정규화한 값, 절대 %는 σ가 커진 장에서의 안전망 — OR 로 묶습니다. 임계를 높게 둬 진짜 이례만 띄웁니다.<br>'
       .   '라벨은 <b>부호 붙은 % 하나</b>입니다 — 「급등/급락」이라는 말을 빼서 아래 20·40거래일 칩과 <b>기간만 다른 같은 계열</b>로 읽히게 했습니다.</td></tr>'
       . '<tr><td><span class="mkt t-up">20일 +112%</span> <span class="mkt t-up">40일 +139%</span><br>'
       .   '<span class="mkt t-down">20일 −52%</span> <span class="mkt t-down">40일 −60%</span></td>'
       .   '<td class="crit"><b>급등</b>(빨강): 20거래일 <b>+80%</b> 이상 · 40거래일 <b>+100%</b> 이상<br>'
       .   '<b>급락</b>(파랑): 20거래일 <b>−30%</b> 이하 · 40거래일 <b>−40%</b> 이하<br>'
       .   '<span class="muted">창마다 따로 뜹니다 — 둘 다 넘으면 칩도 둘</span></td>'
       .   '<td><b>급등은 실측 근거가 있습니다</b> — 이 무리의 돌파 매수는 중앙 0.00%·승률 51.6%로 <b>엣지가 없습니다</b>'
       .   '(평균만 +7%인 복권꼬리 · 검증 탭 ⑧). 금지가 아니라 고지이며 손절 규칙은 바뀌지 않습니다.<br>'
       .   '<b>급락은 근거가 없습니다</b> — 측정한 적이 없는 <b>지정값</b>이라 경고색을 쓰지 않고 회색으로 둡니다.'
       .   ' 임계를 두 번 낮췄습니다(로그대칭 −44/−50 → −30/−50 → <b>−30/−40</b>) — 앞의 둘은 실제 급락을 놓쳤습니다(현대차 40거래일 <b>−44.6%</b>).'
       .   ' 발생률 실측(600종목·2026-07-31): 20일 −30% <b>6.0%</b> · 40일 −40% <b>7.7%</b> — 급등(1.8%·2.2%)보다 3배쯤 잦은데 이는 <b>하락장의 산물</b>이지 대칭이 아닙니다.<br>'
       .   '<b>★ 기준 시점이 둘입니다 — 라벨로 구별하세요.</b><br>'
       .   '<span class="mkt t-up">20일 +90%</span> = <b>오늘 종가</b> 기준 (종목 상세의 「시장」 칸 · 단타 목록)<br>'
       .   '<span class="mkt t-up">신호일 20일 +90%</span> = 그 <b>신호가 난 날</b> 기준 (퀀트 목록 · 관심종목) — '
       .   '「그 신호가 이미 오른 자리에서 났나」를 보는 값이라 <b>지금 값이 아닙니다</b>.<br>'
       .   '<span class="muted">실제로 겪은 함정(2026-08-02): 현대차는 신호일(2026-01-21) 기준 +90.3% 인데 '
       .   '<b>오늘 기준 −19.5%</b> 였습니다 — 신호가 반년 전이면 두 값이 정반대가 됩니다.</span></td></tr>'
       . '<tr><td><span class="mkt t-info">거래량 N배</span></td>'
       .   '<td class="crit">최근 20일 평균 거래량의 <b>' . Thr::num(Thr::VOL_MULT) . '배</b> 이상 (얇은 종목이면 ★ 표시)</td>'
       .   '<td>가격 움직임의 신뢰도. 평소 2천만원어치만 거래되는 종목의 3배는 「없던 관심이 생겼다」는 첫 신호라 ★로 키웁니다.</td></tr>'
       . '<tr><td><span class="mkt t-risk">52주 최저권</span> <span class="mkt t-sellish">52주 최고권</span></td>'
       .   '<td class="crit">(현재가−52주최저) ÷ (52주최고−52주최저) 가 <b>하위 ' . Thr::pct(Thr::POS52_LOW_BADGE) . '%</b> / <b>상위 ' . Thr::pct(Thr::POS52_HIGH_BADGE) . '%</b> <span class="muted">(배지 기준 — 신뢰도는 ' . Thr::pct(Thr::POS52_LOW_CONF) . '%/' . Thr::pct(Thr::POS52_HIGH_CONF) . '% 로 더 넓게 봅니다)</span></td>'
       .   '<td>최저가를 계속 깨는 종목은 「싸진 것」이 아니라 <b>나빠지고 있는 것</b>일 수 있습니다. 역배열과 함께 볼 것.</td></tr>'
       . '<tr><td><span class="mkt t-buyish">과매도 RSI 28</span> <span class="mkt t-sellish">과매수 RSI 74</span></td>'
       .   '<td class="crit">RSI(14) <b>' . Thr::num(Thr::RSI_OVERSOLD) . ' 이하</b> / <b>' . Thr::num(Thr::RSI_OVERBOUGHT) . ' 이상</b></td>'
       .   '<td>단기 되돌림 압력. 매수 신호와 과매도가 겹치면 「근거 강함」으로 승격됩니다(아래 ② 신뢰도).</td></tr>'
       . '<tr><td><span class="mkt t-risk">역배열</span> <span class="mkt t-info">정배열</span></td>'
       .   '<td class="crit">5일선 &lt; 20일선 &lt; 60일선 (역) / 5 &gt; 20 &gt; 60 (정)</td>'
       .   '<td>추세의 방향. 역배열에서의 매수는 역추세 매수라 <b>여러 차수를 한 번에 채우지 말고</b> 한 칸씩이 원칙입니다.</td></tr>'
       . '<tr><td><span class="mkt t-info">이격 +12%</span></td>'
       .   '<td class="crit">20일선에서 <b>±' . Thr::pct(Thr::DISP20) . '%</b> 이상 벌어짐</td>'
       .   '<td>평균회귀 압력 — 많이 벌어진 쪽의 반대 방향 힘이 커집니다.</td></tr>'
       . '<tr><td><span class="mkt t-buyish">4일 연속 하락</span> <span class="mkt t-sellish">3일 연속 상승</span></td>'
       .   '<td class="crit"><b>' . Thr::num(Thr::STREAK) . '일</b> 이상 연속 (첫 낱말만 잘라 읽지 말 것 — 일수가 뜻입니다)</td>'
       .   '<td>단기 과매도/과열 국면 표시.</td></tr></table>'

       . '<h3 style="font-size:13.5px;margin:18px 0 2px;color:#12406b">1-b. <b>실적 (SUE)</b> — 분기 이익 서프라이즈 <span style="font-weight:600;color:#9aa7b4">(재무 스크리너 「SUE」 열 · 어닝 탭 · 관심종목 · 보유종목·현황의 종목명 옆 · 종목 상세 · 재무상세 차트 ▲▼ 마커)</span></h3>'
       . '<table class="sg"><tr><th>SUE</th><th>판정 기준</th><th>어떻게 읽나</th></tr>'
       . '<tr><td><b>SUE 값</b> <span class="muted">2.1 · 26.1Q</span></td><td class="crit">(당분기 영업이익 − 전년동기) ÷ σ(과거 최대 8개 분기 증감) — 당분기는 YTD 뺄셈·연결 우선</td>'
       .   '<td>「예상 밖의 이익 변화가 평소 출렁임의 몇 배인가」. 8년 백테스트에서 5분위 단조 — 효과는 <b>상위 20% + 품질(YTD 영업흑자) + 매출동반</b>에 집중.</td></tr>'
       . '<tr><td><span class="sue-b up">SUE +2.1<span class="sue-q">26.1Q</span></span></td><td class="crit"><b>어닝서프라이즈</b> — 최신 분기 <b>SUE ≥ +' . Thr::num(Thr::SUE_HIT) . '</b> (상위 20% 안팎)</td>'
       .   '<td>공시 후 두 달 <b>상방</b> 드리프트가 실측된 자리 (어닝 탭·사례분석 참조). 재무상세 차트에는 접수일 다음 거래일에 ▲ 마커로도 찍힙니다.</td></tr>'
       . '<tr><td><span class="sue-b dn">SUE -1.4<span class="sue-q">26.1Q</span></span></td><td class="crit"><b>어닝쇼크</b> — 최신 분기 <b>SUE ≤ −' . Thr::num(abs(Thr::SUE_SHOCK)) . '</b></td>'
       .   '<td>8년 중 거의 매년 음수·공시 후 두 달 <b>하방</b> 드리프트 — 회피 목록입니다. 차트에는 ▼ 마커.'
       .   ' ★배지 라벨은 「SUE ±값」으로 줄였습니다(2026-08-12) — 뜻은 색(빨강=서프라이즈·파랑=쇼크)이 말하고, 긴 말은 <b>재무분석 상세</b>에만 남습니다.</td></tr></table>'
       . '<div class="sg-note">★ <b>어닝쇼크는 ④ 포트폴리오 경보가 아닙니다</b> — 포트폴리오 경보 셋은 「내가 산 포지션」의 사정인데'
       . ' SUE 는 <b>그 종목 자체의 사실</b>이라 사지 않은 종목에도 그대로 성립합니다. 그래서 목록에서도 포지션 칸(나이)이 아니라'
       . ' <b>종목명 옆</b>에 붙습니다. ±1 안쪽은 배지를 그리지 않습니다(이례만 배지가 된다).</div>'
       . '</div>';

    /* ── 2. 퀀트 — 신호(그 날) 와 경로(그 뒤). 2026-08-02 사용자가 ① 상태에서 갈라낸 층이다:
     *   시장·실적은 <b>종목의 상태</b>지만, 퀀트신호·경로는 <b>한 사건(최고 거래대금 신호일)</b>의 두 면이다. */
    echo '<div class="card"><div class="sg-h2"><h2>② 퀀트 — 신호와 경로</h2>'
       . '<span class="w">거래대금이 120거래일 중 최고인 날 하나 — 그 날의 성격(신호)과 그 뒤 가격이 걸어간 길(경로)</span></div>'
       . '<h3 style="font-size:13.5px;margin:18px 0 2px;color:#12406b">2-a. <b>퀀트신호</b> — 최고 거래대금 <b>신호일 하루</b>의 성격 <span style="font-weight:600;color:#9aa7b4">(퀀트 목록·관심종목의 「퀀트신호」 열 · 단타 목록)</span></h3>'
       . '<div class="sg-note" style="margin:2px 0 6px">거래대금이 <b>120거래일 중 최고</b>인 날 하나를 20일 평균 대비 배수와 그날 등락률로 분류합니다.'
       . ' 판정은 <b>매집형 · 중립 · 불꽃형</b> 셋 중 하나이고, <b>나쁜 쪽 우선</b>입니다 — 매집형 조건과 불꽃형이 겹치면 불꽃형입니다.'
       . ' <b>셋 다 배지로 그립니다</b>(2026-08-02) — 빈 칸으로 두면 「중립이라고 판정한 것」과 「판정할 신호 이력이 없는 것」이 구별되지 않습니다.</div>'
       /* ★ 「표에는 세 가지가 있는데 화면엔 죄다 중립」이라는 오해를 막는다(2026-08-02 사용자 지적) —
        * 중립이 <b>가장 흔한 판정</b>이라는 사실이 문서에 없었다. 실측을 그대로 싣는다. */
       . '<div class="sg-note" style="margin:2px 0 8px;background:#f7fafc;border:1px solid #e9eff5;'
       . 'border-radius:8px;padding:8px 11px">★ <b>화면에서 「중립」이 자주 보이는 것이 정상입니다.</b>'
       . ' 실측 분포(krx_surge 전 이력 6,940건 · 하한 100억):<br>'
       . '<span class="qb qb-neu">중립</span> <b>45.5%</b> · '
       . '<span class="qb qb-flame">불꽃형</span> <b>46.1%</b> <span class="muted">(옛 폭발형 25.2 + 추격주의 20.9)</span> · '
       . '<span class="qb qb-acc">매집형</span> <b>8.4%</b><br>'
       . '거래대금 신고가의 <b>절반 가까이가 중립</b>이고, 매수 규칙이 걸리는 <b>매집형은 12건 중 1건</b>뿐입니다.'
       . ' 화면에 매집형이 드문 것은 신호가 없어서가 아니라 <b>대부분이 관망·금지 구간</b>이기 때문입니다.</div>'
       . '<table class="sg"><tr><th>퀀트신호</th><th>판정 기준</th><th>실측 (+20일 시장중앙 대비)</th></tr>'
       . '<tr><td><span class="qb qb-acc">매집형</span></td><td class="crit">20일 평균의 <b>' . Thr::num(Thr::ACC_AVGMUL_MAX) . '배 이하</b> ∧ 등락 <b>' . Thr::pct(Thr::ACC_CHG_MIN) . '~+' . Thr::pct(Thr::ACC_CHG_MAX) . '%</b> — 조용히 차오른 최고 거래대금</td>'
       .   '<td><span class="sg-good">+1.74% · 승률 55.2%</span> — 유일하게 견고히 이기는 무리. 단독(등락 조건 없이)은 후반기 부호가 반전해 등락 조건이 필수.</td></tr>'
       . '<tr><td><span class="qb qb-neu">중립</span></td><td class="crit">위도 아래도 아닌 나머지 — 매집형인데 <b>하락하며</b> 찍었거나, 배수는 크지만 폭발 임계엔 못 미친 경우</td>'
       .   '<td>−1.23% · 승률 47.5%(중립×돌파) — <b>동전</b>입니다. 가장 흔한 판정이고 행동은 관망.</td></tr>'
       /* ★ 두 조건이 한 행이다 — 2026-08-02 사용자: 「폭발형과 추격주의는 같은 의미」.
        * 수치를 한 칸에 합치지 않은 이유는 조건별로 잰 것이고 모집단이 겹치기 때문(검증 탭 ③ 주석과 같은 이야기). */
       . '<tr><td><span class="qb qb-flame">불꽃형</span></td>'
       .   '<td class="crit">20일 평균의 <b>' . Thr::num(Thr::FLAME_AVGMUL) . '배 이상</b> 폭발 <b>또는</b> 신호일 등락 <b>+' . Thr::pct(Thr::FLAME_CHG) . '% 이상</b> 폭등'
       .     '<br><span class="muted">둘 중 하나만 걸려도 불꽃형 — 어느 쪽이 걸렸는지는 배지 툴팁에 적힙니다.</span></td>'
       .   '<td><span class="sg-bad">20배↑ −4.24% · 승률 36.7%</span> / <span class="sg-bad">등락 +20%↑ −7.23% · 승률 34.6%</span><br>'
       .     '<b>돌파해도 사지 않습니다</b>(돌파 매수 실측 −5.72% / −7.60%). 특히 폭등형은 돌파율이 67.7%로 가장 높은데'
       .     ' 사면 집니다 — 가장 잘 가는 것처럼 보이는 자리가 가장 지는 자리(마지막 불꽃).</td></tr>'
       . '</table>'
       . '<div class="sg-note">★ <b>불꽃형은 옛 「폭발형」과 「추격주의」를 합친 이름입니다</b>(2026-08-02) —'
       . ' 거래대금이 터지든 주가가 터지든 <b>과열되어 터진 하루</b>라는 성격이 같고 처방도 하나(돌파해도 매수 금지)라'
       . ' 배지를 둘로 나눠 둘 이유가 없었습니다. <b>합친 것은 이름이지 측정이 아닙니다</b> — 위 실측을 조건별로 남긴 것은'
       . ' 두 조건의 모집단이 서로 겹쳐서, 합친 수치는 잰 적이 없기 때문입니다.'
       . ' 두 조건의 돌파 매수 실측은 <a href="/stock/index.php?mode=quantstat">검증 탭 ③·④</a>에 갈라 남겼습니다'
       . ' <span class="muted">(두 조건에 「마지막 불꽃」·「소문난 잔치」 실사례 차트를 달아 두던 옛 패턴분석'
       . ' 화면은 2026-08-10 에 「패턴분석 (박스 상향돌파)」로 흡수·삭제됐습니다).</span></div>'
       . '<div class="sg-note">★ 예전에 이 자리에 있던 <b>급등⚠</b> 는 <b>1-a 시장</b>으로 옮겼습니다(2026-08-02) —'
       . ' 재는 것이 거래대금이 아니라 <b>가격의 최근 움직임</b>이라 시장지표와 같은 계열이고,'
       . ' 하루치 「<span class="mkt t-up">+16.2%</span>」와 <b>기간만 다르기</b> 때문입니다.'
       . ' 지금은 창별로 <span class="mkt t-up">20일 +112%</span> <span class="mkt t-up">40일 +139%</span> 처럼 따로 뜹니다.</div>'

       . '<h3 style="font-size:13.5px;margin:18px 0 2px;color:#12406b">2-b. <b>퀀트 경로</b> — 신호일 <b>이후</b> 가격이 걸어간 길 <span style="font-weight:600;color:#9aa7b4">(= 「최고 거래대금 박스」)</span> <span style="font-weight:600;color:#9aa7b4">(퀀트 목록·📦추적 카드·관심종목·어닝의 「최고 거래대금 박스」 열)</span></h3>'
       . '<div class="sg-note" style="margin:2px 0 6px">신호일 고가 <b>H = 저항</b> · 저가 <b>L = 지지</b>가 박스입니다. 종가가 H를 넘으면 돌파, L을 깨면 붕괴 —'
       . ' 붕괴해도 아래 계단(현재 L 아래 레벨을 주는 최근 박스 2개의 H·L)이 <b>74.9%</b> 받아줍니다. 판정은 전부 <b>종가</b> 기준·계단 터치는 ±2%.</div>'
       . '<div class="sg-note" style="margin:2px 0 8px;background:#f4f8ff;border:1px solid #dbe7f5;border-radius:8px;padding:8px 11px">'
       . '★★<b>2026-08-10 전면 재정의</b> — 아래 경로 배지(新박스·박스안·돌파✓·계단지지…)는 이제'
       . ' <b>「박스 상향돌파」(2-d · 5조건) 박스에만</b> 붙습니다. 그 밖의 최고 거래대금 신호는 경로 칸이'
       . ' <b>-</b> 입니다. 판정 «로직»은 그대로고 <b>대상 박스</b>만 좁힌 것입니다(단일본 boxStatusMany 의 게이트'
       . ' — 퀀트 목록·관심종목·어닝·알림 크론이 한 곳으로 같이 바뀝니다). 당일 박스는 16:20 적재 뒤에 판정됩니다.'
       . ' <b>유일한 예외 = ④ 계단관통↓ 경보</b>(편입 시 기준 박스는 기록·안전장치).</div>'
       . '<table class="sg"><tr><th>최고 거래대금 박스</th><th>뜻</th><th>어떻게 읽나</th></tr>'
       . '<tr><td><span class="bx bx-new">新박스</span></td><td>박스가 오늘 막 생김</td><td>아직 경로 없음 — 돌파/지지 확인 전이므로 관망. 당일 잠정치(고가·저가 미확정)는 배지 없이 빈 칸이며 다음날 13:05 KRX 확정값이 오면 박스가 생깁니다.</td></tr>'
       . '<tr><td><span class="bx bx-in">박스안 H+3.2%</span></td><td>박스 안 체류 중 (숫자 = 저항까지 거리)</td>'
       .   '<td>매집형 박스의 유통기한: 체류 1~3일 돌파율 59.9% → 11일 넘으면 37.5%로 급감. <b>10일 초과 시 N일⚠</b>이 붙고, 그 뒤의 돌파는 사도 실측 −6.68%.</td></tr>'
       . '<tr><td><span class="bx bx-brk">돌파✓</span></td><td>종가가 H를 넘음</td>'
       .   '<td>🟢매집형이면 <b>매수 규칙 ①</b>(+2.26%·57.9%). <b>불꽃형</b>의 돌파는 금지(−5.7~−7.6%).</td></tr>'
       . '<tr><td><span class="bx bx-fake">가짜돌파</span></td><td>돌파 후 <b>5일 내</b> H 아래로 재진입</td><td>돌파 실패 — 재돌파를 기다립니다(SK하이닉스 5/13→5/26 재돌파가 실체였던 사례).</td></tr>'
       . '<tr><td><span class="bx bx-fake">돌파후반락 12일</span></td><td>돌파 후 <b>늦게</b> 반락 (5일 이후)이고'
       .   ' <b>지지선 L 은 아직 지키는 중</b><br>'
       .   '<span class="muted">숫자 = 돌파일로부터 지난 거래일</span></td>'
       .   '<td>가짜돌파와 다릅니다 — +40% 다녀온 성공 사례도 이렇게 읽힐 수 있어 이름을 분리했습니다.'
       .   ' 「<span class="bx bx-brk">돌파✓ 3일</span>」의 숫자와 같은 뜻입니다(돌파 후 경과 거래일).</td></tr>'
       /* ★ 2026-08-02 사용자 지적으로 고친 사각지대 — 문서에도 남긴다 */
       . '<tr><td><span class="bx bx-dn">지지이탈↓</span> <span class="bx bx-dn">붕괴·표류</span>'
       .   ' <span class="bx bx-lad">계단지지 N단⚠</span><br><span class="muted">(돌파 후 이탈)</span></td>'
       .   '<td>돌파했다가 <b>그 뒤 지지선 L 까지 잃은</b> 경우 — 그 날부터 <b>붕괴 경로로 넘어갑니다</b></td>'
       .   '<td>★ 예전에는 한 번 돌파하면 L 을 다시 보지 않아, 지지선을 한참 아래로 뚫고도 「돌파후반락」으로 남았습니다'
       .   '(현대차 실측: L 467,500 대비 −17%인데 돌파후반락 107일). 그 탓에 <b>지지이탈↓ 과 계단관통↓ 경보가 영영 안 뜨는'
       .   ' 사각지대</b>가 있었습니다 — 최근 신호 500종목 중 <b>46건(9.2%)</b>이 이 경로였습니다.<br>'
       .   '판정 순서: ①지금 저항 위면 <b>돌파✓</b> ②아니고 지지선을 잃었으면 <b>붕괴 경로</b> ③둘 다 아니면 <b>반락</b>.<br>'
       .   '<b>단 매수 트리거는 넓히지 않았습니다</b> — 이 경로(돌파→이탈→계단지지)는 경로 연구에서 <b>측정한 적 없는 무리</b>라'
       .   ' 계단지지에 ⚠ 를 붙여 트리거에서 자동으로 빠지게 했습니다. 경보만 살리고 매매 규칙의 전제는 그대로 둡니다.</td></tr>'
       . '<tr><td><span class="bx bx-lad">계단지지 N단</span></td><td>붕괴 후 N번째 계단에서 저가가 닿고 종가가 버팀</td>'
       .   '<td>🟢매집형 ∧ <b>아래층 박스(floors) 3개 이상</b>이면 <b>매수 규칙 ②</b>(+2.40%·58.5%). floors 1~2는 실측 음수(−2.47%·45%)라 <b>⚠가 붙고 관망</b>. 지지가 다시 깨지면 다음 계단으로 내려가며 갱신됩니다.</td></tr>'
       . '<tr><td><span class="bx bx-dn">지지이탈↓</span></td><td>알려진 계단을 <b>전부</b> 종가로 뚫고 내려감</td><td>지지 구조 소멸 — 붕괴의 2.5%뿐인 드문 사건이라 더 무겁게 읽습니다. 보유종목이면 ④의 계단관통↓ 경보로 이어집니다.</td></tr>'
       . '<tr><td><span class="bx bx-dn">붕괴·표류</span></td><td>붕괴했는데 닿을 계단이 없음 (첫 박스 등)</td><td>받아줄 곳이 없는 상태 — 「첫 폭발」(계단 없는 첫 박스)이 이 경로의 전형입니다. 박스 상향돌파도 조건 ①(이전 박스 존재)로 이것을 거릅니다.</td></tr></table>'

       /* ── 2-c. 퀀트 매수원칙 — 2026-08-02 사용자가 ③ 행동에서 옮겨 온 절.
        *   트리거는 룰셋이 내는 「오늘의 행동」이 아니라 <b>2-a × 2-b 의 조합</b>이라 여기가 제자리다. */
       . '<h3 style="font-size:13.5px;margin:18px 0 2px;color:#12406b">2-c. <b>퀀트 매수원칙</b> — <b>2-a × 2-b</b> 가 겹치는 두 자리만 산다 <span style="font-weight:600;color:#9aa7b4">(관심종목의 「트리거」 열)</span></h3>'
       . '<div class="sg-note" style="margin:2px 0 6px">백테스트로 <b>검증된 매수규칙은 둘뿐</b>입니다 — 퀀트신호와 경로의'
       . ' <b>특정 조합</b>에서만 실측 우위가 있었습니다. 그 밖은 전부 관망이고, 매도 규칙도 진입한 규칙마다 다릅니다.'
       . ' 근거 전문은 <a href="/stock/index.php?mode=quantstat">검증 탭 ④~⑥</a>.'
       . ' ★<b>2026-08-10 재정의 이후 트리거는 박스 상향돌파 박스에서만 뜹니다</b>(2-b 게이트) — 위 실측치는'
       . ' 전체 모집단 기준의 기록이라 새 기준에서는 <b>참고치</b>입니다(5조건 부분집합의 트리거 성적은 잰 적 없음).</div>'
       . '<table class="sg"><tr><th>트리거</th><th>조건 (2-a × 2-b)</th><th>실측 · 매도 규칙</th></tr>'
       . '<tr><td><span class="wl-go">🟢 돌파확인</span></td>'
       .   '<td class="crit"><span class="qb qb-acc">매집형</span> × <span class="bx bx-brk">돌파✓</span>'
       .     ' <span class="muted">(종가가 저항 H 위)</span></td>'
       .   '<td>실측 <b>+2.26%</b> · 승률 <b>57.9%</b> — 매도는 <b>한 계단 유예</b>(T① · 시간 규칙 없음).</td></tr>'
       . '<tr><td><span class="wl-go">🟢 계단지지</span></td>'
       .   '<td class="crit"><span class="qb qb-acc">매집형</span> × <span class="bx bx-lad">계단지지 N단</span>'
       .     ' <span class="muted">(⚠ 없음 = 아래층 박스 3개↑)</span></td>'
       .   '<td>실측 <b>+2.40%</b> · 승률 <b>58.5%</b> — 매도는 <b>20거래일 잠금 후 손절선</b>(T②).</td></tr>'
       . '<tr><td><span class="sg-none">관망</span></td>'
       .   '<td class="crit"><b>그 외 전부</b> — <span class="qb qb-neu">중립</span>·<span class="qb qb-flame">불꽃형</span>'
       .     ' / 트리거 전 <span class="qb qb-acc">매집형</span> / <span class="bx bx-lad">계단지지 1단⚠</span>'
       .     ' <span class="bx bx-lad">2단 시험중</span> / <span class="bx bx-dn">지지이탈↓</span></td>'
       .   '<td><b>관망이 비어 보여도 그것이 답입니다.</b> 검증된 두 규칙 밖은 실측 우위가 없습니다.</td></tr></table>'
       . '<div class="sg-note">★ 트리거는 <b>③ 행동의 「신호」와 다릅니다</b> — 신호는 <b>룰셋 차수표</b>가 내는 오늘의 행동이고,'
       . ' 트리거는 <b>퀀트 조합</b>이 「지금이 그 두 자리 중 하나」라고 알리는 것입니다. 그래서 이 절이 ② 퀀트에 있습니다.</div>'

       /* ── 2-d. 박스 상향돌파 — 사용자 정의 패턴 (2026-08-10 신설). 알림까지 쏘는 신호인데
        *   이 레지스트리에 없으면 「어디서 온 알림인가」에 답할 곳이 없다. 정본은 stock/lib/boxbrk.php. */
       . '<h3 style="font-size:13.5px;margin:18px 0 2px;color:#12406b">2-d. <b>박스 상향돌파</b> —'
       . ' 직접 고른 관심차트에서 되맞춘 패턴 <span style="font-weight:600;color:#9aa7b4">'
       . '(패턴분석 화면 · 매일 16:20 적재 · Pushover 알림)</span></h3>'
       . '<table class="sg"><tr><th>무엇</th><th>판정 기준</th><th>어떻게 읽나</th></tr>'
       . '<tr><td><b>후보</b></td>'
       .   '<td class="crit">다섯 조건 — ①이전 100억↑ 박스 존재 ②그 날 박스 신설(직전 119거래일 최고 &amp; 100억↑)'
       .   ' ③기존 박스에 붙음(+10% 이내) ④직전 3개 박스보다 위 ⑤시가→종가 +7%↑'
       .   ' <span class="muted">(판정 순서 그림은 <a href="/stock/index.php?mode=boxbrk">패턴분석</a>의 「패턴 지도」)</span></td>'
       .   '<td>2-a 퀀트신호와 <b>같은 모집단</b>(최고 거래대금 신호)에서 다른 자로 거른 것 — 직접 담으신'
       .   ' 관심차트 그룹을 되맞춘 정의입니다(사전 피처 11축 로지스틱 · AUC 0.750).</td></tr>'
       . '<tr><td><b>등급 A~D</b></td>'
       .   '<td class="crit">닮음 점수(z)의 8년 백분위 — A 상위 5% · B 10% · C 25% · D 그 밖. <b>담는 컷이 아닙니다</b></td>'
       .   '<td>「그 날이었다면 담으셨을 확률」이지 <b>「오를 확률」이 아닙니다</b> — 8년 실측에서 등급 A 도'
       .   ' 승률 45.2%·중앙 −2.63%로 판을 못 뒤집습니다(검증 탭 ⑨).</td></tr>'
       . '<tr><td><b>출처</b></td>'
       .   '<td class="crit">★내가 고름(pick) = 정의 원본 · 자동(auto) = 매일 5조건 판정</td>'
       .   '<td><b>섞어 세지 않습니다</b> — pick 은 결과를 보고 담은 표본(룩어헤드)이라 승률 우위가'
       .   ' 「눈」이 아니라 「결과」에서 왔음이 실측됐습니다. 이 패턴의 정직한 성적은 auto 쪽입니다.</td></tr>'
       . '<tr><td><b>알림</b></td>'
       .   '<td class="crit">매일 16:20 크론 끝에 <b>새로 뜬 등급 B↑</b> 후보만 Pushover · 중복키 = (종목, 날짜)</td>'
       .   '<td>본문 끝의 「※성과 우위 미검증」은 측정 결과입니다 — 8년 재측정(2026-08-10)에서 기준선과'
       .   ' 사실상 같았습니다(비용 뒤 +0.01% vs −0.20% · <a href="/stock/index.php?mode=quantstat">검증 탭 ⑨</a>).'
       .   ' <b>매수 신호가 아니라 판단 소집</b>이라 2-c 매수원칙에 들어가지 않습니다.</td></tr></table>'
       . '</div>';

    /* ── 3. 행동 신호 ── */
    echo '<div class="card"><div class="sg-h2"><h2>③ 행동 신호</h2><span class="w">오늘 무엇을 하라는 것인가 — 상태 배지와 달리 이것만이 매매 지시다</span></div>'
       . '<table class="sg"><tr><th>신호</th><th>누가 계산하나</th><th>판정 기준</th></tr>'
       . '<tr><td><b>매수</b></td><td>룰셋 차수표 (현황 카드·보유종목)</td>'
       .   '<td class="crit">현재가 ≤ 다음 차수 이론가 — 계획 수량·금액은 누적목표(한도 × Σ비중)에서 옵니다.</td></tr>'
       . '<tr><td><b>잔여매수</b></td><td>룰셋 차수표</td>'
       .   '<td class="crit">이미 친 차수인데 <b>누적목표를 덜 채웠고</b> 현재가가 아직 그 차수 이론가 이하 — 카드에 「그 차수 누적목표 미달」로 이유가 적힙니다.</td></tr>'
       . '<tr><td><b>매도</b></td><td>룰셋 차수표</td><td class="crit">현재가 ≥ 탈출가(평균단가 × (1+목표)) — 매도가 항상 맨 위에 정렬됩니다.</td></tr>'
       . '<tr><td><b>대기</b></td><td>룰셋 차수표</td><td class="crit">위 어느 것도 아님 — 아무것도 안 하는 것이 계획입니다.</td></tr>'
       . '<tr><td><b>재진입</b></td><td>청산 기록 (pf_reentry_check)<br><span class="muted">현황 카드·매매히스토리</span></td>'
       .   '<td class="crit">전량 매도한 종목이 <b>대기 ' . PF_SIM_WAIT_DEFAULT . '일 경과 + 현재가 ≤ 청산가 × (1 − 하락요건)</b>.'
       .   ' 하락요건 기본값은 그 종목 <b>룰셋의 2차 하락률</b>입니다 — 계획상 한 차수 아래면 다시 시작할 만하다는 뜻.<br>'
       .   '★ 룰셋이 내는 신호가 아니라 <b>지난 사이클</b>이 내는 신호입니다. 그래서 차수도 수량도 아직 없고,'
       .   ' 카드는 「얼마나 싸졌나」만 말합니다.</td></tr>'
       /* ⊖ 「트리거」 행은 2026-08-02 사용자 지시로 <b>2-c 퀀트 매수원칙</b>으로 옮겼다 —
        *   룰셋이 내는 행동이 아니라 퀀트신호 × 경로의 조합이라 ② 퀀트가 제자리다. */
       . '<tr><td><b>신뢰도 라벨</b></td><td>행동 신호 × 시장 상태<br><span class="muted">(카드·표에 덧붙음)</span></td>'
       .   '<td class="crit">'
       .   '<div class="sg-rule"><span class="sig-conf lv-caution">△ 주의 — 하락추세</span>'
       .     '<span class="c"><b>매수·잔여매수</b> + 역배열 + 52주 최저권'
       .     '<span class="a">→ 계획대로 담되 <b>한 번에 다 채우지 말 것</b>. ★가장 먼저 판정한다 — 과매도라도 이쪽이 이긴다(하락추세 초기 물타기가 이 전략의 가장 큰 위험).</span></span></div>'
       .   '<div class="sg-rule"><span class="sig-conf lv-strong">◎ 근거 강함 — 투매</span>'
       .     '<span class="c"><b>매수·잔여매수</b> + (과매도 ∨ 급락) + 거래량 2배↑'
       .     '<span class="a">→ 팔 사람이 다 팔았을 자리. 계획대로 담을 근거가 강하다.</span></span></div>'
       .   '<div class="sg-rule"><span class="sig-conf lv-strong">◎ 근거 강함</span>'
       .     '<span class="c"><b>매수·잔여매수</b> + (과매도 ∨ 급락)'
       .     '<span class="a">→ 거래량이 안 실린 판. 투매만큼은 아니다.</span></span></div>'
       .   '<div class="sg-rule"><span class="sig-conf lv-caution">△ 분할매도 고려</span>'
       .     '<span class="c"><b>매도</b> + 정배열 + 52주 최고권'
       .     '<span class="a">→ 추세가 살아 있다. 전량 매도하면 남은 추세를 놓칠 수 있다.</span></span></div>'
       .   '<div class="sg-rule"><span class="sig-conf lv-strong">◎ 근거 강함 — 과열</span>'
       .     '<span class="c"><b>매도</b> + (과매수 ∨ 급등)'
       .     '<span class="a">→ 목표 도달과 과열이 겹쳤다. 팔 근거가 강하다.</span></span></div>'
       .   '<div class="sg-note" style="margin-top:7px">★ 여기의 <b>52주 최저·최고권은 하위·상위 10%</b>로, 배지(5%)보다 <b>넓은 기준</b>입니다 —'
       .   ' 그래서 <span class="mkt t-risk">52주 최저권</span> 배지가 안 떴는데도 「주의 — 하락추세」가 붙을 수 있습니다.'
       .   ' 급등락 판정(3σ ∨ 15%)은 배지와 같은 기준입니다.</div>'
       .   '</td></tr></table>'
       . '<div class="sg-note">행동 신호가 상태 배지와 반대로 보일 수 있습니다 — 사다리는 떨어질 때 사는 구조라 매수 신호는 대개'
       . ' 나쁜 상태(급락·역배열)와 같이 옵니다. 그래서 상태가 행동을 <b>막지 않고</b>, 신뢰도 라벨로 <b>속도만 보정</b>합니다.</div>'
       . '<div class="sg-note">★ <b>트리거</b>(🟢 돌파확인 · 🟢 계단지지)는 여기가 아니라 <b>2-c 퀀트 매수원칙</b>에 있습니다 —'
       . ' 룰셋 차수표가 내는 오늘의 행동이 아니라 <b>퀀트신호 × 경로의 조합</b>이기 때문입니다.</div></div>';

    /* ⊖ 「③ 실행 게이트」 카드는 2026-08-02 사용자 지시로 삭제 — 층이 통째로 없어졌다.
     *   판정 함수 pf_fill_gate 도 함께 지웠다(lib/calc.php 그 자리의 주석 참조). */

    /* ── 4. 포트폴리오 ── */
    echo '<div class="card"><div class="sg-h2"><h2>④ 포트폴리오 — 판단 소집</h2>'
       . '<span class="w">내가 산 포지션에서만 성립하는 셋 · 자동 조치 없음 — 기계식 손절·동결은 백테스트에서 수익을 깎았다</span></div>'
       . '<table class="sg"><tr><th>포트폴리오</th><th>판정 기준</th><th>무엇을 다시 판단하나</th></tr>'
       . '<tr><td><span class="mkt t-risk">장기물림</span></td><td class="crit">사이클 <b>' . number_format(PF_CYCLE_WARN_DAYS) . '일 경과</b> 또는 <b>' . PF_CYCLE_WARN_STEP . '차 도달</b>, 먼저 오는 쪽 (보유종목 「나이」 셀)</td>'
       .   '<td>실측(사이클 226개): 물림비율이 4차까지 ≤9% → <b>5차 23% · 6차 42% · 7차 50%</b>로 꺾이고, 2년 넘긴 사이클의 ⅓은 끝내 안 닫혔습니다. 이 종목이 정말 반등형인지(박스권 이력·거래량) 다시 볼 지점.</td></tr>'
       . '<tr><td><span class="mkt t-warn">N차 지연</span></td><td class="crit">마지막 매수 후 룰셋의 차수별 지연일(delay_days)이 지나 그 차수가 만료됨</td>'
       .   '<td>계획이 <b>한 차수 아래로</b> 옮겨졌다는 뜻 — 건너뛴 금액은 누적목표가 흡수합니다. 느린 한 차수 하락은 스킵하고 급락은 면제하는 실측 규칙.</td></tr>'
       . '<tr><td><span class="mkt t-risk">계단관통↓</span></td><td class="crit"><b>편입 시 못박은 기준 박스</b>(pf_position.surge_event_d)에서 알려진 계단이 전부 종가로 뚫림 — 기준 박스가 없는 포지션은 <b>판정하지 않습니다</b>(종목 상세에 「기준 박스 없음」). 2026-08-03 이전에는 「가장 최근 박스」를 봤습니다.</td>'
       .   '<td>사다리의 전제(반등을 받아줄 지지 구조)가 소멸했다는 신호 — 백테스트에서 이 오버레이가 물림을 <b>11.3→5.2%</b>로 줄였습니다(비용 중앙 −0.8%p). 시뮬레이터의 「계단관통 손절」 옵션으로 이 종목에서의 효과를 확인할 수 있습니다.</td></tr>'
       . '</table>'
       . '<div class="sg-note">★ <b>이 셋뿐입니다</b> — 셋 다 <b>내가 산 포지션</b>의 사정이라, 사지 않은 종목에는 뜻이 없습니다.'
       . ' <span class="sue-b dn">SUE -1.4<span class="sue-q">26.1Q</span></span>(어닝쇼크)는 여기가 아니라 <b>①-b 실적</b>입니다(종목 자체의 사실).'
       . ' <b>②</b>의 <span class="bx bx-in">박스안 24일⚠</span> ·'
       . ' <span class="bx bx-lad">계단지지 1단⚠</span> 처럼 <b>⚠ 가 붙은 상태 배지도 여기가 아니라 강등</b>입니다'
       . ' — 「이 상태의 좋은 규칙이 여기엔 적용 안 된다」는 뜻이라 <b>아직 안 산 종목</b>을 거르는 쪽에서 씁니다.</div>'
       . '<div class="sg-note">★ <b>손절 플레이북</b>: 자동 손절은 없지만, <span class="mkt t-risk">계단관통↓</span>(수급 구조 소멸)과'
       . ' <span class="sue-b dn">SUE -1.4<span class="sue-q">26.1Q</span></span>(어닝쇼크·실적 반증)가 <b>겹치는 자리</b>가 손절을 판단하는 자리입니다 — 층은 다르지만 함께 봅니다.'
       . ' 매도 규칙 자체는 검증 탭 ⑤: 돌파 진입 = 한 계단 유예 · 계단지지 진입 = 20거래일 보유 후 손절선.</div></div>';

    /* ── 5. 화면별 지도 — ①상태(a시장/b실적) × ②퀀트(a신호/b경로) 로 세분한 ○·✕ 매트릭스 (2026-08-02 사용자).
     * ★ 표의 O/X 는 <b>코드에서 확인한 것</b>이다(추측 금지 — 지도가 틀리면 지도가 아니다):
     *   매매히스토리엔 시장 배지가 없고(체결 당일 상태는 <b>종목 상세</b>·시뮬레이터에 있다),
     *   박스 열은 어닝 탭엔 있고 스크리너엔 없다. 화면을 고치면 이 표도 같이 고친다. */
    $ox = static function (bool $on, string $tip = ''): string {
        return $on ? '<td class="sg-o" title="' . pf_h($tip) . '">○</td>'
                   : '<td class="sg-x" title="이 화면에는 뜨지 않습니다">✕</td>';
    };
    echo '<div class="card"><h2>화면별 지도 — 어느 화면에 어떤 배지가 뜨나</h2>'
       . '<table class="sg sg-map"><tr>'
       .   '<th rowspan="2">화면</th><th colspan="2" class="ctr">① 상태</th>'
       .   '<th colspan="3" class="ctr">② 퀀트</th>'
       .   '<th rowspan="2" class="ctr">③ 행동</th>'
       .   '<th rowspan="2" class="ctr">④ 포트폴리오</th><th rowspan="2">그 화면의 질문</th></tr>'
       . '<tr><th class="ctr">a 시장</th><th class="ctr">b 실적(SUE)</th>'
       .   '<th class="ctr">a 퀀트신호</th><th class="ctr">b 퀀트 경로</th><th class="ctr">c 트리거</th></tr>'

       . '<tr><td><a href="/stock/index.php">현황 (오늘의 신호)</a></td>'
       .   $ox(true, '역배열 · 급락 −15.4% · 52주 최저권 — 신호 카드마다 최대 3개')
       .   $ox(false) . $ox(false) . $ox(false)
       .   $ox(false)
       .   $ox(true, '매도 · 매수 · 잔여매수 · 대기 + 신뢰도(◎ 근거 강함 / △ 주의) · 재진입(청산한 종목)')
       .   $ox(true, '장기물림 · N차 지연 · 계단관통↓ — 종목 표의 「나이」 칸')
       .   '<td>오늘 실행할 것이 있나</td></tr>'

       . '<tr><td><a href="/stock/index.php?mode=all">보유종목</a></td>'
       .   $ox(true, '역배열 · 과매도 RSI 28 등 — 「시장」 열')
       .   $ox(false) . $ox(false) . $ox(false)
       .   $ox(false)
       .   $ox(true, '매도 · 매수 · 잔여매수 · 대기 + 신뢰도 — 「신호」 열')
       .   $ox(true, '장기물림 · N차 지연 · 계단관통↓ — 포트폴리오 경보가 전부 모이는 화면')
       .   '<td>들고 있는 것들이 지금 어떤가</td></tr>'

       /* ★2026-08-03 (M4): 보유 종목 상세에서 ②a·②b 를 <b>제거</b>했다 — 이미 산 종목을 보는 자리에
        *   오늘의 퀀트 판정이 있으면 편입 판단과 무관한 값이 살아 있는 지시처럼 읽힌다(D8).
        *   그 자리는 「편입 당시」(다시 계산하지 않는 기록)가 대신한다. 표도 같이 고친다. */
       . '<tr><td><a href="/stock/index.php?mode=position&id=1">종목 상세 <span class="muted">(보유)</span></a></td>'
       .   $ox(true, '「시장」 줄의 배지 + 체결 이력의 그 날 시장 상태(산 날 기준으로 다시 계산)')
       .   $ox(true, 'SUE 칸 — 배지(어닝서프라이즈·어닝쇼크) 또는 ±1 안쪽이면 값만')
       .   $ox(false) . $ox(false)
       .   $ox(false)
       .   $ox(true, '신뢰도(◎ 근거 강함 / △ 주의) — 현황 카드와 같은 판정')
       .   $ox(true, '장기물림 · N차 지연 · 계단관통↓ — 「포트폴리오」 칸 (목록과 같은 판정)')
       .   '<td>이 한 종목을 지금 어떻게 할까 <span class="muted">(퀀트는 「편입 당시」 기록으로만)</span></td></tr>'

       . '<tr><td><a href="/stock/index.php?mode=hist">매매히스토리</a></td>'
       .   $ox(false) . $ox(false) . $ox(false) . $ox(false)
       .   $ox(false)
       .   $ox(true, '잘 담았음 / 이르게 팔았음 — 오늘의 지시가 아니라 지난 행동의 채점')
       .   $ox(false)
       .   '<td>그때의 판단이 맞았나 (되짚기)</td></tr>'

       . '<tr><td><a href="/stock/index.php?mode=watch">관심종목</a></td>'
       .   $ox(true, '「시장」 열 — 역배열 · 과매도 RSI · 급락 · 거래량 N배 등 최대 3개(현황·보유종목과 같은 배지)'
                   . ' + 20·40거래일 모멘텀 칩(「20일 +112%」). ★2026-08-04 이전에는 모멘텀 칩뿐이었습니다')
       .   $ox(true, 'SUE 값 (최신 분기) — 어닝 탭과 같은 계산')
       .   $ox(true, '매집형 · 중립 · 불꽃형 — 최근 신호일 기준')
       .   $ox(true, '박스안 H+5.2% · 돌파✓ · 계단지지 N단 · 지지이탈↓ — ★박스 상향돌파(5조건) 박스에 한함(그 밖은 -)')
       .   $ox(true, '🟢 돌파확인 · 🟢 계단지지 — 검증된 매수규칙 둘만. 그 외는 관망. ★박스 상향돌파 박스에서만 뜸(2026-08-10 재정의)')
       .   $ox(false) . $ox(false)
       .   '<td>담아 둔 것 중 오늘 살 자리가 왔나 (관제탑)</td></tr>'

       . '<tr><td><a href="/stock/index.php?mode=quant">퀀트 목록</a></td>'
       .   $ox(true, '20·40거래일 모멘텀 칩(「20일 +112%」)만 — 하루치 지표(역배열·RSI 등)는 pf_daily 가 보유·관심 종목만 담아 여기엔 없습니다')
       .   $ox(false)
       .   $ox(true, '매집형 · 중립 · 불꽃형 — 셋 다 배지로 뜹니다 + 📦박스돌파(5조건 통과 · bx_cand 읽기 —'
                   . ' 16:20 적재라 오늘 것은 마감 뒤 · 「📦 박스 상향돌파만」 칩으로 압축 가능)')
       .   $ox(true, '新박스 · 박스안 · 돌파✓ · 계단지지 N단 · 지지이탈↓ · 붕괴·표류 — ★박스 상향돌파(5조건) 박스에 한함(그 밖은 -)')
       .   $ox(false)
       .   $ox(false) . $ox(false)
       .   '<td>오늘 새로 발견할 것이 있나</td></tr>'

       /* ★2026-08-10 신설 행 — 이 화면의 배지(출처·등급·결과·불꽃형)는 대부분 «자기 층»(2-d)이라
        *   이 표의 열에는 불꽃형 유형 배지 하나만 걸린다. O/X 는 pf_bx_card() 에서 확인한 것. */
       . '<tr><td><a href="/stock/index.php?mode=boxbrk">패턴분석 <span class="muted">(박스 상향돌파)</span></a></td>'
       .   $ox(false) . $ox(false)
       .   $ox(true, '불꽃형 배지만 — 20평비 20배↑ 유형 표시(매집형·중립 배지는 없습니다). 그 밖의 배지'
                   . '(★내가 고름/자동 · 등급 A~D · 익절/손절 · 상한가)는 이 화면 전용 — 2-d 절')
       .   $ox(false) . $ox(false)
       .   $ox(false) . $ox(false)
       .   '<td>내가 고르던 그 패턴이 오늘 또 나왔나 <span class="muted">(등급 = 닮음 · 성과 우위 미검증)</span></td></tr>'

       . '<tr><td><a href="/stock/index.php?mode=fund">재무 스크리너</a></td>'
       .   $ox(false)
       .   $ox(true, 'SUE 열 (≥1 굵게) · 재무상세 차트의 ▲어닝서프라이즈 ▼어닝쇼크 마커')
       .   $ox(false)
       .   $ox(false) . $ox(false)
       .   $ox(false) . $ox(false)
       .   '<td>실적으로 걸러낼 것이 있나</td></tr>'

       . '<tr><td><a href="/stock/index.php?mode=earn">어닝 서프라이즈</a></td>'
       .   $ox(false)
       .   $ox(true, 'SUE · 규칙 충족 여부')
       .   $ox(false)
       .   $ox(true, '박스 열 — 실적으로 고르고 수급(박스)으로 타이밍을 보는 다리. ★박스 상향돌파 박스에 한함(그 밖은 -)')
       .   $ox(false)
       .   $ox(false) . $ox(false)
       .   '<td>공시가 난 것 중 살 만한 게 있나</td></tr>'

       . '<tr><td><a href="/stock/index.php?mode=short">단타 <span class="muted">(목록·둘러보기)</span></a></td>'
       .   $ox(true, '20·40거래일 모멘텀 칩(「20일 +112%」)만 — 하루치 지표(역배열·RSI 등)는 pf_daily 가 보유·관심 종목만 담아 여기엔 없습니다')
       .   $ox(false)
       .   $ox(true, '🟢매집형 · 중립 · 불꽃형 — 오늘 최고 거래대금을 넘긴 종목만(장중 잠정)')
       .   $ox(true, '최근 신호 박스의 현재 상태 — ★박스 상향돌파(5조건) 박스에 한함(그 밖은 칩 없음)')
       .   $ox(false)
       .   $ox(false) . $ox(false)
       .   '<td>오늘 오르는 것 중 구조가 좋은 게 있나</td></tr></table>'
       . '<div class="sg-note"><b>○</b> = 그 층의 배지가 뜬다(칸에 마우스를 올리면 실제로 뜨는 배지) · <b>✕</b> = 안 뜬다.'
       . ' 배지의 생김새와 판정 기준은 위 ①~④ 절에 있습니다.'
       . ' ★탐색 화면(퀀트·관심종목·어닝·단타)의 배지는 맨 위 <b>배지 표시 설정</b>에서 끌 수 있습니다 —'
       . ' 끈 배지는 이 지도가 ○ 여도 그 화면에 안 뜹니다.</div>'
       . '<div class="sg-note">흐름은 왼쪽에서 오른쪽입니다: 퀀트·스크리너에서 <b>발견</b> → ☆로 관심종목에 <b>보관</b> → 트리거가 오면 <b>편입</b> → 현황·보유종목에서 <b>운용</b> → 경보가 판단을 <b>소집</b>.</div></div>';

    /* ── 6. 임계 레지스트리 (M5) — <b>이 화면의 모든 숫자가 나온 곳</b>.
     *
     * ★★ 여기 값은 classes/Thr.class 를 <b>그대로 읽는다</b>. 예전에는 화면마다 임계를 글로 적어 뒀는데,
     *   상수를 고치면 설명이 조용히 거짓이 됐다(문서와 코드가 갈리는 자리). 이제 갈릴 수가 없다.
     * ★★ evidence 를 숨기지 않는다 — <b>assumed 가 measured 만큼 많다</b>는 것이 이 표의 알맹이다.
     *   그 사실을 가리면 「전부 백테스트로 정했다」는 (틀린) 인상을 준다. */
    $thr = Thr::all();
    $cnt = Thr::counts();
    $evTag = static function (string $e): string {
        $m = ['measured'    => ['sg-o',   '실측',   '검증 탭에 표·표본·기간분할이 있습니다'],
              'assumed'     => ['sg-x',   '지정값', '★백테스트 근거가 없습니다 — 사용자가 정한 값입니다'],
              'operational' => ['muted',  '운영',   '판정이 아니라 운영상의 선택(하한·허용오차·표본 하한)']];
        [$c, $t, $tip] = $m[$e] ?? ['muted', $e, ''];
        return '<span class="' . $c . '" title="' . pf_h($tip) . '" style="font-weight:800">' . $t . '</span>';
    };
    echo '<div class="card"><h2>임계 레지스트리 <span class="muted" style="font-size:12px;font-weight:600">'
       . count($thr) . '개 — 이 화면·알림·크론이 보는 <b>단일 정본</b> (classes/Thr.class)</span></h2>';
    echo '<div class="sg-note" style="margin-bottom:8px">실측 <b>' . $cnt['measured'] . '</b> · '
       . '지정값 <b>' . $cnt['assumed'] . '</b> · 운영 <b>' . $cnt['operational'] . '</b> — '
       . '★<b>지정값이 실측만큼 많습니다.</b> 급등락 3σ/15%·RSI·52주·이격·연속일·거래량 배수에는 '
       . '검증 탭에 <b>항목 자체가 없습니다</b>. 「임계는 다 백테스트로 정했다」가 아니라 '
       . '「어떤 것은 재서 정했고 어떤 것은 정해 놓고 쓴다」가 사실입니다.</div>';
    echo '<div class="tbl-scroll"><table class="sg"><tr><th>키</th><th class="ctr">값</th>'
       . '<th class="ctr">근거</th><th>뜻</th></tr>';
    foreach ($thr as $k => $r) {
        $v = is_float($r['v']) && abs($r['v']) >= 1000000
            ? number_format($r['v'] / 100000000, 0) . '억'
            : Thr::num((float)$r['v'], 4);
        echo '<tr><td><code style="font-size:11.5px">' . pf_h($k) . '</code></td>'
           . '<td class="ctr"><b>' . pf_h($v) . '</b> <span class="muted">' . pf_h($r['u']) . '</span></td>'
           . '<td class="ctr">' . $evTag($r['e']) . '</td>'
           . '<td style="font-size:12.5px">' . pf_h($r['n']) . '</td></tr>';
    }
    echo '</table></div>';
    echo '<div class="sg-note">★ 값을 바꾸려면 <code>classes/Thr.class</code> 한 곳만 고칩니다 — '
       . '화면·알림(Pushover)·스냅샷·SQL 이 같은 상수를 봅니다. '
       . '2026-08-03 이전에는 같은 숫자가 최대 <b>여섯 군데</b>에 흩어져 있었고(SUE ±1), '
       . '「같은 값이어야 한다」는 주석만 있었습니다.</div></div>';

    /* ── 7. 공통 원칙 ── */
    echo '<div class="card"><h2>다섯 가지 공통 원칙</h2><div style="font-size:13px;line-height:2">'
       . '1. <b>임계값의 근거는 저마다 다르다</b> — 5배·20배·+20%·floors≥3·10일·2년·5차는 백테스트 측정값이지만'
       . ' (근거 전문은 <a href="/stock/index.php?mode=quantstat">검증 탭</a>), 급등락 3σ/15%·RSI·52주 같은 것은'
       . ' <b>정해 놓고 쓰는 값</b>이다. 어느 쪽인지는 바로 위 <b>임계 레지스트리</b>의 「근거」 칸에 적혀 있다.<br>'
       . '2. <b>이례적인 것만 배지가 된다</b> — 임계를 넘지 않으면 침묵한다. 배지가 없는 것도 정보다'
       . ' (집계 전 박스는 그리지 않는다). ⊖ 실행 게이트(분할 경고 둘)는 근거가 지정값이라 2026-08-02 삭제했다.<br>'
       . '3. <b>나쁜 쪽 우선 판정</b> — 좋은 조건과 나쁜 조건이 겹치면 나쁜 쪽으로 분류한다(매집형+폭등 = 불꽃형).<br>'
       . '4. <b>경보는 소집이지 명령이 아니다</b> — 자동 손절·자동 차단은 백테스트에서 승자까지 잘랐다. 화면은 판단할 <b>지점</b>만 기억해 준다.<br>'
       . '5. <b>층이 다르면 같이 떠도 모순이 아니다</b> — 매수 신호(행동)와 역배열(상태)은 흔히 공존한다. 사다리는 원래 떨어질 때 사는 구조다.'
       . '</div></div>';

    pf_foot();
}

// ══════════════════════════════════════════════════════════════════════
//  단타 (mode=short) — 1분봉 원장을 세 시선으로
// ══════════════════════════════════════════════════════════════════════
/**
 * 화면 구성 (다크 · 장중 관찰 계열)
 *
 *   ┌ 좌: 단타 종목 ┬── 우상: 메인 분봉 (단위·기간을 고른다) ──┐
 *   │  현재가·등락  │                                          │
 *   │  수집 배지    ├── 우하좌: 일봉 ── 우하우: 보조 분봉 ──────┤
 *   └───────────────┴──────────────────────────────────────────┘
 *
 * ★ 보조 분봉의 «단위»는 고르는 것이 아니라 <b>메인보다 한 단계 위</b>다(1분→3분).
 *   그래야 두 패널이 늘 다른 말을 한다 — 같은 단위·같은 기간이면 정보가 겹쳐 자리만 먹는다.
 *   기간도 10거래일 고정이다(보조의 역할이 「맥락」이라서).
 *
 * ★ 판정을 세우지 않는다. 이 화면은 <b>보는 자리</b>다 — 퀀트 배지·승률 같은 것을 여기 얹지 않는다
 *   (관찰 화면이라 그렇다. 판정 기능은 요건 D9 로 미뤄져 있다).
 *
 * ★ 일봉 패널은 <b>기존 경로를 그대로</b> 읽는다(stock_analysis_api.php?action=daily) —
 *   네이버 일봉 + krx_amt 실제 거래대금 병합이 저절로 따라온다. 새 수집 경로를 만들지 않는다.
 *
 * ★ 지표 바(차트틀·차트저장·＋지표)는 <b>패널 셋에 각각</b> 붙고 차트틀 키가 셋 다 다르다:
 *   일봉 'short' · 메인 분봉 'shortmin' · 보조 분봉 'shortsub'.
 *   나눠 쓰지 않는 이유는 <b>단위</b>다 — 같은 min 축이라도 1분과 3분은 「120」이 뜻하는 시간이
 *   세 배 다르다. 기간·높이는 어차피 화면(chart_pref.view_json)이 따로 기억한다.
 */
function pf_page_short(PDO $pdo, Pf $pf): void
{
    $dt = new Dt($pdo);
    $dt->ensureTables();
    $hasKey = (new Kiwoom($pdo))->hasKey();
    $days   = $dt->tradingDays();
    $F      = ChartFeat::vals('short', $pdo);      // 기능 구성 — 꺼진 패널은 DOM 도 fetch 도 없다

    echo '<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8">';
    echo '<title>단타 · 주식 포트폴리오</title>';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.css"/>';
    pf_css();                                       // 상단 메뉴(pf-topbar)를 공유하려면 기본 CSS 가 필요하다

    /* 다크 — 「장중에 보는 화면」의 팔레트다(차트 모듈의 dark 테마·차트설정 갤러리와 같은 값).
     * 색이 다르면 차트와 화면 배경이 서로 겉돌아 눈이 다시 적응해야 한다. */
    echo <<<'CSS'
<style>
:root{
  --bg:#0e1320; --panel:#141b2b; --panel-2:#1b2335; --line:#26304a;
  --ink:#dfe6f2; --ink-dim:#8893ab; --ink-mute:#5b6884;
  --up:#e8493f; --down:#2f7bd6; --accent:#d9a441;
  --mono:'SFMono-Regular',ui-monospace,Consolas,'Roboto Mono',monospace;
}
html,body{height:100%;margin:0;overflow:hidden}
body.dt-dark{background:var(--bg);color:var(--ink);display:flex;flex-direction:column}
/* 헤더의 다크 규칙(body.dt-dark .pf-topbar …)은 stock/lib/topbar.php 에 있다 —
   다크 화면이 늘어나도 그 한 벌을 나눠 쓴다(여기 다시 적지 않는다) */
.mono{font-family:var(--mono);font-variant-numeric:tabular-nums}
.up{color:var(--up)} .down{color:var(--down)} .flat{color:var(--ink-mute)}

#dt-shell{flex:1;display:flex;min-height:0}
/* ── 좌: 종목 리스트 (고정폭) ── */
/* 목록이 3줄이 되면서(2026-08-05 · 배지·시총·회전율) 262px 로는 배지가 잘려 296px 로 넓혔다.
   ★스크롤 없이 보이는 종목은 15~18 → 11~12 개로 줄었다. 상한(Dt::POOL_MAX=20)은 그대로다 —
   담는 값어치는 「보관 창에 쌓아 둘 종목 수」이지 「한눈에 들어오는 수」가 아니다. */
#dt-side{flex:0 0 296px;display:flex;flex-direction:column;min-height:0;
  background:var(--panel);border-right:1px solid var(--line)}
.dt-side-head{flex:0 0 auto;padding:9px 10px;border-bottom:1px solid var(--line);position:relative}
#dtQ{width:100%;padding:7px 10px;background:var(--panel-2);border:1px solid var(--line);
  border-radius:7px;color:var(--ink);font-size:13px;outline:none;font-family:inherit}
#dtQ:focus{border-color:var(--accent)}
#dtSug{display:none;position:absolute;top:calc(100% - 4px);left:10px;right:10px;z-index:60;
  background:var(--panel-2);border:1px solid var(--line);border-radius:8px;
  box-shadow:0 10px 26px rgba(0,0,0,.55);max-height:330px;overflow-y:auto;padding:4px 0}
#dtSug .s{display:flex;justify-content:space-between;gap:8px;padding:7px 11px;cursor:pointer;font-size:13px}
#dtSug .s:hover{background:var(--panel)}
/* .on = 키보드(↓/↑)로 고른 자리 — 왼쪽 종목 목록의 «고른 행»(.dt-it.on)과 같은 표시를 쓴다 */
#dtSug .s.on{background:#1d2740;box-shadow:inset 3px 0 0 var(--accent)}
#dtSug .s .c{color:var(--ink-mute);font-size:11px;font-family:var(--mono)}
#dtList{flex:1;overflow-y:auto;min-height:0}
.dt-it{display:grid;grid-template-columns:1fr auto;gap:2px 8px;padding:8px 10px;cursor:pointer;
  border-bottom:1px solid rgba(38,48,74,.55);position:relative}
.dt-it:hover{background:var(--panel-2)}
/* 보유 종목 — 은은한 초록 바탕(2026-08-26 · 한 목록으로 합치며 배경으로 가른다).
   초록은 「보유」 배지(.dt-bdg.held)와 같은 계열이라 배지·바탕이 한 말을 한다.
   ★.on(고른 행)이 이겨야 하므로 이 규칙은 .on «앞»에 둔다. */
.dt-it.hld{background:rgba(64,178,104,.26)}
.dt-it.hld:hover{background:rgba(64,178,104,.36)}
.dt-it.on{background:#1d2740;box-shadow:inset 3px 0 0 var(--accent)}
.dt-it.drag{opacity:.45}
/* 이름 줄은 flex — 이름만 줄이고 배지(ETF 편입 수·수집 상태)는 끝까지 남긴다.
   통째로 ellipsis 를 걸면 긴 이름의 종목에서 배지가 통째로 사라진다. */
.dt-it .nm{display:flex;align-items:center;min-width:0;font-size:13.5px;font-weight:700;letter-spacing:-.01em}
.dt-it .nm .t{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
.dt-it .nm .etfb,.dt-it .nm .dt-bdg{flex:0 0 auto}
.dt-it .px{font-size:13px;text-align:right;font-family:var(--mono);font-weight:700}
/* 둘째 줄 — 코드 + SUE 칩(2026-08-20 · 종목명 옆에서 내림). 넘치면 잘린다(전문은 툴팁) —
   줄을 바꾸면 종목마다 행 높이가 달라진다(.sg 와 같은 규칙). */
.dt-it .cd{font-size:11px;color:var(--ink-mute);font-family:var(--mono);
  min-width:0;overflow:hidden;white-space:nowrap}
.dt-it .rt{font-size:11.5px;text-align:right;font-family:var(--mono)}
/* 셋째 줄 — 퀀트 배지(왼쪽) + 시총·회전율(오른쪽). 왼쪽 「상위 종목」 목록과 같은 정보다.
   배지는 넘치면 잘린다(title 에 전문이 있다) — 줄을 바꾸면 종목마다 행 높이가 달라진다. */
.dt-it .sg{min-width:0;overflow:hidden;white-space:nowrap;line-height:1.6}
.dt-it .mk{font-size:10.5px;text-align:right;color:var(--ink-mute);font-family:var(--mono);
  white-space:nowrap;align-self:center}
.dt-it .x{position:absolute;right:6px;top:6px;display:none;border:0;background:transparent;
  color:var(--ink-mute);cursor:pointer;font-size:14px;line-height:1;padding:2px 4px}
.dt-it:hover .x{display:block}
.dt-it .x:hover{color:var(--up)}
.dt-bdg{display:inline-block;margin-left:5px;padding:0 5px;border-radius:4px;font-size:10px;font-weight:800;
  vertical-align:1px;border:1px solid}
.dt-bdg.wait{color:var(--accent);border-color:#5a4a22;background:#241d10}
.dt-bdg.etf{color:#7fb1e8;border-color:#33507a;background:#243250;letter-spacing:.03em}
.dt-bdg.prev{color:#93a4c3;border-color:#2c3a55;background:#1e2637}
.dt-bdg.hole{color:#e0a44a;border-color:#5a4a22;background:#241d10}
.dt-bdg.fail{color:#ff8a80;border-color:#5c2a26;background:#2a1412}
/* 보유 배지 — 사이트 공통 어휘 그대로다: 「보유」=돈이 들어감 · 「편입됨」=자리만 있음.
   초록은 상위 목록의 «담긴 종목»(.ad.has)과 같은 값을 쓴다 — 두 목록이 같은 색으로 말한다. */
.dt-bdg.held{color:#5dd58a;border-color:#1e5c3a;background:#12341f;text-decoration:none}
.dt-bdg.slot{color:#93a4c3;border-color:#2c3a55;background:#1e2637;text-decoration:none}
a.dt-bdg:hover{border-color:var(--accent);color:var(--accent)}

.dt-side-foot{flex:0 0 auto;padding:7px 10px;border-top:1px solid var(--line);
  font-size:11px;color:var(--ink-mute);line-height:1.6}
.dt-side-foot b{color:var(--ink-dim)}

/* ── 맨 왼쪽: 관심종목 + 시총 (탐색 · 2026-08-05 · 2026-08-20 상승률→관심 교체) ──
   소스는 stock_analysis_api.php — 관심 action=watch(pf_watchlist) · 시총 action=top30.
   새 조회 경로를 만들지 않는다(watch 도 top30 과 같은 행 모양·같은 배지 단일본).
   여기서 «담고» 오른쪽 내 목록에서 «본다» — 왼쪽에서 오른쪽으로 탐색 → 편입 → 관찰. */
#dt-top{flex:0 0 302px;display:flex;flex-direction:column;min-height:0;
  background:var(--panel);border-right:1px solid var(--line)}

/* ── 옆 패널 접기 손잡이 (2026-08-05) ──
   패널 오른쪽 가장자리의 탭을 누르면 그 패널이 13px «레일»로 접힌다.
   ★display:none 으로 지우지 않는 이유 — 통째로 사라지면 어디를 눌러 되돌리는지 알 수 없다.
   ★도구모음의 칩과 «같은 함수»를 부른다 — 둘 중 하나만 상태를 바꾸면 다른 하나가 거짓말을 한다.
   ★접히는 것은 «곁 패널 둘»(상위 종목·뉴스)뿐이다 — 단타 종목 목록은 이 화면의 주인공이라 늘 보인다. */
#dt-top,#dt-news{position:relative;transition:flex-basis .12s ease}
#dt-top.col,#dt-news.col{flex-basis:13px;min-width:13px;overflow:hidden}
#dt-top.col > :not(.dt-hnd),#dt-news.col > :not(.dt-hnd){display:none}
.dt-hnd{position:absolute;right:0;top:50%;transform:translateY(-50%);z-index:5;
  width:13px;height:52px;padding:0;border:1px solid var(--line);border-right:0;
  border-radius:5px 0 0 5px;background:var(--panel-2);color:var(--ink-mute);
  font-size:9px;line-height:1;cursor:pointer;font-family:inherit}
.dt-hnd:hover{background:#26314c;color:var(--accent)}
.col > .dt-hnd{top:0;bottom:0;height:auto;transform:none;border:0;border-radius:0}
.dt-top-head{flex:0 0 auto;display:flex;align-items:center;gap:7px;padding:7px 9px;
  border-bottom:1px solid var(--line);flex-wrap:wrap;row-gap:5px}
.dt-top-head .sub{font-size:11px;color:var(--ink-mute);font-family:var(--mono)}
.dt-top-head .pg{margin-left:auto;display:flex;gap:5px}
#dtTopList{flex:1;overflow-y:auto;min-height:0}
/* 자리를 못박는다 — 자동 배치에 맡기면 「담기」 버튼이 두 줄을 걸치는 순간 순서가 흔들린다 */
.dt-tr{display:grid;grid-template-columns:1fr auto 24px;gap:2px 7px;padding:7px 9px;
  border-bottom:1px solid rgba(38,48,74,.55);cursor:pointer}
.dt-tr:hover{background:var(--panel-2)}
.dt-tr.on{background:#1d2740;box-shadow:inset 3px 0 0 var(--accent)}
.dt-tr .n1{grid-area:1/1;display:flex;align-items:center;min-width:0;
  font-size:13px;font-weight:700;letter-spacing:-.01em}
.dt-tr .n1 .rk{flex:0 0 auto;width:20px;margin-right:5px;text-align:right;
  color:var(--ink-mute);font-size:11px;font-family:var(--mono);font-weight:600}
.dt-tr .n1 .t{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
.dt-tr .n1 .etfb,.dt-tr .n1 .dt-bdg{flex:0 0 auto}
.dt-tr .v1{grid-area:1/2;text-align:right;font-size:12.5px;font-weight:700;
  font-family:var(--mono);white-space:nowrap}
.dt-tr .n2{grid-area:2/1;min-width:0;overflow:hidden;white-space:nowrap;line-height:1.6;
  font-size:11px;color:var(--ink-mute);font-family:var(--mono)}
.dt-tr .v2{grid-area:2/2;text-align:right;font-size:10.5px;color:var(--ink-mute);
  font-family:var(--mono);white-space:nowrap;align-self:center}
/* SUE 칩은 «코드 옆(둘째 줄)»이다(2026-08-20 사용자 — 종목명 옆에 두니 배지가 이름을 통째로
   밀어냈다: SUE+💎175 가 들어오면 302px 에서 이름 몫이 0. 08-12 의 「종목명 바로 옆」을 뒤집음).
   이름 줄은 이름·ETF·상태 배지만 갖는다 — 이름(.t)이 ellipsis 로 양보하는 규칙은 그대로다. */
.dt-tr .ad{grid-area:1/3/3/4;align-self:center;width:24px;height:24px;border-radius:6px;
  border:1px solid var(--line);background:var(--panel-2);color:var(--ink-dim);
  font-size:13px;font-weight:800;line-height:1;padding:0;cursor:pointer;font-family:inherit}
.dt-tr .ad:hover:not(:disabled){border-color:var(--accent);color:var(--accent)}
.dt-tr .ad:disabled{cursor:default}
.dt-tr .ad.has{border-color:#1e5c3a;background:#12341f;color:#5dd58a}
.dt-top-msg{padding:16px 11px;color:var(--ink-mute);font-size:11.5px;line-height:1.7}

/* ── 가운데: 뉴스 (2026-08-05) ──
   소스는 stock_analysis_api.php?module=stock&action=news — 새 수집 경로를 만들지 않는다.
   자리는 «종목 목록과 차트 사이»다 — 고르고 → 왜 움직였나 읽고 → 차트로, 눈이 한 방향으로 간다.
   ★폭을 고정한다 — 뉴스 제목은 길이가 제각각이라 유동폭이면 종목을 바꿀 때마다 차트가 들썩인다. */
#dt-news{flex:0 0 292px;display:flex;flex-direction:column;min-height:0;
  background:var(--panel);border-right:1px solid var(--line)}
.dt-news-head{flex:0 0 auto;display:flex;align-items:baseline;gap:8px;padding:7px 11px;
  border-bottom:1px solid var(--line)}
.dt-news-head b{font-size:13px;font-weight:800;letter-spacing:-.01em}
.dt-news-head span{font-size:11.5px;color:var(--ink-mute);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#dtNewsList{flex:1;overflow-y:auto;min-height:0;padding:2px 0}
.dt-news{display:block;padding:8px 11px;border-bottom:1px solid rgba(38,48,74,.5);
  color:var(--ink);text-decoration:none;cursor:pointer}
.dt-news:hover{background:var(--panel-2)}
.dt-news .t{font-size:12.5px;line-height:1.45;display:-webkit-box;-webkit-line-clamp:3;
  -webkit-box-orient:vertical;overflow:hidden}
.dt-news .d{font-size:11px;color:var(--ink-mute);margin-top:3px;font-family:var(--mono)}
.dt-news-msg{padding:16px 12px;color:var(--ink-mute);font-size:11.5px;line-height:1.7}

/* ── 우: 차트 3분할 ── */
#dt-main{flex:1;display:grid;grid-template-rows:55% 45%;min-width:0;min-height:0}
.dt-bot{display:grid;grid-template-columns:1fr 1fr;min-height:0;border-top:1px solid var(--line)}
.dt-bot .dt-panel + .dt-panel{border-left:1px solid var(--line)}
.dt-bot.one{grid-template-columns:1fr}
.dt-panel{display:flex;flex-direction:column;min-width:0;min-height:0}
.dt-bar{flex:0 0 auto;display:flex;align-items:center;gap:9px;padding:6px 11px;
  background:var(--panel);border-bottom:1px solid var(--line);white-space:nowrap;overflow-x:auto}
.dt-bar::-webkit-scrollbar{height:4px}
/* 지표 바가 붙는 패널은 도구모음이 길다 — 가로로 숨기지 말고 줄을 바꾼다.
   가로 스크롤이면 ＋지표·차트저장이 늘 화면 밖에 있어 손이 두 번 간다.
   ★보조 분봉(#dtSubBar)은 폭이 절반이라 더 그렇다. */
#dtDayBar,#dtMainBar,#dtSubBar{flex-wrap:wrap;white-space:normal;overflow-x:visible;row-gap:5px}
.dt-unit{font-size:11.5px;font-weight:800;color:var(--accent);letter-spacing:-.01em}
.dt-chip{background:var(--panel-2);color:var(--ink-mute);border:1px solid var(--line);border-radius:6px;
  padding:2px 9px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit}
.dt-chip:hover{color:var(--ink)}
.dt-chip.on{background:var(--accent);color:#0b1020;border-color:var(--accent)}
.dt-chip:disabled{opacity:.4;cursor:not-allowed}
.dt-t{font-size:13.5px;font-weight:800;letter-spacing:-.01em}
.dt-t .c{color:var(--ink-mute);font-family:var(--mono);font-size:11.5px;font-weight:600;margin-left:5px}
.dt-note{font-size:11.5px;color:var(--ink-mute);margin-left:auto;font-family:var(--mono)}
.dt-seg{display:inline-flex;border:1px solid var(--line);border-radius:7px;overflow:hidden}
.dt-seg button{background:var(--panel-2);color:var(--ink-mute);border:0;font-size:12px;font-weight:700;
  padding:4px 10px;cursor:pointer;font-family:inherit}
.dt-seg button + button{border-left:1px solid var(--line)}
.dt-seg button:hover:not(.on){color:var(--ink)}
.dt-seg button.on{background:var(--accent);color:#0b1020}
.dt-seg button:disabled{opacity:.35;cursor:not-allowed}
.dt-live{font-size:10.5px;font-weight:800;letter-spacing:.05em;padding:2px 7px;border-radius:5px;
  border:1px solid #2c4a33;color:#5fd08a;background:#122318;cursor:pointer;user-select:none}
.dt-live.off{border-color:var(--line);color:var(--ink-mute);background:var(--panel-2)}
.dt-host{flex:1;min-height:0}
.dt-empty{display:flex;align-items:center;justify-content:center;height:100%;
  color:var(--ink-mute);font-size:13px;text-align:center;line-height:1.8;padding:16px}
#dtToast{position:fixed;left:50%;bottom:26px;transform:translateX(-50%);z-index:90;
  background:#1d2740;border:1px solid var(--line);color:var(--ink);font-size:12.5px;
  padding:9px 16px;border-radius:9px;box-shadow:0 10px 26px rgba(0,0,0,.5);display:none}
</style>
CSS;

    echo '</head><body class="dt-dark">';
    pf_topbar('short');

    /* ── 맨 왼쪽: 관심종목 + 시총 (탐색) ─────────────────────────────
     * 기본은 관심종목(action=watch — 「상승률은 거의 안 쓴다」 2026-08-20 사용자 지시로 교체).
     * 시총 탐색은 기존 경로 그대로(action=top30). 담기 버튼 하나로 오른쪽 내 목록에 들어간다.
     * ★페이징(처음/다음)은 시총에서만 보인다 — 관심종목은 상승률 순으로 전부 온다. */
    echo '<div id="dt-shell"><aside id="dt-top">';
    echo '<div class="dt-top-head">'
       . '<span class="dt-seg" id="dtTopSort"><button type="button" data-s="watch" class="on" '
       . 'title="관심종목 (포트폴리오 > 관심종목과 같은 목록)">관심</button>'
       . '<button type="button" data-s="cap">시총</button></span>'
       . '<span class="sub" id="dtTopSub">—</span>'
       . '<span class="pg" id="dtTopPg" style="display:none">'
       . '<button type="button" class="dt-chip" id="dtTopFirst" disabled>처음</button>'
       . '<button type="button" class="dt-chip" id="dtTopNext">다음</button></span></div>';
    echo '<div id="dtTopList"><div class="dt-top-msg">불러오는 중…</div></div>';
    echo '<button type="button" class="dt-hnd" id="dtTopHnd" title="「관심종목」 패널 접기/펴기">◀</button>';
    echo '</aside>';

    /* ── 좌: 종목 리스트 (한 목록 · 등락률순 — 2026-08-26 구획을 다시 걷어냄) ───────────
     * 사용자: 「단타든 보유든 어떤 종목이 올랐나·내렸나가 중요하다」 — 단타+보유를 «한 목록»으로
     * 합쳐 등락률순으로 세우고, 보유 종목은 «초록 바탕»(.dt-it.hld)과 「보유」 배지로만 가른다.
     * 「보유」는 살아있는 포지션(pf_position status<>'closed')을 종목 단위로 접은 목록이다.
     * ★dt_pool 에 «담지» 않는다 — 그 표는 상한 20 을 FIFO 로 지우는 표라, 보유가 섞이면
     *   탐색하다 「＋」 한 번 누른 것이 내가 산 종목의 봉을 통째로 지운다(Dt::targetCodes 주석).
     * ★두 출처가 «원장 하나»(dt_min)를 나눠 본다 — 겹치는 종목도 봉은 한 벌뿐이라 따로 놀 수 없다. */
    echo '<aside id="dt-side">';
    echo '<div class="dt-side-head">';
    echo '<input id="dtQ" placeholder="종목명·코드로 추가 (Enter)" autocomplete="off">';
    echo '<div id="dtSug"></div></div>';
    echo '<div id="dtList"><div class="dt-empty">불러오는 중…</div></div>';
    echo '<div class="dt-side-foot" id="dtFoot">보관 창 <b id="dtWin">—</b><br>'
       . '<span id="dtFootMsg">'
       . ($hasKey ? '키움 연결됨 · 등록하면 10거래일을 받아 옵니다'
                  : '<span style="color:#e0a44a">키움 키 없음(env/kiwoom.inc) — 오늘 분봉만 보입니다</span>')
       . '</span></div></aside>';

    /* ── 가운데: 뉴스 ────────────────────────────────────────────────
     * 종목 목록과 차트 «사이»에 둔다(2026-08-05 사용자 지시) — 종목을 고르고 나서
     * 「왜 움직였나」를 먼저 읽고 차트로 넘어가는 순서라, 눈이 왼쪽에서 오른쪽으로 그대로 간다.
     * 소스는 기존 경로 그대로 — 새 수집 경로를 만들지 않는다
     * (일봉 패널이 기존 action=daily 를 그대로 읽는 것과 같은 규칙). */
    echo '<aside id="dt-news">'
       . '<div class="dt-news-head"><b>뉴스</b><span id="dtNewsSub"></span></div>'
       . '<div id="dtNewsList"><div class="dt-news-msg">왼쪽에서 종목을 고르면<br>관련 뉴스를 불러옵니다.</div></div>'
       . '<button type="button" class="dt-hnd" id="dtNewsHnd" title="「뉴스」 패널 접기/펴기">◀</button>'
       . '</aside>';

    // ── 우: 차트 ─────────────────────────────────────────────────────
    echo '<main id="dt-main">';
    echo '<section class="dt-panel">';
    /* 메인은 <b>1분봉 전용</b>이다(2026-08-04). 단위 선택을 없앤 이유 — 세부를 보는 자리라
     * 1분이 아니면 이 패널의 값어치가 없고, 굵은 단위는 보조 패널이 이미 맡는다.
     * 데이터는 보관 창 전부(10거래일)를 <b>한 번에</b> 싣고(HTS 방식) 창만 움직인다 —
     * [당일]=300봉 · [5일]=최근 5거래일 · [전체]=실린 전부 · 그 사이는 휠로. */
    echo '<div class="dt-bar" id="dtMainBar">';
    echo '<span class="dt-t" id="dtTitle">단타<span class="c" id="dtCode"></span></span>';
    echo '<span class="dt-unit">1분</span>';
    if ($F['overlay.intraday_ref']) {
        /* 기준선은 «직전고가» 하나다(2026-08-05 사용자 지시로 당일전고·기간고가를 걷어냄).
         * 물음이 「오늘 저 선을 뚫었나」라서 오늘 봉은 계산에서 뺀다 — 넣으면 장중 신고가를
         * 낼 때마다 선이 따라 올라가 영영 안 뚫린다(그게 당일전고가 답하지 못하던 물음이다). */
        echo '<button type="button" id="dtMPV" class="dt-chip on"'
           . ' title="직전고가 — 오늘을 뺀 직전 거래일까지의 최고가(보관 창 10거래일) 점선.'
           . ' 켜면 가격축이 그 값까지 넓어집니다">직전고가</button>'
           . '<button type="button" id="dtMCP" class="dt-chip" title="현재가격선 표시/숨김">현재가</button>';
    }
    echo '<span class="dt-seg" id="dtSpan"><button type="button" data-m="today" class="on">당일</button>'
       . '<button type="button" data-m="5">5일</button>'
       . '<button type="button" data-m="all">전체</button></span>';
    /* 미리보기 중에만 뜨는 담기 버튼 — 「＋」는 상위 목록에도 있지만, 차트를 보다가
     * 「이건 계속 봐야겠다」고 마음먹는 자리는 여기다. 손이 목록으로 돌아가지 않게 한다. */
    echo '<button type="button" id="dtAdd" class="dt-chip" style="display:none"'
       . ' title="단타에 담기 — 10거래일 1분봉을 받아 원장에 쌓습니다">＋ 담기</button>';
    if ($F['chart.live']) {
        /* ★주기를 손으로 적지 않는다 — Dt::TICK_SEC 이 화면 타이머 둘·서버 신선도 창·이 글자를
         *   함께 정한다. 넷이 갈리면 한 화면이 두 값을 말한다(2026-08-06 그래서 묶었다). */
        echo '<span class="dt-live" id="dtLive" title="장중(09:00~15:30)에만 ' . Dt::TICK_SEC
           . '초마다 당일 분봉을 덧대고 목록 시세를 새로 받습니다 (키움 ka10095 · 실패 시 네이버)">LIVE '
           . Dt::TICK_SEC . '초</span>';
    }
    // 옆 패널 접기 — 차트 폭이 아까운 화면이라 둘 다 끌 수 있어야 한다(기본은 펴짐)
    echo '<button type="button" id="dtTopTgl" class="dt-chip on" title="맨 왼쪽 「관심종목」 패널 접기/펴기">관심</button>';
    echo '<button type="button" id="dtNewsTgl" class="dt-chip on" title="「뉴스」 패널 접기/펴기">뉴스</button>';
    echo '<span id="dtMainIBar"></span>';
    if ($F['legend.values']) echo '<span id="dtMainLeg" class="dc-leg-dark"></span>';
    echo '<span class="dt-note" id="dtNote"></span></div>';
    echo '<div class="dt-host" id="dtMain"></div></section>';

    $bot = ($F['panel.short_daily'] ? 1 : 0) + ($F['panel.short_sub'] ? 1 : 0);
    if ($bot) {
        echo '<section class="dt-bot' . ($bot === 1 ? ' one' : '') . '">';
        if ($F['panel.short_daily']) {
            /* 도구모음은 모듈이 그린다 — 기간 바(일봉/주봉· 160/240/480/전체·±·SUE 공시)와
             * 지표 바(차트틀·차트저장·＋지표). 여기에 버튼을 손으로 적지 않는다.
             * 차트틀·지표 기억 키는 'short' 다(ChartFeat::SCREENS['short']['pref']). */
            echo '<div class="dt-panel"><div class="dt-bar" id="dtDayBar">'
               . '<span class="dt-t" id="dtDayLbl">일봉</span>';
            if ($F['overlay.intraday_ref']) {
                echo '<button type="button" id="dtTH" class="dt-chip on"'
                   . ' title="당일 기준 전고점(직전 60봉 최고가) 수평선 — 관찰용 기준선">당일전고</button>'
                   . '<button type="button" id="dtCP" class="dt-chip" title="현재가격선 표시/숨김">현재가</button>';
            }
            echo '<span id="dtDayPBar"></span><span id="dtDayIBar"></span>';
            if ($F['legend.values']) echo '<span id="dtDayLeg" class="dc-leg-dark"></span>';
            echo '<span class="dt-note" id="dtDayNote"></span></div>';
            echo '<div class="dt-host" id="dtDay"></div></div>';
        }
        if ($F['panel.short_sub']) {
            /* 지표 바(차트틀·차트저장·＋지표)를 여기에도 붙인다 — 차트틀 키는 'shortsub' 다.
             * ★메인('shortmin')과 «나눠 쓰지 않는» 이유: 축은 둘 다 min 이라 키를 공유하면
             *   같은 지표가 같은 변수로 두 패널에 실린다. 그런데 단위가 다르다(메인 1분 · 보조 3분)
             *   — 「이동평균 120」이 한쪽에선 2시간, 다른 쪽에선 6시간이라 같은 숫자가 다른 뜻이 된다. */
            echo '<div class="dt-panel"><div class="dt-bar" id="dtSubBar">'
               . '<span class="dt-t">보조 <span id="dtSubU">3분</span> · 10거래일</span>'
               . '<span id="dtSubIBar"></span>';
            if ($F['legend.values']) echo '<span id="dtSubLeg" class="dc-leg-dark"></span>';
            echo '<span class="dt-note" id="dtSubNote"></span></div>';
            echo '<div class="dt-host" id="dtSub"></div></div>';
        }
        echo '</section>';
    }
    echo '</main></div><div id="dtToast"></div>';

    echo '<script src="/style/dailychart.js?v=56"></script>';
    // 목록 배지(ETF 편입 수·퀀트) — 그리기·색의 단일본(사이트 공통 모듈)
    echo '<script src="/style/quantbadge.js?v=6"></script>';   // v6 = SUE 라벨 축약·종목명 옆 배치 (2026-08-12)
    pf_acnav_js();                                        // 종목 추가 검색창의 ↓/↑ 이동
    echo ChartFeat::boot('short', $pdo);
    /* 배지 표시 설정(설정 > 신호분석 설정 · BadgeFeat) — 세 목록(상위·내 목록·보유)이 같은 스위치를 본다.
     * 판정(quant_badge_many)은 서버가 그대로 내린다 — 끄는 것은 «그리기»뿐이다. */
    echo BadgeFeat::script(BadgeFeat::vals('short', $pdo));
    echo '<script>var DT_DAYS=' . json_encode($days) . ';'
       . 'var DT_TODAY=' . json_encode(date('Y-m-d')) . ';'
       . 'var DT_HASKEY=' . ($hasKey ? 1 : 0) . ';'
       . 'var DT_MAX=' . Dt::POOL_MAX . ';'
       /* 장중 갱신 주기 — 화면 타이머 둘과 서버 신선도(Dt::refreshQuotesLive)가 <b>한 숫자</b>다. */
       . 'var DT_TICK=' . (Dt::TICK_SEC * 1000) . ';</script>';

    echo <<<'JS'
<script>
(function(){
  var API = '/stock/api.php?module=dt&action=';
  var F   = DailyChart.feats;
  var $   = function(id){ return document.getElementById(id); };
  // 배지 표시 설정 — 서버(BadgeFeat::script)가 심는다. 없으면 전부 켬(첫 배포·오류에도 목록은 산다)
  var BF  = window.BADGE_FEATS || { etf:1, qsig:1, qpath:1, mom:1, sue:1, cap:1, held:1 };
  /* 칩 묶음용 사본 — SUE 는 두 목록 모두 «코드 옆(둘째 줄)»에 따로 그리므로 묶음에서는 뺀다
     (2026-08-20 사용자 — 종목명 옆은 이름을 밀어내서 옮겼다).
     끄고 켜는 것은 여전히 BF.sue 하나다(자리만 다르지 스위치가 갈리면 안 된다). */
  var BFq = { etf:BF.etf, qsig:BF.qsig, qpath:BF.qpath, mom:BF.mom, sue:0, cap:BF.cap };

  // live 는 «기능이 켜져 있을 때만» 참이다 — 꺼 두면 서버에 live=1 을 보내지도 않는다
  /* 메인 분봉은 1분 고정 · <b>보관 창 전부(10거래일)</b>를 한 번에 싣는다(2026-08-04 사용자 지시).
   * span = 지금 고른 창('today' | 거래일 수 | 'all') · view = 실제로 보이는 봉 수(null=전부).
   * ★「전체」는 3,810봉이라 캔들이 가늘다 — 그래서 [5일] 을 사이에 뒀다. 기본은 [당일]이다.
   * 보조 패널(5분 · 10거래일)과 기간이 같아졌지만 <b>단위가 다르다</b> — 메인=세부, 보조=맥락. */
  var MAIN_UNIT = 1, MAIN_DAYS = 10, MAIN_VIEW = 300;
  /* owned = 이 종목이 «원장(dt_min)을 가진» 종목인가.
   * ★담지 않은 종목도 목록에서 눌러 볼 수 있다 — 그때는 «미리보기»다(2026-08-05).
   *   담기는 조회가 아니라 <b>약속</b>이라(매일 크론이 수집 · 20칸 중 하나 · 넘치면 맨 아래를
   *   봉째로 삭제) 훑어보는 클릭이 그 부작용을 내면 안 된다. 그래서 미리보기는
   *   <b>네이버 당일 1분봉</b>(무료·토큰 불필요·차트 모듈의 정본 경로)만 쓰고 아무것도 쌓지 않는다.
   *   일봉은 어차피 담기와 무관하다(늘 action=daily 를 그때그때 읽는다) — 그대로 다 보인다. */
  var state = { code:'', name:'', owned:false, span:'today', view:MAIN_VIEW, live:F('chart.live'),
                rows:[], subRows:[] };

  /* 최근 n거래일이 «실린 봉 몇 개인가» — 하루 381봉을 가정하지 않는다.
   * 오늘은 장중이면 덜 차 있고, 반나절 장(폐장일)도 있어 곱셈은 어긋난다. */
  function barsForDays(n){
    var rows = state.rows || [];
    if (!rows.length) return null;
    var t0 = Math.floor(Date.parse(fromOf(n) + 'T00:00:00Z') / 1000);
    var i = 0;
    while (i < rows.length && rows[i].time < t0) i++;
    return (rows.length - i) || null;
  }
  function viewOf(span){
    if (span === 'all')   return null;                  // 실린 전부
    if (span === 'today') return MAIN_VIEW;             // 300봉
    return barsForDays(+span) || MAIN_VIEW;
  }
  function applySpan(){
    if (!main) return;
    state.view = viewOf(state.span);
    main.setViewDays(state.view);
  }
  var main = null, sub = null, day = null;

  /* ── 세션 캐시 ──
   * 종목 클릭 한 번에 fetch 가 셋(메인·보조·일봉)이라, 종목을 오갈 때마다 새로 받으면
   * 체감이 둔해진다. 메모리에만 둔다(localStorage 금지 — 시세는 오래 두면 거짓이 된다).
   * 장중엔 60초, 장 마감 뒤엔 세션 내내 유효. */
  var cache = {};
  function ttlOk(at){ return (Date.now() - at) < (isOpen() ? 60000 : 3600000); }
  function isOpen(){
    var d = new Date(), h = d.getHours()*100 + d.getMinutes(), w = d.getDay();
    return w >= 1 && w <= 5 && h >= 900 && h <= 1535;
  }
  function toast(t){
    var el = $('dtToast'); el.textContent = t; el.style.display='block';
    clearTimeout(el._t); el._t = setTimeout(function(){ el.style.display='none'; }, 2600);
  }
  function fmt(n){ return (Math.round(Number(n)||0)).toLocaleString(); }
  function rateCls(r){ return r > 0 ? 'up' : (r < 0 ? 'down' : 'flat'); }
  function rateTxt(r){ r = Number(r)||0; return (r>0?'+':'') + r.toFixed(2) + '%'; }

  /* 기간 = «거래일 수». 달력이 아니다 — 연휴가 끼면 3일이 실제로는 일주일 전이다.
   * 오늘이 아직 원장(krx_amt)에 없을 수 있어(마감 묶음은 15:50) 평일이면 오늘을 끝에 붙인다. */
  function effDays(){
    var d = DT_DAYS.slice();
    var w = new Date().getDay();
    if (w >= 1 && w <= 5 && d[d.length-1] !== DT_TODAY) d.push(DT_TODAY);
    return d;
  }
  function fromOf(span){
    var d = effDays();
    return d[Math.max(0, d.length - span)] || DT_TODAY;
  }
  /* 보조는 «메인보다 한 단계 위» — 같은 단위면 두 패널이 같은 말을 한다.
     ★단위는 서버의 Dt::UNITS 가 정본이다(현재 [1,3]). 여기 숫자가 그 목록에 없으면
       stock/api.php 의 series 가 <b>오류 없이 빈 배열</b>을 돌려줘 조용히 빈 차트가 된다. */
  function subUnitOf(u){ return u === 1 ? 3 : 3; }

  function series(code, unit, from, to, live){
    var k = code + '|' + unit + '|' + from + '|' + to;
    if (!live && cache[k] && ttlOk(cache[k].at)) return Promise.resolve(cache[k].rows);
    return DailyChart.fetchMinuteRange(code, unit, from, to, live ? 1 : 0)
      .then(function(rows){ cache[k] = { rows: rows, at: Date.now() }; return rows; })
      .catch(function(){ return []; });
  }

  /* ── 맨 왼쪽: 관심종목 + 시총 (탐색) ──────────────────────────────
   * 기본은 관심종목(action=watch · 상승률 순 전부 · 페이징 없음 — 2026-08-20 상승률 목록 교체).
   * 시총은 기존 경로 그대로 — action=top30(커서 페이징). 행 모양이 같아 렌더러는 하나다.
   * 여기서 담고 오른쪽 내 목록에서 본다. 배지·시총·회전율은 두 목록이 같은 단일본을 쓴다.
   * ★접혀 있으면 부르지 않는다 — 이 화면의 「끄면 네트워크 호출까지 사라진다」 원칙. */
  var TOP_KEY = 'dt_top_on', TOP_API = '/stock_analysis_api.php?module=stock&action=top30';
  var WATCH_API = '/stock_analysis_api.php?module=stock&action=watch';
  var topSort = 'watch', topStart = 0, topCursor = null, topRows = [], topSeq = 0;
  /* 이미 «원장을 가진» 종목 둘 — renderList 가 갱신한다.
     poolSet = 내가 담은 것 · heldSet = 포트폴리오가 보유한 것.
     ★둘 다 매일 수집된다(Dt::targetCodes) — 그래서 미리보기 판정(state.owned)은 «합집합»이다. */
  var poolSet = {}, heldSet = {};

  function topOn(){ return !$('dt-top').classList.contains('col'); }

  /* ★「받는 중이면 무시」가 아니라 «번호표»를 쓴다 — 전자는 「다음」 직후 「시총」을 누르면
     버튼만 시총으로 바뀌고 목록은 상승률인 채 남는다(요청이 조용히 버려진다).
     번호표면 늘 마지막 요청이 이기고, 늦게 온 응답은 스스로 물러난다(뉴스와 같은 규칙). */
  function loadTop(cursor, start){
    if (!topOn()) return;
    var my = ++topSeq;
    /* 관심종목은 상승률 순으로 «전부» 온다 — 커서가 없다(페이징은 시총에서만 보인다) */
    var isWatch = topSort === 'watch';
    var u = isWatch ? WATCH_API : TOP_API + '&sort=' + topSort;
    if (!isWatch && cursor) u += '&before=' + encodeURIComponent(cursor.val) + '&before_code=' + encodeURIComponent(cursor.code);
    fetch(u, { credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(rows){
        if (my !== topSeq) return;
        rows = Array.isArray(rows) ? rows : [];
        $('dtTopPg').style.display = isWatch ? 'none' : '';
        if (isWatch){
          topRows = rows; topStart = 0; topCursor = null;
          $('dtTopSub').textContent = rows.length + '종목';
          if (!rows.length){
            $('dtTopList').innerHTML = '<div class="dt-top-msg">관심종목이 없습니다.<br>'
              + '포트폴리오의 관심종목 화면(☆)에서 담으면 여기에 나옵니다.</div>';
            return;
          }
          renderTop();
          return;
        }
        // 빈 응답 = 더 없음. 지금 보이는 쪽을 그대로 두고 「다음」만 막는다
        if (!rows.length) { $('dtTopNext').disabled = true; return; }
        topRows = rows; topStart = start;
        var last = rows[rows.length - 1];
        topCursor = { val: last[topSort], code: last.code };
        $('dtTopSub').textContent = (start + 1) + '–' + (start + rows.length) + '위';
        $('dtTopFirst').disabled = (start === 0);
        $('dtTopNext').disabled  = (rows.length < 30);
        renderTop();
      })
      .catch(function(){
        if (my !== topSeq) return;
        $('dtTopList').innerHTML = '<div class="dt-top-msg">목록을 불러오지 못했습니다.</div>';
      });
  }

  function renderTop(){
    var box = $('dtTopList');
    if (!topRows.length) { box.innerHTML = '<div class="dt-top-msg">목록이 없습니다.</div>'; return; }
    box.innerHTML = '';
    topRows.forEach(function(s, i){
      var el = document.createElement('div');
      el.className = 'dt-tr qb-sm' + (s.code === state.code ? ' on' : '');
      el.dataset.code = s.code;
      var cap = Number(s.cap) || 0, tv = Number(s.tradeEok) || 0;
      var turn = cap > 0 ? (tv / cap * 100) : 0;
      var isEtf = s.kind === 'etf', has = !!poolSet[s.code], hasHeld = !!heldSet[s.code];
      /* ★ETF 는 담지 않는다 — 단타 목록의 이름·현재가·시총은 all_stock_info 에서 오는데
         ETF 는 그 표에 없다. 담기면 빈 행이 되어 「고장난 것처럼」 보인다.
         ★보유 종목도 담지 않는다 — 이미 매일 수집되므로(Dt::targetCodes) 담아 봐야
         20칸 하나를 먹고 남의 종목을 FIFO 로 밀어낼 뿐이다. 내 목록에 보유로 이미 있다. */
      var btn = isEtf
        ? '<button class="ad" type="button" disabled title="ETF 는 단타 목록에 담지 않습니다 (시세 원천이 다릅니다)">·</button>'
        : hasHeld
          ? '<button class="ad has" type="button" disabled title="보유 종목 — 담지 않아도 매일 수집됩니다 (내 목록에 보유로 있습니다)">✓</button>'
          : has
            ? '<button class="ad has" type="button" disabled title="이미 단타에 담긴 종목">✓</button>'
            : '<button class="ad" type="button" title="단타에 담기 — 10거래일 분봉을 받아 옵니다">＋</button>';
      el.innerHTML =
        '<div class="n1"><span class="rk">' + (topStart + i + 1) + '</span>'
          + '<span class="t">' + QuantBadge.esc(s.name) + '</span>'
          + (isEtf ? '<span class="dt-bdg etf">ETF</span>' : '')
          + (hasHeld ? '<span class="dt-bdg held" title="포트폴리오에 있는 종목입니다">보유</span>' : '')
          + (BF.etf ? QuantBadge.etf(s.etfTop) : '') + '</div>' +
        '<div class="v1 ' + rateCls(s.rate) + '">' + rateTxt(s.rate) + '</div>' +
        btn +
        // SUE 는 코드 바로 옆(2026-08-20 사용자 — 종목명 옆은 이름을 밀어냈다) — 묶음(BFq)에서는 빠져 있다
        '<div class="n2">' + s.code
          + (BF.sue && s.q && s.q.sue ? QuantBadge.sue(s.q.sue) : '')
          + QuantBadge.quant(s.q, BFq) + '</div>' +
        // 시총·회전율(BF.cap) — 칸은 남긴다(그리드 자리) · 내용만 비운다
        '<div class="v2">' + (!BF.cap ? '' : (cap > 0 ? QuantBadge.eok(cap) : '—')
          + (turn > 0 ? ' · ' + turn.toFixed(1) + '%' : '')) + '</div>';
      var ad = el.querySelector('.ad');
      if (!isEtf && !has && !hasHeld) ad.onclick = function(e){
        e.stopPropagation();
        ad.disabled = true; ad.textContent = '…';
        addCode(s.code, s.name);
      };
      /* 행 클릭 — 담지 않은 종목도 «미리보기»로 열린다(오늘 분봉 + 일봉).
         담기는 오른쪽 「＋」가 하는 명시적 행동이라, 보는 것과 담는 것을 섞지 않는다. */
      el.onclick = function(){ pick({ code:s.code, name:s.name }); };
      box.appendChild(el);
    });
  }

  seg('dtTopSort', 'data-s', function(v){ topSort = v; topCursor = null; loadTop(null, 0); });
  $('dtTopFirst').onclick = function(){ loadTop(null, 0); };
  $('dtTopNext').onclick  = function(){ if (topCursor) loadTop(topCursor, topStart + topRows.length); };

  // 차트를 보다가 「계속 봐야겠다」 싶을 때 — 목록으로 손이 돌아가지 않게 여기서도 담는다
  $('dtAdd').onclick = function(){
    if (!state.code) return;
    this.disabled = true;
    addCode(state.code, state.name);
  };

  panel('dt-top', 'dtTopHnd', 'dtTopTgl', TOP_KEY, function(){
    if (!topRows.length) loadTop(null, 0);        // 접혀 있던 동안엔 안 받아 왔다
  });

  /* ── 종목 목록 — 단타+보유 «한 목록» · 등락률순 (2026-08-26 사용자 지시) ──────────
   * 「단타든 보유든 어떤 종목이 올랐나·내렸나가 중요하다」 — 구분을 걷어내고 등락률로 세운다.
   * 보유 종목은 초록 바탕(.hld)과 「보유」 배지로만 가른다 · 겹치는 종목(담았고 보유이기도)은
   * 한 행으로 접되 양쪽 성질을 다 갖는다(_pool → ×빼기 가능 · _held → 보유 배지·바탕).
   * ★드래그 정렬은 없다 — 등락률이 순서의 주인이라 손으로 옮긴 순서가 설 자리가 없다
   *   (dt_pool.sort_no 는 담은 순 그대로 남아 FIFO 우선순위 역할만 한다).
   * ★보유 목록은 여전히 따로 받아 온다 — 미리보기 판정(state.owned)과
   *   상위 목록의 「보유」 표시가 그 집합을 보기 때문이다.
   * ★두 출처가 원장 하나(dt_min)를 나눠 본다 — 겹치는 종목도 봉은 한 벌뿐이라 따로 놀 수 없다. */
  var poolRows = [], heldRows = [], poolMax = DT_MAX;
  /* 보유 종목의 초기 10거래일을 «화면이 열릴 때» 당겨 오는 자리 (2026-08-05 사용자 선택).
     heldFilling = 지금 받고 있는 코드(하나) · heldTried = 이 세션에서 이미 시도한 코드들. */
  var heldFilling = null, heldTried = {};

  function loadPool(keep){
    var pool = fetch(API + 'pool_list', { credentials:'same-origin' })
                 .then(function(r){ return r.json(); });
    // 보유 목록이 실패해도 단타 목록은 떠야 한다 — 곁다리가 본체를 죽이지 않는다
    var held = fetch(API + 'held_list', { credentials:'same-origin' })
                 .then(function(r){ return r.json(); })
                 .catch(function(){ return { rows: [] }; });

    return Promise.all([pool, held]).then(function(a){
      var d = a[0] || {}, h = a[1] || {};
      if (d.days && d.days.length) DT_DAYS = d.days;
      $('dtWin').textContent = d.window ? (d.window + ' ~ 오늘 · ' + (d.days||[]).length + '거래일') : '—';
      poolRows = d.rows || [];
      heldRows = h.rows || [];
      poolMax  = d.max || DT_MAX;
      /* 두 집합을 여기서 «다시» 만든다 — 목록을 그릴 때마다 만들므로 세 패널이 어긋날 수 없다
         (상위 종목의 ✓/보유 표시가 이것만 본다). */
      poolSet = {}; poolRows.forEach(function(r){ poolSet[r.code] = 1; });
      heldSet = {}; heldRows.forEach(function(r){ heldSet[r.code] = 1; });
      if (topRows.length) renderTop();
      renderList();
      if (!keep && !state.code) {
        var first = mergedRows()[0];
        if (first) pick(first);
      }
      return d;
    });
  }

  /* 단타+보유를 한 벌로 접는다 — 겹치는 종목은 «보유 행»을 바탕으로(포지션 배지에 pos_id 가
     필요하다) 단타 쪽 수집 상태(init_status)를 얹는다. 정렬은 등락률 내림차순 하나. */
  function mergedRows(){
    var by = {}, out = [];
    heldRows.forEach(function(r){
      var m = { _held: 1 };
      for (var k in r) m[k] = r[k];
      by[r.code] = m;
    });
    poolRows.forEach(function(r){
      var m = by[r.code];
      if (m) {
        m._pool = 1;
        m.init_status = r.init_status;
        if ((r.holes || 0) > (m.holes || 0)) m.holes = r.holes;
      } else {
        m = { _pool: 1 };
        for (var k in r) m[k] = r[k];
        by[r.code] = m;
      }
    });
    for (var c in by) out.push(by[c]);
    out.sort(function(a, b){ return (Number(b.rate) || 0) - (Number(a.rate) || 0); });
    return out;
  }

  function renderList(){
    var box  = $('dtList');
    var rows = mergedRows();
    if (!rows.length) {
      box.innerHTML = '<div class="dt-empty">종목이 없습니다.<br>위 칸에서 담거나,<br>포트폴리오에 편입하면 나타납니다.</div>';
    } else {
      box.innerHTML = '';
      rows.forEach(function(r){ box.appendChild(listRow(r)); });
    }
    $('dtQ').placeholder = '종목 추가 (' + poolRows.length + '/' + poolMax + ')';
  }

  /* 한 행 — 단타·보유가 «같은 함수»를 지난다. 배지·열 모양을 출처마다 따로 적으면
     하나를 고칠 때 다른 하나가 조용히 옛말을 한다(서버의 finishRows 와 같은 이유). */
  function listRow(r){
    var el = document.createElement('div');
    // qb-sm = 좁은 사이드바용 축소 배지 (크기 규칙도 style/quantbadge.js 소유)
    // hld  = 보유 종목의 초록 바탕 — 한 목록 안에서 보유를 가르는 표시(배지와 같은 색 계열)
    el.className = 'dt-it qb-sm' + (r._held ? ' hld' : '') + (r.code === state.code ? ' on' : '');
    el.dataset.code = r.code;

    /* 수집 상태 — 「보유」에는 init_status 가 없다(담은 적이 없으니 「받는 중」도 없다).
       대신 봉이 아직 없으면 «왜 비었는지»를 적는다 — 빈 칸은 고장으로 읽힌다.
       초기 10거래일은 크론의 «구멍 치유» 단계가 메운다(담기의 pool_init 에 해당하는 자리). */
    var bdg = '';
    if (r._held && heldFilling === r.code)    bdg = '<span class="dt-bdg wait">받는 중</span>';
    else if (r._pool && r.init_status === 1)  bdg = '<span class="dt-bdg wait">받는 중</span>';
    else if (r._pool && r.init_status === 9)  bdg = '<span class="dt-bdg fail">수집 실패</span>';
    else if (r.holes > 0)                     bdg = '<span class="dt-bdg hole">구멍 ' + r.holes + '</span>';
    else if (r._held && !r.bars)              bdg = '<span class="dt-bdg wait" title="다음 수집(평일 16:45)이'
                                                  + ' 10거래일을 채웁니다">채우는 중</span>';

    /* 보유 배지 — 사이트 공통 어휘 그대로다(「보유」=돈이 들어감 · 「편입됨」=자리만 있음).
       바탕색과 «함께» 단다 — 색약·인쇄에서도 배지가 말한다. 눌러서 포지션 상세로. */
    var hb = '';
    if (r._held) {
      hb = '<a class="dt-bdg ' + (r.open ? 'held' : 'slot')
         + '" href="/stock/index.php?mode=position&id=' + (Number(r.pos_id) || 0)
         + '" title="' + QuantBadge.esc((r.pf_names || '') + ' · 눌러서 포지션 상세로') + '">'
         + (r.open ? '보유' : '편입됨') + (r.pf_n > 1 ? ' ' + r.pf_n : '') + '</a>';
    }

    /* 배지·시총·회전율은 왼쪽 「상위 종목」 목록과 «같은 것»이다 —
       판정은 서버(stock/lib/quant.php), 그리기는 공용 모듈. 여기서 다시 세우지 않는다.
       회전율 = 거래대금 ÷ 시가총액 (둘 다 억원) — 파생값이라 저장하지 않는다. */
    var cap = Number(r.cap)||0, amt = Number(r.amt_eok)||0;
    var turn = cap > 0 ? (amt / cap * 100) : 0;
    // 배지 표시 설정(BF) — 상위 목록과 «같은 스위치»를 본다. 칸은 남기고 내용만 비운다
    var mk = !BF.cap ? ''
           : (cap > 0 ? QuantBadge.eok(cap) : '—') + (turn > 0 ? ' · ' + turn.toFixed(1) + '%' : '');
    el.innerHTML =
      '<div class="nm"><span class="t">' + QuantBadge.esc(r.name||r.code) + '</span>'
        + (BF.etf ? QuantBadge.etf(r.etf_top) : '') + hb + bdg + '</div>' +
      '<div class="px ' + rateCls(r.rate) + '">' + (r.price ? fmt(r.price) : '—') + '</div>' +
      // SUE 는 코드 바로 옆(탐색 패널과 같은 자리·같은 스위치 · 2026-08-20) — sg 칩 묶음(BFq)에서는 빠진다
      '<div class="cd">' + r.code
        + (BF.sue && r.q && r.q.sue ? QuantBadge.sue(r.q.sue) : '')
        + ' · ' + r.days + '일 ' + fmt(r.bars) + '봉</div>' +
      '<div class="rt ' + rateCls(r.rate) + '">' + (r.price ? rateTxt(r.rate) : '') + '</div>' +
      '<div class="sg">' + QuantBadge.quant(r.q, BFq) + '</div>' +
      '<div class="mk" title="시가총액 · 회전율(거래대금÷시가총액)">' + mk + '</div>' +
      // × 빼기는 단타 탭에만 — 보유는 여기서 뺄 수 있는 것이 아니다(포트폴리오가 정한다)
      // × 빼기는 «담은» 종목에만 — 보유만인 행은 여기서 뺄 수 있는 것이 아니다(포트폴리오가 정한다)
      (r._pool ? '<button class="x" type="button" title="단타 종목에서 빼기">×</button>' : '');
    var x = el.querySelector('.x');
    if (x) x.onclick = function(e){ e.stopPropagation(); removeCode(r); };
    var a = el.querySelector('a.dt-bdg');
    if (a) a.onclick = function(e){ e.stopPropagation(); };   // 배지는 포지션 상세로, 행은 종목 고르기로
    el.onclick = function(){ pick(r); };
    return el;
  }

  /* ── 보유 종목의 초기 10거래일을 «화면이 열릴 때» 당겨 온다 (2026-08-05 사용자 선택) ──
   * 보유엔 「＋를 누르는 순간」이 없다. 크론의 ③ 구멍 치유가 결국 메우지만 그건 «다음 16:45» 라,
   * 편입한 날 분봉을 못 본다. 그래서 이 자리에 뒀다 — 훅이 <b>단타 화면 한 곳</b>뿐이라
   * 포트폴리오 저장 경로(Pf::positionSave)를 키움에 매달지 않는다(그쪽이 느려지거나 같이 죽는다).
   *
   * ★<b>한 번에 하나씩</b> — 키움은 TR 별 1 req/s 이고 한 종목이 ~5초다. 동시에 쏘면 429 를 부른다.
   * ★<b>한 세션에 한 종목당 한 번만</b>(`heldTried`) — 실패를 되풀이하면 화면을 열 때마다 콜을 태운다.
   *   못 받은 것은 크론이 맡는다(그래서 배지가 「채우는 중」으로 돌아간다).
   * ★키움 키가 없으면 아예 시도하지 않는다 — 네이버는 당일치뿐이라 10거래일을 못 만든다. */
  function fillHeld(){
    if (!DT_HASKEY || heldFilling) return;
    var next = null;
    for (var i = 0; i < heldRows.length; i++) {
      var r = heldRows[i];
      if (!r.bars && !heldTried[r.code]) { next = r; break; }
    }
    if (!next) return;

    var code = next.code;
    heldTried[code] = 1;
    heldFilling = code;
    renderList();                                // 「채우는 중」 → 「받는 중」

    var done = function(){
      heldFilling = null;
      /* 목록을 다시 읽어야 봉 수가 바뀐다. ★기다린 «뒤에» 다음 종목으로 넘어간다 —
         heldRows 가 아직 옛것이면 방금 채운 종목을 또 고른다(heldTried 가 막지만 헛돈다). */
      return loadPool(true).then(function(){
        if (state.code === code) pick({ code: code, name: state.name });
        fillHeld();
      });
    };
    fetch(API + 'held_init&code=' + encodeURIComponent(code), { credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(d){
        // 성공은 조용히 — 목록의 봉 수가 곧 답이다. 실패만 말한다(왜 안 채워졌는지 알아야 한다)
        if (!d || !d.ok) toast(code + ' — ' + ((d && d.msg) || '분봉을 못 받았습니다') + ' (크론이 다시 시도합니다)');
        return done();
      })
      .catch(function(){ return done(); });
  }

  function removeCode(r){
    /* ★보유 종목이면 «봉은 남는다» — 단타에서 빠져도 여전히 수집 대상이다(Dt::poolRemove).
       빠지는 것은 슬롯 하나뿐이니 겁주는 문구를 그대로 쓰면 거짓말이 된다. */
    var msg = r.held
      ? r.name + ' 을(를) 단타 목록에서 뺍니다.\n\n보유 종목이라 분봉은 그대로 두고 매일 수집도 이어집니다.'
              + '\n(목록에는 보유 종목으로 계속 보입니다)'
      : r.name + ' 을(를) 단타에서 뺍니다.\n\n쌓아 둔 분봉도 함께 지웁니다. (재등록하면 10거래일을 다시 받습니다)';
    if (!confirm(msg)) return;
    fetch(API + 'pool_remove&code=' + r.code, { credentials:'same-origin' })
      .then(function(x){ return x.json(); })
      .then(function(d){
        if (r.code === state.code) { state.code=''; state.name=''; }
        toast(d.msg || '뺐습니다.');
        loadPool(true);
      });
  }

  /* 드래그 정렬은 없앴다(2026-08-26) — 목록의 순서가 등락률이 되면서 손으로 옮긴 순서가
     설 자리가 없다. dt_pool.sort_no 는 담은 순 그대로 남아 FIFO(맨 아래 밀어내기) 우선순위만 정한다. */

  // ── 종목 추가 ──────────────────────────────────────────────────────
  var sugT = null;
  $('dtQ').addEventListener('input', function(){
    clearTimeout(sugT);
    var q = this.value.trim();
    if (q.length < 1) { $('dtSug').style.display='none'; return; }
    sugT = setTimeout(function(){ search(q); }, 200);
  });
  /* ↓/↑/Enter/Esc — 공용 모듈(style/acnav.js)이 맡는다. 화면에 다시 적지 않는다. */
  AcNav.attach($('dtQ'), {
    box:   '#dtSug',
    item:  '.s',
    close: function(){ $('dtSug').style.display='none'; }
  });
  document.addEventListener('click', function(e){
    if (!e.target.closest('.dt-side-head')) $('dtSug').style.display='none';
  });

  function search(q){
    // 종목 검색은 기존 경로를 그대로 쓴다 (module=stock&action=search · all_stock_info)
    fetch('/stock/api.php?module=stock&action=search&q=' + encodeURIComponent(q), { credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(list){
        var box = $('dtSug');
        if (!list || !list.length) { box.style.display='none'; return; }
        box.innerHTML = '';
        list.slice(0, 12).forEach(function(s){
          var el = document.createElement('div');
          el.className = 's';
          el.innerHTML = '<span>' + s.name + '</span><span class="c">' + s.code + '</span>';
          el.onclick = function(){ addCode(s.code, s.name); };
          box.appendChild(el);
        });
        box.style.display = 'block';
      });
  }

  function addCode(code, name){
    $('dtSug').style.display='none';
    $('dtQ').value = '';
    fetch(API + 'pool_add&code=' + encodeURIComponent(code) + '&name=' + encodeURIComponent(name),
          { credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(d){
        // 실패하면 상위 종목 패널의 「담기」 버튼을 원래대로 되돌린다(눌린 채 「…」로 굳지 않게)
        if (d.error || !d.ok) { toast(d.error || d.msg || '담지 못했습니다.'); renderTop(); return; }
        toast(d.msg);
        loadPool(true);
        // 초기 적재는 5초쯤 걸린다 — 버튼을 잡아 두지 않고 «받는 중» 배지로 알린다
        fetch(API + 'pool_init&code=' + encodeURIComponent(code), { credentials:'same-origin' })
          .then(function(r){ return r.json(); })
          .then(function(x){
            toast(code + ' — ' + (x.msg || '완료'));
            /* ★적재가 «끝난 뒤에» 다시 고른다 — 담기 직후(적재 전)에 바꾸면 빈 차트가 잠깐 뜬다.
               미리보기로 보고 있던 종목이 그 자리에서 10거래일 그림으로 바뀐다.
               ★loadPool 을 «기다린 뒤에» 부른다 — poolSet 이 아직 안 채워졌으면 pick 이
                 여전히 미리보기로 판정한다(비동기 순서 함정). */
            loadPool(true).then(function(){
              if (state.code === code) pick({ code: code, name: state.name });
            });
          })
          .catch(function(){ loadPool(true); });
      });
  }

  // ── 차트 ───────────────────────────────────────────────────────────
  function pick(r){
    if (!r || !r.code) return;
    state.code = r.code; state.name = r.name || r.code;
    /* ★owned = 「원장(dt_min)에 10거래일이 쌓이는 종목인가」다 — 담은 것 «∪» 보유한 것.
       보유 종목도 크론이 매일 수집하므로(Dt::targetCodes) 미리보기로 판정하면 거짓말이 된다. */
    state.owned = !!(poolSet[r.code] || heldSet[r.code]);
    $('dtTitle').innerHTML = QuantBadge.esc(state.name)
      + '<span class="c" id="dtCode">' + r.code + '</span>'
      + (state.owned ? '' : '<span class="dt-bdg prev" title="담지 않은 종목 — 오늘 분봉과 일봉만 보입니다">미리보기</span>');
    $('dtAdd').style.display = state.owned ? 'none' : '';
    $('dtAdd').disabled = false;
    syncSpan();
    // 두 구획을 함께 짚는다 — 겹치는 종목(담았고 보유이기도)은 양쪽 다 켜진다(같은 종목이니 맞는 그림)
    Array.prototype.forEach.call($('dtList').querySelectorAll('.dt-it'), function(el){
      el.classList.toggle('on', el.dataset.code === r.code);
    });
    // 상위 종목 패널도 같은 종목을 짚어 준다 — 두 목록에 같은 종목이 있을 때 눈이 헤매지 않게
    Array.prototype.forEach.call($('dtTopList').children, function(el){
      if (el.classList) el.classList.toggle('on', el.dataset.code === r.code);
    });
    drawFills();     // 마커 원본을 먼저 받아 둔다 — 각 패널이 봉을 실은 뒤 스스로 얹는다
    drawMain();
    drawSub();
    drawDay();
    drawNews();
  }

  /* ── 실계좌 체결 마커 ───────────────────────────────────────────────
   * 「내가 언제 샀나」를 봉 위에 얹는다. <b>판정이 아니라 기록</b>이라 단타 규칙 9 에 걸리지 않는다.
   *
   * ★분봉의 시각은 «봉 시작»이다(Kiwoom::TS_BASE='start'). 그래서 체결 시각을 그대로 쓰지 않고
   *   <b>실린 봉 중 그 시각 «이하»의 마지막 봉</b>에 붙인다 — 15:02:46 매도는 15:02 봉이다.
   * ★맞는 봉이 없으면 «조용히 생략»한다. 보관 창이 10거래일뿐이고(그 밖의 체결은 봉이 없다),
   *   15:20~15:29 처럼 봉이 아예 없는 시간대도 있다. 앞으로 당겨 붙이면 첫 봉에 마커가 일렬로 선다
   *   (SUE·시뮬레이터에서 이미 겪은 함정).
   * ★시각을 모르는 옛 체결은 분봉에서 빠지고 <b>일봉에는 남는다</b> — 날짜는 아니까. */
  var fills = [];
  function drawFills(){
    fills = [];
    if (!state.code || !F('overlay.real_trades')) { return; }
    var code = state.code;
    fetch('/stock/api.php?module=dt&action=fills&code=' + encodeURIComponent(code),
          { credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (code !== state.code) return;              // 그 사이 다른 종목을 골랐다
        fills = (j && j.rows) || [];
        applyFills();
      })
      .catch(function(){ fills = []; });
  }

  /** 체결 한 건의 칩 글자. 묶지 않는다 — 분봉은 봉이 촘촘해 겹칠 일이 드물다. */
  function fillText(f){
    return (f.sell ? '매도' : (f.step > 0 ? f.step + '차' : '매수'))
         + ' ' + Number(f.qty).toLocaleString() + '주 @' + Number(f.price).toLocaleString();
  }

  /* 분봉 봉시각 = 그 날 KST 초가 그대로 실린 값이다(09:00=32400). 그래서 UTC 로 만들면 맞는다. */
  function fillUnix(f){
    if (!f.t) return null;
    var ms = Date.parse(f.d + 'T' + f.t + 'Z');
    return isNaN(ms) ? null : Math.floor(ms / 1000);
  }

  /* 체결이 «담기는» 봉을 찾는다 — 마지막 bar ≤ t 이면서 t 가 그 봉의 구간(unit분) 안일 때만.
   * ★「t 이하의 마지막 봉」만으로는 안 된다: 원장에 시간외(16:00~18:00)가 없어서
   *   16:30 체결이 15:30 단일가 봉에 붙어 «장중에 판 것»처럼 보인다. 구간 밖이면 안 찍는다.
   * ★15:20~15:29 도 봉이 없는 구간이다(정규 381봉 = 09:00~15:19 + 15:30 단일가). */
  function snapBar(rows, t, unitMin){
    var span = (unitMin || 1) * 60, best = null;
    for (var i = 0; i < rows.length; i++) {
      var bt = rows[i].time;
      if (bt > t) break;
      best = bt;
    }
    if (best === null) return null;
    return (t < best + span) ? best : null;
  }

  function minuteMarks(rows, unitMin){
    if (!rows || !rows.length || !fills.length) return [];
    var out = [];
    for (var i = 0; i < fills.length; i++) {
      var t = fillUnix(fills[i]);
      if (t === null) continue;                       // 시각 모름 → 분봉엔 못 찍는다
      var bar = snapBar(rows, t, unitMin);
      if (bar === null) continue;                     // 실린 봉 밖 → 조용히 생략
      out.push({ time: bar, sell: !!fills[i].sell, text: fillText(fills[i]) });
    }
    return out.sort(function(a, b){ return a.time - b.time; });
  }

  function dayMarks(){
    /* 일봉은 하루 한 봉이라 시각을 못 쓴다 — 날짜만 쓰고, 같은 날 같은 방향은 한 칩으로 묶는다
       (안 묶으면 칩이 겹겹이 쌓여 캔들을 가린다 — 포트폴리오 일봉과 같은 규칙). */
    var g = {};
    for (var i = 0; i < fills.length; i++) {
      var f = fills[i], k = f.d + '|' + (f.sell ? 'S' : 'B');
      if (!g[k]) g[k] = { time: f.d, sell: !!f.sell, qty: 0, amt: 0, n: 0 };
      g[k].qty += f.qty; g[k].amt += f.qty * f.price; g[k].n++;
    }
    return Object.keys(g).sort().map(function(k){
      var x = g[k];
      return { time: x.time, sell: x.sell,
               text: (x.sell ? '매도' : '매수') + ' ' + x.qty.toLocaleString()
                   + '주 @' + Math.round(x.amt / x.qty).toLocaleString()
                   + (x.n > 1 ? ' (' + x.n + '건)' : '') };
    });
  }

  /** 봉이 새로 실릴 때마다 다시 얹는다 — setData 가 마커를 지우기 때문이다. */
  function applyFills(){
    if (!F('overlay.real_trades')) return;
    if (main && state.rows) main.setMarkers(minuteMarks(state.rows, MAIN_UNIT));
    if (sub  && state.subRows) sub.setMarkers(minuteMarks(state.subRows, subUnitOf(MAIN_UNIT)));
    if (day) day.setMarkers(dayMarks());
  }

  /* ── 뉴스 ───────────────────────────────────────────────────────────
   * 소스는 기존 경로 그대로다(action=news) — 새 수집 경로를 만들지 않는다
   * (일봉 패널이 기존 action=daily 를 그대로 읽는 것과 같은 규칙).
   * 접혀 있으면 부르지도 않는다 — 「끄면 네트워크 호출까지 사라진다」가 이 화면의 원칙이다. */
  var newsCache = {};
  function newsOn(){ return !$('dt-news').classList.contains('col'); }
  function drawNews(){
    if (!state.code || !newsOn()) return;
    $('dtNewsSub').textContent = state.name;
    var code = state.code;
    var c = newsCache[code];
    if (c && ttlOk(c.at)) { renderNews(code, c.rows); return; }
    $('dtNewsList').innerHTML = '<div class="dt-news-msg">불러오는 중…</div>';
    fetch('/stock_analysis_api.php?module=stock&action=news&code=' + encodeURIComponent(code),
          { credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(list){
        list = Array.isArray(list) ? list : [];
        newsCache[code] = { rows:list, at:Date.now() };
        renderNews(code, list);
      })
      .catch(function(){ renderNews(code, []); });
  }
  /* ★어느 종목의 응답인지 들고 다닌다 — 늦게 온 응답이 «지금 고른 종목»의 목록을 덮으면
     화면이 다른 종목의 뉴스를 그 종목 것인 양 보여 준다(종목을 빨리 넘길 때 실제로 난다). */
  function renderNews(code, list){
    if (code !== state.code) return;
    var box = $('dtNewsList');
    if (!list.length) { box.innerHTML = '<div class="dt-news-msg">관련 뉴스가 없습니다.</div>'; return; }
    box.innerHTML = '';
    list.forEach(function(n){
      var a = document.createElement('a');
      a.className = 'dt-news';
      a.href = n.url || '#';
      a.onclick = function(e){
        e.preventDefault();
        if (n.url) window.open(n.url, 'news_popup', 'width=800,height=900,left=200,top=100,scrollbars=yes');
      };
      a.innerHTML = '<div class="t">' + QuantBadge.esc(n.title) + '</div>'
                  + '<div class="d">' + QuantBadge.esc(n.date || '') + '</div>';
      box.appendChild(a);
    });
  }
  // 접혀 있는 동안 바뀐 종목의 뉴스는 펴는 순간 받아 온다
  panel('dt-news', 'dtNewsHnd', 'dtNewsTgl', 'dt_news_on', drawNews);
  /* ★단타 종목 목록에는 접기를 두지 않는다(2026-08-05 사용자 지시) — 이 화면의 «주인공»이라
     늘 보여야 하고, 손잡이가 오른쪽 값(시총·회전율)을 가리기만 했다. 접는 것은 곁 패널 둘뿐이다. */

  /* 미리보기용 «오늘 1분봉» — 차트 모듈의 정본(DailyChart.fetchMinute)을 그대로 부른다.
     새 수집 경로를 만들지 않는다(일봉 패널·뉴스와 같은 규칙). 캐시는 원장과 같은 통을 쓰되
     열쇠를 달리해 둔다 — 같은 종목이라도 «원장 10일»과 «네이버 오늘»은 다른 물건이다. */
  function todaySeries(code){
    var k = code + '|nav';
    if (cache[k] && ttlOk(cache[k].at)) return Promise.resolve(cache[k].rows);
    return DailyChart.fetchMinute(code)
      .then(function(rows){ cache[k] = { rows: rows, at: Date.now() }; return rows; })
      .catch(function(){ return []; });
  }

  /* 미리보기에서는 [5일]·[전체]가 거짓말이 된다 — 실린 것은 오늘치뿐이다. */
  function syncSpan(){
    var pv = !state.owned;
    if (pv) state.span = 'today';
    Array.prototype.forEach.call($('dtSpan').querySelectorAll('button'), function(b){
      var m = b.getAttribute('data-m');
      b.disabled = pv && m !== 'today';
      b.title = b.disabled ? '담아야 여러 날을 볼 수 있습니다 (미리보기는 오늘치뿐)' : '';
      if (pv) b.classList.toggle('on', m === 'today');
    });
  }

  function drawMain(){
    if (!main || !state.code) return;
    var code = state.code;
    $('dtNote').textContent = '불러오는 중…';
    /* 종목을 모듈에 알린다 — 사용자 수평선(지지·저항)이 이 종목 것으로 붙는다.
     * SUE 공시 마커는 분봉 축에선 모듈이 스스로 건너뛴다(공시일은 날짜 단위라 뜻이 없다). */
    main.setCode(code);
    if (!state.owned) {
      todaySeries(code).then(function(rows){
        if (code !== state.code) return;          // 그 사이 다른 종목을 골랐다
        state.rows = rows;
        main.setData(rows);
        applySpan();
        applyFills();
        $('dtNote').textContent = rows.length
          ? '미리보기 · 오늘 ' + rows.length + '봉 — 담으면 10거래일이 쌓입니다'
          : '오늘 분봉이 없습니다 (휴장일이거나 장 시작 전) — 아래 일봉은 그대로 보입니다';
      });
      return;
    }
    var from = fromOf(MAIN_DAYS), to = DT_TODAY;
    /* ★live 플래그에 isOpen() 을 걸지 않는다 (2026-08-06).
     * 걸었더니 <b>15:35~16:45 사이 70분</b> 동안 차트가 어제 봉을 보여 줬다 —
     * 장이 닫혀 화면이 «묻지 않는데» 원장(dt_min)은 16:45 크론이 채우기 «전»인 구간이다.
     * 이 플래그의 뜻은 「지금 실시간인가」가 아니라 <b>「오늘치가 있으면 얹어 달라」</b>이고,
     * 실제로 받을지는 서버(Dt::todayBars)가 정한다 — 원장이 이미 찼거나 휴장일이면 콜 0회다.
     * (타이머는 여전히 isOpen() 으로 막혀 있어 마감 뒤 폴링은 없다.) */
    series(code, MAIN_UNIT, from, to, state.live).then(function(rows){
      if (code !== state.code) return;
      state.rows = rows;
      /* ★fit 하지 않는다 — 창의 주인은 [당일]/[5일]/[전체]와 휠이다.
       * fit 을 걸면 종목을 바꿀 때마다 창이 「전체」로 풀려 300봉 기본값이 무의미해진다.
       * 창은 «봉이 실린 뒤에» 다시 센다 — 「5일」이 몇 봉인지는 데이터를 봐야 안다. */
      main.setData(rows);
      applySpan();
      applyFills();
      $('dtNote').textContent = rows.length
        ? rows.length + '봉 · ' + from + ' ~ ' + to + (state.live && isOpen() ? ' · LIVE' : '')
        : (DT_HASKEY ? '데이터가 없습니다 — 다음 수집(평일 16:45) 때 채워집니다'
                     : '데이터가 없습니다 — 키움 키를 넣으면 10거래일을 받아 옵니다');
    });
  }

  function drawSub(){
    if (!sub || !state.code) return;
    var u = subUnitOf(MAIN_UNIT);
    $('dtSubU').textContent = u + '분';
    sub.setCode(state.code);       // 수평선은 «종목»의 것이다 — 세 패널이 같은 선을 본다
    /* 보조 패널은 «맥락»(10거래일)이라 원장이 없으면 만들 수 없다 — 네이버는 당일치뿐이다.
       비워 두되 «왜 비었는지»를 적는다(빈 패널은 고장으로 읽힌다). */
    if (!state.owned) {
      state.subRows = [];
      sub.setData([], true);
      $('dtSubNote').textContent = '담으면 보입니다 — 10거래일 원장이 필요합니다';
      return;
    }
    var code = state.code, from = fromOf(10);
    $('dtSubNote').textContent = '불러오는 중…';
    /* ★메인과 <b>같이</b> 당일분을 얹는다 (2026-08-06).
     * 예전엔 여기가 false 로 못 박혀 있어서, 원장(dt_min)이 어제까지인 장중에는 보조만
     * <b>어제 종가에 멈춰</b> 있었다 — 메인은 14:30 을 가리키는데 보조는 전날 15:18 이었다.
     * 서버는 진작 되고 있었다: Dt::series() 가 당일분을 «합친 뒤» resample 하므로 3분봉도 붙는다.
     * 같은 종목이라 서버의 Dt::todayBars() 캐시를 메인과 나눠 써 콜은 늘지 않는다. */
    series(code, u, from, DT_TODAY, state.live).then(function(rows){   // ← isOpen() 안 건다(메인과 같은 이유)
      if (code !== state.code) return;
      state.subRows = rows;
      sub.setData(rows, true);
      applyFills();
      $('dtSubNote').textContent = rows.length ? rows.length + '봉 · ' + from + ' ~' : '데이터 없음';
    });
  }

  function noteDay(){
    if (!day) return;
    var r = day.range();
    $('dtDayNote').textContent = r ? (r.n + '거래일 · ' + r.from + ' ~ ' + r.to) : '데이터 없음';
  }

  /* 일봉은 «담기와 무관»하다 — 늘 기존 경로(action=daily)를 그때그때 읽는다.
     그래서 미리보기 종목도 일봉은 100% 그대로 보인다(갈리는 것은 분봉뿐이다). */
  function drawDay(){
    if (!day || !state.code) return;
    var code = state.code;
    $('dtDayNote').textContent = '불러오는 중…';
    day.setCode(code);                             // SUE 공시 마커는 모듈이 스스로 얹는다
    /* 1000영업일(≈4년)을 한 번에 — 480일·전체·96주 버튼이 재조회 없이 성립한다.
     * ★fit 하지 않는다: 보이는 구간의 주인은 기간 바다(fit 하면 종목을 바꿀 때마다 기간이 풀린다). */
    DailyChart.fetchDaily(code, 1000).then(function(rows){
      if (code !== state.code) return;             // 늦게 온 응답이 새 종목을 덮지 않게
      day.setData(rows);
      applyFills();
      noteDay();
    }).catch(function(){
      if (code === state.code) $('dtDayNote').textContent = '일봉을 불러오지 못했습니다.';
    });
  }

  // 세그먼트 버튼 공통
  function seg(id, attr, fn){
    var box = $(id);
    if (!box) return;
    box.addEventListener('click', function(e){
      var b = e.target.closest('button');
      if (!b) return;
      Array.prototype.forEach.call(box.querySelectorAll('button'), function(x){ x.classList.remove('on'); });
      b.classList.add('on');
      fn(b.getAttribute(attr), b);
    });
  }

  /* 옆 패널 접기 — 손잡이(가장자리 탭)와 도구모음 칩이 «한 함수»를 부른다.
   * 둘이 각자 상태를 만지면 하나가 반드시 거짓말을 한다(칩은 켜져 있는데 패널은 접혀 있는 식).
   * 상태의 주인은 «패널의 class» 하나고 나머지는 그걸 따라 그린다.
   * 기억은 localStorage — 시세가 아니라 «내 화면 취향»이라 오래 두어도 거짓이 되지 않는다
   * (차트의 기간·높이가 chart_pref 로 가는 것과 다르다: 저건 차트 모듈의 것, 이건 화면의 것). */
  function panel(id, hndId, chipId, key, onOpen){
    var el = $(id), hnd = $(hndId), tgl = chipId ? $(chipId) : null;
    if (!el || !hnd) return;
    function open(){ return !el.classList.contains('col'); }
    function set(on){
      el.classList.toggle('col', !on);
      hnd.textContent = on ? '◀' : '▶';
      if (tgl) tgl.classList.toggle('on', on);
      try { localStorage.setItem(key, on ? '1' : '0'); } catch(e){}
      if (on && onOpen) onOpen();
    }
    hnd.onclick = function(){ set(!open()); };
    if (tgl) tgl.onclick = function(){ set(!open()); };
    try { if (localStorage.getItem(key) === '0') set(false); } catch(e){}
  }

  // on/off 칩 (당일전고·현재가)
  function chip(id, fn){
    var b = $(id);
    if (!b) return;
    b.onclick = function(){
      var on = !b.classList.contains('on');
      b.classList.toggle('on', on);
      fn(on);
    };
  }

  DailyChart.load().then(function(){
    var ref = F('overlay.intraday_ref');
    /* 메인 분봉 — 1분 고정. 도구는 일봉과 같은 한 벌이되 «분봉에 뜻이 있는 것»만 붙인다:
     * 당일전고(분봉에서는 «오늘 고가»)·현재가·지표. 기간 바는 안 붙인다 —
     * 일봉/주봉 전환과 160/240일 같은 라벨이 1분봉 자리에선 거짓말이 된다. */
    main = DailyChart.create('dtMain', { theme:'dark', kind:'minute', multiDay:true,
                                         screen:'short', resize:false, key:'shortmin',
                                         curPrice: ref ? '#d9a441' : null,
                                         prevHigh: ref ? '#ff3b30' : null,
                                         legend: F('legend.values') ? 'dtMainLeg' : null });
    DailyChart.indicatorBar('dtMainIBar', main, { theme:'dark', key:'shortmin',
                                                 preset: F('preset.select') });
    chip('dtMCP', function(on){ main.setCurPrice(on); });
    /* 직전고가 — 오늘을 뺀 최고가(창을 따라가지 않는다). 종목을 바꿔도 켜 둔 상태는 남는다:
       setData 가 다시 그릴 때 모듈이 «그 종목의» 값으로 새로 잰다(칩을 다시 누를 필요가 없다). */
    chip('dtMPV', function(on){ main.setPrevHigh(on); });
    if (main) main.setPrevHigh(true);            // 칩이 기본 켜짐이라 선도 처음부터 그린다

    /* 보조 분봉 — 메인과 같은 도구를 갖되 차트틀·지표는 «따로» 기억한다(key:'shortsub').
     * 단위가 다르니(1분 ↔ 3분) 같은 변수를 얹으면 두 패널이 다른 뜻을 말한다.
     * 기간 바는 안 붙인다 — 단위·구간은 메인이 정한다(보조는 «한 단계 위 · 10거래일» 고정). */
    if ($('dtSub')) {
      sub = DailyChart.create('dtSub', { theme:'dark', kind:'minute', multiDay:true,
                                         screen:'', resize:false, key:'shortsub',
                                         legend: F('legend.values') ? 'dtSubLeg' : null });
      DailyChart.indicatorBar('dtSubIBar', sub, { theme:'dark', key:'shortsub',
                                                  preset: F('preset.select') });
    }
    /* 일봉 패널 — 다크 관찰용 한 벌. 차트틀·지표 키는 'short'(분봉 메인은 'shortmin').
     * ★2026-08-05 에 'updash' 에서 옮겨 왔다 — 그 이름을 쓰던 상승종목분석 화면이 삭제됐다.
     *   기간·높이는 «화면»이 따로 기억한다(chart_pref.view_json — 기간의 주인은 화면이다). */
    if ($('dtDay')) {
      day = DailyChart.create('dtDay', { theme:'dark', key:'short', screen:'', resize:false,
                                         todayHigh: ref,
                                         curPrice: ref ? '#d9a441' : null,
                                         markers: { chips: true, toggle: true },  // toggle — 기간 바에 「체결 N」 켬/끔 (포지션 상세와 같은 버튼)
                                         legend: F('legend.values') ? 'dtDayLeg' : null });
      DailyChart.periodBar('dtDayPBar', day, {
        theme:'dark', defaultIndex:0, fullscreen: F('fullscreen'),
        onChange: function(info){ $('dtDayLbl').textContent = info.tf === 'week' ? '주봉' : '일봉'; noteDay(); }
      });
      DailyChart.indicatorBar('dtDayIBar', day, { theme:'dark', key:'short',
                                                 preset: F('preset.select') });
      chip('dtTH', function(on){ day.setTodayHigh(on); });
      chip('dtCP', function(on){ day.setCurPrice(on); });
    }
    /* 십자선은 «분봉끼리만» 잇는다 — 일봉은 축 단위가 날짜라 이어 봐야 뜻이 없다 */
    if (main && sub) DailyChart.linkCrosshair([main, sub]);

    /* [당일]=300봉 · [5일]=최근 5거래일 · [전체]=실린 10거래일 전부. 데이터는 이미 다 실려 있으므로
     * 다시 받지 않고 «창만» 옮긴다(그 사이 배율은 휠로 — 모듈이 알아서 한다). */
    seg('dtSpan', 'data-m', function(m){
      state.span = m;
      applySpan();
    });
    var live = $('dtLive');
    if (live) {
      var LIVE_ON = 'LIVE ' + (DT_TICK / 1000) + '초';
      live.onclick = function(){
        state.live = !state.live;
        live.classList.toggle('off', !state.live);
        live.textContent = state.live ? LIVE_ON : 'LIVE 꺼짐';
        if (state.live) drawMain();
      };
      /* 장중에만 돈다. 장이 끝나면 타이머는 살아 있되 아무 것도 부르지 않는다 —
       * 「배지만 지우고 뒤에서 계속 부르면 왜 느리지가 된다」의 반대편 규칙이다. */
      setInterval(function(){
        if (!state.live || !state.code || !isOpen()) return;
        drawMain();
      }, DT_TICK);
      /* ★보조는 <b>느리게</b> 돈다 — 3분봉 한 칸은 3분에 한 번 닫힌다.
       * 메인과 같은 10초로 돌리면 화면만 바쁘고 값은 그대로다(같은 캐시를 다시 그릴 뿐).
       * 60초면 한 칸이 닫히기 전에 늘 따라잡는다. */
      setInterval(function(){
        if (!state.live || !state.code || !isOpen()) return;
        drawSub();
      }, 60000);
    }
    /* ★목록도 차트와 <b>같은 주기</b>로 돈다 (2026-08-06).
     * 예전엔 60초였는데, 그때 목록 시세는 all_stock_info 크론(장중 정규 fire 가 09·11·13·15시
     * 넷뿐)에 매여 있어서 아무리 자주 읽어도 <b>같은 값</b>이 왔다 — 60초는 헛돌기였고
     * 차트(30초)와 옆 목록이 최대 두 시간 어긋난 값을 나란히 말했다.
     * 이제 서버(module=dt 의 pool_list/held_list)가 응답 전에 Dt::refreshQuotesLive() 를 지나
     * <b>보는 종목만</b> 실시간으로 받아 오므로, 다시 읽는 것이 실제로 새 값을 뜻한다.
     *
     * ★탐색 패널(관심·시총)은 «첫 페이지를 보고 있을 때만» 새로 읽는다 — 뒤 페이지를 보는 중에
     *   갱신하면 커서가 움직여 읽던 자리가 사라진다(관심은 페이지가 없어 늘 첫 페이지다).
     * ★탐색 패널은 전종목 표(all_stock_info)를 읽는 목록이라 <b>실시간 갱신 대상이 아니다</b>
     *   (크론 주기 그대로). 거기까지 촘촘히 받으면 28콜 × 10초 = 하루 1만 콜이 된다(CRON.md §4). */
    setInterval(function(){
      /* ★state.live 를 함께 본다 — 「LIVE 꺼짐」은 이제 차트뿐 아니라 <b>목록 시세</b>도 뜻한다
       *   (배지 설명이 그렇게 적혀 있다). 끄고도 뒤에서 콜이 나가면 그 글자가 거짓이 된다. */
      if (!state.live || !isOpen()) return;   // 장 밖에서는 값이 안 변한다 — 서버도 같은 판정으로 막는다
      loadPool(true).then(fillHeld);
    }, DT_TICK);
    /* ★느린 시계는 그대로 둔다 — 하는 일이 다르다.
     *   빠른 시계(위): 시세를 새로 «받는다» · 장중에만
     *   느린 시계(아래): 목록의 «구성»이 바뀐 것을 잡는다 — 화면을 켜 둔 채 새로 편입한 종목이
     *   여기서 나타난다(heldTried 가 되풀이를 막는다). 장 밖에서도 돌아야 하는 이유가 그것이다.
     *   탐색 패널(관심·시총)은 전종목 표(크론 주기)를 읽는 목록이라 촘촘히 할 값어치가 없다. */
    setInterval(function(){
      if (!state.live || !isOpen()) loadPool(true).then(fillHeld);
      if (topStart === 0) loadTop(null, 0);
    }, 60000);

    loadPool(false).then(fillHeld);
    loadTop(null, 0);
  });
})();
</script>
JS;
    echo '</body></html>';
}
?>
