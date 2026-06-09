<?php
/**
 * NXT 전 종목 시세 테스트
 * http://localhost/test_nxt.php?market=ALL
 * http://localhost/test_nxt.php?market=KOSPI
 * http://localhost/test_nxt.php?market=KOSDAQ
 */
require_once "./env/cnt.inc";
require_once "./env/auth_fnc.php";
require_login();
header('Content-Type: text/html; charset=utf-8');

$market = strtoupper($_GET['market'] ?? 'ALL');

echo "<h2>NXT 시세 수집 테스트 — market: <b>{$market}</b></h2>";
echo "<p>
  <a href='?market=ALL'>ALL</a> |
  <a href='?market=KOSPI'>KOSPI</a> |
  <a href='?market=KOSDAQ'>KOSDAQ</a>
</p><hr>";

$api    = new NaverFinanceAPI();
$start  = microtime(true);
$result = $api->getAllNxtSise($market);
$elapsed = round(microtime(true) - $start, 2);

if (isset($result['error'])) {
    echo "<p style='color:red'>❌ 오류: " . htmlspecialchars($result['error']) . "</p>";
    exit;
}

$stocks = $result['success'];
echo "<p>✅ 수집 완료: <b>" . count($stocks) . "개</b> 종목 / 소요시간: {$elapsed}초</p>";

// 테이블 출력
echo "<table border='1' cellpadding='5' style='border-collapse:collapse; font-size:13px;'>";
echo "<tr style='background:#2c3e50;color:white;'>
        <th>순위</th><th>시장</th><th>종목코드</th><th>종목명</th>
        <th>현재가</th><th>전일비</th><th>등락률</th>
        <th>거래량</th><th>거래대금(백만)</th>
        <th>매수호가</th><th>매도호가</th><th>시가총액(억)</th>
      </tr>";

foreach ($stocks as $s) {
    $rate_color = $s['rate'] > 0 ? '#e1234a' : ($s['rate'] < 0 ? '#1261c4' : '#333');
    $change_txt = ($s['change'] > 0 ? '+' : '') . number_format($s['change']);
    echo "<tr>
      <td style='text-align:center'>{$s['rank']}</td>
      <td style='text-align:center'>{$s['market']}</td>
      <td style='text-align:center'>{$s['stock_code']}</td>
      <td>" . htmlspecialchars($s['stock_name']) . "</td>
      <td style='text-align:right'>" . number_format($s['price']) . "</td>
      <td style='text-align:right;color:{$rate_color}'>{$change_txt}</td>
      <td style='text-align:right;color:{$rate_color}'>{$s['rate']}%</td>
      <td style='text-align:right'>" . number_format($s['volume']) . "</td>
      <td style='text-align:right'>" . number_format($s['trading_val']) . "</td>
      <td style='text-align:right'>" . number_format($s['bid']) . "</td>
      <td style='text-align:right'>" . number_format($s['ask']) . "</td>
      <td style='text-align:right'>" . number_format($s['market_cap']) . "</td>
    </tr>";
}
echo "</table>";
