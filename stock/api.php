<?php
/**
 * stock/api.php — 주식 포트폴리오 API 엔드포인트
 *
 * ?module=xxx&action=yyy 2단계 라우팅. 폼 POST 는 처리 후 화면으로 리다이렉트하고,
 * ajax(json=1) 요청은 JSON 으로 응답한다.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/cnt.inc';
require_once $_SERVER['DOCUMENT_ROOT'] . '/env/auth_fnc.php';
require_once __DIR__ . '/lib/calc.php';
require_once __DIR__ . '/lib/entry.php';   // M4 — 편입 스냅샷 조립 (index.php 와 함께 쓴다)
require_once __DIR__ . '/lib/quant.php';   // 목록 퀀트 배지 판정 (상위 종목 목록과 같은 단일본)
require_once __DIR__ . '/lib/slowlog.php'; // 느린 렌더 계측 — index.php 와 같은 임계·같은 파일
require_login();
pf_slowlog_boot();

$pf = new Pf($pdo);
$pf->ensureTables();
pf_slowlog_mark('ddl');   // 10초 폴링(단타)도 매번 이 DDL 을 지난다 — 잠금 대기 용의 구간

$module = $_GET['module'] ?? $_POST['module'] ?? '';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($module) {
        case 'portfolio': api_portfolio($action, $pdo, $pf); break;
        case 'ruleset':  api_ruleset($action, $pdo, $pf);  break;
        case 'position': api_position($action, $pdo, $pf); break;
        case 'trade':    api_trade($action, $pdo, $pf);    break;
        case 'stock':    api_stock($action, $pdo, $pf);    break;
        case 'setting':  api_setting($action, $pdo, $pf);  break;
        case 'broker':   api_broker($action, $pdo, $pf);   break;
        case 'sim':      api_sim($action, $pdo, $pf);      break;
        case 'dart':     api_dart($action, $pdo, $pf);     break;
        case 'krx':      api_krx($action, $pdo, $pf);      break;
        case 'watch':    api_watch($action, $pdo, $pf);    break;
        case 'note':     api_note($action, $pdo);          break;
        case 'preset':   api_preset($action, $pdo, $pf);   break;
        case 'dt':       api_dt($action, $pdo);            break;
        case 'qm':       api_qm($action, $pdo);            break;
        case 'fav':      api_fav($action, $pdo);           break;
        case 'bx':       api_bx($action, $pdo);            break;
        case 'badge':    api_badge($action, $pdo);         break;
        default:         pf_api_fail('알 수 없는 module 입니다.');
    }
} catch (Throwable $e) {
    pf_api_fail($e->getMessage());
}

// ══ 공통 ════════════════════════════════════════════════════════════════
/** 처리 결과와 함께 화면으로 돌아간다. */
function pf_api_done(string $url, string $kind, string $text): void
{
    if (!empty($_REQUEST['json'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => ($kind === 'ok'), 'message' => $text], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sep = (strpos($url, '?') === false) ? '?' : '&';
    header('Location: ' . $url . $sep . 'msg=' . urlencode($kind . ':' . $text));
    exit;
}

function pf_api_fail(string $text): void
{
    $back = $_POST['back'] ?? $_SERVER['HTTP_REFERER'] ?? '/stock/index.php';
    $back = preg_replace('/[?&]msg=[^&]*/', '', $back);
    pf_api_done($back, 'err', $text);
}

/** POST 배열 파라미터를 인덱스로 안전하게 꺼낸다. */
function pf_arr(string $key, int $i, $default = '')
{
    return $_POST[$key][$i] ?? $default;
}

/** 화면에서 콤마가 붙어 오는 금액 입력을 정수로 (예: "20,000,000" → 20000000) */
function pf_money(string $key, int $default = 0): int
{
    $v = $_POST[$key] ?? '';
    if ($v === '') return $default;
    return (int)preg_replace('/[^0-9\-]/', '', (string)$v);
}

// ══ 포트폴리오 ══════════════════════════════════════════════════════════
function api_portfolio(string $action, PDO $pdo, Pf $pf): void
{
    $back = '/stock/index.php?mode=portfolio';

    switch ($action) {
        case 'save':
            $name = trim($_POST['name'] ?? '');
            $id   = (int)($_POST['id'] ?? 0);
            if ($name === '') {
                pf_api_done($back . ($id ? '&fid=' . $id : ''), 'err', '포트폴리오명을 입력하세요.');
            }

            $save = [
                'id'        => $id ?: null,
                'name'      => $name,
                'broker_id' => (int)($_POST['broker_id'] ?? 0),
                'acct_no'   => trim($_POST['acct_no'] ?? ''),
                'memo'      => trim($_POST['memo'] ?? ''),
                'note'      => mb_substr(trim($_POST['note'] ?? ''), 0, 200),
                'sort_no'   => (int)($_POST['sort_no'] ?? 0),
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
            ];

            $newId = $pf->portfolioSave($save);

            // 원금은 입출금 이력의 합계다. 신규 등록에서 넣은 금액은 첫 줄로 기록한다.
            if (!$id) {
                $seed = pf_money('principal');   // "20,000,000" 같은 입력 허용
                if ($seed > 0) $pf->principalFlowAdd($newId, date('Y-m-d'), $seed, '최초 원금');
            }

            pf_api_done($back . '&fid=' . $newId, 'ok',
                $id ? '포트폴리오를 저장했습니다.' : '포트폴리오를 추가했습니다.');

        case 'reorder':
            // 드래그로 바뀐 순서를 그대로 sort_no 에 넣는다 (0,1,2…)
            $ids = $_POST['id'] ?? [];
            $n   = 0;
            foreach ($ids as $i => $id) {
                $pf->portfolioSort((int)$id, $i);
                $n++;
            }
            pf_api_done($back, 'ok', "순서를 저장했습니다 ({$n}건).");

        case 'delete':
            $id = (int)($_POST['id'] ?? $_POST['del_id'] ?? 0);
            if ($pf->portfolioPositionCount($id) > 0) {
                pf_api_done($back, 'err', '이 포트폴리오에 종목이 남아 있어 삭제할 수 없습니다.');
            }
            $pf->portfolioDelete($id);
            pf_api_done($back, 'ok', '포트폴리오를 삭제했습니다.');

        // ── 원금 입출금 (원금 자체를 고치지 않고 증액/인출을 쌓는다)
        case 'flow_add':
            $fid    = (int)($_POST['fid'] ?? 0);
            $amt    = pf_money('amount');
            $date   = trim($_POST['flow_at'] ?? '') ?: date('Y-m-d');
            $reason = mb_substr(trim($_POST['reason'] ?? ''), 0, 80);
            $out    = (($_POST['kind'] ?? 'in') === 'out');
            $to     = $back . ($fid ? '&fid=' . $fid : '');

            if (!$fid)      pf_api_done($back, 'err', '포트폴리오를 먼저 선택하세요.');
            if ($amt <= 0)  pf_api_done($to, 'err', '금액을 입력하세요.');

            $signed = $out ? -$amt : $amt;
            if ($pf->principalSum($fid) + $signed < 0) {
                pf_api_done($to, 'err', '인출 후 원금이 음수가 됩니다. 금액을 확인하세요.');
            }
            $pf->principalFlowAdd($fid, $date, $signed, $reason);
            pf_api_done($to, 'ok', ($out ? '인출' : '증액') . ' ' . number_format($amt) . '원을 반영했습니다.');

        case 'flow_del':
            $fid = (int)($_POST['fid'] ?? 0);
            $pf->principalFlowDelete((int)($_POST['id'] ?? 0));
            pf_api_done($back . ($fid ? '&fid=' . $fid : ''), 'ok', '원금 변동 기록을 삭제했습니다.');

        /* ── 이월손익·배당 (체결기록으로는 만들 수 없는 돈. 원금과 달리 수익률의 분자다)
         *   금액은 부호를 그대로 받는다 — 배당소득세·이월손실이 음수로 들어온다.
         *   그래서 원금처럼 「합계가 음수면 거절」 하지 않는다(손실이 이익보다 클 수 있다). */
        case 'income_add':
            $fid    = (int)($_POST['fid'] ?? 0);
            $amt    = pf_money('amount');
            $date   = trim($_POST['flow_at'] ?? '') ?: date('Y-m-d');
            $reason = mb_substr(trim($_POST['reason'] ?? ''), 0, 80);
            $kind   = trim($_POST['kind'] ?? 'dividend');
            $to     = $back . ($fid ? '&fid=' . $fid : '');

            if (!$fid)      pf_api_done($back, 'err', '포트폴리오를 먼저 선택하세요.');
            if ($amt === 0) pf_api_done($to, 'err', '금액을 입력하세요.');

            $pf->incomeFlowAdd($fid, $date, $amt, $kind, $reason);
            $label = Pf::INCOME_KINDS[$kind] ?? Pf::INCOME_KINDS['etc'];
            pf_api_done($to, 'ok', $label . ' ' . number_format($amt) . '원을 반영했습니다.');

        case 'income_del':
            $fid = (int)($_POST['fid'] ?? 0);
            $pf->incomeFlowDelete((int)($_POST['id'] ?? 0));
            pf_api_done($back . ($fid ? '&fid=' . $fid : ''), 'ok', '이월·배당 기록을 삭제했습니다.');

        // ── 메모 이력 (수정 없이 신규/삭제만)
        case 'memo_add':
            $fid = (int)($_POST['fid'] ?? 0);
            $txt = mb_substr(trim($_POST['content'] ?? ''), 0, 60);
            if (!$fid)        pf_api_done($back, 'err', '포트폴리오를 먼저 선택하세요.');
            if ($txt === '')  pf_api_done($back . '&fid=' . $fid, 'err', '메모 내용을 입력하세요.');
            $pf->portfolioMemoAdd($fid, $txt);
            pf_api_done($back . '&fid=' . $fid, 'ok', '메모를 남겼습니다.');

        case 'memo_del':
            $fid = (int)($_POST['fid'] ?? 0);
            $pf->portfolioMemoDelete((int)($_POST['id'] ?? 0));
            pf_api_done($back . ($fid ? '&fid=' . $fid : ''), 'ok', '메모를 삭제했습니다.');

        default:
            pf_api_done($back, 'err', '알 수 없는 action 입니다.');
    }
}

// ══ 룰셋 ════════════════════════════════════════════════════════════════
/**
 * 같은 이름이 이미 있으면 뒤에 2, 3 … 을 붙여 비켜 준다.
 * 목록에서 이름이 겹치면 어느 것을 고르는지 알 수 없으므로 저장 단계에서 갈라 둔다.
 */
function pf_unique_name(string $want, array $sets): string
{
    $used = array_map(fn($s) => (string)$s['name'], $sets);
    if (!in_array($want, $used, true)) return $want;

    $n = 2;
    while (in_array($want . ' ' . $n, $used, true)) $n++;
    return $want . ' ' . $n;
}

function api_ruleset(string $action, PDO $pdo, Pf $pf): void
{
    $back = '/stock/index.php?mode=ruleset';

    switch ($action) {
        case 'create':
            $id = $pf->ruleSetSave(['name' => '새 룰셋', 'volatility' => null, 'memo' => '']);
            // 최소 1차수는 있어야 편집이 가능하다
            $pf->ruleStepsReplace($id, [
                ['step_no' => 1, 'weight' => 0.015, 'drop_rate' => 0, 'target_rate' => 0.15],
            ]);
            pf_api_done($back . '&rid=' . $id, 'ok', '새 룰셋을 만들었습니다.');

        /* ── 이름·메모 + 차수를 <b>한 번에</b> 저장한다.
         *
         * 전에는 「이름/메모 저장」(save)과 「차수 저장」(steps)이 <b>각각 다른 폼</b>에 있었다.
         * 폼이 갈리면 브라우저는 누른 버튼이 속한 폼의 값만 보내므로, 차수 표를 고친 뒤
         * 「이름/메모 저장」 을 누르면 <b>차수 수정이 조용히 사라졌다</b> —
         * 저장 뒤 화면이 DB 값으로 다시 그려져 경고조차 없었다. 나눠 둘 실익이 없어 버튼을 하나로 합쳤다.
         *
         * ★ 차수를 지우는 사고를 막는 장치: 차수 표가 실려 온 요청인지 <b>`has_steps`</b> 로 가른다.
         *   - has_steps 있고 차수 0개  → 사용자가 행을 다 지웠다 → 오류로 되돌린다
         *   - has_steps 없음           → 이름·메모만 보낸 요청 → 차수는 손대지 않는다
         *   (둘 다 `step_no` 가 비어 있어서 POST 만으로는 구별할 수 없다)
         */
        case 'save':
            $id    = (int)($_POST['id'] ?? 0);
            $steps = null;

            /* ★ 차수를 <b>먼저 검사</b>한다 — 이름을 저장한 뒤 차수에서 막으면
             *   "오류라는데 이름은 바뀌어 있다"는 반쪽 저장이 된다(실측으로 그랬다). */
            if (isset($_POST['has_steps'])) {
                $steps = [];
                foreach (($_POST['step_no'] ?? []) as $i => $n) {
                    $steps[] = [
                        'step_no'     => $i + 1,   // 화면 순서대로 1..N 재부여
                        'weight'      => (float)pf_arr('weight', $i, 0)      / 100,
                        'drop_rate'   => (float)pf_arr('drop_rate', $i, 0)   / 100,
                        'target_rate' => (float)pf_arr('target_rate', $i, 0) / 100,
                        // 차수 지연(달력일·0=규칙없음). 1차는 직전 매수가 없어 항상 0
                        'delay_days'  => ($i === 0) ? 0 : max(0, (int)pf_arr('delay_days', $i, 0)),
                    ];
                }
                if (!$steps) {
                    pf_api_done($back . '&rid=' . $id, 'err',
                        '차수가 하나도 없습니다 — 아무것도 저장하지 않았습니다.');
                }
            }

            /* ★ 변동율 입력칸은 화면에서 걷어냈다. 그런데 ruleSetSave 는 늘 그 열을 쓰므로
             *   값을 안 보내면 기존 값이 <b>조용히 지워진다</b> — 기능을 뺀 것이 데이터를 지울
             *   이유는 아니므로, 폼에 없으면 DB 의 현재 값을 그대로 다시 넣는다. */
            $vol = array_key_exists('volatility', $_POST)
                ? $_POST['volatility']
                : (($id && ($cur = $pf->ruleSetGet($id))) ? $cur['volatility'] : null);

            $id = $pf->ruleSetSave([
                'id'         => $id,
                'name'       => trim($_POST['name'] ?? ''),
                'volatility' => $vol,
                'memo'       => trim($_POST['memo'] ?? ''),
            ]);

            if ($steps === null) {
                pf_api_done($back . '&rid=' . $id, 'ok', '룰셋 정보를 저장했습니다.');
            }
            $pf->ruleStepsReplace($id, $steps);
            pf_api_done($back . '&rid=' . $id, 'ok', '저장했습니다 (차수 ' . count($steps) . '개).');

        /* ── 자동 생성 결과를 <b>새 룰셋</b>으로 저장한다.
         *
         * 화면(mode=ruleset&gen=1)의 미리보기는 GET 이라 DB 를 안 건드린다. 저장만 여기로 온다.
         * ★ 언제나 <b>새로</b> 만든다 — 쓰는 룰셋을 덮어쓸 길을 아예 두지 않는다.
         * ★ 미리보기와 <b>같은 함수</b>(pf_gen_input/pf_gen_opt/pf_rule_gen)로 다시 계산한다.
         *   폼에서 계산된 차수를 받아 오면 화면과 저장이 갈릴 수 있다. */
        case 'gen':
            $in = pf_gen_input($_REQUEST);
            $g  = pf_rule_gen(pf_gen_opt($in));
            if (empty($g['ok'])) pf_api_done($back . '&gen=1', 'err', $g['error']);

            $name = trim($_POST['name'] ?? '');
            if ($name === '') $name = '자동 ' . (int)$in['n'] . '단계';
            $name = pf_unique_name($name, $pf->ruleSets());

            /* 변동율은 「상정하는 20거래일 변동성」이다 — 자동 생성은 그 값을 모르므로 비워 둔다.
             * (거짓 숫자를 넣으면 실측 대조가 엉뚱한 판정을 낸다) */
            $new = $pf->ruleSetSave([
                'name'       => $name,
                'volatility' => null,
                // 곡률은 소수 둘째 자리까지 남긴다 — 1.15 와 1.20 이 결과를 가르는 값이라 잘리면 재현이 안 된다
                'memo'       => sprintf('자동 생성 · %d단계 · 목표 %+.1f%%→%+.1f%% · 손익분기 %.1f%% · 곡률 %.2f',
                                        (int)$in['n'], $in['ef'], $in['el'], $in['be'], $in['k']),
            ]);

            $rows = [];
            foreach ($g['steps'] as $n => $s) {
                $rows[] = ['step_no' => $n, 'weight' => $s['weight'],
                           'drop_rate' => $s['drop_rate'], 'target_rate' => $s['target_rate']];
            }
            $pf->ruleStepsReplace($new, $rows);
            pf_api_done($back . '&rid=' . $new, 'ok',
                '「' . $name . '」 를 만들었습니다 (' . count($rows) . '차수). 원본 룰셋은 그대로입니다.');

        /* ── 저장된 룰셋을 통째로 복제한다.
         *
         * 룰셋을 이리저리 바꿔 보려면 전에는 새 룰셋을 만들어 차수를 하나씩 다시 입력해야 했다.
         * ★ 「화면에서 고친 뒤 다른 이름으로 저장」 도 만들어 봤다가 <b>걷어냈다</b> —
         *   복제로 같은 일이 되는데, 그쪽은 <b>쓰는 룰셋에 묶인 폼</b>에서 값을 고치게 해서
         *   실수로 「차수 저장」 을 누르면 포지션이 딸린 원본이 바뀐다. 복제가 먼저인 편이 안전하다. */
        case 'copy':
            $src = (int)($_POST['id'] ?? 0);
            $rs  = $src ? $pf->ruleSetGet($src) : null;
            if (!$rs) pf_api_done($back, 'err', '복제할 룰셋을 찾을 수 없습니다.');

            $new = $pf->ruleSetSave([
                'name'       => pf_unique_name((string)$rs['name'] . ' (사본)', $pf->ruleSets()),
                'volatility' => $rs['volatility'],
                'memo'       => (string)$rs['memo'],
            ]);
            $pf->ruleStepsReplace($new, array_values($rs['steps']));
            pf_api_done($back . '&rid=' . $new, 'ok',
                '「' . $rs['name'] . '」 을 복제했습니다. 여기서 고치면 원본은 그대로 남습니다.');

        case 'reorder':
            // 드래그로 바뀐 순서를 그대로 sort_no 에 넣는다 (0,1,2…)
            $ids = $_POST['id'] ?? [];
            $n   = 0;
            foreach ($ids as $i => $id) {
                $pf->ruleSetSort((int)$id, $i);
                $n++;
            }
            pf_api_done($back, 'ok', "순서를 저장했습니다 ({$n}건).");

        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            $pf->ruleSetDelete($id);
            pf_api_done($back, 'ok', '룰셋을 삭제했습니다.');

        case 'simulate':
            // ajax 미리보기 (화면 JS 가 자체 계산하므로 예비용)
            $rid   = (int)($_GET['rid'] ?? 0);
            $limit = (float)str_replace(',', '', (string)($_GET['limit'] ?? 0));
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(pf_simulate($pf->ruleSteps($rid), $limit), JSON_UNESCAPED_UNICODE);
            exit;

        default:
            pf_api_done($back, 'err', '알 수 없는 action 입니다.');
    }
}

// ══ 포지션 ══════════════════════════════════════════════════════════════
function api_position(string $action, PDO $pdo, Pf $pf): void
{
    switch ($action) {
        /* ── 퀀트 사다리 (2026-08-02 · 옛 이름 「박스 사다리」·내부 식별자는 box 그대로)
         *    — 후보 조회 → 비중 계산(미리보기) → 저장/해제.
         * boxlv/boxsolve 는 JSON 을 그대로 돌려준다(모달의 fetch 가 소비 — positions payload 와 같은 방식). */
        case 'boxlv': {
            $code   = preg_replace('/[^0-9]/', '', (string)($_GET['code'] ?? ''));
            // 기간바(160/240/480일·24/48/96주·전체·±)를 개월수로 바꿔 온다 — 2~48 클램프
            $months = (int)($_GET['months'] ?? 6);
            $months = max(2, min(48, $months ?: 6));
            $tf     = (($_GET['tf'] ?? 'day') === 'week') ? 'week' : 'day';
            header('Content-Type: application/json; charset=utf-8');
            if (!preg_match('/^\d{6}$/', $code)) { echo json_encode(['err' => '종목코드가 없습니다.']); exit; }
            try {
                $krx = new KrxAmt($pdo);
                $cands = $tf === 'week' ? $krx->weeklyBoxCandidates($code, $months)
                                        : $krx->dailyBoxCandidates($code, $months);
                echo json_encode(['candidates' => $cands, 'tf' => $tf, 'months' => $months], JSON_UNESCAPED_UNICODE);
            } catch (Throwable $e) {
                echo json_encode(['err' => '박스 조회 실패 — krx 원장을 확인하세요.'], JSON_UNESCAPED_UNICODE);
            }
            exit;
        }
        case 'boxsolve': {
            header('Content-Type: application/json; charset=utf-8');
            $prices = array_map(fn($v) => (float)str_replace(',', '', (string)$v), (array)($_POST['prices'] ?? []));
            $r = pf_box_ladder_build($prices);
            if ($r === null) {
                echo json_encode(['err' => '이 가격들로는 비중이 풀리지 않습니다 — 지지선을 3~5개, 간격이 있게 고르세요.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $r['detail'] = pf_box_ladder_detail($r['levels']);   // 룰셋처럼 차수별 파생값(변동율·누적·평단·손실률)
            echo json_encode($r, JSON_UNESCAPED_UNICODE);
            exit;
        }
        case 'save':
            $id   = (int)($_POST['id'] ?? 0);
            $code = trim($_POST['stock_code'] ?? '');
            $name = trim($_POST['stock_name'] ?? '');

            if ($code === '') pf_api_done('/stock/index.php?mode=position&id=new', 'err', '종목코드를 입력하세요.');

            /* 포트폴리오 미지정 신규 저장 = 편입 관심종목 등록 (2026-08-02 규칙).
             * 포지션(차수 계획)은 포트폴리오가 있어야 뜻이 있다 — 없이 저장한 것은 「편입 대기」로
             * pf_watchlist.adopt_at 에 표시하고, 실제 편입은 pid 폼 하단 목록에서 골라서 한다.
             * ★폼에서 골라 둔 매수방식·룰셋·박스 지지선은 함께 보관한다(「선택」이 그대로 복원) —
             *   한도만 포트폴리오 소속 값이라 편입 때 정한다. */
            if (!$id && (int)($_POST['portfolio_id'] ?? 0) <= 0) {
                if (!preg_match('/^\w{6}$/', $code)) {
                    pf_api_done('/stock/index.php?mode=position&id=new', 'err', '종목을 검색해서 골라 주세요.');
                }
                $label = trim($_POST['stock_name'] ?? '') ?: $code;
                $bxp = [];
                foreach ((array)($_POST['box_prices'] ?? []) as $v) {
                    $v = (float)str_replace(',', '', (string)$v);
                    if ($v > 0) $bxp[] = $v;
                }
                $bm = (($_POST['buy_mode'] ?? 'rule') === 'box') ? 'box' : 'rule';
                $pf->watchAdopt($code, trim((string)($_POST['memo'] ?? '')),
                    preg_replace('/[^a-z]/', '', (string)($_POST['source'] ?? '')),
                    $bm, (int)($_POST['rule_set_id'] ?? 0), $bm === 'box' ? $bxp : []);
                pf_api_done('/stock/index.php?mode=position&id=new&code=' . urlencode($code), 'ok',
                    "{$label} 을 편입 관심종목으로 등록했습니다"
                    . ($bm === 'box' && $bxp ? ' (박스 지지선 ' . count($bxp) . '개 보관)' : '')
                    . ' — 포트폴리오의 「＋ 종목 추가」 하단 목록에서 선택해 편입하세요.');
            }

            $inLast = str_replace(',', '', trim((string)($_POST['last_price'] ?? '')));
            $inHigh = str_replace(',', '', trim((string)($_POST['high_price'] ?? '')));

            // 종목명·현재가를 비워 두면 all_stock_info 에서 채운다
            $last = ($inLast !== '') ? (float)$inLast : null;
            if ($name === '' || $last === null) {
                $st = $pdo->prepare("SELECT stock_name, stock_price FROM all_stock_info WHERE stock_code = ?");
                $st->execute([$code]);
                if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    if ($name === '')   $name = $row['stock_name'];
                    if ($last === null) $last = (float)$row['stock_price'];
                }
            }
            if ($name === '') $name = $code;

            // 최고가는 입력값 우선, 없으면 신규일 때만 현재가로 초기화
            $known = $pf->stockGet($code);
            $high  = ($inHigh !== '') ? (float)$inHigh : ($known ? null : $last);

            $pf->stockUpsert([
                'code'       => $code,
                'name'       => $name,
                'market'     => trim((string)($_POST['market'] ?? '')) ?: ($known['market'] ?? 'KOSPI'),
                'last_price' => $last,
                'high_price' => $high,
            ]);

            /* 퀀트 사다리 — 매수 방식이 box 면 <b>저장 전에</b> 가격 조합이 풀리는지 검증한다.
             * 안 풀리는데 포지션부터 만들면 사다리 없는 반쪽 등록이 남는다. 룰셋 종목을 박스로
             * 전환하는 흐름은 없다 — 방식은 편입 때 정해지고, 박스 포지션의 재조정만 이 길로 온다. */
            $buyMode  = (($_POST['buy_mode'] ?? 'rule') === 'box') ? 'box' : 'rule';
            $boxBuilt = null;
            if ($buyMode === 'box') {
                $bxPrices = array_map(fn($v) => (float)str_replace(',', '', (string)$v), (array)($_POST['box_prices'] ?? []));
                $boxBuilt = pf_box_ladder_build($bxPrices);
                if ($boxBuilt === null) {
                    pf_api_done($id ? '/stock/index.php?mode=position&id=' . $id . '&edit=1'
                                    : '/stock/index.php?mode=position&id=new&pid=' . (int)($_POST['portfolio_id'] ?? 0),
                        'err', '퀀트 사다리: 지지선 3~5개를 간격 있게 고르고 「비중 풀기」까지 확인한 뒤 저장하세요.');
                }
            }

            /* ── 중복 편입 가드 (2026-08-04).
             * uk_pf_pos(portfolio_id, stock_code) 유니크라 같은 포트폴리오에 같은 종목을 두 번 담을 수 없다.
             * 가드가 없으면 여기서 PDOException 이 그대로 터져 사용자는 원인 모를 오류를 본다.
             * ★청산한 종목은 「막는 것」이 답이 아니다 — 재진입은 <b>그 포지션에 매수를 기록</b>하는 것이므로
             *   그 포지션으로 보내 주고, 무엇을 하면 되는지 문장으로 알려 준다. */
            if (!$id) {
                $dup = $pf->pdo()->prepare(
                    "SELECT id, status FROM pf_position WHERE portfolio_id = ? AND stock_code = ?");
                $dup->execute([(int)($_POST['portfolio_id'] ?? 0), $code]);
                if ($d = $dup->fetch(PDO::FETCH_ASSOC)) {
                    pf_api_done('/stock/index.php?mode=position&id=' . (int)$d['id'], 'err',
                        ((string)$d['status'] === 'closed')
                            ? '이 포트폴리오에서 이미 청산한 종목입니다 — 재진입은 새로 추가하지 않고 '
                              . '이 포지션에 매수를 기록하면 종료 상태가 자동으로 풀립니다.'
                            : '이 포트폴리오에 이미 담겨 있는 종목입니다.');
                }
            }

            // 상태는 매매에 따라 자동으로 바뀐다 (첫 매수 → 보유, 전량매도 → 종료).
            // 화면에서 직접 고르지 않으므로 기존 값을 유지하고, 신규는 관심(watch)으로 시작한다.
            $cur = $id ? $pf->positionGet($id) : null;

            $pid = $pf->positionSave([
                'id'           => $id ?: null,
                'portfolio_id' => (int)($_POST['portfolio_id'] ?? 0),
                'stock_code'   => $code,
                'rule_set_id'  => (int)($_POST['rule_set_id'] ?? 0),
                'limit_amt'    => pf_money('limit_amt'),   // "30,000,000" 같은 입력 허용
                'started_at'   => $_POST['started_at'] ?? '',
                'status'       => $cur['status'] ?? 'watch',
                'memo'         => trim($_POST['memo'] ?? ''),
                'source'       => preg_replace('/[^a-z]/', '', (string)($_POST['source'] ?? '')),   // 발굴 채널(신규만 저장)
                /* ── M2 (v0.3 §2.4) 편입 3축 — 전부 <b>신규만</b> 저장된다(positionSave 가 수정 시 안 덮는다).
                 * ladder_method 는 위에서 이미 검증한 $buyMode 에서 곧바로 온다 —
                 * 「레벨 행이 있느냐」로 나중에 되짚지 않고 <b>고른 그 순간</b>을 적는다.
                 * entry_trigger·surge_event_d 는 탐색 화면의 편입 버튼이 폼에 실어 준 값이고,
                 * 없으면 positionSave 가 각각 'none' / 상한 안의 최신 확정 신호로 메운다. */
                'ladder_method' => ($buyMode === 'box') ? 'quant_ladder' : 'ruleset_pct',
                'entry_trigger' => (string)($_POST['entry_trigger'] ?? ''),
                'surge_event_d' => (string)($_POST['surge_event_d'] ?? ''),
            ]);
            if ($boxBuilt !== null) $pf->positionLevelsReplace($pid, $boxBuilt['levels']);

            /* ── M4 (v0.3 §2.5) — 신규 편입이면 「그때의 판정」을 한 번 찍는다.
             * ★신규만이다. 수정 저장에서 다시 찍으면 스냅샷이 아니라 최신값이 되어 존재 이유가 사라진다
             *   (Pf::entrySnapshotSave 도 INSERT IGNORE 로 이중 방어).
             * ★실패해도 저장은 성공으로 끝낸다 — 기록이 편입을 막으면 안 된다. */
            if (!$id) {
                try {
                    /* ★기준 신호일은 positionGet 이 아니라 단일 테이블 조회로 읽는다 —
                     * positionGet 은 INNER JOIN 이라 마스터가 하나라도 비면 행째 null 이 되고,
                     * 그러면 signal_date 가 조용히 비어 잘못된 기록이 남는다(실측으로 밟았다). */
                    $pf->entrySnapshotSave($pid, pf_entry_snapshot_build(
                        $pdo, $code, $pf->positionSurgeEventDate($pid)));
                } catch (Throwable $e) { /* 스냅샷 없이도 편입은 끝난다 */ }
            }
            pf_api_done('/stock/index.php?mode=position&id=' . $pid, 'ok',
                '종목 설정을 저장했습니다.' . ($boxBuilt !== null ? ' (퀀트 사다리 ' . count($boxBuilt['levels']) . '차 확정)' : ''));

        case 'delete':
            $id  = (int)($_POST['id'] ?? 0);
            $pos = $pf->positionGet($id);
            $fid = $pos ? (int)$pos['portfolio_id'] : 0;
            $pf->positionDelete($id);
            pf_api_done($fid ? '/stock/index.php?id=' . $fid : '/stock/index.php',
                'ok', '종목을 삭제했습니다.');

        case 'list':
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(pf_positions_payload($pf), JSON_UNESCAPED_UNICODE);
            exit;

        default:
            pf_api_done('/stock/index.php', 'err', '알 수 없는 action 입니다.');
    }
}

/** 대시보드와 동일한 계산 결과를 JSON 으로 (§5 api/positions.php 대응) */
function pf_positions_payload(Pf $pf): array
{
    $positions = $pf->positions();
    $stepsMap  = $pf->ruleStepsMap(array_column($positions, 'rule_set_id'));
    $tradesMap = $pf->tradesMap(array_column($positions, 'id'));
    $feeMap    = $pf->brokerFeesMap(array_column($positions, 'broker_id'));
    $lvMap     = $pf->positionLevelsMap(array_column($positions, 'id'));

    $out = [];
    foreach ($positions as $p) {
        $steps  = $stepsMap[(int)$p['rule_set_id']] ?? [];
        $lvs    = $lvMap[(int)$p['id']] ?? [];
        if (!$steps && !$lvs) continue;

        $rows   = $tradesMap[(int)$p['id']] ?? [];
        $last   = ($p['last_price'] !== null) ? (float)$p['last_price'] : null;
        $prm    = pf_cost_params($p, $feeMap[(int)$p['broker_id']] ?? []);
        $c      = pf_position_calc($steps, pf_trades_by_step($rows), (float)$p['limit_amt'], $last, $prm, pf_ledger($rows, $prm), $lvs);
        // 종료 포지션은 계획·신호를 지운다 (화면과 같은 규칙 — pf_calc_closed 주석 참조)
        if ($p['status'] === 'closed') $c = pf_calc_closed($c);
        // 차수 지연 — pf_load_calc 와 같은 규칙 (세 적용 지점이 같아야 화면 간 판정이 일치한다)
        elseif ($c !== null) $c = pf_delay_adjust($c, $steps, pf_last_buy_at($rows), date('Y-m-d'));

        $out[] = [
            'id'           => (int)$p['id'],
            'portfolio'    => $p['portfolio_name'],
            'code'         => $p['stock_code'],
            'name'         => $p['stock_name'],
            'status'       => $p['status'],
            'last_price'   => $last,
            'cur_step'     => $c['cur_step'],
            'next_step'    => $c['next_step'],
            'next_price'   => $c['next_price'],
            'next_qty'     => $c['next_qty'],
            'held_qty'     => $c['filled_qty'],
            'sold_qty'     => $c['sold_qty'],
            'avg_cost'     => $c['avg_cost'],
            'sell_price'   => $c['sell_price'],
            'eval_amount'  => $c['eval_amount'],
            'eval_pl'      => $c['eval_pl'],
            'realized_pl'  => $c['realized_pl'],
            'total_pl'     => $c['total_pl'],
            'rate'         => $c['rate'],
            'buy_signal'   => $c['buy_signal'],
            'sell_signal'  => $c['sell_signal'],
        ];
    }
    return $out;
}

// ══ 매매 ════════════════════════════════════════════════════════════════
function api_trade(string $action, PDO $pdo, Pf $pf): void
{
    switch ($action) {
        case 'save':
            $pid  = (int)($_POST['position_id'] ?? 0);
            $back = '/stock/index.php?mode=position&id=' . $pid;

            $side  = (($_POST['side'] ?? 'buy') === 'sell') ? 'sell' : 'buy';
            // 화면 입력칸(.num-comma)이 콤마째 보낸다 — 걷지 않으면 (float)"1,630,000" = 1 로 저장된다
            $qty   = (int)str_replace(',', '', (string)($_POST['qty'] ?? 0));
            $price = (float)str_replace(',', '', (string)($_POST['price'] ?? 0));
            $date  = ($_POST['traded_at'] ?? '') ?: date('Y-m-d');
            /* 체결 시각 — 비우면 「모른다」(NULL). type=time 은 HH:MM 또는 HH:MM:SS 로 온다.
             * 형식이 아니면 조용히 버린다(틀린 시각을 넣느니 모르는 편이 낫다). */
            $time  = trim((string)($_POST['traded_time'] ?? ''));
            if ($time !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) $time = '';
            if ($time !== '' && strlen($time) === 5) $time .= ':00';

            if ($qty <= 0 || $price <= 0) pf_api_done($back, 'err', '체결가와 수량을 확인하세요.');

            $pos = $pf->positionGet($pid);
            if (!$pos) pf_api_done('/stock/index.php', 'err', '종목을 찾을 수 없습니다.');

            $prm    = pf_cost_params($pos, $pf->brokerFees((int)$pos['broker_id']));
            $before = pf_ledger($pf->trades($pid), $prm);

            if ($side === 'sell') {
                if ($before['held_qty'] <= 0) {
                    pf_api_done($back, 'err', '보유 수량이 없어 매도할 수 없습니다.');
                }
                if ($qty > $before['held_qty']) {
                    pf_api_done($back, 'err',
                        '보유 수량(' . number_format($before['held_qty']) . '주)보다 많이 매도할 수 없습니다.');
                }
            }

            $pf->tradeSave([
                'id'          => (int)($_POST['id'] ?? 0) ?: null,
                'position_id' => $pid,
                'step_no'     => ($side === 'sell') ? 0 : (int)($_POST['step_no'] ?? 0),
                'side'        => $side,
                'traded_at'   => $date,
                'traded_time' => $time,
                'price'       => $price,
                'qty'         => $qty,
                'memo'        => trim($_POST['memo'] ?? ''),
            ]);

            $after = pf_ledger($pf->trades($pid), $prm);

            // 상태 자동 전환: 첫 매수 → 보유 / 전량 매도 → 종료 / 재매수 → 보유
            $status  = $pos['status'];
            $started = $pos['started_at'];
            if ($side === 'buy') {
                if ($status !== 'open' && $after['held_qty'] > 0) $status = 'open';
                if (!$started) $started = $date;
            } elseif ($after['held_qty'] === 0) {
                $status = 'closed';
            }
            if ($status !== $pos['status'] || $started !== $pos['started_at']) {
                $pos['status']     = $status;
                $pos['started_at'] = $started;
                $pf->positionSave($pos);
            }

            if ($side === 'sell') {
                $realized = $after['realized_pl'] - $before['realized_pl'];
                $msg = number_format($qty) . '주 매도 · 실현손익 ' . number_format(round($realized)) . '원';
                $msg .= ($after['held_qty'] === 0)
                    ? ' · 전량 매도라 종료 처리했습니다.'
                    : ' · 잔여 ' . number_format($after['held_qty']) . '주';
                pf_api_done($back, 'ok', $msg);
            }

            pf_api_done($back, 'ok', '체결 내역을 등록했습니다.');

        case 'delete':
            $t   = $pf->tradeGet((int)($_POST['id'] ?? 0));
            $pid = $t ? (int)$t['position_id'] : 0;
            if ($t) $pf->tradeDelete((int)$t['id']);
            pf_api_done('/stock/index.php?mode=position&id=' . $pid, 'ok', '체결 기록을 삭제했습니다.');

        default:
            pf_api_done('/stock/index.php', 'err', '알 수 없는 action 입니다.');
    }
}

// ══ 증권사 + 수수료 ═════════════════════════════════════════════════════
/**
 * 증권사 고시 수수료 프리셋.
 * 실제 요율은 계좌·거래채널·이벤트에 따라 다르니 적용 후 확인·조정할 것.
 */
function pf_fee_presets(): array
{
    return [
        'kiwoom' => [
            ['min_amt' => 0, 'fee_rate' => 0.00015, 'fee_fixed' => 0],
        ],
        'samsung' => [
            ['min_amt' => 0,         'fee_rate' => 0.00147216, 'fee_fixed' => 1500],
            ['min_amt' => 10000000,  'fee_rate' => 0.00127216, 'fee_fixed' => 3000],
            ['min_amt' => 50000000,  'fee_rate' => 0.00117216, 'fee_fixed' => 0],
            ['min_amt' => 100000000, 'fee_rate' => 0.00097216, 'fee_fixed' => 0],
            ['min_amt' => 300000000, 'fee_rate' => 0.00077216, 'fee_fixed' => 0],
        ],
    ];
}

function api_broker(string $action, PDO $pdo, Pf $pf): void
{
    $back = '/stock/index.php?mode=setting';

    switch ($action) {
        case 'create':
            $name = trim($_POST['name'] ?? '');
            if ($name === '') pf_api_done($back, 'err', '증권사명을 입력하세요.');

            $bid = $pf->brokerSave(['name' => $name]);

            $preset  = $_POST['preset'] ?? '';
            $presets = pf_fee_presets();
            if (isset($presets[$preset])) {
                $pf->brokerFeesReplace($bid, $presets[$preset]);
            }
            pf_api_done($back . '&bid=' . $bid, 'ok', $name . ' 증권사를 추가했습니다.');

        case 'save':
            $bid  = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            if ($name === '') pf_api_done($back . '&bid=' . $bid, 'err', '증권사명을 입력하세요.');

            $cur = $pf->brokerGet($bid);
            // 구간을 쓰는 중이면 입력칸이 disabled 라 값이 안 넘어온다 → 기존 값 유지
            $buy  = array_key_exists('buy_fee', $_POST)
                ? max(0, (float)$_POST['buy_fee']) / 100  : (float)($cur['buy_fee_rate']  ?? 0.0034);
            $sell = array_key_exists('sell_fee', $_POST)
                ? max(0, (float)$_POST['sell_fee']) / 100 : (float)($cur['sell_fee_rate'] ?? 0.0015);

            $pf->brokerSave([
                'id'            => $bid,
                'name'          => $name,
                'buy_fee_rate'  => $buy,
                'sell_fee_rate' => $sell,
                'memo'          => trim($_POST['memo'] ?? ''),
                'sort_no'       => (int)($_POST['sort_no'] ?? ($cur['sort_no'] ?? 0)),
            ]);
            pf_api_done($back . '&bid=' . $bid, 'ok', '저장했습니다. 이 증권사를 쓰는 포트폴리오가 다시 계산됩니다.');

        case 'save_tier':
            $bid   = (int)($_POST['id'] ?? 0);
            $mins  = $_POST['min_amt'] ?? [];
            $tiers = [];
            foreach ($mins as $i => $min) {
                $tiers[] = [
                    'min_amt'   => max(0, (int)str_replace(',', '', (string)$min)),
                    'fee_rate'  => max(0, (float)pf_arr('fee_rate', $i, 0)) / 100,
                    'fee_fixed' => max(0, (int)str_replace(',', '', (string)pf_arr('fee_fixed', $i, 0))),
                ];
            }
            usort($tiers, fn($a, $b) => $a['min_amt'] <=> $b['min_amt']);
            $pf->brokerFeesReplace($bid, $tiers);

            pf_api_done($back . '&bid=' . $bid, 'ok', $tiers
                ? count($tiers) . '개 구간을 저장했습니다. 누적단가·자동매도가가 다시 계산됩니다.'
                : '구간을 비웠습니다. 단일 요율로 돌아갑니다.');

        case 'preset':
            $bid     = (int)($_POST['id'] ?? 0);
            $preset  = $_POST['preset'] ?? 'flat';
            $presets = pf_fee_presets();

            $pf->brokerFeesReplace($bid, $presets[$preset] ?? []);
            pf_api_done($back . '&bid=' . $bid, 'ok', isset($presets[$preset])
                ? ($preset === 'kiwoom' ? '키움증권' : '삼성증권') . ' 수수료 구간을 적용했습니다.'
                : '구간을 삭제했습니다. 단일 요율을 사용합니다.');

        case 'delete':
            $bid = (int)($_POST['id'] ?? 0);
            if ($pf->brokerFolioCount($bid) > 0) {
                pf_api_done($back . '&bid=' . $bid, 'err', '이 증권사를 쓰는 포트폴리오가 있어 삭제할 수 없습니다.');
            }
            $pf->brokerDelete($bid);
            pf_api_done($back, 'ok', '증권사를 삭제했습니다.');

        default:
            pf_api_done($back, 'err', '알 수 없는 action 입니다.');
    }
}

// ══ 설정 (세율) ═════════════════════════════════════════════════════════
function api_setting(string $action, PDO $pdo, Pf $pf): void
{
    $back = '/stock/index.php?mode=setting';

    switch ($action) {
        case 'save_tax':
            $codes = $_POST['code'] ?? [];
            $n     = 0;
            foreach ($codes as $i => $code) {
                $pf->marketSave([
                    'code'     => $code,
                    'name'     => trim((string)pf_arr('name', $i)),
                    'tax_rate' => max(0, (float)pf_arr('tax', $i, 0)) / 100,
                    'memo'     => trim((string)pf_arr('memo', $i)),
                    'sort_no'  => (int)pf_arr('sort_no', $i, 0),
                ]);
                $n++;
            }
            pf_api_done($back, 'ok', "시장 세율 {$n}건을 저장했습니다.");

        case 'add_tax':
            $code = strtoupper(trim($_POST['code'] ?? ''));
            if ($code === '') pf_api_done($back, 'err', '시장 코드를 입력하세요.');

            $pf->marketSave([
                'code'     => $code,
                'name'     => trim($_POST['name'] ?? ''),
                'tax_rate' => max(0, (float)($_POST['tax'] ?? 0)) / 100,
                'memo'     => trim($_POST['memo'] ?? ''),
                'sort_no'  => 99,
            ]);
            pf_api_done($back, 'ok', $code . ' 시장을 추가했습니다.');

        case 'reset_tax':
            $n = $pf->resetMarkets();
            pf_api_done($back, 'ok', "시장 세율 {$n}건을 2026년 기준값으로 되돌렸습니다. 자동매도가·실현손익이 다시 계산됩니다.");

        case 'delete_tax':
            $code = $_POST['del_code'] ?? '';
            if ($code !== '') $pf->marketDelete($code);
            pf_api_done($back, 'ok', $code . ' 세율을 삭제했습니다.');

        default:
            pf_api_done($back, 'err', '알 수 없는 action 입니다.');
    }
}

// ══ 시뮬레이터 시세 데이터 ══════════════════════════════════════════════
function api_sim(string $action, PDO $pdo, Pf $pf): void
{
    $back = '/stock/index.php?mode=sim';

    switch ($action) {
        // ── 네이버 일봉 수집 (시·고·저·종). 종목당 1건이라 같은 종목이면 갱신된다.
        case 'fetch':
            [$code, $sname] = pf_sim_stock_input();
            if ($code === '') pf_api_done($back, 'err', '종목을 선택하세요.');

            $from = preg_replace('/[^0-9]/', '', $_POST['from'] ?? '');
            $to   = preg_replace('/[^0-9]/', '', $_POST['to']   ?? '');
            if (strlen($from) !== 8) $from = date('Ymd', strtotime('-10 years'));
            if (strlen($to)   !== 8) $to   = date('Ymd');

            $api = new NaverFinanceAPI();
            $r   = $api->getDailyOhlcRange($code, $from, $to);
            if (isset($r['error'])) pf_api_done($back, 'err', '네이버 수집 실패: ' . $r['error']);

            $rows = [];
            foreach ($r['success'] as $x) {
                if ((float)$x['c'] <= 0) continue;
                $rows[] = ['d' => $x['t'], 'c' => (float)$x['c'],
                           'o' => (float)$x['o'], 'h' => (float)$x['h'], 'l' => (float)$x['l'],
                           'v' => (int)($x['v'] ?? 0)];
            }
            if (!$rows) pf_api_done($back, 'err', '수집된 시세가 없습니다. 종목코드와 기간을 확인하세요.');

            $exists = $pf->simDataFindByCode($code);
            $id     = $pf->simDataUpsert($code, $sname !== '' ? $sname : $code, $rows);
            pf_api_done($back . '&id=' . $id, 'ok',
                ($exists ? '시세를 갱신했습니다 — ' : '시세를 등록했습니다 — ')
                . count($rows) . '거래일 (' . $rows[0]['d'] . ' ~ ' . $rows[count($rows) - 1]['d'] . ') · 시·고·저·종 포함');

        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            if ($id) $pf->simDataDelete($id);
            pf_api_done($back, 'ok', '시세 데이터를 삭제했습니다.');

        default:
            pf_api_done($back, 'err', '알 수 없는 action 입니다.');
    }
}

/**
 * 종목 자동완성이 보내는 stock_code / stock_name 정리.
 * 목록에서 고르지 않고 이름만 친 경우도 있어 코드는 6자리 숫자만 인정한다.
 *
 * @return array [code, name]
 */
function pf_sim_stock_input(): array
{
    $code = preg_replace('/[^0-9A-Za-z]/', '', trim($_POST['stock_code'] ?? ''));
    if (!preg_match('/^\d{6}$/', $code)) $code = '';
    return [$code, mb_substr(trim($_POST['stock_name'] ?? ''), 0, 60)];
}

// ══ 종목 ════════════════════════════════════════════════════════════════
// 시세 화면은 삭제했다. 종목의 시장·현재가는 종목 설정 폼에서 저장하고,
// 일괄 시세 수집은 all_stock_info 연동 로직에서 sync 를 호출한다.
function api_stock(string $action, PDO $pdo, Pf $pf): void
{
    $back = '/stock/index.php';

    switch ($action) {
        case 'sync':
            $n = $pf->syncPrices();
            pf_api_done($back, $n > 0 ? 'ok' : 'warn',
                $n > 0 ? "all_stock_info 에서 {$n}개 종목 시세를 갱신했습니다."
                       : 'all_stock_info 에서 일치하는 종목을 찾지 못했습니다.');

        case 'search':
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($pf->stockSearch(trim($_GET['q'] ?? '')), JSON_UNESCAPED_UNICODE);
            exit;

        default:
            pf_api_done($back, 'err', '알 수 없는 action 입니다.');
    }
}

// ══ DART 재무지표 ═══════════════════════════════════════════════════════
// 연도별 EPS·BPS·DPS·배당수익률을 DART 에서 받아 stock_fundamental 에 쌓는다.
// PER·PBR 은 주가로 나눈 파생값이라 저장하지 않고 화면에서 만든다.
function api_dart(string $action, PDO $pdo, Pf $pf): void
{
    $back = '/stock/index.php?mode=fund';

    require_once $_SERVER['DOCUMENT_ROOT'] . '/env/dart.inc';
    $dart = new Dart($pdo);

    /* 종목 검색만은 DB 만 본다 — 인증키도 테이블 생성도 필요 없다.
     * 아래 hasKey() 게이트 앞에 두는 이유가 그것이다. 키가 없어도 이미 받아 둔 재무는 볼 수 있어야 한다. */
    if ($action === 'search') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($dart->searchCorp(trim($_GET['q'] ?? '')), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!$dart->hasKey()) pf_api_done($back, 'err', 'DART 인증키가 없습니다 (env/dart.inc).');
    $dart->ensureTables();

    switch ($action) {
        // ── 종목코드 ↔ corp_code 매핑 적재. 어떤 수집보다 먼저 한 번은 돌아야 한다.
        case 'corpsync':
            $r = $dart->syncCorpCodes();
            pf_api_done($back, 'ok',
                "고유번호를 동기화했습니다 — 전체 " . number_format($r['total']) . "개 중 상장 "
                . number_format($r['saved']) . "개 적재");

        // ── 한 종목의 연도별 지표 수집
        case 'collect':
            $code = preg_replace('/[^0-9]/', '', trim($_POST['stock_code'] ?? $_GET['stock_code'] ?? ''));
            if (!preg_match('/^\d{6}$/', $code)) pf_api_done($back, 'err', '종목을 선택하세요.');

            $to   = (int)($_POST['to_year']   ?? $_GET['to_year']   ?? 0);
            $from = (int)($_POST['from_year'] ?? $_GET['from_year'] ?? 0);
            // 사업보고서는 이듬해 3월에 나온다 — 올해 것은 아직 없으므로 작년까지가 상한
            if ($to   <= 0) $to   = (int)date('Y') - 1;
            if ($from <= 0) $from = Dart::MIN_YEAR;

            $rows = $dart->collect($code, $from, $to);
            if (!$rows) pf_api_done($back . '&code=' . $code, 'warn',
                "{$code} — DART 에 " . $from . '~' . $to . '년 사업보고서 데이터가 없습니다.');

            $ys = array_keys($rows);
            pf_api_done($back . '&code=' . $code, 'ok',
                "{$code} 재무지표 " . count($rows) . "개 연도를 저장했습니다 ("
                . min($ys) . '~' . max($ys) . ').');

        /* ── 한 (사업연도 × 보고서) 의 전종목 재무제표.
         *    100종목씩 묶어 부르므로 40회 · 20초쯤이면 끝나 화면에서 눌러도 된다.
         *    새 분기보고서가 나왔을 때 그 분기만 받아 오는 용도다.
         *    11년치를 통째로 받는 것처럼 긴 작업은 cron/dart_collect.php (cron_job.php?task=dart_*) 가 맡는다. */
        case 'quarter':
            $y  = (int)($_POST['year'] ?? $_GET['year'] ?? 0);
            $rc = (string)($_POST['rc'] ?? $_GET['rc'] ?? '');
            if (!isset(Dart::REPRT_INFO[$rc]))  pf_api_done($back, 'err', '보고서 종류를 고르세요.');
            if ($y < Dart::MIN_YEAR || $y > (int)date('Y')) pf_api_done($back, 'err', '사업연도가 올바르지 않습니다.');

            $r = $dart->collectFinancials($y, $rc);
            if (!$r['companies']) pf_api_done($back, 'warn',
                "{$y}년 " . Dart::reprtName($rc) . ' — DART 에 아직 자료가 없습니다.');

            pf_api_done($back . '&y=' . $y . '&rc=' . $rc, 'ok',
                "{$y}년 " . Dart::reprtName($rc) . ' — ' . number_format($r['saved']) . '종목을 저장했습니다.');

        // ── 연도별 주식수 채우기 (PER·PBR 을 그 해 기준으로 맞추려고)
        //    종목별 호출이라 한 번에 다 못 한다 — 이미 채운 행은 건너뛰므로 반복 실행하면 이어진다.
        //    한 종목에 0.22초쯤 걸린다. 화면에서 기다릴 수 있는 선이 300종목(약 1분)이라 그걸 기본으로 둔다.
        case 'shares':
            $y     = (int)($_POST['year']  ?? $_GET['year']  ?? 0);
            $limit = (int)($_POST['limit'] ?? $_GET['limit'] ?? 300);
            if ($y <= 0) $y = (int)date('Y') - 1;

            $r = $dart->collectShares($y, max(1, min(1000, $limit)));
            pf_api_done($back . '&y=' . $y, $r['remain'] > 0 ? 'warn' : 'ok',
                "{$y}년 주식수 — 이번에 {$r['done']}종목 처리(채움 {$r['filled']} · 값없음 {$r['empty']})"
                . ($r['remain'] > 0 ? " · 남은 {$r['remain']}종목은 버튼을 다시 누르면 이어서 받습니다." : ' · 완료'));

        default:
            pf_api_done($back, 'err', '알 수 없는 action 입니다.');
    }
}

// ══ KRX 시세 ════════════════════════════════════════════════════════════
// 거래소 원본으로 "지금 거래되는 종목"과 "상장주식수"를 받아 온다.
// 상장주식수가 있어야 전종목 EPS·BPS(= 순이익·자본총계 ÷ 주식수)를 만들 수 있다.
function api_krx(string $action, PDO $pdo, Pf $pf): void
{
    $back = '/stock/index.php?mode=fund';

    require_once $_SERVER['DOCUMENT_ROOT'] . '/env/krx.inc';
    $krx = new Krx($pdo);
    if (!$krx->hasKey()) pf_api_done($back, 'err', 'KRX 인증키가 없습니다 (env/krx.inc).');
    $krx->ensureTables();

    switch ($action) {
        case 'collect':
            // 기준일을 주면 그 날, 없으면 가장 최근 거래일 (오늘 것은 아직 안 올라온다)
            $dd = preg_replace('/[^0-9]/', '', (string)($_POST['dd'] ?? $_GET['dd'] ?? ''));
            $r  = (strlen($dd) === 8) ? $krx->collect($dd) : $krx->collectLatest();

            if (!$r['rows']) pf_api_done($back, 'warn',
                '거래 데이터가 없습니다. KRX 는 당일 자료를 바로 주지 않고 최근 며칠치만 제공합니다.');

            pf_api_done($back, 'ok',
                $r['date'] . ' 기준 ' . number_format($r['rows']) . '종목의 시세·상장주식수를 받았습니다.');

        default:
            pf_api_done($back, 'err', '알 수 없는 action 입니다.');
    }
}

// ══ 관심종목 ════════════════════════════════════════════════════════════
// 재무분석에서 눈에 띈 종목을 가볍게 담아 둔다. 포트폴리오·룰셋은 정하지 않는다 —
// 그건 실제로 사기로 마음먹었을 때 「포트폴리오에 담기」로 넘어가서 정한다.
/**
 * 종목 개요·태그 저장 (2026-08-30 · 단일본 stock/lib/note.php).
 * 상세화면 「종목 개요」 카드의 폼 POST 하나가 개요와 태그를 함께 담는다.
 */
function api_note(string $action, PDO $pdo): void
{
    require_once __DIR__ . '/lib/note.php';
    $back = $_POST['back'] ?? $_SERVER['HTTP_REFERER'] ?? '/stock/index.php?mode=fund';
    $back = preg_replace('/[?&]msg=[^&]*/', '', $back);

    if ($action !== 'save') pf_api_fail('알 수 없는 action 입니다.');

    // 우선주 라우터와 같은 규칙 — 상세가 이미 본주로 옮겨 온 뒤라 본주 코드가 온다
    $code = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string)($_POST['code'] ?? '')));
    if (!preg_match('/^[0-9]{5}[0-9A-Z]$/', $code)) pf_api_done($back, 'err', '종목코드가 올바르지 않습니다.');

    // 저장 + 히스토리 한 입구 — 내용이 바뀌었으면 옛 판이 stock_note_hist 에 먼저 남는다
    pf_note_save_with_hist($pdo, $code, (string)($_POST['headline'] ?? ''), (string)($_POST['summary'] ?? ''),
        pf_tag_parse((string)($_POST['tags'] ?? '')));
    pf_api_done($back, 'ok', '종목 개요를 저장했습니다.');
}

function api_watch(string $action, PDO $pdo, Pf $pf): void
{
    $back = $_POST['back'] ?? $_SERVER['HTTP_REFERER'] ?? '/stock/index.php?mode=watch';
    $back = preg_replace('/[?&]msg=[^&]*/', '', $back);

    $code = preg_replace('/[^0-9A-Za-z]/', '', (string)($_POST['code'] ?? $_GET['code'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));

    switch ($action) {
        // 담기/빼기를 한 액션으로 — 스크리너의 ☆ 를 누를 때마다 뒤집힌다
        case 'toggle':
            if (!preg_match('/^\w{6}$/', $code)) pf_api_done($back, 'err', '종목코드가 올바르지 않습니다.');
            $label = $name !== '' ? $name : $code;
            if ($pf->watchDel($code)) {
                pf_api_done($back, 'ok', "{$label} 을 관심종목에서 뺐습니다.");
            }
            // src = 어느 화면의 ☆ 인가 (quant/earn/fund) — 채널별 성과 측정의 씨앗
            $src = preg_replace('/[^a-z]/', '', (string)($_POST['src'] ?? ''));
            $pf->watchAdd($code, trim((string)($_POST['memo'] ?? '')), $src);
            pf_api_done($back, 'ok', "{$label} 을 관심종목에 담았습니다.");

        case 'del':
            if (!preg_match('/^\w{6}$/', $code)) pf_api_done($back, 'err', '종목코드가 올바르지 않습니다.');
            $pf->watchDel($code);
            pf_api_done($back, 'ok', '관심종목에서 뺐습니다.');

        // 편입 대기만 해제 — 관심종목 목록에는 남는다 (종목추가 하단 「편입 관심종목」의 삭제 버튼)
        case 'unadopt':
            if (!preg_match('/^\w{6}$/', $code)) pf_api_done($back, 'err', '종목코드가 올바르지 않습니다.');
            $pf->watchUnadopt($code);
            pf_api_done($back, 'ok', '편입 관심종목에서 뺐습니다 — 관심종목에는 그대로 남아 있습니다.');

        case 'memo':
            if (!preg_match('/^\w{6}$/', $code)) pf_api_done($back, 'err', '종목코드가 올바르지 않습니다.');
            $pf->watchMemo($code, (string)($_POST['memo'] ?? ''));
            pf_api_done($back, 'ok', '메모를 저장했습니다.');

        default:
            pf_api_done($back, 'err', '알 수 없는 action 입니다.');
    }
}

// ══ 저장한 스크리너 조건 ════════════════════════════════════════════════
// 조건을 쿼리스트링 통째로 담는다 — 화면이 이미 조건을 주소에 싣고 있어 그대로 쓰면 된다.
function api_preset(string $action, PDO $pdo, Pf $pf): void
{
    $back = $_POST['back'] ?? $_SERVER['HTTP_REFERER'] ?? '/stock/index.php?mode=fund';
    $back = preg_replace('/[?&]msg=[^&]*/', '', $back);

    switch ($action) {
        case 'save':
            $name = trim((string)($_POST['name'] ?? ''));
            $cond = trim((string)($_POST['cond'] ?? ''));
            /* 조건이 하나도 없으면 저장할 것이 없다 — 체크박스만 담긴 빈 조건을
             * 이름 붙여 쌓아 두면 배지만 늘고 쓸모가 없다. */
            if ($cond === '') pf_api_done($back, 'warn', '저장할 조건이 없습니다 — 값을 하나 이상 넣고 검색한 뒤 저장하세요.');

            $r = $pf->presetSave($name, $cond, (string)($_POST['memo'] ?? ''));
            pf_api_done($back, 'ok', $r === 'update'
                ? '같은 이름이 있어 조건을 덮어썼습니다.' : '조건을 저장했습니다.');

        case 'del':
            $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
            if ($id <= 0) pf_api_done($back, 'err', '대상을 찾지 못했습니다.');
            $pf->presetDel($id);
            pf_api_done($back, 'ok', '저장한 조건을 지웠습니다.');

        default:
            pf_api_done($back, 'err', '알 수 없는 action 입니다.');
    }
}

// ══ 단타 (분봉 원장) ════════════════════════════════════════════════════
/**
 * module=dt — 단타 화면 전용. <b>전부 JSON</b> 이다(폼 POST → 리다이렉트가 없다).
 *
 * 규칙(stock_analysis_api.php 와 같은 결):
 *   · 빈/없음 은 빈 배열 `[]` + HTTP 200. 진짜 예외만 `{"error":…}`
 *   · 종목코드는 Dt::cleanCode() 를 지난 6자리만 쓴다 (`_AL` 은 키움에 보낼 때만 붙는다)
 *
 * ★ 일봉용 action 을 여기 만들지 않는다 — 화면이 기존 `stock_analysis_api.php?module=stock&action=daily`
 *   를 그대로 부른다. 그래야 네이버 일봉 + krx_amt 실제 거래대금 병합이 저절로 따라온다.
 */
function api_dt(string $action, PDO $pdo): void
{
    header('Content-Type: application/json; charset=utf-8');

    $out = function ($v, int $code = 200) {
        http_response_code($code);
        echo json_encode($v, JSON_UNESCAPED_UNICODE);
        exit;
    };

    try {
        $dt = new Dt($pdo);
        $dt->ensureTables();
        $code = Dt::cleanCode((string)($_REQUEST['code'] ?? ''));

        /* 목록 배지(퀀트) — 「상위 종목」 목록과 <b>같은 판정·같은 어휘</b>를 쓴다.
         * ★단타 규칙 「판정을 세우지 않는다」는 <b>새 판정을 만들지 말라</b>는 뜻이다.
         *   이미 있는 관찰 배지를 가져다 다는 것은 두 화면이 같은 말을 하게 하는 쪽이다.
         * 원장(krx_amt/krx_surge)이 없어도 목록은 떠야 하므로 실패는 삼킨다(배지는 부가정보).
         * ★탭 둘(내 목록·보유)이 <b>같은 함수</b>를 지난다 — 따로 적으면 한쪽만 조용히 옛말을 한다. */
        $badge = function (array $rows) use ($pdo): array {
            $items = [];
            foreach ($rows as $r) $items[$r['code']] = [
                'price'  => (float)($r['price']   ?? 0),
                'rate'   => (float)($r['rate']    ?? 0),
                'amtEok' => (float)($r['amt_eok'] ?? 0),
            ];
            try { $qm = quant_badge_many($pdo, $items); } catch (Throwable $e) { $qm = []; }
            foreach ($rows as &$r) $r['q'] = $qm[$r['code']] ?? null;
            unset($r);
            return $rows;
        };

        /* 초기 10거래일 적재 — 담기(`pool_init`)와 보유(`held_init`)가 <b>같은 함수</b>를 지난다.
         * 갈리는 것은 「`dt_pool` 의 init_status 를 적을 자리가 있나」뿐이다(보유엔 그 행이 없다).
         * 따로 적으면 한쪽만 고쳐져 두 경로가 다른 봉을 쌓는다.
         * ★switch «앞»에 둔다 — case 사이에 적은 문장은 PHP 가 매칭된 case 로 바로 뛰므로
         *   영영 실행되지 않는다(undefined 로 죽는다). */
        $initLoad = function (string $code, bool $track) use ($dt, $pdo): array {
            $kw = new Kiwoom($pdo);
            if (!$kw->hasKey()) {
                if ($track) $dt->poolInitStatus($code, 9);
                return ['ok' => 0, 'msg' => '키움 인증키가 없습니다 (env/kiwoom.inc). 장중에는 네이버로 당일만 보입니다.'];
            }
            $from = $dt->windowFrom();
            try {
                $r = $kw->minute($code, 1, $from);
            } catch (Throwable $e) {
                if ($track) $dt->poolInitStatus($code, 9);
                $dt->logSave($code, date('Y-m-d'), 0, 'fail', Dt::SRC_KIWOOM, $e->getMessage(),
                             null, null, true);
                return ['ok' => 0, 'msg' => '키움 조회 실패: ' . $e->getMessage()];
            }
            $saved = 0;
            foreach (Dt::byDay($r['rows']) as $d => $rows) {
                $dt->barsUpsert($code, $rows, Dt::SRC_KIWOOM);
                $saved += count($rows);
                $dt->logSave($code, $d, count($rows), Dt::statusOfBars(count($rows)),
                             Dt::SRC_KIWOOM, null,
                             substr($rows[0]['t'], 11, 5),
                             substr($rows[count($rows) - 1]['t'], 11, 5));
            }
            if ($track) $dt->poolInitStatus($code, $saved > 0 ? 2 : 9);
            return ['ok' => $saved > 0 ? 1 : 0, 'bars' => $saved, 'calls' => $r['calls'],
                    'msg' => $saved > 0 ? number_format($saved) . '봉을 받았습니다.'
                                        : '받아 온 봉이 없습니다 (다음 수집 때 다시 시도합니다).'];
        };

        /* ── 장중 실시간 시세 (2026-08-06) ────────────────────────────────
         * 두 목록이 응답을 만들기 «전에» 지난다. 대상은 Dt::targetCodes()(단타 풀 ∪ 보유)뿐이고
         * 키움 ka10095 가 100종목을 한 요청에 주므로 실측 34종목이 1콜·0.03초다.
         *
         * ★새 판정을 세우지 않는다 — 알맹이는 Dt::refreshQuotesLive() 단일본이고,
         *   「무엇이 낡았나」는 다시 NaverFinanceAPI::staleCutoff() 단일본을 지난다.
         *   TICK_SEC 안에 이미 받아 둔 것은 「낡음」에 안 걸려 콜이 아예 안 나간다
         *   — 탭을 여럿 열어도, 두 목록을 잇달아 불러도 콜은 한 번이다.
         * ★전종목을 여기서 갱신하지 않는다 — 28콜 × 10초면 하루 1만 콜이라
         *   「사이트 전 주가의 단일 원천」을 IP 차단 위험에 올린다(CRON.md §4).
         * ★실패해도 조용히 넘어간다 — 목록은 DB 값으로 그대로 뜬다. */
        $live = static function () use ($dt) {
            try { return $dt->refreshQuotesLive(); }
            catch (Throwable $e) { return ['src' => 'none']; }
        };

        switch ($action) {
            // ── 목록 ────────────────────────────────────────────────
            case 'pool_list': {
                $lv   = $live();
                $rows = $badge($dt->poolList());
                /* 「내 목록」에도 보유 여부를 실어 보낸다 — 담아 놓고 나중에 산 종목이
                 * 두 탭에서 다른 말을 하면 안 된다(배지는 화면이 그린다). */
                $held = array_flip($dt->heldCodes());
                foreach ($rows as &$r) $r['held'] = isset($held[$r['code']]) ? 1 : 0;
                unset($r);
                $out([
                    'rows'   => $rows,
                    'max'    => Dt::POOL_MAX,
                    'window' => $dt->windowFrom(),
                    'days'   => $dt->tradingDays(),
                    'haskey' => (new Kiwoom($pdo))->hasKey(),
                    'tick'   => Dt::TICK_SEC,
                    'qsrc'   => $lv['src'] ?? 'none',   // 화면의 LIVE 배지가 소스를 밝힌다
                ]);
            }

            /* ── 「보유」 탭 목록 ───────────────────────────────────
             * 원본은 pf_position 이다 — dt_pool 에 복사하지 않는다(Dt::targetCodes 주석).
             * 그래서 여기엔 담기·해지·순서바꾸기가 없다: 이 목록은 <b>포트폴리오가 정한다</b>. */
            case 'held_list': {
                $lv   = $live();
                $rows = $badge($dt->heldList());
                $out([
                    'rows'   => $rows,
                    'window' => $dt->windowFrom(),
                    'days'   => $dt->tradingDays(),
                    'tick'   => Dt::TICK_SEC,
                    'qsrc'   => $lv['src'] ?? 'none',
                ]);
            }

            /* ── 실계좌 체결 마커 (분봉·일봉에 얹는다)
             * ★새 판정이 아니라 <b>기록</b>이다 — 단타 규칙 9(판정을 세우지 않는다)에 걸리지 않는다.
             *   시뮬레이터의 「실계좌 체결 겹쳐보기」와 같은 성격이고 원본도 같다(pf_trade).
             * ★시각이 없으면 분봉에 못 찍는다 — 거르지 않고 그대로 주고 화면이 정한다
             *   (일봉 마커는 날짜만 있으면 되므로 여기서 지우면 그쪽까지 사라진다). */
            case 'fills': {
                if ($code === '') $out(['rows' => []]);
                $pf   = new Pf($pdo);
                $rows = [];
                foreach ($pf->tradesByCode($code) as $t) {
                    $rows[] = [
                        'd'     => (string)$t['traded_at'],
                        't'     => $t['traded_time'] !== null ? substr((string)$t['traded_time'], 0, 8) : null,
                        'sell'  => ($t['side'] === 'sell') ? 1 : 0,
                        'step'  => (int)$t['step_no'],
                        'qty'   => (int)$t['qty'],
                        'price' => (float)$t['price'],
                        'pf'    => (string)$t['portfolio_name'],
                        'pid'   => (int)$t['position_id'],
                    ];
                }
                $out(['rows' => $rows]);
            }

            // ── 담기 (즉시 응답 · 적재는 pool_init 이 이어받는다) ──
            case 'pool_add': {
                if ($code === '') $out(['error' => '종목을 고르세요.'], 200);
                /* ★보유 종목은 담지 않는다 — 이미 targetCodes() 에 들어 있어 매일 수집된다.
                 *   담아 봐야 20칸 하나를 먹고 남의 종목을 FIFO 로 밀어낼 뿐이다. */
                if (in_array($code, $dt->heldCodes(), true)) {
                    $out(['ok' => false, 'code' => $code,
                          'msg' => '보유 종목이라 이미 자동으로 수집됩니다 — 「보유」 탭에서 보세요.']);
                }
                $r = $dt->poolAdd($code, trim((string)($_REQUEST['name'] ?? '')));
                $out($r + ['code' => $code]);
            }

            // ── 초기 적재 (10거래일) ────────────────────────────────
            case 'pool_init': {
                if ($code === '') $out(['error' => '종목을 고르세요.'], 200);
                $out($initLoad($code, true));
            }

            /* ── 보유 종목의 초기 적재 (2026-08-05) ─────────────────
             * 보유엔 「＋를 누르는 순간」이 없어 `pool_init` 에 해당하는 자리가 없다.
             * 크론의 ③ 구멍 치유가 결국 메우지만 그건 «다음 16:45» 라, 단타 화면이 열릴 때
             * 아직 빈 종목을 하나씩 당겨 온다(사용자 선택 2026-08-05).
             * ★<b>살아있는 포지션인지 반드시 확인한다</b> — 아니면 이 문이 「아무 종목이나
             *   원장에 쌓는 문」이 되어 보관 창·용량 규칙을 우회하게 된다. */
            case 'held_init': {
                if ($code === '') $out(['error' => '종목을 고르세요.'], 200);
                if (!in_array($code, $dt->heldCodes(), true)) {
                    $out(['ok' => 0, 'msg' => '보유 중인 종목이 아닙니다.']);
                }
                $out($initLoad($code, false));
            }

            case 'pool_remove':
                if ($code === '') $out(['error' => '대상을 찾지 못했습니다.'], 200);
                $dt->poolRemove($code);
                $out(['ok' => 1, 'msg' => '단타 종목에서 뺐습니다 (쌓아 둔 분봉도 함께 지웠습니다).']);

            case 'pool_sort':
                $codes = $_POST['codes'] ?? $_GET['codes'] ?? [];
                if (!is_array($codes)) $codes = explode(',', (string)$codes);
                $out(['ok' => 1, 'n' => $dt->poolSort($codes)]);

            // ── 시세 ────────────────────────────────────────────────
            case 'series': {
                $unit = (int)($_GET['unit'] ?? 1);
                if ($code === '' || !in_array($unit, Dt::UNITS, true)) $out([]);
                $to   = Dt::cleanDate((string)($_GET['to'] ?? '')) ?: date('Y-m-d');
                $from = Dt::cleanDate((string)($_GET['from'] ?? '')) ?: $to;

                /* 장중 실시간 — 당일분을 얹는다.
                 * ★소스는 <b>키움</b>이다(2026-08-06) — 원장(dt_min)과 같아야 한 화면의 봉이 안 섞인다.
                 *   알맹이·캐시·네이버 폴백은 Dt::todayBars() 단일본에 있다.
                 *   같은 tick 의 메인(1분)·보조(3분)가 그 캐시를 나눠 쓰므로 콜은 <b>한 번</b>이다.
                 * 같은 분이 양쪽에 있으면 <b>DB 가 이긴다</b>(Dt::series 가 그렇게 합친다). */
                /* ★시간 창을 여기 적지 않는다 — todayBars() 가 스스로 판단한다.
                 *   여기에 `hm<=1600` 을 적어 뒀다가 <b>16:00~16:45 사이 45분</b> 동안
                 *   차트가 어제 봉을 보여 줬다(원장은 16:45 크론이 채우기 전이었다). */
                $extra = [];
                $live  = !empty($_GET['live']) && $to === date('Y-m-d');
                if ($live) $extra = $dt->todayBars($code);
                $out($dt->series($code, $unit, $from, $to,
                                 (int)($_GET['limit'] ?? 4000), $extra));
            }

            case 'status':
                if ($code === '') $out([]);
                $out(['rows' => $dt->statusOf($code), 'window' => $dt->windowFrom()]);

            // ── 진단 [V-1][V-4] — 키움 시각 기준·거래량 기준을 네이버와 대조 ──
            case 'diag':
                if ($code === '') $out(['error' => '종목을 고르세요.'], 200);
                $out((new Kiwoom($pdo))->diag($code));

            default:
                $out(['error' => '알 수 없는 action 입니다.'], 200);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ══ 급등주 아카이브 분봉 (qm_bar) ═══════════════════════════════════════
/**
 * module=qm — 급등주 분봉 아카이브를 «읽기만» 한다. 전부 JSON.
 *
 * ★단타(`module=dt`)와 표가 다르다 — `dt_min` 은 최근 10거래일만 두고 매일 지우는 표라
 *   1년 전 신호에는 쓸 수 없다. 아카이브(`qm_bar`)는 급등일 «전후 10거래일»을 지우지 않고 담는다.
 *   그래서 길을 따로 낸다(급등주 규칙 §1 「qm_* 는 dt_* 와 조인하지 않는다」와 같은 결).
 *
 * ★수집·판정은 여기서 하지 않는다 — 이 파일은 이미 쌓인 봉을 꺼내 줄 뿐이다.
 *   (키움을 부르는 자리는 `cron/qm_collect.php` 하나다.)
 */
/**
 * ══ module=bx — 박스 상향돌파 (2026-08-10 신설) ═════════════════════════
 *
 * action=live — ⚡<b>장중 «잠정» 후보</b>. 판정 단일본은 `stock/lib/boxbrk.php` 의 `boxbrk_live()`.
 *
 * ★<b>장 밖에서는 나가지 않는다</b> — 거래일·시각을 여기서 먼저 막는다(단타의 `refreshQuotesLive`
 *   와 같은 문지기). 없으면 토요일에 화면을 열어 둔 것만으로 키움 콜이 나간다.
 * ★상한이 <b>15:30 «전»</b>인 것은 시세 규칙과 같은 이유다 — 15:30 종가 단일가 «직전» 값을
 *   확정처럼 보여 주면 안 된다. 마감 뒤의 답은 16:20 크론이 담은 `bx_cand` 다.
 * ★결과를 <b>표에 담지 않는다</b>(boxbrk_live 주석) — 잠정치와 확정을 한 표에 섞지 않는다.
 */
function api_bx(string $action, PDO $pdo): void
{
    header('Content-Type: application/json; charset=utf-8');
    $out = function ($v, int $code = 200) {
        http_response_code($code);
        echo json_encode($v, JSON_UNESCAPED_UNICODE);
        exit;
    };

    try {
        switch ($action) {
            case 'live': {
                require_once __DIR__ . '/lib/boxbrk.php';
                $hm = (int)date('Hi');
                $dt = new Dt($pdo);
                if ($hm < 900 || $hm >= 1530 || !$dt->isTradingDay()) {
                    $out(['ok' => 1, 'off' => 1, 'rows' => [], 'at' => date('H:i:s'),
                          'note' => $dt->isTradingDay()
                                    ? '장중(09:00~15:30)에만 잽니다 — 마감 뒤 확정 후보는 16:20 에 담깁니다.'
                                    : '오늘은 거래일이 아닙니다.']);
                }
                $r = boxbrk_live($pdo);
                $out(['ok' => 1, 'off' => 0] + $r);
            }
            /**
             * action=search&q= — 패턴분석 검색칸의 자동완성.
             *
             * ★원천이 <b>`bx_cand` 자신</b>이다(`Pf::stockSearch()` 의 전종목이 아니다) —
             *   이 화면은 「5조건을 통과한 날」만 담으므로, 신호가 없는 종목을 제안하면
             *   고르는 순간 빈 화면이 된다. <b>제안은 반드시 결과가 있는 것</b>이어야 한다.
             * ★건수를 함께 준다 — 한 종목이 여러 날 신호를 내므로 「몇 건인가」가 고를 때의 정보다.
             */
            case 'search': {
                $q = trim((string)($_GET['q'] ?? ''));
                if ($q === '' || mb_strlen($q) > 40) $out([]);
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
                $st = $pdo->prepare(
                    "SELECT code, MAX(name) name, COUNT(*) n, MAX(d) last_d
                       FROM bx_cand WHERE name LIKE :a OR code LIKE :b
                      GROUP BY code ORDER BY n DESC, last_d DESC LIMIT 12");
                $st->execute([':a' => $like, ':b' => $like]);
                $rows = [];
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $rows[] = ['code' => $r['code'], 'name' => (string)$r['name'],
                               'n' => (int)$r['n'], 'last_d' => (string)$r['last_d']];
                }
                $out($rows);
            }
            default:
                $out(['error' => '알 수 없는 action 입니다.'], 200);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function api_qm(string $action, PDO $pdo): void
{
    header('Content-Type: application/json; charset=utf-8');
    $out = function ($v, int $code = 200) {
        http_response_code($code);
        echo json_encode($v, JSON_UNESCAPED_UNICODE);
        exit;
    };

    try {
        switch ($action) {
            /**
             * action=bars&code=&d=&span=10 — 신호일 앞뒤 span 거래일의 1분봉.
             *
             * ★구간의 «거래일»은 `krx_amt` 로 센다 — 휴장일 표를 새로 만들지 않는다
             *   (단타 규칙 2 · 퀀트 · 포트폴리오가 전부 같은 원장을 본다).
             *   달력 날짜로 ±14일 하면 연휴가 낀 구간만 조용히 짧아진다.
             * ★없으면 빈 배열 + 200 이다 — 「이 신호는 아카이브에 없다」는 오류가 아니다.
             */
            case 'bars': {
                $code = preg_replace('/[^0-9A-Za-z]/', '', (string)($_GET['code'] ?? ''));
                $d    = (string)($_GET['d'] ?? '');
                if (strlen($code) !== 6 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $out([]);
                $span = max(1, min(30, (int)($_GET['span'] ?? 10)));

                $st = $pdo->prepare("SELECT d FROM krx_amt WHERE code=? AND d<=? ORDER BY d DESC LIMIT " . ($span + 1));
                $st->execute([$code, $d]);
                $back = $st->fetchAll(PDO::FETCH_COLUMN);
                $from = $back ? end($back) : $d;

                $st = $pdo->prepare("SELECT d FROM krx_amt WHERE code=? AND d>? ORDER BY d ASC LIMIT " . $span);
                $st->execute([$code, $d]);
                $fwd = $st->fetchAll(PDO::FETCH_COLUMN);
                $to  = $fwd ? end($fwd) : $d;

                $st = $pdo->prepare(
                    "SELECT ts, o, h, l, c, v FROM qm_bar
                      WHERE code=? AND ts >= ? AND ts < ? + INTERVAL 1 DAY ORDER BY ts");
                $st->execute([$code, $from, $to]);
                $rows = [];
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
                    $rows[] = ['t' => substr($b['ts'], 0, 16), 'o' => (int)$b['o'], 'h' => (int)$b['h'],
                               'l' => (int)$b['l'], 'c' => (int)$b['c'], 'v' => (int)$b['v']];
                }
                $out($rows);
            }

            default:
                $out(['error' => '알 수 없는 action 입니다.'], 200);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ══ 관심차트 그룹 ═══════════════════════════════════════════════════════
/**
 * module=fav — 「보다가 괜찮은 차트를 그룹으로 묶는다」. 전부 JSON.
 * DB 는 `classes/ChartFav.class` 가 전담한다(이 함수는 받아 넘기고 결과만 적는다).
 */
function api_fav(string $action, PDO $pdo): void
{
    header('Content-Type: application/json; charset=utf-8');
    $out = function ($v, int $code = 200) {
        http_response_code($code);
        echo json_encode($v, JSON_UNESCAPED_UNICODE);
        exit;
    };

    try {
        $fav = new ChartFav($pdo);
        $src = (string)($_REQUEST['src'] ?? 'flame');
        if (!isset(ChartFav::SRCS[$src])) $src = 'flame';
        /* 담을 것들은 'code|d' 배열로 온다 — 키 하나가 «신호 한 건»이다 */
        $keys = $_REQUEST['keys'] ?? [];
        if (is_string($keys)) $keys = array_filter(explode(',', $keys));
        if (!is_array($keys)) $keys = [];

        switch ($action) {
            case 'list':
                $out(['groups' => $fav->groups($src)]);

            /* 새 그룹이면 id=0 · 이름만 바꿀 때도 같은 자리 */
            case 'group_save': {
                $id = $fav->groupSave((int)($_REQUEST['id'] ?? 0),
                                      (string)($_REQUEST['name'] ?? ''),
                                      (string)($_REQUEST['memo'] ?? ''));
                /* ★그룹을 만들면서 «바로 담는» 길 — 「새 그룹에 담기」가 두 번 왕복하지 않게 */
                $n = $keys ? $fav->add($id, $src, $keys) : 0;
                $out(['ok' => true, 'id' => $id, 'added' => $n, 'groups' => $fav->groups($src)]);
            }

            case 'group_del':
                $fav->groupDelete((int)($_REQUEST['id'] ?? 0));
                $out(['ok' => true, 'groups' => $fav->groups($src)]);

            case 'add': {
                $id = (int)($_REQUEST['fav_id'] ?? 0);
                if ($id <= 0 || !$fav->group($id)) $out(['ok' => false, 'message' => '그룹을 고르세요.']);
                $n = $fav->add($id, $src, $keys);
                $out(['ok' => true, 'added' => $n, 'groups' => $fav->groups($src),
                      'member' => $fav->memberOf($src, $keys)]);
            }

            case 'remove': {
                $id = (int)($_REQUEST['fav_id'] ?? 0);
                if ($id <= 0) $out(['ok' => false, 'message' => '그룹을 고르세요.']);
                $n = $fav->remove($id, $src, $keys);
                $out(['ok' => true, 'removed' => $n, 'groups' => $fav->groups($src),
                      'member' => $fav->memberOf($src, $keys)]);
            }

            default:
                $out(['ok' => false, 'message' => '알 수 없는 action 입니다.']);
        }
    } catch (Throwable $e) {
        $out(['ok' => false, 'message' => $e->getMessage()]);
    }
}

// ══ badge 모듈 — 목록 배지 표시 설정 ═══════════════════════════════════════
/**
 * 카탈로그·차분 저장의 단일본은 classes/BadgeFeat.class 다. 화면은 «설정 > 신호분석 설정».
 * ★체크칸이 곧 저장이다(버튼 없음 — 관심차트 담기와 같은 규칙). 실패하면 화면이 체크를 되돌린다.
 * ★끄는 것은 «그리기»뿐 — 판정은 이 설정과 무관하게 그대로 돈다.
 */
function api_badge(string $action, PDO $pdo): void
{
    header('Content-Type: application/json; charset=utf-8');
    $out = function ($v, int $code = 200) {
        http_response_code($code);
        echo json_encode($v, JSON_UNESCAPED_UNICODE);
        exit;
    };

    try {
        switch ($action) {
            // action=save — screen + vals(JSON {badge:0|1}) 한 화면치 통째로 → 차분만 저장
            case 'save': {
                $screen = (string)($_POST['screen'] ?? '');
                if (!isset(BadgeFeat::SCREENS[$screen])) {
                    $out(['ok' => false, 'message' => '모르는 화면입니다: ' . $screen], 400);
                }
                $vals = $_POST['vals'] ?? '{}';
                if (is_string($vals)) $vals = json_decode($vals, true);
                if (!is_array($vals)) $vals = [];
                $out(['ok' => true, 'values' => BadgeFeat::save($screen, $vals, $pdo)]);
            }

            case 'reset': {   // 그 화면을 기본값(전부 표시)으로 — 차분이 비어 NULL 로 저장된다
                $screen = (string)($_POST['screen'] ?? '');
                if (!isset(BadgeFeat::SCREENS[$screen])) {
                    $out(['ok' => false, 'message' => '모르는 화면입니다: ' . $screen], 400);
                }
                $out(['ok' => true, 'values' => BadgeFeat::save($screen, BadgeFeat::defaults($screen), $pdo)]);
            }

            default:
                $out(['ok' => false, 'message' => '알 수 없는 action 입니다: ' . $action], 400);
        }
    } catch (Throwable $e) {
        $out(['ok' => false, 'message' => $e->getMessage()]);
    }
}
?>
