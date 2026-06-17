<?php
// naver_trend.php — 네이버 맛집 추이 대시보드 (라우터+뷰).
//   회차(period)별 맛집 평점/리뷰/방문/블로그/저장 + 직전 회차 대비 델타를 표/차트로 표시.
//   데이터는 naver_trend_api.php (action=periods/list/series) 에서 fetch. PHP 변수 주입 없음 → NOWDOC.
require_once "./env/cnt.inc";
require_once "./env/auth_fnc.php";
require_login();
require_once "./env/nav.inc";

$current_user = $_SESSION['usr_name'] ?? '';

echo '<!DOCTYPE html><html lang="ko"><head>';
echo '<meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>';
echo '<title>맛집 추이</title>';
echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.css"/>';
echo '<script src="/env/js/apexcharts.js"></script>';
nav_css();
echo <<<'HEAD'
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font-family: Pretendard, -apple-system, sans-serif; background: #f4f6f9; color: #2c3440; }
  .nt-wrap { max-width: 1180px; margin: 0 auto; padding: 16px 16px 60px; }
  .nt-head { display: flex; align-items: baseline; gap: 10px; margin: 8px 0 14px; flex-wrap: wrap; }
  .nt-head h1 { font-size: 21px; margin: 0; font-weight: 800; }
  .nt-head .sub { color: #8a97a3; font-size: 13px; }
  /* 컨트롤바 */
  .nt-ctl { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; background: #fff; border: 1px solid #e6eaf0;
            border-radius: 12px; padding: 10px 12px; margin-bottom: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
  .nt-ctl label { font-size: 12px; color: #6a7686; margin-right: 3px; }
  .nt-ctl select, .nt-ctl input { font: inherit; font-size: 13px; padding: 6px 9px; border: 1px solid #d4dae2;
            border-radius: 8px; background: #fff; color: #2c3440; }
  .nt-ctl input.region { width: 130px; }
  .nt-ctl .grow { flex: 1; }
  .nt-cnt { font-size: 13px; color: #8a97a3; }
  /* 표 */
  .nt-tbl { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,.05); }
  .nt-tbl th, .nt-tbl td { padding: 9px 10px; text-align: right; font-size: 13px; border-bottom: 1px solid #eef1f5; white-space: nowrap; }
  .nt-tbl th { background: #fafbfc; color: #6a7686; font-weight: 700; font-size: 12px; cursor: pointer; user-select: none; }
  .nt-tbl th.s-on { color: #2979ff; }
  .nt-tbl th:first-child, .nt-tbl td:first-child { text-align: center; color: #aab3bf; width: 34px; }
  .nt-tbl td.name { text-align: left; max-width: 280px; }
  .nt-tbl td.name .nm { font-weight: 700; }
  .nt-tbl td.name .rg { color: #9aa6b2; font-size: 11.5px; margin-left: 6px; }
  .nt-tbl tbody tr { cursor: pointer; }
  .nt-tbl tbody tr:hover { background: #f3f8ff; }
  .val { font-variant-numeric: tabular-nums; font-weight: 600; }
  .dlt { font-size: 11.5px; font-variant-numeric: tabular-nums; margin-left: 5px; }
  .up   { color: #e8493f; }   /* 증가 = 빨강(국내 관습) */
  .down { color: #2f7bd6; }   /* 감소 = 파랑 */
  .flat { color: #b5bdc8; }
  .nt-empty { text-align: center; color: #9aa6b2; padding: 50px 12px; font-size: 14px; }
  .nt-new { display: inline-block; font-size: 10px; font-weight: 800; color: #fff; background: #2bb673; border-radius: 8px; padding: 1px 6px; margin-left: 6px; vertical-align: middle; }
  /* 차트 모달 */
  .nt-modal { position: fixed; inset: 0; background: rgba(20,28,40,.5); display: none; align-items: center; justify-content: center; z-index: 200; }
  .nt-modal.on { display: flex; }
  .nt-card { background: #fff; border-radius: 14px; width: 640px; max-width: 94vw; max-height: 90vh; overflow: auto; box-shadow: 0 12px 40px rgba(0,0,0,.25); }
  .nt-card-h { display: flex; justify-content: space-between; align-items: center; padding: 14px 18px; border-bottom: 1px solid #eef1f5; }
  .nt-card-h .t { font-weight: 800; font-size: 16px; }
  .nt-card-h .x { background: none; border: none; font-size: 22px; color: #aab3bf; cursor: pointer; line-height: 1; }
  .nt-card-b { padding: 10px 14px 18px; }
  @media (max-width: 720px) {
    .nt-tbl .col-opt { display: none; }   /* 좁은 화면: 방문/블로그 숨김 */
  }
</style>
HEAD;
echo '</head><body>';
render_nav('naver_trend');
echo <<<'BODY'
<div class="nt-wrap">
  <div class="nt-head">
    <h1>맛집 추이</h1>
    <span class="sub">네이버 맛집 평점·리뷰·저장수의 회차별 변화 (직전 회차 대비)</span>
  </div>

  <div class="nt-ctl">
    <span><label>회차</label><select id="ntPeriod"></select></span>
    <span><label>지역</label><input class="region" id="ntRegion" type="text" placeholder="예: 강남구" /></span>
    <span><label>최소리뷰</label>
      <select id="ntMinRev">
        <option value="0">전체</option>
        <option value="500">500+</option>
        <option value="1000" selected>1,000+</option>
        <option value="3000">3,000+</option>
      </select>
    </span>
    <span><label>정렬</label>
      <select id="ntSort">
        <option value="d_review">리뷰 증가순</option>
        <option value="d_save">저장 증가순</option>
        <option value="d_score">평점 상승순</option>
        <option value="d_visitor">방문 증가순</option>
        <option value="review">리뷰 많은순</option>
        <option value="score">평점 높은순</option>
      </select>
    </span>
    <span class="grow"></span>
    <span class="nt-cnt" id="ntCount"></span>
  </div>

  <div id="ntBody"></div>
</div>

<div class="nt-modal" id="ntModal" onclick="if(event.target===this)ntCloseChart()">
  <div class="nt-card">
    <div class="nt-card-h"><span class="t" id="ntChartTitle">추이</span><button class="x" onclick="ntCloseChart()">&times;</button></div>
    <div class="nt-card-b"><div id="ntChart"></div></div>
  </div>
</div>

<script>
var API = '/naver_trend_api.php';
var ntChart = null;

function ntEsc(s){ return String(s==null?'':s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
function ntFmt(n){ return (n==null) ? '-' : Number(n).toLocaleString(); }

// 델타 칩: 양수 빨강▲ / 음수 파랑▼ / 0·null 회색. isFloat=평점(소수1자리)
function ntDelta(d, isFloat){
  if (d == null) return '<span class="dlt new"></span>';           // 신규(직전 없음)
  var z = Number(d);
  if (z === 0) return '<span class="dlt flat">·</span>';
  var v = isFloat ? Math.abs(z).toFixed(2) : ntFmt(Math.abs(z));
  return z > 0 ? '<span class="dlt up">▲'+v+'</span>' : '<span class="dlt down">▼'+v+'</span>';
}
function ntCell(val, d, isFloat){
  var nv = (isFloat && val!=null) ? Number(val).toFixed(2) : ntFmt(val);
  return '<span class="val">'+nv+'</span>'+ntDelta(d, isFloat);
}

function ntLoad(){
  var p   = document.getElementById('ntPeriod').value;
  var rg  = document.getElementById('ntRegion').value.trim();
  var mr  = document.getElementById('ntMinRev').value;
  var srt = document.getElementById('ntSort').value;
  var q = API + '?action=list&period=' + encodeURIComponent(p)
        + '&region=' + encodeURIComponent(rg)
        + '&min_review=' + encodeURIComponent(mr)
        + '&sort=' + encodeURIComponent(srt) + '&limit=300';
  document.getElementById('ntBody').innerHTML = '<div class="nt-empty">불러오는 중…</div>';
  fetch(q).then(function(r){ return r.json(); }).then(function(j){
    if (j.error) { document.getElementById('ntBody').innerHTML = '<div class="nt-empty">오류: '+ntEsc(j.error)+'</div>'; return; }
    ntRender(j.rows || []);
    document.getElementById('ntCount').textContent = (j.total||0).toLocaleString() + '곳';
  }).catch(function(e){ document.getElementById('ntBody').innerHTML = '<div class="nt-empty">불러오기 실패: '+ntEsc(e.message)+'</div>'; });
}

function ntRender(rows){
  if (!rows.length){ document.getElementById('ntBody').innerHTML = '<div class="nt-empty">이 조건에 데이터가 없습니다. (수집 회차가 1개뿐이면 델타는 다음 달부터 표시됩니다)</div>'; return; }
  var srt = document.getElementById('ntSort').value;
  var on = function(k){ return srt===k ? ' class="s-on"' : ''; };
  var h = '<table class="nt-tbl"><thead><tr>'
        + '<th>#</th><th style="text-align:left">맛집</th>'
        + '<th'+on('score')+' data-s="score">평점</th>'
        + '<th'+on('review')+' data-s="review">리뷰</th>'
        + '<th class="col-opt"'+on('d_visitor')+' data-s="d_visitor">방문</th>'
        + '<th class="col-opt" data-s="d_blog">블로그</th>'
        + '<th'+on('d_save')+' data-s="d_save">저장</th>'
        + '</tr></thead><tbody>';
  rows.forEach(function(r, i){
    var isNew = (r.prev_period == null);
    h += '<tr onclick="ntOpenChart(\''+ntEsc(r.naver_id)+'\',\''+ntEsc(r.name).replace(/'/g,"\\'")+'\')">'
       + '<td>'+(i+1)+'</td>'
       + '<td class="name"><span class="nm">'+ntEsc(r.name)+'</span>'
         + (r.region?'<span class="rg">'+ntEsc(r.region)+'</span>':'')
         + (isNew?'<span class="nt-new">NEW</span>':'') + '</td>'
       + '<td>'+ntCell(r.score, r.d_score, true)+'</td>'
       + '<td>'+ntCell(r.review, r.d_review, false)+'</td>'
       + '<td class="col-opt">'+ntCell(r.visitor, r.d_visitor, false)+'</td>'
       + '<td class="col-opt">'+ntCell(r.blog, r.d_blog, false)+'</td>'
       + '<td>'+ntCell(r.save, r.d_save, false)+'</td>'
       + '</tr>';
  });
  h += '</tbody></table>';
  document.getElementById('ntBody').innerHTML = h;
  // 헤더 클릭 정렬
  Array.prototype.forEach.call(document.querySelectorAll('.nt-tbl th[data-s]'), function(th){
    th.addEventListener('click', function(){ document.getElementById('ntSort').value = th.getAttribute('data-s'); ntLoad(); });
  });
}

function ntOpenChart(nid, name){
  document.getElementById('ntChartTitle').textContent = name + ' — 추이';
  document.getElementById('ntModal').classList.add('on');
  fetch(API + '?action=series&nid=' + encodeURIComponent(nid)).then(function(r){ return r.json(); }).then(function(j){
    var s = j.series || [];
    var cats = s.map(function(x){ return x.period; });
    var opt = {
      chart: { height: 320, type: 'line', toolbar: { show: false }, fontFamily: 'Pretendard' },
      stroke: { width: [3,3,2], curve: 'smooth' },
      colors: ['#2979ff', '#2bb673', '#d9a441'],
      series: [
        { name: '리뷰(총)', type: 'line', data: s.map(function(x){ return Number(x.review||0); }) },
        { name: '저장', type: 'line', data: s.map(function(x){ return Number(x.save||0); }) },
        { name: '평점', type: 'line', data: s.map(function(x){ return Number(x.score||0); }) }
      ],
      yaxis: [
        { seriesName: '리뷰(총)', title: { text: '리뷰·저장' }, labels: { formatter: function(v){ return Number(v).toLocaleString(); } } },
        { seriesName: '저장', show: false },
        { opposite: true, seriesName: '평점', min: 0, max: 5, title: { text: '평점' }, decimalsInFloat: 2 }
      ],
      xaxis: { categories: cats },
      legend: { position: 'top' },
      noData: { text: '시계열 데이터가 없습니다' }
    };
    if (ntChart) { ntChart.destroy(); ntChart = null; }
    document.getElementById('ntChart').innerHTML = '';
    ntChart = new ApexCharts(document.getElementById('ntChart'), opt);
    ntChart.render();
  });
}
function ntCloseChart(){ document.getElementById('ntModal').classList.remove('on'); if(ntChart){ ntChart.destroy(); ntChart=null; } }
document.addEventListener('keydown', function(e){ if(e.key==='Escape') ntCloseChart(); });

// 컨트롤 이벤트
['ntPeriod','ntMinRev','ntSort'].forEach(function(id){ document.getElementById(id).addEventListener('change', ntLoad); });
var rgTimer = null;
document.getElementById('ntRegion').addEventListener('input', function(){ clearTimeout(rgTimer); rgTimer = setTimeout(ntLoad, 350); });

// 회차 목록 로드 → 첫 로드
fetch(API + '?action=periods').then(function(r){ return r.json(); }).then(function(j){
  var sel = document.getElementById('ntPeriod');
  var ps = j.periods || [];
  if (!ps.length){ document.getElementById('ntBody').innerHTML = '<div class="nt-empty">아직 수집된 회차가 없습니다. cron_naver_collect.php 실행 후 표시됩니다.</div>'; return; }
  sel.innerHTML = ps.map(function(p){ return '<option value="'+p+'">'+p+'</option>'; }).join('');
  ntLoad();
});
</script>
BODY;
echo '</body></html>';
?>
