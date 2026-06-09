<?php
// cron_keyword_collector.php
// 호출 예시:
//   mode=stock_etf_news  → 시세 갱신 + 종목 키워드 수집 (기존 동작)
//   mode=news            → 네이버 금융 섹션 뉴스 키워드 수집
//   mode 없음            → stock_etf_news 와 동일 (하위 호환)

$secret_key = "mysn1973!";

if (!isset($_GET['ssk']) || $_GET['ssk'] !== $secret_key) {
    die("접근 권한이 없습니다.");
}

require_once "env/cnt.inc";
require_once "env/e.fnc";
if (file_exists("env/kakao.inc")) require_once "env/kakao.inc"; // 카카오 알림(선택)

error_reporting(E_ALL & ~E_NOTICE);
ini_set("display_errors", 1);
ini_set("allow_url_fopen", 1);
set_time_limit(0);
ignore_user_abort(true);

$mode = $_GET['mode'] ?? 'stock_etf_news';

/**
 * ETF 업데이트 결과를 카카오톡(나에게)으로 전송
 * @param int   $updatedCount 업데이트된 ETF 수
 * @param array $newEtfList   신규 ETF "이름(코드)" 문자열 배열
 */
function kakao_etf_notify(int $updatedCount, array $newEtfList): void
{
    if (!class_exists('KakaoNotify')) return; // kakao.inc 미설치 시 조용히 패스

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

    KakaoNotify::send($msg, "https://economist.kr/etf_stock.php");
}

// 즉시 200 OK 전송 후 연결 종료 (cron-job.org 타임아웃 회피)
ignore_user_abort(true);
set_time_limit(0);
ob_start();
echo "OK";
$size = ob_get_length();
header("Content-Length: $size");
header("Connection: close");
ob_end_flush();
flush();

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
        if (class_exists('KakaoNotify')) {
            $kw_slice = array_slice($keywords, 0, 10, true);
            $kw_str   = implode("\n", array_map(fn($k, $v) => "#{$k}({$v})", array_keys($kw_slice), $kw_slice));
            $msg = "[뉴스 키워드 업데이트]\n"
                 . "일시: {$today_date} " . date('H:i') . "\n"
                 . "기사 {$cnt_new}건 → 키워드 " . count($keywords) . "개\n\n"
                 . $kw_str . "\n\n"
                 . "http://economist.kr/analysis_model.php?mode=news&date={$today_date}";
            KakaoNotify::send($msg);
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

        $sql = "SELECT i.etf_code
                FROM all_etf_info i
                LEFT JOIN all_etf_holdings_info h ON i.etf_code = h.etf_code
                WHERE i.skip_update = 0
                GROUP BY i.etf_code
                HAVING MAX(h.uDate) IS NULL OR MAX(h.uDate) < CURDATE()
                ORDER BY i.etf_code ASC";

        $etfs = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        if (empty($etfs)) {
            echo "[완료] 오늘 업데이트할 ETF가 없습니다.\n";
            kakao_etf_notify(0, $newEtfList);
        } else {
            $updatedCount = 0;
            foreach ($etfs as $etf) {
                $etfRepo->updateEtfHoldings($etf['etf_code']);
                $updatedCount++;
                sleep(2);
            }
            data_upTime('auto_etf_naver_update', 'update', $pdo);
            echo "[성공] ETF {$updatedCount}개 편입종목 업데이트 완료.\n";
            kakao_etf_notify($updatedCount, $newEtfList);
        }

    } catch (Exception $e) {
        echo "[실패] " . $e->getMessage() . "\n";
        if (class_exists('KakaoNotify')) {
            KakaoNotify::send("⚠️ ETF 편입종목 업데이트 실패\n" . $e->getMessage());
        }
    }

} else {
    echo "[오류] 알 수 없는 mode: {$mode}\n";
}
?>