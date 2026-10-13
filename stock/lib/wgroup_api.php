<?php
/**
 * stock/lib/wgroup_api.php — 관심그룹 API (stock/api.php?module=wg&action=…)
 *
 * 전부 JSON 이다 — 응답 {ok, data, error}. 입력은 JSON 본문(POST) 또는 폼/쿼리.
 * DB 는 classes/Wg.class 가 전담하고 여기서는 입력을 꺼내 넘기기만 한다.
 *
 * ★바꾸는 action 은 POST 만 받고 «같은 출처»에서 온 것만 받는다(Origin 검사).
 * ★장중 시세는 «보고 있는 그룹»만 받는다 — Dt::refreshQuotesLive() 에 그 그룹의 코드를 넘긴다.
 *   문지기(장중·거래일·신선도 TICK_SEC)·저장 자리(all_stock_info)는 단타와 같은 단일본이다.
 *   전 그룹을 한꺼번에 받지 않는다 — 13그룹 × 50종목이면 10초마다 7콜이 된다.
 */
function api_wg(string $action, PDO $pdo): void
{
    header('Content-Type: application/json; charset=utf-8');

    $out = static function (bool $ok, $data = null, string $error = '') {
        echo json_encode(['ok' => $ok, 'data' => $data, 'error' => $error], JSON_UNESCAPED_UNICODE);
        exit;
    };

    // 입력 — JSON 본문이 있으면 그것이 이긴다
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
        if (!is_array($v)) $v = explode(',', (string)$v);
        return array_values($v);
    };

    $READS = ['group_list', 'group_stats', 'item_list', 'quote_batch', 'item_parse'];
    if (!in_array($action, $READS, true)) {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $out(false, null, 'POST 로 보내 주세요.');
        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''))) {
            $out(false, null, '다른 출처의 요청은 받지 않습니다.');
        }
    }

    $wg = new Wg($pdo);

    /* 고정 그룹(보유종목·Top-Pick)은 «보기 전용»이다 — 주인이 따로 있다(pf_position · pf_watchlist).
     * 화면이 손잡이를 잠그지만 서버도 다시 막는다. */
    $known = static fn(int $gid): bool => Wg::isBuiltin($gid) || $wg->groupExists($gid);
    if (in_array($action, ['item_add', 'sep_add', 'bulk_base', 'item_reorder', 'table_add'], true) && Wg::isBuiltin($int('group_id'))) {
        $out(false, null, '고정 그룹은 여기서 편집할 수 없습니다.');
    }

    /* 장중 시세 — 그 그룹의 종목만. 실패해도 조용히 넘어간다(목록은 DB 값으로 뜬다) */
    $live = static function (int $gid) use ($wg, $pdo): string {
        try {
            $codes = array_slice($wg->codes($gid), 0, Wg::QUOTE_MAX);
            if (!$codes) return 'none';
            $r = (new Dt($pdo))->refreshQuotesLive(null, false, $codes);
            return (string)($r['src'] ?? 'none');
        } catch (Throwable $e) {
            return 'none';
        }
    };

    try {
        switch ($action) {
            // DDL 은 여기(와 CLI)에서만 — 화면·다른 action 은 표를 만들지 않는다
            case 'schema':
                $wg->ensureTables();
                $out(true, ['ready' => $wg->ready()]);

            // ── 그룹 ────────────────────────────────────────────────
            case 'group_list':
                $out(true, ['groups' => $wg->groupsAll()]);

            /* 그룹 목록의 «평균 등락» — 화면이 Wg::STAT_SEC 마다 부른다(장중 · 탭이 보일 때만).
             * 고정 그룹 셋(보유종목·Top-Pick·최근조회)은 곧 «늘 보는 종목»(Dt::targetCodes)이라 그 시세만 다시 받는다 —
             * STAT_SEC 보다 낡았을 때만 · 장 밖·휴장일엔 문지기가 막는다 · 단타 화면이 이미 받고 있으면 0콜(100종목 = 키움 한 콜).
             * ★내가 만든 그룹의 시세는 받지 않는다 — 그 평균은 all_stock_info 에 있는 값(크론 주기)이고 화면이 그렇게 적는다.
             *   그룹 수만큼 종목이 불어나는 것을 타이머에 걸면 「보는 종목만 촘촘히」가 깨진다. 그 그룹을 누르면 그때 최신이 된다. */
            case 'group_stats': {
                $src = 'none';
                try { $src = (string)((new Dt($pdo))->refreshQuotesLive(Wg::STAT_SEC)['src'] ?? 'none'); }
                catch (Throwable $e) { /* 낡은 값으로 계속 */ }
                $out(true, ['groups' => $wg->groupsAll(), 'ts' => date('H:i:s'), 'src' => $src]);
            }

            case 'group_create': {
                $id = $wg->groupCreate($str('name'));
                $out(true, ['id' => $id, 'groups' => $wg->groupsAll()]);
            }
            case 'group_rename':
                $wg->groupRename($int('id'), $str('name'));
                $out(true, ['groups' => $wg->groupsAll()]);

            case 'group_delete':
                $wg->groupDelete($int('id'));
                $out(true, ['groups' => $wg->groupsAll()]);

            case 'group_reorder':
                $wg->groupReorder($list('ids'));
                $out(true, ['groups' => $wg->groupsAll()]);

            // ── 아이템 ──────────────────────────────────────────────
            case 'item_list': {
                $gid = $int('group_id');
                if (!$known($gid)) $out(false, null, '그룹을 찾지 못했습니다.');
                $src = !empty($in['live']) ? $live($gid) : 'none';
                // 테마 그룹이면 연결한 변수도 함께 — 그룹을 바꿀 때 한 번에 온다(값은 module=mv series 가 따로 준다)
                $vars = []; $vall = [];
                try { $m = new Mv($pdo); $vars = $m->groupVars($gid); $vall = $m->vars(); } catch (Throwable $e) { /* 변수 표가 없으면 없이 */ }
                $out(true, ['items' => $wg->rows($gid), 'groups' => $wg->groupsAll(), 'vars' => $vars, 'vall' => $vall,
                            'ts' => date('H:i:s'), 'src' => $src]);
            }

            /* 시세만 — 10초 폴링이 부른다. 그룹 id 를 받는다(코드 목록을 화면에서 받지 않는다 —
             * 받으면 이 문이 「아무 종목이나 10초마다 키움에 묻는 문」이 된다). */
            case 'quote_batch': {
                $gid = $int('group_id');
                if (!$known($gid)) $out(false, null, '그룹을 찾지 못했습니다.');
                $src = $live($gid);
                $out(true, ['quotes' => $wg->quotes($wg->codes($gid)), 'ts' => date('H:i:s'), 'src' => $src]);
            }

            case 'item_add': {
                $gid = $int('group_id');
                $r   = $wg->itemAdd($gid, $list('codes'));
                $out(true, $r + ['items' => $wg->rows($gid), 'groups' => $wg->groupsAll()]);
            }

            case 'sep_add': {
                $gid = $int('group_id');
                $id  = $wg->sepAdd($gid, $str('label') !== '' ? $str('label') : '구분', $int('before_id'));
                $out(true, ['id' => $id, 'items' => $wg->rows($gid)]);
            }

            case 'item_update': {
                $f = [];
                foreach (['base_price', 'memo', 'label', 'dir', 'var_id'] as $k) {
                    if (array_key_exists($k, $in)) $f[$k] = $in[$k];
                }
                if (!$f) $out(false, null, '바꿀 값이 없습니다.');
                $wg->itemUpdate($int('id'), $f);
                $it = $wg->itemGet($int('id'));
                $out(true, ['id' => $int('id'),
                            'base'      => $it && $it['base_price'] !== null ? (int)$it['base_price'] : null,
                            'base_date' => $it['base_date'] ?? null,
                            'memo'      => (string)($it['memo'] ?? ''),
                            'memo_at'   => $it['memo_at'] ?? null,
                            'label'     => (string)($it['label'] ?? ''),
                            'dir'       => (int)($it['dir'] ?? 0),
                            'var_id'    => (int)($it['var_id'] ?? 0)]);
            }

            case 'bulk_base': {
                $gid = $int('group_id');
                $n   = $wg->bulkBase($gid, $str('mode'), $int('section_id'));
                $out(true, ['n' => $n, 'items' => $wg->rows($gid)]);
            }

            case 'item_delete': {
                $n = $wg->itemDelete($list('ids'));
                $out(true, ['n' => $n, 'groups' => $wg->groupsAll()]);
            }

            case 'item_reorder':
                $wg->itemReorder($int('group_id'), $list('ids'));
                $out(true, null);

            case 'item_copy':
            case 'item_move': {
                /* 고정 그룹에서 나오는 것은 «복사»뿐이다 — 행이 wg_item 이 아니라 id 가 없어 종목코드로 받는다 */
                $src = $int('source_group_id');
                if (Wg::isBuiltin($src)) {
                    if ($action === 'item_move') $out(false, null, '고정 그룹의 종목은 옮길 수 없습니다 — 복사만 됩니다.');
                    $r = $wg->copyFromBuiltin($src, $list('codes'), $int('target_group_id'));
                    $out(true, $r + ['groups' => $wg->groupsAll()]);
                }
                $r = $wg->transfer($list('ids'), $int('target_group_id'), $action === 'item_move');
                $out(true, $r + ['groups' => $wg->groupsAll()]);
            }

            /* 미리보기 — 표(종목 │ 근거 …)로 읽히면 table 이 붙는다: 근거는 메모로, 「~ 때 유리」 줄은 구분행으로 담긴다 */
            case 'item_parse': {
                $text = mb_substr($str('text'), 0, 20000);
                $gid  = $int('group_id');
                $t    = $wg->parseTable($text, $gid);
                if (!$t) $out(true, $wg->parse($text, $gid));
                $ok = [];
                foreach ($t['secs'] as $s) foreach ($s['rows'] as $r) $ok[] = ['code' => $r['code'], 'name' => $r['name'], 'dup' => $r['dup']];
                $have = $gid > 0 ? count($wg->codes($gid)) : 0;
                $out(true, ['ok' => $ok, 'miss' => $t['miss'], 'room' => max(0, Wg::ITEM_MAX - $have),
                            'table' => ['memos' => $t['memos'],
                                        'secs'  => array_values(array_map(static fn($s) => ['label' => $s['label'], 'dir' => $s['dir'], 'n' => count($s['rows'])],
                                                                array_filter($t['secs'], static fn($s) => $s['label'] !== '')))]]);
            }

            case 'table_add': {
                $gid = $int('group_id');
                $r   = $wg->tableAdd($gid, mb_substr($str('text'), 0, 20000));
                $out(true, $r + ['items' => $wg->rows($gid), 'groups' => $wg->groupsAll()]);
            }

            default:
                $out(false, null, '알 수 없는 action 입니다.');
        }
    } catch (InvalidArgumentException $e) {
        $out(false, null, $e->getMessage());          // 입력이 틀린 것 — 그대로 말해 준다
    } catch (Throwable $e) {
        $out(false, null, '처리하지 못했습니다: ' . $e->getMessage());
    }
}
?>
