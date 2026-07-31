<?php
/**
 * cron_naver_collect.php — 네이버 맛집 전국 시군구 월1회 수집 + 추이 스냅샷 적재.
 *
 *  매월 1일 특정 시각에 여러 번(예: 6분 간격 10회) 호출하면, 한 번 실행에서 시간예산까지
 *  pending 지역을 처리하고 종료 → 다음 호출이 남은 지역을 이어받는다(resume).
 *  한 회차(period=YYYY-MM)의 모든 지역이 done 이 되면 이후 호출은 no-op.
 *
 *  ★외부 HTTP 크론(cron-job.org 등) 권장 — cafe24 웹호스팅은 자체 크론 없음.
 *    카테고리별로 일자를 나눠 3개 등록(IP 부하 분산):
 *      매월 1일: /cron_naver_collect.php?key=econ-naver-9x2k&cat=food&bg=1&max=10&delay=4&max_sec=120
 *      매월 2일: /cron_naver_collect.php?key=econ-naver-9x2k&cat=stay&bg=1&max=10&delay=4&max_sec=120
 *      매월 3일: /cron_naver_collect.php?key=econ-naver-9x2k&cat=camping&bg=1&max=8&delay=4&max_sec=180
 *    각 스케줄(해당 일·10분 간격·새벽):
 *        분 0,10,20,30,40,50 / 시 3-7 / 일 1(또는 2·3) / 월·요일 매번  → 30회 fire
 *    → bg=1 이라 크론은 즉시 OK 받고(타임아웃 무관), 백그라운드에서 회당 max 지역×4초
 *      딜레이로 수집. 회당 지역수×30회 ≥ 229 면 커버, 완료 후 fire 는 no-op.
 *    ※ camping 은 지역마다 '캠핑장'·'오토캠핑' 2회 fetch(합집합) → 지역당 시간 ≈2배.
 *      그래서 max 를 낮추고(8) max_sec 을 늘려(180) 회당 처리량을 맞춘다(8×30=240 ≥ 229).
 *    ※ 각 지역은 처리 즉시 done 커밋 → 호출이 끊겨도 진행분 보존(resume).
 *    ※ 네이버 안티봇은 IP당 누적 예산형. 회당 10건+10분 휴식이면 429 회피.
 *      몰아치기(연속 호출·짧은 휴식)는 IP 일시차단 위험 → max_sec 로 회당 시간 제한 필수.
 *    ※ 수동 디버깅/이어받기는 bg 빼고 호출하면 진행 리포트를 그대로 받아 볼 수 있음.
 *
 *  파라미터:
 *    key       (필수) 실행 토큰
 *    status    (선택) 1 이면 이 카테고리·회차 진행 현황(done/pending/error)만 출력하고 종료
 *                     (수집·시드·DB쓰기 없음). 예: ?key=…&cat=stay&status=1
 *    cat       (선택) 카테고리: food(맛집·기본) | stay(스테이) | camping(캠핑장)
 *                     ★한 번의 호출은 한 카테고리만 처리. 매월 1일=food, 2일=stay, 3일=camping
 *                       식으로 cron-job.org 에 일자별 URL 3개를 등록한다(IP 부하 분산).
 *    period    (선택) YYYY-MM. 기본 = 이번 달
 *    max       (선택) 이번 호출에서 처리할 최대 지역 수. 기본 30
 *    max_sec   (선택) 이번 호출 시간예산(초). 기본 480 (게이트웨이 타임아웃 회피)
 *    delay     (선택) 지역 간 딜레이(초). 기본 3
 *    reseed    (선택) 1 이면 지역 마스터 재시드(신규만 추가)
 *    retry_err (선택) 1 이면 이 회차의 error 를 pending 으로 되돌려 재시도
 *    dry       (선택) 1 이면 적재 안 함(미리보기 — fetch/파싱/규칙만)
 *    purge     (선택) 1 이면 이 카테고리·회차의 추이 스냅샷(place_naver_stat)+진행로그
 *                     (naver_collect_log)만 삭제하고 종료(재수집용). ★cat·period 둘 다 명시 필수.
 *                     place(여행지도 마커)는 건드리지 않음. bg 없이 호출(리포트 확인).
 */

require_once $_SERVER['DOCUMENT_ROOT'] . "/env/cnt.inc";
// env/ 는 .gitignore 대상이라 git 으로 따라오지 않는다 — 배포 누락을 알아볼 수 있게 가드
if (!is_file($_SERVER['DOCUMENT_ROOT'] . "/env/cronbg.inc")) { http_response_code(500); exit("env/cronbg.inc 없음 — env/ 는 git 제외라 수동 배포가 필요합니다.\n"); }
require_once $_SERVER['DOCUMENT_ROOT'] . "/env/cronbg.inc";

header('Content-Type: text/html; charset=utf-8');

const NAVER_COLLECT_KEY = 'econ-naver-9x2k';     // ⚠️ 노출되면 바꾸세요
if (PHP_SAPI !== 'cli' && ($_GET['key'] ?? '') !== NAVER_COLLECT_KEY) {
    http_response_code(403);
    exit('forbidden: ?key=' . NAVER_COLLECT_KEY . ' 필요');
}

@set_time_limit(0);

$category = NaverPlaceCollector::cat((string)($_GET['cat'] ?? 'food'));   // food|stay|camping (미정의→food)
$catLabel = NaverPlaceCollector::catLabel($category);
$period   = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['period'] ?? '')) ? $_GET['period'] : date('Y-m');
$maxReg   = max(1, min(250, (int)($_GET['max'] ?? 30)));
$maxSec   = max(30, min(540, (int)($_GET['max_sec'] ?? 480)));
$delaySec = max(0, min(10, (int)($_GET['delay'] ?? 3)));
$dry      = !empty($_GET['dry']);
$START    = microtime(true);

/* 외부 HTTP 크론(cron-job.org)은 응답을 30초까지만 기다린다.
 * bg=1 → 자기 자신에게 비동기 요청을 던지고 즉시 성공 응답, 수집은 뒤에서 이어 돈다.
 *
 * ★ 여기 있던 「Content-Length + Connection: close + fastcgi_finish_request」 패턴을
 *   버렸다(2026-07-30). 이 서버는 SAPI 가 apache2handler 라 그 패턴이 <b>듣지 않는다</b> —
 *   응답을 끝까지 기다리므로 stay·camping 회차가 매일 "Failed (timeout)" 이었다.
 *   (ignore_user_abort 덕에 수집은 돌았지만 실패가 상시라 감시가 죽어 있었다.)
 *   자세한 배경과 짝으로 필요한 3종 세트는 env/cronbg.inc 주석 참조.
 *
 * 뒤에서 도는 쪽은 화면이 없으므로 출력이 로그로 간다 → 같은 URL 에 &log=1 로 읽는다.
 * 종료는 이 파일의 max_sec 시간예산이 보장한다(cron_bg_begin 에 준 값은 폭주 대비 상한).
 * 수동 디버깅은 bg 를 빼고 호출하면 리포트를 화면으로 그대로 받는다. */
define('NPC_LOG', sys_get_temp_dir() . '/naver_collect_' . $category . '.log');

if (!empty($_GET['log'])) cron_bg_show_log(NPC_LOG, (int)($_GET['n'] ?? 80));

cron_bg_begin(NPC_LOG, $maxSec + 60);

$col = new NaverPlaceCollector($pdo);
$col->ensureTables();

// ── 현황(status): 이 카테고리·회차의 진행 현황만 읽어 출력하고 종료(수집·시드·쓰기 없음) ──
if (!empty($_GET['status'])) {
    $prog = $pdo->prepare(
        "SELECT l.status, COUNT(*) c
           FROM naver_collect_log l JOIN naver_collect_region r ON r.id = l.region_id
          WHERE l.period = :p AND r.category = :cat GROUP BY l.status"
    );
    $prog->execute([':p' => $period, ':cat' => $category]);
    $st = ['pending' => 0, 'done' => 0, 'error' => 0];
    foreach ($prog->fetchAll(PDO::FETCH_ASSOC) as $row) $st[$row['status']] = (int)$row['c'];
    $tot = array_sum($st);

    $sum = $pdo->prepare(
        "SELECT COALESCE(SUM(l.ingested), 0) FROM naver_collect_log l
           JOIN naver_collect_region r ON r.id = l.region_id
          WHERE l.period = :p AND r.category = :cat AND l.status = 'done'"
    );
    $sum->execute([':p' => $period, ':cat' => $category]);
    $picks = (int)$sum->fetchColumn();

    $rest = $pdo->prepare(
        "SELECT r.region_lv2, l.status FROM naver_collect_log l
           JOIN naver_collect_region r ON r.id = l.region_id
          WHERE l.period = :p AND r.category = :cat AND l.status <> 'done'
          ORDER BY l.status, r.sort_order"
    );
    $rest->execute([':p' => $period, ':cat' => $category]);
    $restRows = $rest->fetchAll(PDO::FETCH_ASSOC);

    echo "<pre style='font-family:monospace;font-size:14px;line-height:1.6'>";
    echo "📊 네이버 {$catLabel} 수집 현황 — 회차 {$period}\n";
    echo str_repeat('─', 52) . "\n";
    if ($tot === 0) {
        echo "아직 이 회차 큐가 없습니다(수집 시작 전).\n";
        echo "→ 수집 호출: ?key=" . NAVER_COLLECT_KEY . "&cat={$category}&bg=1&max=10&delay=4&max_sec=120\n";
    } else {
        $pct = round($st['done'] * 100 / $tot);
        echo "전체 {$tot} = ✅done {$st['done']} / ⏳pending {$st['pending']} / ✗error {$st['error']}  ({$pct}% 완료)\n";
        echo "선정 적재(done 합계): {$picks}곳\n";
        if ($st['pending'] === 0 && $st['error'] === 0) {
            echo "✅ 완료 — 모든 지역 수집됨.\n";
        } else {
            echo str_repeat('─', 52) . "\n남은 지역 (" . count($restRows) . "):\n";
            foreach ($restRows as $rr) echo "  " . ($rr['status'] === 'error' ? '✗' : '⏳') . " {$rr['region_lv2']}\n";
        }
    }
    echo "</pre>";
    exit;
}

// ── 정리(purge): 특정 카테고리·회차의 추이 스냅샷 + 진행로그만 삭제하고 종료 ──
//    재수집용. 안전장치: cat·period 를 모두 명시해야 동작. ★place(단일 마스터 마커)는 보존.
//    (place 는 마스터라 purge 대상 아님. 회차 stat·log 만 지우면 재수집 시 place 는
//     findDuplicate 로 매칭돼 갱신되고 그 회차 추이가 다시 기록된다.)
if (!empty($_GET['purge'])) {
    if (($_GET['cat'] ?? '') === '' || ($_GET['period'] ?? '') === '') {
        http_response_code(400);
        exit('purge 는 cat·period 를 모두 명시해야 합니다. 예: ?key=' . NAVER_COLLECT_KEY . '&purge=1&cat=stay&period=' . date('Y-m'));
    }
    $dStat = $pdo->prepare("DELETE FROM place_naver_stat WHERE category = :cat AND period = :p");
    $dStat->execute([':cat' => $category, ':p' => $period]);
    $nStat = $dStat->rowCount();
    $dLog = $pdo->prepare(
        "DELETE l FROM naver_collect_log l
            JOIN naver_collect_region r ON r.id = l.region_id
          WHERE l.period = :p AND r.category = :cat"
    );
    $dLog->execute([':cat' => $category, ':p' => $period]);
    $nLog = $dLog->rowCount();

    echo "<pre style='font-family:monospace;font-size:14px;line-height:1.6'>";
    echo "🧹 purge — 네이버 {$catLabel} 회차 {$period}\n";
    echo str_repeat('─', 52) . "\n";
    echo "place_naver_stat 삭제: {$nStat}행 (추이 스냅샷)\n";
    echo "naver_collect_log 삭제: {$nLog}행 (다음 수집 때 pending 재시드)\n";
    echo "※ place(여행지도 마커)는 보존됨.\n";
    echo "→ 재수집: ?key=" . NAVER_COLLECT_KEY . "&cat={$category}&max=…\n";
    echo "</pre>";
    exit;
}

// ── 정합(reconcile): 이 회차 소멸 유명점 재분류 ──────────────────────────────
//    직접검색 나옴=이번 회차 실값 복구(100컷 누락 되살림) / 안나옴 1차=감시 등록,
//    다음 회차에도 안나오면 2차=폐업 place 삭제(FK CASCADE 로 추이·ref·태그·마커 정리).
//    ★게이트: 이 카테고리·회차 지역수집이 완료(pending·error 0)돼야 실행(미완료면 소멸 오판).
//    ※ 정합은 수집 완료 후 이 크론의 "완료 이후" fire 에서 자동 수행되므로(하단 완료 분기),
//      별도 cron 등록은 필요 없다. 이 reconcile=1 모드는 수동 실행/디버깅용.
//      ?key=…&reconcile=1&cat=food&max=15&delay=4&max_sec=150  (dry=1 이면 판정만·DB 미변경)
if (!empty($_GET['reconcile'])) {
    $g = $pdo->prepare(
        "SELECT l.status, COUNT(*) c FROM naver_collect_log l
           JOIN naver_collect_region r ON r.id = l.region_id
          WHERE l.period = :p AND r.category = :cat GROUP BY l.status"
    );
    $g->execute([':p' => $period, ':cat' => $category]);
    $gs = ['pending' => 0, 'done' => 0, 'error' => 0];
    foreach ($g->fetchAll(PDO::FETCH_ASSOC) as $row) $gs[$row['status']] = (int)$row['c'];
    $prev = NaverPlaceCollector::prevPeriod($period);

    echo "<pre style='font-family:monospace;font-size:14px;line-height:1.6'>";
    echo "🔧 네이버 {$catLabel} 정합(reconcile) — 회차 {$period} (전월 {$prev} 대비)\n";
    echo str_repeat('─', 52) . "\n";
    if (array_sum($gs) === 0 || $gs['pending'] > 0 || $gs['error'] > 0) {
        echo "정합 보류: 이 회차 지역수집 미완료 (done {$gs['done']} / pending {$gs['pending']} / error {$gs['error']}).\n";
        echo "→ 지역수집 완료 후 다시 호출하세요.\n</pre>";
        exit;
    }
    $deadline = $START + $maxSec;
    try {
    $R = $col->reconcile($period, $category, !$dry, $maxReg, $delaySec, $deadline, (int)($_GET['min'] ?? 0));
    } catch (\Throwable $e) { echo "RECON ERROR: ".$e->getMessage()." @ ".basename($e->getFile()).":".$e->getLine()."\n</pre>"; exit; }
    echo "복구(나옴) {$R['recovered']} / 감시등록 1차 {$R['watched']} / 삭제(2차 폐업) {$R['deleted']}"
       . " / fetch실패 {$R['failed']}" . ($dry ? "  (DRY)" : "") . "\n";
    echo "이번 호출 처리 {$R['processed']}건 · 남은 작업 {$R['remaining']}건"
       . ($R['rate'] ? "  ⚠️429 감지—중단(다음 호출 재시도)" : ($R['budget'] ? "  (예산 도달—이어받기)" : "")) . "\n";
    if ($R['lines']) echo str_repeat('─', 52) . "\n" . implode("\n", array_map('htmlspecialchars', $R['lines'])) . "\n";

    // 드레인 완료 알림(Pushover): 남은 0 & 이번 호출에 실제 작업 & 비dry & Notify 존재.
    if (!$dry && $R['remaining'] === 0 && ($R['recovered'] + $R['deleted'] + $R['watched']) > 0 && class_exists('Notify')) {
        $msg = "🔧 네이버 {$catLabel} 정합 완료 — 회차 {$period}\n"
             . "복구 {$R['recovered']} / 감시 {$R['watched']} / 폐업삭제 {$R['deleted']}";
        Notify::send($msg, "https://economist.kr/naver_trend.php?cat={$category}", [
            "title" => "네이버 {$catLabel} 정합 완료 ({$period})",
        ]);
        echo "📲 정합 완료 알림(Pushover) 발송함.\n";
    }
    echo "</pre>";
    exit;
}

// 지역 마스터 시드(이 카테고리만, 최초 1회 자동·reseed=1 이면 재확인)
$seeded = $col->seedRegions($category);

// 회차 큐 시드: 이 카테고리의 활성 지역 전부를 (period, region) pending 으로 (INSERT IGNORE → 이미 있으면 보존)
$pdo->prepare(
    "INSERT IGNORE INTO naver_collect_log (period, region_id, status)
     SELECT :p, id, 'pending' FROM naver_collect_region WHERE active = 1 AND category = :cat"
)->execute([':p' => $period, ':cat' => $category]);

// error 재시도 (이 카테고리 한정)
$retried = 0;
if (!empty($_GET['retry_err'])) {
    $st = $pdo->prepare(
        "UPDATE naver_collect_log l JOIN naver_collect_region r ON r.id = l.region_id
            SET l.status='pending'
          WHERE l.period=:p AND l.status='error' AND r.category=:cat"
    );
    $st->execute([':p' => $period, ':cat' => $category]);
    $retried = $st->rowCount();
}

// 남은 pending 지역 (이 카테고리, sort_order 순)
$pickSql =
   "SELECT l.id AS log_id, r.id AS region_id, r.region_lv1, r.region_lv2, r.query
      FROM naver_collect_log l
      JOIN naver_collect_region r ON r.id = l.region_id
     WHERE l.period = :p AND l.status = 'pending' AND r.active = 1 AND r.category = :cat
     ORDER BY r.sort_order ASC
     LIMIT {$maxReg}";
$pick = $pdo->prepare($pickSql);
$pick->execute([':p' => $period, ':cat' => $category]);
$queue = $pick->fetchAll(PDO::FETCH_ASSOC);

$markDone = $pdo->prepare(
    "UPDATE naver_collect_log SET status=:s, found=:f, ingested=:i, http=:h, msg=:m, collected_at=NOW()
      WHERE id=:id"
);

$done = 0; $errc = 0; $totNew = 0; $totEnrich = 0; $totStat = 0; $budgetHit = false;
$mapCat = NaverPlaceCollector::CATS[$category]['map'] ?? true;   // false=추이 전용(place 미적재)
$lines = [];

// 카테고리의 검색 접미사 목록(camping = ['캠핑장','오토캠핑'], 그 외 1개).
// 둘 이상이면 지역마다 각 접미사로 fetch 해 naver_id 기준 합집합(중복제거) 후 선정·적재한다.
$suffixes = NaverPlaceCollector::suffixes($category);
$primary  = $suffixes[0];

foreach ($queue as $q) {
    $label = $q['region_lv2'];

    $union = [];        // key(naver_id 우선) => 표준 레코드 — 먼저 본 접미사 우선
    $httpLast = 0;
    $rate = false;      // 429 발생 여부
    $fetchErr = [];     // 비치명적 실패(접미사별)

    foreach ($suffixes as $si => $sfx) {
        // 기본 접미사는 시드 쿼리 그대로, 보조 접미사는 끝의 기본접미사만 교체
        $query = ($si === 0) ? $q['query'] : NaverPlaceCollector::altQuery($q['query'], $primary, $sfx);
        $res = $col->fetchRegion($query, $category);
        $httpLast = $res['http'];

        if (!$res['ok']) {
            if ((int)$res['http'] === 429) { $rate = true; break; }   // 더 두드리지 말고 중단
            $fetchErr[] = "{$sfx}:http{$res['http']}";
        } else {
            foreach ($res['items'] as $it) {
                $nid = trim((string)($it['nid'] ?? ''));
                $key = $nid !== ''
                    ? 'n:' . $nid
                    : 'c:' . ($it['name'] ?? '') . '@' . round((float)($it['lat'] ?? 0), 5) . ',' . round((float)($it['lng'] ?? 0), 5);
                if (!isset($union[$key])) $union[$key] = $it;
            }
        }
        // 같은 지역 내 접미사 사이에도 휴식(429 회피)
        if ($si < count($suffixes) - 1 && $delaySec > 0) sleep($delaySec);
    }

    // 429 → 이 지역은 미완료(pending 유지)로 두고 이번 호출 종료(다음 fire 에서 자동 재시도)
    if ($rate) {
        $lines[] = sprintf("  ⚠️ %-10s 429 감지 — 이번 호출 중단(다음 호출에서 재시도)", $label);
        break;
    }

    // 모든 접미사 fetch 실패(수집 0) → error
    if (!$union && $fetchErr) {
        $markDone->execute([':s' => 'error', ':f' => 0, ':i' => 0, ':h' => $httpLast, ':m' => mb_substr(implode(',', $fetchErr), 0, 255), ':id' => $q['log_id']]);
        $errc++;
        $lines[] = sprintf("  ✗ %-10s %s", $label, implode(',', $fetchErr));
        if ($delaySec > 0) sleep($delaySec);
        if (microtime(true) - $START > $maxSec) { $budgetHit = true; break; }
        continue;
    }

    $items = array_values($union);
    $sel   = $col->selectByRule($items, $category);
    $cNew = 0; $cEnr = 0; $cStat = 0;
    foreach ($sel as $rec) {
        $r = $col->ingestOne($rec, $label, $q['region_lv1'], $period, !$dry, $category);
        $act = $r['action'] ?? '';
        if      ($act === 'new')    $cNew++;
        elseif  ($act === 'enrich') $cEnr++;
        elseif  ($act === 'stat')   $cStat++;
    }
    $totNew += $cNew; $totEnrich += $cEnr; $totStat += $cStat;
    $msg = $mapCat ? "new {$cNew} / enrich {$cEnr}" : "stat {$cStat}";
    if ($fetchErr) $msg .= " (부분실패: " . implode(',', $fetchErr) . ")";
    $markDone->execute([
        ':s' => $dry ? 'pending' : 'done', ':f' => count($items), ':i' => count($sel),
        ':h' => $httpLast, ':m' => mb_substr($msg, 0, 255), ':id' => $q['log_id'],
    ]);
    $done++;
    $sfxNote = count($suffixes) > 1 ? ' [' . implode('+', $suffixes) . ' 합집합]' : '';
    $lines[] = $mapCat
        ? sprintf("  ✓ %-10s 후보 %3d → 선정 %3d (신규 %d / 보강 %d)%s", $label, count($items), count($sel), $cNew, $cEnr, $sfxNote)
        : sprintf("  ✓ %-10s 후보 %3d → 선정 %3d (추이 기록 %d)%s", $label, count($items), count($sel), $cStat, $sfxNote);

    if ($delaySec > 0) sleep($delaySec);
    if (microtime(true) - $START > $maxSec) { $budgetHit = true; break; }
}

// 진행 현황 (이 카테고리 한정)
$prog = $pdo->prepare(
    "SELECT l.status, COUNT(*) c
       FROM naver_collect_log l JOIN naver_collect_region r ON r.id = l.region_id
      WHERE l.period=:p AND r.category=:cat GROUP BY l.status"
);
$prog->execute([':p' => $period, ':cat' => $category]);
$stat = ['pending' => 0, 'done' => 0, 'error' => 0];
foreach ($prog->fetchAll(PDO::FETCH_ASSOC) as $row) $stat[$row['status']] = (int)$row['c'];
$totalReg = array_sum($stat);
$elapsed  = round(microtime(true) - $START, 1);

echo "<pre style='font-family:monospace;font-size:14px;line-height:1.6'>";
echo "🍜 네이버 {$catLabel} 수집 — 회차 {$period}" . ($dry ? "  (DRY RUN)" : "") . "\n";
echo str_repeat('─', 52) . "\n";
if ($seeded)  echo "지역 마스터 신규 시드: {$seeded}개\n";
if ($retried) echo "error → pending 되돌림: {$retried}개\n";
if ($mapCat) {
    echo "이번 호출 처리: 지역 {$done}개 (오류 {$errc}) / 적재 신규 {$totNew} · 보강 {$totEnrich}\n";
} else {
    echo "이번 호출 처리: 지역 {$done}개 (오류 {$errc}) / 추이 기록 {$totStat} (place 미적재·추이 전용)\n";
}
echo "소요: {$elapsed}초" . ($budgetHit ? "  (시간예산 도달 — 이어받기 대기)" : "") . "\n";
echo str_repeat('─', 52) . "\n";
echo "회차 진행: 전체 {$totalReg} = ✅done {$stat['done']} / ⏳pending {$stat['pending']} / ✗error {$stat['error']}\n";
if ($stat['pending'] === 0 && $stat['error'] === 0) {
    echo "✅ 회차 {$period} {$catLabel} 완료 — 모든 지역 수집됨.\n";

    // ── 회차(카테고리) 완료 알림(Pushover) ──────────────────────────
    // 이번 호출이 마지막 pending 지역을 실제로 처리($done>0)해 "완료"가 된
    // 순간에만 1회 발송한다. 완료 후의 no-op 호출($done=0)에서는 보내지 않아
    // 매월 30회 호출에도 알림은 카테고리당 단 한 번만 간다. (Notify 미설치 시 조용히 패스)
    if (!$dry && $done > 0 && class_exists('Notify')) {
        $sum = $pdo->prepare(
            "SELECT COUNT(*) AS regions, COALESCE(SUM(l.ingested), 0) AS picks
               FROM naver_collect_log l JOIN naver_collect_region r ON r.id = l.region_id
              WHERE l.period = :p AND r.category = :cat AND l.status = 'done'"
        );
        $sum->execute([':p' => $period, ':cat' => $category]);
        $agg = $sum->fetch(PDO::FETCH_ASSOC);

        $msg = "🍜 네이버 {$catLabel} 수집 완료 — 회차 {$period}\n"
             . "전국 {$agg['regions']}개 지역 / 선정 적재 {$agg['picks']}곳\n"
             . "추이 대시보드에서 확인하세요.";
        Notify::send($msg, "https://economist.kr/naver_trend.php?cat={$category}", [
            "title" => "네이버 {$catLabel} 수집 완료 ({$period})",
        ]);
        echo "📲 완료 알림(Pushover) 발송함.\n";
    }

    // ── 자동 정합(reconcile): 수집이 끝난 뒤 남는 시간예산으로 소멸 유명점 재분류 ─────
    //    별도 크론 등록 없이, 이 수집 크론의 "완료 이후" fire 들이 정합까지 마무리한다.
    //    (완료 분기 안이라 게이트=수집완료는 이미 충족.) self-drain 이라 여러 fire 로 나눠
    //    처리되고, 다 끝나면 이후 fire 에선 처리 0(조용). dry·시간예산 초과 시엔 건너뜀.
    if (!$dry && !$budgetHit && (microtime(true) - $START) < $maxSec - 5) {
        try {
            $deadline = $START + $maxSec;
            $R = $col->reconcile($period, $category, true, max(1, $maxReg), $delaySec, $deadline, (int)($_GET['min'] ?? 0));
            if (($R['processed'] > 0) || ($R['remaining'] > 0)) {
                echo str_repeat('─', 52) . "\n";
                echo "🔧 자동 정합: 복구 {$R['recovered']} / 감시 1차 {$R['watched']} / 삭제 2차 {$R['deleted']}"
                   . " / 실패 {$R['failed']} · 남은 {$R['remaining']}"
                   . ($R['rate'] ? "  ⚠️429" : ($R['budget'] ? "  (예산도달)" : "")) . "\n";
                if ($R['lines']) echo implode("\n", array_map('htmlspecialchars', $R['lines'])) . "\n";
            }
            // 드레인 완료 + 이번에 실제 작업 → Pushover 1회(삭제/복구가 있었음을 알림)
            if ($R['remaining'] === 0 && ($R['recovered'] + $R['deleted'] + $R['watched']) > 0 && class_exists('Notify')) {
                Notify::send(
                    "🔧 네이버 {$catLabel} 정합 완료 — 회차 {$period}\n"
                    . "복구 {$R['recovered']} / 감시 {$R['watched']} / 폐업삭제 {$R['deleted']}",
                    "https://economist.kr/naver_trend.php?cat={$category}",
                    ["title" => "네이버 {$catLabel} 정합 완료 ({$period})"]
                );
                echo "📲 정합 완료 알림(Pushover) 발송함.\n";
            }
        } catch (\Throwable $e) {
            echo "⚠️ 자동 정합 오류(수집엔 영향 없음): " . htmlspecialchars($e->getMessage()) . "\n";
        }
    }
} elseif ($stat['pending'] > 0) {
    echo "↻ pending {$stat['pending']}개 남음. 같은 URL 을 다시 호출해 이어서 처리하세요.\n";
} elseif ($stat['error'] > 0) {
    echo "↻ error {$stat['error']}개. retry_err=1 로 재시도하세요.\n";
}
if ($lines) { echo "\n" . implode("\n", array_map('htmlspecialchars', $lines)) . "\n"; }
echo "</pre>";
?>
