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
/* 맛집 마커 — 가이드색 원형 + 흰 포크·나이프 + 우상단 숫자 배지 */
.mk-food { position: relative; width: 30px; height: 30px; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 2px 6px rgba(0,0,0,.45); display: flex; align-items: center; justify-content: center; box-sizing: border-box; background: #e74c3c; transition: transform .1s; }
.mk-food .mk-fk { width: 16px; height: 16px; fill: #fff; }
.mk-food .mk-fnum { position: absolute; top: -8px; right: -8px; height: 15px; padding: 0 3px; box-sizing: border-box; background: #fff; border: 1px solid rgba(0,0,0,.28); border-radius: 999px; display: inline-flex; align-items: center; gap: 0px; white-space: nowrap; }
.mk-food .mk-fnum .mk-rb { width: 8px; height: 10px; fill: #1f3a93; }            /* 블루리본 = 네이비 리본 */
.mk-food .mk-fnum .mk-star { font-size: 9px; font-style: normal; color: #d4a23a; line-height: 1; }  /* 미쉐린 = 골드 별 */
.mk-food .mk-fnum .mk-gstar { color: #1a9c4f; }                                  /* 그린스타 */
.mk-food .mk-fnum .mk-bib { font-size: 8px; font-style: normal; font-weight: 800; color: #c0392b; line-height: 1; }
.mk-food.active { transform: scale(1.38); background: #2979ff !important; border-color: #fff; z-index: 1000; animation: pl-food-glow 1.7s ease-in-out infinite; }
@keyframes pl-food-glow {
    0%, 100% { box-shadow: 0 0 5px 2px rgba(41,121,255,.45), 0 4px 11px rgba(0,0,0,.5); }
    50%      { box-shadow: 0 0 16px 6px rgba(41,121,255,.85), 0 4px 11px rgba(0,0,0,.5); }
}
.mk-food.active .mk-fnum { background: #2c3e50; border-color: #2c3e50; }
.mk-food.active .mk-fnum .mk-rb { fill: #9ec3f5; }
.mk-food.active .mk-fnum .mk-gstar { color: #6ee29b; }
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
.pl-list-body { flex: 1; overflow-y: auto; }
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
/* 상세패널 가이드 배지 */
.panel-guides { display: flex; flex-wrap: wrap; gap: 5px; margin: 6px 0 2px; }
.panel-guides .li-guide { font-size: 11px; padding: 2px 9px; }
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
#pl-panel { position: absolute; top: 0; right: 0; bottom: 0; width: 340px; max-width: 88vw; background: #fff; box-shadow: -3px 0 14px rgba(0,0,0,.15); z-index: 30; transform: translateX(100%); transition: transform .25s; display: flex; flex-direction: column; }
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
.rt-rnum { width: 56px; font-size: 12px; padding: 5px 7px; border: 1px solid #d8dde3; border-radius: 6px; }
.rt-chk { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; color: #5b6b7b; cursor: pointer; user-select: none; }
.rt-chk input { margin: 0; cursor: pointer; }
.rt-go { background: #6c5ce7; border: none; color: #fff; font-size: 12.5px; font-weight: 700; padding: 7px 12px; border-radius: 7px; cursor: pointer; margin-left: auto; }
.rt-go:hover { background: #5a4cd0; }
.rt-go.on { background: #2c3e50; }
.rt-go.on:hover { background: #1f2c39; }

/* 패널 본문 스크롤 + 섹션 헤더 */
.rt-scroll { flex: 1; overflow-y: auto; min-height: 0; }
.rt-sec-hd { position: sticky; top: 0; z-index: 1; background: #fff; padding: 10px 14px 7px; font-size: 12.5px; font-weight: 800; color: #5a4cd0; border-bottom: 1px solid #f3f3fa; }
.rt-sec-hd .rt-sub { font-size: 11px; font-weight: 600; color: #aab3bc; }
.rt-sec-hd-row { display: flex; align-items: center; gap: 6px; }
.rt-clearbtn { margin-left: auto; background: #fdecea; border: 1px solid #f5c6c0; color: #c0392b; font-size: 11.5px; font-weight: 700; padding: 4px 10px; border-radius: 6px; cursor: pointer; white-space: nowrap; }
.rt-clearbtn:hover { background: #f9d9d4; }

/* 종합(지점별 주변 장소) */
.rt-sum { padding: 8px 12px 4px; }
.rt-sum-empty { padding: 22px 12px; text-align: center; color: #b0b8bf; font-size: 12.5px; line-height: 1.7; }
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
/* 좌측 '지점별 주변 장소' 종합 패널 (경로 패널과 별개) */
/* 상세패널 '경로에 추가' 버튼 */
.rt-addcur { display: block; width: 100%; margin-top: 12px; background: #6c5ce7; border: none; color: #fff; font-size: 13.5px; font-weight: 700; padding: 10px; border-radius: 8px; cursor: pointer; }
.rt-addcur:hover { background: #5a4cd0; }
@media (max-width: 640px) {
    /* 모바일: 두 패널을 하단 시트로 모아 위아래로 쌓음 */
    #rt-dock { left: 0; right: 0; top: auto; height: 64vh; flex-direction: column; transform: translateY(100%); box-shadow: 0 -3px 14px rgba(0,0,0,.18); }
    body.rt-on #rt-dock { transform: translateY(0); }
    #rt-sum-panel, #rt-panel { width: 100%; max-width: 100%; flex: 1; min-height: 0; }
    #rt-sum-panel { border-right: none; border-bottom: 1px solid #e8eaf0; }
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
.pl-autochk { display: inline-flex; align-items: center; gap: 5px; font-size: 12.5px; color: #5b6b7b; white-space: nowrap; cursor: pointer; user-select: none; }
.pl-autochk input { cursor: pointer; margin: 0; }
.pem-lbl-row { display: flex; align-items: center; gap: 6px; }
.pem-tagmgr { margin-left: auto; background: #f3f1fb; border: 1px solid #d9d2f0; color: #6c5ce7; font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 6px; cursor: pointer; white-space: nowrap; }
.pem-tagmgr:hover { background: #e9e4f8; }
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
    <select id="category" onchange="plCategoryChange()">
        <option value="">전체</option>
        <option value="travel">여행지</option>
        <option value="restaurant">맛집</option>
        <option value="stay">숙소</option>
        <option value="camping">캠핑장</option>
        <option value="etc">기타</option>
    </select>
    <!-- 반경 선택 UI 제거(줌인/아웃으로 영역 조절). 주소검색·자동확장 등 내부 로직용 기본값만 숨김 보관 -->
    <input type="hidden" id="radius" value="5">
    <label class="pl-autochk" title="체크하면 지도를 옮길 때마다 그 지역 장소를 자동으로 표시합니다">
        <input type="checkbox" id="autoSearch" onchange="plAutoToggle()"> 이동 시 주변검색
    </label>
<?php if (!$isGuest): ?>
    <button class="btn btn-route" id="rtModeBtn" onclick="rtToggleMode()" title="여행 경로(동선)를 만듭니다">🧭 여행지도 만들기</button>
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

<div id="pl-tagbar">
    <div id="tbChips" class="tb-chips"></div>
    <button id="tbMore" class="tb-more" onclick="plTagBarToggleMore()" style="display:none"></button>
</div>
<div id="pl-monthbar">
    <span class="mb-lbl" id="mbLbl">🌸 방문하기 좋은 달</span>
    <div id="mbChips" class="mb-chips"></div>
</div>
<div id="pl-foodbar">
    <span class="mb-lbl">🍜 맛집</span>
    <div id="foodChips" class="mb-chips"></div>
    <button id="foodMore" class="tb-more" onclick="plFoodToggleMore()" style="display:none"></button>
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
<?php if (!$isGuest): ?>
    <div class="rt-banner">🧭 경로 만들기 중 — 지도 마커를 클릭하거나 주소를 입력해 경로를 만드세요 (검색 마커는 유지됩니다)</div>
    <div id="rt-dock">
    <div id="rt-sum-panel">
        <div class="rt-head"><h3>📋 지점별 주변 장소</h3><span class="rt-sub" id="rtSumCnt"></span></div>
        <div class="rt-sum" id="rtSum">
            <div class="rt-sum-empty">경로를 추가하면 각 지점 반경 안의<br>장소가 자동으로 여기에 모입니다.</div>
        </div>
    </div>
    <div id="rt-panel">
        <div class="rt-head"><h3>🧭 경로 만들기</h3><button class="rt-x" onclick="rtToggleMode()" title="닫기">×</button></div>
        <div class="rt-controls">
            <span class="rt-clbl">반경</span>
            <span class="rt-rquick">
                <button type="button" class="rt-rq" data-r="3" onclick="rtSetRadius(3)">3km</button>
                <button type="button" class="rt-rq on" data-r="5" onclick="rtSetRadius(5)">5km</button>
                <button type="button" class="rt-rq" data-r="10" onclick="rtSetRadius(10)">10km</button>
            </span>
            <input type="number" id="rtRadius" class="rt-rnum" min="0.5" max="50" step="0.5" value="5" onchange="rtRadiusInput()" title="직접 입력(km)">
            <label class="rt-chk"><input type="checkbox" id="rtCircleChk" checked onchange="rtToggleCircles()"> 반경 원</label>
            <label class="rt-chk"><input type="checkbox" id="rtDistChk" checked onchange="rtRenderSummary()"> 거리</label>
            <button class="rt-go" id="rtFinalBtn" onclick="rtFinalize()" title="반경 밖 마커를 숨기거나 다시 표시합니다">🙈 마크 숨기기</button>
        </div>
        <div class="rt-tip">검색해 둔 마커는 <b>그대로 유지</b>됩니다. 경로를 추가하면 <b>반경 안의 장소는 작은 원</b>으로 바뀌고 오른쪽 종합에 자동 정리됩니다(반경 밖은 그대로). <b>지도 마커 클릭</b>·<b>주소 직접 입력</b>으로 경로에 추가, <b>≡</b> 드래그로 순서변경. <b>🙈 마크 숨기기</b>로 반경 밖 마커를 감출 수 있습니다.</div>
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
                <input type="text" id="pemCuisineInput" class="pem-inp" autocomplete="off"
                       placeholder="음식 종류 입력 후 Enter (쉼표로 여러 개)"
                       onkeydown="if(event.key==='Enter'){event.preventDefault();plEditCuisineAdd();}">
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
<?php endif; ?>

<script>
var PLACE_NAVER_KEY = <?= json_encode($naverClientId) ?>;
var PL_SHARE        = <?= json_encode($isGuest ? $shareToken : '') ?>; // 게스트면 토큰, 소유자면 ''
var PL_GUIDES       = <?= json_encode(FoodGuide::clientDefs(), JSON_UNESCAPED_UNICODE) ?>; // 맛집 가이드 정의(색·prio·등급라벨)

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
// 카드/패널용 가이드 배지들 [한글명] (등급은 #리본2 태그로 별도 표시)
function plGuideBadges(guides) {
    if (!guides || !guides.length) return '';
    return guides.map(function (g) {
        var d = PL_GUIDES[g.guide]; if (!d) return '';
        return '<span class="li-guide" style="background:' + d.color + '">' + plEsc(d.ko) + '</span>';
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
//  베이스(필터 결과, 예: 호수)는 그대로 두고, 줌인하면 화면 안의 전 분류(맛집·숙소·명소)를
//  자동으로 위에 얹는다. 줌아웃(임계 미만)하면 오버레이만 사라지고 베이스는 남음(전체 현황 유지).
//  ※ 필터(칩) 모드에서만 동작. 일반 뷰포트 모드는 기존 줌 티어링이 이미 전 분류를 보여줌.
var PL_DETAIL_ZOOM = 14;       // 이 줌 이상이면 주변 오버레이 표시(네이버 스케일 ≈ 300m. 100m=16/200m=15/300m=14/500m=13/1km=12)
var plOv = [];                 // 현재 오버레이 마커들
var plOvFeats = [];            // 현재 오버레이 장소(좌측 '주변' 목록용)
var plListMode = 'base';       // 'base'=필터/뷰포트 목록 / 'overlay'=주변 목록
var plBaseTitle = '';          // 베이스 목록 제목(줌아웃 복원용)
function plClearOverlay() { plOv.forEach(function (m) { m.setMap(null); }); plOv = []; }
// 오버레이 종료(줌아웃) → 마커 제거 + 좌측 목록을 베이스(필터)로 복원
function plExitOverlay() {
    plClearOverlay();
    if (plListMode === 'overlay') {
        plListMode = 'base';
        plRenderList(plFeatures);
        if (plBaseTitle) document.getElementById('plListTitle').textContent = plBaseTitle;
        plToggleList(plFeatures.length > 0);
    }
}
// 좌측에 '이 화면 주변' 목록 렌더(클릭=그 장소로 이동+상세). 베이스 마커는 지도에 그대로.
function plRenderNearbyList(feats) {
    plOvFeats = feats;
    var title = document.getElementById('plListTitle');
    var body  = document.getElementById('plListBody');
    title.textContent = '📍 이 화면 주변 ' + feats.length + '곳';
    body.innerHTML = feats.length
        ? feats.map(function (f, i) { return plLiHtml(f, i, { prefix: 'pl-ov-li-', onclickFn: 'plProxPick', num: false }); }).join('')
        : '<div class="pl-list-empty">이 화면에 주변 장소가 없습니다</div>';
    plToggleList(true);
}
function plProxPick(i) {
    var f = plOvFeats[i]; if (!f) return;
    var co = f.geometry.coordinates;
    plMap.panTo(new naver.maps.LatLng(co[1], co[0]));
    var items = document.querySelectorAll('.pl-li');
    for (var k = 0; k < items.length; k++) items[k].classList.remove('active');
    var li = document.getElementById('pl-ov-li-' + i);
    if (li) { li.classList.add('active'); li.scrollIntoView({ block: 'nearest' }); }
    f.properties.lat = co[1]; f.properties.lng = co[0];
    plOpenPanel(f.properties);
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
// 줌/뷰포트에 맞춰 오버레이 갱신 — idle 마다 호출. 조건 미충족이면 제거.
function plUpdateOverlay() {
    if (!plReady) return;
    // 필터 모드(베이스가 좁은 필터)이고 충분히 줌인했을 때만 주변을 덧댄다.
    if (!plChipActive() || plMap.getZoom() < PL_DETAIL_ZOOM) { plExitOverlay(); return; }
    var c = plMap.getCenter(), rad = plViewportRadiusKm();
    var sp = { module: 'place', action: 'search', lat: c.lat(), lng: c.lng(), radius: rad,
               limit: 400, mr_restaurant: 0, mr_stay: 0, mr_camping: 0 };   // 전 분류·다 표시
    fetch(plApiUrl(sp))
        .then(function (r) { return r.json(); })
        .then(function (geo) {
            plClearOverlay();
            if (!plChipActive() || plMap.getZoom() < PL_DETAIL_ZOOM) { plExitOverlay(); return; }   // 응답 사이 줌아웃
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
            plListMode = 'overlay';
            plRenderNearbyList(ovFeats);          // 좌측을 '이 화면 주변' 목록으로
        })
        .catch(function () {});
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

        // 추이 페이지에서 저장한 분류별 지도 기준(localStorage 'pl_map_filter') 로드.
        //  기본 분류='전체' → 맛집·스테이·캠핑을 각 기준으로 함께 표시.
        plLoadCatFilter();

        // 지도 열면 현재 화면의 마커를 바로 표시(뷰포트 + 최소리뷰 기준) — 바운드 준비 후 1회
        setTimeout(function () { if (plReady && !plChipActive()) plSearchHere(); }, 600);

        // 지도 멈추면(이동·줌 종료) 그 지역 자동 검색 — 별도 '이 지역 검색' 버튼 대체
        naver.maps.Event.addListener(plMap, 'idle', function () {
            clearTimeout(plIdleTimer);
            plIdleTimer = setTimeout(function () {
                plUpdateOverlay();                                 // 줌인=주변(전 분류) 오버레이 표시 / 줌아웃=제거(베이스 유지)
                if (plChipActive()) return;                        // 칩(여행·맛집) 검색 중이면 자동검색 안 함(전국 결과 보호)
                if (Date.now() - plLastSearchAt < 800) return;     // 방금 검색했으면(프로그램 이동) 중복 방지
                // 줌이 바뀌면 품질 티어가 달라지므로 자동검색 옵션과 무관하게 재검색.
                // 단순 이동(pan)은 '이동 시 주변검색' 옵션이 켜졌을 때만(기본 off).
                var zoomed = (plLastSearchZoom !== null && plMap.getZoom() !== plLastSearchZoom);
                var chk = document.getElementById('autoSearch');
                if (!zoomed && !(chk && chk.checked)) return;
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
    }).catch(function (e) {
        document.getElementById('hint').textContent =
            (e === 'no-key') ? '네이버 지도 키가 설정되지 않았습니다 (env/maps.inc)' : '지도 로딩 실패';
    });
}
plInit();

// ── 상단 칩 바 (3행 동시 표시) ──
//  1행 #tbChips   : 여행/숙소/기타 테마 태그칩(최대3 AND)
//  2행 #mbChips   : 🌸 방문하기 좋은 달(1~12)
//  3행 #foodChips : 🍜 맛집 — 가이드칩(블루리본/미쉐린, 단일선택) + 음식종류·등급칩(최대3 AND)
//  여행(테마·달)과 맛집(가이드·음식)은 검색 도메인이 달라, 한쪽을 선택하면 다른쪽 선택은 자동 해제.
var plTagBarAll = [], plTagBarExpanded = false, plSelTags = [], plSelMonth = null;
var plBarGuides = [], plBarCuisines = [], plSelGuide = null, plSelFood = [], plFoodExpanded = false;   // 맛집 데이터/선택
var PL_TAG_MAX = 3;   // 태그 동시 선택 최대 개수(AND)
var PL_TAGBAR_TOP = 14, PL_FOODBAR_TOP = 14;
function plTagBarInit() {
    // 1행: 여행 테마 태그(서버 tag_list 기본이 월·음식·등급 제외) — bar=1: 지도에 뜨는 장소 기준 distinct
    fetch(plApiUrl({ module: 'place', action: 'tag_list', bar: 1 }))
        .then(function (r) { return r.json(); })
        .then(function (d) {
            plTagBarAll = ((d && d.items) || []).filter(function (t) { return t.kind !== 'month'; }); // 월 제외
            plTagBarRender();
        })
        .catch(function () {});
    // 2행: 방문 좋은 달(1~12 정적)
    plMonthBarRender();
    // 3행: 가이드별 곳수 + 음식종류(cuisine)·등급(grade) 태그
    fetch(plApiUrl({ module: 'place', action: 'guide_list' }))
        .then(function (r) { return r.json(); })
        .then(function (d) { plBarGuides = (d && d.items) || []; plFoodBarRender(); })
        .catch(function () {});
    fetch(plApiUrl({ module: 'place', action: 'tag_list', bar: 1, kind: 'cuisine,grade' }))
        .then(function (r) { return r.json(); })
        .then(function (d) { plBarCuisines = (d && d.items) || []; plFoodBarRender(); })
        .catch(function () {});
}
// ── 분류(1차 축) ↔ 칩(하위필터) 연동 ──────────────────────────
//  분류가 주인: 선택 분류의 칩 행만 표시. '전체'면 모두 보임(칩 클릭 시 그 분류로 자동 전환).
//  여행지=테마·달 / 맛집=가이드·음식 / 숙소·캠핑·기타=세부칩 없음(리뷰기준만).
function plChipActive() { return !!(plSelTags.length || plSelMonth || plSelFood.length || plSelGuide); }
function plSetDisp(id, on) { var el = document.getElementById(id); if (el) el.style.display = on ? '' : 'none'; }
function plChipContext() {
    var cat = (document.getElementById('category') || {}).value || '';
    var showTravel = (cat === '' || cat === 'travel');
    var showFood   = (cat === '' || cat === 'restaurant');
    var tb = document.getElementById('tbChips'), fb = document.getElementById('foodChips');
    plSetDisp('pl-tagbar',   showTravel && !!(tb && tb.innerHTML));
    plSetDisp('pl-monthbar', showTravel);
    plSetDisp('pl-foodbar',  showFood && !!(fb && fb.innerHTML));
}
// 칩 클릭 시 드롭다운을 그 분류로 명시적으로 맞춤(숨은 전환 방지 — 증상1 해소)
function plEnsureCat(cat) {
    var sel = document.getElementById('category');
    if (sel && sel.value !== cat) { sel.value = cat; plChipContext(); }
}
// 분류 변경 = 칩 선택 초기화 + 칩바 재구성 + 이 화면(뷰포트) 기준 재검색
function plCategoryChange() { plClearTagSel(); plChipContext(); plSearchHere(); }
// 도메인 분리: 한쪽 선택 시 다른쪽 해제
function plFoodDeselect()   { if (plSelGuide || plSelFood.length) { plSelGuide = null; plSelFood = []; plFoodBarRender(); } }
function plTravelDeselect() { if (plSelTags.length || plSelMonth) { plSelTags = []; plSelMonth = null; plTagBarRender(); plMonthBarRender(); } }
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
    plFoodDeselect();                                  // 맛집 선택 해제(검색 도메인 분리)
    if (plSelMonth) plEnsureCat('travel');             // 달은 여행 하위필터 → 분류=여행지
    plMonthBarRender();
    plRunTagSearch();
}
// 1행 렌더 (여행/숙소/기타 테마칩 — 최대 14개 + 더보기)
function plTagBarRender() {
    var box = document.getElementById('tbChips');
    var more = document.getElementById('tbMore');
    var list = plTagBarExpanded ? plTagBarAll : plTagBarAll.slice(0, PL_TAGBAR_TOP);
    box.innerHTML = list.map(function (t) {
        var tg = t.tag.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
        var on = (plSelTags.indexOf(t.tag) >= 0) ? ' active' : '';
        return '<button class="tb-chip' + on + '" onclick="plTagBarClick(\'' + tg + '\')">' +
            plEsc(t.tag) + '<span class="tb-cnt">' + t.cnt + '</span></button>';
    }).join('');
    var hidden = plTagBarAll.length - PL_TAGBAR_TOP;
    if (hidden > 0) {
        more.style.display = '';
        more.textContent = plTagBarExpanded ? '접기' : ('+' + hidden + ' 더보기');
    } else {
        more.style.display = 'none';
    }
    plChipContext();   // 분류에 맞춰 칩 행 표시/숨김
}
function plTagBarToggleMore() { plTagBarExpanded = !plTagBarExpanded; plTagBarRender(); }
// 가이드 칩 클릭 = 단일 선택(재클릭 해제)
function plGuideClick(g) {
    plSelGuide = (plSelGuide === g) ? null : g;
    plTravelDeselect();                                // 여행 선택 해제(도메인 분리)
    if (plSelGuide) plEnsureCat('restaurant');         // 가이드는 맛집 하위필터 → 분류=맛집
    plFoodBarRender();
    plRunTagSearch();
}
// 여행 테마칩 클릭 = 다중 AND 선택(재클릭 해제)
function plTagBarClick(tag) {
    var i = plSelTags.indexOf(tag);
    if (i >= 0) { plSelTags.splice(i, 1); }            // 재클릭=해제
    else {
        if (plSelTags.length >= PL_TAG_MAX) { plHint('태그는 최대 ' + PL_TAG_MAX + '개까지 선택할 수 있습니다'); return; }
        plSelTags.push(tag);                           // 추가(최대 3개 AND)
    }
    plFoodDeselect();                                  // 맛집 선택 해제(도메인 분리)
    if (plSelTags.length) plEnsureCat('travel');       // 테마는 여행 하위필터 → 분류=여행지
    plTagBarRender();
    plRunTagSearch();
}
// 맛집 음식종류·등급칩 클릭 = 다중 AND 선택(재클릭 해제)
function plFoodClick(tag) {
    var i = plSelFood.indexOf(tag);
    if (i >= 0) { plSelFood.splice(i, 1); }            // 재클릭=해제
    else {
        if (plSelFood.length >= PL_TAG_MAX) { plHint('태그는 최대 ' + PL_TAG_MAX + '개까지 선택할 수 있습니다'); return; }
        plSelFood.push(tag);                           // 추가(최대 3개 AND)
    }
    plTravelDeselect();                                // 여행 선택 해제(도메인 분리)
    if (plSelFood.length) plEnsureCat('restaurant');   // 음식종류는 맛집 하위필터 → 분류=맛집
    plFoodBarRender();
    plRunTagSearch();
}
function plClearTagSel() {
    plSelTags = []; plSelMonth = null; plSelGuide = null; plSelFood = [];
    plTagBarRender(); plMonthBarRender(); plFoodBarRender();
}
// 선택된 칩 AND 검색. 맛집(가이드·음식) 선택이 있으면 맛집 검색, 아니면 여행(테마·달).
function plRunTagSearch() {
    if (plSelGuide || plSelFood.length) {              // 맛집 도메인
        plTagSearch(plSelFood.slice(), { guide: plSelGuide, category: 'restaurant' });
        return;
    }
    var tags = plSelTags.slice();                      // 여행 도메인
    if (plSelMonth) tags.push(plSelMonth);
    if (!tags.length) { plSearchHere(); return; }      // 칩 모두 해제 → 이 화면(현 분류) 기준으로 복귀
    plTagSearch(tags, { category: 'travel' });         // 테마/달은 여행지로 한정(맛집 잔여태그 오염 차단)
}
function plTagClear() {
    plClearMarkers(); plFeatures = []; plActive = -1;
    plToggleList(false);
    plHint('선택 해제');
}
function plTagSearch(tags, opts) {
    if (!plReady) return;
    plClearOverlay();              // 베이스 재검색 → 주변 오버레이 해제(idle이 조건 맞으면 다시 표시)
    opts = opts || {};
    var parts = [];
    if (opts.guide && PL_GUIDES[opts.guide]) parts.push(PL_GUIDES[opts.guide].ko);
    parts = parts.concat(tags);
    var label = parts.join(' · ');
    plHint('“' + label + '” 불러오는 중…');
    var params = { module: 'place', action: 'tag_search', tags: tags.join(',') };
    if (opts.guide)    params.guide    = opts.guide;
    if (opts.category) params.category = opts.category;
    fetch(plApiUrl(params))
        .then(function (r) { return r.json(); })
        .then(function (geo) {
            var feats = plSortFeatures((geo && geo.features) || []);
            plClearMarkers(); plActive = -1; plFeatures = feats;
            feats.forEach(plAddMarker);
            plRenderList(feats);
            plRefreshCurrent = function () { plTagSearch(tags, opts); };   // 수정 후 목록 갱신용
            plListMode = 'base';
            plBaseTitle = '🏷 ' + label + ' · 전국 ' + feats.length + '곳';   // 줌아웃 복원용
            document.getElementById('plListTitle').textContent = plBaseTitle;
            plToggleList(feats.length > 0);
            if (feats.length) plFitToFeatures(feats);
            plHint(feats.length ? ('🏷 ' + label + ' · 전국 ' + feats.length + '곳')
                                : ('“' + label + '” 해당 장소가 없습니다'));
        })
        .catch(function () { plHint('검색 실패'); });
}
// 맛집(네이버 리뷰 있는 곳)은 거리 무관 '리뷰 많은 순'. 리뷰 없는 곳은 거리순 유지하며 뒤로.
function plSortFeatures(feats) {
    var rev = function (f) {
        var nv = f.properties && f.properties.attributes && f.properties.attributes.naver;
        return (nv && nv.review != null) ? Number(nv.review) : null;
    };
    feats.sort(function (a, b) {
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

// '이동 시 주변검색' 토글: 켜면 지금 화면을 즉시 한 번 검색(이후 이동마다 자동)
function plAutoToggle() {
    var chk = document.getElementById('autoSearch');
    if (chk && chk.checked) plSearchHere();
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
    var cat = document.getElementById('category').value;
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

// 좌표 기준 검색 → GeoJSON → 마커 렌더
//  expandFrom 숫자면 그 반경 사용(결과 0이면 자동확장). viewport=true 면 뷰포트 반경·확장/드롭다운 갱신 안 함.
function plSearch(lat, lng, expandFrom, viewport) {
    plClearOverlay();                      // 베이스(필터) 재검색 → 주변 오버레이 해제
    if (plChipActive()) plClearTagSel();   // 지역(뷰포트) 검색 시 칩(여행·맛집) 선택 모두 해제
    plMergeSel = [];                                       // 새 검색 시 병합 선택 초기화
    plLastSearchAt = Date.now();                           // idle 자동검색 중복 방지용 타임스탬프
    if (plMap) plLastSearchZoom = plMap.getZoom();         // 줌 변경 감지 기준 갱신
    var rad = (expandFrom != null) ? expandFrom : parseFloat(document.getElementById('radius').value);
    var cat = document.getElementById('category').value;
    var sp = { module: 'place', action: 'search', lat: lat, lng: lng, radius: rad, limit: PL_MAP_LIMIT };
    if (cat === '') {           // 전체: 맛집·스테이·캠핑을 각 기준으로 함께 표시(여행지는 항상)
        var eff = plEffCatMr();
        sp.mr_restaurant = eff.restaurant; sp.mr_stay = eff.stay; sp.mr_camping = eff.camping;
    } else {                    // 단일 분류 선택
        sp.category = cat; sp.min_review = plCatMr(cat);
    }
    fetch(plApiUrl(sp))
        .then(function (r) { return r.json(); })
        .then(function (geo) {
            var feats = (geo && geo.features) || [];

            // 자동 확장: 결과 0곳이고 더 넓힐 수 있으면 다음 반경으로(뷰포트 모드는 제외)
            if (!feats.length && expandFrom != null && !viewport) {
                var nxt = plNextRadius(rad);
                if (nxt) {
                    document.getElementById('radius').value = String(nxt); // 실제 사용 반경 반영
                    plMap.setZoom(plZoomForRadius(nxt));                    // 넓어진 반경에 맞게 줌아웃
                    plHint(rad + 'km에 없음 → ' + nxt + 'km로 확장 검색…');
                    plSearch(lat, lng, nxt);
                    return;
                }
            }

            feats = plSortFeatures(feats);   // 맛집은 리뷰순(거리 무관)
            plClearMarkers();
            plActive = -1;
            plFeatures = feats;
            feats.forEach(plAddMarker);   // (f, idx) — forEach 2번째 인자가 번호
            plRenderList(feats);
            plListMode = 'base';
            plBaseTitle = document.getElementById('plListTitle').textContent;   // '이 화면 N곳' (줌아웃 복원용)
            plRefreshCurrent = function () { plSearch(lat, lng, viewport ? plViewportRadiusKm() : null, viewport); };
            plToggleList(feats.length > 0);
            if (expandFrom != null && !viewport) document.getElementById('radius').value = String(rad);
            var scope = viewport ? '이 화면' : ('반경 ' + Math.round(rad) + 'km');
            plHint(feats.length ? (scope + ' · ' + plCritSummary() + ' · ' + feats.length + '곳')
                                : (scope + ' · ' + plCritSummary() + ' · 표시할 곳 없음'));
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
        icon: plMarkerIcon(pr.category, idx + 1, false, plMkColor(pr), plMkBadge(pr))
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
        var badge = gradeHtml ? '<b class="mk-fnum">' + gradeHtml + '</b>' : '';
        return {
            content: '<div class="mk-food' + (active ? ' active' : '') + '" style="background:' + bg + '">' +
                PL_FORK_SVG + badge + '</div>',
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
    var gb = plGuideBadges(pr.guides);                       // 맛집 가이드 배지
    var gbLine = gb ? '<div class="li-guides">' + gb + '</div>' : '';
    var nvLine = plNaverLine(pr);                            // 네이버 평점·리뷰
    var sub = (pr.tags && pr.tags.length)
        ? '<div class="li-tags">' + pr.tags.slice(0, 6).map(function (t) {
              return '<span class="li-tag">#' + plEsc(t) + '</span>'; }).join('') + '</div>'
        : (gb ? '' : '<div class="li-sub">' + plEsc(CAT_KO[c] || '기타') + '</div>');
    var edit = (!PL_SHARE && pr.id) ? '<button class="li-edit" title="수정" onclick="event.stopPropagation();plEditOpen(' + pr.id + ',\'list\')">✏️</button>' : '';
    var selCls = (pr.id && plMergeSel.indexOf(pr.id) >= 0) ? ' mc-sel' : '';
    var noClick = (!PL_SHARE && pr.id) ? ' onclick="plNoClick(event,' + pr.id + ')"' : '';
    var label = opts.num ? (i + 1) : '';                     // 베이스=번호 / 주변=빈 색원
    return '<div class="pl-li' + selCls + '" id="' + opts.prefix + i + '" data-pid="' + (pr.id || 0) + '" onclick="' + opts.onclickFn + '(' + i + ')">' +
        '<span class="li-no cat-' + c + '"' + noClick + '>' + label + '</span>' +
        '<div class="li-body">' +
            '<div class="li-name"><span class="nm">' + plEsc(pr.name) + '</span>' + refs +
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
    title.textContent = '이 화면 ' + feats.length + '곳';   // 칩 검색은 plTagSearch가 '전국'으로 덮어씀
    body.innerHTML = feats.map(function (f, i) {
        return plLiHtml(f, i, { prefix: 'pl-li-', onclickFn: 'plFocus', num: true });
    }).join('');
    // 통합검색에서 우리 DB 장소를 골랐으면, 그 마커를 강조 + 상세패널 표시
    if (plPendingFocusId != null) {
        for (var k = 0; k < feats.length; k++) {
            if (feats[k].properties && feats[k].properties.id == plPendingFocusId) { plFocus(k); break; }
        }
        plPendingFocusId = null;
    }
    plMergeHeadRender();   // 상단 병합 버튼 상태 동기화 (행 mc-sel 은 렌더 시 반영됨)
    // 경로 모드 중 새 검색이면, 결과 마커를 반경 기준으로 다시 스타일링(반경 안=작은 원)
    if (typeof rtMode !== 'undefined' && rtMode) rtRefresh();
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
        plMarkers[plActive].setIcon(plMarkerIcon(plFeatures[plActive].properties.category, plActive + 1, false, plMkColor(plFeatures[plActive].properties), plMkBadge(plFeatures[plActive].properties)));
    }
    // 새 마커 강조
    if (plMarkers[idx]) plMarkers[idx].setIcon(plMarkerIcon(f.properties.category, idx + 1, true, plMkColor(f.properties), plMkBadge(f.properties)));
    plActive = idx;

    // 리스트 항목 강조 + 스크롤
    var items = document.querySelectorAll('.pl-li');
    for (var i = 0; i < items.length; i++) items[i].classList.remove('active');
    var li = document.getElementById('pl-li-' + idx);
    if (li) { li.classList.add('active'); li.scrollIntoView({ block: 'nearest' }); }

    // 좌표는 geometry 에만 있고 properties 엔 없음 → 패널 버튼·주변검색용으로 주입
    f.properties.lat = co[1];
    f.properties.lng = co[0];
    plOpenPanel(f.properties);

    // 선택 → 반경 3km 지도로 포커스(클릭한 곳이 중심).
    //  단, 이미 그보다 더 확대(줌인)된 상태면 줌아웃하지 않고 현재 줌 유지 + 중심만 이동.
    //  (포커스 목표 줌 ≤ 현재 줌 이면 자동 줌아웃이 일어나므로 panTo 만)
    if (plMap.getZoom() < plFitZoom(co[1], 3)) plFitRadius(co[1], co[0], 3);   // 더 넓게 보던 중 → 줌인 포커스
    else plMap.panTo(new naver.maps.LatLng(co[1], co[0]));                      // 이미 더 확대 → 줌 유지, 중심만
}

var CAT_KO = { travel: '여행지', stay: '숙소', restaurant: '맛집', camping: '캠핑장', etc: '기타' };

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
    if (pr.dist != null) meta.push('↔️ 동선 지점에서 ~' + plEsc(plFmtDist(pr.dist)));
    var tags = (pr.tags && pr.tags.length) ? pr.tags : ((pr.attributes && pr.attributes.tags) || []);
    // 경로 모드 + 좌표가 있으면 '경로에 추가' 버튼 노출
    var addBtn = (typeof rtMode !== 'undefined' && rtMode && pr.lat != null && pr.lng != null)
        ? '<button class="rt-addcur" onclick="rtAddCurrent()">➕ 경로에 추가</button>' : '';
    head.innerHTML =
        '<button class="panel-close" onclick="plClosePanel()">×</button>' +
        '<span class="cat-badge ' + (pr.category || 'etc') + '">' + (CAT_KO[pr.category] || '기타') + '</span>' +
        (plGuideBadges(pr.guides) ? '<div class="panel-guides">' + plGuideBadges(pr.guides) + '</div>' : '') +
        '<h3>' + plEsc(pr.name) + '</h3>' +
        '<div class="meta">' + meta.join('<br>') + '</div>' +
        plNaverHtml(pr) +
        (tags.length ? '<div class="tags">' + tags.map(function (t) { return '<em>' + plEsc(t) + '</em>'; }).join('') + '</div>' : '') +
        addBtn;

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
            (r.summary ? '<div class="rsum">' + plEsc(r.summary) + '</div>' : '') +
            '</a>';
    }).join('');
}

function plClosePanel() { document.getElementById('pl-panel').classList.remove('open'); }

// 기사 원문 → JS 팝업창
function plOpenArticle(url) {
    if (!url) return;
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
var plEditId = 0, plEditResults = [], plEditSel = null, plEditTimer = null, plEditExtras = [], plEditTags = [], plEditGuides = [], plTagListLoaded = false;
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
function plLoadTagDatalist() {
    if (plTagListLoaded) return;
    plTagListLoaded = true;
    fetch(plApiUrl({ module: 'place', action: 'tag_list' }))
        .then(function (r) { return r.json(); })
        .then(function (d) {
            var tags = ((d && d.items) || []).filter(function (t) { return t.kind !== 'month'; }); // 월은 버튼으로
            // datalist 자동완성
            document.getElementById('pemTagList').innerHTML =
                tags.map(function (t) { return '<option value="' + plEsc(t.tag) + '">'; }).join('');
            // 추천 태그(많이 쓴 순 전체) — 클릭해서 재사용 → 동의어 난립 방지
            plTagSuggestAll = tags.map(function (t) { return t.tag; });
            plEditRenderSuggest();
        })
        .catch(function () { plTagListLoaded = false; });
}
var plTagSuggestAll = [], plSuggestExpanded = false;
var PL_SUGGEST_TOP = 12;
function plSuggestToggle() { plSuggestExpanded = !plSuggestExpanded; plEditRenderSuggest(); }
function plEditRenderSuggest() {
    var box = document.getElementById('pemTagSuggest');
    if (!box) return;
    var avail = plTagSuggestAll.filter(function (tg) { return plTagHas('theme', tg) < 0; }); // 이미 단 건 숨김
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
    if (tg && plTagHas('theme', tg) < 0) plEditTags.push({ kind: 'theme', tag: tg });
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
    inp.value.split(',').forEach(function (s) {              // 쉼표로 여러 개 허용
        var tag = plNormTag(s);
        if (tag && plTagHas('theme', tag) < 0) plEditTags.push({ kind: 'theme', tag: tag });
    });
    inp.value = '';
    plEditRenderSuggest(); plEditRenderTagChips();
}
function plEditTagRemove(i) { plEditTags.splice(i, 1); plEditRenderMonths(); plEditRenderSuggest(); plEditRenderTagChips(); plEditRenderCuisines(); }
function plEditRenderTagChips() {
    document.getElementById('pemTags').innerHTML = plEditTags.map(function (t, i) {
        if (t.kind === 'month' || t.kind === 'cuisine') return '';   // 월=버튼, 음식=맛집 섹션
        return '<span class="pem-tag k-' + t.kind + '">' + plEsc(t.tag) +
            '<button onclick="plEditTagRemove(' + i + ')" title="제거">×</button></span>';
    }).join('');
}

// ── 맛집 정보(가이드 + 음식 종류) 편집 ──
function plEditCatChange() {
    document.getElementById('pemFood').style.display =
        (document.getElementById('pemCat').value === 'restaurant') ? '' : 'none';
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
    plTagListLoaded = false;        // 추천/자동완성 갱신되도록
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
function plTagMgrRender() {
    var q = (document.getElementById('tmSearch').value || '').trim().toLowerCase();
    var list = q ? plTagAll.filter(function (t) { return t.tag.toLowerCase().indexOf(q) >= 0; }) : plTagAll;
    document.getElementById('tmCount').textContent = '총 ' + plTagAll.length + '개' + (q ? (' · ' + list.length + ' 일치') : '');
    var grid = document.getElementById('tmGrid');
    if (!plTagAll.length) { grid.innerHTML = '<div class="tm-empty">아직 태그가 없습니다</div>'; plTagActionRender(); return; }
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
    var travel = list.filter(function (t) { return !plTagIsFood(t); });
    var food   = list.filter(plTagIsFood);
    grid.innerHTML = section('🗺️ 여행지 태그', travel) + section('🍜 맛집 태그', food);
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
//  여행지도(경로 만들기) 모드
//   · 지도 마커·좌측 목록 클릭(plFocus 가로채기) 또는 주소 직접 입력으로 경로 지점 추가
//   · 번호 마커(채운 색 물방울) + 동선 polyline 을 검색 마커 위에 겹쳐 표시
//   · 데이터는 클라이언트 전용(localStorage 보존). 서버 변경 없음.
// ══════════════════════════════════════════════════════════
var rtMode = false;
var rtRoute = [];                 // [{name, lat, lng, address, placeId}]
var rtRouteMarkers = [], rtRoutePolyline = null, rtDragIdx = -1;
var rtRadius = 5;                 // 주변 종합 반경(km)
var rtNearby = [];                // 지점별 주변 장소: rtNearby[wi] = [rec...]
var rtNearbyActive = false;       // 종합을 계산했는지
var rtAssign = {};                // 마커 인덱스 → 배정된 지점(반경 안)
var rtFinalized = false;          // '지도 만들기'(영역 밖 마커 숨김) 상태
var rtActiveMi = null;            // 종합에서 클릭해 강조 중인 마커 인덱스
var rtCircles = [];
var RT_COLORS = ['#e74c3c','#2980b9','#27ae60','#e67e22','#8e44ad','#16a085','#d35400','#2c3e50','#c0392b','#1abc9c','#9b59b6','#f39c12'];
function rtColor(i) { return RT_COLORS[i % RT_COLORS.length]; }

function rtToggleMode() {
    rtMode = !rtMode;
    document.body.classList.toggle('rt-on', rtMode);
    var b = document.getElementById('rtModeBtn');
    if (b) { b.classList.toggle('active', rtMode); b.textContent = rtMode ? '🧭 경로 만들기 종료' : '🧭 여행지도 만들기'; }
    if (rtMode) {
        plClosePanel();              // 상세패널 닫고 경로패널로
        plToggleList(false);         // 좌측은 '주변 종합' 패널 차지 → 검색 목록 닫음
        rtUpdateFinalBtn();          // 진입 시 버튼 라벨 동기화(기본=마크보기 → '마크 숨기기')
        rtRenderRows(); rtDraw();
        rtRefresh();                 // 진입 즉시 자동 종합(현재 떠 있는 마커 기준 — 검색 결과 유지)
        plHint('지도 마커를 클릭하거나 주소를 입력해 경로를 만드세요');
        setTimeout(plBumpResize, 60);
    } else {
        rtFinalized = false;
        rtClearLayer(); rtClearCircles(); rtRestoreMarkers();  // 경로/원 감추고 마커 원복(입력 데이터는 보존)
        setTimeout(plBumpResize, 60);
    }
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
    rtRenderRows(); rtDraw(); rtSave(); rtRefresh();
    plHint('경로 ' + rtRoute.length + '번째 추가: ' + wp.name);
}
function rtRemove(i) { rtRoute.splice(i, 1); rtRenderRows(); rtDraw(); rtSave(); rtRefresh(); }
function rtClear() {
    if (rtRoute.length && !confirm('만든 경로를 모두 비울까요?')) return;
    rtRoute = []; rtNearby = []; rtAssign = {};
    rtClearCircles(); rtRestoreMarkers();
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
        var mk = new naver.maps.Marker({
            position: pos, map: plMap, zIndex: 400, title: (i + 1) + '. ' + wp.name,
            icon: { content: '<div class="rt-pin" style="background:' + rtColor(i) + '"><b>' + (i + 1) + '</b></div>', anchor: new naver.maps.Point(15, 30) }
        });
        (function (idx, p) {
            naver.maps.Event.addListener(mk, 'click', function () {
                plMap.panTo(p);
                var r = document.getElementById('rt-row-' + idx);
                if (r) { r.scrollIntoView({ block: 'nearest' }); r.style.background = '#efe9ff'; setTimeout(function () { r.style.background = ''; }, 700); }
            });
        })(i, pos);
        rtRouteMarkers.push(mk);
    });
    if (path.length >= 2) {
        rtRoutePolyline = new naver.maps.Polyline({
            map: plMap, path: path, strokeColor: '#5a4cd0', strokeWeight: 5, strokeOpacity: 0.85,
            strokeLineCap: 'round', strokeLineJoin: 'round', zIndex: 350
        });
    }
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
    rtRenderRows(); rtDraw(); rtSave(); rtRefresh();
}
function rtDragEnd(e) { rtDragIdx = -1; document.querySelectorAll('#rtRows .rt-row').forEach(function (r) { r.classList.remove('dragging', 'drag-over'); }); }

// ── 반경 컨트롤 ──
function rtSetRadius(km) {
    rtRadius = km;
    var inp = document.getElementById('rtRadius'); if (inp) inp.value = km;
    document.querySelectorAll('.rt-rq').forEach(function (b) { b.classList.toggle('on', +b.getAttribute('data-r') === km); });
    rtRefresh();
}
function rtRadiusInput() {
    var v = parseFloat(document.getElementById('rtRadius').value);
    if (isNaN(v) || v <= 0) v = 5;
    v = Math.min(50, Math.max(0.5, v));
    rtRadius = v;
    document.getElementById('rtRadius').value = v;
    document.querySelectorAll('.rt-rq').forEach(function (b) { b.classList.toggle('on', +b.getAttribute('data-r') === v); });
    rtRefresh();
}

// 거리(km) — Haversine
function rtHaversine(la1, lo1, la2, lo2) {
    var R = 6371, d2r = Math.PI / 180;
    var dLa = (la2 - la1) * d2r, dLo = (lo2 - lo1) * d2r;
    var a = Math.sin(dLa / 2) * Math.sin(dLa / 2) +
            Math.cos(la1 * d2r) * Math.cos(la2 * d2r) * Math.sin(dLo / 2) * Math.sin(dLo / 2);
    return 2 * R * Math.asin(Math.min(1, Math.sqrt(a)));
}

// ── 경로 주변 종합 ──
//  새로 검색하지 않고, 지금 지도에 떠 있는 마커(plFeatures = 태그/지역 검색 결과)를
//  각 지점 반경으로 판정 → 가장 가까운 지점에 1번만 배정. (검색 결과를 지우지 않음)
function rtComputeNearby() {
    rtAssign = {};                       // 마커 인덱스 → 배정된 지점
    rtNearby = rtRoute.map(function () { return []; });
    if (!rtRoute.length || !plFeatures.length) return;
    for (var i = 0; i < plFeatures.length; i++) {
        var co = plFeatures[i].geometry.coordinates;   // [lng, lat]
        var lat = co[1], lng = co[0], best = -1, bestD = Infinity;
        for (var w = 0; w < rtRoute.length; w++) {
            var d = rtHaversine(lat, lng, rtRoute[w].lat, rtRoute[w].lng);
            if (d <= rtRadius && d < bestD) { bestD = d; best = w; }
        }
        if (best >= 0) {
            rtAssign[i] = best;
            var rec = Object.assign({}, plFeatures[i].properties, { lat: lat, lng: lng, dist: bestD, _mi: i });
            rtNearby[best].push(rec);
        }
    }
    rtNearby.forEach(function (a) { a.sort(function (x, y) { return x.dist - y.dist; }); });
}

// 마커 스타일링: 반경 안 → 지점 색 작은 원 / 반경 밖 → 원래 번호 마커(단, 지도만들기 ON 이면 숨김)
function rtStyleMarkers() {
    rtActiveMi = null;
    for (var i = 0; i < plFeatures.length; i++) {
        var mk = plMarkers[i]; if (!mk) continue;
        var wi = rtAssign[i];
        if (wi != null) {                 // 반경 안 → 작은 색 원
            mk.setMap(plMap);
            mk.setIcon({ content: '<div class="rt-spot" style="background:' + rtColor(wi) + '"></div>', anchor: new naver.maps.Point(8, 8) });
            mk.setZIndex(120);
        } else if (rtFinalized) {          // 반경 밖 + 지도만들기 ON → 숨김
            mk.setMap(null);
        } else {                           // 반경 밖 → 원래 마커 그대로
            mk.setMap(plMap);
            mk.setIcon(plMarkerIcon(plFeatures[i].properties.category, i + 1, false, plMkColor(plFeatures[i].properties), plMkBadge(plFeatures[i].properties)));
            mk.setZIndex(100);
        }
    }
}
// 경로 모드 종료 시: 모든 마커를 원래 상태로 복원
function rtRestoreMarkers() {
    for (var i = 0; i < plFeatures.length; i++) {
        var mk = plMarkers[i]; if (!mk) continue;
        mk.setMap(plMap);
        mk.setIcon(plMarkerIcon(plFeatures[i].properties.category, i + 1, false, plMkColor(plFeatures[i].properties), plMkBadge(plFeatures[i].properties)));
        mk.setZIndex(100);
    }
}

// 경로/반경/마커 변경 시 자동 갱신(검색 X, 즉시)
function rtRefresh() {
    if (!rtMode) return;
    rtNearbyActive = true;
    rtComputeNearby();
    rtStyleMarkers();
    rtDrawCircles();
    rtRenderSummary();
}

// 마크 보기 / 마크 숨기기 토글 — 경로 반경 밖 마커를 감추거나 다시 표시(기본=마크보기)
function rtFinalize() {
    if (!rtFinalized && !rtRoute.length) { plHint('먼저 경로를 추가하세요'); return; }
    rtFinalized = !rtFinalized;
    rtUpdateFinalBtn();
    rtStyleMarkers();
    plHint(rtFinalized ? '경로 영역 밖 마커를 숨겼습니다' : '전체 마커를 다시 표시합니다');
}
function rtUpdateFinalBtn() {
    var b = document.getElementById('rtFinalBtn');
    if (!b) return;
    b.textContent = rtFinalized ? '👁️ 마크 보기' : '🙈 마크 숨기기';   // 라벨 = 누르면 할 동작
    b.classList.toggle('on', rtFinalized);
}

// 반경 원(지점 색상)
function rtToggleCircles() { rtDrawCircles(); }
function rtDrawCircles() {
    rtClearCircles();
    var chk = document.getElementById('rtCircleChk');
    if (!rtMode || !chk || !chk.checked) return;
    rtRoute.forEach(function (wp, i) {
        rtCircles.push(new naver.maps.Circle({
            map: plMap, center: new naver.maps.LatLng(wp.lat, wp.lng), radius: rtRadius * 1000,
            strokeColor: rtColor(i), strokeWeight: 1.5, strokeOpacity: 0.7,
            fillColor: rtColor(i), fillOpacity: 0.06, zIndex: 60
        }));
    });
}
function rtClearCircles() { rtCircles.forEach(function (c) { c.setMap(null); }); rtCircles = []; }

// 종합(지점별 주변 장소) 패널 렌더
function rtRenderSummary() {
    var box = document.getElementById('rtSum'); if (!box) return;
    var cnt = document.getElementById('rtSumCnt');
    var showDist = (function () { var c = document.getElementById('rtDistChk'); return !c || c.checked; })();
    if (!rtNearbyActive || !rtRoute.length) {
        if (cnt) cnt.textContent = '';
        box.innerHTML = '<div class="rt-sum-empty">경로를 추가하면 각 지점 반경 안의<br>장소가 자동으로 여기에 모입니다.</div>';
        return;
    }
    var total = 0; rtNearby.forEach(function (a) { total += (a ? a.length : 0); });
    if (cnt) cnt.textContent = '반경 ' + rtRadius + 'km · ' + total + '곳';
    box.innerHTML = rtRoute.map(function (wp, wi) {
        var col = rtColor(wi);
        var arr = rtNearby[wi] || [];
        var places = arr.length
            ? arr.map(function (s) {
                var d = showDist ? '<span class="rt-pdist">~' + plFmtDist(s.dist) + '</span>' : '';
                return '<div class="rt-place" id="rt-pl-' + wi + '-' + s.id + '" onclick="rtSpotFocusById(' + wi + ',' + s.id + ')">' +
                    '<span class="rt-pdot" style="background:' + col + '"></span>' +
                    '<div class="rt-pbody"><div class="rt-pname">' + plEsc(s.name) + '</div>' +
                        '<div class="rt-pcat">' + plEsc(CAT_KO[s.category] || '기타') + (s.ref_count ? ' · 기사 ' + s.ref_count : '') + '</div>' +
                    '</div>' + d +
                '</div>';
            }).join('')
            : '<div class="rt-wp-none">반경 ' + rtRadius + 'km 안에 등록된 장소가 없습니다</div>';
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

// 지점 헤더 클릭 → 그 지점으로 이동
function rtFocusWaypoint(wi) {
    var wp = rtRoute[wi]; if (!wp) return;
    plMap.morph(new naver.maps.LatLng(wp.lat, wp.lng), Math.max(11, plZoomForRadius(rtRadius)));
}

// 스팟 마커/종합 항목 클릭 → 지도 이동 + 강조 + 상세패널(기존 plOpenPanel 재사용)
function rtSpotFocusById(wi, pid) {
    var arr = rtNearby[wi] || [];
    var s = arr.filter(function (x) { return x.id == pid; })[0];
    if (s) rtSpotFocus(s, wi);
}
function rtSpotFocus(s, wi) {
    if (!s) return;
    plMap.panTo(new naver.maps.LatLng(s.lat, s.lng));
    // 마커 강조 토글(반경 안 작은 원 → 강조 큰 원)
    if (rtActiveMi != null && plMarkers[rtActiveMi] && rtAssign[rtActiveMi] != null) {
        plMarkers[rtActiveMi].setIcon({ content: '<div class="rt-spot" style="background:' + rtColor(rtAssign[rtActiveMi]) + '"></div>', anchor: new naver.maps.Point(8, 8) });
    }
    var mi = s._mi;
    if (mi != null && plMarkers[mi]) {
        plMarkers[mi].setIcon({ content: '<div class="rt-spot active" style="background:' + rtColor(wi) + '"></div>', anchor: new naver.maps.Point(11, 11) });
        rtActiveMi = mi;
    }
    // 리스트 강조
    document.querySelectorAll('#rtSum .rt-place').forEach(function (el) { el.classList.remove('active'); });
    var li = document.getElementById('rt-pl-' + (wi != null ? wi : '') + '-' + s.id);
    if (li) { li.classList.add('active'); li.scrollIntoView({ block: 'nearest' }); }
    plOpenPanel(s);   // 상세 패널(분류·주소·태그·출처) — 경로 패널 위로 슬라이드
}

// localStorage 보존(새로고침 대비)
function rtSave() { try { localStorage.setItem('placesRoute', JSON.stringify(rtRoute)); } catch (e) {} }
function rtLoad() {
    try { var raw = localStorage.getItem('placesRoute'); if (raw) { var a = JSON.parse(raw); if (Array.isArray(a)) rtRoute = a; } } catch (e) {}
}
rtLoad();
</script>
</body>
</html>
<?php /* end places.php */ ?>
