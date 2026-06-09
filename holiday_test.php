<?php
require_once "./env/cnt.inc";
require_once "./env/kakao.inc";

// 간단 접근 제한
if (($_GET['ssk']??'') !== 'mysn1973!') die('no');

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('m'));
$action = $_GET['action'] ?? 'test_api';

echo "<pre style='font-size:13px;'>";

// ── 1. DB 테이블 확인 ───────────────────────────────────────
echo "=== tbl_holiday 확인 ===\n";
try {
    $cnt = $pdo->query("SELECT COUNT(*) FROM tbl_holiday")->fetchColumn();
    echo "총 {$cnt}건\n";
    if ($cnt > 0) {
        $rows = $pdo->query("SELECT * FROM tbl_holiday ORDER BY holiday_date DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) echo "  {$r['holiday_date']} {$r['holiday_name']}\n";
    }
} catch (Exception $e) {
    echo "오류: " . $e->getMessage() . "\n";
}

// ── 2. API 키 확인 ──────────────────────────────────────────
echo "\n=== API 키 확인 ===\n";
echo defined('HOLIDAY_API_KEY')
    ? "키 정의됨: " . substr(HOLIDAY_API_KEY, 0, 20) . "...\n"
    : "❌ HOLIDAY_API_KEY 미정의\n";

// ── 3. curl/file_get_contents 가능 여부 ─────────────────────
echo "\n=== 네트워크 함수 확인 ===\n";
echo "curl_init: " . (function_exists('curl_init') ? '✅' : '❌') . "\n";
echo "allow_url_fopen: " . (ini_get('allow_url_fopen') ? '✅' : '❌') . "\n";

// ── 4. 실제 API 호출 테스트 ─────────────────────────────────
echo "\n=== API 호출 테스트 ({$year}년 {$month}월) ===\n";
if (defined('HOLIDAY_API_KEY')) {
    $params = http_build_query([
        'solYear'   => sprintf('%04d', $year),
        'solMonth'  => sprintf('%02d', $month),
        '_type'     => 'json',
        'numOfRows' => 20,
        'pageNo'    => 1,
    ]);
    $url = sprintf(
        'https://apis.data.go.kr/B090041/openapi/service/SpcdeInfoService/getRestDeInfo?ServiceKey=%s&%s',
        HOLIDAY_API_KEY,
        $params
    );
    echo "전체 URL (브라우저에서 직접 테스트):\n$url\n\n";

    $raw = null;
    if (function_exists('curl_init')) {
        echo "curl로 호출 중...\n";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        echo "HTTP 코드: {$code}\n";
        if ($err) echo "curl 오류: {$err}\n";
    } else {
        echo "file_get_contents로 호출 중...\n";
        $raw = @file_get_contents($url);
    }

    if ($raw) {
        echo "응답 길이: " . strlen($raw) . " bytes\n";
        echo "응답 앞 500자:\n";
        echo htmlspecialchars(substr($raw, 0, 500)) . "\n";

        // JSON 파싱 시도
        $json = json_decode($raw, true);
        if ($json) {
            $items = $json['response']['body']['items']['item'] ?? null;
            echo "\n파싱된 공휴일:\n";
            if (empty($items)) {
                echo "  (해당 월 공휴일 없음)\n";
            } else {
                if (isset($items['locdate'])) $items = [$items];
                foreach ($items as $it) {
                    echo "  {$it['locdate']} {$it['dateName']} (isHoliday:{$it['isHoliday']})\n";
                }
            }
        } else {
            echo "\nJSON 파싱 실패. XML인지 확인:\n";
            // XML 파싱
            $xml = @simplexml_load_string($raw);
            if ($xml) {
                $resultCode = (string)($xml->header->resultCode ?? $xml->cmmMsgHeader->returnReasonCode ?? '?');
                echo "결과코드: {$resultCode}\n";
                foreach ($xml->body->items->item ?? [] as $it) {
                    echo "  {$it->locdate} {$it->dateName}\n";
                }
            }
        }
    } else {
        echo "❌ 응답 없음\n";
    }
}

// ── 5. 직접 동기화 실행 ─────────────────────────────────────
if ($_GET['action'] ?? '' === 'sync') {
    echo "\n=== 직접 동기화 실행 ===\n";
    $hApi = new HolidayAPI($pdo);
    $r = $hApi->syncMonth($year, $month);
    echo "success:{$r['success']} fail:{$r['fail']} total:{$r['total']}\n";
    if ($r['error']) echo "오류: {$r['error']}\n";
}

echo "</pre>";
