<?php
/**
 * cron_naver_collect.php — 네이버 맛집 전국 시군구 월1회 수집 + 추이 스냅샷 적재.
 *
 *  매월 1일 특정 시각에 여러 번(예: 6분 간격 10회) 호출하면, 한 번 실행에서 시간예산까지
 *  pending 지역을 처리하고 종료 → 다음 호출이 남은 지역을 이어받는다(resume).
 *  한 회차(period=YYYY-MM)의 모든 지역이 done 이 되면 이후 호출은 no-op.
 *
 *  ★외부 HTTP 크론(cron-job.org 등) 권장 — cafe24 웹호스팅은 자체 크론 없음.
 *    URL : /cron_naver_collect.php?key=econ-naver-9x2k&bg=1&max=10&delay=4&max_sec=120
 *    스케줄(매월 1일·10분 간격·새벽):
 *        분 0,10,20,30,40,50 / 시 3-7 / 일 1 / 월·요일 매번  → 30회 fire
 *    → bg=1 이라 크론은 즉시 OK 받고(타임아웃 무관), 백그라운드에서 회당 10지역×4초
 *      딜레이(≈50초·max_sec 120초 내) 수집. 10×30=300 ≥ 229 커버, 완료 후 fire 는 no-op.
 *    ※ 각 지역은 처리 즉시 done 커밋 → 호출이 끊겨도 진행분 보존(resume).
 *    ※ 네이버 안티봇은 IP당 누적 예산형. 회당 10건+10분 휴식이면 429 회피.
 *      몰아치기(연속 호출·짧은 휴식)는 IP 일시차단 위험 → max_sec 로 회당 시간 제한 필수.
 *    ※ 수동 디버깅/이어받기는 bg 빼고 호출하면 진행 리포트를 그대로 받아 볼 수 있음.
 *
 *  파라미터:
 *    key       (필수) 실행 토큰
 *    period    (선택) YYYY-MM. 기본 = 이번 달
 *    max       (선택) 이번 호출에서 처리할 최대 지역 수. 기본 30
 *    max_sec   (선택) 이번 호출 시간예산(초). 기본 480 (게이트웨이 타임아웃 회피)
 *    delay     (선택) 지역 간 딜레이(초). 기본 3
 *    reseed    (선택) 1 이면 지역 마스터 재시드(신규만 추가)
 *    retry_err (선택) 1 이면 이 회차의 error 를 pending 으로 되돌려 재시도
 *    dry       (선택) 1 이면 적재 안 함(미리보기 — fetch/파싱/규칙만)
 */

require_once "./env/cnt.inc";

header('Content-Type: text/html; charset=utf-8');

const NAVER_COLLECT_KEY = 'econ-naver-9x2k';     // ⚠️ 노출되면 바꾸세요
if (PHP_SAPI !== 'cli' && ($_GET['key'] ?? '') !== NAVER_COLLECT_KEY) {
    http_response_code(403);
    exit('forbidden: ?key=' . NAVER_COLLECT_KEY . ' 필요');
}

@set_time_limit(0);

$period   = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['period'] ?? '')) ? $_GET['period'] : date('Y-m');
$maxReg   = max(1, min(250, (int)($_GET['max'] ?? 30)));
$maxSec   = max(30, min(540, (int)($_GET['max_sec'] ?? 480)));
$delaySec = max(0, min(10, (int)($_GET['delay'] ?? 3)));
$dry      = !empty($_GET['dry']);
$START    = microtime(true);

// 외부 HTTP 크론(cron-job.org 등)은 응답 타임아웃(보통 30초)이 있다.
// bg=1 → 즉시 200 OK 로 연결을 끊어 크론은 성공 처리하고, 수집은 백그라운드에서
// 이어서 수행한다(cron_keyword_collector.php 와 동일 패턴). max_sec 시간예산이
// 종료를 보장하므로 좀비 워커는 생기지 않는다. (수동 디버깅은 bg 없이 호출 → 리포트 수신)
if (!empty($_GET['bg'])) {
    ignore_user_abort(true);
    ob_start();
    echo "OK";
    header("Content-Length: " . ob_get_length());
    header("Connection: close");
    ob_end_flush();
    flush();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
} else {
    ignore_user_abort(false);                     // 수동 호출: 끊기면 종료(좀비 방지)
}

$col = new NaverPlaceCollector($pdo);
$col->ensureTables();

// 지역 마스터 시드(최초 1회 자동, reseed=1 이면 재확인)
$seeded = $col->seedRegions();

// 회차 큐 시드: 활성 지역 전부를 (period, region) pending 으로 (INSERT IGNORE → 이미 있으면 보존)
$pdo->prepare(
    "INSERT IGNORE INTO naver_collect_log (period, region_id, status)
     SELECT :p, id, 'pending' FROM naver_collect_region WHERE active = 1"
)->execute([':p' => $period]);

// error 재시도
$retried = 0;
if (!empty($_GET['retry_err'])) {
    $st = $pdo->prepare("UPDATE naver_collect_log SET status='pending' WHERE period=:p AND status='error'");
    $st->execute([':p' => $period]);
    $retried = $st->rowCount();
}

// 남은 pending 지역 (sort_order 순)
$pickSql =
   "SELECT l.id AS log_id, r.id AS region_id, r.region_lv1, r.region_lv2, r.query
      FROM naver_collect_log l
      JOIN naver_collect_region r ON r.id = l.region_id
     WHERE l.period = :p AND l.status = 'pending' AND r.active = 1
     ORDER BY r.sort_order ASC
     LIMIT {$maxReg}";
$pick = $pdo->prepare($pickSql);
$pick->execute([':p' => $period]);
$queue = $pick->fetchAll(PDO::FETCH_ASSOC);

$markDone = $pdo->prepare(
    "UPDATE naver_collect_log SET status=:s, found=:f, ingested=:i, http=:h, msg=:m, collected_at=NOW()
      WHERE id=:id"
);

$done = 0; $errc = 0; $totNew = 0; $totEnrich = 0; $budgetHit = false;
$lines = [];

foreach ($queue as $q) {
    $label = $q['region_lv2'];
    $res = $col->fetchRegion($q['query']);
    if (!$res['ok']) {
        $markDone->execute([':s' => 'error', ':f' => 0, ':i' => 0, ':h' => $res['http'], ':m' => mb_substr($res['msg'], 0, 255), ':id' => $q['log_id']]);
        $errc++;
        $lines[] = sprintf("  ✗ %-10s %s [http %d] %s", $label, $q['query'], $res['http'], $res['msg']);
        // 429 면 더 두드리지 말고 이번 호출 종료(다음 fire 에서 이어받기)
        if ($res['http'] === 429) { $lines[] = "  ⚠️ 429 감지 — 이번 호출 중단(다음 호출에서 재시도)"; break; }
        if ($delaySec > 0) sleep($delaySec);
        if (microtime(true) - $START > $maxSec) { $budgetHit = true; break; }
        continue;
    }

    $sel = $col->selectByRule($res['items']);
    $cNew = 0; $cEnr = 0;
    foreach ($sel as $rec) {
        $r = $col->ingestOne($rec, $label, $q['region_lv1'], $period, !$dry);
        if (($r['action'] ?? '') === 'new')    $cNew++;
        elseif (($r['action'] ?? '') === 'enrich') $cEnr++;
    }
    $totNew += $cNew; $totEnrich += $cEnr;
    $markDone->execute([
        ':s' => $dry ? 'pending' : 'done', ':f' => count($res['items']), ':i' => count($sel),
        ':h' => $res['http'], ':m' => "new {$cNew} / enrich {$cEnr}", ':id' => $q['log_id'],
    ]);
    $done++;
    $lines[] = sprintf("  ✓ %-10s 후보 %3d → 선정 %3d (신규 %d / 보강 %d)", $label, count($res['items']), count($sel), $cNew, $cEnr);

    if ($delaySec > 0) sleep($delaySec);
    if (microtime(true) - $START > $maxSec) { $budgetHit = true; break; }
}

// 진행 현황
$prog = $pdo->prepare(
    "SELECT status, COUNT(*) c FROM naver_collect_log WHERE period=:p GROUP BY status"
);
$prog->execute([':p' => $period]);
$stat = ['pending' => 0, 'done' => 0, 'error' => 0];
foreach ($prog->fetchAll(PDO::FETCH_ASSOC) as $row) $stat[$row['status']] = (int)$row['c'];
$totalReg = array_sum($stat);
$elapsed  = round(microtime(true) - $START, 1);

echo "<pre style='font-family:monospace;font-size:14px;line-height:1.6'>";
echo "🍜 네이버 맛집 수집 — 회차 {$period}" . ($dry ? "  (DRY RUN)" : "") . "\n";
echo str_repeat('─', 52) . "\n";
if ($seeded)  echo "지역 마스터 신규 시드: {$seeded}개\n";
if ($retried) echo "error → pending 되돌림: {$retried}개\n";
echo "이번 호출 처리: 지역 {$done}개 (오류 {$errc}) / 적재 신규 {$totNew} · 보강 {$totEnrich}\n";
echo "소요: {$elapsed}초" . ($budgetHit ? "  (시간예산 도달 — 이어받기 대기)" : "") . "\n";
echo str_repeat('─', 52) . "\n";
echo "회차 진행: 전체 {$totalReg} = ✅done {$stat['done']} / ⏳pending {$stat['pending']} / ✗error {$stat['error']}\n";
if ($stat['pending'] === 0 && $stat['error'] === 0) {
    echo "✅ 회차 {$period} 완료 — 모든 지역 수집됨.\n";

    // ── 회차 전체 완료 알림(Pushover) ───────────────────────────────
    // 이번 호출이 마지막 pending 지역을 실제로 처리($done>0)해 "완료"가 된
    // 순간에만 1회 발송한다. 완료 후의 no-op 호출($done=0)에서는 보내지 않아
    // 매월 1일 30회 호출에도 알림은 단 한 번만 간다. (Notify 미설치 시 조용히 패스)
    if (!$dry && $done > 0 && class_exists('Notify')) {
        $sum = $pdo->prepare(
            "SELECT COUNT(*) AS regions, COALESCE(SUM(ingested), 0) AS picks
               FROM naver_collect_log WHERE period = :p AND status = 'done'"
        );
        $sum->execute([':p' => $period]);
        $agg = $sum->fetch(PDO::FETCH_ASSOC);

        $msg = "🍜 네이버 맛집 수집 완료 — 회차 {$period}\n"
             . "전국 {$agg['regions']}개 지역 / 선정 적재 {$agg['picks']}곳\n"
             . "추이 대시보드에서 확인하세요.";
        Notify::send($msg, "https://economist.kr/naver_trend.php", [
            "title" => "네이버 맛집 수집 완료 ({$period})",
        ]);
        echo "📲 완료 알림(Pushover) 발송함.\n";
    }
} elseif ($stat['pending'] > 0) {
    echo "↻ pending {$stat['pending']}개 남음. 같은 URL 을 다시 호출해 이어서 처리하세요.\n";
} elseif ($stat['error'] > 0) {
    echo "↻ error {$stat['error']}개. retry_err=1 로 재시도하세요.\n";
}
if ($lines) { echo "\n" . implode("\n", array_map('htmlspecialchars', $lines)) . "\n"; }
echo "</pre>";
?>
