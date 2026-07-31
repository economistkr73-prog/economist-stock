<?php
// cron_keyword_collector.php
// 호출 예시:
//   mode=stock_etf_news  → 시세 갱신 + 종목 키워드 수집 (기존 동작 · 평일 9회 · 약 14초)
//   mode=news            → 네이버 금융 섹션 뉴스 키워드 수집 (매일 10회 · 약 5초)
//   mode=etf_update      → ETF 편입종목 갱신 (★ 30초를 넘는다 → bg=1 필수)
//   mode 없음            → stock_etf_news 와 동일 (하위 호환)
//
// bg / 로그 (자세한 배경은 env/cronbg.inc 주석)
//   ?ssk=…&mode=etf_update&bg=1    ← 크론용. 즉시 성공 응답 후 뒤에서 완주
//   ?ssk=…&mode=etf_update&log=1   ← 지난 bg 실행이 무엇을 했는지
//   ?ssk=…&mode=etf_update&sec=900 ← 이번 실행 시간 예산(초). 기본 3600
//   bg 를 빼면 화면으로 그대로 본다(연결이 끊기면 종료 — 좀비 방지).
//
//   ※ mode=news·stock_etf_news 는 30초 안에 끝나므로 bg 가 필요 없다. 다만
//     stock_etf_news 는 실측 14초라 여유가 크지 않다 — 네이버가 느려지면 &bg=1 을 붙인다.

$secret_key = "mysn1973!";

if (!isset($_GET['ssk']) || $_GET['ssk'] !== $secret_key) {
    http_response_code(403);
    die("접근 권한이 없습니다.");
}

require_once $_SERVER['DOCUMENT_ROOT'] . "/env/cnt.inc";
require_once $_SERVER['DOCUMENT_ROOT'] . "/env/e.fnc";
// env/ 는 .gitignore 대상이라 git 으로 따라오지 않는다 — 배포 누락을 알아볼 수 있게 가드
if (!is_file($_SERVER['DOCUMENT_ROOT'] . "/env/cronbg.inc")) { http_response_code(500); die("env/cronbg.inc 없음 — env/ 는 git 제외라 수동 배포가 필요합니다.\n"); }
require_once $_SERVER['DOCUMENT_ROOT'] . "/env/cronbg.inc";
if (file_exists($_SERVER['DOCUMENT_ROOT'] . "/env/kakao.inc")) require_once $_SERVER['DOCUMENT_ROOT'] . "/env/kakao.inc"; // 카카오/Pushover 알림(선택)

error_reporting(E_ALL & ~E_NOTICE);
ini_set("display_errors", 1);
ini_set("allow_url_fopen", 1);
set_time_limit(0);

$mode = $_GET['mode'] ?? 'stock_etf_news';

// 모드별로 로그를 분리한다 — 하루 10회 도는 news 가 etf_update 로그를 덮어쓰면 안 된다
define('KWC_LOG', sys_get_temp_dir() . '/keyword_collector_' . preg_replace('/[^a-z_]/', '', $mode) . '.log');

if (!empty($_GET['log'])) cron_bg_show_log(KWC_LOG, (int)($_GET['n'] ?? 60));

/* 시간 예산 기본 3600초(1시간).
 * etf_update 는 ETF 1개당 sleep(2) 라 대상 수에 비례해 길어진다. 지금까지는 무제한으로
 * 돌려 완주했으므로 <b>현행 동작을 바꾸지 않는 선</b>에서 폭주만 막는 값으로 뒀다.
 * 로그의 "총 N초" 를 보고 실제 소요를 알면 &sec= 로 줄인다. */
cron_bg_begin(KWC_LOG, max(0, (int)($_GET['sec'] ?? 3600)));

/**
 * ETF 업데이트 결과를 카카오톡(나에게)으로 전송
 * @param int   $updatedCount 업데이트된 ETF 수
 * @param array $newEtfList   신규 ETF "이름(코드)" 문자열 배열
 */
function kakao_etf_notify(int $updatedCount, array $newEtfList): void
{
    if (!class_exists('Notify')) return; // kakao.inc 미설치 시 조용히 패스

    $msg = "[성공] ETF {$updatedCount}개 편입종목 업데이트 완료.\n\n";

    if (empty($newEtfList)) {
        $msg .= "신규 ETF는 없습니다.";
    } else {
        $total = count($newEtfList);
        $msg  .= "신규 ETF는 다음과 같습니다.\n";
        $shown = [];
        foreach ($newEtfList as $item) {
            // 카카오 텍스트 200자 제한 → 넘으면 자르고 "...외 N개" 표기
            $candidate = $msg . "- " . $item . "\n";
            if (mb_strlen($candidate) > 180) {
                $remain = $total - count($shown);
                $msg   .= "…외 {$remain}개";
                break;
            }
            $msg .= "- {$item}\n";
            $shown[] = $item;
        }
    }

    Notify::send($msg, "https://economist.kr/etf_stock.php");
}

/* ★ 여기 있던 「Content-Length + Connection: close」 블록을 없앴다 (2026-07-30).
 *   이 서버는 SAPI 가 apache2handler 라 그 패턴이 <b>듣지 않는다</b> — 응답을 끝까지
 *   기다리므로 etf_update 는 매일 "Failed (timeout)" 이었다. 이제 위쪽 cron_bg_begin()
 *   이 &bg=1 일 때 자기 자신에게 비동기 요청을 던진다. 자세한 내용은 env/cronbg.inc. */

// ============================================================
// mode=stock_etf_news  시세 + 종목 키워드 수집 (기존 기능)
// ============================================================
if ($mode === 'stock_etf_news' || $mode === '') {

    try {
        $api = new NaverFinanceAPI();

        // 1. 최신 주식/ETF 시세 갱신
        $update_success = $api->get_real_time_data_from_naver($pdo);
        if (!$update_success) {
            echo "⚠️ 시세 갱신 실패. 기존 데이터로 분석을 계속합니다.\n";
        }

        $today_date   = date('Y-m-d');
        $current_time = date('H:i:00');

        // 2. 대형주 급등 TOP 9
        $rising_stocks = $api->get_analysis_report($pdo);
        if (empty($rising_stocks)) {
            die("수집할 급등 종목이 없습니다.\n");
        }

        $all_news_titles   = [];
        $source_stocks_data = [];
        $count = 0;

        foreach ($rising_stocks as $stock) {
            if ($count >= 9) break;
            $source_stocks_data[] = [
                'code' => $stock['stock_code'],
                'name' => $stock['stock_name'],
                'rate' => $stock['stock_rate'],
            ];
            $news_data = $api->getNaverFinanceNews($stock['stock_code'], 10);
            foreach ($news_data as $news) {
                $all_news_titles[] = $news['title'];
            }
            $count++;
        }

        // 3. 키워드 TOP 20 추출
        $hot_keywords = $api->get_market_keywords($all_news_titles, 20);

        // 4. DB 저장
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "INSERT INTO market_trend_snapshots (check_date, check_time, source_stocks) VALUES (?, ?, ?)"
        );
        $stmt->execute([$today_date, $current_time, json_encode($source_stocks_data, JSON_UNESCAPED_UNICODE)]);
        $snapshot_id = $pdo->lastInsertId();

        if (!empty($hot_keywords)) {
            $stmt_kw = $pdo->prepare(
                "INSERT INTO market_trend_keywords (snapshot_id, ranking, keyword, mention_count) VALUES (?, ?, ?, ?)"
            );
            $rank = 1;
            foreach ($hot_keywords as $keyword => $mention_count) {
                $stmt_kw->execute([$snapshot_id, $rank++, $keyword, $mention_count]);
            }
        }

        $pdo->commit();
        echo "[성공] {$today_date} {$current_time} - 종목 키워드 TOP 20 저장 완료.\n";

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo "[실패] " . $e->getMessage() . "\n";
    }

// ============================================================
// mode=news  네이버 금융 섹션 뉴스 키워드 수집
// ============================================================
} elseif ($mode === 'news') {

    try {
        $api = new NaverFinanceAPI();

        // DB 테이블 자동 생성
        // last_article_time: 이번 배치에서 처리한 기사 중 가장 최신 기사 시간
        // → 다음 실행 때 이 시간 이후 기사만 필터링하여 중복 방지
        $pdo->exec("CREATE TABLE IF NOT EXISTS news_trend_snapshots (
            id                INT AUTO_INCREMENT PRIMARY KEY,
            snap_date         DATE        NOT NULL,
            snap_time         TIME        NOT NULL,
            article_count     INT         DEFAULT 0,
            last_article_time DATETIME    NULL COMMENT '이번 배치 최신 기사 시간(중복 방지 기준)',
            created_at        TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_date (snap_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 기존 테이블에 컬럼이 없으면 추가 (운영 중 마이그레이션)
        $pdo->exec("ALTER TABLE news_trend_snapshots
                    ADD COLUMN IF NOT EXISTS last_article_time DATETIME NULL
                    COMMENT '이번 배치 최신 기사 시간(중복 방지 기준)'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS news_trend_keywords (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            snapshot_id   INT         NOT NULL,
            ranking       INT         NOT NULL,
            keyword       VARCHAR(100) NOT NULL,
            mention_count INT         DEFAULT 0,
            INDEX idx_snapshot (snapshot_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $today_date  = date('Y-m-d');
        $naver_date  = date('Ymd');   // 네이버 URL용 YYYYMMDD

        // ── 1. 오늘 마지막 실행의 기사 시간 조회 ──────────────────────────
        // 첫 실행이면 NULL → 전체 기사 처리
        $stmt_last = $pdo->prepare(
            "SELECT last_article_time
             FROM news_trend_snapshots
             WHERE snap_date = ? AND last_article_time IS NOT NULL
             ORDER BY id DESC LIMIT 1"
        );
        $stmt_last->execute([$today_date]);
        $last_article_time = $stmt_last->fetchColumn(); // 예: "2026-06-02 09:28" 또는 false

        // ── 2. 뉴스 수집 ($since 지정 시 해당 시간 이하 기사를 만나면 즉시 중단) ──
        // 첫 실행(last_article_time=false)이면 전체 수집, 이후엔 신규 기사만 수집
        $since    = $last_article_time ?: null;
        $new_news = $api->getSectionNews($naver_date, true, 20, $since);

        $cnt_new = count($new_news);

        if ($cnt_new === 0) {
            echo "[스킵] {$today_date} - '{$last_article_time}' 이후 신규 기사 없음. 종료.\n";
            exit;
        }

        // ── 4. 신규 기사 중 가장 최신 기사 시간 추출 ─────────────────────
        // 다음 실행의 중복 방지 기준점으로 저장
        $article_dates = array_filter(array_column($new_news, 'date')); // 빈 값 제거
        $max_article_time = !empty($article_dates) ? max($article_dates) : null;

        // ── 5. 신규 기사만 키워드 분석 (DB 제외단어 반영) ────────────────
        $db_stopwords = [];
        try {
            $db_stopwords = $pdo->query(
                "SELECT keyword FROM news_stopwords"
            )->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {} // 테이블 없으면 무시

        $titles   = array_column($new_news, 'title');
        $keywords = $api->get_market_keywords($titles, 15, $db_stopwords);

        // 2회 미만 키워드 제외
        $keywords = array_filter($keywords, fn($mc) => $mc >= 2);

        // ── 6. DB 저장 (키워드 없으면 스냅샷도 저장 안 함) ───────────────
        if (empty($keywords)) {
            echo "[스킵] {$today_date} - 신규 {$cnt_new}건 처리, 유효 키워드 없음(2회 미만). 종료.\n";
            exit;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO news_trend_snapshots
             (snap_date, snap_time, article_count, last_article_time)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$today_date, date('H:i:s'), $cnt_new, $max_article_time]);
        $snap_id = $pdo->lastInsertId();

        if (!empty($keywords)) {
            $stmt_kw = $pdo->prepare(
                "INSERT INTO news_trend_keywords (snapshot_id, ranking, keyword, mention_count)
                 VALUES (?, ?, ?, ?)"
            );
            $rank = 1;
            foreach ($keywords as $kw => $mc) {
                $stmt_kw->execute([$snap_id, $rank++, $kw, $mc]);
            }
        }

        $since_label = $since ?? '처음';
        echo "[성공] {$today_date} - 신규 {$cnt_new}건 처리, "
           . "키워드 " . count($keywords) . "개 저장 (기준시간: {$since_label} → {$max_article_time})\n";

        // 카카오톡 알림
        if (class_exists('Notify')) {
            $kw_slice = array_slice($keywords, 0, 10, true);
            $kw_str   = implode("\n", array_map(fn($k, $v) => "#{$k}({$v})", array_keys($kw_slice), $kw_slice));
            $msg = "[뉴스 키워드 업데이트]\n"
                 . "일시: {$today_date} " . date('H:i') . "\n"
                 . "기사 {$cnt_new}건 → 키워드 " . count($keywords) . "개\n\n"
                 . $kw_str . "\n\n"
                 . "http://economist.kr/analysis_model.php?mode=daily&date={$today_date}";
            Notify::send($msg);
        }

    } catch (Exception $e) {
        echo "[실패] " . $e->getMessage() . "\n";
    }

// ============================================================
// mode=etf_update  ETF 편입종목 업데이트 (etf_auto_update.php 대체)
// ============================================================
} elseif ($mode === 'etf_update') {

    try {
        $etfRepo = new StockRepository($pdo);

        // 신규 ETF 판별을 위해 업데이트 직전의 ETF 코드 목록을 기록
        $beforeCodes = array_flip(
            $pdo->query("SELECT etf_code FROM all_etf_info")->fetchAll(PDO::FETCH_COLUMN)
        );

        // 0) 네이버에서 신규 국내 ETF(etfTabCode 1,2,3) 자동 등록
        //    → 새로 등록된 ETF(holdings_count=0)도 아래 루프에서 편입종목까지 채워짐
        $api = new NaverFinanceAPI();
        $api->discover_new_domestic_etfs($pdo);

        // discover 후 새로 생긴 ETF(이름) 목록 추출
        $newEtfs = $pdo->query(
            "SELECT etf_code, etf_name FROM all_etf_info ORDER BY etf_code"
        )->fetchAll(PDO::FETCH_ASSOC);
        $newEtfList = [];
        foreach ($newEtfs as $row) {
            if (!isset($beforeCodes[$row['etf_code']])) {
                $newEtfList[] = "{$row['etf_name']}({$row['etf_code']})";
            }
        }

        /* ★ 정렬을 "오래 안 받은 것부터" 로 바꿨다 (한 번도 안 받음 → 가장 낡음 → 코드순).
         *   etf_code ASC 로 두면 시간예산에 걸려 중간에 끊길 때 <b>뒷쪽 코드가 영원히 안 받아진다</b>
         *   — ETF 1개당 sleep(2) 라 대상이 많으면 매일 같은 자리에서 끊기기 때문이다.
         *   낡은 것부터 받으면 끊겨도 다음 실행이 그 뒤를 이어받아 전체가 돌아간다. */
        $sql = "SELECT i.etf_code, MAX(h.uDate) AS last_u
                FROM all_etf_info i
                LEFT JOIN all_etf_holdings_info h ON i.etf_code = h.etf_code
                WHERE i.skip_update = 0
                GROUP BY i.etf_code
                HAVING MAX(h.uDate) IS NULL OR MAX(h.uDate) < CURDATE()
                ORDER BY (MAX(h.uDate) IS NULL) DESC, MAX(h.uDate) ASC, i.etf_code ASC";

        $etfs = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        if (empty($etfs)) {
            echo "[완료] 오늘 업데이트할 ETF가 없습니다.\n";
            kakao_etf_notify(0, $newEtfList);
        } else {
            $total = count($etfs);
            $updatedCount = 0; $budgetHit = false;
            cron_bg_log("갱신 대상 ETF {$total}개 (ETF당 sleep 2초 — 최소 " . ($total * 2) . "초)");

            foreach ($etfs as $i => $etf) {
                $etfRepo->updateEtfHoldings($etf['etf_code']);
                $updatedCount++;

                // 예산을 넘겼으면 남은 것은 다음 실행에 넘긴다 (낡은 것부터 받으므로 밀리지 않는다)
                if (cron_bg_over()) { $budgetHit = true; break; }

                if ($updatedCount % 25 === 0) cron_bg_log(sprintf('  … %d/%d', $updatedCount, $total));
                sleep(2);
            }

            data_upTime('auto_etf_naver_update', 'update', $pdo);

            if ($budgetHit) {
                $left = $total - $updatedCount;
                cron_bg_log("시간 예산 도달 — {$updatedCount}/{$total} 처리, 남은 {$left}개는 다음 실행에서");
                echo "[부분성공] ETF {$updatedCount}/{$total}개 업데이트 (예산 도달 — 남은 {$left}개는 다음 실행).\n";
            } else {
                cron_bg_log("완료 — ETF {$updatedCount}개 편입종목 갱신");
                echo "[성공] ETF {$updatedCount}개 편입종목 업데이트 완료.\n";
            }
            kakao_etf_notify($updatedCount, $newEtfList);
        }

    } catch (Exception $e) {
        echo "[실패] " . $e->getMessage() . "\n";
        if (class_exists('Notify')) {
            Notify::send("⚠️ ETF 편입종목 업데이트 실패\n" . $e->getMessage());
        }
    }

} else {
    echo "[오류] 알 수 없는 mode: {$mode}\n";
}
?>