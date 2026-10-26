<?php
/**
 * stock/lib/research.php — 리서치 화면(mode=research) · 재무분석 상세의 리서치 카드 · Markdown 렌더  (2026-10-15)
 *
 * claude.ai 에서 만든 종목 분석 문서(Claude Docs → Markdown 내보내기)를 올리면 종목별 «버전»으로 쌓고 보여 준다.
 * DB·AI 추출은 classes/Rs.class, API 는 stock/lib/research_api.php(module=rs), 차트의 리서치 층은 style/dailychart.js.
 * 규칙 정본은 CLAUDE.md 「/stock 리서치」 절.
 *
 * ★목록은 «종목 단위»다 — 한 줄 = 한 종목의 최신 버전. 문서 단위로 늘어놓으면 같은 종목의 옛 판단이
 *   최신 판단을 밀어내고 종목이 섞인다(「올린 순」 탭이 문서 단위를 따로 보여 준다).
 * ★여기의 판정(지금 가격 대비·매수 구간 상태·재점검 필요)은 «저장하지 않는다» — 볼 때 만든다.
 *   재점검 근거 넷: 리서치 뒤 새 정기공시(pf_sue_receipts 단일본) · 문서의 일정이 지남 · 비관/마지막 구간 아래 · 오래됨.
 * ★포트폴리오 화면에는 여기의 판정을 가져가지 않는다 — 링크와 기록(의견·기준일)만(포트폴리오 규칙 1 · 룰셋 사다리와 «매수가»가 둘이 된다).
 */

// ══ 작은 도우미 ═════════════════════════════════════════════════════
/** 174000 → 17.4만 · 180000 → 18만 · 9500 → 9,500 (화면의 짧은 가격) */
function rs_man($v): string
{
    if ($v === null || $v === '' || (int)$v <= 0) return '-';
    $v = (int)$v;
    if ($v < 10000) return number_format($v);
    $m = floor($v / 1000) / 10;
    return (fmod($m, 1.0) == 0.0 ? number_format($m, 0) : number_format($m, 1)) . '만';
}

/** 매수 구간 글자 — 한 가격 · 「15만대」 · 범위 (차트 모듈의 rsRange 와 같은 규칙) */
function rs_range(int $lo, int $hi): string
{
    if ($lo === $hi) return rs_man($lo);
    if ($lo % 10000 === 0 && $hi - $lo === 9999) return rs_man($lo) . '대';
    return rs_man($lo) . '~' . rs_man($hi);
}

function rs_pct(float $r, int $dec = 1): string
{
    $p = $r * 100;
    return ($p > 0.00001 ? '+' : ($p < -0.00001 ? '−' : '')) . number_format(abs($p), $dec) . '%';
}

/** JS 에 심는 값 — </script> 를 끊지 못하게 꺾쇠·따옴표를 이스케이프한다 */
function rs_js($v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

/** 날짜 + 정밀도 → 보이는 글자 */
function rs_date_label(?string $d, string $prec): string
{
    if (!$d) return '';
    $y = (int)substr($d, 0, 4);
    $m = (int)substr($d, 5, 2);
    switch ($prec) {
        case 'month':   return substr($d, 0, 7);
        case 'quarter': return $y . ' ' . (intdiv($m - 1, 3) + 1) . 'Q';
        case 'half':    return $y . ' ' . ($m <= 6 ? '상반기' : '하반기');
        case 'year':    return (string)$y;
        default:        return $d;
    }
}

/** 그 날짜(정밀도)가 끝나는 날 — 「11월」은 11월 말에야 «지났다» */
function rs_period_end(string $d, string $prec): string
{
    return Rs::periodEnd($d, $prec);   // 단일본 — 일정 묶기(Rs::scheduleGroups)·캘린더가 같은 끝날을 쓴다
}

function rs_op_chip(string $op, string $extra = ''): string
{
    $lab = Rs::opinionLabel($op);
    if ($lab === '') return '<span class="rs-op none">의견 없음</span>';
    return '<span class="rs-op' . ($extra !== '' ? ' ' . $extra : '') . '" style="background:' . Rs::opinionColor($op) . '">'
         . pf_h($lab) . '</span>';
}

// ══ 시세 ═══════════════════════════════════════════════════════════
/** 지금 가격 — 시세 스냅샷(all_stock_info) 이 정본, 없으면 원장(krx_amt) 마지막 종가. [code => ['price','rate','at']] */
function rs_prices(PDO $pdo, array $codes): array
{
    $codes = array_values(array_unique(array_filter(array_map('strval', $codes))));
    if (!$codes) return [];
    $out = [];
    try {
        $st = $pdo->prepare("SELECT stock_code, stock_price, stock_rate, uDate FROM all_stock_info WHERE stock_code IN ("
                          . implode(',', array_fill(0, count($codes), '?')) . ")");
        $st->execute($codes);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ((float)$r['stock_price'] <= 0) continue;
            $out[strval($r['stock_code'])] = ['price' => (float)$r['stock_price'], 'rate' => (float)$r['stock_rate'],
                                               'at' => (string)$r['uDate']];
        }
    } catch (Throwable $e) { /* 원장으로 */ }
    foreach ($codes as $c) {
        if (isset($out[$c])) continue;
        try {
            $st = $pdo->prepare("SELECT c, d FROM krx_amt WHERE code = ? AND vol > 0 ORDER BY d DESC LIMIT 1");
            $st->execute([$c]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r && (float)$r['c'] > 0) $out[$c] = ['price' => (float)$r['c'], 'rate' => null, 'at' => (string)$r['d']];
        } catch (Throwable $e) { }
    }
    return $out;
}

/** 그 날(또는 그 전 마지막 거래일)의 종가 */
function rs_close_on(PDO $pdo, string $code, string $d): ?float
{
    try {
        $st = $pdo->prepare("SELECT c FROM krx_amt WHERE code = ? AND d <= ? AND vol > 0 ORDER BY d DESC LIMIT 1");
        $st->execute([$code, $d]);
        $c = $st->fetchColumn();
        return ($c !== false && (float)$c > 0) ? (float)$c : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** 버전의 기준가 — 문서가 말한 값이 먼저, 없으면 기준일 종가 */
function rs_base_price(PDO $pdo, array $n): ?float
{
    if ((int)($n['price_ref'] ?? 0) > 0) return (float)$n['price_ref'];
    return rs_close_on($pdo, (string)$n['code'], (string)$n['d']);
}

// ══ 판정(저장하지 않는다) ═══════════════════════════════════════════
/** 시나리오 [라벨 => 값] — 비관·기본·낙관 순 */
function rs_scen(array $n): array
{
    $out = [];
    foreach (Rs::SCENS as $l) {
        foreach ($n['levels'] as $v) {
            if ($v['kind'] === 'scen' && $v['label'] === $l && (int)$v['lo'] > 0) { $out[$l] = (int)$v['lo']; break; }
        }
    }
    return $out;
}

/** 매수 구간 — 높은 것(1차)이 앞 */
function rs_zones(array $n): array
{
    $out = [];
    foreach ($n['levels'] as $v) {
        if ($v['kind'] !== 'zone' || (int)$v['lo'] <= 0) continue;
        $out[] = ['label' => (string)$v['label'], 'lo' => (int)$v['lo'], 'hi' => (int)$v['hi'], 'memo' => (string)$v['memo']];
    }
    usort($out, fn($a, $b) => $b['hi'] <=> $a['hi']);
    return $out;
}

/**
 * 지금 가격이 매수 구간의 어디인가 → ['text','short','cls'(in|above|mid|below|''),'below'=>bool]
 * short = 좁은 칸용 짧은 말(「1차 안」·「1차 위 8%」·「2차 아래 3%」 — 포트폴리오 오늘의 신호 표 리서치 칸 · 2026-10-19 · 리서치 목록 가격 지도의 매수 줄 · 2026-10-22).
 *   두 구간 사이는 가까운 쪽 기준(「1차 아래 5%」 또는 「2차 위 7%」 — 2026-10-22 · 옛 「1·2차 사이」는 거리가 안 보였다).
 *   text 와 같은 갈래에서 만든다 — 따로 계산하면 두 화면이 다른 자리를 말한다.
 */
function rs_zone_state(array $n, ?float $px): array
{
    $z = rs_zones($n);
    if (!$z || !$px) return ['text' => '', 'short' => '', 'cls' => '', 'below' => false];
    $ap = fn(float $r): string => number_format(abs($r) * 100) . '%';   // 방향은 말(위·아래)이 한다
    foreach ($z as $q) {
        if ($px >= $q['lo'] && $px <= $q['hi'] && $q['lo'] !== $q['hi']) return ['text' => $q['label'] . ' 구간 안', 'short' => $q['label'] . ' 안', 'cls' => 'in', 'below' => false];
        if ($q['lo'] === $q['hi'] && abs($px / $q['lo'] - 1) <= Rs::NEAR_PCT) {
            return ['text' => $q['label'] . ' 부근 (' . rs_pct($px / $q['lo'] - 1) . ')', 'short' => $q['label'] . ' 부근', 'cls' => 'in', 'below' => false];
        }
    }
    $top = $z[0];
    if ($px > $top['hi']) return ['text' => $top['label'] . '까지 ' . rs_pct($top['hi'] / $px - 1), 'short' => $top['label'] . ' 위 ' . $ap($px / $top['hi'] - 1), 'cls' => 'above', 'below' => false];
    for ($i = 0; $i < count($z) - 1; $i++) {
        if ($px < $z[$i]['lo'] && $px > $z[$i + 1]['hi']) {
            $a = $z[$i]['label']; $b = $z[$i + 1]['label'];
            $dn = 1 - $px / $z[$i]['lo'];            // 위 구간(1차)까지 내려온 몫
            $up = $px / $z[$i + 1]['hi'] - 1;        // 아래 구간(2차) 위로 남은 몫
            $near = $dn <= $up ? $a . ' 아래 ' . $ap($dn) : $b . ' 위 ' . $ap($up);
            return ['text' => $a . ' 아래 · ' . $b . '까지 ' . rs_pct($z[$i + 1]['hi'] / $px - 1), 'short' => $near,
                    'cls' => 'mid', 'below' => false];
        }
    }
    $last = $z[count($z) - 1];
    return ['text' => $last['label'] . ' 아래 ' . rs_pct($px / $last['lo'] - 1), 'short' => $last['label'] . ' 아래 ' . $ap($px / $last['lo'] - 1), 'cls' => 'below', 'below' => true];
}

/** 재점검이 필요한 이유들 — 없으면 빈 배열. [['k'=>filing|event|price|age, 'text'=>…], …] */
function rs_stale(PDO $pdo, array $n, ?float $px): array
{
    $out = [];
    $code = (string)$n['code'];
    $d = (string)$n['d'];
    $today = date('Y-m-d');

    // ① 리서치 뒤에 나온 정기공시 — 접수일의 단일본은 pf_sue_receipts(정정 재접수는 무시)
    if (function_exists('pf_sue_receipts')) {
        $new = [];
        foreach (pf_sue_receipts($pdo, $code) as $qk => $dt) if ($dt > $d) $new[$qk] = $dt;
        if ($new) {
            asort($new);
            $sue = function_exists('pf_sue_stock') ? pf_sue_stock($pdo, $code) : [];
            foreach ($new as $qk => $dt) {
                $y = intdiv($qk - 1, 4);
                $q = ($y % 100) . '.' . ($qk - $y * 4) . 'Q';
                $s = isset($sue[$qk]) ? ' · SUE ' . ($sue[$qk] > 0 ? '+' : '') . number_format($sue[$qk], 1) : '';
                $out[] = ['k' => 'filing', 'text' => '새 정기공시 ' . substr($dt, 5, 2) . '/' . substr($dt, 8, 2) . ' (' . $q . $s . ')'];
            }
        }
    }
    // ② 문서에 적힌 일정이 지남 — 기간이 기준일 전에 끝난 것은 배경 설명(Rs::itemLive) · 일정 탭에서 ✓ 한 것은 뺀다
    //   ★2026-10-26 고침: 옛 「d < 기준일」은 「2026-10」 월 일정(10-01 로 저장)을 10/16 문서에서 통째로 뺐다
    $past = [];
    foreach ($n['items'] as $it) {
        if ($it['kind'] !== 'event' || (int)$it['done'] === 1 || !Rs::itemLive($it, $d)) continue;
        if (rs_period_end((string)$it['d'], (string)$it['prec']) < $today) $past[] = (string)$it['text'];
    }
    if ($past) $out[] = ['k' => 'event', 'text' => '지난 일정: ' . $past[0] . (count($past) > 1 ? ' 외 ' . (count($past) - 1) : '')];
    // ③ 가격 — 비관 시나리오·마지막 매수 구간 아래
    if ($px) {
        $sc = rs_scen($n);
        if (isset($sc['비관']) && $px < $sc['비관']) $out[] = ['k' => 'price', 'text' => '비관 시나리오 아래 (' . rs_pct($px / $sc['비관'] - 1) . ')'];
        $zs = rs_zone_state($n, $px);
        if ($zs['below']) $out[] = ['k' => 'price', 'text' => '마지막 매수 구간 아래'];
    }
    // ④ 오래됨
    $days = (int)floor((strtotime($today) - strtotime($d)) / 86400);
    if ($days > Rs::STALE_DAYS) $out[] = ['k' => 'age', 'text' => '기준일 ' . $days . '일 지남'];
    return $out;
}

/** 다가오는 일정(이벤트·확인 신호 중 날짜가 있고 아직 안 끝난 것의 가장 이른 것) */
function rs_next_item(array $n): ?array
{
    $today = date('Y-m-d');
    $best = null;
    foreach ($n['items'] as $it) {
        if (!in_array($it['kind'], ['event', 'check'], true) || !$it['d']) continue;
        if ((int)$it['done'] === 1) continue;   // 확인한 것(확인 신호 체크 · 일정 탭 ✓)
        if (rs_period_end((string)$it['d'], (string)$it['prec']) < $today) continue;
        if ($best === null || $it['d'] < $best['d']) $best = $it;
    }
    if (!$best) return null;
    $days = (int)floor((strtotime((string)$best['d']) - strtotime($today)) / 86400);
    return ['text' => (string)$best['text'], 'when' => rs_date_label((string)$best['d'], (string)$best['prec']),
            'dday' => $days > 0 ? 'D-' . $days : ((string)$best['prec'] === 'day' ? '오늘' : '진행 중')];
}

/**
 * 가격 지도 — 한 자 위에 비관·기본·낙관 눈금 + 매수 구간 띠 + 지금 가격(2026-10-22 · 목록·상세·재무분석 카드 공용).
 * 옛 「시나리오 위치 막대」와 「매수 구간」 글자를 한 그림으로 합쳤다(사용자 — 「중요한데 눈에 안 들어온다」).
 * 한 가격으로 적힌 구간은 ±NEAR_PCT 띠로 그린다 — rs_zone_state 의 「부근」과 같은 폭. 시나리오·구간이 다 없으면 null.
 * $bpx = 기준가(rs_base_price) — 적정가 선 위의 ○(2026-10-22 · 옛 「기준가 → 현재」 칸).
 */
function rs_price_map(array $n, ?float $px, ?float $bpx = null): ?array
{
    $sc = rs_scen($n);
    $zs = rs_zones($n);
    if (!$sc && !$zs) return null;
    $span = [];
    foreach ($zs as $z) {
        $a = $z['lo']; $b = $z['hi'];
        if ($a === $b) { $a = $z['lo'] * (1 - Rs::NEAR_PCT); $b = $z['lo'] * (1 + Rs::NEAR_PCT); }
        $span[] = [$a, $b];
    }
    $vals = array_values($sc);
    foreach ($span as [$a, $b]) { $vals[] = $a; $vals[] = $b; }
    if ($px) $vals[] = $px;
    if ($bpx) $vals[] = $bpx;
    $lo = min($vals) * 0.96;
    $hi = max($vals) * 1.04;
    $x = static fn($v) => $hi > $lo ? round(max(0, min(100, ($v - $lo) / ($hi - $lo) * 100)), 1) : 50.0;
    $pts = [];
    foreach ($sc as $l => $v) $pts[] = ['label' => $l, 'v' => $v, 'x' => $x($v)];
    $al = rs_label_align(array_column($pts, 'x'));                   // 이름표는 제 눈금 위 — 가까우면 서로 반대로 펼친다
    foreach ($pts as $i => $p) $pts[$i]['al'] = $al[$i];
    $bands = [];
    foreach ($zs as $i => $z) {
        $x1 = $x($span[$i][0]);
        $w = $x($span[$i][1]) - $x1;
        if ($w < 6) { $x1 = max(0, $x1 + $w / 2 - 3); $w = 6; }   // 이름(「1차」)이 들어갈 최소 폭 — 실제 값은 툴팁
        $bands[] = ['label' => $z['label'], 'lo' => $z['lo'], 'hi' => $z['hi'], 'x' => round($x1, 1), 'w' => round($w, 1)];
    }
    // 적정가 줄의 판정 — 「기본보다 14% 아래」(2026-10-22 사용자: 「기본까지」(오를 몫)보다 «지금 자리»가 낫다 · 매수 줄과 같은 어법).
    // 비관보다 아래·낙관보다 위면 그 눈금이 기준이다. 방향은 말(위·아래)이 한다 — 빨강·파랑 부호는 «올랐다·내렸다»로 읽힌다.
    $pos = null;
    $out = false;
    if ($px) {
        $ref = null;
        if (isset($sc['비관']) && $px < $sc['비관']) { $ref = '비관'; $out = true; }
        elseif (isset($sc['낙관']) && $px > $sc['낙관']) { $ref = '낙관'; $out = true; }
        elseif (isset($sc['기본'])) $ref = '기본';
        if ($ref !== null) {
            $r = $px / $sc[$ref] - 1;
            $pos = ['ref' => $ref, 'r' => $r, 'text' => abs($r) < 0.005 ? $ref . ' 부근' : $ref . '보다 ' . number_format(abs($r) * 100) . '% ' . ($r < 0 ? '아래' : '위')];
        }
    }
    return ['pts' => $pts, 'bands' => $bands, 'px' => $px, 'px_x' => $px ? $x($px) : null,
            'pos' => $pos, 'up' => ($px && isset($sc['기본'])) ? $sc['기본'] / $px - 1 : null, 'basic' => $sc['기본'] ?? null, 'out' => $out,
            'bpx' => $bpx, 'bx' => $bpx ? $x($bpx) : null, 'bd' => rs_base_date($n)];
}

/**
 * 눈금 이름표의 펼침 방향 — 이름표는 늘 제 눈금 위에 선다(옮기지 않는다 · 2026-10-22 사용자).
 * c = 가운데 · l = 눈금에서 왼쪽으로(끝이 눈금) · r = 오른쪽으로(시작이 눈금).
 * 이웃 눈금이 $gap(%)보다 가까우면 둘이 서로 반대로 펼친다 · 자의 양 끝은 안쪽으로. [i => c|l|r]
 */
function rs_label_align(array $xs, float $gap = 20): array
{
    $idx = array_keys($xs);
    usort($idx, fn($a, $b) => $xs[$a] <=> $xs[$b]);
    $al = array_fill_keys($idx, 'c');
    foreach ($idx as $k => $i) {
        if ($xs[$i] < 10) $al[$i] = 'r';
        elseif ($xs[$i] > 90) $al[$i] = 'l';
    }
    for ($k = 0; $k < count($idx) - 1; $k++) {
        $i = $idx[$k]; $j = $idx[$k + 1];
        if ($xs[$j] - $xs[$i] >= $gap) continue;
        if ($al[$i] !== 'r') $al[$i] = 'l';     // 왼쪽 것이 이미 끝(10% 안)이라 오른쪽으로 펼치면 그대로 둔다
        $al[$j] = 'r';
    }
    return $al;
}

/** 앞 버전과 달라진 것 — 의견·시나리오·매수 구간 */
function rs_diff(?array $prev, array $cur): array
{
    if (!$prev) return [];
    $out = [];
    if ((string)$prev['opinion'] !== (string)$cur['opinion']) {
        $out[] = '의견 ' . (Rs::opinionLabel((string)$prev['opinion']) ?: '-') . ' → ' . (Rs::opinionLabel((string)$cur['opinion']) ?: '-');
    }
    $ps = rs_scen($prev);
    $cs = rs_scen($cur);
    foreach (Rs::SCENS as $l) {
        if (isset($ps[$l], $cs[$l]) && $ps[$l] !== $cs[$l]) $out[] = $l . ' ' . rs_man($ps[$l]) . ' → ' . rs_man($cs[$l]) . ' (' . rs_pct($cs[$l] / $ps[$l] - 1) . ')';
        elseif (!isset($ps[$l]) && isset($cs[$l])) $out[] = $l . ' 새로 ' . rs_man($cs[$l]);
        elseif (isset($ps[$l]) && !isset($cs[$l])) $out[] = $l . ' 빠짐';
    }
    $pz = [];
    foreach (rs_zones($prev) as $z) $pz[$z['label']] = $z;
    foreach (rs_zones($cur) as $z) {
        $p = $pz[$z['label']] ?? null;
        if ($p && ($p['lo'] !== $z['lo'] || $p['hi'] !== $z['hi'])) $out[] = $z['label'] . ' ' . rs_range($p['lo'], $p['hi']) . ' → ' . rs_range($z['lo'], $z['hi']);
        elseif (!$p) $out[] = '새 구간 ' . $z['label'] . ' ' . rs_range($z['lo'], $z['hi']);
        unset($pz[$z['label']]);
    }
    foreach ($pz as $l => $_) $out[] = $l . ' 구간 빠짐';
    return $out;
}

// ══ 조각 ═══════════════════════════════════════════════════════════
/**
 * 가격 지도 HTML — 같은 눈금의 두 줄: ①적정가(비관·기본·낙관 눈금 → 오른쪽 「기본보다 14% 아래」) ②매수(구간 띠 → 오른쪽 「1차 안」·「1차 위 8%」) + 두 줄을 꿰는 «지금» 선.
 * $lg = 카드용(넓게 · 「지금」 옆에 가격). 왼쪽 = 싼 쪽.
 */
function rs_map_html(?array $m, array $zs, bool $lg = false): string
{
    if (!$m) return '';
    $sc = ['비관' => 's-b', '기본' => 's-g', '낙관' => 's-n'];
    // 목록 = 짧게(10만 원 아래는 그대로 · 위는 만 단위 반올림 — rs_man 은 내림이라 10,670 이 「1만」이 된다) · 카드 = 원
    $won = static fn($v) => ($lg || (float)$v < 100000) ? number_format((float)$v) : rtrim(rtrim(number_format((float)$v / 10000, 1), "0"), ".") . "만";
    $h = '<div class="rs-map' . ($lg ? ' lg' : '') . '"><div class="rs-map-in">';
    // 줄 이름(왼쪽) · 판정(오른쪽) — 두 기준이 섞여 보이지 않게 줄마다 제 이름과 제 결론을 단다(2026-10-22 2차)
    $h .= '<span class="rs-map-k k-a">적정가</span><span class="rs-map-k k-b">매수</span>';
    $sc0 = $m['pos'] ? ($sc[$m['pos']['ref']] ?? 's-g') : '';
    $tipA = ($m['basic'] && $m['px']) ? '기본 ' . number_format($m['basic']) . ' · 현재 ' . number_format((float)$m['px']) . ' — 기본까지 ' . rs_pct((float)$m['up']) : '';
    $h .= '<span class="rs-map-v v-a"' . ($tipA !== '' ? ' title="' . pf_h($tipA) . '"' : '') . '>'
        . ($m['pos'] ? '<b class="' . $sc0 . ($m['out'] ? ' out' : '') . '">' . pf_h($m['pos']['text']) . '</b>' : '') . '</span>';
    $h .= '<span class="rs-map-v v-b"' . ($zs['text'] !== '' ? ' title="' . pf_h($zs['text']) . '"' : '') . '>'
        . ($zs['short'] !== '' ? '<span class="rs-zs ' . $zs['cls'] . '">' . pf_h($zs['short']) . '</span>' : '<span class="muted">-</span>') . '</span>';
    // ① 적정가 줄 — 가는 선 위에 비관·기본·낙관 눈금, 이름표는 위
    $h .= '<i class="rs-map-la"></i>';
    foreach ($m['pts'] as $p) {
        $k = $sc[$p['label']] ?? 's-g';
        $h .= '<i class="tk ' . $k . '" style="left:' . $p['x'] . '%" title="' . pf_h($p['label'] . ' ' . number_format($p['v'])) . '"></i>'
            . '<span class="lb ' . $k . ' a' . $p['al'] . '" style="left:' . $p['x'] . '%">' . pf_h($p['label']) . '<b>' . pf_h(rs_man($p['v'])) . '</b></span>';
    }
    // ② 매수 줄 — 구간 띠만(이름은 띠 안 · 회색 바닥은 사용자 지적으로 뺐다 — 띠가 더 잘 보인다)
    foreach ($m['bands'] as $b) {
        $t = $b['label'] . ' 매수 구간 ' . ($b['lo'] === $b['hi'] ? number_format($b['lo']) . ' 부근(±' . (Rs::NEAR_PCT * 100) . '%)' : number_format($b['lo']) . '~' . number_format($b['hi']));
        $h .= '<i class="z" style="left:' . $b['x'] . '%;width:' . $b['w'] . '%" title="' . pf_h($t) . '">' . pf_h($b['label']) . '</i>';
    }
    // ③ 기준가 — 적정가 선 위의 회색 ○ 하나(리서치 때 주가가 시나리오 사이 어디였나 · 날짜·가격은 툴팁).
    //   아래 줄의 「○ 기준 ━▶ ● 현재」 흐름 줄은 같은 날 사용자 지시로 뺐다 — 기준→현재 등락률은 현재 꼬리표가 말한다.
    if ($m['bx'] !== null) {
        $bt = '기준가 ' . number_format((float)$m['bpx']) . ' (' . $m['bd'] . ' · 리서치가 말한 그때 주가)';
        $h .= '<i class="bdot" style="left:' . $m['bx'] . '%" title="' . pf_h($bt) . '"></i>';
    }
    // ④ 현재 — 두 줄을 꿰는 세로선 + 아래 꼬리표(「현재 16.2만 −4.4%」 · 등락률은 기준가 대비 · 양 끝에선 안쪽으로 붙인다)
    if ($m['px_x'] !== null) {
        $x = (float)$m['px_x'];
        $st = pf_h($zs['cls']) . ($m['out'] && $m['pos']['ref'] === '비관' ? ' out' : '');
        $chg = $m['bpx'] ? rs_pct((float)$m['px'] / (float)$m['bpx'] - 1) : '';
        $al = $x < 15 ? ' al' : ($x > 85 ? ' ar' : '');
        $tt = '현재 ' . number_format((float)$m['px']) . ($m['bpx'] ? ' · 기준가 ' . number_format((float)$m['bpx']) . '(' . $m['bd'] . ') 대비 ' . $chg : '');
        $h .= '<i class="now ' . $st . '" style="left:' . $x . '%"></i>'
            . '<span class="nowt ' . $st . $al . '" style="left:' . $x . '%" title="' . pf_h($tt) . '">'
            . '현재 ' . pf_h($won($m['px'])) . ($chg !== '' ? '<small>' . pf_h($chg) . '</small>' : '') . '</span>';
    }
    return $h . '</div></div>';
}

/** 기준가의 날짜 — 문서가 기준 주가를 적었으면 그 날짜(없으면 문서 날짜), 아니면 문서 날짜의 종가(rs_base_price 와 같은 갈래) */
function rs_base_date(array $n): string
{
    if ((int)($n['price_ref'] ?? 0) > 0 && (string)($n['price_ref_d'] ?? '') !== '') return (string)$n['price_ref_d'];
    return (string)$n['d'];
}

/** 다른 화면이 끌어다 쓰는 링크 칩 — 「📑 10/15 분할매수」 */
function rs_link_chip(array $n): string
{
    $url = '/stock/index.php?mode=research&code=' . rawurlencode((string)$n['code']) . '&id=' . (int)$n['id'];
    return '<a class="rs-chip" href="' . pf_h($url) . '" title="' . pf_h((string)$n['title'] . "\n" . (string)$n['headline']) . '">'
         . '📑 ' . pf_h(substr((string)$n['d'], 5, 2) . '/' . substr((string)$n['d'], 8, 2)) . ' '
         . '<b style="color:' . Rs::opinionColor((string)$n['opinion']) . '">' . pf_h(Rs::opinionLabel((string)$n['opinion'])) . '</b></a>';
}

/** 재점검 근거 칩들 */
function rs_stale_chips(array $stale): string
{
    $h = '';
    foreach ($stale as $s) $h .= '<span class="rs-todo k-' . pf_h($s['k']) . '" title="' . pf_h($s['text']) . '">' . pf_h($s['text']) . '</span>';
    return $h;
}

/**
 * 재무분석 상세의 리서치 카드 — 종목 개요 카드 바로 아래(2026-10-15).
 * 없으면 슬림한 한 줄 + 「＋ 리서치 올리기」(그 종목의 리서치 화면으로) · 있으면 최신 버전의 요지와 위치.
 */
function pf_fund_research_card(PDO $pdo, string $code): void
{
    $rs = new Rs($pdo);
    if (!$rs->ready()) return;                // 표가 없으면 카드도 없다(리서치 화면이 「표 만들기」를 보인다)
    $latest = $rs->latestMany([$code]);
    $n = $latest ? reset($latest) : null;
    rs_css();
    $url = '/stock/index.php?mode=research&code=' . rawurlencode($code);

    if (!$n) {
        echo '<div class="card" style="padding-top:11px;padding-bottom:11px">'
           . '<div style="display:flex;align-items:baseline;gap:10px;flex-wrap:wrap"><h2 style="margin:0">리서치</h2>'
           . '<span class="muted" style="font-size:12px">아직 없습니다 — Claude 로 만든 분석(.md)을 올리면 여기와 아래 차트(📑 깃발)에 표시됩니다</span>'
           . '<a class="btn btn-outline btn-sm" style="margin-left:auto" href="' . pf_h($url) . '#up">＋ 리서치 올리기</a></div></div>';
        return;
    }
    $px = rs_prices($pdo, [$code])[$code]['price'] ?? null;
    $base = rs_base_price($pdo, $n);
    $zs = rs_zone_state($n, $px);
    $stale = rs_stale($pdo, $n, $px);
    $next = rs_next_item($n);
    $durl = $url . '&id=' . (int)$n['id'];

    echo '<div class="card rs-fcard">';
    echo '<div style="display:flex;align-items:baseline;gap:10px;flex-wrap:wrap"><h2 style="margin:0">리서치</h2>'
       . '<span class="muted" style="font-size:12px">' . pf_h((string)$n['d']) . ' · ' . (int)$n['n'] . '번째 버전</span>'
       . '<a class="btn btn-outline btn-sm" style="margin-left:auto" href="' . pf_h($url) . '#up">＋ 새 버전</a>'
       . '<a class="btn btn-outline btn-sm" href="' . pf_h($durl) . '">전체 보기 →</a></div>';
    echo '<div class="rs-fc-top"><a class="rs-ftitle" href="' . pf_h($durl) . '">' . pf_h((string)$n['title']) . '</a>'
       . rs_op_chip((string)$n['opinion'])
       . ((string)$n['prev_opinion'] !== '' && $n['prev_opinion'] !== $n['opinion']
            ? '<span class="muted" style="font-size:11px">← ' . pf_h(Rs::opinionLabel((string)$n['prev_opinion'])) . '</span>' : '')
       . '</div>';
    if ((string)$n['headline'] !== '') echo '<div class="rs-head">' . pf_h((string)$n['headline']) . '</div>';
    echo '<div class="rs-facts">';
    if ($base && $px) {
        echo '<span>기준가 <b>' . number_format($base) . '</b> → 지금 <b>' . number_format($px) . '</b> '
           . '<em class="' . pf_updown($px / $base - 1) . '">' . pf_h(rs_pct($px / $base - 1)) . '</em></span>';
    }
    if ($next) echo '<span class="rs-next">' . pf_h($next['dday'] . ' ' . $next['when'] . ' ' . $next['text']) . '</span>';
    echo '</div>';
    echo rs_map_html(rs_price_map($n, $px, $base), $zs, true);
    if ($stale) echo '<div class="rs-stale"><b>재점검</b>' . rs_stale_chips($stale) . '</div>';
    echo '</div>';
}

// ══ 화면 ═══════════════════════════════════════════════════════════
function pf_page_research(PDO $pdo, Pf $pf): void
{
    $rs = new Rs($pdo);
    $code = Rs::cleanCode($_GET['code'] ?? '');
    if ($code !== '' && $rs->ready()) { rs_page_detail($pdo, $rs, $code); return; }
    rs_page_list($pdo, $rs);
}

function rs_page_list(PDO $pdo, Rs $rs): void
{
    pf_head('리서치', 'research');
    pf_flash();
    rs_css();
    echo '<div class="pf-head"><div><h1>리서치</h1>'
       . '<div class="sub">claude.ai 에서 만든 종목 분석을 <b>Markdown(.md)</b>으로 내보내 올리면, AI(Haiku)가 의견·시나리오·매수 구간·일정을 한 번 읽어 '
       . '<b>종목별 버전</b>으로 쌓습니다. 재무분석 상세의 리서치 카드와 차트(📑 깃발)에 표시됩니다.</div></div></div>';

    if (!$rs->ready()) {
        echo '<div class="card"><h2>리서치 표가 아직 없습니다</h2>'
           . '<p class="muted" style="font-size:13px">한 번만 만들면 됩니다(rs_note · rs_level · rs_item).</p>'
           . '<button type="button" class="btn btn-primary btn-sm" id="rsSchema">표 만들기</button> <span id="rsSchemaMsg" class="muted"></span></div>';
        echo '<script>document.getElementById("rsSchema").onclick=function(){var b=this;b.disabled=true;'
           . 'fetch("/stock/api.php?module=rs&action=schema",{method:"POST",credentials:"same-origin"}).then(function(r){return r.json();})'
           . '.then(function(j){if(j.ok)location.reload();else{b.disabled=false;document.getElementById("rsSchemaMsg").textContent=j.error||"실패";}})'
           . '.catch(function(){b.disabled=false;document.getElementById("rsSchemaMsg").textContent="서버에 닿지 못했습니다";});};</script>';
        pf_foot();
        return;
    }

    rs_upload_ui('');

    $view = in_array($_GET['view'] ?? '', ['feed', 'cal'], true) ? (string)$_GET['view'] : 'stock';
    $latest = $rs->latestMany();
    $total = $rs->count();
    $groups = $rs->scheduleGroups();
    $over = $open = 0;
    $today = date('Y-m-d');
    foreach ($groups as $x) {
        $b = rs_sched_bucket($x, $today);
        if ($b === 'over') $over++;
        elseif ($b === 'week' || $b === 'now') $open++;   // 일정 탭의 「오늘 · 7일 안」+「기간 중」과 같은 수
    }
    echo '<div class="rs-tabs">'
       . '<a href="/stock/index.php?mode=research"' . ($view === 'stock' ? ' class="on"' : '') . '>종목별 <b>' . count($latest) . '</b></a>'
       . '<a href="/stock/index.php?mode=research&view=cal"' . ($view === 'cal' ? ' class="on"' : '')
       . ' title="지났는데 확인 안 함 ' . $over . ' · 7일 안·진행 중 ' . $open . '">일정'
       . ($over ? ' <i class="rs-tab-over">' . $over . '</i>' : '') . ' <b>' . $open . '</b></a>'
       . '<a href="/stock/index.php?mode=research&view=feed"' . ($view === 'feed' ? ' class="on"' : '') . '>올린 순 <b>' . $total . '</b></a>'
       . '</div>';

    if (!$latest) {
        echo '<div class="card"><p class="muted" style="margin:0;font-size:13px">아직 리서치가 없습니다 — 위 칸에 .md 파일을 끌어다 놓으세요. '
           . '아래 「작성 형식」을 claude.ai 프로젝트 지침에 붙여 두면 AI 가 더 정확히 읽습니다.</p></div>';
        echo rs_format_guide();
        pf_foot();
        return;
    }

    if ($view === 'feed') {
        rs_list_feed($pdo, $rs);
    } elseif ($view === 'cal') {
        rs_list_schedule($pdo, $groups);
    } else {
        rs_list_stocks($pdo, $latest);
    }
    echo rs_format_guide();
    pf_foot();
}

/** 종목별 — 한 줄 = 한 종목의 최신 버전 · 할 일 순(재점검 → 매수 구간 안 → 최근) */
function rs_list_stocks(PDO $pdo, array $latest): void
{
    $codes = array_map(fn($n) => (string)$n['code'], array_values($latest));
    $px = rs_prices($pdo, $codes);
    $names = StockName::many($pdo, $codes);
    foreach ($codes as $c) if (!isset($names[$c])) $names[$c] = StockName::one($pdo, $c, $c);
    $held = array_flip((new Dt($pdo))->heldCodes());
    $top = (new Pf($pdo))->watchCodes();

    $rows = [];
    foreach ($latest as $n) {
        $p = $px[(string)$n['code']]['price'] ?? null;
        $rows[] = ['n' => $n, 'px' => $p, 'base' => rs_base_price($pdo, $n), 'stale' => rs_stale($pdo, $n, $p)];
    }
    rs_list_render($rows, $names, $held, $top);
}

/**
 * Top-Pick ☆ — 보유가 아닌 종목만(보유면 「보유」 배지 · 관심그룹의 「Top-Pick 은 보유하지 않은 것만」과 같은 말).
 * 2026-10-26 사용자 — 리서치를 읽고 내리는 판단이 곧 «담을까»라 그 자리에 둔다. 저장은 module=watch toggle 그대로(src=research).
 * 켜진 ★ 가 곧 Top-Pick 배지다 — 목록에선 「★ Top-Pick」 · 꺼지면 회색 ☆ 하나.
 */
function rs_star_html(string $code, string $name, bool $on, bool $big = false): string
{
    $cls = $big ? 'btn ' . ($on ? 'btn-primary' : 'btn-outline') . ' rs-star big' : 'rs-star' . ($on ? ' on' : '');
    $txt = $big ? ($on ? '★ Top-Pick' : '☆ Top-Pick') : ($on ? '★ Top-Pick' : '☆');
    return '<button type="button" class="' . $cls . '" data-code="' . pf_h($code) . '" data-name="' . pf_h($name)
         . '" title="Top-Pick에 담기/빼기">' . $txt . '</button>';
}

function rs_star_js(): string
{
    static $done = false;
    if ($done) return '';
    $done = true;
    return <<<'JS'
<script>
document.addEventListener('click', function(e){
  var b = e.target.closest ? e.target.closest('.rs-star') : null;
  if (!b || b.disabled) return;
  e.preventDefault();
  b.disabled = true;
  var body = new URLSearchParams({json:'1', src:'research', code:b.getAttribute('data-code'), name:b.getAttribute('data-name')});
  fetch('/stock/api.php?module=watch&action=toggle',
    {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body})
    .then(function(r){ return r.json(); })
    .then(function(j){
      if (!j || !j.ok) { alert((j && j.message) || '실패했습니다.'); return; }
      var on = j.message.indexOf('담았습니다') >= 0, big = b.classList.contains('big');
      b.textContent = on ? '★ Top-Pick' : (big ? '☆ Top-Pick' : '☆');
      if (big) { b.classList.toggle('btn-primary', on); b.classList.toggle('btn-outline', !on); }
      else b.classList.toggle('on', on);
    })
    .catch(function(){ alert('통신에 실패했습니다.'); })
    .then(function(){ b.disabled = false; });
});
</script>
JS;
}

/**
 * 종목별 표 그리기 — 재료(가격·기준가·재점검)는 rs_list_stocks 가 모은다. 판정(구간 상태·다음 일정·가격 지도)은 여기서 만든다.
 * 2026-10-22 사용자 — 「제목이 다 같은데 너비를 많이 차지한다」·「시나리오 위치가 중요한데 눈에 안 들어온다」:
 * 제목 칸을 없애고(의견 칸 아래 날짜 · 제목은 툴팁) 시나리오 위치·매수 구간 두 칸을 가격 지도 한 칸으로 합쳤다.
 */
function rs_list_render(array $rows, array $names, array $held, array $top = []): void
{
    foreach ($rows as $i => $r) {
        $rows[$i]['zs'] = rs_zone_state($r['n'], $r['px']);
        $rows[$i]['next'] = rs_next_item($r['n']);
        $rows[$i]['map'] = rs_price_map($r['n'], $r['px'], $r['base']);
    }
    usort($rows, function ($a, $b) {
        $ra = $a['stale'] ? 0 : ($a['zs']['cls'] === 'in' ? 1 : 2);
        $rb = $b['stale'] ? 0 : ($b['zs']['cls'] === 'in' ? 1 : 2);
        if ($ra !== $rb) return $ra <=> $rb;
        return strcmp((string)$b['n']['d'], (string)$a['n']['d']);
    });

    echo '<div class="card"><div class="tbl-scroll"><table class="pf rs-tbl"><thead><tr>'
       . '<th>종목</th><th>최근 리서치</th>'
       . '<th>가격 위치 <small class="muted">— 왼쪽이 싼 쪽 · 위 = 적정가(비관·기본·낙관) · 아래 = <span class="rs-lg-z">매수 구간</span> · ○ = 리서치 때 주가(기준가) · 오른쪽 = 현재가가 그 기준보다 몇 % 위·아래</small></th><th>할 일</th>'
       . '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $n = $r['n'];
        $c = (string)$n['code'];
        $name = $names[$c] ?? $c;
        $url = '/stock/index.php?mode=research&code=' . rawurlencode($c);
        $days = (int)floor((time() - strtotime((string)$n['d'])) / 86400);
        $tip = (string)$n['title'] . ((string)$n['headline'] !== '' ? "\n\n" . (string)$n['headline'] : '');
        echo '<tr>';
        echo '<td class="stk"><a href="' . pf_h($url) . '">' . pf_h($name) . '</a><span class="code">' . pf_h($c) . '</span>'
           . (isset($held[$c]) ? ' <span class="badge st-held">보유</span>' : ' ' . rs_star_html($c, $name, isset($top[$c]))) . '</td>';
        echo '<td class="rs-c-op"><a href="' . pf_h($url . '&id=' . (int)$n['id']) . '" title="' . pf_h($tip) . '">' . rs_op_chip((string)$n['opinion'])
           . ((string)$n['prev_opinion'] !== '' && $n['prev_opinion'] !== $n['opinion']
                ? '<span class="rs-prev">← ' . pf_h(Rs::opinionLabel((string)$n['prev_opinion'])) . '</span>' : '')
           . '<div class="muted">' . pf_h(substr((string)$n['d'], 5, 2) . '/' . substr((string)$n['d'], 8, 2)) . ' · ' . ($days <= 0 ? '오늘' : $days . '일 전')
           . ((int)$n['n'] > 1 ? ' · ' . (int)$n['n'] . '번째' : '') . '</div></a></td>';
        // 기준가 → 현재는 가격 지도의 흐름 줄(○ ━▶ ●)이 말한다 — 지도가 없을 때(시나리오·구간 둘 다 없음)만 글자로
        $plain = $r['px'] ? ($r['base'] ? '<span class="muted">' . number_format($r['base']) . ' →</span> ' : '') . '<b>' . number_format($r['px']) . '</b>'
               . ($r['base'] ? ' <span class="' . pf_updown($r['px'] / $r['base'] - 1) . '">' . pf_h(rs_pct($r['px'] / $r['base'] - 1)) . '</span>' : '') : '-';
        echo '<td class="rs-c-map">' . ($r['map'] ? rs_map_html($r['map'], $r['zs']) : $plain) . '</td>';
        echo '<td class="rs-c-todo">';
        if ($r['stale']) echo rs_stale_chips($r['stale']);
        elseif ($r['next']) {
            $nt = $r['next']['dday'] . ' · ' . $r['next']['text'];
            echo '<span class="rs-next" title="' . pf_h($nt) . '">' . pf_h($nt) . '</span>';
        }
        else echo '<span class="muted">-</span>';
        echo '</td></tr>';
    }
    echo '</tbody></table></div>' . rs_star_js()
       . '<p class="muted" style="font-size:11.5px;margin:8px 0 0"><span class="badge st-held">보유</span> 실제로 담고 있음 · '
       . '<b style="color:#d99000">★ Top-Pick</b> 담아 두고 지켜보는 중(☆ 를 누르면 담기/빼기 — 보유 종목엔 없습니다) · 둘 다 없으면 리서치만 한 종목.<br>할 일 순 —<b>재점검</b>(리서치 뒤 새 정기공시 · 문서의 일정이 지남 · 비관/마지막 구간 아래 · '
       . Rs::STALE_DAYS . '일 지남) → 매수 구간 안 → 최근 리서치. 매수 구간의 「부근」은 한 가격으로 적힌 구간의 ±' . (Rs::NEAR_PCT * 100) . '% 입니다(가격 위치에선 그 폭의 띠).</p></div>';
}

/** 올린 순 — 문서 단위 피드 */
function rs_list_feed(PDO $pdo, Rs $rs): void
{
    $feed = $rs->feed(80);
    $names = StockName::many($pdo, array_map(fn($n) => (string)$n['code'], $feed));
    echo '<div class="rs-feed">';
    foreach ($feed as $n) {
        $c = (string)$n['code'];
        $url = '/stock/index.php?mode=research&code=' . rawurlencode($c) . '&id=' . (int)$n['id'];
        echo '<a class="card rs-fitem" href="' . pf_h($url) . '">'
           . '<div class="rs-fi-top"><b>' . pf_h($names[$c] ?? $c) . '</b><span class="muted">' . pf_h($c) . '</span>'
           . rs_op_chip((string)$n['opinion']) . '<span class="muted" style="margin-left:auto">기준일 ' . pf_h((string)$n['d'])
           . ' · 올림 ' . pf_h(substr((string)$n['created_at'], 0, 16)) . '</span></div>'
           . '<div class="rs-fi-title">' . pf_h((string)$n['title']) . '</div>'
           . ((string)$n['headline'] !== '' ? '<div class="rs-fi-head">' . pf_h((string)$n['headline']) . '</div>' : '')
           . '</a>';
    }
    echo '</div>';
}

// ══ 일정 (2026-10-26) ═══════════════════════════════════════════════
/*
 * 사용자 — 「일정이 그 종목 리서치에 들어가야만 보인다 · 해당일에 체크하고 그때 Claude 에게 리서치 검증을 받고 싶다」.
 * 재료는 Rs::scheduleGroups() 단일본(종목마다 최신 버전 · 종목·날짜·정밀도로 묶음). 같은 묶음을 아침 알림·캘린더·폰 위젯도 읽는다.
 * 검증은 서버가 Claude 를 부르지 않는다 — 「검증 요청문」을 claude.ai 에 붙이고(웹 검색으로 결과 확인) 나온 .md 를 다시 올리면
 * 새 버전이 되어 그 일정은 저절로 닫힌다(크론에 AI 를 걸지 않는다 · 리서치 규칙 3).
 */

/** 묶음이 지금 어느 칸인가 — over(지났는데 확인 안 함) · week(7일 안) · now(기간 중) · month(한 달 안) · later · done */
function rs_sched_bucket(array $x, string $today): string
{
    if ($x['done']) return 'done';
    if ($x['end'] < $today) return 'over';
    $w = date('Y-m-d', strtotime($today . ' +7 days'));
    $m = date('Y-m-d', strtotime($today . ' +31 days'));
    if ($x['prec'] === 'day') return $x['d'] <= $w ? 'week' : ($x['d'] <= $m ? 'month' : 'later');
    return $x['d'] <= $today ? 'now' : ($x['d'] <= $m ? 'month' : 'later');
}

/** 묶음의 날짜 글자 — [큰 글자, 작은 글자] : 「10/27」「D-1 · 화」 · 「11월」「~11/30」 */
function rs_sched_when(array $x, string $today): array
{
    $dw = ['일', '월', '화', '수', '목', '금', '토'];
    $t = strtotime($x['d']);
    $yy = date('Y', $t) !== substr($today, 0, 4) ? date('y', $t) . '년 ' : '';
    if ($x['prec'] === 'day') {
        $big = $yy . date('n/j', $t);
        $dd = (int)round((strtotime($x['d']) - strtotime($today)) / 86400);
        $sm = ($dd === 0 ? '오늘' : ($dd > 0 ? 'D-' . $dd : (-$dd) . '일 지남')) . ' · ' . $dw[(int)date('w', $t)];
        return [$big, $sm];
    }
    $big = $x['prec'] === 'month' ? $yy . date('n', $t) . '월' : rs_date_label($x['d'], $x['prec']);
    $endT = strtotime($x['end']);
    if ($x['end'] < $today) $sm = (int)round((strtotime($today) - $endT) / 86400) . '일 지남';
    elseif ($x['d'] <= $today) $sm = '기간 중 · ~' . date('n/j', $endT);
    else $sm = 'D-' . (int)round(($t - strtotime($today)) / 86400) . ' 시작';
    return [$big, $sm];
}

/** 그 기간에 이 종목의 정기공시가 접수됐나 — 「발표됐다」는 판정이 아니라 사실 한 줄(pf_sue_receipts 단일본) */
function rs_sched_filing(PDO $pdo, array $x, array &$cache): string
{
    if (!function_exists('pf_sue_receipts')) return '';
    $c = $x['code'];
    if (!isset($cache[$c])) { try { $cache[$c] = pf_sue_receipts($pdo, $c); } catch (Throwable $e) { $cache[$c] = []; } }
    $lo = $x['prec'] === 'day' ? date('Y-m-d', strtotime($x['d'] . ' -3 days')) : $x['d'];
    $hi = $x['prec'] === 'day' ? date('Y-m-d', strtotime($x['d'] . ' +3 days')) : $x['end'];
    $out = [];
    foreach ($cache[$c] as $qk => $dt) {
        if ($dt < $lo || $dt > $hi || $dt <= $x['note_d']) continue;
        $y = intdiv($qk - 1, 4);
        $out[] = '정기공시 ' . date('n/j', strtotime($dt)) . ' 접수 (' . ($y % 100) . '.' . ($qk - $y * 4) . 'Q)';
    }
    return $out ? '<span class="rs-sg-fil" title="이 기간에 이 종목의 정기공시가 접수됐습니다 — 결과는 검증 요청문으로 확인하세요">' . pf_h(implode(' · ', $out)) . '</span>' : '';
}

function rs_list_schedule(PDO $pdo, array $groups): void
{
    $today = date('Y-m-d');
    $by = ['over' => [], 'week' => [], 'now' => [], 'month' => [], 'later' => [], 'done' => []];
    foreach ($groups as $x) $by[rs_sched_bucket($x, $today)][] = $x;
    // 확인함은 최근에 확인한 것이 앞
    usort($by['done'], function ($a, $b) {
        $ta = max(array_column($a['items'], 'done_at') ?: ['']);
        $tb = max(array_column($b['items'], 'done_at') ?: ['']);
        return strcmp($tb, $ta);
    });
    $codes = array_values(array_unique(array_column($groups, 'code')));
    $names = $codes ? StockName::many($pdo, $codes) : [];
    $held = array_flip((new Dt($pdo))->heldCodes());
    $fcache = [];

    $SEC = [   // 칸의 이름·설명 — 탭 숫자(rs_page_list)는 week+now 를 센다
        'over'  => ['지났는데 확인 안 함', '날짜(기간)가 지났습니다 — 결과를 확인하고 ✓, 리서치를 고칠 일이면 「검증 요청문」', false],
        'week'  => ['오늘 · 7일 안', '', false],
        'now'   => ['기간 중 — 날짜 미정', '문서가 「11월」·「4분기」처럼 기간으로 적은 일정 · 그 기간이 끝나면 위 「지났는데」로 올라갑니다', false],
        'month' => ['한 달 안', '', false],
        'later' => ['그 뒤', '', true],
        'done'  => ['확인함', '✓ 를 다시 누르면 되돌립니다 · 새 리서치를 올리면 그 종목의 옛 일정은 새 일정으로 바뀝니다', true],
    ];
    if (!$groups) {
        echo '<div class="card"><p class="muted" style="margin:0;font-size:13px">리서치 문서에 날짜가 적힌 일정·확인 신호가 아직 없습니다.</p></div>';
        return;
    }
    echo '<div class="card rs-sched">';
    echo '<p class="muted rs-sg-intro">각 종목 <b>최신 리서치</b>의 일정과 날짜가 있는 확인 신호를 종목·날짜로 묶었습니다. '
       . '날이 오면 결과를 보고 <b>✓ 확인</b> — 리서치를 다시 따져 볼 일이면 <b>검증 요청문</b>을 복사해 claude.ai 에 붙이세요'
       . '(웹 검색으로 결과를 확인하고 같은 형식으로 갱신해 달라는 글입니다). 나온 .md 를 위 칸에 올리면 새 버전이 되고 그 일정은 닫힙니다. '
       . '평일 아침(08:05)에 그날 시작하는 일정과 방금 지난 일정을 알림으로 보내고, 날짜가 정해진 일정은 업무 캘린더에도 보입니다.</p>';
    foreach ($SEC as $k => [$title, $sub, $fold]) {
        $list = $by[$k];
        if (!$list && $k !== 'over' && $k !== 'week') continue;
        if ($k === 'done') $list = array_slice($list, 0, 60);
        $head = '<span class="rs-sg-h rs-sg-h-' . $k . '">' . pf_h($title) . ' <b>' . count($by[$k]) . '</b></span>'
              . ($sub !== '' ? ' <small class="muted">' . pf_h($sub) . '</small>' : '');
        echo $fold ? '<details class="rs-sg-sec"><summary>' . $head . '</summary>' : '<div class="rs-sg-sec">' . $head;
        if (!$list) echo '<div class="muted rs-sg-none">' . ($k === 'over' ? '없습니다 — 지난 일정은 모두 확인했습니다.' : '7일 안에 날짜가 정해진 일정이 없습니다.') . '</div>';
        foreach ($list as $x) echo rs_sched_row($pdo, $x, $names, $held, $today, $k, $fcache);
        echo $fold ? '</details>' : '</div>';
    }
    echo '</div>';
    echo rs_sched_js();
}

/** 묶음 한 줄 — 날짜 | 종목 | 일정·확인 신호 | ✓ · 검증 요청문 */
function rs_sched_row(PDO $pdo, array $x, array $names, array $held, string $today, string $bucket, array &$fcache): string
{
    [$big, $sm] = rs_sched_when($x, $today);
    $c = $x['code'];
    $ev = array_values(array_filter($x['items'], fn($i) => $i['kind'] === 'event'));
    $okIds = array_column($ev ?: $x['items'], 'id');
    $allIds = array_column($x['items'], 'id');
    $anchor = 'g' . $x['note_id'] . '-' . str_replace('-', '', $x['d']) . ($x['prec'] !== 'day' ? '-' . $x['prec'] : '');
    $h = '<div class="rs-sg rs-sg-' . $bucket . '" id="' . pf_h($anchor) . '">';
    $h .= '<div class="rs-sg-d"><b>' . pf_h($big) . '</b><small>' . pf_h($sm) . '</small></div>';
    $h .= '<div class="rs-sg-s"><a href="/stock/index.php?mode=research&code=' . rawurlencode($c) . '&id=' . (int)$x['note_id'] . '">'
        . pf_h($names[$c] ?? $c) . '</a><span class="code">' . pf_h($c) . '</span>'
        . (isset($held[$c]) ? ' <span class="badge st-held">보유</span>' : '')
        . '<small class="muted">리서치 ' . pf_h(date('n/j', strtotime($x['note_d']))) . '</small></div>';
    $h .= '<div class="rs-sg-i">';
    foreach ($x['items'] as $i) {
        if ($i['kind'] === 'event') {
            $h .= '<div class="rs-sg-ev' . ($i['done'] ? ' done' : '') . '">📅 ' . pf_h($i['text']) . '</div>';
        } else {
            $h .= '<label class="rs-sg-ck"><input type="checkbox" data-item="' . $i['id'] . '"' . ($i['done'] ? ' checked' : '') . '> '
                . '<span>' . pf_h($i['text']) . '</span></label>';
        }
    }
    $h .= rs_sched_filing($pdo, $x, $fcache);
    if ($x['done']) {
        $at = max(array_column($ev ?: $x['items'], 'done_at') ?: ['']);
        if ($at !== '') $h .= '<span class="rs-sg-at">' . pf_h(date('n/j', strtotime($at))) . ' 확인</span>';
    }
    $h .= '</div>';
    $h .= '<div class="rs-sg-a">'
        . '<button type="button" class="btn btn-sm ' . ($x['done'] ? 'btn-outline' : 'btn-primary') . ' rs-sg-ok" data-items="' . implode(',', $okIds) . '" data-on="' . ($x['done'] ? 1 : 0) . '"'
        . ' title="' . ($x['done'] ? '확인 표시를 되돌립니다' : ($ev ? '일정을 확인했다고 표시합니다(확인 신호의 체크는 따로)' : '확인 신호를 모두 확인했다고 표시합니다')) . '">'
        . ($x['done'] ? '✓ 확인함' : '✓ 확인') . '</button>'
        . '<button type="button" class="btn btn-outline btn-sm rs-sg-pr" data-note="' . (int)$x['note_id'] . '" data-items="' . implode(',', $allIds) . '"'
        . ' title="이 일정을 중심으로 리서치를 검증해 달라는 글 — claude.ai 에 붙여 넣으세요">검증 요청문</button>'
        . '</div></div>';
    return $h;
}

function rs_sched_js(): string
{
    return <<<'JS'
<script>
(function(){
  var API = '/stock/api.php?module=rs&action=';
  function post(a, data){
    var fd = new FormData(); for (var k in data) fd.append(k, data[k]);
    return fetch(API + a, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function(r){ return r.json(); });
  }
  document.addEventListener('change', function(e){
    var c = e.target;
    if (!c.matches || !c.matches('.rs-sg input[data-item]')) return;
    c.disabled = true;
    post('done', { items: c.dataset.item, done: c.checked ? 1 : 0 })
      .then(function(j){ c.disabled = false; if (!j.ok) { c.checked = !c.checked; alert(j.error || '저장하지 못했습니다.'); } })
      .catch(function(){ c.disabled = false; c.checked = !c.checked; alert('서버에 닿지 못했습니다.'); });
  });
  document.addEventListener('click', function(e){
    var b = e.target.closest ? e.target.closest('.rs-sg-ok') : null;
    if (b) {
      b.disabled = true;
      post('done', { items: b.dataset.items, done: b.dataset.on === '1' ? 0 : 1 })
        .then(function(j){ if (j.ok) location.reload(); else { b.disabled = false; alert(j.error || '저장하지 못했습니다.'); } })
        .catch(function(){ b.disabled = false; alert('서버에 닿지 못했습니다.'); });
      return;
    }
    var p = e.target.closest ? e.target.closest('.rs-sg-pr') : null;
    if (p) {
      p.disabled = true;
      fetch(API + 'prompt&id=' + encodeURIComponent(p.dataset.note) + '&items=' + encodeURIComponent(p.dataset.items), { credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(j){ p.disabled = false; if (!j.ok) { alert(j.error || '만들지 못했습니다.'); return; } RS.promptText(j.data.text, j.data.title); })
        .catch(function(){ p.disabled = false; alert('서버에 닿지 못했습니다.'); });
    }
  });
  // 캘린더·알림에서 들어온 묶음(#g…)은 펴서 짚는다
  if (location.hash.length > 2) {
    var el = document.getElementById(location.hash.slice(1));
    if (el) {
      var d = el.closest('details'); if (d) d.open = true;
      el.classList.add('hl');
      setTimeout(function(){ el.scrollIntoView({ block: 'center' }); }, 50);
    }
  }
})();
</script>
JS;
}

/**
 * 아침 알림 — 평일 08:05 dart_fresh 끝(크론 줄을 늘리지 않았다). 묶음마다 두 번까지만 운다(pf_alert_log · kind 'rsev'):
 *   ①시작 — 일 일정은 그날, 기간 일정은 기간 첫날(주말·휴일에 시작한 것은 다음 평일 아침에 · 창 4일)
 *   ②지남 — 끝난 지 4일 안인데 ✓ 가 없으면 한 번.
 * 기간 일정은 한꺼번에 시작한다(11/1 에 「11월」 열 몇 개) — 종목 이름만 한 줄로 접는다.
 * @return string[] 보낸 줄(dry 면 보낼 줄 · 기록도 안 한다)
 */
function rs_alert_events(PDO $pdo, bool $dry = false): array
{
    $rs = new Rs($pdo);
    if (!$rs->ready()) return [];
    $today = date('Y-m-d');
    $from = date('Y-m-d', strtotime($today . ' -3 days'));
    $groups = $rs->scheduleGroups();
    if (!$groups) return [];
    $names = StockName::many($pdo, array_values(array_unique(array_column($groups, 'code'))));
    $nm = fn(array $x): string => $names[$x['code']] ?? $x['code'];
    $first = fn(array $x): string => mb_strlen($x['items'][0]['text']) > 34 ? mb_substr($x['items'][0]['text'], 0, 33) . '…' : $x['items'][0]['text'];
    $key = fn(string $t, array $x): string => 'rs|' . $t . '|' . $x['note_id'] . '|' . $x['d'] . '|' . $x['prec'];
    $isNew = function (string $ref) use ($pdo, $dry): bool {
        if (!$dry) return pf_alert_new($pdo, 'rsev', $ref);
        $st = $pdo->prepare("SELECT 1 FROM pf_alert_log WHERE kind = 'rsev' AND ref = ?");
        $st->execute([mb_substr($ref, 0, 60)]);
        return !$st->fetchColumn();
    };

    $day = $per = $over = [];
    $overAll = 0;
    foreach ($groups as $x) {
        if ($x['done']) continue;
        if ($x['end'] < $today) {
            $overAll++;
            if ($x['end'] >= date('Y-m-d', strtotime($today . ' -4 days')) && $isNew($key('o', $x))) $over[] = $x;
            continue;
        }
        if ($x['d'] < $from || $x['d'] > $today) continue;
        if ($x['d'] <= $x['note_d']) continue;   // 문서를 쓸 때 이미 시작한 일정(「하반기」 등)은 «시작»이 아니다
        if (!$isNew($key('s', $x))) continue;
        if ($x['prec'] === 'day') $day[] = $x; else $per[rs_date_label($x['d'], $x['prec'])][] = $x;
    }
    $L = [];
    foreach ($day as $x) {
        $nc = count(array_filter($x['items'], fn($i) => $i['kind'] === 'check'));
        $L[] = ($x['d'] === $today ? '오늘' : date('n/j', strtotime($x['d']))) . ' · ' . $nm($x) . ' — ' . $first($x) . ($nc ? ' (확인 신호 ' . $nc . ')' : '');
    }
    foreach ($per as $lab => $xs) {
        $ns = array_values(array_unique(array_map($nm, $xs)));
        $L[] = $lab . ' 일정 시작 ' . count($xs) . '건 · ' . implode('·', array_slice($ns, 0, 6)) . (count($ns) > 6 ? ' 외 ' . (count($ns) - 6) : '');
    }
    if (count($over) > 3) {
        $ns = array_values(array_unique(array_map($nm, $over)));
        $L[] = '⏰ 방금 지남 ' . count($over) . '건 · ' . implode('·', array_slice($ns, 0, 6)) . (count($ns) > 6 ? ' 외 ' . (count($ns) - 6) : '');
    } else {
        foreach ($over as $x) $L[] = '⏰ 지남 · ' . $nm($x) . ' — ' . $first($x) . ' → 결과 확인';
    }
    if (!$L) return [];
    if ($overAll > count($over)) $L[] = '지났는데 확인 안 한 일정 모두 ' . $overAll . '건';
    if (!$dry) pf_alert_send('📅 리서치 일정', $L, 'https://economist.kr/stock/index.php?mode=research&view=cal');
    return $L;
}

/** 종목 상세 — 버전 타임라인 | 고른 버전(요지·표·차트·원문) */
function rs_page_detail(PDO $pdo, Rs $rs, string $code): void
{
    $versions = $rs->versions($code);
    $name = StockName::one($pdo, $code, $code);
    pf_head('리서치 · ' . $name, 'research');
    pf_flash();
    rs_css();

    $held = in_array($code, (new Dt($pdo))->heldCodes(), true);
    $top = isset((new Pf($pdo))->watchCodes()[$code]);
    echo '<div class="pf-head"><div><h1>' . pf_h($name) . ' <span class="muted" style="font-size:14px;font-weight:600">' . pf_h($code) . '</span>'
       . ($held ? ' <span class="badge st-held" style="vertical-align:4px">보유</span>'
                : ' <span style="vertical-align:3px;margin-left:4px">' . rs_star_html($code, $name, $top, true) . '</span>' . rs_star_js()) . '</h1>'
       . '<div class="sub"><a href="/stock/index.php?mode=research">‹ 리서치 목록</a> · '
       . '<a href="/stock/index.php?mode=fund&code=' . rawurlencode($code) . '">재무분석</a> · '
       . '<a href="/stock/index.php?mode=short&code=' . rawurlencode($code) . '">단타</a></div></div></div>';

    if (!$versions) {
        echo '<div class="card"><p class="muted" style="margin:0;font-size:13px">이 종목의 리서치가 아직 없습니다 — 아래 칸에 .md 를 올리세요.</p></div>';
        rs_upload_ui($code);
        echo rs_format_guide();
        pf_foot();
        return;
    }

    $sel = (int)($_GET['id'] ?? 0);
    $idx = 0;
    foreach ($versions as $i => $v) if ((int)$v['id'] === $sel) { $idx = $i; break; }
    $cur = $rs->get((int)$versions[$idx]['id']);
    $prev = $versions[$idx + 1] ?? null;
    $px = rs_prices($pdo, [$code])[$code]['price'] ?? null;

    // 버전마다 「그 뒤 주가」 — 이 버전의 기준가 → 다음(더 새) 버전의 기준가 · 최신은 → 지금
    $bases = [];
    foreach ($versions as $i => $v) $bases[$i] = rs_base_price($pdo, $v);

    echo '<div class="rs-grid"><aside class="rs-tl card"><h2>버전 ' . count($versions) . '</h2>';
    foreach ($versions as $i => $v) {
        $end = $i === 0 ? $px : ($bases[$i - 1] ?? null);
        $chg = ($bases[$i] && $end) ? $end / $bases[$i] - 1 : null;
        echo '<a class="rs-tl-it' . ($i === $idx ? ' on' : '') . '" href="/stock/index.php?mode=research&code=' . rawurlencode($code) . '&id=' . (int)$v['id'] . '">'
           . '<div class="rs-tl-d">' . pf_h((string)$v['d']) . rs_op_chip((string)$v['opinion'], 'sm') . '</div>'
           . '<div class="rs-tl-t">' . pf_h((string)$v['title']) . '</div>'
           . ($chg !== null ? '<div class="rs-tl-c">' . ($i === 0 ? '→ 지금 ' : '→ 다음 리서치까지 ')
                              . '<b class="' . pf_updown($chg) . '">' . pf_h(rs_pct($chg)) . '</b></div>' : '')
           . '</a>';
    }
    echo '<button type="button" class="btn btn-outline btn-sm" style="width:100%;margin-top:10px" onclick="RS.showUpload()">＋ 다음 리서치 올리기</button>';
    echo '</aside><div class="rs-main">';

    rs_detail_summary($pdo, $cur, $prev, $px, $name, $idx === 0);
    rs_detail_tables($cur, $px);
    rs_detail_chart($pdo, $code, (int)$cur['id']);

    echo '<div class="card rs-doc"><div style="display:flex;align-items:baseline;gap:10px"><h2 style="margin:0">원문</h2>'
       . '<span class="muted" style="font-size:11.5px">' . pf_h((string)$cur['src_name']) . ' · 올림 ' . pf_h(substr((string)$cur['created_at'], 0, 16))
       . ((string)$cur['ai_model'] !== '' ? ' · 읽은 AI ' . pf_h((string)$cur['ai_model']) : '') . '</span></div>'
       . '<div class="rs-md">' . rs_md_html((string)$cur['body']) . '</div></div>';

    rs_upload_ui($code, true);   // 올리기 칸은 접어 둔다(「＋ 다음 리서치 올리기」·#up·끌어다 놓기로 펴진다) — 창(모달)은 접지 않는다
    echo '</div></div>';   // rs-main · rs-grid

    $edit = ['id' => (int)$cur['id'], 'draft' => Rs::toDraft($cur), 'stock' => ['code' => $code, 'name' => $name]];
    echo '<script>RS.edit = ' . rs_js($edit) . ';</script>';
    pf_foot();
}

/**
 * 사업 개요 + 사업 태그(그 버전의 스냅샷) — 종목 태그에 이미 있는 것은 스크리너 링크 칩, 아직 없는 것은 점선 칩.
 * 「종목 태그에 더하기」는 누를 때만(module=rs&action=tags_apply · 더하기만 · 종목 개요가 비었으면 사업 개요로 채움).
 */
function rs_biz_line(PDO $pdo, array $n): string
{
    $biz = trim((string)($n['biz'] ?? ''));
    $tags = Rs::tagList((string)($n['tags'] ?? ''));
    if ($biz === '' && !$tags) {
        return '<div class="rs-biz none"><b>사업</b> <span class="muted">이 버전엔 사업 개요·태그가 없습니다 — 「값 고치기」에서 적거나, 다음 리서치부터 작성 형식의 「사업 개요」 절로 받습니다.</span></div>';
    }
    $code = (string)$n['code'];
    $have = array_map('mb_strtolower', pf_tags_of($pdo, [$code])[$code] ?? []);
    $note = pf_note_get($pdo, $code);
    $need = trim((string)($note['summary'] ?? '')) === '' && $biz !== '';
    $new = 0;
    pf_tag_css();
    $chips = '';
    foreach ($tags as $t) {
        if (in_array(mb_strtolower($t), $have, true)) { $chips .= pf_tag_chips([$t]); continue; }
        $new++;
        $chips .= '<span class="stag rs-tnew" title="' . pf_h('아직 종목 태그가 아닙니다 — 「종목 태그에 더하기」를 누르면 재무분석 개요·스크리너 태그에 붙습니다') . '">#' . pf_h($t) . '</span>';
    }
    $btn = ($new || $need)
        ? ' <button type="button" class="rs-tapply" id="rsTagApply" data-id="' . (int)$n['id'] . '" title="'
          . pf_h('이 리서치의 태그를 종목 태그에 더합니다(내가 단 태그는 그대로)' . ($need ? ' · 종목 개요의 「사업 개요」가 비어 있어 이 사업 개요로 채웁니다' : ''))
          . '">종목 태그에 더하기' . ($new ? ' ' . $new : '') . '</button>'
        : '';
    return '<div class="rs-biz"><b>사업</b>' . ($biz !== '' ? ' <span>' . pf_h($biz) . '</span>' : '')
         . ($chips !== '' || $btn !== '' ? '<div class="rs-btags">' . $chips . $btn . '</div>' : '') . '</div>';
}

/** 고른 버전의 요지 카드 — 결론·이전 대비·지금 위치·재점검·버튼 */
function rs_detail_summary(PDO $pdo, array $n, ?array $prev, ?float $px, string $name, bool $isLatest): void
{
    $base = rs_base_price($pdo, $n);
    $zs = rs_zone_state($n, $px);
    $stale = $isLatest ? rs_stale($pdo, $n, $px) : [];
    $next = rs_next_item($n);
    $diff = rs_diff($prev, $n);

    echo '<div class="card rs-sum">';
    echo '<div class="rs-sum-top"><div><div class="rs-sum-title">' . pf_h((string)$n['title']) . '</div>'
       . '<div class="muted" style="font-size:12px">기준일 ' . pf_h((string)$n['d'])
       . ($isLatest ? ' · <b style="color:#1d5c93">최신</b>' : ' · 옛 버전 — 재점검 표시는 최신 버전에만') . '</div></div>'
       . '<div class="rs-sum-op">' . rs_op_chip((string)$n['opinion'], 'lg') . '</div></div>';
    if ((string)$n['headline'] !== '') echo '<div class="rs-head lg">' . pf_h((string)$n['headline']) . '</div>';
    echo rs_biz_line($pdo, $n);
    if ($diff) echo '<div class="rs-diff"><b>앞 버전(' . pf_h((string)$prev['d']) . ') 대비</b>' . implode('', array_map(fn($t) => '<span>' . pf_h($t) . '</span>', $diff)) . '</div>';
    if ((string)$n['review'] !== '') echo '<div class="rs-review"><b>지난 판단 점검</b> ' . pf_h((string)$n['review']) . '</div>';

    echo '<div class="rs-facts">';
    if ($base) {
        echo '<span>기준가 <b>' . number_format($base) . '</b>'
           . ((string)($n['price_ref_d'] ?? '') !== '' ? ' <small class="muted">(' . pf_h((string)$n['price_ref_d']) . ')</small>' : '');
        if ($px) echo ' → 지금 <b>' . number_format($px) . '</b> <em class="' . pf_updown($px / $base - 1) . '">' . pf_h(rs_pct($px / $base - 1)) . '</em>';
        echo '</span>';
    }
    if ($next) echo '<span class="rs-next">' . pf_h($next['dday'] . ' · ' . $next['when'] . ' ' . $next['text']) . '</span>';
    echo '</div>';
    echo rs_map_html(rs_price_map($n, $px, $base), $zs, true);
    if ($stale) echo '<div class="rs-stale"><b>재점검</b>' . rs_stale_chips($stale) . '</div>';

    echo '<div class="rs-acts">'
       . '<button type="button" class="btn btn-primary btn-sm" onclick="RS.showPrompt()">재점검 요청문</button>'
       . '<button type="button" class="btn btn-outline btn-sm" onclick="RS.openEdit()">값 고치기</button>'
       . '<a class="btn btn-outline btn-sm" href="/stock/api.php?module=rs&action=raw&id=' . (int)$n['id'] . '">원문 받기(.md)</a>'
       . '<button type="button" class="btn btn-outline btn-sm rs-del" id="rsDel" data-id="' . (int)$n['id'] . '">삭제</button>'
       . '</div>';
    echo '<textarea id="rsPromptText" hidden>' . pf_h(rs_review_prompt($pdo, $n, $name, $px, $stale)) . '</textarea>';
    echo '</div>';
}

/** 시나리오·매수 구간·확인 신호·일정·리스크·증권사 목표가 */
function rs_detail_tables(array $n, ?float $px): void
{
    $sc = [];
    foreach ($n['levels'] as $v) if ($v['kind'] === 'scen') $sc[] = $v;
    $zones = rs_zones($n);
    $tg = [];
    foreach ($n['levels'] as $v) if ($v['kind'] === 'target') $tg[] = $v;
    $items = ['check' => [], 'event' => [], 'risk' => []];
    foreach ($n['items'] as $it) $items[$it['kind']][] = $it;
    $today = date('Y-m-d');

    echo '<div class="rs-2col">';
    // 시나리오
    echo '<div class="card"><h2>시나리오 <small class="muted">주당 가치</small></h2>';
    if ($sc) {
        echo '<table class="pf rs-mini"><tbody>';
        foreach ($sc as $v) {
            $val = (int)$v['lo'];
            echo '<tr><td><b>' . pf_h($v['label']) . '</b>' . ($v['basis'] !== '' ? ' <span class="muted">' . pf_h($v['basis']) . '</span>' : '') . '</td>'
               . '<td class="num"><b>' . number_format($val) . '</b>'
               . ($px ? '<div class="muted" style="font-size:11px">지금 대비 ' . pf_h(rs_pct($val / $px - 1)) . '</div>' : '') . '</td>'
               . '<td class="rs-memo">' . pf_h($v['memo']) . '</td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p class="muted rs-none">문서에 시나리오가 없습니다.</p>';
    }
    echo '</div>';
    // 매수 구간
    echo '<div class="card"><h2>매수 구간</h2>';
    if ($zones) {
        echo '<table class="pf rs-mini"><tbody>';
        foreach ($zones as $z) {
            $in = $px && (($px >= $z['lo'] && $px <= $z['hi'] && $z['lo'] !== $z['hi'])
                          || ($z['lo'] === $z['hi'] && abs($px / $z['lo'] - 1) <= Rs::NEAR_PCT));
            echo '<tr' . ($in ? ' class="rs-zin"' : '') . '><td><b>' . pf_h($z['label']) . '</b></td>'
               . '<td class="num"><b>' . pf_h($z['lo'] === $z['hi'] ? number_format($z['lo']) : number_format($z['lo']) . '~' . number_format($z['hi'])) . '</b>'
               . ($px ? '<div class="muted" style="font-size:11px">' . ($in ? '지금 이 구간' : '지금 대비 ' . pf_h(rs_pct($z['hi'] / $px - 1))) . '</div>' : '') . '</td>'
               . '<td class="rs-memo">' . pf_h($z['memo']) . '</td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p class="muted rs-none">문서에 가격으로 적힌 매수 구간이 없습니다.</p>';
    }
    echo '</div>';
    // 확인 신호 — 사용자가 「확인했다」를 표시한다(판정이 아니다)
    echo '<div class="card"><h2>확인 신호 <small class="muted">확인하면 체크 — 다음 재점검 요청문에 실린다</small></h2>';
    if ($items['check']) {
        echo '<ul class="rs-checks">';
        foreach ($items['check'] as $it) {
            echo '<li class="rs-chk"><label><input type="checkbox" data-item="' . (int)$it['id'] . '"' . ((int)$it['done'] === 1 ? ' checked' : '') . '> '
               . '<span>' . pf_h((string)$it['text']) . '</span></label>'
               . ($it['d'] ? '<span class="rs-when">' . pf_h(rs_date_label((string)$it['d'], (string)$it['prec'])) . '</span>' : '')
               . ((int)$it['done'] === 1 && $it['done_at'] ? '<span class="rs-when ok">' . pf_h(substr((string)$it['done_at'], 0, 10)) . ' 확인</span>' : '')
               . '</li>';
        }
        echo '</ul>';
    } else {
        echo '<p class="muted rs-none">문서에 확인 신호가 없습니다.</p>';
    }
    echo '</div>';
    // 일정
    echo '<div class="card"><h2>일정 <small class="muted"><a href="/stock/index.php?mode=research&view=cal">전 종목 일정 ›</a></small></h2>';
    if ($items['event']) {
        echo '<ul class="rs-events">';
        foreach ($items['event'] as $it) {
            $past = $it['d'] && rs_period_end((string)$it['d'], (string)$it['prec']) < $today;
            $ok = (int)$it['done'] === 1;   // 일정 탭에서 ✓
            echo '<li' . ($past ? ' class="past"' : '') . '><span class="rs-when">' . pf_h($it['d'] ? rs_date_label((string)$it['d'], (string)$it['prec']) : '날짜 없음') . '</span>'
               . '<span>' . pf_h((string)$it['text']) . '</span>'
               . ($ok ? '<em class="ok">' . pf_h(($it['done_at'] ? date('n/j', strtotime((string)$it['done_at'])) . ' ' : '') . '확인') . '</em>' : ($past ? '<em>지남</em>' : '')) . '</li>';
        }
        echo '</ul>';
    } else {
        echo '<p class="muted rs-none">문서에 일정이 없습니다.</p>';
    }
    echo '</div>';
    // 리스크
    echo '<div class="card"><h2>리스크</h2>';
    if ($items['risk']) {
        echo '<ul class="rs-risks">';
        foreach ($items['risk'] as $it) echo '<li>' . pf_h((string)$it['text']) . '</li>';
        echo '</ul>';
    } else {
        echo '<p class="muted rs-none">문서에 리스크 항목이 없습니다.</p>';
    }
    echo '</div>';
    // 증권사 목표가
    echo '<div class="card"><h2>증권사 목표가</h2>';
    if ($tg) {
        echo '<table class="pf rs-mini"><thead><tr><th>증권사</th><th>날짜</th><th class="num">목표가</th><th class="num">이전</th></tr></thead><tbody>';
        foreach ($tg as $v) {
            $p = (int)$v['lo'];
            $pv = (int)($v['prev'] ?? 0);
            echo '<tr><td><b>' . pf_h($v['label']) . '</b></td><td>' . pf_h((string)($v['d'] ?? '')) . '</td>'
               . '<td class="num"><b>' . number_format($p) . '</b>' . ($px ? '<div class="muted" style="font-size:11px">지금 대비 ' . pf_h(rs_pct($p / $px - 1)) . '</div>' : '') . '</td>'
               . '<td class="num">' . ($pv > 0 ? number_format($pv) . '<div class="muted" style="font-size:11px">' . pf_h(rs_pct($p / $pv - 1)) . '</div>' : '-') . '</td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p class="muted rs-none">문서에 증권사 목표가가 없습니다.</p>';
    }
    echo '</div>';
    echo '</div>';
}

/** 고른 버전의 가격을 얹은 일봉 — 차트 규칙 그대로(구성 ① · dailychart.js · 차트틀은 재무분석과 공유) */
function rs_detail_chart(PDO $pdo, string $code, int $id): void
{
    echo '<div class="card"><div class="pf-head" style="margin-bottom:10px"><div>';
    echo '<h2 style="margin:0" id="rsChartTitle">일봉 차트</h2>';
    echo '<div class="sub">📑 깃발 = 리서치 기준일(누르면 그 버전) · <b style="color:#16a34a">초록</b> = 이 버전의 매수 구간 · '
       . '<b style="color:#7c3aed">보라 점선</b> = 시나리오. 가격축 밖의 값은 그리지 않습니다(위의 위치 막대가 말합니다).</div>';
    echo '</div><div class="act"><span id="rsPBar"></span> <span id="rsIBar"></span></div></div>';
    echo '<div id="rsChart" style="height:380px;position:relative"></div>';
    echo '<div class="muted" id="rsChartNote" style="font-size:12px;margin-top:8px">불러오는 중…</div></div>';
    echo '<script src="/style/dailychart.js?v=63"></script>';
    echo ChartFeat::boot('research', $pdo);
    echo '<script>var RS_CODE=' . rs_js($code) . ',RS_ID=' . $id . ';</script>';
    echo <<<'JS'
<script>
DailyChart.load().then(function(){
  var note = document.getElementById('rsChartNote');
  var F = DailyChart.feats;
  var dc = DailyChart.create('rsChart', { theme: 'light', key: 'fund', code: RS_CODE, rsFocus: RS_ID });
  if (!dc) { note.textContent = '차트 라이브러리를 불러오지 못했습니다.'; return; }
  DailyChart.periodBar('rsPBar', dc, {
    theme: 'light', defaultIndex: 0, fullscreen: F('fullscreen'),
    onChange: function(info){
      var t = document.getElementById('rsChartTitle');
      if (t) t.textContent = info.tf === 'week' ? '주봉 차트' : '일봉 차트';
    }
  });
  DailyChart.indicatorBar('rsIBar', dc, { theme: 'light', key: 'fund', preset: F('preset.select') });
  DailyChart.fetchDaily(RS_CODE, 1000).then(function(rows){
    if (!rows.length) { note.textContent = '일봉을 가져오지 못했습니다.'; return; }
    dc.setData(rows);
    var r = dc.range();
    if (r) note.textContent = r.n + (dc.tf() === 'week' ? '주' : '거래일') + ' (' + r.from + ' ~ ' + r.to + ') · 종가 ' + Math.round(r.lastClose).toLocaleString();
  }).catch(function(){ note.textContent = '일봉을 불러오지 못했습니다.'; });
}).catch(function(){ document.getElementById('rsChartNote').textContent = '차트 라이브러리를 불러오지 못했습니다.'; });
</script>
JS;
}

/**
 * 재점검 요청문 — claude.ai 에 붙여 넣을 글. 지난 판단의 요지 + 그 뒤 사이트가 아는 사실 + 같은 형식으로 갱신해 달라는 요청.
 * ★다음 리서치가 «사이트의 숫자» 위에서 시작하게 하는 문이다 — 피드백 루프의 고리.
 */
function rs_review_prompt(PDO $pdo, array $n, string $name, ?float $px, array $stale, ?array $focus = null): string
{
    $code = (string)$n['code'];
    $today = date('Y-m-d');
    $L = [];
    $L[] = '[' . $name . '(' . $code . ') 리서치 ' . ($focus ? '일정 검증' : '재점검') . ' 요청 — ' . $today . ']';
    $L[] = '';
    // 일정 탭의 「검증 요청문」(2026-10-26) — 이번에 따질 일정을 맨 앞에 둔다
    if ($focus) {
        $L[] = '■ 이번에 검증할 일정 — ' . $focus['when'];
        foreach ($focus['items'] as $i) {
            $L[] = $i['kind'] === 'event' ? '- 일정: ' . $i['text'] : '- 확인 신호 ' . ($i['done'] ? '✓(확인함)' : '☐') . ': ' . $i['text'];
        }
        $L[] = '';
    }
    $L[] = '■ 지난 리서치 (' . $n['d'] . ') 「' . $n['title'] . '」';
    $L[] = '- 의견: ' . (Rs::opinionLabel((string)$n['opinion']) ?: '-');
    if ((string)$n['headline'] !== '') $L[] = '- 한 줄 결론: ' . $n['headline'];
    if (trim((string)($n['biz'] ?? '')) !== '' || (string)($n['tags'] ?? '') !== '') {
        $L[] = '- 사업: ' . trim((string)($n['biz'] ?? '') . ' ' . (string)($n['tags'] ?? ''));
    }
    $sc = [];
    $basis = '';
    foreach ($n['levels'] as $v) if ($v['kind'] === 'scen') { $sc[] = $v['label'] . ' ' . rs_man((int)$v['lo']); $basis = $basis ?: (string)$v['basis']; }
    if ($sc) $L[] = '- 시나리오: ' . implode(' · ', $sc) . ($basis !== '' ? ' (' . $basis . ')' : '');
    $zs = [];
    foreach (rs_zones($n) as $z) $zs[] = $z['label'] . ' ' . rs_range($z['lo'], $z['hi']) . ($z['memo'] !== '' ? ' (' . $z['memo'] . ')' : '');
    if ($zs) $L[] = '- 매수 구간: ' . implode(' · ', $zs);
    foreach ($n['items'] as $it) {
        if ($it['kind'] === 'check') $L[] = '- 확인 신호 ' . ((int)$it['done'] === 1 ? '✓(확인함)' : '☐') . ': ' . $it['text'];
    }
    $ev = [];
    foreach ($n['items'] as $it) if ($it['kind'] === 'event') $ev[] = ($it['d'] ? rs_date_label((string)$it['d'], (string)$it['prec']) . ' ' : '') . $it['text'];
    if ($ev) $L[] = '- 일정: ' . implode(' / ', $ev);

    $L[] = '';
    $L[] = '■ 그 뒤 사이트가 확인한 사실 (' . $n['d'] . ' → ' . $today . ')';
    $base = rs_base_price($pdo, $n);
    if ($base && $px) {
        $hl = '';
        try {
            $st = $pdo->prepare("SELECT MAX(h) hi, MIN(l) lo, COUNT(*) n FROM krx_amt WHERE code = ? AND d > ? AND vol > 0 AND l > 0");
            $st->execute([$code, (string)$n['d']]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r && (int)$r['n'] > 0) $hl = ' · 그 사이 ' . (int)$r['n'] . '거래일 고가 ' . number_format((float)$r['hi']) . ' / 저가 ' . number_format((float)$r['lo']);
        } catch (Throwable $e) { }
        $L[] = '- 주가: ' . number_format($base) . ' → ' . number_format($px) . ' (' . rs_pct($px / $base - 1) . ')' . $hl;
    }
    $zst = rs_zone_state($n, $px);
    if ($zst['text'] !== '') $L[] = '- 매수 구간 위치: ' . $zst['text'];
    $filing = array_filter($stale, fn($s) => $s['k'] === 'filing');
    if ($filing) foreach ($filing as $s) $L[] = '- ' . $s['text'];
    else $L[] = '- 리서치 뒤 새 정기공시: 없음';
    foreach ($stale as $s) if ($s['k'] === 'event') $L[] = '- ' . $s['text'];

    $L[] = '';
    $L[] = '■ 요청';
    if ($focus) {
        $L[] = '0. 먼저 「이번에 검증할 일정」이 실제로 어떻게 됐는지(발표 숫자·공시·보도)를 웹에서 찾아 확인하고, '
             . '확인 신호마다 통과/미달/아직 모름을 근거와 함께 판정해 주세요. 그 결과가 지난 리서치의 가정과 맞는지 먼저 짚어 주세요.';
    }
    $L[] = '1. 위 변화를 반영해 의견·시나리오·매수 구간·확인 신호·일정을 갱신해 주세요.';
    $L[] = '2. 지난 판단에서 맞은 것과 틀린 것을 「지난 판단 점검」 절로 짚어 주세요.';
    $L[] = '3. 지난번과 같은 형식으로 써 주세요 — 한 줄 결론 / 의견(매수·분할매수·관망·비중축소·매도 중 하나) / 사업 개요(+#태그) / 시나리오 표 / '
         . '매수 구간 / 확인 신호 / 이벤트 캘린더 / 리스크 / 증권사 목표가 / 지난 판단 점검.';
    return implode("\n", $L);
}

/** claude.ai 프로젝트 지침에 붙여 둘 «작성 형식» — 이 형식이면 AI 가 거의 틀리지 않게 읽는다 */
function rs_format_guide(): string
{
    $ops = implode(' / ', array_map(fn($o) => $o['label'], Rs::OPINIONS));
    $g = "[주식 리서치 작성 형식]\n"
       . "종목 분석 문서는 아래 형식을 지켜 주세요. 이 문서를 Markdown 으로 내보내 사이트에 올리면, 사이트가 값을 읽어 차트에 표시합니다.\n\n"
       . "# {종목명} {주제} (YYYY-MM-DD)\n\n"
       . "## 한 줄 결론\n2문장 이내.\n\n"
       . "## 의견\n{$ops} 중 하나만.\n\n"
       . "## 사업 개요\n1~2문장 — 무엇을 만들어 누구에게 파는가, 매출 비중이 큰 사업(예: 라면 수출 70%).\n"
       . "태그: #라면 #K푸드 #수출 (사업 키워드 3~6개 · 띄어쓰기 없는 짧은 명사 · 종목명·의견은 넣지 않기)\n\n"
       . "## 현재 위치\n- 기준 주가: 179,900원 (YYYY-MM-DD 종가)\n\n"
       . "## 시나리오 (기준: 2027E)\n| 구분 | 주당 가치 | 가정 |\n| --- | --- | --- |\n| 비관 | 174,000원 | … |\n| 기본 | 288,000원 | … |\n| 낙관 | 423,000원 | … |\n\n"
       . "## 매수 구간\n1. 1차 — 175,000~185,000원 (계획 비중 1/3): 이유\n2. 2차 — 150,000~159,999원: 이유\n3. 3차 — 가격이 아니라 조건이면 그렇게 적기\n\n"
       . "## 확인 신호\n- (예상 시점) 무엇을 확인하나 — 수치 기준\n\n"
       . "## 이벤트 캘린더\n- YYYY-MM-DD 또는 YYYY-MM: 내용\n\n"
       . "## 리스크\n- …\n\n"
       . "## 증권사 목표가\n| 증권사 | 날짜 | 목표가 | 이전 |\n| --- | --- | --- | --- |\n\n"
       . "## 지난 판단 점검 (재점검일 때만)\n- 맞은 것 / 틀린 것";
    return '<details class="card rs-guide"><summary><b>Claude 쪽 작성 형식</b> <span class="muted">— claude.ai 프로젝트 지침에 붙여 두면 AI 가 더 정확히 읽습니다</span></summary>'
         . '<pre id="rsGuide">' . pf_h($g) . '</pre>'
         . '<button type="button" class="btn btn-outline btn-sm" onclick="RS.copy(document.getElementById(\'rsGuide\').textContent, this)">복사</button></details>';
}

// ══ Markdown → HTML (Claude Docs 내보내기가 쓰는 범위) ═══════════════
/**
 * 제목 · 문단 · 목록(중첩) · 표 · 인용 · 구분선 · 코드 · 굵게/기울임/코드/링크.
 * ★전부 이스케이프한 뒤 서식만 입힌다 — 원문의 HTML 은 글자로 보인다. 링크는 http(s) 만, 새 창으로.
 * ★문서의 # 는 화면의 h2 다 — 화면 제목(h1)보다 아래로 한 칸씩 내린다.
 */
function rs_md_html(string $md): string
{
    $md = preg_replace('/<\?claude[^>]*\?>/u', '', $md);   // Claude Docs 블록 표식이 남아 있으면 지운다
    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $md));
    $n = count($lines);
    $html = '';
    $para = [];
    $flush = static function () use (&$para, &$html) {
        if ($para) { $html .= '<p>' . rs_md_inline(implode(' ', $para)) . '</p>'; $para = []; }
    };
    $i = 0;
    while ($i < $n) {
        $line = $lines[$i];
        $t = trim($line);
        if (preg_match('/^(```|~~~)/', $t)) {
            $flush();
            $buf = [];
            $i++;
            while ($i < $n && !preg_match('/^(```|~~~)/', trim($lines[$i]))) { $buf[] = $lines[$i]; $i++; }
            $i++;
            $html .= '<pre><code>' . htmlspecialchars(implode("\n", $buf), ENT_QUOTES, 'UTF-8') . '</code></pre>';
            continue;
        }
        if ($t === '') { $flush(); $i++; continue; }
        if (preg_match('/^(#{1,6})\s+(.+)$/u', $t, $m)) {
            $flush();
            $lv = min(6, strlen($m[1]) + 1);
            $html .= '<h' . $lv . '>' . rs_md_inline(rtrim($m[2], " #")) . '</h' . $lv . '>';
            $i++;
            continue;
        }
        if (preg_match('/^([-*_])(\s*\1){2,}\s*$/', $t)) { $flush(); $html .= '<hr>'; $i++; continue; }
        if (strpos($t, '|') !== false && $i + 1 < $n
            && preg_match('/^\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?$/', trim($lines[$i + 1]))) {
            $flush();
            $head = rs_md_cells($t);
            $al = array_map(static function ($c) {
                $c = trim($c);
                $l = strpos($c, ':') === 0; $r = substr($c, -1) === ':';
                return ($l && $r) ? 'center' : ($r ? 'right' : '');
            }, rs_md_cells(trim($lines[$i + 1])));
            $i += 2;
            $h = '<div class="rs-md-tbl"><table><thead><tr>';
            foreach ($head as $k => $c) $h .= '<th' . (($al[$k] ?? '') !== '' ? ' style="text-align:' . $al[$k] . '"' : '') . '>' . rs_md_inline($c) . '</th>';
            $h .= '</tr></thead><tbody>';
            while ($i < $n && trim($lines[$i]) !== '' && strpos($lines[$i], '|') !== false) {
                $h .= '<tr>';
                foreach (rs_md_cells(trim($lines[$i])) as $k => $c) {
                    $h .= '<td' . (($al[$k] ?? '') !== '' ? ' style="text-align:' . $al[$k] . '"' : '') . '>' . rs_md_inline($c) . '</td>';
                }
                $h .= '</tr>';
                $i++;
            }
            $html .= $h . '</tbody></table></div>';
            continue;
        }
        if (strpos($t, '>') === 0) {
            $flush();
            $buf = [];
            while ($i < $n && strpos(trim($lines[$i]), '>') === 0) { $buf[] = preg_replace('/^>\s?/', '', trim($lines[$i])); $i++; }
            $html .= '<blockquote>' . rs_md_html(implode("\n", $buf)) . '</blockquote>';
            continue;
        }
        if (preg_match('/^\s*([-*+]|\d+[.)])\s+/', $line)) {
            $flush();
            [$h, $i] = rs_md_list($lines, $i);
            $html .= $h;
            continue;
        }
        $para[] = $t;
        $i++;
    }
    $flush();
    return $html;
}

function rs_md_cells(string $row): array
{
    $row = trim($row);
    if (strpos($row, '|') === 0) $row = substr($row, 1);
    if (substr($row, -1) === '|' && substr($row, -2) !== '\\|') $row = substr($row, 0, -1);
    return array_map(static fn($c) => trim(str_replace('\\|', '|', $c)), preg_split('/(?<!\\\\)\|/', $row));
}

/** 목록 — 들여쓰기로 중첩을 만든다. [html, 다음 줄 번호] */
function rs_md_list(array $lines, int $i): array
{
    $n = count($lines);
    $items = [];   // [들여쓰기, 번호목록?, 글]
    while ($i < $n) {
        $line = str_replace("\t", '    ', $lines[$i]);
        if (trim($line) === '') {
            $j = $i + 1;
            while ($j < $n && trim($lines[$j]) === '') $j++;
            if ($j < $n && preg_match('/^\s*([-*+]|\d+[.)])\s+/', $lines[$j])) { $i = $j; continue; }
            break;
        }
        if (preg_match('/^(\s*)([-*+]|\d+[.)])\s+(.*)$/u', $line, $m)) {
            $items[] = [strlen($m[1]), !in_array($m[2], ['-', '*', '+'], true), $m[3]];
            $i++;
            continue;
        }
        if ($items && preg_match('/^\s+\S/', $line)) { $items[count($items) - 1][2] .= ' ' . trim($line); $i++; continue; }
        break;
    }
    $k = 0;
    $html = '';
    while ($k < count($items)) $html .= rs_md_list_build($items, $k, $items[$k][0]);
    return [$html, $i];
}

function rs_md_list_build(array $items, int &$k, int $indent): string
{
    $ol = $items[$k][1];
    $html = $ol ? '<ol>' : '<ul>';
    while ($k < count($items) && $items[$k][0] >= $indent) {
        $html .= '<li>' . rs_md_inline($items[$k][2]);
        $k++;
        if ($k < count($items) && $items[$k][0] > $indent) $html .= rs_md_list_build($items, $k, $items[$k][0]);
        $html .= '</li>';
    }
    return $html . ($ol ? '</ol>' : '</ul>');
}

function rs_md_inline(string $s): string
{
    $codes = [];
    $s = preg_replace_callback('/`([^`]+)`/u', static function ($m) use (&$codes) {
        $codes[] = '<code>' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</code>';
        return "\x01" . (count($codes) - 1) . "\x01";
    }, $s);
    $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $s = preg_replace_callback('/!?\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/u', static function ($m) {
        return '<a href="' . $m[2] . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>';
    }, $s);
    $s = preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', $s);
    $s = preg_replace('/__(.+?)__/u', '<b>$1</b>', $s);
    $s = preg_replace('/(?<![*\w])\*(?![\s*])(.+?)(?<![\s*])\*(?![*\w])/u', '<i>$1</i>', $s);
    $s = preg_replace('/~~(.+?)~~/u', '<s>$1</s>', $s);
    return preg_replace_callback("/\x01(\d+)\x01/", static fn($m) => $codes[(int)$m[1]] ?? '', $s);
}

// ══ 올리기 (끌어다 놓기 → AI 초안 → 확인 폼 → 저장) ═══════════════════
/** 올리기 칸 + 확인 폼 + 화면 JS. $code 를 주면 그 종목으로 올린다(종목 상세). 한 화면에 한 번만 그린다.
 *  $folded = 올리기 칸을 접어 둔다(종목 상세 — 버전이 이미 있을 때). ★창(모달)은 칸 밖에 둔다 — 접힌(display:none) 부모 안의 창은 열어도 안 보인다 */
function rs_upload_ui(string $code, bool $folded = false): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    echo '<div class="card rs-up" id="up"' . ($folded ? ' hidden' : '') . '>'
       . '<div class="rs-drop" id="rsDrop">'
       . '<div class="rs-drop-ic">📄</div>'
       . '<div><b>Claude 분석 문서(.md)를 여기에 끌어다 놓으세요</b>'
       . '<div class="muted">Claude Docs 에서 «Markdown 으로 내보내기» 한 파일 · '
       . '<button type="button" class="rs-lnk" id="rsPick">파일 고르기</button> · '
       . '<button type="button" class="rs-lnk" id="rsPasteBtn">본문 붙여넣기</button>'
       . ($code !== '' ? ' · 이 종목(' . pf_h($code) . ')으로 올립니다' : '') . '</div></div>'
       . '<input type="file" id="rsFile" accept=".md,.markdown,.txt,text/markdown,text/plain" hidden>'
       . '</div><div class="rs-up-msg" id="rsUpMsg"></div></div>';

    // 붙여넣기 창
    echo '<div class="rs-modal" id="rsPaste" hidden><div class="rs-mbox sm"><div class="rs-mhd"><b>본문 붙여넣기</b>'
       . '<button type="button" class="rs-x" data-close>✕</button></div>'
       . '<div class="rs-mbd"><textarea id="rsPasteText" rows="14" placeholder="Claude 문서의 본문을 통째로 붙여 넣으세요(Markdown 이면 표·목록이 살아납니다)"></textarea></div>'
       . '<div class="rs-mft"><button type="button" class="btn btn-outline btn-sm" data-close>취소</button>'
       . '<button type="button" class="btn btn-primary btn-sm" id="rsPasteGo">읽기</button></div></div></div>';

    // 확인 폼 — 칸은 JS 가 초안으로 채운다(종목 검색칸만 서버가 그린다 — 키보드로 고르는 공용 검색칸)
    echo '<div class="rs-modal" id="rsModal" hidden><div class="rs-mbox"><div class="rs-mhd"><b id="rsMTitle">리서치 확인</b>'
       . '<span class="muted" id="rsMSub"></span><button type="button" class="rs-x" data-close>✕</button></div>'
       . '<form id="rsForm" class="rs-mbd" onsubmit="return false" autocomplete="off">'
       . '<div class="rs-warn" id="rsWarn" hidden></div>'
       . '<div class="fld-row">';
    pf_stock_picker('rsStk', '', '', '종목', '', '220px');
    echo '<label class="fld"><span>기준일</span><input type="date" id="rsAsOf"></label></div>'
       . '<label class="fld rs-f"><span>제목</span><input type="text" id="rsTitle" maxlength="200"></label>'
       . '<div class="fld rs-f"><span>의견</span><div class="rs-ops" id="rsOps"></div></div>'
       . '<label class="fld rs-f"><span>한 줄 결론</span><textarea id="rsHead" rows="2" maxlength="600"></textarea></label>'
       . '<label class="fld rs-f"><span>사업 개요 <small class="muted">무엇을 만들어 누구에게 파나 · 1~2문장</small></span><textarea id="rsBiz" rows="2" maxlength="600"></textarea></label>'
       . '<label class="fld rs-f"><span>사업 태그 <small class="muted">#라면 #수출 — 띄어쓰기·쉼표로 구분 · 12개까지</small></span><input type="text" id="rsTags" maxlength="400"></label>'
       . '<div class="fld-row rs-f"><label class="fld"><span>기준 주가(원)</span><input type="text" class="num-comma" inputmode="numeric" id="rsPx"></label>'
       . '<label class="fld"><span>기준가 날짜</span><input type="date" id="rsPxD"></label></div>'
       . '<div class="rs-fsec"><h4>시나리오 <small>주당 가치(원) · 기준 · 가정</small></h4><div id="rsScen"></div></div>'
       . '<div class="rs-fsec"><h4>매수 구간 <small>한 가격이면 하단 = 상단 · 「15만원대」면 150,000~159,999</small></h4><div id="rsZone"></div>'
       . '<button type="button" class="rs-add" data-add="zone">＋ 구간</button></div>'
       . '<div class="rs-fsec"><h4>확인 신호 <small>매수·비중 확대 전에 확인할 것</small></h4><div id="rsCheck"></div>'
       . '<button type="button" class="rs-add" data-add="check">＋ 확인 신호</button></div>'
       . '<div class="rs-fsec"><h4>일정 <small>실적 발표·컨퍼런스 — 날짜가 지나면 「재점검」에 뜬다</small></h4><div id="rsEvent"></div>'
       . '<button type="button" class="rs-add" data-add="event">＋ 일정</button></div>'
       . '<div class="rs-fsec"><h4>리스크</h4><div id="rsRisk"></div><button type="button" class="rs-add" data-add="risk">＋ 리스크</button></div>'
       . '<div class="rs-fsec"><h4>증권사 목표가</h4><div id="rsTarget"></div><button type="button" class="rs-add" data-add="target">＋ 목표가</button></div>'
       . '<label class="fld rs-f"><span>지난 판단 점검 <small class="muted">(재점검 문서일 때)</small></span><textarea id="rsReview" rows="2" maxlength="1000"></textarea></label>'
       . '</form>'
       . '<div class="rs-mft"><span class="muted" id="rsAiInfo"></span>'
       . '<button type="button" class="btn btn-outline btn-sm" data-close>취소</button>'
       . '<button type="button" class="btn btn-primary btn-sm" id="rsSave">저장</button></div></div></div>';

    // 요청문 창
    echo '<div class="rs-modal" id="rsPrompt" hidden><div class="rs-mbox"><div class="rs-mhd"><b>재점검 요청문</b>'
       . '<span class="muted">claude.ai 에 붙여 넣고, 지난 문서(.md)도 함께 주세요</span><button type="button" class="rs-x" data-close>✕</button></div>'
       . '<div class="rs-mbd"><textarea id="rsPromptBox" rows="18" readonly></textarea></div>'
       . '<div class="rs-mft"><button type="button" class="btn btn-outline btn-sm" data-close>닫기</button>'
       . '<button type="button" class="btn btn-primary btn-sm" id="rsPromptCopy">복사</button></div></div></div>';

    pf_stock_picker_css();
    pf_stock_picker_script('rsStk', false);

    $ops = [];
    foreach (Rs::OPINIONS as $k => $o) $ops[] = ['k' => $k, 'label' => $o['label'], 'color' => $o['color'], 'desc' => $o['desc']];
    echo '<script>var RS_CFG=' . rs_js(['code' => $code, 'ops' => $ops, 'scens' => Rs::SCENS, 'precs' => Rs::PRECS,
                                       'max' => Rs::BODY_MAX]) . ';</script>';
    echo rs_js_app();
}

function rs_js_app(): string
{
    return <<<'JS'
<script>
var RS = (function(){
  var CFG = window.RS_CFG || {}, API = '/stock/api.php?module=rs&action=';
  var st = { body: null, src: '', ai_json: '', ai_model: '', editId: 0 };
  function $(s){ return document.querySelector(s); }
  function mk(tag, cls, txt){ var e = document.createElement(tag); if (cls) e.className = cls; if (txt != null) e.textContent = txt; return e; }
  function post(action, fd){
    return fetch(API + action, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function(r){ return r.json(); });
  }
  function msg(text, kind, link){
    var m = $('#rsUpMsg'); if (!m) return;
    m.className = 'rs-up-msg' + (kind ? ' rs-m-' + kind : '');
    m.textContent = text || '';
    if (link) { var a = mk('a', '', link.text); a.href = link.href; m.appendChild(document.createTextNode(' ')); m.appendChild(a); }
  }
  function won(v){ v = Math.round(+v || 0); return v > 0 ? v.toLocaleString() : ''; }
  function num(v){ return parseInt(String(v || '').replace(/[^0-9]/g, ''), 10) || 0; }
  function open(id){ var m = document.getElementById(id); if (m) m.hidden = false; }
  function closeAll(){ ['rsModal', 'rsPaste', 'rsPrompt'].forEach(function(id){ var m = document.getElementById(id); if (m) m.hidden = true; }); }
  document.addEventListener('click', function(e){
    var c = e.target.closest ? e.target.closest('[data-close]') : null;
    if (c) closeAll();
  });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeAll(); });

  /* ── 올리기 — 칸 안이든 화면 어디든 끌어다 놓으면 받는다 ── */
  var drop = $('#rsDrop'), file = $('#rsFile');
  function readFile(f){
    if (/\.pdf$/i.test(f.name) || f.type === 'application/pdf') {
      msg('PDF 는 받지 않습니다 — Claude 문서를 Markdown(.md)으로 내보내 올려 주세요.', 'err'); return;
    }
    if (f.size > (CFG.max || 300000)) { msg('문서가 너무 깁니다(300KB 까지).', 'err'); return; }
    var r = new FileReader();
    r.onload = function(){ extract(String(r.result || ''), f.name); };
    r.onerror = function(){ msg('파일을 읽지 못했습니다.', 'err'); };
    r.readAsText(f, 'utf-8');
  }
  function extract(text, name){
    text = String(text || '').replace(/^﻿/, '');
    if (!text.trim()) { msg('문서가 비었습니다.', 'err'); return; }
    var up = document.getElementById('up');
    if (up) up.hidden = false;
    st.body = text; st.src = name || '붙여넣기'; st.editId = 0;
    if (drop) drop.classList.add('busy');
    msg('AI 가 문서를 읽는 중입니다… (10초 안팎)', 'busy');
    var fd = new FormData();
    fd.append('md', new Blob([text], { type: 'text/markdown' }), 'doc.md');   // 파일 조각 — 앞단의 128KB 본문 제한을 피한다
    if (CFG.code) fd.append('code', CFG.code);
    post('extract', fd).then(function(j){
      if (drop) drop.classList.remove('busy');
      if (!j.ok) { msg(j.error || '읽지 못했습니다.', 'err'); return; }
      var d = j.data;
      if (d.dup) {
        msg('이미 올린 문서입니다 — ' + (d.dup.name || d.dup.code) + ' ' + d.dup.d + ' 버전.', 'warn',
            { text: '열기 →', href: '/stock/index.php?mode=research&code=' + d.dup.code + '&id=' + d.dup.id });
        return;
      }
      st.ai_json = d.ai_json || ''; st.ai_model = (d.ai && d.ai.model) || '';
      msg(d.ai.ok ? '' : (d.ai.msg || 'AI 가 읽지 못했습니다 — 칸을 직접 채워 주세요.'), d.ai.ok ? '' : 'warn');
      fill(d.draft, d.stock, d.found, d.ai);
    }).catch(function(){
      if (drop) drop.classList.remove('busy');
      msg('서버에 닿지 못했습니다 — 잠시 뒤 다시 해 주세요.', 'err');
    });
  }
  if (drop) {
    ['dragenter', 'dragover'].forEach(function(ev){
      document.addEventListener(ev, function(e){
        if (!e.dataTransfer || Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') < 0) return;
        e.preventDefault(); drop.classList.add('over');
      });
    });
    document.addEventListener('dragleave', function(e){ if (!e.relatedTarget) drop.classList.remove('over'); });
    document.addEventListener('drop', function(e){
      if (!e.dataTransfer || !e.dataTransfer.files || !e.dataTransfer.files.length) return;
      e.preventDefault(); drop.classList.remove('over');
      readFile(e.dataTransfer.files[0]);
    });
    $('#rsPick').onclick = function(){ file.click(); };
    file.addEventListener('change', function(){ if (file.files[0]) readFile(file.files[0]); file.value = ''; });
    $('#rsPasteBtn').onclick = function(){ open('rsPaste'); setTimeout(function(){ $('#rsPasteText').focus(); }, 30); };
    $('#rsPasteGo').onclick = function(){
      var t = $('#rsPasteText').value;
      closeAll();
      extract(t, '붙여넣기');
    };
  }

  /* ── 확인 폼 ── */
  function opRadios(cur){
    var box = $('#rsOps'); box.innerHTML = '';
    (CFG.ops || []).forEach(function(o){
      var l = mk('label', 'rs-opt'), i = mk('input'), s = mk('span', '', o.label);
      i.type = 'radio'; i.name = 'rsOp'; i.value = o.k; i.checked = (o.k === cur);
      s.style.setProperty('--c', o.color); l.title = o.desc;
      l.appendChild(i); l.appendChild(s); box.appendChild(l);
    });
  }
  function inp(f, val, ph, cls, type){
    var i = mk('input', cls || ''); i.type = type || 'text'; i.dataset.f = f;
    if (ph) i.placeholder = ph;
    if (val != null) i.value = val;
    if (cls && cls.indexOf('num-comma') >= 0) i.inputMode = 'numeric';
    return i;
  }
  function precSel(v){
    var s = mk('select'); s.dataset.f = 'prec';
    [['', '정밀도']].concat(Object.keys(CFG.precs || {}).map(function(k){ return [k, CFG.precs[k]]; })).forEach(function(p){
      var o = mk('option', '', p[1]); o.value = p[0]; if (p[0] === (v || '')) o.selected = true; s.appendChild(o);
    });
    return s;
  }
  function delBtn(row){ var b = mk('button', 'rs-rx', '✕'); b.type = 'button'; b.title = '이 줄 빼기'; b.onclick = function(){ row.remove(); }; return b; }
  var ROW = {
    zone: function(v){ v = v || {}; var r = mk('div', 'rs-r');
      r.appendChild(inp('label', v.label || '', '차수', 'xs'));
      r.appendChild(inp('lo', won(v.lo), '하단', 'num-comma sm'));
      r.appendChild(inp('hi', won(v.hi), '상단', 'num-comma sm'));
      r.appendChild(inp('memo', [v.weight, v.note].filter(Boolean).join(' · '), '비중 · 이유'));
      r.appendChild(delBtn(r)); return r; },
    check: function(v){ v = v || {}; var r = mk('div', 'rs-r');
      r.appendChild(inp('text', v.text || '', '무엇을 확인하나 — 수치 기준'));
      r.appendChild(inp('date', v.date || '', '', 'md', 'date'));
      r.appendChild(precSel(v.prec)); r.appendChild(delBtn(r)); return r; },
    risk: function(v){ var r = mk('div', 'rs-r'); r.appendChild(inp('text', v || '', '리스크')); r.appendChild(delBtn(r)); return r; },
    target: function(v){ v = v || {}; var r = mk('div', 'rs-r');
      r.appendChild(inp('broker', v.broker || '', '증권사', 'sm'));
      r.appendChild(inp('date', v.date || '', '', 'md', 'date'));
      r.appendChild(inp('price', won(v.price), '목표가', 'num-comma sm'));
      r.appendChild(inp('prev', won(v.prev), '이전', 'num-comma sm'));
      r.appendChild(delBtn(r)); return r; }
  };
  ROW.event = ROW.check;
  var BOX = { zone: '#rsZone', check: '#rsCheck', event: '#rsEvent', risk: '#rsRisk', target: '#rsTarget' };
  document.addEventListener('click', function(e){
    var a = e.target.closest ? e.target.closest('[data-add]') : null;
    if (!a) return;
    var k = a.dataset.add; $(BOX[k]).appendChild(ROW[k]());
  });
  function rows(k, list){ var b = $(BOX[k]); b.innerHTML = ''; (list || []).forEach(function(v){ b.appendChild(ROW[k](v)); }); }
  function scenRows(list){
    var b = $('#rsScen'); b.innerHTML = '';
    (CFG.scens || []).forEach(function(l){
      var v = (list || []).filter(function(s){ return s.label === l; })[0] || {};
      var r = mk('div', 'rs-r'); r.dataset.label = l;
      r.appendChild(mk('b', 'rs-rl', l));
      r.appendChild(inp('value', won(v.value), '주당 가치', 'num-comma sm'));
      r.appendChild(inp('basis', v.basis || '', '기준(예: 2027E)', 'sm'));
      r.appendChild(inp('note', v.note || '', '가정 한 줄'));
      b.appendChild(r);
    });
  }
  function setStock(s){
    var c = $('#rsStkCode'), n = $('#rsStkName'), q = $('#rsStkSearch');
    c.value = (s && s.code) || ''; n.value = (s && s.name) || '';
    q.value = s && s.code ? (s.name || s.code) + ' (' + s.code + ')' : ((s && s.name) || '');
    q.classList.toggle('picked', !!(s && s.code));
  }
  function warn(text){ var w = $('#rsWarn'); w.textContent = text || ''; w.hidden = !text; }
  function fill(d, stock, found, ai){
    d = d || {};
    setStock(stock);
    var w = '';
    if (!stock || !stock.code) w = '종목을 찾지 못했습니다' + (d.stock_name ? '(문서: ' + d.stock_name + ')' : '') + ' — 검색칸에서 골라 주세요.';
    else if (found && found.code && found.code !== stock.code) w = '문서는 「' + (found.name || found.code) + '」에 대한 것 같습니다 — 종목을 확인해 주세요.';
    warn(w);
    $('#rsAsOf').value = d.as_of || '';
    $('#rsTitle').value = d.title || '';
    opRadios(d.opinion || '');
    $('#rsHead').value = d.headline || '';
    $('#rsBiz').value = d.biz || '';
    $('#rsTags').value = (d.tags || []).map(function(t){ return '#' + t; }).join(' ');
    $('#rsReview').value = d.review || '';
    $('#rsPx').value = won(d.price_ref);
    $('#rsPxD').value = d.price_ref_date || '';
    scenRows(d.scenarios);
    rows('zone', d.zones); rows('check', d.checks); rows('event', d.events); rows('risk', d.risks); rows('target', d.targets);
    $('#rsMTitle').textContent = st.editId ? '리서치 값 고치기' : '리서치 확인 — 저장 전에 틀린 곳만 고치세요';
    $('#rsMSub').textContent = st.editId ? '원문은 그대로 두고 값만 고칩니다' : (st.src || '');
    var info = '';
    if (ai && ai.ok) {
      var u = ai.usage || {};
      info = (ai.model || 'AI') + ' 가 읽었습니다' + (u.input_tokens ? ' · 입력 ' + u.input_tokens.toLocaleString() + ' · 출력 ' + (u.output_tokens || 0).toLocaleString() + ' 토큰' : '');
    } else if (ai && !ai.ok) info = 'AI 가 읽지 못해 빈 칸입니다';
    $('#rsAiInfo').textContent = info;
    open('rsModal');
  }
  function collect(){
    var d = {
      stock_code: $('#rsStkCode').value, stock_name: $('#rsStkName').value,
      as_of: $('#rsAsOf').value, title: $('#rsTitle').value.trim(), headline: $('#rsHead').value.trim(),
      review: $('#rsReview').value.trim(), price_ref: num($('#rsPx').value), price_ref_date: $('#rsPxD').value,
      biz: $('#rsBiz').value.trim(), tags: $('#rsTags').value,
      opinion: (document.querySelector('input[name=rsOp]:checked') || {}).value || '',
      scenarios: [], zones: [], checks: [], events: [], risks: [], targets: []
    };
    function val(r, f){ var e = r.querySelector('[data-f="' + f + '"]'); return e ? e.value.trim() : ''; }
    Array.prototype.forEach.call($('#rsScen').children, function(r){
      var v = num(val(r, 'value'));
      if (v > 0) d.scenarios.push({ label: r.dataset.label, value: v, basis: val(r, 'basis'), note: val(r, 'note') });
    });
    Array.prototype.forEach.call($('#rsZone').children, function(r){
      var lo = num(val(r, 'lo')), hi = num(val(r, 'hi'));
      if (lo > 0 || hi > 0) d.zones.push({ label: val(r, 'label'), lo: lo, hi: hi, weight: '', note: val(r, 'memo') });
    });
    [['check', 'checks'], ['event', 'events']].forEach(function(k){
      Array.prototype.forEach.call($(BOX[k[0]]).children, function(r){
        var t = val(r, 'text');
        if (t) d[k[1]].push({ text: t, date: val(r, 'date'), prec: val(r, 'date') ? (val(r, 'prec') || 'day') : '' });
      });
    });
    Array.prototype.forEach.call($('#rsRisk').children, function(r){ var t = val(r, 'text'); if (t) d.risks.push(t); });
    Array.prototype.forEach.call($('#rsTarget').children, function(r){
      var p = num(val(r, 'price'));
      if (p > 0 && val(r, 'broker')) d.targets.push({ broker: val(r, 'broker'), date: val(r, 'date'), price: p, prev: num(val(r, 'prev')) });
    });
    return d;
  }
  $('#rsSave') && ($('#rsSave').onclick = function(){
    var b = this, d = collect(), code = d.stock_code;
    if (!code) { var m = ($('#rsStkSearch').value.match(/(\d{5}[0-9A-Z])/) || [])[1]; if (m) code = m; }
    if (!code) { warn('종목을 골라 주세요 — 검색칸에서 목록의 종목을 누르세요.'); return; }
    if (!d.as_of) { warn('기준일을 넣어 주세요.'); return; }
    if (!d.opinion) { warn('의견(다섯 단계 중 하나)을 골라 주세요.'); return; }
    var fd = new FormData();
    fd.append('code', code);
    fd.append('draft', JSON.stringify(d));
    if (st.editId) fd.append('id', st.editId);
    else {
      fd.append('md', new Blob([st.body || ''], { type: 'text/markdown' }), 'doc.md');
      fd.append('src_name', st.src || '');
      fd.append('ai_json', st.ai_json || '');
      fd.append('ai_model', st.ai_model || '');
    }
    b.disabled = true; b.textContent = '저장 중…';
    post('save', fd).then(function(j){
      if (!j.ok) { b.disabled = false; b.textContent = '저장'; warn(j.error || '저장하지 못했습니다.'); return; }
      location.href = j.data.url;
    }).catch(function(){ b.disabled = false; b.textContent = '저장'; warn('서버에 닿지 못했습니다 — 잠시 뒤 다시 눌러 주세요.'); });
  });

  /* ── 상세 화면 — 확인 신호 · 삭제 · 요청문 ── */
  document.addEventListener('change', function(e){
    var c = e.target;
    if (!c.dataset || !c.dataset.item) return;
    var fd = new FormData(); fd.append('item', c.dataset.item); fd.append('done', c.checked ? 1 : 0);
    post('check', fd).then(function(j){ if (!j.ok) { c.checked = !c.checked; alert(j.error || '저장하지 못했습니다.'); } })
      .catch(function(){ c.checked = !c.checked; });
  });
  var del = $('#rsDel');
  if (del) {
    var armed = 0;
    del.onclick = function(){
      if (!armed) {   // confirm() 을 쓰지 않는다 — 한 번 누르면 3초간 「정말?」
        armed = setTimeout(function(){ armed = 0; del.textContent = '삭제'; del.classList.remove('armed'); }, 3000);
        del.textContent = '정말 삭제? (다시 누르기)'; del.classList.add('armed'); return;
      }
      clearTimeout(armed); armed = 0;
      var fd = new FormData(); fd.append('id', del.dataset.id);
      del.disabled = true;
      post('delete', fd).then(function(j){ if (j.ok) location.href = j.data.url; else { del.disabled = false; alert(j.error || '지우지 못했습니다.'); } })
        .catch(function(){ del.disabled = false; });
    };
  }
  // 사업 태그 → 종목 태그(더하기만) — 끝나면 다시 그려 칩이 링크로 바뀐다
  var ta = $('#rsTagApply');
  if (ta) ta.onclick = function(){
    var fd = new FormData(); fd.append('id', ta.dataset.id);
    ta.disabled = true; ta.textContent = '더하는 중…';
    post('tags_apply', fd).then(function(j){
      if (!j.ok) { ta.disabled = false; ta.textContent = '종목 태그에 더하기'; alert(j.error || '더하지 못했습니다.'); return; }
      var d = j.data, t = d.added.length ? '#' + d.added.join(' #') + ' 를 더했습니다' : '더할 새 태그가 없습니다';
      if (d.summary_filled) t += ' · 종목 개요의 사업 개요를 채웠습니다';
      if (d.dropped.length) t += ' · 12개 상한으로 못 더한 것: #' + d.dropped.join(' #');
      ta.textContent = t;
      setTimeout(function(){ location.reload(); }, d.dropped.length ? 3500 : 1200);
    }).catch(function(){ ta.disabled = false; ta.textContent = '종목 태그에 더하기'; });
  };
  function copy(text, btn){
    function done(){ if (btn) { var t = btn.textContent; btn.textContent = '복사했습니다'; setTimeout(function(){ btn.textContent = t; }, 1500); } }
    if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done, function(){ fallback(); }); return; }
    fallback();
    function fallback(){ var ta = mk('textarea'); ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); done(); } catch (e) {} ta.remove(); }
  }
  var pc = $('#rsPromptCopy');
  if (pc) pc.onclick = function(){ copy($('#rsPromptBox').value, pc); };
  // 재무분석 카드의 「＋ 새 버전」(#up)으로 들어오면 접혀 있던 올리기 칸을 편다
  if (location.hash === '#up') setTimeout(function(){ RS.showUpload(); }, 60);

  return {
    copy: copy,
    showUpload: function(){ var w = document.getElementById('up'); if (w) { w.hidden = false; w.scrollIntoView({ behavior: 'smooth', block: 'center' }); } },
    showPrompt: function(){ var t = document.getElementById('rsPromptText'); if (!t) return; $('#rsPromptBox').value = t.value; open('rsPrompt'); },
    // 일정 탭의 「검증 요청문」 — 서버가 만든 글을 같은 창에 띄운다
    promptText: function(text, title){
      $('#rsPromptBox').value = text;
      var h = document.querySelector('#rsPrompt .rs-mhd b'); if (h) h.textContent = title || '재점검 요청문';
      open('rsPrompt');
    },
    openEdit: function(){ if (!RS.edit) return; st.editId = RS.edit.id; fill(RS.edit.draft, RS.edit.stock, null, null); },
    edit: null
  };
})();
</script>
JS;
}

// ══ 스타일 ═════════════════════════════════════════════════════════
function rs_css(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    echo <<<'CSS'
<style>
.rs-op{display:inline-block;color:#fff;font-size:12px;font-weight:800;padding:1px 8px;border-radius:5px;line-height:1.6;white-space:nowrap;vertical-align:middle}
.rs-op.none{background:#c9d4de}
.rs-op.sm{font-size:10.5px;padding:0 6px}
.rs-op.lg{font-size:14px;padding:3px 12px;border-radius:7px}
.rs-chip{display:inline-flex;align-items:center;gap:3px;font-size:11px;font-weight:700;color:#5b21b6;background:#f3efff;border:1px solid #ddd2fb;
  border-radius:10px;padding:0 7px;text-decoration:none;white-space:nowrap}
.rs-chip:hover{background:#ebe4ff}
.rs-tabs{display:flex;gap:6px;margin:0 0 12px}
.rs-tabs a{padding:6px 14px;border-radius:8px;background:#fff;border:1px solid #d7e0ea;color:#3c4d5e;font-size:13px;font-weight:700;text-decoration:none}
.rs-tabs a.on{background:#22303f;border-color:#22303f;color:#fff}
.rs-tabs a b{font-weight:800;margin-left:3px;opacity:.75}
/* 올리기 칸 */
.rs-up[hidden]{display:none}
.rs-drop{display:flex;align-items:center;gap:14px;border:2px dashed #c9d4de;border-radius:11px;padding:16px 18px;background:#fafcfe;transition:background .12s,border-color .12s}
.rs-drop.over{border-color:#7c3aed;background:#f5f1ff}
.rs-drop.busy{border-color:#1d5c93;background:#f2f7fc;opacity:.8;pointer-events:none}
.rs-drop-ic{font-size:30px;line-height:1}
.rs-drop b{font-size:14px}
.rs-drop .muted{font-size:12px;margin-top:3px}
.rs-lnk{border:0;background:none;padding:0;color:#1d5c93;font:inherit;font-weight:700;cursor:pointer;text-decoration:underline}
.rs-up-msg{font-size:12.5px;font-weight:600;margin-top:8px}
.rs-up-msg:empty{display:none}
.rs-m-err{color:#b3261e}.rs-m-warn{color:#8a6d1f}.rs-m-busy{color:#1d5c93}
/* 목록 */
.rs-tbl td{vertical-align:middle}
.rs-c-title a{font-weight:700;color:#22303f;text-decoration:none}
.rs-c-title a:hover{text-decoration:underline}
.rs-c-title .muted{font-size:11px;margin-top:2px}
.rs-c-op a{text-decoration:none;color:inherit;display:block}
.rs-c-op .muted{font-size:11px;margin-top:3px}
.rs-c-op a:hover .rs-op{filter:brightness(1.08)}

.rs-prev{font-size:11px;color:#8b98a5;margin-left:5px;font-weight:600}
.rs-star:not(.big){border:0;background:none;cursor:pointer;font-size:15px;color:#c8d3dd;padding:0 2px;line-height:1;vertical-align:-1px}
.rs-star:not(.big):hover{color:#f0a500}
.rs-star.on{color:#d99000;font-size:11.5px;font-weight:700;white-space:nowrap}
.rs-star.big{font-size:12.5px;padding:3px 10px}
.rs-tbl td.stk,.rs-tbl td.rs-c-op,.rs-tbl td.num{width:1%}
.rs-c-map{min-width:490px}
.rs-lg-z{display:inline-block;background:#3fae5f;color:#fff;border-radius:3px;padding:0 4px;font-weight:700}
.rs-c-todo{width:280px;max-width:280px}
.rs-c-todo .rs-next,.rs-c-todo .rs-todo{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden;white-space:normal;line-height:1.45;padding:1px 6px}
.rs-zs{display:inline-block;font-size:12px;font-weight:700;padding:1px 7px;border-radius:5px;white-space:nowrap}
.rs-zs.in{background:#e6f6ea;color:#15803d}
.rs-zs.above{background:#f1f4f7;color:#5f7183}
.rs-zs.mid{background:#eef4fb;color:#1d5c93}
.rs-zs.below{background:#fdecea;color:#b3261e}
.rs-todo{display:inline-block;font-size:11.5px;font-weight:700;background:#fff4e5;color:#b45309;border:1px solid #fde1b8;border-radius:5px;padding:0 6px;margin:1px 3px 1px 0;white-space:nowrap}
.rs-todo.k-filing{background:#fff1f0;color:#b3261e;border-color:#f6c9c5}
.rs-next{display:inline-block;font-size:11.5px;font-weight:700;color:#5b21b6;background:#f5f1ff;border-radius:5px;padding:0 6px;white-space:nowrap}
/* 가격 지도 — 같은 눈금의 두 줄: 적정가(비관·기본·낙관 눈금) / 매수(초록 구간 띠) + 두 줄을 꿰는 «지금» 선 · 2026-10-22 */
.rs-map{position:relative;padding:0 158px 0 40px;min-width:470px;box-sizing:border-box}
.rs-map.lg{max-width:780px;margin:14px 0 4px;padding-right:170px}
.rs-map-in{position:relative;height:62px}
.rs-map-k{position:absolute;left:-40px;font-size:10.5px;font-weight:800;color:#8b98a5;line-height:14px;white-space:nowrap}
.rs-map-k.k-a{top:13px}.rs-map-k.k-b{top:31px}
.rs-map-v{position:absolute;left:100%;margin-left:12px;white-space:nowrap;font-size:11px;color:#5f7183;font-weight:700;line-height:18px}
.rs-map-v.v-a{top:10px}.rs-map-v.v-b{top:29px}
.rs-map-v b{font-size:13.5px;font-weight:800}
.rs-map.lg .rs-map-v b{font-size:15px}
.rs-map-v b.s-b{color:#2563eb}.rs-map-v b.s-g{color:#6d28d9}.rs-map-v b.s-n{color:#dc2626}
.rs-map-v b.out{background:#fdecea;border-radius:4px;padding:0 5px}.rs-map-v b.s-n.out{background:#fdeef0}
.rs-map-v .rs-zs{font-size:11.5px;padding:0 6px;line-height:17px}
.rs-map-la{position:absolute;left:0;right:0;top:19px;height:2px;background:#cfd8e3;border-radius:1px}
.rs-map .tk{position:absolute;top:14px;height:12px;width:3px;margin-left:-1.5px;border-radius:2px;z-index:2}
.rs-map .lb{position:absolute;top:0;transform:translateX(-50%);font-size:10.5px;font-weight:700;white-space:nowrap;line-height:13px}
.rs-map .lb.al{transform:translateX(calc(-100% + 2px))}.rs-map .lb.ar{transform:translateX(-2px)}
.rs-map .lb b{font-weight:800;margin-left:3px}
.rs-map.lg .lb{font-size:11.5px}
.rs-map .tk.s-b{background:#2563eb}.rs-map .tk.s-g{background:#7c3aed}.rs-map .tk.s-n{background:#dc2626}
.rs-map .lb.s-b{color:#2563eb}.rs-map .lb.s-g{color:#7c3aed}.rs-map .lb.s-n{color:#dc2626}
.rs-map .z{position:absolute;top:31px;height:14px;background:#3fae5f;border-radius:4px;color:#fff;font-size:9.5px;font-weight:800;line-height:14px;text-align:center;overflow:hidden;white-space:nowrap;font-style:normal;z-index:3;opacity:.88}
.rs-map .now{position:absolute;top:12px;height:36px;width:2px;margin-left:-1px;background:#22303f;z-index:2;border-radius:1px}
/* 기준가 ○ — 적정가 선 위(시나리오 눈금이 그 위에 선다) */
.rs-map .bdot{position:absolute;top:15px;width:10px;height:10px;margin-left:-5px;border-radius:50%;background:#fff;border:2px solid #8b98a5;box-sizing:border-box;z-index:1}
.rs-map .nowt{position:absolute;top:47px;transform:translateX(-50%);font-size:10px;font-weight:800;color:#fff;background:#22303f;border-radius:4px;padding:0 5px;line-height:15px;white-space:nowrap;z-index:4}
.rs-map .nowt small{font-size:10px;font-weight:700;margin-left:4px;opacity:.85}
.rs-map .nowt.al{transform:translateX(-6px)}.rs-map .nowt.ar{transform:translateX(calc(-100% + 6px))}
.rs-map.lg .nowt,.rs-map.lg .nowt small{font-size:11px}
.rs-map .now.in,.rs-map .nowt.in{background:#15803d}
.rs-map .now.below,.rs-map .nowt.below,.rs-map .now.out,.rs-map .nowt.out{background:#b3261e}

/* 카드(재무분석 상세 · 상세 요지) */
.rs-fc-top{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:8px}
.rs-ftitle{font-weight:800;color:#22303f;text-decoration:none;font-size:14px}
.rs-ftitle:hover{text-decoration:underline}
.rs-head{font-size:13px;line-height:1.65;margin-top:6px;color:#22303f}
.rs-head.lg{font-size:14px;background:#f8f6ff;border-left:3px solid #7c3aed;padding:8px 11px;border-radius:0 7px 7px 0;margin-top:10px}
.rs-facts{display:flex;gap:10px 16px;flex-wrap:wrap;align-items:center;margin-top:10px;font-size:13px}
.rs-facts em{font-style:normal;font-weight:800}
.rs-stale{display:flex;flex-wrap:wrap;align-items:center;gap:2px;margin-top:10px;padding:7px 10px;background:#fffaf0;border:1px solid #fde1b8;border-radius:8px}
.rs-stale b{font-size:12px;color:#b45309;margin-right:6px}
.rs-grid{display:grid;grid-template-columns:260px minmax(0,1fr);gap:14px;align-items:start}
.rs-tl{position:sticky;top:10px}
.rs-tl h2{margin-bottom:8px}
.rs-tl-it{display:block;padding:8px 9px;border-radius:8px;text-decoration:none;color:#22303f;border:1px solid transparent;margin-bottom:4px}
.rs-tl-it:hover{background:#f4f7fa}
.rs-tl-it.on{background:#f5f1ff;border-color:#ddd2fb}
.rs-tl-d{display:flex;align-items:center;justify-content:space-between;gap:6px;font-size:12px;font-weight:800}
.rs-tl-t{font-size:12px;color:#3c4d5e;margin-top:3px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.rs-tl-c{font-size:11px;color:#5f7183;margin-top:3px}
.rs-sum-top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
.rs-sum-title{font-size:17px;font-weight:800}
.rs-diff{display:flex;flex-wrap:wrap;gap:4px;align-items:center;margin-top:10px;font-size:12px}
.rs-diff b{color:#5f7183;margin-right:4px}
.rs-diff span{background:#f1f4f7;border-radius:5px;padding:1px 7px;font-weight:700}
.rs-biz{margin-top:8px;font-size:12.5px;line-height:1.6;color:#3c4d5e}
.rs-biz>b{color:#1d5c93;margin-right:4px}
.rs-biz.none{font-size:12px}
.rs-btags{margin-top:4px}
.stag.rs-tnew{background:#fff;border:1px dashed #9fb3c8;color:#5f7183;padding:0 6px;cursor:default}
.rs-tapply{font-size:11px;font-weight:700;color:#1d5c93;background:#eef5fc;border:1px solid #c9dcef;border-radius:9px;padding:1px 9px;cursor:pointer;vertical-align:middle;line-height:1.6}
.rs-tapply:hover{background:#dde8f3}
.rs-tapply:disabled{cursor:default;color:#5f7183;background:#f5f8fb;white-space:normal}
.rs-review{margin-top:8px;font-size:12.5px;line-height:1.6;color:#3c4d5e}
.rs-review b{color:#5b21b6;margin-right:4px}
.rs-acts{display:flex;gap:6px;flex-wrap:wrap;margin-top:12px}
.rs-del.armed{background:#fdecea;border-color:#f5c6c0;color:#b3261e}
.rs-2col{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 14px}
.rs-mini td{vertical-align:top}
.rs-memo{font-size:12px;color:#5f7183}
table.pf.rs-mini{width:100%}
table.pf.rs-mini td.rs-memo{white-space:normal;word-break:keep-all;overflow-wrap:anywhere;width:100%;line-height:1.5}
tr.rs-zin td{background:#effaf2}
.rs-none{font-size:12.5px;margin:0}
.rs-checks,.rs-events,.rs-risks{list-style:none;margin:0;padding:0;font-size:13px}
.rs-checks li,.rs-events li{display:flex;gap:8px;align-items:baseline;padding:5px 0;border-bottom:1px solid #f0f3f6;flex-wrap:wrap}
.rs-checks label{display:flex;gap:6px;align-items:baseline;flex:1;min-width:220px;cursor:pointer}
.rs-checks input:checked+span{color:#15803d;text-decoration:line-through;text-decoration-color:rgba(21,128,61,.4)}
.rs-when{font-size:11px;font-weight:800;color:#5b21b6;background:#f5f1ff;border-radius:4px;padding:0 5px;white-space:nowrap}
.rs-when.ok{color:#15803d;background:#e6f6ea}
.rs-events li.past{color:#8496a6}
.rs-events li em{font-style:normal;font-size:11px;font-weight:800;color:#b45309}
.rs-risks li{padding:4px 0 4px 14px;position:relative;border-bottom:1px solid #f0f3f6}
.rs-risks li::before{content:"·";position:absolute;left:3px;font-weight:800;color:#b3261e}
/* 피드 */
.rs-feed{display:grid;gap:10px}
.rs-fitem{display:block;text-decoration:none;color:#22303f;margin:0}
.rs-fitem:hover{border-color:#cdbcf5}
.rs-fi-top{display:flex;align-items:center;gap:8px;font-size:13px;flex-wrap:wrap}
.rs-fi-top .muted{font-size:11.5px}
.rs-fi-title{font-weight:800;margin-top:6px}
.rs-fi-head{font-size:12.5px;color:#3c4d5e;margin-top:4px;line-height:1.6}
.rs-guide summary{cursor:pointer;font-size:13px}
.rs-guide pre{white-space:pre-wrap;font-size:12px;background:#f6f8fa;border:1px solid #e3eaf0;border-radius:8px;padding:10px 12px;margin:10px 0 8px}
/* 원문(Markdown) */
.rs-md{font-size:14px;line-height:1.75;color:#22303f;max-width:900px}
.rs-md h2{font-size:20px;margin:6px 0 10px}
.rs-md h3{font-size:16.5px;margin:22px 0 8px;padding-bottom:4px;border-bottom:1px solid #eef2f6}
.rs-md h4,.rs-md h5,.rs-md h6{font-size:14.5px;margin:16px 0 6px}
.rs-md p{margin:8px 0}
.rs-md ul,.rs-md ol{margin:6px 0;padding-left:22px}
.rs-md li{margin:3px 0}
.rs-md a{color:#1d5c93}
.rs-md code{background:#f1f4f7;border-radius:4px;padding:0 4px;font-size:12.5px}
.rs-md pre{background:#f6f8fa;border-radius:8px;padding:10px 12px;overflow:auto;font-size:12.5px}
.rs-md blockquote{margin:8px 0;padding:4px 12px;border-left:3px solid #d7e0ea;color:#5f7183}
.rs-md hr{border:0;border-top:1px solid #e3eaf0;margin:16px 0}
.rs-md-tbl{overflow-x:auto;margin:10px 0}
.rs-md table{border-collapse:collapse;font-size:13px;min-width:60%}
.rs-md th,.rs-md td{border:1px solid #dfe6ed;padding:6px 9px;vertical-align:top}
.rs-md th{background:#f4f7fa;font-weight:800;text-align:left}
/* 창 */
.rs-modal{position:fixed;inset:0;z-index:9990;background:rgba(10,20,35,.45);display:flex;align-items:flex-start;justify-content:center;padding:4vh 16px;overflow:auto}
.rs-modal[hidden]{display:none}
.rs-mbox{background:#fff;border-radius:12px;box-shadow:0 14px 40px rgba(10,25,45,.35);width:100%;max-width:880px;display:flex;flex-direction:column;max-height:92vh}
.rs-mbox.sm{max-width:640px}
.rs-mhd{display:flex;align-items:baseline;gap:10px;padding:12px 16px;border-bottom:1px solid #e3eaf0}
.rs-mhd b{font-size:15px}
.rs-mhd .muted{font-size:12px}
.rs-x{margin-left:auto;border:0;background:none;font-size:16px;cursor:pointer;color:#5f7183}
.rs-mbd{padding:12px 16px;overflow:auto}
.rs-mbd textarea{width:100%;font-size:13px;line-height:1.6}
.rs-mft{display:flex;align-items:center;gap:8px;padding:10px 16px;border-top:1px solid #e3eaf0}
.rs-mft .muted{margin-right:auto;font-size:11.5px}
.rs-f{margin-top:10px}
.rs-f input[type=text],.rs-f textarea{width:100%}
.rs-warn{background:#fff8e6;border:1px solid #f3dfa8;color:#8a6d1f;padding:7px 10px;border-radius:7px;font-size:12.5px;font-weight:700;margin-bottom:8px}
.rs-ops{display:flex;gap:6px;flex-wrap:wrap}
.rs-opt{cursor:pointer}
.rs-opt input{position:absolute;opacity:0;pointer-events:none}
.rs-opt span{display:inline-block;padding:4px 12px;border-radius:7px;border:1.5px solid var(--c);color:var(--c);font-size:13px;font-weight:800}
.rs-opt input:checked+span{background:var(--c);color:#fff}
.rs-opt input:focus-visible+span{box-shadow:0 0 0 3px rgba(29,92,147,.25)}
.rs-fsec{margin-top:14px;padding-top:10px;border-top:1px dashed #e3eaf0}
.rs-fsec h4{margin:0 0 6px;font-size:13px}
.rs-fsec h4 small{font-weight:600;color:#8496a6;margin-left:6px;font-size:11.5px}
.rs-r{display:flex;gap:6px;align-items:center;margin-bottom:5px}
.rs-r input[type=text]{flex:1;min-width:0}
.rs-r input.xs{flex:0 0 64px}
.rs-r input.sm{flex:0 0 118px}
.rs-r input.md{flex:0 0 140px}
.rs-r select{flex:0 0 auto}
.rs-rl{flex:0 0 40px;font-size:13px}
.rs-rx{border:0;background:#f1f4f7;color:#8496a6;border-radius:6px;width:26px;height:28px;cursor:pointer;flex:0 0 auto}
.rs-rx:hover{background:#fdecea;color:#b3261e}
.rs-add{border:1px dashed #c9d4de;background:#fff;color:#1d5c93;border-radius:6px;padding:3px 10px;font-size:12px;font-weight:700;cursor:pointer}
.rs-events li em.ok{color:#15803d}
/* 일정 탭 (2026-10-26) */
.rs-tab-over{font-style:normal;display:inline-block;min-width:16px;padding:0 4px;border-radius:8px;background:#d93025;color:#fff;font-size:11px;font-weight:800;text-align:center;margin-left:2px}
.rs-sg-intro{font-size:12px;margin:0 0 12px;line-height:1.6}
.rs-sg-sec{margin:0 0 14px}
details.rs-sg-sec>summary{cursor:pointer;list-style:none}
details.rs-sg-sec>summary::-webkit-details-marker{display:none}
details.rs-sg-sec>summary::before{content:'▸ ';color:#8496a6}
details.rs-sg-sec[open]>summary::before{content:'▾ '}
.rs-sg-h{display:inline-block;font-size:13px;font-weight:800;color:#22303f;margin:0 0 6px}
.rs-sg-h b{font-weight:800;color:#8496a6;margin-left:2px}
.rs-sg-h-over{color:#b3261e}
.rs-sg-none{font-size:12px;padding:4px 0 2px 2px}
.rs-sg{display:grid;grid-template-columns:96px 170px minmax(0,1fr) auto;gap:12px;align-items:start;padding:9px 6px;border-top:1px solid #edf1f5}
.rs-sg.hl{background:#fff8e1;border-radius:8px}
.rs-sg-over{background:#fff6f5}
.rs-sg-done{opacity:.72}
.rs-sg-d b{display:block;font-size:14px;font-weight:800;color:#5b21b6}
.rs-sg-d small{display:block;font-size:11px;color:#8496a6;font-weight:700;margin-top:1px}
.rs-sg-over .rs-sg-d b,.rs-sg-over .rs-sg-d small{color:#b3261e}
.rs-sg-s a{font-weight:800;color:#22303f;text-decoration:none;font-size:13.5px}
.rs-sg-s a:hover{text-decoration:underline}
.rs-sg-s .code{font-size:11px;color:#8496a6;margin-left:5px}
.rs-sg-s small{display:block;font-size:11px;margin-top:2px}
.rs-sg-i{font-size:13px;line-height:1.5;display:flex;flex-direction:column;gap:3px;align-items:flex-start}
.rs-sg-ev{font-weight:600;color:#22303f}
.rs-sg-ev.done{color:#6b7c8c}
.rs-sg-ck{display:flex;gap:6px;align-items:baseline;color:#3c4d5e;cursor:pointer}
.rs-sg-ck input{flex:0 0 auto;margin:0;position:relative;top:1px}
.rs-sg-ck input:checked+span{color:#15803d}
.rs-sg-fil{font-size:11px;font-weight:800;color:#1d5c93;background:#e8f1fb;border-radius:4px;padding:1px 6px}
.rs-sg-at{font-size:11px;font-weight:800;color:#15803d;background:#e6f6ea;border-radius:4px;padding:1px 6px}
.rs-sg-a{display:flex;gap:6px;flex-wrap:nowrap}
@media (max-width:900px){
  .rs-sg{grid-template-columns:78px minmax(0,1fr)}
  .rs-sg-i{grid-column:1 / -1}
  .rs-sg-a{grid-column:1 / -1;justify-content:flex-end}
}
@media (max-width:900px){
  /* ★minmax(0,1fr) — 그냥 1fr 은 «내용 폭»만큼 칸을 늘려 카드가 화면 밖으로 밀린다(휴대폰 폭에서 실측) */
  .rs-grid{grid-template-columns:minmax(0,1fr)}
  .rs-tl{position:static}
  .rs-2col{grid-template-columns:minmax(0,1fr)}
  .rs-2col .card{overflow-x:auto}
  .rs-zs,.rs-next,.rs-todo{white-space:normal}
  .rs-sum-top{flex-direction:column-reverse;gap:6px}
}
@media (max-width:640px){
  /* 차트 도구 줄(기간 바·지표 바)이 한 줄로 600px 를 넘어 휴대폰에서 가로 스크롤을 만든다 — 접어 내린다 */
  .rs-main .pf-head{flex-wrap:wrap}
  .rs-main .pf-head .act{max-width:100%;display:flex;flex-wrap:wrap;gap:6px}
  .rs-main .dc-pbar,.rs-main .dc-ibar{flex-wrap:wrap;max-width:100%}
  .rs-modal{padding:0}
  .rs-mbox{max-height:100vh;height:100%;border-radius:0}
  .rs-r{flex-wrap:wrap}
  .rs-r input[type=text]{flex:1 1 100%}
  .rs-r input.xs,.rs-r input.sm,.rs-r input.md{flex:1 1 30%}
  .rs-drop{flex-direction:column;text-align:center}
}
</style>
CSS;
}
?>
