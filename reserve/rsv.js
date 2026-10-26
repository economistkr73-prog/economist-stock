/*!
 * reserve/rsv.js — 캠핏 빈자리 «받기 + 기록» 공용 모듈 (v1 · 2026-10-23)
 *
 * 빈자리 화면(mode=camp)과 달력 화면(mode=cal)이 같은 것을 쓴다 — 받는 법·기록 모양을 화면마다 다시 적지 않는다.
 *   ★캠핏은 «브라우저가 직접» 부른다(서버 IP 는 Cloudflare 에 막힌다 · classes/Reserve.class 헤더).
 *   ★받은 것은 그대로 서버에 넘겨 rsv_snap 에 남긴다(save) — 직전과 같으면 서버가 확인 시각만 고친다.
 *   ★캠핏 제약: 과거 날짜 400 · 한 번에 최대 34일 → 한 달 = 1콜, 이번 달은 오늘부터(range).
 * 저장 요청은 전역 RSV_CSRF 를 쓴다(화면이 심는다).
 */
window.RsvSnap = (function () {   // v2 · 배치도
  'use strict';
  var CF = 'https://api.camfit.co.kr';
  var CODE = { available: 'A', sold_out: 'S', unavailable: 'U' };

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function lastDay(ym) { return new Date(+ym.slice(0, 4), +ym.slice(5, 7), 0).getDate(); }

  /* 캠핏 GET — 실패는 사람 말로 (Cloudflare 챌린지면 CORS 헤더가 없어 fetch 자체가 TypeError 로 떨어진다) */
  function get(path) {
    return fetch(CF + path, { headers: { Accept: 'application/json' } }).then(function (r) {
      return r.text().then(function (t) {
        var j = null; try { j = JSON.parse(t); } catch (e) {}
        if (!r.ok || j === null) throw new Error((j && j.message) ? j.message : ('캠핏 응답 오류 (HTTP ' + r.status + ')'));
        return j;
      });
    }, function () { throw new Error('캠핏에 연결하지 못했습니다 — 접속 차단이거나 네트워크 문제입니다. 잠시 뒤 다시 시도해 주세요.'); });
  }

  /** 캠핑장 정보 한 번 — 이름·지역·주소·연락처·썸네일·예약 오픈 한도·배치도(map=1000px · mapFull=원본) */
  var metaCache = {};
  function campMeta(id) {
    if (metaCache[id]) return Promise.resolve(metaCache[id]);
    return get('/v1/camps/' + id).then(function (j) {
      var th = j.thumbnail || {}, mp = j.mapPicture || {}, mf = mp.formats || {};
      var m = {
        id: id, name: j.name || '', region: [j.city, j.major].filter(Boolean).join(' '),
        address: [j.address1, j.address2].filter(Boolean).join(' '), contact: j.contact || '',
        thumb: (th.formats && th.formats.thumbnail && th.formats.thumbnail.url) || th.url || '',
        until: j.bookableUntilTimestamp ? ymd(new Date(j.bookableUntilTimestamp)) : '',
        /* 배치도 원본은 수 MB~십수 MB 라 화면·PDF 는 large(1000px) — 원본은 누를 때만 */
        map: (mf.large && mf.large.url) || (mf.medium && mf.medium.url) || mp.url || '',
        mapFull: mp.url || ''
      };
      metaCache[id] = m; return m;
    });
  }

  /** 그 달에서 받을 수 있는 기간 — 지난 달이면 null */
  function range(ym) {
    var t = ymd(new Date()), start = ym + '-01', end = ym + '-' + pad(lastDay(ym));
    if (start < t) start = t;
    return end < start ? null : { start: start, end: end };
  }

  /** 한 달 받기 → {start,end,data} */
  function fetchMonth(id, ym) {
    var r = range(ym);
    if (!r) return Promise.reject(new Error('지난 달은 받을 수 없습니다.'));
    return get('/v1/vacancy-calendars/camps/' + id + '/sites?startDate=' + r.start + '&endDate=' + r.end).then(function (j) {
      if (j.status !== 'success' || !j.data) throw new Error(j.message || '캠핏 응답 형식이 바뀌었습니다.');
      return { start: r.start, end: r.end, data: j.data };
    });
  }

  /** 캠핏 응답 → 서버 기록 모양 (사이트마다 날짜 순 한 글자: A 가능 · S 마감 · U 불가 · 없는 날은 U) */
  function build(data, start, end, meta) {
    var days = [], d = new Date(+start.slice(0, 4), +start.slice(5, 7) - 1, +start.slice(8, 10));
    while (ymd(d) <= end) { days.push(ymd(d)); d.setDate(d.getDate() + 1); }
    var camp = data.camp || {};
    return {
      start: start, end: end,
      name: (meta && meta.name) || camp.name || '',
      until: (meta && meta.until) || (camp.bookableUntilTimestamp ? ymd(new Date(camp.bookableUntilTimestamp)) : ''),
      map: (meta && meta.map) || '', mapFull: (meta && meta.mapFull) || '',
      zones: (data.zones || []).map(function (z) { return { id: z.id, name: z.name }; }),
      sites: (data.sites || []).map(function (s) {
        var m = {}; (s.availability || []).forEach(function (a) { m[a.date] = CODE[a.status] || 'U'; });
        return { id: s.id, name: s.name, z: s.zoneId, s: days.map(function (x) { return m[x] || 'U'; }).join('') };
      })
    };
  }

  /** 서버에 기록 — {ok, changed, newly:["siteId|date"], fetched_at, checked_at} */
  function save(id, ym, start, end, data, meta) {
    var fd = new FormData();
    fd.append('action', 'snap_save'); fd.append('csrf', window.RSV_CSRF || '');
    fd.append('ext_id', id); fd.append('ym', ym);
    fd.append('data', JSON.stringify(build(data, start, end, meta)));
    return fetch('/reserve/api.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); });
  }

  return { get: get, campMeta: campMeta, range: range, fetchMonth: fetchMonth, build: build, save: save };
})();
