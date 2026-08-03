<?php
/**
 * PER·PBR 밴드 차트 — 계산 단일본 (2026-08-03 신설)
 *
 * 「주가 = 배수 × 주당지표」를 뒤집어, <b>이익·자본 × 배수</b> 선 다섯 개 위에 실제 시세를 얹는다.
 * 주가선이 어느 띠에 있느냐 = 그 종목이 <b>자기 역사 대비</b> 싼가 비싼가.
 * (배수 절대값은 종목마다 다르므로 회사 간 비교 도구가 아니다.)
 *
 * ★★ 단위는 「원(주당)」이 아니라 <b>시가총액</b>이다.
 *
 *   PER = 시총 ÷ 순이익(TTM)          밴드선(시총) = 순이익(TTM) × 배수
 *   PBR = 시총 ÷ 자본총계             밴드선(시총) = 자본총계   × 배수
 *
 *   주당으로 풀면 과거 5년치 <b>수정주가</b>가 필요한데 우리에겐 없다. 시총으로 두면 주식수가
 *   식에서 통째로 사라지고 <b>액면분할이 저절로 무해</b>해진다 — 분할은 시총을 바꾸지 않기 때문이다.
 *   실측(대한제분 001130): 2026-05-18 10:1 분할로 주가는 143,000 → 14,300 으로 절벽이 생기지만
 *   시총은 2,447억 → 2,417억으로 <b>매끄럽게 이어진다</b>.
 *   화면에 「원」으로 보이고 싶으면 표시 직전에만 ÷ 상장주식수 한다(계산이 아니라 단위 환산이라
 *   밴드와 주가선이 같은 비율로 움직여 그림이 안 깨진다).
 *
 * ★★★ 계단이 꺾이는 날은 <b>결산기가 아니라 공시일</b>이다.
 *
 *   남들은 밴드를 결산기에 맞춰 매끄럽게 잇는다. 그러면 3월에 나온 사업보고서의 이익이
 *   전년 12월 자리에 이미 반영된 그림이 되어 <b>선견 편향</b>이 된다.
 *   이 레포는 그 문제를 이미 한 번 풀었다 — 종목상세의 「공시일」 칸과 차트의 ▲▼ SUE 마커가
 *   모두 DART 접수일(원본 MIN)을 본다. 밴드도 <b>같은 날</b>에 계단으로 꺾인다.
 *   그래서 계단이 오르는 날 = ▲어닝서프라이즈 마커가 찍힌 그날이다.
 *
 * 비율 무저장 원칙 그대로 — 아무것도 저장하지 않는다. 재무·시세가 갱신되면 밴드가 저절로 따라온다.
 */

/** 밴드 배수를 뽑을 분위수 — 관례값이다(§ 아래 주석). */
const PF_BAND_PCTS = [10, 30, 50, 70, 90];

/** 유효 표본이 이 비율 미만이면 그 밴드를 아예 그리지 않는다 (띄엄띄엄한 밴드는 사람을 속인다) */
const PF_BAND_MIN_COVER = 0.60;

/**
 * 계단 값이 이보다 오래되면 <b>선을 끊는다</b> (일).
 *
 * ★★ 이게 없으면 조용히 거짓말을 한다. 실측(현대차 005380): `stock_financial.net_income` 이
 *   2018.3Q~2023.4Q 구간 통째로 NULL 이라(매출·영업이익은 정상) TTM 이 만들어지지 않는데,
 *   그냥 두면 <b>2018년 2분기의 이익이 2024년 말까지 6년간 유효한 값인 척</b> 이어져
 *   그 구간 PER 밴드가 완전한 허구가 된다. 화면에는 아무 이상이 없어 보인다 — 그래서 더 나쁘다.
 *
 * 400일 = 4분기(약 365일) + 늦게 내는 회사·비12월 결산 여유. 정상이면 최대 95일이면 갱신된다.
 */
const PF_BAND_MAX_AGE = 400;

/**
 * 분위수 — 선형보간. $sorted 는 오름차순이어야 한다.
 *
 * ★ min/max 를 쓰지 않는 이유: 실적이 바닥일 때 PER 은 수백 배까지 튄다.
 *   적자 직전 분기 하루의 이상치가 밴드 전체를 밖으로 밀어낸다.
 */
function pf_band_quantile(array $sorted, float $p): float
{
    $n = count($sorted);
    if ($n === 0) return 0.0;
    if ($n === 1) return (float)$sorted[0];
    $i = ($n - 1) * ($p / 100);
    $lo = (int)floor($i);
    $hi = (int)ceil($i);
    if ($lo === $hi) return (float)$sorted[$lo];
    return $sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * ($i - $lo);
}

/**
 * 이 종목의 정기공시 접수일 — [(연도*4+분기) => 'YYYY-MM-DD'].
 *
 * `pf_sue_marks()` 와 <b>같은 원장·같은 규칙</b>(원본 MIN — [기재정정] 재접수는 무시)이다.
 * 여기서 따로 읽는 이유는 저쪽이 ±1 밖 공시만 골라 내보내기 때문이다. 밴드는 <b>모든 분기</b>가 필요하다.
 */
function pf_band_filings(PDO $pdo, string $code): array
{
    try {
        if (!$pdo->query("SHOW TABLES LIKE 'dart_rcept'")->fetchColumn()) return [];
    } catch (Throwable $e) { return []; }

    $st = $pdo->prepare("
        SELECT bsns_year, reprt_code, MIN(rcept_dt) dt
          FROM dart_rcept
         WHERE stock_code = ? AND reprt_code IS NOT NULL
         GROUP BY bsns_year, reprt_code");
    $st->execute([$code]);

    $qNoMap = ['11013' => 1, '11012' => 2, '11014' => 3, '11011' => 4];
    $out = [];
    foreach ($st as $r) {
        $qNo = $qNoMap[$r['reprt_code']] ?? null;
        if ($qNo === null) continue;          // 비12월 결산이라 분기를 가릴 수 없는 공시
        $out[(int)$r['bsns_year'] * 4 + $qNo] = (string)$r['dt'];
    }
    return $out;
}

/**
 * 밴드 차트 한 종목분.
 *
 * @return array{
 *   ok:bool, code:string, from:string, to:string, shrs:float,
 *   px:array,            // [['t'=>'YYYY-MM-DD','v'=>시총(원)], …] 주 단위
 *   per:array, pbr:array,// ['ok'=>, 'mult'=>[5], 'step'=>[['t'=>,'v'=>], …], 'n'=>, 'cover'=>]
 *   note:string[]
 * }
 */
function pf_band_series(PDO $pdo, string $code, int $years = 5): array
{
    $code = preg_replace('/[^0-9A-Za-z]/', '', $code);
    $note = [];
    $out  = ['ok' => false, 'code' => $code, 'from' => '', 'to' => '', 'shrs' => 0.0,
             'px' => [], 'per' => null, 'pbr' => null, 'note' => $note];

    /* ── ① 시총 시계열 ──────────────────────────────────────────────
     * krx_amt 는 전종목 일별 원장이다(2019-01-02~, 471만행). mktcap 결측 0건을 실측했다.
     * ★ all_stock_info(utf8mb3)를 여기 끌어들이지 않는다 — 콜레이션 불일치로 조인이 인덱스를
     *   못 타 15배 느려진 사고가 이 레포에 이미 있었다. krx_amt 는 CHAR(6) 이라 안전하다. */
    $st = $pdo->prepare("
        SELECT d, mktcap, list_shrs
          FROM krx_amt
         WHERE code = ? AND d >= DATE_SUB(CURDATE(), INTERVAL ? YEAR) AND mktcap > 0
         ORDER BY d");
    $st->execute([$code, max(1, min(7, $years))]);
    $daily = $st->fetchAll(PDO::FETCH_ASSOC);
    if (count($daily) < 60) {                       // 3개월도 안 되면 밴드가 뜻을 갖지 못한다
        $out['note'] = ['시세 원장이 부족합니다 (' . count($daily) . '거래일).'];
        return $out;
    }

    $out['from'] = (string)$daily[0]['d'];
    $out['to']   = (string)$daily[count($daily) - 1]['d'];

    /* 단위 환산용 상장주식수 — 오늘 행은 네이버 잠정치라 list_shrs 가 비어 있다(실측).
     * 뒤에서부터 훑어 가장 최근의 실제 값을 쓴다. */
    for ($i = count($daily) - 1; $i >= 0; $i--) {
        if ((float)$daily[$i]['list_shrs'] > 0) { $out['shrs'] = (float)$daily[$i]['list_shrs']; break; }
    }

    /* ── ② 분기 재무 → TTM 순이익 · 자본총계 ────────────────────────
     * 저장은 누적(YTD) 하나로 통일돼 있으므로 Dart::quarterly() 로 당분기를 뽑아 4개를 더한다.
     * ★ 자본총계는 그 분기말 <b>시점값</b>이라 더하지 않는다(BS). */
    $st = $pdo->prepare("SELECT * FROM stock_financial WHERE stock_code = ? ORDER BY bsns_year");
    $st->execute([$code]);
    $fin = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$fin) { $out['note'] = ['재무제표가 없습니다.']; return $out; }

    $qs = Dart::quarterly($fin);                    // 최근순 [{bsns_year,quarter,net_income,equity_total}, …]
    if (count($qs) < 5) { $out['note'] = ['분기 재무가 부족합니다.']; return $out; }

    /* TTM = 그 분기 포함 최근 4개 당분기 합. 하나라도 비면 만들지 않는다(0 으로 채우면 적자로 읽힌다).
     *
     * ★★ 배열 순서로 `$qs[$i+1]` 을 집으면 안 된다 — 빠진 분기가 있으면 조용히 <b>다섯 분기 전</b>을
     *   더하게 되고, 화면에는 그냥 「이익이 큰 회사」로 보인다. 반드시 (연도·분기) 키로 짚는다.
     *   실측: 이 실수로 현대차 계단이 21회가 아니라 7회만 생겼다(2021~24 분기가 통째로 누락). */
    $byQ = [];
    foreach ($qs as $q) $byQ[(int)$q['bsns_year'] * 4 + (int)$q['quarter']] = $q;

    $ttm = [];
    foreach ($byQ as $qk => $q) {
        $sum = 0.0; $ok = true;
        for ($k = 0; $k < 4; $k++) {                 // qk, qk−1, qk−2, qk−3 = 직전 4분기
            $v = $byQ[$qk - $k]['net_income'] ?? null;
            if ($v === null) { $ok = false; break; }
            $sum += (float)$v;
        }
        $ttm[] = ['y' => (int)$q['bsns_year'], 'q' => (int)$q['quarter'],
                  'ni' => $ok ? $sum : null,
                  'eq' => $q['equity_total'] === null ? null : (float)$q['equity_total']];
    }

    /* ── ③ 계단 — 값이 바뀌는 날 = 공시 <b>다음 거래일</b> ─────────────
     * 접수일이 없는 분기는 계단을 만들지 않는다(앞 값이 그대로 이어진다).
     * 비12월 결산 회사에서 실제로 발생한다 — reprt_code 로 분기를 가릴 수 없어 원장이 건너뛴다. */
    $fil  = pf_band_filings($pdo, $code);
    $days = array_column($daily, 'd');               // 거래일 목록 (오름차순)

    $stepNi = []; $stepEq = [];
    foreach ($ttm as $t) {
        $dt = $fil[$t['y'] * 4 + $t['q']] ?? null;
        if ($dt === null) continue;
        // 공시 다음 거래일로 스냅 — 차트의 ▲▼ 마커와 같은 규칙(백테스트의 매수 시점 그대로)
        $at = null;
        foreach ($days as $d) { if ($d > $dt) { $at = $d; break; } }
        if ($at === null) continue;                  // 아직 시세가 안 따라온 공시(오늘 이후)
        if ($t['ni'] !== null) $stepNi[$at] = $t['ni'];
        if ($t['eq'] !== null) $stepEq[$at] = $t['eq'];
    }
    ksort($stepNi); ksort($stepEq);

    if (!$stepNi && !$stepEq) { $out['note'] = ['공시 접수일 원장이 없어 계단을 만들 수 없습니다.']; return $out; }

    /* ── ④ 주가선은 주 단위로 줄인다 ────────────────────────────────
     * 5년이면 1,200거래일인데 밴드는 분기마다 한 번 꺾이는 계단이라 일별 해상도가 필요 없다.
     * 각 주의 <b>마지막</b> 거래일을 남긴다 — 배열 끝이 곧 최신 거래일이라 오른쪽 끝이 오늘이 된다.
     *
     * ★ 다만 <b>계단이 꺾이는 날은 정확한 그 날짜로</b> 끼워 넣는다.
     *   주 단위로만 줄이면 전환일이 그 주 금요일까지 최대 4일 밀려, 「▲ 마커와 같은 날 꺾인다」는
     *   이 화면의 주장이 눈으로 어긋난다(실측: 삼성 26.1Q 공시 5/15 → 계단이 5/22 에 섰다). */
    $capAt = [];
    foreach ($daily as $r) $capAt[(string)$r['d']] = (float)$r['mktcap'];

    $keep = [];
    foreach ($daily as $r) $keep[date('oW', strtotime((string)$r['d']))] = (string)$r['d'];
    $dates = array_values($keep);
    foreach (array_merge(array_keys($stepNi), array_keys($stepEq)) as $d) $dates[] = $d;
    $dates = array_unique($dates);
    sort($dates);

    $out['px'] = [];
    foreach ($dates as $d) if (isset($capAt[$d])) $out['px'][] = ['t' => $d, 'v' => $capAt[$d]];

    /* ── ⑤ 「그날 유효한 값」 붙이기 + 배수 표본 ─────────────────────
     * ★ 배수 분위수는 <b>일별</b> 표본에서 낸다(표본이 5배 많아 흔들리지 않는다).
     *   내보내는 계단은 <b>주별</b>이다 — 주가선과 x 축을 맞춰야 화면에서 그대로 겹쳐 그린다. */
    /* 값이 없는 이유는 셋이고 대응이 다르다 — 뭉뚱그리면 화면이 엉뚱한 설명을 한다.
     *   early = 첫 공시 이전 (그 종목의 재무 이력이 짧다)
     *   stale = 계단이 400일 넘게 안 바뀜 (수집 구멍 — 현대차 사례)
     *   neg   = 값이 0 이하 (적자·자본잠식)                                   */
    $walk = function (array $step, array $pts) {
        $keys = array_keys($step);
        $vals = array_values($step);
        $n = count($keys);
        $idx = -1;
        $res = []; $why = ['early' => 0, 'stale' => 0, 'neg' => 0];
        foreach ($pts as $p) {
            while ($idx + 1 < $n && $keys[$idx + 1] <= $p['t']) $idx++;
            if ($idx < 0) { $res[] = null; $why['early']++; continue; }
            // 묵은 값으로 선을 이어 그리지 않는다 (PF_BAND_MAX_AGE 주석의 현대차 사례)
            if ((strtotime($p['t']) - strtotime($keys[$idx])) / 86400 > PF_BAND_MAX_AGE) {
                $res[] = null; $why['stale']++; continue;
            }
            if ($vals[$idx] <= 0) { $res[] = null; $why['neg']++; continue; }
            $res[] = $vals[$idx];
        }
        return [$res, $why];
    };

    $dailyPts = [];
    foreach ($daily as $r) $dailyPts[] = ['t' => (string)$r['d'], 'v' => (float)$r['mktcap']];

    $band = function (array $step, string $label) use ($walk, $dailyPts, $out) {
        $empty = ['ok' => false, 'why' => '', 'mult' => [], 'step' => [], 'n' => 0, 'cover' => 0.0];
        if (!$step) return ['why' => $label . ' 계단을 만들 수 없습니다 (공시 접수일 없음)'] + $empty;

        // 배수 표본 = 일별
        [$vd, $why] = $walk($step, $dailyPts);
        $mult = [];
        foreach ($dailyPts as $i => $p) if ($vd[$i] !== null) $mult[] = $p['v'] / $vd[$i];
        $cover = count($dailyPts) > 0 ? count($mult) / count($dailyPts) : 0.0;

        // 빈 구간이 왜 비었는지 — 가장 많은 사유 하나로 말한다
        $miss = round((1 - $cover) * 100);
        arsort($why);
        $top  = array_key_first($why);
        $txt  = ['early' => '그 이전에는 분기 재무가 없습니다',
                 'stale' => $label . ' 이 오래 갱신되지 않았습니다 (DART 수집 구멍)',
                 'neg'   => $label . ' 이 0 이하입니다 (적자·자본잠식)'][$top] ?? '';

        if (!$mult) return ['why' => '밴드를 만들 수 없습니다 — ' . $txt] + $empty;

        sort($mult);
        $ms = [];
        foreach (PF_BAND_PCTS as $p) $ms[] = round(pf_band_quantile($mult, (float)$p), 2);
        $ms = array_values(array_unique($ms));       // 표본이 좁으면 분위수가 겹친다

        // 내보내는 계단 = 주별. 값이 없는 구간은 null → 화면이 선을 <b>끊는다</b>
        [$vw] = $walk($step, $out['px']);
        $rows = [];
        foreach ($out['px'] as $i => $p) $rows[] = ['t' => $p['t'], 'v' => $vw[$i]];

        $ok = $cover >= PF_BAND_MIN_COVER;
        return [
            'ok'    => $ok,
            'why'   => $ok ? '' : "구간의 {$miss}% 가 비어 밴드를 그리지 않습니다 — " . $txt,
            'mult'  => $ms,
            'step'  => $rows,
            'n'     => count($mult),
            'cover' => round($cover, 3),
            'gap'   => $why,
        ];
    };

    $out['per'] = $band($stepNi, '순이익(TTM)');
    $out['pbr'] = $band($stepEq, '자본총계');

    $out['ok']   = ($out['per']['ok'] ?? false) || ($out['pbr']['ok'] ?? false);
    $out['note'] = $note;
    return $out;
}
?>
