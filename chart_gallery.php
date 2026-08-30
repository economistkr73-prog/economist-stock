<?php
/* ============================================================================
 * chart_gallery.php — 공용 일봉차트 모듈(style/dailychart.js) 스타일 갤러리
 *
 * 사이트에 적용된 차트 구성 5종을 <b>같은 종목 데이터</b>로 한 화면에 그린다.
 * 스타일(색·오버레이·마커)을 통일할 때 여기서 보면서 모듈을 고치면
 * 실제 화면 5곳에 그대로 반영된다 — 이 페이지가 곧 살아 있는 스타일 가이드다.
 * ========================================================================== */
require_once "./env/cnt.inc";
require_once "./env/auth_fnc.php";
require_login();
require_once "./env/nav.inc";

/* 화면별 「기능 구성」 — 원본 카탈로그는 classes/ChartFeat.class,
 * 저장은 chart_pref.features_json(카탈로그와의 차분만). 여기서 ChartIndicator 를 한 번 만들면
 * ensureTable 이 컬럼까지 맞춰 둔다 — 설정 화면을 여는 것이 곧 이행이다. */
$ci_feat  = new ChartIndicator($pdo);
$feat_over = $ci_feat->featuresAll();
$FEAT_VAL = [];
$FEAT_VIEW = [];      // 화면이 기억한 보기 값 {h, tf, day{}, week{}} — 높이 칸이 읽고 쓴다
foreach (array_keys(ChartFeat::SCREENS) as $sk) {
    $FEAT_VAL[$sk]  = ChartFeat::merge($sk, $feat_over[$sk] ?? null);
    $FEAT_VIEW[$sk] = $ci_feat->viewOf($sk);
}

header('Content-Type: text/html; charset=utf-8');
// embed=1 — 주식포트폴리오 「설정 > 차트 설정」 iframe 에 얹힐 때: 사이트 네비 없이 본문만
$embed = !empty($_GET['embed']);
echo "<!DOCTYPE html><html lang='ko'><head><meta charset='utf-8'>";
echo "<meta name='viewport' content='width=device-width, initial-scale=1.0'>";
echo "<title>차트 스타일 갤러리</title>";
echo "<script src='/style/dailychart.js?v=56'></script>";
echo "<script src='/style/bandchart.js?v=3'></script>";      // 구성 ⑥ 밴드형 (dailychart 위의 얇은 층)
if (!$embed) nav_css();
echo <<<'HTML'
<style>
  /* 다크 계열(단타) 차트가 읽는 CSS 변수 — 모듈 dark 테마의 원천과 같은 값 */
  :root{--up:#e8493f;--down:#2f7bd6;--ink-dim:#8893ab;--ink-mute:#5b6884;--line:#26304a;--accent:#d9a441}
  body{margin:0;background:#f4f7fa;color:#22303f;
    font-family:Pretendard,-apple-system,BlinkMacSystemFont,'Malgun Gothic',sans-serif;font-size:14px}
  .wrap{max-width:1240px;margin:0 auto;padding:18px 16px 60px}
  h2{margin:8px 0 2px}
  .sub{color:#6b7c8e;font-size:13px;margin-bottom:14px}
  .card{background:#fff;border:1px solid #e3eaf0;border-radius:10px;padding:14px 16px;margin:16px 0}
  .card.dark{background:#0e1320;border-color:#26304a;color:#dfe6f2}
  .card h3{margin:0 0 2px;font-size:15px}
  .card .use{font-size:12px;color:#8893ab;margin-bottom:10px}
  .card .use a{color:#1d5c93;text-decoration:none}
  .card.dark .use a{color:var(--accent)}
  .host{height:330px;position:relative}
  .host.sm{height:220px}
  /* 상단 공통 컨트롤 */
  .bar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:#fff;
    border:1px solid #e3eaf0;border-radius:10px;padding:10px 12px;position:sticky;top:8px;z-index:20;
    box-shadow:0 2px 8px rgba(10,25,45,.06)}
  .bar input{width:110px;padding:7px 10px;border:1px solid #c9d4de;border-radius:7px;font-size:13px;font-family:inherit}
  .btn{border:none;cursor:pointer;border-radius:7px;font-size:13px;font-weight:700;padding:8px 14px;line-height:1.3}
  .btn-outline{background:#fff;border:1px solid #c9d4de;color:#3c4d5e}
  .btn-outline:hover{background:#eef3f7}
  .btn-outline.btn-primary{background:#1d5c93;border-color:#1d5c93;color:#fff}
  .bar .st{font-size:12px;color:#6b7c8e;margin-left:auto}
  .note{font-size:12px;color:#6b7c8e;margin-top:8px}
  .card.dark .note{color:#8893ab}
  /* 사용자 지표 관리 */
  .ind-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:8px 0}
  .ind-row label{display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#3c4d5e;font-weight:600}
  .ind-row input,.ind-row select,.ind-row textarea{padding:6px 9px;border:1px solid #c9d4de;border-radius:7px;font-size:13px;font-family:inherit}
  .ind-row input[type=color]{padding:1px 2px;width:40px;height:30px}
  /* 수식칸 — 여러 문장을 줄로 쓰는 편이 읽기 좋다 (키움 수식창처럼) */
  .ind-slot{align-items:flex-start}
  .ind-slot textarea{flex:1;min-width:210px;resize:vertical;line-height:1.5;
    font-family:Consolas,'Malgun Gothic',monospace;font-size:12.5px}
  .ind-row .ext-lbl{color:#6b7c8e;font-weight:600;font-size:12px}
  /* 본문이 빈 수식칸 — 켜고 끌 것이 없다(체크 상태를 저장하지도 않는다) */
  .ind-row label.slot-na{opacity:.42}
  .ind-row .sl-w{width:64px}
  .ind-row .sl-st,.ind-row .sl-exs{width:104px;font-family:Consolas,'Malgun Gothic',monospace}
  .ind-row .sl-ext{width:34px;padding-left:5px;padding-right:2px;text-align:center}   /* 한 자리면 충분 */
  /* 변수 표 — 이름 1번 + 일/주/월 값 열 */
  .var-grid{display:grid;grid-template-columns:120px 84px 84px 84px 84px;gap:4px 8px;
    align-items:center;margin:6px 0 8px;width:max-content}
  .var-grid input{padding:6px 9px;border:1px solid #c9d4de;border-radius:7px;font-size:13px;font-family:inherit;width:100%;box-sizing:border-box}
  .vg-head{font-size:12px;font-weight:800;color:#fff;background:#22303f;border-radius:6px;
    text-align:center;padding:4px 0}
  /* 축 머리글 = 「그 축에서 이 지표를 보여 줄까」 체크칸 (끄면 그 시간축 차트에서 안 그린다) */
  .vg-head.tf{display:flex;align-items:center;justify-content:center;gap:5px;cursor:pointer;user-select:none}
  .vg-head.tf input{width:13px;height:13px;margin:0;accent-color:#d9a441;cursor:pointer}
  .vg-head.tf.off{background:#8496a6}
  /* 지표 관리 머리 — 제목 + 신규등록 */
  .ind-head{display:flex;align-items:center;gap:10px}
  .ind-head h3{margin:0}
  .btn-new{padding:5px 12px;font-size:12.5px;margin-left:auto}
  /* 편집 폼 — 평소엔 숨김. 열리면 카드 안에서 한 덩어리로 보이게 */
  #indForm{margin-top:12px;padding:12px 14px 4px;border:1px solid #dde5ec;border-radius:10px;background:#fafcfe}
  #indForm .form-top{margin-top:0}
  #indForm .form-title{font-size:13px;font-weight:800;color:#22303f;padding-right:2px}
  #indForm .form-close{margin-left:auto;padding:4px 10px;font-size:13px;line-height:1;color:#8496a6}
  /* 지표 목록 — 카드 한 줄에 이름·구성 뱃지만. 수식은 마우스를 올리면 보인다 */
  #indList{display:flex;flex-direction:column;gap:6px;margin-bottom:14px}
  #indList .empty{color:#8893ab;font-size:13px;padding:10px 2px}
  .ind-card{position:relative;display:flex;align-items:center;gap:9px;flex-wrap:wrap;
    padding:9px 12px;border:1px solid #e3eaf0;border-radius:10px;background:#fff;
    transition:border-color .12s,box-shadow .12s}
  .ind-card:hover{border-color:#c9d4de;box-shadow:0 2px 9px rgba(10,25,45,.07)}
  .ind-card .dot{width:10px;height:10px;border-radius:50%;flex:none}
  .ind-card .nm{font-size:13.5px;font-weight:800;color:#22303f;letter-spacing:-.01em}
  .ind-card .shown{font-size:11.5px;font-weight:700;color:#8496a6}
  .ind-card .tags{display:flex;align-items:center;gap:5px;flex-wrap:wrap;margin-left:auto}
  .tag{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;
    font-size:11px;font-weight:700;white-space:nowrap;background:#eef3f7;color:#5b6c7d}
  .tag i{width:7px;height:7px;border-radius:50%;display:inline-block;flex:none}
  .tag i.ln{border-radius:1px}   /* 선 견본 — 크기·모양은 인라인 스타일이 정한다 */
  .tag.draw{background:#eaf1f8;color:#1d5c93}
  .tag.draw.point{background:#f3eefa;color:#6b3fa0}
  .tag.ext{background:#fdf2e4;color:#a2690f}
  .tag.tf{background:#e9f5ee;color:#1d6b46}   /* 일부 축에서만 그리는 지표 */
  .tag.off{opacity:.45;text-decoration:line-through}
  .tag.var{background:#fff;border:1px solid #e3eaf0;color:#6b7c8e;font-weight:600}
  .tag.var b{color:#3c4d5e;font-weight:800}
  /* ⋯ 한 개로 수정·삭제를 모은다 */
  .ind-more{flex:none;width:28px;height:26px;border:1px solid #e3eaf0;border-radius:7px;background:#fff;
    cursor:pointer;color:#8496a6;font-size:15px;font-weight:800;line-height:1;padding:0}
  .ind-more:hover{background:#eef3f7;color:#3c4d5e}
  .ind-menu{position:absolute;top:calc(100% - 2px);right:10px;z-index:30;display:flex;flex-direction:column;
    min-width:104px;padding:4px;border:1px solid #dde5ec;border-radius:9px;background:#fff;
    box-shadow:0 8px 22px rgba(10,25,45,.16)}
  .ind-menu button{border:0;background:none;cursor:pointer;text-align:left;font-family:inherit;
    font-size:12.5px;font-weight:700;color:#3c4d5e;padding:7px 10px;border-radius:6px}
  .ind-menu button:hover{background:#eef3f7}
  .ind-menu button.del{color:#c0241a}
  .ind-menu button.del:hover{background:#fdecea}
  /* ── 알림·확인 모달 (alert 대신) ──
   * 저장이 검증까지 하므로 「무엇이 왜 안 됐는지」를 말하는 자리가 하나뿐이다. */
  .dlg-bd{position:fixed;inset:0;z-index:200;display:flex;align-items:center;justify-content:center;
    padding:20px;background:rgba(12,22,36,.44);animation:dlgFade .12s ease-out}
  .dlg{width:100%;max-width:470px;background:#fff;border-radius:14px;overflow:hidden;
    box-shadow:0 18px 48px rgba(10,25,45,.3);animation:dlgUp .15s cubic-bezier(.2,.9,.3,1)}
  .dlg-h{display:flex;align-items:center;gap:10px;padding:17px 19px 0;font-size:15px;
    font-weight:800;color:#22303f;letter-spacing:-.01em}
  .dlg-ico{flex:none;width:25px;height:25px;border-radius:50%;color:#fff;font-size:14px;font-weight:900;
    display:flex;align-items:center;justify-content:center;line-height:1}
  .dlg-ico.err{background:#e8493f}
  .dlg-ico.warn{background:#d9a441}
  .dlg-ico.ok{background:#2f9e6f}
  .dlg-b{padding:11px 19px 2px;font-size:13px;line-height:1.65;color:#3c4d5e;
    white-space:pre-wrap;word-break:break-word;max-height:52vh;overflow:auto}
  .dlg-b .em{display:block;margin:8px 0 2px;padding:9px 11px;border-radius:8px;background:#f5f8fb;
    border:1px solid #e3eaf0;font-family:Consolas,'Malgun Gothic',monospace;font-size:12.5px;color:#22303f}
  .dlg-b .hint{display:block;margin-top:9px;color:#8496a6;font-size:12.5px}
  .dlg-f{display:flex;justify-content:flex-end;gap:8px;padding:15px 19px 17px}
  @keyframes dlgFade{from{opacity:0}to{opacity:1}}
  @keyframes dlgUp{from{opacity:0;transform:translateY(10px) scale(.985)}to{opacity:1;transform:none}}
  .syntax{font-size:12.5px;line-height:1.8;color:#3c4d5e}
  .syntax code{background:#eef3f7;border-radius:4px;padding:1px 5px;font-family:Consolas,monospace}
  /* ── 화면별 구성 (기능 = 도구모음·레이어) ── */
  .scr-card{background:#fff;border:1px solid #e3eaf0;border-radius:10px;padding:13px 15px;margin:12px 0}
  .scr-hd{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-bottom:3px}
  .scr-hd h4{margin:0;font-size:14.5px;color:#22303f}
  .scr-hd .sty{font-size:11.5px;font-weight:800;color:#1d5c93;background:#eaf1f8;border-radius:20px;padding:2px 9px}
  .scr-hd .go{margin-left:auto;font-size:12px;color:#1d5c93;text-decoration:none}
  .scr-hd .go:hover{text-decoration:underline}
  .scr-note{font-size:12px;color:#6b7c8e;margin:0 0 9px}
  .scr-prev{margin-bottom:10px}
  .fgrp{margin-top:9px}
  .fgrp .gh{font-size:11.5px;font-weight:800;color:#8496a6;margin-bottom:5px}
  .fgrp .gh em{font-style:normal;font-weight:600;color:#a8b4c0;margin-left:6px}
  .flist{display:grid;grid-template-columns:repeat(auto-fill,minmax(232px,1fr));gap:5px 14px}
  .fitem{display:flex;align-items:flex-start;gap:7px;font-size:12.5px;color:#3c4d5e;
    padding:4px 6px;border-radius:7px}
  .fitem:hover{background:#f6f9fc}
  .fitem input{margin-top:2px}
  .fitem.lock{color:#8496a6}
  .fitem.na{color:#b6c0ca}
  .fitem b{font-weight:700;color:#22303f}
  .fitem.lock b,.fitem.na b{color:inherit}
  .fitem .why{display:block;font-size:11px;color:#8496a6;font-weight:400}
  .fitem.na .why{color:#c2ccd5}
  .scr-ft{display:flex;align-items:center;gap:10px;margin-top:11px;padding-top:9px;border-top:1px solid #eef3f7;
    flex-wrap:wrap}
  .scr-ft .st{font-size:12px;color:#8496a6;margin-left:auto}
  .hbox{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:700;color:#3c4d5e}
  .hbox input{width:76px;padding:5px 8px;border:1px solid #c9d4de;border-radius:7px;
    font-size:12.5px;font-family:inherit;text-align:right}
  .hbox .hnote{font-weight:400;color:#8496a6;font-size:11.5px}
  .btn-sm{padding:5px 11px;font-size:12px}
  .bareless{font-size:12px;color:#6b7c8e;line-height:1.9}
  .bareless b{color:#3c4d5e}
</style></head><body>
HTML;
if (!$embed) render_nav('chartgallery');
// 기능 카탈로그(원본)와 화면별 현재값 — 목록·체크리스트·미리보기를 전부 이 둘로 그린다
echo '<script>const FEAT_CAT=' . json_encode(ChartFeat::catalog(), JSON_UNESCAPED_UNICODE)
   . ';const FEAT_VAL=' . json_encode($FEAT_VAL, JSON_UNESCAPED_UNICODE)
   . ';const FEAT_VIEW=' . json_encode($FEAT_VIEW, JSON_UNESCAPED_UNICODE) . ';</script>';
// 이 화면의 미리보기 차트도 제 구성을 따른다 (SUE 마커 등 모듈이 스스로 켜는 것들)
echo ChartFeat::boot('gallery', $pdo);
echo <<<'HTML'
<div class="wrap">
  <h2>차트 스타일 갤러리</h2>
  <div class="sub">사이트에 적용된 차트 구성 6종을 <b>같은 종목</b>으로 나란히 그립니다 (①~⑤는 같은 일봉 데이터, ⑥ 밴드형만 원천이 다릅니다).
    통일하고 싶은 게 보이면 <b>style/dailychart.js 한 곳</b>만 고치면 아래 전부 + 실제 화면에 반영됩니다.
    (2·3번의 가격선·체결 마커는 스타일 확인용 <b>데모 값</b>입니다)</div>

  <div class="bar">
    <input id="gCode" value="005930" maxlength="6" placeholder="종목코드 6자리">
    <button type="button" class="btn btn-outline" onclick="gLoad()">조회</button>
    <span style="width:10px"></span>
    <span id="gPBar"></span>
    <span style="width:10px"></span>
    <span id="gIBar"></span>
    <span id="gIndLeg"></span>
    <span class="st" id="gStat">불러오는 중…</span>
  </div>

  <div class="card">
    <div class="ind-head">
      <h3>사용자 지표 관리</h3>
      <button type="button" class="btn btn-outline btn-new" onclick="indOpen()">＋ 신규등록</button>
    </div>
    <div class="use">여기서 만든 지표는 <b>모든 차트 화면의 지표 바</b>에서 골라 쓸 수 있습니다.
      선(─) = 수식 값을 선으로 · 점(●) = 조건이 참인 봉에 점.</div>
    <div id="indList"></div>
    <!-- 편집 폼 — 평소엔 접혀 있고 「신규등록」·「수정」에서만 열린다 -->
    <div id="indForm" hidden>
    <div class="ind-row form-top">
      <input type="hidden" id="inId">
      <span class="form-title" id="inTitle">새 지표</span>
      <label>이름 <input id="inName" maxlength="60" style="width:150px"></label>
      <label>방식 <select id="inDraw"><option value="line">선 긋기</option><option value="point">점 찍기(조건)</option></select></label>
      <!-- ★지표의 성질이지 차트의 성질이 아니다 — 계단선은 「지나간 단계가 얼마였나」를 묻지만
           이동평균처럼 최근값만 보는 선은 따라다녀 봐야 숫자가 흔들리기만 한다 -->
      <label title="차트에서 마우스가 짚은 봉의 값을 범례(차트 위 값 표시)에 보여 줍니다.&#10;끄면 늘 마지막 값입니다. 선 위에는 숫자를 띄우지 않습니다 — 캔들과 겹쳐 안 읽힙니다.&#10;계단선(최고거래량 H/L)처럼 지나간 단계의 값을 묻는 지표에 켜세요."><input type="checkbox" id="inHover">십자선 값 표시</label>
      <button type="button" class="btn btn-outline form-close" onclick="indClose()" title="닫기">✕</button>
    </div>
    <div class="ind-row syntax" style="margin-top:-2px;line-height:1.6">
      <span>지표 이름·수식칸 이름에 <code>#1</code>(첫 변수) 또는 <code>#변수이름</code>을 넣으면
        차트에서 <b>값</b>으로 보입니다 — <code>최고거래대금선(#1)</code> → 일봉 <b>최고거래대금선(240)</b> · 주봉 <b>(104)</b>.
        <code>#1</code>은 변수 이름을 바꿔도 안 깨지고, <code>#변수이름</code>은 여기서 이름을 바꾸면 자동으로 따라갑니다.</span>
    </div>
    <div class="ind-row" title="수식에서 쓰는 변수의 기본값 — 적용할 때 차트 옆에서 바꿀 수 있습니다">
      <label>변수</label>
      <span class="muted" style="font-size:12px">이름은 일/주/월/<b>분</b> 공통 — 값만 시간축별로.
        <b>비어 있는 칸은 일 값을 씁니다</b> (월봉은 추후 · 분은 단타 1분봉 화면에서 쓰입니다).
        ★<b>거래대금은 원 단위</b>입니다 — 100억 = <code>10000000000</code><br>
        머리글의 <b>체크칸</b>은 「그 축의 차트에서 이 지표를 그릴지」입니다 — 끄면 그 시간축에서는
        <b>선도 칩도 「＋지표」 목록에도 안 나옵니다</b>(축을 되돌리면 그대로 살아납니다). 넷 다 켜면 전 축입니다.</span>
    </div>
    <div class="var-grid">
      <span class="vg-head">이름</span><label class="vg-head tf" id="tfLD" title="일봉 차트에서 이 지표를 그립니다"><input type="checkbox" id="tfD" checked>일</label><label class="vg-head tf" id="tfLW" title="주봉 차트에서 이 지표를 그립니다"><input type="checkbox" id="tfW" checked>주</label><label class="vg-head tf" id="tfLM" title="월봉 차트에서 이 지표를 그립니다"><input type="checkbox" id="tfM" checked>월</label><label class="vg-head tf" id="tfLX" title="분봉 차트에서 이 지표를 그립니다"><input type="checkbox" id="tfX" checked>분</label>
      <input id="varN0" placeholder="이름"><input id="varD0" type="number" step="any"><input id="varW0" type="number" step="any"><input id="varM0" type="number" step="any"><input id="varX0" type="number" step="any">
      <input id="varN1" placeholder="이름"><input id="varD1" type="number" step="any"><input id="varW1" type="number" step="any"><input id="varM1" type="number" step="any"><input id="varX1" type="number" step="any">
      <input id="varN2" placeholder="이름"><input id="varD2" type="number" step="any"><input id="varW2" type="number" step="any"><input id="varM2" type="number" step="any"><input id="varX2" type="number" step="any">
      <input id="varN3" placeholder="이름"><input id="varD3" type="number" step="any"><input id="varW3" type="number" step="any"><input id="varM3" type="number" step="any"><input id="varX3" type="number" step="any">
    </div>
    <!-- 수식 정의 = 변수 계산 전용(그리지 않음) → 수식1~3 = 정의된 변수를 선/점으로 출력 -->
    <div class="ind-row ind-slot">
      <label title="변수 계산 전용 — 그래프를 그리지 않습니다. 여기서 정의한 변수를 수식1~3에서 씁니다">수식 정의</label>
      <textarea id="slDef" rows="6" placeholder="예:
봉수 = SUM(1);
최고_거래량 = HIGHEST(V, 변수_봉수);
조건 = V > 최고_거래량(1);
최고거래량_H = VALUEWHEN(1, 조건, H);"></textarea>
    </div>
    <div class="ind-row ind-slot">
      <label title="해제하면 이 선만 숨깁니다"><input type="checkbox" id="slOn0" checked>수식1</label>
      <input id="slName0" placeholder="이름" style="width:110px">
      <input type="color" id="slColor0" value="#d32f2f">
      <select id="slW0" class="sl-w" title="선 두께"></select>
      <select id="slSt0" class="sl-st" title="선 종류"></select>
      <label class="ext-lbl" title="계단선(VALUEWHEN 류)의 지나간 단계를 오른쪽 끝까지 옅은 선으로 연장합니다. 0=안 함">연장
        <input id="slExt0" type="number" min="0" max="10" step="1" placeholder="0" class="sl-ext">
        <select id="slExw0" class="sl-w" title="연장선 두께"></select>
        <select id="slEx0" class="sl-exs" title="연장선 종류"></select></label>
      <textarea id="slBody0" rows="2" placeholder="정의된 변수 또는 수식 — 예: 최고거래량_H"></textarea>
    </div>
    <div class="ind-row ind-slot">
      <label><input type="checkbox" id="slOn1" checked>수식2</label>
      <input id="slName1" placeholder="이름" style="width:110px">
      <input type="color" id="slColor1" value="#1565c0">
      <select id="slW1" class="sl-w" title="선 두께"></select>
      <select id="slSt1" class="sl-st" title="선 종류"></select>
      <label class="ext-lbl" title="지나간 단계를 오른쪽 끝까지 연장 (0=안 함)">연장
        <input id="slExt1" type="number" min="0" max="10" step="1" placeholder="0" class="sl-ext">
        <select id="slExw1" class="sl-w" title="연장선 두께"></select>
        <select id="slEx1" class="sl-exs" title="연장선 종류"></select></label>
      <textarea id="slBody1" rows="2" placeholder="정의된 변수 또는 수식 — 예: 최고거래량_L"></textarea>
    </div>
    <div class="ind-row ind-slot">
      <label><input type="checkbox" id="slOn2" checked>수식3</label>
      <input id="slName2" placeholder="이름" style="width:110px">
      <input type="color" id="slColor2" value="#1e9e74">
      <select id="slW2" class="sl-w" title="선 두께"></select>
      <select id="slSt2" class="sl-st" title="선 종류"></select>
      <label class="ext-lbl" title="지나간 단계를 오른쪽 끝까지 연장 (0=안 함)">연장
        <input id="slExt2" type="number" min="0" max="10" step="1" placeholder="0" class="sl-ext">
        <select id="slExw2" class="sl-w" title="연장선 두께"></select>
        <select id="slEx2" class="sl-exs" title="연장선 종류"></select></label>
      <textarea id="slBody2" rows="2" placeholder="정의된 변수 또는 수식"></textarea>
    </div>
    <div class="ind-row">
      <label style="flex:1">메모 <input id="inNote" maxlength="200" style="flex:1"></label>
      <!-- ★「검사」 버튼은 없앴다(2026-08-05 사용자 지시) — 저장이 검증까지 한다.
           따로 두면 «검사를 안 누르고 저장»이 되어, 안 그려지는 지표가 조용히 저장된다. -->
      <button type="button" class="btn btn-outline" onclick="indClose()">취소</button>
      <button type="button" class="btn" style="background:#22303f;color:#fff" onclick="indSave()">저장</button>
    </div>
    <details class="note"><summary>수식 문법 (클릭해서 열기)</summary>
      <div class="syntax">
        <b>심볼</b> — <code>C</code> 종가 · <code>O</code> 시가 · <code>H</code> 고가 · <code>L</code> 저가 ·
        <code>V</code> 거래량 · <code>AMT</code>(=<code>거래대금</code>) ·
        <code>시각</code>(=<code>HM</code>, 09:00→900) · <code>첫봉</code> · <code>막봉</code>(그 날 첫/마지막 봉이면 1)<br>
        <span style="color:#8893ab">※ <b>시각·첫봉·막봉은 분봉에서만</b> 값이 생깁니다(일·주·월 봉은 하루 안의 자리가 없어 전부 0).
        시가·종가 단일가 물량이 통째로 실리는 09:00·15:30 봉을 계산에서 뺄 때 씁니다 —
        예: <code>쓸봉 = 1 - 첫봉 - 막봉;</code></span><br>
        <span style="color:#8893ab">※ 거래대금은 <b>KRX 실제값</b>을 씁니다(포트폴리오·관심·시뮬레이터 종목, 2022년~).
        실제값이 없는 종목·기간은 <b>종가×거래량</b>으로 근사하며, 이 근사는 실측 중앙오차 0.99%라
        「전고 거래대금 돌파」 판정이 드물게 갈릴 수 있습니다.</span><br>
        <b>함수</b> — <code>MA(x,n)</code> 이동평균 · <code>SUM(x,n)</code> · <code>HIGHEST(x,n)</code> n봉 최고 ·
        <code>LOWEST(x,n)</code> · <code>STD(x,n)</code> 표준편차 · <code>REF(x,n)</code> n봉 전 값 ·
        <code>ABS</code>·<code>MIN</code>·<code>MAX</code>·<code>ROUND</code> ·
        <code>CROSS(a,b)</code> a가 b를 상향돌파하면 1<br>
        <b>연산</b> — <code>+ - * / %</code> · 비교 <code>&gt; &lt; &gt;= &lt;= == !=</code> ·
        논리 <code>&amp;&amp; || !</code> · 괄호<br>
        <b>변수</b> — 그 외 이름은 변수 칸의 기본값을 씁니다 (예: 변수 <code>N=20</code>, 수식 <code>MA(C,N)</code>).
        적용할 때 차트 옆에서 값만 바꿔 다시 적용할 수 있습니다.<br>
        <b>구조</b> — 위 <b>「수식 정의」</b> 칸에 <code>이름 = 수식;</code> 문장으로 중간 계산에 이름을 붙여 두고
        (한글 이름 가능·그래프는 그리지 않음), <b>수식1~3</b> 에 그 이름을 적으면 그 값이 선/점이 됩니다.
        선이 하나면 수식1만 채우면 됩니다.<br>
        <b>n봉 전 값</b> — <code>이름(1)</code> = 그 값의 1봉 전 (예: <code>최고량(1)</code>, <code>C(1)</code>).
        <code>REF(x,n)</code> 과 같습니다.<br>
        <b>추가 함수</b> — <code>SUM(x)</code> 인자 1개 = 누적합 ·
        <code>HIGHEST(x)</code>/<code>LOWEST(x)</code> 인자 1개 = <b>처음부터 지금까지</b>의 최고/최저
        (창 길이 없이 「신고가·신고 거래대금」을 판정할 때) ·
        <code>VALUEWHEN(n,조건,값)</code> = 최근 n번째로 조건이 참이었던 시점의 값 ·
        <code>and or not</code> 도 <code>&amp;&amp; || !</code> 대신 됩니다.<br>
        <b>예시</b> — 수식 정의: <code>최고량 = HIGHEST(V, 기준봉수); 조건 = V &gt; 최고량(1);
        고점H = VALUEWHEN(1, 조건, H); 저점L = VALUEWHEN(1, 조건, L);</code>
        → 수식1 <code>고점H</code> · 수식2 <code>저점L</code> (두 선)<br>
        <b>간단 예시</b> — 이동평균 3선: 정의 칸은 비우고 수식1 <code>MA(C,5)</code> · 수식2 <code>MA(C,20)</code> · 수식3 <code>MA(C,60)</code> ·
        볼린저: 정의 <code>중심 = MA(C,N); 폭 = STD(C,N)*K;</code> → 수식1 <code>중심+폭</code> · 수식2 <code>중심</code> · 수식3 <code>중심-폭</code><br>
        ※ 선 지표는 <b>가격 축</b>에 그려지므로 가격 스케일 값이 좋습니다. 워밍업 구간(앞 n봉)은 비어 있는 게 정상.
      </div>
    </details>
    </div><!-- /#indForm -->
  </div>

  <div class="card">
    <div class="ind-head"><h3>화면별 구성 — 도구모음·레이어</h3></div>
    <div class="use">아래 <b>스타일 5종</b>이 「어떻게 그리나」라면, 여기는 <b>「무엇을 보여주고 조작하게 하나」</b>입니다.
      두 축은 독립이라 같은 스타일을 쓰는 두 화면이 다른 구성을 가질 수 있습니다
      (②포트폴리오형을 함께 쓰는 「보유종목 상세」와 「종목추가 사다리」가 그렇습니다).
      바꾸면 <b>즉시 저장</b>되고 미리보기가 따라옵니다.</div>
    <div id="scrList"></div>
    <details class="note"><summary>도구모음이 없는 차트 (설정 대상 아님)</summary>
      <div class="bareless" id="barelessList"></div>
    </details>
  </div>

  <div class="card">
    <h3>1. 기본형 — 캔들 + 거래량 (라이트)</h3>
    <div class="use">사용처: <a href="/stock/index.php?mode=fund" target="_blank">재무분석 종목상세</a></div>
    <div class="host" id="c1"></div>
  </div>

  <div class="card">
    <h3>2. 포트폴리오형 — 가격선 3종 + 체결 칩 (라이트)</h3>
    <div class="use">사용처: <a href="/stock/index.php?mode=all" target="_blank">보유종목 → 종목 상세</a>
      · 가격선: <span style="color:#1e9e74">누적단가</span>/<span style="color:#d32f2f">자동매도가</span>/<span style="color:#1d5c93">다음매수가</span>
      · 칩: 매수 빨강/매도 파랑 2줄(체결+그날 시장상태)</div>
    <div class="host" id="c2"></div>
  </div>

  <div class="card">
    <h3>3. 시뮬레이터형 — 누적단가·자동매도가 계단선 + 마커 텍스트 (라이트)</h3>
    <div class="use">사용처: <a href="/stock/index.php?mode=sim" target="_blank">시뮬레이터</a>
      · 체결 마커는 칩 대신 라이브러리 글자(10년 구간용) · 거래량 투명도 더 낮음(44)
      · <b>SUE 공시 배지는 여기서도 칩</b> — 같은 사실은 어느 화면에서나 같은 모양이어야 합니다</div>
    <div class="host" id="c3"></div>
  </div>

  <div class="card dark">
    <h3>4. 분석형 — 당일전고 + 현재가 (다크)</h3>
    <div class="use">사용처: <a href="/stock/index.php?mode=short" target="_blank">단타</a>의 일봉 패널
      · 하늘색 실선 = 당일 기준 직전 60봉 최고가 (관찰용 기준선)</div>
    <div class="host" id="c4"></div>
    <div class="note">구 신호칩(신고가+거래량 흰칩·추정 승률)은 2026-08-02 폐기 — 정적 추정 승률이 실측과 어긋나는 잘못된 신호였습니다.</div>
  </div>

  <div class="card dark">
    <h3>5. 분봉 — 당일 09:00~15:30 · 1분 (다크)</h3>
    <div class="use">사용처: <a href="/stock/index.php?mode=short" target="_blank">단타</a>의 분봉 2종 (메인·보조)</div>
    <div class="host sm" id="c5"></div>
  </div>

  <div class="card">
    <h3>6. 밴드형 — PER·PBR 밴드 (라이트)</h3>
    <div class="use">사용처: <a href="/stock/index.php?mode=fund" target="_blank">재무분석 종목상세</a> 맨 아래
      · 캔들·거래량 없음 · 계단선 5개(과거 배수 분위수) + 주가선
      · <b>밴드선은 가격축에 참여한다</b> — 지표선을 축에서 빼는 규칙(구성 ①~⑤)과 정반대라 차트를 따로 둡니다</div>
    <div class="host" id="c6"></div>
    <div class="note">단위는 원(주당)이지만 계산은 <b>시가총액</b>입니다 — 주식수가 식에서 사라져 액면분할이 저절로 보정됩니다.
      계단이 꺾이는 날은 <b>DART 공시 다음 거래일</b>(SUE 마커와 같은 날). 도구모음이 없어 「화면별 구성」에는 올리지 않습니다.</div>
  </div>
</div>

<script>
DailyChart.load().then(function(){
  var stat = document.getElementById('gStat');
  var charts = [];   // 일봉 4개 — 전역 컨트롤이 한꺼번에 조작
  // 이 화면도 제 구성을 따른다 — 설정 화면이 제 설정을 안 지키면 그것부터 거짓말이 된다
  var GF = FEAT_VAL.gallery || {};
  /* screen:'', resize:true — 미리보기 5장은 «끌 수는 있되 기억하지 않는다».
     한 화면에 차트가 다섯이라 높이를 하나로 기억하면 다섯이 같이 움직인다(실제 화면은 하나뿐). */
  var c1 = DailyChart.create('c1', { theme:'light', key:'gallery', screen:'', resize:true,
                                     legend: +GF['legend.values'] ? 'gIndLeg' : null });
  var c2 = DailyChart.create('c2', { theme:'light', markers:{chips:true}, screen:'', resize:true });
  var c3 = DailyChart.create('c3', { theme:'light', volAlpha:'44', markers:{chips:false}, screen:'', resize:true });
  var c4 = DailyChart.create('c4', { theme:'dark', todayHigh:true, curPrice:'#d9a441', screen:'', resize:true });
  var c5 = DailyChart.create('c5', { theme:'dark', kind:'minute', screen:'', resize:true });
  charts = [c1, c2, c3, c4];

  // 시뮬레이터형 추가 선 (한 번 만들고 데이터만 갈아끼움)
  var simAvg  = c3.addLine({ color:'#1e9e74', width:2, style:'dashed' });
  var simSell = c3.addLine({ color:'#1565c0', width:1, style:'dotted', stepped:true });

  function sma(rows, n){
    var out = [], sum = 0;
    for (var i = 0; i < rows.length; i++) {
      sum += rows[i].close;
      if (i >= n) sum -= rows[i - n].close;
      if (i >= n - 1) out.push({ time: rows[i].time, value: Math.round(sum / n) });
    }
    return out;
  }

  // 기간 바 [일봉|주봉 ┃ 기간 4개] = 공용 컴포넌트 — 일봉 4개 차트를 한꺼번에 조작
  DailyChart.periodBar('gPBar', charts, { theme: 'light', defaultIndex: 1,
                                          fullscreen: !!+GF['fullscreen'] });   // 240일
  // 지표 바 — 저장된 사용자 지표를 골라 4개 차트에 동시 적용
  var ibar = DailyChart.indicatorBar('gIBar', charts, { theme: 'light', key: 'gallery',
                                                        preset: !!+GF['preset.select'] });
  var G_ROWS = [];   // 마지막 조회 일봉 — 지표 「검사」가 실데이터로 돌려 본다

  window.gLoad = function(){
    var code = document.getElementById('gCode').value.trim();
    if (!/^\d{6}$/.test(code)) { stat.textContent = '종목코드 6자리를 입력하세요'; return; }
    stat.textContent = '불러오는 중…';
    Promise.all([
      DailyChart.fetchDaily(code, 1000),
      DailyChart.fetchMinute(code).catch(function(){ return []; })
    ]).then(function(r){
      var rows = r[0], min = r[1];
      if (!rows.length) { stat.textContent = '일봉 데이터를 가져오지 못했습니다 (' + code + ')'; return; }
      G_ROWS = rows;
      var n = rows.length, L = rows[n-1].close;

      // SUE 공시 마커 — 갤러리도 실제 화면과 같게(구성이 켜져 있으면) 모듈이 얹는다
      charts.forEach(function(dc){ dc.setCode(code); dc.setData(rows); });
      c5.setData(min);

      // 2번 데모: 가격선 3종 + 체결 3건
      c2.setPriceLines([
        { price: Math.round(L*0.92), color:'#1e9e74', style:'solid'  },
        { price: Math.round(L*1.12), color:'#d32f2f', style:'dashed' },
        { price: Math.round(L*0.85), color:'#1d5c93', style:'dashed' }
      ]);
      c2.setMarkers([
        { time: rows[Math.max(0,n-95)].time, sell:false,
          text: '1차 3주 @' + rows[Math.max(0,n-95)].close.toLocaleString(), state: '역배열 · 과매도' },
        { time: rows[Math.max(0,n-50)].time, sell:false,
          text: '2차 5주 @' + rows[Math.max(0,n-50)].close.toLocaleString() },
        { time: rows[Math.max(0,n-15)].time, sell:true,
          text: '매도 4주 @' + rows[Math.max(0,n-15)].close.toLocaleString() }
      ]);

      // 3번 데모: 누적단가=SMA20 · 자동매도가=그 108% 계단선(후반부만) · 글자 마커
      var avg = sma(rows, 20);
      simAvg.setData(avg);
      simSell.setData(avg.map(function(p, i){
        return { time: p.time, value: i > avg.length/2 ? Math.round(p.value*1.08) : null };
      }));
      c3.setMarkers([
        { time: rows[Math.max(0,n-95)].time, sell:false, text:'1차 과매도' },
        { time: rows[Math.max(0,n-15)].time, sell:true,  text:'매도' }
      ]);

      // 6번: 밴드형 — 데이터 원천이 달라(재무+시총 원장) 일봉과 따로 받는다
      BandChart.render(code, { per: 'c6' }, 5);

      stat.textContent = code + ' · ' + n + '거래일 (' + rows[0].time + ' ~ ' + rows[n-1].time + ')'
        + ' · 분봉 ' + min.length + '개';
    }).catch(function(e){ stat.textContent = '조회 실패: ' + e; });
  };

  document.getElementById('gCode').addEventListener('keydown', function(e){
    if (e.key === 'Enter') gLoad();
  });
  gLoad();

  /* ── 사용자 지표 관리 ── */
  var IND_CACHE = [];
  function esc(s){ var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; }
  function G(id){ return document.getElementById(id); }

  /* 변수 요약 뱃지 — 「봉수 240·24·6·120」(일·주·월·분). 축별 값이 하나뿐이면 그것만.
   * 어느 축인지 모호해지지 않게 «채워진 축만» 접두 없이 순서대로 잇는다(툴팁에 축 이름을 적는다). */
  var VT_AXES = [['day','일'], ['week','주'], ['month','월'], ['min','분']];
  function varTags(d){
    var vs = d.vars || {};
    var per = false;
    VT_AXES.forEach(function(a){ if (vs[a[0]] && typeof vs[a[0]] === 'object') per = true; });
    var maps = VT_AXES.map(function(a){ return per ? (vs[a[0]] || {}) : {}; });
    if (!per) maps[0] = vs;
    var names = {};
    maps.forEach(function(m){ Object.keys(m).forEach(function(k){ names[k] = 1; }); });
    return Object.keys(names).map(function(k){
      var p = [], t = [];
      maps.forEach(function(m, i){
        if (m[k] !== undefined) { p.push(m[k]); t.push(VT_AXES[i][1] + ' ' + m[k]); }
      });
      return '<span class="tag var" title="' + esc(t.join(' · ')) + '">'
           + esc(k) + ' <b>' + esc(p.join('·')) + '</b></span>';
    }).join('');
  }
  /* 표시 축 뱃지 — 전 축이면 안 붙인다(대부분이 그래서 붙이면 소음이 된다) */
  function tfTag(d){
    var a = d.tfs || [];
    if (!a.length || a.length === VT_AXES.length) return '';
    var lb = a.map(function(k){
      for (var i = 0; i < VT_AXES.length; i++) if (VT_AXES[i][0] === k) return VT_AXES[i][1];
      return k;
    });
    return '<span class="tag tf" title="이 시간축의 차트에서만 그립니다">'
         + esc(lb.join('·')) + '봉만</span>';
  }
  /* 「십자선 값 표시」 뱃지 — 켠 것에만 붙인다(기본이 끔이라 붙어 있는 쪽이 특별한 상태다) */
  function hoverTag(d){
    if (!+(d.hover || 0)) return '';
    return '<span class="tag tf" title="마우스가 짚은 봉의 값을 범례에 보여 줍니다">십자선 값</span>';
  }
  /* 뱃지 속 선 견본 — 두께·종류가 그대로 보이게 (점 지표는 동그라미) */
  function lineSwatch(s, col, isPoint){
    if (isPoint) return 'background:' + col;
    var w = Math.max(1, Math.min(5, +s.w || 2));
    var st = s.st || 'solid';
    var css = 'width:16px;height:' + w + 'px;border-radius:1px;';
    if (st === 'solid') return css + 'background:' + col;
    var seg = { dotted: [2, 2], sdotted: [2, 5], ldashed: [8, 4], dashed: [5, 3] }[st] || [5, 3];
    return css + 'background:repeating-linear-gradient(90deg,' + col + ' 0 ' + seg[0] + 'px,'
         + 'transparent ' + seg[0] + 'px ' + (seg[0] + seg[1]) + 'px)';
  }
  /* 출력선 뱃지 — 선 견본 + 선 이름(#토큰은 값으로) + 연장. 꺼진 칸은 흐리게 */
  function lineTags(d){
    if (!d.lines || !d.lines.length) return '';
    var oi = 0, isPoint = d.draw === 'point';
    return d.lines.map(function(s){
      if (isDefSlot(s)) return '';
      oi++;
      var nm = DailyChart.indLabel(s.name || ('수식' + oi), d, null, 'day');
      return '<span class="tag' + (Number(s.on) === 0 ? ' off' : '') + '">'
        + '<i class="' + (isPoint ? 'dt' : 'ln') + '" style="' + lineSwatch(s, s.color || d.color, isPoint) + '"></i>'
        + esc(nm) + '</span>'
        + (Number(s.ext) ? '<span class="tag ext">연장 ' + Number(s.ext) + '</span>' : '');
    }).join('');
  }

  function indRender(){
    var box = G('indList');
    box.innerHTML = '';
    if (!IND_CACHE.length) {
      box.innerHTML = '<div class="empty">저장된 지표가 없습니다 — 아래에서 첫 지표를 만들어 보세요.</div>';
      return;
    }
    IND_CACHE.forEach(function(d){
      var dotCol = (d.lines && d.lines.length && d.lines[0].color) ? d.lines[0].color : d.color;
      // 이름에 #토큰이 있으면 차트에 실제로 찍히는 모습(일봉 기준)을 함께 보여 준다
      var shown = DailyChart.indLabel(d.name, d, null, 'day');
      var card = document.createElement('div');
      card.className = 'ind-card';
      card.dataset.id = d.id;
      // 수식은 카드에서 빼고 툴팁으로 — 필요할 때만 본다
      card.title = (d.lines || []).map(function(s){
        return (isDefSlot(s) ? '수식 정의' : (s.name || '출력')) + ' : ' + (s.body || '');
      }).join('\n') || (d.expr || '');
      card.innerHTML =
          '<span class="dot" style="background:' + esc(dotCol) + '"></span>'
        + '<span class="nm">' + esc(d.name) + '</span>'
        + (shown !== d.name ? '<span class="shown">→ ' + esc(shown) + '</span>' : '')
        + '<span class="tags">'
        +   '<span class="tag draw' + (d.draw === 'point' ? ' point' : '') + '">'
        +     (d.draw === 'point' ? '점 ●' : '선 ─') + '</span>'
        +   tfTag(d) + hoverTag(d) + lineTags(d) + varTags(d)
        + '</span>'
        + '<button type="button" class="ind-more" data-act="menu" title="수정 · 삭제">⋯</button>';
      box.appendChild(card);
    });
  }

  /* ⋯ 뱃지 → 수정/삭제 작은 메뉴 (한 번에 하나만 열린다) */
  function closeIndMenu(){
    var m = document.querySelector('.ind-menu');
    if (m) m.parentNode.removeChild(m);
  }
  document.addEventListener('click', function(e){
    if (!e.target.closest('.ind-menu') && !e.target.closest('.ind-more')) closeIndMenu();
  });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeIndMenu(); });
  function indLoad(force){
    return DailyChart.loadIndicators(force).then(function(a){ IND_CACHE = a; indRender(); });
  }

  /* 변수 표 — 이름은 축 공통, 값만 축별 ↔ {day:{}, week:{}, month:{}, min:{}} (한글 이름 허용).
   * 열 ↔ 축 키: D=day · W=week · M=month · X=min(분). 「분」은 2026-08-04 신설 —
   * 분봉은 봉 주기가 달라 120봉·240봉 같은 값을 일봉과 나눠 써야 한다. 비면 일 값을 쓴다. */
  var VAR_ROWS = 4;
  /* ── 알림·확인 모달 (alert 대신) ────────────────────────────────
   * o = {tone:'err'|'warn'|'ok', title, msg, em(강조 블록), hint(회색 꼬리말),
   *      ok:'확인', cancel:'취소'} — cancel 을 주면 확인창이 된다.
   * 돌려주는 Promise: 확인 true · 취소 false. Esc·바깥 클릭 = 취소 · Enter = 확인. */
  function dlgEsc(s){
    return String(s === undefined || s === null ? '' : s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }
  function dlg(o){
    o = o || {};
    return new Promise(function(resolve){
      var tone = o.tone || 'err';
      var bd = document.createElement('div');
      bd.className = 'dlg-bd';
      bd.innerHTML =
          '<div class="dlg" role="alertdialog" aria-modal="true">'
        +   '<div class="dlg-h"><span class="dlg-ico ' + tone + '">' + (tone === 'ok' ? '✓' : '!') + '</span>'
        +     '<span>' + dlgEsc(o.title || '알림') + '</span></div>'
        +   '<div class="dlg-b">' + dlgEsc(o.msg || '')
        +     (o.em   ? '<span class="em">'   + dlgEsc(o.em)   + '</span>' : '')
        +     (o.hint ? '<span class="hint">' + dlgEsc(o.hint) + '</span>' : '')
        +   '</div><div class="dlg-f"></div></div>';
      var foot = bd.querySelector('.dlg-f');
      function close(v){ document.removeEventListener('keydown', key, true); bd.remove(); resolve(v); }
      function key(e){
        if (e.key === 'Escape')    { e.preventDefault(); close(false); }
        else if (e.key === 'Enter'){ e.preventDefault(); close(true); }
      }
      if (o.cancel) {
        var bc = document.createElement('button');
        bc.type = 'button'; bc.className = 'btn btn-outline'; bc.textContent = o.cancel;
        bc.onclick = function(){ close(false); };
        foot.appendChild(bc);
      }
      var bo = document.createElement('button');
      bo.type = 'button'; bo.className = 'btn btn-outline btn-primary'; bo.textContent = o.ok || '확인';
      bo.onclick = function(){ close(true); };
      foot.appendChild(bo);
      document.addEventListener('keydown', key, true);
      bd.addEventListener('mousedown', function(e){ if (e.target === bd) close(false); });
      document.body.appendChild(bd);
      bo.focus();
    });
  }

  var VAR_COLS = [['D','day'], ['W','week'], ['M','month'], ['X','min']];
  function formVars(){
    var maps = { day:{}, week:{}, month:{}, min:{} };
    for (var i = 0; i < VAR_ROWS; i++) {
      var k = G('varN' + i).value.trim();
      if (!k) continue;
      var K = k.toUpperCase();
      VAR_COLS.forEach(function(c){
        var v = G('var' + c[0] + i).value.trim();
        if (v !== '' && isFinite(+v)) maps[c[1]][K] = +v;
      });
    }
    var out = {};
    Object.keys(maps).forEach(function(tf){ if (Object.keys(maps[tf]).length) out[tf] = maps[tf]; });
    return out;
  }
  /* 표시 축 체크칸 (변수 표 머리글) — 「그 축의 차트에서 이 지표를 그릴지」.
   * ★넷 다 켜면 서버가 「전 축」(빈 값)으로 접어 저장한다 — 같은 뜻을 두 모양으로 담지 않는다.
   *   그래서 체크칸이 생기기 전에 만든 옛 지표(tfs 없음)는 전부 켜진 모습으로 열린다. */
  function formTfs(){
    return VAR_COLS.filter(function(c){ return G('tf' + c[0]).checked; })
                   .map(function(c){ return c[1]; });
  }
  function fillTfs(tfs){
    var has = (tfs && tfs.length) ? tfs : null;
    VAR_COLS.forEach(function(c){ G('tf' + c[0]).checked = has ? has.indexOf(c[1]) >= 0 : true; });
    tfHead();
  }
  // 꺼진 축 머리글은 회색으로 — 값 칸은 그대로 두므로 표시가 없으면 꺼진 줄 모른다
  function tfHead(){
    VAR_COLS.forEach(function(c){
      G('tfL' + c[0]).classList.toggle('off', !G('tf' + c[0]).checked);
    });
  }
  VAR_COLS.forEach(function(c){ G('tf' + c[0]).addEventListener('change', tfHead); });

  function fillVars(vars){
    vars = vars || {};
    var per = false;
    VAR_COLS.forEach(function(c){ if (vars[c[1]] && typeof vars[c[1]] === 'object') per = true; });
    var maps = {};
    VAR_COLS.forEach(function(c){ maps[c[1]] = per ? (vars[c[1]] || {}) : {}; });
    if (!per) maps.day = vars;                        // 구형(평평)은 일 열로
    var names = {};
    VAR_COLS.forEach(function(c){ Object.keys(maps[c[1]]).forEach(function(k){ names[k] = 1; }); });
    var keys = Object.keys(names);
    for (var i = 0; i < VAR_ROWS; i++) {
      var k = keys[i];
      G('varN' + i).value = k || '';
      VAR_COLS.forEach(function(c){
        var m = maps[c[1]];
        G('var' + c[0] + i).value = (k !== undefined && m[k] !== undefined) ? m[k] : '';
      });
    }
    return keys;                     // 이름 바꿈 감지용 (행 위치로 대조)
  }

  /* 변수 이름을 바꿔 저장하면 이름 속 「#옛이름」 토큰도 같이 따라간다.
   * (위치가 같은 행 = 같은 변수로 본다. #1 같은 자리표는 애초에 안 깨진다) */
  var EDIT_VARS = [];
  function renameTokens(s, ren){
    if (!s || s.indexOf('#') < 0) return s;
    return s.replace(/#([A-Za-z_가-힣][A-Za-z0-9_가-힣]*)/g, function(raw, tok){
      var up = tok.toUpperCase();
      for (var i = 0; i < ren.length; i++) if (ren[i][0] === up) return '#' + ren[i][1];
      return raw;
    });
  }
  function varRenames(){
    var out = [];
    for (var i = 0; i < VAR_ROWS; i++) {
      var o = (EDIT_VARS[i] || '').toUpperCase();
      var n = G('varN' + i).value.trim().toUpperCase();
      if (o && n && o !== n) out.push([o, n]);
    }
    return out;
  }

  var SLOT_COLORS = ['#d32f2f', '#1565c0', '#1e9e74'];

  /* 선 두께·종류 (HTS 「너비/스타일」) — 값은 dailychart.js STYLE_MAP 과 1:1 */
  var LINE_STYLES = [
    ['solid',   '───── 실선'],
    ['dashed',  '── ── 파선'],
    ['dotted',  '‧‧‧‧‧ 점선'],
    ['ldashed', '▬▬ ▬▬ 긴파선'],
    ['sdotted', '‧  ‧  ‧ 성긴점']
  ];
  (function fillLineSelects(){
    for (var i = 0; i < 3; i++) {
      ['slW' + i, 'slExw' + i].forEach(function(id){          // 본선 두께 · 연장선 두께
        var sel = G(id);
        for (var k = 1; k <= 5; k++) {
          var o = document.createElement('option');
          o.value = k; o.textContent = k + 'pt';
          sel.appendChild(o);
        }
      });
      ['slSt' + i, 'slEx' + i].forEach(function(id){          // 본선 종류 · 연장선 종류
        var sel = G(id);
        LINE_STYLES.forEach(function(s){
          var o = document.createElement('option');
          o.value = s[0]; o.textContent = s[1];
          sel.appendChild(o);
        });
      });
      G('slW' + i).value = '2';
      G('slExw' + i).value = '1';            // 연장선 기본은 1pt — 본선보다 가늘게
      G('slSt' + i).value = 'solid';
      G('slEx' + i).value = 'dashed';        // 연장선 기본은 파선 — 지금 값과 구분되게
    }
  })();

  /* 폼 → lines 배열.
   * [0] = 「수식 정의」 칸 (그리지 않는 계산 전용 — 저장 시 def:1 표시)
   * [1..] = 출력 칸 3개 (선/점) */
  function formLines(){
    var lines = [];
    var defBody = G('slDef').value.trim();
    if (defBody) lines.push({ name: '', body: defBody, on: 1, color: '', def: 1 });
    for (var i = 0; i < 3; i++) {
      var body = G('slBody' + i).value.trim();
      if (!body) continue;
      var ext = parseInt(G('slExt' + i).value, 10);
      lines.push({ name: G('slName' + i).value.trim(), body: body,
                   on: G('slOn' + i).checked ? 1 : 0, color: G('slColor' + i).value,
                   ext: (isFinite(ext) && ext > 0) ? Math.min(10, ext) : 0,
                   exs: G('slEx' + i).value || 'dashed',
                   exw: +G('slExw' + i).value || 1,
                   w: +G('slW' + i).value || 2, st: G('slSt' + i).value || 'solid' });
    }
    return lines;
  }
  /* 본문이 빈 수식칸의 체크칸은 «뜻이 없다» — 빈 칸은 저장 대상이 아니라(formLines 가 건너뛴다)
   * 꺼 두고 저장해도 다음에 열면 기본값(켜짐)으로 돌아온다. 그 유령 상태를 없애려고
   * 본문이 비면 체크칸을 잠그고 흐리게 둔다. 본문을 적는 순간 풀린다(그리고 켜진 채로 시작한다). */
  function slotOnSync(){
    for (var i = 0; i < 3; i++) {
      var has = !!G('slBody' + i).value.trim();
      var cb  = G('slOn' + i);
      cb.disabled = !has;
      if (!has) cb.checked = true;
      var lb = cb.parentNode;
      if (lb && lb.classList) lb.classList.toggle('slot-na', !has);
      if (lb) lb.title = has ? '해제하면 이 선만 숨깁니다'
                             : '수식 본문이 비어 있어 켜고 끌 것이 없습니다';
    }
  }
  for (var _si = 0; _si < 3; _si++) {
    G('slBody' + _si).addEventListener('input', slotOnSync);
  }

  /* 「이름만 적고 본문은 비운」 칸 찾기 — 본문이 비면 그 칸은 통째로 버려진다(formLines).
   * 조용히 사라지면 「저장이 안 된다」로 보인다(실제로 그렇게 헤맸다) → 저장·검사에서 막는다. */
  function slotMiss(){
    var out = [];
    for (var i = 0; i < 3; i++) {
      if (G('slBody' + i).value.trim()) continue;
      var ext = parseInt(G('slExt' + i).value, 10);
      if (G('slName' + i).value.trim() || (isFinite(ext) && ext > 0)) out.push(i + 1);
    }
    return out;
  }
  function slotMissAlert(){
    var m = slotMiss();
    if (!m.length) return false;
    dlg({ tone:'warn', title:'수식 본문이 비어 있습니다',
          msg:'수식' + m.join('·수식') + ' 은 이름·연장만 채워져 있고 «수식 본문»(맨 오른쪽 넓은 칸)이 비었습니다.\n'
            + '본문이 비면 그 칸은 저장되지 않고 이름도 함께 버려집니다.',
          hint:'「수식 정의」에서 만든 변수 이름(예: 상단)을 본문 칸에 넣어 주세요.' });
    return true;
  }
  // 정의 칸 판별 — def 표시가 있거나, 마지막 문장이 대입(=출력 없음)인 첫 칸
  function isDefSlot(s){
    if (!s) return false;
    if (Number(s.def) === 1) return true;
    var stmts = String(s.body || '').split(';').map(function(x){ return x.trim(); }).filter(Boolean);
    var last = stmts[stmts.length - 1] || '';
    return /^[A-Za-z_가-힣][A-Za-z0-9_가-힣]*\s*=[^=]/.test(last);
  }
  function fillSlots(lines){
    lines = lines || [];
    var defs = [], outs = [];
    lines.forEach(function(s){ (isDefSlot(s) ? defs : outs).push(s); });
    G('slDef').value = defs.map(function(s){ return s.body; }).join('\n');
    for (var i = 0; i < 3; i++) {
      var s = outs[i] || null;
      G('slOn' + i).checked = s ? Number(s.on) !== 0 : true;
      G('slName' + i).value = s ? (s.name || '') : '';
      G('slColor' + i).value = (s && s.color) ? s.color : SLOT_COLORS[i];
      G('slExt' + i).value = (s && +s.ext) ? +s.ext : '';
      G('slExw' + i).value = String((s && +s.exw) ? Math.max(1, Math.min(5, +s.exw)) : 1);
      G('slEx' + i).value = (s && s.exs) ? s.exs : 'dashed';
      G('slW' + i).value = String((s && +s.w) ? +s.w : 2);
      G('slSt' + i).value = (s && s.st) ? s.st : 'solid';
      G('slBody' + i).value = s ? (s.body || '') : '';
    }
    slotOnSync();          // 빈 칸의 체크칸은 잠근다 (뜻 없는 상태를 남기지 않는다)
  }

  window.indNew = function(){
    G('inId').value = ''; G('inName').value = ''; G('inDraw').value = 'line';
    G('inNote').value = '';
    G('inHover').checked = false;     // 기본 끔 — 켜는 것은 「지나간 값을 묻는 지표」의 선택이다
    G('inTitle').textContent = '새 지표';
    EDIT_VARS = fillVars({});
    fillTfs(null);                    // 새 지표는 전 축 — 만들자마자 어디선가 안 보이면 헷갈린다
    fillSlots(null);
  };
  /* 편집 폼은 평소에 감춰 둔다 — 「신규등록」·「수정」에서만 연다 */
  window.indOpen = function(){
    indNew();
    G('indForm').hidden = false;
    G('indForm').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    G('inName').focus();
  };
  window.indClose = function(){
    G('indForm').hidden = true;
    indNew();
  };

  var TF_LABEL = { day:'일', week:'주', month:'월', min:'분' };

  /* 수식 검증 — 문법 + 조회된 실데이터로 실제 계산까지.
   * ★「이 지표가 켜 둔 축」의 변수 세트로 돌린다 — 늘 일 세트로 돌리면 분(또는 주·월) 칸에만
   *   값을 넣은 지표가 「알 수 없는 이름」으로 헛되이 걸린다(실제로 그렇게 막혔다).
   * 돌려주는 값: {ok:true, outs:출력 선 개수} | {ok:false, title, msg, hint} */
  function validateDef(def){
    var tfSel = formTfs();
    var tf = (!tfSel.length || tfSel.indexOf('day') >= 0) ? 'day' : tfSel[0];
    var hint = (tf === 'day') ? ''
      : TF_LABEL[tf] + ' 변수 세트로 검사했습니다 (갤러리 데이터는 일봉이라 시각·첫봉·막봉은 0 입니다).';
    try {
      DailyChart.checkDef(def);
      if (!G_ROWS.length) return { ok:true, outs:null };     // 조회 전 — 문법만 본 것
      var parts = DailyChart.evalIndicatorMulti(G_ROWS, def, {}, tf);
      return { ok:true, outs:parts.length };
    } catch (e) {
      return { ok:false, title:'수식에 문제가 있습니다', msg:e.message,
               hint:hint || '「수식 정의」의 변수 이름과 변수 표를 확인하세요.' };
    }
  }

  window.indSave = function(){
    var name = G('inName').value.trim();
    var lines = formLines();
    if (!name || !lines.length) {
      dlg({ title:'저장할 수 없습니다', msg:'지표 이름과 수식은 필수입니다.' });
      return;
    }
    if (slotMissAlert()) return;
    var tfs = formTfs();
    // 하나도 안 고르면 「어디에도 안 보이는 지표」가 된다 — 빈 값은 서버에서 「전 축」이라 조용히 뜻이 뒤집힌다
    if (!tfs.length) {
      dlg({ tone:'warn', title:'보여 줄 시간축이 없습니다',
            msg:'이 지표를 그릴 시간축을 하나 이상 고르세요.',
            hint:'변수 표 머리글의 일·주·월·분 체크칸입니다.' });
      return;
    }
    var ren = varRenames();          // 변수 이름을 바꿨으면 이름 속 #토큰도 함께 고친다
    if (ren.length) {
      name = renameTokens(name, ren);
      lines.forEach(function(s){ s.name = renameTokens(s.name, ren); });
      G('inName').value = name;
    }
    /* ★저장이 곧 검사다(2026-08-05) — 따로 「검사」 버튼을 두면 안 누르고 저장하게 되고,
     *   그리지도 못할 지표가 조용히 저장된다. 맞으면 아무 말 없이 넘어간다. */
    var v = validateDef({ name:name, draw:G('inDraw').value, lines:lines,
                          vars:formVars(), color:'#7b1fa2' });
    if (!v.ok) { dlg({ title:v.title, msg:v.msg, hint:v.hint }); return; }
    if (v.outs === 0) {
      dlg({ tone:'warn', title:'그릴 선이 없습니다',
            msg:'수식이 변수만 만들고 끝나서, 저장해도 차트에 아무것도 안 그려집니다.',
            hint:'수식1~3 의 본문 칸(맨 오른쪽)에 「수식 정의」에서 만든 이름을 넣으세요.',
            ok:'그래도 저장', cancel:'돌아가기' })
        .then(function(go){ if (go) indSavePost(name, lines, tfs); });
      return;
    }
    indSavePost(name, lines, tfs);
  };

  function indSavePost(name, lines, tfs){
    var body = new URLSearchParams();
    body.set('module', 'ind'); body.set('action', 'save');
    if (G('inId').value) body.set('id', G('inId').value);
    body.set('name', name);
    body.set('draw', G('inDraw').value);
    body.set('lines', JSON.stringify(lines));
    body.set('note', G('inNote').value);
    body.set('vars', JSON.stringify(formVars()));
    body.set('tfs', JSON.stringify(tfs));       // 넷 다면 서버가 「전 축」으로 접는다
    body.set('hover', G('inHover').checked ? '1' : '0');
    fetch('/stock_analysis_api.php', { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(r){
        if (!r.ok) { dlg({ title:'저장 실패', msg:r.error || '알 수 없는 오류입니다.' }); return; }
        indClose();                     // 저장하면 폼을 접는다 — 목록만 보이는 게 기본 화면
        indLoad(true).then(function(){ if (ibar) ibar.reload(); });
      })
      .catch(function(e){ dlg({ title:'저장 실패', msg:String(e) }); });
  }

  function indEdit(d){
    G('inId').value = d.id; G('inName').value = d.name; G('inDraw').value = d.draw;
    G('inNote').value = d.note || '';
    G('inHover').checked = !!+(d.hover || 0);
    G('inTitle').textContent = '수정';
    EDIT_VARS = fillVars(d.vars || {});
    fillTfs(d.tfs);
    // 신형 lines / 구형 expr 폴백
    fillSlots((d.lines && d.lines.length) ? d.lines
      : (d.expr ? [{ name: '', body: d.expr, on: 1, color: d.color }] : null));
    G('indForm').hidden = false;
    G('indForm').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    G('inName').focus();
  }
  function indDel(d){
    if (!confirm('지표 「' + d.name + '」 를 삭제할까요?')) return;
    var body = new URLSearchParams();
    body.set('module', 'ind'); body.set('action', 'del'); body.set('id', String(d.id));
    fetch('/stock_analysis_api.php', { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(){ indLoad(true).then(function(){ if (ibar) ibar.reload(); }); });
  }

  G('indList').addEventListener('click', function(e){
    var card = e.target.closest('.ind-card');
    if (!card) return;
    var d = IND_CACHE.filter(function(x){ return x.id === +card.dataset.id; })[0];
    if (!d) return;

    var mb = e.target.closest('.ind-menu button');
    if (mb) { closeIndMenu(); (mb.dataset.act === 'del' ? indDel : indEdit)(d); return; }
    if (!e.target.closest('.ind-more')) return;

    var open = card.querySelector('.ind-menu');
    closeIndMenu();
    if (open) return;                       // 같은 카드의 ⋯ 를 다시 누르면 닫기
    var m = document.createElement('div');
    m.className = 'ind-menu';
    m.innerHTML = '<button type="button" data-act="edit">수정</button>'
                + '<button type="button" class="del" data-act="del">삭제</button>';
    card.appendChild(m);
  });

  indLoad(false);
}).catch(function(){
  document.getElementById('gStat').textContent = '차트 라이브러리를 불러오지 못했습니다.';
});

/* ══ 화면별 구성 — 카탈로그(FEAT_CAT) 하나로 카드·체크리스트·미리보기를 다 그린다 ══
 * 목록을 여기에 다시 적지 않는다. 기능을 늘리려면 classes/ChartFeat.class 에 한 줄. */
(function(){
  var wrap = document.getElementById('scrList');
  if (!wrap || typeof FEAT_CAT === 'undefined') return;
  function esc2(s){ var d=document.createElement('div'); d.textContent=(s==null?'':String(s)); return d.innerHTML; }
  var STY = { 0:'구성 ①~⑤', 1:'구성 ① 기본형', 2:'구성 ② 포트폴리오형',
              3:'구성 ③ 시뮬레이터형', 4:'구성 ④ 분석형 + ⑤ 분봉' };

  function avail(sk, fk){
    var f = FEAT_CAT.features[fk], s = FEAT_CAT.screens[sk];
    return !f.dep || s.data.indexOf(f.dep) >= 0;
  }
  function locked(sk, fk){
    return FEAT_CAT.features[fk].g === 'core' || FEAT_CAT.screens[sk].lock.indexOf(fk) >= 0;
  }
  function drawPrev(sk){
    var s = FEAT_CAT.screens[sk];
    DailyChart.toolbarPreview('prev_' + sk, FEAT_VAL[sk], { theme: s.style === 4 ? 'dark' : 'light' });
  }
  function save(sk, reset){
    var st = document.getElementById('st_' + sk);
    if (st) st.textContent = '저장 중…';
    var body = new URLSearchParams();
    body.set('module','ind'); body.set('action','feat_save'); body.set('screen', sk);
    if (reset) body.set('reset','1'); else body.set('vals', JSON.stringify(FEAT_VAL[sk]));
    fetch('/stock_analysis_api.php', { method:'POST', body: body, credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(r){
        if (!r || !r.ok) { if (st) st.textContent = '저장 실패'; return; }
        FEAT_VAL[sk] = r.values;          // 서버가 잠금·데이터 규칙을 다시 먹인 최종값
        card(sk);                         // 같은 자리를 다시 그린다 (서버가 되돌린 값이 보이게)
        var st2 = document.getElementById('st_' + sk);
        if (st2) st2.textContent = reset ? '기본값으로 되돌렸습니다' : '저장했습니다';
      })
      .catch(function(){ if (st) st.textContent = '저장 실패'; });
  }

  /* 차트 높이 저장 — 기간(tf·day·week)과 같은 칸에 사니 통째로 보낸다(서로 지우지 않게).
     빈칸 = 높이 기억을 지운다(화면 기본값으로 돌아간다). */
  function saveHeight(sk, val){
    var v = FEAT_VIEW[sk] || (FEAT_VIEW[sk] = {});
    var n = parseInt(val, 10);
    if (isFinite(n) && n > 0) v.h = Math.max(140, Math.min(1400, n)); else delete v.h;
    var st = document.getElementById('st_' + sk);
    if (st) st.textContent = '저장 중…';
    var body = new URLSearchParams();
    body.set('module','ind'); body.set('action','view_save');
    body.set('chart_key', sk); body.set('view', JSON.stringify(v));
    fetch('/stock_analysis_api.php', { method:'POST', body: body, credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(r){
        FEAT_VIEW[sk] = (r && r.view) ? r.view : {};
        var hi = document.getElementById('hi_' + sk);
        if (hi) hi.value = FEAT_VIEW[sk].h || '';      // 서버가 자른 값(140~1400)을 그대로 보여 준다
        if (st) st.textContent = FEAT_VIEW[sk].h ? ('높이 ' + FEAT_VIEW[sk].h + 'px 로 저장') : '기본 높이로';
      })
      .catch(function(){ if (st) st.textContent = '저장 실패'; });
  }

  function card(sk){
    var s = FEAT_CAT.screens[sk], vals = FEAT_VAL[sk];
    var el = document.getElementById('scr_' + sk);
    if (!el) { el = document.createElement('div'); el.className = 'scr-card'; el.id = 'scr_' + sk; wrap.appendChild(el); }
    var h = '<div class="scr-hd"><h4>' + esc2(s.label) + '</h4>'
          + '<span class="sty">' + STY[s.style] + '</span>'
          + '<a class="go" href="' + esc2(s.url) + '" target="_blank">화면 열기 ↗</a></div>'
          + '<div class="scr-note">' + esc2(s.note) + '</div>'
          + '<div class="scr-prev" id="prev_' + sk + '"></div>';
    Object.keys(FEAT_CAT.groups).forEach(function(g){
      var keys = Object.keys(FEAT_CAT.features).filter(function(k){ return FEAT_CAT.features[k].g === g; });
      if (!keys.length) return;
      h += '<div class="fgrp"><div class="gh">' + esc2(FEAT_CAT.groups[g].label)
         + '<em>' + esc2(FEAT_CAT.groups[g].desc) + '</em></div><div class="flist">';
      keys.forEach(function(k){
        var f = FEAT_CAT.features[k], lk = locked(sk, k), av = avail(sk, k);
        var cls = lk ? 'fitem lock' : (av ? 'fitem' : 'fitem na');
        var why = av ? f.desc : ('데이터 없음 — ' + FEAT_CAT.data[f.dep]);
        h += '<label class="' + cls + '">'
           + '<input type="checkbox" data-k="' + k + '"' + (+vals[k] ? ' checked' : '')
           + ((lk || !av) ? ' disabled' : '') + '>'
           + '<span><b>' + esc2(f.label) + '</b>' + (lk ? ' 🔒' : '')
           + '<span class="why">' + esc2(why) + '</span></span></label>';
      });
      h += '</div></div>';
    });
    // 차트 높이 — 차트 아래 가장자리를 끌어도 되고, 여기서 숫자로 정해도 된다 (같은 값)
    var hv = (FEAT_VIEW[sk] && FEAT_VIEW[sk].h) ? FEAT_VIEW[sk].h : '';
    h += '<div class="scr-ft">'
       + '<label class="hbox">차트 높이 <input type="number" id="hi_' + sk + '" min="140" max="1400" step="10"'
       +   ' value="' + hv + '" placeholder="기본"> px'
       +   '<span class="hnote">비우면 화면 기본값 · 차트 아래 가장자리를 끌어도 됩니다</span></label>'
       + '<button type="button" class="btn btn-outline btn-sm" data-reset="1">기본값으로 되돌리기</button>'
       + '<span class="st" id="st_' + sk + '"></span></div>';
    el.innerHTML = h;
    el.onchange = function(e){
      var hi = e.target.closest ? e.target.closest('input[type=number]') : null;
      if (hi) { saveHeight(sk, hi.value); return; }
      var cb = e.target.closest ? e.target.closest('input[type=checkbox]') : null;
      if (!cb || !cb.dataset.k) return;
      FEAT_VAL[sk][cb.dataset.k] = cb.checked ? 1 : 0;
      drawPrev(sk);                      // 미리보기는 먼저, 저장은 뒤 — 손이 느리게 느껴지지 않게
      save(sk, false);
    };
    el.onclick = function(e){
      var b = e.target.closest ? e.target.closest('button[data-reset]') : null;
      if (b) save(sk, true);
    };
    drawPrev(sk);
  }

  Object.keys(FEAT_CAT.screens).forEach(function(sk){ card(sk); });

  var bl = document.getElementById('barelessList');
  if (bl) bl.innerHTML = FEAT_CAT.bareless.map(function(b){
    return '<div><b>' + esc2(b[0]) + '</b> — ' + esc2(b[2]) + ' <span style="color:#a8b4c0">(' + esc2(b[1]) + ')</span></div>';
  }).join('');
})();
</script>
</body></html>
HTML;
?>
