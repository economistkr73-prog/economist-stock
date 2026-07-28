<?php
// ==========================================================
// 영상 썸네일 인증 프록시 + 디스크 캐시  (travel_thumb.php 패턴)
// ----------------------------------------------------------
// "영상" 폴더가 비공개(제한됨)라 공개 썸네일 URL 은 열리지 않는다.
// 이 프록시가 요청자를 인가한 뒤 서비스계정(GDriveSA)으로 썸네일 바이트를
// 받아 ./cache/thumbs 에 저장하고 서빙한다.
//
//  인가 규칙: 로그인 소유자 + fileId 가 tbl_vod 에 존재할 때만 허용. 그 외 403.
//
// 사용: <img src="vod_thumb.php?id={driveFileId}&w=640">
// ==========================================================

require_once __DIR__ . '/env/cnt.inc';        // PDO + 클래스 오토로더
require_once __DIR__ . '/env/auth_fnc.php';   // start_custom_session
if (file_exists(__DIR__ . '/env/gdrive.inc')) require_once __DIR__ . '/env/gdrive.inc';

$id = $_GET['id'] ?? '';
$w  = (int)($_GET['w'] ?? 640);

$allowed = [200, 400, 640, 800, 1000, 1600];
if (!in_array($w, $allowed, true)) $w = 640;

if (!preg_match('/^[A-Za-z0-9_-]{10,200}$/', $id)) {
    http_response_code(400);
    exit;
}

// ── 인가 게이트 (세션은 읽자마자 잠금 해제 — 동시 이미지 요청 직렬화 방지) ──
start_custom_session();
$owner = !empty($_SESSION['usr_name']);
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

if (!Vod::mediaAuthorized($pdo, $owner, $id, (string)($_GET['share'] ?? ''))) {
    http_response_code(403);
    exit;
}

$cacheDir  = __DIR__ . '/cache/thumbs';
$cacheFile = $cacheDir . '/vod_' . $id . '_w' . $w . '.img';

// ── 캐시 히트 → 즉시 서빙 ──
if (is_file($cacheFile) && filesize($cacheFile) > 0) {
    vod_serve($cacheFile);
    exit;
}

// ── 캐시 미스 → SA 로 썸네일 확보 → 저장 ──
$data = vod_fetch_sa($id, $w);
if ($data !== '' && strlen($data) > 100) {
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
    @file_put_contents($cacheFile, $data, LOCK_EX);

    header('Content-Type: ' . vod_mime($data));
    header('Cache-Control: private, max-age=2592000');
    header('Content-Length: ' . strlen($data));
    echo $data;
    exit;
}

http_response_code(404);
exit;

function vod_serve(string $file): void
{
    $head = (string)file_get_contents($file, false, null, 0, 16);
    header('Content-Type: ' . vod_mime($head));
    header('Cache-Control: private, max-age=2592000');
    header('Content-Length: ' . filesize($file));
    readfile($file);
}

function vod_mime(string $bytes): string
{
    if (strncmp($bytes, "\x89PNG", 4) === 0)                     return 'image/png';
    if (strncmp($bytes, 'GIF8', 4) === 0)                        return 'image/gif';
    if (strlen($bytes) >= 12 && substr($bytes, 8, 4) === 'WEBP') return 'image/webp';
    return 'image/jpeg';
}

/** 서비스계정으로 썸네일 바이트 확보 (files.get?fields=thumbnailLink → 크기조정 URL). */
function vod_fetch_sa(string $id, int $w): string
{
    $tok = GDriveSA::token();
    if ($tok === null) return '';
    $auth = ['Authorization: Bearer ' . $tok];

    $meta = vod_curl(
        'https://www.googleapis.com/drive/v3/files/' . rawurlencode($id) . '?fields=thumbnailLink',
        $auth
    );
    $j    = json_decode($meta, true);
    $link = $j['thumbnailLink'] ?? '';

    if ($link !== '') {
        if (preg_match('/=[sw]\d+(-[a-z]+)?$/i', $link)) {
            $link = preg_replace('/=[sw]\d+(-[a-z]+)?$/i', '=w' . $w, $link);
        } else {
            $link .= '=w' . $w;
        }
        $img = vod_curl($link, []);
        if ($img !== '' && strlen($img) > 100) return $img;
    }
    return '';
}

function vod_curl(string $url, array $headers): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'vod-thumb-cache',
        CURLOPT_HTTPHEADER     => $headers ?: [],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($body !== false && $code >= 200 && $code < 300) ? (string)$body : '';
}
?>
