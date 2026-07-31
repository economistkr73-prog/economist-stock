<?php
/**
 * cron_place_geocode.php — place 테이블 지오코딩 배치 (한 cron 에서 전체 처리)
 *
 * 한 번 실행에서 아래 순서로 좌표화하고, 시간예산 초과 시 종료 → 다음 호출이 이어받는다.
 *   1단계) 주소 있는 pending : 네이버 주소 지오코딩 → 실패 시 카카오 키워드(주소) 폴백
 *   2단계) 주소 없는 pending : 카카오 키워드(이름 + 지역) 검색 (지역 검증으로 오매칭 방지)
 * 성공 → lat/lng + 'ok', 실패 → 'failed'. ok/failed 는 다음 실행에서 제외(이어받기).
 *
 * 사용 예 (cafe24 cron 은 URL 호출):
 *   /cron_place_geocode.php?key=econ-place-geo
 *   /cron_place_geocode.php?key=econ-place-geo&limit=300
 *   /cron_place_geocode.php?key=econ-place-geo&reset_failed=1   ← failed 를 pending 으로 되돌려 재시도
 *
 * 남은 pending 이 0 이 나올 때까지 반복 호출하면 전체 좌표화 완료.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . "/env/cnt.inc";
if (file_exists($_SERVER['DOCUMENT_ROOT'] . "/env/maps.inc"))  require_once $_SERVER['DOCUMENT_ROOT'] . "/env/maps.inc";  // 네이버 지오코딩 키
if (file_exists($_SERVER['DOCUMENT_ROOT'] . "/env/kakao.inc")) require_once $_SERVER['DOCUMENT_ROOT'] . "/env/kakao.inc"; // 카카오 키워드 검색 키

header('Content-Type: text/html; charset=utf-8');

// 간단한 실행 토큰 (오남용 방지). CLI 실행 시엔 토큰 불필요.
$TOKEN = 'econ-place-geo';
if (PHP_SAPI !== 'cli' && ($_GET['key'] ?? '') !== $TOKEN) {
    http_response_code(403);
    exit('forbidden: ?key=' . $TOKEN . ' 필요');
}

@set_time_limit(0);
$TIME_BUDGET = 25;            // 1회 실행 최대 처리 시간(초) — 프록시 타임아웃 회피
$SLEEP_MS    = 120;          // API 호출 간격(ms) — rate limit 완화
$BATCH       = 50;           // pending 조회 단위
$START_TS    = microtime(true);

$limit = max(1, min(5000, (int)($_GET['limit'] ?? 300)));  // 이번 실행에서 처리할 최대 건수

$place = new Place($pdo);
$place->ensureTable();

// failed → pending 되돌리기 (transient 실패/로직 개선 후 재시도용)
$resetMsg = '';
if (!empty($_GET['reset_failed'])) {
    $n = $pdo->exec("UPDATE place SET geocode_status='pending' WHERE geocode_status='failed'");
    $resetMsg = "failed → pending 으로 되돌림: {$n}건";
}

// 키 확인
$hasKey   = defined('NAVER_MAPS_KEY_ID') && NAVER_MAPS_KEY_ID !== ''
         && defined('NAVER_MAPS_KEY_SECRET') && NAVER_MAPS_KEY_SECRET !== '';
$hasKakao = defined('KAKAO_REST_API_KEY') && KAKAO_REST_API_KEY !== '';

$processed = 0; $okCnt = 0; $failCnt = 0; $fails = [];
$viaCnt = ['naver' => 0, 'kakao-addr' => 0, 'kakao-name' => 0];
$budgetHit = false;

if ($hasKey || $hasKakao) {
    // ── 1단계: 주소 있는 pending (네이버 주소 → 카카오 키워드 폴백) ──
    while (!$budgetHit && $processed < $limit) {
        $rows = $place->pendingForGeocode(min($BATCH, $limit - $processed));
        if (!$rows) break;
        foreach ($rows as $row) {
            $region = geo_region($row);
            $g = geocode_one((string)$row['address'], (string)$row['name'], $region, $hasKey, $hasKakao);
            geo_apply($place, $row, $g, $okCnt, $failCnt, $viaCnt, $fails);
            $processed++;
            if (microtime(true) - $START_TS > $TIME_BUDGET) { $budgetHit = true; break; }
            if ($SLEEP_MS > 0) usleep($SLEEP_MS * 1000);
        }
    }

    // ── 2단계: 주소 없는 pending (이름 + 지역 → 카카오 키워드, 지역 검증) ──
    while (!$budgetHit && $hasKakao && $processed < $limit) {
        $rows = $place->pendingByName(min($BATCH, $limit - $processed));
        if (!$rows) break;
        foreach ($rows as $row) {
            $region = geo_region($row);
            $g = geocode_one(null, (string)$row['name'], $region, $hasKey, $hasKakao);
            geo_apply($place, $row, $g, $okCnt, $failCnt, $viaCnt, $fails);
            $processed++;
            if (microtime(true) - $START_TS > $TIME_BUDGET) { $budgetHit = true; break; }
            if ($SLEEP_MS > 0) usleep($SLEEP_MS * 1000);
        }
    }
}

// 남은 pending 수 (주소 있든 없든 — 이름이 있어 처리 가능한 것)
$remaining = (int)$pdo->query(
    "SELECT COUNT(*) FROM place
      WHERE geocode_status='pending'
        AND ((address IS NOT NULL AND address<>'') OR (name IS NOT NULL AND name<>''))"
)->fetchColumn();
$elapsed = round(microtime(true) - $START_TS, 1);

// ── 결과 출력 ───────────────────────────────────────────────
echo "<pre style='font-family:monospace;font-size:14px;line-height:1.6'>";
echo "🗺️  place 지오코딩 배치\n";
echo str_repeat('─', 44) . "\n";
if ($resetMsg) echo $resetMsg . "\n";
if (!$hasKey && !$hasKakao) {
    echo "⚠️  네이버/카카오 키 모두 미설정 (env/maps.inc · env/kakao.inc) — 처리 중단\n";
} else {
    echo "처리: {$processed}건  (성공 {$okCnt} / 실패 {$failCnt})\n";
    echo "  ├ 네이버 주소     : {$viaCnt['naver']}\n";
    echo "  ├ 카카오 주소폴백 : {$viaCnt['kakao-addr']}\n";
    echo "  └ 카카오 이름검색 : {$viaCnt['kakao-name']}\n";
    echo "소요: {$elapsed}초" . ($budgetHit ? "  (시간예산 도달 — 이어받기 대기)" : "") . "\n";
    if (!$hasKakao) echo "ℹ️  카카오 키 미설정 — 폴백/이름검색 비활성 (env/kakao.inc)\n";
}
echo "남은 pending: {$remaining}건\n";
echo str_repeat('─', 44) . "\n";
if ($remaining === 0 && ($hasKey || $hasKakao)) {
    echo "✅ 완료 — 좌표화할 pending 이 없습니다.\n";
} elseif (($hasKey || $hasKakao) && $processed > 0) {
    echo "↻ 아직 남았습니다. 같은 URL 을 다시 호출해 이어서 처리하세요.\n";
}
if ($fails) {
    echo "\n실패 목록(최대 20건):\n";
    foreach ($fails as $f) echo "  - " . htmlspecialchars($f) . "\n";
}
echo "</pre>";

// ============================================================
// 헬퍼
// ============================================================

/** 행의 지역 라벨: 시군구(region_lv2) 우선, 없으면 시도(region_lv1) */
function geo_region(array $row): string
{
    $lv2 = trim((string)($row['region_lv2'] ?? ''));
    if ($lv2 !== '') return $lv2;
    return trim((string)($row['region_lv1'] ?? ''));
}

/**
 * 좌표화 cascade.
 *   ① (주소 있으면) 네이버 주소 지오코딩
 *   ② (주소 있으면) 카카오 키워드 검색(주소) — top 결과
 *   ③ 카카오 키워드 검색(이름) — 이름 일치 검증으로 채택 (오매칭 방지)
 *
 * ※ region_lv1/lv2 는 크롤러가 키워드/태그로 오염시켜 둔 경우가 많아(예: "충주여행",
 *   "팜파스", "전촌용굴") 신뢰하지 않는다. 쿼리에 붙이지 않고(검색을 망침), 진짜
 *   행정구역일 때만 검증 가산점으로만 쓴다.
 * @return array ['ok'=>bool, 'lat','lng','via']
 */
function geocode_one(?string $address, string $name, string $region, bool $hasKey, bool $hasKakao): array
{
    if ($address !== null && $address !== '') {
        if ($hasKey) {
            $r = GeoCoder::geocode('naver', $address);
            if (!empty($r['ok'])) {
                return ['ok' => true, 'lat' => (float)$r['lat'], 'lng' => (float)$r['lng'], 'via' => 'naver'];
            }
        }
        if ($hasKakao) {
            $k = geo_kakao_addr($address);
            if ($k) return ['ok' => true, 'lat' => $k['lat'], 'lng' => $k['lng'], 'via' => 'kakao-addr'];
        }
    }
    if ($hasKakao && $name !== '') {
        $k = geo_kakao_name($name, $region);
        if ($k) return ['ok' => true, 'lat' => $k['lat'], 'lng' => $k['lng'], 'via' => 'kakao-name'];
    }
    return ['ok' => false];
}

/** 주소 기반 카카오 키워드 검색 — 질의가 이미 구체적이므로 top 결과 사용 */
function geo_kakao_addr(string $address): ?array
{
    $res = GeoCoder::searchKeyword($address, 5);
    if (empty($res['ok']) || empty($res['items'])) return null;
    $top = $res['items'][0];
    return ['lat' => (float)$top['lat'], 'lng' => (float)$top['lng']];
}

/**
 * 이름 기반 카카오 키워드 검색 + 이름 일치 검증.
 *  - 검색은 이름만으로 (오염된 region 을 쿼리에 붙이면 검색이 깨짐)
 *  - 채택 점수: place_name 정확일치(+5) / 이름5자↑ 부분일치(+3) / 진짜 행정구역이 주소에 포함(+2)
 *  - 임계 3 미만이면 null (일반어 "해안·숲길·국내사찰" 등은 좌표 못 찍는 게 정상)
 */
function geo_kakao_name(string $name, string $region): ?array
{
    $name = trim($name);
    if ($name === '') return null;

    $items = GeoCoder::searchKeyword($name, 5)['items'] ?? [];
    // 이름만으로 0건이면 지역을 보조로 한 번 더 (행정구역이 아니어도 카카오는 퍼지 매칭됨)
    if (!$items && $region !== '' && $region !== $name) {
        $items = GeoCoder::searchKeyword(trim($name . ' ' . $region), 5)['items'] ?? [];
    }
    if (!$items) return null;

    $nkey   = geo_norm($name);
    $prov   = geo_prov_core($region);   // 진짜 시도(2자 코어) 또는 ''
    $nameLen = mb_strlen($name);

    $best = null; $bestScore = 0.0;
    foreach ($items as $i => $it) {
        $pn   = geo_norm((string)$it['name']);
        $addr = (string)$it['address'];
        $score = 0.0;
        if ($pn !== '' && $pn === $nkey) {
            $score += 5;                                   // 정확 일치
        } elseif ($pn !== '' && $nameLen >= 5 &&
                  (mb_strpos($pn, $nkey) !== false || mb_strpos($nkey, $pn) !== false)) {
            $score += 3;                                   // 구체적 이름의 부분 일치
        }
        if ($prov !== '' && mb_strpos($addr, $prov) !== false) {
            $score += 2;                                   // 진짜 행정구역 주소 일치
        }
        $score += max(0, 5 - $i) * 0.01;                   // 상위 결과 tie-break
        if ($score > $bestScore) { $bestScore = $score; $best = $it; }
    }
    // 이름이 매칭돼야 채택 (행정구역만 일치(2점)로는 부족 — region 자체가 못 믿음)
    if ($bestScore < 3 || $best === null) return null;
    return ['lat' => (float)$best['lat'], 'lng' => (float)$best['lng']];
}

/** 비교용 정규화: 소문자 + 공백/구분기호 제거 */
function geo_norm(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    return preg_replace('/[\s\x{00A0}·\-_,\.\(\)\[\]]+/u', '', $s) ?? $s;
}

/**
 * region 문자열에서 진짜 시도(2자 코어)를 추출. 행정구역이 아니면 ''.
 * 신·구 명칭 모두 흡수 (강원도/강원특별자치도 → 강원, 전라북도/전북특별자치도 → 전북).
 */
function geo_prov_core(string $region): string
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

/** 좌표화 결과를 DB 반영 + 카운터 누적 */
function geo_apply(Place $place, array $row, array $g, int &$okCnt, int &$failCnt, array &$viaCnt, array &$fails): void
{
    if (!empty($g['ok'])) {
        $place->setGeocode((int)$row['id'], (float)$g['lat'], (float)$g['lng'], 'ok');
        $okCnt++;
        if (isset($viaCnt[$g['via']])) $viaCnt[$g['via']]++;
    } else {
        $place->setGeocode((int)$row['id'], null, null, 'failed');
        $failCnt++;
        if (count($fails) < 20) $fails[] = "#{$row['id']} {$row['name']}";
    }
}
?>
