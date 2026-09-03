<?php
/**
 * recipe/api.php — 레시피 모음 AJAX (action)
 *
 * 규약: 응답은 JSON. 쓰기(POST)는 CSRF. DB 접근은 classes/Recipe.class 경유 — 여기에 SQL 을 적지 않는다.
 * ★외부 호출은 둘뿐 — 등록 시 메타(oEmbed/og) 1회 + 태그(Haiku) 1회, 그리고 「태그 다시」의 Haiku 1회.
 *   크론이 없으니 사용자가 누르지 않으면 한 콜도 안 나간다.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/cnt.inc';
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/auth_fnc.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

function rapi_out(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); }
function rapi_fail(string $msg, int $code = 400): void
{
    http_response_code($code);
    rapi_out(['ok' => false, 'msg' => $msg]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { rapi_fail('POST 만 받습니다.', 405); exit; }
$sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($_SESSION['rc_csrf']) || !hash_equals((string)$_SESSION['rc_csrf'], (string)$sent)) {
    rapi_fail('요청이 만료되었습니다. 새로고침 후 다시 시도하세요.', 419);
    exit;
}

$action = $_POST['action'] ?? '';

/** 화면이 보낸 태그 JSON([{name,kind},…]) → 배열 */
function rapi_tags($raw): array
{
    $a = json_decode((string)$raw, true);
    if (!is_array($a)) return [];
    $out = [];
    foreach ($a as $t) {
        if (!is_array($t)) continue;
        $out[] = ['name' => (string)($t['name'] ?? ''), 'kind' => (string)($t['kind'] ?? 'etc')];
    }
    return $out;
}

try {
    $rc = new Recipe($pdo);

    switch ($action) {

        /* URL 하나로 등록 — 정규화 → 중복 확인 → 메타 → AI 태그 → 저장.
         * 메타·태그가 실패해도 «등록은 된다»(제목은 URL 로, 태그는 비움) — 모바일에서 다시 붙여넣게 하지 않는다. */
        case 'add': {
            $n = Recipe::normalizeUrl((string)($_POST['url'] ?? ''));
            $dup = $rc->findByKey($n['key']);
            if ($dup) { rapi_out(['ok' => true, 'dup' => true, 'id' => (int)$dup['id'], 'msg' => '이미 담겨 있습니다: ' . $dup['title']]); return; }

            $meta = Recipe::fetchMeta($n);
            $warn = [];
            if (!$meta['ok']) $warn[] = $meta['msg'];

            $ai = ['ok' => false, 'dish' => '', 'tags' => []];
            if ($meta['title'] !== '') {
                $ai = $rc->tagSuggest($meta['title'], $meta['channel'], $meta['desc']);
                if (!$ai['ok']) $warn[] = $ai['msg'];
            }
            $id = $rc->add($n, $meta, $ai['dish'] ?? '');
            if (!empty($ai['tags'])) $rc->tagSet($id, $ai['tags']);

            rapi_out(['ok' => true, 'id' => $id, 'title' => $meta['title'] !== '' ? $meta['title'] : $n['url'],
                      'dish' => $ai['dish'] ?? '', 'tags' => $ai['tags'] ?? [], 'warn' => $warn,
                      'msg' => '담았습니다' . ($warn ? ' (' . implode(' · ', $warn) . ')' : '')]);
            return;
        }

        /* 제목·요리명·메모·태그 고치기 — 태그는 통째로 갈아 끼운다 */
        case 'update': {
            $id = (int)($_POST['id'] ?? 0);
            if (!$rc->get($id)) { rapi_fail('레시피를 찾을 수 없습니다.'); return; }
            $rc->update($id, (string)($_POST['title'] ?? ''), (string)($_POST['dish'] ?? ''), (string)($_POST['memo'] ?? ''));
            if (isset($_POST['tags'])) $rc->tagSet($id, rapi_tags($_POST['tags']));
            rapi_out(['ok' => true, 'msg' => '저장']);
            return;
        }

        /* 태그 다시(Haiku 1콜) — 지금 제목·채널·설명으로 다시 뽑아 «갈아 끼운다» */
        case 'retag': {
            $id = (int)($_POST['id'] ?? 0);
            $r  = $rc->get($id);
            if (!$r) { rapi_fail('레시피를 찾을 수 없습니다.'); return; }
            $ai = $rc->tagSuggest((string)$r['title'], (string)$r['channel'], (string)($r['descr'] ?? ''));
            if (!$ai['ok']) { rapi_fail($ai['msg'], 502); return; }
            $rc->tagSet($id, $ai['tags']);
            if ($ai['dish'] !== '' && (string)$r['dish'] === '') $rc->update($id, (string)$r['title'], $ai['dish'], (string)($r['memo'] ?? ''));
            rapi_out(['ok' => true, 'tags' => $ai['tags'], 'dish' => $ai['dish'], 'msg' => '태그 ' . count($ai['tags']) . '개']);
            return;
        }

        case 'del': {
            $id = (int)($_POST['id'] ?? 0);
            $r  = $rc->get($id);
            if (!$r) { rapi_fail('레시피를 찾을 수 없습니다.'); return; }
            $rc->del($id);
            rapi_out(['ok' => true, 'msg' => '삭제: ' . $r['title']]);
            return;
        }

        /* 태그 이름·종류 고치기(같은 이름이 있으면 병합) — 칩 줄의 ✏️ */
        case 'tag_rename': {
            $rc->tagRename((int)($_POST['id'] ?? 0), (string)($_POST['name'] ?? ''), (string)($_POST['kind'] ?? 'etc'));
            rapi_out(['ok' => true, 'msg' => '태그 저장']);
            return;
        }

        /* 최초 1회 표 만들기 — 매 요청 DDL 금지 규칙 때문에 명시적 행동으로만 */
        case 'schema':
            $rc->ensureTables();
            rapi_out(['ok' => true, 'msg' => '표 준비 완료']);
            return;

        default:
            rapi_fail("unknown action: {$action}");
    }
} catch (InvalidArgumentException | RuntimeException $e) {
    rapi_fail($e->getMessage());
} catch (Throwable $e) {
    error_log('[recipe/api] ' . $e->getMessage());
    rapi_fail('처리 중 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.', 500);
}
?>
