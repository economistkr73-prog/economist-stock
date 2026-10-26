<?php
/**
 * stock/lib/research_api.php — 리서치 API (stock/api.php?module=rs&action=…)  2026-10-15
 *
 * 응답은 전부 JSON {ok, data, error}. DB·AI 추출은 classes/Rs.class 가 전담한다. 화면은 stock/lib/research.php.
 *
 * ★바꾸는 action 은 POST 만 · 같은 출처만 받는다(module=wg·mv 와 같은 규칙).
 * ★원문은 «파일 조각»(multipart 의 md)으로 받는다 — 이 서버 앞단(openresty)이 일반 POST 본문을 128KB 에서 자른다
 *   (업무캘린더 첨부에서 실측). 한글은 URL 인코딩하면 세 배로 불어나 40KB 문서도 걸린다. 파일 조각은 16MB 까지 통과한다.
 * ★AI 를 부르는 문은 extract 하나다(올릴 때 한 번 · Haiku). 같은 원문이면 부르지 않고 그 버전을 알려 준다.
 */
function api_rs(string $action, PDO $pdo): void
{
    header('Content-Type: application/json; charset=utf-8');

    $out = static function (bool $ok, $data = null, string $error = '') {
        echo json_encode(['ok' => $ok, 'data' => $data, 'error' => $error], JSON_UNESCAPED_UNICODE);
        exit;
    };

    $in  = $_POST + $_GET;
    $int = static fn(string $k): int => (int)($in[$k] ?? 0);
    $str = static fn(string $k): string => is_scalar($in[$k] ?? '') ? (string)($in[$k] ?? '') : '';

    $READS = ['marks', 'get', 'raw', 'prompt'];      // 그 밖(tags_apply 포함)은 POST·같은 출처
    if (!in_array($action, $READS, true)) {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $out(false, null, 'POST 로 보내 주세요.');
        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''))) {
            $out(false, null, '다른 출처의 요청은 받지 않습니다.');
        }
    }

    $rs = new Rs($pdo);

    try {
        if ($action === 'schema') {                 // DDL 은 여기(와 CLI)에서만
            $rs->ensureTables();
            $out(true, ['ready' => $rs->ready()]);
        }
        if (!$rs->ready()) {
            // 차트 층은 표가 없어도 조용히 비운다(빈 층) — 화면을 죽이지 않는다
            if ($action === 'marks') $out(true, ['notes' => []]);
            $out(false, null, '리서치 표가 아직 없습니다 — 리서치 화면의 「표 만들기」를 먼저 눌러 주세요.');
        }

        switch ($action) {
            /* 차트의 리서치 층 — 한 종목의 버전(오래된 것이 앞)과 그 가격 */
            case 'marks':
                $out(true, ['notes' => $rs->marks(Rs::cleanCode($str('code')))]);

            /* 원문 받기(.md) — 올린 글자 그대로 */
            case 'raw': {
                $n = $rs->get($int('id'));
                if (!$n) $out(false, null, '없는 리서치입니다.');
                $fname = $n['code'] . '_' . $n['d'] . '.md';
                $title = preg_replace('/[\\\\\/:*?"<>|]+/u', ' ', (string)$n['title']);
                header('Content-Type: text/markdown; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $fname . '"; filename*=UTF-8\'\'' . rawurlencode(trim($title) . '.md'));
                echo $n['body'];
                exit;
            }

            /* 일정 탭의 「검증 요청문」(2026-10-26) — 그 버전의 재점검 요청문 + 맨 앞에 «이번에 검증할 일정»(items = 그 묶음의 줄 id) */
            case 'prompt': {
                require_once __DIR__ . '/sue.php';
                require_once __DIR__ . '/research.php';
                $n = $rs->get($int('id'));
                if (!$n) $out(false, null, '없는 리서치입니다.');
                $want = array_flip(array_map('intval', explode(',', $str('items'))));
                $focus = ['when' => '', 'items' => []];
                foreach ($n['items'] as $it) {
                    if (!isset($want[(int)$it['id']]) || !in_array($it['kind'], ['event', 'check'], true)) continue;
                    if ($focus['when'] === '' && $it['d']) $focus['when'] = rs_date_label((string)$it['d'], (string)$it['prec']);
                    $focus['items'][] = ['kind' => (string)$it['kind'], 'text' => (string)$it['text'], 'done' => (int)$it['done'] === 1];
                }
                if (!$focus['items']) $out(false, null, '그 일정이 이 리서치에 없습니다(새 버전이 올라왔으면 화면을 새로 고쳐 주세요).');
                usort($focus['items'], fn($a, $b) => ($a['kind'] === 'event' ? 0 : 1) <=> ($b['kind'] === 'event' ? 0 : 1));
                $code = (string)$n['code'];
                $name = StockName::one($pdo, $code, $code);
                $px = rs_prices($pdo, [$code])[$code]['price'] ?? null;
                $text = rs_review_prompt($pdo, $n, $name, $px, rs_stale($pdo, $n, $px), $focus);
                $out(true, ['text' => $text, 'title' => '일정 검증 요청문 — ' . $name . ' ' . $focus['when']]);
            }

            case 'get': {
                $n = $rs->get($int('id'));
                if (!$n) $out(false, null, '없는 리서치입니다.');
                unset($n['body']);
                $out(true, ['note' => $n, 'draft' => Rs::toDraft($n),
                            'stock' => ['code' => $n['code'], 'name' => StockName::one($pdo, (string)$n['code'], '')]]);
            }

            /* 올리기 1단계 — 원문 → AI 초안(+ 종목 찾기). 저장하지 않는다(확인 폼이 저장한다) */
            case 'extract': {
                @set_time_limit(90);                 // AI 가 10~20초 읽는다
                $body = rs_api_body($in);
                $dup = $rs->byMd5(md5($body));
                if ($dup) {
                    $out(true, ['dup' => $dup + ['name' => StockName::one($pdo, (string)$dup['code'], '')]]);
                }
                $r = $rs->extract($body);
                $draft = $r['ok'] ? $r['draft'] : Rs::normalizeDraft([]);
                if ($draft['as_of'] === '') $draft['as_of'] = date('Y-m-d');
                $found = $rs->resolveStock($draft['stock_name'], $draft['stock_code']);
                // 그 종목 화면에서 올렸으면 그 종목이 이긴다 — 문서가 다른 종목을 말하면 화면이 경고한다
                $pre = Rs::cleanCode($str('code'));
                $stock = $pre !== '' ? ['code' => $pre, 'name' => StockName::one($pdo, $pre, '')] : $found;
                $out(true, [
                    'draft' => $draft, 'stock' => $stock, 'found' => $found,
                    'ai' => ['ok' => $r['ok'], 'msg' => $r['msg'], 'model' => $r['model'] ?? '', 'usage' => $r['usage'] ?? []],
                    'ai_json' => $r['ok'] ? json_encode(['raw' => $r['raw'] ?? null, 'usage' => $r['usage'] ?? null,
                                                         'model' => $r['model'] ?? ''], JSON_UNESCAPED_UNICODE) : '',
                    'bytes' => strlen($body),
                ]);
            }

            /* 올리기 2단계(새 버전) · 수정(id) — 확인 폼의 값으로 저장한다. 수정은 원문을 다시 보내지 않는다 */
            case 'save': {
                $id = $int('id');
                $draft = json_decode($str('draft'), true);
                if (!is_array($draft)) $out(false, null, '값을 읽지 못했습니다.');
                $code = Rs::cleanCode($str('code'));
                $body = $id > 0 ? '' : rs_api_body($in);
                $nid  = $rs->save($draft, $code, $body, $str('src_name'), $id > 0 ? '' : $str('ai_json'),
                                  $id > 0 ? '' : $str('ai_model'), $id);
                $out(true, ['id' => $nid, 'code' => $code,
                            'url' => '/stock/index.php?mode=research&code=' . $code . '&id=' . $nid]);
            }

            case 'delete': {
                $code = $rs->delete($int('id'));
                if ($code === null) $out(false, null, '없는 리서치입니다.');
                $left = $rs->latestMany([$code]);
                $out(true, ['code' => $code,
                            'url' => $left ? '/stock/index.php?mode=research&code=' . $code : '/stock/index.php?mode=research']);
            }

            /* 이 버전의 사업 태그를 종목 태그(재무분석 「종목 개요」·스크리너 &tag=)에 더한다 — 누를 때만.
             * ★더하기만 한다(내가 단 태그는 안 지운다) · 종목 개요의 「사업 개요」가 비어 있을 때만 이 버전의 사업 개요로 채운다.
             * 저장 입구는 종목 개요의 단일본 pf_note_save_with_hist(히스토리 규칙 그대로). */
            case 'tags_apply': {
                require_once __DIR__ . '/note.php';
                $n = $rs->get($int('id'));
                if (!$n) $out(false, null, '없는 리서치입니다.');
                $code = (string)$n['code'];
                $add  = Rs::tagList((string)$n['tags']);
                $biz  = trim((string)$n['biz']);
                if (!$add && $biz === '') $out(false, null, '이 버전에는 사업 태그·개요가 없습니다.');
                $note = pf_note_get($pdo, $code);
                $old  = pf_tags_of($pdo, [$code])[$code] ?? [];
                $merged = pf_tag_parse(implode(' ', array_merge($old, $add)));
                $sum  = trim((string)($note['summary'] ?? ''));
                $fill = $sum === '' && $biz !== '';
                pf_note_save_with_hist($pdo, $code, (string)($note['headline'] ?? ''), $fill ? $biz : $sum, $merged);
                $low  = array_map('mb_strtolower', $old);
                $added = array_values(array_filter($merged, fn($t) => !in_array(mb_strtolower($t), $low, true)));
                $mlow = array_map('mb_strtolower', $merged);
                $dropped = array_values(array_filter($add, fn($t) => !in_array(mb_strtolower($t), $mlow, true)));  // 12개 상한에 걸린 것
                $out(true, ['added' => $added, 'dropped' => $dropped, 'summary_filled' => $fill, 'tags' => $merged]);
            }

            /* 확인 신호 체크 — 사용자가 「확인했다」고 표시하는 것(판정이 아니다) */
            case 'check':
                if (!$rs->setCheck($int('item'), $int('done') === 1)) $out(false, null, '없는 확인 신호입니다.');
                $out(true, ['item' => $int('item'), 'done' => $int('done') === 1]);

            /* 일정 탭의 ✓·체크칸(2026-10-26) — 일정(event)·확인 신호(check) 여러 줄을 한 번에 */
            case 'done': {
                $ids = array_map('intval', explode(',', $str('items')));
                $cnt = $rs->setDoneMany($ids, $int('done') === 1);
                if ($cnt === 0) $out(false, null, '없는 일정입니다(새 버전이 올라왔으면 화면을 새로 고쳐 주세요).');
                $out(true, ['n' => $cnt, 'done' => $int('done') === 1]);
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

/**
 * 올린 원문 — multipart 의 md(파일 조각)가 정본이고, 없으면 text 필드(짧은 붙여넣기).
 * ★PDF·바이너리는 받지 않는다 — 원문은 Markdown 텍스트로 쌓는다(사용자 결정 2026-10-15).
 */
function rs_api_body(array $in): string
{
    $raw = '';
    if (!empty($_FILES['md']['tmp_name']) && is_uploaded_file($_FILES['md']['tmp_name'])) {
        if ((int)$_FILES['md']['size'] > Rs::BODY_MAX) throw new InvalidArgumentException('문서가 너무 깁니다(300KB 까지).');
        $raw = (string)file_get_contents($_FILES['md']['tmp_name']);
    } elseif (isset($in['text']) && is_string($in['text'])) {
        $raw = $in['text'];
    }
    if (strncmp($raw, '%PDF', 4) === 0) {
        throw new InvalidArgumentException('PDF 는 받지 않습니다 — Claude 문서를 Markdown(.md)으로 내보내 올려 주세요.');
    }
    if (!mb_check_encoding($raw, 'UTF-8')) throw new InvalidArgumentException('글자를 읽지 못했습니다 — UTF-8 Markdown(.md) 파일만 받습니다.');
    $body = Rs::normBody($raw);
    if ($body === '') throw new InvalidArgumentException('문서가 비었습니다.');
    if (strlen($body) > Rs::BODY_MAX) throw new InvalidArgumentException('문서가 너무 깁니다(300KB 까지).');
    return $body;
}
?>
