<?php
require_once "./env/cnt.inc";
require_once "./env/auth_fnc.php";
require_once "./env/nav.inc";
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
    // 공통 네비게이션(env/nav.inc)으로 통합. 스케줄러는 항상 'schedule' 활성.
    render_nav('schedule');
}

// ##########################################################
// 공통 CSS (nav + 공유 스타일)
// ##########################################################
function sch_common_css(): void { ?>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { overflow-x: hidden; }
body { font-family: 'Pretendard', 'Malgun Gothic', sans-serif; background: #f0f2f5; color: #2c3e50; height: 100vh; display: flex; flex-direction: column; overflow: hidden; }
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
    (new Habit($pdo))->ensureTable();
    (new Goal($pdo))->ensureTable();   // tbl_project.created_by_goal 멱등 추가 포함
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>업무 스케줄러 — 이코노미스트</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php sch_common_css(); ?>
<?php nav_css(); ?>
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
.proj-item { display: flex; align-items: center; gap: 7px; min-width: 0; padding: 3px 10px; cursor: pointer; border-radius: 0; font-size: 12px; font-weight: 400; color: #2c3e50; transition: background .1s; white-space: nowrap; overflow: hidden; }
.proj-item:hover { background: #f0f4f8; }
.proj-item.active { background: #eaf4ff; font-weight: 700; }
.proj-item .proj-dot { width: 7px; height: 7px; border-radius: 50%; flex: none; }
.proj-item .proj-label { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.proj-item .proj-count { font-size: 11px; color: #aaa; flex: none; }
.proj-item-all { border-bottom: 1px solid #eee; margin-bottom: 4px; }
.proj-empty { font-size: var(--fs-xs); color: #bbb; padding: 10px; text-align: center; }
/* 프로젝트 카드 */
.proj-card { margin: 4px 8px; padding: 8px 9px; border: 1px solid #e8edf2; border-radius: 7px; cursor: pointer; transition: background .1s, border-color .1s; }
.proj-card:hover { background: #f6f9fc; border-color: #cfe0ef; }
.proj-card-top { display: flex; align-items: center; gap: 6px; }
.proj-card-top .proj-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
.proj-card-name { font-size: 12px; font-weight: 500; color: #2c3e50; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.proj-card-period { font-size: 10px; color: #8a97a3; margin: 4px 0 5px; }
.proj-card-prog { display: flex; align-items: center; gap: 5px; }
.proj-bar-track { flex: 1; height: 6px; background: #eef1f4; border-radius: 3px; overflow: hidden; }
.proj-bar-fill { height: 100%; border-radius: 3px; transition: width .2s; }
.proj-card-pct { font-size: 10px; color: #95a5a6; flex-shrink: 0; white-space: nowrap; }
.proj-card-dots { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 7px; }
.proj-dot-task { width: 11px; height: 11px; border-radius: 50%; background: #e74c3c; }
.proj-dot-task.done { background: #c4ccd4; }
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
.proj-type-header { font-size: 11px; color: #aaa; padding: 6px 10px 2px; font-weight: 700; letter-spacing: .5px; }
/* 목표·습관 사이드바 */
.tg-count { margin-left: auto; font-size: 11px; font-weight: 700; flex: none; cursor: default; }
.tg-count.tg-met  { color: #1d9e75; }
.tg-count.tg-miss { color: #e6920a; }
.proj-item .tg-count[onclick] { cursor: pointer; }
.th-row { gap: 7px; }
.th-check { font-size: 15px; line-height: 1; cursor: pointer; color: #b5c0cc; user-select: none; flex: none; }
.th-check.on { color: #1d9e75; }
.th-streak { font-size: 11px; color: #e6920a; white-space: nowrap; }
.th-done { color: #aab2bd; text-decoration: line-through; }
.proj-item .proj-label[onclick] { cursor: pointer; }
/* Todo List 통합 섹션 헤더 (분류·프로젝트와 구분선으로 분리) */
.td-list-head { display: flex; align-items: center; justify-content: space-between; margin-top: 12px; padding: 10px 10px 6px; border-top: 1px solid #eee; }
.td-list-title { display: flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 500; color: #2c3e50; }
.td-list-dash { font-size: 15px; color: #3498db; cursor: pointer; line-height: 1; padding: 2px 4px; }
.td-list-dash:hover { color: #2176ae; }
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
.cal-cell { border-right: 1px solid #e0e0e0; border-bottom: 1px solid #e0e0e0; padding: 4px; min-height: 80px; background: #fff; cursor: pointer; transition: background .1s; position: relative; }
/* 월 칸 습관 점 (과거 회고: 완료=옅게, 오늘 미완료=주황 빈점 대기, 과거 미완료=빨간 ✕ 실패) */
.hab-dots { position: absolute; bottom: 3px; left: 5px; display: flex; gap: 3px; align-items: center; pointer-events: none; z-index: 2; }
.hab-dot { width: 7px; height: 7px; border-radius: 50%; flex: none; box-sizing: border-box; }
.hab-dot.pending { background: #e74c3c; box-shadow: 0 0 0 1px rgba(231,76,60,.22); }
.hab-dot.done { background: #aab2c2; opacity: .42; }
.hab-x { color: #e74c3c; font-size: 9px; line-height: 7px; font-weight: 800; flex: none; }
.hab-more { font-size: 9px; line-height: 1; color: #9aa6b2; font-weight: 700; margin-left: 1px; }
body.is-mobile .hab-dot { width: 6px; height: 6px; }
body.is-mobile .hab-x { font-size: 8px; line-height: 6px; }
/* 일정 메모 이미지 첨부 */
/* 메모 행: 메모칸을 전체폭으로 늘리고 이미지 첨부를 그 아래로 배치 */
.form-row-memo label { flex-basis: 100%; }
.form-row-memo #f-attach-area { flex-basis: 100%; width: 100%; }
.form-row-memo textarea { height: 110px; }
#f-attach-area { margin-top: 8px; }
#f-attach-list { display: flex; flex-wrap: wrap; gap: 8px; }
#f-attach-list:not(:empty) { margin-bottom: 8px; }
.f-att-thumb { position: relative; width: 64px; height: 64px; border-radius: 6px; overflow: hidden; border: 1px solid #dde3ea; background: #f4f6f8; flex: none; }
.f-att-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.f-att-thumb.loading { display: flex; align-items: center; justify-content: center; font-size: 11px; color: #9aa6b2; }
.f-att-x { position: absolute; top: 2px; right: 2px; width: 18px; height: 18px; border-radius: 50%; border: none; background: rgba(0,0,0,.6); color: #fff; font-size: 12px; line-height: 18px; text-align: center; cursor: pointer; padding: 0; }
.f-attach-tools { display: flex; align-items: center; gap: 10px; }
.f-attach-btn { padding: 5px 10px; font-size: 12px; border: 1px solid #cfd6de; background: #fff; border-radius: 6px; cursor: pointer; color: #4a5a6a; }
.f-attach-btn:hover { background: #f4f6f8; }
.f-attach-hint { font-size: 11px; color: #9aa6b2; }
/* 보기 모달 첨부 썸네일 */
#view-attach { display: flex; flex-wrap: wrap; gap: 8px; }
.va-thumb { width: 72px; height: 72px; border-radius: 6px; overflow: hidden; border: 1px solid #dde3ea; cursor: pointer; flex: none; }
.va-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
/* 이미지 라이트박스 */
#img-lightbox { position: fixed; inset: 0; background: rgba(0,0,0,.85); z-index: 9999; display: none; overflow: hidden; }
#img-lightbox img { position: absolute; top: 0; left: 0; max-width: none; max-height: none; transform-origin: 0 0; border-radius: 6px; box-shadow: 0 6px 30px rgba(0,0,0,.5); cursor: zoom-in; user-select: none; -webkit-user-drag: none; }
#img-lightbox img.zoomed { cursor: grab; }
#img-lightbox img.panning { cursor: grabbing; }
#img-lightbox .lb-hint { position: fixed; left: 50%; bottom: 16px; transform: translateX(-50%); color: rgba(255,255,255,.7); font-size: 12px; background: rgba(0,0,0,.4); padding: 5px 12px; border-radius: 14px; pointer-events: none; }
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
/* ── 주간 뷰 (라이트 리디자인) ── */
#view-week { flex: 1; overflow: hidden; display: flex; flex-direction: column;
  --wk-gutter: 56px; --wk-rowH: 58px;
  --wk-line: #e9edf4; --wk-line-soft: #f1f4f9; --wk-grid: #eef1f7;
  --wk-accent: #3b68f5; --wk-accent-soft: #eef3ff; --wk-sun: #e8554e; --wk-sat: #3b82d6; }
.week-wrap { border: 1px solid var(--wk-line); border-radius: 16px; overflow: hidden; background: #fff; flex: 1; display: flex; flex-direction: column;
  box-shadow: 0 1px 2px rgba(22,30,55,.04), 0 18px 44px -22px rgba(22,30,55,.16); }

/* 요일 헤더 */
.week-head-row { display: grid; grid-template-columns: var(--wk-gutter) repeat(7,1fr); background: #fbfcfe; border-bottom: 1px solid var(--wk-line); flex-shrink: 0; }
.week-head-gut { border-right: 1px solid var(--wk-line); }
.week-head { text-align: center; padding: 8px 2px 9px; border-right: 1px solid var(--wk-line-soft); }
.week-head:last-child { border-right: none; }
.week-head .wkd { font-size: var(--fs-sm); font-weight: 700; color: #6b7488; letter-spacing: .3px; }
.week-head .wkn { font-size: var(--fs-lg); font-weight: 800; color: #3a4153; margin-top: 2px; line-height: 1.2; }
.week-head.sun .wkd, .week-head.sun .wkn { color: var(--wk-sun); }
.week-head.sat .wkd, .week-head.sat .wkn { color: var(--wk-sat); }
.week-head.today-col { background: var(--wk-accent-soft); }
.week-head.today-col .wkn { display: inline-grid; place-items: center; width: 26px; height: 26px; border-radius: 50%; background: var(--wk-accent); color: #fff; margin-top: 1px; }

/* 공휴일/기념일/할일/종일 레인 */
.week-allday-row { display: grid; grid-template-columns: var(--wk-gutter) repeat(7,1fr); border-bottom: 1px solid var(--wk-line-soft); flex-shrink: 0; }
.week-allday-label { display: flex; align-items: center; justify-content: center; font-size: var(--fs-xs); font-weight: 700; color: #9aa3b5; border-right: 1px solid var(--wk-line); background: #fbfcfe; }
.week-allday-cell { border-right: 1px solid var(--wk-line-soft); padding: 3px 4px; min-height: 28px; display: flex; flex-wrap: wrap; gap: 3px; align-content: flex-start; }
.week-allday-cell:last-child { border-right: none; }
.week-allday-cell.today-col { background: var(--wk-accent-soft); }
.wk-chip { border-radius: 7px !important; padding: 3px 9px !important; font-weight: 700 !important; }
.wk-chip.done { background: #eef1f5 !important; color: #9aa3b5 !important; border-left: 3px solid #ccd2dd !important; }

/* 프로젝트 레인 */
.proj-week-row .week-allday-label { font-weight: 700; color: #2c3e50; overflow: hidden; text-overflow: ellipsis; }
.proj-week-row .week-allday-cell { min-height: 20px; padding: 2px; }
.proj-week-cell.in-range { margin: 2px 0; align-items: center; }
.proj-week-name { font-size: var(--fs-xs); font-weight: 700; white-space: nowrap; overflow: visible; padding-left: 4px; }

/* 시간 그리드 — 일간 공용 .time-label 유지 */
.time-label { background: #f8f9fa; border-right: 1px solid #dde; border-bottom: 1px solid #ececec; text-align: right; padding: 0 6px; font-size: var(--fs-xs); color: #999; line-height: 40px; height: 40px; }
.wk-gridscroll { flex: 1; min-height: 0; overflow-y: auto; }
.wk-timegrid { display: grid; grid-template-columns: var(--wk-gutter) repeat(7,1fr); position: relative; }
.wk-times { border-right: 1px solid var(--wk-line); }
.wk-thour { height: var(--wk-rowH); position: relative; }
.wk-thour span { position: absolute; top: -7px; right: 8px; font-size: var(--fs-xs); font-weight: 600; color: #aab2c2; }
.wk-col { position: relative; border-right: 1px solid var(--wk-line-soft); cursor: pointer;
  background-image: linear-gradient(var(--wk-grid) 1px, transparent 1px); background-size: 100% var(--wk-rowH); }
.wk-col:last-child { border-right: none; }
.wk-col.today-col { background-color: var(--wk-accent-soft); }
.wk-tev { position: absolute; left: 4px; right: 4px; border-radius: 8px; padding: 5px 8px; cursor: pointer; overflow: hidden;
  box-shadow: 0 1px 2px rgba(22,30,55,.06); box-sizing: border-box; z-index: 2; }
.wk-tev .wk-tm { font-size: var(--fs-xs); font-weight: 700; opacity: .85; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.wk-tev .wk-tt { font-size: var(--fs-sm); margin-top: 1px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.wk-tev.done { background: #f4f6fa !important; color: #aab2c2 !important; border-left: 3px solid #d4d9e3 !important; box-shadow: none; }
/* 습관 타임라인 블록 (점선 + 체크 — 일반 일정과 구분) */
.wk-hev { position: absolute; left: 4px; right: 4px; border-radius: 8px; padding: 5px 8px; cursor: pointer; overflow: hidden;
  box-sizing: border-box; z-index: 3; background: #f0faf3; color: #2c7a4b; border: 1.5px dashed #57b97e; }
.wk-hev .wk-hm { font-size: var(--fs-xs); font-weight: 700; opacity: .8; white-space: nowrap; }
.wk-hev .wk-htt { font-size: var(--fs-sm); margin-top: 1px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.wk-hev .wk-hchk { cursor: pointer; margin-right: 3px; }
.wk-hev .wk-hbadge { font-size: 9.5px; font-weight: 800; background: #d6f0e0; color: #2c7a4b; border-radius: 7px; padding: 1px 6px; margin-left: 5px; }
.wk-hev.done { background: #eef1f5; color: #9aa6b2; border-color: #c4ccd6; }
.wk-hev.done .wk-hbadge { background: #dde3ea; color: #9aa6b2; }
.wk-now { position: absolute; left: 0; right: 0; height: 2px; background: var(--wk-sun); z-index: 4; pointer-events: none; }
.wk-now::before { content: ''; position: absolute; left: -3px; top: -3px; width: 8px; height: 8px; border-radius: 50%; background: var(--wk-sun); }
body.is-mobile #view-week { --wk-gutter: 44px; } body.is-mobile .week-head .wkn { font-size: 15px; }
/* 일간 */
/* ── 일간 뷰 (주간과 동일 라이트 톤) ── */
#view-day { flex: 1; overflow: hidden; display: flex; flex-direction: column;
  --wk-gutter: 56px; --wk-rowH: 58px;
  --wk-line: #e9edf4; --wk-line-soft: #f1f4f9; --wk-grid: #eef1f7;
  --wk-accent: #3b68f5; --wk-accent-soft: #eef3ff; --wk-sun: #e8554e; --wk-sat: #3b82d6; }
.day-wrap { border: 1px solid var(--wk-line); border-radius: 16px; overflow: hidden; background: #fff; flex: 1; display: flex; flex-direction: column;
  box-shadow: 0 1px 2px rgba(22,30,55,.04), 0 18px 44px -22px rgba(22,30,55,.16); }
.day-allday-row { border-bottom: 1px solid var(--wk-line-soft); display: flex; align-items: center; gap: 6px; padding: 5px 8px; min-height: 34px; flex-shrink: 0; }
.day-allday-label { font-size: var(--fs-xs); font-weight: 700; color: #9aa3b5; white-space: nowrap; min-width: var(--wk-gutter); width: var(--wk-gutter); text-align: center; padding: 0 4px; flex-shrink: 0; }
.wk-col.day-col { cursor: pointer; }
/* 목록 */
#view-list { flex: 1; overflow-y: auto; }
.list-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
.list-table th { background: #2c3e50; color: #fff; padding: 10px 12px; text-align: left; font-size: var(--fs-base); }
.list-table td { padding: 9px 12px; border-bottom: 1px solid #f0f0f0; font-size: var(--fs-base); vertical-align: middle; }
.list-table tr:hover td { background: #f8f9fa; }
.list-table tr.done td { opacity: .55; }
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
.color-custom { display: flex; align-items: center; justify-content: center; font-size: 12px; line-height: 1; background: #f6f8fa; border: 1px solid #cfd6dd; box-sizing: border-box; }
.color-edit { display: flex; align-items: center; justify-content: center; font-size: 12px; line-height: 1; background: #f6f8fa; border: 1px solid #cfd6dd; box-sizing: border-box; color: #7f8c8d; }
.color-edit.editing { background: #e67e22; border-color: #e67e22; color: #fff; }
.color-swatches.edit-mode .color-swatch:not(.color-edit):not(.color-custom) { box-shadow: 0 0 0 2px #fff, 0 0 0 3px #e67e22; }
.cc-cell { width: 100%; aspect-ratio: 1/1; border-radius: 2px; cursor: pointer; transition: transform .05s; }
.cc-cell:hover { transform: scale(1.25); outline: 2px solid #2c3e50; position: relative; z-index: 1; }
.modal-footer { display: flex; gap: 8px; margin-top: 18px; justify-content: flex-end; }
/* 목표·습관 입력 모달 */
.td-inp { width: 100%; box-sizing: border-box; padding: 9px 11px; border: 1px solid #dfe6ec; border-radius: 8px; background: #f7f9fb; color: #2c3e50; font-size: 13px; }
.td-lbl { color: #7f8c8d; font-size: 12px; margin: 14px 0 6px; }
.td-prev { margin-top: 9px; color: #3498db; font-weight: 600; font-size: 13px; }
.td-help { font-size: 11px; color: #aab2bd; margin-top: 7px; }
.td-seg { display: flex; gap: 6px; }
.td-seg button { flex: 1; padding: 8px 4px; border: 1px solid #dfe6ec; border-radius: 8px; background: #fff; color: #7f8c8d; font-size: 12px; cursor: pointer; }
.td-seg button.active { border-color: #3498db; background: #eaf3fb; color: #2176ae; font-weight: 600; }
.td-daychips { display: flex; gap: 5px; margin-top: 8px; }
.td-daychips button { width: 30px; height: 30px; border-radius: 50%; border: 1px solid #dfe6ec; background: #fff; color: #7f8c8d; font-size: 12px; cursor: pointer; }
.td-daychips button.on { background: #3498db; color: #fff; border-color: #3498db; }
.td-switch { position: relative; display: inline-block; width: 40px; height: 23px; }
.td-switch input { display: none; }
.td-switch span { position: absolute; inset: 0; border-radius: 12px; background: #cfd6dd; transition: .15s; cursor: pointer; }
.td-switch span::before { content: ''; position: absolute; top: 2px; left: 2px; width: 19px; height: 19px; border-radius: 50%; background: #fff; transition: .15s; }
.td-switch input:checked + span { background: #3498db; }
.td-switch input:checked + span::before { left: 19px; }
.td-alert { display: flex; align-items: center; gap: 8px; margin-top: 14px; color: #2c3e50; font-size: 13px; cursor: pointer; }
.td-reminders { display: flex; flex-wrap: wrap; gap: 6px; }
.td-reminders .td-rem { display: inline-flex; align-items: center; gap: 4px; }
.td-reminders .td-rem button { border: none; background: none; color: #e74c3c; cursor: pointer; font-size: 14px; line-height: 1; }
.td-addbtn { margin-top: 7px; background: none; border: 1px dashed #cfd6dd; border-radius: 8px; color: #3498db; font-size: 12px; padding: 6px 10px; cursor: pointer; }
.td-seg button[data-s].active { border-color: #3498db; background: #eaf3fb; color: #2176ae; font-weight: 600; }
.td-endbtn { flex: 1; padding: 9px 4px; border: 1px solid #dfe6ec; border-radius: 8px; background: #fff; color: #1d9e75; font-size: 12px; font-weight: 600; cursor: pointer; }
.td-endbtn.stop { color: #e74c3c; }
.td-endbtn:hover { background: #f7f9fb; }
/* 사이드바 습관: 윗줄 체크/이름/streak + (track_total) 아랫줄 진행바 */
.th-right { margin-left: auto; flex: none; display: inline-flex; align-items: center; gap: 6px; }
.th-wrap + .th-wrap { margin-top: 1px; }
.th-trackline { display: flex; align-items: center; gap: 6px; margin: 1px 10px 4px 23px; }  /* 체크 아이콘 폭만큼 들여쓰기 */
.th-bar { flex: 1; min-width: 0; height: 5px; background: #eef1f4; border-radius: 3px; overflow: hidden; }
.th-bar > div { height: 100%; background: #1d9e75; border-radius: 3px; }
.th-bar.near > div { background: #13865f; }   /* 90%↑ 강조 */
.th-bartxt { flex: none; font-size: 10px; color: #1d9e75; white-space: nowrap; }
.th-slot { font-size: 10px; color: #b5c0cc; padding: 5px 10px 2px; font-weight: 600; }
/* 📊 대시보드 */
.dash-wrap { max-width: 760px; margin: 0 auto; padding: 14px; display: flex; flex-direction: column; gap: 12px; }
.dash-tiles { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
.dash-tile { background: #f4f6f8; border-radius: 10px; padding: 14px 10px; text-align: center; }
.dash-tile-v { font-size: 22px; font-weight: 600; color: #2c3e50; }
.dash-tile-sub { font-size: 14px; color: #95a5a6; font-weight: 500; }
.dash-tile-l { font-size: 12px; color: #95a5a6; margin-top: 3px; }
/* 섹션 헤더(흰 카드 래퍼 없이 라벨만) */
.dash-sec-h { display: flex; align-items: center; gap: 6px; font-size: 14px; font-weight: 600; color: #7f8c8d; margin: 6px 0 2px; }
.dash-card { background: #fff; border: 1px solid #e6ebf0; border-radius: 14px; padding: 14px; }
.dash-card-h { font-size: 12px; color: #7f8c8d; font-weight: 700; margin-bottom: 12px; }
.dash-empty { color: #aab2bd; font-size: 13px; text-align: center; padding: 12px; }
.dash-heat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 16px; }
.dash-heat-head { display: flex; align-items: center; font-size: 12px; color: #2c3e50; margin-bottom: 7px; }
.dash-heat-streak { margin-left: auto; color: #e6920a; }
.hc-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 3px; }
.hc { aspect-ratio: 1/1; border-radius: 2px; background: #eceff3; }
.hc.out { background: transparent; }
.hc.done { background: #1d9e75; }
.hc.miss { background: #eceff3; }
.hc.future { border: 1px solid #e6ebf0; background: transparent; }
.hc.today { border: 1.5px solid #3498db; background: #eaf3fb; }
.dash-heat-pct { font-size: 11px; color: #aab2bd; margin-top: 6px; }
.dash-legend { display: flex; gap: 12px; margin-top: 12px; font-size: 11px; color: #aab2bd; }
.dash-legend span { display: inline-flex; align-items: center; gap: 4px; }
.dash-legend .lg { width: 9px; height: 9px; border-radius: 2px; display: inline-block; }
.dash-goal { padding: 8px 0; cursor: pointer; }
.dash-goal + .dash-goal { border-top: 1px solid #f0f2f5; }
.dash-goal-top { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #2c3e50; }
.dash-goal-note { font-size: 11px; color: #aab2bd; }
.dash-goal-cnt { margin-left: auto; font-weight: 700; font-size: 12px; }
.dash-bar { height: 6px; background: #eef1f4; border-radius: 3px; overflow: hidden; margin-top: 6px; }
.dash-bar > div { height: 100%; border-radius: 3px; }
.dash-heat-track { display: flex; align-items: center; gap: 7px; margin-top: 7px; }
.dash-heat-track .dash-bar { flex: 1; margin-top: 0; }
.dash-heat-cum { font-size: 11px; color: #8e7cc3; white-space: nowrap; }
/* 📊 대시보드 틴트 카드 (습관·목표) */
.dash-tcards { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 12px; }
.dash-gcards { display: flex; flex-direction: column; gap: 12px; }
.dash-tcard { border-radius: 12px; padding: 14px; }
.dash-tcard-head { display: flex; align-items: center; gap: 10px; margin-bottom: 13px; }
.dash-tcard-ico { width: 38px; height: 38px; border-radius: 8px; background: #fff; display: flex; align-items: center; justify-content: center; font-size: 20px; flex: none; }
.dash-tcard-meta { min-width: 0; flex: 1; }
.dash-tcard-badge { display: inline-block; font-size: 11px; font-weight: 500; background: #fff; padding: 1px 7px; border-radius: 8px; margin-bottom: 3px; }
.dash-tcard-name { font-size: 13px; font-weight: 500; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.dash-tcard-streak { font-size: 12px; font-weight: 500; align-self: flex-start; white-space: nowrap; }
.dash-tcard-row { display: flex; align-items: center; justify-content: space-between; font-size: 12px; margin-bottom: 8px; }
.dash-tcard-row .l { display: inline-flex; align-items: center; gap: 4px; opacity: .92; }
.dash-tcard-row b { font-weight: 500; }
/* 잔디(체크 그리드): 13px 고정 정사각형, gap 4, 라벨과 세로 분리 — 스펙 §4 */
.dash-tcard .hc-grid { display: flex; flex-wrap: wrap; gap: 4px; grid-template-columns: none; margin-bottom: 12px; }
.dash-tcard .hc { width: 13px; height: 13px; aspect-ratio: auto; border-radius: 3px; box-sizing: border-box; background: #fff; border: .5px solid rgba(0,0,0,.10); }
.dash-tcard .hc.out { background: rgba(255,255,255,.45); border-color: transparent; }
.dash-tcard .hc.miss { background: #fff; border: .5px solid rgba(0,0,0,.10); display: flex; align-items: center; justify-content: center; overflow: hidden; }
.dash-tcard .hc.miss::before { content: '✕'; color: #e74c3c; font-size: 14px; line-height: 1; font-weight: 800; }
.dash-tcard .hc.done { background: var(--dh); border: none; }
.dash-tcard .hc.future { background: #fff; border: .5px solid rgba(0,0,0,.06); }
.dash-tcard .hc.today { background: transparent; border: 1.5px solid var(--dfg); }
.dash-tcard-bar { height: 8px; background: #fff; border-radius: 99px; overflow: hidden; }
.dash-tcard-bar > div { height: 100%; border-radius: 99px; background: var(--dh); }
/* 목표 카드(가로형) */
.dash-tcard.goal { cursor: pointer; }
.dash-tcard.goal .dash-tcard-ico { width: 34px; height: 34px; font-size: 17px; }
.dash-gcard-head { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.dash-gcard-meta { flex: 1; min-width: 0; display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.dash-gcard-name { font-size: 13px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dash-gcard-note { font-size: 11px; font-weight: 400; opacity: .75; }
.dash-gcard-cnt { font-size: 13px; font-weight: 600; white-space: nowrap; }
.dash-end-row { display: flex; align-items: center; gap: 8px; padding: 7px 0; font-size: 13px; color: #2c3e50; }
.dash-end-row + .dash-end-row { border-top: 1px solid #f0f2f5; }
.dash-end-stopname { color: #aab2bd; }
.dash-end-meta { margin-left: 6px; font-size: 11px; color: #aab2bd; }
.dash-end-badge { margin-left: auto; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 10px; }
.dash-end-badge.done { color: #1d9e75; background: #eafaf3; }
.dash-end-badge.stop { color: #8a94a0; background: #eceff3; }
.dash-end-reopen { cursor: pointer; color: #3498db; font-size: 15px; padding: 0 2px; }
/* 대시보드 모달 헤더 */
.dash-modal-head { display: flex; align-items: center; gap: 12px; padding: 14px 18px; border-bottom: 1px solid #eee; flex-shrink: 0; }
.dash-modal-title { font-size: 16px; font-weight: 700; color: #2c3e50; }
.dash-modal-nav { display: flex; align-items: center; gap: 8px; margin-left: auto; }
.dash-modal-nav #dash-month-label { font-size: 14px; font-weight: 600; color: #2c3e50; min-width: 92px; text-align: center; }
.dash-modal-nav .btn { padding: 5px 10px; }
.dash-modal-x { background: none; border: none; cursor: pointer; font-size: 18px; color: #95a5a6; padding: 2px 6px; line-height: 1; }
.dash-modal-x:hover { color: #2c3e50; }
#dash-overlay .dash-wrap { padding: 0 8px; }
/* 습관 오늘 기록 모달 */
.hl-head { display: flex; align-items: flex-start; gap: 9px; margin-bottom: 14px; }
.hl-icon { font-size: 20px; line-height: 1.2; flex: none; }
.hl-name { font-size: 15px; font-weight: 500; color: #2c3e50; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.hl-date { font-size: 12px; color: #aab2bd; }
.hl-x { margin-left: auto; font-size: 16px; color: #aab2bd; cursor: pointer; flex: none; }
.hl-x:hover { color: #2c3e50; }
.hl-lbl { font-size: 12px; color: #7f8c8d; }
.hl-inputs { display: flex; gap: 10px; }
.hl-incol { flex: 1; min-width: 0; }
.hl-prevhint { margin-top: 8px; font-size: 12px; color: #7a8794; line-height: 1.5; }
.hl-prevhint b { color: #2c3e50; }
.hl-prevhint .hl-rewind { color: #e67e22; font-weight: 700; }
.hl-amt-row { display: flex; align-items: center; gap: 8px; margin-top: 6px; }
.hl-amt { flex: 1; min-width: 0; padding: 10px 12px; border: 1px solid #3498db; border-radius: 8px; background: #fff; color: #2c3e50; font-size: 15px; }
.hl-unit { font-size: 13px; color: #7f8c8d; }
.hl-daily { margin-top: 8px; font-size: 12px; }
.hl-daily .hl-ok   { color: #1d9e75; }
.hl-daily .hl-warn { color: #e6920a; }
.hl-track { margin-top: 16px; padding-top: 14px; border-top: 1px solid #eee; }
.hl-track-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 7px; }
.hl-track-lbl { font-size: 12px; color: #7f8c8d; }
.hl-track-streak { font-size: 12px; color: #e6920a; }
.hl-bar { height: 9px; background: #eef1f4; border-radius: 5px; overflow: hidden; position: relative; }
.hl-bar-base { position: absolute; left: 0; top: 0; height: 100%; background: #1d9e75; transition: width .12s; }
.hl-bar-add  { position: absolute; top: 0; height: 100%; background: #9fe1cb; transition: width .12s, left .12s; }
.hl-track-nums { display: flex; align-items: baseline; justify-content: space-between; margin-top: 8px; }
.hl-track-nums b { font-weight: 600; color: #2c3e50; font-size: 14px; }
.hl-muted { color: #aab2bd; font-size: 13px; }
.hl-pct { font-size: 12px; color: #1d9e75; font-weight: 600; }
.hl-remain { font-size: 11px; color: #aab2bd; margin-top: 3px; }
.hl-today { margin-bottom: 16px; }
.hl-today-h { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 6px; font-size: 13px; color: #7f8c8d; }
.hl-hero { display: flex; align-items: baseline; gap: 6px; margin-bottom: 10px; }
.hl-hero b { font-size: 34px; font-weight: 500; line-height: 1; color: #2c3e50; }
.hl-hero-sub { font-size: 18px; color: #aab2bd; }
.hl-hero-done { margin-left: auto; font-size: 13px; font-weight: 600; color: #1d9e75; }
.hl-bar-lg { height: 8px; }
.hl-head-streak { display: inline-flex; align-items: center; gap: 3px; font-size: 12px; font-weight: 500; padding: 3px 8px; border-radius: 20px; background: #faeeda; color: #8a5410; flex: none; }
.hl-seg { display: flex; gap: 4px; padding: 3px; background: #eef1f4; border-radius: 10px; margin-bottom: 12px; }
.hl-seg button { flex: 1; height: 32px; border: none; border-radius: 7px; font-size: 13px; font-weight: 500; color: #7f8c8d; background: transparent; cursor: pointer; }
.hl-seg button.active { background: #fff; color: #185fa5; box-shadow: 0 1px 2px rgba(0,0,0,.08); }
.hl-seg-label { font-size: 12px; color: #7f8c8d; margin-bottom: 6px; }
.hl-mode-hint { font-size: 12px; color: #aab2bd; margin-top: 7px; }
.hl-cum-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
.hl-cum-l { font-size: 13px; color: #7f8c8d; }
.hl-cum-l b { font-size: 15px; font-weight: 500; color: #2c3e50; }
.hl-cum-pct { font-size: 13px; font-weight: 500; color: #7f8c8d; }
.hl-cum-remain { font-size: 12px; color: #aab2bd; margin-top: 7px; }
.th-ref { flex: none; cursor: pointer; font-size: 13px; margin-right: 2px; }
.hl-ref { display: flex; align-items: center; justify-content: center; gap: 6px; width: 100%; margin-bottom: 14px; padding: 9px; border: 1px solid #d6e4f0; border-radius: 8px; background: #f3f8fd; color: #1f6fb2; font-size: 13px; font-weight: 500; cursor: pointer; }
.hl-ref:hover { background: #e7f1fb; }
/* 월 하단 '오늘 남은 습관' 고정 바 */
#month-habit-bar { flex: none; border-top: 1px solid #e6ebf0; background: #f7f9fb; padding: 8px 11px; }
.mhb-head { display: flex; align-items: center; gap: 6px; margin-bottom: 7px; font-size: 11px; color: #7f8c8d; }
.mhb-head-lbl { font-weight: 500; }
.mhb-cnt { color: #e6920a; font-weight: 600; }
.mhb-chips { display: flex; flex-wrap: wrap; gap: 6px; max-height: 92px; overflow-y: auto; }
.mhb-chip { display: flex; align-items: center; gap: 6px; background: #fff; border: 1px solid #e3e9ef; border-radius: 8px; padding: 5px 9px; cursor: pointer; transition: border-color .1s; }
.mhb-chip:hover { border-color: #cfe0ef; }
.mhb-box { font-size: 15px; color: #e6920a; line-height: 1; flex: none; }
.mhb-name { font-size: 12px; color: #2c3e50; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mhb-sub { font-size: 9px; color: #aab2bd; }
.mhb-done { font-size: 12px; color: #1d9e75; display: flex; align-items: center; gap: 5px; }
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
.chip-trip-mark { cursor: pointer; }
.chip-trip-mark:hover { filter: brightness(1.15); }
.chip-log-mark { font-size: .7em; opacity: .85; white-space: nowrap; }
/* ── 메모 체크리스트 (보기모달 #view-memo) ── */
.memo-chk-head { font-size: 12px; font-weight: 600; color: #888; margin-bottom: 4px; }
.memo-chk-head .memo-chk-count { color: #3498db; }
.memo-chk-item { display: flex; align-items: flex-start; gap: 8px; padding: 5px 4px; border-radius: 6px; cursor: pointer; line-height: 1.45; }
.memo-chk-item:hover { background: #eef1f5; }
.memo-chk-item input { margin: 2px 0 0; flex-shrink: 0; width: 16px; height: 16px; cursor: pointer; accent-color: #3498db; }
.memo-chk-item .memo-chk-txt { flex: 1; color: #444; word-break: break-word; }
.memo-chk-item.on .memo-chk-txt { text-decoration: line-through; color: #aab2bd; }
.memo-plain { padding: 3px 4px; color: #666; line-height: 1.5; white-space: pre-wrap; word-break: break-word; }
.pdp-dday { display:inline-block; background:#fdecea; color:#c0392b; font-size:11px; font-weight:700; padding:1px 8px; border-radius:10px; margin-left:6px; }
.proj-bar, .travel-bar { font-size: var(--fs-xs); line-height: 16px; height: 16px; color:#fff; padding: 0 4px; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: pointer; font-weight: 600; }
/* ── 모바일 일정 입력 모달 → 풀스크린 ── */
body.is-mobile #modal-overlay {
    align-items: stretch;
}
body.is-mobile #modal-overlay .modal {
    width: 100%;
    max-width: 100%;
    border-radius: 0;
    box-shadow: none;
    padding: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
body.is-mobile #modal-overlay .type-tabs {
    flex-shrink: 0;
    padding: 12px 44px 0 16px;
    margin: 0;
    border-bottom: 1px solid #eee;
}
body.is-mobile #modal-overlay #modal-form-body {
    flex: 1;
    overflow-y: auto;
    padding: 0 16px 12px;
    -webkit-overflow-scrolling: touch;
}
body.is-mobile #modal-overlay .modal-footer {
    flex-shrink: 0;
    padding: 12px 16px;
    border-top: 1px solid #eee;
    margin: 0;
}
/* ===================================================================
   일정/기념일/할일 입력 폼 리디자인 (디자인 톤: 토큰·칩·세그먼트·필드·카드)
   적용 범위: #modal-overlay 한정 (다른 페이지·모달 영향 없음)
=================================================================== */
#modal-overlay{
  --m-ink:#1f2433; --m-muted:#7b8294;
  --m-line:#e4e8f0; --m-line2:#d4dae6; --m-field:#f7f9fc;
  --m-acc:#3b68f5; --m-acc-soft:#eaf0ff; --m-acc-ink:#264bd6;
}
#modal-overlay .modal{
  position:relative;
  padding:0; border-radius:22px; color:var(--m-ink);
  box-shadow:0 1px 2px rgba(22,30,55,.05), 0 18px 40px -16px rgba(22,30,55,.22);
}
/* 헤더 우상단 × 닫기 버튼 */
#modal-overlay .modal-x{
  position:absolute; top:9px; right:11px; z-index:6;
  width:30px; height:30px; padding:0; line-height:1;
  border:none; background:transparent; color:var(--m-muted);
  font-size:17px; cursor:pointer; border-radius:9px;
}
#modal-overlay .modal-x:hover{ background:var(--m-field); color:var(--m-ink); }
/* 상단 탭 */
#modal-overlay .type-tabs{ padding:14px 44px 0 16px; gap:6px; border-bottom:1px solid var(--m-line); }
#modal-overlay .type-tab{
  flex:1; border:none; background:transparent; color:var(--m-muted);
  border-radius:12px 12px 0 0; padding:12px 10px 13px; font-size:14.5px; font-weight:600;
}
#modal-overlay .type-tab:hover{ background:transparent; color:var(--m-ink); border:none; }
#modal-overlay .type-tab.active{ background:var(--m-acc-soft); color:var(--m-acc); border:none; }
/* 본문·제목 */
#modal-overlay #modal-form-body{ padding:20px 22px 6px; }
#modal-overlay #modal-title{ font-size:20px; font-weight:700; letter-spacing:-.3px; margin:4px 0 18px; }
/* 라벨 */
#modal-overlay .form-row label{ color:#4a5160; }
/* 입력 필드 + 포커스 */
#modal-overlay input:not([type=checkbox]):not([type=radio]),
#modal-overlay select,
#modal-overlay textarea{
  border:1px solid var(--m-line) !important; background:var(--m-field) !important;
  border-radius:10px !important; color:var(--m-ink); transition:.15s;
}
#modal-overlay input:not([type=checkbox]):not([type=radio]):focus,
#modal-overlay select:focus,
#modal-overlay textarea:focus{
  border-color:var(--m-acc) !important; background:#fff !important;
  box-shadow:0 0 0 3px rgba(59,104,245,.14);
}
/* 칩: 반복(소프트) */
#modal-overlay .recur-type-btn{
  border:1px solid var(--m-line2); background:#fff; color:#5a6173;
  border-radius:11px; padding:9px 15px; font-size:13.5px; font-weight:600;
}
#modal-overlay .recur-type-btn:hover{ border-color:var(--m-acc); color:var(--m-acc); background:#fff; }
#modal-overlay .recur-type-btn.active{ background:var(--m-acc-soft); color:var(--m-acc-ink); border-color:transparent; }
/* 칩: 기념일 분류·카테고리(채움) */
#modal-overlay .anniv-class-btn, #modal-overlay .anniv-cat-btn{
  border:1px solid var(--m-line2); background:#fff; color:#5a6173;
  border-radius:11px; padding:9px 15px; font-size:13.5px; font-weight:600;
}
#modal-overlay .anniv-class-btn:hover, #modal-overlay .anniv-cat-btn:hover{ border-color:var(--m-acc); color:var(--m-acc); background:#fff; }
#modal-overlay .anniv-class-btn.active, #modal-overlay .anniv-cat-btn.active{
  background:var(--m-acc); border-color:var(--m-acc); color:#fff; box-shadow:0 2px 6px rgba(59,104,245,.20);
}
/* 세그먼트(국내/해외) */
#modal-overlay .loc-region-btn.active{ background:var(--m-acc); }
/* 색상 스와치 선택 링 */
#modal-overlay .color-swatch.selected{ border-color:transparent; box-shadow:0 0 0 2px #fff, 0 0 0 4px var(--m-acc); }
/* 알림: 체크박스 → 알약 칩 */
#modal-overlay .alarms > label{
  flex:1; min-width:0;
  display:flex !important; flex-direction:row; align-items:center; justify-content:center; gap:5px;
  border:1px solid var(--m-line2); background:#fff; border-radius:9px;
  padding:7px 4px !important; color:#5a6173; font-weight:600 !important;
  font-size:12px !important; white-space:nowrap;
}
#modal-overlay .alarms > label:has(input:checked){ border-color:var(--m-acc); color:var(--m-acc-ink); background:var(--m-acc-soft); }
#modal-overlay .alarms .f-alert{ accent-color:var(--m-acc); }
/* 푸터 + 버튼 */
#modal-overlay .modal-footer{ padding:16px 22px 20px; border-top:1px solid var(--m-line); margin-top:6px; }
#modal-overlay .modal-footer .btn{ border-radius:12px; padding:12px 24px; font-weight:700; }
#modal-overlay .modal-footer .btn-primary{ background:var(--m-acc); box-shadow:0 2px 6px rgba(59,104,245,.22); }
#modal-overlay .modal-footer .btn-primary:hover{ background:#2f59e0; }
#modal-overlay .modal-footer .btn-outline{ background:var(--m-field); border-color:var(--m-line); color:#5a6173; }
/* 보조 버튼(주소확인/보기 등) */
#modal-overlay .btn-outline{ border-color:var(--m-line2); color:#4a5160; }
#modal-overlay .btn-outline:hover{ border-color:var(--m-acc); color:var(--m-acc); background:#fff; }
/* ── 모바일 월간 캘린더 ──
   UA 기반 body.is-mobile(실기기) + 좁은 폭(max-width:820px) 양쪽에서 적용.
   두 셀렉터가 같은 규칙을 공유하도록 :is()로 묶음.
   ※ 헤더(네비게이션) 모바일 규칙은 env/nav.inc 의 nav_css() 로 통합됨 */
body.is-mobile #proj-panel,
body.is-mobile #proj-panel-tab { display: none !important; }
body.is-mobile #scheduler { padding: 8px; gap: 8px; }
body.is-mobile #sch-body { gap: 0; }
body.is-mobile .sch-toolbar { flex-wrap: wrap; gap: 6px; }
body.is-mobile .sch-toolbar h2 { font-size: 17px; min-width: 0; flex: 1; order: -1; width: 100%; }
body.is-mobile .spacer { display: none; }
body.is-mobile .view-tabs { flex: 1; }
body.is-mobile .cal-cell { min-height: 64px; padding: 2px; }
body.is-mobile .cal-cell .day-num { font-size: 13px; margin-bottom: 2px; }
/* 이벤트 칩 = 작은 점으로 표시 (제목은 탭하면 보기) */
body.is-mobile .event-chip {
    display: inline-block; width: 10px; height: 10px; padding: 0;
    border-radius: 50%; margin: 2px; font-size: 0; line-height: 0;
    vertical-align: middle; overflow: hidden;
}
body.is-mobile .event-chip.done { width: 10px; height: 10px; border: 1px solid #ccc; }
body.is-mobile .holiday-badge,
body.is-mobile .jeoegi-badge { font-size: 9px; }
body.is-mobile .more-link { font-size: 10px; }
body.is-mobile .proj-bar,
body.is-mobile .travel-bar { font-size: 9px; height: 13px; line-height: 13px; }
/* 선택된 날짜 강조 */
body.is-mobile .cal-cell.sel-day { box-shadow: inset 0 0 0 2px #3498db; }
/* 모바일: 달력은 컴팩트(내용만), 아래 상세 패널이 남은 공간 채움 */
body.is-mobile #view-month { flex: 0 0 auto !important; overflow: visible !important; }
body.is-mobile .cal-grid { overflow: visible !important; }
body.is-mobile .cal-cell { cursor: default; }
body.is-mobile #btn-new { display: none; }   /* 상단 추가버튼 숨김 → FAB 사용 */
body.is-mobile #btn-voice { display: none; }  /* 모바일은 플로팅 🎤 사용 */
/* 하단 상세 패널 (기본 숨김, 모바일만 표시) */
#m-day-detail { display: none; }
body.is-mobile #m-day-detail {
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
body.is-mobile #m-fab {
    display: flex; align-items: center; justify-content: center;
    position: fixed; right: 18px; bottom: 22px; width: 56px; height: 56px;
    border-radius: 50%; border: none; background: #3498db; color: #fff;
    font-size: 30px; line-height: 1; cursor: pointer; z-index: 1000;
    box-shadow: 0 4px 14px rgba(52,152,219,.45);
}
#m-fab:active { background: #2176ae; }

/* 🎤 음성 명령 버튼 (모바일 플로팅, + 위) */
#m-mic { display: none; }
body.is-mobile #m-mic {
    display: flex; align-items: center; justify-content: center;
    position: fixed; right: 18px; bottom: 88px; width: 56px; height: 56px;
    border-radius: 50%; border: none; background: #9b59b6; color: #fff;
    font-size: 26px; line-height: 1; cursor: pointer; z-index: 1000;
    box-shadow: 0 4px 14px rgba(155,89,182,.45);
}
#m-mic:active, #btn-voice.listening, #m-mic.listening { background: #e74c3c; }
#btn-voice.listening { color:#fff; border-color:#c0392b; }
@keyframes micPulse { 0%,100%{ box-shadow:0 4px 14px rgba(231,76,60,.5);} 50%{ box-shadow:0 4px 22px rgba(231,76,60,.9);} }
.listening { animation: micPulse 1s ease-in-out infinite; }

/* 음성 결과 오버레이 */
.vo-heard { background:#f3f0f8; border:1px solid #e0d5ef; color:#6c3483; border-radius:8px;
    padding:9px 12px; font-size:14px; margin-bottom:12px; }
.vo-heard b { color:#9b59b6; }
.vo-sub { font-size:12.5px; color:#7f8c8d; margin-bottom:8px; }
.vo-sum { background:#f8fafc; border:1px solid #eef1f4; border-radius:8px; padding:11px 13px; margin-bottom:12px; }
.vo-sum b { font-size:15px; color:#2c3e50; }
.vo-sum .vo-it-sub { margin-top:3px; }
.vo-choice { display:flex; gap:8px; }
.vo-choice .btn { flex:1; padding:11px 8px; font-size:14px; }
.vo-hint { margin-top:10px; font-size:12px; color:#95a5a6; line-height:1.5; }
.vo-warn { background:#fdecea; border:1px solid #f5c6c2; color:#c0392b; border-radius:8px; padding:10px 12px; margin-bottom:12px; font-size:13.5px; }
.vo-warn-list { margin-top:6px; font-size:12.5px; color:#922b21; line-height:1.55; }
.vo-loading, .vo-empty { color:#7f8c8d; font-size:14px; padding:16px 4px; text-align:center; }
.vo-err { color:#c0392b; font-size:14px; padding:14px 4px; line-height:1.6; }
.vo-item { display:flex; align-items:center; gap:10px; padding:10px 12px; border:1px solid #eef1f4;
    border-radius:8px; margin-bottom:7px; cursor:pointer; background:#fff; }
.vo-item:hover { background:#f7f9fb; }
.vo-it-main { flex:1; min-width:0; }
.vo-it-main b { font-size:14.5px; color:#2c3e50; }
.vo-it-sub { font-size:12px; color:#8a97a3; margin-top:2px; }
.vo-del { flex-shrink:0; border:1px solid #e74c3c; color:#e74c3c; background:#fff;
    border-radius:6px; padding:6px 12px; font-size:13px; font-weight:700; cursor:pointer; }
.vo-del:hover { background:#e74c3c; color:#fff; }

/* 참석자 자동완성 드롭다운 */
.att-ac {
    position: absolute; left: 0; right: 0; top: 100%; z-index: 50;
    margin-top: 4px; max-height: 232px; overflow-y: auto;
    background: #fff; border: 1px solid #e1e5ea; border-radius: 8px;
    box-shadow: 0 6px 20px rgba(0,0,0,.12);
}
.att-ac-item {
    display: flex; align-items: baseline; gap: 8px;
    padding: 8px 12px; cursor: pointer; font-size: 13px; line-height: 1.3;
    border-bottom: 1px solid #f2f4f7;
}
.att-ac-item:last-child { border-bottom: none; }
.att-ac-item.active, .att-ac-item:hover { background: #eaf4ff; }
.att-ac-item .nm { font-weight: 600; color: #2c3e50; white-space: nowrap; }
.att-ac-item .grp { flex-shrink: 0; color: #2980b9; background: #eaf4ff; font-size: 11px; padding: 1px 7px; border-radius: 9px; white-space: nowrap; }
.att-ac-item .org { color: #95a5a6; font-size: 11.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.att-ac-new { color: #7f8c9b; font-style: normal; }
.att-ac-new b { color: #2980b9; font-style: normal; }

/* ===================================================================
   월간 캘린더 리디자인 (입력 폼과 동일 디자인 톤: 카드·세그먼트·소프트 칩)
=================================================================== */
#scheduler{
  --c-line:#e9edf4; --c-line-soft:#f0f3f8; --c-muted:#8a93a6;
  --c-acc:#3b68f5; --c-acc-soft:#eef3ff; --c-sun:#e8554e; --c-sat:#3b82d6;
}
/* 툴바 */
#scheduler .sch-toolbar h2{ font-size:22px; font-weight:800; letter-spacing:-.5px; color:#1f2433; }
#scheduler .sch-toolbar .btn-outline{ border:1px solid var(--c-line); background:#fff; color:#5a6173; border-radius:9px; font-weight:700; }
#scheduler .sch-toolbar .btn-outline:hover{ border-color:var(--c-acc); color:var(--c-acc); background:#fff; }
#scheduler #btn-prev, #scheduler #btn-next{ padding:6px 12px; }
#scheduler .sch-toolbar .btn-primary{ background:var(--c-acc); border-radius:10px; font-weight:700; box-shadow:0 2px 6px rgba(59,104,245,.22); }
#scheduler .sch-toolbar .btn-primary:hover{ background:#2f59e0; }
/* 뷰 탭 = 세그먼트 */
#scheduler .view-tabs{ border:none; background:var(--c-line-soft); border-radius:10px; padding:3px; overflow:visible; }
#scheduler .view-tabs button{ background:transparent; color:var(--c-muted); border-radius:7px; font-weight:700; padding:7px 15px; }
#scheduler .view-tabs button.active{ background:#fff; color:var(--c-acc); box-shadow:0 1px 3px rgba(22,30,55,.1); }
/* 월간 = 떠 있는 카드 */
#scheduler #view-month{ background:#fff; border:1px solid var(--c-line); border-radius:16px; box-shadow:0 1px 2px rgba(22,30,55,.04), 0 16px 36px -20px rgba(22,30,55,.18); overflow:hidden; }
/* 요일 헤더(밝게) */
#scheduler .cal-header{ background:#fbfcfe; color:#6b7488; border-radius:0; border-bottom:1px solid var(--c-line); }
#scheduler .cal-header div{ font-weight:700; }
#scheduler .cal-header div:first-child{ color:var(--c-sun); }
#scheduler .cal-header div:last-child{ color:var(--c-sat); }
/* 토·일 컬럼은 평일의 70% 폭 (열 순서: 일 월 화 수 목 금 토) */
#scheduler .cal-header,
#scheduler .cal-grid{ grid-template-columns: 0.7fr 1fr 1fr 1fr 1fr 1fr 0.7fr; }
/* 그리드·셀 — 모든 행 동일 높이(내용 많아도 안 늘어남, 넘치면 +N개) */
#scheduler .cal-grid{ border:none; grid-auto-rows:minmax(0,1fr); }
#scheduler .cal-cell{ border-right:1px solid var(--c-line-soft); border-bottom:1px solid var(--c-line-soft); overflow:hidden; }
#scheduler .cal-cell:nth-child(7n){ border-right:none; }
#scheduler .cal-cell:hover{ background:#fbfcfe; }
#scheduler .cal-cell.other-month{ background:#fafbfd; }
#scheduler .cal-cell.other-month .day-num{ color:#c4cad6; }
#scheduler .cal-cell.today{ background:var(--c-acc-soft); }
#scheduler .cal-cell.today .day-num{ background:var(--c-acc); }
#scheduler .cal-cell.sunday .day-num{ color:var(--c-sun); }
#scheduler .cal-cell.saturday .day-num{ color:var(--c-sat); }
/* 이벤트 칩(소프트 파스텔 + 점) */
#scheduler .event-chip{ display:flex; align-items:center; gap:5px; border-radius:7px; padding:3px 7px; font-weight:600; letter-spacing:-.2px; }
#scheduler .event-chip .ev-dot{ width:6px; height:6px; border-radius:50%; flex:none; }
#scheduler .event-chip .ev-tx{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; min-width:0; flex:1; }
#scheduler .event-chip.done{ background:transparent; border:none; text-decoration:none; }
#scheduler .event-chip.done .ev-tx{ color:#9aa3b5; text-decoration:none; }
#scheduler .event-chip.done:hover{ background:#f3f5f9; }
/* ⏳ 입력대기(draft): 점선 테두리 + 앰버 톤 */
#scheduler .event-chip.draft{ background:#fff7e6 !important; color:#b97400 !important; border:1px dashed #f0b95a; font-weight:600; }
#scheduler .event-chip.draft .ev-dot{ display:none; }
body.is-mobile .event-chip.draft{ background:#fff !important; border:1.5px dashed #f0b95a; }
/* 모바일: 카드 테두리 제거(전체화면 느낌) + 셀 클립 해제(점 표시) */
body.is-mobile #scheduler #view-month{ border:none; border-radius:0; box-shadow:none; }
body.is-mobile #scheduler .cal-cell{ overflow:visible; }
/* 모바일: 칩 = 작은 점으로 (데스크톱 flex/패딩 스타일 리셋) */
body.is-mobile #scheduler .event-chip{ display:inline-block; padding:0; gap:0; border-radius:50%; width:10px; height:10px; }
body.is-mobile #scheduler .event-chip .ev-tx{ display:none; }
/* 모바일 완료 일정 = 회색 동그라미(기존처럼) */
body.is-mobile #scheduler .event-chip.done{ width:10px; height:10px; padding:0; border-radius:50%; border:1px solid #c4ccd4; background:transparent; }
</style>
</head>
<body class="<?= $mobile ? 'is-mobile' : '' ?>">
<?php sch_nav('calendar'); ?>

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
        <button class="btn" id="btn-voice" onclick="voiceStart()" title="음성으로 일정 등록·조회·삭제">🎤 음성</button>
        <button class="btn btn-primary" id="btn-new">+ 일정 추가</button>
    </div>


    <div id="sch-body">
        <!-- 프로젝트/그룹 사이드패널 -->
        <div id="proj-panel">
            <div class="proj-panel-head">
                <span class="proj-panel-title">📂 분류·프로젝트</span>
                <span class="proj-add-wrap">
                    <button class="btn-add-proj" onclick="toggleProjAddMenu(event)" title="추가">＋</button>
                    <div class="proj-add-menu" id="proj-add-menu">
                        <button onclick="openProjModal(0,'group')">📁 분류 추가</button>
                        <button onclick="openProjModal(0,'project')">📌 프로젝트 추가</button>
                        <button onclick="openTodoModal('goal')">🎯 목표 추가</button>
                        <button onclick="openTodoModal('habit')">✓ 습관 추가</button>
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
                <!-- 월 격자 하단 고정: 오늘 남은 습관 바 -->
                <div id="month-habit-bar" style="display:none;"></div>
            </div>
            <div id="view-week" style="display:none;flex:1;overflow:auto;"></div>
            <div id="view-day"  style="display:none;flex:1;overflow:auto;"></div>
            <div id="view-list" style="display:none;flex:1;overflow-y:auto;"></div>
            <!-- 모바일 전용: 선택한 날짜의 상세 일정 패널 -->
            <div id="m-day-detail"></div>
        </div>
    </div>
    <!-- 모바일 전용: 음성 명령 플로팅 버튼 -->
    <button id="m-mic" onclick="voiceStart()" title="음성 명령">🎤</button>
    <!-- 모바일 전용: 일정 추가 플로팅 버튼 -->
    <button id="m-fab" onclick="fabAdd()" title="일정 추가">＋</button>
</div>

<!-- 🎤 음성 명령 결과 오버레이 -->
<div class="modal-overlay" id="voice-overlay">
    <div class="modal" style="max-width:440px">
        <h3 id="vo-title" style="margin:14px 0 12px;font-size:16px;">🎤 음성 명령</h3>
        <div id="vo-heard" class="vo-heard" style="display:none"></div>
        <div id="vo-body"></div>
        <div class="modal-footer" style="margin-top:14px;text-align:right;">
            <button class="btn" onclick="voiceClose()">닫기</button>
        </div>
    </div>
</div>

<!-- 🎯 목표 / ✓ 습관 입력 오버레이 -->
<div class="modal-overlay" id="todo-overlay">
  <div class="modal" style="width:420px;max-width:94vw;">
    <div class="type-tabs">
      <button class="type-tab" id="td-tab-goal"  data-t="goal"  onclick="setTodoTab('goal')">🎯 목표</button>
      <button class="type-tab" id="td-tab-habit" data-t="habit" onclick="setTodoTab('habit')">✓ 습관</button>
    </div>

    <!-- ── 목표 탭 ── -->
    <div id="td-pane-goal" class="td-pane" style="padding-top:12px;">
      <input id="td-g-title" class="td-inp" placeholder="목표 이름 입력 *">
      <div class="td-lbl">연결 분류</div>
      <select id="td-g-cat" class="td-inp" onchange="tdCatChange()"></select>
      <div class="td-lbl">목표 주기</div>
      <div style="display:flex;align-items:center;gap:8px;">
        <input id="td-g-cnt" class="td-inp" type="number" value="1" min="1" style="width:54px;text-align:center;" oninput="tdGoalPrev()">
        <span style="color:#7f8c8d;">회 ·</span>
        <select id="td-g-per" class="td-inp" style="flex:1;" onchange="tdGoalPrev()">
          <option value="week">매주</option>
          <option value="month" selected>매월</option>
          <option value="quarter">분기</option>
          <option value="year">매년</option>
        </select>
      </div>
      <div id="td-g-prev" class="td-prev">🏳 한 달에 1번</div>
      <div class="td-lbl">집계 방식</div>
      <div class="td-seg" id="td-g-modeseg">
        <button data-m="auto"   class="active" onclick="tdSetMode('auto')">분류 일정 자동</button>
        <button data-m="manual" onclick="tdSetMode('manual')">수동 체크</button>
      </div>
      <div id="td-g-help" class="td-help">연결 분류에 이번 기간 일정이 쌓인 만큼 진행률이 오릅니다</div>
    </div>

    <!-- ── 습관 탭 ── -->
    <div id="td-pane-habit" class="td-pane" style="padding-top:12px;display:none;">
      <input id="td-h-title" class="td-inp" placeholder="제목 입력 *">
      <div class="td-lbl">분류 <span style="color:#aab2bd;">(선택 · 배지)</span></div>
      <input id="td-h-cat" class="td-inp" placeholder="예: 독서, 운동, 공부" list="td-h-cat-list" maxlength="40">
      <datalist id="td-h-cat-list"></datalist>
      <div class="td-lbl">참고 링크 <span style="color:#aab2bd;">(선택 · 유튜브/블로그)</span></div>
      <input id="td-h-ref" class="td-inp" type="url" placeholder="https://… (기록할 때 팝업으로 열림)" maxlength="500">
      <div class="td-lbl">반복 *</div>
      <div class="td-seg" id="td-h-recurseg">
        <button data-k="daily"      class="active" onclick="tdSetRecur('daily')">매일</button>
        <button data-k="weekdays"   onclick="tdSetRecur('weekdays')">요일 선택</button>
        <button data-k="week_quota" onclick="tdSetRecur('week_quota')">주 N회</button>
      </div>
      <div id="td-h-days" class="td-daychips" style="display:none;"></div>
      <div id="td-h-week" style="display:none;align-items:center;gap:7px;margin-top:8px;">
        <span style="color:#7f8c8d;font-size:12px;">주</span>
        <input id="td-h-quota" class="td-inp" type="number" value="3" min="1" style="width:54px;text-align:center;">
        <span style="color:#7f8c8d;font-size:12px;">회 (요일 무관)</span>
      </div>
      <div class="td-lbl">하루 목표 <span style="color:#aab2bd;">(선택 · 측정형)</span></div>
      <div style="display:flex;align-items:center;gap:8px;">
        <input id="td-h-daily" class="td-inp" type="number" min="1" placeholder="예: 100" style="width:90px;text-align:center;" oninput="tdSyncUnit()">
        <input id="td-h-unit"  class="td-inp" placeholder="단위(분/개/쪽)" style="flex:1;" oninput="tdSyncUnit()">
      </div>
      <div class="td-help">수량을 넣으면 측정형(오늘 한 양 기록), 비우면 체크형</div>

      <div style="display:flex;align-items:center;justify-content:space-between;margin-top:14px;">
        <span style="color:#7f8c8d;font-size:12px;">누적 목표 <span style="color:#aab2bd;">(선택 · 졸업)</span></span>
        <label class="td-switch"><input type="checkbox" id="td-h-tracksw" onchange="tdToggleTrack()"><span></span></label>
      </div>
      <div id="td-h-trackrow" style="display:none;align-items:center;gap:8px;margin-top:8px;">
        <span style="color:#7f8c8d;font-size:12px;">목표</span>
        <input id="td-h-target" class="td-inp" type="number" min="1" placeholder="예: 10000" style="width:110px;text-align:center;">
        <span id="td-h-target-unit" style="color:#aab2bd;font-size:12px;"></span>
        <span style="color:#aab2bd;font-size:11px;">달성 시 자동 졸업</span>
      </div>

      <div class="td-lbl">시간대 <span style="color:#aab2bd;">(선택 · 정렬)</span></div>
      <div class="td-seg" id="td-h-slotseg">
        <button type="button" data-s="morning"   onclick="tdSetSlot('morning')">아침</button>
        <button type="button" data-s="afternoon" onclick="tdSetSlot('afternoon')">점심</button>
        <button type="button" data-s="evening"   onclick="tdSetSlot('evening')">저녁</button>
        <button type="button" data-s="anytime"   onclick="tdSetSlot('anytime')">아무때</button>
      </div>

      <div class="td-lbl">알림 <span style="color:#aab2bd;">(선택 · 여러 개)</span></div>
      <div id="td-h-reminders" class="td-reminders"></div>
      <button type="button" class="td-addbtn" onclick="tdAddReminder()">＋ 알림 시각 추가</button>

      <div class="td-lbl">예정 종료일 <span style="color:#aab2bd;">(선택)</span></div>
      <input id="td-h-enddate" class="td-inp" type="date" style="width:auto;">

      <div style="display:flex;align-items:center;justify-content:space-between;margin-top:14px;">
        <span style="color:#7f8c8d;font-size:12px;">캘린더 시각 표시 <span style="color:#aab2bd;">(선택)</span></span>
        <label class="td-switch"><input type="checkbox" id="td-h-timesw" onchange="tdToggleTime()"><span></span></label>
      </div>
      <div id="td-h-timerow" style="display:none;margin-top:8px;">
        <input id="td-h-time" class="td-inp" type="time" value="07:00" style="width:auto;">
        <span style="color:#aab2bd;font-size:11px;margin-left:8px;">타임라인 블록으로 표시</span>
      </div>

      <!-- 종료(편집 시): 삭제 아님, 기록 보존 -->
      <div id="td-h-endbox" style="display:none;margin-top:16px;border-top:1px solid #eee;padding-top:12px;">
        <div style="display:flex;gap:8px;">
          <button type="button" class="td-endbtn" onclick="tdEndHabit('completed')">🎓 달성으로 종료</button>
          <button type="button" class="td-endbtn stop" onclick="tdEndHabit('stopped')">🛑 그만두기</button>
        </div>
        <div class="td-help">종료해도 기록은 보존돼 나의 기록 "지난 습관"에서 볼 수 있어요</div>
      </div>
    </div>

    <!-- ── 공통: 색상 ── -->
    <div class="td-lbl">색상</div>
    <div class="color-swatches" id="td-swatches" style="display:flex;align-items:center;gap:8px;position:relative;">
      <span id="td-preset-wrap" style="display:contents;"></span>
      <span class="color-swatch color-custom" id="td-custom" onclick="tdOpenPalette(event)" title="맞춤색">🎨</span>
      <div id="td-popover" style="display:none;position:absolute;top:30px;left:0;background:#fff;border:1px solid #d6e4f0;border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,.16);padding:10px;z-index:60;">
        <div style="font-size:12px;font-weight:700;margin-bottom:8px;">색상 선택</div>
        <div id="td-palette" style="display:grid;gap:3px;"></div>
        <div style="display:flex;align-items:center;gap:8px;margin-top:8px;">
          <span id="td-preview" style="width:30px;height:30px;border-radius:6px;border:1px solid #ddd;"></span>
          <input id="td-hex" class="td-inp" placeholder="#RRGGBB" maxlength="7" style="flex:1;">
          <button class="btn btn-primary" onclick="tdApplyHex()">적용</button>
        </div>
      </div>
      <input type="hidden" id="td-color" value="#3498db">
    </div>

    <div class="modal-footer" style="margin-top:18px;display:flex;justify-content:flex-end;gap:8px;">
      <button class="btn" id="td-del" style="margin-right:auto;display:none;color:#e74c3c;" onclick="deleteTodo()">삭제</button>
      <button class="btn" onclick="closeTodoModal()">취소</button>
      <button class="btn btn-primary" onclick="saveTodoModal()">저장</button>
    </div>
  </div>
</div>

<!-- 📊 대시보드 모달 -->
<div class="modal-overlay" id="dash-overlay">
  <div class="modal" style="width:780px;max-width:96vw;max-height:90vh;display:flex;flex-direction:column;padding:0;">
    <div class="dash-modal-head">
      <span class="dash-modal-title">📊 나의 기록</span>
      <span class="dash-modal-nav">
        <button class="btn btn-outline" onclick="dashNav(-1)">◀</button>
        <span id="dash-month-label"></span>
        <button class="btn btn-outline" onclick="dashNav(1)">▶</button>
        <button class="btn btn-outline" onclick="dashToday()">이번 달</button>
      </span>
      <button class="dash-modal-x" onclick="closeDashboard()" title="닫기">✕</button>
    </div>
    <div id="dash-body" style="overflow-y:auto;padding:14px 6px;"></div>
  </div>
</div>

<!-- 습관 오늘 기록 모달 (측정형, 실시간 미리보기) -->
<div class="modal-overlay" id="habit-log-overlay">
  <div class="modal" style="width:320px;max-width:94vw;">
    <div class="hl-head">
      <span id="hl-icon" class="hl-icon">🔁</span>
      <div style="min-width:0;flex:1;">
        <div id="hl-name" class="hl-name"></div>
        <div id="hl-date" class="hl-date"></div>
      </div>
      <span id="hl-streak" class="hl-head-streak"></span>
      <span class="hl-x" onclick="closeHabitLog()" title="닫기">✕</span>
    </div>
    <!-- 오늘 = 히어로 -->
    <div class="hl-today">
      <div class="hl-today-h">
        <span>오늘 목표</span>
        <span id="hl-today-target"></span>
      </div>
      <div class="hl-hero">
        <b id="hl-today-now">0</b><span id="hl-today-slash" class="hl-hero-sub"></span>
        <span id="hl-today-remain" class="hl-hero-done"></span>
      </div>
      <div class="hl-bar hl-bar-lg"><div id="hl-today-bar" class="hl-bar-base"></div></div>
    </div>

    <!-- 참고 링크(있을 때만) -->
    <button id="hl-ref" class="hl-ref" style="display:none;" onclick="openHabitRef(event,this.dataset.url)"></button>

    <!-- 입력 방식: ＋추가(증분) / 누적값(현재 총값 직접 입력) -->
    <div class="hl-seg" id="hl-mode">
      <button type="button" data-m="add"   onclick="hlSetMode('add')">＋ 추가</button>
      <button type="button" data-m="total" onclick="hlSetMode('total')">누적값</button>
    </div>
    <div id="hl-mode-label" class="hl-seg-label"></div>
    <div class="hl-amt-row">
      <input id="hl-val" type="number" min="0" class="hl-amt" onfocus="if(HL.mode==='total')this.value='';" onkeydown="if(event.key==='Enter'){event.preventDefault();hlSubmit();}">
      <span id="hl-unit" class="hl-unit"></span>
      <button class="btn btn-primary" style="flex:none;" onclick="hlSubmit()">기록</button>
    </div>
    <div id="hl-mode-hint" class="hl-mode-hint"></div>

    <!-- 누적 = 요약 (track_total만) -->
    <div id="hl-track" class="hl-track" style="display:none;">
      <div class="hl-cum-top">
        <span class="hl-cum-l">누적 <b id="hl-now"></b> <span class="hl-muted" id="hl-target"></span></span>
        <span id="hl-pct" class="hl-cum-pct"></span>
      </div>
      <div class="hl-bar"><div id="hl-bar-base" class="hl-bar-base"></div></div>
      <div id="hl-cum-remain" class="hl-cum-remain"></div>
    </div>
  </div>
</div>

<!-- 참고 영상(유튜브) 임베드 팝업 -->
<div class="modal-overlay" id="yt-overlay">
  <div class="modal" style="width:760px;max-width:96vw;padding:0;overflow:hidden;background:#000;">
    <div style="display:flex;justify-content:flex-end;padding:6px 8px;background:#000;">
      <span class="hl-x" onclick="closeYt()" title="닫기" style="color:#fff;">✕</span>
    </div>
    <div style="position:relative;width:100%;padding-top:56.25%;">
      <iframe id="yt-frame" src="" style="position:absolute;top:0;left:0;width:100%;height:100%;border:0;" allow="autoplay; encrypted-media; fullscreen" allowfullscreen></iframe>
    </div>
  </div>
</div>

<div class="modal-overlay" id="modal-overlay">
    <div class="modal">
        <button type="button" class="modal-x" id="btn-modal-x" title="닫기" onclick="closeModal()">✕</button>
        <!-- 타입 탭 -->
        <div class="type-tabs">
            <button class="type-tab" data-type="timed"       onclick="setEventType('timed')">일정</button>
            <button class="type-tab" data-type="anniversary" onclick="setEventType('anniversary')">★ 기념일</button>
            <button class="type-tab" data-type="todo"        onclick="setEventType('todo')">☑ 할일</button>
        </div>

        <div id="modal-form-body">
        <h3 id="modal-title">일정 추가</h3>

        <!-- 제목 (라벨 없이 입력칸만) -->
        <div class="form-row" id="row-title">
            <input type="text" id="f-title" placeholder="제목 입력 *">
        </div>

        <!-- 일정: 시작·종료 + 반복 버튼 (한 줄) -->
        <div id="row-timed" style="display:flex;align-items:flex-end;gap:10px;margin-bottom:12px;flex-wrap:wrap;">
            <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;font-weight:600;flex:1 1 185px;min-width:178px;">
                시작 * <input type="datetime-local" id="f-start">
            </label>
            <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;font-weight:600;flex:1 1 185px;min-width:178px;">
                종료   <input type="datetime-local" id="f-end">
            </label>
            <div style="display:flex;align-items:center;gap:5px;padding-bottom:4px;flex-shrink:0;">
                <span style="font-size:15px;color:#7f8c8d;">↺</span>
                <button type="button" class="recur-type-btn" data-rtype="daily"   onclick="openRecurModal('daily')">매일</button>
                <button type="button" class="recur-type-btn" data-rtype="weekly"  onclick="openRecurModal('weekly')">매주</button>
                <button type="button" class="recur-type-btn" data-rtype="monthly" onclick="openRecurModal('monthly')">매월</button>
                <button type="button" class="recur-type-btn" data-rtype="yearly"  onclick="openRecurModal('yearly')">매년</button>
                <button type="button" id="btn-recur-clear" onclick="clearRecur()" style="display:none;margin-left:4px;background:none;border:none;color:#e74c3c;font-size:13px;cursor:pointer;padding:2px 6px;border-radius:4px;border:1px solid #e74c3c;">× 반복해제</button>
            </div>
            <!-- 종일: 화면 비표시(기존 종일 일정 호환용 상태값만 유지) -->
            <label style="display:none;"><input type="checkbox" id="f-allday"> 종일</label>
            <!-- 반복 설정 hidden -->
            <input type="hidden" id="h-recur-type" value="">
            <input type="hidden" id="h-recur-interval" value="1">
            <input type="hidden" id="h-recur-end-type" value="none">
        </div>

        <div id="end-time-msg" style="display:none;color:#e74c3c;font-size:12px;margin:-6px 0 8px;"></div>

        <!-- 반복 요약 -->
        <div id="recur-summary-row" style="display:none;margin:-6px 0 10px;padding:4px 10px;background:#eaf4ff;border-radius:5px;font-size:12px;color:#2980b9;">
            <span id="recur-summary-text"></span>
        </div>

        <!-- 기념일: 분류 + 카테고리 (한 줄) -->
        <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:12px;">
            <!-- 분류 (일반/가족) -->
            <div id="row-anniv-class" style="display:none;flex:0 0 auto;">
                <div style="font-size:13px;font-weight:600;margin-bottom:8px;">분류</div>
                <div style="display:flex;gap:8px;">
                    <button type="button" class="anniv-class-btn active" data-cls="0" onclick="setAnnivClass(0)">📅 일반</button>
                    <button type="button" class="anniv-class-btn" data-cls="1" onclick="setAnnivClass(1)">🏠 가족</button>
                </div>
                <input type="hidden" id="h-is-family" value="0">
            </div>
            <!-- 카테고리 버튼 -->
            <div id="row-anniv-cat" style="display:none;flex:1;min-width:200px;">
                <div style="font-size:13px;font-weight:600;margin-bottom:8px;">카테고리</div>
                <div style="display:flex;gap:6px;flex-wrap:wrap;" id="anniv-cat-btns"></div>
                <input type="hidden" id="h-anniv-cat" value="생일">
            </div>
        </div>

        <div id="row-anniversary" style="display:none;margin-bottom:12px;">
            <!-- 양력/음력 + 날짜 (한 줄) -->
            <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
                <div style="display:flex;gap:14px;align-items:center;">
                    <label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer;">
                        <input type="radio" name="anniv-cal" value="solar" checked onchange="onAnnivCalChange()"> 양력
                    </label>
                    <label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer;">
                        <input type="radio" name="anniv-cal" value="lunar" onchange="onAnnivCalChange()"> 음력
                    </label>
                </div>
                <!-- 양력 날짜 -->
                <div id="row-anniv-solar">
                    <input type="date" id="f-anniv-date" style="height:34px;">
                </div>
                <!-- 음력 날짜 (양력 필드처럼 컴팩트 · 윤달 이모지 배지) -->
                <div id="row-anniv-lunar" style="display:none;">
                    <div style="display:flex;gap:6px;align-items:center;">
                        <select id="f-lunar-month" style="width:84px;height:34px;">
                            <?php for($m=1;$m<=12;$m++) echo "<option value='{$m}'>{$m}월</option>"; ?>
                        </select>
                        <select id="f-lunar-day" style="width:78px;height:34px;">
                            <?php for($d=1;$d<=30;$d++) echo "<option value='{$d}'>{$d}일</option>"; ?>
                        </select>
                        <span id="f-lunar-leap-badge" onclick="toggleLunarLeap()" title="윤달 — 클릭으로 전환"
                              style="cursor:pointer;user-select:none;font-size:13px;font-weight:600;padding:7px 11px;border-radius:9px;border:1px solid #d4dae6;color:#b8bfca;background:#fff;white-space:nowrap;transition:.15s;">윤달</span>
                        <input type="checkbox" id="f-lunar-leap" style="display:none;">
                    </div>
                </div>
            </div>

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

        <!-- 카테고리(반) + 그룹 + 프로젝트 (한 줄) -->
        <div class="form-row">
            <label style="flex:0.6 1 76px;min-width:76px;">카테고리
                <select id="f-cat">
                    <option value="업무">업무</option><option value="개인">개인</option>
                    <option value="주식">주식</option><option value="회의">회의</option>
                    <option value="기타">기타</option>
                </select>
            </label>
            <label style="min-width:100px;">분류
                <select id="f-group-id">
                    <option value="">없음</option>
                </select>
            </label>
            <label id="row-project" style="display:none;min-width:100px;">프로젝트
                <select id="f-project-id">
                    <option value="">연결 안 함</option>
                </select>
            </label>
        </div>

        <!-- 색상 + 알림 (한 줄) -->
        <div class="form-row">
            <label style="flex:0 0 auto;min-width:auto;">색상
                <div class="color-swatches" id="evt-swatches" style="position:relative;">
                    <span id="evt-preset-wrap" style="display:contents;"></span>
                    <span class="color-swatch color-edit" id="evt-edit-btn" onclick="toggleSwatchEdit(event)" title="색상 칸 편집: 누른 뒤 바꿀 칸을 선택">✎</span>
                    <span class="color-swatch color-custom" id="evt-custom-swatch" onclick="openCustomPicker(event)" title="맞춤 색상(이 일정만)">🎨</span>
                    <!-- 맞춤 색상 팝오버(팔레트 격자 + 정밀 입력) -->
                    <div id="color-popover" style="display:none;position:absolute;top:34px;left:0;z-index:60;background:#fff;border:1px solid #d6dce2;border-radius:10px;box-shadow:0 6px 24px rgba(0,0,0,.18);padding:12px;width:312px;">
                        <div style="font-size:12px;font-weight:700;color:#444;margin-bottom:8px;">색상 선택</div>
                        <div id="cc-palette" style="display:grid;gap:3px;margin-bottom:10px;"></div>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span id="cc-preview" style="width:32px;height:32px;border-radius:6px;border:1px solid #dde;background:#3498db;flex-shrink:0;"></span>
                            <input type="text" id="cc-hex" value="#3498db" maxlength="7" placeholder="#RRGGBB"
                                   style="flex:1;min-width:0;border:1px solid #dde;border-radius:6px;padding:6px 8px;font-size:13px;font-family:monospace;"
                                   oninput="ccSyncFromHex(this.value);">
                            <button type="button" class="btn" style="padding:5px 12px;font-size:12px;background:#3498db;color:#fff;border:none;flex-shrink:0;" onclick="ccApply()">적용</button>
                        </div>
                    </div>
                </div>
            </label>
            <label>알림
                <div class="alarms" style="display:flex;gap:6px;flex-wrap:nowrap;margin-top:5px;">
                    <label style="display:flex;align-items:center;gap:4px;font-weight:normal;font-size:13px;cursor:pointer;">
                        <input type="checkbox" class="f-alert" value="30" style="width:auto;accent-color:#3498db;"> 30분 전
                    </label>
                    <label style="display:flex;align-items:center;gap:4px;font-weight:normal;font-size:13px;cursor:pointer;">
                        <input type="checkbox" class="f-alert" value="60" style="width:auto;accent-color:#3498db;"> 1시간 전
                    </label>
                    <label style="display:flex;align-items:center;gap:4px;font-weight:normal;font-size:13px;cursor:pointer;">
                        <input type="checkbox" class="f-alert" value="1440" style="width:auto;accent-color:#3498db;"> 하루 전
                    </label>
                </div>
            </label>
        </div>

        <!-- 참석자 -->
        <div class="form-row" id="row-attendees">
            <label>참석자
                <div style="display:flex;gap:6px;align-items:flex-start;">
                    <div style="flex:1;position:relative;">
                        <div id="attendee-chips" style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:5px;"></div>
                        <input type="text" id="f-attendee-input" autocomplete="off"
                               placeholder="이름 입력 (주소록에 없으면 이름만 추가)"
                               style="width:100%;border:1px solid #dde;border-radius:6px;padding:7px 10px;font-size:13px;">
                        <div id="attendee-ac" class="att-ac" style="display:none;"></div>
                    </div>
                </div>
            </label>
        </div>

        <!-- 위치/지도 -->
        <div class="form-row" id="row-location">
            <label style="min-width:auto;">위치 (선택)
                <div style="display:flex;gap:5px;align-items:center;margin-top:5px;">
                    <div style="display:flex;border:1px solid #bdc3c7;border-radius:6px;overflow:hidden;flex-shrink:0;height:34px;">
                        <button type="button" id="loc-btn-naver"  class="loc-region-btn active" onclick="setLocRegion('naver')"  style="padding:0 10px;font-size:12px;height:34px;font-weight:600;">국내</button>
                        <button type="button" id="loc-btn-google" class="loc-region-btn"        onclick="setLocRegion('google')" style="padding:0 10px;font-size:12px;height:34px;font-weight:600;">해외</button>
                    </div>
                    <input type="text" id="f-address" placeholder="주소 입력 후 엔터"
                           style="flex:2;min-width:0;border:1px solid #dde;border-radius:6px;padding:0 10px;font-size:13px;height:34px;box-sizing:border-box;"
                           onkeydown="if(event.key==='Enter'){event.preventDefault();openMapPicker(this.value.trim());}"
                           oninput="setLocBtn('default'); if(!this.value.trim()){ this.dataset.picked=''; document.getElementById('f-place-name').value=''; }">
                    <input type="text" id="f-place-name" placeholder="상호명"
                           style="flex:1;min-width:0;border:1px solid #dde;border-radius:6px;padding:0 8px;font-size:13px;height:34px;box-sizing:border-box;">
                    <button type="button" class="btn btn-outline" id="btn-map-pick" onclick="reopenMapPicker()" style="flex-shrink:0;font-size:12px;padding:0 12px;height:34px;">주소확인</button>
                </div>
                <div id="loc-status" style="font-size:12px;margin-top:4px;min-height:0;display:none;"></div>
                <input type="hidden" id="f-lat">
                <input type="hidden" id="f-lng">
                <input type="hidden" id="f-provider" value="naver">
            </label>
        </div>

        <!-- 여행지도 (places.php에서 저장한 트립 연결) -->
        <div class="form-row" id="row-trip">
            <label style="min-width:auto;">여행지도 (선택)
                <div style="display:flex;gap:5px;align-items:center;margin-top:5px;">
                    <select id="f-trip" onchange="onTripChange()"
                            style="flex:1;min-width:0;border:1px solid #dde;border-radius:6px;padding:0 8px;font-size:13px;height:34px;box-sizing:border-box;background:#fff;">
                        <option value="">— 연결 안 함 —</option>
                    </select>
                    <button type="button" class="btn btn-outline" id="btn-trip-view" onclick="openTripPopup()" disabled
                            style="flex-shrink:0;font-size:12px;padding:0 12px;height:34px;">🗺 보기</button>
                </div>
                <div id="trip-status" style="font-size:12px;margin-top:4px;color:#8a97a3;display:none;"></div>
                <input type="hidden" id="f-trip-token">
                <input type="hidden" id="f-trip-name">
            </label>
        </div>

        <!-- 메모 -->
        <div class="form-row form-row-memo">
            <label>메모 <textarea id="f-memo" placeholder="메모 (선택) — 이미지를 붙여넣기(Ctrl+V)하면 첨부됩니다"></textarea></label>
            <div id="f-attach-area">
                <div id="f-attach-list"></div>
                <div class="f-attach-tools">
                    <button type="button" class="f-attach-btn" onclick="document.getElementById('f-attach-file').click()">📎 이미지 첨부</button>
                    <span class="f-attach-hint">메모창에 붙여넣기(Ctrl+V)해도 첨부됩니다</span>
                </div>
                <input type="file" id="f-attach-file" accept="image/*" multiple style="display:none" onchange="attachFromFiles(this.files); this.value='';">
            </div>
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
            <div id="view-draft-note" style="display:none;background:#fff7e6;border:1px solid #f0d29a;color:#b97400;border-radius:8px;padding:9px 12px;font-size:13px;margin-bottom:10px;line-height:1.5;">⏳ <b>입력대기</b> 일정입니다. <b>수정</b>을 눌러 내용을 채워 저장하면 정식 등록됩니다.</div>
            <div id="view-datetime" style="font-size:13px;color:#555;margin-bottom:8px;"></div>
            <div id="view-category" style="font-size:13px;color:#555;margin-bottom:8px;"></div>
            <div id="view-recur"    style="font-size:12px;color:#3498db;margin-bottom:8px;display:none;"></div>
            <div id="view-attendees" style="font-size:13px;color:#555;margin-bottom:8px;display:none;"></div>
            <div id="view-memo"     style="font-size:13px;color:#666;background:#f8f9fa;border-radius:6px;padding:10px;display:none;white-space:pre-wrap;"></div>
            <div id="view-attach"   style="display:none;margin-top:8px;"></div>
            <!-- 위치/지도 -->
            <div id="view-location" style="display:none;margin-top:10px;">
                <div id="view-address" style="font-size:13px;color:#555;margin-bottom:6px;"></div>
                <div id="view-map"></div>
                <div id="view-map-fallback" class="view-map-fallback" style="display:none;"></div>
                <div id="view-map-links" class="view-map-links"></div>
            </div>
            <!-- 연결된 여행지도 -->
            <div id="view-trip" style="display:none;margin-top:10px;">
                <button type="button" class="btn btn-outline" onclick="openTripWindow(document.getElementById('view-trip').dataset.token)"
                        style="font-size:13px;padding:7px 12px;width:100%;text-align:left;">
                    🗺 <span id="view-trip-name" style="font-weight:600;"></span> 여행지도 보기
                </button>
            </div>
            <!-- 활동 메모(타임스탬프 로그) -->
            <div id="view-log" style="margin-top:12px;border-top:1px solid #f0f0f0;padding-top:10px;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                    <span style="font-size:13px;font-weight:600;color:#444;">📝 메모</span>
                    <button id="view-log-toggle" class="btn btn-outline" style="padding:3px 9px;font-size:13px;line-height:1;" onclick="toggleLogInput()" title="메모 추가">✏️</button>
                </div>
                <div id="view-log-list" style="display:flex;flex-direction:column;gap:4px;"></div>
                <div id="view-log-input" style="display:none;margin-top:8px;">
                    <textarea id="view-log-text" rows="2" placeholder="메모 입력 후 등록 (시각 자동 기록)"
                        style="width:100%;box-sizing:border-box;border:1px solid #ddd;border-radius:6px;padding:8px;font-size:13px;resize:vertical;"></textarea>
                    <div style="display:flex;justify-content:flex-end;gap:6px;margin-top:6px;">
                        <button class="btn btn-outline" style="padding:4px 11px;font-size:12px;" onclick="toggleLogInput(false)">취소</button>
                        <button class="btn btn-primary" style="padding:4px 11px;font-size:12px;" onclick="addLog()">등록</button>
                    </div>
                </div>
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
                        <label><input type="radio" name="ryt" value="lastday" onchange="updateRPreview()"> <span id="r-y-month-lbl"></span> 말일</label>
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
let TODO_HABITS = []; // 습관 캐시 (사이드바·대시보드 공유)
let TODO_GOALS  = []; // 목표 캐시 (진행률 포함)
let dashOpen = false; // 대시보드 모달 열림 여부
let DASH = { year: 0, month: 1 };  // 대시보드 전용 월 상태 (캘린더와 독립)
let _travels  = [];   // 여행 목록 캐시 (캘린더 막대 읽기전용)

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
// 임의 색 → 소프트 칩(연한 배경 + 읽기 쉬운 글자색 + 원본 점색)
function _hexRgb(h){ h=(h||'').replace('#',''); if(h.length===3)h=h.split('').map(c=>c+c).join(''); return [parseInt(h.slice(0,2),16)||0,parseInt(h.slice(2,4),16)||0,parseInt(h.slice(4,6),16)||0]; }
function _mix(a,b,t){ return a.map((v,i)=>Math.round(v+(b[i]-v)*t)); }
function softChip(hex){
    const rgb=_hexRgb(hex);
    const bg=_mix(rgb,[255,255,255],0.86);
    const lum=(0.299*rgb[0]+0.587*rgb[1]+0.114*rgb[2])/255;
    const fg=_mix(rgb,[0,0,0], lum>0.62?0.46:0.18);
    return { bg:`rgb(${bg.join(',')})`, fg:`rgb(${fg.join(',')})`, dot:hex };
}
// hex → [h(0~360), s%, l%]
function _hexHsl(hex){
    const [r,g,b]=_hexRgb(hex).map(v=>v/255);
    const mx=Math.max(r,g,b), mn=Math.min(r,g,b), d=mx-mn;
    let h=0;
    if(d){
        if(mx===r) h=((g-b)/d)%6;
        else if(mx===g) h=(b-r)/d+2;
        else h=(r-g)/d+4;
        h*=60; if(h<0) h+=360;
    }
    const l=(mx+mn)/2;
    const s=d===0?0:d/(1-Math.abs(2*l-1));
    return [Math.round(h), Math.round(s*100), Math.round(l*100)];
}
// 임의 색 → 카테고리 색세트(같은 hue를 명도만 달리: 연한 배경 tint / 진한 글자 fg / 중간 채움 fill) — 스펙 §1
function dashColorSet(hex){
    const [h,s]=_hexHsl(hex||'#3498db');
    const gray = s < 12;
    const sat  = gray ? 0 : Math.min(80, Math.max(55, s));
    return {
        tint: hslToHex(h, gray?0:Math.min(70,sat), 93),
        fg:   hslToHex(h, gray?0:Math.min(88,sat+8), 26),
        fill: hslToHex(h, sat, 50),
    };
}
// 이벤트 앞 프로젝트 번호(#N) — 배경 없이 대비색 텍스트만
function projNumBadge(ev) {
    if (!ev.project_id) return '';
    const p = _projects.find(x => x.id == ev.project_id);
    if (!p || p.type !== 'project') return '';   // 프로젝트 포함 일정만 표시
    const ico = p.icon || '📌';                   // 프로젝트 이모지 (없으면 기본)
    return `<span class="proj-num-badge" title="${esc(p.title)}">${ico} </span>`;
}

// 참석자 표시: 1명이면 (이름), 여러명이면 (이름+N) — 주소록 연락처 + 텍스트 참석자 합산
function attendeeSuffix(ev) {
    const names = (ev.attendees || []).map(a=>a.name)
        .concat((ev.extra_attendees || '').split(',').map(s=>s.trim()).filter(Boolean));
    if (!names.length) return '';
    return names.length === 1 ? `(${names[0]})` : `(${names[0]}+${names.length - 1})`;
}

// 칩 제목 텍스트 (제목 + 참석자) — esc() 안에서 사용
function evLabel(ev) {
    return ev.title + attendeeSuffix(ev);
}

// 위치 마커: 주소 있으면 📍 + 지역태그(국내=kr / 해외=o)
// 클릭 시 상세 모달 대신 외부 지도(네이버/구글)를 바로 새 탭으로 연다.
function mapMark(ev) {
    if (!(ev.address && String(ev.address).trim())) return '';
    const tip = esc(ev.place_name || ev.address || '지도');
    return ` <span class="chip-map-mark" onclick="openMapFromChip(event, ${ev.id})" title="${tip}">📍</span>`;
}

// 활동 메모 배지: 메모가 1개 이상이면 제목 옆에 📝N (esc 밖에서 raw HTML로 붙임)
function logMark(ev) {
    const n = +(ev.log_count || 0);
    if (!n) return '';
    return ` <span class="chip-log-mark" title="메모 ${n}개">📝(<b>${n}</b>)</span>`;
}

// 여행지도 마커: 트립 연결된 일정에 🗺, 클릭 시 공유모드 팝업 (토큰은 hex라 인라인 안전)
function tripMark(ev) {
    if (!ev.trip_token) return '';
    return ` <span class="chip-trip-mark" onclick="openTripFromChip(event,'${ev.trip_token}')" title="여행지도 보기">🗺</span>`;
}
function openTripFromChip(e, token) {
    e.stopPropagation();
    openTripWindow(token);
}

// 칩의 위치 마커 클릭 → 현재위치에서 일정 장소로 길찾기
function openMapFromChip(e, id) {
    e.stopPropagation();
    const ev = S.events.find(x => x.id == id);
    if (!ev) return;
    const name = ev.place_name || ev.address || '목적지';
    const lat = ev.lat, lng = ev.lng;
    if (ev.provider === 'google') {
        // 구글: origin 생략 시 현재위치 → 목적지 길찾기 (모바일/PC 모두 정상)
        const dest = (lat && lng) ? (lat + ',' + lng) : (ev.address || '');
        window.open('https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(dest), '_blank', 'noopener');
        return;
    }
    // 네이버: 모바일은 지도 앱 딥링크(폰 실제 GPS로 현재위치 출발), 그 외는 웹 검색
    if (isMobileView() && lat && lng) {
        openNaverRouteApp(lat, lng, name);
    } else {
        window.open(naverMapUrl(ev.address, lat, lng, ev.place_name), '_blank', 'noopener');
    }
}

// 네이버 지도 앱으로 [현재위치 → 목적지] 길찾기. 출발지 생략 → 앱이 폰 GPS 현재위치 사용.
// 앱 미설치 시 1.2초 후 웹 지도로 폴백 (앱이 떠서 페이지가 백그라운드면 폴백 안 함)
function openNaverRouteApp(lat, lng, name) {
    const dn      = encodeURIComponent(name || '목적지');
    const appname = location.hostname || 'economist.kr';
    const scheme  = `nmap://route/car?dlat=${lat}&dlng=${lng}&dname=${dn}&appname=${appname}`;
    const webFallback = naverMapUrl('', lat, lng);
    const t = Date.now();
    window.location.href = scheme;
    setTimeout(() => {
        if (Date.now() - t < 1600) window.open(webFallback, '_blank', 'noopener');
    }, 1200);
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
    const a = (ev.attendee_names || '').split(',').map(s=>s.trim()).filter(Boolean)
        .concat((ev.extra_attendees || '').split(',').map(s=>s.trim()).filter(Boolean));
    if (!a.length) return '';
    return a.length <= 1 ? a[0] : `${a[0]}+${a.length - 1}`;
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

function isMobileView(){ return document.body.classList.contains('is-mobile'); }   // UA(서버 $mobile) 단일 기준

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
            const isDraft=ev.is_draft=='1';
            const isHol=ev.is_holiday=='1'||ev.event_type==='holiday';
            const bg = isHol ? '#e74c3c' : (isDraft ? '#f0b95a' : evBgColor(ev));
            const tr = fmtTimeRange(ev, true).trim() || (ev.is_allday=='1'||ev.event_type==='allday'?'종일':'');
            const pfx = isDraft ? '⏳ ' : (ev.icon?ev.icon+' ':'');
            return `<div class="mdd-item${isDone?' done':''}" data-idx="${i}">
                <span class="mdd-bar" style="background:${bg}"></span>
                <span class="mdd-time">${esc(tr||'-')}</span>
                <span class="mdd-title">${esc(pfx+ (ev.title||'(제목없음)'))}${mapMarkLabel(ev)}${tripMark(ev)}${logMark(ev)}</span>
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

// ━━━ 🎤 음성 명령 (Web Speech API → Claude 해석 → 등록/조회/삭제) ━━━
let _recog = null, _recBusy = false;
function voiceSupported(){ return ('webkitSpeechRecognition' in window) || ('SpeechRecognition' in window); }

function voiceSetListening(on){
    _recBusy = on;
    document.getElementById('btn-voice')?.classList.toggle('listening', on);
    document.getElementById('m-mic')?.classList.toggle('listening', on);
}

function voiceStart(){
    if (!voiceSupported()){
        alert('이 브라우저는 음성 인식을 지원하지 않습니다.\n안드로이드 크롬 브라우저에서 사용해 주세요.');
        return;
    }
    if (_recBusy){ try{ _recog && _recog.stop(); }catch(_){} return; }
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    const r = new SR();
    _recog = r;
    r.lang = 'ko-KR'; r.interimResults = false; r.maxAlternatives = 1;
    r.onresult = e => { const t = (e.results[0][0].transcript || '').trim(); if (t) voiceHandle(t); };
    r.onerror  = e => {
        voiceSetListening(false);
        if (e.error === 'not-allowed' || e.error === 'service-not-allowed')
            alert('마이크 권한이 필요합니다. 브라우저 설정에서 마이크를 허용해 주세요.');
        else if (e.error === 'no-speech') showToast('음성이 인식되지 않았습니다. 다시 시도해 주세요.');
        else if (e.error !== 'aborted') showToast('음성 인식 오류: ' + e.error);
    };
    r.onend = () => voiceSetListening(false);
    try { r.start(); voiceSetListening(true); showToast('🎤 듣고 있어요… 말씀하세요'); }
    catch(_){ voiceSetListening(false); }
}

function voiceShow(heard, bodyHtml){
    const ov = document.getElementById('voice-overlay');
    const hd = document.getElementById('vo-heard');
    if (heard){ hd.style.display=''; hd.innerHTML = '들은 내용: <b>' + esc(heard) + '</b>'; }
    else hd.style.display='none';
    document.getElementById('vo-title').textContent = '🎤 음성 명령';
    document.getElementById('vo-body').innerHTML = bodyHtml || '';
    ov.classList.add('open');
}
function voiceBody(html){ document.getElementById('vo-body').innerHTML = html; }
function voiceClose(){ document.getElementById('voice-overlay').classList.remove('open'); }

async function voiceHandle(text){
    voiceShow(text, '<div class="vo-loading">🤖 명령을 분석하는 중…</div>');
    const res = await api('voice', { text }, 'POST');
    if (!res || !res.ok){
        voiceBody('<div class="vo-err">' + esc((res && res.msg) || '분석에 실패했습니다.') + '</div>');
        return;
    }
    const p = res.parsed || {};
    if (p.intent === 'create'){
        voiceCreateChoice(text, p, res.conflicts || []);
    } else if (p.intent === 'find'){
        voiceRenderList('find', res.candidates || [], p);
    } else if (p.intent === 'delete'){
        voiceRenderList('delete', res.candidates || [], p);
    } else {
        voiceBody('<div class="vo-err">무슨 작업인지 이해하지 못했어요.<br>'
            + '예) "내일 오후 3시 치과 예약 등록", "이번 주 일정 찾아줘", "금요일 회의 삭제"</div>');
    }
}

// 등록 인식 후: [지금 입력] vs [입력대기로 저장] 선택 화면 (+ 시간 충돌 경고)
function voiceCreateChoice(heard, p, conflicts){
    conflicts = conflicts || [];
    const date  = (p.date && /^\d{4}-\d{2}-\d{2}$/.test(p.date)) ? p.date : ymd(new Date());
    const isAll = !!p.is_allday || !p.start_time;
    const st    = (p.start_time && /^\d{1,2}:\d{2}$/.test(p.start_time)) ? p.start_time : null;
    const when  = date + ((isAll || !st) ? ' · 종일' : ' · ' + st);
    let warn = '';
    if (conflicts.length){
        const lines = conflicts.slice(0,5).map(ev =>
            '· ' + esc((fmtTimeRange(ev, true).trim() || '종일') + ' ' + (ev.title || '(제목없음)'))).join('<br>');
        const more = conflicts.length > 5 ? '<br>… 외 ' + (conflicts.length - 5) + '건' : '';
        warn = '<div class="vo-warn">⚠️ <b>해당 시간에 일정이 있습니다</b><div class="vo-warn-list">'
             + lines + more + '</div></div>';
    }
    voiceShow(heard,
        '<div class="vo-sum"><b>' + esc(p.title || '(제목 없음)') + '</b>'
      +   '<div class="vo-it-sub">' + esc(when) + '</div></div>'
      + warn
      + '<div class="vo-choice">'
      +   '<button class="btn btn-primary" id="vo-fill-now">📝 지금 입력</button>'
      +   '<button class="btn" id="vo-save-draft">⏳ 입력대기로 저장</button>'
      + '</div>'
      + '<div class="vo-hint">입력대기로 저장하면 제목·시간만 캘린더에 ⏳로 올라가고, '
      +   '나중에 그 일정을 탭해서 나머지를 채우면 정식 등록됩니다.</div>');
    document.getElementById('vo-fill-now').onclick = () => {
        voiceClose(); voiceFillCreate(p); showToast('🎤 인식: ' + heard);
    };
    document.getElementById('vo-save-draft').onclick = () => voiceSaveDraft(p);
}

// 입력대기(draft) 즉시 저장 — 제목+시간만, 모달 없이
async function voiceSaveDraft(p){
    const date  = (p.date && /^\d{4}-\d{2}-\d{2}$/.test(p.date)) ? p.date : ymd(new Date());
    const isAll = !!p.is_allday || !p.start_time;
    const st    = (p.start_time && /^\d{1,2}:\d{2}$/.test(p.start_time)) ? p.start_time : null;
    let start_dt, end_dt, is_allday;
    if (isAll || !st){
        is_allday = 1; start_dt = date + ' 00:00:00'; end_dt = null;
    } else {
        is_allday = 0; start_dt = date + ' ' + st + ':00';
        const d = new Date(date + 'T' + st); d.setHours(d.getHours() + 1);
        end_dt = ymdhm(d).replace('T', ' ') + ':00';   // draft도 1시간 기본 종료(주/일뷰 렌더용, 겹침조정 제외)
    }
    const payload = {
        event_type: 'timed', title: p.title || '(제목 없음)',
        is_draft: 1, is_allday, start_dt, end_dt,
        color: '#f0b95a', force: true,   // 빠른 캡처 → 중복확인 생략
    };
    const res = await api('create', payload, 'POST');
    if (res && !res.ok){ voiceBody('<div class="vo-err">저장 실패: ' + esc(res.msg || '') + '</div>'); return; }
    voiceClose();
    showToast('⏳ 입력대기로 저장했어요 — 캘린더에서 탭해 완성하세요');
    const d = new Date(date + 'T00:00:00');   // 저장한 달로 이동 후 갱신
    S.year = d.getFullYear(); S.month = d.getMonth() + 1;
    loadEvents();
}

// 등록: 기존 일정 모달에 값 채워 열기 → 사용자가 확인·수정 후 저장
function voiceFillCreate(p){
    const date  = (p.date && /^\d{4}-\d{2}-\d{2}$/.test(p.date)) ? p.date : ymd(new Date());
    const isAll = !!p.is_allday || !p.start_time;
    const st    = (p.start_time && /^\d{1,2}:\d{2}$/.test(p.start_time)) ? p.start_time : '09:00';
    openNew(date + 'T' + st, 'timed');
    document.getElementById('f-title').value = p.title || '';
    setAllday(isAll);
    if (!isAll){
        document.getElementById('f-start').value = date + 'T' + st;
        if (p.end_time && /^\d{1,2}:\d{2}$/.test(p.end_time)){
            document.getElementById('f-end').value = date + 'T' + p.end_time;
        } else {
            const d = new Date(date + 'T' + st); d.setHours(d.getHours() + 1);
            document.getElementById('f-end').value = ymdhm(d);
        }
    }
    document.getElementById('f-title').focus();
}

// 조회/삭제: 후보 일정 목록 표시 (탭→상세, 삭제버튼→확인 후 삭제)
function voiceRenderList(mode, list, p){
    document.getElementById('vo-title').textContent = mode === 'find' ? '🔍 검색 결과' : '🗑 삭제할 일정 선택';
    const kw = (p.keyword || '').trim();
    if (!list.length){
        voiceBody('<div class="vo-empty">해당하는 일정이 없습니다.' + (kw ? ' (검색어: ' + esc(kw) + ')' : '') + '</div>');
        return;
    }
    let h = '<div class="vo-sub">' + (kw ? '"' + esc(kw) + '" ' : '') + list.length + '건' + '</div>';
    h += list.map((ev, i) => {
        const ds = (ev.start_dt || ev.due_dt || '').slice(0, 10);
        const tr = fmtTimeRange(ev).trim();
        return '<div class="vo-item" data-idx="' + i + '">'
            +   '<div class="vo-it-main"><b>' + esc((ev.icon ? ev.icon + ' ' : '') + (ev.title || '(제목없음)')) + '</b>'
            +     '<div class="vo-it-sub">' + ds + (tr ? ' · ' + esc(tr) : '') + (ev.is_recur_instance == '1' ? ' · 🔁반복' : '') + '</div></div>'
            +   (mode === 'delete' ? '<button class="vo-del" data-idx="' + i + '">삭제</button>' : '')
            + '</div>';
    }).join('');
    voiceBody(h);

    const box = document.getElementById('vo-body');
    box.querySelectorAll('.vo-item').forEach(el => {
        el.addEventListener('click', e => {
            if (e.target.classList.contains('vo-del')) return;
            const ev = list[+el.dataset.idx];
            if (ev){ voiceClose(); openView(ev); }
        });
    });
    box.querySelectorAll('.vo-del').forEach(btn => {
        btn.addEventListener('click', async () => {
            const ev = list[+btn.dataset.idx];
            if (ev) await voiceDelete(ev, btn);
        });
    });
}

async function voiceDelete(ev, btn){
    const ds = (ev.start_dt || ev.due_dt || '').slice(0, 10);
    let scope = null;
    if (ev.is_recur_instance == '1'){
        // 반복 일정: 이 날짜만 vs 전체 시리즈 선택
        scope = confirm('"' + ev.title + '"은(는) 반복 일정입니다.\n\n확인 = 이 날짜(' + ds + ') 하나만 삭제\n취소 = 전체 반복 일정 삭제') ? 'one' : 'all';
    } else {
        if (!confirm('"' + ev.title + '" (' + ds + ') 일정을 삭제할까요?')) return;
    }
    let res;
    if (scope) res = await api('delete_scoped', { id: ev.id, scope, origin_dt: ds }, 'POST');
    else       res = await api('delete', { id: ev.id });
    if (res && !res.ok){ alert('삭제 실패: ' + (res.msg || '')); return; }
    btn.closest('.vo-item')?.remove();
    loadEvents();
    showToast('🗑 삭제되었습니다.');
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

// 시간 범위 포맷: "(종일)" 또는 "(HH:MM~HH:MM)"
function fmtTimeRange(ev, full) {
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
    if (full) {   // 모바일 하단 상세 등: 시작~종료 풀 표시
        const e = ev.end_dt ? ev.end_dt.slice(11,16) : '';
        const validEnd = e && e!=='00:00' && e!=='23:59';
        return validEnd ? `(${s}~${e}) ` : `(${s}~) `;
    }
    return `(${s}~) `;   // 데스크톱 칩: 시작만(공간 절약)
}
// 지도 마크(장소명 포함) — 모바일 하단 상세 패널용
function mapMarkLabel(ev) {
    if (!(ev.address && String(ev.address).trim())) return '';
    const label = ev.place_name ? ' ' + esc(ev.place_name) : '';
    return ` <span class="chip-map-mark" onclick="openMapFromChip(event, ${ev.id})" title="지도 열기">📍${label}</span>`;
}

async function api(action, payload={}, method='GET', module='calendar') {
    const base='/schedule_api.php';
    try {
        if (method==='GET') {
            const q=new URLSearchParams(Object.assign({module,action},payload)).toString();
            return await (await fetch(`${base}?${q}`,{cache:'no-store'})).json();
        }
        return await (await fetch(`${base}?module=${encodeURIComponent(module)}&action=${encodeURIComponent(action)}`,{
            method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)
        })).json();
    } catch(e) { console.error('API error',module,action,e); return {ok:false,data:[]}; }
}

// ===== 메모 이미지 첨부 (클립보드 붙여넣기 / 파일 선택) — DB(BLOB) 저장 =====
// MODAL_ATTACH 항목: 기존={id,w,h}(DB에 있음) / 신규={blob}(저장 후 multipart 업로드)
let MODAL_ATTACH = [];

const ATTACH_IMG = id => `/schedule_api.php?module=attach&action=img&id=${id}`;
function attachSrc(a){
    if (a.blob)    return a._url || (a._url = URL.createObjectURL(a.blob));  // 신규(원본 blob) 미리보기
    if (a.dataURL) return a.dataURL;                                         // 레거시 호환
    return ATTACH_IMG(a.id);                                                 // 기존(DB 저장분)
}

function renderAttachList(){
    const box = document.getElementById('f-attach-list');
    if (!box) return;
    box.innerHTML = '';
    MODAL_ATTACH.forEach((a, i) => {
        const src = attachSrc(a);
        const d = document.createElement('div');
        d.className = 'f-att-thumb';
        d.innerHTML = `<img src="${esc(src)}" alt=""><button type="button" class="f-att-x" title="삭제">×</button>`;
        d.querySelector('img').onclick = () => openLightbox(src);
        d.querySelector('.f-att-x').onclick = () => {
            const rm = MODAL_ATTACH.splice(i, 1)[0];
            if (rm && rm._url) URL.revokeObjectURL(rm._url);
            renderAttachList();
        };
        box.appendChild(d);
    });
}

// ★화질 보존: multipart로 큰 파일도 올라가므로 KEEP_LIMIT 이하는 원본 그대로 업로드(축소·재인코딩 없음).
//   초대형(>6MB)/미지원 형식만 캔버스로 축소해 6MB 안쪽으로 맞춤(DB max_allowed_packet 16MB 대비 여유).
const ATTACH_KEEP_LIMIT = 6 * 1024 * 1024;
const ATTACH_TYPES = ['image/png','image/jpeg','image/gif','image/webp'];

function loadImageEl(blob){
    return new Promise((resolve, reject) => {
        const img = new Image(); const u = URL.createObjectURL(blob);
        img.onload  = () => resolve({ img, revoke: () => URL.revokeObjectURL(u) });
        img.onerror = () => { URL.revokeObjectURL(u); reject(new Error('이미지 로드 실패')); };
        img.src = u;
    });
}

async function attachPrepare(blob){
    // 지원 형식 + 용량 여유 → 원본 그대로 (화질 손실 없음)
    if (ATTACH_TYPES.includes(blob.type) && blob.size <= ATTACH_KEEP_LIMIT) return blob;
    // 그 외 → 축소/재인코딩(JPEG)해서 한도 안쪽으로
    const { img, revoke } = await loadImageEl(blob);
    try {
        let maxSide = 4000, last = null;
        for (let k = 0; k < 5; k++){
            let w = img.width, h = img.height;
            if (w > maxSide || h > maxSide){ const r = Math.min(maxSide/w, maxSide/h); w = Math.round(w*r); h = Math.round(h*r); }
            const cv = document.createElement('canvas'); cv.width = w; cv.height = h;
            cv.getContext('2d').drawImage(img, 0, 0, w, h);
            last = await new Promise(res => cv.toBlob(res, 'image/jpeg', Math.max(0.5, 0.9 - k*0.12)));
            if (last && last.size <= ATTACH_KEEP_LIMIT) return last;
            maxSide = Math.round(maxSide * 0.8);
        }
        return last || blob;
    } finally { revoke(); }
}

// 붙여넣기/선택 이미지를 (서버 전송 없이) 모달 목록에 추가 — 저장 시 업로드
async function addAttachBlob(blob){
    const box = document.getElementById('f-attach-list');
    const ph = document.createElement('div'); ph.className = 'f-att-thumb loading'; ph.textContent = '…';
    if (box) box.appendChild(ph);
    try {
        const finalBlob = await attachPrepare(blob);
        MODAL_ATTACH.push({ blob: finalBlob });
    } catch(e){ showToast('이미지 처리 실패'); }
    finally { renderAttachList(); }   // placeholder 제거 + 목록 갱신
}

function attachFromFiles(files){
    [...files].filter(f => f.type.startsWith('image/')).forEach(addAttachBlob);
}

// 새 이미지 첨부를 개별 multipart 업로드 (JSON 저장에서 분리 — openresty가 128KB 초과 JSON 본문을 차단)
async function uploadPendingAttachments(scheduleId){
    const news = MODAL_ATTACH.filter(a => a.blob);
    for (const a of news){
        const t = a.blob.type;
        const ext = t === 'image/png' ? 'png' : t === 'image/webp' ? 'webp' : t === 'image/gif' ? 'gif' : 'jpg';
        const fd = new FormData();
        fd.append('schedule_id', scheduleId);
        fd.append('file', a.blob, 'attach.' + ext);
        let j;
        try {
            const r = await fetch('/schedule_api.php?module=attach&action=upload', { method:'POST', body: fd });
            j = await r.json();
        } catch(e){ return { ok:false, msg:'이미지 전송 실패' }; }
        if (!j || !j.ok) return { ok:false, msg:(j && j.msg) || '이미지 저장 실패' };
    }
    return { ok:true };
}

// 메모창 붙여넣기 → 클립보드 이미지만 가로채 첨부 (텍스트 붙여넣기는 그대로)
function attachPasteHandler(e){
    const items = (e.clipboardData && e.clipboardData.items) || [];
    const imgs = [...items].filter(it => it.kind === 'file' && it.type.startsWith('image/'));
    if (!imgs.length) return;
    e.preventDefault();
    imgs.forEach(it => { const b = it.getAsFile(); if (b) addAttachBlob(b); });
}

// ── 이미지 라이트박스: 휠 확대/축소(커서 중심) · 드래그 이동 · 클릭 닫기 ──
// ★확대는 CSS scale이 아니라 실제 표시 width를 키움 → 브라우저가 원본에서 다시 렌더 = 선명
//   (좌상단 절대배치 모델: tx/ty=이미지 좌상단 화면좌표, 표시폭 = fitW*zoom)
let LB = { natW:0, natH:0, fitW:0, fitH:0, zoom:1, min:1, max:8, tx:0, ty:0,
           bound:false, down:false, moved:false, sx:0, sy:0, stx:0, sty:0 };
function lbImg(){ return document.querySelector('#img-lightbox img'); }
function lbApply(){
    const img = lbImg(); if (!img) return;
    img.style.width = (LB.fitW * LB.zoom) + 'px';
    img.style.transform = `translate(${LB.tx}px,${LB.ty}px)`;
    img.classList.toggle('zoomed', LB.zoom > 1.001);
}
function lbComputeFit(){
    const vw = window.innerWidth * 0.94, vh = window.innerHeight * 0.94;
    const fitScale = Math.min(vw / LB.natW, vh / LB.natH, 1);   // 화면보다 크면 축소, 작으면 원본
    LB.fitW = LB.natW * fitScale;
    LB.fitH = LB.natH * fitScale;
    LB.min  = 1;
    LB.max  = Math.max(3, (LB.natW * 1.5) / LB.fitW);           // 원본의 ~150%까지 확대 허용
}
function lbCenter(){
    LB.tx = (window.innerWidth  - LB.fitW * LB.zoom) / 2;
    LB.ty = (window.innerHeight - LB.fitH * LB.zoom) / 2;
}
function lbInitView(){
    const img = lbImg(); if (!img) return;
    LB.natW = img.naturalWidth  || img.width  || 1;
    LB.natH = img.naturalHeight || img.height || 1;
    LB.zoom = 1;
    lbComputeFit();
    lbCenter();
    lbApply();
}
function closeLightbox(){ document.getElementById('img-lightbox').style.display = 'none'; }
function bindLightbox(){
    if (LB.bound) return; LB.bound = true;
    const lb  = document.getElementById('img-lightbox');
    const img = lb.querySelector('img');

    // 휠: 커서 지점을 고정한 채 확대/축소 (표시 width 변경 → 재렌더로 선명)
    lb.addEventListener('wheel', e => {
        e.preventDefault();
        if (!LB.natW) return;
        const W = LB.fitW * LB.zoom, H = LB.fitH * LB.zoom;
        const fx = (e.clientX - LB.tx) / W;     // 커서 밑 지점의 이미지 내 비율
        const fy = (e.clientY - LB.ty) / H;
        const factor = e.deltaY < 0 ? 1.2 : 1 / 1.2;
        const nz = Math.min(LB.max, Math.max(LB.min, LB.zoom * factor));
        if (nz === LB.zoom) return;
        LB.zoom = nz;
        if (LB.zoom <= 1.001) { LB.zoom = 1; lbCenter(); }
        else { LB.tx = e.clientX - fx * LB.fitW * nz; LB.ty = e.clientY - fy * LB.fitH * nz; }
        lbApply();
    }, { passive:false });

    // 드래그: 확대 상태에서 이동 (움직였으면 클릭 닫기 취소)
    img.addEventListener('mousedown', e => {
        LB.down = true; LB.moved = false;
        LB.sx = e.clientX; LB.sy = e.clientY; LB.stx = LB.tx; LB.sty = LB.ty;
        if (LB.zoom > 1.001) img.classList.add('panning');
        e.preventDefault();
    });
    window.addEventListener('mousemove', e => {
        if (!LB.down) return;
        const mdx = e.clientX - LB.sx, mdy = e.clientY - LB.sy;
        if (Math.abs(mdx) > 4 || Math.abs(mdy) > 4) LB.moved = true;
        if (LB.zoom > 1.001) { LB.tx = LB.stx + mdx; LB.ty = LB.sty + mdy; lbApply(); }
    });
    window.addEventListener('mouseup', () => { LB.down = false; img.classList.remove('panning'); });

    // 클릭: 닫기 (단, 드래그로 이동한 경우는 제외)
    lb.addEventListener('click', () => {
        if (LB.moved) { LB.moved = false; return; }
        closeLightbox();
    });
}
function openLightbox(src){
    bindLightbox();
    const lb  = document.getElementById('img-lightbox');
    const img = lb.querySelector('img');
    img.classList.remove('zoomed', 'panning');
    img.style.width = 'auto'; img.style.transform = '';
    lb.style.display = 'block';
    img.onload = lbInitView;
    img.src = src;
    if (img.complete && img.naturalWidth) lbInitView();   // 캐시된 경우 onload 미발화 대비
}

// 일정 id로 기존 첨부 메타를 불러와 수정 모달 목록 채우기
async function loadAttachInto(id){
    MODAL_ATTACH = []; renderAttachList();
    if (!id) return;
    const res = await api('list', { id }, 'GET', 'attach');
    if (res && res.ok && Array.isArray(res.data)) {
        MODAL_ATTACH = res.data.map(a => ({ id: a.id, w: a.w, h: a.h }));
        renderAttachList();
    }
}

// 보기 모달 첨부 썸네일 (일정 id로 조회)
async function renderViewAttach(ev){
    const box = document.getElementById('view-attach');
    if (!box) return;
    box.style.display = 'none'; box.innerHTML = '';
    if (!ev || !ev.id) return;
    const res = await api('list', { id: ev.id }, 'GET', 'attach');
    const list = (res && res.ok && Array.isArray(res.data)) ? res.data : [];
    if (!list.length) return;
    box.innerHTML = list.map(a => `<div class="va-thumb"><img src="${esc(ATTACH_IMG(a.id))}" alt=""></div>`).join('');
    [...box.querySelectorAll('.va-thumb img')].forEach((im,i) => im.onclick = () => openLightbox(ATTACH_IMG(list[i].id)));
    box.style.display = 'flex';
}

document.getElementById('f-memo')?.addEventListener('paste', attachPasteHandler);

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
    renderMonthHabitBar();   // 월 하단 '오늘 남은 습관' 바
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
    if (S.view==='month') el.textContent=`${S.year}년 ${MK[S.month-1]}`;
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

        // 여행 기간 막대: 이 날짜가 여행 기간(start~end)에 포함되면 표시 (읽기전용 → 클릭 시 갤러리)
        _travels.filter(t=>t.start_dt&&t.end_dt&&ds>=t.start_dt&&ds<=t.end_dt)
            .forEach(t=>{
                const isStart=ds===t.start_dt, isEnd=ds===t.end_dt;
                const bar=document.createElement('div'); bar.className='travel-bar';
                bar.style.background=t.color||'#e67e22';
                bar.style.borderRadius=`${isStart?'4px':'0'} ${isEnd?'4px':'0'} ${isEnd?'4px':'0'} ${isStart?'4px':'0'}`;
                bar.textContent=isStart?((t.icon||'🧳')+t.title):'';   // 시작일에만 제목
                bar.title=t.title+' — 클릭하면 여행 갤러리 열기';
                bar.onclick=e=>{e.stopPropagation(); window.location.href='/travel.php?mode=view&id='+t.id;};
                cell.appendChild(bar);
            });

        // 모바일: 점 표시 / 데스크톱: 칩 전부 렌더 후 applyMonthChipLimit()가 높이 기반으로 동적 제한
        const mob=isMobileView();
        const makeChip = (ev) => {
            const chip=document.createElement('div');
            const isDone=ev.is_done=='1';
            const isDraft=ev.is_draft=='1';
            chip.className='event-chip'+(isDone?' done':'')+(isDraft?' draft':'');
            let dotHtml='';
            if (!isDone && !isDraft) {
                if (mob) {
                    chip.style.background = evBgColor(ev);   // 모바일: 점 = 솔리드 색(선명)
                } else {
                    const c = softChip(evBgColor(ev));       // 데스크톱: 파스텔 칩 + 점
                    chip.style.background = c.bg;
                    chip.style.color = c.fg;
                    dotHtml = `<span class="ev-dot" style="background:${c.dot}"></span>`;
                }
            }
            const draftPfx = isDraft ? '⏳ ' : '';
            chip.innerHTML=dotHtml+'<span class="ev-tx">'+projNumBadge(ev)+esc(draftPfx+(isDone?'✓ ':'')+fmtTimeRange(ev)+evLabel(ev))+'</span>'+mapMark(ev)+tripMark(ev)+logMark(ev);
            // 입력대기는 탭하면 바로 편집(나머지 채우기) → 저장 시 정식 등록으로 전환
            chip.onclick=e=>{e.stopPropagation(); if(isMobileView()){showDayDetail(ds);} else if(isDraft){openEdit(ev);} else {openView(ev);}};
            return chip;
        };
        regularEvs.forEach(ev => cell.appendChild(makeChip(ev)));
        // 습관 점 — 과거·오늘만(미래 제외). 완료=옅은회색 점 / 오늘 미완료=주황 빈점(대기) / 과거 미완료=빨간 ✕(실패).
        if (ds <= todayStr()) {
            const schedH = (TODO_HABITS||[]).filter(hb=>habitScheduled(hb, date));
            if (schedH.length) {
                const isToday = (ds === todayStr());
                const states = schedH.map(hb => doneOn(hb, ds) ? 'done' : (isToday ? 'pending' : 'fail'));
                const order = {fail:0, pending:1, done:2};
                states.sort((a,b)=>order[a]-order[b]);   // 실패 > 대기 > 완료 순 (중요한 것 먼저 노출)
                const wrap=document.createElement('div'); wrap.className='hab-dots';
                const MAXD=4;
                states.slice(0,MAXD).forEach(st=>{
                    const s=document.createElement('span');
                    if (st==='fail'){ s.className='hab-x'; s.textContent='✕'; }
                    else { s.className='hab-dot '+st; }
                    wrap.appendChild(s);
                });
                if (states.length>MAXD){ const m=document.createElement('span'); m.className='hab-more'; m.textContent='+'+(states.length-MAXD); wrap.appendChild(m); }
                cell.appendChild(wrap);
            }
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
    } else {
        requestAnimationFrame(() => applyMonthChipLimit());
    }
}
function applyMonthChipLimit() {
    if (S.view !== 'month' || isMobileView()) return;
    const grid = document.getElementById('cal-grid');
    if (!grid) return;
    // 기존 더보기 버튼 제거 + 숨겨진 칩 전부 다시 보이기 (재측정용)
    grid.querySelectorAll('.more-link').forEach(e => e.remove());
    grid.querySelectorAll('.event-chip').forEach(c => c.style.display = '');
    const cells = [...grid.querySelectorAll('.cal-cell')];
    if (!cells.length) return;
    // 모든 행은 grid-auto-rows:1fr → 동일 높이
    const cellH = cells[0].getBoundingClientRect().height;
    if (cellH <= 0) return;
    // 칩 높이 측정 (첫 번째 칩 기준, margin-bottom 2px 포함)
    let chipH = 20;
    for (const cell of cells) {
        const chip = cell.querySelector('.event-chip');
        if (chip) { const h = chip.getBoundingClientRect().height; if (h > 0) { chipH = h + 2; break; } }
    }
    cells.forEach(cell => {
        const chips = [...cell.querySelectorAll('.event-chip')];
        if (!chips.length) return;
        // 이 셀에서 칩에 쓸 수 있는 높이
        const headerH = (cell.querySelector('.cell-header')?.getBoundingClientRect().height ?? 22) + 3;
        const barsH = [...cell.querySelectorAll('.proj-bar')]
                        .reduce((s, b) => s + b.getBoundingClientRect().height + 2, 0);
        const available = cellH - headerH - barsH - 8; // 8 = 셀 상하 패딩
        // 전부 들어가면 더보기 불필요
        if (chips.length * chipH <= available) return;
        // 더보기 버튼 한 줄 남기고 최대 표시 수 계산
        const maxVisible = Math.max(1, Math.floor((available - chipH) / chipH));
        if (maxVisible >= chips.length) return;
        chips.slice(maxVisible).forEach(c => c.style.display = 'none');
        const more = document.createElement('div');
        more.className = 'more-link';
        more.textContent = `+${chips.length - maxVisible}개 더보기`;
        const hiddenChips = chips.slice(maxVisible);
        more.onclick = e => {
            e.stopPropagation();
            more.remove();
            hiddenChips.forEach(c => c.style.display = '');
        };
        cell.appendChild(more);
    });
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

// 색상(hex) → 소프트 파스텔 톤 {bg:밝은배경, tx:진한글자, bd:원색보더}
function wkSoft(hex) {
    hex = (hex||'#3b68f5').trim();
    let m = /^#?([0-9a-fA-F]{6})$/.exec(hex) || /^#?([0-9a-fA-F]{3})$/.exec(hex);
    let r, g, b, full;
    if (m) {
        let s = m[1]; if (s.length===3) s = s.split('').map(x=>x+x).join('');
        r = parseInt(s.slice(0,2),16); g = parseInt(s.slice(2,4),16); b = parseInt(s.slice(4,6),16);
        full = '#'+s;
    } else { r=59; g=104; b=245; full='#3b68f5'; }
    const mix = (t,ra)=>`rgb(${Math.round(r*(1-ra)+t[0]*ra)},${Math.round(g*(1-ra)+t[1]*ra)},${Math.round(b*(1-ra)+t[2]*ra)})`;
    return { bg: mix([255,255,255],.86), tx: mix([22,28,46],.42), bd: full };
}

function renderWeek() {
    const c=document.getElementById('view-week');
    const DK=['일','월','화','수','목','금','토'];
    const days=Array.from({length:7},(_,i)=>addDays(S.weekStart,i));
    const CELL_H=58;
    const todayIdx=days.findIndex(d=>sameDay(d,TODAY));

    // 요일 헤더
    let h='<div class="week-wrap"><div class="week-head-row"><div class="week-head-gut"></div>';
    days.forEach((d,i)=>{
        const dow=d.getDay();
        const cls=(dow===0?'sun':dow===6?'sat':'')+(i===todayIdx?' today-col':'');
        h+=`<div class="week-head ${cls}"><div class="wkd">${DK[dow]}</div><div class="wkn">${d.getDate()}</div></div>`;
    });
    h+='</div>';

    // 상단 레인 렌더 헬퍼 (공휴일/기념일/할일/종일)
    const topRow=(label, filter, icon2)=>{
        let r=`<div class="week-allday-row"><div class="week-allday-label">${label}</div>`;
        days.forEach((d,i)=>{
            const ds=ymd(d);
            const evs=S.events.filter(e=>filter(e)&&(e.start_dt||e.due_dt||'').startsWith(ds));
            r+=`<div class="week-allday-cell${i===todayIdx?' today-col':''}">`+evs.map(ev=>{
                const isHol=ev.is_holiday==1||ev.event_type==='holiday';
                const done=ev.is_done==1||ev.is_done=='1';
                const cls='allday-chip wk-chip'+(isHol?' holiday':'')+(done?' done':'');
                let sty='';
                if (!isHol&&!done) { const s=wkSoft(evBgColor(ev)); sty=`background:${s.bg};color:${s.tx};border-left:3px solid ${s.bd}`; }
                const ico=isHol?'':(icon2[ev.event_type]||'');
                const cat=(!isHol&&ev.event_type==='anniversary'&&ev.category)?'['+ev.category+'] ':'';
                return `<div class="${cls}" style="${sty}" data-id="${ev.id}">${isHol?'':projNumBadge(ev)}${esc((done?'✓ ':'')+ico+cat+evLabel(ev))}${mapMark(ev)}${tripMark(ev)}${logMark(ev)}</div>`;
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
                ? `background:${p.color||'#3498db'};color:${contrastColor(p.color||'#3498db')};border-radius:${isStart?'5px':'0'} ${isEnd?'5px':'0'} ${isEnd?'5px':'0'} ${isStart?'5px':'0'};`
                : '';
            const nameTxt=showName?`<span class="proj-week-name">${esc((p.icon||'')+p.title)}</span>`:'';
            h+=`<div class="week-allday-cell proj-week-cell${inR?' in-range':''}${i===todayIdx?' today-col':''}" style="${sty}" ${inR?`data-proj="${p.id}" data-date="${ds}"`:''}>${nameTxt}</div>`;
        });
        h+=`</div>`;
    });

    // 시간 그리드 (목업식: 컬럼별 절대배치 + 그라데이션 그리드선)
    h+='<div class="wk-gridscroll"><div class="wk-timegrid">';
    h+='<div class="wk-times">';
    for (let hr=0;hr<24;hr++) { h+=`<div class="wk-thour">${hr>0?`<span>${pad(hr)}:00</span>`:''}</div>`; }
    h+='</div>';
    const now=new Date();
    days.forEach((d,i)=>{
        const ds=ymd(d);
        const isToday=i===todayIdx;
        h+=`<div class="wk-col${isToday?' today-col':''}" data-date="${ds}">`;
        if (isToday) { const tp=(now.getHours()+now.getMinutes()/60)*CELL_H; h+=`<div class="wk-now" style="top:${tp}px"></div>`; }
        const dayEvs=S.events.filter(e=>isTimedEv(e)&&(e.start_dt||'').startsWith(ds));
        dayEvs.forEach(ev=>{
            const sd=new Date(ev.start_dt);
            const ed=ev.end_dt?new Date(ev.end_dt):null;
            const topPx=(sd.getHours()+sd.getMinutes()/60)*CELL_H;
            const durMin=ed?(ed-sd)/60000:60;
            const hPx=Math.max(durMin/60*CELL_H, 24);
            const done=ev.is_done==1||ev.is_done=='1';
            let sty='';
            if (!done) { const s=wkSoft(evBgColor(ev)); sty=`background:${s.bg};color:${s.tx};border-left:3px solid ${s.bd};`; }
            const sT=ev.start_dt.slice(11,16), eT=ev.end_dt?ev.end_dt.slice(11,16):'';
            const validEnd=eT&&eT!=='00:00'&&eT!=='23:59';
            const tm=esc(validEnd?`${sT} ~ ${eT}`:sT);
            const tt=projNumBadge(ev)+esc((done?'✓ ':'')+evLabel(ev))+mapMark(ev)+tripMark(ev)+logMark(ev);
            h+=`<div class="wk-tev${done?' done':''}" style="${sty}top:${topPx+1}px;height:${hPx-3}px;" data-id="${ev.id}"><div class="wk-tm">${tm}</div><div class="wk-tt">${tt}</div></div>`;
        });
        h+='</div>';
    });
    h+='</div></div></div>'; c.innerHTML=h;

    c.querySelectorAll('.wk-tev,.allday-chip').forEach(el=>el.addEventListener('click',e=>{e.stopPropagation();const ev=S.events.find(x=>x.id==el.dataset.id);if(ev)openView(ev);}));
    // 빈 시간 컬럼 클릭 → 클릭 높이로 시각 계산해 새 일정
    c.querySelectorAll('.wk-col').forEach(el=>el.addEventListener('click',e=>{
        if(e.target.closest('.wk-tev')||e.target.closest('.wk-hev'))return;
        const rect=el.getBoundingClientRect();
        const hr=Math.max(0,Math.min(23,Math.floor((e.clientY-rect.top)/CELL_H)));
        openNew(`${el.dataset.date}T${pad(hr)}:00`);
    }));
    // 프로젝트 색칸 클릭 → 그 날짜로 일정 추가 (프로젝트 자동 고정)
    c.querySelectorAll('.proj-week-cell.in-range').forEach(el=>{
        el.style.cursor='pointer';
        el.addEventListener('click',e=>{e.stopPropagation();openNew(`${el.dataset.date}T09:00`);});
    });
    const scroll=c.querySelector('.wk-gridscroll');
    if(scroll) scroll.scrollTop=Math.max(new Date().getHours()*CELL_H-80,0);
}

function renderDay() {
    const c=document.getElementById('view-day'), ds=ymd(S.day);
    const CELL_H=58;
    const isToday=sameDay(S.day,TODAY);

    // 상단 행 헬퍼 (일간용)
    const topRowDay=(label, filter, iconMap)=>{
        const evs=S.events.filter(e=>filter(e)&&(e.start_dt||e.due_dt||'').startsWith(ds));
        if (!evs.length) return '';
        let r=`<div class="day-allday-row"><div class="day-allday-label">${label}</div>`;
        r+=evs.map(ev=>{
            const isHol=ev.is_holiday==1||ev.event_type==='holiday';
            const done=ev.is_done==1||ev.is_done=='1';
            const cls='allday-chip wk-chip'+(isHol?' holiday':'')+(done?' done':'');
            let sty='';
            if (!isHol&&!done) { const s=wkSoft(evBgColor(ev)); sty=`background:${s.bg};color:${s.tx};border-left:3px solid ${s.bd}`; }
            const ico=isHol?'':(iconMap[ev.event_type]||'');
            const cat=(!isHol&&ev.event_type==='anniversary'&&ev.category)?'['+ev.category+'] ':'';
            return `<div class="${cls}" style="${sty}" data-id="${ev.id}">${isHol?'':projNumBadge(ev)}${esc((done?'✓ ':'')+ico+cat+evLabel(ev))}${mapMark(ev)}${tripMark(ev)}${logMark(ev)}</div>`;
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
            const cls='allday-chip wk-chip'+(isHol?' holiday':'');
            let sty='';
            if (!isHol) { const s=wkSoft(ev.color); sty=`background:${s.bg};color:${s.tx};border-left:3px solid ${s.bd}`; }
            return `<div class="${cls}" style="${sty}" data-id="${ev.id}">${esc(ev.title)}</div>`;
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
                <div class="allday-chip proj-day-chip" style="background:${bg};color:${contrastColor(bg)};border-radius:7px;font-weight:700;" data-proj="${p.id}" data-date="${ds}">${esc((p.icon||'')+p.title)}</div>
            </div>`;
        });

    // 시간 그리드 (주간과 동일 wk-* 구조, 단일 컬럼)
    h+='<div class="wk-gridscroll"><div class="wk-timegrid" style="grid-template-columns:var(--wk-gutter) 1fr">';
    h+='<div class="wk-times">';
    for (let hr=0;hr<24;hr++) { h+=`<div class="wk-thour">${hr>0?`<span>${pad(hr)}:00</span>`:''}</div>`; }
    h+='</div>';
    h+=`<div class="wk-col day-col${isToday?' today-col':''}" data-date="${ds}">`;
    if (isToday) { const now=new Date(); const tp=(now.getHours()+now.getMinutes()/60)*CELL_H; h+=`<div class="wk-now" style="top:${tp}px"></div>`; }
    const dayEvs=S.events.filter(e=>isTimedEv(e)&&(e.start_dt||'').startsWith(ds));
    dayEvs.forEach(ev=>{
        const sd=new Date(ev.start_dt);
        const ed=ev.end_dt?new Date(ev.end_dt):null;
        const topPx=(sd.getHours()+sd.getMinutes()/60)*CELL_H;
        const durMin=ed?(ed-sd)/60000:60;
        const hPx=Math.max(durMin/60*CELL_H, 24);
        const done=ev.is_done==1||ev.is_done=='1';
        let sty='';
        if (!done) { const s=wkSoft(evBgColor(ev)); sty=`background:${s.bg};color:${s.tx};border-left:3px solid ${s.bd};`; }
        const sT=ev.start_dt.slice(11,16), eT=ev.end_dt?ev.end_dt.slice(11,16):'';
        const validEnd=eT&&eT!=='00:00'&&eT!=='23:59';
        const tm=esc(validEnd?`${sT} ~ ${eT}`:sT);
        const tt=projNumBadge(ev)+esc((done?'✓ ':'')+evLabel(ev))+mapMark(ev)+tripMark(ev)+logMark(ev);
        h+=`<div class="wk-tev${done?' done':''}" style="${sty}top:${topPx+1}px;height:${hPx-3}px;" data-id="${ev.id}"><div class="wk-tm">${tm}</div><div class="wk-tt">${tt}</div></div>`;
    });
    // 습관 타임라인 블록 — do_time 있고 이 날짜에 예정된 습관 (점선·체크)
    const _hd=new Date(ds+'T00:00:00');
    (TODO_HABITS||[]).forEach(hb=>{
        if(!hb.do_time || !habitScheduled(hb,_hd)) return;
        const hm=String(hb.do_time).slice(0,5), pp=hm.split(':');
        const hTop=((+pp[0])+(+pp[1]||0)/60)*CELL_H;
        const durMin=+hb.duration_min>0?+hb.duration_min:60;
        const hHt=Math.max(durMin/60*CELL_H, 26);
        const hdone=doneOn(hb,ds);
        h+=`<div class="wk-hev${hdone?' done':''}" style="top:${hTop+1}px;height:${hHt-3}px;" onclick="openTodoModal('habit',${hb.id})">`
          +`<div class="wk-hm">${hm}</div>`
          +`<div class="wk-htt"><span class="wk-hchk" onclick="habitBlockToggle(event,${hb.id},'${ds}')">${hdone?'☑':'☐'}</span>${esc((hb.icon||'')+hb.title)}<span class="wk-hbadge">습관</span></div>`
          +`</div>`;
    });
    h+='</div></div></div></div>'; c.innerHTML=h;

    c.querySelectorAll('.wk-tev,.allday-chip').forEach(el=>el.addEventListener('click',e=>{e.stopPropagation();const ev=S.events.find(x=>x.id==el.dataset.id);if(ev)openView(ev);}));
    // 빈 시간 컬럼 클릭 → 클릭 높이로 시각 계산
    c.querySelectorAll('.wk-col').forEach(el=>el.addEventListener('click',e=>{
        if(e.target.closest('.wk-tev')||e.target.closest('.wk-hev'))return;
        const rect=el.getBoundingClientRect();
        const hr=Math.max(0,Math.min(23,Math.floor((e.clientY-rect.top)/CELL_H)));
        openNew(`${ds}T${pad(hr)}:00`);
    }));
    // 프로젝트 칩 클릭 → 그 날짜로 일정 추가 (프로젝트 자동 고정)
    c.querySelectorAll('.proj-day-chip').forEach(el=>el.addEventListener('click',e=>{e.stopPropagation();openNew(`${el.dataset.date}T09:00`);}));
    const scroll=c.querySelector('.wk-gridscroll');
    if(scroll) scroll.scrollTop=Math.max(new Date().getHours()*CELL_H-80,0);
}

function renderList() {
    const c=document.getElementById('view-list');
    if (!S.events.length){c.innerHTML='<p style="padding:20px;color:#999">올해 일정이 없습니다.</p>';return;}
    const rows=S.events.map(ev=>`<tr class="${ev.is_done=='1'?'done':''}" style="cursor:pointer" data-id="${ev.id}">
        <td><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:${ev.color};margin-right:6px"></span>${projNumBadge(ev)}${esc(evLabel(ev))}${mapMark(ev)}${tripMark(ev)}${logMark(ev)}</td>
        <td>${ev.start_dt.slice(0,16).replace('T',' ')}</td>
        <td>${ev.category}</td>
        <td style="text-align:center">${ev.is_done=='1'?'✔':'—'}</td>
    </tr>`).join('');
    c.innerHTML=`<table class="list-table"><thead><tr><th>제목</th><th>시작일시</th><th>카테고리</th><th>완료</th></tr></thead><tbody>${rows}</tbody></table>`;
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
    MODAL_ATTACH=[]; renderAttachList();
    document.getElementById('f-cat').value='업무';
    setColor('#3498db');
    closeColorPicker();
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
    SEL_EXTRA = [];
    document.getElementById('f-attendee-input').value = '';
    if (typeof attAcClose === 'function') attAcClose();
    renderAttendeeChips();
    // 위치 초기화
    resetLocationForm();
    // 여행지도 초기화
    resetTripForm();
    // 그룹/프로젝트: 그룹 드롭다운 + 날짜에 맞는 프로젝트 고정 표시
    fillGroupSelect();
    document.getElementById('f-group-id').value = '';
    populateProjectSelect('');

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
    document.getElementById('f-memo').value=ev.memo||'';
    loadAttachInto(ev.id);
    setColor(ev.color);
    closeColorPicker();
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
            document.getElementById('f-lunar-leap').checked = (ev.lunar_leap=='1' || ev.is_leap=='1');
            refreshLunarLeapBadge();
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
        document.getElementById('f-start').value = '';  // recurBaseVal()이 f-due-dt를 읽도록 초기화
        document.getElementById('f-due-dt').value=ev.due_dt||ev.start_dt?.slice(0,10)||'';
    }

    // 아이콘 복원
    document.getElementById('f-icon').value = ev.icon || '';
    // 그룹/프로젝트 복원 — ev.project_id 는 그룹 또는 프로젝트 행을 가리킴
    fillGroupSelect();
    document.getElementById('f-group-id').value = '';
    let projForceId = '';
    if (ev.project_id) {
        const owner = _projects.find(x => x.id == ev.project_id);
        if (owner && owner.type === 'project') {
            if (owner.parent_id) document.getElementById('f-group-id').value = owner.parent_id;
            projForceId = owner.id;               // 저장된 프로젝트를 드롭다운에서 선택
        } else if (owner) {
            document.getElementById('f-group-id').value = ev.project_id;
        }
    }
    populateProjectSelect(projForceId);
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
    SEL_EXTRA = (ev.extra_attendees || '').split(',').map(s=>s.trim()).filter(Boolean);
    renderAttendeeChips();

    // 위치 복원
    fillLocationForm(ev);
    // 여행지도 복원
    fillTripForm(ev);

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
    let matched=false;
    document.querySelectorAll('#evt-swatches .color-swatch:not(.color-custom):not(.color-edit)').forEach(el=>{
        const on = el.dataset.color===c;
        el.classList.toggle('selected', on);
        if (on) matched=true;
    });
    // 맞춤 스와치: 프리셋에 없는 색이면 그 색으로 채워 선택 표시, 프리셋이면 🎨 아이콘 복귀
    const cust=document.getElementById('evt-custom-swatch');
    if (cust) {
        if (!matched && c) {                 // 현재 색 = 맞춤색 → 그 색으로 채워 선택 표시
            cust.style.background=c; cust.dataset.color=c; cust.textContent='';
            cust.classList.add('selected'); cust.title='맞춤 색상(이 일정만)';
        } else {                             // 현재 색 = 프리셋 → 마지막 쓴 맞춤색이 있으면 그 색 미리보기
            cust.classList.remove('selected');
            const last = loadLastColor();
            if (last) { cust.style.background=last; cust.dataset.color=last; cust.textContent=''; cust.title='맞춤 색상(마지막 색 표시 — 눌러서 변경/재사용)'; }
            else { cust.style.background=''; cust.removeAttribute('data-color'); cust.textContent='🎨'; cust.title='맞춤 색상(이 일정만)'; }
        }
    }
}
// ── 맞춤 색상 피커 ─────────────────────────────────────────────
// HSL → #RRGGBB
function hslToHex(h, s, l) {
    s /= 100; l /= 100;
    const k = n => (n + h / 30) % 12;
    const a = s * Math.min(l, 1 - l);
    const f = n => l - a * Math.max(-1, Math.min(k(n) - 3, Math.min(9 - k(n), 1)));
    const hx = x => Math.round(255 * x).toString(16).padStart(2, '0');
    return '#' + hx(f(0)) + hx(f(8)) + hx(f(4));
}
// 팔레트 격자 셀 HTML(색상 컬럼 × 명도 행 + 우측 무채색 컬럼). pickFn=클릭 시 호출할 함수명
const CC_HUE_COLS = 16;
function paletteCellsHtml(pickFn) {
    const hues = Array.from({ length: CC_HUE_COLS }, (_, i) => Math.round(i * 360 / CC_HUE_COLS));
    const lRows = [94, 86, 76, 66, 56, 47, 38, 29, 20, 12];   // 밝음→어두움
    let html = '';
    lRows.forEach(L => {
        const sat = L > 88 || L < 16 ? 70 : 88;
        hues.forEach(H => {
            const hex = hslToHex(H, sat, L);
            html += `<span class="cc-cell" style="background:${hex}" onclick="${pickFn}('${hex}')" title="${hex}"></span>`;
        });
        const g = Math.round(255 * L / 100).toString(16).padStart(2, '0');
        const ghex = '#' + g + g + g;
        html += `<span class="cc-cell" style="background:${ghex}" onclick="${pickFn}('${ghex}')" title="${ghex}"></span>`;
    });
    return html;
}
function buildPaletteInto(gridId, pickFn) {
    const grid = document.getElementById(gridId);
    if (!grid || grid.dataset.built) return;
    grid.style.gridTemplateColumns = `repeat(${CC_HUE_COLS + 1}, 1fr)`;
    grid.innerHTML = paletteCellsHtml(pickFn);
    grid.dataset.built = '1';
}
function buildPalette() { buildPaletteInto('cc-palette', 'ccPick'); }
// ── 사용자 지정 색상 칸(프리셋) — localStorage 영구 저장 ──────────
const SCH_SWATCH_N = 5;   // 색상칸 개수
const SCH_SWATCH_DEFAULT = ['#3498db','#2ecc71','#e74c3c','#f39c12','#9b59b6'];
let SCH_SWATCHES = loadSwatches();
let swatchEditMode = false;
let ccAssignIndex = null;   // 편집모드에서 색을 지정할 칸 인덱스(없으면 null=일반 선택)
function loadSwatches() {
    try { const a = JSON.parse(localStorage.getItem('sch_swatches') || ''); if (Array.isArray(a) && a.length) return a.slice(0, SCH_SWATCH_N); } catch (e) {}
    return SCH_SWATCH_DEFAULT.slice();
}
function saveSwatches() { try { localStorage.setItem('sch_swatches', JSON.stringify(SCH_SWATCHES.slice(0, SCH_SWATCH_N))); } catch (e) {} }
// 마지막으로 쓴 맞춤색 자동 기억
function loadLastColor() { try { return localStorage.getItem('sch_last_color') || ''; } catch (e) { return ''; } }
function saveLastColor(hex) { try { localStorage.setItem('sch_last_color', hex); } catch (e) {} }
function renderSwatches() {
    const wrap = document.getElementById('evt-preset-wrap');
    if (!wrap) return;
    wrap.innerHTML = SCH_SWATCHES.map((c, i) =>
        `<span class="color-swatch" data-color="${c}" data-idx="${i}" style="background:${c}" onclick="swatchClick(${i},event)"></span>`
    ).join('');
    const editBtn = document.getElementById('evt-edit-btn');
    if (editBtn) editBtn.classList.toggle('editing', swatchEditMode);
    document.getElementById('evt-swatches').classList.toggle('edit-mode', swatchEditMode);
    if (typeof S !== 'undefined' && S && S.color) setColor(S.color);   // 선택표시 재적용
}
function swatchClick(i, e) {
    if (swatchEditMode) { ccAssignIndex = i; openColorPicker(e); }   // 그 칸 색을 새로 지정
    else setColor(SCH_SWATCHES[i]);
}
function toggleSwatchEdit(e) {
    if (e) e.stopPropagation();
    swatchEditMode = !swatchEditMode;
    ccAssignIndex = null;
    closeColorPicker();
    renderSwatches();
}
function openCustomPicker(e) { ccAssignIndex = null; openColorPicker(e); }   // 🎨 = 이 일정만 맞춤색
// 팔레트/hex 선택 결과 처리: 편집모드면 칸에 저장, 아니면 일정 색으로 선택
function applyColorChoice(hex) {
    if (ccAssignIndex !== null) {
        SCH_SWATCHES[ccAssignIndex] = hex;
        saveSwatches();
        ccAssignIndex = null;
        renderSwatches();      // 편집모드 유지(연속 편집 가능)
        closeColorPicker();
    } else {
        if (!SCH_SWATCHES.includes(hex)) saveLastColor(hex);   // 프리셋이 아니면 '마지막 쓴 색'으로 기억
        setColor(hex);
        closeColorPicker();
    }
}
function ccPick(hex) { applyColorChoice(hex); }
function openColorPicker(e) {
    if (e) e.stopPropagation();
    buildPalette();
    const cur = S.color || '#3498db';
    // 현재 색이 프리셋이면(=맞춤색 아님) 마지막 쓴 맞춤색을 미리 띄워 재사용 쉽게
    const seed = (ccAssignIndex === null && SCH_SWATCHES.includes(cur) && loadLastColor()) ? loadLastColor() : cur;
    document.getElementById('cc-hex').value = seed;
    const pv = document.getElementById('cc-preview');
    if (pv) pv.style.background = /^#[0-9a-fA-F]{6}$/.test(seed) ? seed : '#3498db';
    document.getElementById('color-popover').style.display = 'block';
}
function closeColorPicker() {
    const pop = document.getElementById('color-popover');
    if (pop) pop.style.display = 'none';
}
function ccSyncFromHex(v) {
    if (/^#[0-9a-fA-F]{6}$/.test(v)) document.getElementById('cc-preview').style.background = v;
}
function ccApply() {
    let v = document.getElementById('cc-hex').value.trim();
    if (/^[0-9a-fA-F]{6}$/.test(v)) v = '#' + v;
    if (!/^#[0-9a-fA-F]{6}$/.test(v)) { alert('색상코드를 #RRGGBB 형식으로 입력하세요.'); return; }
    applyColorChoice(v.toLowerCase());
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
        memo:      document.getElementById('f-memo').value,
        attach_keep: MODAL_ATTACH.filter(a=>a.id).map(a=>a.id),
        // 새 이미지(attach_new)는 JSON에 싣지 않고 저장 후 multipart로 개별 업로드
        // (openresty가 128KB 초과 JSON POST를 404 차단하기 때문)
        recur_rule: RECUR||null,
        alert_mins: [...document.querySelectorAll('.f-alert:checked')].map(cb=>+cb.value),
        attendees: SEL_ATTENDEES.map(a=>a.id),
        extra_attendees: SEL_EXTRA.join(', '),
        icon: document.getElementById('f-icon').value || null,
        // 프로젝트 우선, 없으면 그룹 (둘 다 tbl_project 행 → project_id 단일 컬럼)
        project_id: document.getElementById('f-project-id').value
                    || document.getElementById('f-group-id').value || null,
        // 위치/지도
        address:    document.getElementById('f-address').value.trim() || null,
        lat:        document.getElementById('f-lat').value || null,
        lng:        document.getElementById('f-lng').value || null,
        provider:   document.getElementById('f-address').value.trim() ? document.getElementById('f-provider').value : null,
        place_name: document.getElementById('f-place-name').value.trim() || null,
        // 연결된 여행지도(트립)
        trip_token: document.getElementById('f-trip-token').value || null,
        trip_name:  document.getElementById('f-trip-name').value || null,
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

    let savedId = null;
    if (S.editId) {
        payload.id = S.editId;
        // 반복 인스턴스 수정
        if (S.editEv && S.editEv.is_recur_instance == '1') {
            // todo는 항상 전체 시리즈 업데이트 (날짜 변경 = 반복 기준일 변경)
            if (ETYPE !== 'todo') {
                openScopeModal('edit', payload); return;
            }
        }
        const upRes = await api('update', payload, 'POST');
        if (upRes && !upRes.ok) { alert('저장 실패: ' + (upRes.msg||'')); return; }
        if (upRes?.shifted?.length) showToast(`겹치는 일정 ${upRes.shifted.length}건 시간이 자동 조정됐습니다.`);
        savedId = S.editId;
    } else {
        let crRes = await api('create', payload, 'POST');
        if (crRes?.dup) {
            if (!confirm('같은 날짜에 동일 제목의 일정이 이미 있습니다.\n그래도 등록하시겠습니까?')) return;
            crRes = await api('create', {...payload, force: true}, 'POST');
        }
        if (crRes && !crRes.ok) { alert('저장 실패: ' + (crRes.msg||'')); return; }
        if (crRes?.shifted?.length) showToast(`겹치는 일정 ${crRes.shifted.length}건 시간이 자동 조정됐습니다.`);
        savedId = crRes.id;
    }
    // 이미지 첨부: 저장된 일정 id로 multipart 업로드
    if (savedId) {
        const upl = await uploadPendingAttachments(savedId);
        if (!upl.ok) alert('일정은 저장됐으나 일부 이미지 첨부에 실패했습니다: ' + (upl.msg||''));
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

    // 제목 (이모지 + 아이콘 포함). 입력대기는 ⏳ 표시
    const isDraft = ev.is_draft == '1';
    const icon = ev.icon ? ev.icon+' ' : '';
    const typeIcon = {timed:'',allday:'📅 ',anniversary:'',todo:'☑ '}[ev.event_type]||'';
    document.getElementById('view-title').textContent = (isDraft?'⏳ ':'') + icon + typeIcon + ev.title;
    // 입력대기 안내: 수정 버튼으로 나머지를 채우면 정식 등록됨
    const draftNote = document.getElementById('view-draft-note');
    if (draftNote) draftNote.style.display = isDraft ? '' : 'none';

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

    // 참석자 (주소록 연락처 + 텍스트 참석자)
    const attEl = document.getElementById('view-attendees');
    const cChips = (ev.attendees || []).map(a=>
        `<span style="display:inline-block;background:#eaf4ff;color:#2980b9;border-radius:10px;padding:2px 8px;margin:2px;font-size:12px;">${esc(a.name)}</span>`);
    const eChips = (ev.extra_attendees || '').split(',').map(s=>s.trim()).filter(Boolean).map(n=>
        `<span title="주소록에 없는 참석자" style="display:inline-block;background:#f0f1f4;color:#555;border:1px dashed #c4ccd4;border-radius:10px;padding:2px 8px;margin:2px;font-size:12px;">${esc(n)}</span>`);
    if (cChips.length || eChips.length) {
        attEl.innerHTML = '👥 ' + cChips.concat(eChips).join('');
        attEl.style.display = '';
    } else {
        attEl.style.display = 'none';
    }

    // 메모 → 체크리스트(줄 단위, 회차별 체크 상태)
    renderMemoChecklist(ev);

    // 첨부 이미지
    renderViewAttach(ev);

    // 위치/지도
    renderViewLocation(ev);

    // 연결된 여행지도
    const tripWrap = document.getElementById('view-trip');
    if (ev.trip_token) {
        tripWrap.dataset.token = ev.trip_token;
        document.getElementById('view-trip-name').textContent = ev.trip_name || '';
        tripWrap.style.display = '';
    } else {
        tripWrap.style.display = 'none';
    }

    // 헤더 색상 띠
    document.getElementById('view-header').style.borderLeft = `4px solid ${ev.color||'#3498db'}`;

    // 활동 메모 로드 (입력창은 접은 상태로 초기화)
    toggleLogInput(false);
    loadLogs();

    document.getElementById('view-overlay').classList.add('open');
}

// ── 활동 메모(타임스탬프 로그) ──────────────────────────────
// VIEW_EV의 발생일(occ_date): 반복 인스턴스는 origin_dt, 단일은 자기 날짜
function viewOccDate(ev) {
    if (!ev) return '';
    if (ev.origin_dt) return ev.origin_dt;
    const base = ev.start_dt || ev.due_dt || '';
    return base ? base.substr(0, 10) : '';
}

// ── 메모 체크리스트 ─────────────────────────────────────────
// 메모 줄 앞에 체크표시(ㅁ / [] / [ ] / - [ ] / □ / ☐)가 있으면 체크박스 항목,
// 없으면 일반 설명 텍스트로 렌더. 체크 상태는 (schedule_id, occ_date)별 저장 →
// 반복 일정은 회차(날짜)마다 독립. 매월 반복이면 다음 달엔 초기화됨.
let MEMO_CHK_ITEMS = [];

// 줄 앞 체크표시 감지: 매칭되면 표시부를 떼어낸 나머지가 항목 텍스트
const MEMO_CHK_RE = /^\s*(?:-\s*)?(?:\[\s*[xXvV]?\s*\]|ㅁ|□|☐|☑|✔|✓)\s*/;
function parseMemoItem(raw) {
    const m = raw.match(MEMO_CHK_RE);
    if (m) return { isCheck: true, text: raw.slice(m[0].length).trim() };
    return { isCheck: false, text: raw.trim() };
}

async function renderMemoChecklist(ev) {
    const memoEl = document.getElementById('view-memo');
    const lines = (ev.memo || '').split(/\r?\n/).filter(l => l.trim() !== '');
    if (!lines.length) { memoEl.style.display = 'none'; memoEl.innerHTML = ''; return; }
    const parsed = lines.map(parseMemoItem);
    const hasCheck = parsed.some(p => p.isCheck && p.text);

    memoEl.style.display = '';
    memoEl.style.whiteSpace = 'normal';

    // 체크 상태 조회 (체크 항목이 있을 때만)
    let checked = new Set();
    if (hasCheck && ev.id) {
        const res = await api('check_list', { schedule_id: ev.id, occ_date: viewOccDate(ev) });
        if (res.ok) checked = new Set((res.data || []).map(String));
    }
    // 조회 중 다른 일정이 열렸으면 폐기
    if (VIEW_EV !== ev) return;

    MEMO_CHK_ITEMS = [];
    let doneN = 0, total = 0, body = '';
    for (const p of parsed) {
        if (p.isCheck && p.text) {
            const idx = MEMO_CHK_ITEMS.length;
            MEMO_CHK_ITEMS.push(p.text);
            const on = checked.has(p.text);
            total++; if (on) doneN++;
            body += `<label class="memo-chk-item${on ? ' on' : ''}" data-idx="${idx}">`
                 +  `<input type="checkbox"${on ? ' checked' : ''}>`
                 +  `<span class="memo-chk-txt">${esc(p.text)}</span></label>`;
        } else if (p.text) {
            body += `<div class="memo-plain">${esc(p.text)}</div>`;
        }
    }
    const head = total
        ? `<div class="memo-chk-head">✅ 체크리스트 <span class="memo-chk-count">${doneN}/${total}</span></div>`
        : '';
    memoEl.innerHTML = head + body;
    memoEl.querySelectorAll('.memo-chk-item input').forEach(cb =>
        cb.addEventListener('change', () => toggleMemoCheck(cb)));
}

async function toggleMemoCheck(cb) {
    const item = cb.closest('.memo-chk-item');
    const txt  = MEMO_CHK_ITEMS[+item.dataset.idx];
    const on   = cb.checked;
    item.classList.toggle('on', on);
    updateMemoCheckCount();
    if (!VIEW_EV || !VIEW_EV.id || txt == null) return;
    const res = await api('check_toggle', {
        schedule_id: VIEW_EV.id, occ_date: viewOccDate(VIEW_EV), item: txt, checked: on ? 1 : 0
    }, 'POST');
    if (!res.ok) {   // 실패 시 원복
        cb.checked = !on;
        item.classList.toggle('on', !on);
        updateMemoCheckCount();
        alert(res.msg || '체크 저장에 실패했습니다.');
    }
}

function updateMemoCheckCount() {
    const memoEl = document.getElementById('view-memo');
    const cntEl = memoEl.querySelector('.memo-chk-count');
    if (!cntEl) return;
    const total = memoEl.querySelectorAll('.memo-chk-item').length;
    const done  = memoEl.querySelectorAll('.memo-chk-item.on').length;
    cntEl.textContent = `${done}/${total}`;
}

async function loadLogs() {
    const listEl = document.getElementById('view-log-list');
    if (!VIEW_EV || !VIEW_EV.id) { listEl.innerHTML = ''; return; }
    const res = await api('log_list', { schedule_id: VIEW_EV.id, occ_date: viewOccDate(VIEW_EV) });
    renderLogs(res.ok ? (res.data || []) : []);
}

function renderLogs(logs) {
    const listEl = document.getElementById('view-log-list');
    if (!logs.length) {
        listEl.innerHTML = `<div style="font-size:12px;color:#aaa;">아직 메모가 없습니다.</div>`;
        return;
    }
    listEl.innerHTML = logs.map(l => `
        <div style="display:flex;align-items:flex-start;gap:6px;font-size:13px;color:#444;">
            <span style="color:#3498db;font-weight:600;flex-shrink:0;">[${esc(l.t)}]</span>
            <span style="white-space:pre-wrap;word-break:break-word;flex:1;">${esc(l.note)}</span>
            <button onclick="delLog(${+l.id})" title="삭제"
                style="border:0;background:none;color:#ccc;cursor:pointer;font-size:13px;line-height:1;flex-shrink:0;padding:0 2px;">×</button>
        </div>`).join('');
}

function toggleLogInput(show) {
    const box = document.getElementById('view-log-input');
    const open = (show === undefined) ? (box.style.display === 'none') : show;
    box.style.display = open ? 'block' : 'none';
    if (open) {
        const ta = document.getElementById('view-log-text');
        ta.value = '';
        setTimeout(() => ta.focus(), 0);
    }
}

async function addLog() {
    const ta = document.getElementById('view-log-text');
    const note = ta.value.trim();
    if (!note) { ta.focus(); return; }
    if (!VIEW_EV || !VIEW_EV.id) return;
    const now = new Date();
    const time = pad(now.getHours()) + ':' + pad(now.getMinutes());
    const res = await api('log_add', {
        schedule_id: VIEW_EV.id, occ_date: viewOccDate(VIEW_EV), note, time
    }, 'POST');
    if (res.ok) { toggleLogInput(false); loadLogs(); loadEvents(); }
    else alert(res.msg || '메모 저장에 실패했습니다.');
}

async function delLog(id) {
    if (!confirm('이 메모를 삭제할까요?')) return;
    const res = await api('log_delete', { id }, 'GET');
    if (res.ok) { loadLogs(); loadEvents(); }
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
        // 이미지 첨부: 저장된 일정 id로 multipart 업로드
        const upl = await uploadPendingAttachments(S.editId);
        if (!upl.ok) alert('일정은 저장됐으나 일부 이미지 첨부에 실패했습니다: ' + (upl.msg||''));
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
    if (S.view==='month') {
        const todayHere = TODAY.getFullYear()===S.year && TODAY.getMonth()+1===S.month;
        return todayHere ? new Date(TODAY) : new Date(S.year, S.month-1, 1);
    }
    if (S.view==='week') {
        const todayInWeek = TODAY >= S.weekStart && TODAY <= addDays(S.weekStart, 6);
        return todayInWeek ? new Date(TODAY) : new Date(S.weekStart);
    }
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
        document.getElementById(`view-${n}`).style.display=n===v?(n==='list'?'block':'flex'):'none';
    });
    document.querySelectorAll('.sch-toolbar .view-tabs button').forEach(b=>b.classList.toggle('active',b.dataset.view===v));
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
    savePos();
    loadEvents();
}

document.getElementById('f-allday').onchange  = e=>setAllday(e.target.checked);

// ━━━ 참석자 입력 자동완성 (입력한 글자로 주소록 필터) ━━━
let _attAcList = [];   // 현재 표시중인 후보 [{id,name,org}]
let _attAcIdx  = -1;   // 활성 항목 인덱스 (-1=없음)

function attAcClose() {
    const box = document.getElementById('attendee-ac');
    box.style.display = 'none'; box.innerHTML = '';
    _attAcList = []; _attAcIdx = -1;
}
function attAcOpen() {
    const inp = document.getElementById('f-attendee-input');
    const q = inp.value.trim().toLowerCase();
    if (!q) { attAcClose(); return; }
    // 이미 선택된 연락처는 제외, 이름·그룹·회사명 부분일치, 최대 8개
    _attAcList = ALL_CONTACTS
        .filter(c => !SEL_ATTENDEES.some(a=>a.id==c.id))
        .filter(c => (c.name||'').toLowerCase().includes(q) ||
                     (c.group_name||'').toLowerCase().includes(q) ||
                     (c.organization||'').toLowerCase().includes(q))
        .slice(0, 8)
        .map(c => ({id:c.id, name:c.name, org:c.organization||'', grp:c.group_name||''}));
    _attAcIdx = _attAcList.length ? 0 : -1;
    attAcRender(inp.value.trim());
}
function attAcRender(rawQ) {
    const box = document.getElementById('attendee-ac');
    const rows = _attAcList.map((c,i)=>`
        <div class="att-ac-item${i===_attAcIdx?' active':''}" data-i="${i}">
            <span class="nm">${esc(c.name)}</span>
            ${c.grp?`<span class="grp">${esc(c.grp)}</span>`:''}
            ${c.org?`<span class="org">${esc(c.org)}</span>`:''}
        </div>`);
    // 정확히 일치하는 이름이 없으면 "이름만 추가" 안내 행
    const exact = ALL_CONTACTS.some(c=>c.name===rawQ);
    if (rawQ && !exact) {
        rows.push(`<div class="att-ac-item att-ac-new" data-new="1">
            <b>+ "${esc(rawQ)}"</b>&nbsp;주소록에 없는 참석자로 추가</div>`);
    }
    if (!rows.length) { attAcClose(); return; }
    box.innerHTML = rows.join('');
    box.style.display = 'block';
}
function attAcCommit() {
    // 활성 항목이 후보면 그걸, 'new' 행이거나 후보 없으면 입력값 그대로 추가
    const inp = document.getElementById('f-attendee-input');
    if (_attAcIdx >= 0 && _attAcList[_attAcIdx]) {
        addAttendeeByName(_attAcList[_attAcIdx].name);
    } else {
        addAttendeeByName(inp.value);
    }
    attAcClose();
}

(function(){
    const inp = document.getElementById('f-attendee-input');
    const box = document.getElementById('attendee-ac');
    inp.addEventListener('input', attAcOpen);
    inp.addEventListener('focus', function(){ if (this.value.trim()) attAcOpen(); });
    inp.addEventListener('keydown', function(e){
        const open = box.style.display !== 'none';
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (!open) { attAcOpen(); return; }
            _attAcIdx = Math.min(_attAcIdx + 1, _attAcList.length - 1);
            attAcRender(this.value.trim());
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            _attAcIdx = Math.max(_attAcIdx - 1, 0);
            attAcRender(this.value.trim());
        } else if (e.key === 'Enter') {
            e.preventDefault();
            attAcCommit();
        } else if (e.key === 'Escape') {
            if (open) { e.preventDefault(); attAcClose(); }
        }
    });
    // 마우스 클릭 선택 (mousedown으로 blur보다 먼저 처리)
    box.addEventListener('mousedown', function(e){
        const it = e.target.closest('.att-ac-item');
        if (!it) return;
        e.preventDefault();
        if (it.dataset.new) addAttendeeByName(inp.value);
        else addAttendeeByName(_attAcList[+it.dataset.i].name);
        attAcClose();
    });
    inp.addEventListener('blur', function(){ setTimeout(attAcClose, 120); });
})();

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
    refreshLunarLeapBadge();
    updateLunarPreview();
}
// 윤달 배지: 체크 상태 반영(자동변환이 윤달이면 강조). 클릭으로 수동 전환도 가능.
function refreshLunarLeapBadge() {
    const c = document.getElementById('f-lunar-leap');
    const b = document.getElementById('f-lunar-leap-badge');
    if (!c || !b) return;
    if (c.checked) {
        b.textContent = '🔁 윤달';
        b.style.background = '#fff7e6'; b.style.borderColor = '#f0a92e'; b.style.color = '#b9740a';
    } else {
        b.textContent = '윤달';
        b.style.background = '#fff'; b.style.borderColor = '#d4dae6'; b.style.color = '#b8bfca';
    }
}
function toggleLunarLeap() {
    const c = document.getElementById('f-lunar-leap');
    if (!c) return;
    c.checked = !c.checked;
    refreshLunarLeapBadge();
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
    // 모달 제목 = 타입 + 추가/수정
    const _verb = S.editId ? '수정' : '추가';
    const _noun = type==='anniversary' ? '기념일' : (type==='todo' ? '할일' : '일정');
    document.getElementById('modal-title').textContent = _noun + ' ' + _verb;
    // 날짜 행
    document.getElementById('row-timed').style.display       = type==='timed'       ? 'flex'  : 'none';
    document.getElementById('row-anniv-class').style.display = type==='anniversary' ? 'block' : 'none';
    document.getElementById('row-anniv-cat').style.display   = type==='anniversary' ? 'block' : 'none';
    document.getElementById('row-anniversary').style.display = type==='anniversary' ? 'block' : 'none';
    document.getElementById('row-todo').style.display        = type==='todo'        ? 'block' : 'none';
    document.getElementById('row-todo-recur').style.display  = type==='todo'        ? 'flex'  : 'none';
    // 카테고리 select: 기념일 숨김
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
let SEL_ATTENDEES = [];       // 선택된 참석자(주소록 연락처) [{id,name}]
let SEL_EXTRA = [];           // 주소록에 없는 참석자 (이름 텍스트만) [name]

async function loadContactsForAttendee() {
    try {
        const r = await fetch('/schedule_api.php?module=contacts&action=list');
        const res = await r.json();
        ALL_CONTACTS = res.data || [];
    } catch(e){ ALL_CONTACTS=[]; }
}

function renderAttendeeChips() {
    const el = document.getElementById('attendee-chips');
    // 주소록 연락처 칩 (파란색)
    const contactChips = SEL_ATTENDEES.map(a=>`
        <span style="display:inline-flex;align-items:center;gap:4px;background:#eaf4ff;color:#2980b9;border-radius:12px;padding:3px 8px;font-size:12px;font-weight:600;">
            ${esc(a.name)}
            <span style="cursor:pointer;color:#e74c3c;" onclick="removeAttendee(${a.id})">×</span>
        </span>`);
    // 주소록에 없는 텍스트 참석자 칩 (회색 점선 — 주소록과 무관)
    const extraChips = SEL_EXTRA.map((n,i)=>`
        <span title="주소록에 없는 참석자" style="display:inline-flex;align-items:center;gap:4px;background:#f0f1f4;color:#555;border:1px dashed #c4ccd4;border-radius:12px;padding:3px 8px;font-size:12px;font-weight:600;">
            ${esc(n)}
            <span style="cursor:pointer;color:#e74c3c;" onclick="removeExtra(${i})">×</span>
        </span>`);
    el.innerHTML = contactChips.concat(extraChips).join('');
}
function removeAttendee(id) {
    SEL_ATTENDEES = SEL_ATTENDEES.filter(a=>a.id!=id);
    renderAttendeeChips();
}
function removeExtra(i) {
    SEL_EXTRA.splice(i, 1);
    renderAttendeeChips();
}
function addAttendeeByName(name) {
    name = name.trim();
    if (!name) return;
    document.getElementById('f-attendee-input').value='';
    const c = ALL_CONTACTS.find(x=>x.name===name);
    if (c) {
        // 주소록에 있는 사람 → 연락처로 연결
        if (SEL_ATTENDEES.some(a=>a.id==c.id)) return; // 중복
        SEL_ATTENDEES.push({id:c.id, name:c.name});
    } else {
        // 주소록에 없는 사람 → 이름만 텍스트로 추가 (주소록 안 건드림)
        if (SEL_EXTRA.includes(name) || SEL_ATTENDEES.some(a=>a.name===name)) return; // 중복
        SEL_EXTRA.push(name);
    }
    renderAttendeeChips();
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
            const s=yt==='day'?`${m}월 ${d}일`:yt==='lastday'?`${m}월 말일`:`${m}월 ${nth}번째 ${DAY_KR[dow]}요일`;
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
        document.getElementById('r-y-day-lbl').textContent   = `${m}월 ${d}일`;
        document.getElementById('r-y-wd-lbl').textContent    = `${m}월 ${nth}번째 ${DAY_KR[dow]}요일`;
        document.getElementById('r-y-month-lbl').textContent = `${m}월`;
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
        const s=yt==='day'?`${m}월 ${d}일`:yt==='lastday'?`${m}월 말일`:`${m}월 ${nth}번째 ${DAY_KR[dow]}요일`;
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
document.querySelectorAll('.sch-toolbar .view-tabs button').forEach(b=>{b.onclick=()=>switchView(b.dataset.view);});
renderSwatches();   // 사용자 지정 색상 칸 렌더(localStorage)
// 팝오버 바깥 클릭 시 닫기(이벤트 모달 + 그룹/프로젝트 모달 공통)
document.addEventListener('click', e=>{
    const skip = e.target.closest('.color-custom') || e.target.closest('.color-edit');
    const pop=document.getElementById('color-popover');
    if (pop && pop.style.display==='block' && !pop.contains(e.target) && !skip) closeColorPicker();
    const pmp=document.getElementById('pm-popover');
    if (pmp && pmp.style.display==='block' && !pmp.contains(e.target) && !skip) pmCloseColorPicker();
});
// 일정 입력 모달은 바깥(오버레이) 클릭으로 닫지 않음 — × 버튼·취소·Esc 로만 닫기(실수 닫힘 방지)
document.getElementById('view-overlay').addEventListener('click',function(e){if(e.target===this)closeViewModal();});
document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeModal();closeViewModal();}});

// ── 초기화: 저장된 위치/뷰 복원 후 렌더 ──
(function init(){
    loadContactsForAttendee();  // 참석자용 주소록 미리 로드
    loadProjPanel();         // 프로젝트 패널 로드
    loadTodoData();          // 목표·습관 데이터 로드 (사이드바 섹션)
    loadTravelBars();        // 여행 기간 막대 로드 (읽기전용)
    restorePos();  // localStorage에서 위치/뷰 복원 (없으면 오늘 기준 그대로)
    if (S.view==='dashboard') S.view='month';   // 구버전(대시보드=뷰) 잔재 보정
    // 저장된 뷰로 화면 전환 (탭 활성화 + display)
    ['month','week','day','list'].forEach(n=>{
        document.getElementById(`view-${n}`).style.display = n===S.view ? (n==='list'?'block':'flex') : 'none';
    });
    document.querySelectorAll('.sch-toolbar .view-tabs button').forEach(b=>b.classList.toggle('active', b.dataset.view===S.view));
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

// 여행 막대 로드 (읽기전용 — tbl_travel 단방향 조회, 캘린더에 기간 막대만 표시)
async function loadTravelBars() {
    try {
        const res  = await fetch('schedule_api.php?module=travel&action=list');
        const json = await res.json();
        if (!json.ok) return;
        _travels = json.data || [];
        // 캘린더가 이미 그려져 있으면 막대 반영 위해 재렌더
        if (S.events && S.events.length !== undefined && S.view === 'month') render();
    } catch (e) { /* 여행 미설정 시 조용히 무시 */ }
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

    // ── 프로젝트 카드 (이름·기간·시간경과율·업무 건수 원) ──
    const projCard = p => {
        const total = +p.item_count || 0, done = +p.done_count || 0;
        const period = (p.start_dt || p.end_dt)
            ? `${(p.start_dt||'').slice(5)} ~ ${(p.end_dt||'').slice(5)}` : '기간 미설정';
        // 시간 경과율: 시작일~종료일 중 오늘이 얼마나 지났는지 (업무 완료율 아님)
        let tpct = 0;
        if (p.start_dt && p.end_dt) {
            const s = new Date(p.start_dt + 'T00:00:00'), e = new Date(p.end_dt + 'T00:00:00');
            const span = e - s;
            tpct = span > 0 ? Math.round((TODAY - s) / span * 100) : (TODAY >= e ? 100 : 0);
            tpct = Math.max(0, Math.min(100, tpct));
        }
        // 업무 건수: 완료=회색원 / 미완료=빨간원 (건수만큼 표시)
        const dots = total
            ? `<div class="proj-card-dots">${Array.from({length: total}, (_, i) =>
                  `<span class="proj-dot-task${i < done ? ' done' : ''}"></span>`).join('')}</div>`
            : '';
        const dimmed = p.is_done ? ' style="opacity:.55;"' : '';
        return `<div class="proj-card"${dimmed} onclick="openProjDetail(event,${p.id})" oncontextmenu="openProjDetail(event,${p.id});return false;">
            <div class="proj-card-top">
                <span class="proj-dot" style="background:${p.color||'#3498db'}"></span>
                <span class="proj-card-name">${esc((p.icon||'')+p.title)}</span>
            </div>
            <div class="proj-card-period">📅 ${period}</div>
            <div class="proj-card-prog">
                <div class="proj-bar-track"><div class="proj-bar-fill" style="width:${tpct}%;background:${p.color||'#3498db'}"></div></div>
                <span class="proj-card-pct">${tpct}%</span>
            </div>
            ${dots}
        </div>`;
    };

    const groups   = _projects.filter(p => p.type === 'group');
    // 프로젝트: 현재 보고 있는 달력 기간과 겹치는 것만 표시
    const [rs, re] = viewRange();
    const projects = _projects.filter(p =>
        p.type === 'project' && p.start_dt && p.end_dt && p.start_dt <= re && p.end_dt >= rs);

    let html = '';
    // 1) 프로젝트 (현재 기간 내)
    html += `<div class="proj-type-header">📌 프로젝트</div>`;
    html += projects.length ? projects.map(projCard).join('')
                            : '<div class="proj-empty">이 기간에 표시할 프로젝트 없음</div>';
    // 2) 분류 (단순 라벨)
    html += `<div class="proj-type-header" style="margin-top:12px;">📁 분류</div>`;
    html += groups.length ? groups.map(groupItem).join('')
                          : '<div class="proj-empty">＋ 로 분류 추가</div>';

    // 3) 목표 (이번 기간 진행률)
    const goalItem = g => {
        const pr = g.progress || {current:0,total:1,met:false};
        const cls = pr.met ? 'tg-met' : 'tg-miss';
        const badge = g.mode==='manual'
            ? `<span class="tg-count ${cls}" onclick="toggleGoalManual(event,${g.id})" title="달성 체크">${pr.current}/${pr.total}</span>`
            : `<span class="tg-count ${cls}">${pr.current}/${pr.total}</span>`;
        return `<div class="proj-item" onclick="openTodoModal('goal',${g.id})">
            <span class="proj-dot" style="background:${g.color||'#3498db'}"></span>
            <span class="proj-label">${esc(g.title)}</span>${badge}
        </div>`;
    };
    // 습관 행 (윗줄 항상 체크아이콘, track_total이면 아랫줄 누적 진행바)
    const SLOT_LABELS = {morning:'🌅 아침', afternoon:'🌤 점심', evening:'🌙 저녁', anytime:'🕐 아무때나'};
    const habitItem = h => {
        const t = todayStr();
        const measure = (h.daily_target != null && h.daily_target !== '');  // 탭=수치입력
        const done = doneOn(h, t);
        const sk = h.recur_type==='week_quota' ? 0 : habitStreak(h);
        const streak = sk>0 ? `<span class="th-streak">🔥${sk}</span>` : '';
        const refIcon = h.ref_url ? `<span class="th-ref" data-url="${esc(h.ref_url)}" onclick="openHabitRef(event,this.dataset.url)" title="참고 링크">${ytId(h.ref_url)?'📺':'🔗'}</span>` : '';
        const icon = `<span class="th-check ${done?'on':''}" onclick="habitIconTap(event,${h.id})" title="${measure?'오늘 수치 입력':'오늘 완료'}">${done?'☑':'☐'}</span>`;
        const lbl  = `<span class="proj-label ${done?'th-done':''}" onclick="openTodoModal('habit',${h.id})">${esc((h.icon||'')+h.title)}</span>`;
        const top  = `<div class="proj-item th-row">${icon}${lbl}<span class="th-right">${refIcon}${streak}</span></div>`;
        // 아랫줄: 누적 진행바 (track_total일 때만)
        let bar = '';
        if (+h.track_total===1 && h.target_total){
            const cum = +h.cum_total||0, tt = +h.target_total;
            const pct = Math.min(100, Math.round(cum/Math.max(1,tt)*100));
            const unit = h.unit ? esc(h.unit) : '';
            bar = `<div class="th-trackline"><div class="th-bar${pct>=90?' near':''}"><div style="width:${pct}%"></div></div><span class="th-bartxt">${cum.toLocaleString()}/${tt.toLocaleString()}${unit}</span></div>`;
        }
        return `<div class="th-wrap">${top}${bar}</div>`;
    };

    // ── Todo List (목표 + 습관 통합) — 헤더 우측 아이콘으로 대시보드 ──
    html += `<div class="td-list-head">
        <span class="td-list-title">📋 Todo List</span>
        <span class="td-list-dash" onclick="openDashboard()" title="나의 기록 열기">📊</span>
    </div>`;
    html += TODO_GOALS.map(goalItem).join('');         // 목표 먼저
    {                                                   // 습관 나중 (시간대 그룹)
        let prevSlot = '__init__';
        html += TODO_HABITS.map(h=>{
            const slot = h.time_slot || '';
            let pre = '';
            if (slot !== prevSlot){ prevSlot = slot; if (slot && SLOT_LABELS[slot]) pre = `<div class="th-slot">${SLOT_LABELS[slot]}</div>`; }
            return pre + habitItem(h);
        }).join('');
    }
    if (!TODO_GOALS.length && !TODO_HABITS.length)
        html += '<div class="proj-empty">＋ 로 목표·습관 추가</div>';

    list.innerHTML = html;
}

// ==========================================================
// 목표·습관 (Todo) — 상태·로드·계산·토글
//   (TODO_HABITS / TODO_GOALS 선언은 상단 상태부에 위치)
// ==========================================================
function todayStr(){ return ymd(new Date()); }

async function loadTodoData(){
    const t = todayStr();
    try{
        const [hr, gr] = await Promise.all([
            fetch(`schedule_api.php?module=habit&action=list&today=${t}`, {cache:'no-store'}).then(r=>r.json()),
            fetch(`schedule_api.php?module=goal&action=list&today=${t}`,  {cache:'no-store'}).then(r=>r.json())
        ]);
        TODO_HABITS = (hr && hr.ok) ? hr.data : [];
        TODO_GOALS  = (gr && gr.ok) ? gr.data : [];
    }catch(e){ console.error('loadTodoData', e); }
    renderProjPanel();
    renderMonthHabitBar();
    if (typeof render==='function' && S && Array.isArray(S.events)) render();   // 캘린더 습관 점·블록 갱신
    if (dashOpen) renderDashboard();
}

// 로그 맵 (날짜→amount)
function habitLogMap(h){ const m=new Map(); (h.logs||[]).forEach(l=>m.set(l.d, +l.a||0)); return m; }
// 그날 "성공" 판정 (스펙 3.4 doneOn): 체크형=기록 존재, 측정형=amount>=하루목표
function doneOn(h, ds){
    const m = habitLogMap(h);
    if (!m.has(ds)) return false;
    const dt = (h.daily_target!=null && h.daily_target!=='') ? +h.daily_target : null;
    if (dt==null) return true;
    return (m.get(ds)||0) >= dt;
}

// 예정 여부 (스펙 3.4 isScheduled): 시작일~종료일(예정종료 or 실제종료 중 먼저) 범위 + 요일
function habitScheduled(h, d){
    const ds = ymd(d);
    if (h.start_date && ds < h.start_date) return false;
    const ends = [h.end_date, h.ended_at].filter(Boolean);
    if (ends.length){ ends.sort(); if (ds > ends[0]) return false; }   // 먼저 온 종료일
    const t = h.recur_type || 'daily';
    if (t==='weekdays'){
        const days = String(h.recur_days||'').split(',').filter(s=>s!=='').map(Number);
        return days.includes(d.getDay());              // 0=일 .. 6=토
    }
    return true;                                       // daily, week_quota
}

// streak (스펙 3.4): 오늘부터 뒤로, 예정된 날만 따라가며 연속 성공
function habitStreak(h){
    let n=0; const d=new Date(); d.setHours(0,0,0,0);
    for(let i=0;i<400;i++){
        if (h.start_date && ymd(d) < h.start_date) break;
        if (!habitScheduled(h, d)){ d.setDate(d.getDate()-1); continue; }
        const done = doneOn(h, ymd(d));
        if (i===0 && !done){ d.setDate(d.getDate()-1); continue; }     // 오늘 아직 → 어제부터
        if (done){ n++; d.setDate(d.getDate()-1); }
        else break;                                                   // 예정인데 미달 → 끊김
    }
    return n;
}

// 이번 달 달성률 (스펙 3.5): scheduled 대비 doneOn
function habitMonthPct(h, year, month){
    const today=new Date(); today.setHours(0,0,0,0);
    const last = new Date(year, month, 0).getDate();
    let sched=0, done=0;
    for(let day=1; day<=last; day++){
        const d=new Date(year, month-1, day);
        if (d>today) break;
        if (!habitScheduled(h,d)) continue;
        sched++;
        if (doneOn(h, ymd(d))) done++;
    }
    return { sched, done, pct: sched? Math.round(done/sched*100):0 };
}

// 오늘 예정이고 아직 미완료인 습관 (종료 제외는 listActive에서 이미 처리됨)
function habitPendingToday(){
    const t = todayStr();
    const today = new Date(); today.setHours(0,0,0,0);
    return TODO_HABITS.filter(h => habitScheduled(h, today) && !doneOn(h, t));
}

// 월 캘린더 하단 '오늘 남은 습관' 고정 바 (습관 전용·데이터 불변, doneOn 조회시점 계산)
function renderMonthHabitBar(){
    const host = document.getElementById('month-habit-bar');
    if (!host) return;
    if (!TODO_HABITS.length){ host.style.display='none'; host.innerHTML=''; clearTimeout(host._collapse); return; }
    const pending = habitPendingToday();
    if (!pending.length){
        host.style.display='';
        host.innerHTML = `<div class="mhb-done">✓ 오늘 습관 다 했어요</div>`;
        clearTimeout(host._collapse);
        host._collapse = setTimeout(()=>{ host.style.display='none'; }, 1800);  // 접힘
        return;
    }
    clearTimeout(host._collapse);
    host.style.display='';
    const chips = pending.map(h=>{
        const unit = h.unit ? esc(h.unit) : '';
        const track = (+h.track_total===1) && h.target_total;
        const sub = track ? `<div class="mhb-sub">${(+h.cum_total||0).toLocaleString()}/${(+h.target_total).toLocaleString()}${unit}</div>` : '';
        return `<div class="mhb-chip" onclick="habitIconTap(event,${h.id})">
            <span class="mhb-box">☐</span>
            <div style="min-width:0;"><div class="mhb-name">${esc((h.icon||'')+h.title)}</div>${sub}</div>
        </div>`;
    }).join('');
    host.innerHTML = `<div class="mhb-head"><span>🔁</span><span class="mhb-head-lbl">오늘 남은 습관</span><span class="mhb-cnt">${pending.length}</span></div><div class="mhb-chips">${chips}</div>`;
}

// 자정 지나면 그날 예정 습관으로 리셋 (조회시점 today 재계산)
let _mhbDay = todayStr();
setInterval(()=>{ const d=todayStr(); if(d!==_mhbDay){ _mhbDay=d; loadTodoData(); } }, 60000);

// 로컬 낙관적 갱신 (logs 배열 = [{d,a}])
function applyHabitLocal(h, t, amount, cumTotal){
    h.logs = (h.logs||[]).filter(l=>l.d!==t);
    if (amount>0) h.logs.push({d:t, a:amount});
    h.today_amount = amount>0 ? amount : 0;
    if (cumTotal!=null) h.cum_total = cumTotal;
}

// 사이드바 오늘 체크 아이콘 탭: 체크형=토글 / 측정형=오늘 기록 모달
function habitIconTap(e, id){
    if(e){ e.stopPropagation(); }
    const h = TODO_HABITS.find(x=>x.id==id);
    if (!h){ toggleHabitToday(null, id); return; }
    const measure = (h.daily_target != null && h.daily_target !== '');
    if (!measure){ toggleHabitToday(null, id); return; }   // 순수 체크형=토글
    openHabitLog(id);                                       // 측정형=모달(실시간 미리보기)
}

// ── 습관 오늘 기록 모달 (측정형 · 증분 입력) ──
let HL = { id:0, habit:null, track:false };
function openHabitLog(id){
    const h = TODO_HABITS.find(x=>x.id==id);
    if (!h) return;
    HL = { id:id, habit:h, track:(+h.track_total===1) && !!h.target_total };
    const DK=['일','월','화','수','목','금','토'];
    const d = new Date();
    document.getElementById('hl-icon').textContent = h.icon || '🔁';
    document.getElementById('hl-name').textContent = h.title || '';
    document.getElementById('hl-date').textContent = `오늘 · ${d.getMonth()+1}/${d.getDate()} (${DK[d.getDay()]})`;
    const unit = h.unit || '';
    document.getElementById('hl-unit').textContent = unit;
    const refEl = document.getElementById('hl-ref');
    if (h.ref_url){
        refEl.style.display = 'flex';
        refEl.textContent = ytId(h.ref_url) ? '📺 참고 영상 보기' : '🔗 참고 링크 열기';
        refEl.dataset.url = h.ref_url;
    } else { refEl.style.display = 'none'; }
    hlRender();
    hlSetMode('add');   // 기본은 항상 ＋추가
    document.getElementById('habit-log-overlay').classList.add('open');
    setTimeout(()=>{ document.getElementById('hl-val').focus(); }, 30);
}
function closeHabitLog(){ document.getElementById('habit-log-overlay').classList.remove('open'); }

// 참고 링크: 유튜브면 임베드 팝업, 그 외엔 새 창 팝업
function ytId(url){
    if (!url) return null;
    try {
        const u = new URL(url);
        const host = u.hostname.replace(/^www\./,'');
        if (host==='youtu.be') return u.pathname.slice(1) || null;
        if (host.endsWith('youtube.com')){
            if (u.pathname==='/watch') return u.searchParams.get('v');
            const m = u.pathname.match(/\/(embed|shorts|v)\/([^/?#]+)/);
            if (m) return m[2];
        }
    } catch(e){}
    return null;
}
function openHabitRef(e, url){
    if (e){ e.stopPropagation(); }
    if (!url) return;
    const id = ytId(url);
    if (id){
        document.getElementById('yt-frame').src = `https://www.youtube.com/embed/${id}?autoplay=1`;
        document.getElementById('yt-overlay').classList.add('open');
    } else {
        window.open(url, 'habitRef', 'width=900,height=720,scrollbars=yes,resizable=yes');
    }
}
function closeYt(){
    document.getElementById('yt-overlay').classList.remove('open');
    document.getElementById('yt-frame').src = '';   // 재생 중지
}

// 현재 상태(HL.habit)로 진행 표시 갱신 — 저장이 끝난 뒤 다시 그림
function hlRender(){
    const h = HL.habit; if(!h) return;
    const unit = h.unit || '';
    const ta = +h.today_amount || 0;
    const dt = +h.daily_target || 0;
    // 오늘 = 히어로
    document.getElementById('hl-today-target').textContent = (dt ? dt.toLocaleString() : '0') + unit;
    document.getElementById('hl-today-now').textContent = ta.toLocaleString();
    document.getElementById('hl-today-slash').textContent = ' / ' + (dt ? dt.toLocaleString() : '0');
    const tpct = dt>0 ? Math.min(100, ta/dt*100) : (ta>0 ? 100 : 0);
    document.getElementById('hl-today-bar').style.width = tpct + '%';
    document.getElementById('hl-today-remain').textContent = (dt>0 && ta>=dt) ? '✓ 달성' : '';
    // streak 칩(헤더)
    const sk = h.recur_type==='week_quota' ? 0 : habitStreak(h);
    const skEl = document.getElementById('hl-streak');
    if (sk>0){ skEl.textContent = `🔥 ${sk}`; skEl.style.display = 'inline-flex'; }
    else { skEl.style.display = 'none'; }
    // 누적 = 요약 (track_total만)
    document.getElementById('hl-track').style.display = HL.track ? 'block' : 'none';
    if (HL.track){
        const cum = +h.cum_total || 0, tt = +h.target_total;
        const cpct = tt>0 ? Math.min(100, cum/tt*100) : 0;
        document.getElementById('hl-bar-base').style.width = cpct + '%';
        document.getElementById('hl-now').textContent = cum.toLocaleString();
        document.getElementById('hl-target').textContent = `/ ${tt.toLocaleString()}${unit}`;
        document.getElementById('hl-pct').textContent = (Math.round(cpct*10)/10) + '%';
        document.getElementById('hl-cum-remain').textContent = cum>=tt
            ? '목표 달성!' : `목표까지 ${(tt-cum).toLocaleString()}${unit}`;
    }
}

// 입력 방식 전환: add=방금 한 양(증분) / total=현재 누적값 직접 입력
function hlSetMode(m){
    HL.mode = (m==='total') ? 'total' : 'add';
    document.querySelectorAll('#hl-mode button').forEach(b=>b.classList.toggle('active', b.dataset.m===HL.mode));
    const h = HL.habit; if(!h) return;
    const unit = h.unit || '';
    const inp = document.getElementById('hl-val');
    const lbl = document.getElementById('hl-mode-label');
    const hint = document.getElementById('hl-mode-hint');
    if (HL.mode==='total'){
        const cur = HL.track ? (+h.cum_total||0) : (+h.today_amount||0);   // 책=누적페이지 / 그 외=오늘총량
        inp.value = cur>0 ? cur : '';
        inp.placeholder = HL.track ? '현재 누적' : '오늘 누적';
        lbl.textContent = '누적값';
        hint.textContent = HL.track ? '지금까지의 누적 합계를 이 값으로 맞춰요' : '오늘까지의 총량을 이 값으로 맞춰요';
    } else {
        inp.value = '';
        inp.placeholder = '방금 한 양';
        lbl.textContent = '방금 한 양';
        hint.textContent = '방금 한 양만큼 누적에 더해요';
    }
    if (HL.mode==='add') inp.focus();   // total은 미리채운 값 보이게 자동포커스 안 함(클릭 시 지워짐)
}

// 기록 — 모드에 따라 증분(add) 또는 누적 절대값(log)으로 저장. 모달은 열어둔 채 갱신
async function hlSubmit(){
    const h = HL.habit; if(!h) return;
    const id = HL.id, t = todayStr();
    const inp = document.getElementById('hl-val');
    if (inp.value.trim()===''){ inp.focus(); return; }   // 빈 입력=실수 방지(누적 0 처리 안 함)
    const raw = Math.max(0, parseInt(inp.value||'0',10) || 0);
    let url, body;
    if (HL.mode==='total'){
        // 현재 누적값 → 오늘분 = 누적 − 이전누적(어제까지). 이전보다 작으면 0 클램프
        const prev = HL.track ? Math.max(0, (+h.cum_total||0) - (+h.today_amount||0)) : 0;
        let today = raw - prev;
        if (today < 0) today = 0;
        url = 'schedule_api.php?module=habit&action=log';
        body = {habit_id:id, date:t, amount:today};
    } else {
        if (raw<=0){ inp.focus(); return; }
        url = 'schedule_api.php?module=habit&action=add';
        body = {habit_id:id, date:t, delta:raw};
    }
    const r = await fetch(url, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)}).then(r=>r.json()).catch(()=>null);
    if (!r || !r.ok){ showToast('기록 실패'); return; }
    if (h) applyHabitLocal(h, t, (+r.amount||0), r.cum_total);
    renderProjPanel(); renderMonthHabitBar();
    if (dashOpen) renderDashboard();
    const unit = h.unit || '';
    if (r.ended){ showToast('🎉 누적 목표 달성! 습관을 졸업했어요'); closeHabitLog(); await loadTodoData(); return; }
    hlRender();
    if (HL.mode==='total'){
        showToast(`누적 ${(HL.track ? (+r.cum_total||0) : (+r.amount||0)).toLocaleString()}${unit}`);
        hlSetMode('total');   // 새 누적값을 입력칸에 다시 표시
    } else {
        inp.value = ''; inp.focus();
        showToast(`+${raw.toLocaleString()}${unit} · 오늘까지 ${(+r.amount||0).toLocaleString()}${unit}`);
    }
}

// 체크형 토글
async function toggleHabitToday(e, id){
    if(e){ e.stopPropagation(); }
    const t = todayStr();
    const r = await fetch('schedule_api.php?module=habit&action=toggle', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({habit_id:id, date:t})
    }).then(r=>r.json()).catch(()=>null);
    if (!r || !r.ok) { showToast('습관 토글 실패'); return; }
    if (r.ended){ showToast('🎉 목표 달성! 습관을 졸업했어요'); await loadTodoData(); return; }
    const h = TODO_HABITS.find(x=>x.id==id);
    if (h) applyHabitLocal(h, t, r.done ? (r.amount||1) : 0, r.cum_total);
    renderProjPanel();
    renderMonthHabitBar();
    if (dashOpen) renderDashboard();
}

// 일간뷰 타임라인 블록의 체크 토글 (해당 날짜 기준). 측정형이고 오늘이면 기록 모달.
async function habitBlockToggle(e, id, ds){
    if(e){ e.stopPropagation(); }
    const h = TODO_HABITS.find(x=>x.id==id);
    const measure = h && (h.daily_target!=null && h.daily_target!=='');
    if (measure && ds===todayStr()){ openHabitLog(id); return; }   // 측정형 오늘 → 기록 모달
    const r = await fetch('schedule_api.php?module=habit&action=toggle', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({habit_id:id, date:ds})
    }).then(r=>r.json()).catch(()=>null);
    if (!r || !r.ok) { showToast('습관 토글 실패'); return; }
    await loadTodoData();   // 습관 로그 갱신(사이드바·하단바·캘린더 점/블록 — loadTodoData가 render 호출)
}

async function toggleGoalManual(e, id){
    if(e){ e.stopPropagation(); }
    const r = await fetch('schedule_api.php?module=goal&action=toggle', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({goal_id:id, today: todayStr()})
    }).then(r=>r.json()).catch(()=>null);
    if (!r || !r.ok) { showToast('목표 체크 실패'); return; }
    loadTodoData();
}

// ==========================================================
// 목표·습관 입력 모달
// ==========================================================
let TD = { type:'goal', id:0 };
const TD_DAY_LABELS = ['일','월','화','수','목','금','토'];

async function openTodoModal(type, id){
    TD = { type: type||'goal', id: +id||0 };
    document.getElementById('proj-add-menu')?.classList.remove('open');
    await tdFillCategories();
    // 기본값
    document.getElementById('td-g-title').value = '';
    document.getElementById('td-g-cnt').value   = 1;
    document.getElementById('td-g-per').value   = 'month';
    document.getElementById('td-g-cat').value   = '';
    tdSetMode('auto');
    document.getElementById('td-h-title').value = '';
    document.getElementById('td-h-cat').value = '';
    document.getElementById('td-h-ref').value = '';
    tdFillHabitCats();
    document.getElementById('td-h-quota').value = 3;
    document.getElementById('td-h-daily').value = '';
    document.getElementById('td-h-unit').value = '';
    document.getElementById('td-h-tracksw').checked = false;
    document.getElementById('td-h-target').value = '';
    document.getElementById('td-h-enddate').value = '';
    document.getElementById('td-h-timesw').checked = false;
    document.getElementById('td-h-time').value = '07:00';
    document.getElementById('td-h-endbox').style.display = 'none';
    TD_REMINDERS = [];
    tdSetSlot('');                 // 시간대 미선택
    tdToggleTrack(); tdToggleTime(); tdSyncUnit(); tdRenderReminders();
    tdSetRecur('daily');
    tdRenderSwatches();
    tdSetColor(loadLastColor() || SCH_SWATCHES[0]);

    // 편집: 캐시에서 채우기
    if (TD.id){
        if (type==='goal'){
            const g = TODO_GOALS.find(x=>x.id==TD.id);
            if (g){
                document.getElementById('td-g-title').value = g.title||'';
                document.getElementById('td-g-cnt').value   = g.cnt||1;
                document.getElementById('td-g-per').value   = g.period||'month';
                document.getElementById('td-g-cat').value   = g.category_id||'';
                tdSetMode(g.mode||'auto');
                tdSetColor(g.color||SCH_SWATCHES[0]);
            }
        } else {
            const h = TODO_HABITS.find(x=>x.id==TD.id);
            if (h){
                document.getElementById('td-h-title').value = h.title||'';
                document.getElementById('td-h-cat').value = h.category||'';
                document.getElementById('td-h-ref').value = h.ref_url||'';
                tdSetRecur(h.recur_type||'daily');
                if (h.recur_type==='weekdays'){
                    const days = String(h.recur_days||'').split(',').filter(s=>s!=='').map(Number);
                    document.querySelectorAll('#td-h-days button').forEach(b=>{
                        const on = days.includes(+b.dataset.day);
                        b.classList.toggle('on', on); b.dataset.on = on?'1':'0';
                    });
                }
                if (h.recur_type==='week_quota') document.getElementById('td-h-quota').value = h.week_quota||3;
                // 측정형/누적
                document.getElementById('td-h-daily').value = (h.daily_target!=null) ? h.daily_target : '';
                document.getElementById('td-h-unit').value  = h.unit || '';
                document.getElementById('td-h-tracksw').checked = (+h.track_total===1);
                document.getElementById('td-h-target').value = (h.target_total!=null) ? h.target_total : '';
                tdToggleTrack(); tdSyncUnit();
                // 시간대 / 알림 / 예정종료
                tdSetSlot(h.time_slot || '');
                TD_REMINDERS = String(h.reminders||'').split(',').map(s=>s.trim()).filter(Boolean);
                tdRenderReminders();
                document.getElementById('td-h-enddate').value = h.end_date || '';
                // 캘린더 시각
                if (h.do_time){ document.getElementById('td-h-timesw').checked=true; document.getElementById('td-h-time').value=String(h.do_time).slice(0,5); }
                tdToggleTime();
                tdSetColor(h.color||SCH_SWATCHES[0]);
                document.getElementById('td-h-endbox').style.display = 'block';   // 편집 시 종료 가능
            }
        }
    }
    setTodoTab(TD.type);
    document.getElementById('td-del').style.display = TD.id ? '' : 'none';
    document.getElementById('td-popover').style.display = 'none';
    document.getElementById('todo-overlay').classList.add('open');
    tdGoalPrev();
}
function closeTodoModal(){ document.getElementById('todo-overlay').classList.remove('open'); }

function setTodoTab(t){
    TD.type = t;
    document.querySelectorAll('#todo-overlay .type-tab').forEach(b=>b.classList.toggle('active', b.dataset.t===t));
    document.getElementById('td-pane-goal').style.display  = t==='goal'  ? 'block' : 'none';
    document.getElementById('td-pane-habit').style.display = t==='habit' ? 'block' : 'none';
}

async function tdFillCategories(){
    const sel = document.getElementById('td-g-cat');
    let cats = [];
    try{
        const r = await fetch('schedule_api.php?module=goal&action=categories', {cache:'no-store'}).then(r=>r.json());
        if (r && r.ok) cats = r.data;
    }catch(e){}
    sel.innerHTML = '<option value="">（제목으로 자동 생성）</option>'
        + cats.map(c=>`<option value="${c.id}">${esc(c.title)}</option>`).join('');
}
function tdCatChange(){ /* 분류를 직접 고르면 자동 집계가 자연스러움 → auto 유지 */ }

const TD_PER_WORDS = {week:'한 주에', month:'한 달에', quarter:'분기에', year:'한 해에'};
function tdGoalPrev(){
    const n = Math.max(1, parseInt(document.getElementById('td-g-cnt').value||'1',10));
    const p = document.getElementById('td-g-per').value;
    document.getElementById('td-g-prev').textContent = `🏳 ${TD_PER_WORDS[p]||''} ${n}번`;
}
function tdSetMode(m){
    document.querySelectorAll('#td-g-modeseg button').forEach(b=>b.classList.toggle('active', b.dataset.m===m));
    const sel = document.getElementById('td-g-cat');
    sel.disabled = (m==='manual');
    sel.style.opacity = (m==='manual') ? '.5' : '';
    document.getElementById('td-g-help').textContent = (m==='manual')
        ? '기간마다 직접 "달성"을 체크합니다 (분류 연결 없음)'
        : '연결 분류에 이번 기간 일정이 쌓인 만큼 진행률이 오릅니다';
    TD.mode = m;
}

function tdSetRecur(k){
    document.querySelectorAll('#td-h-recurseg button').forEach(b=>b.classList.toggle('active', b.dataset.k===k));
    if (!document.querySelector('#td-h-days button')) tdRenderDayChips();
    document.getElementById('td-h-days').style.display = k==='weekdays'   ? 'flex' : 'none';
    document.getElementById('td-h-week').style.display = k==='week_quota' ? 'flex' : 'none';
    TD.recur = k;
}
function tdRenderDayChips(){
    document.getElementById('td-h-days').innerHTML =
        TD_DAY_LABELS.map((d,i)=>`<button type="button" data-day="${i}" data-on="0" onclick="tdToggleDay(this)">${d}</button>`).join('');
}
function tdToggleDay(b){ const on=b.dataset.on==='1'; b.dataset.on=on?'0':'1'; b.classList.toggle('on', !on); }
function tdToggleTime(){ document.getElementById('td-h-timerow').style.display = document.getElementById('td-h-timesw').checked ? 'block' : 'none'; }

/* v2: 누적 목표 / 시간대 / 단위동기 / 알림(다중) / 종료 */
let TD_REMINDERS = [];   // ['08:00','12:00']
function tdToggleTrack(){ document.getElementById('td-h-trackrow').style.display = document.getElementById('td-h-tracksw').checked ? 'flex' : 'none'; }
function tdSyncUnit(){ document.getElementById('td-h-target-unit').textContent = document.getElementById('td-h-unit').value.trim() || ''; }
// 기존 습관들에서 쓰인 분류를 datalist 자동완성으로 제공
function tdFillHabitCats(){
    const dl = document.getElementById('td-h-cat-list');
    if (!dl) return;
    const cats = [...new Set((TODO_HABITS||[]).map(h=>(h.category||'').trim()).filter(Boolean))].sort();
    dl.innerHTML = cats.map(c=>`<option value="${esc(c)}">`).join('');
}
function tdSetSlot(s){
    TD.slot = s || '';
    document.querySelectorAll('#td-h-slotseg button').forEach(b=>b.classList.toggle('active', b.dataset.s===TD.slot));
}
function tdAddReminder(){ TD_REMINDERS.push('08:00'); tdRenderReminders(); }
function tdRemoveReminder(i){ TD_REMINDERS.splice(i,1); tdRenderReminders(); }
function tdReminderChange(i, v){ TD_REMINDERS[i] = v; }
function tdRenderReminders(){
    const host = document.getElementById('td-h-reminders');
    host.innerHTML = TD_REMINDERS.map((tm,i)=>
        `<span class="td-rem"><input class="td-inp" type="time" value="${tm}" style="width:auto;" onchange="tdReminderChange(${i},this.value)"><button type="button" onclick="tdRemoveReminder(${i})" title="삭제">✕</button></span>`).join('');
}
async function tdEndHabit(reason){
    if (!TD.id) return;
    const msg = reason==='completed' ? '목표 달성으로 이 습관을 졸업할까요? (기록은 보존)' : '이 습관을 그만둘까요? (기록은 보존)';
    if (!confirm(msg)) return;
    const r = await fetch('schedule_api.php?module=habit&action=end', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({habit_id:TD.id, reason})
    }).then(r=>r.json()).catch(()=>null);
    if (!r || !r.ok){ showToast('종료 실패'); return; }
    closeTodoModal();
    await loadTodoData();
    showToast(reason==='completed' ? '🎓 졸업했어요' : '종료했어요');
}

/* 색상 picker (이벤트 모달 팔레트 재사용) */
function tdRenderSwatches(){
    const wrap = document.getElementById('td-preset-wrap');
    wrap.innerHTML = SCH_SWATCHES.map((c,i)=>
        `<span class="color-swatch" data-color="${c}" style="background:${c}" onclick="tdSetColor('${c}')"></span>`).join('');
}
function tdSetColor(hex){
    document.getElementById('td-color').value = hex;
    document.getElementById('td-custom').style.background = hex;
    document.getElementById('td-custom').textContent = '';
    document.querySelectorAll('#td-preset-wrap .color-swatch').forEach(s=>
        s.style.boxShadow = (s.dataset.color===hex) ? '0 0 0 2px #fff,0 0 0 3px #2c3e50' : '');
}
function tdOpenPalette(e){
    if(e) e.stopPropagation();
    buildPaletteInto('td-palette','tdPick');
    document.getElementById('td-hex').value = document.getElementById('td-color').value;
    document.getElementById('td-preview').style.background = document.getElementById('td-color').value;
    document.getElementById('td-popover').style.display = 'block';
}
function tdPick(hex){
    document.getElementById('td-hex').value = hex;
    document.getElementById('td-preview').style.background = hex;
}
function tdApplyHex(){
    let v = (document.getElementById('td-hex').value||'').trim();
    if (/^[0-9a-fA-F]{6}$/.test(v)) v = '#'+v;
    if (!/^#[0-9a-fA-F]{6}$/.test(v)) { alert('#RRGGBB 형식'); return; }
    v = v.toLowerCase();
    if (!SCH_SWATCHES.includes(v)) saveLastColor(v);
    tdSetColor(v);
    document.getElementById('td-popover').style.display = 'none';
}

async function saveTodoModal(){
    const color = document.getElementById('td-color').value || '#3498db';
    let module, payload;
    if (TD.type==='goal'){
        const title = document.getElementById('td-g-title').value.trim();
        if (!title){ alert('목표 이름을 입력하세요'); return; }
        const mode = TD.mode || 'auto';
        const catSel = document.getElementById('td-g-cat').value;
        module = 'goal';
        payload = {
            title, color, mode,
            cnt: Math.max(1, parseInt(document.getElementById('td-g-cnt').value||'1',10)),
            period: document.getElementById('td-g-per').value,
            category_id: (mode==='auto' && catSel) ? +catSel : null
        };
    } else {
        const title = document.getElementById('td-h-title').value.trim();
        if (!title){ alert('습관 제목을 입력하세요'); return; }
        const recur = TD.recur || 'daily';
        let days = null, quota = null;
        if (recur==='weekdays'){
            days = Array.from(document.querySelectorAll('#td-h-days button'))
                .filter(b=>b.dataset.on==='1').map(b=>+b.dataset.day);
            if (!days.length){ alert('요일을 1개 이상 선택하세요'); return; }
        }
        if (recur==='week_quota') quota = Math.max(1, parseInt(document.getElementById('td-h-quota').value||'1',10));
        const timeOn = document.getElementById('td-h-timesw').checked;
        const dailyV = document.getElementById('td-h-daily').value.trim();
        const trackOn = document.getElementById('td-h-tracksw').checked;
        const targetV = document.getElementById('td-h-target').value.trim();
        module = 'habit';
        payload = {
            title, color, recur_type: recur,
            category: document.getElementById('td-h-cat').value.trim(),
            ref_url: document.getElementById('td-h-ref').value.trim(),
            recur_days: days, week_quota: quota,
            daily_target: dailyV !== '' ? Math.max(1, parseInt(dailyV,10)||0) : '',
            unit: document.getElementById('td-h-unit').value.trim(),
            track_total: trackOn ? 1 : 0,
            target_total: (trackOn && targetV !== '') ? Math.max(1, parseInt(targetV,10)||0) : '',
            time_slot: TD.slot || '',
            reminders: TD_REMINDERS.slice(),
            end_date: document.getElementById('td-h-enddate').value || '',
            do_time: timeOn ? document.getElementById('td-h-time').value : '',
            // 편집 시 시작일 보존(오늘로 덮어쓰기 방지)
            start_date: TD.id ? ((TODO_HABITS.find(x=>x.id==TD.id)||{}).start_date || todayStr()) : todayStr()
        };
    }
    if (TD.id) payload.id = TD.id;
    const action = TD.id ? 'update' : 'create';
    const r = await fetch(`schedule_api.php?module=${module}&action=${action}`, {
        method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)
    }).then(r=>r.json()).catch(()=>null);
    if (!r || !r.ok){ showToast((r&&r.msg)||'저장 실패'); return; }
    closeTodoModal();
    if (module==='goal') await loadProjPanel();   // 자동 생성된 분류 반영
    await loadTodoData();
    showToast(TD.id ? '수정했습니다.' : '추가했습니다.');
}

async function deleteTodo(){
    if (!TD.id) return;
    const what = TD.type==='goal' ? '목표' : '습관';
    if (!confirm(`이 ${what}을(를) 삭제할까요?`)) return;
    const r = await fetch(`schedule_api.php?module=${TD.type}&action=delete&id=${TD.id}`, {cache:'no-store'})
        .then(r=>r.json()).catch(()=>null);
    if (!r || !r.ok){ showToast('삭제 실패'); return; }
    closeTodoModal();
    await loadTodoData();
    showToast('삭제했습니다.');
}

// 오버레이 바깥 클릭 닫기
document.getElementById('todo-overlay').addEventListener('click', function(e){
    if (e.target === this) closeTodoModal();
});
document.getElementById('dash-overlay').addEventListener('click', function(e){
    if (e.target === this) closeDashboard();
});
document.getElementById('habit-log-overlay').addEventListener('click', function(e){
    if (e.target === this) closeHabitLog();
});
document.addEventListener('keydown', e=>{
    if(e.key==='Escape'){
        if (dashOpen) closeDashboard();
        if (document.getElementById('habit-log-overlay').classList.contains('open')) closeHabitLog();
    }
});

// ==========================================================
// 📊 대시보드 모달 (캘린더와 독립된 월 상태 — 선언은 상단 상태부)
// ==========================================================
function openDashboard(){
    document.getElementById('proj-add-menu')?.classList.remove('open');
    DASH = { year: TODAY.getFullYear(), month: TODAY.getMonth()+1 };
    dashOpen = true;
    document.getElementById('dash-overlay').classList.add('open');
    renderDashboard();
}
function closeDashboard(){
    dashOpen = false;
    document.getElementById('dash-overlay').classList.remove('open');
}
function dashNav(dir){
    DASH.month += dir;
    if (DASH.month>12){ DASH.month=1; DASH.year++; }
    if (DASH.month<1){ DASH.month=12; DASH.year--; }
    renderDashboard();
}
function dashToday(){ DASH = { year: TODAY.getFullYear(), month: TODAY.getMonth()+1 }; renderDashboard(); }

async function renderDashboard(){
    const host = document.getElementById('dash-body');
    if (!host) return;
    const MK=['1월','2월','3월','4월','5월','6월','7월','8월','9월','10월','11월','12월'];
    const lblEl = document.getElementById('dash-month-label');
    if (lblEl) lblEl.textContent = `${DASH.year}년 ${MK[DASH.month-1]}`;
    const y = DASH.year, m = DASH.month;
    // 지난(종료된) 습관 — 회고
    let ended = [];
    try{
        const er = await fetch('schedule_api.php?module=habit&action=ended', {cache:'no-store'}).then(r=>r.json());
        if (er && er.ok) ended = er.data;
    }catch(e){}

    // ── 요약: 목표·습관만 계산 (할일 제외) ──
    // 1) 이번 달 습관 달성률 = 전체 활성 습관의 예정일 대비 완료 합산
    let schedSum=0, doneSum=0;
    TODO_HABITS.forEach(h=>{ const mp=habitMonthPct(h,y,m); schedSum+=mp.sched; doneSum+=mp.done; });
    const achPct = schedSum ? Math.round(doneSum/schedSum*100) : 0;
    // 2) 이번 기간 목표 달성 = met / 전체 목표
    const goalMet = TODO_GOALS.filter(g=>g.progress && g.progress.met).length;
    const goalTot = TODO_GOALS.length;
    // 3) 최고 streak (습관)
    const maxStreak = TODO_HABITS.reduce((mx,h)=>Math.max(mx, (h.recur_type==='week_quota'?0:habitStreak(h))), 0);

    // 요약 타일 3개 (습관 달성률 · 목표 달성 · 최고 streak)
    let html = `<div class="dash-wrap">
      <div class="dash-tiles">
        <div class="dash-tile"><div class="dash-tile-v">${achPct}%</div><div class="dash-tile-l">이번 달 습관</div></div>
        <div class="dash-tile"><div class="dash-tile-v">${goalMet}<span class="dash-tile-sub">/${goalTot}</span></div><div class="dash-tile-l">목표 달성</div></div>
        <div class="dash-tile"><div class="dash-tile-v" style="color:#e6920a;">🔥${maxStreak}</div><div class="dash-tile-l">최고 streak</div></div>
      </div>`;

    // 습관 — 틴트 카드 (색조 배경 + 히트맵 + 누적바)
    html += `<div class="dash-sec-h">✓ 습관</div>`;
    if (!TODO_HABITS.length){
        html += `<div class="dash-empty">＋ 로 습관을 추가하세요</div>`;
    } else {
        html += `<div class="dash-tcards">`;
        TODO_HABITS.forEach(h=>{ html += dashHabitHeat(h, y, m); });
        html += `</div>`;
    }

    // 목표 진행
    html += `<div class="dash-sec-h">🎯 목표</div>`;
    if (!TODO_GOALS.length){
        html += `<div class="dash-empty">＋ 로 목표를 추가하세요</div>`;
    } else {
        html += `<div class="dash-gcards">`;
        TODO_GOALS.forEach(g=>{
            const pr = g.progress || {current:0,total:1,met:false};
            const pct = Math.min(100, Math.round(pr.current/Math.max(1,pr.total)*100));
            const note = {week:'이번 주', month:'이번 달', quarter:'분기', year:'올해'}[g.period]||'';
            const cs = dashColorSet(g.color||'#e6920a');
            html += `<div class="dash-tcard goal" style="--dh:${cs.fill};--dfg:${cs.fg};background:${cs.tint};color:${cs.fg}" onclick="openTodoModal('goal',${g.id})">
                <div class="dash-gcard-head">
                    <div class="dash-tcard-ico" style="color:${cs.fg}">${esc(g.icon||'🎯')}</div>
                    <div class="dash-gcard-meta">
                        <span class="dash-gcard-name">${esc(g.title)} <span class="dash-gcard-note">${note}</span></span>
                        <span class="dash-gcard-cnt">${pr.current} / ${pr.total}</span>
                    </div>
                </div>
                <div class="dash-tcard-bar"><div style="width:${pct}%"></div></div>
            </div>`;
        });
        html += `</div>`;
    }

    // 지난 습관 (회고) — 종료된 것만, 졸업/그만둠 구분
    if (ended.length){
        const compN = ended.filter(h=>h.end_reason==='completed').length;
        html += `<div class="dash-sec-h">📚 지난 습관 <span style="color:#1d9e75;">· ${compN} 졸업</span></div><div class="dash-ended">`;
        ended.forEach(h=>{
            const done = h.end_reason==='completed';
            const badge = done ? `<span class="dash-end-badge done">🎓 졸업</span>` : `<span class="dash-end-badge stop">그만둠</span>`;
            const unit = h.unit ? esc(h.unit) : '';
            const cum = (+h.track_total===1 && h.target_total) ? ` · ${(+h.cum_total||0)}/${h.target_total}${unit}` : (h.cum_total>0?` · 누적 ${h.cum_total}${unit}`:'');
            html += `<div class="dash-end-row">
                <span class="proj-dot" style="background:${h.color||'#3498db'}"></span>
                <span class="${done?'':'dash-end-stopname'}">${esc((h.icon||'')+h.title)}</span>
                <span class="dash-end-meta">${(h.ended_at||'').slice(0,10)}${cum}</span>
                ${badge}
                <span class="dash-end-reopen" onclick="reopenHabit(${h.id})" title="다시 시작">↺</span>
            </div>`;
        });
        html += `</div>`;
    }

    html += `</div>`;
    host.innerHTML = html;
}

async function reopenHabit(id){
    if (!confirm('이 습관을 다시 시작할까요?')) return;
    const r = await fetch('schedule_api.php?module=habit&action=reopen', {
        method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({habit_id:id})
    }).then(r=>r.json()).catch(()=>null);
    if (!r || !r.ok){ showToast('재시작 실패'); return; }
    await loadTodoData();
    showToast('다시 시작했어요');
}

// 한 습관의 이번 달 히트맵 (1일→말일 순서, flex-wrap) + 누적 진행바
function dashHabitHeat(h, year, month){
    const today = new Date(); today.setHours(0,0,0,0);
    const lastDay = new Date(year, month, 0).getDate();
    let cells = '';
    for (let day=1; day<=lastDay; day++){
        const d = new Date(year, month-1, day);
        const isToday = d.getTime()===today.getTime();
        let cls = 'hc';
        if (!habitScheduled(h,d)) cls='hc out';        // 예정 아님(범위 밖·요일 제외)
        else if (isToday) cls='hc today';
        else if (d>today) cls='hc future';
        else if (doneOn(h, ymd(d))) cls='hc done';     // 성공(측정형=목표달성)
        else cls='hc miss';
        cells += `<span class="${cls}"></span>`;
    }
    const mp = habitMonthPct(h, year, month);
    const sk = h.recur_type==='week_quota' ? 0 : habitStreak(h);
    const unit = h.unit ? esc(h.unit) : '';
    const cs = dashColorSet(h.color||'#3498db');
    const icon = esc(h.icon || '⭐');                  // 카테고리 아이콘(이모지). 반복아이콘(🔁) 금지 — 스펙 §2
    const recurLbl = h.recur_type==='weekdays' ? '요일' : (h.recur_type==='week_quota' ? '주'+(h.week_quota||1)+'회' : '매일');
    const badgeLbl = (h.category||'').trim() || recurLbl;   // 분류 있으면 분류 배지, 없으면 반복라벨
    // 누적 진행바 (track_total)
    let track = '';
    if (+h.track_total===1 && h.target_total){
        const cum = +h.cum_total||0, tt = +h.target_total;
        const pct = Math.min(100, Math.round(cum/Math.max(1,tt)*100));
        track = `<div class="dash-tcard-row" style="margin-top:11px;margin-bottom:6px;">
                    <span class="l">누적</span><b>${cum.toLocaleString()} / ${tt.toLocaleString()}${unit}</b>
                 </div>
                 <div class="dash-tcard-bar"><div style="width:${pct}%"></div></div>`;
    }
    return `<div class="dash-tcard" style="--dh:${cs.fill};--dfg:${cs.fg};background:${cs.tint};color:${cs.fg}">
        <div class="dash-tcard-head">
            <div class="dash-tcard-ico" style="color:${cs.fg}">${icon}</div>
            <div class="dash-tcard-meta">
                <span class="dash-tcard-badge" style="color:${cs.fg}">${esc(badgeLbl)}</span>
                <div class="dash-tcard-name">${esc(h.title)}</div>
            </div>
            ${sk>0?`<span class="dash-tcard-streak">🔥${sk}</span>`:''}
        </div>
        <div class="dash-tcard-row">
            <span class="l">체크</span><b>이번 달 ${mp.pct}%</b>
        </div>
        <div class="hc-grid">${cells}</div>
        ${track}
    </div>`;
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

// 프로젝트 드롭다운 채우기: 현재 일정 날짜에 걸친 프로젝트들을 옵션으로 나열.
// forceId가 주어지면 그 값을 선택(편집 시 저장된 프로젝트), undefined면 기존 선택 유지.
// 날짜에 걸친 프로젝트가 하나도 없고 선택된 것도 없으면 줄 자체를 숨긴다.
function populateProjectSelect(forceId) {
    const sel  = document.getElementById('f-project-id');
    const row  = document.getElementById('row-project');
    const date = curEventDate();
    const want = (forceId !== undefined) ? String(forceId || '') : sel.value;

    // 날짜에 걸친 진행중 프로젝트
    const covering = _projects.filter(p => p.type === 'project' && !p.is_done
        && p.start_dt && p.end_dt && date && date >= p.start_dt && date <= p.end_dt);

    // 선택값이 목록에 없으면(날짜 밖이지만 연결돼 있던 프로젝트) 보존용으로 추가
    let list = covering.slice();
    if (want && !list.some(p => String(p.id) === want)) {
        const extra = _projects.find(p => String(p.id) === want && p.type === 'project');
        if (extra) list = [extra, ...list];
    }

    if (!list.length) { sel.innerHTML = '<option value="">연결 안 함</option>'; sel.value = ''; row.style.display = 'none'; return; }

    const md = s => s ? s.slice(5, 10).replace('-', '/') : '';
    sel.innerHTML = '<option value="">연결 안 함</option>' + list.map(p =>
        `<option value="${p.id}">${esc((p.icon || '') + p.title)} (${md(p.start_dt)}~${md(p.end_dt)})</option>`
    ).join('');
    sel.value = want && list.some(p => String(p.id) === want) ? want : '';
    row.style.display = '';
}

// 일정 날짜 변경 시: 옵션을 새 날짜 기준으로 갱신(기존 선택은 유지)
function applyProjectByDate() { populateProjectSelect(undefined); }

// 그룹/프로젝트 칸 동기화 (그룹 드롭다운 + 날짜기반 프로젝트)
function fillProjSelect() {
    fillGroupSelect();
    applyProjectByDate();
}

// 그룹/프로젝트 클릭 → 상세 모달
// ── 프로젝트 메모 체크리스트 (일정과 동일 포맷 · (project_id, 항목) 단위) ──
let PROJ_CHK_ITEMS = [];
async function renderProjMemoChecklist(projId, memo){
    const el = document.getElementById('pdp-memo-content');
    if (!el) return;
    const lines  = (memo || '').split(/\r?\n/).filter(l => l.trim() !== '');
    const parsed = lines.map(parseMemoItem);
    const hasCheck = parsed.some(p => p.isCheck && p.text);

    let checked = new Set();
    if (hasCheck && projId){
        const res = await api('check_list', { id: projId }, 'GET', 'projects');
        if (res.ok) checked = new Set((res.data || []).map(String));
    }
    // 렌더 대상이 사라졌으면(모달 닫힘/재오픈) 폐기
    if (document.getElementById('pdp-memo-content') !== el) return;

    el.style.whiteSpace = 'normal';
    PROJ_CHK_ITEMS = [];
    let done = 0, total = 0, body = '';
    for (const p of parsed){
        if (p.isCheck && p.text){
            const idx = PROJ_CHK_ITEMS.length; PROJ_CHK_ITEMS.push(p.text);
            const on = checked.has(p.text); total++; if (on) done++;
            body += `<label class="memo-chk-item${on ? ' on' : ''}" data-idx="${idx}">`
                 +  `<input type="checkbox"${on ? ' checked' : ''}>`
                 +  `<span class="memo-chk-txt">${esc(p.text)}</span></label>`;
        } else if (p.text){
            body += `<div class="memo-plain">${esc(p.text)}</div>`;
        }
    }
    const head = total
        ? `<div class="memo-chk-head">✅ 체크리스트 <span class="memo-chk-count">${done}/${total}</span></div>`
        : '';
    el.innerHTML = head + body;
    el.querySelectorAll('.memo-chk-item input').forEach(cb =>
        cb.addEventListener('change', () => toggleProjMemoCheck(projId, cb)));
}

async function toggleProjMemoCheck(projId, cb){
    const item = cb.closest('.memo-chk-item');
    const txt  = PROJ_CHK_ITEMS[+item.dataset.idx];
    const on   = cb.checked;
    item.classList.toggle('on', on);
    updateProjMemoCount();
    if (!projId || txt == null) return;
    const res = await api('check_toggle', { id: projId, item: txt, checked: on ? 1 : 0 }, 'POST', 'projects');
    if (!res.ok){
        cb.checked = !on; item.classList.toggle('on', !on); updateProjMemoCount();
        alert(res.msg || '체크 저장에 실패했습니다.');
    }
}

function updateProjMemoCount(){
    const el = document.getElementById('pdp-memo-content'); if (!el) return;
    const c  = el.querySelector('.memo-chk-count'); if (!c) return;
    c.textContent = `${el.querySelectorAll('.memo-chk-item.on').length}/${el.querySelectorAll('.memo-chk-item').length}`;
}

function openProjDetail(e, id) {
    e.stopPropagation();
    document.getElementById('proj-detail-overlay')?.remove();

    const p = _projects.find(x => x.id == id);
    if (!p) return;

    const typeLabel = p.type === 'project' ? '프로젝트' : '분류';
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
    if (hasMemo) renderProjMemoChecklist(id, p.memo);   // 메모 → 체크리스트(일정과 동일 포맷)

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
    const typeLabel = isProject ? '프로젝트' : '분류';
    const defIcon   = isProject ? '📌' : '📁';

    // 프로젝트 전용: 상위 분류 드롭다운 (분류 목록만)
    let parentRow = '';
    if (isProject) {
        const groups = _projects.filter(x => x.type === 'group');
        const opts = ['<option value="">(상위 분류 없음 · 단독)</option>']
            .concat(groups.map(g =>
                `<option value="${g.id}" ${(p&&p.parent_id==g.id)?'selected':''}>${esc((g.icon?g.icon+' ':'')+g.title)}</option>`
            )).join('');
        parentRow = `
        <div class="form-row">
            <label>상위 분류 <select id="pm-parent">${opts}</select></label>
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
                <input type="text" id="pm-icon" value="${p?p.icon||'':''}" placeholder="${defIcon}" style="width:60px;">
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
                <div class="color-swatches" id="pm-swatches" style="position:relative;">
                    <span id="pm-preset-wrap" style="display:contents;"></span>
                    <span class="color-swatch color-edit" id="pm-edit-btn" onclick="pmToggleSwatchEdit(event)" title="색상 칸 편집: 누른 뒤 바꿀 칸을 선택">✎</span>
                    <span class="color-swatch color-custom" id="pm-custom-swatch" onclick="pmOpenCustomPicker(event)" title="맞춤 색상">🎨</span>
                    <div id="pm-popover" style="display:none;position:absolute;top:34px;left:0;z-index:4100;background:#fff;border:1px solid #d6dce2;border-radius:10px;box-shadow:0 6px 24px rgba(0,0,0,.18);padding:12px;width:312px;">
                        <div style="font-size:12px;font-weight:700;color:#444;margin-bottom:8px;">색상 선택</div>
                        <div id="pm-palette" style="display:grid;gap:3px;margin-bottom:10px;"></div>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span id="pm-preview" style="width:32px;height:32px;border-radius:6px;border:1px solid #dde;background:#3498db;flex-shrink:0;"></span>
                            <input type="text" id="pm-hex" value="#3498db" maxlength="7" placeholder="#RRGGBB"
                                   style="flex:1;min-width:0;border:1px solid #dde;border-radius:6px;padding:6px 8px;font-size:13px;font-family:monospace;"
                                   oninput="pmCcSyncFromHex(this.value);">
                            <button type="button" class="btn" style="padding:5px 12px;font-size:12px;background:#3498db;color:#fff;border:none;flex-shrink:0;" onclick="pmCcApply()">적용</button>
                        </div>
                    </div>
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
    pmEditMode = false; pmAssignIndex = null;
    pmRenderSwatches();
    pmSetColor(p ? (p.color || '#3498db') : '#3498db');
    setTimeout(() => overlay.querySelector('#pm-title').focus(), 50);
}

// ── 그룹/프로젝트 모달 색상 선택(이벤트 모달과 동일: 팔레트·편집·마지막색 공유) ──
let pmEditMode = false;
let pmAssignIndex = null;
function pmCurColor() { const el = document.getElementById('pm-color'); return el ? el.value : '#3498db'; }
function pmSetColor(c) {
    const el = document.getElementById('pm-color'); if (el) el.value = c;
    let matched = false;
    document.querySelectorAll('#pm-swatches .color-swatch:not(.color-custom):not(.color-edit)').forEach(s => {
        const on = s.dataset.color === c; s.classList.toggle('selected', on); if (on) matched = true;
    });
    const cust = document.getElementById('pm-custom-swatch');
    if (cust) {
        if (!matched && c) { cust.style.background = c; cust.dataset.color = c; cust.textContent = ''; cust.classList.add('selected'); }
        else {
            cust.classList.remove('selected');
            const last = loadLastColor();
            if (last) { cust.style.background = last; cust.dataset.color = last; cust.textContent = ''; }
            else { cust.style.background = ''; cust.removeAttribute('data-color'); cust.textContent = '🎨'; }
        }
    }
}
function pmRenderSwatches() {
    const wrap = document.getElementById('pm-preset-wrap'); if (!wrap) return;
    wrap.innerHTML = SCH_SWATCHES.map((c, i) =>
        `<span class="color-swatch" data-color="${c}" data-idx="${i}" style="background:${c}" onclick="pmSwatchClick(${i},event)"></span>`
    ).join('');
    const eb = document.getElementById('pm-edit-btn'); if (eb) eb.classList.toggle('editing', pmEditMode);
    document.getElementById('pm-swatches').classList.toggle('edit-mode', pmEditMode);
    pmSetColor(pmCurColor());
}
function pmSwatchClick(i, e) {
    if (pmEditMode) { pmAssignIndex = i; pmOpenColorPicker(e); }
    else pmSetColor(SCH_SWATCHES[i]);
}
function pmToggleSwatchEdit(e) {
    if (e) e.stopPropagation();
    pmEditMode = !pmEditMode; pmAssignIndex = null; pmCloseColorPicker(); pmRenderSwatches();
}
function pmOpenCustomPicker(e) { pmAssignIndex = null; pmOpenColorPicker(e); }
function pmApplyColorChoice(hex) {
    if (pmAssignIndex !== null) {
        SCH_SWATCHES[pmAssignIndex] = hex; saveSwatches(); pmAssignIndex = null;
        pmRenderSwatches();
        if (typeof renderSwatches === 'function') renderSwatches();   // 이벤트 모달 색칸도 동기화
        pmCloseColorPicker();
    } else {
        if (!SCH_SWATCHES.includes(hex)) saveLastColor(hex);
        pmSetColor(hex); pmCloseColorPicker();
    }
}
function pmCcPick(hex) { pmApplyColorChoice(hex); }
function pmOpenColorPicker(e) {
    if (e) e.stopPropagation();
    buildPaletteInto('pm-palette', 'pmCcPick');
    const cur = pmCurColor() || '#3498db';
    const seed = (pmAssignIndex === null && SCH_SWATCHES.includes(cur) && loadLastColor()) ? loadLastColor() : cur;
    document.getElementById('pm-hex').value = seed;
    const pv = document.getElementById('pm-preview');
    if (pv) pv.style.background = /^#[0-9a-fA-F]{6}$/.test(seed) ? seed : '#3498db';
    document.getElementById('pm-popover').style.display = 'block';
}
function pmCloseColorPicker() { const p = document.getElementById('pm-popover'); if (p) p.style.display = 'none'; }
function pmCcSyncFromHex(v) { if (/^#[0-9a-fA-F]{6}$/.test(v)) document.getElementById('pm-preview').style.background = v; }
function pmCcApply() {
    let v = document.getElementById('pm-hex').value.trim();
    if (/^[0-9a-fA-F]{6}$/.test(v)) v = '#' + v;
    if (!/^#[0-9a-fA-F]{6}$/.test(v)) { alert('색상코드를 #RRGGBB 형식으로 입력하세요.'); return; }
    pmApplyColorChoice(v.toLowerCase());
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
        : `"${p?.title}" 분류를 삭제하면 포함 프로젝트는 단독으로 풀리고, 직속 일정은 연결만 해제됩니다.\n계속할까요?`;
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
    // 주소확인 버튼: 국내(Naver)만 표시
    const mapBtn = document.getElementById('btn-map-pick');
    if (mapBtn) mapBtn.style.display = region === 'naver' ? '' : 'none';
    // 지역을 바꾸면 기존 좌표는 무효 (제공자-좌표 일치 보장)
    const lat = document.getElementById('f-lat').value;
    if (lat) {
        document.getElementById('f-lat').value = '';
        document.getElementById('f-lng').value = '';
        setLocBtn('default');
        setLocStatus('지역이 변경됐습니다. 주소확인을 다시 눌러주세요.', 'warn');
    } else {
        setLocStatus('', '');
    }
}

function setLocStatus(msg, kind) {
    const el = document.getElementById('loc-status');
    el.textContent = msg;
    el.className = kind || '';
    el.style.display = (msg && (kind === 'err' || kind === 'warn')) ? '' : 'none';
}

function setLocBtn(state) {
    const btn = document.getElementById('btn-map-pick');
    if (!btn) return;
    if (state === 'ok') {
        btn.textContent = '확인완료';
        btn.style.background = '#e74c3c';
        btn.style.color = '#fff';
        btn.style.borderColor = '#e74c3c';
    } else {
        btn.textContent = '주소확인';
        btn.style.background = '';
        btn.style.color = '';
        btn.style.borderColor = '';
    }
}

// ── Daum 주소 검색 팝업 (국내 전용) ─────────────────────────
function openAddrSearch() {
    const load = cb => {
        if (window.daum?.Postcode) { cb(); return; }
        const s = document.createElement('script');
        s.src = 'https://t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js';
        s.onload = cb;
        document.head.appendChild(s);
    };
    load(() => new daum.Postcode({
        oncomplete(data) {
            const addr = data.roadAddress || data.jibunAddress;
            document.getElementById('f-address').value = addr;
            document.getElementById('f-address').dataset.geocoded = '';
            document.getElementById('f-lat').value = '';
            document.getElementById('f-lng').value = '';
            setLocStatus('주소 선택됨 — 좌표 확인 중…', '');
            doGeocode(); // 주소 선택 즉시 자동 좌표 확인
        }
    }).open());
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
        document.getElementById('f-provider').value = res.provider;
        document.getElementById('f-address').dataset.geocoded = address;
        setLocBtn('ok');
        setLocStatus('', '');
        return true;
    }
    document.getElementById('f-lat').value = '';
    document.getElementById('f-lng').value = '';
    setLocBtn('default');
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
        addrEl.dataset.picked = '';
        return true;
    }
    const hasLat = !!document.getElementById('f-lat').value;
    // ★좌표가 이미 있으면(지도에서 찍었거나 기존 저장값) 절대 재지오코딩하지 않는다 — 정확한 핀을 주소 중심점으로 덮어쓰는 사고 방지.
    //   좌표가 아예 없을 때만(주소만 직접 타이핑하고 지도 선택을 안 한 경우) 주소로 변환.
    if (!hasLat) {
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
    document.getElementById('f-address').dataset.picked = '';
    document.getElementById('f-lat').value = '';
    document.getElementById('f-lng').value = '';
    document.getElementById('f-place-name').value = '';
    setLocRegion('naver');
    setLocBtn('default');
    setLocStatus('', '');
}

// 입력 폼 위치 복원 (수정 시)
function fillLocationForm(ev) {
    const provider = ev.provider === 'google' ? 'google' : 'naver';
    setLocRegion(provider);
    document.getElementById('f-address').value = ev.address || '';
    document.getElementById('f-address').dataset.geocoded = (ev.lat && ev.lng) ? (ev.address || '') : '';
    // 저장된 좌표는 확정 지점으로 취급 — 수정 시 주소 텍스트를 고쳐도 핀 유지(재지오코딩 방지)
    document.getElementById('f-address').dataset.picked = (ev.lat && ev.lng) ? '1' : '';
    document.getElementById('f-lat').value = ev.lat || '';
    document.getElementById('f-lng').value = ev.lng || '';
    document.getElementById('f-place-name').value = ev.place_name || '';
    setLocBtn(ev.address && ev.lat && ev.lng ? 'ok' : 'default');
    setLocStatus('', '');
}

// ── 여행지도(트립) 연결 ───────────────────────────────────────
// places.php에서 저장한 트립을 일정에 연결 → 보기모달/모달에서 공유모드 팝업으로 열람
let TRIPS = null;           // 내 트립 목록 캐시 [{id,name,stops,picks}]
let _tripsLoading = null;
function loadTrips() {
    if (_tripsLoading) return _tripsLoading;
    _tripsLoading = fetch('place_api.php?module=place&action=trip_list', {cache:'no-store'})
        .then(r => r.json())
        .then(d => { TRIPS = (d && d.trips) || []; })
        .catch(() => { TRIPS = []; });
    return _tripsLoading;
}
// select 옵션 렌더 (저장된 트립이 목록에 없으면 보존 옵션으로 표시)
function renderTripSelect(selToken, selName) {
    const sel = document.getElementById('f-trip');
    const escA = s => esc(s).replace(/"/g, '&quot;');   // 속성값용(따옴표까지 이스케이프)
    let html = '<option value="">— 연결 안 함 —</option>';
    let matched = false;
    (TRIPS || []).forEach(t => {
        const on = (selName && t.name === selName) ? ' selected' : '';
        if (on) matched = true;
        html += '<option value="' + t.id + '" data-name="' + escA(t.name) + '"' + on + '>'
              + esc(t.name) + ' (' + (t.stops||0) + '곳)</option>';
    });
    if (selToken && !matched) {
        html += '<option value="__keep" data-name="' + escA(selName||'') + '" selected>'
              + esc(selName || '연결된 여행지도') + ' (저장됨)</option>';
    }
    sel.innerHTML = html;
    updateTripViewBtn();
}
function resetTripForm() {
    document.getElementById('f-trip-token').value = '';
    document.getElementById('f-trip-name').value  = '';
    setTripStatus('');
    loadTrips().then(() => renderTripSelect('', ''));
}
function fillTripForm(ev) {
    const token = ev.trip_token || '';
    const name  = ev.trip_name || '';
    document.getElementById('f-trip-token').value = token;
    document.getElementById('f-trip-name').value  = name;
    setTripStatus('');
    loadTrips().then(() => renderTripSelect(token, name));
}
// 드롭다운 변경 → 선택 트립의 '만료 없는' 영구 공유토큰 확보
function onTripChange() {
    const sel = document.getElementById('f-trip');
    const opt = sel.options[sel.selectedIndex];
    const val = sel.value;
    if (!val) {
        document.getElementById('f-trip-token').value = '';
        document.getElementById('f-trip-name').value  = '';
        setTripStatus(''); updateTripViewBtn(); return;
    }
    if (val === '__keep') { updateTripViewBtn(); return; }  // 기존 토큰 유지
    setTripStatus('여행지도 연결 중…');
    fetch('place_api.php?module=place&action=trip_share_perm&id=' + encodeURIComponent(val), {cache:'no-store'})
        .then(r => r.json())
        .then(d => {
            if (d && d.ok && d.token) {
                document.getElementById('f-trip-token').value = d.token;
                document.getElementById('f-trip-name').value  = d.name || (opt ? opt.dataset.name : '');
                setTripStatus('');
            } else {
                setTripStatus('연결 실패: ' + ((d && d.msg) || '오류'));
            }
            updateTripViewBtn();
        })
        .catch(() => setTripStatus('연결 실패(네트워크)'));
}
function updateTripViewBtn() {
    document.getElementById('btn-trip-view').disabled = !document.getElementById('f-trip-token').value;
}
function setTripStatus(msg) {
    const el = document.getElementById('trip-status');
    el.textContent = msg || '';
    el.style.display = msg ? '' : 'none';
}
// 팝업으로 여행지도 보기 (공유모드 게스트 뷰 = places.php?trip=토큰, 만료 없음)
function openTripPopup() { openTripWindow(document.getElementById('f-trip-token').value); }
function openTripWindow(token) {
    if (!token) return;
    window.open('places.php?trip=' + encodeURIComponent(token),
        'tripmap_' + token,
        'width=900,height=760,menubar=no,toolbar=no,location=no,scrollbars=yes,resizable=yes');
}

// ── 외부 지도 링크 ───────────────────────────────────────────
function naverMapUrl(address, lat, lng, placeName) {
    // 상호명(POI)이 있으면 그 이름으로 검색 → 해당 업체가 바로 뜸(주소로 검색하면 그 주소의 모든 업체가 나열됨).
    // 상호명 없으면 주소, 둘 다 없으면 좌표.
    const q = (placeName && placeName.trim()) || address || (lat + ',' + lng);
    return 'https://map.naver.com/p/search/' + encodeURIComponent(q);
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
        s.src = 'https://oapi.map.naver.com/openapi/v3/maps.js?ncpKeyId=' + encodeURIComponent(MAP_CFG.naverClientId) + '&submodules=geocoder';
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
    const addrLine = (ev.place_name ? ev.place_name + '  ' : '') + (ev.address || `${ev.lat}, ${ev.lng}`);
    document.getElementById('view-address').textContent = '📍 ' + addrLine;

    const provider = ev.provider === 'google' ? 'google' : 'naver';
    const mapEl    = document.getElementById('view-map');
    const fbEl     = document.getElementById('view-map-fallback');
    const linksEl  = document.getElementById('view-map-links');
    mapEl.innerHTML = '';
    fbEl.style.display = 'none';

    // "다른 지도로 보기" — 좌표를 찾은 제공자와 반대편 지도
    const naverLink  = `<a href="${naverMapUrl(ev.address, ev.lat, ev.lng, ev.place_name)}"  target="_blank" rel="noopener">네이버 지도에서 열기 ↗</a>`;
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

// 모바일 판정은 UA(body.is-mobile=서버 $mobile) 전담. 폭 기반 w-narrow 폴백 제거(PC는 창 좁혀도 항상 PC).
// 데스크톱 월간뷰에서 창 폭이 바뀌면 칩 표시 개수만 재계산.
(function(){
    let _chipLimitTimer = null;
    window.addEventListener('resize', () => {
        if (document.body.classList.contains('is-mobile')) return;
        if (S.view === 'month') {
            clearTimeout(_chipLimitTimer);
            _chipLimitTimer = setTimeout(applyMonthChipLimit, 150);
        }
    });
})();

// ══════════════════════════════════════════════════════════════
//  지도 주소 선택 (Naver Maps 클릭/검색 → 주소 역지오코딩)
// ══════════════════════════════════════════════════════════════
let _mpMap = null, _mpMarker = null, _mpPicked = null;

// 주소확인/확인완료 버튼: 좌표가 이미 확정돼 있으면 주소 재검색 없이 그 지점으로 픽커를 연다(선택 유지·무한 반복 방지)
function reopenMapPicker() {
    const hasCoord = document.getElementById('f-lat').value && document.getElementById('f-lng').value;
    openMapPicker(hasCoord ? '' : document.getElementById('f-address').value.trim());
}

function openMapPicker(query) {
    if (!MAP_CFG.naverClientId) { alert('네이버 지도 키가 설정되지 않았습니다.'); return; }
    document.getElementById('map-picker-overlay').style.display = 'flex';
    document.getElementById('mp-selected-addr').textContent = '지도를 클릭하거나 검색으로 위치를 선택하세요.';
    document.getElementById('mp-btn-select').disabled = true;
    document.getElementById('mp-status').textContent = '지도 불러오는 중…';
    _mpPicked = null;
    // 이전 검색어·결과목록 잔존 제거 (좌표로 재오픈 시 옛 목록 오클릭 → 엉뚱한 POI 선택 방지)
    document.getElementById('mp-search').value = query || '';
    mpHideResults();
    loadNaverSDK().then(() => {
        document.getElementById('mp-status').textContent = '';
        if (!_mpMap) {
            // 최초 생성
            const initPos = new naver.maps.LatLng(37.5665, 126.9780);
            _mpMap = new naver.maps.Map('mp-map', { center: initPos, zoom: 13 });
            _mpMarker = new naver.maps.Marker({ map: _mpMap, position: initPos, visible: false });
            naver.maps.Event.addListener(_mpMap, 'click', e => mpPickCoord(e.coord));
        } else {
            naver.maps.Event.trigger(_mpMap, 'resize');
        }
        // 검색어가 넘어온 경우 자동 검색 (이미 좌표가 있어도 검색 우선)
        if (query) {
            mpSearch();
        } else {
            // 기존 좌표가 있으면 그 위치로 이동 + 그 선택을 그대로 재확정할 수 있게 _mpPicked 미리 채움
            const lat = document.getElementById('f-lat').value;
            const lng = document.getElementById('f-lng').value;
            if (lat && lng) {
                const pos = new naver.maps.LatLng(+lat, +lng);
                _mpMap.setCenter(pos); _mpMap.setZoom(16);
                _mpMarker.setPosition(pos); _mpMarker.setVisible(true);
                _mpPicked = {
                    lat: +lat, lng: +lng,
                    address: document.getElementById('f-address').value.trim(),
                    name:    document.getElementById('f-place-name').value.trim()
                };
                document.getElementById('mp-selected-addr').textContent =
                    (_mpPicked.name ? _mpPicked.name + '  ' : '') + _mpPicked.address;
                document.getElementById('mp-btn-select').disabled = false;
            }
        }
    }).catch(() => {
        document.getElementById('mp-status').textContent = '지도를 불러오지 못했습니다. (네이버 키/도메인 확인)';
    });
}

function closeMapPicker() {
    document.getElementById('map-picker-overlay').style.display = 'none';
}

function mpPickCoord(coord) {
    _mpMarker.setPosition(coord); _mpMarker.setVisible(true);
    document.getElementById('mp-selected-addr').textContent = '주소 변환 중…';
    document.getElementById('mp-status').textContent = '';
    document.getElementById('mp-btn-select').disabled = true;
    // orders를 문자열로 지정 (OrderType 상수 의존 없이 안정적)
    naver.maps.Service.reverseGeocode({ coords: coord, orders: 'roadaddr,addr' }, (status, res) => {
        let addr = '';
        if (status === naver.maps.Service.Status.OK) {
            addr = res.v2?.address?.roadAddress || res.v2?.address?.jibunAddress || '';
        }
        _mpPicked = { lat: coord.lat(), lng: coord.lng(), address: addr, name: '' };
        // 주소 조회 실패해도 좌표로 선택 가능하게 허용
        document.getElementById('mp-selected-addr').textContent =
            addr || `${coord.lat().toFixed(6)}, ${coord.lng().toFixed(6)} (주소 미확인)`;
        document.getElementById('mp-btn-select').disabled = false;
    });
}

// 검색: 상호명/키워드(Kakao) 우선, 없으면 주소 지오코딩(Naver) 폴백
async function mpSearch() {
    const q = document.getElementById('mp-search').value.trim();
    if (!q) return;
    mpHideResults();
    document.getElementById('mp-status').textContent = '검색 중…';

    // 1. 상호명/키워드 검색 (Kakao)
    const placeRes = await api('place_search', { q }, 'GET', 'geo');
    if (placeRes?.ok && placeRes.places?.length) {
        document.getElementById('mp-status').textContent = `${placeRes.places.length}건 검색됨`;
        mpShowResults(placeRes.places);
        return;
    }

    // 2. 주소 지오코딩 폴백 (Naver)
    const geoRes = await api('geocode', { provider: 'naver', address: q }, 'GET', 'geo');
    if (!geoRes?.ok) {
        document.getElementById('mp-status').textContent = '검색 결과가 없습니다.';
        return;
    }
    document.getElementById('mp-status').textContent = '';
    const coord = new naver.maps.LatLng(+geoRes.lat, +geoRes.lng);
    _mpMap.setCenter(coord); _mpMap.setZoom(16);
    mpPickCoord(coord);
}

let _mpPlaces = [];
function mpShowResults(places) {
    _mpPlaces = places;
    let list = document.getElementById('mp-results');
    if (!list) {
        list = document.createElement('div');
        list.id = 'mp-results';
        list.style.cssText = [
            'position:absolute;top:calc(100% + 2px);left:0;right:0',
            'background:#fff;border:1px solid #c8d6e5;border-radius:8px',
            'box-shadow:0 4px 16px rgba(0,0,0,.15);z-index:100',
            'max-height:220px;overflow-y:auto'
        ].join(';');
        const wrap = document.getElementById('mp-search').closest('div');
        wrap.style.position = 'relative';
        wrap.appendChild(list);
    }
    list.innerHTML = places.map((p, i) => `
        <div data-idx="${i}" class="mp-place-item"
             style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #f0f0f0;">
            <div style="font-weight:600;font-size:13px;">${esc(p.name)}</div>
            <div style="font-size:11px;color:#888;">${esc(p.address)}${p.phone ? ' · ' + esc(p.phone) : ''}</div>
        </div>`).join('');
    list.querySelectorAll('.mp-place-item').forEach(el => {
        el.addEventListener('mouseover', () => el.style.background = '#f5f9ff');
        el.addEventListener('mouseout',  () => el.style.background = '');
        el.addEventListener('click', () => mpSelectPlace(+el.dataset.idx));
    });
    list.style.display = '';
}

function mpHideResults() {
    const el = document.getElementById('mp-results');
    if (el) el.style.display = 'none';
}

function mpSelectPlace(i) {
    const p = _mpPlaces[i];
    const coord = new naver.maps.LatLng(+p.lat, +p.lng);
    _mpMap.setCenter(coord); _mpMap.setZoom(17);
    mpHideResults();
    _mpPicked = { lat: +p.lat, lng: +p.lng, address: p.address, name: p.name || '' };
    _mpMarker.setPosition(coord); _mpMarker.setVisible(true);
    document.getElementById('mp-selected-addr').textContent = p.name + '  ' + p.address;
    document.getElementById('mp-btn-select').disabled = false;
    document.getElementById('mp-status').textContent = '';
}

function confirmMapPick() {
    if (!_mpPicked) return;
    // ★setLocRegion을 좌표 세팅보다 "먼저" 호출 — setLocRegion은 기존 좌표를 클리어하므로,
    //   뒤에 부르면 방금 넣은 핀 좌표를 지워버린다(저장 시 빈 좌표→주소 재지오코딩→중심점 사고의 원인이었음).
    setLocRegion('naver');
    document.getElementById('f-address').value = _mpPicked.address;
    document.getElementById('f-address').dataset.geocoded = _mpPicked.address;
    document.getElementById('f-address').dataset.picked = '1';   // 지도에서 콕 찍은 정확한 지점 — 저장 시 재지오코딩 금지
    document.getElementById('f-lat').value = _mpPicked.lat;
    document.getElementById('f-lng').value = _mpPicked.lng;
    document.getElementById('f-place-name').value = _mpPicked.name || '';
    setLocBtn('ok');
    setLocStatus('', '');
    closeMapPicker();
}
</script>

<!-- 지도 주소 선택 모달 -->
<div id="map-picker-overlay"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:6000;align-items:center;justify-content:center;"
     onclick="if(event.target===this)closeMapPicker()">
    <div style="background:#fff;border-radius:12px;width:min(700px,96vw);height:min(580px,90vh);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,.3);">
        <div style="display:flex;align-items:center;gap:8px;padding:12px 16px;border-bottom:1px solid #eee;flex-shrink:0;">
            <strong style="white-space:nowrap;font-size:14px;">📍 위치 선택</strong>
            <input type="text" id="mp-search" placeholder="장소·주소 검색 후 Enter"
                   style="flex:1;border:1px solid #dde;border-radius:6px;padding:7px 10px;font-size:13px;"
                   onkeydown="if(event.key==='Enter'){mpSearch();}">
            <button type="button" class="btn btn-primary" onclick="mpSearch()" style="flex-shrink:0;">검색</button>
            <button type="button" class="btn btn-outline" onclick="closeMapPicker()" style="flex-shrink:0;padding:6px 10px;">✕</button>
        </div>
        <div id="mp-status" style="font-size:12px;padding:3px 16px;min-height:18px;color:#888;flex-shrink:0;"></div>
        <div id="mp-map" style="flex:1;min-height:0;"></div>
        <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;border-top:1px solid #eee;flex-shrink:0;">
            <div id="mp-selected-addr" style="flex:1;font-size:13px;color:#555;">지도를 클릭하거나 검색으로 위치를 선택하세요.</div>
            <button type="button" class="btn btn-primary" id="mp-btn-select" onclick="confirmMapPick()" style="flex-shrink:0;" disabled>이 위치 선택</button>
        </div>
    </div>
</div>
<div id="img-lightbox"><img src="" alt=""><div class="lb-hint">휠: 확대/축소 · 드래그: 이동 · 클릭: 닫기</div></div>
</body>
</html>
<?php
} // end sch_calendar()





