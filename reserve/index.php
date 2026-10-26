<?php
/**
 * reserve/index.php — 예약 › 캠핑장 빈자리 (화면)
 *
 * 캠핏(camfit.co.kr)의 「빈자리 캘린더」를 한 화면에서 본다 — 캠핑장 → 공간(A공간…) → 월 → 사이트×날짜.
 *   ★데이터는 이 화면의 JS 가 캠핏 API 를 «직접» 부른다(서버는 Cloudflare 에 막힌다 · classes/Reserve.class 헤더).
 *   ★서버(DB)에는 「내 캠핑장 목록」만 남는다 — 빈자리는 볼 때마다 받는다(저장·크론 없음).
 *
 * 주소가 상태다: ?mode=camp&c=<campId>&z=<zoneId|all>&m=YYYY-MM — 북마크·새로고침하면 같은 화면.
 * 「전체」는 공간별로 «그 날 빈 사이트 수»를 접어 보이고, 공간을 고르면 사이트별 칸(예약가능·불가·마감)으로 편다.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/cnt.inc';
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/auth_fnc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/nav.inc';
require_login();

if (empty($_SESSION['rsv_csrf'])) $_SESSION['rsv_csrf'] = bin2hex(random_bytes(16));

$mode = (string)($_GET['mode'] ?? 'camp');
if (!in_array($mode, ['camp', 'cal', 'trip'], true)) $mode = 'camp';   // 서브메뉴가 늘면 여기서 가른다

$rs = new Reserve($pdo);
$schemaOk = true;
try { $camps = $rs->campList(); } catch (Throwable $e) { $schemaOk = false; $camps = []; }

/* 월 달력(보기 + PDF) — DB 기록으로 그린다 · reserve/cal.php */
if ($mode === 'cal' && $schemaOk) {
    require __DIR__ . '/cal.php';
    rsv_page_cal($rs);
    exit;
}
/* 일정 찾기 — 내 캠핑장 2~4곳을 이어서 머무는 출발일 · reserve/trip.php */
if ($mode === 'trip' && $schemaOk) {
    require __DIR__ . '/trip.php';
    rsv_page_trip($rs, $camps);
    exit;
}

echo '<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8">';
echo '<title>캠핑장 빈자리</title>';
echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
nav_css();
echo <<<'CSS'
<style>
body { margin:0; font-family:'Pretendard','Segoe UI','Malgun Gothic',sans-serif; background:#f4f6f8; color:#222; }
.cp-body { max-width:1500px; margin:0 auto; padding:16px 14px 60px; }
h2.cp-h { margin:4px 0 12px; font-size:18px; }
.rsv-tabs { display:flex; gap:4px; margin:2px 0 12px; border-bottom:2px solid #e3e8ee; }
.rsv-tabs a { padding:8px 16px; font-size:15px; font-weight:700; color:#7f8c8d; text-decoration:none; border-bottom:3px solid transparent; margin-bottom:-2px; }
.rsv-tabs a.on { color:#14b86c; border-bottom-color:#14b86c; }
h2.cp-h small { font-size:12px; color:#95a5a6; font-weight:400; margin-left:6px; }
.cp-card { background:#fff; border:1px solid #e3e8ee; border-radius:12px; padding:14px 16px; margin:0 0 12px; }
.cp-warn { background:#fdf3f2; border:1px solid #f5c6c2; color:#b03a2e; padding:12px 14px; border-radius:8px; font-size:14px; }
button.cp-btn, a.cp-btn { display:inline-block; text-decoration:none; padding:7px 14px; border:0; border-radius:8px; background:#14b86c; color:#fff; font-size:14px; font-weight:600; cursor:pointer; font-family:inherit; }
.cp-btn.gray { background:#eef1f4; color:#4a5560; }
.cp-btn.sm { padding:5px 10px; font-size:12.5px; }

/* 검색 */
.cp-search { position:relative; }
.cp-search input { width:100%; box-sizing:border-box; padding:11px 12px; font-size:15px; border:1px solid #cfd8e0; border-radius:10px; font-family:inherit; }
.cp-search input:focus { outline:none; border-color:#14b86c; }
#cpSug { display:none; position:absolute; left:0; right:0; min-width:340px; top:100%; margin-top:4px; background:#fff; border:1px solid #dfe5ea; border-radius:10px; box-shadow:0 8px 20px rgba(0,0,0,.12); z-index:50; max-height:320px; overflow-y:auto; }
#cpSug .s { padding:10px 12px; cursor:pointer; font-size:14px; border-bottom:1px solid #f2f4f6; }
#cpSug .s:last-child { border-bottom:0; }
#cpSug .s small { color:#8a96a3; margin-left:6px; }
#cpSug .s:hover, #cpSug .s.on { background:#eafaf2; }
#cpSug .none { padding:10px 12px; color:#95a5a6; font-size:13px; }

/* 내 캠핑장 */
/* 왼쪽 목록 + 오른쪽 본문 — 좁은 화면(≤900)은 위·아래로 쌓고 목록은 가로 칩 */
.cp-layout { display:grid; grid-template-columns:280px minmax(0, 1fr); gap:14px; align-items:start; }
.cp-side { position:sticky; top:10px; }
.cp-side .cp-card { overflow:visible; }   /* 카드에 스크롤을 걸면 자동완성 목록이 잘린다 — 스크롤은 목록에만 */
.cp-mine-h { font-size:12.5px; color:#7f8c8d; margin:14px 2px 6px; font-weight:600; }
.cp-mine-h b { color:#14b86c; }
.cp-side .cp-mine { flex-direction:column; overflow-x:visible; overflow-y:auto; max-height:calc(100vh - 200px); gap:4px; margin-top:0; }
.cp-side .cp-mine a { border-radius:10px; padding:7px 8px; white-space:normal; border-color:transparent; }
.cp-side .cp-mine a:hover { background:#f4f7f9; }
.cp-side .cp-mine a img, .cp-side .cp-mine a .ph { width:40px; height:40px; border-radius:8px; }
.cp-side .cp-mine a span { min-width:0; line-height:1.3; }
.cp-side .cp-mine a small { display:block; font-size:11.5px; margin-top:1px; }
.cp-side .cp-mine a.on { border-color:#14b86c; background:#eafaf2; box-shadow:inset 3px 0 0 #14b86c; }
.cp-hint { color:#95a5a6; font-size:14px; text-align:center; padding:40px 16px; }
@media (max-width:900px) {
  .cp-layout { grid-template-columns:minmax(0, 1fr); }
  .cp-side { position:static; }
  .cp-side .cp-mine { flex-direction:row; overflow-x:auto; overflow-y:visible; max-height:none; gap:8px; }
  .cp-side .cp-mine a { border-radius:999px; padding:6px 12px 6px 6px; white-space:nowrap; border-color:#e3e8ee; }
  .cp-side .cp-mine a img, .cp-side .cp-mine a .ph { width:28px; height:28px; border-radius:50%; }
  .cp-side .cp-mine a small { display:inline; }
  .cp-side .cp-mine a.on { box-shadow:none; }
  .cp-mine-h { display:none; }
}
.cp-mine { display:flex; gap:8px; overflow-x:auto; padding:2px 0 4px; margin-top:12px; scrollbar-width:thin; }
.cp-mine a { flex:0 0 auto; display:flex; align-items:center; gap:8px; padding:6px 12px 6px 6px; border:1px solid #e3e8ee; border-radius:999px; background:#fff; color:#222; text-decoration:none; font-size:13.5px; white-space:nowrap; }
.cp-mine a img, .cp-mine a .ph { width:28px; height:28px; border-radius:50%; object-fit:cover; background:#e9eef2; flex:0 0 auto; }
.cp-mine a small { color:#8a96a3; }
.cp-mine a.on { border-color:#14b86c; background:#eafaf2; font-weight:700; }
.cp-mine .empty { color:#95a5a6; font-size:13px; padding:6px 2px; }

/* 캠핑장 머리 */
.cp-head { display:flex; gap:14px; align-items:flex-start; }
.cp-head img { width:88px; height:66px; border-radius:8px; object-fit:cover; background:#e9eef2; flex:0 0 auto; }
.cp-head .t { flex:1 1 auto; min-width:0; }
.cp-head .nm { font-size:18px; font-weight:800; margin:0 0 4px; }
.cp-head .meta { font-size:13px; color:#5d6975; line-height:1.7; }
.cp-head .meta a { color:#1d74e0; text-decoration:none; }
.cp-head .act { display:flex; gap:6px; flex-wrap:wrap; margin-top:6px; }
.cp-map { margin-top:12px; text-align:center; }
.cp-map img { max-width:100%; max-height:560px; border-radius:10px; border:1px solid #e3e8ee; cursor:zoom-in; }
.cp-star { background:#fff; border:1px solid #14b86c; color:#14b86c; }
.cp-star.on { background:#14b86c; color:#fff; }

/* 조작줄 */
.cp-ctl { display:flex; flex-wrap:wrap; align-items:center; gap:10px 16px; margin:14px 0 8px; }
.cp-ctl select { padding:8px 10px; font-size:15px; font-weight:700; border:1px solid #cfd8e0; border-radius:8px; background:#fff; font-family:inherit; }
.cp-month { display:flex; align-items:center; gap:6px; font-size:17px; font-weight:800; }
.cp-month button { border:0; background:none; font-size:16px; color:#555; cursor:pointer; padding:4px 8px; }
.cp-month button:disabled { color:#d0d5da; cursor:default; }
.cp-legend { display:flex; gap:12px; flex-wrap:wrap; font-size:12.5px; color:#6b7782; }
.cp-legend i { display:inline-block; width:9px; height:9px; border-radius:50%; margin-right:4px; vertical-align:0; }
.cp-stamp { margin-left:auto; font-size:12px; color:#95a5a6; }

/* 빈자리 요약 칩 */
.cp-sum { font-size:13px; color:#4a5560; margin:4px 0 10px; line-height:2; }
.cp-sum b { margin-right:6px; }
.cp-sum .chip { display:inline-block; padding:2px 9px; margin:0 4px 4px 0; border-radius:999px; background:#eafaf2; color:#0e9257; border:1px solid #bfe9d3; cursor:pointer; font-weight:600; }
.cp-sum .chip.sat { color:#1d74e0; background:#eef5ff; border-color:#cfe0fb; }
.cp-sum .chip.sun { color:#e5484d; background:#fff0f0; border-color:#f8cfd0; }
.cp-sum .none { color:#95a5a6; }

/* 격자 */
.cp-gridwrap { overflow-x:auto; border-radius:10px; -webkit-overflow-scrolling:touch; }
table.cp-g { border-collapse:separate; border-spacing:4px; font-size:12px; }
table.cp-g th, table.cp-g td { padding:0; text-align:center; }
table.cp-g thead th { min-width:46px; height:46px; background:#f3f5f7; border-radius:8px; font-weight:600; color:#666; line-height:1.25; }
table.cp-g thead th b { display:block; font-size:16px; color:#333; }
table.cp-g thead th.sat { background:#eaf3ff; } table.cp-g thead th.sat, table.cp-g thead th.sat b { color:#1d74e0; }
table.cp-g thead th.sun { background:#ffecec; } table.cp-g thead th.sun, table.cp-g thead th.sun b { color:#e5484d; }
table.cp-g thead th.today { box-shadow:inset 0 0 0 2px #14b86c; }
table.cp-g thead th.past { opacity:.45; }
table.cp-g .rh { position:sticky; left:0; z-index:2; background:#fff; min-width:64px; max-width:120px; padding:0 8px; text-align:left; font-weight:700; font-size:13px; color:#333; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; border-radius:8px; }
table.cp-g thead .rh { background:#fff; }
table.cp-g tbody .rh.zl { cursor:pointer; color:#1d74e0; }
table.cp-g tbody .rh small { display:block; font-weight:400; color:#95a5a6; font-size:11px; }
table.cp-g td .c { display:flex; align-items:center; justify-content:center; height:44px; min-width:46px; border-radius:8px; font-size:11.5px; line-height:1.2; box-sizing:border-box; }
.c.av { border:1.5px solid #14b86c; color:#0e9257; background:#fff; font-weight:700; cursor:pointer; }
.c.av:hover { background:#eafaf2; }
.c.un { background:#f3f5f7; color:#b5bcc3; }
.c.so { background:#fff; border:1.5px dashed #dfe3e7; color:#b5bcc3; }
.c.op { background:#fbfbf3; color:#c9c39a; }
.c.ps { background:transparent; }
.c.cnt { font-size:15px; }
.c.av.cnt small { font-size:10px; font-weight:400; color:#5bbf8f; margin-left:1px; }
.c.av.nw { position:relative; box-shadow:0 0 0 2px #ffb020; }
.c.av.nw::after { content:'NEW'; position:absolute; top:-7px; right:-5px; background:#ff8a00; color:#fff; font-size:8.5px; font-weight:800; padding:0 3px; border-radius:4px; line-height:13px; }
.cp-sum .chip em { font-style:normal; color:#ff8a00; }
tr.tot .c.av { background:#14b86c; color:#fff; border-color:#14b86c; }
tr.tot .c.av small { color:#d6f5e5; }
.cp-msg { padding:30px 10px; text-align:center; color:#7f8c8d; font-size:14px; }
.cp-msg.err { color:#b03a2e; }
.cp-msg a { color:#1d74e0; }
.cp-note { font-size:12px; color:#95a5a6; margin:10px 2px 0; line-height:1.6; }

@media (max-width:640px) {
  .cp-body { padding:10px 10px 50px; }
  .cp-head img { width:64px; height:48px; }
  .cp-head .nm { font-size:16px; }
  .cp-stamp { margin-left:0; width:100%; }
}
</style>
CSS;
echo '</head><body>';
render_nav('rsv_camp');
echo '<div class="cp-body">';
echo '<div class="rsv-tabs"><a class="on" href="/reserve/index.php?mode=camp">⛺ 빈자리</a><a href="/reserve/index.php?mode=trip">🧭 일정 찾기</a></div>';

if (!$schemaOk) {
    echo '<div class="cp-warn">「내 캠핑장」 표가 아직 없습니다. <button class="cp-btn sm" onclick="cpSchema(this)">표 만들기</button></div>';
}

/* ── 왼쪽: 검색 + 내 캠핑장 목록 (2026-10-23 사용자 — 「내 캠핑장이 여러 개가 될 수 있어 · 좌측에 리스트로」) ──
 *    넓은 화면 = 세로 목록(스크롤해도 따라온다) · 좁은 화면 = 위쪽 가로 칩(예전 모양) */
echo '<div class="cp-layout"><aside class="cp-side">';
echo '<div class="cp-card">';
echo '<div class="cp-search"><input type="text" id="cpQ" autocomplete="off" placeholder="캠핑장 검색 · 캠핏 주소 붙여넣기"><div id="cpSug"></div></div>';
echo '<div class="cp-mine-h" id="cpMineH"></div>';
echo '<div class="cp-mine" id="cpMine"></div>';
echo '</div></aside><main class="cp-main">';
echo '<div class="cp-card cp-hint" id="cpHint">왼쪽에서 캠핑장을 고르거나, 검색해서 여세요.</div>';

/* ── 캠핑장 패널 (JS 가 채운다) ───────────────────── */
echo '<div class="cp-card" id="cpPanel" style="display:none">';
echo '<div class="cp-head" id="cpHead"></div>';
echo '<div class="cp-map" id="cpMap" style="display:none"></div>';
echo '<div class="cp-ctl">';
echo '<select id="cpZone"></select>';
echo '<div class="cp-month"><button id="cpPrev" title="이전 달">◀</button><span id="cpYm"></span><button id="cpNext" title="다음 달">▶</button></div>';
echo '<div class="cp-legend"><span><i style="background:#14b86c"></i>예약 가능</span><span><i style="background:#c9ced3"></i>예약 불가</span>'
   . '<span><i style="background:#fff;border:1.5px dashed #c9ced3;box-sizing:border-box"></i>마감</span><span><i style="background:#e9e4b8"></i>오픈 전</span></div>';
echo '<div class="cp-stamp"><span id="cpStamp"></span> <button class="cp-btn gray sm" id="cpReload">🔄 새로고침</button></div>';
echo '</div>';
echo '<div class="cp-sum" id="cpSum"></div>';
echo '<div class="cp-gridwrap" id="cpGrid"></div>';
echo '<p class="cp-note">「전체」는 공간별로 그 날 비어 있는 사이트 수(빈 수/전체)입니다 — 공간 이름을 누르면 사이트별로 펼칩니다. '
   . '예약 가능 칸을 누르면 캠핏의 그 공간 빈자리 캘린더가 새 창으로 열립니다(예약은 캠핏에서). 받을 때마다 기록이 저장되고, <b style="color:#ff8a00">NEW</b> 는 직전 기록에서는 없던 빈자리입니다.</p>';
echo '</div>';
echo '</main></div>';

echo '</div>';
echo '<script src="/style/acnav.js?v=1"></script><script src="/reserve/rsv.js?v=2"></script>';
echo '<script>var RSV_CSRF=' . json_encode($_SESSION['rsv_csrf']) . ';const RSV_CAMPS=' . json_encode($camps, JSON_UNESCAPED_UNICODE) . ';</script>';
echo <<<'JS'
<script>
(function () {
'use strict';
var WD = ['일','월','화','수','목','금','토'];
var S = { camps: RSV_CAMPS || [], id: '', meta: null, zone: 'all', ym: '', data: null, at: null };
var dataCache = {};   // 같은 화면 안에서 3분 재사용 — 새로고침 버튼은 무시하고 다시 받는다
var TTL = 180000;
var mapOpen = false; try { mapOpen = localStorage.getItem('rsv_map') === '1'; } catch (e) {}   // 배치도 펼침은 기기별 기억

function $(id) { return document.getElementById(id); }
function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function pad(n) { return (n < 10 ? '0' : '') + n; }
function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
function todayStr() { return ymd(new Date()); }
function thisYm() { return todayStr().slice(0, 7); }
function ymAdd(ym, k) { var y = +ym.slice(0, 4), m = +ym.slice(5, 7) - 1 + k; var d = new Date(y, m, 1); return d.getFullYear() + '-' + pad(d.getMonth() + 1); }
function lastDay(ym) { return new Date(+ym.slice(0, 4), +ym.slice(5, 7), 0).getDate(); }

var cfGet = RsvSnap.get;   // 캠핏 받기·기록은 reserve/rsv.js 단일본
function post(action, data) {
  var fd = new FormData(); fd.append('action', action); fd.append('csrf', RSV_CSRF);
  for (var k in data) fd.append(k, data[k]);
  return fetch('/reserve/api.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); });
}
window.cpSchema = function (btn) {
  btn.disabled = true;
  post('schema', {}).then(function (j) { if (j.ok) location.reload(); else { alert(j.msg); btn.disabled = false; } });
};

/* ── 주소 = 상태 ── */
function syncUrl() {
  var q = new URLSearchParams({ mode: 'camp' });
  if (S.id) { q.set('c', S.id); q.set('z', S.zone); q.set('m', S.ym); }
  history.replaceState(null, '', '?' + q.toString());
}

/* ── 내 캠핑장 ── */
function isMine(id) { return S.camps.some(function (c) { return c.ext_id === id; }); }
function renderMine() {
  var box = $('cpMine');
  $('cpMineH').innerHTML = '내 캠핑장 <b>' + S.camps.length + '</b>';
  if (!S.camps.length) { box.innerHTML = '<div class="empty">담아 둔 캠핑장이 없습니다 — 위에서 검색해 열고 「☆ 내 캠핑장」을 누르세요.</div>'; return; }
  box.innerHTML = S.camps.map(function (c) {
    var img = c.thumb ? '<img src="' + esc(c.thumb) + '" alt="" loading="lazy">' : '<span class="ph"></span>';
    return '<a href="?mode=camp&c=' + esc(c.ext_id) + '" data-id="' + esc(c.ext_id) + '"' + (c.ext_id === S.id ? ' class="on"' : '') + '>'
         + img + '<span>' + esc(c.name) + (c.region ? ' <small>' + esc(c.region) + '</small>' : '') + '</span></a>';
  }).join('');
}
$('cpMine').addEventListener('click', function (e) {
  var a = e.target.closest('a[data-id]'); if (!a) return;
  e.preventDefault(); openCamp(a.getAttribute('data-id'));
});

/* ── 검색 (이름 → 캠핏 자동완성 · 주소/ID 붙여넣기 → 바로 열기) ── */
var sugT = null, sugSeq = 0;
function sugClose() { $('cpSug').style.display = 'none'; }
function idFrom(s) { var m = String(s).match(/(?:^|\/camp\/)([0-9a-f]{24})(?:[/?#]|$)/i); return m ? m[1].toLowerCase() : ''; }
$('cpQ').addEventListener('input', function () {
  var q = this.value.trim();
  clearTimeout(sugT);
  if (!q) { sugClose(); return; }
  if (idFrom(q)) { sugClose(); return; }
  sugT = setTimeout(function () {
    var my = ++sugSeq;
    cfGet('/v1/camps/search/autocomplete?search=' + encodeURIComponent(q)).then(function (arr) {
      if (my !== sugSeq) return;
      var box = $('cpSug');
      arr = (arr || []).filter(function (x) { return x && x._id; });
      box.innerHTML = arr.length ? arr.slice(0, 20).map(function (x) {
        return '<div class="s" data-id="' + esc(x._id) + '">' + esc(x.name) + '<small>' + esc([x.city, x.major].filter(Boolean).join(' ')) + '</small></div>';
      }).join('') : '<div class="none">검색 결과가 없습니다</div>';
      box.style.display = 'block';
    }).catch(function (e) {
      if (my !== sugSeq) return;
      $('cpSug').innerHTML = '<div class="none">' + esc(e.message) + '</div>'; $('cpSug').style.display = 'block';
    });
  }, 250);
});
$('cpSug').addEventListener('click', function (e) {
  var s = e.target.closest('.s'); if (!s) return;
  sugClose(); $('cpQ').value = ''; $('cpQ').blur(); openCamp(s.getAttribute('data-id'));
});
document.addEventListener('click', function (e) { if (!e.target.closest('.cp-search')) sugClose(); });
AcNav.attach($('cpQ'), {
  box: '#cpSug', item: '.s', close: sugClose, enterFirst: true,
  onEnter: function () { var id = idFrom($('cpQ').value.trim()); if (!id) return false; sugClose(); $('cpQ').value = ''; openCamp(id); return true; }
});

/* ── 캠핑장 열기 ── */
function openCamp(id, zone, ym) {
  S.id = id; S.zone = zone || 'all'; S.ym = ym || thisYm(); S.data = null;
  if (S.ym < thisYm()) S.ym = thisYm();
  $('cpPanel').style.display = ''; $('cpHint').style.display = 'none';
  renderMine();
  $('cpHead').innerHTML = '<div class="cp-msg">캠핑장 정보를 받는 중…</div>';
  $('cpGrid').innerHTML = ''; $('cpSum').innerHTML = '';
  var my = id;
  loadMeta(id).then(function (m) {
    if (S.id !== my) return;
    S.meta = m; renderHead(); loadMonth(false);
  }).catch(function (e) {
    if (S.id !== my) return;
    $('cpHead').innerHTML = '<div class="cp-msg err">' + esc(e.message) + '</div>';
  });
}
function loadMeta(id) { return RsvSnap.campMeta(id); }   // 캠핑장 정보(배치도 포함)는 reserve/rsv.js 단일본
function renderHead() {
  var m = S.meta, mine = isMine(m.id);
  $('cpHead').innerHTML =
    (m.thumb ? '<img src="' + esc(m.thumb) + '" alt="">' : '') +
    '<div class="t"><div class="nm">' + esc(m.name) + '</div><div class="meta">' +
    esc(m.address || m.region) +
    (m.contact ? ' · <a href="tel:' + esc(m.contact.replace(/[^0-9+]/g, '')) + '">' + esc(m.contact) + '</a>' : '') +
    (m.until ? '<br>예약 오픈: <b>' + esc(m.until) + '</b>까지' : '') +
    '</div><div class="act">' +
    '<button class="cp-btn sm cp-star' + (mine ? ' on' : '') + '" id="cpStar">' + (mine ? '★ 내 캠핑장' : '☆ 내 캠핑장에 담기') + '</button>' +
    '<button class="cp-btn sm" id="cpCal" style="background:#1d74e0">📅 달력 · PDF</button>' +
    (m.map ? '<button class="cp-btn gray sm" id="cpMapBtn">🗺 배치도</button>' : '') +
    '<a class="cp-btn gray sm" target="_blank" rel="noopener" href="https://camfit.co.kr/camp/' + esc(m.id) + '">캠핏에서 보기 ↗</a>' +
    '</div></div>';
  $('cpStar').onclick = toggleMine;
  var mb = $('cpMapBtn'), mp = $('cpMap');
  if (m.map) {
    mp.innerHTML = '<a href="' + esc(m.mapFull || m.map) + '" target="_blank" rel="noopener" title="원본 크게 보기"><img src="' + esc(m.map) + '" alt="배치도" loading="lazy"></a>';
    mp.style.display = mapOpen ? '' : 'none';
    mb.onclick = function () { mapOpen = !mapOpen; mp.style.display = mapOpen ? '' : 'none'; try { localStorage.setItem('rsv_map', mapOpen ? '1' : '0'); } catch (e) {} };
  } else { mp.innerHTML = ''; mp.style.display = 'none'; }
  $('cpCal').onclick = function () { window.open('?mode=cal&c=' + S.id + '&from=' + S.ym + '&n=3', '_blank'); };
}
function toggleMine() {
  var m = S.meta, btn = $('cpStar'); btn.disabled = true;
  var p = isMine(m.id)
    ? post('camp_del', { ext_id: m.id })
    : post('camp_add', { ext_id: m.id, name: m.name, region: m.region, address: m.address, contact: m.contact, thumb: m.thumb });
  p.then(function (j) {
    if (!j.ok) { alert(j.msg); btn.disabled = false; return; }
    S.camps = j.camps || []; renderMine(); renderHead();
  }).catch(function () { alert('저장하지 못했습니다.'); btn.disabled = false; });
}

/* ── 월 데이터 ── */
function loadMonth(force) {
  var id = S.id, ym = S.ym, key = id + '|' + ym;
  var t = todayStr(), start = ym + '-01', end = ym + '-' + pad(lastDay(ym));
  if (start < t) start = t;
  renderCtl();
  syncUrl();
  if (end < start) { $('cpGrid').innerHTML = '<div class="cp-msg">지난 달은 볼 수 없습니다.</div>'; return; }
  var hit = dataCache[key];
  if (!force && hit && Date.now() - hit.at < TTL) { S.data = hit.data; S.at = hit.at; render(); return; }
  S.data = null;
  $('cpGrid').innerHTML = '<div class="cp-msg">빈자리를 받는 중…</div>'; $('cpSum').innerHTML = '';
  cfGet('/v1/vacancy-calendars/camps/' + id + '/sites?startDate=' + start + '&endDate=' + end).then(function (j) {
    if (S.id !== id || S.ym !== ym) return;
    if (j.status !== 'success' || !j.data) throw new Error(j.message || '캠핏 응답 형식이 바뀌었습니다.');
    var hit = dataCache[key] = { data: j.data, at: Date.now(), newly: {}, saved: '' };
    S.data = j.data; S.at = hit.at;
    render();
    /* 받은 그대로 DB 에 남긴다(직전과 같으면 서버가 확인 시각만 고친다) — 실패해도 화면은 그대로 */
    RsvSnap.save(id, ym, start, end, j.data, S.meta).then(function (r) {
      if (!r || !r.ok) { hit.saved = '저장 실패'; }
      else { (r.newly || []).forEach(function (k) { hit.newly[k] = 1; }); hit.saved = r.changed ? '변동 저장' : '변동 없음'; }
      if (S.id === id && S.ym === ym) render();
    }).catch(function () { hit.saved = '저장 실패'; if (S.id === id && S.ym === ym) render(); });
  }).catch(function (e) {
    if (S.id !== id || S.ym !== ym) return;
    $('cpGrid').innerHTML = '<div class="cp-msg err">' + esc(e.message) + '<br><a target="_blank" rel="noopener" href="https://camfit.co.kr/camp/' + esc(id) + '">캠핏에서 직접 보기 ↗</a></div>';
  });
}
function maxYm() {
  var t = thisYm(), u = S.meta && S.meta.until ? S.meta.until.slice(0, 7) : t;
  var m = ymAdd(u > t ? u : t, 1);           // 오픈 한도 다음 달까지 (그 뒤는 전부 「오픈 전」이라 볼 것이 없다)
  var cap = ymAdd(t, 12);
  return m > cap ? cap : m;
}
function renderCtl() {
  $('cpYm').textContent = S.ym.slice(0, 4) + '년 ' + (+S.ym.slice(5, 7)) + '월';
  $('cpPrev').disabled = S.ym <= thisYm();
  $('cpNext').disabled = S.ym >= maxYm();
  var sel = $('cpZone'), zs = (S.data && S.data.zones) || [];
  var html = '<option value="all">전체 공간</option>' + zs.map(function (z) { return '<option value="' + esc(z.id) + '">' + esc(z.name) + '</option>'; }).join('');
  if (sel.innerHTML !== html) sel.innerHTML = html;
  if (S.zone !== 'all' && zs.length && !zs.some(function (z) { return z.id === S.zone; })) S.zone = 'all';
  sel.value = S.zone;
}
$('cpPrev').onclick = function () { S.ym = ymAdd(S.ym, -1); loadMonth(false); };
$('cpNext').onclick = function () { S.ym = ymAdd(S.ym, 1); loadMonth(false); };
$('cpReload').onclick = function () { loadMonth(true); };
$('cpZone').onchange = function () { S.zone = this.value; syncUrl(); render(); };

/* ── 그리기 ── */
function days() {
  var out = [], n = lastDay(S.ym), t = todayStr();
  for (var d = 1; d <= n; d++) {
    var s = S.ym + '-' + pad(d), w = new Date(+S.ym.slice(0, 4), +S.ym.slice(5, 7) - 1, d).getDay();
    if (s < t) continue;   // 지난 날은 캠핏이 안 준다(400) — 빈 칸으로 자리만 먹지 않게 아예 뺀다
    out.push({ s: s, d: d, w: w, past: s < t, today: s === t });
  }
  return out;
}
function cellKind(st, date) {
  if (st === 'available') return 'av';
  if (st === 'sold_out') return 'so';
  var u = S.meta && S.meta.until;
  return (u && date > u) ? 'op' : 'un';
}
function render() {
  renderCtl();
  var data = S.data; if (!data) return;
  var ds = days();
  var sites = (data.sites || []).map(function (s) {
    var m = {}; (s.availability || []).forEach(function (a) { m[a.date] = a.status; }); return { id: s.id, name: s.name, zoneId: s.zoneId, st: m };
  });
  var zones = (data.zones || []).map(function (z) { return { id: z.id, name: z.name, sites: sites.filter(function (s) { return s.zoneId === z.id; }) }; });
  var shown = S.zone === 'all' ? sites : sites.filter(function (s) { return s.zoneId === S.zone; });
  var cur = dataCache[S.id + '|' + S.ym] || {}, NW = cur.newly || {};   // 새로 난 자리 = 직전 «다른» 기록 대비 (서버 Reserve::newlyOpen)

  /* 빈자리 요약 — 지금 보는 범위(전체 또는 그 공간)에서 빈 사이트가 있는 날 */
  var chips = [];
  ds.forEach(function (x) {
    if (x.past) return;
    var n = 0, nn = 0; shown.forEach(function (s) { if (s.st[x.s] === 'available') { n++; if (NW[s.id + '|' + x.s]) nn++; } });
    if (n) chips.push('<span class="chip' + (x.w === 6 ? ' sat' : x.w === 0 ? ' sun' : '') + '" data-d="' + x.s + '">' + x.d + '(' + WD[x.w] + ') ' + n + (nn ? ' <em>🆕' + nn + '</em>' : '') + '</span>');
  });
  $('cpSum').innerHTML = '<b>빈자리 있는 날</b>' + (chips.length ? chips.join('') : '<span class="none">이 달에는 없습니다</span>');

  var head = '<thead><tr><th class="rh">' + (S.zone === 'all' ? '공간' : '사이트') + '</th>' + ds.map(function (x) {
    return '<th data-d="' + x.s + '" class="' + (x.w === 6 ? 'sat' : x.w === 0 ? 'sun' : '') + (x.today ? ' today' : '') + (x.past ? ' past' : '') + '">' + WD[x.w] + '<b>' + x.d + '</b></th>';
  }).join('') + '</tr></thead>';

  var body = '';
  if (S.zone === 'all') {
    var rows = [{ tot: true, name: '전체', sites: sites }].concat(zones);
    body = rows.map(function (z) {
      var rh = z.tot ? '<th class="rh">전체<small>' + sites.length + '자리</small></th>'
                     : '<th class="rh zl" data-z="' + esc(z.id) + '" title="' + esc(z.name) + ' 펼치기">' + esc(z.name) + '<small>' + z.sites.length + '자리</small></th>';
      return '<tr' + (z.tot ? ' class="tot"' : '') + '>' + rh + ds.map(function (x) {
        if (x.past) return '<td><div class="c ps"></div></td>';
        var av = 0, so = 0, op = 0, nw = 0;
        z.sites.forEach(function (s) { var k = cellKind(s.st[x.s], x.s); if (k === 'av') { av++; if (NW[s.id + '|' + x.s]) nw++; } else if (k === 'so') so++; else if (k === 'op') op++; });
        if (av) return '<td><div class="c av cnt' + (nw ? ' nw' : '') + '" data-z="' + (z.tot ? '' : esc(z.id)) + '" title="' + x.s + ' 빈 사이트 ' + av + '/' + z.sites.length + '">' + av + '<small>/' + z.sites.length + '</small></div></td>';
        if (op && op === z.sites.length) return '<td><div class="c op">오픈<br>전</div></td>';
        if (so) return '<td><div class="c so">마감</div></td>';
        return '<td><div class="c un">예약<br>불가</div></td>';
      }).join('') + '</tr>';
    }).join('');
  } else {
    body = shown.map(function (s) {
      return '<tr><th class="rh" title="' + esc(s.name) + '">' + esc(s.name) + '</th>' + ds.map(function (x) {
        if (x.past) return '<td><div class="c ps"></div></td>';
        var k = cellKind(s.st[x.s], x.s);
        if (k === 'av') return '<td><div class="c av' + (NW[s.id + '|' + x.s] ? ' nw' : '') + '" data-z="' + esc(s.zoneId) + '" title="' + esc(s.name) + ' ' + x.s + '">예약<br>가능</div></td>';
        if (k === 'so') return '<td><div class="c so">🔔<br>마감</div></td>';
        if (k === 'op') return '<td><div class="c op">오픈<br>전</div></td>';
        return '<td><div class="c un">예약<br>불가</div></td>';
      }).join('') + '</tr>';
    }).join('');
  }
  $('cpGrid').innerHTML = shown.length || S.zone === 'all'
    ? '<table class="cp-g">' + head + '<tbody>' + body + '</tbody></table>'
    : '<div class="cp-msg">이 공간에는 사이트가 없습니다.</div>';
  var tm = new Date(S.at);
  $('cpStamp').textContent = pad(tm.getHours()) + ':' + pad(tm.getMinutes()) + ':' + pad(tm.getSeconds()) + ' 받음' + (cur.saved ? ' · ' + cur.saved : '');
  syncUrl();
}
$('cpGrid').addEventListener('click', function (e) {
  var zl = e.target.closest('.rh.zl');
  if (zl) { S.zone = zl.getAttribute('data-z'); syncUrl(); render(); window.scrollTo({ top: $('cpPanel').offsetTop - 10, behavior: 'smooth' }); return; }
  var av = e.target.closest('.c.av');
  if (av) {
    var z = av.getAttribute('data-z');
    var url = z ? 'https://camfit.co.kr/camp/' + S.id + '/' + z + '/vacancy-calendar' : 'https://camfit.co.kr/camp/' + S.id;
    window.open(url, '_blank', 'noopener');
  }
});
$('cpSum').addEventListener('click', function (e) {
  var c = e.target.closest('.chip'); if (!c) return;
  var th = $('cpGrid').querySelector('th[data-d="' + c.getAttribute('data-d') + '"]');
  if (th) th.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
});

/* ── 시작 ── */
renderMine();
var q0 = new URLSearchParams(location.search);
var c0 = (q0.get('c') || '').toLowerCase();
if (/^[0-9a-f]{24}$/.test(c0)) openCamp(c0, q0.get('z') || 'all', /^\d{4}-\d{2}$/.test(q0.get('m') || '') ? q0.get('m') : '');
else if (S.camps.length) openCamp(S.camps[0].ext_id);
})();
</script>
JS;
echo '</body></html>';
?>
