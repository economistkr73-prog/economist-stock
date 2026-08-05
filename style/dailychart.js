/* ============================================================================
 * dailychart.js — 사이트 공용 일봉(캔들) 차트 모듈  (lightweight-charts 래퍼)
 *
 * 쓰는 곳: chart_gallery.php · stock/index.php (short / position / sim / fund)
 *
 * 왜 하나로 모았나 — 같은 차트 코드가 5벌 복사되어 있어서
 * 신호 조건 하나를 고치면 다섯 군데를 고쳐야 했다. 이제 여기 한 곳만 고친다.
 *
 * 사용 순서:
 *   DailyChart.load().then(function(){
 *     var dc = DailyChart.create('hostId', { theme:'light' });
 *     DailyChart.fetchDaily(code, 160).then(function(rows){ dc.setData(rows); });
 *   });
 *
 * 기능 스위치 (opts):
 *   theme      'light' | 'dark'   — dark 는 페이지 CSS 변수(--up/--down/--line/--ink-dim)를 읽는다
 *   kind       'day'(기본) | 'minute'
 *   volume     거래량 히스토그램 (기본 true)
 *   volAlpha   거래량 색 투명도 hex 2자리 (테마 기본값 있음)
 *   markers    { chips:true }  — 체결 마커. chips=true 면 HTML 칩(충돌회피), false 면 라이브러리 text
 *   todayHigh  true            — 당일전고선: 최신봉 제외 직전 60봉 최고가 수평선 (관찰용 기준선)
 *              'session'       — 분봉용: «오늘 고가»(마지막 봉과 같은 날짜의 직전 봉까지). 1분봉에서
 *                                60봉 규칙을 쓰면 「직전 60분 최고가」가 되어 이름이 거짓이 된다
 *   curPrice   현재가선 색 (없으면 기능 자체 꺼짐. 다크 화면='#d9a441')
 *   prevHigh   직전고가선 색 (true 면 기본 빨강 '#ff3b30'. 없으면 기능 자체 꺼짐)
 *              — «오늘을 뺀» 실린 봉 전부의 최고가. <b>굵은 실선</b>(3px)이다 —
 *                이 화면에서 가장 자주 보는 선이라 캔들 사이에서 한눈에 잡혀야 한다.
 *                dc.setPrevHigh(on) 으로 토글.
 *              「오늘」은 마지막 봉의 날짜다(분봉 time 은 KST 벽시계를 UTC 로 취급한 초라
 *              Math.floor(t/86400) 이 그대로 KST 날짜 — todayHigh:'session' 과 같은 셈).
 *              ★창(보이는 구간)이 아니라 실린 전부를 본다 — 휠로 줌할 때마다 「최고가」가
 *                달라지면 그건 기준선이 아니다. 대신 <b>켜져 있으면 가격축에 포함</b>해서
 *                화면 밖으로 사라지지 않게 한다(당일 창에서 캔들이 눌리는 건 감수한다 —
 *                사용자가 켠 기준선은 보여야 한다. 지표선과 반대 규칙).
 *              ★실린 봉이 오늘치뿐이면(미리보기) 직전이 없다 — 선을 그리지 않는다.
 *   code       종목코드 6자리 — 주면 SUE 공시 마커(▲어닝서프라이즈·▼어닝쇼크)를 모듈이 스스로 얹는다.
 *              종목이 바뀌는 화면은 dc.setCode(code). 차트설정의 overlay.sue_markers 로 켜고 끈다.
 *
 * 주봉: dc.setTf('week') — 보관해 둔 일봉을 주 단위로 접어 다시 그린다(월요일 기준,
 *       시가=주 첫 봉, 고저=주 내 최대·최소, 종가=주 마지막 봉, 거래량=합).
 *       가격선·마커·추가선도 같이 따라온다. 별도 API 불필요.
 * ========================================================================== */
(function () {
  'use strict';

  var LIB_URL = 'https://unpkg.com/lightweight-charts@4.1.3/dist/lightweight-charts.standalone.production.js';
  var API = '/stock_analysis_api.php';

  /* ── 라이브러리 로더 — 페이지가 <script> 태그로 미리 실었으면 그대로 쓴다 ── */
  var _libPromise = null;
  function load() {
    if (window.LightweightCharts) return Promise.resolve(window.LightweightCharts);
    if (_libPromise) return _libPromise;
    _libPromise = new Promise(function (res, rej) {
      var s = document.createElement('script');
      s.src = LIB_URL;
      s.onload = function () { res(window.LightweightCharts); };
      s.onerror = function () { rej(new Error('lightweight-charts load fail')); };
      document.head.appendChild(s);
    });
    return _libPromise;
  }

  /* ── 테마 — 두 계열의 색·배경 차이는 전부 여기서만 갈린다 ── */
  function cssVar(n, dflt) {
    var v = getComputedStyle(document.documentElement).getPropertyValue(n).trim();
    return v || dflt;
  }
  function themeOf(name) {
    if (name === 'dark') {
      return {
        bg: 'transparent', text: cssVar('--ink-dim', '#8893ab'),
        gridV: 'rgba(38,48,74,.4)', gridH: 'rgba(38,48,74,.4)',
        border: cssVar('--line', '#26304a'),
        up: cssVar('--up', '#e8493f'), down: cssVar('--down', '#2f7bd6'),
        volAlpha: '66', volTop: 0.8
      };
    }
    return {   // light — 한국식 상승 빨강 / 하락 파랑
      bg: '#fff', text: '#5f7183',
      gridV: '#f0f4f8', gridH: '#f0f4f8', border: '#e3eaf0',
      up: '#d32f2f', down: '#1565c0',
      volAlpha: '55', volTop: 0.82
    };
  }

  /* 거래대금(원) → 사람이 읽는 한국식 단위.
   * 자릿수를 나누는 이유: 일봉은 몇천억이 흔하고 1분봉은 몇천만이 흔하다 —
   * 「0.01억」이나 「1234567890억」처럼 읽을 수 없는 숫자가 나오지 않게 한다. */
  function eokText(v) {
    v = +v || 0;
    var e = v / 1e8;
    if (e >= 1000) return Math.round(e).toLocaleString() + '억';
    if (e >= 100)  return e.toFixed(0) + '억';
    if (e >= 10)   return e.toFixed(1) + '억';
    if (e >= 1)    return e.toFixed(2) + '억';
    if (v >= 1e4)  return Math.round(v / 1e4).toLocaleString() + '만';
    return Math.round(v).toLocaleString() + '원';
  }

  /* ── 시간 포맷 (한국식) ── */
  function ymd(t) {
    if (typeof t === 'string') { var p = t.split('-'); return [+p[0], +p[1], +p[2]]; }
    if (t && t.year) return [t.year, t.month, t.day];
    return null;
  }
  function fmtKDate(t) {
    var v = ymd(t);
    if (v) return v[0] + '년 ' + v[1] + '월 ' + v[2] + '일';
    var d = new Date((Number(t) || 0) * 1000);
    return d.getUTCFullYear() + '년 ' + (d.getUTCMonth() + 1) + '월 ' + d.getUTCDate() + '일';
  }
  // 눈금 종류 인지형: 0=연 1=월 그 외=일 — 10년 구간(sim)과 160일 구간 둘 다 자연스럽다
  function fmtTick(t, type) {
    var v = ymd(t);
    if (!v) return '';
    if (type === 0) return v[0] + '년';
    if (type === 1) return v[1] + '월';
    return v[1] + '/' + v[2];
  }
  function fmtHM(t) {
    var d = new Date((Number(t) || 0) * 1000);
    return String(d.getUTCHours()).padStart(2, '0') + ':' + String(d.getUTCMinutes()).padStart(2, '0');
  }
  /* 여러 날이 이어진 분봉 — 날이 바뀌는 자리(09:00 봉)에 «날짜»를 찍는다.
   * 이게 없으면 09:00 이 며칠이고 반복되어 어디가 어제고 오늘인지 구분이 안 된다.
   * (opts.multiDay 를 준 차트에서만 쓴다 — 당일치만 싣는 분봉은 옛 모습 그대로) */
  function fmtHMD(t) {
    var d = new Date((Number(t) || 0) * 1000);
    if (d.getUTCHours() === 9 && d.getUTCMinutes() === 0) {
      return (d.getUTCMonth() + 1) + '/' + d.getUTCDate();
    }
    return fmtHM(t);
  }
  function fmtDHM(t) {                    // 툴팁·범례용 — 날짜까지
    var d = new Date((Number(t) || 0) * 1000);
    return (d.getUTCMonth() + 1) + '/' + d.getUTCDate() + ' ' + fmtHM(t);
  }

  /* ── 데이터 유틸 ── */

  // API {t,o,h,l,c,v,a} 든 이미 푼 {time,open,...} 이든 내부형으로 통일
  //   a = KRX 실제 거래대금(있는 날만). 없으면 지표 엔진이 종가×거래량으로 근사한다.
  function normalize(raw) {
    return (Array.isArray(raw) ? raw : []).map(function (x) {
      if (x.time !== undefined) return x;
      var b = { time: x.t, open: x.o, high: x.h, low: x.l, close: x.c, vol: x.v };
      if (x.a !== undefined && x.a !== null) b.amt = x.a;
      return b;
    });
  }

  // 그 날짜가 속한 주의 월요일 'YYYY-MM-DD'
  function mondayOf(dateStr) {
    var p = dateStr.split('-');
    var ms = Date.UTC(+p[0], +p[1] - 1, +p[2]);
    var day = (new Date(ms).getUTCDay() + 6) % 7;          // 월=0 … 일=6
    var d = new Date(ms - day * 86400000);
    var mm = String(d.getUTCMonth() + 1).padStart(2, '0');
    var dd = String(d.getUTCDate()).padStart(2, '0');
    return d.getUTCFullYear() + '-' + mm + '-' + dd;
  }

  /* 일봉 → 주봉. 봉의 time 은 그 주의 <b>마지막 거래일</b>로 둔다 —
   * 축·십자선에 실재하는 날짜가 보이고, 이번 주 미완성 봉이 오늘 날짜로 잡힌다.
   * 거래정지봉(o/h/l null·0)은 고저 집계에서 제외하고 종가만 잇는다. */
  function resampleWeek(bars) {
    var out = [], cur = null, curKey = '';
    for (var i = 0; i < bars.length; i++) {
      var b = bars[i];
      if (typeof b.time !== 'string') return bars;   // 문자열 날짜가 아니면 주봉 불가 — 그대로
      var key = mondayOf(b.time);
      if (key !== curKey) { if (cur) out.push(cur); curKey = key; cur = null; }
      var o = (b.open  > 0) ? b.open  : null;
      var h = (b.high  > 0) ? b.high  : null;
      var l = (b.low   > 0) ? b.low   : null;
      if (!cur) {
        cur = { time: b.time, open: o, high: h, low: l, close: b.close, vol: b.vol || 0,
                amt: (b.amt === undefined ? undefined : b.amt), days: [b.time] };
      } else {
        cur.time  = b.time;
        cur.close = b.close;
        cur.vol  += b.vol || 0;
        // 거래대금도 주 단위 합산 — 한 날이라도 실제값이 있으면 그 주는 실제값 기준
        if (b.amt !== undefined) cur.amt = (cur.amt === undefined ? 0 : cur.amt) + b.amt;
        if (o !== null && cur.open === null) cur.open = o;
        if (h !== null && (cur.high === null || h > cur.high)) cur.high = h;
        if (l !== null && (cur.low  === null || l < cur.low))  cur.low  = l;
        cur.days.push(b.time);
      }
    }
    if (cur) out.push(cur);
    return out;
  }

  /* ── 칩 CSS 주입 (1회) — 페이지마다 복사돼 있던 스타일의 단일본 ── */
  var _cssDone = false;
  function injectCss() {
    if (_cssDone) return;
    _cssDone = true;
    var st = document.createElement('style');
    st.textContent =
      /* 체결 칩 — 매수 빨강 / 매도 파랑 + 흰 글씨 (position 계열) */
      '.dc-mk-layer{position:absolute;inset:0;overflow:hidden;pointer-events:none;z-index:2}' +
      /* 높이 조절 손잡이 — 차트 아래 가장자리. 평소엔 거의 안 보이고 올리면 드러난다 */
      '.dc-rsz{position:absolute;left:0;right:0;bottom:-3px;height:10px;z-index:5;cursor:ns-resize;' +
        'display:flex;align-items:center;justify-content:center;touch-action:none}' +
      /* 평소에도 옅게 보인다 — 안 보이면 있는 줄을 모른다(실제로 못 찾으셨다) */
      '.dc-rsz::before{content:"";width:46px;height:3px;border-radius:3px;background:#c9d4de;opacity:.45;' +
        'transition:opacity .12s,width .12s}' +
      '.dc-rsz:hover::before,.dc-rsz.on::before{opacity:1;width:64px}' +
      '.dc-rsz span{position:absolute;bottom:12px;padding:1px 7px;border-radius:20px;font-size:11px;' +
        'font-weight:800;background:#22303f;color:#fff;pointer-events:none;white-space:nowrap}' +
      '.dc-bx-layer{position:absolute;inset:0;overflow:hidden;pointer-events:none;z-index:1}' +
      '.dc-bx{position:absolute;box-sizing:border-box;border-radius:2px}' +
      '.dc-bx .bx-lb{position:absolute;top:1px;left:4px;font-size:10px;font-weight:700;white-space:nowrap;' +
        'font-family:Pretendard,-apple-system,sans-serif}' +
      '.dc-mk{position:absolute;transform:translateX(-50%);white-space:nowrap;' +
        'font-size:11px;font-weight:800;line-height:1.35;color:#fff;padding:2px 6px;border-radius:5px;' +
        'font-variant-numeric:tabular-nums;box-shadow:0 1px 3px rgba(10,25,45,.28);' +
        'font-family:Pretendard,-apple-system,sans-serif}' +
      /* 거래대금 표시 — 거래량 막대 위에 마우스를 올렸을 때만 뜬다(스칠 때 자리를 안 먹게 떠다닌다) */
      '.dc-amt{position:absolute;z-index:4;pointer-events:none;white-space:nowrap;transform:translate(-50%,-100%);' +
        'font-size:11px;font-weight:800;line-height:1.35;padding:3px 7px;border-radius:6px;' +
        'font-variant-numeric:tabular-nums;font-family:Pretendard,-apple-system,sans-serif;' +
        'background:rgba(20,28,44,.94);color:#e8eefc;box-shadow:0 2px 8px rgba(6,12,26,.45)}' +
      '.dc-amt.light{background:rgba(255,255,255,.97);color:#22303f;box-shadow:0 2px 8px rgba(20,40,70,.18);' +
        'border:1px solid #d7e0ea}' +
      '.dc-amt small{display:block;font-size:10px;font-weight:700;opacity:.72;margin-top:1px}' +
      '.dc-mk.buy{background:#d32f2f}.dc-mk.sell{background:#1565c0}' +
      '.dc-mk .mk-l1{display:block}' +
      '.dc-mk .mk-l2{display:block;font-size:10px;font-weight:700;opacity:.82;margin-top:1px;' +
        'border-top:1px solid rgba(255,255,255,.35);padding-top:1px}' +
      /* 기간 바 — [일봉|주봉 ┃ 160일 240일 480일 전체] 를 한 박스로 감싼 세그먼트 */
      '.dc-pbar{display:inline-flex;align-items:center;border-radius:8px;padding:3px;gap:2px;vertical-align:middle}' +
      '.dc-pbar button{border:0;cursor:pointer;font-weight:700;font-size:12px;padding:4px 10px;' +
        'border-radius:6px;background:transparent;font-family:inherit;line-height:1.3;white-space:nowrap}' +
      '.dc-pbar .dc-pdiv{width:1px;align-self:stretch;margin:2px 4px}' +
      '.dc-pbar .dc-pnum{min-width:46px;text-align:center;font-size:12px;font-weight:800;' +
        'font-variant-numeric:tabular-nums;padding:0 2px}' +
      '.dc-pbar.light .dc-pnum{color:#22303f}' +
      '.dc-pbar.dark .dc-pnum{color:var(--ink,#dfe6f2)}' +
      '.dc-pbar.light{background:#fff;border:1px solid #d3dde6}' +
      '.dc-pbar.light button{color:#5b6c7d}.dc-pbar.light button:hover{color:#22303f;background:#eef3f7}' +
      '.dc-pbar.light button.on{background:#22303f;color:#fff}.dc-pbar.light button.on:hover{background:#22303f}' +
      '.dc-pbar.light .dc-pdiv{background:#d3dde6}' +
      '.dc-pbar.dark{background:var(--panel-2,#1b2335);border:1px solid var(--line,#26304a)}' +
      '.dc-pbar.dark button{color:var(--ink-dim,#8893ab)}.dc-pbar.dark button:hover{color:var(--ink,#dfe6f2)}' +
      '.dc-pbar.dark button.on{background:var(--accent,#d9a441);color:#0b1020}' +
      '.dc-pbar.dark .dc-pdiv{background:var(--line,#26304a)}' +
      /* 전체화면 — 차트를 화면 가득 (host 와 조작 바를 옮겨 띄운다) */
      '.dc-fs{position:fixed;inset:0;z-index:9998;box-sizing:border-box;display:flex;flex-direction:column;' +
        'gap:8px;padding:10px 14px 14px;background:#fff}' +
      '.dc-fs.dark{background:var(--bg,#0e1320)}' +
      '.dc-fs-bar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;flex:0 0 auto}' +
      '.dc-fs-x{margin-left:auto;cursor:pointer;font-family:inherit;' +
        'font-size:12.5px;font-weight:700;padding:5px 12px;border-radius:7px;' +
        'background:#fff;border:1px solid #d3dde6;color:#3c4d5e}' +
      '.dc-fs-x:hover{background:#eef3f7}' +
      '.dc-fs.dark .dc-fs-x{background:var(--panel-2,#1b2335);border-color:var(--line,#26304a);color:var(--ink,#dfe6f2)}' +
      /* 사용자 지표 바 — 지표 선택 + 변수 입력 + 적용 + 적용중 칩 */
      '.dc-ibar{display:inline-flex;align-items:center;gap:5px;flex-wrap:wrap;vertical-align:middle;font-size:12px;font-family:inherit}' +
      '.dc-ibar select,.dc-ibar input{font-family:inherit;font-size:12px;border-radius:6px;padding:3px 6px;outline:none}' +
      '.dc-ibar input{width:58px}' +
      '.dc-ibar .dc-ivar{display:inline-flex;align-items:center;gap:3px;font-weight:700}' +
      '.dc-ibar .dc-iapply{border:0;cursor:pointer;font-weight:700;font-size:12px;padding:4px 10px;border-radius:6px;font-family:inherit}' +
      '.dc-ichip{display:inline-flex;align-items:center;gap:5px;border-radius:20px;padding:2px 9px;font-weight:700;font-size:11.5px}' +
      '.dc-ichip i{width:8px;height:8px;border-radius:50%;display:inline-block}' +
      '.dc-ichip b{cursor:pointer;opacity:.65;font-weight:800}' +
      '.dc-ibar.light select,.dc-ibar.light input{background:#fff;border:1px solid #c9d4de;color:#3c4d5e}' +
      '.dc-ibar.light .dc-ivar{color:#5b6c7d}' +
      '.dc-ibar.light .dc-iapply{background:#22303f;color:#fff}' +
      '.dc-ibar.light .dc-ichip{background:#eef3f7;color:#3c4d5e}' +
      '.dc-ibar.dark select,.dc-ibar.dark input{background:var(--panel-2,#1b2335);border:1px solid var(--line,#26304a);color:var(--ink,#dfe6f2)}' +
      '.dc-ibar.dark .dc-ivar{color:var(--ink-dim,#8893ab)}' +
      '.dc-ibar.dark .dc-iapply{background:var(--accent,#d9a441);color:#0b1020}' +
      '.dc-ibar.dark .dc-ichip{background:var(--panel-2,#1b2335);color:var(--ink,#dfe6f2);border:1px solid var(--line,#26304a)}' +
      /* 차트틀 영역 구분선 + 지표 값 범례 */
      '.dc-pdiv2{width:1px;align-self:stretch;margin:0 4px;background:#d3dde6}' +
      '.dc-ibar.dark .dc-pdiv2{background:var(--line,#26304a)}' +
      '.dc-pset{font-weight:700}' +
      '.dc-ind-legend{display:inline-flex;gap:14px;flex-wrap:wrap;align-items:center}' +
      /* 다크 화면(범례 컨테이너에 .dc-leg-dark)에서 쓰는 지표 값 표시 */
      '.dc-leg-dark .dc-ilg{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:700;' +
        'color:var(--ink,#dfe6f2);margin-left:10px}' +
      '.dc-leg-dark .dc-ilg i{width:9px;height:3px;border-radius:2px;display:inline-block}' +
      '.dc-leg-dark .dc-ilg b{font-variant-numeric:tabular-nums}' +
      /* 라이트 화면 — 기존 .cl-item 스타일을 쓰되 없을 때를 대비한 최소 규칙 */
      '.dc-ilg{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600}' +
      '.dc-ilg i{width:12px;height:3px;border-radius:2px;display:inline-block}' +
      '.dc-ilg b{font-variant-numeric:tabular-nums}' +
      /* 지표 칩 = 클릭하면 변수 수정 모달 (✕ 는 제거) */
      '.dc-ichip{cursor:pointer}' +
      /* ── 도구모음 미리보기 (차트설정 > 구성) — 진짜 바와 같은 클래스를 쓰고 배치만 여기서 ── */
      '.dc-tbprev{display:flex;flex-direction:column;gap:7px;padding:9px 10px;border-radius:9px;' +
        'background:#f6f9fc;border:1px solid #e3eaf0}' +
      '.dc-tbprev.dark{background:#0e1320;border-color:#26304a}' +
      '.dc-tbrow{display:flex;align-items:center;gap:8px;flex-wrap:wrap}' +
      '.dc-tbleg{font-size:11.5px;font-weight:700;color:#5b6c7d}' +
      '.dc-tbleg.dark{color:#dfe6f2}' +
      '.dc-tbnote{font-size:11.5px;color:#8496a6}' +
      '.dc-tbnote.dark{color:#8893ab}' +
      /* ── 모달 (지표 선택 · 변수 수정) — 화면 어디서 열어도 같은 모양 ── */
      '.dc-modal{position:fixed;inset:0;z-index:9999;background:rgba(10,20,35,.45);' +
        'display:flex;align-items:center;justify-content:center;padding:16px;' +
        'font-family:Pretendard,-apple-system,sans-serif}' +
      '.dc-modal .dc-mbox{display:flex;flex-direction:column;overflow:hidden;' +
        'min-width:210px;max-width:290px;width:100%;max-height:78vh;border-radius:10px;' +
        'background:#fff;box-shadow:0 12px 36px rgba(10,25,45,.36)}' +
      '.dc-modal .dc-mhd{display:flex;align-items:baseline;justify-content:space-between;gap:8px;' +
        'padding:8px 11px;border-bottom:1px solid #e3eaf0;font-size:12.5px;font-weight:800;color:#22303f}' +
      '.dc-modal .dc-mhd .sub{font-size:11px;font-weight:600;color:#8496a6;white-space:nowrap}' +
      '.dc-modal .dc-mbd{padding:5px 6px;overflow:auto}' +
      '.dc-modal .dc-mft{display:flex;justify-content:flex-end;gap:5px;padding:7px 9px;border-top:1px solid #e3eaf0}' +
      '.dc-modal .dc-mft button{border:0;cursor:pointer;font-family:inherit;font-size:12px;font-weight:700;' +
        'padding:5px 11px;border-radius:6px;background:#eef3f7;color:#3c4d5e}' +
      '.dc-modal .dc-mft button.pri{background:#22303f;color:#fff}' +
      '.dc-modal .dc-mft button.del{background:#fdecea;color:#c0241a;margin-right:auto}' +
      '.dc-modal .dc-mrow{display:flex;align-items:center;gap:7px;padding:5px 6px;border-radius:6px;' +
        'cursor:pointer;font-size:12px;color:#3c4d5e}' +
      '.dc-modal .dc-mrow:hover{background:#f2f6fa}' +
      '.dc-modal .dc-mrow i{width:8px;height:8px;border-radius:50%;flex:none}' +
      '.dc-modal .dc-mrow b{font-weight:700}' +
      '.dc-modal .dc-mrow em{margin-left:auto;font-style:normal;font-size:10.5px;color:#8496a6;' +
        'white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:50%}' +
      /* 차트 관리 줄 — 고르는 줄이 아니라 「이름 + 삭제」다 */
      '.dc-modal .dc-mrow.plain{cursor:default}' +
      '.dc-modal .dc-mrow.plain b{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
      '.dc-modal .dc-mdel{flex:none;margin-left:8px;border:0;cursor:pointer;font-family:inherit;' +
        'font-size:11px;font-weight:700;padding:3px 9px;border-radius:6px;background:#fdecea;color:#c0241a}' +
      '.dc-modal .dc-mdel:disabled{opacity:.5;cursor:default}' +
      '.dc-modal.dark .dc-mdel{background:#3a1d1d;color:#f0928a}' +
      '.dc-modal .dc-mvar{display:flex;align-items:center;gap:8px;padding:3px 6px;font-size:12px}' +
      '.dc-modal .dc-mvar label{flex:1;min-width:0;font-weight:700;color:#3c4d5e;' +
        'white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
      '.dc-modal .dc-mvar input{flex:none;width:74px;text-align:right;font-family:inherit;font-size:12px;' +
        'padding:4px 7px;border-radius:6px;border:1px solid #c9d4de;background:#fff;color:#3c4d5e;outline:none}' +
      '.dc-modal .dc-mnote{padding:5px 7px;font-size:11px;line-height:1.5;color:#8496a6}' +
      /* 다크 — 페이지 CSS 변수를 그대로 쓴다 */
      '.dc-modal.dark .dc-mbox{background:var(--panel,#131a2a)}' +
      '.dc-modal.dark .dc-mhd{border-bottom-color:var(--line,#26304a);color:var(--ink,#dfe6f2)}' +
      '.dc-modal.dark .dc-mft{border-top-color:var(--line,#26304a)}' +
      '.dc-modal.dark .dc-mft button{background:var(--panel-2,#1b2335);color:var(--ink,#dfe6f2)}' +
      '.dc-modal.dark .dc-mft button.pri{background:var(--accent,#d9a441);color:#0b1020}' +
      '.dc-modal.dark .dc-mft button.del{background:#3a1d1d;color:#f0928a}' +
      '.dc-modal.dark .dc-mrow{color:var(--ink,#dfe6f2)}' +
      '.dc-modal.dark .dc-mrow:hover{background:var(--panel-2,#1b2335)}' +
      '.dc-modal.dark .dc-mvar label{color:var(--ink,#dfe6f2)}' +
      '.dc-modal.dark .dc-mvar input{background:var(--panel-2,#1b2335);border-color:var(--line,#26304a);' +
        'color:var(--ink,#dfe6f2)}';
    document.head.appendChild(st);
  }

  /* (구 신호 엔진 — '60봉 신고가 돌파+거래량 2배' 흰칩·전고점선 — 은 2026-08-02 삭제됨.
   * 상승확률 엔진(rise_*) 폐기의 마무리: 정적 추정 승률(78/82/90%)이 실측과 어긋나
   * 잘못된 신호를 계속 내보냈다. 복원하지 않는다 — [[session_상승확률분석]] 참조.) */

  /* ══════════════════════════════════════════════════════════════════════
   * 사용자 지표 수식 엔진 (키움 수식관리자 방식)
   *
   * 한 수식칸 = 여러 문장(;) — 「이름 = 수식;」 으로 변수를 정의해 쌓고,
   * 마지막 문장이 대입이 아니면 그 값이 이 칸의 출력(선/점)이다.
   * 마지막 문장까지 대입이면 이 칸은 변수 정의 전용(그리지 않음 — 키움의 * 수식).
   * 변수는 뒤 수식칸에서도 그대로 쓴다 (수식1에서 정의 → 수식4에서 사용).
   *
   * 심볼(한글 가능·대소문자 무관):
   *   C/종가 O/시가 H/고가 L/저가 V/거래량 AMT/거래대금(종가×거래량)
   *   HM/시각(0900→900) FIRSTBAR/첫봉 LASTBAR/막봉 — ★분봉에서만 값이 생긴다(일·주·월은 0)
   * 함수:
   *   MA(x,n) 이동평균 · SUM(x,n) n봉 합 · SUM(x) 누적합 · HIGHEST/LOWEST(x,n)
   *   HIGHEST(x)/LOWEST(x) 처음부터 지금까지의 최고/최저 (SUM(x) 와 같은 「인자 1개=누적」 규칙)
   *   STD(x,n) 표준편차 · REF(x,n) n봉 전 값 · ABS · MIN · MAX · ROUND
   *   CROSS(a,b) 상향돌파=1 · VALUEWHEN(n,조건,값) 최근 n번째 조건 참 시점의 값
   * 키움 문법: 이름(1) = 그 값의 1봉 전 (REF 와 동일) · and or not 도 됨
   * 연산: + - * / %   비교 > < >= <= == !=   논리 && || !   괄호 ()
   * 그 외 식별자 = 사용자 변수(vars 의 숫자) 또는 앞에서 대입한 이름.
   *
   * 값이 null(워밍업 구간·정지봉)인 봉은 선이 끊기고 점이 찍히지 않는다.
   * ════════════════════════════════════════════════════════════════════ */
  var _exprCache = {};
  var _indErrSeen = {};      // 그리기 실패 알림 중복 방지 (지표 이름|메시지)

  function exprTokenize(src) {
    var re = /([A-Za-z_가-힣][A-Za-z0-9_가-힣]*)|(\d+(?:\.\d+)?)|(>=|<=|==|!=|&&|\|\||=)|([-+*\/%(),<>!;])|(\s+)/g;
    var out = [], m, pos = 0;
    while ((m = re.exec(src))) {
      if (m.index !== pos) throw new Error('수식 해석 불가: "' + src.slice(pos, m.index) + '"');
      pos = re.lastIndex;
      if (m[5]) continue;                                    // 공백
      if (m[1]) {
        var id = m[1].toUpperCase();
        if (id === 'AND') out.push({ t: 'op', v: '&&' });         // 키움식 단어 연산자
        else if (id === 'OR') out.push({ t: 'op', v: '||' });
        else if (id === 'NOT') out.push({ t: 'op', v: '!' });
        else out.push({ t: 'id', v: id });
      }
      else if (m[2]) out.push({ t: 'num', v: parseFloat(m[2]) });
      else out.push({ t: 'op', v: m[3] || m[4] });
    }
    if (pos !== src.length) throw new Error('수식 해석 불가: "' + src.slice(pos) + '"');
    return out;
  }

  /* 프로그램(문장 목록) 파싱 → { stmts:[{assign?:이름, e:AST}] } */
  function exprParse(src) {
    var tk = exprTokenize(src), p = 0;
    function peek() { return tk[p]; }
    function eat(v) {
      var t = tk[p];
      if (!t || (v !== undefined && !(t.t === 'op' && t.v === v))) {
        throw new Error('수식 오류: ' + (t ? '"' + t.v + '" 근처' : '수식이 일찍 끝남'));
      }
      p++; return t;
    }
    function isOp(v) { var t = tk[p]; return t && t.t === 'op' && t.v === v; }

    function primary() {
      var t = peek();
      if (!t) throw new Error('수식이 일찍 끝남');
      if (t.t === 'num') { p++; return { k: 'num', v: t.v }; }
      if (t.t === 'id') {
        p++;
        if (isOp('(')) {
          eat('(');
          var args = [];
          if (!isOp(')')) {
            args.push(orExpr());
            while (isOp(',')) { eat(','); args.push(orExpr()); }
          }
          eat(')');
          return { k: 'call', n: t.v, a: args };
        }
        return { k: 'id', n: t.v };
      }
      if (isOp('(')) { eat('('); var e = orExpr(); eat(')'); return e; }
      throw new Error('수식 오류: "' + t.v + '"');
    }
    function unary() {
      if (isOp('-')) { eat('-'); return { k: 'neg', a: unary() }; }
      if (isOp('!')) { eat('!'); return { k: 'not', a: unary() }; }
      if (isOp('+')) { eat('+'); return unary(); }
      return primary();
    }
    function mul() {
      var e = unary();
      while (isOp('*') || isOp('/') || isOp('%')) { var o = eat().v; e = { k: 'bin', o: o, a: e, b: unary() }; }
      return e;
    }
    function add() {
      var e = mul();
      while (isOp('+') || isOp('-')) { var o = eat().v; e = { k: 'bin', o: o, a: e, b: mul() }; }
      return e;
    }
    function cmp() {
      var e = add();
      while (isOp('>') || isOp('<') || isOp('>=') || isOp('<=') || isOp('==') || isOp('!=')) {
        var o = eat().v; e = { k: 'bin', o: o, a: e, b: add() };
      }
      return e;
    }
    function andExpr() {
      var e = cmp();
      while (isOp('&&')) { eat('&&'); e = { k: 'bin', o: '&&', a: e, b: cmp() }; }
      return e;
    }
    function orExpr() {
      var e = andExpr();
      while (isOp('||')) { eat('||'); e = { k: 'bin', o: '||', a: e, b: andExpr() }; }
      return e;
    }

    var stmts = [];
    while (p < tk.length) {
      if (isOp(';')) { eat(';'); continue; }                 // 빈 문장·연속 세미콜론 허용
      var st;
      if (tk[p] && tk[p].t === 'id' && tk[p + 1] && tk[p + 1].t === 'op' && tk[p + 1].v === '=') {
        var name = tk[p].v; p += 2;
        st = { assign: name, e: orExpr() };
      } else {
        st = { e: orExpr() };
      }
      stmts.push(st);
      if (p < tk.length) eat(';');                           // 문장 사이 ; (마지막은 생략 가능)
    }
    if (!stmts.length) throw new Error('수식이 비었습니다');
    return { stmts: stmts };
  }

  function exprCompile(src) {
    if (!_exprCache[src]) _exprCache[src] = exprParse(src);
    return _exprCache[src];
  }

  /* AST 평가 — 결과는 스칼라(number) 또는 배열(number|null 의 목록) */
  function exprEval(node, env) {
    var n = env.len;
    function toArr(v) {
      if (Array.isArray(v)) return v;
      var a = new Array(n);
      for (var i = 0; i < n; i++) a[i] = v;
      return a;
    }
    function el2(va, vb, f) {                 // 요소별 2항 (null 전파)
      if (!Array.isArray(va) && !Array.isArray(vb)) {
        return (va === null || vb === null) ? null : f(va, vb);
      }
      var a = toArr(va), b = toArr(vb), out = new Array(n);
      for (var i = 0; i < n; i++) {
        out[i] = (a[i] === null || a[i] === undefined || b[i] === null || b[i] === undefined)
          ? null : f(a[i], b[i]);
      }
      return out;
    }
    function el1(v, f) {
      if (!Array.isArray(v)) return v === null ? null : f(v);
      var out = new Array(n);
      for (var i = 0; i < n; i++) out[i] = (v[i] === null || v[i] === undefined) ? null : f(v[i]);
      return out;
    }
    function needN(v, fn) {                   // 창 길이 인자 — 양의 정수 스칼라만
      if (Array.isArray(v) || v === null || !isFinite(v) || v < 1) {
        throw new Error(fn + '의 기간 인자는 1 이상 숫자여야 합니다');
      }
      return Math.floor(v);
    }
    function rolling(v, win, f) {             // 완전한 창(null 없음)에서만 값
      var a = toArr(v), out = new Array(n);
      for (var i = 0; i < n; i++) {
        if (i < win - 1) { out[i] = null; continue; }
        var buf = [], ok = true;
        for (var k = i - win + 1; k <= i; k++) {
          if (a[k] === null || a[k] === undefined) { ok = false; break; }
          buf.push(a[k]);
        }
        out[i] = ok ? f(buf) : null;
      }
      return out;
    }

    switch (node.k) {
      case 'num': return node.v;
      case 'id': {
        if (node.n in env.locals) return env.locals[node.n];   // 앞 문장·앞 수식칸에서 대입한 이름
        if (node.n in env.series) return env.series[node.n];
        if (node.n in env.vars)   return env.vars[node.n];
        throw new Error('알 수 없는 이름: ' + node.n + ' (변수 기본값 또는 앞 수식의 대입을 확인)');
      }
      case 'neg': return el1(exprEval(node.a, env), function (x) { return -x; });
      case 'not': return el1(exprEval(node.a, env), function (x) { return x ? 0 : 1; });
      case 'bin': {
        var a = exprEval(node.a, env), b = exprEval(node.b, env);
        switch (node.o) {
          case '+': return el2(a, b, function (x, y) { return x + y; });
          case '-': return el2(a, b, function (x, y) { return x - y; });
          case '*': return el2(a, b, function (x, y) { return x * y; });
          case '/': return el2(a, b, function (x, y) { return y === 0 ? null : x / y; });
          case '%': return el2(a, b, function (x, y) { return y === 0 ? null : x % y; });
          case '>':  return el2(a, b, function (x, y) { return x >  y ? 1 : 0; });
          case '<':  return el2(a, b, function (x, y) { return x <  y ? 1 : 0; });
          case '>=': return el2(a, b, function (x, y) { return x >= y ? 1 : 0; });
          case '<=': return el2(a, b, function (x, y) { return x <= y ? 1 : 0; });
          case '==': return el2(a, b, function (x, y) { return x === y ? 1 : 0; });
          case '!=': return el2(a, b, function (x, y) { return x !== y ? 1 : 0; });
          case '&&': return el2(a, b, function (x, y) { return (x && y) ? 1 : 0; });
          case '||': return el2(a, b, function (x, y) { return (x || y) ? 1 : 0; });
        }
        throw new Error('연산자 오류: ' + node.o);
      }
      case 'call': {
        var fn = node.n;
        var args = node.a.map(function (x) { return exprEval(x, env); });
        switch (fn) {
          case 'MA':      return rolling(args[0], needN(args[1], 'MA'), function (b) {
                            var s = 0; b.forEach(function (x) { s += x; }); return s / b.length; });
          case 'SUM': {
            if (node.a.length === 1) {          // SUM(x) 1인자 = 누적합 (키움 sum(1)=봉수)
              var sa = toArr(args[0]), so = new Array(n), run = 0;
              for (var si = 0; si < n; si++) {
                if (sa[si] !== null && sa[si] !== undefined) run += sa[si];
                so[si] = run;
              }
              return so;
            }
            return rolling(args[0], needN(args[1], 'SUM'), function (b) {
              var s = 0; b.forEach(function (x) { s += x; }); return s; });
          }
          /* HIGHEST/LOWEST — 인자 2개는 n봉 창, 인자 1개는 «처음부터 지금까지»의 누적이다.
           * (SUM(x) 가 이미 누적합인 것과 같은 규칙. 창 길이는 상수라 「지금까지」를 못 적는데,
           *  「직전 최고를 넘은 봉」 같은 신고 판정이 그 값을 필요로 한다.)
           * ★null(정지봉·워밍업)은 «건너뛴다» — 창 함수처럼 결과를 null 로 만들지 않는다.
           *   한 봉이 비었다고 지금까지의 최고가 사라지면 그 뒤가 통째로 끊긴다. */
          case 'HIGHEST':
          case 'LOWEST': {
            if (node.a.length === 1) {
              var ra = toArr(args[0]), ro = new Array(n), run = null;
              var up = (fn === 'HIGHEST');
              for (var rk = 0; rk < n; rk++) {
                var rv = ra[rk];
                if (rv !== null && rv !== undefined) {
                  if (run === null || (up ? rv > run : rv < run)) run = rv;
                }
                ro[rk] = run;
              }
              return ro;
            }
            return (fn === 'HIGHEST')
              ? rolling(args[0], needN(args[1], 'HIGHEST'), function (b) { return Math.max.apply(null, b); })
              : rolling(args[0], needN(args[1], 'LOWEST'),  function (b) { return Math.min.apply(null, b); });
          }
          case 'STD':     return rolling(args[0], needN(args[1], 'STD'), function (b) {
                            var m = 0; b.forEach(function (x) { m += x; }); m /= b.length;
                            var s = 0; b.forEach(function (x) { s += (x - m) * (x - m); });
                            return Math.sqrt(s / b.length); });
          case 'REF': {
            var off = needN(args[1], 'REF'), src = toArr(args[0]), out = new Array(n);
            for (var i = 0; i < n; i++) out[i] = (i - off >= 0) ? src[i - off] : null;
            return out;
          }
          case 'ABS':   return el1(args[0], Math.abs);
          case 'ROUND': return el1(args[0], Math.round);
          case 'MIN':   return el2(args[0], args[1], Math.min);
          case 'MAX':   return el2(args[0], args[1], Math.max);
          case 'CROSS': {
            var xa = toArr(args[0]), xb = toArr(args[1]), oc = new Array(n);
            for (var j = 0; j < n; j++) {
              if (j === 0 || xa[j] === null || xb[j] === null || xa[j-1] === null || xb[j-1] === null
                  || xa[j] === undefined || xb[j] === undefined) { oc[j] = null; continue; }
              oc[j] = (xa[j-1] <= xb[j-1] && xa[j] > xb[j]) ? 1 : 0;
            }
            return oc;
          }
          case 'VALUEWHEN': {                   // 최근 nth번째로 조건이 참이었던 시점의 값
            var nth = needN(args[0], 'VALUEWHEN');
            var cd = toArr(args[1]), vv = toArr(args[2]), ow = new Array(n), idxs = [];
            for (var wi = 0; wi < n; wi++) {
              if (cd[wi] !== null && cd[wi] !== undefined && cd[wi] !== 0) idxs.push(wi);
              ow[wi] = (idxs.length >= nth) ? vv[idxs[idxs.length - nth]] : null;
            }
            return ow;
          }
        }
        // 함수가 아니면 「이름(n)」 = 그 값의 n봉 전 (키움 문법: 최고거래량(1))
        var found = (fn in env.locals) ? env.locals[fn]
                  : (fn in env.series) ? env.series[fn]
                  : (fn in env.vars)   ? env.vars[fn] : undefined;
        if (found !== undefined) {
          if (node.a.length !== 1) throw new Error(fn + '(n) 형식은 인자 1개(몇 봉 전)만 받습니다');
          var offv = args[0];
          if (Array.isArray(offv) || offv === null || !isFinite(offv) || offv < 0) {
            throw new Error(fn + '(n) 의 n 은 0 이상 숫자여야 합니다');
          }
          var off2 = Math.floor(offv), sr2 = toArr(found), o2 = new Array(n);
          for (var ri = 0; ri < n; ri++) o2[ri] = (ri - off2 >= 0) ? sr2[ri - off2] : null;
          return o2;
        }
        throw new Error('알 수 없는 함수: ' + fn);
      }
    }
    throw new Error('수식 평가 오류');
  }

  /* 변수 기본값의 시간축별 해석.
   * 신형: {day:{N:20}, week:{N:52}, month:{N:12}, min:{N:120}} — 일 값이 기본, 나머지 축은 덮어씀.
   * 구형(평평한 {N:20})은 모든 축 공통.
   * ★분(min)도 «비면 일 값»을 쓴다 — 주·월과 같은 규칙이다. 분봉만 다르게 쓰고 싶을 때만 채운다. */
  var TFS = ['day', 'week', 'month', 'min'];
  function tfVars(vars, tf) {
    if (!vars) return {};
    var per = false;
    TFS.forEach(function (k) { if (vars[k] && typeof vars[k] === 'object') per = true; });
    if (!per) return vars;
    var out = {};
    var base = (vars.day && typeof vars.day === 'object') ? vars.day : {};
    Object.keys(base).forEach(function (k) { out[k] = base[k]; });
    var ov = (tf && tf !== 'day' && vars[tf] && typeof vars[tf] === 'object') ? vars[tf] : null;
    if (ov) Object.keys(ov).forEach(function (k) { out[k] = ov[k]; });
    return out;
  }

  /* 이 지표가 쓰는 변수 이름 — 정의 순서 그대로 (신형 {day,week,month} / 구형 평평 둘 다) */
  function indVarNames(def) {
    var vs = (def && def.vars) || {};
    var per = false;
    TFS.forEach(function (k) { if (vs[k] && typeof vs[k] === 'object') per = true; });
    var maps = per ? TFS.map(function (k) { return vs[k] || {}; }) : [vs];
    var seen = {}, out = [];
    maps.forEach(function (m) {
      Object.keys(m).forEach(function (k) { if (!seen[k]) { seen[k] = 1; out.push(k); } });
    });
    return out;
  }
  /* 이 지표를 이 시간축에서 «보여 줄» 것인가 (지표 관리의 일/주/월/분 체크칸).
   * ★비어 있으면 전 축이다 — 체크칸이 생기기 전에 만든 지표가 조용히 사라지면 안 된다.
   * 판정은 이 한 함수뿐이다: 그리기(renderIndicators) · 칩 · 지표 선택 모달이 전부 여기를 본다. */
  function indTfOk(def, tf) {
    var a = def && def.tfs;
    if (!a || !a.length) return true;
    return a.indexOf(tf || 'day') >= 0;
  }
  /* 지금 이 축에서 실제로 쓰이는 변수 값 — 축별 기본값에 화면 override 를 얹은 것 */
  function indVarValues(def, override, tf) {
    var out = tfVars(def && def.vars, tf || 'day');
    Object.keys(override || {}).forEach(function (k) {
      if (override[k] !== '' && isFinite(+override[k])) out[k] = +override[k];
    });
    return out;
  }
  /* ── 이름 속 변수 토큰을 값으로 바꿔 표시한다 ──
   *   #1 #2 …   n번째 변수  ← ★변수 이름을 바꿔도 안 깨진다 (권장)
   *   #변수명    그 변수     ← 갤러리에서 이름을 바꾸면 토큰도 함께 바뀐다
   *   #*        전체 값을 · 로 이어서
   * 예: '최고거래대금선(#1)' → '최고거래대금선(240)' · 주봉이면 '(104)'
   * 못 찾은 토큰은 그대로 남긴다 — 눈에 보여야 고친다. */
  var VAR_TOKEN = /#(\*|\d+|[A-Za-z_가-힣][A-Za-z0-9_가-힣]*)/g;
  function indLabel(name, def, override, tf) {
    name = String(name === undefined || name === null ? '' : name);
    if (name.indexOf('#') < 0) return name;
    var names = indVarNames(def), vals = indVarValues(def, override, tf);
    function num(v) { return (v === undefined || v === null) ? null : String(+v); }
    return name.replace(VAR_TOKEN, function (raw, tok) {
      if (tok === '*') {
        var all = names.map(function (k) { return num(vals[k]); }).filter(function (v) { return v !== null; });
        return all.length ? all.join('·') : raw;
      }
      if (/^\d+$/.test(tok)) {
        var v = num(vals[names[+tok - 1]]);
        return v === null ? raw : v;
      }
      var up = tok.toUpperCase();
      for (var i = 0; i < names.length; i++) {
        if (names[i].toUpperCase() === up) {
          var v2 = num(vals[names[i]]);
          if (v2 !== null) return v2;
        }
      }
      return raw;
    });
  }

  /* 봉 배열 → 평가 환경. locals 는 수식칸끼리 공유하는 대입 변수 저장소 */
  function buildEnv(bars, def, varsOverride, locals, tf) {
    var C = [], O = [], H = [], L = [], V = [], AMT = [];
    bars.forEach(function (b) {
      var c = b.close, v = (b.vol === null || b.vol === undefined) ? null : b.vol;
      C.push(c);
      O.push((b.open  === null || b.open  === undefined || b.open  === 0) ? c : b.open);
      H.push((b.high  === null || b.high  === undefined || b.high  === 0) ? c : b.high);
      L.push((b.low   === null || b.low   === undefined || b.low   === 0) ? c : b.low);
      V.push(v);
      /* 거래대금 — KRX 실제값(b.amt)이 있으면 그것을, 없으면 종가×거래량으로 근사한다.
       * ★ 근사는 VWAP 대비 중앙 0.99% 오차라 「전고 거래대금 돌파」 판정이 갈릴 수 있다
       *   (실측 2026-07-31 · 7,949표본). 그래서 cron/krx_amt.php 로 실제값을 받아 둔다. */
      AMT.push(b.amt !== undefined && b.amt !== null ? b.amt : (v === null ? null : c * v));
    });

    /* ── 하루 안에서의 자리 — 분봉에서만 뜻이 있다 ──
     *   시각 HM  09:00 → 900 · 15:30 → 1530
     *   첫봉/막봉  그 날의 첫/마지막 봉이면 1
     * 쓰는 이유: 대형주는 시가·종가 단일가 물량이 그 한 봉에 통째로 실려 「그 날 최고 거래량 봉」이
     * 09:00·15:30 으로 쏠린다. 그건 장중 매집이 아니라 주문 접수 결과라 지지·저항으로 읽으면 어긋난다.
     * ★일·주·월 봉은 time 이 'YYYY-MM-DD' 문자열이라 하루 안의 자리가 없다 → 셋 다 0 이다.
     *   (봉 하나가 곧 하루라 「첫봉」이 1 이 되면 `1-첫봉` 류 수식이 전 구간을 지워 버린다 —
     *    0 = 「뺄 것이 없다」가 안전하고 뜻도 맞다.) */
    var HM = new Array(bars.length), FIRST = new Array(bars.length), LAST = new Array(bars.length);
    var dayKey = new Array(bars.length);
    for (var bi = 0; bi < bars.length; bi++) {
      var t = bars[bi].time;
      if (typeof t === 'number' && isFinite(t)) {
        var d = new Date(t * 1000);                       // KST 벽시계를 UTC 로 담아 둔 값
        HM[bi] = d.getUTCHours() * 100 + d.getUTCMinutes();
        dayKey[bi] = Math.floor(t / 86400);
      } else {
        HM[bi] = 0; dayKey[bi] = null;
      }
      FIRST[bi] = 0; LAST[bi] = 0;
    }
    for (var fi = 0; fi < bars.length; fi++) {
      if (dayKey[fi] === null) continue;
      if (fi === 0 || dayKey[fi - 1] !== dayKey[fi]) FIRST[fi] = 1;
      if (fi === bars.length - 1 || dayKey[fi + 1] !== dayKey[fi]) LAST[fi] = 1;
    }

    var vars = {};
    var base = tfVars(def && def.vars, tf || 'day');
    Object.keys(base).forEach(function (k) { vars[k.toUpperCase()] = +base[k]; });
    Object.keys(varsOverride || {}).forEach(function (k) {
      if (varsOverride[k] !== '' && isFinite(+varsOverride[k])) vars[k.toUpperCase()] = +varsOverride[k];
    });
    return {
      len: bars.length, vars: vars, locals: locals || {},
      series: { C: C, O: O, H: H, L: L, V: V, AMT: AMT,
                CLOSE: C, OPEN: O, HIGH: H, LOW: L, VOL: V,
                '종가': C, '시가': O, '고가': H, '저가': L, '거래량': V, '거래대금': AMT,
                HM: HM, FIRSTBAR: FIRST, LASTBAR: LAST,
                '시각': HM, '첫봉': FIRST, '막봉': LAST }
    };
  }

  /* 프로그램 실행 — 대입은 locals 에 쌓고, 마지막 문장이 대입이 아니면 그 값이 출력.
   * 마지막까지 대입이면 null(변수 정의 전용 수식칸). */
  function evalProgram(prog, env) {
    var out = null;
    for (var i = 0; i < prog.stmts.length; i++) {
      var s = prog.stmts[i];
      var v = exprEval(s.e, env);
      if (s.assign) env.locals[s.assign] = v;
      else out = v;
      if (i === prog.stmts.length - 1 && s.assign) out = null;
    }
    return out;
  }

  /* 수식칸 하나 실행 → 봉별 값 배열 (출력 없는 칸이면 null). tf='day'|'week'|'month' — 변수 세트 선택 */
  function evalIndicator(bars, def, varsOverride, tf) {
    var env = buildEnv(bars, def, varsOverride, {}, tf);
    var v = evalProgram(exprCompile(String(def.expr || def.body || '')), env);
    if (v === null) return null;
    if (!Array.isArray(v)) {           // 상수 수식 — 수평선으로 쓸 수 있게 배열로 편다
      var arr = new Array(bars.length);
      for (var i = 0; i < bars.length; i++) arr[i] = v;
      return arr;
    }
    return v;
  }

  /* 지표의 수식칸 목록 정규화 — 신형 lines([{name,body,on,color}]) / 구형 expr 단일 */
  function indSlots(def) {
    var lines = def.lines;
    if (!lines || !lines.length) {
      lines = [{ name: '', body: def.expr || '', on: 1, color: def.color }];
    }
    lines = lines.filter(function (s) { return s && String(s.body || '').trim() !== ''; });
    if (!lines.length) throw new Error('수식이 비었습니다');
    if (lines.length > 4) throw new Error('수식칸은 최대 4개입니다');
    return lines;
  }

  // 문법 검사 — 파싱 + 함수 이름·인자 수 (변수·대입 이름은 실행 시점에 값이 오므로 여기선 못 본다)
  var EXPR_ARITY = { MA: [2,2], SUM: [1,2], HIGHEST: [1,2], LOWEST: [1,2], STD: [2,2], REF: [2,2],
                     ABS: [1,1], ROUND: [1,1], MIN: [2,2], MAX: [2,2], CROSS: [2,2], VALUEWHEN: [3,3] };
  function astCheck(node) {
    if (!node) return;
    if (node.k === 'call') {
      var ar = EXPR_ARITY[node.n];
      if (ar) {
        if (node.a.length < ar[0] || node.a.length > ar[1]) {
          throw new Error(node.n + ' 함수는 인자 ' + (ar[0] === ar[1] ? ar[0] : ar[0] + '~' + ar[1])
            + '개가 필요합니다 (현재 ' + node.a.length + '개)');
        }
      } else if (node.a.length !== 1) {
        // 함수가 아닌 이름의 (…) 는 「이름(n)」 n봉 전 참조 — 인자 1개만 말이 된다
        throw new Error(node.n + ' 는 함수가 아닙니다 — 「이름(n)」(n봉 전) 형식은 인자 1개입니다');
      }
      node.a.forEach(astCheck);
    } else if (node.k === 'bin') { astCheck(node.a); astCheck(node.b); }
    else if (node.k === 'neg' || node.k === 'not') { astCheck(node.a); }
  }
  function checkExpr(body) {
    exprCompile(String(body)).stmts.forEach(function (s) { astCheck(s.e); });
    return true;
  }
  function checkDef(def) {
    indSlots(def).forEach(function (s) { checkExpr(s.body); });
    return true;
  }

  /* 지표 하나 → 출력 있는 수식칸별 [{name, color, vals}].
   * ★ 환경(locals)을 칸끼리 공유한다 — 수식1의 대입을 수식4가 쓴다 (키움 방식).
   * 사용 해제(on=0) 칸도 계산은 한다(변수 정의가 뒤 칸에 필요할 수 있음) — 출력만 숨긴다. */
  function evalIndicatorMulti(bars, def, varsOverride, tf) {
    var env = buildEnv(bars, def, varsOverride, {}, tf);
    var out = [];
    indSlots(def).forEach(function (s, si) {
      var v = evalProgram(exprCompile(String(s.body)), env);
      // def=1(수식 정의 칸)·사용 해제·출력 없음(대입으로 끝남)은 그리지 않는다 — 변수는 살아 있다
      if (Number(s.def) === 1 || Number(s.on) === 0 || v === null) return;
      if (!Array.isArray(v)) {
        var arr = new Array(bars.length);
        for (var i = 0; i < bars.length; i++) arr[i] = v;
        v = arr;
      }
      out.push({ name: s.name || '', color: s.color || def.color, vals: v,
                 si:  si,                                              // 수식칸 위치 (연장 즉석 조정이 이걸로 짚는다)
                 ext: Math.max(0, Math.min(10, +(s.ext || 0) || 0)),   // 옛 단계 연장 개수
                 exs: s.exs || 'dashed',                               // 연장선 종류
                 exw: Math.max(1, Math.min(5, +(s.exw || 0) || 1)),    // 연장선 두께 1~5pt (기본 1)
                 w:   Math.max(1, Math.min(5, +(s.w || 0) || 2)),      // 선 두께 1~5pt
                 st:  s.st || 'solid' });                              // 선 종류
    });
    return out;
  }

  /* ── 계단선의 «단계» 뽑기 — 값이 바뀌는 지점이 새 단계다 ──
   * VALUEWHEN 류(신호 때만 값이 바뀌는 선)에서 지나간 단계가 곧 지지·저항 자리다.
   * 같은 값이 여러 번 나오면 한 단계로 합친다(같은 가격에 선 두 개를 긋지 않는다).
   * 돌려주는 순서 = 마지막으로 유효했던 순 → 뒤쪽이 최근. */
  function stepLevels(vals) {
    var byVal = {}, order = [], prev = null;
    for (var i = 0; i < vals.length; i++) {
      var v = vals[i];
      if (v === null || v === undefined || !isFinite(v)) { prev = null; continue; }
      var k = String(v);
      if (!byVal[k]) { byVal[k] = { value: v, start: i, end: i }; order.push(k); }
      else { byVal[k].end = i; if (i < byVal[k].start) byVal[k].start = i; }
      prev = v;
    }
    void prev;
    var out = order.map(function (k) { return byVal[k]; });
    out.sort(function (a, b) { return a.end - b.end; });
    return out;
  }
  // '#rrggbb' → 'rgba(r,g,b,a)' (옛 단계는 옅게)
  function fadeColor(c, a) {
    var m = /^#([0-9a-f]{6})$/i.exec(String(c || ''));
    if (!m) return c;
    var n = parseInt(m[1], 16);
    return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')';
  }

  /* ════════════════════════════════════════════════════════════════════════
   * create(host, opts) — 차트 인스턴스
   * ══════════════════════════════════════════════════════════════════════ */
  function create(host, opts) {
    if (typeof host === 'string') host = document.getElementById(host);
    opts = opts || {};
    if (!host || !window.LightweightCharts) return null;   // load() 뒤에 부를 것

    injectCss();
    var LWC = window.LightweightCharts;
    var TH  = themeOf(opts.theme);
    if (opts.volAlpha) TH.volAlpha = opts.volAlpha;
    var kind = opts.kind || 'day';

    /* ── 차트 높이 — 화면마다 «사용자가 끌어서» 정하고, 그 화면이 기억한다 ──
     * hkey  = 높이를 기억하는 단위 = 화면(ChartFeat 의 screen). 차트틀 키(opts.key)와 다르다 —
     *         보유종목 상세와 종목추가 사다리는 차트틀은 공유하지만(position) 화면은 다르니까.
     * hTarget = 실제로 늘어나는 요소. 기본은 host 지만, 단타처럼 flex 형제와 자리를 나눠 갖는
     *         배치에서는 페이지가 부모 블록을 지정한다(opts.resizeTarget) — host 에 높이를 박으면
     *         flex 가 무시해서 「끌리지 않는 손잡이」가 된다.
     * 저장값은 서버가 페이지에 심어 준다(DC_VIEW) — 비동기로 받으면 화면이 한 번 튄다. */
    // screen:'' 를 명시하면 「끌 수는 있되 기억은 안 한다」 (갤러리 미리보기처럼 한 화면에 여럿일 때)
    var hkey = (opts.screen !== undefined) ? String(opts.screen)
             : (typeof window.DC_SCREEN === 'string' ? window.DC_SCREEN : '');
    var hTarget = opts.resizeTarget
      ? (typeof opts.resizeTarget === 'string' ? host.closest(opts.resizeTarget) : opts.resizeTarget)
      : host;
    if (!hTarget) hTarget = host;
    var H_MIN = 140, H_MAX = 1400;
    function applyHeight(h, fit) {
      h = Math.max(H_MIN, Math.min(H_MAX, Math.round(h)));
      if (hTarget === host) { host.style.height = h + 'px'; opts.height = h; }
      else hTarget.style.flex = '0 0 ' + h + 'px';        // 형제(분봉)가 나머지를 가져간다
      if (fit) onResize();
      return h;
    }
    // 페이지가 원래 정해 둔 높이 — 저장값을 얹기 «전에» 기억해 둔다 (두 번 누르면 여기로 돌아온다)
    var pageH = opts.height || (hTarget === host ? host.clientHeight : hTarget.clientHeight) || 0;
    var savedH = (window.DC_VIEW && hkey && +window.DC_VIEW.h) ? +window.DC_VIEW.h : 0;
    if (savedH && opts.resize !== false) applyHeight(savedH, false);   // 그리기 전에 — 튐 없이

    var chartOpt = {
      width: host.clientWidth, height: opts.height || host.clientHeight,
      layout: { background: { color: TH.bg }, textColor: TH.text, fontFamily: 'Pretendard' },
      grid: { vertLines: { color: TH.gridV }, horzLines: { color: TH.gridH } },
      rightPriceScale: { borderColor: TH.border },
      crosshair: { mode: LWC.CrosshairMode.Normal },
      localization: {
        timeFormatter: kind === 'minute' ? (opts.multiDay ? fmtDHM : fmtHM) : fmtKDate,
        priceFormatter: function (v) { return Math.round(v).toLocaleString(); }
      },
      /* ★시간축을 «데이터 안»에 가둔다 (2026-08-03).
       *   fixRightEdge — 최신 봉이 항상 오른쪽 끝. 휠로 줌아웃하면 왼쪽(과거)만 늘어난다.
       *                  이게 없으면 커서 기준으로 벌어져 오른쪽에 빈 자리가 생긴다(실측).
       *   fixLeftEdge  — 첫 봉보다 더 왼쪽으로는 못 간다. 전부 보이면 줌아웃이 거기서 멎는다.
       *   HTS 와 같은 감각 — 데이터가 있는 만큼만 움직이고 빈 여백은 만들지 않는다. */
      timeScale: kind === 'minute'
        ? { borderColor: TH.border, timeVisible: true,  secondsVisible: false,
            tickMarkFormatter: opts.multiDay ? fmtHMD : fmtHM,
            fixLeftEdge: true, fixRightEdge: true, rightOffset: 0 }
        : { borderColor: TH.border, timeVisible: false, secondsVisible: false, tickMarkFormatter: fmtTick,
            fixLeftEdge: true, fixRightEdge: true, rightOffset: 0 }
    };
    var chart = LWC.createChart(host, chartOpt);

    /* ── 내부 상태 ── */
    var self = {
      chart: chart, host: host,
      _bars: [],            // 일봉 원본 (전체 — 화면 슬라이스와 무관)
      /* 시간축 = «변수 세트·차트틀을 고르는 키»이기도 하다.
       * 분봉은 처음부터 'min' 으로 산다 — 주기가 달라 240봉·120봉 같은 값을 일봉과 나눠 써야 하고,
       * 저장한 차트틀도 섞이면 안 된다(일봉 목록에 분봉 틀이 뜨는 일). setTf 는 여기에 안 온다. */
      _tf: (opts.kind === 'minute') ? 'min' : 'day',
      _viewDays: null,      // n 이면 마지막 n 봉만 표시 (신호 계산은 전체 기준)
      _pinnedRange: null,   // zoomRange 로 잡은 과거 구간 — go() 재적용 때도 이 값으로 되돌아온다
      _main: null,          // 캔들 or 라인(폴백) 시리즈
      _mainIsCandle: null,
      _vol: null,
      _plines: [],          // [{price,color,style,width,axisLabel}]
      _plineObjs: [],
      _extra: [],           // 추가 선 [{opt, raw, series}]
      _marks: [],           // [{time,sell,text,state}] — 화면이 얹는 마커(체결 등)
      _sueMarks: [],        // SUE 공시 마커 — 모듈이 스스로 받아 얹는 층 (setCode 참조)
      _sueOn: true, _sueCode: '', _sueRaw: [], _sueFit: 0,   // _sueFit = 실린 봉 안에 «자리가 있는» 공시 수
      _mkLayer: null, _mkVisible: 0,
      _th: null,            // 당일전고선 상태 {obj, show}
      _curPriceOn: false,
      _inds: [],            // 적용된 사용자 지표 [{def, vars}]
      _indSeries: [],       // 지표 선 시리즈들
      _indMarks: [],        // 지표 점 마커들 (applyMarkers 가 체결 마커와 병합)
      _indLast: [],         // 지표별 마지막 값 [{name,color,last}] — 범례용
      _tfCbs: [],           // 시간축 변경 구독자 (지표 바가 축별 세트를 갈아끼운다)
      key: opts.key || ''   // 화면 식별자 (차트틀 기억용)
    };

    /* ── 가격축 채우기 — 「보이는 봉」 기준 위 +10% · 아래 −10% ──
     * 라이브러리 기본은 ⒜구간 폭에 비례한 여백(위 20%·아래 10%)을 더하고 ⒝가격선·지표선까지
     * 스케일에 넣는다. 그래서 최고가 79만인 차트의 축이 95만까지 벌어져 캔들이 납작해졌다(실측).
     * 여기서는 봉의 고·저만으로 범위를 정하고, 여백은 «가격의 %»로 준다 — 구간을 넓혀도 비율이 같다.
     * ★가격선(누적단가·자동매도가·다음매수가)은 범위를 넓혀서라도 포함한다 — 「내가 산 자리」가
     *   화면에서 사라지면 안 된다. 반대로 지표선은 제외한다(옛 레벨 하나 때문에 캔들이 눌린다).
     */
    var PAD = (opts.padPct === undefined) ? 0.10 : +opts.padPct;
    function barScale(original) {
      var fb = fullBars();
      if (!fb.length) return original ? original() : null;
      var from = 0, to = fb.length - 1, r = null;
      try { r = chart.timeScale().getVisibleLogicalRange(); } catch (e) { r = null; }
      if (r) {
        from = Math.max(0, Math.floor(r.from));
        to   = Math.min(fb.length - 1, Math.ceil(r.to));
      }
      if (to < from) return original ? original() : null;
      var lo = Infinity, hi = -Infinity;
      for (var i = from; i <= to; i++) {
        var b = fb[i];
        if (!b) continue;
        var l = (b.low  === null || b.low  === undefined) ? b.close : b.low;
        var h = (b.high === null || b.high === undefined) ? b.close : b.high;
        if (l !== null && l !== undefined && l < lo) lo = l;
        if (h !== null && h !== undefined && h > hi) hi = h;
      }
      if (!isFinite(lo) || !isFinite(hi) || hi <= 0) return original ? original() : null;
      var B = lo * (1 - PAD), T = hi * (1 + PAD);      // 화면 «맨 아래·맨 위»에 오길 바라는 값
      self._plines.forEach(function (L) {
        var v = +L.price;
        if (!isFinite(v)) return;
        if (v < B) B = v * (1 - PAD / 2);
        if (v > T) T = v * (1 + PAD / 2);
      });
      /* 직전고가선은 «켜져 있을 때만» 축에 포함한다 — 사용자가 켠 기준선이 화면 밖에 있으면
       * 「켰는데 아무 일도 안 일어나는」 스위치가 된다. (지표선은 반대로 제외한다 —
       *  멀리 있는 레벨 하나가 캔들을 납작하게 눌러선 안 되기 때문. 저건 안 켰는데 생기는 선이다.) */
      if (self._pv && self._pv.show && isFinite(self._pv.val)) {
        if (self._pv.val > T) T = self._pv.val * (1 + PAD / 2);
        if (self._pv.val < B) B = self._pv.val * (1 - PAD / 2);
      }
      /* 라이브러리는 여기서 돌려준 범위에 scaleMargins(비율 여백)를 «또» 더해서 그린다.
       * 그대로 두면 +10% 가 +12%,+15% 로 불어난다 — 여백만큼 미리 빼서 돌려준다.
       * 그러면 화면 위·아래 끝이 정확히 최고가+10% · 최저가−10% 가 된다. */
      var V = T - B;
      return { priceRange: { minValue: B + MB * V, maxValue: T - MT * V } };
    }

    /* ⊗ 거래량 축에서 단일가 봉(09:00·15:30)을 빼는 시도가 여기 있었다 — 2026-08-05 원복.
     *   대형주는 단일가 한 봉이 축을 다 먹어 나머지가 바닥에 붙는데, 그 처리를 «차트»가 아니라
     *   «사용자 지표(수식)»에서 하기로 했다(사용자 판단). 차트는 원장을 있는 그대로 그린다. */
    /* 라이브러리 비율 여백 — 아래를 조금 남기는 이유는 거래량 막대(별도 축)가 바닥을 쓰기 때문.
     * 이 값은 barScale 이 되빼므로 «가격축 눈금»에는 영향이 없다(위 +10%·아래 −10% 그대로). */
    var MT = 0.02, MB = (opts.volume === false) ? 0.02 : 0.06;
    chart.priceScale('right').applyOptions({ scaleMargins: { top: MT, bottom: MB } });

    /* ── 메인 시리즈 — 시·고·저가 하나도 없으면 선 폴백(sim 의 옛 종가전용 데이터) ── */
    function ensureMain(wantCandle) {
      if (self._main && self._mainIsCandle === wantCandle) return;
      if (self._main) chart.removeSeries(self._main);
      if (wantCandle) {
        self._main = chart.addCandlestickSeries({
          upColor: TH.up, downColor: TH.down,
          borderUpColor: TH.up, borderDownColor: TH.down,
          wickUpColor: TH.up, wickDownColor: TH.down,
          priceLineVisible: false,
          autoscaleInfoProvider: barScale        // 보이는 봉 ±10% 로 축을 꽉 채운다
        });
        if (opts.curPrice) {
          self._main.applyOptions({
            priceLineVisible: self._curPriceOn, priceLineStyle: LWC.LineStyle.Solid,
            priceLineColor: opts.curPrice, priceLineWidth: 1
          });
        }
      } else {
        self._main = chart.addLineSeries({ color: '#22303f', lineWidth: 2, priceLineVisible: false,
                                          autoscaleInfoProvider: barScale });
      }
      self._mainIsCandle = wantCandle;
      self._plineObjs = [];          // 시리즈가 바뀌면 가격선도 다시 그려야 한다
      if (self._th) self._th.obj = null;   // 시리즈가 바뀌면 당일전고선도 다시 그린다
      if (self._pv) self._pv.obj = null;   // 직전고가선도 마찬가지
    }

    if (opts.volume !== false) {
      self._vol = chart.addHistogramSeries({
        priceFormat: { type: 'volume' }, priceScaleId: 'vol',
        priceLineVisible: false, lastValueVisible: false
      });
      chart.priceScale('vol').applyOptions({ scaleMargins: { top: TH.volTop, bottom: 0 } });

      /* ── 거래량 칸 위에서만 뜨는 «거래대금» 표시 ──
       * 막대 높이는 거래량인데 정작 궁금한 건 «얼마어치»인 경우가 많다(단타에서 특히).
       * 상시 범례로 두지 않은 이유: 차트가 낮은 3분할 화면에서 한 줄이 아깝고, 이 값은
       * 「이 봉 얼마였지」를 물을 때만 필요하다 — 물을 때만 답한다.
       * ★실제 거래대금(krx_amt)이 없는 봉은 종가×거래량 근사라 «≈»를 붙여 구분한다.
       *   분봉은 원장에 거래대금이 없어 언제나 근사다(중앙 0.99% 오차 · 실측 2026-07-31). */
      var amtEl = null;
      function hideAmt() { if (amtEl) amtEl.style.display = 'none'; }
      function showAmt(p) {
        if (!feats('overlay.amt_hover')) return hideAmt();
        if (!p || !p.point || p.time === undefined || p.time === null) return hideAmt();
        /* 거래량 칸(아래 band) 밖이면 안 띄운다 — 캔들 위에서는 조용하다.
         * ★기준은 host 높이가 아니라 «거래량 축의 바닥»이다. host 에는 시간축(약 28px)이
         *   포함돼 있어 그걸로 재면 막대 윗부분이 판정에서 빠진다. */
        var vb = self._vol.priceToCoordinate(0);
        if (vb === null || vb === undefined) vb = host.clientHeight;
        if (p.point.y < vb * TH.volTop) return hideAmt();
        var b = barByTime(p.time);
        if (!b) return hideAmt();
        var v = +b.vol || 0;
        var real = (b.amt !== undefined && b.amt !== null);
        var amt = real ? +b.amt : (+b.close || 0) * v;
        if (!isFinite(amt) || amt <= 0) return hideAmt();
        if (!amtEl) {
          anchorHost();
          amtEl = document.createElement('div');
          amtEl.className = 'dc-amt' + (opts.theme === 'dark' ? '' : ' light');
          host.appendChild(amtEl);
        }
        amtEl.innerHTML = '';
        amtEl.appendChild(document.createTextNode((real ? '' : '≈') + eokText(amt)));
        var sm = document.createElement('small');
        sm.textContent = v.toLocaleString() + '주';
        amtEl.appendChild(sm);
        amtEl.style.display = 'block';
        var w = amtEl.offsetWidth, W = host.clientWidth;
        amtEl.style.left = Math.max(w / 2 + 2, Math.min(W - w / 2 - 2, p.point.x)) + 'px';
        amtEl.style.top  = Math.max(amtEl.offsetHeight + 2, p.point.y - 6) + 'px';
      }
      chart.subscribeCrosshairMove(showAmt);
      host.addEventListener('mouseleave', hideAmt);
    }

    /* ── 현재 표시용 봉 (주봉 접기 → 기간 슬라이스) ──
     * viewDays 는 <b>현재 시간축의 봉 수</b>다 — 일봉이면 160일, 주봉이면 24주.
     * 그래서 접기를 먼저 하고 나서 자른다. */
    function fullBars()  { return self._tf === 'week' ? resampleWeek(self._bars) : self._bars; }
    /* 십자선이 가리키는 봉 — 시간 표현이 축마다 다르다(분봉=초 단위 숫자 · 일봉=날짜).
     * 그래서 키로 바꿔 맞춘다. 색인은 그릴 때마다 버린다(종목이 바뀌면 같은 길이여도 다른 봉이다). */
    function timeKey(t) {
      if (typeof t === 'number') return 'n' + t;
      var d = ymd(t);
      return d ? ('d' + d[0] + '-' + d[1] + '-' + d[2]) : ('s' + String(t));
    }
    function barByTime(t) {
      if (!self._barIdx) {
        var m = {}, fb = fullBars();
        for (var i = 0; i < fb.length; i++) m[timeKey(fb[i].time)] = fb[i];
        self._barIdx = m;
      }
      return self._barIdx[timeKey(t)] || null;
    }
    function viewBars()  {
      var b = fullBars();
      return self._viewDays ? b.slice(-self._viewDays) : b;
    }
    // 일봉 날짜 → 주봉일 때 그 날이 속한 봉의 time (마커 스냅용). 데이터에 그 주가 없으면 null
    function snapTime(t) {
      if (self._tf !== 'week' || typeof t !== 'string') return t;
      var mk = mondayOf(t);
      var wb = fullBars();
      for (var i = 0; i < wb.length; i++) {
        if (mondayOf(wb[i].time) === mk) return wb[i].time;
      }
      return null;
    }

    /* ── 가격선 (누적단가·자동매도가·다음매수가 류 — 정적) ── */
    var STYLE_MAP = { solid: LWC.LineStyle.Solid, dashed: LWC.LineStyle.Dashed, dotted: LWC.LineStyle.Dotted,
                      ldashed: LWC.LineStyle.LargeDashed, sdotted: LWC.LineStyle.SparseDotted };
    function applyPriceLines() {
      if (!self._main) return;
      self._plineObjs.forEach(function (o) { self._main.removePriceLine(o); });
      self._plineObjs = self._plines.map(function (L) {
        return self._main.createPriceLine({
          price: L.price, color: L.color, lineWidth: L.width || 2,
          lineStyle: STYLE_MAP[L.style || 'solid'],
          axisLabelVisible: L.axisLabel !== false
        });
      });
    }

    /* host 를 «위치 기준»으로 못박는다.
     * 이 아래 층들(마커 칩·박스·높이 손잡이)은 전부 position:absolute + inset:0 이라, host 가
     * static 이면 기준이 «더 바깥의 positioned 조상»으로 새 나간다 — 그러면 층의 overflow:hidden 도
     * 그 바깥 상자에서 잘리므로 칩이 «남의 패널 위»에 그려진다.
     * ★실제로 그랬다(2026-08-04, 단타 3분할): 높이 손잡이 분기 안에만 이 한 줄이 있어서
     *   resize:false 인 차트는 못 박히지 않았고, 일봉의 SUE 칩이 위쪽 분봉 패널에 떠올랐다. */
    function anchorHost() {
      if (getComputedStyle(host).position === 'static') host.style.position = 'relative';
    }

    /* ── 체결 마커 (화살표 = 라이브러리 / 글자 = HTML 칩 + 충돌회피) ── */
    var rafId = 0;
    function drawMarkChips() {
      if (!self._mkLayer) return;
      self._mkLayer.textContent = '';
      var data = self._mkChipData || [];
      if (!data.length) return;
      var ts = chart.timeScale(), w = host.clientWidth;
      var placed = { 0: [], 1: [] };
      data.forEach(function (m) {
        var x = ts.timeToCoordinate(m.time);
        var y = self._main.priceToCoordinate(m.sell ? m.hi : m.lo);
        if (x === null || y === null) return;
        var wide = Math.max(m.text.length, (m.state || '').length);
        var half = wide * 3.6 + 9;
        var tall = m.state ? 30 : 19;
        if (x + half < 0 || x - half > w) return;
        var side = m.sell ? 1 : 0;
        var step = m.sell ? -(tall + 6) : (tall + 6);
        var top  = y + (m.sell ? -(tall + 12) : 13);
        for (var i = 0; i < 5; i++) {
          var hit = placed[side].some(function (p) {
            return Math.abs(p.x - x) < (p.half + half) && Math.abs(p.top - top) < Math.max(p.tall, tall);
          });
          if (!hit) break;
          top += step;
        }
        placed[side].push({ x: x, half: half, top: top, tall: tall });
        var el = document.createElement('div');
        el.className = 'dc-mk ' + (m.sell ? 'sell' : 'buy');
        el.style.left = x + 'px';
        el.style.top  = top + 'px';
        var l1 = document.createElement('span');
        l1.className = 'mk-l1'; l1.textContent = m.text;
        el.appendChild(l1);
        if (m.state) {
          var l2 = document.createElement('span');
          l2.className = 'mk-l2'; l2.textContent = m.state;
          el.appendChild(l2);
        }
        self._mkLayer.appendChild(el);
      });
    }

    function applyMarkers() {
      if (!self._main) return;
      var fb = fullBars();   // 데이터 전체가 실려 있으므로 마커도 전체 — 스크롤로 과거를 봐도 체결이 보인다
      // 화면이 얹은 마커 + SUE 공시 층. 층이 여럿이어도 라이브러리엔 시간순 한 목록으로 넘긴다
      var marks = self._sueMarks.length            // 칩 겹침 회피가 시간순을 전제로 한다 → 합치면 다시 정렬
        ? self._marks.concat(self._sueMarks).sort(function (a, b) {
            return a.time < b.time ? -1 : a.time > b.time ? 1 : 0;
          })
        : self._marks;
      if (!fb.length || (!marks.length && !self._indMarks.length)) {
        self._main.setMarkers([]);
        self._mkChipData = [];
        self._mkVisible = 0;
        if (self._mkLayer) self._mkLayer.textContent = '';
        return;
      }
      // 데이터보다 오래된 체결(4년 넘은 것)만 거른다 — 시리즈에 없는 시간은 첫 봉에 오붙는다
      var from = fb[0].time, to = fb[fb.length - 1].time;
      var byTime = {};
      fb.forEach(function (b) { byTime[b.time] = b; });

      /* 칩(HTML 말풍선) vs 라이브러리 글자 — 화면 기본값은 opts.markers.chips 가 정하지만
       * 마커 하나가 m.chip 으로 뒤집을 수 있다. SUE 공시 마커가 그 경우다:
       * 「어느 화면에서 봐도 같은 배지」여야 해서 시뮬레이터(chips:false)에서도 칩으로 그린다. */
      var chips = (opts.markers && opts.markers.chips) !== false;
      var lib = [], chipData = [], pageSeen = 0;
      marks.forEach(function (m) {
        var t = snapTime(m.time);
        if (t === null || t < from || t > to) return;
        // 화면이 얹은 마커만 센다 — 「체결 마커 N개」 같은 문구가 SUE 층 때문에 부풀면 안 된다
        if (self._sueMarks.indexOf(m) < 0) pageSeen++;
        var useChip = (m.chip === undefined) ? chips : !!m.chip;
        lib.push({
          time: t,
          position: m.sell ? 'aboveBar' : 'belowBar',
          color: m.sell ? TH.down : TH.up,
          shape: m.sell ? 'arrowDown' : 'arrowUp',
          text: useChip ? '' : (m.text || '')
        });
        var b = byTime[t];
        if (useChip && b && b.high !== undefined) {
          chipData.push({ time: t, text: m.text, state: m.state || '', sell: m.sell,
                          hi: b.high === null ? b.close : b.high,
                          lo: b.low  === null ? b.close : b.low });
        }
      });
      // 사용자 지표 점 — 체결 마커와 한 목록으로 (setMarkers 는 시간 오름차순 하나만 받는다)
      self._indMarks.forEach(function (m) {
        if (m.time < from || m.time > to) return;
        lib.push(m);
      });
      lib.sort(function (a, b) { return a.time < b.time ? -1 : a.time > b.time ? 1 : 0; });
      self._main.setMarkers(lib);
      self._mkVisible = pageSeen;
      self._mkChipData = chipData;
      // 칩이 하나라도 있으면 층을 만든다 (화면 기본이 「글자」여도 SUE 배지는 칩이다)
      if (chipData.length && !self._mkLayer) {
        self._mkLayer = document.createElement('div');
        self._mkLayer.className = 'dc-mk-layer';
        anchorHost();
        host.appendChild(self._mkLayer);
      }
      drawMarkChips();
    }

    /* ── 사용자 지표 렌더 — 선은 시리즈로, 점은 _indMarks 로 (applyMarkers 가 병합) ── */
    /* 지표 값 범례 — 화면의 가격선 범례(누적단가·자동매도가…) 옆에 지표별 마지막 값을 붙인다.
     * opts.legend 로 컨테이너를 주면 모듈이 그 안의 자기 영역만 갱신한다(기존 항목은 건드리지 않는다). */
    var legendBox = null;
    function updateIndLegend() {
      if (!opts.legend) return;
      var host2 = (typeof opts.legend === 'string') ? document.getElementById(opts.legend) : opts.legend;
      if (!host2) return;
      if (!legendBox) {
        legendBox = document.createElement('span');
        legendBox.className = 'dc-ind-legend';
        host2.appendChild(legendBox);
      }
      legendBox.textContent = '';
      self._indLast.forEach(function (L) {
        if (L.last === null || L.last === undefined) return;
        var el = document.createElement('span');
        el.className = 'cl-item dc-ilg';
        var i = document.createElement('i');
        i.style.background = L.color;
        el.appendChild(i);
        el.appendChild(document.createTextNode(L.name + ' '));
        var b = document.createElement('b');
        b.textContent = Math.round(L.last).toLocaleString();
        el.appendChild(b);
        legendBox.appendChild(el);
      });
    }

    /* ── 옛 단계 오른쪽 연장 (계단선 전용) ──
     * 지나간 단계 값은 그대로 두면 사라지지만 실제로는 지지·저항으로 계속 작동한다.
     * 그 «생긴 자리»에서 오른쪽 끝까지 옅은 점선으로 늘려 준다 (최근 pt.ext 개).
     * ★가격축 스케일에는 참여시키지 않는다 — 화면 밖 옛 레벨 때문에 캔들이 납작해지면 안 된다. */
    function drawStepExtends(pt, vals, fb) {
      var lv = stepLevels(vals);
      if (lv.length < 2) return;
      lv.slice(Math.max(0, lv.length - 1 - pt.ext), lv.length - 1).forEach(function (L) {
        var from = L.start;   // 그 선이 생긴 자리부터 — 전체 데이터가 실려 있으니 창 클램프 불필요
        if (from >= fb.length) return;
        var s = chart.addLineSeries({
          color: fadeColor(pt.color, 0.5), lineWidth: pt.exw || 1,
          lineStyle: STYLE_MAP[pt.exs] === undefined ? LWC.LineStyle.Dashed : STYLE_MAP[pt.exs],
          priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false,
          autoscaleInfoProvider: function () { return null; }
        });
        var pairs = [];
        for (var i = from; i < fb.length; i++) pairs.push({ time: fb[i].time, value: L.value });
        s.setData(pairs);
        self._indSeries.push(s);
      });
    }

    function renderIndicators() {
      self._indSeries.forEach(function (s) { chart.removeSeries(s); });
      self._indSeries = [];
      self._indMarks = [];
      self._indLast = [];
      if (!self._inds.length) { updateIndLegend(); return; }
      var fb = fullBars();
      if (!fb.length) { updateIndLegend(); return; }
      self._inds.forEach(function (ap) {
        // 이 축에서 안 보이기로 한 지표 — 계산도 하지 않는다 (범례·점 마커에도 안 남는다)
        if (!indTfOk(ap.def, self._tf)) return;
        var parts;
        try {
          // 전체 봉 기준 계산(워밍업 정확) + 현재 시간축의 변수 세트(일/주/월 다르게 설정 가능)
          parts = evalIndicatorMulti(fb, ap.def, ap.vars, self._tf);
        } catch (e) {
          /* ★조용히 넘기지 않는다 — 「선이 하나도 안 그려짐」으로만 보여 원인을 못 찾는다
           * (실제로 변수 하나가 빠진 채 한참 헤맸다). 렌더는 휠·창 이동마다 도니
           * 같은 오류는 한 번만 알린다 — 지표 이름+메시지로 묶는다. */
          console.error('[지표 ' + ap.def.name + ']', e);
          var ek = (ap.def.name || '') + '|' + (e && e.message);
          if (!_indErrSeen[ek]) {
            _indErrSeen[ek] = 1;
            alert('지표 「' + (ap.def.name || '이름 없음') + '」 를 그리지 못했습니다.\n\n'
                + (e && e.message) + '\n\n(차트설정 > 지표 관리에서 변수·수식을 확인하세요)');
          }
          return;
        }
        parts.forEach(function (pt) {
          // 칩 모달의 「연장」 즉석 조정 — 지표 정의는 그대로 두고 이 화면 적용분에만 얹는다 (변수 override 와 같은 결)
          if (ap.exts && ap.exts[pt.si] !== undefined && isFinite(+ap.exts[pt.si])) {
            pt.ext = Math.max(0, Math.min(10, Math.floor(+ap.exts[pt.si])));
          }
          var vals = pt.vals;
          // 범례용 마지막 유효값 (지표 이름 + 선 이름)
          var lastV = null;
          for (var li = vals.length - 1; li >= 0; li--) {
            if (vals[li] !== null && vals[li] !== undefined && isFinite(vals[li])) { lastV = vals[li]; break; }
          }
          self._indLast.push({
            // 이름의 #토큰 → 지금 축의 변수 값 (예: 'H선(#1)' → 'H선(120)')
            name: indLabel(pt.name || ap.def.name, ap.def, ap.vars, self._tf),
            color: pt.color, last: lastV
          });
          if (ap.def.draw === 'point') {
            for (var i = 0; i < fb.length; i++) {
              var v = vals[i];
              if (v === null || v === undefined || v === 0 || !isFinite(v)) continue;
              self._indMarks.push({
                time: fb[i].time, position: 'belowBar',
                color: pt.color, shape: 'circle', text: ''
              });
            }
          } else {
            var s = chart.addLineSeries({
              color: pt.color, lineWidth: pt.w || 2,
              lineStyle: STYLE_MAP[pt.st] === undefined ? LWC.LineStyle.Solid : STYLE_MAP[pt.st],
              priceLineVisible: false, lastValueVisible: false,
              // ★가격축은 봉이 정한다 — 멀리 있는 지표 레벨 하나가 캔들을 납작하게 눌러선 안 된다
              autoscaleInfoProvider: function () { return null; }
            });
            var pairs = fb.map(function (b, i) {
              var v = vals[i];
              return (v === null || v === undefined || !isFinite(v))
                ? { time: b.time } : { time: b.time, value: v };
            });
            // 캔들도 전체가 실려 있으므로 지표도 전체 — 스크롤로 과거를 봐도 선이 이어진다
            s.setData(pairs);
            self._indSeries.push(s);
            if (pt.ext > 0) drawStepExtends(pt, vals, fb);
          }
        });
      });
      updateIndLegend();
    }

    /* ── 당일전고선 — 최신봉 제외 직전 60봉 최고가. 관찰용 기준선(전략 아님) ── */
    function thState() {
      if (!self._th) self._th = { obj: null, show: true };
      return self._th;
    }
    function drawTodayHigh() {
      var S = self._th;
      if (!S || !self._main) return;
      if (S.obj) { self._main.removePriceLine(S.obj); S.obj = null; }
      if (!S.show) return;
      var fb = fullBars(), n = fb.length;
      if (n < 2) return;
      var ph = -Infinity, k;
      if (opts.todayHigh === 'session' && typeof fb[n - 1].time === 'number') {
        /* 분봉용 — 「당일전고」를 글자 그대로 «오늘 고가»(직전 봉까지)로 잡는다.
         * 일봉의 60봉 전고를 1분봉에 그대로 쓰면 「직전 60분 최고가」가 되어 이름이 거짓이 된다.
         * 분봉 time 은 KST 벽시계를 UTC 로 취급한 초 단위라, 86400 으로 나누면 그대로 KST 날짜다. */
        var day0 = Math.floor(fb[n - 1].time / 86400);
        for (k = n - 2; k >= 0 && Math.floor(fb[k].time / 86400) === day0; k--) {
          if (fb[k].high > ph) ph = fb[k].high;
        }
      } else {
        for (k = Math.max(0, n - 1 - 60); k < n - 1; k++) if (fb[k].high > ph) ph = fb[k].high;
      }
      if (ph <= -Infinity) return;
      S.obj = self._main.createPriceLine({
        price: ph, color: '#22d3ee', lineWidth: 1,
        lineStyle: LWC.LineStyle.Solid, axisLabelVisible: false
      });
    }
    function applyTodayHigh() {
      if (!opts.todayHigh) return;
      thState();
      drawTodayHigh();
    }

    /* ── 직전고가선 — «오늘을 뺀» 실린 봉 전부의 최고가. 관찰용 저항 기준선 ──
     * 「오늘 이 선을 뚫었나」가 이 선을 보는 이유다. 그래서 오늘 봉은 계산에서 뺀다 —
     * 넣으면 장중 신고가를 낼 때마다 선이 스스로 따라 올라가 영영 안 뚫린다.
     * ★보이는 창이 아니라 실린 전부를 본다. 창을 따라가면 휠을 굴릴 때마다 「최고가」가 달라져
     *   기준선 구실을 못 한다(그건 그냥 축 눈금이다).
     * ★색으로 소유자를 가른다 — 현재가(#d9a441 얇은 실선)·지표와 겹치지 않는 <b>빨간 굵은 실선</b>.
     *   캔들의 양봉색(#e8493f)보다 채도가 높은 순빨강이라 3px 실선이면 헷갈리지 않는다.
     * ★가격축 눈금에 값을 띄운다(axisLabelVisible) — 얼마인지 따로 적을 자리를 만들지 않는다. */
    function pvState() {
      if (!self._pv) self._pv = { obj: null, show: false, val: NaN };
      return self._pv;
    }
    /* 축을 «다시 재게» 만든다 — 이 한 줄을 찾는 데 탐침을 세 번 돌렸다(2026-08-05 실측).
     * 라이브러리(LWC 4.1.3)는 autoscale 결과를 «데이터+보이는 범위»에 걸어 캐시한다. 그래서
     *   ⊗ 시리즈 applyOptions (같은 참조든 새 껍데기든)   ⊗ priceScale autoScale false→true
     *   ⊗ createPriceLine / removePriceLine              — 넷 다 축을 안 움직인다.
     *   ○ setData 는 되지만 <b>마커를 지운다</b>(체결·SUE·지표 점이 통째로 날아간다).
     *   ◎ update(마지막 봉) 은 캐시를 깨면서 마커를 지키지 않는다 — 실측으로 마커 1개 생존 확인.
     * 그래서 «있는 그대로의 마지막 봉»을 한 번 다시 넣는다. 값이 같으니 그림은 안 바뀌고
     * 축만 다시 계산된다. */
    function pvRescale() {
      if (!self._main) return;
      var fb = fullBars();
      if (!fb.length) return;
      try { self._main.update(mainPoint(fb[fb.length - 1])); } catch (e) {}
    }
    /* 「같은 날인가」 — 분봉은 초 단위(하루 86400), 일봉은 'YYYY-MM-DD' 문자열이다.
       일봉은 봉 하나가 곧 하루라 마지막 봉만 빼면 된다. */
    function sameDay(b, last) {
      if (typeof last.time === 'number') return Math.floor(b.time / 86400) === Math.floor(last.time / 86400);
      return b.time === last.time;
    }
    function drawPrevHigh() {
      var S = self._pv;
      if (!S || !self._main) return;
      if (S.obj) { self._main.removePriceLine(S.obj); S.obj = null; }
      var fb = fullBars(), hi = -Infinity;
      if (fb.length) {
        var last = fb[fb.length - 1];
        for (var i = 0; i < fb.length; i++) {
          if (sameDay(fb[i], last)) continue;          // ★오늘은 뺀다
          var h = fb[i].high;
          if (h !== null && h !== undefined && h > hi) hi = h;
        }
      }
      // 실린 봉이 오늘치뿐이면(미리보기) 직전이 없다 — 값도 선도 없다
      S.val = isFinite(hi) ? hi : NaN;
      if (S.show && isFinite(S.val)) {
        // 굵은 실선 — 이 화면에서 가장 자주 보는 선이라 캔들 사이에서 한눈에 잡혀야 한다
        S.obj = self._main.createPriceLine({
          price: S.val, color: (opts.prevHigh === true ? '#ff3b30' : opts.prevHigh),
          lineWidth: 3, lineStyle: LWC.LineStyle.Solid, axisLabelVisible: true, title: ''
        });
      }
      // ★끌 때도 다시 잰다 — 안 그러면 켜면서 넓어진 축이 «끈 뒤에도» 그대로 남는다(실측).
      pvRescale();
    }
    function applyPrevHigh() {
      if (!opts.prevHigh) return;
      pvState();
      drawPrevHigh();
    }

    /* ── 박스 오버레이 (신호일 H~L 사각형 — 패턴분석의 지지·저항 박스) ──
     * LWC 4.1.3 엔 사각형 프리미티브가 없다 → 칩과 같은 HTML 오버레이로 그린다.
     * 시리즈가 아니라서 가격축 autoscale 에 안 잡힌다(깊은 옛 박스가 축을 안 누른다). */
    function drawBoxes() {
      if (!self._bxLayer && !(self._boxes || []).length) return;
      if (!self._bxLayer) {
        self._bxLayer = document.createElement('div');
        self._bxLayer.className = 'dc-bx-layer';
        anchorHost();
        host.appendChild(self._bxLayer);
      }
      self._bxLayer.textContent = '';
      var list = self._boxes || [];
      if (!list.length || !self._main) return;
      var vb = fullBars();   // 창 밖으로 스크롤해도 박스가 보이게 — 전체 봉에서 스냅
      if (!vb.length) return;
      var ts = chart.timeScale(), hh = host.clientHeight;
      var vr = ts.getVisibleRange();
      if (!vr) return;
      // 화면 밖 시간은 좌표가 null → 보이는 범위로 클램프 후 범위 안의 실제 봉에 스냅
      function xAt(t) {
        if (t < vr.from) t = vr.from;
        if (t > vr.to) t = vr.to;
        for (var i = 0; i < vb.length; i++) if (vb[i].time >= t) return ts.timeToCoordinate(vb[i].time);
        return ts.timeToCoordinate(vb[vb.length - 1].time);
      }
      list.forEach(function (bx) {
        var to = (bx.to === undefined || bx.to === null) ? vr.to : bx.to;
        if (to < vr.from || bx.from > vr.to) return;
        var x1 = xAt(bx.from), x2 = xAt(to);
        var y1 = self._main.priceToCoordinate(bx.top), y2 = self._main.priceToCoordinate(bx.bottom);
        if (x1 === null || x2 === null || y1 === null || y2 === null) return;
        var top = Math.min(y1, y2), bot = Math.max(y1, y2);
        if (bot < 0 || top > hh || x2 <= x1) return;
        var el = document.createElement('div');
        el.className = 'dc-bx';
        el.style.left = x1 + 'px';
        el.style.width = Math.max(2, x2 - x1) + 'px';
        el.style.top = top + 'px';
        el.style.height = Math.max(2, bot - top) + 'px';
        if (bx.fill) el.style.background = bx.fill;
        if (bx.topColor) el.style.borderTop = (bx.topW || 2) + 'px solid ' + bx.topColor;
        if (bx.bottomColor) el.style.borderBottom = (bx.bottomW || 2) + 'px solid ' + bx.bottomColor;
        if (bx.sideColor) {
          el.style.borderLeft = '1px dashed ' + bx.sideColor;
          el.style.borderRight = '1px dashed ' + bx.sideColor;
        }
        if (bx.label) {
          var lb = document.createElement('span');
          lb.className = 'bx-lb';
          lb.textContent = bx.label;
          lb.style.color = bx.labelColor || bx.topColor || '#667';
          el.appendChild(lb);
        }
        self._bxLayer.appendChild(el);
      });
    }

    /* ── 오버레이 통합 리프레시 (스크롤·줌·리사이즈 추종) ── */
    function overlaysSoon() {
      if (rafId) return;
      rafId = requestAnimationFrame(function () {
        rafId = 0;
        drawMarkChips();
        drawBoxes();
      });
    }
    chart.timeScale().subscribeVisibleLogicalRangeChange(overlaysSoon);
    // 가격축 드래그로 배율이 바뀔 때는 범위 이벤트가 안 떠서 포인터로 잡는다
    ['mousemove', 'mouseup', 'wheel', 'touchmove', 'touchend'].forEach(function (ev) {
      host.addEventListener(ev, overlaysSoon, { passive: true });
    });

    /* ── 휠 줌·드래그 → 기간 선택 동기화 ──
     * HTS 방식이라 데이터는 전부 실려 있고 사용자는 창만 움직인다. 줌아웃하면 옛 봉이
     * 즉시 보이므로(빈 화면 없음) 여기서 할 일은 하나 — «지금 보이는 봉 수»를
     * 기간 바 숫자칸과 선택값(viewDays)에 따라 적는 것. 차트저장에도 이 값이 실린다. */
    var syncTimer = 0;
    chart.timeScale().subscribeVisibleLogicalRangeChange(function () {
      if (syncTimer) clearTimeout(syncTimer);
      syncTimer = setTimeout(function () {
        syncTimer = 0;
        var N = fullBars().length;
        if (!N) return;
        var r = null;
        try { r = chart.timeScale().getVisibleLogicalRange(); } catch (e) { r = null; }
        if (!r) return;
        // 화면에 실제로 보이는 «데이터 봉» 수 (데이터 밖 여백 제외)
        var vis = Math.round(Math.min(r.to, N - 1) - Math.max(r.from, 0) + 1);
        if (vis < 20) return;
        var cur = self._viewDays || N;
        if (Math.abs(vis - cur) < 3) return;             // 반올림·fitContent 여유는 소음
        self._viewDays = (vis >= N) ? null : vis;
        if (self._pbar && self._pbar.syncBars) self._pbar.syncBars(vis, self);
      }, 200);
    });
    function onResize() {
      // 전체화면일 때는 페이지가 정해 준 고정 높이(opts.height)를 무시하고 화면을 꽉 채운다
      chart.resize(host.clientWidth, self._fs ? host.clientHeight : (opts.height || host.clientHeight));
      overlaysSoon();
    }
    if (window.ResizeObserver) new ResizeObserver(onResize).observe(host);
    window.addEventListener('resize', onResize);

    /* ── 높이 조절 손잡이 — 차트 아래 가장자리를 끌면 높이가 바뀐다 ──
     * 놓는 순간 그 «화면»의 높이로 저장된다(chart_pref.view_json). 두 번 누르면 기본값으로.
     * 도구모음에 버튼을 늘리지 않는 이유: 높이는 눈으로 맞추는 값이라 끌어서 정하는 게 자연스럽다.
     * 손잡이가 없는 곳 = 화면 식별자가 없는 미니 차트(사례분석·패턴) — 거기선 페이지가 정한 높이 그대로. */
    var baseH = opts.baseHeight || pageH;      // 되돌리기 목표 = 페이지가 정한 높이
    if (opts.resize !== false && (hkey || opts.resize === true)) {
      anchorHost();
      var grip = document.createElement('div');
      grip.className = 'dc-rsz';
      grip.title = '끌어서 차트 높이 조절 · 두 번 누르면 기본 높이';
      var tip = document.createElement('span');
      grip.appendChild(tip);
      host.appendChild(grip);

      var dragging = false, startY = 0, startH = 0, saveT = null;
      function curH() { return hTarget === host ? host.clientHeight : hTarget.clientHeight; }
      function saveSoon(h) {
        if (!hkey) return;
        clearTimeout(saveT);
        saveT = setTimeout(function () {
          if (!window.DC_VIEW) window.DC_VIEW = {};
          window.DC_VIEW.h = h;
          apiPost('view_save', { chart_key: hkey, view: JSON.stringify({ h: h }) })
            .catch(function () {});
        }, 400);                         // 끌던 손이 멎은 뒤 한 번만 (드래그마다 쏘지 않는다)
      }
      grip.addEventListener('pointerdown', function (e) {
        if (self._fs) return;            // 전체화면에선 화면이 높이를 정한다
        dragging = true;
        startY = e.clientY;
        startH = curH();
        try { grip.setPointerCapture(e.pointerId); } catch (err) { /* 캡처는 있으면 좋은 것뿐 */ }
        grip.classList.add('on');
        e.preventDefault();
      });
      grip.addEventListener('pointermove', function (e) {
        if (!dragging) return;
        var h = applyHeight(startH + (e.clientY - startY), true);
        tip.textContent = h + 'px';
      });
      function endDrag() {
        if (!dragging) return;
        dragging = false;
        grip.classList.remove('on');
        tip.textContent = '';
        saveSoon(curH());
      }
      grip.addEventListener('pointerup', endDrag);
      grip.addEventListener('pointercancel', endDrag);
      // 두 번 누르면 페이지가 정한 기본 높이로 (저장도 지운다)
      grip.addEventListener('dblclick', function () {
        if (!baseH) return;
        applyHeight(baseH, true);
        if (hkey) {
          if (window.DC_VIEW) delete window.DC_VIEW.h;
          apiPost('view_save', { chart_key: hkey, view: JSON.stringify({}) }).catch(function () {});
        }
      });
    }

    /* ── 렌더 본체 ── */
    /* 선택된 기간(viewDays)을 «보이는 창»으로 적용 — HTS 방식.
     * 시리즈에는 전체 데이터가 실려 있으므로(아래 render), 기간 선택은 창 이동일 뿐이다.
     * 그래서 휠 줌인/줌아웃·과거 드래그가 라이브러리 기본 동작만으로 자연스럽다 —
     * 줌아웃하면 이미 실려 있는 옛 봉이 그냥 보인다 (빈 화면을 채우려 데이터를 만질 일 없음).
     * ★setData 직후엔 LWC 가 «같은 시간 구간 유지» 보정을 한 번 더 얹을 수 있다(실측)
     *   → 스택이 빈 뒤 같은 창을 다시 적용해 확정한다. */
    function applyWindow() {
      function go() {
        var N = fullBars().length;
        if (!N) return;
        var ts = chart.timeScale();
        if (self._viewDays && self._viewDays < N) {
          try { ts.setVisibleLogicalRange({ from: N - self._viewDays, to: N - 1 }); }
          catch (e) { ts.fitContent(); }
          return;
        }
        // 기간 버튼(viewDays)이 없을 땐 zoomRange 로 고정해 둔 과거 구간을 우선한다 —
        // 없으면(둘 다 null) 그제서야 전체 보기.
        if (self._pinnedRange) {
          try { ts.setVisibleRange(self._pinnedRange); return; } catch (e) { /* fall through */ }
        }
        ts.fitContent();
      }
      go();
      setTimeout(go, 0);
      setTimeout(go, 80);   // ★그 보정은 rAF 뒤에 와서 0ms 재적용도 질 때가 있다(실측) — 한 번 더
    }

    /* 봉 하나 → 메인 시리즈의 점. render(전체 적재)와 pvRescale(마지막 봉 재적용)이 나눠 쓴다 —
     * 두 곳이 다른 모양을 만들면 「재적용했더니 마지막 봉만 달라지는」 버그가 난다.
     * 거래정지봉(시·고·저가 null 또는 0)은 종가 도지로 — 봉이 0 까지 늘어나지 않게. */
    function mainPoint(b) {
      var c = b.close;
      if (!self._mainIsCandle) return { time: b.time, value: c };
      return {
        time: b.time,
        open:  (b.open  === null || b.open  === undefined || b.open  === 0) ? c : b.open,
        high:  (b.high  === null || b.high  === undefined || b.high  === 0) ? c : b.high,
        low:   (b.low   === null || b.low   === undefined || b.low   === 0) ? c : b.low,
        close: c
      };
    }

    function render(fit) {
      self._barIdx = null;   // 봉이 바뀌면 시간→봉 색인을 버린다 (거래대금 표시가 옛 봉을 가리키지 않게)
      var fb = fullBars();   // ★전체를 싣는다 — 기간은 applyWindow 가 «창»으로만 자른다 (HTS 방식)
      var hasOhlc = fb.some(function (b) {
        return b.open !== null && b.open !== undefined && b.open !== 0;
      });
      ensureMain(hasOhlc);
      self._main.setData(fb.map(mainPoint));   // 캔들/선 구분은 mainPoint 가 한다
      if (self._vol) {
        self._vol.setData(fb.filter(function (b) { return b.vol !== null && b.vol !== undefined; })
          .map(function (b) {
            var up = (b.open === null || b.open === undefined || b.open === 0)
              ? true : b.close >= b.open;
            return { time: b.time, value: b.vol, color: (up ? TH.up : TH.down) + TH.volAlpha };
          }));
      }
      self._extra.forEach(function (ex) { renderExtra(ex); });
      applyPriceLines();
      renderIndicators();   // applyMarkers 전에 — 지표 점을 마커 목록에 넣는다
      applyMarkers();
      applyTodayHigh();
      applyPrevHigh();
      if (fit !== false) applyWindow();
      overlaysSoon();
    }

    /* ── 추가 선 (sim 의 누적단가·자동매도가 계단선 등) ── */
    function renderExtra(ex) {
      if (!ex.series) {
        ex.series = chart.addLineSeries({
          color: ex.opt.color, lineWidth: ex.opt.width || 2,
          lineStyle: STYLE_MAP[ex.opt.style || 'solid'],
          lineType: ex.opt.stepped ? LWC.LineType.WithSteps : LWC.LineType.Simple,
          priceLineVisible: false, lastValueVisible: false
        });
      }
      var pairs = ex.raw || [];
      if (self._tf === 'week' && pairs.length) {
        // 주봉: 그 주 마지막 값. null(비보유) 이면 끊김 유지
        var byWeek = {}, order = [];
        pairs.forEach(function (p) {
          var k = (typeof p.time === 'string') ? mondayOf(p.time) : p.time;
          if (!(k in byWeek)) order.push(k);
          byWeek[k] = p;
        });
        var wb = fullBars(), wkOf = {};
        wb.forEach(function (b) { (b.days || []).forEach(function (d) { wkOf[mondayOf(d)] = b.time; }); });
        pairs = order.map(function (k) {
          var p = byWeek[k], t = wkOf[k];
          if (!t) return null;
          return (p.value === null || p.value === undefined) ? { time: t } : { time: t, value: p.value };
        }).filter(Boolean);
      }
      ex.series.setData(pairs.map(function (p) {
        return (p.value === null || p.value === undefined) ? { time: p.time } : p;
      }));
    }

    /* ── 공개 API ── */
    self.setData = function (raw, fit) {
      self._bars = normalize(raw);
      render(fit);
      // 봉이 늦게 왔으면 SUE 마커 자리를 다시 잡는다 (스냅은 봉이 있어야 가능)
      if (self._sueRaw && self._sueRaw.length) sueSnap();
      return self;
    };
    self.setTf = function (tf) {
      if (tf !== 'day' && tf !== 'week' && tf !== 'month') return self;
      if (self._tf === tf) return self;
      self._tf = tf;
      render();
      // 축이 바뀌면 지표 세트도 그 축의 것으로 — 구독자(지표 바)에게 알린다
      self._tfCbs.forEach(function (cb) { try { cb(tf); } catch (e) { console.error(e); } });
      return self;
    };
    self.tf = function () { return self._tf; };
    self.onTf = function (cb) { if (typeof cb === 'function') self._tfCbs.push(cb); return self; };
    self.setViewDays = function (n) {
      /* 마지막 n봉을 «보이는 창»으로 (null=전체). ★데이터는 안 건드린다 —
       * 같은 데이터를 setData 로 다시 실으면 LWC 의 «같은 시간 구간 유지» 보정과
       * 경합해 창 이동이 먹히지 않는 일이 있다(실측). 창·창 기준 표시물만 갱신한다. */
      self._viewDays = n || null;
      self._pinnedRange = null;                 // 기간 버튼을 누르면 zoomRange 로 고정한 구간은 해제
      applyWindow();
      return self;
    };
    self.viewDays  = function () { return self._viewDays; };
    self.barCount  = function () { return fullBars().length; };   // 현재 시간축의 전체 봉 수
    self.setPriceLines = function (list) {
      self._plines = (list || []).filter(function (L) { return L && L.price !== null && L.price !== undefined; });
      applyPriceLines();
      return self;
    };
    self.setMarkers = function (list) {
      self._marks = (list || []).slice().sort(function (a, b) {
        return a.time < b.time ? -1 : a.time > b.time ? 1 : 0;
      });
      applyMarkers();
      return self;
    };
    // 화면이 얹은 마커 중 보이는 수 (SUE 층·지표 점은 빼고 — 「체결 마커 N개」 문구용)
    self.markerCount = function () { return self._mkVisible; };

    /* ── SUE 공시 마커 — 화면이 아니라 «모듈»이 들고 있는 층 ──
     * 종목코드만 알려 주면(setCode) 나머지는 모듈이 한다: 받아오기 → 공시 다음 거래일로 스냅 →
     * 기간 바에 [SUE 공시 N] 토글 달기. 그래서 어느 화면에서도 켤 수 있는 «이동 가능한 모듈»이다.
     * ★위치가 접수일이 아니라 «다음 거래일»인 이유 — 백테스트의 매수 시점이 거기다.
     *   공시 뒤 아직 장이 안 열렸으면 마지막 봉에 붙인다.
     * ★★실린 봉보다 «오래된» 공시는 버린다(2026-08-03) — 예전엔 「m.d 보다 큰 첫 봉」이 언제나
     *   찾아져서(맨 앞 봉) 옛 공시가 전부 첫 봉에 몰렸다. 화면에서는 왼쪽 끝에 배지가 «일렬»로 섰고,
     *   그 자리는 실제 공시일이 아니라 «데이터가 시작한 날»이라 사실과 다른 말을 한다. */
    function sueSnap() {
      var raw = self._sueRaw || [], bars = self._bars;
      if (!raw.length || !bars.length) { self._sueFit = 0; self._sueMarks = []; applyMarkers(); return; }
      var first = bars[0].time, last = bars[bars.length - 1].time, fit = [];
      raw.forEach(function (m) {
        if (m.d < first) return;                 // 불러온 구간 이전의 공시 — 찍을 자리가 없다
        var t = null;
        for (var i = 0; i < bars.length; i++) if (bars[i].time > m.d) { t = bars[i].time; break; }
        var shock = m.sue <= (self._sueShock === undefined ? -1 : self._sueShock);
        // chip:true — 화면이 「라이브러리 글자」를 쓰더라도(시뮬레이터) 이 배지만은 칩으로.
        // 같은 사실을 화면마다 다른 모양으로 보여 주면 그것이 곧 「다른 말」이 된다.
        fit.push({ time: t || last, sell: shock, chip: true,
                   text: shock ? '어닝쇼크' : '어닝서프라이즈',
                   state: m.q + ' SUE ' + m.sue });
      });
      self._sueFit  = fit.length;
      self._sueMarks = self._sueOn ? fit : [];
      applyMarkers();
      sueButton();   // 버린 옛 공시는 「SUE 공시 N」 에서도 빠진다 (버튼과 화면이 같은 말을 하도록)
    }
    /* 토글 버튼은 기간 바에 산다 — 차트 안에는 글자를 넣지 않는다(레포 공통 규칙).
     * 기간 바가 없는 화면(사례분석 미니 차트 등)은 버튼 없이 마커만 나온다.
     * ★버튼은 «바»의 것이지 «차트»의 것이 아니다 — 갤러리처럼 바 하나가 차트 넷을 조종하면
     *   버튼도 하나여야 하고 누르면 넷이 함께 꺼진다(차트마다 달면 버튼이 넷 생긴다). */
    function sueButton() {
      var pb = self._pbar;
      if (!pb || !pb.el) return;
      var bar = pb.el;
      if (!bar._sueDcs) bar._sueDcs = [];
      if (bar._sueDcs.indexOf(self) < 0) bar._sueDcs.push(self);
      var n = 0;
      // 세는 건 «받아 온 수»가 아니라 «찍을 수 있는 수» — 봉이 아직이면 0(그때는 숨는다)
      bar._sueDcs.forEach(function (dc) { n = Math.max(n, dc._sueFit || 0); });
      if (!bar._sueBtn) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'on';
        b.onclick = function () {
          var on = !b.classList.contains('on');
          b.classList.toggle('on', on);
          bar._sueDcs.forEach(function (dc) { dc.setSue(on); });
        };
        bar.appendChild(b);
        bar._sueBtn = b;
      }
      bar._sueBtn.textContent = 'SUE 공시 ' + n;
      bar._sueBtn.title = '▲어닝서프라이즈·▼어닝쇼크 공시 마커 — 공시 다음 거래일에 찍는다'
                        + ' (차트에 실린 구간의 공시만)';
      bar._sueBtn.style.display = n ? '' : 'none';
    }
    self.setSue = function (on) { self._sueOn = !!on; sueSnap(); return self; };
    /* 이 차트가 보고 있는 종목. 기능이 꺼져 있으면 부르지도 않는다(=조회 0회). */
    self.setCode = function (code) {
      code = String(code || '');
      if (code === self._sueCode) return self;
      self._sueCode = code;
      self._sueRaw = [];
      self._sueMarks = [];
      if (!code || !feats('overlay.sue_markers')) { applyMarkers(); return self; }
      sueLoad(code).then(function (d) {
        if (self._sueCode !== code) return;          // 그 사이 다른 종목으로 바뀌었으면 버린다
        self._sueRaw = d.marks || [];
        self._sueShock = (d.shock === undefined) ? -1 : d.shock;
        sueButton();
        sueSnap();
      });
      return self;
    };
    self.sueMarkerCount = function () { return self._sueFit || 0; };   // 실제로 찍히는 수
    // 기간 바가 나보다 늦게 만들어졌을 때 버튼을 뒤늦게 달기 위한 고리 (periodBar 가 부른다)
    self._sueSyncBtn = sueButton;
    self.setIndicators = function (list) {      // [{def:{name,draw,expr,vars,color}, vars:{덮어쓸 변수}}]
      self._inds = list || [];
      renderIndicators();
      applyMarkers();
      return self;
    };
    self.addLine = function (opt) {
      var ex = { opt: opt || {}, raw: [], series: null };
      self._extra.push(ex);
      return {
        setData: function (pairs) { ex.raw = pairs || []; renderExtra(ex); }
      };
    };
    /* 박스 목록 교체 — [{from,to,top,bottom, fill,topColor,bottomColor,topW,bottomW,sideColor,label,labelColor}]
     * to 를 생략하면 화면 오른쪽 끝까지. 스크롤·줌은 overlaysSoon 이 따라간다. */
    self.setBoxes = function (list) {
      self._boxes = (list || []).slice();
      drawBoxes();
      return self;
    };
    self.setTodayHigh = function (on) {
      var S = thState(); S.show = !!on; drawTodayHigh(); return self;
    };
    self.setPrevHigh = function (on) {
      var S = pvState(); S.show = !!on; drawPrevHigh(); return self;
    };
    self.prevHigh = function () {                // 지금 잰 직전고가 값 (오늘치뿐이면 null)
      return (self._pv && isFinite(self._pv.val)) ? self._pv.val : null;
    };
    self.setCurPrice = function (on) {
      self._curPriceOn = !!on;
      if (self._main && self._mainIsCandle) self._main.applyOptions({ priceLineVisible: self._curPriceOn });
      return self;
    };
    self.range = function () {                  // 하단 노트용 요약
      var vb = viewBars();
      if (!vb.length) return null;
      var f = vb[0], l = vb[vb.length - 1];
      return { n: vb.length, from: f.time, to: l.time,
               firstClose: f.close, lastClose: l.close,
               chg: f.close > 0 ? (l.close / f.close - 1) * 100 : null };
    };
    self.zoomRange = function (from, to, o) {   // sim 사이클 구간 이동 — 전체 데이터라 옛 사이클도 바로 간다
      o = o || {};
      var dates = fullBars().map(function (b) { return b.time; });
      if (!dates.length) return self;
      function idxOf(d, dflt) {
        var i = dates.indexOf(d);
        if (i >= 0) return i;
        for (var k = 0; k < dates.length; k++) if (dates[k] >= d) return k;
        return dflt;
      }
      var a = idxOf(from, 0), b = idxOf(to, dates.length - 1);
      if (b < a) b = a;
      var minBars = o.minBars || 60;
      if (b - a < minBars) b = Math.min(dates.length - 1, a + minBars);
      var pad = (o.pad === undefined) ? 3 : o.pad;
      a = Math.max(0, a - pad);
      b = Math.min(dates.length - 1, b + pad);
      self._viewDays = null;                              // 기간 버튼 대신 이 구간을 우선
      self._pinnedRange = { from: dates[a], to: dates[b] }; // setData 뒤 지연 재적용(go)도 이 구간으로 되돌아온다
      chart.timeScale().setVisibleRange(self._pinnedRange);
      return self;
    };
    self.zoomAll = function () {
      self._viewDays = null; self._pinnedRange = null;    // 「전체」도 상태로 남겨야 다음 재적용에 안 밀린다
      chart.timeScale().fitContent();
      return self;
    };
    self.refreshChips = overlaysSoon;

    /* ── 전체화면 ──
     * 새 차트를 만들지 않고 host 를 통째로 오버레이에 «옮긴다» → 지표·마커·가격선·신호칩이
     * 그대로 따라온다(다시 계산·재조회 없음). 닫으면 원래 자리(자리표시자)로 되돌린다. */
    function onFsKey(e) { if (e.key === 'Escape') self.fullscreen(false); }
    self.isFullscreen = function () { return !!self._fs; };
    /* 전체화면에 «같이 데려갈» 조작 바 등록 — 기간 바·지표 바가 스스로 등록한다.
     * 새로 그리지 않고 원본을 옮기므로 상태(선택된 기간·적용 지표)가 그대로다. */
    self._fsCarry = [];
    self.fsCarry = function (el) {
      if (typeof el === 'string') el = document.getElementById(el);
      if (el && self._fsCarry.indexOf(el) < 0) self._fsCarry.push(el);
      return self;
    };
    function carryIn(ov) {
      var moved = [];
      self._fsCarry.forEach(function (el) {
        if (!el.parentNode) return;
        var ph = document.createElement('span');
        el.parentNode.insertBefore(ph, el);
        ov.appendChild(el);
        moved.push({ el: el, ph: ph });
      });
      return moved;
    }
    function carryBack(moved) {
      moved.forEach(function (m) {
        if (m.ph.parentNode) {
          m.ph.parentNode.insertBefore(m.el, m.ph);
          m.ph.parentNode.removeChild(m.ph);
        }
      });
    }
    self.fullscreen = function (on) {
      if (on === undefined) on = !self._fs;
      if (!!on === !!self._fs) return self;
      /* ★보고 있던 구간을 붙잡아 둔다 — 폭만 커지면 라이브러리는 봉 간격을 그대로 두고
       * 남는 자리를 왼쪽 여백으로 채워서 캔들이 오른쪽에 몰린다. 크기를 바꾼 뒤 같은 범위를
       * 다시 걸어 주면 봉이 넓어지며 화면을 채운다. */
      var keep = null;
      try { keep = chart.timeScale().getVisibleLogicalRange(); } catch (e) { keep = null; }
      if (on) {
        injectCss();
        var ov = document.createElement('div');
        ov.className = 'dc-fs ' + (opts.theme === 'dark' ? 'dark' : 'light');
        var ph = document.createElement('div');                 // 원래 자리 표시자 (레이아웃 유지)
        ph.style.cssText = 'height:' + host.offsetHeight + 'px';
        host.parentNode.insertBefore(ph, host);
        self._fs = { ov: ov, ph: ph, style: host.getAttribute('style'), moved: [] };
        // 조작 바(기간·지표)를 먼저 옮기고 그 아래에 차트를 채운다
        var barBox = document.createElement('div');
        barBox.className = 'dc-fs-bar';
        ov.appendChild(barBox);
        self._fs.moved = carryIn(barBox);
        var x = document.createElement('button');
        x.type = 'button'; x.className = 'dc-fs-x'; x.textContent = '✕ 닫기 (ESC)';
        x.onclick = function () { self.fullscreen(false); };
        barBox.appendChild(x);
        host.style.width = '100%'; host.style.height = 'auto';
        host.style.flex = '1 1 0'; host.style.minHeight = '0';
        ov.appendChild(host);
        document.body.appendChild(ov);
        document.body.style.overflow = 'hidden';
        document.addEventListener('keydown', onFsKey);
      } else {
        var f = self._fs;
        self._fs = null;
        carryBack(f.moved);                    // 조작 바를 제자리로 (차트보다 먼저)
        f.ph.parentNode.insertBefore(host, f.ph);
        f.ph.parentNode.removeChild(f.ph);
        if (f.style === null) host.removeAttribute('style'); else host.setAttribute('style', f.style);
        if (f.ov.parentNode) f.ov.parentNode.removeChild(f.ov);
        document.body.style.overflow = '';
        document.removeEventListener('keydown', onFsKey);
      }
      function fit() {
        onResize();
        var ts = chart.timeScale();
        if (keep) { try { ts.setVisibleLogicalRange(keep); } catch (e) { ts.fitContent(); } }
        else ts.fitContent();
        overlaysSoon();
      }
      fit();
      setTimeout(fit, 30);           // 레이아웃이 잡힌 뒤 한 번 더 (칩 좌표까지 정확히)
      return self;
    };

    // opts.code — 이 차트가 보는 종목. 주면 SUE 공시 마커를 모듈이 알아서 얹는다
    if (opts.code) self.setCode(opts.code);

    /* 라이브러리 객체를 꺼내 쓰는 통로 — 모듈이 감싸지 못한 일(차트 간 crosshair 연동 등)에만.
     * ★일반 화면은 쓰지 말 것. 여기로 옵션을 직접 만지면 모듈이 세운 규칙(축 가두기·가격축 여백)이 깨진다. */
    self.lwc  = function () { return chart; };
    self.main = function () { return self._main; };

    return self;
  }

  /* ── 차트 둘의 십자선을 잇는다 (같은 시각을 두 창에서 함께 짚는다) ──
   * 단타 화면의 메인 분봉 ↔ 보조 분봉처럼 <b>축 단위가 같은</b> 차트끼리만 쓴다.
   * ★일봉과 분봉은 잇지 않는다 — 축 단위가 날짜 vs 분이라 무의미하고,
   *   액면분할 종목에서 가격대가 어긋나 보여 오독을 부른다.
   */
  function linkCrosshair(dcs) {
    var list = (dcs || []).filter(Boolean);
    if (list.length < 2) return;
    var busy = false;
    list.forEach(function (dc) {
      var chart = dc.lwc && dc.lwc();
      if (!chart) return;
      chart.subscribeCrosshairMove(function (p) {
        if (busy) return;
        busy = true;
        list.forEach(function (o) {
          if (o === dc) return;
          var oc = o.lwc && o.lwc(), os = o.main && o.main();
          if (!oc || !os) return;
          try {
            if (!p || !p.time) oc.clearCrosshairPosition();
            else {
              var v = p.seriesData && p.seriesData.get ? p.seriesData.get(dc.main()) : null;
              oc.setCrosshairPosition(v ? (v.close !== undefined ? v.close : v.value) : 0, p.time, os);
            }
          } catch (e) { /* 라이브러리 버전 차이는 조용히 넘긴다 — 십자선 하나로 화면이 죽지 않는다 */ }
        });
        busy = false;
      });
    });
  }

  /* ── 기간 바 — 화면들이 같은 기간 UI·정책을 공유한다 ──
   * 한 박스 안에 [ 일봉 | 주봉 ┃ 160일 240일 480일 전체 ] 를 그린다.
   * 시간축을 누르면 기간 라벨이 함께 바뀐다 (일봉 160/240/480/전체 ↔ 주봉 24/48/96주/전체).
   * 같은 인덱스가 비슷한 구간이라(240일≈48주) 축을 바꿔도 선택 위치를 유지한다.
   * 데이터는 1000영업일(≈4년)을 한 번 받아 두고 화면만 슬라이스한다 — 재조회 없음.
   *
   * periodBar(container, dc|[dc...], { theme:'light'|'dark',
   *                                    defaultIndex:1(=240일), onChange({tf,label,bars}) })
   *
   * ★기간의 주인은 «화면»이다 (2026-08-03).
   *   차트틀(chart_preset)은 여러 화면이 공유하는 물건이라 거기에 기간을 넣으면 재무분석에서 맞춘
   *   240일이 시뮬레이터까지 따라간다 — 화면마다 차트 폭이 다른데. 그래서 우선순위를 셋으로 둔다:
   *     ① 이 화면이 기억한 기간 (chart_pref.view_json → 서버가 DC_VIEW 로 심어 준다)
   *     ② 차트틀의 view — 그 차트틀을 «처음 쓰는» 화면의 시작값
   *     ③ 화면 기본값 defaultIndex (재무·보유 160일 · 갤러리·사다리 240일 · 시뮬 전체)
   *   기간을 만지면 ①만 갱신된다(차트틀은 안 건드림). 차트틀을 «고르면» ②가 적용되고 그것이 ①이 된다.
   */
  var PERIODS = {
    day:  [[160, '160일'], [240, '240일'], [480, '480일'], [null, '전체']],
    week: [[24, '24주'],   [48, '48주'],   [96, '96주'],   [null, '전체']]
  };
  function periodBar(container, dcs, cfg) {
    if (typeof container === 'string') container = document.getElementById(container);
    cfg = cfg || {};
    if (!container) return null;
    injectCss();

    var list = (Array.isArray(dcs) ? dcs : [dcs]).filter(Boolean);
    // 화면 식별자 — 있으면 이 바가 기간을 기억한다 (없으면 옛날처럼 기본값만)
    var vkey = (cfg.screen !== undefined) ? String(cfg.screen)
             : (typeof window.DC_SCREEN === 'string' ? window.DC_SCREEN : '');
    var mem = (vkey && window.DC_VIEW) ? window.DC_VIEW : null;
    var tf = (mem && (mem.tf === 'day' || mem.tf === 'week')) ? mem.tf : 'day';
    var idx = (cfg.defaultIndex === undefined) ? 1 : cfg.defaultIndex;
    var custom = null;          // ± 로 직접 정한 봉 수 (null 이면 위 4개 버튼 중 하나)
    if (mem && mem[tf]) {       // 이 화면이 기억한 기간이 있으면 그것으로 시작한다
      if (mem[tf].index !== undefined && mem[tf].index !== null) idx = +mem[tf].index;
      if (mem[tf].bars) custom = +mem[tf].bars;
    }

    var bar = document.createElement('div');
    bar.className = 'dc-pbar ' + (cfg.theme === 'dark' ? 'dark' : 'light');
    function mkBtn(txt) {
      var b = document.createElement('button');
      b.type = 'button';
      b.textContent = txt;
      bar.appendChild(b);
      return b;
    }
    var bDay = mkBtn('일봉');
    var bWeek = mkBtn('주봉');
    var div = document.createElement('span');
    div.className = 'dc-pdiv';
    bar.appendChild(div);
    var pBtns = [0, 1, 2, 3].map(function () { return mkBtn(''); });
    /* ± 미세 조정 — 지금 기간의 20%씩 늘리고 줄인다 (버튼 4개 사이의 값도 볼 수 있게) */
    var dz = document.createElement('span');
    dz.className = 'dc-pdiv';
    bar.appendChild(dz);
    var bMinus = mkBtn('－');
    bMinus.title = '기간 20% 줄이기';
    var readout = document.createElement('span');
    readout.className = 'dc-pnum';
    bar.appendChild(readout);
    var bPlus = mkBtn('＋');
    bPlus.title = '기간 20% 늘리기';
    /* 전체화면 — 차트가 하나일 때만 (여러 개를 한 바로 조종하는 갤러리에선 대상이 모호하다).
     * 이 바 자체를 전체화면에 데려가게 등록해 둔다 — 큰 화면에서도 기간을 바꿀 수 있어야 한다. */
    if (cfg.fullscreen !== false && list.length === 1 && list[0].fullscreen) {
      if (list[0].fsCarry) list[0].fsCarry(bar);
      var d2 = document.createElement('span');
      d2.className = 'dc-pdiv';
      bar.appendChild(d2);
      var fsBtn = mkBtn('⛶');
      fsBtn.title = '전체화면으로 크게 보기 (ESC 로 닫기)';
      fsBtn.onclick = function () { list[0].fullscreen(); };
    }
    container.appendChild(bar);

    function totalBars() { return (list[0] && list[0].barCount) ? list[0].barCount() : 0; }
    function unit() { return tf === 'week' ? '주' : tf === 'month' ? '월' : '일'; }
    /* 상태 → 라벨·활성 표시만 (차트는 안 건드린다 — 휠 자동 확장의 표시 동기화에도 쓴다) */
    function paint() {
      var P = PERIODS[tf];
      bDay.classList.toggle('on', tf === 'day');
      bWeek.classList.toggle('on', tf === 'week');
      pBtns.forEach(function (b, i) {
        b.textContent = P[i][1];
        b.classList.toggle('on', custom === null && i === idx);   // ± 로 바꾼 뒤엔 어느 버튼도 아니다
      });
      readout.textContent = custom !== null ? (custom + unit()) : P[idx][1];
    }
    /* 이 화면의 기간 기억 — 손이 멎은 뒤 한 번만 쓴다(버튼 연타·휠에 매번 쏘지 않게).
     * 높이(h)와 같은 칸에 살므로 window.DC_VIEW 를 통째로 보낸다 — 서로 지우지 않는다. */
    var saveT = null;
    function saveView() {
      if (!vkey) return;
      clearTimeout(saveT);
      saveT = setTimeout(function () {
        var v = window.DC_VIEW || (window.DC_VIEW = {});
        v.tf = tf;
        v[tf] = { index: idx, bars: custom };
        apiPost('view_save', { chart_key: vkey, view: JSON.stringify(v) }).catch(function () {});
      }, 500);
    }
    /* 축을 바꾸면 그 축이 기억한 기간으로 (없으면 지금 버튼 위치를 유지 — 240일↔48주는 같은 자리) */
    function useMem() {
      var m = (window.DC_VIEW && window.DC_VIEW[tf]) || null;
      custom = null;
      if (m) {
        if (m.index !== undefined && m.index !== null) idx = +m.index;
        if (m.bars) custom = +m.bars;
      }
    }
    var firstApply = true;
    function apply() {
      paint();
      var n = custom !== null ? custom : PERIODS[tf][idx][0];
      list.forEach(function (dc) { dc.setTf(tf); dc.setViewDays(n); });
      if (cfg.onChange) cfg.onChange({ tf: tf, label: readout.textContent, bars: n });
      // 만들 때의 첫 적용은 «읽은 값을 그대로 그린 것»이라 되쓰지 않는다
      if (firstApply) firstApply = false; else saveView();
    }
    /* 기간 ±20% — 지금 보이는 봉 수 기준. 「전체」에서 줄이면 전체의 80%부터 시작한다. */
    function zoom(dir) {
      var total = totalBars();
      var cur = custom !== null ? custom : (PERIODS[tf][idx][0] || total);
      if (!cur) return;
      var n = dir > 0 ? Math.round(cur * 1.2) : Math.round(cur / 1.2);
      if (n === cur) n = cur + dir;                    // 반올림으로 제자리면 최소 1봉은 움직인다
      if (total) n = Math.min(n, total);
      n = Math.max(20, n);                             // 20봉 밑으로는 안 줄인다
      if (n === cur) return;
      custom = n;
      apply();
    }
    bDay.onclick  = function () { if (tf !== 'day')  { tf = 'day';  useMem(); apply(); } };
    bWeek.onclick = function () { if (tf !== 'week') { tf = 'week'; useMem(); apply(); } };
    pBtns.forEach(function (b, i) { b.onclick = function () { idx = i; custom = null; apply(); }; });
    bMinus.onclick = function () { zoom(-1); };
    bPlus.onclick  = function () { zoom(1); };

    var ctl = {
      refresh: apply,
      state: function () { return { tf: tf, index: idx, bars: custom }; },
      /* 저장된 차트를 되살릴 때 쓴다 — 시간축·기간(버튼 또는 ± 값)을 한꺼번에 맞춘다 */
      set: function (newTf, newIdx, bars) {
        var ch = false;
        if ((newTf === 'day' || newTf === 'week') && newTf !== tf) { tf = newTf; ch = true; }
        if (newIdx !== undefined && newIdx !== null && +newIdx !== idx &&
            +newIdx >= 0 && +newIdx < PERIODS[tf].length) { idx = +newIdx; ch = true; }
        var nb = (bars === undefined || bars === null || !(+bars > 0)) ? null : +bars;
        if (nb !== custom) { custom = nb; ch = true; }
        if (ch) apply();
        return ctl;
      },
      zoom: zoom,
      /* 휠 줌아웃 자동 확장(모듈)이 부른다 — 차트가 스스로 기간을 늘렸으니 표시만 따라간다.
       * srcDc(늘어난 차트)는 이미 제 배율 그대로 다시 그려졌으므로 건너뛰고,
       * 같은 바에 묶인 다른 차트(갤러리)만 새 기간으로 맞춘다. */
      syncBars: function (n, srcDc) {
        var total = totalBars();
        if (total && n >= total) { custom = null; idx = PERIODS[tf].length - 1; }   // 끝까지 늘었으면 = 전체
        else custom = Math.max(20, Math.round(+n) || 20);
        paint();
        list.forEach(function (dc) {
          if (dc === srcDc) return;
          dc.setViewDays(custom);
        });
        if (cfg.onChange) cfg.onChange({ tf: tf, label: readout.textContent, bars: custom });
        saveView();                    // 휠로 넓힌 것도 이 화면의 기간이다
        return ctl;
      },
      /* 이 화면이 그 축의 기간을 기억하고 있나 — 지표 바가 「차트틀의 기간을 덮어쓸지」 여기서 묻는다.
       * 축별로 답한다: 일봉은 기억이 있고 주봉은 없을 수 있다. */
      hasMemory: function (t) {
        var m = window.DC_VIEW;
        return !!(vkey && m && m[t || tf]);
      }
    };
    ctl.el = bar;   // SUE 공시 토글 같은 모듈 소유 버튼이 여기에 붙는다
    // 지표 바가 「차트 저장/불러오기」에서 이 컨트롤러를 찾아 쓴다 (호출부 수정 없이)
    list.forEach(function (dc) {
      dc._pbar = ctl;
      if (dc._sueSyncBtn) dc._sueSyncBtn();   // 마커가 바보다 먼저 도착했으면 이제 버튼을 단다
    });
    apply();
    return ctl;
  }

  /* ── 화면이 심어 둔 기능 구성 읽기 ──
   * 각 화면은 서버에서 ChartFeat::merge() 한 결과를 <script>var DC_FEATS={...}</script> 로 심는다.
   * 안 심은 곳(도구모음 없는 미니 차트 등)은 「켜짐」으로 본다 — 구성을 안 준다고 기능이
   * 사라지면 안 된다. 끄고 싶으면 차트설정에서 그 화면을 등록해 명시적으로 끈다. */
  function feats(k, dflt) {
    var m = window.DC_FEATS;
    if (!m || m[k] === undefined || m[k] === null) return dflt === undefined ? true : !!dflt;
    return !!+m[k];
  }

  /* ── 도구모음 미리보기 (차트설정 > 구성) ──
   * toolbarPreview(container, feats, { theme, sueCount })
   *   feats = {feature_key: 0|1} — 서버 카탈로그(ChartFeat)가 병합해 준 최종값.
   * ★진짜 바와 같은 CSS 클래스·같은 PERIODS 라벨로 그린다 — 실물 바를 고치면 미리보기도 따라온다.
   *   진짜 바를 그대로 쓰지 않는 이유: 지표 바는 조작하면 서버에 「마지막 쓴 차트」를 기록한다.
   *   설정 화면에서 6개를 늘어놓고 만지작거리는 순간 그 기록이 오염된다. 그래서 조작 불가 모형.
   */
  function toolbarPreview(container, feats, cfg) {
    if (typeof container === 'string') container = document.getElementById(container);
    if (!container) return null;
    injectCss();
    cfg = cfg || {};
    feats = feats || {};
    var dark = cfg.theme === 'dark';
    var on = function (k) { return !!+feats[k]; };

    container.innerHTML = '';
    var box = document.createElement('div');
    box.className = 'dc-tbprev' + (dark ? ' dark' : '');
    box.style.pointerEvents = 'none';        // 보여 주기만 한다
    container.appendChild(box);

    function row() {
      var r = document.createElement('div');
      r.className = 'dc-tbrow';
      box.appendChild(r);
      return r;
    }
    function bar(cls) {
      var b = document.createElement('span');
      b.className = cls + ' ' + (dark ? 'dark' : 'light');
      return b;
    }
    function btn(host, txt, isOn) {
      var b = document.createElement('button');
      b.type = 'button'; b.textContent = txt; b.tabIndex = -1;
      if (isOn) b.className = 'on';
      host.appendChild(b);
      return b;
    }
    function div(host) {
      var d = document.createElement('span');
      d.className = 'dc-pdiv';
      host.appendChild(d);
    }

    var r1 = row();
    // 화면 전용 버튼·칩이 먼저 온다 (실제 화면들의 배치 순서)
    if (on('overlay.intraday_ref')) {
      var cb = bar('dc-pbar');
      btn(cb, '당일전고', true); btn(cb, '현재가', false);
      r1.appendChild(cb);
    }
    if (on('period.full_range')) {
      var fb = bar('dc-pbar');
      btn(fb, '전체 기간', false);
      r1.appendChild(fb);
    }
    // 기간 바
    if (on('period.cycle') || on('period.preset') || on('fullscreen')) {
      var pb = bar('dc-pbar');
      if (on('period.cycle')) { btn(pb, '일봉', true); btn(pb, '주봉', false); }
      if (on('period.preset')) {
        if (on('period.cycle')) div(pb);
        PERIODS.day.forEach(function (p, i) { btn(pb, p[1], i === 1); });
        div(pb);
        btn(pb, '－', false);
        var num = document.createElement('span');
        num.className = 'dc-pnum'; num.textContent = '240일';
        pb.appendChild(num);
        btn(pb, '＋', false);
      }
      if (on('fullscreen')) { div(pb); btn(pb, '⛶', false); }
      // SUE 공시 토글도 이 바에 산다 (모듈이 붙인다 — 공시가 있는 종목에서만 보인다)
      if (on('overlay.sue_markers')) btn(pb, 'SUE 공시 ' + (cfg.sueCount || 6), true);
      r1.appendChild(pb);
    }
    // 지표 바
    if (on('preset.select') || on('save') || on('indicator.add')) {
      var ib = bar('dc-ibar');
      if (on('preset.select')) {
        var sel = document.createElement('select');
        sel.className = 'dc-pset';
        var o = document.createElement('option');
        o.textContent = '최고거래대금_일봉';
        sel.appendChild(o);
        ib.appendChild(sel);
      }
      if (on('save')) {
        var sv = document.createElement('button');
        sv.type = 'button'; sv.className = 'dc-iapply dc-psave'; sv.textContent = '차트저장';
        sv.tabIndex = -1;
        ib.appendChild(sv);
      }
      if (on('indicator.add')) {
        var d2 = document.createElement('span'); d2.className = 'dc-pdiv2';
        ib.appendChild(d2);
        var ad = document.createElement('button');
        ad.type = 'button'; ad.className = 'dc-iapply'; ad.textContent = '＋ 지표';
        ad.tabIndex = -1;
        ib.appendChild(ad);
        var chip = document.createElement('span');
        chip.className = 'dc-ichip';
        var dot = document.createElement('i'); dot.style.background = '#d32f2f';
        chip.appendChild(dot);
        chip.appendChild(document.createTextNode('최고거래대금선(240)'));
        var x = document.createElement('b'); x.textContent = '✕';
        chip.appendChild(x);
        ib.appendChild(chip);
      }
      r1.appendChild(ib);
    }
    if (on('legend.values')) {
      var lg = document.createElement('div');
      lg.className = 'dc-tbleg' + (dark ? ' dark' : '');
      lg.textContent = '최고거래대금선(240) H 62,400 · L 51,900';
      box.appendChild(lg);
    }
    // 도구모음에 자리를 갖지 않는 것들 — 차트 위에 얹히는 층
    var layers = [];
    if (on('overlay.position_lines')) layers.push('가격선 3종');
    if (on('overlay.trade_markers'))  layers.push('체결 마커');
    if (on('overlay.sue_markers'))    layers.push('SUE 공시 마커');
    if (on('overlay.intraday_ref'))   layers.push('당일전고선·현재가선');
    if (on('overlay.box_ladder'))     layers.push('박스 후보선·선택 지지선');
    if (on('panel.minute'))           layers.push('분봉 보조 차트');
    var note = document.createElement('div');
    note.className = 'dc-tbnote' + (dark ? ' dark' : '');
    note.textContent = layers.length ? '차트 위: ' + layers.join(' · ') : '차트 위: 얹히는 층 없음';
    box.appendChild(note);
    return box;
  }

  /* ── 공용 모달 — 지표 선택 / 변수 수정 ──
   * buttons: [{label, kind:'pri'|'del', onClick(close)}] — onClick 이 있으면 닫기 책임도 그쪽 */
  function openModal(cfg) {
    injectCss();
    cfg = cfg || {};
    var ov = document.createElement('div');
    ov.className = 'dc-modal ' + (cfg.theme === 'dark' ? 'dark' : 'light');
    var box = document.createElement('div'); box.className = 'dc-mbox';
    var hd  = document.createElement('div'); hd.className  = 'dc-mhd';
    var ttl = document.createElement('span'); ttl.textContent = cfg.title || '';
    hd.appendChild(ttl);
    if (cfg.sub) {
      var sb = document.createElement('span'); sb.className = 'sub'; sb.textContent = cfg.sub;
      hd.appendChild(sb);
    }
    var bd = document.createElement('div'); bd.className = 'dc-mbd';
    var ft = document.createElement('div'); ft.className = 'dc-mft';
    box.appendChild(hd); box.appendChild(bd); box.appendChild(ft);
    ov.appendChild(box);

    function close() {
      if (ov.parentNode) ov.parentNode.removeChild(ov);
      document.removeEventListener('keydown', onKey);
    }
    function onKey(e) { if (e.key === 'Escape') close(); }
    ov.onclick = function (e) { if (e.target === ov) close(); };
    document.addEventListener('keydown', onKey);

    (cfg.buttons || []).forEach(function (b) {
      var el = document.createElement('button');
      el.type = 'button'; el.textContent = b.label;
      if (b.kind) el.className = b.kind;
      el.onclick = function () { if (b.onClick) b.onClick(close); else close(); };
      ft.appendChild(el);
    });
    document.body.appendChild(ov);
    if (cfg.build) cfg.build(bd, close);
    return { close: close, body: bd };
  }
  function modalNote(bd, text) {
    var n = document.createElement('div');
    n.className = 'dc-mnote'; n.textContent = text;
    bd.appendChild(n);
    return n;
  }

  /* ── 사용자 지표 목록 (API module=ind) — 페이지당 1회 캐시 ── */
  var _indList = null, _indFetchP = null;
  function loadIndicators(force) {
    if (_indList && !force) return Promise.resolve(_indList);
    if (_indFetchP && !force) return _indFetchP;
    _indFetchP = fetch(API + '?module=ind&action=list', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (a) { _indList = Array.isArray(a) ? a : []; _indFetchP = null; return _indList; })
      .catch(function () { _indFetchP = null; _indList = _indList || []; return _indList; });
    return _indFetchP;
  }

  /* ── SUE 공시 마커 — 종목당 한 번만 받아 캐시 (단타처럼 종목을 갈아 끼우는 화면 대비) ── */
  var _sueCache = {};
  function sueLoad(code) {
    if (_sueCache[code]) return _sueCache[code];
    _sueCache[code] = fetch(API + '?module=sue&action=marks&code=' + encodeURIComponent(code),
                            { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) { return (d && d.marks) ? d : { marks: [] }; })
      .catch(function () { return { marks: [] }; });   // 조용히 생략 — 마커 하나 때문에 화면이 죽지 않는다
    return _sueCache[code];
  }

  /* ── 차트틀 (이름 붙인 지표 세트 + 화면별 마지막 선택) ── */
  var _presetData = null, _presetP = null;
  function loadPresets(force) {
    if (_presetData && !force) return Promise.resolve(_presetData);
    if (_presetP && !force) return _presetP;
    _presetP = fetch(API + '?module=ind&action=preset', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        _presetData = { presets: (d && d.presets) || [], prefs: (d && d.prefs) || {} };
        _presetP = null;
        return _presetData;
      })
      .catch(function () {
        _presetP = null;
        _presetData = _presetData || { presets: [], prefs: {} };
        return _presetData;
      });
    return _presetP;
  }
  function apiPost(action, params) {
    var body = new URLSearchParams();
    body.set('module', 'ind'); body.set('action', action);
    Object.keys(params || {}).forEach(function (k) { body.set(k, params[k]); });
    return fetch(API, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }

  /* ── 지표 바 — [차트 ▾][차트저장] ┃ [＋ 지표][적용중 칩✕] ──
   * indicatorBar(container, dc|[dc...], {theme, key})
   *   key = 화면 식별자(fund/position/…). 이 화면에서 마지막에 쓴 차트를 기억해 열 때 되살린다.
   *
   * 「차트」 = 이름 붙인 한 벌의 화면 상태:
   *   그래프 포맷(기간) · 일봉/주봉 · 지표 선택 · 변수  — 저장한 그대로 되살아난다.
   *   축(일/주/월)별 지표 세트를 따로 들고 있어 축을 바꾸면 그 축 구성으로 자동 전환된다.
   * 지표 선택은 [＋ 지표] 모달, 변수 수정은 칩 클릭 모달 (바 위에 입력칸을 늘어놓지 않는다). */
  function indicatorBar(container, dcs, cfg) {
    if (typeof container === 'string') container = document.getElementById(container);
    cfg = cfg || {};
    if (!container) return null;
    injectCss();

    var list = (Array.isArray(dcs) ? dcs : [dcs]).filter(Boolean);
    var chartKey = cfg.key || (list[0] && list[0].key) || '';
    var wrap = document.createElement('span');
    wrap.className = 'dc-ibar ' + (cfg.theme === 'dark' ? 'dark' : 'light');

    // 차트(저장된 화면 상태) 영역
    var pSel = document.createElement('select');
    pSel.className = 'dc-pset';
    pSel.title = '저장된 차트 — 지표·변수·일봉/주봉을 통째로 불러온다 (기간은 화면마다 따로 기억한다)';
    var pSave = document.createElement('button');
    pSave.type = 'button'; pSave.className = 'dc-iapply dc-psave';
    pSave.textContent = '차트저장';
    pSave.title = '지금 화면 그대로 저장 — 지표 선택·변수·일봉/주봉. 기간은 이 차트를 처음 쓰는 화면의 시작값으로만 담긴다';
    var pDiv = document.createElement('span');
    pDiv.className = 'dc-pdiv2';

    var addBtn = document.createElement('button');
    addBtn.type = 'button'; addBtn.className = 'dc-iapply';
    addBtn.textContent = '＋ 지표';
    addBtn.title = '차트에 그릴 지표 고르기';
    var chips = document.createElement('span');
    // preset:false — 「저장된 차트 ▾」를 숨긴다 (기능 구성 preset.select). 저장 버튼은 공통 핵심이라 남는다
    if (cfg.preset !== false) wrap.appendChild(pSel);
    wrap.appendChild(pSave); wrap.appendChild(pDiv);
    wrap.appendChild(addBtn); wrap.appendChild(chips);
    container.appendChild(wrap);
    // 전체화면에도 이 바를 데려간다 (차트 1개짜리 화면에서만 — 갤러리는 전체화면 자체가 없다)
    if (list.length === 1 && list[0].fsCarry) list[0].fsCarry(wrap);

    var defs = [], applied = {};   // applied[id] = {def, vars}
    var presets = [], prefs = {}, curPreset = 0;
    var restoring = false;         // 저장된 차트를 되살리는 중 (onTf 재진입 방지)

    function defById(id) {
      for (var i = 0; i < defs.length; i++) if (defs[i].id === +id) return defs[i];
      return null;
    }
    // 지표의 얼굴색 = 첫 수식칸 색 (없으면 구형 단일 color)
    function defColor(d) {
      return (d.lines && d.lines.length && d.lines[0].color) ? d.lines[0].color : d.color;
    }
    function tfName(tf) {
      return tf === 'week' ? '주봉' : tf === 'month' ? '월봉' : tf === 'min' ? '분봉' : '일봉';
    }
    // 이 화면의 기간 바 (있으면 기간·시간축까지 저장/복원한다)
    function pbar() {
      for (var i = 0; i < list.length; i++) if (list[i]._pbar) return list[i]._pbar;
      return null;
    }
    var varNames = indVarNames;   // 변수 이름 목록 (정의 순서)
    function push() {
      var arr = Object.keys(applied).map(function (k) { return applied[k]; });
      list.forEach(function (dc) { dc.setIndicators(arr); });
      renderChips();
    }
    function renderChips() {
      chips.innerHTML = '';
      var tf = curTf();
      Object.keys(applied).forEach(function (id) {
        var ap = applied[id];
        /* ★이 축에서 안 보이기로 한 지표는 칩도 «안 그린다» — 화면에서 완전히 사라진다.
         *   다만 applied 에서는 «빼지 않는다»: 축을 되돌리면 그대로 살아나야 하고,
         *   차트저장이 그 축의 세트를 통째로 덮어쓰므로 여기서 지우면 저장이 지표를 잃는다. */
        if (!indTfOk(ap.def, tf)) return;
        var c = document.createElement('span');
        c.className = 'dc-ichip';
        c.title = '클릭 — 변수 수정';
        var dot = document.createElement('i');
        dot.style.background = defColor(ap.def);
        c.appendChild(dot);
        c.appendChild(document.createTextNode(indLabel(ap.def.name, ap.def, ap.vars, curTf())));
        var x = document.createElement('b');
        x.textContent = '✕';
        x.title = '차트에서 빼기';
        x.onclick = function (e) { e.stopPropagation(); delete applied[id]; push(); };
        c.appendChild(x);
        c.onclick = function () { openVarModal(id); };
        chips.appendChild(c);
      });
    }

    /* ── [＋ 지표] 모달 — 체크한 것만 그린다 (이미 적용된 지표의 변수는 그대로 유지) ── */
    addBtn.onclick = function () {
      var chosen = {};
      Object.keys(applied).forEach(function (k) { chosen[k] = 1; });
      openModal({
        theme: cfg.theme,
        title: '지표 선택',
        sub: tfName(curTf()) + ' 기준',
        build: function (bd) {
          if (!defs.length) {
            modalNote(bd, '등록된 지표가 없습니다. 차트 갤러리(chart_gallery.php)에서 먼저 지표를 만드세요.');
            return;
          }
          var tf = curTf(), hid = 0;
          defs.forEach(function (d) {
            /* 이 축에서 안 그리는 지표는 목록에서도 뺀다 — 고를 수 없는 것을 늘어놓지 않는다.
             * ★골라 둔 상태(chosen)는 건드리지 않는다: 「적용」이 chosen 을 그대로 옮기므로
             *   목록에 없어도 안 빠지고, 축을 되돌리면 다시 나온다. */
            if (!indTfOk(d, tf)) { hid++; return; }
            var row = document.createElement('label');
            row.className = 'dc-mrow';
            var cb = document.createElement('input');
            cb.type = 'checkbox'; cb.checked = !!chosen[d.id];
            cb.onchange = function () { if (cb.checked) chosen[d.id] = 1; else delete chosen[d.id]; };
            var dot = document.createElement('i');
            dot.style.background = defColor(d);
            var nm = document.createElement('b');
            nm.textContent = indLabel(d.name, d, null, tf);
            var em = document.createElement('em');
            var vn = varNames(d);
            em.textContent = (d.draw === 'point' ? '점' : '선') + (vn.length ? ' · ' + vn.join(', ') : '');
            row.appendChild(cb); row.appendChild(dot); row.appendChild(nm); row.appendChild(em);
            bd.appendChild(row);
          });
          // 왜 목록이 짧은지 말해 준다 — 조용히 줄어들면 「지표가 사라졌다」가 된다
          if (hid) {
            modalNote(bd, tfName(tf) + '에서 쓰지 않는 지표 ' + hid + '개는 숨겼습니다 '
                        + '(지표 관리에서 축을 켜면 나옵니다).');
          }
        },
        buttons: [
          { label: '취소' },
          { label: '적용', kind: 'pri', onClick: function (close) {
            var next = {}, bad = [];
            Object.keys(chosen).forEach(function (id) {
              var d = defById(id);
              if (!d) return;
              // 수식이 잘못됐으면 여기서 바로 알린다 (조용히 안 그리면 "왜 안 나오지"가 된다)
              try { checkDef(d); } catch (e) { bad.push(d.name + ' — ' + e.message); return; }
              next[d.id] = { def: d, vars: (applied[d.id] && applied[d.id].vars) || {},
                             exts: (applied[d.id] && applied[d.id].exts) || {} };
            });
            applied = next;
            push();
            close();
            if (bad.length) alert('수식 오류로 제외된 지표:\n' + bad.join('\n'));
          } }
        ]
      });
    };

    /* ── 칩 클릭 모달 — 그 지표의 변수·연장을 고쳐 다시 그린다 ──
     * 칸에는 «지금 축의 실제 값»을 채워 보여준다 (일봉 240 / 주봉 104).
     * ★손대지 않은 값(=그 축 기본값 그대로)은 저장하지 않는다 — 저장해 버리면
     *   축을 바꿔도 그 숫자가 따라와 일/주/월 기본값 전환이 죽는다.
     * 「연장」도 같은 결 — 정의(갤러리)는 안 건드리고 이 화면 적용분(ap.exts)에만 얹는다. */
    function openVarModal(id) {
      var ap = applied[id];
      if (!ap) return;
      var tf = curTf();
      var names = varNames(ap.def), base = tfVars(ap.def.vars, tf), inputs = [], extInputs = [];
      openModal({
        theme: cfg.theme,
        title: indLabel(ap.def.name, ap.def, ap.vars, tf),
        sub: tfName(tf) + ' 기준',
        build: function (bd) {
          names.forEach(function (k) {
            var dflt = (base[k] === undefined) ? '' : String(base[k]);
            var row = document.createElement('div');
            row.className = 'dc-mvar';
            var lb = document.createElement('label');
            lb.textContent = k;
            var inp = document.createElement('input');
            inp.type = 'number'; inp.step = 'any';
            inp.value = (ap.vars && ap.vars[k] !== undefined) ? ap.vars[k] : dflt;
            inp.dataset.varname = k;
            inp.dataset.dflt = dflt;
            row.appendChild(lb); row.appendChild(inp);
            bd.appendChild(row);
            inputs.push(inp);
          });
          // 연장 즉석 조정 — 선 지표의 출력칸마다 하나 (점 지표엔 연장이 없다)
          if (ap.def.draw !== 'point') {
            var slots = [];
            try { slots = indSlots(ap.def); } catch (e) { slots = []; }
            var oi = 0;
            slots.forEach(function (s, si) {
              if (Number(s.def) === 1) return;
              oi++;
              if (Number(s.on) === 0) return;
              var dflt = String(Math.max(0, Math.min(10, +(s.ext || 0) || 0)));
              var row = document.createElement('div');
              row.className = 'dc-mvar';
              var lb = document.createElement('label');
              lb.textContent = '연장 · ' + indLabel(s.name || ('수식' + oi), ap.def, ap.vars, tf);
              lb.title = '지나간 계단(단계)을 오른쪽 끝까지 몇 개 연장할지 (0=안 함). 지표 정의는 바뀌지 않습니다';
              var inp = document.createElement('input');
              inp.type = 'number'; inp.min = '0'; inp.max = '10'; inp.step = '1';
              inp.value = (ap.exts && ap.exts[si] !== undefined) ? ap.exts[si] : dflt;
              inp.dataset.slot = si;
              inp.dataset.dflt = dflt;
              row.appendChild(lb); row.appendChild(inp);
              bd.appendChild(row);
              extInputs.push(inp);
            });
          }
          if (!inputs.length && !extInputs.length) modalNote(bd, '바꿀 변수가 없습니다.');
        },
        buttons: [
          { label: '빼기', kind: 'del', onClick: function (close) {
            delete applied[id]; push(); close();
          } },
          { label: '취소' },
          { label: '적용', kind: 'pri', onClick: function (close) {
            var vars = {};
            inputs.forEach(function (inp) {
              var v = inp.value;
              if (v === '' || +v === +inp.dataset.dflt) return;   // 그대로면 축별 기본값에 맡긴다
              vars[inp.dataset.varname] = v;
            });
            ap.vars = vars;
            var exts = {};
            extInputs.forEach(function (inp) {
              var v = inp.value;
              if (v === '' || +v === +inp.dataset.dflt) return;   // 정의값 그대로면 저장 안 함
              exts[inp.dataset.slot] = Math.max(0, Math.min(10, Math.floor(+v) || 0));
            });
            ap.exts = exts;
            push(); close();
          } }
        ]
      });
    }

    /* ── 저장된 차트 (그래프 포맷 + 일봉/주봉 + 지표 + 변수) ──
     * 차트 하나는 «한 축의 것»이다 (저장할 때의 축 = p.view.tf).
     * 그래서 목록에는 지금 축의 차트만 나오고, 축을 바꾸면 그 축의 기본 차트가 바로 뜬다. */
    function curTf() { return (list[0] && list[0].tf && list[0].tf()) || 'day'; }
    function findPreset(id) {
      for (var i = 0; i < presets.length; i++) if (presets[i].id === id) return presets[i];
      return null;
    }
    function presetTf(p) { return (p && p.view && p.view.tf) || 'day'; }   // 옛 차트는 일봉으로 본다
    function presetsFor(tf) {
      return presets.filter(function (p) { return presetTf(p) === tf; });
    }
    // 화면×축별 「마지막에 쓴 차트」 — chart_pref 의 키를 fund_day / fund_week 로 나눠 쓴다
    function prefKey(tf) { return chartKey ? chartKey + '_' + tf : ''; }
    function rememberPref(tf, id) {
      var k = prefKey(tf);
      if (!k) return;
      prefs[k] = id || 0;
      apiPost('pref_save', { chart_key: k, preset_id: String(id || 0) }).catch(function () {});
    }
    /* 이 축의 기본 차트 — ①마지막에 쓴 것 ②그 축 차트가 하나뿐이면 그것 */
    function defaultFor(tf) {
      var id = +(prefs[prefKey(tf)] || 0);
      var p = id ? findPreset(id) : null;
      if (p && presetTf(p) === tf) return id;
      var only = presetsFor(tf);
      return only.length === 1 ? only[0].id : 0;
    }
    function renderPSel() {
      var tf = curTf();
      pSel.innerHTML = '';
      var o0 = document.createElement('option');
      o0.value = '0'; o0.textContent = '차트 없음';
      pSel.appendChild(o0);
      var show = presetsFor(tf);
      // 지금 고른 차트가 이 축 목록에 없더라도(옛 차트 등) 빈칸으로 보이지 않게 끼워 넣는다
      var cur = curPreset ? findPreset(curPreset) : null;
      if (cur && show.indexOf(cur) < 0) show = show.concat([cur]);
      show.forEach(function (p) {
        var o = document.createElement('option');
        o.value = p.id; o.textContent = p.name;
        pSel.appendChild(o);
      });
      var oN = document.createElement('option');
      oN.value = 'new'; oN.textContent = '＋ 새 차트로 저장…';
      pSel.appendChild(oN);
      // 관리(삭제)는 지울 것이 있을 때만 — 늘 띄우면 빈 목록을 여는 헛걸음이 생긴다
      if (presets.length) {
        var oM = document.createElement('option');
        oM.value = 'manage'; oM.textContent = '⚙ 차트 관리(삭제)…';
        pSel.appendChild(oM);
      }
      pSel.value = String(curPreset || 0);
      pSel.title = tfName(tf) + ' 차트 — 축을 바꾸면 그 축 목록으로 바뀐다';
    }
    // 지금 화면 상태 → 저장할 값
    function curView() {
      var pb = pbar(), st = pb ? pb.state() : null;
      return { tf: curTf(), index: st ? st.index : null, bars: st ? st.bars : null };
    }
    function curItems() {
      return JSON.stringify(Object.keys(applied).map(function (k) {
        return { id: +k, vars: applied[k].vars || {}, exts: applied[k].exts || {} };
      }));
    }
    /* 저장된 차트를 화면에 적용.
     *   skipView=true → 지표 세트만 (시간축 변경 알림을 타고 들어온 재적용) */
    function applyPreset(pid, remember, skipView) {
      curPreset = pid || 0;
      var p = findPreset(curPreset);
      // ★ 그래프 포맷·시간축 먼저 — 저장이 주봉이면 주봉으로 돌아와야 한다
      if (p && p.view && !skipView) {
        restoring = true;
        try {
          var pb = pbar();
          if (pb) pb.set(p.view.tf, p.view.index, p.view.bars);
          else list.forEach(function (dc) { dc.setTf(p.view.tf || 'day'); });
        } finally { restoring = false; }
      }
      applied = {};
      if (p) {
        // 이 축의 세트가 비어 있으면 그 차트가 저장된 축의 세트를 쓴다 (옛 차트 구제)
        var set = p.sets[curTf()];
        if (!set || !set.length) set = p.sets[presetTf(p)] || [];
        set.forEach(function (it) {
          var d = defById(it.id);
          if (d) applied[it.id] = { def: d, vars: it.vars || {}, exts: it.exts || {} };
        });
      }
      push();
      renderPSel();
      if (remember) rememberPref(curTf(), curPreset);
    }
    // 새 이름으로 저장 (기존 차트가 있어도 별도로 하나 더 만든다)
    function saveAsNew() {
      var nm = prompt('저장할 차트 이름 (지표·변수·일봉/주봉을 저장합니다. 기간은 화면마다 따로 기억되며 여기에는 시작값으로만 담깁니다)');
      pSel.value = String(curPreset || 0);
      if (!nm || !nm.trim()) return;
      var tf = curTf();                    // ★저장을 누른 순간의 축 — 응답이 온 뒤엔 바뀌어 있을 수 있다
      apiPost('preset_save', {
        name: nm.trim(), tf: tf, items: curItems(), view: JSON.stringify(curView())
      }).then(function (r) {
        if (!r || !r.ok) { alert('차트 저장 실패'); return; }
        return loadPresets(true).then(function (d) {
          presets = d.presets; prefs = d.prefs;
          curPreset = r.id;
          renderPSel();
          rememberPref(tf, r.id);            // 그 축의 기본 차트로
        });
      }).catch(function (e) { alert('차트 저장 실패: ' + e); });
    }
    /* ── 차트 관리 = 지우기 ──
     * 목록(pSel)은 «지금 축»의 차트만 보여 주지만 여기는 전부 보여 준다 —
     * 지우려고 그 축으로 옮겨 갈 이유가 없고, 옮기면 그 차트가 «적용»되는 부작용까지 딸린다.
     * ★차트 하나는 일·주·월·분 세트를 «함께» 들고 있다. 분봉 자리에서 지워도 그 차트의
     *   일봉 구성까지 사라지므로, 줄에 축을 적고 확인 문구에도 밝힌다. */
    function openChartManager() {
      openModal({
        theme: cfg.theme,
        title: '차트 관리',
        sub: '저장된 차트 ' + presets.length + '개',
        build: fillManager,
        buttons: [{ label: '닫기' }]
      });
    }
    function fillManager(bd) {
      bd.textContent = '';
      if (!presets.length) {
        modalNote(bd, '저장된 차트가 없습니다. 「차트저장」으로 지금 화면을 담아 두세요.');
        return;
      }
      presets.forEach(function (p) {
        var tf = presetTf(p);
        var row = document.createElement('div');
        row.className = 'dc-mrow plain';
        var nm = document.createElement('b');
        nm.textContent = p.name + (p.id === curPreset ? ' (지금)' : '');
        var em = document.createElement('em');
        em.textContent = tfName(tf) + ' · 지표 ' + (((p.sets || {})[tf]) || []).length;
        var del = document.createElement('button');
        del.type = 'button'; del.className = 'dc-mdel'; del.textContent = '삭제';
        del.title = '이 차트를 지웁니다 — 지표 정의는 그대로 남습니다';
        del.onclick = function () {
          if (!confirm('차트 「' + p.name + '」 를 지울까요?\n'
                     + '이 차트에 담긴 일·주·월·분 구성이 함께 사라집니다.\n'
                     + '(지표 자체는 지워지지 않습니다)')) return;
          del.disabled = true;
          apiPost('preset_del', { id: String(p.id) }).then(function (r) {
            if (!r || !r.ok) { alert('삭제 실패'); del.disabled = false; return; }
            return loadPresets(true).then(function (d) {
              presets = d.presets; prefs = d.prefs || {};
              // ★지금 쓰던 차트를 지웠으면 «선택만» 푼다 — 그려져 있는 지표는 그대로 둔다
              //   (그 축에 차트가 없을 때와 같은 규칙. 손으로 고른 것이 날아가면 안 된다)
              if (curPreset === p.id) curPreset = 0;
              renderPSel();
              fillManager(bd);
            });
          }).catch(function (e) { alert('삭제 실패: ' + e); del.disabled = false; });
        };
        row.appendChild(nm); row.appendChild(em); row.appendChild(del);
        bd.appendChild(row);
      });
    }

    pSel.onchange = function () {
      if (pSel.value === 'new') { saveAsNew(); return; }
      // 관리는 «고르는» 행위가 아니다 — 선택을 되돌려 놓고 모달만 연다
      if (pSel.value === 'manage') { pSel.value = String(curPreset || 0); openChartManager(); return; }
      applyPreset(+pSel.value, true);
    };
    /* 차트저장 — 고른 차트가 없으면 이름을 물어 새로 만든다.
     * 지표 세트는 «현재 축»만 덮어쓰고(다른 축 보존), 기간·시간축은 이 차트의 기준값이 된다. */
    pSave.onclick = function () {
      if (!curPreset) { saveAsNew(); return; }
      var tf = curTf(), id = curPreset;     // ★누른 순간의 축·차트로 고정 (응답은 나중에 온다)
      apiPost('preset_save', {
        id: String(id), tf: tf, items: curItems(), view: JSON.stringify(curView())
      }).then(function (r) {
        if (!r || !r.ok) { alert('저장 실패'); return; }
        return loadPresets(true).then(function (d) {
          presets = d.presets; prefs = d.prefs;
          rememberPref(tf, id);
          renderPSel();
        });
      }).then(function () {
        pSave.textContent = '저장됨';
        setTimeout(function () { pSave.textContent = '차트저장'; }, 1200);
      }).catch(function (e) { alert('저장 실패: ' + e); });
    };

    /* ★시간축이 바뀌면 그 축의 기본 차트를 바로 띄운다 —
     * 목록에서 다시 고를 필요 없이 일봉↔주봉만 눌러도 저장해 둔 구성이 나온다.
     * 기간(그래프 개수)도 그 차트에 저장된 값으로 되살린다 (2026-08-01 — 전엔 "보던 창 유지"였는데
     * 사용자가 「저장 당시 개수도 같이」를 원해서 바꿈).
     * ★재진입 함정: 이 콜백은 기간 바 apply() «실행 도중»(dc.setTf 안)에 불린다.
     *   여기서 동기로 pb.set 을 부르면, 바깥 apply 가 루프 전에 계산해 둔 옛 기간(n)으로
     *   도로 덮어쓴다 → setTimeout(0) 으로 스택이 빈 뒤에 기간을 적용한다. */
    list.forEach(function (dc) {
      if (dc.onTf) dc.onTf(function () {
        if (restoring) return;
        var want = defaultFor(curTf());
        if (want) {
          applyPreset(want, false, true);           // 지표 세트는 지금 바로 (동기)
          var p = findPreset(want);
          if (p && p.view && ((p.view.index !== null && p.view.index !== undefined) || p.view.bars)) {
            setTimeout(function () {
              if (curPreset !== want) return;       // 그 사이 다른 차트를 골랐으면 건드리지 않는다
              var pb = pbar();
              if (!pb) return;
              // 이 축의 기간을 이 화면이 기억하고 있으면 그것이 이긴다 (차트틀 기간은 시작값일 뿐)
              if (pb.hasMemory && pb.hasMemory(curTf())) return;
              restoring = true;
              try { pb.set(p.view.tf, p.view.index, p.view.bars); }
              finally { restoring = false; }
            }, 0);
          }
          return;
        }
        // 그 축에 저장된 차트가 없으면 지금 지표를 그대로 둔다 (지우면 손으로 고른 게 날아간다)
        curPreset = 0;
        renderChips();          // 이름 속 변수 값(#1)은 이 축 기준으로 다시
        renderPSel();
      });
    });

    // 초기 로드 — 지표 목록 + 저장된 차트, 그리고 이 화면·이 축의 기본 차트 복원
    Promise.all([loadIndicators(), loadPresets()]).then(function (r) {
      defs = r[0];
      presets = r[1].presets;
      prefs = r[1].prefs || {};
      var last = defaultFor(curTf()) || (chartKey ? +(prefs[chartKey] || 0) : 0);   // 옛 단일 키 폴백
      renderPSel();
      /* ★열 때는 «이 화면이 기억한 기간»이 이긴다 — 차트틀의 기간은 그것이 없을 때의 시작값일 뿐.
       *   (차트틀은 여러 화면이 공유하므로, 열 때마다 덮어쓰면 화면마다 다른 기간을 가질 수 없다.
       *    차트틀을 «고르는» 것은 명시적 행동이라 그때는 아래 pSel 쪽에서 기간까지 적용한다.) */
      var pb0 = pbar();
      var keepView = !!(pb0 && pb0.hasMemory && pb0.hasMemory());
      if (last && findPreset(last)) applyPreset(last, false, keepView);
    });

    return {
      reload: function () {
        return Promise.all([loadIndicators(true), loadPresets(true)]).then(function (r) {
          defs = r[0]; presets = r[1].presets; prefs = r[1].prefs || {}; renderPSel();
          // 적용 중인 지표의 정의가 바뀌었을 수 있다 — 최신 정의로 바꿔 다시 그린다
          Object.keys(applied).forEach(function (id) {
            var d = defById(id);
            if (d) applied[id].def = d; else delete applied[id];
          });
          push();
        });
      },
      applied: function () { return Object.keys(applied).map(function (k) { return applied[k]; }); },
      applyPreset: applyPreset
    };
  }

  /* ── 데이터 어댑터 — 시세 API 주소는 여기 한 곳 ── */
  function fetchDaily(code, days) {
    return fetch(API + '?module=stock&action=daily&code=' + encodeURIComponent(code) + '&days=' + (days || 160),
                 { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(normalize);
  }
  /* 단타 분봉 — 우리가 쌓아 둔 원장(dt_min)에서 기간·봉단위로 받는다.
   * ★ 기존 fetchMinute() 는 <b>손대지 않는다</b> — 갤러리·미리보기가 쓰는 정본이라
   *   여기에 파라미터를 늘리면 그 동작이 조용히 바뀐다. 새 길을 하나 더 낸다.
   *   주소가 다른 이유: 이 데이터의 주인은 단타(stock/api.php)지 시세 공용 API 가 아니다.
   *   live=1 이면 장중에만 네이버 당일분을 서버가 덧대 준다(같은 분은 DB 가 이긴다).
   */
  function fetchMinuteRange(code, unit, from, to, live) {
    var q = '/stock/api.php?module=dt&action=series&code=' + encodeURIComponent(code)
          + '&unit=' + (unit || 1) + '&from=' + (from || '') + '&to=' + (to || '')
          + (live ? '&live=1' : '');
    return fetch(q, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (raw) {
        return (Array.isArray(raw) ? raw : []).map(function (x) {
          return {   // KST 벽시계를 UTC 로 취급 — fetchMinute 과 같은 'Z' 트릭
            time: Math.floor(Date.parse(x.t.replace(' ', 'T') + 'Z') / 1000),
            open: x.o, high: x.h, low: x.l, close: x.c, vol: x.v
          };
        });
      });
  }
  function fetchMinute(code) {
    return fetch(API + '?module=stock&action=minute&code=' + encodeURIComponent(code),
                 { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (raw) {
        return (Array.isArray(raw) ? raw : []).map(function (x) {
          return {   // KST 벽시계를 UTC 로 취급 → 09:00 이 09:00 으로 보인다
            time: Math.floor(Date.parse(x.t.replace(' ', 'T') + 'Z') / 1000),
            open: x.o, high: x.h, low: x.l, close: x.c, vol: x.v
          };
        });
      });
  }

  window.DailyChart = {
    load: load,
    create: create,
    fetchDaily: fetchDaily,
    fetchMinute: fetchMinute,
    fetchMinuteRange: fetchMinuteRange,   // 단타 — 쌓아 둔 분봉 원장(기간·단위는 Dt::UNITS)
    linkCrosshair: linkCrosshair,         // 같은 축 단위 차트끼리 십자선 잇기
    resampleWeek: resampleWeek,
    periodBar: periodBar,
    PERIODS: PERIODS,
    indicatorBar: indicatorBar,
    toolbarPreview: toolbarPreview,   // 차트설정 > 구성의 도구모음 미리보기
    feats: feats,                     // 화면에 심어 둔 기능 구성 읽기 (DC_FEATS)
    loadIndicators: loadIndicators,
    evalIndicator: evalIndicator,
    evalIndicatorMulti: evalIndicatorMulti,
    checkExpr: checkExpr,
    checkDef: checkDef,
    indLabel: indLabel,         // 이름의 #토큰 → 변수 값
    indTfOk: indTfOk,           // 이 지표를 이 시간축에서 보여 주나 (빈 tfs = 전 축)
    indVarNames: indVarNames,
    stepLevels: stepLevels,     // 계단선의 단계 목록 (연장 기능·검사용)
    compileExpr: exprCompile
  };
})();
