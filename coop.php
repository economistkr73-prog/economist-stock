<?php
// coop.php — 공제조합 회원관리 시스템 (전국 폐기물 수집·운반 업체 지도)
//   타겟 고객(운영 중 비조합원)을 지도에서 즉시 식별하는 것이 핵심.
//   데이터: waste_companies  /  API: waste_api.php(module=waste)  /  DB: WasteCompany.class
//   지도/마커/정보창 패턴은 places.php 를 그대로 따른다(HTML 아이콘 마커 + 클릭 패널).
require_once "./env/cnt.inc";
require_once "./env/auth_fnc.php";
require_once "./env/coop_nav.inc";   // 공제조합 전용 헤더 (메인 nav 와 독립)
require_login();

if (file_exists("./env/maps.inc")) require_once "./env/maps.inc";
$naverClientId = defined('NAVER_MAPS_CLIENT_ID') ? NAVER_MAPS_CLIENT_ID : '';
$current_user  = $_SESSION['usr_name'] ?? '';
$GLOBALS['page_title'] = '전국지도';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>전국지도 · 공제조합</title>
<?php coop_nav_css(); ?>
<style>
*, *::before, *::after { box-sizing: border-box; }
html, body { margin:0; height:100%; font-family:"Malgun Gothic","맑은 고딕",sans-serif; color:#222; }
body { display:flex; flex-direction:column; height:100vh; overflow:hidden; }

/* 통계 카드 */
#wc-stats { display:flex; gap:8px; padding:8px 12px; background:#f4f6f8; border-bottom:1px solid #dde3e8; overflow-x:auto; flex-shrink:0; }
.wc-card { background:#fff; border:1px solid #dde3e8; border-radius:8px; padding:6px 12px; min-width:84px; text-align:center; white-space:nowrap; }
.wc-card .v { font-size:20px; font-weight:800; line-height:1.1; }
.wc-card .l { font-size:11px; color:#667; margin-top:2px; }
.wc-card.g-coop .v    { color:#2e6fdb; }
.wc-card.g-noncoop .v { color:#27ae60; }
.wc-card.g-nonmem .v  { color:#f39c12; }
.wc-card.g-inactive .v{ color:#9aa6b2; }

/* 필터 바 */
#wc-filter { display:flex; flex-wrap:wrap; gap:6px; align-items:center; padding:8px 12px; background:#fff; border-bottom:1px solid #e2e7ec; flex-shrink:0; }
.wc-chip { border:1px solid #c8d0d8; background:#fff; color:#445; border-radius:16px; padding:5px 13px; font-size:13px; font-weight:600; cursor:pointer; }
.wc-chip.active { background:#2c3e50; color:#fff; border-color:#2c3e50; }
.wc-chip.c-direct.active { background:#7a8794; border-color:#7a8794; }
#wc-filter select { border:1px solid #c8d0d8; border-radius:6px; padding:5px 8px; font-size:13px; background:#fff; }
#wc-filter .sep { width:1px; height:22px; background:#e0e5ea; margin:0 2px; }
#wc-filter .count { margin-left:auto; font-size:13px; color:#556; font-weight:700; }
/* 보기 모드 토글 */
.wc-mode { border:1px solid #c8d0d8; background:#fff; color:#445; padding:5px 13px; font-size:13px; font-weight:700; cursor:pointer; }
.wc-mode:first-child { border-radius:16px 0 0 16px; }
.wc-mode:last-child  { border-radius:0 16px 16px 0; }
.wc-mode + .wc-mode  { border-left:0; }
.wc-mode.active { background:#2c3e50; color:#fff; border-color:#2c3e50; }
/* 지역 색칠 범례 */
#wc-rlegend { position:absolute; left:12px; bottom:12px; background:rgba(255,255,255,.95); border:1px solid #d5dbe1; border-radius:8px; padding:8px 11px; font-size:12px; box-shadow:0 2px 8px rgba(0,0,0,.12); z-index:5; display:none; }
#wc-rlegend .grad { width:160px; height:12px; border-radius:3px; background:linear-gradient(90deg,#232323,#21409a,#1457d6); }
#wc-rlegend .lbls { display:flex; justify-content:space-between; font-size:10px; color:#667; margin-top:2px; }
#wc-rlegend .row { display:flex; align-items:center; gap:6px; margin-top:5px; }
#wc-rlegend .dot { width:13px; height:13px; border-radius:3px; border:1px solid #c3c9cf; }
#wc-loading { position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); background:rgba(44,62,80,.9); color:#fff; padding:10px 18px; border-radius:8px; font-size:14px; z-index:30; display:none; }
/* 결과 요약 막대그래프 박스 (2배 크기 · 드래그 이동) */
#wc-summary { position:absolute; top:16px; right:16px; width:440px; max-width:92vw; background:rgba(255,255,255,.97); border:1px solid #d5dbe1; border-radius:12px; padding:20px 24px; box-shadow:0 5px 18px rgba(0,0,0,.2); z-index:7; cursor:move; user-select:none; touch-action:none; }
#wc-summary .sm-title { font-weight:800; font-size:28px; color:#222; line-height:1.15; }
#wc-summary .sm-sub { font-size:15px; color:#778; margin:2px 0 14px; }
#wc-summary .sm-row { margin:14px 0; }
#wc-summary .sm-row .lab { display:flex; justify-content:space-between; align-items:baseline; font-size:21px; font-weight:700; margin-bottom:6px; }
#wc-summary .sm-row .pct { font-weight:800; font-size:23px; }
#wc-summary .sm-bar { height:28px; background:#eef1f4; border-radius:14px; overflow:hidden; }
#wc-summary .sm-bar > i { display:block; height:100%; border-radius:14px; transition:width .25s; }
/* 요약 박스 하단 색인(범례) */
#wc-summary .sm-legend { margin-top:18px; padding-top:14px; border-top:1px solid #e3e8ed; display:flex; flex-wrap:wrap; gap:8px 16px; }
#wc-summary .sm-legend .lg-row { display:flex; align-items:center; gap:7px; font-size:15px; font-weight:600; color:#445; }
#wc-summary .sm-legend .lg-dot { width:15px; height:15px; border-radius:50%; border:2px solid #fff; box-shadow:0 0 0 1px rgba(0,0,0,.2); }
#wc-summary .sm-legend .lg-flag { display:inline-flex; width:20px; justify-content:center; line-height:0; }
/* 요약 박스 하단 기업 매트릭스 (시·군·구 선택 시) — 회원/비회원 × 조합원/비조합원 */
#wc-summary .sm-matrix { margin-top:16px; padding-top:14px; border-top:1px solid #e3e8ed; }
#wc-summary .sm-matrix table { width:100%; border-collapse:collapse; table-layout:fixed; }
#wc-summary .sm-matrix th, #wc-summary .sm-matrix td { border:1px solid #dfe4e9; padding:6px 8px; vertical-align:top; }
#wc-summary .sm-matrix thead th, #wc-summary .sm-matrix tbody th { background:#f4f6f8; font-weight:800; text-align:center; color:#334; white-space:nowrap; }
#wc-summary .sm-matrix tbody th { vertical-align:middle; }
#wc-summary .sm-matrix .mx-corner { background:#eef1f4; }
#wc-summary .sm-matrix col.mx-rh { width:78px; }
#wc-summary .sm-matrix .col-coop    { color:#2e6fdb; }
#wc-summary .sm-matrix .col-noncoop { color:#27ae60; }
#wc-summary .sm-matrix .mx-cnt { font-size:11px; font-weight:700; color:#889; margin-left:3px; }
#wc-summary .sm-matrix .mx-co { font-size:13px; font-weight:600; color:#223; padding:1px 0; word-break:break-all; }
#wc-summary .sm-matrix .mx-none { color:#aab; font-size:13px; }
/* 헤더 색인 글리프 */
#wc-summary .sm-matrix .mx-dot  { display:inline-block; width:11px; height:11px; border-radius:50%; vertical-align:middle; margin-right:4px; border:1.5px solid #fff; box-shadow:0 0 0 1px rgba(0,0,0,.25); }
#wc-summary .sm-matrix .mx-circ { display:inline-block; width:11px; height:11px; border-radius:50%; vertical-align:middle; margin-right:4px; background:#fff; border:1.5px solid #9aa6b2; }
#wc-summary .sm-matrix .mx-flag { display:inline-block; vertical-align:middle; margin-right:3px; line-height:0; }
@media (max-width:768px){ #wc-summary{ width:300px; top:10px; right:10px; padding:13px 16px; } #wc-summary .sm-title{font-size:21px;} #wc-summary .sm-row .lab{font-size:16px;} #wc-summary .sm-row .pct{font-size:18px;} #wc-summary .sm-bar{height:20px;} #wc-summary .sm-legend{margin-top:12px;padding-top:10px;gap:5px 12px;} #wc-summary .sm-legend .lg-row{font-size:12px;} #wc-summary .sm-matrix{margin-top:12px;padding-top:10px;} #wc-summary .sm-matrix th, #wc-summary .sm-matrix td{padding:4px 6px;} #wc-summary .sm-matrix .mx-co{font-size:11px;} #wc-summary .sm-matrix col.mx-rh{width:48px;} }

/* 지도 + 패널 */
#wc-main { position:relative; flex:1; min-height:0; overflow:hidden; }
/* 네이버 SDK가 #map 의 position 을 relative 로 바꿔도 부모를 꽉 채우도록 명시적 width/height
   (inset:0 은 position:relative 가 되면 무시되어 높이 0 으로 붕괴) */
#map { position:absolute; top:0; left:0; width:100%; height:100%; background:#e9eef2; }
#wc-nokey { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:#789; font-size:15px; text-align:center; padding:20px; }

/* 범례 */
#wc-legend { position:absolute; left:12px; bottom:12px; background:rgba(255,255,255,.95); border:1px solid #d5dbe1; border-radius:8px; padding:8px 11px; font-size:12px; box-shadow:0 2px 8px rgba(0,0,0,.12); z-index:5; }
#wc-legend .row { display:flex; align-items:center; gap:6px; margin:2px 0; }
#wc-legend .row.indent { padding-left:12px; }
#wc-legend .lg-grp { font-weight:800; color:#445; font-size:11px; margin-top:5px; }
#wc-legend .lgflag { display:inline-flex; width:14px; justify-content:center; line-height:0; }
#wc-legend .dot { width:13px; height:13px; border-radius:50%; border:2px solid #fff; box-shadow:0 0 0 1px rgba(0,0,0,.2); }

/* 마커 */
.wc-mk { border-radius:50%; border:2px solid #fff; box-shadow:0 1px 4px rgba(0,0,0,.4); cursor:pointer; }
/* 마커 옆 회사명 라벨 (시·군·구 선택 시) — 절대위치라 앵커(원 중심) 불변 */
.wc-label { position:absolute; left:100%; margin-left:6px; top:50%; transform:translateY(-50%); white-space:nowrap; background:#fff; border:1px solid rgba(0,0,0,.25); border-radius:7px; padding:7px 14px; font-size:17px; line-height:1.2; font-weight:800; color:#111; box-shadow:0 2px 7px rgba(0,0,0,.4); pointer-events:none; }

/* 상세 패널 */
#wc-panel { position:absolute; top:0; right:0; width:330px; max-width:86vw; height:100%; background:#fff; border-left:1px solid #d8dee4; box-shadow:-4px 0 16px rgba(0,0,0,.12); transform:translateX(100%); transition:transform .22s; z-index:20; overflow-y:auto; }
#wc-panel.open { transform:none; }
#wc-panel .hd { display:flex; justify-content:space-between; align-items:flex-start; padding:14px 16px 8px; }
#wc-panel .name { font-size:18px; font-weight:800; }
#wc-panel .x { background:none; border:none; font-size:24px; line-height:1; cursor:pointer; color:#889; }
#wc-panel .badges { padding:0 16px 8px; display:flex; flex-wrap:wrap; gap:5px; }
.wc-badge { font-size:11px; font-weight:700; border-radius:12px; padding:2px 9px; }
.b-member  { background:#e8f0fe; color:#2e6fdb; }
.b-nonmem  { background:#fef0db; color:#d98012; }
.b-coop    { background:#e8f0fe; color:#2e6fdb; }
.b-noncoop { background:#e9f7ef; color:#1e9e54; }
.b-direct  { background:#eef1f4; color:#6b7884; }
.b-active  { background:#fff7e6; color:#b8860b; }
#wc-panel .meta { padding:6px 16px 18px; font-size:13.5px; line-height:1.7; color:#334; }
#wc-panel .meta b { display:inline-block; width:62px; color:#778; font-weight:600; }
</style>
</head>
<body>
<?php render_coop_nav('map'); ?>

<div id="wc-stats"><!-- 통계 카드 (JS) --></div>

<div id="wc-filter">
  <span class="wc-modes">
    <button id="mode-marker" class="wc-mode active" onclick="wcSetMode('marker')">개별 마커</button>
    <button id="mode-member" class="wc-mode" onclick="wcSetMode('member')">회원/비회원</button>
    <button id="mode-coop"   class="wc-mode" onclick="wcSetMode('coop')">조합원/비조합원</button>
  </span>
  <span class="sep"></span>
  <button class="wc-chip c-member"    data-chip="member"    onclick="wcChip('member')">회원</button>
  <button class="wc-chip c-nonmember" data-chip="nonmember" onclick="wcChip('nonmember')">비회원</button>
  <span class="sep"></span>
  <button class="wc-chip c-coop"      data-chip="coop"      onclick="wcChip('coop')">조합원</button>
  <button class="wc-chip c-noncoop"   data-chip="noncoop"   onclick="wcChip('noncoop')">비조합원</button>
  <span class="sep"></span>
  <button class="wc-chip c-direct"    data-chip="direct"    onclick="wcChip('direct')">지점(미운영)</button>
  <span class="sep"></span>
  <select id="wc-sido"  onchange="wcSidoChange()"><option value="">시·도 전체</option></select>
  <select id="wc-sigungu" onchange="wcApply()"><option value="">시·군·구 전체</option></select>
  <select id="wc-waste" onchange="wcApply()"><option value="">폐기물 전체</option></select>
  <button class="wc-chip" onclick="wcReset()">초기화</button>
  <span class="count" id="wc-count"></span>
</div>

<div id="wc-main">
  <div id="map"></div>
  <?php if ($naverClientId === ''): ?>
  <div id="wc-nokey">네이버 지도 키(NAVER_MAPS_CLIENT_ID)가 설정되지 않아 지도를 표시할 수 없습니다.<br>env/maps.inc 를 확인하세요.</div>
  <?php endif; ?>
  <div id="wc-rlegend">
    <div id="wc-rlegend-title" style="font-weight:800;font-size:11px;margin-bottom:4px">회원 비율 (회원수÷업체수)</div>
    <div class="grad"></div>
    <div class="lbls"><span>0%</span><span>25%</span><span>50%+</span></div>
    <div class="row"><span class="dot" style="background:#dfe3e7"></span> 업체 없음</div>
  </div>
  <div id="wc-loading">지역 경계 불러오는 중…</div>
  <div id="wc-summary"></div>
  <div id="wc-panel">
    <div class="hd"><div class="name" id="wp-name"></div><button class="x" onclick="wcPanelClose()">&times;</button></div>
    <div class="badges" id="wp-badges"></div>
    <div class="meta" id="wp-meta"></div>
  </div>
</div>

<script>
var WC = { all: [], markers: [], map: null, info: null, sel: [], mode: 'marker', polygons: [], regionGeo: null };
var WC_NAVER_KEY = <?= json_encode($naverClientId) ?>;

// ── 데이터 로드 ─────────────────────────────────────────────
function wcBoot() {
  fetch('waste_api.php?module=waste&action=bootstrap')
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d || !d.ok) { alert('데이터 로드 실패'); return; }
      WC.all = d.rows || [];
      wcFillSido(d.sido || []);
      wcFillWaste(d.waste_types || []);
      if (WC_NAVER_KEY) wcLoadNaver(); else wcApply();
    })
    .catch(function () { alert('데이터 로드 오류'); });
}

function wcFillSido(list) {
  var sel = document.getElementById('wc-sido');
  list.forEach(function (s) {
    var o = document.createElement('option');
    o.value = s.sido; o.textContent = s.sido + ' (' + s.cnt + ')';
    sel.appendChild(o);
  });
}
function wcFillWaste(list) {
  var sel = document.getElementById('wc-waste');
  list.forEach(function (w) {
    var o = document.createElement('option');
    o.value = w.waste_type; o.textContent = w.waste_type + ' (' + w.cnt + ')';
    sel.appendChild(o);
  });
}
function wcSidoChange() {
  // 선택 시도에 속한 시군구로 2단계 드롭다운 갱신
  var sido = document.getElementById('wc-sido').value;
  var sel = document.getElementById('wc-sigungu');
  sel.innerHTML = '<option value="">시·군·구 전체</option>';
  if (sido) {
    var seen = {};
    WC.all.forEach(function (r) {
      if (r.sido === sido && r.sigungu && !seen[r.sigungu]) {
        seen[r.sigungu] = 1;
        var o = document.createElement('option');
        o.value = r.sigungu; o.textContent = r.sigungu;
        sel.appendChild(o);
      }
    });
  }
  wcApply();
}

// ── 네이버 지도 로더 (places.php plLoadNaver 패턴) ───────────
function wcLoadNaver() {
  if (window.naver && window.naver.maps) { wcInitMap(); return; }
  var s = document.createElement('script');
  s.src = 'https://oapi.map.naver.com/openapi/v3/maps.js?ncpKeyId=' + encodeURIComponent(WC_NAVER_KEY);
  s.onload = wcInitMap;
  s.onerror = function () { document.getElementById('map').innerHTML = '<div id="wc-nokey">지도 로드 실패</div>'; };
  document.head.appendChild(s);
}
function wcInitMap() {
  WC.map = new naver.maps.Map('map', {
    center: new naver.maps.LatLng(36.5, 127.8),
    zoom: 7,
    mapDataControl: false
  });
  WC.info = new naver.maps.InfoWindow({ anchorSkew: true, borderWidth: 0, backgroundColor: 'transparent', disableAnchor: true, pixelOffset: new naver.maps.Point(0, -6) });
  wcApply();
}

// ── 필터 칩 ──
//   회원/비회원만 상호배타. 조합원/비조합원은 동시 선택 가능(둘 다 = 조합 조건 없음).
//   지점은 독립 토글. 개수 제한 없음.
var WC_OPP = { member: 'nonmember', nonmember: 'member' };
function wcChip(chip) {
  var i = WC.sel.indexOf(chip);
  if (i >= 0) { WC.sel.splice(i, 1); }
  else {
    var opp = WC_OPP[chip];
    if (opp) { var oi = WC.sel.indexOf(opp); if (oi >= 0) WC.sel.splice(oi, 1); }
    WC.sel.push(chip);
  }
  document.querySelectorAll('.wc-chip[data-chip]').forEach(function (b) {
    b.classList.toggle('active', WC.sel.indexOf(b.getAttribute('data-chip')) >= 0);
  });
  wcApply();
}
function wcReset() {
  WC.sel = [];
  document.querySelectorAll('.wc-chip[data-chip]').forEach(function (b) { b.classList.remove('active'); });
  document.getElementById('wc-sido').value = '';
  document.getElementById('wc-sigungu').innerHTML = '<option value="">시·군·구 전체</option>';
  document.getElementById('wc-waste').value = '';
  wcApply();
}

// ── 필터 적용 → 마커 + 통계 갱신 ────────────────────────────
function wcMatch(r) {
  var s = WC.sel;
  if (s.indexOf('member') >= 0    && r.is_member !== 1) return false;
  if (s.indexOf('nonmember') >= 0 && r.is_member !== 0) return false;
  // 조합원/비조합원: 둘 다 선택 시 조합 조건 없음(전부), 하나만 선택 시 그 그룹만
  var selC = s.indexOf('coop') >= 0, selNC = s.indexOf('noncoop') >= 0;
  if (selC && !selNC && r.is_coop !== 1) return false;
  if (selNC && !selC && r.is_coop !== 0) return false;
  if (s.indexOf('direct') >= 0    && r.is_active !== 0) return false;
  var sido = document.getElementById('wc-sido').value;
  var sgg  = document.getElementById('wc-sigungu').value;
  var wt   = document.getElementById('wc-waste').value;
  if (sido && r.sido !== sido) return false;
  if (sgg && r.sigungu !== sgg) return false;
  if (wt && r.waste_type !== wt) return false;
  return true;
}
function wcApply() {
  var rows = WC.all.filter(wcMatch);
  wcRenderStats(rows);
  document.getElementById('wc-count').textContent = '표시 ' + rows.length + '곳';
  if (WC.mode === 'marker' && WC.map) {
    wcRenderMarkers(rows);
  } else if (wcIsRegion() && WC.map && WC.regionGeo) {
    wcRenderRegions();
    // 색칠 모드에서도 칩(회원/비회원·조합원/비조합원·지점)을 선택하면
    // 해당 업체 마커를 지역색 위에 오버레이 (칩 없으면 색칠만)
    if (WC.sel.length > 0) wcRenderMarkers(rows, true);
    else wcClearMarkers();
  }
  wcRenderSummary();
}
function wcIsRegion() { return WC.mode === 'member' || WC.mode === 'coop'; }

// ── 결과 요약 막대그래프 (선택 지역/필터 반영) ──────────────
function wcSummaryRows() {
  if (wcIsRegion()) {
    var selSido = document.getElementById('wc-sido').value;
    var selSgg = document.getElementById('wc-sigungu').value;
    var selCity = selSgg ? wcCanon(selSido, selSgg) : '';
    return WC.all.filter(function (r) {
      if (selSido && r.sido !== selSido) return false;
      if (selCity && wcCanon(r.sido, r.sigungu) !== selCity) return false;
      return true;
    });
  }
  return WC.all.filter(wcMatch);
}
function wcBar(label, val, total, color) {
  var pct = total ? (val / total * 100) : 0;
  return '<div class="sm-row"><div class="lab"><span>' + label
    + ' <span style="color:#889;font-weight:600">' + val + '</span></span>'
    + '<span class="pct" style="color:' + color + '">' + pct.toFixed(1) + '%</span></div>'
    + '<div class="sm-bar"><i style="width:' + pct.toFixed(1) + '%;background:' + color + '"></i></div></div>';
}
function wcRenderSummary() {
  var rows = wcSummaryRows();
  var total = rows.length, mem = 0, co = 0;
  rows.forEach(function (r) { if (r.is_member === 1) mem++; if (r.is_coop === 1) co++; });
  var selSido = document.getElementById('wc-sido').value;
  var selSgg = document.getElementById('wc-sigungu').value;
  var scope = selSgg ? (selSido + ' ' + selSgg) : (selSido || '전국');
  var html = '<div class="sm-title">' + scope + '</div>';
  html += '<div class="sm-sub">전체 ' + total + '개 업체</div>';
  if (WC.mode === 'coop') {
    html += wcBar('조합원', co, total, '#2e6fdb');
    html += wcBar('비조합원', total - co, total, '#27ae60');
  } else {
    html += wcBar('회원', mem, total, '#2e6fdb');
    html += wcBar('비회원', total - mem, total, '#f39c12');
  }
  // 시·군·구 선택 시: 기업 매트릭스(회원/비회원 × 조합원/비조합원), 그 외: 마커 색인
  if (document.getElementById('wc-sigungu').value) html += wcMatrixHtml(rows);
  else html += wcLegendHtml();
  document.getElementById('wc-summary').innerHTML = html;
}
// 기업 매트릭스 — 행=회원/비회원, 열=조합원/비조합원, 칸=해당 기업명 목록
function wcMatrixHtml(rows) {
  var cells = { '1_1': [], '1_0': [], '0_1': [], '0_0': [] };
  rows.forEach(function (r) {
    var k = (r.is_member ? 1 : 0) + '_' + (r.is_coop ? 1 : 0);
    if (cells[k]) cells[k].push(r.company_name || '(무명)');
  });
  function cell(k) {
    var arr = cells[k];
    if (!arr.length) return '<span class="mx-none">–</span>';
    return arr.map(function (n) { return '<div class="mx-co">' + wcEsc(n) + '</div>'; }).join('');
  }
  var FLAG = '<svg width="13" height="15" viewBox="0 0 13 15">'
    + '<line x1="3" y1="14" x2="3" y2="2" stroke="#666" stroke-width="1.6" stroke-linecap="round"/>'
    + '<path d="M3 2 L12 4.5 L3 7 Z" fill="#e74c3c"/></svg>';
  return '<div class="sm-matrix"><table>'
    + '<colgroup><col class="mx-rh"><col><col></colgroup>'
    + '<thead><tr><th class="mx-corner"></th>'
    + '<th class="col-coop"><span class="mx-flag">' + FLAG + '</span>조합원<span class="mx-cnt">' + (cells['1_1'].length + cells['0_1'].length) + '</span></th>'
    + '<th class="col-noncoop"><span class="mx-circ"></span>비조합원<span class="mx-cnt">' + (cells['1_0'].length + cells['0_0'].length) + '</span></th></tr></thead>'
    + '<tbody>'
    + '<tr><th><span class="mx-dot" style="background:#2e6fdb"></span>회원</th><td>' + cell('1_1') + '</td><td>' + cell('1_0') + '</td></tr>'
    + '<tr><th><span class="mx-dot" style="background:#222"></span>비회원</th><td>' + cell('0_1') + '</td><td>' + cell('0_0') + '</td></tr>'
    + '</tbody></table></div>';
}
// 마커 색인(범례) — 요약 박스 하단에 표시
function wcFlagSvg(circ, flag) {
  return '<svg width="20" height="20" viewBox="0 0 20 20">'
    + '<circle cx="10" cy="10" r="8.5" fill="' + circ + '" stroke="#fff" stroke-width="1.4"/>'
    + '<line x1="8" y1="15" x2="8" y2="5" stroke="#fff" stroke-width="1.3"/>'
    + '<path d="M8 5 L15 7 L8 9 Z" fill="' + flag + '"/></svg>';
}
function wcLegendHtml() {
  return '<div class="sm-legend">'
    + '<div class="lg-row"><span class="lg-dot" style="background:#2e6fdb"></span>회원</div>'
    + '<div class="lg-row"><span class="lg-dot" style="background:#222"></span>비회원</div>'
    + '<div class="lg-row"><span class="lg-flag">' + wcFlagSvg('#2e6fdb', '#e74c3c') + '</span>조합원(회원)</div>'
    + '<div class="lg-row"><span class="lg-flag">' + wcFlagSvg('#222', '#f1c40f') + '</span>조합원(비회원)</div>'
    + '<div class="lg-row"><span class="lg-dot" style="background:#9aa6b2;width:11px;height:11px"></span>지점/미운영</div>'
    + '</div>';
}

// ── 보기 모드 토글 (개별 마커 / 회원·비회원 / 조합원·비조합원) ──
function wcSetMode(m) {
  if (WC.mode === m) return;
  WC.mode = m;
  ['marker', 'member', 'coop'].forEach(function (x) {
    document.getElementById('mode-' + x).classList.toggle('active', x === m);
  });
  var region = wcIsRegion();
  document.getElementById('wc-rlegend').style.display = region ? 'block' : 'none';
  if (region) {
    document.getElementById('wc-rlegend-title').textContent =
      (m === 'member') ? '회원 비율 (회원수÷업체수)' : '조합원 비율 (조합원수÷업체수)';
  }
  wcPanelClose();
  if (region) {
    wcClearMarkers();
    wcLoadRegions(wcApply);
  } else {
    wcClearRegions();
    wcApply();
  }
}

// ── 지역 단계구분도 (회원 비율) ─────────────────────────────
var WC_SIDO = { '11':'서울','21':'부산','22':'대구','23':'인천','24':'광주','25':'대전','26':'울산','29':'세종','31':'경기','32':'강원','33':'충북','34':'충남','35':'전북','36':'전남','37':'경북','38':'경남','39':'제주' };
function wcSidoFromCode(code) { return WC_SIDO[String(code).slice(0, 2)] || ''; }
// 일반시 분할구("수원시장안구") → 시("수원시"). 특별·광역시 자치구("강남구")는 그대로.
function wcCityOf(name) { return /시.+구$/.test(name) ? name.slice(0, name.indexOf('시') + 1) : name; }
// 경계파일(2018)·데이터 간 이름 차이 보정 (양쪽에 동일 적용 → 키 일치)
function wcCanon(sido, name) {
  if (sido === '인천' && name === '남구') return '미추홀구';   // 2018 경계=남구 → 현행 미추홀구
  if (sido === '부산' && name === '진구') return '부산진구';   // 데이터 약칭 보정
  return name;
}

function wcLoadRegions(cb) {
  if (WC.regionGeo) { cb(); return; }
  var el = document.getElementById('wc-loading');
  el.style.display = 'block';
  fetch('coop_regions.json')
    .then(function (r) { return r.json(); })
    .then(function (g) { WC.regionGeo = g; el.style.display = 'none'; cb(); })
    .catch(function () { el.textContent = '지역 경계 로드 실패'; });
}

function wcRegionAgg() {
  var agg = {};
  WC.all.forEach(function (r) {
    var k = r.sido + '|' + wcCanon(r.sido, r.sigungu);
    if (!agg[k]) agg[k] = { total: 0, member: 0, coop: 0 };
    agg[k].total++;
    if (r.is_member === 1) agg[k].member++;
    if (r.is_coop === 1) agg[k].coop++;
  });
  return agg;
}
function wcRegionColor(a, metric) {
  if (!a || a.total === 0) return { fill: '#dfe3e7', op: 0.4 };   // 업체 없음
  var t = Math.min(1, (a[metric] / a.total) / 0.5);              // 50%+ = 진파랑
  var c0 = [35, 35, 35], c1 = [20, 87, 214];                      // 0%=검정 → 파랑
  var c = c0.map(function (v, i) { return Math.round(v + (c1[i] - v) * t); });
  return { fill: 'rgb(' + c[0] + ',' + c[1] + ',' + c[2] + ')', op: 0.78 };
}
function wcClearRegions() {
  WC.polygons.forEach(function (p) { p.setMap(null); });
  WC.polygons = [];
}
function wcRenderRegions() {
  if (!WC.regionGeo || !WC.map) return;
  wcClearRegions();
  var agg = wcRegionAgg();
  // 시·도 / 시·군·구 드롭다운 선택 시 그 지역만 색칠 + 확대
  var selSido = document.getElementById('wc-sido').value;
  var selSgg  = document.getElementById('wc-sigungu').value;
  var selCity = selSgg ? wcCanon(selSido, selSgg) : '';
  var bounds = null;
  WC.regionGeo.features.forEach(function (f) {
    var sido = wcSidoFromCode(f.properties.code);
    if (selSido && sido !== selSido) return;
    var city = wcCanon(sido, wcCityOf(f.properties.name));
    if (selCity && city !== selCity) return;
    var a = agg[sido + '|' + city];
    var col = wcRegionColor(a, WC.mode);
    var polys = f.geometry.type === 'Polygon' ? [f.geometry.coordinates] : f.geometry.coordinates;
    polys.forEach(function (poly) {
      var path = poly[0].map(function (pt) { return new naver.maps.LatLng(pt[1], pt[0]); });
      var pg = new naver.maps.Polygon({
        map: WC.map, paths: [path],
        fillColor: col.fill, fillOpacity: col.op,
        strokeColor: '#ffffff', strokeWeight: 1, strokeOpacity: 0.7
      });
      naver.maps.Event.addListener(pg, 'click', function () { wcRegionClick(sido, city, a); });
      WC.polygons.push(pg);
      if (selSido) path.forEach(function (ll) { if (!bounds) bounds = new naver.maps.LatLngBounds(ll, ll); else bounds.extend(ll); });
    });
  });
  if (selSido && bounds) WC.map.fitBounds(bounds);
  else { WC.map.setCenter(new naver.maps.LatLng(36.3, 127.8)); WC.map.setZoom(7); }
}
function wcRegionClick(sido, city, a) {
  document.getElementById('wp-name').textContent = sido + ' ' + city;
  document.getElementById('wp-badges').innerHTML = '';
  var t = a ? a.total : 0, mem = a ? a.member : 0, co = a ? a.coop : 0;
  var num = (WC.mode === 'member') ? mem : co;
  var ratio = t ? Math.round(num / t * 100) : 0;
  var m = [];
  m.push('<div><b>업체수</b>' + t + '</div>');
  m.push('<div><b>회원</b>' + mem + ' <span style="color:#889">(비회원 ' + (t - mem) + ')</span></div>');
  m.push('<div><b>조합원</b>' + co + ' <span style="color:#889">(비조합원 ' + (t - co) + ')</span></div>');
  m.push('<div><b>' + (WC.mode === 'member' ? '회원비율' : '조합원비율') + '</b>' + ratio + '%</div>');
  if (!a || t === 0) m.push('<div style="color:#889;margin-top:6px">등록 업체 없음</div>');
  document.getElementById('wp-meta').innerHTML = m.join('');
  document.getElementById('wc-panel').classList.add('open');
}

function wcRenderStats(rows) {
  // 회원/비회원 중심 집계 (발표 1단계: 전체 → 회원 vs 비회원 → 회원 내 조합원/비조합원)
  var st = { total: rows.length, member: 0, nonmember: 0, mcoop: 0, mnoncoop: 0, inactive: 0 };
  rows.forEach(function (r) {
    if (r.is_member === 1) {
      st.member++;
      if (r.is_coop === 1) st.mcoop++; else st.mnoncoop++;
    } else {
      st.nonmember++;
    }
    if (r.is_active === 0) st.inactive++;
  });
  var cards = [
    ['', st.total, '전체'],
    ['g-coop', st.member, '회원'],
    ['g-nonmem', st.nonmember, '비회원'],
    ['g-coop', st.mcoop, '└ 조합원'],
    ['g-noncoop', st.mnoncoop, '└ 비조합원'],
    ['g-inactive', st.inactive, '지점/미운영']
  ];
  document.getElementById('wc-stats').innerHTML = cards.map(function (c) {
    return '<div class="wc-card ' + c[0] + '"><div class="v">' + c[1] + '</div><div class="l">' + c[2] + '</div></div>';
  }).join('');
}

// ── 마커: 업체 고유 그룹별 고정 색 (필터와 무관), 크기 통일 ──
//   회원·조합원=파랑 / 회원·비조합원=초록 / 비회원=주황 / 지점·미운영=회색
var WC_COLOR = { coop: '#2e6fdb', noncoop: '#27ae60', nonmember: '#f39c12', inactive: '#9aa6b2' };
function wcGroup(r) {
  if (r.is_active === 0) return 'inactive';
  if (r.is_member === 0) return 'nonmember';
  return r.is_coop === 1 ? 'coop' : 'noncoop';
}
function wcIcon(r, showLabel) {
  var label = (showLabel && r.company_name)
    ? '<span class="wc-label">' + wcEsc(r.company_name) + '</span>' : '';
  // 지점/미운영 → 작은 회색 원
  if (r.is_active === 0) {
    return {
      content: '<div style="position:relative;width:16px;height:16px;line-height:0">'
        + '<div class="wc-mk" style="width:16px;height:16px;background:#9aa6b2"></div>' + label + '</div>',
      anchor: new naver.maps.Point(8, 8), zIndex: 10
    };
  }
  // 회원=파란 원 / 비회원=검은 원 (동일 크기). 조합원이면 원 안에 깃발(회원=빨강, 비회원=노랑)
  var circ = r.is_member ? '#2e6fdb' : '#222222';
  var coop = (r.is_coop === 1);
  var flag = r.is_member ? '#e74c3c' : '#f1c40f';
  var svg = '<svg width="28" height="28" viewBox="0 0 28 28" xmlns="http://www.w3.org/2000/svg">'
    + '<circle cx="14" cy="14" r="12" fill="' + circ + '" stroke="#fff" stroke-width="2"/>';
  if (coop) {
    svg += '<line x1="11" y1="21" x2="11" y2="7" stroke="#fff" stroke-width="1.8" stroke-linecap="round"/>'
      + '<path d="M11 7 L21 10 L11 13 Z" fill="' + flag + '" stroke="#fff" stroke-width="0.7"/>';
  }
  svg += '</svg>';
  var content = '<div style="position:relative;width:28px;height:28px;line-height:0">' + svg + label + '</div>';
  return { content: content, anchor: new naver.maps.Point(14, 14), zIndex: coop ? 200 : 100 };
}
function wcClearMarkers() {
  WC.markers.forEach(function (m) { m.setMap(null); });
  WC.markers = [];
}
function wcRenderMarkers(rows, skipFit) {
  wcClearMarkers();
  if (!WC.map) return;
  // 시·군·구 선택 시에만 회사명 라벨 표시 (시도/전국은 마커 과밀로 겹침)
  var showLabel = !!document.getElementById('wc-sigungu').value;
  var bounds = null, plotted = 0;
  rows.forEach(function (r) {
    if (r.lat == null || r.lng == null) return;
    var pos = new naver.maps.LatLng(r.lat, r.lng);
    var ic = wcIcon(r, showLabel);
    var mk = new naver.maps.Marker({ position: pos, map: WC.map, icon: ic, zIndex: ic.zIndex, title: r.company_name });
    naver.maps.Event.addListener(mk, 'click', function () { wcOpenPanel(r); });
    WC.markers.push(mk);
    if (!bounds) bounds = new naver.maps.LatLngBounds(pos, pos); else bounds.extend(pos);
    plotted++;
  });
  // 색칠 모드 오버레이(skipFit)에서는 지역색이 잡아둔 화면을 흔들지 않는다
  if (!skipFit && bounds && plotted > 1) WC.map.fitBounds(bounds);
}

// ── 상세 패널 ───────────────────────────────────────────────
function wcEsc(s) { return (s == null ? '' : String(s)).replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }); }
function wcOpenPanel(r) {
  document.getElementById('wp-name').textContent = r.company_name || '(이름 없음)';
  var b = [];
  b.push(r.is_member === 1 ? '<span class="wc-badge b-member">회원</span>' : '<span class="wc-badge b-nonmem">비회원</span>');
  if (r.is_member === 1) b.push(r.is_coop === 1 ? '<span class="wc-badge b-coop">조합원</span>' : '<span class="wc-badge b-noncoop">비조합원</span>');
  b.push(r.is_active === 1 ? '<span class="wc-badge b-active">운영 중</span>' : '<span class="wc-badge b-direct">지점/미운영</span>');
  document.getElementById('wp-badges').innerHTML = b.join('');
  var m = [];
  m.push('<div><b>대표자</b>' + wcEsc(r.ceo || '-') + '</div>');
  m.push('<div><b>지역</b>' + wcEsc(r.sido) + ' ' + wcEsc(r.sigungu) + '</div>');
  m.push('<div><b>주소</b>' + wcEsc(r.address || '-') + '</div>');
  m.push('<div><b>전화</b>' + wcEsc(r.phone || '-') + '</div>');
  m.push('<div><b>폐기물</b>' + wcEsc(r.waste_type || '-') + '</div>');
  if (r.note) m.push('<div><b>비고</b>' + wcEsc(r.note) + '</div>');
  if (r.lat == null) m.push('<div style="color:#c0392b;margin-top:6px">※ 좌표 미확인 (지도 미표시)</div>');
  document.getElementById('wp-meta').innerHTML = m.join('');
  document.getElementById('wc-panel').classList.add('open');
  if (WC.map && r.lat != null) WC.map.panTo(new naver.maps.LatLng(r.lat, r.lng));
}
function wcPanelClose() { document.getElementById('wc-panel').classList.remove('open'); }

// ── 요약 박스 드래그 이동 (마우스·터치) ─────────────────────
function wcMakeDraggable(el) {
  var drag = false, ox = 0, oy = 0;
  var main = document.getElementById('wc-main');
  el.addEventListener('pointerdown', function (e) {
    drag = true;
    var r = el.getBoundingClientRect();
    ox = e.clientX - r.left;
    oy = e.clientY - r.top;
    el.style.right = 'auto';
    try { el.setPointerCapture(e.pointerId); } catch (x) {}
    e.preventDefault();
  });
  el.addEventListener('pointermove', function (e) {
    if (!drag) return;
    var mr = main.getBoundingClientRect();
    var x = e.clientX - mr.left - ox;
    var y = e.clientY - mr.top - oy;
    x = Math.max(0, Math.min(mr.width - el.offsetWidth, x));
    y = Math.max(0, Math.min(mr.height - el.offsetHeight, y));
    el.style.left = x + 'px';
    el.style.top = y + 'px';
  });
  el.addEventListener('pointerup', function () { drag = false; });
  el.addEventListener('pointercancel', function () { drag = false; });
}
wcMakeDraggable(document.getElementById('wc-summary'));

wcBoot();
</script>
</body>
</html>
