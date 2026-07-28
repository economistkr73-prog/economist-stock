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

require_once __DIR__ . '/../lib/calc.php';

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

// ══ 결과 ════════════════════════════════════════════════════════════════
$pass = $GLOBALS['pf_pass'];
$fail = $GLOBALS['pf_fail'];
echo "\n" . str_repeat('═', 74) . "\n";
printf(" 결과: %d PASS / %d FAIL  (총 %d)\n", $pass, $fail, $pass + $fail);
echo str_repeat('═', 74) . "\n";
exit($fail > 0 ? 1 : 0);
?>
