<?php
/* ════════════════════════════════════════════════════════════════════════
 * 편입 스냅샷 조립 — M4 (요건정의서 v0.3 §2.5)
 *
 * 「내가 왜 이걸 담았나」를 <b>편입 순간에 한 번</b> 찍는다. 퀀트 판정(신호·경로·계단)은
 * 날마다 바뀌므로 나중에 다시 계산하면 그때의 사실이 아니라 오늘의 사실이 된다 —
 * 그래서 v0.3 §2.1 은 「참조가 아니라 값(스냅샷)」을 못박았다.
 *
 * ★ 여기는 <b>조립만</b> 한다. 저장은 Pf::entrySnapshotSave (DB 전담 클래스), 판정은
 *   전부 기존 함수를 그대로 부른다(pf_surge_badge · KrxAmt::boxStatusMany/momMany · pf_sue_stock) —
 *   화면·알림과 다른 잣대를 새로 만들면 기록의 뜻이 없다.
 * ★ 이 파일이 index.php 가 아니라 lib 에 있는 이유: 쓰는 쪽은 api.php 다.
 *   index.php 에 두면 api.php 에서 「Call to undefined function」으로 죽는다(전에 겪은 사고).
 * ★ 실패는 삼킨다 — 기록이 편입 자체를 막으면 안 된다. 못 채운 칸은 null 로 남는다.
 * ════════════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/sue.php';

/**
 * 재진입 사다리 재생성 — 새 진입가 기준으로 퀀트 사다리를 다시 짠다(2026-09-08).
 *
 * ★<b>왜 필요한가</b>: 퀀트 사다리는 «절대 가격»이라 재진입해도 편입 때 박스가 그대로 남는다.
 *   실측(삼양식품 003230): 사이클 2 를 1,298,000 에 시작했는데 1차 지지선은 옛 1,341,000 이었다.
 *   하락률 룰셋은 기준가가 «직전 차수의 실매수가»라 저절로 다시 짜인다 — 그 쪽과 뜻을 맞추는 것이다.
 * ★<b>①먼저 「그 사람이 고른 지지선」을 다시 쓴다</b> — 박스는 그대로인데 진입가만 달라진 경우가 대부분이라,
 *   새로 훑기보다 확정본 중 진입가 «아래»만 남기고 1차만 새 진입가로 바꾸는 것이 가장 가깝다.
 *   ★간격 문턱은 후보를 훑을 때와 <b>같다</b>(3%) — 사람이 고른 값이라도 새 1차에 너무 붙으면 그 조합은
 *   솔버가 거부한다(실측: 1,272,000 이 새 진입가 대비 −2.0% · 빼면 4차로 풀린다).
 * ★②고른 값이 전부 진입가 «위»면(훨씬 싸게 재진입) 박스 후보를 새로 훑는다 — 재료·규칙은 <b>편입 화면과 같다</b>
 *   (최고 거래대금 박스의 H·L → pf_box_ladder_build). 일봉으로 안 풀리면 주봉으로 한 번 더(편입 화면이 권하는 순서).
 *   ★새 판정을 만들지 않는다 — 후보도 비중 풀이도 전부 기존 함수 그대로다.
 * ★못 풀면 null 이고 부르는 쪽은 <b>옛 사다리를 그대로 둔다</b>. 사다리 없는 포지션을 만들지 않는다.
 *
 * @param array $oldPrices 지금 확정돼 있는 차수 가격들(pf_position_level) — ①에서 먼저 쓴다
 * @return array|null pf_box_ladder_build 결과 + src('old'=고른 값 재사용 / 'day'·'week'=박스 후보에서 새로)
 */
function pf_box_ladder_regen(PDO $pdo, string $code, float $entryPx, array $oldPrices = [], int $months = 24): ?array
{
    if ($entryPx <= 0) return null;

    if ($oldPrices) {
        $r = pf_box_pick_levels($oldPrices, $entryPx);
        if ($r !== null) { $r['src'] = 'old'; return $r; }
    }
    if (!preg_match('/^\\w{6}$/', $code)) return null;
    try {
        $krx = new KrxAmt($pdo);
        foreach (['day', 'week'] as $tf) {
            $boxes = ($tf === 'week') ? $krx->weeklyBoxCandidates($code, $months)
                                       : $krx->dailyBoxCandidates($code, $months);
            $px = [];
            foreach ($boxes as $b) { $px[] = (float)$b['l']; $px[] = (float)$b['h']; }
            $r = pf_box_pick_levels($px, $entryPx);
            if ($r !== null) { $r['src'] = $tf; return $r; }
        }
    } catch (Throwable $e) { /* 원장이 없어도 재진입은 끝난다 */ }
    return null;
}

/**
 * 판정 정의 버전 — 임계·규칙을 바꾸면 <b>올린다</b>.
 * 옛 스냅샷과 새 스냅샷을 같은 잣대로 착각해 섞어 세지 않기 위한 표식이다(v0.3 §3.1.2·D7).
 * 1 = 2026-08-03 기준 (매집형 20평비≤5 ∧ 등락 0~10% · 불꽃형 등락≥20% ∨ 20평비≥20 · 계단 floors≥3)
 */
const PF_DEF_VER = 1;

/**
 * 편입 시점의 퀀트·실적 상태를 한 벌로 만든다.
 *
 * @param string      $code 종목코드
 * @param string|null $sed  기준 신호일 (pf_position.surge_event_d) — 없으면 퀀트 칸은 비운다
 * @return array Pf::entrySnapshotSave 가 그대로 받는 모양
 */
function pf_entry_snapshot_build(PDO $pdo, string $code, ?string $sed): array
{
    $out = [
        'snapshot_at'  => date('Y-m-d'),
        'quant_signal' => 'none',
        'flame_reason' => null,
        'box_path'     => '',
        'floors_count' => null,
        'box_levels'   => '',
        'signal_date'  => $sed ?: null,
        'ret_20d'      => null,
        'sue_latest'   => null,
        'def_ver'      => PF_DEF_VER,
    ];

    /* ── 퀀트(2-a 신호 · 2-b 경로 · 계단 수 · 신호일 모멘텀) — 기준 박스가 있어야 성립한다.
     *   기준 박스가 없으면(수동 편입·퀀트 이전 종목) 'none' 으로 남긴다 — 「판정 안 함」이지 「나쁨」이 아니다. */
    if ($sed) {
        try {
            $st = $pdo->prepare("SELECT avg_mul, chg FROM krx_surge WHERE code = ? AND d = ?");
            $st->execute([$code, $sed]);
            if ($s = $st->fetch(PDO::FETCH_ASSOC)) {
                $am  = $s['avg_mul'] !== null ? (float)$s['avg_mul'] : null;
                $chg = $s['chg']     !== null ? (float)$s['chg']     : null;

                /* 라벨이 아니라 <b>조건</b>으로 되짚는다 — pf_surge_badge 의 한글 라벨을 파싱하면
                 * 문구를 바꿀 때마다 기록이 깨진다. 임계는 classes/Thr.class 정본을 그대로 본다(M5). */
                if ($chg !== null && $chg >= Thr::FLAME_CHG) {
                    $out['quant_signal'] = 'flame';
                    $out['flame_reason'] = ($am !== null && $am >= Thr::FLAME_AVGMUL) ? 'both' : 'by_return';
                } elseif ($am !== null && $am >= Thr::FLAME_AVGMUL) {
                    $out['quant_signal'] = 'flame';
                    $out['flame_reason'] = 'by_volume';
                } elseif ($am !== null && $am <= Thr::ACC_AVGMUL_MAX
                          && $chg !== null && $chg >= Thr::ACC_CHG_MIN && $chg < Thr::ACC_CHG_MAX) {
                    $out['quant_signal'] = 'accumulate';
                } else {
                    $out['quant_signal'] = 'neutral';
                }
            }
        } catch (Throwable $e) { /* krx_surge 미구축 — 퀀트 칸 없이 */ }

        try {
            $ka  = new KrxAmt($pdo);
            $sig = [['code' => $code, 'd' => $sed]];
            $b   = $ka->boxStatusMany($sig)[$code . '|' . $sed] ?? null;
            if ($b) {
                $out['box_path']     = (string)$b['txt'];
                $out['floors_count'] = isset($b['floors']) ? (int)$b['floors'] : null;
                /* ★계단 <b>가격</b>까지 값으로 남긴다 — 개수(floors)만으로는 「어느 자리가 계단이었나」를
                 *   되짚을 수 없어서, 그걸 알려면 결국 krx_surge 를 다시 읽어야 했다.
                 *   레벨을 들고 있으면 스냅샷이 자기완결이 된다(§2.1 「참조가 아니라 값」). */
                if (!empty($b['levels']) && is_array($b['levels'])) {
                    $out['box_levels'] = implode(',', array_map(
                        static fn($v) => (string)(int)round((float)$v), $b['levels']));
                }
            }
            $m = $ka->momMany($sig)[$code . '|' . $sed] ?? null;
            if ($m && $m['m20'] !== null) $out['ret_20d'] = (float)$m['m20'];
        } catch (Throwable $e) { /* 원장 없음 */ }
    }

    /* ── 실적(1-b) — 기준 박스와 무관하게 늘 찍는다. 공통층이라 어느 편입에도 뜻이 있다. */
    try {
        $sq = pf_sue_stock($pdo, $code);
        if ($sq) $out['sue_latest'] = (float)$sq[array_key_last($sq)];
    } catch (Throwable $e) { /* 재무 없음 */ }

    return $out;
}

/** 스냅샷의 퀀트신호 → 화면 라벨 (기록을 읽는 쪽 전용 — 오늘의 판정이 아니다) */
function pf_entry_signal_label(?string $k): string
{
    return ['accumulate' => '매집형', 'neutral' => '중립', 'flame' => '불꽃형'][$k ?? ''] ?? '';
}

/** 불꽃형의 근거 → 화면 문구 */
function pf_entry_flame_label(?string $k): string
{
    return ['by_volume' => '거래대금 20배↑', 'by_return' => '등락 +20%↑',
            'both' => '거래대금·등락 둘 다'][$k ?? ''] ?? '';
}
?>
