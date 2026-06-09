<?php
// 네이버 ETF 데이터 구조 / etfTabCode 분포 확인
require_once "./env/cnt.inc";
require_once "./env/auth_fnc.php";
require_login();
header('Content-Type: text/html; charset=utf-8');

$api = new NaverFinanceAPI();
$res = $api->getAllNaverEtfs();

if (isset($res['error'])) {
    echo "<p style='color:red'>오류: " . htmlspecialchars($res['error']) . "</p>";
    exit;
}
$etfs = $res['success'];

echo "<h2>① 첫 번째 ETF 레코드 전체 필드</h2>";
echo "<table border='1' cellpadding='5' style='border-collapse:collapse;font-size:13px;'>";
foreach ($etfs[0] as $k => $v) {
    echo "<tr><td><b>{$k}</b></td><td>" . htmlspecialchars((string)$v) . "</td></tr>";
}
echo "</table>";
echo "<p>총 ETF 개수: <b>" . count($etfs) . "</b></p>";

// etfTabCode 분포 + 샘플 이름
echo "<h2>② etfTabCode 값별 분포 & 샘플</h2>";
$byTab = [];
foreach ($etfs as $e) {
    $tab = $e['etfTabCode'] ?? '(없음)';

	if($tab>3) continue;
    if (!isset($byTab[$tab])) $byTab[$tab] = ['count' => 0, 'names' => []];
    $byTab[$tab]['count']++;
    if (count($byTab[$tab]['names']) < 8) {
        $byTab[$tab]['names'][] = $e['itemname'] ?? $e['itemcode'] ?? '?';
    }
}
ksort($byTab);
echo "<table border='1' cellpadding='6' style='border-collapse:collapse;font-size:13px;'>";
echo "<tr><th>etfTabCode</th><th>개수</th><th>샘플 종목명 (최대 8개)</th></tr>";
foreach ($byTab as $tab => $info) {

    echo "<tr><td style='text-align:center;'><b>{$tab}</b></td><td style='text-align:center;'>{$info['count']}</td><td>"
       . htmlspecialchars(implode(', ', $info['names'])) . "</td></tr>";
}
echo "</table>";

// all_etf_info 에 없는(신규) ETF 개수 미리보기
echo "<h2>③ all_etf_info 에 아직 없는 ETF</h2>";
$existing = $pdo->query("SELECT etf_code FROM all_etf_info")->fetchAll(PDO::FETCH_COLUMN);
$existing = array_flip($existing);
$new = [];
foreach ($etfs as $e) {
    $code = $e['itemcode'] ?? '';

	if($e['etfTabCode']>3) continue;
    if ($code !== '' && !isset($existing[$code])) {
        $new[] = ($e['itemname'] ?? '?') . " ({$code}) tab=" . ($e['etfTabCode'] ?? '?');
    }
}
echo "<p>신규 ETF 개수: <b>" . count($new) . "</b></p>";
echo "<pre style='background:#f4f6f9;padding:10px;max-height:400px;overflow:auto;font-size:12px;'>"
   . htmlspecialchars(implode("\n", $new)) . "</pre>";