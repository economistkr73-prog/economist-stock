<?php
/**
 * mig_category_to_group.php — 카테고리(업무/개인/주식/회의/기타) 폐지 데이터 마이그레이션
 *
 * 기념일이 아닌 일정 중 project_id(분류/프로젝트)가 비어 있고 category만 있는 건을
 * 같은 이름의 분류(그룹)로 자동 변환해 project_id를 채운다. 이미 분류가 연결된 일정은 손대지 않는다.
 *
 * 사용법:
 *   /mig_category_to_group.php?key=econ-cat2grp            → 미리보기(집계만, DB 변경 없음)
 *   /mig_category_to_group.php?key=econ-cat2grp&apply=1     → 실제 반영(그룹 생성 + project_id 연결 + category 필드 정리)
 *
 * 1회성 스크립트 — 실행 후 삭제할 것.
 */

require_once "./env/cnt.inc";
header('Content-Type: text/plain; charset=utf-8');

$TOKEN = 'econ-cat2grp';
if (($_GET['key'] ?? '') !== $TOKEN) {
    http_response_code(403);
    exit('forbidden: ?key=' . $TOKEN . ' 필요');
}

$apply = ($_GET['apply'] ?? '') === '1';

echo $apply ? "=== 실행 모드 (실제 반영) ===\n\n" : "=== 미리보기 모드 (DB 변경 없음. 실제 반영하려면 &apply=1 추가) ===\n\n";

$stmt = $pdo->query("
    SELECT category, COUNT(*) cnt
    FROM tbl_schedule
    WHERE event_type <> 'anniversary'
      AND project_id IS NULL
      AND category IS NOT NULL AND category <> ''
    GROUP BY category
");
$cats = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$cats) {
    echo "대상 없음 (이미 마이그레이션 완료됐거나 해당 데이터가 없습니다)\n";
    exit;
}

$defaultIcons = ['업무' => '💼', '개인' => '🏠', '주식' => '📈', '회의' => '🤝', '기타' => '⭐'];

foreach ($cats as $row) {
    $cat = $row['category'];
    $cnt = (int)$row['cnt'];
    echo "카테고리 '{$cat}': {$cnt}건\n";

    $find = $pdo->prepare("SELECT id FROM tbl_project WHERE type='group' AND title=:t LIMIT 1");
    $find->execute([':t' => $cat]);
    $gid = $find->fetchColumn();

    if (!$gid) {
        $icon = $defaultIcons[$cat] ?? null;
        echo "  -> '{$cat}' 분류(그룹) 없음 -> " . ($apply ? "생성" : "생성 예정") . " (아이콘: " . ($icon ?? '없음') . ")\n";
        if ($apply) {
            $ins = $pdo->prepare("INSERT INTO tbl_project (title, type, color, icon, sort_order) VALUES (:t, 'group', '#3498db', :icon, 0)");
            $ins->execute([':t' => $cat, ':icon' => $icon]);
            $gid = $pdo->lastInsertId();
        }
    } else {
        echo "  -> 기존 '{$cat}' 분류(그룹) #{$gid} 재사용\n";
    }

    if ($apply && $gid) {
        $upd = $pdo->prepare("UPDATE tbl_schedule SET project_id=:gid WHERE event_type<>'anniversary' AND project_id IS NULL AND category=:cat");
        $upd->execute([':gid' => $gid, ':cat' => $cat]);
        echo "  -> {$upd->rowCount()}건에 project_id=#{$gid} 연결 완료\n";
    }
}

if ($apply) {
    $clr = $pdo->exec("UPDATE tbl_schedule SET category='' WHERE event_type <> 'anniversary'");
    echo "\n비기념일 일정 category 필드 정리: {$clr}건\n";
}

echo "\n=== 끝 ===\n";
