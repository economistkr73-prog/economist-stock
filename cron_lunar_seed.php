<?php
/**
 * cron_lunar_seed.php — 음양력 변환 테이블(tbl_lunar_solar) 적재 스크립트
 *
 * 한국천문연구원 음양력정보 API(getLunCalInfo)로 양력→음력 매핑을 일자별로 저장한다.
 * 이미 저장된 날짜는 건너뛰므로 여러 번 나눠 실행(이어받기) 가능하다.
 *
 * 사용 예:
 *   /cron_lunar_seed.php?key=econ-lunar-seed
 *   /cron_lunar_seed.php?key=econ-lunar-seed&limit=3000
 *   /cron_lunar_seed.php?key=econ-lunar-seed&from=1990-01-01&to=2050-12-31
 *
 * 전체(1990~2050, 약 22,300일) 적재 완료될 때까지 "완료" 표시가 나올 때까지 반복 실행.
 */

require_once "./env/cnt.inc";
require_once "./env/kakao.inc"; // HOLIDAY_API_KEY (data.go.kr 인증키, 음양력 API도 동일 키 사용)

header('Content-Type: text/html; charset=utf-8');

// 간단한 실행 토큰 (오남용 방지)
$TOKEN = 'econ-lunar-seed';
if (($_GET['key'] ?? '') !== $TOKEN) {
    http_response_code(403);
    exit('forbidden: ?key=' . $TOKEN . ' 필요');
}

// ── 디버그: 단건 호출 원시 응답 확인 (?debug=1) ──────────────
if (($_GET['debug'] ?? '') === '1') {
    $apiKey   = defined('HOLIDAY_API_KEY') ? HOLIDAY_API_KEY : '';
    $endpoint = 'https://apis.data.go.kr/B090041/openapi/service/LrsrCldInfoService/getLunCalInfo';
    $params = http_build_query(['solYear'=>'2024','solMonth'=>'09','solDay'=>'17','_type'=>'json']);
    $url = sprintf('%s?ServiceKey=%s&%s', $endpoint, $apiKey, $params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10, CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_FOLLOWLOCATION=>true]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    header('Content-Type: text/plain; charset=utf-8');
    echo "HTTP CODE : {$code}\n";
    echo "CURL ERR  : {$err}\n";
    echo "KEY(앞12) : " . substr($apiKey,0,12) . "...(" . strlen($apiKey) . "자)\n";
    echo "URL       : " . preg_replace('/ServiceKey=[^&]+/','ServiceKey=***',$url) . "\n";
    echo "─── RAW RESPONSE ───\n";
    echo $raw === false ? "(false)" : $raw;
    exit;
}

@set_time_limit(0);          // 실행시간 제한 해제 시도
$TIME_BUDGET = 20;           // 1회 요청당 최대 처리 시간(초) — 프록시 타임아웃 회피
$START_TS    = microtime(true);

$from  = $_GET['from'] ?? '1990-01-01';
$to    = $_GET['to']   ?? '2050-12-31';
$limit = max(1, min(20000, (int)($_GET['limit'] ?? 9000))); // 일일 트래픽 한도(1만) 고려

$apiKey   = defined('HOLIDAY_API_KEY') ? HOLIDAY_API_KEY : '';
$endpoint = 'https://apis.data.go.kr/B090041/openapi/service/LrsrCldInfoService/getLunCalInfo';

// ── 테이블 보장 ──────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS tbl_lunar_solar (
        solar_date DATE        NOT NULL PRIMARY KEY,
        lun_year   SMALLINT     NOT NULL,
        lun_month  TINYINT      NOT NULL,
        lun_day    TINYINT      NOT NULL,
        is_leap    TINYINT(1)   NOT NULL DEFAULT 0,
        KEY idx_lunar (lun_year, lun_month, lun_day, is_leap),
        KEY idx_md    (lun_month, lun_day)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── 이미 저장된 날짜 집합 (범위 내) ──────────────────────────
$exist = [];
$st = $pdo->prepare("SELECT solar_date FROM tbl_lunar_solar WHERE solar_date BETWEEN ? AND ?");
$st->execute([$from, $to]);
foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) $exist[$d] = true;

// ── getLunCalInfo 호출 ───────────────────────────────────────
function fetchLunar(string $endpoint, string $apiKey, int $y, int $m, int $d): ?array {
    $params = http_build_query([
        'solYear'  => sprintf('%04d', $y),
        'solMonth' => sprintf('%02d', $m),
        'solDay'   => sprintf('%02d', $d),
        '_type'    => 'json',
    ]);
    $url = sprintf('%s?ServiceKey=%s&%s', $endpoint, $apiKey, $params);

    $raw = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $code !== 200) return null;
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) return null;
    }

    // JSON 우선 시도
    $json = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $item = $json['response']['body']['items']['item'] ?? null;
        if (empty($item)) return null;
        if (!isset($item['lunYear'])) $item = $item[0] ?? null;
        if (!$item) return null;
        return [
            'lun_year'  => (int)$item['lunYear'],
            'lun_month' => (int)$item['lunMonth'],
            'lun_day'   => (int)$item['lunDay'],
            'is_leap'   => ((string)($item['lunLeapmonth'] ?? '평') === '윤') ? 1 : 0,
        ];
    }

    // XML 응답 파싱 (이 API의 기본 포맷)
    $xml = @simplexml_load_string($raw);
    if ($xml === false) return null;
    $item = $xml->body->items->item ?? null;
    if (!$item) return null;
    if (isset($item[0]) && !isset($item->lunYear)) $item = $item[0];
    if (!isset($item->lunYear)) return null;
    return [
        'lun_year'  => (int)$item->lunYear,
        'lun_month' => (int)$item->lunMonth,
        'lun_day'   => (int)$item->lunDay,
        'is_leap'   => ((string)$item->lunLeapmonth === '윤') ? 1 : 0,
    ];
}

// ── 메인 루프 ────────────────────────────────────────────────
$ins = $pdo->prepare("
    INSERT INTO tbl_lunar_solar (solar_date, lun_year, lun_month, lun_day, is_leap)
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE lun_year=VALUES(lun_year), lun_month=VALUES(lun_month),
                            lun_day=VALUES(lun_day), is_leap=VALUES(is_leap)
");

$cur     = new DateTime($from);
$end     = new DateTime($to);
$done    = 0;   // 이번 실행에서 적재한 건수
$fail    = 0;
$lastDay = '';

while ($cur <= $end && $done < $limit) {
    // 시간 예산 초과 시 이번 요청 종료(이어받기로 계속)
    if ((microtime(true) - $START_TS) > $TIME_BUDGET) break;
    $ds = $cur->format('Y-m-d');
    if (!isset($exist[$ds])) {
        $r = fetchLunar($endpoint, $apiKey, (int)$cur->format('Y'), (int)$cur->format('m'), (int)$cur->format('d'));
        if ($r) {
            $ins->execute([$ds, $r['lun_year'], $r['lun_month'], $r['lun_day'], $r['is_leap']]);
            $done++;
            $lastDay = $ds;
        } else {
            $fail++;
            if ($fail >= 20) break; // 연속 실패 많으면 중단(키 미승인/한도초과 가능)
        }
    }
    $cur->modify('+1 day');
}

// ── 진행률 ───────────────────────────────────────────────────
$total = (int)$pdo->query("SELECT COUNT(*) FROM tbl_lunar_solar WHERE solar_date BETWEEN " .
            $pdo->quote($from) . " AND " . $pdo->quote($to))->fetchColumn();
$need  = (new DateTime($from))->diff(new DateTime($to))->days + 1;
$pct   = $need > 0 ? round($total / $need * 100, 1) : 0;
$remain = $need - $total;

$auto    = ($_GET['auto'] ?? '1') !== '0';   // 자동 이어실행 (기본 ON, &auto=0으로 끔)
$contUrl = "?key={$TOKEN}&from={$from}&to={$to}&limit={$limit}";

// 완료 전이고 실패 누적이 적으면 2초 뒤 자동 이어실행
if ($auto && $remain > 0 && $fail < 20) {
    echo "<meta http-equiv='refresh' content='2;url=" . htmlspecialchars($contUrl) . "&auto=1'>";
}

echo "<pre style='font-size:14px;line-height:1.6'>";
echo "음양력 시드 적재 결과\n";
echo "──────────────────────────\n";
echo "범위        : {$from} ~ {$to} (총 {$need}일)\n";
echo "이번 적재   : {$done}건" . ($lastDay ? " (마지막: {$lastDay})" : "") . "\n";
echo "실패        : {$fail}건\n";
echo "누적 저장   : {$total}일 / {$need}일  ({$pct}%)\n";
echo "남은 일수   : {$remain}일\n";
echo "──────────────────────────\n";
if ($remain <= 0) {
    echo "✅ 적재 완료! 더 실행할 필요 없음.\n";
} elseif ($fail >= 20) {
    echo "⏸ 중단됨 — 실패 20건 누적.\n";
    echo "   원인: ① 일일 트래픽 한도(1만) 초과 → 내일 다시 실행하면 이어짐\n";
    echo "         ② 인증키가 음양력정보(LrsrCldInfoService)에 미승인\n";
    echo "   이어서 하려면 아래 링크 클릭.\n";
} elseif ($auto) {
    echo "▶ 자동 이어실행 중… (2초 뒤 새로고침) 이 창을 열어두세요.\n";
} else {
    echo "▶ 아직 남음. 아래 링크로 이어서 실행.\n";
}
echo "</pre>";
echo "<p><a href='" . htmlspecialchars($contUrl) . "&auto=1'>↻ 이어서 실행(자동)</a> &nbsp;|&nbsp; ";
echo "<a href='" . htmlspecialchars($contUrl) . "&auto=0'>한 번만 실행</a></p>";
?>
