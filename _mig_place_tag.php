<?php
/**
 * _mig_place_tag.php — place_tag(M:N 태그) 테이블 생성
 *
 * category(단일 주분류) 외의 다중 라벨(부분류·테마·월·편의)을 태그로 관리.
 * Place::ensureTable 에도 동일 정의가 있으나, 운영 DB 는 IF NOT EXISTS 라
 * 자동 생성되지 않을 수 있어 이 스크립트로 1회 보장한다.
 *
 * 실행: https://economist.kr/_mig_place_tag.php?key=econ-place-mig   (1회)
 * 완료 후 삭제 권장.
 */
require_once "./env/cnt.inc";
header('Content-Type: text/plain; charset=utf-8');

$TOKEN = 'econ-place-mig';
if (PHP_SAPI !== 'cli' && ($_GET['key'] ?? '') !== $TOKEN) {
    http_response_code(403);
    exit('forbidden: ?key=' . $TOKEN . ' 필요');
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS place_tag (
            place_id BIGINT UNSIGNED NOT NULL,
            kind     ENUM('type','theme','month','facet') NOT NULL DEFAULT 'theme',
            tag      VARCHAR(40)     NOT NULL,
            PRIMARY KEY (place_id, kind, tag),
            KEY idx_tag      (tag),
            KEY idx_kind_tag (kind, tag),
            CONSTRAINT fk_ptag_place FOREIGN KEY (place_id)
                REFERENCES place(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM place_tag")->fetchColumn();
    echo "✅ place_tag 준비 완료. 현재 태그 행 수: {$cnt}\n";
    echo "이 파일은 삭제하세요.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "❌ 실패: " . $e->getMessage() . "\n";
}
?>
