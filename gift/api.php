<?php
/**
 * gift/api.php — 선물 발송 관리 AJAX 엔드포인트 (module + action)
 *
 * 규약: 모든 응답은 JSON. 쓰기(POST)는 CSRF 토큰을 요구한다.
 * DB 접근은 전부 classes/Gift.class 를 경유한다 — 여기에 SQL 을 적지 않는다.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/cnt.inc';
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/auth_fnc.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$module = $_GET['module'] ?? $_POST['module'] ?? '';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

/** 화면과 같은 토큰 (index.php 의 gift_csrf 와 한 몸) */
function gapi_csrf_ok(): bool
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return true;
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return !empty($_SESSION['gift_csrf']) && hash_equals((string)$_SESSION['gift_csrf'], (string)$sent);
}

function gapi_out(array $d): void
{
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
}

function gapi_fail(string $msg, int $code = 400): void
{
    http_response_code($code);
    gapi_out(['ok' => false, 'msg' => $msg]);
}

/** POST 로 온 id 배열 (ids[] 또는 콤마 문자열) */
function gapi_ids($v): array
{
    if (is_array($v)) return array_map('intval', $v);
    return array_map('intval', array_filter(explode(',', (string)$v), 'strlen'));
}

if (!gapi_csrf_ok()) { gapi_fail('요청이 만료되었습니다. 새로고침 후 다시 시도하세요.', 419); exit; }

try {
    $gift = new Gift($pdo);

    switch ($module) {
        case 'customer': gapi_customer($action, $gift); break;
        case 'group':    gapi_group($action, $gift);    break;
        case 'product':  gapi_product($action, $gift);  break;
        case 'batch':    gapi_batch($action, $gift);    break;
        case 'item':     gapi_item($action, $gift);     break;
        default:         gapi_fail("unknown module: {$module}");
    }
} catch (InvalidArgumentException | RuntimeException $e) {
    // 사용자가 고칠 수 있는 오류 — 그대로 안내한다
    gapi_fail($e->getMessage());
} catch (Throwable $e) {
    // DB 오류 등은 일반 메시지만. 상세는 서버 로그로.
    error_log('[gift/api] ' . $e->getMessage());
    gapi_fail('처리 중 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.', 500);
}

// ==========================================================
// 고객 명단
// ==========================================================
function gapi_customer(string $action, Gift $gift): void
{
    switch ($action) {
        case 'get':
            $c = $gift->customerGet((int)($_GET['id'] ?? 0));
            $c ? gapi_out(['ok' => true, 'row' => $c]) : gapi_fail('고객을 찾을 수 없습니다.');
            return;

        case 'search':   // 대상 추가 모달용
            $r = $gift->customerList([
                'q'    => $_GET['q'] ?? '',
                'addr' => $_GET['addr'] ?? '',
                'grp'  => $_GET['grp'] ?? '',
                'size' => 500,
            ]);
            gapi_out(['ok' => true, 'rows' => $r['rows'], 'total' => $r['total']]);
            return;

        case 'save':
            $id = $gift->customerSave($_POST);
            gapi_out(['ok' => true, 'id' => $id, 'row' => $gift->customerGet($id)]);
            return;

        case 'delete':
            $gift->customerDelete((int)($_POST['id'] ?? 0));
            gapi_out(['ok' => true]);
            return;

        case 'dirSearch':   // 인명록 검색
            gapi_out(['ok' => true, 'rows' => $gift->directorySearch((string)($_GET['q'] ?? ''), 200)]);
            return;

        case 'dirImport':
            $n = $gift->directoryImport(gapi_ids($_POST['ids'] ?? []));
            gapi_out(['ok' => true, 'n' => $n, 'msg' => "{$n}명을 고객 명단에 등록했습니다."]);
            return;

        case 'zipFill':
            // 우편번호 조회는 외부 REST 라 느리다 → 화면이 몇 명씩 나눠서 반복 호출한다(응답 30초 제한).
            // skip = 앞 배치에서 못 찾은 사람들 (다시 물어봐야 결과가 같다)
            $rows    = $gift->customersMissingZip((int)($_POST['limit'] ?? 8), gapi_ids($_POST['skip'] ?? []));
            $filled  = 0;
            $fail    = [];
            $guessed = [];
            foreach ($rows as $c) {
                $r = Zipcode::lookup(trim($c['address1'] . ' ' . $c['address2']));
                if ($r['ok']) {
                    $gift->customerSetZip((int)$c['id'], $r['zip']);
                    $filled++;
                    // 지번 없이 건물명으로 찾은 건은 단지·동이 갈릴 수 있다 → 사람이 확인하도록 따로 모은다
                    if ($r['src'] === 'kakao-keyword') {
                        $guessed[] = ['name' => $c['name'], 'zip' => $r['zip'], 'road' => $r['road']];
                    }
                } else {
                    // 못 찾은 사람은 그대로 둔다 — 틀린 우편번호를 채우는 것보다 빈 칸이 낫다
                    $fail[] = ['id' => (int)$c['id'], 'name' => $c['name'], 'address' => $c['address1']];
                }
            }
            gapi_out([
                'ok'      => true,
                'done'    => count($rows),
                'filled'  => $filled,
                'fail'    => $fail,
                'guessed' => $guessed,
                'remain'  => $gift->countMissingZip(),
            ]);
            return;
    }
    gapi_fail("unknown action: {$action}");
}

// ==========================================================
// 고객 분류 그룹
// ==========================================================
function gapi_group(string $action, Gift $gift): void
{
    switch ($action) {
        case 'list':
            gapi_out(['ok' => true, 'rows' => $gift->groupList()]);
            return;

        case 'save':
            $id = $gift->groupSave($_POST);
            gapi_out(['ok' => true, 'id' => $id]);
            return;

        case 'reorder':   // 끌어 옮긴 순서를 그대로 저장
            $n = $gift->groupReorder(gapi_ids($_POST['ids'] ?? []));
            gapi_out(['ok' => true, 'n' => $n]);
            return;

        case 'delete':
            // 그룹만 지운다 — 속해 있던 고객은 미분류로 남는다
            $gift->groupDelete((int)($_POST['id'] ?? 0));
            gapi_out(['ok' => true]);
            return;

        case 'assign':   // 선택한 고객들을 한 그룹으로
            $n = $gift->customerSetGroup(gapi_ids($_POST['ids'] ?? []), (int)($_POST['group_id'] ?? 0));
            gapi_out(['ok' => true, 'n' => $n, 'msg' => "{$n}명의 그룹을 바꿨습니다."]);
            return;
    }
    gapi_fail("unknown action: {$action}");
}

// ==========================================================
// 물품
// ==========================================================
function gapi_product(string $action, Gift $gift): void
{
    switch ($action) {
        case 'list':
            gapi_out(['ok' => true, 'rows' => $gift->productList(!empty($_GET['active']))]);
            return;

        case 'save':
            $id  = $gift->productSave($_POST);
            $out = ['ok' => true, 'id' => $id, 'row' => $gift->productGet($id)];

            // 단가를 바꿨고 사용자가 원하면 작업중 회차에도 일괄 반영 (확정 회차는 불변)
            if (!empty($_POST['apply_draft'])) {
                $out['applied'] = $gift->productApplyToDraft($id, Gift::num($_POST['unit_price'] ?? 0));
            }
            gapi_out($out);
            return;

        case 'toggle':
            $gift->productToggle((int)($_POST['id'] ?? 0), (int)($_POST['active'] ?? 0));
            gapi_out(['ok' => true]);
            return;

        case 'draftUse':   // 이 물품이 작업중 회차에서 몇 줄이나 쓰이는지 (단가 반영 여부를 묻기 전에)
            $draft = $gift->batchDraft();
            $n = 0;
            if ($draft) {
                foreach ($gift->items((int)$draft['id']) as $it) {
                    if ((int)$it['product_id'] === (int)($_GET['id'] ?? 0)) $n++;
                }
            }
            gapi_out(['ok' => true, 'n' => $n, 'batch' => $draft['title'] ?? '']);
            return;
    }
    gapi_fail("unknown action: {$action}");
}

// ==========================================================
// 회차
// ==========================================================
function gapi_batch(string $action, Gift $gift): void
{
    switch ($action) {
        case 'create':
            $id = $gift->batchCreate($_POST);
            gapi_out(['ok' => true, 'id' => $id]);
            return;

        case 'saveMeta':
            $gift->batchSaveMeta((int)($_POST['id'] ?? 0), $_POST);
            gapi_out(['ok' => true]);
            return;

        case 'delete':
            $gift->batchDelete((int)($_POST['id'] ?? 0));
            gapi_out(['ok' => true]);
            return;

        case 'confirm':
            $id = (int)($_POST['id'] ?? 0);
            $r  = $gift->batchConfirm($id);
            gapi_out(['ok' => $r['ok'], 'msg' => $r['msg'], 'bad' => $r['bad'], 'id' => $id]);
            return;

        case 'summary':
            $id = (int)($_GET['id'] ?? 0);
            gapi_out(['ok' => true, 'summary' => $gift->summary($id), 'bad' => $gift->invalidItems($id)]);
            return;
    }
    gapi_fail("unknown action: {$action}");
}

// ==========================================================
// 발송 라인
// ==========================================================
function gapi_item(string $action, Gift $gift): void
{
    switch ($action) {
        case 'list':
            gapi_out(['ok' => true, 'rows' => $gift->items((int)($_GET['batch_id'] ?? 0))]);
            return;

        case 'save':   // 행 단위 인라인 저장
            $row = $gift->itemSave((int)($_POST['id'] ?? 0), $_POST);
            gapi_out([
                'ok'      => true,
                'row'     => $row,
                'summary' => $gift->summary((int)$row['batch_id']),
                'bad'     => $gift->invalidItems((int)$row['batch_id']),
            ]);
            return;

        case 'saveAll':   // 임시저장 — 화면의 모든 행을 한 번에
            $rows = json_decode((string)($_POST['rows'] ?? '[]'), true);
            if (!is_array($rows)) { gapi_fail('전송된 내용을 읽을 수 없습니다.'); return; }
            $bid = 0;
            foreach ($rows as $r) {
                $id = (int)($r['id'] ?? 0);
                if ($id <= 0) continue;
                $saved = $gift->itemSave($id, $r);
                $bid   = (int)$saved['batch_id'];
            }
            if ($bid === 0) $bid = (int)($_POST['batch_id'] ?? 0);
            gapi_out([
                'ok'      => true,
                'n'       => count($rows),
                'summary' => $gift->summary($bid),
                'bad'     => $gift->invalidItems($bid),
            ]);
            return;

        case 'add':
            $bid = (int)($_POST['batch_id'] ?? 0);
            $n   = $gift->itemsAdd($bid, gapi_ids($_POST['ids'] ?? []), (int)($_POST['product_id'] ?? 0));
            gapi_out(['ok' => true, 'n' => $n, 'msg' => "{$n}명을 추가했습니다."]);
            return;

        case 'addPrev':   // 이전 회차에서 복사
            $bid = (int)($_POST['batch_id'] ?? 0);
            $n   = $gift->itemsAddFromPrev($bid);
            gapi_out(['ok' => true, 'n' => $n, 'msg' => $n ? "이전 회차에서 {$n}명을 불러왔습니다." : '불러올 이전 회차가 없습니다.']);
            return;

        case 'bulk':
            $bid = (int)($_POST['batch_id'] ?? 0);
            $n   = $gift->itemBulk($bid, gapi_ids($_POST['ids'] ?? []), (string)($_POST['op'] ?? ''), $_POST);
            gapi_out([
                'ok'      => true,
                'n'       => $n,
                'summary' => $gift->summary($bid),
                'bad'     => $gift->invalidItems($bid),
            ]);
            return;
    }
    gapi_fail("unknown action: {$action}");
}
?>
