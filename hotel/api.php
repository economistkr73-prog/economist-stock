<?php
/**
 * hotel/api.php — 아고다 호텔 가격 추적 AJAX (module + action)
 *
 * 규약: 응답은 JSON. 쓰기(POST)는 CSRF. DB 접근은 classes/Agoda.class 경유 — 여기에 SQL 을 적지 않는다.
 * ★적재(매일 쌓기)는 크론(cron/agoda_track.php)의 일이다 — 여기의 실호출은
 *   「등록 시 hotel_id 추출」과 「즉석 조회(check)」 둘뿐이고, 즉석 조회는 DB 에 안 남긴다.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/cnt.inc';
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/auth_fnc.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

function hapi_out(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); }
function hapi_fail(string $msg, int $code = 400): void
{
    http_response_code($code);
    hapi_out(['ok' => false, 'msg' => $msg]);
}

/** "1,234.5" · "$95" 같은 입력을 float 로 (빈 값 = null) */
function hapi_usd($v): ?float
{
    $s = str_replace([',', '$', ' '], '', (string)$v);
    if ($s === '') return null;
    if (!is_numeric($s) || (float)$s <= 0) throw new InvalidArgumentException('목표가가 숫자가 아닙니다.');
    return round((float)$s, 2);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (empty($_SESSION['ag_csrf']) || !hash_equals((string)$_SESSION['ag_csrf'], (string)$sent)) {
        hapi_fail('요청이 만료되었습니다. 새로고침 후 다시 시도하세요.', 419);
        exit;
    }
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    $ag = new Agoda($pdo);

    switch ($action) {

        /* URL 붙여넣기 등록 — hotel_id 추출(페이지 1회) → 호텔 등록.
         * URL 에 checkIn 이 있으면 그 날짜의 특정일 추적도 함께 만든다(목표가는 그때만 의미). */
        case 'add': {
            $url    = (string)($_POST['url'] ?? '');
            $target = hapi_usd($_POST['target'] ?? '');
            $x = Agoda::extract($url);
            if (!$x['ok']) { hapi_fail($x['msg']); return; }

            $hid = $ag->hotelAdd($x['agoda_id'], $x['name'], $url);
            $msg = '호텔 등록: ' . ($x['name'] !== '' ? $x['name'] : '#' . $x['agoda_id']);
            if ($x['checkin'] !== '' && $x['checkin'] >= date('Y-m-d')) {
                $ag->trackAdd($hid, $x['checkin'], $x['nights'], $x['adults'], $target);
                $msg .= " + {$x['checkin']} 추적";
                if ($target !== null) $msg .= ' (목표 $' . number_format($target, 2) . ')';
            } elseif ($target !== null) {
                $msg .= ' — 목표가는 날짜 추적에만 붙습니다 (URL 에 checkIn 이 없어 무시)';
            }
            hapi_out(['ok' => true, 'msg' => $msg, 'hotel_id' => $hid]);
            return;
        }

        case 'del': {
            $h = $ag->hotelGet((int)($_POST['id'] ?? 0));
            if (!$h) { hapi_fail('호텔을 찾을 수 없습니다.'); return; }
            $ag->hotelDel((int)$h['id']);
            hapi_out(['ok' => true, 'msg' => '삭제: ' . $h['name']]);
            return;
        }

        case 'track_add': {
            $hid = (int)($_POST['hotel_id'] ?? 0);
            if (!$ag->hotelGet($hid)) { hapi_fail('호텔을 찾을 수 없습니다.'); return; }
            $ag->trackAdd($hid, (string)($_POST['checkin'] ?? ''),
                (int)($_POST['nights'] ?? 1), (int)($_POST['adults'] ?? 2),
                hapi_usd($_POST['target'] ?? ''));
            hapi_out(['ok' => true, 'msg' => '추적 등록 — 다음 크론부터 매일 쌓입니다.']);
            return;
        }

        case 'track_del':
            $ag->trackDel((int)($_POST['id'] ?? 0));
            hapi_out(['ok' => true, 'msg' => '추적 종료 (이력은 남습니다)']);
            return;

        case 'target':
            $ag->trackTarget((int)($_POST['id'] ?? 0), hapi_usd($_POST['target'] ?? ''));
            hapi_out(['ok' => true, 'msg' => '목표가 저장']);
            return;

        /* 즉석 조회 — 지금 1콜. DB 에 안 남긴다(추적 등록과 다른 것). 2~5초 걸린다. */
        case 'check': {
            $h = $ag->hotelGet((int)($_GET['hotel'] ?? 0));
            if (!$h) { hapi_fail('호텔을 찾을 수 없습니다.'); return; }
            $ci = (string)($_GET['checkin'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ci)) { hapi_fail('체크인 날짜가 필요합니다.'); return; }
            $r = Agoda::fetch((int)$h['agoda_id'], $ci,
                max(1, (int)($_GET['nights'] ?? 1)), max(1, (int)($_GET['adults'] ?? 2)));
            if (!$r['ok']) { hapi_fail('아고다 응답 실패: ' . $r['msg'], 502); return; }
            hapi_out(['ok' => true, 'checkin' => $ci, 'min' => $r['min'], 'minRoom' => $r['minRoom'],
                      'soldout' => $r['soldout'], 'rooms' => $r['rooms']]);
            return;
        }

        default:
            hapi_fail("unknown action: {$action}");
    }
} catch (InvalidArgumentException | RuntimeException $e) {
    hapi_fail($e->getMessage());
} catch (Throwable $e) {
    error_log('[hotel/api] ' . $e->getMessage());
    hapi_fail('처리 중 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.', 500);
}
?>
