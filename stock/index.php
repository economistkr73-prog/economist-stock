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

$pf = new Pf($pdo);
$pf->ensureTables();
$pf->seedRuleSets();
$pf->seedPortfolio();
$pf->seedMarkets();

$mode = $_GET['mode'] ?? 'dashboard';

$routes = [
    'dashboard' => 'pf_page_dashboard',   // 포트폴리오 목록
    'folio'     => 'pf_page_folio',       // 포트폴리오 상세 (종목 목록)
    'position'  => 'pf_page_position',    // 종목 상세
    'sim'       => 'pf_page_sim',         // 백테스트 시뮬레이터
    'fund'      => 'pf_page_fund',        // 재무분석 (DART 스크리너)
    'portfolio' => 'pf_page_portfolio',   // 설정 > 포트폴리오
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
        ['key' => 'dashboard', 'href' => '/stock/index.php',              'label' => '대시보드'],
        ['key' => 'sim',       'href' => '/stock/index.php?mode=sim',     'label' => '시뮬레이터'],
        ['key' => 'fund',      'href' => '/stock/index.php?mode=fund',    'label' => '재무분석'],
        ['key' => 'setting',   'href' => '/stock/index.php?mode=setting', 'label' => '설정'],
    ];
}

/** 설정 하위 메뉴 */
function pf_sub_menus(): array
{
    return [
        ['key' => 'portfolio', 'href' => '/stock/index.php?mode=portfolio', 'label' => '포트폴리오 추가'],
        ['key' => 'ruleset',   'href' => '/stock/index.php?mode=ruleset',   'label' => '룰셋 설정'],
        ['key' => 'fee',       'href' => '/stock/index.php?mode=setting',   'label' => '수수료 설정'],
    ];
}

function pf_subtabs(string $active): void
{
    echo '<div class="pf-sub">';
    foreach (pf_sub_menus() as $m) {
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
    echo '</head><body>';
    pf_topbar($active);
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
.cl-item b{font-variant-numeric:tabular-nums;color:#22303f}
.warn{background:#fff8e6;border:1px solid #f3dfa8;color:#8a6d1f;padding:9px 12px;border-radius:8px;font-size:13px;font-weight:600;margin-bottom:12px}
.err{background:#fdecea;border:1px solid #f5c6c0;color:#b3261e;padding:9px 12px;border-radius:8px;font-size:13px;font-weight:600;margin-bottom:12px}
.ok{background:#e9f6ec;border:1px solid #b9e0c3;color:#1e6b33;padding:9px 12px;border-radius:8px;font-size:13px;font-weight:600;margin-bottom:12px}

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
 * @return array [positions, calc(position_id => 계산결과)]
 */
function pf_load_calc(Pf $pf, ?int $folioId = null, bool $showClosed = false): array
{
    $positions = $pf->positions($folioId);
    if (!$showClosed) {
        $positions = array_values(array_filter($positions, fn($p) => $p['status'] !== 'closed'));
    }

    $stepsMap  = $pf->ruleStepsMap(array_column($positions, 'rule_set_id'));
    $tradesMap = $pf->tradesMap(array_column($positions, 'id'));
    $feeMap    = $pf->brokerFeesMap(array_column($positions, 'broker_id'));

    $calc = [];
    foreach ($positions as $p) {
        $steps = $stepsMap[(int)$p['rule_set_id']] ?? [];
        $rows  = $tradesMap[(int)$p['id']] ?? [];
        $last  = ($p['last_price'] !== null) ? (float)$p['last_price'] : null;
        // 증권사 수수료(구간 or 단일요율) + 시장 세율
        $prm   = pf_cost_params($p, $feeMap[(int)$p['broker_id']] ?? []);
        $calc[(int)$p['id']] = $steps
            ? pf_position_calc($steps, pf_trades_by_step($rows), (float)$p['limit_amt'], $last, $prm, pf_ledger($rows, $prm))
            : null;
    }
    return [$positions, $calc];
}

/** 계산결과 묶음의 합계 */
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

    [$positions, $calc] = pf_load_calc($pf, null, $showClosed);

    $byFolio = [];
    foreach ($positions as $p) $byFolio[(int)$p['portfolio_id']][] = $p;

    pf_head('대시보드', 'dashboard', 'wide');

    $tPrincipal = 0.0;
    foreach ($folios as $f) $tPrincipal += (float)$f['principal'];

    $t      = pf_sum_calc($calc);
    $tCash  = $tPrincipal + $t['flow'];   // 예수금 = 원금 − 매수지출 + 매도수취
    $tAsset = $tCash + $t['net'];         // 추정자산 = 예수금 + 보유종목 현재가치
    $tRate  = ($tPrincipal > 0) ? ($tAsset / $tPrincipal - 1) : null;

    // ── 좌우 컬럼. 오른쪽 상세는 맨 위부터 시작하는 독립 영역이다.
    echo '<div class="split">';
    echo '<div class="split-left">';
    pf_flash();

    echo '<div class="pf-head"><div>';
    echo '<h1>대시보드</h1>';
    echo '<div class="sub">왼쪽에서 포트폴리오를 고르면 오른쪽에 담긴 종목과 매수·매도 신호가 나옵니다.</div>';
    echo '</div><div class="act">';
    echo '<a class="btn btn-outline" href="/stock/index.php?mode=portfolio">포트폴리오 관리</a>';
    $closedHref = '/stock/index.php?id=' . $selId . ($showClosed ? '' : '&closed=1');
    echo '<a class="btn btn-outline" href="' . $closedHref . '">' . ($showClosed ? '종료 숨기기' : '종료 보기') . '</a>';
    echo '</div></div>';

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
        $rows   = $byFolio[$fid] ?? [];
        $sub    = pf_sum_calc(array_intersect_key($calc, array_flip(array_column($rows, 'id'))));
        $prin   = (float)$f['principal'];
        $sCash  = $prin + $sub['flow'];
        $sAsset = $sCash + $sub['net'];
        $sRate  = ($prin > 0) ? ($sAsset / $prin - 1) : null;

        $cls = 'folio-row' . ($fid === $selId ? ' on' : '') . ((int)$f['is_active'] ? '' : ' watch');
        echo '<tr class="' . $cls . '" data-id="' . $fid . '">';

        echo '<td><a href="/stock/index.php?id=' . $fid . ($showClosed ? '&closed=1' : '') . '">'
           . '<b>' . pf_h($f['name']) . '</b></a>';
        if (!(int)$f['is_active']) echo ' <span class="badge st-closed">미사용</span>';
        $ident = array_filter([$f['broker'], $f['acct_no']]);
        if ($ident) echo '<br><span class="muted" style="font-size:11px">' . pf_h(implode(' ', $ident)) . '</span>';
        echo '</td>';

        echo '<td class="num">' . pf_n($prin) . '</td>';
        echo '<td class="num' . ($sCash < 0 ? ' down' : '') . '">' . pf_n(round($sCash)) . '</td>';
        echo '<td class="num">' . pf_n(round($sub['cost'])) . '</td>';
        echo '<td class="num">' . count($rows) . '</td>';
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
    echo '<td class="num">' . count($positions) . '</td>';
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

    [$rows, $calc] = pf_load_calc($pf, $fid, $showClosed);

    $sub    = pf_sum_calc($calc);
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

    if (!$rows) {
        echo '<div class="folio-empty">담긴 종목이 없습니다. '
           . '<a href="' . $addUrl . '">이 포트폴리오에 종목 추가</a>하면 차수·다음매수가가 자동 계산됩니다.</div>';
        echo '</div>';
        return;
    }

    echo '<div class="tbl-scroll"><table class="pf"><thead><tr>';
    foreach ([
        ['종목명', ''], ['차수', 'num'], ['수익률', 'num'], ['현재가', 'num'],
        ['보유수량', 'num'], ['평가금액', 'num'], ['평가손익', 'num'], ['실현손익', 'num'],
        ['다음매수가', 'num'], ['다음수량', 'num'], ['누적단가', 'num'], ['자동매도가', 'num'],
    ] as [$label, $cls]) {
        echo '<th class="' . $cls . '">' . pf_h($label) . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($rows as $p) {
        $pid   = (int)$p['id'];
        $c     = $calc[$pid];
        $last  = ($p['last_price'] !== null) ? (float)$p['last_price'] : null;
        $watch = ($p['status'] !== 'open');

        echo '<tr class="' . ($watch ? 'watch' : '') . '">';

        echo '<td><a href="/stock/index.php?mode=position&id=' . $pid . '">' . pf_h($p['stock_name']) . '</a> ';
        echo '<span class="muted">' . pf_h($p['stock_code']) . '</span> ' . pf_status_badge($p['status']) . '</td>';

        if (!$c) {
            echo '<td colspan="11" class="muted">룰셋에 차수가 없습니다 — '
               . '<a href="/stock/index.php?mode=ruleset">룰셋 설정</a></td></tr>';
            continue;
        }

        echo '<td class="num">' . ($c['cur_step'] > 0 ? $c['cur_step'] . '차' : '#') . '</td>';
        echo '<td class="num">' . pf_signed_pct($c['rate']) . '</td>';
        echo '<td class="num">' . pf_n($last) . '</td>';
        echo '<td class="num">' . pf_n($c['filled_qty'] ?: null) . '</td>';
        echo '<td class="num">' . pf_n($c['eval_amount'] === null ? null : round($c['eval_amount'])) . '</td>';
        echo '<td class="num">' . pf_signed($c['eval_pl']) . '</td>';
        echo '<td class="num">' . (abs((float)$c['realized_pl']) > 0.5 ? pf_signed($c['realized_pl']) : '<span class="flat">-</span>') . '</td>';

        // 매수 구간이면 현재가 기준 수량(급락 시 여러 차수 합산), 아니면 이론가 기준 계획수량
        $buyCls = $c['buy_signal'] ? ' class="num hit"' : ' class="num"';
        echo '<td' . $buyCls . '><span>' . pf_n($c['next_price']) . '</span></td>';
        $showQty = $c['buy_signal'] ? $c['buy_qty'] : $c['next_qty'];
        echo '<td' . $buyCls . '><span>' . pf_n($showQty ?: null) . '</span></td>';
        echo '<td class="num">' . pf_n($c['avg_cost'] === null ? null : round($c['avg_cost'])) . '</td>';

        $sellCls = $c['sell_signal'] ? ' class="num hit"' : ' class="num"';
        echo '<td' . $sellCls . '><span>' . pf_n($c['sell_price']) . '</span></td>';
        echo '</tr>';
    }

    echo '</tbody><tfoot><tr>';
    echo '<td>소계</td><td colspan="4" class="num muted">다음 차수 소요 ' . pf_n(round($sub['reserve'])) . '</td>';
    echo '<td class="num">' . pf_n(round($sub['eval'])) . '</td>';
    echo '<td class="num">' . pf_signed($sub['pl']) . '</td>';
    echo '<td class="num">' . (abs($sub['real']) > 0.5 ? pf_signed($sub['real']) : '') . '</td>';
    echo '<td colspan="4"></td>';
    echo '</tr></tfoot></table></div>';
    echo '</div>';   // .fd-body
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
    $c        = $steps ? pf_position_calc($steps, $trades, (float)$pos['limit_amt'], $last, $prm, $ledger) : null;

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

    // 이 종목의 시세를 시뮬레이터에 올려 뒀으면 그 데이터로 바로 열어 준다
    $simD = $pf->simDataFindByCode((string)$pos['stock_code']);
    if ($simD) {
        echo '<a class="btn btn-outline" title="' . pf_h($simD['from_date'] . ' ~ ' . $simD['to_date']) . '" '
           . 'href="/stock/index.php?mode=sim&id=' . (int)$simD['id'] . '&go=1'
           . '&rs=' . (int)$pos['rule_set_id'] . '&limit=' . (int)$pos['limit_amt'] . '">📊 시뮬레이션</a>';
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

    // 요약 카드
    $cards = [
        ['현재가',     pf_n($last), ''],
        ['최고가',     pf_n($high), ''],
        ['변동율',     $draw === null ? '-' : pf_pct($draw), pf_updown($draw)],
        ['현재차수',   $c['cur_step'] > 0 ? $c['cur_step'] . '차' : '미진입', ''],
        ['보유수량',   pf_n($c['filled_qty'] ?: null), ''],
        ['누적단가',   pf_n($c['avg_cost'] === null ? null : round($c['avg_cost'])), ''],
        ['수익률',     $c['rate'] === null ? '-' : pf_pct($c['rate']), pf_updown($c['rate'])],
        ['평가손익',   pf_n($c['eval_pl'] === null ? null : round($c['eval_pl'])), pf_updown($c['eval_pl'])],
    ];
    if ((int)$c['sold_qty'] > 0) {
        $cards[] = ['실현손익', pf_n(round($c['realized_pl'])), pf_updown($c['realized_pl'])];
        $cards[] = ['총손익',   pf_n(round($c['total_pl'])),    pf_updown($c['total_pl'])];
    }
    $cards[] = ['자동매도가', pf_n($c['sell_price']), ''];

    echo '<div class="sum-grid">';
    foreach ($cards as [$k, $v, $cls]) {
        echo '<div class="sum-box"><div class="k">' . pf_h($k) . '</div>';
        echo '<div class="v ' . $cls . '">' . pf_h($v) . '</div></div>';
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

    echo '<div style="display:grid;grid-template-columns:1.35fr 1fr;gap:14px" class="pf-detail-grid">';

    // ── 차수 사다리
    // 매도가 있으면 차수별 수량·금액을 안분된 "보유" 기준으로 보여준다
    $hasSold = ((int)$c['sold_qty'] > 0);
    $qtyLabel = $hasSold ? '보유수량' : '매수수량';
    $amtLabel = $hasSold ? '보유원가' : '매수금액';

    echo '<div class="card"><h2>차수 사다리 '
       . '<a class="muted" style="font-weight:600;font-size:12px" '
       . 'href="/stock/index.php?mode=ruleset&rid=' . (int)$pos['rule_set_id'] . '" '
       . 'title="이 룰셋 설정 열기">' . pf_h($pos['rule_name']) . '</a></h2>';
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
    $labels = [];
    $theory = [];
    $actual = [];
    $avgRun = [];
    foreach ($c['steps'] as $n => $s) {
        $labels[] = $n . '차';
        $theory[] = $s['theory_price'];
        $actual[] = $s['traded'] ? round((float)$s['price']) : null;   // 미체결 차수는 null
        $avgRun[] = ($s['avg_cost_upto'] === null) ? null : round((float)$s['avg_cost_upto']);
    }
    echo '<div class="card"><h2>차수별 이론가</h2><div class="chart-box"><canvas id="pfChart"></canvas></div></div>';
    echo '</div>';

    $held = (int)$c['filled_qty'];

    // ── 일봉 차트 (기존 stock_analysis_api.php 의 daily 엔드포인트 재사용)
    echo '<div class="card"><div class="pf-head" style="margin-bottom:10px"><div>';
    echo '<h2 style="margin:0">일봉 차트</h2>';
    echo '<div class="sub">누적단가·자동매도가·다음매수가를 가격선으로, 체결 지점을 마커로 표시합니다.</div>';
    echo '</div><div class="act">';
    echo '<button type="button" class="btn btn-outline btn-sm" id="pfD160" onclick="pfLoadDaily(160)">160일</button>';
    echo '<button type="button" class="btn btn-outline btn-sm" id="pfD240" onclick="pfLoadDaily(240)">240일</button>';
    echo '</div></div>';
    // 가격선 범례 — 차트 위에 글자를 얹지 않고 여기서 설명한다
    echo '<div class="chart-legend">';
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

    // 차트에 얹을 체결 마커
    $marks = [];
    foreach ($tradeRow as $t) {
        $isSell = ($t['side'] === 'sell');
        $marks[] = [
            'time' => $t['traded_at'],
            'sell' => $isSell,
            'text' => ($isSell ? '매도' : (int)$t['step_no'] . '차')
                     . ' ' . number_format((int)$t['qty']) . '주 @' . number_format(round((float)$t['price'])),
        ];
    }

    echo '<script src="https://unpkg.com/lightweight-charts@4.1.3/dist/lightweight-charts.standalone.production.js"></script>';
    echo '<script>';
    echo 'const PF_CODE=' . json_encode($pos['stock_code']) . ';';
    echo 'const PF_MARKS=' . json_encode($marks, JSON_UNESCAPED_UNICODE) . ';';
    echo 'const PF_PLINES=' . json_encode([
        ['v' => $c['avg_cost']   === null ? null : round($c['avg_cost']),  'c' => '#1e9e74', 't' => '누적단가',   'd' => 0],
        ['v' => $c['sell_price'] === null ? null : (float)$c['sell_price'],'c' => '#d32f2f', 't' => '자동매도가', 'd' => 1],
        ['v' => $c['next_price'] === null ? null : (float)$c['next_price'],'c' => '#1d5c93', 't' => '다음매수가', 'd' => 1],
    ], JSON_UNESCAPED_UNICODE) . ';';

    echo <<<'JS'
(function(){
  var host = document.getElementById('pfDailyChart');
  var note = document.getElementById('pfDailyNote');
  if (!host || !window.LightweightCharts) { if (note) note.textContent = '차트 라이브러리를 불러오지 못했습니다.'; return; }

  var LWC  = LightweightCharts;
  var UP   = '#d32f2f';   // 한국식 — 상승 빨강
  var DOWN = '#1565c0';   //          하락 파랑

  var chart = LWC.createChart(host, {
    width: host.clientWidth, height: host.clientHeight,
    layout: { background: { color: '#fff' }, textColor: '#5f7183', fontFamily: 'Pretendard' },
    grid:   { vertLines: { color: '#f0f4f8' }, horzLines: { color: '#f0f4f8' } },
    rightPriceScale: { borderColor: '#e3eaf0' },
    timeScale: {
      borderColor: '#e3eaf0', timeVisible: false, secondsVisible: false,
      tickMarkFormatter: function(t){
        if (typeof t === 'string') { var p = t.split('-'); return (+p[1]) + '/' + (+p[2]); }
        if (t && t.year) return t.month + '/' + t.day;
        return '';
      }
    },
    crosshair: { mode: LWC.CrosshairMode.Normal },
    localization: {
      timeFormatter: function(t){
        if (typeof t === 'string') { var p = t.split('-'); return (+p[0]) + '년 ' + (+p[1]) + '월 ' + (+p[2]) + '일'; }
        if (t && t.year) return t.year + '년 ' + t.month + '월 ' + t.day + '일';
        return '';
      },
      priceFormatter: function(v){ return Math.round(v).toLocaleString(); }
    }
  });

  var candle = chart.addCandlestickSeries({
    upColor: UP, downColor: DOWN, borderUpColor: UP, borderDownColor: DOWN,
    wickUpColor: UP, wickDownColor: DOWN, priceLineVisible: false
  });
  var vol = chart.addHistogramSeries({ priceFormat: { type: 'volume' }, priceScaleId: 'vol' });
  chart.priceScale('vol').applyOptions({ scaleMargins: { top: 0.82, bottom: 0 } });

  // 누적단가 · 자동매도가 · 다음매수가 가로선
  // title 은 차트 안쪽에 글자를 그려 캔들을 가리므로 쓰지 않는다 (설명은 위 범례로)
  PF_PLINES.forEach(function(L){
    if (L.v === null) return;
    candle.createPriceLine({
      price: L.v, color: L.c, lineWidth: 2,
      lineStyle: L.d ? LWC.LineStyle.Dashed : LWC.LineStyle.Solid,
      axisLabelVisible: true
    });
  });

  // ── 체결 라벨
  // lightweight-charts 의 마커 text 는 배경 없이 글자만 그린다(색=마커색).
  // 매수 빨강칩 / 매도 파랑칩 + 흰 글씨로 보이게 하려고 HTML 레이어를 따로 얹는다.
  var mLayer = document.createElement('div');
  mLayer.className = 'pf-mk-layer';
  host.appendChild(mLayer);

  var mData = [];   // {time, text, sell, hi, lo}
  var rafId = 0;

  function pfDrawMarks(){
    mLayer.textContent = '';
    if (!mData.length) return;

    var ts = chart.timeScale();
    var w  = host.clientWidth;
    var placed = { 0: [], 1: [] };   // 위/아래로 나눠 겹침만 피한다

    mData.forEach(function(m){
      var x = ts.timeToCoordinate(m.time);
      var y = candle.priceToCoordinate(m.sell ? m.hi : m.lo);
      if (x === null || y === null) return;

      var half = m.text.length * 3.6 + 9;          // 대략적인 반폭 (겹침 판정용)
      if (x + half < 0 || x - half > w) return;

      var side = m.sell ? 1 : 0;
      var step = m.sell ? -20 : 20;
      var top  = y + (m.sell ? -32 : 13);
      for (var i = 0; i < 5; i++) {
        var hit = placed[side].some(function(p){
          return Math.abs(p.x - x) < (p.half + half) && Math.abs(p.top - top) < 19;
        });
        if (!hit) break;
        top += step;
      }
      placed[side].push({ x: x, half: half, top: top });

      var el = document.createElement('div');
      el.className   = 'pf-mk ' + (m.sell ? 'sell' : 'buy');
      el.textContent = m.text;
      el.style.left  = x + 'px';
      el.style.top   = top + 'px';
      mLayer.appendChild(el);
    });
  }

  function pfMarksSoon(){
    if (rafId) return;
    rafId = requestAnimationFrame(function(){ rafId = 0; pfDrawMarks(); });
  }

  chart.timeScale().subscribeVisibleLogicalRangeChange(pfMarksSoon);
  // 가격축을 드래그해 배율이 바뀔 때도 따라오도록 (범위 변경 이벤트가 안 뜬다)
  ['mousemove', 'mouseup', 'wheel', 'touchmove', 'touchend'].forEach(function(ev){
    host.addEventListener(ev, pfMarksSoon, { passive: true });
  });

  window.addEventListener('resize', function(){
    chart.applyOptions({ width: host.clientWidth });
    pfMarksSoon();
  });

  window.pfLoadDaily = function(days){
    ['pfD160','pfD240'].forEach(function(id){
      var b = document.getElementById(id);
      if (b) b.classList.toggle('btn-primary', id === 'pfD' + days);
    });
    note.textContent = '불러오는 중…';

    fetch('/stock_analysis_api.php?module=stock&action=daily&code=' + encodeURIComponent(PF_CODE) + '&days=' + days,
          { credentials: 'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(raw){
        if (!Array.isArray(raw) || !raw.length) {
          note.textContent = '일봉 데이터를 가져오지 못했습니다 (종목코드 ' + PF_CODE + ').';
          return;
        }
        // API 는 {t,o,h,l,c,v} 로 준다 → lightweight-charts 형식으로 변환
        var rows = raw.map(function(x){
          return { time: x.t, open: x.o, high: x.h, low: x.l, close: x.c, vol: x.v };
        });

        candle.setData(rows.map(function(d){
          return { time: d.time, open: d.open, high: d.high, low: d.low, close: d.close };
        }));
        vol.setData(rows.map(function(d){
          return { time: d.time, value: d.vol, color: (d.close >= d.open ? UP : DOWN) + '55' };
        }));

        // 차트 구간 안에 있는 체결만 마커로 (오래된 체결은 구간 밖이라 안 보인다)
        var from = rows[0].time, to = rows[rows.length - 1].time;
        var byTime = {};
        rows.forEach(function(d){ byTime[d.time] = d; });

        var hits = PF_MARKS.filter(function(m){ return m.time >= from && m.time <= to; });

        // 화살표는 라이브러리에, 글자는 아래 HTML 레이어에 맡긴다 (text 를 비워 중복 방지)
        candle.setMarkers(hits.map(function(m){
          return {
            time: m.time,
            position: m.sell ? 'aboveBar' : 'belowBar',
            color:    m.sell ? DOWN : UP,
            shape:    m.sell ? 'arrowDown' : 'arrowUp',
            text:     ''
          };
        }));

        mData = hits.map(function(m){
          var r = byTime[m.time] || {};
          return { time: m.time, text: m.text, sell: m.sell, hi: r.high, lo: r.low };
        }).filter(function(m){ return m.hi !== undefined; });

        chart.timeScale().fitContent();
        pfMarksSoon();

        note.textContent = rows.length + '거래일 (' + from + ' ~ ' + to + ') · 체결 마커 '
          + hits.length + '개';
      })
      .catch(function(e){
        console.error('[pf daily]', e);
        note.textContent = '일봉 데이터를 불러오지 못했습니다. (' + e + ')';
      });
  };

  pfLoadDaily(160);
})();
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
    echo '<label class="fld">체결가<input type="number" id="pfmBuyPrice" name="price" step="0.01" min="0" style="width:120px" required></label>';
    echo '<label class="fld">수량<input type="number" id="pfmBuyQty" name="qty" min="1" style="width:110px" required></label>';
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
    echo '<label class="fld">매도가<input type="number" id="pfmSellPrice" name="price" step="0.01" min="0" style="width:120px" required></label>';
    echo '<label class="fld">수량<input type="number" id="pfmSellQty" name="qty" min="1" max="' . $held . '" style="width:110px" required></label>';
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
    $sellDefault = ($c['sell_price'] !== null && $last !== null && $last >= $c['sell_price'])
        ? $c['sell_price'] : $last;

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

function pfN(v){ return (v === null || v === undefined) ? '-' : Math.round(v).toLocaleString(); }

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
  document.getElementById('pfmBuyPrice').value = (basePrice !== null) ? basePrice : '';
  document.getElementById('pfmBuyQty').value   = (q > 0) ? q : '';
  document.getElementById('pfmBuyNote').textContent = (s.need > 0)
    ? '누적목표 ' + pfN(s.planCum) + ' − 투입 ' + pfN(PF_POS.used) + ' = ' + pfN(s.need)
      + '원을 ' + pfN(basePrice) + '원으로 나눠 ' + q.toLocaleString() + '주. 실제 체결값으로 덮어쓰면 다시 계산됩니다.'
    : '이 차수의 누적목표는 이미 채웠습니다. 추가로 담으면 다음 차수 매수액이 줄어듭니다.';

  // ── 매도 탭은 그 차수에 보유분이 있을 때만
  var canSell = !!(s.traded && s.held > 0 && PF_POS.held > 0);
  document.getElementById('pfmTabs').style.display = canSell ? '' : 'none';

  if (canSell) {
    document.getElementById('pfmSellPrice').value = (PF_POS.sellDef !== null) ? PF_POS.sellDef : '';
    document.getElementById('pfmSellQty').value   = s.held;
    document.getElementById('pfmSellQty').max     = PF_POS.held;

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
  document.getElementById('pfmSellQty').value = Math.max(1, Math.min(q, PF_POS.held));
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
  var q  = parseInt(document.getElementById('pfmBuyQty').value, 10) || 0;
  var pr = parseFloat(document.getElementById('pfmBuyPrice').value) || 0;
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
  var q  = parseInt(document.getElementById('pfmSellQty').value, 10) || 0;
  var pr = parseFloat(document.getElementById('pfmSellPrice').value) || 0;
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
  if (e.target.id === 'pfmBuyQty'  || e.target.id === 'pfmBuyPrice')  pfBuyCalc();
  if (e.target.id === 'pfmSellQty' || e.target.id === 'pfmSellPrice') pfSellCalc();
});
document.addEventListener('keydown', function(e){
  if (e.key === 'Escape') pfCloseModal();
});
document.getElementById('pfmSell').addEventListener('submit', function(e){
  var q = parseInt(document.getElementById('pfmSellQty').value, 10) || 0;
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
.qbtns{display:flex;gap:4px}
.sell-preview{margin-top:12px}
.sell-preview:empty{display:none}
.sp-row{display:flex;align-items:baseline;gap:10px;padding:5px 12px;font-size:13px;border-bottom:1px dashed #e6edf4}
.sp-row:first-child{border-top:1px solid #e6edf4}
.sp-k{width:96px;color:#7d8b99;font-weight:700;font-size:12px}
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

/* 일봉 위 체결 라벨 — 매수 빨강칩 / 매도 파랑칩, 글자는 흰색 */
.pf-mk-layer{position:absolute;inset:0;overflow:hidden;pointer-events:none;z-index:2}
.pf-mk{position:absolute;transform:translateX(-50%);white-space:nowrap;
  font-size:11px;font-weight:800;line-height:1.35;color:#fff;padding:2px 6px;border-radius:5px;
  font-variant-numeric:tabular-nums;box-shadow:0 1px 3px rgba(10,25,45,.28)}
.pf-mk.buy{background:#d32f2f}
.pf-mk.sell{background:#1565c0}

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
       . '<input type="number" name="last_price" step="0.01" min="0" style="width:120px" value="'
       . pf_h(($pos['last_price'] ?? null) === null ? '' : (float)$pos['last_price']) . '"></label>';

    echo '<label class="fld">최고가<input type="number" name="high_price" step="0.01" min="0" style="width:120px" value="'
       . pf_h(($pos['high_price'] ?? null) === null ? '' : (float)$pos['high_price']) . '"></label>';

    if (!$isNew && ($pos['priced_at'] ?? '') !== '') {
        echo '<div class="fld"><span>시세 갱신시각</span><span class="muted" style="font-size:13px;padding:6px 0">'
           . pf_h($pos['priced_at']) . '</span></div>';
    }
    echo '</div>';

    echo '<button class="btn btn-primary" type="submit">저장</button> ';
    if (!$isNew) {
        echo '<a class="btn btn-outline" href="/stock/index.php?mode=position&id=' . (int)$pos['id'] . '">취소</a>';
    }
    echo '</form></div>';

    // ── 종목 자동완성 + 금액 콤마 + 한도 배수
    echo '<script>const PF_PRINCIPAL=' . json_encode($principal) . ';</script>';
    echo <<<'JS'
<script>
function pfComma(el){
  var d = el.value.replace(/[^\d]/g, '');
  el.value = d ? Number(d).toLocaleString() : '';
}
document.addEventListener('input', function(e){
  if (!e.target.classList || !e.target.classList.contains('num-comma')) return;
  pfComma(e.target);
  if (e.target.id === 'limitAmt') pfSyncMult();
});
document.querySelectorAll('.num-comma').forEach(pfComma);

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
</script>
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
function pf_page_ruleset(PDO $pdo, Pf $pf): void
{
    $sets = $pf->ruleSets();
    $sel  = (int)($_GET['rid'] ?? ($sets[0]['id'] ?? 0));
    $rs   = $sel ? $pf->ruleSetGet($sel) : null;

    // 시뮬레이션 한도 (미리보기용)
    $simLimit = (float)($_GET['limit'] ?? 200000000);

    pf_head('룰셋 설정', 'setting', 'narrow');
    pf_subtabs('ruleset');
    pf_flash();

    echo '<div class="pf-head"><div><h1>룰셋 설정</h1>';
    echo '<div class="sub">하락률은 <b>직전 차수 대비 단계 하락률</b>입니다 (최초가 대비 누적 아님).</div>';
    echo '</div><div class="act">';
    echo '<form class="inline" method="post" action="/stock/api.php?module=ruleset&action=create">';
    echo '<button class="btn btn-outline" type="submit">＋ 새 룰셋</button></form>';
    echo '</div></div>';

    echo '<div class="card"><h2>룰셋 목록</h2><div class="tbl-scroll"><table class="pf" id="ruleTbl"><thead><tr>';
    echo '<th class="center" style="width:34px">순서</th>';
    echo '<th>이름</th><th class="num">변동율</th><th class="num">차수</th><th class="num">비중합</th>';
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
        echo '<td class="num">' . ($s['volatility'] === null ? '-' : pf_n2($s['volatility'], 1) . '%') . '</td>';
        echo '<td class="num">' . (int)$s['step_count'] . '</td>';
        echo '<td class="num">' . pf_h(pf_pct0($sum, 1)) . '</td>';
        echo '<td class="num">' . (int)$s['pos_count'] . '</td>';
        echo '<td>' . pf_h($s['memo']) . '</td>';
        echo '<td class="right">';
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

    if (!$rs) { pf_foot(); return; }

    // ── 룰셋 편집
    $steps = $rs['steps'];
    $sum   = pf_weight_sum($steps);

    echo '<div class="card"><h2>' . pf_h($rs['name']) . ' — 차수 편집</h2>';

    echo '<form method="post" action="/stock/api.php?module=ruleset&action=save">';
    echo '<input type="hidden" name="id" value="' . (int)$rs['id'] . '">';
    echo '<div class="fld-row" style="margin-bottom:12px">';
    echo '<label class="fld">이름<input type="text" name="name" style="width:220px" value="' . pf_h($rs['name']) . '" required></label>';
    echo '<label class="fld">변동율(%)<input type="number" name="volatility" step="0.01" style="width:100px" value="'
       . pf_h($rs['volatility']) . '"></label>';
    echo '<label class="fld">메모<input type="text" name="memo" style="width:280px" value="' . pf_h($rs['memo']) . '"></label>';
    echo '<button class="btn btn-outline" type="submit">이름/메모 저장</button>';
    echo '</div></form>';

    if (abs($sum - 1.0) > 0.0001) {
        echo '<div class="warn">비중 합계가 <b>' . pf_h(pf_pct0($sum, 2)) . '</b> 입니다 (100% '
           . ($sum > 1 ? '초과' : '미달') . '). 경고만 표시하며 강제하지 않습니다.</div>';
    }

    echo '<form method="post" action="/stock/api.php?module=ruleset&action=steps" id="stepForm">';
    echo '<input type="hidden" name="rule_set_id" value="' . (int)$rs['id'] . '">';
    echo '<div class="tbl-scroll"><table class="pf" id="stepTbl"><thead><tr>';
    echo '<th class="num">차수</th><th class="num">비중(%)</th><th class="num">누적비중</th>';
    echo '<th class="num">단계하락률(%)</th><th class="num">최초대비</th>';
    echo '<th class="num">목표수익률(%)</th><th class="num">금액</th><th class="num">누적금액</th>';
    echo '<th class="num">손익분기율</th><th></th></tr></thead><tbody>';

    $sim  = $steps ? pf_simulate($steps, $simLimit) : [];
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
        echo '<td class="num c-cumd muted">'
           . ($pfac === null ? '-' : number_format(($pfac - 1) * 100, 1) . '%') . '</td>';
        echo '<td class="num"><input type="number" class="w-target" name="target_rate[]" step="0.0001" style="width:90px" value="'
           . pf_h(round($s['target_rate'] * 100, 4)) . '"></td>';
        echo '<td class="num c-amt">' . pf_mil($sim[$n]['amount'] ?? null) . '</td>';
        echo '<td class="num c-cum">' . pf_mil($sim[$n]['cum_amount'] ?? null) . '</td>';
        echo '<td class="num c-be">' . (isset($sim[$n]) ? pf_signed_pct($sim[$n]['breakeven_rate'], 2) : '-') . '</td>';
        echo '<td class="right"><button type="button" class="btn btn-danger btn-sm" onclick="pfDelRow(this)">삭제</button></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';

    echo '<div class="fld-row" style="margin-top:11px">';
    echo '<button class="btn btn-primary" type="submit">차수 저장</button>';
    echo '<button class="btn btn-outline" type="button" onclick="pfAddRow()">＋ 차수 추가</button>';
    echo '<label class="fld">시뮬레이션 한도(원)<input type="number" id="simLimit" step="1000000" style="width:160px" value="'
       . pf_h((int)$simLimit) . '"></label>';
    echo '<span class="muted" style="font-size:12px">금액·누적금액·손익분기율은 백만원 단위 미리보기입니다 (저장 안 됨)</span>';
    echo '</div></form></div>';

    echo <<<'JS'
<script>
function pfAddRow(){
  var tb = document.querySelector('#stepTbl tbody');
  var n  = tb.rows.length + 1;
  var tr = document.createElement('tr');
  tr.dataset.step = n;
  tr.innerHTML =
    '<td class="num"><b>'+n+'차</b><input type="hidden" name="step_no[]" value="'+n+'"></td>'+
    '<td class="num"><input type="number" class="w-weight" name="weight[]" step="0.0001" style="width:90px" value="0"></td>'+
    '<td class="num c-cumw muted">-</td>'+
    '<td class="num"><input type="number" class="w-drop" name="drop_rate[]" step="0.0001" style="width:90px" value="0"></td>'+
    '<td class="num c-cumd muted">-</td>'+
    '<td class="num"><input type="number" class="w-target" name="target_rate[]" step="0.0001" style="width:90px" value="0"></td>'+
    '<td class="num c-amt">-</td><td class="num c-cum">-</td><td class="num c-be">-</td>'+
    '<td class="right"><button type="button" class="btn btn-danger btn-sm" onclick="pfDelRow(this)">삭제</button></td>';
  tb.appendChild(tr);
  pfSim();
}
function pfDelRow(btn){
  btn.closest('tr').remove();
  pfRenumber();
  pfSim();
}
function pfRenumber(){
  var rows = document.querySelectorAll('#stepTbl tbody tr');
  rows.forEach(function(tr, i){
    var n = i + 1;
    tr.dataset.step = n;
    tr.querySelector('td b').textContent = n + '차';
    tr.querySelector('input[name="step_no[]"]').value = n;
  });
}
/* 요건정의서 §2.6 — 단계하락률 누적 곱으로 손익분기율 즉시 재계산 */
function pfSim(){
  var limit = parseFloat(document.getElementById('simLimit').value) || 0;
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

    // 누적비중 · 최초가 대비 하락률
    tr.querySelector('.c-cumw').textContent = (cumW * 100).toFixed(2) + '%';
    tr.querySelector('.c-cumd').textContent = first ? '0.0%' : ((pf - 1) * 100).toFixed(1) + '%';
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
  if (e.target.matches('.w-weight,.w-drop,.w-target,#simLimit')) pfSim();
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
    $res   = ($rows && $steps) ? pf_sim_run($rows, $steps,
        ['limit_amt' => $o['limit'], 'reenter' => $o['re'], 'wait' => $o['wait'], 'intraday' => $o['intraday']], $p) : pf_sim_empty();

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

    // ── 룰셋 비교 — 같은 종목·같은 조건에서 룰셋만 바꿔 전부 돌린다
    echo '<div class="card"><h2>룰셋 비교</h2>';
    echo '<div class="tbl-scroll"><table class="pf"><thead><tr>';
    echo '<th>룰셋</th><th class="num">단계</th><th class="num">총수익률</th><th class="num">CAGR</th>';
    echo '<th class="num">MDD</th><th class="num">사이클</th><th class="num">승률</th>';
    echo '<th class="num">최고차수</th><th class="num">최대소진</th><th></th></tr></thead><tbody>';

    $best = null;
    $cmp  = [];
    foreach ($rules as $r) {
        $rid = (int)$r['id'];
        $st  = $pf->ruleSteps($rid);
        if (!$st) continue;
        $rr  = pf_sim_run($rows, $st, ['limit_amt' => $o['limit'], 'reenter' => $o['re'], 'wait' => $o['wait'], 'intraday' => $o['intraday']], $p);
        if (empty($rr['ok'])) continue;
        $cmp[$rid] = ['name' => $r['name'], 'steps' => count($st), 'r' => $rr, 'st' => $st];
        if ($best === null || $rr['total_rate'] > $cmp[$best]['r']['total_rate']) $best = $rid;
    }

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
        echo '<td class="num"><b>' . pf_signed_pct($rr['total_rate']) . '</b></td>';
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
       . '같은 종목·같은 조건(한도·기간·수수료)에서 룰셋만 바꿔 돌린 결과입니다. '
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
    echo '<button type="button" class="btn btn-outline btn-sm" onclick="pfSimZoomAll()">전체 기간</button>';
    echo '</div>';
    echo '<div class="chart-legend">';
    echo '<span class="cl-item"><i style="background:#22303f"></i> 종가</span>';
    echo '<span class="cl-item"><i style="background:#1e9e74"></i> 누적단가</span>';
    echo '<span class="cl-item"><i style="background:#1565c0"></i> 자동매도가</span>';
    echo '<span class="cl-item"><span class="up">▲</span> 매수</span>';
    echo '<span class="cl-item"><span class="down">▼</span> 매도</span>';
    if ($hasVol) echo '<span class="cl-item"><i style="background:#c9d4de"></i> 거래량</span>';
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
                : pf_h($cy['exit_date']) . '<br><span class="muted" style="font-size:11px">' . pf_n($cy['exit_price']) . '</span>') . '</td>';
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

    // ── 체결 내역
    echo '<div class="card"><h2>체결 내역 ' . count($res['trades']) . '건</h2>';
    if (!$res['trades']) {
        echo '<p class="muted" style="font-size:13px;margin:0">체결이 없습니다.</p>';
    } else {
        echo '<div class="tbl-scroll" style="max-height:420px;overflow-y:auto"><table class="pf"><thead><tr>';
        echo '<th>일자</th><th>구분</th><th class="num">차수</th><th class="num">체결가</th>';
        echo '<th class="num">수량</th><th class="num">금액</th><th class="num">비용</th>';
        echo '<th class="num">예수금</th></tr></thead><tbody>';
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
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    // ── 차트 데이터 (배열로 압축해서 넘긴다 — 수천 행이라 키 이름이 아깝다)
    //    [일자, 종가, 누적단가, 거래량, 상승여부, 자동매도가]
    //    누적단가·자동매도가는 보유 중일 때만 값이 있다 (비보유 구간은 null → 선이 끊긴다)
    $bar = [];
    foreach ($rows as $r) $bar[$r['d']] = $r;   // 거래량·시가를 일자로 찾아 쓰려고

    $series = [];
    foreach ($res['equity'] as $e) {
        $b   = $bar[$e['d']] ?? [];
        $vol = isset($b['v']) ? (int)$b['v'] : null;
        $series[] = [
            $e['d'],
            $e['price'] + 0,
            $e['avg'] === null ? null : round($e['avg'], 2),
            $vol,
            (isset($b['o']) && $b['o'] > 0) ? ($e['price'] >= $b['o'] ? 1 : 0) : 1,
            ($e['sell'] ?? null) === null ? null : round($e['sell'], 2),
        ];
    }
    $marks = [];
    foreach ($res['trades'] as $t) {
        $marks[] = [$t['d'], $t['side'] === 'sell' ? 1 : 0, $t['step'], $t['qty']];
    }

    echo '<script>';
    echo 'const SIM_SERIES=' . json_encode($series) . ';';
    echo 'const SIM_MARKS='  . json_encode($marks) . ';';
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

    echo '<script src="https://unpkg.com/lightweight-charts@4.1.3/dist/lightweight-charts.standalone.production.js"></script>';
    echo <<<'JS'
<script>
function pfComma(el){
  var d = el.value.replace(/[^\d]/g, '');
  el.value = d ? Number(d).toLocaleString() : '';
}
document.addEventListener('input', function(e){
  if (e.target.classList && e.target.classList.contains('num-comma')) pfComma(e.target);
});
document.querySelectorAll('.num-comma').forEach(pfComma);

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
  if (typeof SIM_SERIES === 'undefined' || !window.LightweightCharts) return;
  var LWC = LightweightCharts;

  // 라이브러리 기본 축 표기가 "18 9월 '20" 처럼 어색해서 한국식으로 바꾼다
  function ymd(t){
    if (typeof t === 'string') { var p = t.split('-'); return [+p[0], +p[1], +p[2]]; }
    if (t && t.year) return [t.year, t.month, t.day];
    return null;
  }

  function base(host, h){
    return LWC.createChart(host, {
      width: host.clientWidth, height: h,
      layout: { background: { color: '#fff' }, textColor: '#5f7183', fontFamily: 'Pretendard' },
      grid:   { vertLines: { color: '#f0f4f8' }, horzLines: { color: '#f0f4f8' } },
      rightPriceScale: { borderColor: '#e3eaf0' },
      timeScale: {
        borderColor: '#e3eaf0', timeVisible: false, secondsVisible: false,
        // tickMarkType: 0=연 1=월 2=일 → 눈금 종류에 맞춰 "2021년 / 9월 / 12/15"
        tickMarkFormatter: function(t, type){
          var v = ymd(t);
          if (!v) return '';
          if (type === 0) return v[0] + '년';
          if (type === 1) return v[1] + '월';
          return v[1] + '/' + v[2];
        }
      },
      crosshair: { mode: LWC.CrosshairMode.Normal },
      localization: {
        // 십자선을 올렸을 때 축 아래 뜨는 날짜 — "2025년 12월 15일"
        timeFormatter: function(t){
          var v = ymd(t);
          return v ? (v[0] + '년 ' + v[1] + '월 ' + v[2] + '일') : '';
        },
        priceFormatter: function(v){ return Math.round(v).toLocaleString(); }
      }
    });
  }

  // ── 주가 + 누적단가 + 체결 마커
  var ph = document.getElementById('simPrice');
  if (ph) {
    var pc  = base(ph, ph.clientHeight);
    var px  = pc.addLineSeries({ color: '#22303f', lineWidth: 2, priceLineVisible: false });
    var avg = pc.addLineSeries({ color: '#1e9e74', lineWidth: 2, lineStyle: LWC.LineStyle.Dashed,
                                 priceLineVisible: false, lastValueVisible: false });
    /* 자동매도가 — 차수가 늘 때마다 계단처럼 바뀌므로 계단선.
       보유 중일 때만 값이 있고, 비보유 구간은 값 없는 점(whitespace)을 넣어 선을 끊는다. */
    var sell = pc.addLineSeries({ color: '#1565c0', lineWidth: 1, lineStyle: LWC.LineStyle.Dotted,
                                  lineType: LWC.LineType.WithSteps,
                                  priceLineVisible: false, lastValueVisible: false });

    px.setData(SIM_SERIES.map(function(r){ return { time: r[0], value: r[1] }; }));
    avg.setData(SIM_SERIES.filter(function(r){ return r[2] !== null; })
                          .map(function(r){ return { time: r[0], value: r[2] }; }));
    sell.setData(SIM_SERIES.map(function(r){
      return (r[5] === null || r[5] === undefined) ? { time: r[0] } : { time: r[0], value: r[5] };
    }));

    // 거래량 — 아래 18% 자리에 따로 눈금을 두고 얹는다 (시세에 거래량이 있을 때만)
    var vols = SIM_SERIES.filter(function(r){ return r[3] !== null && r[3] > 0; });
    if (vols.length) {
      var vs = pc.addHistogramSeries({ priceFormat: { type: 'volume' }, priceScaleId: 'vol',
                                       priceLineVisible: false, lastValueVisible: false });
      pc.priceScale('vol').applyOptions({ scaleMargins: { top: 0.82, bottom: 0 } });
      vs.setData(vols.map(function(r){
        return { time: r[0], value: r[3], color: (r[4] ? '#d32f2f' : '#1565c0') + '44' };
      }));
    }

    px.setMarkers(SIM_MARKS.map(function(m){
      var sell = (m[1] === 1);
      return {
        time: m[0],
        position: sell ? 'aboveBar' : 'belowBar',
        color:    sell ? '#1565c0' : '#d32f2f',
        shape:    sell ? 'arrowDown' : 'arrowUp',
        text:     sell ? '매도' : (m[2] + '차')
      };
    }));
    pc.timeScale().fitContent();
    window.addEventListener('resize', function(){ pc.applyOptions({ width: ph.clientWidth }); });
  }

  /* ── 사이클 표에서 행을 누르면 그 구간으로 이동.
        구간이 60거래일보다 짧으면 진입일부터 60거래일까지 넓혀서 본다. */
  var DATES = SIM_SERIES.map(function(r){ return r[0]; });

  function idxOf(d, dflt){
    var i = DATES.indexOf(d);
    if (i >= 0) return i;
    for (var k = 0; k < DATES.length; k++) if (DATES[k] >= d) return k;   // 휴장일이면 다음 봉
    return dflt;
  }

  window.pfSimZoom = function(from, to){
    if (typeof pc === 'undefined' || !pc || !DATES.length) return;

    var a = idxOf(from, 0);
    var b = idxOf(to, DATES.length - 1);
    if (b < a) b = a;
    if (b - a < 60) b = Math.min(DATES.length - 1, a + 60);

    var pad = 3;   // 진입 화살표가 가장자리에 붙지 않게 여유
    a = Math.max(0, a - pad);
    b = Math.min(DATES.length - 1, b + pad);

    pc.timeScale().setVisibleRange({ from: DATES[a], to: DATES[b] });
    ph.scrollIntoView({ behavior: 'smooth', block: 'center' });
  };

  window.pfSimZoomAll = function(){
    if (typeof pc !== 'undefined' && pc) pc.timeScale().fitContent();
  };

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
    ];
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
    if (preg_match('/^\d{6}$/', $code)) { pf_page_fund_detail($dart, $code); return; }

    $years = $dart->financialYears();
    if (!$years) {
        pf_head('재무분석', 'fund', 'wide');
        pf_flash();
        echo '<div class="pf-head"><div><h1>재무분석</h1></div></div>';
        echo '<div class="warn">아직 수집된 재무제표가 없습니다. '
           . 'DART 에서 먼저 받아야 합니다.</div>';
        pf_foot(); return;
    }

    $yearList = array_column($years, 'bsns_year');
    $year     = (int)($_GET['y'] ?? 0);
    if (!in_array($year, array_map('intval', $yearList), true)) $year = (int)$yearList[0];

    // ── 조건 읽기 (빈 칸은 조건 없음)
    $q = [];
    foreach (pf_fund_filters() as [$k]) {
        $v = trim((string)($_GET[$k] ?? ''));
        $q[$k] = ($v === '') ? null : (float)str_replace(',', '', $v);
    }
    // 체크박스는 폼에서 왔는지(go)로 판단해야 해제가 먹는다
    $fromForm   = isset($_GET['go']);
    $profitOnly = $fromForm ? isset($_GET['profit']) : true;   // 기본은 흑자만
    $listedOnly = $fromForm ? isset($_GET['listed']) : true;   // 기본은 상장 종목만
    $sort       = (string)($_GET['sort'] ?? 'roe');
    $desc       = ((string)($_GET['dir'] ?? 'desc') !== 'asc');

    // ── 거르기. 3천 행 남짓이라 PHP 에서 도는 편이 조건을 붙이기 쉽다.
    $rows = [];
    foreach ($dart->screenRows($year, Dart::REPRT_ANNUAL, $listedOnly) as $r) {
        $m = Dart::ratio($r);

        if ($profitOnly && (float)$r['op_income'] <= 0) continue;
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

        $rows[] = $r + $m;
    }

    // ── 정렬. null 은 항상 뒤로 보낸다 (없는 값이 1등으로 올라오면 안 된다)
    $sortable = ['mktcap' => '시총', 'revenue' => '매출액', 'op_income' => '영업이익',
                 'op_margin' => '영업이익률', 'net_margin' => '순이익률', 'roe' => 'ROE',
                 'rev_growth' => '매출성장률', 'debt_ratio' => '부채비율', 'cur_ratio' => '유동비율',
                 'per' => 'PER', 'pbr' => 'PBR'];
    if (!isset($sortable[$sort])) $sort = 'roe';

    usort($rows, function ($a, $b) use ($sort, $desc) {
        $x = $a[$sort]; $y = $b[$sort];
        if ($x === null && $y === null) return 0;
        if ($x === null) return 1;
        if ($y === null) return -1;
        return $desc ? ($y <=> $x) : ($x <=> $y);
    });

    $total = count($rows);
    $rows  = array_slice($rows, 0, 300);

    // ══ 화면
    pf_head('재무분석', 'fund', 'wide');
    pf_flash();

    echo '<div class="pf-head"><div><h1>재무분석</h1>';
    echo '<div class="sub">DART 사업보고서에서 받은 전종목 재무제표로 조건에 맞는 종목을 찾습니다. '
       . '비율은 저장하지 않고 <b>금액에서 매번 계산</b>합니다 — 재무제표를 다시 받으면 숫자도 바로 따라옵니다.</div></div>';

    // KRX 는 당일 자료를 바로 주지 않는다 — 최근 거래일을 받아 두면 PER·PBR·시총이 채워진다
    echo '<div class="act"><form class="inline" method="post" action="/stock/api.php?module=krx&action=collect">';
    echo '<button class="btn btn-outline" type="submit" title="KRX 오픈API 에서 최근 거래일의 시세·상장주식수를 받아옵니다">'
       . '↻ 시세 갱신' . ($krxDate !== '' ? ' <span class="muted" style="font-size:11px">(' . pf_h($krxDate) . ')</span>' : '')
       . '</button></form></div>';
    echo '</div>';

    // ── 조건 폼
    echo '<div class="card">';
    echo '<form method="get" action="/stock/index.php" class="fld-row">';
    echo '<input type="hidden" name="mode" value="fund">';
    echo '<input type="hidden" name="go" value="1">';
    echo '<input type="hidden" name="sort" value="' . pf_h($sort) . '">';
    echo '<input type="hidden" name="dir" value="' . ($desc ? 'desc' : 'asc') . '">';

    echo '<label class="fld">사업연도<select name="y" style="width:110px">';
    foreach ($years as $yr) {
        echo '<option value="' . (int)$yr['bsns_year'] . '"' . ((int)$yr['bsns_year'] === $year ? ' selected' : '') . '>'
           . (int)$yr['bsns_year'] . '년 (' . number_format((int)$yr['n']) . ')</option>';
    }
    echo '</select></label>';

    foreach (pf_fund_filters() as [$k, $lab, $dir, $unit, , $help]) {
        echo '<label class="fld" title="' . pf_h($help) . '">'
           . pf_h($lab) . ' <span class="muted" style="font-weight:600">'
           . ($dir === 'min' ? '≥' : '≤') . $unit . '</span>'
           . '<input type="text" inputmode="decimal" name="' . $k . '" style="width:88px" value="'
           . pf_h($q[$k] === null ? '' : rtrim(rtrim(number_format($q[$k], 2, '.', ''), '0'), '.')) . '"></label>';
    }

    echo '<label class="fld">영업흑자만<span style="padding:7px 0"><input type="checkbox" name="profit" value="1"'
       . ($profitOnly ? ' checked' : '') . '></span></label>';
    echo '<label class="fld" title="KRX 최근 거래일에 실제로 거래된 종목만 봅니다. '
       . '끄면 상장폐지·거래정지 종목도 함께 나옵니다.">거래 종목만'
       . '<span style="padding:7px 0"><input type="checkbox" name="listed" value="1"'
       . ($listedOnly ? ' checked' : '') . '></span></label>';
    echo '<button class="btn btn-primary" type="submit">검색</button>';
    echo '<a class="btn btn-outline" href="/stock/index.php?mode=fund&y=' . $year . '">조건 지우기</a>';
    echo '</form>';

    echo '<p class="sub muted" style="margin:9px 0 0;font-size:12px">'
       . '<b>' . number_format($total) . '종목</b>이 조건에 맞습니다'
       . ($total > 300 ? ' (상위 300개만 표시)' : '')
       . ' · ' . $year . '년 사업보고서 기준 · 금융업은 매출액 계정이 없어 매출 관련 조건에서 빠집니다'
       . ($krxDate !== ''
            ? ' · 시세·상장주식수는 <b>KRX ' . pf_h($krxDate) . '</b> 기준'
              . ($listedOnly ? ' (그날 거래된 종목만)' : ' · <b class="down">미거래 종목 포함</b>')
            : ' · <b class="down">KRX 시세가 아직 없습니다</b> — PER·PBR·시총이 비어 있습니다')
       . '</p>';
    echo '</div>';

    // ── 결과
    echo '<div class="card"><h2>검색 결과</h2>';
    if (!$rows) {
        echo '<p class="muted" style="font-size:13px;margin:0">조건에 맞는 종목이 없습니다. 조건을 느슨하게 해 보세요.</p>';
    } else {
        $head = function (string $key, string $label) use ($sort, $desc, $year) {
            $d   = ($sort === $key && $desc) ? 'asc' : 'desc';
            $qs  = $_GET;
            $qs['mode'] = 'fund'; $qs['y'] = $year; $qs['sort'] = $key; $qs['dir'] = $d;
            $arw = ($sort === $key) ? ($desc ? ' ▾' : ' ▴') : '';
            return '<th class="num"><a href="/stock/index.php?' . pf_h(http_build_query($qs)) . '">'
                 . pf_h($label) . $arw . '</a></th>';
        };

        echo '<div class="tbl-scroll" style="max-height:70vh;overflow-y:auto"><table class="pf"><thead><tr>';
        echo '<th>종목</th>';
        echo $head('mktcap', '시총');
        echo $head('per', 'PER');
        echo $head('pbr', 'PBR');
        echo $head('revenue', '매출액');
        echo $head('op_income', '영업이익');
        echo $head('op_margin', '영업이익률');
        echo $head('net_margin', '순이익률');
        echo $head('roe', 'ROE');
        echo $head('rev_growth', '매출성장률');
        echo $head('debt_ratio', '부채비율');
        echo $head('cur_ratio', '유동비율');
        echo '<th class="num">기준</th></tr></thead><tbody>';

        foreach ($rows as $r) {
            echo '<tr class="fund-row" data-code="' . pf_h($r['stock_code'])
               . '" title="클릭하면 연도별 재무를 봅니다">';
            echo '<td><b>' . pf_h($r['corp_name'] ?: $r['stock_code']) . '</b> '
               . '<span class="muted" style="font-size:11px">' . pf_h($r['stock_code']) . '</span>'
               // 필터를 껐을 때만 나타난다 — 시세에 없는 종목은 상장폐지일 가능성이 높다
               . (empty($r['listed']) ? ' <span class="badge st-closed" title="KRX 최근 거래일에 없습니다">미거래</span>' : '')
               . '</td>';
            echo '<td class="num muted">' . pf_eok($r['mktcap']) . '</td>';
            echo '<td class="num">' . ($r['per'] === null ? '-' : number_format($r['per'], 2)) . '</td>';
            echo '<td class="num">' . ($r['pbr'] === null ? '-' : number_format($r['pbr'], 2)) . '</td>';
            echo '<td class="num">' . pf_eok($r['revenue']) . '</td>';
            echo '<td class="num">' . pf_eok($r['op_income']) . '</td>';
            echo '<td class="num">' . pf_signed_pct($r['op_margin']) . '</td>';
            echo '<td class="num">' . pf_signed_pct($r['net_margin']) . '</td>';
            echo '<td class="num">' . pf_signed_pct($r['roe']) . '</td>';
            echo '<td class="num">' . pf_signed_pct($r['rev_growth']) . '</td>';
            echo '<td class="num">' . ($r['debt_ratio'] === null ? '-' : pf_h(pf_pct0($r['debt_ratio'], 0))) . '</td>';
            echo '<td class="num">' . ($r['cur_ratio']  === null ? '-' : pf_h(pf_pct0($r['cur_ratio'], 0))) . '</td>';
            echo '<td class="num muted" style="font-size:11px">' . ($r['fs_div'] === 'CFS' ? '연결' : '별도') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="sub muted" style="margin:9px 0 0;font-size:12px">'
           . '<b>행을 클릭</b>하면 그 종목의 11년 재무를 봅니다. 금액 단위는 <b>억원</b>입니다. '
           . '자본총계가 0 이하(자본잠식)면 ROE·부채비율은 <b>-</b> 로 둡니다 — 계산하면 숫자가 거짓말을 합니다.<br>'
           . 'PER·PBR 은 <b>KRX 최근 종가 ÷ (그 해 순이익·자본총계 ÷ 지금 주식수)</b> 로 계산합니다. '
           . '주가가 지금 값이므로 분모도 지금 주식수를 씁니다 — 액면분할이 있었던 종목에서 '
           . '그 해 주식수로 나누면 PER 이 수십 배 찌그러집니다. '
           . '자기주식이 빠진 유통주식수는 <b>주식 수 체계가 그대로인 종목에 한해</b> 씁니다. '
           . '적자·자본잠식이면 계산하지 않습니다.</p>';
    }
    echo '</div>';

    echo <<<'JS'
<script>
document.addEventListener('click', function(e){
  var tr = e.target.closest ? e.target.closest('tr.fund-row') : null;
  if (!tr || (e.target.closest && e.target.closest('a'))) return;
  location.href = '/stock/index.php?mode=fund&code=' + tr.getAttribute('data-code');
});
</script>
<style>
tr.fund-row{cursor:pointer}
tr.fund-row:hover{background:#f2f8fd}
</style>
JS;

    pf_foot();
}

/** 한 종목의 연도별 재무 */
function pf_page_fund_detail(Dart $dart, string $code): void
{
    $series = $dart->financialSeries($code);
    $name   = $dart->corpName($code);
    $funds  = Dart::adjust($dart->rows($code));       // EPS·BPS·DPS (있으면)
    $byYear = [];
    foreach ($funds as $f) $byYear[(int)$f['bsns_year']] = $f;

    pf_head('재무분석 · ' . ($name ?: $code), 'fund', 'wide');
    pf_flash();

    echo '<div class="pf-head"><div><h1>' . pf_h($name ?: $code)
       . ' <span class="muted" style="font-size:14px;font-weight:600">' . pf_h($code) . '</span></h1>';
    echo '<div class="sub">DART 사업보고서 기준 연도별 재무입니다. 금액 단위는 <b>억원</b>.</div></div>';
    echo '<div class="act"><a class="btn btn-outline" href="/stock/index.php?mode=fund">← 재무분석</a></div>';
    echo '</div>';

    if (!$series) {
        echo '<div class="warn">이 종목의 재무제표가 아직 없습니다.</div>';
        pf_foot(); return;
    }

    echo '<div class="card"><h2>연도별 재무</h2>';
    echo '<div class="tbl-scroll"><table class="pf"><thead><tr>';
    echo '<th class="num">연도</th><th class="num">매출액</th><th class="num">영업이익</th>'
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
        $m  = Dart::ratio($r);
        $fd = $byYear[(int)$r['bsns_year']] ?? null;

        echo '<tr>';
        echo '<td class="num"><b>' . (int)$r['bsns_year'] . '</b></td>';
        echo '<td class="num">' . pf_eok($r['revenue']) . '</td>';
        echo '<td class="num">' . pf_eok($r['op_income']) . '</td>';
        echo '<td class="num">' . pf_signed_pct($m['op_margin']) . '</td>';
        echo '<td class="num">' . pf_eok($r['net_income']) . '</td>';
        echo '<td class="num">' . pf_signed_pct($m['net_margin']) . '</td>';
        echo '<td class="num">' . pf_signed_pct($m['roe']) . '</td>';
        echo '<td class="num">' . pf_eok($r['asset_total']) . '</td>';
        echo '<td class="num">' . pf_eok($r['debt_total']) . '</td>';
        echo '<td class="num">' . pf_eok($r['equity_total']) . '</td>';
        echo '<td class="num">' . ($m['debt_ratio'] === null ? '-' : pf_h(pf_pct0($m['debt_ratio'], 0))) . '</td>';
        echo '<td class="num">' . ($m['cur_ratio']  === null ? '-' : pf_h(pf_pct0($m['cur_ratio'], 0))) . '</td>';
        // EPS·BPS·DPS 는 액면분할 보정값을 쓴다 (옛 연도가 지금 주식과 단위가 다르다)
        echo '<td class="num">' . pf_n($fd['adj_eps'] ?? null) . '</td>';
        echo '<td class="num">' . pf_n($fd['adj_bps'] ?? null) . '</td>';
        echo '<td class="num">' . pf_n($fd['adj_dps'] ?? null) . '</td>';
        echo '<td class="num muted" style="font-size:11px">' . ($r['fs_div'] === 'CFS' ? '연결' : '별도') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';

    if (!$funds) {
        echo '<p class="sub muted" style="margin:9px 0 0;font-size:12px">'
           . 'EPS·BPS·DPS 는 아직 수집되지 않았습니다 (보유·관심 종목만 받아 둔 상태입니다).</p>';
    } else {
        echo '<p class="sub muted" style="margin:9px 0 0;font-size:12px">'
           . 'EPS·BPS·DPS 는 <b>액면분할 보정</b>을 거친 값입니다 — 옛 연도를 지금 주식 기준으로 환산했습니다.</p>';
    }
    echo '</div>';

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

    pf_head('포트폴리오', 'setting', 'narrow');
    pf_subtabs('portfolio');
    pf_flash();

    echo '<div class="pf-head"><div><h1>포트폴리오</h1>';
    echo '<div class="sub">종목을 담는 단위입니다. 증권사 계좌와 1:1로 써도 되고, 한 계좌를 전략별로 쪼개 여러 개를 둬도 됩니다. '
       . '증권사를 고르면 <a href="/stock/index.php?mode=setting">설정</a>에 등록한 수수료가 자동으로 적용됩니다. '
       . '원금은 대시보드 합계에 쓰입니다.</div></div>';
    echo '<div class="act"><button type="button" class="btn btn-primary" onclick="pfOpenFolio()">＋ 포트폴리오 추가</button></div>';
    echo '</div>';

    if (!$brokers) {
        echo '<div class="warn">등록된 증권사가 없습니다. '
           . '<a href="/stock/index.php?mode=setting">설정</a>에서 증권사와 수수료를 먼저 등록하세요.</div>';
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
function pfComma(el){
  var d = el.value.replace(/[^\d]/g, '');
  el.value = d ? Number(d).toLocaleString() : '';
}
function pfNoteCnt(el){
  var c = el.parentNode.querySelector('.note-cnt');
  if (c) c.textContent = Array.from(el.value).length;
}
document.addEventListener('input', function(e){
  if (!e.target.classList) return;
  if (e.target.classList.contains('num-comma')) pfComma(e.target);
  if (e.target.classList.contains('note-in'))  pfNoteCnt(e.target);
});
document.querySelectorAll('.num-comma').forEach(pfComma);
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
            echo '<td class="num"><input type="number" name="min_amt[]" step="1000000" min="0" style="width:150px" value="'
               . (int)$t['min_amt'] . '"></td>';
            echo '<td class="num"><input type="number" name="fee_rate[]" step="0.000001" min="0" style="width:120px" value="'
               . pf_h(pf_rate_pct((float)$t['fee_rate'])) . '"></td>';
            echo '<td class="num"><input type="number" name="fee_fixed[]" step="100" min="0" style="width:100px" value="'
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
    '<td class="num"><input type="number" name="min_amt[]" step="1000000" min="0" style="width:150px" value="0"></td>' +
    '<td class="num"><input type="number" name="fee_rate[]" step="0.000001" min="0" style="width:120px" value="0"></td>' +
    '<td class="num"><input type="number" name="fee_fixed[]" step="100" min="0" style="width:100px" value="0"></td>' +
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
?>
