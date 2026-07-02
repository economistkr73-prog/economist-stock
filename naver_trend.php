<?php
// naver_trend.php — 네이버 맛집 추이 대시보드 (라우터+뷰).
//   회차(period)별 맛집 평점/리뷰/방문/블로그/저장 + 직전 회차 대비 델타를 표/차트로 표시.
//   데이터는 naver_trend_api.php (action=periods/list/series) 에서 fetch. PHP 변수 주입 없음 → NOWDOC.
require_once "./env/cnt.inc";
require_once "./env/auth_fnc.php";
if (file_exists("./env/maps.inc")) require_once "./env/maps.inc";   // 지도(네이버 다이내믹) 클라이언트 키
$NT_NAVER_KEY = defined('NAVER_MAPS_CLIENT_ID') ? NAVER_MAPS_CLIENT_ID : '';

// ── 한시적 공개 공유: 비로그인 게스트 읽기전용 보기 (로그인 게이트보다 먼저) ──
$NT_SHARE = false; $NT_SHARE_CAT = 'food'; $NT_SHARE_EXP = '';
$NT_SHARE_REGION = ''; $NT_SHARE_MINREV = 0; $NT_SHARE_SORT = 'total_score'; $NT_SHARE_TAGS = [];
$shareToken = $_GET['share'] ?? '';
if ($shareToken !== '') {
    $col = new NaverPlaceCollector($pdo);
    $col->ensureTables();
    $sh = $col->getValidShare($shareToken);
    if ($sh) {
        $NT_SHARE = true;
        $NT_SHARE_CAT = $sh['cat'] ?: 'food';
        $NT_SHARE_EXP = $sh['expires_at'] ?? '';
        $NT_SHARE_REGION = (string)($sh['region'] ?? '');
        $NT_SHARE_MINREV = (int)($sh['min_review'] ?? 0);
        $NT_SHARE_SORT = (string)($sh['sort'] ?? 'total_score');
        // 공유시점 태그(파이프 조인) → 배열. 게스트 헤더·목록 필터 고정용.
        $NT_SHARE_TAGS = array_values(array_filter(explode('|', (string)($sh['tags'] ?? '')), fn($t) => $t !== ''));
    } else {
        // 만료/무효 링크
        echo '<!DOCTYPE html><meta charset="utf-8"><title>공유 링크 만료</title>';
        echo '<div style="max-width:480px;margin:80px auto;font-family:sans-serif;text-align:center;color:#444;">';
        echo '<h2 style="font-size:20px;">🔗 만료되었거나 유효하지 않은 링크입니다</h2>';
        echo '<p style="color:#888;">공유 기간이 끝났거나 취소된 링크예요.</p></div>';
        exit;
    }
}
if (!$NT_SHARE) {
    require_login();
    require_once "./env/nav.inc";
}

$current_user = $_SESSION['usr_name'] ?? '';

echo '<!DOCTYPE html><html lang="ko"><head>';
echo '<meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>';
if ($NT_SHARE) {
    $catLbl = NaverPlaceCollector::CATS[$NT_SHARE_CAT]['label'] ?? '맛집';
    $pgTitle = trim(($NT_SHARE_REGION !== '' ? $NT_SHARE_REGION . ' ' : '') . $catLbl);
    if ($NT_SHARE_TAGS) $pgTitle .= '(' . implode('·', $NT_SHARE_TAGS) . ')';   // 예: 영등포구 맛집(한식)
    echo '<title>' . htmlspecialchars($pgTitle) . '</title>';
} else {
    echo '<title>네이버 추이</title>';
}
echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.css"/>';
echo '<script src="/env/js/apexcharts.js"></script>';
if (!$NT_SHARE) nav_css();
echo <<<'HEAD'
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font-family: Pretendard, -apple-system, sans-serif; background: #f4f6f9; color: #2c3440; }
  .nt-wrap { max-width: 1180px; margin: 0 auto; padding: 16px 16px 60px; }
  .nt-head { display: flex; align-items: baseline; gap: 10px; margin: 8px 0 14px; flex-wrap: wrap; }
  .nt-head h1 { font-size: 21px; margin: 0; font-weight: 800; }
  .nt-head .sub { color: #8a97a3; font-size: 13px; }
  /* 카테고리 탭 */
  .nt-tabs { display: flex; gap: 6px; margin-bottom: 12px; flex-wrap: wrap; }
  .nt-tabs button { font: inherit; font-size: 14px; font-weight: 700; padding: 8px 16px; border-radius: 999px;
            border: 1px solid #d4dae2; background: #fff; color: #6a7686; cursor: pointer; transition: all .12s; }
  .nt-tabs button:hover { border-color: #2979ff; color: #2979ff; }
  .nt-tabs button.on { background: #2979ff; border-color: #2979ff; color: #fff; }
  /* 컨트롤바 */
  .nt-ctl { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; background: #fff; border: 1px solid #e6eaf0;
            border-radius: 12px; padding: 10px 12px; margin-bottom: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
  .nt-ctl label { font-size: 12px; color: #6a7686; margin-right: 3px; }
  .nt-ctl select, .nt-ctl input { font: inherit; font-size: 13px; padding: 6px 9px; border: 1px solid #d4dae2;
            border-radius: 8px; background: #fff; color: #2c3440; }
  .nt-ctl input.region { width: 130px; }
  .nt-ctl .grow { flex: 1; }
  .nt-cnt { font-size: 12px; font-weight: 700; color: #4a5663; background: #eef2f7; border: 1px solid #e0e6ee;
            border-radius: 999px; padding: 5px 12px; white-space: nowrap; }
  .nt-cnt:empty { display: none; }
  .nt-mapcnt { color: #2f8f4e; background: #eaf7ee; border-color: #cde9d6; }
  .nt-mapbtn { font: inherit; font-size: 13px; font-weight: 600; padding: 6px 13px; border: 1px solid #2f8f4e;
               background: #27ae60; color: #fff; border-radius: 999px; cursor: pointer; white-space: nowrap; }
  .nt-mapbtn:hover { background: #229152; }
  /* 지도 기준 모달 */
  .nm-row { display: flex; align-items: center; gap: 12px; padding: 10px 4px; }
  .nm-lbl { width: 64px; font-size: 13px; color: #6a7686; font-weight: 600; flex-shrink: 0; }
  .nm-opts { display: flex; flex-wrap: wrap; gap: 7px; }
  .nm-opt { font: inherit; font-size: 13px; padding: 6px 13px; border: 1px solid #d4dae2; background: #fff;
            color: #44505d; border-radius: 999px; cursor: pointer; }
  .nm-opt:hover { border-color: #27ae60; }
  .nm-opt.on { background: #27ae60; border-color: #27ae60; color: #fff; font-weight: 600; }
  .nm-hint { font-size: 12px; color: #9aa6b2; padding: 8px 4px 4px; }
  .nm-act { padding: 14px 4px 2px; text-align: right; }
  .nm-apply { font: inherit; font-size: 14px; font-weight: 700; padding: 9px 20px; border: none; background: #27ae60;
              color: #fff; border-radius: 9px; cursor: pointer; }
  .nm-apply:hover { background: #229152; }
  /* 표 */
  .nt-tbl { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,.05); }
  .nt-tbl th, .nt-tbl td { padding: 9px 10px; text-align: right; font-size: 13px; border-bottom: 1px solid #eef1f5; white-space: nowrap; }
  .nt-tbl th { background: #fafbfc; color: #6a7686; font-weight: 700; font-size: 12px; user-select: none; }
  .nt-tbl th.sortable { cursor: pointer; }
  .nt-tbl th.sortable:hover { color: #2979ff; background: #f0f4fa; }
  .nt-tbl th.s-on { color: #2979ff; }
  .nt-tbl th .sar { margin-left: 3px; font-size: 11px; color: #c2cad4; }        /* 미정렬 ↕ */
  .nt-tbl th.s-on .sar.von { color: #2979ff; }                                   /* 값 정렬 ▼ */
  .nt-tbl th.tot.s-on .sar.von { color: #6a3fb5; }
  .nt-tbl th .sar.don { color: #e1234a; font-weight: 700; }                      /* 증가순(Δ)·급등 정렬 */
  .nt-tbl th .sar.down2 { color: #2979ff; font-weight: 700; }                    /* 급락 정렬 */
  .nt-sorthint { color: #9aa6b2; font-size: 12px; }
  .nt-sortline { text-align: right; margin: -6px 2px 12px; }
  /* 태그(칩) 필터 바 */
  .nt-tagbar { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; margin: -6px 0 14px; }
  .nt-tagbar:empty { display: none; }
  .nt-tagbar .ntt-lbl { font-size: 12px; color: #6a7686; font-weight: 700; margin-right: 2px; }
  .ntt-chip { font: inherit; font-size: 12.5px; padding: 5px 11px; border: 1px solid #d4dae2; background: #fff;
              color: #4a5663; border-radius: 999px; cursor: pointer; transition: all .12s; white-space: nowrap; }
  .ntt-chip:hover { border-color: #2979ff; color: #2979ff; }
  .ntt-chip.on { background: #2979ff; border-color: #2979ff; color: #fff; font-weight: 700; }
  .ntt-chip .ntt-c { margin-left: 5px; font-size: 11px; opacity: .65; font-variant-numeric: tabular-nums; }
  .ntt-more { font: inherit; font-size: 12.5px; padding: 5px 10px; border: none; background: none;
              color: #2979ff; cursor: pointer; font-weight: 700; }
  .nt-tbl th:first-child, .nt-tbl td:first-child { text-align: center; color: #aab3bf; width: 48px; }
  .nt-tbl td.rank { line-height: 1.2; font-weight: 700; color: #6a7686; }
  .rkmv { display: block; font-size: 10.5px; font-weight: 800; margin-top: 1px; font-variant-numeric: tabular-nums; }
  .rkmv.up   { color: #e1234a; }   /* 순위 상승 ▲ */
  .rkmv.down { color: #2979ff; }   /* 순위 하락 ▼ */
  .rkmv.flat { color: #c2cad4; font-weight: 600; }
  .rkmv.new  { color: #2bb673; }   /* 신규 NEW */
  .nt-tbl td.name { text-align: left; max-width: 280px; }
  .nt-tbl td.name .nm { font-weight: 700; }
  .nt-tbl td.name a.nm { color: #2c3440; text-decoration: none; }
  .nt-tbl td.name a.nm:hover { color: #2979ff; text-decoration: underline; }
  .nt-tbl td.name .addr { display: block; color: #9aa6b2; font-size: 11.5px; margin-top: 2px; max-width: 280px; overflow: hidden; text-overflow: ellipsis; }
  .nt-tbl td.name .rg { color: #9aa6b2; font-size: 11.5px; margin-left: 6px; }
  .nt-tbl tbody tr { cursor: pointer; }
  .nt-tbl tbody tr:hover { background: #f3f8ff; }
  .val { font-variant-numeric: tabular-nums; font-weight: 600; }
  .nt-tbl th.tot, .nt-tbl td.tot { background: #f7f4ff; }
  .nt-tbl th.tot.s-on { color: #6a3fb5; }
  .nt-tbl td.tot .val { color: #6a3fb5; font-weight: 800; }
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
  /* 공유 버튼/모달 */
  .nt-sharebtn { font: inherit; font-size: 13px; font-weight: 600; padding: 6px 13px; border: 1px solid #117a65;
                 background: #16a085; color: #fff; border-radius: 999px; cursor: pointer; white-space: nowrap; }
  .nt-sharebtn:hover { background: #117a65; }
  .ns-row { display: flex; align-items: center; gap: 8px; padding: 8px 4px; flex-wrap: wrap; }
  .ns-url { flex: 1; min-width: 200px; font: inherit; font-size: 12.5px; padding: 7px 9px; border: 1px solid #d4dae2; border-radius: 8px; color: #2c3440; background: #f7f9fb; }
  .ns-btn { font: inherit; font-size: 13px; font-weight: 600; padding: 7px 13px; border-radius: 8px; cursor: pointer; border: 1px solid #d4dae2; background: #fff; color: #44505d; }
  .ns-btn.primary { background: #16a085; border-color: #117a65; color: #fff; }
  .ns-btn.danger { color: #c0392b; border-color: #e6b3ac; }
  .ns-hint { font-size: 12px; color: #9aa6b2; padding: 6px 4px 2px; line-height: 1.5; }
  .ns-exp { font-size: 12.5px; color: #2f8f4e; font-weight: 600; padding: 2px 4px 6px; }
  /* 표/지도 전환 토글 */
  .nt-vtoggle { margin-left: auto; display: flex; }
  .nt-vtoggle button { font: inherit; font-size: 13px; font-weight: 700; padding: 6px 15px; border: 1px solid #d4dae2;
            background: #fff; color: #6a7686; cursor: pointer; }
  .nt-vtoggle button:first-child { border-radius: 8px 0 0 8px; }
  .nt-vtoggle button:last-child { border-radius: 0 8px 8px 0; border-left: none; }
  .nt-vtoggle button.on { background: #2979ff; border-color: #2979ff; color: #fff; }
  /* 지도 */
  #ntMapWrap { display: none; }
  #ntMap { width: 100%; height: 64vh; min-height: 380px; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
  .nt-mapnote { font-size: 12px; color: #9aa6b2; padding: 8px 2px 0; }
  /* 지도 마커(순위 원형) + 정보창 */
  .ntmk { min-width: 24px; height: 24px; padding: 0 5px; box-sizing: border-box; border-radius: 999px; background: #e74c3c;
          color: #fff; font-weight: 800; font-size: 12px; display: flex; align-items: center; justify-content: center;
          border: 2px solid #fff; box-shadow: 0 1px 4px rgba(0,0,0,.45); cursor: pointer; }
  .ntiw { background: #fff; border-radius: 10px; box-shadow: 0 4px 16px rgba(0,0,0,.22); padding: 10px 12px; min-width: 180px; max-width: 250px; }
  .ntiw-t { font-weight: 800; font-size: 14px; color: #2c3440; margin-bottom: 3px; }
  .ntiw-s { font-size: 12px; color: #5a6570; }
  .ntiw-a { font-size: 11.5px; color: #9aa6b2; margin-top: 3px; }
  .ntiw-b { margin-top: 8px; display: flex; gap: 12px; }
  .ntiw-b a { font-size: 12.5px; color: #2979ff; text-decoration: none; font-weight: 700; cursor: pointer; }
  /* 게스트(공유) 화면: 분류 탭·컨트롤바(회차·지역·최소리뷰·정렬·지도 기준·공유) 전부 숨김 — 공유시점 고정 보기 */
  body.nt-guest #ntTabs, body.nt-guest .nt-ctl, body.nt-guest .nt-sortline, body.nt-guest .nt-tagbar { display: none !important; }
  @media (max-width: 720px) {
    .nt-tbl .col-opt { display: none; }   /* 좁은 화면: 방문/블로그 숨김 */
  }
</style>
HEAD;
echo '</head><body' . ($NT_SHARE ? ' class="nt-guest"' : '') . '>';
if (!$NT_SHARE) render_nav('naver_trend');
// 게스트(공유) 여부·고정 분류·토큰을 JS 로 전달
echo '<script>var NT_SHARE=' . ($NT_SHARE ? 'true' : 'false')
   . ', NT_SHARE_CAT=' . json_encode($NT_SHARE_CAT)
   . ', NT_SHARE_TOKEN=' . json_encode($NT_SHARE ? $shareToken : '')
   . ', NT_SHARE_REGION=' . json_encode($NT_SHARE_REGION)
   . ', NT_SHARE_MINREV=' . (int)$NT_SHARE_MINREV
   . ', NT_SHARE_SORT=' . json_encode($NT_SHARE_SORT)
   . ', NT_SHARE_TAGS=' . json_encode($NT_SHARE_TAGS, JSON_UNESCAPED_UNICODE)
   . ', NT_NAVER_KEY=' . json_encode($NT_NAVER_KEY) . ';</script>';
echo <<<'BODY'
<div class="nt-wrap">
  <div class="nt-head">
    <h1 id="ntTitle">맛집 추이</h1>
    <span class="sub">네이버 평점·리뷰·저장수의 회차별 변화 (직전 회차 대비)</span>
    <div class="nt-vtoggle">
      <button id="ntViewList" class="on" onclick="ntSetView('list')">표</button>
      <button id="ntViewMap" onclick="ntSetView('map')">지도</button>
    </div>
  </div>

  <div class="nt-tabs" id="ntTabs"></div>

  <div class="nt-ctl">
    <span><label>회차</label><select id="ntPeriod"></select></span>
    <span><label>지역</label><input class="region" id="ntRegion" type="text" list="ntRegionList" placeholder="예: 강남구" autocomplete="off" /><datalist id="ntRegionList"></datalist></span>
    <span><label>최소리뷰</label>
      <select id="ntMinRev">
        <option value="0">전체</option>
        <option value="500">500+</option>
        <option value="1000" selected>1,000+</option>
        <option value="3000">3,000+</option>
      </select>
    </span>
    <span class="nt-cnt" id="ntCount" title="현재 표(최소리뷰·지역) 필터에 해당하는 곳수"></span>
    <span class="grow"></span>
    <span class="nt-cnt nt-mapcnt" id="ntMapCount" title="지도 표시 기준에 해당하는 마커 곳수(고정)"></span>
    <button type="button" id="ntMapBtn" class="nt-mapbtn" onclick="ntMapModalOpen()" title="지도에 표시할 기준(분류·최소리뷰)을 선택">🗺️ 지도 기준 설정</button>
    <button type="button" id="ntShareBtn" class="nt-sharebtn" onclick="ntShareOpen()" title="이 분류 추이를 비로그인 공개 링크로 공유">🔗 공유</button>
  </div>
  <div class="nt-sortline"><span class="nt-sorthint">정렬: 헤더 클릭(재클릭=증가순 Δ) · <b>#</b> 클릭=순위 급등/급락순</span></div>
  <div class="nt-tagbar" id="ntTagBar"></div>

  <div id="ntBody"></div>
  <div id="ntMapWrap"><div id="ntMap"></div><div class="nt-mapnote" id="ntMapNote"></div></div>
</div>

<div class="nt-modal" id="ntModal" onclick="if(event.target===this)ntCloseChart()">
  <div class="nt-card">
    <div class="nt-card-h"><span class="t" id="ntChartTitle">추이</span><button class="x" onclick="ntCloseChart()">&times;</button></div>
    <div class="nt-card-b"><div id="ntChart"></div></div>
  </div>
</div>

<div class="nt-modal" id="ntMapModal" onclick="if(event.target===this)ntMapModalClose()">
  <div class="nt-card" style="width:400px">
    <div class="nt-card-h"><span class="t" id="nmTitle">🗺️ 지도 표시 기준</span><button class="x" onclick="ntMapModalClose()">&times;</button></div>
    <div class="nt-card-b">
      <div class="nm-row"><div class="nm-lbl">최소 리뷰</div><div class="nm-opts" id="nmMr"></div></div>
      <div class="nm-hint">이 분류에서 선택한 리뷰 이상인 곳만 여행지도에 마커로 표시됩니다. (분류는 위 탭으로 전환 · 세 분류가 각 기준으로 지도에 함께 표시)</div>
      <div class="nm-act"><button class="nm-apply" onclick="ntMapApply()">지도에 적용</button></div>
    </div>
  </div>
</div>

<div class="nt-modal" id="ntShareModal" onclick="if(event.target===this)ntShareClose()">
  <div class="nt-card" style="width:460px">
    <div class="nt-card-h"><span class="t">🔗 <span id="nsTitle">맛집</span> 추이 공유</span><button class="x" onclick="ntShareClose()">&times;</button></div>
    <div class="nt-card-b"><div id="nsBody"></div></div>
  </div>
</div>

<script>
var API = '/naver_trend_api.php';
var ntChart = null;
var ntView = 'list', ntRows = [];                       // 현재 표시 행(표·지도 공용)
var ntSort = 'total_score';                             // 현재 정렬 키(헤더 클릭으로 변경 · 값⇄증가순 토글)
var ntSelTags = [], ntAllTags = [], ntTagsExpanded = false;   // 태그(칩) 필터 상태
var NT_TAG_TOPN = 18;                                   // 칩 기본 노출 개수(초과분은 더보기)
// 표 헤더 정의: 값 정렬(v) ⇄ 증가순 델타 정렬(d) 토글. 종합점수는 델타 정렬 없음.
var NT_COLS = [
  { label:'종합점수', v:'total_score', d:null,        cls:'tot',     title:'평점·리뷰·저장·방문·블로그 가중 종합(0~100)' },
  { label:'평점',     v:'score',       d:'d_score',   cls:'' },
  { label:'리뷰',     v:'review',      d:'d_review',  cls:'' },
  { label:'방문',     v:'visitor',     d:'d_visitor', cls:'col-opt' },
  { label:'블로그',   v:'blog',        d:'d_blog',    cls:'col-opt' },
  { label:'저장',     v:'save',        d:'d_save',    cls:'' }
];
var ntMap = null, ntMapMarkers = [], ntInfo = null, ntSelRow = null;
// 게스트(공유) 모드 — PHP 가 주입(미주입 시 false)
if (typeof NT_SHARE === 'undefined') { var NT_SHARE = false, NT_SHARE_CAT = 'food', NT_SHARE_TOKEN = '', NT_SHARE_REGION = '', NT_SHARE_MINREV = 0, NT_SHARE_SORT = 'total_score', NT_SHARE_TAGS = []; }
var SHARE_Q = NT_SHARE ? ('&share=' + encodeURIComponent(NT_SHARE_TOKEN)) : '';   // 게스트 fetch 인증용
var CAT = NT_SHARE ? NT_SHARE_CAT : ((new URLSearchParams(location.search).get('cat')) || 'food');
var CAT_LABELS = { food: '맛집', stay: '스테이', camping: '캠핑장' };   // cats API 응답으로 갱신
function ntCatLabel(k){ return CAT_LABELS[k] || k; }

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

// onclick 인라인 인자용 이스케이프(백슬래시·작은따옴표) — 태그에 콤마·공백 있어도 안전
function ntEscAttr(s){ return String(s==null?'':s).replace(/\\/g, '\\\\').replace(/'/g, "\\'"); }

// 헤더 문구: "[지역 ]분류[(태그·태그)]" (예: 영등포구 맛집(한식) · 캠핑장(오토캠핑))
function ntHeadLabel(region, tags){
  var s = (region ? region + ' ' : '') + ntCatLabel(CAT);
  if (tags && tags.length) s += '(' + tags.join('·') + ')';
  return s;
}
function ntUpdateHead(){
  var region = NT_SHARE ? (NT_SHARE_REGION || '') : document.getElementById('ntRegion').value.trim();
  var lbl = ntHeadLabel(region, ntSelTags);
  // 소유자 & 필터 없음 → 랜딩 느낌의 "OO 추이" 유지
  document.getElementById('ntTitle').textContent =
    (!NT_SHARE && !region && !ntSelTags.length) ? (ntCatLabel(CAT) + ' 추이') : lbl;
}

// 태그(칩) 목록 로드 — 현 분류·회차의 cuisine 태그를 곳수순으로. 게스트는 칩바 숨김이라 로드 안 함.
function ntLoadTags(){
  if (NT_SHARE) return;
  var p = document.getElementById('ntPeriod').value || '';
  fetch(API + '?action=tags&cat=' + encodeURIComponent(CAT) + '&period=' + encodeURIComponent(p))
    .then(function(r){ return r.json(); })
    .then(function(j){ ntAllTags = j.tags || []; ntRenderTags(); })
    .catch(function(){ ntAllTags = []; ntRenderTags(); });
}
function ntRenderTags(){
  var bar = document.getElementById('ntTagBar');
  if (!bar) return;
  if (NT_SHARE || !ntAllTags.length){ bar.innerHTML = ''; return; }
  var shown = ntTagsExpanded ? ntAllTags : ntAllTags.slice(0, NT_TAG_TOPN);
  var inShown = {}; shown.forEach(function(t){ inShown[t.tag] = true; });
  // 선택됐지만 상위 목록에 없는(접힘·회차변경) 태그도 칩으로 유지
  var extraSel = ntSelTags.filter(function(t){ return !inShown[t]; });
  var html = '<span class="ntt-lbl">태그</span>'
           + '<button class="ntt-chip' + (ntSelTags.length ? '' : ' on') + '" onclick="ntClearTags()">전체</button>';
  extraSel.forEach(function(tg){
    html += '<button class="ntt-chip on" onclick="ntToggleTag(\'' + ntEscAttr(tg) + '\')">' + ntEsc(tg) + '</button>';
  });
  shown.forEach(function(t){
    var on = ntSelTags.indexOf(t.tag) >= 0;
    html += '<button class="ntt-chip' + (on ? ' on' : '') + '" onclick="ntToggleTag(\'' + ntEscAttr(t.tag) + '\')">'
          + ntEsc(t.tag) + '<span class="ntt-c">' + ntFmt(t.c) + '</span></button>';
  });
  if (ntAllTags.length > NT_TAG_TOPN){
    html += '<button class="ntt-more" onclick="ntToggleTagsExpand()">'
          + (ntTagsExpanded ? '접기' : ('더보기 +' + (ntAllTags.length - NT_TAG_TOPN))) + '</button>';
  }
  bar.innerHTML = html;
}
function ntToggleTag(tg){
  var i = ntSelTags.indexOf(tg);
  if (i >= 0) ntSelTags.splice(i, 1); else ntSelTags.push(tg);
  ntRenderTags(); ntLoad();                              // ntLoad 가 ntUpdateHead 호출
}
function ntClearTags(){
  if (!ntSelTags.length) return;
  ntSelTags = []; ntRenderTags(); ntLoad();
}
function ntToggleTagsExpand(){ ntTagsExpanded = !ntTagsExpanded; ntRenderTags(); }

function ntLoad(){
  var p   = document.getElementById('ntPeriod').value;
  var rg  = document.getElementById('ntRegion').value.trim();
  var mr  = document.getElementById('ntMinRev').value;
  var srt = ntSort;
  var q = API + '?action=list&cat=' + encodeURIComponent(CAT)
        + '&period=' + encodeURIComponent(p)
        + '&region=' + encodeURIComponent(rg)
        + '&tags=' + encodeURIComponent(ntSelTags.join('|'))
        + '&min_review=' + encodeURIComponent(mr)
        + '&sort=' + encodeURIComponent(srt) + '&limit=100' + SHARE_Q;
  ntUpdateHead();                                        // 헤더에 지역·분류·태그 반영
  document.getElementById('ntBody').innerHTML = '<div class="nt-empty">불러오는 중…</div>';
  fetch(q).then(function(r){ return r.json(); }).then(function(j){
    if (j.error) { document.getElementById('ntBody').innerHTML = '<div class="nt-empty">오류: '+ntEsc(j.error)+'</div>'; return; }
    ntRender(j.rows || []);
    document.getElementById('ntCount').textContent = (j.total||0).toLocaleString() + '곳';
    if (ntView === 'map') ntEnsureMap().then(ntRenderMap).catch(function(){});   // 지도 보기 중이면 마커 갱신
  }).catch(function(e){ document.getElementById('ntBody').innerHTML = '<div class="nt-empty">불러오기 실패: '+ntEsc(e.message)+'</div>'; });
}

function ntRender(rows){
  ntRows = rows || [];                                   // 표·지도 공용 보관
  if (!rows.length){ document.getElementById('ntBody').innerHTML = '<div class="nt-empty">이 조건에 데이터가 없습니다. (수집 회차가 1개뿐이면 델타는 다음 달부터 표시됩니다)</div>'; return; }
  var ths = NT_COLS.map(function(c){
    var isV = (ntSort === c.v), isD = (c.d && ntSort === c.d);
    var cls = ['sortable'];
    if (c.cls) cls.push(c.cls);
    if (isV || isD) cls.push('s-on');
    var arrow = isV ? '<span class="sar von">▼</span>'
              : isD ? '<span class="sar don">Δ▲</span>'
              :       '<span class="sar">↕</span>';
    var title = c.title ? c.title : (c.d ? '클릭: 값 많은순 · 다시 클릭: 증가순(Δ)' : '');
    return '<th class="'+cls.join(' ')+'" data-v="'+c.v+'"'+(c.d?' data-d="'+c.d+'"':'')
         + (title?' title="'+ntEsc(title)+'"':'')+'>'+c.label+arrow+'</th>';
  }).join('');
  // # 헤더 = 순위 급등/급락 정렬 토글 (급등 → 급락 → 기본)
  var rankArrow = ntSort==='rank_up'   ? '<span class="sar don">▲</span>'
                : ntSort==='rank_down' ? '<span class="sar down2">▼</span>'
                :                        '<span class="sar">⇅</span>';
  var rankOn = (ntSort==='rank_up'||ntSort==='rank_down') ? ' s-on' : '';
  var rankTh = '<th class="sortable rankcol'+rankOn+'" data-rank="1" title="클릭: 순위 급등순 → 급락순 → 기본">#'+rankArrow+'</th>';
  var h = '<table class="nt-tbl"><thead><tr>'
        + rankTh + '<th style="text-align:left">'+ntEsc(ntCatLabel(CAT))+'</th>'
        + ths
        + '</tr></thead><tbody>';
  rows.forEach(function(r, i){
    var isNew = (r.prev_period == null);
    var dTot = (r.total_score != null && r.prev_total != null) ? (r.total_score - r.prev_total) : null;
    // 전월대비 순위 변동: 이번 순위(cur_rank) vs 지난달 순위(prev_rank). + = 상승
    var isMoveSort = (ntSort==='rank_up'||ntSort==='rank_down');
    var cr = (r.cur_rank != null) ? Number(r.cur_rank) : (i + 1);
    var mv = '';
    if (isNew) {
      mv = '<span class="rkmv new">NEW</span>';
    } else if (r.prev_rank != null) {
      var rd = Number(r.prev_rank) - cr;
      mv = rd > 0 ? '<span class="rkmv up" title="지난달 대비 '+rd+'계단 상승">▲'+rd+'</span>'
         : rd < 0 ? '<span class="rkmv down" title="지난달 대비 '+(-rd)+'계단 하락">▼'+(-rd)+'</span>'
         :          '<span class="rkmv flat" title="지난달과 동일">–</span>';
    }
    var num = isMoveSort ? cr : (i + 1);   // 급등/급락 정렬 땐 실제 종합순위 표시
    h += '<tr onclick="ntOpenChart(\''+(r.place_id||0)+'\',\''+ntEsc(r.name).replace(/'/g,"\\'")+'\')">'
       + '<td class="rank">'+num+mv+'</td>'
       + '<td class="name">'
         + (r.naver_id
             ? '<a class="nm" href="https://map.naver.com/p/entry/place/'+ntEsc(String(r.naver_id))+'" onclick="return ntOpenNaver(event,\''+ntEsc(String(r.naver_id))+'\')" title="네이버 플레이스에서 자세히 보기">'+ntEsc(r.name)+'</a>'
             : '<span class="nm">'+ntEsc(r.name)+'</span>')
         + ((r.address||r.region)?'<div class="addr">'+ntEsc(r.address||r.region)+'</div>':'') + '</td>'
       + '<td class="tot">'+ntCell(r.total_score, dTot, true)+'</td>'
       + '<td>'+ntCell(r.score, r.d_score, true)+'</td>'
       + '<td>'+ntCell(r.review, r.d_review, false)+'</td>'
       + '<td class="col-opt">'+ntCell(r.visitor, r.d_visitor, false)+'</td>'
       + '<td class="col-opt">'+ntCell(r.blog, r.d_blog, false)+'</td>'
       + '<td>'+ntCell(r.save, r.d_save, false)+'</td>'
       + '</tr>';
  });
  h += '</tbody></table>';
  document.getElementById('ntBody').innerHTML = h;
  // 값 헤더 클릭: 값 정렬 ⇄ 같은 헤더 재클릭 시 증가순(Δ) 토글
  Array.prototype.forEach.call(document.querySelectorAll('.nt-tbl th[data-v]'), function(th){
    th.addEventListener('click', function(){
      var v = th.getAttribute('data-v'), d = th.getAttribute('data-d');
      ntSort = (d && ntSort === v) ? d : v;   // 값 → 증가순 토글, 그 외(증가순·다른 헤더)엔 값
      ntLoad();
    });
  });
  // # 헤더 클릭: 순위 급등순 → 급락순 → 기본(종합점수) 순환
  var rankHead = document.querySelector('.nt-tbl th[data-rank]');
  if (rankHead) rankHead.addEventListener('click', function(){
    ntSort = (ntSort==='rank_up') ? 'rank_down' : (ntSort==='rank_down' ? 'total_score' : 'rank_up');
    ntLoad();
  });
}

// ── 공유 링크 (소유자 전용) — travel.php 패턴: 한시적 토큰 발급/복사/중단 ───────────
function ntShareOpen(){
  document.getElementById('nsTitle').textContent = ntCatLabel(CAT);
  document.getElementById('ntShareModal').classList.add('on');
  document.getElementById('nsBody').innerHTML = '<div class="ns-hint">불러오는 중…</div>';
  var region = document.getElementById('ntRegion').value.trim();
  fetch(API + '?action=share_status&cat=' + encodeURIComponent(CAT) + '&region=' + encodeURIComponent(region))
    .then(function(r){ return r.json(); })
    .then(function(j){ ntShareRender(j.token, j.expires_at, j.region); })
    .catch(function(){ ntShareRender(null, null, ''); });
}
function ntShareClose(){ document.getElementById('ntShareModal').classList.remove('on'); }
function ntShareUrl(token){ return location.origin + '/naver_trend.php?share=' + token; }
// 공유될 제목 미리보기 = "[지역 ]분류[(태그)]"(예: 관악구 맛집(한식))
function ntShareTitle(region){ return ntHeadLabel(region, ntSelTags); }
function ntShareRender(token, exp, region){
  var b = document.getElementById('nsBody');
  if (token){
    b.innerHTML =
        '<div class="ns-exp">🔗 공개 중 · <b>' + ntEsc(ntShareTitle(region)) + '</b> · 만료: ' + ntEsc(exp || '') + '</div>'
      + '<div class="ns-row"><input class="ns-url" id="nsUrl" readonly value="' + ntEsc(ntShareUrl(token)) + '" onclick="this.select()">'
      + '<button class="ns-btn primary" onclick="ntShareCopy()">복사</button></div>'
      + '<div class="ns-row"><button class="ns-btn danger" onclick="ntShareRevoke()">공유 중단</button>'
      + '<button class="ns-btn" onclick="ntShareCreate()">현재 화면 기준으로 새 링크</button></div>'
      + '<div class="ns-hint">받은 사람은 로그인 없이 <b>' + ntEsc(ntShareTitle(region)) + '</b> 화면만 보게 돼요. (지역·회차·필터 고정, 컨트롤은 숨김)</div>';
  } else {
    b.innerHTML =
        '<div class="ns-hint">현재 화면 기준으로 공유됩니다 → <b>' + ntEsc(ntShareTitle(document.getElementById('ntRegion').value.trim())) + '</b></div>'
      + '<div class="ns-row"><label class="nm-lbl" style="width:auto">공개 기간</label>'
      + '<select class="ns-btn" id="nsTtl"><option value="86400">1일</option><option value="604800" selected>7일</option>'
      + '<option value="2592000">30일</option><option value="7776000">90일</option></select>'
      + '<button class="ns-btn primary" onclick="ntShareCreate()">링크 생성</button></div>'
      + '<div class="ns-hint">지역·회차·최소리뷰·정렬이 지금 화면 그대로 고정됩니다.</div>';
  }
}
function ntShareCreate(){
  var el = document.getElementById('nsTtl');
  var ttl = el ? el.value : 604800;
  var q = API + '?action=share_create&cat=' + encodeURIComponent(CAT) + '&ttl=' + encodeURIComponent(ttl)
        + '&region=' + encodeURIComponent(document.getElementById('ntRegion').value.trim())
        + '&tags=' + encodeURIComponent(ntSelTags.join('|'))
        + '&min_review=' + encodeURIComponent(document.getElementById('ntMinRev').value)
        + '&sort=' + encodeURIComponent(ntSort);
  fetch(q).then(function(r){ return r.json(); })
    .then(function(j){ if (j.ok) ntShareRender(j.token, j.expires_at, j.region); else alert('생성 실패'); });
}
function ntShareRevoke(){
  if (!confirm('이 지역 공유를 중단할까요? 해당 링크만 즉시 막힙니다.')) return;
  var region = document.getElementById('ntRegion').value.trim();
  fetch(API + '?action=share_revoke&cat=' + encodeURIComponent(CAT) + '&region=' + encodeURIComponent(region))
    .then(function(r){ return r.json(); })
    .then(function(){ ntShareRender(null, null, region); });
}
function ntShareCopy(){
  var u = document.getElementById('nsUrl'); if (!u) return;
  u.select();
  try { navigator.clipboard.writeText(u.value); } catch(e){ try { document.execCommand('copy'); } catch(e2){} }
  alert('링크가 복사되었습니다.');
}

// ── 표 ↔ 지도 전환 + 리스트를 네이버 지도에 마커로 ───────────────────────────
var NT_CAT_COLOR = { restaurant: '#e74c3c', stay: '#8e44ad', camping: '#27ae60' };
function ntCatPlace(){ return ({ food:'restaurant', stay:'stay', camping:'camping' })[CAT] || 'restaurant'; }
function ntSetView(v){
  ntView = v;
  document.getElementById('ntViewList').classList.toggle('on', v === 'list');
  document.getElementById('ntViewMap').classList.toggle('on', v === 'map');
  document.getElementById('ntBody').style.display = (v === 'list') ? '' : 'none';
  document.getElementById('ntMapWrap').style.display = (v === 'map') ? 'block' : 'none';
  if (v === 'map') ntEnsureMap().then(ntRenderMap).catch(function(err){
    document.getElementById('ntMapNote').textContent = (err === 'no-key')
      ? '지도 키가 설정되어 있지 않습니다.' : '지도를 불러오지 못했습니다.';
  });
}
function ntEnsureMap(){
  return new Promise(function(resolve, reject){
    if (window.naver && window.naver.maps){ if (!ntMap) ntInitMap(); resolve(); return; }
    if (!NT_NAVER_KEY){ reject('no-key'); return; }
    var s = document.createElement('script');
    s.src = 'https://oapi.map.naver.com/openapi/v3/maps.js?ncpKeyId=' + encodeURIComponent(NT_NAVER_KEY);
    s.onload = function(){ ntInitMap(); resolve(); };
    s.onerror = function(){ reject('load-fail'); };
    document.head.appendChild(s);
  });
}
function ntInitMap(){
  ntMap = new naver.maps.Map('ntMap', { center: new naver.maps.LatLng(36.5, 127.8), zoom: 7 });
}
function ntRenderMap(){
  if (!ntMap) return;
  ntMapMarkers.forEach(function(m){ m.setMap(null); }); ntMapMarkers = [];
  if (ntInfo){ ntInfo.close(); }
  var color = NT_CAT_COLOR[ntCatPlace()] || '#e74c3c';
  var bounds = new naver.maps.LatLngBounds(), shown = 0, missing = 0;
  ntRows.forEach(function(r, i){
    var lat = (r.lat != null) ? Number(r.lat) : null, lng = (r.lng != null) ? Number(r.lng) : null;
    if (!lat || !lng){ missing++; return; }
    var pos = new naver.maps.LatLng(lat, lng);
    var mk = new naver.maps.Marker({
      position: pos, map: ntMap, zIndex: 1000 - i,
      icon: { content: '<div class="ntmk" style="background:'+color+'">'+(i+1)+'</div>', anchor: new naver.maps.Point(13, 13) }
    });
    (function(row, p){ naver.maps.Event.addListener(mk, 'click', function(){ ntMapInfo(row, p); }); })(r, pos);
    ntMapMarkers.push(mk); bounds.extend(pos); shown++;
  });
  if (shown) ntMap.fitBounds(bounds, { top: 40, right: 40, bottom: 40, left: 40 });
  naver.maps.Event.trigger(ntMap, 'resize');
  document.getElementById('ntMapNote').textContent = shown
    ? ('지도에 ' + shown + '곳 표시' + (missing ? ' · 좌표 없는 ' + missing + '곳 제외' : '') + ' (마커 클릭 = 상세)')
    : '표시할 좌표가 있는 곳이 없습니다.';
}
function ntMapInfo(r, pos){
  ntSelRow = r;
  if (!ntInfo) ntInfo = new naver.maps.InfoWindow({ borderWidth: 0, disableAnchor: true, backgroundColor: 'transparent', pixelOffset: new naver.maps.Point(0, -12) });
  var nv = r.naver_id ? '<a onclick="ntNaverFromSel();return false;">네이버 ↗</a>' : '';
  ntInfo.setContent(
    '<div class="ntiw"><div class="ntiw-t">'+ntEsc(r.name)+'</div>'
    + '<div class="ntiw-s">종합 '+(r.total_score!=null?Number(r.total_score).toFixed(1):'-')
      +' · ⭐'+(r.score!=null?Number(r.score).toFixed(2):'-')+' · 리뷰 '+ntFmt(r.review)+'</div>'
    + (r.address||r.region ? '<div class="ntiw-a">'+ntEsc(r.address||r.region)+'</div>' : '')
    + '<div class="ntiw-b"><a onclick="ntChartFromSel();return false;">추이</a>'+nv+'</div></div>'
  );
  ntInfo.open(ntMap, pos);
}
function ntChartFromSel(){ if (ntSelRow) ntOpenChart(ntSelRow.place_id || 0, ntSelRow.name); }
function ntNaverFromSel(){ if (ntSelRow && ntSelRow.naver_id) window.open('https://map.naver.com/p/entry/place/' + encodeURIComponent(ntSelRow.naver_id), 'nvplace', 'width=480,height=820,scrollbars=yes,resizable=yes'); }

// 이름 클릭 → 네이버 플레이스 상세 페이지 팝업(행 클릭의 차트 모달과 분리)
function ntOpenNaver(ev, nid){
  ev.stopPropagation();
  if (!nid) return true;            // naver_id 없으면 기본 동작(거의 없음)
  ev.preventDefault();
  window.open('https://map.naver.com/p/entry/place/' + encodeURIComponent(nid),
              'nvplace', 'width=480,height=820,scrollbars=yes,resizable=yes');
  return false;
}

function ntOpenChart(pid, name){
  document.getElementById('ntChartTitle').textContent = name + ' — 추이';
  document.getElementById('ntModal').classList.add('on');
  fetch(API + '?action=series&cat=' + encodeURIComponent(CAT) + '&pid=' + encodeURIComponent(pid) + SHARE_Q).then(function(r){ return r.json(); }).then(function(j){
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
document.addEventListener('keydown', function(e){ if(e.key==='Escape'){ ntCloseChart(); ntMapModalClose(); ntShareClose(); } });

// 카테고리 탭 적용 → 제목·회차 갱신
function ntApplyCat(){
  // 태그(칩)는 분류마다 다르므로 전환 시 초기화. 게스트는 공유시점 태그로 고정.
  ntSelTags = NT_SHARE ? (NT_SHARE_TAGS || []).slice() : [];
  ntTagsExpanded = false;
  ntAllTags = [];
  ntRenderTags();
  ntUpdateHead();            // 헤더: "[지역 ]분류[(태그)]" (게스트 공유·소유자 공통)
  Array.prototype.forEach.call(document.querySelectorAll('#ntTabs button'), function(b){
    b.classList.toggle('on', b.getAttribute('data-cat') === CAT);
  });
  ntSetMinRevOptions(CAT);   // 표(table) 최소리뷰 옵션(스테이·캠핑 낮게)
  if (NT_SHARE) {            // 공유: 공유시점 지역·필터로 고정(컨트롤바는 숨김 상태)
    document.getElementById('ntRegion').value = NT_SHARE_REGION || '';
    document.getElementById('ntMinRev').value = String(NT_SHARE_MINREV);
    if (NT_SHARE_SORT) ntSort = NT_SHARE_SORT;
  }
  ntRenderMapCrit();         // 지도 기준 버튼 라벨 = 현재 탭 분류 기준
  ntLoadMapCount();          // 지도 기준 곳수 = 현재 탭 분류
  ntLoadRegions();           // 지역창 자동완성 목록(현 카테고리 시군구)
  ntLoadPeriods();           // 회차 로드 → 확정 후 태그·표 로드(태그는 회차별이라 순서 중요)
}
function ntSwitchCat(k){
  if (k === CAT) return;
  CAT = k;
  // URL 동기화(새로고침·공유 시 유지)
  try { history.replaceState(null, '', location.pathname + '?cat=' + encodeURIComponent(k)); } catch(e){}
  ntApplyCat();   // 최소리뷰 옵션은 ntSetMinRevOptions 가 카테고리별로 재설정
}

// 회차 목록 로드(현 카테고리) → 첫 표 로드
function ntLoadPeriods(){
  fetch(API + '?action=periods&cat=' + encodeURIComponent(CAT) + SHARE_Q).then(function(r){ return r.json(); }).then(function(j){
    var sel = document.getElementById('ntPeriod');
    var ps = j.periods || [];
    if (!ps.length){
      sel.innerHTML = '';
      document.getElementById('ntCount').textContent = '';
      document.getElementById('ntBody').innerHTML = '<div class="nt-empty">아직 수집된 회차가 없습니다. '+ntEsc(ntCatLabel(CAT))+' 수집(cron_naver_collect.php?cat='+CAT+') 후 표시됩니다.</div>';
      return;
    }
    sel.innerHTML = ps.map(function(p){ return '<option value="'+p+'">'+p+'</option>'; }).join('');
    ntLoad();
    ntLoadTags();   // 회차 확정 후 태그(칩) 로드 — 캠핑처럼 최신 회차가 다른 분류도 정확히 반영
  });
}

// 지역창 자동완성 — 현 카테고리 시군구 목록을 메모리에 두고, 입력 글자에 맞는 것만 최대 12개 제안.
//  (빈 입력일 때 전체 목록을 띄우면 긴 스크롤바가 생겨서, 타이핑한 글자로 필터링한 결과만 채운다)
var ntAllRegions = [];
function ntLoadRegions(){
  fetch(API + '?action=regions&cat=' + encodeURIComponent(CAT) + SHARE_Q).then(function(r){ return r.json(); }).then(function(j){
    ntAllRegions = j.regions || [];
    ntFillRegionList(document.getElementById('ntRegion').value);
  }).catch(function(){ ntAllRegions = []; });
}
function ntFillRegionList(q){
  q = (q || '').trim().toLowerCase();
  var list = q
    ? ntAllRegions.filter(function(x){ return String(x).toLowerCase().indexOf(q) >= 0; }).slice(0, 12)
    : [];   // 빈 입력 = 제안 없음(긴 드롭다운 방지)
  document.getElementById('ntRegionList').innerHTML =
    list.map(function(x){ return '<option value="'+ntEsc(x)+'"></option>'; }).join('');
}

// 카테고리별 최소리뷰 옵션 — 스테이·캠핑은 리뷰가 적어 낮은 단계.
function ntSetMinRevOptions(cat){
  var sel = document.getElementById('ntMinRev');
  var prev = sel.value;
  var opts = (cat === 'food')
    ? [['0','전체'],['500','500+'],['1000','1,000+'],['3000','3,000+']]
    : [['0','전체'],['50','50+'],['100','100+'],['300','300+']];
  sel.innerHTML = opts.map(function(o){ return '<option value="'+o[0]+'">'+o[1]+'</option>'; }).join('');
  var keep = opts.some(function(o){ return o[0] === prev; });
  sel.value = keep ? prev : (cat === 'food' ? '1000' : '0');
}

// ── 지도 표시 기준 모달 (분류별·현재 탭 기준) ─────────────────────────
//  지도 기준은 분류별로 따로 저장: localStorage 'pl_map_filter' = {restaurant,stay,camping}.
//  지도는 세 분류를 각 기준으로 함께 표시. 이 모달은 '현재 탭' 분류의 기준만 다룬다.
function ntMapCat(){ return ({ food:'restaurant', stay:'stay', camping:'camping' })[CAT] || 'restaurant'; }
function ntMapStore(){ try { var o = JSON.parse(localStorage.getItem('pl_map_filter')||'null'); return (o && typeof o==='object') ? o : {}; } catch(e){ return {}; } }
function nmMrOpts(cat){   // 분류별 최소리뷰 옵션(맛집은 높게, 스테이·캠핑은 낮게)
  return (cat === 'restaurant')
    ? [[0,'전체'],[500,'500+'],[1000,'1,000+'],[3000,'3,000+']]
    : [[0,'전체'],[50,'50+'],[100,'100+'],[300,'300+']];
}
var nmSelMr = 0;
function ntMapModalOpen(){
  var cat = ntMapCat(), store = ntMapStore();
  nmSelMr = (store[cat] != null) ? store[cat] : 0;
  document.getElementById('nmTitle').textContent = '🗺️ ' + ntCatLabel(CAT) + ' 지도 표시 기준';
  ntMapRender();
  document.getElementById('ntMapModal').classList.add('on');
}
function ntMapModalClose(){ document.getElementById('ntMapModal').classList.remove('on'); }
function ntMapRender(){
  var opts = nmMrOpts(ntMapCat());
  if (!opts.some(function(o){ return o[0] === nmSelMr; })) nmSelMr = 0;
  document.getElementById('nmMr').innerHTML = opts.map(function(o){
    return '<button class="nm-opt'+(o[0]===nmSelMr?' on':'')+'" onclick="ntMapPickMr('+o[0]+')">'+o[1]+'</button>';
  }).join('');
}
function ntMapPickMr(m){ nmSelMr = m; ntMapRender(); }
function ntMapApply(){
  var cat = ntMapCat(), store = ntMapStore();
  store[cat] = nmSelMr;
  try { localStorage.setItem('pl_map_filter', JSON.stringify(store)); } catch(e){}
  ntRenderMapCrit(); ntLoadMapCount(); ntMapModalClose();
}

// 현재 탭 분류의 지도 기준 곳수(고정) — 버튼 옆에 표시.
function ntLoadMapCount(){
  if (NT_SHARE) return;                         // 게스트는 지도 기준 UI 숨김 → 호출 안 함
  var el = document.getElementById('ntMapCount');
  if (!el) return;
  var cat = ntMapCat(), store = ntMapStore();
  if (store[cat] == null){ el.textContent = ''; return; }
  el.textContent = '…';
  fetch(API + '?action=mapcount&mcat=' + encodeURIComponent(cat) + '&min_review=' + encodeURIComponent(store[cat] || 0))
    .then(function(r){ return r.json(); })
    .then(function(j){ el.textContent = '지도 ' + Number(j.count || 0).toLocaleString() + '곳'; })
    .catch(function(){ el.textContent = ''; });
}

// 현재 탭 분류의 지도 기준(저장값)을 버튼 라벨에 표시.
function ntRenderMapCrit(){
  var btn = document.getElementById('ntMapBtn');
  if (!btn) return;
  var cat = ntMapCat(), store = ntMapStore();
  if (store[cat] == null){ btn.textContent = '🗺️ 지도 기준 설정'; return; }
  var mr = store[cat] ? ('리뷰 ' + Number(store[cat]).toLocaleString() + '+') : '전체';
  btn.textContent = '🗺️ 지도: ' + ntCatLabel(CAT) + ' · ' + mr;
}

// 컨트롤 이벤트
document.getElementById('ntMinRev').addEventListener('change', ntLoad);
document.getElementById('ntPeriod').addEventListener('change', function(){ ntLoadTags(); ntLoad(); });   // 회차 바뀌면 태그 분포도 갱신
var rgTimer = null;
document.getElementById('ntRegion').addEventListener('input', function(){
  ntFillRegionList(this.value);                              // 자동완성 후보 갱신(최대 12)
  clearTimeout(rgTimer); rgTimer = setTimeout(ntLoad, 350);
});

// 카테고리 목록 로드 → 탭 빌드 → 현 카테고리 적용
fetch(API + '?action=cats' + SHARE_Q).then(function(r){ return r.json(); }).then(function(j){
  var cats = (j.cats && j.cats.length) ? j.cats : [{key:'food',label:'맛집'},{key:'stay',label:'스테이'},{key:'camping',label:'캠핑장'}];
  cats.forEach(function(c){ CAT_LABELS[c.key] = c.label; });
  if (!CAT_LABELS[CAT]) CAT = 'food';
  document.getElementById('ntTabs').innerHTML = cats.map(function(c){
    return '<button data-cat="'+ntEsc(c.key)+'" onclick="ntSwitchCat(\''+ntEsc(c.key)+'\')">'+ntEsc(c.label)+'</button>';
  }).join('');
  ntApplyCat();         // 표 옵션·지도 기준 버튼/곳수까지 모두 갱신
});
</script>
BODY;
echo '</body></html>';
?>
