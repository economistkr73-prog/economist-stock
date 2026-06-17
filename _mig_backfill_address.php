<?php
/**
 * _mig_backfill_address.php — 좌표는 있는데 주소(address)가 빈 장소를 역지오코딩으로 채움
 *
 * 배경: 이름 기반 카카오 키워드 검색으로 좌표만 받아 저장된 장소는 lat/lng 는 있어
 * 지도에 마커는 뜨지만 address 컬럼이 비어, 수정모달 검색창이 이름으로 뜬다.
 * 좌표가 있으니 coord2address(역지오코딩)로 주소를 채운다(카카오, 도로명 우선·없으면 지번).
 *
 * 실행: https://economist.kr/_mig_backfill_address.php?key=econ-place-mig            (한 묶음 처리)
 *       https://economist.kr/_mig_backfill_address.php?key=econ-place-mig&dry=1      (대상 수만 확인)
 *       옵션: &limit=800(한 번에 최대 건수) &sec=25(시간예산 초)
 * 남은 게 있으면 같은 URL 을 다시 호출하면 이어서 처리(이미 채운 건 제외). 완료 후 삭제 권장.
 */
require_once "./env/cnt.inc";
require_once "./env/kakao.inc";          // KAKAO_REST_API_KEY (역지오코딩에 필요)
header('Content-Type: text/plain; charset=utf-8');

$TOKEN = 'econ-place-mig';
if (PHP_SAPI !== 'cli' && ($_GET['key'] ?? '') !== $TOKEN) {
    http_response_code(403);
    exit('forbidden: ?key=' . $TOKEN . ' 필요');
}
// 진단 조회: ?find=가평레일바이크 → 이름 LIKE 매칭 장소들의 실제 DB 상태 출력
if (isset($_GET['find']) && trim((string)$_GET['find']) !== '') {
    $q = trim((string)$_GET['find']);
    $st = $pdo->prepare("SELECT id, name, category, geocode_status, lat, lng, address
                           FROM place WHERE name LIKE :q ORDER BY id LIMIT 50");
    $st->execute([':q' => '%' . $q . '%']);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    echo "“{$q}” 매칭 " . count($rows) . "건\n\n";
    foreach ($rows as $r) {
        echo sprintf("id=%d  status=%s  lat=%s lng=%s\n  name=%s\n  address=%s\n\n",
            $r['id'], $r['geocode_status'], $r['lat'] ?? 'NULL', $r['lng'] ?? 'NULL',
            $r['name'], ($r['address'] === null ? 'NULL' : ($r['address'] === '' ? '(빈문자열)' : $r['address'])));
    }
    exit;
}

$dry    = ((int)($_GET['dry'] ?? 0) === 1);
$limit  = max(1, min(3000, (int)($_GET['limit'] ?? 800)));
$maxSec = max(5, min(60, (int)($_GET['sec'] ?? 25)));
$start  = microtime(true);

$WHERE = "geocode_status = 'ok' AND lat IS NOT NULL AND lng IS NOT NULL AND (address IS NULL OR address = '')";

try {
    $total = (int)$pdo->query("SELECT COUNT(*) FROM place WHERE {$WHERE}")->fetchColumn();
    echo "주소 없는 좌표 장소(역지오코딩 대상): {$total}건\n";
    if ($total === 0) { echo "채울 대상이 없습니다. 이 파일은 삭제하세요.\n"; exit; }
    if ($dry) { echo "\n[dry-run] 변경 없음. 적용하려면 &dry=1 빼고 호출하세요.\n"; exit; }

    $rows = $pdo->query("SELECT id, name, lat, lng FROM place WHERE {$WHERE} ORDER BY id LIMIT {$limit}")
                ->fetchAll(PDO::FETCH_ASSOC);
    $upd = $pdo->prepare("UPDATE place SET address = :a, updated_at = NOW() WHERE id = :id");

    // 두 좌표 사이 거리(m) — 하버사인
    $haversine = function (float $la1, float $ln1, float $la2, float $ln2): float {
        $R = 6371000.0; $dLa = deg2rad($la2 - $la1); $dLn = deg2rad($ln2 - $ln1);
        $a = sin($dLa / 2) ** 2 + cos(deg2rad($la1)) * cos(deg2rad($la2)) * sin($dLn / 2) ** 2;
        return 2 * $R * asin(min(1.0, sqrt($a)));
    };

    $keyLen = defined('KAKAO_REST_API_KEY') ? strlen((string)KAKAO_REST_API_KEY) : -1;
    echo "KAKAO_REST_API_KEY 길이: " . ($keyLen < 0 ? '정의 안 됨' : $keyLen) . "\n\n";

    $done = 0; $filled = 0; $fail = 0; $stopped = false; $samples = [];
    foreach ($rows as $r) {
        if (microtime(true) - $start > $maxSec) { $stopped = true; break; }
        $done++;
        $lat = (float)$r['lat']; $lng = (float)$r['lng'];
        $addr = ''; $how = '';

        // 1순위: 이름 키워드 재검색 → 저장 좌표에 가장 가까운(≤1km) 결과의 주소
        //   (원래 이 좌표를 얻은 방식이라, 바다/산정상처럼 지번이 없는 좌표도 POI 주소 복구 가능)
        $name = trim((string)($r['name'] ?? ''));
        if ($name !== '') {
            $kw = GeoCoder::searchKeyword($name, 10);
            if (!empty($kw['ok']) && !empty($kw['items'])) {
                $bestD = INF; $bestA = '';
                foreach ($kw['items'] as $it) {
                    if (empty($it['address'])) continue;
                    $d = $haversine($lat, $lng, (float)$it['lat'], (float)$it['lng']);
                    if ($d < $bestD) { $bestD = $d; $bestA = $it['address']; }
                }
                if ($bestA !== '' && $bestD <= 1000) { $addr = $bestA; $how = 'keyword'; }
            }
            usleep(50000);
        }
        // 2순위: 역지오코딩(좌표→주소) — 육지 필지면 성공
        if ($addr === '') {
            $rv = GeoCoder::reverseGeocode($lat, $lng);
            if (!empty($rv['ok']) && !empty($rv['address'])) { $addr = $rv['address']; $how = 'reverse'; }
        }

        if ($addr !== '') {
            $upd->execute([':a' => mb_substr($addr, 0, 255), ':id' => (int)$r['id']]);
            $filled++;
        } else {
            $fail++;
            if (count($samples) < 8) {
                $samples[] = sprintf("  id=%d \"%s\" lat=%s lng=%s → 주소 못 찾음", $r['id'], $name, $r['lat'], $r['lng']);
            }
        }
        usleep(50000); // 카카오 호출 매너
    }
    if ($samples) { echo "실패 샘플(최대 8):\n" . implode("\n", $samples) . "\n\n"; }

    $remain = (int)$pdo->query("SELECT COUNT(*) FROM place WHERE {$WHERE}")->fetchColumn();
    echo "처리 {$done} · 주소 채움 {$filled} · 주소 못 찾음 {$fail}";
    echo $stopped ? "  (시간예산 {$maxSec}s 도달로 중단)\n" : "\n";
    echo "남은 대상: {$remain}건\n";
    echo $remain > 0
        ? "→ 같은 URL 을 다시 호출하면 이어서 처리합니다.\n"
        : "✅ 모두 완료. 이 파일은 삭제하세요.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "❌ 실패: " . $e->getMessage() . "\n";
}
?>
