<?php
/**
 * reserve/api.php — 예약 AJAX (action)
 *
 * 규약: 응답은 JSON. 전부 POST + CSRF. DB 접근은 classes/Reserve.class 경유 — 여기에 SQL 을 적지 않는다.
 * ★캠핏 호출은 여기서 하지 않는다 — 서버 IP 는 Cloudflare 에 막힌다(Reserve.class 헤더). 화면(브라우저)이 직접 부른다.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/cnt.inc';
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/auth_fnc.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

function rsapi_out(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); }
function rsapi_fail(string $msg, int $code = 400): void
{
    http_response_code($code);
    rsapi_out(['ok' => false, 'msg' => $msg]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { rsapi_fail('POST 만 받습니다.', 405); exit; }
$sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($_SESSION['rsv_csrf']) || !hash_equals((string)$_SESSION['rsv_csrf'], (string)$sent)) {
    rsapi_fail('요청이 만료되었습니다. 새로고침 후 다시 시도하세요.', 419);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    $rs = new Reserve($pdo);

    switch ($action) {

        /* 표 만들기 — 화면의 「표 만들기」 버튼(표가 없을 때만 보인다) */
        case 'schema':
            $rs->ensureTables();
            rsapi_out(['ok' => true, 'msg' => '표를 만들었습니다.']);
            return;

        /* 내 캠핑장에 담기 — 값은 화면이 캠핏에서 받아 온 스냅샷 */
        case 'camp_add': {
            $id = $rs->campAdd((string)($_POST['ext_id'] ?? ''), [
                'name'    => $_POST['name'] ?? '',
                'region'  => $_POST['region'] ?? '',
                'address' => $_POST['address'] ?? '',
                'contact' => $_POST['contact'] ?? '',
                'thumb'   => $_POST['thumb'] ?? '',
            ]);
            rsapi_out(['ok' => true, 'id' => $id, 'camps' => $rs->campList()]);
            return;
        }

        /* 빈자리 한 달 기록 — 화면이 캠핏에서 받은 그대로 넘긴다(서버는 캠핏을 못 부른다 · 중계 저장).
         * 직전과 같으면 확인 시각만 고친다 · newly = 직전 «다른» 기록 대비 새로 난 자리 ("siteId|date") */
        case 'snap_save': {
            $raw = (string)($_POST['data'] ?? '');
            if (strlen($raw) > 100000) { rsapi_fail('기록이 너무 큽니다.'); return; }
            $p = json_decode($raw, true);
            if (!is_array($p)) { rsapi_fail('기록 형식이 아닙니다.'); return; }
            $r = $rs->snapSave((string)($_POST['ext_id'] ?? ''), (string)($_POST['ym'] ?? ''), $p);
            rsapi_out(['ok' => true] + $r);
            return;
        }

        /* 내가 고른 사이트 — 캠핑장별 한 줄을 통째로 덮는다 (sites = 쉼표로 이은 사이트 id) */
        case 'pick_save': {
            $ids = array_filter(explode(',', (string)($_POST['sites'] ?? '')));
            $saved = $rs->pickSave((string)($_POST['ext_id'] ?? ''), $ids);
            rsapi_out(['ok' => true, 'sites' => $saved]);
            return;
        }

        case 'camp_del':
            $rs->campDel((string)($_POST['ext_id'] ?? ''));
            rsapi_out(['ok' => true, 'camps' => $rs->campList()]);
            return;

        default:
            rsapi_fail("unknown action: {$action}");
    }
} catch (InvalidArgumentException | RuntimeException $e) {
    rsapi_fail($e->getMessage());
} catch (Throwable $e) {
    error_log('[reserve/api] ' . $e->getMessage());
    rsapi_fail('처리 중 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.', 500);
}
?>
