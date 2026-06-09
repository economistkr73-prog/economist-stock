<?php
require_once "./env/cnt.inc";
if (($_GET['ssk'] ?? '') !== 'mysn1973!') die("접근 권한이 없습니다.");

header('Content-Type: text/plain; charset=utf-8');

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

foreach ($tables as $table) {
    $row = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
    echo $row['Create Table'] . ";\n\n";
}
?>
