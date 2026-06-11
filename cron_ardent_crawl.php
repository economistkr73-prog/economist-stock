<?php
/**
 * cron_ardent_crawl.php — 아덴트뉴스 '국내여행' 크롤러 (오케스트레이션 + 테스트)
 *
 * 설계: CRAWLER_BRIEF.md  /  파싱·HTTP 는 ArdentNews.class, 적재는 Place::ingest, 좌표화는 cron_place_geocode.php
 *
 * 모드 (현재: 저장 없는 검증 모드부터):
 *   ?key=econ-ardent&test=15288      단일 기사 파싱 결과 덤프 (저장 X) ← 추출 검증용
 *   ?key=econ-ardent&list=1          목록 1페이지의 idxno 추출 (저장 X)
 *   ?key=econ-ardent&dry=1&pages=1   1페이지 기사들 파싱 결과만 덤프 (저장 X)
 *   (적재 모드 ingest 는 검증 통과 후 추가 예정)
 *
 * 매너: 요청 간 sleep, 직렬 처리, 신원 밝힌 UA, 원본 HTML 캐시.
 */

require_once "./env/cnt.inc";

header('Content-Type: text/html; charset=utf-8');

$TOKEN = 'econ-ardent';
if (PHP_SAPI !== 'cli' && ($_GET['key'] ?? '') !== $TOKEN) {
    http_response_code(403);
    exit('forbidden: ?key=' . $TOKEN . ' 필요');
}

@set_time_limit(0);
mb_internal_encoding('UTF-8');
// 관리/크론 도구라 에러를 화면에 노출(원인 추적용)
ini_set('display_errors', '1');
error_reporting(E_ALL);
// 진행 상황 실시간 출력(스트리밍) — sleep 가 있는 다건 처리에서 브라우저가 멈춘 듯 보이지 않게
while (ob_get_level() > 0) @ob_end_flush();
@ob_implicit_flush(true);

$cacheDir = sys_get_temp_dir() . '/ardent_cache';
$crawler  = new ArdentNews($cacheDir);

// bg 모드(run=1&bg=1)는 즉시 헤더(Connection:close)를 보내야 하므로 <pre> 출력 안 함.
$IS_BG = !empty($_GET['run']) && !empty($_GET['bg']);
if (!$IS_BG) echo "<pre style='font-family:monospace;font-size:13px;line-height:1.55;white-space:pre-wrap'>";

// ── 모드 0: 처리이력 리셋 (개선 후 재크롤용) ──────────────
// 처리이력만 비움. place/place_ref 는 그대로 두고, 재크롤 시 dedup_key 로 upsert(이름·주소 갱신)
if (!empty($_GET['reset_crawl'])) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tbl_ardent_crawl (idxno BIGINT UNSIGNED PRIMARY KEY, status ENUM('done','skip') NOT NULL, place_id BIGINT UNSIGNED NULL, reason VARCHAR(255) NULL, crawled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $n = (int)$pdo->query("SELECT COUNT(*) FROM tbl_ardent_crawl")->fetchColumn();
    $pdo->exec("TRUNCATE TABLE tbl_ardent_crawl");
    echo "🔄 처리이력 리셋: {$n}건 비움\n";

    // &purge=1 : 아덴트뉴스에서 적재한 place 도 함께 삭제(이름 변경으로 생긴 중복 정리용).
    // seed 데모(source_site 없음/다름)는 보존. place_ref 는 FK CASCADE 로 자동 삭제.
    if (!empty($_GET['purge'])) {
        (new Place($pdo))->ensureTable();
        $del = $pdo->exec("DELETE FROM place WHERE JSON_EXTRACT(attributes, '$.source_site') = 'ardentnews'");
        echo "🗑️  아덴트뉴스 place 삭제: " . (int)$del . "건 (seed 데모는 보존) — 이제 run=1 으로 깨끗하게 재크롤\n";
    } else {
        echo "→ run=1 으로 재크롤. (이름 로직을 바꿨다면 중복 방지 위해 &purge=1 로 기존 아덴트 place 삭제 권장)\n";
    }
    echo "</pre>";
    exit;
}

// ── 모드: 진행 상태 (cron 모니터링) ───────────────────────
if (!empty($_GET['status'])) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tbl_ardent_state (k VARCHAR(40) PRIMARY KEY, v VARCHAR(255) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tbl_ardent_crawl (idxno BIGINT UNSIGNED PRIMARY KEY, status ENUM('done','skip') NOT NULL, place_id BIGINT UNSIGNED NULL, reason VARCHAR(255) NULL, crawled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    (new Place($pdo))->ensureTable();
    $sg = function (string $k, string $d) use ($pdo): string {
        $s = $pdo->prepare("SELECT v FROM tbl_ardent_state WHERE k=?"); $s->execute([$k]);
        $v = $s->fetchColumn(); return $v === false ? $d : (string)$v;
    };
    $tdone  = (int)$pdo->query("SELECT COUNT(*) FROM tbl_ardent_crawl WHERE status='done'")->fetchColumn();
    $tskip  = (int)$pdo->query("SELECT COUNT(*) FROM tbl_ardent_crawl WHERE status='skip'")->fetchColumn();
    $places = (int)$pdo->query("SELECT COUNT(*) FROM place")->fetchColumn();
    $okc    = (int)$pdo->query("SELECT COUNT(*) FROM place WHERE geocode_status='ok'")->fetchColumn();
    $pend   = (int)$pdo->query("SELECT COUNT(*) FROM place WHERE geocode_status='pending' AND address IS NOT NULL AND address<>''")->fetchColumn();
    $fail   = (int)$pdo->query("SELECT COUNT(*) FROM place WHERE geocode_status='failed'")->fetchColumn();
    echo "📊 아덴트 크롤 진행 상태\n" . str_repeat('─', 48) . "\n";
    echo "다음 시작 페이지: " . $sg('last_page', '1') . " / 324\n";
    echo "최근 실행: " . $sg('last_run', '(없음)') . "\n";
    echo "처리이력 — done {$tdone} / skip {$tskip}\n";
    echo "place — 총 {$places}  (좌표 ok {$okc} / pending {$pend} / failed {$fail})\n";
    echo "</pre>";
    exit;
}

// ── 모드 1: 단일 기사 파싱 검증 ────────────────────────────
if (isset($_GET['test'])) {
    $idxno = (int)$_GET['test'];
    echo "🔎 단일 기사 파싱 테스트 — idxno={$idxno}\n";
    echo str_repeat('─', 60) . "\n";

    $html = $crawler->fetchArticle($idxno);
    if (!$html) { echo "❌ HTML 요청 실패\n</pre>"; exit; }
    echo "HTML 수신: " . number_format(strlen($html)) . " bytes\n\n";

    $r = ArdentNews::parseArticle($html, $idxno);

    // 본문 추출 진단 (regex 가 본문을 제대로 받았는지 확인용)
    $bodyText = ArdentNews::extractBodyText($html);
    $kw = array_values(array_filter(array_map('trim', explode(',', $r['meta']['keywords'] ?? ''))));
    echo "● 본문 추출 진단\n";
    echo "   본문 길이: " . mb_strlen($bodyText) . "자\n";
    echo "   주소: "   . (ArdentNews::extractAddress($bodyText, $kw) ?: '(실패)') . "\n";
    echo "   전화: "   . (ArdentNews::extractPhone($bodyText)        ?: '(실패)') . "\n";
    echo "   시간: "   . (ArdentNews::extractHours($bodyText)        ?: '(실패)') . "\n";
    echo "   입장료: " . (ArdentNews::extractAdmission($bodyText)    ?: '(실패)') . "\n";
    echo "   본문 앞 200자: " . htmlspecialchars(mb_substr($bodyText, 0, 200)) . "\n\n";

    echo "● 주요 meta 태그\n";
    foreach (['og:title','og:url','classification','article:section1','keywords',
              'article:published_time','author','og:image','dable:item_id'] as $k) {
        if (isset($r['meta'][$k])) echo "   {$k}: " . $r['meta'][$k] . "\n";
    }
    echo "\n";

    if (!$r['ok']) {
        echo "⏭️  SKIP — {$r['reason']}\n";
    } else {
        echo "✅ 추출 성공 — 표준 입력 포맷:\n";
        echo json_encode($r['item'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        echo "\n(dedup_key 미리보기) " . Place::makeDedupKey(
            $r['item']['place']['name'], $r['item']['place']['region_lv2'] ?? null
        ) . "\n";
    }
    echo "</pre>";
    exit;
}

// ── 모드: 페이지네이션 진단 (그냥/쿠키+워밍업/Referer 비교) ─
if (isset($_GET['diag'])) {
    $page = max(2, (int)$_GET['diag']);
    $UA   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
    $base = 'https://www.ardentnews.co.kr/news/articleList.html';
    $q = fn($p) => $base . "?page={$p}&total=6475&box_idxno=&sc_sub_section_code=S2N3&view_type=sm";
    $first = function ($html) {
        $scope = (string)$html;
        $s = stripos($scope, 'section-list');
        if ($s !== false) { $rest = substr($scope, $s); $c = stripos($rest, 'auto-article'); $scope = $c !== false ? substr($rest, 0, $c) : $rest; }
        preg_match_all('/articleView\.html\?idxno=(\d+)/', $scope, $m);
        return implode(',', array_slice(array_values(array_unique($m[1])), 0, 5)) ?: '(없음)';
    };
    $get = function ($url, $jar, $referer, $extra = []) use ($UA) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => $UA, CURLOPT_ENCODING => '']);
        if ($extra) curl_setopt($ch, CURLOPT_HTTPHEADER, $extra);
        if ($jar) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
        if ($referer) curl_setopt($ch, CURLOPT_REFERER, $referer);
        $r = (string)curl_exec($ch);
        $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return [substr($r, 0, $hs), substr($r, $hs)];
    };
    echo "🩺 페이지네이션 진단 — page={$page} (브라우저 정답 page2=15234.. / page3=15179..)\n" . str_repeat('─', 60) . "\n";

    [$hA, $bA] = $get($q($page), null, null);
    echo "A. 그냥 page{$page}          : " . $first($bA) . "\n";

    $jar = sys_get_temp_dir() . '/diag_ck.txt'; @unlink($jar);
    [$h1, $b1] = $get($q(1), $jar, null);
    preg_match_all('/^set-cookie:[^\r\n]*/im', $h1, $sc);
    echo "   page1 Set-Cookie        : " . (implode(' | ', $sc[0]) ?: '(없음)') . "\n";
    echo "   page1 first             : " . $first($b1) . "\n";

    [$h2, $b2] = $get($q($page), $jar, null);
    echo "B. 쿠키+워밍업 page{$page}    : " . $first($b2) . "\n";

    [$h3, $b3] = $get($q($page), null, $q($page - 1));
    echo "C. Referer page{$page}       : " . $first($b3) . "\n";

    [$h4, $b4] = $get($q($page), null, $q($page - 1), ['X-Requested-With: XMLHttpRequest']);
    echo "D. X-Requested-With page{$page}: " . $first($b4) . " (size " . strlen($b4) . ")\n";

    // E. 같은 URL 에 &exec=Read 류 없이, ajax 전용 헤더+Accept
    [$h5, $b5] = $get($q($page), null, $q($page - 1), ['X-Requested-With: XMLHttpRequest', 'Accept: text/html, */*; q=0.01']);
    echo "E. XHR+Accept page{$page}    : " . $first($b5) . " (size " . strlen($b5) . ")\n";

    echo "쿠키jar 내용: " . substr((string)@file_get_contents($jar), 0, 400) . "\n";
    echo "</pre>";
    exit;
}

// ── 모드: 목록 HTML 구조 디버그 (본문목록 vs 사이드바 구분용) ─
if (isset($_GET['listraw'])) {
    $page = max(1, (int)$_GET['listraw']);
    $html = $crawler->httpGet(sprintf(ArdentNews::LIST_URL, $page), null);
    echo "🔬 목록 HTML 구조 — page={$page} (" . number_format(strlen((string)$html)) . " bytes)\n";
    echo "각 idxno 링크 앞 200자(감싸는 태그 class 확인용):\n" . str_repeat('─', 60) . "\n";
    if ($html && preg_match_all('/articleView\.html\?idxno=(\d+)/', $html, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $i => $hit) {
            $pos    = $hit[1];
            $before = substr($html, max(0, $pos - 200), min(200, $pos));
            $before = (string)preg_replace('/\s+/u', ' ', (string)$before);
            echo "#" . $m[1][$i][0] . "  …" . htmlspecialchars($before) . "\n";
        }
    } else {
        echo "(링크 없음 또는 요청 실패)\n";
    }

    // 실제 페이지네이션/목록 URL 형식 (page 2 의 진짜 링크 확인용)
    if ($html && preg_match_all('/articleList\.html\?[^"\'<>\s]*/i', $html, $pg)) {
        $uniq = array_values(array_unique($pg[0]));
        echo "\n── articleList 링크 (" . count($uniq) . "종) ──\n";
        foreach ($uniq as $u) echo "  " . htmlspecialchars(html_entity_decode($u)) . "\n";
    }
    echo "</pre>";
    exit;
}

// ── 모드 2: 목록 페이지 idxno 추출 검증 ───────────────────
if (isset($_GET['list'])) {
    $page = max(1, (int)$_GET['list']);
    $url  = sprintf(ArdentNews::LIST_AJAX, $page);
    echo "🔎 목록 idxno 추출 (AJAX) — page={$page}\n";
    echo "URL: " . htmlspecialchars($url) . "\n";
    echo str_repeat('─', 60) . "\n";
    $json = $crawler->httpGet($url, null, 3, ['X-Requested-With: XMLHttpRequest', 'Accept: application/json, text/javascript, */*; q=0.01']);
    echo "응답 " . number_format(strlen((string)$json)) . " bytes, 앞 200자: " . htmlspecialchars(mb_substr((string)$json, 0, 200)) . "\n";
    $ids = ArdentNews::parseListJson($json);
    echo count($ids) . "건:\n" . implode(', ', $ids) . "\n";
    echo "</pre>";
    exit;
}

// ── 모드 3: 1페이지 기사들 파싱 결과만 덤프 (저장 X) ───────
if (!empty($_GET['dry'])) {
    $pages      = max(1, min(5, (int)($_GET['pages'] ?? 1)));
    $TIME_BUDGET = 22;                 // 프록시 타임아웃 회피 — 초과 시 중단하고 요약
    $START_TS    = microtime(true);
    $ok = 0; $skip = 0; $noAddr = 0;
    echo "🔎 dry-run (저장 없음) — pages={$pages}, 시간예산 {$TIME_BUDGET}s\n";
    echo str_repeat('─', 60) . "\n";
    for ($p = 1; $p <= $pages; $p++) {
        $ids = $crawler->fetchListIdxnos($p);
        echo "── page {$p}: " . count($ids) . "건 ──\n";
        foreach ($ids as $idxno) {
            $html = $crawler->fetchArticle($idxno);
            $r = ArdentNews::parseArticle($html, $idxno);
            if ($r['ok']) {
                $ok++;
                $pl = $r['item']['place'];
                if (empty($pl['address'])) $noAddr++;
                echo sprintf("  ✅ #%d  %s | %s | %s\n", $idxno,
                    $pl['name'] ?? '?', $pl['category'] ?? '?', $pl['address'] ?? '(주소없음·장소명만)');
            } else {
                $skip++;
                echo sprintf("  ⏭️  #%d  %s\n", $idxno, $r['reason']);
            }
            if (microtime(true) - $START_TS > $TIME_BUDGET) {
                echo "  …시간예산 도달, 중단\n";
                break 2;
            }
            sleep(1); // 매너: 요청 간 1초
        }
    }
    echo str_repeat('─', 60) . "\n";
    echo "완료 — 추출 {$ok} (그중 주소없음 {$noAddr}) / skip {$skip}\n";
    echo "※ '주소없음·장소명만' = 적재는 되나 좌표화는 2차(검색API 필요). skip = 필터(A) 탈락\n";
    echo "</pre>";
    exit;
}

// ── 모드 4: 실제 적재 (증분 크롤 + resume 포인터 + 백그라운드) ─
//   수동:  run=1                  (화면 출력, 22초 예산)
//   크론:  run=1&bg=1             (즉시 OK 응답+연결종료 후 백그라운드 장시간 — cron_keyword_collector 패턴)
//          run=1&bg=1&budget=180  (백그라운드 1회 처리시간(초) 조절, 30~600)
//          run=1&from=50          (특정 페이지부터 강제 시작)
//   last_page(진행 페이지)를 기억해 매 실행 이어받음. 324p 완주하면 1로 순환(신규기사 반영).
if (!empty($_GET['run'])) {
    $LAST_PAGE = 324; // 국내여행 섹션 마지막 페이지. 신규 기사는 page 1 로 들어오므로 324 고정으로 충분
                      // (324 완주 후 1 로 순환하며 새 기사 반영)

    // resume 포인터 상태 테이블
    $pdo->exec("CREATE TABLE IF NOT EXISTS tbl_ardent_state (k VARCHAR(40) PRIMARY KEY, v VARCHAR(255) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $stateGet = function (string $k, string $def) use ($pdo): string {
        $s = $pdo->prepare("SELECT v FROM tbl_ardent_state WHERE k=?"); $s->execute([$k]);
        $v = $s->fetchColumn(); return $v === false ? $def : (string)$v;
    };
    $stateSet = function (string $k, string $v) use ($pdo): void {
        $s = $pdo->prepare("INSERT INTO tbl_ardent_state (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)");
        $s->execute([$k, $v]);
    };

    $pdo->exec("CREATE TABLE IF NOT EXISTS tbl_ardent_crawl (idxno BIGINT UNSIGNED PRIMARY KEY, status ENUM('done','skip') NOT NULL, place_id BIGINT UNSIGNED NULL, reason VARCHAR(255) NULL, crawled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $place = new Place($pdo);
    $place->ensureTable();

    $bg          = !empty($_GET['bg']);
    $TIME_BUDGET = $bg ? max(30, min(600, (int)($_GET['budget'] ?? 180))) : 22;
    $startPage   = isset($_GET['from']) ? max(1, (int)$_GET['from']) : (int)$stateGet('last_page', '1');
    if ($startPage < 1 || $startPage > $LAST_PAGE) $startPage = 1;

    // 백그라운드: 즉시 OK 응답 + 연결 종료 후 계속 실행 (cron-job.org 타임아웃 회피)
    if ($bg) {
        ignore_user_abort(true);
        while (ob_get_level() > 0) @ob_end_clean();
        $okMsg = "OK ardent crawl bg — from page {$startPage}, budget {$TIME_BUDGET}s\n";
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Length: ' . strlen($okMsg));
        header('Connection: close');
        echo $okMsg;
        @flush();
        // 이후 출력은 연결종료로 안 보임 → 진행은 ?status=1 로 확인
    }

    $done = array_flip($pdo->query("SELECT idxno FROM tbl_ardent_crawl")->fetchAll(PDO::FETCH_COLUMN));
    $rec  = $pdo->prepare("INSERT INTO tbl_ardent_crawl (idxno, status, place_id, reason) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE status=VALUES(status), place_id=VALUES(place_id), reason=VALUES(reason)");

    $START_TS = microtime(true);
    $ingested = 0; $skipped = 0; $already = 0; $errors = 0; $budgetHit = false;
    if (!$bg) echo "🕷️  적재 크롤 — page {$startPage}부터, 예산 {$TIME_BUDGET}s\n" . str_repeat('─', 60) . "\n";

    for ($p = $startPage; $p <= $LAST_PAGE; $p++) {
        $ids = $crawler->fetchListIdxnos($p);
        if (!$bg) echo "── page {$p}: " . count($ids) . "건 ──\n";
        foreach ($ids as $idxno) {
            if (isset($done[$idxno])) { $already++; continue; }
            try {
                $html = $crawler->fetchArticle($idxno);
                if (!$html) {
                    $errors++;
                    if (!$bg) echo "  ⚠️  #{$idxno} HTML 요청 실패(재시도 대상)\n";
                } else {
                    $r = ArdentNews::parseArticle($html, $idxno);
                    if ($r['ok']) {
                        $res = $place->ingest($r['item']);
                        $rec->execute([$idxno, 'done', $res['place_id'], null]);
                        $ingested++;
                        if (!$bg) { $pl = $r['item']['place']; echo sprintf("  ✅ #%d  %s | %s\n", $idxno, $pl['name'] ?? '?', $pl['address'] ?? '(주소없음)'); }
                    } else {
                        $rec->execute([$idxno, 'skip', null, mb_substr($r['reason'], 0, 250)]);
                        $skipped++;
                        if (!$bg) echo sprintf("  ⏭️  #%d  %s\n", $idxno, $r['reason']);
                    }
                    $done[$idxno] = true;
                }
            } catch (Throwable $e) {
                $errors++;
                if (!$bg) echo "  ❌ #{$idxno} 오류: " . htmlspecialchars($e->getMessage()) . "\n";
            }

            if (microtime(true) - $START_TS > $TIME_BUDGET) {
                $stateSet('last_page', (string)$p);   // 이 페이지 중간 → 다음 실행 이 페이지부터
                $budgetHit = true;
                if (!$bg) echo "  …예산 도달, 중단 (다음 실행 page {$p}부터)\n";
                break 2;
            }
            sleep(1); // 매너: 요청 간 1초
        }
        // 페이지 완료 → 다음 페이지(324 끝이면 1로 순환)
        $stateSet('last_page', (string)($p < $LAST_PAGE ? $p + 1 : 1));
    }
    if (!$budgetHit) $stateSet('last_page', '1'); // 시작~324 완주 → 처음으로 순환

    $nextPage = $stateGet('last_page', '1');
    $stateSet('last_run', date('Y-m-d H:i') . " +{$ingested}/skip{$skipped}/err{$errors} next-p{$nextPage}");

    if (!$bg) {
        $totalDone = (int)$pdo->query("SELECT COUNT(*) FROM tbl_ardent_crawl WHERE status='done'")->fetchColumn();
        $pending   = (int)$pdo->query("SELECT COUNT(*) FROM place WHERE geocode_status='pending' AND address IS NOT NULL AND address<>''")->fetchColumn();
        echo str_repeat('─', 60) . "\n";
        echo "이번 실행 — 적재 {$ingested} / skip {$skipped} / 이미처리 {$already} / 오류 {$errors}\n";
        echo "누적 done {$totalDone} / 좌표화대기 {$pending} / 다음시작 page " . $stateGet('last_page', '1') . "\n";
        echo "→ 좌표: /cron_place_geocode.php?key=econ-place-geo\n";
        echo "</pre>";
    }
    exit;
}

echo "모드를 지정하세요:\n";
echo "  ?key={$TOKEN}&status=1            (진행 상태 모니터링)\n";
echo "  ?key={$TOKEN}&test=15288         (단일 기사 검증, 저장X)\n";
echo "  ?key={$TOKEN}&list=1             (목록 idxno 추출, 저장X)\n";
echo "  ?key={$TOKEN}&dry=1&pages=1      (파싱 덤프, 저장X)\n";
echo "  ?key={$TOKEN}&run=1             (수동 적재, 22초·이어받기)\n";
echo "  ?key={$TOKEN}&run=1&bg=1        (크론용: 즉시응답+백그라운드 장시간)\n";
echo "  ?key={$TOKEN}&reset_crawl=1&purge=1 (이름로직 변경 후 중복정리 재크롤용)\n";
echo "</pre>";
?>
