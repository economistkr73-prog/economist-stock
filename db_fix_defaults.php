<?php
// db_fix_defaults.php — all_stock_info / all_etf_price 의 DEFAULT 없는 NOT NULL 컬럼을 자동 수정
// 실행 후 삭제할 것

require_once "./env/cnt.inc";

$secret = $_GET['ssk'] ?? '';
if ($secret !== 'mysn1973!') die("접근 권한이 없습니다.");

$tables = ['all_stock_info', 'all_etf_price', 'all_etf_holdings_info'];

foreach ($tables as $table) {
    echo "<h3>{$table}</h3>";

    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME, DATA_TYPE, COLUMN_DEFAULT, IS_NULLABLE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = ?
          AND IS_NULLABLE  = 'NO'
          AND COLUMN_DEFAULT IS NULL
          AND COLUMN_KEY NOT IN ('PRI')
        ORDER BY ORDINAL_POSITION
    ");
    $stmt->execute([$table]);
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($cols)) {
        echo "✅ DEFAULT 없는 NOT NULL 컬럼 없음<br>";
        continue;
    }

    foreach ($cols as $col) {
        $colName  = $col['COLUMN_NAME'];
        $dataType = strtoupper($col['DATA_TYPE']);

        // 타입별 기본값 결정
        if (in_array($dataType, ['INT','BIGINT','SMALLINT','TINYINT','MEDIUMINT','FLOAT','DOUBLE','DECIMAL'])) {
            $default = '0';
        } elseif (in_array($dataType, ['VARCHAR','CHAR','TEXT','TINYTEXT','MEDIUMTEXT','LONGTEXT'])) {
            $default = "''";
        } elseif ($dataType === 'DATE') {
            $default = "'0000-00-00'";
        } elseif (in_array($dataType, ['DATETIME','TIMESTAMP'])) {
            $default = "'0000-00-00 00:00:00'";
        } else {
            $default = '0';
        }

        try {
            $pdo->exec("ALTER TABLE `{$table}` ALTER COLUMN `{$colName}` SET DEFAULT {$default}");
            echo "✅ {$colName} ({$dataType}) → DEFAULT {$default} 설정<br>";
        } catch (Exception $e) {
            echo "❌ {$colName} 실패: " . $e->getMessage() . "<br>";
        }
    }
}

echo "<br><b>완료. 이 파일을 서버에서 삭제하세요.</b>";
?>
