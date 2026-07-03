<?php
// ==========================================================
// 여행 동영상 인증 스트리밍 프록시 (서비스계정 + Range 지원)
// ----------------------------------------------------------
// 드라이브 폴더가 "제한됨(비공개)"이라 /preview iframe 은 게스트 브라우저에서 열리지 않는다.
// 이 프록시가 요청자를 인가한 뒤, 서비스계정으로 alt=media 스트림을 받아
// 브라우저의 Range 요청(탐색/구간재생)을 그대로 중계한다 → <video src> 로 재생.
//
//  인가 규칙 = travel_thumb.php 와 동일:
//    - 소유자(로그인 세션): 모든 파일 허용
//    - 게스트: 유효 공유토큰(?share=) + 그 여행에 이 fileId 가 속할 때만 허용
//
// 사용: <video src="travel_video.php?id={driveFileId}[&share=token]" controls>
// ==========================================================

require_once __DIR__ . '/env/cnt.inc';        // PDO + 클래스 오토로더
require_once __DIR__ . '/env/auth_fnc.php';   // start_custom_session
if (file_exists(__DIR__ . '/env/gdrive.inc')) require_once __DIR__ . '/env/gdrive.inc';

$id = $_GET['id'] ?? '';
if (!preg_match('/^[A-Za-z0-9_-]{10,200}$/', $id)) {
    http_response_code(400);
    exit;
}

// ── 인가 게이트 (세션은 읽자마자 잠금 해제 — 스트리밍이 세션을 오래 쥐지 않게) ──
start_custom_session();
$owner = !empty($_SESSION['usr_name']);
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

if (!Travel::mediaAuthorized($pdo, $owner, $id, (string)($_GET['share'] ?? ''))) {
    http_response_code(403);
    exit;
}

$tok = GDriveSA::token();
if ($tok === null) {
    http_response_code(502);
    exit;
}

// 브라우저의 Range 를 드라이브로 그대로 전달 → 부분 응답(206) 중계
$hdr = ['Authorization: Bearer ' . $tok];
if (!empty($_SERVER['HTTP_RANGE'])) $hdr[] = 'Range: ' . $_SERVER['HTTP_RANGE'];

// 프록시가 응답을 버퍼링하지 않도록 (대용량 스트림)
while (ob_get_level() > 0) @ob_end_clean();
header('X-Accel-Buffering: no');

$url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($id) . '?alt=media';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => $hdr,
    CURLOPT_FOLLOWLOCATION => true,   // alt=media 는 스토리지로 302 → 따라감(토큰 헤더 유지)
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT        => 0,       // 스트리밍 — 시간제한 없음
    CURLOPT_BUFFERSIZE     => 65536,
    // 응답 헤더 중 필요한 것만 클라이언트로 중계 + 상태코드 동기화(200/206)
    CURLOPT_HEADERFUNCTION => function ($ch, $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            http_response_code((int)$m[1]);   // 체인의 마지막(최종) 응답 코드가 남음
            return strlen($h);
        }
        $l = trim($h);
        foreach (['Content-Type', 'Content-Length', 'Content-Range', 'Accept-Ranges'] as $k) {
            if (stripos($l, $k . ':') === 0) { header($l, true); break; }
        }
        return strlen($h);
    },
    // 본문은 받는 즉시 클라이언트로 흘려보냄
    CURLOPT_WRITEFUNCTION  => function ($ch, $data) {
        echo $data;
        flush();
        return strlen($data);
    },
]);
curl_exec($ch);
curl_close($ch);
?>
