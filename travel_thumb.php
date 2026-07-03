<?php
// ==========================================================
// 여행 썸네일 인증 프록시 + 디스크 캐시
// ----------------------------------------------------------
// 드라이브 폴더가 "제한됨(비공개)"이므로 공개 썸네일 URL 은 더 이상 열리지 않는다.
// 이 프록시가 요청자를 인가한 뒤, 서비스계정(GDriveSA)으로 썸네일 바이트를
// 받아 ./cache/thumbs 에 저장하고 서빙한다.
//
//  인가 규칙:
//    - 소유자(로그인 세션): 모든 파일 허용
//    - 게스트: 유효 공유토큰(?share=) + 그 여행에 이 fileId 가 속할 때만 허용
//  그 외(비로그인·잘못된 토큰·다른 여행 파일) → 403
//
// 사용: <img src="travel_thumb.php?id={driveFileId}&w=400[&share=token]">
// ==========================================================

require_once __DIR__ . '/env/cnt.inc';        // PDO + 클래스 오토로더
require_once __DIR__ . '/env/auth_fnc.php';   // start_custom_session
if (file_exists(__DIR__ . '/env/gdrive.inc')) require_once __DIR__ . '/env/gdrive.inc';

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

// ── 인가 게이트 (세션은 읽자마자 잠금 해제 — 동시 이미지 요청 직렬화 방지) ──
start_custom_session();
$owner = !empty($_SESSION['usr_name']);
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

if (!Travel::mediaAuthorized($pdo, $owner, $id, (string)($_GET['share'] ?? ''))) {
    http_response_code(403);
    exit;
}

$cacheDir  = __DIR__ . '/cache/thumbs';
$cacheFile = $cacheDir . '/' . $id . '_w' . $w . '.img';

// ── 캐시 히트 → 서버 디스크에서 즉시 서빙 ──
if (is_file($cacheFile) && filesize($cacheFile) > 0) {
    tv_serve($cacheFile);
    exit;
}

// ── 캐시 미스 → 서비스계정으로 썸네일 바이트 확보 → 저장 ──
$data = tv_fetch_sa($id, $w);
if ($data !== '' && strlen($data) > 100) {
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
    @file_put_contents($cacheFile, $data, LOCK_EX);

    header('Content-Type: ' . tv_mime($data));
    header('Cache-Control: private, max-age=2592000');   // 비공개 — 브라우저 캐시만(공유 캐시 금지)
    header('Content-Length: ' . strlen($data));
    echo $data;
    exit;
}

http_response_code(404);
exit;

/** 캐시 파일을 알맞은 헤더로 서빙 */
function tv_serve(string $file): void
{
    $head = (string)file_get_contents($file, false, null, 0, 16);
    header('Content-Type: ' . tv_mime($head));
    header('Cache-Control: private, max-age=2592000');
    header('Content-Length: ' . filesize($file));
    readfile($file);
}

/** 매직바이트로 이미지 MIME 추론 (드라이브 썸네일은 보통 jpeg, 간혹 png/webp) */
function tv_mime(string $bytes): string
{
    if (strncmp($bytes, "\x89PNG", 4) === 0)                     return 'image/png';
    if (strncmp($bytes, 'GIF8', 4) === 0)                        return 'image/gif';
    if (strlen($bytes) >= 12 && substr($bytes, 8, 4) === 'WEBP') return 'image/webp';
    return 'image/jpeg';
}

/**
 * 서비스계정으로 썸네일 바이트 확보.
 *   ① files.get?fields=thumbnailLink → 크기 조정(=w{폭}) → 그 URL 에서 바이트(토큰 내장, 가벼움)
 *   ② thumbnailLink 없으면 alt=media 로 원본 다운로드(리사이즈 없이 서빙 — 폴백)
 */
function tv_fetch_sa(string $id, int $w): string
{
    $tok = GDriveSA::token();
    if ($tok === null) return '';
    $auth = ['Authorization: Bearer ' . $tok];

    $meta = tv_curl(
        'https://www.googleapis.com/drive/v3/files/' . rawurlencode($id) . '?fields=thumbnailLink',
        $auth
    );
    $j    = json_decode($meta, true);
    $link = $j['thumbnailLink'] ?? '';

    if ($link !== '') {
        // 구글 썸네일 링크 끝의 크기 지시자(=s220 / =w220 / =s220-c)를 원하는 폭으로 교체
        if (preg_match('/=[sw]\d+(-[a-z]+)?$/i', $link)) {
            $link = preg_replace('/=[sw]\d+(-[a-z]+)?$/i', '=w' . $w, $link);
        } else {
            $link .= '=w' . $w;
        }
        $img = tv_curl($link, []);   // 토큰이 URL 에 내장됨 — 별도 헤더 불필요
        if ($img !== '' && strlen($img) > 100) return $img;
    }

    // 폴백: 원본 전체 다운로드 (썸네일을 못 얻는 파일에 한함)
    $raw = tv_curl('https://www.googleapis.com/drive/v3/files/' . rawurlencode($id) . '?alt=media', $auth);
    return ($raw !== '' && strlen($raw) > 100) ? $raw : '';
}

/** 단순 GET (2xx 만 성공으로 간주, 그 외 ''). */
function tv_curl(string $url, array $headers): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'travel-thumb-cache',
        CURLOPT_HTTPHEADER     => $headers ?: [],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($body !== false && $code >= 200 && $code < 300) ? (string)$body : '';
}
?>
