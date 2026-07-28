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
function pf_position_calc(array $steps, array $trades, float $limitAmt, ?float $lastPrice = null, array $p = [], array $ledger = []): array
{
    $p      = pf_params($p);
    $ladder = pf_theory_ladder($steps, $trades, $p, $lastPrice);

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
        'buy_amount'     => $buyAmt,       // 지금 사야 할 금액
        'buy_qty'        => $buyQty,       // 현재가로 나눈 수량
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
?>
