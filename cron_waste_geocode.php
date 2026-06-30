<?php
/**
 * cron_waste_geocode.php — waste_companies 지오코딩 배치 (cron_place_geocode.php 패턴 복제)
 *
 *   1단계) 주소 있는 pending : 네이버 주소 지오코딩 → 실패 시 카카오 키워드(주소) 폴백
 *   2단계) 주소 없는 pending : 카카오 키워드(이름 + 지역) 검색 (지역 검증으로 오매칭 방지)
 * 성공 → lat/lng + 'ok', 실패 → 'failed'. ok/failed 는 다음 실행에서 제외(이어받기).
 *
 * 사용 (cafe24 cron 은 URL 호출):
 *   /cron_waste_geocode.php?key=econ-waste-geo
 *   /cron_waste_geocode.php?key=econ-waste-geo&limit=300
 *   /cron_waste_geocode.php?key=econ-waste-geo&reset_failed=1
 *
 * 남은 pending 이 0 이 될 때까지 반복 호출하면 전체 좌표화 완료.
 */

require_once "./env/cnt.inc";
if (file_exists("./env/maps.inc"))  require_once "./env/maps.inc";
if (file_exists("./env/kakao.inc")) require_once "./env/kakao.inc";

header('Content-Type: text/html; charset=utf-8');

$TOKEN = 'econ-waste-geo';
if (PHP_SAPI !== 'cli' && ($_GET['key'] ?? '') !== $TOKEN) {
    http_response_code(403);
    exit('forbidden: ?key=' . $TOKEN . ' 필요');
}

@set_time_limit(0);
$TIME_BUDGET = 25;     // 1회 최대 처리 시간(초)
$SLEEP_MS    = 120;    // API 호출 간격(ms)
$BATCH       = 50;
$START_TS    = microtime(true);

$limit = max(1, min(5000, (int)($_GET['limit'] ?? 300)));

$wc = new WasteCompany($pdo);
$wc->ensureTable();

$resetMsg = '';
if (!empty($_GET['reset_failed'])) {
    $n = $pdo->exec("UPDATE waste_companies SET geocode_status='pending' WHERE geocode_status='failed'");
    $resetMsg = "failed → pending 으로 되돌림: {$n}건";
}

$hasKey   = defined('NAVER_MAPS_KEY_ID') && NAVER_MAPS_KEY_ID !== ''
         && defined('NAVER_MAPS_KEY_SECRET') && NAVER_MAPS_KEY_SECRET !== '';
$hasKakao = defined('KAKAO_REST_API_KEY') && KAKAO_REST_API_KEY !== '';

$processed = 0; $okCnt = 0; $failCnt = 0; $fails = [];
$viaCnt = ['naver' => 0, 'kakao-addr' => 0, 'kakao-name' => 0];
$budgetHit = false;

if ($hasKey || $hasKakao) {
    // 1단계: 주소 있는 pending
    while (!$budgetHit && $processed < $limit) {
        $rows = $wc->pendingForGeocode(min($BATCH, $limit - $processed));
        if (!$rows) break;
        foreach ($rows as $row) {
            $region = wgeo_region($row);
            $g = wgeo_one((string)$row['address'], (string)$row['name'], $region, $hasKey, $hasKakao);
            wgeo_apply($wc, $row, $g, $okCnt, $failCnt, $viaCnt, $fails);
            $processed++;
            if (microtime(true) - $START_TS > $TIME_BUDGET) { $budgetHit = true; break; }
            if ($SLEEP_MS > 0) usleep($SLEEP_MS * 1000);
        }
    }
    // 2단계: 주소 없는 pending
    while (!$budgetHit && $hasKakao && $processed < $limit) {
        $rows = $wc->pendingByName(min($BATCH, $limit - $processed));
        if (!$rows) break;
        foreach ($rows as $row) {
            $region = wgeo_region($row);
            $g = wgeo_one(null, (string)$row['name'], $region, $hasKey, $hasKakao);
            wgeo_apply($wc, $row, $g, $okCnt, $failCnt, $viaCnt, $fails);
            $processed++;
            if (microtime(true) - $START_TS > $TIME_BUDGET) { $budgetHit = true; break; }
            if ($SLEEP_MS > 0) usleep($SLEEP_MS * 1000);
        }
    }
}

$remaining = $wc->remainingPending();
$elapsed = round(microtime(true) - $START_TS, 1);

echo "<h2>공제조합 좌표화 배치</h2>";
if ($resetMsg) echo "<p>{$resetMsg}</p>";
if (!$hasKey && !$hasKakao) echo "<p style='color:#c00'>지오코딩 키 없음 (env/maps.inc · env/kakao.inc 확인)</p>";
echo "<ul>";
echo "<li>처리: {$processed} (성공 {$okCnt} / 실패 {$failCnt})</li>";
echo "<li>경로: 네이버 {$viaCnt['naver']} · 카카오주소 {$viaCnt['kakao-addr']} · 카카오이름 {$viaCnt['kakao-name']}</li>";
echo "<li>남은 pending: <b>{$remaining}</b>" . ($budgetHit ? " (시간예산 초과 — 다시 호출하세요)" : "") . "</li>";
echo "<li>소요: {$elapsed}s</li>";
echo "</ul>";
if ($fails) {
    echo "<p>실패 샘플:</p><ul>";
    foreach ($fails as $f) echo "<li>" . htmlspecialchars($f) . "</li>";
    echo "</ul>";
}
if ($remaining > 0) {
    echo "<p>계속: <code>/cron_waste_geocode.php?key={$TOKEN}</code></p>";
} else {
    echo "<p>✅ 좌표화 완료.</p>";
}

// ============================================================
// 헬퍼 (cron_place_geocode.php 와 동일 로직, waste_companies 필드명에 맞춤)
// ============================================================

/** 행의 지역 라벨: 시군구 우선, 없으면 시도 */
function wgeo_region(array $row): string
{
    $lv2 = trim((string)($row['region_lv2'] ?? ''));
    if ($lv2 !== '') return $lv2;
    return trim((string)($row['region_lv1'] ?? ''));
}

/** 좌표화 cascade: 네이버 주소 → 카카오 주소 → 카카오 이름(검증) */
function wgeo_one(?string $address, string $name, string $region, bool $hasKey, bool $hasKakao): array
{
    if ($address !== null && $address !== '') {
        if ($hasKey) {
            $r = GeoCoder::geocode('naver', $address);
            if (!empty($r['ok'])) {
                return ['ok' => true, 'lat' => (float)$r['lat'], 'lng' => (float)$r['lng'], 'via' => 'naver'];
            }
        }
        if ($hasKakao) {
            $k = wgeo_kakao_addr($address);
            if ($k) return ['ok' => true, 'lat' => $k['lat'], 'lng' => $k['lng'], 'via' => 'kakao-addr'];
        }
    }
    if ($hasKakao && $name !== '') {
        $k = wgeo_kakao_name($name, $region);
        if ($k) return ['ok' => true, 'lat' => $k['lat'], 'lng' => $k['lng'], 'via' => 'kakao-name'];
    }
    return ['ok' => false];
}

function wgeo_kakao_addr(string $address): ?array
{
    $res = GeoCoder::searchKeyword($address, 5);
    if (empty($res['ok']) || empty($res['items'])) return null;
    $top = $res['items'][0];
    return ['lat' => (float)$top['lat'], 'lng' => (float)$top['lng']];
}

function wgeo_kakao_name(string $name, string $region): ?array
{
    $name = trim($name);
    if ($name === '') return null;
    $items = GeoCoder::searchKeyword($name, 5)['items'] ?? [];
    if (!$items && $region !== '' && $region !== $name) {
        $items = GeoCoder::searchKeyword(trim($name . ' ' . $region), 5)['items'] ?? [];
    }
    if (!$items) return null;

    $nkey    = wgeo_norm($name);
    $prov    = wgeo_prov_core($region);
    $nameLen = mb_strlen($name);

    $best = null; $bestScore = 0.0;
    foreach ($items as $i => $it) {
        $pn   = wgeo_norm((string)$it['name']);
        $addr = (string)$it['address'];
        $score = 0.0;
        if ($pn !== '' && $pn === $nkey) {
            $score += 5;
        } elseif ($pn !== '' && $nameLen >= 5 &&
                  (mb_strpos($pn, $nkey) !== false || mb_strpos($nkey, $pn) !== false)) {
            $score += 3;
        }
        if ($prov !== '' && mb_strpos($addr, $prov) !== false) {
            $score += 2;
        }
        $score += max(0, 5 - $i) * 0.01;
        if ($score > $bestScore) { $bestScore = $score; $best = $it; }
    }
    if ($bestScore < 3 || $best === null) return null;
    return ['lat' => (float)$best['lat'], 'lng' => (float)$best['lng']];
}

function wgeo_norm(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    return preg_replace('/[\s\x{00A0}·\-_,\.\(\)\[\]]+/u', '', $s) ?? $s;
}

function wgeo_prov_core(string $region): string
{
    $region = trim($region);
    if ($region === '') return '';
    $map = [
        '서울' => '서울', '부산' => '부산', '대구' => '대구', '인천' => '인천',
        '광주' => '광주', '대전' => '대전', '울산' => '울산', '세종' => '세종',
        '경기' => '경기', '강원' => '강원', '제주' => '제주',
        '충청북' => '충북', '충북' => '충북', '충청남' => '충남', '충남' => '충남',
        '전라북' => '전북', '전북' => '전북', '전라남' => '전남', '전남' => '전남',
        '경상북' => '경북', '경북' => '경북', '경상남' => '경남', '경남' => '경남',
    ];
    foreach ($map as $k => $v) {
        if (mb_strpos($region, $k) !== false) return $v;
    }
    return '';
}

function wgeo_apply(WasteCompany $wc, array $row, array $g, int &$okCnt, int &$failCnt, array &$viaCnt, array &$fails): void
{
    if (!empty($g['ok'])) {
        $wc->setGeocode((int)$row['id'], (float)$g['lat'], (float)$g['lng'], 'ok');
        $okCnt++;
        if (isset($viaCnt[$g['via']])) $viaCnt[$g['via']]++;
    } else {
        $wc->setGeocode((int)$row['id'], null, null, 'failed');
        $failCnt++;
        if (count($fails) < 20) $fails[] = "#{$row['id']} {$row['name']}";
    }
}
?>
