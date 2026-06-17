<?php
/**
 * _mig_food_guide.php — 맛집 가이드 도메인 스키마 마이그레이션 (1회용 throwaway)
 *
 *   ① place_guide 테이블 생성 (가이드 중분류, 복수 등재 허용)
 *   ② place_tag.kind ENUM 에 'cuisine'(음식 종류) + 'grade'(등급 #리본2) 추가
 *
 * 실행:  https://economist.kr/_mig_food_guide.php?key=econ-mig-food
 * 실행 후 서버에서 삭제할 것(커밋 제외).
 */
require_once "./env/cnt.inc";

header('Content-Type: text/plain; charset=utf-8');

if (($_GET['key'] ?? '') !== 'econ-mig-food') {
    http_response_code(403);
    exit("forbidden\n");
}

$log = [];

try {
    // ① place_guide
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS place_guide (
            place_id BIGINT UNSIGNED NOT NULL,
            guide    VARCHAR(20)     NOT NULL,   -- 'bluer' | 'michelin' | 'etc' (FoodGuide::DEFS)
            grade    VARCHAR(20)     NULL,       -- bluer:'3'|'2'|'1' / michelin:'3star'..'selected'
            created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (place_id, guide),
            KEY idx_guide (guide, grade),
            CONSTRAINT fk_pguide_place FOREIGN KEY (place_id)
                REFERENCES place(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $log[] = "OK  place_guide 테이블 준비 완료";

    // ② place_tag.kind ENUM 에 'cuisine'(음식) + 'grade'(등급) 추가 (이미 둘 다 있으면 스킵)
    $colType = (string)$pdo->query(
        "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'place_tag' AND COLUMN_NAME = 'kind'"
    )->fetchColumn();

    if ($colType === '') {
        $log[] = "SKIP place_tag.kind 컬럼을 찾을 수 없음 (place_tag 미생성?)";
    } elseif (stripos($colType, "'cuisine'") !== false && stripos($colType, "'grade'") !== false) {
        $log[] = "SKIP place_tag.kind 에 이미 'cuisine','grade' 있음 ({$colType})";
    } else {
        $pdo->exec(
            "ALTER TABLE place_tag
               MODIFY COLUMN kind ENUM('type','theme','month','facet','cuisine','grade')
               NOT NULL DEFAULT 'theme'"
        );
        $log[] = "OK  place_tag.kind 에 'cuisine','grade' 반영 (이전: {$colType})";
    }

    $log[] = "";
    $log[] = "=== 완료. 이 파일을 서버에서 삭제하세요. ===";
} catch (Throwable $e) {
    http_response_code(500);
    $log[] = "ERROR " . $e->getMessage();
}

echo implode("\n", $log) . "\n";
