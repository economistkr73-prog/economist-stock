<?php
/** market/diag.php — 한경 수집 진단 (서버에서 1회 확인용). /market/diag.php?key=econ-mkt-7x3k */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
header('Content-Type: text/plain; charset=utf-8');
if (($_GET['key'] ?? '') !== MKT_KEY) { http_response_code(403); exit('forbidden'); }

$url  = MKT_SRC['indices']['url'];

// 원시 curl 진단 (SSL/에러 원인 확인)
if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => MKT_UA, CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $raw = curl_exec($ch);
    echo "raw curl: errno=" . curl_errno($ch) . " err='" . curl_error($ch) . "' http=" . curl_getinfo($ch, CURLINFO_HTTP_CODE) . " len=" . (is_string($raw) ? strlen($raw) : 0) . "\n";
}

$body = mkt_http($url);
echo "URL: {$url}\n";
echo "fetch: " . ($body === null ? 'NULL (실패)' : 'OK') . "\n";
if ($body !== null) {
    echo "length: " . strlen($body) . "\n";
    echo "starts: " . bin2hex(substr($body, 0, 4)) . " (1f8b=gzip)\n";
    echo "has 'table-stock': " . (str_contains($body, 'table-stock') ? 'Y' : 'N') . "\n";
    echo "has 'data-value': "  . (str_contains($body, 'data-value') ? 'Y' : 'N') . "\n";
    $rows = mkt_parse_hankyung($body);
    echo "parsed rows: " . count($rows) . "\n";
    echo "sample names: " . implode(' | ', array_slice(array_keys($rows), 0, 8)) . "\n";
}

/* ── Claude 브리핑(데스크 코멘트) 진단 ── */
echo "\n--- Claude 브리핑 ---\n";
$inc = __DIR__ . '/../env/anthropic.inc';
echo "anthropic.inc: " . (is_file($inc) ? "있음 ({$inc})" : "없음 ({$inc})") . "\n";
if (is_file($inc)) require_once $inc;
$key = getenv('ANTHROPIC_API_KEY') ?: (defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '');
echo "key: " . ($key !== '' ? ('발견 len=' . strlen($key) . ' ' . substr($key, 0, 8) . '…') : '없음') . "\n";
if ($key !== '') {
    $payload = json_encode(['model' => MKT_MODEL, 'max_tokens' => 20,
        'messages' => [['role' => 'user', 'content' => '한 단어로만 답: 안녕']]], JSON_UNESCAPED_UNICODE);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01', 'content-type: application/json'],
    ]);
    $r = curl_exec($ch);
    echo "model: " . MKT_MODEL . "\n";
    echo "claude api: errno=" . curl_errno($ch) . " err='" . curl_error($ch) . "' http=" . curl_getinfo($ch, CURLINFO_HTTP_CODE) . "\n";
    echo "resp: " . substr((string) $r, 0, 400) . "\n";
}

/* ── 뉴스(국내 401 / 해외 403) 진단 ── */
echo "\n--- 뉴스 ---\n";
$nd = $_GET['nd'] ?? date('Ymd');   // 진단할 거래일(YYYYMMDD), 기본 오늘
echo "date={$nd}\n";
$dom = mkt_naver_news($nd, 8, '401');
echo "국내(401): " . count($dom) . "개\n";
$us  = mkt_naver_news($nd, 5, '403', MKT_NEWS_US_POS, true, 4);   // 크롤과 동일하게 4p 스캔
echo "뉴욕(403,titleOnly,4p): " . count($us) . "개\n";
foreach ($us as $a) echo "   [US] " . $a['title'] . "\n";

$html = mkt_http('https://finance.naver.com/news/news_list.naver?mode=LSS3D&section_id=101&section_id2=258&section_id3=403&date=' . $nd,
    ['euckr' => true, 'headers' => ['Referer: https://finance.naver.com/']]);
if ($html === null) { echo "403 fetch: NULL\n"; }
else {
    $xp = mkt_dom($html);
    $arts = $xp->query('//dd[contains(@class,"articleSubject")]//a');
    echo "403 page1 기사수: " . $arts->length . "\n";
    $i = 0;
    foreach ($arts as $a) {
        $t = trim($a->getAttribute('title') !== '' ? $a->getAttribute('title') : $a->textContent);
        $m = mkt_is_market_news($t, '', MKT_NEWS_US_POS, true);
        echo "  " . ($m ? '[O] ' : '[X] ') . $t . "\n";
        if (++$i >= 8) break;
    }
}
