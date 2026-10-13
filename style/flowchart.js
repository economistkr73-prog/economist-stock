/* flowchart.js — 외인비 밴드 (재무분석 상세 · 밴드 차트 아래의 넓은 칸)  2026-10-08 신설
 *
 * 주가선 위에 «외인비(외국인 보유 비중 %)» 선을 겹치고, 그 기간 외인비의 최저~최고 범위에서 «낮은 구역»(아래 20%)·«높은 구역»(위 20%)을
 * 배경으로 칠한다. 외인비 선도 구역에 따라 색이 바뀌고, 차트 위의 위치 막대가 「지금 어디인가」를 먼저 말한다.
 * 보는 것 = 「외인비가 자기 범위의 어디쯤에 있을 때 주가가 어디였나 · 외인이 채우고 비우는 동안 주가가 어떻게 움직였나」.
 *
 * ★PER 밴드와 «같은 모양이 아니다». PER 은 「그 배수였다면 주가가 얼마」로 바꿀 수 있어 밴드선이 가격축에 서지만,
 *   외인비는 주가로 환산되지 않는다. 그래서 축이 둘이다 — 오른쪽 = 주가(원) · 왼쪽 = 외인비(%). 분위선은 왼쪽 축의 것이다.
 * ★차트는 DailyChart.create() 위에 얹는다(사이트의 시세 차트는 dailychart.js 로만 만든다 — bandchart.js 와 같은 방식).
 *   주가선의 재료도 밴드 차트와 같은 응답(module=band 의 px = 수정 시총 ÷ 지금 주식수)이라 두 그림의 주가선이 같다.
 * ★외인비는 «버튼을 누를 때» 받는다(module=flow&action=pull · 어느 종목이든). 예외 하나 — 이미 받아 둔 종목을 열었는데 최신 쪽이
 *   비어 있으면 그 빈 기간만 스스로 이어 받는다(한두 콜 · 한 번만). 처음 받기는 언제나 버튼이다.
 *   서버가 한 번에 10콜(200거래일)까지만 받으므로 done 이 올 때까지 이어 부르고, 그동안 「받는 중 N / 목표일」을 적는다.
 * ★구역의 경계(20%·80%)에는 백테스트 근거가 없다 — 판정이 아니라 자리를 보여 준다.
 * ★가장 최근 하루의 외인비는 잠정일 수 있다(당일 수치는 저녁에 굳는다).
 */
(function (w) {
  'use strict';

  var API = '/stock/api.php?module=flow&action=';
  /* 구역의 경계 — 그 기간 «최저~최고 범위»의 아래 20% 가 «낮은 구역», 위 20% 가 «높은 구역». 백테스트 근거는 없다(자리를 보여 줄 뿐 판정이 아니다) */
  var ZL = 0.2, ZH = 0.8;
  /* 낮음 파랑 · 가운데 보라 · 높음 빨강. ★가운데는 처음에 회색이었는데 검은 주가선과 구별이 안 됐다(사용자 실화면) — 어느 구역에서도 유채색이어야 한다 */
  var ZC = { lo: '#1565c0', mid: '#7e57c2', hi: '#d32f2f' };
  var ZF = { lo: '21,101,192', mid: '126,87,194', hi: '211,47,47' };   // 같은 색의 rgb — 선 아래 채움에 쓴다
  var FOR_COLOR = '#1565c0', PX_COLOR = '#22303f';
  var YEARS = [5, 3, 2], LSKEY = 'flowYears';

  function css() {
    if (document.getElementById('flowChartCss')) return;
    var st = document.createElement('style');
    st.id = 'flowChartCss';
    st.textContent =
      '.flow-msg{margin-left:8px;font-size:12px;font-weight:400;color:#5a6673}'
    + '.flow-msg.err{color:#c0392b}'
    + '.flow-leg .bl em{font-style:normal;color:#a0a8b2}'
    /* SUE 공시 — 이 칸에서는 «큰 화살표»만 세워 두고 마우스를 올렸을 때 배지 상자를 편다(사용자 지시 — 상자가 외인비 선을 가린다).
       배지 자체는 차트 모듈이 일봉과 같은 것을 만든다. 여기서는 «보이는 방식»만 바꾼다(이 칸 안에서만 · .flow-host 로 가둔다).
       ★상자는 자리를 그대로 차지한 채 투명해진다 — 접어 버리면 위쪽 배지(어닝쇼크)의 화살표가 주가선에서 멀어진다.
       ★마우스를 받는 것은 화살표뿐이다(상자는 pointer-events 없음) — 투명한 상자가 그 넓이만큼 크로스헤어를 먹지 않게. */
    + '.flow-host .dc-mk,.flow-host .dc-mk.lk{pointer-events:none}'
    + '.flow-host .dc-mk:not(:hover){background:transparent;box-shadow:none}'
    + '.flow-host .dc-mk:not(:hover)>*{visibility:hidden}'
    + '.flow-host .dc-mk:hover{z-index:6}'
    + '.flow-host .dc-mk::before{content:"\\25B2";position:absolute;left:50%;top:-19px;transform:translateX(-50%);font-size:20px;line-height:1;'
    + 'color:#d32f2f;pointer-events:auto;cursor:help;text-shadow:0 0 2px #fff,0 0 2px #fff,0 0 3px #fff}'
    + '.flow-host .dc-mk.sell::before{content:"\\25BC";color:#1565c0;top:auto;bottom:-19px}'
    + '.dc-ibar .flow-get{margin-left:2px}'
    + '.dc-ibar.light .flow-get[disabled]{background:#eef3f7;color:#9aa5b1;cursor:default}'
    /* 받는 동안의 진행 막대 — 몇 %·몇 거래일·남은 시간을 함께 적는다(1분 남짓 걸리는 일이라 «멈춘 것처럼» 보이면 안 된다) */
    + '.flow-prog{display:inline-flex;align-items:center;gap:8px;vertical-align:middle}'
    + '.flow-prog .tr{width:180px;height:8px;border-radius:4px;background:#e6ebf1;overflow:hidden}'
    + '.flow-prog .fi{height:100%;width:0;border-radius:4px;background:#3f5a76;transition:width .4s ease}'
    + '.flow-prog b{color:#22303f}'
    + '.flow-host{height:380px;position:relative}'
    + '.flow-zone{position:absolute;z-index:1;pointer-events:none;overflow:hidden}'
    + '.flow-zone.lo{background:rgba(21,101,192,.09);border-top:1px solid rgba(21,101,192,.45)}'
    + '.flow-zone.hi{background:rgba(211,47,47,.08);border-bottom:1px solid rgba(211,47,47,.45)}'
    + '.flow-zone span{position:absolute;right:6px;font-size:10.5px;line-height:1;white-space:nowrap}'
    + '.flow-zone.lo span{top:4px;color:#1565c0}.flow-zone.hi span{bottom:4px;color:#d32f2f}'
    + '.flow-gauge{margin:2px 0 10px;font-size:12px;color:#5a6673}'
    + '.flow-gauge[hidden]{display:none}'
    + '.flow-gauge .fg-row{display:flex;align-items:center;gap:10px}'
    + '.flow-gauge .fg-end{flex:0 0 auto;white-space:nowrap}.flow-gauge .fg-end b{color:#22303f}'
    + '.flow-gauge em{font-style:normal;color:#a0a8b2;margin-left:4px;font-size:11px}'
    + '.flow-gauge .fg-track{position:relative;flex:1;height:12px;border-radius:6px;background:#eef1f5}'
    + '.flow-gauge .fg-lo{position:absolute;left:0;top:0;bottom:0;border-radius:6px 0 0 6px;background:rgba(21,101,192,.35)}'
    + '.flow-gauge .fg-hi{position:absolute;right:0;top:0;bottom:0;border-radius:0 6px 6px 0;background:rgba(211,47,47,.35)}'
    + '.flow-gauge .fg-dot{position:absolute;top:50%;width:18px;height:18px;margin:-9px 0 0 -9px;border-radius:50%;border:3px solid #fff;'
    + 'box-sizing:border-box;box-shadow:0 1px 4px rgba(20,40,70,.4);background:#7e57c2}'
    + '.flow-gauge .fg-dot.lo{background:#1565c0}.flow-gauge .fg-dot.hi{background:#d32f2f}'
    + '.flow-gauge .fg-now{margin-top:6px;text-align:center;font-size:13px;color:#22303f}'
    + '.flow-gauge .fg-now b{font-size:15px}.flow-gauge b.lo{color:#1565c0}.flow-gauge b.hi{color:#d32f2f}.flow-gauge b.mid{color:#22303f}';
    document.head.appendChild(st);
  }
  function pad2(n) { return (n < 10 ? '0' : '') + n; }
  function tkey(t) {
    if (t === null || t === undefined) return '';
    if (typeof t === 'string') return t;
    if (typeof t === 'number') { var d = new Date(t * 1000); return d.getUTCFullYear() + '-' + pad2(d.getUTCMonth() + 1) + '-' + pad2(d.getUTCDate()); }
    if (typeof t === 'object' && t.year) return t.year + '-' + pad2(t.month) + '-' + pad2(t.day);
    return String(t);
  }
  function fmtDate(s) { return s ? String(s).slice(2).replace(/-/g, '.') : ''; }
  function pct(v) { return (+v).toFixed(2) + '%'; }
  function quant(sorted, q) {                       // 선형 보간 분위수
    if (!sorted.length) return null;
    var p = (sorted.length - 1) * q, lo = Math.floor(p), hi = Math.ceil(p);
    return sorted[lo] + (sorted[hi] - sorted[lo]) * (p - lo);
  }
  function post(action, body) {
    return fetch(API + action, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
                                 body: JSON.stringify(body) })
      .then(function (r) { return r.json(); })
      .catch(function () { throw new Error('응답을 읽지 못했습니다 (로그인이 풀렸을 수 있습니다)'); })
      .then(function (j) { if (!j || !j.ok) throw new Error((j && j.error) || '처리하지 못했습니다.'); return j.data; });
  }
  function get(code, years) {
    return fetch(API + 'get&code=' + encodeURIComponent(code) + '&years=' + years, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (!j || !j.ok) throw new Error((j && j.error) || '외인비를 읽지 못했습니다.'); return j.data; });
  }

  /**
   * @param code 종목코드
   * @param ids  {host, legend, bar, btn, msg} 요소 id
   */
  function mount(code, ids) {
    css();
    var host = document.getElementById(ids.host), leg = document.getElementById(ids.legend),
        bar = document.getElementById(ids.bar), btn = document.getElementById(ids.btn), msg = document.getElementById(ids.msg);
    if (!host) return;
    /* 공시 배지 끄기·켜기 — 일봉 차트의 「SUE 공시 N」 버튼과 같은 일을 한다(그 버튼은 기간 바에 사는데 이 칸엔 기간 바가 없다).
       배지가 외인비 선을 가릴 때 끈다. 고른 것은 이 칸이 기억한다. */
    var sueOn = true;
    try { sueOn = localStorage.getItem('flowSue') !== '0'; } catch (e0) {}
    var sueBtn = document.createElement('button');
    sueBtn.type = 'button'; sueBtn.className = 'flow-sue'; sueBtn.hidden = true;
    sueBtn.title = '정기공시(▲어닝서프라이즈 · ▼어닝쇼크) 화살표를 보이거나 숨깁니다 — 화살표에 마우스를 올리면 내용이 나옵니다';
    function paintSue() {
      sueBtn.className = 'flow-sue' + (sueOn ? ' on' : '');
      var n = (dc && dc.sueMarkerCount) ? dc.sueMarkerCount() : 0;
      sueBtn.textContent = 'SUE 공시' + (n ? ' ' + n : '');   // 일봉 차트의 버튼과 같은 이름(사용자 지적)
    }
    sueBtn.addEventListener('click', function () {
      sueOn = !sueOn;
      try { localStorage.setItem('flowSue', sueOn ? '1' : '0'); } catch (e1) {}
      if (dc && dc.setSue) dc.setSue(sueOn);
      paintSue();
    });
    // 받기 버튼을 지표 바 틀(dc-ibar)에 넣어 「차트저장」과 같은 버튼으로 만든다
    if (btn && btn.parentNode) {
      var ib = document.createElement('span');
      ib.className = 'dc-ibar light';
      btn.parentNode.insertBefore(ib, btn);
      ib.appendChild(btn);
      btn.className = 'dc-iapply flow-get';
    }

    // 위치 막대는 모듈이 차트 «위»에 만든다(화면은 자리만 내주면 된다)
    var gauge = document.createElement('div');
    gauge.className = 'flow-gauge'; gauge.hidden = true;
    host.parentNode.insertBefore(gauge, host);
    var y = 5, dc = null, busy = false, last = null, autoTop = false;
    try { var sv = +localStorage.getItem(LSKEY); if (YEARS.indexOf(sv) >= 0) y = sv; } catch (e) {}

    function say(t, err) { if (msg) { msg.textContent = t || ''; msg.className = 'flow-msg' + (err ? ' err' : ''); } }
    /* 윗줄은 일봉 차트의 기간 바와 «같은 틀»이다(사용자 지시) — 클래스를 차트 모듈의 것(dc-pbar · dc-pdiv · dc-iapply)으로 써서
       모양을 여기 다시 적지 않는다. [5년 3년 2년 ┃ 공시 배지 N] 이 한 틀에 들고, 받기 버튼은 「차트저장」과 같은 진한 버튼이다. */
    function paintBar() {
      if (!bar) return;
      bar.className = 'dc-pbar light';
      bar.innerHTML = YEARS.map(function (v) {
        return '<button type="button"' + (v === y ? ' class="on"' : '') + ' data-y="' + v + '">' + v + '년</button>';
      }).join('');
      if (!sueBtn.hidden) {
        var dv = document.createElement('span');
        dv.className = 'dc-pdiv';
        bar.appendChild(dv);
        bar.appendChild(sueBtn);
      }
    }
    /* 버튼의 글자는 서버의 적재 상태가 정한다 — need: new 처음 · top 최신 쪽이 비었다 · back 과거를 더 받을 수 있다 · '' 다 받았다 */
    function paintBtn(d) {
      if (!btn) return;
      var s = d.status || {}, need = s.need;
      btn.hidden = false; btn.disabled = false;
      if (!d.allowed) { btn.disabled = true; btn.textContent = '외인비 받기'; btn.title = '종목 마스터에 없는 코드라 받을 수 없습니다'; }
      else if (!d.key) { btn.disabled = true; btn.textContent = '외인비 받기'; btn.title = '키움 인증키가 없습니다'; }
      else if (need === 'new')  { btn.textContent = '외인비 받기';   btn.title = '키움에서 약 ' + d.target + '거래일(5년)을 받아 저장합니다 — 1분 남짓 걸립니다'; }
      else if (need === 'top')  { btn.textContent = '최신까지 받기'; btn.title = s.to + ' 이후가 비어 있습니다 — 빈 기간만 이어 받습니다'; }
      else if (need === 'back') { btn.textContent = '과거 더 받기';  btn.title = '지금 ' + s.days + '거래일 — ' + d.target + '거래일까지 과거로 더 받습니다'; }
      else { btn.disabled = true; btn.textContent = '다 받았음'; btn.title = s.days + '거래일 (' + s.from + ' ~ ' + s.to + ')'; }
      if (!busy) say(s.days ? s.days.toLocaleString() + '거래일 · ' + fmtDate(s.from) + ' ~ ' + fmtDate(s.to) : '');
    }

    function clear() {
      if (dc && dc.chart) { try { dc.chart.remove(); } catch (e) {} }
      dc = null; host.innerHTML = ''; if (leg) leg.innerHTML = ''; gauge.hidden = true; gauge.innerHTML = ''; sueBtn.hidden = true; paintBar();
    }
    function empty(text) { clear(); host.innerHTML = '<div class="band-empty">' + text + '</div>'; }

    function draw(d, band) {
      clear();
      var rows = d.rows || [];
      if (!rows.length) {
        empty(d.allowed ? '아직 받은 외인비가 없습니다 — 위의 「외인비 받기」를 누르면 키움에서 받아 저장합니다.'
                        : '종목 마스터에 없는 코드라 외인비를 받을 수 없습니다.');
        return;
      }
      var shrs = +band.shrs > 0 ? +band.shrs : 1, px = band.px || [];
      if (!px.length) { empty('주가선을 그릴 자료가 없습니다.'); return; }
      /* code 를 주면 SUE 공시 배지(어닝서프라이즈·어닝쇼크)는 차트 모듈이 일봉과 «같은 모양·같은 자리(공시 다음 거래일)»로 얹는다
 *       — 여기서 다시 만들지 않는다(같은 사실을 화면마다 다른 모양으로 보이면 그것이 곧 다른 말이다).
         draw:false = 수평선 «도구»는 달지 않는다(이 칸은 보는 자리다 · 그 종목에 이미 그어 둔 선은 주가 축에 그대로 선다). */
      dc = w.DailyChart.create(ids.host, { theme: 'light', volume: false, screen: '', key: '', padPct: 0.06, code: code, draw: false });
      if (!dc) { empty('차트 라이브러리를 불러오지 못했습니다.'); return; }
      if (!sueOn) dc.setSue(false);
      sueBtn.hidden = false; paintSue(); paintBar();
      setTimeout(paintSue, 600); setTimeout(paintSue, 2000);   // 공시는 뒤늦게 온다 — 건수는 온 뒤에 적는다
      /* h·l 을 «null» 로 싣는다(빼지 않는다) — 주가선은 종가뿐인 선인데, 차트 모듈은 고·저가 «아예 없는» 봉에는 공시 배지의
         세로 자리를 잡지 못해 화살표만 남긴다. null 이면 종가를 자리로 쓴다(선 폴백은 그대로다). */
      dc.setData(px.map(function (p) { return { t: p.t, c: p.v / shrs, h: null, l: null }; }));

      // 외인비 선 — 왼쪽 축(%). 주가선이 있는 날만 싣는다(시간축이 주가선의 것이라야 두 선이 같은 날에 선다)
      var has = {}; px.forEach(function (p) { has[p.t] = p.v / shrs; });
      var fr = rows.filter(function (r) { return has[r.t] !== undefined; });
      if (!fr.length) { empty('받은 외인비가 이 기간(' + y + '년)과 겹치지 않습니다.'); return; }
      dc.chart.applyOptions({ leftPriceScale: { visible: true, borderColor: '#dde3ea' } });
      /* 주가선과 «모양부터» 다르게 — 외인비는 굵은 실선(4px) + 선 아래 옅은 채움(면), 주가는 가는 점선(1px). 이 칸의 주인공은 외인비다. */
      // 주가는 가는 «점선»(lineStyle 2), 외인비는 가장 굵은 실선(4px) — 사용자 지시
      if (dc.main && dc.main()) { try { dc.main().applyOptions({ lineWidth: 1, lineStyle: 2 }); } catch (e0) {} }
      var line = dc.chart.addAreaSeries({ priceScaleId: 'left', lineColor: FOR_COLOR, topColor: 'rgba(21,101,192,.2)', bottomColor: 'rgba(21,101,192,.02)', lineWidth: 4, priceLineVisible: false,
        lastValueVisible: true, crosshairMarkerVisible: true,
        priceFormat: { type: 'custom', minMove: 0.01, formatter: function (v) { return (+v).toFixed(2) + '%'; } } });
      /* ── 낮은 구역 · 높은 구역 ───────────────────────────────────────
       * 그 기간에 «받아 둔» 외인비 분포의 하위 ZL · 상위 (1−ZH) 를 구역으로 삼는다. 처음엔 분위선 다섯 개를 그었는데
       * 「높은지 낮은지 한눈에 안 들어온다」(사용자 실화면) — 선은 숫자를 범례와 맞춰 봐야 뜻이 나온다.
       * 그래서 ①배경을 두 구역으로 칠하고 ②외인비 선의 색을 구역에 따라 바꾸고 ③맨 위에 위치 막대를 둔다. */
      var vals = fr.map(function (r) { return r.r; }), sorted = vals.slice().sort(function (a, b) { return a - b; });
      /* ★구역은 «최저~최고 범위»로 나눈다(같은 날 사용자 결정 — 처음엔 날의 순위(분위)였다). 범위의 아래 ZL 이 낮은 구역, 위 (1−ZH) 가 높은 구역.
         외인비는 천천히 움직여 하루 튄 값이 범위를 늘리는 일이 드물고, 이렇게 해야 두 구역의 높이가 같아 «눈에 보이는 높낮이»와 구역이 맞는다.
         대신 「그 구역에 머문 날」의 비율은 종목마다 달라진다 — 그 수를 범례에 적는다(정보다). */
      var lo0 = sorted[0], hi0 = sorted[sorted.length - 1], span = hi0 - lo0;
      var qLo = lo0 + span * ZL, qHi = lo0 + span * ZH;
      function zoneOf(v) { return span <= 0 ? 'mid' : (v <= qLo ? 'lo' : (v >= qHi ? 'hi' : 'mid')); }
      var nLo = vals.filter(function (v) { return zoneOf(v) === 'lo'; }).length, nHi = vals.filter(function (v) { return zoneOf(v) === 'hi'; }).length;
      line.setData(fr.map(function (r) {
        var zq = zoneOf(r.r);
        return { time: r.t, value: r.r, lineColor: ZC[zq], topColor: 'rgba(' + ZF[zq] + ',.16)', bottomColor: 'rgba(' + ZF[zq] + ',.02)' };
      }));
      /* ── 경계를 «넘어선 만큼»만 진하게 칠한다 ──
       * 높은 구역: 경계선(qHi)과 그 위로 올라간 외인비 선 «사이» · 낮은 구역: 경계선(qLo)과 그 아래로 내려간 선 «사이».
       * 처음엔 그 구간의 선 아래를 바닥까지 칠했는데 사용자가 가리킨 것은 이 자리였다(실화면 캡처의 빨간 상자) —
       * 「경계를 얼마나, 얼마 동안 넘었나」가 면적으로 보인다. 기준선 시리즈(baseline) 둘을 겹쳐 만든다: 선은 투명, 한쪽 채움만 쓴다. */
      if (span > 0) {
        var pts = fr.map(function (r) { return { time: r.t, value: r.r }; });
        var none = 'rgba(0,0,0,0)';
        var common = { priceScaleId: 'left', lineWidth: 1, priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false,
                       topLineColor: none, bottomLineColor: none };
        var fh = dc.chart.addBaselineSeries(Object.assign({}, common, { baseValue: { type: 'price', price: qHi },
                   topFillColor1: 'rgba(' + ZF.hi + ',.62)', topFillColor2: 'rgba(' + ZF.hi + ',.5)', bottomFillColor1: none, bottomFillColor2: none }));
        var fl = dc.chart.addBaselineSeries(Object.assign({}, common, { baseValue: { type: 'price', price: qLo },
                   topFillColor1: none, topFillColor2: none, bottomFillColor1: 'rgba(' + ZF.lo + ',.5)', bottomFillColor2: 'rgba(' + ZF.lo + ',.62)' }));
        fh.setData(pts); fl.setData(pts);
      }

      var mn = fr[0], mx = fr[0];
      fr.forEach(function (r) { if (r.r < mn.r) mn = r; if (r.r > mx.r) mx = r; });
      [mn, mx].forEach(function (r) {
        line.createPriceLine({ price: r.r, color: '#8a96a3', lineWidth: 1, lineStyle: 2, axisLabelVisible: false });
      });
      var cur = fr[fr.length - 1];
      var rank = span > 0 ? Math.round((cur.r - lo0) / span * 100) : 50;   // 최저 0 ~ 최고 100 사이의 자리(값의 비례)

      /* 배경 구역 — HTML 층(차트 라이브러리에 띠 채우기가 없다). 가격축이 줌·스크롤로 움직이므로 그때마다 다시 놓는다.
         pointer-events 없음 — 마우스를 먹으면 크로스헤어가 끊긴다(밴드 차트의 배지 층과 같은 규칙). */
      var zl = document.createElement('div'), zh = document.createElement('div');
      zl.className = 'flow-zone lo'; zh.className = 'flow-zone hi';
      zl.innerHTML = '<span>낮은 구역 · 범위의 아래 ' + Math.round(ZL * 100) + '% (' + pct(qLo) + ' 이하)</span>';
      zh.innerHTML = '<span>높은 구역 · 범위의 위 ' + Math.round((1 - ZH) * 100) + '% (' + pct(qHi) + ' 이상)</span>';
      host.appendChild(zl); host.appendChild(zh);
      var raf = 0, mine = dc, tries = 0;
      function place() {
        raf = 0;
        if (dc !== mine) return;                         // 다시 그려져 이 차트는 치워졌다
        /* 차트가 첫 배치를 끝내기 전에는 라이브러리가 축 너비를 물으면 예외를 던진다(「Value is null」) — 그때는 조용히 넘긴다.
           rAF 는 안 보이는 탭에서 돌지 않으므로 타이머로 다시 부른다. */
        try { placeNow(); tries = 0; } catch (e) { if (++tries < 20) setTimeout(place, 150); }   // 못 놓았으면 잠시 뒤 다시(끝없이 돌지는 않는다)
      }
      function placeNow() {
        var yLo = line.priceToCoordinate(qLo), yHi = line.priceToCoordinate(qHi);
        var lw = mine.chart.priceScale('left').width(), rw = mine.chart.priceScale('right').width();
        var paneH = host.clientHeight - mine.chart.timeScale().height();
        [zl, zh].forEach(function (z) { z.style.left = lw + 'px'; z.style.right = rw + 'px'; });
        /* 공시 화살표 층을 왼쪽 축 너비만큼 민다 — 차트 모듈은 왼쪽 축이 없는 차트만 그려 봐서 배지를 «차트 왼쪽 끝» 기준으로 놓는다.
           이 칸은 왼쪽에 외인비 축이 있어 그대로 두면 화살표가 그 너비만큼 왼쪽(= 며칠 앞)에 선다. */
        var ml = host.querySelector('.dc-mk-layer');
        if (ml) ml.style.left = lw + 'px';
        if (yLo === null || yLo >= paneH) zl.style.display = 'none';
        else { zl.style.display = ''; zl.style.top = Math.max(0, yLo) + 'px'; zl.style.height = (paneH - Math.max(0, yLo)) + 'px'; }
        if (yHi === null || yHi <= 0) zh.style.display = 'none';
        else { zh.style.display = ''; zh.style.top = '0px'; zh.style.height = Math.min(paneH, yHi) + 'px'; }
      }
      function soon() { if (!raf) raf = requestAnimationFrame(place); }
      mine.chart.timeScale().subscribeVisibleLogicalRangeChange(soon);
      ['mousemove', 'mouseup', 'wheel', 'touchmove', 'touchend'].forEach(function (ev) { host.addEventListener(ev, soon, { passive: true }); });
      if (w.ResizeObserver) new ResizeObserver(soon).observe(host);
      /* 공시 층은 뒤늦게 생긴다 — 생기는 순간 자리를 맞춘다. ★여기는 rAF 가 아니라 곧바로 부른다: 화면이 안 보이는 탭에서는 rAF 가
         돌지 않아, 탭을 열어 둔 채 받아 온 공시의 화살표가 보정 없이 서 있게 된다. */
      if (w.MutationObserver) new MutationObserver(function () { setTimeout(place, 0); }).observe(host, { childList: true });
      soon(); setTimeout(soon, 80); setTimeout(soon, 400);

      /* 위치 막대 — 차트를 읽기 전에 「지금이 낮은 쪽인가 높은 쪽인가」부터. 가로축은 최저(왼쪽 끝)~최고(오른쪽 끝) 사이의 «값의 비례»다 —
         구역도 같은 범위로 나누므로 막대의 구역 눈금·표식·차트의 배경이 전부 같은 말을 한다. */
      var z = zoneOf(cur.r), zname = { lo: '낮은 구역', mid: '가운데', hi: '높은 구역' }[z];
      gauge.innerHTML =
          '<div class="fg-row"><span class="fg-end">최저 <b>' + pct(mn.r) + '</b><em>' + fmtDate(mn.t) + '</em></span>'
        + '<span class="fg-track"><i class="fg-lo" style="width:' + (ZL * 100) + '%"></i><i class="fg-hi" style="width:' + ((1 - ZH) * 100) + '%"></i>'
        + '<i class="fg-dot ' + z + '" style="left:' + Math.max(1, Math.min(99, rank)) + '%"></i></span>'
        + '<span class="fg-end">최고 <b>' + pct(mx.r) + '</b><em>' + fmtDate(mx.t) + '</em></span></div>'
        + '<div class="fg-now">현재 <b class="' + z + '">' + pct(cur.r) + '</b> — ' + y + '년 최저~최고 범위의 <b>' + rank + '% 지점</b> · <b class="' + z + '">' + zname + '</b>'
        + ' <em>' + fmtDate(cur.t) + ' 기준 · 받아 둔 ' + fr.length.toLocaleString() + '거래일</em></div>';
      gauge.hidden = false;

      // 판독기 — 커서가 짚은 날(없으면 최신)
      var at = {}; fr.forEach(function (r) { at[r.t] = r; });
      var read = document.createElement('div');
      read.className = 'band-cur';
      host.appendChild(read);
      function show(t) {
        var r = at[t];
        if (!r) { read.innerHTML = ''; return; }
        var zz = zoneOf(r.r);
        read.innerHTML = '<b>' + fmtDate(t) + '</b> 외인비 <b class="v" style="color:' + ZC[zz] + '">' + pct(r.r) + '</b>'
          + (zz === 'mid' ? '' : ' <span style="color:' + ZC[zz] + '">' + (zz === 'lo' ? '낮은 구역' : '높은 구역') + '</span>')
          + ' <span class="na">·</span> ' + Math.round(has[t]).toLocaleString() + '원'
          + ' <span class="na">· 외인 순매수</span> ' + (r.n > 0 ? '+' : '') + r.n.toLocaleString() + '주';
      }
      show(cur.t);
      mine.chart.subscribeCrosshairMove(function (p) { var t = tkey(p && p.time); show(at[t] ? t : cur.t); });

      if (leg) {
        var h = '<span class="bl"><i style="background:none;height:0;border-top:2px dashed ' + PX_COLOR + '"></i>주가(오른쪽 축 · 점선)</span>'
              + '<span class="bl">외인비(왼쪽 축 · 굵은 실선) —</span>'
              + '<span class="bl" title="최저~최고 범위의 아래 ' + Math.round(ZL * 100) + '% — 이 구역에 머문 날의 비율을 함께 적습니다"><i style="background:' + ZC.lo + '"></i>낮은 구역 ' + pct(qLo) + ' 이하 <em>(머문 날 ' + Math.round(nLo / vals.length * 100) + '%)</em></span>'
              + '<span class="bl"><i style="background:' + ZC.mid + '"></i>가운데</span>'
              + '<span class="bl" title="최저~최고 범위의 위 ' + Math.round((1 - ZH) * 100) + '% — 이 구역에 머문 날의 비율을 함께 적습니다"><i style="background:' + ZC.hi + '"></i>높은 구역 ' + pct(qHi) + ' 이상 <em>(머문 날 ' + Math.round(nHi / vals.length * 100) + '%)</em></span>'
              + '<span class="bl muted" title="회색 점선 — 받아 둔 일별 외인비의 실제 최저·최고">회색 점선 = 기간 최저·최고</span>'
              + '<span class="bl muted" title="일봉 차트와 같은 정기공시 — 공시 다음 거래일 자리. 화살표에 마우스를 올리면 SUE·그때 PER·PBR 이 나옵니다"><b style="color:#d32f2f">▲</b> 어닝서프라이즈 <b style="color:#1565c0">▼</b> 어닝쇼크 (마우스를 올리면 내용)</span>';
        if (fr[0].t > px[0].t) {
          h += '<span class="bl muted" title="주가선은 ' + fmtDate(px[0].t) + ' 부터인데 외인비는 그 뒤부터 받아 두었습니다">외인비는 '
             + fmtDate(fr[0].t) + ' 부터</span>';
        }
        leg.innerHTML = h;
      }
    }

    function load() {
      paintBar();
      return w.DailyChart.load()
        .then(function () { return Promise.all([get(code, y), w.BandChart.fetch(code, y)]); })
        .then(function (a) {
          last = a[0]; paintBtn(a[0]); draw(a[0], a[1] || {});
          /* 최신 쪽이 비었으면 «열 때» 한 번 스스로 이어 받는다(2026-10-09 사용자 결정) — 빈 기간만이라 한두 콜이다.
             처음 받기·과거 더 받기(1분 걸리는 일)는 여전히 버튼이다. 한 번만 시도한다 — 실패를 되풀이해 열 때마다 콜을 태우지 않는다. */
          var st = a[0].status || {};
          if (!autoTop && !busy && a[0].allowed && a[0].key && st.need === 'top') { autoTop = true; pull(); }
        })
        .catch(function (e) { empty((e && e.message) || '외인비를 불러오지 못했습니다.'); });
    }

    /* 받기 — done 이 올 때까지 이어 부른다. 한 번의 응답마다 얼마나 쌓였는지 적는다. 실패하면 받은 데까지는 남는다. */
    function pull() {
      if (busy) return;
      busy = true; btn.disabled = true; btn.textContent = '받는 중…';
      var cursor = '', n = 0, t0 = Date.now();
      var s0 = (last && last.status && last.status.days) || 0, tg = (last && last.target) || 0;
      /* 진행 막대 — 분모는 목표 거래일, 분자는 지금 쌓인 거래일. 남은 시간은 «이번에 실제로 걸린 속도»로 어림한다.
         상장한 지 얼마 안 된 종목은 목표에 못 미쳐 끝난다 — 그때는 100% 로 닫는다(멈춘 막대를 남기지 않는다). */
      function prog(days, target, fin) {
        if (!msg) return;
        var p = fin ? 100 : Math.max(2, Math.min(99, Math.round(days / Math.max(1, target) * 100)));
        var got = days - s0, el = (Date.now() - t0) / 1000, left = '';
        if (!fin && got > 0 && el > 1) {
          var sec = Math.round((target - days) / (got / el));
          left = ' · 약 ' + (sec >= 60 ? Math.floor(sec / 60) + '분 ' : '') + (sec % 60) + '초 남음';
        }
        msg.className = 'flow-msg';
        msg.innerHTML = '<span class="flow-prog"><span class="tr"><span class="fi" style="width:' + p + '%"></span></span>'
          + '<b>' + p + '%</b> ' + days.toLocaleString() + ' / ' + target.toLocaleString() + '거래일' + left + '</span>';
      }
      prog(s0, tg || 1, false);
      (function step() {
        return post('pull', { code: code, cursor: cursor }).then(function (d) {
          n++;
          tg = d.target;
          prog(d.days || 0, d.target, !!d.done);
          cursor = d.cursor || '';
          if (!d.done && n < 12) return step();
        });
      })().then(function () { busy = false; return load(); })
          .catch(function (e) { busy = false; load().then(function () { say((e && e.message) || '받지 못했습니다.', true); }); });
    }

    if (bar) bar.addEventListener('click', function (e) {
      var b = e.target && e.target.closest ? e.target.closest('[data-y]') : null;
      if (!b) return;
      var v = +b.getAttribute('data-y');
      if (!v || v === y) return;
      y = v;
      try { localStorage.setItem(LSKEY, String(y)); } catch (e2) {}
      load();
    });
    if (btn) btn.addEventListener('click', pull);
    return load();
  }

  w.FlowChart = { mount: mount };
})(window);
