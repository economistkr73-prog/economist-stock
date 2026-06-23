<?php
/**
 * market/db.php — 스냅샷·리포트 저장소 (DB, A안: 날짜 + JSON 컬럼)
 *
 * 자격증명은 커밋되는 코드에 두지 않고 env/cnt.inc(gitignore)에서 읽어 직접 PDO 연결한다.
 * (cnt.inc 전체 require 시 따라오는 StockSummaryCache 초기화·DOCUMENT_ROOT 오토로더 의존을 피함)
 *
 * 테이블: market_snapshot(snap_date PK, data=스냅샷JSON, html=생성된 리포트, …)
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function mkt_pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $inc  = (string) @file_get_contents(__DIR__ . '/../env/cnt.inc');
    $host = preg_match('/host=([^;"\']+)/', $inc, $m) ? $m[1] : 'localhost';
    $db   = preg_match('/dbname=([^;"\']+)/', $inc, $m) ? $m[1] : 'economist73';
    // $pdo = new PDO($dsn, "user", "pass", [...]) 라인에서 자격증명 추출
    preg_match('/new\s+PDO\s*\([^,]+,\s*"([^"]+)"\s*,\s*"([^"]+)"/', $inc, $c);
    $user = $c[1] ?? 'economist73';
    $pass = $c[2] ?? '';

    $pdo = new PDO("mysql:host={$host};dbname={$db};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    mkt_db_init($pdo);
    return $pdo;
}

function mkt_db_init(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS market_snapshot (
            snap_date  DATE       NOT NULL PRIMARY KEY,
            data       LONGTEXT   NOT NULL,
            brief      LONGTEXT   NULL,
            html       LONGTEXT   NULL,
            html_at    DATETIME   NULL,
            created_at DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    // 기존 테이블에 brief 컬럼 보강 (MariaDB 10.6 IF NOT EXISTS)
    try { $pdo->exec("ALTER TABLE market_snapshot ADD COLUMN IF NOT EXISTS brief LONGTEXT NULL AFTER data"); } catch (\Throwable $e) {}
    $done = true;
}

/* ── 스냅샷 ── */

function mkt_save_snapshot(string $date, array $snap): void {
    $json = json_encode($snap, JSON_UNESCAPED_UNICODE);
    $st = mkt_pdo()->prepare(
        "INSERT INTO market_snapshot (snap_date, data) VALUES (:d, :j)
         ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = CURRENT_TIMESTAMP"
    );
    $st->execute([':d' => $date, ':j' => $json]);
}

function mkt_load_snap(string $date): ?array {
    $st = mkt_pdo()->prepare("SELECT data FROM market_snapshot WHERE snap_date = :d");
    $st->execute([':d' => $date]);
    $row = $st->fetch();
    if (!$row) return null;
    $arr = json_decode($row['data'], true);
    return is_array($arr) ? $arr : null;
}

function mkt_prev_date(string $today): ?string {
    $st = mkt_pdo()->prepare("SELECT MAX(snap_date) AS d FROM market_snapshot WHERE snap_date < :d");
    $st->execute([':d' => $today]);
    $row = $st->fetch();
    return $row && $row['d'] ? $row['d'] : null;
}

/** 가장 최근 스냅샷 거래일 (report 기본값) */
function mkt_latest_date(): ?string {
    $row = mkt_pdo()->query("SELECT MAX(snap_date) AS d FROM market_snapshot")->fetch();
    return $row && $row['d'] ? $row['d'] : null;
}

/* ── Claude 브리핑 캐시 (데이터 바뀔 때만 새로 생성, 템플릿 수정 땐 재사용) ── */

function mkt_save_brief(string $date, array $brief): void {
    $st = mkt_pdo()->prepare("UPDATE market_snapshot SET brief = :b WHERE snap_date = :d");
    $st->execute([':b' => json_encode($brief, JSON_UNESCAPED_UNICODE), ':d' => $date]);
}

function mkt_load_brief(string $date): ?array {
    $st = mkt_pdo()->prepare("SELECT brief FROM market_snapshot WHERE snap_date = :d");
    $st->execute([':d' => $date]);
    $row = $st->fetch();
    if (!$row || $row['brief'] === null || $row['brief'] === '') return null;
    $b = json_decode($row['brief'], true);
    return (is_array($b) && isset($b['head'])) ? $b : null;
}

/* ── 생성된 리포트 HTML (캐시) ── */

function mkt_save_html(string $date, string $html): void {
    $st = mkt_pdo()->prepare(
        "UPDATE market_snapshot SET html = :h, html_at = NOW() WHERE snap_date = :d"
    );
    $st->execute([':h' => $html, ':d' => $date]);
}

function mkt_load_html(string $date): ?string {
    $st = mkt_pdo()->prepare("SELECT html FROM market_snapshot WHERE snap_date = :d");
    $st->execute([':d' => $date]);
    $row = $st->fetch();
    return ($row && $row['html'] !== null && $row['html'] !== '') ? $row['html'] : null;
}
