<?php
require_once "./env/cnt.inc";

$errors = [];
$done   = [];

$sqls = [
    // 1. tbl_project 생성
    "tbl_project 생성" => "
        CREATE TABLE IF NOT EXISTS tbl_project (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            title      VARCHAR(100) NOT NULL,
            type       ENUM('group','project') DEFAULT 'group',
            color      CHAR(7)      DEFAULT '#3498db',
            icon       VARCHAR(10)  DEFAULT NULL,
            memo       TEXT,
            start_dt   DATE         DEFAULT NULL COMMENT 'project 타입 전용',
            end_dt     DATE         DEFAULT NULL COMMENT 'project 타입 전용',
            is_done    TINYINT(1)   DEFAULT 0,
            sort_order INT          DEFAULT 0,
            created_at DATETIME     DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",

    // 2. tbl_schedule 에 project_id 추가
    "tbl_schedule.project_id 추가" => "
        ALTER TABLE tbl_schedule
        ADD COLUMN IF NOT EXISTS project_id INT DEFAULT NULL
            COMMENT '소속 프로젝트/그룹 ID'
    ",

    // 3. FK — IF NOT EXISTS 미지원이므로 기존 FK 확인 후 조건부 추가
    "tbl_schedule FK 추가" => "
        ALTER TABLE tbl_schedule
        ADD CONSTRAINT fk_schedule_project
        FOREIGN KEY (project_id) REFERENCES tbl_project(id) ON DELETE SET NULL
    ",
];

foreach ($sqls as $label => $sql) {
    try {
        $pdo->exec($sql);
        $done[] = "✅ " . $label;
    } catch (PDOException $e) {
        $errors[] = "❌ " . $label . " — " . $e->getMessage();
    }
}

// 결과 출력
header('Content-Type: text/plain; charset=utf-8');
echo "=== tbl_project 셋업 결과 ===\n\n";
foreach ($done   as $d) echo $d . "\n";
foreach ($errors as $e) echo $e . "\n";

// 컬럼 확인
echo "\n--- tbl_project 컬럼 ---\n";
$cols = $pdo->query("DESCRIBE tbl_project")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) echo $c['Field'] . " / " . $c['Type'] . "\n";

echo "\n--- tbl_schedule project_id 확인 ---\n";
$cols = $pdo->query("SHOW COLUMNS FROM tbl_schedule LIKE 'project_id'")->fetchAll(PDO::FETCH_ASSOC);
echo $cols ? "project_id 컬럼 존재: " . $cols[0]['Type'] . "\n" : "project_id 없음\n";
?>
