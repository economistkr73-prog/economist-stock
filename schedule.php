<?php
require_once "./env/cnt.inc";
require_once "./env/auth_fnc.php";
require_login();

// 지도/지오코딩 키 (없으면 지도 임베드 자동 비활성화 → 주소+외부링크만)
if (file_exists("./env/maps.inc")) require_once "./env/maps.inc";

$mode = $_GET['mode'] ?? 'calendar';

// ==========================================================
// 1. 라우팅 지도
// ==========================================================
$routes = [
    'calendar'  => 'sch_calendar',
    // 'projects'  => 'sch_projects',
    // 'contacts'  => 'sch_contacts',
    // 'kakao'     => 'sch_kakao',
    // 'progress'  => 'sch_progress',
];

// ==========================================================
// 2. 실행 엔진
// ==========================================================
if (isset($routes[$mode]) && function_exists($routes[$mode])) {
    $routes[$mode]($pdo);
} else {
    sch_calendar($pdo);
}

// ##########################################################
// 공통 헬퍼: 네비게이션 바 출력
// ##########################################################
function sch_nav(string $active_mode): void {
    global $current_user, $expire_date;
    $menus = [
        ['href' => '/etf_stock.php?mode=ef',          'label' => '주식ETF분석'],
        ['href' => '/condition_analysis.php?mode=cf', 'label' => '조건검색분석'],
        ['href' => '/stock_analysis.php?mode=si',     'label' => '주식그래프'],
        ['href' => '/classes/data_upload.php',        'label' => '데이터 입력'],
        ['href' => '/schedule.php?mode=calendar',     'label' => '스케줄러'],
        ['href' => '/contacts.php',                   'label' => '주소록'],
        ['href' => '/anniversary.php',                'label' => '기념일'],
    ];
    echo "<div class='top-nav-bar'>";
    echo "<button class='nav-burger' onclick='document.body.classList.toggle(\"nav-open\")' aria-label='메뉴'>☰</button>";
    echo "<div class='nav-menu'>";
    foreach ($menus as $m) {
        $active = (strpos($m['href'], "mode={$active_mode}") !== false) ? " class='active'" : '';
        echo "<a href='{$m['href']}'{$active}>{$m['label']}</a>";
    }
    echo "</div><div class='nav-user-info'>";
    echo "<span>환영합니다, <span class='user-name'>" . htmlspecialchars($current_user) . "</span>님</span>";
    echo "<span style='font-size:12px;opacity:.7'>(자동연장: {$expire_date})</span>";
    echo "<a href='logout.php' class='btn-logout'>로그아웃</a>";
    echo "</div></div>";
}

// ##########################################################
// 공통 CSS (nav + 공유 스타일)
// ##########################################################
function sch_common_css(): void { ?>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { overflow-x: hidden; }
body { font-family: 'Pretendard', 'Malgun Gothic', sans-serif; background: #f0f2f5; color: #2c3e50; height: 100vh; display: flex; flex-direction: column; overflow: hidden; }
.nav-burger { display: none; background: none; border: none; color: #fff; font-size: 22px; cursor: pointer; padding: 4px 8px; line-height: 1; }
.top-nav-bar { background: #2c3e50; color: #fff; height: 60px; display: flex; justify-content: space-between; align-items: center; padding: 0 20px; box-shadow: 0 2px 8px rgba(0,0,0,.15); flex-shrink: 0; z-index: 1000; }
.nav-menu { display: flex; gap: 10px; }
.nav-menu a { color: #ecf0f1; text-decoration: none; font-size: 16px; font-weight: 600; padding: 10px 16px; border-radius: 6px; }
.nav-menu a:hover, .nav-menu a.active { background: #34495e; color: #f1c40f; }
.nav-user-info { display: flex; align-items: center; gap: 15px; font-size: 14px; color: #bdc3c7; }
.nav-user-info .user-name { color: #f1c40f; font-weight: bold; }
.btn-logout { background: #e74c3c; color: #fff; text-decoration: none; padding: 6px 14px; border-radius: 4px; font-size: 13px; font-weight: bold; }
.btn { border: none; cursor: pointer; border-radius: 6px; font-size: var(--fs-sm); font-weight: 600; padding: 7px 14px; transition: .15s; }
.btn-primary { background: #3498db; color: #fff; }
.btn-primary:hover { background: #2980b9; }
.btn-outline { background: #fff; border: 1px solid #bdc3c7; color: #2c3e50; }
.btn-outline:hover { background: #ecf0f1; }
.btn-danger { background: #e74c3c; color: #fff; }
.btn-danger:hover { background: #c0392b; }
</style>
<?php }

// ##########################################################
// 기능 함수: 캘린더
// ##########################################################
function sch_calendar(PDO $pdo): void {
    global $mobile;
    (new Schedule($pdo))->ensureTable();
    (new Project($pdo))->ensureTable();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>업무 스케줄러 — 이코노미스트</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php sch_common_css(); ?>
<style>
/* ── 반응형 폰트 변수 (화면 크기에 비례해 자동 조절) ─────────
   clamp(최소, 뷰포트비율, 최대)
   1366px: xs≈10 sm≈12 base≈14 lg≈16 xl≈21
   1920px: xs≈13 sm≈16 base≈19 lg≈23 xl≈29
   2560px: xs≈18 sm≈20 base≈22 lg≈26 xl≈32
─────────────────────────────────────────────────── */
:root {
    --fs-xs:   clamp(10px, 0.70vw, 18px);
    --fs-sm:   clamp(11px, 0.85vw, 20px);
    --fs-base: clamp(12px, 1.00vw, 22px);
    --fs-lg:   clamp(14px, 1.20vw, 26px);
    --fs-xl:   clamp(18px, 1.50vw, 32px);
}
#scheduler { flex: 1; display: flex; flex-direction: column; overflow: hidden; padding: 16px; gap: 12px; }
#sch-body   { flex: 1; display: flex; gap: 12px; overflow: hidden; min-height: 0; }
/* 프로젝트 사이드패널 */
#proj-panel { width: 200px; flex-shrink: 0; background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,.08); display: flex; flex-direction: column; overflow: hidden; min-height: 0; transition: width .2s, opacity .2s; }
#proj-panel.hidden { width: 0; opacity: 0; pointer-events: none; }
#proj-panel-tab { flex-shrink: 0; width: 18px; background: #e8ecf0; border: none; cursor: pointer; font-size: 11px; color: #7f8c8d; border-radius: 4px; align-self: stretch; transition: background .15s; padding: 0; }
#proj-panel-tab:hover { background: #d0d6de; color: #2c3e50; }
.proj-panel-head { display: flex; align-items: center; justify-content: space-between; padding: 10px 10px 8px; border-bottom: 1px solid #eee; font-size: var(--fs-sm); font-weight: 700; color: #2c3e50; white-space: nowrap; overflow: visible; position: relative; z-index: 30; }
.proj-panel-head .proj-panel-title { overflow: hidden; text-overflow: ellipsis; }
.proj-panel-head .btn-add-proj { background: none; border: none; cursor: pointer; font-size: 17px; color: #3498db; padding: 2px 4px; line-height: 1; font-weight: 700; }
.proj-panel-head .btn-add-proj:hover { color: #2176ae; }
.proj-list { flex: 1; overflow-y: auto; padding: 6px 0; }
.proj-item { display: flex; align-items: center; gap: 6px; padding: 7px 10px; cursor: pointer; border-radius: 0; font-size: var(--fs-sm); color: #2c3e50; transition: background .1s; white-space: nowrap; overflow: hidden; }
.proj-item:hover { background: #f0f4f8; }
.proj-item.active { background: #eaf4ff; font-weight: 700; }
.proj-item .proj-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.proj-item .proj-label { flex: 1; overflow: hidden; text-overflow: ellipsis; }
.proj-item .proj-count { font-size: var(--fs-xs); color: #aaa; flex-shrink: 0; }
.proj-item-all { border-bottom: 1px solid #eee; margin-bottom: 4px; }
.proj-empty { font-size: var(--fs-xs); color: #bbb; padding: 10px; text-align: center; }
/* 프로젝트 카드 */
.proj-card { margin: 4px 8px; padding: 8px 9px; border: 1px solid #e8edf2; border-radius: 7px; cursor: pointer; transition: background .1s, border-color .1s; }
.proj-card:hover { background: #f6f9fc; border-color: #cfe0ef; }
.proj-card-top { display: flex; align-items: center; gap: 6px; }
.proj-card-top .proj-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
.proj-card-name { font-size: var(--fs-sm); font-weight: 700; color: #2c3e50; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.proj-card-period { font-size: var(--fs-xs); color: #8a97a3; margin: 4px 0 5px; }
.proj-card-prog { display: flex; align-items: center; gap: 5px; }
.proj-bar-track { flex: 1; height: 6px; background: #eef1f4; border-radius: 3px; overflow: hidden; }
.proj-bar-fill { height: 100%; border-radius: 3px; transition: width .2s; }
.proj-card-pct { font-size: 10px; color: #95a5a6; flex-shrink: 0; white-space: nowrap; }
/* 일정 모달: 프로젝트 고정 표시 칸 */
.fixed-proj { display: flex; align-items: center; gap: 6px; padding: 7px 9px; background: #f4f8fb; border: 1px solid #d6e4f0; border-radius: 6px; font-size: var(--fs-sm); }
.fixed-proj .proj-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.fixed-proj #f-project-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 600; color: #2c3e50; }
.fixed-proj #f-project-clear { background: none; border: none; cursor: pointer; color: #c0392b; font-size: 16px; line-height: 1; padding: 0 2px; }
/* 그룹 상세: 포함 프로젝트 행 */
.pdp-proj-row { display: flex; align-items: center; gap: 8px; padding: 8px 10px; border: 1px solid #e8edf2; border-radius: 7px; margin-bottom: 6px; cursor: pointer; transition: background .1s; }
.pdp-proj-row:hover { background: #f6f9fc; border-color: #cfe0ef; }
.pdp-proj-row .proj-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.pdp-proj-name { font-weight: 700; color: #2c3e50; }
.pdp-proj-meta { margin-left: auto; font-size: var(--fs-xs); color: #8a97a3; white-space: nowrap; }
.proj-add-wrap { position: relative; }
.proj-add-menu { display: none; position: absolute; right: 0; top: 100%; margin-top: 4px; background: #fff; border: 1px solid #d6e4f0; border-radius: 6px; box-shadow: 0 4px 14px rgba(0,0,0,.14); z-index: 50; overflow: hidden; }
.proj-add-menu.open { display: block; }
.proj-add-menu button { display: block; width: 100%; text-align: left; white-space: nowrap; background: none; border: none; cursor: pointer; font-size: var(--fs-sm); color: #2c3e50; padding: 8px 14px; }
.proj-add-menu button:hover { background: #f0f4f8; }
.proj-type-header { font-size: var(--fs-xs); color: #aaa; padding: 6px 10px 2px; font-weight: 700; letter-spacing: .5px; }
.proj-panel-foot { padding: 8px 10px; border-top: 1px solid #eee; }
.proj-panel-foot button { width: 100%; font-size: var(--fs-xs); }
#sch-main { flex: 1; display: flex; flex-direction: column; overflow: hidden; gap: 12px; }
/* 프로젝트 상세 모달 */
#proj-detail-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 5000; display: flex; align-items: center; justify-content: center; }
#proj-detail-overlay .pdp-modal { background: #fff; border-radius: 12px; width: 580px; max-width: 96vw; max-height: 88vh; box-shadow: 0 12px 40px rgba(0,0,0,.22); overflow: hidden; display:flex; flex-direction:column; }
#proj-detail-overlay .pdp-header { display: flex; align-items: center; gap: 12px; padding: 22px 24px 18px; border-bottom: 1px solid #eee; }
#proj-detail-overlay .pdp-color-bar { width: 6px; height: 48px; border-radius: 4px; flex-shrink: 0; }
#proj-detail-overlay .pdp-header-text { flex: 1; }
#proj-detail-overlay .pdp-title { font-size: 20px; font-weight: 700; color: #1a2a3a; margin-bottom: 4px; }
#proj-detail-overlay .pdp-badge { display: inline-block; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 10px; background: #f0f4f8; color: #5a6a7a; }
#proj-detail-overlay .pdp-body { padding: 20px 24px; }
#proj-detail-overlay .pdp-row { display: flex; gap: 10px; align-items: flex-start; margin-bottom: 14px; color: #3a4a5a; font-size: var(--fs-sm); }
#proj-detail-overlay .pdp-row-label { min-width: 54px; font-weight: 600; color: #8a9aaa; font-size: var(--fs-xs); padding-top: 2px; }
#proj-detail-overlay .pdp-memo-text { white-space: pre-wrap; line-height: 1.7; color: #4a5a6a; background: #f8fafc; border-radius: 6px; padding: 10px 12px; font-size: var(--fs-sm); max-height: 160px; overflow-y: auto; width: 100%; }
#proj-detail-overlay .pdp-body { padding: 16px 24px 0; overflow-y: auto; flex: 1; }
#proj-detail-overlay .pdp-items { margin-top: 14px; border-top: 1px solid #eee; padding-top: 12px; }
#proj-detail-overlay .pdp-items-title { font-size: var(--fs-xs); font-weight: 700; color: #8a9aaa; margin-bottom: 8px; letter-spacing: .5px; }
#proj-detail-overlay .pdp-items table { width: 100%; border-collapse: collapse; font-size: var(--fs-xs); }
#proj-detail-overlay .pdp-items th { background: #f4f6f9; color: #7f8c8d; font-weight: 700; padding: 6px 8px; text-align: left; border-bottom: 1px solid #eee; }
#proj-detail-overlay .pdp-items td { padding: 7px 8px; border-bottom: 1px solid #f0f0f0; color: #3a4a5a; vertical-align: middle; }
#proj-detail-overlay .pdp-items tr:last-child td { border-bottom: none; }
#proj-detail-overlay .pdp-items tr:hover td { background: #f8fafc; }
#proj-detail-overlay .pdp-items .ev-type-badge { display:inline-block; padding:1px 6px; border-radius:3px; font-size:10px; font-weight:600; background:#e8f4fd; color:#2980b9; }
#proj-detail-overlay .pdp-footer { display: flex; justify-content: flex-end; gap: 8px; padding: 12px 24px 16px; border-top: 1px solid #eee; flex-shrink:0; }
/* 그룹/프로젝트 번호 뱃지 */
.proj-num-badge { display:inline-block; color:#fff; border-radius:4px; padding:0 5px; font-size:inherit; font-weight:700; margin-right:4px; vertical-align:baseline; line-height:inherit; flex-shrink:0; }
.sch-toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.sch-toolbar h2 { font-size: var(--fs-xl); font-weight: 700; min-width: 160px; text-align: center; }
.view-tabs { display: flex; border: 1px solid #bdc3c7; border-radius: 6px; overflow: hidden; }
.view-tabs button { border: none; background: #fff; padding: 7px 14px; cursor: pointer; font-size: var(--fs-sm); font-weight: 600; color: #7f8c8d; transition: .15s; }
.view-tabs button.active { background: #3498db; color: #fff; }
.spacer { flex: 1; }
/* 월간 */
#view-month { flex: 1; overflow: hidden; display: flex; flex-direction: column; }
.cal-header { display: grid; grid-template-columns: repeat(7,1fr); background: #2c3e50; color: #fff; border-radius: 8px 8px 0 0; }
.cal-header div { text-align: center; padding: 10px 0; font-size: var(--fs-sm); font-weight: 700; }
.cal-header div:first-child { color: #e74c3c; }
.cal-header div:last-child  { color: #3498db; }
.cal-grid { flex: 1; display: grid; grid-template-columns: repeat(7,1fr); grid-auto-rows: 1fr; border: 1px solid #dde; border-top: none; overflow-y: auto; }
.cal-cell { border-right: 1px solid #e0e0e0; border-bottom: 1px solid #e0e0e0; padding: 4px; min-height: 80px; background: #fff; cursor: pointer; transition: background .1s; }
.cal-cell:hover { background: #f8f9fa; }
.cal-cell.other-month { background: #f8f8f8; }
.cal-cell.today { background: #eaf4ff; }
.cal-cell .day-num { font-size: var(--fs-base); font-weight: 700; margin-bottom: 3px; }
.cal-cell.sunday     .day-num { color: #e74c3c; }
.cal-cell.saturday   .day-num { color: #3498db; }
.day-num.holiday-day             { color: #e74c3c !important; }
.cal-cell.today    .day-num { background: #3498db; color: #fff; border-radius: 50%; width: 1.8em; height: 1.8em; display: flex; align-items: center; justify-content: center; }
.event-chip { font-size: var(--fs-xs); padding: 1px 5px; border-radius: 3px; margin-bottom: 2px; cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #fff; }
.event-chip.done { background: transparent !important; color: #aaa !important; text-decoration: line-through; border: 1px solid #e0e0e0; }
.event-chip.holiday { display: none; } /* 월간: 별도 holiday-badge로 표시 */
.allday-chip.holiday { background: none !important; color: #e74c3c; font-weight: 700; border: none; padding-left: 2px; }
.allday-chip.holiday::before { content: ''; display: inline-block; width: 7px; height: 7px; border-radius: 50%; background: #e74c3c; margin-right: 5px; vertical-align: middle; flex-shrink: 0; }
/* 월간 셀 상단 행 */
.cell-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 3px; gap: 2px; }
.holiday-badge { font-size: var(--fs-xs); color: #e74c3c; font-weight: 700; text-align: right; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; line-height: 1.4; }
.holiday-badge::before { content: ''; display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: #e74c3c; margin-right: 3px; vertical-align: middle; }
/* 절기 */
.jeoegi-badge { font-size: var(--fs-xs); color: #888; font-weight: 500; text-align: right; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; line-height: 1.4; }
/* 음력 라벨 */
.lunar-badge { font-size: var(--fs-xs); color: #8e44ad; font-style: italic; }
/* 이모지 선택 */
.emoji-btn { font-size: 22px; width: 38px; height: 38px; border: 2px solid transparent; border-radius: 8px; cursor: pointer; background: #f8f9fa; display:flex; align-items:center; justify-content:center; transition:.15s; }
.emoji-btn:hover { background: #eaf4ff; border-color: #3498db; }
.emoji-btn.selected { border-color: #3498db; background: #eaf4ff; box-shadow: 0 0 0 2px #3498db40; }
.more-link { font-size: var(--fs-xs); color: #888; cursor: pointer; }
.more-link:hover { text-decoration: underline; }
/* 종일 이벤트 공통 */
.allday-bar { background: #f0f4f8; border: 1px solid #dde; border-bottom: none; padding: 4px 6px; display: flex; flex-wrap: wrap; gap: 4px; min-height: 28px; }
.allday-chip { font-size: var(--fs-xs); padding: 2px 8px; border-radius: 3px; color: #fff; cursor: pointer; white-space: nowrap; font-weight: 600; }
.allday-chip::before { content: ''; }
/* 주간 */
#view-week { flex: 1; overflow: hidden; display: flex; flex-direction: column; }
.week-wrap { border: 1px solid #dde; border-radius: 8px; overflow: hidden; background: #fff; flex: 1; display: flex; flex-direction: column; }
.week-allday-row { display: grid; grid-template-columns: 50px repeat(7,1fr); background: #f0f4f8; border-bottom: 2px solid #c8d6e5; }
.week-allday-label { background: #e8edf2; border-right: 1px solid #dde; text-align: center; padding: 4px 2px; font-size: var(--fs-xs); color: #7f8c8d; white-space: nowrap; line-height: 1.4; }
.week-allday-cell { border-right: 1px solid #dde; padding: 3px 2px; min-height: 26px; display: flex; flex-wrap: wrap; gap: 2px; }
.proj-week-row .week-allday-label { font-weight: 700; color: #2c3e50; overflow: hidden; text-overflow: ellipsis; }
.proj-week-row .week-allday-cell { min-height: 18px; padding: 2px; }
.proj-week-cell.in-range { margin: 2px 0; align-items: center; }
.proj-week-name { font-size: var(--fs-xs); font-weight: 700; white-space: nowrap; overflow: visible; padding-left: 2px; }
.week-grid { display: grid; grid-template-columns: 50px repeat(7,1fr); }
.week-head { background: #2c3e50; color: #fff; text-align: center; padding: 10px 4px; font-size: var(--fs-sm); font-weight: 700; border-right: 1px solid #4a6177; }
.week-head.sun { color: #e74c3c; } .week-head.sat { color: #7fb3d3; } .week-head.today-col { background: #2980b9; }
.time-label { background: #f8f9fa; border-right: 1px solid #dde; border-bottom: 1px solid #ececec; text-align: right; padding: 0 6px; font-size: var(--fs-xs); color: #999; line-height: 40px; height: 40px; }
.week-cell { border-right: 1px solid #ececec; border-bottom: 1px solid #ececec; height: 40px; cursor: pointer; box-sizing: border-box; }
.week-cell:hover { background: #f0f7ff; }
.week-event { position: absolute; left: 2px; right: 2px; border-radius: 3px; font-size: var(--fs-xs); color: #fff; padding: 2px 4px; overflow: hidden; text-overflow: ellipsis; cursor: pointer; z-index: 2; box-sizing: border-box; }
.week-event.done { background: transparent !important; color: #aaa !important; text-decoration: line-through; border: 1px solid #e0e0e0; }
.week-ev-col { position: relative; pointer-events: none; }  /* 이벤트 오버레이 컬럼 */
/* 일간 */
#view-day { flex: 1; overflow: hidden; display: flex; flex-direction: column; }
.day-wrap { border: 1px solid #dde; border-radius: 8px; overflow: hidden; background: #fff; flex: 1; display: flex; flex-direction: column; }
.day-allday-row { background: #f0f4f8; border-bottom: 2px solid #c8d6e5; display: flex; align-items: center; gap: 6px; padding: 5px 8px; min-height: 32px; }
.day-allday-label { font-size: var(--fs-xs); color: #7f8c8d; white-space: nowrap; min-width: 50px; width: 50px; text-align: center; padding: 0 4px; flex-shrink: 0; }
.day-grid { display: grid; grid-template-columns: 50px 1fr; }
.day-cell { border-bottom: 1px solid #ececec; height: 40px; cursor: pointer; box-sizing: border-box; }
.day-cell:hover { background: #f0f7ff; }
.day-event { position: absolute; left: 4px; right: 4px; border-radius: 4px; font-size: var(--fs-sm); color: #fff; padding: 3px 6px; cursor: pointer; z-index: 2; box-sizing: border-box; }
.day-event.done { background: transparent !important; color: #aaa !important; text-decoration: line-through; border: 1px solid #e0e0e0; }
/* 목록 */
#view-list { flex: 1; overflow-y: auto; }
.list-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
.list-table th { background: #2c3e50; color: #fff; padding: 10px 12px; text-align: left; font-size: var(--fs-base); }
.list-table td { padding: 9px 12px; border-bottom: 1px solid #f0f0f0; font-size: var(--fs-base); vertical-align: middle; }
.list-table tr:hover td { background: #f8f9fa; }
.list-table tr.done td { opacity: .55; }
.priority-badge { display: inline-block; padding: 2px 7px; border-radius: 10px; font-size: var(--fs-xs); font-weight: 700; }
.p1 { background: #fde8e8; color: #c0392b; } .p2 { background: #fef9e7; color: #d68910; } .p3 { background: #e9f7ef; color: #1e8449; }
/* 모달 */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 3000; align-items: center; justify-content: center; }
.modal-overlay.open { display: flex; }
.modal { background: #fff; border-radius: 10px; width: 620px; max-width: 96vw; box-shadow: 0 8px 32px rgba(0,0,0,.2); padding: 24px; }
.modal h3 { font-size: 18px; margin-bottom: 18px; }
.form-row { display: flex; gap: 10px; margin-bottom: 12px; flex-wrap: wrap; }
.form-row label { display: flex; flex-direction: column; gap: 4px; font-size: 13px; font-weight: 600; flex: 1; min-width: 120px; }
.form-row input, .form-row select, .form-row textarea { border: 1px solid #dde; border-radius: 6px; padding: 7px 10px; font-size: 13px; width: 100%; }
.form-row textarea { resize: vertical; height: 70px; }
.color-swatches { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 4px; }
.color-swatch { width: 24px; height: 24px; border-radius: 50%; cursor: pointer; border: 3px solid transparent; transition: .1s; }
.color-swatch.selected { border-color: #2c3e50; }
.modal-footer { display: flex; gap: 8px; margin-top: 18px; justify-content: flex-end; }
/* 기념일 분류/카테고리 버튼 */
.anniv-class-btn { border:1px solid #dde; background:#f8f9fa; color:#555; border-radius:6px; padding:7px 18px; font-size:13px; font-weight:600; cursor:pointer; transition:.15s; }
.anniv-class-btn:hover { background:#eaf4ff; border-color:#3498db; }
.anniv-class-btn.active { background:#3498db; color:#fff; border-color:#2980b9; }
.anniv-cat-btn { border:1px solid #dde; background:#f8f9fa; color:#555; border-radius:20px; padding:5px 13px; font-size:12px; font-weight:600; cursor:pointer; transition:.15s; }
.anniv-cat-btn:hover { background:#eaf4ff; border-color:#3498db; }
.anniv-cat-btn.active { background:#3498db; color:#fff; border-color:#2980b9; }
/* 타입 탭 */
.type-tabs { display:flex; gap:4px; border-bottom:2px solid #eee; padding-bottom:8px; }
.type-tab { border:1px solid #dde; background:#f8f9fa; color:#7f8c8d; border-radius:6px 6px 0 0; padding:6px 14px; font-size:12px; font-weight:600; cursor:pointer; transition:.15s; }
.type-tab:hover { background:#eaf4ff; color:#3498db; border-color:#3498db; }
.type-tab.active { background:#3498db; color:#fff; border-color:#3498db; }
/* 반복 타입 버튼 */
.recur-type-btn { border: 1px solid #dde; background: #f8f9fa; color: #666; border-radius: 4px; padding: 4px 12px; font-size: 12px; font-weight: 600; cursor: pointer; transition: .15s; }
.recur-type-btn:hover { background: #eaf4ff; border-color: #3498db; color: #3498db; }
.recur-type-btn.active { background: #3498db; color: #fff; border-color: #2980b9; }
/* 반복 서브모달 */
.recur-modal { width: 520px; }
.recur-modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; }
.recur-modal-header h3 { font-size: 17px; margin: 0; }
.recur-form { width: 100%; border-collapse: collapse; }
.recur-form td { padding: 7px 0; vertical-align: top; font-size: 13px; }
.recur-form td:first-child { width: 80px; color: #7f8c8d; padding-right: 12px; white-space: nowrap; padding-top: 10px; }
.recur-form input[type=number] { width: 54px; border: 1px solid #dde; border-radius: 6px; padding: 5px 8px; font-size: 13px; }
.recur-form select { border: 1px solid #dde; border-radius: 6px; padding: 5px 8px; font-size: 13px; }
.recur-form input[type=date] { border: 1px solid #dde; border-radius: 6px; padding: 5px 8px; font-size: 13px; }
.r-day-row { display: flex; gap: 6px; margin-top: 6px; flex-wrap: wrap; }
.r-day-row label { display: flex; align-items: center; gap: 3px; font-size: 12px; cursor: pointer; padding: 3px 6px; border: 1px solid #dde; border-radius: 4px; }
.r-day-row input[type=checkbox] { width: auto; accent-color: #3498db; }
.r-sub-row { margin-top: 7px; display: flex; flex-direction: column; gap: 5px; }
.r-sub-row label { display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer; }
.r-sub-row input[type=radio] { width: auto; accent-color: #3498db; }
.r-end-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 8px; font-size: 13px; }
.r-preview { background: #f8f9fa; border-radius: 6px; padding: 12px 14px; margin: 14px 0; font-size: 13px; color: #555; line-height: 1.7; min-height: 56px; white-space: pre-line; }
/* 위치/지도 */
.loc-region-btn { border: none; background: #fff; padding: 7px 12px; cursor: pointer; font-size: 13px; font-weight: 600; color: #7f8c8d; transition: .15s; }
.loc-region-btn.active { background: #3498db; color: #fff; }
#loc-status.ok   { color: #1e8449; }
#loc-status.err  { color: #e74c3c; }
#loc-status.warn { color: #d68910; }
#view-map { width: 100%; height: 200px; border-radius: 8px; overflow: hidden; margin-top: 4px; background: #eef2f5; }
.view-map-links { margin-top: 8px; font-size: 12px; display: flex; flex-wrap: wrap; gap: 10px; }
.view-map-links a { color: #2980b9; text-decoration: none; }
.view-map-links a:hover { text-decoration: underline; }
.view-map-fallback { font-size: 13px; color: #555; background: #f8f9fa; border-radius: 6px; padding: 10px; }
.chip-map-mark { cursor: pointer; }
.chip-map-mark:hover { text-decoration: underline; }
.pdp-dday { display:inline-block; background:#fdecea; color:#c0392b; font-size:11px; font-weight:700; padding:1px 8px; border-radius:10px; margin-left:6px; }
.proj-bar { font-size: var(--fs-xs); line-height: 16px; height: 16px; color:#fff; padding: 0 4px; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: pointer; font-weight: 600; }
/* ── 모바일 일정 입력 모달 → 풀스크린 ── */
:is(body.is-mobile, body.w-narrow) #modal-overlay {
    align-items: stretch;
}
:is(body.is-mobile, body.w-narrow) #modal-overlay .modal {
    width: 100%;
    max-width: 100%;
    border-radius: 0;
    box-shadow: none;
    padding: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
:is(body.is-mobile, body.w-narrow) #modal-overlay .type-tabs {
    flex-shrink: 0;
    padding: 12px 16px 0;
    margin: 0;
    border-bottom: 1px solid #eee;
}
:is(body.is-mobile, body.w-narrow) #modal-overlay #modal-form-body {
    flex: 1;
    overflow-y: auto;
    padding: 0 16px 12px;
    -webkit-overflow-scrolling: touch;
}
:is(body.is-mobile, body.w-narrow) #modal-overlay .modal-footer {
    flex-shrink: 0;
    padding: 12px 16px;
    border-top: 1px solid #eee;
    margin: 0;
}
/* ── 모바일 월간 캘린더 ──
   UA 기반 body.is-mobile(실기기) + 좁은 폭(max-width:820px) 양쪽에서 적용.
   두 셀렉터가 같은 규칙을 공유하도록 :is()로 묶음 */
/* 모바일 헤더: 햄버거 메뉴 */
:is(body.is-mobile, body.w-narrow) .top-nav-bar { height: 48px; padding: 0 8px; }
:is(body.is-mobile, body.w-narrow) .nav-burger { display: block; }
:is(body.is-mobile, body.w-narrow) .nav-menu {
    position: absolute; top: 48px; left: 0; right: 0; flex-direction: column;
    background: #2c3e50; gap: 0; display: none; z-index: 1001;
    box-shadow: 0 6px 16px rgba(0,0,0,.25); max-height: 70vh; overflow-y: auto;
}
body.is-mobile.nav-open .nav-menu, body.w-narrow.nav-open .nav-menu { display: flex; }
:is(body.is-mobile, body.w-narrow) .nav-menu a { padding: 13px 18px; border-bottom: 1px solid rgba(255,255,255,.08); border-radius: 0; font-size: 16px; }
/* 모바일 메뉴에서 일부 항목 숨김 */
:is(body.is-mobile, body.w-narrow) .nav-menu a[href*="condition_analysis"],
:is(body.is-mobile, body.w-narrow) .nav-menu a[href*="stock_analysis"],
:is(body.is-mobile, body.w-narrow) .nav-menu a[href*="data_upload"] { display: none; }
/* 환영문구·자동연장 숨기고 로그아웃만 */
:is(body.is-mobile, body.w-narrow) .nav-user-info { gap: 8px; }
:is(body.is-mobile, body.w-narrow) .nav-user-info > span { display: none; }

:is(body.is-mobile, body.w-narrow) #proj-panel,
:is(body.is-mobile, body.w-narrow) #proj-panel-tab { display: none !important; }
:is(body.is-mobile, body.w-narrow) #scheduler { padding: 8px; gap: 8px; }
:is(body.is-mobile, body.w-narrow) #sch-body { gap: 0; }
:is(body.is-mobile, body.w-narrow) .sch-toolbar { flex-wrap: wrap; gap: 6px; }
:is(body.is-mobile, body.w-narrow) .sch-toolbar h2 { font-size: 17px; min-width: 0; flex: 1; order: -1; width: 100%; }
:is(body.is-mobile, body.w-narrow) .spacer { display: none; }
:is(body.is-mobile, body.w-narrow) .view-tabs { flex: 1; }
:is(body.is-mobile, body.w-narrow) .cal-cell { min-height: 64px; padding: 2px; }
:is(body.is-mobile, body.w-narrow) .cal-cell .day-num { font-size: 13px; margin-bottom: 2px; }
/* 이벤트 칩 = 작은 점으로 표시 (제목은 탭하면 보기) */
:is(body.is-mobile, body.w-narrow) .event-chip {
    display: inline-block; width: 10px; height: 10px; padding: 0;
    border-radius: 50%; margin: 2px; font-size: 0; line-height: 0;
    vertical-align: middle; overflow: hidden;
}
:is(body.is-mobile, body.w-narrow) .event-chip.done { width: 10px; height: 10px; border: 1px solid #ccc; }
:is(body.is-mobile, body.w-narrow) .holiday-badge,
:is(body.is-mobile, body.w-narrow) .jeoegi-badge { font-size: 9px; }
:is(body.is-mobile, body.w-narrow) .more-link { font-size: 10px; }
:is(body.is-mobile, body.w-narrow) .proj-bar { font-size: 9px; height: 13px; line-height: 13px; }
/* 선택된 날짜 강조 */
:is(body.is-mobile, body.w-narrow) .cal-cell.sel-day { box-shadow: inset 0 0 0 2px #3498db; }
/* 모바일: 달력은 컴팩트(내용만), 아래 상세 패널이 남은 공간 채움 */
:is(body.is-mobile, body.w-narrow) #view-month { flex: 0 0 auto !important; overflow: visible !important; }
:is(body.is-mobile, body.w-narrow) .cal-grid { overflow: visible !important; }
:is(body.is-mobile, body.w-narrow) .cal-cell { cursor: default; }
:is(body.is-mobile, body.w-narrow) #btn-new { display: none; }   /* 상단 추가버튼 숨김 → FAB 사용 */
/* 하단 상세 패널 (기본 숨김, 모바일만 표시) */
#m-day-detail { display: none; }
:is(body.is-mobile, body.w-narrow) #m-day-detail {
    display: flex; flex-direction: column; flex: 1; min-height: 120px;
    border-top: 8px solid #f0f2f5; background: #fff; overflow-y: auto; padding: 12px 14px 80px;
}
.mdd-head { font-size: 18px; font-weight: 700; color: #2c3e50; margin-bottom: 10px; display: flex; align-items: baseline; gap: 8px; }
.mdd-head .mdd-lunar { font-size: 13px; font-weight: 400; color: #8e44ad; }
.mdd-empty { color: #aaa; font-size: 14px; padding: 16px 2px; }
.mdd-item { display: flex; align-items: flex-start; gap: 10px; padding: 11px 6px; border-bottom: 1px solid #f2f2f2; cursor: pointer; }
.mdd-item:active { background: #f4f8fc; }
.mdd-bar { width: 4px; align-self: stretch; border-radius: 2px; background: #3498db; flex-shrink: 0; min-height: 34px; }
.mdd-time { font-size: 13px; color: #888; min-width: 92px; flex-shrink: 0; padding-top: 1px; }
.mdd-title { flex: 1; font-size: 15px; font-weight: 600; color: #2c3e50; }
.mdd-item.done .mdd-title { text-decoration: line-through; color: #aaa; }
/* 플로팅 추가 버튼 */
#m-fab { display: none; }
:is(body.is-mobile, body.w-narrow) #m-fab {
    display: flex; align-items: center; justify-content: center;
    position: fixed; right: 18px; bottom: 22px; width: 56px; height: 56px;
    border-radius: 50%; border: none; background: #3498db; color: #fff;
    font-size: 30px; line-height: 1; cursor: pointer; z-index: 1000;
    box-shadow: 0 4px 14px rgba(52,152,219,.45);
}
#m-fab:active { background: #2176ae; }
</style>
</head>
<body class="<?= $mobile ? 'is-mobile' : '' ?>">
<?php sch_nav('calendar'); ?>
<script>
(function(){ if (matchMedia('(max-width:768px)').matches || document.body.classList.contains('is-mobile')) {
    document.querySelectorAll('.nav-menu a[href*="etf_stock.php"]').forEach(a=>a.href='/etf_stock.php?mode=m'); } })();
</script>

<div id="scheduler">
    <div class="sch-toolbar">
        <button class="btn btn-outline" id="btn-prev">◀</button>
        <h2 id="period-label"></h2>
        <button class="btn btn-outline" id="btn-next">▶</button>
        <button class="btn btn-outline" id="btn-today">오늘</button>
        <span class="spacer"></span>
        <div class="view-tabs">
            <button data-view="month" class="active">월</button>
            <button data-view="week">주</button>
            <button data-view="day">일</button>
            <button data-view="list">목록</button>
        </div>
        <button class="btn btn-primary" id="btn-new">+ 일정 추가</button>
    </div>


    <div id="sch-body">
        <!-- 프로젝트/그룹 사이드패널 -->
        <div id="proj-panel">
            <div class="proj-panel-head">
                <span class="proj-panel-title">📁 그룹/프로젝트</span>
                <span class="proj-add-wrap">
                    <button class="btn-add-proj" onclick="toggleProjAddMenu(event)" title="추가">＋</button>
                    <div class="proj-add-menu" id="proj-add-menu">
                        <button onclick="openProjModal(0,'group')">📁 그룹 추가</button>
                        <button onclick="openProjModal(0,'project')">📌 프로젝트 추가</button>
                    </div>
                </span>
            </div>
            <div class="proj-list" id="proj-list"></div>
        </div>
        <!-- 사이드패널 토글 탭 -->
        <button id="proj-panel-tab" onclick="toggleProjPanel()" title="사이드바 숨김/보이기">◀</button>
        <!-- 메인 캘린더 영역 -->
        <div id="sch-main">
            <div id="view-month" style="display:flex;flex-direction:column;flex:1;overflow:hidden;">
                <div class="cal-header">
                    <div>일</div><div>월</div><div>화</div><div>수</div><div>목</div><div>금</div><div>토</div>
                </div>
                <div class="cal-grid" id="cal-grid"></div>
            </div>
            <div id="view-week" style="display:none;flex:1;overflow:auto;"></div>
            <div id="view-day"  style="display:none;flex:1;overflow:auto;"></div>
            <div id="view-list" style="display:none;flex:1;overflow-y:auto;"></div>
            <!-- 모바일 전용: 선택한 날짜의 상세 일정 패널 -->
            <div id="m-day-detail"></div>
        </div>
    </div>
    <!-- 모바일 전용: 일정 추가 플로팅 버튼 -->
    <button id="m-fab" onclick="fabAdd()" title="일정 추가">＋</button>
</div>

<div class="modal-overlay" id="modal-overlay">
    <div class="modal">
        <!-- 타입 탭 -->
        <div class="type-tabs">
            <button class="type-tab" data-type="timed"       onclick="setEventType('timed')">일정</button>
            <button class="type-tab" data-type="anniversary" onclick="setEventType('anniversary')">★ 기념일</button>
            <button class="type-tab" data-type="todo"        onclick="setEventType('todo')">☑ 할일</button>
        </div>

        <div id="modal-form-body">
        <h3 id="modal-title" style="margin:14px 0 12px;font-size:16px;">일정 추가</h3>

        <!-- 제목 -->
        <div class="form-row">
            <label>제목 * <input type="text" id="f-title" placeholder="제목 입력"></label>
        </div>

        <!-- 일정: 시작~종료 + 종일 -->
        <div id="row-timed" style="display:flex;align-items:flex-end;gap:10px;margin-bottom:8px;">
            <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;font-weight:600;flex:1;">
                시작 * <input type="datetime-local" id="f-start">
            </label>
            <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;font-weight:600;flex:1;">
                종료   <input type="datetime-local" id="f-end">
            </label>
            <label style="display:flex;align-items:center;gap:4px;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap;padding-bottom:8px;flex-shrink:0;">
                <input type="checkbox" id="f-allday" style="width:auto;accent-color:#3498db;"> 종일
            </label>
        </div>

        <div id="end-time-msg" style="display:none;color:#e74c3c;font-size:12px;margin:-6px 0 8px;"></div>
        <!-- 일정: 반복 버튼 행 -->
        <div id="row-recur" style="display:flex;align-items:center;gap:6px;margin-bottom:12px;">
            <span style="font-size:15px;color:#7f8c8d;">↺</span>
            <button type="button" class="recur-type-btn" data-rtype="daily"   onclick="openRecurModal('daily')">매일</button>
            <button type="button" class="recur-type-btn" data-rtype="weekly"  onclick="openRecurModal('weekly')">매주</button>
            <button type="button" class="recur-type-btn" data-rtype="monthly" onclick="openRecurModal('monthly')">매월</button>
            <button type="button" class="recur-type-btn" data-rtype="yearly"  onclick="openRecurModal('yearly')">매년</button>
            <button type="button" id="btn-recur-clear" onclick="clearRecur()" style="display:none;margin-left:4px;background:none;border:none;color:#e74c3c;font-size:13px;cursor:pointer;padding:2px 6px;border-radius:4px;border:1px solid #e74c3c;">× 반복해제</button>
            <!-- 반복 설정 hidden -->
            <input type="hidden" id="h-recur-type" value="">
            <input type="hidden" id="h-recur-interval" value="1">
            <input type="hidden" id="h-recur-end-type" value="none">
        </div>

        <!-- 반복 요약 -->
        <div id="recur-summary-row" style="display:none;margin:-6px 0 10px;padding:4px 10px;background:#eaf4ff;border-radius:5px;font-size:12px;color:#2980b9;">
            <span id="recur-summary-text"></span>
        </div>

        <!-- 기념일: 양력/음력 선택 + 날짜 -->
        <!-- 기념일: 분류 (일반/가족) -->
        <div id="row-anniv-class" style="display:none;margin-bottom:12px;">
            <div style="font-size:13px;font-weight:600;margin-bottom:8px;">분류</div>
            <div style="display:flex;gap:8px;">
                <button type="button" class="anniv-class-btn active" data-cls="0" onclick="setAnnivClass(0)">📅 일반</button>
                <button type="button" class="anniv-class-btn" data-cls="1" onclick="setAnnivClass(1)">🏠 가족</button>
            </div>
            <input type="hidden" id="h-is-family" value="0">
        </div>

        <!-- 기념일: 카테고리 버튼 -->
        <div id="row-anniv-cat" style="display:none;margin-bottom:12px;">
            <div style="font-size:13px;font-weight:600;margin-bottom:8px;">카테고리</div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;" id="anniv-cat-btns"></div>
            <input type="hidden" id="h-anniv-cat" value="생일">
        </div>

        <div id="row-anniversary" style="display:none;margin-bottom:12px;">
            <div style="display:flex;gap:14px;margin-bottom:8px;">
                <label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer;">
                    <input type="radio" name="anniv-cal" value="solar" checked onchange="onAnnivCalChange()"> 양력
                </label>
                <label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer;">
                    <input type="radio" name="anniv-cal" value="lunar" onchange="onAnnivCalChange()"> 음력
                </label>
            </div>
            <!-- 양력 날짜 -->
            <div id="row-anniv-solar">
                <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;font-weight:600;max-width:200px;">
                    날짜 * <input type="date" id="f-anniv-date">
                </label>
            </div>
            <!-- 음력 날짜 -->
            <div id="row-anniv-lunar" style="display:none;">
                <div style="display:flex;gap:8px;align-items:flex-end;">
                    <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;font-weight:600;">
                        음력 월 *
                        <select id="f-lunar-month" style="width:80px;">
                            <?php for($m=1;$m<=12;$m++) echo "<option value='{$m}'>{$m}월</option>"; ?>
                        </select>
                    </label>
                    <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;font-weight:600;">
                        일 *
                        <select id="f-lunar-day" style="width:70px;">
                            <?php for($d=1;$d<=30;$d++) echo "<option value='{$d}'>{$d}일</option>"; ?>
                        </select>
                    </label>
                    <label style="display:flex;align-items:center;gap:4px;font-size:13px;padding-bottom:8px;cursor:pointer;">
                        <input type="checkbox" id="f-lunar-leap" style="width:auto;"> 윤달
                    </label>
                </div>
                <div id="lunar-preview" style="margin-top:6px;font-size:12px;color:#8e44ad;"></div>
            </div>
            <div style="margin-top:6px;font-size:12px;color:#888;">★ 매년 자동 반복됩니다.</div>

            <!-- 이모지 선택 -->
            <div style="margin-top:10px;">
                <div style="font-size:13px;font-weight:600;margin-bottom:6px;">아이콘</div>
                <div id="emoji-picker" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
                <input type="hidden" id="f-icon" value="">
            </div>
        </div>

        <!-- 할일: 마감일 + 반복 -->
        <div id="row-todo" style="display:none;margin-bottom:8px;">
            <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;font-weight:600;max-width:200px;">
                마감일 <input type="date" id="f-due-dt">
            </label>
        </div>
        <!-- 할일: 반복 버튼 행 -->
        <div id="row-todo-recur" style="display:none;align-items:center;gap:6px;margin-bottom:12px;">
            <span style="font-size:15px;color:#7f8c8d;">↺</span>
            <button type="button" class="recur-type-btn" data-rtype="daily"   onclick="openRecurModal('daily')">매일</button>
            <button type="button" class="recur-type-btn" data-rtype="weekly"  onclick="openRecurModal('weekly')">매주</button>
            <button type="button" class="recur-type-btn" data-rtype="monthly" onclick="openRecurModal('monthly')">매월</button>
            <button type="button" class="recur-type-btn" data-rtype="yearly"  onclick="openRecurModal('yearly')">매년</button>
            <button type="button" id="btn-recur-clear-todo" onclick="clearRecur()" style="display:none;margin-left:4px;background:none;border:none;color:#e74c3c;font-size:13px;cursor:pointer;padding:2px 6px;border-radius:4px;border:1px solid #e74c3c;">× 반복해제</button>
        </div>

        <!-- 카테고리 + 우선순위 -->
        <div class="form-row">
            <label>카테고리
                <select id="f-cat">
                    <option value="업무">업무</option><option value="개인">개인</option>
                    <option value="주식">주식</option><option value="회의">회의</option>
                    <option value="기타">기타</option>
                </select>
            </label>
            <label id="row-priority">우선순위
                <select id="f-priority">
                    <option value="1">높음</option><option value="2" selected>보통</option><option value="3">낮음</option>
                </select>
            </label>
        </div>

        <!-- 그룹 / 프로젝트 (분리) -->
        <div class="form-row">
            <label>그룹
                <select id="f-group-id">
                    <option value="">없음</option>
                </select>
            </label>
            <label id="row-project" style="display:none;">프로젝트
                <div id="f-project-fixed" class="fixed-proj">
                    <span class="proj-dot" id="f-project-dot"></span>
                    <span id="f-project-name"></span>
                    <button type="button" id="f-project-clear" title="프로젝트 해제" onclick="clearProjectField()">×</button>
                </div>
                <input type="hidden" id="f-project-id">
            </label>
        </div>

        <!-- 색상 -->
        <div class="form-row">
            <label>색상
                <div class="color-swatches">
                    <span class="color-swatch selected" data-color="#3498db" style="background:#3498db"></span>
                    <span class="color-swatch" data-color="#2ecc71" style="background:#2ecc71"></span>
                    <span class="color-swatch" data-color="#e74c3c" style="background:#e74c3c"></span>
                    <span class="color-swatch" data-color="#f39c12" style="background:#f39c12"></span>
                    <span class="color-swatch" data-color="#9b59b6" style="background:#9b59b6"></span>
                    <span class="color-swatch" data-color="#1abc9c" style="background:#1abc9c"></span>
                    <span class="color-swatch" data-color="#e67e22" style="background:#e67e22"></span>
                    <span class="color-swatch" data-color="#95a5a6" style="background:#95a5a6"></span>
                </div>
            </label>
        </div>

        <!-- 알림 -->
        <div class="form-row">
            <label>알림
                <div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:5px;">
                    <label style="display:flex;align-items:center;gap:5px;font-weight:normal;font-size:13px;cursor:pointer;">
                        <input type="checkbox" class="f-alert" value="30" style="width:auto;accent-color:#3498db;"> 30분 전
                    </label>
                    <label style="display:flex;align-items:center;gap:5px;font-weight:normal;font-size:13px;cursor:pointer;">
                        <input type="checkbox" class="f-alert" value="60" style="width:auto;accent-color:#3498db;"> 1시간 전
                    </label>
                    <label style="display:flex;align-items:center;gap:5px;font-weight:normal;font-size:13px;cursor:pointer;">
                        <input type="checkbox" class="f-alert" value="1440" style="width:auto;accent-color:#3498db;"> 하루 전
                    </label>
                </div>
            </label>
        </div>

        <!-- 참석자 -->
        <div class="form-row" id="row-attendees">
            <label>참석자
                <div style="display:flex;gap:6px;align-items:flex-start;">
                    <div style="flex:1;">
                        <div id="attendee-chips" style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:5px;"></div>
                        <input type="text" id="f-attendee-input" list="attendee-datalist"
                               placeholder="이름 입력 후 Enter (주소록 연동)"
                               style="width:100%;border:1px solid #dde;border-radius:6px;padding:7px 10px;font-size:13px;">
                        <datalist id="attendee-datalist"></datalist>
                    </div>
                </div>
            </label>
        </div>

        <!-- 위치/지도 -->
        <div class="form-row" id="row-location">
            <label style="min-width:auto;">위치 (선택)
                <div style="display:flex;gap:8px;align-items:center;margin-top:5px;">
                    <div class="view-tabs" style="flex-shrink:0;">
                        <button type="button" id="loc-btn-naver"  class="loc-region-btn active" onclick="setLocRegion('naver')">국내</button>
                        <button type="button" id="loc-btn-google" class="loc-region-btn"        onclick="setLocRegion('google')">해외</button>
                    </div>
                    <input type="text" id="f-address" placeholder="주소 입력 (예: 서울특별시 중구 세종대로 110)"
                           style="flex:1;border:1px solid #dde;border-radius:6px;padding:7px 10px;font-size:13px;"
                           onkeydown="if(event.key==='Enter'){event.preventDefault();doGeocode();}">
                    <button type="button" class="btn btn-outline" id="btn-geocode" onclick="doGeocode()" style="flex-shrink:0;">좌표 확인</button>
                </div>
                <div id="loc-status" style="font-size:12px;margin-top:4px;min-height:16px;"></div>
                <input type="hidden" id="f-lat">
                <input type="hidden" id="f-lng">
                <input type="hidden" id="f-provider" value="naver">
            </label>
        </div>

        <!-- 메모 -->
        <div class="form-row">
            <label>메모 <textarea id="f-memo" placeholder="메모 (선택)"></textarea></label>
        </div>
        </div><!-- /modal-form-body -->

        <div class="modal-footer">
            <button class="btn btn-danger"  id="btn-delete" style="display:none;margin-right:auto">삭제</button>
            <button class="btn btn-outline" id="btn-cancel">취소</button>
            <button class="btn btn-primary" id="btn-save">저장</button>
        </div>
    </div>
</div>

<!-- 일정 보기 모달 -->
<div class="modal-overlay" id="view-overlay" style="z-index:3500;">
    <div class="modal" style="width:420px;padding:0;overflow:hidden;">
        <!-- 헤더: 제목 + 우측 상단 액션 버튼 -->
        <div id="view-header" style="padding:16px 18px 14px;border-bottom:1px solid #eee;display:flex;align-items:flex-start;justify-content:space-between;gap:10px;">
            <div style="min-width:0;">
                <div id="view-title" style="font-size:20px;font-weight:700;line-height:1.3;"></div>
                <div id="view-done-badge" style="display:none;margin-top:4px;font-size:12px;color:#27ae60;font-weight:600;">✔ 완료됨</div>
            </div>
            <div style="display:flex;gap:5px;flex-shrink:0;">
                <button id="view-btn-done"   class="btn btn-outline" style="padding:5px 9px;font-size:12px;" onclick="toggleDoneFromView()">완료</button>
                <button id="view-btn-edit"   class="btn btn-outline" style="padding:5px 9px;font-size:12px;" onclick="openEditFromView()">수정</button>
                <button id="view-btn-delete" class="btn btn-danger"  style="padding:5px 9px;font-size:12px;" onclick="deleteFromView()">삭제</button>
            </div>
        </div>
        <!-- 본문 -->
        <div style="padding:14px 20px;">
            <div id="view-datetime" style="font-size:13px;color:#555;margin-bottom:8px;"></div>
            <div id="view-category" style="font-size:13px;color:#555;margin-bottom:8px;"></div>
            <div id="view-recur"    style="font-size:12px;color:#3498db;margin-bottom:8px;display:none;"></div>
            <div id="view-attendees" style="font-size:13px;color:#555;margin-bottom:8px;display:none;"></div>
            <div id="view-memo"     style="font-size:13px;color:#666;background:#f8f9fa;border-radius:6px;padding:10px;display:none;white-space:pre-wrap;"></div>
            <!-- 위치/지도 -->
            <div id="view-location" style="display:none;margin-top:10px;">
                <div id="view-address" style="font-size:13px;color:#555;margin-bottom:6px;"></div>
                <div id="view-map"></div>
                <div id="view-map-fallback" class="view-map-fallback" style="display:none;"></div>
                <div id="view-map-links" class="view-map-links"></div>
            </div>
        </div>
        <!-- 하단: 닫기 -->
        <div style="padding:12px 20px;border-top:1px solid #eee;display:flex;justify-content:flex-end;">
            <button class="btn btn-outline" style="min-width:90px;" onclick="closeViewModal()">닫기</button>
        </div>
    </div>
</div>

<!-- 반복 범위 선택 모달 (수정/삭제 시) -->
<div class="modal-overlay" id="scope-overlay" style="z-index:5000;">
    <div class="modal" style="width:360px;">
        <h3 style="margin-bottom:10px;" id="scope-title">반복 일정 삭제</h3>
        <p style="font-size:13px;color:#666;margin-bottom:18px;" id="scope-desc">어떤 일정을 삭제하시겠습니까?</p>
        <div style="display:flex;flex-direction:column;gap:8px;">
            <button class="btn btn-outline" style="text-align:left;padding:10px 14px;" onclick="confirmScope('one')">
                📌 <strong>이 일정만</strong> <span style="font-size:12px;color:#888;display:block;margin-left:22px;">이 날짜의 일정만 적용</span>
            </button>
            <button class="btn btn-outline" style="text-align:left;padding:10px 14px;" onclick="confirmScope('future')">
                📅 <strong>이 일정 이후 모두</strong> <span style="font-size:12px;color:#888;display:block;margin-left:22px;">이 날짜부터 이후 전체 적용</span>
            </button>
            <button class="btn btn-outline" style="text-align:left;padding:10px 14px;" onclick="confirmScope('all')">
                🔄 <strong>모든 반복 일정</strong> <span style="font-size:12px;color:#888;display:block;margin-left:22px;">과거/미래 전체 적용</span>
            </button>
        </div>
        <div style="text-align:right;margin-top:14px;">
            <button class="btn btn-outline" onclick="closeScopeModal()">취소</button>
        </div>
    </div>
</div>

<!-- 반복 서브모달 -->
<div class="modal-overlay" id="recur-overlay" style="z-index:4000;">
    <div class="modal recur-modal">
        <div class="recur-modal-header">
            <h3 id="recur-modal-title">반복</h3>
            <button onclick="closeRecurModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:#888;line-height:1;">×</button>
        </div>
        <table class="recur-form">
            <!-- 반복 주기 행: 버튼으로 이미 선택했으므로 hidden 처리 -->
            <tr id="r-type-row" style="display:none;">
                <td>반복 주기</td>
                <td>
                    <select id="r-type" onchange="onRTypeChange()">
                        <option value="daily">매일</option>
                        <option value="weekly">매주</option>
                        <option value="monthly">매월</option>
                        <option value="yearly">매년</option>
                    </select>
                </td>
            </tr>
            <tr>
                <td>주기</td>
                <td>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <input type="number" id="r-interval" value="1" min="1" max="99" oninput="updateRPreview()">
                        <span id="r-unit">일</span>
                    </div>
                    <!-- 매주: 요일 선택 -->
                    <div id="r-days-row" class="r-day-row" style="display:none;">
                        <label><input type="checkbox" class="r-day" value="0"> 일</label>
                        <label><input type="checkbox" class="r-day" value="1"> 월</label>
                        <label><input type="checkbox" class="r-day" value="2"> 화</label>
                        <label><input type="checkbox" class="r-day" value="3"> 수</label>
                        <label><input type="checkbox" class="r-day" value="4"> 목</label>
                        <label><input type="checkbox" class="r-day" value="5"> 금</label>
                        <label><input type="checkbox" class="r-day" value="6"> 토</label>
                    </div>
                    <!-- 매월: 날짜 or N번째 요일 -->
                    <div id="r-monthly-row" class="r-sub-row" style="display:none;">
                        <label><input type="radio" name="rmt" value="day" checked onchange="updateRPreview()"> <span id="r-m-day-lbl"></span></label>
                        <label><input type="radio" name="rmt" value="weekday" onchange="updateRPreview()"> <span id="r-m-wd-lbl"></span></label>
                        <label><input type="radio" name="rmt" value="lastday" onchange="updateRPreview()"> 말일</label>
                    </div>
                    <!-- 매년: 월일 or N번째 요일 -->
                    <div id="r-yearly-row" class="r-sub-row" style="display:none;">
                        <label><input type="radio" name="ryt" value="day" checked onchange="updateRPreview()"> <span id="r-y-day-lbl"></span></label>
                        <label><input type="radio" name="ryt" value="weekday" onchange="updateRPreview()"> <span id="r-y-wd-lbl"></span></label>
                    </div>
                    <!-- 영업일 조정 (매월/매년 공통) -->
                    <div id="r-biz-row" style="display:none;margin-top:10px;padding:8px 10px;background:#f0f4f8;border-radius:6px;border-left:3px solid #3498db;">
                        <div style="font-size:12px;color:#7f8c8d;margin-bottom:6px;font-weight:600;">📅 공휴일(토·일) 인 경우</div>
                        <div style="display:flex;gap:14px;flex-wrap:wrap;">
                            <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;">
                                <input type="radio" name="r-biz" value="none" checked onchange="updateRPreview()"> 조정 없음
                            </label>
                            <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;">
                                <input type="radio" name="r-biz" value="prev" onchange="updateRPreview()"> 이전 <span style="color:#888;font-size:12px;">(토·일→금)</span>
                            </label>
                            <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;">
                                <input type="radio" name="r-biz" value="next" onchange="updateRPreview()"> 다음 <span style="color:#888;font-size:12px;">(토·일→월)</span>
                            </label>
                        </div>
                    </div>
                </td>
            </tr>
            <tr>
                <td>시간</td>
                <td id="r-time-disp" style="padding-top:10px;color:#555;"></td>
            </tr>
            <tr>
                <td>종료</td>
                <td>
                    <div style="display:flex;gap:14px;flex-wrap:wrap;">
                        <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;"><input type="radio" name="rend" value="none" checked onchange="onREndChange()"> 없음</label>
                        <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;"><input type="radio" name="rend" value="date" onchange="onREndChange()"> 날짜</label>
                        <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;"><input type="radio" name="rend" value="count" onchange="onREndChange()"> 횟수</label>
                    </div>
                    <div class="r-end-row">
                        <span id="r-start-lbl" style="color:#888;"></span>
                        <span style="color:#888;">부터</span>
                        <input type="date" id="r-end-date" style="display:none;" oninput="updateRPreview()">
                        <span id="r-end-count-wrap" style="display:none;align-items:center;gap:4px;">
                            <input type="number" id="r-end-count" value="10" min="1" max="999" oninput="updateRPreview()">
                            <span>번</span>
                        </span>
                    </div>
                </td>
            </tr>
        </table>
        <div class="r-preview" id="r-preview"></div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeRecurModal()">취소</button>
            <button class="btn btn-primary" onclick="applyRecur()">설정</button>
        </div>
    </div>
</div>

<?php
// 지도 키 노출 정책:
//  - JS SDK용 클라이언트 키는 브라우저 노출이 불가피 → 도메인/리퍼러 제한으로 보호
//  - 시크릿(서버 지오코딩 키)은 절대 출력하지 않음
$__naverClientId   = defined('NAVER_MAPS_CLIENT_ID')   ? NAVER_MAPS_CLIENT_ID   : '';
$__googleBrowserKey= defined('GOOGLE_MAPS_BROWSER_KEY')? GOOGLE_MAPS_BROWSER_KEY : '';
$__naverGeoReady   = defined('NAVER_MAPS_KEY_ID')      && NAVER_MAPS_KEY_ID      !== '';
$__googleGeoReady  = defined('GOOGLE_MAPS_SERVER_KEY') && GOOGLE_MAPS_SERVER_KEY !== '';
?>
<script>
// 지도 설정 (서버에서 주입) — 시크릿은 포함되지 않음
window.MAP_CFG = {
    naverClientId:    <?= json_encode($__naverClientId) ?>,
    googleBrowserKey: <?= json_encode($__googleBrowserKey) ?>,
    naverGeoReady:    <?= $__naverGeoReady  ? 'true' : 'false' ?>,
    googleGeoReady:   <?= $__googleGeoReady ? 'true' : 'false' ?>
};
</script>

<script>
let _projects = [];   // 프로젝트 목록 캐시

// HTML 이스케이프 (XSS 방지)
function esc(s) { const d=document.createElement('span'); d.textContent=s; return d.innerHTML; }

// 이벤트 배경색: 프로젝트가 있으면 프로젝트 색상, 없으면 이벤트 자체 색상
function evBgColor(ev) {
    if (ev.project_id) {
        const p = _projects.find(x => x.id == ev.project_id);
        if (p) return p.color || '#3498db';
    }
    return ev.color || '#3498db';
}
// 배경색 대비 글자색: 밝으면 검정, 어두우면 흰색
function contrastColor(hex) {
    if (!hex || hex.length < 7) return '#ffffff';
    const r = parseInt(hex.slice(1,3),16);
    const g = parseInt(hex.slice(3,5),16);
    const b = parseInt(hex.slice(5,7),16);
    return (0.299*r + 0.587*g + 0.114*b) / 255 > 0.5 ? '#1a1a1a' : '#ffffff';
}
// 이벤트 앞 프로젝트 번호(#N) — 배경 없이 대비색 텍스트만
function projNumBadge(ev) {
    if (!ev.project_id) return '';
    const p = _projects.find(x => x.id == ev.project_id);
    if (!p || p.type !== 'project') return '';   // 프로젝트 포함 일정만 표시
    const ico = p.icon || '📌';                   // 프로젝트 이모지 (없으면 기본)
    return `<span class="proj-num-badge" title="${esc(p.title)}">${ico} </span>`;
}

// 참석자 표시: 1명이면 (이름), 여러명이면 (이름+N)
function attendeeSuffix(ev) {
    const a = ev.attendees || [];
    if (!a.length) return '';
    return a.length === 1 ? `(${a[0].name})` : `(${a[0].name}+${a.length - 1})`;
}

// 칩 제목 텍스트 (제목 + 참석자) — esc() 안에서 사용
function evLabel(ev) {
    return ev.title + attendeeSuffix(ev);
}

// 위치 마커: 주소 있으면 📍 + 지역태그(국내=kr / 해외=o)
// 클릭 시 상세 모달 대신 외부 지도(네이버/구글)를 바로 새 탭으로 연다.
function mapMark(ev) {
    if (!(ev.address && String(ev.address).trim())) return '';
    const tag = ev.provider === 'google' ? 'o' : 'kr';
    return ` <span class="chip-map-mark" onclick="openMapFromChip(event, ${ev.id})" title="지도 바로 열기">📍(${tag})</span>`;
}

// 칩의 위치 마커 클릭 → 제공자에 맞는 외부 지도 새 탭
function openMapFromChip(e, id) {
    e.stopPropagation();
    const ev = S.events.find(x => x.id == id);
    if (!ev) return;
    const url = ev.provider === 'google'
        ? googleMapUrl(ev.address, ev.lat, ev.lng)
        : naverMapUrl(ev.address, ev.lat, ev.lng);
    window.open(url, '_blank', 'noopener');
}

// 프로젝트 D-day (종료일 기준): D-n / D-DAY / 종료 +n일
function projDday(end) {
    if (!end) return '';
    const today = new Date(); today.setHours(0,0,0,0);
    const e = new Date(end + 'T00:00:00');
    const diff = Math.round((e - today) / 86400000);
    if (diff > 0)  return `D-${diff}`;
    if (diff === 0) return 'D-DAY';
    return `종료 +${-diff}일`;
}

// 그룹/프로젝트 상세 목록용: 참석자 요약 (attendee_names 콤마문자열 기반)
function pdpAtt(ev) {
    const s = (ev.attendee_names || '').trim();
    if (!s) return '';
    const a = s.split(',').filter(Boolean);
    return a.length <= 1 ? (a[0] || '') : `${a[0]}+${a.length - 1}`;
}
// 그룹/프로젝트 상세 목록용: 주소 아이콘 (클릭 시 외부 지도)
function pdpMapIcon(ev) {
    if (!(ev.address && String(ev.address).trim())) return '';
    const tag = ev.provider === 'google' ? 'o' : 'kr';
    return ` <span class="chip-map-mark" data-prov="${ev.provider || 'naver'}" data-addr="${encodeURIComponent(ev.address)}" title="지도 열기">📍(${tag})</span>`;
}

const S = {
    view: 'month', year: new Date().getFullYear(), month: new Date().getMonth()+1,
    weekStart: mondayOf(new Date()), day: new Date(),
    events: [], editId: null, editEv: null, color: '#3498db',
};
const TODAY = new Date(); TODAY.setHours(0,0,0,0);
let _selDay = null;   // 모바일 월간뷰에서 선택된 날짜(YYYY-MM-DD)

function isMobileView(){ return document.body.classList.contains('is-mobile') || document.body.classList.contains('w-narrow'); }

// 모바일: 선택한 날짜의 상세 일정 패널 렌더
function showDayDetail(ds){
    _selDay = ds;
    // 선택 셀 강조 갱신
    document.querySelectorAll('.cal-cell.sel-day').forEach(c=>c.classList.remove('sel-day'));
    const selCell=document.querySelector(`.cal-cell[data-ds="${ds}"]`);
    if (selCell) selCell.classList.add('sel-day');

    const box=document.getElementById('m-day-detail');
    if (!box) return;
    const d=new Date(ds+'T00:00:00');
    const DK=['일','월','화','수','목','금','토'];
    const evs=S.events.filter(e=>(e.start_dt||e.due_dt||'').startsWith(ds))
        .sort((a,b)=>(a.start_dt||'').localeCompare(b.start_dt||''));

    let html=`<div class="mdd-head">${d.getMonth()+1}월 ${d.getDate()}일 (${DK[d.getDay()]})</div>`;
    if (evs.length===0){
        html+=`<div class="mdd-empty">일정이 없습니다. 우측 하단 ＋ 버튼으로 추가하세요.</div>`;
    } else {
        html+=evs.map((ev,i)=>{
            const isDone=ev.is_done=='1';
            const isHol=ev.is_holiday=='1'||ev.event_type==='holiday';
            const bg = isHol ? '#e74c3c' : evBgColor(ev);
            const tr = fmtTimeRange(ev).trim() || (ev.is_allday=='1'||ev.event_type==='allday'?'종일':'');
            return `<div class="mdd-item${isDone?' done':''}" data-idx="${i}">
                <span class="mdd-bar" style="background:${bg}"></span>
                <span class="mdd-time">${esc(tr||'-')}</span>
                <span class="mdd-title">${esc((ev.icon?ev.icon+' ':'')+ (ev.title||'(제목없음)'))}${mapMark(ev)}</span>
            </div>`;
        }).join('');
    }
    box.innerHTML=html;
    // 항목 탭 → 상세 모달
    box.querySelectorAll('.mdd-item').forEach(el=>{
        el.addEventListener('click',()=>{ const ev=evs[+el.dataset.idx]; if(ev) openView(ev); });
    });
}

// 플로팅 + : 선택한 날짜(없으면 오늘)로 새 일정 추가
function fabAdd(){
    const ds = _selDay || ymd(TODAY);
    openNew(ds+'T09:00');
}

function pad(n) { return String(n).padStart(2,'0'); }
function ymd(d) { return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`; }
function ymdhm(d) { return ymd(d)+`T${pad(d.getHours())}:${pad(d.getMinutes())}`; }
function mondayOf(d) {
    const r=new Date(d); r.setHours(0,0,0,0);
    const diff=r.getDay()===0?-6:1-r.getDay(); r.setDate(r.getDate()+diff); return r;
}
function addDays(d,n) { const r=new Date(d); r.setDate(r.getDate()+n); return r; }
function sameDay(a,b) { return ymd(a)===ymd(b); }
function priorityLabel(p) { return p==1?'높음':p==3?'낮음':'보통'; }
function priorityClass(p) { return p==1?'p1':p==3?'p3':'p2'; }

// 시간 범위 포맷: "(종일)" 또는 "(HH:MM~HH:MM)"
function fmtTimeRange(ev) {
    // 절기·잡절·공휴일은 시간 표시 없음
    if (['holiday','jeoegi','sundry'].includes(ev.event_type)) return '';
    // 기념일: 이모지 + 카테고리
    if (ev.event_type === 'anniversary') {
        const icon = ev.icon ? ev.icon + ' ' : '';
        const cat  = ev.category ? '[' + ev.category + '] ' : '';
        return icon + cat;
    }
    if (ev.is_allday=='1' || ev.event_type==='allday') {
        return '';
    }
    if (ev.event_type === 'todo') return '☑ ';
    if (!ev.start_dt) return '';
    const s = ev.start_dt.slice(11,16);
    if (!s || s==='00:00') return '';
    const e = ev.end_dt ? ev.end_dt.slice(11,16) : '';
    const validEnd = e && e!=='00:00' && e!=='23:59';
    return validEnd ? `(${s}~${e}) ` : `(${s}) `;
}

async function api(action, payload={}, method='GET', module='calendar') {
    const base='/schedule_api.php';
    try {
        if (method==='GET') {
            const q=new URLSearchParams(Object.assign({module,action},payload)).toString();
            return (await fetch(`${base}?${q}`,{cache:'no-store'})).json();
        }
        return (await fetch(`${base}?module=${encodeURIComponent(module)}&action=${encodeURIComponent(action)}`,{
            method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)
        })).json();
    } catch(e) { console.error('API error',module,action,e); return {ok:false,data:[]}; }
}

async function loadEvents() {
    let p={};
    if (S.view==='month')      p={view:'month',year:S.year,month:S.month};
    else if (S.view==='week')  p={view:'week',start:ymd(S.weekStart)};
    else if (S.view==='day')   p={view:'day',date:ymd(S.day)};
    else                       p={view:'list',start:`${S.year}-01-01`,end:`${S.year}-12-31`};
    const res=await api('list',p);
    S.events=res.data||[]; render();
}
function render() {
    updateToolbar();
    if (S.view==='month') renderMonth();
    else if (S.view==='week') renderWeek();
    else if (S.view==='day') renderDay();
    else renderList();
    renderProjPanel();   // 현재 뷰 기간에 맞춰 프로젝트 목록 갱신
}

// 현재 보고 있는 달력의 표시 기간 [start, end] (YYYY-MM-DD)
function viewRange() {
    if (S.view==='month') {
        const last = new Date(S.year, S.month, 0).getDate();
        const mm = pad(S.month);
        return [`${S.year}-${mm}-01`, `${S.year}-${mm}-${pad(last)}`];
    }
    if (S.view==='week') return [ymd(S.weekStart), ymd(addDays(S.weekStart,6))];
    if (S.view==='day')  return [ymd(S.day), ymd(S.day)];
    return [`${S.year}-01-01`, `${S.year}-12-31`];   // list(연간)
}

function updateToolbar() {
    const MK=['1월','2월','3월','4월','5월','6월','7월','8월','9월','10월','11월','12월'];
    const DK=['일','월','화','수','목','금','토'];
    const el=document.getElementById('period-label');
    if (S.view==='month')     el.textContent=`${S.year}년 ${MK[S.month-1]}`;
    else if (S.view==='week') el.textContent=`${ymd(S.weekStart)} ~ ${ymd(addDays(S.weekStart,6))}`;
    else if (S.view==='day')  el.textContent=`${ymd(S.day)} (${DK[S.day.getDay()]})`;
    else                      el.textContent=`${S.year}년 일정 목록`;
}

function renderMonth() {
    const grid=document.getElementById('cal-grid'); grid.innerHTML='';
    const first=new Date(S.year,S.month-1,1), last=new Date(S.year,S.month,0);
    const startDay=first.getDay(), total=Math.ceil((startDay+last.getDate())/7)*7;
    for (let i=0;i<total;i++) {
        const date=new Date(S.year,S.month-1,1-startDay+i);
        const dow=date.getDay(), isOther=date.getMonth()!==S.month-1;
        const cell=document.createElement('div');
        cell.className='cal-cell'+(isOther?' other-month':'')+(sameDay(date,TODAY)?' today':'')
            +(dow===0?' sunday':dow===6?' saturday':'');
        const ds=ymd(date), dayEvs=S.events.filter(e=>(e.start_dt||'').startsWith(ds));
        cell.dataset.ds=ds;
        if (ds===_selDay) cell.classList.add('sel-day');
        const BADGE_TYPES = ['holiday','jeoegi','sundry']; // 뱃지 전용 타입 (anniversary는 칩으로 표시)
        const holidays   = dayEvs.filter(e=>e.is_holiday=='1'||e.event_type==='holiday');
        const jeoegis    = dayEvs.filter(e=>['jeoegi','sundry'].includes(e.event_type));
        const regularEvs = dayEvs.filter(e=>!BADGE_TYPES.includes(e.event_type)&&e.is_holiday!='1');

        // 셀 상단: [날짜 번호] [공휴일/절기명 오른쪽]
        const header=document.createElement('div'); header.className='cell-header';
        const num=document.createElement('div');
        // 공휴일이면 날짜도 빨간색
        num.className='day-num'+(holidays.length>0?' holiday-day':'');
        num.textContent=date.getDate();
        header.appendChild(num);

        // 우측: 공휴일(빨간색) + 절기(회색) 동시 표시
        if (holidays.length > 0 || jeoegis.length > 0) {
            const right=document.createElement('div');
            right.style.cssText='display:flex;flex-direction:column;align-items:flex-end;flex:1;overflow:hidden;gap:1px;';
            if (holidays.length > 0) {
                const hBadge=document.createElement('div'); hBadge.className='holiday-badge';
                hBadge.textContent=holidays[0].title+(holidays.length>1?` +${holidays.length-1}`:'');
                hBadge.title=holidays.map(h=>h.title).join(', ');
                right.appendChild(hBadge);
            }
            if (jeoegis.length > 0) {
                const jBadge=document.createElement('div'); jBadge.className='jeoegi-badge';
                jBadge.textContent=jeoegis[0].title;
                right.appendChild(jBadge);
            }
            header.appendChild(right);
        }
        cell.appendChild(header);

        // 프로젝트 기간 막대: 이 날짜가 프로젝트 기간(start~end)에 포함되면 표시
        _projects.filter(p=>p.type==='project'&&p.start_dt&&p.end_dt&&ds>=p.start_dt&&ds<=p.end_dt&&!p.is_done)
            .forEach(p=>{
                const isStart=ds===p.start_dt, isEnd=ds===p.end_dt;
                const bar=document.createElement('div'); bar.className='proj-bar';
                bar.style.background=p.color||'#3498db';
                bar.style.borderRadius=`${isStart?'4px':'0'} ${isEnd?'4px':'0'} ${isEnd?'4px':'0'} ${isStart?'4px':'0'}`;
                bar.textContent=isStart?((p.icon||'')+p.title):'';   // 시작일에만 제목
                bar.title=p.title+' — 클릭하면 이 프로젝트로 일정 추가';
                bar.style.cursor='pointer';
                // 프로젝트 색상 칸 클릭 → 이 날짜로 일정 추가 (프로젝트 자동 고정)
                bar.onclick=e=>{e.stopPropagation(); if(isMobileView()){showDayDetail(ds);} else {openNew(ds+'T09:00');}};
                cell.appendChild(bar);
            });

        // 모바일: 모든 일정을 점으로 표시(개수 시각화) / 데스크톱: 칩 3개 + 더보기
        const mob=isMobileView();
        const evsToShow = mob ? regularEvs : regularEvs.slice(0,3);
        evsToShow.forEach(ev=>{
            const chip=document.createElement('div');
            const isDone=ev.is_done=='1';
            chip.className='event-chip'+(isDone?' done':'');
            if (!isDone) {
                const bg = evBgColor(ev);
                chip.style.background = bg;
                chip.style.color = contrastColor(bg);
            }
            chip.innerHTML=projNumBadge(ev)+esc((isDone?'✓ ':'')+fmtTimeRange(ev)+evLabel(ev))+mapMark(ev);
            // 모바일: 칩(점) 탭 → 그날 상세 / 데스크톱: 바로 상세 모달
            chip.onclick=e=>{e.stopPropagation(); if(isMobileView()){showDayDetail(ds);} else {openView(ev);}};
            cell.appendChild(chip);
        });
        if (!mob && regularEvs.length>3) {
            const more=document.createElement('div'); more.className='more-link';
            more.textContent=`+${regularEvs.length-3}개 더보기`;
            more.onclick=e=>{e.stopPropagation();switchView('day');S.day=date;loadEvents();};
            cell.appendChild(more);
        }
        // 셀 탭: 모바일=그날 상세 패널 / 데스크톱=일정 추가
        cell.addEventListener('click',()=>{ if(isMobileView()){showDayDetail(ds);} else {openNew(ds+'T09:00');} });
        grid.appendChild(cell);
    }
    // 모바일: 하단 상세 패널 갱신 (선택일이 이번 달이 아니면 오늘/1일로)
    if (isMobileView()) {
        const inMonth = _selDay && _selDay.startsWith(`${S.year}-${pad(S.month)}`);
        const def = inMonth ? _selDay : (sameMonth(TODAY,S.year,S.month) ? ymd(TODAY) : ymd(first));
        showDayDetail(def);
    }
}
function sameMonth(d,y,m){ return d.getFullYear()===y && (d.getMonth()+1)===m; }

// 이벤트가 시간 그리드(시간대)에 표시되어야 하는지 판별
function isTimedEv(e) {
    // 시간 그리드에 표시할 이벤트: timed 타입이고 종일 아닌 것만
    if (e.is_allday==1 || e.is_allday=='1' || e.is_allday==='1') return false;
    if (['allday','anniversary','todo','holiday','jeoegi','sundry'].includes(e.event_type)) return false;
    // event_type이 없거나 'timed'인 경우만 허용
    return !e.event_type || e.event_type === 'timed';
}

function renderWeek() {
    const c=document.getElementById('view-week');
    const DK=['일','월','화','수','목','금','토'];
    const days=Array.from({length:7},(_,i)=>addDays(S.weekStart,i));
    const CELL_H=40;

    // 헤더
    let h='<div class="week-wrap"><div class="week-grid" style="grid-template-columns:50px repeat(7,1fr)">';
    h+='<div class="week-head"></div>';
    days.forEach(d=>{
        const dow=d.getDay();
        h+=`<div class="week-head ${dow===0?'sun':dow===6?'sat':''}${sameDay(d,TODAY)?' today-col':''}">${DK[dow]}<br>${d.getDate()}</div>`;
    });
    h+='</div>';

    // 상단 행 렌더 헬퍼
    const topRow=(label, filter, icon2)=>{
        let r=`<div class="week-allday-row"><div class="week-allday-label">${label}</div>`;
        days.forEach(d=>{
            const ds=ymd(d);
            const evs=S.events.filter(e=>filter(e)&&(e.start_dt||e.due_dt||'').startsWith(ds));
            r+=`<div class="week-allday-cell">`+evs.map(ev=>{
                const isHol=ev.is_holiday==1||ev.event_type==='holiday';
                const done=ev.is_done==1||ev.is_done=='1';
                const cls='allday-chip'+(isHol?' holiday':'')+(done?' done':'');
                const bg2 = (!isHol&&!done) ? evBgColor(ev) : '';
                const sty = bg2 ? `background:${bg2};color:${contrastColor(bg2)}` : '';
                const ico=isHol?'':(icon2[ev.event_type]||'');
                const cat=(!isHol&&ev.event_type==='anniversary'&&ev.category)?'['+ev.category+'] ':'';
                return `<div class="${cls}" style="${sty}" data-id="${ev.id}">${isHol?'':projNumBadge(ev)}${esc((done?'✓ ':'')+ico+cat+evLabel(ev))}${mapMark(ev)}</div>`;
            }).join('')+'</div>';
        });
        return r+'</div>';
    };

    // 순서: 공휴일 → 기념일 → 할일 → 종일
    h+=topRow('공휴일', e=>['holiday','jeoegi','sundry'].includes(e.event_type), {'holiday':'','jeoegi':'','sundry':''});
    h+=topRow('기념일', e=>e.event_type==='anniversary', {anniversary:''});
    h+=topRow('할 일', e=>e.event_type==='todo', {todo:'☑ '});
    h+=topRow('종일', e=>(e.is_allday==1||e.is_allday=='1')&&e.event_type==='timed', {timed:''});

    // 프로젝트 행 — 이번 주와 겹치는 프로젝트마다 1줄, 기간 요일 배경색 표시
    const wkS=ymd(days[0]), wkE=ymd(days[6]);
    const weekProjs=_projects.filter(p=>p.type==='project'&&!p.is_done&&p.start_dt&&p.end_dt&&p.start_dt<=wkE&&p.end_dt>=wkS);
    weekProjs.forEach(p=>{
        h+=`<div class="week-allday-row proj-week-row"><div class="week-allday-label" title="${esc(p.title)}">${p.icon||'📌'}</div>`;
        days.forEach((d,i)=>{
            const ds=ymd(d);
            const inR=ds>=p.start_dt&&ds<=p.end_dt;
            const isStart=ds===p.start_dt, isEnd=ds===p.end_dt;
            // 이번 주 첫 칸(주 시작)에서 시작 이전부터 이어진 경우에도 이름 표시
            const showName=inR&&(isStart||i===0);
            const sty=inR
                ? `background:${p.color||'#3498db'};color:${contrastColor(p.color||'#3498db')};border-radius:${isStart?'4px':'0'} ${isEnd?'4px':'0'} ${isEnd?'4px':'0'} ${isStart?'4px':'0'};`
                : '';
            const nameTxt=showName?`<span class="proj-week-name">${esc((p.icon||'')+p.title)}</span>`:'';
            h+=`<div class="week-allday-cell proj-week-cell${inR?' in-range':''}" style="${sty}" ${inR?`data-proj="${p.id}" data-date="${ds}"`:''}>${nameTxt}</div>`;
        });
        h+=`</div>`;
    });

    // 시간대 그리드
    h+=`<div style="overflow-y:auto;flex:1;min-height:0;"><div style="position:relative;">`;
    h+='<div class="week-grid" style="grid-template-columns:50px repeat(7,1fr)">';
    for (let hr=0;hr<24;hr++) {
        h+=`<div class="time-label">${pad(hr)}:00</div>`;
        days.forEach(d=>{ h+=`<div class="week-cell" data-date="${ymd(d)}" data-hour="${hr}"></div>`; });
    }
    h+='</div>';

    // 이벤트 오버레이
    h+=`<div style="position:absolute;top:0;left:50px;right:0;height:${24*CELL_H}px;display:grid;grid-template-columns:repeat(7,1fr);pointer-events:none;">`;
    days.forEach(d=>{
        const ds=ymd(d);
        const dayEvs=S.events.filter(e=>isTimedEv(e)&&(e.start_dt||'').startsWith(ds));
        h+='<div class="week-ev-col">';
        dayEvs.forEach(ev=>{
            const sd=new Date(ev.start_dt);
            const ed=ev.end_dt?new Date(ev.end_dt):null;
            const topPx=(sd.getHours()+sd.getMinutes()/60)*CELL_H;
            const durMin=ed?(ed-sd)/60000:60;
            const hPx=Math.max(durMin/60*CELL_H, 18);
            const done=ev.is_done==1||ev.is_done=='1';
            const cls='week-event'+(done?' done':'');
            const wBg=done?'':evBgColor(ev);
            const bg=done?'':`background:${wBg};color:${contrastColor(wBg)};`;
            const txt=projNumBadge(ev)+esc((done?'✓ ':'')+fmtTimeRange(ev)+evLabel(ev))+mapMark(ev);
            h+=`<div class="${cls}" style="pointer-events:all;position:absolute;${bg}top:${topPx}px;height:${hPx}px;left:2px;right:2px;" data-id="${ev.id}">${txt}</div>`;
        });
        h+='</div>';
    });
    h+='</div></div></div></div>'; c.innerHTML=h;

    c.querySelectorAll('.week-event,.allday-chip').forEach(el=>el.addEventListener('click',e=>{e.stopPropagation();const ev=S.events.find(x=>x.id==el.dataset.id);if(ev)openView(ev);}));
    c.querySelectorAll('.week-cell').forEach(el=>el.addEventListener('click',()=>openNew(`${el.dataset.date}T${pad(el.dataset.hour)}:00`)));
    // 프로젝트 색칸 클릭 → 그 날짜로 일정 추가 (프로젝트 자동 고정)
    c.querySelectorAll('.proj-week-cell.in-range').forEach(el=>{
        el.style.cursor='pointer';
        el.addEventListener('click',e=>{e.stopPropagation();openNew(`${el.dataset.date}T09:00`);});
    });
    const scroll=c.querySelector('[style*="overflow-y"]');
    if(scroll) scroll.scrollTop=Math.max(new Date().getHours()*CELL_H-80,0);
}

function renderDay() {
    const c=document.getElementById('view-day'), ds=ymd(S.day);
    const CELL_H=40;

    // 상단 행 헬퍼 (일간용)
    const topRowDay=(label, filter, iconMap)=>{
        const evs=S.events.filter(e=>filter(e)&&(e.start_dt||e.due_dt||'').startsWith(ds));
        if (!evs.length) return '';
        let r=`<div class="day-allday-row"><div class="day-allday-label">${label}</div>`;
        r+=evs.map(ev=>{
            const isHol=ev.is_holiday==1||ev.event_type==='holiday';
            const done=ev.is_done==1||ev.is_done=='1';
            const cls='allday-chip'+(isHol?' holiday':'')+(done?' done':'');
            const dBg = (!isHol&&!done) ? evBgColor(ev) : '';
            const sty = dBg ? `background:${dBg};color:${contrastColor(dBg)}` : '';
            const ico=isHol?'':(iconMap[ev.event_type]||'');
            const cat=(!isHol&&ev.event_type==='anniversary'&&ev.category)?'['+ev.category+'] ':'';
            return `<div class="${cls}" style="${sty}" data-id="${ev.id}">${isHol?'':projNumBadge(ev)}${esc((done?'✓ ':'')+ico+cat+ev.title)}</div>`;
        }).join('');
        return r+'</div>';
    };

    let h='<div class="day-wrap">';
    // 공휴일/절기 (항상 표시)
    const holEvs=S.events.filter(e=>['holiday','jeoegi','sundry'].includes(e.event_type)&&(e.start_dt||'').startsWith(ds));
    if (holEvs.length) {
        h+='<div class="day-allday-row"><div class="day-allday-label">공휴일</div>';
        h+=holEvs.map(ev=>{
            const isHol=ev.event_type==='holiday';
            const cls='allday-chip'+(isHol?' holiday':'');
            const sty=isHol?'':'background:'+ev.color;
            return `<div class="${cls}" style="${sty}" data-id="${ev.id}">${ev.title}</div>`;
        }).join('');
        h+='</div>';
    }
    // 순서: 공휴일 → 기념일 → 할일 → 종일
    h+=topRowDay('기념일', e=>e.event_type==='anniversary', {anniversary:''});
    h+=topRowDay('할 일', e=>e.event_type==='todo', {todo:'☑ '});
    h+=topRowDay('종일', e=>(e.is_allday==1||e.is_allday=='1')&&e.event_type==='timed', {timed:''});

    // 프로젝트 행 — 이 날짜가 기간에 포함된 프로젝트마다 1줄 (색칠 + 이름)
    _projects.filter(p=>p.type==='project'&&!p.is_done&&p.start_dt&&p.end_dt&&ds>=p.start_dt&&ds<=p.end_dt)
        .forEach(p=>{
            const bg=p.color||'#3498db';
            h+=`<div class="day-allday-row proj-day-row"><div class="day-allday-label">${p.icon||'📌'}</div>
                <div class="allday-chip proj-day-chip" style="background:${bg};color:${contrastColor(bg)};" data-proj="${p.id}" data-date="${ds}">${esc((p.icon||'')+p.title)}</div>
            </div>`;
        });

    // 시간대 그리드
    h+=`<div style="overflow-y:auto;flex:1;min-height:0;"><div style="position:relative;">`;
    h+='<div class="day-grid">';
    for (let hr=0;hr<24;hr++) {
        h+=`<div class="time-label">${pad(hr)}:00</div>`;
        h+=`<div class="day-cell" data-hour="${hr}"></div>`;
    }
    h+='</div>';

    // 이벤트 오버레이
    const dayEvs=S.events.filter(e=>isTimedEv(e)&&(e.start_dt||'').startsWith(ds));
    h+=`<div style="position:absolute;top:0;left:50px;right:0;height:${24*CELL_H}px;pointer-events:none;">`;
    dayEvs.forEach(ev=>{
        const sd=new Date(ev.start_dt);
        const ed=ev.end_dt?new Date(ev.end_dt):null;
        const topPx=(sd.getHours()+sd.getMinutes()/60)*CELL_H;
        const durMin=ed?(ed-sd)/60000:60;
        const hPx=Math.max(durMin/60*CELL_H, 20);
        const done=ev.is_done==1||ev.is_done=='1';
        const cls='day-event'+(done?' done':'');
        const dEvBg=done?'':evBgColor(ev);
        const bg=done?'':`background:${dEvBg};color:${contrastColor(dEvBg)};`;
        const txt=projNumBadge(ev)+esc((done?'✓ ':'')+fmtTimeRange(ev)+ev.title);
        h+=`<div class="${cls}" style="pointer-events:all;position:absolute;${bg}top:${topPx}px;height:${hPx}px;left:4px;right:4px;" data-id="${ev.id}">${txt}</div>`;
    });
    h+='</div></div></div></div>'; c.innerHTML=h;

    c.querySelectorAll('.day-event,.allday-chip').forEach(el=>el.addEventListener('click',e=>{e.stopPropagation();const ev=S.events.find(x=>x.id==el.dataset.id);if(ev)openView(ev);}));
    c.querySelectorAll('.day-cell').forEach(el=>el.addEventListener('click',()=>openNew(`${ds}T${pad(el.dataset.hour)}:00`)));
    // 프로젝트 칩 클릭 → 그 날짜로 일정 추가 (프로젝트 자동 고정)
    c.querySelectorAll('.proj-day-chip').forEach(el=>el.addEventListener('click',e=>{e.stopPropagation();openNew(`${el.dataset.date}T09:00`);}));
    const scroll=c.querySelector('[style*="overflow-y"]');
    if(scroll) scroll.scrollTop=Math.max(new Date().getHours()*CELL_H-80,0);
}

function renderList() {
    const c=document.getElementById('view-list');
    if (!S.events.length){c.innerHTML='<p style="padding:20px;color:#999">올해 일정이 없습니다.</p>';return;}
    const rows=S.events.map(ev=>`<tr class="${ev.is_done=='1'?'done':''}" style="cursor:pointer" data-id="${ev.id}">
        <td><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:${ev.color};margin-right:6px"></span>${projNumBadge(ev)}${esc(evLabel(ev))}${mapMark(ev)}</td>
        <td>${ev.start_dt.slice(0,16).replace('T',' ')}</td>
        <td>${ev.category}</td>
        <td><span class="priority-badge ${priorityClass(ev.priority)}">${priorityLabel(ev.priority)}</span></td>
        <td style="text-align:center">${ev.is_done=='1'?'✔':'—'}</td>
    </tr>`).join('');
    c.innerHTML=`<table class="list-table"><thead><tr><th>제목</th><th>시작일시</th><th>카테고리</th><th>우선순위</th><th>완료</th></tr></thead><tbody>${rows}</tbody></table>`;
    c.querySelectorAll('tr[data-id]').forEach(tr=>tr.addEventListener('click',()=>{const ev=S.events.find(x=>x.id==tr.dataset.id);if(ev)openView(ev);}));
}

function setAllday(flag) {
    const chk=document.getElementById('f-allday');
    chk.checked=flag;
    const fs=document.getElementById('f-start'), fe=document.getElementById('f-end');
    // 현재 날짜 보존
    const curStartDate = fs.value ? fs.value.slice(0,10) : '';
    const curEndDate   = fe.value ? fe.value.slice(0,10) : curStartDate;
    if (flag) {
        fs.type='date'; fe.type='date';
        if (curStartDate) fs.value = curStartDate;
        if (curEndDate)   fe.value = curEndDate;
    } else {
        fs.type='datetime-local'; fe.type='datetime-local';
        // 날짜만 있으면 시간 복원
        if (curStartDate && !fs.value.includes('T')) fs.value = curStartDate + 'T09:00';
        if (curEndDate   && !fe.value.includes('T')) fe.value = curEndDate   + 'T10:00';
    }
}
function openNew(dt='', type='timed') {
    S.editId=null;
    document.getElementById('modal-title').textContent='일정 추가';
    document.getElementById('f-title').value='';
    document.getElementById('f-memo').value='';
    document.getElementById('f-cat').value='업무';
    document.getElementById('f-priority').value='2';
    setColor('#3498db');
    clearRecur();
    setEventType(type);
    setAllday(false);
    // 알림 초기화
    document.querySelectorAll('.f-alert').forEach(cb => cb.checked = false);

    // 날짜 기본값: 날짜는 클릭한 셀(dt), 시간은 항상 현재 시각
    const now      = new Date();
    const dateOnly = dt ? dt.slice(0,10) : ymd(now);
    const curHH    = pad(now.getHours()), curMM = pad(now.getMinutes());
    const startDt  = `${dateOnly}T${curHH}:${curMM}`;
    const endDate  = new Date(`${dateOnly}T${curHH}:${curMM}`);
    endDate.setHours(endDate.getHours() + 1);
    document.getElementById('f-start').value = startDt;
    document.getElementById('f-end').value   = ymdhm(endDate);
    document.getElementById('f-anniv-date').value = dateOnly;
    document.getElementById('f-due-dt').value     = dateOnly;
    // 기념일 분류·카테고리 초기화
    setAnnivClass(0);
    initAnnivCatBtns('생일');
    // 음력 초기화
    const solarRadio = document.querySelector('input[name="anniv-cal"][value="solar"]');
    if (solarRadio) { solarRadio.checked = true; onAnnivCalChange(); }
    document.getElementById('f-icon').value = '';
    // 참석자 초기화
    SEL_ATTENDEES = [];
    renderAttendeeChips();
    // 위치 초기화
    resetLocationForm();
    // 그룹/프로젝트: 그룹 드롭다운 + 날짜에 맞는 프로젝트 고정 표시
    fillGroupSelect();
    document.getElementById('f-group-id').value = '';
    applyProjectByDate();

    document.getElementById('btn-delete').style.display='none';
    document.getElementById('modal-overlay').classList.add('open');
    document.getElementById('f-title').focus();
}
function openEdit(ev) {
    if (ev.event_type==='holiday' || ev.is_holiday=='1') return; // 공휴일은 수정 불가
    S.editId=ev.id; S.editEv=ev;
    const type=ev.event_type||'timed';
    document.getElementById('modal-title').textContent='일정 수정';
    document.getElementById('f-title').value=ev.title;
    document.getElementById('f-cat').value=ev.category;
    document.getElementById('f-priority').value=ev.priority||2;
    document.getElementById('f-memo').value=ev.memo||'';
    setColor(ev.color);
    setEventType(type);
    clearRecur();

    // 타입별 날짜 복원
    if (type==='timed') {
        setAllday(ev.is_allday=='1');
        document.getElementById('f-start').value=ev.start_dt?ev.start_dt.slice(0,16):'';
        document.getElementById('f-end').value=ev.end_dt?ev.end_dt.slice(0,16):'';
    } else if (type==='allday') {
        document.getElementById('f-allday-start').value=ev.start_dt?ev.start_dt.slice(0,10):'';
        document.getElementById('f-allday-end').value=ev.end_dt?ev.end_dt.slice(0,10):'';
    } else if (type==='anniversary') {
        if (ev.is_lunar=='1') {
            // 음력: API 호출 없이 직접 값 세팅 (onAnnivCalChange 호출 시 API가 덮어씀)
            document.querySelector('input[name="anniv-cal"][value="lunar"]').checked = true;
            document.getElementById('row-anniv-solar').style.display = 'none';
            document.getElementById('row-anniv-lunar').style.display = '';
            document.getElementById('f-lunar-month').value = ev.lunar_month || 1;
            document.getElementById('f-lunar-day').value   = ev.lunar_day   || 1;
            updateLunarPreview();
        } else {
            // 양력: 등록된 날짜 그대로 표시
            document.querySelector('input[name="anniv-cal"][value="solar"]').checked = true;
            document.getElementById('row-anniv-solar').style.display = '';
            document.getElementById('row-anniv-lunar').style.display = 'none';
            // 반복 인스턴스면 원본 날짜에서 월/일만 유지 (연도는 원본 연도)
            const origDate = ev.is_recur_instance=='1'
                ? (ev.start_dt||'').slice(0,10)  // 원본 발생 날짜
                : (ev.start_dt||'').slice(0,10);
            document.getElementById('f-anniv-date').value = origDate;
        }
    } else if (type==='todo') {
        document.getElementById('f-due-dt').value=ev.due_dt||ev.start_dt?.slice(0,10)||'';
    }

    // 아이콘 복원
    document.getElementById('f-icon').value = ev.icon || '';
    // 그룹/프로젝트 복원 — ev.project_id 는 그룹 또는 프로젝트 행을 가리킴
    fillGroupSelect();
    document.getElementById('f-group-id').value = '';
    setProjectField(null);
    if (ev.project_id) {
        const owner = _projects.find(x => x.id == ev.project_id);
        if (owner && owner.type === 'project') {
            if (owner.parent_id) document.getElementById('f-group-id').value = owner.parent_id;
            setProjectField(owner.id);            // 저장된 프로젝트는 날짜 무관 고정 표시
        } else if (owner) {
            document.getElementById('f-group-id').value = ev.project_id;
        }
    }
    if (type === 'anniversary') {
        // 분류·카테고리 복원
        setAnnivClass(ev.is_family == '1' ? 1 : 0);
        const cat = ev.category || '생일';
        initAnnivCatBtns(cat);
        setTimeout(()=>renderEmojiPicker(cat, ev.icon||''), 0);
    }

    // 알림 복원
    const alertMins = ev.alert_mins || [];
    document.querySelectorAll('.f-alert').forEach(cb => {
        cb.checked = alertMins.includes(+cb.value);
    });

    // 참석자 복원
    SEL_ATTENDEES = (ev.attendees || []).map(a=>({id:a.id, name:a.name}));
    renderAttendeeChips();

    // 위치 복원
    fillLocationForm(ev);

    // 반복 규칙 복원
    RECUR = ev.recur_rule||null;
    if (RECUR) {
        const startDt = new Date(ev.start_dt||new Date());
        const summary  = recurSummaryLine(RECUR, startDt);
        document.querySelectorAll('.recur-type-btn').forEach(b => {
            b.classList.toggle('active', b.dataset.rtype === RECUR.type);
        });
        document.getElementById('h-recur-type').value = RECUR.type||'';
        document.getElementById('recur-summary-text').textContent = summary;
        document.getElementById('recur-summary-row').style.display = '';
        document.getElementById('btn-recur-clear').style.display = '';
        const ct = document.getElementById('btn-recur-clear-todo');
        if (ct) ct.style.display = '';
        document.getElementById('modal-title').textContent =
            `수정 — 반복일정 (${RECUR_LABEL[RECUR.type]||RECUR.type})`;
    }

    document.getElementById('btn-delete').style.display='';
    document.getElementById('modal-overlay').classList.add('open');
}
function closeModal() { document.getElementById('modal-overlay').classList.remove('open'); }
function setColor(c) {
    S.color=c;
    document.querySelectorAll('.color-swatch').forEach(el=>el.classList.toggle('selected',el.dataset.color===c));
}
async function saveEvent() {
    const title=document.getElementById('f-title').value.trim();
    if (!title){alert('제목을 입력하세요.');return;}

    // 위치: 주소가 있으면 저장 전 좌표 확보 (실패 시 사용자 확인)
    if (!await ensureGeocodedForSave()) return;

    const isAnniv = ETYPE === 'anniversary';
    const payload={
        event_type: ETYPE,
        title,
        category:  isAnniv ? document.getElementById('h-anniv-cat').value : document.getElementById('f-cat').value,
        is_family: isAnniv ? +document.getElementById('h-is-family').value : 0,
        color:     S.color,
        priority:  document.getElementById('f-priority').value,
        memo:      document.getElementById('f-memo').value,
        recur_rule: RECUR||null,
        alert_mins: [...document.querySelectorAll('.f-alert:checked')].map(cb=>+cb.value),
        attendees: SEL_ATTENDEES.map(a=>a.id),
        icon: document.getElementById('f-icon').value || null,
        // 프로젝트 우선, 없으면 그룹 (둘 다 tbl_project 행 → project_id 단일 컬럼)
        project_id: document.getElementById('f-project-id').value
                    || document.getElementById('f-group-id').value || null,
        // 위치/지도
        address:  document.getElementById('f-address').value.trim() || null,
        lat:      document.getElementById('f-lat').value || null,
        lng:      document.getElementById('f-lng').value || null,
        provider: document.getElementById('f-address').value.trim() ? document.getElementById('f-provider').value : null,
    };

    if (ETYPE==='timed') {
        const isAllday=document.getElementById('f-allday').checked;
        const sv=document.getElementById('f-start').value;
        if (!sv){alert('시작일시를 입력하세요.');return;}
        if (!isAllday && !checkEndTime()) { alert('종료 시간을 확인해 주세요.'); document.getElementById('f-end').focus(); return; }
        payload.start_dt = isAllday ? sv.slice(0,10)+' 00:00:00' : sv.replace('T',' ');
        const ev=document.getElementById('f-end').value;
        payload.end_dt   = ev ? (isAllday ? ev.slice(0,10)+' 23:59:59' : ev.replace('T',' ')) : null;
        payload.is_allday= isAllday ? 1 : 0;

    } else if (ETYPE==='anniversary') {
        const isLunar = document.querySelector('input[name="anniv-cal"]:checked')?.value === 'lunar';
        if (isLunar) {
            const lm = parseInt(document.getElementById('f-lunar-month').value);
            const ld = parseInt(document.getElementById('f-lunar-day').value);
            if (!lm||!ld){alert('음력 월/일을 입력하세요.');return;}
            payload.is_lunar    = 1;
            payload.lunar_month = lm;
            payload.lunar_day   = ld;
            payload.is_allday   = 1;
            payload.start_dt    = new Date().getFullYear()+'-'+String(lm).padStart(2,'0')+'-'+String(ld).padStart(2,'0')+' 00:00:00';
            payload.recur_rule  = null; // 음력은 자체 확장
        } else {
            const sv=document.getElementById('f-anniv-date').value;
            if (!sv){alert('날짜를 입력하세요.');return;}
            payload.start_dt  = sv+' 00:00:00';
            payload.is_allday = 1;
            payload.is_lunar  = 0;
            if (!payload.recur_rule) payload.recur_rule={type:'yearly',calendar:'solar',interval:1,end_type:'none'};
        }

    } else if (ETYPE==='todo') {
        const dv=document.getElementById('f-due-dt').value;
        payload.due_dt    = dv||null;
        payload.start_dt  = dv ? dv+' 00:00:00' : null;
        payload.is_allday = 1;
    }

    if (S.editId) {
        payload.id = S.editId;
        // 반복 인스턴스 수정 → 범위 선택
        if (S.editEv && S.editEv.is_recur_instance == '1') {
            openScopeModal('edit', payload); return;
        }
        const upRes = await api('update', payload, 'POST');
        if (upRes && !upRes.ok) { alert('저장 실패: ' + (upRes.msg||'')); return; }
        if (upRes?.shifted?.length) showToast(`겹치는 일정 ${upRes.shifted.length}건 시간이 자동 조정됐습니다.`);
    } else {
        const crRes = await api('create', payload, 'POST');
        if (crRes && !crRes.ok) { alert('저장 실패: ' + (crRes.msg||'')); return; }
        if (crRes?.shifted?.length) showToast(`겹치는 일정 ${crRes.shifted.length}건 시간이 자동 조정됐습니다.`);
    }
    closeModal(); loadEvents();
}
async function deleteEvent() {
    // 반복 인스턴스면 범위 선택 모달 (confirm 없이 바로)
    if (S.editEv && S.editEv.is_recur_instance == '1') {
        openScopeModal('delete'); return;
    }
    // 일반 일정은 confirm 한 번만
    if (!confirm('일정을 삭제하시겠습니까?')) return;
    const delRes = await api('delete', {id: S.editId});
    if (delRes && !delRes.ok) { alert('삭제 실패: ' + (delRes.msg||'')); return; }
    closeModal(); loadEvents();
}

// ━━━ 보기 모달 ━━━
let VIEW_EV = null;

function openView(ev) {
    if (ev.event_type === 'holiday' || ev.is_holiday=='1') return;
    VIEW_EV = ev;

    const isDone = ev.is_done == '1';
    const DAY_KR = ['일','월','화','수','목','금','토'];

    // 제목 (이모지 + 아이콘 포함)
    const icon = ev.icon ? ev.icon+' ' : '';
    const typeIcon = {timed:'',allday:'📅 ',anniversary:'',todo:'☑ '}[ev.event_type]||'';
    document.getElementById('view-title').textContent = icon + typeIcon + ev.title;

    // 완료 뱃지
    document.getElementById('view-done-badge').style.display = isDone ? '' : 'none';
    document.getElementById('view-btn-done').textContent = isDone ? '되돌리기' : '완료';

    // 날짜/시간
    let dtText = '';
    if (ev.start_dt) {
        const d = new Date(ev.start_dt);
        const dateStr = `${d.getFullYear()}년 ${d.getMonth()+1}월 ${d.getDate()}일 (${DAY_KR[d.getDay()]})`;
        if (ev.is_allday=='1') {
            dtText = '📅 ' + dateStr;
        } else {
            const s = pad(d.getHours())+':'+pad(d.getMinutes());
            const e = ev.end_dt ? (() => { const e=new Date(ev.end_dt); return ' ~ '+pad(e.getHours())+':'+pad(e.getMinutes()); })() : '';
            dtText = '🕐 ' + dateStr + ' ' + s + e;
        }
    } else if (ev.due_dt) {
        dtText = '📅 마감: ' + ev.due_dt;
    }
    // 음력 기념일이면 음력 날짜 추가
    if (ev.is_lunar=='1' && ev.lunar_month && ev.lunar_day) {
        dtText += `  *(음력 ${ev.lunar_month}/${ev.lunar_day})`;
    }
    document.getElementById('view-datetime').textContent = dtText;

    // 카테고리
    const cat = ev.category || '';
    const colorDot = `<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:${ev.color};margin-right:5px;"></span>`;
    document.getElementById('view-category').innerHTML = colorDot + cat;

    // 반복 정보
    const recurEl = document.getElementById('view-recur');
    if (ev.recur_rule && typeof ev.recur_rule === 'object') {
        const rLabel = {daily:'매일',weekly:'매주',monthly:'매월',yearly:'매년'};
        recurEl.textContent = '↺ ' + (rLabel[ev.recur_rule.type]||'반복') + ' 반복';
        recurEl.style.display = '';
    } else if (ev.is_lunar=='1') {
        recurEl.textContent = '↺ 음력 기념일 (매년 자동)';
        recurEl.style.display = '';
    } else {
        recurEl.style.display = 'none';
    }

    // 참석자
    const attEl = document.getElementById('view-attendees');
    if (ev.attendees && ev.attendees.length) {
        attEl.innerHTML = '👥 ' + ev.attendees.map(a=>
            `<span style="display:inline-block;background:#eaf4ff;color:#2980b9;border-radius:10px;padding:2px 8px;margin:2px;font-size:12px;">${a.name}</span>`
        ).join('');
        attEl.style.display = '';
    } else {
        attEl.style.display = 'none';
    }

    // 메모
    const memoEl = document.getElementById('view-memo');
    if (ev.memo && ev.memo.trim()) {
        memoEl.textContent = ev.memo;
        memoEl.style.display = '';
    } else {
        memoEl.style.display = 'none';
    }

    // 위치/지도
    renderViewLocation(ev);

    // 헤더 색상 띠
    document.getElementById('view-header').style.borderLeft = `4px solid ${ev.color||'#3498db'}`;

    document.getElementById('view-overlay').classList.add('open');
}

function closeViewModal() {
    document.getElementById('view-overlay').classList.remove('open');
    // VIEW_EV는 여기서 null 처리하지 않음 — 버튼 핸들러가 먼저 저장 후 닫기
}

function openEditFromView() {
    const ev = VIEW_EV;          // 닫기 전에 저장
    closeViewModal();
    VIEW_EV = null;
    if (!ev) return;
    S.editEv = ev; S.editId = ev.id;
    // 반복 인스턴스면 수정 시 scope는 saveEvent()에서 물어봄
    openEdit(ev);
}

async function toggleDoneFromView() {
    const ev = VIEW_EV;
    if (!ev) return;
    S.editEv = ev; S.editId = ev.id;
    closeViewModal();
    VIEW_EV = null;

    // 반복 인스턴스면 범위 선택
    if (ev.is_recur_instance == '1') {
        openScopeModal('done'); return;
    }
    await api('toggle_done', {id: ev.id}, 'POST', 'calendar');
    loadEvents();
}

async function deleteFromView() {
    const ev = VIEW_EV;
    if (!ev) return;
    S.editEv = ev; S.editId = ev.id;
    closeViewModal();
    VIEW_EV = null;

    // 반복 인스턴스면 범위 선택 모달
    if (ev.is_recur_instance == '1') {
        openScopeModal('delete'); return;
    }
    if (!confirm('일정을 삭제하시겠습니까?')) return;
    const res = await api('delete', {id: S.editId});   // VIEW_EV는 이미 null → S.editId 사용
    if (res && !res.ok) { alert('삭제 실패: ' + (res.msg||'')); return; }
    loadEvents();
}

// ━━━ 반복 범위 선택 모달 ━━━
let SCOPE_ACTION = null;
let SCOPE_PAYLOAD = null;

function openScopeModal(action, payload=null) {
    SCOPE_ACTION  = action;
    SCOPE_PAYLOAD = payload;
    const labels = {delete:['반복 일정 삭제','어떤 일정을 삭제하시겠습니까?'], edit:['반복 일정 수정','어떤 일정을 수정하시겠습니까?'], done:['반복 일정 완료','어떤 일정을 완료 처리하시겠습니까?']};
    const [title, desc] = labels[action] || labels['edit'];
    document.getElementById('scope-title').textContent = title;
    document.getElementById('scope-desc').textContent  = desc;
    document.getElementById('scope-overlay').classList.add('open');
}
function closeScopeModal() {
    document.getElementById('scope-overlay').classList.remove('open');
}
async function confirmScope(scope) {
    closeScopeModal();
    const originDt = S.editEv?.origin_dt || S.editEv?.start_dt?.slice(0,10) || '';
    if (SCOPE_ACTION === 'delete') {
        await api('delete_scoped', {id: S.editId, scope, origin_dt: originDt}, 'POST');
        closeModal();
    } else if (SCOPE_ACTION === 'edit') {
        if (!SCOPE_PAYLOAD) { alert('저장 데이터가 없습니다. 다시 시도해주세요.'); return; }
        SCOPE_PAYLOAD.origin_dt = originDt;
        SCOPE_PAYLOAD.scope     = scope;
        const res = await api('update_scoped', SCOPE_PAYLOAD, 'POST');
        if (!res.ok) { alert('저장 실패: ' + (res.msg||'알 수 없는 오류')); return; }
        closeModal();
    } else if (SCOPE_ACTION === 'done') {
        // 완료 범위 처리
        if (scope === 'all') {
            // 전체: 원본 is_done 토글
            await api('toggle_done', {id: S.editId}, 'POST', 'calendar');
        } else if (scope === 'one') {
            // 이 일정만: 해당 날짜를 예외로 분리 후 완료 처리
            const payload = {
                id: S.editId, scope: 'one',
                origin_dt: originDt,
                is_done_override: 1   // 서버에서 처리
            };
            await api('toggle_done_scoped', payload, 'POST', 'calendar');
        } else if (scope === 'future') {
            // 이 날짜 이후 종료 + 새 시리즈 완료
            await api('toggle_done_scoped', {id: S.editId, scope: 'future', origin_dt: originDt}, 'POST', 'calendar');
        }
    }
    loadEvents();
}
// 현재 뷰의 기준 날짜(앵커) 반환
function currentAnchor() {
    if (S.view==='month') return new Date(S.year, S.month-1, 1);
    if (S.view==='week')  return new Date(S.weekStart);
    if (S.view==='day')   return new Date(S.day);
    return new Date(S.year, 0, 1); // 목록 = 연 기준
}
// 앵커를 모든 뷰 위치에 동기화
function applyAnchor(d) {
    S.year  = d.getFullYear();
    S.month = d.getMonth()+1;
    S.weekStart = mondayOf(d);
    S.day = new Date(d); S.day.setHours(0,0,0,0);
}
// 현재 위치를 localStorage에 저장
function savePos() {
    try {
        localStorage.setItem('sch_pos', JSON.stringify({view:S.view, anchor:ymd(currentAnchor())}));
    } catch(e){}
}
// localStorage에서 위치 복원
function restorePos() {
    try {
        const p=JSON.parse(localStorage.getItem('sch_pos')||'null');
        if (p && p.anchor) {
            applyAnchor(new Date(p.anchor+'T00:00'));
            if (p.view) S.view=p.view;
            return true;
        }
    } catch(e){}
    return false;
}

function switchView(v) {
    // 전환 전 현재 뷰 위치를 모든 뷰에 동기화 (오늘로 점프 방지)
    applyAnchor(currentAnchor());
    S.view=v;
    ['month','week','day','list'].forEach(n=>{
        document.getElementById(`view-${n}`).style.display=n===v?(n==='month'?'flex':'block'):'none';
    });
    document.querySelectorAll('.view-tabs button').forEach(b=>b.classList.toggle('active',b.dataset.view===v));
    // 하단 상세 패널은 월간뷰에서만
    const mdd=document.getElementById('m-day-detail'); if(mdd) mdd.style.display = (v==='month'?'':'none');
    savePos();
    loadEvents();
}
function navigate(dir) {
    if (S.view==='month'){S.month+=dir;if(S.month>12){S.month=1;S.year++;}if(S.month<1){S.month=12;S.year--;}}
    else if (S.view==='week') S.weekStart=addDays(S.weekStart,dir*7);
    else if (S.view==='day')  S.day=addDays(S.day,dir);
    else S.year+=dir;
    savePos();
    loadEvents();
}
function goToday() {
    const n=new Date(); S.year=n.getFullYear(); S.month=n.getMonth()+1;
    S.weekStart=mondayOf(n); S.day=new Date(n); S.day.setHours(0,0,0,0);
    savePos(); loadEvents();
}

document.getElementById('f-allday').onchange  = e=>setAllday(e.target.checked);

// 참석자 입력: Enter 또는 datalist 선택 시 추가
document.getElementById('f-attendee-input').addEventListener('keydown', function(e){
    if (e.key==='Enter'){ e.preventDefault(); addAttendeeByName(this.value); }
});
document.getElementById('f-attendee-input').addEventListener('change', function(){
    if (this.value) addAttendeeByName(this.value);
});

// ━━━ 이모지 선택 ━━━
const EMOJI_MAP = {
    '생일': ['🎂','🎁','🎉','🎈','🥳','👶','🌸'],
    '제사': ['🕯️','🙏','🌸','🍚','🌹','😢','👴'],
    '기념일': ['💍','🥂','💑','🌹','🎊','💕','✨'],
    '기타': ['⭐','📅','🌟','🔔','📌','💡','🏆'],
};

function renderEmojiPicker(category, selectedIcon='') {
    const picker = document.getElementById('emoji-picker');
    const emojis = EMOJI_MAP[category] || EMOJI_MAP['기타'];
    picker.innerHTML = emojis.map(e =>
        `<button type="button" class="emoji-btn${e===selectedIcon?' selected':''}"
            data-emoji="${e}" onclick="selectEmoji('${e}')">${e}</button>`
    ).join('');
    // 선택된 아이콘 없으면 첫번째 자동 선택
    if (!selectedIcon || !emojis.includes(selectedIcon)) {
        selectEmoji(emojis[0]);
    }
}

function selectEmoji(emoji) {
    document.getElementById('f-icon').value = emoji;
    document.querySelectorAll('.emoji-btn').forEach(b => {
        b.classList.toggle('selected', b.dataset.emoji === emoji);
    });
}

// 카테고리 변경 시 이모지 피커 갱신
document.getElementById('f-cat').addEventListener('change', function() {
    if (ETYPE === 'anniversary') {
        renderEmojiPicker(this.value, '');
    }
});

// 음력/양력 전환
async function onAnnivCalChange() {
    const isLunar = document.querySelector('input[name="anniv-cal"]:checked')?.value === 'lunar';
    document.getElementById('row-anniv-solar').style.display = isLunar ? 'none' : '';
    document.getElementById('row-anniv-lunar').style.display = isLunar ? '' : 'none';

    if (isLunar) {
        // 현재 날짜 기준 (기념일 입력값 or 오늘)
        const solarDate = document.getElementById('f-anniv-date').value || ymd(new Date());
        const res = await api('solar_to_lunar', {date: solarDate}, 'GET', 'calendar');
        if (res.ok && res.data) {
            document.getElementById('f-lunar-month').value = res.data.month;
            document.getElementById('f-lunar-day').value   = res.data.day;
            document.getElementById('f-lunar-leap').checked = res.data.leap === true || res.data.leap == 1;
        }
    }
    updateLunarPreview();
}
function updateLunarPreview() {
    const m = document.getElementById('f-lunar-month')?.value;
    const d = document.getElementById('f-lunar-day')?.value;
    const leap = document.getElementById('f-lunar-leap')?.checked;
    const prev = document.getElementById('lunar-preview');
    if (prev && m && d) {
        prev.textContent = `음력 ${m}월 ${d}일${leap?' (윤달)':''} → 매년 양력으로 자동 변환`;
    }
}
['f-lunar-month','f-lunar-day'].forEach(id=>{
    const el=document.getElementById(id);
    if(el) el.addEventListener('change', updateLunarPreview);
});
const lunarLeapEl = document.getElementById('f-lunar-leap');
if(lunarLeapEl) lunarLeapEl.addEventListener('change', updateLunarPreview);

// 종료 시간 유효성 검사
function checkEndTime() {
    const sv = document.getElementById('f-start').value;
    const ev = document.getElementById('f-end').value;
    const endEl = document.getElementById('f-end');
    const msgEl = document.getElementById('end-time-msg');
    if (!sv || !ev) { endEl.style.borderColor=''; if(msgEl) msgEl.style.display='none'; return true; }
    const start = new Date(sv.replace('T',' ')), end = new Date(ev.replace('T',' '));
    if (isNaN(start)||isNaN(end)) return true;
    if (end <= start) {
        endEl.style.borderColor = '#e74c3c';
        if (msgEl) { msgEl.textContent = '⚠ 종료 시간이 시작 시간보다 앞섭니다.'; msgEl.style.display = ''; }
        return false;
    }
    endEl.style.borderColor = '';
    if (msgEl) msgEl.style.display = 'none';
    return true;
}
document.getElementById('f-end').addEventListener('change', checkEndTime);

// 시작 시간 변경 시 종료 = 시작 + 1시간 자동 세팅
document.getElementById('f-start').addEventListener('change', function() {
    const sv = this.value;
    if (!sv) return;
    const endEl = document.getElementById('f-end');
    // 종일 체크 시에는 날짜만 처리
    if (document.getElementById('f-allday').checked) {
        endEl.value = sv; // 종일이면 같은 날짜
        return;
    }
    const d = new Date(sv.replace('T', ' '));
    if (isNaN(d.getTime())) return;
    d.setHours(d.getHours() + 1);
    endEl.value = ymdhm(d);
});
// 날짜 변경 시 기간에 맞는 프로젝트 목록 갱신
document.getElementById('f-start').addEventListener('change', applyProjectByDate);
document.getElementById('f-due-dt').addEventListener('change', applyProjectByDate);
document.querySelectorAll('.r-day').forEach(cb=>cb.addEventListener('change', updateRPreview));

// ━━━ 이벤트 타입 ━━━
let ETYPE = 'timed';

const TYPE_ICON = {timed:'', allday:'📅', anniversary:'★', todo:'☑'};

function setEventType(type) {
    ETYPE = type;
    document.querySelectorAll('.type-tab').forEach(t => t.classList.toggle('active', t.dataset.type===type));
    // 날짜 행
    document.getElementById('row-timed').style.display       = type==='timed'       ? 'flex'  : 'none';
    document.getElementById('row-recur').style.display       = type==='timed'       ? 'flex'  : 'none';
    document.getElementById('row-anniv-class').style.display = type==='anniversary' ? 'block' : 'none';
    document.getElementById('row-anniv-cat').style.display   = type==='anniversary' ? 'block' : 'none';
    document.getElementById('row-anniversary').style.display = type==='anniversary' ? 'block' : 'none';
    document.getElementById('row-todo').style.display        = type==='todo'        ? 'block' : 'none';
    document.getElementById('row-todo-recur').style.display  = type==='todo'        ? 'flex'  : 'none';
    // 우선순위·카테고리 select: 기념일 숨김
    document.getElementById('row-priority').style.display    = type==='anniversary' ? 'none'  : '';
    document.getElementById('f-cat').closest('label').style.display = type==='anniversary' ? 'none' : '';
    document.getElementById('recur-summary-row').style.display = 'none';

    // 기념일: 분류·카테고리 버튼 초기화
    if (type === 'anniversary') {
        initAnnivCatBtns(document.getElementById('h-anniv-cat').value || '생일');
        renderEmojiPicker(document.getElementById('h-anniv-cat').value || '생일', document.getElementById('f-icon').value);
    }
}

// ━━━ 기념일 분류·카테고리 버튼 ━━━
const ANNIV_CAT_DEF = [
    {cat:'생일', icon:'🎂'}, {cat:'제사', icon:'🕯'},
    {cat:'기념일', icon:'🎉'}, {cat:'기타', icon:'⭐'},
];

function initAnnivCatBtns(selected) {
    selected = selected || '생일';
    document.getElementById('anniv-cat-btns').innerHTML = ANNIV_CAT_DEF.map(c =>
        `<button type="button" class="anniv-cat-btn${c.cat===selected?' active':''}"
            data-cat="${c.cat}" onclick="selectAnnivCat('${c.cat}')">${c.icon} ${c.cat}</button>`
    ).join('');
    document.getElementById('h-anniv-cat').value = selected;
}

function selectAnnivCat(cat) {
    document.getElementById('h-anniv-cat').value = cat;
    document.querySelectorAll('.anniv-cat-btn').forEach(b => b.classList.toggle('active', b.dataset.cat === cat));
    renderEmojiPicker(cat, document.getElementById('f-icon').value);
}

function setAnnivClass(val) {
    document.getElementById('h-is-family').value = val;
    document.querySelectorAll('.anniv-class-btn').forEach(b => b.classList.toggle('active', parseInt(b.dataset.cls) === val));
}

// ━━━ 반복 일정 ━━━
let RECUR = null; // 현재 반복 규칙

// ━━━ 참석자 (주소록 연동) ━━━
let ALL_CONTACTS = [];        // 전체 주소록
let SEL_ATTENDEES = [];       // 선택된 참석자 [{id,name}]

async function loadContactsForAttendee() {
    try {
        const r = await fetch('/schedule_api.php?module=contacts&action=list');
        const res = await r.json();
        ALL_CONTACTS = res.data || [];
        document.getElementById('attendee-datalist').innerHTML =
            ALL_CONTACTS.map(c=>`<option value="${c.name}">${c.organization||''}</option>`).join('');
    } catch(e){ ALL_CONTACTS=[]; }
}

function renderAttendeeChips() {
    const el = document.getElementById('attendee-chips');
    el.innerHTML = SEL_ATTENDEES.map(a=>`
        <span style="display:inline-flex;align-items:center;gap:4px;background:#eaf4ff;color:#2980b9;border-radius:12px;padding:3px 8px;font-size:12px;font-weight:600;">
            ${a.name}
            <span style="cursor:pointer;color:#e74c3c;" onclick="removeAttendee(${a.id})">×</span>
        </span>`).join('');
}
function removeAttendee(id) {
    SEL_ATTENDEES = SEL_ATTENDEES.filter(a=>a.id!=id);
    renderAttendeeChips();
}
function addAttendeeByName(name) {
    name = name.trim();
    if (!name) return;
    const c = ALL_CONTACTS.find(x=>x.name===name);
    if (!c) { alert('주소록에 없는 이름입니다. 주소록에서 먼저 등록하세요.'); return; }
    if (SEL_ATTENDEES.some(a=>a.id==c.id)) return; // 중복
    SEL_ATTENDEES.push({id:c.id, name:c.name});
    renderAttendeeChips();
    document.getElementById('f-attendee-input').value='';
}
const RECUR_LABEL = {daily:'매일', weekly:'매주', monthly:'매월', yearly:'매년'};

function selectRepeatType(type) {
    const sv = document.getElementById('f-start').value ||
               document.getElementById('f-due-dt').value;
    const startDt = sv ? new Date(sv.includes('T') ? sv : sv+'T00:00') : new Date();

    // 기본 규칙 생성 (interval=1, 종료없음)
    const rule = { type, interval: 1, end_type: 'none' };
    if (type === 'weekly')  rule.days = [startDt.getDay()];
    if (type === 'monthly') rule.monthly_type = 'day';
    if (type === 'yearly')  rule.yearly_type  = 'day';

    RECUR = rule;

    // hidden 필드 동기화
    document.getElementById('h-recur-type').value     = type;
    document.getElementById('h-recur-interval').value = '1';
    document.getElementById('h-recur-end-type').value = 'none';

    // 버튼 활성화
    document.querySelectorAll('.recur-type-btn').forEach(b => {
        b.classList.toggle('active', b.dataset.rtype === type);
    });

    // 모달 제목 업데이트
    document.getElementById('modal-title').textContent =
        (S.editId ? '수정' : '추가') + ` — 반복일정 (${RECUR_LABEL[type]})`;

    // 요약 표시
    const summary = recurSummaryLine(rule, startDt);
    document.getElementById('recur-summary-text').textContent = summary;
    document.getElementById('recur-summary-row').style.display = '';
    document.getElementById('btn-recur-clear').style.display = '';
    const ct = document.getElementById('btn-recur-clear-todo');
    if (ct) ct.style.display = '';
}

const DAY_KR = ['일','월','화','수','목','금','토'];

function fmt12(timeStr) {
    if (!timeStr) return '';
    const [h,m] = timeStr.split(':').map(Number);
    return (h<12?'오전':'오후')+' '+(h===0?12:h>12?h-12:h)+':'+String(m).padStart(2,'0');
}

function getNth(d) { return Math.ceil(d/7); }

// 반복 기준 일시: 시작일시(timed) 없으면 마감일(todo)로 폴백
function recurBaseVal() {
    let v = document.getElementById('f-start').value;
    if (!v) {
        const dv = document.getElementById('f-due-dt').value;
        if (dv) v = dv.includes('T') ? dv : dv + 'T00:00';
    }
    return v;
}

function recurSummaryLine(rule, startDt) {
    if (!rule) return '';
    const iv = rule.interval||1;
    const d = startDt.getDate(), m = startDt.getMonth()+1, dow = startDt.getDay();
    const nth = getNth(d);
    switch(rule.type) {
        case 'daily':   return iv===1?'매일':`매 ${iv}일`;
        case 'weekly': {
            const ds=(rule.days||[dow]).map(x=>DAY_KR[x]+'요일').join(', ');
            return iv===1?`매주 ${ds}`:`${iv}주마다 ${ds}`;
        }
        case 'monthly': {
            const mt=rule.monthly_type||'day';
            const s=mt==='day'?`${d}일`:mt==='lastday'?'말일':`${nth}번째 ${DAY_KR[dow]}요일`;
            return iv===1?`매월 ${s}`:`${iv}개월마다 ${s}`;
        }
        case 'yearly': {
            const yt=rule.yearly_type||'day';
            const s=yt==='day'?`${m}월 ${d}일`:`${m}월 ${nth}번째 ${DAY_KR[dow]}요일`;
            return iv===1?`매년 ${s}`:`${iv}년마다 ${s}`;
        }
    }
    return '';
}

function openRecurModal(preType=null) {
    // 기준 일시: 시작일시 우선, 없으면 마감일(할일). 둘 다 없으면 현재 시각
    let sv = recurBaseVal();
    if (!sv) {
        const now = new Date();
        sv = ymdhm(now);
        document.getElementById('f-start').value = sv;
        const endD = new Date(now); endD.setHours(endD.getHours()+1);
        document.getElementById('f-end').value = ymdhm(endD);
    }
    const ev = document.getElementById('f-end').value;
    const startDt = new Date(sv.includes('T')?sv:sv+'T00:00');

    // 시간 표시
    const st = sv.includes('T')?sv.split('T')[1].slice(0,5):'';
    const et = ev&&ev.includes('T')?ev.split('T')[1].slice(0,5):'';
    let timeDisp = st?fmt12(st):'';
    if (et) {
        const ms=(new Date(ev))-(new Date(sv));
        const dh=Math.floor(ms/3600000), dm=Math.floor((ms%3600000)/60000);
        const dur=dh>0?(dm>0?`${dh}시간 ${dm}분`:`${dh}시간`):`${dm}분`;
        timeDisp+=` - 당일 ${fmt12(et)} (${dur})`;
    }
    document.getElementById('r-time-disp').textContent = timeDisp || '시간 미설정';

    // 시작 날짜 표시
    document.getElementById('r-start-lbl').textContent = ymd(startDt).replace(/-/g,'.');

    // 타입 선택: 버튼 클릭으로 넘어온 경우 우선, 그 다음 기존 규칙, 기본값 daily
    const resolvedType = preType || (RECUR && RECUR.type) || 'daily';
    document.getElementById('r-type').value = resolvedType;

    // 반복 모달 제목 = 선택된 타입
    const rLabel = {daily:'반복 — 매일', weekly:'반복 — 매주', monthly:'반복 — 매월', yearly:'반복 — 매년'};
    document.getElementById('recur-modal-title').textContent = rLabel[resolvedType] || '반복';

    // 기존 규칙 복원
    if (RECUR) {
        document.getElementById('r-type').value = resolvedType;
        document.getElementById('r-interval').value = RECUR.interval||1;
        const et2 = RECUR.end_type||'none';
        document.querySelector(`input[name="rend"][value="${et2}"]`).checked = true;
        if (RECUR.end_date) document.getElementById('r-end-date').value = RECUR.end_date;
        if (RECUR.end_count) document.getElementById('r-end-count').value = RECUR.end_count;
        if (RECUR.type==='weekly' && RECUR.days) {
            document.querySelectorAll('.r-day').forEach(cb=>{
                cb.checked=RECUR.days.includes(parseInt(cb.value));
            });
        }
        if (RECUR.type==='monthly') {
            const el=document.querySelector(`input[name="rmt"][value="${RECUR.monthly_type||'day'}"]`);
            if(el) el.checked=true;
        }
        if (RECUR.type==='yearly') {
            const el=document.querySelector(`input[name="ryt"][value="${RECUR.yearly_type||'day'}"]`);
            if(el) el.checked=true;
        }
        // 영업일 조정 복원
        if (RECUR.type==='monthly'||RECUR.type==='yearly') {
            const bizEl=document.querySelector(`input[name="r-biz"][value="${RECUR.biz_day||'none'}"]`);
            if(bizEl) bizEl.checked=true;
        }
    } else {
        // r-type은 이미 resolvedType으로 설정됨 — 덮어쓰지 않음
        document.getElementById('r-interval').value = 1;
        document.querySelector('input[name="rend"][value="none"]').checked = true;
        // 매주: 현재 시작요일 기본 체크
        document.querySelectorAll('.r-day').forEach(cb => {
            cb.checked = parseInt(cb.value) === startDt.getDay();
        });
        // 매월/매년: 첫번째 라디오(날짜) 기본 선택
        const rmtFirst = document.querySelector('input[name="rmt"][value="day"]');
        if (rmtFirst) rmtFirst.checked = true;
        const rytFirst = document.querySelector('input[name="ryt"][value="day"]');
        if (rytFirst) rytFirst.checked = true;
    }

    onRTypeChange(resolvedType);
    onREndChange();
    document.getElementById('recur-overlay').classList.add('open');
}

function closeRecurModal() {
    document.getElementById('recur-overlay').classList.remove('open');
}

function onRTypeChange(forceType=null) {
    const type = forceType || document.getElementById('r-type').value;
    // hidden select에도 동기화
    document.getElementById('r-type').value = type;

    const units = {daily:'일', weekly:'주', monthly:'개월', yearly:'년'};
    document.getElementById('r-unit').textContent = units[type] || '일';

    document.getElementById('r-days-row').style.display    = type==='weekly'  ? 'flex'  : 'none';
    document.getElementById('r-monthly-row').style.display = type==='monthly' ? 'flex'  : 'none';
    document.getElementById('r-yearly-row').style.display  = type==='yearly'  ? 'flex'  : 'none';
    // 영업일 조정: 매월/매년만 표시
    document.getElementById('r-biz-row').style.display     = (type==='monthly'||type==='yearly') ? '' : 'none';

    const sv = recurBaseVal();
    if (sv) {
        const dt  = new Date(sv.includes('T') ? sv : sv+'T00:00');
        const d   = dt.getDate(), m = dt.getMonth()+1, dow = dt.getDay(), nth = getNth(d);
        document.getElementById('r-m-day-lbl').textContent = `${d}일`;
        document.getElementById('r-m-wd-lbl').textContent  = `${nth}번째 ${DAY_KR[dow]}요일`;
        document.getElementById('r-y-day-lbl').textContent = `${m}월 ${d}일`;
        document.getElementById('r-y-wd-lbl').textContent  = `${m}월 ${nth}번째 ${DAY_KR[dow]}요일`;
    }
    updateRPreview();
}

function onREndChange() {
    const et=document.querySelector('input[name="rend"]:checked')?.value||'none';
    document.getElementById('r-end-date').style.display       =et==='date' ?'':'none';
    document.getElementById('r-end-count-wrap').style.display =et==='count'?'flex':'none';
    updateRPreview();
}

function updateRPreview() {
    const type = document.getElementById('r-type').value || 'daily';
    const iv=parseInt(document.getElementById('r-interval').value)||1;
    const sv=recurBaseVal();
    const ev=document.getElementById('f-end').value;
    if (!sv) return;

    const startDt=new Date(sv.includes('T')?sv:sv+'T00:00');
    const d=startDt.getDate(), m=startDt.getMonth()+1, dow=startDt.getDay(), nth=getNth(d);

    // 반복 설명
    let freqText='';
    if (type==='daily') freqText=iv===1?'매일':`매 ${iv}일`;
    else if (type==='weekly') {
        const checked=[...document.querySelectorAll('.r-day:checked')].map(cb=>parseInt(cb.value));
        const ds=checked.map(x=>DAY_KR[x]+'요일').join(', ')||DAY_KR[dow]+'요일';
        freqText=iv===1?`매주 ${ds}`:`${iv}주마다 ${ds}`;
    } else if (type==='monthly') {
        const mt=document.querySelector('input[name="rmt"]:checked')?.value||'day';
        const s=mt==='day'?`${d}일`:mt==='lastday'?'말일':`${nth}번째 ${DAY_KR[dow]}요일`;
        freqText=iv===1?`매월 ${s}`:`${iv}개월마다 ${s}`;
    } else if (type==='yearly') {
        const yt=document.querySelector('input[name="ryt"]:checked')?.value||'day';
        const s=yt==='day'?`${m}월 ${d}일`:`${m}월 ${nth}번째 ${DAY_KR[dow]}요일`;
        freqText=iv===1?`매년 ${s}`:`${iv}년마다 ${s}`;
    }

    // 시간
    const st=sv.includes('T')?sv.split('T')[1].slice(0,5):'';
    const et2=ev&&ev.includes('T')?ev.split('T')[1].slice(0,5):'';
    let timeText=st?fmt12(st):'';
    if (et2) {
        const ms=(new Date(ev))-(new Date(sv));
        const dh=Math.floor(ms/3600000), dm2=Math.floor((ms%3600000)/60000);
        const dur=dh>0?(dm2>0?`${dh}시간 ${dm2}분`:`${dh}시간`):`${dm2}분`;
        timeText+=` - ${fmt12(et2)} (${dur})`;
    }

    // 종료
    const endType=document.querySelector('input[name="rend"]:checked')?.value||'none';
    const startLbl=ymd(startDt).replace(/-/g,'.');
    let endText='무한반복';
    if (endType==='date') {
        const ed=document.getElementById('r-end-date').value;
        endText=ed?ed.replace(/-/g,'.')+'까지':'무한반복';
    } else if (endType==='count') {
        endText=(document.getElementById('r-end-count').value||'?')+'번 반복';
    }

    // 영업일 조정 텍스트
    if (type==='monthly'||type==='yearly') {
        const biz=document.querySelector('input[name="r-biz"]:checked')?.value||'none';
        if (biz==='prev') freqText+=' · 공휴일→이전영업일(금)';
        else if (biz==='next') freqText+=' · 공휴일→다음영업일(월)';
    }

    let preview=freqText+(timeText?` | ${timeText}`:'');
    preview+=`\n${startLbl} - ${endText}`;
    document.getElementById('r-preview').textContent=preview;
}

function applyRecur() {
    const type=document.getElementById('r-type').value;
    const iv=parseInt(document.getElementById('r-interval').value)||1;
    const sv=recurBaseVal();
    if (!sv) { alert('시작일 또는 마감일을 입력하세요.'); return; }
    const startDt=new Date(sv.includes('T')?sv:sv+'T00:00');

    const rule={type, interval:iv, end_type:document.querySelector('input[name="rend"]:checked')?.value||'none'};

    if (type==='weekly') {
        rule.days=[...document.querySelectorAll('.r-day:checked')].map(cb=>parseInt(cb.value));
        if (!rule.days.length){alert('요일을 하나 이상 선택하세요.');return;}
    }
    if (type==='monthly') {
        rule.monthly_type=document.querySelector('input[name="rmt"]:checked')?.value||'day';
        if (rule.monthly_type==='weekday') rule.nth=getNth(startDt.getDate());
    }
    if (type==='yearly') {
        rule.yearly_type=document.querySelector('input[name="ryt"]:checked')?.value||'day';
        if (rule.yearly_type==='weekday') rule.nth=getNth(startDt.getDate());
    }
    if (rule.end_type==='date')  rule.end_date  = document.getElementById('r-end-date').value;
    if (rule.end_type==='count') rule.end_count = parseInt(document.getElementById('r-end-count').value)||1;
    // 영업일 조정
    if (type==='monthly'||type==='yearly') {
        rule.biz_day = document.querySelector('input[name="r-biz"]:checked')?.value || 'none';
    }

    RECUR=rule;
    const summary=recurSummaryLine(rule, startDt);
    // 반복 타입 버튼 활성화
    document.querySelectorAll('.recur-type-btn').forEach(b=>{
        b.classList.toggle('active', b.dataset.rtype===type);
    });
    document.getElementById('recur-summary-text').textContent=summary;
    document.getElementById('recur-summary-row').style.display='';
    document.getElementById('btn-recur-clear').style.display='';
    const ctTodo=document.getElementById('btn-recur-clear-todo');
    if(ctTodo) ctTodo.style.display='';
    closeRecurModal();
}

function clearRecur() {
    RECUR = null;
    document.getElementById('h-recur-type').value = '';
    document.querySelectorAll('.recur-type-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('recur-summary-text').textContent = '';
    document.getElementById('recur-summary-row').style.display = 'none';
    document.getElementById('btn-recur-clear').style.display = 'none';
    const ct = document.getElementById('btn-recur-clear-todo');
    if (ct) ct.style.display = 'none';
    // 모달 제목 원래대로
    document.getElementById('modal-title').textContent = S.editId ? '일정 수정' : '일정 추가';
}
document.getElementById('btn-prev').onclick   = ()=>navigate(-1);
document.getElementById('btn-next').onclick   = ()=>navigate(1);
document.getElementById('btn-today').onclick  = goToday;
document.getElementById('btn-new').onclick    = ()=>openNew(ymdhm(new Date()));
document.getElementById('btn-cancel').onclick = closeModal;
document.getElementById('btn-save').onclick   = saveEvent;
document.getElementById('btn-delete').onclick = deleteEvent;
document.querySelectorAll('.view-tabs button').forEach(b=>{b.onclick=()=>switchView(b.dataset.view);});
document.querySelectorAll('.color-swatch').forEach(el=>{el.onclick=()=>setColor(el.dataset.color);});
document.getElementById('modal-overlay').addEventListener('click',function(e){
    if(e.target===this && !document.body.classList.contains('is-mobile') && !document.body.classList.contains('w-narrow')) closeModal();
});
document.getElementById('view-overlay').addEventListener('click',function(e){if(e.target===this)closeViewModal();});
document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeModal();closeViewModal();}});

// ── 초기화: 저장된 위치/뷰 복원 후 렌더 ──
(function init(){
    loadContactsForAttendee();  // 참석자용 주소록 미리 로드
    loadProjPanel();         // 프로젝트 패널 로드
    restorePos();  // localStorage에서 위치/뷰 복원 (없으면 오늘 기준 그대로)
    // 저장된 뷰로 화면 전환 (탭 활성화 + display)
    ['month','week','day','list'].forEach(n=>{
        document.getElementById(`view-${n}`).style.display = n===S.view ? (n==='month'?'flex':'block') : 'none';
    });
    document.querySelectorAll('.view-tabs button').forEach(b=>b.classList.toggle('active', b.dataset.view===S.view));
    const mdd0=document.getElementById('m-day-detail'); if(mdd0) mdd0.style.display = (S.view==='month'?'':'none');
    requestAnimationFrame(() => loadEvents()); // 레이아웃 확정 후 렌더
})();

function showToast(msg, duration = 3000) {
    let t = document.getElementById('_toast');
    if (!t) {
        t = document.createElement('div');
        t.id = '_toast';
        t.style.cssText = 'position:fixed;bottom:30px;left:50%;transform:translateX(-50%);background:#2c3e50;color:#fff;padding:10px 20px;border-radius:8px;font-size:14px;z-index:99999;box-shadow:0 4px 12px rgba(0,0,0,.3);transition:opacity .3s;';
        document.body.appendChild(t);
    }
    t.textContent = msg;
    t.style.opacity = '1';
    clearTimeout(t._timer);
    t._timer = setTimeout(() => { t.style.opacity = '0'; }, duration);
}

// ==========================================================
// 프로젝트/그룹 사이드패널
// ==========================================================

// 패널 로드
async function loadProjPanel() {
    const res  = await fetch('schedule_api.php?module=projects&action=list');
    const json = await res.json();
    if (!json.ok) return;
    _projects = json.data;
    renderProjPanel();
    fillProjSelect();
    // 프로젝트 기간 막대 반영을 위해 캘린더가 이미 그려져 있으면 재렌더
    if (S.events && S.events.length !== undefined && S.view === 'month') render();
}

// 사이드패널 렌더
function renderProjPanel() {
    const list = document.getElementById('proj-list');
    if (!list) return;

    // ── 그룹 행 (단순 분류 라벨) ──
    // 그룹 카운트 = 직속(별도) 일정 + 포함 프로젝트 수
    const groupItem = g => {
        const childProj = _projects.filter(p => p.type === 'project' && p.parent_id == g.id).length;
        const cnt = (+g.item_count || 0) + childProj;
        return `<div class="proj-item" onclick="openProjDetail(event,${g.id})" oncontextmenu="openProjDetail(event,${g.id});return false;">
            <span class="proj-dot" style="background:${g.color||'#3498db'}"></span>
            <span class="proj-label">${esc((g.icon||'')+g.title)}</span>
            <span class="proj-count">${cnt}</span>
        </div>`;
    };

    // ── 프로젝트 카드 (이름·기간·진행률) ──
    const projCard = p => {
        const total = +p.item_count || 0, done = +p.done_count || 0;
        const pct = total ? Math.round(done / total * 100) : 0;
        const period = (p.start_dt || p.end_dt)
            ? `${(p.start_dt||'').slice(5)} ~ ${(p.end_dt||'').slice(5)}` : '기간 미설정';
        const dimmed = p.is_done ? ' style="opacity:.55;"' : '';
        return `<div class="proj-card"${dimmed} onclick="openProjDetail(event,${p.id})" oncontextmenu="openProjDetail(event,${p.id});return false;">
            <div class="proj-card-top">
                <span class="proj-dot" style="background:${p.color||'#3498db'}"></span>
                <span class="proj-card-name">${esc((p.icon||'')+p.title)}</span>
            </div>
            <div class="proj-card-period">📅 ${period}</div>
            <div class="proj-card-prog">
                <div class="proj-bar-track"><div class="proj-bar-fill" style="width:${pct}%;background:${p.color||'#3498db'}"></div></div>
                <span class="proj-card-pct">${pct}% (${done}/${total})</span>
            </div>
        </div>`;
    };

    const groups   = _projects.filter(p => p.type === 'group');
    // 프로젝트: 현재 보고 있는 달력 기간과 겹치는 것만 표시
    const [rs, re] = viewRange();
    const projects = _projects.filter(p =>
        p.type === 'project' && p.start_dt && p.end_dt && p.start_dt <= re && p.end_dt >= rs);

    let html = '';
    // 1) 상단: 프로젝트 리스트 (현재 기간 내)
    html += `<div class="proj-type-header">📌 프로젝트</div>`;
    html += projects.length ? projects.map(projCard).join('')
                            : '<div class="proj-empty">이 기간에 표시할 프로젝트 없음</div>';
    // 2) 하단: 그룹 리스트 (프로젝트 중첩 없음)
    html += `<div class="proj-type-header" style="margin-top:8px;">📁 그룹</div>`;
    html += groups.length ? groups.map(groupItem).join('')
                          : '<div class="proj-empty">＋ 로 그룹 추가</div>';

    list.innerHTML = html;
}

// 드롭다운 채우기
// 그룹 드롭다운 채우기 (항상 표시)
function fillGroupSelect() {
    const sel = document.getElementById('f-group-id');
    if (!sel) return;
    const cur = sel.value;
    const groups = _projects.filter(p => p.type === 'group' && !p.is_done);
    sel.innerHTML = '<option value="">없음</option>' +
        groups.map(g => `<option value="${g.id}">${g.icon||''}${g.title}</option>`).join('');
    sel.value = cur;
}

// 현재 일정 날짜(YYYY-MM-DD) 추출 (시작 또는 마감)
function curEventDate() {
    const sv = document.getElementById('f-start')?.value
            || document.getElementById('f-due-dt')?.value || '';
    return sv ? sv.slice(0, 10) : '';
}

// 프로젝트 칸 고정 표시 (특정 프로젝트로). projId 없으면 숨김
function setProjectField(projId) {
    const row = document.getElementById('row-project');
    const hid = document.getElementById('f-project-id');
    const p = projId ? _projects.find(x => x.id == projId && x.type === 'project') : null;
    if (!p) {
        hid.value = '';
        row.style.display = 'none';
        return;
    }
    hid.value = p.id;
    document.getElementById('f-project-dot').style.background = p.color || '#3498db';
    document.getElementById('f-project-name').textContent = (p.icon || '') + p.title;
    row.style.display = '';
}

// 일정 날짜가 포함된 프로젝트를 찾아 고정 표시 (없으면 숨김)
function applyProjectByDate() {
    const date = curEventDate();
    const hit = _projects.find(p => p.type === 'project' && !p.is_done
        && p.start_dt && p.end_dt && date && date >= p.start_dt && date <= p.end_dt);
    setProjectField(hit ? hit.id : null);
}

// 프로젝트 수동 해제
function clearProjectField() { setProjectField(null); }

// 그룹/프로젝트 칸 동기화 (그룹 드롭다운 + 날짜기반 프로젝트)
function fillProjSelect() {
    fillGroupSelect();
    applyProjectByDate();
}

// 그룹/프로젝트 클릭 → 상세 모달
function openProjDetail(e, id) {
    e.stopPropagation();
    document.getElementById('proj-detail-overlay')?.remove();

    const p = _projects.find(x => x.id == id);
    if (!p) return;

    const typeLabel = p.type === 'group' ? '그룹' : '프로젝트';
    const color = p.color || '#3498db';

    const overlay = document.createElement('div');
    overlay.id = 'proj-detail-overlay';

    let dateRow = '';
    if (p.type === 'project' && (p.start_dt || p.end_dt)) {
        const dday = projDday(p.end_dt);
        const ddayBadge = (dday && !p.is_done) ? ` <span class="pdp-dday">${dday}</span>` : '';
        dateRow = `<div class="pdp-row"><span class="pdp-row-label">기간</span><span>${p.start_dt||'?'} ~ ${p.end_dt||'?'}${ddayBadge}</span></div>`;
    }
    // 진행률 (프로젝트 전용 — 일정 로드 후 채움)
    const progRow = (p.type === 'project')
        ? `<div class="pdp-row"><span class="pdp-row-label">진행률</span><div style="flex:1;"><div id="pdp-progress">—</div></div></div>`
        : '';
    const hasMemo = p.memo && p.memo.trim();
    const memoRow = hasMemo ? `<div class="pdp-row" style="flex-direction:column;gap:6px;"><span class="pdp-row-label">메모</span><div class="pdp-memo-text" id="pdp-memo-content"></div></div>` : '';

    overlay.innerHTML = `
    <div class="pdp-modal">
        <div class="pdp-header">
            <div class="pdp-color-bar" style="background:${color}"></div>
            <div class="pdp-header-text">
                <div class="pdp-title" id="pdp-title-el"></div>
                <span class="pdp-badge">${typeLabel}</span>
            </div>
            <div style="display:flex;gap:6px;align-self:flex-start;padding-top:2px;">
                ${p.type === 'project' ? `<button class="btn btn-outline" id="pdp-btn-done" style="padding:4px 10px;font-size:12px;">${p.is_done?'되돌리기':'완료'}</button>` : ''}
                <button class="btn btn-outline" id="pdp-btn-edit" style="padding:4px 10px;font-size:12px;">✏ 수정</button>
                <button class="btn btn-danger"  id="pdp-btn-del"  style="padding:4px 10px;font-size:12px;">삭제</button>
            </div>
        </div>
        <div class="pdp-body">
            ${dateRow}
            ${progRow}
            ${memoRow}
            ${p.type === 'group' ? `
            <div class="pdp-items">
                <div class="pdp-items-title">📌 포함 프로젝트</div>
                <div id="pdp-projects-wrap"><span style="color:#aaa;font-size:12px;">불러오는 중...</span></div>
            </div>` : ''}
            <div class="pdp-items">
                <div class="pdp-items-title">${p.type === 'group' ? '📋 별도 일정' : '일정 목록'}</div>
                <div id="pdp-items-wrap"><span style="color:#aaa;font-size:12px;">불러오는 중...</span></div>
            </div>
        </div>
        <div class="pdp-footer" style="justify-content:center;">
            <button class="btn btn-outline" id="pdp-btn-close">닫기</button>
        </div>
    </div>`;

    document.body.appendChild(overlay);

    // 텍스트 안전 삽입
    document.getElementById('pdp-title-el').textContent = (p.icon || '') + p.title;
    if (hasMemo) document.getElementById('pdp-memo-content').textContent = p.memo;

    overlay.querySelector('#pdp-btn-close').onclick = () => overlay.remove();
    overlay.querySelector('#pdp-btn-edit').onclick  = () => { overlay.remove(); openProjModal(id); };
    overlay.querySelector('#pdp-btn-del').onclick   = () => { overlay.remove(); deleteProjConfirm(id); };
    overlay.querySelector('#pdp-btn-done')?.addEventListener('click', async () => {
        await fetch('schedule_api.php?module=projects&action=toggle_done', {
            method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ id })
        });
        overlay.remove();
        await loadProjPanel();
        loadEvents();
    });
    overlay.addEventListener('click', ev => { if (ev.target === overlay) overlay.remove(); });

    // 일정 목록 로드
    fetch(`schedule_api.php?module=projects&action=items&id=${id}`)
        .then(r => r.json())
        .then(json => {
            const wrap = document.getElementById('pdp-items-wrap');
            if (!wrap) return;
            const items = json.data || [];

            // 그룹: 포함 프로젝트 목록 렌더 (이름·기간·진행률)
            const projWrap = document.getElementById('pdp-projects-wrap');
            if (projWrap) {
                const projs = json.projects || [];
                if (!projs.length) {
                    projWrap.innerHTML = '<span style="color:#aaa;font-size:12px;">포함된 프로젝트가 없습니다.</span>';
                } else {
                    projWrap.innerHTML = projs.map(pr => {
                        const tot = +pr.item_count||0, dn = +pr.done_count||0;
                        const pct = tot ? Math.round(dn/tot*100) : 0;
                        const period = (pr.start_dt||pr.end_dt) ? `${(pr.start_dt||'').slice(5)}~${(pr.end_dt||'').slice(5)}` : '기간 미설정';
                        return `<div class="pdp-proj-row" onclick="openProjDetail(event,${pr.id})">
                            <span class="proj-dot" style="background:${pr.color||'#3498db'}"></span>
                            <span class="pdp-proj-name">${esc((pr.icon||'')+pr.title)}</span>
                            <span class="pdp-proj-meta">${period} · ${pct}% (${dn}/${tot})</span>
                        </div>`;
                    }).join('');
                }
            }

            // 진행률 채우기 (프로젝트 전용): 완료 일정 / 전체 일정
            const progEl = document.getElementById('pdp-progress');
            if (progEl) {
                const total = items.length;
                const doneN = items.filter(ev => ev.is_done == '1').length;
                const pct   = total ? Math.round(doneN / total * 100) : 0;
                progEl.innerHTML = total
                    ? `<div style="display:flex;align-items:center;gap:8px;">
                         <div style="flex:1;height:8px;background:#eee;border-radius:4px;overflow:hidden;">
                           <div style="width:${pct}%;height:100%;background:${color};"></div>
                         </div>
                         <span style="font-size:12px;color:#5a6a7a;white-space:nowrap;">${doneN}/${total} (${pct}%)</span>
                       </div>`
                    : '<span style="color:#aaa;font-size:12px;">일정 없음</span>';
            }

            if (!items.length) { wrap.innerHTML = '<span style="color:#aaa;font-size:12px;">일정이 없습니다.</span>'; return; }

            const typeMap  = { timed:'일반', allday:'종일', anniversary:'기념일', todo:'할일' };
            const recurMap = { daily:'매일', weekly:'매주', monthly:'매월', yearly:'매년' };
            const PAGE = 10;

            // 반복/개별 분리
            const recurItems  = items.filter(ev => ev.recur_rule && ev.recur_rule !== '');
            const singleItems = items.filter(ev => !ev.recur_rule || ev.recur_rule === '');

            // 반복 일정 섹션
            function makeRecurRows() {
                return recurItems.map(ev => {
                    let rule = {};
                    try { rule = JSON.parse(ev.recur_rule); } catch(e) {}
                    const cycle = recurMap[rule.type] || rule.type || '반복';
                    return `<tr>
                        <td><span class="ev-type-badge" style="background:#e8f5e9;color:#2e7d32;">🔁 ${cycle}</span></td>
                        <td>${esc(ev.title)}${pdpMapIcon(ev)}</td>
                        <td style="white-space:nowrap;color:#5a6a7a;">${esc(pdpAtt(ev))}</td>
                    </tr>`;
                }).join('');
            }

            // 개별 일정 섹션
            function makeSingleRows(list) {
                return list.map(ev => {
                    const dt = (ev.start_dt || ev.due_dt || '').slice(0,10);
                    const tl = typeMap[ev.event_type] || ev.event_type;
                    return `<tr>
                        <td style="white-space:nowrap">${dt}</td>
                        <td><span class="ev-type-badge">${tl}</span></td>
                        <td>${esc(ev.title)}${pdpMapIcon(ev)}</td>
                        <td style="white-space:nowrap;color:#5a6a7a;">${esc(pdpAtt(ev))}</td>
                    </tr>`;
                }).join('');
            }

            function render(showAll) {
                let html = '';

                // 반복 일정 섹션
                if (recurItems.length) {
                    html += `<div class="pdp-items-title" style="margin-top:0;">🔁 반복 일정</div>
                    <table style="margin-bottom:12px;">
                        <thead><tr><th>주기</th><th>내용</th><th>참석자</th></tr></thead>
                        <tbody>${makeRecurRows()}</tbody>
                    </table>`;
                }

                // 개별 일정 섹션
                if (singleItems.length) {
                    const visible = showAll ? singleItems : singleItems.slice(0, PAGE);
                    const more = !showAll && singleItems.length > PAGE;
                    html += `<div class="pdp-items-title">📋 개별 일정</div>
                    <table>
                        <thead><tr><th>일자</th><th>구분</th><th>내용</th><th>참석자</th></tr></thead>
                        <tbody>${makeSingleRows(visible)}</tbody>
                    </table>
                    ${more ? `<div style="text-align:center;margin-top:8px;">
                        <button class="btn btn-outline" id="pdp-load-more" style="font-size:11px;padding:4px 14px;">
                            전체 보기 (${singleItems.length}개)
                        </button></div>` : ''}`;
                }

                wrap.innerHTML = html;
                wrap.querySelector('#pdp-load-more')?.addEventListener('click', () => render(true));
                // 주소 아이콘 클릭 → 외부 지도 새 탭
                wrap.querySelectorAll('.chip-map-mark').forEach(el => el.onclick = e => {
                    e.stopPropagation();
                    const addr = decodeURIComponent(el.dataset.addr || '');
                    if (!addr) return;
                    const url = el.dataset.prov === 'google' ? googleMapUrl(addr) : naverMapUrl(addr);
                    window.open(url, '_blank', 'noopener');
                });
            }

            render(false);
        });
}

// 패널 숨김/보이기
function toggleProjPanel() {
    const panel = document.getElementById('proj-panel');
    const tab   = document.getElementById('proj-panel-tab');
    panel.classList.toggle('hidden');
    tab.textContent = panel.classList.contains('hidden') ? '▶' : '◀';
}

// ── 프로젝트 추가/수정 모달 ──────────────────────────────
// ＋ 클릭 → 그룹/프로젝트 추가 선택 메뉴 토글
function toggleProjAddMenu(e) {
    e.stopPropagation();
    const menu = document.getElementById('proj-add-menu');
    const open = menu.classList.toggle('open');
    if (open) {
        const close = ev => {
            if (!menu.contains(ev.target)) { menu.classList.remove('open'); document.removeEventListener('click', close); }
        };
        setTimeout(() => document.addEventListener('click', close), 0);
    }
}

// 방법 A: 진입 시 종류 고정 (그룹 또는 프로젝트). 수정 시엔 기존 type 유지
function openProjModal(id, presetType) {
    document.getElementById('proj-add-menu')?.classList.remove('open');
    const isEdit = !!id;
    const p = isEdit ? _projects.find(x => x.id == id) : null;
    const type = isEdit ? p.type : (presetType || 'group');
    const isProject = type === 'project';
    const typeLabel = isProject ? '프로젝트' : '그룹';

    // 프로젝트 전용: 상위 그룹 드롭다운 (그룹 목록만)
    let parentRow = '';
    if (isProject) {
        const groups = _projects.filter(x => x.type === 'group');
        const opts = ['<option value="">(상위 그룹 없음 · 단독)</option>']
            .concat(groups.map(g =>
                `<option value="${g.id}" ${(p&&p.parent_id==g.id)?'selected':''}>${esc((g.icon?g.icon+' ':'')+g.title)}</option>`
            )).join('');
        parentRow = `
        <div class="form-row">
            <label>상위 그룹 <select id="pm-parent">${opts}</select></label>
        </div>`;
    }

    const overlay = document.createElement('div');
    overlay.id = 'proj-modal-overlay';
    overlay.className = 'modal-overlay open';
    overlay.style.zIndex = 4000;
    overlay.innerHTML = `
    <div class="modal" style="width:420px;">
        <h3>${typeLabel} ${isEdit ? '수정' : '추가'}</h3>
        <input type="hidden" id="pm-type" value="${type}">
        <div class="form-row">
            <label>제목 *
                <input type="text" id="pm-title" value="${p?esc(p.title):''}" placeholder="${typeLabel}명 입력">
            </label>
            <label>이모지
                <input type="text" id="pm-icon" value="${p?p.icon||'':''}" placeholder="${isProject?'📌':'📁'}" style="width:60px;">
            </label>
        </div>
        ${parentRow}
        ${isProject ? `
        <div class="form-row">
            <label>시작일 <input type="date" id="pm-start" value="${p?p.start_dt||'':''}"></label>
            <label>종료일 <input type="date" id="pm-end"   value="${p?p.end_dt||'':''}"></label>
        </div>` : ''}
        <div class="form-row">
            <label>색상
                <div class="color-swatches" id="pm-swatches">
                    ${['#3498db','#2ecc71','#e74c3c','#f39c12','#9b59b6','#1abc9c','#e67e22','#95a5a6'].map(c=>
                        `<span class="color-swatch ${(p?p.color:'')==c?'selected':''}" data-color="${c}" style="background:${c}" onclick="pmPickColor(this)"></span>`
                    ).join('')}
                </div>
                <input type="hidden" id="pm-color" value="${p?p.color||'#3498db':'#3498db'}">
            </label>
        </div>
        <div class="form-row">
            <label>메모 <textarea id="pm-memo" style="height:60px;">${p?esc(p.memo||''):''}</textarea></label>
        </div>
        <div class="modal-footer">
            ${isEdit ? `<button class="btn btn-danger" onclick="deleteProjConfirm(${id})">삭제</button><span class="spacer"></span>` : ''}
            <button class="btn btn-outline" onclick="this.closest('.modal-overlay').remove()">취소</button>
            <button class="btn btn-primary" onclick="saveProjModal(${id||0})">저장</button>
        </div>
    </div>`;

    document.body.appendChild(overlay);
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
    setTimeout(() => overlay.querySelector('#pm-title').focus(), 50);
}

function pmPickColor(el) {
    document.querySelectorAll('#pm-swatches .color-swatch').forEach(s => s.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('pm-color').value = el.dataset.color;
}

async function saveProjModal(id) {
    const overlay = document.getElementById('proj-modal-overlay');
    const title = overlay.querySelector('#pm-title').value.trim();
    if (!title) { alert('제목을 입력하세요.'); return; }

    const data = {
        title:    title,
        type:     overlay.querySelector('#pm-type').value,
        icon:     overlay.querySelector('#pm-icon').value,
        color:    overlay.querySelector('#pm-color').value,
        memo:     overlay.querySelector('#pm-memo').value,
        start_dt: overlay.querySelector('#pm-start')?.value || null,
        end_dt:   overlay.querySelector('#pm-end')?.value   || null,
        parent_id: overlay.querySelector('#pm-parent')?.value || null,
    };
    if (id) data.id = id;

    const action = id ? 'update' : 'create';
    const res = await fetch(`schedule_api.php?module=projects&action=${action}`, {
        method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(data)
    });
    const json = await res.json();
    if (json.ok) {
        overlay.remove();
        await loadProjPanel();
        loadEvents();  // 색상 변경 등을 캘린더에 반영
        showToast(id ? '수정했습니다.' : '추가했습니다.');
    } else { alert(json.msg); }
}

async function deleteProjConfirm(id) {
    const p = _projects.find(x => x.id == id);
    const msg = (p?.type === 'project')
        ? `"${p?.title}" 프로젝트를 삭제하면 포함된 일정이 모두 삭제됩니다.\n계속할까요?`
        : `"${p?.title}" 그룹을 삭제하면 포함 프로젝트는 단독으로 풀리고, 직속 일정은 그룹만 해제됩니다.\n계속할까요?`;
    if (!confirm(msg)) return;
    const res  = await fetch(`schedule_api.php?module=projects&action=delete&id=${id}`);
    const json = await res.json();
    if (json.ok) {
        document.getElementById('proj-modal-overlay')?.remove();
        await loadProjPanel();
        loadEvents();  // 그룹 해제된 일정 색상 원복 반영
        showToast('삭제했습니다.');
    }
}

// ════════════════════════════════════════════════════════════
//  위치 / 지도 (하이브리드: 국내=네이버 · 해외=구글)
// ════════════════════════════════════════════════════════════

// ── 입력 폼: 국내/해외 토글 ──────────────────────────────────
function setLocRegion(region) {   // 'naver'(국내) | 'google'(해외)
    document.getElementById('f-provider').value = region;
    document.getElementById('loc-btn-naver').classList.toggle('active', region === 'naver');
    document.getElementById('loc-btn-google').classList.toggle('active', region === 'google');
    // 지역을 바꾸면 기존 좌표는 무효 (제공자-좌표 일치 보장)
    const lat = document.getElementById('f-lat').value;
    if (lat) {
        document.getElementById('f-lat').value = '';
        document.getElementById('f-lng').value = '';
        setLocStatus('지역이 변경됐습니다. "좌표 확인"을 다시 눌러주세요.', 'warn');
    } else {
        setLocStatus('', '');
    }
}

function setLocStatus(msg, kind) {
    const el = document.getElementById('loc-status');
    el.textContent = msg;
    el.className = kind || '';
}

// ── 주소 → 좌표 (서버 지오코딩 호출) ─────────────────────────
// 반환: true(좌표 확보) | false(실패)
async function doGeocode() {
    const address  = document.getElementById('f-address').value.trim();
    const provider = document.getElementById('f-provider').value || 'naver';
    if (!address) { setLocStatus('주소를 입력하세요.', 'err'); return false; }

    // 해당 제공자 지오코딩 키가 없으면 안내
    if (provider === 'naver'  && !MAP_CFG.naverGeoReady)  { setLocStatus('네이버 지오코딩 키가 설정되지 않았습니다. 주소만 저장됩니다.', 'warn'); return false; }
    if (provider === 'google' && !MAP_CFG.googleGeoReady) { setLocStatus('구글 지오코딩 키가 설정되지 않았습니다. 주소만 저장됩니다.', 'warn'); return false; }

    setLocStatus('좌표 변환 중…', '');
    const res = await api('geocode', { provider, address }, 'GET', 'geo');
    if (res && res.ok) {
        document.getElementById('f-lat').value = res.lat;
        document.getElementById('f-lng').value = res.lng;
        document.getElementById('f-provider').value = res.provider;  // 좌표를 찾은 제공자로 확정
        document.getElementById('f-address').dataset.geocoded = address;
        setLocStatus(`✔ 좌표 확인됨 (${(+res.lat).toFixed(5)}, ${(+res.lng).toFixed(5)})`, 'ok');
        return true;
    }
    document.getElementById('f-lat').value = '';
    document.getElementById('f-lng').value = '';
    setLocStatus('✕ ' + (res?.msg || '주소를 찾을 수 없습니다.'), 'err');
    return false;
}

// 저장 직전 좌표 보정: 주소가 있는데 좌표가 없거나 주소가 바뀌었으면 재변환
// 반환: true(계속 저장) | false(저장 중단)
async function ensureGeocodedForSave() {
    const addrEl  = document.getElementById('f-address');
    const address = addrEl.value.trim();
    if (!address) {  // 위치 미입력 → 좌표 클리어
        document.getElementById('f-lat').value = '';
        document.getElementById('f-lng').value = '';
        return true;
    }
    const hasLat   = !!document.getElementById('f-lat').value;
    const changed  = addrEl.dataset.geocoded !== address;
    if (!hasLat || changed) {
        const ok = await doGeocode();
        if (!ok && !document.getElementById('f-lat').value) {
            return confirm('주소 좌표를 찾지 못했습니다.\n주소만 저장하고 지도는 표시하지 않을까요?');
        }
    }
    return true;
}

// 입력 폼 위치 초기화
function resetLocationForm() {
    document.getElementById('f-address').value = '';
    document.getElementById('f-address').dataset.geocoded = '';
    document.getElementById('f-lat').value = '';
    document.getElementById('f-lng').value = '';
    setLocRegion('naver');
    setLocStatus('', '');
}

// 입력 폼 위치 복원 (수정 시)
function fillLocationForm(ev) {
    const provider = ev.provider === 'google' ? 'google' : 'naver';
    setLocRegion(provider);
    document.getElementById('f-address').value = ev.address || '';
    document.getElementById('f-address').dataset.geocoded = (ev.lat && ev.lng) ? (ev.address || '') : '';
    document.getElementById('f-lat').value = ev.lat || '';
    document.getElementById('f-lng').value = ev.lng || '';
    if (ev.address && ev.lat && ev.lng) setLocStatus('✔ 저장된 좌표', 'ok');
    else setLocStatus('', '');
}

// ── 외부 지도 링크 ───────────────────────────────────────────
function naverMapUrl(address, lat, lng) {
    // 네이버 지도 웹 검색 (좌표보다 주소 검색이 핀 표기 안정적)
    return 'https://map.naver.com/p/search/' + encodeURIComponent(address || (lat + ',' + lng));
}
function googleMapUrl(address, lat, lng) {
    const q = (lat && lng) ? (lat + ',' + lng) : address;
    return 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(q);
}

// ── SDK 지연 로드 ────────────────────────────────────────────
let _naverSDK = null, _googleSDK = null;
function loadNaverSDK() {
    if (_naverSDK) return _naverSDK;
    _naverSDK = new Promise((resolve, reject) => {
        if (window.naver && window.naver.maps) return resolve();
        if (!MAP_CFG.naverClientId) return reject('no-key');
        const s = document.createElement('script');
        s.src = 'https://oapi.map.naver.com/openapi/v3/maps.js?ncpKeyId=' + encodeURIComponent(MAP_CFG.naverClientId);
        s.onload = () => resolve();
        s.onerror = () => reject('load-fail');
        document.head.appendChild(s);
    });
    return _naverSDK;
}
function loadGoogleSDK() {
    if (_googleSDK) return _googleSDK;
    _googleSDK = new Promise((resolve, reject) => {
        if (window.google && window.google.maps) return resolve();
        if (!MAP_CFG.googleBrowserKey) return reject('no-key');
        const s = document.createElement('script');
        s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(MAP_CFG.googleBrowserKey);
        s.onload = () => resolve();
        s.onerror = () => reject('load-fail');
        document.head.appendChild(s);
    });
    return _googleSDK;
}

// ── 보기 모달: 지도 렌더 ─────────────────────────────────────
function renderViewLocation(ev) {
    const wrap = document.getElementById('view-location');
    const hasAddr = !!(ev.address && ev.address.trim());
    const hasCoord = !!(ev.lat && ev.lng);
    if (!hasAddr && !hasCoord) { wrap.style.display = 'none'; return; }

    wrap.style.display = '';
    document.getElementById('view-address').textContent = '📍 ' + (ev.address || `${ev.lat}, ${ev.lng}`);

    const provider = ev.provider === 'google' ? 'google' : 'naver';
    const mapEl    = document.getElementById('view-map');
    const fbEl     = document.getElementById('view-map-fallback');
    const linksEl  = document.getElementById('view-map-links');
    mapEl.innerHTML = '';
    fbEl.style.display = 'none';

    // "다른 지도로 보기" — 좌표를 찾은 제공자와 반대편 지도
    const naverLink  = `<a href="${naverMapUrl(ev.address, ev.lat, ev.lng)}"  target="_blank" rel="noopener">네이버 지도에서 열기 ↗</a>`;
    const googleLink = `<a href="${googleMapUrl(ev.address, ev.lat, ev.lng)}" target="_blank" rel="noopener">구글 지도에서 열기 ↗</a>`;
    linksEl.innerHTML = provider === 'naver' ? (naverLink + googleLink) : (googleLink + naverLink);

    if (!hasCoord) {  // 좌표 없음 → 주소+외부링크만
        mapEl.style.display = 'none';
        fbEl.style.display = '';
        fbEl.textContent = '좌표 정보가 없어 지도를 표시할 수 없습니다. 아래 링크로 외부 지도에서 확인하세요.';
        return;
    }
    mapEl.style.display = '';

    // 임베드 (provider 와 동일한 지도로 렌더해 핀 어긋남 방지)
    if (provider === 'naver') {
        loadNaverSDK()
            .then(() => {
                const pos = new naver.maps.LatLng(+ev.lat, +ev.lng);
                const map = new naver.maps.Map(mapEl, { center: pos, zoom: 16 });
                new naver.maps.Marker({ position: pos, map });
            })
            .catch(() => showMapFallback(mapEl, fbEl, '네이버'));
    } else {
        loadGoogleSDK()
            .then(() => {
                const pos = { lat: +ev.lat, lng: +ev.lng };
                const map = new google.maps.Map(mapEl, { center: pos, zoom: 16 });
                new google.maps.Marker({ position: pos, map });
            })
            .catch(() => showMapFallback(mapEl, fbEl, '구글'));
    }
}

function showMapFallback(mapEl, fbEl, name) {
    mapEl.style.display = 'none';
    fbEl.style.display = '';
    fbEl.textContent = `${name} 지도 키가 없거나 불러오지 못했습니다. 아래 링크로 외부 지도에서 확인하세요.`;
}

// 좁은 폭(태블릿/가로모드 등) 폴백: body.w-narrow 토글
(function(){
    const apply = () => {
        const now = window.innerWidth <= 820;
        const was = document.body.classList.contains('w-narrow');
        document.body.classList.toggle('w-narrow', now);
        // 폭 기준 모바일 여부가 바뀌면 월간뷰를 다시 그려 칩↔점 전환
        if (was!==now && S.view==='month' && typeof renderMonth==='function') renderMonth();
    };
    apply();
    window.addEventListener('resize', apply);
})();
</script>
</body>
</html>
<?php
} // end sch_calendar()





