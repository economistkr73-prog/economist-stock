<?php
/**
 * nw/index.php — 노르웨이의숲 원룸 관리 (신규)
 * 구 nw.php 대체. mode 기반 라우팅 + 렌더 (schedule.php 패턴 계승).
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/cnt.inc';
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/auth_fnc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/nav.inc';
require_login();

$mode = $_GET['mode'] ?? 'dashboard';

// 계약형태/갱신 라벨 (top-level const 는 hoisting 안 되므로 반드시 라우팅 디스패치 위에서 선언)
const NW_CONTRACT_TYPE_LABEL = ['jeonse' => '전세', 'semi_monthly' => '반전세', 'monthly' => '월세'];
const NW_RENEWAL_LABEL       = ['initial' => '최초', 'extended' => '연장', 'implied' => '묵시적갱신中'];

$routes = [
    'dashboard' => 'nw_page_dashboard',
    'building'  => 'nw_page_building',
    'contract'  => 'nw_page_contract',
    'payment'   => 'nw_page_payment',
    'import'    => 'nw_page_import',
];

if (isset($routes[$mode]) && function_exists($routes[$mode])) {
    $routes[$mode]($pdo);
} else {
    nw_page_dashboard($pdo);
}

// ==========================================================
// 공통 헬퍼
// ==========================================================
function nw_h($s): string {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

function nw_money($n): string {
    return number_format((float)($n ?? 0));
}

function nw_pyeong($sqm): string {
    return number_format((float)$sqm / 3.305785, 2);
}

function nw_head(string $title): void {
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title><?= nw_h($title) ?> — 원룸대시보드</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php nw_css(); ?>
</head>
<body>
<?php nw_nav(); ?>
<div class="nw-body">
<?php
}

// 원룸 전용 독립 헤더 (공통 사이트 네비와 분리)
function nw_nav(): void {
    global $current_user;
?>
<div class="nw-topbar">
  <a class="nw-brand" href="/nw/index.php"><span class="nw-brand-ico">🏠</span> 원룸대시보드</a>
  <div class="nw-topbar-right">
    <?php if (!empty($current_user)): ?><span class="nw-uname"><?= nw_h($current_user) ?>님</span><?php endif; ?>
    <a href="/" class="nw-tb-link">메인</a>
    <a href="/logout.php" class="nw-tb-link nw-logout">로그아웃</a>
  </div>
</div>
<?php
}

function nw_foot(): void {
?>
</div>
<?php nw_js(); ?>
</body>
</html>
<?php
}

function nw_css(): void {
?>
<style>
*, *::before, *::after { box-sizing: border-box; }
body { font-family: 'Pretendard', 'Malgun Gothic', sans-serif; background: #f0f2f5; color: #2c3e50; margin: 0; }
/* 원룸 전용 독립 헤더 */
.nw-topbar { background: linear-gradient(90deg,#2b6cb0,#1e4e8c); color:#fff; height:56px; display:flex; align-items:center; justify-content:space-between; padding:0 20px; box-shadow:0 2px 8px rgba(0,0,0,.18); }
.nw-brand { color:#fff; text-decoration:none; font-size:19px; font-weight:800; letter-spacing:-.02em; display:flex; align-items:center; gap:7px; }
.nw-brand:hover { opacity:.92; }
.nw-brand-ico { font-size:20px; }
.nw-topbar-right { display:flex; align-items:center; gap:12px; font-size:14px; }
.nw-uname { color:#dbeafe; font-weight:600; }
.nw-tb-link { color:#eaf2fb; text-decoration:none; font-weight:600; padding:6px 12px; border-radius:6px; }
.nw-tb-link:hover { background:rgba(255,255,255,.16); }
.nw-logout { background:rgba(255,255,255,.14); }
@media (max-width:560px){ .nw-topbar{height:48px;padding:0 12px;} .nw-brand{font-size:16px;} .nw-uname{display:none;} .nw-tb-link{padding:5px 9px;font-size:13px;} }
.nw-body { max-width: 1200px; margin: 0 auto; padding: 20px 16px 60px; }
.page-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 18px; }
.page-head h1 { font-size: 22px; margin: 0; }
.page-head .sub { color: #7f8c8d; font-size: 14px; margin-top: 4px; }
.back-link { color: #3498db; text-decoration: none; font-size: 14px; font-weight: 600; }
.back-link:hover { text-decoration: underline; }

.btn { border: none; cursor: pointer; border-radius: 6px; font-size: 13px; font-weight: 600; padding: 8px 14px; transition: .15s; }
.btn-primary { background: #3498db; color: #fff; }
.btn-primary:hover { background: #2980b9; }
.btn-outline { background: #fff; border: 1px solid #bdc3c7; color: #2c3e50; }
.btn-outline:hover { background: #ecf0f1; }
.btn-danger { background: #fdecea; color: #e74c3c; }
.btn-danger:hover { background: #fadbd8; }
.btn-warn { background: #fef5e7; color: #d68910; }
.btn-warn:hover { background: #fdebd0; }
.btn-sm { padding: 5px 10px; font-size: 12px; }

.card { background: #fff; border-radius: 12px; box-shadow: 0 1px 4px rgba(0,0,0,.08); padding: 16px; }

.summary-cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 18px; }
.stat { text-align: center; padding: 18px 10px; }
.stat-num { font-size: 30px; font-weight: 800; color: #2c3e50; }
.stat-label { font-size: 13px; color: #7f8c8d; margin-top: 4px; }
.stat-danger .stat-num { color: #e74c3c; }

.alert-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 18px; }
.alert-grid h3 { font-size: 15px; margin: 0 0 10px; }
.alert-list { list-style: none; margin: 0; padding: 0; max-height: 260px; overflow-y: auto; }
.alert-list li { padding: 9px 6px; border-bottom: 1px solid #f0f2f5; cursor: pointer; font-size: 13px; display: flex; justify-content: space-between; gap: 8px; }
.alert-list li:hover { background: #f8f9fb; }
.alert-list li:last-child { border-bottom: none; }
.alert-empty { color: #bdc3c7; font-size: 13px; padding: 20px 0; text-align: center; }

.building-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; }
.b-card { display: block; text-decoration: none; color: inherit; transition: .15s; }
.b-card:hover { box-shadow: 0 4px 14px rgba(0,0,0,.12); transform: translateY(-1px); }
.b-name { font-size: 17px; font-weight: 800; margin-bottom: 4px; }
.b-addr { font-size: 12px; color: #7f8c8d; margin-bottom: 12px; }
.occ-bar { background: #ecf0f1; border-radius: 6px; height: 8px; overflow: hidden; margin-bottom: 6px; }
.occ-bar-fill { background: #3498db; height: 100%; }
.occ-label { font-size: 12px; color: #7f8c8d; display: flex; justify-content: space-between; }

.badge { display: inline-block; padding: 3px 9px; border-radius: 12px; font-size: 12px; font-weight: 700; white-space: nowrap; }
.badge-ok { background: #eafaf1; color: #27ae60; }
.badge-warn { background: #fef5e7; color: #d68910; }
.badge-danger { background: #fdecea; color: #e74c3c; }
.badge-over { background: #2c3e50; color: #fff; }
.badge-paid { background: #eafaf1; color: #27ae60; }
.badge-due { background: #eef2f7; color: #7f8c8d; }
.badge-overdue { background: #fdecea; color: #e74c3c; }
.badge-na { background: #f4f4f4; color: #aaa; }
.badge-implied { background: #2c3e50; color: #fff; }
.badge-extended { background: #eaf2fb; color: #3498db; }

.room-scroll { border-radius: 12px; box-shadow: 0 1px 4px rgba(0,0,0,.08); background: #fff; }
.room-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; font-size: 13px; }
.room-table th { background: #2c3e50; color: #fff; padding: 10px 8px; text-align: left; font-size: 12px; white-space: nowrap; }
.room-table th:first-child { border-top-left-radius: 12px; }
.room-table th:last-child { border-top-right-radius: 12px; }
.room-table td { padding: 9px 8px; border-bottom: 1px solid #f0f2f5; vertical-align: middle; white-space: nowrap; }
.room-table tr:last-child td { border-bottom: none; }
.room-table tr:hover td { background: #fafbfc; }
.col-tenant { max-width: 160px; }
.col-tenant .tenant-link { display: inline-block; max-width: 100%; overflow: hidden; text-overflow: ellipsis; vertical-align: bottom; }
.col-agent { max-width: 120px; overflow: hidden; text-overflow: ellipsis; }
.renewal-line { margin-top: 3px; }
.renewal-line .badge { font-size: 10px; padding: 2px 7px; }
.stat-bar { display: flex; flex-wrap: nowrap; gap: 8px; margin-bottom: 16px; overflow-x: auto; }
.sb-item { background: #fff; border-radius: 10px; box-shadow: 0 1px 4px rgba(0,0,0,.08); padding: 10px 14px; display: flex; align-items: center; gap: 8px; font-size: 13px; white-space: nowrap; flex-shrink: 0; }
.sb-type { font-weight: 800; padding: 3px 11px; border-radius: 20px; font-size: 12px; white-space: nowrap; }
.sb-cnt { font-weight: 800; color: #2c3e50; font-size: 15px; }
.sb-detail { color: #7f8c8d; white-space: nowrap; }
.sb-jeonse .sb-type { background: #eaf2fb; color: #2471a3; }
.sb-semi .sb-type { background: #fef5e7; color: #b9770e; }
.sb-monthly .sb-type { background: #eafaf1; color: #1e8449; }
.ctype { display: inline-block; padding: 3px 10px; border-radius: 6px; font-size: 12px; font-weight: 800; }
.ctype-jeonse { background: #eaf2fb; color: #2471a3; }
.ctype-semi_monthly { background: #fef5e7; color: #b9770e; }
.ctype-monthly { background: #eafaf1; color: #1e8449; }
.sb-total { background: #2c3e50; }
.sb-total .sb-type { background: #f1c40f; color: #2c3e50; }
.sb-total .sb-detail { color: #ecf0f1; font-weight: 600; }
.agent-chip { display: inline-flex; align-items: center; justify-content: center; width: 23px; height: 23px; border-radius: 50%; font-size: 12px; font-weight: 800; }
.agent-legend { margin-top: 14px; padding: 13px 18px; background: #fff; border-radius: 12px; box-shadow: 0 1px 4px rgba(0,0,0,.08); display: flex; align-items: center; gap: 8px 18px; flex-wrap: wrap; font-size: 13px; }
.agent-legend .al-title { font-weight: 800; color: #34495e; margin-right: 4px; }
.al-item { display: inline-flex; align-items: center; gap: 7px; color: #2c3e50; font-weight: 600; }
.al-item b { color: #7f8c8d; font-weight: 700; }
.vacant-tag { color: #bdc3c7; font-size: 12px; }
.tenant-link { color: #2c3e50; text-decoration: none; font-weight: 700; }
.tenant-link:hover { color: #3498db; }
.mono { font-variant-numeric: tabular-nums; }

/* 관리 케밥 드롭다운 */
.rowmenu { position: relative; display: inline-block; }
.rowmenu-btn { border: 1px solid #dfe4ea; background: #fff; border-radius: 7px; width: 30px; height: 28px; cursor: pointer; font-size: 17px; line-height: 1; color: #7f8c8d; padding: 0; }
.rowmenu-btn:hover { background: #f4f6f8; color: #2c3e50; }
.rowmenu-pop { display: none; position: absolute; right: 0; top: 32px; background: #fff; border: 1px solid #e4e8ec; border-radius: 9px; box-shadow: 0 8px 24px rgba(0,0,0,.15); z-index: 60; min-width: 138px; padding: 5px; }
.rowmenu-pop.on { display: block; }
.rowmenu-pop button { display: block; width: 100%; text-align: left; border: none; background: none; padding: 9px 12px; font-size: 13px; font-weight: 600; cursor: pointer; border-radius: 6px; color: #2c3e50; white-space: nowrap; }
.rowmenu-pop button:hover { background: #f0f4f8; }
.rowmenu-pop button.danger { color: #e74c3c; }
.rowmenu-pop button.danger:hover { background: #fdecea; }

.form-card { max-width: 760px; margin: 0 auto; padding: 0; overflow: hidden; }
.form-card-head { padding: 16px 24px; background: linear-gradient(180deg,#fbfcfe,#f4f7fb); border-bottom: 1px solid #eef0f3; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
.form-section { padding: 20px 24px; border-bottom: 1px solid #f2f4f6; }
.form-section:last-of-type { border-bottom: none; }
.form-section-title { font-size: 13px; font-weight: 800; color: #34495e; margin-bottom: 16px; letter-spacing: .02em; display: flex; align-items: center; gap: 7px; }
.form-section-title::before { content: ''; width: 3px; height: 14px; background: #3498db; border-radius: 2px; }
.form-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px 18px; }
.form-grid .full { grid-column: 1 / -1; }
.form-field label { display: block; font-size: 12px; color: #7f8c8d; margin-bottom: 5px; font-weight: 600; }
.form-field input[type=text], .form-field input[type=date], .form-field input[type=month], .form-field input[type=number], .form-field textarea {
  width: 100%; padding: 9px 11px; border: 1px solid #dfe4ea; border-radius: 8px; font-size: 14px; font-family: inherit; transition: border-color .12s, box-shadow .12s; background: #fff;
}
.form-field input:focus, .form-field textarea:focus { outline: none; border-color: #3498db; box-shadow: 0 0 0 3px rgba(52,152,219,.13); }
.form-field textarea { min-height: 96px; resize: vertical; line-height: 1.5; }
.radio-group { display: flex; gap: 10px; align-items: center; }
.radio-group label { font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 6px; cursor: pointer; padding: 8px 16px; border: 1px solid #dfe4ea; border-radius: 8px; transition: .12s; background: #fff; }
.radio-group label:has(input:checked) { border-color: #3498db; background: #eaf4fc; color: #2471a3; }
.radio-group input { accent-color: #3498db; }
.check-field { display: flex; align-items: center; gap: 6px; font-size: 13px; color: #e74c3c; font-weight: 700; cursor: pointer; padding: 8px 14px; border: 1px solid #f5c6c0; border-radius: 8px; background: #fff; }
.check-field:has(input:checked) { background: #fdecea; border-color: #e74c3c; }
.check-field input { accent-color: #e74c3c; width: 16px; height: 16px; cursor: pointer; }
.input-money { position: relative; }
.input-money input { text-align: right; padding-right: 32px !important; font-variant-numeric: tabular-nums; }
.input-money::after { content: '원'; position: absolute; right: 11px; top: 50%; transform: translateY(-50%); font-size: 12px; color: #95a5a6; pointer-events: none; }
.ssn-group { display: flex; align-items: center; gap: 7px; }
.ssn-group input { text-align: center; letter-spacing: .04em; }
.ssn-group .dash { color: #bdc3c7; font-weight: 800; }
.form-actions { position: sticky; bottom: 0; background: #fff; padding: 15px 24px; border-top: 1px solid #eef0f3; display: flex; gap: 8px; justify-content: flex-end; }
.form-actions .btn { padding: 10px 22px; font-size: 14px; }

.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.4); align-items: center; justify-content: center; z-index: 2000; }
.modal-overlay.on { display: flex; }
.modal-box { background: #fff; border-radius: 12px; padding: 20px; width: 360px; max-width: 90vw; }
.modal-box h3 { margin: 0 0 14px; font-size: 16px; }
.modal-box .form-field { margin-bottom: 10px; }
.modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 14px; }

.pay-summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 16px; }
.pay-summary .card { text-align: center; padding: 14px 8px; }
.pay-summary .lbl { font-size: 12px; color: #7f8c8d; }
.pay-summary .val { font-size: 18px; font-weight: 800; margin-top: 4px; }
.pay-form { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 16px; }
.pay-form .form-field { min-width: 0; }
.pay-form .form-field label { white-space: nowrap; font-size: 12px; }
.pay-form .input-money { width: 108px; }
.pay-form input[type=date], .pay-form input[type=month] { width: 130px; }
.pay-form #p_memo { width: 200px; }
.pay-form > .btn { margin-left: auto; }
.hist-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.08); font-size: 13px; }
.hist-table th { background: #34495e; color: #fff; padding: 8px; text-align: center; }
.hist-table td { padding: 8px; text-align: center; border-bottom: 1px solid #f0f2f5; }

@media (max-width: 820px) {
  .form-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 700px) {
  .summary-cards { grid-template-columns: repeat(2, 1fr); }
  .alert-grid { grid-template-columns: 1fr; }
  .form-grid { grid-template-columns: 1fr; }
  .room-scroll { overflow-x: auto; }
}
</style>
<?php
}

function nw_js(): void {
?>
<script>
async function nwApi(module, action, data) {
  const body = new URLSearchParams({ module, action, ...data });
  const res = await fetch('/nw/api.php', { method: 'POST', body });
  const json = await res.json();
  if (!json.ok) { alert('오류: ' + (json.msg || '알 수 없는 오류')); throw new Error(json.msg || 'error'); }
  return json;
}
function nwGoto(url) { window.location.href = url; }

function nwConfirmAction(msg, module, action, data, reloadUrl) {
  if (!confirm(msg)) return;
  nwApi(module, action, data).then(() => { window.location.href = reloadUrl; }).catch(() => {});
}

function nwOpenModal(id) { document.getElementById(id).classList.add('on'); }
function nwCloseModal(id) { document.getElementById(id).classList.remove('on'); }

// 관리 케밥 메뉴 토글 (다른 열린 메뉴는 닫고, 바깥 클릭 시 닫힘)
function nwToggleMenu(ev, btn) {
  ev.stopPropagation();
  const pop = btn.nextElementSibling;
  const isOpen = pop.classList.contains('on');
  document.querySelectorAll('.rowmenu-pop.on').forEach(p => p.classList.remove('on'));
  if (!isOpen) pop.classList.add('on');
}
document.addEventListener('click', () => {
  document.querySelectorAll('.rowmenu-pop.on').forEach(p => p.classList.remove('on'));
});

// 금액 입력: 숫자만 남기고 천단위 콤마 포맷
function nwUnmoney(id) { return document.getElementById(id).value.replace(/[^0-9]/g, ''); }
function nwFmtMoney(el) { const v = el.value.replace(/[^0-9]/g, ''); el.value = v ? Number(v).toLocaleString('en-US') : ''; }
// 주민번호 앞6-뒤7 결합 (둘 다 비면 빈값)
function nwSsn(f, b) {
  const a = document.getElementById(f).value.replace(/\D/g, '');
  const c = document.getElementById(b).value.replace(/\D/g, '');
  if (!a && !c) return '';
  return c ? (a + '-' + c) : a;
}
// 폼 공통 바인딩: 금액 라이브 포맷 + 주민번호 숫자만/자동이동
function nwBindFormInputs() {
  document.querySelectorAll('input.money').forEach(el => {
    el.addEventListener('input', () => nwFmtMoney(el));
  });
  document.querySelectorAll('.ssn-group input').forEach(el => {
    el.addEventListener('input', () => {
      el.value = el.value.replace(/[^0-9]/g, '');
      if (el.value.length >= Number(el.getAttribute('maxlength')||99)) {
        const grp = el.closest('.ssn-group');
        const ins = grp ? grp.querySelectorAll('input') : [];
        if (ins.length === 2 && el === ins[0]) ins[1].focus();
      }
    });
  });
}
document.addEventListener('DOMContentLoaded', nwBindFormInputs);
</script>
<?php
}

// ==========================================================
// 대시보드
// ==========================================================
function nw_page_dashboard(PDO $pdo): void {
    $nw = new Nw($pdo);
    $nw->ensureTable();

    $buildings = $nw->listBuildings();
    $summary   = $nw->dashboardSummary();

    nw_head('원룸관리 대시보드');
?>
<div class="page-head">
  <div>
    <h1>🏢 원룸관리 대시보드</h1>
    <div class="sub">계약현황 · 만기 · 월세납부를 한눈에 모니터링합니다</div>
  </div>
  <button class="btn btn-primary" onclick="nwOpenModal('nwAddBuildingModal')">+ 건물 추가</button>
</div>

<div class="summary-cards">
  <div class="card stat"><div class="stat-num"><?= $summary['totalBuildings'] ?></div><div class="stat-label">건물</div></div>
  <div class="card stat"><div class="stat-num"><?= $summary['totalUnits'] ?></div><div class="stat-label">전체 호실</div></div>
  <div class="card stat"><div class="stat-num"><?= $summary['vacantUnits'] ?></div><div class="stat-label">공실</div></div>
  <div class="card stat<?= count($summary['overdueList']) ? ' stat-danger' : '' ?>"><div class="stat-num"><?= count($summary['overdueList']) ?></div><div class="stat-label">이번달 연체</div></div>
</div>

<div class="alert-grid">
  <div class="card">
    <h3>⚠️ 연체 알림</h3>
    <ul class="alert-list">
      <?php if (!$summary['overdueList']): ?>
        <li class="alert-empty" style="border:none;">연체 중인 세입자가 없습니다</li>
      <?php else: foreach ($summary['overdueList'] as $c): ?>
        <li onclick="nwGoto('/nw/index.php?mode=building&id=<?= (int)$c['building_id'] ?>')">
          <span><b><?= nw_h($c['building_name']) ?> <?= nw_h($c['room_no']) ?>호</b> · <?= nw_h($c['tenant_name']) ?></span>
          <span class="badge badge-overdue"><?= nw_h($c['pay']['label']) ?></span>
        </li>
      <?php endforeach; endif; ?>
    </ul>
  </div>
  <div class="card">
    <h3>⏰ 만기임박 (D-30 이내)</h3>
    <ul class="alert-list">
      <?php if (!$summary['expiringList']): ?>
        <li class="alert-empty" style="border:none;">만기가 임박한 계약이 없습니다</li>
      <?php else: foreach ($summary['expiringList'] as $c): ?>
        <li onclick="nwGoto('/nw/index.php?mode=building&id=<?= (int)$c['building_id'] ?>')">
          <span><b><?= nw_h($c['building_name']) ?> <?= nw_h($c['room_no']) ?>호</b> · <?= nw_h($c['tenant_name']) ?></span>
          <span class="badge badge-<?= nw_h($c['exp']['level']) ?>"><?= nw_h($c['exp']['label']) ?></span>
        </li>
      <?php endforeach; endif; ?>
    </ul>
  </div>
</div>

<div class="building-grid">
  <?php foreach ($buildings as $b):
        $total = count($nw->listUnits((int)$b['id']));
        $occ   = count($nw->listActiveContractsByBuilding((int)$b['id']));
        $pct   = $total ? round($occ / $total * 100) : 0;
  ?>
  <a class="card b-card" href="/nw/index.php?mode=building&id=<?= (int)$b['id'] ?>">
    <div class="b-name"><?= nw_h($b['name']) ?></div>
    <div class="b-addr"><?= nw_h($b['address']) ?></div>
    <div class="occ-bar"><div class="occ-bar-fill" style="width:<?= $pct ?>%;"></div></div>
    <div class="occ-label"><span><?= $occ ?> / <?= $total ?> 입주</span><span><?= $pct ?>%</span></div>
  </a>
  <?php endforeach; ?>
</div>

<div class="modal-overlay" id="nwAddBuildingModal">
  <div class="modal-box">
    <h3>건물 추가</h3>
    <div class="form-field"><label>건물명</label><input type="text" id="nwB_name"></div>
    <div class="form-field"><label>사업자번호</label><input type="text" id="nwB_biz_no"></div>
    <div class="form-field"><label>주소</label><input type="text" id="nwB_address"></div>
    <div class="form-field"><label>업종/업태</label><input type="text" id="nwB_category"></div>
    <div class="form-field"><label>용도</label><input type="text" id="nwB_purpose"></div>
    <div class="form-field"><label>토지면적(㎡)</label><input type="number" id="nwB_land_size"></div>
    <div class="form-field"><label>연면적(㎡)</label><input type="number" id="nwB_floor_size"></div>
    <div class="modal-actions">
      <button class="btn btn-outline" onclick="nwCloseModal('nwAddBuildingModal')">취소</button>
      <button class="btn btn-primary" onclick="nwSubmitAddBuilding()">등록</button>
    </div>
  </div>
</div>
<script>
function nwSubmitAddBuilding() {
  const d = {
    name: document.getElementById('nwB_name').value,
    biz_no: document.getElementById('nwB_biz_no').value,
    address: document.getElementById('nwB_address').value,
    category: document.getElementById('nwB_category').value,
    purpose: document.getElementById('nwB_purpose').value,
    land_size: document.getElementById('nwB_land_size').value,
    floor_size: document.getElementById('nwB_floor_size').value,
  };
  if (!d.name) { alert('건물명을 입력하세요'); return; }
  nwApi('building', 'add', d).then(() => location.reload());
}
</script>
<?php
    nw_foot();
}

// ==========================================================
// 건물 상세 (호실 목록)
// ==========================================================
function nw_page_building(PDO $pdo): void {
    $nw = new Nw($pdo);
    $nw->ensureTable(); // move_out_date 등 스키마 최신화(멱등)
    $id = (int)($_GET['id'] ?? 0);
    $b  = $nw->getBuilding($id);
    if (!$b) { nw_head('건물 없음'); echo "<p>건물을 찾을 수 없습니다.</p>"; nw_foot(); return; }

    $units          = $nw->listUnits($id);
    $contractsByRoom = $nw->listActiveContractsByBuilding($id);
    $paySums  = $nw->paymentSumsByBuilding($id);      // [contract_id][year] => 납부합계
    $allContr = $nw->listAllContractsByBuilding($id); // 호실별 과거+현재 전 계약

    // 호실별 미납 계산용 JS 데이터: unit_id => 그 호실 전 계약(월납부·시작/종료연월·활성·연도별 납부)
    $jsRooms = [];
    foreach ($units as $u) { $jsRooms[(int)$u['id']] = []; } // 모든 호실(공실 포함)을 먼저 초기화
    foreach ($allContr as $cc) {
        $uid = (int)$cc['unit_id'];
        $s = $cc['contract_date'] ?: ($cc['first_contract_date'] ?? '');
        $sy = 0; $sm = 0; if (preg_match('/^(\d{4})-(\d{2})/', (string)$s, $mm)) { $sy = (int)$mm[1]; $sm = (int)$mm[2]; }
        $occEnd = ($cc['move_out_date'] ?? '') ?: ($cc['contract_end_date'] ?? ''); // 재실 종료 = 실제 퇴거일 우선(없으면 계약만료일)
        $ey = 0; $em = 0; if (preg_match('/^(\d{4})-(\d{2})/', (string)$occEnd, $mm)) { $ey = (int)$mm[1]; $em = (int)$mm[2]; }
        $psS = $cc['contract_date'] ? substr((string)$cc['contract_date'], 2, 2) . '.' . substr((string)$cc['contract_date'], 5, 2) : '';
        $psE = $occEnd ? substr((string)$occEnd, 2, 2) . '.' . substr((string)$occEnd, 5, 2) : '';
        $jsRooms[$uid][] = [
            'id'      => (int)$cc['id'],
            'monthly' => (int)$cc['rent_fee'] + (int)$cc['maintenance_fee'],
            'sy' => $sy, 'sm' => $sm, 'ey' => $ey, 'em' => $em,
            'active'  => $cc['status'] === 'active',
            'paid'    => (object)($paySums[(int)$cc['id']] ?? []),
            'tenant'  => (string)$cc['tenant_name'],
            'ext'     => $cc['renewal_type'] === 'extended',
            'ps'      => $psS, 'pe' => $psE,
            'type'    => (string)$cc['contract_type'],
            'deposit' => (int)$cc['deposit'],
            'rent'    => (int)$cc['rent_fee'],
            'mnt'     => (int)$cc['maintenance_fee'],
            'dr'      => (int)$cc['deposit_registered'],
            'renewal' => (string)$cc['renewal_type'],
            'end'     => substr((string)($cc['contract_end_date'] ?? ''), 0, 10),
        ];
    }

    // 공인중개사 → 번호칩 매핑 (건물 내 활성계약 기준, 건수 많은 순)
    $agentCounts = [];
    foreach ($contractsByRoom as $c) {
        $office = trim((string)($c['agent_office'] ?? ''));
        if ($office === '') continue;
        $agentCounts[$office] = ($agentCounts[$office] ?? 0) + 1;
    }
    arsort($agentCounts);
    $agentNum = [];
    $an = 0;
    foreach ($agentCounts as $office => $cnt) { $agentNum[$office] = ++$an; }

    $AGENT_PALETTE = [['#eaf2fb','#2471a3'],['#eafaf1','#1e8449'],['#fef5e7','#b9770e'],['#fdecea','#c0392b'],['#f4ecf7','#7d3c98'],['#e8f6f3','#148f77'],['#fdf2e9','#ca6f1e'],['#eef2f7','#566573']];
    $agentChip = function ($num, $title = '') use ($AGENT_PALETTE) {
        [$bg, $fg] = $AGENT_PALETTE[($num - 1) % count($AGENT_PALETTE)];
        $t = $title !== '' ? ' title="' . nw_h($title) . '"' : '';
        return '<span class="agent-chip" style="background:' . $bg . ';color:' . $fg . ';"' . $t . '>' . $num . '</span>';
    };

    // 계약형태별 통계 (건물 내 활성계약 기준)
    $stats = [
        'jeonse'       => ['cnt' => 0, 'deposit' => 0, 'rent' => 0],
        'semi_monthly' => ['cnt' => 0, 'deposit' => 0, 'rent' => 0],
        'monthly'      => ['cnt' => 0, 'deposit' => 0, 'rent' => 0],
    ];
    $totalMaint = 0;
    $grandDeposit = 0;
    $grandRent = 0;
    foreach ($contractsByRoom as $c) {
        $t = $c['contract_type'];
        if (isset($stats[$t])) {
            $stats[$t]['cnt']++;
            $stats[$t]['deposit'] += (int)$c['deposit'];
            $stats[$t]['rent']    += (int)$c['rent_fee'];
        }
        $totalMaint   += (int)$c['maintenance_fee'];
        $grandDeposit += (int)$c['deposit'];
        $grandRent    += (int)$c['rent_fee'];
    }

    nw_head($b['name']);
?>
<div class="page-head">
  <div>
    <a class="back-link" href="/nw/index.php">← 대시보드</a>
    <h1 style="margin-top:6px;"><?= nw_h($b['name']) ?></h1>
    <div class="sub"><?= nw_h($b['address']) ?> · 토지 <?= nw_pyeong($b['land_size']) ?>평(<?= nw_money($b['land_size']) ?>㎡) · 연면적 <?= nw_pyeong($b['floor_size']) ?>평(<?= nw_money($b['floor_size']) ?>㎡)</div>
  </div>
  <div style="display:flex;gap:8px;">
    <button class="btn btn-outline" onclick="nwGoto('/nw/index.php?mode=import&building_id=<?= $id ?>')">🏦 은행내역 업로드</button>
    <button class="btn btn-primary" onclick="nwOpenModal('nwAddUnitModal')">+ 호실 추가</button>
  </div>
</div>

<div class="stat-bar">
  <div class="sb-item sb-jeonse">
    <span class="sb-type">전세</span>
    <span class="sb-cnt"><?= $stats['jeonse']['cnt'] ?>건</span>
    <span class="sb-detail">보증금 <?= nw_money($stats['jeonse']['deposit'] / 1000000) ?>백만</span>
  </div>
  <div class="sb-item sb-semi">
    <span class="sb-type">반전세</span>
    <span class="sb-cnt"><?= $stats['semi_monthly']['cnt'] ?>건</span>
    <span class="sb-detail">보증금 <?= nw_money($stats['semi_monthly']['deposit'] / 1000000) ?>백만 · 월세 <?= nw_money($stats['semi_monthly']['rent'] / 10000) ?>만</span>
  </div>
  <div class="sb-item sb-monthly">
    <span class="sb-type">월세</span>
    <span class="sb-cnt"><?= $stats['monthly']['cnt'] ?>건</span>
    <span class="sb-detail">보증금 <?= nw_money($stats['monthly']['deposit'] / 1000000) ?>백만 · 월세 <?= nw_money($stats['monthly']['rent'] / 10000) ?>만</span>
  </div>
  <div class="sb-item sb-total">
    <span class="sb-type">합계</span>
    <span class="sb-detail">보증금 <?= number_format($grandDeposit / 100000000, 1) ?>억 · 월세 <?= nw_money($grandRent / 10000) ?>만 · 관리비 <?= nw_money($totalMaint / 10000) ?>만</span>
  </div>
</div>

<style>
.yb-bar{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px;align-items:center;}
.yb-bar .yb-label{font-size:13px;color:#718096;margin-right:2px;}
.ybchip{padding:5px 14px;border:1px solid #cbd5e0;border-radius:16px;background:#fff;cursor:pointer;font-size:13px;color:#4a5568;font-weight:600;}
.ybchip:hover{background:#f7fafc;}
.ybchip.on{background:#2b6cb0;border-color:#2b6cb0;color:#fff;}
.paycell{text-decoration:none;}
.paycell .ps-due{color:#c53030;font-weight:700;}
.paycell .ps-over{color:#b7791f;font-weight:600;}
.paycell .ps-done{color:#2f855a;font-weight:600;}
.paycell .ps-none{color:#cbd5e0;}
.col-tenant .tn-line{margin:2px 0;line-height:1.35;}
.col-tenant .tn-past{opacity:.62;}
.col-tenant .tn-past .tenant-link{color:#718096;font-size:12px;}
.col-tenant .tn-period{font-size:10px;color:#a0aec0;margin-left:4px;white-space:nowrap;}
.col-tenant .tn-vacant{color:#a0aec0;font-size:12px;font-style:italic;}
</style>

<div id="nwbYearBar" class="yb-bar"><span class="yb-label">납부 기준</span></div>

<div class="room-scroll">
<table class="room-table">
  <tr>
    <th>호실</th><th>평수</th><th>근저당</th><th>세입자</th><th>계약형태</th>
    <th>보증금</th><th>월세</th><th>관리비</th><th>납부상태<br><span style="font-size:10px;font-weight:400;color:#95a5a6;">(미납)</span></th><th>만기</th>
    <th>공인중개사</th><th>특약</th><th>관리</th>
  </tr>
  <?php foreach ($units as $u):
        $c = $contractsByRoom[(int)$u['id']] ?? null;
  ?>
  <tr>
    <td><b><?= nw_h($u['room_no']) ?></b>호</td>
    <td><?= nw_pyeong($u['room_size']) ?>평<br><span class="mono" style="font-size:11px;color:#95a5a6;"><?= nw_money($u['room_size']) ?>㎡</span></td>
    <td class="mono"><?= $u['bank_loan'] ? nw_money($u['bank_loan'] / 1000000) . '백만' : '-' ?></td>
    <td class="col-tenant" id="tenant-<?= (int)$u['id'] ?>"></td>
    <td id="ctype-<?= (int)$u['id'] ?>"></td>
    <td class="mono" id="deposit-<?= (int)$u['id'] ?>"></td>
    <td class="mono" id="rent-<?= (int)$u['id'] ?>"></td>
    <td class="mono" id="mnt-<?= (int)$u['id'] ?>"></td>
    <td><a class="paycell" id="paystat-<?= (int)$u['id'] ?>" href="/nw/index.php?mode=payment&room_id=<?= (int)$u['id'] ?>">–</a></td>
    <td id="expiry-<?= (int)$u['id'] ?>"></td>
    <td style="text-align:center;"><?php $office = $c ? trim((string)($c['agent_office'] ?? '')) : ''; echo $office !== '' ? $agentChip($agentNum[$office], $office) : '<span style="color:#dfe4ea;">-</span>'; ?></td>
    <td style="text-align:center;"><?= ($c && $c['special_terms']) ? '📝' : '' ?></td>
    <td>
      <?php if ($c): ?>
      <div class="rowmenu">
        <button class="rowmenu-btn" onclick="nwToggleMenu(event, this)" aria-label="관리 메뉴">⋮</button>
        <div class="rowmenu-pop">
          <?php if ($c['renewal_type'] !== 'implied'): ?>
          <button onclick="nwImpliedRenewal(<?= (int)$c['id'] ?>,<?= (int)$u['id'] ?>)">🔁 묵시적갱신</button>
          <?php endif; ?>
          <button onclick="nwGoto('/nw/index.php?mode=contract&room_id=<?= (int)$u['id'] ?>&renew_from=<?= (int)$c['id'] ?>')">📄 연장(신규계약)</button>
          <button class="danger" onclick="nwEndContract(<?= (int)$c['id'] ?>,'/nw/index.php?mode=building&id=<?= $id ?>')">🚪 계약 만료</button>
        </div>
      </div>
      <?php else: ?>
      <a class="tenant-link" href="/nw/index.php?mode=contract&room_id=<?= (int)$u['id'] ?>" style="font-size:12px;">+ 임차인</a>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$units): ?>
  <tr><td colspan="13" style="text-align:center;color:#bdc3c7;padding:24px;">등록된 호실이 없습니다</td></tr>
  <?php endif; ?>
</table>
</div>

<script>
const NWB = {
  curY: <?= (int)date('Y') ?>, curM: <?= (int)date('n') ?>, today: '<?= date('Y-m-d') ?>',
  rooms: <?= json_encode($jsRooms ?: (object)[], JSON_UNESCAPED_UNICODE) ?>, // {unit_id: [계약...]}
};
const NW_TYPE_LABEL = { jeonse: '전세', semi_monthly: '반전세', monthly: '월세' };
const NW_DASH = '<span style="color:#cbd5e0;">-</span>';
const wonB = n => Number(n || 0).toLocaleString('en-US');
const ymI = (y, m) => y * 12 + (m - 1);
const nowYm = ymI(NWB.curY, NWB.curM);

// 호실별 계약 소유기간 사전계산(시작 오름차순·계약일 없는건 뒤로·각 계약 종료월=만기/없으면 다음계약 직전/오늘·활성은 무한)
for (const uid in NWB.rooms) {
  const cs = NWB.rooms[uid];
  cs.sort((a, b) => (a.sy ? ymI(a.sy, a.sm) : Infinity) - (b.sy ? ymI(b.sy, b.sm) : Infinity));
  cs.forEach((c, i) => {
    c.startYm = c.sy ? ymI(c.sy, c.sm) : null;
    const next = cs[i + 1];
    c.endYm = c.active ? Infinity : (c.ey ? ymI(c.ey, c.em) : (next && next.sy ? ymI(next.sy, next.sm) - 1 : nowYm));
  });
}
// 그 달 담당계약: ①기간 포함(시작 늦은것) ②계약 사이 공백→다음 세입자 ③첫 계약 이전/최종종료 이후=공실(null)
function coverExp(cs, cur){
  let contain = null, hasPrior = false, next = null;
  for (const c of cs) {
    if (c.startYm == null) continue;
    // 포함하는 계약 중 시작이 늦은 것, 시작이 같으면(연장 등) 종료가 늦은(=최신 갱신) 것
    if (c.startYm <= cur && cur <= c.endYm && (!contain || c.startYm > contain.startYm || (c.startYm === contain.startYm && c.endYm > contain.endYm))) contain = c;
    if (c.startYm <= cur) hasPrior = true;
    if (c.startYm > cur && (!next || c.startYm < next.startYm)) next = c;
  }
  if (contain) return contain;
  return hasPrior ? next : null;
}
function roomStartOf(cs){ for (const c of cs) if (c.startYm != null) return c.startYm; return nowYm; }
// 호실 단위 미납: 각 달 담당 계약 월세율 합(이사월·현재/미래월 제외) vs 그 기간 납부합
function roomCalc(cs, sel){
  const roomStartYm = roomStartOf(cs);
  let expected = 0, paid = 0;
  const addYear = (y) => {
    for (let m = 1; m <= 12; m++) {
      const cur = ymI(y, m);
      if (cur >= nowYm) continue;
      const o = coverExp(cs, cur);
      // 신규(initial) 계약의 입주월(시작월)은 월세 없음 → 제외
      if (o && !(o.renewal === 'initial' && cur === o.startYm)) expected += o.monthly;
    }
  };
  if (sel === 'all') {
    for (let y = Math.floor(roomStartYm / 12); y <= NWB.curY; y++) addYear(y);
    cs.forEach(c => { for (const k in c.paid) paid += c.paid[k]; });
  } else {
    const y = +sel; addYear(y);
    cs.forEach(c => { if (c.paid[y]) paid += c.paid[y]; });
  }
  return { expected, paid };
}
const escB = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
// 해당 연도에 이 호실에 실제 거주(담당)했던 계약들 — 표시용(이사월/현재월 제외 안 함)
// 그 해 담당계약들(중복제거) + 공실 여부
function ownersInYear(cs, y){
  const owners = []; let vacant = false;
  for (let m = 1; m <= 12; m++) {
    const o = coverExp(cs, ymI(y, m));
    if (o) { if (owners.indexOf(o) < 0) owners.push(o); }
    else vacant = true;
  }
  return { owners, vacant };
}
function tenantLine(uid, o){
  const klass = o.active ? 'tn-cur' : 'tn-past';
  const ext = o.ext ? ' <span class="badge badge-extended">연장</span>' : '';
  const per = (o.ps || o.pe) ? '<span class="tn-period">' + o.ps + '~' + o.pe + '</span>' : '';
  return '<div class="tn-line ' + klass + '"><a class="tenant-link" href="/nw/index.php?mode=contract&room_id=' + uid + '&id=' + o.id + '">' + (escB(o.tenant) || '(무기명)') + '</a>' + ext + per + '</div>';
}
function setCell(id, html){ const el = document.getElementById(id); if (el) el.innerHTML = html; }
function daysTo(end){ return Math.round((new Date(end) - new Date(NWB.today)) / 86400000); }
// 만기 표시: 활성계약은 D-day/묵시적갱신중 배지, 과거계약은 종료일만
function expiryHtml(o){
  if (!o.active) return o.end ? '<span style="font-size:11px;color:#95a5a6;">~' + o.end + '</span>' : NW_DASH;
  if (!o.end) return o.renewal === 'implied' ? '<span class="badge badge-implied">묵시적 갱신중</span>' : NW_DASH;
  const d = daysTo(o.end);
  let badge;
  if (o.renewal === 'implied' || d < 0) badge = '<span class="badge badge-implied">묵시적 갱신중</span>';
  else if (d <= 7) badge = '<span class="badge badge-danger">D-' + d + '</span>';
  else if (d <= 30) badge = '<span class="badge badge-warn">D-' + d + '</span>';
  else badge = '<span class="badge badge-ok">D-' + d + '</span>';
  return badge + '<br><span style="font-size:11px;color:#95a5a6;">' + o.end + '</span>';
}
// 계약 만료/중도해지: 실제 퇴거일을 입력받아 종료 처리
function nwEndContract(id, reloadUrl){
  const d = prompt('계약 종료 — 실제 퇴거일을 입력하세요 (YYYY-MM-DD)\n※ 계약 만료일 전에 나갔다면 자동으로 중도해지로 기록됩니다', NWB.today);
  if (d === null) return;
  const date = d.trim();
  if (date && !/^\d{4}-\d{2}-\d{2}$/.test(date)) { alert('날짜 형식이 올바르지 않습니다 (예: 2026-05-31)'); return; }
  nwApi('contract', 'end', { id: id, move_out_date: date }).then(() => { window.location.href = reloadUrl; }).catch(() => {});
}
function nwbSelectYear(sel){
  document.querySelectorAll('.ybchip').forEach(ch => ch.classList.toggle('on', ch.dataset.y === String(sel)));
  const y = +sel;
  for (const uid in NWB.rooms) {
    const cs = NWB.rooms[uid];
    const hasActive = cs.some(c => c.active);
    const { owners, vacant } = ownersInYear(cs, y);
    const ordered = owners.slice().sort((a, b) => ((b.startYm || 0) - (a.startYm || 0)) || ((b.endYm || 0) - (a.endYm || 0)));
    const primary = ordered[0] || null; // 그 해 가장 최근(시작 늦은·연장이면 종료 늦은) 계약

    // 세입자 칸: 그 해 세입자들 + 공실
    let th = ordered.map(o => tenantLine(uid, o)).join('');
    if (vacant) {
      const reg = !hasActive ? ' <a class="tenant-link" href="/nw/index.php?mode=contract&room_id=' + uid + '">— 임차인 등록</a>' : '';
      th += '<div class="tn-line tn-vacant">공실' + reg + '</div>';
    }
    setCell('tenant-' + uid, th || NW_DASH);

    // 계약형태/보증금/월세/관리비/만기: 그 해 대표(primary) 계약 기준
    setCell('ctype-' + uid, primary ? ((primary.dr ? '<span style="color:#e74c3c;font-weight:700;">🔒</span> ' : '') + '<span class="ctype ctype-' + primary.type + '">' + (NW_TYPE_LABEL[primary.type] || '') + '</span>') : NW_DASH);
    setCell('deposit-' + uid, primary ? (wonB(Math.round(primary.deposit / 1000000)) + '백만') : NW_DASH);
    setCell('rent-' + uid, primary ? (wonB(Math.round(primary.rent / 10000)) + '만') : NW_DASH);
    setCell('mnt-' + uid, primary ? (wonB(Math.round(primary.mnt / 10000)) + '만') : NW_DASH);
    setCell('expiry-' + uid, primary ? expiryHtml(primary) : NW_DASH);

    // 납부상태(미납)
    const cell = document.getElementById('paystat-' + uid);
    if (cell) {
      const { expected, paid } = roomCalc(cs, sel);
      const diff = expected - paid;
      if (expected === 0 && paid === 0) cell.innerHTML = '<span class="ps-none">–</span>';
      else if (diff > 0) cell.innerHTML = '<span class="ps-due">미납 ' + wonB(diff) + '</span>';
      else if (diff < 0) cell.innerHTML = '<span class="ps-over">초과 ' + wonB(-diff) + '</span>';
      else cell.innerHTML = '<span class="ps-done">완납</span>';
      cell.href = '/nw/index.php?mode=payment&room_id=' + uid + '&year=' + sel;
    }
  }
}
(function(){
  let minY = NWB.curY;
  for (const uid in NWB.rooms) NWB.rooms[uid].forEach(c => { if (c.sy && c.sy < minY) minY = c.sy; });
  let chips = '';
  for (let y = minY; y <= NWB.curY; y++) chips += '<span class="ybchip" data-y="' + y + '" onclick="nwbSelectYear(' + y + ')">' + (y % 100) + '년</span>';
  document.getElementById('nwbYearBar').insertAdjacentHTML('beforeend', chips);
  nwbSelectYear(NWB.curY);
})();
</script>

<!-- 묵시적 갱신: 조건 변경 여부 선택 -->
<div class="modal-overlay" id="nwImpliedModal">
  <div class="modal-box">
    <h3>묵시적 갱신 처리</h3>
    <p style="color:#5c6b7a;font-size:14px;margin:0 0 16px;">월세·보증금·관리비 등 <b>조건 변경</b>이 있나요?</p>
    <div style="display:flex;flex-direction:column;gap:10px;">
      <button class="btn btn-outline" style="text-align:left;padding:12px 14px;line-height:1.4;" onclick="nwImpliedSame()">조건 그대로 갱신<br><span style="color:#8493a2;font-size:12px;font-weight:400;">기존 계약을 '묵시적 갱신중'으로 표시만 (새 계약 없음)</span></button>
      <button class="btn btn-primary" style="text-align:left;padding:12px 14px;line-height:1.4;" onclick="nwImpliedChanged()">조건 변경 있음 — 새 금액 입력<br><span style="color:#dbeafe;font-size:12px;font-weight:400;">변경 시점부터 새 조건인 계약을 생성</span></button>
    </div>
    <div class="modal-actions">
      <button class="btn btn-outline" onclick="nwCloseModal('nwImpliedModal')">취소</button>
    </div>
  </div>
</div>
<script>
let NW_IMPL = { id: 0, room: 0 };
function nwImpliedRenewal(id, roomId){ NW_IMPL = { id: id, room: roomId }; nwOpenModal('nwImpliedModal'); }
function nwImpliedSame(){
  nwCloseModal('nwImpliedModal');
  nwApi('contract', 'impliedRenewal', { id: NW_IMPL.id }).then(() => { location.href = '/nw/index.php?mode=building&id=<?= $id ?>'; }).catch(() => {});
}
function nwImpliedChanged(){
  location.href = '/nw/index.php?mode=contract&room_id=' + NW_IMPL.room + '&renew_from=' + NW_IMPL.id + '&implied=1';
}
</script>

<?php if ($agentNum): ?>
<div class="agent-legend">
  <span class="al-title">공인중개사</span>
  <?php foreach ($agentNum as $office => $num): ?>
  <span class="al-item"><?= $agentChip($num) ?> <?= nw_h($office) ?> <b><?= (int)$agentCounts[$office] ?>개</b></span>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="modal-overlay" id="nwAddUnitModal">
  <div class="modal-box">
    <h3>호실 추가</h3>
    <div class="form-field"><label>호실 (예: 101, B01)</label><input type="text" id="nwU_room_no"></div>
    <div class="form-field"><label>임대면적(㎡)</label><input type="number" id="nwU_room_size"></div>
    <div class="form-field"><label>근저당(원)</label><input type="number" id="nwU_bank_loan"></div>
    <div class="modal-actions">
      <button class="btn btn-outline" onclick="nwCloseModal('nwAddUnitModal')">취소</button>
      <button class="btn btn-primary" onclick="nwSubmitAddUnit()">등록</button>
    </div>
  </div>
</div>
<script>
function nwSubmitAddUnit() {
  const d = {
    building_id: <?= $id ?>,
    room_no: document.getElementById('nwU_room_no').value,
    room_size: document.getElementById('nwU_room_size').value,
    bank_loan: document.getElementById('nwU_bank_loan').value,
  };
  if (!d.room_no) { alert('호실 번호를 입력하세요'); return; }
  nwApi('unit', 'add', d).then(() => location.reload());
}
</script>
<?php
    nw_foot();
}

// ==========================================================
// 계약 등록/수정/연장 폼
// ==========================================================
function nw_page_contract(PDO $pdo): void {
    $nw = new Nw($pdo);
    $roomId    = (int)($_GET['room_id'] ?? 0);
    $editId    = (int)($_GET['id'] ?? 0);
    $renewFrom = (int)($_GET['renew_from'] ?? 0);
    $isImplied = ($_GET['implied'] ?? '') === '1'; // 묵시적갱신+조건변경 진입

    $unit = $nw->getUnit($roomId);
    if (!$unit) { nw_head('호실 없음'); echo "<p>호실을 찾을 수 없습니다.</p>"; nw_foot(); return; }
    $building = $nw->getBuilding((int)$unit['building_id']);

    $c = null;
    $saveAction = 'add';
    if ($editId) {
        $c = $nw->getContract($editId);
        $saveAction = 'update';
    } elseif ($renewFrom) {
        $old = $nw->getContract($renewFrom);
        if ($old) {
            $c = $old;
            $c['contract_date'] = $c['balance_date'] = $c['contract_end_date'] = '';
        }
        $saveAction = 'renew';
    }
    $c = $c ?? [];
    $get = fn($k, $def = '') => nw_h($c[$k] ?? $def);
    $money = fn($k) => nw_money($c[$k] ?? 0);
    // 주민번호를 앞6/뒤7로 분리 (하이픈 유무 무관하게 숫자만 추출)
    $ssnDigits = fn($k) => preg_replace('/\D/', '', (string)($c[$k] ?? ''));
    $ssnFront  = fn($k) => nw_h(substr($ssnDigits($k), 0, 6));
    $ssnBack   = fn($k) => nw_h(substr($ssnDigits($k), 6, 7));

    nw_head(($editId ? '계약 수정' : ($renewFrom ? '계약 연장' : '임차인 등록')));
?>
<div class="page-head">
  <div>
    <a class="back-link" href="/nw/index.php?mode=building&id=<?= (int)$unit['building_id'] ?>">← 호실 목록</a>
    <h1 style="margin-top:6px;"><?= nw_h($building['name']) ?> (<?= nw_h($unit['room_no']) ?>호) <?= $renewFrom ? '계약 연장' : ($editId ? '계약 수정' : '임차인 정보 등록') ?></h1>
  </div>
</div>

<?php if ($renewFrom): ?>
<div style="display:flex;flex-wrap:wrap;gap:16px;align-items:center;background:#eaf2fb;border:1px solid #bcd7f0;border-radius:8px;padding:12px 16px;margin-bottom:16px;">
  <span style="font-weight:700;color:#2b6cb0;">갱신 유형</span>
  <label style="cursor:pointer;"><input type="radio" name="renewal_type" value="extended" <?= $isImplied ? '' : 'checked' ?>> 계약 연장 <span style="color:#7f8c8d;font-size:12px;">(새 계약서)</span></label>
  <label style="cursor:pointer;"><input type="radio" name="renewal_type" value="implied" <?= $isImplied ? 'checked' : '' ?>> 묵시적 갱신 <span style="color:#7f8c8d;font-size:12px;">(무서류 자동연장)</span></label>
  <span style="color:#7f8c8d;font-size:12px;">· 변경된 월세/보증금/관리비는 아래에 입력하면 그 시점부터 적용됩니다</span>
</div>
<?php endif; ?>

<div class="card form-card">
  <div class="form-card-head">
    <div class="radio-group">
      <?php foreach (NW_CONTRACT_TYPE_LABEL as $val => $label): ?>
      <label><input type="radio" name="contract_type" value="<?= $val ?>" <?= ($c['contract_type'] ?? 'monthly') === $val ? 'checked' : '' ?>> <?= $label ?></label>
      <?php endforeach; ?>
    </div>
    <label class="check-field"><input type="checkbox" id="f_deposit_registered" <?= !empty($c['deposit_registered']) ? 'checked' : '' ?>> 전세권설정</label>
  </div>

  <div class="form-section">
    <div class="form-section-title">임차인 정보</div>
    <div class="form-grid">
      <div class="form-field"><label>이름</label><input type="text" id="f_tenant_name" value="<?= $get('tenant_name') ?>"></div>
      <div class="form-field"><label>주민번호</label>
        <div class="ssn-group">
          <input type="text" inputmode="numeric" maxlength="6" id="f_tenant_ssn1" value="<?= $ssnFront('tenant_ssn') ?>" placeholder="앞 6자리">
          <span class="dash">–</span>
          <input type="text" inputmode="numeric" maxlength="7" id="f_tenant_ssn2" value="<?= $ssnBack('tenant_ssn') ?>" placeholder="뒤 7자리">
        </div>
      </div>
      <div class="form-field"><label>연락처</label><input type="text" id="f_tenant_phone" value="<?= $get('tenant_phone') ?>"></div>

      <div class="form-field"><label>공동임차인</label><input type="text" id="f_co_tenant_name" value="<?= $get('co_tenant_name') ?>"></div>
      <div class="form-field"><label>주민번호</label>
        <div class="ssn-group">
          <input type="text" inputmode="numeric" maxlength="6" id="f_co_tenant_ssn1" value="<?= $ssnFront('co_tenant_ssn') ?>" placeholder="앞 6자리">
          <span class="dash">–</span>
          <input type="text" inputmode="numeric" maxlength="7" id="f_co_tenant_ssn2" value="<?= $ssnBack('co_tenant_ssn') ?>" placeholder="뒤 7자리">
        </div>
      </div>
      <div class="form-field"><label>연락처</label><input type="text" id="f_co_tenant_phone" value="<?= $get('co_tenant_phone') ?>"></div>
    </div>
  </div>

  <div class="form-section">
    <div class="form-section-title">계약 조건</div>
    <div class="form-grid">
      <div class="form-field"><label>보증금</label><div class="input-money"><input type="text" inputmode="numeric" class="money" id="f_deposit" value="<?= $money('deposit') ?>"></div></div>
      <div class="form-field"><label>계약금</label><div class="input-money"><input type="text" inputmode="numeric" class="money" id="f_down_payment" value="<?= $money('down_payment') ?>"></div></div>
      <div class="form-field"><label>잔금</label><div class="input-money"><input type="text" inputmode="numeric" class="money" id="f_balance_amount" value="<?= $money('balance_amount') ?>"></div></div>

      <div class="form-field"><label>계약일</label><input type="date" id="f_contract_date" value="<?= $get('contract_date') ?>"></div>
      <div class="form-field"><label>잔금일</label><input type="date" id="f_balance_date" value="<?= $get('balance_date') ?>"></div>
      <div class="form-field"><label>~ 만료일 <span style="color:#a0aec0;font-size:11px;">(계약서상)</span></label><input type="date" id="f_contract_end_date" value="<?= $get('contract_end_date') ?>"></div>
      <div class="form-field"><label>실제 퇴거일 <span style="color:#a0aec0;font-size:11px;">(재실중 비움)</span></label><input type="date" id="f_move_out_date" value="<?= $get('move_out_date') ?>"></div>

      <div class="form-field"><label>차임(월세)</label><div class="input-money"><input type="text" inputmode="numeric" class="money" id="f_rent_fee" value="<?= $money('rent_fee') ?>"></div></div>
      <div class="form-field"><label>관리비</label><div class="input-money"><input type="text" inputmode="numeric" class="money" id="f_maintenance_fee" value="<?= $money('maintenance_fee') ?>"></div></div>
      <div class="form-field"><label>납부일 (매월)</label><input type="number" min="1" max="31" id="f_rent_pay_day" value="<?= $get('rent_pay_day') ?>"></div>
    </div>
  </div>

  <div class="form-section">
    <div class="form-section-title">중개 정보</div>
    <div class="form-grid">
      <div class="form-field"><label>공인중개업소</label><input type="text" id="f_agent_office" value="<?= $get('agent_office') ?>"></div>
      <div class="form-field"><label>전화번호</label><input type="text" id="f_agent_phone" value="<?= $get('agent_phone') ?>"></div>
      <div class="form-field"><label>대표자</label><input type="text" id="f_agent_ceo" value="<?= $get('agent_ceo') ?>"></div>
      <div class="form-field full"><label>특약사항</label><textarea id="f_special_terms"><?= $get('special_terms') ?></textarea></div>
    </div>
  </div>

  <div class="form-actions">
    <button class="btn btn-outline" onclick="nwGoto('/nw/index.php?mode=building&id=<?= (int)$unit['building_id'] ?>')">취소</button>
    <button class="btn btn-primary" onclick="nwSubmitContract()">저장</button>
  </div>
</div>

<script>
function nwSubmitContract() {
  const rt = document.querySelector('input[name=contract_type]:checked');
  const d = {
    room_id: <?= $roomId ?>,
    contract_type: rt ? rt.value : 'monthly',
    tenant_name: document.getElementById('f_tenant_name').value,
    tenant_ssn: nwSsn('f_tenant_ssn1', 'f_tenant_ssn2'),
    tenant_phone: document.getElementById('f_tenant_phone').value,
    co_tenant_name: document.getElementById('f_co_tenant_name').value,
    co_tenant_ssn: nwSsn('f_co_tenant_ssn1', 'f_co_tenant_ssn2'),
    co_tenant_phone: document.getElementById('f_co_tenant_phone').value,
    deposit: nwUnmoney('f_deposit'),
    deposit_registered: document.getElementById('f_deposit_registered').checked ? 1 : 0,
    down_payment: nwUnmoney('f_down_payment'),
    contract_date: document.getElementById('f_contract_date').value,
    balance_amount: nwUnmoney('f_balance_amount'),
    balance_date: document.getElementById('f_balance_date').value,
    contract_end_date: document.getElementById('f_contract_end_date').value,
    move_out_date: document.getElementById('f_move_out_date').value,
    rent_fee: nwUnmoney('f_rent_fee'),
    maintenance_fee: nwUnmoney('f_maintenance_fee'),
    rent_pay_day: document.getElementById('f_rent_pay_day').value,
    agent_office: document.getElementById('f_agent_office').value,
    agent_phone: document.getElementById('f_agent_phone').value,
    agent_ceo: document.getElementById('f_agent_ceo').value,
    special_terms: document.getElementById('f_special_terms').value,
  };
  if (!d.tenant_name) { alert('이름을 입력하세요'); return; }
  if (!confirm('등록하시겠습니까?')) return;

  <?php if ($saveAction === 'update'): ?>
  d.id = <?= (int)$editId ?>;
  nwApi('contract', 'update', d).then(() => nwGoto('/nw/index.php?mode=building&id=<?= (int)$unit['building_id'] ?>'));
  <?php elseif ($saveAction === 'renew'): ?>
  d.renew_from = <?= (int)$renewFrom ?>;
  d.renewal_type = (document.querySelector('input[name=renewal_type]:checked') || {}).value || 'extended';
  nwApi('contract', 'renew', d).then(() => nwGoto('/nw/index.php?mode=building&id=<?= (int)$unit['building_id'] ?>'));
  <?php else: ?>
  nwApi('contract', 'add', d).then(() => nwGoto('/nw/index.php?mode=building&id=<?= (int)$unit['building_id'] ?>'));
  <?php endif; ?>
}
</script>
<?php
    nw_foot();
}

// ==========================================================
// 월세/관리비 납부 이력
// ==========================================================
function nw_page_payment(PDO $pdo): void {
    $nw = new Nw($pdo);
    // 호실 중심: room_id 우선, 없으면 contract_id로 호실을 역추적(구 링크 호환)
    $roomId = (int)($_GET['room_id'] ?? 0);
    if (!$roomId && ($cid = (int)($_GET['contract_id'] ?? 0))) {
        $c0 = $nw->getContract($cid);
        if ($c0) $roomId = (int)$c0['room_id'];
    }
    $nw->ensureTable(); // move_out_date 등 스키마 최신화(멱등)
    $unit = $roomId ? $nw->getUnit($roomId) : null;
    if (!$unit) { nw_head('호실 없음'); echo "<p>호실을 찾을 수 없습니다.</p>"; nw_foot(); return; }

    $building  = $nw->getBuilding((int)$unit['building_id']);
    $contracts = $nw->listContractsByRoom($roomId);   // id DESC
    $payments  = $nw->listPaymentsByRoom($roomId);    // pay_date DESC, 세입자명 포함
    $preYear   = (int)($_GET['year'] ?? 0);

    // focus 계약: URL contract_id 우선 → 활성 → 최신. 이 계약의 기간만 보여준다.
    $focusCid = (int)($_GET['contract_id'] ?? 0);
    $head = null;
    if ($focusCid) foreach ($contracts as $cc) if ((int)$cc['id'] === $focusCid) { $head = $cc; break; }
    if (!$head)    foreach ($contracts as $cc) if ($cc['status'] === 'active') { $head = $cc; break; }
    if (!$head)    $head = $contracts[0] ?? null;
    $focusId = $head ? (int)$head['id'] : 0;

    // JS용 계약 배열(호실 전 계약) + 세입자 전환 칩용 기간 라벨
    $jsC = [];
    foreach ($contracts as $cc) {
        $s = $cc['contract_date'] ?: ($cc['first_contract_date'] ?? '');
        $sy = 0; $sm = 0; if (preg_match('/^(\d{4})-(\d{2})/', (string)$s, $m)) { $sy = (int)$m[1]; $sm = (int)$m[2]; }
        $occEnd = ($cc['move_out_date'] ?? '') ?: ($cc['contract_end_date'] ?? ''); // 재실 종료 = 실제 퇴거일 우선
        $ey = 0; $em = 0; if (preg_match('/^(\d{4})-(\d{2})/', (string)$occEnd, $m)) { $ey = (int)$m[1]; $em = (int)$m[2]; }
        $psS = $sy ? substr((string)$sy, 2, 2) . '.' . sprintf('%02d', $sm) : '';
        $psE = $occEnd ? substr((string)$occEnd, 2, 2) . '.' . substr((string)$occEnd, 5, 2) : '';
        $jsC[] = [
            'id' => (int)$cc['id'], 'tenant' => (string)$cc['tenant_name'],
            'rent' => (int)$cc['rent_fee'], 'mnt' => (int)$cc['maintenance_fee'],
            'monthly' => (int)$cc['rent_fee'] + (int)$cc['maintenance_fee'],
            'sy' => $sy, 'sm' => $sm, 'ey' => $ey, 'em' => $em,
            'active' => $cc['status'] === 'active',
            'renewal' => (string)$cc['renewal_type'],
            'ps' => $psS, 'pe' => $psE,
            'down' => (int)$cc['down_payment'], 'bal' => (int)$cc['balance_amount'],
            'cdate' => substr((string)($cc['contract_date'] ?? ''), 0, 10),
            'bdate' => substr((string)($cc['balance_date'] ?? ''), 0, 10),
        ];
    }

    nw_head('월세 납부 현황');
?>
<style>
.nw-body{max-width:940px;}
.tenant-bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:14px;}
.tenant-bar .tb-label{font-size:13px;color:#718096;margin-right:2px;}
.tchip{padding:6px 14px;border:1px solid #cbd5e0;border-radius:18px;background:#fff;cursor:pointer;font-size:14px;color:#4a5568;font-weight:600;text-decoration:none;display:inline-block;}
.tchip:hover{background:#f7fafc;}
.tchip.on{background:#2d3748;border-color:#2d3748;color:#fff;}
.tchip .tc-per{font-size:11px;font-weight:400;opacity:.8;margin-left:5px;}
.year-bar{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;}
.year-chip{padding:6px 16px;border:1px solid #cbd5e0;border-radius:18px;background:#fff;cursor:pointer;font-size:14px;color:#4a5568;font-weight:600;}
.year-chip:hover{background:#f7fafc;}
.year-chip.on{background:#2b6cb0;border-color:#2b6cb0;color:#fff;}
.year-chip .yc-sub{font-size:11px;font-weight:600;color:#c53030;margin-left:5px;}
.year-chip.on .yc-sub{color:#fed7d7;}
.year-summary{display:flex;flex-wrap:wrap;gap:8px 18px;align-items:center;background:#f7fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 18px;margin-bottom:14px;}
.year-summary .ys-title{font-weight:700;color:#2d3748;margin-right:6px;}
.year-summary .ys-stat{font-size:14px;color:#4a5568;}
.year-summary .ys-stat b{font-size:16px;color:#2b6cb0;margin-left:4px;}
.year-summary .ys-due b{color:#c53030;}
.year-summary .ys-over b{color:#b7791f;}
.year-summary .ys-done b{color:#2f855a;}
.mstat{display:inline-block;padding:2px 10px;border-radius:11px;font-size:12px;font-weight:700;}
.ms-paid{background:#e8f7ef;color:#1e8449;}
.ms-due{background:#fdecea;color:#c53030;}
.ms-soon{background:#eef2f7;color:#8493a2;}
.ms-none{background:transparent;color:#c0cad4;}
.ms-dep{background:#eaf2fb;color:#2471a3;}
.hist-table tr.dep-row{background:#f6faff;}
.hist-table .hdim{color:#c0cad4;}
.hist-table tr.editable{cursor:pointer;}
.hist-table tr.editable:hover{background:#f0f6fc;}
.nwep-row{border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin-bottom:10px;}
.nwep-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;}
.nwep-grid label{font-size:12px;color:#718096;display:block;margin-bottom:2px;}
.nwep-grid input{width:100%;padding:6px 8px;border:1px solid #cbd5e0;border-radius:6px;}
.nwep-actions{display:flex;justify-content:space-between;gap:8px;}
.pay-attr{font-size:12px;color:#2b6cb0;margin-top:4px;min-height:16px;}
.hist-table td .tn{display:inline-block;font-size:11px;color:#718096;}
</style>

<div class="page-head">
  <div>
    <a class="back-link" href="/nw/index.php?mode=building&id=<?= (int)$unit['building_id'] ?>">← 호실 목록</a>
    <h1 style="margin-top:6px;"><?= nw_h($building['name']) ?> (<?= nw_h($unit['room_no']) ?>호)<?= $head ? ' — ' . nw_h($head['tenant_name']) : '' ?></h1>
    <div class="sub">
      <?php if ($head): ?><?= nw_h($head['tenant_phone']) ?> · 현재계약 <?= nw_h($head['contract_date']) ?> ~ <?= nw_h($head['contract_end_date']) ?><?php endif; ?>
      <?php if (count($contracts) > 1): ?> · <b>계약 이력 <?= count($contracts) ?>건</b>(과거 세입자 포함)<?php endif; ?>
    </div>
  </div>
</div>

<div class="pay-summary">
  <div class="card"><div class="lbl">월세(현재)</div><div class="val mono"><?= nw_money($head['rent_fee'] ?? 0) ?>원</div></div>
  <div class="card"><div class="lbl">관리비(현재)</div><div class="val mono"><?= nw_money($head['maintenance_fee'] ?? 0) ?>원</div></div>
  <div class="card"><div class="lbl">계약 이력</div><div class="val"><?= count($contracts) ?>건</div></div>
</div>

<div class="card" style="margin-bottom:16px;">
  <div class="pay-form">
    <div class="form-field"><label>월세</label><div class="input-money"><input type="text" inputmode="numeric" class="money" id="p_rent_fee" value="<?= nw_money($head['rent_fee'] ?? 0) ?>"></div></div>
    <div class="form-field"><label>관리비</label><div class="input-money"><input type="text" inputmode="numeric" class="money" id="p_maintenance_fee" value="<?= nw_money($head['maintenance_fee'] ?? 0) ?>"></div></div>
    <div class="form-field"><label>납부일</label><input type="date" id="p_pay_date" value="<?= date('Y-m-d') ?>" onchange="nwAttrUpdate()"></div>
    <div class="form-field"><label>귀속월</label><input type="month" id="p_bill_ym" value="<?= date('Y-m') ?>"></div>
    <div class="form-field"><label>메모</label><input type="text" id="p_memo"></div>
    <button class="btn btn-primary" onclick="nwAddPayment()">등록</button>
  </div>
</div>

<div id="nwTenantBar" class="tenant-bar"></div>
<div id="nwYearBar" class="year-bar"></div>
<div id="nwYearSummary" class="year-summary" style="display:none;"></div>

<table class="hist-table" id="nwHist">
  <thead><tr><th>월</th><th>세입자</th><th>상태</th><th>월세</th><th>관리비</th><th>납부일</th><th>납부금액</th><th>메모</th></tr></thead>
  <tbody id="nwHistBody"></tbody>
</table>

<script>
const NWP = {
  curY: <?= (int)date('Y') ?>, curM: <?= (int)date('n') ?>,
  preYear: <?= $preYear ?>, focusId: <?= $focusId ?>, roomId: <?= (int)$roomId ?>,
  contracts: <?= json_encode($jsC, JSON_UNESCAPED_UNICODE) ?>,
  payments: <?= json_encode(array_map(fn($p) => ['id' => (int)$p['id'], 'date' => substr((string)$p['pay_date'], 0, 10), 'bym' => ($p['bill_ym'] ?: substr((string)$p['pay_date'], 0, 7)), 'rent' => (int)$p['rent_fee'], 'mnt' => (int)$p['maintenance_fee'], 'memo' => (string)$p['memo'], 'cid' => (int)$p['contract_id'], 'tenant' => (string)$p['tenant_name']], $payments), JSON_UNESCAPED_UNICODE) ?>,
};

const won = n => Number(n || 0).toLocaleString('en-US');
const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const ymI = (y, m) => y * 12 + (m - 1);

// 계약 시작일 오름차순 정렬 + 각 계약 소유기간(startYm/endYm). 종료계약은 계약만기(없으면 다음계약 직전/오늘)로 상한, 활성은 무한.
NWP.contracts.sort((a, b) => (a.sy ? ymI(a.sy, a.sm) : Infinity) - (b.sy ? ymI(b.sy, b.sm) : Infinity));
const nowYm = ymI(NWP.curY, NWP.curM);
NWP.contracts.forEach((c, i) => {
  c.startYm = c.sy ? ymI(c.sy, c.sm) : null;
  const next = NWP.contracts[i + 1];
  c.endYm = c.active ? Infinity : (c.ey ? ymI(c.ey, c.em) : (next && next.sy ? ymI(next.sy, next.sm) - 1 : nowYm));
});
let roomStartYm = nowYm;
for (const c of NWP.contracts) { if (c.startYm != null) { roomStartYm = c.startYm; break; } }

// ★focus 계약: 이 계약의 기간만 보여준다(호정인으로 들어오면 호정인 기간만)
const FOCUS = NWP.contracts.find(c => c.id === NWP.focusId) || NWP.contracts.find(c => c.active) || NWP.contracts[NWP.contracts.length - 1] || null;
const focusStartYm = FOCUS && FOCUS.startYm != null ? FOCUS.startYm : roomStartYm;
let focusEndYm = nowYm;
if (FOCUS) {
  // 활성(재실·묵시적갱신)이면 계약만료와 무관하게 오늘(현재월)까지. 종료계약이면 종료월까지.
  focusEndYm = FOCUS.active ? nowYm : (FOCUS.ey ? ymI(FOCUS.ey, FOCUS.em) : nowYm);
}
// 신규(initial) 계약의 시작월 = 입주월 → 월세는 다음달부터(그 달은 미납 아님). 연장/묵시적은 이어짐이라 제외 안함.
const moveInYm = (FOCUS && FOCUS.renewal === 'initial') ? focusStartYm : -1;

// 그 달 실제 담당계약: ①기간 포함(시작 늦은것) ②계약 사이 공백→다음 세입자 ③첫 계약 이전/최종종료 이후=공실(null)
function coverExp(cur){
  let contain = null, hasPrior = false, next = null;
  for (const c of NWP.contracts) {
    if (c.startYm == null) continue;
    // 포함하는 계약 중 시작이 늦은 것, 시작이 같으면(연장 등) 종료가 늦은(=최신 갱신) 것
    if (c.startYm <= cur && cur <= c.endYm && (!contain || c.startYm > contain.startYm || (c.startYm === contain.startYm && c.endYm > contain.endYm))) contain = c;
    if (c.startYm <= cur) hasPrior = true;
    if (c.startYm > cur && (!next || c.startYm < next.startYm)) next = c;
  }
  if (contain) return contain;
  return hasPrior ? next : null;
}
// 납부 귀속(항상 반환): 담당계약 없으면 그 이전 마지막 계약
function coverAt(cur){
  const o = coverExp(cur); if (o) return o;
  let prev = null;
  for (const c of NWP.contracts) { if (c.startYm != null && c.startYm <= cur) prev = c; }
  return prev || NWP.contracts[0] || null;
}
function attrAt(dstr){ const m = /^(\d{4})-(\d{2})/.exec(dstr || ''); return m ? coverAt(ymI(+m[1], +m[2])) : (NWP.contracts[NWP.contracts.length - 1] || null); }

// 연도 칩 = focus 계약이 걸친 연도(과거~현재). 미래 연도는 제외.
const years = [];
const _endY = Math.min(Math.floor(focusEndYm / 12), NWP.curY);
for (let y = Math.floor(focusStartYm / 12); y <= _endY; y++) years.push(y);
if (!years.length) years.push(NWP.curY);

// 해당 연도 납부예정: focus 기간 내 달만, focus 계약 월세율. 호실 최초 이사월·현재/미래월 제외.
function expectedFor(y){
  let exp = 0, n = 0;
  if (!FOCUS) return { exp, n };
  for (let m = 1; m <= 12; m++) {
    const cur = ymI(y, m);
    if (cur < focusStartYm || cur > focusEndYm) continue; // focus 기간 밖
    if (cur === moveInYm) continue;   // 입주월 제외(월세는 다음달부터)
    if (cur >= nowYm) continue;       // 현재월·미래 제외(직전월까지)
    exp += FOCUS.monthly; n++;
  }
  return { exp, n };
}
function paidFor(y){
  let s = 0;
  NWP.payments.forEach(p => { const mm = /^(\d{4})-(\d{2})/.exec(p.bym); if (mm && +mm[1] === y) { const cur = ymI(+mm[1], +mm[2]); if (cur >= focusStartYm && cur <= focusEndYm) s += p.rent + p.mnt; } }); // 귀속월 기준
  return s;
}

function nwSelectYear(sel){
  document.querySelectorAll('.year-chip').forEach(c => c.classList.toggle('on', c.dataset.y === String(sel)));
  const isAll = sel === 'all';
  const yy = isAll ? years.slice() : [+sel];

  // 요약(전체면 focus 계약 기간 합산)
  let exp = 0, paid = 0, n = 0;
  yy.forEach(yr => { const e = expectedFor(yr); exp += e.exp; n += e.n; paid += paidFor(yr); });
  const diff = paid - exp;
  let status;
  if (diff < 0)      status = '<span class="ys-stat ys-due">미납<b>' + won(-diff) + '원</b></span>';
  else if (diff > 0) status = '<span class="ys-stat ys-over">초과<b>' + won(diff) + '원</b></span>';
  else               status = '<span class="ys-stat ys-done">완납<b>✓</b></span>';
  document.getElementById('nwYearSummary').innerHTML =
    '<span class="ys-title">' + (isAll ? '전체 기간' : (sel + '년')) + ' · ' + n + '개월</span>' +
    '<span class="ys-stat">납부예정<b>' + won(exp) + '원</b></span>' +
    '<span class="ys-stat">납부완료<b>' + won(paid) + '원</b></span>' + status;
  document.getElementById('nwYearSummary').style.display = '';

  // 월별 표(귀속월 기준). 전체면 여러 연도 연속·월 라벨에 연도(YY.MM) 표시.
  const byMonth = {}; // 'YYYY-MM' => [pays]
  NWP.payments.forEach(p => { const mm = /^(\d{4})-(\d{2})/.exec(p.bym); if (mm) { const k = mm[1] + '-' + mm[2]; (byMonth[k] = byMonth[k] || []).push(p); } });
  const dash = '<span class="hdim">-</span>';
  const ftn = FOCUS ? esc(FOCUS.tenant || '(무기명)') : dash;
  let mh = '';
  // 보증금(계약금·잔금) 행 — focus 계약, 전체 또는 계약 시작연도에 표시
  if (FOCUS && (isAll || +sel === FOCUS.sy) && (FOCUS.down || FOCUS.bal)) {
    const depRow = (label, amt, date, memo) =>
      '<tr class="dep-row"><td><b>' + label + '</b></td><td><span class="tn">' + ftn + '</span></td>' +
      '<td><span class="mstat ms-dep">보증금</span></td>' +
      '<td class="mono">' + dash + '</td><td class="mono">' + dash + '</td>' +
      '<td class="mono">' + (date || dash) + '</td><td class="mono"><b>' + won(amt) + '원</b></td><td>' + (memo || label) + '</td></tr>';
    mh += depRow('계약금', FOCUS.down, FOCUS.cdate);
    mh += depRow('잔금', FOCUS.bal, FOCUS.bdate, '잔금 및 입주');
  }
  yy.forEach(yr => {
    for (let m = 1; m <= 12; m++) {
      const cur = ymI(yr, m);
      if (!FOCUS || cur < focusStartYm || cur > focusEndYm) continue; // focus 계약 기간 밖 월 제외
      const key = yr + '-' + String(m).padStart(2, '0');
      const pays = byMonth[key] || [];
      if (cur === moveInYm && !pays.length) continue; // 입주월은 월세 없음(잔금 행에 "잔금 및 입주"로 표기) → 행 생략
      const label = isAll ? (String(yr).slice(2) + '.' + String(m).padStart(2, '0')) : (m + '월');
      let st, cls, rentCell, mntCell, dateCell, amtCell, memoCell;
      if (pays.length) {
        st = '납부'; cls = 'ms-paid';
        const pr = pays.reduce((s, p) => s + p.rent, 0), pm = pays.reduce((s, p) => s + p.mnt, 0);
        rentCell = won(pr) + '원'; mntCell = won(pm) + '원';
        dateCell = pays.map(p => p.date).join('<br>');
        amtCell = '<b>' + won(pr + pm) + '원</b>';
        memoCell = pays.some(p => p.memo) ? pays.map(p => esc(p.memo || '')).join('<br>') : dash;
      } else {
        dateCell = amtCell = memoCell = dash;
        if (cur >= nowYm)  { st = '예정'; cls = 'ms-soon'; rentCell = '<span class="hdim">' + won(FOCUS.rent) + '</span>'; mntCell = '<span class="hdim">' + won(FOCUS.mnt) + '</span>'; }
        else               { st = '미납'; cls = 'ms-due'; rentCell = '<span class="hdim">' + won(FOCUS.rent) + '</span>'; mntCell = '<span class="hdim">' + won(FOCUS.mnt) + '</span>'; }
      }
      const trAttr = pays.length ? ' class="editable" title="클릭해 수정" onclick="nwEditMonth(\'' + key + '\')"' : '';
      mh += '<tr' + trAttr + '><td><b>' + label + '</b></td><td><span class="tn">' + ftn + '</span></td>' +
            '<td><span class="mstat ' + cls + '">' + st + '</span></td>' +
            '<td class="mono">' + rentCell + '</td><td class="mono">' + mntCell + '</td>' +
            '<td class="mono">' + dateCell + '</td><td class="mono">' + amtCell + '</td><td>' + memoCell + '</td></tr>';
    }
  });
  document.getElementById('nwHistBody').innerHTML = mh || '<tr><td colspan="8" style="color:#bdc3c7;padding:20px;text-align:center;">해당 없음</td></tr>';
}

function nwRenderYears(){
  let h = '<div class="year-chip" data-y="all" onclick="nwSelectYear(\'all\')">전체</div>';
  h += years.map(y => {
    const e = expectedFor(y), paid = paidFor(y);
    const dot = (e.exp > 0 && paid < e.exp) ? ' <span class="yc-sub">미납</span>' : '';
    return '<div class="year-chip" data-y="' + y + '" onclick="nwSelectYear(' + y + ')">' + y + '년' + dot + '</div>';
  }).join('');
  document.getElementById('nwYearBar').innerHTML = h;
  let def = (NWP.preYear && years.indexOf(NWP.preYear) >= 0) ? NWP.preYear : (years.indexOf(NWP.curY) >= 0 ? NWP.curY : years[years.length - 1]);
  if (def != null) nwSelectYear(def);
}
// 세입자 전환 칩 (호실에 계약 여럿일 때) — 클릭하면 그 세입자 기간으로 스코프
function nwRenderTenantBar(){
  const bar = document.getElementById('nwTenantBar');
  if (!bar) return;
  if (NWP.contracts.length <= 1) { bar.style.display = 'none'; return; }
  let h = '<span class="tb-label">세입자</span>';
  NWP.contracts.forEach(c => {
    const on = (FOCUS && c.id === FOCUS.id) ? ' on' : '';
    const per = (c.ps || c.pe) ? '<span class="tc-per">' + c.ps + '~' + c.pe + '</span>' : '';
    h += '<a class="tchip' + on + '" href="/nw/index.php?mode=payment&room_id=' + NWP.roomId + '&contract_id=' + c.id + '">' + esc(c.tenant || '(무기명)') + per + '</a>';
  });
  bar.innerHTML = h;
}
nwRenderTenantBar();
nwRenderYears();

// 납부일 → 귀속 계약(세입자) 표시 + 월세/관리비 자동 채움
function nwAttrUpdate(){
  const date = document.getElementById('p_pay_date').value;
  const c = attrAt(date);
  if (c) {
    document.getElementById('p_rent_fee').value = won(c.rent);
    document.getElementById('p_maintenance_fee').value = won(c.mnt);
  }
  const bm = document.getElementById('p_bill_ym'); if (bm && date) bm.value = date.slice(0, 7); // 귀속월 기본=납부일 월
}
nwAttrUpdate();

function nwAddPayment() {
  const date = document.getElementById('p_pay_date').value;
  const c = attrAt(date);
  if (!c) { alert('납부일에 해당하는 계약을 찾을 수 없습니다.'); return; }
  const d = {
    contract_id: c.id,
    rent_fee: document.getElementById('p_rent_fee').value,
    maintenance_fee: document.getElementById('p_maintenance_fee').value,
    pay_date: date,
    bill_ym: document.getElementById('p_bill_ym').value,
    memo: document.getElementById('p_memo').value,
  };
  if (!confirm((c.tenant || '(무기명)') + ' 계약으로 등록하시겠습니까?')) return;
  nwApi('payment', 'add', d).then(() => location.reload());
}

// 납부 수정: 월 행 클릭 → 그 달 납부기록 편집 모달
const nwUnmoney2 = v => Number(String(v).replace(/[^0-9]/g, '')) || 0;
function nwEditMonth(bym){
  const pays = NWP.payments.filter(p => p.bym === bym);
  if (!pays.length) return;
  document.getElementById('nwEditPayBody').innerHTML = pays.map(p =>
    '<div class="nwep-row">' +
      '<div class="nwep-grid">' +
        '<div><label>월세</label><input class="ep-rent money" value="' + won(p.rent) + '" oninput="nwFmtMoney(this)"></div>' +
        '<div><label>관리비</label><input class="ep-mnt money" value="' + won(p.mnt) + '" oninput="nwFmtMoney(this)"></div>' +
        '<div><label>납부일</label><input type="date" class="ep-date" value="' + p.date + '"></div>' +
        '<div><label>귀속월 (어느 달 것)</label><input type="month" class="ep-bym" value="' + p.bym + '"></div>' +
        '<div style="grid-column:1/3;"><label>메모</label><input class="ep-memo" value="' + esc(p.memo || '') + '"></div>' +
      '</div>' +
      '<div class="nwep-actions">' +
        '<button class="btn btn-danger btn-sm" onclick="nwDeletePayment(' + p.id + ')">삭제</button>' +
        '<button class="btn btn-primary btn-sm" onclick="nwSavePayment(' + p.id + ', this)">저장</button>' +
      '</div>' +
    '</div>'
  ).join('');
  nwOpenModal('nwEditPayModal');
}
function nwSavePayment(id, btn){
  const row = btn.closest('.nwep-row');
  nwApi('payment', 'update', {
    id: id,
    rent_fee: nwUnmoney2(row.querySelector('.ep-rent').value),
    maintenance_fee: nwUnmoney2(row.querySelector('.ep-mnt').value),
    pay_date: row.querySelector('.ep-date').value,
    bill_ym: row.querySelector('.ep-bym').value,
    memo: row.querySelector('.ep-memo').value,
  }).then(() => location.reload());
}
function nwDeletePayment(id){
  if (!confirm('이 납부 기록을 삭제하시겠습니까?')) return;
  nwApi('payment', 'delete', { id: id }).then(() => location.reload());
}
</script>

<div class="modal-overlay" id="nwEditPayModal">
  <div class="modal-box" style="width:460px;">
    <h3>납부 수정</h3>
    <div id="nwEditPayBody"></div>
    <div class="modal-actions"><button class="btn btn-outline" onclick="nwCloseModal('nwEditPayModal')">닫기</button></div>
  </div>
</div>
<?php
    nw_foot();
}

// ==========================================================
// 은행 거래내역 업로드 → 월세/관리비 일괄 정리
// ==========================================================
function nw_page_import(PDO $pdo): void {
    $nw = new Nw($pdo);
    $buildingId = (int)($_GET['building_id'] ?? 0);
    $b = $nw->getBuilding($buildingId);
    if (!$b) { nw_head('건물 없음'); echo "<p>건물을 찾을 수 없습니다.</p>"; nw_foot(); return; }

    nw_head('은행내역 업로드 — ' . $b['name']);
?>
<style>
.imp-drop{border:2px dashed #cbd5e0;border-radius:10px;padding:22px;text-align:center;background:#f8fafc;margin-bottom:16px;}
.imp-drop input[type=file]{margin:8px 0;}
.imp-hint{color:#718096;font-size:13px;margin-top:6px;}
.imp-summary{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:12px;font-size:14px;}
.imp-summary .chip{background:#edf2f7;border-radius:16px;padding:4px 12px;}
.imp-summary .chip b{color:#2b6cb0;}
.imp-scroll{overflow-x:auto;border-radius:8px;}
table.imp-table{width:100%;border-collapse:collapse;font-size:13px;white-space:nowrap;}
table.imp-table th,table.imp-table td{padding:7px 9px;border-bottom:1px solid #edf2f7;text-align:left;}
table.imp-table th{background:#f7fafc;color:#4a5568;font-weight:600;position:sticky;top:0;}
table.imp-table td.num{text-align:right;font-variant-numeric:tabular-nums;}
table.imp-table tr.grp-head td{background:#e2e8f0;color:#2d3748;font-weight:700;font-size:12px;padding:8px 10px;border-top:2px solid #cbd5e0;}
table.imp-table tr.row-none{background:#eef2f7;}
table.imp-table tr.row-low{background:#fff0f0;}
table.imp-table tr.row-mid{background:#fff8ee;}
table.imp-table tr.row-high{background:#ffffff;}
table.imp-table tr.duprow td{opacity:.72;}
table.imp-table select{max-width:170px;padding:4px 6px;border:1px solid #cbd5e0;border-radius:6px;}
table.imp-table input.imoney{width:88px;padding:4px 6px;border:1px solid #cbd5e0;border-radius:6px;text-align:right;}
.conf{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;}
.conf-high{background:#c6f6d5;color:#22543d;}
.conf-mid{background:#feebc8;color:#7b341e;}
.conf-low{background:#fed7d7;color:#742a2a;}
.conf-none{background:#e2e8f0;color:#4a5568;}
.tag-dup{background:#fbd38d;color:#7b341e;padding:2px 7px;border-radius:10px;font-size:11px;margin-left:4px;}
.mism{color:#c05621;font-size:11px;margin-top:3px;}
.recon{color:#c53030;font-size:11px;font-weight:600;margin-top:3px;}
table.imp-table input.imoney.bad{border-color:#e53e3e;background:#fff5f5;}
</style>

<div class="page-head">
  <div>
    <a class="back-link" href="/nw/index.php?mode=building&id=<?= $buildingId ?>">← <?= nw_h($b['name']) ?></a>
    <h1 style="margin-top:6px;">🏦 은행내역 업로드</h1>
    <div class="sub">은행에서 받은 거래내역 엑셀(.xls)을 올리면 입금자·금액으로 계약을 자동매칭해 월세·관리비를 일괄 등록합니다.</div>
  </div>
</div>

<div class="imp-drop">
  <input type="file" id="nwFile" accept=".xls,.xlsx,.csv">
  <div>
    <button class="btn btn-primary" onclick="nwImportParse()">분석하기</button>
  </div>
  <div class="imp-hint">우리은행 거래내역(.xls) 지원 · 출금/이자 자동 제외 · 이미 등록된 납부는 중복 표시</div>
</div>

<div id="nwReviewWrap" style="display:none;">
  <div class="imp-summary" id="nwSummary"></div>
  <div class="imp-scroll">
    <table class="imp-table">
      <thead>
        <tr>
          <th><input type="checkbox" id="nwChkAll" onclick="nwToggleAll(this)"></th>
          <th>거래일</th><th>입금자</th><th>입금액</th><th>계약(호실·세입자)</th>
          <th>월세</th><th>관리비</th><th>판정</th>
        </tr>
      </thead>
      <tbody id="nwReview"></tbody>
    </table>
  </div>
  <div style="margin-top:16px;text-align:right;">
    <button class="btn btn-primary" onclick="nwImportConfirm()">✔ 선택 항목 일괄등록</button>
  </div>
</div>

<script>
let NW_ROWS = [], NW_CAND = [];
const NW_BID = <?= $buildingId ?>;

function nwMoney(n){ return Number(n||0).toLocaleString('en-US'); }
function nwNum(v){ return Number(String(v).replace(/[^0-9]/g,'')) || 0; }

function nwImportParse(){
  const inp = document.getElementById('nwFile');
  if (!inp.files.length) { alert('파일을 선택하세요.'); return; }
  const fd = new FormData();
  fd.append('file', inp.files[0]);
  fetch('/nw/api.php?module=payment&action=importParse&building_id=' + NW_BID, { method:'POST', body: fd })
    .then(r => r.json())
    .then(j => {
      if (!j.ok) { alert('오류: ' + (j.msg || '분석 실패')); return; }
      NW_ROWS = j.rows || [];
      NW_CAND = j.candidates || [];
      if (!NW_ROWS.length) { alert('거래 행을 찾지 못했습니다. 다른 파일을 시도해 주세요.'); return; }
      nwRenderReview();
      document.getElementById('nwReviewWrap').style.display = 'block';
    })
    .catch(e => alert('업로드 실패: ' + e));
}

function nwCandOptions(sel){
  let h = '<option value="">— 미지정 —</option>';
  NW_CAND.forEach(c => {
    h += '<option value="' + c.contract_id + '"' + (String(c.contract_id) === String(sel) ? ' selected' : '') + '>' + c.label + '</option>';
  });
  return h;
}

function nwRenderReview(){
  const confLabel = {high:'확실', mid:'추정', low:'모호', none:'미매칭'};
  const order = {none:0, low:1, mid:2, high:3}; // 확인 필요한 것(미매칭·모호·추정)을 위로, 확실을 아래로
  const groupHead = {
    none:'❓ 미매칭 — 계약을 직접 지정하세요',
    low: '🔴 모호 — 확인 필요',
    mid: '🟠 추정 — 확인 권장',
    high:'🟢 확실',
  };

  // 출금·이자(skip)는 목록에서 제외. 원래 인덱스(i)는 보존.
  const list = [];
  NW_ROWS.forEach((r, i) => { if (!r.skip_reason && !r.dup) list.push({ i, r }); }); // 출금·이자·이미등록은 목록서 숨김
  // 안정정렬(모던 브라우저): 우선순위 오름차순, 동순위는 원래(날짜) 순서 유지
  list.sort((a, b) => order[a.r.confidence] - order[b.r.confidence]);

  // 그룹별 건수
  const grpCnt = {};
  list.forEach(({ r }) => { grpCnt[r.confidence] = (grpCnt[r.confidence] || 0) + 1; });

  let h = '', curGrp = null;
  list.forEach(({ i, r }) => {
    if (r.confidence !== curGrp) {
      curGrp = r.confidence;
      h += '<tr class="grp-head"><td colspan="8">' + groupHead[curGrp] + ' · ' + (grpCnt[curGrp] || 0) + '건</td></tr>';
    }
    const dupTag = r.dup ? '<span class="tag-dup">이미등록</span>' : '';
    const reason = r.match_reason ? ' <span style="color:#718096;font-size:11px;">' + r.match_reason + '</span>' : '';
    const billTag = (r.bill_ym && r.bill_ym !== r.date.slice(0, 7)) ? ' <span style="color:#b7791f;font-size:11px;">귀속 ' + r.bill_ym + '</span>' : '';
    h += '<tr class="row-' + r.confidence + (r.dup ? ' duprow' : '') + '">' +
      '<td><input type="checkbox" class="chk" data-i="' + i + '" ' + (r.include ? 'checked' : '') + ' onchange="nwUpdateSummary()"></td>' +
      '<td>' + r.date + '</td>' +
      '<td>' + r.counterparty + '</td>' +
      '<td class="num">' + nwMoney(r.deposit) + '</td>' +
      '<td><select data-i="' + i + '" onchange="nwRowRecalc(' + i + ')">' + nwCandOptions(r.contract_id) + '</select></td>' +
      '<td class="num"><input class="imoney" data-f="rent" data-i="' + i + '" value="' + nwMoney(r.rent_fee) + '" oninput="nwFmtMoney(this);nwRowReconcile(' + i + ');nwUpdateSummary()"></td>' +
      '<td class="num"><input class="imoney" data-f="mnt" data-i="' + i + '" value="' + nwMoney(r.maintenance_fee) + '" oninput="nwFmtMoney(this);nwRowReconcile(' + i + ');nwUpdateSummary()"></td>' +
      '<td><span class="conf conf-' + r.confidence + '">' + (confLabel[r.confidence] || r.confidence) + '</span>' + reason + billTag + dupTag +
        '<div class="mism" id="mism-' + i + '" style="display:none;"></div>' +
        '<div class="recon" id="recon-' + i + '" style="display:none;"></div></td>' +
      '</tr>';
  });
  document.getElementById('nwReview').innerHTML = h || '<tr><td colspan="8" style="text-align:center;color:#a0aec0;padding:24px;">새로 등록할 입금내역이 없습니다 (이미등록·자동제외 항목 제외)</td></tr>';
  // 계약 예상액 불일치 안내 초기 렌더
  list.forEach(({ i }) => nwRenderMism(i));
  nwUpdateSummary();
}

// 분할 규칙(서버와 동일): 관리비=계약 정액 고정, 월세=입금액−관리비.
//   전세(월세=0)·관리비명칭 입금은 전액을 관리비로.
function nwSplit(dep, c, cp){
  if (!c) return { pr: dep, pm: 0 };
  if ((cp && cp.indexOf('관리비') >= 0) || c.rent_fee === 0) return { pr: 0, pm: dep };
  const pm = Math.min(c.maintenance_fee, dep);
  return { pr: dep - pm, pm: pm };
}

// 계약 선택이 바뀌면 그 계약 기준으로 분할 재계산
function nwRowRecalc(i){
  const sel = document.querySelector('select[data-i="' + i + '"]');
  const c = NW_CAND.find(x => String(x.contract_id) === String(sel.value));
  const rentEl = document.querySelector('input[data-f="rent"][data-i="' + i + '"]');
  const mntEl  = document.querySelector('input[data-f="mnt"][data-i="' + i + '"]');
  if (c) {
    const s = nwSplit(NW_ROWS[i].deposit, c, NW_ROWS[i].counterparty);
    rentEl.value = nwMoney(s.pr); mntEl.value = nwMoney(s.pm);
  }
  const chk = document.querySelector('.chk[data-i="' + i + '"]');
  if (chk && sel.value) chk.checked = true;
  nwRenderMism(i);
  nwRowReconcile(i);
  nwUpdateSummary();
}

// 계약 예상 총액과 입금액이 다르면 안내(미납/초과/월세변동 포착)
function nwRenderMism(i){
  const box = document.getElementById('mism-' + i);
  if (!box) return;
  const sel = document.querySelector('select[data-i="' + i + '"]');
  const c = NW_CAND.find(x => String(x.contract_id) === String(sel.value));
  const dep = NW_ROWS[i].deposit;
  if (c) {
    const exp = c.rent_fee + c.maintenance_fee; // 전세는 관리비
    if (exp > 0 && exp !== dep) {
      const diff = dep - exp;
      box.innerHTML = '계약 ' + nwMoney(exp) + ' · 입금 ' + nwMoney(dep) + ' (' + (diff > 0 ? '+' : '') + nwMoney(diff) + ')';
      box.style.display = '';
      return;
    }
  }
  box.innerHTML = ''; box.style.display = 'none';
}

// 월세+관리비 합계가 입금액과 맞는지 실시간 검증(수동 편집 실수 방지)
function nwRowReconcile(i){
  const rentEl = document.querySelector('input[data-f="rent"][data-i="' + i + '"]');
  const mntEl  = document.querySelector('input[data-f="mnt"][data-i="' + i + '"]');
  const box = document.getElementById('recon-' + i);
  if (!rentEl || !mntEl || !box) return;
  const sum = nwNum(rentEl.value) + nwNum(mntEl.value);
  const dep = NW_ROWS[i].deposit;
  const bad = sum !== dep;
  rentEl.classList.toggle('bad', bad);
  mntEl.classList.toggle('bad', bad);
  if (bad) { box.innerHTML = '⚠ 합계 ' + nwMoney(sum) + ' ≠ 입금 ' + nwMoney(dep); box.style.display = ''; }
  else { box.innerHTML = ''; box.style.display = 'none'; }
}

function nwToggleAll(el){
  document.querySelectorAll('.chk').forEach(c => c.checked = el.checked);
  nwUpdateSummary();
}

function nwUpdateSummary(){
  let sel = 0, amt = 0, dup = 0, none = 0, skip = 0;
  NW_ROWS.forEach((r, i) => {
    if (r.skip_reason) { skip++; return; }
    if (r.dup) dup++;
    if (r.confidence === 'none') none++;
    const chk = document.querySelector('.chk[data-i="' + i + '"]');
    if (chk && chk.checked) {
      sel++;
      const rent = nwNum(document.querySelector('input[data-f="rent"][data-i="' + i + '"]').value);
      const mnt  = nwNum(document.querySelector('input[data-f="mnt"][data-i="' + i + '"]').value);
      amt += rent + mnt;
    }
  });
  document.getElementById('nwSummary').innerHTML =
    '<span class="chip">선택 <b>' + sel + '</b>건</span>' +
    '<span class="chip">합계 <b>' + nwMoney(amt) + '</b>원</span>' +
    '<span class="chip">미매칭 ' + none + '건</span>' +
    '<span class="chip">이미등록 ' + dup + '건</span>' +
    '<span class="chip">자동제외 ' + skip + '건</span>';
}

function nwImportConfirm(){
  const items = [];
  document.querySelectorAll('.chk:checked').forEach(chk => {
    const i = chk.dataset.i;
    const cid = document.querySelector('select[data-i="' + i + '"]').value;
    if (!cid) return;
    items.push({
      contract_id: cid,
      pay_date: NW_ROWS[i].date,
      bill_ym: NW_ROWS[i].bill_ym || NW_ROWS[i].date.slice(0, 7),
      rent_fee: nwNum(document.querySelector('input[data-f="rent"][data-i="' + i + '"]').value),
      maintenance_fee: nwNum(document.querySelector('input[data-f="mnt"][data-i="' + i + '"]').value),
      memo: NW_ROWS[i].counterparty,
    });
  });
  if (!items.length) { alert('선택된(계약이 지정된) 항목이 없습니다.'); return; }
  if (!confirm(items.length + '건을 납부내역으로 등록하시겠습니까?')) return;
  nwApi('payment', 'bulkImport', { items: JSON.stringify(items) }).then(j => {
    alert('등록 ' + j.inserted + '건 · 중복 스킵 ' + j.skipped + '건');
    nwGoto('/nw/index.php?mode=building&id=' + NW_BID);
  }).catch(() => {});
}
</script>
<?php
    nw_foot();
}
?>
