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

  function fetchSeries(code, years) {
    return fetch(API + '?module=band&action=series&code=' + encodeURIComponent(code)
                 + '&years=' + (years || 5))
      .then(function (r) { return r.json(); });
  }

  function fmtEok(v) {
    if (v === null || v === undefined || !isFinite(v)) return '-';
    return Math.round(v / 1e8).toLocaleString() + '억';
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

    if (leg) {
      var html = '<span class="bl"><i style="background:' + PX_COLOR + '"></i>수정 시총</span>';
      (b.mult || []).forEach(function (m, i) {
        html += '<span class="bl"><i style="background:' + COLORS[i % COLORS.length] + '"></i>'
              + m + 'x</span>';
      });
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

  w.BandChart = { render: render, draw: draw, fetch: fetchSeries, fmtEok: fmtEok, COLORS: COLORS };
})(window);
