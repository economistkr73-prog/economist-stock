<?php
/**
 * stock/lib/sim.php — 분할매수 백테스트 시뮬레이터 (순수 함수)
 *
 * DB·세션·출력 의존 0. 매매 판단은 전부 calc.php 의 계산엔진을 그대로 재사용한다.
 * 화면과 같은 함수로 돌려야 "시뮬 결과와 실제 화면이 다르다"는 사고가 안 난다.
 *
 * 시세는 네이버 일봉(시·고·저·종)을 쓴다. 고가·저가가 있으면 장중 체결까지 흉내내고,
 * 종가만 있는 옛 데이터는 자동으로 종가 체결로 떨어진다.
 */

/**
 * 계산엔진 판번호 — 목록 캐시의 지문에 섞는다.
 *
 * 시세·조건·룰셋이 그대로여도 <b>엔진 코드를 고치면 결과가 달라진다.</b>
 * 그때 이 값을 올리면 저장된 요약 캐시가 전부 한 번에 무효가 된다.
 * (calc.php 의 매매 규칙이나 sim.php 의 체결 가정을 바꿀 때마다 반드시 올릴 것)
 */
const PF_SIM_VER = '2026-07-28.1';

/**
 * 새 종목을 등록했을 때 "청산 후 대기(거래일)" 기본값.
 *
 * 0 이면 청산한 바로 다음 날 다시 1차를 잡아 사이클이 과도하게 늘어난다.
 * 이미 등록된 종목은 저장된 값을 그대로 쓰므로 여기를 고쳐도 바뀌지 않는다.
 */
const PF_SIM_WAIT_DEFAULT = 10;

// ── 백테스트 ──────────────────────────────────────────────────────────
/**
 * 룰셋대로 매매했다면 어떻게 됐을지 일자별로 돌린다.
 *
 * 판단은 pf_position_calc() 가 내놓는 값만 쓴다.
 *   buy_amount > 0 → 매수 (현재가로 도달한 차수까지 누적목표를 따라잡는다)
 *   sell_price 도달 → 전량 매도
 *
 * 두 가지 체결 가정을 지원한다.
 *   종가 모드 (기본)   : 판단·체결 모두 그 날 종가. 고가·저가가 없어도 돌아간다.
 *   장중 모드 (intraday): 고가·저가가 있을 때. 지정가 주문을 걸어 둔 것처럼 본다.
 *       매수 = 저가가 이론가에 닿으면 체결, 체결가 = min(이론가, 시가)   (갭하락이면 시가)
 *       매도 = 고가가 자동매도가에 닿으면 체결, 체결가 = max(자동매도가, 시가)
 *       단, 1차 진입은 걸어 둔 주문이 없으므로 시가 체결로 본다.
 *
 * 전량 매도하면 한 사이클이 끝난다. reenter 면 wait 일 뒤 새 사이클로 다시 1차부터 시작한다.
 *
 * @param array $prices [['d'=>,'c'=>, (o,h,l)], ...] 오름차순
 * @param array $steps  step_no => ['weight','drop_rate','target_rate']
 * @param array $opt    limit_amt · reenter(bool) · wait(int) · intraday(bool)
 * @param array $p      pf_params() 오버라이드 (수수료·세율·호가단위)
 */
function pf_sim_run(array $prices, array $steps, array $opt = [], array $p = []): array
{
    $p        = pf_params($p);
    $limit    = (float)($opt['limit_amt'] ?? 0);
    $reenter  = !empty($opt['reenter']);
    $wait     = max(0, (int)($opt['wait'] ?? 0));
    $intraday = !empty($opt['intraday']);

    if (!$prices || !$steps || $limit <= 0) {
        return pf_sim_empty();
    }

    $cash     = $limit;      // 예수금 (원금 = 투자한도)
    $trades   = [];          // 현재 사이클의 체결 (calc 에 넘길 형태)
    $all      = [];          // 전체 체결 로그 (화면 표시용)
    $cycles   = [];
    $cycle    = null;
    $seq      = 0;
    $stopped  = false;       // 재진입 안 함 + 청산 완료
    $cooldown = 0;
    $realizedTotal = 0.0;

    $equity   = [];
    $peak     = $cash;
    $mdd      = 0.0;
    $maxUsed  = 0.0;
    $maxStep  = 0;

    foreach ($prices as $row) {
        $d  = $row['d'];
        $px = (float)$row['c'];

        // 고가·저가가 없으면(종가만 있는 옛 데이터) 자동으로 종가 모드로 떨어진다
        $op = (isset($row['o']) && $row['o'] > 0) ? (float)$row['o'] : $px;
        $hi = (isset($row['h']) && $row['h'] > 0) ? (float)$row['h'] : $px;
        $lo = (isset($row['l']) && $row['l'] > 0) ? (float)$row['l'] : $px;
        $intra = $intraday && $hi >= $lo && $lo > 0;

        // 매수는 그 날 저가로 판단한다 (장중에 이론가를 찍었는지)
        $judge = $intra ? $lo : $px;

        $led = pf_ledger($trades, $p);
        $c   = pf_position_calc($steps, pf_trades_by_step($trades), $limit, $judge, $p, $led);

        $act = null;

        // 매도는 그 날 고가로 판단한다
        $sp      = $c['sell_price'];
        $hitSell = $intra ? ($sp !== null && $hi >= $sp) : !empty($c['sell_signal']);

        if ((int)$led['held_qty'] > 0 && $hitSell) {
            // ── 자동매도가 도달 → 전량 매도
            $fillS    = $intra ? min($hi, max((float)$sp, $op)) : $px;
            $qty      = (int)$led['held_qty'];
            $gross    = $fillS * $qty;
            $fee      = pf_sell_cost($gross, $p);
            $cash    += $gross - $fee;
            $trades[] = ['id' => ++$seq, 'side' => 'sell', 'step_no' => 0, 'traded_at' => $d, 'price' => $fillS, 'qty' => $qty];

            $ledAfter       = pf_ledger($trades, $p);
            $cycleRealized  = (float)$ledAfter['realized_pl'];
            $realizedTotal += $cycleRealized - (float)$led['realized_pl'];

            $act = ['d' => $d, 'side' => 'sell', 'step' => 0, 'price' => $fillS, 'qty' => $qty,
                    'amount' => $gross, 'fee' => $fee, 'cash' => $cash];
            $all[] = $act;

            if ($cycle !== null) {
                $cycle['exit_date']   = $d;
                $cycle['exit_price']  = $fillS;
                $cycle['days']        = pf_sim_days($cycle['entry_date'], $d);
                $cycle['realized_pl'] = $cycleRealized;
                $cycle['return']      = ($cycle['invested'] > 0) ? $cycleRealized / $cycle['invested'] : null;
                $cycles[] = $cycle;
                $cycle = null;
            }

            // 사이클 종료 → 체결 초기화 (다음 사이클은 1차부터)
            $trades   = [];
            $cooldown = $wait;
            if (!$reenter) $stopped = true;

        } elseif (!$stopped && $cooldown <= 0 && (float)$c['buy_amount'] > 0) {
            // ── 다음 차수 도달 → 매수. 금액은 누적목표가, 수량은 체결가가 정한다.
            $step   = (int)$c['reach_step'];
            $theory = $c['steps'][$step]['theory_price'] ?? null;

            $fillB = $px;
            if ($intra) {
                // 1차 진입은 걸어 둔 주문이 없으니 시가 체결, 2차부터는 지정가(이론가) 체결
                $fillB = ((int)$c['cur_step'] === 0 || $theory === null || $theory <= 0)
                    ? $op : min((float)$theory, $op);
                $fillB = min($hi, max($lo, $fillB));
            }

            $qty = ($fillB > 0) ? (int)floor(round((float)$c['buy_amount'] / $fillB, 6)) : 0;
            $amt = $fillB * $qty;
            $fee = pf_buy_cost($amt, $p);

            if ($qty > 0 && $amt + $fee <= $cash + 1e-6) {   // 예수금 부족이면 건너뛴다
                $cash    -= $amt + $fee;
                $trades[] = ['id' => ++$seq, 'side' => 'buy', 'step_no' => $step, 'traded_at' => $d, 'price' => $fillB, 'qty' => $qty];

                $act = ['d' => $d, 'side' => 'buy', 'step' => $step, 'price' => $fillB, 'qty' => $qty,
                        'amount' => $amt, 'fee' => $fee, 'cash' => $cash];
                $all[] = $act;

                if ($cycle === null) {
                    $cycle = ['no' => count($cycles) + 1, 'entry_date' => $d, 'entry_price' => $fillB,
                              'exit_date' => null, 'exit_price' => null, 'days' => null,
                              'max_step' => $step, 'buys' => 0, 'invested' => 0.0,
                              'realized_pl' => 0.0, 'return' => null];
                }
                $cycle['buys']++;
                $cycle['invested'] += $amt;
                $cycle['max_step']  = max($cycle['max_step'], $step);
                if ($step > $maxStep) $maxStep = $step;
            }
        } else {
            if ($cooldown > 0) $cooldown--;
        }

        // ── 체결이 있었으면 다시 계산해서 그 날 마감 상태를 찍는다
        if ($act !== null) {
            $led = pf_ledger($trades, $p);
            $c   = pf_position_calc($steps, pf_trades_by_step($trades), $limit, $px, $p, $led);
        }

        $held  = (int)$led['held_qty'];
        $asset = $cash + pf_net_value($held, $px, $p);
        $used  = (float)$c['used_amount'];
        if ($used > $maxUsed) $maxUsed = $used;

        if ($asset > $peak) $peak = $asset;
        $dd = ($peak > 0) ? $asset / $peak - 1 : 0.0;
        if ($dd < $mdd) $mdd = $dd;

        $equity[] = [
            'd'      => $d,
            'price'  => $px,
            'cash'   => $cash,
            'qty'    => $held,
            'avg'    => $led['avg_cost'],
            // 그 날 마감 시점에 걸려 있는 자동매도가 (보유 0 이면 null).
            // 가격이 아니라 누적단가·차수 목표수익률로만 정해지므로 판단가와 무관하다.
            'sell'   => $held > 0 ? $c['sell_price'] : null,
            'step'   => (int)$c['cur_step'],
            'eval'   => $held > 0 ? $px * $held : 0.0,
            'asset'  => $asset,
            'dd'     => $dd,
        ];
    }

    // 미청산 사이클도 결과에 넣는다 (평가손익 기준)
    $last     = end($prices);
    $lastPx   = (float)$last['c'];
    $ledEnd   = pf_ledger($trades, $p);
    $heldEnd  = (int)$ledEnd['held_qty'];
    $openCycle = null;

    if ($cycle !== null) {
        $cycle['days']        = pf_sim_days($cycle['entry_date'], $last['d']);
        $cycle['realized_pl'] = pf_net_value($heldEnd, $lastPx, $p) - (float)$ledEnd['cost_amount'];
        $cycle['return']      = ($cycle['invested'] > 0) ? $cycle['realized_pl'] / $cycle['invested'] : null;
        $cycle['open']        = true;
        $openCycle = $cycle;
    }

    $first    = reset($prices);
    $assetEnd = $equity ? (float)end($equity)['asset'] : $limit;
    $days     = pf_sim_days($first['d'], $last['d']);
    $years    = $days > 0 ? $days / 365.0 : 0.0;

    $closed = $cycles;
    $win    = 0;
    $sumDay = 0;
    foreach ($closed as $cy) {
        if ($cy['realized_pl'] > 0) $win++;
        $sumDay += (int)$cy['days'];
    }

    // ── 벤치마크: 시작일에 한도 전액으로 사서 끝까지 들고 있었다면
    $bhQty   = (int)floor($limit / (float)$first['c']);
    $bhAmt   = $bhQty * (float)$first['c'];
    $bhCash  = $limit - $bhAmt - pf_buy_cost($bhAmt, $p);
    $bhAsset = $bhCash + pf_net_value($bhQty, $lastPx, $p);

    return [
        'ok'          => true,
        'principal'   => $limit,
        'from'        => $first['d'],
        'to'          => $last['d'],
        'bars'        => count($prices),
        'days'        => $days,
        'first_price' => (float)$first['c'],
        'last_price'  => $lastPx,
        'price_return'=> ((float)$first['c'] > 0) ? $lastPx / (float)$first['c'] - 1 : null,

        'asset'       => $assetEnd,
        'total_pl'    => $assetEnd - $limit,
        'total_rate'  => ($limit > 0) ? $assetEnd / $limit - 1 : null,
        'cagr'        => ($years > 0.08 && $limit > 0 && $assetEnd > 0)
                            ? pow($assetEnd / $limit, 1 / $years) - 1 : null,
        'mdd'         => $mdd,

        'cash'        => $equity ? (float)end($equity)['cash'] : $limit,
        'held_qty'    => $heldEnd,
        'avg_cost'    => $ledEnd['avg_cost'],
        'eval_amount' => $heldEnd > 0 ? $lastPx * $heldEnd : 0.0,
        'eval_pl'     => $heldEnd > 0 ? $lastPx * $heldEnd - (float)$ledEnd['cost_amount'] : 0.0,
        'realized_pl' => $realizedTotal,

        'buy_count'   => count(array_filter($all, fn($t) => $t['side'] === 'buy')),
        'sell_count'  => count(array_filter($all, fn($t) => $t['side'] === 'sell')),
        'cycle_count' => count($closed),
        'win_count'   => $win,
        'win_rate'    => $closed ? $win / count($closed) : null,
        'avg_days'    => $closed ? $sumDay / count($closed) : null,
        'max_step'    => $maxStep,
        'max_used'    => $maxUsed,
        'max_used_rate' => ($limit > 0) ? $maxUsed / $limit : null,

        'bh_asset'    => $bhAsset,
        'bh_rate'     => ($limit > 0) ? $bhAsset / $limit - 1 : null,

        'cycles'      => $closed,
        'open_cycle'  => $openCycle,
        'trades'      => $all,
        'equity'      => $equity,
    ];
}

/**
 * 사이클 <b>전부</b> — 닫힌 것 + 미청산.
 *
 * ★ 미청산 사이클은 `cycles` 가 아니라 <b>`open_cycle` 에 따로</b> 담긴다.
 *   `cycles` 만 보면 「물린 사이클 0개」 라는 거짓 결론이 나온다(2026-07-30 사이클 분석에서
 *   실제로 그렇게 속았다 — 종목 19개가 물려 있는데 물림 0 으로 집계됐다). 합쳐 주는 자리를 하나로 둔다.
 */
function pf_sim_all_cycles(array $res): array
{
    $out = $res['cycles'] ?? [];
    if (!empty($res['open_cycle'])) $out[] = $res['open_cycle'];
    return $out;
}

/** 결과 없음 (입력 부족) */
function pf_sim_empty(): array
{
    return ['ok' => false, 'principal' => 0, 'bars' => 0, 'trades' => [], 'cycles' => [],
            'equity' => [], 'open_cycle' => null];
}

/** 두 날짜 사이 일수 */
function pf_sim_days(string $from, string $to): int
{
    $a = strtotime($from);
    $b = strtotime($to);
    if ($a === false || $b === false) return 0;
    return (int) round(($b - $a) / 86400);
}

/** 기간 필터 (빈 값이면 전체) */
function pf_sim_slice(array $rows, string $from = '', string $to = ''): array
{
    if ($from === '' && $to === '') return $rows;

    $out = [];
    foreach ($rows as $r) {
        if ($from !== '' && $r['d'] < $from) continue;
        if ($to   !== '' && $r['d'] > $to)   continue;
        $out[] = $r;
    }
    return $out;
}

// ── 조건 / 캐시 지문 ──────────────────────────────────────────────────
/**
 * 시뮬레이션 조건 결정.
 * 우선순위: $query(주소창) > 데이터에 저장된 마지막 조건 > 기본값.
 * $query 가 null 이면 저장된 조건만 쓴다 (목록·API 처럼 주소창이 없는 자리).
 *
 * 결과가 아니라 조건만 저장하므로 룰셋 내용이 바뀌면 수치는 자동으로 따라온다.
 */
function pf_sim_options(?array $data, array $rules, array $brokers, array $markets, ?array $query = null): array
{
    $q = function (string $k, $def) use ($query) {
        if ($query === null) return $def;
        return (isset($query[$k]) && $query[$k] !== '') ? $query[$k] : $def;
    };

    $rs = (int)$q('rs', (int)($data['rule_set_id'] ?? 0));
    if (!$rs && $rules) $rs = (int)$rules[0]['id'];

    $b = (int)$q('b', (int)($data['broker_id'] ?? 0));
    if (!$b && $brokers) $b = (int)$brokers[0]['id'];

    $mk = (string)$q('mk', (string)($data['market'] ?? ''));
    if ($mk === '' && $markets) $mk = (string)$markets[0]['code'];

    $limit = (float)preg_replace('/[^0-9]/', '', (string)$q('limit', (string)($data['limit_amt'] ?? '')));
    if ($limit <= 0) $limit = 10000000;

    // 체크박스는 "폼에서 왔는지"(go)로 판단해야 해제가 먹는다
    $fromForm = ($query !== null && isset($query['go']));
    $re = $fromForm ? isset($query['re'])       : ((int)($data['reenter']  ?? 1) === 1);
    $in = $fromForm ? isset($query['intraday']) : ((int)($data['intraday'] ?? 1) === 1);

    // 고가·저가가 없는 데이터는 장중 모드를 켤 수 없다
    if (empty($data['has_ohlc'])) $in = false;

    return [
        'rs'       => $rs,
        'b'        => $b,
        'mk'       => $mk,
        'limit'    => $limit,
        'from'     => (string)$q('from', (string)($data['opt_from'] ?? '')),
        'to'       => (string)$q('to',   (string)($data['opt_to']   ?? '')),
        're'       => $re,
        'wait'     => max(0, (int)$q('wait', (int)($data['wait_days'] ?? PF_SIM_WAIT_DEFAULT))),
        'intraday' => $in,
    ];
}

/**
 * 목록 캐시의 유효성 지문.
 *
 * 결과에 영향을 주는 것을 <b>빠짐없이</b> 넣어야 한다 —
 * 엔진 판번호 · 어느 종목의 어떤 시세인지 · 조건 전부 · 룰셋 단계 내용 · 수수료/세율.
 * 그래서 룰셋이나 증권사 수수료를 고치면 키가 달라져 캐시가 저절로 버려진다.
 * 엔진 코드를 고쳤을 때는 PF_SIM_VER 를 올려 주면 된다 (그것만이 코드로는 감지되지 않는다).
 *
 * 종목 식별자(id·code)와 시세 범위(bar_count·to_date)까지 넣는 이유:
 * 조건이 같은 두 종목이 같은 키를 갖는 걸 막고, 혹시 data_ver 가 안 올라간 채
 * 시세가 바뀌어도 걸리게 하기 위해서다.
 *
 * @param array $data pf_sim_data 행 (id·stock_code·data_ver·bar_count·to_date)
 */
function pf_sim_fingerprint(array $o, array $steps, array $p, array $data): string
{
    ksort($steps, SORT_NUMERIC);

    $cost = [
        $p['buy_cost_rate']  ?? null, $p['sell_cost_rate'] ?? null,
        $p['sell_fee_rate']  ?? null, $p['tax_rate']       ?? null,
        $p['tick']           ?? null, $p['fee_tiers']      ?? [],
    ];

    $src = [
        (int)   ($data['id']         ?? 0),
        (string)($data['stock_code'] ?? ''),
        (int)   ($data['data_ver']   ?? 0),
        (int)   ($data['bar_count']  ?? 0),
        (string)($data['to_date']    ?? ''),
    ];

    return md5(json_encode([PF_SIM_VER, $src, $o, $steps, $cost]));
}

/** 목록에 뿌릴 요약만 뽑아낸다 (캐시에 넣는 값) */
function pf_sim_summary(array $res): array
{
    if (empty($res['ok'])) return ['ok' => 0];

    return [
        'ok'   => 1,
        'rate' => round((float)$res['total_rate'], 6),
        'mdd'  => round((float)$res['mdd'], 6),
        'cyc'  => (int)$res['cycle_count'],
        'step' => (int)$res['max_step'],
        'cagr' => $res['cagr'] === null ? null : round((float)$res['cagr'], 6),
    ];
}
?>
