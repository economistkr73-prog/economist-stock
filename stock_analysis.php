<?php

require_once "./env/cnt.inc";

# error 표시
 error_reporting( E_ALL  & ~E_NOTICE);
 ini_set( "display_errors", 1 );
 ini_set("allow_url_fopen",1);

#변수정의
define('CUR_PHP', basename($_SERVER['PHP_SELF']));

require_once "./env/auth_fnc.php";
require_login(); 

?>



<style>
    /* 활성화 상태: 파란색 배경, 하얀색 글자, 클릭 가능한 손모양 */
    .btn-active { 
        background-color: #1a73e8 !important; 
        color: #ffffff !important; 
        cursor: pointer !important; 
    }
    
    /* 비활성화 상태: 회색 배경, 회색 글자, 금지 모양 */
    .btn-disabled { 
        background-color: #cccccc !important; 
        color: #888888 !important; 
        cursor: not-allowed !important; 
    }
</style>

<?php


// 1. 변수 안전하게 받기 (중복 선언 제거)
$mode = $_REQUEST["mode"] ?? ''; 

$routes = [
	'updash'                     => 'up_dashboard',   // 상승종목 분석 대시보드 (stock_analysis_api.php 소비)
];




// ==========================================================
// 2. 실행 엔진
// ==========================================================

if (isset($routes[$mode]) && function_exists($routes[$mode])) {
    $func_name = $routes[$mode];
    $func_name($pdo);

} else {
    echo "<meta http-equiv=\"refresh\" content=\"0;url=lo.php\">";
    exit;
}





#################################################################
function up_dashboard($pdo) {
#################################################################
// 상승종목 분석 대시보드 — 목업(stock_dashboard_mockup) 이식.
// 데이터는 전부 stock_analysis_api.php (action=top30/news/daily/minute) 에서 fetch.
// 상단 공통 네비(env/nav.inc)를 PC 전용으로 부착. PHP 변수 주입 없음(클라이언트 전담) → NOWDOC.
require_once "./env/nav.inc";
$current_user = $_SESSION['usr_name'] ?? '';
echo <<<'PAGE'
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>상승종목 분석 대시보드</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.css"/>
<script src="/style/dailychart.js?v=28"></script>
<style>
  :root{
    --bg:#0e1320; --panel:#141b2b; --panel-2:#1b2335; --line:#26304a;
    --ink:#dfe6f2; --ink-dim:#8893ab; --ink-mute:#5b6884;
    --up:#e8493f; --down:#2f7bd6; --accent:#d9a441;
    --mono:'SFMono-Regular',ui-monospace,Consolas,'Roboto Mono',monospace;
  }
  *{box-sizing:border-box;}
  html,body{margin:0;height:100%;background:var(--bg);color:var(--ink);
    font-family:Pretendard,-apple-system,BlinkMacSystemFont,'Malgun Gothic',sans-serif;
    font-size:15px;overflow:hidden;}
  body{display:flex;flex-direction:column;height:100vh;}
  .mono{font-family:var(--mono);font-variant-numeric:tabular-nums;}
  .up{color:var(--up);} .down{color:var(--down);}
  #shell{display:flex;flex-direction:column;flex:1;min-height:0;}
  header{display:flex;align-items:center;gap:14px;padding:0 16px;height:46px;
    background:var(--panel);border-bottom:1px solid var(--line);flex:0 0 46px;}
  header .logo{display:flex;align-items:center;gap:8px;font-weight:800;letter-spacing:-.02em;font-size:15px;}
  header .logo i{width:8px;height:8px;border-radius:2px;background:var(--up);box-shadow:0 0 10px var(--up);}
  header .badge{font-size:10.5px;color:var(--accent);border:1px solid var(--accent);
    border-radius:5px;padding:2px 7px;letter-spacing:.04em;cursor:pointer;user-select:none;
    transition:background .15s,color .15s;}
  header .badge:hover{background:var(--accent);color:#0b1020;}
  header .badge.loading{opacity:.6;border-style:dashed;cursor:progress;}
  header .qtime{color:var(--ink-dim);font-size:12px;}
  header .qtime b{color:var(--ink);font-variant-numeric:tabular-nums;letter-spacing:0;}
  header .clock{margin-left:auto;color:var(--ink-dim);font-size:12px;}
  /* 검색 + 최근조회 툴바 */
  #topbar{display:flex;align-items:center;gap:10px;padding:6px 14px;background:var(--panel-2);
    border-bottom:1px solid var(--line);flex:0 0 auto;position:relative;z-index:6;}
  #topbar .sbox{position:relative;flex:0 0 auto;}
  #stkSearch{width:250px;padding:7px 12px;background:var(--panel);border:1px solid var(--line);
    border-radius:7px;color:var(--ink);font-size:13px;outline:none;font-family:inherit;}
  #stkSearch:focus{border-color:var(--accent);}
  #sugBox{display:none;position:absolute;top:calc(100% + 5px);left:0;width:310px;background:var(--panel);
    border:1px solid var(--line);border-radius:8px;box-shadow:0 10px 26px rgba(0,0,0,.55);z-index:60;
    max-height:380px;overflow-y:auto;padding:4px 0;}
  #sugBox .sug{display:flex;align-items:center;gap:8px;padding:8px 12px;cursor:pointer;font-size:13px;}
  #sugBox .sug:hover,#sugBox .sug.on{background:var(--panel-2);}
  #sugBox .sug b{font-weight:700;color:var(--ink);}
  #sugBox .sug .c{color:var(--ink-mute);font-size:11px;font-family:var(--mono);}
  .recent-wrap{display:flex;align-items:center;gap:6px;overflow-x:auto;flex:1;min-width:0;}
  .recent-wrap::-webkit-scrollbar{height:5px;}
  .recent-wrap .rlbl{font-size:11px;color:var(--ink-mute);flex:0 0 auto;font-weight:700;}
  .rchip{flex:0 0 auto;background:var(--panel);border:1px solid var(--line);border-radius:999px;
    padding:4px 12px;font-size:12px;color:var(--ink-dim);cursor:pointer;white-space:nowrap;font-family:inherit;}
  .rchip:hover{border-color:var(--accent);color:var(--accent);}
  /* 주식/ETF 구분 태그 */
  .ktag{display:inline-block;margin-left:6px;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:800;
    background:#243250;color:#7fb1e8;border:1px solid #33507a;vertical-align:middle;letter-spacing:.03em;}
  /* ETF 편입 뱃지: 이 종목을 상위 편입한 ETF 수(티어 색상) */
  .etfb{display:inline-block;margin-left:6px;padding:1px 7px;border-radius:5px;font-size:11px;font-weight:800;
    vertical-align:middle;line-height:1.5;letter-spacing:.02em;}
  .etfb.et1{background:#1e2c44;color:#8fb6ec;border:1px solid #33507a;}
  .etfb.et2{background:#23449c;color:#dbe7ff;border:1px solid #3b82f6;}
  .etfb.et3{background:#2563eb;color:#fff;border:1px solid #4f8cff;}
  .etfb.et4{background:#e11d2a;color:#fff;border:1px solid #ff5a5a;box-shadow:0 0 8px rgba(225,29,42,.5);}
  #grid{flex:1;display:grid;grid-template-columns:17% 13% 70%;min-height:0;}
  .col{min-width:0;min-height:0;display:flex;flex-direction:column;border-right:1px solid var(--line);}
  .col:last-child{border-right:none;}
  .col-head{flex:0 0 auto;padding:9px 12px;border-bottom:1px solid var(--line);
    background:var(--panel);display:flex;align-items:baseline;gap:8px;}
  .col-head h2{margin:0;font-size:15px;font-weight:700;letter-spacing:.02em;}
  .col-head .sub{font-size:12px;color:var(--ink-mute);}
  .seg{display:inline-flex;border:1px solid var(--line);border-radius:7px;overflow:hidden;align-self:center;}
  .seg button{background:var(--panel-2);color:var(--ink-mute);border:0;font-size:13px;font-weight:700;
    padding:4px 11px;cursor:pointer;transition:background .1s,color .1s;}
  .seg button + button{border-left:1px solid var(--line);}
  .seg button:hover:not(.on){color:var(--ink);}
  .seg button.on{background:var(--accent);color:#0b1020;}
  .col-body{flex:1;overflow-y:auto;min-height:0;}
  .row-head,.row{display:grid;grid-template-columns:1fr auto auto auto;gap:8px;align-items:center;padding:0 12px;}
  .row-head{position:sticky;top:0;background:var(--panel);height:30px;
    font-size:13px;color:var(--ink-mute);border-bottom:1px solid var(--line);z-index:2;}
  .row{height:58px;border-bottom:1px solid rgba(38,48,74,.5);cursor:pointer;
    border-left:3px solid transparent;transition:background .1s;}
  .row:hover{background:var(--panel-2);}
  .row.on{background:#1d2741;border-left-color:var(--accent);}
  .row .nm{overflow:hidden;}
  .row .nm b{display:block;font-size:19px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .row .nm > span{font-size:15px;color:var(--ink-mute);}   /* 코드 줄만(직계). 뱃지(b 안)는 제외 */
  .row .rate{text-align:right;font-size:18px;font-weight:700;}
  .row .val{text-align:right;font-size:15px;color:var(--ink-dim);min-width:58px;}
  .row .turn{text-align:right;font-size:14px;color:var(--ink-mute);min-width:46px;}
  .row-head span:last-child,.row .turn{padding-right:2px;}
  .rank{display:inline-block;width:30px;color:var(--ink-mute);font-size:14px;text-align:right;margin-right:4px;}
  .list-msg{padding:18px 12px;color:var(--ink-mute);font-size:13px;line-height:1.6;}
  .col-head .pager{margin-left:auto;display:flex;gap:6px;align-self:center;}
  .col-head .pager button{background:var(--panel-2);color:var(--ink);border:1px solid var(--line);
    border-radius:6px;padding:4px 12px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;}
  .col-head .pager button:hover:not(:disabled){background:#26314c;border-color:var(--accent);color:var(--accent);}
  .col-head .pager button:disabled{opacity:.4;cursor:not-allowed;}
  #newsList{padding:4px 0;}
  .news{display:block;padding:9px 11px;border-bottom:1px solid rgba(38,48,74,.5);color:var(--ink);text-decoration:none;}
  .news:hover{background:var(--panel-2);}
  .news .t{font-size:14px;line-height:1.45;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;}
  .news .d{font-size:12px;color:var(--ink-mute);margin-top:4px;}
  .news-empty{padding:18px 12px;color:var(--ink-mute);font-size:11.5px;line-height:1.6;}
  .charts{flex:1;display:flex;flex-direction:column;min-height:0;}
  .chart-block{flex:1 1 50%;min-height:0;display:flex;flex-direction:column;}
  .chart-block.bottom{border-top:1px solid var(--line);}
  .chart-bar{flex:0 0 auto;display:flex;align-items:center;gap:10px;padding:7px 12px;background:var(--panel);}
  .chart-bar .lbl{font-size:11.5px;font-weight:700;}
  .cline-chip{background:var(--panel-2);color:var(--ink-mute);border:1px solid var(--line);border-radius:6px;
    padding:2px 9px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .1s,color .1s;}
  .cline-chip:hover{color:var(--ink);}
  .cline-chip.on{background:var(--accent);color:#0b1020;border-color:var(--accent);}
  .seg.dseg button{padding:2px 9px;font-size:11px;}   /* 일봉 기간 토글: 차트바용 축소 */
  .chart-bar .px{font-size:11px;color:var(--ink-dim);}
  .chart-host{flex:1;min-height:0;position:relative;}
  /* 퀀트 배지 (종목 행 둘째 줄) — 퀀트 화면과 같은 어휘·임계, 다크 톤 */
  .qb,.qbx{display:inline-block;margin-left:6px;padding:1px 7px;border-radius:6px;font-size:11px;
    font-weight:700;vertical-align:middle;line-height:1.5;letter-spacing:.01em;white-space:nowrap;}
  .qb-acc{background:#12341f;color:#5dd58a;border:1px solid #1e5c3a;}     /* 🟢매집형 */
  .qb-neu{background:#1e2637;color:#93a4c3;border:1px solid #2c3a55;}     /* 중립 */
  .qb-exp{background:#3a2410;color:#f0a13b;border:1px solid #6b4a1a;}     /* 폭발형 */
  .qb-chase{background:#3b1518;color:#f87171;border:1px solid #6e2429;}   /* 추격주의 */
  /* 20·40일 모멘텀 — 등락색 <b>단색</b> 배경 + 흰 글씨 (밝은 화면의 .mkt.t-up/.t-down 과 같은 규칙) */
  .qb-hot{background:#c62828;color:#fff;border:1px solid #c62828;}     /* 급등 (실측 근거 있음) */
  .qb-cold{background:#1565c0;color:#fff;border:1px solid #1565c0;}    /* 급락 (근거 없음·참고) */
  .qbx{border-radius:9px;}                                                 /* 박스 상태 */
  .qbx.bx-brk{background:#12341f;color:#5dd58a;}   .qbx.bx-lad{background:#152742;color:#7fb1e8;}
  .qbx.bx-dn{background:#3b1518;color:#f87171;}    .qbx.bx-in{background:#1e2637;color:#93a4c3;}
  .qbx.bx-new{background:#242c3d;color:#8893ab;}   .qbx.bx-fake{background:#3a2410;color:#f0a13b;}
  .qbx.bx-na{background:#242c3d;color:#5b6884;}
  #selBar{display:flex;align-items:baseline;gap:12px;padding:8px 14px;background:var(--panel-2);border-bottom:1px solid var(--line);}
  #selBar .snm{font-size:16px;font-weight:800;letter-spacing:-.02em;}
  #selBar .scode{font-size:11px;color:var(--ink-mute);}
  #selBar .sprice{font-family:var(--mono);font-size:16px;font-weight:700;margin-left:auto;}
  #selBar .schg{font-family:var(--mono);font-size:12.5px;font-weight:700;}
  @media(max-width:860px){
    #grid{grid-template-columns:1fr;grid-template-rows:auto auto 1fr;}
    .col{border-right:none;border-bottom:1px solid var(--line);}
    .col-body{max-height:30vh;}
  }
  ::-webkit-scrollbar{width:9px;height:9px;}
  ::-webkit-scrollbar-thumb{background:#2b3654;border-radius:5px;}
  ::-webkit-scrollbar-track{background:transparent;}
  /* 공통 네비는 PC 전용 페이지 — 모바일(UA)에선 아래(서버 $mobile)에서 숨김 */
</style>
PAGE;
nav_css();                 // 공통 네비 CSS (PC 가로 메뉴)
// PC/4K 분석 전용 → 모바일(UA, 서버 $mobile)이면 상단 네비 숨김 (폭 아님)
if (!empty($GLOBALS['mobile'])) echo "<style>.top-nav-bar{display:none!important;}</style>";
echo "</head>\n<body>\n";
render_nav('updash');      // 상단 네비 바 (PC 전용 노출)
echo <<<'PAGE'
<div id="shell">
  <header>
    <div class="logo"><i></i>상승종목 분석</div>
    <span class="badge" id="liveBadge" title="클릭하면 화면을 새로고침합니다">LIVE</span>
    <span class="qtime" id="qtime"></span>
    <div class="clock" id="clock"></div>
  </header>
  <div id="topbar">
    <div class="sbox">
      <input id="stkSearch" type="text" autocomplete="off" placeholder="🔍 종목명 또는 코드 검색"/>
      <div id="sugBox"></div>
    </div>
    <div class="recent-wrap" id="recentWrap"><span class="rlbl">최근조회</span></div>
  </div>
  <div id="grid">
    <section class="col">
      <div class="col-head">
        <span class="seg"><button id="segRate" type="button" class="on">상승률</button><button id="segCap" type="button">시총</button></span>
        <span class="sub" id="rangeSub">실시간 시세</span>
        <span class="pager"><button id="btnFirst" type="button" disabled>처음</button><button id="btnNext" type="button">다음</button></span></div>
      <div class="row-head"><span>종목 / 코드</span><span style="text-align:right">등락률</span><span style="text-align:right" id="colMetric">거래대금</span><span style="text-align:right">회전율</span></div>
      <div class="col-body" id="stockList"><div class="list-msg">불러오는 중…</div></div>
    </section>
    <section class="col">
      <div class="col-head"><h2>뉴스</h2><span class="sub" id="newsSub"></span></div>
      <div class="col-body" id="newsList">
        <div class="news-empty">왼쪽에서 종목을 선택하면<br/>관련 뉴스를 불러옵니다.</div>
      </div>
    </section>
    <section class="col">
      <div id="selBar">
        <span class="snm" id="selName">종목을 선택하세요</span>
        <span class="scode" id="selCode"></span>
        <span class="sprice" id="selPrice"></span>
        <span class="schg" id="selChg"></span>
      </div>
      <div class="charts">
        <div class="chart-block">
          <div class="chart-bar"><span class="lbl" id="dailyLbl">일봉</span><button type="button" id="toggleTodayHigh" class="cline-chip on" title="당일 기준 전고점(직전 60일 최고가) 수평선 — 관찰용 기준선">당일전고</button><button type="button" id="toggleCurPrice" class="cline-chip" title="현재가격선 표시/숨김">현재가</button><span id="dPBar"></span><span id="dIBar"></span><span id="dIndLeg" class="dc-leg-dark"></span><span class="px">OHLC + 거래량</span></div>
          <div class="chart-host" id="dailyChart"></div>
        </div>
        <div class="chart-block bottom">
          <div class="chart-bar"><span class="lbl">분봉</span><span class="px">당일 09:00~15:30 · 1분</span></div>
          <div class="chart-host" id="minuteChart"></div>
        </div>
      </div>
    </section>
  </div>
</div>

<script>
const API = 'stock_analysis_api.php';
const css = n => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
const UP = css('--up'), DOWN = css('--down');
const $ = id => document.getElementById(id);

function fmtVal(eok){ // 거래대금: 입력은 '억' 단위
  eok = Number(eok) || 0;
  if(eok>=10000) return (eok/10000).toFixed(1)+'조';
  return Math.round(eok).toLocaleString()+'억';
}
function fmtPrice(p){return Math.round(Number(p)||0).toLocaleString();}
function fmtRate(r){r=Number(r)||0; return (r>=0?'+':'')+r.toFixed(2)+'%';}
// 날짜·시각 축 포맷(한글 날짜·HH:MM)은 style/dailychart.js 모듈이 맡는다

/* ---- 차트 (공용 모듈 style/dailychart.js — 당일전고·현재가선 포함) ----
 * 구 신호칩('60봉 신고가 돌파+거래량 2배' 흰칩·전고점선)은 2026-08-02 폐기 — 정적 추정
 * 승률이 실측과 어긋나는 잘못된 신호였다(상승확률 엔진 폐기의 마무리). */
let daily=null, minute=null;   // DailyChart 핸들 (load 후 생성)
const dcReady = DailyChart.load().then(()=>{
  daily = DailyChart.create('dailyChart', {theme:'dark', todayHigh:true, curPrice:'#d9a441',
                                           key:'updash', legend:'dIndLeg'});
  minute = DailyChart.create('minuteChart', {theme:'dark', kind:'minute'});
  // 기간 바 [일봉|주봉 ┃ 기간 4개] = 공용 컴포넌트 (일봉 160/240/480/전체 ↔ 주봉 24/48/96주/전체)
  DailyChart.periodBar('dPBar', daily, {
    theme: 'dark',
    defaultIndex: 0,   // 160일 (기존 기본값 유지)
    onChange: info => { $('dailyLbl').textContent = info.tf==='week' ? '주봉' : '일봉'; }
  });
  DailyChart.indicatorBar('dIBar', daily, { theme: 'dark', key: 'updash' });   // 사용자 지표 + 차트틀
});
// '당일전고' 칩 토글 (기본 ON)
$('toggleTodayHigh').onclick=function(){
  const on=!this.classList.contains('on');
  this.classList.toggle('on', on);
  if(daily) daily.setTodayHigh(on);
};
// 일봉 '현재가' 칩 토글 (기본 OFF, 실선)
$('toggleCurPrice').onclick=function(){
  const on=!this.classList.contains('on');
  this.classList.toggle('on', on);
  if(daily) daily.setCurPrice(on);
};
// 기간 바(일봉/주봉·기간)는 위 periodBar 가 dcReady 이후 #dPBar 에 그린다

/* ---- 목록(top30, 커서 페이징) ---- */
const listEl=$('stockList');
let STOCKS=[];
let pageStart=0;       // 현재 페이지 첫 종목의 (순위-1): 0, 30, 60 …
let nextCursor=null;   // 다음 페이지 커서 {val, code} = 현재 페이지 마지막 종목
let loadingList=false;
let LIST_SORT='rate';  // 'rate'=상승률상위 / 'cap'=시총상위

// ETF 편입 뱃지: 이 종목을 상위 편입(top_rank)한 ETF 수 → 티어 색상 (etf_stock.php get_etf_badge_html 로직 차용)
function etfBadge(n){
  n=Number(n)||0;
  if(n<=0) return '';
  const cls = n>=100?'et4' : n>=50?'et3' : n>=20?'et2' : 'et1';
  const icon = n>=100?'💎' : '';
  return `<span class="etfb ${cls}" title="상위 편입 ETF ${n}개">${icon}${n}</span>`;
}
async function loadPage(cursor, startRank){
  if(loadingList) return;
  loadingList=true; $('btnFirst').disabled=true; $('btnNext').disabled=true;
  let url=API+`?action=top30&sort=${LIST_SORT}`;
  if(cursor) url+=`&before=${cursor.val}&before_code=${encodeURIComponent(cursor.code)}`;
  let rows=[];
  try{ rows=await fetch(url).then(r=>r.json()); }catch(e){ rows=[]; }
  rows=Array.isArray(rows)?rows:[];
  loadingList=false;

  if(!rows.length){
    if(startRank===0){   // 첫 페이지가 비었을 때만 메시지
      const msg=LIST_SORT==='cap'?'시총 데이터가 없습니다.':'상승 종목이 없습니다.<br/>(장 시작 전이거나 데이터 갱신 전)';
      STOCKS=[]; listEl.innerHTML=`<div class="list-msg">${msg}</div>`;
      $('rangeSub').textContent=''; $('btnFirst').disabled=true; $('btnNext').disabled=true;
    }else{               // 다음 페이지가 없으면 현재 유지 + 다음 비활성
      $('btnNext').disabled=true; $('btnFirst').disabled=false;
    }
    return;
  }

  pageStart=startRank; STOCKS=rows;
  renderList();
  const last=rows[rows.length-1];
  nextCursor={val:last[LIST_SORT], code:last.code};
  $('rangeSub').textContent=`${pageStart+1}–${pageStart+rows.length}위`;
  $('btnFirst').disabled=(pageStart===0);
  $('btnNext').disabled=(rows.length<30);   // 30개 미만이면 더 이상 없음
}

/* 퀀트 배지 — 유형(오늘 최고 거래대금 경신 시)·급등⚠·박스 상태. 판정은 서버(top30 API)가
 * krx_amt/krx_surge 원장으로 내리고 여기선 그리기만 한다(임계·어휘 = 퀀트 화면과 동일). */
const escq = t => String(t||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');
function quantBadges(q){
  if(!q) return '';
  let h='';
  if(q.t)   h+=`<span class="qb qb-${q.cls}" title="${escq(q.tip)}">${q.t}</span>`;
  /* 20·40거래일 모멘텀 — 창별로 「20일 +112%」처럼. 급등은 실측 근거가 있어 경고색(qb-hot),
     급락은 근거 없이 급등 임계의 로그 대칭일 뿐이라 정보색(qb-cold). 퀀트 화면과 같은 어휘. */
  (q.mom||[]).forEach(m=>{
    const up = m.hot ? '급등' : '급락';
    const why = m.hot
      ? ` 급등 — 이 무리의 돌파 매수는 백테스트에서 중앙 0%·승률 51.6%로 엣지가 없습니다(평균만 +7%인 복권꼬리). 금지가 아니라 고지입니다.`
      : ` 급락 — 임계는 급등의 로그 대칭이라 백테스트 근거가 없습니다. 얼마나 빠르게 빠졌는지 보는 참고 표시입니다.`;
    // ★ 기준 시점을 반드시 밝힌다 — 이 화면은 <b>현재가</b> 기준이다(퀀트 목록의 신호일 기준과 다르다)
    h+=`<span class="qb ${m.hot?'qb-hot':'qb-cold'}" title="${escq('현재가 기준 '+m.w+'거래일 '+(m.v>0?'+':'')+m.v+'%'+why)}">${m.w}일 ${m.v>0?'+':''}${m.v}%</span>`;
  });
  if(q.bx)  h+=`<span class="qbx ${q.bx.st}" title="${escq(q.bx.tip)}">${q.bx.txt}</span>`;
  return h;
}
function renderList(){
  listEl.innerHTML='';
  STOCKS.forEach((s,i)=>{
    const up=Number(s.rate)>=0;
    const row=document.createElement('div');
    row.className='row'; row.dataset.code=s.code;
    const metric=LIST_SORT==='cap'?s.cap:s.tradeEok;
    const cap=Number(s.cap)||0, trade=Number(s.tradeEok)||0;
    const turn=cap>0 ? (trade/cap*100) : 0;       // 회전율(%) = 거래대금/시총
    const etfTag=s.kind==='etf'?'<span class="ktag">ETF</span>':'';
    row.innerHTML=`<div class="nm"><b><span class="rank">${pageStart+i+1}</span>${s.name}${etfTag}${etfBadge(s.etfTop)}</b>
        <span class="mono" style="padding-left:34px">${s.code}${quantBadges(s.q)}</span></div>
      <div class="rate mono ${up?'up':'down'}">${fmtRate(s.rate)}</div>
      <div class="val mono">${fmtVal(metric)}</div>
      <div class="turn mono">${turn>0?turn.toFixed(1)+'%':'–'}</div>`;
    row.onclick=()=>selectStock(s,row);
    listEl.appendChild(row);
  });
  const first=listEl.querySelector('.row'); if(first) first.click();
}

$('btnFirst').onclick=()=>loadPage(null,0);
$('btnNext').onclick=()=>{ if(nextCursor) loadPage(nextCursor, pageStart+STOCKS.length); };

/* ---- 정렬 토글: 상승률상위 ↔ 시총상위 ---- */
function setListSort(sort){
  if(LIST_SORT===sort || loadingList) return;
  LIST_SORT=sort;
  $('segRate').classList.toggle('on', sort==='rate');
  $('segCap').classList.toggle('on', sort==='cap');
  $('colMetric').textContent = sort==='cap' ? '시가총액' : '거래대금';
  nextCursor=null;
  loadPage(null,0);   // 첫 페이지부터 새 정렬로
}
$('segRate').onclick=()=>setListSort('rate');
$('segCap').onclick=()=>setListSort('cap');

/* ---- 종목 선택 → 뉴스/일봉/분봉 동시 fetch (Promise.all) ---- */
let CUR_STOCK=null;            // 현재 선택 종목
async function selectStock(s,row){
  document.querySelectorAll('.row.on').forEach(r=>r.classList.remove('on'));
  if(row) row.classList.add('on');
  CUR_STOCK=s;
  const up=Number(s.rate)>=0;
  $('selName').textContent=s.name;
  $('selCode').textContent=s.code;
  $('selPrice').textContent=fmtPrice(s.price);
  const chg=$('selChg'); chg.textContent=fmtRate(s.rate); chg.className='schg mono '+(up?'up':'down');
  $('newsSub').textContent=s.name;
  $('newsList').innerHTML='<div class="news-empty">불러오는 중…</div>';

  const [news,d,m]=await Promise.all([
    fetch(`${API}?action=news&code=${s.code}`).then(r=>r.json()).catch(()=>[]),
    DailyChart.fetchDaily(s.code,1000).catch(()=>[]),  // 1000영업일(≈4년) 한 번 — 표시는 bindPeriod 슬라이스
    DailyChart.fetchMinute(s.code).catch(()=>[]),
  ]);

  await dcReady;
  daily.setData(d);     // 당일전고선은 모듈이 그린다
  minute.setData(m);
  renderNews(Array.isArray(news)?news:[]);
}
// 기간 토글은 bindPeriod 가 관리 — 재조회 없이 1000일 원본을 슬라이스만 한다
function renderNews(list){
  const el=$('newsList'); el.innerHTML='';
  if(!list.length){ el.innerHTML='<div class="news-empty">관련 뉴스가 없습니다.</div>'; return; }
  list.forEach(n=>{
    const a=document.createElement('a');
    a.className='news'; a.href=n.url||'#';
    a.onclick=(e)=>{ e.preventDefault(); if(n.url) window.open(n.url,'news_popup','width=800,height=900,left=200,top=100,scrollbars=yes'); };
    a.innerHTML=`<div class="t">${n.title}</div><div class="d">${n.date||''}</div>`;
    el.appendChild(a);
  });
}

/* ---- 종목 검색 (자동완성) + 최근조회(etf_stock.php와 etf_recent_view_stocks 공유) ---- */
const searchEl=$('stkSearch'), sugBox=$('sugBox');
let SUG=[], sugIdx=-1, searchTimer=null;
function hideSug(){ sugBox.style.display='none'; sugIdx=-1; }
function renderSug(){
  if(!SUG.length){
    sugBox.innerHTML='<div class="sug" style="cursor:default;color:var(--ink-mute)">검색 결과 없음</div>';
    sugBox.style.display='block'; return;
  }
  sugBox.innerHTML=SUG.map((s,i)=>{
    const up=Number(s.rate)>=0;
    const etfTag=s.kind==='etf'?'<span class="ktag">ETF</span>':'';
    return `<div class="sug${i===sugIdx?' on':''}" data-i="${i}"><b>${s.name}</b>${etfTag}<span class="c">${s.code}</span>`+
           `<span class="mono ${up?'up':'down'}" style="margin-left:auto;font-size:12px">${fmtRate(s.rate)}</span></div>`;
  }).join('');
  sugBox.style.display='block';
  sugBox.querySelectorAll('.sug[data-i]').forEach(el=>{ el.onclick=()=>pickStock(SUG[+el.dataset.i]); });
}
async function doSearch(kw){
  kw=kw.trim();
  if(!kw){ hideSug(); return; }
  try{ SUG=await fetch(`${API}?action=search&keyword=${encodeURIComponent(kw)}`).then(r=>r.json()); }
  catch(e){ SUG=[]; }
  SUG=Array.isArray(SUG)?SUG:[]; sugIdx=-1; renderSug();
}
function pickStock(s){ if(!s) return; hideSug(); searchEl.value=''; openStock(s); }
searchEl.oninput=()=>{ clearTimeout(searchTimer); searchTimer=setTimeout(()=>doSearch(searchEl.value),200); };
searchEl.onkeydown=(e)=>{
  if(sugBox.style.display!=='block') return;
  if(e.key==='ArrowDown'){ e.preventDefault(); sugIdx=Math.min(sugIdx+1,SUG.length-1); renderSug(); }
  else if(e.key==='ArrowUp'){ e.preventDefault(); sugIdx=Math.max(sugIdx-1,0); renderSug(); }
  else if(e.key==='Enter'){ e.preventDefault(); if(sugIdx>=0) pickStock(SUG[sugIdx]); else if(SUG.length) pickStock(SUG[0]); }
  else if(e.key==='Escape'){ hideSug(); }
};
document.addEventListener('click',(e)=>{ if(!sugBox.contains(e.target)&&e.target!==searchEl) hideSug(); });

/* 종목 열기: 차트/뉴스 표시 + 최근조회 저장(공유 테이블). 칩 등 시세 없는 입력은 검색으로 보강 */
async function openStock(s){
  let full=s;
  if(!s.price){
    try{ const r=await fetch(`${API}?action=search&keyword=${encodeURIComponent(s.code||s.name)}`).then(x=>x.json());
         if(Array.isArray(r)&&r.length) full=r.find(x=>x.code===s.code)||r[0]; }catch(e){}
  }
  document.querySelectorAll('.row.on').forEach(x=>x.classList.remove('on'));
  selectStock(full,null);
  try{ await fetch(`${API}?action=saverecent&code=${encodeURIComponent(full.code)}&name=${encodeURIComponent(full.name)}`); }catch(e){}
  loadRecent();
}

/* 최근조회 칩 (etf_stock.php 종목 조회와 동일 목록) */
async function loadRecent(){
  let list=[];
  try{ list=await fetch(`${API}?action=recent`).then(r=>r.json()); }catch(e){}
  list=Array.isArray(list)?list:[];
  const wrap=$('recentWrap');
  wrap.innerHTML='<span class="rlbl">최근조회</span>';
  list.forEach(it=>{
    const code=it.stock_code||it.code, name=it.stock_name||it.name;
    if(!code||!name) return;
    const b=document.createElement('button'); b.className='rchip'; b.type='button'; b.textContent=name;
    b.onclick=()=>openStock({code,name,price:0,rate:0,cap:0,tradeEok:0});
    wrap.appendChild(b);
  });
}

/* ---- 시세 갱신 시각 + LIVE 새로고침(네이버 시세 재수집) ---- */
async function loadMeta(){
  try{ const m=await fetch(`${API}?action=meta`).then(r=>r.json());
       if(m&&m.updated) $('qtime').innerHTML=`<b>${m.updated}</b> 기준 (KRX 장중)`; }catch(e){}
}
let liveBusy=false;
async function refreshLive(){   // 화면 새로고침: DB 최신값으로 리스트·시각 다시 그림(시세 수집 아님)
  if(liveBusy) return; liveBusy=true;
  const badge=$('liveBadge');
  badge.classList.add('loading'); badge.textContent='새로고침…';
  await loadMeta();
  nextCursor=null; await loadPage(null,0);   // 첫 페이지부터 다시
  badge.classList.remove('loading'); badge.textContent='LIVE'; liveBusy=false;
}
$('liveBadge').onclick=refreshLive;

/* 시계 */
function tick(){ $('clock').textContent=new Date().toLocaleString('ko-KR',{hour12:false}); }
tick(); setInterval(tick,1000);

loadPage(null,0);
loadRecent();
loadMeta();
</script>
</body>
</html>
PAGE;
}







?>
