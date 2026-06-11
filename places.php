<?php
// places.php — 전국 여행지 지도 (네이버 다이내믹 지도)
//   주소 검색 → 그 좌표 주변의 여행지/축제/맛집 마커 → 클릭 시 원본 링크 패널
//   데이터/검색은 place_api.php(module=place) 가 담당, 이 파일은 라우터+화면.
require_once "./env/cnt.inc";
require_once "./env/auth_fnc.php";
require_once "./env/nav.inc";

if (file_exists("./env/maps.inc")) require_once "./env/maps.inc";

$place = new Place($pdo);
$place->ensureTable();

// 한시적 공개 링크(?share=토큰): 유효하면 로그인 없이 게스트(읽기전용)로 열람
$shareToken  = trim((string)($_GET['share'] ?? ''));
$isGuest     = false;
$shareExpiry = null;
if ($shareToken !== '') {
    $sh = $place->getValidShare($shareToken);
    if ($sh) {
        $isGuest     = true;
        $shareExpiry = $sh['expires_at'];
    } else {
        http_response_code(410);
        echo "<!doctype html><html lang='ko'><meta charset='utf-8'>"
           . "<div style='font-family:sans-serif;padding:48px 24px;text-align:center;color:#444'>"
           . "<h2 style='margin-bottom:8px'>🔗 만료되었거나 잘못된 공유 링크입니다</h2>"
           . "<p style='color:#888'>링크 소유자에게 새 공유 링크를 요청하세요.</p></div></html>";
        exit;
    }
}
if (!$isGuest) require_login();

$naverClientId = defined('NAVER_MAPS_CLIENT_ID') ? NAVER_MAPS_CLIENT_ID : '';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>웅이가 간다! (전국의 숨겨진 명소,맛집 지도)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php nav_css(); ?>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root { --fs-sm: clamp(11px,0.85vw,16px); --fs-base: clamp(12px,1vw,18px); --fs-lg: clamp(14px,1.2vw,22px); }
html, body { overflow: hidden; }
/* body 에 viewport 높이를 직접 부여(100vh) — height:100% 체인이 안 풀려 #map 높이 0 되는 것 방지 */
body { font-family: 'Pretendard','Malgun Gothic',sans-serif; background: #f0f2f5; color: #2c3e50; height: 100vh; display: flex; flex-direction: column; }

/* 툴바 */
.pl-toolbar { display: flex; align-items: center; gap: 8px; padding: 10px 16px; background: #fff; box-shadow: 0 1px 4px rgba(0,0,0,.08); flex-shrink: 0; flex-wrap: wrap; }
.pl-toolbar h2 { font-size: var(--fs-lg); font-weight: 700; margin-right: 6px; white-space: nowrap; }
.pl-toolbar input, .pl-toolbar select { font-size: var(--fs-sm); padding: 7px 10px; border: 1px solid #cdd4da; border-radius: 6px; }
.pl-toolbar input#addr { width: 220px; }
.btn { border: none; cursor: pointer; border-radius: 6px; font-size: var(--fs-sm); font-weight: 600; padding: 8px 14px; transition: .15s; white-space: nowrap; }
.btn-primary { background: #3498db; color: #fff; } .btn-primary:hover { background: #2980b9; }
.btn-outline { background: #fff; border: 1px solid #bdc3c7; color: #2c3e50; } .btn-outline:hover { background: #ecf0f1; }
.btn-ghost { background: #f6f7f9; color: #7f8c8d; } .btn-ghost:hover { background: #e9ecef; }
.btn-share { background: #16a085; color: #fff; } .btn-share:hover { background: #117a65; }

/* 공유 패널 (소유자 전용) + 게스트 배너 */
.pl-share-wrap { position: relative; }
#pl-share { position: absolute; right: 0; top: calc(100% + 8px); z-index: 30; background: #fff; border: 1px solid #e6e9ee; border-radius: 10px; box-shadow: 0 6px 20px rgba(0,0,0,.18); padding: 14px; width: 330px; max-width: 92vw; display: none; }
#pl-share.open { display: block; }
#pl-share .ts-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 9px; }
#pl-share label { font-size: 13px; color: #5a6b7b; font-weight: 600; }
#pl-share input[type=text] { flex: 1; min-width: 0; font-size: 12px; padding: 7px 9px; border: 1px solid #d8dde3; border-radius: 7px; }
#pl-share .ts-hint { font-size: 12px; color: #95a5a6; margin-top: 4px; line-height: 1.5; }
.pl-guest-banner { background: #eafaf1; border-bottom: 1px solid #abebc6; color: #1e8449; font-size: 13px; padding: 8px 16px; text-align: center; flex-shrink: 0; }

/* 검색창 자동완성 드롭다운 (카카오 키워드 장소검색) */
.pl-search-box { position: relative; }
.pl-ac { position: absolute; left: 0; top: calc(100% + 4px); z-index: 40; width: 300px; max-width: 80vw; background: #fff; border: 1px solid #e2e6ea; border-radius: 8px; box-shadow: 0 6px 20px rgba(0,0,0,.18); max-height: 320px; overflow-y: auto; display: none; }
.pl-ac.open { display: block; }
.pl-ac-item { padding: 9px 12px; cursor: pointer; border-bottom: 1px solid #f3f5f7; }
.pl-ac-item:last-child { border-bottom: none; }
.pl-ac-item:hover, .pl-ac-item.active { background: #eef6ff; }
.pl-ac-name { font-size: 13.5px; font-weight: 600; color: #2c3e50; }
.pl-ac-cat { font-size: 10.5px; color: #16a085; margin-left: 6px; font-weight: 600; }
.pl-ac-addr { font-size: 11.5px; color: #8a97a3; margin-top: 1px; }
.pl-ac-empty { padding: 12px; font-size: 12.5px; color: #aaa; text-align: center; }

/* 본문: 지도 + 우측 패널 */
#pl-main { flex: 1; min-height: 0; position: relative; overflow: hidden; }
/* 네이버 SDK가 #map 의 position 을 relative 로 바꿔도 부모를 꽉 채우도록 명시적 width/height 사용
   (inset:0 만 쓰면 position 이 바뀌는 순간 높이가 0 으로 무너짐) */
#map { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: #e6eaf0; }
.pl-hint { position: absolute; left: 50%; top: 14px; transform: translateX(-50%); z-index: 5; background: rgba(44,62,80,.88); color: #fff; font-size: 13px; padding: 7px 14px; border-radius: 20px; pointer-events: none; transition: opacity .4s; }

/* 카테고리 범례 */
.pl-legend { position: absolute; left: 12px; bottom: 12px; z-index: 5; background: rgba(255,255,255,.95); border-radius: 8px; padding: 8px 12px; font-size: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.15); display: flex; gap: 12px; flex-wrap: wrap; }
.pl-legend span { display: inline-flex; align-items: center; gap: 5px; }
.dot { width: 12px; height: 12px; border-radius: 50%; display: inline-block; }
.dot.travel { background: #3498db; } .dot.event { background: #e67e22; }
.dot.restaurant { background: #e74c3c; } .dot.etc { background: #7f8c8d; }

/* 번호 마커 (HTML 아이콘) — 흰 배경 + 카테고리색 테두리 + 빨간 번호로 잘 보이게 */
.mk-pin { width: 28px; height: 28px; border-radius: 50% 50% 50% 0; transform: rotate(-45deg); background: #fff; border: 3px solid #3498db; box-shadow: 0 2px 5px rgba(0,0,0,.45); display: flex; align-items: center; justify-content: center; }
.mk-pin b { transform: rotate(45deg); color: #e74c3c; font-weight: 800; font-size: 13px; line-height: 1; }
.mk-pin.cat-event { border-color: #e67e22; } .mk-pin.cat-restaurant { border-color: #e74c3c; } .mk-pin.cat-etc { border-color: #7f8c8d; }
.mk-pin.active { background: #e74c3c; border-color: #c0392b; transform: rotate(-45deg) scale(1.28); }
.mk-pin.active b { color: #fff; }

/* 검색 위치 마커 — 여행지 핀과 확연히 구분(보라 솔리드 + 흰 별, 더 큼) */
.mk-search { width: 34px; height: 34px; border-radius: 50% 50% 50% 0; transform: rotate(-45deg); background: #ff2d2d; border: 3px solid #fff; box-shadow: 0 3px 9px rgba(0,0,0,.5); display: flex; align-items: center; justify-content: center; }
.mk-search b { transform: rotate(45deg); color: #fff; font-size: 16px; line-height: 1; }

/* 좌측 결과 리스트 */
#pl-list { position: absolute; left: 0; top: 0; bottom: 0; width: 264px; max-width: 80vw; background: #fff; box-shadow: 3px 0 12px rgba(0,0,0,.12); z-index: 15; display: none; flex-direction: column; }
#pl-list.open { display: flex; }
#pl-list.open ~ .pl-legend { left: 276px; }
.pl-list-head { padding: 12px 14px; border-bottom: 1px solid #eee; font-size: 14px; font-weight: 700; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0; }
.pl-list-head .lh-close { background: none; border: none; font-size: 20px; color: #aaa; cursor: pointer; line-height: 1; }
.pl-list-body { flex: 1; overflow-y: auto; }
.pl-li { display: flex; gap: 10px; align-items: center; padding: 10px 12px; border-bottom: 1px solid #f1f3f5; cursor: pointer; transition: .12s; }
.pl-li:hover { background: #f7fbff; }
.pl-li.active { background: #fdecea; }
.li-no { flex-shrink: 0; width: 26px; height: 26px; border-radius: 50%; background: #fff; border: 2px solid #3498db; color: #e74c3c; font-weight: 800; font-size: 13px; display: flex; align-items: center; justify-content: center; }
.li-no.cat-event { border-color: #e67e22; } .li-no.cat-restaurant { border-color: #e74c3c; } .li-no.cat-etc { border-color: #7f8c8d; }
.pl-li.active .li-no { background: #e74c3c; border-color: #c0392b; color: #fff; }
.li-body { min-width: 0; flex: 1; }
.li-name { font-size: 13.5px; display: flex; align-items: baseline; gap: 6px; }
.li-name .nm { font-weight: 600; color: #2c3e50; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.li-dist { margin-left: auto; font-size: 11px; color: #3498db; font-weight: 700; white-space: nowrap; flex-shrink: 0; }
.li-sub { font-size: 11.5px; color: #8a97a3; margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pl-list-empty { color: #aaa; font-size: 13px; text-align: center; padding: 30px 12px; }
@media (max-width: 640px) {
    #pl-list { width: 100%; max-width: 100%; top: auto; height: 42vh; box-shadow: 0 -3px 12px rgba(0,0,0,.15); }
    #pl-list.open ~ .pl-legend { display: none; }
}

/* 우측 상세 패널 (마커 클릭 시) */
#pl-panel { position: absolute; top: 0; right: 0; bottom: 0; width: 340px; max-width: 88vw; background: #fff; box-shadow: -3px 0 14px rgba(0,0,0,.15); z-index: 20; transform: translateX(100%); transition: transform .25s; display: flex; flex-direction: column; }
#pl-panel.open { transform: translateX(0); }
.panel-head { padding: 16px 18px 12px; border-bottom: 1px solid #eee; }
.panel-head .cat-badge { font-size: 11px; font-weight: 700; color: #fff; padding: 2px 8px; border-radius: 10px; }
.cat-badge.travel { background: #3498db; } .cat-badge.event { background: #e67e22; }
.cat-badge.restaurant { background: #e74c3c; } .cat-badge.etc { background: #7f8c8d; }
.panel-head h3 { font-size: 19px; margin: 8px 0 6px; }
.panel-head .meta { font-size: 13px; color: #5b6b7b; line-height: 1.7; }
.panel-head .tags { margin-top: 8px; display: flex; gap: 5px; flex-wrap: wrap; }
.panel-head .tags em { font-style: normal; font-size: 11px; background: #eef2f6; color: #5b6b7b; padding: 2px 8px; border-radius: 10px; }
.panel-close { float: right; background: none; border: none; font-size: 22px; color: #aaa; cursor: pointer; line-height: 1; }
.panel-refs { flex: 1; overflow-y: auto; padding: 12px 14px; }
.panel-refs h4 { font-size: 13px; color: #7f8c8d; margin-bottom: 8px; }
.ref-item { display: block; text-decoration: none; color: inherit; border: 1px solid #eceff2; border-radius: 8px; padding: 10px 12px; margin-bottom: 8px; transition: .12s; }
.ref-item:hover { border-color: #3498db; background: #f7fbff; }
.ref-item .rt { font-size: 11px; font-weight: 700; color: #fff; padding: 1px 7px; border-radius: 8px; margin-right: 6px; }
.rt.article { background: #16a085; } .rt.youtube { background: #c0392b; } .rt.blog { background: #8e44ad; }
.rt.official { background: #2c3e50; } .rt.manual { background: #95a5a6; }
.ref-item .rtitle { font-size: 14px; font-weight: 600; margin: 4px 0 2px; }
.ref-item .rsum { font-size: 12px; color: #7f8c8d; }
.ref-empty { color: #aaa; font-size: 13px; text-align: center; padding: 30px 0; }

/* 미좌표(좌표 없는) 장소 패널 — 우측 슬라이드인 */
#pl-nogeo { position: absolute; top: 0; right: 0; bottom: 0; width: 380px; max-width: 92vw; background: #fff; box-shadow: -3px 0 14px rgba(0,0,0,.15); z-index: 25; transform: translateX(100%); transition: transform .25s; display: flex; flex-direction: column; }
#pl-nogeo.open { transform: translateX(0); }
.ng-head { padding: 14px 16px 10px; border-bottom: 1px solid #eee; display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.ng-head h3 { font-size: 16px; margin: 0; flex: 1; }
.ng-head .ng-close { background: none; border: none; font-size: 22px; color: #aaa; cursor: pointer; line-height: 1; }
.ng-sub { font-size: 12px; color: #95a5a6; padding: 8px 16px; line-height: 1.5; border-bottom: 1px solid #f1f3f5; flex-shrink: 0; }
.ng-body { flex: 1; overflow-y: auto; }
.ng-li { padding: 9px 16px; border-bottom: 1px solid #f3f5f7; }
.ng-li .ng-nm { font-size: 13.5px; font-weight: 600; color: #2c3e50; }
.ng-li .ng-cat { font-size: 10px; font-weight: 700; color: #fff; padding: 1px 6px; border-radius: 8px; margin-right: 6px; }
.ng-cat.travel { background: #3498db; } .ng-cat.event { background: #e67e22; }
.ng-cat.restaurant { background: #e74c3c; } .ng-cat.etc { background: #7f8c8d; }
.ng-li .ng-reg { font-size: 11.5px; color: #8a97a3; margin-top: 2px; }
.ng-li .ng-link { font-size: 11.5px; color: #3498db; text-decoration: none; }
.ng-li .ng-link:hover { text-decoration: underline; }
.ng-empty { color: #aaa; font-size: 13px; text-align: center; padding: 30px 12px; }
.ng-row { display: flex; align-items: center; gap: 6px; }
.ng-row .ng-nm { flex: 1; min-width: 0; }
.ng-edit { flex-shrink: 0; background: #eef2f6; border: 1px solid #dde3e9; color: #5b6b7b; font-size: 11px; font-weight: 600; padding: 3px 8px; border-radius: 6px; cursor: pointer; }
.ng-edit:hover { background: #e1e8ef; }
@media (max-width: 640px) { #pl-nogeo { width: 100%; max-width: 100%; } }

/* 수정 모달 (미좌표 장소 이름/분류/좌표 지정) */
.pl-modal { position: fixed; inset: 0; z-index: 1000; background: rgba(0,0,0,.45); display: none; align-items: center; justify-content: center; }
.pl-modal.open { display: flex; }
.pem-box { background: #fff; border-radius: 12px; width: 440px; max-width: 94vw; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 12px 40px rgba(0,0,0,.3); }
.pem-head { padding: 14px 18px; border-bottom: 1px solid #eee; font-size: 16px; font-weight: 700; display: flex; align-items: center; justify-content: space-between; }
.pem-x { background: none; border: none; font-size: 22px; color: #aaa; cursor: pointer; line-height: 1; }
.pem-body { padding: 14px 18px; overflow-y: auto; }
.pem-lbl { display: block; font-size: 12px; font-weight: 700; color: #5a6b7b; margin: 10px 0 4px; }
.pem-lbl:first-child { margin-top: 0; }
.pem-inp { width: 100%; box-sizing: border-box; font-size: 13.5px; padding: 9px 11px; border: 1px solid #d8dde3; border-radius: 7px; }
.pem-results { margin-top: 8px; max-height: 230px; overflow-y: auto; border: 1px solid #eef1f4; border-radius: 8px; }
.pem-res { padding: 9px 11px; border-bottom: 1px solid #f3f5f7; cursor: pointer; }
.pem-res:last-child { border-bottom: none; }
.pem-res:hover { background: #f3f9ff; }
.pem-res.active { background: #e3f1ff; box-shadow: inset 3px 0 0 #3498db; }
.pem-res b { font-size: 13.5px; color: #2c3e50; font-weight: 600; }
.pem-rcat { font-size: 10.5px; color: #16a085; margin-left: 6px; font-weight: 600; }
.pem-raddr { font-size: 11.5px; color: #8a97a3; margin-top: 1px; }
.pem-empty { padding: 12px; font-size: 12.5px; color: #aaa; text-align: center; }
.pem-picked { margin-top: 10px; font-size: 12.5px; color: #1e8449; line-height: 1.5; }
.pem-picked span { color: #8a97a3; }
.pem-hint { margin-top: 10px; font-size: 11.5px; color: #95a5a6; line-height: 1.5; }
.pem-foot { padding: 12px 18px; border-top: 1px solid #eee; display: flex; justify-content: flex-end; gap: 8px; }
</style>
</head>
<body>
<?php if (!$isGuest) render_nav('places'); ?>
<?php if ($isGuest): ?>
<div class="pl-guest-banner">🔗 공유 링크로 보는 중입니다 · 공개 만료: <b><?= htmlspecialchars($shareExpiry) ?></b></div>
<?php endif; ?>

<div class="pl-toolbar">
    <h2>🗺️ 웅이가 간다! (전국의 숨겨진 명소,맛집 지도)</h2>
    <div class="pl-search-box">
        <input type="text" id="addr" placeholder="장소·주소 입력 (예: 경포대, 강릉시)" autocomplete="off"
               oninput="plSuggest(this.value)" onkeydown="plAddrKey(event)" onblur="setTimeout(plAcClose,150)">
        <div id="pl-ac" class="pl-ac"></div>
    </div>
    <button class="btn btn-primary" onclick="plGeocode()">검색</button>
    <select id="category" onchange="plSearchHere()">
        <option value="">전체</option>
        <option value="travel">여행지</option>
        <option value="event">축제</option>
        <option value="restaurant">맛집</option>
        <option value="etc">기타</option>
    </select>
    <select id="radius" onchange="plRadiusChange()">
        <option value="3">3km</option>
        <option value="5" selected>5km</option>
        <option value="10">10km</option>
        <option value="15">15km</option>
        <option value="20">20km</option>
        <option value="30">30km</option>
        <option value="50">50km</option>
    </select>
    <input type="text" id="keyword" placeholder="이름 검색" onkeydown="if(event.key==='Enter')plSearchHere()">
    <button class="btn btn-outline" onclick="plSearchHere()">이 지역 검색</button>
<?php if (!$isGuest): ?>
    <button class="btn btn-outline" onclick="plNoGeoToggle()" title="좌표를 못 찾아 지도에 표시되지 않는 장소 목록">📍 미좌표</button>
    <div class="pl-share-wrap">
        <button class="btn btn-share" onclick="plShareToggle()">🔗 공유</button>
        <div id="pl-share">
            <div class="ts-row">
                <label>공개 기간</label>
                <select id="shareTtl">
                    <option value="3600">1시간</option>
                    <option value="86400" selected>1일</option>
                    <option value="259200">3일</option>
                    <option value="604800">7일</option>
                    <option value="2592000">30일</option>
                </select>
                <button class="btn btn-primary" onclick="plShareCreate()">링크 생성</button>
            </div>
            <div class="ts-row">
                <input type="text" id="shareUrl" readonly placeholder="링크를 생성하면 여기에 표시됩니다" onclick="this.select()">
                <button class="btn btn-outline" onclick="plShareCopy()">복사</button>
            </div>
            <div class="ts-row">
                <button class="btn btn-ghost" onclick="plShareRevoke()">공유 중단</button>
                <span class="ts-hint" id="shareState"></span>
            </div>
            <div class="ts-hint">이 링크를 가진 사람은 로그인 없이 지도를 볼 수 있습니다(읽기전용). 만료되면 자동 차단됩니다.</div>
        </div>
    </div>
<?php endif; ?>
</div>

<div id="pl-main">
    <div id="map"></div>
    <div id="pl-list">
        <div class="pl-list-head"><span id="plListTitle">결과</span><button class="lh-close" onclick="plToggleList(false)" title="목록 닫기">×</button></div>
        <div class="pl-list-body" id="plListBody"></div>
    </div>
    <div class="pl-hint" id="hint">주소를 검색하거나 지도를 옮긴 뒤 '이 지역 검색'을 누르세요</div>
    <div class="pl-legend">
        <span><i class="dot travel"></i>여행지</span>
        <span><i class="dot event"></i>축제</span>
        <span><i class="dot restaurant"></i>맛집</span>
        <span><i class="dot etc"></i>기타</span>
        <span><i class="dot" style="background:#ff2d2d"></i>검색 위치</span>
    </div>
    <div id="pl-panel">
        <div class="panel-head" id="panelHead"></div>
        <div class="panel-refs" id="panelRefs"></div>
    </div>
<?php if (!$isGuest): ?>
    <div id="pl-nogeo">
        <div class="ng-head">
            <h3>📍 미좌표 장소</h3>
            <button class="ng-close" onclick="plNoGeoClose()" title="닫기">×</button>
        </div>
        <div class="ng-sub" id="ngSub">불러오는 중…</div>
        <div class="ng-body" id="ngBody"></div>
    </div>
<?php endif; ?>
</div>

<?php if (!$isGuest): ?>
<div id="pl-edit" class="pl-modal">
    <div class="pem-box">
        <div class="pem-head"><span>✏️ 미좌표 장소 수정</span><button class="pem-x" onclick="plEditClose()">×</button></div>
        <div class="pem-body">
            <label class="pem-lbl">이름</label>
            <input type="text" id="pemName" class="pem-inp">
            <label class="pem-lbl">분류</label>
            <select id="pemCat" class="pem-inp">
                <option value="travel">여행지</option>
                <option value="event">축제</option>
                <option value="restaurant">맛집</option>
                <option value="etc">기타</option>
            </select>
            <label class="pem-lbl">좌표 지정 (카카오 장소 검색 → 선택)</label>
            <input type="text" id="pemSearch" class="pem-inp" placeholder="장소·주소 검색 (예: 정동진 해안)"
                   oninput="plEditSearchDebounced()" onkeydown="if(event.key==='Enter'){event.preventDefault();plEditSearch();}">
            <div id="pemResults" class="pem-results"></div>
            <div id="pemPicked" class="pem-picked"></div>
            <div class="pem-hint">좌표를 선택하지 않고 저장하면 이름·분류만 바뀌고 지도엔 계속 안 뜹니다. 좌표를 선택해 저장하면 지도에 마커가 생깁니다.</div>
        </div>
        <div class="pem-foot">
            <button class="btn btn-ghost" onclick="plEditClose()">취소</button>
            <button class="btn btn-primary" id="pemSave" onclick="plEditSave()">저장</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
var PLACE_NAVER_KEY = <?= json_encode($naverClientId) ?>;
var PL_SHARE        = <?= json_encode($isGuest ? $shareToken : '') ?>; // 게스트면 토큰, 소유자면 ''
var plMap = null, plMarkers = [], plReady = false;
var plFeatures = [], plActive = -1;
var plSearchMarker = null; // 검색으로 이동한 위치 마커(여행지 마커와 별개로 유지)

// 검색 위치 마커 표시(기존 것 교체) — 보라 별 핀
function plSetSearchMarker(lat, lng, label) {
    if (plSearchMarker) plSearchMarker.setMap(null);
    plSearchMarker = new naver.maps.Marker({
        position: new naver.maps.LatLng(lat, lng),
        map: plMap,
        title: label || '검색 위치',
        zIndex: 300, // 여행지 번호 마커 위에
        icon: {
            content: '<div class="mk-search"><b>★</b></div>',
            anchor: new naver.maps.Point(17, 34)
        }
    });
}
var PL_RADII = [3, 5, 10, 15, 20, 30, 50]; // 자동 확장 사다리

// place_api.php URL 빌더 — 게스트면 share 토큰 자동 첨부
function plApiUrl(params) {
    var p = new URLSearchParams(params);
    if (PL_SHARE) p.set('share', PL_SHARE);
    return 'place_api.php?' + p.toString();
}

// ── 네이버 SDK 로더 (검증된 onload Promise 패턴) ──
function plLoadNaver() {
    return new Promise(function (resolve, reject) {
        if (window.naver && window.naver.maps) return resolve();
        if (!PLACE_NAVER_KEY) return reject('no-key');
        var s = document.createElement('script');
        s.src = 'https://oapi.map.naver.com/openapi/v3/maps.js?ncpKeyId=' + encodeURIComponent(PLACE_NAVER_KEY);
        s.onload = function () { resolve(); };
        s.onerror = function () { reject('load-fail'); };
        document.head.appendChild(s);
    });
}

// 지도 repaint — 컨테이너 크기가 나중에 확정되면 호출해야 회색 타일이 안 남는다
function plBumpResize() {
    if (plMap && window.naver && naver.maps) naver.maps.Event.trigger(plMap, 'resize');
}

function plInit() {
    plLoadNaver().then(function () {
        plMap = new naver.maps.Map('map', {
            center: new naver.maps.LatLng(37.5665, 126.9780), // 서울 기본
            zoom: 12
        });
        plReady = true;

        // 생성 직후 컨테이너 크기 보정 (회색 타일 방지) — 레이아웃 확정 타이밍을 놓치지 않게 다단 + 옵저버
        [60, 250, 600].forEach(function (ms) { setTimeout(plBumpResize, ms); });
        window.addEventListener('resize', plBumpResize);
        window.addEventListener('load', plBumpResize);
        if (window.ResizeObserver) {
            // #map 박스 크기가 바뀔 때마다(툴바 줄바꿈·폴더블 등) 자동 repaint
            new ResizeObserver(plBumpResize).observe(document.getElementById('map'));
        }
    }).catch(function (e) {
        document.getElementById('hint').textContent =
            (e === 'no-key') ? '네이버 지도 키가 설정되지 않았습니다 (env/maps.inc)' : '지도 로딩 실패';
    });
}
plInit();

function plClearMarkers() {
    plMarkers.forEach(function (m) { m.setMap(null); });
    plMarkers = [];
}

function plHint(msg) {
    var h = document.getElementById('hint');
    h.textContent = msg; h.style.opacity = 1;
    clearTimeout(plHint._t);
    plHint._t = setTimeout(function () { h.style.opacity = 0; }, 2500);
}

// 반경(km) → 네이버 지도 줌 레벨 (반경이 화면에 알맞게 들어오도록)
function plZoomForRadius(rad) {
    rad = parseFloat(rad);
    if (rad <= 3)       return 16;
    if (rad <= 5)       return 16;
    if (rad <= 10)      return 15;
    if (rad <= 15)      return 15;
    if (rad <= 20)      return 15;
    if (rad <= 30)      return 15;
    return 14;  // 50km
}

// 현재 지도 중심 기준 검색 (선택 반경부터 자동 확장)
function plSearchHere() {
    if (!plReady) return;
    var c = plMap.getCenter();
    plSearch(c.lat(), c.lng(), parseFloat(document.getElementById('radius').value));
}

// 반경 드롭다운 변경 → 줌도 그 반경에 맞게 바꾸고 재검색
function plRadiusChange() {
    if (!plReady) return;
    plMap.setZoom(plZoomForRadius(document.getElementById('radius').value));
    plSearchHere();
}

// 사다리에서 cur 보다 큰 다음 반경 (없으면 null)
function plNextRadius(cur) {
    for (var i = 0; i < PL_RADII.length; i++) { if (PL_RADII[i] > cur) return PL_RADII[i]; }
    return null;
}

// 좌표 기준 검색 → GeoJSON → 마커 렌더
//  expandFrom 가 숫자면: 결과 0곳일 때 다음 반경으로 자동 확장(데이터 나올 때까지, 최대 50km)
function plSearch(lat, lng, expandFrom) {
    var rad = (expandFrom != null) ? expandFrom : parseFloat(document.getElementById('radius').value);
    fetch(plApiUrl({
        module: 'place', action: 'search',
        lat: lat, lng: lng,
        radius: rad,
        category: document.getElementById('category').value,
        keyword: document.getElementById('keyword').value.trim()
    }))
        .then(function (r) { return r.json(); })
        .then(function (geo) {
            var feats = (geo && geo.features) || [];

            // 자동 확장: 결과 없고 더 넓힐 수 있으면 다음 반경으로 재검색
            if (!feats.length && expandFrom != null) {
                var nxt = plNextRadius(rad);
                if (nxt) {
                    document.getElementById('radius').value = String(nxt); // 실제 사용 반경 반영
                    plMap.setZoom(plZoomForRadius(nxt));                    // 넓어진 반경에 맞게 줌아웃
                    plHint(rad + 'km에 없음 → ' + nxt + 'km로 확장 검색…');
                    plSearch(lat, lng, nxt);
                    return;
                }
            }

            plClearMarkers();
            plActive = -1;
            plFeatures = feats;
            feats.forEach(plAddMarker);   // (f, idx) — forEach 2번째 인자가 번호
            plRenderList(feats);
            plToggleList(feats.length > 0);
            if (expandFrom != null) document.getElementById('radius').value = String(rad);
            plHint(feats.length ? (rad + 'km 반경 ' + feats.length + '곳')
                                : ('최대 ' + rad + 'km까지 데이터가 없습니다'));
        })
        .catch(function () { plHint('검색 실패'); });
}

function plAddMarker(f, idx) {
    var pr = f.properties, co = f.geometry.coordinates; // [lng, lat]
    var marker = new naver.maps.Marker({
        position: new naver.maps.LatLng(co[1], co[0]),
        map: plMap,
        title: (idx + 1) + '. ' + pr.name,
        zIndex: 100,
        icon: plMarkerIcon(pr.category, idx + 1, false)
    });
    naver.maps.Event.addListener(marker, 'click', function () { plFocus(idx); });
    plMarkers.push(marker);
}

// 번호 마커 아이콘 HTML — active 시 빨강 강조
function plMarkerIcon(cat, num, active) {
    var c = cat || 'etc';
    return {
        content: '<div class="mk-pin cat-' + c + (active ? ' active' : '') + '"><b>' + num + '</b></div>',
        anchor: new naver.maps.Point(14, 28)
    };
}

// 좌측 결과 리스트 렌더 (번호=마커 번호와 동일)
function plRenderList(feats) {
    var title = document.getElementById('plListTitle');
    var body  = document.getElementById('plListBody');
    if (!feats.length) {
        title.textContent = '결과 0곳';
        body.innerHTML = '<div class="pl-list-empty">이 반경에 데이터가 없습니다</div>';
        return;
    }
    title.textContent = '이 지역 ' + feats.length + '곳';
    body.innerHTML = feats.map(function (f, i) {
        var pr = f.properties, c = pr.category || 'etc';
        var sub = pr.address || (CAT_KO[pr.category] || '');
        var dist = (pr.dist_km != null) ? '<span class="li-dist">~' + plFmtDist(pr.dist_km) + '</span>' : '';
        return '<div class="pl-li" id="pl-li-' + i + '" onclick="plFocus(' + i + ')">' +
            '<span class="li-no cat-' + c + '">' + (i + 1) + '</span>' +
            '<div class="li-body">' +
                '<div class="li-name"><span class="nm">' + plEsc(pr.name) + '</span>' + dist + '</div>' +
                '<div class="li-sub">' + plEsc(sub) + '</div>' +
            '</div></div>';
    }).join('');
}

// 거리(km) 표기: 1km 미만은 m, 그 이상은 소수1자리 km
function plFmtDist(km) {
    km = parseFloat(km);
    if (isNaN(km)) return '';
    if (km < 1) return Math.round(km * 1000) + 'm';
    return (Math.round(km * 10) / 10) + 'km';
}

function plToggleList(show) {
    document.getElementById('pl-list').classList.toggle('open', show);
    setTimeout(plBumpResize, 60); // 지도 폭 변동 → 회색 타일 방지
}

// 리스트/마커 클릭 → 지도 이동 + 양쪽 강조 + 상세패널
function plFocus(idx) {
    var f = plFeatures[idx]; if (!f) return;
    var co = f.geometry.coordinates;
    plMap.panTo(new naver.maps.LatLng(co[1], co[0]));

    // 이전 active 마커 원복
    if (plActive >= 0 && plMarkers[plActive] && plFeatures[plActive]) {
        plMarkers[plActive].setIcon(plMarkerIcon(plFeatures[plActive].properties.category, plActive + 1, false));
    }
    // 새 마커 강조
    if (plMarkers[idx]) plMarkers[idx].setIcon(plMarkerIcon(f.properties.category, idx + 1, true));
    plActive = idx;

    // 리스트 항목 강조 + 스크롤
    var items = document.querySelectorAll('.pl-li');
    for (var i = 0; i < items.length; i++) items[i].classList.remove('active');
    var li = document.getElementById('pl-li-' + idx);
    if (li) { li.classList.add('active'); li.scrollIntoView({ block: 'nearest' }); }

    plOpenPanel(f.properties);
}

var CAT_KO = { travel: '여행지', event: '축제', restaurant: '맛집', etc: '기타' };

function plOpenPanel(pr) {
    var head = document.getElementById('panelHead');
    var meta = [];
    if (pr.address) meta.push('📍 ' + pr.address);
    if (pr.phone)   meta.push('📞 ' + pr.phone);
    if (pr.period_start) meta.push('🗓️ ' + pr.period_start + (pr.period_end ? ' ~ ' + pr.period_end : ''));
    var tags = (pr.attributes && pr.attributes.tags) || [];

    head.innerHTML =
        '<button class="panel-close" onclick="plClosePanel()">×</button>' +
        '<span class="cat-badge ' + (pr.category || 'etc') + '">' + (CAT_KO[pr.category] || '기타') + '</span>' +
        '<h3>' + plEsc(pr.name) + '</h3>' +
        '<div class="meta">' + meta.map(plEsc).join('<br>') + '</div>' +
        (tags.length ? '<div class="tags">' + tags.map(function (t) { return '<em>' + plEsc(t) + '</em>'; }).join('') + '</div>' : '');

    document.getElementById('panelRefs').innerHTML = '<div class="ref-empty">불러오는 중…</div>';
    document.getElementById('pl-panel').classList.add('open');

    fetch(plApiUrl({ module: 'place', action: 'refs', id: pr.id }))
        .then(function (r) { return r.json(); })
        .then(function (d) { plRenderRefs((d && d.refs) || []); })
        .catch(function () { plRenderRefs([]); });
}

function plRenderRefs(refs) {
    var box = document.getElementById('panelRefs');
    if (!refs.length) { box.innerHTML = '<div class="ref-empty">연결된 출처가 없습니다</div>'; return; }
    var RT = { article: '기사', youtube: '유튜브', blog: '블로그', official: '공식', manual: '메모' };
    box.innerHTML = '<h4>출처 ' + refs.length + '건</h4>' + refs.map(function (r) {
        var href = r.url ? plEsc(r.url) : '#';
        return '<a class="ref-item" href="' + href + '" target="_blank" rel="noopener">' +
            '<span class="rt ' + r.source_type + '">' + (RT[r.source_type] || r.source_type) + '</span>' +
            (r.published_at ? '<span class="rsum">' + plEsc(r.published_at) + '</span>' : '') +
            '<div class="rtitle">' + plEsc(r.title || '(제목 없음)') + '</div>' +
            (r.summary ? '<div class="rsum">' + plEsc(r.summary) + '</div>' : '') +
            '</a>';
    }).join('');
}

function plClosePanel() { document.getElementById('pl-panel').classList.remove('open'); }

// ── 미좌표(좌표 없는) 장소 목록 패널 ──
function plNoGeoToggle() {
    var p = document.getElementById('pl-nogeo');
    if (!p) return;
    var open = !p.classList.contains('open');
    p.classList.toggle('open', open);
    if (open) plNoGeoLoad();
}
function plNoGeoClose() {
    var p = document.getElementById('pl-nogeo');
    if (p) p.classList.remove('open');
}
function plNoGeoLoad() {
    var body = document.getElementById('ngBody');
    var sub  = document.getElementById('ngSub');
    body.innerHTML = '<div class="ng-empty">불러오는 중…</div>';
    sub.textContent = '불러오는 중…';
    fetch(plApiUrl({ module: 'place', action: 'ungeocoded', status: 'failed' }))
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.ok) { body.innerHTML = '<div class="ng-empty">' + plEsc((d && d.msg) || '불러오기 실패') + '</div>'; sub.textContent = ''; return; }
            sub.textContent = '좌표를 못 찾아 지도에 표시되지 않는 장소 · 총 ' + d.total + '건';
            plNoGeoRender(d.items || []);
        })
        .catch(function () { body.innerHTML = '<div class="ng-empty">불러오기 실패</div>'; sub.textContent = ''; });
}
var plNoGeoItems = [];
function plNoGeoRender(items) {
    plNoGeoItems = items || [];
    var body = document.getElementById('ngBody');
    if (!plNoGeoItems.length) { body.innerHTML = '<div class="ng-empty">미좌표 장소가 없습니다 🎉</div>'; return; }
    body.innerHTML = plNoGeoItems.map(function (it) {
        var reg = [it.region_lv1, it.region_lv2].filter(Boolean).join(' ');
        var c = it.category || 'etc';
        var more = (it.ref_cnt > 1) ? ' 외 ' + (it.ref_cnt - 1) + '건' : '';
        var link = it.top_url
            ? '<a class="ng-link" href="' + plEsc(it.top_url) + '" onclick="plOpenArticle(this.href);return false;">🔗 ' + plEsc(it.top_title || '원문') + more + '</a>'
            : '<span class="ng-reg">연결된 출처 없음</span>';
        return '<div class="ng-li" id="ng-li-' + it.id + '">' +
            '<div class="ng-row">' +
                '<div class="ng-nm"><span class="ng-cat ' + c + '">' + (CAT_KO[c] || '기타') + '</span>' + plEsc(it.name) + '</div>' +
                '<button class="ng-edit" onclick="plEditOpen(' + it.id + ')">✏️ 수정</button>' +
            '</div>' +
            (reg ? '<div class="ng-reg">' + plEsc(reg) + '</div>' : '') +
            '<div style="margin-top:3px">' + link + '</div>' +
        '</div>';
    }).join('');
}

// 기사 원문 → JS 팝업창
function plOpenArticle(url) {
    if (!url) return;
    window.open(url, 'plArticle', 'width=920,height=860,scrollbars=yes,resizable=yes,menubar=no,toolbar=no');
}

// ── 미좌표 장소 수정 모달 (이름/분류 변경 + 카카오로 좌표 직접 지정) ──
var plEditId = 0, plEditResults = [], plEditSel = null, plEditTimer = null;

function plEditOpen(id) {
    var it = null;
    for (var i = 0; i < plNoGeoItems.length; i++) { if (plNoGeoItems[i].id == id) { it = plNoGeoItems[i]; break; } }
    if (!it) return;
    plEditId = id; plEditSel = null; plEditResults = [];
    document.getElementById('pemName').value = it.name || '';
    document.getElementById('pemCat').value  = it.category || 'travel';
    document.getElementById('pemSearch').value = it.name || '';
    document.getElementById('pemPicked').innerHTML = '';
    document.getElementById('pemResults').innerHTML = '';
    document.getElementById('pl-edit').classList.add('open');
    plEditSearch(); // 장소명으로 즉시 후보 검색
    setTimeout(function () { document.getElementById('pemSearch').focus(); }, 50);
}
function plEditClose() {
    document.getElementById('pl-edit').classList.remove('open');
    plEditId = 0; plEditSel = null;
}
function plEditSearchDebounced() { clearTimeout(plEditTimer); plEditTimer = setTimeout(plEditSearch, 250); }
function plEditSearch() {
    var q = document.getElementById('pemSearch').value.trim();
    var box = document.getElementById('pemResults');
    if (q.length < 2) { box.innerHTML = '<div class="pem-empty">2글자 이상 입력</div>'; return; }
    box.innerHTML = '<div class="pem-empty">검색 중…</div>';
    fetch(plApiUrl({ module: 'place', action: 'suggest', q: q }))
        .then(function (r) { return r.json(); })
        .then(function (d) {
            var items = (d && d.items) || [];
            plEditResults = items;
            if (!items.length) { box.innerHTML = '<div class="pem-empty">검색 결과 없음</div>'; return; }
            box.innerHTML = items.map(function (it, i) {
                return '<div class="pem-res" id="pem-res-' + i + '" onclick="plEditPick(' + i + ')">' +
                    '<b>' + plEsc(it.name) + '</b>' +
                    (it.category ? '<span class="pem-rcat">' + plEsc(it.category) + '</span>' : '') +
                    '<div class="pem-raddr">' + plEsc(it.address || '') + '</div>' +
                '</div>';
            }).join('');
        })
        .catch(function () { box.innerHTML = '<div class="pem-empty">검색 실패</div>'; });
}
function plEditPick(i) {
    var it = plEditResults[i]; if (!it) return;
    plEditSel = { lat: it.lat, lng: it.lng, name: it.name, addr: it.address };
    var els = document.querySelectorAll('.pem-res');
    for (var k = 0; k < els.length; k++) els[k].classList.remove('active');
    var el = document.getElementById('pem-res-' + i); if (el) el.classList.add('active');
    document.getElementById('pemPicked').innerHTML =
        '✅ 지정 좌표: <b>' + plEsc(it.name) + '</b> <span>' + plEsc(it.address || '') + '</span>';
}
function plEditSave() {
    if (!plEditId) return;
    var name = document.getElementById('pemName').value.trim();
    var cat  = document.getElementById('pemCat').value;
    if (!name) { alert('이름을 입력하세요'); return; }
    var params = { module: 'place', action: 'place_update', id: plEditId, name: name, category: cat };
    if (plEditSel) { params.lat = plEditSel.lat; params.lng = plEditSel.lng; }
    var btn = document.getElementById('pemSave'); btn.disabled = true; btn.textContent = '저장 중…';
    fetch(plApiUrl(params))
        .then(function (r) { return r.json(); })
        .then(function (d) {
            btn.disabled = false; btn.textContent = '저장';
            if (!d || !d.ok) { alert((d && d.msg) || '저장 실패'); return; }
            plEditClose();
            plNoGeoLoad(); // 좌표 지정됐으면 목록에서 빠짐
        })
        .catch(function () { btn.disabled = false; btn.textContent = '저장'; alert('저장 실패'); });
}

// 주소 → 좌표 (서버 GeoCoder) → 지도 이동 후 검색
function plGeocode() {
    var addr = document.getElementById('addr').value.trim();
    if (!addr || !plReady) return;
    fetch(plApiUrl({ module: 'place', action: 'geocode', address: addr }))
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.ok) { plHint(d.msg || '주소를 찾을 수 없습니다'); return; }
            plMap.setCenter(new naver.maps.LatLng(d.lat, d.lng));
            plMap.setZoom(plZoomForRadius(document.getElementById('radius').value));
            plSetSearchMarker(d.lat, d.lng, d.address || addr);
            plSearch(d.lat, d.lng, parseFloat(document.getElementById('radius').value)); // 선택 반경부터 자동 확장
        })
        .catch(function () { plHint('지오코딩 실패'); });
}

// ── 검색창 자동완성 (카카오 키워드 장소검색) ──
var plAcItems = [], plAcIndex = -1, plAcTimer = null;

function plSuggest(q) {
    q = (q || '').trim();
    clearTimeout(plAcTimer);
    if (q.length < 2) { plAcClose(); return; }
    plAcTimer = setTimeout(function () {       // 디바운스 220ms — 타이핑마다 호출 방지
        fetch(plApiUrl({ module: 'place', action: 'suggest', q: q }))
            .then(function (r) { return r.json(); })
            .then(function (j) { plAcRender((j && j.items) || []); })
            .catch(function () { plAcClose(); });
    }, 220);
}

function plAcRender(items) {
    plAcItems = items; plAcIndex = -1;
    var box = document.getElementById('pl-ac');
    if (!items.length) {
        box.innerHTML = '<div class="pl-ac-empty">검색 결과가 없습니다</div>';
        box.classList.add('open'); return;
    }
    box.innerHTML = items.map(function (it, i) {
        return '<div class="pl-ac-item" data-i="' + i + '" onmousedown="plAcPick(' + i + ')">' +
            '<div class="pl-ac-name">' + plEsc(it.name) +
                (it.category ? '<span class="pl-ac-cat">' + plEsc(it.category) + '</span>' : '') +
            '</div>' +
            (it.address ? '<div class="pl-ac-addr">' + plEsc(it.address) + '</div>' : '') +
        '</div>';
    }).join('');
    box.classList.add('open');
}

function plAcClose() {
    var box = document.getElementById('pl-ac');
    if (box) box.classList.remove('open');
    plAcIndex = -1;
}

function plAcHighlight() {
    var els = document.querySelectorAll('.pl-ac-item');
    for (var i = 0; i < els.length; i++) {
        var on = (i === plAcIndex);
        els[i].classList.toggle('active', on);
        if (on) els[i].scrollIntoView({ block: 'nearest' });
    }
}

// 후보 선택 → 지도 이동 + 주변 여행지 검색 (onmousedown: input blur보다 먼저 실행)
function plAcPick(i) {
    var it = plAcItems[i]; if (!it || !plReady) return;
    document.getElementById('addr').value = it.name;
    plAcClose();
    plMap.setCenter(new naver.maps.LatLng(it.lat, it.lng));
    plMap.setZoom(plZoomForRadius(document.getElementById('radius').value));
    plSetSearchMarker(it.lat, it.lng, it.name);
    plSearch(it.lat, it.lng, parseFloat(document.getElementById('radius').value));
}

// 검색창 키보드: ↑↓ 후보이동, Enter 선택/검색, Esc 닫기
function plAddrKey(e) {
    var box = document.getElementById('pl-ac');
    var open = box && box.classList.contains('open') && plAcItems.length > 0;
    if (e.key === 'ArrowDown' && open) {
        e.preventDefault(); plAcIndex = (plAcIndex + 1) % plAcItems.length; plAcHighlight();
    } else if (e.key === 'ArrowUp' && open) {
        e.preventDefault(); plAcIndex = (plAcIndex - 1 + plAcItems.length) % plAcItems.length; plAcHighlight();
    } else if (e.key === 'Enter') {
        if (open) { e.preventDefault(); plAcPick(plAcIndex >= 0 ? plAcIndex : 0); } // 강조 없으면 첫 후보
        else { plGeocode(); }
    } else if (e.key === 'Escape') {
        plAcClose();
    }
}

// ── 한시적 공개 공유 링크 (소유자 전용) ──
function plShareToggle() {
    var p = document.getElementById('pl-share');
    if (p) p.classList.toggle('open');
}
function plShareShow(token, exp) {
    document.getElementById('shareUrl').value = location.origin + '/places.php?share=' + token;
    document.getElementById('shareState').textContent = exp ? ('현재 공개 중 · 만료: ' + exp) : '';
}
function plShareCreate() {
    var ttl = document.getElementById('shareTtl').value;
    fetch(plApiUrl({ module: 'place', action: 'share_create', ttl: ttl }))
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (!j.ok) { plHint(j.msg || '링크 생성 실패'); return; }
            plShareShow(j.token, j.expires_at);
            plHint('공유 링크 생성됨');
        })
        .catch(function () { plHint('링크 생성 실패'); });
}
function plShareCopy() {
    var el = document.getElementById('shareUrl');
    if (!el.value) { plHint('먼저 링크를 생성하세요'); return; }
    el.select();
    if (navigator.clipboard) navigator.clipboard.writeText(el.value);
    else document.execCommand('copy');
    plHint('링크 복사됨');
}
function plShareRevoke() {
    fetch(plApiUrl({ module: 'place', action: 'share_revoke' }))
        .then(function (r) { return r.json(); })
        .then(function () {
            document.getElementById('shareUrl').value = '';
            document.getElementById('shareState').textContent = '공유 중단됨';
            plHint('공유를 중단했습니다');
        })
        .catch(function () { plHint('공유 중단 실패'); });
}
function plShareLoad() {  // 페이지 진입 시 기존 활성 링크 표시
    fetch(plApiUrl({ module: 'place', action: 'share_status' }))
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j.ok && j.token) plShareShow(j.token, j.expires_at); })
        .catch(function () {});
}
if (!PL_SHARE) plShareLoad(); // 소유자(로그인)일 때만 기존 링크 조회

function plEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}
</script>
</body>
</html>
<?php /* end places.php */ ?>
