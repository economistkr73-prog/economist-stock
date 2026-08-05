<?php
/**
 * mig_updash_to_short.php — 상승종목분석(updash) 삭제에 딸린 차트 설정 이관
 *
 * 왜 필요한가
 *   단타(mode=short)의 일봉 패널은 차트틀·지표를 기억하는 키로 'updash' 를 나눠 쓰고 있었다
 *   (2026-08-04 「updash 일봉과 동일하게」). 상승종목분석 화면이 2026-08-05 에 삭제되면서
 *   그 이름을 쓸 이유가 없어져 키를 'short' 로 옮겼다. 표에 남은 행도 같이 옮겨 주지 않으면
 *   단타 일봉이 「마지막에 쓰던 차트틀」을 조용히 잊는다.
 *
 * 무엇을 하나 (chart_pref 한 표뿐)
 *   ① 축 행  updash_day / _week / _month / _min  →  short_day / _week / _month / _min
 *      (대상 자리에 이미 행이 있으면 «건드리지 않고» 보고만 한다 — 덮어쓰지 않는다)
 *   ② 화면 행 updash (기능 구성·보기값)          →  삭제 (그 화면이 없어졌다)
 *
 *   ★차트틀 자체(chart_preset)와 지표(chart_indicator)는 손대지 않는다 — 화면에 매이지 않은
 *     물건이라 그대로 살아 있어야 한다. 이 스크립트는 「어느 화면이 무엇을 쓰더라」만 옮긴다.
 *
 * 사용법:
 *   /mig_updash_to_short.php?key=econ-updash-off            → 미리보기(DB 변경 없음)
 *   /mig_updash_to_short.php?key=econ-updash-off&apply=1    → 실제 반영
 *
 * 1회성 스크립트 — 실행 후 삭제할 것.
 */

require_once "./env/cnt.inc";
header('Content-Type: text/plain; charset=utf-8');

$TOKEN = 'econ-updash-off';
if (($_GET['key'] ?? '') !== $TOKEN) {
    http_response_code(403);
    exit('forbidden: ?key=' . $TOKEN . ' 필요');
}

$apply = ($_GET['apply'] ?? '') === '1';
echo $apply ? "=== 실행 모드 (실제 반영) ===\n\n"
            : "=== 미리보기 모드 (DB 변경 없음. 실제 반영하려면 &apply=1 추가) ===\n\n";

/* 현재 상태부터 보여 준다 — 무엇을 옮기는지 눈으로 확인하고 apply 한다 */
$rows = $pdo->query(
    "SELECT chart_key, preset_id,
            CASE WHEN features_json IS NULL THEN '' ELSE 'features' END AS f,
            CASE WHEN view_json     IS NULL THEN '' ELSE 'view'     END AS v
       FROM chart_pref
      WHERE chart_key = 'updash' OR chart_key LIKE 'updash\\_%'
         OR chart_key = 'short'  OR chart_key LIKE 'short\\_%'
      ORDER BY chart_key"
)->fetchAll(PDO::FETCH_ASSOC);

echo "── 현재 chart_pref (updash·short 계열)\n";
if (!$rows) echo "   (없음)\n";
foreach ($rows as $r) {
    printf("   %-14s preset_id=%-4d %s %s\n", $r['chart_key'], (int)$r['preset_id'], $r['f'], $r['v']);
}
echo "\n";

$have = [];
foreach ($rows as $r) $have[$r['chart_key']] = $r;

// ① 축 행 이름 바꾸기
$moved = $skipped = 0;
foreach (['day', 'week', 'month', 'min'] as $tf) {
    $from = 'updash_' . $tf;
    $to   = 'short_'  . $tf;
    if (!isset($have[$from])) continue;

    if (isset($have[$to])) {                 // 목적지가 이미 있으면 덮어쓰지 않는다
        echo "   ! {$from} → {$to} : 목적지에 이미 행이 있어 건너뜀 (원본은 그대로 둡니다)\n";
        $skipped++;
        continue;
    }
    echo "   · {$from} → {$to}\n";
    if ($apply) {
        $pdo->prepare("UPDATE chart_pref SET chart_key = ? WHERE chart_key = ?")->execute([$to, $from]);
    }
    $moved++;
}
if (!$moved && !$skipped) echo "   (옮길 축 행 없음 — 이미 이관됐거나 저장된 설정이 없습니다)\n";

// ② 화면 행 삭제
echo "\n── 화면 행 'updash' (삭제 대상 — 그 화면이 없어졌습니다)\n";
if (isset($have['updash'])) {
    echo "   · 삭제\n";
    if ($apply) $pdo->prepare("DELETE FROM chart_pref WHERE chart_key = 'updash'")->execute();
} else {
    echo "   (없음)\n";
}

echo "\n";
if ($apply) {
    $after = $pdo->query(
        "SELECT chart_key, preset_id FROM chart_pref
          WHERE chart_key = 'short' OR chart_key LIKE 'short\\_%' OR chart_key LIKE 'updash%'
          ORDER BY chart_key"
    )->fetchAll(PDO::FETCH_ASSOC);
    echo "── 반영 후\n";
    if (!$after) echo "   (없음)\n";
    foreach ($after as $r) printf("   %-14s preset_id=%d\n", $r['chart_key'], (int)$r['preset_id']);
    echo "\n완료. 이 파일은 지워 주세요.\n";
} else {
    echo "미리보기 끝 — 위 내용이 맞으면 &apply=1 을 붙여 다시 실행하세요.\n";
}
?>
