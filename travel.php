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
#tv-map { width:810px; max-width:100%; height:320px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.08); margin-bottom:8px; background:#e8ebee; }
.map-note { font-size:12px; color:#95a5a6; margin-bottom:22px; }
.day-sec { margin-bottom:26px; }
.day-head { font-size:16px; font-weight:700; color:#34495e; margin-bottom:12px; padding-left:8px; border-left:4px solid #e67e22; }
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
.ps-top { display:flex; align-items:center; justify-content:space-between; gap:8px; min-height:20px; }
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
.place-body { display:grid; grid-template-columns:1fr 1fr; gap:16px 18px; align-items:stretch; }
.place-map { min-height:170px; height:auto; align-self:stretch; }
.pshot { display:grid; grid-template-columns:120px 1fr; gap:14px; align-items:start; position:relative; }
.ps-thumb { display:block; width:120px; aspect-ratio:3/4; flex-shrink:0; border-radius:10px; overflow:hidden; background:#dfe4ea center/cover no-repeat; box-shadow:0 1px 4px rgba(0,0,0,.12); cursor:pointer; }
.ps-del { display:none; position:absolute; top:5px; left:5px; z-index:3; width:23px; height:23px; padding:0; border:none; border-radius:50%; background:rgba(0,0,0,.55); color:#fff; font-size:13px; line-height:23px; text-align:center; cursor:pointer; transition:.15s; }
.ps-del:hover { background:#e74c3c; transform:scale(1.08); }
.pshot .ps-side { display:flex; flex-direction:column; gap:6px; min-width:0; }
.pshot .ps-side .dr-memo { flex:0 0 auto; align-self:stretch; }
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
/* ── 폴더블·태블릿(펼친 화면, 561~819px): 사진(좌, 세로로 김) | 지도·메모(우, 위아래) ── */
@media (max-width:819px){
    /* (모바일 햄버거 헤더 CSS는 env/nav.inc 의 nav_css() 로 통합됨) */
    #wrap { padding:14px; }
    #tv-map { height:260px; }
    .tv-headrow { flex-direction:column; }
    /* 폴더블·태블릿(561~819px): PC식 2열 유지, 사이즈만 축소 */
    .place-body { gap:14px; }
    .place-map  { min-height:150px; }
    .pshot { grid-template-columns:96px 1fr; gap:12px; }
    .ps-thumb { width:96px; }
}
/* ── 일반 세로 스마트폰(≤560px): 사진 / 지도 / 메모 단일 컬럼 3줄 ── */
@media (max-width:560px){
    /* 세로 스마트폰: 단일 열 — 지도(풀폭) → [이미지|메모] 행 반복 */
    .place-body { grid-template-columns:1fr; }
    .place-map  { height:190px; min-height:0; align-self:auto; }
    .pshot { grid-template-columns:104px 1fr; gap:12px; }
    .ps-thumb { width:104px; }
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
function tv_render_pshot(array $s, bool $guest): void {
    $time = $s['taken_at'] ? substr($s['taken_at'], 11, 5) : '';
    $bg   = "background-image:url('" . htmlspecialchars($s['thumb_url']) . "')";
    $big  = htmlspecialchars(Travel::thumbUrl($s['drive_file_id'], 1600), ENT_QUOTES);

    echo "<div class='pshot' data-pid='{$s['id']}'>";
    echo "<div class='ps-thumb' role='button' tabindex='0' style=\"{$bg}\" onclick=\"tvOpenPhoto('{$big}')\"></div>";
    if (!$guest) {
        echo "<button type='button' class='ps-del' title='이 사진 숨기기' aria-label='이 사진 숨기기' onclick='tvHidePhoto({$s['id']})'>✕</button>";
    }
    echo "<div class='ps-side'>";

    $memo = trim((string)($s['memo'] ?? ''));
    // 상단 행: 시각(좌) + 메모 편집 연필 아이콘(우, 소유자만)
    echo "<div class='ps-top'>";
    echo "<span class='dr-time'>" . ($time !== '' ? "🕒 {$time}" : '') . "</span>";
    if (!$guest) {
        $ttl = $memo !== '' ? '메모 편집' : '메모 쓰기';
        echo "<button type='button' class='dm-edit' title='{$ttl}' aria-label='{$ttl}' onclick='tvEditMemo(this)'>✏️</button>";
    }
    echo "</div>"; // .ps-top

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

    echo "<div class='tv-grid'>";
    foreach ($travels as $t) {
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
                ];
            } else {
                $s['route_idx'] = null;
                $s['photo_idx'] = null;
            }
        }
        unset($s);
    }
    unset($shots);

    // ── 지도 provider 판별: 첫 GPS 좌표가 한국 밖이면 해외(구글맵), 안이면 국내(네이버) ──
    //   여행 1건의 사진은 대개 같은 나라 → 대표(첫) 좌표로 통일 (지도 SDK가 섞이면 복잡)
    $overseas = $routePts && class_exists('GeoCoder')
        && !GeoCoder::isKorea((float)$routePts[0]['lat'], (float)$routePts[0]['lng']);
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
        echo "<div id='tv-map'></div>";
        echo "<div class='map-note'>📍 사진에 남은 GPS를 촬영 시간순(①②③…)으로 이은 동선입니다. 핀을 누르면 위치를 크게 볼 수 있어요.</div>";
    } elseif ($mapProvider === 'none' && $overseas && $withGps > 0) {
        // 해외 여행인데 구글 지도 브라우저 키가 아직 없음 → 외부 지도 링크로 폴백
        $gurl = "https://www.google.com/maps/search/?api=1&query={$extLat},{$extLng}";
        echo "<div class='tv-map-fallback'>🌍 해외 여행이라 구글 지도가 필요해요. "
           . "<a href='" . htmlspecialchars($gurl, ENT_QUOTES) . "' target='_blank' rel='noopener'>구글 지도에서 위치 열기 ↗</a>"
           . "<span class='muted'> (구글 지도 키를 등록하면 여기 동선 지도가 자동 표시됩니다)</span></div>";
    }

    // 날짜별 일기 — 같은 장소(≤100m) 사진은 하나의 카드로 묶어 [지도 1개 | 사진별 메모 리스트]
    foreach ($byDate as $day => $shots) {
        $label = ($day === '날짜미상') ? '📅 날짜 미상' : '📅 ' . date('n월 j일 (D)', strtotime($day));
        echo "<div class='day-sec'><div class='day-head'>{$label}</div>";

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
                $headAddr = $addr !== '' ? htmlspecialchars($addr) : '이 부근';
                $alat = $g['alat']; $alng = $g['alng'];

                echo "<div class='place-card'>";
                echo "<div class='place-head'><span class='ph-addr'>📍 {$headAddr}</span>{$headTime}{$headCnt}</div>";
                echo "<div class='place-body'>";
                // 지도 = 사진 칸과 동일 크기의 첫 셀 (미니맵 재사용 + 클릭 시 동선 모달)
                if ($mapsActive) {
                    $hit = $firstPhoto !== null ? "onclick=\"tvOpenPhotoAt({$firstPhoto})\"" : '';
                    echo "<div class='dr-map place-map' data-lat='{$alat}' data-lng='{$alng}'>"
                       . "<div class='dr-map-canvas'></div>"
                       . "<div class='dr-map-hit' title='크게 보기' {$hit}></div>"
                       . "</div>";
                } elseif ($mapProvider === 'none' && $overseas) {
                    // 해외+키없음 → 미니맵 대신 구글 지도 외부 링크
                    $gurl = "https://www.google.com/maps/search/?api=1&query={$alat},{$alng}";
                    echo "<a class='dr-map-ext place-map' href='" . htmlspecialchars($gurl, ENT_QUOTES) . "' target='_blank' rel='noopener'>🌍 구글 지도에서 보기 ↗</a>";
                }
                foreach ($gShots as $s) tv_render_pshot($s, $guest);
                echo "</div>"; // .place-body
                echo "</div>"; // .place-card
            } else {
                // 위치 미상 그룹 (지도 없음)
                echo "<div class='place-card'>";
                echo "<div class='place-head'><span class='ph-addr'>📍 위치 미상</span>{$headTime}{$headCnt}</div>";
                echo "<div class='place-body no-map'><div class='place-shots'>";
                foreach ($gShots as $s) tv_render_pshot($s, $guest);
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
       . "<div class='tv-modal-photo' id='tv-modal-photo' style='display:none'>"
       . "<img id='tv-modal-thumb' alt='사진'>"
       . "</div>"
       . "<div id='tv-modal-map'></div>"
       . "<div class='tv-modal-cap' id='tv-modal-cap'></div>"
       . "</div></div>";

    echo "</div>"; // .tv-detail
    travel_foot();

    // ── 사진 라이트박스 (공통: 소유자·게스트) ──
    echo "<script>"
       . "function tvOpenPhoto(u){var m=document.getElementById('tv-pmodal');document.getElementById('tv-pimg').src=u;m.classList.add('on');}"
       . "function tvClosePhoto(e){if(e&&e.target&&e.target.id!=='tv-pmodal'&&!e.target.classList.contains('tv-modal-x'))return;document.getElementById('tv-pmodal').classList.remove('on');}"
       . "document.addEventListener('keydown',function(e){if(e.key==='Escape'){var m=document.getElementById('tv-pmodal');if(m)m.classList.remove('on');}});"
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
    try{
        const res=await fetch('schedule_api.php?module=travel&action=sync&id='+id);
        const j=await res.json();
        if(!j.ok) throw new Error(j.msg||'새로고침 실패');
        const s=(j.data&&j.data[0])||null;
        if(s&&s.deleted){   // 드라이브 폴더가 삭제됨 → 이 여행은 제거됨, 목록으로 이동
            st.className='tv-sync-status ok';
            st.textContent=' 🗑️ 드라이브 폴더가 삭제되어 목록에서 제거했습니다.';
            setTimeout(()=>location.href='travel.php', 900);
            return;
        }
        st.className='tv-sync-status ok';
        st.textContent = s ? (' ✅ 사진 '+s.photos_total+'장(신규 '+s.photos_new+') — 갱신합니다.') : ' ✅ 갱신합니다.';
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

// ── 사진 숨기기 (일기에서만 제외, 드라이브 원본은 유지) — reload 없이 즉시 DOM 갱신 ──
async function tvHidePhoto(pid){
    if(!confirm('이 사진을 일기에서 숨길까요?\n구글 드라이브 원본은 그대로 유지됩니다.')) return;
    const card=document.querySelector('.pshot[data-pid="'+pid+'"]');
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
            var left=placeCard.querySelectorAll('.pshot').length;
            if(left<=0){
                placeCard.remove();          // 장소의 마지막 사진 → 장소 카드 통째 제거
            }else{
                var badge=placeCard.querySelector('.ph-count');
                if(badge) badge.textContent='📷 '+left;   // 장소 카드 카운트 갱신
            }
        },210);
    }catch(e){ alert(e.message); }
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
    for(var k=i; k>=0 && k<ph.length; k+=d){ if(ph[k] && !ph[k].hidden) return k; }
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
    // 항상 사진+지도 (사진별 썸네일)
    var img=document.getElementById('tv-modal-thumb');
    img.src=p.thumb; document.getElementById('tv-modal-photo').style.display='block'; modal.classList.add('has-thumb');
    // 사진별 메모 — 있으면 사진 위 반투명 레이어로 표시
    var memoEl=document.getElementById('tv-modal-memo');
    if(memoEl){ var mt=(p.memo||'').trim(); memoEl.textContent=mt; memoEl.classList.toggle('on', mt!==''); }
    // 이전/다음 화살표 — 남은(보이는) 사진 기준으로 양끝에서만 숨김
    var pv=document.getElementById('tv-modal-prev'), nx=document.getElementById('tv-modal-next');
    pv.hidden=(tvNextVisible(i-1,-1)<0); nx.hidden=(tvNextVisible(i+1,1)<0);
    // 지도 — 해외=구글맵 / 국내=네이버. 둘 다 장소 번호를 마커에 표시
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
    document.getElementById('tv-modal').classList.remove('on');
}
document.addEventListener('keydown',function(e){
    var m=document.getElementById('tv-modal');
    if(!m || !m.classList.contains('on')) return;
    if(e.key==='Escape'){ m.classList.remove('on'); }
    else if(e.key==='ArrowLeft' && TV_photoIdx>0){ tvPhotoGo(-1); }
    else if(e.key==='ArrowRight' && TV_photoIdx>=0 && TV_photoIdx<(window.TV_PHOTOS||[]).length-1){ tvPhotoGo(1); }
});

// 인라인 미니맵: 화면에 보일 때 SDK 로드 보장 후 생성 (드래그/줌 비활성 = 미리보기)
function tvInitMini(el){
    if(el.dataset.done) return; el.dataset.done='1';
    var la=parseFloat(el.dataset.lat), ln=parseFloat(el.dataset.lng);
    if(window.TV_MAP_PROVIDER==='google'){
        tvLoadGoogle().then(function(){
            var pos={lat:la,lng:ln};
            var map=new google.maps.Map(el.querySelector('.dr-map-canvas'), {
                center:pos, zoom:15, disableDefaultUI:true, gestureHandling:'none',
                keyboardShortcuts:false, clickableIcons:false
            });
            new google.maps.Marker({position:pos, map:map});
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
        new naver.maps.Marker({position:pos, map:map});
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
        if((maxLa-minLa)<0.0015 && (maxLn-minLn)<0.0015){
            map.setCenter({lat:(minLa+maxLa)/2,lng:(minLn+maxLn)/2}); map.setZoom(16);
        }else{
            var b=new google.maps.LatLngBounds();
            pts.forEach(function(p){ b.extend({lat:p.lat,lng:p.lng}); });
            map.fitBounds(b);
            google.maps.event.addListenerOnce(map,'idle',function(){ if(map.getZoom()>18) map.setZoom(18); });
        }
        setTimeout(function(){ google.maps.event.trigger(map,'resize'); },150);
    }).catch(function(){});
}
tvInitRoute();

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
