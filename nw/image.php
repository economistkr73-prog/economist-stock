<?php
/**
 * nw/image.php — 계약서 원본 이미지 서빙 (로그인 게이트)
 * ?id=N  → nw_contract_image 의 바이너리를 알맞은 Content-Type 으로 출력
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/cnt.inc';
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/auth_fnc.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); exit('bad request'); }

$nw  = new Nw($pdo);
$img = $nw->getContractImage($id);
if (!$img) { http_response_code(404); exit('not found'); }

$mime = $img['mime'] ?: 'image/jpeg';
header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($img['image_data']));
header('Cache-Control: private, max-age=86400');
echo $img['image_data'];
?>
