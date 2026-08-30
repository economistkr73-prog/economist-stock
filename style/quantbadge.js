/* style/quantbadge.js — 종목 목록 배지 «그리기» 단일본
 *
 * 왜 있나
 *   단타(mode=short)의 두 목록(상위 종목·내 목록)이 <b>같은 배지</b>를 단다(2026-08-05).
 *   화면마다 그리기 코드를 적으면 어휘·색이 조용히 갈라진다 — 같은 종목이 화면마다
 *   다른 말을 하게 된다. 그래서 그리기와 스타일을 여기 한 벌로 둔다.
 *
 *   판정(무엇이 매집형인가·박스가 어떤 상태인가)은 여기 없다. 서버 단일본은
 *   stock/lib/quant.php 의 quant_badge_many() 다. 이 파일은 그 결과를 «같은 어휘로»
 *   그리기만 한다.
 *
 * 쓰는 법
 *   QuantBadge.etf(n)      상위 편입 ETF 수 배지 (0이면 빈 문자열)
 *   QuantBadge.quant(q,o)  퀀트 배지 묶음 (유형 · 20/40일 모멘텀 · 박스 상태)
 *                          o = 배지 표시 설정 {qsig,mom,qpath} (BadgeFeat 가 심는 BADGE_FEATS 를
 *                          그대로 넘긴다 · 생략하면 전부 그린다 — 화면은 «고르기만» 한다)
 *   좁은 목록(단타 262px 사이드바)은 바깥 요소에 class="qb-sm" 을 주면 한 단계 작아진다.
 *
 * ★스타일은 이 파일이 <style> 로 심는다(로드 즉시 1회). 색은 화면의 CSS 변수
 *   (--panel-2 · --line · --ink-mute …)를 쓰므로 다크 팔레트를 가진 화면이면 그대로 맞는다.
 * ★이 파일 주석에 스크립트 닫는 글자를 넣지 않는다 — 인라인으로 붙여 검증할 때 거기서 끊긴다.
 */
var QuantBadge = (function () {
  'use strict';

  var CSS = [
    /* ETF 편입 뱃지: 이 종목을 상위 편입한 ETF 수(티어 색상 — etf_stock.php get_etf_badge_html 로직) */
    '.etfb{display:inline-block;margin-left:6px;padding:1px 7px;border-radius:5px;font-size:11px;',
    '  font-weight:800;vertical-align:middle;line-height:1.5;letter-spacing:.02em;white-space:nowrap}',
    '.etfb.et1{background:#1e2c44;color:#8fb6ec;border:1px solid #33507a}',
    '.etfb.et2{background:#23449c;color:#dbe7ff;border:1px solid #3b82f6}',
    '.etfb.et3{background:#2563eb;color:#fff;border:1px solid #4f8cff}',
    '.etfb.et4{background:#e11d2a;color:#fff;border:1px solid #ff5a5a;box-shadow:0 0 8px rgba(225,29,42,.5)}',
    /* 퀀트 배지 — 퀀트 화면과 같은 어휘·임계, 다크 톤 */
    '.qb,.qbx{display:inline-block;margin-left:6px;padding:1px 7px;border-radius:6px;font-size:11px;',
    '  font-weight:700;vertical-align:middle;line-height:1.5;letter-spacing:.01em;white-space:nowrap}',
    '.qb-acc{background:#12341f;color:#5dd58a;border:1px solid #1e5c3a}',      /* 매집형 */
    '.qb-neu{background:#1e2637;color:#93a4c3;border:1px solid #2c3a55}',      /* 중립 */
    /* 불꽃형 = 옛 폭발형+추격주의 (2026-08-02 통합) — 처방이 「매수 금지」 하나라 가장 강한 색 */
    '.qb-flame{background:#3b1518;color:#f87171;border:1px solid #6e2429}',
    /* 20·40일 모멘텀 — 등락색 단색 배경 + 흰 글씨 */
    '.qb-hot{background:#c62828;color:#fff;border:1px solid #c62828}',         /* 급등 (실측 근거 있음) */
    '.qb-cold{background:#1565c0;color:#fff;border:1px solid #1565c0}',        /* 급락 (근거 없음·참고) */
    /* SUE — 사이트 공통 어휘·등락색(빨강=좋은 소식/파랑=나쁜 소식 · PHP .sue-b 와 같은 색) */
    '.qb-sue.up{background:#c62828;color:#fff;border:1px solid #c62828}',
    '.qb-sue.dn{background:#1565c0;color:#fff;border:1px solid #1565c0}',
    '.qb-sue .q{margin-left:4px;font-size:9px;opacity:.72;font-weight:600}',
    '.qbx{border-radius:9px}',                                                 /* 박스 상태 */
    '.qbx.bx-brk{background:#12341f;color:#5dd58a}  .qbx.bx-lad{background:#152742;color:#7fb1e8}',
    '.qbx.bx-dn{background:#3b1518;color:#f87171}   .qbx.bx-in{background:#1e2637;color:#93a4c3}',
    '.qbx.bx-new{background:#242c3d;color:#8893ab}  .qbx.bx-fake{background:#3a2410;color:#f0a13b}',
    '.qbx.bx-na{background:#242c3d;color:#5b6884}',
    /* ⚡장중 «잠정» 신박스 (2026-08-10) — 확정 배지와 <b>색을 갈라 둔다</b>.
     * 마감까지 뒤집힐 수 있는 판정이라, 같은 회색(bx-new)으로 두면 「오늘 확정된 것」으로 읽힌다.
     * 점선 테두리 = 「아직 굳지 않았다」는 뜻(사이트에서 잠정을 그렇게 그린다). */
    '.qbx.bx-live{background:#3a2f10;color:#f5c451;border:1px dashed #7a6320}',
    /* 좁은 목록용 축소판 — 단타 사이드바(262px)처럼 폭이 모자란 자리 */
    '.qb-sm .etfb,.qb-sm .qb,.qb-sm .qbx{font-size:10px;padding:0 5px;margin-left:4px;',
    '  line-height:1.6;letter-spacing:0}'
  ].join('');

  function ensureCss() {
    if (document.getElementById('quantbadge-css')) return;
    var s = document.createElement('style');
    s.id = 'quantbadge-css';
    s.textContent = CSS;
    (document.head || document.documentElement).appendChild(s);
  }

  function esc(t) {
    return String(t == null ? '' : t)
      .replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
  }

  /* ETF 편입 뱃지 — 상위 편입(top_rank)한 ETF 수. 티어가 넷이고 100개 이상만 💎를 단다. */
  function etf(n) {
    n = Number(n) || 0;
    if (n <= 0) return '';
    var cls = n >= 100 ? 'et4' : n >= 50 ? 'et3' : n >= 20 ? 'et2' : 'et1';
    var icon = n >= 100 ? '💎' : '';
    return '<span class="etfb ' + cls + '" title="상위 편입 ETF ' + n + '개">' + icon + n + '</span>';
  }

  /* 퀀트 배지 — 유형(오늘 최고 거래대금 경신 시)·급등/급락·박스 상태.
     판정은 서버(stock/lib/quant.php)가 krx_amt/krx_surge 원장으로 내리고 여기선 그리기만 한다.
     o = 배지 표시 설정(BADGE_FEATS · 키는 BadgeFeat 카탈로그의 qsig/mom/qpath) — 없는 키는 켬으로 본다
     (설정을 모르는 옛 화면이 이 모듈을 그대로 써도 아무것도 사라지지 않는다). */
  function quant(q, o) {
    if (!q) return '';
    var on = function (k) { return !o || o[k] === undefined || !!o[k]; };
    var h = '';
    if (on('qsig') && q.t) h += '<span class="qb qb-' + q.cls + '" title="' + esc(q.tip) + '">' + q.t + '</span>';
    /* 20·40거래일 모멘텀 — 창별로 「20일 +112%」처럼. 급등은 실측 근거가 있어 경고색(qb-hot),
       급락은 근거 없이 급등 임계의 로그 대칭일 뿐이라 정보색(qb-cold). */
    if (on('mom')) (q.mom || []).forEach(function (m) {
      var why = m.hot
        ? ' 급등 — 이 무리의 돌파 매수는 백테스트에서 중앙 0%·승률 51.6%로 엣지가 없습니다(평균만 +7%인 복권꼬리). 금지가 아니라 고지입니다.'
        : ' 급락 — 임계는 급등의 로그 대칭이라 백테스트 근거가 없습니다. 얼마나 빠르게 빠졌는지 보는 참고 표시입니다.';
      // ★ 기준 시점을 반드시 밝힌다 — 이 배지는 «현재가» 기준이다(퀀트 목록의 신호일 기준과 다르다)
      var tip = '현재가 기준 ' + m.w + '거래일 ' + (m.v > 0 ? '+' : '') + m.v + '%' + why;
      h += '<span class="qb ' + (m.hot ? 'qb-hot' : 'qb-cold') + '" title="' + esc(tip) + '">'
         + m.w + '일 ' + (m.v > 0 ? '+' : '') + m.v + '%</span>';
    });
    if (on('sue') && q.sue) h += sue(q.sue);
    if (on('qpath') && q.bx) h += '<span class="qbx ' + q.bx.st + '" title="' + esc(q.bx.tip) + '">' + q.bx.txt + '</span>';
    return h;
  }

  /* SUE 칩 하나 — 판정(±1 문턱·최신 분기)은 서버(stock/lib/sue.php 경유)가 끝냈다. 어휘·색은
     사이트 공통 배지(pf_sue_badge_map)와 같다 — 한 종목이 화면마다 다른 말을 하면 안 된다.
     quant() 안에서도 쓰고, 종목명 옆에 따로 그리는 화면(단타 두 목록)은 직접 부른다.
     ★라벨은 「SUE ±값」 — 긴 말(어닝서프라이즈/어닝쇼크)은 목록에서 잘려서 뺐다(2026-08-12 사용자).
       뜻은 배경색(빨강=서프라이즈·파랑=쇼크)과 부호가 말하고, 전문은 툴팁에 남는다. */
  function sue(s) {
    if (!s) return '';
    var sv = (s.v > 0 ? '+' : '') + s.v;
    var tip = '최신 분기(' + s.q + ') 이익 서프라이즈 SUE ' + sv + (s.up
      ? ' ≥ +1 = 어닝서프라이즈 무리(상위 20% 안팎) — 8년 백테스트에서 공시 후 두 달 상방 드리프트가 실측된 자리입니다.'
      : ' ≤ −1 = 어닝쇼크 무리 — 거의 매년 음수 · 공시 후 두 달 하방 드리프트. 자동 매도는 없습니다 — 실적 전제가 깨졌는지 다시 보라는 표시입니다.');
    return '<span class="qb qb-sue ' + (s.up ? 'up' : 'dn') + '" title="' + esc(tip) + '">SUE '
       + sv + '<span class="q">' + s.q + '</span></span>';
  }

  /* 억 단위 금액(시가총액·거래대금) 표기 — 목록이 화면마다 다른 자릿수로 말하지 않게 여기 둔다.
     all_stock_info 의 stock_cap·stock_vol_cap 이 «억원»이다(krx_amt.amt 의 원 단위가 아니다). */
  function eok(v) {
    v = Number(v) || 0;
    if (v >= 10000) return (v / 10000).toFixed(1) + '조';
    return Math.round(v).toLocaleString() + '억';
  }

  ensureCss();
  return { css: ensureCss, esc: esc, etf: etf, quant: quant, sue: sue, eok: eok };
})();
