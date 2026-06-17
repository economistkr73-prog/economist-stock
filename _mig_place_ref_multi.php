<?php
/**
 * _mig_place_ref_multi.php — place_ref 의 전역 UNIQUE(url_hash) → 복합 UNIQUE(place_id, url_hash)
 *
 * 목적: 한 기사(URL)가 여러 장소에 연결될 수 있게 한다(멀티 등록).
 *   기존: uq_url (url_hash)            — 같은 URL 은 DB 전체에서 1행만 가능 → 멀티 등록 불가
 *   변경: uq_place_url (place_id, url_hash) — 장소가 다르면 같은 URL 도 허용, 같은 장소 중복은 여전히 차단
 *
 * 크롤러 dedup 은 tbl_ardent_crawl(idxno) 로 처리하므로 이 인덱스 변경의 영향 없음.
 * 실행: https://economist.kr/_mig_place_ref_multi.php?key=econ-place-mig   (1회)
 * 완료 후 이 파일은 삭제 권장.
 */
require_once "./env/cnt.inc";
header('Content-Type: text/plain; charset=utf-8');

$TOKEN = 'econ-place-mig';
if (PHP_SAPI !== 'cli' && ($_GET['key'] ?? '') !== $TOKEN) {
    http_response_code(403);
    exit('forbidden: ?key=' . $TOKEN . ' 필요');
}

try {
    // 현재 place_ref 의 인덱스 상태 확인
    $idx = $pdo->query("SHOW INDEX FROM place_ref")->fetchAll(PDO::FETCH_ASSOC);
    $names = array_unique(array_column($idx, 'Key_name'));
    $hasOld = in_array('uq_url', $names, true);
    $hasNew = in_array('uq_place_url', $names, true);

    echo "현재 인덱스: " . implode(', ', $names) . "\n";

    if ($hasNew) {
        echo "이미 마이그레이션됨 (uq_place_url 존재). 변경 없음.\n";
        exit;
    }

    // 복합 UNIQUE 추가 (+ 있으면 기존 전역 UNIQUE 제거)
    $sql = $hasOld
        ? "ALTER TABLE place_ref DROP INDEX uq_url, ADD UNIQUE KEY uq_place_url (place_id, url_hash)"
        : "ALTER TABLE place_ref ADD UNIQUE KEY uq_place_url (place_id, url_hash)";
    $pdo->exec($sql);

    echo "실행: {$sql}\n";

    $after = array_unique(array_column($pdo->query("SHOW INDEX FROM place_ref")->fetchAll(PDO::FETCH_ASSOC), 'Key_name'));
    echo "변경 후 인덱스: " . implode(', ', $after) . "\n";
    echo "✅ 완료. 멀티 등록 가능. 이 파일은 삭제하세요.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "❌ 실패: " . $e->getMessage() . "\n";
}
?>
