<?php
/**
 * stock/lib/quant.php — 목록 <b>퀀트 배지 판정 단일본</b>
 *
 * 왜 여기 있나
 *   원래는 `stock_analysis_api.php` 의 `quant_augment()` 안에만 있었다. 2026-08-05 에
 *   단타(mode=short) 「내 목록」도 <b>같은 배지</b>를 보이게 되면서, 그대로 두면 같은 판정이
 *   두 파일에 살게 된다 — 임계 하나를 고칠 때 한 화면만 조용히 옛말을 하게 된다.
 *   그래서 판정을 여기로 옮기고 두 소비자가 나눠 쓴다.
 *
 * 소비자
 *   - 단타 「상위 종목」 목록 : stock_analysis_api.php  action=top30
 *   - 단타 「내 목록」        : stock/api.php           module=dt&action=pool_list
 *   그리는 쪽(HTML·CSS)의 단일본은 `style/quantbadge.js` 다 — 어휘·색을 화면에 다시 적지 않는다.
 *
 * ★판정은 여기서만 내린다. 화면은 「무엇을 보여 줄지」만 고른다.
 */

/**
 * 종목별 퀀트 배지(q)를 한 번에 만든다 — 종목 수와 무관하게 쿼리 2회.
 *
 * @param array $items [code => ['price'=>현재가, 'rate'=>등락률(%), 'amtEok'=>오늘 거래대금(억)]]
 * @return array [code => q|null]   q = ['t','cls','tip','hot','mom'=>[…],'bx'=>…]
 *
 *   t/cls/tip : 오늘 거래대금이 직전 120거래일 최고를 넘었을 때만 — 유형 판정
 *               (임계·어휘 = stock/index.php pf_surge_badge 와 동일:
 *                불꽃형 등락≥20% 또는 20평비≥20 → 매집형 20평비≤5∧등락0~10% → 중립.
 *                나쁜 쪽 우선. 장중 거래대금은 하한이라 매집형→불꽃형으로 마감에 바뀔 수 있어 '잠정' 명시)
 *   mom       : 20·40거래일 모멘텀 (KrxAmt::MOM_HOT20/40 · MOM_COLD20/40)
 *   hot       : mom 이 하나라도 있으면 1 (옛 소비자 호환)
 *   bx        : 최근 최고 거래대금 신호(krx_surge)의 박스 상태 (boxStatusMany — 어닝 탭과 동일 재사용)
 *
 * ★역사: 이 판정 자리에 있던 흰칩('60봉 신고가+거래량 2배'·정적 승률 78/82/90%)은 2026-08-02 폐기.
 *   정적 추정이 실측(칼리브레이션)과 어긋났고, 전략 자체가 퀀트 백테스트에서 기각된 계열이다.
 */
function quant_badge_many(PDO $pdo, array $items): array
{
    $codes = [];
    foreach (array_keys($items) as $c) { $c = (string)$c; if ($c !== '') $codes[] = $c; }
    if (!$codes) return [];
    $in = implode(',', array_fill(0, count($codes), '?'));

    // ① 과거 원장(오늘 제외 — 오늘 잠정행 src='n' 이 15:50 이후 있을 수 있다) 최근 120거래일
    $st = $pdo->prepare("SELECT code, d, c, amt FROM krx_amt
                          WHERE code IN ($in) AND d < CURDATE()
                            AND d >= DATE_SUB(CURDATE(), INTERVAL 200 DAY)
                            AND amt > 0 AND c > 0
                          ORDER BY code, d");
    $st->execute($codes);
    $hist = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $hist[$r['code']][] = $r;

    // ② 최근 최고 거래대금 신호의 박스 상태 (krx_surge 미구축이면 조용히 생략)
    $bx = [];
    try {
        $sg = $pdo->prepare("SELECT code, MAX(d) d FROM krx_surge WHERE code IN ($in) GROUP BY code");
        $sg->execute($codes);
        $sigs = [];
        foreach ($sg->fetchAll(PDO::FETCH_ASSOC) as $r) $sigs[] = ['code' => $r['code'], 'd' => $r['d']];
        if ($sigs) {
            $bxAll = (new KrxAmt($pdo))->boxStatusMany($sigs);
            foreach ($sigs as $s) {
                $b = $bxAll[$s['code'] . '|' . $s['d']] ?? null;
                /* ★'bx-na' = 박스 상향돌파(5조건) 박스가 아님(2026-08-10 재정의 게이트) —
                 *   좁은 목록(단타 사이드바)에 '-' 칩을 늘어놓지 않고 조용히 생략한다 */
                if ($b && $b['st'] !== 'bx-na') $bx[$s['code']] = ['st' => $b['st'], 'txt' => $b['txt'],
                    'tip' => '최고 거래대금 신호일 ' . $s['d'] . ' — ' . $b['tip']];
            }
        }
    } catch (Throwable $e) { /* 박스 없이 계속 */ }

    /* ── ⚡장중 «잠정» 신박스 (2026-08-10 사용자 지시) ──────────────────────
     *
     * 위 ②의 박스 배지는 `bx_cand`(16:20 적재)를 문지기로 쓰므로 <b>오늘 생긴 박스는 장중 내내
     * 안 보인다</b>(그 전엔 `krx_surge` 에도 없다 — 15:50 `dart_eod` 가 만든다).
     * 그래서 마감 뒤에야 「新박스」가 뜨는데, 그때는 이미 그 날이 끝나 있다.
     *
     * ★<b>판정을 여기서 새로 내리지 않는다</b> — `boxbrk_live()` 단일본이 잰 것을 가져다 «고르기만»
     *   한다(단타 규칙 9 와 같은 자리). 캐시는 분당 한 콜로 묶여 있다.
     * ★<b>5조건을 다 통과한 것만</b> 단다 — 「새 박스만 생긴 것」까지 달면 확정 배지와 뜻이 갈린다
     *   (재정의 이후 「新박스」 자체가 5조건 박스만 가리킨다).
     * ★옛 신호의 확정 배지를 <b>덮지 않는다</b> — 오늘 것이 있을 때만 갈아 끼운다(그게 더 최근이다).
     * ★뜻은 <b>잠정</b>이다 — 마감까지 ④(저가)·⑤(시가→종가)가 뒤집힐 수 있고, 16:20 적재가
     *   확정 여부를 갱신한다. 그래서 색도 어휘도 확정과 다르게 둔다. */
    try {
        require_once __DIR__ . '/boxbrk.php';
        $lv = boxbrk_live_cached($pdo);
        foreach ($lv['rows'] as $r) {
            $c = (string)$r['code'];
            if (!isset($items[$c])) continue;
            $bx[$c] = ['st' => 'bx-live', 'txt' => '⚡신박스 ' . $r['grade'],
                       'tip' => '오늘 박스 상향돌파 5조건을 «지금» 만족합니다 (등급 ' . $r['grade']
                              . ' · 시가→현재 ' . sprintf('%+.1f%%', (float)$r['ocPct']) . ')'
                              . ' — ★잠정입니다. 고가·저가·현재가가 마감까지 바뀌면 뒤집힐 수 있고,'
                              . ' 16:20 적재가 확정 여부를 갱신합니다.'];
        }
    } catch (Throwable $e) { /* 잠정 배지 없이 계속 — 곁들이는 것이라 목록을 죽이지 않는다 */ }

    $out = [];
    foreach ($items as $code => $it) {
        $code = (string)$code;
        $h = $hist[$code] ?? [];
        $n = count($h);
        $q = ['t' => '', 'cls' => '', 'tip' => '', 'hot' => 0, 'mom' => [], 'bx' => $bx[$code] ?? null];

        /* 20·40거래일 모멘텀 — 오늘 현재가 vs 20/40거래일 전 종가 (퀀트 momMany 와 같은 임계).
         * ★ 창별·방향별로 나눠 보낸다(2026-08-02) — 화면은 「20일 +112%」처럼 기간+부호%로 그린다.
         *   급등은 실측 근거가 있어 경고색, 급락은 근거가 없어 정보색(로그 대칭 임계일 뿐). */
        $price = (float)($it['price'] ?? 0);
        if ($price > 0 && $n >= 20) {
            $c20 = (float)$h[$n - 20]['c'];
            $m20 = $c20 > 0 ? $price / $c20 - 1 : null;
            $m40 = null;
            if ($n >= 40) { $c40 = (float)$h[$n - 40]['c']; if ($c40 > 0) $m40 = $price / $c40 - 1; }
            foreach ([[20, $m20], [40, $m40]] as [$w, $m]) {
                if ($m === null) continue;
                $hi = ($w === 20) ? KrxAmt::MOM_HOT20  : KrxAmt::MOM_HOT40;
                $lo = ($w === 20) ? KrxAmt::MOM_COLD20 : KrxAmt::MOM_COLD40;
                if ($m >= $hi)      $q['mom'][] = ['w' => $w, 'v' => round($m * 100), 'hot' => 1];
                elseif ($m <= $lo)  $q['mom'][] = ['w' => $w, 'v' => round($m * 100), 'hot' => 0];
            }
            if ($q['mom']) $q['hot'] = 1;   // 옛 소비자 호환 (합집합)
        }

        // 유형 — 오늘 거래대금이 직전 120거래일 최고를 넘었을 때만 (그 외엔 배지 없음)
        $todayAmt = (float)($it['amtEok'] ?? 0) * 1e8;   // stock_vol_cap = 억원
        if ($n >= 40 && $todayAmt > 0) {
            $win = array_slice($h, -120);
            $maxAmt = 0.0;
            foreach ($win as $b) if ((float)$b['amt'] > $maxAmt) $maxAmt = (float)$b['amt'];
            if ($maxAmt > 0 && $todayAmt >= $maxAmt) {
                $a20 = array_slice($h, -20);
                $sum = 0.0;
                foreach ($a20 as $b) $sum += (float)$b['amt'];
                $mul = $sum > 0 ? $todayAmt / ($sum / count($a20)) : null;
                $chg = (float)($it['rate'] ?? 0) / 100;    // stock_rate 는 % 단위
                /* 불꽃형 = 옛 폭발형+추격주의 (2026-08-02 통합 · 어느 조건이 걸렸는지는 툴팁에 남긴다).
                 * 중립도 배지로 그린다 — 빈 칸은 「중립」과 「신호 없음」을 구별하지 못한다. */
                if ($chg >= 0.20)                                { $cls = 'flame'; $t = '불꽃형';
                    $why = '신호일 등락 +20% 이상 폭등 — 실측 +20일 초과수익 중앙 -7.23% · 승률 34.6%'; }
                elseif ($mul !== null && $mul >= 20)             { $cls = 'flame'; $t = '불꽃형';
                    $why = '거래대금이 20일 평균의 20배 이상 폭발 — 실측 중앙 -4.24% · 승률 36.7%'; }
                elseif ($mul !== null && $mul <= 5 && $chg >= 0 && $chg < 0.10) { $cls = 'acc'; $t = '🟢매집형';
                    $why = '20일 평균의 5배 이하 + 등락 0~10%로 조용히 차오른 최고 거래대금 — 실측 중앙 +1.74% · 승률 55.2%'; }
                else                                             { $cls = 'neu';   $t = '중립';
                    $why = '실측상 우위도 열위도 뚜렷하지 않은 구간 — 중립×돌파도 동전(-1.23% · 승률 47.5%)'; }
                $q['t'] = $t; $q['cls'] = $cls;
                $q['tip'] = '오늘 거래대금 ' . number_format($todayAmt / 1e8) . '억 = 직전 120거래일 최고('
                    . number_format($maxAmt / 1e8) . '억) 이상'
                    . ($mul !== null ? ' · 20일 평균의 ' . round($mul, 1) . '배' : '')
                    . ' — ' . $why . ' (장중엔 잠정 · 마감 후 확정)';
            }
        }
        $out[$code] = ($q['t'] === '' && !$q['hot'] && $q['bx'] === null) ? null : $q;
    }
    return $out;
}
?>
