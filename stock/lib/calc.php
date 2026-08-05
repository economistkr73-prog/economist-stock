<?php
/**
 * stock/lib/calc.php — 분할매수 포트폴리오 계산 엔진 (순수 함수 전용)
 *
 * 요건정의서 §2 전체 구현.
 * DB·세션·$_GET·출력에 일절 의존하지 않는다. 모든 화면/API는 이 파일의 함수만 호출한다.
 * 검증: php stock/tests/calc_test.php
 *
 * 용어
 *   steps  : 룰셋 차수 배열. step_no => ['weight','drop_rate','target_rate']
 *            weight     0.0150 = 1.5%   (투자한도 대비 비중)
 *            drop_rate -0.0700 = -7%    (★직전 차수 대비 "단계" 하락률. 최초가 대비 누적 아님)
 *            target_rate 0.1500 = +15%  (해당 차수 도달 시 목표수익률)
 *   trades : 실체결 매수 배열. step_no => ['price','qty']  (미체결 차수는 키 자체가 없음)
 */

// ── §2.1 파라미터 기본값 ───────────────────────────────────────────────
const PF_TICK           = 10;      // 목표가 절사 단위 (원)
const PF_BUY_COST_RATE  = 0.0034;  // 매수비용률 0.34% (= 매수 위탁수수료)
const PF_SELL_COST_RATE = 0.0030;  // 매도비용률 0.30% (= 매도 위탁수수료 + 증권거래세)

// 매도비용의 내역. 두 값을 더하면 PF_SELL_COST_RATE 가 되도록 기본값을 잡았다.
const PF_SELL_FEE_RATE  = 0.0015;  // 매도 위탁수수료 (증권사별)
const PF_TAX_RATE       = 0.0020;  // 증권거래세+농특세 (시장별, 2026년 코스피/코스닥 기준)

/**
 * 파라미터 병합. 화면/포트폴리오별 오버라이드를 받아 결측치를 기본값으로 채운다.
 *
 * fee_tiers 가 있으면 정률 대신 구간표를 쓴다 (삼성증권처럼 거래금액 구간별 요율+정액).
 */
function pf_params(array $override = []): array
{
    return [
        'tick'            => (int)  ($override['tick']            ?? PF_TICK),
        'buy_cost_rate'   => (float)($override['buy_cost_rate']   ?? PF_BUY_COST_RATE),
        'sell_cost_rate'  => (float)($override['sell_cost_rate']  ?? PF_SELL_COST_RATE),
        'sell_fee_rate'   => (float)($override['sell_fee_rate']   ?? PF_SELL_FEE_RATE),
        'tax_rate'        => (float)($override['tax_rate']        ?? PF_TAX_RATE),
        'fee_tiers'       => $override['fee_tiers'] ?? [],
    ];
}

/**
 * 포트폴리오 수수료 + 시장 세율을 계산 파라미터로 합성한다.
 *
 *   매수비용 = 위탁수수료
 *   매도비용 = 위탁수수료 + 증권거래세(시장별)
 *
 * 수수료는 증권사(포트폴리오)마다, 거래세는 시장(KOSPI/KOSDAQ/ETF)마다 다르므로
 * 하나의 상수로 묶지 않고 두 축으로 나눈다.
 * 구간표(fee_tiers)가 있으면 정률 대신 그걸 쓴다.
 *
 * @param array $row   buy_fee_rate·sell_fee_rate·tax_rate 를 담은 행 (positions() 결과 등)
 * @param array $tiers 이 포트폴리오의 수수료 구간표 (없으면 정률)
 */
function pf_cost_params(array $row, array $tiers = []): array
{
    $buyFee  = (!isset($row['buy_fee_rate'])  || $row['buy_fee_rate']  === null) ? PF_BUY_COST_RATE : (float)$row['buy_fee_rate'];
    $sellFee = (!isset($row['sell_fee_rate']) || $row['sell_fee_rate'] === null) ? PF_SELL_FEE_RATE : (float)$row['sell_fee_rate'];
    $tax     = (!isset($row['tax_rate'])      || $row['tax_rate']      === null) ? PF_TAX_RATE      : (float)$row['tax_rate'];

    return [
        'tick'           => (int)($row['tick'] ?? PF_TICK),
        'buy_cost_rate'  => $buyFee,
        'sell_fee_rate'  => $sellFee,
        'sell_cost_rate' => $sellFee + $tax,
        'tax_rate'       => $tax,
        'fee_tiers'      => $tiers,
    ];
}

// ── 위탁수수료 구간표 ─────────────────────────────────────────────────
/**
 * 거래대금에 대한 위탁수수료.
 *
 * 증권사마다 체계가 다르다.
 *   키움  : 전 구간 0.015% (단일 구간, 정액 0)
 *   삼성  : 1천만 미만 0.147216% + 1,500원 / 1천만~5천만 0.127216% + 3,000원 / …
 *
 * 구간은 min_amt 이상 중 가장 큰 것을 고른다. 구간표가 비어 있으면 $flatRate 정률.
 *
 * @param array $tiers [['min_amt'=>0,'fee_rate'=>0.0015,'fee_fixed'=>1500], ...]
 */
function pf_fee_amount(float $amount, array $tiers, float $flatRate = 0.0): float
{
    if ($amount <= 0) return 0.0;
    if (!$tiers)      return $amount * $flatRate;

    $sel = null;
    foreach ($tiers as $t) {
        $min = (float)($t['min_amt'] ?? 0);
        if ($amount < $min) continue;
        if ($sel === null || $min >= (float)($sel['min_amt'] ?? 0)) $sel = $t;
    }
    if ($sel === null) {   // 최저 구간보다 작은 금액 → 가장 낮은 구간 적용
        foreach ($tiers as $t) {
            if ($sel === null || (float)($t['min_amt'] ?? 0) < (float)($sel['min_amt'] ?? 0)) $sel = $t;
        }
    }

    return $amount * (float)($sel['fee_rate'] ?? 0) + (float)($sel['fee_fixed'] ?? 0);
}

/** 매수 1건의 비용 (위탁수수료) */
function pf_buy_cost(float $amount, array $p): float
{
    return pf_fee_amount($amount, $p['fee_tiers'] ?? [], (float)$p['buy_cost_rate']);
}

/**
 * 매도 1건의 비용 (위탁수수료 + 증권거래세)
 *
 * 정률일 때는 합산된 sell_cost_rate 를 그대로 쓴다. pf_cost_params() 가 이미
 * (수수료 + 세율)로 채워 넣기 때문이고, 그래야 요건정의서 §2.1 의 0.30% 를
 * 직접 넘겼을 때 §2.5 검증표가 그대로 재현된다.
 * 구간표가 있을 때만 수수료와 세금을 따로 계산한다 (수수료가 금액에 따라 달라지므로).
 */
function pf_sell_cost(float $amount, array $p): float
{
    if (empty($p['fee_tiers'])) {
        return $amount * (float)$p['sell_cost_rate'];
    }
    return pf_fee_amount($amount, $p['fee_tiers'], (float)$p['sell_fee_rate'])
         + $amount * (float)$p['tax_rate'];
}

/** 특정 거래금액에서의 실효 매도비용률 (구간표를 정률로 환산) */
function pf_sell_cost_rate(float $amount, array $p): float
{
    if ($amount <= 0) return (float)$p['sell_cost_rate'];
    if (empty($p['fee_tiers'])) return (float)$p['sell_cost_rate'];
    return pf_sell_cost($amount, $p) / $amount;
}

// ── 호가 절사 ─────────────────────────────────────────────────────────
/**
 * tick 단위 내림. 부동소수 오차 방어를 위해 몫을 6자리에서 반올림한 뒤 floor 한다.
 * (예: 37000*0.75 가 27749.999999 로 떨어져 27,740 이 되는 사고 방지)
 */
function pf_floor_tick(float $v, int $tick = PF_TICK): float
{
    if ($tick <= 1) return floor(round($v, 6));
    return floor(round($v / $tick, 6)) * $tick;
}

/** tick 단위 올림. 동일하게 오차 방어. */
function pf_ceil_tick(float $v, int $tick = PF_TICK): float
{
    if ($tick <= 1) return ceil(round($v, 6));
    return ceil(round($v / $tick, 6)) * $tick;
}

// ── §2.2 이론가 사다리 ────────────────────────────────────────────────
/**
 * 전 차수의 이론 매수가를 1차부터 순차 루프로 한 번에 만든다. (재귀 금지 — 요건정의서 주의사항)
 *
 * 기준가 규칙: 직전 차수의 (실매수가, 이론가) 중 더 낮은 값.
 *   → 계획보다 더 싸게 샀으면 그 가격에서 다시 내려간다.
 *
 * @param array      $steps  step_no => ['drop_rate' => float, ...]
 * @param array      $trades step_no => ['price' => float, ...]
 * @param array      $p      pf_params() 결과 (tick 만 사용)
 * @param float|null $anchor 1차 미체결(관심종목)일 때 1차 기준가로 쓸 값. 보통 현재가.
 * @return array step_no => ['step_no','base_price','base_from','theory_price']
 *               base_from: actual|theory|anchor|null
 */
function pf_theory_ladder(array $steps, array $trades, array $p = [], ?float $anchor = null): array
{
    $p    = pf_params($p);
    $tick = $p['tick'];

    $stepNos = array_keys($steps);
    sort($stepNos, SORT_NUMERIC);

    $ladder     = [];
    $prevNo     = null;
    $prevTheory = null;

    foreach ($stepNos as $n) {
        if ($prevNo === null) {
            // 1차: 실매수가가 있으면 그것이 사다리의 원점, 없으면 anchor(현재가)
            if (isset($trades[$n]['price'])) {
                $theory = (float)$trades[$n]['price'];
                $from   = 'actual';
                $base   = $theory;
            } elseif ($anchor !== null) {
                $theory = pf_floor_tick((float)$anchor, $tick);
                $from   = 'anchor';
                $base   = (float)$anchor;
            } else {
                $theory = null;
                $from   = null;
                $base   = null;
            }
        } elseif ($prevTheory === null) {
            $theory = null;
            $from   = null;
            $base   = null;
        } else {
            $prevActual = isset($trades[$prevNo]['price']) ? (float)$trades[$prevNo]['price'] : null;

            if ($prevActual !== null && $prevActual < $prevTheory) {
                $base = $prevActual;
                $from = 'actual';
            } else {
                $base = $prevTheory;
                $from = 'theory';
            }

            $theory = pf_floor_tick($base * (1 + (float)$steps[$n]['drop_rate']), $tick);
        }

        $ladder[$n] = [
            'step_no'      => $n,
            'base_price'   => $base,
            'base_from'    => $from,
            'theory_price' => $theory,
        ];

        $prevNo     = $n;
        $prevTheory = $theory;
    }

    return $ladder;
}

// ── §2.3 차수별 금액 / 이론수량 ───────────────────────────────────────
/** 차수 투입금액 = 투자한도 × 비중 */
function pf_step_amount(float $limitAmt, float $weight): float
{
    return $limitAmt * $weight;
}

/** 이론수량 = FLOOR(차수금액 ÷ 이론가). 0주면 한도부족. */
function pf_step_qty(float $stepAmt, ?float $price): int
{
    if ($price === null || $price <= 0) return 0;
    return (int) floor(round($stepAmt / $price, 6));
}

// ── §2.4 누적 단가 ────────────────────────────────────────────────────
/**
 * 누적단가 = (Σ 매수금액) × (1 + buy_cost_rate) ÷ (Σ 매수수량)
 * 매수 체결분만 넣는다.
 */
function pf_avg_cost(array $trades, array $p = []): ?float
{
    $p = pf_params($p);

    $cost = 0.0;   // 매수금액 + 수수료
    $qty  = 0;
    foreach ($trades as $t) {
        $amt   = (float)$t['price'] * (int)$t['qty'];
        $cost += $amt + pf_buy_cost($amt, $p);   // 건별 수수료 (정률이면 결과가 종전과 동일)
        $qty  += (int)$t['qty'];
    }
    if ($qty <= 0) return null;

    return $cost / $qty;
}

// ── §2.5 자동매도가 ───────────────────────────────────────────────────
/**
 * 자동매도가 = CEIL( 누적단가 × (1 + 목표수익률) ÷ (1 - sell_cost_rate) / tick ) × tick
 * 목표수익률은 "현재 도달한 차수"의 값을 쓴다.
 */
function pf_auto_sell_price(?float $avgCost, ?float $targetRate, array $p = [], int $qty = 0): ?float
{
    if ($avgCost === null || $targetRate === null) return null;
    $p = pf_params($p);

    // 구간 수수료면 비용률이 거래금액에 따라 달라진다. 예상 매도대금으로 실효율을 구해 쓴다.
    $rate = (float)$p['sell_cost_rate'];
    if (!empty($p['fee_tiers']) && $qty > 0) {
        $gross = $avgCost * (1 + $targetRate) * $qty;
        $rate  = pf_sell_cost_rate($gross, $p);
    }

    return pf_ceil_tick($avgCost * (1 + $targetRate) / (1 - $rate), $p['tick']);
}

// ── §2.6 룰셋 시뮬레이션 ──────────────────────────────────────────────
/**
 * "계획대로 전부 하락한다"고 가정했을 때 차수별 손익분기율을 미리 계산한다.
 * 1차 가격을 1.0 으로 정규화하고 단계하락률을 곱해 내려간다. (누적하락률 아님 — 요건정의서 핵심 발견)
 *
 * @return array step_no => ['step_no','weight','drop_rate','target_rate',
 *                           'price_factor','amount','cum_amount','cum_qty',
 *                           'avg_factor','breakeven_rate','limit_used_rate']
 */
function pf_simulate(array $steps, float $limitAmt, array $p = []): array
{
    $stepNos = array_keys($steps);
    sort($stepNos, SORT_NUMERIC);

    $priceFactor = 1.0;
    $totalQty    = 0.0;
    $totalAmt    = 0.0;
    $first       = true;
    $out         = [];

    foreach ($stepNos as $n) {
        $s = $steps[$n];

        if (!$first) $priceFactor *= (1 + (float)$s['drop_rate']);
        $first = false;

        $amt = pf_step_amount($limitAmt, (float)$s['weight']);
        $totalAmt += $amt;
        $totalQty += ($priceFactor > 0) ? $amt / $priceFactor : 0;

        $avg = ($totalQty > 0) ? $totalAmt / $totalQty : null;

        $out[$n] = [
            'step_no'         => $n,
            'weight'          => (float)$s['weight'],
            'drop_rate'       => (float)$s['drop_rate'],
            'target_rate'     => isset($s['target_rate']) ? (float)$s['target_rate'] : null,
            'price_factor'    => $priceFactor,
            'amount'          => $amt,
            'cum_amount'      => $totalAmt,
            'cum_qty'         => $totalQty,
            'avg_factor'      => $avg,
            'breakeven_rate'  => ($avg > 0) ? $priceFactor / $avg - 1 : null,
            'limit_used_rate' => ($limitAmt > 0) ? $totalAmt / $limitAmt : null,
        ];
    }

    return $out;
}

// ── §2.7 룰셋 자동 생성 ────────────────────────────────────────────────
//
//  「7단계 · 목표수익률 최초가 +15%~−10% · 손익분기 최대 −35%」 처럼
//  <b>원하는 결과</b>를 주면 차수표(비중·하락률·목표수익률)를 만들어 낸다.
//
//  ★★★ 핵심 — <b>비중은 풀 수 있다</b>(탐색이 아니라 수식이다).
//    평균단가 a_n = C_n / Q_n   (C=누적 투입액, Q=누적 수량, 한도 1 기준)
//    손익분기 b_n = p_n / a_n − 1  ⟹  a_n = p_n / (1+b_n)
//    두 식을 합쳐 w_n 만 남기면
//        w_n = [ p_n·Q_{n−1} − C_{n−1}·(1+b_n) ] / b_n
//    즉 <b>가격 경로(하락률)와 손익분기 곡선을 주면 비중이 결정된다.</b>
//    a_n 은 w 의 스케일에 무관하므로 w_1 = 1 로 시작해 마지막에 합이 1 이 되게 정규화하면 된다.
//    ★검증: rs4 의 하락률 + rs4 의 실제 손익분기를 넣으면 rs4 의 비중(5/5/8/14/17/23/28)이 그대로 나온다.
//
//  ★ <b>하락률은 3개 입력으로 결정되지 않는다.</b> 같은 손익분기 곡선을 만드는
//    (하락률, 비중) 조합이 무한히 많다 — 이번 세션의 노출중립 실험에서 본 자유도와 같은 이야기다.
//    그래서 하락률은 「첫 간격 + 차수마다 늘리는 폭」 두 값으로 받고 기본값을 준다.
//
//  ★ 목표수익률은 <b>직접 정하는 값이 아니다</b>. 자동매도가 = 평균단가 × (1+목표)이므로
//        목표수익률 = 탈출가 ÷ 평균단가 − 1
//    탈출가를 「최초 진입가 대비」로 흐르게 두면(예 +15% → −10%) 평균단가가 내려가는 만큼
//    목표수익률은 <b>저절로 커진다</b>. 깊은 차수에서 목표가 높아 보이는 것은 그 때문이다.

/**
 * 손익분기 목표 곡선 — 1차 0% 에서 마지막 $beLast 까지.
 *
 * ★ 곡률 $k: 1 이면 등차, 1 보다 크면 <b>앞이 완만하고 뒤가 급하다</b>.
 *   현재 rs4 를 되짚으면 k ≈ 1.4 다(4차에서 최종의 37% 지점) — 그래서 기본값을 1.4 로 둔다.
 */
function pf_be_curve(int $n, float $beLast, float $k = 1.4): array
{
    $out = [1 => 0.0];
    for ($i = 2; $i <= $n; $i++) {
        $x = ($i - 1) / ($n - 1);
        $out[$i] = $beLast * ($x ** $k);
    }
    return $out;
}

/**
 * 가격 경로 + 손익분기 곡선 → 차수별 비중 (합 1 로 정규화).
 *
 * @param array $p 차수 => 1차 대비 가격 배수 (1차 = 1.0)
 * @param array $b 차수 => 손익분기율 (1차 = 0, 이후 음수)
 * @return array  차수 => 비중. 풀 수 없으면(비중이 0 이하로 나오면) 빈 배열
 */
function pf_weights_from_be(array $p, array $b): array
{
    $w = [1 => 1.0];
    $C = 1.0;                      // 누적 투입액
    $Q = 1.0 / $p[1];              // 누적 수량

    $ns = array_keys($p);
    sort($ns, SORT_NUMERIC);
    foreach ($ns as $n) {
        if ($n === 1) continue;
        if (($b[$n] ?? 0.0) >= 0.0) return [];       // 손익분기가 음수가 아니면 풀리지 않는다

        $wn = ($p[$n] * $Q - $C * (1 + $b[$n])) / $b[$n];
        if (!is_finite($wn) || $wn <= 0) return [];  // 그 곡선은 이 가격 경로로 만들 수 없다

        $w[$n] = $wn;
        $C    += $wn;
        $Q    += $wn / $p[$n];
    }

    $sum = array_sum($w);
    foreach ($w as $n => $v) $w[$n] = $v / $sum;
    return $w;
}

/**
 * 룰셋 자동 생성.
 *
 * @param array $o  n(단계수) · be_last(최종 손익분기율, 음수) · be_k(곡률)
 *                  drop_first(2차 하락률, 음수) · drop_step(차수마다 더 깊어지는 폭, 음수)
 *                  exit_first(1차 탈출가 — 최초가 대비, 예 0.15) · exit_last(마지막 탈출가, 예 −0.10)
 * @return array ['ok','steps','rows','error'] — rows 는 차수별 진단(가격·평균단가·손익분기·탈출가)
 */
/* ── 룰셋 자동 생성의 <b>기본값과 물리적 한계</b> (M5-2차 — 이름 붙이기) ──
 *
 * ★기본값은 <b>판정 임계가 아니라 폼의 출발점</b>이다 — 사용자가 화면에서 덮어쓴다.
 *   그래서 레지스트리(Thr)에 올리지 않는다. 다만 「왜 이 숫자로 출발하는가」는 남길 값이 있다.
 * ★clamp 는 <b>말이 되는 범위</b>다 — 하락률 0% 나 −95% 같은 입력이 들어오면 식이 뜻을 잃는다. */
const PF_GEN_N_DEF        = 7;       // 기본 단계 수 (운영 룰셋 rs4 와 같다)
const PF_GEN_N_MIN        = 2;
const PF_GEN_N_MAX        = 12;
const PF_GEN_BE_LAST_DEF  = -0.35;   // 최종 손익분기 기본 −35% (rs4 역산값 −35.31% 에서)
const PF_GEN_BE_K_DEF     = 1.4;     // 곡률 기본 — rs4 를 되짚으면 ≈1.4 (4차가 최종의 37% 지점)
const PF_GEN_BE_K_MIN     = 0.5;
const PF_GEN_BE_K_MAX     = 3.0;
const PF_GEN_DROP1_DEF    = -0.09;   // 2차 하락률 기본 −9% (rs4 와 같다)
const PF_GEN_DROP_STEP_DEF = -0.042; // 차수마다 더 깊어지는 폭 −4.2%p
const PF_GEN_DROP_MIN     = -0.9;    // clamp — 이보다 깊으면 가격이 사실상 0 이 된다
const PF_GEN_DROP_MAX     = -0.001;  // clamp — 0 이면 「내려가지 않는 사다리」라 식이 안 풀린다
const PF_GEN_EXIT1_DEF    = 0.15;    // 1차 탈출가 최초가 +15%
const PF_GEN_EXITN_DEF    = -0.10;   // 마지막 탈출가 최초가 −10% (깊어지면 손실 감수)
/** 통과 곡률 범위 탐색 — pf_rule_gen_k_range 가 훑는 구간 (사람이 손으로는 못 찾는다) */
const PF_GEN_KR_LO   = 0.8;
const PF_GEN_KR_HI   = 2.0;
const PF_GEN_KR_STEP = 0.05;

function pf_rule_gen(array $o): array
{
    $n  = max(PF_GEN_N_MIN, min(PF_GEN_N_MAX, (int)($o['n'] ?? PF_GEN_N_DEF)));
    $beLast = (float)($o['be_last'] ?? PF_GEN_BE_LAST_DEF);
    $beK    = max(PF_GEN_BE_K_MIN, min(PF_GEN_BE_K_MAX, (float)($o['be_k'] ?? PF_GEN_BE_K_DEF)));
    $d1     = (float)($o['drop_first'] ?? PF_GEN_DROP1_DEF);
    $dStep  = (float)($o['drop_step']  ?? PF_GEN_DROP_STEP_DEF);
    $eFirst = (float)($o['exit_first'] ?? PF_GEN_EXIT1_DEF);
    $eLast  = (float)($o['exit_last']  ?? PF_GEN_EXITN_DEF);

    if ($beLast >= 0) return ['ok' => false, 'error' => '최종 손익분기율은 음수여야 합니다.', 'steps' => [], 'rows' => []];
    if ($d1 >= 0)     return ['ok' => false, 'error' => '2차 하락률은 음수여야 합니다.',     'steps' => [], 'rows' => []];

    // 가격 경로 — 하락률은 등차(차수마다 dStep 만큼 더 깊어진다)
    $drop = [1 => 0.0];
    $p    = [1 => 1.0];
    for ($i = 2; $i <= $n; $i++) {
        $d = $d1 + $dStep * ($i - 2);
        $d = max(PF_GEN_DROP_MIN, min(PF_GEN_DROP_MAX, $d));           // 물리적으로 말이 되는 범위로 묶는다
        $drop[$i] = $d;
        $p[$i]    = $p[$i - 1] * (1 + $d);
    }

    $b = pf_be_curve($n, $beLast, $beK);
    $w = pf_weights_from_be($p, $b);
    if (!$w) {
        return ['ok' => false, 'steps' => [], 'rows' => [],
                'error' => '이 조합으로는 비중이 풀리지 않습니다 — 손익분기를 더 깊게 하거나 하락률을 더 얕게 해 보세요.'];
    }

    /* 목표수익률 = 탈출가 ÷ 평균단가 − 1.
     * 탈출가는 최초가 대비 eFirst → eLast 로 선형 글라이드한다. */
    $steps = [];
    $rows  = [];
    $C = 0.0; $Q = 0.0;
    for ($i = 1; $i <= $n; $i++) {
        $C += $w[$i];
        $Q += $w[$i] / $p[$i];
        $avg = ($Q > 0) ? $C / $Q : null;

        $e = 1 + $eFirst + (($eLast - $eFirst) * (($n > 1) ? ($i - 1) / ($n - 1) : 0));
        $t = ($avg > 0) ? $e / $avg - 1 : 0.0;

        $steps[$i] = ['weight' => $w[$i], 'drop_rate' => $drop[$i], 'target_rate' => $t];
        $rows[$i]  = [
            'step_no'   => $i,
            'weight'    => $w[$i],
            'drop_rate' => $drop[$i],
            'price'     => $p[$i],            // 1차 대비 가격
            'avg'       => $avg,              // 1차 대비 평균단가
            'breakeven' => ($avg > 0) ? $p[$i] / $avg - 1 : null,
            'exit'      => $e,                // 1차 대비 탈출가
            'target'    => $t,
            'cum_w'     => $C,
        ];
    }
    return ['ok' => true, 'steps' => $steps, 'rows' => $rows, 'error' => '',
            'warn' => pf_rule_gen_warn($rows, (float)($o['limit_amt'] ?? 0), (float)($o['top_price'] ?? 0))];
}

/**
 * 생성한 룰셋의 <b>스스로 알아야 할 문제</b>를 짚는다.
 *
 * 수식이 풀렸다는 것과 쓸 만하다는 것은 다른 이야기다 — 실제로 이 세 가지가 나왔다:
 *   · 1차 비중 2.53% → 한도 3천만이면 75만원이라 <b>고가주는 1주도 못 산다</b>(방금 고친 문제의 재발)
 *   · 5단계로 만들면 비중이 30% → 12% 로 <b>거꾸로</b> 흐른다 — 물타기 형태가 아니다
 *   · 마지막 목표수익률 120% → 평균단가에서 <b>두 배</b> 오를 때까지 안 팔겠다는 뜻
 *
 * @param float $limit 투자한도(원). 0 이면 금액 판정을 건너뛴다
 * @param float $top   1주 값이 가장 비싼 종목의 현재가. 0 이면 건너뛴다
 */
function pf_rule_gen_warn(array $rows, float $limit = 0, float $top = 0): array
{
    $w = [];
    if (!$rows) return $w;

    $first = reset($rows);
    $last  = end($rows);

    // ① 비중이 뒤로 갈수록 줄면 물타기가 아니다
    $prev = null;
    foreach ($rows as $r) {
        if ($prev !== null && $r['weight'] < $prev - 1e-9) {
            $w[] = '비중이 뒤 차수에서 <b>줄어듭니다</b> — 물타기 형태가 아닙니다. '
                 . '단계 수를 늘리거나 최종 손익분기를 얕게 해 보세요.';
            break;
        }
        $prev = $r['weight'];
    }

    // ② 1차 비중이 작으면 고가주가 1주도 못 들어간다 (한도·주가를 주면 금액으로 짚는다)
    if ($limit > 0 && $top > 0) {
        $amt = $limit * $first['weight'];
        if ($amt < $top) {
            $w[] = '1차 금액이 ' . number_format($amt) . '원인데 가장 비싼 종목이 '
                 . number_format($top) . '원입니다 — <b>1차에 1주도 못 담습니다</b>. '
                 . '손익분기 곡률을 낮추면(1.0 쪽) 1차 비중이 커집니다.';
        }
    } elseif ($first['weight'] < 0.04) {
        $w[] = '1차 비중이 ' . number_format($first['weight'] * 100, 2)
             . '% 로 작습니다 — 고가주는 1차에 1주도 못 담을 수 있습니다(곡률을 낮추면 커집니다).';
    }

    // ③ 마지막 목표수익률이 너무 높으면 사실상 탈출 불가
    if ($last['target'] > 1.0) {
        $w[] = '마지막 목표수익률이 ' . number_format($last['target'] * 100, 0)
             . '% 입니다 — 평균단가에서 두 배 넘게 올라야 팔립니다. '
             . '마지막 탈출가를 낮추면(−25% 쪽) 내려갑니다.';
    }

    // ④ 사다리가 너무 깊으면 뒤 차수는 실전에서 안 쓰인다 (실측: 6·7차 체결 0)
    if ($last['price'] < 0.30) {
        $w[] = '마지막 차수가 최초가의 ' . number_format(($last['price'] - 1) * 100, 1)
             . '% 지점입니다 — 실전에서 그만큼 빠지는 일은 드물어 뒤 차수가 <b>안 쓰일 수</b> 있습니다.';
    }
    return $w;
}

/**
 * 자동 생성 폼의 입력값 — 화면에 보이는 <b>퍼센트 그대로</b> 담는다(비율 변환은 pf_gen_opt 가 한다).
 *
 * ★ `$_REQUEST` 를 직접 읽지 않고 <b>인자로 받는다</b> — 이 파일은 요청·DB·출력에 의존하지 않는다는
 *   규칙을 지키면서, 미리보기(index.php·GET)와 저장(api.php·POST)이 <b>같은 파싱</b>을 쓰게 하려는 것이다.
 *   ⚠처음에 index.php 안에 두었더니 api.php 가 그 파일을 include 하지 않아
 *   저장이 "Call to undefined function" 으로 죽었다. 두 곳이 함께 쓰는 것은 여기 둔다.
 */
function pf_gen_input(array $src): array
{
    $num = function (string $k, float $def) use ($src): float {
        $v = $src[$k] ?? '';
        return ($v === '' || !is_numeric($v)) ? $def : (float)$v;
    };
    return [
        'n'  => max(2, min(12, (int)$num('g_n', 7))),
        'ef' => $num('g_ef',  15.0),    // 1차 탈출가 (최초가 대비 %)
        'el' => $num('g_el', -10.0),    // 마지막 탈출가
        'be' => $num('g_be', -35.0),    // 최종 손익분기율
        'k'  => $num('g_k',    1.4),    // 손익분기 곡률
        'd1' => $num('g_d1',  -9.0),    // 2차 하락률
        'ds' => $num('g_ds',  -4.2),    // 차수마다 더 깊어지는 폭(%p)
    ];
}

/** 폼 입력(%) → pf_rule_gen 이 받는 비율. 경고용 한도·주가도 함께 실어 준다 */
function pf_gen_opt(array $in, array $tight = []): array
{
    return [
        'n'          => (int)$in['n'],
        'be_last'    => $in['be'] / 100,
        'be_k'       => $in['k'],
        'drop_first' => $in['d1'] / 100,
        'drop_step'  => $in['ds'] / 100,
        'exit_first' => $in['ef'] / 100,
        'exit_last'  => $in['el'] / 100,
        'limit_amt'  => (float)($tight['limit'] ?? 0),
        'top_price'  => (float)($tight['price'] ?? 0),
    ];
}

/**
 * 「비중 역전」과 「1차에 1주 불가」를 <b>모두 피하는 곡률 범위</b>를 찾아 준다.
 *
 * 곡률은 1차 비중을 좌우하는 손잡이인데 방향이 서로 반대인 두 벽 사이에 끼어 있다:
 *   곡률 ↓ → 1차 비중 ↑ (고가주 1주 문제는 풀리지만) 어느 지점부터 <b>1차 > 2차 역전</b>
 *   곡률 ↑ → 매끄러워지지만 1차 비중이 작아져 <b>고가주는 1주도 못 담는다</b>
 * 실측(7단계·−35%·한도 3천만·하이닉스 132만)에서는 <b>1.15~1.20</b> 만 둘 다 통과했다.
 * 손으로 찾게 두면 못 찾는다 — 그래서 훑어서 알려 준다.
 *
 * ★ 「사다리가 깊다」 경고는 곡률과 무관(하락률이 정한다)하므로 여기서는 보지 않는다.
 * @return array ['from'=>float|null, 'to'=>float|null]
 */
function pf_rule_gen_k_range(array $o, float $lo = PF_GEN_KR_LO, float $hi = PF_GEN_KR_HI, float $step = PF_GEN_KR_STEP): array
{
    $limit = (float)($o['limit_amt'] ?? 0);
    $top   = (float)($o['top_price'] ?? 0);
    $from  = $to = null;

    for ($k = $lo; $k <= $hi + 1e-9; $k += $step) {
        $g = pf_rule_gen(array_merge($o, ['be_k' => round($k, 4)]));
        if (empty($g['ok'])) continue;

        // ① 비중이 뒤로 갈수록 줄지 않아야 한다
        $ok = true;
        $prev = null;
        foreach ($g['rows'] as $r) {
            if ($prev !== null && $r['weight'] < $prev - 1e-9) { $ok = false; break; }
            $prev = $r['weight'];
        }
        // ② 1차에 최소 1주가 들어가야 한다 (한도·주가를 준 경우만)
        if ($ok && $limit > 0 && $top > 0) {
            $ok = ($limit * reset($g['rows'])['weight'] >= $top);
        }
        if (!$ok) continue;

        if ($from === null) $from = round($k, 2);
        $to = round($k, 2);
    }
    return ['from' => $from, 'to' => $to];
}

/**
 * 저장된 차수표에서 <b>룰셋의 조건값을 역산</b>한다 (자동 생성 화면의 입력값과 같은 것들).
 *
 * 왜 메모를 파싱하지 않나: 메모는 생성 당시의 <b>기록</b>이라 차수를 손으로 고치면 곧 거짓이 된다.
 * 차수에서 역산하면 <b>언제나 지금의 사실</b>이고, 사람이 손으로 만든 룰셋에도 그대로 쓸 수 있다.
 *
 * ★ 곡률(be_k)은 `b_i = b_last × x_i^k` 를 뒤집어 x=0,1 을 뺀 차수마다 k 를 구해 평균한다.
 *   한 점(가운데)만 쓰면 그 점의 반올림에 흔들린다.
 * ★ 스케일 무관 — pf_simulate 는 금액을 반올림하지 않으므로 한도 값이 결과를 바꾸지 않는다.
 */
function pf_rule_conditions(array $steps): array
{
    if (count($steps) < 2) return [];

    $sim = pf_simulate($steps, 1.0);
    $ns  = array_keys($sim);
    sort($ns, SORT_NUMERIC);
    $n = count($ns);

    $first = $sim[$ns[0]];
    $last  = $sim[$ns[$n - 1]];
    $d2    = (float)$sim[$ns[1]]['drop_rate'];
    $dN    = (float)$last['drop_rate'];

    $out = [
        'n'          => $n,
        'weight_sum' => pf_weight_sum($steps),
        'drop_first' => $d2,                                              // 2차 하락률
        'drop_step'  => ($n >= 3) ? ($dN - $d2) / ($n - 2) : null,        // 차수마다 더 깊어지는 폭
        'be_last'    => (float)$last['breakeven_rate'],
        'exit_first' => (float)$first['avg_factor'] * (1 + (float)$first['target_rate']) - 1,
        'exit_last'  => (float)$last['avg_factor']  * (1 + (float)$last['target_rate'])  - 1,
        'depth'      => (float)$last['price_factor'] - 1,
        'be_k'       => null,
    ];

    $ks = [];
    for ($i = 1; $i < $n - 1; $i++) {
        $b = (float)$sim[$ns[$i]]['breakeven_rate'];
        $x = $i / ($n - 1);
        if ($b >= 0 || $out['be_last'] >= 0) continue;
        $r = $b / $out['be_last'];
        if ($r <= 0 || $x <= 0 || $x >= 1) continue;
        $ks[] = log($r) / log($x);
    }
    if ($ks) $out['be_k'] = array_sum($ks) / count($ks);

    return $out;
}

/** 룰셋 비중 합계 (100% 미달/초과 경고용). */
function pf_weight_sum(array $steps): float
{
    $sum = 0.0;
    foreach ($steps as $s) $sum += (float)$s['weight'];
    return $sum;
}

// ── DB 매매행 → 계산용 형태 ──────────────────────────────────────────
/**
 * pf_trade 행 목록을 pf_position_calc 가 받는 step_no 키 배열로 바꾼다.
 * 같은 차수를 나눠서 체결했으면 수량가중평균 단가로 합친다. 매도(side=sell)와 step_no=0 은 제외.
 */
function pf_trades_by_step(array $rows): array
{
    $acc = [];
    foreach ($rows as $r) {
        if (($r['side'] ?? 'buy') !== 'buy') continue;
        $n   = (int)($r['step_no'] ?? 0);
        $qty = (int)($r['qty'] ?? 0);
        if ($n <= 0 || $qty <= 0) continue;

        if (!isset($acc[$n])) $acc[$n] = ['qty' => 0, 'amt' => 0.0, 'traded_at' => $r['traded_at'] ?? null];
        $acc[$n]['qty'] += $qty;
        $acc[$n]['amt'] += (float)$r['price'] * $qty;

        $d = $r['traded_at'] ?? null;
        if ($d !== null && ($acc[$n]['traded_at'] === null || $d < $acc[$n]['traded_at'])) $acc[$n]['traded_at'] = $d;
    }

    $out = [];
    foreach ($acc as $n => $a) {
        $out[$n] = ['price' => $a['amt'] / $a['qty'], 'qty' => $a['qty'], 'traded_at' => $a['traded_at']];
    }
    ksort($out);
    return $out;
}

// ── 매도 원장 (이동평균법) ────────────────────────────────────────────
/**
 * 매수·매도를 시간순으로 훑어 보유수량·누적단가·실현손익을 만든다.
 *
 * 이동평균법: 매도는 평균단가를 바꾸지 않는다. 평균단가로 원가를 덜어내기만 하므로
 * 매도 후에도 누적단가·차수·다음매수가가 그대로 유지된다.
 *   실현손익 = 매도가 × 수량 × (1 - sell_cost_rate) - 누적단가 × 수량
 *   (누적단가에 매수비용이 이미 들어 있어 매수·매도 비용이 양쪽 다 반영된다)
 *
 * @param array $rows pf_trade 행 목록 (side/traded_at/price/qty)
 * @return array held_qty·avg_cost·realized_pl·buy_qty·sell_qty·sell_amount·cost_amount
 */
function pf_ledger(array $rows, array $p = []): array
{
    $p = pf_params($p);

    usort($rows, fn($a, $b) =>
        [$a['traded_at'] ?? '', (int)($a['id'] ?? 0)] <=> [$b['traded_at'] ?? '', (int)($b['id'] ?? 0)]);

    $qty       = 0;      // 보유수량
    $costTotal = 0.0;    // 보유분 원가 (매수금액 + 매수수수료)
    $avg       = null;   // 누적단가
    $realized  = 0.0;
    $buyQty    = 0;
    $buyAmt    = 0.0;    // 누적 매수대금 (수수료 제외)
    $buyFee    = 0.0;
    $sellQty   = 0;
    $sellAmt   = 0.0;    // 누적 매도 수취액 (비용 차감 후)
    $sellFee   = 0.0;

    foreach ($rows as $r) {
        $q     = (int)($r['qty'] ?? 0);
        $price = (float)($r['price'] ?? 0);
        if ($q <= 0 || $price <= 0) continue;

        if (($r['side'] ?? 'buy') === 'buy') {
            $amt        = $price * $q;
            $fee        = pf_buy_cost($amt, $p);
            $qty       += $q;
            $costTotal += $amt + $fee;
            $buyQty    += $q;
            $buyAmt    += $amt;
            $buyFee    += $fee;
            $avg        = $costTotal / $qty;
            continue;
        }

        // 매도 — 보유분을 넘겨 팔 수는 없다
        if ($qty <= 0 || $avg === null) continue;
        $q = min($q, $qty);

        $gross     = $price * $q;
        $cost      = pf_sell_cost($gross, $p);      // 위탁수수료 + 증권거래세
        $proceeds  = $gross - $cost;
        $realized += $proceeds - $avg * $q;
        $sellQty  += $q;
        $sellAmt  += $proceeds;
        $sellFee  += $cost;

        $costTotal -= $avg * $q;                    // 이동평균법 — 평균단가로 원가 차감
        $qty       -= $q;

        if ($qty === 0) { $costTotal = 0.0; $avg = null; }   // 전량 매도 → 사이클 종료
    }

    return [
        'held_qty'    => $qty,
        'avg_cost'    => $avg,
        'buy_qty'     => $buyQty,
        'buy_amount'  => $buyAmt,             // 매수대금 누계 (수수료 제외)
        'buy_fee'     => $buyFee,
        'buy_cost'    => $buyAmt + $buyFee,   // 매수로 나간 현금
        'sell_qty'    => $sellQty,
        'sell_amount' => $sellAmt,            // 매도로 들어온 현금 (비용 차감 후)
        'sell_fee'    => $sellFee,
        'realized_pl' => $realized,
        'cost_amount' => ($avg !== null) ? $avg * $qty : 0.0,
        // 예수금 변동 = 매도 수취액 − 매수 지출액
        'cash_flow'   => $sellAmt - ($buyAmt + $buyFee),
    ];
}

/**
 * 보유분 현재가치 = 수량 × 현재가 − 매도비용
 * 지금 전량 매도하면 손에 들어올 금액. 추정자산 계산에 쓴다.
 */
function pf_net_value(int $qty, ?float $price, array $p): float
{
    if ($qty <= 0 || $price === null || $price <= 0) return 0.0;
    $gross = $price * $qty;
    return $gross - pf_sell_cost($gross, $p);
}

// ── 매도분 차수 안분 ──────────────────────────────────────────────────
/**
 * 매도 수량을 차수별 매수수량에 비례 배분해서 차수별 보유수량을 만든다.
 *
 *   차수 보유 = FLOOR( 매수수량 × (1 - 총매도 ÷ 총매수) )
 *   예) 1차 171주, 600주 중 100주 매도 → 171 - 171×(100/600) = 142.5 → 142
 *
 * 절사로 모자란 주수는 <b>최초 매수 차수</b>에 얹어 합계를 보유수량과 정확히 맞춘다.
 *   171/156/273 (600주) 에서 100주 매도 → 142/130/227 = 499 → 1차에 +1 → 143/130/227 = 500
 *
 * 비례 배분이라 평균단가는 이론상 그대로 보존된다 (정수 절사분만 미세 오차).
 *
 * @param array $stepQty step_no => 매수수량
 * @param int   $soldQty 총 매도수량
 * @return array step_no => 보유수량
 */
function pf_allocate_holdings(array $stepQty, int $soldQty): array
{
    ksort($stepQty, SORT_NUMERIC);

    $total = array_sum($stepQty);
    if ($total <= 0)        return $stepQty;
    if ($soldQty <= 0)      return $stepQty;
    if ($soldQty >= $total) return array_map(fn($q) => 0, $stepQty);

    $held = $total - $soldQty;
    $keep = 1 - $soldQty / $total;

    $out = [];
    foreach ($stepQty as $n => $q) {
        $out[$n] = (int) floor(round((int)$q * $keep, 6));
    }

    // 절사분을 최초 차수에 얹어 총량을 맞춘다
    $diff = $held - array_sum($out);
    if ($diff !== 0) {
        $firstNo = array_key_first($out);
        $out[$firstNo] = max(0, $out[$firstNo] + $diff);
    }

    return $out;
}

// ── 종합: 포지션 1건 전체 계산 ────────────────────────────────────────
/**
 * 대시보드/종목상세가 필요로 하는 값을 한 번에 만든다.
 * 저장하지 않는 파생값은 전부 여기서 나온다. (요건정의서 §3 "절대 컬럼으로 만들지 말 것")
 *
 * @param array      $steps     룰셋 차수
 * @param array      $trades    실체결 매수 step_no => ['price','qty','traded_at']
 * @param float      $limitAmt  투자한도
 * @param float|null $lastPrice 현재가
 * @param array      $p         파라미터 오버라이드
 * @param array      $ledger    pf_ledger() 결과. 주면 보유수량·누적단가·실현손익을 여기서 가져온다.
 *                              (매도가 없는 화면/테스트는 생략 가능 — 매수만으로 계산)
 * @return array
 */
function pf_position_calc(array $steps, array $trades, float $limitAmt, ?float $lastPrice = null, array $p = [], array $ledger = [], array $levels = []): array
{
    $p = pf_params($p);

    if ($levels !== []) {
        /* ── 퀀트 사다리 (2026-08-02) — 차수 가격이 <b>절대값</b>(그 종목의 실제 박스 지지선).
         * 룰셋의 하락률 체인 대신 편입 때 확정한 가격표(pf_position_level)가 사다리를 정의한다.
         * 비중·목표·지연도 레벨 행이 들고 온다(가격 간격이 종목마다 달라 비중도 그 가격으로 푼 값이다).
         * 이 치환 한 곳만 지나면 누적목표·catch-up·신호·지연·매도 전부 기존 체인 그대로다.
         * ★1차도 가격 조건을 갖는다(이론가 = 1차 지지선) — 하락률 모드의 「보유 0 이면 즉시 1차」와 달리
         *   지지까지 내려와야 신호가 선다(매복형). */
        ksort($levels, SORT_NUMERIC);
        $steps = []; $ladder = []; $prev = null;
        foreach ($levels as $n => $lv) {
            $px = (float)$lv['price'];
            $steps[$n] = [
                'step_no'     => $n,
                'weight'      => (float)$lv['weight'],
                'drop_rate'   => ($prev !== null && $prev > 0) ? $px / $prev - 1 : 0.0,   // 표시용 실효 간격
                'target_rate' => (float)$lv['target_rate'],
                'delay_days'  => (int)($lv['delay_days'] ?? 0),
            ];
            $ladder[$n] = ['base_price' => $px, 'base_from' => 'box', 'theory_price' => $px];
            $prev = $px;
        }
    } else {
        $ladder = pf_theory_ladder($steps, $trades, $p, $lastPrice);
    }

    $stepNos = array_keys($ladder);
    sort($stepNos, SORT_NUMERIC);

    $rows       = [];
    $curStep    = 0;     // 실제 도달한 최고 차수
    $filledQty  = 0;
    $filledAmt  = 0.0;

    foreach ($stepNos as $n) {
        $s      = $steps[$n];
        $theory = $ladder[$n]['theory_price'];
        $amt    = pf_step_amount($limitAmt, (float)$s['weight']);

        $tPrice = isset($trades[$n]['price']) ? (float)$trades[$n]['price'] : null;
        $tQty   = isset($trades[$n]['qty'])   ? (int)  $trades[$n]['qty']   : null;

        if ($tPrice !== null && $tQty !== null) {
            $curStep    = $n;
            $filledQty += $tQty;
            $filledAmt += $tPrice * $tQty;
        }

        $theoryQty = pf_step_qty($amt, $theory);
        $actualAmt = ($tPrice !== null) ? $tPrice * $tQty : null;

        $rows[$n] = [
            'step_no'       => $n,
            'weight'        => (float)$s['weight'],
            'drop_rate'     => (float)$s['drop_rate'],
            'target_rate'   => isset($s['target_rate']) ? (float)$s['target_rate'] : null,
            'delay_days'    => (int)($s['delay_days'] ?? 0),   // 지연 판정의 정본 — 박스 모드는 레벨 행 값
            'plan_amount'   => $amt,
            'base_price'    => $ladder[$n]['base_price'],
            'base_from'     => $ladder[$n]['base_from'],
            'theory_price'  => $theory,
            'theory_qty'    => $theoryQty,
            'traded'        => ($tPrice !== null),
            'traded_at'     => $trades[$n]['traded_at'] ?? null,
            'price'         => $tPrice,
            'qty'           => $tQty,
            'amount'        => $actualAmt,
            // 계획 대비 집행 — 금액 기준이 정확하다 (주수는 이론가/실매수가 차이가 섞임)
            'exec_rate'     => ($actualAmt !== null && $amt > 0) ? $actualAmt / $amt : null,
            'qty_diff'      => ($tQty !== null) ? $tQty - $theoryQty : null,
            'amt_diff'      => ($actualAmt !== null) ? $actualAmt - $amt : null,
            'weight_actual' => ($actualAmt !== null && $limitAmt > 0) ? $actualAmt / $limitAmt : null,
            'avg_cost_upto' => null,   // 아래에서 채움
        ];
    }

    // 차수별 누적단가 (해당 차수까지 체결분 기준)
    $accum = [];
    foreach ($stepNos as $n) {
        if ($rows[$n]['traded']) $accum[$n] = $trades[$n];
        $rows[$n]['avg_cost_upto'] = $accum ? pf_avg_cost($accum, $p) : null;
    }

    // 매도가 있으면 원장 값이 우선한다 (이동평균법 — 매도해도 누적단가·차수는 그대로)
    $hasLedger = ($ledger !== []);
    $avgCost   = $hasLedger ? $ledger['avg_cost'] : pf_avg_cost(array_intersect_key($trades, $rows), $p);
    $heldQty   = $hasLedger ? (int)$ledger['held_qty']    : $filledQty;
    $soldQty   = $hasLedger ? (int)$ledger['sell_qty']    : 0;
    $realized  = $hasLedger ? (float)$ledger['realized_pl'] : 0.0;

    // 매도분을 차수별로 안분해 차수별 보유수량·보유원가를 만든다
    $stepQty = [];
    foreach ($rows as $n => $r) {
        if ($r['traded']) $stepQty[$n] = (int)$r['qty'];
    }
    $alloc    = pf_allocate_holdings($stepQty, $soldQty);
    $heldCost = 0.0;   // 보유분 매수금액 합 (수수료 제외 — 계획금액과 같은 기준)

    foreach ($rows as $n => &$r) {
        $r['held_qty']    = $alloc[$n] ?? null;
        $r['held_amount'] = ($r['held_qty'] !== null && $r['price'] !== null)
            ? $r['price'] * $r['held_qty'] : null;
        $heldCost += (float)($r['held_amount'] ?? 0);

        // 매도가 있으면 계획대비를 "보유" 기준으로 다시 잡는다.
        // 판 물량은 한도를 더 이상 점유하지 않으므로 남은 물량으로 비교하는 게 맞다.
        if ($soldQty > 0 && $r['traded']) {
            $r['exec_rate']     = ($r['plan_amount'] > 0) ? $r['held_amount'] / $r['plan_amount'] : null;
            $r['qty_diff']      = (int)$r['held_qty'] - (int)$r['theory_qty'];
            $r['amt_diff']      = $r['held_amount'] - $r['plan_amount'];
            $r['weight_actual'] = ($limitAmt > 0) ? $r['held_amount'] / $limitAmt : null;
        }
    }
    unset($r);

    // 한도 점유액 — 매도가 있으면 보유분만 센다
    $usedAmt = ($soldQty > 0) ? $heldCost : $filledAmt;

    /*
     * ── 누적목표(catch-up) ───────────────────────────────────────────
     * 룰셋의 불변량은 차수별 금액이 아니라 "그 지점에서 한도의 몇 %가 들어가 있어야 하는가"다.
     *   누적목표(n) = 한도 × Σ비중(1..n)
     *   매수금액    = 누적목표(도달차수) − 현재 투입액
     * 이 한 줄로 미집행 잔액 이월·초과분 상계·차수 건너뛰기가 모두 처리된다.
     */
    $planCum = [];
    $accPlan = 0.0;
    foreach ($rows as $n => $r) {
        $accPlan    += $r['plan_amount'];
        $planCum[$n] = $accPlan;
    }

    // 차수별 누적목표 / 과부족 (그 차수까지 투입액 − 누적목표)
    $accUsed = 0.0;
    foreach ($rows as $n => &$r) {
        if ($n <= $curStep) {
            $accUsed += (float)(($soldQty > 0 ? $r['held_amount'] : $r['amount']) ?? 0);
        }
        $r['plan_cum'] = $planCum[$n];
        $r['gap']      = $accUsed - $planCum[$n];   // 음수 = 그 차수 도달 시 더 사야 할 금액
    }
    unset($r);

    $targetRate = ($curStep > 0 && isset($steps[$curStep]['target_rate']))
        ? (float)$steps[$curStep]['target_rate'] : null;

    // 다음 차수 = 도달 차수 + 1 (차수 건너뛰기 허용 — §4.4)
    $nextStep  = null;
    foreach ($stepNos as $n) {
        if ($n > $curStep) { $nextStep = $n; break; }
    }

    $nextPrice = ($nextStep !== null) ? $rows[$nextStep]['theory_price'] : null;

    // 다음 차수 계획 — 이월을 반영한 누적목표 기준
    $nextAmt = ($nextStep !== null) ? max(0.0, $planCum[$nextStep] - $usedAmt) : null;
    $nextQty = ($nextStep !== null && $nextPrice > 0)
        ? (int)floor(round($nextAmt / $nextPrice, 6)) : null;

    /*
     * 현재가로 실제 도달한 차수. 급락해서 여러 구간을 건너뛰었으면 그만큼 따라잡는다.
     * 수량은 이론가가 아니라 현재가로 나눈다 — 이론가는 "언제", 금액이 "얼마나"를 정한다.
     */
    $reachStep = $curStep;
    if ($lastPrice !== null) {
        foreach ($stepNos as $n) {
            if ($n <= $curStep) continue;
            $tp = $rows[$n]['theory_price'];
            if ($tp !== null && $lastPrice <= $tp) $reachStep = $n;
        }
    }
    $buyAmt = ($reachStep > $curStep) ? max(0.0, $planCum[$reachStep] - $usedAmt) : 0.0;
    $buyQty = ($buyAmt > 0 && $lastPrice > 0)
        ? (int)floor(round($buyAmt / $lastPrice, 6)) : 0;

    /*
     * ── 같은 차수 안에 남은 매수분 ──────────────────────────────────
     * buy_amount 는 <b>차수가 올라갈 때</b>(reachStep > curStep)만 잡힌다.
     * 그런데 그 차수를 이미 쳤어도 <b>누적목표를 덜 채웠고</b> 현재가가 아직 그 차수 이론가 이하면
     * 계획상 더 살 수 있다 — 실측(2026-07-29) 현대차우가 4차를 친 뒤에도
     * 179,200원에서 2,351,300원(13주)이 남아 있었다.
     *
     * ★ 조건에 <b>"현재가 ≤ 그 차수 이론가"</b> 가 반드시 들어가야 한다.
     *   금액만 보면 매일홀딩스도 905,140원(85주)이 남지만 현재가(10,580)가 2차 이론가(9,640)보다 <b>위</b>다 —
     *   지금 사면 계획보다 비싸게 담는 것이라 권해서는 안 된다.
     *
     * ★ buy_amount 를 고치지 않고 따로 둔 이유: 시뮬레이터(lib/sim.php)가 `buy_amount > 0` 을
     *   매수 트리거로 쓴다. 의미를 바꾸면 백테스트 결과가 통째로 달라진다.
     */
    $fillAmt = 0.0;
    if ($lastPrice !== null && $curStep > 0 && $reachStep === $curStep) {
        $curTp = $rows[$curStep]['theory_price'] ?? null;
        if ($curTp !== null && $lastPrice <= $curTp) {
            $fillAmt = max(0.0, $planCum[$curStep] - $usedAmt);
        }
    }
    $fillQty = ($fillAmt > 0 && $lastPrice > 0)
        ? (int)floor(round($fillAmt / $lastPrice, 6)) : 0;

    // 계획 대비 집행 현황 — 앞 차수를 과다 집행하면 뒷차수 총알이 모자란다
    $planUpto = $planRemain = 0.0;
    foreach ($rows as $n => $r) {
        if ($n <= $curStep) $planUpto   += $r['plan_amount'];
        else                $planRemain += $r['plan_amount'];
    }
    $limitRemain = $limitAmt - $usedAmt;

    $costAmt  = $hasLedger ? (float)$ledger['cost_amount'] : ($avgCost !== null ? $avgCost * $heldQty : 0.0);
    $evalAmt  = ($lastPrice !== null && $heldQty > 0) ? $lastPrice * $heldQty : null;
    $evalPl   = ($evalAmt !== null) ? $evalAmt - $costAmt : null;

    // 예수금·추정자산용 현금 흐름
    $cashFlow = $hasLedger
        ? (float)$ledger['cash_flow']
        : -($filledAmt + $filledAmt * $p['buy_cost_rate']);
    $netValue = pf_net_value($heldQty, $lastPrice, $p);
    $rate     = ($avgCost !== null && $lastPrice !== null) ? $lastPrice / $avgCost - 1 : null;
    $sellPrice = pf_auto_sell_price($avgCost, $targetRate, $p, $heldQty);

    return [
        'steps'          => $rows,
        'cur_step'       => $curStep,
        'next_step'      => $nextStep,
        'next_price'     => $nextPrice,
        'next_qty'       => $nextQty,      // 이론가 기준 (계획 표시용)
        'next_amount'    => $nextAmt,      // 누적목표 − 투입액 (이월 반영)
        // 현재가 기준 즉시 매수분
        'reach_step'     => $reachStep,    // 현재가로 도달한 차수
        'buy_amount'     => $buyAmt,       // 지금 사야 할 금액 (차수가 올라갔을 때)
        'buy_qty'        => $buyQty,       // 현재가로 나눈 수량
        // 같은 차수에 남은 매수분 — 그 차수를 이미 쳤지만 누적목표를 덜 채웠고 현재가가 아직 이론가 이하
        'fill_amount'    => $fillAmt,
        'fill_qty'       => $fillQty,
        'fill_signal'    => ($fillQty > 0),
        'plan_cum'       => $planCum,      // 차수별 누적목표
        'filled_qty'     => $heldQty,      // 보유수량 (매도 반영)
        'bought_qty'     => $filledQty,    // 총 매수수량
        'sold_qty'       => $soldQty,
        'filled_amount'  => $filledAmt,
        'cost_amount'    => $costAmt,
        'avg_cost'       => $avgCost,
        'target_rate'    => $targetRate,
        'sell_price'     => $sellPrice,
        'eval_amount'    => $evalAmt,
        'eval_pl'        => $evalPl,
        'realized_pl'    => $realized,
        'total_pl'       => ($evalPl ?? 0) + $realized,
        'cash_flow'      => $cashFlow,   // 예수금 증감 (매도 − 매수)
        'net_value'      => $netValue,   // 지금 전량 매도 시 수취액
        'closed_out'     => ($soldQty > 0 && $heldQty === 0),
        'rate'           => $rate,
        'buy_signal'     => ($lastPrice !== null && $nextPrice !== null && $lastPrice <= $nextPrice),
        'sell_signal'    => ($lastPrice !== null && $sellPrice !== null && $lastPrice >= $sellPrice),
        'limit_amt'      => $limitAmt,
        'limit_used'     => ($limitAmt > 0) ? $filledAmt / $limitAmt : null,
        // 계획 대비 집행
        'plan_upto'      => $planUpto,                                        // 도달 차수까지 계획금액
        'plan_remain'    => $planRemain,                                      // 남은 차수 계획금액
        'used_amount'    => $usedAmt,                                         // 한도 점유액 (매도분 제외)
        'held_cost'      => $heldCost,                                        // 보유분 매수금액 (수수료 제외)
        'exec_rate_upto' => ($planUpto > 0) ? $usedAmt / $planUpto : null,    // 계획대비 (1.0 = 계획대로)
        'limit_remain'   => $limitRemain,                                     // 남은 한도
        'plan_shortfall' => max(0.0, $planRemain - $limitRemain),             // 계획 이행에 모자란 금액
    ];
}

/**
 * 종료(청산)된 포지션의 <b>앞을 보는 값</b>을 지운다 — 확정된 돈만 남긴다.
 *
 * 왜 필요한가. 전량 매도하면 보유수량이 0 이라 계산엔진은 그 포지션을
 * "아직 아무것도 안 산 종목"으로 읽는다. 그래서 1차 이론가·다음매수가·매수수량을
 * 다시 만들어 낸다 — 계획이 아니라 <b>잔상</b>이다. 실제로 청산 종목의 상세 화면에
 * "1차 매수 구간입니다" 가 떴다.
 *
 * 그런데 <b>실현손익과 현금흐름은 확정된 사실</b>이다. 판 돈은 이미 계좌에 있다.
 * 그래서 예전에는 청산 포지션을 합계에서 통째로 빼 버렸는데, 그 바람에 예수금과
 * 실현손익이 실제와 어긋났다 — 실측(2026-07-30): 현금흐름 1,772,382원 차이,
 * 실현손익 −175,793 vs 실제 +1,596,589.
 *
 * ⇒ 돈은 남기고 계획만 지운다. 그러면 <b>합계는 항상 종료를 포함</b>해도 안전하고
 *   「종료 보기」 토글은 목록에 보일지 말지만 정하면 된다.
 *
 * ★ 기준은 <b>사용자가 지정한 status='closed'</b> 다. closed_out(전량매도 상태)으로 판정하면
 *   재진입을 기다리는 포지션(status 는 그대로 open)의 1차 매수 신호까지 함께 꺼진다 —
 *   그건 잔상이 아니라 살아 있는 계획이다.
 * ★ 남은 한도·남은 차수 계획(plan_remain 등)은 손대지 않는다. 그건 룰셋이 말하는 값이라
 *   포지션의 종료 여부와 무관하고, 어디에서도 합산되지 않는다.
 */
/**
 * 퀀트 사다리 비중 풀기 — 사용자가 고른 지지선 가격들(내림차순)에서 비중·목표·지연을 만든다.
 * ★이름은 「퀀트 사다리」(2026-08-03)이고 내부 식별자만 box 로 남았다 — DB 에 'box' 가 저장돼 있다.
 *
 * 비중은 자동생성기와 같은 수식(w_n = [p_n·Q_{n−1} − C_{n−1}(1+b_n)]/b_n)을 실제 가격 간격에
 * 적용해 푼다. 손익분기 곡선의 최종값은 깊이×비율(0.40~0.65), 곡률 k 는 1.0~1.8 을 전부 훑어
 * <b>계단형(내려갈수록 비중 비감소) 해를 먼저, 그 안에서 보수적 BE(비율 낮은 쪽)를 먼저</b> 고른다.
 * 예전에는 공격적 BE(0.65)부터 첫 해를 반환했는데, 가격 간격이 크게 불균등하면 해가
 * 「산봉우리형」(중간 차수에 절반·마지막 차수는 몇 %)으로 나와 지지가 다 깨졌을 때
 * 손실이 큰 쪽으로 쏠렸다 — 실사례 비중 20/21/48/11·최종 BE −45.8% 가 계단형 우선으로
 * 10/10/39/41·−28.2% 가 된다(같은 가격·같은 수식 — 고르는 기준만 바꿈).
 * 계단형 해가 아예 없으면(좁은 칸 섞임) 산봉우리형 중 보수 BE 를 쓴다 — 좁은 칸에
 * 비중이 얇게 붙는 것은 수식상 정상이고 미리보기 상세표로 드러난다.
 * 금지는 「1차 > 2차 역전」과 1차 5% 미만.
 * 목표수익률은 rs4 앞 구간(15/20/25/30/35 — 코호트 실측에서 낮은 목표는 사이클당 수익만 깎았다),
 * 지연은 0/0/20/20/20 (실측 중립~유리 구간·앞 두 차수는 박스 안 출렁임이라 지연 없음).
 *
 * @param array $prices 내림차순 절대가격 (3~5개)
 * @return ?array ['levels'=>[step=>['price','weight','target_rate','delay_days']], 'be'=>[], 'k','ratio','depth','mono'] · 해 없으면 null
 */
/* ── 퀀트 사다리 해 탐색 파라미터 (M5-2차 — 이름 붙이기) ──────────────────
 *
 * ★이것들은 <b>판정 임계가 아니라 알고리즘 내부값</b>이라 classes/Thr.class(레지스트리)에 올리지 않는다.
 *   레지스트리는 「화면이 사람에게 설명해야 하는 기준」을 담는 곳이고, 여기 값들은 <b>해를 어떻게 찾는가</b>다.
 *   섞으면 신호분석의 임계표가 읽을 수 없게 부푼다. 대신 이름과 근거를 여기 남긴다.
 * ★사본이 없다(이 함수에서만 쓴다) — 그래서 M5 1차의 「사본 제거」 대상이 아니었다. */
const PF_BOX_LV_MIN    = 3;      // 지지선 최소 개수 — 이보다 적으면 사다리가 아니다
const PF_BOX_LV_MAX    = 5;      // 최대 (더 늘리면 차수당 비중이 잡음 수준으로 얇아진다)
const PF_BOX_MIN_DEPTH = -0.005; // 1차→마지막 낙폭이 이보다 얕으면 간격이 없다시피 한 것
const PF_BOX_RATIO     = [0.40, 0.45, 0.50, 0.55, 0.60, 0.65];  // 최종 손익분기 = 깊이 × 이 비율 (보수 → 공격 순)
const PF_BOX_K_LO      = 100;    // 곡률 스캔 하한 ×100
const PF_BOX_K_HI      = 180;    // 상한 ×100
const PF_BOX_K_STEP    = 5;      // 간격 ×100
const PF_BOX_W_EPS     = 0.0005; // 비중 비교 허용오차 (반올림 잡음으로 「역전」 판정하지 않게)
const PF_BOX_W1_MIN    = 0.05;   // 1차 비중 하한 — 이보다 얇으면 고가주에서 1주도 못 산다

function pf_box_ladder_build(array $prices): ?array
{
    $prices = array_values(array_filter(array_map('floatval', $prices), fn($v) => $v > 0));
    rsort($prices);
    $N = count($prices);
    if ($N < PF_BOX_LV_MIN || $N > PF_BOX_LV_MAX) return null;

    $pF = [];
    foreach ($prices as $px) $pF[] = $px / $prices[0];
    $depth = end($pF) - 1.0;
    if ($depth >= PF_BOX_MIN_DEPTH) return null;   // 간격이 없다시피 하면 사다리가 아니다

    $TGT = [0.15, 0.20, 0.25, 0.30, 0.35];
    $DLY = [0, 0, 20, 20, 20];

    $best = null;   // [monoRank(0=계단형), ratio, k] 사전순 최소가 승자
    foreach (PF_BOX_RATIO as $ratio) {
        $beF = $depth * $ratio;
        for ($k = PF_BOX_K_LO; $k <= PF_BOX_K_HI; $k += PF_BOX_K_STEP) {
            $kk = $k / 100;
            $b = [0.0];
            for ($n = 1; $n < $N; $n++) $b[$n] = $beF * pow($n / ($N - 1), $kk);
            $w = [1.0]; $Q = 1.0; $C = 1.0;
            $ok = true;
            for ($n = 1; $n < $N; $n++) {
                $wn = ($pF[$n] * $Q - $C * (1 + $b[$n])) / $b[$n];
                if ($wn <= 0) { $ok = false; break; }
                $w[$n] = $wn; $Q += $wn / $pF[$n]; $C += $wn;
            }
            if (!$ok) continue;
            $sum = array_sum($w);
            $wN = array_map(fn($x) => $x / $sum, $w);
            if ($wN[1] < $wN[0] - PF_BOX_W_EPS || $wN[0] < PF_BOX_W1_MIN) continue;

            $mono = true;
            for ($n = 1; $n < $N; $n++) {
                if ($wN[$n] < $wN[$n - 1] - PF_BOX_W_EPS) { $mono = false; break; }
            }
            // 스캔이 (보수 BE → 공격 BE, 곡률 낮은 → 높은) 순이라
            // 첫 계단형 해 = 계단형 중 가장 보수적, 첫 유효 해 = 산봉우리 폴백 중 가장 보수적.
            $sol = ['w' => $wN, 'be' => $b, 'k' => $kk, 'ratio' => $ratio, 'mono' => $mono];
            if ($mono) { $best = $sol; break 2; }
            if ($best === null) $best = $sol;
        }
    }
    if ($best === null) return null;

    $levels = [];
    for ($n = 0; $n < $N; $n++) {
        $levels[$n + 1] = [
            'price'       => $prices[$n],
            'weight'      => round($best['w'][$n], 4),
            'target_rate' => $TGT[$n],
            'delay_days'  => $DLY[$n],
        ];
    }
    return ['levels' => $levels, 'be' => $best['be'], 'k' => $best['k'],
            'ratio' => $best['ratio'], 'depth' => $depth, 'mono' => $best['mono']];
}

/**
 * 퀀트 사다리 상세표 — 확정본(pf_position_level)이든 방금 푼 해든, levels 만으로
 * 룰셋 화면처럼 차수별 파생값을 만든다: 직전 대비 변동율·1차 대비·누적비중·
 * 그 차수까지 계획대로 샀을 때의 평균단가·그 가격에서의 평가손실률(=손익분기 도달거리)·탈출가.
 *
 * ★전부 비중·가격만의 함수라 저장 없이 재계산해도 항상 같다(파생값 무저장 원칙).
 *   평가손실률 be = p_n/평단 − 1 — 「그 지지선까지 내려와 다 샀을 때 계좌에 찍히는 수익률」.
 *   탈출가 = 평단 × (1+목표) — 그 차수에서 자동매도가 걸리는 자리(수수료 제외).
 *
 * @param array $levels [step=>['price','weight','target_rate','delay_days']] (step 1..N)
 * @return array [step=>['price','chg','from1','weight','cum','avg','be','target_rate','exit','delay_days']]
 */
function pf_box_ladder_detail(array $levels): array
{
    ksort($levels);
    $rows = [];
    $p1 = null; $prev = null; $cum = 0.0; $C = 0.0; $Q = 0.0;
    foreach ($levels as $step => $lv) {
        $px = (float)$lv['price'];
        $w  = (float)$lv['weight'];
        if ($p1 === null) $p1 = $px;
        $cum += $w;
        $C   += $w;
        $Q   += ($px > 0) ? $w / $px : 0.0;
        $avg  = ($Q > 0) ? $C / $Q : $px;
        $tgt  = (float)($lv['target_rate'] ?? 0);
        $rows[$step] = [
            'price'       => $px,
            'chg'         => ($prev !== null && $prev > 0) ? $px / $prev - 1 : null,
            'from1'       => ($p1 > 0) ? $px / $p1 - 1 : null,
            'weight'      => $w,
            'cum'         => $cum,
            'avg'         => $avg,
            'be'          => ($avg > 0) ? $px / $avg - 1 : null,
            'target_rate' => $tgt,
            'exit'        => $avg * (1 + $tgt),
            'delay_days'  => (int)($lv['delay_days'] ?? 0),
        ];
        $prev = $px;
    }
    return $rows;
}

/** 마지막 「매수」 체결일 — 차수 지연 판정의 기준점 (매도는 세지 않는다) */
function pf_last_buy_at(array $tradeRows): ?string
{
    $last = null;
    foreach ($tradeRows as $t) {
        if (($t['side'] ?? '') !== 'buy') continue;
        $d = substr((string)$t['traded_at'], 0, 10);
        if ($last === null || $d > $last) $last = $d;
    }
    return $last;
}

/**
 * 차수 지연을 <b>실전 신호</b>에 반영 — 시뮬 엔진(pf_sim_run)과 같은 규칙의 무상태 판정.
 *
 * 다음 차수(next_step)에 delay_days 가 정의돼 있고 직전 매수 후 그 일수를 넘겼으면
 * 그 차수는 「만료」 — 느린 한 차수 하락은 추세로 보고 쉬어간다:
 *   · 그 차수의 매수 신호를 끈다 (buy_signal/buy_amount/buy_qty)
 *   · 다음매수 계획(next_*)을 한 차수 아래로 옮긴다 (건너뛴 금액은 누적목표가 흡수 — 이월과 같은 식)
 *   · 현재가가 이미 그 아래 차수까지 내려와 있으면(reach_step > next) 손대지 않는다
 *     — 급락 예외이자 「스킵 후 다음 도달은 무조건 매수」(엔진과 동일)
 *   · 마지막 차수가 만료되면 다음매수 없음 (가장 깊은 구간 노출 축소)
 *
 * ★엔진과 달리 무상태다 — 「트리거 도달 시점의 경과일」이 아니라 「지금의 경과일」로 판정한다.
 *   빨리 내려와 놓고 안 산 채 시간이 지나면 실전은 만료로 읽는다(지금 판단으로는 그게 맞다).
 * ★1차 대기(직전 매수 없음)와 종료 포지션은 규칙 밖 — 여기·호출부가 함께 거른다.
 * ★적용 지점은 pf_calc_closed 와 같은 3곳(pf_load_calc·종목상세·api payload) —
 *   하나라도 빼먹으면 같은 종목이 화면마다 다른 판정을 한다.
 */
function pf_delay_adjust(?array $c, array $steps, ?string $lastBuyAt, string $today): ?array
{
    if ($c === null || $lastBuyAt === null) return $c;
    if ((int)($c['cur_step'] ?? 0) < 1) return $c;
    $n = (int)($c['next_step'] ?? 0);
    if ($n <= 0) return $c;
    // 정본은 calc 가 rows 에 실어 둔 값 — 퀀트 사다리(레벨별 지연)도 이 한 줄로 커버된다
    $dN = (int)($c['steps'][$n]['delay_days'] ?? ($steps[$n]['delay_days'] ?? 0));
    if ($dN <= 0) return $c;

    $elapsed = (int)floor((strtotime($today) - strtotime($lastBuyAt)) / 86400);
    if ($elapsed <= $dN) return $c;
    if ((int)($c['reach_step'] ?? 0) > $n) return $c;   // 이미 더 깊이 도달 — 무조건 매수 구간

    $c['delay_skip'] = ['step' => $n, 'elapsed' => $elapsed, 'limit' => $dN];
    $c['buy_signal'] = false;
    $c['buy_amount'] = 0.0;
    $c['buy_qty']    = 0;

    $m = null;
    foreach (array_keys($c['steps'] ?? []) as $k) if ($k > $n) { $m = $k; break; }
    if ($m === null) {
        $c['next_step'] = null; $c['next_price'] = null; $c['next_amount'] = null; $c['next_qty'] = null;
        return $c;
    }
    $c['next_step']   = $m;
    $c['next_price']  = $c['steps'][$m]['theory_price'] ?? null;
    $c['next_amount'] = isset($c['plan_cum'][$m])
        ? max(0.0, (float)$c['plan_cum'][$m] - (float)($c['used_amount'] ?? 0)) : null;
    $c['next_qty']    = ($c['next_price'] !== null && $c['next_price'] > 0 && $c['next_amount'] !== null)
        ? (int)floor(round($c['next_amount'] / $c['next_price'], 6)) : null;
    return $c;
}

function pf_calc_closed(?array $c): ?array
{
    if (!$c) return $c;

    // 다음 차수 — 계획 자체가 없다 (합계의 '다음 차수 소요'도 이 값을 센다)
    $c['next_step']   = null;
    $c['next_price']  = null;
    $c['next_qty']    = null;
    $c['next_amount'] = null;
    // 지금 살 것 / 그 차수에 남은 몫
    $c['reach_step']  = $c['cur_step'];
    $c['buy_amount']  = 0.0;
    $c['buy_qty']     = 0;
    $c['fill_amount'] = 0.0;
    $c['fill_qty']    = 0;
    $c['fill_signal'] = false;
    // 팔 것도 없다 (보유 0)
    $c['sell_price']  = null;
    // 신호 — pf_signal() 이 이 세 값으로 종류를 정한다
    $c['buy_signal']  = false;
    $c['sell_signal'] = false;

    return $c;
}

// ══ 시장 지표 / 시장 신호 ═══════════════════════════════════════════════
//
//  룰셋 신호(매수·매도·잔여)는 "내 계획이 무엇을 하라고 하는가"를 말한다.
//  여기서 만드는 시장 신호는 "지금 시장이 어떤 상태인가"를 말한다 — 둘은 다른 질문이고,
//  시장 신호의 진짜 쓸모는 <b>계획 신호의 신뢰도를 보정</b>하는 데 있다(pf_signal_confidence).
//
//  ★ 임계치는 전부 <b>그 종목의 평소</b>와 견준 상대값이다. "±5% 급등" 같은 고정 기준은
//    삼성전자와 코스닥 소형주에 같은 뜻이 아니다. 그래서 등락은 σ(표준편차), 거래량은 배수로 본다.

/**
 * σ 를 만들 수 있는 <b>최소 일간 변동성</b>(0.1%/일).
 *
 * ★★ 이보다 잔잔하면 σ 를 계산하지 않는다. 20일 수익률이 거의 일정하면 sd20 이
 *   부동소수 잔여값(~1e-18)까지 내려가는데, `sd20 > 0` 만 보면 그 값을 통과시켜
 *   <b>−0.5% 움직임이 −6경 σ</b> 가 되고 「급락 −0.5%」 배지가 뜬다(2026-07-30 실측으로 확인).
 *   메모리에 남은 「σ 하나로는 급등락을 놓친다」의 <b>거울상</b>이다 — σ 가 0 에 가까우면
 *   아무 움직임도 사건이 된다.
 * ★ 실측 14종목의 일간 σ 중앙이 2.7~3.2%, 가장 잔잔한 종목도 1.0% 였다.
 *   0.1% 는 그보다 열 배 아래라 정상 종목을 걸러낼 위험이 없다.
 */
const PF_SD_MIN = 0.001;

/** 정지일 판정 — 거래정지·분할정지일은 o/h/l/v 가 0 이고 종가만 유지된다 (실측 확인) */
function pf_bar_valid(array $b): bool
{
    return ((float)($b['c'] ?? 0) > 0) && ((float)($b['o'] ?? 0) > 0) && ((float)($b['v'] ?? 0) > 0);
}

/** 단순이동평균 — 배열의 <b>마지막 n개</b>. 표본이 모자라면 null */
function pf_sma(array $v, int $n): ?float
{
    $c = count($v);
    if ($n <= 0 || $c < $n) return null;
    return array_sum(array_slice($v, $c - $n, $n)) / $n;
}

/**
 * RSI(14) — Wilder 방식.
 *
 * ★ 단순평균이 아니라 Wilder 평활(전값×(n−1)+신값)÷n 을 쓴다. 단순평균으로 만든 RSI 는
 *   같은 이름으로 다른 숫자가 나와 증권사 화면과 어긋난다 — 그러면 믿고 쓸 수 없다.
 */
function pf_rsi(array $closes, int $n = 14): ?float
{
    $c = count($closes);
    if ($c < $n + 1) return null;

    $g = $l = 0.0;
    for ($i = 1; $i <= $n; $i++) {
        $d = $closes[$i] - $closes[$i - 1];
        if ($d >= 0) $g += $d; else $l -= $d;
    }
    $ag = $g / $n;
    $al = $l / $n;

    for ($i = $n + 1; $i < $c; $i++) {
        $d  = $closes[$i] - $closes[$i - 1];
        $ag = ($ag * ($n - 1) + max(0.0, $d)) / $n;
        $al = ($al * ($n - 1) + max(0.0, -$d)) / $n;
    }
    if ($al == 0.0) return ($ag == 0.0) ? 50.0 : 100.0;
    return 100 - (100 / (1 + $ag / $al));
}

/**
 * 중앙값. 표본이 없으면 null.
 *
 * 이 레포에서 평균보다 중앙값을 먼저 보는 자리가 많다 — 종목이 십여 개뿐이라
 * 한 종목의 극단값이 평균을 통째로 끌고 간다(실측으로 여러 번 속았다).
 */
function pf_median(array $v): ?float
{
    $v = array_values(array_filter($v, fn($x) => $x !== null));
    $n = count($v);
    if (!$n) return null;

    sort($v);
    return ($n % 2) ? (float)$v[intdiv($n, 2)] : ((float)$v[$n / 2 - 1] + (float)$v[$n / 2]) / 2;
}

/** 표본표준편차 (n−1). 표본이 2개 미만이면 null */
function pf_stdev(array $v): ?float
{
    $n = count($v);
    if ($n < 2) return null;
    $m = array_sum($v) / $n;
    $s = 0.0;
    foreach ($v as $x) $s += ($x - $m) ** 2;
    return sqrt($s / ($n - 1));
}

/**
 * 일봉에서 시장 지표를 만든다. <b>저장하지 않는다</b> — 볼 때마다 계산한다(이 레포의 규칙).
 *
 * @param array      $bars [['d','o','h','l','c','v'], ...] 날짜 오름차순
 * @param float|null $last 장중 현재가. 주면 마지막 봉의 종가를 이 값으로 갈아 오늘을 반영한다.
 *                         (일봉은 하루 한 번 받으므로 이걸 안 하면 장중에는 어제 상태만 보인다)
 */
function pf_indicators(array $bars, ?float $last = null): array
{
    $out = [
        'n' => 0, 'date' => null, 'close' => null, 'chg' => null,
        'sma5' => null, 'sma20' => null, 'sma60' => null, 'trend' => null,
        'sd20' => null, 'sigma' => null, 'rsi14' => null,
        'vol' => null, 'vol_ma20' => null, 'vol_mult' => null,
        'hi52' => null, 'lo52' => null, 'pos52' => null,
        'disp20' => null, 'streak' => 0, 'dd20' => null, 'intraday' => false,
    ];

    // ★ 정지일을 먼저 걸러낸다. 남겨 두면 등락 0%·거래량 0 이 평균에 섞여
    //   다음 거래일에 가짜 "거래량 급증"·"Nσ 급등"이 뜬다 (실측으로 확인된 위험).
    $b = array_values(array_filter($bars, 'pf_bar_valid'));
    $n = count($b);
    if (!$n) return $out;

    $closes = array_map(fn($x) => (float)$x['c'], $b);
    $vols   = array_map(fn($x) => (float)$x['v'], $b);

    /* 장중이면 마지막 종가를 현재가로 갈아끼운다. 거래량은 손대지 않는다 —
     * 장중에 받아 둔 오늘 봉의 거래량은 <b>하루의 일부</b>다.
     * ★ 그래도 배수는 안전하다: 미완성 거래량은 완성값보다 <b>작을 수밖에</b> 없으므로
     *   vol_mult 는 늘 하한이다. 즉 "거래량 2배" 배지가 뜨면 그건 진짜이고(과장 불가),
     *   놓치는 쪽(아직 2배가 안 찬 경우)만 생긴다 — 그건 마감 뒤 크론이 덮어쓰면 잡힌다. */
    if ($last !== null && $last > 0 && abs($closes[$n - 1] - $last) > 0.0001) {
        $closes[$n - 1]  = $last;
        $out['intraday'] = true;
    }

    $out['n']     = $n;
    $out['date']  = $b[$n - 1]['d'] ?? null;
    $out['close'] = $closes[$n - 1];
    $out['vol']   = $vols[$n - 1];
    if ($n >= 2 && $closes[$n - 2] > 0) $out['chg'] = $closes[$n - 1] / $closes[$n - 2] - 1;

    $out['sma5']  = pf_sma($closes, 5);
    $out['sma20'] = pf_sma($closes, 20);
    $out['sma60'] = pf_sma($closes, 60);
    if ($out['sma5'] !== null && $out['sma20'] !== null && $out['sma60'] !== null) {
        if ($out['sma5'] > $out['sma20'] && $out['sma20'] > $out['sma60'])      $out['trend'] = 'up';
        elseif ($out['sma5'] < $out['sma20'] && $out['sma20'] < $out['sma60'])  $out['trend'] = 'down';
        else                                                                    $out['trend'] = 'mixed';
    }
    if ($out['sma20'] !== null && $out['sma20'] > 0) $out['disp20'] = $out['close'] / $out['sma20'] - 1;

    // 일간수익률 20개의 표준편차 → 오늘 움직임이 몇 σ 인가
    if ($n >= Thr::BARS_SIGMA_MIN) {
        $rets = [];
        for ($i = $n - 20; $i < $n; $i++) {
            if ($closes[$i - 1] > 0) $rets[] = $closes[$i] / $closes[$i - 1] - 1;
        }
        $out['sd20'] = pf_stdev($rets);
        // ★ PF_SD_MIN 미만이면 σ 를 만들지 않는다 (그 상수 주석 참조 — 0 에 가까운 σ 는 폭발한다).
        //   σ 가 null 이면 급등락 판정은 「절대 15%」 규칙으로 떨어진다.
        if ($out['sd20'] !== null && $out['sd20'] >= PF_SD_MIN && $out['chg'] !== null) {
            $out['sigma'] = $out['chg'] / $out['sd20'];
        }
    }

    $out['rsi14']    = pf_rsi($closes, Thr::RSI_N);
    $out['vol_ma20'] = pf_sma(array_slice($vols, 0, $n - 1), 20);   // 오늘을 뺀 최근 20일
    if ($out['vol_ma20'] !== null && $out['vol_ma20'] > 0) {
        $out['vol_mult'] = $out['vol'] / $out['vol_ma20'];
    }

    // 52주(250거래일) 고·저. 표본이 모자라면 계산하지 않는다 — 신규상장주에서 거짓 신고가가 뜬다
    if ($n >= Thr::BARS_52W_MIN) {
        $w = array_slice($closes, max(0, $n - Thr::BARS_52W_WIN));
        $hi = max($w);
        $lo = min($w);
        $out['hi52'] = $hi;
        $out['lo52'] = $lo;
        if ($hi > $lo) $out['pos52'] = ($out['close'] - $lo) / ($hi - $lo);
    }

    // 최근 20일 고점 대비 낙폭
    if ($n >= Thr::BARS_DD_WIN) {
        $hi20 = max(array_slice($closes, $n - Thr::BARS_DD_WIN));
        if ($hi20 > 0) $out['dd20'] = $out['close'] / $hi20 - 1;
    }

    // 연속 상승(+)/하락(−) 일수
    $st = 0;
    for ($i = $n - 1; $i >= 1; $i--) {
        $up = ($closes[$i] > $closes[$i - 1]);
        if ($i === $n - 1) { $st = $up ? 1 : (($closes[$i] < $closes[$i - 1]) ? -1 : 0); if ($st === 0) break; continue; }
        if ($st > 0 && $up)                              $st++;
        elseif ($st < 0 && $closes[$i] < $closes[$i - 1]) $st--;
        else break;
    }
    $out['streak'] = $st;

    return $out;
}

// ── 유동성 (체결 가능성) ───────────────────────────────────────────────
/**
 * 유동성 지표 — <b>"신호가 떠도 그 수량을 실제로 살 수 있는가"</b>.
 *
 * 이 시스템은 지금까지 매수 <b>비용</b>으로 수수료+세금(0.34%)만 셌다. 그런데 실측(2026-07-30)
 * 한국주철관 849주를 호가를 훑어 사면 평균 체결가가 계획가보다 <b>약 1.4%</b> 높다 —
 * 수수료의 4배다. 이걸 안 보면 시뮬레이터와 실전이 갈린다.
 *
 * ★ 호가 잔량은 쓸 수 없다(네이버 itemOrderBook API 는 404 로 폐지됐다). 그리고 쓸 수 있어도
 *   초 단위로 변해 "며칠에 나눠 담자"는 계획을 세울 수 없다. 기관도 호가가 아니라
 *   <b>ADV(일평균 거래대금) 대비 참여율</b>로 집행을 관리한다 — 그래서 그 방식을 쓴다.
 *
 * ★ 유동성의 척도는 거래량(주수)이 아니라 <b>거래대금</b>이다. 주수만 보면 저가주가 유동성이
 *   좋아 보인다 — 매일홀딩스 2,324주(0.2억)와 삼성전자 3천만주(78,264억)를 주수로 견줄 수 없다.
 *
 * 반환:
 *   avg_vol/avg_val  최근 20일 평균 거래량·거래대금
 *   thin_ratio       최근 250봉 중 거래대금 1억 미만인 날의 비율 — <b>사실상 못 사는 날</b>
 *   grade            deep(≥50억) / ok(≥10억) / thin(≥1억) / very_thin(<1억)
 *   part             참여율 = 계획 수량 ÷ 일평균 거래량
 *   days             목표 참여율로 나눠 담을 때 걸리는 일수
 *   impact           추정 시장충격 — √법칙(σ × √참여율). <b>하루에 나눠 담는 기준</b>이다.
 *                    즉시 시장가로 치면 이보다 크다(호가를 훑기 때문). 그래서 하한으로 읽어야 한다.
 *
 * @param float|null $sd20   일간수익률 표준편차 (pf_indicators 의 sd20). 없으면 impact 는 null
 * @param float      $target 하루에 차지할 최대 참여율 (기본 10%)
 */
function pf_liquidity(array $bars, int $planQty = 0, ?float $sd20 = null, float $target = 0.10): array
{
    $out = [
        'n' => 0, 'avg_vol' => null, 'avg_val' => null, 'thin_ratio' => null,
        'grade' => null, 'part' => null, 'days' => null, 'impact' => null, 'plan_qty' => $planQty,
    ];

    // 정지일은 제외한다 — 거래량 0 이 평균에 섞이면 유동성을 실제보다 낮게 본다
    $b = array_values(array_filter($bars, 'pf_bar_valid'));
    $n = count($b);
    if ($n < 5) return $out;
    $out['n'] = $n;

    $win = array_slice($b, max(0, $n - 20));
    $vol = $val = 0.0;
    foreach ($win as $x) {
        $vol += (float)$x['v'];
        $val += (float)$x['v'] * (float)$x['c'];
    }
    $out['avg_vol'] = $vol / count($win);
    $out['avg_val'] = $val / count($win);

    // 못 사는 날의 비율 — 1억은 "몇 백만원 주문이 호가를 흔드는" 경계로 잡았다
    $long = array_slice($b, max(0, $n - Thr::BARS_52W_WIN));
    $thin = 0;
    foreach ($long as $x) if ((float)$x['v'] * (float)$x['c'] < Thr::LIQ_THIN_DAY_AMT) $thin++;
    $out['thin_ratio'] = $thin / count($long);

    $eok = $out['avg_val'] / 100000000;
    $out['grade'] = ($eok >= Thr::LIQ_DEEP_EOK) ? 'deep'
        : (($eok >= Thr::LIQ_OK_EOK) ? 'ok' : (($eok >= Thr::LIQ_THIN_EOK) ? 'thin' : 'very_thin'));

    if ($planQty > 0 && $out['avg_vol'] > 0) {
        $out['part'] = $planQty / $out['avg_vol'];
        $out['days'] = max(1, (int)ceil($out['part'] / max(0.01, $target)));
        if ($sd20 !== null && $sd20 > 0) {
            /* √법칙 (Almgren 등) — 시장충격 ≈ 변동성 × √참여율.
             * 선형(Amihud)보다 실측에 가깝다. 참여율이 1을 넘으면(하루 거래량보다 큰 주문)
             * 이 식은 뜻을 잃으므로 1 로 막는다 — 그때는 숫자가 아니라 "불가능"이 답이다. */
            $out['impact'] = $sd20 * sqrt(min(1.0, $out['part']));
        }
    }
    return $out;
}

/* ⊖ <b>실행 게이트(체결 게이트 `pf_fill_gate`)는 2026-08-02 사용자 지시로 삭제했다.</b>
 * 「△ 분할 권장」·「⚠ N일 분할 필요」 배지와 배지 층 ③ 실행이 통째로 없어졌다 —
 * 참여율 임계(5%·10%·얇으면 2%)는 백테스트가 아니라 지정값이었고, 화면은 3층(상태·행동·포트폴리오)이 됐다.
 * ★ 측정 자체는 남아 있다 — pf_liquidity 의 part/days/impact 를 부르면 언제든 다시 판정할 수 있다.
 * ★ grade 는 계속 쓰인다(pf_market_signals 의 「★ 거래량 N배」 = 얇은 종목 강조). */

/**
 * 시장 신호 배지 — 임계치를 <b>넘은 것만</b> 만든다.
 *
 * ★ 17종목 × 8지표 = 136개 숫자를 다 보여 주면 아무것도 안 보인다. 그래서 지표가 아니라
 *   <b>이례적인 것</b>만 배지로 세우고, 순서는 행동에 가까운 것부터(급등락 → 거래량 → 위치 → 강도 → 추세)다.
 *
 * tone: 'up'/'down'(가격 움직임 % — 등락색 빨강/파랑) ·
 *       'buyish'(살 쪽에 유리) · 'sellish'(팔 쪽에 유리) · 'risk'(경고) · 'warn' · 'info'
 * ★ 두 팔레트를 섞어 쓴다(2026-08-02): %는 방향이 곧 사실이라 등락색, 나머지는 뜻이 먼저라 의미색.
 */
function pf_market_signals(array $ind, int $max = 3, ?array $liq = null): array
{
    $sig = [];
    $add = function (string $key, string $label, string $tone, string $why) use (&$sig) {
        $sig[] = ['key' => $key, 'label' => $label, 'tone' => $tone, 'why' => $why];
    };
    $thin = ($liq !== null && in_array($liq['grade'] ?? '', ['thin', 'very_thin'], true));

    /* 급등·급락 — <b>상대(σ) 또는 절대(%) 어느 한쪽</b>만 넘어도 신호로 본다.
     *
     * ★ σ 하나로는 놓친다: 최근 20일이 유난히 출렁였으면 sd20 이 커져서 오늘의 큰 움직임도
     *   작아 보인다(변동성 클러스터링). 반대로 절대 % 하나로는 조용한 종목의 사건을 놓친다.
     *   그래서 둘을 OR 로 묶고, <b>표시는 등락률</b>(누구나 읽는 값)로 하고 σ 는 설명에 붙인다.
     * ★ 임계 3σ · 15% 는 사용자 지정(2026-08-02 · 2σ/5% 에서 상향) — 웬만한 등락에는 침묵하고
     *   진짜 이례만 띄운다. 배지가 흔하면 표시가 아니다. */
    $sg  = $ind['sigma'];
    $chg = $ind['chg'];
    $bigS = ($sg  !== null && abs($sg)  >= Thr::SIGMA);      // M5 — 임계 정본은 classes/Thr.class
    $bigP = ($chg !== null && abs($chg) >= Thr::CHG_ABS);
    /* ★ 라벨은 <b>부호 붙은 % 하나</b>다(2026-08-02 사용자 지시) — 「급등/급락」이라는 말을 빼서
     *   20·40거래일 모멘텀 칩(「20일 +112%」)과 <b>기간만 다른 같은 계열</b>로 읽히게 했다.
     *   방향은 부호와 색이 말하고, 이유(σ·절대%)는 툴팁이 말한다. */
    if ($bigS || $bigP) {
        $pct = sprintf('%+.1f%%', (float)$chg * 100);
        $absPct = Thr::pct(Thr::CHG_ABS);
        $why = '하루 ' . $pct . ' · ' . ($sg === null ? '' : '평소 변동의 ' . number_format(abs($sg), 1) . 'σ')
             . ($bigP && !$bigS ? ($sg === null ? '' : ' · ') . '절대 ' . $absPct . '% 이상' : '')
             . ' — 임계 ' . Thr::num(Thr::SIGMA) . 'σ 또는 절대 ' . $absPct . '%';
        /* ★ 색은 <b>등락색</b>(up=빨강·down=파랑) — 20·40거래일 모멘텀 칩과 같은 규칙(2026-08-02).
         *   다른 상태 배지의 「초록=사는 쪽 유리」 팔레트를 여기에만 쓰지 않는 이유:
         *   하루 −15% 를 초록으로 칠하면 20일 −52%(파랑) 와 나란히 놓였을 때 정반대로 읽힌다. */
        if ((float)$chg > 0) $add('surge',  $pct, 'up',   $why);
        else                 $add('plunge', $pct, 'down', $why);
    }

    /* 거래량 급증.
     * ★ 얇은 종목에서는 <b>같은 배수가 전혀 다른 사건</b>이다. 평소 하루 2천만원어치만 거래되는
     *   종목에 갑자기 3배가 붙는 것은 "없던 관심이 생겼다"는 뜻이고, 그게 변화의 첫 신호다.
     *   그래서 얇은 종목이면 ★ 를 붙여 드러내고 설명에 일평균 거래대금을 함께 적는다.
     * ★ 배수는 <b>주수로 재도 된다</b> — 같은 종목의 자기 비교라 단위가 상쇄된다.
     *   거래대금이 필요한 자리는 종목 간 비교(유동성 등급)이고 그건 pf_liquidity 가 맡는다. */
    $vm = $ind['vol_mult'];
    if ($vm !== null && $vm >= Thr::VOL_MULT) {
        $mult = number_format($vm, 1) . '배';
        $ctx  = '최근 20일 평균 거래량의 ' . $mult . ' — 가격 움직임의 신뢰도가 높다';
        if ($thin) {
            $ctx = '평소 하루 ' . number_format(($liq['avg_val'] ?? 0) / 100000000, 1) . '억만 거래되는 종목에 '
                 . $mult . '가 붙었다 — 없던 관심이 생겼다는 뜻이라 얇은 종목에서 특히 크게 읽어야 한다';
        }
        $add('vol', ($thin ? '★ ' : '') . '거래량 ' . $mult, 'info', $ctx);
    }

    /* 유동성은 배지로 세우지 않는다(2026-08-02 사용자 지시로 「유동성 매우 얇음」 배지 삭제).
     * 사건이 아니라 상시 특성이라 배지 슬롯(3개)을 먹으면 정작 오늘 일어난 일을 밀어낸다 —
     * 유동성은 보유종목 표의 「유동성」 열과 신호 카드의 체결 게이트가 전담한다. */

    /* ★52주 임계는 <b>배지용</b>이다 — 신뢰도(pf_signal_confidence)는 더 넓은 값을 쓴다.
     *   같은 지표라도 용도가 다르면 임계가 다르다(Thr 이 키를 나눠 둔 이유 · 보고서 D4). */
    $p   = $ind['pos52'];
    $lo5 = Thr::pct(Thr::POS52_LOW_BADGE);
    $hi5 = Thr::pct(1 - Thr::POS52_HIGH_BADGE);
    if ($p !== null && $p <= Thr::POS52_LOW_BADGE)      $add('low52',  '52주 최저권', 'risk',   '52주 범위의 하단 ' . $lo5 . '% — 하락추세일 수 있다');
    elseif ($p !== null && $p >= Thr::POS52_HIGH_BADGE) $add('high52', '52주 최고권', 'sellish', '52주 범위의 상단 ' . $hi5 . '%');

    $r = $ind['rsi14'];
    if ($r !== null && $r <= Thr::RSI_OVERSOLD)        $add('oversold',   '과매도 RSI ' . round($r), 'buyish',  'RSI ' . Thr::RSI_OVERSOLD . ' 이하 — 단기 과매도');
    elseif ($r !== null && $r >= Thr::RSI_OVERBOUGHT)  $add('overbought', '과매수 RSI ' . round($r), 'sellish', 'RSI ' . Thr::RSI_OVERBOUGHT . ' 이상 — 단기 과매수');

    if ($ind['trend'] === 'down')    $add('downtrend', '역배열', 'risk', '5<20<60일선 — 하락추세. 역추세 매수는 위험이 크다');
    elseif ($ind['trend'] === 'up')  $add('uptrend',   '정배열', 'info', '5>20>60일선 — 상승추세');

    $d = $ind['disp20'];
    if ($d !== null && abs($d) >= Thr::DISP20) {
        $add('disp', '이격 ' . sprintf('%+.0f%%', $d * 100), 'info',
             '20일선에서 ' . sprintf('%+.1f%%', $d * 100) . ' 벌어져 있다 — 평균회귀 압력');
    }

    $s = (int)$ind['streak'];
    if ($s <= -Thr::STREAK)     $add('down_streak', abs($s) . '일 연속 하락', 'buyish',  '단기 과매도 국면');
    elseif ($s >= Thr::STREAK)  $add('up_streak',   $s . '일 연속 상승',     'sellish', '단기 과열 국면');

    return array_slice($sig, 0, $max);
}

/**
 * ★★ 계획 신호 × 시장 상태 = <b>신뢰도</b>.
 *
 * 이 시스템의 매매 판단은 룰셋이 이미 내린다. 시장 지표를 따로 늘어놓는 것만으로는
 * "그래서 어쩌라고"가 남는다. 그래서 <b>계획 신호를 보정하는 자리</b>에 놓는다:
 *
 *   매수 신호 + (과매도 또는 급락) + 거래량 급증  → 투매 구간. 계획대로 담을 근거가 강하다
 *   매수 신호 + 역배열 + 52주 최저권             → 하락추세 초기. 계획보다 <b>천천히</b>
 *   매도 신호 + (과매수 또는 급등)               → 목표 도달 + 과열. 팔 근거가 강하다
 *   매도 신호 + 정배열 + 52주 최고권             → 추세가 살아 있다. 전량보다 <b>분할 매도</b> 고려
 *
 * @param string|null $kind pf_signal() 의 kind (sell|buy|fill|null)
 * @return array ['level'=>'strong'|'normal'|'caution', 'label'=>, 'why'=>]
 */
function pf_signal_confidence(?string $kind, array $ind): array
{
    $none = ['level' => 'normal', 'label' => '', 'why' => ''];
    if ($kind === null) return $none;

    /* ★ 이름이 뜻과 반대다(옛 실수): $over = 과매도(RSI 낮음) · $under = 과매수(RSI 높음).
     *   지금 고치면 아래 분기들을 전부 뒤집어야 해서 <b>읽는 사람을 위해 여기 적어 둔다</b>. */
    $over   = ($ind['rsi14'] !== null && $ind['rsi14'] <= Thr::RSI_OVERSOLD);
    $under  = ($ind['rsi14'] !== null && $ind['rsi14'] >= Thr::RSI_OVERBOUGHT);
    /* 급등락 판정은 배지와 <b>같은 상수</b>를 본다 — 예전엔 같은 숫자를 두 번 적어 두고
     * 「어긋나면 안 된다」고 주석으로 약속했다. M5 로 그 약속이 구조가 됐다. */
    $plunge = (($ind['sigma'] !== null && $ind['sigma'] <= -Thr::SIGMA) || ($ind['chg'] !== null && $ind['chg'] <= -Thr::CHG_ABS));
    $surge  = (($ind['sigma'] !== null && $ind['sigma'] >=  Thr::SIGMA) || ($ind['chg'] !== null && $ind['chg'] >=  Thr::CHG_ABS));
    $volUp  = ($ind['vol_mult'] !== null && $ind['vol_mult'] >= Thr::VOL_MULT);
    // ★52주는 <b>신뢰도용</b> 임계(10%/90%) — 배지(5%/95%)보다 넓다. 의도된 차이다.
    $low52  = ($ind['pos52'] !== null && $ind['pos52'] <= Thr::POS52_LOW_CONF);
    $high52 = ($ind['pos52'] !== null && $ind['pos52'] >= Thr::POS52_HIGH_CONF);

    if ($kind === 'buy' || $kind === 'fill') {
        if ($ind['trend'] === 'down' && $low52) {
            return ['level' => 'caution', 'label' => '주의 — 하락추세',
                    'why'   => '역배열 + 52주 최저권입니다. 계획대로 담되 한 번에 다 채우지 않는 편이 안전합니다.'];
        }
        if (($over || $plunge) && $volUp) {
            return ['level' => 'strong', 'label' => '근거 강함 — 투매',
                    'why'   => ($over ? '과매도' : '급락') . ' + 거래량 급증입니다. 계획대로 담을 근거가 강합니다.'];
        }
        if ($over || $plunge) {
            return ['level' => 'strong', 'label' => '근거 강함',
                    'why'   => ($over ? 'RSI 과매도' : '평소보다 큰 하락') . ' 구간입니다.'];
        }
        return $none;
    }

    if ($kind === 'sell') {
        if ($ind['trend'] === 'up' && $high52) {
            return ['level' => 'caution', 'label' => '분할매도 고려',
                    'why'   => '정배열 + 52주 최고권입니다. 전량 매도하면 남은 추세를 놓칠 수 있습니다.'];
        }
        if ($under || $surge) {
            return ['level' => 'strong', 'label' => '근거 강함 — 과열',
                    'why'   => ($under ? 'RSI 과매수' : '평소보다 큰 상승') . ' 구간입니다. 목표 도달과 겹칩니다.'];
        }
        return $none;
    }
    return $none;
}

// ── 신호 요약 (여러 포트폴리오를 한 화면에 세우기 위한 것) ─────────────
/**
 * 포지션 1건의 "지금 무엇을 해야 하나" 를 한 줄로 압축한다.
 *
 * kind 는 <b>행동 우선순위</b> 순서로 정해진다:
 *   'sell'  자동매도가 도달        — 팔면 예수금이 늘어 다른 매수의 재원이 되므로 가장 먼저 본다
 *   'buy'   차수가 올라간 매수     — 새 차수를 치는 것이라 금액이 크다 (buy_signal)
 *   'fill'  같은 차수의 잔여 매수  — 이미 친 차수에서 누적목표를 덜 채운 몫 (fill_signal)
 *   null    대기
 *
 * ★ 신호가 <b>없는</b> 종목에도 거리를 매긴다. "오늘은 볼 필요 없다"를 가르는 값은
 *   수익률이 아니라 "다음 매수가까지 몇 % 남았나" 다. 수익률 −18% 여도 다음 매수가가
 *   −21% 아래면 오늘 할 일은 없다 — 이 둘을 구별하려면 거리가 있어야 한다.
 *     buy_gap  = 현재가 ÷ 다음매수가 − 1   → 양수 = 그만큼 <b>더 내려야</b> 매수
 *     sell_gap = 자동매도가 ÷ 현재가 − 1   → 양수 = 그만큼 <b>더 올라야</b> 매도
 *   gap  = 두 거리 중 <b>가까운 쪽의 크기</b>, near = 그 방향. 대기 종목을 임박한 순으로 세운다.
 *   (두 값은 방향이 반대지만 "얼마나 움직여야 하는가" 라는 뜻이 같아 크기끼리 견줄 수 있다.)
 *
 * ★ 매수는 <b>수량 > 0</b> 을 조건에 넣는다. 이론가에는 닿았어도 이미 계획보다 많이 사놨으면
 *   (누적목표 − 투입액 ≤ 0) 살 것이 없다. "매수 신호 0주" 는 신호가 아니라 잡음이다.
 *
 * @param array|null $c    pf_position_calc() 결과 (룰셋에 차수가 없으면 null 이 올 수 있다)
 * @param float|null $last 현재가
 */
function pf_signal(?array $c, ?float $last): array
{
    $out = [
        'kind'     => null,
        'qty'      => 0,
        'amount'   => 0.0,
        'buy_gap'  => null,
        'sell_gap' => null,
        'gap'      => null,
        'near'     => null,
    ];
    if (!$c) return $out;

    $next = (($c['next_price'] ?? null) !== null) ? (float)$c['next_price'] : null;
    $sell = (($c['sell_price'] ?? null) !== null) ? (float)$c['sell_price'] : null;

    if ($last !== null && $last > 0) {
        if ($next !== null && $next > 0) $out['buy_gap']  = $last / $next - 1;
        if ($sell !== null && $sell > 0) $out['sell_gap'] = $sell / $last - 1;
    }

    // 가까운 쪽 — 크기로 견준다
    $cands = [];
    if ($out['buy_gap']  !== null) $cands['buy']  = abs($out['buy_gap']);
    if ($out['sell_gap'] !== null) $cands['sell'] = abs($out['sell_gap']);
    if ($cands) {
        $out['near'] = array_search(min($cands), $cands, true);
        $out['gap']  = min($cands);
    }

    if (!empty($c['sell_signal'])) {
        $out['kind']   = 'sell';
        $out['qty']    = (int)($c['filled_qty'] ?? 0);
        $out['amount'] = (float)($c['eval_amount'] ?? 0);
    } elseif (!empty($c['buy_signal']) && (int)($c['buy_qty'] ?? 0) > 0) {
        $out['kind']   = 'buy';
        $out['qty']    = (int)$c['buy_qty'];
        $out['amount'] = (float)$c['buy_amount'];
    } elseif (!empty($c['fill_signal']) && (int)($c['fill_qty'] ?? 0) > 0) {
        $out['kind']   = 'fill';
        $out['qty']    = (int)$c['fill_qty'];
        $out['amount'] = (float)$c['fill_amount'];
    }
    return $out;
}

// ── 매매 되짚기 (체결한 뒤 가격이 어떻게 됐나) ─────────────────────────
/**
 * 체결 한 건을 <b>지금 가격으로 되짚는다</b>. 매매 품질 피드백의 최소 단위.
 *
 * ★ 매수와 매도를 <b>한 지표로</b> 묶는 것이 핵심이다. 물어보는 것이 같기 때문이다 —
 *   "그 체결 뒤 가격이 내 편으로 갔나?" 매수는 올라야 잘한 것이고 매도는 내려야 잘한 것이라
 *   <b>부호만 반대</b>다. 그래서 표를 둘로 쪼갤 이유가 없고, 대신 부호를 뒤집어 흡수한다.
 *
 *   after  = 현재가 ÷ 체결가 − 1        가격이 실제로 간 방향 (그대로)
 *   edge   = 매수면 after, 매도면 −after <b>＋면 잘한 매매</b>
 *   impact = edge × 체결금액             그 판단이 금액으로 얼마였나
 *              매수 : (현재가 − 매수가) × 수량  = 담은 뒤 평가증감
 *              매도 : (매도가 − 현재가) × 수량  = ＋아낀 손실 / −놓친 이익
 *
 * 현재가나 체결가가 없으면(시세 미수집·0원 기록) 전부 null 로 둔다 — 0 으로 두면
 * "판단이 완벽했다"는 뜻으로 읽혀 집계까지 오염된다.
 */
function pf_trade_review(string $side, ?float $price, int $qty, ?float $last): array
{
    $out = ['after' => null, 'edge' => null, 'impact' => null, 'good' => null];
    if ($price === null || $price <= 0 || $last === null || $last <= 0) return $out;

    $after = $last / $price - 1;
    $sign  = ($side === 'sell') ? -1 : 1;

    $out['after']  = $after;
    $out['edge']   = $sign * $after;
    $out['impact'] = $sign * ($last - $price) * $qty;
    $out['good']   = ($out['edge'] > 0);
    return $out;
}

/**
 * 체결 묶음의 매매 품질 집계 — "내 매수는 대체로 이른가, 내 매도는 대체로 빠른가".
 *
 * ★ 적중률은 매수·매도를 <b>반드시 나눠</b> 센다. 섞으면 "매수는 늘 이르고 매도는 늘 좋았다"처럼
 *   서로 상쇄되는 두 습관이 평균 하나로 뭉개져 고칠 것을 못 찾는다.
 * ★ 무승부(edge 정확히 0)는 분모에서 뺀다 — 같은 날 체결한 건이 늘 무승부로 잡혀
 *   적중률을 아래로 끌어당긴다.
 *
 * @param array $rows [['side'=>, 'edge'=>, 'impact'=>], ...]  (pf_trade_review 결과를 실은 행)
 */
function pf_trade_score(array $rows): array
{
    $z = ['n' => 0, 'hit' => 0, 'rate' => null, 'impact' => 0.0, 'edge_sum' => 0.0, 'edge_avg' => null];
    $out = ['buy' => $z, 'sell' => $z, 'impact' => 0.0];

    foreach ($rows as $r) {
        $side = (($r['side'] ?? '') === 'sell') ? 'sell' : 'buy';
        $edge = $r['edge'] ?? null;
        if ($edge === null) continue;

        $out[$side]['impact']   += (float)($r['impact'] ?? 0);
        $out['impact']          += (float)($r['impact'] ?? 0);
        $out[$side]['edge_sum'] += (float)$edge;
        if (abs((float)$edge) < 1e-12) continue;      // 무승부는 분모에서 제외
        $out[$side]['n']++;
        if ($edge > 0) $out[$side]['hit']++;
    }
    foreach (['buy', 'sell'] as $s) {
        if ($out[$s]['n'] > 0) {
            $out[$s]['rate']     = $out[$s]['hit'] / $out[$s]['n'];
            $out[$s]['edge_avg'] = $out[$s]['edge_sum'] / $out[$s]['n'];
        }
    }
    return $out;
}

/**
 * <b>차수별</b> 매매 품질 — "어느 차수에서 돈이 새는가".
 *
 * pf_trade_score 는 매수 전체를 한 덩이로 센다(실측 적중률 31.0% · 13/42). 그런데 분할매수에서
 * 1차와 5차는 <b>서로 다른 판단</b>이다 — 1차는 "이 종목을 시작할까", 5차는 "여기서 더 담을까".
 * 둘을 평균 하나로 뭉개면 고칠 자리를 못 찾는다(매수·매도를 나눠 세는 것과 같은 이유).
 *
 * ★★ <b>새로운 정보는 적중률이 아니라 「금액영향」이다.</b> 적중률은 MFE/MAE(pf_step_excursion)가
 *   이미 "앞 차수가 얕다"로 답한 질문을 이진값으로 다시 세는 것에 가깝다. 반면 금액영향은
 *   <b>차수마다 실린 돈이 다르다</b>는 사실을 담는다 — 1차 비중 5% 에서 −30% 인 것과
 *   6차 비중 23% 에서 −10% 인 것은 적중률로는 똑같이 「1패」지만 잃은 돈은 전혀 다르다.
 *   그래서 이 표의 정렬 기준·강조는 금액영향이다.
 *
 * ★ 무승부(edge 정확히 0)는 분모에서 뺀다 — pf_trade_score 와 같은 규칙(같은 날 체결이 적중률을 끌어내린다).
 * ★ 값이 없으면(시세 미수집) <b>0 이 아니라 제외</b>. 0 은 "완벽한 판단"으로 읽혀 집계를 오염시킨다.
 * ★★ 표본 PF_EXC_MIN_N 건 미만은 <b>판정하지 않는다</b>(thin) — 차수가 높을수록 표본이 적은데
 *   하필 그쪽이 좋아 보이는 편향이 있다(6·7차는 실전 체결 0 이라 아예 행이 없다).
 * ★ 매도는 섞지 않는다 — 차수는 매수 계획의 단위이고, 매도는 안분이라 차수의 뜻이 흐려진다.
 *
 * @param array $rows ['side','step_no','edge','impact','price','qty'] 를 실은 체결 행
 * @return array step_no => ['n','hit','rate','edge_avg','edge_med','impact','amount','qty','no_edge','thin']
 */
function pf_step_score(array $rows): array
{
    $acc = [];
    foreach ($rows as $r) {
        if ((($r['side'] ?? 'buy')) !== 'buy') continue;
        $n = (int)($r['step_no'] ?? 0);
        if ($n <= 0) continue;
        if (!isset($acc[$n])) {
            $acc[$n] = ['edge' => [], 'hit' => 0, 'n' => 0, 'impact' => 0.0,
                        'amount' => 0.0, 'qty' => 0, 'no_edge' => 0];
        }
        $acc[$n]['amount'] += (float)($r['price'] ?? 0) * (int)($r['qty'] ?? 0);
        $acc[$n]['qty']    += (int)($r['qty'] ?? 0);

        $edge = $r['edge'] ?? null;
        if ($edge === null) { $acc[$n]['no_edge']++; continue; }   // 시세가 없어 되짚을 수 없는 건
        $acc[$n]['impact'] += (float)($r['impact'] ?? 0);
        $acc[$n]['edge'][]  = (float)$edge;
        if (abs((float)$edge) < 1e-12) continue;                   // 무승부는 분모에서 제외
        $acc[$n]['n']++;
        if ($edge > 0) $acc[$n]['hit']++;
    }

    $out = [];
    ksort($acc);
    foreach ($acc as $n => $a) {
        $out[$n] = [
            'n'        => $a['n'],
            'hit'      => $a['hit'],
            'rate'     => $a['n'] > 0 ? $a['hit'] / $a['n'] : null,
            'edge_avg' => $a['edge'] ? array_sum($a['edge']) / count($a['edge']) : null,
            'edge_med' => pf_median($a['edge']),
            'impact'   => $a['impact'],
            'amount'   => $a['amount'],
            'qty'      => $a['qty'],
            'no_edge'  => $a['no_edge'],
            // 판정 보류 — 초록으로 칠하지 않는다. "괜찮다"가 아니라 "아직 모른다"
            'thin'     => ($a['n'] < PF_EXC_MIN_N),
        ];
    }
    return $out;
}

// ── 룰셋의 「변동율」 vs 종목의 실측 변동성 ────────────────────────────────
//
//  `pf_rule_set.volatility` 는 옛 엑셀에서 넘어온 라벨이었고 <b>계산에 쓰이지 않았다</b>
//  (스키마 주석도 "참고용 메모"). 9 로 바꿔도 아무것도 안 움직여서 있으나 없으나였다.
//  여기서 <b>뜻을 정하고</b> 실측과 견줘 쓸모를 준다.
//
//  ★ 정의 — <b>변동율 = 그 룰셋이 상정하는 종목의 20거래일 변동성(%)</b>.
//    실측 2026-07-30(14종목·5.5년)에서 잔잔한 종목의 20거래일 변동성이
//    한국주철관 5.0% · 매일홀딩스 4.9% 로 나왔고, 옛 엑셀에 「변동율 5%」 계열 표가
//    따로 있었다는 사실과 자리가 맞는다. 8% 는 그보다 한 단계 위 계열이다.
//    (엑셀이 정말 이 단위였는지는 알 수 없다 — 그래서 화면에 정의를 밝힌다)
//
//  ★★ <b>창 길이가 결정적이다</b>(실측): 이씨에스 전체 11.4% vs 최근 1년 6.1%,
//    SK하이닉스 16.9% vs 27.4%. 순위까지 바뀐다. 「지금 이 종목이 이 룰셋에 맞나」를
//    묻는 화면이므로 <b>최근 1년</b>으로 고정한다 — 창을 안 밝히면 숫자가 거짓말을 한다.
//
//  ★ √t 스케일(일간 σ × √20)을 쓰지 않고 <b>20거래일 구간수익률을 직접</b> 잰다.
//    둘의 중앙값이 11.9% vs 11.2% 로 비슷해 가정을 넣을 이유가 없다.

const PF_VOL_SPAN   = 20;    // 구간 길이(거래일) — 「20거래일 변동성」의 20
const PF_VOL_WINDOW = 250;   // 볼 기간(거래일) ≈ 최근 1년
const PF_VOL_MIN_N  = 60;    // 구간 표본이 이보다 적으면 판정하지 않는다

/**
 * 실측 변동성 — 최근 $window 거래일에서 <b>$span 거래일 구간수익률의 표준편차</b>.
 *
 * ★ 정지일(o/h/l/v = 0)은 먼저 뺀다 — 안 걸면 0 이 섞여 변동성이 부풀거나 꺼진다.
 * ★ 창은 겹친다(rolling). 표본이 늘어나 추정이 안정되는 대신 자기상관이 남는다 —
 *   종목 간 <b>견주기</b>에 쓰는 값이라 그 편향은 모두에게 같게 걸리므로 문제되지 않는다.
 */
function pf_vol20(array $bars, int $window = PF_VOL_WINDOW, int $span = PF_VOL_SPAN): ?float
{
    $b = array_values(array_filter($bars, 'pf_bar_valid'));
    $c = array_map(fn($x) => (float)$x['c'], $b);

    // 첫 구간을 만들려면 span 개가 더 필요하다
    $c = array_slice($c, -($window + $span));
    $n = count($c);

    $r = [];
    for ($i = $span; $i < $n; $i++) {
        if ($c[$i - $span] > 0) $r[] = $c[$i] / $c[$i - $span] - 1;
    }
    return (count($r) >= PF_VOL_MIN_N) ? pf_stdev($r) : null;
}

/**
 * 실측 변동성이 룰셋이 상정한 것과 맞는가.
 *
 * ★ 임계를 <b>1.5배 / 0.8배</b>로 넉넉히 둔다. 실측 중앙값이 라벨의 1.39배라
 *   1.2배쯤에서 자르면 거의 모든 종목에 경고가 붙어 배지가 잡음이 된다.
 * ★ 「출렁임 큼」은 <b>룰셋이 틀렸다</b>는 뜻이 아니다 — 상정보다 크게 움직이니
 *   차수 간격이 그만큼 빨리 소진된다는 뜻이다(그 판단은 사람이 한다).
 *
 * @param float|null $real  pf_vol20() 결과
 * @param float|null $label pf_rule_set.volatility (％가 아니라 비율. 8% → 0.08)
 */
function pf_vol_match(?float $real, ?float $label): array
{
    if ($real === null || $label === null || $label <= 0) {
        return ['level' => 'none', 'label' => '', 'ratio' => null, 'why' => ''];
    }
    $ratio = $real / $label;
    $fmt   = fn(float $v) => number_format($v * 100, 1) . '%';
    $tail  = ' (실측 ' . $fmt($real) . ' vs 상정 ' . $fmt($label)
           . ' · ' . number_format($ratio, 2) . '배)';

    if ($ratio >= 1.5) {
        return ['level' => 'rough', 'label' => '출렁임 큼', 'ratio' => $ratio,
                'why' => '이 룰셋이 상정한 것보다 크게 움직입니다 — 차수 간격이 그만큼 빨리 소진됩니다' . $tail];
    }
    if ($ratio <= 0.8) {
        return ['level' => 'calm', 'label' => '잔잔', 'ratio' => $ratio,
                'why' => '이 룰셋이 상정한 것보다 조용합니다 — 뒤 차수까지 잘 닿지 않을 수 있습니다' . $tail];
    }
    return ['level' => 'fit', 'label' => '맞음', 'ratio' => $ratio,
            'why' => '상정 범위 안입니다' . $tail];
}

// ── MFE/MAE — 체결 이후 최고·최저 (차수 하락률 튜닝의 직접 근거) ──────────
//
//  pf_trade_review 는 <b>현재가 한 점</b>과만 견준다. 그래서 "매도 뒤 크게 올랐다 되돌아온" 매매나
//  "담은 뒤 반토막까지 갔다가 회복한" 매매가 전부 "무승부"로 보인다 — 지나간 위험이 지워진다.
//  여기서는 체결 이후 <b>가장 유리했던 지점(MFE)</b>과 <b>가장 불리했던 지점(MAE)</b>을 일봉으로 되짚는다.
//
//  ★ 부호 규칙은 pf_trade_review 의 edge 와 같다 — <b>＋면 내 편</b>.
//      매수 : MFE = 최고가/체결가 − 1   (오르면 이득)      MAE = 최저가/체결가 − 1
//      매도 : MFE = −(최저가/체결가 − 1) (내리면 이득)      MAE = −(최고가/체결가 − 1)
//    그래서 매수·매도를 <b>한 표</b>에 둘 수 있다(히스토리 화면의 설계와 같다).

/** 체결 뒤 이 달력일 안에 일봉이 있어야 "그 구간이 덮였다"고 본다 */
const PF_EXC_COVER_DAYS = 10;

/** 차수 판정에 필요한 최소 표본 — 이보다 적으면 "괜찮다"가 아니라 "아직 모른다"다 */
const PF_EXC_MIN_N = 5;

/**
 * 체결 한 건의 MFE/MAE.
 *
 * ★ <b>체결일은 세지 않는다</b>(d > traded_at). 체결 당일의 저가는 내 주문 <b>전</b>에 지나갔을 수도 있어
 *   "담은 뒤 더 빠졌나"라는 질문과 섞인다. 하루를 버리는 대신 뜻이 분명한 값을 얻는다.
 * ★ 거래정지일(o/h/l/v = 0, 종가만)은 제외한다 — 안 걸면 저가 0 이 섞여 MAE 가 −100% 로 찍힌다.
 * ★ 일봉이 <b>체결 시점을 덮지 못하면</b>(오래된 체결 + 일봉 보관 3년) covered=false 로 알린다.
 *   이때 최저·최고는 일봉이 시작된 뒤의 값이라 실제보다 <b>얕다</b> — 집계에서 빼야 한다.
 *   (조용히 섞으면 "생각보다 안 빠졌다"는 반대 결론이 난다)
 *
 * @param array $bars pf_daily 행 ['d','o','h','l','c','v'] · 날짜 오름차순
 */
function pf_trade_excursion(string $side, ?float $price, string $tradedAt, array $bars): array
{
    $out = ['mfe' => null, 'mae' => null, 'mfe_at' => null, 'mae_at' => null,
            'mae_days' => null, 'n' => 0, 'covered' => false];

    $d0 = substr($tradedAt, 0, 10);
    if ($price === null || $price <= 0 || $d0 === '' || !$bars) return $out;

    $hi = $lo = null;
    $hiAt = $loAt = null;
    $first = null;

    foreach ($bars as $b) {
        $d = substr((string)($b['d'] ?? ''), 0, 10);
        if ($d === '' || $d <= $d0) continue;      // 체결일 다음 거래일부터
        if (!pf_bar_valid($b)) continue;           // 거래정지일 제외
        if ($first === null) $first = $d;
        $out['n']++;

        $h = (float)$b['h'];
        $l = (float)$b['l'];
        if ($hi === null || $h > $hi) { $hi = $h; $hiAt = $d; }
        if ($lo === null || $l < $lo) { $lo = $l; $loAt = $d; }
    }
    if ($hi === null) return $out;

    // 일봉이 체결 직후를 덮었나 (오래된 체결은 일봉 시작이 한참 뒤일 수 있다)
    $out['covered'] = ((strtotime($first) - strtotime($d0)) / 86400) <= PF_EXC_COVER_DAYS;

    $up   = $hi / $price - 1;      // 오른 쪽 최대
    $down = $lo / $price - 1;      // 내린 쪽 최대 (보통 음수)

    if ($side === 'sell') {
        $out['mfe'] = -$down; $out['mfe_at'] = $loAt;
        $out['mae'] = -$up;   $out['mae_at'] = $hiAt;
    } else {
        $out['mfe'] = $up;    $out['mfe_at'] = $hiAt;
        $out['mae'] = $down;  $out['mae_at'] = $loAt;
    }
    $out['mae_days'] = (int)round((strtotime($out['mae_at']) - strtotime($d0)) / 86400);
    return $out;
}

/**
 * 차수별 MFE/MAE 집계 — <b>"차수 하락률이 얕은가"에 답하는 표</b>.
 *
 * 매수 적중률 31%(2026-07-30 실측)의 원인 가설이 "차수 간격이 좁아 하락 초기에 총알을 다 쓴다"였다.
 * 그 가설은 <b>차수를 담은 뒤 실제로 얼마나 더 빠졌는가</b>로만 확정할 수 있다.
 *
 * ★ <b>중앙값을 함께 낸다</b>. 표본이 차수당 몇 건뿐이라 한 건의 −60% 가 평균을 통째로 끌고 간다.
 * ★ <b>건별 단순평균</b>이다(금액가중 아님) — 각 판단이 한 표다. 같은 날 같은 차수를 나눠 체결하면
 *   그만큼 표가 늘어난다는 뜻이기도 하다.
 * ★ 표본에서 빠지는 건은 <b>사유를 나눠</b> 센다 — 둘은 뜻이 다르고 할 일도 다르다:
 *     fresh   아직 되짚을 날이 없다(오늘·어제 체결) → 시간이 지나면 저절로 들어온다
 *     skipped 일봉이 체결 시점을 못 덮었다(옛 체결)  → <b>일봉을 더 받아야</b> 들어온다
 *   합쳐서 "(−3)" 으로만 적으면 오늘 담은 것을 "일봉이 없다"로 오해한다(실제로 그렇게 적혀 있었다).
 * ★ 매도는 섞지 않는다 — 차수는 매수 계획의 단위다.
 *
 * @param array $rows ['side','step_no','exc'=>pf_trade_excursion 결과] 를 실은 체결 행
 * @return array step_no => ['n','skipped','fresh','mae_avg','mae_med','mae_worst','mfe_avg','days_avg']
 */
function pf_step_excursion(array $rows): array
{
    $acc = [];
    foreach ($rows as $r) {
        if ((($r['side'] ?? 'buy')) !== 'buy') continue;
        $n = (int)($r['step_no'] ?? 0);
        if ($n <= 0) continue;
        $e = $r['exc'] ?? null;
        if (!isset($acc[$n])) $acc[$n] = ['mae' => [], 'mfe' => [], 'days' => [], 'skipped' => 0, 'fresh' => 0];
        if (!$e || $e['mae'] === null) { $acc[$n]['fresh']++;   continue; }
        if (empty($e['covered']))      { $acc[$n]['skipped']++; continue; }
        $acc[$n]['mae'][]  = (float)$e['mae'];
        $acc[$n]['mfe'][]  = (float)$e['mfe'];
        $acc[$n]['days'][] = (int)$e['mae_days'];
    }

    $avg = fn(array $v) => $v ? array_sum($v) / count($v) : null;

    $out = [];
    ksort($acc);
    foreach ($acc as $n => $a) {
        $out[$n] = [
            'n'         => count($a['mae']),
            'skipped'   => $a['skipped'],
            'fresh'     => $a['fresh'],
            'mae_avg'   => $avg($a['mae']),
            'mae_med'   => pf_median($a['mae']),
            'mae_worst' => $a['mae'] ? min($a['mae']) : null,
            'mfe_avg'   => $avg($a['mfe']),
            'days_avg'  => $a['days'] ? (int)round($avg($a['days'])) : null,
        ];
    }
    return $out;
}

/**
 * 그 차수의 실측 추가하락을 <b>계획상 다음 차수 하락률</b>과 견준 판정.
 *
 * 뜻: 그 차수를 담은 뒤 실제로 다음 트리거를 <b>지나쳐</b> 더 빠졌다면, 다음 차수는 이르게 잡힌 것이다
 * (총알이 하락 초기에 먼저 나간다). 반대로 다음 트리거에도 못 닿았다면 그 차수 간격은 넉넉하다.
 *
 * ★ 여유(margin)를 둔다 — 표본이 작아 몇 %p 차이로 "얕다/깊다"를 단정하면 매번 판정이 뒤집힌다.
 * ★★ <b>표본이 모자라면 판정하지 않는다</b>(thin). 3건으로 "넉넉"이라 적으면 그것은
 *   "괜찮다"로 읽히는데 사실은 <b>아직 모른다</b>는 뜻이다 — 이 레포의 52주위치·추세 판정과 같은 규칙이다.
 *   차수가 높을수록 최근 체결이라 표본이 적고 빠질 시간도 짧아, 하필 그쪽이 안전해 보이는 편향이 있다.
 *
 * @param float|null $mae      실측 추가하락 (음수)
 * @param float|null $nextDrop 계획상 다음 차수 하락률 (음수)
 * @param int|null   $n        표본 수 (null 이면 표본 판정을 건너뛴다)
 */
function pf_exc_verdict(?float $mae, ?float $nextDrop, ?int $n = null, float $margin = 0.03): array
{
    if ($mae === null || $nextDrop === null || $nextDrop >= 0) {
        return ['level' => 'none', 'label' => '', 'why' => ''];
    }
    if ($n !== null && $n < PF_EXC_MIN_N) {
        return ['level' => 'thin', 'label' => '표본 부족',
                'why' => '표본 ' . $n . '건뿐입니다 — ' . PF_EXC_MIN_N . '건 이상이어야 판정합니다. '
                       . '"괜찮다"가 아니라 아직 모른다는 뜻입니다'];
    }
    $gap = abs($mae) - abs($nextDrop);      // ＋면 계획보다 더 빠졌다
    if ($gap > $margin) {
        return ['level' => 'shallow', 'label' => '얕다',
                'why' => '다음 차수 트리거(' . number_format($nextDrop * 100, 1) . '%)를 지나 '
                       . number_format($gap * 100, 1) . '%p 더 빠졌습니다 — 그 차수도 이르게 잡혔습니다'];
    }
    if ($gap < -$margin) {
        return ['level' => 'deep', 'label' => '넉넉',
                'why' => '다음 차수 트리거(' . number_format($nextDrop * 100, 1) . '%)에 '
                       . number_format(-$gap * 100, 1) . '%p 못 미쳤습니다 — 간격이 넉넉합니다'];
    }
    return ['level' => 'fit', 'label' => '맞음',
            'why' => '실측 추가하락이 다음 차수 트리거와 ' . number_format($margin * 100, 0) . '%p 안에서 맞습니다'];
}

/**
 * 청산(전량매도)한 종목의 <b>재진입 판정</b> — 시뮬레이터의 `재진입 + 대기(거래일)` 규칙을 실전으로 옮긴 것.
 *
 * 시뮬레이터는 청산 후 wait 거래일을 쉬고 곧바로 1차를 다시 담는다(진입에는 하락 조건이 없다).
 * 실전에서 그대로 하면 <b>청산가보다 비싼 값에 되사는 일</b>이 생긴다. 그래서 조건을 하나 더 건다:
 *
 *   ① 대기일이 지났다            (경과일 ≥ wait)
 *   ② 현재가 ≤ 청산가 × (1 − 하락요건)   되살 값이 팔았던 값보다 충분히 낮다
 *
 * ★ 경과일은 <b>달력일</b>이다. 시뮬레이터의 wait 는 거래일이라 며칠 어긋난다 — 화면에 그대로 밝힌다.
 *
 * ★★ <b>두 요건을 따로 돌려준다</b>(2026-08-04). state 만으로는 「대기 중」일 때 값 요건이 충족인지
 *   아닌지 알 수 없다 — 대기에서 곧바로 돌아 나오기 때문이다. 실제로 화면에 「대기 6일 남음」만 떠서
 *   <b>값도 한참 모자란다는 사실이 숨었다</b>(삼성전자 −2.23% vs 요건 −9.0%). 무엇이 얼마나 남았는지
 *   보여 주려면 두 관문을 다 알아야 하므로 판정 안에서 함께 낸다 — 화면이 조건을 다시 적으면 안 된다.
 *
 * @return array [
 *   'state'      'wait'|'ready'|'expensive'   wait=아직 대기 / ready=둘 다 충족 / expensive=대기는 끝났으나 값이 비쌈
 *   'days','left','gap'                       경과일 · 남은 대기일 · 청산가 대비(음수=싸다)
 *   'days_ok','price_ok'                      관문별 충족 여부 (state 와 무관하게 늘 판정한다)
 *   'need_price'                              값 요건을 만족하는 가격 = 청산가 × (1 − 하락요건)
 *   'need_gap'                                거기까지 현재가가 더 내려야 하는 비율(충족이면 0)
 * ]
 */
function pf_reentry_check(?float $sellPrice, ?float $last, int $days, int $waitDays, float $dropReq = 0.0): array
{
    $out = ['state' => 'wait', 'days' => $days, 'left' => max(0, $waitDays - $days), 'gap' => null,
            'days_ok' => ($days >= $waitDays), 'price_ok' => false,
            'need_price' => null, 'need_gap' => null];

    if ($sellPrice !== null && $sellPrice > 0) {
        $out['need_price'] = $sellPrice * (1 - $dropReq);
    }
    if ($sellPrice !== null && $sellPrice > 0 && $last !== null && $last > 0) {
        $out['gap']      = $last / $sellPrice - 1;      // 음수 = 팔았던 값보다 그만큼 싸다
        $out['price_ok'] = ($out['gap'] <= -$dropReq);
        // 아직 비싸면 「여기서 얼마나 더 내려야 하나」 — 화면이 다시 계산하지 않게 여기서 낸다
        $out['need_gap'] = $out['price_ok'] ? 0.0 : ($out['need_price'] / $last - 1);
    }

    if ($days < $waitDays) return $out;
    if ($out['gap'] === null)          { $out['state'] = 'expensive'; return $out; }
    $out['state'] = $out['price_ok'] ? 'ready' : 'expensive';
    return $out;
}

/**
 * 시장 신호의 <b>짧은 이름</b> — 차트 마커처럼 폭이 없는 자리에 쓴다.
 *
 * 배지 라벨에는 수치가 붙어 있다(「과매도 RSI 25」·「거래량 2.0배」·「4일 연속 하락」).
 * 표에서는 그게 정보지만 차트 마커에 그대로 쓰면 <b>10년 구간에서 라벨이 겹쳐</b> 못 읽는다.
 * ★ 첫 낱말만 잘라 쓰면 안 된다 — 「52주 최저권」→「52주」, 「4일 연속 하락」→「4일」 로 뜻이 사라진다.
 *   그래서 <b>키로 매핑</b>한다.
 */
function pf_mkt_short(string $key): string
{
    return [
        'surge'       => '급등',   'plunge'      => '급락',
        'vol'         => '거래량', 'illiq'       => '얇음',
        'low52'       => '최저권', 'high52'      => '최고권',
        'oversold'    => '과매도', 'overbought'  => '과매수',
        'downtrend'   => '역배열', 'uptrend'     => '정배열',
        'disp'        => '이격',
        'down_streak' => '연속하락', 'up_streak' => '연속상승',
    ][$key] ?? '';
}

/**
 * <b>그 날까지의 봉</b>으로 계산한 시장 상태 — 체결 시점을 되짚을 때 쓴다.
 *
 * "내가 산 그 날 시장은 역배열 + 과매도였다" 를 알면 매매 품질을 되짚을 수 있다.
 * ★★ <b>오늘 상태를 옛 체결에 붙이면 아무 뜻도 없는 라벨</b>이 된다 — 그래서 날짜로 잘라 다시 계산한다.
 * ★ 현재가를 갈아끼우지 않는다(pf_indicators 2번째 인자 없음) — 그 날의 종가가 그 날의 종가다.
 * ★ 표본이 25봉 미만이면 빈 배열이다(이동평균·RSI 가 아직 뜻을 갖지 못한다).
 *
 * @param array  $bars 날짜 오름차순 · 'd' 키 필요
 * @param int    $max  몇 개까지 (화면 폭에 맞춰 부르는 쪽이 정한다)
 */
function pf_state_at(array $bars, string $date, int $max = 2): array
{
    if (!$bars || $date === '') return [];

    $upto = [];
    foreach ($bars as $b) {
        if ((string)($b['d'] ?? '') > $date) break;
        $upto[] = $b;
    }
    if (count($upto) < 25) return [];

    return pf_market_signals(pf_indicators($upto), $max);
}

// ── 사이클 나이 경보 ────────────────────────────────────────────────────
//
//  2026-07-30 사이클 전수 분석(226개)의 실측이 근거다:
//    · 물림비율은 차수 계단을 따라 오른다 — 4차까지 ≤9% · <b>5차 23% · 6차 42% · 7차 50%</b>
//    · 2년을 넘긴 사이클의 32%(하한 추정)는 끝내 안 닫혔고, 3년이면 동전던지기(52%)다
//    · 시간손절·비중동결을 백테스트했더니 <b>기계식 규칙은 수익을 깎았다</b>(동결은 사다리 원리 훼손)
//  ⇒ 그래서 이것은 <b>자동 규칙이 아니라 경보</b>다 — 「2년 경과 또는 5차 도달, 먼저 오는 쪽에서
//    이 종목이 정말 반등형인지 재평가하라」를 화면이 대신 기억해 준다.

const PF_CYCLE_WARN_DAYS = 730;   // 달력일 2년
const PF_CYCLE_WARN_STEP = 5;     // 물림비율이 9% → 23% 로 꺾이는 차수

/**
 * 사이클 나이 — <b>첫 매수일</b>부터 오늘까지 달력일.
 *
 * 실전 포지션은 전량매도하면 closed 가 되고 재진입은 새 포지션이므로,
 * 열린 포지션의 사이클 시작 = 그 포지션의 가장 이른 매수일이다.
 */
function pf_cycle_age(array $tradeRows, ?string $today = null): array
{
    $start = null;
    foreach ($tradeRows as $r) {
        if (($r['side'] ?? 'buy') !== 'buy') continue;
        $d = substr((string)($r['traded_at'] ?? ''), 0, 10);
        if ($d === '') continue;
        if ($start === null || $d < $start) $start = $d;
    }
    if ($start === null) return ['start' => null, 'days' => null];

    $t = $today ?? date('Y-m-d');
    return ['start' => $start, 'days' => max(0, (int)round((strtotime($t) - strtotime($start)) / 86400))];
}

/**
 * 「장기물림」 경보 — 2년 경과 <b>또는</b> 5차 도달, 먼저 오는 쪽.
 * (2026-08-02 사용자 지시로 「재평가」에서 개명 — 무엇을 하라가 아니라 <b>무슨 상태인지</b>를 이름에 담는다.)
 *
 * ★ 손절·매수중단을 자동으로 걸지 않는다. 백테스트 실측 —
 *   1년 시간손절은 승자까지 잘랐고(1년 생존자의 78%가 결국 닫혔다),
 *   비중동결은 평균단가가 안 내려와 탈출 목표가 영영 안 낮아졌다(수익 반토막·물림 증가).
 *   시간과 깊이는 <b>판단을 소집하는 신호</b>로만 쓴다.
 */
function pf_cycle_alert(?int $days, ?int $curStep): array
{
    $old  = ($days !== null && $days >= PF_CYCLE_WARN_DAYS);
    $deep = ($curStep !== null && $curStep >= PF_CYCLE_WARN_STEP);
    if (!$old && !$deep) return ['level' => 'none', 'label' => '', 'why' => ''];

    $what = [];
    if ($old)  $what[] = '2년 경과(' . number_format($days) . '일)';
    if ($deep) $what[] = $curStep . '차 도달';

    return ['level' => 'alert', 'label' => '장기물림',
            'why' => implode(' + ', $what) . ' — 실측(사이클 226개)에서 5차부터 물림비율이 23~50%로 꺾이고, '
                   . '2년을 넘긴 사이클의 3분의 1은 끝내 닫히지 않았습니다. '
                   . '기계식 손절·매수중단은 백테스트에서 수익을 깎았으므로 자동으로 막지 않습니다 — '
                   . '이 종목이 정말 반등형인지(박스권 이력·거래량) 다시 판단할 지점입니다.'];
}

/** 신호 정렬 순위 — 작을수록 먼저 (sell → buy → fill → 대기) */
function pf_signal_rank(?string $kind): int
{
    return ['sell' => 0, 'buy' => 1, 'fill' => 2][$kind ?? ''] ?? 3;
}
?>
