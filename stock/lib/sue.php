<?php
/* ════════════════════════════════════════════════════════════════════════
 * SUE(표준화 이익 서프라이즈) — 계산 단일본
 *
 * 원래 stock/index.php 에 있었으나(주석: "두 번째 소비자가 생기면 그때 옮긴다")
 * 2026-08-02 알림 크론(cron/dart_collect.php → lib/alert.php)이 두 번째 소비자가 되어
 * 여기로 옮겼다. ★index.php 와 크론이 같은 정의를 읽어야 화면과 알림이 딴소리를 안 한다.
 *
 * 정의(백테스트와 동일 · Foster 1977): SUE = (당분기 영업이익 − 전년동기) ÷ σ(과거 최대 8개 ΔE, 최소 4개)
 * 당분기 = YTD 뺄셈(Q1=1Q누적 · Q2=반기−1Q · Q3=3Q−반기 · Q4=연간−3Q) · fs_div 는 CFS(연결) 우선.
 * 소비자: 스크리너 SUE 열 · 어닝 탭 · 재무상세 공시 마커 · 관심종목 관제탑 · 보유 어닝쇼크 경보 · 알림 크론.
 * 2026-08-05 어닝 <b>목록</b>(pf_earn_rows)도 여기로 — 알림 ⑥ 이 화면과 같은 「규칙 충족」을 봐야 했다.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * SUE 상세 맵 — 기준 (연도×보고서)가 가리키는 당분기의 전종목 SUE + 규칙 재료.
 * [stock_code => ['sue','rev_up','quality','ni_op']]
 *
 * 백테스트(8년 · 6만 이벤트 · 시장중앙 대비): 5분위 단조 · 효과는 상위 20%에 집중 ·
 * 품질(YTD 영업흑자 ∧ |순익|÷영익≤1.5)·매출동반 없으면 무효 · 쇼크(하위)는 회피 목록.
 * ★비율 무저장 원칙 — 재무를 다시 받으면 값도 따라온다. 전종목 한 번에 계산(수십 ms).
 */
function pf_sue_build(PDO $pdo, int $year, string $reprt): array
{
    $qNo = ['11013' => 1, '11012' => 2, '11014' => 3, '11011' => 4][$reprt] ?? null;
    if ($qNo === null) return [];
    $qk = $year * 4 + $qNo;

    // 당분기 = YTD 뺄셈이라 과거 12분기(σ 이력 8 + 전년동기 4)까지 거슬러 읽는다
    $st = $pdo->prepare("
        SELECT stock_code, bsns_year, reprt_code, fs_div, revenue, op_income, net_income
          FROM stock_financial
         WHERE bsns_year BETWEEN ? AND ? AND op_income IS NOT NULL
         ORDER BY stock_code, bsns_year, reprt_code, fs_div");
    $st->execute([$year - 4, $year]);

    $ytd = [];   // [code][year][reprt] = ['fs','rev','op','ni'] — 연결(CFS) 우선, 백테스트와 같은 규칙
    foreach ($st as $r) {
        $c = $r['stock_code']; $y = (int)$r['bsns_year']; $rc = $r['reprt_code'];
        if (isset($ytd[$c][$y][$rc]) && ($ytd[$c][$y][$rc]['fs'] === 'CFS' || $r['fs_div'] !== 'CFS')) continue;
        $ytd[$c][$y][$rc] = ['fs' => $r['fs_div'],
            'rev' => $r['revenue'] !== null ? (float)$r['revenue'] : null,
            'op'  => (float)$r['op_income'],
            'ni'  => $r['net_income'] !== null ? (float)$r['net_income'] : null];
    }

    $map = [];
    foreach ($ytd as $c => $ys) {
        $qv = pf_sue_qv($ys, 'op');    // 당분기: Q1=1Q누적 · Q2=반기−1Q · Q3=3Q−반기 · Q4=연간−3Q
        $rv = pf_sue_qv($ys, 'rev');
        if (!isset($qv[$qk], $qv[$qk - 4])) continue;
        $hist = [];
        for ($i = 1; $i <= Thr::SUE_HIST_MAX; $i++) {
            if (isset($qv[$qk - $i], $qv[$qk - $i - 4])) $hist[] = $qv[$qk - $i] - $qv[$qk - $i - 4];
        }
        if (count($hist) < Thr::SUE_HIST_MIN) continue;   // 이력 부족이면 값 없음 — 0 으로 채우면 "서프라이즈 없음"으로 잘못 읽힌다
        $m = array_sum($hist) / count($hist);
        $var = 0.0;
        foreach ($hist as $h) $var += ($h - $m) ** 2;
        $sd = sqrt($var / count($hist));
        if ($sd <= 0) continue;

        // 품질·매출동반은 백테스트 규칙 그대로 — 품질 = 그 보고서 YTD 영업흑자 ∧ |순익|÷영익 ≤ 1.5
        $yr = $ytd[$c][$year][$reprt] ?? null;
        $map[$c] = [
            'sue'     => ($qv[$qk] - $qv[$qk - 4]) / $sd,
            'rev_up'  => (isset($rv[$qk], $rv[$qk - 4]) && $rv[$qk] !== null && $rv[$qk - 4] !== null)
                            ? ($rv[$qk] > $rv[$qk - 4]) : null,
            'quality' => ($yr !== null && $yr['op'] > 0 && $yr['ni'] !== null)
                            ? (abs($yr['ni']) / $yr['op'] <= Thr::SUE_QUALITY_NI_OP) : false,   // M5 — 임계 정본 Thr
            'ni_op'   => ($yr !== null && $yr['op'] > 0 && $yr['ni'] !== null) ? $yr['ni'] / $yr['op'] : null,
        ];
    }
    return $map;
}

/** 스크리너용 — SUE 값만 [code => float] */
function pf_sue_map(PDO $pdo, int $year, string $reprt): array
{
    return array_map(fn($v) => $v['sue'], pf_sue_build($pdo, $year, $reprt));
}

/**
 * YTD 보고서 4벌(1Q·반기·3Q·연간)에서 <b>그 분기 3개월</b> 값을 만든다 [연도*4+분기 => 값].
 * Q1=1Q누적 · Q2=반기−1Q · Q3=3Q−반기 · Q4=연간−3Q. 앞 보고서가 없으면 그 분기는 만들지 않고,
 * 값이 한쪽이라도 null 이면(매출 미신고) null — 소비자의 isset() 검사에서 자연히 빠진다.
 */
function pf_sue_qv(array $ys, string $key): array
{
    $qv = [];
    foreach ($ys as $y => $rc) {
        $q1 = $rc['11013'] ?? null; $q2 = $rc['11012'] ?? null;
        $q3 = $rc['11014'] ?? null; $q4 = $rc['11011'] ?? null;
        if ($q1) $qv[$y * 4 + 1] = $q1[$key];
        if ($q2 && $q1) {
            $qv[$y * 4 + 2] = ($q2[$key] !== null && $q1[$key] !== null) ? $q2[$key] - $q1[$key] : null;
        }
        if ($q3 && $q2) {
            $qv[$y * 4 + 3] = ($q3[$key] !== null && $q2[$key] !== null) ? $q3[$key] - $q2[$key] : null;
        }
        if ($q4 && $q3) {
            $qv[$y * 4 + 4] = ($q4[$key] !== null && $q3[$key] !== null) ? $q4[$key] - $q3[$key] : null;
        }
    }
    return $qv;
}

/**
 * 종목 하나의 분기별 SUE 시계열 [연도*4+분기 => sue] — 정의는 pf_sue_build 와 동일한 단일 종목판
 * (CFS 우선 · σ 이력 8개 최소 4개 · pf_sue_qv 공용). 소비자는 재무상세 차트의 공시 마커 ·
 * 관심종목 관제탑 · 보유 어닝쇼크 경보. 전종목판을 분기 수만큼 돌리면 수백 ms 라 종목 하나만
 * 읽는 판을 따로 둔다 (1~2ms).
 */
function pf_sue_stock(PDO $pdo, string $code): array
{
    $st = $pdo->prepare("
        SELECT bsns_year, reprt_code, fs_div, op_income
          FROM stock_financial
         WHERE stock_code = ? AND op_income IS NOT NULL
         ORDER BY bsns_year, reprt_code, fs_div");
    $st->execute([$code]);

    $ytd = [];
    foreach ($st as $r) {
        $y = (int)$r['bsns_year']; $rc = $r['reprt_code'];
        if (isset($ytd[$y][$rc]) && ($ytd[$y][$rc]['fs'] === 'CFS' || $r['fs_div'] !== 'CFS')) continue;
        $ytd[$y][$rc] = ['fs' => $r['fs_div'], 'op' => (float)$r['op_income']];
    }
    $qv = pf_sue_qv($ytd, 'op');

    $out = [];
    foreach ($qv as $qk => $v) {
        if (!isset($qv[$qk - 4])) continue;
        $hist = [];
        for ($i = 1; $i <= Thr::SUE_HIST_MAX; $i++) {
            if (isset($qv[$qk - $i], $qv[$qk - $i - 4])) $hist[] = $qv[$qk - $i] - $qv[$qk - $i - 4];
        }
        if (count($hist) < Thr::SUE_HIST_MIN) continue;   // 이력 부족이면 값 없음 — pf_sue_build 와 같은 원칙
        $m = array_sum($hist) / count($hist);
        $var = 0.0;
        foreach ($hist as $h) $var += ($h - $m) ** 2;
        $sd = sqrt($var / count($hist));
        if ($sd <= 0) continue;
        $out[$qk] = ($v - $qv[$qk - 4]) / $sd;
    }
    return $out;
}

/**
 * 이 종목 정기공시의 <b>원본 접수일</b> — [연도*4+분기 => 'YYYY-MM-DD'].
 * `[기재정정]` 재접수는 무시한다(MIN) — 정정 때문에 「오늘 공시」로 되살아나면 안 된다.
 *
 * ★접수일은 「이 실적이 세상에 알려진 날」이다. 분기 키(qk)만으로는 <b>어제 공시와 석 달 묵은
 *   공시를 구별할 수 없어</b>, 신선도를 묻는 쪽은 반드시 이 값을 봐야 한다.
 *   (2026-08-04 실측 사고: 알림에 접수일 조건이 없어 5월 15일 공시가 8월 4일 아침에 울렸다.)
 *
 * 소비자 둘 — 차트 마커 `pf_sue_marks()` 와 알림 `pf_alert_sue_fresh()`.
 * @param ?int $withinDays 최근 N일 이내 접수만 (null = 제한 없음)
 */
function pf_sue_receipts(PDO $pdo, string $code, ?int $withinDays = null): array
{
    if (!$pdo->query("SHOW TABLES LIKE 'dart_rcept'")->fetchColumn()) return [];   // 원장 아직 없음
    $sql = "SELECT bsns_year, reprt_code, MIN(rcept_dt) dt
              FROM dart_rcept
             WHERE stock_code = ? AND reprt_code IS NOT NULL
             GROUP BY bsns_year, reprt_code";
    if ($withinDays !== null) $sql .= " HAVING dt >= DATE_SUB(CURDATE(), INTERVAL " . (int)$withinDays . " DAY)";
    $st = $pdo->prepare($sql);
    $st->execute([$code]);

    $qNoMap = ['11013' => 1, '11012' => 2, '11014' => 3, '11011' => 4];
    $out = [];
    foreach ($st as $r) {
        $qNo = $qNoMap[$r['reprt_code']] ?? null;
        if ($qNo === null) continue;
        $out[(int)$r['bsns_year'] * 4 + $qNo] = $r['dt'];
    }
    return $out;
}

/**
 * 차트에 얹을 SUE 공시 마커 — 이 종목의 정기공시(원본 접수일 MIN · [기재정정] 재접수 무시)에
 * 그 분기 SUE 를 붙인 것. [['d'=>접수일, 'q'=>'25.4Q', 'sue'=>7.7], …] 접수일 오름차순.
 *
 * ★서프라이즈(SUE ≥ +1)·쇼크(≤ −1)만 — 어닝 탭의 매수·회피 경계와 같은 상·하위 20% 문턱이고,
 *   중간값까지 다 찍으면 분기마다 마커가 생겨 소음이 된다. 매수 시점(다음 거래일) 스냅은 JS 몫.
 *
 * 2026-08-03 재무상세 전용에서 여기로 옮겼다 — 「모든 차트에서 SUE 마커」(차트설정의
 * overlay.sue_markers)가 두 번째 소비자다. 화면은 stock_analysis_api.php?module=sue 로 받는다.
 * 2026-08-04 접수일 조회를 pf_sue_receipts() 로 분리 — 알림이 세 번째 소비자가 됐다.
 */
function pf_sue_marks(PDO $pdo, string $code): array
{
    $recv = pf_sue_receipts($pdo, $code, 1600);    // 차트 데이터가 1000영업일(≈4.2년)까지다
    if (!$recv) return [];
    $sue = pf_sue_stock($pdo, $code);              // 공시가 있을 때만 계산 (1~2ms 지만 습관)

    $out = [];
    foreach ($recv as $qk => $dt) {
        $v = $sue[$qk] ?? null;
        // 문턱은 Thr 단일 원본 — 여기에 1.0 을 또 적으면 그것이 여섯 번째 사본이 된다
        if ($v === null || ($v < Thr::SUE_HIT && $v > Thr::SUE_SHOCK)) continue;
        $y = intdiv($qk - 1, 4);                   // qk = 연도*4 + 분기(1~4) 의 역산
        $out[] = ['d' => $dt, 'q' => ($y % 100) . '.' . ($qk - $y * 4) . 'Q', 'sue' => round($v, 1)];
    }
    usort($out, fn($a, $b) => strcmp($a['d'], $b['d']));
    return $out;
}

/**
 * 어닝 목록 — 최근 N일 안에 접수된 정기공시의 (종목 × 보고서) 한 벌. <b>화면과 알림의 단일본</b>.
 *
 * ★2026-08-05 pf_page_earn() 안에서 여기로 뺐다. 알림이 두 번째 소비자가 됐기 때문인데,
 *   특히 <b>「고변동 아님」은 절대 임계가 아니라 목록 안의 상대 상위 ⅓</b>이라 목록 없이는
 *   재현할 수 없다. 알림이 조건을 다시 적으면 「알림은 규칙 충족이라는데 화면에선 고변동⚠」이
 *   되므로, 표시 상한(200)·자르는 순서까지 그대로 한 함수 안에 둔다.
 *
 * 반환 ['hasTbl','filings','rows','shock','total','shockTotal','okN','okHighVol','lastD']
 *   filings = 창 안의 공시 건수 · total = 그중 SUE 가 계산된 건수 — <b>둘을 갈라 돌려준다</b>.
 *             화면 안내가 「공시가 없다」와 「SUE 를 낼 수 있는 공시가 없다」로 다르기 때문이다.
 *   rows  = 표시분(≤200 · SUE 상위) — 'ok'(규칙 충족)·'vol'·'high_vol' 포함
 *   shock = 어닝 쇼크(≤50 · 나쁜 쪽부터) — <b>자르기 전에</b> 갈라낸 것
 */
function pf_earn_rows(PDO $pdo, int $days): array
{
    $out = ['hasTbl' => false, 'filings' => 0, 'rows' => [], 'shock' => [], 'total' => 0,
            'shockTotal' => 0, 'okN' => 0, 'okHighVol' => 0, 'lastD' => null];

    if (!$pdo->query("SHOW TABLES LIKE 'dart_rcept'")->fetchColumn()) return $out;
    $out['hasTbl'] = true;

    /* 최근 공시 — 원본 접수일(MIN) 이 창 안에 든 (종목 × 보고서)만. 정정공시는 같은 그룹으로 접힌다. */
    $st = $pdo->prepare("
        SELECT stock_code, bsns_year, reprt_code, MIN(rcept_dt) dt
          FROM dart_rcept
         WHERE reprt_code IS NOT NULL
         GROUP BY stock_code, bsns_year, reprt_code
        HAVING dt >= DATE_SUB(CURDATE(), INTERVAL ? DAY)");
    $st->execute([$days]);
    $filings = $st->fetchAll(PDO::FETCH_ASSOC);
    $out['filings'] = count($filings);
    if (!$filings) return $out;

    // 그룹(연도×보고서)별로 SUE 를 한 번씩만 계산 — 시즌엔 보통 1~3개 그룹이다
    $groups = [];
    foreach ($filings as $f) $groups[$f['bsns_year'] . ':' . $f['reprt_code']] = true;
    $sue = [];
    foreach (array_keys($groups) as $g) {
        [$gy, $grc] = explode(':', $g);
        $sue[$g] = pf_sue_build($pdo, (int)$gy, $grc);
    }

    // 시세 — krx_amt 최신 거래일 스냅샷 + 공시 다음 거래일 진입가(드리프트 실측)
    $lastD = (string)$pdo->query("SELECT MAX(d) FROM krx_amt")->fetchColumn();
    $out['lastD'] = $lastD;
    $tds   = $pdo->query("SELECT DISTINCT d FROM krx_amt WHERE d >= DATE_SUB(CURDATE(), INTERVAL " . ($days + 40) . " DAY) ORDER BY d")
                 ->fetchAll(PDO::FETCH_COLUMN);
    $names = [];
    foreach ($pdo->query("SELECT stock_code, stock_name FROM all_stock_info") as $r) {
        $names[$r['stock_code']] = $r['stock_name'];
    }
    $qLast  = $pdo->prepare("SELECT c, amt, mktcap FROM krx_amt WHERE code = ? AND d = ?");
    $qEntry = $pdo->prepare("SELECT c FROM krx_amt WHERE code = ? AND d = ?");

    $qNoMap = ['11013' => 1, '11012' => 2, '11014' => 3, '11011' => 4];
    $rows = [];
    foreach ($filings as $f) {
        $g = $f['bsns_year'] . ':' . $f['reprt_code'];
        $s = $sue[$g][$f['stock_code']] ?? null;
        if ($s === null) continue;                       // SUE 이력 부족(신규상장 등)

        $qLast->execute([$f['stock_code'], $lastD]);
        $mk = $qLast->fetch(PDO::FETCH_ASSOC) ?: null;

        // 진입 = 공시 다음 거래일 종가 (백테스트와 같은 정의). 그날 이후 거래일 수 = 드리프트 경과
        $entryD = null;
        foreach ($tds as $i => $d) if ($d > $f['dt']) { $entryD = $d; $entryIx = $i; break; }
        $drift = null; $elapsed = null;
        if ($entryD !== null && $mk) {
            $qEntry->execute([$f['stock_code'], $entryD]);
            $c0 = (float)($qEntry->fetchColumn() ?: 0);
            if ($c0 > 0 && (float)$mk['c'] > 0) $drift = (float)$mk['c'] / $c0 - 1;
            $elapsed = count($tds) - 1 - $entryIx;
        }
        $liq = $mk !== null && (float)$mk['amt'] >= Thr::EARN_MIN_AMT;
        $rows[] = [
            'code' => $f['stock_code'], 'name' => $names[$f['stock_code']] ?? $f['stock_code'],
            'dt' => $f['dt'], 'y' => (int)$f['bsns_year'], 'rc' => $f['reprt_code'],
            // qk = 연도*4+분기 — 알림 중복방지 키가 이걸 쓴다(분기 하나당 한 번만 울린다)
            'qk' => (int)$f['bsns_year'] * 4 + ($qNoMap[$f['reprt_code']] ?? 0),
            'sue' => $s['sue'], 'rev_up' => $s['rev_up'], 'quality' => $s['quality'], 'ni_op' => $s['ni_op'],
            'cap' => $mk['mktcap'] ?? null, 'amt' => $mk['amt'] ?? null,
            'drift' => $drift, 'elapsed' => $elapsed,
            'ok' => ($s['sue'] >= Thr::SUE_HIT && $s['quality'] && $s['rev_up'] === true && $liq),
        ];
    }

    // 규칙 충족 먼저 · 그 안에서 SUE 큰 순 — "지금 볼 것"이 맨 위에 오게
    usort($rows, fn($a, $b) => [$b['ok'], $b['sue']] <=> [$a['ok'], $a['sue']]);
    $out['okN']   = count(array_filter($rows, fn($r) => $r['ok']));
    $out['total'] = count($rows);

    /* ★ 「어닝 쇼크」 목록 — 잘려 나가던 꼬리를 되살린다(2026-08-03).
     * 위 정렬이 (규칙 충족 → SUE 큰 순)이라 200행 상한에 걸리는 시즌에는 쇼크가 통째로 사라졌다.
     * ★ <b>자르기 전에</b> 갈라야 한다 — 뒤에 하면 이미 잘려 나간 것 중에서 고르게 된다.
     * ★ 쇼크는 조건이 <b>SUE ≤ −1 하나뿐</b>이다(서프라이즈 쪽의 품질·매출동반·거래대금이 없다).
     *   서프라이즈는 「사는 규칙」이라 좁힐수록 정확해지지만 쇼크는 「피하는 목록」이라
     *   좁히면 피해야 할 것을 놓친다. 백테스트도 하위 20%를 단일 기준으로 재서 얻은 결과다. */
    $shock = array_values(array_filter($rows, fn($r) => $r['sue'] <= Thr::SUE_SHOCK));
    usort($shock, fn($a, $b) => $a['sue'] <=> $b['sue']);      // 나쁜 쪽부터
    $out['shockTotal'] = count($shock);
    $out['shock'] = array_slice($shock, 0, 50);

    /* 표시는 상위 200행 — 시즌의 90일 창은 2천 건이 넘는데, 정렬이 (충족 → SUE 큰 순)이라
     * 잘리는 것은 SUE 하위(볼 일 없는 쪽)뿐이다. ★상한 없이 그리면 박스 판정(boxStatusMany)이
     * 수천 종목의 봉을 읽다 메모리를 터뜨린다 — 실측 90일 창 256MB 초과. */
    $rows = array_slice($rows, 0, 200);

    /* 변동성(진입 전 60일 일σ) — 팩터 스윕(2026-08-02)의 두 번째 생존자.
     * 규칙 충족을 변동성 ⅓로 가르면 고변동⅓만 −1.6%(독)이고 저·중은 8년 전부 양수 —
     * 특히 2022 하락장 음수가 고변동에서 왔다. 경계는 절대값이 아니라 <b>목록 내 상대 ⅓</b>
     * (국면 따라 66백분위가 3.8~6.0%로 움직여 절대 임계는 국면이 바뀌면 틀린다 — 백테스트 정의와 동일).
     * ★표시분만 계산(200행 × 봉 61개 — 가볍다). 목록이 30행 미만이면 ⅓ 판정이 노이즈라 게이트 없음. */
    $stVol = $pdo->prepare("SELECT c FROM krx_amt WHERE code = ? AND d <= ? AND c > 0 ORDER BY d DESC LIMIT 61");
    foreach ($rows as &$r) {
        $r['vol'] = null;
        $stVol->execute([$r['code'], $lastD]);
        $px = array_reverse(array_map('floatval', $stVol->fetchAll(PDO::FETCH_COLUMN)));
        if (count($px) < 46) continue;
        $rets = [];
        for ($i = 1; $i < count($px); $i++) $rets[] = $px[$i] / $px[$i - 1] - 1;
        $m = array_sum($rets) / count($rets);
        $var = 0.0;
        foreach ($rets as $x) $var += ($x - $m) ** 2;
        $r['vol'] = sqrt($var / count($rets));
    }
    unset($r);
    $volBound = null;
    $vols = array_values(array_filter(array_map(fn($r) => $r['vol'], $rows), fn($v) => $v !== null));
    if (count($vols) >= 30) {
        sort($vols);
        $volBound = $vols[(int)(count($vols) * 0.66)];
    }
    foreach ($rows as &$r) {
        $r['high_vol'] = ($volBound !== null && $r['vol'] !== null && $r['vol'] > $volBound);
        if ($r['ok'] && $r['high_vol']) $out['okHighVol']++;
    }
    unset($r);

    $out['rows'] = $rows;
    return $out;
}
?>
