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
        for ($i = 1; $i <= 8; $i++) {
            if (isset($qv[$qk - $i], $qv[$qk - $i - 4])) $hist[] = $qv[$qk - $i] - $qv[$qk - $i - 4];
        }
        if (count($hist) < 4) continue;   // 이력 부족이면 값 없음 — 0 으로 채우면 "서프라이즈 없음"으로 잘못 읽힌다
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
                            ? (abs($yr['ni']) / $yr['op'] <= 1.5) : false,
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
        for ($i = 1; $i <= 8; $i++) {
            if (isset($qv[$qk - $i], $qv[$qk - $i - 4])) $hist[] = $qv[$qk - $i] - $qv[$qk - $i - 4];
        }
        if (count($hist) < 4) continue;   // 이력 부족이면 값 없음 — pf_sue_build 와 같은 원칙
        $m = array_sum($hist) / count($hist);
        $var = 0.0;
        foreach ($hist as $h) $var += ($h - $m) ** 2;
        $sd = sqrt($var / count($hist));
        if ($sd <= 0) continue;
        $out[$qk] = ($v - $qv[$qk - 4]) / $sd;
    }
    return $out;
}
?>
