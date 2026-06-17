<?php
/**
 * _mig_category_event_stay.php — place.category 개편
 *
 *   1) '축제(event)' 분류 폐지 → 해당 장소에 '축제' 태그(theme) 부여 후 분류를 '여행지(travel)'로 전환
 *   2) '숙소(stay)' 분류 신설 (ENUM 에 추가)
 *   3) ENUM 재정의: ('travel','stay','restaurant','etc')  ※ '기타(etc)' 는 미분류 보관함 버킷이 쓰므로 유지
 *
 * 실행: https://economist.kr/_mig_category_event_stay.php?key=econ-place-mig          (적용)
 *       https://economist.kr/_mig_category_event_stay.php?key=econ-place-mig&dry=1    (미리보기)
 * 완료 후 삭제 권장.
 */
require_once "./env/cnt.inc";
header('Content-Type: text/plain; charset=utf-8');

$TOKEN = 'econ-place-mig';
if (PHP_SAPI !== 'cli' && ($_GET['key'] ?? '') !== $TOKEN) {
    http_response_code(403);
    exit('forbidden: ?key=' . $TOKEN . ' 필요');
}
$dry = ((int)($_GET['dry'] ?? 0) === 1);

try {
    echo "── 통합 전 category 분포 ──\n";
    foreach ($pdo->query("SELECT category, COUNT(*) cnt FROM place GROUP BY category ORDER BY cnt DESC") as $r) {
        echo sprintf("  %-11s %d\n", $r['category'], $r['cnt']);
    }
    $evt = (int)$pdo->query("SELECT COUNT(*) FROM place WHERE category = 'event'")->fetchColumn();
    echo "\n'축제(event)' 장소 {$evt}건 → '축제' 태그 부여 + 분류 '여행지'로 전환\n";

    if ($dry) {
        echo "\n[dry-run] 변경 없음. 적용하려면 &dry=1 빼고 호출하세요.\n";
        exit;
    }

    // 1) event 장소에 '축제' 태그(theme) 부여
    $tagged = $pdo->exec(
        "INSERT IGNORE INTO place_tag (place_id, kind, tag)
         SELECT id, 'theme', '축제' FROM place WHERE category = 'event'"
    );
    // 2) event → travel 전환 (ENUM 에서 event 제거 전에 비워둠)
    $moved = $pdo->exec("UPDATE place SET category = 'travel' WHERE category = 'event'");
    // 3) ENUM 재정의: event 제거, stay 추가
    $pdo->exec("ALTER TABLE place
                MODIFY category ENUM('travel','stay','restaurant','etc') NOT NULL DEFAULT 'travel'");

    echo "\n✅ '축제' 태그 부여: {$tagged}건, 분류 전환(event→travel): {$moved}건, ENUM 재정의 완료(stay 추가·event 제거)\n";

    echo "\n── 통합 후 category 분포 ──\n";
    foreach ($pdo->query("SELECT category, COUNT(*) cnt FROM place GROUP BY category ORDER BY cnt DESC") as $r) {
        echo sprintf("  %-11s %d\n", $r['category'], $r['cnt']);
    }
    $chk = (int)$pdo->query("SELECT COUNT(DISTINCT place_id) FROM place_tag WHERE tag = '축제' AND kind = 'theme'")->fetchColumn();
    echo "\n검증) '축제' 태그가 달린 장소 수: {$chk}\n";
    echo "이 파일은 삭제하세요.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "❌ 실패: " . $e->getMessage() . "\n";
}
?>
