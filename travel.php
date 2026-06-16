<?php
require_once "./env/cnt.inc";
require_once "./env/auth_fnc.php";
require_once "./env/nav.inc";
if (file_exists("./env/gdrive.inc")) require_once "./env/gdrive.inc";
if (file_exists("./env/maps.inc"))   require_once "./env/maps.inc";
if (file_exists("./env/kakao.inc"))  require_once "./env/kakao.inc"; // 역지오코딩(좌표→주소)용 카카오 키

// ── 한시적 공개 공유 링크: 비로그인 게스트 읽기전용 보기 (로그인 게이트보다 먼저) ──
$shareToken = $_GET['share'] ?? '';
if ($shareToken !== '') {
    $tv    = new Travel($pdo);
    $tv->ensureTable();
    $share = $tv->getValidShare($shareToken);
    if ($share) {
        travel_view($pdo, true, (int)$share['travel_id'], $share['expires_at'] ?? null);   // guest=true + 만료시각
    } else {
        travel_share_invalid();                              // 만료/무효 링크
    }
    exit;
}

// ── 일반(로그인) 흐름 ──
require_login();

$mode = $_GET['mode'] ?? 'list';

$routes = [
    'list' => 'travel_list',
    'view' => 'travel_view',
];

if (isset($routes[$mode]) && function_exists($routes[$mode])) {
    $routes[$mode]($pdo);
} else {
    travel_list($pdo);
}

// ##########################################################
// 공통 chrome (nav + 기본 스타일)
// ##########################################################
function travel_head(string $title, bool $guest = false): void {
    global $current_user, $expire_date;
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($title) ?> — 이코노미스트</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
html, body { overflow-x:hidden; }
body { font-family:'Pretendard','Malgun Gothic',sans-serif; background:#f0f2f5; color:#2c3e50; height:100vh; display:flex; flex-direction:column; overflow:hidden; }
#wrap { flex:1; overflow:auto; padding:20px; }
.inner { max-width:1100px; margin:0 auto; }
/* 목록 카드 */
.tv-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:18px; }
.tv-card { background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,.08); text-decoration:none; color:inherit; transition:.15s; display:flex; flex-direction:column; }
.tv-card:hover { transform:translateY(-3px); box-shadow:0 6px 18px rgba(0,0,0,.14); }
.tv-cover { aspect-ratio:4/3; background:#dfe4ea center/cover no-repeat; position:relative; }
.tv-cover-img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; display:block; }
.tv-meta { padding:12px 14px; }
.tv-meta h3 { font-size:17px; margin-bottom:4px; }
.tv-meta h3 .pc { font-size:12px; font-weight:600; color:#e67e22; background:#fdebd0; padding:1px 8px; border-radius:20px; margin-left:6px; vertical-align:middle; white-space:nowrap; }
.tv-meta .period { font-size:13px; color:#7f8c8d; }
.dur { display:inline-block; background:#eef2f7; color:#5a6b7b; font-size:11px; font-weight:600; padding:1px 8px; border-radius:20px; margin-left:6px; white-space:nowrap; vertical-align:middle; }
.empty { background:#fff; border-radius:12px; padding:40px; text-align:center; color:#7f8c8d; }
/* 연도 그룹 헤딩 */
.tv-year { font-size:19px; font-weight:800; color:#34495e; margin:26px 0 12px; padding-bottom:6px; border-bottom:2px solid #f0d9bf; }
.tv-year:first-of-type { margin-top:8px; }
/* 목록 헤더 + 동기화 버튼 */
.tv-listhead { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:8px; }
.tv-listhead h2 { font-size:22px; }
.tv-sync { background:#e67e22; color:#fff; border:none; border-radius:8px; padding:10px 16px; font-size:14px; font-weight:600; cursor:pointer; transition:.15s; }
.tv-sync:hover { background:#d35400; }
.tv-sync:disabled { background:#bbb; cursor:default; }
.tv-sync-status { font-size:13px; min-height:18px; margin-bottom:14px; }
.tv-sync-status.loading { color:#e67e22; }
.tv-sync-status.ok { color:#27ae60; }
.tv-sync-status.err { color:#c0392b; }
.tv-refresh { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin:-8px 0 20px; }
.tv-refresh .tv-sync { padding:7px 13px; font-size:13px; }
.tv-refresh .tv-sync-status { margin-bottom:0; }
/* 상세 갤러리 */
.tv-back { display:inline-block; color:#3498db; text-decoration:none; font-size:14px; margin-bottom:12px; }
.tv-title { font-size:24px; margin-bottom:4px; }
.tv-sub { color:#7f8c8d; font-size:14px; margin-bottom:18px; }
/* 상세보기 콘텐츠 폭 = 지도/리스트와 동일(810px) 한 컬럼 */
.tv-detail { max-width:810px; }
.tv-map-wrap { position:relative; width:810px; max-width:100%; margin-bottom:8px; }
#tv-map { width:100%; height:320px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.08); background:#e8ebee; }
/* 동선 지도 전체화면 토글 */
.tv-mapfull-btn { position:absolute; top:8px; right:8px; z-index:5; width:34px; height:34px; padding:0; border:none; border-radius:8px; background:rgba(0,0,0,.55); color:#fff; font-size:17px; line-height:34px; text-align:center; cursor:pointer; transition:.15s; }
.tv-mapfull-btn:hover { background:rgba(0,0,0,.78); }
.tv-mapfull-close { display:none; position:fixed; top:14px; right:14px; z-index:1901; width:40px; height:40px; padding:0; border:none; border-radius:50%; background:rgba(0,0,0,.6); color:#fff; font-size:18px; line-height:40px; text-align:center; cursor:pointer; }
.tv-map-wrap.full { position:fixed; inset:0; z-index:1900; width:auto; max-width:none; margin:0; }
.tv-map-wrap.full #tv-map { height:100%; border-radius:0; box-shadow:none; }
.tv-map-wrap.full .tv-mapfull-btn { display:none; }
.tv-map-wrap.full .tv-mapfull-close { display:block; }
body.tv-map-lock { overflow:hidden; }
.map-note { font-size:12px; color:#95a5a6; margin-bottom:22px; }
.day-sec { margin-bottom:26px; }
.day-head { font-size:16px; font-weight:700; color:#34495e; margin-bottom:12px; padding-left:8px; border-left:4px solid #e67e22; }
.day-sec--misc { margin-top:34px; padding-top:20px; border-top:1px dashed #cfd6dd; }
.day-sec--misc .day-head { color:#7f8c8d; border-left-color:#b0b8c0; }
.misc-note { font-size:12.5px; color:#8a949c; background:#f6f8fa; border:1px solid #e6eaee; border-radius:8px; padding:8px 11px; margin-bottom:12px; line-height:1.5; }
.shots { display:flex; flex-wrap:wrap; gap:12px; }
.shot { width:150px; }
.shot a { display:block; aspect-ratio:1; border-radius:10px; overflow:hidden; background:#dfe4ea center/cover no-repeat; box-shadow:0 1px 4px rgba(0,0,0,.1); }
.shot .t { font-size:12px; color:#7f8c8d; text-align:center; margin-top:4px; }
.shot .nogps { color:#bbb; font-size:11px; }
.pin-num { background:#e67e22; color:#fff; width:24px; height:24px; border-radius:50% 50% 50% 0; transform:rotate(-45deg); display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:bold; box-shadow:0 1px 4px rgba(0,0,0,.4); }
.pin-num span { transform:rotate(45deg); }
/* 상세 — 일기(다이어리) 레이아웃 */
.tv-headrow { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:4px; }
.tv-headrow .tv-title { margin-bottom:0; }
.tv-rf-top { flex-shrink:0; padding:7px 13px; font-size:13px; white-space:nowrap; }
.tv-head-btns { display:flex; gap:8px; flex-shrink:0; flex-wrap:wrap; }
.tv-share-btn { background:#16a085; }
.tv-share-btn:hover { background:#117a65; }
/* 통계줄 + 버튼 한 줄 (제목 아래) */
.tv-subrow { display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-bottom:18px; }
.tv-subrow .tv-sub { margin-bottom:0; }
/* 편집(썸네일 ✕ 토글) 버튼 — 와이드모바일에서만 노출 */
.tv-edit-btn { display:inline-flex; align-items:center; background:#7f8c8d; }
.tv-edit-btn:hover { background:#5f6c75; }
.tv-edit-btn.on { background:#c0392b; }
/* 편집 모드 ON → 메모 연필 + 썸네일 ✕ 동시 노출 (PC·와이드모바일 통일) */
body.tv-edit-on .ps-del { display:block; opacity:1; }
body.tv-edit-on .dm-edit { display:inline-block; }
/* 공유 패널 */
.tv-share { background:#fff; border:1px solid #e6e9ee; border-radius:10px; padding:12px 14px; margin-bottom:14px; box-shadow:0 2px 8px rgba(0,0,0,.06); }
.tv-share .ts-row { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.tv-share label { font-size:13px; color:#5a6b7b; font-weight:600; }
.tv-share select { padding:6px 10px; border:1px solid #d8dde3; border-radius:8px; font-size:13px; }
.tv-share .ts-hint { font-size:12px; color:#95a5a6; margin-top:8px; }
.ts-result { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-top:10px; }
.ts-result #ts-url { flex:1; min-width:220px; padding:7px 10px; border:1px solid #d8dde3; border-radius:8px; font-size:13px; background:#f8fafc; color:#34495e; }
.ts-exp { width:100%; font-size:12px; color:#e67e22; }
/* 공유 중 만료 배너 */
.tv-share-on { background:#eafaf1; border:1px solid #abebc6; color:#1e8449; border-radius:10px; padding:10px 14px; font-size:13px; line-height:1.5; margin-bottom:14px; word-break:keep-all; }
.tv-share-on .muted { color:#7f8c8d; }
.tv-share-on .ts-manage { color:#16a085; font-weight:600; text-decoration:none; margin-left:4px; white-space:nowrap; }
.tv-share-on .ts-manage:hover { text-decoration:underline; }
/* 게스트(공유 링크) 상단바 */
.guest-bar { background:#2c3e50; color:#f1c40f; font-weight:700; font-size:16px; padding:13px 18px; flex-shrink:0; }
.diary-list { display:flex; flex-direction:column; gap:16px; align-items:flex-start; }
.diary-row { display:grid; grid-template-columns:170px 260px 320px; gap:16px; background:#fff; border-radius:14px; box-shadow:0 2px 8px rgba(0,0,0,.07); padding:14px; max-width:100%; }
.dr-photo { display:block; border-radius:10px; overflow:hidden; background:#dfe4ea center/cover no-repeat; aspect-ratio:3/4; box-shadow:0 1px 4px rgba(0,0,0,.12); cursor:pointer; }
.dr-loc { display:flex; flex-direction:column; gap:6px; min-width:0; }
.dr-time { font-size:13px; font-weight:700; color:#34495e; }
.dr-addr { font-size:13px; color:#5a6b7b; line-height:1.45; word-break:keep-all; }
.dr-addr .pin { color:#e67e22; }
.dr-map { position:relative; width:100%; height:150px; border-radius:10px; overflow:hidden; background:#eef1f4; box-shadow:0 1px 3px rgba(0,0,0,.12); }
/* 해외+구글키 없을 때 미니맵 자리 폴백 링크 */
.dr-map-ext { display:flex; align-items:center; justify-content:center; text-align:center; height:150px; border-radius:10px; background:#eef1f4; color:#2c6cb0; font-size:13px; font-weight:600; text-decoration:none; box-shadow:0 1px 3px rgba(0,0,0,.12); }
.dr-map-ext:hover { background:#e3e9f0; }
.tv-map-fallback { width:810px; max-width:100%; background:#eef3f8; border:1px dashed #b9c7d6; border-radius:12px; padding:14px 16px; margin-bottom:8px; font-size:14px; color:#34495e; }
.tv-map-fallback a { color:#2c6cb0; font-weight:600; }
.tv-map-fallback .muted { color:#8a97a4; font-size:12px; display:block; margin-top:4px; }
.dr-map-canvas { width:100%; height:100%; }
.dr-map-hit { position:absolute; inset:0; z-index:5; cursor:pointer; }
.dr-map-hit::after { content:'🔍 크게 보기'; position:absolute; right:6px; bottom:6px; background:rgba(0,0,0,.55); color:#fff; font-size:11px; padding:2px 7px; border-radius:12px; opacity:0; transition:.15s; }
.dr-map:hover .dr-map-hit::after { opacity:1; }
.dr-links { display:flex; gap:14px; font-size:12px; flex-wrap:wrap; }
.dr-links a { color:#3498db; text-decoration:none; }
.dr-links a:hover { text-decoration:underline; }
.dr-nogps { font-size:13px; color:#b0b8c0; padding:8px 0; }
.dr-memo { display:flex; flex-direction:column; background:#fffdf7; border:1px solid #f3e7c9; border-radius:10px; padding:12px 14px; min-width:0; }
.dr-memo .dm-body { flex:1; font-size:14px; color:#4a4a4a; line-height:1.6; word-break:break-word; }
.dr-memo .dm-empty { color:#bcae8a; font-style:italic; }
.dr-memo.dm-blank { background:none; border:none; }
.ps-top { display:flex; align-items:center; justify-content:flex-end; gap:8px; min-height:20px; }
.dm-edit { display:none; background:none; border:none; color:#b8860b; font-size:15px; line-height:1; padding:3px 6px; border-radius:8px; cursor:pointer; opacity:.7; transition:.15s; flex-shrink:0; }
.dm-edit:hover { opacity:1; background:#f6efda; }
.dm-ta { width:100%; min-height:90px; border:1px solid #e0d6b8; border-radius:8px; padding:8px; font-size:14px; font-family:inherit; line-height:1.6; resize:vertical; box-sizing:border-box; }
.dm-actions { display:flex; gap:8px; justify-content:flex-end; margin-top:8px; }
.dm-save { background:#e67e22; color:#fff; border:none; padding:5px 14px; border-radius:16px; font-size:12px; font-weight:600; cursor:pointer; }
.dm-cancel { background:#eef2f7; color:#5a6b7b; border:none; padding:5px 14px; border-radius:16px; font-size:12px; font-weight:600; cursor:pointer; }
/* 상세 — 장소(같은 위치 ≤100m) 그룹 카드: 지도 1개 + 사진별 메모 리스트 */
.place-card { background:#fff; border-radius:14px; box-shadow:0 2px 8px rgba(0,0,0,.07); padding:14px; max-width:100%; margin-bottom:16px; }
.place-head { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:12px; }
.place-head .ph-addr { font-size:15px; font-weight:700; color:#34495e; word-break:keep-all; }
.place-head .ph-time { font-size:13px; color:#7f8c8d; }
.place-head .ph-count { font-size:12px; font-weight:600; color:#e67e22; background:#fdebd0; padding:1px 9px; border-radius:20px; white-space:nowrap; }
.place-head .ph-setloc { margin-left:auto; background:#eaf4ff; border:1px solid #b9dcff; color:#1a73c2; font-size:12px; font-weight:600; padding:3px 10px; border-radius:14px; cursor:pointer; white-space:nowrap; transition:.15s; }
.place-head .ph-setloc:hover { background:#1a73c2; color:#fff; border-color:#1a73c2; }
/* 위치 지정 모달 (위치 미상 사진에 좌표 수동 등록) */
.tv-locmodal { display:none; position:fixed; inset:0; z-index:2100; background:rgba(0,0,0,.55); align-items:center; justify-content:center; padding:16px; }
.tv-locmodal.on { display:flex; }
.tv-locbox { position:relative; background:#fff; border-radius:16px; width:min(440px,100%); max-height:88vh; overflow:auto; padding:22px 20px 18px; box-shadow:0 18px 50px rgba(0,0,0,.3); }
.tv-loc-title { margin:0 0 4px; font-size:18px; color:#2c3e50; }
.tv-loc-sub { margin:0 0 14px; font-size:12.5px; color:#8a949c; line-height:1.5; }
.tv-loc-search { display:flex; gap:7px; }
.tv-loc-search input { flex:1; padding:9px 11px; border:1px solid #ccd3da; border-radius:9px; font-size:14px; }
.tv-loc-search button, .tv-loc-manual button { padding:9px 14px; border:none; border-radius:9px; background:#1a73c2; color:#fff; font-weight:600; cursor:pointer; white-space:nowrap; }
.tv-loc-results { margin-top:10px; max-height:34vh; overflow:auto; }
.tv-loc-item { padding:9px 11px; border:1px solid #eef1f4; border-radius:9px; margin-bottom:6px; cursor:pointer; transition:.12s; }
.tv-loc-item:hover { background:#f3f8ff; border-color:#cfe3fb; }
.tv-loc-item.sel { background:#e7f2ff; border-color:#1a73c2; }
.tv-loc-item .tl-name { font-size:14px; font-weight:600; color:#2c3e50; }
.tv-loc-item .tl-addr { font-size:12px; color:#8a949c; margin-top:2px; }
.tv-loc-empty { font-size:13px; color:#8a949c; padding:10px 4px; }
.tv-loc-manual { margin-top:12px; font-size:12.5px; color:#7f8c8d; display:flex; gap:7px; align-items:center; flex-wrap:wrap; }
.tv-loc-manual input { flex:1; min-width:160px; padding:8px 10px; border:1px solid #ccd3da; border-radius:9px; font-size:13px; }
.tv-loc-spread { display:flex; align-items:center; gap:6px; flex-wrap:wrap; margin-top:12px; font-size:13px; color:#34495e; }
.tv-loc-spread input[type=number] { width:54px; padding:5px 6px; border:1px solid #ccd3da; border-radius:7px; font-size:13px; text-align:center; }
.tv-loc-spread input[type=checkbox] { width:16px; height:16px; }
.tv-loc-foot { display:flex; align-items:center; gap:10px; margin-top:16px; padding-top:13px; border-top:1px solid #eef1f4; }
.tv-loc-sel { flex:1; font-size:13px; color:#1a73c2; font-weight:600; word-break:break-all; }
.tv-loc-save { padding:10px 16px; border:none; border-radius:10px; background:#27ae60; color:#fff; font-weight:700; cursor:pointer; white-space:nowrap; }
.tv-loc-save:disabled { background:#c5ccd2; cursor:not-allowed; }
.place-body { display:grid; grid-template-columns:1fr 1fr; gap:16px 18px; align-items:stretch; }
.place-map { min-height:0; height:auto; align-self:stretch; }   /* 시각+사진 높이에 맞춰 늘어남 */
.pshot { display:grid; grid-template-columns:100px 1fr; gap:14px; align-items:start; position:relative; }
.ps-thumb { display:block; width:100px; aspect-ratio:3/4; flex-shrink:0; border-radius:10px; overflow:hidden; background:#dfe4ea center/cover no-repeat; box-shadow:0 1px 4px rgba(0,0,0,.12); cursor:pointer; }
/* 사진 칸 = [촬영시각 줄] + [썸네일] (지도 높이를 시각+사진에 맞추기 위함) */
.ps-col { display:flex; flex-direction:column; gap:5px; min-width:0; }
.ps-time { font-size:12px; font-weight:700; color:#34495e; line-height:1.2; padding-left:2px; white-space:nowrap; }
.ps-del { display:none; position:absolute; top:5px; right:5px; z-index:3; width:23px; height:23px; padding:0; border:none; border-radius:50%; background:rgba(0,0,0,.55); color:#fff; font-size:13px; line-height:23px; text-align:center; cursor:pointer; transition:.15s; }
.ps-del:hover { background:#e74c3c; transform:scale(1.08); }
/* 동영상 썸네일 ▶ 재생 배지 (가운데) */
.ps-thumb { position:relative; }
.ps-thumb.is-vid::after { content:''; position:absolute; inset:0; background:rgba(0,0,0,.18); }
.ps-play { position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); z-index:2; width:38px; height:38px; border-radius:50%; background:rgba(0,0,0,.6); color:#fff; font-size:15px; line-height:38px; text-align:center; padding-left:3px; box-shadow:0 1px 6px rgba(0,0,0,.4); pointer-events:none; }
.pshot .ps-side { display:flex; flex-direction:column; gap:6px; min-width:0; }
.pshot .ps-side .dr-memo { flex:0 0 auto; align-self:stretch; }
/* 메모 없는 사진 = 컴팩트 썸네일 줄 (그리드 전체폭 차지) */
.ps-strip { grid-column:1 / -1; display:flex; flex-wrap:wrap; gap:12px 10px; }
.ps-chip { position:relative; width:84px; }
.ps-chip .ps-thumb { width:84px; aspect-ratio:3/4; }
.ps-chip-time { font-size:11px; color:#7f8c8d; text-align:center; margin-top:3px; white-space:nowrap; }
.ps-memo-add { display:none; position:absolute; top:5px; left:5px; z-index:3; width:23px; height:23px; padding:0; border:none; border-radius:50%; background:rgba(0,0,0,.55); color:#fff; font-size:12px; line-height:23px; text-align:center; cursor:pointer; transition:.15s; }
.ps-memo-add:hover { background:#b8860b; transform:scale(1.08); }
body.tv-edit-on .ps-memo-add { display:block; }
/* 사진 1장 위치 지정 버튼(썸네일 하단 좌측). GPS 없는 사진엔 항상, 그 외엔 편집모드에서 노출 */
.ps-loc { display:none; position:absolute; bottom:5px; left:5px; z-index:3; height:22px; padding:0 8px; border:none; border-radius:12px; background:rgba(26,115,194,.9); color:#fff; font-size:11px; font-weight:600; line-height:22px; cursor:pointer; transition:.15s; }
.ps-loc:hover { background:#1a73c2; transform:scale(1.06); }
.pshot[data-nogps="1"] .ps-loc, .ps-chip[data-nogps="1"] .ps-loc { display:block; }
body.tv-edit-on .ps-loc { display:block; }
/* 칩 인라인 메모 편집기 (저장 시 풀 카드로 재배치되도록 reload) */
.ps-chip-editor { flex:0 0 100%; }
/* 메모 사진이 없는 장소 → 지도를 전체폭 배너로 */
.place-map--full { grid-column:1 / -1; height:200px; }
/* 지도 팝업 모달 */
.tv-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.6); z-index:2000; align-items:center; justify-content:center; padding:20px; }
.tv-modal.on { display:flex; }
.tv-modal-box { background:#fff; border-radius:14px; overflow:hidden; width:min(560px,94vw); box-shadow:0 12px 40px rgba(0,0,0,.4); position:relative; }
.tv-modal-x { position:absolute; top:8px; right:8px; z-index:5; background:rgba(0,0,0,.55); color:#fff; border:none; width:32px; height:32px; border-radius:50%; font-size:16px; cursor:pointer; }
.tv-modal-photo { position:relative; line-height:0; padding:12px; background:#fff; }
#tv-modal-thumb { width:100%; max-height:46vh; object-fit:cover; display:block; border:1px solid #e3e7ec; border-radius:12px; box-shadow:0 1px 4px rgba(0,0,0,.12); }
#tv-modal-map { width:100%; aspect-ratio:1/1; max-height:80vh; background:#e8ebee; }
/* 썸네일+지도 동시 표시: 둘 다 보이게 높이 분배 */
.tv-modal.has-thumb #tv-modal-thumb { max-height:38vh; }
.tv-modal.has-thumb #tv-modal-map { aspect-ratio:auto; height:240px; max-height:34vh; }
.tv-modal-cap { padding:10px 14px; font-size:13px; color:#34495e; }
/* 지도 아래 메모 블록 */
.tv-modal-memo { display:none; padding:12px 16px 10px; background:#fafbfc; color:#1c2b3a; font-size:16px; line-height:1.5; white-space:pre-wrap; word-break:break-word; max-height:22vh; overflow:auto; }
.tv-modal-memo.on { display:block; }
.tv-modal-memo::before { content:'📝 '; }
/* 동선 이전/다음 화살표 */
.tv-modal-nav { position:absolute; top:19vh; transform:translateY(-50%); z-index:6; background:rgba(0,0,0,.5); color:#fff; border:none; width:58px; height:58px; border-radius:50%; font-size:38px; line-height:1; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:.15s; }
.tv-modal-nav:hover { background:rgba(0,0,0,.78); }
.tv-modal-nav.prev { left:8px; }
.tv-modal-nav.next { right:8px; }
.tv-modal-nav[hidden] { display:none; }
.tv-modal:not(.has-thumb) .tv-modal-nav { top:50%; }
/* 사진 라이트박스 */
.tv-pmodal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.85); z-index:2100; align-items:center; justify-content:center; padding:20px; }
.tv-pmodal.on { display:flex; }
.tv-pmodal img { max-width:100%; max-height:92vh; border-radius:8px; box-shadow:0 8px 30px rgba(0,0,0,.5); }
.tv-pmodal .tv-modal-x { position:fixed; top:14px; right:14px; }
/* 동선 모달 안 동영상(iframe) — 크기는 JS가 영상 비율로 inline 지정, 가운데 정렬 */
.tv-modal-photo .tv-modal-vid { display:block; margin:0 auto; max-width:100%; border:0; border-radius:12px; background:#000; }
/* 동영상 단순 재생 모달 */
.tv-vmodal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.82); z-index:2100; align-items:center; justify-content:center; padding:20px; }
.tv-vmodal.on { display:flex; }
.tv-vbox { position:relative; }
/* 크기(width/height)는 JS tvFitBox 가 영상 비율로 inline 지정 → 검은 여백 없음. 미지정 시 폴백 */
.tv-vframe { position:relative; width:min(820px,96vw); max-width:96vw; aspect-ratio:16/9; background:#000; border-radius:10px; overflow:hidden; box-shadow:0 8px 30px rgba(0,0,0,.5); margin:0 auto; }
.tv-vframe iframe { position:absolute; inset:0; width:100%; height:100%; border:0; }
.tv-vmodal .tv-modal-x { position:fixed; top:14px; right:14px; }
/* 모바일(터치)=화면 채움: 검은 배경 + iframe 풀뷰포트(드라이브 플레이어가 영상 비율 처리) */
.tv-vmodal.full { background:#000; padding:0; }
.tv-vmodal.full .tv-vframe { max-width:100vw; max-height:100vh; border-radius:0; box-shadow:none; aspect-ratio:auto; }
/* ── 폴더블·태블릿(펼친 화면, 561~819px): 사진(좌, 세로로 김) | 지도·메모(우, 위아래) ── */
@media (max-width:819px){
    /* (모바일 햄버거 헤더 CSS는 env/nav.inc 의 nav_css() 로 통합됨) */
    #wrap { padding:14px; }
    #tv-map { height:260px; }
    .tv-headrow { flex-direction:column; }
    /* 폴더블·태블릿(561~819px): PC식 2열 유지, 사이즈만 축소 */
    .place-body { gap:14px; }
    .place-map  { min-height:0; }
    .pshot { grid-template-columns:88px 1fr; gap:12px; }
    .ps-thumb { width:88px; }
}
/* ── 일반 세로 스마트폰(≤560px): 사진 / 지도 / 메모 단일 컬럼 3줄 ── */
@media (max-width:560px){
    /* 세로 스마트폰: 단일 열 — 지도(풀폭) → [이미지|메모] 행 반복 */
    .place-body { grid-template-columns:1fr; }
    .place-map  { height:190px; min-height:0; align-self:auto; }
    .pshot { grid-template-columns:96px 1fr; gap:12px; }
    .ps-thumb { width:96px; }
    /* 세로폰: 공유·새로고침·편집 버튼 모두 숨김 */
    .tv-subrow .tv-head-btns { display:none; }
}
</style>
<?php nav_css(); ?>
</head>
<body>
<?php if ($guest): ?>
<div class='guest-bar'>🧳 여행 이야기</div>
<?php else: render_nav('travel'); endif; ?>
<div id='wrap'><div class='inner'>
<?php
}

function travel_foot(): void {
?>
</div></div>
</body>
</html>
<?php
}

// ##########################################################
// 장소 그룹화 헬퍼 (같은 위치 ≤100m 사진 묶기)
// ##########################################################
/** 두 좌표 사이 거리(m) — Haversine */
function tv_dist_m(float $la1, float $ln1, float $la2, float $ln2): float {
    $R = 6371000.0;
    $dLa = deg2rad($la2 - $la1);
    $dLn = deg2rad($ln2 - $ln1);
    $a = sin($dLa/2)**2 + cos(deg2rad($la1)) * cos(deg2rad($la2)) * sin($dLn/2)**2;
    return 2 * $R * asin(min(1.0, sqrt($a)));
}

/**
 * 시간순 사진 배열을 '장소' 단위로 묶는다.
 * - GPS 사진: 그룹 첫(앵커) 좌표에서 $maxMeters 이내면 같은 장소
 * - GPS 없는 사진: 연속된 것끼리 '위치 미상' 그룹
 * 반환: [['gps'=>bool, 'alat'?, 'alng'?, 'shots'=>[...]], ...]
 */
function tv_group_by_place(array $shots, float $maxMeters = 100.0): array {
    $groups = [];
    $cur = null;
    foreach ($shots as $s) {
        $hasGps = ($s['lat'] !== null && $s['lng'] !== null);
        if ($hasGps) {
            $lat = (float)$s['lat']; $lng = (float)$s['lng'];
            if ($cur && $cur['gps'] && tv_dist_m($cur['alat'], $cur['alng'], $lat, $lng) <= $maxMeters) {
                $cur['shots'][] = $s;
            } else {
                if ($cur) $groups[] = $cur;
                $cur = ['gps'=>true, 'alat'=>$lat, 'alng'=>$lng, 'shots'=>[$s]];
            }
        } else {
            if ($cur && !$cur['gps']) {
                $cur['shots'][] = $s;
            } else {
                if ($cur) $groups[] = $cur;
                $cur = ['gps'=>false, 'shots'=>[$s]];
            }
        }
    }
    if ($cur) $groups[] = $cur;
    return $groups;
}

/** 장소 카드 안의 사진 1장 = [썸네일 | 시각+메모] (소유자는 메모 편집 가능) */
function tv_render_pshot(array $s, bool $guest, bool $mapsActive = false): void {
    $time = $s['taken_at'] ? substr($s['taken_at'], 11, 5) : '';
    $bg   = "background-image:url('" . htmlspecialchars($s['thumb_url']) . "')";
    $big  = htmlspecialchars(Travel::thumbUrl($s['drive_file_id'], 1600), ENT_QUOTES);
    $isVid = (($s['media_type'] ?? 'image') === 'video');
    $fid   = htmlspecialchars($s['drive_file_id'], ENT_QUOTES);
    $vw    = (int)($s['vid_w'] ?? 0); $vh = (int)($s['vid_h'] ?? 0);

    // 클릭: 동영상=전체화면 재생 / 사진=라이트박스
    if ($isVid) {
        $click = "tvOpenVideo('{$fid}',{$vw},{$vh})";
    } else {
        $click = "tvOpenPhoto('{$big}')";
    }
    $vidCls = $isVid ? ' is-vid' : '';
    $noGps  = ($s['lat'] === null || $s['lng'] === null);   // GPS 없는 사진 = 위치 지정 대상

    echo "<div class='pshot' data-pid='{$s['id']}'" . ($noGps ? " data-nogps='1'" : '') . ">";
    // 사진 칸 = [촬영시각 줄] + [썸네일]. 시각을 사진 위 줄에 둬서 지도 높이를 '시각+사진' 높이에 맞춤
    echo "<div class='ps-col'>";
    if ($time !== '') echo "<div class='ps-time'>🕒 {$time}</div>";
    echo "<div class='ps-thumb{$vidCls}' role='button' tabindex='0' style=\"{$bg}\" onclick=\"{$click}\">";
    if (!$guest) {
        echo "<button type='button' class='ps-del' title='이 사진 숨기기' aria-label='이 사진 숨기기' onclick='event.stopPropagation();tvHidePhoto({$s['id']})'>✕</button>";
        echo "<button type='button' class='ps-loc' title='이 사진만 위치 지정' aria-label='이 사진만 위치 지정' data-ids=\"[{$s['id']}]\" onclick='event.stopPropagation();tvSetLoc(this)'>📍</button>";
    }
    if ($isVid) echo "<span class='ps-play' aria-hidden='true'>▶</span>";
    echo "</div>"; // .ps-thumb
    echo "</div>"; // .ps-col
    echo "<div class='ps-side'>";

    $memo = trim((string)($s['memo'] ?? ''));
    // 메모 편집 연필 아이콘(우, 소유자만) — 시각은 썸네일 오버레이로 이동
    if (!$guest) {
        $ttl = $memo !== '' ? '메모 편집' : '메모 쓰기';
        echo "<div class='ps-top'>";
        echo "<button type='button' class='dm-edit' title='{$ttl}' aria-label='{$ttl}' onclick='tvEditMemo(this)'>✏️</button>";
        echo "</div>"; // .ps-top
    }

    if ($guest) {
        if ($memo !== '') {
            echo "<div class='dr-memo'><div class='dm-body'>" . nl2br(htmlspecialchars($memo)) . "</div></div>";
        } else {
            echo "<div class='dr-memo dm-blank'></div>";
        }
    } else {
        $bodyH = $memo !== ''
               ? "<div class='dm-body'>" . nl2br(htmlspecialchars($memo)) . "</div>"
               : "<div class='dm-body dm-empty'>아직 메모가 없습니다.</div>";
        echo "<div class='dr-memo' data-pid='{$s['id']}'>" . $bodyH . "</div>";
    }
    echo "</div>"; // .ps-side
    echo "</div>"; // .pshot
}

/** 장소 그룹 사진 렌더: 메모 있는 사진 = 풀 카드 / 메모 없는 사진 = 컴팩트 썸네일 줄로 모음 */
function tv_render_shots(array $gShots, bool $guest, bool $mapsActive): void {
    $plain = [];
    foreach ($gShots as $s) {
        if (trim((string)($s['memo'] ?? '')) !== '') {
            tv_render_pshot($s, $guest, $mapsActive);   // 메모 있는 사진 = 기존 풀 레이아웃
        } else {
            $plain[] = $s;                              // 메모 없는 사진은 한 줄로 모음
        }
    }
    if ($plain) {
        echo "<div class='ps-strip'>";
        foreach ($plain as $s) tv_render_chip($s, $guest, $mapsActive);
        echo "</div>";
    }
}

/** 메모 없는 사진 1장 = 컴팩트 썸네일(시각 라벨 + 편집모드 연필·숨김) */
function tv_render_chip(array $s, bool $guest, bool $mapsActive): void {
    $time  = $s['taken_at'] ? substr($s['taken_at'], 11, 5) : '';
    $bg    = "background-image:url('" . htmlspecialchars($s['thumb_url']) . "')";
    $big   = htmlspecialchars(Travel::thumbUrl($s['drive_file_id'], 1600), ENT_QUOTES);
    $isVid = (($s['media_type'] ?? 'image') === 'video');
    $fid   = htmlspecialchars($s['drive_file_id'], ENT_QUOTES);
    $vw    = (int)($s['vid_w'] ?? 0); $vh = (int)($s['vid_h'] ?? 0);
    if ($isVid) {
        $click = "tvOpenVideo('{$fid}',{$vw},{$vh})";   // 동영상은 전체화면 재생
    } else {
        $click = "tvOpenPhoto('{$big}')";
    }
    $vidCls = $isVid ? ' is-vid' : '';
    $noGps  = ($s['lat'] === null || $s['lng'] === null);   // GPS 없는 사진 = 위치 지정 대상

    echo "<div class='ps-chip' data-pid='{$s['id']}'" . ($noGps ? " data-nogps='1'" : '') . ">";
    echo "<div class='ps-thumb{$vidCls}' role='button' tabindex='0' style=\"{$bg}\" onclick=\"{$click}\">";
    if (!$guest) {
        echo "<button type='button' class='ps-del' title='이 사진 숨기기' aria-label='이 사진 숨기기' onclick='event.stopPropagation();tvHidePhoto({$s['id']})'>✕</button>";
        echo "<button type='button' class='ps-memo-add' title='메모 쓰기' aria-label='메모 쓰기' onclick='event.stopPropagation();tvChipMemo({$s['id']})'>✏️</button>";
        echo "<button type='button' class='ps-loc' title='이 사진만 위치 지정' aria-label='이 사진만 위치 지정' data-ids=\"[{$s['id']}]\" onclick='event.stopPropagation();tvSetLoc(this)'>📍</button>";
    }
    if ($isVid) echo "<span class='ps-play' aria-hidden='true'>▶</span>";
    echo "</div>"; // .ps-thumb
    if ($time !== '') echo "<div class='ps-chip-time'>🕒 {$time}</div>";
    echo "</div>"; // .ps-chip
}

// ##########################################################
// 공유 링크 만료/무효 안내 (비로그인 게스트)
// ##########################################################
function travel_share_invalid(): void {
    http_response_code(410); // Gone
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>링크 만료 — 이코노미스트</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { font-family:'Pretendard','Malgun Gothic',sans-serif; background:#f0f2f5; color:#2c3e50; display:flex; align-items:center; justify-content:center; height:100vh; margin:0; }
.box { background:#fff; border-radius:14px; box-shadow:0 4px 16px rgba(0,0,0,.1); padding:40px; text-align:center; max-width:360px; }
.box .ic { font-size:44px; }
.box h1 { font-size:20px; margin:14px 0 8px; }
.box p { color:#7f8c8d; font-size:14px; line-height:1.6; }
</style>
</head>
<body>
<div class='box'>
<div class='ic'>🔒</div>
<h1>접근할 수 없는 링크입니다</h1>
<p>이 공유 링크는 만료되었거나 더 이상 유효하지 않습니다.<br>공유한 분에게 새 링크를 요청해 주세요.</p>
</div>
</body>
</html>
<?php
}

// ##########################################################
// 1. 목록 — 여행 카드
// ##########################################################
function travel_list(PDO $pdo): void {
    $travel = new Travel($pdo);
    $travel->ensureTable();
    $travels = $travel->listTravels();

    travel_head('여행 갤러리');
    echo "<div class='tv-listhead'>"
       . "<h2>🧳 여행 갤러리</h2>"
       . "<button id='tv-sync-btn' class='tv-sync' onclick='travelSync()'>🔄 드라이브에서 가져오기</button>"
       . "</div><div id='tv-sync-status' class='tv-sync-status'></div>";
    echo <<<'JS'
<script>
async function travelSync(){
    const btn=document.getElementById('tv-sync-btn');
    const st =document.getElementById('tv-sync-status');
    const orig=btn.textContent;
    btn.disabled=true; btn.textContent='⏳ 가져오는 중...';
    st.className='tv-sync-status loading';
    st.textContent='구글 드라이브에서 사진을 가져오는 중입니다. 사진이 많으면 시간이 걸릴 수 있어요...';
    try{
        const res=await fetch('schedule_api.php?module=travel&action=sync');
        const j=await res.json();
        if(!j.ok) throw new Error(j.msg||'동기화 실패');
        const sum=j.data||[];
        const msg = sum.length
            ? sum.map(s=>`${s.travel} ${s.photos_total}장(신규 ${s.photos_new})`).join(', ')
            : '새로 발견된 여행이 없습니다.';
        st.className='tv-sync-status ok';
        st.textContent='✅ '+msg+' — 목록을 갱신합니다.';
        setTimeout(()=>location.reload(), 1000);
    }catch(e){
        st.className='tv-sync-status err';
        st.textContent='❌ '+e.message;
        btn.disabled=false; btn.textContent=orig;
    }
}
</script>
JS;

    if (!$travels) {
        echo "<div class='empty'>아직 동기화된 여행이 없습니다.<br><br>"
           . "위의 <b>🔄 드라이브에서 가져오기</b> 버튼을 누르면 드라이브 여행 사진을 불러옵니다.</div>";
        travel_foot();
        return;
    }

    // 갤러리 기간(시작날짜) 기준으로 연도별 그룹핑 — 연도 내림차순(최신 위), 연도 내 월 오름차순(1월→12월)
    $byYear = [];
    foreach ($travels as $t) {
        $y = $t['start_dt'] ? substr($t['start_dt'], 0, 4) : '0000';   // 기간 미상은 맨 끝('0000')
        $byYear[$y][] = $t;
    }
    krsort($byYear);                                                  // 연도 내림차순
    foreach ($byYear as $y => $items) {
        usort($items, fn($a, $b) => strcmp($a['start_dt'] ?? '9999', $b['start_dt'] ?? '9999'));  // 연도 내 시작일 오름차순
        echo "<h2 class='tv-year'>" . ($y === '0000' ? '📅 기간 미상' : "{$y}년") . "</h2>";
        echo "<div class='tv-grid'>";
        foreach ($items as $t) {
            // 표지 = 캐시 프록시(travel_thumb.php) 경유 + 지연로딩. 표지 file_id 없으면 기존 드라이브 URL 폴백
            $coverUrl = !empty($t['cover_file_id'])
                ? 'travel_thumb.php?id=' . rawurlencode($t['cover_file_id']) . '&w=400'
                : ($t['cover_thumb'] ?? '');
            $coverHtml = $coverUrl !== ''
                ? "<img class='tv-cover-img' loading='lazy' decoding='async' src='" . htmlspecialchars($coverUrl, ENT_QUOTES) . "' alt=''>"
                : '';
            $period = $t['start_dt'] ? htmlspecialchars($t['start_dt']) . ' ~ ' . htmlspecialchars($t['end_dt']) : '기간 미상';
            $icon   = $t['icon'] ? htmlspecialchars($t['icon']) . ' ' : '';
            // 총 기간 = 시작~종료 포함 일수 (같은 날이면 '당일')
            $dur = '';
            if ($t['start_dt']) {
                $n = (new DateTime($t['start_dt']))->diff(new DateTime($t['end_dt']))->days + 1;
                $dur = " <span class='dur'>" . ($n <= 1 ? '당일' : "{$n}일") . "</span>";
            }
            echo "<a class='tv-card' href='/travel.php?mode=view&id={$t['id']}'>"
               . "<div class='tv-cover'>{$coverHtml}</div>"
               . "<div class='tv-meta'><h3>{$icon}" . htmlspecialchars($t['title'])
               . " <span class='pc'>📷 {$t['photo_count']}</span></h3>"
               . "<div class='period'>{$period}{$dur}</div></div></a>";
        }
        echo "</div>";
    }
    travel_foot();
}

// ##########################################################
// 2. 상세 — 날짜별 사진 + 지도 동선
// ##########################################################
function travel_view(PDO $pdo, bool $guest = false, ?int $forceId = null, ?string $shareExpiry = null): void {
    // 게스트(공유 링크)는 토큰에 묶인 여행만 본다 — $_GET['id'] 는 무시
    $id = $forceId ?? (int)($_GET['id'] ?? 0);
    $travel = new Travel($pdo);
    $travel->ensureTable();
    $t = $travel->getTravel($id);

    if (!$t) {
        if ($guest) { travel_share_invalid(); return; }
        travel_head('여행 없음');
        echo "<div class='empty'>여행을 찾을 수 없습니다. <a href='/travel.php'>목록으로</a></div>";
        travel_foot();
        return;
    }

    // 주소 캐시 채우기는 소유자 보기에서만 (게스트가 외부 지오코딩 호출을 트리거하지 못하게)
    if (!$guest) $travel->backfillAddresses($id);

    $byDate    = $travel->listPhotosByDate($id);
    $naverKey  = defined('NAVER_MAPS_CLIENT_ID')   ? NAVER_MAPS_CLIENT_ID   : '';
    $googleKey = defined('GOOGLE_MAPS_BROWSER_KEY') ? GOOGLE_MAPS_BROWSER_KEY : '';

    // 통계(총 사진 / GPS 보유) + 동선 핀 수집 — 핀은 '사진'이 아니라 '장소(≤100m)' 단위로 번호 매김
    $total = 0; $withGps = 0; $routePts = []; $routePhotos = [];
    $curAnchor = null;   // 현재 장소 앵커 좌표
    $placeIdx  = -1;     // 현재 장소 인덱스(0-based)
    foreach ($byDate as $day => &$shots) {
        foreach ($shots as &$s) {
            $total++;
            if ($s['lat'] !== null && $s['lng'] !== null) {
                $withGps++;
                $lat = (float)$s['lat']; $lng = (float)$s['lng'];
                if ($curAnchor !== null && tv_dist_m($curAnchor['lat'], $curAnchor['lng'], $lat, $lng) <= 100.0) {
                    // 같은 장소 → 기존 핀에 합침 (대표 주소가 비었으면 채움)
                    $routePts[$placeIdx]['count']++;
                    if ($routePts[$placeIdx]['addr'] === '' && trim((string)($s['addr'] ?? '')) !== '') {
                        $routePts[$placeIdx]['addr'] = (string)$s['addr'];
                    }
                    $pi = $routePts[$placeIdx]['count'];   // 장소 내 사진 순번(1-based)
                } else {
                    // 새 장소 → 새 핀 (photo0 = 이 장소의 첫 사진 인덱스)
                    $placeIdx++;
                    $curAnchor = ['lat'=>$lat, 'lng'=>$lng];
                    $routePts[] = [
                        'n'      => $placeIdx + 1,
                        'lat'    => $lat,
                        'lng'    => $lng,
                        'time'   => $s['taken_at'] ? substr($s['taken_at'], 11, 5) : '',
                        'addr'   => (string)($s['addr'] ?? ''),
                        'thumb'  => Travel::thumbUrl($s['drive_file_id'], 1000),
                        'count'  => 1,
                        'photo0' => count($routePhotos),
                    ];
                    $pi = 1;
                }
                $s['route_idx'] = $placeIdx;             // 이 사진이 속한 장소 핀 인덱스
                $s['photo_idx'] = count($routePhotos);   // 전체 동선 사진 리스트 내 인덱스
                // 모달 이전/다음이 넘길 '사진' 단위 리스트(촬영 시간순)
                $routePhotos[] = [
                    'id'    => (int)$s['id'],   // 숨김 즉시반영 시 모달 리스트에서 매칭용
                    'lat'   => $lat,
                    'lng'   => $lng,
                    'time'  => $s['taken_at'] ? substr($s['taken_at'], 11, 5) : '',
                    'addr'  => (string)($s['addr'] ?? ''),
                    'thumb' => Travel::thumbUrl($s['drive_file_id'], 1000),
                    'place' => $placeIdx + 1,   // 속한 장소 핀 번호
                    'pi'    => $pi,             // 장소 내 순번
                    'memo'  => trim((string)($s['memo'] ?? '')),  // 사진별 메모(모달 오버레이용)
                    'vid'   => (($s['media_type'] ?? 'image') === 'video') ? 1 : 0,   // 동영상이면 모달에서 iframe 재생
                    'fid'   => (string)$s['drive_file_id'],                            // 동영상 preview iframe 용
                    'vw'    => (int)($s['vid_w'] ?? 0),                                // 동영상 가로(px) — 모달 비율 맞춤
                    'vh'    => (int)($s['vid_h'] ?? 0),                                // 동영상 세로(px)
                ];
            } else {
                $s['route_idx'] = null;
                $s['photo_idx'] = null;
            }
        }
        unset($s);
    }
    unset($shots);

    // ── 지도 provider 판별: 경로에 해외 좌표가 하나라도 있으면 해외(구글맵), 전부 국내면 네이버 ──
    //   구글맵은 국내·해외 모두 표시되므로, 인천→해외→인천 같은 혼합 여행도 구글로 전 구간 정상 표시.
    //   (순수 국내 여행만 네이버 = 한국 상세도 유지. 지도 SDK는 여행 1건당 1개로 통일)
    $overseas = false;
    if ($routePts && class_exists('GeoCoder')) {
        foreach ($routePts as $pt) {
            if (!GeoCoder::isKorea((float)$pt['lat'], (float)$pt['lng'])) { $overseas = true; break; }
        }
    }
    $mapProvider = $overseas ? ($googleKey !== '' ? 'google' : 'none')
                             : ($naverKey  !== '' ? 'naver'  : 'none');
    $mapsActive  = ($mapProvider === 'naver' || $mapProvider === 'google');  // none=키없음→폴백 링크만
    // 해외 좌표를 외부 지도에서 열기 (폴백/보조 링크용) — 첫 좌표 기준
    $extLat = $routePts ? (float)$routePts[0]['lat'] : 0.0;
    $extLng = $routePts ? (float)$routePts[0]['lng'] : 0.0;

    travel_head($t['title'], $guest);
    echo "<div class='tv-detail'>";

    $period = $t['start_dt'] ? htmlspecialchars($t['start_dt']) . ' ~ ' . htmlspecialchars($t['end_dt']) : '기간 미상';
    // 총 기간 = 시작~종료 포함 일수 (같은 날이면 '당일') — 목록 카드와 동일 규칙
    if ($t['start_dt']) {
        $n = (new DateTime($t['start_dt']))->diff(new DateTime($t['end_dt']))->days + 1;
        $period .= " <span class='dur'>" . ($n <= 1 ? '당일' : "{$n}일") . "</span>";
    }

    $titleHtml = ($t['icon'] ? htmlspecialchars($t['icon']) . ' ' : '🧳 ') . htmlspecialchars($t['title']);
    if ($guest) {
        echo "<div class='tv-headrow'><h1 class='tv-title'>{$titleHtml}</h1></div>";
    } else {
        echo "<a class='tv-back' href='/travel.php'>← 여행 목록</a>";
        echo "<div class='tv-headrow'><h1 class='tv-title'>{$titleHtml}</h1></div>";
        echo "<div id='tv-rf-status' class='tv-sync-status' style='margin-bottom:6px'></div>";
        // 공유 패널 (기본 숨김 — 🔗 공유 버튼으로 토글)
        echo "<div id='tv-share' class='tv-share' style='display:none'>"
           . "<div class='ts-row'>"
           . "<label>유효시간</label>"
           . "<select id='ts-ttl'>"
           . "<option value='3600'>1시간</option>"
           . "<option value='86400' selected>24시간</option>"
           . "<option value='604800'>7일</option>"
           . "</select>"
           . "<button class='tv-sync tv-share-btn' onclick='tvShareCreate({$id})'>🔗 링크 생성</button>"
           . "</div>"
           . "<div id='ts-result' class='ts-result' style='display:none'>"
           . "<input id='ts-url' readonly onclick='this.select()'>"
           . "<button class='dm-save' onclick='tvShareCopy()'>복사</button>"
           . "<button class='dm-cancel' onclick='tvShareRevoke({$id})'>공유 중단</button>"
           . "<div id='ts-exp' class='ts-exp'></div>"
           . "</div>"
           . "<div class='ts-hint'>링크를 아는 사람은 로그인 없이 이 여행만 볼 수 있어요(목록·편집 불가). 유효시간이 지나거나 ‘공유 중단’ 시 즉시 차단됩니다.</div>"
           . "</div>";
    }
    $statsHtml = "<div class='tv-sub'>{$period} · 사진 <span id='tv-stat-photos'>{$total}</span>장 · 위치 {$withGps}곳</div>";
    if ($guest) {
        echo $statsHtml;
    } else {
        // 통계줄 우측 끝에 공유/새로고침/편집 (세로폰은 숨김, 와이드모바일은 아이콘+문구)
        echo "<div class='tv-subrow'>"
           . $statsHtml
           . "<div class='tv-head-btns'>"
           . "<button class='tv-sync tv-rf-top tv-share-btn' onclick='tvShareToggle()'>🔗<span class='tv-btn-tx'> 공유</span></button>"
           . "<button id='tv-rf-btn' class='tv-sync tv-rf-top' onclick='travelRefresh({$id})'>🔄<span class='tv-btn-tx'> 이 여행 새로고침</span></button>"
           . "<button class='tv-sync tv-rf-top tv-edit-btn' onclick='tvToggleEdit(this)' title='사진 편집(숨기기)'>✏️<span class='tv-btn-tx'> 편집</span></button>"
           . "</div></div>";
    }

    // 공유 상태 배너 — 공유 중이면 만료시각 노출 (소유자·게스트 공통)
    if ($guest) {
        if ($shareExpiry) {
            echo "<div class='tv-share-on'>🔗 이 페이지는 공유 링크로 공개 중입니다 · 공개기간 만료: <b>"
               . htmlspecialchars($shareExpiry) . "</b> <span class='muted'>(이 시각 이후 자동 차단)</span></div>";
        }
    } else {
        $active = $travel->getActiveShare($id);
        $bInner = $active
            ? "🔗 <b>현재 공유 중</b> · 공개기간 만료: <b>" . htmlspecialchars($active['expires_at'])
              . "</b> <span class='muted'>(이 시각 이후 자동 차단)</span> "
              . "<a href='#' class='ts-manage' onclick='tvShareToggle();return false;'>관리</a>"
            : '';
        echo "<div id='tv-share-banner' class='tv-share-on'" . ($active ? '' : " style='display:none'") . ">{$bInner}</div>";
    }

    if ($total === 0) {
        echo "<div class='empty'>아직 사진이 없습니다. 위의 <b>🔄 이 여행 새로고침</b> 으로 드라이브에서 가져오세요.</div>";
    }

    // 전체 동선 지도 — 사진 GPS를 촬영 시간순(①②③…)으로 이은 동선 (국내=네이버 / 해외=구글)
    if ($mapsActive && $withGps > 0) {
        echo "<div class='tv-map-wrap'>"
           . "<div id='tv-map'></div>"
           . "<button type='button' class='tv-mapfull-btn' onclick='tvMapFull()' title='전체화면 지도' aria-label='전체화면 지도'>⛶</button>"
           . "<button type='button' class='tv-mapfull-close' onclick='tvMapFull()' title='닫기' aria-label='닫기'>✕</button>"
           . "</div>";
        echo "<div class='map-note'>📍 사진에 남은 GPS를 촬영 시간순(①②③…)으로 이은 동선입니다. 핀을 누르면 위치를 크게 볼 수 있어요. <b>⛶</b> 로 전체화면.</div>";
    } elseif ($mapProvider === 'none' && $overseas && $withGps > 0) {
        // 해외 여행인데 구글 지도 브라우저 키가 아직 없음 → 외부 지도 링크로 폴백
        $gurl = "https://www.google.com/maps/search/?api=1&query={$extLat},{$extLng}";
        echo "<div class='tv-map-fallback'>🌍 해외 여행이라 구글 지도가 필요해요. "
           . "<a href='" . htmlspecialchars($gurl, ENT_QUOTES) . "' target='_blank' rel='noopener'>구글 지도에서 위치 열기 ↗</a>"
           . "<span class='muted'> (구글 지도 키를 등록하면 여기 동선 지도가 자동 표시됩니다)</span></div>";
    }

    // 날짜별 일기 — 같은 장소(≤100m) 사진은 하나의 카드로 묶어 [지도 1개 | 사진별 메모 리스트]
    foreach ($byDate as $day => $shots) {
        $isMisc = ($day === '날짜미상');
        $label  = $isMisc ? '📌 미분류 · 촬영시각 없음' : '📅 ' . date('n월 j일 (D)', strtotime($day));
        $secCls = $isMisc ? 'day-sec day-sec--misc' : 'day-sec';
        echo "<div class='{$secCls}'><div class='day-head'>{$label}</div>";
        if ($isMisc) echo "<div class='misc-note'>파일명·메타데이터에 촬영시각이 없어 날짜를 정하지 못한 항목입니다. 파일명을 <b>YYYYMMDD_HHMMSS</b> 형식으로 바꾸면 자동 분류됩니다.</div>";

        foreach (tv_group_by_place($shots) as $g) {
            $gShots = $g['shots'];
            $cnt    = count($gShots);

            // 시간 범위 (첫~끝 촬영시각)
            $times = [];
            foreach ($gShots as $s) if ($s['taken_at']) $times[] = substr($s['taken_at'], 11, 5);
            $tRange = '';
            if ($times) {
                $first = $times[0]; $last = $times[count($times)-1];
                $tRange = ($first === $last) ? $first : "{$first} ~ {$last}";
            }
            $headTime = $tRange !== '' ? "<span class='ph-time'>🕒 {$tRange}</span>" : '';
            $headCnt  = "<span class='ph-count'>📷 {$cnt}</span>";

            if ($g['gps']) {
                // 대표 주소 = 그룹에서 주소가 채워진 첫 사진 / 동선 진입점 = 첫 GPS 사진 인덱스
                $addr = '';
                foreach ($gShots as $s) { if (trim((string)($s['addr'] ?? '')) !== '') { $addr = (string)$s['addr']; break; } }
                $firstPhoto = null;
                foreach ($gShots as $s) { if (($s['photo_idx'] ?? null) !== null) { $firstPhoto = (int)$s['photo_idx']; break; } }
                // 장소 번호(상단 동선 핀과 동일) = 첫 GPS 사진의 route_idx + 1 → 미니 지도 마커에 표시
                $placeNum = null;
                foreach ($gShots as $s) { if (($s['route_idx'] ?? null) !== null) { $placeNum = (int)$s['route_idx'] + 1; break; } }
                $headAddr = $addr !== '' ? htmlspecialchars($addr) : '이 부근';
                $alat = $g['alat']; $alng = $g['alng'];
                // 메모 있는 사진이 하나도 없으면(=모두 컴팩트 줄로) 지도를 전체폭 배너로
                $memoCnt = 0;
                foreach ($gShots as $s) if (trim((string)($s['memo'] ?? '')) !== '') $memoCnt++;
                $mapFull = ($memoCnt === 0) ? ' place-map--full' : '';

                echo "<div class='place-card'>";
                echo "<div class='place-head'><span class='ph-addr'>📍 {$headAddr}</span>{$headTime}{$headCnt}</div>";
                echo "<div class='place-body'>";
                // 지도 = 사진 칸과 동일 크기의 첫 셀 (미니맵 재사용 + 클릭 시 동선 모달)
                if ($mapsActive) {
                    $hit = $firstPhoto !== null ? "onclick=\"tvOpenPhotoAt({$firstPhoto})\"" : '';
                    $dn = $placeNum !== null ? " data-n='{$placeNum}'" : '';
                    echo "<div class='dr-map place-map{$mapFull}' data-lat='{$alat}' data-lng='{$alng}'{$dn}>"
                       . "<div class='dr-map-canvas'></div>"
                       . "<div class='dr-map-hit' title='크게 보기' {$hit}></div>"
                       . "</div>";
                } elseif ($mapProvider === 'none' && $overseas) {
                    // 해외+키없음 → 미니맵 대신 구글 지도 외부 링크
                    $gurl = "https://www.google.com/maps/search/?api=1&query={$alat},{$alng}";
                    echo "<a class='dr-map-ext place-map{$mapFull}' href='" . htmlspecialchars($gurl, ENT_QUOTES) . "' target='_blank' rel='noopener'>🌍 구글 지도에서 보기 ↗</a>";
                }
                tv_render_shots($gShots, $guest, $mapsActive);
                echo "</div>"; // .place-body
                echo "</div>"; // .place-card
            } else {
                // 위치 미상 그룹 (지도 없음) — 소유자는 좌표 직접 지정 가능
                $setBtn = '';
                if (!$guest) {
                    $ids = array_map(fn($s) => (int)$s['id'], $gShots);
                    $idsAttr = htmlspecialchars(json_encode($ids), ENT_QUOTES);
                    $setBtn = "<button type='button' class='ph-setloc' title='이 그룹 사진 전체를 같은 위치로 지정' data-ids=\"{$idsAttr}\" onclick='tvSetLoc(this)'>📍 전체 지정</button>";
                }
                echo "<div class='place-card'>";
                echo "<div class='place-head'><span class='ph-addr'>📍 위치 미상</span>{$headTime}{$headCnt}{$setBtn}</div>";
                echo "<div class='place-body no-map'><div class='place-shots'>";
                tv_render_shots($gShots, $guest, $mapsActive);
                echo "</div></div>";
                echo "</div>"; // .place-card
            }
        }
        echo "</div>"; // .day-sec
    }

    // 지도 팝업 모달 (네이버 동적지도 1개 재사용)
    // 사진 라이트박스 모달 (공통)
    echo "<div class='tv-pmodal' id='tv-pmodal' onclick='tvClosePhoto(event)'>"
       . "<button class='tv-modal-x' type='button' onclick='tvClosePhoto()'>✕</button>"
       . "<img id='tv-pimg' alt='사진'>"
       . "</div>";

    echo "<div class='tv-modal' id='tv-modal' onclick='tvCloseMap(event)'>"
       . "<div class='tv-modal-box'>"
       . "<button class='tv-modal-x' type='button' onclick='tvCloseMap()'>✕</button>"
       . "<button class='tv-modal-nav prev' id='tv-modal-prev' type='button' title='이전 사진' onclick='tvPhotoGo(-1)' hidden>‹</button>"
       . "<button class='tv-modal-nav next' id='tv-modal-next' type='button' title='다음 사진' onclick='tvPhotoGo(1)' hidden>›</button>"
       . "<div class='tv-modal-memo' id='tv-modal-memo'></div>"
       . "<div class='tv-modal-photo' id='tv-modal-photo' style='display:none'></div>"   // 사진 img / 동영상 iframe 을 JS 가 동적 삽입
       . "<div id='tv-modal-map'></div>"
       . "<div class='tv-modal-cap' id='tv-modal-cap'></div>"
       . "</div></div>";

    // 동영상 단순 재생 모달 (GPS 없는 동영상 / 지도 없는 여행용) — 드라이브 preview iframe
    echo "<div class='tv-vmodal' id='tv-vmodal' onclick='tvCloseVideo(event)'>"
       . "<div class='tv-vbox'>"
       . "<button class='tv-modal-x' type='button' onclick='tvCloseVideo()'>✕</button>"
       . "<div class='tv-vframe' id='tv-vframe'></div>"
       . "</div></div>";

    // 위치 지정 모달 (위치 미상 사진에 좌표 수동 등록 — 소유자 전용)
    if (!$guest) {
        echo "<div class='tv-locmodal' id='tv-locmodal' onclick='tvLocClose(event)'>"
           . "<div class='tv-locbox'>"
           . "<button class='tv-modal-x' type='button' onclick='tvLocClose()'>✕</button>"
           . "<h3 class='tv-loc-title'>📍 위치 지정</h3>"
           . "<p class='tv-loc-sub' id='tv-loc-sub'>사진이 찍힌 장소를 검색해 지정하세요. 지정한 좌표는 새로고침해도 유지됩니다.</p>"
           . "<div class='tv-loc-search'>"
           . "<input type='text' id='tv-loc-q' placeholder='장소·주소 검색 (예: 경복궁, 해운대해수욕장)' autocomplete='off' onkeydown='if(event.key===\"Enter\"){event.preventDefault();tvLocSearch();}'>"
           . "<button type='button' onclick='tvLocSearch()'>검색</button>"
           . "</div>"
           . "<div class='tv-loc-results' id='tv-loc-results'></div>"
           . "<div class='tv-loc-manual'>또는 좌표 직접 입력:"
           . "<input type='text' id='tv-loc-ll' placeholder=\"37.5796, 126.9770  또는  12°45'21&quot;N, 100°55'39&quot;E\">"
           . "<button type='button' onclick='tvLocManual()'>적용</button>"
           . "</div>"
           . "<label class='tv-loc-spread'><input type='checkbox' id='tv-loc-spread-on' checked> 전후 "
           . "<input type='number' id='tv-loc-spread-min' value='30' min='1' max='240'>분 안의 '위치 미상' 사진도 함께 지정</label>"
           . "<div class='tv-loc-foot'>"
           . "<span id='tv-loc-sel' class='tv-loc-sel'></span>"
           . "<button type='button' id='tv-loc-save' class='tv-loc-save' onclick='tvLocSave()' disabled>이 위치로 지정</button>"
           . "</div>"
           . "</div></div>";
    }

    echo "</div>"; // .tv-detail
    travel_foot();

    // ── 사진 라이트박스 (공통: 소유자·게스트) ──
    echo "<script>"
       . "function tvOpenPhoto(u){var m=document.getElementById('tv-pmodal');document.getElementById('tv-pimg').src=u;m.classList.add('on');}"
       . "function tvClosePhoto(e){if(e&&e.target&&e.target.id!=='tv-pmodal'&&!e.target.classList.contains('tv-modal-x'))return;document.getElementById('tv-pmodal').classList.remove('on');}"
       // 영상 비율(w:h)을 가용영역(maxW×maxH)에 맞춰 픽셀 크기 산출 — 검은 여백 제거(없으면 16:9 폴백)
       . "function tvFitBox(maxW,maxH,w,h){var ar=(w>0&&h>0)?(w/h):(16/9);var fw=maxW,fh=maxW/ar;if(fh>maxH){fh=maxH;fw=maxH*ar;}return{w:Math.round(fw),h:Math.round(fh)};}"
       // 동영상 재생 모달 (drive preview iframe). 터치기기(폰·와이드)=화면 가득 채운 iframe → 드라이브 플레이어가 영상 방향/비율 알아서 배치(회전 메타 한계 회피) / PC=창(비율 맞춤). 좁은 폰(≤560)만 네이티브 풀스크린(컨트롤 자동숨김)
       . "function tvOpenVideo(fid,w,h){var m=document.getElementById('tv-vmodal');var fr=document.getElementById('tv-vframe');var touch=!!(window.matchMedia&&window.matchMedia('(pointer:coarse)').matches);var phone=touch&&window.innerWidth<=560;m.classList.toggle('full',touch);if(touch){fr.style.width=window.innerWidth+'px';fr.style.height=Math.round(window.innerHeight*0.95)+'px';}else{var b=tvFitBox(Math.min(window.innerWidth*0.96,820),window.innerHeight*0.86,w,h);fr.style.width=b.w+'px';fr.style.height=b.h+'px';}fr.innerHTML=\"<iframe src='https://drive.google.com/file/d/\"+fid+\"/preview' allow='autoplay; fullscreen' allowfullscreen></iframe>\";m.classList.add('on');if(phone){var ifr=fr.querySelector('iframe');var rq=ifr.requestFullscreen||ifr.webkitRequestFullscreen;if(rq){try{var pr=rq.call(ifr);if(pr&&pr.catch)pr.catch(function(){});}catch(e){}}}}"
       . "function tvCloseVideo(e){if(e&&e.target&&e.target.id!=='tv-vmodal'&&!e.target.classList.contains('tv-modal-x'))return;if(document.fullscreenElement&&document.exitFullscreen){document.exitFullscreen().catch(function(){});}document.getElementById('tv-vframe').innerHTML='';document.getElementById('tv-vmodal').classList.remove('on');}"
       // 네이티브 전체화면을 빠져나오면(시스템 뒤로가기 등) 동영상 모달도 함께 닫고 재생 중지
       . "function tvVfsChange(){if(!(document.fullscreenElement||document.webkitFullscreenElement)){var m=document.getElementById('tv-vmodal');if(m&&m.classList.contains('on')){document.getElementById('tv-vframe').innerHTML='';m.classList.remove('on');}}}"
       . "document.addEventListener('fullscreenchange',tvVfsChange);document.addEventListener('webkitfullscreenchange',tvVfsChange);"
       . "document.addEventListener('keydown',function(e){if(e.key==='Escape'){['tv-pmodal','tv-vmodal'].forEach(function(id){var m=document.getElementById(id);if(m&&m.classList.contains('on')){if(id==='tv-vmodal')document.getElementById('tv-vframe').innerHTML='';m.classList.remove('on');}});}});"
       . "</script>";

    // ── 스크립트(소유자 전용): 새로고침 + 메모 편집 + 공유 ──
    if (!$guest) {
    echo "<script>window.TV_ID={$id};</script>";
    echo <<<'JS'
<script>
async function travelRefresh(id){
    const btn=document.getElementById('tv-rf-btn');
    const st =document.getElementById('tv-rf-status');
    const orig=btn.textContent;
    btn.disabled=true; btn.textContent='⏳ 새로고침 중...';
    st.className='tv-sync-status loading'; st.textContent=' 드라이브에서 이 여행을 다시 가져오는 중...';
    // 사진/동영상이 많으면 한 요청에 다 처리하다 게이트웨이 타임아웃(HTML 504)이 나서
    // JSON 파싱이 깨진다 → offset/limit 으로 끊어 여러 번 호출하고 진행률을 표시한다.
    const CHUNK=40;   // 서버가 요청당 ~18초 시간예산으로 다시 끊으므로 넉넉히(사진 많으면 자동 분할)
    try{
        let offset=0, newTotal=0, names=0, unresolved=0, total=0, guard=0;
        while(true){
            if(++guard>2000) throw new Error('새로고침이 너무 오래 걸립니다');
            const res=await fetch('schedule_api.php?module=travel&action=sync&id='+id+'&offset='+offset+'&limit='+CHUNK);
            const j=await res.json();
            if(!j.ok) throw new Error(j.msg||'새로고침 실패');
            const s=(j.data&&j.data[0])||null;
            if(s&&s.deleted){   // 드라이브 폴더가 삭제됨 → 이 여행은 제거됨, 목록으로 이동
                st.className='tv-sync-status ok';
                st.textContent=' 🗑️ 드라이브 폴더가 삭제되어 목록에서 제거했습니다.';
                setTimeout(()=>location.href='travel.php', 900);
                return;
            }
            if(!s){ break; }
            total=s.total||total;
            newTotal+=s.photos_new||0;
            names+=s.dates_from_name||0;
            unresolved+=s.unresolved||0;
            if(s.done){
                st.className='tv-sync-status ok';
                let msg=' ✅ 사진 '+total+'장(신규 '+newTotal+')';
                if(names)      msg+=' · 파일명시각 '+names+'건';
                if(unresolved) msg+=' · 미분류 '+unresolved+'건';
                st.textContent=msg+' — 갱신합니다.';
                break;
            }
            st.className='tv-sync-status loading';
            st.textContent=' ⏳ 가져오는 중… '+(s.processed||0)+'/'+(total||'?');
            offset=s.next_offset;
            if(offset===null||offset===undefined) break;
        }
        setTimeout(()=>location.reload(), 900);
    }catch(e){
        st.className='tv-sync-status err'; st.textContent=' ❌ '+e.message;
        btn.disabled=false; btn.textContent=orig;
    }
}

function tvEditMemo(btn){
    const side=btn.closest('.ps-side');
    const card=side.querySelector('.dr-memo');
    if(card.querySelector('.dm-ta')) return;            // 이미 편집중
    const bodyEl=card.querySelector('.dm-body');
    const cur=(card.dataset.raw!==undefined) ? card.dataset.raw
            : (bodyEl.classList.contains('dm-empty') ? '' : bodyEl.innerText);
    bodyEl.style.display='none'; btn.style.visibility='hidden';
    const ta=document.createElement('textarea');
    ta.className='dm-ta'; ta.value=cur; ta.placeholder='이 순간의 메모를 남겨보세요...';
    const act=document.createElement('div'); act.className='dm-actions';
    act.innerHTML="<button type='button' class='dm-cancel'>취소</button><button type='button' class='dm-save'>저장</button>";
    card.appendChild(ta); card.appendChild(act); ta.focus();
    const close=()=>{ ta.remove(); act.remove(); bodyEl.style.display=''; btn.style.visibility=''; };
    act.querySelector('.dm-cancel').onclick=close;
    act.querySelector('.dm-save').onclick=async()=>{
        const val=ta.value, sv=act.querySelector('.dm-save');
        sv.disabled=true; sv.textContent='저장중...';
        try{
            const res=await fetch('schedule_api.php?module=travel&action=photo_memo',{
                method:'POST', headers:{'Content-Type':'application/json'},
                body:JSON.stringify({id:Number(card.dataset.pid), memo:val})
            });
            const j=await res.json(); if(!j.ok) throw new Error(j.msg||'저장 실패');
            card.dataset.raw=val;
            if(val.trim()===''){ bodyEl.className='dm-body dm-empty'; bodyEl.textContent='아직 메모가 없습니다.'; }
            else { bodyEl.className='dm-body'; bodyEl.textContent=val; }
            btn.title = val.trim()? '메모 편집' : '메모 쓰기';
            close();
        }catch(e){ sv.disabled=false; sv.textContent='저장'; alert(e.message); }
    };
}

// ── 편집 모드 토글 (와이드모바일): 켜면 썸네일에 ✕(숨기기) 노출 ──
function tvToggleEdit(btn){
    var on=document.body.classList.toggle('tv-edit-on');
    btn.classList.toggle('on', on);
    btn.title = on ? '편집 끝내기' : '사진 편집(숨기기)';
}

// ── 컴팩트 칩(메모 없는 사진)에 메모 추가 — 저장하면 풀 카드로 재배치되도록 reload ──
function tvChipMemo(pid){
    var chip=document.querySelector('.ps-chip[data-pid="'+pid+'"]');
    if(!chip) return;
    if(chip.nextElementSibling && chip.nextElementSibling.classList.contains('ps-chip-editor')) return;  // 이미 열림
    var ed=document.createElement('div'); ed.className='ps-chip-editor';
    ed.innerHTML="<textarea class='dm-ta' placeholder='이 순간의 메모를 남겨보세요...'></textarea>"
        +"<div class='dm-actions'><button type='button' class='dm-cancel'>취소</button><button type='button' class='dm-save'>저장</button></div>";
    chip.insertAdjacentElement('afterend', ed);
    var ta=ed.querySelector('.dm-ta'); ta.focus();
    ed.querySelector('.dm-cancel').onclick=function(){ ed.remove(); };
    ed.querySelector('.dm-save').onclick=async function(){
        var val=ta.value, sv=ed.querySelector('.dm-save');
        sv.disabled=true; sv.textContent='저장중...';
        try{
            var res=await fetch('schedule_api.php?module=travel&action=photo_memo',{
                method:'POST', headers:{'Content-Type':'application/json'},
                body:JSON.stringify({id:Number(pid), memo:val})
            });
            var j=await res.json(); if(!j.ok) throw new Error(j.msg||'저장 실패');
            if(val.trim()!==''){ location.reload(); }   // 메모 생겼으면 메모 사진 영역으로 재배치
            else { ed.remove(); }
        }catch(e){ sv.disabled=false; sv.textContent='저장'; alert(e.message); }
    };
}

// ── 사진 숨기기 (일기에서만 제외, 드라이브 원본은 유지) — reload 없이 즉시 DOM 갱신 ──
async function tvHidePhoto(pid){
    if(!confirm('이 사진을 일기에서 숨길까요?\n구글 드라이브 원본은 그대로 유지됩니다.')) return;
    const card=document.querySelector('.pshot[data-pid="'+pid+'"], .ps-chip[data-pid="'+pid+'"]');
    try{
        const res=await fetch('schedule_api.php?module=travel&action=photo_hide',{
            method:'POST', headers:{'Content-Type':'application/json'},
            body:JSON.stringify({id:Number(pid), hidden:true})
        });
        const j=await res.json(); if(!j.ok) throw new Error(j.msg||'숨김 실패');

        // 모달 이전/다음 리스트(TV_PHOTOS)는 인덱스 기반이라 splice 대신 hidden 마킹 → 모달이 건너뜀
        var ph=window.TV_PHOTOS||[];
        for(var k=0;k<ph.length;k++){ if(ph[k] && Number(ph[k].id)===Number(pid)) ph[k].hidden=true; }

        if(!card){ return; }                 // DOM에 없으면(예외) 마킹만 하고 종료
        const placeCard=card.closest('.place-card');

        // 부드럽게 제거
        card.style.transition='opacity .2s, transform .2s';
        card.style.opacity='0'; card.style.transform='scale(.92)';
        setTimeout(function(){
            card.remove();
            // 상단 통계 '사진 N장' 갱신
            var stat=document.getElementById('tv-stat-photos');
            if(stat){ var n=parseInt(stat.textContent,10); if(!isNaN(n)) stat.textContent=Math.max(0,n-1); }
            if(!placeCard) return;
            var left=placeCard.querySelectorAll('.pshot, .ps-chip').length;
            if(left<=0){
                placeCard.remove();          // 장소의 마지막 사진 → 장소 카드 통째 제거
            }else{
                var badge=placeCard.querySelector('.ph-count');
                if(badge) badge.textContent='📷 '+left;   // 장소 카드 카운트 갱신
            }
        },210);
    }catch(e){ alert(e.message); }
}

// ── 위치 미상 사진에 좌표 직접 지정 (GPS 없는 사진 수동 등록) ──
// 드라이브 EXIF 재편집은 imageMediaMetadata 캐시 때문에 불가 → DB(setPhotoLoc)에 직접 쓴다.
var TV_LOC = {ids:[], lat:null, lng:null, addr:'', name:''};
function tvSetLoc(btn){
    try{ TV_LOC.ids = JSON.parse(btn.dataset.ids||'[]'); }catch(e){ TV_LOC.ids=[]; }
    TV_LOC.lat=null; TV_LOC.lng=null; TV_LOC.addr=''; TV_LOC.name='';
    var n=TV_LOC.ids.length;
    document.getElementById('tv-loc-sub').textContent =
        (n>1 ? '사진 '+n+'장을 같은 장소로 한 번에 지정합니다.'
             : '이 사진 한 장의 위치를 지정합니다.')
        + ' 장소를 검색해 고르거나 좌표를 직접 입력하세요. 지정한 좌표는 새로고침해도 유지됩니다.';
    document.getElementById('tv-loc-q').value='';
    document.getElementById('tv-loc-ll').value='';
    document.getElementById('tv-loc-results').innerHTML='';
    tvLocUpdateSel();
    document.getElementById('tv-locmodal').classList.add('on');
    setTimeout(function(){ document.getElementById('tv-loc-q').focus(); }, 50);
}
function tvLocClose(e){
    if(e&&e.target&&e.target.id!=='tv-locmodal'&&!e.target.classList.contains('tv-modal-x')) return;
    document.getElementById('tv-locmodal').classList.remove('on');
}
function tvLocUpdateSel(){
    var sel=document.getElementById('tv-loc-sel'), sv=document.getElementById('tv-loc-save');
    if(TV_LOC.lat!==null && TV_LOC.lng!==null){
        sel.textContent='✓ '+(TV_LOC.name||TV_LOC.addr||(TV_LOC.lat.toFixed(5)+', '+TV_LOC.lng.toFixed(5)));
        sv.disabled=false;
    }else{ sel.textContent=''; sv.disabled=true; }
}
async function tvLocSearch(){
    var q=document.getElementById('tv-loc-q').value.trim();
    var box=document.getElementById('tv-loc-results');
    if(!q){ box.innerHTML=''; return; }
    box.innerHTML="<div class='tv-loc-empty'>검색 중…</div>";
    try{
        var res=await fetch('schedule_api.php?module=geo&action=place_search&q='+encodeURIComponent(q));
        var j=await res.json();
        if(!j.ok) throw new Error(j.msg||'검색 실패');
        var ps=j.places||[];
        if(!ps.length){ box.innerHTML="<div class='tv-loc-empty'>검색 결과가 없습니다. 좌표를 직접 입력해 보세요.</div>"; return; }
        box.innerHTML='';
        ps.forEach(function(p){
            var it=document.createElement('div'); it.className='tv-loc-item';
            it.innerHTML="<div class='tl-name'></div><div class='tl-addr'></div>";
            it.querySelector('.tl-name').textContent=p.name||'';
            it.querySelector('.tl-addr').textContent=p.address||'';
            it.onclick=function(){ tvLocPick(parseFloat(p.lat), parseFloat(p.lng), p.name||'', p.address||'', it); };
            box.appendChild(it);
        });
    }catch(e){ box.innerHTML="<div class='tv-loc-empty'>❌ "+e.message+"</div>"; }
}
function tvLocPick(lat,lng,name,addr,el){
    TV_LOC.lat=lat; TV_LOC.lng=lng; TV_LOC.name=name; TV_LOC.addr=addr;
    Array.prototype.forEach.call(document.querySelectorAll('#tv-loc-results .tv-loc-item'),function(n){ n.classList.remove('sel'); });
    if(el) el.classList.add('sel');
    tvLocUpdateSel();
}
// 좌표 문자열 파싱 — 소수점(37.5796, 126.9770)과 도분초(12°45'21"N, 100°55'39"E) 둘 다 지원
function tvParseCoord(str){
    str=(str||'').trim().replace(/^\(/,'').replace(/\)$/,'');
    // 도분초: 도[°] 분['′] 초["″] 방위(NSEW). 분·초는 선택적
    var dms=/(\d+(?:\.\d+)?)\s*[°˚]\s*(?:(\d+(?:\.\d+)?)\s*['′]\s*)?(?:(\d+(?:\.\d+)?)\s*["″]?\s*)?([NSEWnsew])/g;
    var found=[], m;
    while((m=dms.exec(str))){
        var dec=(parseFloat(m[1])||0)+(parseFloat(m[2]||0))/60+(parseFloat(m[3]||0))/3600;
        var dir=m[4].toUpperCase();
        if(dir==='S'||dir==='W') dec=-dec;
        found.push({dir:dir, val:dec});
    }
    if(found.length>=2){
        var lat=null, lng=null;
        found.forEach(function(f){ if(f.dir==='N'||f.dir==='S') lat=f.val; else lng=f.val; });
        if(lat===null||lng===null){ lat=found[0].val; lng=found[1].val; }   // 방위 누락 시 입력순(위도,경도)
        return [lat, lng];
    }
    // 소수점 형식
    var nums=str.split(/[ ,]+/).map(function(x){return parseFloat(x);}).filter(function(x){return !isNaN(x);});
    if(nums.length>=2) return [nums[0], nums[1]];
    return null;
}
function tvLocManual(){
    var c=tvParseCoord(document.getElementById('tv-loc-ll').value);
    if(!c){ alert('좌표를 인식하지 못했습니다.\n예) 37.5796, 126.9770\n또는 12°45\'21"N, 100°55\'39"E'); return; }
    var lat=c[0], lng=c[1];
    if(isNaN(lat)||isNaN(lng)||Math.abs(lat)>90||Math.abs(lng)>180){ alert('좌표 범위가 올바르지 않습니다.'); return; }
    tvLocPick(lat,lng,'','');
}
async function tvLocSave(){
    if(TV_LOC.lat===null||!TV_LOC.ids.length) return;
    var sv=document.getElementById('tv-loc-save'); sv.disabled=true; sv.textContent='저장 중…';
    // 전후 ±N분 위치 미상 사진도 함께 지정(옵션, 기본 켜짐) — 서버가 같은 여행의 시각 인접 사진을 찾아 같은 좌표로
    var spreadOn=document.getElementById('tv-loc-spread-on').checked;
    var spreadMin=spreadOn ? Math.max(1, Math.min(240, parseInt(document.getElementById('tv-loc-spread-min').value,10)||0)) : 0;
    try{
        var res=await fetch('schedule_api.php?module=travel&action=photo_setloc',{
            method:'POST', headers:{'Content-Type':'application/json'},
            body:JSON.stringify({ids:TV_LOC.ids, lat:TV_LOC.lat, lng:TV_LOC.lng, addr:TV_LOC.addr,
                                 spread:spreadMin, travel_id:(window.TV_ID||0)})
        });
        var j=await res.json(); if(!j.ok) throw new Error(j.msg||'저장 실패');
        sv.textContent = '✓ '+(j.count||0)+'장 지정' + (j.auto>0 ? ' (전후 '+j.auto+'장 자동)' : '');
        setTimeout(function(){ location.reload(); }, 700);   // 좌표 생겼으니 지도 그룹으로 재배치
    }catch(e){ sv.disabled=false; sv.textContent='이 위치로 지정'; alert(e.message); }
}

// ── 공유 링크 (생성 / 복사 / 중단) ──
function tvShareToggle(){ var p=document.getElementById('tv-share'); p.style.display=(p.style.display==='none'?'block':'none'); }
async function tvShareCreate(id){
    try{
        var ttl=Number(document.getElementById('ts-ttl').value);
        var res=await fetch('schedule_api.php?module=travel&action=share_create',{
            method:'POST', headers:{'Content-Type':'application/json'},
            body:JSON.stringify({id:id, ttl:ttl})
        });
        var j=await res.json(); if(!j.ok) throw new Error(j.msg||'생성 실패');
        tvShareShow(j.token, j.expires_at);
    }catch(e){ alert(e.message); }
}
function tvShareShow(token, exp){
    document.getElementById('ts-url').value = location.origin + '/travel.php?share=' + token;
    document.getElementById('ts-exp').textContent = '공개기간 만료: ' + (exp||'') + ' (이 시각 이후 자동 차단)';
    document.getElementById('ts-result').style.display='flex';
    // 상단 만료 배너도 동기화
    var b=document.getElementById('tv-share-banner');
    if(b){
        b.innerHTML = "🔗 <b>현재 공유 중</b> · 공개기간 만료: <b>" + (exp||'') + "</b> "
                    + "<span class='muted'>(이 시각 이후 자동 차단)</span> "
                    + "<a href='#' class='ts-manage' onclick='tvShareToggle();return false;'>관리</a>";
        b.style.display='';
    }
}
function tvShareCopy(){
    var i=document.getElementById('ts-url'); i.select();
    if(navigator.clipboard){ navigator.clipboard.writeText(i.value).then(function(){ alert('링크를 복사했습니다.'); }); }
    else { document.execCommand('copy'); alert('링크를 복사했습니다.'); }
}
async function tvShareRevoke(id){
    if(!confirm('공유를 지금 중단할까요? 기존 링크는 즉시 접근 불가가 됩니다.')) return;
    try{
        var res=await fetch('schedule_api.php?module=travel&action=share_revoke',{
            method:'POST', headers:{'Content-Type':'application/json'},
            body:JSON.stringify({id:id})
        });
        var j=await res.json(); if(!j.ok) throw new Error(j.msg||'중단 실패');
        document.getElementById('ts-result').style.display='none';
        document.getElementById('ts-url').value='';
        var b=document.getElementById('tv-share-banner'); if(b) b.style.display='none';
        alert('공유를 중단했습니다.');
    }catch(e){ alert(e.message); }
}
// 로드 시 기존 활성 링크가 있으면 표시
(function(){
    if(!window.TV_ID) return;
    fetch('schedule_api.php?module=travel&action=share_status&id='+window.TV_ID)
        .then(function(r){return r.json();})
        .then(function(j){ if(j.ok && j.token) tvShareShow(j.token, j.expires_at); })
        .catch(function(){});
})();
</script>
JS;
    } // end if(!$guest)

    // ── 지도 (국내=네이버 동적지도 / 해외=구글맵) ──
    if ($mapsActive) {
        echo "<script>window.navermap_authFailure=function(){var c=document.getElementById('tv-modal-cap');if(c)c.textContent='⚠️ 네이버 지도 인증 실패 — NCP 콘솔에 이 도메인을 등록하세요.';};</script>";
        echo "<script>window.TV_MAP_PROVIDER='" . $mapProvider . "';</script>";
        echo "<script>window.TV_NAVER_KEY='" . htmlspecialchars($naverKey, ENT_QUOTES) . "';</script>";
        echo "<script>window.TV_GOOGLE_KEY='" . htmlspecialchars($googleKey, ENT_QUOTES) . "';</script>";
        echo "<script>window.TV_ROUTE=" . json_encode($routePts, JSON_UNESCAPED_UNICODE) . ";</script>";
        echo "<script>window.TV_PHOTOS=" . json_encode($routePhotos, JSON_UNESCAPED_UNICODE) . ";</script>";
        echo <<<'JS'
<script>
// schedule.php 와 동일한 검증된 패턴: SDK 를 onload 로 보장한 뒤 지도 생성 (회색 타일 방지)
var _tvNaverSDK=null;
function tvLoadNaver(){
    if(_tvNaverSDK) return _tvNaverSDK;
    _tvNaverSDK=new Promise(function(resolve,reject){
        if(window.naver&&window.naver.maps) return resolve();
        if(!window.TV_NAVER_KEY) return reject('no-key');
        var s=document.createElement('script');
        s.src='https://oapi.map.naver.com/openapi/v3/maps.js?ncpKeyId='+encodeURIComponent(window.TV_NAVER_KEY)+'&submodules=geocoder';
        s.onload=function(){ resolve(); };
        s.onerror=function(){ reject('load-fail'); };
        document.head.appendChild(s);
    });
    return _tvNaverSDK;
}
// 구글맵 JS SDK 로더 (해외 좌표용). callback 방식으로 google.maps 준비 보장
var _tvGoogleSDK=null;
function tvLoadGoogle(){
    if(_tvGoogleSDK) return _tvGoogleSDK;
    _tvGoogleSDK=new Promise(function(resolve,reject){
        if(window.google&&window.google.maps) return resolve();
        if(!window.TV_GOOGLE_KEY) return reject('no-key');
        window.__tvGmapReady=function(){ resolve(); };
        var s=document.createElement('script');
        s.src='https://maps.googleapis.com/maps/api/js?key='+encodeURIComponent(window.TV_GOOGLE_KEY)+'&callback=__tvGmapReady&loading=async&language=ko';
        s.async=true; s.onerror=function(){ reject('load-fail'); };
        document.head.appendChild(s);
    });
    return _tvGoogleSDK;
}

var TV_map=null, TV_marker=null, TV_photoIdx=-1;
var TV_gmap=null, TV_gmarker=null;   // 해외(구글맵) 모달 지도/마커
// 방향 d(+1/-1)로 i부터 숨기지 않은(hidden!=true) 첫 사진 인덱스 (없으면 -1)
function tvNextVisible(i, d){
    var ph=window.TV_PHOTOS||[];
    for(var k=i; k>=0 && k<ph.length; k+=d){ if(ph[k] && !ph[k].hidden && !ph[k].vid) return k; }   // 동영상은 사진 네비에서 제외(전용 플레이어로)
    return -1;
}
// 사진 단위 네비게이션 — 모달에서 이전/다음으로 전체 여행 사진을 촬영 시간순으로 넘김
function tvOpenPhotoAt(i){
    var ph=window.TV_PHOTOS||[];
    var p=ph[i];
    if(p && p.hidden){                       // 숨긴 사진 진입 시 가까운 보이는 사진으로
        var j=tvNextVisible(i,1); if(j<0) j=tvNextVisible(i,-1);
        if(j<0) return; i=j; p=ph[i];
    }
    if(!p) return;
    if(p.vid){ tvOpenVideo(p.fid, p.vw, p.vh); return; }   // 동영상은 전용 플레이어(모바일=전체화면 / PC=창)
    TV_photoIdx=i;
    var pts=window.TV_ROUTE||[];
    var place=pts[p.place-1]||{};
    var addr=p.addr || place.addr || (p.lat.toFixed(5)+', '+p.lng.toFixed(5));
    var label='('+p.place+'/'+pts.length+') '+(p.time? p.time+' ' : '')+addr;
    var pc=place.count||1;
    if(pc>1) label+=' · 사진 '+p.pi+'/'+pc+'장';

    var modal=document.getElementById('tv-modal');
    modal.classList.add('on');
    document.getElementById('tv-modal-cap').textContent=label;
    // 사진만 (동영상은 위에서 전용 플레이어로 분기) — 썸네일 img + 지도
    var box=document.getElementById('tv-modal-photo');
    document.getElementById('tv-modal-map').style.display='';
    box.innerHTML="<img id='tv-modal-thumb' alt='사진'>";
    document.getElementById('tv-modal-thumb').src=p.thumb;
    box.style.display='block'; modal.classList.add('has-thumb');
    // 사진별 메모 — 있으면 사진 위 반투명 레이어로 표시
    var memoEl=document.getElementById('tv-modal-memo');
    if(memoEl){ var mt=(p.memo||'').trim(); memoEl.textContent=mt; memoEl.classList.toggle('on', mt!==''); }
    // 이전/다음 화살표 — 남은(보이는) 사진 기준으로 양끝에서만 숨김
    var pv=document.getElementById('tv-modal-prev'), nx=document.getElementById('tv-modal-next');
    pv.hidden=(tvNextVisible(i-1,-1)<0); nx.hidden=(tvNextVisible(i+1,1)<0);
    // 지도 — 사진일 때만 렌더(동영상은 위에서 지도 숨김). 해외=구글맵 / 국내=네이버, 장소 번호 마커
    if(!p.vid){
        if(window.TV_MAP_PROVIDER==='google'){
            tvLoadGoogle().then(function(){
                var pos={lat:p.lat,lng:p.lng};
                if(!TV_gmap){
                    TV_gmap=new google.maps.Map(document.getElementById('tv-modal-map'),
                        {center:pos,zoom:16,mapTypeControl:false,streetViewControl:false,fullscreenControl:false});
                    TV_gmarker=new google.maps.Marker({position:pos,map:TV_gmap});
                }else{
                    TV_gmap.setCenter(pos); TV_gmarker.setPosition(pos);
                }
                TV_gmarker.setLabel({text:String(p.place),color:'#fff',fontWeight:'700'});
                setTimeout(function(){ if(TV_gmap){ google.maps.event.trigger(TV_gmap,'resize'); TV_gmap.setCenter(pos);} },150);
            }).catch(function(){});
        }else{
            var pinIcon={ content:"<div class='pin-num'><span>"+p.place+"</span></div>", anchor:new naver.maps.Point(12,24) };
            tvLoadNaver().then(function(){
                var pos=new naver.maps.LatLng(p.lat,p.lng);
                if(!TV_map){
                    TV_map=new naver.maps.Map('tv-modal-map',{center:pos,zoom:16});
                    TV_marker=new naver.maps.Marker({position:pos,map:TV_map});
                }else{
                    TV_map.setCenter(pos); TV_marker.setPosition(pos);
                }
                TV_marker.setIcon(pinIcon);
                // 숨김→표시 직후엔 컨테이너 크기 0 → resize 후 재중심
                setTimeout(function(){ if(TV_map){ naver.maps.Event.trigger(TV_map,'resize'); TV_map.setCenter(pos);} },150);
            }).catch(function(){});
        }
    }
}
function tvPhotoGo(d){ if(TV_photoIdx<0) return; var j=tvNextVisible(TV_photoIdx+d, d); if(j>=0) tvOpenPhotoAt(j); }
// 모바일 스와이프 — 사진을 좌우로 끌면 이전/다음(화살표 안 눌러도)
(function(){
    var ph=document.getElementById('tv-modal-photo'); if(!ph) return;
    var x0=0, y0=0, t0=0, on=false;
    ph.addEventListener('touchstart',function(e){
        if(e.touches.length!==1) return;
        x0=e.touches[0].clientX; y0=e.touches[0].clientY; t0=e.timeStamp; on=true;
    },{passive:true});
    ph.addEventListener('touchend',function(e){
        if(!on||TV_photoIdx<0) return; on=false;
        var t=e.changedTouches[0], dx=t.clientX-x0, dy=t.clientY-y0;
        // 가로 우세 + 충분한 거리 + 빠른 동작만 스와이프로 인정
        if(Math.abs(dx)>50 && Math.abs(dx)>Math.abs(dy)*1.6 && (e.timeStamp-t0)<600){
            tvPhotoGo(dx<0 ? 1 : -1);   // 왼쪽으로 끌면 다음, 오른쪽이면 이전
        }
    },{passive:true});
})();
function tvCloseMap(e){
    if(e&&e.target&&e.target.id!=='tv-modal'&&!e.target.classList.contains('tv-modal-x')) return;
    var box=document.getElementById('tv-modal-photo'); if(box) box.innerHTML='';   // 동영상 재생 중지
    document.getElementById('tv-modal').classList.remove('on');
}
document.addEventListener('keydown',function(e){
    var m=document.getElementById('tv-modal');
    if(!m || !m.classList.contains('on')) return;
    if(e.key==='Escape'){ var b=document.getElementById('tv-modal-photo'); if(b) b.innerHTML=''; m.classList.remove('on'); }
    else if(e.key==='ArrowLeft' && TV_photoIdx>0){ tvPhotoGo(-1); }
    else if(e.key==='ArrowRight' && TV_photoIdx>=0 && TV_photoIdx<(window.TV_PHOTOS||[]).length-1){ tvPhotoGo(1); }
});

// 인라인 미니맵: 화면에 보일 때 SDK 로드 보장 후 생성 (드래그/줌 비활성 = 미리보기)
function tvInitMini(el){
    if(el.dataset.done) return; el.dataset.done='1';
    var la=parseFloat(el.dataset.lat), ln=parseFloat(el.dataset.lng);
    var n=el.dataset.n||'';   // 장소 번호 (상단 동선 핀과 동일)
    if(window.TV_MAP_PROVIDER==='google'){
        tvLoadGoogle().then(function(){
            var pos={lat:la,lng:ln};
            var map=new google.maps.Map(el.querySelector('.dr-map-canvas'), {
                center:pos, zoom:15, disableDefaultUI:true, gestureHandling:'none',
                keyboardShortcuts:false, clickableIcons:false
            });
            new google.maps.Marker({position:pos, map:map,
                label: n?{text:String(n), color:'#fff', fontWeight:'700'}:null});
            setTimeout(function(){ google.maps.event.trigger(map,'resize'); map.setCenter(pos); }, 80);
        }).catch(function(){ el.dataset.done=''; });
        return;
    }
    tvLoadNaver().then(function(){
        var pos=new naver.maps.LatLng(la, ln);
        var map=new naver.maps.Map(el.querySelector('.dr-map-canvas'), {
            center:pos, zoom:15, draggable:false, scrollWheel:false, pinchZoom:false,
            keyboardShortcuts:false, disableDoubleClickZoom:true, scaleControl:false,
            mapDataControl:false, zoomControl:false, logoControl:true
        });
        new naver.maps.Marker({position:pos, map:map,
            icon: n?{content:"<div class='pin-num'><span>"+n+"</span></div>", anchor:new naver.maps.Point(12,24)}:undefined});
        // 생성 시점 컨테이너 크기 미확정 대비 → resize 후 재중심 (회색 방지)
        setTimeout(function(){ naver.maps.Event.trigger(map,'resize'); map.setCenter(pos); }, 80);
    }).catch(function(){ el.dataset.done=''; });
}
// 전체 동선 지도: 번호 핀(촬영 시간순) + 폴리라인으로 연결
function tvInitRoute(){
    var el=document.getElementById('tv-map');
    var pts=window.TV_ROUTE||[];
    if(!el || !pts.length) return;
    if(window.TV_MAP_PROVIDER==='google'){ tvInitRouteGoogle(el,pts); return; }
    tvLoadNaver().then(function(){
        var path=pts.map(function(p){ return new naver.maps.LatLng(p.lat,p.lng); });
        var map=new naver.maps.Map('tv-map', { center:path[0], zoom:14, mapDataControl:false });
        // 동선 선
        if(path.length>1){
            new naver.maps.Polyline({
                map:map, path:path,
                strokeColor:'#e67e22', strokeWeight:4, strokeOpacity:0.85, strokeStyle:'solid'
            });
        }
        // 번호 핀 (클릭 시 팝업 지도 — 인덱스로 열어 이전/다음 이동 가능)
        pts.forEach(function(p,idx){
            var mk=new naver.maps.Marker({
                position:new naver.maps.LatLng(p.lat,p.lng), map:map,
                icon:{ content:"<div class='pin-num'><span>"+p.n+"</span></div>", anchor:new naver.maps.Point(12,24) },
                zIndex:p.n
            });
            naver.maps.Event.addListener(mk,'click',function(){ tvOpenPhotoAt(p.photo0); });
        });
        // 영역 맞추기 — 군집 좌표 가드(span<0.0015 → 과확대/타일오류 방지)
        var lats=pts.map(function(p){return p.lat;}), lngs=pts.map(function(p){return p.lng;});
        var minLa=Math.min.apply(null,lats), maxLa=Math.max.apply(null,lats);
        var minLn=Math.min.apply(null,lngs), maxLn=Math.max.apply(null,lngs);
        var tight=(maxLa-minLa)<0.0015 && (maxLn-minLn)<0.0015;
        function applyView(){
            if(tight){ map.setCenter(new naver.maps.LatLng((minLa+maxLa)/2,(minLn+maxLn)/2)); map.setZoom(16); }
            else{
                map.fitBounds(new naver.maps.LatLngBounds(new naver.maps.LatLng(minLa,minLn), new naver.maps.LatLng(maxLa,maxLn)));
                if(map.getZoom()>18) map.setZoom(18);
            }
        }
        applyView();
        // 전체화면 토글 시 resize 후 경로 재맞춤에 재사용
        window.__tvRouteFit=function(){ naver.maps.Event.trigger(map,'resize'); applyView(); };
        // 숨김/레이아웃 직후 컨테이너 크기 0 대비 → resize 후 재적용 (회색 타일 방지)
        setTimeout(function(){ naver.maps.Event.trigger(map,'resize'); applyView(); }, 150);
    }).catch(function(){});
}
// 해외 동선 지도 — 구글맵 버전 (번호 마커 + 폴리라인 + 군집 가드)
function tvInitRouteGoogle(el,pts){
    tvLoadGoogle().then(function(){
        var map=new google.maps.Map(el,{center:{lat:pts[0].lat,lng:pts[0].lng},zoom:14,
            mapTypeControl:false,streetViewControl:false,fullscreenControl:false});
        if(pts.length>1){
            new google.maps.Polyline({map:map,
                path:pts.map(function(p){return{lat:p.lat,lng:p.lng};}),
                strokeColor:'#e67e22',strokeWeight:4,strokeOpacity:0.85});
        }
        pts.forEach(function(p){
            var mk=new google.maps.Marker({position:{lat:p.lat,lng:p.lng},map:map,
                label:{text:String(p.n),color:'#fff',fontWeight:'700'},zIndex:p.n});
            mk.addListener('click',function(){ tvOpenPhotoAt(p.photo0); });
        });
        // 영역 맞추기 — 군집 좌표 가드(span<0.0015 → 과확대/타일오류 방지)
        var lats=pts.map(function(p){return p.lat;}), lngs=pts.map(function(p){return p.lng;});
        var minLa=Math.min.apply(null,lats), maxLa=Math.max.apply(null,lats);
        var minLn=Math.min.apply(null,lngs), maxLn=Math.max.apply(null,lngs);
        function applyG(){
            if((maxLa-minLa)<0.0015 && (maxLn-minLn)<0.0015){
                map.setCenter({lat:(minLa+maxLa)/2,lng:(minLn+maxLn)/2}); map.setZoom(16);
            }else{
                var b=new google.maps.LatLngBounds();
                pts.forEach(function(p){ b.extend({lat:p.lat,lng:p.lng}); });
                map.fitBounds(b);
                google.maps.event.addListenerOnce(map,'idle',function(){ if(map.getZoom()>18) map.setZoom(18); });
            }
        }
        applyG();
        // 전체화면 토글 시 resize 후 경로 재맞춤에 재사용
        window.__tvRouteFit=function(){ google.maps.event.trigger(map,'resize'); applyG(); };
        setTimeout(function(){ google.maps.event.trigger(map,'resize'); },150);
    }).catch(function(){});
}
tvInitRoute();

// 동선 지도 전체화면 토글 — 경로(핀·선) 그대로, 토글 후 resize+재맞춤
function tvMapFull(){
    var w=document.querySelector('.tv-map-wrap'); if(!w) return;
    var on=w.classList.toggle('full');
    document.body.classList.toggle('tv-map-lock', on);
    setTimeout(function(){ if(window.__tvRouteFit) window.__tvRouteFit(); }, 80);
}
document.addEventListener('keydown',function(e){
    if(e.key!=='Escape') return;
    var w=document.querySelector('.tv-map-wrap.full'); if(w) tvMapFull();
});

(function(){
    var els=document.querySelectorAll('.dr-map[data-lat]');
    if(!('IntersectionObserver' in window)){ els.forEach(tvInitMini); return; }
    var io=new IntersectionObserver(function(entries){
        entries.forEach(function(en){ if(en.isIntersecting){ tvInitMini(en.target); io.unobserve(en.target); } });
    },{rootMargin:'200px'});
    els.forEach(function(el){ io.observe(el); });
})();
</script>
JS;
    } else {
        echo "<script>function tvOpenMap(){alert('네이버 지도 키가 설정되지 않았습니다.');}function tvCloseMap(){document.getElementById('tv-modal').classList.remove('on');}</script>";
    }
}
?>
