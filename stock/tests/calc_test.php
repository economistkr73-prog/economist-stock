<?php
/**
 * stock/tests/calc_test.php — 계산 엔진 단위 테스트 (CLI 전용)
 *
 * 요건정의서 §2 의 검증표를 그대로 코드로 옮긴 것.
 * 실행: php stock/tests/calc_test.php
 *
 * §6 구현순서 "2번을 통과하기 전에 화면 작업으로 넘어가지 말 것" 의 관문.
 */

// CLI 전용. 사이트의 나머지가 전부 로그인 뒤에 있으므로 웹으로 열리지 않게 막는다.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit;
}

/* ★Thr 을 먼저 읽는다 — calc.php 의 판정 함수가 임계를 여기서 가져온다(M5).
 *   웹에서는 cnt.inc 의 오토로더가 맡지만 이 테스트는 CLI 단독 실행이라 직접 건다.
 *   (fmt.php 를 쓰게 됐을 때와 같은 사정 — 순수 상수라 CLI 안전) */
require_once __DIR__ . '/../../classes/Thr.class';
require_once __DIR__ . '/../lib/calc.php';
require_once __DIR__ . '/../lib/fmt.php';   // pf_age_txt 등 표시 포맷터 (순수 함수라 CLI 안전)

$GLOBALS['pf_pass'] = 0;
$GLOBALS['pf_fail'] = 0;

function t_head(string $title): void
{
    echo "\n" . str_repeat('─', 74) . "\n {$title}\n" . str_repeat('─', 74) . "\n";
}

function t_eq(string $label, $expect, $actual, float $tol = 0.0): void
{
    /* ★ 숫자가 아닌 값은 <b>정확히</b> 견준다.
     *   예전에는 전부 (float) 로 캐스팅해서 문자열끼리는 'buy' → 0.0, 'sell' → 0.0 이 되어
     *   <b>무엇과 비교해도 통과</b>했다(등급·신호종류·tone 테스트가 통째로 헛통과). */
    $isStr = fn($v) => is_string($v) && !is_numeric($v);

    $ok = ($expect === null || $actual === null || is_bool($expect) || is_bool($actual)
           || $isStr($expect) || $isStr($actual))
        ? ($expect === $actual)
        : (abs((float)$expect - (float)$actual) <= $tol);

    if ($ok) {
        $GLOBALS['pf_pass']++;
        printf("  [PASS] %-42s = %s\n", $label, t_fmt($actual));
    } else {
        $GLOBALS['pf_fail']++;
        printf("  [FAIL] %-42s = %s   (기대 %s, 오차 %s)\n",
            $label, t_fmt($actual), t_fmt($expect), t_fmt(abs((float)$expect - (float)$actual)));
    }
}

function t_fmt($v): string
{
    if ($v === null)     return 'null';
    if (is_bool($v))     return $v ? 'true' : 'false';
    if (is_string($v) && !is_numeric($v)) return "'{$v}'";   // 문자열을 0 으로 찍으면 헛통과를 못 알아본다
    if (is_int($v))      return number_format($v);
    return rtrim(rtrim(number_format((float)$v, 4, '.', ','), '0'), '.');
}

// 한국가구 / 하이비스 공통 룰셋 — 변동율 5% (별첨3·4에서 역산, 전 차수 일치 확인됨)
$DROP_5 = [
    1 => ['weight' => 0.0150, 'drop_rate' =>  0.00, 'target_rate' => 0.15],
    2 => ['weight' => 0.0255, 'drop_rate' => -0.07, 'target_rate' => 0.20],
    3 => ['weight' => 0.0255, 'drop_rate' => -0.08, 'target_rate' => 0.25],
    4 => ['weight' => 0.0640, 'drop_rate' => -0.21, 'target_rate' => 0.30],
    5 => ['weight' => 0.1845, 'drop_rate' => -0.25, 'target_rate' => 0.35],
    6 => ['weight' => 0.2770, 'drop_rate' => -0.29, 'target_rate' => 0.40],
    7 => ['weight' => 0.4160, 'drop_rate' => -0.33, 'target_rate' => 0.50],
];

// ══ §2.1 매수비용 ═══════════════════════════════════════════════════════
t_head('§2.1 파라미터 — 매수비용 반영');
t_eq('하이비스 54,760 × 1.0034', 54946, round(54760 * (1 + PF_BUY_COST_RATE)));

// ══ §2.2 이론가 사다리 ══════════════════════════════════════════════════
t_head('§2.2 이론가 사다리 — 한국가구 (별첨4, 변동율 5%)');
/*
 * 별첨4 기준가 흐름 재현용 실체결:
 *   1차 7,140 (사다리 원점)
 *   2차 5,230 (이론 6,640 보다 싸게 체결 → 3차 기준가가 실매수가로 내려감)
 *   3차 5,000 (이론 4,810 보다 비싸게 체결 → 4차 기준가는 이론가 유지)
 *   4차 이후 미체결 → 이론가로만 진행
 */
$kgTrades = [
    1 => ['price' => 7140, 'qty' => 787],
    2 => ['price' => 5230, 'qty' => 1828],
    3 => ['price' => 5000, 'qty' => 1912],
];
$kg = pf_theory_ladder($DROP_5, $kgTrades);

t_eq('2차 기준가 (실 7,140)',        7140, $kg[2]['base_price']);
t_eq('2차 이론가',                   6640, $kg[2]['theory_price']);
t_eq('3차 기준가 (실 5,230 < 이론)', 5230, $kg[3]['base_price']);
t_eq('3차 이론가',                   4810, $kg[3]['theory_price']);
t_eq('4차 기준가 (이론 4,810 < 실)', 4810, $kg[4]['base_price']);
t_eq('4차 이론가',                   3790, $kg[4]['theory_price']);
t_eq('5차 이론가',                   2840, $kg[5]['theory_price']);
t_eq('6차 이론가',                   2010, $kg[6]['theory_price']);
t_eq('7차 이론가',                   1340, $kg[7]['theory_price']);

t_head('§2.2 이론가 사다리 — 하이비스 (별첨3)');
$hbTrades = [1 => ['price' => 54760, 'qty' => 10]];
$hb = pf_theory_ladder($DROP_5, $hbTrades);

foreach ([2 => 50920, 3 => 46840, 4 => 37000, 5 => 27750, 6 => 19700, 7 => 13190] as $n => $exp) {
    t_eq("{$n}차 이론가", $exp, $hb[$n]['theory_price']);
}

// ══ §2.3 차수별 금액 / 수량 ═════════════════════════════════════════════
t_head('§2.3 차수별 금액 — 한국가구 (한도 375백만)');
$limitKg = 375_000_000;
t_eq('1차 1.5%  (백만)',  5.6,   pf_step_amount($limitKg, 0.015) / 1e6, 0.05);
t_eq('6차 27.7% (백만)',  103.9, pf_step_amount($limitKg, 0.277) / 1e6, 0.05);
t_eq('7차 41.6% (백만)',  155.9, pf_step_amount($limitKg, 0.416) / 1e6, 0.15); // 엑셀 표시 반올림 ±0.1

t_head('§2.3 이론수량 — 한도부족 케이스');
t_eq('5.6백만 ÷ 7,140원',   787, pf_step_qty(pf_step_amount($limitKg, 0.015), 7140));
t_eq('0.2백만 ÷ 54,760원 → 3주', 3, pf_step_qty(pf_step_amount(200_000, 1.0), 54760));
t_eq('0.2백만 × 1.5% ÷ 54,760 → 0주(한도부족)', 0, pf_step_qty(pf_step_amount(200_000, 0.015), 54760));

// ══ §2.4 누적단가 ═══════════════════════════════════════════════════════
t_head('§2.4 누적단가 — 하이비스');
t_eq('1차 547,600 × 1.0034 ÷ 10주', 54946,
    round(pf_avg_cost([1 => ['price' => 54760, 'qty' => 10]])));
t_eq('2차 (547,600+50,920) × 1.0034 ÷ 11주', 54596,
    round(pf_avg_cost([
        1 => ['price' => 54760, 'qty' => 10],
        2 => ['price' => 50920, 'qty' => 1],
    ])));

// ══ §2.5 자동매도가 ═════════════════════════════════════════════════════
t_head('§2.5 자동매도가 (목표 15%)');
t_eq('한국주철관 10,385 → 11,980', 11980, pf_auto_sell_price(10385, 0.15));
t_eq('매일홀딩스 10,636 → 12,270', 12270, pf_auto_sell_price(10636, 0.15));
// 아래 둘은 엑셀 화면값과 10~30원 차이. 누적단가 표시 반올림 때문으로 보고 허용오차 처리.
t_eq('하이비스 54,946 → 63,370±10',   63370,  pf_auto_sell_price(54946, 0.15),  10);
t_eq('삼성전자 175,294 → 202,170±30', 202170, pf_auto_sell_price(175294, 0.15), 30);

// ══ §2.6 룰셋 시뮬레이션 ════════════════════════════════════════════════
t_head('§2.6 룰셋 시뮬레이션 — 별첨1 (한도 200백만, 손익분기율 역산 룰셋)');
/*
 * 별첨1 원본 표(하락률)가 없어 §2.6 검증표의 누적금액·손익분기율에서 역산한 룰셋.
 * 재현 결과가 요건정의서 "계산" 열과 소수 둘째자리까지 일치하므로 시드로 사용한다.
 */
$RULE_A1 = [
    1 => ['weight' => 0.0150, 'drop_rate' =>  0.000, 'target_rate' => 0.15],
    2 => ['weight' => 0.0255, 'drop_rate' => -0.100, 'target_rate' => 0.20],
    3 => ['weight' => 0.0255, 'drop_rate' => -0.110, 'target_rate' => 0.25],
    4 => ['weight' => 0.0640, 'drop_rate' => -0.240, 'target_rate' => 0.30],
    5 => ['weight' => 0.1845, 'drop_rate' => -0.275, 'target_rate' => 0.35],
    6 => ['weight' => 0.2775, 'drop_rate' => -0.320, 'target_rate' => 0.40],
    7 => ['weight' => 0.4160, 'drop_rate' => -0.360, 'target_rate' => 0.50],
];
$sim = pf_simulate($RULE_A1, 200_000_000);

foreach ([2 => 8.1, 3 => 13.2, 4 => 26.0, 5 => 62.9, 6 => 118.4, 7 => 201.6] as $n => $exp) {
    t_eq("{$n}차 누적금액 (백만)", $exp, $sim[$n]['cum_amount'] / 1e6, 0.05);
}
foreach ([2 => -3.70, 3 => -8.77, 4 => -15.57, 5 => -16.05, 6 => -22.79, 7 => -29.71] as $n => $exp) {
    t_eq("{$n}차 손익분기율 (%)", $exp, $sim[$n]['breakeven_rate'] * 100, 0.05);
}
t_eq('비중 합계 (%)', 100.8, pf_weight_sum($RULE_A1) * 100, 0.01);

// ══ 종합: pf_position_calc ══════════════════════════════════════════════
t_head('종합 — pf_position_calc (하이비스 1차 보유, 현재가 52,000)');
$pos = pf_position_calc($DROP_5, $hbTrades, 375_000_000, 52000);

t_eq('현재 차수',        1,      $pos['cur_step']);
t_eq('다음 차수',        2,      $pos['next_step']);
t_eq('다음 매수가',      50920,  $pos['next_price']);
/*
 * 누적목표 방식 — 2차 누적목표 = 375M × (1.5%+2.55%) = 15,187,500
 * 1차에 547,600 밖에 안 넣었으므로 미집행 잔액이 그대로 이월된다.
 *   15,187,500 − 547,600 = 14,639,900 ÷ 50,920 = 287주
 */
t_eq('다음 매수금액',    14639900, $pos['next_amount'], 0.5);
t_eq('다음 수량',        287,      $pos['next_qty']);
t_eq('누적수량',         10,     $pos['filled_qty']);
t_eq('누적단가',         54946,  round($pos['avg_cost']));
t_eq('목표수익률',       0.15,   $pos['target_rate']);
t_eq('자동매도가',       63380,  $pos['sell_price']);
t_eq('평가금액',         520000, $pos['eval_amount']);
t_eq('수익률 (%)',       -5.36,  $pos['rate'] * 100, 0.01);
t_eq('매수신호 (52,000 > 50,920)', false, $pos['buy_signal']);
t_eq('매도신호',                   false, $pos['sell_signal']);

t_head('종합 — 매수신호 (현재가 50,900 ≤ 다음매수가 50,920)');
$pos2 = pf_position_calc($DROP_5, $hbTrades, 375_000_000, 50900);
t_eq('매수신호', true, $pos2['buy_signal']);

t_head('종합 — 관심종목(1차 미체결, anchor=현재가)');
$posW = pf_position_calc($DROP_5, [], 375_000_000, 12345);
t_eq('현재 차수',   0,     $posW['cur_step']);
t_eq('다음 차수',   1,     $posW['next_step']);
t_eq('다음 매수가', 12340, $posW['next_price']);   // 현재가 12,345 → 10원 절사
t_eq('누적단가',    null,  $posW['avg_cost']);
t_eq('자동매도가',  null,  $posW['sell_price']);

// ══ 매도 원장 (이동평균법) ══════════════════════════════════════════════
t_head('매도 원장 — 한국주철관 실제 보유 (171@10,311 + 156@8,255 + 273@7,151)');
$krTrades = [
    ['id' => 1, 'side' => 'buy', 'step_no' => 1, 'traded_at' => '2022-05-04', 'price' => 10311, 'qty' => 171],
    ['id' => 2, 'side' => 'buy', 'step_no' => 2, 'traded_at' => '2022-06-03', 'price' => 8255,  'qty' => 156],
    ['id' => 3, 'side' => 'buy', 'step_no' => 3, 'traded_at' => '2022-09-27', 'price' => 7151,  'qty' => 273],
];
$lg = pf_ledger($krTrades);
t_eq('보유수량',     600,   $lg['held_qty']);
t_eq('누적단가',     8367,  round($lg['avg_cost']));   // 화면 표시값과 일치
t_eq('실현손익',     0,     $lg['realized_pl']);
t_eq('매도수량',     0,     $lg['sell_qty']);

t_head('일부 매도 — 600주 중 100주 @6,550');
$lg2 = pf_ledger(array_merge($krTrades, [
    ['id' => 4, 'side' => 'sell', 'traded_at' => '2026-07-28', 'price' => 6550, 'qty' => 100],
]));
t_eq('보유수량 600 → 500',      500,       $lg2['held_qty']);
t_eq('누적단가 불변 (이동평균)', 8367,      round($lg2['avg_cost']));
t_eq('매도수량',                 100,       $lg2['sell_qty']);
// 6,550 × 100 × 0.997 = 653,035 / 원가 8,366.99 × 100 = 836,699
t_eq('실현손익',                 -183664.1, $lg2['realized_pl'], 1.0);
t_eq('보유분 원가',              4183495.7, $lg2['cost_amount'], 1.0);

t_head('일부 매도 후 차수·다음매수가가 흔들리지 않는지');
$krSteps = [
    1 => ['weight' => 0.0150, 'drop_rate' =>  0.000, 'target_rate' => 0.15],
    2 => ['weight' => 0.0255, 'drop_rate' => -0.100, 'target_rate' => 0.20],
    3 => ['weight' => 0.0255, 'drop_rate' => -0.110, 'target_rate' => 0.25],
    4 => ['weight' => 0.0640, 'drop_rate' => -0.240, 'target_rate' => 0.30],
    5 => ['weight' => 0.1845, 'drop_rate' => -0.275, 'target_rate' => 0.35],
    6 => ['weight' => 0.2775, 'drop_rate' => -0.320, 'target_rate' => 0.40],
    7 => ['weight' => 0.4160, 'drop_rate' => -0.360, 'target_rate' => 0.50],
];
$krBuys = pf_trades_by_step($krTrades);
$before = pf_position_calc($krSteps, $krBuys, 30000000, 6550, [], pf_ledger($krTrades));
$after  = pf_position_calc($krSteps, $krBuys, 30000000, 6550, [], $lg2);

t_eq('매도 전 현재차수',   3,     $before['cur_step']);
t_eq('매도 후 현재차수',   3,     $after['cur_step']);        // 불변
t_eq('매도 전 다음매수가', 5430,  $before['next_price']);
t_eq('매도 후 다음매수가', 5430,  $after['next_price']);      // 불변
t_eq('매도 전 자동매도가', 10500, $before['sell_price']);
t_eq('매도 후 자동매도가', 10500, $after['sell_price']);      // 불변
t_eq('매도 전 보유수량',   600,   $before['filled_qty']);
t_eq('매도 후 보유수량',   500,   $after['filled_qty']);
t_eq('매도 후 평가금액',   3275000, $after['eval_amount']);
t_eq('매도 후 평가손익',   -908495.7, $after['eval_pl'], 1.0);
t_eq('매도 후 총손익(평가+실현)', -1092159.8, $after['total_pl'], 1.5);

t_head('매도분 차수 안분 — 171/156/273 (600주) 중 100주 매도');
$alloc = pf_allocate_holdings([1 => 171, 2 => 156, 3 => 273], 100);
// 171×(1-100/600)=142.5→142, 156→130, 273×…=227.5→227  합 499 → 절사분 1주를 1차에
t_eq('1차 (142.5 → 142 + 절사분 1)', 143, $alloc[1]);
t_eq('2차 (130.0)',                  130, $alloc[2]);
t_eq('3차 (227.5 → 227)',            227, $alloc[3]);
t_eq('합계 = 보유수량',              500, array_sum($alloc));

t_head('안분 경계 조건');
$a0 = pf_allocate_holdings([1 => 171, 2 => 156, 3 => 273], 0);
t_eq('매도 0 → 원본 유지 (1차)', 171, $a0[1]);
t_eq('매도 0 → 합계',            600, array_sum($a0));

$aAll = pf_allocate_holdings([1 => 171, 2 => 156, 3 => 273], 600);
t_eq('전량 매도 → 전 차수 0', 0, array_sum($aAll));

$a599 = pf_allocate_holdings([1 => 171, 2 => 156, 3 => 273], 599);
t_eq('599주 매도 → 잔여 1주 전부 1차로', 1, $a599[1]);
t_eq('599주 매도 → 합계',                1, array_sum($a599));

$aOne = pf_allocate_holdings([1 => 1, 2 => 1, 3 => 1], 1);
t_eq('1/1/1 중 1주 매도 → 합계 2', 2, array_sum($aOne));

t_head('안분 결과가 pf_position_calc 차수 행에 반영되는지');
t_eq('1차 매수수량 (원본 유지)', 171, $after['steps'][1]['qty']);
t_eq('1차 보유수량',             143, $after['steps'][1]['held_qty']);
t_eq('2차 보유수량',             130, $after['steps'][2]['held_qty']);
t_eq('3차 보유수량',             227, $after['steps'][3]['held_qty']);
t_eq('1차 보유원가 (10,311×143)', 1474473, $after['steps'][1]['held_amount']);
t_eq('4차(미체결) 보유수량 null', null, $after['steps'][4]['held_qty']);
t_eq('매도 전에는 보유=매수',     171, $before['steps'][1]['held_qty']);

t_head('계획 대비 집행 — 앞 차수를 계획보다 많이 담은 경우');
/*
 * 한도 3,000만 · 기본 8% 룰셋. 1~4차를 계획보다 크게 초과 집행한 실제 사례.
 *   계획금액  1차 450,000 / 2차 765,000 / 3차 765,000 / 4차 1,920,000  = 3,900,000
 *   실제      1,763,181 + 1,287,780 + 3,200,000 + 508,000            = 6,758,961
 */
$overBuys = [
    1 => ['price' => 10311, 'qty' => 171],
    2 => ['price' => 8255,  'qty' => 156],
    3 => ['price' => 6400,  'qty' => 500],
    4 => ['price' => 5080,  'qty' => 100],
];
$ov = pf_position_calc($krSteps, $overBuys, 30000000, 6550);

t_eq('1차 계획금액',        450000,  $ov['steps'][1]['plan_amount']);
t_eq('1차 실제금액',        1763181, $ov['steps'][1]['amount']);
t_eq('1차 집행률 (%)',      391.82,  $ov['steps'][1]['exec_rate'] * 100, 0.01);
t_eq('1차 계획비중 (%)',    1.50,    $ov['steps'][1]['weight'] * 100, 0.01);
t_eq('1차 실제비중 (%)',    5.88,    $ov['steps'][1]['weight_actual'] * 100, 0.01);
t_eq('1차 주수 과부족',     128,     $ov['steps'][1]['qty_diff']);   // 171 - 43

t_eq('4차까지 계획 누적',   3900000, $ov['plan_upto']);
t_eq('4차까지 실제 투입',   6758961, $ov['filled_amount']);
t_eq('집행률 (%)',          173.31,  $ov['exec_rate_upto'] * 100, 0.01);
t_eq('남은 차수(5~7) 계획', 26340000, $ov['plan_remain']);
t_eq('남은 한도',           23241039, $ov['limit_remain']);
t_eq('계획 이행 부족액',    3098961, $ov['plan_shortfall']);

t_head('계획 대비 — 매도가 있으면 보유(안분) 기준으로 계산');
/*
 * 매수 171/156/273 (600주) 중 100주 매도 → 안분 보유 143/130/227 (500주)
 * 판 물량은 한도를 더 이상 점유하지 않으므로 계획대비는 보유 기준으로 본다.
 *   1차: 보유 143주 · 계획 43주 → +100주
 */
$sellSteps = [
    1 => ['weight' => 0.0150, 'drop_rate' =>  0.000, 'target_rate' => 0.15],
    2 => ['weight' => 0.0255, 'drop_rate' => -0.100, 'target_rate' => 0.20],
    3 => ['weight' => 0.0255, 'drop_rate' => -0.110, 'target_rate' => 0.25],
    4 => ['weight' => 0.0640, 'drop_rate' => -0.240, 'target_rate' => 0.30],
    5 => ['weight' => 0.1845, 'drop_rate' => -0.275, 'target_rate' => 0.35],
    6 => ['weight' => 0.2775, 'drop_rate' => -0.320, 'target_rate' => 0.40],
    7 => ['weight' => 0.4160, 'drop_rate' => -0.360, 'target_rate' => 0.50],
];
$sellRaw = [
    ['id' => 1, 'side' => 'buy',  'step_no' => 1, 'traded_at' => '2022-05-04', 'price' => 10300, 'qty' => 171],
    ['id' => 2, 'side' => 'buy',  'step_no' => 2, 'traded_at' => '2022-06-03', 'price' => 8255,  'qty' => 156],
    ['id' => 3, 'side' => 'buy',  'step_no' => 3, 'traded_at' => '2022-09-27', 'price' => 7151,  'qty' => 273],
    ['id' => 4, 'side' => 'sell', 'step_no' => 0, 'traded_at' => '2026-06-05', 'price' => 6590,  'qty' => 100],
];
$sc = pf_position_calc($sellSteps, pf_trades_by_step($sellRaw), 30000000, 6550, [], pf_ledger($sellRaw));

t_eq('1차 매수수량 (원본 보존)', 171, $sc['steps'][1]['qty']);
t_eq('1차 보유수량 (안분)',      143, $sc['steps'][1]['held_qty']);
t_eq('1차 계획수량',              43, $sc['steps'][1]['theory_qty']);
t_eq('1차 계획대비 주수 = 보유 − 계획', 100, $sc['steps'][1]['qty_diff']);
// 143 × 10,300 = 1,472,900 · 계획 30,000,000 × 1.5% = 450,000
t_eq('1차 보유원가',       1472900, $sc['steps'][1]['held_amount'], 0.5);
t_eq('1차 계획대비 (%)',   327.31,  $sc['steps'][1]['exec_rate'] * 100, 0.01);

t_eq('2차 계획대비 주수', 48, $sc['steps'][2]['qty_diff']);   // 130 - 82
t_eq('3차 계획대비 주수', 123, $sc['steps'][3]['qty_diff']);  // 227 - 104

// 합계 — 보유원가 4,169,327 ÷ 3차까지 계획 1,980,000
t_eq('보유원가 합계',      4169327, $sc['held_cost'], 1.0);
t_eq('한도 점유액 = 보유분', 4169327, $sc['used_amount'], 1.0);
t_eq('3차까지 계획금액',   1980000, $sc['plan_upto'], 0.5);
t_eq('계획대비 합계 (%)',  210.57,  $sc['exec_rate_upto'] * 100, 0.01);
// 남은 한도도 매도분만큼 회복된다
t_eq('남은 한도', 30000000 - 4169327, $sc['limit_remain'], 1.0);

t_head('매도가 없으면 종전과 동일 (매수 기준)');
$noSell = array_slice($sellRaw, 0, 3);
$nc = pf_position_calc($sellSteps, pf_trades_by_step($noSell), 30000000, 6550, [], pf_ledger($noSell));
t_eq('1차 계획대비 주수 = 매수 − 계획', 128, $nc['steps'][1]['qty_diff']);   // 171 - 43
t_eq('한도 점유액 = 총매수', $nc['filled_amount'], $nc['used_amount'], 0.5);
t_eq('보유수량 = 매수수량',  171, $nc['steps'][1]['held_qty']);

t_head('계획 대비 집행 — 계획대로 채운 경우');
$onPlan = [
    1 => ['price' => 10311, 'qty' => 43],   // 450,000 ÷ 10,311 = 43.6 → 43주
];
$op = pf_position_calc($krSteps, $onPlan, 30000000, 10311);
t_eq('1차 집행률 (%)', 98.53, $op['exec_rate_upto'] * 100, 0.01);   // 주수 절사 때문에 100% 미만
/*
 * 계획대로 집행해도 부족액이 233,373원 남는다 — 과다 집행 탓이 아니라
 * 이 룰셋의 비중 합계가 100.80% 라 한도를 0.8%(240,000원) 넘기 때문.
 * 절사로 아낀 6,627원을 빼면 233,373원. 룰셋 구조에서 오는 부족분이다.
 */
t_eq('룰셋 비중합 (%)',      100.80,  pf_weight_sum($krSteps) * 100, 0.01);
t_eq('룰셋 자체 초과분',     240000,  (pf_weight_sum($krSteps) - 1) * 30000000, 1.0);
t_eq('계획대로 집행 시 부족액', 233373, $op['plan_shortfall'], 1.0);

t_head('계획 대비 집행 — 비중합 100% 룰셋이면 부족 없음');
$exact = $krSteps;
$exact[7]['weight'] = 0.4080;   // 41.60% → 40.80% 로 낮춰 합계를 정확히 100% 로
t_eq('비중합 (%)', 100.00, pf_weight_sum($exact) * 100, 0.01);
$ex = pf_position_calc($exact, $onPlan, 30000000, 10311);
t_eq('부족액 없음', 0, $ex['plan_shortfall']);

t_head('전량 매도 — 남은 500주까지 @6,550');
$lg3 = pf_ledger(array_merge($krTrades, [
    ['id' => 4, 'side' => 'sell', 'traded_at' => '2026-07-28', 'price' => 6550, 'qty' => 100],
    ['id' => 5, 'side' => 'sell', 'traded_at' => '2026-07-29', 'price' => 6550, 'qty' => 500],
]));
t_eq('보유수량',       0,          $lg3['held_qty']);
t_eq('누적단가 → null', null,      $lg3['avg_cost']);
// 600주 전량: 600×6,550×0.997 − 5,003,184×1.0034
t_eq('실현손익 합계',   -1101984.8, $lg3['realized_pl'], 1.5);
$posOut = pf_position_calc($krSteps, $krBuys, 30000000, 6550, [], $lg3);
t_eq('전량매도 플래그', true,       $posOut['closed_out']);
t_eq('평가금액 → null', null,       $posOut['eval_amount']);

t_head('매도 후 재매수 — 평균단가 재계산');
// 100주 @10,000 매수 → 50주 @12,000 매도 → 50주 @8,000 매수
$re = pf_ledger([
    ['id' => 1, 'side' => 'buy',  'traded_at' => '2026-01-01', 'price' => 10000, 'qty' => 100],
    ['id' => 2, 'side' => 'sell', 'traded_at' => '2026-02-01', 'price' => 12000, 'qty' => 50],
    ['id' => 3, 'side' => 'buy',  'traded_at' => '2026-03-01', 'price' => 8000,  'qty' => 50],
]);
// 매도 시점 평균 10,034 → 실현 = 50×12,000×0.997 − 10,034×50 = 598,200 − 501,700
t_eq('실현손익',   96500, $re['realized_pl'], 0.5);
t_eq('보유수량',   100,   $re['held_qty']);
// 남은 원가 500,000 + 신규 400,000 = 900,000 → ×1.0034 ÷ 100
t_eq('재계산 누적단가', 9030.6, $re['avg_cost'], 0.1);

t_head('보유수량 초과 매도는 보유분까지만');
$over = pf_ledger([
    ['id' => 1, 'side' => 'buy',  'traded_at' => '2026-01-01', 'price' => 10000, 'qty' => 100],
    ['id' => 2, 'side' => 'sell', 'traded_at' => '2026-02-01', 'price' => 12000, 'qty' => 999],
]);
t_eq('보유수량', 0,   $over['held_qty']);
t_eq('매도수량', 100, $over['sell_qty']);

// ══ 모달 미리보기 공식 == 서버 계산 ═════════════════════════════════════
t_head('매수 미리보기 공식이 저장 후 서버 계산과 일치하는지');
/*
 * 모달(JS)은 원장을 다시 돌리지 않고 아래 한 줄로 새 누적단가를 예측한다.
 *   새 누적단가 = (누적단가 × 보유수량 + 체결가 × 수량 × (1+매수비용률)) ÷ (보유수량 + 수량)
 * 이 값이 실제로 저장했을 때 pf_ledger 가 내놓는 값과 같아야 화면이 거짓말을 하지 않는다.
 */
$jsPredict = function (?float $avg, int $held, float $price, int $qty): float {
    if ($held <= 0 || $avg === null) return $price * (1 + PF_BUY_COST_RATE);
    return ($avg * $held + $price * $qty * (1 + PF_BUY_COST_RATE)) / ($held + $qty);
};

// (1) 매도 이력이 없는 상태에서 추가 매수
$base   = pf_ledger($krTrades);
$addRow = ['id' => 9, 'side' => 'buy', 'step_no' => 3, 'traded_at' => '2026-07-28', 'price' => 7340, 'qty' => 80];
$serverA = pf_ledger(array_merge($krTrades, [$addRow]));
t_eq('예측 = 서버 (매도 없음)',
    $serverA['avg_cost'],
    $jsPredict($base['avg_cost'], $base['held_qty'], 7340, 80), 0.0001);
t_eq('보유수량',  680, $serverA['held_qty']);

// (2) 일부 매도한 뒤 추가 매수 — 원장이 원가를 덜어낸 상태에서도 맞아야 한다
$sold    = pf_ledger(array_merge($krTrades, [
    ['id' => 4, 'side' => 'sell', 'traded_at' => '2026-06-05', 'price' => 6590, 'qty' => 100],
]));
$serverB = pf_ledger(array_merge($krTrades, [
    ['id' => 4, 'side' => 'sell', 'traded_at' => '2026-06-05', 'price' => 6590, 'qty' => 100],
    ['id' => 9, 'side' => 'buy', 'step_no' => 4, 'traded_at' => '2026-07-28', 'price' => 5080, 'qty' => 100],
]));
t_eq('예측 = 서버 (일부 매도 후)',
    $serverB['avg_cost'],
    $jsPredict($sold['avg_cost'], $sold['held_qty'], 5080, 100), 0.0001);
t_eq('보유수량', 600, $serverB['held_qty']);

// (3) 첫 매수 (보유 0)
$serverC = pf_ledger([['id' => 1, 'side' => 'buy', 'step_no' => 1, 'traded_at' => '2026-07-28', 'price' => 10311, 'qty' => 43]]);
t_eq('예측 = 서버 (첫 매수)',
    $serverC['avg_cost'],
    $jsPredict(null, 0, 10311, 43), 0.0001);

// (4) 물타기 방향 — 누적단가보다 싸게 사면 내려가고, 비싸게 사면 올라간다
t_eq('싸게 매수 → 누적단가 하락', true,
    $jsPredict($base['avg_cost'], 600, 5000, 100) < $base['avg_cost']);
t_eq('비싸게 매수 → 누적단가 상승', true,
    $jsPredict($base['avg_cost'], 600, 12000, 100) > $base['avg_cost']);

// ══ 수수료·세율 합성 ════════════════════════════════════════════════════
t_head('비용 합성 — 증권사 수수료 + 시장 세율');
/*
 * 매수비용률 = 매수 위탁수수료
 * 매도비용률 = 매도 위탁수수료 + 증권거래세(시장별)
 * 기본값(0.34% / 0.15% + 0.15%)이 기존 상수(0.34% / 0.30%)와 같아야 기존 데이터가 안 흔들린다.
 */
$dflt = pf_cost_params(['buy_fee_rate' => null, 'sell_fee_rate' => null, 'tax_rate' => null]);
t_eq('기본 매수비용률', PF_BUY_COST_RATE, $dflt['buy_cost_rate'], 1e-9);
// 매도 기본 = 수수료 0.15% + 세금 0.20%(2026 코스피/코스닥) = 0.35%
t_eq('기본 매도비용률', 0.0035, $dflt['sell_cost_rate'], 1e-9);
/*
 * PF_SELL_COST_RATE(0.30%)는 요건정의서 §2.1 의 값으로, §2.5 검증표를 재현할 때만 쓰인다.
 * 실제 화면은 포트폴리오 수수료 + 시장 세율을 합성해서 쓴다.
 */
t_eq('스펙 상수는 그대로 보존', 0.0030, PF_SELL_COST_RATE, 1e-9);

// 2026년 시장별 세율 — 같은 증권사라도 시장에 따라 매도비용이 달라진다
$kiwoom = ['buy_fee_rate' => 0.00015, 'sell_fee_rate' => 0.00015];
t_eq('코스피 매도비용률 (0.015%+0.20%)',
    0.00215, pf_cost_params($kiwoom + ['tax_rate' => 0.0020])['sell_cost_rate'], 1e-9);
t_eq('코스닥 매도비용률 (0.015%+0.20%)',
    0.00215, pf_cost_params($kiwoom + ['tax_rate' => 0.0020])['sell_cost_rate'], 1e-9);
t_eq('코넥스 매도비용률 (0.015%+0.10%)',
    0.00115, pf_cost_params($kiwoom + ['tax_rate' => 0.0010])['sell_cost_rate'], 1e-9);
t_eq('비상장·장외 매도비용률 (0.015%+0.35%)',
    0.00365, pf_cost_params($kiwoom + ['tax_rate' => 0.0035])['sell_cost_rate'], 1e-9);
t_eq('ETF 매도비용률 (거래세 면제)',
    0.00015, pf_cost_params($kiwoom + ['tax_rate' => 0.0000])['sell_cost_rate'], 1e-9);
t_eq('매수비용률은 시장과 무관',
    0.00015, pf_cost_params($kiwoom + ['tax_rate' => 0.0020])['buy_cost_rate'], 1e-9);

t_head('설정값이 실제 계산에 먹히는지');
$hbP = pf_cost_params(['buy_fee_rate' => null, 'sell_fee_rate' => null, 'tax_rate' => null]);
t_eq('누적단가 (기본 설정)', 54946,
    round(pf_avg_cost([1 => ['price' => 54760, 'qty' => 10]], $hbP)));
/*
 * §2.5 검증표의 11,980 은 엑셀이 매도비용 0.30% 를 가정한 값이다.
 * 2026년 실제 세율(코스피 0.20%)에 수수료 0.15% 를 더하면 0.35% 라 10원 올라간다.
 *   10,385 × 1.15 = 11,942.75 ÷ (1-0.0035) = 11,984.7 → 11,990
 * 스펙 상수를 직접 넘기면 여전히 11,980 이 나온다 (§2.5 테스트에서 확인).
 */
t_eq('자동매도가 (2026 세율)', 11990, pf_auto_sell_price(10385, 0.15, $hbP));
t_eq('자동매도가 (스펙 0.30%)', 11980,
    pf_auto_sell_price(10385, 0.15, ['sell_cost_rate' => PF_SELL_COST_RATE]));

// 수수료를 실제 수준(0.015%)으로 낮추면 두 값이 함께 내려간다
$lowP = pf_cost_params(['buy_fee_rate' => 0.00015, 'sell_fee_rate' => 0.00015, 'tax_rate' => 0.0015]);
t_eq('누적단가 (수수료 0.015%)', 54768,
    round(pf_avg_cost([1 => ['price' => 54760, 'qty' => 10]], $lowP)));
// 10,385 × 1.15 = 11,942.75 ÷ (1-0.00165) = 11,962.5 → 10원 올림
t_eq('자동매도가 (수수료 0.015%)', 11970, pf_auto_sell_price(10385, 0.15, $lowP));
t_eq('비용이 낮아지면 자동매도가도 내려감', true,
    pf_auto_sell_price(10385, 0.15, $lowP) < pf_auto_sell_price(10385, 0.15, $hbP));

t_head('실현손익에 시장별 세율이 반영되는지');
$sellRows = [
    ['id' => 1, 'side' => 'buy',  'step_no' => 1, 'traded_at' => '2026-01-01', 'price' => 10000, 'qty' => 100],
    ['id' => 2, 'side' => 'sell', 'step_no' => 0, 'traded_at' => '2026-02-01', 'price' => 12000, 'qty' => 100],
];
$pKospi = pf_cost_params(['buy_fee_rate' => 0.00015, 'sell_fee_rate' => 0.00015, 'tax_rate' => 0.0015]);
$pEtf   = pf_cost_params(['buy_fee_rate' => 0.00015, 'sell_fee_rate' => 0.00015, 'tax_rate' => 0.0000]);
// 코스피: 1,200,000×(1-0.00165) - 1,000,000×1.00015 = 1,198,020 - 1,000,150
t_eq('코스피 실현손익', 197870, pf_ledger($sellRows, $pKospi)['realized_pl'], 1.0);
// ETF: 1,200,000×(1-0.00015) - 1,000,150
t_eq('ETF 실현손익',    199670, pf_ledger($sellRows, $pEtf)['realized_pl'],   1.0);
t_eq('거래세 차이만큼 벌어짐', 1800,
    pf_ledger($sellRows, $pEtf)['realized_pl'] - pf_ledger($sellRows, $pKospi)['realized_pl'], 1.0);

// ══ 증권사 수수료 구간표 ════════════════════════════════════════════════
t_head('수수료 구간표 — 키움증권 (단일 요율 0.015%)');
$KIWOOM = [
    ['min_amt' => 0, 'fee_rate' => 0.00015, 'fee_fixed' => 0],
];
t_eq('100만원',   150,    pf_fee_amount(1000000, $KIWOOM), 0.001);
t_eq('1억원',     15000,  pf_fee_amount(100000000, $KIWOOM), 0.001);
t_eq('0원',       0,      pf_fee_amount(0, $KIWOOM));

t_head('수수료 구간표 — 삼성증권 (MTS/HTS, 구간별 요율 + 정액)');
/*
 * 1천만 미만        0.147216% + 1,500원
 * 1천만 ~ 5천만     0.127216% + 3,000원
 * 5천만 ~ 1억       0.117216%
 * 1억   ~ 3억       0.097216%
 * 3억 이상          0.077216%
 */
$SAMSUNG = [
    ['min_amt' => 0,           'fee_rate' => 0.00147216, 'fee_fixed' => 1500],
    ['min_amt' => 10000000,    'fee_rate' => 0.00127216, 'fee_fixed' => 3000],
    ['min_amt' => 50000000,    'fee_rate' => 0.00117216, 'fee_fixed' => 0],
    ['min_amt' => 100000000,   'fee_rate' => 0.00097216, 'fee_fixed' => 0],
    ['min_amt' => 300000000,   'fee_rate' => 0.00077216, 'fee_fixed' => 0],
];
t_eq('500만원 (1구간)',      8860.8,   pf_fee_amount(5000000,   $SAMSUNG), 0.01);
t_eq('1천만원 경계 (2구간)', 15721.6,  pf_fee_amount(10000000,  $SAMSUNG), 0.01);
// 9,999,999 × 0.00147216 = 14,721.6 + 1,500 → 1구간 유지
t_eq('999만원 (아직 1구간)', 16221.6,  pf_fee_amount(9999999,   $SAMSUNG), 0.1);
t_eq('6천만원 (3구간·정액0)', 70329.6, pf_fee_amount(60000000,  $SAMSUNG), 0.01);
t_eq('2억원 (4구간)',        194432.0, pf_fee_amount(200000000, $SAMSUNG), 0.01);
t_eq('5억원 (5구간)',        386080.0, pf_fee_amount(500000000, $SAMSUNG), 0.01);

t_head('구간표가 없으면 정률로 떨어진다 (하위호환)');
t_eq('구간표 없음 → 정률 0.34%', 3400, pf_fee_amount(1000000, [], 0.0034), 0.001);

t_head('구간표가 누적단가에 반영되는지');
$buy1 = [1 => ['price' => 10000, 'qty' => 100]];   // 100만원 매수
// 키움: 수수료 150원 → (1,000,000+150)/100 = 10,001.5
t_eq('키움 누적단가', 10001.5,
    pf_avg_cost($buy1, pf_cost_params([], $KIWOOM)), 0.01);
// 삼성: 1,000,000×0.00147216 + 1,500 = 2,972.16 → (1,000,000+2,972.16)/100 = 10,029.72
t_eq('삼성 누적단가', 10029.72,
    pf_avg_cost($buy1, pf_cost_params([], $SAMSUNG)), 0.01);
t_eq('삼성이 키움보다 비쌈', true,
    pf_avg_cost($buy1, pf_cost_params([], $SAMSUNG)) > pf_avg_cost($buy1, pf_cost_params([], $KIWOOM)));

t_head('정액 수수료라 소액 거래일수록 실효율이 높아진다');
$r10 = pf_fee_amount(1000000,  $SAMSUNG) / 1000000;    // 100만원
$r1e = pf_fee_amount(100000000, $SAMSUNG) / 100000000; // 1억원
t_eq('100만원 실효율 (%)', 0.2972, $r10 * 100, 0.001);
t_eq('1억원 실효율 (%)',   0.0972, $r1e * 100, 0.001);
t_eq('소액이 더 비쌈', true, $r10 > $r1e);

t_head('구간 수수료에서 자동매도가 — 예상 매도대금으로 실효율 적용');
$pSam = pf_cost_params(['tax_rate' => 0.0015], $SAMSUNG);
// 누적단가 10,000 · 목표 15% · 1,000주 → 예상 매도대금 11,500,000 (2구간)
// 수수료 11,500,000×0.00127216 + 3,000 = 17,629.84 / 세금 17,250 → 34,879.84 ÷ 11,500,000
$gross   = 10000 * 1.15 * 1000;
$effRate = pf_sell_cost($gross, $pSam) / $gross;
t_eq('실효 매도비용률 (%)', 0.30330, $effRate * 100, 0.001);
t_eq('자동매도가',
    pf_ceil_tick(10000 * 1.15 / (1 - $effRate)),
    pf_auto_sell_price(10000, 0.15, $pSam, 1000));
t_eq('수량 0이면 정률로 폴백', true, pf_auto_sell_price(10000, 0.15, $pSam, 0) !== null);

t_head('구간 수수료 실현손익');
$sRows = [
    ['id' => 1, 'side' => 'buy',  'step_no' => 1, 'traded_at' => '2026-01-01', 'price' => 10000, 'qty' => 100],
    ['id' => 2, 'side' => 'sell', 'step_no' => 0, 'traded_at' => '2026-02-01', 'price' => 12000, 'qty' => 100],
];
$lgSam = pf_ledger($sRows, $pSam);
// 매수원가 1,002,972.16 / 매도 1,200,000 - (1,200,000×0.00147216+1,500) - 1,200,000×0.0015
t_eq('삼성 매수수수료',  2972.16,  $lgSam['buy_fee'],  0.01);
t_eq('삼성 매도비용',    5066.59,  $lgSam['sell_fee'], 0.01);
t_eq('삼성 실현손익',    191961.25, $lgSam['realized_pl'], 0.5);

$lgKw = pf_ledger($sRows, pf_cost_params(['tax_rate' => 0.0015], $KIWOOM));
t_eq('키움 실현손익이 더 큼', true, $lgKw['realized_pl'] > $lgSam['realized_pl']);

// ══ 누적목표(catch-up) 매수 규칙 ════════════════════════════════════════
t_head('누적목표 — 미집행 잔액이 다음 차수로 이월되는가');
/*
 * 현대차 실제 사례. 한도 3,000만 · 선제 30% 룰셋
 *   비중   3 / 5 / 8 / 14 / 17 / 24 / 29 %
 *   누적목표 900,000 / 2,400,000 / 4,800,000 / 9,000,000 / …
 *   1차 554,000 × 3주 = 1,662,000  (계획 900,000 → +762,000 초과)
 *   2차 427,500 × 1주 =   427,500  (계획 1,500,000 → 미달)
 *   투입 합계 2,089,500
 */
$hSteps = [
    1 => ['weight' => 0.03, 'drop_rate' =>  0.00, 'target_rate' => 0.15],
    2 => ['weight' => 0.05, 'drop_rate' => -0.09, 'target_rate' => 0.20],
    3 => ['weight' => 0.08, 'drop_rate' => -0.12, 'target_rate' => 0.25],
    4 => ['weight' => 0.14, 'drop_rate' => -0.16, 'target_rate' => 0.30],
    5 => ['weight' => 0.17, 'drop_rate' => -0.20, 'target_rate' => 0.35],
    6 => ['weight' => 0.24, 'drop_rate' => -0.25, 'target_rate' => 0.40],
    7 => ['weight' => 0.29, 'drop_rate' => -0.30, 'target_rate' => 0.50],
];
$hBuys = [
    1 => ['price' => 554000, 'qty' => 3],
    2 => ['price' => 427500, 'qty' => 1],
];
$LIM = 30000000;

// (1) 아직 3차 이론가에 못 미친 상태 — 현재가 427,500
$h1 = pf_position_calc($hSteps, $hBuys, $LIM, 427500);
t_eq('투입 합계',        2089500, $h1['filled_amount'], 0.5);
t_eq('1차 누적목표',      900000, $h1['steps'][1]['plan_cum'], 0.5);
t_eq('1차 과부족 (초과)', 762000, $h1['steps'][1]['gap'], 0.5);
t_eq('2차 누적목표',     2400000, $h1['steps'][2]['plan_cum'], 0.5);
t_eq('2차 과부족 (미달)', -310500, $h1['steps'][2]['gap'], 0.5);
t_eq('3차 누적목표',     4800000, $h1['steps'][3]['plan_cum'], 0.5);
t_eq('3차 과부족',      -2710500, $h1['steps'][3]['gap'], 0.5);

t_eq('다음 차수',        3,       $h1['next_step']);
t_eq('다음 매수가',      376200,  $h1['next_price']);
// 누적목표 4,800,000 − 투입 2,089,500 = 2,710,500 ÷ 376,200 = 7주 (차수독립 방식은 6주)
t_eq('다음 매수금액',    2710500, $h1['next_amount'], 0.5);
t_eq('다음 수량 (이월 반영)', 7,  $h1['next_qty']);
t_eq('아직 트리거 전',   0,       $h1['buy_qty']);
t_eq('도달차수 = 현재차수', 2,    $h1['reach_step']);

t_head('누적목표 — 급락 시 차수를 건너뛰고 따라잡는가');
// 300,000 은 4차 이론가(316,000) 아래 → 4차 구간
$h2 = pf_position_calc($hSteps, $hBuys, $LIM, 300000);
t_eq('도달차수',        4,       $h2['reach_step']);
// 누적목표 9,000,000 − 2,089,500 = 6,910,500 ÷ 현재가 300,000 = 23주
t_eq('매수금액',        6910500, $h2['buy_amount'], 0.5);
t_eq('매수수량 (현재가 기준)', 23, $h2['buy_qty']);
t_eq('매수신호',        true,    $h2['buy_signal']);

t_head('수량은 현재가로 나눈다 — 쌀수록 더 산다');
$cheap = pf_position_calc($hSteps, $hBuys, $LIM, 376200);   // 3차 이론가에 딱 도달
t_eq('376,200 이면',  7, $cheap['buy_qty']);
$cheaper = pf_position_calc($hSteps, $hBuys, $LIM, 350000);
t_eq('350,000 이면',  7, $cheaper['buy_qty']);              // 2,710,500 ÷ 350,000 = 7.7
$cheapest = pf_position_calc($hSteps, $hBuys, $LIM, 320000);
t_eq('320,000 이면',  8, $cheapest['buy_qty']);             // 2,710,500 ÷ 320,000 = 8.4

t_head('3차를 건너뛰고 4차에 매수하면');
/*
 * 2차 보유 상태에서 주가가 300,000(4차 구간)까지 빠져 4차로 바로 매수.
 * 3차 누적목표는 4차 누적목표에 이미 포함돼 있으므로 따로 채울 필요가 없다.
 */
$skipBuys = $hBuys + [4 => ['price' => 300000, 'qty' => 23]];
$sk = pf_position_calc($hSteps, $skipBuys, $LIM, 300000);

t_eq('현재 차수 = 4',        4, $sk['cur_step']);
t_eq('3차는 미체결',     false, $sk['steps'][3]['traded']);
// 투입 1,662,000 + 427,500 + 6,900,000
t_eq('투입 합계',      8989500, $sk['filled_amount'], 0.5);
// 4차 누적목표 9,000,000 → 거의 정확히 채워짐
t_eq('4차 누적목표',   9000000, $sk['steps'][4]['plan_cum'], 0.5);
t_eq('4차 과부족',      -10500, $sk['steps'][4]['gap'], 0.5);

t_eq('다음 차수 = 5',        5, $sk['next_step']);
// 5차 기준가 = min(4차 실매수 300,000, 4차 이론 316,000) = 300,000 → ×0.80
t_eq('5차 이론가',      240000, $sk['next_price']);
// 5차 누적목표 14,100,000 − 투입 8,989,500 = 5,110,500 ÷ 240,000 = 21주
t_eq('5차 매수금액',   5110500, $sk['next_amount'], 0.5);
t_eq('5차 수량',            21, $sk['next_qty']);

t_head('건너뛴 차수를 나중에 채워도 총액은 같은 곳으로 수렴');
// 3차를 먼저 채우고 4차로 갔을 때와 비교 — 4차 시점 누적목표는 동일하다
$viaThird = $hBuys + [
    3 => ['price' => 376200, 'qty' => 7],       // 2,633,400
    4 => ['price' => 300000, 'qty' => 14],      // 4,200,000
];
$v3 = pf_position_calc($hSteps, $viaThird, $LIM, 300000);
t_eq('경유 시 투입 합계', 8922900, $v3['filled_amount'], 0.5);
t_eq('4차 누적목표 동일', 9000000, $v3['steps'][4]['plan_cum'], 0.5);
// 둘 다 4차 누적목표 근처로 수렴 (차이는 주 단위 절사분뿐)
t_eq('두 경로의 투입 차이가 1주 미만', true,
    abs($sk['filled_amount'] - $v3['filled_amount']) < 300000);

t_head('앞 차수를 과다 집행하면 다음 매수가 0이 될 수 있다');
$over2 = pf_position_calc($hSteps, [1 => ['price' => 554000, 'qty' => 5]], $LIM, 504140);
// 1차에 2,770,000 투입 > 2차 누적목표 2,400,000 → 더 살 것 없음
t_eq('2차 매수금액 0', 0, $over2['next_amount'], 0.5);
t_eq('2차 수량 0',     0, $over2['next_qty']);

// ══ 예수금 · 추정자산 ═══════════════════════════════════════════════════
t_head('예수금 = 원금 − 매수금액 + 매도금액');
/*
 * 증권사 예수금과 맞추려면 수수료·세금이 들어가야 한다.
 *   매수 시 나가는 현금 = 매수대금 + 위탁수수료
 *   매도 시 들어오는 현금 = 매도대금 − 위탁수수료 − 증권거래세
 * 키움(0.015%) · 코스피(0.20%) 기준으로 검증한다.
 */
$pKw = pf_cost_params(['tax_rate' => 0.0020], [['min_amt' => 0, 'fee_rate' => 0.00015, 'fee_fixed' => 0]]);

// 100주 @10,000 매수 → 매수대금 1,000,000 + 수수료 150 = 1,000,150 지출
$buyOnly = pf_ledger([
    ['id' => 1, 'side' => 'buy', 'step_no' => 1, 'traded_at' => '2026-01-01', 'price' => 10000, 'qty' => 100],
], $pKw);
t_eq('매수대금',       1000000, $buyOnly['buy_amount'], 0.01);
t_eq('매수수수료',     150,     $buyOnly['buy_fee'],    0.01);
t_eq('매수 지출액',    1000150, $buyOnly['buy_cost'],   0.01);
t_eq('예수금 증감',   -1000150, $buyOnly['cash_flow'],  0.01);

$principal = 20000000;
t_eq('예수금 (원금 2천만 − 매수)', 18999850, $principal + $buyOnly['cash_flow'], 0.01);

// 40주 @12,000 매도 → 480,000 − 수수료 72 − 세금 960 = 478,968 수취
$withSell = pf_ledger([
    ['id' => 1, 'side' => 'buy',  'step_no' => 1, 'traded_at' => '2026-01-01', 'price' => 10000, 'qty' => 100],
    ['id' => 2, 'side' => 'sell', 'step_no' => 0, 'traded_at' => '2026-02-01', 'price' => 12000, 'qty' => 40],
], $pKw);
t_eq('매도 수취액',   478968,   $withSell['sell_amount'], 0.01);
t_eq('매도 비용',     1032,     $withSell['sell_fee'],    0.01);
t_eq('예수금 증감',  -521182,   $withSell['cash_flow'],   0.01);
t_eq('예수금',        19478818, $principal + $withSell['cash_flow'], 0.01);
t_eq('보유수량',      60,       $withSell['held_qty']);

t_head('추정자산 = 예수금 + 보유종목 현재가치');
// 현재가 11,000 · 보유 60주 → 660,000 − 수수료 99 − 세금 1,320 = 658,581
t_eq('현재가치 (매도비용 차감)', 658581, pf_net_value(60, 11000, $pKw), 0.01);
t_eq('추정자산', 20137399, $principal + $withSell['cash_flow'] + pf_net_value(60, 11000, $pKw), 0.01);
t_eq('보유 0주면 현재가치 0',    0, pf_net_value(0, 11000, $pKw));
t_eq('현재가 없으면 현재가치 0', 0, pf_net_value(60, null, $pKw));

t_head('pf_position_calc 에 cash_flow · net_value 가 실리는지');
$cvSteps = [1 => ['weight' => 1.0, 'drop_rate' => 0.0, 'target_rate' => 0.15]];
$cvRows  = [
    ['id' => 1, 'side' => 'buy',  'step_no' => 1, 'traded_at' => '2026-01-01', 'price' => 10000, 'qty' => 100],
    ['id' => 2, 'side' => 'sell', 'step_no' => 0, 'traded_at' => '2026-02-01', 'price' => 12000, 'qty' => 40],
];
$cv = pf_position_calc($cvSteps, pf_trades_by_step($cvRows), 20000000, 11000, $pKw, pf_ledger($cvRows, $pKw));
t_eq('cash_flow', -521182, $cv['cash_flow'], 0.01);
t_eq('net_value', 658581,  $cv['net_value'], 0.01);
t_eq('평가금액',  660000,  $cv['eval_amount'], 0.01);
// 평가금액 − 현재가치 = 지금 팔 때 나갈 비용
t_eq('평가금액 − 현재가치 = 매도비용', 1419, $cv['eval_amount'] - $cv['net_value'], 0.01);

t_head('전량 매도하면 추정자산 = 예수금');
$allOut = pf_ledger([
    ['id' => 1, 'side' => 'buy',  'step_no' => 1, 'traded_at' => '2026-01-01', 'price' => 10000, 'qty' => 100],
    ['id' => 2, 'side' => 'sell', 'step_no' => 0, 'traded_at' => '2026-02-01', 'price' => 12000, 'qty' => 100],
], $pKw);
t_eq('보유수량 0',    0, $allOut['held_qty']);
t_eq('현재가치 0',    0, pf_net_value($allOut['held_qty'], 11000, $pKw));
// 1,200,000 − 180 − 2,400 = 1,197,420 수취 / 1,000,150 지출
t_eq('예수금 증감',   197270,   $allOut['cash_flow'], 0.01);
t_eq('실현손익과 일치', $allOut['realized_pl'], $allOut['cash_flow'], 0.01);

// ══ 신호 요약 pf_signal() ══════════════════════════════════════════════
// 여러 포트폴리오를 한 화면에 세울 때 쓰는 요약. 룰셋: 1차 30%(0%) / 2차 70%(−10%)
$sgSteps = [
    1 => ['weight' => 0.30, 'drop_rate' =>  0.00, 'target_rate' => 0.15],
    2 => ['weight' => 0.70, 'drop_rate' => -0.10, 'target_rate' => 0.20],
];
// 1차를 계획대로(300주 @10,000 = 3,000,000) 채운 상태. 2차 이론가 = 9,000
$sgRows = [['id' => 1, 'side' => 'buy', 'step_no' => 1, 'traded_at' => '2026-01-01', 'price' => 10000, 'qty' => 300]];
$sgCalc = fn(?float $last) => pf_position_calc($sgSteps, pf_trades_by_step($sgRows), 10000000, $last, $pKw, pf_ledger($sgRows, $pKw));

t_head('신호 요약 — 대기 종목의 거리');
$c1 = $sgCalc(9500.0);
$s1 = pf_signal($c1, 9500.0);
t_eq('2차 이론가',              9000,     $c1['next_price'], 0.01);
t_eq('대기 (신호 없음)',        null,     $s1['kind']);
t_eq('매수까지 = 9500/9000−1',  0.055556, $s1['buy_gap'], 1e-6);
t_eq('매도까지 > 매수까지',     true,     $s1['sell_gap'] > $s1['buy_gap']);
t_eq('가까운 쪽 = 매수',        true,     $s1['near'] === 'buy');
t_eq('거리 = 가까운 쪽 크기',   0.055556, $s1['gap'], 1e-6);

t_head('신호 요약 — 차수 상승 매수');
$c2 = $sgCalc(9000.0);
$s2 = pf_signal($c2, 9000.0);
t_eq('매수 신호',        true,    $s2['kind'] === 'buy');
t_eq('매수금액 = 누적목표 10,000,000 − 투입 3,000,000', 7000000, $s2['amount'], 0.01);
t_eq('수량 = 7,000,000÷9,000',  777,     $s2['qty']);        // 현재가로 나눈다 (이론가 아님)
t_eq('이론가에 정확히 닿아 거리 0', 0.0,  $s2['buy_gap'], 1e-9);
// 급락하면 같은 금액을 더 싸게 = 수량이 늘어난다
$s2b = pf_signal($sgCalc(8000.0), 8000.0);
t_eq('8,000원이면 수량 875',    875,     $s2b['qty']);
t_eq('이론가보다 아래 = 음수 거리', -0.111111, $s2b['buy_gap'], 1e-6);

t_head('신호 요약 — 같은 차수 잔여 매수(fill)');
// 1차를 200주(2,000,000)만 채웠다 → 1차 누적목표까지 1,000,000 남음
$fgRows = [['id' => 1, 'side' => 'buy', 'step_no' => 1, 'traded_at' => '2026-01-01', 'price' => 10000, 'qty' => 200]];
$c3 = pf_position_calc($sgSteps, pf_trades_by_step($fgRows), 10000000, 9500.0, $pKw, pf_ledger($fgRows, $pKw));
$s3 = pf_signal($c3, 9500.0);
t_eq('잔여 매수 신호',   true,     $s3['kind'] === 'fill');
t_eq('잔여금액',         1000000,  $s3['amount'], 0.01);
t_eq('수량 = 1,000,000÷9,500', 105, $s3['qty']);

t_head('신호 요약 — 매도(자동매도가 도달)');
$sp  = $c1['sell_price'];               // 자동매도가는 현재가와 무관하게 결정된다
$s4  = pf_signal($sgCalc($sp), $sp);
t_eq('매도 신호',        true,  $s4['kind'] === 'sell');
t_eq('수량 = 보유 전량', 300,   $s4['qty']);
t_eq('매도까지 거리 0',  0.0,   $s4['sell_gap'], 1e-9);
t_eq('가까운 쪽 = 매도', true,  $s4['near'] === 'sell');
// 매도가에 닿으면 매수 신호(가격이 내려야 하는 조건)와 겹칠 수 없다
t_eq('매수까지는 아직 멀다', true, $s4['buy_gap'] > 0);

t_head('★ 이론가에 닿았어도 살 것이 없으면 신호가 아니다');
// 1차에 한도 전액(1,000주 = 10,000,000)을 이미 부어 2차 누적목표까지 다 찬 상태
$ovRows = [['id' => 1, 'side' => 'buy', 'step_no' => 1, 'traded_at' => '2026-01-01', 'price' => 10000, 'qty' => 1000]];
$c5 = pf_position_calc($sgSteps, pf_trades_by_step($ovRows), 10000000, 9000.0, $pKw, pf_ledger($ovRows, $pKw));
$s5 = pf_signal($c5, 9000.0);
t_eq('buy_signal 자체는 참',  true, (bool)$c5['buy_signal']);
t_eq('그러나 매수수량 0',     0,    (int)$c5['buy_qty']);
t_eq('→ 신호로 세우지 않는다', null, $s5['kind']);   // "매수 0주" 는 신호가 아니라 잡음

t_head('신호 요약 — 방어');
$s6 = pf_signal(null, 12000.0);          // 룰셋에 차수가 없는 포지션
t_eq('계산결과 없으면 대기', null, $s6['kind']);
t_eq('거리도 없다',          null, $s6['gap']);
$s7 = pf_signal($c1, null);              // 현재가 미수집
t_eq('현재가 없으면 거리 없음', null, $s7['buy_gap']);
t_eq('우선순위 매도 < 매수',    true, pf_signal_rank('sell') < pf_signal_rank('buy'));
t_eq('우선순위 매수 < 잔여',    true, pf_signal_rank('buy')  < pf_signal_rank('fill'));
t_eq('우선순위 잔여 < 대기',    true, pf_signal_rank('fill') < pf_signal_rank(null));

// ══ 매매 되짚기 pf_trade_review / pf_trade_score / pf_reentry_check ═════
t_head('매매 되짚기 — 매수는 오르면 잘한 것');
$rvB = pf_trade_review('buy', 10000.0, 100, 11000.0);
t_eq('체결후 등락 +10%',   0.10,    $rvB['after'], 1e-9);
t_eq('매매우위 = 등락 그대로', 0.10, $rvB['edge'],  1e-9);
t_eq('금액영향 +100,000',  100000,  $rvB['impact'], 0.01);
t_eq('잘한 매매',          true,    $rvB['good']);
$rvB2 = pf_trade_review('buy', 10000.0, 100, 9000.0);
t_eq('내리면 우위 음수',   -0.10,   $rvB2['edge'], 1e-9);
t_eq('금액영향 −100,000',  -100000, $rvB2['impact'], 0.01);
t_eq('이른 매수',          false,   $rvB2['good']);

t_head('매매 되짚기 — 매도는 <b>내리면</b> 잘한 것 (부호가 뒤집힌다)');
$rvS = pf_trade_review('sell', 10000.0, 100, 9000.0);
t_eq('체결후 등락 −10%',   -0.10,   $rvS['after'], 1e-9);
t_eq('매매우위 +10%',      0.10,    $rvS['edge'],  1e-9);
t_eq('아낀 손실 +100,000', 100000,  $rvS['impact'], 0.01);
t_eq('잘한 매도',          true,    $rvS['good']);
$rvS2 = pf_trade_review('sell', 10000.0, 100, 12000.0);
t_eq('오르면 우위 음수',   -0.20,   $rvS2['edge'], 1e-9);
t_eq('놓친 이익 −200,000', -200000, $rvS2['impact'], 0.01);
// ★ 같은 방향 등락이 매수·매도에서 반대 판정으로 나와야 한다
t_eq('같은 +10%가 매수엔 +, 매도엔 −', true,
     pf_trade_review('buy', 10000.0, 1, 11000.0)['edge'] > 0
     && pf_trade_review('sell', 10000.0, 1, 11000.0)['edge'] < 0);

t_head('매매 되짚기 — 값이 없으면 0 이 아니라 null');
$rvN = pf_trade_review('buy', 10000.0, 100, null);   // 시세 미수집
t_eq('등락 null',   null, $rvN['after']);
t_eq('우위 null',   null, $rvN['edge']);
t_eq('영향 null',   null, $rvN['impact']);
t_eq('판정 null',   null, $rvN['good']);
t_eq('체결가 0 도 null', null, pf_trade_review('buy', 0.0, 100, 11000.0)['edge']);

t_head('매매 품질 집계 — 매수·매도를 나눠 센다');
$mk = function (string $side, float $price, int $qty, ?float $last) {
    return ['side' => $side] + pf_trade_review($side, $price, $qty, $last);
};
$sc = pf_trade_score([
    $mk('buy',  10000.0, 100, 11000.0),   // 매수 적중
    $mk('buy',  10000.0, 100,  9000.0),   // 매수 실패
    $mk('buy',  10000.0, 100, 10500.0),   // 매수 적중
    $mk('sell', 10000.0, 100,  9000.0),   // 매도 적중 (+100,000)
    $mk('sell', 10000.0, 100, 12000.0),   // 매도 실패 (−200,000)
    $mk('buy',  10000.0, 100, null),      // 시세 없음 — 집계에서 빠진다
]);
t_eq('매수 표본 3',      3,      $sc['buy']['n']);
t_eq('매수 적중 2',      2,      $sc['buy']['hit']);
t_eq('매수 적중률 66.7%', 0.6667, $sc['buy']['rate'], 0.001);
t_eq('매도 표본 2',      2,      $sc['sell']['n']);
t_eq('매도 적중률 50%',  0.50,   $sc['sell']['rate'], 1e-9);
t_eq('매도 금액영향 −100,000', -100000, $sc['sell']['impact'], 0.01);
t_eq('매수 금액영향 +50,000',   50000,  $sc['buy']['impact'], 0.01);
t_eq('전체 합계 −50,000',      -50000,  $sc['impact'], 0.01);
// 무승부(등락 0)는 분모에서 빠져야 한다
$sc2 = pf_trade_score([$mk('buy', 10000.0, 10, 10000.0), $mk('buy', 10000.0, 10, 11000.0)]);
t_eq('무승부 제외 → 표본 1', 1,   $sc2['buy']['n']);
t_eq('적중률 100%',          1.0, $sc2['buy']['rate'], 1e-9);

t_head('차수별 매매 품질 — 같은 적중률이라도 실린 돈이 다르면 금액이 갈린다');
$mkS = function (int $step, float $price, int $qty, ?float $last) {
    return ['side' => 'buy', 'step_no' => $step, 'price' => $price, 'qty' => $qty]
         + pf_trade_review('buy', $price, $qty, $last);
};
$ss = pf_step_score([
    $mkS(1, 10000.0,  10,  7000.0),   // 1차 −30% · 체결 100,000 · 영향 −30,000
    $mkS(1, 10000.0,  10, 11000.0),   // 1차 +10% · 영향 +10,000
    $mkS(6, 10000.0, 100,  9000.0),   // 6차 −10% · 체결 1,000,000 · 영향 −100,000
    ['side' => 'sell', 'step_no' => 1, 'price' => 10000.0, 'qty' => 10,
     'edge' => 0.5, 'impact' => 999999.0],                     // 매도는 섞지 않는다
    $mkS(0, 10000.0,  10,  9000.0),   // 차수 없는 건은 제외
]);
t_eq('1차 표본 2',            2,       $ss[1]['n']);
t_eq('1차 적중 1 → 50%',      0.5,     $ss[1]['rate'], 1e-9);
t_eq('1차 체결금액 200,000',  200000,  $ss[1]['amount'], 0.01);
t_eq('1차 금액영향 −20,000',  -20000,  $ss[1]['impact'], 0.01);
// ★ 이 표의 존재 이유 — % 로는 6차(−10%)가 1차(−30%)보다 나은데 <b>잃은 돈은 6차가 5배</b>다
t_eq('6차 중앙 우위 −10%',    -0.10,   $ss[6]['edge_med'], 1e-9);
t_eq('6차 금액영향 −100,000', -100000, $ss[6]['impact'], 0.01);
t_eq('매도는 안 섞임',        true,    !isset($ss[1]['sell']) && $ss[1]['impact'] < 0);
t_eq('차수 0 은 행이 없다',   false,   isset($ss[0]));
// 표본 부족은 판정 보류 — "괜찮다"가 아니라 "아직 모른다"
t_eq('2건은 표본 부족',       true,    $ss[1]['thin']);
$ssMany = pf_step_score(array_map(fn($i) => $mkS(2, 10000.0, 1, 11000.0), range(1, PF_EXC_MIN_N)));
t_eq('5건이면 판정함',        false,   $ssMany[2]['thin']);
// 시세가 없는 건은 0 이 아니라 <b>제외</b> (0 은 "완벽한 판단"으로 읽혀 집계를 오염시킨다)
$ssNull = pf_step_score([$mkS(3, 10000.0, 10, null), $mkS(3, 10000.0, 10, 9000.0)]);
t_eq('시세 없는 건 제외 → 표본 1', 1, $ssNull[3]['n']);
t_eq('빠진 건수를 따로 센다',      1, $ssNull[3]['no_edge']);
t_eq('체결금액은 그래도 다 센다', 200000, $ssNull[3]['amount'], 0.01);
// 무승부는 pf_trade_score 와 같은 규칙으로 분모에서 빠진다
$ssTie = pf_step_score([$mkS(4, 10000.0, 10, 10000.0), $mkS(4, 10000.0, 10, 11000.0)]);
t_eq('무승부 제외 → 표본 1', 1,   $ssTie[4]['n']);
t_eq('적중률 100%',          1.0, $ssTie[4]['rate'], 1e-9);

t_head('재진입 판정 — 대기일 + 청산가보다 싸야 후보');
// 청산가 10,000 · 대기 10일 · 하락요건 5%
t_eq('대기 중',        'wait',      pf_reentry_check(10000.0, 9000.0, 4, 10, 0.05)['state']);
t_eq('남은 대기일 6',  6,           pf_reentry_check(10000.0, 9000.0, 4, 10, 0.05)['left']);
$rdy = pf_reentry_check(10000.0, 9000.0, 12, 10, 0.05);
t_eq('대기 끝 + 10% 싸다 → 후보', 'ready', $rdy['state']);
t_eq('청산가 대비 −10%',          -0.10,   $rdy['gap'], 1e-9);
// 대기는 끝났지만 아직 비싸다 (−3% 는 −5% 요건 미달)
t_eq('요건 미달 → 보류', 'expensive', pf_reentry_check(10000.0, 9700.0, 12, 10, 0.05)['state']);
t_eq('청산가보다 위 → 보류', 'expensive', pf_reentry_check(10000.0, 11000.0, 30, 10, 0.05)['state']);
// 하락요건 0 이면 청산가 이하이기만 하면 된다 (시뮬레이터 규칙에 가장 가까운 설정)
t_eq('요건 0 · 딱 같은 값이면 후보', 'ready', pf_reentry_check(10000.0, 10000.0, 12, 10, 0.0)['state']);
t_eq('시세 없으면 보류', 'expensive', pf_reentry_check(10000.0, null, 99, 10, 0.0)['state']);

t_head('재진입 — 두 관문을 <b>따로</b> 판정한다 (대기 중이어도 값 요건을 안다)');
// 실전 사례: 삼성전자 청산 247,000 → 현재 241,500 (−2.23%) · 경과 4일 · 요건 9%
$rc = pf_reentry_check(247000.0, 241500.0, 4, 10, 0.09);
t_eq('대기 미충족',        false,   $rc['days_ok']);
t_eq('값도 미충족',        false,   $rc['price_ok']);   // ★ state='wait' 라 예전엔 알 수 없었다
t_eq('상태는 대기',        'wait',  $rc['state']);
t_eq('재진입가 224,770',   224770,  $rc['need_price'], 0.01);   // 247,000 × 0.91
t_eq('여기서 6.9% 더',     -0.0693, $rc['need_gap'], 0.0001);   // 224,770 ÷ 241,500 − 1
// 대기만 지나면 값 관문이 그대로 state 가 된다
t_eq('대기 지나면 비쌈',   'expensive', pf_reentry_check(247000.0, 241500.0, 12, 10, 0.09)['state']);
// 값은 됐는데 대기가 남은 경우 — price_ok 는 참이고 state 는 wait
$rc2 = pf_reentry_check(10000.0, 8000.0, 3, 10, 0.09);
t_eq('값 충족 · 대기 남음', true,   $rc2['price_ok']);
t_eq('그래도 상태는 대기',  'wait', $rc2['state']);
t_eq('충족이면 더 내릴 필요 없음', 0.0, $rc2['need_gap'], 1e-9);
// 시세가 없으면 값 관문을 판정하지 않는다 (0 으로 두면 "충족"으로 읽힌다)
$rc3 = pf_reentry_check(10000.0, null, 99, 10, 0.09);
t_eq('시세 없으면 값 미충족', false, $rc3['price_ok']);
t_eq('더 내릴 폭도 null',     null,  $rc3['need_gap']);
t_eq('재진입가는 그래도 나온다', 9100, $rc3['need_price'], 0.01);

// ══ 시장 지표 / 시장 신호 ═══════════════════════════════════════════════
// 일봉을 만드는 헬퍼 — 종가만 주면 o/h/l 은 같게, 거래량은 100 으로 채운다
$bars = function (array $closes, array $vols = []) {
    $out = [];
    foreach ($closes as $i => $c) {
        $out[] = ['d' => sprintf('2026-%02d-%02d', intdiv($i, 28) + 1, ($i % 28) + 1),
                  'o' => $c, 'h' => $c, 'l' => $c, 'c' => $c, 'v' => $vols[$i] ?? 100];
    }
    return $out;
};
$rise = function (int $n, float $from = 100.0, float $step = 1.0) {
    $a = [];
    for ($i = 0; $i < $n; $i++) $a[] = $from + $step * $i;
    return $a;
};

t_head('정지일 판정 — o/h/l/v 가 0 이고 종가만 있는 날 (실측 형태)');
t_eq('정상봉',              true,  pf_bar_valid(['o' => 100, 'h' => 101, 'l' => 99, 'c' => 100, 'v' => 5000]));
t_eq('정지일 (v=0, o=0)',   false, pf_bar_valid(['o' => 0, 'h' => 0, 'l' => 0, 'c' => 53000, 'v' => 0]));
t_eq('거래량만 0',          false, pf_bar_valid(['o' => 100, 'h' => 101, 'l' => 99, 'c' => 100, 'v' => 0]));

t_head('이동평균 · 표준편차');
t_eq('SMA5 = 마지막 5개 평균', 8.0, pf_sma([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 5), 1e-9);  // (6+7+8+9+10)/5
t_eq('표본 부족이면 null',     null, pf_sma([1, 2, 3], 5));
t_eq('표본표준편차 (n−1)',     1.0,  pf_stdev([1, 2, 3, 4, 5]) === null ? null : round(pf_stdev([1, 2, 3, 4, 5]), 6), 0.6);
t_eq('1개면 null',             null, pf_stdev([5]));

t_head('RSI(14) — Wilder 평활 (손계산 대조)');
// n=2 · [10, 11, 10.5] : avgGain 0.5 / avgLoss 0.25 → 100 − 100/(1+2) = 66.6667
t_eq('초기값만 (n=2)',   66.6667, pf_rsi([10, 11, 10.5], 2), 0.001);
// 여기에 +1 을 하나 더: ag=(0.5+1)/2=0.75 · al=(0.25+0)/2=0.125 → 100 − 100/(1+6) = 85.7143
t_eq('평활 1회 (n=2)',   85.7143, pf_rsi([10, 11, 10.5, 11.5], 2), 0.001);
t_eq('계속 오르면 100',  100.0,   pf_rsi($rise(30), 14), 1e-9);
t_eq('계속 내리면 0',    0.0,     pf_rsi(array_reverse($rise(30)), 14), 1e-9);
t_eq('표본 부족이면 null', null,  pf_rsi([1, 2, 3], 14));

t_head('지표 — 정지일은 계산에서 빠진다');
// 20봉 정상 + 정지일 3개 + 정상 1봉
$stop = $bars($rise(20));
for ($i = 0; $i < 3; $i++) $stop[] = ['d' => '2026-02-0' . ($i + 1), 'o' => 0, 'h' => 0, 'l' => 0, 'c' => 119, 'v' => 0];
$stop[] = ['d' => '2026-02-05', 'o' => 119, 'h' => 125, 'l' => 119, 'c' => 125, 'v' => 900];
$iStop = pf_indicators($stop);
t_eq('유효봉 21개 (정지 3일 제외)', 21, $iStop['n']);
// 정지일을 안 걸렀다면 직전 종가가 119(정지일) 이 되어 등락률이 달라진다 → 걸렀으므로 119 → 125
t_eq('등락률은 정지일 앞 봉과 비교', 125 / 119 - 1, $iStop['chg'], 1e-9);
// 거래량 평균도 0 이 섞이지 않는다 (앞 20봉 전부 100)
t_eq('거래량 평균 100',   100.0, $iStop['vol_ma20'], 1e-9);
t_eq('거래량 배수 9배',   9.0,   $iStop['vol_mult'], 1e-9);

t_head('지표 — 장중 현재가로 마지막 봉을 갈아끼운다');
$iDay = pf_indicators($bars($rise(30)), 200.0);
t_eq('장중 반영 표시', true,  $iDay['intraday']);
t_eq('종가 = 현재가',  200.0, $iDay['close'], 1e-9);
t_eq('같은 값이면 장중 아님', false, pf_indicators($bars($rise(30)), 129.0)['intraday']);  // 마지막 종가와 동일

t_head('지표 — 추세 배열 / 이격도 / 연속일');
$iUp = pf_indicators($bars($rise(70)));
t_eq('정배열',            true, $iUp['trend'] === 'up');
t_eq('연속 상승 69일',    69,   $iUp['streak']);
t_eq('이격도 양수',       true, $iUp['disp20'] > 0);
$iDn = pf_indicators($bars(array_reverse($rise(70))));
t_eq('역배열',            true, $iDn['trend'] === 'down');
t_eq('연속 하락 −69일',   -69,  $iDn['streak']);
t_eq('60봉 미만이면 추세 없음', null, pf_indicators($bars($rise(40)))['trend']);

t_head('지표 — 52주 위치는 표본 200봉 이상에서만');
t_eq('199봉이면 null',    null, pf_indicators($bars($rise(199)))['pos52']);
$i250 = pf_indicators($bars($rise(250)));
t_eq('250봉 · 최고가에 있으면 1.0', 1.0, $i250['pos52'], 1e-9);
// 마지막을 최저로 떨어뜨리면 0
$dropped = $rise(250);
$dropped[249] = 100.0;
t_eq('최저권이면 0.0', 0.0, pf_indicators($bars($dropped))['pos52'], 1e-9);

t_head('지표 — σ (평소 변동으로 정규화한 급등락)');
// 20일간 ±1% 를 번갈아 → 표준편차 ≈ 1% · 마지막에 −5% → 약 −5σ
$c = [100.0];
for ($i = 1; $i <= 21; $i++) $c[] = $c[$i - 1] * (($i % 2) ? 1.01 : 0.99);
$c[] = end($c) * 0.95;
$iSig = pf_indicators($bars($c));
t_eq('큰 하락은 음수 σ',   true, $iSig['sigma'] < -3);
t_eq('등락률 −5%',        -0.05, $iSig['chg'], 1e-9);
// 변동이 전혀 없으면 σ 는 만들지 않는다 (0 으로 나누지 않는다)
t_eq('무변동이면 σ null', null, pf_indicators($bars(array_fill(0, 30, 100.0)))['sigma']);
t_eq('빈 배열도 안전',    0,    pf_indicators([])['n']);

t_head('시장 신호 — 임계치를 넘은 것만');
$base = pf_indicators([]);
$sg = fn(array $o) => array_column(pf_market_signals(array_merge($base, $o), 9), 'key');
t_eq('평범하면 신호 없음', 0, count($sg([])));
t_eq('급락 3σ 이상',   true, in_array('plunge', $sg(['sigma' => -3.5, 'chg' => -0.03]), true));
t_eq('2.5σ + 3%는 아님', false, in_array('plunge', $sg(['sigma' => -2.5, 'chg' => -0.03]), true));
/* ★ σ 와 절대% 는 OR 다 — 최근 20일이 출렁이면 sd20 이 커져 큰 움직임도 작아 보인다(변동성 클러스터링).
 *   임계 3σ·15% 는 사용자 지정(2026-08-02 · 2σ/5% 에서 상향) — 진짜 이례만 띄운다. */
t_eq('0.8σ 라도 +15%면 급등',  true, in_array('surge',  $sg(['sigma' => 0.8,  'chg' => 0.155]), true));
t_eq('0.8σ 라도 −15%면 급락',  true, in_array('plunge', $sg(['sigma' => 0.8,  'chg' => -0.155]), true));
t_eq('저변동주 +2%가 3.5σ면 급등', true, in_array('surge', $sg(['sigma' => 3.5, 'chg' => 0.02]), true));
t_eq('14.9% · 2.9σ 는 둘 다 미달', false, in_array('surge', $sg(['sigma' => 2.9, 'chg' => 0.149]), true));
// 신뢰도 판정도 <b>같은 기준</b>을 써야 배지와 따로 놀지 않는다
t_eq('신뢰도도 절대 15%를 인정', 'strong',
     pf_signal_confidence('buy', array_merge($base, ['sigma' => 0.8, 'chg' => -0.16]))['level']);
t_eq('거래량 2배',     true, in_array('vol', $sg(['vol_mult' => 2.1]), true));
t_eq('1.9배는 아님',   false, in_array('vol', $sg(['vol_mult' => 1.9]), true));
t_eq('52주 최저권',    true, in_array('low52', $sg(['pos52' => 0.03]), true));
t_eq('과매도 RSI30',   true, in_array('oversold', $sg(['rsi14' => 28.0]), true));
t_eq('과매수 RSI70',   true, in_array('overbought', $sg(['rsi14' => 72.0]), true));
t_eq('역배열',         true, in_array('downtrend', $sg(['trend' => 'down']), true));
t_eq('3일 연속 하락',  true, in_array('down_streak', $sg(['streak' => -3]), true));
t_eq('2일은 아님',     false, in_array('down_streak', $sg(['streak' => -2]), true));
// ★ 화면이 넘치지 않게 상한을 지킨다
t_eq('최대 3개만', 3, count(pf_market_signals(array_merge($base, [
    'sigma' => -3.0, 'chg' => -0.09, 'vol_mult' => 4.0, 'pos52' => 0.01,
    'rsi14' => 20.0, 'trend' => 'down', 'disp20' => -0.2, 'streak' => -5,
]))));
// 상한이 걸릴 때 <b>행동에 가까운 것</b>부터 남아야 한다
t_eq('급락이 첫 배지', 'plunge', pf_market_signals(array_merge($base, [
    'sigma' => -3.0, 'chg' => -0.09, 'vol_mult' => 4.0, 'rsi14' => 20.0,
]))[0]['key']);

t_head('유동성 — 유동성의 척도는 주수가 아니라 거래대금이다');
// 거래대금 = 거래량 × 종가. 같은 1만주라도 종가가 다르면 유동성이 다르다
$liqDeep = pf_liquidity($bars(array_fill(0, 250, 100000.0), array_fill(0, 250, 100000)));  // 100억/일
$liqThin = pf_liquidity($bars(array_fill(0, 250, 10000.0),  array_fill(0, 250, 1000)));    // 0.1억/일
t_eq('일평균 거래량',       100000.0,       $liqDeep['avg_vol'], 1e-9);
t_eq('일평균 거래대금 100억', 10000000000.0, $liqDeep['avg_val'], 1e-6);
t_eq('풍부(≥50억)',        true, $liqDeep['grade'] === 'deep');
t_eq('매우 얇음(<1억)',    true, $liqThin['grade'] === 'very_thin');
t_eq('저거래일 0%',        0.0,  $liqDeep['thin_ratio'], 1e-9);
t_eq('저거래일 100%',      1.0,  $liqThin['thin_ratio'], 1e-9);
// ★ 주수는 같은데 등급이 갈린다 — 이게 거래대금으로 재는 이유다
t_eq('같은 1만주라도 종가로 갈린다', true,
     pf_liquidity($bars(array_fill(0, 30, 500000.0), array_fill(0, 30, 10000)))['grade'] === 'deep'
     && pf_liquidity($bars(array_fill(0, 30, 500.0), array_fill(0, 30, 10000)))['grade'] === 'very_thin');
// 정지일(거래량 0)이 평균을 끌어내리지 않아야 한다
$withStop = $bars(array_fill(0, 25, 10000.0), array_fill(0, 25, 50000));
$withStop[24] = ['d' => '2026-02-01', 'o' => 0, 'h' => 0, 'l' => 0, 'c' => 10000, 'v' => 0];
t_eq('정지일 제외 후 평균 유지', 50000.0, pf_liquidity($withStop)['avg_vol'], 1e-9);

t_head('유동성 — 참여율과 분할 일수');
$L = pf_liquidity($bars(array_fill(0, 250, 10000.0), array_fill(0, 250, 10000)), 2300, 0.02);
t_eq('참여율 = 2,300 ÷ 10,000',  0.23, $L['part'], 1e-9);
t_eq('목표 10%면 3일 분할',      3,    $L['days']);
// √법칙: 시장충격 ≈ σ × √참여율 = 0.02 × √0.23
t_eq('추정 시장충격',            0.02 * sqrt(0.23), $L['impact'], 1e-9);
// ★ 참여율이 1을 넘으면(하루 거래량보다 큰 주문) 식이 뜻을 잃으므로 1 로 막는다
t_eq('참여율 300%면 충격은 σ 로 상한', 0.02,
     pf_liquidity($bars(array_fill(0, 30, 10000.0), array_fill(0, 30, 1000)), 3000, 0.02)['impact'], 1e-9);
t_eq('계획 수량 0이면 참여율 없음', null, $liqDeep['part']);
t_eq('표본 부족이면 전부 null',     null, pf_liquidity($bars([100.0, 101.0]))['grade']);

/* ⊖ 「체결 게이트(pf_fill_gate)」 시험 7건은 2026-08-02 삭제 — 실행 게이트를 통째로 지웠다.
 *   위 참여율·분할일수·시장충격 시험은 남긴다(측정은 그대로 살아 있고, 판정만 없어졌다). */
$mkL = fn(float $part, string $grade) => ['part' => $part, 'days' => max(1, (int)ceil($part / 0.10)),
                                          'grade' => $grade, 'avg_val' => 0, 'thin_ratio' => 0];

t_head('유동성 배지 — 세우지 않는다 (2026-08-02 사용자 지시 · 얇음은 「★ 거래량 N배」로만 드러난다)');
$sgL = fn(array $o, array $l) => array_column(pf_market_signals(array_merge($base, $o), 9, $l), 'key');
t_eq('매우 얇아도 배지 없음', false, in_array('illiq', $sgL([], $mkL(0, 'very_thin')), true));
t_eq('유동성 정보가 없어도 배지 없음', false, in_array('illiq', $sg([]), true));
// 얇은 종목의 거래량 급증은 ★ 로 드러낸다
$vThin = pf_market_signals(array_merge($base, ['vol_mult' => 3.2]), 9, $mkL(0, 'very_thin'));
$vDeep = pf_market_signals(array_merge($base, ['vol_mult' => 3.2]), 9, $mkL(0, 'deep'));
t_eq('얇은 종목은 ★ 표시', true, strpos($vThin[0]['label'], '★') === 0);
t_eq('풍부한 종목은 없음',  true, strpos($vDeep[0]['label'], '★') === false);

t_head('★ 계획 신호 × 시장 상태 = 신뢰도');
$cf = fn(?string $k, array $o) => pf_signal_confidence($k, array_merge($base, $o));
t_eq('대기 종목은 판정 없음', 'normal', $cf(null, ['rsi14' => 20.0])['level']);
// 매수 + 과매도 + 거래량 급증 = 투매 → 근거 강함
t_eq('매수+과매도+거래량', 'strong',
     $cf('buy', ['rsi14' => 25.0, 'vol_mult' => 3.0])['level']);
t_eq('잔여매수도 같게 본다', 'strong',
     $cf('fill', ['sigma' => -3.5, 'vol_mult' => 3.0])['level']);
// ★ 역배열 + 52주 최저권이면 과매도라도 <b>주의</b>가 이긴다 (하락추세 초기 물타기가 가장 위험)
t_eq('매수+역배열+최저권 → 주의', 'caution',
     $cf('buy', ['rsi14' => 25.0, 'vol_mult' => 3.0, 'trend' => 'down', 'pos52' => 0.05])['level']);
// 매도 + 과매수 = 근거 강함 / 매도 + 정배열 + 최고권 = 분할매도 고려
t_eq('매도+과매수',            'strong',  $cf('sell', ['rsi14' => 75.0])['level']);
t_eq('매도+정배열+최고권 → 분할', 'caution', $cf('sell', ['rsi14' => 75.0, 'trend' => 'up', 'pos52' => 0.95])['level']);
t_eq('매도인데 시장정보 없으면 보통', 'normal', $cf('sell', [])['level']);

// ══ 매수 모달의 「목표 대비」 예측이 엔진 결과와 같은가 ═══════════════════
/*
 * 매수 팝업은 등록 <b>전에</b> "이대로 사면 잔여매수가 몇 주 남는다"를 예측해 보여 준다.
 * 그 예측식은 JS 에 있고(브라우저에서만 돈다) 실제 값은 pf_position_calc 이 만든다 —
 * 두 곳이 어긋나면 화면이 "딱 맞습니다"라고 한 뒤 현황에 잔여매수가 뜨는 사고가 난다.
 * 그래서 <b>엔진 쪽 진실</b>을 여기 박아 둔다: 남은금액 ÷ 현재가 를 내림한 값이 fill_qty 다.
 */
t_head('매수 모달 예측 = 엔진의 fill_qty (잔여매수가 남는지)');
$gSteps = [
    1 => ['weight' => 0.30, 'drop_rate' =>  0.00, 'target_rate' => 0.15],
    2 => ['weight' => 0.70, 'drop_rate' => -0.10, 'target_rate' => 0.20],
];
$gLimit = 10000000;                 // 1차 누적목표 3,000,000
$gLast  = 9500.0;                   // 현재가 (1차 이론가 10,000 이하 → 잔여매수 조건 성립)

/** 1차를 200주 담아 둔 상태에서 추가로 $q 주를 $p 원에 사면 fill_qty 가 얼마인가 */
$gFill = function (int $q, float $p) use ($gSteps, $gLimit, $gLast, $pKw) {
    $rows = [['id' => 1, 'side' => 'buy', 'step_no' => 1, 'traded_at' => '2026-01-01', 'price' => 10000, 'qty' => 200]];
    if ($q > 0) $rows[] = ['id' => 2, 'side' => 'buy', 'step_no' => 1, 'traded_at' => '2026-01-02', 'price' => $p, 'qty' => $q];
    $c = pf_position_calc($gSteps, pf_trades_by_step($rows), $gLimit, $gLast, $pKw, pf_ledger($rows, $pKw));
    return (int)$c['fill_qty'];
};

// 담기 전: 누적목표 3,000,000 − 투입 2,000,000 = 1,000,000 남음 → 9,500원이면 105주
t_eq('추가 매수 전 잔여 105주', 105, $gFill(0, 9500.0));

/* ★ 화면이 권장하는 수량 = floor(남은금액 ÷ 체결가) = 105주.
 *   그대로 사면 2,500원이 남지만 <b>1주 값 미만</b>이라 잔여매수로 뜨지 않는다 —
 *   화면이 "목표에 맞습니다"라고 말하는 근거가 이것이다. */
t_eq('권장 105주를 담으면 잔여 0',      0, $gFill(105, 9500.0));
t_eq('그때 남는 금액은 1주 값 미만', true, (3000000 - 2000000 - 105 * 9500) < 9500);

// 2주 덜 담으면 화면이 "2주 모자랍니다"라고 경고해야 한다 — 엔진도 2주로 잡는다
t_eq('103주만 담으면 잔여 2주', 2, $gFill(103, 9500.0));
t_eq('104주만 담으면 잔여 1주', 1, $gFill(104, 9500.0));

// 더 담으면(초과) 잔여는 0 이다 — 초과분은 뒤 차수 누적목표에 흡수돼 경고할 일이 아니다
t_eq('110주(초과)면 잔여 0', 0, $gFill(110, 9500.0));

/* ★ 체결가가 오르면 같은 수량으로도 목표를 넘긴다 — 모달이 수량을 따라 바꿔 주는 이유.
 *   9,600원에서의 권장은 floor(1,000,000 ÷ 9,600) = 104주다. */
t_eq('9,600원 권장 104주면 잔여 0', 0, $gFill(104, 9600.0));
t_eq('9,600원에 105주는 초과 → 잔여 0', 0, $gFill(105, 9600.0));

// ══ 종료(청산) 포지션 — 돈은 남고 계획은 지워진다 ═══════════════════════
/*
 * 전량 매도하면 보유수량이 0 이다. 계산엔진은 그것을 "아직 아무것도 안 산 종목"으로 읽어
 * 한도 전액을 다시 사라고 말한다 — 아래에서 <b>1,250주</b>가 그 잔상이다.
 * 반대로 실현손익과 현금흐름은 확정된 사실이라 <b>합계에서 빠지면 예수금이 틀린다</b>
 * (실측 2026-07-30 — 청산 2건이 합계에서 빠져 1,772,382원이 어긋났다).
 * 그래서 pf_calc_closed 는 돈만 남기고 계획·신호를 지운다.
 */
t_head('★ 종료 포지션 — pf_calc_closed (돈은 사실, 계획은 잔상)');
$clRows = [
    ['id' => 1, 'side' => 'buy',  'step_no' => 1, 'traded_at' => '2026-01-02', 'price' => 10000, 'qty' => 200],
    ['id' => 2, 'side' => 'sell', 'step_no' => 1, 'traded_at' => '2026-03-02', 'price' => 12000, 'qty' => 200],
];
$clLed = pf_ledger($clRows, $pKw);
$clRaw = pf_position_calc($gSteps, pf_trades_by_step($clRows), 10000000, 8000.0, $pKw, $clLed);

// 손대지 않은 상태 = 잔상이 그대로 살아 있다
t_eq('보유 0',                   0, $clRaw['filled_qty']);
t_eq('전량매도 판정',         true, $clRaw['closed_out']);
t_eq('잔상 — 2차 매수 신호',  true, $clRaw['buy_signal']);
t_eq('잔상 — 1,250주 사라고 한다', 1250, $clRaw['buy_qty']);
t_eq('잔상 — 다음매수가 9,000', 9000, $clRaw['next_price']);

$clFix = pf_calc_closed($clRaw);

// 계획·신호는 전부 지워진다
t_eq('다음 차수 없음',   null, $clFix['next_step']);
t_eq('다음매수가 없음',  null, $clFix['next_price']);
t_eq('다음수량 없음',    null, $clFix['next_qty']);
t_eq('다음금액 없음',    null, $clFix['next_amount']);   // ★ 합계의 '다음 차수 소요'가 이 값을 센다
t_eq('자동매도가 없음',  null, $clFix['sell_price']);
t_eq('매수수량 0',          0, $clFix['buy_qty']);
t_eq('매수금액 0',          0, $clFix['buy_amount']);
t_eq('잔여매수 없음',   false, $clFix['fill_signal']);
t_eq('매수신호 없음',   false, $clFix['buy_signal']);
t_eq('매도신호 없음',   false, $clFix['sell_signal']);
t_eq('도달차수 = 현재차수', $clFix['cur_step'], $clFix['reach_step']);

// 확정된 돈은 그대로 남는다 — 이 두 값이 합계에서 빠졌던 것이 그 버그였다
t_eq('실현손익 유지',  $clRaw['realized_pl'], $clFix['realized_pl']);
t_eq('현금흐름 유지',  $clRaw['cash_flow'],   $clFix['cash_flow']);
t_eq('실현손익 > 0',   true, $clFix['realized_pl'] > 0);          // 10,000 → 12,000 에 팔았다
t_eq('현금흐름 > 0',   true, $clFix['cash_flow']   > 0);          // 판 돈이 계좌에 남는다
t_eq('평가금액 없음',  null, $clFix['eval_amount']);
t_eq('보유원가 0',        0, $clFix['cost_amount']);
t_eq('순가치 0',          0, $clFix['net_value']);

// 신호 조립도 조용해진다 — 목록·스트립에 청산 종목이 되살아나지 않는다
t_eq('신호 종류 없음',   null, pf_signal($clFix, 8000.0)['kind']);
t_eq('거리도 없음',      null, pf_signal($clFix, 8000.0)['gap']);
t_eq('지우기 전에는 매수', 'buy', pf_signal($clRaw, 8000.0)['kind']);
t_eq('null 도 안전',     null, pf_calc_closed(null));

/* ★ 기준은 status='closed' 다 — 전량매도했지만 아직 open 인 포지션(재진입 대기)은
 *   그대로 살아 있는 계획이라 여기서 지우면 안 된다. 그래서 이 함수는 스스로
 *   closed_out 을 보지 않고, 호출부가 status 를 보고 부른다. */
t_eq('closed_out 만으로는 안 지운다', 'buy', pf_signal($clRaw, 8000.0)['kind']);

// ══ MFE/MAE — 체결 이후 최고·최저 ═══════════════════════════════════════
/*
 * pf_trade_review 는 현재가 한 점과만 견주므로 "담은 뒤 −20% 까지 갔다가 회복" 이 무승부로 보인다.
 * 지나간 위험을 드러내는 것이 MFE/MAE 다. 부호는 review 의 edge 와 같다 — <b>＋면 내 편</b>.
 */
t_head('★ MFE/MAE — 체결 이후 최고·최저 (pf_trade_excursion)');
$xBars = [
    ['d' => '2026-01-05', 'o' => 10000, 'h' => 10500, 'l' =>  5000, 'c' => 10000, 'v' => 100],  // 체결일 — 세지 않는다
    ['d' => '2026-01-06', 'o' => 10000, 'h' => 10200, 'l' =>  9800, 'c' => 10000, 'v' => 100],
    ['d' => '2026-01-08', 'o' =>     0, 'h' =>     0, 'l' =>     0, 'c' => 10000, 'v' =>   0],  // 거래정지일 — 제외
    ['d' => '2026-01-10', 'o' =>  9500, 'h' =>  9600, 'l' =>  8000, 'c' =>  8200, 'v' => 200],  // 최저 8,000
    ['d' => '2026-01-15', 'o' => 11000, 'h' => 12000, 'l' => 10800, 'c' => 11800, 'v' => 300],  // 최고 12,000
    ['d' => '2026-01-20', 'o' => 11000, 'h' => 11500, 'l' => 10500, 'c' => 11000, 'v' => 150],
];
$xb = pf_trade_excursion('buy', 10000.0, '2026-01-05', $xBars);
t_eq('매수 MAE −20%',        -0.20, $xb['mae'], 1e-9);
t_eq('그 날짜',        '2026-01-10', $xb['mae_at']);
t_eq('바닥까지 5일',              5, $xb['mae_days']);
t_eq('매수 MFE +20%',         0.20, $xb['mfe'], 1e-9);
t_eq('그 날짜',        '2026-01-15', $xb['mfe_at']);
t_eq('유효봉 4개 (체결일·정지일 제외)', 4, $xb['n']);
t_eq('구간이 덮였다',          true, $xb['covered']);

/* ★ 매도는 부호가 뒤집힌다 — 판 뒤 <b>내려야</b> 잘한 것이다 */
$xs = pf_trade_excursion('sell', 10000.0, '2026-01-05', $xBars);
t_eq('매도 MFE +20% (최저에서)', 0.20, $xs['mfe'], 1e-9);
t_eq('매도 MFE 날짜 = 최저일', '2026-01-10', $xs['mfe_at']);
t_eq('매도 MAE −20% (최고에서)', -0.20, $xs['mae'], 1e-9);
t_eq('매도 MAE 날짜 = 최고일', '2026-01-15', $xs['mae_at']);

/* ★ 체결일 저가 5,000 을 세면 MAE 가 −50% 로 찍힌다 — 안 세는 것이 이 함수의 규칙이다 */
t_eq('체결일을 세면 −50% 였을 것', -0.50, 5000 / 10000 - 1, 1e-9);

/* ★ 일봉이 체결 시점을 못 덮으면(오래된 체결) covered=false — 집계에서 빼야 한다.
 *   값은 나오지만 일봉 시작 이후만 본 것이라 실제보다 <b>얕다</b>. */
$xOld = pf_trade_excursion('buy', 10000.0, '2025-11-01', $xBars);
t_eq('덮지 못했다', false, $xOld['covered']);
t_eq('그래도 값은 나온다', -0.50, $xOld['mae'], 1e-9);

// 시세·일봉이 없으면 전부 null (0 으로 두면 "위험이 없었다"로 읽힌다)
t_eq('일봉 없으면 null', null, pf_trade_excursion('buy', 10000.0, '2026-01-05', [])['mae']);
t_eq('체결가 0 이면 null', null, pf_trade_excursion('buy', 0.0, '2026-01-05', $xBars)['mae']);
t_eq('체결 뒤 봉이 없으면 null', null, pf_trade_excursion('buy', 10000.0, '2026-12-31', $xBars)['mae']);

// ── 차수별 집계
t_head('★ 차수별 추가하락 집계 (pf_step_excursion)');
$mkExc = fn(?float $mae, ?float $mfe, bool $cov, int $days = 10) =>
    ['mae' => $mae, 'mfe' => $mfe, 'mae_days' => $days, 'covered' => $cov, 'mae_at' => '2026-01-10', 'mfe_at' => null, 'n' => 5];
$xRows = [
    ['side' => 'buy',  'step_no' => 1, 'exc' => $mkExc(-0.20, 0.05, true, 10)],
    ['side' => 'buy',  'step_no' => 1, 'exc' => $mkExc(-0.10, 0.15, true, 20)],
    ['side' => 'buy',  'step_no' => 1, 'exc' => $mkExc(-0.30, 0.01, true, 30)],
    ['side' => 'buy',  'step_no' => 1, 'exc' => $mkExc(-0.90, 0.00, false)],   // 덮지 못한 건 → 제외
    ['side' => 'sell', 'step_no' => 1, 'exc' => $mkExc(-0.50, 0.50, true)],    // 매도는 차수 집계에 안 섞는다
    ['side' => 'buy',  'step_no' => 2, 'exc' => $mkExc(-0.04, 0.02, true)],
    ['side' => 'buy',  'step_no' => 2, 'exc' => $mkExc(-0.06, 0.04, true)],
    ['side' => 'buy',  'step_no' => 0, 'exc' => $mkExc(-0.77, 0.00, true)],    // 차수 없는 기록은 제외
];
$xRows[] = ['side' => 'buy', 'step_no' => 1, 'exc' => $mkExc(null, null, true)];   // 오늘 체결 — 되짚을 날 없음
$xAgg = pf_step_excursion($xRows);
t_eq('1차 표본 3건',            3, $xAgg[1]['n']);
/* ★ 빠진 건은 사유를 나눠 센다 — 오늘 담은 것(fresh)과 일봉이 없는 것(skipped)은
 *   할 일이 다르다: 앞은 기다리면 되고 뒤는 일봉을 더 받아야 한다 */
t_eq('일봉 미커버 1건',         1, $xAgg[1]['skipped']);
t_eq('아직 되짚을 날 없음 1건', 1, $xAgg[1]['fresh']);
t_eq('1차 평균 −20%',       -0.20, $xAgg[1]['mae_avg'], 1e-9);
t_eq('1차 중앙 −20% (홀수)', -0.20, $xAgg[1]['mae_med'], 1e-9);
t_eq('1차 최악 −30%',       -0.30, $xAgg[1]['mae_worst'], 1e-9);
t_eq('1차 평균 MFE +7%',     0.07, $xAgg[1]['mfe_avg'], 1e-9);
t_eq('1차 바닥까지 평균 20일',  20, $xAgg[1]['days_avg']);
t_eq('2차 표본 2건',            2, $xAgg[2]['n']);
t_eq('2차 중앙 −5% (짝수=두 값 평균)', -0.05, $xAgg[2]['mae_med'], 1e-9);
t_eq('차수 0 은 집계 안 함',  false, isset($xAgg[0]));
/* ★ 중앙값을 함께 내는 이유 — 한 건의 −90% 가 평균을 통째로 끌고 간다 */
$xOne = pf_step_excursion([
    ['side' => 'buy', 'step_no' => 1, 'exc' => $mkExc(-0.20, 0.05, true)],
    ['side' => 'buy', 'step_no' => 1, 'exc' => $mkExc(-0.10, 0.05, true)],
    ['side' => 'buy', 'step_no' => 1, 'exc' => $mkExc(-0.90, 0.05, true)],
]);
t_eq('평균은 −40%',  -0.40, $xOne[1]['mae_avg'], 1e-9);
t_eq('중앙은 −20%',  -0.20, $xOne[1]['mae_med'], 1e-9);

// ── 계획(다음 차수 하락률)과 견준 판정
t_head('★ 실측 추가하락 vs 계획 하락률 (pf_exc_verdict)');
t_eq('다음 트리거를 지나쳤다 → 얕다', 'shallow', pf_exc_verdict(-0.18, -0.09)['level']);
t_eq('트리거와 맞다',                    'fit', pf_exc_verdict(-0.10, -0.09)['level']);
t_eq('트리거에 못 닿았다 → 넉넉',       'deep', pf_exc_verdict(-0.04, -0.09)['level']);
t_eq('여유 3%p 안은 맞음',               'fit', pf_exc_verdict(-0.12, -0.09)['level']);
t_eq('표본 없으면 판정 없음',           'none', pf_exc_verdict(null, -0.09)['level']);
t_eq('마지막 차수는 판정 없음',         'none', pf_exc_verdict(-0.18, null)['level']);
t_eq('1차(하락률 0)는 판정 없음',       'none', pf_exc_verdict(-0.18, 0.0)['level']);
/* ★★ 표본이 모자라면 판정하지 않는다 — 3건으로 "넉넉"이라 적으면 "괜찮다"로 읽히는데
 *   사실은 아직 모른다는 뜻이다. 차수가 높을수록 최근 체결이라 표본이 적고 빠질 시간도 짧아
 *   하필 그쪽이 안전해 보이는 편향이 있다. */
t_eq('표본 3건이면 판정 보류',          'thin', pf_exc_verdict(-0.04, -0.09, 3)['level']);
t_eq('표본 1건도 보류',                 'thin', pf_exc_verdict(-0.30, -0.09, 1)['level']);
t_eq('표본 5건이면 판정한다',        'shallow', pf_exc_verdict(-0.30, -0.09, 5)['level']);
t_eq('표본 수를 안 주면 그대로 판정',   'deep', pf_exc_verdict(-0.04, -0.09)['level']);

// ══ 룰셋 변동율 vs 실측 변동성 ═══════════════════════════════════════════
/*
 * 「변동율」 칸은 계산에 쓰이지 않는 라벨이었다. 뜻을 정하고(20거래일 변동성) 실측과 견준다.
 * ★ 실측 2026-07-30 — 14종목 20거래일 변동성 중앙 11.1% vs 라벨 8% = 1.39배.
 *   그래서 임계를 1.2배쯤에 두면 거의 전 종목에 경고가 붙는다 → 1.5배 / 0.8배로 넉넉히.
 */
t_head('★ 실측 변동성 (pf_vol20)');
/** 하루 $step 씩 번갈아 오르내리는 봉 — 20거래일 구간수익률이 거의 0 이라 변동성도 0 에 가깝다 */
$mkBars = function (int $n, callable $px): array {
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $p = $px($i);
        $out[] = ['d' => sprintf('2020-01-%02d', ($i % 28) + 1), 'o' => $p, 'h' => $p,
                  'l' => $p, 'c' => $p, 'v' => 1000];
    }
    return $out;
};
// 값이 전혀 안 움직이면 변동성 0
t_eq('불변 시세는 0', 0.0, pf_vol20($mkBars(300, fn($i) => 10000)), 1e-12);
// 표본 부족이면 null (구간이 PF_VOL_MIN_N 개도 안 나옴)
t_eq('봉 60개면 null', null, pf_vol20($mkBars(60, fn($i) => 10000 + $i)));
t_eq('봉 100개면 값이 나온다', true, pf_vol20($mkBars(100, fn($i) => 10000 + $i * 7)) !== null);

/* ★ 정지일(o/h/l/v = 0)이 섞이면 반드시 빠져야 한다 — 안 빼면 구간수익률에 −100% 가 섞인다 */
$vbStop = $mkBars(300, fn($i) => 10000);
$vbStop[150] = ['d' => '2020-01-15', 'o' => 0, 'h' => 0, 'l' => 0, 'c' => 10000, 'v' => 0];
t_eq('정지일이 섞여도 0', 0.0, pf_vol20($vbStop), 1e-12);

/* ★ 창 길이가 결정적이다(실측: 이씨에스 전체 11.4% vs 최근 1년 6.1%).
 *   앞쪽만 출렁이고 최근이 잔잔한 시세를 만들어, 창을 짧게 잡으면 작게 나오는지 본다. */
$vbCalmLate = $mkBars(600, fn($i) => ($i < 300) ? 10000 * (1 + 0.30 * (($i % 2) ? 1 : -1)) : 10000);
$wide   = pf_vol20($vbCalmLate, 560);
$narrow = pf_vol20($vbCalmLate, 250);
t_eq('긴 창은 옛 출렁임을 담는다', true, $wide > 0.05);
t_eq('짧은 창은 최근만 본다',      0.0, $narrow, 1e-12);

t_head('★ 상정 변동율과 견준 판정 (pf_vol_match)');
t_eq('1.5배 이상 → 출렁임 큼', 'rough', pf_vol_match(0.12, 0.08)['level']);
t_eq('딱 1.5배도 포함',        'rough', pf_vol_match(0.12, 0.08)['level']);
t_eq('1.39배(실측 중앙)는 맞음', 'fit',  pf_vol_match(0.1111, 0.08)['level']);
t_eq('0.8배 이하 → 잔잔',      'calm',  pf_vol_match(0.064, 0.08)['level']);
t_eq('0.62배(한국주철관)도 잔잔', 'calm', pf_vol_match(0.0499, 0.08)['level']);
t_eq('배수도 함께 돌려준다',    1.39,   pf_vol_match(0.1112, 0.08)['ratio'], 0.005);
// 라벨이 비어 있으면(변동율 미입력) 판정하지 않는다 — 0 으로 나누거나 거짓 경고를 내면 안 된다
t_eq('라벨 없으면 판정 없음',  'none',  pf_vol_match(0.12, null)['level']);
t_eq('라벨 0 도 판정 없음',    'none',  pf_vol_match(0.12, 0.0)['level']);
t_eq('실측 없으면 판정 없음',  'none',  pf_vol_match(null, 0.08)['level']);

// ══ §2.7 룰셋 자동 생성 ═════════════════════════════════════════════════
/*
 * 「원하는 결과」(단계수·목표 탈출가·손익분기)를 주면 차수표가 나와야 한다.
 * ★★★ 결정적 검증: 현재 rs4 의 <b>하락률 + 실제 손익분기</b>를 넣으면 rs4 의 <b>비중이 그대로</b> 나온다.
 *   비중이 탐색이 아니라 수식으로 풀린다는 증거다 (w_n = [p_n·Q_{n−1} − C_{n−1}(1+b_n)] / b_n).
 */
t_head('★ 손익분기 → 비중 역산 (pf_weights_from_be)');
$rsP = [1 => 1.0];                                    // rs4 가격 경로
foreach ([2 => -0.09, 3 => -0.12, 4 => -0.16, 5 => -0.20, 6 => -0.25, 7 => -0.30] as $n => $d) {
    $rsP[$n] = $rsP[$n - 1] * (1 + $d);
}
// rs4 를 pf_simulate 로 돌려 얻은 실제 손익분기 (화면에 찍히는 값과 같다)
$rsSteps = [];
foreach ([1=>[5,0,15],2=>[5,-9,20],3=>[8,-12,25],4=>[14,-16,30],5=>[17,-20,35],6=>[23,-25,40],7=>[28,-30,50]]
         as $n => [$w, $d, $t]) {
    $rsSteps[$n] = ['weight' => $w/100, 'drop_rate' => $d/100, 'target_rate' => $t/100];
}
$rsSim = pf_simulate($rsSteps, 100000000);
$rsB   = [];
foreach ($rsSim as $n => $s) $rsB[$n] = (float)$s['breakeven_rate'];

$gotW = pf_weights_from_be($rsP, $rsB);
foreach ([1 => 5.0, 2 => 5.0, 3 => 8.0, 4 => 14.0, 5 => 17.0, 6 => 23.0, 7 => 28.0] as $n => $want) {
    t_eq("{$n}차 비중 복원 {$want}%", $want, $gotW[$n] * 100, 0.02);
}
t_eq('비중합 100%', 1.0, array_sum($gotW), 1e-9);
// 풀 수 없는 조합은 빈 배열로 알린다 (거짓 룰셋을 내놓지 않는다)
t_eq('손익분기가 0 이면 못 푼다', 0, count(pf_weights_from_be([1=>1.0, 2=>0.9], [1=>0.0, 2=>0.0])));

t_head('★ 손익분기 목표 곡선 (pf_be_curve)');
$bc = pf_be_curve(7, -0.35, 1.4);
t_eq('1차는 0',            0.0,   $bc[1], 1e-12);
t_eq('마지막은 −35%',     -0.35,  $bc[7], 1e-12);
t_eq('단조 감소',          true,  $bc[2] > $bc[3] && $bc[3] > $bc[4] && $bc[6] > $bc[7]);
/* 곡률 1 이면 등차 — 4차(중간)가 최종의 절반 */
t_eq('곡률 1 은 등차',    -0.175, pf_be_curve(7, -0.35, 1.0)[4], 1e-12);
/* 곡률 1.4 는 앞이 완만 — 중간이 최종의 절반보다 얕다 */
t_eq('곡률 1.4 는 앞이 완만', true, pf_be_curve(7, -0.35, 1.4)[4] > -0.175);

t_head('★ 룰셋 생성 (pf_rule_gen)');
$g = pf_rule_gen(['n' => 7, 'be_last' => -0.35, 'exit_first' => 0.15, 'exit_last' => -0.10]);
t_eq('생성 성공',        true, $g['ok']);
t_eq('7차수',               7, count($g['steps']));
t_eq('비중합 100%',       1.0, pf_weight_sum($g['steps']), 1e-9);
t_eq('1차 하락률 0',      0.0, $g['steps'][1]['drop_rate'], 1e-12);
t_eq('최종 손익분기 −35%', -0.35, $g['rows'][7]['breakeven'], 1e-6);
t_eq('1차 탈출가 = 최초가+15%', 1.15, $g['rows'][1]['exit'], 1e-12);
t_eq('마지막 탈출가 = 최초가−10%', 0.90, $g['rows'][7]['exit'], 1e-12);
t_eq('1차 목표수익률 = 15%', 0.15, $g['steps'][1]['target_rate'], 1e-9);
/* ★ 목표수익률은 깊어질수록 커진다 — 탈출가는 내려가는데 평균단가가 더 빨리 내려가기 때문이다 */
t_eq('목표수익률 단조 증가', true,
     $g['steps'][2]['target_rate'] > $g['steps'][1]['target_rate']
     && $g['steps'][7]['target_rate'] > $g['steps'][6]['target_rate']);
// 비중은 뒤로 갈수록 커져야 한다 (물타기의 기본 형태)
t_eq('비중 단조 증가', true,
     $g['steps'][2]['weight'] >= $g['steps'][1]['weight']
     && $g['steps'][7]['weight'] > $g['steps'][6]['weight']);
// 잘못된 입력은 만들지 않고 이유를 돌려준다
t_eq('손익분기 양수면 실패', false, pf_rule_gen(['be_last' => 0.10])['ok']);
t_eq('하락률 양수면 실패',   false, pf_rule_gen(['drop_first' => 0.05])['ok']);

/* ★ 수식이 풀린 것과 쓸 만한 것은 다르다 — 생성기가 스스로 문제를 짚어야 한다.
 *   실측으로 나온 세 가지: 1차 비중 2.53%(고가주 0주) · 5단계는 비중 역순 · 마지막 목표 120% */
t_head('★ 생성 결과 자기점검 (pf_rule_gen_warn)');
$gWarn = pf_rule_gen(['n' => 7, 'be_last' => -0.35, 'exit_first' => 0.15, 'exit_last' => -0.10,
                      'limit_amt' => 30000000, 'top_price' => 1322000]);
t_eq('1차 2.53% 는 경고 대상', true, count($gWarn['warn']) > 0);
t_eq('1차에 1주도 못 담는다고 짚는다', true,
     (bool)preg_grep('/1주도 못 담/u', $gWarn['warn']));
t_eq('마지막 목표 120% 도 짚는다', true,
     (bool)preg_grep('/목표수익률이 120/u', $gWarn['warn']));

// 5단계는 비중이 거꾸로 흐른다 → 물타기 아님을 짚어야 한다
$g5 = pf_rule_gen(['n' => 5, 'be_last' => -0.35, 'exit_first' => 0.15, 'exit_last' => -0.10]);
t_eq('5단계는 비중 역순', true, $g5['steps'][5]['weight'] < $g5['steps'][1]['weight']);
t_eq('물타기 아님을 짚는다', true, (bool)preg_grep('/물타기 형태가 아닙니다/u', $g5['warn']));

/* ★ 곡률이 1차 비중을 좌우한다 — 경고를 없애는 손잡이가 실제로 이것이다 */
$gK10 = pf_rule_gen(['n' => 7, 'be_last' => -0.35, 'be_k' => 1.0]);
$gK14 = pf_rule_gen(['n' => 7, 'be_last' => -0.35, 'be_k' => 1.4]);
t_eq('곡률 1.0 이 1차 비중을 키운다', true,
     $gK10['steps'][1]['weight'] > $gK14['steps'][1]['weight'] * 2);
t_eq('곡률 1.0 의 1차 비중 8.3%', 8.31, $gK10['steps'][1]['weight'] * 100, 0.05);

/* ★★ 곡률은 방향이 반대인 두 벽 사이에 끼어 있다 — 손으로 찾게 두면 못 찾으니 훑어서 알려 준다.
 *   실측(7단계·−35%·한도 3천만·하이닉스 132만): 1.15~1.20 만 「역전 없음 + 1주 가능」 둘 다 통과 */
t_head('★ 곡률 권장 범위 (pf_rule_gen_k_range)');
$kr = pf_rule_gen_k_range(['n' => 7, 'be_last' => -0.35, 'exit_first' => 0.15, 'exit_last' => -0.10,
                           'limit_amt' => 30000000, 'top_price' => 1322000]);
t_eq('아래끝 1.15', 1.15, $kr['from'], 1e-9);
t_eq('위끝 1.20',   1.20, $kr['to'],   1e-9);
// 한도·주가를 안 주면 「역전 없음」만 보므로 범위가 위로 열린다
$kr2 = pf_rule_gen_k_range(['n' => 7, 'be_last' => -0.35]);
t_eq('한도 없으면 위끝이 더 크다', true, $kr2['to'] > $kr['to']);
// 5단계는 어떤 곡률로도 역전을 피할 수 없다 (앞에 많이 담아야 −35% 가 만들어진다)
$kr5 = pf_rule_gen_k_range(['n' => 5, 'be_last' => -0.35]);
t_eq('5단계는 통과 곡률 없음', null, $kr5['from']);

// ══ 차수표 → 룰셋 조건값 역산 ════════════════════════════════════════════
/*
 * 편집 화면 상단 뱃지는 <b>메모가 아니라 차수에서 역산</b>한다(메모는 손으로 고치면 거짓이 된다).
 * ★★ 결정적 검증: 생성기로 만든 룰셋을 역산하면 <b>넣은 입력값이 그대로 돌아와야</b> 한다.
 */
t_head('★ 룰셋 조건값 역산 (pf_rule_conditions)');
$cIn = ['n' => 7, 'be_last' => -0.35, 'be_k' => 1.4, 'drop_first' => -0.09, 'drop_step' => -0.042,
        'exit_first' => 0.15, 'exit_last' => -0.10];
$cGen = pf_rule_gen($cIn);
$cBack = pf_rule_conditions($cGen['steps']);

t_eq('단계 수 복원',        7,      $cBack['n']);
t_eq('2차 하락률 복원',    -0.09,   $cBack['drop_first'], 1e-9);
t_eq('차수당 증가폭 복원', -0.042,  $cBack['drop_step'], 1e-9);
t_eq('최종 손익분기 복원', -0.35,   $cBack['be_last'], 1e-6);
t_eq('1차 탈출가 복원',     0.15,   $cBack['exit_first'], 1e-9);
t_eq('마지막 탈출가 복원', -0.10,   $cBack['exit_last'], 1e-9);
t_eq('곡률 복원',           1.40,   $cBack['be_k'], 0.02);
t_eq('비중합 100%',         1.0,    $cBack['weight_sum'], 1e-9);

/* 곡률을 바꿔 넣어도 그 값이 돌아온다 (한 점만 보지 않고 평균하므로 안정적) */
$cBack2 = pf_rule_conditions(pf_rule_gen(array_merge($cIn, ['be_k' => 1.15]))['steps']);
t_eq('곡률 1.15 도 복원', 1.15, $cBack2['be_k'], 0.02);

/* ★ 사람이 손으로 만든 rs4 에도 쓸 수 있어야 한다 — 목표수익률이 뜻하는 탈출가가 드러난다 */
$cRs4 = pf_rule_conditions($rsSteps);
t_eq('rs4 단계 수',        7,       $cRs4['n']);
t_eq('rs4 1차 탈출가 +15%', 0.15,   $cRs4['exit_first'], 1e-9);
t_eq('rs4 마지막 탈출가 −34.5%', -0.345, $cRs4['exit_last'], 0.003);
t_eq('rs4 최종 손익분기 −35.31%', -0.3531, $cRs4['be_last'], 1e-4);
t_eq('rs4 최종 깊이 −71.7%', -0.7175, $cRs4['depth'], 1e-3);

// 차수가 하나뿐이면 역산할 것이 없다
t_eq('1차수만 있으면 빈 배열', 0, count(pf_rule_conditions([1 => ['weight'=>1,'drop_rate'=>0,'target_rate'=>0.1]])));

// ══ 체결 시점의 시장 상태 ════════════════════════════════════════════════
/*
 * ★★ 오늘 상태를 옛 체결에 붙이면 아무 뜻도 없는 라벨이 된다 — 반드시 <b>그 날까지의 봉</b>으로 잰다.
 *   종목 상세의 일봉 칩과 시뮬레이터 차트·체결표가 이 함수 하나를 함께 쓴다.
 */
t_head('★ 그 날까지의 봉으로 계산한 시장 상태 (pf_state_at)');
/** 하루 $step% 씩 내리다가 마지막에 급락하는 봉 (역배열·과매도가 뜨게) */
$saBars = [];
$px = 10000.0;
for ($i = 0; $i < 80; $i++) {
    $px *= 0.995;                                   // 꾸준히 하락 → 역배열
    if ($i === 79) $px *= 0.90;                     // 마지막 날 급락
    $d = date('Y-m-d', strtotime('2024-01-01 +' . $i . ' day'));
    $saBars[] = ['d' => $d, 'o' => $px, 'h' => $px * 1.005, 'l' => $px * 0.995, 'c' => $px, 'v' => 1000];
}
$lastD  = $saBars[79]['d'];
$midD   = $saBars[40]['d'];
$earlyD = $saBars[10]['d'];

t_eq('표본 25봉 미만이면 빈 배열', 0, count(pf_state_at($saBars, $earlyD)));
t_eq('중간 날짜엔 상태가 나온다', true, count(pf_state_at($saBars, $midD)) > 0);
/* ★ 마지막 날의 −10% 급락은 <b>그 날 이후</b>를 보지 않는 중간 날짜 계산에 섞이지 않아야 한다.
 *   ★★ 이 단정이 처음에 실패해서 엔진 버그를 잡았다 — 하루 −0.5% 로 <b>일정하게</b> 내리는
 *   이 픽스처는 sd20 이 부동소수 잔여값까지 내려가, 예전 코드에서는 σ 가 −6경이 되어
 *   「급락 −0.5%」 가 떴다. PF_SD_MIN 하한을 넣어 고쳤다. */
/* ★ 단언은 <b>키</b>로 한다 — 라벨은 표시 문구라 바뀐다(2026-08-02 「급락 −N%」→「−N%」로 개편). */
$mid  = array_column(pf_state_at($saBars, $midD, 3), 'key');
$last = array_column(pf_state_at($saBars, $lastD, 3), 'key');
t_eq('중간엔 급락이 없다',  false, in_array('plunge', $mid, true));
t_eq('마지막엔 급락이 뜬다', true, in_array('plunge', $last, true));
t_eq('하락 추세라 역배열',   true, in_array('downtrend', $last, true));
// 라벨은 부호 붙은 % 하나 — 20·40일 모멘텀 칩(「20일 +112%」)과 같은 계열로 읽히게
t_eq('라벨은 % 하나', true, (bool)preg_grep('/^−?-?\d+\.\d%$/u',
     array_column(pf_state_at($saBars, $lastD, 3), 'label')));

/* ★★ 변동성이 0 에 가까울 때 σ 가 폭발하지 않는지 — 위에서 잡은 버그의 회귀 시험 */
t_head('★ σ 하한 (PF_SD_MIN) — 잔잔하면 σ 를 만들지 않는다');
$flatInd = pf_indicators(array_slice($saBars, 0, 41));      // 하루 −0.5% 로 일정
t_eq('sd20 이 0 에 가깝다', true, $flatInd['sd20'] < 1e-6);
t_eq('그럴 때 σ 는 null',   null, $flatInd['sigma']);
t_eq('−0.5% 를 급락으로 보지 않는다', false,
     in_array('plunge', array_column(pf_market_signals($flatInd, 3), 'key'), true));
/* 반대로 변동성이 정상이면 σ 가 나오고 큰 하락은 급락으로 잡힌다 */
$liveInd = pf_indicators($saBars);                          // 마지막 날 −10.45%
t_eq('정상 변동성이면 σ 가 있다', true, $liveInd['sigma'] !== null);
t_eq('σ 가 −2 아래',             true, $liveInd['sigma'] < -2.0);
// max 로 개수를 자른다 (차트 마커는 1개, 표는 3개)
t_eq('max=1 이면 하나만', 1, count(pf_state_at($saBars, $lastD, 1)));
// 빈 입력·빈 날짜는 안전하게 빈 배열
t_eq('봉이 없으면 빈 배열',   0, count(pf_state_at([], $lastD)));
t_eq('날짜가 비면 빈 배열',   0, count(pf_state_at($saBars, '')));
/* ★ 봉보다 앞선 날짜면 자를 것이 없어 빈 배열 (첫 체결이 시세 시작 전인 경우) */
t_eq('시세 시작 전 날짜',     0, count(pf_state_at($saBars, '2020-01-01')));

/* ★ 차트 마커용 짧은 이름 — 첫 낱말만 자르면 뜻이 사라지는 것들이 핵심이다 */
t_eq('과매도',   '과매도',   pf_mkt_short('oversold'));
t_eq('역배열',   '역배열',   pf_mkt_short('downtrend'));
t_eq('52주 최저권 → 최저권', '최저권', pf_mkt_short('low52'));
t_eq('4일 연속 하락 → 연속하락', '연속하락', pf_mkt_short('down_streak'));
t_eq('거래량 2.0배 → 거래량', '거래량', pf_mkt_short('vol'));
t_eq('모르는 키는 빈 문자열', '', pf_mkt_short('nope'));
/* 실제로 나오는 키가 전부 이름을 갖고 있어야 한다 — 하나라도 비면 마커가 「4차 」 로 뜬다 */
foreach (['surge','plunge','vol','illiq','low52','high52','oversold','overbought',
          'downtrend','uptrend','disp','down_streak','up_streak'] as $k) {
    t_eq("키 {$k} 에 이름 있음", true, pf_mkt_short($k) !== '');
}

// ══ 사이클 나이 · 「장기물림」 경보 ═════════════════════════════════════════
/*
 * 근거 = 사이클 226개 실측: 물림비율 5차 23%·6차 42%·7차 50%, 2년 초과의 1/3 은 안 닫힘.
 * ★ 자동 손절·동결이 아니라 <b>경보</b>다 — 백테스트에서 기계식 규칙은 수익을 깎았다.
 */
t_head('★ 사이클 나이 (pf_cycle_age)');
$agRows = [
    ['side' => 'sell', 'traded_at' => '2024-01-05', 'price' => 12000, 'qty' => 10],   // 매도는 시작이 아니다
    ['side' => 'buy',  'traded_at' => '2024-03-10', 'price' => 10000, 'qty' => 10],
    ['side' => 'buy',  'traded_at' => '2024-02-01', 'price' => 11000, 'qty' => 10],   // 이게 첫 매수
];
$ag = pf_cycle_age($agRows, '2024-03-01');
t_eq('첫 매수일 = 가장 이른 buy', '2024-02-01', $ag['start']);
t_eq('나이 29일',                29,           $ag['days']);
t_eq('매도만 있으면 null',       null,         pf_cycle_age([['side'=>'sell','traded_at'=>'2024-01-05']])['days']);
t_eq('체결 없으면 null',         null,         pf_cycle_age([])['days']);
t_eq('오늘 샀으면 0일',          0,            pf_cycle_age([['side'=>'buy','traded_at'=>'2024-03-01']], '2024-03-01')['days']);

t_head('★ 「장기물림」 경보 (pf_cycle_alert) — 2년 또는 5차, 먼저 오는 쪽');
t_eq('729일·4차 = 아직',        'none',  pf_cycle_alert(729, 4)['level']);
t_eq('730일이면 경보',          'alert', pf_cycle_alert(730, 1)['level']);
t_eq('5차면 나이 무관 경보',    'alert', pf_cycle_alert(10, 5)['level']);
t_eq('둘 다면 사유 두 개', true,
     str_contains(pf_cycle_alert(800, 6)['why'], '2년 경과') && str_contains(pf_cycle_alert(800, 6)['why'], '6차 도달'));
t_eq('나이 없으면 차수만 본다', 'alert', pf_cycle_alert(null, 7)['level']);
t_eq('둘 다 없으면 없음',       'none',  pf_cycle_alert(null, null)['level']);
t_eq('경보 라벨',               '장기물림', pf_cycle_alert(730, 1)['label']);

// 나이 표시 — 1년 미만은 일수, 그 뒤는 년 (67일과 3.2년이 한 열에서 갈려야 한다)
t_eq('84일',   '84일',  pf_age_txt(84));
t_eq('365일 = 1.0년', '1.0년', pf_age_txt(365));
t_eq('1,206일 = 3.3년', '3.3년', pf_age_txt(1206));
t_eq('null 은 대시',   '-',    pf_age_txt(null));

// ══ 차수 지연 실전 반영 (pf_delay_adjust · 2026-08-02) ═══════════════════
// 다음 차수에 delay_days 가 있고 직전 매수 후 그 일수를 넘기면 그 차수는 만료 —
// 신호를 끄고 계획을 한 차수 아래로. 급락(더 깊이 도달)은 무조건 매수라 손대지 않는다.
$D5D = $DROP_5;
$D5D[2]['delay_days'] = 30;

$dc = pf_position_calc($D5D, $hbTrades, 375_000_000, 51000);   // 2차 이론가(50,920) 위 — 신호 전
t_eq('경과 이내면 그대로',        2,     pf_delay_adjust($dc, $D5D, '2026-01-01', '2026-01-21')['next_step']);
$b = pf_delay_adjust($dc, $D5D, '2026-01-01', '2026-02-10');   // 40일 경과 > 30
t_eq('만료 시 다음차수 3차',      3,     $b['next_step']);
t_eq('  다음매수가 = 3차 이론가', 46840, $b['next_price']);
t_eq('  매수신호 꺼짐',           false, $b['buy_signal']);
t_eq('  delay_skip 기록',         2,     $b['delay_skip']['step']);

$dc2 = pf_position_calc($D5D, $hbTrades, 375_000_000, 50000);  // 2차 도달 상태
t_eq('도달 상태 원본은 매수신호', true,  $dc2['buy_signal']);
$b2 = pf_delay_adjust($dc2, $D5D, '2026-01-01', '2026-02-10');
t_eq('  만료면 억제',             false, $b2['buy_signal']);
t_eq('  buy_qty 0',               0,     $b2['buy_qty']);

$dc3 = pf_position_calc($D5D, $hbTrades, 375_000_000, 40000);  // 3차까지 급락 (reach 3 > next 2)
$b3 = pf_delay_adjust($dc3, $D5D, '2026-01-01', '2026-02-10');
t_eq('급락(더 깊이 도달)은 그대로', true, ($b3['buy_qty'] ?? 0) > 0);
t_eq('  delay_skip 없음',           true, !isset($b3['delay_skip']));

t_eq('지연 미정의면 그대로', 2,
     pf_delay_adjust(pf_position_calc($DROP_5, $hbTrades, 375_000_000, 51000), $DROP_5, '2026-01-01', '2026-02-10')['next_step']);
t_eq('직전 매수 없으면 그대로', 1,
     pf_delay_adjust(pf_position_calc($D5D, [], 375_000_000, 51000), $D5D, null, '2026-02-10')['next_step']);

// ══ 퀀트 사다리 (pf_box_ladder_build + pf_position_calc $levels · 2026-08-02) ═══
// 가격이 절대값 — 이론가 체인 대신 편입 때 확정한 지지선 표를 쓴다.
$BXL = pf_box_ladder_build([10000, 8900, 8000, 7000]);
t_eq('비중이 풀린다',            true,  $BXL !== null);
t_eq('  4차수',                    4,   count($BXL['levels']));
t_eq('  1차 가격(최고값)',     10000,   $BXL['levels'][1]['price']);
t_eq('  비중합 100%',            1.0,   array_sum(array_map(fn($l) => $l['weight'], $BXL['levels'])), 0.001);
t_eq('  1차>2차 역전 없음',      true,  $BXL['levels'][2]['weight'] >= $BXL['levels'][1]['weight'] - 0.001);
t_eq('  3차부터 지연 20일',       20,   $BXL['levels'][3]['delay_days']);
t_eq('간격 없으면 null',        null,   pf_box_ladder_build([10000, 9990, 9980]));
t_eq('2개는 null',              null,   pf_box_ladder_build([10000, 9000]));

// 해 선택 정책 (2026-08-02) — 계단형(내려갈수록 비중 비감소) 우선 · 보수적 BE 우선.
// 옛 정책(공격 BE 첫 해)은 간격이 불균등하면 산봉우리(중간 48%·마지막 11%)를 골라
// 지지가 다 깨졌을 때 손실이 컸다 — 실사례 가격으로 회귀를 박아 둔다.
t_eq('균등 계단은 계단형 해',    true,  $BXL['mono']);
t_eq('  마지막 차수가 최대 비중', true,  $BXL['levels'][4]['weight'] >= $BXL['levels'][3]['weight'] - 0.001);
$BXU = pf_box_ladder_build([1804000, 1557000, 846000, 532000]);   // 사용자 실사례 (간격 불균등)
t_eq('불균등 간격도 계단형 해',   true,  $BXU['mono']);
t_eq('  1차 비중',             0.099,   $BXU['levels'][1]['weight'], 0.01);
t_eq('  4차 비중(최대)',       0.407,   $BXU['levels'][4]['weight'], 0.01);
t_eq('  4차 ≥ 3차',              true,  $BXU['levels'][4]['weight'] >= $BXU['levels'][3]['weight'] - 0.001);
t_eq('  최종 BE 보수화',      -0.282,   end($BXU['be']), 0.01);   // 옛 정책은 −0.458

// pf_box_ladder_detail — 확정본·미리보기 상세표의 파생값 (비중·가격만의 함수)
$BXD = pf_box_ladder_detail($BXU['levels']);
t_eq('상세 4행',                    4,  count($BXD));
t_eq('  1차 변동율 없음',        null,  $BXD[1]['chg']);
t_eq('  2차 변동율',          -0.1369,  $BXD[2]['chg'], 0.001);
t_eq('  누적비중 100%',           1.0,  $BXD[4]['cum'], 0.001);
t_eq('  1차 평단 = 1차 가격', 1804000,  $BXD[1]['avg'], 1);
t_eq('  1차 손실률 0',            0.0,  $BXD[1]['be'], 0.0001);
t_eq('  최종 손실률 = 풀이 BE', end($BXU['be']), $BXD[4]['be'], 0.002);
t_eq('  평단은 단조 하락',       true,  $BXD[2]['avg'] > $BXD[3]['avg'] && $BXD[3]['avg'] > $BXD[4]['avg']);
t_eq('  탈출가 = 평단×(1+목표)', $BXD[3]['avg'] * 1.25, $BXD[3]['exit'], 0.01);

// calc — 레벨이 있으면 이론가 = 절대가격, 1차도 가격 조건(매복)
$bc = pf_position_calc([], [], 10000000, 10500, [], [], $BXL['levels']);
t_eq('1차 이론가 = 레벨1',     10000,   $bc['steps'][1]['theory_price']);
t_eq('  현재가 위면 1차 신호 없음', false, $bc['buy_signal']);
t_eq('  다음매수가 = 레벨1',   10000,   $bc['next_price']);
$bc2 = pf_position_calc([], [], 10000000, 9900, [], [], $BXL['levels']);
t_eq('지지 터치 시 1차 신호',    true,  (bool)$bc2['buy_signal']);
// 1차 체결 후 급락 — 레벨3까지 도달하면 catch-up (누적목표 − 투입, 절대가 기준)
$bTrades = [1 => ['price' => 9900, 'qty' => 100, 'traded_at' => '2026-01-05']];
$bc3 = pf_position_calc([], $bTrades, 10000000, 7900, [], [], $BXL['levels']);
t_eq('도달 차수 = 3 (8,000 이하)', 3,   $bc3['reach_step']);
t_eq('  catch-up 매수금액 > 0',  true,  (float)$bc3['buy_amount'] > 0);
t_eq('  2차 이론가는 체결과 무관', 8900, $bc3['steps'][2]['theory_price']);
// 지연 — 레벨 행의 delay_days 를 rows 가 실어 pf_delay_adjust 가 읽는다
$bc4 = pf_position_calc([], $bTrades, 10000000, 8950, [], [], $BXL['levels']);   // 2차(8,900) 위
$bc4a = pf_delay_adjust($bc4, [], '2026-01-05', '2026-01-10');
t_eq('2차는 지연 0 — 그대로',      2,   $bc4a['next_step']);
$bTr2 = [1 => ['price' => 9900, 'qty' => 100], 2 => ['price' => 8850, 'qty' => 150, 'traded_at' => '2026-01-20']];
$bc5 = pf_position_calc([], $bTr2, 10000000, 8100, [], [], $BXL['levels']);      // 3차(8,000) 위
$bc5a = pf_delay_adjust($bc5, [], '2026-01-20', '2026-03-01');                   // 40일 > 20일
t_eq('3차 만료 → 다음 4차',        4,   $bc5a['next_step']);
t_eq('  다음매수가 = 레벨4',    7000,   $bc5a['next_price']);

// pf_last_buy_at — 마지막 「매수」 일자 (매도는 무시)
t_eq('마지막 매수일', '2026-03-05', pf_last_buy_at([
    ['side' => 'buy',  'traded_at' => '2026-01-10 09:00:00'],
    ['side' => 'sell', 'traded_at' => '2026-04-01'],
    ['side' => 'buy',  'traded_at' => '2026-03-05'],
]));
t_eq('매수 없으면 null', null, pf_last_buy_at([['side' => 'sell', 'traded_at' => '2026-04-01']]));

// ══ 결과 ════════════════════════════════════════════════════════════════
$pass = $GLOBALS['pf_pass'];
$fail = $GLOBALS['pf_fail'];
echo "\n" . str_repeat('═', 74) . "\n";
printf(" 결과: %d PASS / %d FAIL  (총 %d)\n", $pass, $fail, $pass + $fail);
echo str_repeat('═', 74) . "\n";
exit($fail > 0 ? 1 : 0);
?>
