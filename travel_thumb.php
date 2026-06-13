<?php
// ==========================================================
// 여행 썸네일 프록시 + 디스크 캐시 (목록 표지 로딩 가속용)
// ----------------------------------------------------------
// drive.google.com/thumbnail 응답을 1회 받아 ./cache/thumbs 에 저장 → 이후 서버가 즉시 서빙.
// 드라이브 썸네일은 "링크 공개" 전제(API 키 읽기와 동일 수준)라 별도 인증 없이 동작한다.
// fileId 를 화이트리스트로 검증해 임의 URL 호출(SSRF)을 차단한다.
// 사용: <img src="travel_thumb.php?id={driveFileId}&w=400">
// ==========================================================

$id = $_GET['id'] ?? '';
$w  = (int)($_GET['w'] ?? 400);

// 허용 폭만 (임의 값으로 캐시 파일이 무한 증식하는 것 방지)
$allowed = [200, 400, 800, 1000, 1600];
if (!in_array($w, $allowed, true)) $w = 400;

// 드라이브 fileId 형식만 허용 (경로조작·SSRF 차단)
if (!preg_match('/^[A-Za-z0-9_-]{10,200}$/', $id)) {
    http_response_code(400);
    exit;
}

$src       = 'https://drive.google.com/thumbnail?id=' . rawurlencode($id) . '&sz=w' . $w;
$cacheDir  = __DIR__ . '/cache/thumbs';
$cacheFile = $cacheDir . '/' . $id . '_w' . $w . '.img';

// ── 캐시 히트 → 서버 디스크에서 즉시 서빙 ──
if (is_file($cacheFile) && filesize($cacheFile) > 0) {
    tv_serve($cacheFile);
    exit;
}

// ── 캐시 미스 → 드라이브에서 1회 내려받아 저장 ──
$data = tv_fetch($src);
if ($data !== '' && strlen($data) > 100) {
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
    @file_put_contents($cacheFile, $data, LOCK_EX);

    header('Content-Type: ' . tv_mime($data));
    header('Cache-Control: public, max-age=2592000');   // 30일 — 같은 fileId 썸네일은 불변
    header('Content-Length: ' . strlen($data));
    echo $data;
    exit;
}

// ── 내려받기 실패 → 드라이브 원본 URL 로 폴백 리다이렉트 (깨진 이미지 방지) ──
header('Location: ' . $src);
exit;

/** 캐시 파일을 알맞은 헤더로 서빙 */
function tv_serve(string $file): void
{
    $head = (string)file_get_contents($file, false, null, 0, 16);
    header('Content-Type: ' . tv_mime($head));
    header('Cache-Control: public, max-age=2592000');
    header('Content-Length: ' . filesize($file));
    readfile($file);
}

/** 매직바이트로 이미지 MIME 추론 (드라이브 썸네일은 보통 jpeg, 간혹 png/webp) */
function tv_mime(string $bytes): string
{
    if (strncmp($bytes, "\x89PNG", 4) === 0)               return 'image/png';
    if (strncmp($bytes, 'GIF8', 4) === 0)                  return 'image/gif';
    if (strlen($bytes) >= 12 && substr($bytes, 8, 4) === 'WEBP') return 'image/webp';
    return 'image/jpeg';
}

/** 드라이브 썸네일 1회 GET (리다이렉트 추적, 타임아웃 포함) */
function tv_fetch(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (travel-thumb-cache)',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($body !== false && $code >= 200 && $code < 300) ? (string)$body : '';
}
?>
