// ==UserScript==
// @name         연재추적기 화수 자동기록 (mh)
// @namespace    https://economist.kr/mh
// @version      1.3.0
// @description  toki/newtoki 계열 사이트에서 회차(읽은 화)·최신화를 economist.kr 연재추적기에 자동 기록. 팝업창·SPA(다음화 새로고침없음)에서도 동작.
// @author       economist73
// @include      *://*toki*/webtoon/*
// @include      *://*toki*/novel/*
// @include      *://*toki*/mana/*
// @include      *://*toki*/comic/*
// @include      *://*toki*/anime/*
// @grant        GM_xmlhttpRequest
// @connect      economist.kr
// @run-at       document-idle
// @noframes
// @updateURL    https://economist.kr/mh_tracker.user.js
// @downloadURL  https://economist.kr/mh_tracker.user.js
// ==/UserScript==
(function () {
  'use strict';

  function toast(msg, color) {
    try {
      var el = document.createElement('div');
      el.textContent = '📚 ' + msg;
      el.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:2147483647;' +
        'background:#2c3e50;color:#fff;font:600 14px/1.4 -apple-system,sans-serif;padding:10px 14px;' +
        'border-radius:8px;box-shadow:0 4px 14px rgba(0,0,0,.35);border-left:4px solid ' + (color || '#3498db') + ';' +
        'opacity:0;transition:opacity .25s;pointer-events:none';
      document.body.appendChild(el);
      requestAnimationFrame(function () { el.style.opacity = '1'; });
      setTimeout(function () { el.style.opacity = '0'; setTimeout(function () { el.remove(); }, 320); }, 2800);
    } catch (e) {}
  }

  // 현재 페이지(회차/목록)를 판정해 economist.kr 추적기에 기록
  function run() {
    try {
      var segs = location.pathname.split('/').filter(Boolean);
      if (segs.length < 2 || !/^\d+$/.test(segs[1])) return;   // 작품/회차 페이지 아님
      var seg = segs[0], d = segs[1];
      var wb = (seg === 'novel') ? 1 : ((seg === 'mana' || seg === 'comic') ? 2 : ((seg === 'anime') ? 3 : 0));
      var hn  = (location.hostname.match(/(\d+)/) || [])[1] || '';
      var tit = (document.title || '').replace(/\s*[-|:｜].*$/, '').trim();
      var base = 'https://economist.kr/mh.php?set_latest=1&url_dir=' + encodeURIComponent(d) +
                 '&wb=' + wb + '&url_no=' + hn + '&tit=' + encodeURIComponent(tit);

      var extra = '', label = '';
      if (segs.length >= 3) {
        // 뷰어(회차 본문) → 지금 보는 화 = 읽은 회차 (최신화는 서버가 건드리지 않음)
        var last = segs[segs.length - 1], nums = last.match(/\d+/g) || [];
        var cur = nums.length ? parseInt(nums[nums.length - 1], 10) : 0;
        if (!cur) { var m, rt = /(\d{1,4})[화회]/g, tt = document.title || ''; while ((m = rt.exec(tt)) !== null) { cur = parseInt(m[1], 10); } }
        if (!cur) return;
        extra = 'read_no=' + cur + '&read_path=' + encodeURIComponent(segs.slice(2).join('/'));
        label = '읽은 ' + cur + '화';
      } else {
        // 목록 페이지 → 본문 "N화" 최댓값 = 최신화
        var t = (document.body && document.body.innerText) || '', mm, rb = /(\d{1,4})[화회]/g, mx = 0;
        while ((mm = rb.exec(t)) !== null) { var v = parseInt(mm[1], 10); if (v > mx) mx = v; }
        if (!mx) return;
        extra = 'latest_no=' + mx;
        label = '최신 ' + mx + '화';
      }

      // 같은 화(같은 URL+데이터) 중복 전송 방지
      var key = 'mh_sent::' + location.pathname + '::' + extra;
      if (sessionStorage.getItem(key)) return;

      GM_xmlhttpRequest({
        method: 'GET',
        url: base + '&' + extra,
        onload: function (r) {
          sessionStorage.setItem(key, '1');
          var body = r.responseText || '';
          if (/새 작품 등록/.test(body)) { toast('추적기에 없는 작품 — 등록 안 됨', '#e67e22'); return; }
          if (/로그인|login/i.test(body) && !/기록|최신/.test(body)) { toast('economist.kr 로그인 필요', '#e74c3c'); return; }
          var mm2 = body.match(/id="mhmsg"[^>]*>([^<]*)</);   // 서버가 실제 반영한 결과 한 줄
          var msg = (mm2 && mm2[1].trim()) ? mm2[1].trim() : (label + ' 기록됨');
          var color = /남음/.test(msg) ? '#e67e22' : '#27ae60';
          toast(msg, color);
        },
        onerror: function () { toast('기록 전송 실패', '#e74c3c'); }
      });
    } catch (e) { /* 사이트 방해 금지: 조용히 무시 */ }
  }

  run();   // 최초 로드

  // ★뷰어가 '다음화'를 통짜 새로고침 없이(SPA/history) 넘기는 경우 대응:
  //   URL(경로)이 바뀌면 다시 기록한다. history 훅 + popstate + 폴링으로 방식 무관 감지.
  var lastPath = location.pathname;
  function onNav() {
    if (location.pathname !== lastPath) {
      lastPath = location.pathname;
      setTimeout(run, 500);   // 새 회차 경로/타이틀 반영 대기
    }
  }
  window.addEventListener('popstate', onNav);
  try {
    ['pushState', 'replaceState'].forEach(function (m) {
      var orig = history[m];
      history[m] = function () { var ret = orig.apply(this, arguments); onNav(); return ret; };
    });
  } catch (e) {}
  setInterval(onNav, 1000);   // 폴백: 위 훅을 우회하는 네비게이션까지
})();
