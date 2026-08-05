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
// 트립 공유 링크(?trip=토큰): 유효하면 로그인 없이 그 여행지도(경로+찜)만 게스트 열람(보기 전용)
$tripToken = trim((string)($_GET['trip'] ?? ''));
$tripShareName = '';   // 공유 트립 이름(제목·OG·게스트 보기전용 분기용)
if (!$isGuest && $tripToken !== '') {
    $tripData = $place->tripByToken($tripToken);
    if ($tripData !== null) {
        $isGuest = true;
        $tripShareName = (string)($tripData['name'] ?? '');
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
<?php if ($tripShareName !== ''): $_t = htmlspecialchars($tripShareName, ENT_QUOTES, 'UTF-8'); ?>
<title><?= $_t ?> · 여행지도</title>
<meta property="og:title" content="<?= $_t ?>">
<meta property="og:description" content="📍 여행 경로와 추천 장소를 지도에서 확인하세요">
<meta property="og:type" content="website">
<?php else: ?>
<title>웅이가 간다! (전국의 숨겨진 명소,맛집 지도)</title>
<?php endif; ?>
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
.pl-ac-grp { padding: 6px 12px 4px; font-size: 11px; font-weight: 700; color: #95a5a6; background: #f7f9fb; border-bottom: 1px solid #eef1f4; position: sticky; top: 0; }

/* 상단 태그 칩 바 (가로 스크롤) */
#pl-tagbar { display: flex; align-items: center; gap: 6px; padding: 7px 12px; background: #fff; border-top: 1px solid #f0f2f5; box-shadow: 0 1px 3px rgba(0,0,0,.05); flex-shrink: 0; }
.tb-chips { display: flex; gap: 6px; overflow-x: auto; flex: 1; scrollbar-width: thin; }
.tb-chips::-webkit-scrollbar { height: 5px; }
.tb-chips::-webkit-scrollbar-thumb { background: #d8dde3; border-radius: 3px; }
.tb-chip { flex-shrink: 0; white-space: nowrap; font-size: 12.5px; font-weight: 600; padding: 6px 12px; border-radius: 16px; background: #f4f6f8; border: 1px solid #e2e7ec; color: #5a6b7b; cursor: pointer; transition: .12s; }
.tb-chip:hover { background: #eaf3fb; border-color: #cfe4f7; color: #2471a3; }
.tb-chip.active { background: #3498db; border-color: #2980b9; color: #fff; }
.tb-chip .tb-cnt { font-size: 10.5px; opacity: .6; margin-left: 4px; font-weight: 500; }
.tb-chip.active .tb-cnt { opacity: .9; }
.tb-more { flex-shrink: 0; font-size: 12px; font-weight: 600; padding: 6px 11px; border-radius: 14px; background: #fff; border: 1px dashed #cdd6de; color: #7b8794; cursor: pointer; white-space: nowrap; }
.tb-more:hover { background: #f4f6f8; }
/* 분류 칩바 — 멀티선택(전체 = 전체선택/해제 토글, 개별 = on/off). active 는 그 분류 색. */
#pl-catbar { display: flex; align-items: center; gap: 6px; padding: 8px 12px; background: #fff; border-top: 1px solid #f0f2f5; flex-shrink: 0; }
.cb-lbl { font-size: 11.5px; color: #8b97a2; flex-shrink: 0; margin-right: 2px; }
.cb-chips { display: flex; gap: 6px; overflow-x: auto; flex: 0 1 auto; scrollbar-width: thin; }
.cb-chips::-webkit-scrollbar { height: 5px; }
.cb-chips::-webkit-scrollbar-thumb { background: #d8dde3; border-radius: 3px; }
.cb-chip { flex-shrink: 0; white-space: nowrap; font-size: 13px; font-weight: 700; padding: 6px 14px; border-radius: 18px; background: #f1f3f5; border: 1px solid #e2e7ec; color: #5a6b7b; cursor: pointer; transition: .12s; }
.cb-chip:hover { filter: brightness(.97); }
.cb-chip.active { color: #fff; border-color: transparent; }
.cb-chip.cat-travel.active     { background: #3498db; }
.cb-chip.cat-restaurant.active { background: #e74c3c; }
.cb-chip.cat-stay.active       { background: #8e44ad; }
.cb-chip.cat-camping.active    { background: #27ae60; }
.cb-chip.cat-etc.active        { background: #7f8c8d; }
.cb-chip.cb-all.active         { background: #34495e; }
/* 줌인 주변 오버레이 ON/OFF 토글 (칩바 우측 고정) */
.cb-ov { flex-shrink: 0; white-space: nowrap; font-size: 12px; font-weight: 700; padding: 6px 12px; border-radius: 18px; background: #f1f3f5; border: 1px solid #e2e7ec; color: #8b97a2; cursor: pointer; transition: .12s; }
.cb-ov:hover { filter: brightness(.97); }
.cb-ov.on { background: #16a085; border-color: transparent; color: #fff; }
/* 지역(시도) 선택 바 — 2단계: 권역 칩 → 시도 칩 */
#pl-regionbar { display: flex; align-items: center; gap: 6px; padding: 7px 12px; background: #fbfdfc; border-top: 1px solid #f0f2f5; flex-shrink: 0; }
/* 공유된 여행지도(보기 전용): 검색·지역·분류·태그 바 전부 숨기고 지도만 */
body.trip-view .pl-toolbar,
body.trip-view #pl-regionbar,
body.trip-view #pl-catbar,
body.trip-view #pl-monthbar,
body.trip-view .pl-fbar { display: none !important; }
.rb-lbl { font-size: 11.5px; color: #8b97a2; flex-shrink: 0; margin-right: 2px; }
.rb-chips { display: flex; gap: 6px; overflow-x: auto; flex: 1; scrollbar-width: thin; }
.rb-chips::-webkit-scrollbar { height: 5px; }
.rb-chips::-webkit-scrollbar-thumb { background: #d8dde3; border-radius: 3px; }
.rb-chip { flex-shrink: 0; white-space: nowrap; font-size: 12.5px; font-weight: 600; padding: 6px 13px; border-radius: 16px; background: #eef6f2; border: 1px solid #d6e8df; color: #2c7a57; cursor: pointer; transition: .12s; }
.rb-chip:hover { background: #e1f1e9; border-color: #b9dcc8; }
.rb-chip.active { background: #16a085; border-color: #138d75; color: #fff; }
.rb-chip.rb-all { background: #fff; border-color: #dfe4e8; color: #7b8794; }
.rb-chip.rb-all.active { background: #16a085; border-color: #138d75; color: #fff; }
.rb-chip.rb-back { background: #fff; border-color: #cdd6de; color: #5a6b7b; }
/* 월(방문시기) 바 */
#pl-monthbar { display: flex; align-items: center; gap: 6px; padding: 6px 12px; background: #fff; border-top: 1px solid #f5f6f8; flex-shrink: 0; }
.mb-lbl { font-size: 11.5px; color: #aab3bc; flex-shrink: 0; margin-right: 2px; }
.mb-chips { display: flex; gap: 5px; overflow-x: auto; flex: 1; scrollbar-width: thin; }
.mb-chips::-webkit-scrollbar { height: 5px; }
.mb-chips::-webkit-scrollbar-thumb { background: #d8dde3; border-radius: 3px; }
.mb-chip { flex-shrink: 0; min-width: 34px; text-align: center; font-size: 12px; font-weight: 600; padding: 5px 10px; border-radius: 14px; background: #fff; border: 1px solid #ecdcc6; color: #b9712a; cursor: pointer; transition: .12s; }
.mb-chip:hover { background: #fdf3e7; }
.mb-chip.active { background: #e67e22; border-color: #d35400; color: #fff; }
/* 맛집 바(3행) — 가이드칩 + 음식종류 + 등급칩 */
#pl-foodbar { display: flex; align-items: center; gap: 6px; padding: 6px 12px; background: #fff; border-top: 1px solid #f5f6f8; flex-shrink: 0; }
#pl-staybar, #pl-campbar { display: flex; align-items: center; gap: 6px; padding: 6px 12px; background: #fff; border-top: 1px solid #f5f6f8; flex-shrink: 0; }
/* 분류별 태그 검색창(자동완성) */
.pl-tagsearch { position: relative; flex-shrink: 0; width: 128px; }
.pl-tagsearch-in { width: 100%; box-sizing: border-box; font-size: 12.5px; padding: 5px 9px; border: 1px solid #d8dde3; border-radius: 14px; background: #f8fafc; outline: none; }
.pl-tagsearch-in:focus { border-color: #93b8e0; background: #fff; }
.pl-tagac { display: none; position: absolute; top: calc(100% + 3px); left: 0; min-width: 180px; max-width: 240px; max-height: 240px; overflow-y: auto; background: #fff; border: 1px solid #d8dde3; border-radius: 9px; box-shadow: 0 6px 18px rgba(0,0,0,.16); z-index: 40; }
.pl-tagac.open { display: block; }
.pl-tagac-item { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 7px 11px; font-size: 13px; color: #34495e; cursor: pointer; border-bottom: 1px solid #f3f5f7; }
.pl-tagac-item:last-child { border-bottom: none; }
.pl-tagac-item.active, .pl-tagac-item:hover { background: #eef5fc; }
.pl-tagac-item.on { color: #2471a3; font-weight: 700; }
.pl-tagac-cnt { font-size: 11px; color: #93a1ad; flex-shrink: 0; }
.pl-tagac-empty { padding: 9px 11px; font-size: 12.5px; color: #93a1ad; }

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
.dot.travel { background: #3498db; } .dot.stay { background: #8e44ad; }
.dot.restaurant { background: #e74c3c; } .dot.camping { background: #27ae60; } .dot.etc { background: #7f8c8d; }

/* 현재위치 버튼 (지도 좌상단 플로팅, GPS 크로스헤어) */
.pl-myloc { position: absolute; left: 12px; top: 12px; z-index: 6; width: 42px; height: 42px; border-radius: 50%; background: #fff; border: none; box-shadow: 0 2px 8px rgba(0,0,0,.25); cursor: pointer; display: flex; align-items: center; justify-content: center; color: #3498db; transition: .15s; }
#pl-list.open ~ .pl-myloc { left: 276px; }
.pl-myloc:hover { background: #f0f7ff; }
.pl-myloc.loading { pointer-events: none; opacity: .65; }
.pl-myloc.loading svg { animation: pl-spin 1s linear infinite; }
@keyframes pl-spin { to { transform: rotate(360deg); } }

/* 번호 마커 (HTML 아이콘) — 흰 배경 + 카테고리색 테두리 + 빨간 번호로 잘 보이게 */
.mk-pin { width: 28px; height: 28px; border-radius: 50% 50% 50% 0; transform: rotate(-45deg); background: #fff; border: 3px solid #3498db; box-shadow: 0 2px 5px rgba(0,0,0,.45); display: flex; align-items: center; justify-content: center; }
.mk-pin b { transform: rotate(45deg); color: #e74c3c; font-weight: 800; font-size: 13px; line-height: 1; }
.mk-pin.cat-stay { border-color: #8e44ad; } .mk-pin.cat-restaurant { border-color: #e74c3c; } .mk-pin.cat-camping { border-color: #27ae60; } .mk-pin.cat-etc { border-color: #7f8c8d; }
.mk-pin.active { background: #e74c3c; border-color: #c0392b; transform: rotate(-45deg) scale(1.28); }
.mk-pin.active b { color: #fff; }
/* 줌인 시 마커 옆 라벨/말풍선(순위·이름·리뷰수) — 마커 오른쪽(오프셋은 인라인 transform) */
.mk-label { display: inline-block; background: rgba(255,255,255,.96); border: 1px solid #d7dbe0; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,.2); }
.mk-bubble { display: inline-block; background: rgba(255,255,255,.97); border: 1px solid #cfd5db; border-radius: 8px; box-shadow: 0 2px 9px rgba(0,0,0,.25); padding: 2px; max-width: 210px; }
.mkb-row { display: flex; align-items: center; gap: 4px; padding: 3px 7px; font-size: 11.5px; line-height: 1.4; white-space: nowrap; color: #2c3e50; cursor: pointer; border-radius: 5px; }
.mk-bubble .mkb-row + .mkb-row { border-top: 1px solid #eef0f3; }
.mkb-row:hover { background: #f3f6fa; }
.mkb-row .mkl-rank { background: #e74c3c; color: #fff; border-radius: 8px; min-width: 15px; height: 15px; display: inline-flex; align-items: center; justify-content: center; font-size: 9.5px; font-weight: 800; padding: 0 4px; flex-shrink: 0; }
.mkb-row .mkl-nm { font-weight: 700; max-width: 130px; overflow: hidden; text-overflow: ellipsis; }
.mkb-row .mkl-rev { color: #7a8492; font-size: 10.5px; flex-shrink: 0; }
.mkb-more { font-size: 10px; color: #9aa3ad; text-align: center; padding: 2px; }
/* 맛집 마커 — 가이드색 원형 + 흰 포크·나이프 + 우상단 숫자 배지 */
.mk-food { position: relative; width: 30px; height: 30px; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 2px 6px rgba(0,0,0,.45); display: flex; align-items: center; justify-content: center; box-sizing: border-box; background: #e74c3c; transition: transform .1s; }
.mk-food .mk-fk { width: 16px; height: 16px; fill: #fff; }
.mk-food .mk-fnum { position: absolute; top: -7px; right: -7px; min-width: 15px; height: 15px; padding: 0 3px; box-sizing: border-box; background: #fff; border: 1px solid rgba(0,0,0,.28); border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 800; color: #e74c3c; line-height: 1; white-space: nowrap; }   /* 맛집 번호(리스트와 1:1) = 우상단 */
.mk-food .mk-fgrade { position: absolute; bottom: -7px; right: -7px; height: 14px; padding: 0 2px; box-sizing: border-box; background: #fff; border: 1px solid rgba(0,0,0,.28); border-radius: 999px; display: inline-flex; align-items: center; white-space: nowrap; }   /* 가이드 등급(리본/별) = 우하단 */
.mk-food .mk-fgrade .mk-rb { width: 8px; height: 10px; fill: #1f3a93; }            /* 블루리본 = 네이비 리본 */
.mk-food .mk-fgrade .mk-star { font-size: 9px; font-style: normal; color: #d4a23a; line-height: 1; }  /* 미쉐린 = 골드 별 */
.mk-food .mk-fgrade .mk-gstar { color: #1a9c4f; }                                  /* 그린스타 */
.mk-food .mk-fgrade .mk-bib { font-size: 8px; font-style: normal; font-weight: 800; color: #c0392b; line-height: 1; }
.mk-food.active { transform: scale(1.38); background: #2979ff !important; border-color: #fff; z-index: 1000; animation: pl-food-glow 1.7s ease-in-out infinite; }
@keyframes pl-food-glow {
    0%, 100% { box-shadow: 0 0 5px 2px rgba(41,121,255,.45), 0 4px 11px rgba(0,0,0,.5); }
    50%      { box-shadow: 0 0 16px 6px rgba(41,121,255,.85), 0 4px 11px rgba(0,0,0,.5); }
}
.mk-food.active .mk-fnum { background: #2c3e50; border-color: #2c3e50; color: #fff; }
.mk-food.active .mk-fgrade .mk-rb { fill: #9ec3f5; }
.mk-food.active .mk-fgrade .mk-gstar { color: #6ee29b; }
/* 숙소·캠핑 마커 — 맛집과 같은 분류색 원형 + 흰 아이콘(숙소=침대 / 캠핑=텐트)으로 번호핀과 구분 */
.mk-place { position: relative; width: 30px; height: 30px; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 2px 6px rgba(0,0,0,.45); display: flex; align-items: center; justify-content: center; box-sizing: border-box; transition: transform .1s; }
.mk-place .mk-fk { width: 18px; height: 18px; fill: #fff; }
.mk-place.cat-stay { background: #8e44ad; } .mk-place.cat-camping { background: #27ae60; }
.mk-place.active { transform: scale(1.38); background: #2979ff !important; border-color: #fff; z-index: 1000; animation: pl-food-glow 1.7s ease-in-out infinite; }

/* 검색 위치 마커 — 여행지 핀과 확연히 구분(보라 솔리드 + 흰 별, 더 큼) */
.mk-search { width: 34px; height: 34px; border-radius: 50% 50% 50% 0; transform: rotate(-45deg); background: #ff2d2d; border: 3px solid #fff; box-shadow: 0 3px 9px rgba(0,0,0,.5); display: flex; align-items: center; justify-content: center; }
.mk-search b { transform: rotate(45deg); color: #fff; font-size: 16px; line-height: 1; }

/* 좌측 결과 리스트 */
#pl-list { position: absolute; left: 0; top: 0; bottom: 0; width: 264px; max-width: 80vw; background: #fff; box-shadow: 3px 0 12px rgba(0,0,0,.12); z-index: 15; display: none; flex-direction: column; }
#pl-list.open { display: flex; }
#pl-list.open ~ .pl-legend { left: 276px; }
.pl-list-head { padding: 12px 14px; border-bottom: 1px solid #eee; font-size: 14px; font-weight: 700; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0; }
.pl-list-head .lh-close { background: none; border: none; font-size: 20px; color: #aaa; cursor: pointer; line-height: 1; }
.pl-list-body { flex: 1; min-height: 0; display: flex; flex-direction: column; overflow: hidden; }
.pl-sec { flex: 1 1 0; min-height: 64px; overflow-y: auto; }                 /* 분류별 독립 스크롤 영역(상하 분할) */
.pl-sec + .pl-sec { border-top: 3px solid #e1e6eb; }
.pl-sec-hd { position: sticky; top: 0; z-index: 2; background: #f6f8fa; border-bottom: 1px solid #e6eaee; padding: 6px 12px; font-size: 12px; font-weight: 800; color: #56657a; display: flex; align-items: center; gap: 6px; }
.pl-sec-hd .dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; }
.pl-sec-hd b { color: #cf5b4e; font-weight: 800; }
.pl-li { display: flex; gap: 10px; align-items: center; padding: 10px 12px; border-bottom: 1px solid #f1f3f5; cursor: pointer; transition: .12s; }
.pl-li:hover { background: #f7fbff; }
.pl-li.active { background: #fdecea; }
.li-no { flex-shrink: 0; width: 26px; height: 26px; border-radius: 50%; background: #fff; border: 2px solid #3498db; color: #e74c3c; font-weight: 800; font-size: 13px; display: flex; align-items: center; justify-content: center; }
.li-no.cat-stay { border-color: #8e44ad; } .li-no.cat-restaurant { border-color: #e74c3c; } .li-no.cat-camping { border-color: #27ae60; } .li-no.cat-etc { border-color: #7f8c8d; }
.pl-li.active .li-no { background: #e74c3c; border-color: #c0392b; color: #fff; }
.li-body { min-width: 0; flex: 1; }
.li-name { font-size: 13.5px; display: flex; align-items: center; gap: 5px; }
.li-name .nm { font-weight: 600; color: #2c3e50; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.li-dist { font-size: 11px; color: #3498db; font-weight: 700; white-space: nowrap; flex-shrink: 0; }
.li-sub { font-size: 11.5px; color: #8a97a3; margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
/* 이름 우측 메타(거리·기사수) */
.li-meta { margin-left: auto; flex-shrink: 0; display: flex; align-items: baseline; gap: 6px; }
.li-refs { flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; min-width: 12px; height: 12px; padding: 0 3px; box-sizing: border-box; border-radius: 999px; background: #fcebe9; color: #cf5b4e; font-size: 8px; font-weight: 700; line-height: 1; }
/* 이름 아래 태그(#둘레길 #호수) — 주소 대신 표시 */
.li-tags { display: flex; gap: 7px; margin-top: 3px; white-space: nowrap; overflow: hidden; }
.li-tag { flex-shrink: 0; font-size: 11px; font-weight: 600; color: #2980b9; }
/* 네이버 평점·리뷰 한 줄 */
.li-nv { display: flex; align-items: center; gap: 6px; margin-top: 3px; font-size: 11.5px; color: #5a6570; }
.li-nv-it { white-space: nowrap; }
.li-nv-sep { color: #cbd2d8; }
/* 맛집 가이드 배지(블루리본·미쉐린 등급) — 카드 */
.li-guides { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 3px; }
.li-guide { flex-shrink: 0; font-size: 10px; font-weight: 700; color: #fff; padding: 1px 7px; border-radius: 9px; line-height: 1.5; letter-spacing: -.2px; }
/* 가이드 칩 안 등급 글리프 — 블루리본=흰 리본×N / 미쉐린=흰 ★×N */
.li-guide .lg-grade { margin-left: 3px; font-size: 9px; letter-spacing: -.5px; vertical-align: 1px; }
.li-guide .lg-grade .mk-rb { width: 7px; height: 9px; fill: #fff; vertical-align: -1px; margin-left: 1px; }
.li-guide .lg-grade .lg-gs { color: #2ecc71; }   /* 그린스타 */
.li-guide .lg-grade .lg-bib { font-size: 8.5px; }
/* 상세패널 가이드 배지 */
.panel-guides { display: flex; flex-wrap: wrap; gap: 5px; margin: 6px 0 2px; }
.panel-guides .li-guide { font-size: 11px; padding: 2px 9px; }
.panel-guides .li-guide .lg-grade { font-size: 10px; }
.panel-guides .li-guide .lg-grade .mk-rb { width: 8px; height: 10px; }
/* 가이드 칩(1행, 맛집 모드) — 가이드색 테두리, 선택 시 가이드색 채움 */
.tb-chip.guide { border-color: var(--gc, #e2e7ec); color: var(--gc, #5a6b7b); }
.tb-chip.guide:hover { background: #f4f6f8; }
.tb-chip.guide.active { background: var(--gc, #3498db); border-color: var(--gc, #2980b9); color: #fff; }
/* 음식 종류 칩(2행, 맛집 모드) — 파랑 계열로 월칩과 구분 */
.mb-chip.food { min-width: 0; background: #eef5fb; border-color: #d4e6f5; color: #2471a3; }
.mb-chip.food:hover { background: #e2eef9; }
.mb-chip.food.active { background: #2980b9; border-color: #2471a3; color: #fff; }
.mb-chip.food .tb-cnt { font-size: 10px; opacity: .6; margin-left: 4px; }
.mb-chip.food.active .tb-cnt { opacity: .9; }
/* 등급 칩(2행, 맛집 모드) — 음식칩과 색으로 구분 */
.mb-chip.food.grade { background: #fdeef4; border-color: #f4c2d7; color: #b1356d; }
.mb-chip.food.grade:hover { background: #fbe0ec; }
.mb-chip.food.grade.active { background: #b1356d; border-color: #97275a; color: #fff; }
.lh-acts { display: flex; align-items: center; gap: 6px; }
.lh-edit { background: #eef2f6; border: 1px solid #dde3e9; color: #5b6b7b; font-size: 12px; font-weight: 600; padding: 4px 9px; border-radius: 6px; cursor: pointer; line-height: 1; white-space: nowrap; }
.lh-edit:hover { background: #e1e8ef; }
.lh-edit.active { background: #2980b9; border-color: #2471a3; color: #fff; }
.li-edit { display: none; flex-shrink: 0; font-size: 12px; padding: 4px 7px; border-radius: 6px; cursor: pointer; line-height: 1; background: #eef2f6; border: 1px solid #dde3e9; color: #5b6b7b; }
.li-edit:hover { background: #e1e8ef; }
#pl-list.edit-on .li-edit { display: inline-block; }
/* 편집모드: 원+숫자(li-no) 클릭으로 병합 선택 → 행 배경 반전 + 원 강조 */
#pl-list.edit-on .li-no { cursor: pointer; }
#pl-list.edit-on .li-no:hover { box-shadow: 0 0 0 2px #b6d7f2; }
#pl-list.edit-on .pl-li.mc-sel { background: #e3f1ff; }
.pl-li.mc-sel .li-no { background: #2980b9 !important; border-color: #2471a3 !important; color: #fff !important; }
/* 상단 병합 버튼(편집 중 옆) */
.lh-merge { background: #eaf3fb; border: 1px solid #b6d7f2; color: #2471a3; font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 6px; cursor: pointer; line-height: 1; white-space: nowrap; }
.lh-merge:hover { background: #d8ebfa; }
/* 병합 대표선택 모달 */
.pm-pick { display: flex; flex-direction: column; gap: 8px; margin-top: 10px; }
.pm-row { display: flex; gap: 9px; align-items: flex-start; padding: 9px 11px; border: 1px solid #e2e6ea; border-radius: 9px; cursor: pointer; }
.pm-row.main { border-color: #27ae60; background: #f3fbf6; }
.pm-row input { margin-top: 3px; }
.pm-nm { font-size: 13.5px; font-weight: 600; color: #2c3e50; }
.pm-nm .pm-refs { font-size: 11px; font-weight: 700; color: #cf5b4e; background: #fcebe9; border-radius: 9px; padding: 1px 7px; margin-left: 6px; }
.pm-ad { font-size: 11.5px; color: #8a97a3; margin-top: 1px; }
.pm-main-tag { font-size: 10.5px; font-weight: 700; color: #1e8449; margin-left: 6px; }
.pl-list-empty { color: #aaa; font-size: 13px; text-align: center; padding: 30px 12px; }
@media (max-width: 640px) {
    #pl-list { width: 100%; max-width: 100%; top: auto; height: 42vh; box-shadow: 0 -3px 12px rgba(0,0,0,.15); }
    #pl-list.open ~ .pl-legend { display: none; }
}

/* 우측 상세 패널 (마커 클릭 시) */
#pl-panel { position: absolute; top: 0; right: 0; bottom: 0; width: 340px; max-width: 88vw; background: #fff; box-shadow: -3px 0 14px rgba(0,0,0,.15); z-index: 30; transform: translateX(100%); transition: transform .25s; overflow-y: auto; overflow-x: hidden; -webkit-overflow-scrolling: touch; }
#pl-panel.open { transform: translateX(0); }
.panel-head { padding: 16px 18px 12px; border-bottom: 1px solid #eee; }
.panel-head .cat-badge { font-size: 11px; font-weight: 700; color: #fff; padding: 2px 8px; border-radius: 10px; }
.cat-badge.travel { background: #3498db; } .cat-badge.stay { background: #8e44ad; }
.cat-badge.restaurant { background: #e74c3c; } .cat-badge.camping { background: #27ae60; } .cat-badge.etc { background: #7f8c8d; }
.panel-head h3 { font-size: 19px; margin: 8px 0 6px; }
.panel-head .meta { font-size: 13px; color: #5b6b7b; line-height: 1.7; }
.panel-head .meta .nv-map-btn { display: inline-flex; align-items: center; vertical-align: middle; background: #03c75a; border: none; color: #fff; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 10px; cursor: pointer; margin-left: 6px; line-height: 1.4; }
.panel-head .meta .nv-map-btn:hover { background: #02b350; }
.panel-head .pl-nv { margin-top: 10px; padding: 9px 12px; background: #f3faf5; border: 1px solid #d9ece0; border-radius: 9px; font-size: 12.5px; color: #36504a; display: flex; flex-wrap: wrap; gap: 5px 14px; align-items: center; }
.panel-head .pl-nv .nv-it { font-weight: 700; white-space: nowrap; }
.panel-head .pl-nv .nv-it small { font-weight: 500; color: #7a8a85; }
.panel-head .pl-nv .nv-micro { flex-basis: 100%; font-style: italic; color: #586c66; margin-top: 1px; line-height: 1.45; }
.panel-head .pl-nv .nv-link { flex-basis: 100%; margin-top: 2px; color: #03c75a; font-weight: 700; text-decoration: none; }
.panel-head .pl-nv .nv-link:hover { text-decoration: underline; }
/* 전월 대비 추이 */
.panel-head .pl-nv .nv-trend { flex-basis: 100%; margin-top: 4px; }
.panel-head .pl-nv .nvt-h { font-size: 11px; color: #8a9a94; font-weight: 700; margin-bottom: 3px; }
.panel-head .pl-nv .nvt-row { display: flex; flex-wrap: wrap; gap: 4px 12px; }
.panel-head .pl-nv .nvt-it { font-weight: 700; white-space: nowrap; font-size: 12px; }
.panel-head .pl-nv .nvt-it.up { color: #e8493f; }
.panel-head .pl-nv .nvt-it.down { color: #2f7bd6; }
.panel-head .pl-nv .nvt-flat { font-size: 11.5px; color: #9aa6a2; }
.panel-head .tags { margin-top: 8px; display: flex; gap: 5px; flex-wrap: wrap; }
.panel-head .tags em { font-style: normal; font-size: 11px; background: #eef2f6; color: #5b6b7b; padding: 2px 8px; border-radius: 10px; }
/* 🤖 Claude AI 요약 박스 (마커 상세 — 원문 기사와 구분) */
.ai-summary { margin: 10px 0 2px; padding: 10px 12px; background: #f3f0fb; border: 1px solid #e0d7f5; border-left: 3px solid #7c5cd6; border-radius: 8px; font-size: 13px; line-height: 1.6; color: #3a3550; }
.ai-summary .ai-badge { display: inline-block; font-size: 10.5px; font-weight: 700; color: #fff; background: #7c5cd6; padding: 2px 8px; border-radius: 10px; margin-bottom: 6px; letter-spacing: .2px; }
/* 제목 옆 Claude 요약 칩 배지(클릭 시 모달) */
.ai-sum-chip { display: inline-flex; align-items: center; gap: 3px; vertical-align: middle; margin-left: 8px; padding: 3px 9px; background: #7c5cd6; color: #fff; border: none; border-radius: 11px; font-size: 11px; font-weight: 700; letter-spacing: .2px; line-height: 1.35; cursor: pointer; white-space: nowrap; }
.ai-sum-chip:hover { background: #6a4cc0; }
/* 지도 리스트 항목의 Claude 요약 칩(작게 — 클릭 시 요약 모달, 항목 클릭과 분리) */
.li-ai-chip { display: inline-flex; align-items: center; vertical-align: middle; margin-left: 5px; padding: 1px 6px; background: #7c5cd6; color: #fff; border: none; border-radius: 9px; font-size: 10px; font-weight: 700; line-height: 1.4; cursor: pointer; white-space: nowrap; }
.li-ai-chip:hover { background: #6a4cc0; }
#pl-aisum .aisum-name { font-weight: 700; font-size: 15px; color: #2c2540; margin-bottom: 8px; }
#pl-aisum .aisum-text { font-size: 14px; line-height: 1.75; color: #3a3550; white-space: pre-wrap; }
#pl-aisum .aisum-sub { font-size: 11.5px; color: #9b8fc4; margin-top: 12px; }
/* AI 추천 */
.btn-reco { background: #7c5cd6; color: #fff; border: none; }
.btn-reco:hover { background: #6a4cc0; }
.reco-inrow { display: flex; gap: 8px; }
.reco-inrow .pem-inp { flex: 1; }
.reco-ex { font-size: 11.5px; color: #8a94a0; margin-top: 8px; }
.reco-ex a { color: #7c5cd6; cursor: pointer; text-decoration: none; font-weight: 600; }
.reco-ex a:hover { text-decoration: underline; }
.reco-result { margin-top: 14px; }
.reco-scope { font-size: 12px; color: #6a6480; background: #f6f4fb; border: 1px dashed #d8cfee; border-radius: 6px; padding: 6px 10px; margin-bottom: 9px; line-height: 1.45; }
.reco-scope b { color: #5b3fb0; font-weight: 700; }
.reco-intro { font-size: 13.5px; color: #3a3550; background: #f3f0fb; border-left: 3px solid #7c5cd6; border-radius: 6px; padding: 9px 12px; margin-bottom: 10px; line-height: 1.55; }
.reco-item { border: 1px solid #e7e2f3; border-radius: 9px; padding: 10px 12px; margin-bottom: 8px; cursor: pointer; transition: background .12s; }
.reco-item:hover { background: #f7f5fc; }
.reco-item .ri-top { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; }
.reco-item .ri-name { font-weight: 700; font-size: 14.5px; color: #2c2540; }
.reco-item .ri-cat { font-size: 10.5px; font-weight: 700; color: #fff; background: #9385c0; padding: 1px 7px; border-radius: 9px; }
.reco-item .ri-reg { font-size: 11.5px; color: #8a94a0; }
.reco-item .ri-rev { font-size: 11.5px; color: #e8843f; font-weight: 700; }
.reco-item .ri-reason { font-size: 12.5px; color: #4a5560; margin-top: 5px; line-height: 1.5; }
.reco-item .ri-go { font-size: 11px; color: #7c5cd6; font-weight: 700; margin-top: 4px; }
.reco-empty { color: #98a2ad; font-size: 13px; text-align: center; padding: 24px 0; }
.reco-loading { color: #7c5cd6; font-size: 13px; text-align: center; padding: 24px 0; }
/* 요약 없는 장소: 온디맨드 생성 버튼 */
.ai-gen-btn { display: inline-block; margin: 10px 0 2px; padding: 7px 13px; background: #fff; color: #7c5cd6; border: 1px solid #cdbdf0; border-radius: 8px; font-size: 12.5px; font-weight: 700; cursor: pointer; }
.ai-gen-btn:hover:not(:disabled) { background: #f3f0fb; }
.ai-gen-btn:disabled { color: #9a90bb; border-color: #e2daf3; cursor: default; }
.panel-close { position: sticky; top: 6px; float: right; background: rgba(255,255,255,.9); border: none; font-size: 22px; color: #aaa; cursor: pointer; line-height: 1; z-index: 3; width: 26px; height: 26px; border-radius: 50%; box-shadow: 0 0 0 1px #eee; }
.panel-refs { padding: 12px 14px 40px; }
.panel-refs h4 { font-size: 13px; color: #7f8c8d; margin-bottom: 8px; }
.ref-item { display: block; text-decoration: none; color: inherit; border: 1px solid #eceff2; border-radius: 8px; padding: 10px 12px; margin-bottom: 8px; transition: .12s; }
.ref-item:hover { border-color: #3498db; background: #f7fbff; }
.ref-item .rt { font-size: 11px; font-weight: 700; color: #fff; padding: 1px 7px; border-radius: 8px; margin-right: 6px; }
.rt.article { background: #16a085; } .rt.youtube { background: #c0392b; } .rt.blog { background: #8e44ad; }
.rt.official { background: #2c3e50; } .rt.manual { background: #95a5a6; }
.ref-item .rtitle { font-size: 14px; font-weight: 600; margin: 4px 0 2px; }
.ref-item .rsum { font-size: 12px; color: #7f8c8d; }
.ref-empty { color: #aaa; font-size: 13px; text-align: center; padding: 30px 0; }

/* ── 여행지도(경로 만들기) 모드 ───────────────────────── */
.btn-route { background: #fff; border: 1px solid #6c5ce7; color: #6c5ce7; }
.btn-route:hover { background: #f3f1fb; }
.btn-route.active { background: #6c5ce7; color: #fff; border-color: #5a4cd0; }
/* 모드 ON 안내 배너(지도 위) */
.rt-banner { position: absolute; left: 50%; top: 12px; transform: translateX(-50%); z-index: 18; background: rgba(108,92,231,.95); color: #fff; font-size: 12.5px; font-weight: 600; padding: 7px 14px; border-radius: 20px; box-shadow: 0 2px 8px rgba(0,0,0,.2); display: none; white-space: nowrap; max-width: 90%; }
body.rt-on .rt-banner { display: block; }
/* 경로 만들기 우측 패널 */
/* 두 패널을 오른쪽에 나란히 붙여 한 덩어리로 슬라이드(시선 분산 방지) */
#rt-dock { position: absolute; top: 0; right: 0; bottom: 0; z-index: 26; display: flex; transform: translateX(100%); transition: transform .25s; box-shadow: -3px 0 16px rgba(0,0,0,.16); }
body.rt-on #rt-dock { transform: translateX(0); }
#rt-panel { width: 322px; max-width: 46vw; flex-shrink: 0; background: #fff; display: flex; flex-direction: column; }
#rt-sum-panel { width: 300px; max-width: 44vw; flex-shrink: 0; background: #fff; border-right: 1px solid #e8eaf0; display: flex; flex-direction: column; }
#rt-sum-panel .rt-sum { flex: 1; overflow-y: auto; }
.rt-head { padding: 14px 16px 10px; border-bottom: 1px solid #eee; display: flex; align-items: center; justify-content: space-between; }
.rt-head h3 { font-size: 16px; font-weight: 800; color: #5a4cd0; }
.rt-head .rt-x { background: none; border: none; font-size: 22px; color: #aaa; cursor: pointer; line-height: 1; }
.rt-tip { padding: 9px 16px; font-size: 11.5px; color: #8a7fd0; background: #f5f3fd; line-height: 1.55; }
.rt-input-row { display: flex; gap: 6px; padding: 10px 14px 6px; }
.rt-input-row input { flex: 1; min-width: 0; font-size: 13px; padding: 8px 10px; border: 1px solid #d8dde3; border-radius: 7px; }
.rt-input-row button { flex-shrink: 0; background: #6c5ce7; border: none; color: #fff; font-size: 13px; font-weight: 700; padding: 0 14px; border-radius: 7px; cursor: pointer; }
.rt-input-row button:hover { background: #5a4cd0; }
.rt-rows { flex: 1; overflow-y: auto; padding: 6px 12px; display: flex; flex-direction: column; gap: 7px; }
.rt-empty { padding: 28px 12px; text-align: center; color: #b0b8bf; font-size: 12.5px; line-height: 1.7; }
.rt-row { display: flex; align-items: center; gap: 7px; background: #f9fafb; border: 1px solid #e7ebef; border-radius: 9px; padding: 7px 8px; }
.rt-row.drag-over { box-shadow: 0 0 0 2px #c5bdf2; }
.rt-row.dragging { opacity: .45; }
.rt-handle { flex-shrink: 0; cursor: grab; color: #b9c3cc; font-size: 15px; line-height: 1; user-select: none; }
.rt-num { flex-shrink: 0; width: 25px; height: 25px; border-radius: 50%; color: #fff; font-weight: 800; font-size: 12.5px; display: flex; align-items: center; justify-content: center; }
.rt-info { min-width: 0; flex: 1; }
.rt-name { font-size: 13px; font-weight: 600; color: #2c3e50; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rt-addr { font-size: 11px; color: #8a97a3; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rt-del { flex-shrink: 0; background: none; border: none; color: #c3ccd4; font-size: 17px; line-height: 1; cursor: pointer; }
.rt-del:hover { color: #c0392b; }
/* 경로 번호 마커(채워진 색 물방울) — 검색 마커와 구분 */
.rt-pin { width: 30px; height: 30px; border-radius: 50% 50% 50% 0; transform: rotate(-45deg); border: 3px solid #fff; box-shadow: 0 3px 8px rgba(0,0,0,.45); display: flex; align-items: center; justify-content: center; }
.rt-pin b { transform: rotate(45deg); color: #fff; font-weight: 800; font-size: 13px; line-height: 1; }
.rt-pin.act { box-shadow: 0 0 0 4px rgba(108,92,231,.42), 0 3px 9px rgba(0,0,0,.5); transform: rotate(-45deg) scale(1.22); }
/* 주변 장소 스팟 마커(작은 원, 지점 색상) */
.rt-spot { width: 16px; height: 16px; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 1px 3px rgba(0,0,0,.4); }
.rt-spot.active { width: 22px; height: 22px; box-shadow: 0 0 0 3px rgba(231,76,60,.5), 0 2px 5px rgba(0,0,0,.4); }

/* 패널 상단 컨트롤(버튼) 영역 */
.rt-controls { padding: 9px 14px; border-bottom: 1px solid #f0f1f5; display: flex; flex-wrap: wrap; align-items: center; gap: 8px 10px; }
.rt-controls .rt-clbl { font-size: 12px; color: #5b6b7b; font-weight: 700; }
.rt-rquick { display: inline-flex; gap: 4px; }
.rt-rq { font-size: 12px; font-weight: 600; padding: 5px 9px; border-radius: 13px; background: #f4f6f8; border: 1px solid #e2e7ec; color: #5a6b7b; cursor: pointer; }
.rt-rq:hover { background: #eef0fb; border-color: #cfc8f3; color: #5a4cd0; }
.rt-rq.on { background: #6c5ce7; border-color: #5a4cd0; color: #fff; }
.rt-chk { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; color: #5b6b7b; cursor: pointer; user-select: none; }
.rt-chk input { margin: 0; cursor: pointer; }
.rt-go { background: #6c5ce7; border: none; color: #fff; font-size: 12.5px; font-weight: 700; padding: 7px 12px; border-radius: 7px; cursor: pointer; margin-left: auto; }
.rt-go:hover { background: #5a4cd0; }
.rt-go.on { background: #2c3e50; }
.rt-go.on:hover { background: #1f2c39; }
/* '찜·경로만' 토글을 '경로 주변 장소' 헤더 밑으로 이동 */
.rt-sum-toolbar { padding: 8px 12px; background: #fafbfc; }
.rt-sum-toolbar .rt-go { margin-left: 0; width: 100%; }
/* 3번째 줄: 반경·곳수 요약 */
.rt-sum-count { padding: 6px 12px 8px; font-size: 12px; font-weight: 700; color: #6c5ce7; border-bottom: 1px solid #f0f1f5; background: #fafbfc; }
.rt-sum-count:empty { display: none; }
/* 🌟 맛집 자동 추천(찜) 줄 */
.rt-rec-row { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 10px; padding: 8px 14px; border-bottom: 1px solid #f0f1f5; background: #fffdf5; }
.rt-recbtn { background: linear-gradient(135deg,#f6a623,#f5842a); border: none; color: #fff; font-size: 12.5px; font-weight: 800; padding: 8px 13px; border-radius: 8px; cursor: pointer; box-shadow: 0 1px 3px rgba(240,140,20,.35); }
.rt-recbtn:hover { filter: brightness(1.05); }
.rt-recbtn:disabled { opacity: .6; cursor: default; }
.rt-rec-cats { flex-basis: 100%; display: flex; gap: 5px; }
.rt-recq { font-size: 12px; font-weight: 700; padding: 5px 10px; border-radius: 13px; background: #fff; border: 1px solid #e6d6ab; color: #a07a1e; cursor: pointer; }
.rt-recq:hover { background: #fdf6e3; }
.rt-recq.on { background: linear-gradient(135deg,#f6a623,#f5842a); border-color: #e0851c; color: #fff; }
.rt-recn-lbl { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; color: #7a5a10; font-weight: 700; }
.rt-recn { width: 46px; font-size: 12px; padding: 5px 6px; border: 1px solid #e2cfa0; border-radius: 6px; text-align: center; }
.rt-rec-hint { flex-basis: 100%; font-size: 11px; color: #b08526; line-height: 1.4; }

/* 패널 본문 스크롤 + 섹션 헤더 */
.rt-scroll { flex: 1; overflow-y: auto; min-height: 0; }
.rt-sec-hd { position: sticky; top: 0; z-index: 1; background: #fff; padding: 10px 14px 7px; font-size: 12.5px; font-weight: 800; color: #5a4cd0; border-bottom: 1px solid #f3f3fa; }
.rt-sec-hd .rt-sub { font-size: 11px; font-weight: 600; color: #aab3bc; }
.rt-sec-hd-row { display: flex; align-items: center; gap: 6px; }
.rt-clearbtn { margin-left: auto; background: #fdecea; border: 1px solid #f5c6c0; color: #c0392b; font-size: 11.5px; font-weight: 700; padding: 4px 10px; border-radius: 6px; cursor: pointer; white-space: nowrap; }
.rt-clearbtn:hover { background: #f9d9d4; }

/* 종합(경로 주변 장소) */
.rt-sum { padding: 8px 12px 4px; }
.rt-sum-empty { padding: 22px 12px; text-align: center; color: #b0b8bf; font-size: 12.5px; line-height: 1.7; }
.rt-route-info { background: #fff3f1; border: 1px solid #f5c6bf; color: #c0392b; border-radius: 9px; padding: 8px 11px; font-size: 13px; margin-bottom: 9px; text-align: center; }
.rt-route-info b { font-size: 14.5px; }
.rt-route-info.muted { background: #f4f5f7; border-color: #e3e7eb; color: #8a97a3; font-size: 11.5px; }
.rt-wp { border: 1px solid #eef1f4; border-radius: 10px; margin-bottom: 9px; overflow: hidden; }
.rt-wp-head { display: flex; align-items: center; gap: 8px; padding: 9px 11px; cursor: pointer; background: #fbfcfd; }
.rt-wp-head:hover { background: #f4f3fc; }
.rt-wp-num { flex-shrink: 0; width: 24px; height: 24px; border-radius: 50%; color: #fff; font-weight: 800; font-size: 12px; display: flex; align-items: center; justify-content: center; }
.rt-wp-info { min-width: 0; flex: 1; }
.rt-wp-nm { font-size: 13px; font-weight: 700; color: #2c3e50; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rt-wp-cnt { font-size: 11px; color: #8a97a3; margin-top: 1px; }
.rt-wp-places { padding: 3px 6px 6px; }
.rt-place { display: flex; align-items: center; gap: 8px; padding: 7px 8px; border-radius: 7px; cursor: pointer; }
.rt-place:hover { background: #f3f6ff; }
.rt-place.active { background: #fdecea; }
.rt-pdot { flex-shrink: 0; width: 10px; height: 10px; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 0 0 1px rgba(0,0,0,.12); }
.rt-pbody { min-width: 0; flex: 1; }
.rt-pname { font-size: 12.5px; font-weight: 600; color: #2c3e50; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rt-pcat { font-size: 11px; color: #8a97a3; }
.rt-pdist { flex-shrink: 0; font-size: 11px; font-weight: 700; color: #6c5ce7; }
.rt-wp-none { padding: 8px 11px; font-size: 11.5px; color: #b0b8bf; }
/* 분류 소그룹 헤더(맛집/숙소/여행지…) */
.rt-catgrp { margin-top: 2px; }
.rt-catgrp:first-child { margin-top: 0; }
.rt-cathd { display: flex; align-items: center; gap: 5px; padding: 5px 8px 3px; font-size: 11.5px; font-weight: 800; }
.rt-cathd-ic { font-size: 12px; }
.rt-cathd-n { margin-left: 2px; min-width: 16px; height: 16px; padding: 0 5px; border-radius: 9px; color: #fff; font-size: 10.5px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; }
/* ⭐ 찜 — 목록 별 토글 버튼 */
.rt-star { flex-shrink: 0; background: none; border: none; cursor: pointer; font-size: 16px; line-height: 1; color: #cfd6dd; padding: 2px 4px; }
.rt-star.on { color: #f1c40f; }
.rt-star:hover { color: #f1c40f; }
/* ⭐ 찜 섹션(최상단) */
.rt-picks { background: #fffaf0; border: 1px solid #f6e6b8; border-radius: 9px; margin-bottom: 8px; }
.rt-picks-num { background: #f1c40f !important; }
.rt-pick-dot { width: auto; height: auto; border: none; box-shadow: none; color: #f1c40f; font-size: 13px; }
.rt-pickbtn { background: #f1c40f; color: #7a5a00; } .rt-pickbtn:hover { background: #e0b50c; }
.rt-pickbtn.on { background: #fff5d6; color: #7a5a00; border: 1px solid #f1c40f; }
/* ⭐ 찜 지도 마커 — 분류 아이콘 위 금색 별 배지 */
.rt-pick-wrap { position: relative; }
.rt-pick-star { position: absolute; top: -8px; right: -8px; font-size: 14px; color: #f1c40f; text-shadow: 0 0 2px #fff, 0 0 2px #fff, 0 1px 2px rgba(0,0,0,.4); pointer-events: none; }
/* ⭐ 찜 반전 라벨 — 빨강 배경·흰 글씨·흰 테두리(경로선 위에서도 또렷)·줌 무관 항상 표시 */
.rt-pick-label { display: inline-block; white-space: nowrap; background: #d63031; color: #fff; font-size: 12px; font-weight: 800; line-height: 1; padding: 4px 8px; border-radius: 11px; border: 2px solid #fff; box-shadow: 0 2px 6px rgba(0,0,0,.4); cursor: pointer; transform: translate(13px,-40px); transform-origin: left bottom; }
.rt-pick-label.active { background: #2d8cff; box-shadow: 0 0 0 3px rgba(45,140,255,.45), 0 2px 8px rgba(0,0,0,.55); transform: translate(13px,-40px) scale(1.1); z-index: 5; }
.rt-pick-rev { margin-left: 5px; font-weight: 700; opacity: .9; font-size: 11px; }
/* 📁 현재 불러온 여행지도 이름(경로 만들기 상단) */
.rt-cur-name { padding: 6px 11px 0; font-size: 13px; font-weight: 800; color: #6c5ce7; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
/* 💾 저장/저장함 버튼 행 */
.rt-save-row { display: flex; gap: 6px; padding: 8px 11px 0; }
.rt-savebtn { flex: 1; background: #6c5ce7; border: none; color: #fff; font-size: 12.5px; font-weight: 700; padding: 8px; border-radius: 7px; cursor: pointer; }
.rt-savebtn:hover { background: #5a4cd0; }
.rt-loadbtn { background: #2c3e50; } .rt-loadbtn:hover { background: #1f2d3a; }
/* 📂 저장함 모달 */
.rt-trip-save { display: flex; gap: 6px; margin-bottom: 8px; }
.rt-trip-save .pem-inp { flex: 1; margin: 0; }
.rt-trip-save .tm-btn { white-space: nowrap; }
.rt-trip-cur { font-size: 12px; color: #6c5ce7; font-weight: 700; margin-bottom: 4px; }
.rt-trip-list { max-height: 50vh; overflow-y: auto; }
.rt-trip-empty { padding: 16px; text-align: center; color: #b0b8bf; font-size: 12.5px; }
.rt-trip-it { display: flex; align-items: center; gap: 8px; padding: 8px 6px; border-bottom: 1px solid #eef1f4; }
.rt-trip-info { flex: 1; min-width: 0; cursor: pointer; }
.rt-trip-nm { font-size: 13.5px; font-weight: 700; color: #2c3e50; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rt-trip-meta { font-size: 11px; color: #8a97a3; }
.rt-trip-open { background: #6c5ce7; border: none; color: #fff; font-size: 12px; font-weight: 700; padding: 6px 12px; border-radius: 6px; cursor: pointer; }
.rt-trip-share { background: none; border: none; cursor: pointer; font-size: 15px; padding: 4px; opacity: .65; }
.rt-trip-share:hover { opacity: 1; }
.rt-trip-del { background: none; border: none; cursor: pointer; font-size: 15px; padding: 4px; opacity: .6; }
.rt-trip-del:hover { opacity: 1; }
/* 좌측 '지점별 주변 장소' 종합 패널 (경로 패널과 별개) */
/* 상세패널 '경로에 추가' 버튼 */
.rt-addcur { display: block; width: 100%; margin-top: 12px; background: #6c5ce7; border: none; color: #fff; font-size: 13.5px; font-weight: 700; padding: 10px; border-radius: 8px; cursor: pointer; }
.rt-addcur:hover { background: #5a4cd0; }
/* 상세패널 '여기로 길찾기' 버튼 */
.nav-open-b { background: #e8412e; } .nav-open-b:hover { background: #cf3424; }
/* 공유(트립) 게스트 — 전체 경로 슬라이드 바(지도 하단 가로 스크롤) */
body.trip-view .pl-legend { display: none; }   /* 경로 보기엔 분류 범례 대신 슬라이드 바 */
#rt-sharewrap { position: absolute; left: 0; right: 0; bottom: 0; z-index: 22; display: none; flex-direction: column; gap: 7px; padding: 9px 8px 12px; background: linear-gradient(to top, rgba(255,255,255,.97), rgba(255,255,255,.80)); border-top: 1px solid #e6e9ee; }
#rt-sharewrap.on { display: flex; }
#rt-shareinfo { display: none; align-self: center; max-width: 96%; font-size: 13px; color: #2c3e50; background: #fff; border: 1px solid #e6e9ee; border-radius: 16px; padding: 5px 14px; box-shadow: 0 2px 6px rgba(0,0,0,.10); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
#rt-shareinfo.on { display: block; }
#rt-shareinfo b { color: #e8412e; }
#rt-shareinfo .rti-muted { color: #95a5a6; }
.rt-share-row { display: flex; align-items: center; gap: 4px; }
.rt-sb-nav { flex-shrink: 0; width: 34px; height: 34px; border-radius: 50%; border: 1px solid #d8dde3; background: #fff; color: #5a4cd0; font-size: 21px; font-weight: 700; line-height: 1; cursor: pointer; box-shadow: 0 2px 6px rgba(0,0,0,.14); display: flex; align-items: center; justify-content: center; transition: .14s; }
.rt-sb-nav:hover { background: #f3f1ff; border-color: #c3bdf0; }
.rt-sb-nav:disabled { opacity: .35; cursor: default; box-shadow: none; }
#rt-sharebar { flex: 1; min-width: 0; display: flex; gap: 8px; overflow-x: auto; padding: 2px; scroll-snap-type: x proximity; -webkit-overflow-scrolling: touch; }
#rt-sharebar::-webkit-scrollbar { height: 6px; }
#rt-sharebar::-webkit-scrollbar-thumb { background: #c8cfd6; border-radius: 3px; }
.rt-sb-card { flex: 0 0 auto; scroll-snap-align: center; display: flex; align-items: center; gap: 8px; min-width: 148px; max-width: 230px; background: #fff; border: 1px solid #e2e6ea; border-radius: 12px; box-shadow: 0 2px 7px rgba(0,0,0,.12); padding: 8px 11px; cursor: pointer; transition: .14s; }
.rt-sb-card:hover { border-color: #c3ccd4; }
.rt-sb-card.active { border-color: #6c5ce7; box-shadow: 0 0 0 2px rgba(108,92,231,.35), 0 3px 10px rgba(0,0,0,.18); }
.rt-sb-num { flex-shrink: 0; width: 24px; height: 24px; border-radius: 50%; color: #fff; font-weight: 800; font-size: 12.5px; display: flex; align-items: center; justify-content: center; }
.rt-sb-info { min-width: 0; }
.rt-sb-name { font-size: 13px; font-weight: 700; color: #2c3e50; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rt-sb-addr { font-size: 11px; color: #8a97a3; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
@media (max-width: 640px) {
    /* 모바일: 두 패널을 하단 시트로 모아 위아래로 쌓음 */
    #rt-dock { left: 0; right: 0; top: auto; height: 64vh; flex-direction: column; transform: translateY(100%); box-shadow: 0 -3px 14px rgba(0,0,0,.18); }
    body.rt-on #rt-dock { transform: translateY(0); }
    #rt-sum-panel, #rt-panel { width: 100%; max-width: 100%; flex: 1; min-height: 0; }
    #rt-sum-panel { border-right: none; border-bottom: 1px solid #e8eaf0; }
}

/* 패널 접기/펼치기 — 캘린더식 화살표 탭(경로·찜은 유지, 패널만 슬라이드) */
#rtDockTab { flex-shrink: 0; width: 20px; align-self: stretch; background: #e8ecf0; border: none; cursor: pointer; font-size: 12px; font-weight: 700; color: #5a4cd0; padding: 0; transition: background .15s; }
#rtDockTab:hover { background: #d8d2f3; color: #2c3e50; }
#rt-sum-panel, #rt-panel { transition: width .22s, opacity .22s; }
body.rt-collapsed #rt-sum-panel, body.rt-collapsed #rt-panel { width: 0; max-width: 0; opacity: 0; pointer-events: none; overflow: hidden; border: 0; }
@media (max-width: 640px) {
    #rtDockTab { width: 100%; height: 22px; align-self: auto; }
    #rt-sum-panel, #rt-panel { transition: height .22s, opacity .22s, flex-basis .22s; }
    body.rt-collapsed #rt-dock { height: auto; }   /* 접으면 탭 줄만 남김(하단) */
    body.rt-collapsed #rt-sum-panel, body.rt-collapsed #rt-panel { height: 0; min-height: 0; flex: 0 0 0; opacity: 0; pointer-events: none; overflow: hidden; border: 0; }
}

/* 수정 모달 (장소 이름/분류/좌표 지정) */
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
/* 좌표 검색 결과: 주소를 주 표시로(같은 이름 구분), 이름은 보조 */
.pem-raddr-main { font-size: 13px; color: #2c3e50; font-weight: 600; line-height: 1.35; }
.pem-rname { font-size: 11.5px; color: #8a97a3; margin-top: 1px; }
.pem-empty { padding: 12px; font-size: 12.5px; color: #aaa; text-align: center; }
.pem-picked { margin-top: 10px; font-size: 12.5px; color: #1e8449; line-height: 1.5; }
.pem-picked span { color: #8a97a3; }
/* 최근 수정 시각 */
.pem-updated { font-size: 11.5px; color: #8a97a3; margin-bottom: 10px; }
.pem-updated:empty { display: none; }
.pem-updated b { color: #5a6b7b; font-weight: 700; }
/* 연결된 기사 목록 + 제거 */
.pem-refs-hd { font-size: 12px; font-weight: 700; color: #5a6b7b; margin: 10px 0 4px; }
.pem-refs-cnt { color: #2980b9; margin-left: 2px; }
.pem-refs { display: flex; flex-direction: column; gap: 6px; max-height: 190px; overflow-y: auto; }
.pem-ref { display: flex; align-items: center; gap: 7px; background: #f7fafc; border: 1px solid #e7edf2; border-radius: 8px; padding: 7px 9px; }
.pem-ref-rt { flex-shrink: 0; font-size: 10px; font-weight: 700; color: #fff; background: #95a5a6; border-radius: 4px; padding: 2px 5px; }
.pem-ref-rt.article { background: #3498db; } .pem-ref-rt.youtube { background: #e74c3c; } .pem-ref-rt.blog { background: #16a085; } .pem-ref-rt.official { background: #8e44ad; }
.pem-ref-t { flex: 1; min-width: 0; font-size: 12.5px; color: #2c3e50; text-decoration: none; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
a.pem-ref-t:hover { text-decoration: underline; color: #2980b9; }
.pem-ref-d { flex-shrink: 0; font-size: 10.5px; color: #aab3bc; }
.pem-ref-del { flex-shrink: 0; background: none; border: none; color: #c3ccd4; font-size: 16px; line-height: 1; cursor: pointer; padding: 0 2px; }
.pem-ref-del:hover { color: #c0392b; }
.pem-refs-hint { margin-top: 6px; font-size: 11px; color: #95a5a6; line-height: 1.45; }
/* 기사 직접 추가(제목 + URL) */
.pem-refadd { display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap; }
.pem-refadd #pemRefTitle { flex: 1 1 100%; }
.pem-refadd #pemRefUrl { flex: 1 1 60%; min-width: 0; }
.pem-refadd-btn { flex-shrink: 0; background: #eef7f0; border: 1px solid #cfe6d6; color: #1e8449; font-size: 12.5px; font-weight: 600; padding: 0 12px; border-radius: 7px; cursor: pointer; white-space: nowrap; }
.pem-refadd-btn:hover { background: #e1f0e6; }
/* 검색 결과 행: 본문(클릭=기본좌표) + ➕(추가 장소로) */
.pem-res-main { cursor: pointer; }
.pem-res-row { display: flex; align-items: flex-start; gap: 8px; }
.pem-res-row .pem-res-main { flex: 1; min-width: 0; }
.pem-add { flex-shrink: 0; align-self: center; background: #eafaf1; border: 1px solid #abebc6; color: #1e8449; font-size: 14px; font-weight: 700; width: 26px; height: 26px; border-radius: 6px; cursor: pointer; line-height: 1; }
.pem-add:hover { background: #d4f4e0; }
/* 멀티 등록 — 추가 장소 영역 */
.pem-extra-wrap { margin-top: 14px; padding-top: 12px; border-top: 1px dashed #e3e8ee; }
.pem-extra-hd { font-size: 12px; font-weight: 700; color: #5a6b7b; }
.pem-extra-cnt { color: #1e8449; }
.pem-extra { margin-top: 7px; display: flex; flex-direction: column; gap: 8px; }
.pem-erow { background: #f6fbf8; border: 1px solid #cdeede; border-radius: 9px; padding: 8px 9px; }
.pem-erow-top { display: flex; align-items: center; gap: 6px; }
.pem-einp { flex: 1; min-width: 0; padding: 6px 8px; border: 1px solid #cdd6df; border-radius: 6px; font-size: 13px; color: #2c3e50; }
.pem-esel { flex: 0 0 auto; padding: 6px 6px; border: 1px solid #cdd6df; border-radius: 6px; font-size: 12.5px; color: #2c3e50; background: #fff; }
.pem-edel { flex: 0 0 auto; background: none; border: none; color: #b3c5b8; font-size: 18px; line-height: 1; cursor: pointer; padding: 0 4px; }
.pem-edel:hover { color: #c0392b; }
.pem-eloc { margin-top: 6px; font-size: 11.5px; color: #1e8449; display: flex; align-items: center; gap: 4px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pem-extra-hint { margin-top: 7px; font-size: 11px; color: #95a5a6; line-height: 1.45; }
/* 태그 입력 */
.pem-sub { font-size: 10.5px; font-weight: 500; color: #aab3bc; margin-left: 4px; }
.pem-months { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 8px; }
.pem-mon { font-size: 11.5px; padding: 4px 0; width: calc((100% - 44px) / 12); min-width: 26px; text-align: center; background: #f4f6f8; border: 1px solid #dde3e9; border-radius: 5px; color: #7b8794; cursor: pointer; }
.pem-mon:hover { background: #eaeef2; }
.pem-mon.active { background: #e67e22; border-color: #d35400; color: #fff; font-weight: 700; }
.pem-tag-row { display: flex; gap: 6px; }
.pem-tag-kind { flex-shrink: 0; font-size: 12.5px; padding: 0 8px; border: 1px solid #d8dde3; border-radius: 7px; background: #fff; color: #5a6b7b; }
.pem-tag-row .pem-inp { flex: 1; }
.pem-tag-sug { display: flex; flex-wrap: wrap; align-items: center; gap: 5px; margin-top: 7px; }
.pem-sug-lbl { font-size: 11px; color: #aab3bc; margin-right: 2px; }
.pem-sug-chip { font-size: 11.5px; padding: 3px 9px; background: #f4f6f8; border: 1px dashed #cdd6de; border-radius: 12px; color: #5d6d7e; cursor: pointer; }
.pem-sug-chip:hover { background: #eaf3fb; border-color: #b6d7f2; color: #2471a3; }
.pem-sug-more { font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 12px; background: #fff; border: 1px dashed #cdd6de; color: #7b8794; cursor: pointer; }
.pem-sug-more:hover { background: #f4f6f8; }
.pem-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.pem-tag { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; padding: 3px 6px 3px 10px; border-radius: 13px; border: 1px solid; }
.pem-tag.k-theme { background: #eaf3fb; border-color: #b6d7f2; color: #2471a3; }
.pem-tag.k-type  { background: #e8f8f3; border-color: #a8e6d2; color: #138d75; }
.pem-tag.k-facet { background: #f2f4f6; border-color: #d6dce2; color: #5d6d7e; }
.pem-tag.k-cuisine { background: #fef0e6; border-color: #f6c9a6; color: #c0651b; }
.pem-tag.k-grade { background: #fdeef4; border-color: #f4c2d7; color: #b1356d; }
/* 맛집 정보 섹션(가이드 + 음식 종류) */
.pem-food { border: 1px solid #f0d9c4; background: #fffaf5; border-radius: 9px; padding: 8px 11px 11px; margin: 6px 0 4px; }
.pem-guides { display: flex; flex-direction: column; gap: 5px; margin-bottom: 4px; }
.pem-grow { display: flex; align-items: center; gap: 8px; font-size: 13px; }
.pem-grow .pem-gname { font-weight: 700; min-width: 78px; }
.pem-gsel { font-size: 12.5px; padding: 3px 6px; border: 1px solid #d8dde3; border-radius: 6px; background: #fff; color: #4a5765; }
.pem-gsel:disabled { background: #f2f4f6; color: #aeb6bf; }
.pem-tag button { background: none; border: none; font-size: 14px; line-height: 1; cursor: pointer; color: inherit; opacity: .55; padding: 0 1px; }
.pem-tag button:hover { opacity: 1; color: #c0392b; }
.pem-hint { margin-top: 10px; font-size: 11.5px; color: #95a5a6; line-height: 1.5; }
/* 태그 관리 — 칩 그리드 + 선택형 액션바 */
#pl-tagmgr { z-index: 1100; }   /* 수정모달(1000) 위에 표시 */
.tm-box { width: 540px; max-width: 94vw; height: 78vh; }
.tm-top { padding: 12px 16px; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid #eee; flex-shrink: 0; }
.tm-top .pem-inp { flex: 1; }
.tm-count { font-size: 12px; color: #95a5a6; white-space: nowrap; }
.tm-grid { flex: 1; overflow-y: auto; padding: 12px 16px; }
.tm-sec-h { font-size: 12px; font-weight: 700; color: #8a97a3; margin: 12px 2px 8px; display: flex; align-items: center; gap: 6px; }
.tm-sec-h:first-child { margin-top: 0; }
.tm-sec-h span { background: #eef2f6; color: #5b6b7b; border-radius: 10px; padding: 1px 8px; font-size: 11px; font-weight: 700; }
.tm-sec { display: flex; flex-wrap: wrap; align-content: flex-start; gap: 8px; margin-bottom: 6px; }
.tm-empty { width: 100%; padding: 24px; text-align: center; color: #aab3bc; font-size: 13px; }
.tm-chip { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; padding: 5px 11px; border-radius: 16px; border: 1px solid #dde3e9; background: #f6f8fa; color: #2c3e50; cursor: pointer; user-select: none; }
.tm-chip:hover { border-color: #b6d7f2; background: #eef6ff; }
.tm-chip.sel { background: #2980b9; border-color: #2471a3; color: #fff; }
.tm-chip .tm-c { font-size: 10.5px; font-weight: 700; opacity: .65; }
.tm-chip.sel .tm-c { opacity: .9; }
.tm-action { flex-shrink: 0; border-top: 1px solid #eee; padding: 12px 16px; background: #fafbfc; }
.tm-hint { font-size: 12.5px; color: #95a5a6; line-height: 1.5; }
.tm-sel-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
.tm-selchip { font-size: 12px; background: #eef3f8; border: 1px solid #cfe0ef; color: #2471a3; border-radius: 13px; padding: 2px 4px 2px 10px; display: inline-flex; align-items: center; gap: 3px; }
.tm-selchip button { background: none; border: none; cursor: pointer; color: #2471a3; font-size: 14px; line-height: 1; padding: 0 2px; }
.tm-act-btns { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.tm-act-btns .tm-inp { flex: 1; min-width: 120px; font-size: 13.5px; padding: 7px 10px; border: 1px solid #d8dde3; border-radius: 7px; }
.tm-btn { font-size: 13px; font-weight: 600; padding: 7px 14px; border-radius: 7px; cursor: pointer; border: 1px solid; white-space: nowrap; }
.tm-btn.merge { background: #eaf3fb; border-color: #b6d7f2; color: #2471a3; }
.tm-btn.del { background: #fdecea; border-color: #f5c6c0; color: #c0392b; }
.tm-btn.rename { background: #eef7f0; border-color: #cfe6d6; color: #1e8449; }
.tm-btn.primary { background: #2980b9; border-color: #2471a3; color: #fff; }
.tm-btn.ghost { background: #fff; border-color: #d8dde3; color: #5b6b7b; }
.tm-merge-pick { display: flex; flex-wrap: wrap; gap: 6px; margin: 8px 0 10px; }
.tm-pick { font-size: 12.5px; padding: 5px 11px; border-radius: 14px; border: 1px solid #d8dde3; background: #fff; cursor: pointer; }
.tm-pick.on { background: #27ae60; border-color: #229954; color: #fff; }
.pem-foot { padding: 12px 18px; border-top: 1px solid #eee; display: flex; justify-content: flex-end; gap: 8px; }
.pem-lbl-row { display: flex; align-items: center; gap: 6px; }
.pem-tagmgr { margin-left: auto; background: #f3f1fb; border: 1px solid #d9d2f0; color: #6c5ce7; font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 6px; cursor: pointer; white-space: nowrap; }
.pem-tagmgr:hover { background: #e9e4f8; }
</style>
</head>
<body<?= $tripShareName !== '' ? ' class="trip-view"' : '' ?>>
<?php if (!$isGuest) render_nav('places'); ?>
<?php if ($tripShareName !== ''): ?>
<div class="pl-guest-banner">📍 <b><?= htmlspecialchars($tripShareName, ENT_QUOTES, 'UTF-8') ?></b> · 공유된 여행지도 (보기 전용)</div>
<?php elseif ($isGuest): ?>
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
    <!-- 분류는 아래 칩바(#pl-catbar)로 멀티선택(plCatSel). 거울 hidden 은 제거(단일 소스화) -->
    <!-- 반경 선택 UI 제거(줌인/아웃으로 영역 조절). 주소검색·자동확장 등 내부 로직용 기본값만 숨김 보관 -->
    <input type="hidden" id="radius" value="5">
<?php if (!$isGuest): ?>
    <button class="btn btn-reco" onclick="plRecoOpen()" title="자연어로 물어보면 Claude 요약을 근거로 장소를 추천합니다">🤖 AI 추천</button>
    <button class="btn btn-route" id="rtModeBtn" onclick="rtToggleMode()" title="여러 지점을 잇는 실제 도로 경로와 경로 주변 맛집·여행지를 봅니다">🧭 여행 경로 만들기</button>
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

<!-- 1) 지역 → 2) 분류 → 3) 선택한 분류별 필터 블록(태그 검색창 + top10 칩) -->
<div id="pl-regionbar">
    <span class="rb-lbl">📍 지역</span>
    <div id="rbChips" class="rb-chips"></div>
</div>
<div id="pl-catbar">
    <span class="cb-lbl">🗂 분류</span>
    <div id="cbChips" class="cb-chips"></div>
    <button id="ovToggle" class="cb-ov" onclick="plToggleOverlay()" title="줌인했을 때 주변의 다른 분류 마커를 덧댈지 켜고 끕니다 (맛집 등 일부 분류는 기본 제외)">🔭 주변</button>
</div>
<div id="pl-tagbar" class="pl-fbar">
    <span class="mb-lbl">🌸 여행지</span>
    <div class="pl-tagsearch">
        <input id="travelAc" class="pl-tagsearch-in" placeholder="태그 검색" autocomplete="off"
               oninput="plTagAc('travel', this.value)" onkeydown="plTagAcKey(event, 'travel')"
               onblur="setTimeout(function(){ plTagAcClose('travel'); }, 150)">
        <div id="travelAcBox" class="pl-tagac"></div>
    </div>
    <div id="tbChips" class="tb-chips"></div>
</div>
<div id="pl-monthbar">
    <span class="mb-lbl" id="mbLbl">🌸 방문하기 좋은 달</span>
    <div id="mbChips" class="mb-chips"></div>
</div>
<div id="pl-foodbar" class="pl-fbar">
    <span class="mb-lbl">🍜 맛집</span>
    <div class="pl-tagsearch">
        <input id="foodAc" class="pl-tagsearch-in" placeholder="태그 검색" autocomplete="off"
               oninput="plTagAc('food', this.value)" onkeydown="plTagAcKey(event, 'food')"
               onblur="setTimeout(function(){ plTagAcClose('food'); }, 150)">
        <div id="foodAcBox" class="pl-tagac"></div>
    </div>
    <div id="foodChips" class="mb-chips"></div>
</div>
<div id="pl-staybar" class="pl-fbar">
    <span class="mb-lbl">🏨 숙소</span>
    <div class="pl-tagsearch">
        <input id="stayAc" class="pl-tagsearch-in" placeholder="태그 검색" autocomplete="off"
               oninput="plTagAc('stay', this.value)" onkeydown="plTagAcKey(event, 'stay')"
               onblur="setTimeout(function(){ plTagAcClose('stay'); }, 150)">
        <div id="stayAcBox" class="pl-tagac"></div>
    </div>
    <div id="stayChips" class="mb-chips"></div>
</div>
<div id="pl-campbar" class="pl-fbar">
    <span class="mb-lbl">⛺ 캠핑</span>
    <div class="pl-tagsearch">
        <input id="campAc" class="pl-tagsearch-in" placeholder="태그 검색" autocomplete="off"
               oninput="plTagAc('camp', this.value)" onkeydown="plTagAcKey(event, 'camp')"
               onblur="setTimeout(function(){ plTagAcClose('camp'); }, 150)">
        <div id="campAcBox" class="pl-tagac"></div>
    </div>
    <div id="campChips" class="mb-chips"></div>
</div>

<div id="pl-main">
    <div id="map"></div>
    <div id="pl-list">
        <div class="pl-list-head"><span id="plListTitle">결과</span>
            <div class="lh-acts">
<?php if (!$isGuest): ?>                <button class="lh-merge" id="plMergeBtn" onclick="plMergeOpen()" style="display:none" title="선택한 중복 장소 병합">🔀 병합</button>
                <button class="lh-edit" id="plEditToggle" onclick="plToggleEdit()" title="장소 수정 연필 표시/숨김">✏️ 편집</button>
<?php endif; ?>                <button class="lh-close" onclick="plToggleList(false)" title="목록 닫기">×</button>
            </div>
        </div>
        <div class="pl-list-body" id="plListBody"></div>
    </div>
    <div class="pl-hint" id="hint">주소를 검색하거나 지도를 옮긴 뒤 '이 지역 검색'을 누르세요</div>
    <div class="pl-legend">
        <span><i class="dot travel"></i>여행지</span>
        <span><i class="dot stay"></i>숙소</span>
        <span><i class="dot restaurant"></i>맛집</span>
        <span><i class="dot camping"></i>캠핑장</span>
        <span><i class="dot etc"></i>기타</span>
        <span><i class="dot" style="background:#ff2d2d"></i>검색 위치</span>
    </div>
    <button class="pl-myloc" id="myLocBtn" onclick="plMyLocation()" title="현재 위치로 이동">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="3.4"></circle>
            <path d="M12 2v3.2M12 18.8V22M2 12h3.2M18.8 12H22"></path>
        </svg>
    </button>
    <div id="pl-panel">
        <div class="panel-head" id="panelHead"></div>
        <div class="panel-refs" id="panelRefs"></div>
    </div>
    <!-- 공유(트립) 게스트 전용: 전체 경로를 하단 슬라이드 바로 표시 -->
    <div id="rt-sharewrap">
        <div id="rt-shareinfo"></div>
        <div class="rt-share-row">
            <button type="button" id="rtSbPrev" class="rt-sb-nav" onclick="rtShareStep(-1)" title="이전 지점">‹</button>
            <div id="rt-sharebar"></div>
            <button type="button" id="rtSbNext" class="rt-sb-nav" onclick="rtShareStep(1)" title="다음 지점">›</button>
        </div>
    </div>
<?php if (!$isGuest): ?>
    <div class="rt-banner">🧭 여행 경로 만들기 중 — 주소 입력·주변 마커로 지점을 추가하면 실제 도로 경로·소요시간과 경로 주변(전국 DB) 맛집·여행지를 지도 필터와 무관하게 보여줍니다</div>
    <div id="rt-dock">
    <button id="rtDockTab" onclick="rtCollapse()" title="패널 접기/펼치기(경로는 유지)">▶</button>
    <div id="rt-sum-panel">
        <div class="rt-head"><h3>📋 경로 주변 장소</h3></div>
        <div class="rt-sum-toolbar">
            <button class="rt-go" id="rtFinalBtn" onclick="rtFinalize()" title="반경 밖 마커를 숨기거나 다시 표시합니다">🙈 마크 숨기기</button>
        </div>
        <div class="rt-sum-count" id="rtSumCnt"></div>
        <div class="rt-sum" id="rtSum">
            <div class="rt-sum-empty">지점을 2곳 이상 추가하면<br>경로 주변(반경 안)의 장소가<br>자동으로 여기에 모입니다.</div>
        </div>
    </div>
    <div id="rt-panel">
        <div class="rt-head"><h3>🧭 경로 만들기</h3><button class="rt-x" onclick="rtToggleMode()" title="닫기">×</button></div>
        <div class="rt-cur-name" id="rtCurName" style="display:none"></div>
        <div class="rt-save-row">
            <button class="rt-savebtn" onclick="rtTripSave()" title="현재 경로+찜을 이름 붙여 저장">💾 경로 저장</button>
            <button class="rt-savebtn rt-loadbtn" onclick="rtTripOpen()" title="저장한 경로 불러오기">📂 경로 리스트</button>
            <button class="rt-savebtn" onclick="rtCopyCode()" title="경로 지점·찜 장소 목록 요약도구 URL을 클립보드에 복사">🔖 경로코드</button>
        </div>
        <div class="rt-controls">
            <span class="rt-clbl">반경</span>
            <span class="rt-rquick">
                <button type="button" class="rt-rq" data-r="3" onclick="rtSetRadius(3)">3km</button>
                <button type="button" class="rt-rq on" data-r="5" onclick="rtSetRadius(5)">5km</button>
                <button type="button" class="rt-rq" data-r="10" onclick="rtSetRadius(10)">10km</button>
            </span>
        </div>
        <div class="rt-rec-row">
            <div class="rt-rec-cats">
                <button type="button" class="rt-recq on" data-cat="restaurant" onclick="rtRecCatToggle(this)">🍴 맛집</button>
                <button type="button" class="rt-recq" data-cat="stay" onclick="rtRecCatToggle(this)">🛏️ 숙소</button>
                <button type="button" class="rt-recq" data-cat="travel" onclick="rtRecCatToggle(this)">🏞️ 여행지</button>
            </div>
            <button class="rt-recbtn" id="rtRecBtn" onclick="rtRecommend()" title="켠 분류에서 유명한 곳을 경로 지점당 상위 N개 자동으로 찜합니다">🌟 추천 찜 담기</button>
            <label class="rt-recn-lbl">지점당 <input type="number" id="rtRecN" class="rt-recn" min="1" max="20" step="1" value="5"> 개</label>
            <div class="rt-rec-hint">켠 분류에서 <b>리뷰(맛집·숙소)</b>·<b>기사 수(여행지)</b>가 많은 곳을 각 경로 지점마다 상위 N곳씩 자동으로 ⭐찜에 담습니다. 기존 찜은 유지됩니다.</div>
        </div>
        <div class="rt-tip">지점을 2곳 이상 추가하면 <b>실제 도로 경로·소요시간</b>이 그려지고, <b>전국 DB에서 경로선 반경 안</b>의 맛집·여행지를 찾아(지도 필터와 무관) 작은 원으로 표시하고 오른쪽에 정리합니다. <b>주소 직접 입력</b>으로 지점 추가, 주변 마커 클릭 → <b>➕ 경로에 추가</b>, <b>≡</b> 드래그로 순서변경. <b>🙈 마크 숨기기</b>로 주변 마커를 감출 수 있습니다.</div>
        <div class="rt-input-row">
            <input type="text" id="rtAddr" placeholder="주소·장소명 직접 입력 (예: 강릉시청)"
                   onkeydown="if(event.key==='Enter'){event.preventDefault();rtAddrAdd();}">
            <button onclick="rtAddrAdd()">추가</button>
        </div>
        <div class="rt-scroll">
            <div class="rt-sec-hd rt-sec-hd-row">📍 경로 지점 <span class="rt-sub" id="rtRowsCnt"></span>
                <button class="rt-clearbtn" onclick="rtClear()" title="경로를 모두 비웁니다">경로비우기</button>
            </div>
            <div class="rt-rows" id="rtRows"></div>
        </div>
    </div>
    </div>
<?php endif; ?>
</div>

<?php if (!$isGuest): ?>
<div id="pl-edit" class="pl-modal">
    <div class="pem-box">
        <div class="pem-head"><span>✏️ 장소 수정</span><button class="pem-x" onclick="plEditClose()">×</button></div>
        <div class="pem-body">
            <div id="pemUpdated" class="pem-updated"></div>
            <label class="pem-lbl">이름</label>
            <input type="text" id="pemName" class="pem-inp">
            <label class="pem-lbl">분류</label>
            <select id="pemCat" class="pem-inp" onchange="plEditCatChange()">
                <option value="travel">여행지</option>
                <option value="stay">숙소</option>
                <option value="restaurant">맛집</option>
                <option value="etc">기타</option>
            </select>
            <div id="pemFood" class="pem-food" style="display:none">
                <label class="pem-lbl">맛집 가이드 <span class="pem-sub">등재된 가이드 체크(마커색)</span></label>
                <div id="pemGuides" class="pem-guides"></div>
                <label class="pem-lbl pem-lbl-row">등급 <span class="pem-sub">리본2 · 스타3 등 — 태그로 검색</span></label>
                <input type="text" id="pemGradeInput" class="pem-inp" list="pemGradeList" autocomplete="off"
                       placeholder="등급 입력 후 Enter (예: 리본2)"
                       onkeydown="if(event.key==='Enter'){event.preventDefault();plEditGradeAdd();}">
                <datalist id="pemGradeList"></datalist>
                <label class="pem-lbl pem-lbl-row">음식 종류 <span class="pem-sub">한식·중식·짬뽕 등 — 검색에 쓰입니다</span></label>
                <input type="text" id="pemCuisineInput" class="pem-inp" list="pemCuisineList" autocomplete="off"
                       placeholder="음식 종류 입력 후 Enter (쉼표로 여러 개)"
                       onkeydown="if(event.key==='Enter'){event.preventDefault();plEditCuisineAdd();}">
                <datalist id="pemCuisineList"></datalist>
                <div id="pemCuisines" class="pem-tags"></div>
            </div>
            <div class="pem-refs-hd">📰 연결된 기사 <span class="pem-refs-cnt" id="pemRefsCnt"></span></div>
            <div id="pemRefs" class="pem-refs"></div>
            <div class="pem-refadd">
                <input type="text" id="pemRefTitle" class="pem-inp" placeholder="기사 제목">
                <input type="text" id="pemRefUrl" class="pem-inp" placeholder="원문 URL (선택)"
                       onkeydown="if(event.key==='Enter'){event.preventDefault();plEditAddRef();}">
                <button type="button" class="pem-refadd-btn" onclick="plEditAddRef()">➕ 기사 추가</button>
            </div>
            <div class="pem-refs-hint" id="pemRefsHint">잘못 연결된 기사는 <b>✕</b>로 영구 삭제할 수 있습니다(되돌릴 수 없음).</div>
            <label class="pem-lbl">방문시기 <span class="pem-sub">해당 월을 클릭</span></label>
            <div class="pem-months" id="pemMonths"></div>
            <div class="pem-lbl pem-lbl-row">태그 <span class="pem-sub">벚꽃·캠핑장·야경 등 — 검색에 쓰입니다</span>
                <button type="button" class="pem-tagmgr" onclick="plTagMgrOpen()" title="태그 정리·병합·삭제">🏷 태그 관리</button>
            </div>
            <input type="text" id="pemTagInput" class="pem-inp" list="pemTagList" autocomplete="off"
                   placeholder="태그 입력 후 Enter (쉼표로 여러 개)"
                   onkeydown="if(event.key==='Enter'){event.preventDefault();plEditTagAdd();}">
            <datalist id="pemTagList"></datalist>
            <div id="pemTags" class="pem-tags"></div>
            <div id="pemTagSuggest" class="pem-tag-sug"></div>
            <label class="pem-lbl">좌표 지정 (카카오 장소 검색 → 선택)</label>
            <input type="text" id="pemSearch" class="pem-inp" placeholder="장소·주소 검색 (예: 정동진 해안)"
                   oninput="plEditSearchDebounced()" onkeydown="if(event.key==='Enter'){event.preventDefault();plEditSearch();}">
            <div id="pemResults" class="pem-results"></div>
            <div id="pemPicked" class="pem-picked"></div>
            <div class="pem-extra-wrap">
                <div class="pem-extra-hd">➕ 이 기사에 나온 다른 장소 <span class="pem-extra-cnt" id="pemExtraCnt"></span></div>
                <div id="pemExtra" class="pem-extra"></div>
                <div class="pem-extra-hint">검색 결과의 <b>➕</b> 버튼을 누르면 같은 기사를 공유하는 별도 장소로 추가됩니다. 각 장소의 <b>제목·분류</b>를 따로 지정할 수 있고, 저장 시 각각 지도 마커가 생깁니다.</div>
            </div>
            <div class="pem-hint">좌표를 선택하지 않고 저장하면 이름·분류만 바뀌고 지도엔 계속 안 뜹니다. 좌표를 선택해 저장하면 지도에 마커가 생깁니다.</div>
        </div>
        <div class="pem-foot">
            <button class="btn btn-ghost" onclick="plEditClose()">취소</button>
            <button class="btn btn-primary" id="pemSave" onclick="plEditSave()">저장</button>
        </div>
    </div>
</div>

<div id="pl-tagmgr" class="pl-modal">
    <div class="pem-box tm-box">
        <div class="pem-head"><span>🏷 태그 관리</span><button class="pem-x" onclick="plTagMgrClose()">×</button></div>
        <div class="tm-top">
            <input type="text" id="tmSearch" class="pem-inp" placeholder="🔍 태그 찾기…" autocomplete="off" oninput="plTagMgrRender()">
            <span class="tm-count" id="tmCount"></span>
        </div>
        <div id="tmGrid" class="tm-grid"></div>
        <div id="tmAction" class="tm-action"></div>
    </div>
</div>

<div id="pl-aisum" class="pl-modal" onclick="if(event.target===this)plCloseAiSummary()">
    <div class="pem-box" style="width:460px;max-width:94vw;">
        <div class="pem-head"><span>🤖 Claude 요약</span><button class="pem-x" onclick="plCloseAiSummary()">×</button></div>
        <div class="pem-body">
            <div class="aisum-name" id="aisumName"></div>
            <div class="aisum-text" id="aisumText"></div>
            <div class="aisum-sub">※ 기사 내용을 바탕으로 Claude가 정리한 요약입니다.</div>
        </div>
    </div>
</div>

<div id="pl-reco" class="pl-modal" onclick="if(event.target===this)plRecoClose()">
    <div class="pem-box" style="width:520px;max-width:94vw;">
        <div class="pem-head"><span>🤖 AI 장소 추천</span><button class="pem-x" onclick="plRecoClose()">×</button></div>
        <div class="pem-body">
            <div class="reco-inrow">
                <input type="text" id="recoQ" class="pem-inp" placeholder="예: 8월에 캠핑카로 갈 물놀이 좋은 계곡 / 제주 비 오는 날 실내 맛집" autocomplete="off" onkeydown="if(event.key==='Enter')plRecoRun()">
                <button class="tm-btn primary" id="recoBtn" onclick="plRecoRun()">추천</button>
            </div>
            <div class="reco-ex">예시: <a onclick="plRecoEx('여름에 아이랑 물놀이하기 좋은 계곡')">여름 계곡 물놀이</a> · <a onclick="plRecoEx('반려동물 동반 가능한 캠핑장')">반려동물 캠핑</a> · <a onclick="plRecoEx('제주 저수지나 호수가 있는 조용한 여행지')">제주 호수</a></div>
            <div id="recoResult" class="reco-result"></div>
        </div>
    </div>
</div>

<div id="pl-merge" class="pl-modal">
    <div class="pem-box" style="width:480px;max-width:94vw;">
        <div class="pem-head"><span>🔀 중복 장소 병합</span><button class="pem-x" onclick="plMergeClose()">×</button></div>
        <div class="pem-body">
            <div class="tm-hint">남길 <b>대표 장소</b>를 고르세요. 나머지의 <b>기사·태그가 대표로 합쳐지고</b> 중복 장소는 삭제됩니다(되돌릴 수 없음).</div>
            <div id="plMergePick" class="pm-pick"></div>
        </div>
        <div class="pem-foot">
            <button class="btn btn-ghost" onclick="plMergeClose()">취소</button>
            <button class="tm-btn primary" onclick="plMergeApply()">합치기</button>
        </div>
    </div>
</div>

<div id="rt-trip" class="pl-modal">
    <div class="pem-box" style="width:460px;max-width:94vw;">
        <div class="pem-head"><span>📂 여행지도 저장함</span><button class="pem-x" onclick="rtTripClose()">×</button></div>
        <div class="pem-body">
            <div class="rt-trip-save">
                <input type="text" id="rtTripName" class="pem-inp" placeholder="여행지도 이름 (예: 서울→양양 맛집투어)"
                       onkeydown="if(event.key==='Enter'){event.preventDefault();rtTripSaveDo();}">
                <button class="tm-btn primary" onclick="rtTripSaveDo()">💾 현재 지도 저장</button>
            </div>
            <div class="rt-trip-cur" id="rtTripCur"></div>
            <div class="pem-refs-hd" style="margin-top:6px;">저장된 여행지도</div>
            <div id="rtTripList" class="rt-trip-list"></div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
var PLACE_NAVER_KEY = <?= json_encode($naverClientId) ?>;
var PL_SHARE        = <?= json_encode($isGuest && empty($tripToken) ? $shareToken : '') ?>; // 일반 공유(지도 전체) 게스트 토큰
var PL_TRIP         = <?= json_encode($tripToken ?? '') ?>; // 트립 공유 토큰(?trip=) — 있으면 그 여행지도만 게스트 열람
var PL_GUEST        = !!(PL_SHARE || PL_TRIP);   // 게스트(공유)면 보기 전용 — 찜·경로추가·길찾기 버튼 숨김
var PL_GUIDES       = <?= json_encode(FoodGuide::clientDefs(), JSON_UNESCAPED_UNICODE) ?>; // 맛집 가이드 정의(색·prio·등급라벨)
var PL_FOCUS        = <?= (int)($_GET['place'] ?? 0) ?>;   // ?place=<id> 로 들어오면 그 장소를 지도에 바로 띄운다(아덴트 수집기록 등 외부 링크용)

// ── 맛집 가이드 헬퍼 (마커색·배지) ──
// 대표 가이드 = 한 장소의 여러 guide 중 prio 최댓값(미쉐린 > 블루리본 > 기타)
function plPrimaryGuide(guides) {
    if (!guides || !guides.length) return null;
    var best = null, bp = -1;
    for (var i = 0; i < guides.length; i++) {
        var d = PL_GUIDES[guides[i].guide]; var p = d ? (d.prio || 0) : 0;
        if (p > bp) { bp = p; best = guides[i]; }
    }
    return best;
}
function plGuideColor(guides) {
    var g = plPrimaryGuide(guides);
    return (g && PL_GUIDES[g.guide]) ? PL_GUIDES[g.guide].color : null;
}
// 마커 테두리색: 맛집이면 대표가이드색, 그 외 분류는 기본(category 색)
function plMkColor(pr) {
    return (pr && pr.category === 'restaurant') ? plGuideColor(pr.guides) : null;
}
// 마커 등급 배지 HTML(맛집만)
function plMkBadge(pr) {
    return (pr && pr.category === 'restaurant') ? plGradeBadge(pr) : '';
}
// 가이드 칩 안에 표시할 등급 글리프 — 블루리본=흰 리본×N / 미쉐린=★×N(그린스타·빕·셀)
//  레벨 정본 = place_tag(kind='grade') 태그(리본N/스타N) — 마커 배지 plGradeBadge 와 동일 소스
function plGuideGradeHtml(guideKey, tags) {
    tags = tags || [];
    function has(t) { return tags.indexOf(t) >= 0; }
    function lv(p) { for (var n = 3; n >= 1; n--) if (has(p + n)) return n; return 0; }
    var out = '';
    if (guideKey === 'bluer') {
        var r = lv('리본');
        for (var i = 0; i < r; i++) out += PL_RIBBON_SVG;
    } else if (guideKey === 'michelin') {
        var s = lv('스타');
        if (s) { for (var j = 0; j < s; j++) out += '★'; }
        else if (has('그린스타')) out += '<span class="lg-gs">★</span>';
        else if (has('빕구르망')) out += '<span class="lg-bib">빕</span>';
        else if (has('셀렉티드')) out += '·';
    }
    return out ? '<span class="lg-grade">' + out + '</span>' : '';
}
// 카드/패널용 가이드 배지들 [한글명+등급글리프] (pr 로 guides·tags 함께 읽음)
function plGuideBadges(pr) {
    var guides = (pr && pr.guides) || [];
    if (!guides.length) return '';
    var tags = (pr && pr.tags) || [];
    return guides.map(function (g) {
        var d = PL_GUIDES[g.guide]; if (!d) return '';
        return '<span class="li-guide" style="background:' + d.color + '">' +
               plEsc(d.ko) + plGuideGradeHtml(g.guide, tags) + '</span>';
    }).join('');
}
// 마커 등급 배지 — 가진 가이드별 등급을 모두 표시(블루리본=리본, 미쉐린=별).
//  블루리본 리본N → 리본×N / 미쉐린 스타N → ★×N, 그린스타 → 초록★, 빕구르망 → B
function plGradeBadge(pr) {
    var guides = (pr && pr.guides) || [];
    if (!guides.length) return '';
    var tags = (pr && pr.tags) || [];
    function has(t) { return tags.indexOf(t) >= 0; }
    function hasGuide(g) { for (var i = 0; i < guides.length; i++) if (guides[i].guide === g) return true; return false; }
    function lv(prefix) { for (var n = 3; n >= 1; n--) if (has(prefix + n)) return n; return 0; }
    var out = '';
    if (hasGuide('bluer')) {                       // 블루리본 = 리본 글리프
        var r = lv('리본');
        for (var i = 0; i < r; i++) out += PL_RIBBON_SVG;
    }
    if (hasGuide('michelin')) {                    // 미쉐린 = 별
        var s = lv('스타');
        if (s) { for (var j = 0; j < s; j++) out += '<i class="mk-star">★</i>'; }
        else if (has('그린스타')) out += '<i class="mk-star mk-gstar">★</i>';
        else if (has('빕구르망')) out += '<i class="mk-bib">B</i>';
        else if (has('셀렉티드')) out += '<i class="mk-bib">·</i>';
    }
    return out;
}
var plMap = null, plMarkers = [], plReady = false;
var plFeatures = [], plActive = -1;
var plPendingFocusId = null;          // 통합검색에서 우리 DB 장소 선택 시, 검색결과 렌더 후 그 마커 강조
var plIdleTimer = null, plLastSearchAt = 0;   // 지도 멈춤(idle) 자동검색 디바운스 + 중복방지
var plLastSearchZoom = null;                  // 마지막 검색 시 줌(줌 변경 감지 → 티어 재검색용)
var plMergeSel = [];                  // 편집모드 병합 선택 장소 id 목록
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
// ── 줌 기반 주변 오버레이 ─────────────────────────────────────
//  베이스(필터 결과, 예: 호수)는 그대로 두고, 줌인하면 화면 안의 '선택 안 한 다른 분류'를
//  자동으로 지도 위에 얹는다(마커만). 줌아웃(임계 미만)하면 오버레이 마커만 사라짐.
//  ★좌측 목록은 항상 베이스(필터) 결과를 유지한다 — 줌에 따라 목록 의미가 바뀌지 않게.
//  ※ 분류(또는 태그)가 선택돼 베이스가 좁혀진 상태에서만 동작 — 주변 맥락을 덧대는 용도.
var PL_DETAIL_ZOOM = 14;       // 이 줌 이상이면 주변 오버레이 표시(네이버 스케일 ≈ 300m. 100m=16/200m=15/300m=14/500m=13/1km=12)
// 분류별: 줌인 시 그 분류를 볼 때 '주변 오버레이'(다른 분류 덧대기)를 띄울지.
//  ★단일 진실원천 — 기본은 전 분류 동일(true). 켜고 끄는 건 사용자가 🔭주변 토글(plOverlayOn)로 일괄 제어.
//  특정 분류만 '항상' 끄고 싶으면(분기 추가 말고) 여기서 그 분류만 false 로.
var PL_ZOOM_OVERLAY = { travel: true, restaurant: true, stay: true, camping: true, etc: true };
// 줌인 주변 오버레이 전체 ON/OFF (칩바 🔭주변 버튼). 분류별 PL_ZOOM_OVERLAY 위에 얹는 마스터 스위치. 기본 ON·기억됨.
var plOverlayOn = (function () { try { return localStorage.getItem('pl_overlay_on') !== '0'; } catch (e) { return true; } })();
var plOv = [];                 // 현재 오버레이 마커들
var plOvFeats = [];            // 오버레이 장소(마커 클릭 plProxPick 참조용)
var plOvActive = -1;           // 오버레이/경로주변에서 선택(강조) 중인 마커 인덱스
function plClearOverlay() { plOv.forEach(function (m) { m.setMap(null); }); plOv = []; plOvActive = -1; }
// 오버레이 종료(줌아웃) → 마커만 제거(좌측 목록은 베이스라 손대지 않음)
function plExitOverlay() { plClearOverlay(); plOvFeats = []; }
// 오버레이 마커 클릭 = 그 장소로 이동 + 선택 강조 + 상세패널
function plProxPick(i) {
    var f = plOvFeats[i]; if (!f) return;
    var co = f.geometry.coordinates;
    plMap.panTo(new naver.maps.LatLng(co[1], co[0]));
    f.properties.lat = co[1]; f.properties.lng = co[0];
    // 선택 마커 강조(기존 베이스 active 와 동일) — 이전 선택은 원복
    if (plOvActive >= 0 && plOv[plOvActive] && plOvFeats[plOvActive]) {
        plOv[plOvActive].setIcon(plProxIcon(plOvFeats[plOvActive].properties));
        plOv[plOvActive].setZIndex(90);
    }
    if (plOv[i]) {
        var pr = f.properties;
        plOv[i].setIcon(plMarkerIcon(pr.category, '', true, plMkColor(pr), plMkBadge(pr)));   // active
        plOv[i].setZIndex(200);
        plOvActive = i;
    }
    // 경로 모드: 선택 동기화(찜 라벨 active 해제 + 리스트 강조 유지)
    if (typeof rtMode !== 'undefined' && rtMode) { rtSelId = f.properties.id; rtDrawPicks(); rtRenderSummary(); }
    plOpenById(f.properties.id, f.properties.lat, f.properties.lng, f.properties);   // 단일 경로
}
// 중심 (lat,lng) 기준 ±km 박스로 지도를 맞춤 → 화면 크기와 무관하게 '중심·반경 km'가 보임
function plFitRadius(lat, lng, km) {
    var dLat = km / 111;
    var dLng = km / (111 * Math.cos(lat * Math.PI / 180));
    var b = new naver.maps.LatLngBounds(
        new naver.maps.LatLng(lat - dLat, lng - dLng),
        new naver.maps.LatLng(lat + dLat, lng + dLng));
    try { plMap.fitBounds(b); } catch (e) {}
}
// ±km 박스가 현재 지도 뷰포트에 딱 맞을 때의 줌(정수) — fitBounds 가 갈 줌을 미리 계산(웹 메르카토르)
function plFitZoom(lat, km) {
    var el = document.getElementById('map');
    var W = (el && el.clientWidth) || 800, H = (el && el.clientHeight) || 600;
    var dLat = km / 111, dLng = km / (111 * Math.cos(lat * Math.PI / 180));
    function mercY(d) { var s = Math.max(-0.9999, Math.min(0.9999, Math.sin(d * Math.PI / 180)));
                        return 0.5 - Math.log((1 + s) / (1 - s)) / (4 * Math.PI); }
    var zLng = Math.log(W * 360 / (256 * 2 * dLng)) / Math.LN2;
    var zLat = Math.log(H / (256 * Math.abs(mercY(lat + dLat) - mercY(lat - dLat)))) / Math.LN2;
    return Math.floor(Math.min(zLng, zLat));
}
// 주변 오버레이 마커 = 베이스와 동일한 일반 지도 핀(맛집=빨강 원+포크 / 그 외=분류색 물방울 핀).
//  번호만 비워 베이스(번호핀)와 구분. 줌아웃하면 사라짐.
function plProxIcon(pr) {
    return plMarkerIcon(pr.category, '', false, plMkColor(pr), plMkBadge(pr));
}
// 오버레이 표시 자격 = 선택 분류 중 '오버레이를 원하는'(PL_ZOOM_OVERLAY) 게 하나라도 + 충분히 줌인.
//  맛집만 보면 오버레이 OFF, 여행지·숙소 등은 줌인 시 주변 맥락을 덧댄다(분류별 동작은 PL_ZOOM_OVERLAY 한 곳에서 결정).
function plOverlayElig() { return plReady && plOverlayOn && !(typeof rtMode !== 'undefined' && rtMode) && plCatSel.some(function (c) { return PL_ZOOM_OVERLAY[c]; }) && plMap.getZoom() >= PL_DETAIL_ZOOM; }
// 🔭주변 버튼 = 줌인 오버레이 마스터 스위치. 끄면 즉시 사라지고 켜면 즉시 덧댐.
function plToggleOverlay() {
    plOverlayOn = !plOverlayOn;
    try { localStorage.setItem('pl_overlay_on', plOverlayOn ? '1' : '0'); } catch (e) {}
    plOvToggleRender();
    plUpdateOverlay();   // 끄면 plExitOverlay 로 제거, 켜면 자격 충족 시 다시 덧댐
    plUpdateLabels();
}
function plOvToggleRender() {
    var b = document.getElementById('ovToggle'); if (!b) return;
    b.classList.toggle('on', plOverlayOn);
    b.textContent = plOverlayOn ? '🔭 주변 ON' : '🔭 주변 OFF';
}
// 줌/뷰포트에 맞춰 오버레이 갱신 — idle 마다 호출. 조건 미충족이면 제거.
function plUpdateOverlay() {
    if (!plReady) return;
    // 베이스가 좁혀진(분류 선택) 상태이고 충분히 줌인했을 때만 주변을 덧댄다.
    if (!plOverlayElig()) { plExitOverlay(); return; }
    // 베이스가 전담하는 '선택 분류'는 빼고, 나머지 분류만 주변 맥락으로 덧댄다(같은 분류 중복 핀 방지).
    var others = PL_CATS_ALL.filter(function (c) { return plCatSel.indexOf(c) < 0; });
    if (!others.length) { plExitOverlay(); return; }   // 모든 분류 선택 = 덧댈 다른 분류 없음
    var c = plMap.getCenter(), rad = plViewportRadiusKm();
    var sp = { module: 'place', action: 'search', lat: c.lat(), lng: c.lng(), radius: rad,
               limit: 400, categories: others.join(','), mr_restaurant: 0, mr_stay: 0, mr_camping: 0 };   // 선택분류 제외·다 표시
    fetch(plApiUrl(sp))
        .then(function (r) { return r.json(); })
        .then(function (geo) {
            plClearOverlay();
            if (!plOverlayElig()) { plExitOverlay(); return; }   // 응답 사이 줌아웃/분류 해제
            var baseIds = {};
            plFeatures.forEach(function (f) { baseIds[f.properties.id] = 1; });
            // 베이스 중복 제외 → 맛집 리뷰순/거리순 정렬 → 그 순서로 마커·목록(인덱스 일치)
            var ovFeats = plSortFeatures(((geo && geo.features) || []).filter(function (f) {
                return !baseIds[f.properties.id];
            }));
            ovFeats.forEach(function (f, i) {
                var co = f.geometry.coordinates, pr = f.properties;
                var mk = new naver.maps.Marker({
                    position: new naver.maps.LatLng(co[1], co[0]), map: plMap,
                    title: pr.name, zIndex: 90, icon: plProxIcon(pr)
                });
                naver.maps.Event.addListener(mk, 'click', (function (idx) {
                    return function () { plProxPick(idx); };
                })(i));
                plOv.push(mk);
            });
            plOvFeats = ovFeats;   // 좌측 목록은 베이스(필터) 유지, 마커만 덧댐
            plUpdateLabels();      // 오버레이 로드 후 라벨 갱신
        })
        .catch(function () {});
}
// ── 줌인 시 마커 라벨(순위·이름·리뷰수). 겹치는 마커는 하나의 말풍선으로 묶어 전부 표시 ──
var PL_LABEL_ZOOM = 15;        // 이 줌 이상에서 라벨 표시(오버레이 14보다 한 단계 더 깊게)
var PL_CLUSTER_PX = 30;        // 이 픽셀 이내로 가까운(겹치는) 마커는 한 말풍선으로 묶음
var PL_BUBBLE_MAX = 12;        // 말풍선 한 개에 나열할 최대 항목(초과분은 '외 N곳')
var plLabels = [];

function plClearLabels() { plLabels.forEach(function (m) { m.setMap(null); }); plLabels = []; }

function plRev(f) { var nv = f.properties.attributes && f.properties.attributes.naver; return (nv && nv.review) || 0; }

function plLabelText(pr) {
    var nv = pr.attributes && pr.attributes.naver;
    return { rank: (pr._n != null && pr._n !== '') ? pr._n : null, name: pr.name || '', rev: (nv && nv.review) ? Number(nv.review) : 0 };
}
function plLabelWidth(t) {   // 충돌검사용 대략 폭(px)
    var nameW = Math.min(130, (t.name || '').length * 12);
    return 20 + (t.rank != null ? 18 : 0) + nameW + (t.rev ? 52 : 0);
}
function plRectHit(r, list) {
    for (var i = 0; i < list.length; i++) {
        var p = list[i];
        if (!(r.x + r.w < p.x || r.x > p.x + p.w || r.y + r.h < p.y || r.y > p.y + p.h)) return true;
    }
    return false;
}

// 클릭(라벨/말풍선 항목) → 해당 장소 포커스
function plLabelClick(ov, idx) { ov ? plProxPick(idx) : plFocus(idx); }

function plRowHtml(c) {
    var t = plLabelText(c.f.properties);
    var rank = (t.rank != null) ? '<b class="mkl-rank">' + t.rank + '</b>' : '';
    var rev  = t.rev ? '<span class="mkl-rev">📝' + t.rev.toLocaleString() + '</span>' : '';
    return '<div class="mkb-row" onclick="plLabelClick(' + (c.ov ? 1 : 0) + ',' + c.idx + ')">' +
           rank + '<span class="mkl-nm">' + plEsc(t.name) + '</span>' + rev + '</div>';
}

function plBoxSize(cl) {
    if (cl.items.length === 1) return { w: plLabelWidth(plLabelText(cl.items[0].f.properties)), h: 24 };
    var n = Math.min(PL_BUBBLE_MAX, cl.items.length);
    var w = 0;
    for (var i = 0; i < n; i++) w = Math.max(w, plLabelWidth(plLabelText(cl.items[i].f.properties)));
    var rows = n + (cl.items.length > PL_BUBBLE_MAX ? 1 : 0);
    return { w: w + 6, h: rows * 21 + 8 };
}

function plMakeBox(cl, offX, offY) {
    var multi = cl.items.length > 1;
    var rows = cl.items.slice(0, PL_BUBBLE_MAX).map(plRowHtml).join('');
    var more = cl.items.length - PL_BUBBLE_MAX;
    if (more > 0) rows += '<div class="mkb-more">외 ' + more + '곳</div>';
    var cls = multi ? 'mk-bubble' : 'mk-label';
    return new naver.maps.Marker({
        position: cl.items[0].ll, map: plMap, zIndex: multi ? 110 : 60, clickable: true,
        icon: { content: '<div class="' + cls + '" style="transform:translate(' + offX + 'px,' + offY + 'px)">' + rows + '</div>',
                anchor: new naver.maps.Point(0, 0) }
    });
}

function plUpdateLabels() {
    if (!plReady) return;
    plClearLabels();
    if (plMap.getZoom() < PL_LABEL_ZOOM) return;
    var proj = plMap.getProjection && plMap.getProjection();
    if (!proj || !proj.fromCoordToOffset) return;   // 투영 불가 → 위치계산 불가시 라벨 생략
    var bounds = plMap.getBounds();

    // 1) 화면 안 후보 + 픽셀좌표 (리뷰 많은 순)
    var cands = [];
    function push(f, idx, ov) {
        var co = f.geometry.coordinates, ll = new naver.maps.LatLng(co[1], co[0]);
        if (bounds && !bounds.hasLatLng(ll)) return;
        if (!f.properties.name) return;
        var pt = proj.fromCoordToOffset(ll);
        cands.push({ f: f, idx: idx, ov: ov, ll: ll, x: pt.x, y: pt.y, rev: plRev(f) });
    }
    // 경로 모드에선 베이스(plFeatures)는 숨겨져 있으니 라벨에서 제외. 경로 주변은 plOvFeats 로 들어와 동일하게 처리.
    var rtOn = (typeof rtMode !== 'undefined' && rtMode);
    if (!rtOn) plFeatures.forEach(function (f, i) { push(f, i, false); });
    plOvFeats.forEach(function (f, i) {
        if (rtOn && rtIsPicked(f.properties.id)) return;   // 찜은 전용 반전 라벨이 따로 항상 떠 있음(중복 방지)
        push(f, i, true);
    });
    if (!cands.length) return;
    cands.sort(function (a, b) { return b.rev - a.rev; });

    // 2) 픽셀 근접 클러스터링 — 겹치는 마커끼리 한 말풍선으로
    var clusters = [];
    cands.forEach(function (c) {
        for (var i = 0; i < clusters.length; i++) {
            var dx = c.x - clusters[i].x, dy = c.y - clusters[i].y;
            if (dx * dx + dy * dy <= PL_CLUSTER_PX * PL_CLUSTER_PX) { clusters[i].items.push(c); return; }
        }
        clusters.push({ x: c.x, y: c.y, items: [c] });
    });

    // 3) 박스 배치 — 마커 가까이 8방향 빈자리를 찾고, 연결선(리더선)으로 마커와 이음
    var canInv = !!proj.fromOffsetToCoord;
    var placed = [], MAX = 120;   // 클러스터당 선+박스 2개 push
    for (var i = 0; i < clusters.length && plLabels.length < MAX; i++) {
        var cl = clusters[i], sz = plBoxSize(cl), w = sz.w, h = sz.h;
        var best = null;
        for (var ring = 0; ring < 4 && !best; ring++) {
            var gap = 12 + ring * 16;
            var offs = [
                [gap, -h / 2], [gap, -h - 4], [gap, 4],                 // 오른쪽 / 위 / 아래
                [-(w + gap), -h / 2], [-(w + gap), -h - 4], [-(w + gap), 4], // 왼쪽 3
                [-w / 2, -(h + gap)], [-w / 2, gap]                     // 위 / 아래
            ];
            for (var oi = 0; oi < offs.length; oi++) {
                var ox = offs[oi][0], oy = offs[oi][1];
                var rect = { x: cl.x + ox, y: cl.y + oy, w: w, h: h, ox: ox, oy: oy };
                if (!plRectHit(rect, placed)) { best = rect; break; }
            }
        }
        if (!best) best = { x: cl.x + 14, y: cl.y - h / 2, w: w, h: h, ox: 14, oy: -h / 2 };  // 자리 없으면 오른쪽
        placed.push(best);
        // 연결선: 마커 → 박스에서 마커와 가장 가까운 점
        if (canInv) {
            var cpx = Math.max(best.x, Math.min(cl.x, best.x + w));
            var cpy = Math.max(best.y, Math.min(cl.y, best.y + h));
            plLabels.push(new naver.maps.Polyline({
                map: plMap, path: [cl.items[0].ll, proj.fromOffsetToCoord(new naver.maps.Point(cpx, cpy))],
                strokeColor: '#5b6470', strokeWeight: 1.4, strokeOpacity: .7, zIndex: 50
            }));
        }
        plLabels.push(plMakeBox(cl, best.ox, best.oy));
    }
}

var PL_RADII = [3, 5, 10, 15, 20, 30, 50]; // 자동 확장 사다리

// place_api.php URL 빌더 — 게스트면 share 토큰 자동 첨부
function plApiUrl(params) {
    var p = new URLSearchParams(params);
    if (PL_SHARE) p.set('share', PL_SHARE);
    if (PL_TRIP) p.set('trip', PL_TRIP);   // 트립 공유 게스트: 모든 요청에 trip 토큰 동봉(서버가 게스트로 인가)
    return 'place_api.php?' + p.toString();
}

// ── 네이버 SDK 로더 (검증된 onload Promise 패턴) ──
function plLoadNaver() {
    return new Promise(function (resolve, reject) {
        if (window.naver && window.naver.maps) return resolve();
        if (!PLACE_NAVER_KEY) return reject('no-key');
        var s = document.createElement('script');
        s.src = 'https://oapi.map.naver.com/openapi/v3/maps.js?ncpKeyId=' + encodeURIComponent(PLACE_NAVER_KEY) + '&submodules=geocoder';
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
        plTagBarInit();   // 상단 태그 칩 바 로드
        plOvToggleRender();   // 🔭주변 토글 버튼 초기 상태(저장값) 반영

        // 추이 페이지에서 저장한 분류별 지도 기준(localStorage 'pl_map_filter') 로드.
        //  기본 분류='전체' → 맛집·스테이·캠핑을 각 기준으로 함께 표시.
        plLoadCatFilter();

        // 공유 링크(?trip=토큰)면 그 여행지도만 표시(일반 검색 생략)
        if (PL_TRIP) { rtViewShared(PL_TRIP); }
        // 지도 열면 현재 화면의 마커를 바로 표시(뷰포트 + 최소리뷰 기준) — 바운드 준비 후 1회
        else setTimeout(function () { if (plReady && !plChipActive()) plSearchHere(); }, 600);

        // 지도 멈추면(이동·줌 종료) 그 지역 자동 검색 — 별도 '이 지역 검색' 버튼 대체
        naver.maps.Event.addListener(plMap, 'idle', function () {
            clearTimeout(plIdleTimer);
            plIdleTimer = setTimeout(function () {
                if (typeof rtMode !== 'undefined' && rtMode) { if (rtFinalized) { /* 찜·경로만: 주변 갱신 안 함(찜 마커 유지) */ } else if (rtRoute.length >= 2) rtFetchNearby(); else plUpdateLabels(); return; }   // 경로 모드: 이동/줌 시 현재 화면 ∩ 회랑 재조회(뷰포트 증분)
                plUpdateOverlay();                                 // 줌인=주변(전 분류) 오버레이 표시 / 줌아웃=제거(베이스 유지)
                plUpdateLabels();                                  // 줌/이동 후 마커 라벨(순위·이름·리뷰) 갱신
                if (plRegionLock) return;                          // 시도 고정 중: 베이스(그 시도) 유지, 줌/이동에 재검색 안 함
                if (plChipActive()) return;                        // 칩(여행·맛집) 검색 중이면 자동검색 안 함(전국 결과 보호)
                if (Date.now() - plLastSearchAt < 800) return;     // 방금 검색했으면(프로그램 이동) 중복 방지
                // 줌이 바뀌면 품질 티어가 달라지므로 재검색. 단순 이동(pan)은 재검색 안 함.
                var zoomed = (plLastSearchZoom !== null && plMap.getZoom() !== plLastSearchZoom);
                if (!zoomed) return;
                plSearchHere();
            }, 450);
        });

        // 생성 직후 컨테이너 크기 보정 (회색 타일 방지) — 레이아웃 확정 타이밍을 놓치지 않게 다단 + 옵저버
        [60, 250, 600].forEach(function (ms) { setTimeout(plBumpResize, ms); });
        window.addEventListener('resize', plBumpResize);
        window.addEventListener('load', plBumpResize);
        if (window.ResizeObserver) {
            // #map 박스 크기가 바뀔 때마다(툴바 줄바꿈·폴더블 등) 자동 repaint
            new ResizeObserver(plBumpResize).observe(document.getElementById('map'));
        }
        // ?place=<id> — 외부(아덴트 수집기록 등)에서 특정 장소로 들어온 경우 그 마커+상세패널을 연다.
        // 분류 칩이 기본 미선택이라 일반 검색 결과엔 안 잡히므로 강제 표시 경로를 쓴다.
        if (PL_FOCUS > 0) setTimeout(function () { plForceShowPlace(PL_FOCUS); }, 350);
    }).catch(function (e) {
        document.getElementById('hint').textContent =
            (e === 'no-key') ? '네이버 지도 키가 설정되지 않았습니다 (env/maps.inc)' : '지도 로딩 실패';
    });
}
plInit();

// ── 분류 칩바 — 멀티선택(개별 on/off) ──
//  기본 = 아무것도 선택 안 함(빈 지도 + 안내). 분류를 골라야 그 분류만 표시(category IN).
var PL_CATS_ALL = ['travel', 'restaurant', 'stay', 'camping', 'etc'];
var PL_CAT_KO   = { travel: '여행지', restaurant: '맛집', stay: '숙소', camping: '캠핑장', etc: '기타' };
var plCatSel    = [];   // 기본 = 미선택(새로고침 시 아무 분류도 선택 안 된 상태)

function plCatBarRender() {
    var box = document.getElementById('cbChips'); if (!box) return;
    var h = '';
    PL_CATS_ALL.forEach(function (c) {
        var on = plCatSel.indexOf(c) >= 0;
        h += '<button class="cb-chip cat-' + c + (on ? ' active' : '') + '" onclick="plCatChipClick(\'' + c + '\')">'
           + PL_CAT_KO[c] + '</button>';
    });
    box.innerHTML = h;
}
// 분류 변경 = 칩바 갱신 + 빠진 도메인의 고아 태그만 정리(나머지 태그는 보존) + 통합 재검색
function plCatApply() {
    plCatPrune();
    plCatBarRender(); plTagBarRender(); plMonthBarRender(); plFoodBarRender(); plSubRender('stay'); plSubRender('camp');
    plReload();
}
// 분류에서 빠진 도메인의 태그를 정리(그 분류를 빼면 그 분류의 태그 해제)
function plCatPrune() {
    if (plCatSel.indexOf('travel') < 0)     { plSelTags = []; plSelMonth = null; }
    if (plCatSel.indexOf('restaurant') < 0) { plSelGuide = null; plSelFood = []; }
    if (plCatSel.indexOf('stay') < 0)        PL_SUBBARS.stay.sel = [];
    if (plCatSel.indexOf('camping') < 0)     PL_SUBBARS.camp.sel = [];
}
function plCatChipClick(c) {
    var i = plCatSel.indexOf(c);
    if (i >= 0) plCatSel.splice(i, 1);   // 켜진 칩 재클릭 = 해제
    else plCatSel.push(c);               // 추가(멀티)
    plCatApply();
}
plCatBarRender();

// ── 지역(시도) 선택 바 — 2단계: 권역 칩 → 시도 칩 ──
//  지도 마커는 '보이는 화면' 기준이라, 시도를 고르면 그 시도로 지도를 이동(setCenter+줌)하고
//  plSearch(반경) 로 마커를 다시 불러온다(plGeocode 와 동일 패턴). 칩 필터는 plSearch 가 자동 해제.
//  km = 시도 중심에서 가장자리까지 대략 반경(검색 반경 겸 화면에 들어올 줌 계산용).
//  r1 = 그 시도 '실제 주소(address)' 접두 매칭 목록. 백엔드가 address LIKE 'r1%' 로 거름.
//   region_lv1 은 수집 시드값이라 경계 장소가 어긋나(예: 강원검색에 딸려온 충북 제천) → 진짜 위치인 주소로 필터.
//   단축형이 풀형을 접두로 덮음(강원→강원도/강원특별자치도, 충남→충남만이라 충청남도 별도). 도 명칭개정(전북특별자치도)도 포함.
var PL_REGIONS = [
    { name: '수도권', sido: [
        { name: '서울', lat: 37.5665, lng: 126.9780, km: 20, r1: ['서울'] },
        { name: '인천', lat: 37.4563, lng: 126.7052, km: 28, r1: ['인천'] },
        { name: '경기', lat: 37.4138, lng: 127.5183, km: 60, r1: ['경기'] }
    ] },
    { name: '강원', sido: [
        { name: '강원', lat: 37.8228, lng: 128.1555, km: 80, r1: ['강원'] }
    ] },
    { name: '충청', sido: [
        { name: '대전', lat: 36.3504, lng: 127.3845, km: 14, r1: ['대전'] },
        { name: '세종', lat: 36.4801, lng: 127.2890, km: 12, r1: ['세종'] },
        { name: '충북', lat: 36.8000, lng: 127.7000, km: 55, r1: ['충북', '충청북도'] },
        { name: '충남', lat: 36.5184, lng: 126.8000, km: 55, r1: ['충남', '충청남도'] }
    ] },
    { name: '호남', sido: [
        { name: '광주', lat: 35.1595, lng: 126.8526, km: 14, r1: ['광주'] },
        { name: '전북', lat: 35.7175, lng: 127.1530, km: 55, r1: ['전북', '전라북도'] },
        { name: '전남', lat: 34.8679, lng: 126.9910, km: 70, r1: ['전남', '전라남도'] }
    ] },
    { name: '영남', sido: [
        { name: '부산', lat: 35.1796, lng: 129.0756, km: 20, r1: ['부산'] },
        { name: '대구', lat: 35.8714, lng: 128.6014, km: 22, r1: ['대구'] },
        { name: '울산', lat: 35.5384, lng: 129.3114, km: 22, r1: ['울산'] },
        { name: '경북', lat: 36.3500, lng: 128.8000, km: 80, r1: ['경북', '경상북도'] },
        { name: '경남', lat: 35.3500, lng: 128.2132, km: 65, r1: ['경남', '경상남도'] }
    ] },
    { name: '제주', sido: [
        { name: '제주', lat: 33.4996, lng: 126.5312, km: 35, r1: ['제주'] }
    ] }
];
var plRegionOpen = -1;     // -1=권역 목록 / 그 외=PL_REGIONS 인덱스(그 권역의 시도 펼침)
var plActiveSido = null;   // 현재 선택된 시도 이름(칩 active 표시용)
var plRegionLock = null;   // 시도 고정 필터 ON일 때 region_lv1 매칭 목록(plSearch 가 region 파라미터로 전송)

function plRegionSidoOf(name) {   // 시도이름 → 속한 권역 인덱스(active 표시용)
    for (var i = 0; i < PL_REGIONS.length; i++)
        for (var j = 0; j < PL_REGIONS[i].sido.length; j++)
            if (PL_REGIONS[i].sido[j].name === name) return i;
    return -1;
}
function plRegionBarRender() {
    var box = document.getElementById('rbChips');
    if (!box) return;
    var h = '';
    if (plRegionOpen < 0) {   // 권역 목록(+전국 리셋)
        h += '<button class="rb-chip rb-all' + (plActiveSido ? '' : ' active') +
             '" onclick="plRegionReset()">🇰🇷 전국</button>';
        var actReg = plActiveSido != null ? plRegionSidoOf(plActiveSido) : -1;
        PL_REGIONS.forEach(function (r, i) {
            h += '<button class="rb-chip' + (i === actReg ? ' active' : '') +
                 '" onclick="plRegionPick(' + i + ')">' + r.name + '</button>';
        });
    } else {                  // 선택 권역의 시도 목록(+뒤로)
        var r = PL_REGIONS[plRegionOpen];
        h += '<button class="rb-chip rb-back" onclick="plRegionBack()">‹ ' + r.name + '</button>';
        r.sido.forEach(function (s, j) {
            h += '<button class="rb-chip' + (plActiveSido === s.name ? ' active' : '') +
                 '" onclick="plSidoGo(' + plRegionOpen + ',' + j + ')">' + s.name + '</button>';
        });
    }
    box.innerHTML = h;
}
function plRegionPick(i) {
    if (PL_REGIONS[i].sido.length === 1) { plSidoGo(i, 0); return; }   // 강원·제주(시도 1개) = 바로 이동
    plRegionOpen = i;
    plRegionBarRender();
}
function plRegionBack() { plRegionOpen = -1; plRegionBarRender(); }
function plRegionReset() {           // 전국 조망으로 복귀(시도 잠금 해제)
    plActiveSido = null; plRegionLock = null; plRegionOpen = -1; plRegionBarRender();
    plLoadBars(null);                // 칩바 카운트 전국 기준으로
    if (!plReady) return;
    plClearOverlay();
    plMap.setCenter(new naver.maps.LatLng(36.5, 127.8));
    plMap.setZoom(7);
    plReload();   // 칩 활성이면 전국 태그검색, 아니면 전국 브라우즈
}
function plSidoGo(i, j) {             // 시도 선택 → 그 시도로 이동 + 그 시도만 고정 표시
    var s = PL_REGIONS[i].sido[j];
    plActiveSido = s.name;
    plRegionLock = s.r1;             // 시도 고정 ON → plSearch 가 region 필터 전송(반경 무시·줌 무관)
    plRegionBarRender();
    plLoadBars(plRegionLock);        // 칩바 카운트를 그 지역 기준으로(예: 강원 뷔페 5)
    if (!plReady) return;
    plClearOverlay();
    plMap.setCenter(new naver.maps.LatLng(s.lat, s.lng));
    plMap.setZoom(plFitZoom(s.lat, s.km));   // 시도 전체가 화면에 들어오는 줌
    plSearch(s.lat, s.lng, s.km);            // 지역 모드(반경 무시·시도 전역). 태그 있으면 그 시도로 함께 좁힘
}
// 시도 잠금 해제(주소검색·현재위치 등 다른 곳으로 이동할 때 호출)
function plRegionClear() {
    if (!plRegionLock && plActiveSido == null) return;
    plRegionLock = null; plActiveSido = null; plRegionOpen = -1; plRegionBarRender();
    plLoadBars(null);   // 전국 카운트로 복귀
}
plRegionBarRender();

// ── 상단 칩 바 (3행 동시 표시) ──
//  1행 #tbChips   : 여행/숙소/기타 테마 태그칩(최대3 AND)
//  2행 #mbChips   : 🌸 방문하기 좋은 달(1~12)
//  3행 #foodChips : 🍜 맛집 — 가이드칩(블루리본/미쉐린, 단일선택) + 음식종류·등급칩(최대3 AND)
//  여행(테마·달)과 맛집(가이드·음식)은 검색 도메인이 달라, 한쪽을 선택하면 다른쪽 선택은 자동 해제.
var plTagBarAll = [], plTagBarExpanded = false, plSelTags = [], plSelMonth = null;
var plBarGuides = [], plBarCuisines = [], plSelGuide = null, plSelFood = [], plFoodExpanded = false;   // 맛집 데이터/선택
var PL_TAG_MAX_TRAVEL = 2;   // 여행 테마 태그 동시 선택 최대(AND)
var PL_TAG_MAX_SUB    = 1;   // 맛집·숙소·캠핑 태그 최대(각 1개)
var PL_TAGBAR_TOP = 10, PL_FOODBAR_TOP = 10;   // 칩은 top10, 나머지는 분류별 태그 검색창으로
function plTagBarInit() {
    plMonthBarRender();       // 방문 좋은 달(1~12 정적)
    plLoadBars(plRegionLock); // 칩바 태그 로드(시도 고정 시 그 지역 카운트)
}
// ★분류별 칩바 태그·카운트를 (지역 스코프로) 로드. 시도 고정 시 그 지역 기준(예: 강원 뷔페 5).
//  지역이 바뀔 때마다 재호출 → 칩·자동완성 카운트가 현재 지역을 따른다.
function plLoadBars(region) {
    var rp = (region && region.length) ? region.join(',') : '';
    function tl(extra) {
        var p = { module: 'place', action: 'tag_list', bar: 1 };
        for (var k in extra) p[k] = extra[k];
        if (rp) p.region = rp;
        return plApiUrl(p);
    }
    // 여행 테마(월·음식·등급 제외)
    fetch(tl({})).then(function (r) { return r.json(); })
        .then(function (d) { plTagBarAll = ((d && d.items) || []).filter(function (t) { return t.kind !== 'month'; }); plTagBarRender(); })
        .catch(function () {});
    // 맛집 가이드 곳수
    var gp = { module: 'place', action: 'guide_list' }; if (rp) gp.region = rp;
    fetch(plApiUrl(gp)).then(function (r) { return r.json(); })
        .then(function (d) { plBarGuides = (d && d.items) || []; plFoodBarRender(); })
        .catch(function () {});
    // 맛집 음식종류·등급 (restaurant 스코프)
    fetch(tl({ kind: 'cuisine,grade', category: 'restaurant' })).then(function (r) { return r.json(); })
        .then(function (d) { plBarCuisines = (d && d.items) || []; plFoodBarRender(); })
        .catch(function () {});
    // 숙소·캠핑 유형 (각 분류 스코프)
    fetch(tl({ kind: 'cuisine', category: 'stay' })).then(function (r) { return r.json(); })
        .then(function (d) { PL_SUBBARS.stay.data = (d && d.items) || []; plSubRender('stay'); })
        .catch(function () {});
    fetch(tl({ kind: 'cuisine', category: 'camping' })).then(function (r) { return r.json(); })
        .then(function (d) { PL_SUBBARS.camp.data = (d && d.items) || []; plSubRender('camp'); })
        .catch(function () {});
}
// ── 숙소·캠핑 단순 태그 칩바(분류별 cuisine 태그). 구조가 같아 설정 1개로 공용. ──
var PL_SUBBARS = {
    stay: { data: [], sel: [], expanded: false, cat: 'stay',    box: 'stayChips', more: 'stayMore', bar: 'pl-staybar' },
    camp: { data: [], sel: [], expanded: false, cat: 'camping', box: 'campChips', more: 'campMore', bar: 'pl-campbar' }
};
var PL_SUBBAR_TOP = 10;
function plSubRender(key) {
    var b = PL_SUBBARS[key]; var box = document.getElementById(b.box), more = document.getElementById(b.more);
    if (!box) return;
    var list = b.expanded ? b.data : b.data.filter(function (t, i) { return i < PL_SUBBAR_TOP || b.sel.indexOf(t.tag) >= 0; });
    box.innerHTML = list.map(function (t) {
        var tg = t.tag.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
        var on = (b.sel.indexOf(t.tag) >= 0) ? ' active' : '';
        return '<button class="mb-chip' + on + '" onclick="plSubClick(\'' + key + '\',\'' + tg + '\')">' +
            plEsc(t.tag) + '<span class="tb-cnt">' + t.cnt + '</span></button>';
    }).join('');
    var hidden = b.data.length - PL_SUBBAR_TOP;
    if (more) { if (hidden > 0) { more.style.display = ''; more.textContent = b.expanded ? '접기' : ('+' + hidden + ' 더보기'); } else more.style.display = 'none'; }
    plChipContext();
}
function plSubToggleMore(key) { PL_SUBBARS[key].expanded = !PL_SUBBARS[key].expanded; plSubRender(key); }
function plSubClick(key, tag) {
    var b = PL_SUBBARS[key]; var i = b.sel.indexOf(tag);
    if (i >= 0) { b.sel.splice(i, 1); }
    else if (PL_TAG_MAX_SUB === 1) { b.sel = [tag]; }   // 1개 제한=기존 해제 후 교체
    else {
        if (b.sel.length >= PL_TAG_MAX_SUB) { plHint('태그는 최대 ' + PL_TAG_MAX_SUB + '개까지 선택할 수 있습니다'); return; }
        b.sel.push(tag);
    }
    plSubRender(key); plReload();
}

// ── 분류별 태그 검색(자동완성) — 그 분류 전체 태그에서 입력어로 필터 → 클릭하면 칩과 동일하게 토글 ──
//  data/sel/toggle 만 도메인별로 연결(travel·food 는 기존 변수, stay·camp 는 PL_SUBBARS).
var PL_TAG_DOMS = {
    travel: { box: 'travelAcBox', input: 'travelAc', data: function () { return plTagBarAll; },        sel: function () { return plSelTags; },        toggle: function (t) { plTagBarClick(t); } },
    food:   { box: 'foodAcBox',   input: 'foodAc',   data: function () { return plBarCuisines; },       sel: function () { return plSelFood; },        toggle: function (t) { plFoodClick(t); } },
    stay:   { box: 'stayAcBox',   input: 'stayAc',   data: function () { return PL_SUBBARS.stay.data; }, sel: function () { return PL_SUBBARS.stay.sel; }, toggle: function (t) { plSubClick('stay', t); } },
    camp:   { box: 'campAcBox',   input: 'campAc',   data: function () { return PL_SUBBARS.camp.data; }, sel: function () { return PL_SUBBARS.camp.sel; }, toggle: function (t) { plSubClick('camp', t); } }
};
var plTagAcItems = [], plTagAcIdx = -1, plTagAcDom = '';
function plTagAc(dom, q) {
    var cfg = PL_TAG_DOMS[dom]; if (!cfg) return;
    plTagAcDom = dom; plTagAcIdx = -1;
    var box = document.getElementById(cfg.box);
    q = (q || '').trim().toLowerCase();
    if (!q) { plTagAcClose(dom); return; }
    var sel = cfg.sel();
    var matches = cfg.data().filter(function (t) { return t.tag.toLowerCase().indexOf(q) >= 0; }).slice(0, 10);
    plTagAcItems = matches;
    box.innerHTML = matches.length
        ? matches.map(function (t, i) {
            var on = sel.indexOf(t.tag) >= 0 ? ' on' : '';
            return '<div class="pl-tagac-item' + on + '" data-i="' + i + '" onmousedown="plTagAcPick(\'' + dom + '\',' + i + ')">' +
                '<span>' + plEsc(t.tag) + '</span><span class="pl-tagac-cnt">' + t.cnt + (on ? ' ✓' : '') + '</span></div>';
        }).join('')
        : '<div class="pl-tagac-empty">일치하는 태그 없음</div>';
    box.classList.add('open');
}
function plTagAcPick(dom, i) {
    var cfg = PL_TAG_DOMS[dom], t = plTagAcItems[i]; if (!cfg || !t) return;
    cfg.toggle(t.tag);                 // 칩 클릭과 동일(토글 + 재검색)
    var inp = document.getElementById(cfg.input); if (inp) inp.value = '';
    plTagAcClose(dom);
}
function plTagAcClose(dom) {
    var cfg = PL_TAG_DOMS[dom]; if (!cfg) return;
    var box = document.getElementById(cfg.box); if (box) box.classList.remove('open');
    plTagAcIdx = -1;
}
function plTagAcHi() {
    var cfg = PL_TAG_DOMS[plTagAcDom]; if (!cfg) return;
    var els = document.querySelectorAll('#' + cfg.box + ' .pl-tagac-item');
    for (var i = 0; i < els.length; i++) { var on = (i === plTagAcIdx); els[i].classList.toggle('active', on); if (on) els[i].scrollIntoView({ block: 'nearest' }); }
}
function plTagAcKey(e, dom) {
    var cfg = PL_TAG_DOMS[dom]; var box = document.getElementById(cfg.box);
    var open = box && box.classList.contains('open') && plTagAcItems.length > 0;
    if (e.key === 'ArrowDown' && open) { e.preventDefault(); plTagAcIdx = (plTagAcIdx + 1) % plTagAcItems.length; plTagAcHi(); }
    else if (e.key === 'ArrowUp' && open) { e.preventDefault(); plTagAcIdx = (plTagAcIdx - 1 + plTagAcItems.length) % plTagAcItems.length; plTagAcHi(); }
    else if (e.key === 'Enter') { if (open) { e.preventDefault(); plTagAcPick(dom, plTagAcIdx >= 0 ? plTagAcIdx : 0); } }
    else if (e.key === 'Escape') { plTagAcClose(dom); }
}
// ── 통합 필터 모델: 분류·지역·태그를 독립 축으로, 모든 변경을 plReload 하나로 ──────────
//  · 분류(plCatSel)    : 멀티선택. 브라우즈(뷰포트) 검색의 category IN.
//  · 지역(plRegionLock): 시도 잠금 시 공간 범위 = 그 시도, 아니면 뷰포트/전국.
//  · 태그/가이드/음식/달: 도메인(여행 or 맛집) 종속. 선택 시 그 도메인으로 '지역 스코프' 검색.
//  ★태그는 더 이상 분류 멀티선택을 파괴하지 않는다(도메인은 검색 쿼리에서만 강제). → 클릭 순서 무관.
function plChipActive() { return !!(plSelTags.length || plSelMonth || plSelFood.length || plSelGuide || PL_SUBBARS.stay.sel.length || PL_SUBBARS.camp.sel.length); }
function plSetDisp(id, on) { var el = document.getElementById(id); if (el) el.style.display = on ? '' : 'none'; }
// 칩 행 표시: 선택된 분류의 행을 보여줌(여행지=테마·달 / 맛집=가이드·음식 / 숙소·캠핑=유형).
//  ★각 분류 태그는 독립이라 여러 도메인 행이 동시에 켜질 수 있다(함께 표시·각자 좁힘).
function plChipContext() {
    var hasTravel = plCatSel.indexOf('travel') >= 0;
    var hasFood   = plCatSel.indexOf('restaurant') >= 0;
    var hasStay   = plCatSel.indexOf('stay') >= 0;
    var hasCamp   = plCatSel.indexOf('camping') >= 0;
    var tb = document.getElementById('tbChips'),   fb = document.getElementById('foodChips');
    var sb = document.getElementById('stayChips'), cb = document.getElementById('campChips');
    plSetDisp('pl-tagbar',   hasTravel && !!(tb && tb.innerHTML));
    plSetDisp('pl-monthbar', hasTravel);
    plSetDisp('pl-foodbar',  hasFood && !!(fb && fb.innerHTML));
    plSetDisp('pl-staybar',  hasStay && !!(sb && sb.innerHTML));
    plSetDisp('pl-campbar',  hasCamp && !!(cb && cb.innerHTML));
}
// ★통합 디스패처 — 모든 필터 축(분류·지역·태그)이 이 하나만 호출. 클릭 순서와 무관하게 동일 결과.
function plReload() {
    plChipContext();              // 분류에 맞춰 칩 행 동기화
    if (!plReady) return;
    plSearchHere();               // 통합 로더가 분류·지역·여행태그·맛집태그를 한 번에 적용
}
// 2행 렌더 (방문하기 좋은 달 1~12)
function plMonthBarRender() {
    var box = document.getElementById('mbChips');
    if (!box) return;
    var html = '';
    for (var i = 1; i <= 12; i++) {
        var onM = (plSelMonth === (i + '월')) ? ' active' : '';
        html += '<button class="mb-chip' + onM + '" onclick="plMonthClick(' + i + ')">' + i + '월</button>';
    }
    box.innerHTML = html;
}
// 3행 렌더 (맛집: 가이드칩 + 음식종류·등급칩) — 가이드는 항상, 음식칩은 상위 N + 더보기로 화면 안에 가둠
function plFoodBarRender() {
    var box = document.getElementById('foodChips');
    var more = document.getElementById('foodMore');
    var bar = document.getElementById('pl-foodbar');
    if (!box) return;
    var guides = plBarGuides.map(function (g) {
        var d = PL_GUIDES[g.guide]; if (!d) return '';
        var on = (plSelGuide === g.guide) ? ' active' : '';
        return '<button class="tb-chip guide' + on + '" style="--gc:' + d.color + '" onclick="plGuideClick(\'' +
            g.guide + '\')">' + plEsc(d.ko) + '<span class="tb-cnt">' + g.cnt + '</span></button>';
    }).join('');
    // 선택된 음식칩은 접혀도 보이도록 상위 N 과 합집합
    var flist = plFoodExpanded ? plBarCuisines : plBarCuisines.filter(function (t, i) {
        return i < PL_FOODBAR_TOP || plSelFood.indexOf(t.tag) >= 0;
    });
    var foods = flist.map(function (t) {
        var tg = t.tag.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
        var on = (plSelFood.indexOf(t.tag) >= 0) ? ' active' : '';
        var gk = (t.kind === 'grade') ? ' grade' : '';   // 등급 태그는 색으로 구분
        return '<button class="mb-chip food' + gk + on + '" onclick="plFoodClick(\'' + tg + '\')">' +
            plEsc(t.tag) + '<span class="tb-cnt">' + t.cnt + '</span></button>';
    }).join('');
    box.innerHTML = guides + foods;
    var hidden = plBarCuisines.length - PL_FOODBAR_TOP;
    if (more) {
        if (hidden > 0) { more.style.display = ''; more.textContent = plFoodExpanded ? '접기' : ('+' + hidden + ' 더보기'); }
        else more.style.display = 'none';
    }
    plChipContext();   // 분류·내용에 맞춰 맛집 칩 행 표시/숨김
}
function plFoodToggleMore() { plFoodExpanded = !plFoodExpanded; plFoodBarRender(); }
function plMonthClick(i) {
    var tag = i + '월';
    plSelMonth = (plSelMonth === tag) ? null : tag;   // 재클릭=해제
    plMonthBarRender();
    plReload();
}
// 1행 렌더 (여행/숙소/기타 테마칩 — 최대 14개 + 더보기)
function plTagBarRender() {
    var box = document.getElementById('tbChips');
    // top10 ∪ 선택된 태그(검색으로 고른 희귀 태그도 활성 칩으로 보이게). 나머지는 태그 검색창으로.
    var list = plTagBarAll.filter(function (t, i) { return i < PL_TAGBAR_TOP || plSelTags.indexOf(t.tag) >= 0; });
    box.innerHTML = list.map(function (t) {
        var tg = t.tag.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
        var on = (plSelTags.indexOf(t.tag) >= 0) ? ' active' : '';
        return '<button class="tb-chip' + on + '" onclick="plTagBarClick(\'' + tg + '\')">' +
            plEsc(t.tag) + '<span class="tb-cnt">' + t.cnt + '</span></button>';
    }).join('');
    plChipContext();   // 분류에 맞춰 칩 행 표시/숨김
}
// 가이드 칩 클릭 = 단일 선택(재클릭 해제)
function plGuideClick(g) {
    plSelGuide = (plSelGuide === g) ? null : g;
    plFoodBarRender();
    plReload();
}
// 여행 테마칩 클릭 = 다중 AND 선택(재클릭 해제)
function plTagBarClick(tag) {
    var i = plSelTags.indexOf(tag);
    if (i >= 0) { plSelTags.splice(i, 1); }            // 재클릭=해제
    else {
        if (plSelTags.length >= PL_TAG_MAX_TRAVEL) { plHint('여행 태그는 최대 ' + PL_TAG_MAX_TRAVEL + '개까지 선택할 수 있습니다'); return; }
        plSelTags.push(tag);                           // 추가(최대 2개 AND)
    }
    plTagBarRender();
    plReload();
}
// 맛집 음식종류·등급칩 클릭 = 다중 AND 선택(재클릭 해제)
function plFoodClick(tag) {
    var i = plSelFood.indexOf(tag);
    if (i >= 0) { plSelFood.splice(i, 1); }            // 재클릭=해제
    else if (PL_TAG_MAX_SUB === 1) { plSelFood = [tag]; }   // 1개 제한=기존 해제 후 교체
    else {
        if (plSelFood.length >= PL_TAG_MAX_SUB) { plHint('맛집 태그는 최대 ' + PL_TAG_MAX_SUB + '개까지 선택할 수 있습니다'); return; }
        plSelFood.push(tag);
    }
    plFoodBarRender();
    plReload();
}
function plClearTagSel() {
    plSelTags = []; plSelMonth = null; plSelGuide = null; plSelFood = [];
    PL_SUBBARS.stay.sel = []; PL_SUBBARS.camp.sel = [];
    plTagBarRender(); plMonthBarRender(); plFoodBarRender(); plSubRender('stay'); plSubRender('camp');
}
// 현재 선택된 태그·가이드 라벨(안내용). 없으면 ''.
function plFilterLabel() {
    var parts = [];
    if (plSelGuide && PL_GUIDES[plSelGuide]) parts.push(PL_GUIDES[plSelGuide].ko);
    parts = parts.concat(plSelTags).concat(plSelFood).concat(PL_SUBBARS.stay.sel).concat(PL_SUBBARS.camp.sel);
    if (plSelMonth) parts.push(plSelMonth);
    return parts.length ? ('🏷 ' + parts.join(' · ')) : '';
}
// 분류별로 묶고(여행지→맛집→숙소→캠핑→기타), 그 안에서 품질순(맛집=리뷰순)·거리순.
//  → 마커 번호·좌측 리스트가 분류 섹션 단위로 연속(범위를 좁혀가도 헤매지 않게).
var PL_CAT_ORDER = { travel: 0, restaurant: 1, stay: 2, camping: 3, etc: 4 };
function plSortFeatures(feats) {
    var rev = function (f) {
        var nv = f.properties && f.properties.attributes && f.properties.attributes.naver;
        return (nv && nv.review != null) ? Number(nv.review) : null;
    };
    feats.sort(function (a, b) {
        var ca = PL_CAT_ORDER[a.properties.category]; if (ca == null) ca = 9;
        var cb = PL_CAT_ORDER[b.properties.category]; if (cb == null) cb = 9;
        if (ca !== cb) return ca - cb;                      // 분류 묶음 우선
        var ra = rev(a), rb = rev(b);
        if (ra != null && rb != null) return rb - ra;       // 둘 다 리뷰: 많은 순
        if (ra != null) return -1;                          // 리뷰 있는 쪽 먼저
        if (rb != null) return 1;
        var da = a.properties.dist_km != null ? a.properties.dist_km : 9999;
        var db = b.properties.dist_km != null ? b.properties.dist_km : 9999;
        return da - db;                                     // 둘 다 리뷰 없음: 거리순
    });
    return feats;
}

function plFitToFeatures(feats) {
    if (!feats.length) return;
    var c0 = feats[0].geometry.coordinates;
    var b = new naver.maps.LatLngBounds(
        new naver.maps.LatLng(c0[1], c0[0]), new naver.maps.LatLng(c0[1], c0[0]));
    feats.forEach(function (f) { var co = f.geometry.coordinates; b.extend(new naver.maps.LatLng(co[1], co[0])); });
    try { plMap.fitBounds(b, { top: 70, right: 50, bottom: 50, left: 50 }); }
    catch (e) { plMap.fitBounds(b); }
}

function plClearMarkers() {
    plMarkers.forEach(function (m) { m.setMap(null); });
    plMarkers = [];
    plClearLabels();
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
    plSearch(c.lat(), c.lng(), plViewportRadiusKm(), true);   // 뷰포트 반경 + 상위 N개
}

// 사다리에서 cur 보다 큰 다음 반경 (없으면 null)
function plNextRadius(cur) {
    for (var i = 0; i < PL_RADII.length; i++) { if (PL_RADII[i] > cur) return PL_RADII[i]; }
    return null;
}

// 줌 티어링: 넓게(줌아웃) 볼수록 리뷰 많은 맛집만 노출(여행지·숙소·가이드 맛집은 항상 표시).
//  네이버 줌: 작을수록 광역. 줌아웃=리뷰 최상위만 / 줌인=전체.
//  (서버 searchNearby 가 restaurant 에만 적용·LIMIT 도 리뷰순이라 '좋은 곳'이 남음)
// 분류별 줌 티어링 기준 — 스테이·캠핑은 리뷰가 적어 낮은 단계.
function plZoomTier(cat, z) {
    z = z || (plMap ? plMap.getZoom() : 12);
    if (cat === 'stay' || cat === 'camping') {
        if (z <= 9)  return 500;
        if (z <= 11) return 200;
        if (z <= 13) return 50;
        return 0;
    }
    if (z <= 9)  return 10000;
    if (z <= 11) return 5000;
    if (z <= 13) return 2000;
    return 0;
}

// 추이 페이지에서 저장한 분류별 지도 기준(localStorage 'pl_map_filter' = {restaurant,stay,camping}).
var plCatFilter = null;
function plLoadCatFilter() {
    try { var o = JSON.parse(localStorage.getItem('pl_map_filter') || 'null'); plCatFilter = (o && typeof o === 'object') ? o : null; }
    catch (e) { plCatFilter = null; }
}
// 분류별 효과 기준: 저장값 우선, 없으면 줌 티어링 기본.
function plEffCatMr() {
    var f = plCatFilter || {};
    return {
        restaurant: (f.restaurant != null) ? f.restaurant : plZoomTier('restaurant'),
        stay:       (f.stay       != null) ? f.stay       : plZoomTier('stay'),
        camping:    (f.camping    != null) ? f.camping    : plZoomTier('camping')
    };
}
// 단일 분류의 효과 기준
function plCatMr(cat) {
    var f = plCatFilter || {};
    return (f[cat] != null) ? f[cat] : plZoomTier(cat);
}
// 현재 표시 기준 요약(지도 하단 안내용)
function plCritSummary() {
    function t(n){ return n > 0 ? Number(n).toLocaleString() + '+' : '전체'; }
    var cat = (plCatSel.length === 1) ? plCatSel[0] : '';   // 단일 분류일 때만 그 기준, 그외=종합
    if (cat === '') {
        var e = plEffCatMr();
        return '맛집 ' + t(e.restaurant) + ' · 스테이 ' + t(e.stay) + ' · 캠핑 ' + t(e.camping);
    }
    var ko = { travel:'여행지', restaurant:'맛집', stay:'스테이', camping:'캠핑장', etc:'기타' }[cat] || cat;
    return ko + ' ' + t(plCatMr(cat));
}
var PL_MAP_LIMIT = 800;   // 렌더 안전 한도(과밀·성능 방지) — 사용자 비노출
// 현재 화면(뷰포트) 반경 km = 중심→북동 모서리. 보이는 영역을 덮는 반경(전국 보면 전국).
function plViewportRadiusKm() {
    if (!plMap) return parseFloat(document.getElementById('radius').value) || 5;
    try {
        var b = plMap.getBounds(), c = plMap.getCenter(), ne = b.getNE();
        return Math.max(0.3, Math.min(500, rtHaversine(c.lat(), c.lng(), ne.lat(), ne.lng())));
    } catch (e) {
        return parseFloat(document.getElementById('radius').value) || 5;
    }
}

var plLastMode = '';   // 직전 검색 공간모드(전국 진입 시 1회만 fitBounds 하기 위함)
// ★통합 로더 — 분류·지역·여행태그·맛집태그를 한 번에 보냄. 공간 모드는 현재 상태로 결정:
//   지역잠금 → 'region'(그 시도) / 칩 활성·지역없음 → 'nation'(전국) / 그 외 → 'viewport'(이 화면).
//  결과 = 선택 분류들의 합집합. 각 도메인은 자기 태그로만 좁힘(서로·분류 초기화 없음).
//  expandFrom 숫자면 그 반경(뷰포트 0건시 자동확장). viewport=true 면 화면반경·확장 안 함.
function plSearch(lat, lng, expandFrom, viewport) {
    if (typeof rtMode !== 'undefined' && rtMode && !rtFinalized) return;   // 주변 회랑 '가동 중'에만 베이스 검색 정지. 미가동(rtFinalized=진입/스캔 전)이면 필터로 베이스 마커 갱신 허용.
    plClearOverlay();                      // 베이스 재검색 → 주변 오버레이 해제
    plMergeSel = [];                                       // 새 검색 시 병합 선택 초기화
    plLastSearchAt = Date.now();                           // idle 자동검색 중복 방지용 타임스탬프
    if (plMap) plLastSearchZoom = plMap.getZoom();         // 줌 변경 감지 기준 갱신
    if (!plCatSel.length) {     // 분류 전부 해제 = 표시 안 함
        plClearMarkers(); plActive = -1; plFeatures = [];
        plRenderList([]); plToggleList(false);
        plHint('표시할 분류를 선택하세요'); return;
    }
    var keepId = (plActive >= 0 && plFeatures[plActive]) ? plFeatures[plActive].properties.id : null;  // 재검색 후 포커스 유지용
    var mode = plRegionLock ? 'region' : (plChipActive() ? 'nation' : 'viewport');
    var rad = (expandFrom != null) ? expandFrom : parseFloat(document.getElementById('radius').value);
    var sp = { module: 'place', action: 'search', lat: lat, lng: lng, radius: rad, limit: PL_MAP_LIMIT,
               categories: plCatSel.join(',') };
    if (mode === 'region')      sp.region = plRegionLock.join(',');
    else if (mode === 'nation') sp.scope  = 'nation';
    else { var eff = plEffCatMr(); sp.mr_restaurant = eff.restaurant; sp.mr_stay = eff.stay; sp.mr_camping = eff.camping; }
    // 도메인별 태그(독립): 여행지=테마+달 / 맛집=음식·등급 / 가이드
    // ★태그 구분자는 줄바꿈(\n) — 태그값 자체에 콤마가 들어있어("카페,디저트") 콤마로 구분하면 분리 오류
    var travelTags = plSelTags.slice(); if (plSelMonth) travelTags.push(plSelMonth);
    if (travelTags.length) sp.travel_tags = travelTags.join('\n');
    if (plSelFood.length)  sp.food_tags   = plSelFood.join('\n');
    if (plSelGuide)        sp.guide       = plSelGuide;
    if (PL_SUBBARS.stay.sel.length) sp.stay_tags    = PL_SUBBARS.stay.sel.join('\n');
    if (PL_SUBBARS.camp.sel.length) sp.camping_tags = PL_SUBBARS.camp.sel.join('\n');
    fetch(plApiUrl(sp))
        .then(function (r) { return r.json(); })
        .then(function (geo) {
            var feats = (geo && geo.features) || [];
            // 뷰포트 자동 확장: 결과 0곳이고 더 넓힐 수 있으면 다음 반경으로
            if (!feats.length && mode === 'viewport' && expandFrom != null && !viewport) {
                var nxt = plNextRadius(rad);
                if (nxt) {
                    document.getElementById('radius').value = String(nxt);
                    plMap.setZoom(plZoomForRadius(nxt));
                    plHint(rad + 'km에 없음 → ' + nxt + 'km로 확장 검색…');
                    plSearch(lat, lng, nxt);
                    return;
                }
            }
            feats = plSortFeatures(feats);   // 분류별 묶음 + 맛집 리뷰순
            // ★표시 번호 = 분류 내 순위(1부터). 마커·리스트가 같은 번호 사용(맛집 1위=1).
            //  내부 인덱스(DOM id·plFocus·plMarkers)는 전역 그대로 두어 클릭 연결을 유지.
            var rank = {};
            feats.forEach(function (f) { var c = f.properties.category || 'etc'; rank[c] = (rank[c] || 0) + 1; f.properties._n = rank[c]; });
            plClearMarkers();
            plActive = -1;
            plFeatures = feats;
            feats.forEach(plAddMarker);   // (f, idx) — idx=전역, 표시번호는 properties._n
            plUpdateLabels();             // 줌인 상태면 마커 옆 라벨(순위·이름·리뷰) 표시
            plRenderList(feats);
            plRefreshCurrent = function () { plSearchHere(); };   // 수정 후 목록 갱신(현 필터 재적용)
            plToggleList(feats.length > 0);
            if (mode === 'viewport' && expandFrom != null && !viewport) document.getElementById('radius').value = String(rad);
            // ★지도 자동이동 최소화: '전국 태그검색 첫 진입' 때만 결과에 맞춤.
            //  지역(시도)·뷰포트·필터 추가는 보던 화면을 유지(보던 장소가 사라지지 않게).
            var enteredNation = (mode === 'nation' && plLastMode !== 'nation');
            plLastMode = mode;
            // 경로 모드(엔진 미가동 베이스 브라우징)에선 자동 줌아웃 안 함 — 경로 시야 유지, 마커만 갱신
            if (enteredNation && feats.length && !(typeof rtMode !== 'undefined' && rtMode)) plFitToFeatures(feats);
            // 재검색 후에도 보고 있던 장소를 계속 강조(지도 이동 없이)
            if (plPendingFocusId == null && keepId != null) plRehighlight(keepId);
            // 안내 라벨: {범위} · {태그} N곳
            var scopeLbl = (mode === 'region') ? (plActiveSido || '지역') : (mode === 'nation') ? '전국' : '이 화면';
            var filt = plFilterLabel();
            var titleTxt = scopeLbl + (filt ? ' · ' + filt : '') + ' ' + feats.length + '곳';
            document.getElementById('plListTitle').textContent = titleTxt;
            plHint(feats.length ? (titleTxt + ' · ' + plCritSummary())
                                : (scopeLbl + (filt ? ' · ' + filt : '') + ' · 표시할 곳 없음'));
        })
        .catch(function () { plHint('검색 실패'); });
}

function plAddMarker(f, idx) {
    var pr = f.properties, co = f.geometry.coordinates; // [lng, lat]
    var n = pr._n || (idx + 1);   // 분류 내 순위(표시 번호)
    var marker = new naver.maps.Marker({
        position: new naver.maps.LatLng(co[1], co[0]),
        map: plMap,
        title: n + '. ' + pr.name,
        zIndex: 100,
        icon: plMarkerIcon(pr.category, n, false, plMkColor(pr), plMkBadge(pr))
    });
    naver.maps.Event.addListener(marker, 'click', function () { plFocus(idx); });
    plMarkers.push(marker);
}

// 포크·나이프 SVG(흰색) — 맛집 마커용
var PL_FORK_SVG = '<svg class="mk-fk" viewBox="0 0 24 24"><path d="M8.1 2v6.5c0 .8-.7 1.5-1.5 1.5S5 9.3 5 8.5V2H3.5v6.5C3.5 10.4 5 12 6.6 12v10h1.5V12c1.6 0 3.1-1.6 3.1-3.5V2H8.1zM16.5 2c-1.7 0-3 2-3 4.5 0 2.2 1 4 2.3 4.4V22h1.5V2h-.8z"/></svg>';
// 리본(메달) SVG — 블루리본 등급용
var PL_RIBBON_SVG = '<svg class="mk-rb" viewBox="0 0 24 24"><circle cx="12" cy="8" r="6"/><path d="M8.6 12.7L6 22l3-1.6L12 22l-3.4-9.3zm6.8 0L12 22l3-1.6L18 22l-2.6-9.3z"/></svg>';
// 침대 SVG(흰색) — 숙소 마커용
var PL_BED_SVG = '<svg class="mk-fk" viewBox="0 0 24 24"><path d="M4 8c-1.1 0-2 .9-2 2v3h1.2l.5 2h1.5l-.5-2H19.3l-.5 2h1.5l.5-2H22v-3c0-1.7-1.3-3-3-3H4zm2 1.5h3.6c.5 0 1 .4 1 1V12H5v-1.5c0-.6.4-1 1-1zm6.4 0H19c.6 0 1 .4 1 1V12h-7.6v-1.5c0-.6.4-1 1-1z"/></svg>';
// 텐트 SVG(흰색) — 캠핑장 마커용
var PL_TENT_SVG = '<svg class="mk-fk" viewBox="0 0 24 24" fill-rule="evenodd"><path d="M12 3L1 20h22L12 3zm0 4.5l6.6 10.5h-4.4L12 14.2 9.8 18H5.4L12 7.5z"/></svg>';
// 마커 아이콘 HTML
//  맛집       = 가이드색 원형 + 흰 포크·나이프 + 우상단 숫자 배지(여행지와 모양부터 다름)
//  숙소·캠핑  = 분류색 원형 + 흰 침대/텐트 아이콘(맛집과 같은 원형 계열, 분류로 구분)
//  그 외 분류 = 흰바탕 물방울 핀 + 색테두리 + 빨강 숫자(active 시 빨강 강조)
function plMarkerIcon(cat, num, active, color, gradeHtml) {
    if (cat === 'restaurant') {
        var bg = color || '#e74c3c';
        var numB = (num !== '' && num != null) ? '<b class="mk-fnum">' + num + '</b>' : '';   // 우상단 번호(리스트와 1:1)
        var grB  = gradeHtml ? '<b class="mk-fgrade">' + gradeHtml + '</b>' : '';              // 우하단 가이드 등급
        return {
            content: '<div class="mk-food' + (active ? ' active' : '') + '" style="background:' + bg + '">' +
                PL_FORK_SVG + numB + grB + '</div>',
            anchor: new naver.maps.Point(15, 15)
        };
    }
    if (cat === 'stay' || cat === 'camping') {
        return {
            content: '<div class="mk-place cat-' + cat + (active ? ' active' : '') + '">' +
                (cat === 'camping' ? PL_TENT_SVG : PL_BED_SVG) + '</div>',
            anchor: new naver.maps.Point(15, 15)
        };
    }
    var c = cat || 'etc';
    var st = (color && !active) ? ' style="border-color:' + color + '"' : '';
    return {
        content: '<div class="mk-pin cat-' + c + (active ? ' active' : '') + '"' + st + '><b>' + num + '</b></div>',
        anchor: new naver.maps.Point(14, 28)
    };
}

// 리스트 항목 1개 HTML (베이스 목록 plFocus / 주변 오버레이 목록 plProxPick 공용)
//  opts = { prefix, onclickFn, num(번호표시 여부) }
function plLiHtml(f, i, opts) {
    var pr = f.properties, c = pr.category || 'etc';
    var dist = (pr.dist_km != null) ? '<span class="li-dist">~' + plFmtDist(pr.dist_km) + '</span>' : '';
    var refs = (pr.ref_count > 0)
        ? '<span class="li-refs" title="연결된 기사 ' + pr.ref_count + '건">' + pr.ref_count + '</span>' : '';
    var gb = plGuideBadges(pr);                              // 맛집 가이드 배지(등급글리프 포함)
    var gbLine = gb ? '<div class="li-guides">' + gb + '</div>' : '';
    var nvLine = plNaverLine(pr);                            // 네이버 평점·리뷰
    var sub = (pr.tags && pr.tags.length)
        ? '<div class="li-tags">' + pr.tags.slice(0, 6).map(function (t) {
              return '<span class="li-tag">#' + plEsc(t) + '</span>'; }).join('') + '</div>'
        : (gb ? '' : '<div class="li-sub">' + plEsc(CAT_KO[c] || '기타') + '</div>');
    var edit = (!PL_SHARE && pr.id) ? '<button class="li-edit" title="수정" onclick="event.stopPropagation();plEditOpen(' + pr.id + ',\'list\')">✏️</button>' : '';
    var selCls = (pr.id && plMergeSel.indexOf(pr.id) >= 0) ? ' mc-sel' : '';
    var noClick = (!PL_SHARE && pr.id) ? ' onclick="plNoClick(event,' + pr.id + ')"' : '';
    var label = opts.num ? (pr._n || (i + 1)) : '';          // 베이스=분류 내 순위 / 주변=빈 색원
    // 🤖 Claude 요약 칩 — 요약(attributes.summary) 있으면 표시, 클릭 시 항목 클릭과 분리해 요약 모달만 연다.
    var aiChip = (pr.attributes && pr.attributes.summary)
        ? '<button type="button" class="li-ai-chip" title="Claude 요약 보기" onclick="event.stopPropagation();plShowAiSummaryFrom(\'' +
              (opts.onclickFn === 'plProxPick' ? 'ov' : 'base') + '\',' + i + ')">🤖</button>'
        : '';
    return '<div class="pl-li' + selCls + '" id="' + opts.prefix + i + '" data-pid="' + (pr.id || 0) + '" onclick="' + opts.onclickFn + '(' + i + ')">' +
        '<span class="li-no cat-' + c + '"' + noClick + '>' + label + '</span>' +
        '<div class="li-body">' +
            '<div class="li-name"><span class="nm">' + plEsc(pr.name) + '</span>' + refs + aiChip +
                '<span class="li-meta">' + dist + '</span></div>' +
            gbLine + nvLine + sub +
        '</div>' + edit + '</div>';
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
    title.textContent = '이 화면 ' + feats.length + '곳';   // plSearch가 모드별 라벨로 덮어씀
    // ★분류별 섹션으로 렌더(여행지/맛집/숙소/캠핑/기타 따로). feats 가 분류순 정렬이라 인덱스(=번호)도 섹션 내 연속.
    var secHtml = '';
    ['travel', 'restaurant', 'stay', 'camping', 'etc'].forEach(function (c) {
        var items = [];
        feats.forEach(function (f, i) {
            if ((f.properties.category || 'etc') === c) items.push(plLiHtml(f, i, { prefix: 'pl-li-', onclickFn: 'plFocus', num: true }));
        });
        if (!items.length) return;
        secHtml += '<div class="pl-sec"><div class="pl-sec-hd cat-' + c + '">' +
            '<i class="dot ' + c + '"></i>' + (CAT_KO[c] || c) + ' <b>' + items.length + '</b>곳</div>' +
            items.join('') + '</div>';
    });
    body.innerHTML = secHtml;
    // 통합검색에서 우리 DB 장소를 골랐으면, 그 마커를 강조 + 상세패널 표시
    if (plPendingFocusId != null) {
        var pfFound = false;
        for (var k = 0; k < feats.length; k++) {
            if (feats[k].properties && feats[k].properties.id == plPendingFocusId) { plFocus(k); pfFound = true; break; }
        }
        var pfId = plPendingFocusId;
        plPendingFocusId = null;
        // 리뷰 큐레이션 필터에 걸려 결과에 없으면 → 그 장소만 강제로 가져와 표시(검색한 곳은 항상 보이게)
        if (!pfFound) plForceShowPlace(pfId);
    }
    plMergeHeadRender();   // 상단 병합 버튼 상태 동기화 (행 mc-sel 은 렌더 시 반영됨)
    // 주변 회랑 가동 중(!rtFinalized)에만 새 베이스 마커를 숨김. 미가동(진입/스캔 전)이면 필터 검색 결과를 그대로 보여줌.
    if (typeof rtMode !== 'undefined' && rtMode && !rtFinalized) rtHideBaseMarkers();
}

// 거리(km) 표기: 1km 미만은 m, 그 이상은 소수1자리 km
function plFmtDist(km) {
    km = parseFloat(km);
    if (isNaN(km)) return '';
    if (km < 1) return Math.round(km * 1000) + 'm';
    return (Math.round(km * 10) / 10) + 'km';
}

function plToggleList(show) {
    if (typeof rtMode !== 'undefined' && rtMode) show = false;  // 경로 모드에선 좌측이 '주변 종합' 패널이라 검색 목록은 닫음
    document.getElementById('pl-list').classList.toggle('open', show);
    setTimeout(plBumpResize, 60); // 지도 폭 변동 → 회색 타일 방지
}

// 편집 모드 토글: 켰을 때만 리스트 항목에 ✏️ 수정 연필 + ✓ 병합 선택이 보인다(기본 숨김)
function plToggleEdit() {
    var p = document.getElementById('pl-list');
    var on = !p.classList.contains('edit-on');
    p.classList.toggle('edit-on', on);
    var b = document.getElementById('plEditToggle');
    if (b) { b.classList.toggle('active', on); b.textContent = on ? '✏️ 편집 중' : '✏️ 편집'; }
    if (!on) plMergeClearSel();   // 편집 끄면 병합 선택 해제
    else plMergeHeadRender();
}

// ── 중복 장소 병합: 편집모드에서 ✓로 2곳+ 선택 → 대표 골라 합치기 ──
function plFeatById(id) {
    for (var i = 0; i < plFeatures.length; i++) { if (plFeatures[i].properties && plFeatures[i].properties.id == id) return plFeatures[i].properties; }
    return null;
}
// 편집모드에서만 원+숫자 클릭 = 병합 선택 토글(그 외엔 행 클릭(plFocus)에 맡김)
function plNoClick(e, id) {
    var list = document.getElementById('pl-list');
    if (!list.classList.contains('edit-on')) return;   // 일반 모드: 버블링 → plFocus
    e.stopPropagation();
    plMergeToggle(id);
}
function plMergeToggle(id) {
    var i = plMergeSel.indexOf(id);
    if (i >= 0) plMergeSel.splice(i, 1); else plMergeSel.push(id);
    plMergeSyncRows();
    plMergeHeadRender();
}
function plMergeSyncRows() {   // 선택 상태를 행 배경(mc-sel)에 반영
    var rows = document.querySelectorAll('#plListBody .pl-li');
    for (var i = 0; i < rows.length; i++) {
        var pid = parseInt(rows[i].getAttribute('data-pid'), 10);
        rows[i].classList.toggle('mc-sel', plMergeSel.indexOf(pid) >= 0);
    }
}
function plMergeHeadRender() {   // 상단 '병합 N' 버튼 (2곳 이상일 때만)
    var b = document.getElementById('plMergeBtn');
    if (!b) return;
    var n = plMergeSel.length;
    if (n >= 2) { b.style.display = ''; b.textContent = '🔀 병합 ' + n; }
    else { b.style.display = 'none'; b.textContent = '🔀 병합'; }
}
function plMergeClearSel() {
    plMergeSel = [];
    plMergeSyncRows();
    plMergeHeadRender();
}
function plMergeOpen() {
    if (plMergeSel.length < 2) return;
    var rows = plMergeSel.map(function (id) { return plFeatById(id); }).filter(Boolean);
    if (rows.length < 2) { alert('선택한 장소 정보를 찾을 수 없습니다. 다시 검색해 주세요.'); return; }
    // 기본 대표 = 기사 수 최다
    var mainId = rows.slice().sort(function (a, b) { return (b.ref_count || 0) - (a.ref_count || 0); })[0].id;
    document.getElementById('plMergePick').innerHTML = rows.map(function (p) {
        var reg = [p.region_lv1, p.region_lv2].filter(Boolean).join(' ');
        var ad = p.address || reg || '(주소 없음)';
        var checked = (p.id == mainId) ? ' checked' : '';
        var mainTag = (p.id == mainId) ? '<span class="pm-main-tag">대표</span>' : '';
        var refs = (p.ref_count > 0) ? '<span class="pm-refs">기사 ' + p.ref_count + '</span>' : '';
        return '<label class="pm-row' + (checked ? ' main' : '') + '">' +
            '<input type="radio" name="pmMain" value="' + p.id + '"' + checked + ' onchange="plMergePickMain(' + p.id + ')">' +
            '<div><div class="pm-nm">' + plEsc(p.name) + refs + mainTag + '</div>' +
            '<div class="pm-ad">' + plEsc(ad) + '</div></div></label>';
    }).join('');
    document.getElementById('pl-merge').classList.add('open');
}
function plMergePickMain(id) {   // 라디오 바뀌면 '대표' 강조 갱신
    var rows = document.querySelectorAll('#plMergePick .pm-row');
    for (var i = 0; i < rows.length; i++) {
        var r = rows[i], inp = r.querySelector('input'), on = (inp.value == id);
        r.classList.toggle('main', on);
        var nm = r.querySelector('.pm-nm'); var tag = r.querySelector('.pm-main-tag');
        if (on && !tag) nm.insertAdjacentHTML('beforeend', '<span class="pm-main-tag">대표</span>');
        if (!on && tag) tag.remove();
    }
}
function plMergeClose() { document.getElementById('pl-merge').classList.remove('open'); }
function plMergeApply() {
    var sel = document.querySelector('#plMergePick input[name=pmMain]:checked');
    if (!sel) return;
    var mainId = parseInt(sel.value, 10);
    var others = plMergeSel.filter(function (id) { return id != mainId; });
    if (!others.length) { plMergeClose(); return; }
    fetch(plApiUrl({ module: 'place', action: 'place_merge', to_id: mainId, from_ids: others.join(',') }))
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.ok) { alert((d && d.msg) || '병합 실패'); return; }
            plMergeClose();
            plMergeSel = [];
            plHint((d.merged || others.length) + '곳을 대표로 병합했습니다');
            if (typeof plRefreshCurrent === 'function') plRefreshCurrent();   // 목록·마커 갱신
        })
        .catch(function () { alert('병합 실패'); });
}

// 리스트/마커 클릭 → 지도 이동 + 양쪽 강조 + 상세패널
//  (경로 만들기 모드에서는 상세패널 대신 그 장소를 경로에 추가)
function plFocus(idx) {
    var f = plFeatures[idx]; if (!f) return;
    if (typeof rtMode !== 'undefined' && rtMode) { rtAddFromFeature(f); return; }
    var co = f.geometry.coordinates;

    // 이전 active 마커 원복
    if (plActive >= 0 && plMarkers[plActive] && plFeatures[plActive]) {
        var op = plFeatures[plActive].properties;
        plMarkers[plActive].setIcon(plMarkerIcon(op.category, op._n || (plActive + 1), false, plMkColor(op), plMkBadge(op)));
    }
    // 새 마커 강조
    if (plMarkers[idx]) plMarkers[idx].setIcon(plMarkerIcon(f.properties.category, f.properties._n || (idx + 1), true, plMkColor(f.properties), plMkBadge(f.properties)));
    plActive = idx;

    // 리스트 항목 강조 + 스크롤
    var items = document.querySelectorAll('.pl-li');
    for (var i = 0; i < items.length; i++) items[i].classList.remove('active');
    var li = document.getElementById('pl-li-' + idx);
    if (li) { li.classList.add('active'); li.scrollIntoView({ block: 'nearest' }); }

    // 좌표는 geometry 에만 있고 properties 엔 없음 → 패널 버튼·주변검색용으로 주입
    f.properties.lat = co[1];
    f.properties.lng = co[0];
    plOpenById(f.properties.id, co[1], co[0], f.properties);   // 단일 경로 — 서버 최신정보로 열기(캐시 fallback)

    // 선택 → 반경 3km 지도로 포커스(클릭한 곳이 중심).
    //  단, 이미 그보다 더 확대(줌인)된 상태면 줌아웃하지 않고 현재 줌 유지 + 중심만 이동.
    //  (포커스 목표 줌 ≤ 현재 줌 이면 자동 줌아웃이 일어나므로 panTo 만)
    if (plMap.getZoom() < plFitZoom(co[1], 3)) plFitRadius(co[1], co[0], 3);   // 더 넓게 보던 중 → 줌인 포커스
    else plMap.panTo(new naver.maps.LatLng(co[1], co[0]));                      // 이미 더 확대 → 줌 유지, 중심만
}

// 재검색 후, 보고 있던 장소(id)를 지도 이동 없이 다시 강조(마커+리스트 active, 리스트 스크롤).
function plRehighlight(id) {
    for (var k = 0; k < plFeatures.length; k++) {
        var pr = plFeatures[k].properties;
        if (pr && pr.id == id) {
            if (plActive >= 0 && plMarkers[plActive] && plFeatures[plActive]) {   // 이전 강조 원복
                var op = plFeatures[plActive].properties;
                plMarkers[plActive].setIcon(plMarkerIcon(op.category, op._n || (plActive + 1), false, plMkColor(op), plMkBadge(op)));
            }
            plActive = k;
            if (plMarkers[k]) plMarkers[k].setIcon(plMarkerIcon(pr.category, pr._n || (k + 1), true, plMkColor(pr), plMkBadge(pr)));
            var li = document.getElementById('pl-li-' + k);
            if (li) { li.classList.add('active'); li.scrollIntoView({ block: 'nearest' }); }
            return;
        }
    }
    plActive = -1;   // 결과에서 사라졌으면 강조 해제
}

// 통합검색으로 지목한 장소가 리뷰 큐레이션 필터에 걸려 검색 결과에 없을 때,
// 그 장소만 단건 조회해 마커+리스트에 강제 추가하고 포커스한다(검색한 곳은 항상 보이도록).
function plForceShowPlace(id) {
    if (!id) return;
    fetch(plApiUrl({ module: 'place', action: 'place_one', id: id }))
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (!j || !j.ok || !j.feature) return;
            for (var k = 0; k < plFeatures.length; k++) {   // 이미 있으면(경합) 그냥 포커스
                if (plFeatures[k].properties && plFeatures[k].properties.id == id) { plFocus(k); return; }
            }
            plFeatures.push(j.feature);
            plFeatures = plSortFeatures(plFeatures);
            var rank = {};   // 분류 내 순위 재부여(마커·리스트 번호 일관)
            plFeatures.forEach(function (f) { var c = f.properties.category || 'etc'; rank[c] = (rank[c] || 0) + 1; f.properties._n = rank[c]; });
            plClearMarkers();
            plFeatures.forEach(plAddMarker);
            plUpdateLabels();
            plRenderList(plFeatures);
            plToggleList(true);
            for (var m = 0; m < plFeatures.length; m++) {
                if (plFeatures[m].properties && plFeatures[m].properties.id == id) { plFocus(m); break; }
            }
        })
        .catch(function () {});
}

var CAT_KO = { travel: '여행지', stay: '숙소', restaurant: '맛집', camping: '캠핑장', etc: '기타' };

// ── 상세패널을 여는 단일 경로 ───────────────────────────────
// 어느 진입점(지도 마커·주변 오버레이·경로 번호핀·⭐찜)에서 열어도 id로 서버 최신정보(place_one)를
// 조회해 동일하게 연다 → 캐시(특히 localStorage 저장 찜)의 staleness로 요약·태그·네이버가 누락되는
// 문제를 원천 차단(평행 구현 금지). 못 받으면(id 없음·오류) fallback(호출부가 가진 객체)으로 연다.
function plOpenById(id, lat, lng, fallback) {
    if (id == null) { if (fallback) plOpenPanel(fallback); return; }
    fetch(plApiUrl({ module: 'place', action: 'place_one', id: id }))
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d && d.ok && d.feature && d.feature.properties) {
                var pr = d.feature.properties;
                if (lat != null) pr.lat = lat;   // 패널 버튼·네이버검색·주변검색용 좌표 주입
                if (lng != null) pr.lng = lng;
                plOpenPanel(pr);
            } else if (fallback) { plOpenPanel(fallback); }
        })
        .catch(function () { if (fallback) plOpenPanel(fallback); });
}

var plPanelPlace = null;   // 현재 상세패널에 띄운 장소(경로 추가 버튼용)
function plOpenPanel(pr) {
    plPanelPlace = pr;
    var head = document.getElementById('panelHead');
    var meta = [];   // 각 줄은 이미 escape 처리된 HTML 문자열
    if (pr.address) meta.push('📍 ' + plEsc(pr.address) +
        ' <button class="nv-map-btn" onclick="plOpenNaverMap()" title="네이버에서 검색">🔍 네이버검색</button>');
    else if (pr.lat != null && pr.lng != null) meta.push(
        '<button class="nv-map-btn" onclick="plOpenNaverMap()" title="네이버에서 검색">🔍 네이버검색</button>');
    if (pr.phone)   meta.push('📞 ' + plEsc(pr.phone));
    if (pr.period_start) meta.push('🗓️ ' + plEsc(pr.period_start + (pr.period_end ? ' ~ ' + pr.period_end : '')));
    if (pr.dist != null) meta.push('↔️ 경로에서 ~' + plEsc(plFmtDist(pr.dist)));
    var tags = (pr.tags && pr.tags.length) ? pr.tags : ((pr.attributes && pr.attributes.tags) || []);
    // 좌표가 있을 때 경로 버튼:
    //  - 경로 모드 중이면 '경로에 추가'(지점 목록 끝에 추가) — 여행 경로 빌더 흐름
    //  - 경로 모드가 아니면 '여기로 길찾기'(현위치→이 장소로 경로 시작) — 빠른 길찾기 흐름
    var addBtn = '', navBtns = '';
    if (!PL_GUEST && pr.lat != null && pr.lng != null) {   // 게스트(공유)는 보기 전용 — 추가/찜/길찾기 버튼 없음
        if (typeof rtMode !== 'undefined' && rtMode) {
            addBtn = '<button class="rt-addcur" onclick="rtAddCurrent()">➕ 경로에 추가</button>';
            var pk = (pr.id != null && rtIsPicked(pr.id));   // ⭐ 찜 토글(수집 — 경로 지점과 별개)
            addBtn += '<button class="rt-addcur rt-pickbtn' + (pk ? ' on' : '') + '" onclick="rtTogglePickHere()">' + (pk ? '★ 찜됨' : '☆ 찜') + '</button>';
        } else {
            addBtn = '<button class="rt-addcur nav-open-b" onclick="rtNavHere()">🧭 여기로 길찾기</button>';
        }
    }
    head.innerHTML =
        '<button class="panel-close" onclick="plClosePanel()">×</button>' +
        '<span class="cat-badge ' + (pr.category || 'etc') + '">' + (CAT_KO[pr.category] || '기타') + '</span>' +
        (plGuideBadges(pr) ? '<div class="panel-guides">' + plGuideBadges(pr) + '</div>' : '') +
        '<h3>' + plEsc(pr.name) +
            ((pr.attributes && pr.attributes.summary)
                ? ' <button type="button" class="ai-sum-chip" onclick="plShowAiSummary()" title="Claude 요약 보기">🤖 Claude</button>'
                : '') +
        '</h3>' +
        '<div class="meta">' + meta.join('<br>') + '</div>' +
        ((!PL_GUEST && !(pr.attributes && pr.attributes.summary) && pr.ref_count > 0)
            ? '<button type="button" class="ai-gen-btn" id="aiGenBtn" onclick="plGenSummary(' + pr.id + ')">🤖 AI 요약 생성</button>'
            : '') +
        plNaverHtml(pr) +
        (tags.length ? '<div class="tags">' + tags.map(function (t) { return '<em>' + plEsc(t) + '</em>'; }).join('') + '</div>' : '') +
        addBtn + navBtns;

    document.getElementById('panelRefs').innerHTML = '<div class="ref-empty">불러오는 중…</div>';
    document.getElementById('pl-panel').classList.add('open');

    // 네이버 맛집이면 전월 대비 추이 비동기 로드
    var _nv = pr.attributes && pr.attributes.naver;
    if (_nv && _nv.id) plLoadNaverTrend(String(_nv.id));

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
        var onclk = r.url ? ' onclick="plOpenArticle(this.href); return false;"' : '';
        return '<a class="ref-item" href="' + href + '" target="_blank" rel="noopener"' + onclk + '>' +
            '<span class="rt ' + r.source_type + '">' + (RT[r.source_type] || r.source_type) + '</span>' +
            (r.published_at ? '<span class="rsum">' + plEsc(r.published_at) + '</span>' : '') +
            '<div class="rtitle">' + plEsc(r.title || '(제목 없음)') + '</div>' +
            '</a>';
    }).join('');
}

function plClosePanel() { document.getElementById('pl-panel').classList.remove('open'); }

// 🤖 Claude 요약 모달 열기(공용) — 이름·요약 텍스트를 받아 표시
function plOpenAiSummaryModal(name, summary) {
    if (!summary) return;
    document.getElementById('aisumName').textContent = name || '';
    // 저장된 요약은 한 덩어리 → 문장 끝(.!?)마다 줄바꿈해 가독성 확보(pre-wrap이 \n 렌더)
    document.getElementById('aisumText').textContent =
        String(summary).replace(/\s+/g, ' ').trim().replace(/([.!?])\s+/g, '$1\n');
    document.getElementById('pl-aisum').classList.add('open');
}
// 상세패널 제목 옆 칩 클릭 → 현재 패널 장소 요약
function plShowAiSummary() {
    var pr = plPanelPlace;
    if (!pr || !pr.attributes || !pr.attributes.summary) return;
    plOpenAiSummaryModal(pr.name, pr.attributes.summary);
}
// 지도 리스트 항목 칩 클릭 → 해당 feature 요약(패널 열지 않고 모달만). which='base'(plFeatures)/'ov'(plOvFeats)
function plShowAiSummaryFrom(which, i) {
    var arr = (which === 'ov') ? plOvFeats : plFeatures;
    var f = arr && arr[i]; if (!f) return;
    var pr = f.properties;
    if (!pr || !pr.attributes || !pr.attributes.summary) return;
    plOpenAiSummaryModal(pr.name, pr.attributes.summary);
}
function plCloseAiSummary() { document.getElementById('pl-aisum').classList.remove('open'); }

// ── 🤖 AI 추천: 자연어 질의 → Claude 랭킹(요약 근거) → 클릭 시 마커로 이동 ──
function plRecoOpen() {
    document.getElementById('pl-reco').classList.add('open');
    setTimeout(function () { var i = document.getElementById('recoQ'); if (i) i.focus(); }, 50);
}
function plRecoClose() { document.getElementById('pl-reco').classList.remove('open'); }
function plRecoEx(t) { document.getElementById('recoQ').value = t; plRecoRun(); }
// 현재 화면 필터를 AI 추천 요청에 물려줌 — 후보 스코프를 화면(분류·태그·지역)과 일치시킨다(plSearch 와 동일 파라미터).
function plRecoFilterParams() {
    var p = {};
    if (plCatSel && plCatSel.length) p.categories = plCatSel.join(',');
    if (plRegionLock)               p.region     = plRegionLock.join(',');
    var travelTags = plSelTags.slice(); if (plSelMonth) travelTags.push(plSelMonth);
    if (travelTags.length)                 p.travel_tags  = travelTags.join('\n');
    if (plSelFood.length)                  p.food_tags    = plSelFood.join('\n');
    if (plSelGuide)                        p.guide        = plSelGuide;
    if (PL_SUBBARS.stay.sel.length)        p.stay_tags    = PL_SUBBARS.stay.sel.join('\n');
    if (PL_SUBBARS.camp.sel.length)        p.camping_tags = PL_SUBBARS.camp.sel.join('\n');
    return p;
}
// 활성 필터 사람이 읽는 라벨(안내 배지용). 없으면 ''.
function plRecoFilterLabel() {
    var CATK = { travel: '여행지', restaurant: '맛집', stay: '숙소', camping: '캠핑', etc: '기타' };
    var parts = [];
    if (plCatSel && plCatSel.length) parts.push(plCatSel.map(function (c) { return CATK[c] || c; }).join('·'));
    var tags = [];
    plSelTags.forEach(function (t) { tags.push(t); });
    plSelFood.forEach(function (t) { tags.push(t); });
    PL_SUBBARS.stay.sel.forEach(function (t) { tags.push(t); });
    PL_SUBBARS.camp.sel.forEach(function (t) { tags.push(t); });
    if (plSelMonth) tags.push(plSelMonth);
    if (plSelGuide && PL_GUIDES[plSelGuide]) tags.push(PL_GUIDES[plSelGuide].ko);
    if (tags.length) parts.push(tags.join('·'));
    if (plRegionLock && plActiveSido) parts.unshift(plActiveSido);
    return parts.join(' › ');
}
var plRecoBusy = false;
function plRecoRun() {
    if (plRecoBusy) return;
    var q = (document.getElementById('recoQ').value || '').trim();
    var box = document.getElementById('recoResult');
    if (!q) { box.innerHTML = '<div class="reco-empty">질문을 입력해 주세요.</div>'; return; }
    plRecoBusy = true;
    document.getElementById('recoBtn').disabled = true;
    var flt = plRecoFilterParams();
    var lbl = plRecoFilterLabel();
    var scope = lbl ? '<div class="reco-scope">🔎 현재 필터 <b>' + plEsc(lbl) + '</b> 범위에서 추천</div>' : '';
    box.innerHTML = scope + '<div class="reco-loading">🤖 Claude가 요약을 살펴보는 중…</div>';
    var params = { module: 'place', action: 'recommend', q: q };
    for (var k in flt) params[k] = flt[k];
    fetch('place_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(params)
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        plRecoBusy = false; document.getElementById('recoBtn').disabled = false;
        if (!d || !d.ok) { box.innerHTML = '<div class="reco-empty">' + plEsc((d && d.msg) || '추천 실패') + '</div>'; return; }
        plRecoRender(d);
    })
    .catch(function () {
        plRecoBusy = false; document.getElementById('recoBtn').disabled = false;
        box.innerHTML = '<div class="reco-empty">네트워크 오류. 다시 시도해 주세요.</div>';
    });
}
function plRecoRender(d) {
    var box = document.getElementById('recoResult');
    var html = '';
    var lbl = plRecoFilterLabel();
    if (lbl) html += '<div class="reco-scope">🔎 현재 필터 <b>' + plEsc(lbl) + '</b> 범위에서 추천</div>';
    if (d.intro) html += '<div class="reco-intro">' + plEsc(d.intro) + '</div>';
    if (!d.items || !d.items.length) { box.innerHTML = html + '<div class="reco-empty">딱 맞는 곳을 못 찾았어요. 조건을 바꿔 물어봐 주세요.</div>'; return; }
    var CATK = { travel: '여행지', restaurant: '맛집', stay: '숙소', camping: '캠핑', etc: '기타' };
    html += d.items.map(function (it) {
        var rev = (it.review_count != null) ? '<span class="ri-rev">리뷰 ' + Number(it.review_count).toLocaleString() + '</span>' : '';
        return '<div class="reco-item" onclick="plRecoGo(' + it.id + ')">' +
            '<div class="ri-top"><span class="ri-name">' + plEsc(it.name) + '</span>' +
            '<span class="ri-cat">' + (CATK[it.category] || '기타') + '</span>' +
            '<span class="ri-reg">' + plEsc(it.region || '') + '</span>' + rev + '</div>' +
            (it.reason ? '<div class="ri-reason">' + plEsc(it.reason) + '</div>' : '') +
            '<div class="ri-go">지도에서 보기 →</div>' +
        '</div>';
    }).join('');
    box.innerHTML = html;
}
function plRecoGo(id) { plRecoClose(); plForceShowPlace(id); }

// 요약 없는 장소: 연결 기사를 읽어 6축 요약을 생성·저장(온디맨드)
function plGenSummary(id) {
    var btn = document.getElementById('aiGenBtn');
    if (btn) { btn.disabled = true; btn.textContent = '🤖 요약 생성 중… (10초 내외)'; }
    fetch('place_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ module: 'place', action: 'summarize', id: id })
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        if (!d || !d.ok) {
            if (btn) { btn.disabled = false; btn.textContent = '🤖 AI 요약 생성'; }
            alert((d && d.msg) || '요약 생성 실패');
            return;
        }
        if (plPanelPlace && plPanelPlace.id == id) {
            plPanelPlace.attributes = plPanelPlace.attributes || {};
            plPanelPlace.attributes.summary = d.summary;
            if (d.features) plPanelPlace.attributes.features = d.features;
            plOpenPanel(plPanelPlace);   // 재렌더 → '🤖 Claude' 칩으로 전환
            plShowAiSummary();           // 생성된 요약을 바로 모달로 표시
        }
    })
    .catch(function () {
        if (btn) { btn.disabled = false; btn.textContent = '🤖 AI 요약 생성'; }
        alert('네트워크 오류. 다시 시도해 주세요.');
    });
}

// 기사 원문 → JS 팝업창
function plOpenArticle(url) {
    if (!url) return;
    // 모바일은 팝업 지오메트리(width/height)를 제대로 못 다뤄 "로딩되다 오류" 발생 → 새 탭으로 열고, 차단 시 현재 탭 이동
    var isMobile = /Android|iPhone|iPad|iPod|Mobile|Macintosh/i.test(navigator.userAgent || '');   // UA(기기) 기준, 폭 무관
    if (isMobile) {
        var w = window.open(url, '_blank');
        if (!w) location.href = url;
        return;
    }
    window.open(url, 'plArticle', 'width=920,height=860,scrollbars=yes,resizable=yes,menubar=no,toolbar=no');
}

// ── 좌측 리스트용 네이버 평점·리뷰 한 줄 (⭐4.63 · 📝4,024) — attributes.naver 있을 때만 ──
function plNaverLine(pr) {
    var nv = pr.attributes && pr.attributes.naver;
    if (!nv) return '';
    var parts = [];
    if (nv.score)  parts.push('<span class="li-nv-it">⭐ ' + plEsc(String(nv.score)) + '</span>');
    if (nv.review) parts.push('<span class="li-nv-it">📝 ' + Number(nv.review || 0).toLocaleString() + '</span>');
    if (!parts.length) return '';
    return '<div class="li-nv">' + parts.join('<span class="li-nv-sep">·</span>') + '</div>';
}

// ── 네이버 플레이스 정보 블록(평점·리뷰·저장수·한줄평) — attributes.naver 가 있으면 표시 ──
function plNaverHtml(pr) {
    var nv = pr.attributes && pr.attributes.naver;
    if (!nv) return '';
    var fmt = function (n) { return Number(n || 0).toLocaleString(); };
    var parts = [];
    if (nv.score) parts.push('<span class="nv-it">⭐ ' + plEsc(String(nv.score)) + '</span>');
    if (nv.review) {
        var sub = (nv.visitor != null || nv.blog != null)
            ? ' <small>(방문 ' + fmt(nv.visitor) + ' · 블로그 ' + fmt(nv.blog) + ')</small>' : '';
        parts.push('<span class="nv-it">📝 리뷰 ' + fmt(nv.review) + sub + '</span>');
    }
    if (nv.save) parts.push('<span class="nv-it">🔖 저장 ' + plEsc(String(nv.save)) + '</span>');
    var micro = nv.micro ? '<div class="nv-micro">“' + plEsc(nv.micro) + '”</div>' : '';
    var link = nv.url ? '<a class="nv-link" href="' + plEsc(nv.url) + '" onclick="plOpenArticle(this.href); return false;">네이버 플레이스에서 보기 ↗</a>' : '';
    // 전월 대비 추이 — nv.id 있으면 비동기로 채움(plLoadNaverTrend)
    var trend = nv.id ? '<div class="nv-trend" id="nv-trend-' + plEsc(String(nv.id)) + '"></div>' : '';
    if (!parts.length && !micro && !link && !trend) return '';
    return '<div class="pl-nv">' + parts.join('') + micro + trend + link + '</div>';
}

// ── 전월 대비 추이 로드 (naver_trend_api series) → 상세패널 네이버 박스에 Δ 한 줄 ──
function plLoadNaverTrend(nid) {
    var box = document.getElementById('nv-trend-' + nid);
    if (!box) return;
    fetch('/naver_trend_api.php?action=series&nid=' + encodeURIComponent(nid))
        .then(function (r) { return r.json(); })
        .then(function (j) {
            var s = (j && j.series) || [];
            if (s.length < 2) return;                          // 비교할 직전 회차 없음
            var cur = s[s.length - 1], prev = s[s.length - 2];
            var d = function (label, a, b, isF) {
                var x = Number(a || 0) - Number(b || 0);
                if (!x) return '';
                var v = isF ? Math.abs(x).toFixed(2) : Math.abs(x).toLocaleString();
                var cls = x > 0 ? 'up' : 'down', ar = x > 0 ? '▲' : '▼';
                return '<span class="nvt-it ' + cls + '">' + label + ' ' + ar + v + '</span>';
            };
            var parts = [
                d('리뷰', cur.review, prev.review, false),
                d('방문', cur.visitor, prev.visitor, false),
                d('블로그', cur.blog, prev.blog, false),
                d('저장', cur.save, prev.save, false),
                d('평점', cur.score, prev.score, true)
            ].filter(Boolean);
            if (!parts.length) { box.innerHTML = '<div class="nvt-flat">전월 대비 변화 없음 (' + plEsc(prev.period) + '→' + plEsc(cur.period) + ')</div>'; return; }
            box.innerHTML = '<div class="nvt-h">전월 대비 (' + plEsc(prev.period) + '→' + plEsc(cur.period) + ')</div>'
                          + '<div class="nvt-row">' + parts.join('') + '</div>';
        }).catch(function () {});
}

// ── 현재 상세패널 장소를 네이버 통합검색으로 팝업 (지도 대신 메인검색 — 장소 정보/지도 모두 노출) ──
function plOpenNaverMap() {
    var pr = plPanelPlace;
    if (!pr) return;
    var q = ((pr.name || '') + ' ' + (pr.address || '')).trim();
    if (!q) return;
    var url = 'https://search.naver.com/search.naver?query=' + encodeURIComponent(q);
    window.open(url, 'plNaverSearch', 'width=980,height=900,scrollbars=yes,resizable=yes,menubar=no,toolbar=no');
}

// ── 장소 수정 모달 (이름/분류 변경 + 카카오로 좌표 직접 지정) ──
var plEditId = 0, plEditResults = [], plEditSel = null, plEditTimer = null, plEditExtras = [], plEditTags = [], plEditGuides = [];
var plTagListKey = '', plCuisineListLoaded = false;   // 태그 추천/자동완성 스코프 캐시(분류별)
var plEditFrom = 'list';         // 모달을 연 곳: 'list'(좌측 결과 리스트)
var plRefreshCurrent = null;     // 마지막 검색을 다시 실행해 리스트/마커 갱신 (수정 저장 후)

// 수정 모달에 채울 장소 데이터 찾기 — 지도 검색결과(properties)에서
function plFindPlaceData(id) {
    for (var j = 0; j < plFeatures.length; j++) { var p = plFeatures[j].properties; if (p && p.id == id) return p; }
    return null;
}
function plFmtDateTime(s) { return s ? String(s).slice(0, 16) : ''; }   // 'YYYY-MM-DD HH:MM:SS' → 분까지

// 연결된 기사(출처) 목록 로드/렌더/제거
var PL_RT = { article: '기사', youtube: '유튜브', blog: '블로그', official: '공식', manual: '메모' };
function plEditLoadRefs(id) {
    var box = document.getElementById('pemRefs'); document.getElementById('pemRefsCnt').textContent = '';
    box.innerHTML = '<div class="pem-empty">불러오는 중…</div>';
    fetch(plApiUrl({ module: 'place', action: 'refs', id: id }))
        .then(function (r) { return r.json(); })
        .then(function (d) { if (id !== plEditId) return; plEditRenderRefs((d && d.refs) || []); })
        .catch(function () { box.innerHTML = '<div class="pem-empty">불러오기 실패</div>'; });
}
function plEditRenderRefs(refs) {
    var box = document.getElementById('pemRefs');
    document.getElementById('pemRefsCnt').textContent = refs.length ? '(' + refs.length + '건)' : '';
    if (!refs.length) { box.innerHTML = '<div class="pem-empty">연결된 기사가 없습니다</div>'; return; }
    box.innerHTML = refs.map(function (r) {
        var title = plEsc(r.title || '(제목 없음)');
        var t = r.url
            ? '<a href="' + plEsc(r.url) + '" class="pem-ref-t" onclick="plOpenArticle(this.href);return false;">' + title + '</a>'
            : '<span class="pem-ref-t">' + title + '</span>';
        var del = '<button class="pem-ref-del" title="이 기사 영구 삭제" onclick="plEditDeleteRef(' + r.id + ')">✕</button>';
        return '<div class="pem-ref">' +
            '<span class="pem-ref-rt ' + plEsc(r.source_type) + '">' + (PL_RT[r.source_type] || plEsc(r.source_type)) + '</span>' +
            t +
            (r.published_at ? '<span class="pem-ref-d">' + plEsc(r.published_at) + '</span>' : '') +
            del +
        '</div>';
    }).join('');
}
// 이 장소에서 기사(출처)를 영구 삭제
function plEditDeleteRef(refId) {
    if (!plEditId) return;
    if (!confirm('이 기사를 영구 삭제합니다.\n되돌릴 수 없습니다. 삭제할까요?')) return;
    fetch(plApiUrl({ module: 'place', action: 'ref_delete', ref_id: refId, place_id: plEditId }))
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.ok) { alert((d && d.msg) || '삭제 실패'); return; }
            plEditRenderRefs(d.refs || []);
            plHint('기사 1건을 삭제했습니다');
        })
        .catch(function () { alert('삭제 실패'); });
}
// 이 장소에 기사(출처)를 직접 추가 — 제목 + URL (연결된 기사가 없을 때 등)
function plEditAddRef() {
    if (!plEditId) return;
    var ti = document.getElementById('pemRefTitle');
    var ui = document.getElementById('pemRefUrl');
    var title = ti.value.trim(), url = ui.value.trim();
    if (!title && !url) { alert('기사 제목 또는 URL을 입력하세요'); return; }
    fetch(plApiUrl({ module: 'place', action: 'ref_add', place_id: plEditId, title: title, url: url }))
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.ok) { alert((d && d.msg) || '추가 실패'); return; }
            ti.value = ''; ui.value = '';
            plEditRenderRefs(d.refs || []);
            plHint(d.added ? '기사를 추가했습니다' : '이미 연결된 URL 입니다');
        })
        .catch(function () { alert('추가 실패'); });
}

// 분류 옵션(수정 모달 select 와 동일). 추가 장소 행의 <select> 생성에 재사용
var PL_CAT_OPTS = [['travel', '여행지'], ['restaurant', '맛집'], ['stay', '숙소'], ['camping', '캠핑장'], ['etc', '기타']];
function plCatSelectHtml(sel, onchange) {
    var opts = PL_CAT_OPTS.map(function (o) {
        return '<option value="' + o[0] + '"' + (o[0] === sel ? ' selected' : '') + '>' + o[1] + '</option>';
    }).join('');
    return '<select class="pem-esel" onchange="' + onchange + '">' + opts + '</select>';
}

function plEditOpen(id, from) {
    var it = plFindPlaceData(id);
    if (!it) return;
    plEditId = id; plEditFrom = from || 'list'; plEditSel = null; plEditResults = []; plEditExtras = [];
    document.getElementById('pemName').value = it.name || '';
    document.getElementById('pemCat').value  = it.category || 'travel';
    document.getElementById('pemSearch').value = it.address || it.name || '';  // 주소 우선(없으면 이름)
    document.getElementById('pemPicked').innerHTML = '';
    document.getElementById('pemResults').innerHTML = '';
    document.getElementById('pemRefTitle').value = '';   // 기사 추가 입력 초기화
    document.getElementById('pemRefUrl').value = '';
    document.getElementById('pemUpdated').innerHTML =
        it.updated_at ? ('🕒 최근 수정: <b>' + plEsc(plFmtDateTime(it.updated_at)) + '</b>') : '';
    plEditLoadRefs(id);                   // 연결된 기사 목록
    plEditRenderExtras();
    plEditTags = [];                      // 태그: 초기화 후 비동기 로드
    plSuggestExpanded = false;            // 추천칩 펼침 상태 초기화
    document.getElementById('pemTagInput').value = '';
    document.getElementById('pemCuisineInput').value = '';
    document.getElementById('pemGradeInput').value = '';
    plEditFillGradeList();
    plEditRenderMonths(); plEditRenderTagChips(); plEditRenderSuggest(); plEditRenderCuisines();
    plLoadTagDatalist();                  // 자동완성 후보(최초 1회)
    plEditTagsLoad(id);                   // 이 장소의 기존 태그
    plEditGuides = []; plEditRenderGuides(); plEditGuidesLoad(id);   // 맛집 가이드
    plEditCatChange();                    // 분류에 따라 맛집 정보 섹션 표시/숨김
    document.getElementById('pl-edit').classList.add('open');
    plEditSearch(); // 주소(없으면 이름)로 즉시 후보 검색
    setTimeout(function () { document.getElementById('pemSearch').focus(); }, 50);
}
function plEditClose() {
    document.getElementById('pl-edit').classList.remove('open');
    plEditId = 0; plEditSel = null;
}
function plEditSearchDebounced() { clearTimeout(plEditTimer); plEditTimer = setTimeout(plEditSearch, 250); }
function plEditRenderResults(items) {
    var box = document.getElementById('pemResults');
    plEditResults = items;
    if (!items.length) { box.innerHTML = '<div class="pem-empty">검색 결과 없음</div>'; return; }
    box.innerHTML = items.map(function (it, i) {
        return '<div class="pem-res pem-res-row" id="pem-res-' + i + '">' +
            '<div class="pem-res-main" onclick="plEditPick(' + i + ')">' +
                '<div class="pem-raddr-main">' + plEsc(it.address || '(주소 없음)') + '</div>' +
                '<div class="pem-rname">' + plEsc(it.name) +
                    (it.category ? '<span class="pem-rcat">' + plEsc(it.category) + '</span>' : '') +
                '</div>' +
            '</div>' +
            '<button class="pem-add" title="이 장소를 추가 장소로 등록" onclick="plEditAddExtra(' + i + ')">＋</button>' +
        '</div>';
    }).join('');
}
function plEditSearch() {
    var q = document.getElementById('pemSearch').value.trim();
    var box = document.getElementById('pemResults');
    if (q.length < 2) { box.innerHTML = '<div class="pem-empty">2글자 이상 입력</div>'; return; }
    box.innerHTML = '<div class="pem-empty">검색 중…</div>';
    // ① 카카오 키워드(장소명·POI)
    fetch(plApiUrl({ module: 'place', action: 'suggest', q: q }))
        .then(function (r) { return r.json(); })
        .then(function (d) {
            var items = (d && d.items) || [];
            if (items.length) { plEditRenderResults(items); return; }
            // ② 키워드 결과 없으면 주소 지오코딩 폴백(네이버 도로명/지번 등) — 전체 주소 입력 대응
            fetch(plApiUrl({ module: 'place', action: 'geocode', address: q }))
                .then(function (r) { return r.json(); })
                .then(function (g) {
                    if (g && g.ok && g.lat && g.lng) {
                        plEditRenderResults([{ name: (g.address || q), address: q, category: '', lat: +g.lat, lng: +g.lng }]);
                    } else {
                        plEditRenderResults([]);
                    }
                })
                .catch(function () { box.innerHTML = '<div class="pem-empty">검색 결과 없음</div>'; });
        })
        .catch(function () { box.innerHTML = '<div class="pem-empty">검색 실패</div>'; });
}
function plEditPick(i) {
    var it = plEditResults[i]; if (!it) return;
    plEditSel = { lat: it.lat, lng: it.lng, name: it.name, addr: it.address };
    // 선택한 항목의 주소를 좌표지정 입력창에 표시(값만 세팅 → oninput 미발생, 재검색 안 됨)
    document.getElementById('pemSearch').value = it.address || it.name;
    var els = document.querySelectorAll('.pem-res');
    for (var k = 0; k < els.length; k++) els[k].classList.remove('active');
    var el = document.getElementById('pem-res-' + i); if (el) el.classList.add('active');
    document.getElementById('pemPicked').innerHTML =
        '✅ 지정 좌표: <b>' + plEsc(it.address || it.name) + '</b>' +
        (it.address ? ' <span>' + plEsc(it.name) + '</span>' : '');
}
// 멀티 등록 — 검색 결과를 '추가 장소'로 담아둔다(같은 기사를 공유하는 별도 place)
// 각 추가 장소는 제목(name)·분류(cat)·좌표(lat/lng) 를 독립 보유한다.
function plEditAddExtra(i) {
    var it = plEditResults[i]; if (!it) return;
    for (var k = 0; k < plEditExtras.length; k++) {            // 같은 좌표 중복 방지
        if (plEditExtras[k].lat == it.lat && plEditExtras[k].lng == it.lng) return;
    }
    var defCat = document.getElementById('pemCat').value || 'travel';   // 추가 시점의 상단 분류를 기본값으로
    plEditExtras.push({ name: it.name, cat: defCat, lat: it.lat, lng: it.lng, addr: it.address || '' });
    plEditRenderExtras();
}
function plEditRemoveExtra(k) { plEditExtras.splice(k, 1); plEditRenderExtras(); }
function plEditSetExtraName(k, v) { if (plEditExtras[k]) plEditExtras[k].name = v; }   // 입력 중엔 재렌더 안 함(포커스 유지)
function plEditSetExtraCat(k, v) { if (plEditExtras[k]) plEditExtras[k].cat = v; }
function plEditRenderExtras() {
    var box = document.getElementById('pemExtra');
    document.getElementById('pemExtraCnt').textContent = plEditExtras.length ? '(' + plEditExtras.length + '곳)' : '';
    box.innerHTML = plEditExtras.map(function (ex, k) {
        var loc = plEsc(ex.addr || ex.name);
        return '<div class="pem-erow">' +
            '<div class="pem-erow-top">' +
                '<input class="pem-einp" type="text" value="' + plEsc(ex.name) + '" placeholder="제목" ' +
                    'oninput="plEditSetExtraName(' + k + ', this.value)">' +
                plCatSelectHtml(ex.cat, 'plEditSetExtraCat(' + k + ', this.value)') +
                '<button class="pem-edel" onclick="plEditRemoveExtra(' + k + ')" title="제거">×</button>' +
            '</div>' +
            '<div class="pem-eloc" title="' + loc + '">📍 ' + loc + '</div>' +
        '</div>';
    }).join('');
}
// ── 태그 (방문시기·테마·부분류) ──
var PL_MONTHS = ['1월', '2월', '3월', '4월', '5월', '6월', '7월', '8월', '9월', '10월', '11월', '12월'];
var PL_TKIND_KO = { theme: '테마', type: '부분류', facet: '편의' };
function plTagHas(kind, tag) {
    for (var i = 0; i < plEditTags.length; i++) if (plEditTags[i].kind === kind && plEditTags[i].tag === tag) return i;
    return -1;
}
function plNormTag(s) { return String(s).trim().replace(/\s+/g, ' ').slice(0, 40); }   // 정규화: 공백 정리
// 편집 중인 분류에 따라 태그 섹션의 종류·스코프를 결정.
//  여행지·기타·맛집 = 테마(kind=theme) / 숙소·캠핑 = 그 분류 유형(kind=cuisine: 펜션·오토캠핑…)
function plEditTagScope() {
    var c = (document.getElementById('pemCat') || {}).value || 'travel';
    if (c === 'stay' || c === 'camping') return { addKind: 'cuisine', listCat: c, listKind: 'cuisine' };
    return { addKind: 'theme', listCat: c, listKind: '' };
}
// 태그 추천·datalist 를 '그 분류 장소의 태그'로만 채운다(분류 바뀌면 재로드).
function plLoadTagDatalist() {
    var sc = plEditTagScope();
    var key = sc.listCat + '|' + sc.listKind;
    plLoadCuisineDatalist();                 // 맛집 음식종류 자동완성(분류 무관 1회)
    if (plTagListKey === key) { plEditRenderSuggest(); return; }   // 같은 스코프면 재사용
    plTagListKey = key;
    var p = { module: 'place', action: 'tag_list', bar: 1, category: sc.listCat };
    if (sc.listKind) p.kind = sc.listKind;
    fetch(plApiUrl(p))
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (plTagListKey !== key) return;            // 그새 분류 바뀌면 무시
            var tags = (d && d.items) || [];
            document.getElementById('pemTagList').innerHTML =
                tags.map(function (t) { return '<option value="' + plEsc(t.tag) + '">'; }).join('');
            plTagSuggestAll = tags.map(function (t) { return t.tag; });
            plEditRenderSuggest();
        })
        .catch(function () { plTagListKey = ''; });
}
// 맛집 음식종류(cuisine) datalist — restaurant 스코프(편집 분류와 무관, 한 번만)
function plLoadCuisineDatalist() {
    var box = document.getElementById('pemCuisineList'); if (!box || plCuisineListLoaded) return;
    plCuisineListLoaded = true;
    fetch(plApiUrl({ module: 'place', action: 'tag_list', bar: 1, kind: 'cuisine', category: 'restaurant' }))
        .then(function (r) { return r.json(); })
        .then(function (d) { box.innerHTML = ((d && d.items) || []).map(function (t) { return '<option value="' + plEsc(t.tag) + '">'; }).join(''); })
        .catch(function () { plCuisineListLoaded = false; });
}
var plTagSuggestAll = [], plSuggestExpanded = false;
var PL_SUGGEST_TOP = 12;
function plSuggestToggle() { plSuggestExpanded = !plSuggestExpanded; plEditRenderSuggest(); }
function plEditRenderSuggest() {
    var box = document.getElementById('pemTagSuggest');
    if (!box) return;
    var sk = plEditTagScope().addKind;
    var avail = plTagSuggestAll.filter(function (tg) { return plTagHas(sk, tg) < 0; }); // 이미 단 건 숨김
    if (!avail.length) { box.innerHTML = ''; return; }
    var shown = plSuggestExpanded ? avail : avail.slice(0, PL_SUGGEST_TOP);
    var html = '<span class="pem-sug-lbl">자주 쓰는 태그:</span>' + shown.map(function (tg) {
        return '<button type="button" class="pem-sug-chip" onclick="plEditAddSuggested(\'' +
            tg.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + '\')">' + plEsc(tg) + '</button>';
    }).join('');
    var hidden = avail.length - PL_SUGGEST_TOP;
    if (hidden > 0) {
        html += '<button type="button" class="pem-sug-more" onclick="plSuggestToggle()">' +
            (plSuggestExpanded ? '접기' : ('+' + hidden + ' 전체')) + '</button>';
    }
    box.innerHTML = html;
}
function plEditAddSuggested(tg) {
    tg = plNormTag(tg);
    var k = plEditTagScope().addKind;
    if (tg && plTagHas(k, tg) < 0) plEditTags.push({ kind: k, tag: tg });
    plEditRenderSuggest(); plEditRenderTagChips();
}
function plEditTagsLoad(id) {
    fetch(plApiUrl({ module: 'place', action: 'place_tags', id: id }))
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (id !== plEditId) return;                  // 그새 다른 항목 열렸으면 무시
            plEditTags = (d && d.items) || [];
            plEditRenderMonths(); plEditRenderTagChips(); plEditRenderSuggest();
        })
        .catch(function () {});
}
function plEditRenderMonths() {
    document.getElementById('pemMonths').innerHTML = PL_MONTHS.map(function (m, i) {
        var on = plTagHas('month', m) >= 0;
        return '<button type="button" class="pem-mon' + (on ? ' active' : '') + '" onclick="plEditMonthToggle(' + i + ')">' + (i + 1) + '</button>';
    }).join('');
}
function plEditMonthToggle(i) {
    var m = PL_MONTHS[i], at = plTagHas('month', m);
    if (at >= 0) plEditTags.splice(at, 1); else plEditTags.push({ kind: 'month', tag: m });
    plEditRenderMonths();
}
function plEditTagAdd() {
    var inp = document.getElementById('pemTagInput');
    if (!inp.value.trim()) return;
    var k = plEditTagScope().addKind;
    inp.value.split(',').forEach(function (s) {              // 쉼표로 여러 개 허용
        var tag = plNormTag(s);
        if (tag && plTagHas(k, tag) < 0) plEditTags.push({ kind: k, tag: tag });
    });
    inp.value = '';
    plEditRenderSuggest(); plEditRenderTagChips();
}
function plEditTagRemove(i) { plEditTags.splice(i, 1); plEditRenderMonths(); plEditRenderSuggest(); plEditRenderTagChips(); plEditRenderCuisines(); }
function plEditRenderTagChips() {
    var cuisineScope = (plEditTagScope().addKind === 'cuisine');   // 숙소·캠핑 = 유형(cuisine) 섹션
    document.getElementById('pemTags').innerHTML = plEditTags.map(function (t, i) {
        // 숙소·캠핑: cuisine(유형)만 / 그 외: 테마류(월·음식·등급 제외)
        var show = cuisineScope ? (t.kind === 'cuisine') : (t.kind !== 'month' && t.kind !== 'cuisine' && t.kind !== 'grade');
        if (!show) return '';
        return '<span class="pem-tag k-' + t.kind + '">' + plEsc(t.tag) +
            '<button onclick="plEditTagRemove(' + i + ')" title="제거">×</button></span>';
    }).join('');
}

// ── 맛집 정보(가이드 + 음식 종류) 편집 ──
function plEditCatChange() {
    var cat = document.getElementById('pemCat').value;
    document.getElementById('pemFood').style.display = (cat === 'restaurant') ? '' : 'none';
    // 분류 바뀜 → 태그 추천·datalist 재스코프 + 섹션 칩 재렌더(그 분류 태그만)
    plLoadTagDatalist(); plEditRenderTagChips();
    var cuisineScope = (cat === 'stay' || cat === 'camping');
    var inp = document.getElementById('pemTagInput');
    if (inp) inp.placeholder = cuisineScope ? '유형 입력 후 Enter (예: 펜션, 글램핑)' : '태그 입력 후 Enter (쉼표로 여러 개)';
}
// 음식 종류 태그(kind=cuisine)
function plEditCuisineAdd() {
    var inp = document.getElementById('pemCuisineInput');
    if (!inp.value.trim()) return;
    inp.value.split(',').forEach(function (s) {
        var t = plNormTag(s);
        if (t && plTagHas('cuisine', t) < 0) plEditTags.push({ kind: 'cuisine', tag: t });
    });
    inp.value = '';
    plEditRenderCuisines();
}
// 등급 태그(kind=grade) — #리본2 등
function plEditGradeAdd() {
    var inp = document.getElementById('pemGradeInput');
    if (!inp.value.trim()) return;
    inp.value.split(',').forEach(function (s) {
        var t = plNormTag(s);
        if (t && plTagHas('grade', t) < 0) plEditTags.push({ kind: 'grade', tag: t });
    });
    inp.value = '';
    plEditRenderCuisines();
}
// 등급 자동완성(datalist) — PL_GUIDES 의 추천 등급 합집합
function plEditFillGradeList() {
    var box = document.getElementById('pemGradeList'); if (!box) return;
    var seen = {}, opts = [];
    Object.keys(PL_GUIDES).forEach(function (gk) {
        (PL_GUIDES[gk].grades || []).forEach(function (g) {
            if (!seen[g]) { seen[g] = 1; opts.push('<option value="' + plEsc(g) + '">'); }
        });
    });
    box.innerHTML = opts.join('');
}
// 음식 종류(cuisine) + 등급(grade) 칩 — 색으로 구분
function plEditRenderCuisines() {
    var box = document.getElementById('pemCuisines'); if (!box) return;
    box.innerHTML = plEditTags.map(function (t, i) {
        if (t.kind !== 'cuisine' && t.kind !== 'grade') return '';
        return '<span class="pem-tag k-' + t.kind + '">' + plEsc(t.tag) +
            '<button onclick="plEditTagRemove(' + i + ')" title="제거">×</button></span>';
    }).join('');
}
// 가이드(블루리본/미쉐린/기타) — 체크만(마커색). 등급은 위 등급 태그로
function plEditGuideGet(gk) {
    for (var i = 0; i < plEditGuides.length; i++) if (plEditGuides[i].guide === gk) return plEditGuides[i];
    return null;
}
function plEditGuidesLoad(id) {
    fetch(plApiUrl({ module: 'place', action: 'place_guides', id: id }))
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (id !== plEditId) return;
            plEditGuides = (d && d.items) || [];
            plEditRenderGuides();
        })
        .catch(function () {});
}
function plEditRenderGuides() {
    var box = document.getElementById('pemGuides'); if (!box) return;
    box.innerHTML = Object.keys(PL_GUIDES).map(function (gk) {
        var d = PL_GUIDES[gk]; var on = !!plEditGuideGet(gk);
        return '<label class="pem-grow">' +
            '<input type="checkbox"' + (on ? ' checked' : '') + ' onchange="plEditGuideToggle(\'' + gk + '\',this.checked)">' +
            '<span class="pem-gname" style="color:' + d.color + '">' + plEsc(d.ko) + '</span></label>';
    }).join('');
}
function plEditGuideToggle(gk, on) {
    var cur = plEditGuideGet(gk);
    if (on && !cur) plEditGuides.push({ guide: gk });
    else if (!on && cur) plEditGuides.splice(plEditGuides.indexOf(cur), 1);
    plEditRenderGuides();
}

function plEditSave() {
    if (!plEditId) return;
    var name = document.getElementById('pemName').value.trim();
    var cat  = document.getElementById('pemCat').value;
    if (!name) { alert('이름을 입력하세요'); return; }
    var btn = document.getElementById('pemSave'); btn.disabled = true; btn.textContent = '저장 중…';
    var primary = { module: 'place', action: 'place_update', id: plEditId, name: name, category: cat };
    if (plEditSel) { primary.lat = plEditSel.lat; primary.lng = plEditSel.lng; primary.address = plEditSel.addr || ''; }
    fetch(plApiUrl(primary))
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.ok) throw new Error((d && d.msg) || '기본 장소 저장 실패');
            // 추가 장소들 — 원본(plEditId)의 기사를 공유하는 새 place 로 등록(각자 제목·분류 사용)
            var extraJobs = plEditExtras.map(function (ex) {
                return fetch(plApiUrl({ module: 'place', action: 'place_add', src_id: plEditId,
                                        name: (ex.name || '').trim() || ex.name, category: ex.cat || cat,
                                        lat: ex.lat, lng: ex.lng, address: ex.addr || '' }))
                    .then(function (r) { return r.json(); });
            });
            // 태그(기본 장소) 저장 — 음식 종류(cuisine)도 plEditTags 에 포함되어 함께 저장됨
            var tagJob = fetch(plApiUrl({ module: 'place', action: 'tag_set', id: plEditId,
                                          tags: JSON.stringify(plEditTags) })).then(function (r) { return r.json(); });
            // 맛집 가이드 저장 (맛집 아니면 빈 배열 → 가이드 없음으로 정리)
            var guideJob = fetch(plApiUrl({ module: 'place', action: 'guide_set', id: plEditId,
                                          guides: JSON.stringify(plEditGuides) })).then(function (r) { return r.json(); });
            return Promise.all([Promise.all(extraJobs), tagJob, guideJob]);
        })
        .then(function (res) {
            btn.disabled = false; btn.textContent = '저장';
            var added = (res[0] || []).filter(function (x) { return x && x.ok; }).length;
            plEditClose();
            // 지도 결과리스트의 마지막 검색을 다시 실행해 목록/마커 갱신
            if (typeof plRefreshCurrent === 'function') plRefreshCurrent();
            if (added) plHint(added + '곳을 추가 등록했습니다');
        })
        .catch(function (e) { btn.disabled = false; btn.textContent = '저장'; alert((e && e.message) || '저장 실패'); });
}

// ── 태그 관리(병합/이름변경/삭제) 모달 ──
// 칩 그리드 + 선택형: 태그 클릭=다중선택 → 하단 액션바에서 이름변경/삭제/병합
var plTagAll = [], plTagSel = [], plTagMode = '', plTagMergeMain = null;
function plTagMgrOpen() { document.getElementById('pl-tagmgr').classList.add('open'); plTagMgrLoad(); }
function plTagMgrClose() {
    document.getElementById('pl-tagmgr').classList.remove('open');
    plTagListKey = ''; plCuisineListLoaded = false;   // 추천/자동완성 캐시 무효화 → 갱신되도록
    // 수정모달 위에서 닫혔으면(병합·이름변경 반영) 그 자리에서 자동완성·추천 즉시 갱신
    if (document.getElementById('pl-edit').classList.contains('open')) {
        plLoadTagDatalist();
    }
}
function plTagMgrLoad() {
    plTagSel = []; plTagMode = ''; plTagMergeMain = null;
    var s = document.getElementById('tmSearch'); if (s) s.value = '';
    document.getElementById('tmGrid').innerHTML = '<div class="tm-empty">불러오는 중…</div>';
    fetch(plApiUrl({ module: 'place', action: 'tag_list' }))
        .then(function (r) { return r.json(); })
        .then(function (d) {
            plTagAll = ((d && d.items) || []).filter(function (t) { return t.kind !== 'month'; }); // 월 제외
            plTagMgrRender();
        })
        .catch(function () { document.getElementById('tmGrid').innerHTML = '<div class="tm-empty">불러오기 실패</div>'; });
}
function plTagCnt(tag) { for (var i = 0; i < plTagAll.length; i++) { if (plTagAll[i].tag === tag) return plTagAll[i].cnt; } return 0; }
function plTagJs(t) { return t.replace(/\\/g, '\\\\').replace(/'/g, "\\'"); }
function plTagIsFood(t) { return t.kind === 'cuisine' || t.kind === 'grade'; }   // 맛집 태그
var PL_CATSEC = { travel: '🗺️ 여행지 태그', restaurant: '🍜 맛집 태그', stay: '🏨 숙소 태그', camping: '⛺ 캠핑 태그', etc: '🏷 기타 태그' };
function plTagCatOf(t) { return t.cat || (plTagIsFood(t) ? 'restaurant' : 'travel'); }   // 대표분류(폴백=kind)
function plTagMgrRender() {
    var q = (document.getElementById('tmSearch').value || '').trim().toLowerCase();
    // 태그 관리는 수정 모달에서 열리므로, 그 장소의 분류 태그만 보여준다(혼재 방지).
    var editOpen = document.getElementById('pl-edit').classList.contains('open');
    var only = editOpen ? ((document.getElementById('pemCat') || {}).value || '') : '';
    var base = only ? plTagAll.filter(function (t) { return plTagCatOf(t) === only; }) : plTagAll;
    var list = q ? base.filter(function (t) { return t.tag.toLowerCase().indexOf(q) >= 0; }) : base;
    document.getElementById('tmCount').textContent = '총 ' + base.length + '개' + (q ? (' · ' + list.length + ' 일치') : '');
    var grid = document.getElementById('tmGrid');
    if (!base.length) { grid.innerHTML = '<div class="tm-empty">이 분류의 태그가 없습니다</div>'; plTagActionRender(); return; }
    if (!list.length) { grid.innerHTML = '<div class="tm-empty">일치하는 태그 없음</div>'; plTagActionRender(); return; }
    function chip(t) {
        var sel = plTagSel.indexOf(t.tag) >= 0 ? ' sel' : '';
        return '<button class="tm-chip' + sel + '" onclick="plTagChip(\'' + plTagJs(t.tag) + '\')">' +
            plEsc(t.tag) + '<span class="tm-c">' + t.cnt + '</span></button>';
    }
    function section(title, arr) {
        if (!arr.length) return '';
        return '<div class="tm-sec-h">' + title + ' <span>' + arr.length + '</span></div>' +
               '<div class="tm-sec">' + arr.map(chip).join('') + '</div>';
    }
    function byCat(c) { return list.filter(function (t) { return plTagCatOf(t) === c; }); }
    if (only) {   // 편집 중 분류 1개만
        grid.innerHTML = section(PL_CATSEC[only] || '🏷 태그', list);
    } else {      // 전체(분류별 5섹션)
        grid.innerHTML = ['travel', 'restaurant', 'stay', 'camping', 'etc'].map(function (c) {
            return section(PL_CATSEC[c], byCat(c));
        }).join('');
    }
    plTagActionRender();
}
function plTagChip(tag) {
    var i = plTagSel.indexOf(tag);
    if (i >= 0) plTagSel.splice(i, 1); else plTagSel.push(tag);
    plTagMode = '';                  // 선택이 바뀌면 진행 중 모드 취소
    plTagMgrRender();
}
function plTagDeselect(tag) { var i = plTagSel.indexOf(tag); if (i >= 0) plTagSel.splice(i, 1); plTagMode = ''; plTagMgrRender(); }
function plTagModeSet(m) { plTagMode = m; if (m === 'merge') plTagMergeMain = null; plTagActionRender(); }
function plTagActionRender() {
    var box = document.getElementById('tmAction');
    var n = plTagSel.length;
    if (!n) { box.innerHTML = '<div class="tm-hint">정리할 태그를 클릭해 선택하세요. 여러 개 선택하면 <b>병합·삭제</b>할 수 있습니다.</div>'; return; }
    var chips = '<div class="tm-sel-chips">' + plTagSel.map(function (t) {
        return '<span class="tm-selchip">' + plEsc(t) + '<button onclick="plTagDeselect(\'' + plTagJs(t) + '\')" title="선택 해제">×</button></span>';
    }).join('') + '</div>';

    if (plTagMode === 'rename') {
        box.innerHTML = chips +
            '<div class="tm-act-btns">' +
                '<input id="tmRenameInp" class="tm-inp" value="' + plEsc(plTagSel[0]) + '" ' +
                    'onkeydown="if(event.key===\'Enter\')plTagRenameApply();else if(event.key===\'Escape\')plTagModeSet(\'\');">' +
                '<button class="tm-btn primary" onclick="plTagRenameApply()">변경</button>' +
                '<button class="tm-btn ghost" onclick="plTagModeSet(\'\')">취소</button>' +
            '</div>';
        setTimeout(function () { var i = document.getElementById('tmRenameInp'); if (i) { i.focus(); i.select(); } }, 30);
        return;
    }
    if (plTagMode === 'merge') {
        if (!plTagMergeMain || plTagSel.indexOf(plTagMergeMain) < 0) {   // 기본 메인 = 개수 최다
            plTagMergeMain = plTagSel.slice().sort(function (a, b) { return plTagCnt(b) - plTagCnt(a); })[0];
        }
        var picks = '<div class="tm-merge-pick">' + plTagSel.map(function (t) {
            var on = (t === plTagMergeMain) ? ' on' : '';
            return '<button class="tm-pick' + on + '" onclick="plTagMergeMain=\'' + plTagJs(t) + '\';plTagActionRender();">' +
                plEsc(t) + ' ' + plTagCnt(t) + '</button>';
        }).join('') + '</div>';
        var others = plTagSel.length - 1;
        box.innerHTML = chips +
            '<div class="tm-hint">합칠 <b>메인 이름</b>을 고르세요 — 나머지 ' + others + '개가 <b>' + plEsc(plTagMergeMain) + '</b>(으)로 흡수됩니다.</div>' +
            picks +
            '<div class="tm-act-btns">' +
                '<button class="tm-btn primary" onclick="plTagMergeApply()">합치기</button>' +
                '<button class="tm-btn ghost" onclick="plTagModeSet(\'\')">취소</button>' +
            '</div>';
        return;
    }
    // 기본: 선택 개수에 맞는 액션
    var btns = '<div class="tm-act-btns">';
    if (n === 1) btns += '<button class="tm-btn rename" onclick="plTagModeSet(\'rename\')">✏️ 이름 변경</button>';
    if (n >= 2) btns += '<button class="tm-btn merge" onclick="plTagModeSet(\'merge\')">🔀 병합</button>';
    btns += '<button class="tm-btn del" onclick="plTagDeleteSel()">🗑 삭제' + (n > 1 ? (' (' + n + ')') : '') + '</button>';
    btns += '</div>';
    box.innerHTML = chips + btns;
}
// 작업 후: 서버가 준 최신 목록(있으면)으로 갱신 + 선택/모드 초기화
function plTagMgrAfter(items) {
    plTagSel = []; plTagMode = ''; plTagMergeMain = null;
    if (items) { plTagAll = items.filter(function (t) { return t.kind !== 'month'; }); plTagMgrRender(); }
    else plTagMgrLoad();
}
function plTagRenameApply() {
    var from = plTagSel[0];
    var to = plNormTag(document.getElementById('tmRenameInp').value);
    if (!to || to === from) { plTagModeSet(''); return; }
    fetch(plApiUrl({ module: 'place', action: 'tag_rename', from: from, to: to }))
        .then(function (r) { return r.json(); })
        .then(function (d) { if (!d || !d.ok) { alert((d && d.msg) || '실패'); return; } plHint('“' + from + '” → “' + to + '” 변경'); plTagMgrAfter(d.items); })
        .catch(function () { alert('실패'); });
}
function plTagDeleteSel() {
    var sel = plTagSel.slice(), n = sel.length;
    if (!confirm(n + '개 태그를 모든 장소에서 삭제합니다.\n' + sel.join(', ') + '\n되돌릴 수 없습니다. 삭제할까요?')) return;
    var seq = Promise.resolve(null);
    sel.forEach(function (t) { seq = seq.then(function () { return fetch(plApiUrl({ module: 'place', action: 'tag_delete', tag: t })).then(function (r) { return r.json(); }); }); });
    seq.then(function (d) { plHint(n + '개 태그를 삭제했습니다'); plTagMgrAfter(d && d.items); })
       .catch(function () { alert('삭제 중 오류'); plTagMgrLoad(); });
}
function plTagMergeApply() {
    var main = plTagMergeMain, others = plTagSel.filter(function (t) { return t !== main; });
    if (!others.length) { plTagModeSet(''); return; }
    var seq = Promise.resolve(null);
    others.forEach(function (t) { seq = seq.then(function () { return fetch(plApiUrl({ module: 'place', action: 'tag_rename', from: t, to: main })).then(function (r) { return r.json(); }); }); });
    seq.then(function (d) { plHint(others.length + '개를 “' + main + '”(으)로 병합했습니다'); plTagMgrAfter(d && d.items); })
       .catch(function () { alert('병합 중 오류'); plTagMgrLoad(); });
}

// 주소 → 좌표 (서버 GeoCoder) → 지도 이동 후 검색
function plGeocode() {
    var addr = document.getElementById('addr').value.trim();
    if (!addr || !plReady) return;
    plRegionClear(); plClearTagSel();   // 주소 검색 = 새 위치로 이동 → 시도 고정·칩 필터 해제(브라우즈)
    fetch(plApiUrl({ module: 'place', action: 'geocode', address: addr }))
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.ok) { plHint(d.msg || '주소를 찾을 수 없습니다'); return; }
            plClearOverlay();   // 새 장소로 이동 → 주변 오버레이 해제
            plMap.setCenter(new naver.maps.LatLng(d.lat, d.lng));
            plMap.setZoom(plZoomForRadius(document.getElementById('radius').value));
            plSetSearchMarker(d.lat, d.lng, d.address || addr);
            plSearch(d.lat, d.lng, parseFloat(document.getElementById('radius').value)); // 선택 반경부터 자동 확장
        })
        .catch(function () { plHint('지오코딩 실패'); });
}

// ── 현재 위치 (브라우저 Geolocation → 그 좌표로 이동 + 주변 검색) ──
//  네이버 역지오코딩으로 실제 주소를 검색창·검색마커 라벨에 표시(가능할 때).
//  주의: Geolocation 은 HTTPS(보안 컨텍스트)에서만 동작한다.
function plMyLocation() {
    if (!plReady) return;
    if (!navigator.geolocation) { plHint('이 브라우저는 현재위치를 지원하지 않습니다'); return; }
    plRegionClear(); plClearTagSel();   // 현재위치로 이동 → 시도 고정·칩 필터 해제(브라우즈)
    var btn = document.getElementById('myLocBtn');
    if (btn) btn.classList.add('loading');
    plHint('현재 위치를 확인하는 중…');
    navigator.geolocation.getCurrentPosition(function (pos) {
        if (btn) btn.classList.remove('loading');
        var lat = pos.coords.latitude, lng = pos.coords.longitude;
        var rad = parseFloat(document.getElementById('radius').value);
        plClearOverlay();   // 현재 위치로 이동 → 주변 오버레이 해제
        plMap.setCenter(new naver.maps.LatLng(lat, lng));
        plMap.setZoom(plZoomForRadius(rad));
        // 역지오코딩(주소)은 가능하면 표시하되, 실패해도 검색은 그대로 진행
        plReverseGeocode(lat, lng, function (addr) {
            plSetSearchMarker(lat, lng, addr || '현재 위치');
            document.getElementById('addr').value = addr || '';
            plSearch(lat, lng, rad);   // 선택 반경부터 자동 확장
            plHint(addr ? ('📍 현재 위치: ' + addr) : '📍 현재 위치로 이동');
        });
    }, function (err) {
        if (btn) btn.classList.remove('loading');
        plHint(err && err.code === 1 ? '위치 권한이 거부되었습니다'
             : err && err.code === 3 ? '위치 확인 시간이 초과되었습니다'
             : '현재 위치를 가져올 수 없습니다');
    }, { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 });
}

// 좌표 → 주소 (네이버 reverseGeocode 서브모듈). 미지원/실패 시 빈 문자열로 콜백
function plReverseGeocode(lat, lng, cb) {
    if (!(window.naver && naver.maps && naver.maps.Service && naver.maps.Service.reverseGeocode)) { cb(''); return; }
    naver.maps.Service.reverseGeocode({
        coords: new naver.maps.LatLng(lat, lng),
        orders: [naver.maps.Service.OrderType.ROAD_ADDR, naver.maps.Service.OrderType.ADDR].join(',')
    }, function (status, response) {
        if (status !== naver.maps.Service.Status.OK) { cb(''); return; }
        var addr = '';
        try {
            var a = response.v2.address;
            addr = (a.roadAddress || a.jibunAddress || '').trim();
        } catch (e) {}
        cb(addr);
    });
}

// ── 검색창 자동완성 (카카오 키워드 장소검색) ──
var plAcItems = [], plAcIndex = -1, plAcTimer = null;

function plSuggest(q) {
    q = (q || '').trim();
    clearTimeout(plAcTimer);
    if (q.length < 2) { plAcClose(); return; }
    plAcTimer = setTimeout(function () {       // 디바운스 220ms — 타이핑마다 호출 방지
        // 우리 DB(등록 장소) + 카카오 장소를 동시(병렬) 검색해 한 드롭다운에 병합
        var dbP = fetch(plApiUrl({ module: 'place', action: 'place_name_search', q: q }))
            .then(function (r) { return r.json(); }).catch(function () { return null; });
        var kkP = fetch(plApiUrl({ module: 'place', action: 'suggest', q: q }))
            .then(function (r) { return r.json(); }).catch(function () { return null; });
        Promise.all([dbP, kkP]).then(function (res) {
            var db = (res[0] && res[0].items) || [];
            var kk = (res[1] && res[1].items) || [];
            var items = [];
            db.slice(0, 6).forEach(function (p) {
                items.push({ src: 'db', id: p.id, name: p.name,
                    address: (p.address || [p.region_lv1, p.region_lv2].filter(Boolean).join(' ')),
                    category: (CAT_KO[p.category] || ''), lat: +p.lat, lng: +p.lng });
            });
            kk.slice(0, 7).forEach(function (p) {
                items.push({ src: 'kakao', name: p.name, address: p.address,
                    category: p.category, lat: +p.lat, lng: +p.lng });
            });
            plAcRender(items);
        });
    }, 220);
}

function plAcRender(items) {
    plAcItems = items; plAcIndex = -1;
    var box = document.getElementById('pl-ac');
    if (!items.length) {
        box.innerHTML = '<div class="pl-ac-empty">검색 결과가 없습니다</div>';
        box.classList.add('open'); return;
    }
    var html = '', lastSrc = '';
    items.forEach(function (it, i) {
        if (it.src !== lastSrc) {   // 소스 바뀌면 구역 헤더(📍 내 지도 / 🔍 카카오)
            html += '<div class="pl-ac-grp">' + (it.src === 'db' ? '📍 내 지도 (등록된 곳)' : '🔍 카카오 장소') + '</div>';
            lastSrc = it.src;
        }
        html += '<div class="pl-ac-item" data-i="' + i + '" onmousedown="plAcPick(' + i + ')">' +
            '<div class="pl-ac-name">' + plEsc(it.name) +
                (it.category ? '<span class="pl-ac-cat">' + plEsc(it.category) + '</span>' : '') +
            '</div>' +
            (it.address ? '<div class="pl-ac-addr">' + plEsc(it.address) + '</div>' : '') +
        '</div>';
    });
    box.innerHTML = html;
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

// 후보 선택 (onmousedown: input blur보다 먼저 실행)
//  · 우리 DB 장소(src=db)  → 그 위치로 이동 + 그 마커 강조·상세패널
//  · 카카오 장소(src=kakao) → 그 위치로 이동(빨강 검색마커) + 주변 우리 마커 검색
function plAcPick(i) {
    var it = plAcItems[i]; if (!it || !plReady) return;
    document.getElementById('addr').value = it.name;
    plAcClose();
    plRegionClear(); plClearTagSel();   // 검색창 선택 = 새 위치로 이동 → 시도 고정·칩 필터 해제(브라우즈)
    var rad = parseFloat(document.getElementById('radius').value);
    plMap.setCenter(new naver.maps.LatLng(it.lat, it.lng));
    plMap.setZoom(plZoomForRadius(rad));
    if (it.src === 'db') {
        plPendingFocusId = it.id;                 // 검색 결과 렌더 후 이 장소 마커 강조
        plSearch(it.lat, it.lng, rad);
    } else {
        plSetSearchMarker(it.lat, it.lng, it.name);
        plSearch(it.lat, it.lng, rad);
    }
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

// ══════════════════════════════════════════════════════════
//  여행 경로 만들기 모드 (= 길찾기 통합)
//   · 지도 마커·좌측 목록 클릭(plFocus 가로채기)·주소 직접 입력으로 경로 지점 추가
//     (상세패널 '여기로 길찾기' = 현위치→그 장소 2지점 경로로 진입 — 같은 엔진)
//   · 지점 2곳↑이면 서버 action=route(네이버 Directions)로 실제 도로 경로·소요시간·통행료
//   · '경로 주변' = 경로선에서 반경 안의 (화면에 떠 있는) 장소를 회랑 판정 → 가장 가까운 지점에 묶음
//   · 경로 지점은 클라이언트 전용(localStorage 보존). 주변 판정도 클라이언트(서버 변경 없음).
// ══════════════════════════════════════════════════════════
var rtMode = false;
var rtRoute = [];                 // [{name, lat, lng, address, placeId}]
var rtRouteMarkers = [], rtRoutePolyline = null, rtDragIdx = -1;
var rtRadius = 5;                 // 경로 주변 반경(km) — 경로선까지의 거리
var rtNearby = [];                // 지점별 주변 장소: rtNearby[wi] = [rec...]
var rtNearbyActive = false;       // 종합을 계산했는지
var rtAssign = {};                // 마커 인덱스 → 배정된 지점(반경 안)
var rtFinalized = false;          // '지도 만들기'(영역 밖 마커 숨김) 상태
var RT_WP_MAX = 5;                // 네이버 Directions 경유지 최대 5 → 1콜당 최대 7지점
var rtRoadPath = null;            // [naver.maps.LatLng...] 실제 도로 경로(없으면 직선 fallback)
var rtRouteInfo = null;           // {duration(ms), distance(m), toll(원)}
var rtRouting = false;            // 도로 경로 계산 중
var rtRouteSeq = 0;               // 비동기 경쟁 방지 토큰
var rtSrvFeats = [];              // ★서버 회랑 조회 결과(GeoJSON features) — 경로 주변 장소 원본(필터 무관·DB 전체)
var rtSrvRadius = 0;              // rtSrvFeats 를 받아온 반경(km). 이 이하로 줄이면 재조회 없이 클라 필터
var rtNearbyBusy = false;         // 회랑 조회 중
var rtNearbySeq = 0;              // 회랑 조회 비동기 경쟁 방지 토큰
var rtPicks = [];                 // ⭐찜한 곳(영구·화면 무관 항상 표시·저장 대상) [{id,name,lat,lng,category,address,ref_count,tags,attributes,guides}]
var rtPickMarkers = [];           // 찜 마커 레이어(뷰포트와 무관하게 항상)
var rtSelId = null;               // 현재 선택(강조) 중인 장소 id — 리스트 재렌더·이동 후에도 강조 유지
var rtShareSel = -1;              // 공유(트립) 게스트 슬라이드 바에서 선택된 경로 지점 index
var RT_COLORS = ['#e74c3c','#2980b9','#27ae60','#e67e22','#8e44ad','#16a085','#d35400','#2c3e50','#c0392b','#1abc9c','#9b59b6','#f39c12'];
function rtColor(i) { return RT_COLORS[i % RT_COLORS.length]; }
// 경로 주변 목록 분류 소그룹: 표시 순서 + 분류 색/아이콘(지도 마커색과 동일)
var RT_CAT_SEQ  = ['restaurant', 'stay', 'travel', 'camping', 'etc'];
var RT_CAT_META = {
    restaurant: { ko: '맛집',   ic: '🍴',  col: '#e74c3c' },
    stay:       { ko: '숙소',   ic: '🛏️', col: '#8e44ad' },
    travel:     { ko: '여행지', ic: '🏞️', col: '#3498db' },
    camping:    { ko: '캠핑장', ic: '⛺',  col: '#27ae60' },
    etc:        { ko: '기타',   ic: '📍',  col: '#7f8c8d' }
};

function rtToggleMode() {
    // 종료(끄기) 시 데이터가 있으면 확인 — 종료 = 초기화(경로·찜 모두 비움). 저장한 여행지도는 보존.
    if (rtMode && (rtRoute.length || rtPicks.length)) {
        if (!confirm('경로 만들기를 종료하고 경로·찜을 모두 비울까요?\n(저장한 여행지도는 그대로 남아 있습니다)')) return;
    }
    rtMode = !rtMode;
    document.body.classList.toggle('rt-on', rtMode);
    document.body.classList.remove('rt-collapsed');   // 모드 진입/종료는 항상 펼친 상태로 시작
    rtSyncTabArrow();
    var b = document.getElementById('rtModeBtn');
    if (b) { b.classList.toggle('active', rtMode); b.textContent = rtMode ? '🧭 경로 만들기 종료' : '🧭 여행 경로 만들기'; }
    if (rtMode) {
        // ★진입 기본 = 주변 미가동(rtFinalized). 경로 지점만 추가·도로 경로만 그림.
        //  '추천 찜 담기' 또는 '주변 다시 보기'를 눌러야 비로소 경로 주변(회랑) 스캔이 돈다.
        //  (불러오기/길찾기 경로가 이미 true 로 세팅했으면 그대로 유지)
        rtFinalized = true;
        plClosePanel();              // 상세패널 닫고 경로패널로
        plToggleList(false);         // 좌측은 '주변 종합' 패널 차지 → 검색 목록 닫음
        rtUpdateFinalBtn();          // 진입 시 버튼 라벨 동기화(주변 미가동 → '주변 다시 보기')
        plExitOverlay();             // ★베이스 마커(현재 검색결과)는 유지 — 줌 오버레이만 정리. 베이스 숨김은 엔진 가동(추천/주변보기) 때로 미룸
        rtUpdateTripName();          // 불러온 여행지도 이름 표시(있으면)
        rtRenderRows();
        rtDrawPicks();               // ⭐ 찜한 곳 마커(영구·화면 무관) 표시
        rtFetchRoute();              // 도로 경로만 즉시(회랑 스캔은 rtFinalized 라 skip)
        plHint('경로 지점을 추가한 뒤 “추천 찜 담기”를 누르면 주변 맛집·숙소·여행지를 찾습니다');
        setTimeout(plBumpResize, 60);
    } else {
        // 종료 = 초기화: 경로·찜·이름 전부 비우고 localStorage 도 비움(다음 진입 시 깨끗)
        rtRoute = []; rtSave();
        rtPicks = []; rtPicksSave();
        rtNearby = []; rtFinalized = false; rtSelId = null; rtShareSel = -1; rtLoadedName = ''; rtUpdateTripName();
        rtRoadPath = null; rtRouteInfo = null; rtRouting = false; rtRouteSeq++;
        rtSrvFeats = []; rtSrvRadius = 0; rtNearbyBusy = false; rtNearbySeq++;
        rtClearLayer(); plExitOverlay(); rtClearPicks(); rtRestoreMarkers();  // 경로/주변·찜마커 제거 + 베이스 복원
        rtRenderRows();     // 비운 경로 목록 반영
        plUpdateLabels();   // 베이스 라벨 복원(rtMode 해제됐으므로 plFeatures 기준)
        setTimeout(plBumpResize, 60);
    }
}

// 패널 접기/펼치기 — 경로·찜은 그대로 두고 패널만 슬라이드(모바일에서 지도 가림 해소)
// 캘린더 #proj-panel-tab 패턴: 얇은 화살표 탭만 남기고 패널 폭(모바일=높이)을 0으로
function rtCollapse() {
    document.body.classList.toggle('rt-collapsed');
    rtSyncTabArrow();
    setTimeout(plBumpResize, 240);   // 슬라이드 끝난 뒤 지도 리사이즈
}
// 탭 화살표를 현재 상태·뷰포트(가로/세로)에 맞게 갱신
function rtSyncTabArrow() {
    var tab = document.getElementById('rtDockTab');
    if (!tab) return;
    var mob = window.matchMedia('(max-width:640px)').matches;
    var col = document.body.classList.contains('rt-collapsed');
    // 접힘=다시 열기 방향, 펼침=접기 방향. 모바일은 상/하, 데스크톱은 좌/우
    tab.textContent = col ? (mob ? '▲' : '◀') : (mob ? '▼' : '▶');
}

// 검색 결과(마커/리스트 항목)를 경로에 추가
function rtAddFromFeature(f) {
    var pr = f.properties, co = f.geometry.coordinates;   // [lng, lat]
    plMap.panTo(new naver.maps.LatLng(co[1], co[0]));
    rtAdd({ name: pr.name, lat: co[1], lng: co[0], address: pr.address || '', placeId: pr.id });
}

// 상세패널에 띄운 장소(주변 종합에서 클릭)를 경로에 추가
function rtAddCurrent() {
    var pr = plPanelPlace;
    if (!pr || pr.lat == null || pr.lng == null) return;
    rtAdd({ name: pr.name, lat: +pr.lat, lng: +pr.lng, address: pr.address || '', placeId: pr.id });
    plClosePanel();
}

// 상세패널 '🧭 여기로 길찾기' → 현위치=출발, 이 장소=도착 으로 경로 모드 진입(같은 엔진)
function rtNavHere() {
    var pr = plPanelPlace;
    if (!pr || pr.lat == null || pr.lng == null) { alert('좌표가 없는 장소입니다.'); return; }
    var dest = { name: pr.name, lat: +pr.lat, lng: +pr.lng, address: pr.address || '', placeId: pr.id };
    plClosePanel();
    rtPicks = []; rtPicksSave(); rtSelId = null; rtLoadedName = ''; rtUpdateTripName();   // 새 길찾기 = 새 여행 → 저장 안 한 기존 찜·이름 비움
    rtRoute = [dest]; rtSave();       // 옛 경로 버리고 도착지만 — GPS 오면 출발지 추가
    if (!rtMode) rtToggleMode();     // 경로 모드 진입(진입이 rtRenderRows+rtFetchRoute 수행)
    else { rtRenderRows(); rtFetchRoute(); }
    plHint('출발지(현재 위치) 확인 중…');
    var setOrigin = function (origin) {
        if (origin) rtRoute = [origin, dest];
        rtRenderRows(); rtSave(); rtFetchRoute();
        if (!origin) plHint('출발지를 추가하세요 (주소 입력 또는 지도 마커 클릭)');
    };
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function (pos) {
            var la = pos.coords.latitude, lo = pos.coords.longitude;
            plReverseGeocode(la, lo, function (addr) {
                setOrigin({ name: addr || '현재 위치', lat: la, lng: lo, address: addr || '' });
            });
        }, function () { setOrigin(null); }, { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 });
    } else { setOrigin(null); }
}

// 주소·장소명 직접 입력 → 지오코딩 → 추가
function rtAddrAdd() {
    var inp = document.getElementById('rtAddr');
    var q = (inp.value || '').trim();
    if (!q || !plReady) return;
    plHint('“' + q + '” 좌표 변환 중…');
    fetch(plApiUrl({ module: 'place', action: 'geocode', address: q }))
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.ok || d.lat == null) { plHint('좌표를 찾지 못했습니다'); return; }
            plMap.setCenter(new naver.maps.LatLng(+d.lat, +d.lng));
            rtAdd({ name: d.address || q, lat: +d.lat, lng: +d.lng, address: d.address || q });
            inp.value = '';
        })
        .catch(function () { plHint('지오코딩 실패'); });
}

function rtAdd(wp) {
    if (!wp || wp.lat == null || wp.lng == null) return;
    var last = rtRoute[rtRoute.length - 1];
    if (last && wp.placeId && last.placeId === wp.placeId) { plHint('이미 마지막 경로 지점입니다'); return; }
    rtRoute.push(wp);
    rtRenderRows(); rtSave(); rtFetchRoute();
    plHint('경로 ' + rtRoute.length + '번째 추가: ' + wp.name);
}
function rtRemove(i) { rtRoute.splice(i, 1); rtRenderRows(); rtSave(); rtFetchRoute(); }
function rtClear() {
    if ((rtRoute.length || rtPicks.length) && !confirm('경로와 찜한 곳을 모두 비울까요?')) return;
    rtRoute = []; rtNearby = []; rtSelId = null; rtShareSel = -1; rtLoadedName = ''; rtUpdateTripName();
    rtPicks = []; rtPicksSave(); rtClearPicks();   // ⭐ 찜도 함께 비움(전체 삭제)
    rtRoadPath = null; rtRouteInfo = null; rtRouting = false; rtRouteSeq++;
    rtSrvFeats = []; rtSrvRadius = 0; rtNearbyBusy = false; rtNearbySeq++;
    plExitOverlay();                           // 경로 모드 유지 — 베이스는 계속 숨김
    rtRenderRows(); rtRenderSummary(); rtDraw(); rtSave();
}

function rtRenderRows() {
    var box = document.getElementById('rtRows');
    if (!box) return;
    var cnt = document.getElementById('rtRowsCnt'); if (cnt) cnt.textContent = rtRoute.length ? (rtRoute.length + '곳') : '';
    if (!rtRoute.length) {
        box.innerHTML = '<div class="rt-empty">아직 경로가 없습니다.<br>지도 마커·좌측 목록을 클릭하거나<br>위 칸에 주소를 입력해 추가하세요.</div>';
        return;
    }
    box.innerHTML = rtRoute.map(function (wp, i) {
        return '<div class="rt-row" id="rt-row-' + i + '" draggable="true" data-i="' + i + '"' +
                   ' ondragstart="rtDragStart(event,' + i + ')" ondragover="rtDragOver(event,' + i + ')"' +
                   ' ondragleave="rtDragLeave(event)" ondrop="rtDrop(event,' + i + ')" ondragend="rtDragEnd(event)">' +
                '<span class="rt-handle" title="드래그하여 순서변경">≡</span>' +
                '<span class="rt-num" style="background:' + rtColor(i) + '">' + (i + 1) + '</span>' +
                '<div class="rt-info"><div class="rt-name">' + plEsc(wp.name) + '</div>' +
                    (wp.address ? '<div class="rt-addr">' + plEsc(wp.address) + '</div>' : '') +
                '</div>' +
                '<button class="rt-del" title="삭제" onclick="rtRemove(' + i + ')">×</button>' +
            '</div>';
    }).join('');
}

// 경로 번호 마커 + 동선 polyline (검색 마커 위에 겹침)
function rtDraw() {
    rtClearLayer();
    if (!rtMode || !plReady) return;
    var path = [];
    rtRoute.forEach(function (wp, i) {
        var pos = new naver.maps.LatLng(wp.lat, wp.lng);
        path.push(pos);
        var actCls = (rtShareSel === i) ? ' act' : '';
        var mk = new naver.maps.Marker({
            position: pos, map: plMap, zIndex: (rtShareSel === i ? 500 : 400), title: (i + 1) + '. ' + wp.name,
            icon: { content: '<div class="rt-pin' + actCls + '" style="background:' + rtColor(i) + '"><b>' + (i + 1) + '</b></div>', anchor: new naver.maps.Point(15, 30) }
        });
        (function (idx, p) {
            naver.maps.Event.addListener(mk, 'click', function () { rtRouteMarkerClick(idx, p); });
        })(i, pos);
        rtRouteMarkers.push(mk);
    });
    // 도로 경로(rtRoadPath)가 있으면 그걸로, 없으면 지점 직선 fallback
    var useRoad = rtRoadPath && rtRoadPath.length >= 2;
    var line = useRoad ? rtRoadPath : (path.length >= 2 ? path : null);
    if (line) {
        rtRoutePolyline = new naver.maps.Polyline({
            map: plMap, path: line,
            strokeColor: useRoad ? '#e8412e' : '#5a4cd0',   // 실제 도로=빨강, 직선 fallback=보라
            strokeWeight: useRoad ? 6 : 5, strokeOpacity: useRoad ? 0.9 : 0.85,
            strokeLineCap: 'round', strokeLineJoin: 'round', zIndex: 350
        });
    }
}

// 경로 마커 클릭 — 소유자(편집)는 경로 지점 행 강조, 공유(트립) 게스트는 슬라이드 바와 연동
function rtRouteMarkerClick(idx, pos) {
    if (PL_TRIP) { rtShareFocus(idx); return; }
    plMap.panTo(pos);
    var wp = rtRoute[idx];
    if (wp) { rtSelId = wp.placeId || null; rtShareOpenDetail(wp); }   // 경로 번호핀 클릭 = 그 지점 상세패널(placeId면 풀 상세, 없으면 이름·주소) — 찜/주변 마커와 일관
    var r = document.getElementById('rt-row-' + idx);
    if (r) { r.scrollIntoView({ block: 'nearest' }); r.style.background = '#efe9ff'; setTimeout(function () { r.style.background = ''; }, 700); }
}

// 공유(트립) 게스트 — 전체 경로를 지도 하단 가로 슬라이드 바로 표시
function rtRenderShareBar() {
    var wrap = document.getElementById('rt-sharewrap'), bar = document.getElementById('rt-sharebar');
    if (!wrap || !bar) return;
    if (!(PL_TRIP && rtMode && rtRoute.length)) { wrap.classList.remove('on'); bar.innerHTML = ''; return; }
    bar.innerHTML = rtRoute.map(function (wp, i) {
        return '<div class="rt-sb-card' + (i === rtShareSel ? ' active' : '') + '" id="rt-sb-' + i + '" onclick="rtShareFocus(' + i + ')">' +
                '<span class="rt-sb-num" style="background:' + rtColor(i) + '">' + (i + 1) + '</span>' +
                '<div class="rt-sb-info"><div class="rt-sb-name">' + plEsc(wp.name) + '</div>' +
                    (wp.address ? '<div class="rt-sb-addr">' + plEsc(wp.address) + '</div>' : '') +
                '</div>' +
            '</div>';
    }).join('');
    wrap.classList.add('on');
    rtShareNavState();
    rtRenderShareInfo();
}

// 공유(트립) 게스트 — 경로 요약(지점 수 · 🚗 소요시간 · 거리 · 통행료) 줄
function rtRenderShareInfo() {
    var el = document.getElementById('rt-shareinfo'); if (!el) return;
    if (!(PL_TRIP && rtMode && rtRoute.length)) { el.classList.remove('on'); el.innerHTML = ''; return; }
    var stops = '📍 ' + rtRoute.length + '개 지점';
    var body;
    if (rtRoute.length < 2)   body = '<span class="rti-muted">' + stops + '</span>';
    else if (rtRouting)       body = stops + ' · <span class="rti-muted">🚗 경로 계산 중…</span>';
    else if (rtRouteInfo)     body = stops + ' · ' + rtFmtRoute(rtRouteInfo);
    else                      body = stops + ' · <span class="rti-muted">직선 거리 기준</span>';
    el.innerHTML = body;
    el.classList.add('on');
}

// 이전/다음 버튼 활성 상태 갱신
function rtShareNavState() {
    var prev = document.getElementById('rtSbPrev'), next = document.getElementById('rtSbNext');
    if (prev) prev.disabled = (rtShareSel <= 0);
    if (next) next.disabled = (rtShareSel >= rtRoute.length - 1);
}

// ‹ / › 이전·다음 지점으로 한 칸 이동(처음엔 1번 지점부터)
function rtShareStep(dir) {
    if (!rtRoute.length) return;
    var i = (rtShareSel < 0) ? (dir > 0 ? 0 : 0) : rtShareSel + dir;
    if (i < 0 || i >= rtRoute.length) return;
    rtShareFocus(i);
}

// 슬라이드 카드/경로 마커 클릭 → 그 지점으로 이동 + 카드·마커 강조 + 상세보기 패널
function rtShareFocus(i) {
    var wp = rtRoute[i]; if (!wp || !plMap) return;
    rtShareSel = i;
    plMap.panTo(new naver.maps.LatLng(wp.lat, wp.lng));
    rtDraw();             // 선택 경로 마커 강조 반영
    rtRenderShareBar();   // 선택 카드 강조 반영
    var card = document.getElementById('rt-sb-' + i);
    if (card && card.scrollIntoView) { try { card.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' }); } catch (e) {} }
    rtShareOpenDetail(wp);
}

// 경로 지점 상세보기 — placeId 있으면 서버에서 전체 정보(분류·태그·네이버·출처), 없으면 이름·주소만
function rtShareOpenDetail(wp) {
    var fallback = { id: wp.placeId || null, name: wp.name, address: wp.address || '', lat: wp.lat, lng: wp.lng, category: 'etc', tags: [] };
    plOpenById(wp.placeId, wp.lat, wp.lng, fallback);   // 단일 경로
}

// 지점 2곳↑이면 서버 action=route(네이버 Directions)로 실제 도로 경로를 받아 그린다.
// 7지점(경유지5) 초과 시 구간을 나눠 순차 호출 후 경로를 이어붙임. 실패 시 직선 fallback.
function rtFetchRoute() {
    if (!rtMode) return;
    rtRoadPath = null; rtRouteInfo = null;
    if (rtRoute.length < 2) { rtRouting = false; rtRouteSeq++; rtSrvFeats = []; rtSrvRadius = 0; rtNearbySeq++; rtDraw(); rtRefresh(); return; }
    var seq = ++rtRouteSeq;
    rtRouting = true;
    rtDraw(); rtRefresh();           // 즉시 직선+핀 표시(도로 경로는 비동기로 갱신)
    var stops = rtRoute.slice(), chunks = [];
    for (var s = 0; s < stops.length - 1; s += (RT_WP_MAX + 1))
        chunks.push(stops.slice(s, Math.min(stops.length, s + RT_WP_MAX + 2)));
    var path = [], info = { duration: 0, distance: 0, toll: 0 }, failed = false;
    var chain = Promise.resolve();
    chunks.forEach(function (ck) {
        chain = chain.then(function () {
            if (failed) return;
            var a = ck[0], z = ck[ck.length - 1], mids = ck.slice(1, ck.length - 1);
            var params = { module: 'place', action: 'route', start: a.lng + ',' + a.lat, goal: z.lng + ',' + z.lat };
            if (mids.length) params.waypoints = mids.map(function (m) { return m.lng + ',' + m.lat; }).join('|');
            return fetch(plApiUrl(params)).then(function (r) { return r.json(); }).then(function (d) {
                if (!d || !d.ok || !d.path || d.path.length < 2) { failed = true; return; }
                d.path.forEach(function (p) { path.push(new naver.maps.LatLng(p[1], p[0])); });
                info.duration += d.duration || 0; info.distance += d.distance || 0; info.toll += d.toll || 0;
            });
        });
    });
    chain.then(function () {
        if (seq !== rtRouteSeq) return;          // 더 최신 요청이 있으면 폐기
        rtRouting = false;
        if (!failed && path.length >= 2) { rtRoadPath = path; rtRouteInfo = info; }
        rtDraw(); rtRefresh();
        rtFetchNearby();                         // 경로 확정 → 서버 회랑 주변검색
    }).catch(function () {
        if (seq !== rtRouteSeq) return;
        rtRouting = false; rtDraw(); rtRefresh();
        rtFetchNearby();                         // 도로 실패해도 직선 경로로 주변검색
    });
}

// 서버 회랑 주변검색: 경로 폴리라인을 서버로 보내 DB 전체에서 경로선 N km 이내 장소를 받음.
//  ★지도 필터(지역·분류·줌)와 무관. 넉넉히(≥10km) 받아두고 반경 축소는 클라에서 즉시 필터.
function rtFetchNearby() {
    if (!rtMode) return;
    if (rtFinalized) { rtSrvFeats = []; rtSrvRadius = 0; rtNearbySeq++; rtRefresh(); return; }   // 찜·경로만 보기 = 주변 조회 안 함
    if (rtRoute.length < 2) { rtSrvFeats = []; rtSrvRadius = 0; rtNearbySeq++; rtRefresh(); return; }
    var raw = (rtRoadPath && rtRoadPath.length >= 2)
        ? rtRoadPath.map(function (ll) { return [ll.lng(), ll.lat()]; })   // [lng,lat]
        : rtRoute.map(function (w) { return [w.lng, w.lat]; });
    // ★점 개수는 '경로 길이에 비례'(약 2km 간격) — 길이 무관하게 선분≈2km 유지. 짧으면 적게(빠름), 500km면 촘촘히(정확).
    //  고정 개수면 길수록 선분이 길어져(500km/120≈4km) 코너 컷 오차가 커짐.
    var routeKm = (rtRouteInfo && rtRouteInfo.distance) ? rtRouteInfo.distance / 1000 : 0;
    if (!routeKm) { for (var k = 1; k < rtRoute.length; k++) routeKm += rtHaversine(rtRoute[k-1].lat, rtRoute[k-1].lng, rtRoute[k].lat, rtRoute[k].lng); }
    var maxPts = Math.max(40, Math.min(250, Math.round(routeKm / 2) || 40));   // 2km 간격·40~250점
    var pts = raw;
    if (raw.length > maxPts) {
        var stride = Math.ceil(raw.length / maxPts), t = [];
        for (var i = 0; i < raw.length; i += stride) t.push(raw[i]);
        t.push(raw[raw.length - 1]); pts = t;
    }
    var fetchRad = Math.max(rtRadius, 10);
    // ★뷰포트 증분: 현재 화면 bbox 를 함께 보내 화면 ∩ 회랑만 조회(장거리도 가볍게). 이동/줌 시 idle 에서 재조회.
    var body = { path: JSON.stringify(pts), radius: String(fetchRad), limit: '1200' };
    try {
        var b = plMap.getBounds(), sw = b.getSW(), ne = b.getNE();
        body.minLat = String(sw.lat()); body.maxLat = String(ne.lat());
        body.minLng = String(sw.lng()); body.maxLng = String(ne.lng());
    } catch (e) {}
    var seq = ++rtNearbySeq;
    rtNearbyBusy = true; rtRenderSummary();
    fetch(plApiUrl({ module: 'place', action: 'route_nearby' }), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(body)
    }).then(function (r) { return r.json(); }).then(function (geo) {
        if (seq !== rtNearbySeq) return;
        rtNearbyBusy = false;
        rtSrvFeats = (geo && geo.features) || [];
        rtSrvRadius = fetchRad;
        rtRefresh();
    }).catch(function () {
        if (seq !== rtNearbySeq) return;
        rtNearbyBusy = false; rtSrvFeats = []; rtSrvRadius = 0; rtRefresh();
    });
}
function rtClearLayer() {
    rtRouteMarkers.forEach(function (m) { m.setMap(null); });
    rtRouteMarkers = [];
    if (rtRoutePolyline) { rtRoutePolyline.setMap(null); rtRoutePolyline = null; }
}

// 드래그 순서변경
function rtDragStart(e, i) { rtDragIdx = i; e.dataTransfer.effectAllowed = 'move'; e.currentTarget.classList.add('dragging'); }
function rtDragOver(e, i) { e.preventDefault(); if (i !== rtDragIdx) e.currentTarget.classList.add('drag-over'); }
function rtDragLeave(e) { e.currentTarget.classList.remove('drag-over'); }
function rtDrop(e, i) {
    e.preventDefault(); e.currentTarget.classList.remove('drag-over');
    if (rtDragIdx < 0 || rtDragIdx === i) return;
    var m = rtRoute.splice(rtDragIdx, 1)[0];
    rtRoute.splice(i, 0, m);
    rtDragIdx = -1;
    rtRenderRows(); rtSave(); rtFetchRoute();
}
function rtDragEnd(e) { rtDragIdx = -1; document.querySelectorAll('#rtRows .rt-row').forEach(function (r) { r.classList.remove('dragging', 'drag-over'); }); }

// ── 반경 컨트롤 ──
//  서버 회랑은 넉넉히(≥10km) 받아두므로, 받아온 반경 이하로 줄이면 즉시 클라 필터(rtRefresh).
//  더 넓히면 서버 재조회(rtFetchNearby).
function rtRadiusChanged() {
    if (rtRoute.length >= 2 && rtRadius > rtSrvRadius) rtFetchNearby();
    else rtRefresh();
}
function rtSetRadius(km) {
    rtRadius = km;
    document.querySelectorAll('.rt-rq').forEach(function (b) { b.classList.toggle('on', +b.getAttribute('data-r') === km); });
    rtRadiusChanged();
}

// 거리(km) — Haversine
function rtHaversine(la1, lo1, la2, lo2) {
    var R = 6371, d2r = Math.PI / 180;
    var dLa = (la2 - la1) * d2r, dLo = (lo2 - lo1) * d2r;
    var a = Math.sin(dLa / 2) * Math.sin(dLa / 2) +
            Math.cos(la1 * d2r) * Math.cos(la2 * d2r) * Math.sin(dLo / 2) * Math.sin(dLo / 2);
    return 2 * R * Math.asin(Math.min(1, Math.sqrt(a)));
}


// ── 경로 주변 종합 (현재 화면 회랑 ∪ 찜한 곳) ──
//  ① rtSrvFeats(현재 화면 ∩ 경로선 N km 이내) + ② 찜한 곳(화면 밖이어도 항상 유지)
//  → 가장 가까운 지점(waypoint)에 묶고, 그 지점에서의 직선거리순 정렬. 지도 필터와 무관.
function rtComputeNearby() {
    rtNearby = rtRoute.map(function () { return []; });
    if (!rtRoute.length) return;
    var seen = {};
    // 경로 지점 자신(등록 장소=placeId)은 주변 목록에서 제외 — 지점 헤더로 이미 표시되므로 ~0m 중복 방지
    var wpIds = {};
    for (var w0 = 0; w0 < rtRoute.length; w0++) { if (rtRoute[w0].placeId != null) wpIds[rtRoute[w0].placeId] = 1; }
    function addRec(lat, lng, props) {
        if (lat == null || lng == null || seen[props.id]) return;
        if (props.id != null && wpIds[props.id]) return;   // 경로 지점 자신 제외(헤더=상세 진입점)
        var best = 0, bestD = Infinity;
        for (var w = 0; w < rtRoute.length; w++) {
            var dw = rtHaversine(lat, lng, rtRoute[w].lat, rtRoute[w].lng);
            if (dw < bestD) { bestD = dw; best = w; }
        }
        rtNearby[best].push(Object.assign({}, props, { lat: lat, lng: lng, dist: bestD }));
        seen[props.id] = 1;
    }
    // ① 현재 화면 회랑(반경 안) — '찜·경로만 보기'(rtFinalized)면 건너뜀(찜만)
    if (!rtFinalized) for (var i = 0; i < rtSrvFeats.length; i++) {
        var pr = rtSrvFeats[i].properties, co = rtSrvFeats[i].geometry.coordinates;
        var d = (pr.dist_km != null) ? +pr.dist_km : 0;
        if (d > rtRadius) continue;
        addRec(co[1], co[0], pr);
    }
    // ② 찜한 곳 — 화면 밖이어도 목록에서 안 사라지게 항상 포함(이미 있으면 skip)
    for (var k = 0; k < rtPicks.length; k++) addRec(rtPicks[k].lat, rtPicks[k].lng, rtPicks[k]);
    // 정렬: ★찜 먼저(1순위) → 그 다음 거리순. 찜해도 항상 그룹 맨 위라 위치가 안 흔들림.
    rtNearby.forEach(function (a) {
        a.sort(function (x, y) {
            var px = rtIsPicked(x.id) ? 0 : 1, py = rtIsPicked(y.id) ? 0 : 1;
            if (px !== py) return px - py;
            return x.dist - y.dist;
        });
    });
}

// 경로 주변 = 기존 '줌 오버레이'와 완전히 동일한 레이어(plOv/plOvFeats)로 렌더.
//  마커=plProxIcon(맛집 포크·캠핑 텐트…), 클릭=plProxPick(상세패널), 라벨=plUpdateLabels(겹치면 말풍선 클러스터).
function rtRenderNearby() {
    plClearOverlay();                    // 기존 오버레이 마커 제거
    plOvFeats = [];
    if (!rtMode || rtFinalized) { plUpdateLabels(); return; }   // '마크 숨기기'면 주변 마커·라벨 모두 없음
    var inr = [];
    for (var i = 0; i < rtSrvFeats.length; i++) {
        var dk = rtSrvFeats[i].properties.dist_km;
        if (dk == null || +dk <= rtRadius) inr.push(rtSrvFeats[i]);   // 반경 안만(반경 축소 즉시반영)
    }
    inr = plSortFeatures(inr);           // 기존 오버레이와 동일 정렬(분류 묶음 + 맛집 리뷰순)
    inr.forEach(function (f, i) {
        var co = f.geometry.coordinates, pr = f.properties;
        var mk = new naver.maps.Marker({
            position: new naver.maps.LatLng(co[1], co[0]), map: plMap,
            title: pr.name, zIndex: 90, icon: plProxIcon(pr)
        });
        naver.maps.Event.addListener(mk, 'click', (function (idx) { return function () { plProxPick(idx); }; })(i));
        plOv.push(mk);
    });
    plOvFeats = inr;                      // plProxPick·plUpdateLabels 가 참조
    plUpdateLabels();                     // 겹치면 말풍선으로 묶어 전부 표시 — 기존 로직 그대로
}
// 베이스 마커만 숨김(경로 모드 진입/새검색 시). plFeatures 데이터는 보존 → 종료 시 복원.
function rtHideBaseMarkers() {
    for (var i = 0; i < plMarkers.length; i++) { if (plMarkers[i]) plMarkers[i].setMap(null); }
}
// 경로 모드 진입: 베이스 마커 숨김 + 기존 오버레이/라벨 초기화(경로 주변으로 새로 채움)
function rtHideBase() {
    rtHideBaseMarkers();
    if (typeof plExitOverlay === 'function') plExitOverlay();
    if (typeof plClearLabels === 'function') plClearLabels();
}
// 경로 모드 종료 시: 베이스 마커 복원
function rtRestoreMarkers() {
    for (var i = 0; i < plFeatures.length; i++) {
        var mk = plMarkers[i]; if (!mk) continue;
        mk.setMap(plMap);
        mk.setIcon(plMarkerIcon(plFeatures[i].properties.category, i + 1, false, plMkColor(plFeatures[i].properties), plMkBadge(plFeatures[i].properties)));
        mk.setZIndex(100);
    }
}

// 경로/반경/회랑결과 변경 시 자동 갱신(즉시)
function rtRefresh() {
    if (!rtMode) return;
    rtNearbyActive = true;
    rtComputeNearby();    // 우측 패널(지점별 그룹) 계산
    rtRenderNearby();     // 지도 마커+라벨 = 기존 오버레이 로직 그대로
    rtRenderSummary();
    rtRenderShareInfo();  // 공유 게스트 경로 요약(거리/시간) 갱신
}

// '찜·경로만 보기' 토글 — 줌인 시 뜨는 주변(회랑)을 지도·목록에서 모두 숨기고 경로+찜만. (저장 후 보기 좋음)
function rtFinalize() {
    rtFinalized = !rtFinalized;
    rtUpdateFinalBtn();
    if (rtFinalized) {
        rtSrvFeats = []; rtSrvRadius = 0; rtNearbySeq++;   // 회랑 비우고 서버 호출도 멈춤
        rtRefresh();
        plHint('⭐ 찜·경로만 표시합니다 (주변 숨김)');
    } else {
        rtHideBase();      // 주변 가동 = 베이스 마커 숨기고 회랑으로 교체
        rtFetchNearby();   // 주변 회랑 다시 로드
        plHint('주변 장소를 다시 표시합니다');
    }
}
function rtUpdateFinalBtn() {
    var b = document.getElementById('rtFinalBtn');
    if (!b) return;
    b.textContent = rtFinalized ? '👁️ 주변 다시 보기' : '⭐ 찜·경로만';   // 라벨 = 누르면 될 상태
    b.classList.toggle('on', rtFinalized);
}

// 🌟 리뷰 많은 맛집 자동 추천 — 서버(경로 전 구간 회랑, 뷰포트 무관)에서 review_count DESC 로 받아
//  각 경로 지점(waypoint)에 가장 가까운 곳끼리 묶어 지점당 상위 N개를 ⭐찜에 추가(기존 찜 유지·중복 제외)
function rtRevOf(f) {
    var p = (f && f.properties) || {};
    if (p.review_count != null) return Number(p.review_count) || 0;
    var nv = p.attributes && p.attributes.naver;
    return (nv && nv.review) ? Number(nv.review) : 0;
}
// 분류별 순위값 — 맛집·숙소·캠핑=리뷰수, 여행지 등=기사수(ref_count)
function rtRankVal(f) {
    var p = (f && f.properties) || {};
    var c = p.category;
    if (c === 'restaurant' || c === 'stay' || c === 'camping') return rtRevOf(f);
    return Number(p.ref_count) || 0;
}
// 추천 분류 칩 토글
//  · 끄기 = 그 분류의 '추천 찜(_rec)'만 즉시 제거. 내가 직접 ☆ 찜한 곳은 보존.
//  · 켜기 = 다음 '추천 찜 담기'에 포함(즉시 추가하진 않음). 최소 1개는 켜져 있어야 함.
function rtRecCatToggle(btn) {
    var cat = btn.getAttribute('data-cat');
    var on = btn.classList.contains('on');
    if (on) {
        if (document.querySelectorAll('.rt-recq.on').length <= 1) { plHint('추천 분류를 최소 1개는 선택해야 합니다'); return; }
        btn.classList.remove('on');
        var before = rtPicks.length;
        rtPicks = rtPicks.filter(function (p) { return !(p._rec && p.category === cat); });   // 추천 찜만 제거·직접 찜 보존
        var removed = before - rtPicks.length;
        var ko = CAT_KO[cat] || '기타';
        if (removed) {
            rtPicksSave(); rtDrawPicks(); plUpdateLabels(); rtRenderSummary();
            plHint(ko + ' 추천 찜 ' + removed + '곳 제거 (직접 찜은 유지)');
        } else {
            plHint(ko + ' 분류 추천 제외 (제거할 추천 찜 없음·직접 찜은 유지)');
        }
    } else {
        btn.classList.add('on');
        plHint((CAT_KO[cat] || '기타') + ' 분류 추천 포함 — “추천 찜 담기”를 누르면 채워집니다');
    }
}
function rtRecSelectedCats() {
    var cats = [];
    document.querySelectorAll('.rt-recq.on').forEach(function (b) { cats.push(b.getAttribute('data-cat')); });
    return cats.length ? cats : ['restaurant'];
}
function rtRecommend() {
    if (rtRoute.length < 2) { plHint('경로 지점을 2곳 이상 추가한 뒤 추천을 눌러 주세요'); return; }
    var per = parseInt((document.getElementById('rtRecN') || {}).value, 10);
    if (isNaN(per) || per < 1) per = 5;
    per = Math.min(20, per);
    var cats = rtRecSelectedCats();
    // ★엔진 가동: 여태 미가동(rtFinalized)이었으면 이때 베이스 마커를 숨기고 주변 회랑 스캔을 켠다(좌측 '경로 주변 장소' 채움)
    if (rtFinalized) { rtFinalized = false; rtUpdateFinalBtn(); rtHideBase(); rtFetchNearby(); }
    // 경로선(도로경로 우선, 없으면 지점 직선)을 보냄 — 서버가 회랑 판정
    var raw = (rtRoadPath && rtRoadPath.length >= 2)
        ? rtRoadPath.map(function (ll) { return [ll.lng(), ll.lat()]; })
        : rtRoute.map(function (w) { return [w.lng, w.lat]; });
    var maxPts = 250;
    if (raw.length > maxPts) {
        var stride = Math.ceil(raw.length / maxPts), t = [];
        for (var i = 0; i < raw.length; i += stride) t.push(raw[i]);
        t.push(raw[raw.length - 1]); raw = t;
    }
    var btn = document.getElementById('rtRecBtn');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ 추천 찾는 중…'; }
    plHint('경로 전 구간에서 유명한 곳을 찾는 중…');
    var body = { path: JSON.stringify(raw), radius: String(Math.max(rtRadius, 3)), cats: cats.join(','), limit: '600' };
    fetch(plApiUrl({ module: 'place', action: 'route_recommend' }), {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(body)
    }).then(function (r) { return r.json(); }).then(function (geo) {
        if (btn) { btn.disabled = false; btn.textContent = '🌟 추천 찜 담기'; }
        var feats = (geo && geo.features) || [];
        if (!feats.length) { plHint('추천할 곳을 찾지 못했습니다 (반경·분류를 바꿔 보세요)'); return; }
        // 각 후보를 가장 가까운 경로 지점 그룹에 배정
        var groups = rtRoute.map(function () { return []; });
        feats.forEach(function (f) {
            var co = f.geometry.coordinates, la = co[1], lo = co[0];
            var best = 0, bestD = Infinity;
            for (var w = 0; w < rtRoute.length; w++) {
                var dw = rtHaversine(la, lo, rtRoute[w].lat, rtRoute[w].lng);
                if (dw < bestD) { bestD = dw; best = w; }
            }
            groups[best].push(f);
        });
        // 지점별 × 분류별로 상위 per개 → 찜 추가(중복 제외)
        var added = 0, dup = 0;
        groups.forEach(function (arr) {
            cats.forEach(function (cat) {
                var catFeats = arr.filter(function (f) { return f.properties.category === cat; });
                catFeats.sort(function (a, b) { return rtRankVal(b) - rtRankVal(a); });
                catFeats.slice(0, per).forEach(function (f) {
                    var pr = f.properties, id = pr.id;
                    if (id == null) return;
                    if (rtIsPicked(id)) { dup++; return; }
                    var co = f.geometry.coordinates;
                    rtPicks.push(Object.assign({}, pr, { lat: co[1], lng: co[0], _rec: true }));   // _rec=추천 출처(칩 끄면 이것만 제거·직접 찜은 보존)
                    added++;
                });
            });
        });
        rtPicksSave(); rtDrawPicks(); plUpdateLabels(); rtRenderSummary();
        if (added) plHint('🌟 추천 ' + added + '곳 찜 추가' + (dup ? ' (이미 찜 ' + dup + '곳 제외)' : ''));
        else plHint(dup ? '추천 후보가 이미 모두 찜되어 있습니다' : '추천할 곳이 없습니다');
    }).catch(function () {
        if (btn) { btn.disabled = false; btn.textContent = '🌟 추천 찜 담기'; }
        plHint('추천 검색 실패');
    });
}

// 도로 경로 요약(🚗 소요시간 · 거리 · 통행료)
function rtFmtRoute(info) {
    var min = Math.round((info.duration || 0) / 60000);
    var h = Math.floor(min / 60), m = min % 60;
    var t = h > 0 ? (h + '시간 ' + m + '분') : (m + '분');
    var km = ((info.distance || 0) / 1000).toFixed(1) + 'km';
    var toll = info.toll > 0 ? ' · 통행료 ' + Number(info.toll).toLocaleString() + '원' : '';
    return '🚗 <b>' + t + '</b> · ' + km + toll;
}

// 종합(경로 주변 장소) 패널 렌더 — 찜한 곳은 별도 섹션 없이 목록에 그대로 유지(화면 밖이어도)
function rtRenderSummary() {
    var box = document.getElementById('rtSum'); if (!box) return;
    var cnt = document.getElementById('rtSumCnt');
    var showDist = true;   // 거리 항상 표시(체크박스 제거)
    if (!rtNearbyActive || !rtRoute.length) {
        if (cnt) cnt.textContent = '';
        box.innerHTML = '<div class="rt-sum-empty">지점을 2곳 이상 추가하면<br>경로 주변(반경 안)의 장소가<br>자동으로 여기에 모입니다.</div>';
        return;
    }
    var total = 0; rtNearby.forEach(function (a) { total += (a ? a.length : 0); });
    if (cnt) cnt.textContent = (rtFinalized && !rtPicks.length) ? '' : (rtNearbyBusy ? '주변 검색 중…' : ('반경 ' + rtRadius + 'km · ' + total + '곳' + (rtPicks.length ? ' · ⭐' + rtPicks.length : '')));
    // 경로 요약 헤더(소요시간/거리/통행료 또는 계산중/직선 안내)
    var info = '';
    if (rtRouting) info = '<div class="rt-route-info">⏳ 도로 경로 계산 중…</div>';
    else if (rtRouteInfo) info = '<div class="rt-route-info">' + rtFmtRoute(rtRouteInfo) + '</div>';
    else if (rtRoute.length >= 2) info = '<div class="rt-route-info muted">직선 거리 기준 (도로 경로를 받지 못했습니다)</div>';
    if (rtNearbyBusy) info += '<div class="rt-route-info muted">⏳ 경로 주변 장소를 전국 DB에서 찾는 중…</div>';
    // ★주변 미가동(rtFinalized) + 찜 없음 = 아직 스캔 전. 자동 스캔 대신 안내만.
    if (rtFinalized && !rtPicks.length) {
        box.innerHTML = info + '<div class="rt-sum-empty" style="margin-top:8px">🔍 아직 경로 주변을 찾지 않았습니다.<br><b>“🌟 추천 찜 담기”</b>를 누르면 경로 주변에서<br>맛집·숙소·여행지를 찾아 담습니다.<br><span style="color:#8a97a3;font-size:11px">(“👁️ 주변 다시 보기”로 전체 목록만 볼 수도 있어요)</span></div>';
        return;
    }
    box.innerHTML = info + rtRoute.map(function (wp, wi) {
        var col = rtColor(wi);
        var arr = rtNearby[wi] || [];
        // 한 장소 행 렌더(분류 색 점 사용)
        var renderPlace = function (s, dotCol) {
            var d = showDist ? '<span class="rt-pdist">~' + plFmtDist(s.dist) + '</span>' : '';
            var pk = rtIsPicked(s.id);
            var star = '<button class="rt-star' + (pk ? ' on' : '') + '" title="' + (pk ? '찜 해제' : '찜') + '" onclick="event.stopPropagation();rtTogglePickById(' + s.id + ')">' + (pk ? '★' : '☆') + '</button>';
            return '<div class="rt-place' + (s.id == rtSelId ? ' active' : '') + '" id="rt-pl-' + wi + '-' + s.id + '" onclick="rtSpotFocusById(' + wi + ',' + s.id + ')">' +
                '<span class="rt-pdot" style="background:' + dotCol + '"></span>' +
                '<div class="rt-pbody"><div class="rt-pname">' + plEsc(s.name) + '</div>' +
                    '<div class="rt-pcat">' + plEsc(CAT_KO[s.category] || '기타') + (s.ref_count ? ' · 기사 ' + s.ref_count : '') + '</div>' +
                '</div>' + d + star +
            '</div>';
        };
        // 분류별 소그룹(맛집→숙소→여행지→캠핑→기타). 그룹 내 순서는 rtComputeNearby 정렬(찜 먼저→거리순) 유지.
        var places;
        if (!arr.length) {
            places = '<div class="rt-wp-none">반경 ' + rtRadius + 'km 안에 등록된 장소가 없습니다</div>';
        } else {
            var byCat = {};
            arr.forEach(function (s) { var c = s.category || 'etc'; (byCat[c] = byCat[c] || []).push(s); });
            places = RT_CAT_SEQ.map(function (cat) {
                var list = byCat[cat]; if (!list || !list.length) return '';
                var m = RT_CAT_META[cat] || RT_CAT_META.etc;
                var rows = list.map(function (s) { return renderPlace(s, m.col); }).join('');
                return '<div class="rt-catgrp">' +
                    '<div class="rt-cathd" style="color:' + m.col + '"><span class="rt-cathd-ic">' + m.ic + '</span>' + m.ko +
                        '<span class="rt-cathd-n" style="background:' + m.col + '">' + list.length + '</span></div>' +
                    rows +
                '</div>';
            }).join('');
        }
        return '<div class="rt-wp">' +
            '<div class="rt-wp-head" onclick="rtFocusWaypoint(' + wi + ')">' +
                '<span class="rt-wp-num" style="background:' + col + '">' + (wi + 1) + '</span>' +
                '<div class="rt-wp-info"><div class="rt-wp-nm">' + plEsc(wp.name) + '</div>' +
                    '<div class="rt-wp-cnt">주변 ' + arr.length + '곳</div></div>' +
            '</div>' +
            '<div class="rt-wp-places">' + places + '</div>' +
        '</div>';
    }).join('');
}

// 지점 헤더 클릭 → 그 지점으로 이동 + 상세패널(placeId면 풀 상세, 없으면 이름·주소). 지점 자신은 목록에서 빠졌으므로 헤더가 상세 진입점.
function rtFocusWaypoint(wi) {
    var wp = rtRoute[wi]; if (!wp) return;
    rtSelId = wp.placeId || null;   // 목록 active 표시와 일관(지점 선택 강조)
    plMap.morph(new naver.maps.LatLng(wp.lat, wp.lng), Math.max(11, plZoomForRadius(rtRadius)));
    rtShareOpenDetail(wp);
}

// 종합(우측 패널) 항목 클릭 → 그 장소로 이동 + 상세패널 + 선택 강조(재렌더 후에도 유지)
function rtSpotFocusById(wi, pid) {
    rtSelId = pid;
    // ★찜한 곳은 항상 '찜 마커/라벨' 자체를 강조(맨 위라 안 가려짐). 회랑 마커 강조는 빨간 찜마커에 덮임.
    if (rtIsPicked(pid)) { rtPickFocus(pid); return; }
    // 회랑(현재 화면)에 있으면 plProxPick(이동+상세패널+강조)
    for (var i = 0; i < plOvFeats.length; i++) {
        if (plOvFeats[i].properties.id == pid) { plProxPick(i); return; }
    }
    rtDrawPicks(); rtRenderSummary();   // 동기화(선택 해제된 찜 라벨 원복 등)
}

// ══ ⭐ 찜한 곳(수집 레이어) — 화면(뷰포트)과 무관하게 항상 표시·저장 대상 ══
function rtPickIdx(id) { for (var i = 0; i < rtPicks.length; i++) if (rtPicks[i].id == id) return i; return -1; }
function rtIsPicked(id) { return rtPickIdx(id) >= 0; }
// 찜 토글 — 현재 화면(plOvFeats)·회랑(rtSrvFeats)·이미 찜목록에서 그 장소를 찾아 추가/제거
function rtTogglePickById(id) {
    var i = rtPickIdx(id);
    if (i >= 0) { rtPicks.splice(i, 1); }
    else {
        var f = null, k;
        for (k = 0; k < plOvFeats.length; k++) if (plOvFeats[k].properties.id == id) { f = plOvFeats[k]; break; }
        if (!f) for (k = 0; k < rtSrvFeats.length; k++) if (rtSrvFeats[k].properties.id == id) { f = rtSrvFeats[k]; break; }
        if (!f) return;
        var co = f.geometry.coordinates;
        rtPicks.push(Object.assign({}, f.properties, { lat: co[1], lng: co[0] }));
    }
    rtPicksSave(); rtDrawPicks(); plUpdateLabels(); rtRenderSummary();   // 회랑 라벨 재그림(찜된 곳은 생략돼 중복 제거)
}
// 상세패널 ☆찜 버튼 — 연 장소를 토글
function rtTogglePickHere() {
    var pr = plPanelPlace; if (!pr || pr.id == null || pr.lat == null) return;
    var i = rtPickIdx(pr.id);
    if (i >= 0) rtPicks.splice(i, 1);
    else rtPicks.push(Object.assign({}, pr));
    rtPicksSave(); rtDrawPicks(); plUpdateLabels(); rtRenderSummary();   // 회랑 라벨 재그림(찜된 곳은 생략돼 중복 제거)
    plOpenPanel(pr);   // 패널 버튼 상태(☆↔★) 갱신
}
// 찜 마커/목록 클릭 → 그 곳으로 이동 + 상세패널 + 찜 마커 자체 강조(화면 밖이어도 동작·재렌더 후 유지)
function rtPickFocus(id) {
    var i = rtPickIdx(id); if (i < 0) return;
    rtSelId = id;
    rtDrawPicks();        // 선택 찜 라벨을 active(파랑)로
    rtRenderSummary();    // 리스트 선택 강조
    var p = rtPicks[i];
    plMap.panTo(new naver.maps.LatLng(p.lat, p.lng));
    plOpenById(p.id, p.lat, p.lng, p);   // 단일 경로 — 저장된 찜 객체(요약 없음) 대신 서버 최신정보로
}
// 찜 마커(분류 아이콘 + 금색 ★ 배지) — 항상 표시, 가장 위(zIndex 210)
function rtPickIcon(pr) {
    var base = plMarkerIcon(pr.category, '', false, plMkColor(pr), plMkBadge(pr));
    return { content: '<div class="rt-pick-wrap">' + base.content + '<span class="rt-pick-star">★</span></div>', anchor: base.anchor };
}
function rtClearPicks() { rtPickMarkers.forEach(function (m) { if (m) m.setMap(null); }); rtPickMarkers = []; }
function rtDrawPicks() {
    rtClearPicks();
    if (!rtMode) return;
    rtPicks.forEach(function (p) {
        if (p.lat == null || p.lng == null) return;
        var pos = new naver.maps.LatLng(p.lat, p.lng);
        // ⭐ 마커(분류 아이콘 + 금색 별)
        var mk = new naver.maps.Marker({
            position: pos, map: plMap, zIndex: 210, title: '⭐ ' + p.name, icon: rtPickIcon(p)
        });
        naver.maps.Event.addListener(mk, 'click', (function (id) { return function () { rtPickFocus(id); }; })(p.id));
        rtPickMarkers.push(mk);
        // ★ 반전 라벨(빨강 배경·흰 글씨·흰 테두리) — 줌 무관 항상 표시. 마커 위쪽에 띄움. 선택 시 파랑 active.
        var act = (p.id == rtSelId) ? ' active' : '';
        var nv = p.attributes && p.attributes.naver;
        var rev = (nv && nv.review) ? Number(nv.review) : 0;
        var revHtml = rev ? '<span class="rt-pick-rev">📝' + rev.toLocaleString() + '</span>' : '';
        // 라벨 배경 = 분류 색(맛집🔴·숙소🟣·여행지🔵·캠핑🟢). 선택(active)이면 CSS 파랑이 덮도록 인라인 미적용.
        var cm = RT_CAT_META[p.category] || RT_CAT_META.etc;
        var lblStyle = act ? '' : ' style="background:' + cm.col + '"';
        var lbl = new naver.maps.Marker({
            position: pos, map: plMap, zIndex: act ? 230 : 220, clickable: true,
            icon: { content: '<div class="rt-pick-label' + act + '"' + lblStyle + '>⭐ ' + plEsc(p.name) + revHtml + '</div>',
                    anchor: new naver.maps.Point(0, 0) }
        });
        naver.maps.Event.addListener(lbl, 'click', (function (id) { return function () { rtPickFocus(id); }; })(p.id));
        rtPickMarkers.push(lbl);
    });
}
function rtPicksSave() { try { localStorage.setItem('placesPicks', JSON.stringify(rtPicks)); } catch (e) {} }
function rtPicksLoad() {
    try { var raw = localStorage.getItem('placesPicks'); if (raw) { var a = JSON.parse(raw); if (Array.isArray(a)) rtPicks = a; } } catch (e) {}
}

// ══ 💾 저장함(트립) — 경로+찜을 서버에 이름 붙여 여러 개 저장/불러오기/공유 ══
var rtLoadedName = '';   // 현재 불러온/저장한 트립 이름(저장 시 기본값 → 덮어쓰기 편의)
var rtTripsCache = [];   // 목록 캐시(덮어쓰기 확인용)
// 경로 패널 상단에 현재 불러온 여행지도 이름 표시
function rtUpdateTripName() {
    var el = document.getElementById('rtCurName'); if (!el) return;
    if (rtLoadedName) { el.textContent = '📁 ' + rtLoadedName; el.style.display = ''; }
    else { el.textContent = ''; el.style.display = 'none'; }
}
function rtTripOpen(focusName) {
    document.getElementById('rt-trip').classList.add('open');
    document.getElementById('rtTripCur').textContent = '현재 지도: 지점 ' + rtRoute.length + '곳 · ⭐ 찜 ' + rtPicks.length + '곳';
    var el = document.getElementById('rtTripName');
    if (el && rtLoadedName && !el.value) el.value = rtLoadedName;   // 불러온 이름 기본 채움(그대로 저장=덮어쓰기)
    rtTripListLoad();
    if (focusName && el) setTimeout(function () { el.focus(); el.select(); }, 60);
}
function rtTripSave() {
    if (rtRoute.length < 1 && rtPicks.length < 1) { alert('저장할 경로나 찜이 없습니다.'); return; }
    rtTripOpen(true);
}
function rtTripClose() { document.getElementById('rt-trip').classList.remove('open'); }
function rtTripSaveDo() {
    var name = (document.getElementById('rtTripName').value || '').trim();
    if (!name) { alert('여행지도 이름을 입력하세요.'); return; }
    var exists = rtTripsCache.some(function (t) { return t.name === name; });
    if (exists && !confirm('이미 있는 "' + name + '" 에 덮어쓸까요?')) return;
    fetch(plApiUrl({ module: 'place', action: 'trip_save' }), {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ name: name, route: JSON.stringify(rtRoute), picks: JSON.stringify(rtPicks) })
    }).then(function (r) { return r.json(); }).then(function (d) {
        if (!d || !d.ok) { alert('저장 실패'); return; }
        rtLoadedName = name; rtUpdateTripName();
        plHint(exists ? ('💾 덮어썼습니다: ' + name) : ('💾 저장됨: ' + name));
        rtTripListLoad();
    }).catch(function () { alert('저장 오류'); });
}
function rtTripListLoad() {
    var box = document.getElementById('rtTripList');
    box.innerHTML = '<div class="rt-trip-empty">불러오는 중…</div>';
    fetch(plApiUrl({ module: 'place', action: 'trip_list' })).then(function (r) { return r.json(); }).then(function (d) {
        var trips = (d && d.trips) || [];
        rtTripsCache = trips;
        if (!trips.length) { box.innerHTML = '<div class="rt-trip-empty">저장된 여행지도가 없습니다.</div>'; return; }
        box.innerHTML = trips.map(function (t) {
            return '<div class="rt-trip-it">' +
                '<div class="rt-trip-info" onclick="rtTripLoad(' + t.id + ')">' +
                    '<div class="rt-trip-nm">' + plEsc(t.name) + '</div>' +
                    '<div class="rt-trip-meta">지점 ' + t.stops + ' · ⭐' + t.picks + ' · ' + plEsc((t.updated_at || '').slice(0, 10)) + '</div>' +
                '</div>' +
                '<button class="rt-trip-open" onclick="rtTripLoad(' + t.id + ')">열기</button>' +
                '<button class="rt-trip-share" title="공유 링크 복사" onclick="rtTripShare(' + t.id + ')">🔗</button>' +
                '<button class="rt-trip-del" title="삭제" onclick="rtTripDelete(' + t.id + ')">🗑</button>' +
            '</div>';
        }).join('');
    }).catch(function () { box.innerHTML = '<div class="rt-trip-empty">목록을 불러오지 못했습니다.</div>'; });
}
function rtTripDelete(id) {
    if (!confirm('이 여행지도를 삭제할까요?')) return;
    fetch(plApiUrl({ module: 'place', action: 'trip_delete', id: id }))
        .then(function (r) { return r.json(); }).then(function () { rtTripListLoad(); })
        .catch(function () { alert('삭제 오류'); });
}
// 🔗 공유: 트립에 한시적 공유 토큰 발급 → 링크 생성 → 클립보드 복사(만료일 안내)
function rtTripShare(id) {
    fetch(plApiUrl({ module: 'place', action: 'trip_share', id: id })).then(function (r) { return r.json(); }).then(function (d) {
        if (!d || !d.ok || !d.token) { alert('공유 링크 생성 실패'); return; }
        var url = location.origin + location.pathname + '?trip=' + d.token;
        var exp = (d.expires_at || '').slice(0, 10);
        var msg = '공유 링크 (Ctrl+C 로 복사)' + (exp ? '\n※ ' + exp + ' 까지 유효합니다' : '');
        var done = function () { plHint('🔗 공유 링크 복사됨' + (exp ? ' · ' + exp + '까지' : '')); window.prompt(msg, url); };
        if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(url).then(done, function () { window.prompt(msg, url); });
        else window.prompt(msg, url);
    }).catch(function () { alert('공유 오류'); });
}
// 🔖 경로코드: 현재 경로 지점 + 찜한 곳의 place id 를 모아 요약도구(place_summary_tool) URL 을 클립보드에 복사.
//  Claude 챗에 이 URL 을 주면 목록을 읽어 요약 JSON 을 만들 수 있다(저장 안 한 경로도 화면 그대로 반영).
function rtCopyCode() {
    var ids = [], seen = {};
    var push = function (v) { var n = parseInt(v, 10); if (n > 0 && !seen[n]) { seen[n] = 1; ids.push(n); } };
    for (var i = 0; i < rtRoute.length; i++) push(rtRoute[i].placeId);
    for (var j = 0; j < rtPicks.length; j++) push(rtPicks[j].id);
    if (!ids.length) { plHint('경로에 등록된 장소가 없습니다'); return; }
    var url = location.origin + '/place_summary_tool.php?key=econ-sumtool&ids=' + ids.join(',');
    var msg = '경로코드 URL (Ctrl+C 로 복사)\n※ Claude 챗에 주면 장소 목록을 읽어 요약 JSON 을 만듭니다';
    var done = function () { plHint('🔖 경로코드 복사됨 · ' + ids.length + '곳'); };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(url).then(done, function () { window.prompt(msg, url); });
    else window.prompt(msg, url);
}
function rtTripLoad(id) {
    fetch(plApiUrl({ module: 'place', action: 'trip_load', id: id })).then(function (r) { return r.json(); }).then(function (d) {
        if (!d || !d.ok || !d.trip) { alert('불러오기 실패'); return; }
        rtRoute = Array.isArray(d.trip.route) ? d.trip.route : [];
        rtPicks = Array.isArray(d.trip.picks) ? d.trip.picks : [];
        rtLoadedName = d.trip.name || ''; rtUpdateTripName();
        rtSelId = null; rtSave(); rtPicksSave();
        rtFinalized = true;                              // ★저장함 불러오기 기본 = 주변보기 off(찜·경로만, 회랑 안 부름) — 게스트뷰와 동일
        rtTripClose();
        if (!rtMode) { rtToggleMode(); }                 // 진입(rtUpdateFinalBtn 라벨 동기화·rtFetchNearby 는 finalized 라 skip)
        else { rtUpdateFinalBtn(); rtRenderRows(); rtDrawPicks(); rtFetchRoute(); }
        document.body.classList.add('rt-collapsed'); rtSyncTabArrow();   // ★불러오기 = 좌측 패널 닫힌 채 시작(지도 우선·탭으로 펼침). 진입부가 풀어둔 것을 다시 접음
        // 불러온 지도가 한눈에 보이도록 경로+찜 전체 범위로 맞춤(이후 idle 이 그 화면의 회랑을 로드)
        var pts = rtRoute.concat(rtPicks).filter(function (p) { return p && p.lat != null && p.lng != null; });
        if (pts.length && plMap) {
            var b = new naver.maps.LatLngBounds(new naver.maps.LatLng(pts[0].lat, pts[0].lng), new naver.maps.LatLng(pts[0].lat, pts[0].lng));
            pts.forEach(function (p) { b.extend(new naver.maps.LatLng(p.lat, p.lng)); });
            // 패널 접힘 슬라이드(240ms) 후 지도 리사이즈 → 넓어진 화면 기준으로 범위 맞춤
            setTimeout(function () { plBumpResize(); try { plMap.fitBounds(b, { top: 60, right: 60, bottom: 90, left: 60 }); } catch (e) {} }, 260);
        }
        plHint('📂 불러옴: ' + (d.trip.name || ''));
    }).catch(function () { alert('불러오기 오류'); });
}
// 공유 링크(?trip=토큰) 게스트 뷰 — 그 여행지도(경로+찜)만 읽기전용 표시(주변 회랑 없이)
function rtViewShared(token) {
    fetch(plApiUrl({ module: 'place', action: 'trip_view' })).then(function (r) { return r.json(); }).then(function (d) {
        if (!d || !d.ok || !d.trip) { plHint('공유된 여행지도를 찾을 수 없습니다'); return; }
        rtRoute = Array.isArray(d.trip.route) ? d.trip.route : [];
        rtPicks = Array.isArray(d.trip.picks) ? d.trip.picks : [];
        rtMode = true; rtFinalized = true; rtSelId = null; rtShareSel = -1;   // 게스트=찜·경로만(주변 회랑 안 부름)
        document.body.classList.add('rt-on');
        rtDraw(); rtDrawPicks(); rtFetchRoute();              // 도로경로+찜 그림(rtFinalized라 회랑 skip)
        rtRenderShareBar();                                  // 전체 경로 슬라이드 바 표시
        var pts = rtRoute.concat(rtPicks).filter(function (p) { return p && p.lat != null && p.lng != null; });
        if (pts.length && plMap) {
            var b = new naver.maps.LatLngBounds(new naver.maps.LatLng(pts[0].lat, pts[0].lng), new naver.maps.LatLng(pts[0].lat, pts[0].lng));
            pts.forEach(function (p) { b.extend(new naver.maps.LatLng(p.lat, p.lng)); });
            try { plMap.fitBounds(b, { top: 60, right: 60, bottom: 90, left: 60 }); } catch (e) {}
        }
        plHint('📂 공유 여행지도: ' + (d.trip.name || ''));
    }).catch(function () { plHint('공유 여행지도 불러오기 오류'); });
}

// localStorage 보존(새로고침 대비)
function rtSave() { try { localStorage.setItem('placesRoute', JSON.stringify(rtRoute)); } catch (e) {} }
function rtLoad() {
    try { var raw = localStorage.getItem('placesRoute'); if (raw) { var a = JSON.parse(raw); if (Array.isArray(a)) rtRoute = a; } } catch (e) {}
}
rtLoad(); rtPicksLoad();
</script>
</body>
</html>
<?php /* end places.php */ ?>
