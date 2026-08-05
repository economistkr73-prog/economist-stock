<?php
/**
 * stock/tests/dt_test.php — 단타 분봉 정규화·리샘플 단위 테스트 (CLI 전용)
 *
 * 실행: php stock/tests/dt_test.php
 *
 * 여기서 지키는 것 (요건 5.6 · 7.3):
 *   · 시간외(08:xx · 15:31~)는 <b>버린다</b>
 *   · 15:20~15:29 는 <b>15:30 으로 병합</b>한다 (종가 단일가 구간엔 봉이 없다)
 *   · 리샘플의 마지막 그룹(15:15)이 15:30 단일가 봉을 <b>삼킨다</b>
 *   · ★거래량 총합은 봉 단위를 바꿔도 <b>변하지 않는다</b> — 이 하나가 깨지면 전부 거짓이 된다
 *   · 결측 구간에 <b>0 봉을 만들지 않는다</b> (없던 봉이 생기면 지표가 거짓말을 한다)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); echo 'CLI only'; exit; }

require_once __DIR__ . '/../../classes/Dt.class';   // 순수 static 메서드만 쓴다 (DB 불필요)

$GLOBALS['n_ok'] = 0;
$GLOBALS['n_no'] = 0;

function t_head(string $s): void { echo "\n" . str_repeat('─', 66) . "\n {$s}\n" . str_repeat('─', 66) . "\n"; }
function t(bool $cond, string $label): void
{
    if ($cond) { $GLOBALS['n_ok']++; echo "  ok   {$label}\n"; }
    else       { $GLOBALS['n_no']++; echo "  FAIL {$label}\n"; }
}

/** 봉 하나 만들기 */
function b(string $hm, float $c, float $v = 10, string $d = '2026-08-04'): array
{
    return ['t' => "$d $hm", 'o' => $c, 'h' => $c + 2, 'l' => $c - 2, 'c' => $c, 'v' => $v];
}

// ══ 정규화 ══════════════════════════════════════════════════════════════
t_head('normalize — 시간외 폐기 · 15:20~15:29 병합');

$n = Dt::normalize([
    b('08:30', 100), b('09:00', 101), b('09:01', 102), b('15:19', 103),
    b('15:21', 104), b('15:25', 105), b('15:30', 106), b('15:45', 107),
]);
$times = array_column($n, 't');

t(!in_array('2026-08-04 08:30', $times, true), '08:30 프리마켓을 버린다');
t(!in_array('2026-08-04 15:45', $times, true), '15:45 시간외를 버린다');
t(count($n) === 4, '남는 봉 4개 = 09:00 · 09:01 · 15:19 · 15:30(3봉 병합) → ' . count($n));

$last = end($n);
t($last['t'] === '2026-08-04 15:30', '마지막 봉은 15:30');
t((int)$last['v'] === 30,            '15:21+15:25+15:30 거래량 합산 30 → ' . $last['v']);
t((int)$last['h'] === 108 && (int)$last['l'] === 102, '병합 고·저 재계산 (h=108 l=102)');
t((int)$last['c'] === 106,           '병합 종가 = 가장 늦은 봉의 종가 106 → ' . $last['c']);

// ══ 리샘플 ══════════════════════════════════════════════════════════════
t_head('resample — 3분 · 라벨은 구간 시작');

$bars = [];
for ($i = 0; $i < 20; $i++) {
    $t = strtotime('2026-08-04 09:00') + $i * 60;
    $bars[] = ['t' => date('Y-m-d H:i', $t), 'o' => 100 + $i, 'h' => 110 + $i,
               'l' => 90 + $i, 'c' => 105 + $i, 'v' => 10];
}
$r3 = Dt::resample($bars, 3);
t(count($r3) === 7, '1분 20봉 → 3분 7봉 (' . count($r3) . ')');
t($r3[0]['t'] === '2026-08-04 09:00' && $r3[1]['t'] === '2026-08-04 09:03', '라벨은 09:00 · 09:03 …');
t((int)$r3[0]['o'] === 100 && (int)$r3[0]['c'] === 107, '시가=구간 첫 봉 · 종가=구간 끝 봉');
t((int)$r3[0]['h'] === 112 && (int)$r3[0]['l'] === 90,  '고=max(h) · 저=min(l)');
t(array_sum(array_column($bars, 'v')) === array_sum(array_column($r3, 'v')),
  '★거래량 총합이 봉 단위를 바꿔도 같다');

t_head('resample — 마지막 그룹이 15:30 단일가를 삼킨다');

$tail = [b('15:14', 100, 7), b('15:15', 100, 7), b('15:19', 100, 7), b('15:30', 100, 7)];
$r3t = Dt::resample($tail, 3);
t(count($r3t) === 3, '15:12 + 15:15 + 15:18 그룹 = 3 (' . count($r3t) . ')');
t(end($r3t)['t'] === '2026-08-04 15:18' && (int)end($r3t)['v'] === 14,
  '★3분의 마지막 라벨은 15:18 이고 15:19+15:30 을 삼킨다 (v=14) → '
  . end($r3t)['t'] . ' v=' . end($r3t)['v']);

/* ★회귀 방어 — $lastGm 일반화가 «기존» 5·15분 기대값을 그대로 통과하는지.
 *   UNITS 에서 빠졌어도 resample 자체는 아무 단위나 받는다(차트 갤러리·분석용). */
t_head('resample — ★$lastGm 일반화 회귀 방어 (기존 5·15분 기대값)');
$r5 = Dt::resample($tail, 5);
t(count($r5) === 2, '5분: 15:10 그룹 + 15:15 그룹 = 2 (' . count($r5) . ')');
t(end($r5)['t'] === '2026-08-04 15:15' && (int)end($r5)['v'] === 21,
  '★5분 15:15 그룹 = 15:15+15:19+15:30 (v=21) → ' . end($r5)['v']);
$r15 = Dt::resample($tail, 15);
t(count($r15) === 2 && end($r15)['t'] === '2026-08-04 15:15',
  '15분도 마지막 라벨은 15:15 (09:00+15×25)');
t((int)end($r15)['v'] === 21, '15분 마지막 그룹도 15:30 을 삼킨다 → ' . end($r15)['v']);

/* 하루 전체(381봉)를 넣었을 때의 봉 수 — §10-② 검증 기준값 */
t_head('resample — 하루 381봉 → 단위별 봉 수 · 거래량 보존');
$day = [];
for ($m = 540; $m <= 919; $m++) {                       // 09:00~15:19 = 380봉
    $day[] = b(sprintf('%02d:%02d', intdiv($m, 60), $m % 60), 100, 1);
}
$day[] = b('15:30', 100, 1);                            // 종가 단일가 = 381봉
t(count($day) === 381, '기준 1분봉 381개 (' . count($day) . ')');
foreach ([1 => 381, 3 => 127, 5 => 76, 15 => 26] as $u => $exp) {
    $rr = Dt::resample($day, $u);
    t(count($rr) === $exp, "{$u}분 → {$exp}봉 (" . count($rr) . ')');
    // b() 의 v 는 float 라 합도 float 다 — 값을 견주지 형을 견주지 않는다
    t((int)array_sum(array_column($rr, 'v')) === 381,
       "{$u}분 거래량 합 보존 → " . (int)array_sum(array_column($rr, 'v')));
}

t_head('resample — 결측 구간');
t(count(Dt::resample([b('09:00', 1), b('10:00', 1)], 3)) === 2,
  '★빈 구간을 0 으로 채우지 않는다 (없던 봉을 만들지 않는다)');

// ══ 코드 세탁 ═══════════════════════════════════════════════════════════
t_head('cleanCode — ★_AL 접미사가 저장으로 새 나가지 않는다');
t(Dt::cleanCode('005930_AL') === '005930', 'SOR 접미사 _AL 제거');
t(Dt::cleanCode('005930_NX') === '005930', 'NXT 접미사 _NX 제거');
t(Dt::cleanCode('005930')    === '005930', '순수 코드는 그대로');
// 영숫자만 남기고 컬럼 폭(10)에서 자른다 — 따옴표·공백·세미콜론이 살아남을 길이 없다
t(Dt::cleanCode("005930'; DROP TABLE") === '005930DROP', '따옴표·공백 제거 + 10자 절단(인젝션 차단)');
t(!preg_match('/[^0-9A-Za-z]/', Dt::cleanCode("005930'; DROP TABLE")), '결과에 영숫자 아닌 문자가 없다');

t_head('statusOfBars');
t(Dt::statusOfBars(381) === 'ok',      '381봉 = ok');
t(Dt::statusOfBars(375) === 'ok',      '375봉 = ok (거래 없는 분이 빠질 수 있어 여유)');
t(Dt::statusOfBars(200) === 'partial', '200봉 = partial (치유 대상)');
t(Dt::statusOfBars(0)   === 'none',    '0봉 = none (치유 대상)');

// ══ 결과 ════════════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 66) . "\n";
printf(" 통과 %d · 실패 %d\n", $GLOBALS['n_ok'], $GLOBALS['n_no']);
echo str_repeat('═', 66) . "\n";
exit($GLOBALS['n_no'] > 0 ? 1 : 0);
?>
