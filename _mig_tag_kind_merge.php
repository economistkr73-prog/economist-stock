<?php
/**
 * _mig_tag_kind_merge.php — place_tag 의 kind 통합 (type/facet → theme)
 *
 * 배경: place_tag.kind(type/theme/month/facet) 중 type/theme/facet 구분은
 * 검색(searchByTags)에서 무시되고 화면엔 태그명만 보여 사실상 죽은 구분이며,
 * 같은 단어가 자동태깅(type)·수동입력(theme)으로 갈려 칩 카운트와 검색 결과가
 * 어긋나는 원인이 됐다(예: 둘레길 칩 531 vs 리스트 579). month 만 남기고 통합한다.
 *
 * 동작:
 *   1) UPDATE IGNORE 로 type/facet → theme 이동 (이미 theme 동일태그가 있으면 충돌 → 보류)
 *   2) 이동 못 한(충돌로 남은) type/facet 행 삭제 → 장소·태그당 theme 1행으로 수렴
 *
 * 실행: https://economist.kr/_mig_tag_kind_merge.php?key=econ-place-mig         (적용)
 *       https://economist.kr/_mig_tag_kind_merge.php?key=econ-place-mig&dry=1   (미리보기)
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
    // ── 통합 전 현황 ──
    echo "── 통합 전 kind 분포 ──\n";
    foreach ($pdo->query("SELECT kind, COUNT(*) cnt FROM place_tag GROUP BY kind ORDER BY cnt DESC") as $r) {
        echo sprintf("  %-7s %d\n", $r['kind'], $r['cnt']);
    }

    // 이동 대상(type/facet) 중 같은 place 에 theme 동일태그가 이미 있어 충돌할 행 수
    $dup = (int)$pdo->query(
        "SELECT COUNT(*) FROM place_tag s
          WHERE s.kind IN ('type','facet')
            AND EXISTS (SELECT 1 FROM place_tag d
                         WHERE d.place_id = s.place_id AND d.kind = 'theme' AND d.tag = s.tag)"
    )->fetchColumn();
    $movable = (int)$pdo->query(
        "SELECT COUNT(*) FROM place_tag WHERE kind IN ('type','facet')"
    )->fetchColumn();
    echo "\n이동 대상(type/facet): {$movable}행 — 그중 theme 중복 {$dup}행은 삭제(병합), 나머지 " . ($movable - $dup) . "행은 theme 로 이동\n";

    if ($dry) {
        echo "\n[dry-run] 변경 없음. 적용하려면 &dry=1 빼고 호출하세요.\n";
        exit;
    }

    // ── 1) 충돌 없는 건 theme 로 이동 ──
    $moved = $pdo->exec("UPDATE IGNORE place_tag SET kind = 'theme' WHERE kind IN ('type','facet')");
    // ── 2) 충돌로 남은 type/facet 행 제거(이미 theme 에 동일태그 존재) ──
    $deleted = $pdo->exec("DELETE FROM place_tag WHERE kind IN ('type','facet')");

    echo "\n✅ 이동(theme 전환): {$moved}행, 중복 제거: {$deleted}행\n";

    // ── 통합 후 현황 ──
    echo "\n── 통합 후 kind 분포 ──\n";
    foreach ($pdo->query("SELECT kind, COUNT(*) cnt FROM place_tag GROUP BY kind ORDER BY cnt DESC") as $r) {
        echo sprintf("  %-7s %d\n", $r['kind'], $r['cnt']);
    }

    // ── 검증: '둘레길' 칩 카운트(지도에 뜨는 장소 기준) ──
    $chk = (int)$pdo->query(
        "SELECT COUNT(DISTINCT pt.place_id) FROM place_tag pt
           JOIN place p ON p.id = pt.place_id
          WHERE pt.tag = '둘레길' AND pt.kind = 'theme'
            AND p.is_active = 1 AND p.geocode_status = 'ok'"
    )->fetchColumn();
    echo "\n검증) '둘레길' theme · 지도표시(ok·활성) 장소 수: {$chk}\n";
    echo "이 파일은 삭제하세요.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "❌ 실패: " . $e->getMessage() . "\n";
}
?>
