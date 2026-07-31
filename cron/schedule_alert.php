<?php
// cron_schedule_alert.php
// cron-job.org 등록:
//   [매일 07:00] ?mode=daily_summary&ssk=mysn1973!
//   [매 10분]    ?mode=check_alerts&ssk=mysn1973!

$secret_key = "mysn1973!";
if (!isset($_GET['ssk']) || $_GET['ssk'] !== $secret_key) {
    http_response_code(403);
    die("접근 권한이 없습니다.");
}

require_once $_SERVER['DOCUMENT_ROOT'] . "/env/cnt.inc";
require_once $_SERVER['DOCUMENT_ROOT'] . "/env/kakao.inc";

error_reporting(E_ALL & ~E_NOTICE);
ini_set("display_errors", 0);
set_time_limit(60);
ignore_user_abort(true);

// 디버그 모드는 즉시 출력 (fastcgi 종료 없이)
$is_debug = isset($_GET['debug']);

if (!$is_debug && function_exists('fastcgi_finish_request')) {
    header("Content-Type: text/plain; charset=utf-8");
    echo "OK";
    fastcgi_finish_request();
} elseif ($is_debug) {
    header("Content-Type: text/plain; charset=utf-8");
    ini_set("display_errors", 1);
    echo "=== DEBUG MODE ===\n";
}

$sch  = new Schedule($pdo);
$mode = $_GET['mode'] ?? 'check_alerts';

switch ($mode) {
    case 'daily_summary':  send_daily_summary($sch);        break;
    case 'check_alerts':   check_and_send_alerts($sch);     break;
    case 'sync_holidays':  sync_holidays($pdo);             break;
    default:
        error_log("[cron_schedule_alert] unknown mode: {$mode}");
}

// =============================================================
// 1. 매일 07:00 — 당일 일정 요약 카톡 발송
// =============================================================
function send_daily_summary(Schedule $sch): void {
    $events = $sch->listToday();

    $DAY_KR  = ['일','월','화','수','목','금','토'];
    $dow     = $DAY_KR[date('w')];
    $dateStr = date('Y년 m월 d일') . " ({$dow})";

    if (empty($events)) {
        Notify::send(
            "📅 {$dateStr}\n오늘 등록된 일정이 없습니다.",
            "https://economist.kr/schedule.php?mode=calendar"
        );
        return;
    }

    $groups = [
        'anniversary' => ['icon' => '🎂 기념일',  'items' => []],
        'todo'        => ['icon' => '☑ 할 일',    'items' => []],
        'allday'      => ['icon' => '📅 종일',     'items' => []],
        'timed'       => ['icon' => '⏰ 시간 일정','items' => []],
    ];

    foreach ($events as $ev) {
        $type = $ev['event_type'] ?: 'timed';
        if (!isset($groups[$type])) $type = 'timed';

        if ($type === 'timed' && ($ev['is_allday'] ?? 0) != 1) {
            $s = date('H:i', strtotime($ev['start_dt']));
            $e = !empty($ev['end_dt']) ? date('H:i', strtotime($ev['end_dt'])) : '';
            $time = $e ? "{$s}~{$e}" : $s;
            $groups[$type]['items'][] = "  {$time} {$ev['title']}";
        } else {
            $groups[$type]['items'][] = "  {$ev['title']}";
        }
    }

    $msg  = "📅 {$dateStr} 오늘의 일정\n";
    $msg .= str_repeat('―', 18) . "\n";

    foreach ($groups as $g) {
        if (empty($g['items'])) continue;
        $msg .= "\n{$g['icon']}\n" . implode("\n", $g['items']) . "\n";
    }

    $total = count($events);
    $msg  .= "\n" . str_repeat('―', 18);
    $msg  .= "\n총 {$total}개 일정";

    Notify::send($msg, "https://economist.kr/schedule.php?mode=calendar");
}

// =============================================================
// 2. 매 10분 — 개별 알림 체크 & 발송
// =============================================================
function check_and_send_alerts(Schedule $sch): void {
    $alerts = $sch->getPendingAlerts(15); // 15분 윈도우
    if (empty($alerts)) return;

    foreach ($alerts as $alert) {
        $label = format_alert_label((int)$alert['alert_min']);
        $start = date('m/d(D) H:i', strtotime($alert['start_dt']));

        $msg  = "⏰ 일정 알림 [{$label}]\n\n";
        $msg .= "📌 {$alert['title']}\n";
        $msg .= "🕐 {$start}";

        if (!empty($alert['end_dt'])) {
            $end  = date('H:i', strtotime($alert['end_dt']));
            $msg .= " ~ {$end}";
        }
        if (!empty($alert['category'])) {
            $msg .= "\n🏷 {$alert['category']}";
        }

        $ok = Notify::send($msg, "https://economist.kr/schedule.php?mode=calendar");
        if ($ok) {
            $sch->markAlertSent((int)$alert['alert_id']);
        }
    }
}

// =============================================================
// 3. 공휴일 동기화 (연 1회 권장 — 매년 1월 1일 호출)
//    cron-job.org: ?mode=sync_holidays&ssk=mysn1973!
//    수동 테스트: ?mode=sync_holidays&year=2026&ssk=mysn1973!
// =============================================================
function sync_holidays(PDO $pdo): void {
    if (!defined('HOLIDAY_API_KEY')) {
        error_log('[cron_schedule_alert] HOLIDAY_API_KEY 미설정');
        return;
    }

    $hApi = new HolidayAPI($pdo);
    $year = (int)($_GET['year'] ?? date('Y'));

    // 올해 + 내년 동기화
    $years = [$year, $year + 1];
    $totalSuccess = 0;

    foreach ($years as $y) {
        $r = $hApi->syncYear($y);
        $totalSuccess += $r['success'];
        if (!empty($r['log'])) {
            error_log("[holiday_sync] {$y}년 오류: " . implode(', ', $r['log']));
        }
    }

    // 카카오톡으로 결과 알림
    $msg = "📅 공휴일 동기화 완료\n"
         . implode(', ', array_map(fn($y)=>"{$y}년", $years))
         . "\n총 {$totalSuccess}건 저장";
    Notify::send($msg, "https://economist.kr/schedule.php?mode=calendar");
}

function format_alert_label(int $min): string {
    if ($min >= 1440) return floor($min / 1440) . '일 전';
    if ($min >= 60)   return floor($min / 60)   . '시간 전';
    return $min . '분 전';
}
