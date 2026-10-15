<?php
// 폰 바탕화면 위젯(안드로이드 앱 「스케줄러 위젯」) 전용 월간 데이터.
// 위젯 앱에는 로그인 쿠키가 없으므로 세션 대신 토큰으로 막는다 — 토큰은 env/sch_widget.inc (gitignore).
// 주소: /schedule_widget.php?t=<토큰>&ym=YYYY-MM  → 그 달 그리드(이전월 말~익월 초 포함) 하루씩 (월간 위젯)
//       /schedule_widget.php?t=<토큰>&from=YYYY-MM-DD&n=7 → 그 날부터 n일 (주간 위젯)
// 일정 조회는 화면과 같은 Schedule::listByRange() 를 쓴다(반복·음력·말일 규칙을 따로 풀지 않는다).
ob_start();
require_once "./env/cnt.inc";
if (file_exists("./env/sch_widget.inc")) require_once "./env/sch_widget.inc";
ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
date_default_timezone_set('Asia/Seoul');

$tok = (string)($_GET['t'] ?? '');
if (!defined('SCH_WIDGET_TOKEN') || SCH_WIDGET_TOKEN === '' || !hash_equals(SCH_WIDGET_TOKEN, $tok)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'forbidden']);
    exit;
}

$from = (string)($_GET['from'] ?? '');
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && strtotime($from)) {
    // 주간 위젯: &from=YYYY-MM-DD&n=7 → 그 날부터 n일 (n 은 1~42)
    $n     = max(1, min(42, (int)($_GET['n'] ?? 7)));
    $start = date('Y-m-d', strtotime($from));
    $end   = date('Y-m-d', strtotime($start . ' +' . ($n - 1) . ' days'));
    $ym    = substr($start, 0, 7);
    $first = $start;
    $last  = $end;
} else {
    $ym = (string)($_GET['ym'] ?? '');
    if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $m) || (int)$m[2] < 1 || (int)$m[2] > 12) $ym = date('Y-m');
    $first = $ym . '-01';
    $last  = date('Y-m-t', strtotime($first));
    // 그리드 범위 — schedule_api.php list(month) 와 같은 규칙(일요일 시작)
    $start = date('Y-m-d', strtotime($first . ' -' . (int)date('w', strtotime($first)) . ' days'));
    $end   = date('Y-m-d', strtotime($last  . ' +' . (6 - (int)date('w', strtotime($last))) . ' days'));
}

try {
    // 날짜 뼈대
    $days = [];
    for ($t = strtotime($start); $t <= strtotime($end); $t += 86400) {
        $d = date('Y-m-d', $t);
        $days[$d] = ['d' => $d, 'lun' => '', 'hol' => '', 'note' => '', 'evs' => []];
    }

    // 음력(tbl_lunar_solar) — 초하루는 「월.1」, 그 밖은 일만
    $lunHead = [];
    $st = $pdo->prepare("SELECT solar_date, lun_month, lun_day, is_leap FROM tbl_lunar_solar WHERE solar_date BETWEEN ? AND ?");
    $st->execute([$start, $end]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d = $r['solar_date'];
        if (!isset($days[$d])) continue;
        $lm = (int)$r['lun_month']; $ld = (int)$r['lun_day'];
        $days[$d]['lun'] = $ld === 1 ? (($r['is_leap'] ? '윤' : '') . $lm . '.1') : (string)$ld;
        if ($d >= $first && $d <= $last) $lunHead[($r['is_leap'] ? '윤' : '') . $lm . '월'] = true;
    }

    // 공휴일·절기
    try {
        foreach ((new HolidayAPI($pdo))->getAsEvents($start, $end) as $h) {
            $d = substr((string)$h['start_dt'], 0, 10);
            if (!isset($days[$d])) continue;
            $isRed = ($h['is_holiday'] ?? 0) == 1 || ($h['event_type'] ?? '') === 'holiday';
            $k = $isRed ? 'hol' : 'note';
            $days[$d][$k] = $days[$d][$k] === '' ? $h['title'] : $days[$d][$k] . ',' . $h['title'];
        }
    } catch (Throwable $e) { /* 공휴일 표가 없어도 일정은 보낸다 */ }

    // 분류·프로젝트 색/아이콘 — 화면의 evBgColor()·projNumBadge() 와 같은 규칙
    $proj = [];
    foreach ($pdo->query("SELECT id, color, icon FROM tbl_project")->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $proj[(int)$p['id']] = $p;
    }

    $events = (new Schedule($pdo))->listByRange($start, $end);
    foreach ($events as $ev) {
        $d = substr((string)($ev['start_dt'] ?: ($ev['due_dt'] ?? '')), 0, 10);
        if (!isset($days[$d])) continue;
        $type = (string)($ev['event_type'] ?? '');
        $p    = !empty($ev['project_id']) ? ($proj[(int)$ev['project_id']] ?? null) : null;
        $icon = $p && !empty($p['icon']) ? $p['icon'] : ($type === 'anniversary' && !empty($ev['icon']) ? $ev['icon'] : '');
        $tm   = '';
        $te   = '';
        if ($type === 'timed' && ($ev['is_allday'] ?? 0) != 1) {
            $tm = substr((string)$ev['start_dt'], 11, 5);
            if ($tm === '00:00') $tm = '';
            // 끝 시각은 같은 날일 때만 (주간 위젯 「10:00~11:30」)
            $ed = (string)($ev['end_dt'] ?? '');
            if ($tm !== '' && substr($ed, 0, 10) === $d) {
                $te = substr($ed, 11, 5);
                if ($te === '00:00' || $te === '23:59' || $te === $tm) $te = '';
            }
        }
        $days[$d]['evs'][] = [
            't'    => (string)($ev['title'] ?? ''),
            'c'    => ($p['color'] ?? '') ?: (($ev['color'] ?? '') ?: '#3498db'),
            'i'    => $icon,
            'tm'   => $tm,
            'te'   => $te,
            'p'    => (string)($ev['place_name'] ?? ''),
            'k'    => $type,
            'done' => ($ev['is_done'] ?? 0) == 1 ? 1 : 0,
        ];
    }
    // 하루 안의 순서: 기념일 → 할일 → 종일 → 시각순 (화면 주간뷰 상단행과 같은 순서)
    $rank = ['anniversary' => 0, 'todo' => 1];
    foreach ($days as &$day) {
        usort($day['evs'], function ($a, $b) use ($rank) {
            $ra = $rank[$a['k']] ?? ($a['tm'] === '' ? 2 : 3);
            $rb = $rank[$b['k']] ?? ($b['tm'] === '' ? 2 : 3);
            return $ra <=> $rb ?: strcmp($a['tm'], $b['tm']);
        });
    }
    unset($day);

    echo json_encode([
        'ok'      => true,
        'ym'      => $ym,
        'today'   => date('Y-m-d'),
        'lunHead' => implode(' – ', array_keys($lunHead)),
        'days'    => array_values($days),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
