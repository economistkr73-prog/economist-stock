<?php
/**
 * cron/agoda_track.php — 아고다 호텔 가격 추적 (하루 1바퀴)
 *
 * ══ 무엇을 하나 ═══════════════════════════════════════════════════════════
 *   ① 만료 — 체크인이 지난 특정일 추적을 active=0 으로 내린다 (자동 종료)
 *   ② 롤링 — 살아있는 호텔마다 「오늘+30일 · 1박 · 성인2」 가격 1건 (track_id=0)
 *   ③ 추적 — 살아있는 특정일 추적마다 그 날짜 가격 1건 + 목표가 도달 시 알림 수집
 *   ④ 알림 — 새로 도달한 것만 묶어 Pushover 1건 (중복 방지 = pf_alert_log · 추적당 하루 1회)
 *   ⑤ 감시 — 대상이 있는데 전부 실패면 priority 1 (아고다 차단/구조 변경 신호)
 *
 * ══ 30초 벽 ═══════════════════════════════════════════════════════════════
 * 호텔당 콜 1회 + sleep 1초라 대상이 15개만 넘어도 30초를 넘는다 → env/cronbg.inc bg 필수
 * (TASKS 에 'bg' => true). 워밍업(쿠키)은 Agoda 클래스가 프로세스당 1회만 한다.
 *
 * ══ 지켜야 할 것 ═══════════════════════════════════════════════════════════
 *   - ★비공식 API — 콜은 하루 1~2바퀴로 아낀다(네이버 크롤링과 같은 서버 IP 다).
 *     sleep(1) 을 줄이지 말 것.
 *   - 가격은 1박·세전 <b>USD 고정</b> (KRW 로 못 바꾼다 — 실측 10가지 실패).
 *   - 실패한 (호텔,날짜)는 행을 안 남긴다(구멍) — 다음 바퀴가 다시 잰다.
 *   - 알림 중복 장부는 pf_alert_log 하나뿐 (kind='agoda' · ref=추적id|날짜).
 *     여기에 따로 표를 만들지 않는다 — 「이미 쐈나」에 답하는 장부가 둘이 되면 안 된다.
 *
 * job
 *   daily   (기본) ①~⑤ 전부
 *   schema  표 3개 생성 (최초 1회)
 *   status  적재 현황 (읽기 전용)
 *   check   agoda=62045120 checkin=2026-08-28 [nights= adults=]  실호출 1건 미리보기
 *
 * SSH 실행 예
 *   /usr/local/php84/bin/php cron/agoda_track.php job=schema
 *   /usr/local/php84/bin/php cron/agoda_track.php job=daily
 */
require_once __DIR__ . '/_boot.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/cnt.inc';

$cbg = $_SERVER['DOCUMENT_ROOT'] . '/env/cronbg.inc';
if (!is_file($cbg)) exit("env/cronbg.inc 없음 — env/ 는 git 제외라 수동 배포가 필요합니다.\n");
require_once $cbg;

$CLI = (PHP_SAPI === 'cli');
if (!$CLI) header('Content-Type: text/plain; charset=utf-8');

$TOKEN = 'econ-agoda';
if (!$CLI && ($_GET['key'] ?? '') !== $TOKEN) {
    http_response_code(403);
    exit('forbidden: ?key= 필요');
}
if ($CLI) {
    foreach (array_slice($argv ?? [], 1) as $a) {
        if (strpos($a, '=') !== false) { [$k, $v] = explode('=', $a, 2); $_GET[$k] = $v; }
    }
}
@set_time_limit(0);

define('AG_LOG', sys_get_temp_dir() . '/agoda_track.log');
if (!empty($_GET['log'])) { cron_bg_show_log(AG_LOG, (int)($_GET['n'] ?? 80)); exit; }

/* 호텔 100개 = 콜 2초×100 ≈ 200초 여유. 예산 900초. */
cron_bg_begin(AG_LOG, max(0, (int)($_GET['sec'] ?? 900)));

function say(string $s): void { cron_bg_log($s); }

$job = (string)($_GET['job'] ?? 'daily');
$ag  = new Agoda($pdo);
$t0  = microtime(true);

/* ── job=schema — 표 생성 (최초 1회 · 멱등) ──────────────────────────── */
if ($job === 'schema') {
    $ag->ensureTables();
    foreach (['ag_hotel', 'ag_track', 'ag_price'] as $t) {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
        say("{$t}: {$n}행");
    }
    say('schema 완료');
    exit;
}

/* ── job=status — 현황 (읽기 전용) ───────────────────────────────────── */
if ($job === 'status') {
    $h = (int)$pdo->query("SELECT COUNT(*) FROM ag_hotel WHERE active=1")->fetchColumn();
    $t = (int)$pdo->query("SELECT COUNT(*) FROM ag_track WHERE active=1")->fetchColumn();
    $p = (int)$pdo->query("SELECT COUNT(*) FROM ag_price")->fetchColumn();
    $d = (string)$pdo->query("SELECT MAX(d) FROM ag_price")->fetchColumn();
    say("호텔 {$h} · 추적 {$t} · 표본 {$p}행 · 마지막 수집일 {$d}");
    exit;
}

/* ── job=check — 실호출 1건 미리보기 (DB 안 건드림) ──────────────────── */
if ($job === 'check') {
    $aid = (int)($_GET['agoda'] ?? 0);
    $ci  = (string)($_GET['checkin'] ?? date('Y-m-d', strtotime('+' . Agoda::ROLL_DAYS . ' days')));
    if (!$aid) { say('agoda=<hotel_id> 필요'); exit; }
    $r = Agoda::fetch($aid, $ci, (int)($_GET['nights'] ?? 1), (int)($_GET['adults'] ?? 2));
    if (!$r['ok']) { say("실패: {$r['msg']}"); exit; }
    say("hotel_id={$aid} checkin={$ci} — 객실 " . count($r['rooms']) . '개 · 최저 '
        . ($r['min'] !== null ? '$' . number_format($r['min'], 2) . " ({$r['minRoom']})" : '매진/없음'));
    foreach ($r['rooms'] as $rm) {
        say(sprintf('  %-40s %s  %s', mb_substr($rm['name'], 0, 40),
            $rm['price'] !== null ? '$' . number_format($rm['price'], 2) : '-', $rm['cxl']));
    }
    exit;
}

/* ══════════════════════ job=daily ══════════════════════ */
$ag->ensureTables();

/* ① 만료 — 체크인 지난 추적 자동 종료 */
$exp = $ag->expireTracks();
if ($exp) say("① 만료: 체크인 지난 추적 {$exp}건 종료");

$hotels = $ag->hotelList();
$tracks = $ag->trackList();
$total  = count($hotels) + count($tracks);
if ($total === 0) { say('대상 없음 — 호텔을 먼저 등록하세요 (/hotel/)'); exit; }
say('② 롤링 ' . count($hotels) . '개 + ③ 추적 ' . count($tracks) . '건 시작');

$okN = 0;
$failN = 0;

/* ② 롤링(기본층) — D+30 · 1박 · 성인2 */
$rollCi = date('Y-m-d', strtotime('+' . Agoda::ROLL_DAYS . ' days'));
foreach ($hotels as $h) {
    if (cron_bg_over()) { say('예산 도달 — 나머지는 다음 바퀴'); break; }
    $r = Agoda::fetch((int)$h['agoda_id'], $rollCi);
    if (!$r['ok']) {
        $failN++;
        say("  롤링 실패 {$h['name']}: {$r['msg']}");
    } else {
        $ag->savePrice((int)$h['id'], 0, $rollCi, $r['min'], $r['minRoom'], $r['soldout']);
        $okN++;
    }
    sleep(1);   // ★비공식 API — 줄이지 말 것
}

/* ③ 특정일 추적 + 목표가 판정 */
$hits = [];
foreach ($tracks as $t) {
    if (cron_bg_over()) { say('예산 도달 — 나머지는 다음 바퀴'); break; }
    $r = Agoda::fetch((int)$t['agoda_id'], $t['checkin'], (int)$t['nights'], (int)$t['adults']);
    if (!$r['ok']) {
        $failN++;
        say("  추적 실패 {$t['hotel_name']} {$t['checkin']}: {$r['msg']}");
        sleep(1);
        continue;
    }
    $ag->savePrice((int)$t['hotel_id'], (int)$t['id'], $t['checkin'], $r['min'], $r['minRoom'], $r['soldout']);
    $okN++;

    if ($t['target_usd'] !== null && $r['min'] !== null && $r['min'] <= (float)$t['target_usd']) {
        $hits[] = ['t' => $t, 'min' => $r['min'], 'room' => $r['minRoom']];
    }
    sleep(1);
}
say(sprintf('②③ 완료 — 성공 %d · 실패 %d (%.1f초)', $okN, $failN, microtime(true) - $t0));

/* ④ 알림 — 새로 도달한 것만 (중복 장부 = pf_alert_log · 추적당 하루 1회)
 * 실패는 삼킨다 — 알림이 본업(수집)을 죽이면 안 된다. */
if ($hits) {
    try {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/stock/lib/alert.php';
        pf_alert_ensure($pdo);
        $lines = [];
        foreach ($hits as $x) {
            $t = $x['t'];
            if (!pf_alert_new($pdo, 'agoda', $t['id'] . '|' . date('Y-m-d'))) continue;   // 오늘 이미 쐈다
            $lines[] = sprintf('%s %s(%d박·%d인) $%s ≤ 목표 $%s%s',
                $t['hotel_name'], $t['checkin'], $t['nights'], $t['adults'],
                number_format($x['min'], 2), number_format((float)$t['target_usd'], 2),
                $x['room'] !== '' ? ' · ' . mb_substr($x['room'], 0, 25) : '');
        }
        if ($lines && class_exists('Notify')) {
            Notify::send(
                implode("\n", $lines) . "\n(1박·세전 USD — 아고다 API 기준)",
                'https://economist.kr/hotel/',
                ['title' => '🏨 호텔 목표가 도달', 'priority' => 0]
            );
            say('④ 알림 발송 ' . count($lines) . '건');
        }
    } catch (Throwable $e) {
        say('④ 알림 실패(삼킴): ' . $e->getMessage());
    }
}

/* ⑤ 감시 — 전부 실패면 크게 (자체 catch 로 삼키는 잡이라 중앙 알림이 못 본다 · CRON.md §2.5 규칙 3)
 * 하루 1회 스로틀. */
if ($okN === 0 && $failN > 0) {
    $flag = sys_get_temp_dir() . '/agoda_fail.flag';
    if (!is_file($flag) || trim((string)@file_get_contents($flag)) !== date('Y-m-d')) {
        @file_put_contents($flag, date('Y-m-d'));
        if (class_exists('Notify')) {
            try {
                Notify::send(
                    "아고다 수집이 전부 실패했습니다 ({$failN}건) — 차단 또는 API 구조 변경 가능성.\n"
                    . '로그: https://economist.kr/cron_job.php?task=agoda&k=econ-cron-j7k2&log=1',
                    'https://economist.kr/hotel/',
                    ['title' => '⚠️ 아고다 수집 실패', 'priority' => 1]
                );
            } catch (Throwable $e) {
                say('⑤ 실패 알림 발송 불가: ' . $e->getMessage());
            }
        }
    }
}
say(sprintf('총 %.1f초', microtime(true) - $t0));
?>
