/* style/stockrow.js — 종목 목록의 «한 행»(3줄) 공용 모듈 (2026-10-07)
 *
 * 왜 있나
 *   단타 화면의 목록과 관심그룹 화면의 종목 리스트가 «같은 행»을 보이기로 했다(사용자 지시 —
 *   「관심종목 리스트의 정보를 단타에 있는 것처럼 동일하게」). 화면마다 행을 따로 그리면
 *   하나를 고칠 때 다른 하나가 조용히 옛말을 한다 — 그래서 행의 모양과 칸의 CSS 를 여기 한 벌로 둔다.
 *
 * 행의 모양 (여섯 칸 · 2열 격자의 3줄)
 *   1줄  종목명 · ETF 편입 수 · 보유/편입됨 · 수집 상태      |  현재가
 *   2줄  코드 · SUE 칩 · 「N일 M봉」(원장에 봉이 있는 종목만)   |  등락률
 *   3줄  퀀트 배지(신호·경로·모멘텀)                          |  시총 · 회전율
 *
 * 쓰는 법
 *   el.className += ' srow qb-sm';            // srow = 이 모듈의 칸 CSS 가 먹는 표식 · qb-sm = 좁은 목록용 축소 배지
 *   el.innerHTML  = StockRow.cells(r, { bf: BADGE_FEATS, filling: '받는 중인 코드' });
 *   격자(2열)와 여백은 «그 화면»이 정한다 — 여기는 칸의 글자·정렬만 갖는다(화면마다 행의 바깥 모양이 다르다).
 *
 * r = { code, name, kind, price, rate, cap(억), amt_eok(억), etf_top, q(퀀트 배지 · 서버 stock/lib/quant.php),
 *       days, bars, holes, _held, pos_id, pf_names, pf_n, open, _pool, init_status }
 *
 * ★판정을 여기서 내리지 않는다 — 배지의 판정은 서버(stock/lib/quant.php), 그리기·색은 QuantBadge(style/quantbadge.js)다.
 *   이 모듈은 «어느 칸에 무엇을 놓나»만 안다. 배지 표시 설정(BADGE_FEATS · classes/BadgeFeat.class)도 그대로 따른다.
 * ★이 파일 주석에 스크립트 닫는 태그 글자를 넣지 않는다(인라인으로 붙여 검증할 때 그 자리에서 끊긴다).
 */
(function () {
  'use strict';

  /* 칸의 CSS — 색 변수(--mono·--ink-mute …)와 .up/.down/.flat 은 화면(다크 팔레트)이 정의한 것을 쓴다 */
  var CSS =
    '.srow>.nm{display:flex;align-items:center;min-width:0;font-size:13.5px;font-weight:700;letter-spacing:-.01em}' +
    '.srow>.nm .t{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}' +
    /* 이름만 줄이고 배지는 끝까지 남긴다 — 통째로 ellipsis 를 걸면 긴 이름에서 배지가 통째로 사라진다 */
    '.srow>.nm .etfb,.srow>.nm .dt-bdg{flex:0 0 auto}' +
    '.srow>.px{font-size:13px;text-align:right;font-family:var(--mono);font-weight:700;white-space:nowrap}' +
    /* 둘째 줄 — 넘치면 잘린다(줄을 바꾸면 종목마다 행 높이가 달라진다) */
    '.srow>.cd{font-size:11px;color:var(--ink-mute);font-family:var(--mono);min-width:0;overflow:hidden;white-space:nowrap}' +
    '.srow>.rt{font-size:11.5px;text-align:right;font-family:var(--mono);white-space:nowrap}' +
    '.srow>.sg{min-width:0;overflow:hidden;white-space:nowrap;line-height:1.6}' +
    '.srow>.mk{font-size:10.5px;text-align:right;color:var(--ink-mute);font-family:var(--mono);white-space:nowrap;align-self:center}' +
    /* 행 안의 상태 배지 — 받는 중 · 구멍 · 수집 실패 · ETF (보유/편입됨의 색은 화면의 .dt-bdg.held/.slot) */
    '.srow .dt-bdg{display:inline-block;margin-left:5px;padding:0 5px;border-radius:4px;font-size:10px;font-weight:800;' +
      'vertical-align:1px;border:1px solid;text-decoration:none}' +
    '.srow .dt-bdg.held{color:#5dd58a;border-color:#1e5c3a;background:#12341f}' +
    '.srow .dt-bdg.slot{color:#93a4c3;border-color:#2c3a55;background:#1e2637}' +
    '.srow .dt-bdg.wait{color:var(--accent,#d9a441);border-color:#5a4a22;background:#241d10}' +
    '.srow .dt-bdg.hole{color:#e0a44a;border-color:#5a4a22;background:#241d10}' +
    '.srow .dt-bdg.fail{color:#ff8a80;border-color:#5c2a26;background:#2a1412}' +
    '.srow .dt-bdg.etf{color:#7fb1e8;border-color:#33507a;background:#243250;letter-spacing:.03em}' +
    '.srow a.dt-bdg:hover{border-color:var(--accent,#d9a441);color:var(--accent,#d9a441)}';

  var injected = false;
  function inject() {
    if (injected) return;
    injected = true;
    var st = document.createElement('style');
    st.setAttribute('data-mod', 'stockrow');
    st.textContent = CSS;
    document.head.appendChild(st);
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function fmt(n) { return Math.round(Number(n) || 0).toLocaleString(); }
  function rateCls(r) { return r > 0 ? 'up' : (r < 0 ? 'down' : 'flat'); }
  function rateTxt(r) { r = Number(r) || 0; return (r > 0 ? '+' : '') + r.toFixed(2) + '%'; }

  /* 보유 배지 — 사이트 공통 어휘 그대로다(「보유」=돈이 들어감 · 「편입됨」=자리만 있음). 눌러서 포지션 상세로 */
  function heldBadge(h) {
    var id = Number(h.pos_id) || 0;
    var body = (h.open ? '보유' : '편입됨') + (h.pf_n > 1 ? ' ' + h.pf_n : '');
    var cls = 'dt-bdg ' + (h.open ? 'held' : 'slot');
    if (!id) return '<span class="' + cls + '" title="' + esc(h.pf_names || '포트폴리오에 있는 종목입니다') + '">' + body + '</span>';
    return '<a class="' + cls + '" href="/stock/index.php?mode=position&id=' + id
         + '" title="' + esc((h.pf_names || '') + ' · 눌러서 포지션 상세로') + '">' + body + '</a>';
  }

  /* 수집 상태 — 봉이 아직 없으면 «왜 비었는지»를 적는다(빈 칸은 고장으로 읽힌다).
     ★「받는 중」은 봉이 «아직 없을 때만» 붙인다 — 적재를 부르고 탭을 닫으면 init_status 가 1 인 채 남는데,
       그 종목도 크론이 채우므로 봉이 생긴 뒤까지 「받는 중」이라 하면 거짓말이 된다. */
  function statusBadge(r, filling) {
    if (r._held && filling && filling === r.code)  return '<span class="dt-bdg wait">받는 중</span>';
    if (r._pool && r.init_status === 1 && !r.bars) return '<span class="dt-bdg wait">받는 중</span>';
    if (r._pool && r.init_status === 9 && !r.bars) return '<span class="dt-bdg fail" title="다음 수집(평일 16:45)이 다시 시도합니다">수집 실패</span>';
    if (r.holes > 0)                               return '<span class="dt-bdg hole">구멍 ' + r.holes + '</span>';
    if (r._held && !r.bars)                        return '<span class="dt-bdg wait" title="다음 수집(평일 16:45)이 10거래일을 채웁니다">채우는 중</span>';
    return '';
  }

  /* 여섯 칸의 HTML — 격자 컨테이너(.srow)의 직계 자식으로 넣는다 */
  function cells(r, o) {
    inject();
    o = o || {};
    var QB = window.QuantBadge;
    // 배지 표시 설정 — 서버(BadgeFeat::script)가 심는다. 없으면 전부 켬(첫 배포·오류에도 목록은 산다)
    var BF = o.bf || window.BADGE_FEATS || { etf: 1, qsig: 1, qpath: 1, mom: 1, sue: 1, cap: 1, held: 1 };
    /* 칩 묶음용 사본 — SUE 는 «코드 옆(둘째 줄)»에 따로 그리므로 묶음에서는 뺀다(2026-08-20 — 종목명 옆은 이름을 밀어냈다).
       끄고 켜는 것은 여전히 BF.sue 하나다(자리만 다르지 스위치가 갈리면 안 된다). */
    var BFq = { etf: BF.etf, qsig: BF.qsig, qpath: BF.qpath, mom: BF.mom, sue: 0, cap: BF.cap };

    /* 회전율 = 거래대금 ÷ 시가총액 (둘 다 억원) — 파생값이라 저장하지 않는다 */
    var cap = Number(r.cap) || 0, amt = Number(r.amt_eok) || 0;
    var turn = cap > 0 ? (amt / cap * 100) : 0;
    // 시총·회전율(BF.cap) — 칸은 남기고 내용만 비운다(격자의 자리)
    var mk = !BF.cap || !QB ? ''
           : (cap > 0 ? QB.eok(cap) : '—') + (turn > 0 ? ' · ' + turn.toFixed(1) + '%' : '');
    var hasBars = r.days !== undefined && r.days !== null;

    return '<div class="nm"><span class="t">' + esc(r.name || r.code) + '</span>'
        + (r.kind === 'etf' ? '<span class="dt-bdg etf">ETF</span>' : '')
        + (BF.etf && QB ? QB.etf(r.etf_top) : '')
        // 바탕색과 «함께» 단다 — 색약·인쇄에서도 배지가 말한다
        + (r._held ? heldBadge(r) : '') + statusBadge(r, o.filling) + '</div>'
      + '<div class="px ' + rateCls(r.rate) + '">' + (r.price ? fmt(r.price) : '—') + '</div>'
      + '<div class="cd">' + esc(r.code)
        + (BF.sue && QB && r.q && r.q.sue ? QB.sue(r.q.sue) : '')
        // 「N일 M봉」은 원장에 봉이 있는 종목에만 있다 — 아직 안 연 종목에는 적을 것이 없다
        + (hasBars ? ' · ' + r.days + '일 ' + fmt(r.bars) + '봉' : '') + '</div>'
      + '<div class="rt ' + rateCls(r.rate) + '">' + (r.price ? rateTxt(r.rate) : '') + '</div>'
      + '<div class="sg">' + (QB ? QB.quant(r.q, BFq) : '') + '</div>'
      + '<div class="mk" title="시가총액 · 회전율(거래대금÷시가총액)">' + mk + '</div>';
  }

  window.StockRow = { cells: cells, heldBadge: heldBadge, statusBadge: statusBadge, css: inject };
})();
