/*!
 * style/acnav.js — 자동완성 목록 «키보드 이동» 공용 모듈  (v1 · 2026-08-05)
 *
 * ★사이트의 모든 검색 자동완성(종목 검색·종목 추가·바로가기 등)은 이 모듈로 키보드 이동을 붙인다.
 *   ↓/↑ 로 항목을 옮기고 · Enter 로 고르고 · Esc 로 닫는다. 화면마다 keydown 을 다시 적지 않는다.
 *   (검색창을 새로 만들 때 이 한 줄을 빠뜨리면 «마우스로만 고를 수 있는» 검색창이 또 생긴다)
 *
 * 쓰는 법
 *   화면에 <script src="/style/acnav.js?v=1"> 를 싣고 (PHP 라면 pf_acnav_js())
 *   AcNav.attach(document.getElementById('dtQ'), {
 *     box:   '#dtSug',                      // 목록 컨테이너 (요소·선택자·함수)
 *     item:  '.s',                          // 항목 선택자 (없으면 컨테이너의 자식 전부)
 *     pick:  function(el, i){ el.click(); }, // 고르기 (기본값도 el.click())
 *     close: function(){ box.style.display='none'; }  // Esc
 *   });
 *
 * 옵션
 *   box*       목록 컨테이너. 함수로 주면 매번 다시 찾는다(나중에 그려지는 목록도 된다).
 *   item       항목 선택자. 생략하면 컨테이너의 직계 자식 전부.
 *   on         하이라이트 클래스 (기본 'on') — ★그 클래스의 CSS 가 있어야 눈에 보인다.
 *   pick       고르기. 생략하면 항목을 click() 한다(이미 걸어 둔 클릭 핸들러를 그대로 쓴다).
 *   close      Esc 로 닫기. 목록을 감추는 방법이 화면마다 달라 모듈이 짐작하지 않는다.
 *   isOpen     열림 판정 (기본: 컨테이너가 화면에 보이는가).
 *   enterFirst 아무것도 안 고른 채 Enter → 첫 항목을 고른다 (기본 false).
 *   onEnter    Enter 를 화면이 «먼저» 가로챈다. true 를 돌려주면 모듈은 손을 뗀다.
 *              (예: 6자리 코드를 쳤으면 목록을 기다릴 것 없이 바로 연다)
 *
 * ★목록은 «그때그때 DOM 을 읽는다» — 검색 결과를 다시 그려도 모듈에 알릴 필요가 없다.
 * ★고른 자리는 스크롤을 따라간다(scrollIntoView block:'nearest') — 목록이 max-height 로 잘려 있어도
 *   ↓ 를 계속 누르면 하이라이트가 보이지 않는 곳으로 숨지 않는다.
 */
(function () {
  'use strict';

  function resolve(box) {
    if (typeof box === 'function') return box();
    if (typeof box === 'string')   return document.querySelector(box);
    return box || null;
  }

  /* position:absolute·fixed 어느 쪽이든 통하는 «보이는가» 판정 */
  function visible(el) {
    return !!el && el.getClientRects().length > 0;
  }

  function attach(input, opt) {
    if (!input) return null;
    if (input.dataset.acnav) return null;   // 두 번 걸지 않는다
    input.dataset.acnav = '1';
    opt = opt || {};

    var ON   = opt.on || 'on';
    var ITEM = opt.item || '';
    var idx  = -1;

    function box()  { return resolve(opt.box); }

    function items() {
      var b = box();
      if (!b) return [];
      return Array.prototype.slice.call(ITEM ? b.querySelectorAll(ITEM) : b.children);
    }

    function isOpen() {
      var b = box();
      if (!b) return false;
      return opt.isOpen ? !!opt.isOpen(b) : visible(b);
    }

    function paint(list) {
      list.forEach(function (el, i) {
        if (i !== idx) { el.classList.remove(ON); return; }
        el.classList.add(ON);
        if (el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
      });
    }

    function reset() {
      idx = -1;
      items().forEach(function (el) { el.classList.remove(ON); });
    }

    function close() {
      reset();
      if (opt.close) opt.close();
    }

    function choose(el, i) {
      if (!el) return;
      idx = -1;
      if (opt.pick) opt.pick(el, i);
      else el.click();
    }

    // 글자를 고치면 목록이 다시 그려진다 — 고른 자리도 함께 푼다
    input.addEventListener('input', function () { idx = -1; });

    input.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { close(); return; }

      if (e.key === 'Enter' && opt.onEnter && opt.onEnter(e, idx)) return;

      if (!isOpen()) return;
      var list = items();
      if (!list.length) return;

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        idx = (idx < list.length - 1) ? idx + 1 : list.length - 1;
        paint(list);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        idx = (idx > 0) ? idx - 1 : 0;
        paint(list);
      } else if (e.key === 'Enter') {
        if (idx >= 0)            { e.preventDefault(); choose(list[idx], idx); }
        else if (opt.enterFirst) { e.preventDefault(); choose(list[0], 0); }
      }
    });

    return { reset: reset, close: close, focused: function () { return idx; } };
  }

  window.AcNav = { attach: attach, VERSION: 1 };
})();
