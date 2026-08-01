<?php
/**
 * stock/tests/sim_test.php — 백테스트 시뮬레이터 단위 테스트 (CLI 전용)
 *
 * 실행: php stock/tests/sim_test.php
 *
 * 시뮬레이터는 매매 판단을 calc.php 에 위임하므로, 여기서는
 *   ① CSV 파싱 ② 손으로 계산 가능한 시나리오의 체결·손익 ③ 경계조건
 * 만 확인한다. 계산 규칙 자체는 calc_test.php 가 담당한다.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit;
}

require_once __DIR__ . '/../lib/calc.php';
require_once __DIR__ . '/../lib/sim.php';

$GLOBALS['pf_pass'] = 0;
$GLOBALS['pf_fail'] = 0;

function t_head(string $title): void
{
    echo "\n" . str_repeat('─', 74) . "\n {$title}\n" . str_repeat('─', 74) . "\n";
}

function t_eq(string $label, $expect, $actual, float $tol = 0.0): void
{
    $ok = ($expect === null || $actual === null)
        ? ($expect === $actual)
        : (abs((float)$expect - (float)$actual) <= $tol);

    if ($ok) {
        $GLOBALS['pf_pass']++;
        printf("  [PASS] %-46s = %s\n", $label, t_fmt($actual));
    } else {
        $GLOBALS['pf_fail']++;
        printf("  [FAIL] %-46s = %s   (기대 %s)\n", $label, t_fmt($actual), t_fmt($expect));
    }
}

function t_true(string $label, $actual): void { t_eq($label, true, (bool)$actual); }

function t_fmt($v): string
{
    if ($v === null) return 'null';
    if (is_bool($v)) return $v ? 'true' : 'false';
    if (is_int($v))  return number_format($v);
    if (is_string($v)) return $v;
    return rtrim(rtrim(number_format((float)$v, 4, '.', ','), '0'), '.');
}

/** 종가만 있는 가짜 시세 */
function t_series(array $closes, string $start = '2020-01-06'): array
{
    $out = [];
    $t   = strtotime($start);
    foreach ($closes as $c) {
        $out[] = ['d' => date('Y-m-d', $t), 'c' => (float)$c];
        $t += 86400;
    }
    return $out;
}

// 비용 0 · 호가단위 1 → 손으로 검산 가능한 파라미터
$P0 = ['buy_cost_rate' => 0.0, 'sell_cost_rate' => 0.0, 'sell_fee_rate' => 0.0,
       'tax_rate' => 0.0, 'tick' => 1, 'fee_tiers' => []];

// 3단계 룰셋: 비중 20/30/50, 하락 -10%/-10%, 목표 10%
$R3 = [
    1 => ['weight' => 0.20, 'drop_rate' =>  0.00, 'target_rate' => 0.10],
    2 => ['weight' => 0.30, 'drop_rate' => -0.10, 'target_rate' => 0.10],
    3 => ['weight' => 0.50, 'drop_rate' => -0.10, 'target_rate' => 0.10],
];

// ══════════════════════════════════════════════════════════════════════
t_head('1. 매수 트리거 — 하락하면 차수를 밟는다');

// 10,000 → 9,000 → 8,100. 한도 1,000만.
//   1차: 10,000 에 목표 200만 → 200주
//   2차: 이론가 9,000 도달, 누적목표 500만 − 투입 200만 = 300만 ÷ 9,000 = 333주
//   3차: 이론가 8,100 도달, 누적목표 1,000만 − 투입 499.7만 = 500.3만 ÷ 8,100 = 617주
$s = pf_sim_run(t_series([10000, 9000, 8100]), $R3, ['limit_amt' => 10000000], $P0);

t_eq('매수 3회',        3,   $s['buy_count']);
t_eq('매도 0회',        0,   $s['sell_count']);
t_eq('도달 최고 차수',  3,   $s['max_step']);
t_eq('1차 수량',        200, $s['trades'][0]['qty']);
t_eq('1차 단가',        10000, $s['trades'][0]['price']);
t_eq('2차 수량',        333, $s['trades'][1]['qty']);
t_eq('3차 수량',        617, $s['trades'][2]['qty']);
t_eq('보유수량 합',     200 + 333 + 617, $s['held_qty']);

// 투입액 = 200만 + 299.7만 + 499.77만 = 999.47만 → 예수금 = 한도 − 투입
$used = 200 * 10000 + 333 * 9000 + 617 * 8100;
t_eq('예수금',          10000000 - $used, $s['cash'], 0.01);
t_eq('최대 한도소진액',  $used,            $s['max_used'], 0.01);

// ══════════════════════════════════════════════════════════════════════
t_head('2. 차수 건너뛰기 — 하루에 급락해도 누적목표를 따라잡는다');

// 10,000 → 8,000 (2·3차 이론가 9,000·8,100 을 한 번에 통과)
$s = pf_sim_run(t_series([10000, 8000]), $R3, ['limit_amt' => 10000000], $P0);

t_eq('매수 2회 (1차 + 3차)',  2,    $s['buy_count']);
t_eq('두 번째 체결 차수',      3,    $s['trades'][1]['step']);
// 누적목표 1,000만 − 투입 200만 = 800만 ÷ 8,000 = 1,000주
t_eq('따라잡기 수량',          1000, $s['trades'][1]['qty']);
t_eq('총 투입액',              10000000, 200 * 10000 + 1000 * 8000, 0.01);

// ══════════════════════════════════════════════════════════════════════
t_head('3. 자동매도 — 목표수익률 도달 시 전량 매도');

// 1차만 매수한 뒤 반등. 비용 0 이므로 자동매도가 = 10,000 × 1.10 = 11,000
$s = pf_sim_run(t_series([10000, 10500, 11000]), $R3, ['limit_amt' => 10000000], $P0);

t_eq('매수 1회',            1,      $s['buy_count']);
t_eq('매도 1회',            1,      $s['sell_count']);
t_eq('매도 단가',           11000,  $s['trades'][1]['price']);
t_eq('매도 수량 = 보유 전량', 200,   $s['trades'][1]['qty']);
t_eq('종료 시 보유수량',     0,      $s['held_qty']);
// 실현손익 = (11,000 − 10,000) × 200 = 200,000
t_eq('실현손익',            200000, $s['realized_pl'], 0.01);
t_eq('최종 자산',           10200000, $s['asset'], 0.01);
t_eq('총 수익률',           0.02,   $s['total_rate'], 1e-9);
t_eq('사이클 1건',          1,      $s['cycle_count']);
t_eq('사이클 승',           1,      $s['win_count']);
t_eq('사이클 보유일수',      2,      $s['cycles'][0]['days']);

// ══════════════════════════════════════════════════════════════════════
t_head('4. 재진입 옵션');

$px = t_series([10000, 11000, 10000, 11000]);

$off = pf_sim_run($px, $R3, ['limit_amt' => 10000000, 'reenter' => false], $P0);
t_eq('재진입 안 함 — 매수 1회', 1, $off['buy_count']);
t_eq('재진입 안 함 — 매도 1회', 1, $off['sell_count']);

$on = pf_sim_run($px, $R3, ['limit_amt' => 10000000, 'reenter' => true], $P0);
t_eq('재진입 — 매수 2회',       2, $on['buy_count']);
t_eq('재진입 — 매도 2회',       2, $on['sell_count']);
t_eq('재진입 — 사이클 2건',     2, $on['cycle_count']);

// 대기 1일이면 청산 다음 날은 쉬고 그 다음 날 재진입 → 마지막 날 매도 불가
$wait = pf_sim_run($px, $R3, ['limit_amt' => 10000000, 'reenter' => true, 'wait' => 1], $P0);
t_eq('대기 1일 — 매수 2회',     2, $wait['buy_count']);
t_eq('대기 1일 — 매도 1회',     1, $wait['sell_count']);
t_eq('대기 1일 — 미청산 보유',  true, $wait['held_qty'] > 0);

// ══════════════════════════════════════════════════════════════════════
t_head('5. 비용 반영 — 수수료·거래세가 자동매도가를 밀어올린다');

$PC = ['buy_cost_rate' => 0.00015, 'sell_cost_rate' => 0.00215, 'sell_fee_rate' => 0.00015,
       'tax_rate' => 0.0020, 'tick' => 1, 'fee_tiers' => []];

// 누적단가 = 10,000 × 1.00015 = 10,001.5
// 자동매도가 = ceil(10,001.5 × 1.10 ÷ (1 − 0.00215)) = ceil(11,025.35) = 11,026
t_eq('자동매도가',        11026, pf_auto_sell_price(10001.5, 0.10, $PC));
$s = pf_sim_run(t_series([10000, 11000, 11026]), $R3, ['limit_amt' => 10000000], $PC);
t_eq('비용 반영 매도가',  11026, $s['trades'][1]['price']);
t_eq('11,000 에는 안 팔림', '2020-01-08', $s['trades'][1]['d']);
t_true('실현손익 > 0',     $s['realized_pl'] > 0);

// ══════════════════════════════════════════════════════════════════════
t_head('6. 경계조건');

$s = pf_sim_run([], $R3, ['limit_amt' => 10000000], $P0);
t_eq('시세 없으면 ok=false', false, $s['ok']);

$s = pf_sim_run(t_series([10000]), $R3, ['limit_amt' => 0], $P0);
t_eq('한도 0 이면 ok=false', false, $s['ok']);

// 한도가 너무 작아 1주도 못 사는 경우
$s = pf_sim_run(t_series([10000, 9000]), $R3, ['limit_amt' => 1000], $P0);
t_eq('한도 1,000원 — 매수 0회', 0, $s['buy_count']);
t_eq('한도 1,000원 — 자산 보존', 1000, $s['asset'], 0.01);

// 계속 하락만 하는 시세 — 한도를 넘겨 사지 않는다
$s = pf_sim_run(t_series([10000, 9000, 8000, 7000, 6000, 5000]), $R3, ['limit_amt' => 10000000], $P0);
t_true('총 투입액 ≤ 한도', $s['max_used'] <= 10000000 + 1e-6);
t_true('예수금 ≥ 0',        $s['cash'] >= -1e-6);
t_eq('최고 차수 3 초과 없음', 3, $s['max_step']);

// 기간 슬라이스
$rows = t_series([1, 2, 3, 4, 5]);   // 2020-01-06 ~ 2020-01-10
t_eq('slice 전체',        5, count(pf_sim_slice($rows)));
t_eq('slice 시작일 지정',  3, count(pf_sim_slice($rows, '2020-01-08')));
t_eq('slice 종료일 지정',  2, count(pf_sim_slice($rows, '', '2020-01-07')));
t_eq('slice 양쪽 지정',    2, count(pf_sim_slice($rows, '2020-01-08', '2020-01-09')));

// ══════════════════════════════════════════════════════════════════════
t_head('7. 자산곡선 / MDD');

$s = pf_sim_run(t_series([10000, 8000, 8000]), $R3, ['limit_amt' => 10000000], $P0);
t_eq('자산곡선 길이 = 거래일 수', 3, count($s['equity']));
t_true('MDD ≤ 0',                 $s['mdd'] <= 0);
t_eq('첫날 자산 ≈ 원금',          10000000, $s['equity'][0]['asset'], 1.0);

// 오르기만 하면 낙폭 없음 (1차 매수 → 바로 청산 → 재진입 안 함)
$s = pf_sim_run(t_series([10000, 11000]), $R3, ['limit_amt' => 10000000], $P0);
t_eq('상승만 하면 MDD 0',         0.0, $s['mdd'], 1e-9);

// ── 자동매도가 곡선 (차트 매도가격선의 원본)
//    차수를 밟아 누적단가가 내려가면 자동매도가도 같이 내려온다.
//      1차 avg 10,000                            → ceil(11,000.0) = 11,000
//      2차 avg 4,997,000 ÷ 533   = 9,375.23      → ceil(10,312.8) = 10,313
//      3차 avg 9,994,700 ÷ 1,150 = 8,691.04      → ceil( 9,560.1) =  9,561
$s = pf_sim_run(t_series([10000, 9000, 8100]), $R3, ['limit_amt' => 10000000], $P0);
t_eq('1차 자동매도가', 11000, $s['equity'][0]['sell']);
t_eq('2차 자동매도가', 10313, $s['equity'][1]['sell']);
t_eq('3차 자동매도가',  9561, $s['equity'][2]['sell']);

// 보유가 없는 날은 값이 없어야 선이 끊긴다 (2일차에 전량 청산)
$s = pf_sim_run(t_series([10000, 11000]), $R3, ['limit_amt' => 10000000], $P0);
t_eq('청산일 자동매도가 = null', null, $s['equity'][1]['sell']);

// ══════════════════════════════════════════════════════════════════════
t_head('8. 장중 모드 — 고가·저가로 판단하고 지정가로 체결');

// 1일: 시10,000 고10,200 저9,900 종10,100  → 1차는 걸어 둔 주문이 없으니 시가(10,000) 체결
//      1차가 체결되면 사다리 기준가는 실매수가 10,000 → 2차 이론가 = 10,000×0.9 = 9,000
// 2일: 시 9,500 고 9,600 저8,800 종 9,400  → 저가가 9,000 을 통과 → 지정가 9,000 체결
// 3일: 시10,000 고10,400 저9,900 종10,100  → 자동매도가 10,313 을 고가가 통과 → 10,313 체결
$ohlc = [
    ['d' => '2020-01-06', 'o' => 10000, 'h' => 10200, 'l' => 9900,  'c' => 10100],
    ['d' => '2020-01-07', 'o' =>  9500, 'h' =>  9600, 'l' => 8800,  'c' =>  9400],
    ['d' => '2020-01-08', 'o' => 10000, 'h' => 10400, 'l' => 9900,  'c' => 10100],
];
$s = pf_sim_run($ohlc, $R3, ['limit_amt' => 10000000, 'intraday' => true], $P0);

t_eq('1차 체결가 = 시가',       10000, $s['trades'][0]['price']);
t_eq('1차 수량 (200만 ÷ 시가)', 200,   $s['trades'][0]['qty']);
t_eq('2차 체결가 = 이론가',     9000,  $s['trades'][1]['price']);
t_eq('2차 수량 (300만 ÷ 9,000)', 333,  $s['trades'][1]['qty']);
t_eq('2차 차수',                2,     $s['trades'][1]['step']);

$avg = (200 * 10000 + 333 * 9000) / 533;
t_eq('누적단가',        $avg,  pf_avg_cost([1 => ['price' => 10000, 'qty' => 200],
                                            2 => ['price' => 9000,  'qty' => 333]], $P0), 1e-6);
t_eq('자동매도가',      10313, pf_auto_sell_price($avg, 0.10, $P0));
t_eq('매도 체결가',     10313, $s['trades'][2]['price']);
t_eq('매도 수량',       533,   $s['trades'][2]['qty']);
t_eq('사이클 1건',      1,     $s['cycle_count']);
t_true('실현손익 > 0',  $s['realized_pl'] > 0);

// 고가가 자동매도가에 1원 모자라면 팔지 않는다 (경계)
$near = $ohlc;
$near[2]['h'] = 10312;
$n2 = pf_sim_run($near, $R3, ['limit_amt' => 10000000, 'intraday' => true], $P0);
t_eq('고가 10,312 → 매도 없음', 0, $n2['sell_count']);

// 같은 데이터를 종가 모드로 돌리면 다른 결과 (저가/고가를 안 본다)
$sc = pf_sim_run($ohlc, $R3, ['limit_amt' => 10000000, 'intraday' => false], $P0);
t_eq('종가 모드 1차 체결가', 10100, $sc['trades'][0]['price']);
t_true('종가 모드는 3일차 매도 없음', $sc['sell_count'] === 0);

// 갭하락이면 이론가가 아니라 시가에 체결된다
$gap = [
    ['d' => '2020-01-06', 'o' => 10000, 'h' => 10000, 'l' => 10000, 'c' => 10000],
    ['d' => '2020-01-07', 'o' =>  8000, 'h' =>  8200, 'l' =>  7900, 'c' =>  8100],
];
$g = pf_sim_run($gap, $R3, ['limit_amt' => 10000000, 'intraday' => true], $P0);
t_eq('갭하락 2일차 체결가 = 시가', 8000, $g['trades'][1]['price']);
t_eq('갭하락 도달 차수',           3,    $g['trades'][1]['step']);

// 고가·저가가 없으면 장중 모드를 켜도 종가 모드로 동작한다
$noOhlc = pf_sim_run(t_series([10000, 9000, 8100]), $R3,
                     ['limit_amt' => 10000000, 'intraday' => true], $P0);
$base   = pf_sim_run(t_series([10000, 9000, 8100]), $R3, ['limit_amt' => 10000000], $P0);
t_eq('종가만 있으면 장중 옵션 무시', $base['trades'][1]['price'], $noOhlc['trades'][1]['price']);
t_eq('  매수 횟수도 동일',           $base['buy_count'],           $noOhlc['buy_count']);

// ══ 사이클 전부 (닫힘 + 미청산) ════════════════════════════════════════
/*
 * ★ 미청산 사이클은 `cycles` 가 아니라 `open_cycle` 에 따로 담긴다 —
 *   합치지 않으면 「물린 사이클 0개」 라는 거짓 결론이 나온다(실제로 그렇게 속았다).
 */
t_head('★ pf_sim_all_cycles — open_cycle 을 빠뜨리지 않는다');
t_eq('닫힘 2 + 미청산 1 = 3', 3, count(pf_sim_all_cycles([
    'cycles' => [['no' => 1], ['no' => 2]], 'open_cycle' => ['no' => 3, 'open' => true]])));
t_eq('미청산 없으면 닫힘만', 2, count(pf_sim_all_cycles(['cycles' => [['no' => 1], ['no' => 2]], 'open_cycle' => null])));
t_eq('빈 결과도 안전', 0, count(pf_sim_all_cycles(pf_sim_empty())));
$acAll = pf_sim_all_cycles(['cycles' => [['no' => 1]], 'open_cycle' => ['no' => 2, 'open' => true]]);
t_eq('미청산이 맨 뒤에 온다', true, !empty(end($acAll)['open']));

// ══ 계단관통 손절 (stair_stop · 사다리×퀀트 결합 연구 2026-08-01) ═══════
/*
 * 신호 = 거래대금(c×v)이 직전 120봉 최고를 넘는 날 · 계단 = 그 박스 L 아래 레벨을 주는 최근 박스 2개.
 * 관통선 = 계단 최저값. 보유 중 종가가 관통선 −2% 아래로 마감하면 전량 청산(체결도 종가).
 * 픽스처: 121봉 워밍업(100원·소량) 뒤 신호 A(200)→B(300)→C(400) 계단을 쌓고,
 *   마지막 사이클이 400 에 진입 → 300(2차) → 190(관통선 200 의 −2%=196 아래) 로 무너진다.
 */
t_head('★ 계단관통 손절 — 지지구조가 전부 뚫리면 사다리를 중단한다');

$vrows = [];
for ($i = 0; $i < 121; $i++) $vrows[] = [100, 10];           // 워밍업 (이력 120봉 요건)
$vrows[] = [200, 1000];                                       // 신호 A — 첫 박스(계단 없음)
for ($i = 0; $i < 4; $i++) $vrows[] = [210, 10];
$vrows[] = [300, 2000];                                       // 신호 B — 계단 = A(200)
$vrows[] = [310, 10];
$vrows[] = [400, 3000];                                       // 신호 C — 계단 = B(300)·A(200) → 관통선 200
$vrows[] = [400, 10];                                         // 마지막 사이클 1차 진입 @400
$vrows[] = [300, 10];                                         // 2차 (400×0.9=360 이하)
$vrows[] = [190, 10];                                         // 종가 190 < 196 → 관통 청산
$vseries = [];
$vt = strtotime('2020-01-06');
foreach ($vrows as [$vc, $vv]) { $vseries[] = ['d' => date('Y-m-d', $vt), 'c' => (float)$vc, 'v' => (float)$vv]; $vt += 86400; }

$ssOn  = pf_sim_run($vseries, $R3, ['limit_amt' => 10000000, 'reenter' => true, 'wait' => 0, 'stair_stop' => true],  $P0);
$ssOff = pf_sim_run($vseries, $R3, ['limit_amt' => 10000000, 'reenter' => true, 'wait' => 0],                        $P0);

t_true('옵션 켜짐(stair_used)',             $ssOn['stair_used']);
t_eq('관통 청산 1회(stair_count)',    1,    $ssOn['stair_count']);
t_eq('마지막 사이클은 관통 청산',      true, !empty(end($ssOn['cycles'])['stair']));
t_eq('관통 체결가 = 그 날 종가 190',  190,  end($ssOn['cycles'])['exit_price']);
t_eq('관통 후 보유 0',                0,    $ssOn['held_qty']);
t_eq('앞선 정상 청산은 stair 아님',    true, empty($ssOn['cycles'][0]['stair']));

// 같은 시세에서 옵션이 꺼져 있으면 — 마지막 사이클은 3차까지 담고 물린 채 끝난다
t_eq('OFF: 관통 청산 0회',            0,    $ssOff['stair_count']);
t_true('OFF: 미청산 사이클로 남는다',        $ssOff['open_cycle'] !== null);
t_eq('OFF: 매도 횟수는 정상청산만',    $ssOn['sell_count'] - 1, $ssOff['sell_count']);

// 진입가가 이미 관통선 아래면 손절 없음 (알려진 지지 아래에서 시작한 사이클은 자르지 않는다)
$vlow = array_slice($vseries, 0, 129);                        // 신호 C 봉까지만 자름
$vt2  = strtotime(end($vlow)['d']) + 86400;
foreach ([150, 100, 90] as $c2) { $vlow[] = ['d' => date('Y-m-d', $vt2), 'c' => (float)$c2, 'v' => 10.0]; $vt2 += 86400; }
$ssLow = pf_sim_run($vlow, $R3, ['limit_amt' => 10000000, 'reenter' => true, 'wait' => 0, 'stair_stop' => true], $P0);
t_eq('관통선 아래 진입 → 손절 미적용', 0,   $ssLow['stair_count']);
t_true('  물린 채 미청산으로 남는다',        $ssLow['open_cycle'] !== null);

// 거래량이 없는 데이터는 옵션을 켜도 조용히 꺼진다
$noV = pf_sim_run(t_series([10000, 9000, 8100]), $R3, ['limit_amt' => 10000000, 'stair_stop' => true], $P0);
t_eq('거래량 없으면 stair_used=false', false, $noV['stair_used']);

// ── 차수 지연 (룰셋 차수별 delay_days) — 느린 「한 차수」 하락은 건너뛰고 한 차수 더 아래에서 산다
/*
 * 픽스처: 1차 @10,000 → 40일 횡보(9,500 — 트리거 없음) → 9,000(2차 트리거·41일 경과 > 30 → 스킵)
 *   → 8,100(3차 이론가 = 9,000×0.9) 도달 → 무조건 매수. catch-up 이 2차 몫까지 흡수:
 *   누적목표 1,000만 − 투입 200만 = 800만 ÷ 8,100 = 987주.
 */
$R3D = $R3;
$R3D[2]['delay_days'] = 30;          // 2차에만 지연 30일
$slow = t_series(array_merge([10000], array_fill(0, 40, 9500), [9000, 9000, 8100]));
$dOn  = pf_sim_run($slow, $R3D, ['limit_amt' => 10000000], $P0);
$dOff = pf_sim_run($slow, $R3,  ['limit_amt' => 10000000], $P0);
t_eq('스킵 1회(delay_skips)',        1,    $dOn['delay_skips']);
t_eq('매수 2회 (2차는 건너뜀)',       2,    $dOn['buy_count']);
t_eq('두번째 매수는 3차로 기록',      3,    $dOn['trades'][1]['step']);
t_eq('  체결가 8,100 (한 차수 아래)', 8100, $dOn['trades'][1]['price']);
t_eq('  수량 987 = catch-up 흡수',    987,  $dOn['trades'][1]['qty']);
t_eq('지연 없는 룰셋: 매수 3회',      3,    $dOff['buy_count']);

// 지연이 「그 차수에만」 붙는다 — 2차는 지연 없음이라 느려도 사고, 3차(지연 30)는 느리면 스킵.
// 3단계 룰셋이라 3차 아래가 없어 스킵된 금액은 그대로 안 쓰인다(가장 깊은 구간 노출 축소).
$R3E = $R3;
$R3E[3]['delay_days'] = 30;
$slow2 = t_series(array_merge([10000], array_fill(0, 40, 9500), [9000], array_fill(0, 40, 8500), [8100, 8100]));
$dSel = pf_sim_run($slow2, $R3E, ['limit_amt' => 10000000], $P0);
t_eq('2차(지연없음)는 느려도 산다',   2,    $dSel['trades'][1]['step']);
t_eq('3차(지연30)는 느려서 스킵',     1,    $dSel['delay_skips']);
t_eq('  매수는 1·2차 두 번뿐',        2,    $dSel['buy_count']);

// 같은 날 두 차수 이상 급락은 지연과 무관하게 그대로 산다 (스킵은 「느린 단독 하락」에만)
$crash = t_series(array_merge([10000], array_fill(0, 40, 9500), [8100]));
$dCr = pf_sim_run($crash, $R3D, ['limit_amt' => 10000000], $P0);
t_eq('급락(2차수 점프)은 스킵 없음',  0,    $dCr['delay_skips']);
t_eq('  그날 3차까지 매수',           3,    $dCr['max_step']);

// 빠른 하락(지연 이내)은 정상 매수
$fast = pf_sim_run(t_series([10000, 9000, 8100]), $R3D, ['limit_amt' => 10000000], $P0);
t_eq('빠른 하락은 스킵 없음',         0,    $fast['delay_skips']);
t_eq('  매수 3회 그대로',             3,    $fast['buy_count']);

// ══════════════════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 74) . "\n";
printf(" 결과: %d PASS / %d FAIL  (총 %d)\n",
    $GLOBALS['pf_pass'], $GLOBALS['pf_fail'], $GLOBALS['pf_pass'] + $GLOBALS['pf_fail']);
echo str_repeat('═', 74) . "\n";

exit($GLOBALS['pf_fail'] > 0 ? 1 : 0);
?>
