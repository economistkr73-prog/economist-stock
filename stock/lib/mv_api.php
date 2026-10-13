<?php
/**
 * stock/lib/mv_api.php — 시장 변수 API (stock/api.php?module=mv&action=…)  2026-10-12
 *
 * 관심그룹(mode=wgroup)의 «테마 변수» — 환율·유가·금리 같은 변수를 등록해 그룹에 연결하고 일별 값을 준다.
 * 전부 JSON 이다 — 응답 {ok, data, error}. DB·네이버 수집은 classes/Mv.class 가 전담한다.
 *
 * ★바꾸는 action 은 POST 만 · 같은 출처만 받는다(module=wg 와 같은 규칙).
 * ★네이버에 묻는 문은 셋뿐이다 — ①catalog(목록 · 하루 한 번 파일 캐시) ②var_add(등록할 때 과거 되짚기)
 *   ③series&fresh=1 / var_pull(그 변수가 Mv::STALE_SEC 보다 낡았을 때 한 장). 코드는 목록에 있는 것만 받는다.
 */
function api_mv(string $action, PDO $pdo): void
{
    header('Content-Type: application/json; charset=utf-8');

    $out = static function (bool $ok, $data = null, string $error = '') {
        echo json_encode(['ok' => $ok, 'data' => $data, 'error' => $error], JSON_UNESCAPED_UNICODE);
        exit;
    };

    $in  = $_POST + $_GET;
    $raw = (string)file_get_contents('php://input');
    if ($raw !== '' && $raw[0] === '{') {
        $j = json_decode($raw, true);
        if (is_array($j)) $in = $j + $in;
    }
    $int  = static fn(string $k): int => (int)($in[$k] ?? 0);
    $str  = static fn(string $k): string => is_scalar($in[$k] ?? '') ? (string)($in[$k] ?? '') : '';
    $list = static function (string $k) use ($in): array {
        $v = $in[$k] ?? [];
        if (!is_array($v)) $v = ($v === '' ? [] : explode(',', (string)$v));
        return array_values($v);
    };

    $READS = ['catalog', 'var_list', 'series'];
    if (!in_array($action, $READS, true)) {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $out(false, null, 'POST 로 보내 주세요.');
        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''))) {
            $out(false, null, '다른 출처의 요청은 받지 않습니다.');
        }
    }

    $mv = new Mv($pdo);

    try {
        switch ($action) {
            // DDL 은 여기(와 CLI)에서만 — 구분행의 방향 컬럼(wg_item.dir)도 함께 올린다
            case 'schema':
                (new Wg($pdo))->ensureTables();
                $mv->ensureTables();
                $out(true, ['ready' => $mv->ready()]);

            /* 등록할 수 있는 변수 — 검색칸이 부른다. 이미 등록한 것은 id 가 붙는다 */
            case 'catalog': {
                $have = [];
                foreach ($mv->vars() as $v) $have[$v['cat'] . '|' . $v['code']] = $v['id'];
                $q = trim($str('q'));
                $rows = [];
                foreach ($mv->catalog() as $c) {
                    if ($q !== '' && stripos($c['name'] . ' ' . $c['label'] . ' ' . $c['code'], $q) === false) continue;
                    $c['id'] = $have[$c['cat'] . '|' . $c['code']] ?? 0;
                    $rows[] = $c;
                }
                usort($rows, static fn($a, $b) => ($b['id'] > 0) <=> ($a['id'] > 0));     // 등록한 것이 위
                $out(true, ['items' => array_slice($rows, 0, 40), 'total' => count($rows)]);
            }

            case 'var_list':
                $out(true, ['vars' => $mv->vars(), 'max' => Mv::VAR_MAX]);

            /* 일별 값 — fresh=1 이면 낡은 변수만 한 장(1콜)으로 이은 뒤 준다. 실패해도 있는 값으로 답한다 */
            case 'series': {
                $ids = array_slice(array_map('intval', $list('ids')), 0, 16);   // 그룹의 변수 + 종목별 «같이 볼 변수»
                if (!empty($in['fresh'])) {
                    try { $mv->collect($ids, 20, Mv::STALE_SEC); } catch (Throwable $e) { /* 낡은 값으로 계속 */ }
                }
                $meta = [];
                foreach ($ids as $id) { $v = $mv->varGet($id); if ($v) $meta[] = $v; }
                $out(true, ['series' => $mv->series($ids), 'vars' => $meta]);
            }

            /* 등록 — 목록의 (분류,코드). group_id 를 주면 그 그룹의 맨 뒤에 연결까지 한다 */
            case 'var_add': {
                $r   = $mv->register($str('cat'), $str('code'));
                $gid = $int('group_id');
                if ($gid > 0) {
                    $cur = array_column($mv->groupVars($gid), 'id');
                    if (!in_array($r['id'], $cur, true)) { $cur[] = $r['id']; $mv->groupSet($gid, $cur); }
                }
                $out(true, $r + ['var' => $mv->varGet($r['id']), 'vars' => $gid > 0 ? $mv->groupVars($gid) : []]);
            }

            case 'var_pull': {
                $r = $mv->collect([$int('id')], Mv::PAGE_MAX);
                if ($r['fail']) $out(false, null, '네이버에서 받지 못했습니다 — 잠시 뒤 다시 눌러 주세요.');
                $out(true, $r + ['var' => $mv->varGet($int('id'))]);
            }

            case 'var_delete':
                $mv->varDelete($int('id'));
                $out(true, ['vars' => $mv->vars()]);

            case 'group_set': {
                $gid = $int('group_id');
                $mv->groupSet($gid, $list('ids'));
                $out(true, ['vars' => $mv->groupVars($gid), 'groups' => (new Wg($pdo))->groupsAll()]);
            }

            default:
                $out(false, null, '알 수 없는 action 입니다.');
        }
    } catch (InvalidArgumentException $e) {
        $out(false, null, $e->getMessage());
    } catch (Throwable $e) {
        $out(false, null, '처리하지 못했습니다: ' . $e->getMessage());
    }
}
?>
