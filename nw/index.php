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
<title><?= nw_h($title) ?> — 이코노미스트</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php nw_css(); nav_css(); ?>
</head>
<body>
<?php render_nav('nw'); ?>
<div class="nw-body">
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
.form-field input[type=text], .form-field input[type=date], .form-field input[type=number], .form-field textarea {
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
.pay-form { display: flex; gap: 8px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 16px; }
.pay-form .form-field { min-width: 120px; }
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
    $id = (int)($_GET['id'] ?? 0);
    $b  = $nw->getBuilding($id);
    if (!$b) { nw_head('건물 없음'); echo "<p>건물을 찾을 수 없습니다.</p>"; nw_foot(); return; }

    $units          = $nw->listUnits($id);
    $contractsByRoom = $nw->listActiveContractsByBuilding($id);
    $lastPayments    = $nw->listLastPayments(array_column($contractsByRoom, 'id'));

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
  <button class="btn btn-primary" onclick="nwOpenModal('nwAddUnitModal')">+ 호실 추가</button>
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

<div class="room-scroll">
<table class="room-table">
  <tr>
    <th>호실</th><th>평수</th><th>근저당</th><th>세입자</th><th>계약형태</th>
    <th>보증금</th><th>월세</th><th>관리비</th><th>납부상태</th><th>만기</th>
    <th>공인중개사</th><th>특약</th><th>관리</th>
  </tr>
  <?php foreach ($units as $u):
        $c = $contractsByRoom[(int)$u['id']] ?? null;
  ?>
  <tr>
    <td><b><?= nw_h($u['room_no']) ?></b>호</td>
    <td><?= nw_pyeong($u['room_size']) ?>평<br><span class="mono" style="font-size:11px;color:#95a5a6;"><?= nw_money($u['room_size']) ?>㎡</span></td>
    <td class="mono"><?= $u['bank_loan'] ? nw_money($u['bank_loan'] / 1000000) . '백만' : '-' ?></td>
    <?php if (!$c): ?>
    <td colspan="10"><a class="tenant-link" href="/nw/index.php?mode=contract&room_id=<?= (int)$u['id'] ?>">공실 — 임차인 등록</a></td>
    <?php else:
        $exp = $nw->expiryStatus($c);
        $pay = $nw->paymentStatus($c, $lastPayments[(int)$c['id']] ?? null);
        $renewalBadge = $c['renewal_type'] === 'extended' ? '<span class="badge badge-extended">연장</span>' : '';
    ?>
    <td class="col-tenant">
      <a class="tenant-link" href="/nw/index.php?mode=contract&room_id=<?= (int)$u['id'] ?>&id=<?= (int)$c['id'] ?>"><?= nw_h($c['tenant_name']) ?></a>
      <?php if ($renewalBadge): ?><div class="renewal-line"><?= $renewalBadge ?></div><?php endif; ?>
    </td>
    <td><?= $c['deposit_registered'] ? '<span style="color:#e74c3c;font-weight:700;">🔒</span> ' : '' ?><span class="ctype ctype-<?= nw_h($c['contract_type']) ?>"><?= NW_CONTRACT_TYPE_LABEL[$c['contract_type']] ?? '' ?></span></td>
    <td class="mono"><?= nw_money($c['deposit'] / 1000000) ?>백만</td>
    <td class="mono"><?= nw_money($c['rent_fee'] / 10000) ?>만</td>
    <td class="mono"><?= nw_money($c['maintenance_fee'] / 10000) ?>만</td>
    <td><a href="/nw/index.php?mode=payment&contract_id=<?= (int)$c['id'] ?>"><span class="badge badge-<?= nw_h($pay['level']) ?>"><?= nw_h($pay['label']) ?></span></a></td>
    <td><span class="badge badge-<?= nw_h($exp['level']) ?>"><?= nw_h($exp['label']) ?></span><br><span style="font-size:11px;color:#95a5a6;"><?= nw_h($c['contract_end_date']) ?></span></td>
    <td style="text-align:center;"><?php $office = trim((string)($c['agent_office'] ?? '')); echo $office !== '' ? $agentChip($agentNum[$office], $office) : '<span style="color:#dfe4ea;">-</span>'; ?></td>
    <td style="text-align:center;"><?= $c['special_terms'] ? '📝' : '' ?></td>
    <td>
      <div class="rowmenu">
        <button class="rowmenu-btn" onclick="nwToggleMenu(event, this)" aria-label="관리 메뉴">⋮</button>
        <div class="rowmenu-pop">
          <button onclick="nwGoto('/nw/index.php?mode=contract&room_id=<?= (int)$u['id'] ?>&renew_from=<?= (int)$c['id'] ?>')">📄 계약 연장</button>
          <?php if ($c['renewal_type'] !== 'implied'): ?>
          <button onclick="nwConfirmAction('묵시적갱신 처리하시겠습니까?','contract','impliedRenewal',{id:<?= (int)$c['id'] ?>},'/nw/index.php?mode=building&id=<?= $id ?>')">🔁 묵시적갱신</button>
          <?php endif; ?>
          <button onclick="nwConfirmAction('계약을 만료 처리하시겠습니까?','contract','end',{id:<?= (int)$c['id'] ?>,reason:'expired'},'/nw/index.php?mode=building&id=<?= $id ?>')">⏹ 계약 만료</button>
          <button class="danger" onclick="nwConfirmAction('중도해지 처리하시겠습니까?','contract','end',{id:<?= (int)$c['id'] ?>,reason:'terminated_early'},'/nw/index.php?mode=building&id=<?= $id ?>')">✕ 중도해지</button>
        </div>
      </div>
    </td>
    <?php endif; ?>
  </tr>
  <?php endforeach; ?>
  <?php if (!$units): ?>
  <tr><td colspan="13" style="text-align:center;color:#bdc3c7;padding:24px;">등록된 호실이 없습니다</td></tr>
  <?php endif; ?>
</table>
</div>

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
      <div class="form-field"><label>~ 만료일</label><input type="date" id="f_contract_end_date" value="<?= $get('contract_end_date') ?>"></div>

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
    $contractId = (int)($_GET['contract_id'] ?? 0);
    $c = $nw->getContract($contractId);
    if (!$c) { nw_head('계약 없음'); echo "<p>계약을 찾을 수 없습니다.</p>"; nw_foot(); return; }

    $unit     = $nw->getUnit((int)$c['room_id']);
    $building = $nw->getBuilding((int)$unit['building_id']);
    $payments = $nw->listPayments($contractId);
    $pay      = $nw->paymentStatus($c);

    nw_head('월세 납부 현황');
?>
<div class="page-head">
  <div>
    <a class="back-link" href="/nw/index.php?mode=building&id=<?= (int)$unit['building_id'] ?>">← 호실 목록</a>
    <h1 style="margin-top:6px;"><?= nw_h($building['name']) ?> (<?= nw_h($unit['room_no']) ?>호) — <?= nw_h($c['tenant_name']) ?></h1>
    <div class="sub"><?= nw_h($c['tenant_phone']) ?> · 계약 <?= nw_h($c['contract_date']) ?> ~ <?= nw_h($c['contract_end_date']) ?></div>
  </div>
</div>

<div class="pay-summary">
  <div class="card"><div class="lbl">월세</div><div class="val mono"><?= nw_money($c['rent_fee']) ?>원</div></div>
  <div class="card"><div class="lbl">관리비</div><div class="val mono"><?= nw_money($c['maintenance_fee']) ?>원</div></div>
  <div class="card"><div class="lbl">이번달 상태</div><div class="val"><span class="badge badge-<?= nw_h($pay['level']) ?>"><?= nw_h($pay['label']) ?></span></div></div>
</div>

<div class="card" style="margin-bottom:16px;">
  <div class="pay-form">
    <div class="form-field"><label>월세</label><div class="input-money"><input type="text" inputmode="numeric" class="money" id="p_rent_fee" value="<?= nw_money($c['rent_fee']) ?>"></div></div>
    <div class="form-field"><label>관리비</label><div class="input-money"><input type="text" inputmode="numeric" class="money" id="p_maintenance_fee" value="<?= nw_money($c['maintenance_fee']) ?>"></div></div>
    <div class="form-field"><label>납부일</label><input type="date" id="p_pay_date" value="<?= date('Y-m-d') ?>"></div>
    <div class="form-field" style="flex:1;min-width:150px;"><label>메모</label><input type="text" id="p_memo"></div>
    <button class="btn btn-primary" onclick="nwAddPayment()">등록</button>
  </div>
</div>

<table class="hist-table">
  <tr><th>납부일</th><th>월세</th><th>관리비</th><th>메모</th></tr>
  <?php if (!$payments): ?>
  <tr><td colspan="4" style="color:#bdc3c7;padding:20px;">납부 이력이 없습니다</td></tr>
  <?php else: foreach ($payments as $p): ?>
  <tr>
    <td><?= nw_h($p['pay_date']) ?></td>
    <td class="mono"><?= nw_money($p['rent_fee']) ?>원</td>
    <td class="mono"><?= nw_money($p['maintenance_fee']) ?>원</td>
    <td><?= nw_h($p['memo']) ?></td>
  </tr>
  <?php endforeach; endif; ?>
</table>

<script>
function nwAddPayment() {
  const d = {
    contract_id: <?= $contractId ?>,
    rent_fee: document.getElementById('p_rent_fee').value,
    maintenance_fee: document.getElementById('p_maintenance_fee').value,
    pay_date: document.getElementById('p_pay_date').value,
    memo: document.getElementById('p_memo').value,
  };
  if (!confirm('등록하시겠습니까?')) return;
  nwApi('payment', 'add', d).then(() => location.reload());
}
</script>
<?php
    nw_foot();
}
?>
