/* bandchart.js — PER·PBR 밴드 차트 (구성 ⑥ 「밴드형」)  2026-08-03 신설
 *
 * 「주가 = 배수 × 주당지표」를 뒤집어, 이익·자본에 배수를 곱한 선 다섯 개 위에 실제 시세를 얹는다.
 * 주가선이 어느 띠에 있느냐 = 그 종목이 «자기 역사» 대비 싼가 비싼가.
 *
 * ★ 이 파일은 차트를 «직접» 그리지 않는다 — DailyChart.create()/addLine() 위에 얹는 얇은 층이다.
 *   (사이트의 모든 시세 차트는 style/dailychart.js 로만 만든다는 규칙 그대로.)
 *   dailychart.js 를 건드리지 않은 이유: addLine 이 이미 ⒜계단선(stepped) ⒝null 끊김
 *   ⒞가격축 참여를 다 해 준다. 밴드에 필요한 건 그게 전부다.
 *
 * ★★ 서버가 넘겨주는 값의 단위는 «시가총액»이다(stock/lib/band.php).
 *   주당으로 풀면 과거 5년치 수정주가가 필요한데 우리에겐 없다. 시총으로 두면 주식수가 식에서
 *   사라져 액면분할이 저절로 무해해진다. 여기서 ÷상장주식수 하는 것은 «표시 단위 환산»일 뿐이라
 *   밴드와 주가선이 같은 비율로 움직여 그림이 안 깨진다.
 *
 * 계단이 꺾이는 날 = DART 공시 «다음 거래일». 차트의 ▲▼ SUE 마커와 같은 날이다(선견 편향 없음).
 */
(function (w) {
  'use strict';

  var API = '/stock_analysis_api.php';

  // 낮은 배수 → 높은 배수. FnGuide 관례와 같은 순서(아래가 싸고 위가 비싸다)
  var COLORS = ['#009aa6', '#5aa832', '#e08a1e', '#d6453c', '#8e5bc4'];
  // 주가선 색은 dailychart.js 의 «선 폴백» 색 그대로다 — 범례가 실제 선과 달라지면 안 된다
  var PX_COLOR = '#22303f';

  /* 모듈이 «만드는» 요소의 CSS 는 모듈이 들고 다닌다 — 종목상세든 갤러리든 모양이 같아야 한다.
   * (화면 쪽 CSS 에 두면 갤러리에서 판독기가 자리를 못 잡고 차트 위로 흘러내린다.) */
  function css() {
    if (document.getElementById('bandChartCss')) return;
    var st = document.createElement('style');
    st.id = 'bandChartCss';
    st.textContent =
      '.band-bar{display:inline-flex;gap:4px}'
    + '.band-bar .band-y{font:inherit;font-size:12px;line-height:1;padding:4px 9px;cursor:pointer;'
    + 'color:#5a6673;background:#fff;border:1px solid #dde3ea;border-radius:4px}'
    + '.band-bar .band-y.on{color:#fff;background:#3f5a76;border-color:#3f5a76}'
    + '.band-cur{position:absolute;left:8px;top:6px;z-index:3;pointer-events:none;'
    + 'font-size:11px;line-height:1.5;color:#5a6673;background:rgba(255,255,255,.88);'
    + 'border:1px solid #e6ebf1;border-radius:4px;padding:2px 7px;white-space:nowrap}'
    + '.band-cur .v{color:#22303f;font-size:12px}'
    + '.band-cur .na{color:#a0a8b2}'
    + '.band-leg .st em{font-style:normal;color:#a0a8b2}'
    /* 공시 배지 — 색은 일봉 SUE 칩(dailychart .dc-mk.buy/.sell)과 같은 빨강·파랑 */
    + '.band-mk-layer{position:absolute;left:0;top:0;right:0;bottom:0;z-index:2;pointer-events:none;overflow:hidden}'
    + '.band-mk{position:absolute;width:26px;height:26px;margin:-13px 0 0 -13px;border-radius:50%;'
    + 'display:flex;align-items:center;justify-content:center;font-size:9.5px;font-weight:700;line-height:1;'
    + 'letter-spacing:-.2px;color:#fff;border:2px solid #fff;box-sizing:border-box;box-shadow:0 1px 4px rgba(20,40,70,.35)}'
    + '.band-mk.hit{background:#d32f2f}.band-mk.shock{background:#1565c0}.band-mk.mid{background:#8a96a3}'
    + '.band-mk.na{background:#e3e8ee;color:#7a8794}'
    + '.band-leg .bl i.mk-i{width:10px;height:10px;border-radius:50%;margin-right:-2px}';
    document.head.appendChild(st);
  }

  function fetchSeries(code, years) {
    return fetch(API + '?module=band&action=series&code=' + encodeURIComponent(code)
                 + '&years=' + (years || 5))
      .then(function (r) { return r.json(); });
  }

  function fmtEok(v) {
    if (v === null || v === undefined || !isFinite(v)) return '-';
    return Math.round(v / 1e8).toLocaleString() + '억';
  }

  function fmtWon(v) {
    if (v === null || v === undefined || !isFinite(v)) return '-';
    return Math.round(v).toLocaleString() + '원';
  }

  // 2026-05-18 → 26.05.18 (범례·판독기는 자리가 좁다)
  function fmtDate(s) { return s ? String(s).slice(2).replace(/-/g, '.') : ''; }

  function pad2(n) { return (n < 10 ? '0' : '') + n; }

  /**
   * 크로스헤어의 time 을 'YYYY-MM-DD' 로 되돌린다.
   *
   * ★ 세 가지를 모두 받는다 — 일봉은 넣은 문자열이 그대로 오지만, LWC 버전에 따라
   *   BusinessDay 객체나 UTCTimestamp(초)로 정규화될 수 있다. 한 형태만 받아 두면
   *   조회가 빗나가도 «판독기가 계속 최신값만 띄우는» 조용한 실패가 된다.
   */
  function tkey(t) {
    if (t === null || t === undefined) return '';
    if (typeof t === 'string') return t;
    if (typeof t === 'number') {
      var d = new Date(t * 1000);
      return d.getUTCFullYear() + '-' + pad2(d.getUTCMonth() + 1) + '-' + pad2(d.getUTCDate());
    }
    if (typeof t === 'object' && t.year) return t.year + '-' + pad2(t.month) + '-' + pad2(t.day);
    return String(t);
  }

  /* ── 공시 배지 — 계단이 꺾이는 그 자리에 «그때 PER·PBR» 를 원 안에 (2026-09-04) ──
   * 값·색의 재료는 서버(band.php `marks`)가 정한다 — 색 문턱(hit/shock)도 응답에 실려 온다(Thr 단일본).
   * ★HTML 층이다 — 가격축 autoscale 에 안 잡히고, 스크롤·줌·리사이즈를 따라 다시 놓는다(dailychart 의 칩과 같은 방식).
   * ★pointer-events 없음 — 배지가 마우스를 먹으면 그 자리에서 크로스헤어·드래그가 끊긴다. 분기·공시일·SUE 는 판독기가 말한다. */
  function fmtMult(v, kind) {
    if (v === null || v === undefined || !isFinite(v)) return '–';
    if (kind === 'per') return v >= 100 ? String(Math.round(v)) : v.toFixed(1);
    return v >= 10 ? v.toFixed(1) : v.toFixed(2);
  }
  function badges(dc, host, data, kind, at) {
    var marks = (data && data.marks) || [];
    if (!marks.length || typeof dc.main !== 'function') return 0;
    var hit = +data.hit || 1, shock = +data.shock || -1;
    var layer = document.createElement('div');
    layer.className = 'band-mk-layer';
    host.appendChild(layer);
    var items = [];
    marks.forEach(function (m) {
      var r = at[m.t];
      if (!r) return;                                // 계단날이 주가선에 없다 — 그릴 자리가 없다
      var v = m[kind];
      var cls = (v === null || v === undefined) ? 'na'
              : (m.sue === null || m.sue === undefined) ? 'mid'
              : (m.sue >= hit ? 'hit' : (m.sue <= shock ? 'shock' : 'mid'));
      var el = document.createElement('div');
      el.className = 'band-mk ' + cls;
      el.textContent = fmtMult(v, kind);
      layer.appendChild(el);
      items.push({ t: m.t, won: r.won, el: el });
    });
    var D = 26, raf = 0;
    function place() {
      raf = 0;
      var ts = dc.chart.timeScale(), main = dc.main();
      var cw = host.clientWidth, ch = host.clientHeight, placed = [];
      items.forEach(function (it) {
        var x = ts.timeToCoordinate(it.t), y = main ? main.priceToCoordinate(it.won) : null;
        if (x === null || y === null || x < -D || x > cw + D) { it.el.style.display = 'none'; return; }
        // 이웃과 겹치면 위로 한 칸씩 피한다 — 5년·좁은 폭(모바일 1열)에서 분기 배지가 서로 덮는다
        for (var k = 0; k < 4; k++) {
          var over = placed.some(function (p) { return Math.abs(p.x - x) < D && Math.abs(p.y - y) < D; });
          if (!over) break;
          y -= D + 2;
        }
        placed.push({ x: x, y: y });
        it.el.style.display = '';
        it.el.style.left = x + 'px';
        it.el.style.top  = Math.max(D / 2, Math.min(ch - D / 2, y)) + 'px';
      });
    }
    function soon() { if (!raf) raf = requestAnimationFrame(place); }
    dc.chart.timeScale().subscribeVisibleLogicalRangeChange(soon);
    ['mousemove', 'mouseup', 'wheel', 'touchmove', 'touchend'].forEach(function (ev) {
      host.addEventListener(ev, soon, { passive: true });
    });
    if (w.ResizeObserver) new ResizeObserver(soon).observe(host);
    soon(); setTimeout(soon, 80);                    // fitContent 뒤 좌표가 잡히는 한 박자를 기다린다
    return items.length;
  }

  /**
   * 한 칸(PER 또는 PBR) 그리기.
   * @param hostId  차트가 들어갈 요소 id
   * @param data    pf_band_series() 응답
   * @param kind    'per' | 'pbr'
   * @param legend  범례를 넣을 요소 id (없으면 생략)
   * @returns 차트 인스턴스 또는 null(밴드 미성립)
   */
  function draw(hostId, data, kind, legend) {
    var host = document.getElementById(hostId);
    var leg  = legend ? document.getElementById(legend) : null;
    if (!host) return null;
    css();
    var b = data && data[kind];

    // 밴드가 성립하지 않는 종목 — 빈 차트를 그리지 않고 «왜»를 적는다 (빈 패널 금지)
    if (!b || !b.ok) {
      host.innerHTML = '<div class="band-empty">' + ((b && b.why) || '밴드를 그릴 자료가 없습니다') + '</div>';
      if (leg) leg.innerHTML = '';
      return null;
    }

    var shrs = +data.shrs > 0 ? +data.shrs : 1;    // 표시 단위 환산 (시총 → 주당 원)
    var dc = w.DailyChart.create(hostId, {
      theme: 'light', volume: false, screen: '', key: '', padPct: 0.06
    });
    if (!dc) return null;

    // 주가선 = 시총 ÷ 지금 상장주식수. 종가만 주면 모듈이 선 폴백으로 그린다.
    dc.setData((data.px || []).map(function (p) { return { t: p.t, c: p.v / shrs }; }));

    // 밴드선 — 낮은 배수부터. 값이 null 인 구간은 선이 «끊긴다»(적자·수집 구멍)
    (b.mult || []).forEach(function (m, i) {
      var line = dc.addLine({ color: COLORS[i % COLORS.length], width: 1, stepped: true });
      line.setData((b.step || []).map(function (s) {
        return { time: s.t, value: (s.v === null || s.v === undefined) ? null : (s.v * m) / shrs };
      }));
    });

    /* ── 커서가 짚은 날의 실제 배수 ──────────────────────────────────
     * 밴드선은 「그 배수였다면 얼마」를 보여 줄 뿐, 「그날 실제로 몇 배였나」는 말해 주지 않는다.
     * 그 수치는 여기서만 나온다: 배수 = 시총 ÷ 그날 유효한 값.
     * ★ px 와 step 은 서버에서 «같은 날짜 배열»로 만들어져 인덱스가 맞는다(band.php ⑤).
     *   날짜로 다시 짝짓지 않는 이유 — 계단 전환일이 끼어들어도 두 배열이 함께 늘어난다. */
    var label = kind === 'per' ? 'PER' : 'PBR';
    var px = data.px || [], step = b.step || [], at = {};
    px.forEach(function (p, i) {
      var s = step[i];
      var v = s ? s.v : null;
      at[p.t] = { won: p.v / shrs, mult: (v === null || v === undefined || v <= 0) ? null : p.v / v };
    });

    // 공시 배지 (계단이 꺾이는 날) + 판독기가 그 날을 짚으면 분기·접수일·SUE 를 덧붙인다
    var nMk = badges(dc, host, data, kind, at);
    var mkAt = {};
    (data.marks || []).forEach(function (m) { mkAt[m.t] = m; });

    var read = document.createElement('div');
    read.className = 'band-cur';
    host.appendChild(read);

    var lastT = px.length ? px[px.length - 1].t : '';
    function show(t) {
      var r = at[t];
      if (!r) { read.innerHTML = ''; return; }
      var m = mkAt[t];
      read.innerHTML = '<b>' + fmtDate(t) + '</b> ' + label + ' '
        + (r.mult === null ? '<span class="na">—</span>'
                           : '<b class="v">' + r.mult.toFixed(2) + 'x</b>')
        + ' <span class="na">·</span> ' + fmtWon(r.won)
        + (m ? ' <span class="na">·</span> ' + m.q + ' 공시 ' + fmtDate(m.d)
             + (m.sue === null || m.sue === undefined ? '' : ' <span class="na">SUE</span> ' + m.sue)
             : '');
    }
    show(lastT);                                   // 커서가 없을 때는 «최신»을 띄워 둔다
    dc.chart.subscribeCrosshairMove(function (p) {
      var t = tkey(p && p.time);
      show(at[t] ? t : lastT);                     // 차트 밖으로 나가면 최신값으로 되돌아온다
    });

    if (leg) {
      var html = '<span class="bl"><i style="background:' + PX_COLOR + '"></i>수정 시총</span>';
      (b.mult || []).forEach(function (m, i) {
        html += '<span class="bl"><i style="background:' + COLORS[i % COLORS.length] + '"></i>'
              + m + 'x</span>';
      });
      if (nMk) {
        html += '<span class="bl" title="계단이 꺾이는 자리의 원형 배지 — 안의 숫자는 공시일 시총 기준 «그때» ' + label
              + ' (위 표의 ' + label + ' 열·일봉 SUE 배지와 같은 값) · 빨강 어닝서프라이즈(SUE ≥ ' + data.hit
              + ') · 파랑 어닝쇼크(SUE ≤ ' + data.shock + ') · 회색 그 사이 · 연회색 적자·자본잠식">'
              + '<i class="mk-i" style="background:#d32f2f"></i><i class="mk-i" style="background:#1565c0"></i>'
              + '공시 ' + nMk + '건 — 안의 숫자는 그때 ' + label + '</span>';
      }
      /* 기간 최저·최고 — 밴드선(분위수)이 «말하지 않는» 양 끝이다.
       * 10% 선 아래·90% 선 위가 얼마나 먼지는 이 두 수치로만 알 수 있다. */
      var s = b.stat;
      if (s) {
        /* ★ cur 은 「오늘」이 아니라 «값이 있던 마지막 날»이다 — 오른쪽 끝에서 선이 끊긴 종목이
         *   실제로 있다(실측: 대한제분 001130 PER 은 2026-03-19 이 마지막). 날짜를 빼면
         *   4.14x 가 오늘 값인 척한다. 최신 거래일과 다를 때만 날짜를 붙인다. */
        var stale = s.curAt && s.curAt !== lastT;
        html += '<span class="bl st" title="일별 표본의 실제 최저·최고입니다. 밴드선은 이상치에 밀리지 않도록 분위수로 그립니다.">'
              + '기간 최저 <b>' + s.min + 'x</b> <em>' + fmtDate(s.minAt) + '</em>'
              + ' · 최고 <b>' + s.max + 'x</b> <em>' + fmtDate(s.maxAt) + '</em>'
              + ' · ' + (stale ? '마지막' : '현재') + ' <b>' + s.cur + 'x</b>'
              + (stale ? ' <em>' + fmtDate(s.curAt) + '</em>' : '')
              + '</span>';
      }
      if (b.cover < 1) {
        html += '<span class="bl muted" title="적자·자본잠식·수집 구멍으로 선이 끊긴 구간입니다">'
              + '빈 구간 ' + Math.round((1 - b.cover) * 100) + '%</span>';
      }
      leg.innerHTML = html;
    }
    return dc;
  }

  /**
   * PER·PBR 두 칸을 한꺼번에. 화면은 이것만 부르면 된다.
   * @returns Promise<{data, per, pbr}>
   */
  function render(code, ids, years) {
    return w.DailyChart.load()
      .then(function () { return fetchSeries(code, years); })
      .then(function (d) {
        return {
          data: d,
          per: draw(ids.per, d, 'per', ids.perLegend),
          pbr: draw(ids.pbr, d, 'pbr', ids.pbrLegend)
        };
      });
  }

  /**
   * 기간 토글까지 얹은 진입점. 화면은 이것만 부르면 된다.
   *
   * ★★ 기간을 «고르게» 만든 이유 — 종목마다 맞는 창이 다르다는 것이 실측이다(2026-08-04).
   *   ⒜ 솔본 035610 = 옛 이상치가 창에 남아 상단을 밀어올리는 형. PER 상단이
   *      5년 39.11x · 3년 39.67x 인데 **2년이면 3.63x** 로 내려앉아 FnGuide(4.78x)와 거의 같아진다.
   *   ⒝ LG엔솔 373220 = TTM 순이익이 0 근처를 오가는 형. 5년 548x → 3년 242x → **2년 415x 로 되레 악화**.
   *      창으로는 못 고친다(FnGuide 도 못 고쳐서 최하단선이 `0.00x` 로 서 있다).
   *   시총 상위 60 전수에서도 PER 뭉개짐은 5년 20% · 3년 27% · 2년 20% 로 **단축이 일반해가 아니었다**
   *   (오히려 3년이 가장 나빴다). 그래서 기본값을 바꾸지 않고 «고르게» 둔다. PBR 은 8%→2% 로 잘 듣는다.
   */
  var YEARS = [5, 3, 2];
  var LSKEY = 'bandYears';

  function mount(code, ids, barId) {
    var bar = barId ? document.getElementById(barId) : null;
    var cur = { per: null, pbr: null };
    var y = 5;
    try { var sv = +localStorage.getItem(LSKEY); if (YEARS.indexOf(sv) >= 0) y = sv; } catch (e) {}

    function paint() {
      if (!bar) return;
      bar.innerHTML = YEARS.map(function (v) {
        return '<button type="button" class="band-y' + (v === y ? ' on' : '')
             + '" data-y="' + v + '">' + v + '년</button>';
      }).join('');
    }

    function go() {
      css(); paint();
      // 다시 그리기 전에 앞 차트를 «치운다» — 안 치우면 캔버스가 겹쳐 쌓인다
      ['per', 'pbr'].forEach(function (k) {
        if (cur[k] && cur[k].chart) { try { cur[k].chart.remove(); } catch (e) {} }
        cur[k] = null;
        var h = ids[k] && document.getElementById(ids[k]);
        if (h) h.innerHTML = '';                    // 판독기(.band-cur)도 여기서 함께 사라진다
      });
      return render(code, ids, y).then(function (res) {
        cur.per = res.per; cur.pbr = res.pbr;
        return res;
      });
    }

    if (bar) bar.addEventListener('click', function (e) {
      var b = e.target && e.target.closest ? e.target.closest('.band-y') : null;
      if (!b) return;
      var v = +b.getAttribute('data-y');
      if (!v || v === y) return;
      y = v;
      try { localStorage.setItem(LSKEY, String(y)); } catch (e2) {}
      go();
    });

    return go();
  }

  w.BandChart = { mount: mount, render: render, draw: draw, fetch: fetchSeries,
                  fmtEok: fmtEok, COLORS: COLORS, YEARS: YEARS };
})(window);
