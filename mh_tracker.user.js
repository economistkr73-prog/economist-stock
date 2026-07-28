// ==UserScript==
// @name         연재추적기 화수 자동기록 (mh)
// @namespace    https://economist.kr/mh
// @version      1.7.0
// @description  toki/newtoki 계열 + 네이버웹툰에서 회차(읽은 화)·최신화를 economist.kr 연재추적기에 자동 기록. toki 뷰어는 '총 N화', 네이버 뷰어는 URL의 &no= 로 읽은 회차를 잡고(네이버 최신화는 서버가 API로 조회). 팝업창·SPA(다음화 새로고침없음)에서도 동작.
// @author       economist73
// @include      *://*toki*/webtoon/*
// @include      *://*toki*/novel/*
// @include      *://*toki*/mana/*
// @include      *://*toki*/comic/*
// @include      *://*toki*/anime/*
// @match        *://comic.naver.com/webtoon/*
// @match        *://m.comic.naver.com/webtoon/*
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

  // economist.kr 추적기에 전송(중복 방지 + 결과 토스트). base=쿼리 앞부분, extra=회차/최신 파라미터.
  function send(base, extra, label) {
    var key = 'mh_sent::' + location.pathname + location.search + '::' + extra;
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
  }

  // 네이버 웹툰: 읽은 회차 = URL의 &no=. 최신화는 서버가 API로 조회하므로 여기선 회차만 보낸다.
  //   시즌제 작품은 document.title "작품명 - 시즌2 32화 : 네이버 웹툰" 에서 회차 라벨을 뽑아 함께 보낸다.
  function runNaver() {
    var qs  = new URLSearchParams(location.search);
    var tid = qs.get('titleId');
    if (!tid) return;
    var no    = parseInt(qs.get('no') || '0', 10);
    var full  = (document.title || '').replace(/\s*:\s*네이버\s*웹툰\s*$/, '').trim();  // "무사만리행 - 시즌2 32화"
    var tit   = full.replace(/\s*-\s*[^-]*$/, '').trim() || full;                        // "무사만리행"
    var label = full.replace(/^.*-\s*/, '').trim();                                       // "시즌2 32화"
    if (label === full) label = '';   // 대시가 없으면 라벨 불명 → 생략
    var base = 'https://economist.kr/mh.php?set_latest=1&url_dir=' + encodeURIComponent(tid) +
               '&wb=4&url_no=0&tit=' + encodeURIComponent(tit);
    if (no > 0) send(base, 'read_no=' + no + '&read_path=' + no + (label ? '&read_label=' + encodeURIComponent(label) : ''), '읽은 ' + (label || (no + '화')));
    else        send(base, 'nv=1', '최신화 확인');   // 목록 페이지: 서버 API 최신화만 갱신
  }

  // 현재 페이지(회차/목록)를 판정해 economist.kr 추적기에 기록
  function run() {
    try {
      if (location.hostname.indexOf('comic.naver.com') !== -1) { runNaver(); return; }

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
        // 뷰어(회차 본문) → 지금 보는 화 = 읽은 회차, 총 N화 = 최신화
        // ★진행표시 배지(.vw-ep-progress, aria-label="총 85화 중 84화", 본문 "84/85")가
        //   읽은 회차·최신화 둘 다 정확히 갖고 있다. URL 꼬리 숫자는 슬러그에 회차가 없으면
        //   엉뚱한 값(예: /u-mpyfs10p-mco9 → 9)을 잡으므로 배지를 1순위, URL은 최후 폴백.
        var cur = 0, lt = 0, pg = document.querySelector('.vw-ep-progress');
        if (pg) {
          var al = pg.getAttribute('aria-label') || '', txt = pg.textContent || '';
          var am = al.match(/총\s*(\d+)\s*화/); if (am) lt = parseInt(am[1], 10);   // 총 N화 = 최신
          var cm = al.match(/중\s*(\d+)\s*화/); if (cm) cur = parseInt(cm[1], 10);   // 중 M화 = 읽은
          if (!cur) { var tm = txt.match(/^\s*(\d+)\s*\//); if (tm) cur = parseInt(tm[1], 10); }  // "84/85" → 84
          if (!lt)  { var pm = txt.match(/\/\s*(\d+)/);      if (pm) lt  = parseInt(pm[1], 10); }
        }
        if (!cur) { var m, rt = /(\d{1,4})[화회]/g, tt = document.title || ''; while ((m = rt.exec(tt)) !== null) { cur = parseInt(m[1], 10); } }
        if (!cur) { var last = segs[segs.length - 1], nums = last.match(/\d+/g) || []; if (nums.length) cur = parseInt(nums[nums.length - 1], 10); }
        if (!cur) return;
        extra = 'read_no=' + cur + '&read_path=' + encodeURIComponent(segs.slice(2).join('/'));
        if (lt) extra += '&latest_no=' + lt;
        label = '읽은 ' + cur + '화' + (lt ? ' / 최신 ' + lt + '화' : '');
      } else {
        // 목록 페이지 → 본문 "N화" 최댓값 = 최신화
        var t = (document.body && document.body.innerText) || '', mm, rb = /(\d{1,4})[화회]/g, mx = 0;
        while ((mm = rb.exec(t)) !== null) { var v = parseInt(mm[1], 10); if (v > mx) mx = v; }
        if (!mx) return;
        extra = 'latest_no=' + mx;
        label = '최신 ' + mx + '화';
      }

      send(base, extra, label);
    } catch (e) { /* 사이트 방해 금지: 조용히 무시 */ }
  }

  run();   // 최초 로드

  // ★뷰어가 '다음화'를 통짜 새로고침 없이(SPA/history) 넘기는 경우 대응:
  //   URL(경로+쿼리)이 바뀌면 다시 기록한다. 네이버는 회차가 ?no= 쿼리로 바뀌므로 search까지 본다.
  var lastUrl = location.pathname + location.search;
  function onNav() {
    var u = location.pathname + location.search;
    if (u !== lastUrl) {
      lastUrl = u;
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
