<?php
/**
 * reserve/trip.php — 예약 › 캠핑장 «일정 찾기» · index.php?mode=trip 이 부른다
 *
 * 2026-10-23 사용자 — 「캠핑장을 2~3개 고르고 A 1박·B 1박(또는 A 2박·B 1박)처럼 정하면, 가능한 날짜를 요약해서(달력)」.
 *   = 여러 캠핑장을 «이어서» 머무는 일정의 출발일 찾기. 출발일 d 에 A 를 N박(d ~ d+N−1 밤) → 이어서 B 를 M박(d+N ~ …) …
 *
 * 규칙(화면의 체크칸으로 바꾼다 · 주소에 남는다):
 *   - 순서대로(A→B→C). 「순서 바꿔서도」를 켜면 모든 순서를 본다(3곳 = 6가지).
 *   - 연박은 «같은 사이트»가 그 밤들 모두 비어야 한다(텐트를 옮기지 않는다). 끄면 밤마다 아무 사이트나 비면 된다.
 *   - 「내가 고른 사이트만」을 켜면 캠핑장별 rsv_pick 만 본다(고른 게 없는 캠핑장은 전체).
 * 결과는 «순서별 묶음»이다(같은 날 사용자 — 「A→B · B→A 로 분류해서」) — 묶음마다 달력·상세·목록을 따로. 맨 위에 순서별 한 줄 요약.
 * 판정은 «지금» 캠핏에서 받은 값으로 한다 — 받은 것은 rsv_snap 에도 남긴다(다른 화면과 같은 중계 저장 · reserve/rsv.js).
 * 고를 수 있는 캠핑장은 「내 캠핑장」(rsv_camp) — 검색해서 담은 뒤 고른다.
 *
 * 주소: ?mode=trip&c=<campId>:<박>,<campId>:<박>…&from=YYYY-MM&n=1~3&same=1&perm=0&pick=0
 */

const RSV_TRIP_MAX_CAMPS  = 4;
const RSV_TRIP_MAX_NIGHTS = 7;

function rsv_page_trip(Reserve $rs, array $camps): void
{
    $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    /* 주소 → 계획 (내 캠핑장에 있는 것만) */
    $mine = [];
    foreach ($camps as $c) $mine[$c['ext_id']] = $c;
    $plan = [];
    foreach (explode(',', (string)($_GET['c'] ?? '')) as $tok) {
        [$id, $nt] = array_pad(explode(':', $tok), 2, '1');
        $id = strtolower($id);
        if (isset($mine[$id]) && count($plan) < RSV_TRIP_MAX_CAMPS && !in_array($id, array_column($plan, 'id'), true)) {
            $plan[] = ['id' => $id, 'n' => max(1, min(RSV_TRIP_MAX_NIGHTS, (int)$nt))];
        }
    }
    $thisYm = date('Y-m');
    $from = (string)($_GET['from'] ?? $thisYm);
    if (!preg_match('/^\d{4}-\d{2}$/', $from) || $from < $thisYm) $from = $thisYm;
    $opt = [
        'from' => $from,
        'n'    => max(1, min(3, (int)($_GET['n'] ?? 2))),
        'same' => ($_GET['same'] ?? '1') !== '0',
        'perm' => ($_GET['perm'] ?? '0') === '1',
        'pick' => ($_GET['pick'] ?? '0') === '1',
    ];
    $picks = [];
    foreach ($camps as $c) { try { $picks[$c['ext_id']] = $rs->pickGet($c['ext_id']); } catch (Throwable $e) { $picks[$c['ext_id']] = []; } }

    echo '<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8">';
    echo '<title>캠핑 일정 찾기</title>';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    nav_css();
    echo <<<'CSS'
<style>
body { margin:0; font-family:'Pretendard','Segoe UI','Malgun Gothic',sans-serif; background:#f4f6f8; color:#222; }
.tp-body { max-width:1200px; margin:0 auto; padding:16px 14px 60px; }
.rsv-tabs { display:flex; gap:4px; margin:2px 0 12px; border-bottom:2px solid #e3e8ee; }
.rsv-tabs a { padding:8px 16px; font-size:15px; font-weight:700; color:#7f8c8d; text-decoration:none; border-bottom:3px solid transparent; margin-bottom:-2px; }
.rsv-tabs a.on { color:#14b86c; border-bottom-color:#14b86c; }
.tp-card { background:#fff; border:1px solid #e3e8ee; border-radius:12px; padding:14px 16px; margin:0 0 12px; }
.tp-card h3 { margin:0 0 8px; font-size:15px; }
.tp-card h3 small { font-weight:400; color:#95a5a6; font-size:12px; margin-left:6px; }
.tp-mine { display:flex; flex-wrap:wrap; gap:6px; }
.tp-mine button { display:flex; align-items:center; gap:6px; padding:5px 11px 5px 5px; border:1px solid #dfe5ea; border-radius:999px; background:#fff; font-size:13.5px; cursor:pointer; font-family:inherit; }
.tp-mine button img, .tp-mine button .ph { width:26px; height:26px; border-radius:50%; object-fit:cover; background:#e9eef2; }
.tp-mine button.in { background:#eafaf2; border-color:#14b86c; color:#0e7a49; font-weight:700; }
.tp-mine button:disabled { opacity:.45; cursor:default; }
.tp-plan { margin-top:12px; }
.tp-leg { display:flex; align-items:center; gap:10px; padding:8px 10px; border:1px solid #e3e8ee; border-radius:10px; margin-bottom:6px; background:#fbfcfd; }
.tp-leg .no { width:24px; height:24px; border-radius:50%; background:#14b86c; color:#fff; font-weight:800; display:flex; align-items:center; justify-content:center; font-size:13px; flex:0 0 auto; }
.tp-leg .nm { flex:1 1 auto; font-weight:700; font-size:14.5px; min-width:0; }
.tp-leg .nm small { display:block; font-weight:400; color:#8a96a3; font-size:11.5px; }
.tp-leg select { padding:6px 8px; font-size:14px; font-weight:700; border:1px solid #cfd8e0; border-radius:7px; font-family:inherit; }
.tp-leg button { border:1px solid #dfe5ea; background:#fff; border-radius:6px; width:30px; height:30px; cursor:pointer; font-size:13px; }
.tp-arrow { text-align:center; color:#b5bcc3; font-size:12px; margin:-2px 0 4px; }
.tp-empty { color:#95a5a6; font-size:13.5px; padding:6px 2px; }
.tp-opts { display:flex; flex-wrap:wrap; gap:8px 18px; align-items:center; margin-top:12px; font-size:13.5px; }
.tp-opts select { padding:6px 8px; border:1px solid #cfd8e0; border-radius:7px; font-size:14px; font-family:inherit; }
.tp-opts label { display:flex; align-items:center; gap:5px; cursor:pointer; }
.tp-go { margin-left:auto; padding:9px 22px; border:0; border-radius:9px; background:#14b86c; color:#fff; font-size:15px; font-weight:800; cursor:pointer; font-family:inherit; }
.tp-go:disabled { background:#b9dcc9; cursor:default; }
#tpSt { font-size:13px; color:#7f8c8d; margin:2px 2px 10px; min-height:18px; }
#tpSt.err { color:#b03a2e; }
.tp-sum { font-size:14px; margin:0 0 10px; }
.tp-sum b { color:#0e9257; font-size:17px; }
.tp-sum .wk { color:#e07b00; }
/* 달력 (요약 PDF 의 미니 달력과 같은 모양 · 일요일 시작) */
.mc-wrap { display:flex; gap:18px; flex-wrap:wrap; }
.mc { flex:1 1 0; min-width:250px; }
.mc-t { display:flex; justify-content:space-between; align-items:baseline; border-left:4px solid #14b86c; padding-left:7px; margin-bottom:5px; }
.mc-t b { font-size:15px; } .mc-t span { font-size:11px; color:#0e9257; font-weight:700; }
.mc table { width:100%; border-collapse:separate; border-spacing:3px; table-layout:fixed; }
.mc th { font-size:11px; color:#666; font-weight:700; padding:2px 0; }
.mc th.fs { color:#e07b00; } .mc th.su { color:#e5484d; }
.mc td { height:42px; border-radius:6px; position:relative; vertical-align:top; padding:0; }
.mc td i { position:absolute; left:4px; top:2px; font-style:normal; font-size:10px; }
.mc td b { display:block; text-align:center; font-size:16px; line-height:1; padding-top:17px; }
.mc td.e { background:transparent; }
.mc td.x { background:#f5f6f7; } .mc td.x i { color:#c3c9ce; }
.mc td.x.p { background:#fafbfb; } .mc td.x.p i { color:#dde1e4; }
.mc td.ok { background:#14b86c; color:#fff; cursor:pointer; } .mc td.ok i { color:#d6f5e5; }
.mc td.ok.wk { background:#0e8f55; box-shadow:inset 0 0 0 2px #ffb020; }
.mc td.ok:hover { filter:brightness(1.08); }
.mc td.sel { outline:3px solid #1d74e0; outline-offset:-1px; }
.tp-det { margin-top:12px; }
.tp-ix { display:flex; flex-direction:column; gap:4px; margin:0 0 6px; }
.tp-ix a { display:flex; align-items:center; gap:8px; padding:7px 10px; border:1px solid #e3e8ee; border-radius:9px; color:#222; text-decoration:none; font-size:13.5px; background:#fbfcfd; }
.tp-ix a:hover { border-color:#14b86c; }
.tp-ix a b { margin-left:auto; color:#0e9257; }
.tp-ix a.none { color:#aab2b9; } .tp-ix a.none b { color:#c3c9ce; }
.ord { display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:6px; background:#1d74e0; color:#fff; font-size:12px; font-weight:800; flex:0 0 auto; }
.tp-grp { border-top:2px solid #e3e8ee; margin-top:16px; padding-top:12px; scroll-margin-top:10px; }
.tp-gh { display:flex; flex-wrap:wrap; align-items:center; gap:4px 8px; font-size:16px; margin:0 0 10px; }
.tp-gh small { font-size:12.5px; font-weight:400; color:#666; }
.tp-gh small b { color:#0e9257; font-size:15px; }
.tp-gh .wk { color:#e07b00; }
.tp-more { margin-top:10px; }
.tp-more summary { cursor:pointer; font-size:13.5px; font-weight:700; color:#4a5560; }
.tp-det h4 { margin:0 0 8px; font-size:15px; }
.tp-route { border:1px solid #e3e8ee; border-radius:10px; padding:10px 12px; margin-bottom:8px; }
.tp-route .hd { font-size:12px; color:#8a96a3; margin-bottom:6px; }
.tp-step { display:flex; gap:10px; padding:4px 0; font-size:13.5px; }
.tp-step .when { width:150px; flex:0 0 auto; font-weight:700; white-space:nowrap; }
.tp-step .when small { display:block; font-weight:400; color:#8a96a3; font-size:11.5px; }
.tp-step .camp { font-weight:700; }
.tp-step .sites { color:#3d4a55; font-size:12.5px; margin-top:2px; line-height:1.6; }
.tp-step .sites span { display:inline-block; padding:0 6px; margin:0 4px 3px 0; border:1px solid #bfe9d3; background:#eafaf2; border-radius:5px; color:#0e7a49; font-weight:600; }
.tp-list { width:100%; border-collapse:collapse; font-size:13px; margin-top:6px; }
.tp-list td { border-bottom:1px solid #eef1f3; padding:6px 6px; vertical-align:top; }
.tp-list td.d { width:110px; font-weight:700; white-space:nowrap; cursor:pointer; color:#222; text-decoration:underline dotted #b5bcc3; }
.tp-list td.d.sun { color:#e5484d; } .tp-list td.d.sat { color:#1d74e0; }
.tp-list td.n { width:46px; text-align:right; color:#0e9257; font-weight:800; }
@media (max-width:640px) {
  .tp-body { padding:10px 10px 50px; }
  .tp-step { flex-direction:column; gap:2px; }
  .tp-step .when { width:auto; }
  .tp-go { margin-left:0; width:100%; }
}
</style>
CSS;
    echo '</head><body>';
    render_nav('rsv_camp');
    echo '<div class="tp-body">';
    echo '<div class="rsv-tabs"><a href="/reserve/index.php?mode=camp">⛺ 빈자리</a><a class="on" href="/reserve/index.php?mode=trip">🧭 일정 찾기</a></div>';

    echo '<div class="tp-card"><h3>1. 캠핑장 고르기 <small>누른 순서대로 일정에 들어갑니다 · 최대 ' . RSV_TRIP_MAX_CAMPS . '곳 · 「내 캠핑장」에 담긴 곳만</small></h3>';
    echo '<div class="tp-mine" id="tpMine"></div>';
    echo '<h3 style="margin-top:14px">2. 일정 <small>캠핑장마다 몇 박인지 · ↑↓ 로 순서</small></h3>';
    echo '<div class="tp-plan" id="tpPlan"></div>';
    echo '<div class="tp-opts">';
    echo '<label>찾을 기간 <select id="tpFrom">';
    for ($i = 0; $i < 12; $i++) {
        $ym = date('Y-m', strtotime($thisYm . '-01 +' . $i . ' month'));
        echo '<option value="' . $ym . '"' . ($ym === $opt['from'] ? ' selected' : '') . '>' . (int)substr($ym, 0, 4) . '년 ' . (int)substr($ym, 5) . '월부터</option>';
    }
    echo '</select><select id="tpN">';
    for ($i = 1; $i <= 3; $i++) echo '<option value="' . $i . '"' . ($i === $opt['n'] ? ' selected' : '') . '>' . $i . '개월</option>';
    echo '</select></label>';
    echo '<label title="한 캠핑장에서 2박 이상이면 같은 사이트가 그 밤들 모두 비어야 한다"><input type="checkbox" id="tpSame"' . ($opt['same'] ? ' checked' : '') . '> 연박은 같은 사이트</label>';
    echo '<label title="A→B 뿐 아니라 B→A 도 찾는다"><input type="checkbox" id="tpPerm"' . ($opt['perm'] ? ' checked' : '') . '> 순서 바꿔서도 찾기</label>';
    echo '<label title="빈자리 표에서 체크해 둔 사이트만 본다(고른 게 없는 캠핑장은 전체)"><input type="checkbox" id="tpPick"' . ($opt['pick'] ? ' checked' : '') . '> 내가 고른 사이트만</label>';
    echo '<button class="tp-go" id="tpGo">🔍 가능한 날짜 찾기</button>';
    echo '</div></div>';

    echo '<div id="tpSt"></div>';
    echo '<div class="tp-card" id="tpRes" style="display:none"></div>';
    echo '</div>';

    echo '<script>var RSV_CSRF=' . json_encode($_SESSION['rsv_csrf']) . ';var RSV_TRIP=' . json_encode([
        'camps' => array_map(fn($c) => ['id' => $c['ext_id'], 'name' => $c['name'], 'region' => $c['region'], 'thumb' => $c['thumb']], $camps),
        'plan' => $plan, 'opt' => $opt, 'picks' => $picks, 'maxCamps' => RSV_TRIP_MAX_CAMPS, 'maxNights' => RSV_TRIP_MAX_NIGHTS,
    ], JSON_UNESCAPED_UNICODE) . ';</script>';
    echo '<script src="/reserve/rsv.js?v=2"></script>';
    echo <<<'JS'
<script>
(function () {
  'use strict';
  var T = RSV_TRIP, plan = T.plan.slice(), WD = ['일','월','화','수','목','금','토'];
  var byId = {}; T.camps.forEach(function (c) { byId[c.id] = c; });
  function $(id) { return document.getElementById(id); }
  function esc(t) { return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function parse(s) { return new Date(+s.slice(0, 4), +s.slice(5, 7) - 1, +s.slice(8, 10)); }
  function add(s, k) { var d = parse(s); d.setDate(d.getDate() + k); return ymd(d); }
  function md(s) { return (+s.slice(5, 7)) + '/' + (+s.slice(8, 10)); }
  function wd(s) { return parse(s).getDay(); }
  function ymAdd(ym, k) { var d = new Date(+ym.slice(0, 4), +ym.slice(5, 7) - 1 + k, 1); return d.getFullYear() + '-' + pad(d.getMonth() + 1); }
  function st(t, err) { $('tpSt').textContent = t; $('tpSt').className = err ? 'err' : ''; }

  /* ── 고르기 · 일정 ── */
  function renderMine() {
    if (!T.camps.length) { $('tpMine').innerHTML = '<div class="tp-empty">「내 캠핑장」이 비어 있습니다 — 빈자리 탭에서 캠핑장을 검색해 ☆ 담기를 누르세요.</div>'; return; }
    var inPlan = {}; plan.forEach(function (p) { inPlan[p.id] = 1; });
    $('tpMine').innerHTML = T.camps.map(function (c) {
      var img = c.thumb ? '<img src="' + esc(c.thumb) + '" alt="">' : '<span class="ph"></span>';
      var dis = !inPlan[c.id] && plan.length >= T.maxCamps;
      return '<button type="button" data-id="' + esc(c.id) + '" class="' + (inPlan[c.id] ? 'in' : '') + '"' + (dis ? ' disabled' : '') + '>' + img + esc(c.name) + (inPlan[c.id] ? ' ✓' : '') + '</button>';
    }).join('');
  }
  function renderPlan() {
    if (!plan.length) { $('tpPlan').innerHTML = '<div class="tp-empty">위에서 캠핑장을 누르면 여기에 일정으로 들어갑니다 (예: ① A 2박 → ② B 1박).</div>'; $('tpGo').disabled = true; return; }
    $('tpGo').disabled = false;
    $('tpPlan').innerHTML = plan.map(function (p, i) {
      var c = byId[p.id] || { name: '?' }, opts = '';
      for (var k = 1; k <= T.maxNights; k++) opts += '<option value="' + k + '"' + (k === p.n ? ' selected' : '') + '>' + k + '박</option>';
      return (i ? '<div class="tp-arrow">▼ 이어서</div>' : '') +
        '<div class="tp-leg" data-i="' + i + '"><span class="no">' + (i + 1) + '</span><span class="nm">' + esc(c.name) + '<small>' + esc(c.region || '') + '</small></span>' +
        '<select data-act="n">' + opts + '</select>' +
        '<button type="button" data-act="up" title="앞으로"' + (i ? '' : ' disabled') + '>↑</button>' +
        '<button type="button" data-act="down" title="뒤로"' + (i < plan.length - 1 ? '' : ' disabled') + '>↓</button>' +
        '<button type="button" data-act="del" title="빼기">✕</button></div>';
    }).join('');
  }
  $('tpMine').addEventListener('click', function (e) {
    var b = e.target.closest('button[data-id]'); if (!b || b.disabled) return;
    var id = b.getAttribute('data-id'), at = plan.findIndex(function (p) { return p.id === id; });
    if (at >= 0) plan.splice(at, 1); else plan.push({ id: id, n: 1 });
    renderMine(); renderPlan(); syncUrl();
  });
  $('tpPlan').addEventListener('click', function (e) {
    var b = e.target.closest('button[data-act]'); if (!b) return;
    var i = +b.closest('.tp-leg').getAttribute('data-i'), a = b.getAttribute('data-act');
    if (a === 'del') plan.splice(i, 1);
    else if (a === 'up' && i > 0) plan.splice(i - 1, 0, plan.splice(i, 1)[0]);
    else if (a === 'down' && i < plan.length - 1) plan.splice(i + 1, 0, plan.splice(i, 1)[0]);
    renderMine(); renderPlan(); syncUrl();
  });
  $('tpPlan').addEventListener('change', function (e) {
    if (e.target.getAttribute('data-act') !== 'n') return;
    plan[+e.target.closest('.tp-leg').getAttribute('data-i')].n = +e.target.value; syncUrl();
  });
  ['tpFrom', 'tpN', 'tpSame', 'tpPerm', 'tpPick'].forEach(function (id) { $(id).addEventListener('change', syncUrl); });
  function opts() { return { from: $('tpFrom').value, n: +$('tpN').value, same: $('tpSame').checked, perm: $('tpPerm').checked, pick: $('tpPick').checked }; }
  function syncUrl() {
    var o = opts(), q = new URLSearchParams({ mode: 'trip' });
    if (plan.length) q.set('c', plan.map(function (p) { return p.id + ':' + p.n; }).join(','));
    q.set('from', o.from); q.set('n', o.n); q.set('same', o.same ? 1 : 0); q.set('perm', o.perm ? 1 : 0); q.set('pick', o.pick ? 1 : 0);
    history.replaceState(null, '', '?' + q.toString());
  }

  /* ── 받기 — 캠핑장 × 달(마지막 달 다음 달까지: 끝에서 출발한 일정의 뒷 구간) · 받은 것은 기록에도 남긴다 ── */
  var DATA = {};   // campId → { sites:[{id,name}], st:{siteId:{date:'A'|...}} }
  function load(o) {
    var months = []; for (var k = 0; k <= o.n; k++) months.push(ymAdd(o.from, k));
    var jobs = [];
    plan.forEach(function (p) { months.forEach(function (ym) { if (RsvSnap.range(ym)) jobs.push([p.id, ym]); }); });
    var done = 0; DATA = {};
    return jobs.reduce(function (chain, j) {
      return chain.then(function () {
        st('캠핏에서 받는 중… (' + (++done) + '/' + jobs.length + ' · ' + (byId[j[0]] || {}).name + ' ' + (+j[1].slice(5)) + '월)');
        return RsvSnap.fetchMonth(j[0], j[1]).then(function (r) {
          var D = DATA[j[0]] || (DATA[j[0]] = { sites: [], seen: {}, st: {} });
          (r.data.sites || []).forEach(function (s) {
            if (!D.seen[s.id]) { D.seen[s.id] = 1; D.sites.push({ id: s.id, name: s.name }); }
            var m = D.st[s.id] || (D.st[s.id] = {});
            (s.availability || []).forEach(function (a) { m[a.date] = a.status; });
          });
          RsvSnap.save(j[0], j[1], r.start, r.end, r.data, { name: (byId[j[0]] || {}).name || '' }).catch(function () {});
        });
      });
    }, Promise.resolve());
  }

  /* ── 판정 ── 한 구간: 캠핑장 c 에서 d 부터 n 박 */
  function leg(c, d, n, o) {
    var D = DATA[c]; if (!D) return null;
    var pk = (o.pick && (T.picks[c] || []).length) ? T.picks[c] : null;
    var sites = D.sites.filter(function (s) { return !pk || pk.indexOf(s.id) >= 0; });
    function free(s, day) { return (D.st[s.id] || {})[day] === 'available'; }
    if (o.same || n === 1) {
      var ok = sites.filter(function (s) { for (var k = 0; k < n; k++) if (!free(s, add(d, k))) return false; return true; });
      return ok.length ? { camp: c, d: d, n: n, count: ok.length, nights: [ok.map(function (s) { return s.name; })] } : null;
    }
    var nights = [], minC = 1e9;
    for (var k = 0; k < n; k++) {
      var f = sites.filter(function (s) { return free(s, add(d, k)); });
      if (!f.length) return null;
      nights.push(f.map(function (s) { return s.name; })); minC = Math.min(minC, f.length);
    }
    return { camp: c, d: d, n: n, count: minC, nights: nights };
  }
  function perms(a) {
    if (a.length <= 1) return [a.slice()];
    var out = [];
    a.forEach(function (x, i) { perms(a.slice(0, i).concat(a.slice(i + 1))).forEach(function (r) { out.push([x].concat(r)); }); });
    return out;
  }
  function route(order, start, o) {
    var d = start, legs = [];
    for (var i = 0; i < order.length; i++) {
      var l = leg(order[i].id, d, order[i].n, o); if (!l) return null;
      legs.push(l); d = add(d, order[i].n);
    }
    return { legs: legs, count: Math.min.apply(null, legs.map(function (l) { return l.count; })), end: d };
  }

  /* ── 찾기 ── 순서마다 따로 (2026-10-23 사용자 — 「A→B · B→A 이런 식으로 분류해서」)
   *    RES.groups = [{ order:[{id,n}…], res:{date: route} }] · 순서 바꾸기를 끄면 정한 순서 하나 */
  var RES = null;
  $('tpGo').addEventListener('click', function () {
    if (!plan.length) return;
    var o = opts(), btn = this; btn.disabled = true; $('tpRes').style.display = 'none';
    load(o).then(function () {
      var today = ymd(new Date()), first = o.from + '-01'; if (first < today) first = today;
      var last = add(ymAdd(o.from, o.n) + '-01', -1);
      var orders = o.perm ? perms(plan) : [plan.slice()];
      var groups = orders.map(function (ord) {
        var res = {};
        for (var d = first; d <= last; d = add(d, 1)) { var r = route(ord, d, o); if (r) res[d] = r; }
        return { order: ord, res: res, sel: Object.keys(res)[0] || null };
      });
      /* 정한 순서가 맨 앞 · 나머지는 출발일 많은 순 */
      var head = groups.shift();
      groups.sort(function (x, y) { return Object.keys(y.res).length - Object.keys(x.res).length; });
      groups.unshift(head);
      RES = { groups: groups, o: o, first: first, last: last };
      st('');
      draw();
    }).catch(function (e) { st(e.message, true); }).then(function () { btn.disabled = false; });
  });

  function orderLabel(ord) { return ord.map(function (p) { return esc((byId[p.id] || {}).name) + ' ' + p.n + '박'; }).join(' → '); }
  function draw() {
    var R = RES, box = $('tpRes');
    box.style.display = '';
    var total = plan.reduce(function (s, p) { return s + p.n; }, 0);
    var html = '<div class="tp-sum">총 ' + total + '박 · ' + (R.groups.length > 1 ? '순서 ' + R.groups.length + '가지로 나눠 봅니다' : '정한 순서로 찾았습니다') +
      ' <span style="color:#95a5a6;font-size:12px">(숫자 = 가장 빠듯한 구간의 빈 사이트 수 · 주황 테두리 = 금·토 출발 · 날짜를 누르면 그 묶음 아래에 일정)</span></div>';
    /* 순서별 요약 한 줄 — 눌러서 그 묶음으로 */
    if (R.groups.length > 1) {
      html += '<div class="tp-ix">' + R.groups.map(function (g, k) {
        var n = Object.keys(g.res).length;
        return '<a href="#tpG' + k + '" class="' + (n ? '' : 'none') + '"><span class="ord">' + String.fromCharCode(65 + k) + '</span>' + orderLabel(g.order) + ' <b>' + n + '일</b></a>';
      }).join('') + '</div>';
    }
    html += R.groups.map(function (g, k) { return group(g, k); }).join('');
    box.innerHTML = html;
    R.groups.forEach(function (g, k) { detail(k); });
  }
  function group(g, k) {
    var R = RES, keys = Object.keys(g.res);
    var wk = keys.filter(function (d) { var w = wd(d); return w === 5 || w === 6; }).length;
    var h = '<section class="tp-grp" id="tpG' + k + '" data-g="' + k + '"><h3 class="tp-gh">' + (R.groups.length > 1 ? '<span class="ord">' + String.fromCharCode(65 + k) + '</span>' : '') +
      orderLabel(g.order) + ' <small>출발 가능 <b>' + keys.length + '일</b>' + (wk ? ' · <span class="wk">금·토 출발 ' + wk + '일</span>' : '') + '</small></h3>';
    if (!keys.length) return h + '<div class="tp-empty">이 순서로는 이 기간에 이어서 머물 수 있는 날이 없습니다.</div></section>';
    h += '<div class="mc-wrap">';
    for (var ym = R.first.slice(0, 7); ym <= R.last.slice(0, 7); ym = ymAdd(ym, 1)) {
      var f = parse(ym + '-01'), nd = new Date(f.getFullYear(), f.getMonth() + 1, 0).getDate(), lead = f.getDay(), cnt = 0, cells = '';
      for (var c = 0; c < Math.ceil((lead + nd) / 7) * 7; c++) {
        if (c % 7 === 0) cells += '<tr>';
        var dn = c - lead + 1;
        if (dn < 1 || dn > nd) cells += '<td class="e"></td>';
        else {
          var d = ym + '-' + pad(dn), r = g.res[d];
          if (r) { cnt++; var w = c % 7; cells += '<td class="ok' + (w === 5 || w === 6 ? ' wk' : '') + (d === g.sel ? ' sel' : '') + '" data-d="' + d + '" title="' + md(d) + ' 출발 ~ ' + md(r.end) + ' 퇴실"><i>' + dn + '</i><b>' + r.count + '</b></td>'; }
          else cells += '<td class="x' + (d < R.first ? ' p' : '') + '"><i>' + dn + '</i></td>';
        }
        if (c % 7 === 6) cells += '</tr>';
      }
      h += '<div class="mc"><div class="mc-t"><b>' + (+ym.slice(5)) + '월</b><span>출발 가능 ' + cnt + '일</span></div><table><tr>' +
        WD.map(function (x, i) { return '<th' + (i === 0 ? ' class="su"' : i >= 5 ? ' class="fs"' : '') + '>' + x + '</th>'; }).join('') + '</tr>' + cells + '</table></div>';
    }
    h += '</div><div class="tp-det" id="tpDet' + k + '"></div>';
    h += '<details class="tp-more"><summary>출발일 목록 ' + keys.length + '일</summary><table class="tp-list">' + keys.map(function (d) {
      var r = g.res[d], w = wd(d);
      return '<tr><td class="d' + (w === 0 ? ' sun' : w === 6 ? ' sat' : '') + '" data-d="' + d + '">' + md(d) + '(' + WD[w] + ') ~ ' + md(r.end) + '</td><td>' +
        r.legs.map(function (l) { return md(l.d) + ' ' + esc((byId[l.camp] || {}).name) + ' ' + l.n + '박'; }).join(' → ') + '</td><td class="n">' + r.count + '</td></tr>';
    }).join('') + '</table></details>';
    return h + '</section>';
  }
  function detail(k) {
    var g = RES.groups[k], el = $('tpDet' + k); if (!el || !g.sel) return;
    var r = g.res[g.sel];
    el.innerHTML = '<div class="tp-route"><div class="hd">' + md(g.sel) + '(' + WD[wd(g.sel)] + ') 출발 ~ ' + md(r.end) + '(' + WD[wd(r.end)] + ') 퇴실</div>' +
      r.legs.map(function (l, i) {
        var out = add(l.d, l.n);
        var sites = l.nights.length === 1
          ? l.nights[0].map(function (n) { return '<span>' + esc(n) + '</span>'; }).join('')
          : l.nights.map(function (ns, k2) { return md(add(l.d, k2)) + ' 밤: ' + ns.map(function (n) { return '<span>' + esc(n) + '</span>'; }).join(''); }).join('<br>');
        return '<div class="tp-step"><div class="when">' + (i + 1) + '. ' + md(l.d) + '(' + WD[wd(l.d)] + ') ~ ' + md(out) + '(' + WD[wd(out)] + ')<small>' + l.n + '박</small></div>' +
          '<div><div class="camp">' + esc((byId[l.camp] || {}).name) + ' <span style="color:#0e9257;font-weight:400;font-size:12px">' +
          (l.nights.length === 1 ? (l.n > 1 ? '연박 가능 사이트 ' : '빈 사이트 ') + l.count + '곳' : '밤마다 빈 사이트') + '</span></div><div class="sites">' + sites + '</div></div></div>';
      }).join('') + '</div>';
  }
  $('tpRes').addEventListener('click', function (e) {
    var t = e.target.closest('[data-d]'); if (!t || !RES) return;
    var sec = t.closest('.tp-grp'); if (!sec) return;
    var k = +sec.getAttribute('data-g'), g = RES.groups[k];
    g.sel = t.getAttribute('data-d');
    sec.querySelectorAll('td.sel').forEach(function (x) { x.classList.remove('sel'); });
    var c = sec.querySelector('td.ok[data-d="' + g.sel + '"]'); if (c) c.classList.add('sel');
    detail(k);
    $('tpDet' + k).scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  });

  renderMine(); renderPlan();
  if (plan.length) $('tpGo').click();   // 주소에 일정이 있으면 바로 찾는다(북마크·새로고침)
})();
</script>
JS;
    echo '</body></html>';
}
?>
