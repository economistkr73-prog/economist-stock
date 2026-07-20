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
    'utility'   => 'nw_page_utility',
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
.occ-vacant { color: #e67e22; font-weight: 700; margin-left: 6px; }
.occ-full { color: #27ae60; font-weight: 700; margin-left: 6px; }
.b-lastpay { margin-top: 10px; padding-top: 10px; border-top: 1px solid #eef1f4; font-size: 12px; color: #7f8c8d; display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.b-elapsed { font-weight: 700; color: #34495e; white-space: nowrap; }
.b-elapsed.warn { color: #e67e22; }

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
.sb-item { background: #fff; border-radius: 10px; box-shadow: 0 1px 4px rgba(0,0,0,.08); padding: 0; overflow: hidden; display: flex; flex-wrap: nowrap; align-items: stretch; font-size: 13px; white-space: nowrap; flex-shrink: 0; }
/* 유형(전세…) = 좌측 전체를 채우는 색상 블록 + 오른쪽 2줄(건수 / 상세) */
.sb-type { font-weight: 800; font-size: 15px; white-space: nowrap; display: flex; align-items: center; padding: 0 15px; }
.sb-right { display: flex; flex-direction: column; justify-content: center; gap: 2px; padding: 8px 14px; }
.sb-cnt { font-weight: 800; color: #2c3e50; font-size: 14px; }
.sb-detail { color: #7f8c8d; white-space: nowrap; font-size: 12px; }
/* 칩 전체 배경을 톤으로 채우고, 좌측 라벨은 진한 솔리드 */
.sb-jeonse { background: #eaf2fb; }
.sb-semi { background: #fef5e7; }
.sb-monthly { background: #eafaf1; }
.sb-jeonse .sb-type { background: #2471a3; color: #fff; }
.sb-semi .sb-type { background: #b9770e; color: #fff; }
.sb-monthly .sb-type { background: #1e8449; color: #fff; }
.ctype { display: inline-block; padding: 3px 10px; border-radius: 6px; font-size: 12px; font-weight: 800; }
.ctype-jeonse { background: #eaf2fb; color: #2471a3; }
.ctype-semi_monthly { background: #fef5e7; color: #b9770e; }
.ctype-monthly { background: #eafaf1; color: #1e8449; }
.sb-total { background: #2c3e50; }
.sb-total .sb-type { background: #f1c40f; color: #2c3e50; }
.sb-total .sb-detail { color: #ecf0f1; font-weight: 600; }
.sb-total .sb-right { border-left-color: rgba(255,255,255,.28); }
.sb-tot-fields { display: flex; flex-direction: row; gap: 16px; align-items: center; }
.sb-tot-fields > div { display: flex; flex-direction: column; line-height: 1.25; text-align: right; }
.sb-tot-fields .tf-lbl { font-size: 10px; color: #cbd5e0; font-weight: 600; }
.sb-tot-fields b { color: #fff; font-size: 14px; }
.agent-chip { display: inline-flex; align-items: center; justify-content: center; width: 23px; height: 23px; border-radius: 50%; font-size: 12px; font-weight: 800; }
.agent-legend { margin-top: 14px; padding: 13px 18px; background: #fff; border-radius: 12px; box-shadow: 0 1px 4px rgba(0,0,0,.08); display: flex; align-items: center; gap: 8px 18px; flex-wrap: wrap; font-size: 13px; }
.agent-legend .al-title { font-weight: 800; color: #34495e; margin-right: 4px; }
.al-item { display: inline-flex; align-items: center; gap: 7px; color: #2c3e50; font-weight: 600; }
.al-item b { color: #7f8c8d; font-weight: 700; }
.vacant-tag { color: #bdc3c7; font-size: 12px; }
.tenant-link { color: #2c3e50; text-decoration: none; font-weight: 700; }
.tenant-link:hover { color: #3498db; }
.reg-chip { display: inline-flex; align-items: center; gap: 3px; background: #2b6cb0; color: #fff; padding: 4px 11px; border-radius: 14px; font-size: 12px; font-weight: 700; text-decoration: none; white-space: nowrap; }
.reg-chip:hover { background: #245a94; }
.room-table tr.room-vacant td { background: #fff4e5; }
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
.form-row4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px 18px; }
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
.scan-card { max-width: 760px; margin: 0 auto 16px; padding: 16px 18px; border: 1px dashed #bcd7f0; background: #f5f9fe; }
.scan-row { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; }
.scan-title { font-size: 15px; font-weight: 800; color: #2b6cb0; }
.scan-sub { font-size: 12px; color: #5c6b7a; margin-top: 3px; }
.scan-btn { cursor: pointer; white-space: nowrap; }
.scan-status { margin-top: 12px; font-size: 13px; padding: 9px 12px; border-radius: 6px; }
.scan-status.loading { background: #fff7e6; color: #8a6d3b; }
.scan-status.ok { background: #eafaf1; color: #1e824c; }
.scan-status.err { background: #fdecea; color: #c0392b; }
.doc-chip { vertical-align: middle; margin-left: 8px; padding: 3px 12px; font-size: 13px; font-weight: 700; color: #2b6cb0; background: #eaf2fb; border: 1px solid #bcd7f0; border-radius: 14px; cursor: pointer; white-space: nowrap; }
.doc-chip:hover { background: #d7e8f8; }
.doc-overlay { padding: 20px; }
.doc-box { position: relative; background: #fff; border-radius: 12px; max-width: 92vw; max-height: 92vh; overflow: auto; padding: 14px; }
.doc-x { position: absolute; top: 8px; right: 8px; width: 34px; height: 34px; border: none; border-radius: 50%; background: rgba(0,0,0,.6); color: #fff; font-size: 16px; cursor: pointer; z-index: 1; }
.doc-x:hover { background: rgba(0,0,0,.8); }
.doc-imgs { display: flex; flex-direction: column; gap: 12px; }
.doc-imgs img { max-width: 100%; max-height: 84vh; border-radius: 6px; display: block; margin: 0 auto; }
.scan-thumbs { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 12px; }
.scan-thumb { display: block; width: 84px; height: 84px; border-radius: 8px; overflow: hidden; border: 1px solid #d5dee7; background: #fff; }
.scan-thumb img { width: 100%; height: 100%; object-fit: cover; }
.scan-thumb-wrap { position: relative; display: inline-block; }
.scan-thumb-del { position: absolute; top: -7px; right: -7px; width: 20px; height: 20px; border-radius: 50%; border: none; background: #e53e3e; color: #fff; font-size: 11px; line-height: 20px; text-align: center; cursor: pointer; padding: 0; box-shadow: 0 1px 3px rgba(0,0,0,.3); }
.scan-thumb-del:hover { background: #c53030; }
.nwend-note { font-size: 12px; margin: 10px 0 0; padding: 8px 10px; border-radius: 6px; line-height: 1.4; color: #7f8c8d; background: #f4f6f8; }
.nwend-note.early { background: #fdecea; color: #c0392b; }
.nwend-note.expired { background: #eafaf1; color: #1e824c; }

.pay-stat { display: flex; flex-wrap: wrap; gap: 10px 22px; align-items: baseline; margin-bottom: 16px; padding: 12px 16px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.05); font-size: 14px; }
.pay-stat span { color: #4a5568; white-space: nowrap; }
.pay-stat i { font-style: normal; color: #94a3b8; font-size: 12px; margin-right: 5px; }
.pay-stat b { font-weight: 800; }
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
  .form-row4 { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 700px) {
  .summary-cards { grid-template-columns: repeat(2, 1fr); }
  .alert-grid { grid-template-columns: 1fr; }
  .form-grid { grid-template-columns: 1fr; }
  .form-row4 { grid-template-columns: 1fr; }
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
    $lastPays  = $nw->lastPaymentDatesByBuilding(); // [building_id => 'YYYY-MM-DD']

    nw_head('대시보드');
?>
<div class="page-head">
  <div>
    <h1>🏢 대시보드</h1>
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
        $vacant = max(0, $total - $occ);
        $lastPay = $lastPays[(int)$b['id']] ?? '';
        $elapsed = $lastPay ? (int)floor((strtotime(date('Y-m-d')) - strtotime($lastPay)) / 86400) : null;
  ?>
  <a class="card b-card" href="/nw/index.php?mode=building&id=<?= (int)$b['id'] ?>">
    <div class="b-name"><?= nw_h($b['name']) ?></div>
    <div class="b-addr"><?= nw_h($b['address']) ?></div>
    <div class="occ-bar"><div class="occ-bar-fill" style="width:<?= $pct ?>%;"></div></div>
    <div class="occ-label"><span><?= $occ ?> / <?= $total ?> 입주<?php if ($vacant > 0): ?><span class="occ-vacant">· 공실 <?= $vacant ?></span><?php else: ?><span class="occ-full">· 만실</span><?php endif; ?></span><span><?= $pct ?>%</span></div>
    <div class="b-lastpay">
      <?php if ($lastPay): ?>
        <span>월세 최종 업데이트 <b><?= nw_h($lastPay) ?></b></span>
        <span class="b-elapsed<?= $elapsed >= 35 ? ' warn' : '' ?>"><?= $elapsed === 0 ? '오늘' : $elapsed . '일 경과' ?></span>
      <?php else: ?>
        <span style="color:#bdc3c7;">납부 이력 없음</span>
      <?php endif; ?>
    </div>
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
        // 재실/월세 시작 = 입주일(잔금일) 우선, 없으면 계약일 (계약일과 실입주일이 다른 경우 대응)
        $s = ($cc['balance_date'] ?? '') ?: ($cc['contract_date'] ?: ($cc['first_contract_date'] ?? ''));
        $sy = 0; $sm = 0; if (preg_match('/^(\d{4})-(\d{2})/', (string)$s, $mm)) { $sy = (int)$mm[1]; $sm = (int)$mm[2]; }
        $occEnd = ($cc['move_out_date'] ?? '') ?: ($cc['contract_end_date'] ?? ''); // 재실 종료 = 실제 퇴거일 우선(없으면 계약만료일)
        $ey = 0; $em = 0; if (preg_match('/^(\d{4})-(\d{2})/', (string)$occEnd, $mm)) { $ey = (int)$mm[1]; $em = (int)$mm[2]; }
        $psS = $s ? substr((string)$s, 2, 2) . '.' . substr((string)$s, 5, 2) : '';
        $psE = $occEnd ? substr((string)$occEnd, 2, 2) . '.' . substr((string)$occEnd, 5, 2) : '';
        $jsRooms[$uid][] = [
            'id'      => (int)$cc['id'],
            'monthly' => (int)$cc['rent_fee'] + (int)$cc['maintenance_fee'] + (int)($cc['parking_fee'] ?? 0),
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
            'park'    => (int)($cc['parking_fee'] ?? 0),
            'dr'      => (int)$cc['deposit_registered'],
            'renewal' => (string)$cc['renewal_type'],
            'end'     => substr((string)($cc['contract_end_date'] ?? ''), 0, 10),
            'sd'      => substr((string)$s, 0, 10), // 입주일(잔금→계약→최초) — 퇴거일 하한
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
    $totalPark = 0;
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
        $totalPark    += (int)($c['parking_fee'] ?? 0);
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
    <button class="btn btn-outline" onclick="nwGoto('/nw/index.php?mode=utility&building_id=<?= $id ?>')">💡 공과금 관리</button>
    <button class="btn btn-outline" onclick="nwGoto('/nw/index.php?mode=import&building_id=<?= $id ?>')">🏦 은행내역 업로드</button>
    <a class="btn btn-outline" href="/nw/report.php?building_id=<?= $id ?>">📊 엑셀 보고서</a>
    <button class="btn btn-primary" onclick="nwOpenModal('nwAddUnitModal')">+ 호실 추가</button>
  </div>
</div>

<div class="stat-bar">
  <div class="sb-item sb-jeonse">
    <span class="sb-type">전세</span>
    <div class="sb-right">
      <span class="sb-cnt"><?= $stats['jeonse']['cnt'] ?>건</span>
      <span class="sb-detail">보증금 <?= nw_money($stats['jeonse']['deposit'] / 1000000) ?>백만</span>
    </div>
  </div>
  <div class="sb-item sb-semi">
    <span class="sb-type">반전세</span>
    <div class="sb-right">
      <span class="sb-cnt"><?= $stats['semi_monthly']['cnt'] ?>건</span>
      <span class="sb-detail">보증금 <?= nw_money($stats['semi_monthly']['deposit'] / 1000000) ?>백만 · 월세 <?= nw_money($stats['semi_monthly']['rent'] / 10000) ?>만</span>
    </div>
  </div>
  <div class="sb-item sb-monthly">
    <span class="sb-type">월세</span>
    <div class="sb-right">
      <span class="sb-cnt"><?= $stats['monthly']['cnt'] ?>건</span>
      <span class="sb-detail">보증금 <?= nw_money($stats['monthly']['deposit'] / 1000000) ?>백만 · 월세 <?= nw_money($stats['monthly']['rent'] / 10000) ?>만</span>
    </div>
  </div>
  <div class="sb-item sb-total">
    <span class="sb-type">합계</span>
    <div class="sb-right sb-tot-fields">
      <div><span class="tf-lbl">보증금</span><b><?= number_format($grandDeposit / 100000000, 1) ?>억</b></div>
      <div><span class="tf-lbl">월세</span><b><?= nw_money($grandRent / 10000) ?>만</b></div>
      <div><span class="tf-lbl">관리비</span><b><?= nw_money($totalMaint / 10000) ?>만</b></div>
      <div><span class="tf-lbl">주차비</span><b><?= nw_money($totalPark / 10000) ?>만</b></div>
    </div>
  </div>
</div>

<style>
.yb-bar{display:inline-flex;align-items:stretch;min-height:48px;margin-bottom:14px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08);}
.yb-bar .yb-label{display:flex;align-items:center;font-size:15px;font-weight:800;color:#fff;background:#2d3748;padding:0 18px;}
.yb-chips{display:flex;flex-wrap:wrap;align-items:stretch;}
.ybchip{display:flex;align-items:center;padding:0 15px;border:none;background:transparent;cursor:pointer;font-size:14px;color:#4a5568;font-weight:600;}
.ybchip:hover{background:#edf1f5;}
.ybchip.on{background:#2b6cb0;color:#fff;}
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

<div class="yb-bar"><span class="yb-label">연도</span><div id="nwbYearBar" class="yb-chips"></div></div>

<div class="room-scroll">
<table class="room-table">
  <tr>
    <th>호실</th><th>평수</th><th>근저당</th><th>세입자</th><th>계약형태</th>
    <th>보증금</th><th>월세</th><th>관리비</th><th>주차비</th><th>납부상태<br><span style="font-size:10px;font-weight:400;color:#95a5a6;">(미납)</span></th><th>만기</th>
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
    <td class="mono" id="park-<?= (int)$u['id'] ?>"></td>
    <td><a class="paycell" id="paystat-<?= (int)$u['id'] ?>" href="/nw/index.php?mode=payment&room_id=<?= (int)$u['id'] ?>">–</a></td>
    <td id="expiry-<?= (int)$u['id'] ?>"></td>
    <td style="text-align:center;"><?php $office = $c ? trim((string)($c['agent_office'] ?? '')) : ''; echo $office !== '' ? $agentChip($agentNum[$office], $office) : '<span style="color:#dfe4ea;">-</span>'; ?></td>
    <td style="text-align:center;"><?= ($c && $c['special_terms']) ? '📝' : '' ?></td>
    <td>
      <?php if ($c): ?>
      <div class="rowmenu">
        <button class="rowmenu-btn" onclick="nwToggleMenu(event, this)" aria-label="관리 메뉴">⋮</button>
        <div class="rowmenu-pop">
          <button onclick="nwGoto('/nw/index.php?mode=contract&room_id=<?= (int)$u['id'] ?>&renew_from=<?= (int)$c['id'] ?>')">📄 연장(신규계약)</button>
          <button class="danger" onclick="nwEndContract(<?= (int)$c['id'] ?>,'/nw/index.php?mode=building&id=<?= $id ?>')">🚪 계약 만료</button>
        </div>
      </div>
      <?php else: ?>
      <a class="reg-chip" href="/nw/index.php?mode=contract&room_id=<?= (int)$u['id'] ?>">✏️ 등록</a>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$units): ?>
  <tr><td colspan="14" style="text-align:center;color:#bdc3c7;padding:24px;">등록된 호실이 없습니다</td></tr>
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
// 납부상태: ★대표(그 해 현재) 계약 기준 — 담당인 달의 월세율(이사월·현재/미래월 제외) vs 그 계약 납부합
function roomCalc(cs, sel, target){
  if (!target) return { expected: 0, paid: 0 };
  const roomStartYm = roomStartOf(cs);
  let expected = 0, paid = 0;
  const addYear = (y) => {
    for (let m = 1; m <= 12; m++) {
      const cur = ymI(y, m);
      if (cur >= nowYm) continue;
      const o = coverExp(cs, cur);
      // 대상(대표) 계약이 담당인 달만 · 신규 입주월(월세 없음) 제외
      if (o === target && !(o.renewal === 'initial' && cur === o.startYm)) expected += o.monthly;
    }
  };
  if (sel === 'all') {
    for (let y = Math.floor(roomStartYm / 12); y <= NWB.curY; y++) addYear(y);
    for (const k in target.paid) paid += target.paid[k];
  } else {
    const y = +sel; addYear(y);
    if (target.paid[y]) paid += target.paid[y];
  }
  return { expected, paid };
}
const escB = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
// 해당 연도에 이 호실에 실제 거주(담당)했던 계약들 — 표시용(이사월/현재월 제외 안 함)
// 그 해 담당계약들(중복제거) + 공실 여부.
// ★공실 판정: '첫 입주 전' 달(leading)은 공실로 치지 않음(그 해 세입자가 있으면 미표시).
//   세입자가 있다가 나간 뒤의 공백(trailing=실제 퇴거) 또는 그 해 전체가 공실일 때만 vacant.
function ownersInYear(cs, y){
  const owners = []; let seenOwner = false, trailVacant = false;
  for (let m = 1; m <= 12; m++) {
    const o = coverExp(cs, ymI(y, m));
    if (o) { seenOwner = true; if (owners.indexOf(o) < 0) owners.push(o); }
    else if (seenOwner) trailVacant = true; // 세입자 있은 뒤의 공백 = 실제 공실
    // else: 첫 입주 전(leading) 공백 → 무시
  }
  const vacant = trailVacant || owners.length === 0; // 전 기간 공실이면 vacant
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
  if (d < 0) badge = '<span class="badge badge-implied">묵시적 갱신중</span>';
  else if (d <= 7) badge = '<span class="badge badge-danger">D-' + d + '</span>';
  else if (d <= 30) badge = '<span class="badge badge-warn">D-' + d + '</span>';
  else badge = '<span class="badge badge-ok">D-' + d + '</span>';
  return badge + '<br><span style="font-size:11px;color:#95a5a6;">' + o.end + '</span>';
}
// 계약 만료/중도해지: 실제 퇴거일을 캘린더로 선택받아 종료 처리
let NW_END = { id: 0, reloadUrl: '', end: '', start: '' };
function nwEndContract(id, reloadUrl){
  let end = '', tenant = '', start = '';
  for (const uid in NWB.rooms) {
    const c = NWB.rooms[uid].find(x => x.id === id);
    if (c) { end = c.end || ''; tenant = c.tenant || ''; start = c.sd || ''; break; }
  }
  NW_END = { id: id, reloadUrl: reloadUrl, end: end, start: start };
  // ★종료 대상 명시: 활성계약(이름·입주~만료)을 분명히 보여 오종료 방지
  document.getElementById('nwEnd_tenant').textContent = tenant || '(무기명)';
  document.getElementById('nwEnd_expiry').textContent = (start ? '입주 ' + start + ' · ' : '') + (end ? '만료 ' + end : '만료일 미등록');
  const inp = document.getElementById('nwEnd_date');
  inp.value = NWB.today;
  inp.min = start || '';   // 입주일 이전은 선택 불가
  if (end) inp.max = '';   // 상한 없음(만료 후 퇴거도 허용)
  nwEndSync();
  nwOpenModal('nwEndModal');
}
function nwEndSync(){
  const d = document.getElementById('nwEnd_date').value;
  const msg = document.getElementById('nwEnd_msg');
  if (!d) { msg.className = 'nwend-note'; msg.textContent = '퇴거일을 선택하세요'; return; }
  if (NW_END.start && d < NW_END.start) {
    msg.className = 'nwend-note early';
    msg.innerHTML = '⚠️ 입주일(' + NW_END.start + ') 이전은 선택할 수 없습니다 — 종료 대상 계약을 확인하세요';
    return;
  }
  if (NW_END.end && d < NW_END.end) {
    msg.className = 'nwend-note early';
    msg.innerHTML = '⚠️ 만료일 이전 퇴거 → <b>중도해지</b>로 기록됩니다';
  } else {
    msg.className = 'nwend-note expired';
    msg.innerHTML = '만료일 당일/이후 → <b>계약 만료</b>로 기록됩니다';
  }
}
function nwEndSubmit(){
  const date = document.getElementById('nwEnd_date').value;
  if (!date) { alert('퇴거일을 선택하세요'); return; }
  if (NW_END.start && date < NW_END.start) { alert('퇴거일이 입주일(' + NW_END.start + ')보다 이전일 수 없습니다.\n종료하려는 계약이 맞는지 확인하세요.'); return; }
  nwApi('contract', 'end', { id: NW_END.id, move_out_date: date }).then(() => { window.location.href = NW_END.reloadUrl; }).catch(() => {});
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
    { const _c = document.getElementById('tenant-' + uid), _r = _c && _c.closest('tr'); if (_r) _r.classList.toggle('room-vacant', vacant); } // 공실 행 반전

    // 계약형태/보증금/월세/관리비/만기: 그 해 대표(primary) 계약 기준
    setCell('ctype-' + uid, primary ? ((primary.dr ? '<span style="color:#e74c3c;font-weight:700;">🔒</span> ' : '') + '<span class="ctype ctype-' + primary.type + '">' + (NW_TYPE_LABEL[primary.type] || '') + '</span>') : NW_DASH);
    setCell('deposit-' + uid, primary ? (wonB(Math.round(primary.deposit / 1000000)) + '백만') : NW_DASH);
    setCell('rent-' + uid, primary ? (wonB(Math.round(primary.rent / 10000)) + '만') : NW_DASH);
    setCell('mnt-' + uid, primary ? (wonB(Math.round(primary.mnt / 10000)) + '만') : NW_DASH);
    setCell('park-' + uid, (primary && primary.park) ? (wonB(Math.round(primary.park / 10000)) + '만') : NW_DASH);
    setCell('expiry-' + uid, primary ? expiryHtml(primary) : NW_DASH);

    // 납부상태(미납)
    const cell = document.getElementById('paystat-' + uid);
    if (cell) {
      const { expected, paid } = roomCalc(cs, sel, primary);
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

<!-- 계약 종료: 실제 퇴거일 캘린더 선택 -->
<div class="modal-overlay" id="nwEndModal">
  <div class="modal-box">
    <h3>🚪 계약 종료</h3>
    <p style="color:#5c6b7a;font-size:13px;margin:0 0 14px;"><b id="nwEnd_tenant"></b> · <span id="nwEnd_expiry" style="color:#8493a2;"></span></p>
    <div class="form-field">
      <label>실제 퇴거일</label>
      <input type="date" id="nwEnd_date" onchange="nwEndSync()" oninput="nwEndSync()" style="width:100%;">
    </div>
    <p id="nwEnd_msg" class="nwend-note"></p>
    <div class="modal-actions">
      <button class="btn btn-outline" onclick="nwCloseModal('nwEndModal')">취소</button>
      <button class="btn btn-primary" onclick="nwEndSubmit()">종료 처리</button>
    </div>
  </div>
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
    $nw->ensureTable(); // nw_contract_image 존재 보장(멱등)
    $images = $editId ? $nw->listContractImages($editId) : []; // 이 계약에 첨부된 계약서 사진

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
<div class="page-head" style="max-width:760px;margin:0 auto 18px;">
  <div>
    <a class="back-link" href="/nw/index.php?mode=building&id=<?= (int)$unit['building_id'] ?>">← 호실 목록</a>
    <h1 style="margin-top:6px;"><?= nw_h($building['name']) ?> (<?= nw_h($unit['room_no']) ?>호) <?= $renewFrom ? '계약 연장' : ($editId ? '계약 수정' : '임차인 정보 등록') ?></h1>
  </div>
</div>

<?php if ($renewFrom): ?>
<div style="max-width:760px;margin:0 auto 16px;display:flex;flex-wrap:wrap;gap:16px;align-items:center;background:#eaf2fb;border:1px solid #bcd7f0;border-radius:8px;padding:12px 16px;">
  <span style="font-weight:700;color:#2b6cb0;">📄 계약 연장 (신규계약)</span>
  <span style="color:#7f8c8d;font-size:12px;">변경된 월세/보증금/관리비는 아래에 입력하면 그 시점부터 적용됩니다. 만기가 지나면 자동으로 '묵시적 갱신중'으로 표시됩니다.</span>
</div>
<?php endif; ?>

<div class="card scan-card">
  <div class="scan-row">
    <div>
      <div class="scan-title">📄 계약서 사진으로 자동 채우기</div>
      <div class="scan-sub">계약서를 촬영하거나 이미지를 올리면 AI가 읽어 아래 항목을 채워줍니다. <b>결과는 반드시 확인·수정</b>하세요.</div>
    </div>
    <label class="btn btn-primary scan-btn">
      <span id="nwScanLabel">📷 사진 선택 / 촬영</span>
      <input type="file" id="nwScanInput" accept="image/*" capture="environment" style="display:none;" onchange="nwScanContract(this)">
    </label>
  </div>
  <div id="nwScanStatus" class="scan-status" style="display:none;"></div>
  <div class="scan-thumbs" id="nwScanThumbs">
    <?php foreach ($images as $im): ?>
    <span class="scan-thumb-wrap">
      <a class="scan-thumb" href="/nw/image.php?id=<?= (int)$im['id'] ?>" target="_blank" title="원본 보기"><img src="/nw/image.php?id=<?= (int)$im['id'] ?>" alt="계약서"></a>
      <button type="button" class="scan-thumb-del" title="이 사진 삭제" onclick="nwDeleteImage(<?= (int)$im['id'] ?>, this)">✕</button>
    </span>
    <?php endforeach; ?>
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

      <div class="form-row4 full">
        <div class="form-field"><label>계약일</label><input type="date" id="f_contract_date" value="<?= $get('contract_date') ?>"></div>
        <div class="form-field"><label>잔금일</label><input type="date" id="f_balance_date" value="<?= $get('balance_date') ?>"></div>
        <div class="form-field"><label>~ 만료일 <span style="color:#a0aec0;font-size:11px;">(계약서상)</span></label><input type="date" id="f_contract_end_date" value="<?= $get('contract_end_date') ?>"></div>
        <div class="form-field"><label>실제 퇴거일 <span style="color:#a0aec0;font-size:11px;">(재실중 비움)</span></label><input type="date" id="f_move_out_date" value="<?= $get('move_out_date') ?>"></div>
      </div>

      <div class="form-row4 full">
        <div class="form-field"><label>차임(월세)</label><div class="input-money"><input type="text" inputmode="numeric" class="money" id="f_rent_fee" value="<?= $money('rent_fee') ?>"></div></div>
        <div class="form-field"><label>관리비</label><div class="input-money"><input type="text" inputmode="numeric" class="money" id="f_maintenance_fee" value="<?= $money('maintenance_fee') ?>"></div></div>
        <div class="form-field"><label>주차비</label><div class="input-money"><input type="text" inputmode="numeric" class="money" id="f_parking_fee" value="<?= $money('parking_fee') ?>"></div></div>
        <div class="form-field"><label>납부일 (매월)</label><input type="number" min="1" max="31" id="f_rent_pay_day" value="<?= $get('rent_pay_day') ?>"></div>
      </div>
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
let NW_IMG = 0; // 스캔으로 저장된 계약서 이미지 id (저장 시 계약에 연결)

// 계약서 사진 업로드 → Claude Vision 판독 → 폼 자동 채움
function nwScanContract(input) {
  const f = input.files && input.files[0];
  if (!f) return;
  const status = document.getElementById('nwScanStatus');
  document.getElementById('nwScanLabel').textContent = '📷 사진 선택 / 촬영';
  status.style.display = '';
  status.className = 'scan-status loading';
  status.textContent = '⏳ 계약서를 읽는 중입니다… (10~30초)';

  const fd = new FormData();
  fd.append('module', 'contract');
  fd.append('action', 'scanImage');
  fd.append('room_id', '<?= $roomId ?>');
  <?php if ($editId): ?>fd.append('contract_id', '<?= (int)$editId ?>');<?php endif; ?>
  fd.append('file', f);

  fetch('/nw/api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(j => {
      if (!j.ok) { status.className = 'scan-status err'; status.textContent = '오류: ' + (j.msg || '실패'); return; }
      if (j.image_id) { NW_IMG = j.image_id; nwAddScanThumb(j.image_id); }
      if (!j.fields) { status.className = 'scan-status err'; status.textContent = j.msg || 'AI 판독 실패 — 직접 입력해 주세요. (사진은 저장됨)'; return; }
      const n = nwFillFromScan(j.fields);
      status.className = 'scan-status ok';
      status.textContent = '✅ ' + n + '개 항목을 채웠습니다. 내용을 확인·수정한 뒤 저장하세요.';
    })
    .catch(() => { status.className = 'scan-status err'; status.textContent = '네트워크 오류가 발생했습니다.'; });
}

function nwAddScanThumb(id) {
  const box = document.getElementById('nwScanThumbs');
  const w = document.createElement('span');
  w.className = 'scan-thumb-wrap';
  w.innerHTML = '<a class="scan-thumb" href="/nw/image.php?id=' + id + '" target="_blank" title="원본 보기"><img src="/nw/image.php?id=' + id + '" alt="계약서"></a>' +
                '<button type="button" class="scan-thumb-del" title="이 사진 삭제" onclick="nwDeleteImage(' + id + ', this)">✕</button>';
  box.appendChild(w);
}
// 잘못 올린 계약서 사진 삭제
function nwDeleteImage(id, btn) {
  if (!confirm('이 계약서 사진을 삭제할까요?')) return;
  nwApi('contract', 'deleteImage', { id: id }).then(() => {
    const w = btn.closest('.scan-thumb-wrap');
    if (w) w.remove();
    if (NW_IMG === id) NW_IMG = 0; // 방금 스캔한 사진이면 계약 연결도 해제
    const st = document.getElementById('nwScanStatus');
    if (st) { st.style.display = ''; st.className = 'scan-status ok'; st.textContent = '🗑 사진을 삭제했습니다. 다시 올리려면 위 버튼을 눌러 주세요.'; }
  }).catch(() => alert('삭제에 실패했습니다.'));
}

// AI가 읽은 필드로 폼 채우기 (빈 값은 건너뜀). 채운 개수 반환.
function nwFillFromScan(fx) {
  let cnt = 0;
  const setV = (id, v) => { if (v === undefined || v === null || v === '') return; const el = document.getElementById(id); if (el) { el.value = v; cnt++; } };
  const setMoney = (id, v) => { if (v === undefined || v === null || v === '') return; const el = document.getElementById(id); if (!el) return; el.value = String(v).replace(/[^0-9]/g, ''); nwFmtMoney(el); cnt++; };
  const setSsn = (p1, p2, v) => { if (!v) return; const d = String(v).replace(/\D/g, ''); const a = document.getElementById(p1), b = document.getElementById(p2); if (a && b) { a.value = d.slice(0, 6); b.value = d.slice(6, 13); cnt++; } };

  // 계약형태 라디오
  if (fx.contract_type) {
    const r = document.querySelector('input[name=contract_type][value="' + fx.contract_type + '"]');
    if (r) { r.checked = true; cnt++; }
  }
  // 전세권설정 체크박스
  if (fx.deposit_registered !== undefined && fx.deposit_registered !== '') {
    const chk = document.getElementById('f_deposit_registered');
    if (chk) { chk.checked = (String(fx.deposit_registered) === '1' || fx.deposit_registered === true); cnt++; }
  }
  setV('f_tenant_name', fx.tenant_name);
  setSsn('f_tenant_ssn1', 'f_tenant_ssn2', fx.tenant_ssn);
  setV('f_tenant_phone', fx.tenant_phone);
  setV('f_co_tenant_name', fx.co_tenant_name);
  setSsn('f_co_tenant_ssn1', 'f_co_tenant_ssn2', fx.co_tenant_ssn);
  setV('f_co_tenant_phone', fx.co_tenant_phone);
  setMoney('f_deposit', fx.deposit);
  setMoney('f_down_payment', fx.down_payment);
  setMoney('f_balance_amount', fx.balance_amount);
  setV('f_contract_date', fx.contract_date);
  setV('f_balance_date', fx.balance_date);
  setV('f_contract_end_date', fx.contract_end_date);
  setMoney('f_rent_fee', fx.rent_fee);
  setMoney('f_maintenance_fee', fx.maintenance_fee);
  setMoney('f_parking_fee', fx.parking_fee);
  setV('f_rent_pay_day', fx.rent_pay_day);
  setV('f_agent_office', fx.agent_office);
  setV('f_agent_phone', fx.agent_phone);
  setV('f_agent_ceo', fx.agent_ceo);
  setV('f_special_terms', fx.special_terms);
  return cnt;
}

function nwSubmitContract() {
  const rt = document.querySelector('input[name=contract_type]:checked');
  const d = {
    room_id: <?= $roomId ?>,
    image_id: NW_IMG,
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
    parking_fee: nwUnmoney('f_parking_fee'),
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
  d.renewal_type = 'extended';
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
    $utilCharges = $nw->utilityChargesByRoom($roomId); // 월별 공과금 부과액 {bill_ym:{water,electric}}
    $preYear   = (int)($_GET['year'] ?? 0);

    // focus 계약: URL contract_id 우선 → 활성 → 최신. 이 계약의 기간만 보여준다.
    $focusCid = (int)($_GET['contract_id'] ?? 0);
    $head = null;
    if ($focusCid) foreach ($contracts as $cc) if ((int)$cc['id'] === $focusCid) { $head = $cc; break; }
    if (!$head)    foreach ($contracts as $cc) if ($cc['status'] === 'active') { $head = $cc; break; }
    if (!$head)    $head = $contracts[0] ?? null;
    $focusId = $head ? (int)$head['id'] : 0;
    $headImages = $focusId ? $nw->listContractImages($focusId) : []; // focus 계약에 첨부된 계약서 사진

    // JS용 계약 배열(호실 전 계약) + 세입자 전환 칩용 기간 라벨
    $jsC = [];
    foreach ($contracts as $cc) {
        // 재실/월세 시작 = 입주일(잔금일) 우선, 없으면 계약일
        $s = ($cc['balance_date'] ?? '') ?: ($cc['contract_date'] ?: ($cc['first_contract_date'] ?? ''));
        $sy = 0; $sm = 0; if (preg_match('/^(\d{4})-(\d{2})/', (string)$s, $m)) { $sy = (int)$m[1]; $sm = (int)$m[2]; }
        $occEnd = ($cc['move_out_date'] ?? '') ?: ($cc['contract_end_date'] ?? ''); // 재실 종료 = 실제 퇴거일 우선
        $ey = 0; $em = 0; if (preg_match('/^(\d{4})-(\d{2})/', (string)$occEnd, $m)) { $ey = (int)$m[1]; $em = (int)$m[2]; }
        $psS = $sy ? substr((string)$sy, 2, 2) . '.' . sprintf('%02d', $sm) : '';
        $psE = $occEnd ? substr((string)$occEnd, 2, 2) . '.' . substr((string)$occEnd, 5, 2) : '';
        $jsC[] = [
            'id' => (int)$cc['id'], 'tenant' => (string)$cc['tenant_name'],
            'rent' => (int)$cc['rent_fee'], 'mnt' => (int)$cc['maintenance_fee'], 'park' => (int)($cc['parking_fee'] ?? 0),
            'monthly' => (int)$cc['rent_fee'] + (int)$cc['maintenance_fee'] + (int)($cc['parking_fee'] ?? 0),
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
.tenant-bar,.year-bar{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:12px 14px;margin-bottom:14px;box-shadow:0 1px 3px rgba(0,0,0,.05);}
.tb-head{font-size:12px;font-weight:700;color:#718096;margin-bottom:9px;letter-spacing:.5px;}
.tb-chips{display:flex;flex-wrap:wrap;gap:8px;}
.tchip{display:inline-flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;line-height:1.2;padding:7px 14px;border:1px solid #cbd5e0;border-radius:12px;background:#fff;cursor:pointer;color:#2d3748;font-weight:700;font-size:15px;text-decoration:none;}
.tchip:hover{background:#f7fafc;}
.tchip.on{background:#2d3748;border-color:#2d3748;color:#fff;}
.tchip .tc-per{margin:3px 0 0;font-size:11px;font-weight:400;opacity:.75;letter-spacing:-.2px;}
.year-chip{padding:7px 16px;border:1px solid #cbd5e0;border-radius:12px;background:#fff;cursor:pointer;font-size:14px;color:#4a5568;font-weight:700;}
.year-chip:hover{background:#f7fafc;}
.year-chip.on{background:#2d3748;border-color:#2d3748;color:#fff;}
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
.ms-part{background:#fef3e2;color:#b9770e;}
.hist-table td .mstat + .mstat{margin-top:3px;}
.hist-table th .th-exp{font-size:11px;font-weight:400;color:#cbd5e0;}
.hist-table .pc-paid{color:#1e8449;}
.hist-table .pc-due{color:#c53030;font-weight:700;font-size:12px;}
.hist-table .pc-sub{font-size:10px;font-weight:700;margin-top:1px;line-height:1.3;}
.hist-table .pc-sub-due{color:#c53030;}
.hist-table .pc-sub-over{color:#b9770e;}
.hist-table .pc-date{font-size:10px;color:#a0aec0;font-weight:400;margin-top:2px;line-height:1.35;}
.hist-table tr.mtop > td{border-top:2px solid #e5e9ef;}
.hist-table .cs-over{color:#b9770e;font-weight:700;font-size:12px;}
.hist-table tfoot .hist-foot td{background:#eef2f7;border-top:2px solid #cbd5e0;font-size:13px;padding:11px 8px;text-align:center;}
.hist-table tr.dep-row{background:#f6faff;}
.hist-table .hdim{color:#c0cad4;}
.hist-table tr.editable{cursor:pointer;}
.hist-table tr.editable:hover{background:#f0f6fc;}
.nwep-row{border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin-bottom:10px;}
.nwep-3,.nwep-2,.nwep-1{display:grid;gap:8px;margin-bottom:8px;}
.nwep-3{grid-template-columns:1fr 1fr 1fr;}
.nwep-2{grid-template-columns:1fr 1fr;}
.nwep-1{grid-template-columns:1fr;}
.nwep-row label{font-size:12px;color:#718096;display:block;margin-bottom:2px;}
.nwep-row input{width:100%;padding:6px 8px;border:1px solid #cbd5e0;border-radius:6px;}
.nwep-row input[readonly]{background:#f1f3f5;color:#718096;cursor:not-allowed;}
.nwep-actions{display:flex;justify-content:space-between;gap:8px;}
.nwep-line{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:10px;}
.nwep-line > div{display:flex;flex-direction:column;}
.nwep-line input{width:96px;}
.nwep-line input[type=month]{width:132px;}
.nwep-memo{display:flex;gap:8px;align-items:flex-end;}
.nwep-memo-in{flex:1;display:flex;flex-direction:column;}
.nwep-memo .btn{white-space:nowrap;}
.nwep-line .ep-more{align-self:flex-end;padding:6px 11px;border:1px dashed #cbd5e0;border-radius:8px;background:#f7fafc;color:#4a5568;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;}
.nwep-line .ep-more:hover{background:#eef6ff;border-color:#90cdf4;color:#2b6cb0;}
.pay-attr{font-size:12px;color:#2b6cb0;margin-top:4px;min-height:16px;}
.hist-table td .tn{display:inline-block;font-size:11px;color:#718096;}
</style>

<div class="page-head">
  <div>
    <a class="back-link" href="/nw/index.php?mode=building&id=<?= (int)$unit['building_id'] ?>">← 호실 목록</a>
    <h1 style="margin-top:6px;"><?= nw_h($building['name']) ?> (<?= nw_h($unit['room_no']) ?>호)<?= $head ? ' — ' . nw_h($head['tenant_name']) : '' ?><?php if ($headImages): ?> <button type="button" class="doc-chip" onclick="nwOpenModal('nwDocModal')">📄 계약서<?= count($headImages) > 1 ? ' ' . count($headImages) : '' ?></button><?php endif; ?></h1>
  </div>
</div>

<div class="pay-stat">
  <?php if ($head && $head['tenant_phone']): ?><span><i>전화</i><b><?= nw_h($head['tenant_phone']) ?></b></span><?php endif; ?>
  <?php if ($head): ?><span><i>계약기간</i><b><?= nw_h($head['contract_date']) ?> ~ <?= nw_h($head['contract_end_date']) ?></b></span><?php endif; ?>
  <span><i>월세</i><b class="mono"><?= nw_money($head['rent_fee'] ?? 0) ?>원</b></span>
  <span><i>관리비</i><b class="mono"><?= nw_money($head['maintenance_fee'] ?? 0) ?>원</b></span>
  <span><i>주차비</i><b class="mono"><?= nw_money($head['parking_fee'] ?? 0) ?>원</b></span>
  <span><i>계약이력</i><b><?= count($contracts) ?>건</b></span>
</div>


<div id="nwTenantBar" class="tenant-bar"></div>
<div id="nwYearBar" class="year-bar"></div>
<div id="nwYearSummary" class="year-summary" style="display:none;"></div>

<table class="hist-table" id="nwHist">
  <thead><tr><th>월</th><th>월세<?php if ($head && (int)$head['rent_fee']): ?><br><span class="th-exp">(<?= number_format((int)$head['rent_fee']) ?>)</span><?php endif; ?></th><th>관리비<?php if ($head && (int)$head['maintenance_fee']): ?><br><span class="th-exp">(<?= number_format((int)$head['maintenance_fee']) ?>)</span><?php endif; ?></th><th>주차비<?php if ($head && (int)($head['parking_fee'] ?? 0)): ?><br><span class="th-exp">(<?= number_format((int)$head['parking_fee']) ?>)</span><?php endif; ?></th><th>납부일</th><th class="edge">메모</th><th>공과금<br><span style="font-size:10px;font-weight:400;color:#95a5a6;">(수도·전기)</span></th><th>납부일</th><th>메모</th></tr></thead>
  <tbody id="nwHistBody"></tbody>
  <tfoot id="nwHistFoot"></tfoot>
</table>

<script>
const NWP = {
  curY: <?= (int)date('Y') ?>, curM: <?= (int)date('n') ?>,
  preYear: <?= $preYear ?>, focusId: <?= $focusId ?>, roomId: <?= (int)$roomId ?>,
  charges: <?= json_encode($utilCharges ?: (object)[], JSON_UNESCAPED_UNICODE) ?>, // {bill_ym:{water,electric}} 월별 공과금 부과액
  contracts: <?= json_encode($jsC, JSON_UNESCAPED_UNICODE) ?>,
  payments: <?= json_encode(array_map(fn($p) => ['id' => (int)$p['id'], 'date' => substr((string)$p['pay_date'], 0, 10), 'bym' => ($p['bill_ym'] ?: substr((string)$p['pay_date'], 0, 7)), 'rent' => (int)$p['rent_fee'], 'mnt' => (int)$p['maintenance_fee'], 'park' => (int)($p['parking_fee'] ?? 0), 'water' => (int)($p['water_fee'] ?? 0), 'elec' => (int)($p['electric_fee'] ?? 0), 'memo' => (string)$p['memo'], 'cid' => (int)$p['contract_id'], 'tenant' => (string)$p['tenant_name']], $payments), JSON_UNESCAPED_UNICODE) ?>,
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
  NWP.payments.forEach(p => { const mm = /^(\d{4})-(\d{2})/.exec(p.bym); if (mm && +mm[1] === y) { const cur = ymI(+mm[1], +mm[2]); if (cur >= focusStartYm && cur <= focusEndYm) s += p.rent + p.mnt + (p.park || 0); } }); // 귀속월 기준
  return s;
}
// 공과금(수도·전기) 부과액 합계: focus 기간 내 해당 연도 월별 부과액
function chargeFor(y){
  let s = 0;
  for (let m = 1; m <= 12; m++) {
    const cur = ymI(y, m);
    if (cur < focusStartYm || cur > focusEndYm) continue;
    const c = NWP.charges[y + '-' + String(m).padStart(2, '0')];
    if (c) s += (c.water || 0) + (c.electric || 0);
  }
  return s;
}
// 공과금 실제 납부액 합계(귀속월 기준)
function utilPaidFor(y){
  let s = 0;
  NWP.payments.forEach(p => { const mm = /^(\d{4})-(\d{2})/.exec(p.bym); if (mm && +mm[1] === y) { const cur = ymI(+mm[1], +mm[2]); if (cur >= focusStartYm && cur <= focusEndYm) s += (p.water || 0) + (p.elec || 0); } });
  return s;
}

// 항목별(월세·관리비·주차) 기간 예상·납부 합계 — 하단 미납/초과 요약용
function catTotals(yy){
  let er = 0, em = 0, ep = 0, pr = 0, pm = 0, pk = 0;
  if (FOCUS) yy.forEach(y => {
    for (let m = 1; m <= 12; m++) {
      const cur = ymI(y, m);
      if (cur < focusStartYm || cur > focusEndYm || cur === moveInYm || cur >= nowYm) continue; // 예상은 직전월까지·이사월 제외
      er += FOCUS.rent || 0; em += FOCUS.mnt || 0; ep += FOCUS.park || 0;
    }
  });
  NWP.payments.forEach(p => {
    const mm = /^(\d{4})-(\d{2})/.exec(p.bym);
    if (!mm || yy.indexOf(+mm[1]) < 0) return;
    const cur = ymI(+mm[1], +mm[2]);
    if (cur < focusStartYm || cur > focusEndYm) return;
    pr += p.rent || 0; pm += p.mnt || 0; pk += p.park || 0;
  });
  return { er, em, ep, pr, pm, pk };
}
function nwSelectYear(sel){
  document.querySelectorAll('.year-chip').forEach(c => c.classList.toggle('on', c.dataset.y === String(sel)));
  const isAll = sel === 'all';
  const yy = isAll ? years.slice() : [+sel];

  // 요약(전체면 focus 계약 기간 합산)
  let exp = 0, paid = 0, n = 0;
  yy.forEach(yr => { const e = expectedFor(yr); exp += e.exp; n += e.n; paid += paidFor(yr); });
  let uexp = 0, upaid = 0;
  yy.forEach(yr => { uexp += chargeFor(yr); upaid += utilPaidFor(yr); });
  const udiff = upaid - uexp;
  const uStatus = (uexp === 0 && upaid === 0) ? '' :
    (udiff < 0 ? '<span class="ys-stat ys-due">미납<b>' + won(-udiff) + '원</b></span>'
     : (udiff > 0 ? '<span class="ys-stat ys-over">초과<b>' + won(udiff) + '원</b></span>'
        : '<span class="ys-stat ys-done">완납<b>✓</b></span>'));
  const diff = paid - exp;
  let status;
  if (diff < 0)      status = '<span class="ys-stat ys-due">미납<b>' + won(-diff) + '원</b></span>';
  else if (diff > 0) status = '<span class="ys-stat ys-over">초과<b>' + won(diff) + '원</b></span>';
  else               status = '<span class="ys-stat ys-done">완납<b>✓</b></span>';
  document.getElementById('nwYearSummary').innerHTML =
    '<span class="ys-title">' + (isAll ? '전체 기간' : (sel + '년')) + ' · ' + n + '개월</span>' +
    '<span class="ys-stat">납부예정<b>' + won(exp) + '원</b></span>' +
    '<span class="ys-stat">납부완료<b>' + won(paid) + '원</b></span>' + status +
    ((uexp || upaid) ? '<span style="flex-basis:100%;height:0;"></span><span class="ys-title" style="font-size:13px;">공과금(수도·전기)</span><span class="ys-stat">부과<b>' + won(uexp) + '원</b></span><span class="ys-stat">납부<b>' + won(upaid) + '원</b></span>' + uStatus : '');
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
      '<tr class="dep-row"><td><b>' + label + '</b></td>' +
      '<td class="mono">' + dash + '</td><td class="mono">' + dash + '</td><td class="mono">' + dash + '</td>' +
      '<td class="mono">' + (date || dash) + '</td><td class="edge">' + (memo || label) + ' <b>' + won(amt) + '원</b></td>' +
      '<td class="mono">' + dash + '</td><td class="mono">' + dash + '</td><td>' + dash + '</td></tr>';
    mh += depRow('계약금', FOCUS.down, FOCUS.cdate);
    mh += depRow('잔금', FOCUS.bal, FOCUS.bdate, '잔금 및 입주');
  }
  yy.forEach(yr => {
    for (let m = 1; m <= 12; m++) {
      const cur = ymI(yr, m);
      if (!FOCUS || cur < focusStartYm || cur > focusEndYm) continue; // focus 계약 기간 밖 월 제외
      const key = yr + '-' + String(m).padStart(2, '0');
      const pays = (byMonth[key] || []).slice().sort((a, b) => String(a.date || '').localeCompare(String(b.date || '')));
      if (cur === moveInYm && !pays.length) continue; // 입주월은 월세 없음 → 생략
      const label = isAll ? (String(yr).slice(2) + '.' + String(m).padStart(2, '0')) : (m + '월');
      const chg = NWP.charges[key] || { water: 0, electric: 0 };
      const ctot = (chg.water || 0) + (chg.electric || 0);
      const pr = pays.reduce((s, p) => s + (p.rent || 0), 0), pm = pays.reduce((s, p) => s + (p.mnt || 0), 0), pk = pays.reduce((s, p) => s + (p.park || 0), 0);
      const pw = pays.reduce((s, p) => s + (p.water || 0) + (p.elec || 0), 0);
      // 정액 항목 셀: 납부금액 / 미납 / 예정(회색) / 해당없음
      const catCell = (paid, exp) => {
        if (paid > 0)                      return '<b class="pc-paid">' + won(paid) + '</b>';
        if (exp === 0 || cur === moveInYm) return dash;
        if (cur >= nowYm)                  return '<span class="hdim">' + won(exp) + '</span>';
        return '<span class="pc-due">미납</span>';
      };
      const fixPays = pays.filter(p => (p.rent || 0) + (p.mnt || 0) + (p.park || 0) > 0);   // 월세·관리비·주차 입금
      const utilPays = pays.filter(p => (p.water || 0) + (p.elec || 0) > 0);                // 공과금 입금
      const datesOf = ps => ps.length ? ps.map(p => p.date).join('<br>') : dash;
      const memosOf = ps => ps.some(p => p.memo) ? ps.map(p => esc(p.memo || '')).join('<br>') : dash;
      // 공과금 셀: 납부액 + 부과(청구) 대비 미납/초과 표시(많이/적게 낸 것 구분)
      let utilCell;
      if (pw > 0) {
        utilCell = '<b class="pc-paid">' + won(pw) + '</b>';
        if (ctot > pw)                  utilCell += '<div class="pc-sub pc-sub-due">미납 ' + won(ctot - pw) + '</div>';
        else if (ctot > 0 && pw > ctot) utilCell += '<div class="pc-sub pc-sub-over">초과 ' + won(pw - ctot) + '</div>';
      } else if (ctot > 0 && cur < nowYm && cur !== moveInYm) {
        utilCell = '<span class="pc-due">미납 ' + won(ctot) + '</span>';
      } else { utilCell = dash; }
      // 월 1줄: [정액] 월세·관리비·주차·납부일·메모  ┃  [공과금] 공과금·납부일·메모
      mh += '<tr class="editable" title="클릭해 수정" onclick="nwEditMonth(\'' + key + '\')">' +
            '<td><b>' + label + '</b></td>' +
            '<td class="mono">' + catCell(pr, FOCUS.rent || 0) + '</td>' +
            '<td class="mono">' + catCell(pm, FOCUS.mnt || 0) + '</td>' +
            '<td class="mono">' + catCell(pk, FOCUS.park || 0) + '</td>' +
            '<td class="mono">' + datesOf(fixPays) + '</td><td class="edge">' + memosOf(fixPays) + '</td>' +
            '<td class="mono">' + utilCell + '</td><td class="mono">' + datesOf(utilPays) + '</td><td>' + memosOf(utilPays) + '</td>' +
            '</tr>';
    }
  });
  document.getElementById('nwHistBody').innerHTML = mh || '<tr><td colspan="9" style="color:#bdc3c7;padding:20px;text-align:center;">해당 없음</td></tr>';

  // 하단 합계 행(리스트와 동일 레벨): 항목별 미납/초과를 각 열에 정렬
  const ct = catTotals(yy);
  const footCell = (ex, pd) => {
    if (ex === 0 && pd === 0) return dash;
    const d = pd - ex;
    if (d < 0) return '<span class="pc-due">미납 ' + won(-d) + '</span>';
    if (d > 0) return '<span class="cs-over">초과 ' + won(d) + '</span>';
    return '<span class="pc-paid">완납 ✓</span>';
  };
  document.getElementById('nwHistFoot').innerHTML =
    '<tr class="hist-foot"><td><b>합계</b></td>' +
    '<td class="mono">' + footCell(ct.er, ct.pr) + '</td>' +
    '<td class="mono">' + footCell(ct.em, ct.pm) + '</td>' +
    '<td class="mono">' + footCell(ct.ep, ct.pk) + '</td>' +
    '<td></td><td class="edge"></td>' +
    '<td class="mono">' + footCell(uexp, upaid) + '</td><td></td><td></td></tr>';
}

function nwRenderYears(){
  let h = '<div class="year-chip" data-y="all" onclick="nwSelectYear(\'all\')">전체</div>';
  h += years.map(y => {
    const e = expectedFor(y), paid = paidFor(y);
    const dot = (e.exp > 0 && paid < e.exp) ? ' <span class="yc-sub">미납</span>' : '';
    return '<div class="year-chip" data-y="' + y + '" onclick="nwSelectYear(' + y + ')">' + y + '년' + dot + '</div>';
  }).join('');
  document.getElementById('nwYearBar').innerHTML = '<div class="tb-head">조회 기간</div><div class="tb-chips">' + h + '</div>';
  let def = (NWP.preYear && years.indexOf(NWP.preYear) >= 0) ? NWP.preYear : (years.indexOf(NWP.curY) >= 0 ? NWP.curY : years[years.length - 1]);
  if (def != null) nwSelectYear(def);
}
// 세입자 전환 칩 (호실에 계약 여럿일 때) — 클릭하면 그 세입자 기간으로 스코프
function nwRenderTenantBar(){
  const bar = document.getElementById('nwTenantBar');
  if (!bar) return;
  if (NWP.contracts.length <= 1) { bar.style.display = 'none'; return; }
  let chips = '';
  NWP.contracts.forEach(c => {
    const on = (FOCUS && c.id === FOCUS.id) ? ' on' : '';
    const per = (c.ps || c.pe) ? '<span class="tc-per">' + c.ps + '~' + c.pe + '</span>' : '';
    chips += '<a class="tchip' + on + '" href="/nw/index.php?mode=payment&room_id=' + NWP.roomId + '&contract_id=' + c.id + '">' + esc(c.tenant || '(무기명)') + per + '</a>';
  });
  bar.innerHTML = '<div class="tb-head">세입자</div><div class="tb-chips">' + chips + '</div>';
}
nwRenderTenantBar();
nwRenderYears();

// 납부 수정: 월 행 클릭 → 그 달 납부기록 편집 모달
const nwUnmoney2 = v => Number(String(v).replace(/[^0-9]/g, '')) || 0;
function nwEditMonth(bym){ nwOpenPayEdit(NWP.payments.filter(p => p.bym === bym)); }
function nwEditPayment(id){ const p = NWP.payments.find(x => x.id === id); if (p) nwOpenPayEdit([p]); }
function nwOpenPayEdit(pays){
  // 납부 기록 편집 — 클릭한 입금(들)만. 정액 + 공과금 납부액 (부과액 입력은 '💡 공과금 관리' 페이지에서).
  let html = '';
  if (pays.length) {
    html += pays.map(p => {
      // 값 있는 항목만 표시, 0원 항목은 숨겨두고 ＋로 펼쳐 편집(항목 간 이동용)
      const fld = (cls, label, val) => {
        const zero = (val || 0) <= 0;
        return '<div' + (zero ? ' class="ep-zero" style="display:none;"' : '') + '><label>' + label + '</label><input class="' + cls + ' money" value="' + won(val || 0) + '" oninput="nwFmtMoney(this)"></div>';
      };
      const flds = fld('ep-rent', '월세', p.rent) + fld('ep-mnt', '관리비', p.mnt) + fld('ep-park', '주차비', p.park || 0) +
                   fld('ep-water', '수도세', p.water || 0) + fld('ep-elec', '전기세', p.elec || 0);
      const hasZero = [p.rent, p.mnt, p.park || 0, p.water || 0, p.elec || 0].some(v => (v || 0) <= 0);
      return '<div class="nwep-row">' +
        '<div class="nwep-line">' + flds +
          '<div><label>귀속월</label><input type="month" class="ep-bym" value="' + p.bym + '"></div>' +
          (hasZero ? '<button type="button" class="ep-more" onclick="nwEpMore(this)" title="0원 항목 펼쳐 편집(항목 이동)">＋ 항목</button>' : '') +
          '<input type="hidden" class="ep-date" value="' + p.date + '">' + // 납부일 숨김(값 유지)
        '</div>' +
        '<div class="nwep-memo">' +
          '<div class="nwep-memo-in"><label>메모</label><input class="ep-memo" value="' + esc(p.memo || '') + '"></div>' +
          '<button class="btn btn-danger btn-sm" onclick="nwDeletePayment(' + p.id + ')">삭제</button>' +
          '<button class="btn btn-primary btn-sm" onclick="nwSavePayment(' + p.id + ', this)">저장</button>' +
          '<button class="btn btn-outline btn-sm" onclick="nwCloseModal(\'nwEditPayModal\')">닫기</button>' +
        '</div>' +
      '</div>';
    }).join('');
  } else {
    html += '<div style="padding:12px 4px;color:#a0aec0;font-size:13px;">이 달 납부 내역이 없습니다. 납부액은 은행 거래내역 업로드로 등록됩니다.</div><div style="text-align:right;"><button class="btn btn-outline" onclick="nwCloseModal(\'nwEditPayModal\')">닫기</button></div>';
  }
  document.getElementById('nwEditPayBody').innerHTML = html;
  nwOpenModal('nwEditPayModal');
}
// 숨긴(0원) 항목 펼치기 — 관리비↔주차비 등 항목 간 이동 편집용
function nwEpMore(btn){
  btn.closest('.nwep-row').querySelectorAll('.ep-zero').forEach(d => { d.style.display = ''; d.classList.remove('ep-zero'); });
  btn.style.display = 'none';
}
function nwSavePayment(id, btn){
  const row = btn.closest('.nwep-row');
  const g = cls => { const el = row.querySelector('.' + cls); return el ? nwUnmoney2(el.value) : 0; }; // 숨겨진(0) 항목은 0으로
  nwApi('payment', 'update', {
    id: id,
    rent_fee: g('ep-rent'),
    maintenance_fee: g('ep-mnt'),
    parking_fee: g('ep-park'),
    water_fee: g('ep-water'),
    electric_fee: g('ep-elec'),
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
  <div class="modal-box" style="width:520px;max-width:94vw;">
    <h3>납부 수정</h3>
    <div id="nwEditPayBody"></div>
  </div>
</div>

<?php if ($headImages): ?>
<div class="modal-overlay doc-overlay" id="nwDocModal" onclick="if(event.target===this)nwCloseModal('nwDocModal')">
  <div class="doc-box">
    <button type="button" class="doc-x" onclick="nwCloseModal('nwDocModal')" aria-label="닫기">✕</button>
    <div class="doc-imgs">
      <?php foreach ($headImages as $im): ?>
      <img src="/nw/image.php?id=<?= (int)$im['id'] ?>" alt="계약서" loading="lazy">
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>
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
table.imp-table th,table.imp-table td{padding:6px 6px;border-bottom:1px solid #edf2f7;text-align:left;}
table.imp-table th{background:#f7fafc;color:#4a5568;font-weight:600;position:sticky;top:0;}
table.imp-table td.num{text-align:right;font-variant-numeric:tabular-nums;}
table.imp-table tr.grp-head td{background:#e2e8f0;color:#2d3748;font-weight:700;font-size:12px;padding:8px 10px;border-top:2px solid #cbd5e0;}
table.imp-table tr.grp-head td.gh-paid{background:#c6f6d5;color:#22543d;text-align:right;font-variant-numeric:tabular-nums;} /* 항목 입금완료 */
table.imp-table tr.grp-head td.gh-due{background:#fed7d7;color:#9b2c2c;text-align:right;font-variant-numeric:tabular-nums;}  /* 항목 미납 */
table.imp-table tr.grp-head td.gh-over{background:#feebc8;color:#9c4221;text-align:right;font-variant-numeric:tabular-nums;} /* 부과없이 입금 */
table.imp-table tr.grp-head td.gh-none{background:#edf2f7;color:#cbd5e0;text-align:right;} /* 해당 항목 없음 */
table.imp-table tr.grp-head.gh-rowbad td:first-child{box-shadow:inset 5px 0 0 #e53e3e;} /* 미납 있는 방 */
table.imp-table tr.grp-head.gh-rowok td:first-child{box-shadow:inset 5px 0 0 #38a169;}  /* 완납된 방 */
.gh-ok{color:#22643a;} .gh-short{color:#c53030;} .gh-extra{color:#c05621;}
table.imp-table tr.row-none{background:#eef2f7;}
table.imp-table tr.row-low{background:#fff0f0;}
table.imp-table tr.row-mid{background:#fff8ee;}
table.imp-table tr.row-high{background:#ffffff;}
table.imp-table tr.duprow td{opacity:.72;}
table.imp-table select{max-width:170px;padding:4px 6px;border:1px solid #cbd5e0;border-radius:6px;}
table.imp-table input.imoney{width:74px;box-sizing:border-box;padding:4px 5px;border:1px solid #cbd5e0;border-radius:6px;text-align:right;}
table.imp-table input.imoney::placeholder{color:#a0aec0;font-style:italic;} /* 미매칭 수동배정 참고값 */
.conf{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;}
.conf-high{background:#c6f6d5;color:#22543d;}
.conf-mid{background:#feebc8;color:#7b341e;}
.conf-low{background:#fed7d7;color:#742a2a;}
.conf-none{background:#e2e8f0;color:#4a5568;}
.tag-dup{background:#fbd38d;color:#7b341e;padding:2px 7px;border-radius:10px;font-size:11px;margin-left:4px;}
.mism{color:#c05621;font-size:11px;margin-top:3px;}
.recon{color:#c53030;font-size:11px;font-weight:600;margin-top:3px;}
table.imp-table input.imoney.bad{border-color:#e53e3e;background:#fff5f5;}
table.imp-table .csel{display:inline-block;min-width:52px;max-width:120px;padding:4px 9px;border:1px solid #cbd5e0;border-radius:14px;background:#eef6ff;color:#2b6cb0;font-weight:700;font-size:13px;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;vertical-align:middle;}
table.imp-table .csel:hover{background:#e0efff;border-color:#90cdf4;}
table.imp-table .csel.empty{background:#fff5f5;color:#c53030;border-color:#feb2b2;font-weight:600;}
.cpick{position:fixed;display:none;z-index:1000;background:#fff;border:1px solid #cbd5e0;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.18);padding:10px;width:min(94vw,680px);max-height:60vh;overflow:auto;}
.cpick-row{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:6px;}
.cpick-row:last-child{margin-bottom:0;}
.cchip{padding:5px 10px;border:1px solid #dbe3ec;border-radius:12px;background:#f7fafc;color:#2d3748;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap;}
.cchip b{font-weight:400;color:#718096;}
.cchip:hover{background:#eef6ff;border-color:#90cdf4;}
.cchip.on{background:#2b6cb0;border-color:#2b6cb0;color:#fff;}
.cchip.on b{color:#dbeafe;}
.cchip.none{color:#a0aec0;font-weight:600;}
.bymwrap{font-size:11px;color:#718096;white-space:nowrap;display:flex;align-items:center;gap:3px;margin-top:4px;}
.bymwrap.bym-off{color:#b7791f;font-weight:700;}
table.imp-table input.bym{font-size:11px;padding:2px 4px;border:1px solid #cbd5e0;border-radius:5px;}
.bymwrap.bym-off input.bym{border-color:#f6ad55;background:#fffaf0;}
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
          <th>월세</th><th>관리비</th><th>주차비</th><th>수도요금</th><th>전기요금</th><th>판정</th>
        </tr>
      </thead>
      <tbody id="nwReview"></tbody>
    </table>
  </div>
  <div style="margin-top:16px;text-align:right;">
    <button class="btn btn-primary" onclick="nwImportConfirm()">✔ 선택 항목 일괄등록</button>
  </div>
</div>

<div id="nwPick" class="cpick"></div>
<script>
let NW_ROWS = [], NW_CAND = [], NW_CHARGES = {};
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
      NW_CHARGES = j.chargesByRoom || {};
      if (!NW_ROWS.length) { alert('거래 행을 찾지 못했습니다. 다른 파일을 시도해 주세요.'); return; }
      nwRenderReview();
      document.getElementById('nwReviewWrap').style.display = 'block';
    })
    .catch(e => alert('업로드 실패: ' + e));
}

// 계약 라벨 파싱: "201호 · 박규동 (25.07~27.08)" → {room, tenant, period}
function nwCandParts(c){
  const m = c.label.match(/^(.+?)호 · (.+?)(?: \((.+)\))?$/);
  return m ? { room: m[1], tenant: m[2], period: m[3] || '' } : { room: c.label, tenant: '', period: '' };
}
function nwCandShort(cid){
  const c = NW_CAND.find(x => String(x.contract_id) === String(cid));
  if (!c) return '— 미지정 —';
  const p = nwCandParts(c);
  return p.room + (p.tenant ? '(' + p.tenant + ')' : '');
}
// 계약의 항목별 예상액(받을 금액): 월세·관리비·주차(계약) + 수도·전기(그 귀속월 부과액)
function nwExpectedOf(c, bym){
  if (!c) return { rent: 0, mnt: 0, park: 0, wat: 0, elec: 0 };
  const ch = NW_CHARGES[nwCandParts(c).room + '|' + bym];
  return { rent: c.rent_fee || 0, mnt: c.maintenance_fee || 0, park: c.parking_fee || 0, wat: ch ? (ch.water || 0) : 0, elec: ch ? (ch.electric || 0) : 0 };
}
function nwRowExpected(r){
  return nwExpectedOf(NW_CAND.find(x => String(x.contract_id) === String(r.contract_id)), r.bill_ym || r.date.slice(0, 7));
}
// 금액 입력칸: 실제값(value) + 예상액 회색 참고(placeholder). 값이 0이면 빈칸으로 두어 참고값이 보이게.
function nwMoneyCell(i, f, val, ph){
  return '<td class="num"><input class="imoney" data-f="' + f + '" data-i="' + i + '" value="' + (val > 0 ? nwMoney(val) : '') + '" placeholder="' + (ph > 0 ? nwMoney(ph) : '') + '" oninput="nwFmtMoney(this);nwRowReconcile(' + i + ');nwUpdateSummary()"></td>';
}
// 계약 선택 = 컴팩트 칩(클릭 시 팝오버). value는 span data-cid에 보관.
function nwSelCell(i, cid){
  const has = cid != null && cid !== '' && String(cid) !== '0';
  return '<span class="csel' + (has ? '' : ' empty') + '" id="csel-' + i + '" data-i="' + i + '" data-cid="' + (has ? cid : '') + '" title="클릭해 계약 선택" onclick="nwOpenPick(event,' + i + ')">' + nwCandShort(has ? cid : '') + '</span>';
}
function nwSelVal(i){ const el = document.getElementById('csel-' + i); return el ? (el.dataset.cid || '') : ''; }
function nwSetSel(i, cid){
  const el = document.getElementById('csel-' + i);
  if (!el) return;
  el.dataset.cid = cid || '';
  el.textContent = nwCandShort(cid || '');
  el.classList.toggle('empty', !cid);
}
// 계약 선택 팝오버(칩 그리드 · 7열). 트리거 재클릭=토글로 닫힘.
let NW_PICK_I = null;
function nwOpenPick(ev, i){
  ev.stopPropagation();
  const box = document.getElementById('nwPick');
  if (NW_PICK_I === i && box.style.display === 'block') { nwClosePick(); return; } // 열려있으면 닫기
  NW_PICK_I = i;
  const cur = nwSelVal(i);
  // 층별로 줄 구분(201~207 / 301~307 …)
  const floors = {};
  NW_CAND.forEach(c => {
    const p = nwCandParts(c);
    const m = p.room.match(/^(.*?)\d{2}$/);
    const fl = m ? (m[1] || '?') : p.room;
    (floors[fl] || (floors[fl] = [])).push({ c: c, p: p });
  });
  let h = '<div class="cpick-row"><span class="cchip none' + (cur ? '' : ' on') + '" onclick="nwPickChoose(\'\')">— 미지정 —</span></div>';
  Object.keys(floors).sort((a, b) => (parseInt(a, 10) || 0) - (parseInt(b, 10) || 0) || a.localeCompare(b)).forEach(fl => {
    h += '<div class="cpick-row">';
    floors[fl].sort((a, b) => a.p.room.localeCompare(b.p.room, undefined, { numeric: true }));
    floors[fl].forEach(o => {
      const on = String(o.c.contract_id) === String(cur) ? ' on' : '';
      h += '<span class="cchip' + on + '" title="' + o.c.label + '" onclick="nwPickChoose(\'' + o.c.contract_id + '\')">' + o.p.room + '<b>(' + o.p.tenant + ')</b></span>';
    });
    h += '</div>';
  });
  box.innerHTML = h;
  box.style.display = 'block';
  const el = document.getElementById('csel-' + i), r = el.getBoundingClientRect();
  let top = r.bottom + 4;
  if (top + box.offsetHeight > window.innerHeight - 8) top = Math.max(8, r.top - box.offsetHeight - 4);
  box.style.left = Math.max(8, Math.min(r.left, window.innerWidth - box.offsetWidth - 12)) + 'px';
  box.style.top = top + 'px';
}
function nwPickChoose(cid){
  if (NW_PICK_I == null) return;
  nwSetSel(NW_PICK_I, cid);
  nwRowRecalc(NW_PICK_I);
  nwClosePick();
}
function nwClosePick(){ const b = document.getElementById('nwPick'); if (b) b.style.display = 'none'; NW_PICK_I = null; }
document.addEventListener('click', nwClosePick);

function nwRenderReview(){
  const confLabel = {high:'확실', mid:'추정', low:'모호', none:'미매칭'};

  // 출금·이자(skip)·이미등록은 목록서 제외. 원래 인덱스(i)는 보존.
  const list = [];
  NW_ROWS.forEach((r, i) => { if (!r.skip_reason && !r.dup) list.push({ i, r }); });

  // ★정렬 기준: 호실(오름차순) → 항목(월세·관리비·주차·수도·전기) → 날짜. 미매칭(호실 없음)은 맨 위.
  const catRank = r => (r.rent_fee > 0 ? 0 : (r.maintenance_fee > 0 ? 1 : ((r.parking_fee || 0) > 0 ? 2 : ((r.water_fee || 0) > 0 ? 3 : ((r.electric_fee || 0) > 0 ? 4 : 5)))));
  const roomOf = r => {
    if (!r.contract_id) return '';
    const c = NW_CAND.find(x => String(x.contract_id) === String(r.contract_id));
    if (!c) return '';
    const k = c.label.indexOf('호');
    return k > 0 ? c.label.slice(0, k) : '';
  };
  const roomCmp = (a, b) => {
    const na = parseInt(a, 10), nb = parseInt(b, 10);
    if (!isNaN(na) && !isNaN(nb) && na !== nb) return na - nb;
    return String(a).localeCompare(String(b));
  };
  list.forEach(o => { o.room = roomOf(o.r); });
  const unmatched = list.filter(o => o.room === '');
  const matched   = list.filter(o => o.room !== '');
  matched.sort((a, b) => roomCmp(a.room, b.room) || (catRank(a.r) - catRank(b.r)) || String(a.r.date).localeCompare(String(b.r.date)));

  // 호실별 집계: 건수·입금합계·대표 계약/귀속월(청구 예정액 계산용)
  const roomCnt = {}, roomFixed = {}, roomAgg = {};
  matched.forEach(o => {
    const rm = o.room;
    roomCnt[rm] = (roomCnt[rm] || 0) + 1;
    if (!o.r.util_kind) roomFixed[rm] = (roomFixed[rm] || 0) + 1; // 월세/관리비류(공과금 제외)
    const a = roomAgg[rm] || (roomAgg[rm] = { dep: 0, rent: 0, mnt: 0, park: 0, wat: 0, elec: 0, ym: {}, con: {} });
    a.dep += o.r.deposit;
    a.rent += o.r.rent_fee || 0; a.mnt += o.r.maintenance_fee || 0; a.park += o.r.parking_fee || 0;
    a.wat += o.r.water_fee || 0; a.elec += o.r.electric_fee || 0;
    const ym = o.r.bill_ym || o.r.date.slice(0, 7); a.ym[ym] = (a.ym[ym] || 0) + 1;
    if (o.r.contract_id) a.con[o.r.contract_id] = (a.con[o.r.contract_id] || 0) + 1;
  });

  let h = '', curRoom = null;
  if (unmatched.length) h += '<tr class="grp-head"><td colspan="11">❓ 미매칭 — 계약을 직접 지정하세요 · ' + unmatched.length + '건</td></tr>';
  const ordered = unmatched.concat(matched);
  ordered.forEach(({ i, r, room }) => {
    if (room && room !== curRoom) {
      curRoom = room;
      h += nwRoomHead(room, roomCnt[room] || 0, roomAgg[room]);
    }
    const dupTag = r.dup ? '<span class="tag-dup">이미등록</span>' : '';
    const reason = r.match_reason ? ' <span style="color:#718096;font-size:11px;">' + r.match_reason + '</span>' : '';
    const bym = r.bill_ym || r.date.slice(0, 7);
    // 귀속월 변경은 '월세/관리비류' 입금이 한 방에 2건 이상일 때, 그 행에만(공과금·단건 제외) — 거래일 아래 표시
    const bymBlock = (room && !r.util_kind && roomFixed[room] >= 2)
      ? '<div class="bymwrap' + (bym !== r.date.slice(0, 7) ? ' bym-off' : '') + '">귀속 <input type="month" class="bym" data-i="' + i + '" value="' + bym + '" onchange="nwBymChange(' + i + ')"></div>'
      : '';
    const e = nwRowExpected(r); // 이 계약의 항목별 예상액(회색 참고 placeholder)
    h += '<tr class="row-' + r.confidence + (r.dup ? ' duprow' : '') + '">' +
      '<td><input type="checkbox" class="chk" data-i="' + i + '" ' + (r.include ? 'checked' : '') + ' onchange="nwUpdateSummary()"></td>' +
      '<td>' + r.date + bymBlock + '</td>' +
      '<td>' + r.counterparty + '</td>' +
      '<td class="num">' + nwMoney(r.deposit) + '</td>' +
      '<td>' + nwSelCell(i, r.contract_id) + '</td>' +
      nwMoneyCell(i, 'rent', r.rent_fee, e.rent) +
      nwMoneyCell(i, 'mnt', r.maintenance_fee, e.mnt) +
      nwMoneyCell(i, 'park', r.parking_fee || 0, e.park) +
      nwMoneyCell(i, 'water', r.water_fee || 0, e.wat) +
      nwMoneyCell(i, 'elec', r.electric_fee || 0, e.elec) +
      '<td><span class="conf conf-' + r.confidence + '">' + (confLabel[r.confidence] || r.confidence) + '</span>' + reason + dupTag +
        '<div class="mism" id="mism-' + i + '" style="display:none;"></div>' +
        '<div class="recon" id="recon-' + i + '" style="display:none;"></div></td>' +
      '</tr>';
  });
  document.getElementById('nwReview').innerHTML = h || '<tr><td colspan="11" style="text-align:center;color:#a0aec0;padding:24px;">새로 등록할 입금내역이 없습니다 (이미등록·자동제외 항목 제외)</td></tr>';
  // 계약 예상액 불일치 안내 초기 렌더
  list.forEach(({ i }) => nwRenderMism(i));
  nwUpdateSummary();
}

// 검토표의 현재 편집상태(계약·금액·귀속월·체크)를 NW_ROWS에 반영 — 재렌더 전에 사용자 편집 보존
function nwSyncRows(){
  NW_ROWS.forEach((r, i) => {
    const chk = document.querySelector('.chk[data-i="' + i + '"]');
    if (!chk) return; // 미렌더(스킵·이미등록) 행은 유지
    r.include = chk.checked;
    const cid = nwSelVal(i);
    r.contract_id = cid ? Number(cid) : null;
    const g = f => { const el = document.querySelector('input[data-f="' + f + '"][data-i="' + i + '"]'); return el ? nwNum(el.value) : 0; };
    r.rent_fee = g('rent'); r.maintenance_fee = g('mnt'); r.parking_fee = g('park'); r.water_fee = g('water'); r.electric_fee = g('elec');
    const bm = document.querySelector('.bym[data-i="' + i + '"]');
    if (bm && /^\d{4}-\d{2}$/.test(bm.value)) r.bill_ym = bm.value;
  });
}
// 귀속월 변경 → 편집 보존 후 재렌더(헤더의 개월수·받을금액 갱신)
function nwBymChange(i){
  nwSyncRows();
  nwRenderReview();
}

// 호실 그룹 헤더 행: 각 열(월세·관리비·주차·수도·전기)에 정렬된 '받아야 할 금액' + 항목별 입금/미납 배경색
//   받을 금액 = 대표계약 정액(월세·관리비·주차) + 대표 귀속월의 공과금 부과액(수도·전기)
function nwRoomHead(room, cnt, agg){
  if (!agg) return '<tr class="grp-head"><td colspan="11">🏠 ' + room + '호 · ' + cnt + '건</td></tr>';
  let repCid = null, bestC = -1;                       // 대표 계약 = 가장 많이 매칭된 계약
  for (const cid in agg.con) { if (agg.con[cid] > bestC) { bestC = agg.con[cid]; repCid = cid; } }
  const c = NW_CAND.find(x => String(x.contract_id) === String(repCid));
  // ★귀속월(밀린 관리비 등) 반영: 정액은 개월수만큼, 공과금은 귀속월별 부과액 합산
  const months = Object.keys(agg.ym || {});
  const mc = months.length || 1;
  let utWat = 0, utElec = 0, hasCharge = false;
  months.forEach(ym => { const cc = NW_CHARGES[room + '|' + ym]; if (cc) { utWat += cc.water || 0; utElec += cc.electric || 0; hasCharge = true; } });
  const exp = { rent: (c ? c.rent_fee : 0) * mc, mnt: (c ? c.maintenance_fee : 0) * mc, park: (c ? (c.parking_fee || 0) : 0) * mc, wat: utWat, elec: utElec };
  const dep = { rent: agg.rent, mnt: agg.mnt, park: agg.park, wat: agg.wat, elec: agg.elec };
  const expTot = exp.rent + exp.mnt + exp.park + exp.wat + exp.elec;
  const diff = agg.dep - expTot;
  const cell = k => {                                  // 셀: 받을 금액 표시 + 납부상태 배경
    const e = exp[k], d = dep[k];
    let cls, txt;
    if (e > 0) { cls = (d >= e) ? 'gh-paid' : 'gh-due'; txt = nwMoney(e); }   // 받을 것 있음: 입금완료 초록 / 미납 빨강
    else if (d > 0) { cls = 'gh-over'; txt = nwMoney(d); }                    // 부과 없는데 입금됨: 주황
    else { cls = 'gh-none'; txt = '-'; }
    return '<td class="' + cls + '">' + txt + '</td>';
  };
  let sum = '🏠 ' + room + '호 · ' + cnt + '건' + (mc > 1 ? ' · <span style="color:#6b46c1;font-weight:700;">' + mc + '개월분</span>' : ''), rowCls = '';
  if (expTot > 0) {
    const badge = diff === 0 ? '<span class="gh-ok">✓ 완납</span>' : (diff < 0 ? '<span class="gh-short">미납 ' + nwMoney(-diff) + '</span>' : '<span class="gh-extra">초과 +' + nwMoney(diff) + '</span>');
    sum += ' · 받을 금액 <b>' + nwMoney(expTot) + '</b>원 · 입금 <b>' + nwMoney(agg.dep) + '</b>원 ' + badge + (hasCharge ? '' : ' <span style="color:#a0aec0;">(공과금 미부과)</span>');
    rowCls = (diff >= 0) ? ' gh-rowok' : ' gh-rowbad';
  }
  return '<tr class="grp-head' + rowCls + '"><td colspan="5">' + sum + '</td>' + cell('rent') + cell('mnt') + cell('park') + cell('wat') + cell('elec') + '<td></td></tr>';
}

// 분할 규칙(서버와 동일): 관리비=계약 정액 고정, 월세=입금액−관리비.
//   전세(월세=0)·관리비명칭 입금은 전액을 관리비로.
// 공과금 키워드 판별(서버 matchBankRows와 동일): '' | 'elec' | 'water' | 'util'(합산)
function nwUtilKind(cp){
  const s = String(cp || '');
  const e = s.indexOf('전기') >= 0, w = s.indexOf('수도') >= 0, u = s.indexOf('공과') >= 0;
  if (u || (e && w)) return 'util';
  if (e) return 'elec';
  if (w) return 'water';
  return '';
}
function nwSplit(dep, c, cp, row){
  const kind = (row && row.util_kind) || nwUtilKind(cp); // 서버가 판정한 공과금 종류(추정 포함) 우선
  if (kind === 'elec')  return { pr: 0, pm: 0, pk: 0, pw: 0, pe: dep };
  if (kind === 'water') return { pr: 0, pm: 0, pk: 0, pw: dep, pe: 0 };
  if (kind === 'util') { // 합산 공과금 → 서버가 준 수도:전기 비율 유지, 없으면 전기로(합계 유지)
    const sw = row ? (row.water_fee || 0) : 0, se = row ? (row.electric_fee || 0) : 0;
    if (sw + se > 0) { const pw = Math.round(dep * sw / (sw + se)); return { pr: 0, pm: 0, pk: 0, pw: pw, pe: dep - pw }; }
    return { pr: 0, pm: 0, pk: 0, pw: 0, pe: dep };
  }
  if (!c) return { pr: dep, pm: 0, pk: 0, pw: 0, pe: 0 };
  const rent = c.rent_fee || 0, mnt = c.maintenance_fee || 0, park = c.parking_fee || 0;
  // ★정액과 정확히 일치하면 전액 그 항목(월세·관리비 별도 입금 대응)
  if (rent > 0 && dep === rent) return { pr: dep, pm: 0, pk: 0, pw: 0, pe: 0 };
  if (mnt > 0 && dep === mnt)   return { pr: 0, pm: dep, pk: 0, pw: 0, pe: 0 };
  if (park > 0 && dep === park) return { pr: 0, pm: 0, pk: dep, pw: 0, pe: 0 };
  const pk = Math.min(park, dep); // 주차비 정액 우선 차감
  const rem = dep - pk;
  if ((cp && cp.indexOf('관리비') >= 0) || rent === 0) return { pr: 0, pm: rem, pk: pk, pw: 0, pe: 0 };
  const pm = Math.min(mnt, rem);
  return { pr: rem - pm, pm: pm, pk: pk, pw: 0, pe: 0 };
}

// 계약 선택이 바뀌면 그 계약 기준으로 분할 재계산
function nwRowRecalc(i){
  const cid = nwSelVal(i);
  const c = NW_CAND.find(x => String(x.contract_id) === String(cid));
  const rentEl = document.querySelector('input[data-f="rent"][data-i="' + i + '"]');
  const mntEl  = document.querySelector('input[data-f="mnt"][data-i="' + i + '"]');
  const parkEl = document.querySelector('input[data-f="park"][data-i="' + i + '"]');
  const watEl  = document.querySelector('input[data-f="water"][data-i="' + i + '"]');
  const elcEl  = document.querySelector('input[data-f="elec"][data-i="' + i + '"]');
  // placeholder = 그 계약의 항목별 예상액(회색 참고). 값 = 입금 분할(미매칭 수동배정은 빈칸으로 두고 참고만).
  const ref = NW_ROWS[i].confidence === 'none';
  const e = nwExpectedOf(c, NW_ROWS[i].bill_ym || NW_ROWS[i].date.slice(0, 7));
  const put = (el, sv, ev) => { if (!el) return; el.placeholder = ev > 0 ? nwMoney(ev) : ''; el.value = ref ? '' : (sv > 0 ? nwMoney(sv) : ''); };
  if (c || NW_ROWS[i].util_kind || nwUtilKind(NW_ROWS[i].counterparty)) {
    const s = nwSplit(NW_ROWS[i].deposit, c, NW_ROWS[i].counterparty, NW_ROWS[i]);
    put(rentEl, s.pr, e.rent); put(mntEl, s.pm, e.mnt); put(parkEl, s.pk, e.park); put(watEl, s.pw, e.wat); put(elcEl, s.pe, e.elec);
  } else {
    [rentEl, mntEl, parkEl, watEl, elcEl].forEach(el => { if (el) el.placeholder = ''; });
  }
  const chk = document.querySelector('.chk[data-i="' + i + '"]');
  if (chk && cid && !ref) chk.checked = true; // 미매칭은 참고값뿐이므로 자동 체크 안 함(금액 입력 후 체크)
  nwRenderMism(i);
  nwRowReconcile(i);
  nwUpdateSummary();
}

// 계약 예상 총액과 입금액이 다르면 안내(미납/초과/월세변동 포착)
function nwRenderMism(i){
  const box = document.getElementById('mism-' + i);
  if (!box) return;
  const c = NW_CAND.find(x => String(x.contract_id) === String(nwSelVal(i)));
  const dep = NW_ROWS[i].deposit;
  if (c && !(NW_ROWS[i].util_kind || nwUtilKind(NW_ROWS[i].counterparty))) {
    const exp = c.rent_fee + c.maintenance_fee + (c.parking_fee || 0); // 전세는 관리비(+주차비)
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
  const parkEl = document.querySelector('input[data-f="park"][data-i="' + i + '"]');
  const watEl  = document.querySelector('input[data-f="water"][data-i="' + i + '"]');
  const elcEl  = document.querySelector('input[data-f="elec"][data-i="' + i + '"]');
  const box = document.getElementById('recon-' + i);
  if (!rentEl || !mntEl || !box) return;
  const sum = nwNum(rentEl.value) + nwNum(mntEl.value) + (parkEl ? nwNum(parkEl.value) : 0) + (watEl ? nwNum(watEl.value) : 0) + (elcEl ? nwNum(elcEl.value) : 0);
  const dep = NW_ROWS[i].deposit;
  const bad = sum !== dep;
  rentEl.classList.toggle('bad', bad);
  mntEl.classList.toggle('bad', bad);
  if (parkEl) parkEl.classList.toggle('bad', bad);
  if (watEl) watEl.classList.toggle('bad', bad);
  if (elcEl) elcEl.classList.toggle('bad', bad);
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
      const parkI = document.querySelector('input[data-f="park"][data-i="' + i + '"]');
      const watI  = document.querySelector('input[data-f="water"][data-i="' + i + '"]');
      const elcI  = document.querySelector('input[data-f="elec"][data-i="' + i + '"]');
      amt += rent + mnt + (parkI ? nwNum(parkI.value) : 0) + (watI ? nwNum(watI.value) : 0) + (elcI ? nwNum(elcI.value) : 0);
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
    const cid = nwSelVal(i);
    if (!cid) return;
    items.push({
      contract_id: cid,
      pay_date: NW_ROWS[i].date,
      bill_ym: NW_ROWS[i].bill_ym || NW_ROWS[i].date.slice(0, 7),
      rent_fee: nwNum(document.querySelector('input[data-f="rent"][data-i="' + i + '"]').value),
      maintenance_fee: nwNum(document.querySelector('input[data-f="mnt"][data-i="' + i + '"]').value),
      parking_fee: nwNum(document.querySelector('input[data-f="park"][data-i="' + i + '"]').value),
      water_fee: nwNum(document.querySelector('input[data-f="water"][data-i="' + i + '"]').value),
      electric_fee: nwNum(document.querySelector('input[data-f="elec"][data-i="' + i + '"]').value),
      txn_at: NW_ROWS[i].datetime || '', // 은행 거래일시(유니크·중복판정)
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

// ==========================================================
// 공과금(수도·전기) 관리 — 건물 단위 월별 일괄 입력 + 과거 내역
// ==========================================================
function nw_page_utility(PDO $pdo): void {
    $nw = new Nw($pdo);
    $nw->ensureTable();
    $buildingId = (int)($_GET['building_id'] ?? 0);
    $b = $nw->getBuilding($buildingId);
    if (!$b) { nw_head('건물 없음'); echo "<p>건물을 찾을 수 없습니다.</p>"; nw_foot(); return; }

    $units  = $nw->listUnits($buildingId);
    $active = $nw->listActiveContractsByBuilding($buildingId); // [unit_id => 활성계약]
    $history  = $nw->utilityChargeMonths($buildingId); // 최신순
    $ym = (string)($_GET['ym'] ?? '');
    // 청구월 미지정 시: 데이터 있는 최신 청구월로, 없으면 이번 달
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = ($history[0]['bill_ym'] ?? '') ?: date('Y-m');
    $cfg      = $nw->getUtilityConfig($buildingId, $ym);
    $readings = $nw->utilityReadingsForBuildingMonth($buildingId, $ym); // [unit_id => row]
    $prev     = $nw->prevReadingsForBuilding($buildingId, $ym);         // [unit_id => [elec,cold,hot]]

    $locked = !empty($readings); // 등록된 청구월 → 읽기전용(수정모드 전환 필요)
    $byYear = [];
    foreach ($history as $h) { $byYear[substr((string)$h['bill_ym'], 0, 4)][] = (string)$h['bill_ym']; }
    krsort($byYear);
    $curYear = substr($ym, 0, 4);

    $sumW = 0; $sumE = 0;
    foreach ($readings as $r) { $sumW += (int)$r['water_fee']; $sumE += (int)$r['electric_fee']; }
    $cfv = function ($v) { return rtrim(rtrim(number_format((float)$v, 3, '.', ','), '0'), '.'); }; // 2160→2,160 · 93.300→93.3

    nw_head('공과금 관리 — ' . $b['name']);
?>
<style>
.ut-cfg{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:12px 14px;margin-bottom:14px;box-shadow:0 1px 3px rgba(0,0,0,.05);}
.ut-cfg-row{display:flex;flex-wrap:wrap;gap:10px;align-items:stretch;}
.cfg-group{border:1.5px dashed #a9b6c5;border-radius:12px;padding:7px 10px 8px;background:#f6f9fc;}
.cfg-group-hd{font-size:11px;font-weight:700;color:#5a7089;margin:0 0 6px 2px;}
.cfg-group-hd span{font-weight:400;color:#9aa8b8;}
.cfg-group-body{display:flex;flex-wrap:wrap;gap:10px;align-items:stretch;}
.cfg-box{display:flex;align-items:stretch;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;background:#fff;}
.cfg-tag{display:flex;align-items:center;font-weight:800;font-size:14px;padding:0 14px;white-space:nowrap;}
.cfg-fields{display:flex;gap:10px;align-items:center;padding:7px 12px;}
.cfg-fields > div{text-align:right;}
.cfg-box.ym .cfg-tag{background:#4a5568;color:#fff;}
.cfg-box.wat .cfg-tag{background:#2471a3;color:#fff;}
.cfg-box.elc .cfg-tag{background:#b9770e;color:#fff;}
.cfg-box.sum{border-color:#2c3e50;}
.cfg-box.sum .cfg-tag{background:#f1c40f;color:#2c3e50;}
.cfg-box.sum .cfg-fields{background:#2c3e50;color:#fff;align-items:center;font-size:13px;}
.cfg-box.sum .cfg-fields label{color:#cbd5e0;}
.cfg-box.sum b{display:block;color:#fff;font-size:15px;}
.ut-cfg-row label{display:block;font-size:11px;font-weight:700;color:#718096;margin-bottom:4px;}
.ut-cfg-row input[type=month]{padding:7px 10px;border:1px solid #cbd5e0;border-radius:8px;font-size:14px;}
.ut-cfg-row input.cfgin{width:64px;padding:6px 6px;border:1px solid #cbd5e0;border-radius:8px;font-size:13px;text-align:right;}
.cfg-fields{gap:8px;padding:6px 10px;}
.cfg-sep{align-self:center;font-weight:700;color:#2b6cb0;font-size:12px;background:#eef6ff;padding:3px 9px;border-radius:10px;}
.cfg-sep.elc{color:#b9770e;background:#fef5e7;}
.cfg-note{font-size:11px;color:#a0aec0;margin-top:8px;}
.ut-sumbar{margin-left:auto;font-size:13px;color:#4a5568;align-self:center;}
.ut-sumbar b{color:#2b6cb0;font-size:15px;}
table.ut-table{width:100%;table-layout:fixed;border-collapse:collapse;font-size:13px;background:#fff;white-space:nowrap;}
table.ut-table th,table.ut-table td{padding:6px 4px;border-bottom:1px solid #edf2f7;text-align:right;}
table.ut-table th{background:#f7fafc;color:#4a5568;font-weight:600;font-size:12px;position:sticky;top:0;}
table.ut-table th.rm,table.ut-table td.rm{text-align:left;}
table.ut-table td.calc{color:#2b6cb0;font-weight:700;font-variant-numeric:tabular-nums;}
table.ut-table td.use{color:#718096;font-variant-numeric:tabular-nums;}
table.ut-table input.ug{width:100%;box-sizing:border-box;padding:4px 4px;border:1px solid #cbd5e0;border-radius:6px;text-align:right;font-variant-numeric:tabular-nums;}
/* 직전 검침값: 평소엔 텍스트처럼(테두리 없음·회색), 클릭(포커스)하면 편집 가능 — 현재값과 구분 */
table.ut-table input.ug-prev{border-color:transparent;background:transparent;color:#8a97a6;cursor:pointer;}
table.ut-table input.ug-prev:hover{background:#f0f4f8;}
table.ut-table input.ug-prev:focus{border-color:#90cdf4;background:#fff;color:#2d3748;cursor:text;}
/* 현재 검침값: 입력하는 칸이므로 뚜렷한 박스로 강조(직전 텍스트와 구분) */
table.ut-table input.ug-cur{border-color:#7fa8d0;background:#f4f9ff;font-weight:600;color:#1f2d3d;}
/* 전기(요금)그룹과 냉수 사이 구분선 */
table.ut-table th.edge,table.ut-table td.edge{border-right:2px solid #94a3b8;}
table.ut-table input.ugd{width:100%;box-sizing:border-box;padding:4px 2px;border:1px solid #cbd5e0;border-radius:6px;font-size:11px;}
table.ut-table col.grp-e{background:#f7fbff;}
table.ut-table col.grp-w{background:#fbfdf9;}
table.ut-table tfoot td{font-weight:700;background:#f7fafc;position:sticky;bottom:0;}
.ut-scroll{overflow-x:auto;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.05);}
.ut-hist{margin-top:22px;}
.ut-hist h3{font-size:15px;color:#2d3748;margin:0 0 10px;}
table.ut-hist-t{width:100%;border-collapse:collapse;font-size:13px;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);}
table.ut-hist-t th,table.ut-hist-t td{padding:8px 12px;border-bottom:1px solid #edf2f7;text-align:left;}
table.ut-hist-t th{background:#f7fafc;color:#4a5568;font-weight:600;}
table.ut-hist-t td.num,table.ut-hist-t th.num{text-align:right;font-variant-numeric:tabular-nums;}
table.ut-hist-t tr.cur{background:#eef6ff;}
table.ut-hist-t tr.click{cursor:pointer;}
table.ut-hist-t tr.click:hover{background:#f0f6fc;}
.ut-chips{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:12px 14px;margin-bottom:14px;box-shadow:0 1px 3px rgba(0,0,0,.05);}
.uc-years{display:flex;flex-wrap:wrap;gap:7px;}
.uc-year{padding:6px 14px;border:1px solid #cbd5e0;border-radius:16px;background:#fff;cursor:pointer;font-weight:700;font-size:14px;color:#4a5568;}
.uc-year:hover{background:#f7fafc;}
.uc-year.on{background:#2d3748;border-color:#2d3748;color:#fff;}
.uc-months{display:flex;flex-wrap:wrap;gap:6px;margin-top:9px;}
.uc-month{padding:5px 13px;border:1px solid #cbd5e0;border-radius:14px;background:#fff;color:#2b6cb0;font-weight:600;font-size:13px;text-decoration:none;}
.uc-month:hover{background:#eef6ff;}
.uc-month.on{background:#2b6cb0;border-color:#2b6cb0;color:#fff;}
/* 읽기전용(보기) — 박스 없이 텍스트만 */
table.ut-table input.ug[readonly],table.ut-table input.ugd[readonly],.ut-cfg input[readonly]{border:none;background:transparent;color:#2d3748;cursor:default;padding-left:0;padding-right:0;}
table.ut-table input.ugd[readonly]::-webkit-calendar-picker-indicator{display:none;}
table.ut-table tr.ov td{background:#ffe0b2;}
table.ut-table tr.ov input.ug,table.ut-table tr.ov input.ugd{background:#fff6ec;border-color:#f6ad55;}
.ut-ov-n{font-size:10px;color:#c05621;font-weight:800;white-space:nowrap;margin-top:2px;}
/* 보기(읽기전용) 화면의 미검침 행: 검침일 직전의 'N개월 미검침'만 남기고 값·박스 모두 숨김 */
.ut-table.ut-view tr.ov input{display:none;}
.ut-table.ut-view tr.ov td.use,.ut-table.ut-view tr.ov td.calc{font-size:0;}
table.ut-table th.wtcol,table.ut-table td.wtcol{padding-left:2px;padding-right:2px;}
table.ut-table input.r-wt{width:100%;box-sizing:border-box;border:none;background:transparent;text-align:center;font-weight:800;color:#2b6cb0;font-size:13px;padding:2px 0;font-variant-numeric:tabular-nums;}
table.ut-table input.r-wt:focus{outline:none;background:#fff;border:1px solid #90cdf4;border-radius:5px;}
table.ut-table input.r-wt[readonly]{color:#4a5568;}
</style>

<div class="page-head">
  <div>
    <a class="back-link" href="/nw/index.php?mode=building&id=<?= $buildingId ?>">← <?= nw_h($b['name']) ?></a>
    <h1 style="margin-top:6px;">💡 공과금 관리</h1>
    <div class="sub">청구월 검침값(전기·냉수·온수)을 입력하면 사용량·요금이 자동 계산됩니다. 직전 검침은 지난달 값이 자동 표시됩니다.</div>
  </div>
</div>

<?php if ($history): ?>
<div class="ut-chips">
  <div class="uc-years">
    <?php foreach (array_keys($byYear) as $y): ?>
    <span class="uc-year<?= (string)$y === $curYear ? ' on' : '' ?>" data-y="<?= $y ?>" onclick="utYear('<?= $y ?>')"><?= $y ?>년</span>
    <?php endforeach; ?>
  </div>
  <?php foreach ($byYear as $y => $months): ?>
  <div class="uc-months" data-year="<?= $y ?>" style="display:<?= (string)$y === $curYear ? 'flex' : 'none' ?>;">
    <?php foreach ($months as $m): ?>
    <a class="uc-month<?= $m === $ym ? ' on' : '' ?>" href="/nw/index.php?mode=utility&building_id=<?= $buildingId ?>&ym=<?= $m ?>"><?= (int)substr($m, 5, 2) ?>월</a>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="ut-cfg">
  <div class="ut-cfg-row">
    <div class="cfg-box ym"><span class="cfg-tag">청구월</span><div class="cfg-fields"><input type="month" id="uym" value="<?= nw_h($ym) ?>" onchange="nwGoYm(this.value)"></div></div>
    <div class="cfg-group">
      <div class="cfg-group-hd">💡 요금 상수 <span>· [저장]하면 이 청구월에 적용</span></div>
      <div class="cfg-group-body">
        <div class="cfg-box wat"><span class="cfg-tag">수도</span><div class="cfg-fields">
          <div><label>기본요금</label><input class="cfgin" id="c_wb" value="<?= $cfv($cfg['water_base']) ?>" oninput="calcAll()"></div>
          <div><label>단위당</label><input class="cfgin" id="c_wu" value="<?= $cfv($cfg['water_unit']) ?>" oninput="calcAll()"></div>
        </div></div>
        <div class="cfg-box elc"><span class="cfg-tag">전기</span><div class="cfg-fields">
          <div><label>TV료</label><input class="cfgin" id="c_et" value="<?= $cfv($cfg['elec_tv']) ?>" oninput="calcAll()"></div>
          <div><label>기본료</label><input class="cfgin" id="c_eb" value="<?= $cfv($cfg['elec_base']) ?>" oninput="calcAll()"></div>
          <div><label>단위당</label><input class="cfgin" id="c_eu" value="<?= $cfv($cfg['elec_unit']) ?>" oninput="calcAll()"></div>
        </div></div>
        <button id="ut-cfg-save" class="btn btn-primary" style="padding:6px 18px;align-self:center;" onclick="nwSaveCfg()">저장</button>
      </div>
    </div>
    <div class="cfg-box sum"><span class="cfg-tag">합계</span><div class="cfg-fields">
      <div><label>수도요금</label><b id="ut-sw"><?= nw_money($sumW) ?></b></div>
      <div><label>전기요금</label><b id="ut-se"><?= nw_money($sumE) ?></b></div>
    </div></div>
  </div>
  <div class="cfg-note">수도 = 절사( 기본 + (냉수+온수)사용량 × 단위당 × 1.1 ) · 전기 = 절사( (TV+기본)×개월 + 전기사용량 × 단위당 × 1.1 )</div>
</div>

<div class="ut-scroll">
<table class="ut-table">
  <colgroup><col style="width:46px"><col style="width:74px"><col style="width:92px"><col style="width:92px"><col class="grp-e" style="width:62px"><col class="grp-e" style="width:62px"><col class="grp-e" style="width:52px"><col class="grp-e edge" style="width:72px"><col class="grp-w" style="width:56px"><col class="grp-w" style="width:56px"><col class="grp-w" style="width:56px"><col class="grp-w" style="width:56px"><col style="width:52px"><col style="width:72px"><col style="width:44px"></colgroup>
  <thead><tr>
    <th class="rm">호실</th><th class="rm edge">세입자</th>
    <th>검침일<br>직전</th><th class="edge">검침일<br>청구월</th>
    <th>전기<br>직전</th><th>전기<br>현재</th><th>전기<br>사용량</th><th class="edge">전기요금</th>
    <th>냉수<br>직전</th><th>냉수<br>현재</th><th>온수<br>직전</th><th>온수<br>현재</th>
    <th>수도<br>사용량</th><th class="edge">수도요금</th><th class="wtcol">개월</th>
  </tr></thead>
  <tbody>
    <?php foreach ($units as $u):
      $uid = (int)$u['id']; $r = $readings[$uid] ?? null; $pv = $prev[$uid] ?? null;
      $ep = $r ? (int)$r['elec_prev'] : ($pv ? $pv['elec'] : '');
      $ec = $r ? (int)$r['elec_cur']  : '';
      $cp = $r ? (int)$r['cold_prev'] : ($pv ? $pv['cold'] : '');
      $cc = $r ? (int)$r['cold_cur']  : '';
      $hp = $r ? (int)$r['hot_prev']  : ($pv ? $pv['hot'] : '');
      $hc = $r ? (int)$r['hot_cur']   : '';
      $wf = $r ? (int)$r['water_fee'] : 0;
      $ef = $r ? (int)$r['electric_fee'] : 0;
      $euse = $r ? max(0, (int)$r['elec_cur'] - (int)$r['elec_prev']) : '';
      $wuse = $r ? max(0, ((int)$r['cold_cur'] + (int)$r['hot_cur']) - ((int)$r['cold_prev'] + (int)$r['hot_prev'])) : '';
      $t = $active[$uid]['tenant_name'] ?? '';
      $rpd = ($r && $r['read_prev_date']) ? substr((string)$r['read_prev_date'], 0, 10) : (($pv && !empty($pv['cdate'])) ? substr((string)$pv['cdate'], 0, 10) : '');
      $rcd = ($r && $r['read_cur_date']) ? substr((string)$r['read_cur_date'], 0, 10) : '';
      // 미검침(이월): 이번 청구월에 값을 안 넣어 직전값을 그대로 끌어온 경우 → 검침일 직전==현재
      $wt = ($r && (int)$r['bill_weight'] > 0) ? (int)$r['bill_weight'] : 2;
      // 미검침 판정 — 등록/신규 공통: 이번 달 '실제 검침'(검침일 존재 + 직전≠현재)이 없으면 밀린 것
      $stale = false; $lastActual = '';
      if ($r) {
          $hasReading = !empty($r['read_cur_date']) && (string)$r['read_cur_date'] !== (string)$r['read_prev_date'];
          $stale = !$hasReading;
          $lastActual = !empty($r['read_cur_date']) ? (string)$r['read_cur_date'] : (string)$r['read_prev_date'];
      } elseif ($pv) {
          // 직전값은 '실제검침(0 초과) 최신'에서 가져옴. 그 이후 미입력/이월 기록이 있거나(밀림), 최신이 이월(직전==현재)이면 미검침
          $laterBlank = !empty($pv['maxym']) && (string)$pv['maxym'] > (string)($pv['from'] ?? '');
          $carry = !empty($pv['cdate']) && (string)$pv['cdate'] === (string)($pv['pdate'] ?? '');
          $stale = $laterBlank || $carry;
          $lastActual = (string)($pv['cdate'] ?? '');
      }
      $overdue = 0;
      if ($stale && $lastActual !== '') { $rcYm = substr($lastActual, 0, 7); $overdue = ((int)substr($ym, 0, 4) * 12 + (int)substr($ym, 5, 2)) - ((int)substr($rcYm, 0, 4) * 12 + (int)substr($rcYm, 5, 2)); }
    ?>
    <tr data-uid="<?= $uid ?>" data-wfee="<?= $wf ?>" data-efee="<?= $ef ?>"<?= $stale ? ' class="ov"' : '' ?>>
      <td class="rm"><b><?= nw_h($u['room_no']) ?></b>호</td>
      <td class="rm edge"><?= $t !== '' ? nw_h($t) : '<span style="color:#cbd5e0;">공실</span>' ?></td>
      <td><input type="date" class="ugd r-pd" value="<?= $rpd ?>"><?php if ($stale): ?><div class="ut-ov-n" title="최종 검침 <?= nw_h($lastActual) ?> · 이후 미입력">⚠ <?= $overdue > 0 ? $overdue . '개월 ' : '' ?>미검침</div><?php elseif (!$r && !empty($pv['from'])): ?><div style="font-size:10px;color:#a0aec0;margin-top:2px;">↖ <?= substr((string)$pv['from'], 2, 5) ?> 검침</div><?php endif; ?></td>
      <td class="edge"><input type="date" class="ugd r-cd" value="<?= $rcd ?>"></td>
      <td><input class="ug ug-prev r-ep" inputmode="numeric" value="<?= $ep === '' ? '' : number_format($ep) ?>" oninput="nwFmtMoney(this);calcAll()"></td>
      <td><input class="ug ug-cur r-ec" inputmode="numeric" value="<?= $ec === '' ? '' : number_format($ec) ?>" oninput="nwFmtMoney(this);calcAll()"></td>
      <td class="use r-euse"><?= $euse === '' ? '-' : number_format($euse) ?></td>
      <td class="calc r-efee edge"><?= $stale ? '' : ($ef ? number_format($ef) : '-') ?></td>
      <td><input class="ug ug-prev r-cp" inputmode="numeric" value="<?= $cp === '' ? '' : number_format($cp) ?>" oninput="nwFmtMoney(this);calcAll()"></td>
      <td><input class="ug ug-cur r-cc" inputmode="numeric" value="<?= $cc === '' ? '' : number_format($cc) ?>" oninput="nwFmtMoney(this);calcAll()"></td>
      <td><input class="ug ug-prev r-hp" inputmode="numeric" value="<?= $hp === '' ? '' : number_format($hp) ?>" oninput="nwFmtMoney(this);calcAll()"></td>
      <td><input class="ug ug-cur r-hc" inputmode="numeric" value="<?= $hc === '' ? '' : number_format($hc) ?>" oninput="nwFmtMoney(this);calcAll()"></td>
      <td class="use r-wuse"><?= $wuse === '' ? '-' : number_format($wuse) ?></td>
      <td class="calc r-wfee edge"><?= $stale ? '' : ($wf ? number_format($wf) : '-') ?></td>
      <td class="wtcol"><input class="r-wt" inputmode="numeric" value="<?= $stale ? '' : $wt ?>" title="청구 개월수(전기 기본료 가중치). ↑/↓ 키로 조정" oninput="calcAll()" onkeydown="wtKey(event,this)"></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$units): ?><tr><td colspan="15" style="text-align:center;color:#bdc3c7;padding:20px;">호실이 없습니다</td></tr><?php endif; ?>
  </tbody>
  <tfoot><tr><td class="rm" colspan="7">합계</td><td class="calc edge" id="ft-e"><?= nw_money($sumE) ?></td><td colspan="5"></td><td class="calc edge" id="ft-w"><?= nw_money($sumW) ?></td><td></td></tr></tfoot>
</table>
</div>

<div style="margin-top:16px;display:flex;justify-content:flex-end;gap:10px;align-items:center;">
  <span id="ut-calc-note" style="color:#2f855a;font-size:13px;"></span>
  <button id="ut-edit-btn" class="btn btn-outline" style="display:none;" onclick="utEditMode()">✏️ 수정 모드</button>
  <span id="ut-save-wrap" style="display:flex;gap:10px;">
    <button class="btn btn-outline" onclick="calcAll(true)">🧮 계산</button>
    <button class="btn btn-primary" onclick="nwSaveUtil()">💾 검침·요금 전체 저장</button>
  </span>
</div>

<div class="ut-hist">
  <h3>📜 지난 공과금 내역</h3>
  <?php if ($history): ?>
  <table class="ut-hist-t">
    <thead><tr><th>청구월</th><th class="num">수도 합계</th><th class="num">전기 합계</th><th class="num">계</th><th class="num">호실</th></tr></thead>
    <tbody>
      <?php foreach ($history as $h): $hym = (string)$h['bill_ym']; $tot = (int)$h['water'] + (int)$h['electric']; ?>
      <tr class="click <?= $hym === $ym ? 'cur' : '' ?>" onclick="nwGoYm('<?= nw_h($hym) ?>')">
        <td><b><?= nw_h($hym) ?></b></td>
        <td class="num"><?= nw_money($h['water']) ?></td>
        <td class="num"><?= nw_money($h['electric']) ?></td>
        <td class="num"><b><?= nw_money($tot) ?></b></td>
        <td class="num"><?= (int)$h['units'] ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p style="color:#a0aec0;">아직 입력된 공과금 내역이 없습니다.</p>
  <?php endif; ?>
</div>

<script>
const NW_UBID = <?= $buildingId ?>;
function nwGoYm(v){ if (v && /^\d{4}-\d{2}$/.test(v)) location.href = '/nw/index.php?mode=utility&building_id=' + NW_UBID + '&ym=' + v; }
function readCfg(){
  const n = id => Number((document.getElementById(id).value || '').replace(/[^0-9.]/g, '')) || 0;
  return { water_base: n('c_wb'), water_unit: n('c_wu'), elec_tv: n('c_et'), elec_base: n('c_eb'), elec_unit: n('c_eu') };
}
// 개월 칸: ↑/↓ 키로 ±1
function wtKey(e, el){
  if (el.readOnly) return;
  if (e.key === 'ArrowUp' || e.key === 'ArrowDown'){
    e.preventDefault();
    el.value = Math.max(1, (parseInt(el.value, 10) || 2) + (e.key === 'ArrowUp' ? 1 : -1));
    calcAll();
  }
}
function calcFees(eUse, wUse, cfg, wt){
  const water = Math.floor((cfg.water_base + wUse * cfg.water_unit * 1.1) / 10) * 10;                              // 절사(10원)
  const elec  = Math.floor(((cfg.elec_tv + cfg.elec_base) * (wt || 2) + eUse * cfg.elec_unit * 1.1) / 10) * 10;    // 기본료×개월수 + 사용량. 절사(10원)
  return { water: Math.max(0, water), elec: Math.max(0, elec) };
}
function calcAll(flash){
  const cfg = readCfg();
  let sw = 0, se = 0;
  document.querySelectorAll('tr[data-uid]').forEach(tr => {
    const wtEl = tr.querySelector('.r-wt');
    const g = c => Number((tr.querySelector('.' + c).value || '').replace(/[^0-9]/g, '')) || 0;
    const ep = g('r-ep'), ec = g('r-ec'), cp = g('r-cp'), cc = g('r-cc'), hp = g('r-hp'), hc = g('r-hc');
    const hasE = tr.querySelector('.r-ec').value !== '';
    const hasW = tr.querySelector('.r-cc').value !== '' || tr.querySelector('.r-hc').value !== '';
    // 미검침(주황 행)이면서 이번 달 실제 검침(0 초과 & 직전과 다름)이 없으면 → 사용량·요금·개월 공란, 합계 제외
    const validReading = hasE && ec > 0 && ec !== ep;
    if (tr.classList.contains('ov') && !validReading) {
      tr.querySelector('.r-euse').textContent = '-';
      tr.querySelector('.r-wuse').textContent = '-';
      tr.querySelector('.r-wfee').textContent = '';
      tr.querySelector('.r-efee').textContent = '';
      wtEl.value = '';
      tr.dataset.wfee = 0; tr.dataset.efee = 0;
      return;
    }
    if (wtEl.value === '') wtEl.value = 2;
    const eUse = Math.max(0, ec - ep), wUse = Math.max(0, (cc + hc) - (cp + hp));
    const wt = Math.max(1, Number(wtEl.value.replace(/[^0-9]/g, '')) || 2);
    const f = calcFees(eUse, wUse, cfg, wt);
    tr.querySelector('.r-euse').textContent = hasE ? eUse.toLocaleString('en-US') : '-';
    tr.querySelector('.r-wuse').textContent = hasW ? wUse.toLocaleString('en-US') : '-';
    tr.querySelector('.r-wfee').textContent = hasW ? f.water.toLocaleString('en-US') : '-';
    tr.querySelector('.r-efee').textContent = hasE ? f.elec.toLocaleString('en-US') : '-';
    tr.dataset.wfee = hasW ? f.water : 0;
    tr.dataset.efee = hasE ? f.elec : 0;
    sw += hasW ? f.water : 0; se += hasE ? f.elec : 0;
  });
  document.getElementById('ut-sw').textContent = sw.toLocaleString('en-US');
  document.getElementById('ut-se').textContent = se.toLocaleString('en-US');
  document.getElementById('ft-w').textContent = sw.toLocaleString('en-US');
  document.getElementById('ft-e').textContent = se.toLocaleString('en-US');
  if (flash === true) document.getElementById('ut-calc-note').textContent = '계산 완료 — 확인 후 저장하세요.';
}
function nwSaveCfg(){
  const ym = document.getElementById('uym').value;
  if (!/^\d{4}-\d{2}$/.test(ym)) { alert('청구월을 선택하세요.'); return; }
  nwApi('payment', 'saveConfig', Object.assign({ building_id: NW_UBID, bill_ym: ym }, readCfg()))
    .then(() => { alert('요금 상수를 저장했습니다.'); calcAll(true); });
}
function nwSaveUtil(){
  const ym = document.getElementById('uym').value;
  if (!/^\d{4}-\d{2}$/.test(ym)) { alert('청구월을 선택하세요.'); return; }
  calcAll(); // 저장 전 최신 계산
  const rows = [];
  document.querySelectorAll('tr[data-uid]').forEach(tr => {
    const g = c => Number((tr.querySelector('.' + c).value || '').replace(/[^0-9]/g, '')) || 0;
    rows.push({ unit_id: tr.dataset.uid, elec_prev: g('r-ep'), elec_cur: g('r-ec'), cold_prev: g('r-cp'), cold_cur: g('r-cc'), hot_prev: g('r-hp'), hot_cur: g('r-hc'), read_prev_date: tr.querySelector('.r-pd').value, read_cur_date: tr.querySelector('.r-cd').value, bill_weight: Math.max(1, Number((tr.querySelector('.r-wt').value || '').replace(/[^0-9]/g, '')) || 2), water_fee: tr.dataset.wfee || 0, electric_fee: tr.dataset.efee || 0 });
  });
  if (!confirm(ym + ' 검침·요금을 ' + rows.length + '개 호실에 저장할까요?')) return;
  nwApi('payment', 'saveConfig', Object.assign({ building_id: NW_UBID, bill_ym: ym }, readCfg()))
    .then(() => nwApi('payment', 'bulkReadings', { building_id: NW_UBID, bill_ym: ym, rows: JSON.stringify(rows) }))
    .then(j => { alert(j.count + '개 호실 저장 완료.'); location.reload(); });
}
const UT_REGISTERED = <?= $locked ? 'true' : 'false' ?>;
function utYear(y){
  document.querySelectorAll('.uc-year').forEach(e => e.classList.toggle('on', e.dataset.y === y));
  document.querySelectorAll('.uc-months').forEach(e => e.style.display = e.dataset.year === y ? 'flex' : 'none');
}
function utSetLock(lock){
  document.querySelectorAll('.ug, .ugd, .cfgin, .r-wt').forEach(i => i.readOnly = lock);
  const tb = document.querySelector('.ut-table'); if (tb) tb.classList.toggle('ut-view', lock);
  document.getElementById('ut-edit-btn').style.display = lock ? '' : 'none';
  document.getElementById('ut-save-wrap').style.display = lock ? 'none' : 'flex';
  const cs = document.getElementById('ut-cfg-save'); if (cs) cs.style.display = lock ? 'none' : '';
}
function utEditMode(){
  if (!confirm('이 청구월은 이미 등록된 내역입니다. 수정 모드로 전환할까요?')) return;
  utSetLock(false);
  document.getElementById('ut-calc-note').textContent = '✏️ 수정 모드 — 계산 후 저장하세요.';
}
calcAll(); // 초기 표시 정렬
if (UT_REGISTERED) { utSetLock(true); document.getElementById('ut-calc-note').textContent = '📋 등록된 내역 (읽기전용)'; }
</script>
<?php
    nw_foot();
}
?>
