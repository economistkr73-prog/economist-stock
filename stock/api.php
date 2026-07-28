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
require_login();

$pf = new Pf($pdo);
$pf->ensureTables();

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

            $newId = $pf->portfolioSave([
                'id'        => $id ?: null,
                'name'      => $name,
                'broker_id' => (int)($_POST['broker_id'] ?? 0),
                'acct_no'   => trim($_POST['acct_no'] ?? ''),
                'memo'      => trim($_POST['memo'] ?? ''),
                'note'      => mb_substr(trim($_POST['note'] ?? ''), 0, 200),
                'sort_no'   => (int)($_POST['sort_no'] ?? 0),
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
            ]);

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

        case 'save':
            $id = (int)($_POST['id'] ?? 0);
            $pf->ruleSetSave([
                'id'         => $id,
                'name'       => trim($_POST['name'] ?? ''),
                'volatility' => $_POST['volatility'] ?? null,
                'memo'       => trim($_POST['memo'] ?? ''),
            ]);
            pf_api_done($back . '&rid=' . $id, 'ok', '룰셋 정보를 저장했습니다.');

        case 'steps':
            $rid    = (int)($_POST['rule_set_id'] ?? 0);
            $stepNo = $_POST['step_no'] ?? [];
            $steps  = [];

            foreach ($stepNo as $i => $n) {
                $steps[] = [
                    'step_no'     => $i + 1,   // 화면 순서대로 1..N 재부여
                    'weight'      => (float)pf_arr('weight', $i, 0)      / 100,
                    'drop_rate'   => (float)pf_arr('drop_rate', $i, 0)   / 100,
                    'target_rate' => (float)pf_arr('target_rate', $i, 0) / 100,
                ];
            }
            if (!$steps) pf_api_done($back . '&rid=' . $rid, 'err', '차수가 하나도 없습니다.');

            $pf->ruleStepsReplace($rid, $steps);
            pf_api_done($back . '&rid=' . $rid, 'ok', count($steps) . '개 차수를 저장했습니다.');

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
            $limit = (float)($_GET['limit'] ?? 0);
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
        case 'save':
            $id   = (int)($_POST['id'] ?? 0);
            $code = trim($_POST['stock_code'] ?? '');
            $name = trim($_POST['stock_name'] ?? '');

            if ($code === '') pf_api_done('/stock/index.php?mode=position&id=new', 'err', '종목코드를 입력하세요.');

            $inLast = trim((string)($_POST['last_price'] ?? ''));
            $inHigh = trim((string)($_POST['high_price'] ?? ''));

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
            ]);
            pf_api_done('/stock/index.php?mode=position&id=' . $pid, 'ok', '종목 설정을 저장했습니다.');

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

    $out = [];
    foreach ($positions as $p) {
        $steps  = $stepsMap[(int)$p['rule_set_id']] ?? [];
        if (!$steps) continue;

        $rows   = $tradesMap[(int)$p['id']] ?? [];
        $last   = ($p['last_price'] !== null) ? (float)$p['last_price'] : null;
        $prm    = pf_cost_params($p, $feeMap[(int)$p['broker_id']] ?? []);
        $c      = pf_position_calc($steps, pf_trades_by_step($rows), (float)$p['limit_amt'], $last, $prm, pf_ledger($rows, $prm));

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
            $qty   = (int)($_POST['qty'] ?? 0);
            $price = (float)($_POST['price'] ?? 0);
            $date  = ($_POST['traded_at'] ?? '') ?: date('Y-m-d');

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
                    'min_amt'   => max(0, (int)$min),
                    'fee_rate'  => max(0, (float)pf_arr('fee_rate', $i, 0)) / 100,
                    'fee_fixed' => max(0, (int)pf_arr('fee_fixed', $i, 0)),
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

        // ── 연도별 주식수 채우기 (PER·PBR 을 그 해 기준으로 맞추려고)
        //    종목별 호출이라 한 번에 다 못 한다 — 이미 채운 행은 건너뛰므로 반복 실행하면 이어진다.
        case 'shares':
            $y     = (int)($_POST['year']  ?? $_GET['year']  ?? 0);
            $limit = (int)($_POST['limit'] ?? $_GET['limit'] ?? 800);
            if ($y <= 0) $y = (int)date('Y') - 1;

            $r = $dart->collectShares($y, max(1, $limit));
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
?>
