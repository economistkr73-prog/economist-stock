<?php
/**
 * db_waste_setup.php — 공제조합 회원관리(전국 폐기물 업체) 테이블 생성/점검
 *
 * cafe24 DB는 localhost 라 로컬에서 직접 접속 불가 → 서버에 업로드 후 URL 로 1회 실행.
 *   /db_waste_setup.php?key=econ-waste-setup
 *
 * IF NOT EXISTS 라 재실행해도 안전(idempotent). WasteCompany::ensureTable() 과 동일 스키마.
 */

require_once "./env/cnt.inc";

header('Content-Type: text/html; charset=utf-8');

$TOKEN = 'econ-waste-setup';
if (PHP_SAPI !== 'cli' && ($_GET['key'] ?? '') !== $TOKEN) {
    http_response_code(403);
    exit('forbidden: ?key=' . $TOKEN . ' 필요');
}

$done = [];
$errors = [];

try {
    (new WasteCompany($pdo))->ensureTable();
    $done[] = '✅ waste_companies 테이블 생성/점검 완료';
} catch (Throwable $e) {
    $errors[] = '❌ ' . $e->getMessage();
}

// 현황 카운트
$rowCnt = 0;
try {
    $rowCnt = (int)$pdo->query("SELECT COUNT(*) FROM waste_companies")->fetchColumn();
} catch (Throwable $e) { /* 테이블 없으면 무시 */ }

echo "<h2>공제조합 DB 설정</h2><ul>";
foreach ($done as $d)   echo "<li>" . htmlspecialchars($d) . "</li>";
foreach ($errors as $e) echo "<li style='color:#c00'>" . htmlspecialchars($e) . "</li>";
echo "</ul>";
echo "<p>현재 waste_companies 행 수: <b>{$rowCnt}</b></p>";
echo "<p>다음 단계: <code>/mig_waste_import.php?key=econ-waste-import</code> 로 데이터 적재 → "
   . "<code>/cron_waste_geocode.php?key=econ-waste-geo</code> 로 좌표화.</p>";
?>
